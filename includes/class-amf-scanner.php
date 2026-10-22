<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AMF_Scanner {
	/** @var AMF_Database */
	private $db;
	/** @var AMF_File_Scanner */
	private $file_scanner;
	/** @var AMF_Usage_Scanner */
	private $usage_scanner;
	/** @var AMF_WooCommerce_Audit */
	private $woo_audit;
	/** @var wpdb */
	private $wpdb;

	public function __construct( AMF_Database $db ) {
		$this->db = $db;
		$this->file_scanner  = new AMF_File_Scanner( $db );
		$this->usage_scanner = new AMF_Usage_Scanner( $db );
		$this->woo_audit     = new AMF_WooCommerce_Audit( $db );
		global $wpdb;
		$this->wpdb = $wpdb;
	}


	public function performance_defaults() {
		return array(
			'media_batch'        => 10,
			'posts_batch'        => 25,
			'postmeta_batch'     => 200,
			'termmeta_batch'     => 200,
			'options_batch'      => 25,
			'files_batch'        => 200,
			'woo_batch'          => 8,
			'request_delay_ms'   => 100,
			'time_budget_seconds'=> 10,
		);
	}

	public function performance_presets() {
		return array(
			'prudent' => array(
				'media_batch' => 10, 'posts_batch' => 15, 'postmeta_batch' => 50, 'termmeta_batch' => 75,
				'options_batch' => 10, 'files_batch' => 100, 'woo_batch' => 6, 'request_delay_ms' => 250, 'time_budget_seconds' => 7,
			),
			'balanced' => $this->performance_defaults(),
			'fast' => array(
				'media_batch' => 15, 'posts_batch' => 40, 'postmeta_batch' => 500, 'termmeta_batch' => 500,
				'options_batch' => 50, 'files_batch' => 400, 'woo_batch' => 12, 'request_delay_ms' => 50, 'time_budget_seconds' => 12,
			),
		);
	}

	public function get_performance_settings() {
		$saved = get_option( 'amf_performance_settings', array() );
		if ( ! is_array( $saved ) ) {
			$saved = array();
		}
		return $this->sanitize_performance_settings( wp_parse_args( $saved, $this->performance_defaults() ) );
	}

	public function save_performance_settings( $settings ) {
		$clean = $this->sanitize_performance_settings( $settings );
		update_option( 'amf_performance_settings', $clean, false );
		return $clean;
	}

	public function sanitize_performance_settings( $settings ) {
		$defaults = $this->performance_defaults();
		$settings = is_array( $settings ) ? $settings : array();
		$ranges = array(
			'media_batch' => array( 1, 100 ),
			'posts_batch' => array( 1, 250 ),
			'postmeta_batch' => array( 10, 1000 ),
			'termmeta_batch' => array( 10, 1000 ),
			'options_batch' => array( 1, 250 ),
			'files_batch' => array( 25, 1000 ),
			'woo_batch' => array( 1, 50 ),
			'request_delay_ms' => array( 0, 3000 ),
			'time_budget_seconds' => array( 3, 18 ),
		);
		$clean = array();
		foreach ( $defaults as $key => $default ) {
			$value = isset( $settings[ $key ] ) ? absint( $settings[ $key ] ) : $default;
			$min = $ranges[ $key ][0];
			$max = $ranges[ $key ][1];
			$clean[ $key ] = max( $min, min( $max, $value ) );
		}
		return $clean;
	}

	public function phase_batch_limit( $phase, $settings = null ) {
		$settings = is_array( $settings ) ? $settings : $this->get_performance_settings();
		$map = array(
			'media' => 'media_batch',
			'usage_posts' => 'posts_batch',
			'usage_postmeta' => 'postmeta_batch',
			'usage_termmeta' => 'termmeta_batch',
			'usage_options' => 'options_batch',
			'files' => 'files_batch',
			'woo' => 'woo_batch',
		);
		return isset( $map[ $phase ], $settings[ $map[ $phase ] ] ) ? (int) $settings[ $map[ $phase ] ] : 0;
	}

	public function start_new_scan() {
		$this->db->reset_scan_data();
		$scan_id = $this->db->create_scan();
		return $this->db->get_scan( $scan_id );
	}

	public function resume_scan( $scan_id ) {
		$scan = $this->db->get_scan( $scan_id );
		if ( ! $scan ) {
			return new WP_Error( 'amf_scan_missing', 'Analyse introuvable.' );
		}
        if ( ! AMF_Reference_Parser::scan_is_current( $scan ) ) {
            return new WP_Error( 'amf_old_reference_scan', 'Le moteur de détection a changé. Cliquez sur Recommencer l’analyse pour inclure les shortcodes et les URL CDN.' );
        }
		if ( 'complete' !== $scan['status'] ) {
			$this->db->update_scan( $scan_id, array( 'status' => 'running', 'message' => '' ) );
		}
		return $this->db->get_scan( $scan_id );
	}

	public function process_one_batch( $scan_id ) {
		$scan_id = absint( $scan_id );
		$scan = $this->db->get_scan( $scan_id );
		if ( ! $scan ) {
			return new WP_Error( 'amf_scan_missing', 'Analyse introuvable.' );
		}
        if ( ! AMF_Reference_Parser::scan_is_current( $scan ) ) {
            return new WP_Error( 'amf_old_reference_scan', 'Recommencez l’analyse avec le moteur actuel avant de poursuivre.' );
        }
		if ( 'complete' === $scan['status'] || 'complete' === $scan['phase'] ) {
			return $this->progress_payload( $scan );
		}

		$token = wp_generate_uuid4();
		if ( ! $this->db->acquire_lock( $scan_id, $token, 35 ) ) {
			return new WP_Error( 'amf_scan_busy', 'Une autre requête traite déjà ce lot. Réessayez dans quelques secondes.' );
		}

		try {
			if ( function_exists( 'wp_raise_memory_limit' ) ) {
				wp_raise_memory_limit( 'admin' );
			}
			$performance = $this->get_performance_settings();
			if ( function_exists( 'set_time_limit' ) ) {
				$php_time_limit = max( 15, min( 28, (int) $performance['time_budget_seconds'] + 8 ) );
				@set_time_limit( $php_time_limit ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			}

			$scan = $this->db->get_scan( $scan_id );
			if ( 'error' === $scan['status'] ) {
				$this->db->update_scan( $scan_id, array( 'status' => 'running', 'message' => '' ) );
				$scan['status'] = 'running';
			}

			$result = $this->process_phase( $scan, $performance );
			$this->apply_phase_result( $scan, $result );
			$scan = $this->db->get_scan( $scan_id );
			return $this->progress_payload( $scan );
		} catch ( Throwable $e ) {
			$this->db->update_scan( $scan_id, array(
				'status'  => 'error',
				'message' => 'Erreur de scan : ' . $e->getMessage(),
			) );
			$this->db->add_issue( $scan_id, array(
				'object_type' => 'scan',
				'severity'    => 'warning',
				'code'        => 'scan_batch_exception',
				'message'     => 'Un lot de l’analyse a rencontré une erreur. Le scan peut être repris.',
				'details'     => $e->getMessage(),
			) );
			return new WP_Error( 'amf_scan_exception', 'Le lot a rencontré une erreur : ' . $e->getMessage() );
		} finally {
			$this->db->release_lock( $scan_id, $token );
		}
	}

	public function progress_payload( $scan = null ) {
		if ( ! $scan ) {
			$scan = $this->db->get_latest_scan();
		}
		if ( ! $scan ) {
			return array( 'exists' => false );
		}

		$performance = $this->get_performance_settings();
		return array(
			'exists'        => true,
			'id'            => (int) $scan['id'],
			'status'        => $scan['status'],
			'phase'         => $scan['phase'],
			'phase_label'   => AMF_Utils::status_label( $scan['phase'] ),
			'phase_current' => (int) $scan['phase_current'],
			'phase_total'   => (int) $scan['phase_total'],
			'message'       => (string) $scan['message'],
			'started_at'    => (string) $scan['started_at'],
			'updated_at'    => (string) $scan['updated_at'],
			'finished_at'   => (string) $scan['finished_at'],
			'batch_limit'    => $this->phase_batch_limit( $scan['phase'], $performance ),
			'request_delay_ms' => (int) $performance['request_delay_ms'],
			'time_budget_seconds' => (int) $performance['time_budget_seconds'],
		);
	}

	private function process_phase( $scan, $settings ) {
		$budget = (int) $settings['time_budget_seconds'];
		switch ( $scan['phase'] ) {
			case 'media':
				return $this->process_media_batch( $scan, (int) $settings['media_batch'], $budget );
			case 'usage_posts':
				return $this->usage_scanner->process_posts_batch( $scan, (int) $settings['posts_batch'], $budget );
			case 'usage_postmeta':
				return $this->usage_scanner->process_postmeta_batch( $scan, (int) $settings['postmeta_batch'], $budget );
			case 'usage_termmeta':
				return $this->usage_scanner->process_termmeta_batch( $scan, (int) $settings['termmeta_batch'], $budget );
			case 'usage_options':
				return $this->usage_scanner->process_options_batch( $scan, (int) $settings['options_batch'], $budget );
			case 'files':
				return $this->file_scanner->process_physical_batch( $scan, (int) $settings['files_batch'] );
			case 'woo':
				return $this->woo_audit->process_batch( $scan, (int) $settings['woo_batch'] );
			case 'finalize':
				$this->db->refresh_usage_counts( (int) $scan['id'] );
				$this->db->refresh_file_counts( (int) $scan['id'] );
				return array( 'done' => true, 'cursor' => 1, 'current' => 1, 'total' => 1 );
			default:
				return array( 'done' => true, 'cursor' => 0, 'current' => 0, 'total' => 0 );
		}
	}

	private function apply_phase_result( $scan, $result ) {
		$scan_id = (int) $scan['id'];
		$data = array(
			'phase_cursor'  => isset( $result['cursor'] ) ? (int) $result['cursor'] : (int) $scan['phase_cursor'],
			'phase_current' => isset( $result['current'] ) ? (int) $result['current'] : (int) $scan['phase_current'],
			'phase_total'   => isset( $result['total'] ) ? (int) $result['total'] : (int) $scan['phase_total'],
			'status'        => 'running',
		);
		if ( isset( $result['message'] ) ) {
			$data['message'] = $result['message'];
		}

		if ( ! empty( $result['done'] ) ) {
			if ( 'usage_options' === $scan['phase'] ) {
				$this->db->refresh_usage_counts( $scan_id );
			}

			$next = $this->next_phase( $scan['phase'] );
			if ( 'complete' === $next ) {
				$data['phase'] = 'complete';
				$data['status'] = 'complete';
				$data['phase_cursor'] = 0;
				$data['phase_current'] = 1;
				$data['phase_total'] = 1;
				$data['finished_at'] = current_time( 'mysql' );
				$data['message'] = 'Analyse terminée. Aucun fichier ni média n’a été modifié ou supprimé.';
			} else {
				$data['phase'] = $next;
				$data['phase_cursor'] = 0;
				$data['phase_current'] = 0;
				$data['phase_total'] = 0;
			}
		}

		$this->db->update_scan( $scan_id, $data );
	}

	private function next_phase( $phase ) {
		$sequence = array(
			'media'          => 'usage_posts',
			'usage_posts'    => 'usage_postmeta',
			'usage_postmeta' => 'usage_termmeta',
			'usage_termmeta' => 'usage_options',
			'usage_options'  => 'files',
			'files'          => 'woo',
			'woo'            => 'finalize',
			'finalize'       => 'complete',
		);
		return isset( $sequence[ $phase ] ) ? $sequence[ $phase ] : 'complete';
	}

	private function process_media_batch( $scan, $limit = 40, $time_budget = 10 ) {
		$scan_id = (int) $scan['id'];
		$cursor  = (int) $scan['phase_cursor'];
		$total   = (int) $scan['phase_total'];

		if ( 0 === $total ) {
			$total = (int) $this->wpdb->get_var(
				"SELECT COUNT(*) FROM {$this->wpdb->posts} WHERE post_type = 'attachment' AND post_mime_type LIKE 'image/%'" // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			);
		}

		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT a.ID, a.post_title, a.post_mime_type, a.post_parent,
				        p.post_type AS parent_post_type, p.post_title AS parent_post_title, p.post_status AS parent_post_status
				 FROM {$this->wpdb->posts} a
				 LEFT JOIN {$this->wpdb->posts} p ON p.ID = a.post_parent
				 WHERE a.ID > %d AND a.post_type = 'attachment' AND a.post_mime_type LIKE 'image/%%'
				 ORDER BY a.ID ASC LIMIT %d",
				$cursor,
				$limit
			),
			ARRAY_A
		);

		$media_table = $this->db->table( 'media' );
		$started = microtime( true );
		$processed = 0;
		foreach ( $rows as $row ) {
			$attachment_id = (int) $row['ID'];
			try {
				$rel_path = AMF_Utils::normalize_rel_path( (string) get_post_meta( $attachment_id, '_wp_attached_file', true ) );
				$metadata = wp_get_attachment_metadata( $attachment_id );
				if ( ! is_array( $metadata ) ) {
					$metadata = array();
				}
				$backup_sizes = get_post_meta( $attachment_id, '_wp_attachment_backup_sizes', true );
				if ( ! is_array( $backup_sizes ) ) {
					$backup_sizes = array();
				}
				$rel_dir = '';
				$filename = '';
				$family_stem = '';
				$file_exists = 0;
				if ( '' !== $rel_path ) {
					$rel_dir = dirname( $rel_path );
					if ( '.' === $rel_dir ) {
						$rel_dir = '';
					}
					$filename = wp_basename( $rel_path );
					$family_stem = AMF_Utils::family_stem_from_rel_path( $rel_path );
					$abs = AMF_Utils::safe_abs_path( $rel_path );
					$file_exists = $abs && is_file( $abs ) ? 1 : 0;
				}

				$sql = $this->wpdb->prepare(
					"INSERT INTO {$media_table}
					(scan_id, attachment_id, title, mime_type, rel_path, path_hash, rel_dir, dir_hash, filename, family_stem, width, height, file_exists)
					VALUES (%d, %d, %s, %s, %s, %s, %s, %s, %s, %s, %d, %d, %d)
					ON DUPLICATE KEY UPDATE
					 title = VALUES(title), mime_type = VALUES(mime_type), rel_path = VALUES(rel_path), path_hash = VALUES(path_hash),
					 rel_dir = VALUES(rel_dir), dir_hash = VALUES(dir_hash), filename = VALUES(filename), family_stem = VALUES(family_stem),
					 width = VALUES(width), height = VALUES(height), file_exists = VALUES(file_exists)",
					$scan_id,
					$attachment_id,
					$row['post_title'],
					$row['post_mime_type'],
					$rel_path,
					AMF_Utils::path_hash( $rel_path ),
					$rel_dir,
					AMF_Utils::dir_hash( $rel_dir ),
					$filename,
					$family_stem,
					isset( $metadata['width'] ) ? absint( $metadata['width'] ) : 0,
					isset( $metadata['height'] ) ? absint( $metadata['height'] ) : 0,
					$file_exists
				);
				$this->wpdb->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

				// post_parent indique uniquement l'origine du téléversement.
				// Il ne doit jamais constituer une utilisation (même probable).

				if ( '' !== $rel_path ) {
					$this->file_scanner->index_attachment_files( $scan_id, $attachment_id, $rel_path, $metadata, $backup_sizes );
					if ( ! $file_exists ) {
						$this->db->add_issue( $scan_id, array(
							'object_type'   => 'media',
							'object_id'     => $attachment_id,
							'object_title'  => $row['post_title'],
							'attachment_id' => $attachment_id,
							'severity'      => 'warning',
							'code'          => 'attachment_file_missing',
							'message'       => 'Le fichier principal déclaré par WordPress est absent du disque.',
							'details'       => $rel_path,
							'edit_url'      => get_edit_post_link( $attachment_id, 'raw' ) ?: '',
						) );
					}
				} else {
					$this->db->add_issue( $scan_id, array(
						'object_type'  => 'media',
						'object_id'    => $attachment_id,
						'object_title' => $row['post_title'],
						'attachment_id'=> $attachment_id,
						'severity'     => 'warning',
						'code'         => 'attachment_path_missing',
						'message'      => 'Attachment image sans chemin _wp_attached_file exploitable.',
						'edit_url'     => get_edit_post_link( $attachment_id, 'raw' ) ?: '',
					) );
				}
			} catch ( Throwable $e ) {
				$this->db->add_issue( $scan_id, array(
					'object_type'  => 'media',
					'object_id'    => $attachment_id,
					'object_title' => $row['post_title'],
					'attachment_id'=> $attachment_id,
					'severity'     => 'warning',
					'code'         => 'media_index_error',
					'message'      => 'Ce média n’a pas pu être indexé complètement.',
					'details'      => $e->getMessage(),
				) );
			}
			$cursor = $attachment_id;
			$processed++;
			if ( $processed > 0 && ( microtime( true ) - $started ) >= max( 1, (float) $time_budget ) ) {
				break;
			}
		}

		return array(
			'done'    => $processed === count( $rows ) && count( $rows ) < $limit,
			'cursor'  => $cursor,
			'current' => min( $total, (int) $scan['phase_current'] + $processed ),
			'total'   => $total,
		);
	}
}
