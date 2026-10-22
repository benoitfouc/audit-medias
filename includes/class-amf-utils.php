<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AMF_Utils {
	public static function uploads() {
		$uploads = wp_get_upload_dir();
		return array(
			'basedir' => wp_normalize_path( untrailingslashit( $uploads['basedir'] ) ),
			'baseurl' => untrailingslashit( $uploads['baseurl'] ),
		);
	}

	public static function normalize_rel_path( $path ) {
		$path = html_entity_decode( (string) $path, ENT_QUOTES, 'UTF-8' );
		$path = rawurldecode( $path );
		$path = str_replace( '\\', '/', $path );
		$path = preg_replace( '#/+#', '/', $path );
		$path = ltrim( $path, '/' );
		return $path;
	}

	public static function path_hash( $rel_path ) {
		return md5( self::normalize_rel_path( $rel_path ) );
	}

	public static function dir_hash( $rel_dir ) {
		return md5( trim( self::normalize_rel_path( $rel_dir ), '/' ) );
	}

	public static function safe_abs_path( $rel_path ) {
		$uploads = self::uploads();
		$rel_path = self::normalize_rel_path( $rel_path );
		if ( '' === $rel_path || false !== strpos( $rel_path, "\0" ) || preg_match( '#(^|/)\.\.(/|$)#', $rel_path ) ) {
			return false;
		}
		return $uploads['basedir'] . '/' . $rel_path;
	}

	public static function is_image_extension( $extension ) {
		return in_array(
			strtolower( (string) $extension ),
			array( 'jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'heic', 'heif', 'bmp', 'tif', 'tiff' ),
			true
		);
	}

	public static function canonical_file_parts( $basename ) {
		$basename = wp_basename( (string) $basename );
		$work = $basename;
		$outer_ext = strtolower( pathinfo( $work, PATHINFO_EXTENSION ) );
		$is_conversion = in_array( $outer_ext, array( 'webp', 'avif' ), true );
		$inner_ext = '';

		if ( '' !== $outer_ext ) {
			$work = substr( $work, 0, -1 * ( strlen( $outer_ext ) + 1 ) );
		}

		if ( $is_conversion ) {
			$candidate_inner = strtolower( pathinfo( $work, PATHINFO_EXTENSION ) );
			if ( self::is_image_extension( $candidate_inner ) ) {
				$inner_ext = $candidate_inner;
				$work = substr( $work, 0, -1 * ( strlen( $candidate_inner ) + 1 ) );
			}
		}

		$had_dimensions = false;
		$had_scaled = false;
		$had_rotated = false;

		if ( preg_match( '/-([0-9]{2,5})x([0-9]{2,5})$/', $work ) ) {
			$work = preg_replace( '/-([0-9]{2,5})x([0-9]{2,5})$/', '', $work );
			$had_dimensions = true;
		}

		if ( preg_match( '/-scaled$/i', $work ) ) {
			$work = preg_replace( '/-scaled$/i', '', $work );
			$had_scaled = true;
		}

		if ( preg_match( '/-rotated$/i', $work ) ) {
			$work = preg_replace( '/-rotated$/i', '', $work );
			$had_rotated = true;
		}

		return array(
			'family_stem'   => $work,
			'outer_ext'     => $outer_ext,
			'inner_ext'     => $inner_ext,
			'is_conversion' => $is_conversion,
			'had_dimensions'=> $had_dimensions,
			'had_scaled'    => $had_scaled,
			'had_rotated'   => $had_rotated,
		);
	}

	public static function family_stem_from_rel_path( $rel_path ) {
		$parts = self::canonical_file_parts( wp_basename( $rel_path ) );
		return $parts['family_stem'];
	}

	public static function extract_upload_rel_paths_from_text( $text ) {
        return array_column( AMF_Reference_Parser::upload_references( $text ), 'rel_path' );
    }

	public static function extract_strong_attachment_ids_from_text( $text ) {
		$text = str_replace( '\\"', '"', (string) $text );
		$ids = array();
		$patterns = array(
			'/\bwp-image-([0-9]+)\b/i',
			'/\battachment[_-]id["\'=:\s]+([0-9]+)\b/i',
			'/data-attachment-id=["\']([0-9]+)["\']/i',
			'/\battachment_([0-9]+)\b/i',
		);
		foreach ( $patterns as $pattern ) {
			if ( preg_match_all( $pattern, $text, $matches ) ) {
				foreach ( $matches[1] as $id ) {
					$ids[ (int) $id ] = true;
				}
			}
		}

		if ( preg_match_all( '/\[gallery[^\]]*\bids=["\']([^"\']+)["\'][^\]]*\]/i', $text, $gallery_matches ) ) {
			foreach ( $gallery_matches[1] as $list ) {
				foreach ( preg_split( '/\s*,\s*/', $list ) as $id ) {
					if ( ctype_digit( $id ) ) {
						$ids[ (int) $id ] = true;
					}
				}
			}
		}
		return array_keys( $ids );
	}

	public static function image_like_key( $key_path ) {
		$key_path = strtolower( (string) $key_path );
		return (bool) preg_match( '/(^|[_\.\-\[\/])(image|images|img|thumbnail|thumb|gallery|galleries|logo|icon|photo|photos|media|attachment|avatar|banner|background)([_\.\-\]\/]|$)/i', $key_path );
	}


	public static function likely_attachment_id_key( $key_path ) {
		$key_path = strtolower( (string) $key_path );
		$parts = preg_split( '/[\.\[\]\/]+/', $key_path, -1, PREG_SPLIT_NO_EMPTY );
		if ( empty( $parts ) ) {
			return false;
		}
		$leaf = (string) end( $parts );
		$parent_parts = $parts;
		array_pop( $parent_parts );
		$parent = implode( '.', $parent_parts );

		if ( ctype_digit( $leaf ) ) {
			return (bool) preg_match( '/(^|[_.\-])(gallery|galleries|images|image_ids|gallery_ids|attachments|attachment_ids|media_ids|photos)([_.\-]|$)/i', $parent );
		}

		if ( in_array( $leaf, array( 'attachment_id', 'image_id', 'media_id', 'thumbnail_id', 'thumb_id', 'logo_id', 'icon_id', 'photo_id', 'banner_id', 'background_id' ), true ) ) {
			return true;
		}

		if ( 'id' === $leaf ) {
			return self::image_like_key( $parent );
		}

		if ( preg_match( '/(size|width|height|quality|crop|ratio|position|offset|alt|caption|title|url|src|mime|count|limit|max|min|columns|rows)$/i', $leaf ) ) {
			return false;
		}

		return self::image_like_key( $leaf );
	}

	public static function maybe_decode_structured( $value ) {
		if ( ! is_string( $value ) || '' === $value ) {
			return null;
		}

		if ( is_serialized( $value ) ) {
			$decoded = @unserialize( trim( $value ), array( 'allowed_classes' => false ) ); // Read-only: never execute serialized object hooks.
			if ( $decoded !== $value ) {
				return $decoded;
			}
		}

		$trimmed = ltrim( $value );
		if ( '' !== $trimmed && ( '{' === $trimmed[0] || '[' === $trimmed[0] ) ) {
			$decoded = json_decode( $value, true );
			if ( JSON_ERROR_NONE === json_last_error() ) {
				return $decoded;
			}
			$decoded = json_decode( wp_unslash( $value ), true );
			if ( JSON_ERROR_NONE === json_last_error() ) {
				return $decoded;
			}
		}

		return null;
	}


	public static function family_key_for_attachment( $attachment_id ) {
		return md5( 'attachment:' . absint( $attachment_id ) );
	}

	public static function family_key_for_unassociated( $rel_dir, $family_stem ) {
		return md5( 'family:' . trim( self::normalize_rel_path( $rel_dir ), '/' ) . '|' . strtolower( (string) $family_stem ) );
	}

	public static function file_url( $rel_path ) {
		$rel_path = self::normalize_rel_path( $rel_path );
		if ( '' === $rel_path ) {
			return '';
		}
		$uploads = self::uploads();
		$segments = array_filter( explode( '/', $rel_path ), 'strlen' );
		$encoded = implode( '/', array_map( 'rawurlencode', $segments ) );
		return trailingslashit( $uploads['baseurl'] ) . $encoded;
	}

	public static function is_safe_existing_upload_file( $rel_path ) {
		$abs = self::safe_abs_path( $rel_path );
		if ( ! $abs || ! is_file( $abs ) || is_link( $abs ) ) {
			return false;
		}
		$real = realpath( $abs );
		$base = realpath( self::uploads()['basedir'] );
		if ( false === $real || false === $base ) {
			return false;
		}
		$real = wp_normalize_path( $real );
		$base = trailingslashit( wp_normalize_path( $base ) );
		return 0 === strpos( $real, $base );
	}

	public static function looks_like_image_rel_path( $value ) {
		$value = self::normalize_rel_path( $value );
		if ( '' === $value ) {
			return false;
		}
		$ext = strtolower( pathinfo( preg_split( '/[?#]/', $value, 2 )[0], PATHINFO_EXTENSION ) );
		return self::is_image_extension( $ext );
	}

	public static function human_bytes( $bytes ) {
		$bytes = (float) $bytes;
		if ( $bytes < 1024 ) {
			return number_format_i18n( $bytes, 0 ) . ' o';
		}
		$units = array( 'Ko', 'Mo', 'Go', 'To' );
		$value = $bytes;
		foreach ( $units as $unit ) {
			$value /= 1024;
			if ( $value < 1024 || 'To' === $unit ) {
				return number_format_i18n( $value, $value < 10 ? 2 : 1 ) . ' ' . $unit;
			}
		}
		return number_format_i18n( $value, 1 ) . ' To';
	}

	public static function status_label( $phase ) {
		$labels = array(
			'media'          => 'Médias WordPress',
			'usage_posts'    => 'Contenus / Gutenberg',
			'usage_postmeta' => 'Métadonnées / constructeurs / extensions',
			'usage_termmeta' => 'Taxonomies / catégories / marques',
			'usage_options'  => 'Options WordPress',
			'files'          => 'Fichiers physiques',
			'woo'            => 'Audit WooCommerce',
			'finalize'       => 'Finalisation',
			'complete'       => 'Terminé',
		);
		return isset( $labels[ $phase ] ) ? $labels[ $phase ] : $phase;
	}
}
