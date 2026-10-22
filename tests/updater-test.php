<?php
/* Isolated WordPress/HTTP substitutes: no requests or installations. */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
error_reporting(E_ALL);
set_error_handler(function($l,$m,$f,$n){throw new RuntimeException($m.' at '.$f.':'.$n);});
define('ABSPATH','/fixture/');define('AMF_VERSION','2.3.5');
define('AMF_PLUGIN_FILE','/plugins/audit-medias/audit-medias.php');
define('MINUTE_IN_SECONDS',60);define('HOUR_IN_SECONDS',3600);
$GLOBALS['cache']=array();$GLOBALS['options']=array();$GLOBALS['filters']=array();$GLOBALS['http_count']=0;
$GLOBALS['allowed']=true;$GLOBALS['nonce_ok']=true;$GLOBALS['core_checks']=0;
$GLOBALS['basename']='audit-medias/audit-medias.php';
function add_filter($hook,...$a){$GLOBALS['filters'][]=$hook;}function add_action($hook,...$a){$GLOBALS['filters'][]=$hook;}
function plugin_basename($file){return $GLOBALS['basename'];}
function get_site_transient($key){return isset($GLOBALS['cache'][$key])?$GLOBALS['cache'][$key]:false;}
function set_site_transient($key,$v,$ttl=0){$GLOBALS['cache'][$key]=$v;$GLOBALS['ttls'][$key]=$ttl;}
function delete_site_transient($key){unset($GLOBALS['cache'][$key]);}
function get_site_option($key,$default=false){return isset($GLOBALS['options'][$key])?$GLOBALS['options'][$key]:$default;}
function update_site_option($key,$v){$GLOBALS['options'][$key]=$v;}
function wp_remote_get($url,$args){$GLOBALS['http_count']++;$GLOBALS['http_url']=$url;$GLOBALS['http_args']=$args;return $GLOBALS['response'];}
function wp_remote_retrieve_response_code($r){return $r['code'];}function wp_remote_retrieve_body($r){return $r['body'];}
function wp_remote_retrieve_header($r,$key){return isset($r['headers'][$key])?$r['headers'][$key]:'';}
class WP_Error {function get_error_code(){return 'http_request_failed';}function get_error_message(){return 'Sensitive /private/path?token=secret';}}
function is_wp_error($v){return $v instanceof WP_Error;}
function esc_html($v){return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}function esc_attr($v){return esc_html($v);}function esc_url($v){return esc_html($v);}function esc_html__($v,$d=''){return esc_html($v);}
function current_user_can($cap){return $GLOBALS['allowed'];}function wp_die($s){throw new RuntimeException('DENIED');}
function check_admin_referer($a){if(!$GLOBALS['nonce_ok'])throw new RuntimeException('NONCE');}
function admin_url($s){return '/wp-admin/'.$s;}function self_admin_url($s){return admin_url($s);}
function wp_nonce_field($a){echo '<input type="hidden" name="_wpnonce" value="test">';}
function wp_date($format,$timestamp){return gmdate($format,$timestamp);}
function wp_update_plugins(){$GLOBALS['core_checks']++;/* Simulate early WP.org failure. */}
function wp_safe_redirect($url){$GLOBALS['redirect']=$url;throw new RuntimeException('REDIRECT');}
function check($c,$name){if(!$c)throw new RuntimeException('FAIL '.$name);echo 'PASS '.$name.PHP_EOL;$GLOBALS['passes']++;}
$GLOBALS['passes']=0;
require __DIR__.'/../includes/class-amf-updates.php';
AMF_Updates::register();
check(in_array('update_plugins_github.com',$GLOBALS['filters'],true),'native filter registered outside admin-only code');
check(!in_array('auto_update_plugin',$GLOBALS['filters'],true),'automatic installation not enabled');
function release_fixture($version='2.3.6'){
 return array('tag_name'=>'v'.$version,'draft'=>false,'prerelease'=>false,'body'=>'Notes <script>alert(1)</script>',
 'assets'=>array(array('name'=>'audit-medias-v'.$version.'.zip','browser_download_url'=>'https://github.com/benoitfouc/audit-medias/releases/download/v'.$version.'/audit-medias-v'.$version.'.zip')));
}
$r=release_fixture();
$headers=array('UpdateURI'=>AMF_Updates::UPDATE_URI);
$valid=AMF_Updates::validate_release($r);check($valid && $valid['version']==='2.3.6','canonical stable asset accepted');
foreach(array('draft','prerelease') as $key){$bad=$r;$bad[$key]=true;check(false===AMF_Updates::validate_release($bad),'reject '.$key);}
foreach(array(null,'string',array(),array('tag_name'=>array(),'assets'=>array())) as $bad){check(false===AMF_Updates::validate_release($bad),'malformed JSON structure rejected');}
foreach(array('2.3.6','v2.3.6-beta','v2.3.6/path',array('invalid')) as $tag){$bad=$r;$bad['tag_name']=$tag;check(false===AMF_Updates::validate_release($bad),'unsafe/nonstable tag rejected');}
foreach(array('http://github.com/x.zip','https://evil.invalid/a.zip','https://github.com/benoitfouc/audit-medias/archive/refs/tags/v2.3.6.zip','https://github.com/benoitfouc/audit-medias/releases/download/v2.3.6/audit-medias-v2.3.6.zip?x=1') as $url){$bad=$r;$bad['assets'][0]['browser_download_url']=$url;check(false===AMF_Updates::validate_release($bad),'noncanonical download rejected');}
$GLOBALS['response']=array('code'=>200,'body'=>json_encode($r),'headers'=>array('x-ratelimit-remaining'=>'59'));
check('untouched'===AMF_Updates::check_update('untouched',$headers,'other/other.php',array()),'other plugin untouched');
check(0===$GLOBALS['http_count'],'no HTTP for unrelated plugin');
$u=AMF_Updates::check_update(false,$headers,$GLOBALS['basename'],array());
check('2.3.6'===$u['version'] && $u['package']===$r['assets'][0]['browser_download_url'],'valid newer proposal');
check(!isset($u['autoupdate']),'no forced auto-update flag');
check($GLOBALS['http_args']['limit_response_size']===524288 && !isset($GLOBALS['http_args']['sslverify']),'bounded request and TLS verification left enabled');
AMF_Updates::check_update(false,$headers,$GLOBALS['basename'],array());check($GLOBALS['http_count']===1,'positive cache reused');
check($GLOBALS['ttls'][AMF_Updates::CACHE_KEY]===21600,'positive cache TTL preserved');
$d=AMF_Updates::diagnostics();check($d['http']===200 && $d['rate_limit']['remaining']===59 && $d['detected_version']==='2.3.6','diagnostic records HTTP and quota');
check($d['checked_at']>0 && $d['cache_expires_at']>$d['checked_at'],'cache timestamps stored');
$before=$GLOBALS['http_count'];AMF_Updates::diagnostics();ob_start();AMF_Updates::render_settings();$html=ob_get_clean();check($GLOBALS['http_count']===$before,'opening diagnostics makes no request');
check(strpos($html,'Diagnostic')!==false && strpos($html,'name="_wpnonce"')!==false,'protected diagnostic UI rendered');
$info=AMF_Updates::plugin_information(false,'plugin_information',(object)array('slug'=>'audit-medias'));check(strpos($info->sections['changelog'],'<script>')===false,'changelog escaped');
foreach(array(array(429,'{}','rate_limited'),array(403,'{}','http_error'),array(404,'{}','no_release'),array(200,'<html>','invalid_json'),array(200,json_encode(array('tag_name'=>'v2.3.6','assets'=>array())),'missing_asset')) as $case){
 $GLOBALS['cache']=array();$GLOBALS['response']=array('code'=>$case[0],'body'=>$case[1]);
 check(false===AMF_Updates::check_update(false,$headers,$GLOBALS['basename'],array()),'no invented offer on '.$case[2]);
 check(AMF_Updates::diagnostics()['status']===$case[2],'diagnostic reason '.$case[2]);
 $count=$GLOBALS['http_count'];AMF_Updates::check_update(false,$headers,$GLOBALS['basename'],array());check($count===$GLOBALS['http_count'],'negative cache reused');
}
$GLOBALS['cache']=array();$GLOBALS['response']=new WP_Error();AMF_Updates::check_update(false,$headers,$GLOBALS['basename'],array());
$d=AMF_Updates::diagnostics();check($d['status']==='network_error' && $d['error_code']==='http_request_failed','network error distinguished');
check(strpos(json_encode($GLOBALS['options']),'secret')===false,'no raw error path or token recorded');
$GLOBALS['cache']=array();$GLOBALS['response']=array('code'=>403,'body'=>'{}','headers'=>array('x-ratelimit-remaining'=>'0','x-ratelimit-reset'=>(string)(time()+120)));
AMF_Updates::check_update(false,$headers,$GLOBALS['basename'],array());check(AMF_Updates::diagnostics()['status']==='rate_limited' && $GLOBALS['ttls'][AMF_Updates::CACHE_KEY]<=120,'GitHub shared-IP quota handled');
$GLOBALS['cache']=array(AMF_Updates::CACHE_KEY=>array('state'=>'ok','release'=>$valid));$before=$GLOBALS['http_count'];
check(AMF_Updates::diagnostics()['detected_version']==='2.3.6' && $before===$GLOBALS['http_count'],'legacy 2.3.3 cache still readable');
$GLOBALS['allowed']=false;try{AMF_Updates::refresh_updates();throw new RuntimeException('FAIL permission');}catch(RuntimeException $e){check($e->getMessage()==='DENIED','permission verified before refresh');}
$GLOBALS['allowed']=true;$GLOBALS['nonce_ok']=false;try{AMF_Updates::refresh_updates();throw new RuntimeException('FAIL nonce');}catch(RuntimeException $e){check($e->getMessage()==='NONCE','nonce verified before refresh');}
$GLOBALS['nonce_ok']=true;$GLOBALS['response']=array('code'=>200,'body'=>json_encode($r));
$other=(object)array('new_version'=>'9.9');$GLOBALS['cache']['update_plugins']=(object)array('last_checked'=>123,'response'=>array('other/other.php'=>$other),'translations'=>array('preserved'),'checked'=>array('other/other.php'=>'1.0'));
try{AMF_Updates::refresh_updates();}catch(RuntimeException $e){check($e->getMessage()==='REDIRECT','refresh returns to diagnostics');}
$w=$GLOBALS['cache']['update_plugins'];
check($w->response['audit-medias/audit-medias.php']->new_version==='2.3.6','manual refresh publishes offer despite simulated WP.org failure');
check($w->response['other/other.php']===$other && $w->translations===array('preserved'),'other plugins and translations preserved');
check($w->last_checked===0,'does not pretend global WordPress check succeeded');
check($GLOBALS['core_checks']===1,'native WordPress metadata check attempted');
$GLOBALS['response']=array('code'=>200,'body'=>json_encode(release_fixture('2.3.5')));
try{AMF_Updates::refresh_updates();}catch(RuntimeException $e){}
$w=$GLOBALS['cache']['update_plugins'];check(!isset($w->response[$GLOBALS['basename']]) && isset($w->no_update[$GLOBALS['basename']]),'same version is not offered');
$GLOBALS['basename']='audit-medias-2.3.5/audit-medias.php';check(false===AMF_Updates::check_update(false,$headers,$GLOBALS['basename'],array()),'duplicate folder does not get update');
check(!AMF_Updates::diagnostics()['canonical_path'],'incorrect folder visible in diagnostic');
$GLOBALS['basename']='audit-medias/audit-medias.php';
$GLOBALS['options'][AMF_Updates::DIAGNOSTIC_KEY]=array('status'=>'network_error','error_code'=>'<script>bad</script>');
ob_start();AMF_Updates::render_settings();$html=ob_get_clean();check(strpos($html,'<script>')===false,'diagnostic values escaped');
echo 'UPDATER: '.$GLOBALS['passes'].' checks passed'.PHP_EOL;
