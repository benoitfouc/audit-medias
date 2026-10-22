<?php
if (!defined('ABSPATH')) { exit; }

/** Public static metadata only. This client never calls api.github.com. */
class AMF_Static_Manifest {
    const URI = 'https://github.com/benoitfouc/audit-medias';
    const FILE = 'audit-medias/audit-medias.php';
    const SOURCE = 'https://raw.githubusercontent.com/benoitfouc/audit-medias/updates/update.json';
    const FALLBACK = 'https://github.com/benoitfouc/audit-medias/releases/latest/download/audit-medias-update.json';
    const CACHE = 'amf_static_release_v1';
    const REPORT = 'amf_static_diagnostic_v1';
    const LOCK = 'amf_static_request_lock_v1';
    private static $busy = false;

    public static function clear_cache() {
        delete_site_transient(self::CACHE);
        // A request in another process must keep its short-lived lock.
    }

    /** Validate all fields before any URL is used or offered to WordPress. */
    public static function validate($data) {
        if (!is_array($data)) { return false; }
        foreach (array('schema','slug','channel','version','tag','package','sha256','size','requires','requires_php') as $key) {
            if (!isset($data[$key])) { return false; }
        }
        if (1 !== $data['schema'] || 'audit-medias' !== $data['slug'] || 'stable' !== $data['channel']) { return false; }
        if (!is_string($data['version']) || !preg_match('/^(0|[1-9][0-9]{0,4})\.(0|[1-9][0-9]{0,4})\.(0|[1-9][0-9]{0,4})$/D', $data['version'])) { return false; }
        $tag = 'v'.$data['version'];
        $package = self::URI.'/releases/download/'.$tag.'/audit-medias-'.$tag.'.zip';
        if ($tag !== $data['tag'] || $package !== $data['package']) { return false; }
        if (!is_string($data['sha256']) || !preg_match('/^[a-f0-9]{64}$/D', $data['sha256'])) { return false; }
        if (!is_int($data['size']) || $data['size'] < 1 || $data['size'] > 20971520) { return false; }
        foreach (array('requires','requires_php') as $key) {
            if (!is_string($data[$key]) || !preg_match('/^[0-9]{1,3}\.[0-9]{1,3}(\.[0-9]{1,3})?$/D', $data[$key])) { return false; }
        }
        if (!empty($data['draft']) || !empty($data['prerelease'])) { return false; }
        $description = isset($data['description']) && is_string($data['description']) ? substr($data['description'], 0, 16000) : '';
        return array('schema'=>1,'slug'=>'audit-medias','channel'=>'stable',
            'version'=>$data['version'],'tag'=>$tag,'package'=>$package,
            'sha256'=>$data['sha256'],'size'=>$data['size'],
            'requires'=>$data['requires'],'requires_php'=>$data['requires_php'],'description'=>$description);
    }

    public static function cached() {
        $cache = get_site_transient(self::CACHE);
        if (!is_array($cache) || !isset($cache['state'],$cache['expires_at']) || (int)$cache['expires_at'] <= time()) { return null; }
        if ('unavailable' === $cache['state']) { return false; }
        if ('ok' !== $cache['state'] || !isset($cache['release'])) { return null; }
        $release = self::validate($cache['release']);
        return $release ?: null;
    }

    /** Called only by an update check, not while rendering a public/admin page. */
    public static function fetch() {
        $cached = self::cached();
        if (null !== $cached) { return $cached; }
        if (self::$busy || get_site_transient(self::LOCK)) { return false; }
        self::$busy = true;
        set_site_transient(self::LOCK, 1, 30);
        $attempts = array(); $release = false; $source = ''; $status = 'unavailable'; $http = 0; $retry = 600;
        try {
            foreach (array(self::SOURCE, self::FALLBACK) as $url) {
                $source = $url;
                $response = wp_remote_get($url, array('timeout'=>6, 'redirection'=>3,
                    'limit_response_size'=>131073, 'sslverify'=>true, 'reject_unsafe_urls'=>true,
                    'headers'=>array('Accept'=>'application/json'), 'user-agent'=>'Audit-Medias-Static/1.0'));
                $error = ''; $http = 0;
                if (is_wp_error($response)) {
                    $status = 'network_error';
                    $error = substr(preg_replace('/[^a-zA-Z0-9_-]/', '', (string)$response->get_error_code()), 0, 80);
                } else {
                    $http = (int)wp_remote_retrieve_response_code($response);
                    if (200 !== $http) {
                        $status = 404 === $http ? 'not_published' : (429 === $http ? 'rate_limited' : 'http_error');
                        $after = wp_remote_retrieve_header($response, 'retry-after');
                        if (is_scalar($after) && preg_match('/^[0-9]+$/D', (string)$after)) { $retry = max($retry, min(21600, (int)$after)); }
                    } else {
                        $body = wp_remote_retrieve_body($response);
                        if (!is_string($body) || strlen($body) > 131072) { $status = 'invalid_manifest'; }
                        else {
                            $data = json_decode($body, true, 16);
                            $release = JSON_ERROR_NONE === json_last_error() ? self::validate($data) : false;
                            $status = $release ? 'ok' : 'invalid_manifest';
                        }
                    }
                }
                $attempts[] = array('source'=>$url,'http'=>$http,'status'=>$status,'error_code'=>$error);
                if ($release) { break; }
            }
            $ttl = $release ? 3600 : $retry;
            $now = time();
            $previous = get_site_option(self::REPORT, array());
            $report = array('status'=>$status, 'source'=>$source,'http'=>$http,'checked_at'=>$now,'expires_at'=>$now+$ttl,
                'attempts'=>$attempts, 'last_success_at'=>$release ? $now : (is_array($previous) && isset($previous['last_success_at']) ? (int)$previous['last_success_at'] : 0));
            update_site_option(self::REPORT, $report);
            $cache = array('state'=>$release ? 'ok' : 'unavailable','expires_at'=>$now+$ttl);
            if ($release) {
                $cache['release'] = $release;
                // Keep the checksum for the exact offered version, even if a newer release appears later.
                set_site_transient(self::package_key($release['package']), $release, 604800);
            }
            set_site_transient(self::CACHE, $cache, $ttl);
        } finally {
            self::$busy = false;
            delete_site_transient(self::LOCK);
        }
        return $release;
    }

    public static function offer($release) {
        return array('slug'=>'audit-medias','version'=>$release['version'],
            'url'=>self::URI.'/releases/tag/'.$release['tag'],'package'=>$release['package'],
            'requires'=>$release['requires'],'requires_php'=>$release['requires_php']);
    }

    /** A merge of verified metadata, not a forced update or a changed local version. */
    public static function sync_wordpress($release, $installed) {
        $release = self::validate($release);
        if (!$release || !is_string($installed)) { return false; }
        $current = get_site_transient('update_plugins');
        if (!is_object($current)) { $current = new stdClass(); }
        foreach (array('response','no_update','checked') as $key) {
            if (!isset($current->$key) || !is_array($current->$key)) { $current->$key = array(); }
        }
        $offer = (object)self::offer($release);
        $offer->id = self::URI; $offer->plugin = self::FILE; $offer->new_version = $release['version'];
        $current->checked[self::FILE] = $installed;
        unset($current->response[self::FILE], $current->no_update[self::FILE]);
        if (version_compare($release['version'], $installed, '>')) { $current->response[self::FILE] = $offer; }
        else { $current->no_update[self::FILE] = $offer; }
        // Global last_checked, translations and all other plugin entries remain untouched.
        set_site_transient('update_plugins', $current);
        return true;
    }

    private static function package_key($package) { return 'amf_static_package_'.md5($package); }

    public static function verify_package($file, $release) {
        $release = self::validate($release);
        if (!$release || !is_string($file) || !is_file($file) || !is_readable($file)) { return false; }
        return (int)filesize($file) === $release['size'] && hash_equals($release['sha256'], hash_file('sha256', $file));
    }

    /** Verify integrity before WordPress unpacks our remote ZIP; never handle another plugin. */
    public static function pre_download($reply, $package, $upgrader, $extra = array()) {
        if (!is_string($package) || strpos($package, self::URI.'/releases/download/') !== 0) { return $reply; }
        if (is_array($extra) && isset($extra['plugin']) && self::FILE !== $extra['plugin']) { return $reply; }
        if (is_wp_error($reply)) { return $reply; }
        $release = self::validate(get_site_transient(self::package_key($package)));
        if (!$release || $release['package'] !== $package) {
            $release = self::fetch();
            if (!$release || $release['package'] !== $package) {
                return new WP_Error('amf_checksum_unavailable', 'Empreinte du ZIP indisponible. Relancez la recherche de mises à jour avant de réessayer.');
            }
        }
        $own_download = false === $reply;
        if ($own_download) {
            if (!function_exists('download_url')) { require_once ABSPATH.'wp-admin/includes/file.php'; }
            $file = download_url($package, 90);
        } else { $file = $reply; }
        if (is_wp_error($file)) { return $file; }
        if (!self::verify_package($file, $release)) {
            if ($own_download && is_string($file) && is_file($file)) { unlink($file); }
            return new WP_Error('amf_checksum_mismatch', 'Le ZIP ne correspond pas à son empreinte SHA-256 : installation annulée avant extraction.');
        }
        return $file;
    }
}
