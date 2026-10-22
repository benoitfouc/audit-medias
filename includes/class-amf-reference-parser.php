<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Read-only evidence extraction. No shortcode execution, HTTP requests or image writes.
 * A reference is evidence of stored usage, not a guarantee of visibility on the page.
 */
class AMF_Reference_Parser {
    const VERSION = '2.3.2';

    public static function scan_is_current( $scan ) {
        if ( ! is_array( $scan ) ) { return false; }
        $state = isset( $scan['state'] ) ? json_decode( (string) $scan['state'], true ) : array();
        return is_array( $state ) && isset( $state['reference_parser'] ) && self::VERSION === $state['reference_parser'];
    }

    /** Decode common HTML / JSON escaping without stripping CSS or filename backslashes wholesale. */
    public static function decode_text( $text ) {
        $text = (string) $text;
        for ( $i = 0; $i < 2; $i++ ) {
            $before = $text;
            $text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
            $text = str_ireplace(
                array( '\u002f', '\u003a', '\u003f', '\u0026', '\u003d', '\u0022', '\u0027' ),
                array( '/', ':', '?', '&', '=', '"', "'" ), $text
            );
            $text = str_replace( array( '\/', '\"', "\\'" ), array( '/', '"', "'" ), $text );
            if ( $text === $before ) { break; }
        }
        return $text;
    }

    /** Exact media attributes only: never interpret arbitrary shortcode id/width/count as media. */
    public static function shortcode_attribute_role( $tag, $attribute ) {
        $tag = strtolower( (string) $tag );
        $attribute = strtolower( (string) $attribute );
        if ( preg_match( '/^(?:bg_image|background_image|background)(?:_(?:desktop|tablet|mobile|phone))?(?:_id|_url)?$/D', $attribute ) ) {
            return 'Arrière-plan';
        }
        if ( preg_match( '/^(?:video_image|video_poster|poster_image|poster)(?:_(?:desktop|tablet|mobile|phone))?(?:_id)?$/D', $attribute ) ) {
            return 'Image de couverture vidéo';
        }
        if ( in_array( $attribute, array( 'images', 'image_ids', 'gallery_ids', 'gallery_images', 'attachment_ids', 'media_ids' ), true ) ||
            ( 'gallery' === $tag && in_array( $attribute, array( 'ids', 'include' ), true ) ) ) {
            return 'Galerie';
        }
        if ( in_array( $attribute, array( 'image', 'image_id', 'image_url', 'img', 'img_id', 'attachment_id',
            'media_id', 'thumbnail', 'thumbnail_id', 'thumb_id', 'logo', 'logo_id', 'photo', 'photo_id' ), true ) ) {
            return 'Image';
        }
        return '';
    }

    /** Inspect opening tags independently, so nested vc_row/vc_column shortcodes are not lost. */
    public static function shortcode_references( $text ) {
        $text = self::decode_text( $text );
        if ( false === strpos( $text, '[' ) ) { return array(); }
        // Removing literal escaped shortcodes prevents [[vc_row bg_image="123"]] false positives.
        $text = preg_replace( '/\[\[[^\]]*\]\]/s', '', $text );
        $pattern = '~(?<!\[)\[([a-zA-Z][a-zA-Z0-9_-]*)\b((?:[^\]"\']|"[^"]*"|\'[^\']*\')*)\]~s';
        $matched = preg_match_all( $pattern, $text, $matches, PREG_SET_ORDER );
        if ( false === $matched ) { throw new RuntimeException( 'Analyse des shortcodes interrompue (PCRE).' ); }
        $out = array();
        foreach ( $matches as $match ) {
            $tag = strtolower( $match[1] );
            // Core API: parses attribute syntax only; no rendering / do_shortcode().
            $attribute_text = preg_replace( '~/\s*$~', '', $match[2] );
            $attributes = shortcode_parse_atts( $attribute_text );
            if ( ! is_array( $attributes ) ) { continue; }
            foreach ( $attributes as $attribute => $value ) {
                if ( ! is_string( $attribute ) || ! is_scalar( $value ) ) { continue; }
                $role = self::shortcode_attribute_role( $tag, $attribute );
                if ( '' === $role ) { continue; }
                $value = trim( (string) $value );
                // URLs are handled by upload_references(); don't extract resize arguments as IDs.
                if ( ! preg_match( '/^[0-9]+(?:[\s,|]+[0-9]+)*$/D', $value ) ) { continue; }
                foreach ( preg_split( '/[\s,|]+/', $value, -1, PREG_SPLIT_NO_EMPTY ) as $id ) {
                    $id = absint( $id );
                    if ( ! $id ) { continue; }
                    $key = $tag . ':' . $attribute . ':' . $id;
                    $out[ $key ] = array( 'attachment_id' => $id, 'tag' => $tag, 'attribute' => $attribute, 'role' => $role );
                }
            }
        }
        return array_values( $out );
    }

    private static function base_configuration() {
        $uploads = AMF_Utils::uploads();
        $bases = array( $uploads['baseurl'] );
        // Optional extension point for a CDN replacing both the host and the uploads prefix.
        $aliases = apply_filters( 'amf_upload_url_bases', array(), $uploads );
        if ( is_array( $aliases ) ) { $bases = array_merge( $bases, $aliases ); }
        $site_hosts = array();
        foreach ( array( home_url( '/' ), site_url( '/' ), $uploads['baseurl'] ) as $url ) {
            $host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
            if ( $host ) { $site_hosts[ $host ] = true; }
        }
        $out = array();
        foreach ( $bases as $base ) {
            if ( ! is_string( $base ) ) { continue; }
            $parts = wp_parse_url( $base );
            if ( ! is_array( $parts ) || empty( $parts['host'] ) || ! empty( $parts['user'] ) || ! empty( $parts['pass'] ) ) { continue; }
            $host = strtolower( $parts['host'] );
            $path = '/' . trim( isset( $parts['path'] ) ? $parts['path'] : '', '/' ) . '/';
            $path = '/' === $path ? '/' : preg_replace( '#/+#', '/', $path );
            $out[] = array( 'host' => $host, 'path' => $path );
            $site_hosts[ $host ] = true;
        }
        $upload_path = isset( $out[0] ) ? $out[0]['path'] : '/wp-content/uploads/';
        foreach ( array_keys( $site_hosts ) as $host ) { $out[] = array( 'host' => $host, 'path' => $upload_path ); }
        return array( 'bases' => $out, 'hosts' => $site_hosts, 'upload_path' => $upload_path );
    }

    private static function valid_relative( $relative ) {
        // Query strings are removed BEFORE decoding, so a percent-encoded filename stays a filename.
        $relative = preg_split( '/[?#]/', $relative, 2 )[0];
        $relative = AMF_Utils::normalize_rel_path( $relative );
        if ( '' === $relative || preg_match( '#(^|/)\.\.(/|$)|[\x00-\x1f\x7f]#', $relative ) ) { return ''; }
        $ext = strtolower( pathinfo( $relative, PATHINFO_EXTENSION ) );
        if ( ! AMF_Utils::is_image_extension( $ext ) && ! in_array( $ext, array( 'svg', 'pdf', 'mp4', 'webm', 'mp3', 'ogg' ), true ) ) { return ''; }
        return $relative;
    }

    private static function reference( $relative, $confidence, $kind ) {
        $relative = self::valid_relative( $relative );
        return $relative ? array( 'rel_path' => $relative, 'confidence' => $confidence, 'kind' => $kind ) : null;
    }

    /** No fetching: resolve local paths or recognizable CDN wrappers. Unknown hosts remain probable. */
    private static function reference_from_url( $url, $config, $depth = 0 ) {
        if ( $depth > 3 ) { return null; }
        $url = trim( self::decode_text( $url ), " \t\n\r\0\x0B,;" );
        if ( preg_match( '#^https?%3a#i', $url ) || preg_match( '#^%2f#i', $url ) ) { $url = rawurldecode( $url ); }
        if ( 0 === strpos( $url, '//' ) ) { $url = 'https:' . $url; }
        $p = wp_parse_url( $url );
        if ( ! is_array( $p ) || ! empty( $p['user'] ) || ! empty( $p['pass'] ) ) { return null; }
        if ( ! empty( $p['scheme'] ) && ! in_array( strtolower( $p['scheme'] ), array( 'http', 'https' ), true ) ) { return null; }
        $host = isset( $p['host'] ) ? strtolower( $p['host'] ) : '';
        $path = isset( $p['path'] ) ? $p['path'] : '';
        $local = '' === $host || isset( $config['hosts'][ $host ] );

        // Photon: origin host is part of the path, not the CDN host. Never match another site's origin.
        if ( preg_match( '/^i[0-3]\.wp\.com$/D', $host ) ) {
            $origin = ltrim( $path, '/' );
            $origin = rawurldecode( $origin );
            if ( ! preg_match( '#^https?://#i', $origin ) ) { $origin = 'https://' . $origin; }
            $origin_host = strtolower( (string) wp_parse_url( $origin, PHP_URL_HOST ) );
            if ( ! isset( $config['hosts'][ $origin_host ] ) ) { return null; }
            $r = self::reference_from_url( $origin, $config, $depth + 1 );
            if ( $r ) { $r['kind'] = 'CDN Jetpack/Photon'; }
            return $r;
        }

        // Common proxy URLs (including a percent-encoded original URL in url/src/image).
        if ( ! empty( $p['query'] ) ) {
            $query = array();
            parse_str( $p['query'], $query );
            foreach ( array( 'url', 'src', 'image', 'image_url', 'img', 'u' ) as $key ) {
                if ( empty( $query[ $key ] ) || ! is_string( $query[ $key ] ) ) { continue; }
                $inner = $query[ $key ];
                for ( $i = 0; $i < 2 && preg_match( '#^(?:https?%3a|%2f)#i', $inner ); $i++ ) { $inner = rawurldecode( $inner ); }
                if ( in_array( $host, array( 'images.weserv.nl', 'wsrv.nl' ), true ) && ! preg_match( '#^(?:https?:)?//#i', $inner ) ) {
                    $inner = 'https://' . ltrim( $inner, '/' );
                }
                if ( preg_match( '#^(?:https?:)?//#i', $inner ) ) {
                    $ih = strtolower( (string) wp_parse_url( 0 === strpos( $inner, '//' ) ? 'https:' . $inner : $inner, PHP_URL_HOST ) );
                    if ( ! isset( $config['hosts'][ $ih ] ) && ! preg_match( '/^i[0-3]\.wp\.com$/D', $ih ) ) { continue; }
                } elseif ( 0 !== strpos( $inner, '/' ) ) { continue; }
                elseif ( ! $local ) { $inner = 'https://' . $host . $inner; }
                $r = self::reference_from_url( $inner, $config, $depth + 1 );
                if ( $r ) { $r['kind'] = 'URL source dans un proxy/CDN'; return $r; }
            }
        }

        // Cloudflare image resizing keeps the source after its transformation options.
        if ( preg_match( '#^/cdn-cgi/image/[^/]+/(.+)$#', $path, $m ) ) {
            $inner = $m[1];
            if ( preg_match( '#^https?://#i', $inner ) ) {
                $ih = strtolower( (string) wp_parse_url( $inner, PHP_URL_HOST ) );
                if ( ! isset( $config['hosts'][ $ih ] ) ) { return null; }
            } else { $inner = ( $host ? 'https://' . $host : '' ) . '/' . ltrim( $inner, '/' ); }
            $r = self::reference_from_url( $inner, $config, $depth + 1 );
            if ( $r ) { $r['kind'] = 'CDN avec transformation de chemin'; }
            return $r;
        }

        foreach ( $config['bases'] as $base ) {
            if ( $host && $host !== $base['host'] ) { continue; }
            $prefix = $base['path'];
            $candidate = '/' . ltrim( $path, '/' );
            if ( 0 === strpos( $candidate, $prefix ) ) {
                $r = self::reference( substr( $candidate, strlen( $prefix ) ), 'confirmed', 'URL / chemin uploads' );
                if ( $r ) { return $r; }
            }
        }

        // Another CDN can preserve the complete uploads path but use an unknown host.
        // Protect the family conservatively; do not promote a host-only guess to confirmed evidence.
        $prefix = $config['upload_path'];
        $pos = strpos( '/' . ltrim( $path, '/' ), $prefix );
        if ( false !== $pos && $host && ! $local && '/' !== $prefix ) {
            $candidate = '/' . ltrim( $path, '/' );
            return self::reference( substr( $candidate, $pos + strlen( $prefix ) ), 'probable', 'URL externe/CDN : chemin correspondant, origine à confirmer' );
        }
        return null;
    }

    public static function upload_references( $text ) {
        if ( '' === (string) $text ) { return array(); }
        if ( false === strpos( (string) $text, '/' ) && false === strpos( (string) $text, '%' ) && false === stripos( (string) $text, '\u002f' ) ) { return array(); }
        $text = self::decode_text( $text );
        $variants = array( $text );
        // Encoded CSS/URL values occur in builder data. Keep the raw version as well for encoded filenames.
        $decoded = $text;
        for ( $i = 0; $i < 2 && preg_match( '/%(?:25|2f|3a|3d|22|27|28|29)/i', $decoded ); $i++ ) {
            $next = rawurldecode( $decoded );
            if ( $next === $decoded ) { break; }
            $variants[] = $next; $decoded = $next;
        }
        $config = self::base_configuration(); $out = array();
        foreach ( array_unique( $variants ) as $variant ) {
            $collect = function( $candidate ) use ( &$out, $config ) {
                $r = self::reference_from_url( $candidate, $config );
                if ( $r && ( ! isset( $out[ $r['rel_path'] ] ) || 'confirmed' === $r['confidence'] ) ) { $out[ $r['rel_path'] ] = $r; }
            };
            // Consume absolute URLs first; do not then mistake a foreign Photon path for a local relative path.
            $remaining = preg_replace_callback( '~(?:https?:)?//[^\s<>"\'()\[\]{}\\\\]+~i', function( $m ) use ( $collect ) {
                $collect( $m[0] ); return ' ';
            }, $variant );
            if ( null === $remaining ) { throw new RuntimeException( 'Analyse des URL interrompue (PCRE).' ); }
            $prefixes = array_unique( array_map( function( $b ) { return trim( $b['path'], '/' ); }, $config['bases'] ) );
            foreach ( $prefixes as $prefix ) {
                if ( '' === $prefix ) { continue; }
                $pattern = '~(?<![a-zA-Z0-9_/%.-])/?' . preg_quote( $prefix, '~' ) . '/[^\s<>"\'()\[\]{}\\\\]+~i';
                $ok = preg_match_all( $pattern, $remaining, $matches );
                if ( false === $ok ) { throw new RuntimeException( 'Analyse des chemins interrompue (PCRE).' ); }
                foreach ( $matches[0] as $candidate ) { $collect( '/' . ltrim( $candidate, '/' ) ); }
            }
        }
        return array_values( $out );
    }
}
