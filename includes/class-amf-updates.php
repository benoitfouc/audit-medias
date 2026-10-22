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

	public static function register() {
		// WordPress evaluates this filter in cron and MainWP update checks too.
		add_filter( 'update_plugins_github.com', array( __CLASS__, 'check_update' ), 10, 4 );
		add_filter( 'plugins_api', array( __CLASS__, 'plugin_information' ), 10, 3 );
	}

	/** Only intercept this plugin, never other GitHub-hosted extensions. */
	public static function check_update( $update, $plugin_data, $plugin_file, $locales ) {
		if ( plugin_basename( AMF_PLUGIN_FILE ) !== $plugin_file ||
			self::UPDATE_URI !== ( isset( $plugin_data['UpdateURI'] ) ? $plugin_data['UpdateURI'] : '' ) ) {
			return $update;
		}

		$release = self::latest_release();
		if ( ! $release ) {
			return $update;
		}
		return array(
			'slug'         => 'audit-medias',
			'version'      => $release['version'],
			'url'          => self::UPDATE_URI . '/releases/tag/' . $release['tag'],
			'package'      => $release['package'],
			'requires_php' => '7.4',
		);
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

	/** Fetch once per site / multisite network, with a short negative cache. */
	private static function latest_release() {
		$cached = get_site_transient( self::CACHE_KEY );
		if ( is_array( $cached ) && isset( $cached['state'] ) ) {
			return 'ok' === $cached['state'] ? $cached['release'] : false;
		}

		$url = 'https://api.github.com/repos/' . self::REPOSITORY . '/releases/latest';
		$response = wp_remote_get( $url, array(
			'timeout'     => 8,
			'redirection' => 2,
			'headers'     => array(
				'Accept'               => 'application/vnd.github+json',
				'X-GitHub-Api-Version' => '2022-11-28',
			),
			'user-agent' => 'Audit-Medias/' . AMF_VERSION . ' (WordPress)',
		) );
		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			set_site_transient( self::CACHE_KEY, array( 'state' => 'unavailable' ), 30 * MINUTE_IN_SECONDS );
			return false;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		$release = self::validate_release( $body );
		if ( ! $release ) {
			set_site_transient( self::CACHE_KEY, array( 'state' => 'unavailable' ), 30 * MINUTE_IN_SECONDS );
			return false;
		}
		set_site_transient( self::CACHE_KEY, array( 'state' => 'ok', 'release' => $release ), 6 * HOUR_IN_SECONDS );
		return $release;
	}

	/** Strictly allow ZIP assets published from our own repository only. */
	public static function validate_release( $release ) {
		if ( ! is_array( $release ) || ! empty( $release['draft'] ) || ! empty( $release['prerelease'] ) ||
			! isset( $release['tag_name'], $release['assets'] ) || ! is_array( $release['assets'] ) ) {
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
