<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AMF_Database {
	const DB_VERSION = '2.3.0';

	/** @var wpdb */
	private $wpdb;

	public function __construct() {
		global $wpdb;
		$this->wpdb = $wpdb;
	}

	public function table( $name ) {
		return $this->wpdb->prefix . 'amf_' . $name;
	}

	public function install() {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $this->wpdb->get_charset_collate();

		$sql = array();

		$sql[] = "CREATE TABLE {$this->table( 'scans' )} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			status varchar(20) NOT NULL DEFAULT 'idle',
			phase varchar(40) NOT NULL DEFAULT 'media',
			phase_cursor bigint(20) unsigned NOT NULL DEFAULT 0,
			phase_current bigint(20) unsigned NOT NULL DEFAULT 0,
			phase_total bigint(20) unsigned NOT NULL DEFAULT 0,
			state longtext NULL,
			message text NULL,
			lock_token varchar(64) NULL,
			locked_until datetime NULL,
			started_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			finished_at datetime NULL,
			PRIMARY KEY  (id),
			KEY status (status),
			KEY updated_at (updated_at)
		) $charset_collate;";

		$sql[] = "CREATE TABLE {$this->table( 'media' )} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			scan_id bigint(20) unsigned NOT NULL,
			attachment_id bigint(20) unsigned NOT NULL,
			title text NULL,
			mime_type varchar(100) NULL,
			rel_path text NULL,
			path_hash char(32) NOT NULL,
			rel_dir text NULL,
			dir_hash char(32) NOT NULL,
			filename varchar(255) NULL,
			family_stem varchar(255) NULL,
			width int(10) unsigned NOT NULL DEFAULT 0,
			height int(10) unsigned NOT NULL DEFAULT 0,
			file_exists tinyint(1) NOT NULL DEFAULT 0,
			usage_confirmed int(10) unsigned NOT NULL DEFAULT 0,
			usage_probable int(10) unsigned NOT NULL DEFAULT 0,
			physical_count int(10) unsigned NOT NULL DEFAULT 0,
			total_bytes bigint(20) unsigned NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			UNIQUE KEY scan_attachment (scan_id, attachment_id),
			KEY scan_path_hash (scan_id, path_hash),
			KEY family_lookup (scan_id, dir_hash, family_stem(191)),
			KEY usage_confirmed (scan_id, usage_confirmed),
			KEY file_exists (scan_id, file_exists)
		) $charset_collate;";

		$sql[] = "CREATE TABLE {$this->table( 'files' )} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			scan_id bigint(20) unsigned NOT NULL,
			attachment_id bigint(20) unsigned NULL,
			rel_path text NOT NULL,
			path_hash char(32) NOT NULL,
			dir_hash char(32) NOT NULL,
			family_key char(32) NOT NULL DEFAULT '',
			family_stem varchar(255) NULL,
			basename varchar(255) NOT NULL,
			extension varchar(20) NULL,
			classification varchar(60) NOT NULL DEFAULT 'unassociated',
			association_confidence tinyint(3) unsigned NOT NULL DEFAULT 0,
			physical tinyint(1) NOT NULL DEFAULT 0,
			current_meta tinyint(1) NOT NULL DEFAULT 0,
			direct_usage_count int(10) unsigned NOT NULL DEFAULT 0,
			bytes bigint(20) unsigned NOT NULL DEFAULT 0,
			width int(10) unsigned NOT NULL DEFAULT 0,
			height int(10) unsigned NOT NULL DEFAULT 0,
			note text NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY scan_path_hash (scan_id, path_hash),
			KEY scan_attachment (scan_id, attachment_id),
			KEY scan_classification (scan_id, classification),
			KEY scan_physical (scan_id, physical),
			KEY scan_dir (scan_id, dir_hash),
			KEY scan_family (scan_id, family_key)
		) $charset_collate;";

		$sql[] = "CREATE TABLE {$this->table( 'usage' )} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			scan_id bigint(20) unsigned NOT NULL,
			attachment_id bigint(20) unsigned NOT NULL DEFAULT 0,
			file_path_hash char(32) NULL,
			file_rel_path text NULL,
			confidence varchar(20) NOT NULL DEFAULT 'confirmed',
			source_type varchar(50) NOT NULL,
			source_id bigint(20) unsigned NOT NULL DEFAULT 0,
			source_subtype varchar(191) NULL,
			context text NULL,
			edit_url text NULL,
			view_url text NULL,
			usage_hash char(32) NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY scan_usage_hash (scan_id, usage_hash),
			KEY scan_attachment (scan_id, attachment_id),
			KEY scan_file_path (scan_id, file_path_hash),
			KEY scan_confidence (scan_id, confidence),
			KEY scan_source (scan_id, source_type, source_id)
		) $charset_collate;";

		$sql[] = "CREATE TABLE {$this->table( 'issues' )} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			scan_id bigint(20) unsigned NOT NULL,
			object_type varchar(40) NOT NULL,
			object_id bigint(20) unsigned NOT NULL DEFAULT 0,
			object_title text NULL,
			attachment_id bigint(20) unsigned NOT NULL DEFAULT 0,
			image_role varchar(60) NULL,
			file_rel_path text NULL,
			severity varchar(20) NOT NULL DEFAULT 'warning',
			code varchar(80) NOT NULL,
			message text NOT NULL,
			details longtext NULL,
			edit_url text NULL,
			view_url text NULL,
			issue_hash char(32) NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY scan_issue_hash (scan_id, issue_hash),
			KEY scan_severity (scan_id, severity),
			KEY scan_object (scan_id, object_type, object_id),
			KEY scan_code (scan_id, code),
			KEY scan_attachment (scan_id, attachment_id)
		) $charset_collate;";

		$sql[] = "CREATE TABLE {$this->table( 'actions' )} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			action_type varchar(40) NOT NULL,
			target_type varchar(40) NOT NULL,
			target_id varchar(191) NULL,
			details longtext NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY action_type (action_type),
			KEY created_at (created_at)
		) $charset_collate;";

		$sql[] = "CREATE TABLE {$this->table( 'dirs' )} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			scan_id bigint(20) unsigned NOT NULL,
			rel_dir text NOT NULL,
			path_hash char(32) NOT NULL,
			cursor_pos int(10) unsigned NOT NULL DEFAULT 0,
			status varchar(20) NOT NULL DEFAULT 'pending',
			PRIMARY KEY  (id),
			UNIQUE KEY scan_path_hash (scan_id, path_hash),
			KEY scan_status (scan_id, status, id)
		) $charset_collate;";


        $sql[] = "CREATE TABLE {$this->table( 'product_images' )} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            generation char(32) NOT NULL,
            attachment_id bigint(20) unsigned NOT NULL,
            object_id bigint(20) unsigned NOT NULL,
            parent_id bigint(20) unsigned NOT NULL DEFAULT 0,
            image_role varchar(20) NOT NULL,
            image_position int(10) unsigned NOT NULL DEFAULT 0,
            source_type varchar(30) NOT NULL,
            source_status varchar(20) NOT NULL,
            parent_status varchar(20) NOT NULL DEFAULT '',
            PRIMARY KEY  (id),
            UNIQUE KEY assignment (generation,object_id,image_role,attachment_id),
            KEY media_lookup (generation,attachment_id),
            KEY object_lookup (generation,object_id)
        ) $charset_collate;";

		foreach ( $sql as $statement ) {
			dbDelta( $statement );
		}

		update_option( 'amf_db_version', self::DB_VERSION, false );
	}

	public function maybe_upgrade() {
		if ( get_option( 'amf_db_version' ) !== self::DB_VERSION ) {
			$this->install();
			$this->remove_historical_parent_usages();
		}
	}

	/**
	 * Avant la 2.2, post_parent gonflait artificiellement les associations probables.
	 * Il s'agit d'une origine de téléversement, pas d'une utilisation. Migration
	 * limitée aux tables du plugin, sans toucher aux attachments WordPress.
	 */
	private function remove_historical_parent_usages() {
		$table = $this->table( 'usage' );
		$scan_ids = $this->wpdb->get_col( "SELECT DISTINCT scan_id FROM {$table} WHERE source_subtype = 'attachment_parent'" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		if ( ! $scan_ids ) {
			return;
		}
		$this->wpdb->query( "DELETE FROM {$table} WHERE source_subtype = 'attachment_parent'" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		foreach ( $scan_ids as $scan_id ) {
			$this->refresh_usage_counts( absint( $scan_id ) );
		}
	}

	public function reset_scan_data() {
		$tables = array( 'media', 'files', 'usage', 'issues', 'dirs', 'scans' );
		foreach ( $tables as $name ) {
			$table = $this->table( $name );
			$this->wpdb->query( "DELETE FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Internal fixed table name.
		}
	}

	public function create_scan() {
		$now = current_time( 'mysql' );
		$this->wpdb->insert(
			$this->table( 'scans' ),
			array(
				'status'        => 'running',
				'phase'         => 'media',
				'phase_cursor'  => 0,
				'phase_current' => 0,
				'phase_total'   => 0,
				'state'         => wp_json_encode( array( 'reference_parser' => AMF_Reference_Parser::VERSION ) ),
				'started_at'    => $now,
				'updated_at'    => $now,
			),
			array( '%s', '%s', '%d', '%d', '%d', '%s', '%s', '%s' )
		);

		return (int) $this->wpdb->insert_id;
	}

	public function get_scan( $scan_id ) {
		$table = $this->table( 'scans' );
		return $this->wpdb->get_row(
			$this->wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $scan_id ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);
	}

	public function get_latest_scan() {
		$table = $this->table( 'scans' );
		return $this->wpdb->get_row( "SELECT * FROM {$table} ORDER BY id DESC LIMIT 1", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	public function update_scan( $scan_id, $data ) {
		$data['updated_at'] = current_time( 'mysql' );
		$formats = array();
		foreach ( $data as $key => $value ) {
			$formats[] = in_array( $key, array( 'phase_cursor', 'phase_current', 'phase_total' ), true ) ? '%d' : '%s';
		}
		return $this->wpdb->update( $this->table( 'scans' ), $data, array( 'id' => $scan_id ), $formats, array( '%d' ) );
	}

	public function acquire_lock( $scan_id, $token, $seconds = 45 ) {
		$table = $this->table( 'scans' );
		$now = current_time( 'mysql' );
		$until = wp_date( 'Y-m-d H:i:s', time() + absint( $seconds ), wp_timezone() );
		$sql = $this->wpdb->prepare(
			"UPDATE {$table}
			 SET lock_token = %s, locked_until = %s, updated_at = %s
			 WHERE id = %d
			   AND (locked_until IS NULL OR locked_until < %s OR lock_token = %s)",
			$token,
			$until,
			$now,
			$scan_id,
			$now,
			$token
		);
		$this->wpdb->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return $this->wpdb->rows_affected > 0;
	}

	public function release_lock( $scan_id, $token ) {
		$table = $this->table( 'scans' );
		$sql = $this->wpdb->prepare(
			"UPDATE {$table} SET lock_token = NULL, locked_until = NULL WHERE id = %d AND lock_token = %s",
			$scan_id,
			$token
		);
		$this->wpdb->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	public function add_issue( $scan_id, $args ) {
		$defaults = array(
			'object_type'  => 'scan',
			'object_id'    => 0,
			'object_title' => '',
			'attachment_id'=> 0,
			'image_role'   => '',
			'file_rel_path'=> '',
			'severity'     => 'warning',
			'code'         => 'generic',
			'message'      => '',
			'details'      => '',
			'edit_url'     => '',
			'view_url'     => '',
		);
		$args = wp_parse_args( $args, $defaults );
		$hash_source = implode( '|', array(
			$scan_id,
			$args['object_type'],
			$args['object_id'],
			$args['attachment_id'],
			$args['image_role'],
			$args['file_rel_path'],
			$args['code'],
			$args['message'],
		) );
		$issue_hash = md5( $hash_source );

		$sql = $this->wpdb->prepare(
			"INSERT IGNORE INTO {$this->table( 'issues' )}
			(scan_id, object_type, object_id, object_title, attachment_id, image_role, file_rel_path, severity, code, message, details, edit_url, view_url, issue_hash)
			VALUES (%d, %s, %d, %s, %d, %s, %s, %s, %s, %s, %s, %s, %s, %s)",
			$scan_id,
			$args['object_type'],
			$args['object_id'],
			$args['object_title'],
			$args['attachment_id'],
			$args['image_role'],
			AMF_Utils::normalize_rel_path( $args['file_rel_path'] ),
			$args['severity'],
			$args['code'],
			$args['message'],
			$args['details'],
			$args['edit_url'],
			$args['view_url'],
			$issue_hash
		);
		return $this->wpdb->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	public function add_usage( $scan_id, $attachment_id, $args ) {
		$defaults = array(
			'confidence'     => 'confirmed',
			'source_type'    => 'unknown',
			'source_id'      => 0,
			'source_subtype' => '',
			'context'        => '',
			'edit_url'       => '',
			'view_url'       => '',
			'file_rel_path'  => '',
		);
		$args = wp_parse_args( $args, $defaults );
		$file_rel_path = AMF_Utils::normalize_rel_path( $args['file_rel_path'] );
		$file_path_hash = $file_rel_path ? AMF_Utils::path_hash( $file_rel_path ) : '';
		$usage_hash = md5( implode( '|', array(
			$attachment_id,
			$file_rel_path,
			$args['confidence'],
			$args['source_type'],
			$args['source_id'],
			$args['source_subtype'],
			$args['context'],
		) ) );

		$sql = $this->wpdb->prepare(
			"INSERT IGNORE INTO {$this->table( 'usage' )}
			(scan_id, attachment_id, file_path_hash, file_rel_path, confidence, source_type, source_id, source_subtype, context, edit_url, view_url, usage_hash)
			VALUES (%d, %d, %s, %s, %s, %s, %d, %s, %s, %s, %s, %s)",
			$scan_id,
			$attachment_id,
			$file_path_hash,
			$file_rel_path,
			$args['confidence'],
			$args['source_type'],
			$args['source_id'],
			$args['source_subtype'],
			$args['context'],
			$args['edit_url'],
			$args['view_url'],
			$usage_hash
		);
		return $this->wpdb->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	public function add_file_usage( $scan_id, $rel_path, $args ) {
		$rel_path = AMF_Utils::normalize_rel_path( $rel_path );
		if ( '' === $rel_path ) {
			return false;
		}
		$args['file_rel_path'] = $rel_path;
		return $this->add_usage( $scan_id, 0, $args );
	}

	public function refresh_usage_counts( $scan_id ) {
		$media = $this->table( 'media' );
		$usage = $this->table( 'usage' );
		$this->wpdb->query(
			$this->wpdb->prepare( "UPDATE {$media} SET usage_confirmed = 0, usage_probable = 0 WHERE scan_id = %d", $scan_id ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
		$sql = $this->wpdb->prepare(
			"UPDATE {$media} m
			 LEFT JOIN (
				SELECT attachment_id,
				SUM(CASE WHEN confidence = 'confirmed' THEN 1 ELSE 0 END) AS confirmed_count,
				SUM(CASE WHEN confidence = 'probable' THEN 1 ELSE 0 END) AS probable_count
				FROM {$usage}
				WHERE scan_id = %d
				GROUP BY attachment_id
			 ) u ON u.attachment_id = m.attachment_id
			 SET m.usage_confirmed = COALESCE(u.confirmed_count, 0),
			     m.usage_probable = COALESCE(u.probable_count, 0)
			 WHERE m.scan_id = %d",
			$scan_id,
			$scan_id
		);
		$this->wpdb->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	public function refresh_file_counts( $scan_id ) {
		$media = $this->table( 'media' );
		$files = $this->table( 'files' );
		$usage = $this->table( 'usage' );
		$this->wpdb->query(
			$this->wpdb->prepare( "UPDATE {$media} SET physical_count = 0, total_bytes = 0 WHERE scan_id = %d", $scan_id ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
		$sql = $this->wpdb->prepare(
			"UPDATE {$media} m
			 LEFT JOIN (
				SELECT attachment_id, COUNT(*) AS file_count, SUM(bytes) AS total_bytes
				FROM {$files}
				WHERE scan_id = %d AND physical = 1 AND attachment_id IS NOT NULL
				GROUP BY attachment_id
			 ) f ON f.attachment_id = m.attachment_id
			 SET m.physical_count = COALESCE(f.file_count, 0),
			     m.total_bytes = COALESCE(f.total_bytes, 0)
			 WHERE m.scan_id = %d",
			$scan_id,
			$scan_id
		);
		$this->wpdb->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		$this->wpdb->query(
			$this->wpdb->prepare( "UPDATE {$files} SET direct_usage_count = 0 WHERE scan_id = %d", $scan_id ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
		$sql = $this->wpdb->prepare(
			"UPDATE {$files} f
			 LEFT JOIN (
				SELECT file_path_hash, COUNT(*) AS usage_count
				FROM {$usage}
				WHERE scan_id = %d AND file_path_hash IS NOT NULL AND file_path_hash <> ''
				GROUP BY file_path_hash
			 ) u ON u.file_path_hash = f.path_hash
			 SET f.direct_usage_count = COALESCE(u.usage_count, 0)
			 WHERE f.scan_id = %d",
			$scan_id,
			$scan_id
		);
		$this->wpdb->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}


	public function log_action( $action_type, $target_type, $target_id, $details = array() ) {
		return $this->wpdb->insert(
			$this->table( 'actions' ),
			array(
				'user_id'     => get_current_user_id(),
				'action_type' => sanitize_key( $action_type ),
				'target_type' => sanitize_key( $target_type ),
				'target_id'   => (string) $target_id,
				'details'     => wp_json_encode( $details, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ),
				'created_at'  => current_time( 'mysql' ),
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s' )
		);
	}

}
