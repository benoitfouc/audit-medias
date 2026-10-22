<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AMF_File_Scanner {
	/** @var AMF_Database */
	private $db;
	/** @var wpdb */
	private $wpdb;

	public function __construct( AMF_Database $db ) {
		$this->db = $db;
		global $wpdb;
		$this->wpdb = $wpdb;
	}

	public function index_attachment_files( $scan_id, $attachment_id, $rel_path, $metadata, $backup_sizes = array() ) {
		$rel_path = AMF_Utils::normalize_rel_path( $rel_path );
		if ( '' === $rel_path ) {
			return;
		}
		$dir = dirname( $rel_path );
		if ( '.' === $dir ) {
			$dir = '';
		}

		$this->upsert_expected_file( $scan_id, $attachment_id, $rel_path, 'wp_original', 100, 1, 'Fichier principal déclaré par WordPress.' );

		if ( is_array( $metadata ) ) {
			if ( ! empty( $metadata['original_image'] ) && is_string( $metadata['original_image'] ) ) {
				$this->upsert_expected_file(
					$scan_id,
					$attachment_id,
					$this->join_rel( $dir, $metadata['original_image'] ),
					'wp_original_source',
					100,
					1,
					'Image source originale référencée par les métadonnées WordPress.'
				);
			}

			if ( ! empty( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) ) {
				foreach ( $metadata['sizes'] as $size_name => $size_data ) {
					if ( ! is_array( $size_data ) ) {
						continue;
					}
					if ( ! empty( $size_data['file'] ) && is_string( $size_data['file'] ) ) {
						$this->upsert_expected_file(
							$scan_id,
							$attachment_id,
							$this->join_rel( $dir, $size_data['file'] ),
							'wp_thumbnail',
							100,
							1,
							'Taille WordPress actuelle : ' . $size_name,
							isset( $size_data['width'] ) ? absint( $size_data['width'] ) : 0,
							isset( $size_data['height'] ) ? absint( $size_data['height'] ) : 0
						);
					}
					$this->index_nested_metadata_files( $scan_id, $attachment_id, $dir, $size_data, 'wp_metadata_variant', 'Métadonnée actuelle : ' . $size_name );
				}
			}

			$this->index_nested_metadata_files( $scan_id, $attachment_id, $dir, $metadata, 'wp_metadata_variant', 'Fichier référencé dans les métadonnées WordPress.' );
		}

		if ( is_array( $backup_sizes ) ) {
			foreach ( $backup_sizes as $name => $data ) {
				if ( is_array( $data ) && ! empty( $data['file'] ) ) {
					$this->upsert_expected_file(
						$scan_id,
						$attachment_id,
						$this->join_rel( $dir, $data['file'] ),
						'wp_backup',
						100,
						1,
						'Sauvegarde WordPress : ' . $name,
						isset( $data['width'] ) ? absint( $data['width'] ) : 0,
						isset( $data['height'] ) ? absint( $data['height'] ) : 0
					);
				}
			}
		}
	}

	public function initialize_directory_queue( $scan_id ) {
		$table = $this->db->table( 'dirs' );
		$sql = $this->wpdb->prepare(
			"INSERT IGNORE INTO {$table} (scan_id, rel_dir, path_hash, cursor_pos, status) VALUES (%d, %s, %s, 0, 'pending')",
			$scan_id,
			'',
			AMF_Utils::dir_hash( '' )
		);
		$this->wpdb->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	public function process_physical_batch( $scan, $limit = 250 ) {
		$scan_id = (int) $scan['id'];
		$this->initialize_directory_queue( $scan_id );
		$dirs_table = $this->db->table( 'dirs' );

		$dir_row = $this->wpdb->get_row(
			$this->wpdb->prepare(
				"SELECT * FROM {$dirs_table} WHERE scan_id = %d AND status IN ('pending','processing') ORDER BY id ASC LIMIT 1",
				$scan_id
			),
			ARRAY_A
		);

		if ( ! $dir_row ) {
			$this->db->refresh_file_counts( $scan_id );
			return array(
				'done'    => true,
				'cursor'  => (int) $scan['phase_cursor'],
				'current' => (int) $scan['phase_current'],
				'total'   => 0,
			);
		}

		$rel_dir = AMF_Utils::normalize_rel_path( $dir_row['rel_dir'] );
		$uploads = AMF_Utils::uploads();
		$abs_dir = $uploads['basedir'] . ( $rel_dir ? '/' . $rel_dir : '' );

		if ( is_link( $abs_dir ) ) {
			$this->mark_dir_done( $dir_row['id'] );
			$this->db->add_issue( $scan_id, array(
				'object_type'  => 'filesystem',
				'object_title' => $rel_dir,
				'severity'     => 'warning',
				'code'         => 'symlink_directory_skipped',
				'message'      => 'Un lien symbolique a été ignoré pour éviter de sortir du dossier uploads.',
			) );
			return array( 'done' => false, 'cursor' => (int) $scan['phase_cursor'], 'current' => (int) $scan['phase_current'], 'total' => 0 );
		}

		$real = realpath( $abs_dir );
		$base_real = realpath( $uploads['basedir'] );
		if ( false === $real || false === $base_real || 0 !== strpos( wp_normalize_path( $real ), wp_normalize_path( $base_real ) ) || ! is_readable( $abs_dir ) ) {
			$this->mark_dir_done( $dir_row['id'] );
			$this->db->add_issue( $scan_id, array(
				'object_type'  => 'filesystem',
				'object_title' => $rel_dir,
				'severity'     => 'warning',
				'code'         => 'directory_unreadable',
				'message'      => 'Un dossier dans uploads n’a pas pu être lu.',
				'details'      => $rel_dir,
			) );
			return array( 'done' => false, 'cursor' => (int) $scan['phase_cursor'], 'current' => (int) $scan['phase_current'], 'total' => 0 );
		}

		$entries = @scandir( $abs_dir, SCANDIR_SORT_ASCENDING );
		if ( false === $entries ) {
			$this->mark_dir_done( $dir_row['id'] );
			$this->db->add_issue( $scan_id, array(
				'object_type'  => 'filesystem',
				'object_title' => $rel_dir,
				'severity'     => 'warning',
				'code'         => 'directory_scan_failed',
				'message'      => 'Impossible de lister un dossier dans uploads.',
				'details'      => $rel_dir,
			) );
			return array( 'done' => false, 'cursor' => (int) $scan['phase_cursor'], 'current' => (int) $scan['phase_current'], 'total' => 0 );
		}

		$cursor_pos = (int) $dir_row['cursor_pos'];
		$processed_entries = 0;
		$processed_images = 0;
		$total_entries = count( $entries );

		$this->wpdb->update( $dirs_table, array( 'status' => 'processing' ), array( 'id' => (int) $dir_row['id'] ), array( '%s' ), array( '%d' ) );

		for ( $i = $cursor_pos; $i < $total_entries && $processed_entries < $limit; $i++ ) {
			$name = $entries[ $i ];
			$processed_entries++;
			if ( '.' === $name || '..' === $name ) {
				continue;
			}

			$abs_path = $abs_dir . '/' . $name;
			$child_rel = AMF_Utils::normalize_rel_path( ( $rel_dir ? $rel_dir . '/' : '' ) . $name );

			if ( is_link( $abs_path ) ) {
				continue;
			}
			if ( is_dir( $abs_path ) ) {
				$this->queue_directory( $scan_id, $child_rel );
				continue;
			}
			if ( ! is_file( $abs_path ) ) {
				continue;
			}

			$extension = strtolower( pathinfo( $name, PATHINFO_EXTENSION ) );
			if ( ! AMF_Utils::is_image_extension( $extension ) ) {
				continue;
			}

			$bytes = @filesize( $abs_path );
			$this->index_physical_file( $scan_id, $child_rel, false === $bytes ? 0 : (int) $bytes );
			$processed_images++;
		}

		$new_cursor = min( $total_entries, $cursor_pos + $processed_entries );
		if ( $new_cursor >= $total_entries ) {
			$this->wpdb->update(
				$dirs_table,
				array( 'cursor_pos' => $new_cursor, 'status' => 'done' ),
				array( 'id' => (int) $dir_row['id'] ),
				array( '%d', '%s' ),
				array( '%d' )
			);
		} else {
			$this->wpdb->update(
				$dirs_table,
				array( 'cursor_pos' => $new_cursor, 'status' => 'processing' ),
				array( 'id' => (int) $dir_row['id'] ),
				array( '%d', '%s' ),
				array( '%d' )
			);
		}

		$pending = (int) $this->wpdb->get_var(
			$this->wpdb->prepare( "SELECT COUNT(*) FROM {$dirs_table} WHERE scan_id = %d AND status IN ('pending','processing')", $scan_id ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
		$done = 0 === $pending;
		if ( $done ) {
			$this->db->refresh_file_counts( $scan_id );
		}

		return array(
			'done'    => $done,
			'cursor'  => (int) $scan['phase_cursor'] + $processed_images,
			'current' => (int) $scan['phase_current'] + $processed_images,
			'total'   => 0,
		);
	}

	private function index_nested_metadata_files( $scan_id, $attachment_id, $dir, $node, $classification, $note, $depth = 0 ) {
		if ( $depth > 8 || ! is_array( $node ) ) {
			return;
		}
		foreach ( $node as $key => $value ) {
			if ( 'file' === (string) $key && is_string( $value ) ) {
				$extension = strtolower( pathinfo( $value, PATHINFO_EXTENSION ) );
				if ( AMF_Utils::is_image_extension( $extension ) ) {
					$rel = false !== strpos( $value, '/' ) ? AMF_Utils::normalize_rel_path( $value ) : $this->join_rel( $dir, $value );
					$this->upsert_expected_file( $scan_id, $attachment_id, $rel, $classification, 100, 1, $note );
				}
			} elseif ( is_array( $value ) ) {
				$this->index_nested_metadata_files( $scan_id, $attachment_id, $dir, $value, $classification, $note, $depth + 1 );
			}
		}
	}

	private function upsert_expected_file( $scan_id, $attachment_id, $rel_path, $classification, $confidence, $current_meta, $note, $width = 0, $height = 0 ) {
		$rel_path = AMF_Utils::normalize_rel_path( $rel_path );
		if ( '' === $rel_path ) {
			return;
		}
		$rel_dir = dirname( $rel_path );
		if ( '.' === $rel_dir ) {
			$rel_dir = '';
		}
		$basename = wp_basename( $rel_path );
		$ext = strtolower( pathinfo( $basename, PATHINFO_EXTENSION ) );
		$table = $this->db->table( 'files' );

		$sql = $this->wpdb->prepare(
			"INSERT INTO {$table}
			(scan_id, attachment_id, rel_path, path_hash, dir_hash, family_key, family_stem, basename, extension, classification, association_confidence, physical, current_meta, bytes, width, height, note)
			VALUES (%d, %d, %s, %s, %s, %s, %s, %s, %s, %s, %d, 0, %d, 0, %d, %d, %s)
			ON DUPLICATE KEY UPDATE
			 attachment_id = VALUES(attachment_id),
			 family_key = VALUES(family_key),
			 family_stem = VALUES(family_stem),
			 classification = IF(current_meta = 1 AND classification <> 'wp_metadata_variant', classification, VALUES(classification)),
			 association_confidence = GREATEST(association_confidence, VALUES(association_confidence)),
			 current_meta = GREATEST(current_meta, VALUES(current_meta)),
			 width = GREATEST(width, VALUES(width)),
			 height = GREATEST(height, VALUES(height)),
			 note = VALUES(note)",
			$scan_id,
			$attachment_id,
			$rel_path,
			AMF_Utils::path_hash( $rel_path ),
			AMF_Utils::dir_hash( $rel_dir ),
			AMF_Utils::family_key_for_attachment( $attachment_id ),
			AMF_Utils::family_stem_from_rel_path( $rel_path ),
			$basename,
			$ext,
			$classification,
			$confidence,
			$current_meta,
			$width,
			$height,
			$note
		);
		$this->wpdb->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	private function index_physical_file( $scan_id, $rel_path, $bytes ) {
		$table = $this->db->table( 'files' );
		$media = $this->db->table( 'media' );
		$hash = AMF_Utils::path_hash( $rel_path );
		$basename = wp_basename( $rel_path );
		$extension = strtolower( pathinfo( $basename, PATHINFO_EXTENSION ) );
		$rel_dir = dirname( $rel_path );
		if ( '.' === $rel_dir ) {
			$rel_dir = '';
		}

		$known = $this->wpdb->get_row(
			$this->wpdb->prepare( "SELECT id, attachment_id FROM {$table} WHERE scan_id = %d AND path_hash = %s LIMIT 1", $scan_id, $hash ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);
		if ( $known ) {
			$this->wpdb->update(
				$table,
				array( 'physical' => 1, 'bytes' => $bytes, 'extension' => $extension, 'family_key' => ! empty( $known['attachment_id'] ) ? AMF_Utils::family_key_for_attachment( (int) $known['attachment_id'] ) : AMF_Utils::family_key_for_unassociated( $rel_dir, AMF_Utils::family_stem_from_rel_path( $rel_path ) ), 'family_stem' => AMF_Utils::family_stem_from_rel_path( $rel_path ) ),
				array( 'id' => (int) $known['id'] ),
				array( '%d', '%d', '%s', '%s', '%s' ),
				array( '%d' )
			);
			return;
		}

		$parts = AMF_Utils::canonical_file_parts( $basename );
		$candidates = $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT attachment_id, filename FROM {$media} WHERE scan_id = %d AND dir_hash = %s AND family_stem = %s LIMIT 3",
				$scan_id,
				AMF_Utils::dir_hash( $rel_dir ),
				$parts['family_stem']
			),
			ARRAY_A
		);

		$attachment_id = null;
		$classification = 'unassociated';
		$confidence = 0;
		$note = 'Aucun attachment correspondant identifié.';

		if ( 1 === count( $candidates ) ) {
			$attachment_id = (int) $candidates[0]['attachment_id'];
			$confidence = 75;
			if ( $parts['is_conversion'] ) {
				$classification = 'conversion_' . $parts['outer_ext'];
				$note = 'Conversion de format rattachée à cette famille par nom et dossier (association probable).';
			} elseif ( $parts['had_dimensions'] ) {
				$classification = 'legacy_thumbnail';
				$note = 'Ancienne miniature probable, absente des métadonnées WordPress actuelles.';
			} elseif ( $parts['had_scaled'] || $parts['had_rotated'] ) {
				$classification = 'legacy_variant';
				$note = 'Variante scaled/rotated probablement liée à cet attachment.';
			} else {
				$classification = 'family_derivative';
				$confidence = 65;
				$note = 'Fichier de même famille nominale ; association probable uniquement.';
			}
		} elseif ( count( $candidates ) > 1 ) {
			$classification = 'unassociated_ambiguous';
			$note = 'Plusieurs attachments pourraient correspondre : aucune association automatique.';
		}

		$family_stem = $parts['family_stem'];
		$family_key = $attachment_id ? AMF_Utils::family_key_for_attachment( $attachment_id ) : AMF_Utils::family_key_for_unassociated( $rel_dir, $family_stem );

		$this->wpdb->insert(
			$table,
			array(
				'scan_id'                => $scan_id,
				'attachment_id'          => $attachment_id,
				'rel_path'               => $rel_path,
				'path_hash'              => $hash,
				'dir_hash'               => AMF_Utils::dir_hash( $rel_dir ),
				'family_key'             => $family_key,
				'family_stem'            => $family_stem,
				'basename'               => $basename,
				'extension'              => $extension,
				'classification'         => $classification,
				'association_confidence' => $confidence,
				'physical'               => 1,
				'current_meta'           => 0,
				'bytes'                  => $bytes,
				'width'                  => 0,
				'height'                 => 0,
				'note'                   => $note,
			),
			array( '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%d', '%d', '%d', '%s' )
		);
	}

	private function queue_directory( $scan_id, $rel_dir ) {
		$table = $this->db->table( 'dirs' );
		$sql = $this->wpdb->prepare(
			"INSERT IGNORE INTO {$table} (scan_id, rel_dir, path_hash, cursor_pos, status) VALUES (%d, %s, %s, 0, 'pending')",
			$scan_id,
			AMF_Utils::normalize_rel_path( $rel_dir ),
			AMF_Utils::dir_hash( $rel_dir )
		);
		$this->wpdb->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	private function mark_dir_done( $dir_id ) {
		$this->wpdb->update( $this->db->table( 'dirs' ), array( 'status' => 'done' ), array( 'id' => (int) $dir_id ), array( '%s' ), array( '%d' ) );
	}

	private function join_rel( $dir, $file ) {
		$file = AMF_Utils::normalize_rel_path( $file );
		if ( '' === $dir ) {
			return $file;
		}
		if ( 0 === strpos( $file, $dir . '/' ) ) {
			return $file;
		}
		return AMF_Utils::normalize_rel_path( $dir . '/' . $file );
	}
}
