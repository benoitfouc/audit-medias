<?php
if (PHP_SAPI !== 'cli') { exit; }
error_reporting(E_ALL);
set_error_handler(function($l,$m,$f,$n){throw new RuntimeException($m.' at '.$f.':'.$n);});
define('ABSPATH','/fixture/'); define('AMF_VERSION',isset($argv[1])?$argv[1]:'2.3.3');
define('AMF_PLUGIN_FILE',isset($argv[2])?$argv[2]:'audit-medias/audit-medias.php');
$GLOBALS['hooks']=array();$GLOBALS['cache']=array();$GLOBALS['options']=array();$GLOBALS['requests']=array();
$GLOBALS['allowed']=true;$GLOBALS['nonce']=true;$GLOBALS['passes']=0;
function check($x,$n){if(!$x)throw new RuntimeException('FAIL '.$n);$GLOBALS['passes']++;echo 'PASS '.$n.PHP_EOL;}
function add_filter($h,$c,$p=10,$a=1){$GLOBALS['hooks'][$h][$p][]=$c;}function add_action($h,$c,$p=10,$a=1){add_filter($h,$c,$p,$a);}
function has_filter($h,$c){foreach(isset($GLOBALS['hooks'][$h])?$GLOBALS['hooks'][$h]:array() as $p=>$callbacks)if(in_array($c,$callbacks,true))return $p;return false;}
function remove_filter($h,$c,$p){foreach($GLOBALS['hooks'][$h][$p] as $k=>$v)if($v===$c)unset($GLOBALS['hooks'][$h][$p][$k]);}
function plugin_basename($p){return $p;}
function get_site_transient($k){return isset($GLOBALS['cache'][$k])?$GLOBALS['cache'][$k]:false;}
function set_site_transient($k,$v,$t=0){$GLOBALS['cache'][$k]=$v;}function delete_site_transient($k){unset($GLOBALS['cache'][$k]);}
function get_site_option($k,$d=false){return isset($GLOBALS['options'][$k])?$GLOBALS['options'][$k]:$d;}function update_site_option($k,$v){$GLOBALS['options'][$k]=$v;}
function wp_remote_get($url,$args){$GLOBALS['requests'][]=$url;return array('code'=>200,'body'=>json_encode($GLOBALS['manifest']));}
function wp_remote_retrieve_response_code($r){return $r['code'];}function wp_remote_retrieve_body($r){return $r['body'];}function wp_remote_retrieve_header($r,$h){return '';}
class WP_Error {} function is_wp_error($v){return $v instanceof WP_Error;}
function esc_html($v){return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}function esc_url($v){return esc_html($v);}
function current_user_can($c){return $GLOBALS['allowed'];}function wp_die($s){throw new RuntimeException('DENIED');}function check_admin_referer($a){if(!$GLOBALS['nonce'])throw new RuntimeException('NONCE');}
function admin_url($s){return '/wp-admin/'.$s;}function self_admin_url($s){return admin_url($s);}function wp_nonce_field($s){echo '<input name="_wpnonce">';}
function wp_safe_redirect($s){throw new RuntimeException('REDIRECT');}
function wp_update_plugins(){
    $h='update_plugins_github.com';$v=false;
    foreach($GLOBALS['hooks'][$h] as $cs)foreach($cs as $c)$v=call_user_func($c,$v,array('UpdateURI'=>'https://github.com/benoitfouc/audit-medias'),'audit-medias/audit-medias.php',array());
    $GLOBALS['native_result']=$v;
}
class AMF_Updates {
 static function check_update(){throw new RuntimeException('OLD_API_CALLED');}
 static function plugin_information(){throw new RuntimeException('OLD_API_INFO_CALLED');}
}
add_filter('update_plugins_github.com',array('AMF_Updates','check_update'),10,4);
add_filter('plugins_api',array('AMF_Updates','plugin_information'),10,3);
$other=function($v){return $v;};add_filter('update_plugins_github.com',$other,20,4);
require __DIR__.'/../includes/class-amf-static-manifest.php';
require __DIR__.'/../tools/update-transition/benfou-amf-transition.php';
Benfou_AMF_Transition_236::boot();
$eligible=version_compare(AMF_VERSION,'2.3.3','>=') && version_compare(AMF_VERSION,'2.3.6','<') && AMF_PLUGIN_FILE===AMF_Static_Manifest::FILE;
check(Benfou_AMF_Transition_236::eligible()===$eligible,'eligibility precisely bounded');
if(!$eligible){check(false!==has_filter('update_plugins_github.com',array('AMF_Updates','check_update')),'native new client untouched');check(false===has_filter('update_plugins_github.com',array('Benfou_AMF_Transition_236','check')),'transition inert after update / wrong folder');echo 'TRANSITION: '.$GLOBALS['passes'].' checks passed'.PHP_EOL;exit;}
check(false===has_filter('update_plugins_github.com',array('AMF_Updates','check_update')),'old quota-limited call removed');
check(false===has_filter('plugins_api',array('AMF_Updates','plugin_information')),'old modal call removed');
check(false!==has_filter('update_plugins_github.com',$other),'other GitHub client untouched');
check(!isset($GLOBALS['hooks']['auto_update_plugin']),'automatic installation unchanged');
$GLOBALS['manifest']=array('schema'=>1,'slug'=>'audit-medias','channel'=>'stable','version'=>'2.3.6','tag'=>'v2.3.6','package'=>AMF_Static_Manifest::URI.'/releases/download/v2.3.6/audit-medias-v2.3.6.zip','sha256'=>str_repeat('a',64),'size'=>123,'requires'=>'6.2','requires_php'=>'7.4');
$GLOBALS['allowed']=false;try{Benfou_AMF_Transition_236::run();}catch(RuntimeException $e){check($e->getMessage()==='DENIED','capability required');}
$GLOBALS['allowed']=true;$GLOBALS['nonce']=false;try{Benfou_AMF_Transition_236::run();}catch(RuntimeException $e){check($e->getMessage()==='NONCE','nonce required');}
check(!$GLOBALS['requests'],'no network before permissions');
$GLOBALS['nonce']=true;$other=(object)array('new_version'=>'99');$GLOBALS['cache']['update_plugins']=(object)array('response'=>array('other.php'=>$other),'last_checked'=>123);
try{Benfou_AMF_Transition_236::run();}catch(RuntimeException $e){check($e->getMessage()==='REDIRECT','returns to transition screen');}
check($GLOBALS['native_result']['version']==='2.3.6','native WordPress hook returns verified 2.3.6');
check($GLOBALS['cache']['update_plugins']->response[AMF_Static_Manifest::FILE]->new_version==='2.3.6','real WordPress proposal stored');
check($GLOBALS['cache']['update_plugins']->response['other.php']===$other,'other updates preserved');
check(count($GLOBALS['requests'])===1 && strpos($GLOBALS['requests'][0],'api.github.com')===false,'one static request zero API calls');
ob_start();Benfou_AMF_Transition_236::page();$html=ob_get_clean();check(strpos($html,'2.3.6')!==false && strpos($html,'_wpnonce')!==false,'result and secure button displayed');
echo 'TRANSITION: '.$GLOBALS['passes'].' checks passed'.PHP_EOL;
