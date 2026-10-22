<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AMF_Usage_Scanner {
	/** @var AMF_Database */
	private $db;
	/** @var wpdb */
	private $wpdb;

	public function __construct( AMF_Database $db ) {
		$this->db = $db;
		global $wpdb;
		$this->wpdb = $wpdb;
	}

	public function process_posts_batch( $scan, $limit = 100, $time_budget = 10 ) {
		$scan_id = (int) $scan['id'];
		$cursor  = (int) $scan['phase_cursor'];
		$total   = (int) $scan['phase_total'];

		if ( 0 === $total ) {
			$total = (int) $this->wpdb->get_var(
				"SELECT COUNT(*) FROM {$this->wpdb->posts} WHERE post_type NOT IN ('attachment','revision')" // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			);
		}

		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT ID, post_type, post_status, post_title, post_content, post_excerpt, post_parent
				 FROM {$this->wpdb->posts}
				 WHERE ID > %d AND post_type NOT IN ('attachment','revision')
				 ORDER BY ID ASC LIMIT %d",
				$cursor,
				$limit
			),
			ARRAY_A
		);

		$started = microtime( true );
		$processed = 0;
		foreach ( $rows as $row ) {
			try {
				$source = $this->source_for_post_row( $row );
				$this->scan_text_references( $scan_id, $row['post_content'], $source, 'Contenu' );
				$this->scan_text_references( $scan_id, $row['post_excerpt'], $source, 'Extrait' );

				if ( false !== strpos( (string) $row['post_content'], '<!-- wp:' ) && function_exists( 'parse_blocks' ) ) {
					$blocks = parse_blocks( $row['post_content'] );
					$this->scan_blocks( $scan_id, $blocks, $source );
				}
			} catch ( Throwable $e ) {
				$this->db->add_issue( $scan_id, array(
					'object_type'  => 'post',
					'object_id'    => (int) $row['ID'],
					'object_title' => (string) $row['post_title'],
					'source_type'  => 'scan',
					'severity'     => 'warning',
					'code'         => 'usage_post_scan_error',
					'message'      => 'Une erreur a été rencontrée pendant l’analyse de ce contenu.',
					'details'      => $e->getMessage(),
				) );
			}
			$cursor = (int) $row['ID'];
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

	public function process_postmeta_batch( $scan, $limit = 200, $time_budget = 10 ) {
		$scan_id = (int) $scan['id'];
		$cursor  = (int) $scan['phase_cursor'];
		$total   = (int) $scan['phase_total'];

		if ( 0 === $total ) {
			$total = (int) $this->wpdb->get_var(
				"SELECT COUNT(*)
				 FROM {$this->wpdb->postmeta} pm
				 INNER JOIN {$this->wpdb->posts} p ON p.ID = pm.post_id
				 WHERE p.post_type <> 'attachment'
				 AND pm.meta_key NOT IN ('_wp_attachment_metadata','_wp_attached_file','_wp_attachment_backup_sizes')" // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			);
		}

		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT pm.meta_id, pm.post_id, pm.meta_key, pm.meta_value,
				        p.post_type, p.post_status, p.post_title, p.post_parent
				 FROM {$this->wpdb->postmeta} pm
				 INNER JOIN {$this->wpdb->posts} p ON p.ID = pm.post_id
				 WHERE pm.meta_id > %d AND p.post_type <> 'attachment'
				 AND pm.meta_key NOT IN ('_wp_attachment_metadata','_wp_attached_file','_wp_attachment_backup_sizes')
				 ORDER BY pm.meta_id ASC LIMIT %d",
				$cursor,
				$limit
			),
			ARRAY_A
		);

		$started = microtime( true );
		$processed = 0;
		foreach ( $rows as $row ) {
			try {
				$source = $this->source_for_post_row( array(
					'ID'          => $row['post_id'],
					'post_type'   => $row['post_type'],
					'post_status' => $row['post_status'],
					'post_title'  => $row['post_title'],
					'post_parent' => $row['post_parent'],
				) );
				$meta_key = (string) $row['meta_key'];
				$value = $row['meta_value'];

				if ( '_thumbnail_id' === $meta_key && ctype_digit( (string) $value ) ) {
					$this->add_id_reference( $scan_id, (int) $value, $source, 'Image mise en avant', 'confirmed', $meta_key );
				} elseif ( '_product_image_gallery' === $meta_key ) {
					foreach ( array_filter( array_map( 'absint', explode( ',', (string) $value ) ) ) as $attachment_id ) {
						$this->add_id_reference( $scan_id, $attachment_id, $source, 'Galerie du produit', 'confirmed', $meta_key );
					}
				}

				$context = '_elementor_data' === $meta_key ? 'Elementor' : 'Meta : ' . $meta_key;
				$this->scan_text_references( $scan_id, $value, $source, $context, $meta_key );

				if ( ! in_array( $meta_key, array( '_thumbnail_id', '_product_image_gallery' ), true ) && AMF_Utils::likely_attachment_id_key( $meta_key ) ) {
					$this->scan_scalar_image_ids( $scan_id, $value, $source, $context, $meta_key, 'confirmed' );
				}

				$decoded = AMF_Utils::maybe_decode_structured( $value );
				if ( null !== $decoded ) {
					$this->scan_structured_value( $scan_id, $decoded, $source, $context, $meta_key, 0 );
				}
			} catch ( Throwable $e ) {
				$this->db->add_issue( $scan_id, array(
					'object_type'  => 'post',
					'object_id'    => (int) $row['post_id'],
					'object_title' => (string) $row['post_title'],
					'severity'     => 'warning',
					'code'         => 'usage_postmeta_scan_error',
					'message'      => 'Une métadonnée n’a pas pu être analysée complètement.',
					'details'      => $row['meta_key'] . ' — ' . $e->getMessage(),
				) );
			}
			$cursor = (int) $row['meta_id'];
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

	public function process_termmeta_batch( $scan, $limit = 200, $time_budget = 10 ) {
		$scan_id = (int) $scan['id'];
		$cursor  = (int) $scan['phase_cursor'];
		$total   = (int) $scan['phase_total'];

		if ( 0 === $total ) {
			$total = (int) $this->wpdb->get_var( "SELECT COUNT(*) FROM {$this->wpdb->termmeta}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}

		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT tm.meta_id, tm.term_id, tm.meta_key, tm.meta_value,
				        t.name, COALESCE(tt.taxonomy, '') AS taxonomy
				 FROM {$this->wpdb->termmeta} tm
				 LEFT JOIN {$this->wpdb->terms} t ON t.term_id = tm.term_id
				 LEFT JOIN {$this->wpdb->term_taxonomy} tt ON tt.term_id = tm.term_id
				 WHERE tm.meta_id > %d
				 ORDER BY tm.meta_id ASC LIMIT %d",
				$cursor,
				$limit
			),
			ARRAY_A
		);

		$started = microtime( true );
		$processed = 0;
		foreach ( $rows as $row ) {
			try {
				$source = $this->source_for_term_row( $row );
				$meta_key = (string) $row['meta_key'];
				$value = $row['meta_value'];
				$context = 'Terme / ' . $meta_key;

				if ( AMF_Utils::likely_attachment_id_key( $meta_key ) && ctype_digit( (string) $value ) ) {
					$this->add_id_reference( $scan_id, (int) $value, $source, $context, 'confirmed', $meta_key );
				}

				$this->scan_text_references( $scan_id, $value, $source, $context, $meta_key );
				if ( AMF_Utils::likely_attachment_id_key( $meta_key ) ) {
					$this->scan_scalar_image_ids( $scan_id, $value, $source, $context, $meta_key, 'confirmed' );
				}
				$decoded = AMF_Utils::maybe_decode_structured( $value );
				if ( null !== $decoded ) {
					$this->scan_structured_value( $scan_id, $decoded, $source, $context, $meta_key, 0 );
				}
			} catch ( Throwable $e ) {
				$this->db->add_issue( $scan_id, array(
					'object_type'  => 'term',
					'object_id'    => (int) $row['term_id'],
					'object_title' => (string) $row['name'],
					'severity'     => 'warning',
					'code'         => 'usage_termmeta_scan_error',
					'message'      => 'Une métadonnée de taxonomie n’a pas pu être analysée complètement.',
					'details'      => $row['meta_key'] . ' — ' . $e->getMessage(),
				) );
			}
			$cursor = (int) $row['meta_id'];
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

	public function process_options_batch( $scan, $limit = 100, $time_budget = 10 ) {
		$scan_id = (int) $scan['id'];
		$cursor  = (int) $scan['phase_cursor'];
		$total   = (int) $scan['phase_total'];
		$where = "option_name NOT LIKE '\\_transient\\_%' AND option_name NOT LIKE '\\_site\\_transient\\_%' AND option_name NOT LIKE 'amf\\_%' AND option_name NOT IN ('rewrite_rules','cron')";

		if ( 0 === $total ) {
			$total = (int) $this->wpdb->get_var( "SELECT COUNT(*) FROM {$this->wpdb->options} WHERE {$where}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}

		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT option_id, option_name, option_value
				 FROM {$this->wpdb->options}
				 WHERE option_id > %d AND {$where}
				 ORDER BY option_id ASC LIMIT %d",
				$cursor,
				$limit
			),
			ARRAY_A
		);

		$started = microtime( true );
		$processed = 0;
		foreach ( $rows as $row ) {
			try {
				$value = (string) $row['option_value'];
				if ( strlen( $value ) > 2 * MB_IN_BYTES ) {
					$this->db->add_issue( $scan_id, array(
						'object_type'  => 'option',
						'object_id'    => (int) $row['option_id'],
						'object_title' => (string) $row['option_name'],
						'severity'     => 'warning',
						'code'         => 'option_too_large_skipped',
						'message'      => 'Option volumineuse ignorée pour préserver les performances.',
						'details'      => sprintf( '%s octets', strlen( $value ) ),
					) );
				} else {
					$source = array(
						'source_type'    => 'option',
						'source_id'      => (int) $row['option_id'],
						'source_subtype' => (string) $row['option_name'],
						'context_prefix' => 'Option WordPress — ' . $row['option_name'],
						'edit_url'       => '',
						'view_url'       => '',
					);
					$this->scan_text_references( $scan_id, $value, $source, 'Option', (string) $row['option_name'] );
					if ( AMF_Utils::likely_attachment_id_key( $row['option_name'] ) ) {
						$this->scan_scalar_image_ids( $scan_id, $value, $source, 'Option', (string) $row['option_name'], 'confirmed' );
					}
					$decoded = AMF_Utils::maybe_decode_structured( $value );
					if ( null !== $decoded ) {
						$this->scan_structured_value( $scan_id, $decoded, $source, 'Option', (string) $row['option_name'], 0 );
					}
				}
			} catch ( Throwable $e ) {
				$this->db->add_issue( $scan_id, array(
					'object_type'  => 'option',
					'object_id'    => (int) $row['option_id'],
					'object_title' => (string) $row['option_name'],
					'severity'     => 'warning',
					'code'         => 'usage_option_scan_error',
					'message'      => 'Une option WordPress n’a pas pu être analysée complètement.',
					'details'      => $e->getMessage(),
				) );
			}
			$cursor = (int) $row['option_id'];
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

	public function resolve_path_to_attachment( $scan_id, $rel_path ) {
		$rel_path = AMF_Utils::normalize_rel_path( $rel_path );
		if ( '' === $rel_path ) {
			return array( 0, 'none' );
		}

		$files = $this->db->table( 'files' );
		$media = $this->db->table( 'media' );
		$hash = AMF_Utils::path_hash( $rel_path );

		$row = $this->wpdb->get_row(
			$this->wpdb->prepare(
				"SELECT attachment_id, current_meta, classification FROM {$files} WHERE scan_id = %d AND path_hash = %s AND attachment_id IS NOT NULL LIMIT 1",
				$scan_id,
				$hash
			),
			ARRAY_A
		);
		if ( $row && ! empty( $row['attachment_id'] ) ) {
			return array( (int) $row['attachment_id'], 'confirmed' );
		}

		$attachment_id = (int) $this->wpdb->get_var(
			$this->wpdb->prepare(
				"SELECT attachment_id FROM {$media} WHERE scan_id = %d AND path_hash = %s LIMIT 1",
				$scan_id,
				$hash
			)
		);
		if ( $attachment_id ) {
			return array( $attachment_id, 'confirmed' );
		}

		$rel_dir = dirname( $rel_path );
		if ( '.' === $rel_dir ) {
			$rel_dir = '';
		}
		$stem = AMF_Utils::family_stem_from_rel_path( $rel_path );
		$candidates = $this->wpdb->get_col(
			$this->wpdb->prepare(
				"SELECT attachment_id FROM {$media} WHERE scan_id = %d AND dir_hash = %s AND family_stem = %s LIMIT 3",
				$scan_id,
				AMF_Utils::dir_hash( $rel_dir ),
				$stem
			)
		);
		if ( 1 === count( $candidates ) ) {
			return array( (int) $candidates[0], 'probable' );
		}

		return array( 0, 'none' );
	}

	private function scan_text_references( $scan_id, $text, $source, $context, $source_subtype = '' ) {
		$text = (string) $text;
		if ( '' === $text ) {
			return;
		}

		foreach ( AMF_Utils::extract_strong_attachment_ids_from_text( $text ) as $attachment_id ) {
			$this->add_id_reference( $scan_id, $attachment_id, $source, $context, 'confirmed', $source_subtype );
		}

        foreach ( AMF_Reference_Parser::shortcode_references( $text ) as $ref ) {
            $subtype = $source_subtype ?: 'shortcode:' . $ref['tag'] . ':' . $ref['attribute'];
            $recorded = $this->add_id_reference(
                $scan_id, $ref['attachment_id'], $source,
                $context . ' - ' . $ref['role'] . ' [' . $ref['tag'] . '] ' . $ref['attribute'] . '="' . $ref['attachment_id'] . '"',
                'confirmed', $subtype
            );
            if ( ! $recorded ) {
                $this->db->add_issue( $scan_id, array(
                    'object_type' => $source['source_type'], 'object_id' => $source['source_id'],
                    'object_title' => $source['context_prefix'], 'attachment_id' => $ref['attachment_id'],
                    'severity' => 'warning', 'code' => 'shortcode_media_not_indexed',
                    'message' => 'Un shortcode référence un ID absent de l’index des médias de cet audit.',
                    'details' => '[' . $ref['tag'] . '] ' . $ref['attribute'] . '="' . $ref['attachment_id'] . '". Vérifiez cet ID dans WordPress ; il n’est pas remplacé par un média au nom ressemblant.',
                    'edit_url' => $source['edit_url'], 'view_url' => $source['view_url'],
                ) );
            }
        }

        foreach ( AMF_Reference_Parser::upload_references( $text ) as $url_ref ) {
            $rel_path = $url_ref['rel_path'];
			// Local / known proxy paths are confirmed; unknown CDN hosts remain probable.
			$this->db->add_file_usage( $scan_id, $rel_path, array(
				'confidence'     => $url_ref['confidence'],
				'source_type'    => $source['source_type'],
				'source_id'      => $source['source_id'],
				'source_subtype' => $source_subtype ? $source_subtype : $source['source_subtype'],
				'context'        => $source['context_prefix'] . ' — ' . $context . ' — ' . $url_ref['kind'] . ' : ' . wp_basename( $rel_path ),
				'edit_url'       => $source['edit_url'],
				'view_url'       => $source['view_url'],
			) );

			list( $attachment_id, $confidence ) = $this->resolve_path_to_attachment( $scan_id, $rel_path );
			if ( $attachment_id ) {
                if ( 'probable' === $url_ref['confidence'] ) { $confidence = 'probable'; }
				$this->add_id_reference(
					$scan_id,
					$attachment_id,
					$source,
					$context . ' — ' . $url_ref['kind'] . ' : ' . wp_basename( $rel_path ),
					$confidence,
					$source_subtype
				);
			}
		}
	}

	private function scan_scalar_image_ids( $scan_id, $value, $source, $context, $key_path, $confidence ) {
		$value = trim( (string) $value );
		if ( ctype_digit( $value ) ) {
			$this->add_id_reference( $scan_id, (int) $value, $source, $context, $confidence, $key_path );
			return;
		}
		if ( preg_match( '/^[0-9]+(?:\s*,\s*[0-9]+)+$/', $value ) ) {
			foreach ( preg_split( '/\s*,\s*/', $value ) as $id ) {
				$this->add_id_reference( $scan_id, (int) $id, $source, $context, $confidence, $key_path );
			}
		}
	}

	private function scan_structured_value( $scan_id, $value, $source, $context, $key_path = '', $depth = 0 ) {
		if ( $depth > 12 ) {
			return;
		}

		if ( is_array( $value ) || is_object( $value ) ) {
			foreach ( (array) $value as $key => $child ) {
				$child_path = '' === $key_path ? (string) $key : $key_path . '.' . $key;
				$this->scan_structured_value( $scan_id, $child, $source, $context, $child_path, $depth + 1 );
			}
			return;
		}

		if ( is_int( $value ) || ( is_string( $value ) && ctype_digit( trim( $value ) ) ) ) {
			if ( AMF_Utils::likely_attachment_id_key( $key_path ) ) {
				$this->add_id_reference( $scan_id, (int) $value, $source, $context . ' — ' . $key_path, 'confirmed', $key_path );
			}
			return;
		}

		if ( is_string( $value ) ) {
			$this->scan_text_references( $scan_id, $value, $source, $context . ( $key_path ? ' — ' . $key_path : '' ), $key_path );
			if ( AMF_Utils::likely_attachment_id_key( $key_path ) ) {
				$this->scan_scalar_image_ids( $scan_id, $value, $source, $context, $key_path, 'confirmed' );
			}
			$nested = AMF_Utils::maybe_decode_structured( $value );
			if ( null !== $nested && $nested !== $value ) {
				$this->scan_structured_value( $scan_id, $nested, $source, $context, $key_path, $depth + 1 );
			}
		}
	}

	private function scan_blocks( $scan_id, $blocks, $source ) {
		foreach ( (array) $blocks as $block ) {
			$block_name = isset( $block['blockName'] ) ? (string) $block['blockName'] : '';
			$attrs = isset( $block['attrs'] ) ? $block['attrs'] : array();
			if ( ! empty( $attrs ) ) {
				$this->scan_structured_value( $scan_id, $attrs, $source, 'Gutenberg — ' . $block_name, 'gutenberg.' . $block_name, 0 );
			}
			if ( ! empty( $block['innerBlocks'] ) ) {
				$this->scan_blocks( $scan_id, $block['innerBlocks'], $source );
			}
		}
	}

	private function add_id_reference( $scan_id, $attachment_id, $source, $context, $confidence = 'confirmed', $source_subtype = '' ) {
		$attachment_id = absint( $attachment_id );
		if ( ! $attachment_id ) {
			return false;
		}

		$media = $this->db->table( 'media' );
		$exists = (int) $this->wpdb->get_var(
			$this->wpdb->prepare( "SELECT COUNT(*) FROM {$media} WHERE scan_id = %d AND attachment_id = %d", $scan_id, $attachment_id ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
        if ( $this->wpdb->last_error ) { throw new RuntimeException( 'Lecture des médias impossible pendant l’analyse des références.' ); }
		if ( ! $exists ) {
			return false;
		}

		$result = $this->db->add_usage( $scan_id, $attachment_id, array(
			'confidence'     => 'probable' === $confidence ? 'probable' : 'confirmed',
			'source_type'    => $source['source_type'],
			'source_id'      => $source['source_id'],
			'source_subtype' => $source_subtype ? $source_subtype : $source['source_subtype'],
			'context'        => $source['context_prefix'] . ' — ' . $context,
			'edit_url'       => $source['edit_url'],
			'view_url'       => $source['view_url'],
		) );
        if ( false === $result ) { throw new RuntimeException( 'Enregistrement de la preuve d’utilisation impossible.' ); }
        return true;
	}

	private function source_for_post_row( $row ) {
		$post_id = (int) $row['ID'];
		$post_type = (string) $row['post_type'];
		$title = '' !== trim( (string) $row['post_title'] ) ? $row['post_title'] : '(sans titre)';
		$prefix = ucfirst( $post_type );

		if ( 'product' === $post_type ) {
			$prefix = 'Produit';
		} elseif ( 'product_variation' === $post_type ) {
			$prefix = 'Variation';
		} elseif ( 'post' === $post_type ) {
			$prefix = 'Article';
		} elseif ( 'page' === $post_type ) {
			$prefix = 'Page';
		} else {
			$type_obj = get_post_type_object( $post_type );
			if ( $type_obj && ! empty( $type_obj->labels->singular_name ) ) {
				$prefix = $type_obj->labels->singular_name;
			}
		}

		$edit_id = $post_id;
		$view_id = $post_id;
		if ( 'product_variation' === $post_type && ! empty( $row['post_parent'] ) ) {
			$edit_id = (int) $row['post_parent'];
			$view_id = (int) $row['post_parent'];
		}

		return array(
			'source_type'    => $post_type,
			'source_id'      => $post_id,
			'source_subtype' => $post_type,
			'context_prefix' => sprintf( '%s #%d — %s', $prefix, $post_id, $title ),
			'edit_url'       => get_edit_post_link( $edit_id, 'raw' ) ?: '',
			'view_url'       => get_permalink( $view_id ) ?: '',
		);
	}

	private function source_for_term_row( $row ) {
		$term_id = (int) $row['term_id'];
		$taxonomy = (string) $row['taxonomy'];
		$name = (string) $row['name'];
		$prefix = 'Taxonomie ' . $taxonomy;
		if ( 'product_cat' === $taxonomy ) {
			$prefix = 'Catégorie produit';
		} elseif ( false !== stripos( $taxonomy, 'brand' ) || false !== stripos( $taxonomy, 'marque' ) ) {
			$prefix = 'Marque';
		} elseif ( 'category' === $taxonomy ) {
			$prefix = 'Catégorie';
		}

		$edit_url = '';
		$view_url = '';
		if ( $taxonomy ) {
			$edit = get_edit_term_link( $term_id, $taxonomy );
			$view = get_term_link( $term_id, $taxonomy );
			$edit_url = is_wp_error( $edit ) ? '' : (string) $edit;
			$view_url = is_wp_error( $view ) ? '' : (string) $view;
		}

		return array(
			'source_type'    => 'term',
			'source_id'      => $term_id,
			'source_subtype' => $taxonomy,
			'context_prefix' => sprintf( '%s — %s', $prefix, $name ),
			'edit_url'       => $edit_url,
			'view_url'       => $view_url,
		);
	}
}
