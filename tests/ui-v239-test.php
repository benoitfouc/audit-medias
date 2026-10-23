<?php
if ( PHP_SAPI !== 'cli' ) { exit; }
error_reporting( E_ALL );
set_error_handler( function($level,$message,$file,$line) { throw new RuntimeException($message.' at '.$file.':'.$line); });
define( 'ABSPATH', '/fixture/' );
class AMF_Database {}
class AMF_Product_Usage {
    public $source = array();
    public function source_link($id) { return $this->source; }
}
function get_post($id) { return isset($GLOBALS['media_post']) ? $GLOBALS['media_post'] : null; }
function esc_html($s) { return htmlspecialchars( (string)$s, ENT_QUOTES, 'UTF-8' ); }
function esc_url($s) { return preg_match('~^https?://~i',(string)$s) ? esc_html($s) : ''; }
require __DIR__ . '/../includes/class-amf-usage-view.php';
$pass=0;
function check($cond,$name) { global $pass; if(!$cond)throw new RuntimeException('FAIL '.$name); ++$pass; echo 'PASS '.$name.PHP_EOL; }
$db = new AMF_Database(); $products = new AMF_Product_Usage(); $view = new AMF_Usage_View($db,$products);
check($view->history_html(0)==='', 'no historical note when attachment identifier absent');
$GLOBALS['media_post'] = (object)array('post_type'=>'attachment','post_parent'=>0);
check($view->history_html(31)==='', 'no fabricated historical source for unattached media');
$GLOBALS['media_post'] = (object)array('post_type'=>'post','post_parent'=>88);
check($view->history_html(31)==='', 'never render source for non-attachment');
$GLOBALS['media_post'] = (object)array('post_type'=>'attachment','post_parent'=>88);
$products->source=array('title'=>'Page de présentation','url'=>'https://example.test/ancienne-page?x=1&y=2','edit_url'=>'https://example.test/wp-admin/post.php?post=88&action=edit','state'=>'');
$html=$view->history_html(31);
check(strpos($html,'<section class="amf-history-note"')!==false,'semantic always-visible section');
check(strpos($html,'<details')===false && strpos($html,'<summary')===false,'never a collapsible disclosure');
check(strpos($html,'Ancien rattachement WordPress')!==false,'section heading present');
check(strpos($html,'Page de présentation')!==false,'historic content preserved');
check(strpos($html,'Ouvrir dans WordPress')!==false,'link to edit parent when provided');
check(strpos($html,'ne prouve pas')!==false,'historic evidence not real current usage');
check(strpos($html,'&amp;y=2')!==false,'URL ampersands escaped');
$products->source=array('title'=>'<script>alert(1)</script>','url'=>'javascript:alert(1)','edit_url'=>'','state'=>'Contenu supprimé');
$html=$view->history_html(31);
check(strpos($html,'<script>')===false && strpos($html,'&lt;script&gt;')!==false,'malicious title escaped');
check(strpos($html,'href="javascript:')===false,'malicious historical link rejected');
check(strpos($html,'href=""')===false,'unsafe URL not represented by empty hyperlink');
check(strpos($html,'Contenu supprimé')!==false,'missing source status shown');
check(strpos($html,'<strong>')!==false,'missing link retains source label');
$css=file_get_contents(__DIR__.'/../assets/admin-brand.css');
check(strpos($css,'background:transparent;border:0;border-radius:0;padding:0')!==false,'original SVG shown without added white card');
check(strpos($css,'.amf-wrap .amf-history-note-header')!==false,'historical context has visible header styling');
check(strpos($css,'#ebebeb')!==false,'Benfou grey header unchanged');
check(strpos($css,'.amf-wrap .amf-status-danger')!==false,'delete-risk badge styling preserved');
echo 'UI 2.3.9: '.$pass.' assertions passed' .PHP_EOL;
