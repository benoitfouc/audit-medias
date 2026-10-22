<?php
/**
 * Plugin Name: Benfou - Transition des mises a jour Audit Medias
 * Description: Remplace temporairement la source API des versions 2.3.3 a 2.3.5 par le manifeste statique officiel. Aucune installation automatique.
 * Version: 1.0.0
 * Author: Benfou
 * Author URI: https://www.benfou.fr/
 * Requires at least: 6.2
 * Requires PHP: 7.4
 */
if (!defined('ABSPATH')) { exit; }
class Benfou_AMF_Transition_236 {
    private static $enabled = false;
    public static function eligible() {
        return defined('AMF_VERSION') && defined('AMF_PLUGIN_FILE') &&
            'audit-medias/audit-medias.php' === plugin_basename(AMF_PLUGIN_FILE) &&
            version_compare(AMF_VERSION, '2.3.3', '>=') && version_compare(AMF_VERSION, '2.3.6', '<');
    }
    public static function boot() {
        add_action('admin_menu', array(__CLASS__,'menu'));
        add_action('admin_post_bfamf_transition_check', array(__CLASS__,'run'));
        add_action('admin_notices', array(__CLASS__,'notice'));
        if (!self::eligible()) { return; }
        if (!class_exists('AMF_Static_Manifest', false)) { require_once __DIR__.'/class-amf-static-manifest.php'; }
        // Remove only the old Audit Medias callbacks. Other plugins are never touched.
        $priority = has_filter('update_plugins_github.com', array('AMF_Updates','check_update'));
        if (false !== $priority) { remove_filter('update_plugins_github.com', array('AMF_Updates','check_update'), $priority); }
        $priority = has_filter('plugins_api', array('AMF_Updates','plugin_information'));
        if (false !== $priority) { remove_filter('plugins_api', array('AMF_Updates','plugin_information'), $priority); }
        add_filter('update_plugins_github.com', array(__CLASS__,'check'), 10, 4);
        add_filter('plugins_api', array(__CLASS__,'information'), 10, 3);
        add_filter('upgrader_pre_download', array('AMF_Static_Manifest','pre_download'), 10, 4);
        self::$enabled = true;
    }
    public static function check($update, $headers, $file, $locales) {
        if (!self::eligible() || AMF_Static_Manifest::FILE !== $file || !isset($headers['UpdateURI']) || AMF_Static_Manifest::URI !== $headers['UpdateURI']) { return $update; }
        $r = AMF_Static_Manifest::fetch();
        if (!$r || version_compare($r['version'], '2.3.6', '<')) { return $update; }
        return AMF_Static_Manifest::offer($r);
    }
    public static function information($result, $action, $args) {
        if (!self::eligible() || 'plugin_information' !== $action || !is_object($args) || !isset($args->slug) || 'audit-medias' !== $args->slug) { return $result; }
        $r = AMF_Static_Manifest::fetch();
        if (!$r || version_compare($r['version'], '2.3.6', '<')) { return $result; }
        return (object)array('name'=>'Audit Médias','slug'=>'audit-medias','version'=>$r['version'],
            'requires'=>$r['requires'],'requires_php'=>$r['requires_php'],'homepage'=>AMF_Static_Manifest::URI,
            'author'=>'Benfou','download_link'=>$r['package'],'sections'=>array('changelog'=>nl2br(esc_html($r['description']))));
    }
    private static function allowed() { return current_user_can('manage_options') && current_user_can('update_plugins'); }
    public static function menu() { add_management_page('Transition Audit Médias','Transition Audit Médias','update_plugins','bfamf-transition',array(__CLASS__,'page')); }
    public static function run() {
        if (!self::allowed()) { wp_die('Accès refusé.'); }
        check_admin_referer('bfamf_transition_check');
        if (!self::$enabled || !self::eligible()) { wp_die('Transition inutile ou installation Audit Médias non reconnue.'); }
        AMF_Static_Manifest::clear_cache();
        $current = get_site_transient('update_plugins');
        if (is_object($current)) { $current->last_checked = 0; set_site_transient('update_plugins',$current); }
        wp_update_plugins();
        $r = AMF_Static_Manifest::fetch();
        if ($r && version_compare($r['version'], '2.3.6', '>=')) { AMF_Static_Manifest::sync_wordpress($r, AMF_VERSION); }
        wp_safe_redirect(admin_url('tools.php?page=bfamf-transition')); exit;
    }
    public static function notice() {
        if (self::allowed() && defined('AMF_VERSION') && version_compare(AMF_VERSION, '2.3.6', '>=')) {
            echo '<div class="notice notice-success"><p>Audit Médias utilise maintenant sa source statique. La transition est inactive : vous pouvez désactiver puis supprimer <strong>Benfou - Transition des mises a jour Audit Medias</strong>.</p></div>';
        }
    }
    public static function page() {
        if (!self::allowed()) { wp_die('Accès refusé.'); }
        echo '<div class="wrap"><h1>Transition Audit Médias</h1><p>Cet outil utilise les métadonnées réelles du manifeste officiel, sans appeler l’API GitHub. Il ne modifie pas les fichiers d’Audit Médias et n’installe rien automatiquement.</p>';
        echo '<p>Version installée : <strong>'.esc_html(defined('AMF_VERSION') ? AMF_VERSION : 'non chargée').'</strong></p>';
        if (!self::$enabled) { echo '<p>Transition inactive : Audit Médias est absent, son dossier est incorrect ou la version 2.3.6 (ou ultérieure) est déjà installée.</p></div>'; return; }
        $report = get_site_option(AMF_Static_Manifest::REPORT, array());
        $r = AMF_Static_Manifest::cached();
        $wp = get_site_transient('update_plugins');
        $offered = is_object($wp) && isset($wp->response[AMF_Static_Manifest::FILE]->new_version) ? $wp->response[AMF_Static_Manifest::FILE]->new_version : '';
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="bfamf_transition_check">';
        wp_nonce_field('bfamf_transition_check');
        echo '<button class="button button-primary">Rechercher la mise à jour officielle</button></form>';
        echo '<p>Version validée dans le manifeste : <strong>'.esc_html($r ? $r['version'] : 'aucune').'</strong></p>';
        echo '<p>Version effectivement proposée par WordPress : <strong>'.esc_html(is_scalar($offered) && $offered ? $offered : 'aucune').'</strong></p>';
        if (is_array($report) && isset($report['status'])) {
            echo '<p>Dernier contrôle : '.esc_html($report['status']).' (HTTP '.esc_html(isset($report['http']) ? $report['http'] : 0).')</p>';
        }
        echo '<p><a class="button" href="'.esc_url(self_admin_url('update-core.php')).'">Ouvrir les mises à jour WordPress</a></p><p>Après installation de la 2.3.6 depuis WordPress, désactivez puis supprimez cette extension temporaire. Aucune analyse, aucun média et aucun réglage d’installation automatique ne sont modifiés par cet outil.</p></div>';
    }
}
add_action('plugins_loaded',array('Benfou_AMF_Transition_236','boot'),30);
