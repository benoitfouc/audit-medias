<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Shared presentation: product assignments now; other references at last full audit. */
class AMF_Usage_View {
    private $db;
    private $wpdb;
    private $products;

    public function __construct( AMF_Database $db, AMF_Product_Usage $products ) {
        $this->db = $db;
        $this->products = $products;
        global $wpdb;
        $this->wpdb = $wpdb;
    }

    public static function family_expr( $f = 'f' ) {
        return "CASE WHEN {$f}.family_key <> '' THEN {$f}.family_key WHEN {$f}.attachment_id IS NOT NULL
            THEN MD5(CONCAT('attachment:',{$f}.attachment_id)) ELSE MD5(CONCAT('legacy-file:',{$f}.id)) END";
    }

    private function other_condition() {
        $condition = "COALESCE(u.source_subtype,'') <> 'attachment_parent' AND u.source_type <> 'revision'";
        if($this->products->available()) {
            $condition .= " AND NOT (u.source_type IN ('product','product_variation')
                AND COALESCE(u.source_subtype,'') IN ('_thumbnail_id','_product_image_gallery'))";
        }
        return $condition;
    }

    private function usage_target() {
        return "CASE WHEN u.attachment_id > 0 THEN MD5(CONCAT('attachment:',u.attachment_id))
            ELSE " . self::family_expr( 'uf' ) . ' END';
    }

    private function usage_from() {
        return "FROM {$this->db->table('usage')} u LEFT JOIN {$this->db->table('files')} uf
            ON u.attachment_id=0 AND uf.scan_id=u.scan_id AND uf.path_hash=u.file_path_hash";
    }

    /** A confirmed file URL is not a confirmed link to a heuristically associated media. */
    private function effective_confidence() {
        return "CASE WHEN u.confidence='confirmed' AND (u.attachment_id>0 OR
            COALESCE(uf.attachment_id,0)=0 OR uf.association_confidence=100)
            THEN 'confirmed' ELSE 'probable' END";
    }

    /** Counts source locations, not thumbnail count nor duplicated URL/ID matches. */
    public function other_aggregate_sql( $scan_id ) {
        $confidence = $this->effective_confidence();
        $source = "CONCAT(u.source_type,':',u.source_id,':',COALESCE(u.source_subtype,''))";
        return $this->wpdb->prepare(
            'SELECT ' . $this->usage_target() . " AS group_key,
             COUNT(DISTINCT {$source}) AS other_count,
             COUNT(DISTINCT CASE WHEN ({$confidence})='confirmed' THEN {$source} END) AS other_confirmed " .
             $this->usage_from() . ' WHERE u.scan_id=%d AND ' . $this->other_condition() . ' GROUP BY group_key',
            (int) $scan_id
        );
    }

    public function other_references( $scan_id, $attachment_ids, $family_keys = array() ) {
        $ids = array_values( array_unique( array_filter( array_map( 'absint', (array) $attachment_ids ) ) ) );
        $keys = array_values( array_filter( (array) $family_keys, function( $k ) { return is_string( $k ) && preg_match('/^[a-f0-9]{32}$/D', $k); } ) );
        if ( ! $ids && ! $keys ) { return array(); }
        $where = array(); $params = array( (int) $scan_id );
        if ( $ids ) {
            $marks = implode(',',array_fill(0,count($ids),'%d'));
            $where[] = "u.attachment_id IN ({$marks}) OR uf.attachment_id IN ({$marks})";
            $params = array_merge( $params, $ids, $ids );
        }
        if ( $keys ) {
            $where[] = 'uf.family_key IN (' . implode(',',array_fill(0,count($keys),'%s')) . ')';
            $params = array_merge( $params, $keys );
        }
        $sql = 'SELECT u.*, ' . $this->effective_confidence() . ' AS effective_confidence, ' . $this->usage_target() . ' AS target_key ' . $this->usage_from() .
            ' WHERE u.scan_id=%d AND ' . $this->other_condition() . ' AND (' . implode(' OR ',$where) .
            ") ORDER BY u.confidence ASC, u.source_type, u.source_id, u.source_subtype, u.id LIMIT 6000";
        $rows = $this->wpdb->get_results( $this->wpdb->prepare( $sql, $params ), ARRAY_A );
        if ($this->wpdb->last_error) { return new WP_Error('amf_other_read_error','Lecture des autres références impossible.'); }
        $out = array();
        foreach ( (array) $rows as $r ) {
            $r['confidence'] = $r['effective_confidence'];
            $target = (string) $r['target_key'];
            if ( ! $target ) { continue; }
            $key = $r['source_type'] . ':' . $r['source_id'] . ':' . $r['source_subtype'];
            // Prefer the confirmed evidence; never turn a probable match into a confirmed one.
            if ( ! isset($out[$target][$key]) || 'confirmed' === $r['confidence'] ) { $out[$target][$key] = $r; }
        }
        foreach ( $out as &$items ) { $items = array_values($items); }
        unset($items);
        return $out;
    }

    public function product_html( $refs, $attachment_id, $compact = true, $uncertain = false ) {
        if(is_wp_error($refs)) { return '<span class="amf-badge amf-pending">À vérifier - lecture impossible</span>'; }
        $s = $this->products->status();
        if ( ! $s['available'] ) { return '<span class="amf-text-muted">WooCommerce inactif</span>'; }
        if ( ! $attachment_id ) {
            return '<span class="amf-text-muted">Sans média WordPress identifié</span><small class="amf-cell-note">Les références par URL sont indiquées à droite.</small>';
        }
        if ( $uncertain ) {
            return '<span class="amf-badge amf-probable">Association de fichiers à vérifier</span><small class="amf-cell-note">Le rapprochement avec le média repose sur le nom. Voir les détails.</small>';
        }
        if ( ! $refs ) {
            return $s['ready']
                ? '<strong class="amf-usage-absent">Absente des images produits</strong><small class="amf-cell-note">Ni principale, ni galerie, ni image de variation.</small>'
                : '<span class="amf-badge amf-pending">À vérifier</span><small class="amf-cell-note">Aucune conclusion avant la fin de la vérification WooCommerce.</small>';
        }
        $html = $s['ready'] ? '' : '<small class="amf-cell-note amf-stale">Dernière vérification - actualisation nécessaire</small>';
        $html .= '<div class="amf-reference-list">';
        foreach ( array_values($refs) as $i => $ref ) {
            if ( $compact && $i === 2 ) { $html .= '<details class="amf-more-references"><summary>Voir les ' . esc_html( count($refs)-2 ) . ' autres affectations</summary>'; }
            $link = $this->products->source_link( (int) $ref['object_id'] );
            $role = 'main' === $ref['image_role'] ? 'Image principale' : ( 'gallery' === $ref['image_role'] ? 'Galerie' : 'Variation' );
            if ( 'product_variation' === $ref['source_type'] && $link['variation'] ) {
                $role = ( 'gallery' === $ref['image_role'] ? 'Galerie variation : ' : 'Variation : ' ) . $link['variation'];
            }
            $html .= '<div class="amf-reference"><strong class="amf-reference-role">' . esc_html($role) . '</strong>';
            $html .= $link['url'] ? '<a href="' . esc_url($link['url']) . '" target="_blank" rel="noopener noreferrer" title="Voir le produit sur le site">' . esc_html($link['title']) . ' &#8599;</a>' : esc_html($link['title']);
            if ( $link['state'] ) { $html .= '<small class="amf-cell-note">' . esc_html($link['state']) . '</small>'; }
            $html .= '</div>';
        }
        if ( $compact && count($refs)>2 ) { $html .= '</details>'; }
        return $html . '</div>';
    }

    /** A direct file URL found on ANY member protects the whole family from negative-use filters. */
    private function direct_file_usage_html( $direct_count, $compact = true ) {
        $direct_count = max( 0, (int) $direct_count );
        if ( ! $direct_count ) { return ''; }
        return '<div class="amf-direct-family-usage"><strong>URL(s) de fichiers de cette famille retrouvée(s) : '
            . esc_html( number_format_i18n( $direct_count ) ) . '</strong>'
            . '<small class="amf-cell-note">Au moins un original, une miniature ou une conversion est cité par URL dans le dernier audit. '
            . ( $compact ? 'Dépliez « Détails / utilisations », puis la liste des fichiers pour identifier les variantes concernées.'
                : 'La liste des fichiers ci-dessous indique les variantes avec des références URL.' )
            . ' Ce compteur peut recouper les emplacements déjà listés.</small></div>';
    }

    public function other_html( $refs, $ready, $count = 0, $compact = true, $direct_count = 0 ) {
        $direct = $this->direct_file_usage_html( $direct_count, $compact );
        if(is_wp_error($refs)) { return '<span class="amf-badge amf-pending">Références à vérifier - lecture impossible</span>' . $direct; }
        if ( ! $refs ) {
            if ( $count > 0 ) { return '<span class="amf-badge amf-probable">' . esc_html($count) . ' référence(s) - ouvrir les détails</span>' . $direct; }
            if ( $direct ) { return $direct; } // Never claim absence when one physical variant has a reference.
            return $ready ? '<span class="amf-text-muted">Aucune autre référence détectée (URLs comprises)</span>'
                : '<span class="amf-badge amf-pending">Audit des références en attente</span>';
        }
        $html = '<div class="amf-reference-list">';
        foreach ( array_values($refs) as $i => $ref ) {
            if ( $compact && $i===2 ) { $html .= '<details class="amf-more-references"><summary>Voir les ' . esc_html(count($refs)-2) . ' autres emplacements</summary>'; }
            $link = $this->other_link($ref);
            $html .= '<div class="amf-reference"><strong class="amf-reference-role">' . esc_html($link['role']) . '</strong>';
            if ( 'probable' === $ref['confidence'] ) { $html .= '<small class="amf-probable-tag">Rapprochement à confirmer</small>'; }
            $html .= $link['url'] ? '<a href="' . esc_url($link['url']) . '" target="_blank" rel="noopener noreferrer">' . esc_html($link['title']) . ' &#8599;</a>' : '<span>' . esc_html($link['title']) . '</span>';
            if ( $link['note'] ) { $html .= '<small class="amf-cell-note">' . esc_html($link['note']) . '</small>'; }
            if ( ! $compact && ! empty($ref['edit_url']) ) { $html .= '<small class="amf-cell-note"><a href="' . esc_url($ref['edit_url']) . '">Modifier</a></small>'; }
            $html .= '</div>';
        }
        if ( $compact && count($refs)>2 ) { $html .= '</details>'; }
        if ( $count > count($refs) ) { $html .= '<small class="amf-cell-note">Liste partielle : ' . esc_html($count) . ' emplacements comptés dans l\'audit.</small>'; }
        return $html . '</div>' . $direct;
    }

    private function other_link( $r ) {
        $type = (string)$r['source_type']; $sub = (string)$r['source_subtype'];
        $link = array('url'=>'','title'=>'','role'=>'Référence du dernier audit','note'=>'');
        if ( 'term' === $type ) {
            $term = get_term((int)$r['source_id']);
            if ( $term && ! is_wp_error($term) ) {
                $tax = get_taxonomy($term->taxonomy);
                $link['role'] = $tax ? $tax->labels->singular_name : 'Taxonomie';
                $link['title'] = $term->name;
                $url = get_term_link($term);
                if ( ! is_wp_error($url) ) { $link['url'] = $url; }
            } else { $link['title'] = 'Ancien terme #' . $r['source_id']; $link['note'] = 'Terme absent depuis le dernier audit.'; }
        } elseif ( 'option' === $type ) {
            $link['role'] = 'Réglage du site';
            $link['title'] = $sub ?: 'Option WordPress';
            $link['note'] = 'Emplacement public non déterminé. Référence conservée dans les réglages.';
        } else {
            $p = get_post((int)$r['source_id']);
            if ( $p ) {
                $info = $this->products->source_link((int)$r['source_id']);
                $link['url'] = $info['url']; $link['title'] = $info['title']; $link['note'] = $info['state'];
                $obj = get_post_type_object($p->post_type);
                $type_label = $obj ? $obj->labels->singular_name : $p->post_type;
                if ( 0 === strpos($sub, 'shortcode:') ) {
                    $parts = explode(':', $sub, 3);
                    $tag = isset($parts[1]) ? $parts[1] : '';
                    $attr = isset($parts[2]) ? $parts[2] : '';
                    $role = AMF_Reference_Parser::shortcode_attribute_role($tag, $attr);
                    $link['role'] = ($role ?: 'Image') . ' - shortcode [' . $tag . ']';
                    $link['note'] = trim($link['note'] . ' Attribut : ' . $attr . '. Référence enregistrée dans le contenu.');
                }
                elseif ( in_array($sub, array('_wpb_shortcodes_custom_css','_wpb_post_custom_css'), true) ) {
                    $link['role'] = 'Image dans le CSS WPBakery - ' . $type_label;
                }
                elseif ( '_elementor_data' === $sub ) { $link['role'] = 'Elementor - ' . $type_label; }
                elseif ( '_thumbnail_id' === $sub ) { $link['role'] = 'Image mise en avant - ' . $type_label; }
                elseif ( in_array($sub,array('product','product_variation'),true) ) { $link['role'] = 'Description du produit'; }
                elseif ( in_array($sub,array('post','page',$p->post_type),true) ) { $link['role'] = 'Contenu - ' . $type_label; }
                else { $link['role'] = $type_label . ' - champ personnalisé'; $link['note'] = trim($link['note'] . ' ' . $sub); }
                if ( function_exists('is_post_type_viewable') && ! is_post_type_viewable($p->post_type) && 'product_variation' !== $p->post_type ) {
                    $link['url'] = ''; $link['note'] = 'Modèle ou contenu interne : emplacement public non déterminé.';
                }
            } else {
                $link['title'] = 'Ancien contenu #' . $r['source_id'];
                $link['note'] = 'Contenu absent depuis le dernier audit.';
            }
        }
        if ( ! $link['title'] ) { $link['title'] = $r['context'] ?: 'Référence sans emplacement public'; }
        return $link;
    }

    public function history_html( $attachment_id ) {
        $p = $attachment_id ? get_post($attachment_id) : null;
        if ( ! $p || 'attachment' !== $p->post_type || ! $p->post_parent ) { return ''; }
        $link = $this->products->source_link((int)$p->post_parent);
        $html = '<details class="amf-history-note"><summary>Ancien rattachement WordPress</summary><p>';
        $html .= $link['url'] ? '<a href="' . esc_url($link['url']) . '" target="_blank" rel="noopener noreferrer">' . esc_html($link['title']) . ' &#8599;</a>' : esc_html($link['title']);
        return $html . '</p><p class="description">Origine du téléversement uniquement : ce rattachement ne prouve pas une utilisation actuelle.</p></details>';
    }
    public function list_data( $scan, $mode, $filters, $usage_ready ) {
        $sid = (int)$scan['id'];
        $usage_ready = $usage_ready && AMF_Reference_Parser::scan_is_current($scan) && 'complete' === $scan['status'];
        $files = $this->db->table('files'); $media = $this->db->table('media');
        $params = array($sid);
        if ( 'files' === $mode ) {
            $base = 'SELECT ' . self::family_expr() . " AS group_key,
                MAX(NULLIF(f.family_key,'')) AS family_key, MAX(f.attachment_id) AS attachment_id,
                MIN(f.id) AS sample_file_id, MIN(f.rel_path) AS sample_rel_path,
                COALESCE(MAX(NULLIF(m.rel_path,'')), MIN(f.rel_path)) AS rel_path,
                MAX(m.filename) AS filename, MAX(m.title) AS title, MAX(f.family_stem) AS family_stem,
                COUNT(DISTINCT f.attachment_id) AS owner_count, COUNT(*) AS file_count, SUM(f.bytes) AS total_bytes,
                SUM(f.direct_usage_count) AS direct_usage_count,
                MAX(f.association_confidence) AS max_confidence, MIN(f.association_confidence) AS min_confidence,
                SUM(f.classification='unassociated_ambiguous') AS ambiguous_count,
                SUM(f.current_meta=1) AS current_meta_count,
                MAX(m.file_exists) AS file_exists, MAX(m.width) AS width, MAX(m.height) AS height
                FROM {$files} f LEFT JOIN {$media} m ON m.scan_id=f.scan_id AND m.attachment_id=f.attachment_id
                WHERE f.scan_id=%d AND f.physical=1 GROUP BY group_key";
        } else {
            $base = "SELECT MD5(CONCAT('attachment:',m.attachment_id)) AS group_key,
                MD5(CONCAT('attachment:',m.attachment_id)) AS family_key, m.attachment_id,
                0 AS sample_file_id, m.rel_path AS sample_rel_path, m.rel_path, m.filename, m.title, m.family_stem,
                m.physical_count AS file_count, m.total_bytes,
                COALESCE((SELECT SUM(fx.direct_usage_count) FROM {$files} fx
                    WHERE fx.scan_id=m.scan_id AND fx.attachment_id=m.attachment_id AND fx.physical=1),0) AS direct_usage_count,
                100 AS max_confidence,
                100 AS min_confidence, 1 AS owner_count, 0 AS ambiguous_count, 0 AS current_meta_count,
                m.file_exists, m.width, m.height
                FROM {$media} m WHERE m.scan_id=%d";
        }
        $wc = $this->products->aggregate_sql();
        $other = $this->other_aggregate_sql($sid);
        $from = "FROM ({$base}) g LEFT JOIN ({$wc}) w ON w.attachment_id=g.attachment_id
            LEFT JOIN ({$other}) o ON o.group_key=g.group_key";
        $where = array('1=1');
        $s = $this->products->status();
        $wc_valid = !empty($s['ready']);
        $wc_filter = $filters['wc_usage']; $other_filter = $filters['other_usage'];
        $state = $filters['state']; $assoc = $filters['assoc'];
        // The count from the file scanner belongs to the WHOLE family (original + all variants).
        // Meta registration alone is not a usage: only scanned content references count here.
        $no_other = '(COALESCE(o.other_count,0)=0 AND COALESCE(g.direct_usage_count,0)=0)';
        $has_other = '(COALESCE(o.other_count,0)>0 OR COALESCE(g.direct_usage_count,0)>0)';
        $no_usage = '(COALESCE(w.wc_count,0)=0 AND ' . $no_other . ')';
        if ( $filters['s'] !== '' ) {
            $like = '%' . $this->wpdb->esc_like($filters['s']) . '%';
            $where[] = '(g.filename LIKE %s OR g.title LIKE %s OR g.family_stem LIKE %s OR g.sample_rel_path LIKE %s OR g.attachment_id=%d' .
                ( 'files' === $mode ? " OR EXISTS(SELECT 1 FROM {$files} sf WHERE sf.scan_id={$sid} AND sf.family_key=g.group_key AND sf.rel_path LIKE %s)" : '' ) . ')';
            $params = array_merge($params,array($like,$like,$like,$like,absint($filters['s'])));
            if ('files'===$mode) { $params[]=$like; }
        }
        if ( $wc_filter ) {
            if ( !$wc_valid ) { $where[]='1=0'; }
            elseif ('absent'===$wc_filter) { $where[]='g.attachment_id>0 AND g.max_confidence=100 AND g.owner_count<=1 AND g.ambiguous_count=0 AND COALESCE(w.wc_count,0)=0'; }
            elseif ('used'===$wc_filter) { $where[]='COALESCE(w.wc_count,0)>0'; }
            elseif (in_array($wc_filter,array('main','gallery','variation'),true)) { $where[]='COALESCE(w.wc_' . $wc_filter . ',0)>0'; }
        }
        if ( $other_filter ) {
            if (!$usage_ready) { $where[]='1=0'; }
            elseif ('none'===$other_filter) { $where[]=$no_other; }
            elseif ('found'===$other_filter) { $where[]=$has_other; }
        }
        // Preserve links/bookmarks from older releases, with clearer semantics.
        if ('missing'===$state) { $where[]='g.file_exists=0'; }
        if ('used'===$state) { $where[]='(COALESCE(w.wc_count,0)>0 OR COALESCE(o.other_confirmed,0)>0 OR COALESCE(g.direct_usage_count,0)>0)'; }
        if ('probable'===$state) { $where[]='COALESCE(w.wc_count,0)=0 AND COALESCE(o.other_confirmed,0)=0 AND COALESCE(g.direct_usage_count,0)=0 AND COALESCE(o.other_count,0)>0'; }
        if ('no_usage'===$state) {
            $where[]=$usage_ready && ($wc_valid || !$s['available']) ? $no_usage : '1=0';
        }
        if ('review'===$assoc) {
            $where[]=$usage_ready && ($wc_valid || !$s['available']) ? $no_usage . ' AND g.ambiguous_count=0' : '1=0';
        }
        if ('unassociated'===$assoc) { $where[]='g.attachment_id IS NULL'; }
        if ('direct_used'===$assoc) { $where[]='g.attachment_id IS NULL AND ' . $has_other; }
        if ('probable'===$assoc) { $where[]='g.attachment_id IS NOT NULL AND g.max_confidence<100'; }
        if ('confirmed'===$assoc) { $where[]='g.attachment_id IS NOT NULL AND g.max_confidence=100'; }
        $from .= ' WHERE ' . implode(' AND ',$where);
        $total = $this->wpdb->get_var($this->wpdb->prepare('SELECT COUNT(*) ' . $from,$params));
        if ($this->wpdb->last_error) { return new WP_Error('amf_list_error','Lecture des résultats impossible. Aucun classement négatif ne peut être affiché.'); }
        $priority = 'CASE WHEN ' . $no_usage . ' THEN 0 WHEN COALESCE(w.wc_count,0)=0 THEN 1 ELSE 2 END';
        $sorts = array(
            'media'=>"LOWER(COALESCE(NULLIF(g.filename,''),g.family_stem,g.sample_rel_path))", 'family'=>'g.family_stem',
            'state'=>$priority, 'wc'=>'COALESCE(w.wc_count,0)', 'files'=>'g.file_count',
            'references'=>'GREATEST(COALESCE(o.other_count,0),COALESCE(g.direct_usage_count,0))', 'size'=>'g.total_bytes',
        );
        $order = 'asc' === $filters['order'] ? 'ASC' : 'DESC';
        $sort = isset($sorts[$filters['orderby']]) ? $sorts[$filters['orderby']] . ' ' . $order : $priority . ' ASC';
        // Deterministic tie break: pagination must not repeat or lose equal-valued rows.
        $sort .= ', g.total_bytes DESC, g.group_key ASC';
        $per_page=(int)$filters['per_page'];
        $page=min(max(1,(int)$filters['paged']),max(1,(int)ceil((int)$total/$per_page)));
        $rows=$this->wpdb->get_results($this->wpdb->prepare(
            'SELECT g.*, COALESCE(w.wc_count,0) AS wc_count, COALESCE(o.other_count,0) AS other_count,
             COALESCE(o.other_confirmed,0) AS other_confirmed ' . $from . ' ORDER BY ' . $sort . ' LIMIT %d OFFSET %d',
            array_merge($params,array($per_page,($page-1)*$per_page))
        ),ARRAY_A);
        if($this->wpdb->last_error) { return new WP_Error('amf_list_error','Le tableau ne peut pas être lu. Réessayez avant toute suppression.'); }
        return array('rows'=>(array)$rows,'total'=>(int)$total,'paged'=>$page);
    }

}
