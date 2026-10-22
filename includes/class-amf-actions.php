<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AMF_Actions {
	/** @var AMF_Database */
	private $db;
	/** @var wpdb */
	private $wpdb;

	public function __construct( AMF_Database $db ) {
		$this->db = $db;
		global $wpdb;
		$this->wpdb = $wpdb;
	}

	public function deletion_enabled() {
		return (bool) get_option( 'amf_deletion_enabled', false );
	}

	public function set_deletion_enabled( $enabled ) {
		update_option( 'amf_deletion_enabled', $enabled ? 1 : 0, false );
		return $this->deletion_enabled();
	}

	public function delete_attachment( $scan_id, $attachment_id ) {
		if ( ! $this->deletion_enabled() ) {
			return new WP_Error( 'amf_delete_disabled', 'Les actions de suppression sont désactivées dans les réglages.' );
		}

		$scan_id = absint( $scan_id );
		$attachment_id = absint( $attachment_id );
		$scan = $this->db->get_scan( $scan_id );
		if ( ! $scan || 'complete' !== $scan['status'] ) {
			return new WP_Error( 'amf_delete_scan_incomplète', 'La suppression n’est autorisée qu’après une analyse terminée.' );
		}

        if ( ! AMF_Reference_Parser::scan_is_current( $scan ) ) {
            return new WP_Error( 'amf_old_reference_scan', 'Suppression bloquée : recommencez l’analyse pour prendre en compte les arrière-plans et les URL CDN.' );
        }

		$media_table = $this->db->table( 'media' );
		$row = $this->wpdb->get_row(
			$this->wpdb->prepare(
				"SELECT * FROM {$media_table} WHERE scan_id = %d AND attachment_id = %d LIMIT 1",
				$scan_id,
				$attachment_id
			),
			ARRAY_A
		);
		if ( ! $row ) {
			return new WP_Error( 'amf_delete_media_missing', 'Ce média n’existe pas dans les résultats de l’analyse.' );
		}
		if ( (int) $row['usage_confirmed'] > 0 || (int) $row['usage_probable'] > 0 ) {
			return new WP_Error( 'amf_delete_media_used', 'Suppression bloquée : une utilisation confirmée ou une association probable a été détectée.' );
		}

		$post = get_post( $attachment_id );
		if ( ! $post || 'attachment' !== $post->post_type ) {
			return new WP_Error( 'amf_delete_attachment_missing', 'L’attachment WordPress n’existe plus.' );
		}

        if (!current_user_can('delete_post',$attachment_id)) {
            return new WP_Error('amf_delete_forbidden_attachment','Vous ne pouvez pas supprimer ce média.');
        }
        $product_usage = new AMF_Product_Usage($this->db);
        $live_count = $product_usage->live_count($attachment_id);
        if (is_wp_error($live_count)) { return $live_count; }
        if ($live_count > 0) {
            return new WP_Error('amf_delete_live_product_usage','Cette image est actuellement affectée à un produit. Contrôlez ses utilisations dans Fichiers physiques avant toute suppression forcée.');
        }

        // An attachment can have no direct ID usage while one of its thumbnails
        // or AVIF/WebP derivatives is still referenced by URL. Check the *whole*
        // family and the files that WordPress itself would remove.
        $safety = $this->verify_attachment_family_for_deletion( $scan_id, $attachment_id, $row );
        if ( is_wp_error( $safety ) ) { return $safety; }

		$before = array(
			'attachment_id' => $attachment_id,
			'title'         => $post->post_title,
			'rel_path'      => $row['rel_path'],
			'physical_count'=> (int) $row['physical_count'],
			'total_bytes'   => (int) $row['total_bytes'],
		);

		$deleted = wp_delete_attachment( $attachment_id, true );
		if ( ! $deleted ) {
			return new WP_Error( 'amf_delete_failed', 'WordPress n’a pas pu supprimer ce média.' );
		}

		$this->remove_attachment_scan_rows( $scan_id, $attachment_id );
		$this->db->log_action( 'delete_attachment', 'attachment', $attachment_id, $before );

		return array(
			'message' => sprintf( 'Le média #%d a été supprimé définitivement par WordPress.', $attachment_id ),
			'attachment_id' => $attachment_id,
		);
	}

	/**
	 * Suppression volontaire d'une famille physique complète. Si elle est rattachee
	 * a un attachment WP, l'API native retire aussi l'entree de la mediatheque.
	 * Les familles utilisees/ambiguës exigent une confirmation renforcée.
	 */
	public function delete_family( $scan_id, $family_key, $force = false, $confirmation = '' ) {
		if ( ! $this->deletion_enabled() ) {
			return new WP_Error( 'amf_delete_disabled', 'Activez la suppression manuelle dans les réglages.' );
		}

		$scan_id = absint( $scan_id );
		$family_key = strtolower( (string) $family_key );
		if ( ! preg_match( '/^[a-f0-9]{32}$/D', $family_key ) ) {
			return new WP_Error( 'amf_delete_bad_family', 'Clé de famille invalide.' );
		}
		$scan = $this->db->get_scan( $scan_id );
		if ( ! $scan || 'complete' !== $scan['status'] ) {
			return new WP_Error( 'amf_delete_scan_incomplète', 'La suppression est disponible uniquement après une analyse terminée.' );
		}

        if ( ! AMF_Reference_Parser::scan_is_current( $scan ) ) {
            return new WP_Error( 'amf_old_reference_scan', 'Suppression bloquée : recommencez l’analyse pour prendre en compte les arrière-plans et les URL CDN.' );
        }

		$files_table = $this->db->table( 'files' );
		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT * FROM {$files_table} WHERE scan_id = %d AND family_key = %s AND physical = 1 ORDER BY id ASC",
				$scan_id,
				$family_key
			),
			ARRAY_A
		);
		if ( ! $rows ) {
			return new WP_Error( 'amf_delete_family_missing', 'Cette famille ne figure pas dans le scan. Recommencez l’analyse.' );
		}

		$attachment_ids = array();
		$direct_refs = 0;
		$ambiguous = 0;
		$paths = array();
		foreach ( $rows as $row ) {
			$id = absint( $row['attachment_id'] );
			if ( $id ) {
				$attachment_ids[ $id ] = true;
			}
			$direct_refs += absint( $row['direct_usage_count'] );
			if ( 'unassociated_ambiguous' === $row['classification'] ) {
				$ambiguous++;
			}
			$rel = AMF_Utils::normalize_rel_path( $row['rel_path'] );
			if ( '' === $rel || ! AMF_Utils::looks_like_image_rel_path( $rel ) || ! AMF_Utils::safe_abs_path( $rel ) ) {
				return new WP_Error( 'amf_delete_bad_path', 'Un fichier possède un chemin non conforme. Suppression intégralement annulée.' );
			}
			$paths[ $rel ] = true;
		}
		if ( count( $attachment_ids ) > 1 ) {
			return new WP_Error( 'amf_delete_multiple_attachments', 'Cette famille semble mélanger plusieurs médias WordPress. Suppression bloquée : contrôlez le regroupement.' );
		}

		$attachment_id = $attachment_ids ? (int) key( $attachment_ids ) : 0;
		if ( $attachment_id && ! hash_equals( AMF_Utils::family_key_for_attachment( $attachment_id ), $family_key ) ) {
			return new WP_Error( 'amf_delete_owner_conflict', 'La clé de famille et son attachment ne correspondent pas. Recommencez le scan.' );
		}

		$media_row = null;
		$usage_count = 0;
		if ( $attachment_id ) {
			$media_table = $this->db->table( 'media' );
			$media_row = $this->wpdb->get_row( $this->wpdb->prepare(
				"SELECT * FROM {$media_table} WHERE scan_id = %d AND attachment_id = %d LIMIT 1",
				$scan_id,
				$attachment_id
			), ARRAY_A );
			$usage_count = $media_row ? absint( $media_row['usage_confirmed'] ) + absint( $media_row['usage_probable'] ) : 0;
		}

        $live_product_count=0;
        if ($attachment_id) {
            $live_product_count=(new AMF_Product_Usage($this->db))->live_count($attachment_id);
            if (is_wp_error($live_product_count)) { return $live_product_count; }
            $usage_count=max($usage_count,$live_product_count);
        }

		$requires_override = (bool) ( $attachment_id || $usage_count || $direct_refs || $ambiguous );
		if ( $requires_override && ( ! $force || ! hash_equals( 'SUPPRIMER', (string) $confirmation ) ) ) {
			return new WP_Error( 'amf_delete_force_required', 'Cette famille est rattachée, référencée ou ambiguë. Confirmez explicitement en saisissant SUPPRIMER.' );
		}

		if ( $attachment_id ) {
			$post = get_post( $attachment_id );
			if ( ! $post || 'attachment' !== $post->post_type ) {
				return new WP_Error( 'amf_delete_missing_attachment', 'Le média a changé ou a disparu depuis le scan. Recommencez l’analyse.' );
			}
			if ( ! current_user_can( 'delete_post', $attachment_id ) ) {
				return new WP_Error( 'amf_delete_forbidden_attachment', 'Vous ne disposez pas du droit de supprimer ce média WordPress.' );
			}

			// Ne jamais supprimer un attachment dont le chemin principal a changé
			// depuis l'analyse, ni un original partage avec un autre attachment.
			$current_rel = AMF_Utils::normalize_rel_path( (string) get_post_meta( $attachment_id, '_wp_attached_file', true ) );
			if ( ! $media_row || $current_rel !== AMF_Utils::normalize_rel_path( $media_row['rel_path'] ) ) {
				return new WP_Error( 'amf_delete_changed_attachment', 'Le chemin principal du média a changé depuis l’analyse. Relancez le scan.' );
			}
			if ( '' !== $current_rel ) {
				// Un fichier principal créé ou remplacé après le scan ne doit pas
				// être supprimé implicitement par wp_delete_attachment().
				$main_abs = AMF_Utils::safe_abs_path( $current_rel );
				if ( ! isset( $paths[ $current_rel ] ) && $main_abs && is_file( $main_abs ) ) {
					return new WP_Error( 'amf_delete_new_original', 'Un original physique n’était pas présent lors du scan. Relancez l’analyse avant de supprimer.' );
				}
				// Même principe pour les nouvelles sous-tailles déclarées depuis
				// le scan. Un scan périmé ne peut autoriser une suppression étendue.
				$live_meta = wp_get_attachment_metadata( $attachment_id );
				if ( is_array( $live_meta ) && ! empty( $live_meta['sizes'] ) && is_array( $live_meta['sizes'] ) ) {
					$folder = dirname( $current_rel );
					foreach ( $live_meta['sizes'] as $sub ) {
						if ( ! is_array( $sub ) || empty( $sub['file'] ) ) {
							continue;
						}
						$sub_rel = AMF_Utils::normalize_rel_path( ( '.' === $folder ? '' : $folder . '/' ) . wp_basename( $sub['file'] ) );
						$sub_abs = AMF_Utils::safe_abs_path( $sub_rel );
						if ( $sub_abs && is_file( $sub_abs ) && ! isset( $paths[ $sub_rel ] ) ) {
							return new WP_Error( 'amf_delete_new_thumbnail', 'Des miniatures ont été créées depuis le dernier scan. Relancez l’analyse avant de supprimer ce média.' );
						}
					}
				}
				$other_originals = (int) $this->wpdb->get_var( $this->wpdb->prepare(
					"SELECT COUNT(*) FROM {$media_table} WHERE scan_id = %d AND attachment_id <> %d AND path_hash = %s",
					$scan_id,
					$attachment_id,
					AMF_Utils::path_hash( $current_rel )
				) );
				if ( $other_originals ) {
					return new WP_Error( 'amf_delete_shared_original', 'Ce fichier original semble partagé entre plusieurs attachments. Suppression bloquée.' );
				}
			}
		}

		// Vérification systématique des chemins : pas de liens symboliques,
		// pas de sortie de wp-content/uploads (y compris sous-dossiers liens).
		foreach ( $rows as $row ) {
			$rel_path = AMF_Utils::normalize_rel_path( $row['rel_path'] );
			$abs = AMF_Utils::safe_abs_path( $rel_path );
			if ( $abs && is_file( $abs ) && (int) @filesize( $abs ) !== (int) $row['bytes'] ) {
				return new WP_Error( 'amf_delete_file_changed', 'Un fichier de cette famille a changé de taille depuis le scan. Relancez une analyse avant de le supprimer.' );
			}
			if ( is_link( $abs ) || ( file_exists( $abs ) && ! AMF_Utils::is_safe_existing_upload_file( $rel_path ) ) ) {
				return new WP_Error( 'amf_delete_unsafe_path', 'Un fichier n’est pas un fichier image ordinaire situé dans uploads : suppression annulée.' );
			}
		}

		$context = array(
			'attachment_id' => $attachment_id,
			'forced' => (bool) $requires_override,
            'live_product_references' => $live_product_count,
			'confirmed_usage' => $media_row ? absint( $media_row['usage_confirmed'] ) : 0,
			'probable_usage' => $media_row ? absint( $media_row['usage_probable'] ) : 0,
			'direct_refs' => $direct_refs,
			'ambiguous' => $ambiguous,
			'file_paths' => array_keys( $paths ),
		);

		// WordPress can remove additional backups or source companions. Ensure
		// every currently present generated file belonged to the audited family.
		if ( $attachment_id ) {
			$scope = $this->verify_wordpress_generated_paths( $attachment_id, $current_rel, $paths );
			if ( is_wp_error( $scope ) ) { return $scope; }
		}

		// La suppression WordPress nettoie les enregistrements d'attachment et
		// les tailles declarees. Les derives historiques restants seront retires.
		if ( $attachment_id && ! wp_delete_attachment( $attachment_id, true ) ) {
			return new WP_Error( 'amf_delete_wp_failed', 'WordPress n’a pas réussi à supprimer le média. Aucun fichier supplémentaire n’a été supprimé.' );
		}

		$deleted = array();
		$failed  = array();
		foreach ( array_keys( $paths ) as $rel_path ) {
			$abs = AMF_Utils::safe_abs_path( $rel_path );
			if ( ! $abs || ( file_exists( $abs ) && ! AMF_Utils::is_safe_existing_upload_file( $rel_path ) ) ) {
				$failed[] = $rel_path;
				continue;
			}
			if ( is_file( $abs ) ) {
				wp_delete_file( $abs );
			}
			if ( ! file_exists( $abs ) ) {
				$deleted[] = $rel_path;
			} else {
				$failed[] = $rel_path;
			}
		}

		if ( $attachment_id ) {
			$this->remove_attachment_scan_rows( $scan_id, $attachment_id );
		}

		// Ne plus afficher de fichiers disparus comme encore presents dans le scan.
		foreach ( $rows as $row ) {
			$rel = AMF_Utils::normalize_rel_path( $row['rel_path'] );
			if ( in_array( $rel, $deleted, true ) ) {
				$this->wpdb->delete( $files_table, array( 'id' => absint( $row['id'] ), 'scan_id' => $scan_id ), array( '%d', '%d' ) );
			}
		}
		$context['files_removed_or_missing'] = $deleted;
		$context['failures'] = $failed;
		$this->db->log_action( $failed ? 'delete_family_partial' : 'delete_family', 'family', $family_key, $context );

		if ( $failed ) {
			return new WP_Error( 'amf_delete_family_partial', sprintf(
				'%d fichier(s) supprimé(s) ou déjà absents et %d échec(s). L’attachment WordPress a éventuellement déjà été supprimé. Relancez une analyse et vérifiez les fichiers restants.',
				count( $deleted ),
				count( $failed )
			) );
		}

		return array(
			'message' => sprintf(
				'%d fichier(s) de la famille ont été supprimés (ou étaient déjà absents).%s',
				count( $deleted ),
				$attachment_id ? ' Le média WordPress #' . $attachment_id . ' a aussi été supprimé.' : ''
			),
			'family_key' => $family_key,
			'deleted' => count( $deleted ),
			'attachment_id' => $attachment_id,
		);
	}

	/**
	 * Compatibilité avec l'ancienne action (suppression des non-associés).
	 */
	public function delete_unassociated_family( $scan_id, $family_key ) {
		return $this->delete_family( $scan_id, $family_key );
	}

	/**
	 * Revérifie les seuls champs d'images WooCommerce de façon ponctuelle,
	 * sans devoir refaire un scan des 150 000 métadonnées du site.
	 * Ne modifie que la base d'index interne du plugin.
	 */
	public function refresh_woo_image_usage( $scan_id, $attachment_id ) {
		$scan_id = absint( $scan_id );
		$attachment_id = absint( $attachment_id );
		$scan = $this->db->get_scan( $scan_id );
		$media = $this->db->table( 'media' );
		if ( ! $scan || 'complete' !== $scan['status'] || ! $attachment_id ) {
			return new WP_Error( 'amf_refresh_not_ready', 'Une analyse terminée est nécessaire pour vérifier les liens.' );
		}
		$exists = (int) $this->wpdb->get_var( $this->wpdb->prepare(
			"SELECT COUNT(*) FROM {$media} WHERE scan_id = %d AND attachment_id = %d",
			$scan_id,
			$attachment_id
		) );
		if ( ! $exists ) {
			return new WP_Error( 'amf_refresh_not_found', 'Média absent des résultats du scan.' );
		}

		$pm = $this->wpdb->postmeta;
		$posts = $this->wpdb->posts;
		$needle = (string) $attachment_id;
		$rows = $this->wpdb->get_results( $this->wpdb->prepare(
			"SELECT pm.post_id, pm.meta_key, pm.meta_value, p.post_type, p.post_title, p.post_parent
			 FROM {$pm} pm INNER JOIN {$posts} p ON p.ID = pm.post_id
			 WHERE p.post_type IN ('product','product_variation')
			 AND ((pm.meta_key = '_thumbnail_id' AND pm.meta_value = %s)
			 OR (pm.meta_key = '_product_image_gallery' AND pm.meta_value LIKE %s))",
			$needle,
			'%' . $this->wpdb->esc_like( $needle ) . '%'
		), ARRAY_A );

		if ( $this->wpdb->last_error ) {
			return new WP_Error( 'amf_refresh_query_failed', 'La lecture des références WooCommerce a échoué ; aucun résultat n’a été mis à jour.' );
		}

		$usage_table = $this->db->table( 'usage' );
		// On n'efface pas les autres liens (Elementor, contenus, options, SEO, etc.).
		$this->wpdb->query( $this->wpdb->prepare(
			"DELETE FROM {$usage_table} WHERE scan_id = %d AND attachment_id = %d
			 AND source_type IN ('product','product_variation')
			 AND source_subtype IN ('_thumbnail_id','_product_image_gallery')",
			$scan_id,
			$attachment_id
		) );


		$found = 0;
		foreach ( $rows as $row ) {
			$subtype = (string) $row['meta_key'];
			$matches = '_thumbnail_id' === $subtype
				? absint( $row['meta_value'] ) === $attachment_id
				: in_array( $attachment_id, array_map( 'absint', explode( ',', (string) $row['meta_value'] ) ), true );
			if ( ! $matches ) {
				continue;
			}
			$post_id = absint( $row['post_id'] );
			$is_variation = 'product_variation' === $row['post_type'];
			$parent_id = $is_variation ? absint( $row['post_parent'] ) : $post_id;
			$name = trim( (string) $row['post_title'] );
			$this->db->add_usage( $scan_id, $attachment_id, array(
				'confidence' => 'confirmed',
				'source_type' => $row['post_type'],
				'source_id' => $post_id,
				'source_subtype' => $subtype,
				'context' => sprintf( '%s #%d — %s — %s', $is_variation ? 'Variation' : 'Produit', $post_id, $name ?: '(sans titre)', '_thumbnail_id' === $subtype ? 'Image principale actuelle' : 'Galerie actuelle' ),
				'edit_url' => get_edit_post_link( $parent_id, 'raw' ) ?: '',
				'view_url' => get_permalink( $parent_id ) ?: '',
			) );
			$found++;
		}

		$this->db->refresh_usage_counts( $scan_id );
		return array(
			'message' => sprintf( '%d référence(s) active(s) trouvée(s) dans les images principales, galeries et variations WooCommerce. Les autres références restent celles du dernier scan.', $found ),
			'count' => $found,
		);
	}

	/**
	 * Fail closed when direct attachment deletion would remove referenced or
	 * unscanned derivatives. The explicit family action has a separate, stronger
	 * confirmation path for intentionally referenced media.
	 */
	private function verify_attachment_family_for_deletion( $scan_id, $attachment_id, $media_row ) {
		$family_key = AMF_Utils::family_key_for_attachment( $attachment_id );
		$files_table = $this->db->table( 'files' );
		$rows = $this->wpdb->get_results( $this->wpdb->prepare(
			"SELECT * FROM {$files_table} WHERE scan_id = %d AND (family_key = %s OR attachment_id = %d) AND physical = 1",
			$scan_id, $family_key, $attachment_id
		), ARRAY_A );
		if ( ! $rows ) {
			return new WP_Error( 'amf_delete_family_not_scanned', 'Aucun fichier physique de cette famille n’a été vérifié. Relancez un audit avant suppression.' );
		}
		$scanned_paths = array();
		foreach ( $rows as $file ) {
			if ( absint( $file['direct_usage_count'] ) > 0 || 'unassociated_ambiguous' === $file['classification'] ||
				( absint( $file['attachment_id'] ) && absint( $file['attachment_id'] ) !== $attachment_id ) ) {
				return new WP_Error( 'amf_delete_family_referenced', 'Suppression bloquée : un dérivé de ce média est référencé par URL, ambigu ou partagé. Consultez les utilisations de la famille.' );
			}
			$rel = AMF_Utils::normalize_rel_path( $file['rel_path'] );
			$abs = AMF_Utils::safe_abs_path( $rel );
			if ( ! $abs || ! AMF_Utils::looks_like_image_rel_path( $rel ) ||
				( is_link( $abs ) || ( file_exists( $abs ) && ( ! AMF_Utils::is_safe_existing_upload_file( $rel ) || (int) @filesize( $abs ) !== (int) $file['bytes'] ) ) ) ) {
				return new WP_Error( 'amf_delete_family_changed', 'Un fichier est absent du périmètre de sécurité ou a été modifié depuis le scan. Relancez l’audit.' );
			}
			$scanned_paths[ $rel ] = true;
		}
		$current_rel = AMF_Utils::normalize_rel_path( (string) get_post_meta( $attachment_id, '_wp_attached_file', true ) );
		if ( ! $current_rel || $current_rel !== AMF_Utils::normalize_rel_path( $media_row['rel_path'] ) ) {
			return new WP_Error( 'amf_delete_attachment_changed', 'Le fichier principal a changé depuis le dernier scan. Relancez l’analyse.' );
		}
		return $this->verify_wordpress_generated_paths( $attachment_id, $current_rel, $scanned_paths );
	}

	/** Guard everything wp_delete_attachment() may delete, including backups. */
	private function verify_wordpress_generated_paths( $attachment_id, $current_rel, $scanned_paths ) {
		$meta = wp_get_attachment_metadata( $attachment_id );
		$folder = dirname( $current_rel );
		$folder = '.' === $folder ? '' : $folder . '/';
		$declared = array( $current_rel );
		if ( is_array( $meta ) ) {
			if ( ! empty( $meta['file'] ) && AMF_Utils::normalize_rel_path( $meta['file'] ) !== $current_rel ) {
				return new WP_Error( 'amf_delete_metadata_changed', 'Les métadonnées du fichier principal sont incohérentes. Relancez l’audit.' );
			}
			foreach ( array( 'thumb', 'original_image', 'source_image', 'animated_video', 'animated_video_poster' ) as $key ) {
				if ( ! empty( $meta[ $key ] ) && is_string( $meta[ $key ] ) ) {
					$declared[] = $folder . wp_basename( $meta[ $key ] );
				}
			}
			if ( ! empty( $meta['sizes'] ) && is_array( $meta['sizes'] ) ) {
				foreach ( $meta['sizes'] as $size ) {
					if ( is_array( $size ) && ! empty( $size['file'] ) ) {
						$declared[] = $folder . wp_basename( $size['file'] );
					}
				}
			}
		}
		$backup_sizes = get_post_meta( $attachment_id, '_wp_attachment_backup_sizes', true );
		if ( is_array( $backup_sizes ) ) {
			foreach ( $backup_sizes as $size ) {
				if ( is_array( $size ) && ! empty( $size['file'] ) ) {
					$declared[] = $folder . wp_basename( $size['file'] );
				}
			}
		}
		foreach ( $declared as $path ) {
			$rel = AMF_Utils::normalize_rel_path( $path );
			$abs = AMF_Utils::safe_abs_path( $rel );
			if ( ! $abs || is_link( $abs ) || ( file_exists( $abs ) && ! AMF_Utils::is_safe_existing_upload_file( $rel ) ) ) {
				return new WP_Error( 'amf_delete_unsafe_metadata_path', 'Les métadonnées du média contiennent un chemin non autorisé.' );
			}
			if ( is_file( $abs ) && ! isset( $scanned_paths[ $rel ] ) ) {
				return new WP_Error( 'amf_delete_new_derivative', 'Un original ou dérivé a été ajouté depuis le dernier scan. Relancez l’audit avant de supprimer.' );
			}
		}
		return true;
	}

	private function remove_attachment_scan_rows( $scan_id, $attachment_id ) {
		$files_table = $this->db->table( 'files' );
		$files = $this->wpdb->get_results(
			$this->wpdb->prepare( "SELECT id, rel_path, family_stem FROM {$files_table} WHERE scan_id = %d AND attachment_id = %d", $scan_id, $attachment_id ),
			ARRAY_A
		);
		foreach ( $files as $file ) {
			$rel_path = AMF_Utils::normalize_rel_path( $file['rel_path'] );
			if ( AMF_Utils::is_safe_existing_upload_file( $rel_path ) ) {
				$rel_dir = dirname( $rel_path );
				if ( '.' === $rel_dir ) {
					$rel_dir = '';
				}
				$family_stem = $file['family_stem'] ?: AMF_Utils::family_stem_from_rel_path( $rel_path );
				$this->wpdb->update(
					$files_table,
					array(
						'attachment_id'          => null,
						'family_key'             => AMF_Utils::family_key_for_unassociated( $rel_dir, $family_stem ),
						'family_stem'            => $family_stem,
						'classification'         => 'unassociated',
						'association_confidence' => 0,
						'current_meta'           => 0,
						'note'                   => 'Fichier encore présent après suppression de l’attachment ; aucun attachment correspondant identifié.',
					),
					array( 'id' => (int) $file['id'] ),
					array( '%d', '%s', '%s', '%s', '%d', '%d', '%s' ),
					array( '%d' )
				);
			} else {
				$this->wpdb->delete( $files_table, array( 'id' => (int) $file['id'] ), array( '%d' ) );
			}
		}

		$this->wpdb->delete( $this->db->table( 'media' ), array( 'scan_id' => $scan_id, 'attachment_id' => $attachment_id ), array( '%d', '%d' ) );
		$this->wpdb->delete( $this->db->table( 'usage' ), array( 'scan_id' => $scan_id, 'attachment_id' => $attachment_id ), array( '%d', '%d' ) );
		$this->wpdb->delete( $this->db->table( 'issues' ), array( 'scan_id' => $scan_id, 'attachment_id' => $attachment_id ), array( '%d', '%d' ) );
	}
}
