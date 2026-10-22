<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AMF_WooCommerce_Audit {
	/** @var AMF_Database */
	private $db;
	/** @var wpdb */
	private $wpdb;

	public function __construct( AMF_Database $db ) {
		$this->db = $db;
		global $wpdb;
		$this->wpdb = $wpdb;
	}

	public function process_batch( $scan, $limit = 25 ) {
		$scan_id = (int) $scan['id'];
		$cursor  = (int) $scan['phase_cursor'];
		$total   = (int) $scan['phase_total'];

		if ( ! class_exists( 'WooCommerce' ) || ! function_exists( 'wc_get_product' ) ) {
			return array(
				'done'    => true,
				'cursor'  => $cursor,
				'current' => 0,
				'total'   => 0,
				'message' => 'WooCommerce n’est pas actif : audit WooCommerce ignoré.',
			);
		}

		$status_sql = "('publish','draft','private','pending','future')";
		if ( 0 === $total ) {
			$total = (int) $this->wpdb->get_var(
				"SELECT COUNT(*) FROM {$this->wpdb->posts} WHERE post_type IN ('product','product_variation') AND post_status IN {$status_sql}" // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			);
		}

		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT ID, post_type, post_title, post_parent, post_status
				 FROM {$this->wpdb->posts}
				 WHERE ID > %d AND post_type IN ('product','product_variation') AND post_status IN {$status_sql}
				 ORDER BY ID ASC LIMIT %d",
				$cursor,
				$limit
			),
			ARRAY_A
		);

		foreach ( $rows as $row ) {
			try {
				if ( 'product' === $row['post_type'] ) {
					$this->audit_product( $scan_id, $row );
				} else {
					$this->audit_variation( $scan_id, $row );
				}
			} catch ( Throwable $e ) {
				$this->db->add_issue( $scan_id, array(
					'object_type'  => $row['post_type'],
					'object_id'    => (int) $row['ID'],
					'object_title' => (string) $row['post_title'],
					'severity'     => 'warning',
					'code'         => 'woo_scan_error',
					'message'      => 'Le produit n’a pas pu être audité complètement.',
					'details'      => $e->getMessage(),
				) );
			}
			$cursor = (int) $row['ID'];
		}

		return array(
			'done'    => count( $rows ) < $limit,
			'cursor'  => $cursor,
			'current' => min( $total, (int) $scan['phase_current'] + count( $rows ) ),
			'total'   => $total,
		);
	}

	private function audit_product( $scan_id, $row ) {
		$product_id = (int) $row['ID'];
		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			$this->db->add_issue( $scan_id, array(
				'object_type'  => 'product',
				'object_id'    => $product_id,
				'object_title' => $row['post_title'],
				'severity'     => 'critical',
				'code'         => 'wc_product_unreadable',
				'message'      => 'WooCommerce ne parvient pas à charger ce produit.',
				'edit_url'     => get_edit_post_link( $product_id, 'raw' ) ?: '',
				'view_url'     => get_permalink( $product_id ) ?: '',
			) );
			return;
		}

		$object = $this->object_context( 'product', $product_id, $product->get_name(), $product_id );
		$thumbnail_id = absint( get_post_meta( $product_id, '_thumbnail_id', true ) );
		if ( $thumbnail_id ) {
			$this->audit_attachment_reference( $scan_id, $object, $thumbnail_id, 'Image principale', array( 'woocommerce_single', 'woocommerce_thumbnail', 'woocommerce_gallery_thumbnail' ) );
		}

		$gallery_raw = (string) get_post_meta( $product_id, '_product_image_gallery', true );
		if ( '' !== trim( $gallery_raw ) ) {
			$gallery_ids = array_filter( array_map( 'absint', explode( ',', $gallery_raw ) ) );
			foreach ( $gallery_ids as $gallery_id ) {
				$this->audit_attachment_reference( $scan_id, $object, $gallery_id, 'Galerie', array( 'woocommerce_single', 'woocommerce_gallery_thumbnail' ) );
			}
		}
	}

	private function audit_variation( $scan_id, $row ) {
		$variation_id = (int) $row['ID'];
		$parent_id = (int) $row['post_parent'];
		$image_id_raw = get_post_meta( $variation_id, '_thumbnail_id', true );

		// A variation without its own image is normal: WooCommerce uses the parent image.
		if ( '' === (string) $image_id_raw || '0' === (string) $image_id_raw ) {
			return;
		}

		$image_id = absint( $image_id_raw );
		$product = wc_get_product( $variation_id );
		$name = $product ? $product->get_name() : ( $row['post_title'] ?: 'Variation #' . $variation_id );
		$object = $this->object_context( 'variation', $variation_id, $name, $parent_id ?: $variation_id );
		$this->audit_attachment_reference( $scan_id, $object, $image_id, 'Image de variation', array( 'woocommerce_single', 'woocommerce_thumbnail', 'woocommerce_gallery_thumbnail' ) );
	}

	private function audit_attachment_reference( $scan_id, $object, $attachment_id, $role, $required_size_names ) {
		$attachment = get_post( $attachment_id );
		if ( ! $attachment || 'attachment' !== $attachment->post_type ) {
			$this->db->add_issue( $scan_id, array_merge( $object, array(
				'attachment_id' => $attachment_id,
				'image_role'    => $role,
				'severity'      => 'critical',
				'code'          => 'attachment_missing',
				'message'       => sprintf( '%s : attachment #%d inexistant.', $role, $attachment_id ),
			) ) );
			return;
		}

		$relative = AMF_Utils::normalize_rel_path( (string) get_post_meta( $attachment_id, '_wp_attached_file', true ) );
		$absolute = AMF_Utils::safe_abs_path( $relative );
		if ( ! $absolute || ! is_file( $absolute ) ) {
			$this->db->add_issue( $scan_id, array_merge( $object, array(
				'attachment_id' => $attachment_id,
				'image_role'    => $role,
				'file_rel_path' => $relative,
				'severity'      => 'critical',
				'code'          => 'original_missing',
				'message'       => sprintf( '%s : le fichier principal de l’attachment #%d est absent du disque.', $role, $attachment_id ),
				'details'       => $relative,
			) ) );
			return;
		}

		$size = function_exists( 'wp_getimagesize' ) ? wp_getimagesize( $absolute ) : @getimagesize( $absolute );
		if ( ! is_array( $size ) || empty( $size[0] ) || empty( $size[1] ) ) {
			$this->db->add_issue( $scan_id, array_merge( $object, array(
				'attachment_id' => $attachment_id,
				'image_role'    => $role,
				'file_rel_path' => $relative,
				'severity'      => 'critical',
				'code'          => 'image_unreadable',
				'message'       => sprintf( '%s : le fichier image existe mais ses dimensions sont illisibles.', $role ),
				'details'       => $relative,
			) ) );
			return;
		}

		$source_width = (int) $size[0];
		$source_height = (int) $size[1];
		$metadata = wp_get_attachment_metadata( $attachment_id );
		if ( ! is_array( $metadata ) ) {
			$metadata = array();
		}
		$registered = function_exists( 'wp_get_registered_image_subsizes' ) ? wp_get_registered_image_subsizes() : array();
		$too_small = array();
		$missing = array();

		foreach ( $required_size_names as $size_name ) {
			if ( empty( $registered[ $size_name ] ) || ! is_array( $registered[ $size_name ] ) ) {
				continue;
			}
			$config = $registered[ $size_name ];
			$req_w = isset( $config['width'] ) ? absint( $config['width'] ) : 0;
			$req_h = isset( $config['height'] ) ? absint( $config['height'] ) : 0;
			$crop  = ! empty( $config['crop'] );

			if ( $this->source_too_small( $source_width, $source_height, $req_w, $req_h, $crop ) ) {
				$too_small[] = sprintf( '%s (%dx%d%s)', $size_name, $req_w, $req_h, $crop ? ', crop' : '' );
				continue;
			}

			if ( ! $this->subsize_should_exist( $source_width, $source_height, $req_w, $req_h, $crop ) ) {
				continue;
			}

			if ( empty( $metadata['sizes'][ $size_name ] ) || ! is_array( $metadata['sizes'][ $size_name ] ) ) {
				$missing[] = $size_name . ' (métadonnée absente)';
				continue;
			}

			if ( ! $this->metadata_size_has_physical_file( $metadata['sizes'][ $size_name ], $relative ) ) {
				$missing[] = $size_name . ' (fichier absent)';
			}
		}

		if ( ! empty( $too_small ) ) {
			$this->db->add_issue( $scan_id, array_merge( $object, array(
				'attachment_id' => $attachment_id,
				'image_role'    => $role,
				'file_rel_path' => $relative,
				'severity'      => 'manual',
				'code'          => 'source_too_small',
				'message'       => sprintf( '%s : source %dx%d px plus petite qu’au moins une taille WooCommerce attendue. Risque de pixellisation.', $role, $source_width, $source_height ),
				'details'       => 'Tailles concernées : ' . implode( ', ', $too_small ) . '. Fichier : ' . $relative,
			) ) );
		}

		if ( ! empty( $missing ) ) {
			$this->db->add_issue( $scan_id, array_merge( $object, array(
				'attachment_id' => $attachment_id,
				'image_role'    => $role,
				'file_rel_path' => $relative,
				'severity'      => 'regenerable',
				'code'          => 'woo_subsize_missing',
				'message'       => sprintf( '%s : une ou plusieurs tailles WooCommerce actuellement nécessaires sont absentes.', $role ),
				'details'       => 'Tailles : ' . implode( ', ', $missing ) . '. Source : ' . $source_width . '×' . $source_height . ' px. Le plugin ne régénère rien automatiquement.',
			) ) );
		}
	}


	private function metadata_size_has_physical_file( $size_data, $attached_relative ) {
		$files = array();
		$walker = function ( $node ) use ( &$walker, &$files ) {
			if ( ! is_array( $node ) ) {
				return;
			}
			foreach ( $node as $key => $value ) {
				if ( 'file' === (string) $key && is_string( $value ) ) {
					$files[] = $value;
				} elseif ( is_array( $value ) ) {
					$walker( $value );
				}
			}
		};
		$walker( $size_data );
		if ( empty( $files ) ) {
			return false;
		}

		$dir = dirname( $attached_relative );
		if ( '.' === $dir ) {
			$dir = '';
		}
		foreach ( array_unique( $files ) as $file ) {
			$rel = false !== strpos( $file, '/' ) ? AMF_Utils::normalize_rel_path( $file ) : AMF_Utils::normalize_rel_path( ( $dir ? $dir . '/' : '' ) . $file );
			$abs = AMF_Utils::safe_abs_path( $rel );
			if ( $abs && is_file( $abs ) ) {
				return true;
			}
		}
		return false;
	}

	private function source_too_small( $source_w, $source_h, $req_w, $req_h, $crop ) {
		if ( $crop ) {
			return ( $req_w && $source_w < $req_w ) || ( $req_h && $source_h < $req_h );
		}
		if ( $req_w && ! $req_h ) {
			return $source_w < $req_w;
		}
		if ( $req_h && ! $req_w ) {
			return $source_h < $req_h;
		}
		if ( $req_w && $req_h ) {
			return $source_w < $req_w && $source_h < $req_h;
		}
		return false;
	}

	private function subsize_should_exist( $source_w, $source_h, $req_w, $req_h, $crop ) {
		if ( $crop ) {
			return $source_w > $req_w || $source_h > $req_h;
		}
		if ( $req_w && ! $req_h ) {
			return $source_w > $req_w;
		}
		if ( $req_h && ! $req_w ) {
			return $source_h > $req_h;
		}
		if ( $req_w && $req_h ) {
			return $source_w > $req_w || $source_h > $req_h;
		}
		return false;
	}

	private function object_context( $type, $id, $title, $front_id ) {
		return array(
			'object_type'  => $type,
			'object_id'    => $id,
			'object_title' => $title,
			'edit_url'     => get_edit_post_link( $front_id, 'raw' ) ?: '',
			'view_url'     => get_permalink( $front_id ) ?: '',
		);
	}
}
