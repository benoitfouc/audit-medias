<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * A small, independent index of native WooCommerce image assignments.
 * Only this plugin's table/options are written. No product or media is saved.
 * A staging generation is published only after every row has been read.
 */
class AMF_Product_Usage {
    const STATE_OPTION = 'amf_product_image_index';
    const REVISION_OPTION = 'amf_product_image_revision';
    const LOCK_OPTION = 'amf_product_image_lock';
    private $db;
    private $wpdb;
    private $link_cache = array();
    private $status_cache = null;

    public function __construct( AMF_Database $db ) {
        $this->db = $db;
        global $wpdb;
        $this->wpdb = $wpdb;
    }

    public function register_hooks() {
        foreach ( array( 'added_post_meta', 'updated_post_meta', 'deleted_post_meta' ) as $hook ) {
            add_action( $hook, array( $this, 'on_meta_changed' ), 20, 4 );
        }
        add_action( 'save_post_product', array( $this, 'invalidate' ), 100, 0 );
        add_action( 'save_post_product_variation', array( $this, 'invalidate' ), 100, 0 );
        add_action( 'transition_post_status', array( $this, 'on_status_changed' ), 20, 3 );
        add_action( 'deleted_post', array( $this, 'on_post_deleted' ), 20, 2 );
    }

    public function available() {
        return function_exists( 'wc_get_product' ) && post_type_exists( 'product' );
    }

    public function invalidate() {
        $this->status_cache = null;
        update_option( self::REVISION_OPTION, wp_generate_uuid4(), false );
    }

    public function on_meta_changed( $meta_id, $object_id, $key, $value = null ) {
        if ( in_array( $key, array( '_thumbnail_id', '_product_image_gallery' ), true ) &&
             in_array( get_post_type( $object_id ), array( 'product', 'product_variation' ), true ) ) {
            $this->invalidate();
        }
    }

    public function on_status_changed( $new, $old, $post ) {
        if ( $new !== $old && $post && in_array( $post->post_type, array( 'product', 'product_variation' ), true ) ) {
            $this->invalidate();
        }
    }

    public function on_post_deleted( $id, $post ) {
        if ( $post && in_array( $post->post_type, array( 'product', 'product_variation', 'attachment' ), true ) ) {
            $this->invalidate();
        }
    }

    private function revision() {
        // Read directly to notice edits in another request, even during a batch.
        $value = $this->wpdb->get_var( $this->wpdb->prepare(
            "SELECT option_value FROM {$this->wpdb->options} WHERE option_name = %s", self::REVISION_OPTION
        ) );
        return null === $value ? 'initial' : (string) $value;
    }

    public function status() {
        if ( null !== $this->status_cache ) { return $this->status_cache; }
        $s = get_option( self::STATE_OPTION, array() );
        if ( ! is_array( $s ) ) { $s = array(); }
        $s = wp_parse_args( $s, array(
            'status' => 'idle', 'active' => '', 'active_revision' => '', 'working' => '',
            'revision' => '', 'cursor' => 0, 'current' => 0, 'total' => 0, 'upper' => 0,
            'checked_at' => '', 'message' => '',
        ) );
        $s['available'] = $this->available();
        $s['ready'] = $s['available'] && 'complete' === $s['status'] && ! $s['working'] &&
            $s['active'] && hash_equals( (string) $s['active_revision'], $this->revision() );
        if ( 'complete' === $s['status'] && ! $s['ready'] && $s['available'] ) {
            $s['status'] = 'stale';
            $s['message'] = 'Une fiche produit a changé depuis cette vérification.';
        }
        $this->status_cache = $s;
        return $s;
    }

    private function save( $state ) {
        $this->status_cache = null;
        unset( $state['available'], $state['ready'] );
        update_option( self::STATE_OPTION, $state, false );
    }

    /** Serialise refreshes across browser tabs; expired locks use compare-and-swap. */
    private function lock() {
        $token = wp_json_encode( array( 'token' => wp_generate_uuid4(), 'until' => time() + 90 ) );
        if ( add_option( self::LOCK_OPTION, $token, '', false ) ) { return $token; }
        $old = $this->wpdb->get_var( $this->wpdb->prepare(
            "SELECT option_value FROM {$this->wpdb->options} WHERE option_name = %s", self::LOCK_OPTION
        ) );
        $decoded = json_decode( (string) $old, true );
        if ( is_array( $decoded ) && isset($decoded['until']) && (int) $decoded['until'] < time() ) {
            $changed = $this->wpdb->query( $this->wpdb->prepare(
                "UPDATE {$this->wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s",
                $token, self::LOCK_OPTION, $old
            ) );
            if ( $changed ) { wp_cache_delete( self::LOCK_OPTION, 'options' ); return $token; }
        }
        return false;
    }

    private function unlock( $token ) {
        $this->wpdb->query( $this->wpdb->prepare(
            "DELETE FROM {$this->wpdb->options} WHERE option_name = %s AND option_value = %s", self::LOCK_OPTION, $token
        ) );
        wp_cache_delete( self::LOCK_OPTION, 'options' );
    }

    /** Native stored values, not attachment post_parent, not inherited variation images. */
    public function source_sql() {
        return "FROM {$this->wpdb->postmeta} pm
            INNER JOIN {$this->wpdb->posts} p ON p.ID = pm.post_id
            LEFT JOIN {$this->wpdb->posts} parent ON parent.ID = p.post_parent
            WHERE p.post_type IN ('product','product_variation')
            AND p.post_status NOT IN ('trash','auto-draft')
            AND (p.post_type <> 'product_variation' OR parent.ID IS NULL OR parent.post_status NOT IN ('trash','auto-draft'))
            AND pm.meta_key IN ('_thumbnail_id','_product_image_gallery')
            AND NOT EXISTS (SELECT 1 FROM {$this->wpdb->postmeta} earlier
                WHERE earlier.post_id = pm.post_id AND earlier.meta_key = pm.meta_key AND earlier.meta_id < pm.meta_id)";
    }

    /** Empty values are valid. Malformed native fields must not imply absence. */
    public static function image_ids( $value, $key ) {
        $value = trim( (string) $value );
        if ( '' === $value || '0' === $value ) { return array(); }
        if ( '_thumbnail_id' === $key ) {
            if ( ! ctype_digit( $value ) ) { throw new RuntimeException( 'Identifiant de miniature non standard.' ); }
            return array( (int) $value );
        }
        if ( ! preg_match( '/^\d+(?:\s*,\s*\d+)*$/D', $value ) ) {
            throw new RuntimeException( 'Liste de galerie WooCommerce non standard.' );
        }
        return array_values( array_unique( array_filter( array_map( 'absint', explode( ',', $value ) ) ) ) );
    }

    public function step( $restart = false ) {
        if ( ! $this->available() ) { return new WP_Error( 'amf_wc_unavailable', 'WooCommerce est inactif.' ); }
        $token = $this->lock();
        if ( ! $token ) { return new WP_Error( 'amf_wc_busy', 'Une autre vérification est en cours. Nouvelle tentative dans quelques secondes.' ); }
        $s = $this->status();
        try {
            if ( $restart || ! $s['working'] || ! hash_equals( (string) $s['revision'], $this->revision() ) ) {
                $revision = $this->revision();
                $upper = $this->wpdb->get_var( "SELECT COALESCE(MAX(meta_id),0) FROM {$this->wpdb->postmeta}" );
                if ( $this->wpdb->last_error ) { throw new RuntimeException( 'Lecture des métadonnées impossible.' ); }
                $total = $this->wpdb->get_var( $this->wpdb->prepare(
                    'SELECT COUNT(*) ' . $this->source_sql() . ' AND pm.meta_id <= %d', (int) $upper
                ) );
                if ( $this->wpdb->last_error ) { throw new RuntimeException( 'Comptage des images produits impossible.' ); }
                $s = array_merge( $s, array(
                    'working' => md5( wp_generate_uuid4() ), 'revision' => $revision, 'status' => 'running',
                    'cursor' => 0, 'current' => 0, 'total' => (int) $total, 'upper' => (int) $upper, 'message' => '',
                ) );
                $this->save( $s );
            }
            $s['status'] = 'running';
            $rows = $this->wpdb->get_results( $this->wpdb->prepare(
                "SELECT pm.meta_id, pm.post_id, pm.meta_key, pm.meta_value, p.post_type, p.post_status, p.post_parent,
                        COALESCE(parent.post_status,'') AS parent_status " . $this->source_sql() .
                ' AND pm.meta_id > %d AND pm.meta_id <= %d ORDER BY pm.meta_id ASC LIMIT %d',
                (int) $s['cursor'], (int) $s['upper'], 250
            ), ARRAY_A );
            if ( $this->wpdb->last_error ) { throw new RuntimeException( 'Lecture des affectations WooCommerce impossible. Aucun résultat négatif valide.' ); }
            $start = microtime( true );
            $processed = 0;
            foreach ( $rows as $r ) {
                try { $ids = self::image_ids( $r['meta_value'], $r['meta_key'] ); }
                catch ( Throwable $e ) { throw new RuntimeException( 'Produit #' . $r['post_id'] . ' : ' . $e->getMessage() ); }
                $role = '_product_image_gallery' === $r['meta_key'] ? 'gallery' : ( 'product_variation' === $r['post_type'] ? 'variation' : 'main' );
                foreach ( $ids as $position => $id ) {
                    $ok = $this->wpdb->query( $this->wpdb->prepare(
                        "INSERT INTO {$this->db->table( 'product_images' )}
                        (generation, attachment_id, object_id, parent_id, image_role, image_position, source_type, source_status, parent_status)
                        VALUES (%s,%d,%d,%d,%s,%d,%s,%s,%s)
                        ON DUPLICATE KEY UPDATE image_position=VALUES(image_position), source_status=VALUES(source_status), parent_status=VALUES(parent_status)",
                        $s['working'], $id, (int) $r['post_id'], (int) $r['post_parent'], $role, $position + 1,
                        $r['post_type'], $r['post_status'], $r['parent_status']
                    ) );
                    if ( false === $ok ) { throw new RuntimeException( 'Enregistrement de l\'index impossible. Vérification incomplète.' ); }
                }
                $s['cursor'] = (int) $r['meta_id'];
                $s['current']++;
                $processed++;
                if ( microtime( true ) - $start >= 4.0 ) { break; }
            }
            if ( count( $rows ) < 250 && $processed === count( $rows ) ) {
                if ( ! hash_equals( (string) $s['revision'], $this->revision() ) ) {
                    $s['status'] = 'stale';
                    $s['working'] = '';
                    $s['message'] = 'Des images produits ont changé pendant la vérification. Relancez-la pour valider les absences.';
                } else {
                    $s['active'] = $s['working'];
                    $s['active_revision'] = $s['revision'];
                    $s['working'] = '';
                    $s['status'] = 'complete';
                    $s['checked_at'] = current_time( 'mysql' );
                    $s['message'] = 'Images principales, galeries et images propres aux variations vérifiées.';
                }
            }
            $this->save( $s );
            if ( 'complete' === $s['status'] ) {
                // Garbage collection affects this plugin's obsolete generations only.
                $this->wpdb->query( $this->wpdb->prepare(
                    "DELETE FROM {$this->db->table( 'product_images' )} WHERE generation <> %s", $s['active']
                ) );
            }
            return $this->status();
        } catch ( Throwable $e ) {
            $s['status'] = 'error';
            $s['message'] = $e->getMessage();
            $this->save( $s );
            return new WP_Error( 'amf_wc_index_error', $e->getMessage() );
        } finally { $this->unlock( $token ); }
    }

    public function aggregate_sql() {
        $s = $this->status();
        return $this->wpdb->prepare(
            "SELECT attachment_id, COUNT(*) AS wc_count,
             SUM(image_role='main') AS wc_main, SUM(image_role='gallery') AS wc_gallery,
             SUM(source_type='product_variation') AS wc_variation
             FROM {$this->db->table( 'product_images' )} WHERE generation = %s GROUP BY attachment_id", ($s['available'] ? $s['active'] : '__inactive__')
        );
    }

    public function references( $ids ) {
        $ids = array_values( array_unique( array_filter( array_map( 'absint', (array) $ids ) ) ) );
        if ( ! $ids ) { return array(); }
        $s = $this->status();
        if ( ! $s['active'] ) { return array(); }
        $marks = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
        $rows = $this->wpdb->get_results( $this->wpdb->prepare(
            "SELECT * FROM {$this->db->table( 'product_images' )} WHERE generation = %s AND attachment_id IN ({$marks})
             ORDER BY object_id ASC, image_role ASC", array_merge( array( $s['active'] ), $ids )
        ), ARRAY_A );
        if ($this->wpdb->last_error) { return new WP_Error('amf_wc_read_error','Lecture des affectations impossible.'); }
        $out = array();
        foreach ( (array) $rows as $r ) { $out[ (int) $r['attachment_id'] ][] = $r; }
        return $out;
    }

    /** Exact native references for one media, used again before a non-forced deletion. */
    public function live_count( $id ) {
        if ( ! $this->available() ) { return 0; }
        $rows = $this->wpdb->get_results( $this->wpdb->prepare(
            'SELECT pm.meta_value, pm.meta_key ' . $this->source_sql() .
            " AND pm.meta_value LIKE %s",
            '%' . $this->wpdb->esc_like( (string) absint( $id ) ) . '%'
        ), ARRAY_A );
        if ( $this->wpdb->last_error ) { return new WP_Error( 'amf_wc_live_error', 'Vérification WooCommerce impossible. Suppression annulée.' ); }
        $n = 0;
        foreach ( (array) $rows as $r ) {
            try { if ( in_array( (int) $id, self::image_ids( $r['meta_value'], $r['meta_key'] ), true ) ) { $n++; } }
            catch ( Throwable $e ) { return new WP_Error( 'amf_wc_live_error', $e->getMessage() ); }
        }
        return $n;
    }

    /** Build fresh front links, including variation attributes when WooCommerce supplies them. */
    public function source_link( $object_id ) {
        $object_id = absint( $object_id );
        if ( isset( $this->link_cache[ $object_id ] ) ) { return $this->link_cache[ $object_id ]; }
        $p = get_post( $object_id );
        $r = array( 'title' => '#' . $object_id, 'url' => '', 'edit_url' => '', 'state' => '', 'variation' => '' );
        if ( ! $p ) { $r['state'] = 'Contenu supprimé'; return $r; }
        $r['title'] = get_the_title( $p ) ?: '#' . $object_id;
        $view_id = $object_id;
        if ( 'product_variation' === $p->post_type ) {
            $view_id = (int) $p->post_parent;
            $r['title'] = get_the_title( $view_id ) ?: $r['title'];
            if ( $this->available() ) {
                try {
                    $product = wc_get_product( $object_id );
                    if ( $product ) {
                        $r['url'] = $product->get_permalink();
                        $r['variation'] = function_exists( 'wc_get_formatted_variation' )
                            ? wp_strip_all_tags( wc_get_formatted_variation( $product, true, true, true ) ) : '#' . $object_id;
                    }
                } catch ( Throwable $e ) { /* Fall back to the parent, without a fabricated variation URL. */ }
            }
            if ( ! $r['variation'] ) { $r['variation'] = '#' . $object_id; }
        }
        $target = get_post( $view_id );
        if ( $target && ! $r['url'] ) { $r['url'] = get_permalink( $view_id ) ?: ''; }
        $r['edit_url'] = get_edit_post_link( $view_id, 'raw' ) ?: '';
        if ( 'publish' !== $p->post_status || ( $target && 'publish' !== $target->post_status ) ) {
            $status = $target && 'publish' !== $target->post_status ? $target->post_status : $p->post_status;
            $obj = get_post_status_object( $status );
            $r['state'] = $obj ? $obj->label : $status;
            if ( $target && in_array( $target->post_status, array( 'draft','pending','future' ), true ) && current_user_can( 'edit_post', $view_id ) ) {
                $r['url'] = get_preview_post_link( $view_id ) ?: '';
                $r['state'] .= ' - aperçu';
            }
            if ( 'trash' === $status ) { $r['url'] = ''; }
        }
        $this->link_cache[ $object_id ] = $r;
        return $r;
    }
}
