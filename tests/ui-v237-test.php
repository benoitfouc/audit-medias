<?php
if (PHP_SAPI !== 'cli') { exit; }
error_reporting(E_ALL);
set_error_handler(function($l,$m,$f,$n){throw new RuntimeException($m.' at '.$f.':'.$n);});
define('ABSPATH','/fixture/');define('AMF_PLUGIN_FILE','/plugins/audit-medias/audit-medias.php');
$passes=0;$allowed=true;$hooks=array();
function check($v,$m){global $passes;if(!$v)throw new RuntimeException('FAIL '.$m);$passes++;echo 'PASS '.$m.PHP_EOL;}
function esc_html($s){return htmlspecialchars((string)$s,ENT_QUOTES,'UTF-8');}
function esc_url($s,$protocols=array()){return preg_match('#^https?://#i',$s)?htmlspecialchars($s,ENT_QUOTES,'UTF-8'):'';}
function current_user_can($c){global $allowed;return $allowed;}
function admin_url($s){return 'https://example.test/wp-admin/'.$s;}
function plugin_basename($p){return 'audit-medias/audit-medias.php';}
function plugin_dir_url($p){return 'https://example.test/wp-content/plugins/audit-medias/';}
function add_filter($h,$c,$p=10){global $hooks;$hooks[]=$h;}
require __DIR__.'/../includes/class-amf-release-notes.php';
require __DIR__.'/../includes/class-amf-presentation.php';
require __DIR__.'/../includes/class-amf-media-filters.php';
$md="# Version 2.3.7\n\n## Nouveautes\n- **Important** : `audit-medias/`\n- [Guide](https://example.test/?a=1&b=2)\n\nTexte normal.\n\n1. Premier\n2. Deuxieme\n\n```html\n<script>alert(1)</script>\n```";
$html=AMF_Release_Notes::render($md);
foreach(array('<h3>Version 2.3.7</h3>','<h4>Nouveautes</h4>','<ul>','<ol>','<strong>Important</strong>','<code>audit-medias/</code>','rel="noopener noreferrer"','&lt;script&gt;','<p>Texte normal.</p>') as $s)check(strpos($html,$s)!==false,'format '.strip_tags($s));
check(strpos($html,'<script>')===false,'code fence HTML escaped');
foreach(array('<img src=x onerror=alert(1)>','[x](javascript:alert(1))','**<svg onload=alert(1)>**','`<iframe>`') as $x){$r=AMF_Release_Notes::render($x);check(!preg_match('/<(img|svg|iframe|script)\b/i',$r),'raw HTML escaped');check(strpos($r,'href="javascript:')===false,'no unsafe link');}
check(strlen(AMF_Release_Notes::render(str_repeat('x',100000)))<21000,'bounded release notes');
check(strpos(AMF_Release_Notes::render(null),'Aucune note')!==false,'missing notes');
AMF_Presentation::register();
check(in_array('plugin_action_links_audit-medias/audit-medias.php',$hooks,true),'local plugin action hook');
check(in_array('network_admin_plugin_action_links_audit-medias/audit-medias.php',$hooks,true),'network action hook');
$links=AMF_Presentation::action_links(array('deactivate'=>'Deactivate','translate'=>'Translate'));
check(count($links)===3 && isset($links['amf_open'],$links['deactivate'],$links['translate']),'existing action links preserved');
$allowed=false;check(AMF_Presentation::action_links(array('deactivate'=>'x'))===array('deactivate'=>'x'),'link respects capability');
check(AMF_Presentation::decorate_updates(false)===false,'no update invented');
$other=(object)array('new_version'=>'9');$v=(object)array('response'=>array('other'=>$other));AMF_Presentation::decorate_updates($v);check(count($v->response)===1 && $v->response['other']===$other,'other offers untouched');
$v->response['audit-medias/audit-medias.php']=(object)array('new_version'=>'2.3.7');AMF_Presentation::decorate_updates($v);
check(isset($v->response['audit-medias/audit-medias.php']->icons['2x']),'icon attached only to existing own offer');
check(strpos(AMF_Presentation::icons()['2x'],'audit-medias-icon-256.png')!==false,'retina icon');
$f=array('usage'=>'','wc_usage'=>'absent','other_usage'=>'none','assoc'=>'unassociated');
$n=AMF_Media_Filters::normalize($f,false);check($n['usage']==='legacy' && $n['wc_usage']==='absent','old usage links retained visibly');
$f['usage']='used';$n=AMF_Media_Filters::normalize($f,true);check($n['wc_usage']==='' && $n['other_usage']==='' && $n['assoc']==='unassociated','new selection overrides legacy usage only');
$f['usage']='';$n=AMF_Media_Filters::normalize($f,true);check($n['wc_usage']==='' && $n['other_usage']==='','all usage truly resets usage constraints');
check(!isset(AMF_Media_Filters::options(false)['products']),'no product filter without WooCommerce');
foreach(array('unused','used','products','review') as $u){check(AMF_Media_Filters::condition($u,false,true,true)==='1=0','audit readiness '.$u);check(AMF_Media_Filters::condition($u,true,false,true)==='1=0','product readiness '.$u);}
$sql=AMF_Media_Filters::condition('unused',true,true,true);
foreach(array('w.wc_count,0)=0','o.other_count,0)=0','g.direct_usage_count,0)=0','g.ambiguous_count=0','g.owner_count<=1','g.min_confidence=100') as $s)check(strpos($sql,$s)!==false,'unused requires '.$s);
check(AMF_Media_Filters::condition('unused',true,false,false)===$sql,'site without WooCommerce remains usable');
check(AMF_Media_Filters::condition('products',true,true,false)==='1=0','product-only filter cannot invent matches');
check(AMF_Media_Filters::condition("unused' OR 1=1",true,true,true)==='','unknown filter adds no user SQL');
check(strpos(AMF_Media_Filters::condition('used',true,true,true),'g.direct_usage_count,0)>0')!==false,'used includes derivative URL references');
echo 'UI 2.3.7: '.$passes.' assertions passed'.PHP_EOL;
