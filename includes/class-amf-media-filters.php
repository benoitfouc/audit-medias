<?php
if (!defined('ABSPATH')) { exit; }

/** Fixed SQL fragments only; these filters never grant permission to delete. */
class AMF_Media_Filters {
    public static function normalize($filters,$has_new_usage) {
        $valid=array('','unused','used','products','review','legacy');
        $usage=isset($filters['usage']) && in_array($filters['usage'],$valid,true) ? $filters['usage'] : '';
        $legacy=!empty($filters['wc_usage']) || !empty($filters['other_usage']);
        if (!$has_new_usage && $legacy) { $usage='legacy'; }
        if ($has_new_usage && 'legacy'!==$usage) { $filters['wc_usage']=''; $filters['other_usage']=''; }
        if ('legacy'===$usage && !$legacy) { $usage=''; }
        $filters['usage']=$usage;
        return $filters;
    }
    public static function options($woo) {
        $out=array(''=>'Toutes les utilisations','unused'=>'Sans utilisation détectée','used'=>'Avec utilisation détectée');
        if ($woo) { $out['products']='Utilisées sur une fiche produit'; }
        $out['review']='Association à vérifier';
        return $out;
    }
    public static function condition($usage,$audit_ready,$woo_ready,$woo_available) {
        if (!in_array($usage,array('unused','used','products','review'),true)) { return ''; }
        if (!$audit_ready || ($woo_available && !$woo_ready)) { return '1=0'; }
        $count='(COALESCE(w.wc_count,0)>0 OR COALESCE(o.other_count,0)>0 OR COALESCE(g.direct_usage_count,0)>0)';
        if ('used'===$usage) { return $count; }
        if ('products'===$usage) { return $woo_available ? 'COALESCE(w.wc_count,0)>0' : '1=0'; }
        if ('review'===$usage) { return '(g.ambiguous_count>0 OR g.owner_count>1 OR (g.attachment_id IS NOT NULL AND g.min_confidence<100))'; }
        return '(COALESCE(w.wc_count,0)=0 AND COALESCE(o.other_count,0)=0 AND COALESCE(g.direct_usage_count,0)=0 AND g.ambiguous_count=0 AND g.owner_count<=1 AND (g.attachment_id IS NULL OR g.min_confidence=100))';
    }
}
