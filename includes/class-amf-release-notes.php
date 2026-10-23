<?php
if (!defined('ABSPATH')) { exit; }

/** Small, bounded Markdown subset. Raw HTML is always escaped, never executed. */
class AMF_Release_Notes {
    private static function inline_text($text) {
        $parts = preg_split('/(`[^`\n]+`|\*\*[^*\n]+\*\*|\[[^\]\n]+\]\(https?:\/\/[^\s)]+\))/', $text, -1, PREG_SPLIT_DELIM_CAPTURE);
        $out = '';
        foreach ($parts as $part) {
            if (strlen($part) > 2 && $part[0] === '`' && substr($part,-1) === '`') {
                $out .= '<code>'.esc_html(substr($part,1,-1)).'</code>';
            } elseif (strlen($part)>4 && substr($part,0,2)==='**' && substr($part,-2)==='**') {
                $out .= '<strong>'.esc_html(substr($part,2,-2)).'</strong>';
            } elseif (preg_match('/^\[([^\]\n]+)\]\((https?:\/\/[^\s)]+)\)$/D',$part,$m)) {
                $url = esc_url($m[2],array('https','http'));
                $out .= $url ? '<a href="'.$url.'" target="_blank" rel="noopener noreferrer">'.esc_html($m[1]).'</a>' : esc_html($m[1]);
            } else { $out .= esc_html($part); }
        }
        return $out;
    }
    public static function render($markdown) {
        if (!is_string($markdown) || trim($markdown)==='') { return '<p>Aucune note de version disponible.</p>'; }
        $lines = preg_split('/\r\n|\r|\n/',substr($markdown,0,20000));
        $out=''; $paragraph=array(); $list=''; $code=false; $code_lines=array();
        $flush=function() use (&$out,&$paragraph,&$list) {
            if ($paragraph) { $out.='<p>'.self::inline_text(implode(' ',$paragraph)).'</p>'; $paragraph=array(); }
            if ($list) { $out.='</'.$list.'>'; $list=''; }
        };
        foreach ($lines as $line) {
            if (preg_match('/^\s*```/',$line)) {
                $flush();
                if ($code) { $out.='<pre><code>'.esc_html(implode("\n",$code_lines)).'</code></pre>'; $code_lines=array(); }
                $code=!$code; continue;
            }
            if ($code) { $code_lines[]=$line; continue; }
            if (trim($line)==='') { $flush(); continue; }
            if (preg_match('/^\s*(#{1,6})\s+(.+)$/',$line,$m)) {
                $flush(); $h=min(6,strlen($m[1])+1); $out.='<h'.$h.'>'.self::inline_text($m[2]).'</h'.$h.'>'; continue;
            }
            if (preg_match('/^\s*(?:[-+*]|[0-9]+\.)\s+(.+)$/',$line,$m)) {
                $type=preg_match('/^\s*[0-9]+\./',$line)?'ol':'ul';
                if ($paragraph || ($list && $list!==$type)) { $flush(); }
                if (!$list) { $list=$type; $out.='<'.$type.'>'; }
                $out.='<li>'.self::inline_text($m[1]).'</li>'; continue;
            }
            if ($list) { $flush(); }
            $paragraph[]=trim($line);
        }
        $flush();
        if ($code) { $out.='<pre><code>'.esc_html(implode("\n",$code_lines)).'</code></pre>'; }
        return $out;
    }
}
