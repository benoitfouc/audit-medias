<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Official WordPress Update URI integration for the public Benfou releases.
 * No third-party library, GitHub credentials or client-specific configuration.
 */
class AMF_Updates {
	const REPOSITORY = 'benoitfouc/audit-medias';
	const UPDATE_URI = 'https://github.com/benoitfouc/audit-medias';
	const CACHE_KEY  = 'amf_github_release_v1';
	const DIAGNOSTIC_KEY = 'amf_update_diagnostic_v1';
	const PLUGIN_BASENAME = 'audit-medias/audit-medias.php';

	public static function register() {
		// WordPress evaluates this filter in cron and MainWP update checks too.
		add_filter( 'update_plugins_github.com', array( __CLASS__, 'check_update' ), 10, 4 );
		add_filter( 'plugins_api', array( __CLASS__, 'plugin_information' ), 10, 3 );
		add_action( 'admin_post_amf_check_updates', array( __CLASS__, 'refresh_updates' ) );
	}

	/** Explicit admin action: clear update metadata, never install anything. */
	public static function refresh_updates() {
		if (!current_user_can('manage_options') || !current_user_can('update_plugins')) {
			wp_die(esc_html__('Vous n’avez pas les droits nécessaires.', 'audit-medias'));
		}
		check_admin_referer('amf_check_updates');
		delete_site_transient(self::CACHE_KEY);
		// Preserve other plugins' offers if WordPress.org cannot be contacted.
		$current = get_site_transient('update_plugins');
		if (is_object($current)) {
			$current->last_checked = 0;
			set_site_transient('update_plugins', $current);
		}
		wp_update_plugins();
		// The native callback is skipped by core if WordPress.org fails first.
		// Query our fixed endpoint anyway, then merge this plugin only.
		$release = self::latest_release();
		if ($release) { self::sync_wordpress($release); }
		wp_safe_redirect(admin_url('tools.php?page=audit-medias&tab=settings&amf_update_checked=1#amf-updates'));
		exit;
	}

	/** Keep every other plugin's metadata and automatic-update preferences. */
	private static function sync_wordpress($release) {
		$file = plugin_basename(AMF_PLUGIN_FILE);
		if ($file !== self::PLUGIN_BASENAME) { return; }
		$current = get_site_transient('update_plugins');
		if (!is_object($current)) { $current = new stdClass(); }
		foreach (array('response','no_update','checked') as $key) {
			if (!isset($current->$key) || !is_array($current->$key)) { $current->$key = array(); }
		}
		$offer = (object) self::offer($release);
		$offer->id = self::UPDATE_URI;
		$offer->plugin = $file;
		$offer->new_version = $release['version'];
		$current->checked[$file] = AMF_VERSION;
		unset($current->response[$file], $current->no_update[$file]);
		if (version_compare($release['version'], AMF_VERSION, '>')) {
			$current->response[$file] = $offer;
		} else { $current->no_update[$file] = $offer; }
		// Do not change last_checked: it belongs to the whole WordPress check.
		set_site_transient('update_plugins', $current);
	}

	private static function offer($release) {
		return array(
			'slug' => 'audit-medias', 'version' => $release['version'],
			'url' => self::UPDATE_URI . '/releases/tag/' . $release['tag'],
			'package' => $release['package'], 'requires' => '6.2', 'requires_php' => '7.4',
		);
	}

	/** Only intercept this plugin, never other GitHub-hosted extensions. */
	public static function check_update( $update, $plugin_data, $plugin_file, $locales ) {
		if ( plugin_basename( AMF_PLUGIN_FILE ) !== $plugin_file ||
			self::PLUGIN_BASENAME !== $plugin_file ||
			self::UPDATE_URI !== ( isset( $plugin_data['UpdateURI'] ) ? $plugin_data['UpdateURI'] : '' ) ) {
			return $update;
		}

		$release = self::latest_release();
		if ( ! $release ) {
			return $update;
		}
		return self::offer($release);
	}

	/** Supply the standard WordPress plugin-information modal. */
	public static function plugin_information( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || ! is_object( $args ) ||
			! isset( $args->slug ) || 'audit-medias' !== $args->slug ) {
			return $result;
		}
		$release = self::latest_release();
		if ( ! $release ) {
			return $result;
		}
		$changelog = trim( $release['description'] );
		return (object) array(
			'name'          => 'Audit Médias',
			'slug'          => 'audit-medias',
			'version'       => $release['version'],
			'author'        => '<a href="https://www.benfou.fr/">Benfou</a>',
			'homepage'      => self::UPDATE_URI,
			'requires'      => '6.2',
			'requires_php'  => '7.4',
			'download_link' => $release['package'],
			'sections'      => array(
				'description' => '<p>Audit des médias WordPress, recherche des références et suppression manuelle contrôlée des familles de fichiers.</p><p><a href="' . esc_url( self::UPDATE_URI ) . '">Code source et documentation GitHub</a>.</p>',
				'changelog'   => $changelog ? nl2br( esc_html( $changelog ) ) : '<p>Consulter les notes de version sur GitHub.</p>',
			),
		);
	}

	/** Cached, fixed HTTPS endpoint. No credentials, media or site data sent. */
	private static function latest_release() {
		$cached = get_site_transient(self::CACHE_KEY);
		if (is_array($cached) && isset($cached['state'])) {
			if ('unavailable' === $cached['state']) { return false; }
			$release = self::read_cached_release($cached);
			if ($release) { return $release; }
		}
		$response = wp_remote_get('https://api.github.com/repos/' . self::REPOSITORY . '/releases/latest', array(
			'timeout' => 8, 'redirection' => 2, 'limit_response_size' => 524288,
			'headers' => array('Accept'=>'application/vnd.github+json','X-GitHub-Api-Version'=>'2022-11-28'),
			'user-agent' => 'Audit-Medias/' . AMF_VERSION . ' (WordPress)',
		));
		$ttl = 30 * MINUTE_IN_SECONDS;
		if (is_wp_error($response)) {
			$code = preg_replace('/[^a-zA-Z0-9_-]/', '', (string)$response->get_error_code());
			// Do not retain raw error messages: they may expose paths or URLs.
			self::record_result(false, 'network_error', 0, $ttl, array(), substr($code, 0, 80));
			return false;
		}
		$http = (int)wp_remote_retrieve_response_code($response);
		$limits = array();
		foreach (array('x-ratelimit-remaining'=>'remaining','x-ratelimit-reset'=>'reset') as $header=>$key) {
			$value = wp_remote_retrieve_header($response, $header);
			if (is_scalar($value) && preg_match('/^[0-9]+$/D', (string)$value)) { $limits[$key] = (int)$value; }
		}
		if (200 !== $http) {
			$status = 'http_error';
			if (404 === $http) { $status = 'no_release'; }
			if (429 === $http || (403 === $http && isset($limits['remaining']) && 0 === $limits['remaining'])) {
				$status = 'rate_limited';
				if (!empty($limits['reset'])) { $ttl = max(60, min(HOUR_IN_SECONDS, $limits['reset'] - time())); }
			}
			self::record_result(false, $status, $http, $ttl, $limits);
			return false;
		}
		$body = json_decode(wp_remote_retrieve_body($response), true);
		if (JSON_ERROR_NONE !== json_last_error() || !is_array($body)) {
			self::record_result(false, 'invalid_json', $http, $ttl, $limits);
			return false;
		}
		$release = self::validate_release($body);
		if (!$release) {
			$stable = empty($body['draft']) && empty($body['prerelease']) && isset($body['tag_name']) && is_string($body['tag_name']) && preg_match('/^v[0-9]+\.[0-9]+\.[0-9]+$/D', $body['tag_name']);
			self::record_result(false, $stable ? 'missing_asset' : 'invalid_release', $http, $ttl, $limits);
			return false;
		}
		self::record_result($release, 'ok', $http, 6 * HOUR_IN_SECONDS, $limits);
		return $release;
	}

	private static function read_cached_release($cached) {
		if (!is_array($cached) || !isset($cached['state'],$cached['release']) || 'ok' !== $cached['state'] || !is_array($cached['release'])) { return false; }
		$r = $cached['release'];
		if (!isset($r['tag'],$r['package']) || !is_string($r['tag']) || !is_string($r['package'])) { return false; }
		return self::validate_release(array(
			'tag_name'=>$r['tag'], 'draft'=>false, 'prerelease'=>false,
			'body'=>isset($r['description']) ? $r['description'] : '',
			'assets'=>array(array('name'=>'audit-medias-'.$r['tag'].'.zip','browser_download_url'=>$r['package'])),
		));
	}

	private static function record_result($release, $status, $http, $ttl, $limits=array(), $error='') {
		$now = time();
		$previous = get_site_option(self::DIAGNOSTIC_KEY, array());
		$report = array('status'=>$status, 'checked_at'=>$now, 'expires_at'=>$now+$ttl,
			'http'=>$http, 'error_code'=>$error, 'rate_limit'=>$limits,
			'last_success_at'=>$release ? $now : (is_array($previous) && isset($previous['last_success_at']) ? (int)$previous['last_success_at'] : 0));
		if ($release) { $report['version'] = $release['version']; }
		update_site_option(self::DIAGNOSTIC_KEY, $report);
		$cache = array('state'=>$release ? 'ok' : 'unavailable','checked_at'=>$now,'expires_at'=>$now+$ttl);
		if ($release) { $cache['release'] = $release; }
		set_site_transient(self::CACHE_KEY, $cache, $ttl);
	}

	/** Read-only: opening the settings does not make an HTTP request. */
	public static function diagnostics() {
		$cache = get_site_transient(self::CACHE_KEY);
		$release = self::read_cached_release($cache);
		$report = get_site_option(self::DIAGNOSTIC_KEY, array());
		$report = is_array($report) ? $report : array();
		$updates = get_site_transient('update_plugins');
		$file = plugin_basename(AMF_PLUGIN_FILE);
		$offered = is_object($updates) && isset($updates->response[$file]->new_version) ? $updates->response[$file]->new_version : '';
		$report['local_version'] = AMF_VERSION;
		$report['plugin_file'] = $file;
		$report['canonical_path'] = self::PLUGIN_BASENAME === $file;
		$report['cache_active'] = is_array($cache);
		$report['cache_expires_at'] = is_array($cache) && isset($cache['expires_at']) ? (int)$cache['expires_at'] : 0;
		$report['detected_version'] = $release ? $release['version'] : '';
		$report['wordpress_version'] = is_scalar($offered) ? (string)$offered : '';
		$report['wordpress_checked_at'] = is_object($updates) && isset($updates->last_checked) ? (int)$updates->last_checked : 0;
		return $report;
	}

	private static function format_time($timestamp) {
		return $timestamp ? wp_date('d/m/Y H:i:s T', (int)$timestamp) : 'Non enregistré';
	}

	public static function render_settings() {
		if (!current_user_can('manage_options')) { return; }
		$r = self::diagnostics();
		$messages = array(
			'ok'=>'Réponse GitHub valide et ZIP installable identifié.',
			'network_error'=>'Connexion HTTPS impossible : vérifiez DNS, certificat et accès sortants de l’hébergement.',
			'http_error'=>'GitHub a refusé ou n’a pas pu traiter la requête. Consultez le code HTTP ci-dessous.',
			'no_release'=>'Aucune release publique accessible à cette adresse (HTTP 404).',
			'rate_limited'=>'Limite de requêtes GitHub atteinte. Une IP partagée entre plusieurs sites peut en être la cause.',
			'invalid_json'=>'La réponse reçue ne contient pas de JSON valide.',
			'missing_asset'=>'La release est visible mais son ZIP installable officiel manque ou son URL ne correspond pas.',
			'invalid_release'=>'La release ne respecte pas le format stable attendu (tag vX.Y.Z).',
		);
		$status = isset($r['status']) && isset($messages[$r['status']]) ? $r['status'] : '';
		$is_newer = $r['detected_version'] && version_compare($r['detected_version'], AMF_VERSION, '>');
		$rows = array(
			'Extension chargée'=>$r['plugin_file'],
			'Dernière tentative HTTP'=>self::format_time(isset($r['checked_at']) ? $r['checked_at'] : 0),
			'Dernière réponse valide'=>self::format_time(isset($r['last_success_at']) ? $r['last_success_at'] : 0),
			'Code HTTP'=>isset($r['http']) && $r['http'] ? (string)$r['http'] : 'Non enregistré',
			'Cache GitHub'=>$r['cache_active'] ? ($r['cache_expires_at'] ? 'Expire le '.self::format_time($r['cache_expires_at']) : 'Actif (ancien cache, expiration non enregistrée)') : 'Absent ou expiré',
			'Version dans le cache GitHub'=>$r['detected_version'] ?: 'Non disponible',
			'Version proposée par WordPress'=>$r['wordpress_version'] ?: 'Aucune actuellement',
			'Dernière vérification WordPress'=>self::format_time($r['wordpress_checked_at']),
			'Point de contrôle'=>'https://api.github.com/repos/'.self::REPOSITORY.'/releases/latest',
		);
		if (!empty($r['error_code'])) { $rows['Erreur réseau'] = $r['error_code']; }
		if (isset($r['rate_limit']['remaining'])) { $rows['Requêtes GitHub restantes'] = (string)$r['rate_limit']['remaining']; }
		if (!empty($r['rate_limit']['reset'])) { $rows['Réinitialisation du quota'] = self::format_time($r['rate_limit']['reset']); }
		$rows['Tâches WordPress automatiques'] = defined('DISABLE_WP_CRON') && DISABLE_WP_CRON ? 'WP-Cron désactivé (vérifier le cron externe)' : 'WP-Cron non désactivé';
		$rows['Modifications de fichiers'] = defined('DISALLOW_FILE_MODS') && DISALLOW_FILE_MODS ? 'Bloquées par la configuration WordPress' : 'Non bloquées par DISALLOW_FILE_MODS';
		$rows['Restrictions HTTP WordPress'] = defined('WP_HTTP_BLOCK_EXTERNAL') && WP_HTTP_BLOCK_EXTERNAL ? 'Actives (vérifier les hôtes autorisés)' : 'Non activées';
		$checked = isset($_GET['amf_update_checked']) && '1' === $_GET['amf_update_checked'];
		?>
		<section class="amf-info-box" id="amf-updates">
			<div class="amf-update-heading"><h3>Mises à jour</h3><span class="amf-update-status">Version installée : <strong><?php echo esc_html(AMF_VERSION); ?></strong></span></div>
			<p><?php echo esc_html($is_newer ? 'Version '.$r['detected_version'].' détectée. Son installation reste votre choix.' : 'Vérifiez les nouvelles versions sans déclencher leur installation.'); ?></p>
			<?php if (!$r['canonical_path']) : ?><p class="amf-update-message is-error">Dossier incorrect : conservez une seule installation dans <code>audit-medias/</code>. Les mises à jour de cette copie sont bloquées.</p><?php endif; ?>
			<?php if ($checked && $status) : ?><p role="status" class="amf-update-message <?php echo 'ok' !== $status ? 'is-error' : ''; ?>"><?php echo esc_html($messages[$status]); ?></p><?php endif; ?>
			<?php if (current_user_can('update_plugins')) : ?>
			<div class="amf-update-actions"><form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><input type="hidden" name="action" value="amf_check_updates"><?php wp_nonce_field('amf_check_updates'); ?><button type="submit" class="button button-primary">Vérifier maintenant</button></form><a class="button" href="<?php echo esc_url(self_admin_url('update-core.php')); ?>">Mises à jour WordPress</a></div>
			<details class="amf-disclosure amf-diagnostic-update" <?php echo $checked ? 'open' : ''; ?>><summary>Diagnostic des mises à jour</summary>
			<p><?php echo esc_html($status ? $messages[$status] : 'Aucune tentative HTTP documentée depuis cette version. Cliquez sur Vérifier maintenant.'); ?></p>
			<dl class="amf-diagnostic-list"><?php foreach($rows as $label=>$value) : ?><dt><?php echo esc_html($label); ?></dt><dd><?php echo esc_html($value); ?></dd><?php endforeach; ?></dl>
			<p class="amf-brand-note">Seul le dernier résultat technique est conservé localement. Aucun média, contenu ou identifiant client n’est envoyé. Le bouton renouvelle le cache, pas les réglages de mise à jour automatique.</p></details>
			<?php endif; ?>
		</section>
		<?php
	}

	/** Strictly allow ZIP assets published from our own repository only. */
	public static function validate_release( $release ) {
		if ( ! is_array( $release ) || ! empty( $release['draft'] ) || ! empty( $release['prerelease'] ) ||
			! isset( $release['tag_name'], $release['assets'] ) || ! is_string($release['tag_name']) || ! is_array( $release['assets'] ) ) {
			return false;
		}
		$tag = (string) $release['tag_name'];
		if ( ! preg_match( '/^v([0-9]+\.[0-9]+\.[0-9]+)$/D', $tag, $matches ) ) {
			return false;
		}
		$expected_name = 'audit-medias-' . $tag . '.zip';
		$expected_url = self::UPDATE_URI . '/releases/download/' . $tag . '/' . $expected_name;
		foreach ( $release['assets'] as $asset ) {
			if ( ! is_array( $asset ) || ! isset( $asset['name'], $asset['browser_download_url'] ) ||
				$expected_name !== $asset['name'] || $expected_url !== $asset['browser_download_url'] ) {
				continue;
			}
			return array(
				'version'     => $matches[1],
				'tag'         => $tag,
				'package'     => $expected_url,
				'description' => isset( $release['body'] ) && is_string( $release['body'] ) ? substr( $release['body'], 0, 10000 ) : '',
			);
		}
		return false;
	}
}
