<?php
/* Isolated regression tests. No real HTTP, database or installation. */
if (PHP_SAPI !== 'cli') { exit; }
error_reporting(E_ALL);
set_error_handler(function($l,$m,$f,$n){throw new RuntimeException($m.' at '.$f.':'.$n);});
define('ABSPATH','/fixture/'); define('AMF_VERSION','2.3.6');
define('AMF_PLUGIN_FILE','/plugins/audit-medias/audit-medias.php'); define('MINUTE_IN_SECONDS',60); define('HOUR_IN_SECONDS',3600);
$GLOBALS['cache']=array();$GLOBALS['options']=array();$GLOBALS['hooks']=array();$GLOBALS['requests']=array();$GLOBALS['ttls']=array();
$GLOBALS['allowed']=true;$GLOBALS['nonce_ok']=true;$GLOBALS['base']='audit-medias/audit-medias.php';$GLOBALS['checks']=0;
function add_filter($h,$c,$p=10,$a=1){$GLOBALS['hooks'][$h][]=$c;} function add_action($h,$c,$p=10,$a=1){add_filter($h,$c,$p,$a);}
function plugin_basename($p){return $GLOBALS['base'];}
function plugin_dir_url($p){return 'https://example.test/wp-content/plugins/audit-medias/';}
function get_site_transient($k){return isset($GLOBALS['cache'][$k])?$GLOBALS['cache'][$k]:false;}
function set_site_transient($k,$v,$ttl=0){$GLOBALS['cache'][$k]=$v;$GLOBALS['ttls'][$k]=$ttl;}
function delete_site_transient($k){unset($GLOBALS['cache'][$k]);}
function get_site_option($k,$d=false){return isset($GLOBALS['options'][$k])?$GLOBALS['options'][$k]:$d;}
function update_site_option($k,$v){$GLOBALS['options'][$k]=$v;}
function wp_remote_get($url,$args){$GLOBALS['requests'][]=array($url,$args);return array_shift($GLOBALS['responses']);}
function wp_remote_retrieve_response_code($r){return $r['code'];} function wp_remote_retrieve_body($r){return $r['body'];}
function wp_remote_retrieve_header($r,$k){return isset($r['headers'][$k])?$r['headers'][$k]:'';}
class WP_Error {public $code;public $message;function __construct($c='http_request_failed',$m='Sensitive /path?token=secret'){$this->code=$c;$this->message=$m;}function get_error_code(){return $this->code;}function get_error_message(){return $this->message;}}
function is_wp_error($v){return $v instanceof WP_Error;}
function esc_html($v){return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}function esc_attr($v){return esc_html($v);}function esc_url($v){return esc_html($v);}function esc_html__($v,$d=''){return esc_html($v);}
function sanitize_key($s){return preg_replace('/[^a-z0-9_\-]/','',strtolower($s));}
function current_user_can($c){return $GLOBALS['allowed'];}function wp_die($s){throw new RuntimeException('DENIED');}
function check_admin_referer($a){if(!$GLOBALS['nonce_ok'])throw new RuntimeException('NONCE');}
function admin_url($s){return '/wp-admin/'.$s;}function self_admin_url($s){return admin_url($s);}
function wp_nonce_field($s){echo '<input name="_wpnonce">';}function wp_date($f,$t){return gmdate($f,$t);}
function wp_update_plugins(){$GLOBALS['checks']++;} /* WordPress.org early return. */
function wp_safe_redirect($s){throw new RuntimeException('REDIRECT');}
function download_url($u,$t){$GLOBALS['downloads']++;return $GLOBALS['download_file'];}
$GLOBALS['downloads']=0;$GLOBALS['passes']=0;
function check($c,$name){if(!$c)throw new RuntimeException('FAIL '.$name);$GLOBALS['passes']++;echo 'PASS '.$name.PHP_EOL;}
function expect($fn,$code,$name){try{$fn();throw new RuntimeException('NOT_THROWN');}catch(RuntimeException $e){check($code===$e->getMessage(),$name);}}
function fixture($v='2.3.7') {return array('schema'=>1,'slug'=>'audit-medias','channel'=>'stable','version'=>$v,'tag'=>'v'.$v,'package'=>'https://github.com/benoitfouc/audit-medias/releases/download/v'.$v.'/audit-medias-v'.$v.'.zip','sha256'=>hash('sha256','zip fixture'),'size'=>strlen('zip fixture'),'requires'=>'6.2','requires_php'=>'7.4','description'=>'Notes <script>bad</script>');}
function response($data,$code=200,$headers=array()){return array('code'=>$code,'body'=>is_string($data)?$data:json_encode($data),'headers'=>$headers);}
function reset_http($responses){$GLOBALS['cache']=array();$GLOBALS['requests']=array();$GLOBALS['responses']=$responses;}
require __DIR__.'/../includes/class-amf-updates.php';
AMF_Updates::register();
check(isset($GLOBALS['hooks']['update_plugins_github.com']),'native hook registered');
check(isset($GLOBALS['hooks']['upgrader_pre_download']),'checksum hook registered');
check(!isset($GLOBALS['hooks']['auto_update_plugin']),'no forced automatic installation');
$r=fixture();check(AMF_Static_Manifest::validate($r)===$r,'canonical manifest accepted');
foreach(array(null,42,'x',array(),array('schema'=>1)) as $bad)check(false===AMF_Static_Manifest::validate($bad),'reject malformed structure');
foreach(array('schema'=>2,'slug'=>'other','channel'=>'beta','version'=>'02.3.7','tag'=>'v2.3.8','sha256'=>'xxx','size'=>'11','requires'=>array(),'requires_php'=>'abc','draft'=>true,'prerelease'=>true) as $k=>$v){$b=$r;$b[$k]=$v;check(false===AMF_Static_Manifest::validate($b),'reject invalid '.$k);}
foreach(array('2.3.7-beta','../x','2.3','2.3.7/evil',array()) as $v){$b=$r;$b['version']=$v;check(false===AMF_Static_Manifest::validate($b),'reject version variant');}
foreach(array('http://github.com/a.zip','https://evil.test/a.zip',$r['package'].'?secret=x',str_replace('/releases/download/','/archive/',$r['package'])) as $url){$b=$r;$b['package']=$url;check(false===AMF_Static_Manifest::validate($b),'reject foreign or source ZIP URL');}
$h=array('UpdateURI'=>AMF_Updates::UPDATE_URI);
reset_http(array(response($r)));
check(AMF_Updates::check_update('same',$h,'other/other.php',array())==='same' && !$GLOBALS['requests'],'unrelated plugin untouched');
$u=AMF_Updates::check_update(false,$h,AMF_Static_Manifest::FILE,array());
check($u['version']==='2.3.7' && $u['package']===$r['package'],'native update offers real newer release');
check(!isset($u['autoupdate']),'no autoupdate override');
check($GLOBALS['requests'][0][0]===AMF_Static_Manifest::SOURCE,'primary endpoint is static');
$args=$GLOBALS['requests'][0][1];check($args['sslverify'] && $args['reject_unsafe_urls'] && $args['limit_response_size']===131073,'TLS and bounded response');
check($GLOBALS['ttls'][AMF_Static_Manifest::CACHE]===3600,'one-hour positive cache');
AMF_Static_Manifest::fetch();check(count($GLOBALS['requests'])===1,'positive cache reused');
$d=AMF_Updates::diagnostics();check($d['detected_version']==='2.3.7' && $d['http']===200,'diagnostics show real cached version');
ob_start();AMF_Updates::render_settings();$html=ob_get_clean();check(count($GLOBALS['requests'])===1 && strpos($html,'_wpnonce')!==false,'settings read-only and protected');
$info=AMF_Updates::plugin_information(false,'plugin_information',(object)array('slug'=>'audit-medias'));
check(strpos($info->sections['changelog'],'<script>')===false,'HTML in release notes escaped');
reset_http(array(response('{}',404),response($r)));
check(AMF_Static_Manifest::fetch()['version']==='2.3.7','fallback works after primary failure');
check($GLOBALS['requests'][1][0]===AMF_Static_Manifest::FALLBACK,'fallback is release asset not REST');
check(count(AMF_Updates::diagnostics()['attempts'])===2,'both transport attempts recorded');
foreach(array(response('bad'),response(str_repeat('x',131073)),response('{}',404),response('{}',403),response('{}',500),new WP_Error()) as $fail){
 reset_http(array($fail,$fail));check(AMF_Static_Manifest::fetch()===false,'fail closed for invalid/unavailable sources');
 $count=count($GLOBALS['requests']);AMF_Static_Manifest::fetch();check(count($GLOBALS['requests'])===$count,'negative cache reused');
 check($GLOBALS['ttls'][AMF_Static_Manifest::CACHE]===600,'ten-minute negative cache');
}
check(strpos(json_encode($GLOBALS['options']),'secret')===false,'no sensitive error messages stored');
reset_http(array(response('{}',429,array('retry-after'=>'7200')),response('{}',429,array('retry-after'=>'7200'))));AMF_Static_Manifest::fetch();check($GLOBALS['ttls'][AMF_Static_Manifest::CACHE]===7200,'Retry-After honored');
reset_http(array(response($r)));$GLOBALS['cache']['amf_github_release_v1']=array('state'=>'unavailable');check(AMF_Static_Manifest::fetch()['version']==='2.3.7','old exhausted API cache ignored');
reset_http(array(response($r)));$GLOBALS['cache'][AMF_Static_Manifest::CACHE]=array('state'=>'ok','release'=>$r,'expires_at'=>time()-1);AMF_Static_Manifest::fetch();check(count($GLOBALS['requests'])===1,'expired cache not trusted');
reset_http(array(response($r)));$bad=$r;$bad['package']='https://evil.test/a.zip';$GLOBALS['cache'][AMF_Static_Manifest::CACHE]=array('state'=>'ok','release'=>$bad,'expires_at'=>time()+100);check(AMF_Static_Manifest::fetch()['package']===$r['package'],'cached metadata revalidated');
reset_http(array());$GLOBALS['cache'][AMF_Static_Manifest::LOCK]=1;check(!AMF_Static_Manifest::fetch() && !$GLOBALS['requests'],'short request lock avoids parallel polling');
reset_http(array(response($r)));
$other=(object)array('new_version'=>'10');$GLOBALS['cache']['update_plugins']=(object)array('last_checked'=>123,'response'=>array('other/other.php'=>$other),'translations'=>array('keep'),'checked'=>array('other/other.php'=>'1'));
AMF_Static_Manifest::sync_wordpress($r,'2.3.3');$w=$GLOBALS['cache']['update_plugins'];
check($w->response[AMF_Static_Manifest::FILE]->new_version==='2.3.7','transition merges authenticated-by-TLS metadata for 2.3.3');
check($w->response['other/other.php']===$other && $w->translations===array('keep') && $w->last_checked===123,'other offers and global timestamp preserved');
AMF_Static_Manifest::sync_wordpress($r,'2.3.7');check(!isset($w->response[AMF_Static_Manifest::FILE]) && isset($w->no_update[AMF_Static_Manifest::FILE]),'same version not offered');
AMF_Static_Manifest::sync_wordpress($r,'2.3.8');check(!isset($w->response[AMF_Static_Manifest::FILE]),'no downgrade');
$GLOBALS['allowed']=false;expect(function(){AMF_Updates::refresh_updates();},'DENIED','refresh capability required');
$GLOBALS['allowed']=true;$GLOBALS['nonce_ok']=false;expect(function(){AMF_Updates::refresh_updates();},'NONCE','refresh nonce required');
$GLOBALS['nonce_ok']=true;$GLOBALS['responses']=array(response($r));expect(function(){AMF_Updates::refresh_updates();},'REDIRECT','manual check returns safely');
check($GLOBALS['cache']['update_plugins']->response[AMF_Static_Manifest::FILE]->new_version==='2.3.7','manual check works when WP.org returns early');
check($GLOBALS['cache']['update_plugins']->response['other/other.php']===$other,'refresh preserves unrelated updates');
$GLOBALS['base']='audit-medias-2.3.6/audit-medias.php';check(false===AMF_Updates::check_update(false,$h,$GLOBALS['base'],array()),'duplicate path blocked');$GLOBALS['base']=AMF_Static_Manifest::FILE;
$tmp=tempnam(sys_get_temp_dir(),'amf-test-');file_put_contents($tmp,'zip fixture');
check(AMF_Static_Manifest::verify_package($tmp,$r),'correct checksum and size accepted');
$GLOBALS['download_file']=$tmp;
$reply=AMF_Static_Manifest::pre_download(false,$r['package'],null,array('plugin'=>AMF_Static_Manifest::FILE));check($reply===$tmp && $GLOBALS['downloads']===1,'native package intercepted and verified');
check(AMF_Static_Manifest::pre_download(false,'https://example.test/other.zip',null)===false,'other package not intercepted');
$err=new WP_Error('other');check(AMF_Static_Manifest::pre_download($err,$r['package'],null)===$err,'previous error preserved');
file_put_contents($tmp,'ZIP fixture');check(!AMF_Static_Manifest::verify_package($tmp,$r),'same-size tampering rejected');
$x=AMF_Static_Manifest::pre_download(false,$r['package'],null);check(is_wp_error($x) && $x->get_error_code()==='amf_checksum_mismatch' && !file_exists($tmp),'bad temporary download removed before extraction');
check(AMF_Static_Manifest::pre_download(false,$r['package'],null,array('plugin'=>'other/other.php'))===false,'explicit other plugin not touched');
foreach($GLOBALS['requests'] as $request)check(strpos($request[0],'api.github.com')===false,'no REST API HTTP call');
echo 'UPDATER: '.$GLOBALS['passes'].' assertions passed'.PHP_EOL;
