<?php
/* Isolated presentation regressions: no WordPress database or media mutation. */
if (PHP_SAPI !== 'cli') { exit; }
error_reporting(E_ALL);
set_error_handler(function($level,$message,$file,$line){throw new RuntimeException($message.' at '.$file.':'.$line);});
define('ABSPATH','/fixture/');
function esc_html($value){return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');}
function esc_url($value){return preg_match('~^https?://~i',(string)$value)?esc_html($value):'';}
function is_wp_error($value){return false;}
function number_format_i18n($value){return number_format((int)$value);}
function get_post($id){return isset($GLOBALS['posts'][$id])?$GLOBALS['posts'][$id]:null;}
function get_post_meta($id,$key,$single=false){return isset($GLOBALS['templates'][$id])?$GLOBALS['templates'][$id]:'';}
function get_post_type_object($type){return (object)array('labels'=>(object)array('singular_name'=>'Modele'));}
function is_post_type_viewable($type){return true;}
class AMF_Database {}
class AMF_Product_Usage {
    function source_link($id){return array('title'=>$GLOBALS['titles'][$id],'url'=>'https://example.test/source/'.$id,'state'=>'','variation'=>'');}
    function available(){return false;}
}
require __DIR__.'/../includes/class-amf-usage-view.php';
require __DIR__.'/../includes/class-amf-release-notes.php';
$GLOBALS['wpdb']=new stdClass();
$GLOBALS['posts']=array();
$GLOBALS['titles']=array();
$GLOBALS['templates']=array(1=>'kit',2=>'footer',3=>'header',4=>'');
foreach(array(1=>'Kit par defaut',2=>'Footer',3=>'Header',4=>'Article') as $id=>$title){
    $GLOBALS['posts'][$id]=(object)array('ID'=>$id,'post_type'=>$id===4?'post':'elementor_library');
    $GLOBALS['titles'][$id]=$title;
}
$view=new AMF_Usage_View(new AMF_Database(),new AMF_Product_Usage());
$refs=array();
foreach(array(1,4,2,3) as $id){$refs[]=array('source_type'=>$id===4?'post':'elementor_library','source_subtype'=>$id===4?'post':'_elementor_data','source_id'=>$id,'confidence'=>'confirmed','context'=>'','edit_url'=>'');}
$count=0;
function check($condition,$label){global $count;if(!$condition)throw new RuntimeException('FAIL '.$label);++$count;echo 'PASS '.$label.PHP_EOL;}
$html=$view->other_html($refs,true,0,true,0);
$hidden=strpos($html,'Voir les 2 autres emplacements');
check($hidden!==false,'all references retained in expandable list');
check(strpos($html,'Footer')!==false && strpos($html,'Footer')<$hidden,'Elementor Footer shown before fold');
check(strpos($html,'Header')!==false && strpos($html,'Header')<$hidden,'Elementor Header shown before fold');
check(strpos($html,'Kit par defaut')>$hidden,'Elementor kit folded by default');
check(strpos($html,'Elementor - Modele')!==false,'readable Elementor model role preserved');
check(substr_count($html,'class="amf-reference"')===4,'no lost source refs');
$full=$view->other_html($refs,true,0,false,0);
check(strpos($full,'Kit par defaut')<strpos($full,'Footer'),'full details retain original ordering');
check(strpos($full,'Voir les 2 autres emplacements')===false,'full details do not hide references');
$md="# Audit Medias v2.3.10\n\n## Details\n- **Important**\n- `<b>`\n";
$out=AMF_Release_Notes::render($md);
check(strpos($out,'<h2>')===false,'no top h2 to clear WordPress floated plugin sidebar');
check(strpos($out,'<h3>Audit Medias v2.3.10</h3>')!==false,'top heading uses compact semantic h3');
check(strpos($out,'<h4>Details</h4>')!==false,'subheadings remain hierarchical');
check(strpos($out,'<strong>Important</strong>')!==false,'emphasis parsed and preserved');
check(strpos($out,'<code>&lt;b&gt;</code>')!==false,'raw HTML inside backtick is escaped');
$php=file_get_contents(__DIR__.'/../includes/class-amf-admin.php');
check(strpos($php,"'amf_restart_scan'")!==false,'restart nonce action present');
check(strpos($php,'wp_nonce_url(')!==false,'restart hyperlink nonce protected');
check(strpos($php,'wp_verify_nonce(')!==false,'restart nonce checked by PHP');
check(strpos($php,'amf-overview-shortcuts')!==false,'overview shortcut links rendered');
check(strpos($php,'review_families')!==false,'overview uses existing aggregate statistics');
check(strpos($php,'amf-phase-step')!==false,'phase progress label present');
$js=file_get_contents(__DIR__.'/../assets/admin.js');
check(strpos($js,"['media', 'usage_posts', 'usage_postmeta', 'usage_termmeta', 'usage_options', 'files', 'woo', 'finalize']")!==false,'all 8 backend phases declared in frontend');
check(strpos($js,"if (AMF_DATA.autoRestart && $('#amf-start-scan').length)")!==false,'nonce-gated restart launches existing AJAX mechanism');
check(strpos($js,"$('#amf-start-scan').trigger('click')")!==false,'existing confirmation and start path reused');
$css=file_get_contents(__DIR__.'/../assets/admin-brand.css');
check(strpos($css,'.amf-browse-media.button-primary')!==false,'finished audit CTA receives dedicated green style');
check(strpos($css,'#c0fa39')!==false,'Benfou lime present');
check(strpos($css,'.amf-phase-step')!==false,'step indicator styled');
$up=file_get_contents(__DIR__.'/../includes/class-amf-updates.php');
check(strpos($up,'blob/main/CHANGELOG.md')!==false,'full changelog GitHub link in modal');
check(strpos($up,'class="amf-release-notes"')!==false,'changelog wrapped for scoped styling');
echo 'Audit Medias 2.3.10: '.$count.' isolated assertions passed'.PHP_EOL;
