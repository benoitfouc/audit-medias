<?php
/* Isolated tests: WordPress HTTP/cache functions are substituted, never networked. */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
error_reporting(E_ALL);
set_error_handler(function($l,$m,$f,$n){throw new RuntimeException($m.' at '.$f.':'.$n);});
define('ABSPATH','/fixture/');define('AMF_VERSION','2.3.3');define('AMF_PLUGIN_FILE','/plugins/audit-medias/audit-medias.php');
define('MINUTE_IN_SECONDS',60);define('HOUR_IN_SECONDS',3600);
$GLOBALS['cache']=array();$GLOBALS['http_count']=0;$GLOBALS['filters']=array();$GLOBALS['allowed']=true;$GLOBALS['nonce_ok']=true;
function add_filter($hook,...$a){$GLOBALS['filters'][]=$hook;} function add_action($hook,...$a){$GLOBALS['filters'][]=$hook;}
function plugin_basename($path){return 'audit-medias/audit-medias.php';}
function get_site_transient($key){return isset($GLOBALS['cache'][$key])?$GLOBALS['cache'][$key]:false;}
function set_site_transient($key,$v,$ttl){$GLOBALS['cache'][$key]=$v;$GLOBALS['ttl']=$ttl;}
function delete_site_transient($key){unset($GLOBALS['cache'][$key]);}
function wp_remote_get($url,$args){$GLOBALS['http_count']++;$GLOBALS['http_url']=$url;return $GLOBALS['response'];}
function wp_remote_retrieve_response_code($r){return $r['code'];} function wp_remote_retrieve_body($r){return $r['body'];}
class WP_Error {} function is_wp_error($v){return $v instanceof WP_Error;}
function esc_html($v){return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}function esc_url($v){return esc_html($v);}function esc_html__($v,$d=''){return esc_html($v);}
function current_user_can($cap){return $GLOBALS['allowed'];}function wp_die($s){throw new RuntimeException('DENIED');}
function check_admin_referer($a){if(!$GLOBALS['nonce_ok'])throw new RuntimeException('NONCE');}
function wp_update_plugins(){throw new RuntimeException('REQUESTED_REFRESH_ONLY');}
function check($condition,$name){if(!$condition){throw new RuntimeException('FAIL '.$name);}echo 'PASS '.$name.PHP_EOL;}
$source=isset($argv[1])?$argv[1]:__DIR__.'/../includes/class-amf-updates.php';require $source;
AMF_Updates::register();
check(in_array('update_plugins_github.com',$GLOBALS['filters'],true),'native Update URI filter');
check(!in_array('auto_update_plugin',$GLOBALS['filters'],true),'does not enable automatic installation');
$release=array('tag_name'=>'v2.3.4','draft'=>false,'prerelease'=>false,'body'=>'UX update <script>bad</script>','assets'=>array(array('name'=>'audit-medias-v2.3.4.zip','browser_download_url'=>'https://github.com/benoitfouc/audit-medias/releases/download/v2.3.4/audit-medias-v2.3.4.zip')));
$valid=AMF_Updates::validate_release($release);check($valid && $valid['version']==='2.3.4','valid v2.3.4 asset accepted');
foreach(array('draft','prerelease') as $k){$bad=$release;$bad[$k]=true;check(AMF_Updates::validate_release($bad)===false,'reject '.$k);}
foreach(array('2.3.4','v2.3.4-beta','v2.3.4/extra') as $tag){$bad=$release;$bad['tag_name']=$tag;check(AMF_Updates::validate_release($bad)===false,'reject tag '.$tag);}
$bad=$release;$bad['assets']=array();check(AMF_Updates::validate_release($bad)===false,'reject missing installable asset');
foreach(array('http://github.com/benoitfouc/audit-medias/releases/download/v2.3.4/audit-medias-v2.3.4.zip','https://example.invalid/audit-medias-v2.3.4.zip','https://github.com/benoitfouc/audit-medias/archive/refs/tags/v2.3.4.zip') as $url){$bad=$release;$bad['assets'][0]['browser_download_url']=$url;check(AMF_Updates::validate_release($bad)===false,'reject noncanonical asset URL');}
$headers=array('UpdateURI'=>AMF_Updates::UPDATE_URI);
$GLOBALS['response']=array('code'=>200,'body'=>json_encode($release));
check(AMF_Updates::check_update('untouched',$headers,'other/other.php',array())==='untouched','does not intercept other plugins');
check($GLOBALS['http_count']===0,'no request for other plugins');
$u=AMF_Updates::check_update(false,$headers,'audit-medias/audit-medias.php',array());
check($u['version']==='2.3.4' && version_compare($u['version'],AMF_VERSION,'>'),'installed 2.3.3 receives newer version metadata');
check($u['package']===$release['assets'][0]['browser_download_url'],'correct installable ZIP proposed');
check(!isset($u['autoupdate']),'no forced auto-update in response');
AMF_Updates::check_update(false,$headers,'audit-medias/audit-medias.php',array());check($GLOBALS['http_count']===1,'positive cache prevents duplicate HTTP request');
check($GLOBALS['ttl']===21600,'6-hour positive cache');
$info=AMF_Updates::plugin_information(false,'plugin_information',(object)array('slug'=>'audit-medias'));
check(strpos($info->sections['changelog'],'<script>')===false,'release description HTML escaped');
$GLOBALS['cache']=array();$GLOBALS['response']=array('code'=>429,'body'=>'{}');
check(AMF_Updates::check_update(false,$headers,'audit-medias/audit-medias.php',array())===false,'HTTP failure does not invent an update');
check($GLOBALS['ttl']===1800,'30-minute negative cache');
if(method_exists('AMF_Updates','refresh_updates')){
 $GLOBALS['allowed']=false;try{AMF_Updates::refresh_updates();throw new RuntimeException('FAIL no capability check');}catch(RuntimeException $e){check($e->getMessage()==='DENIED','manual refresh permission check');}
 $GLOBALS['allowed']=true;$GLOBALS['nonce_ok']=false;try{AMF_Updates::refresh_updates();throw new RuntimeException('FAIL no nonce');}catch(RuntimeException $e){check($e->getMessage()==='NONCE','manual refresh nonce check');}
 $GLOBALS['nonce_ok']=true;$GLOBALS['cache']=array(AMF_Updates::CACHE_KEY=>array('state'=>'unavailable'),'update_plugins'=>'cached');try{AMF_Updates::refresh_updates();}catch(RuntimeException $e){check($e->getMessage()==='REQUESTED_REFRESH_ONLY','manual refresh invokes metadata check');}
 check(!isset($GLOBALS['cache'][AMF_Updates::CACHE_KEY]) && !isset($GLOBALS['cache']['update_plugins']),'manual refresh clears only update caches');
}
echo 'UPDATER TESTS OK'.PHP_EOL;
