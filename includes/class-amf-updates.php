<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Official WordPress Update URI integration for the public Benfou releases.
 * No third-party library, GitHub credentials or client-specific configuration.
 */
if (!class_exists('AMF_Static_Manifest', false)) { require_once __DIR__.'/class-amf-static-manifest.php'; }
require_once __DIR__.'/class-amf-release-notes.php';
require_once __DIR__.'/class-amf-presentation.php';
class AMF_Updates {
	const REPOSITORY = 'benoitfouc/audit-medias';
	const UPDATE_URI = 'https://github.com/benoitfouc/audit-medias';
	const CACHE_KEY  = 'amf_static_release_v1';
	const DIAGNOSTIC_KEY = 'amf_static_diagnostic_v1';
	const PLUGIN_BASENAME = 'audit-medias/audit-medias.php';

	public static function register() {
        AMF_Presentation::register();
		// WordPress evaluates this filter in cron and MainWP update checks too.
		add_filter( 'update_plugins_github.com', array( __CLASS__, 'check_update' ), 10, 4 );
		add_filter( 'plugins_api', array( __CLASS__, 'plugin_information' ), 10, 3 );
		add_action( 'admin_post_amf_check_updates', array( __CLASS__, 'refresh_updates' ) );
		add_filter('upgrader_pre_download', array('AMF_Static_Manifest', 'pre_download'), 10, 4);
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
		// Query our fixed static endpoint anyway, then merge this plugin only.
		$release = self::latest_release();
		if ($release) { self::sync_wordpress($release); }
		wp_safe_redirect(admin_url('tools.php?page=audit-medias&tab=settings&amf_update_checked=1#amf-updates'));
		exit;
	}

	/** Keep every other plugin's metadata and automatic-update preferences. */
	private static function sync_wordpress($release) {
		if (self::PLUGIN_BASENAME === plugin_basename(AMF_PLUGIN_FILE)) {
			AMF_Static_Manifest::sync_wordpress($release, AMF_VERSION);
		}
	}
	private static function offer($release) { $offer=AMF_Static_Manifest::offer($release); $offer['icons']=AMF_Presentation::icons(); return $offer; }

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
			'requires'      => $release['requires'],
			'requires_php'  => $release['requires_php'],
			'download_link' => $release['package'],
            'icons'         => AMF_Presentation::icons(),
			'sections'      => array(
				'description' => '<p>Audit des médias WordPress, recherche des références et suppression manuelle contrôlée des familles de fichiers.</p><p><a href="' . esc_url( self::UPDATE_URI ) . '">Code source et documentation GitHub</a>.</p>',
				'changelog'   => '<div class="amf-release-notes">' . AMF_Release_Notes::render($changelog) . '<p class="amf-full-changelog"><a href="https://github.com/benoitfouc/audit-medias/blob/main/CHANGELOG.md" target="_blank" rel="noopener noreferrer">Consulter l\'historique complet des versions sur GitHub &#8599;</a></p></div>',
			),
		);
	}

	/** Static publication channel: no REST API and no personal token. */
	private static function latest_release() { return AMF_Static_Manifest::fetch(); }
	private static function read_cached_release($cached) {
		return AMF_Static_Manifest::cached();
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
			'ok'=>'Manifeste statique valide et ZIP officiel identifié (sans API GitHub).',
			'network_error'=>'Connexion HTTPS impossible : vérifiez DNS, certificat et accès sortants de l’hébergement.',
			'http_error'=>'La source statique a refusé ou n’a pas pu traiter la requête. Consultez le code HTTP ci-dessous.',
			'not_published'=>'Manifeste statique indisponible (HTTP 404). Consultez le résultat de publication.',
			'rate_limited'=>'La source statique demande de patienter (HTTP 429). Ce n’est pas le quota de l’API REST GitHub.',
			'invalid_manifest'=>'Manifeste refusé : format, version, URL du ZIP ou empreinte incorrects.',
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
			'Cache du manifeste'=>$r['cache_active'] ? ($r['cache_expires_at'] ? 'Expire le '.self::format_time($r['cache_expires_at']) : 'Actif (ancien cache, expiration non enregistrée)') : 'Absent ou expiré',
			'Version du manifeste'=>$r['detected_version'] ?: 'Non disponible',
			'Version proposée par WordPress'=>$r['wordpress_version'] ?: 'Aucune actuellement',
			'Dernière vérification WordPress'=>self::format_time($r['wordpress_checked_at']),
			'Source utilisée'=>isset($r['source']) ? $r['source'] : AMF_Static_Manifest::SOURCE,
			'Source statique de secours'=>AMF_Static_Manifest::FALLBACK,
			'API GitHub'=>'Non utilisée depuis la 2.3.6',
		);
		if (!empty($r['attempts']) && is_array($r['attempts'])) {
			foreach ($r['attempts'] as $index=>$attempt) {
				if (is_array($attempt) && isset($attempt['http'],$attempt['status'])) {
					$rows['Source '.($index+1)] = 'HTTP '.(int)$attempt['http'].' / '.sanitize_key($attempt['status']);
				}
			}
		}
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

	/** Legacy validation helper retained for diagnostic compatibility; never fetches the API. */
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
