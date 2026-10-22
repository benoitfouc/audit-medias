<?php
/* Isolated UI fixture, not a WordPress integration test. Never serve via HTTP. */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
error_reporting(E_ALL);
set_error_handler(function($level,$message,$file,$line){ throw new RuntimeException($message.' at '.$file.':'.$line); });
parse_str(isset($argv[1]) ? $argv[1] : '', $_GET);
define('ABSPATH', '/fixture/'); define('ARRAY_A','ARRAY_A');
define('AMF_VERSION','2.3.4'); define('AMF_PAGE_SLUG','audit-medias');
define('AMF_PLUGIN_URL','./'); define('AMF_PLUGIN_FILE',__DIR__.'/../audit-medias.php');
$GLOBALS['woo']=!isset($_GET['no_woo']);
$GLOBALS['allow_delete']=!isset($_GET['readonly']);
$GLOBALS['scan_current']=!isset($_GET['old_scan']);
$GLOBALS['scan']=isset($_GET['no_scan']) ? null : array('id'=>7,'status'=>isset($_GET['running']) ? 'running' : 'complete','phase'=>isset($_GET['running']) ? 'files' : 'complete','started_at'=>'2026-10-22 15:00:00','finished_at'=>'2026-10-22 15:10:00','message'=>'Audit complete');
function esc_html($v){return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');} function esc_attr($v){return esc_html($v);} function esc_url($v){return esc_html($v);} function esc_html__($v,$domain=''){return esc_html($v);}
function absint($v){return abs((int)$v);} function sanitize_key($v){return preg_replace('/[^a-z0-9_\-]/','',strtolower((string)$v));} function sanitize_text_field($v){return strip_tags((string)$v);} function wp_unslash($v){return $v;}
function current_user_can($cap,...$args){return !isset($_GET['forbidden']);}
function wp_die($v){throw new RuntimeException('Forbidden: '.$v);} function add_action(...$a){} function add_filter(...$a){}
function admin_url($v=''){return '/wp-admin/'.$v;} function self_admin_url($v=''){return admin_url($v);}
function wp_nonce_field($action){echo '<input type="hidden" name="_wpnonce" value="fixture-nonce">';}
function wp_create_nonce($action){return 'fixture-nonce';}
function number_format_i18n($v,$dec=0){return number_format((float)$v,$dec,',',' ');} function get_bloginfo($v=''){return 'fixture-WordPress';}
function disabled($a,$b=true,$echo=true){$s=$a==$b?' disabled="disabled"':''; if($echo)echo $s; return $s;}
function checked($a,$b=true,$echo=true){$s=$a==$b?' checked="checked"':''; if($echo)echo $s; return $s;}
function selected($a,$b=true,$echo=true){$s=(string)$a===(string)$b?' selected="selected"':''; if($echo)echo $s; return $s;}
function wp_basename($s){return basename($s);} function wp_normalize_path($p){return str_replace('\\','/',$p);}
function wp_parse_url($s){return parse_url($s);} function wp_json_encode($v){return json_encode($v,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);}
function is_wp_error($v){return $v instanceof WP_Error;} class WP_Error {private $m; function __construct($c,$m){$this->m=$m;} function get_error_message(){return $this->m;}}
function add_query_arg($args,$url){return $url.(strpos($url,'?')===false?'?':'&').http_build_query($args);}
function remove_query_arg($keys,$url=''){return $url;}
function paginate_links($args){return array('<span class="page-numbers current">1</span>','<a class="page-numbers" href="?paged=2">2</a>');}
function get_edit_post_link($id,$c=''){return '/edit/'.$id;} function wp_get_attachment_url($id){return './thumb.svg';}
function wp_get_attachment_image_src($id,$size=''){return array('./thumb.svg',60,60);}
function wp_get_attachment_image_url($id,$size=''){return './thumb.svg';} function wp_get_attachment_image($id,$size='',$icon=false,$attrs=array()){return '<img class="amf-thumb" src="./thumb.svg" alt="Fixture">';}
function get_post($id){return (object)array('ID'=>$id,'post_type'=>'attachment','post_parent'=>0,'post_title'=>'Fixture image');}
function get_post_type_object($s){return (object)array('labels'=>(object)array('singular_name'=>'Page'));}
function is_post_type_viewable($v){return true;}
function wp_upload_dir(){return array('baseurl'=>'/uploads','basedir'=>'/fixture/uploads');}
function size_format($v,$d=0){return number_format($v/1024,1).' Ko';}
class wpdb {public $last_error=''; public $prefix='wp_'; function prepare($sql,...$args){return $sql;} function get_var($sql){return 3;} function get_results($sql,$type=null){return array();} function get_row($sql,$type=null){return array('used'=>2,'no_usage'=>1,'wc_absent'=>1);}}
$GLOBALS['wpdb']=new wpdb();
class AMF_Database {function table($n){return 'wp_amf_'.$n;} function get_latest_scan(){return $GLOBALS['scan'];}}
class AMF_Scanner {function get_performance_settings(){return array_fill_keys(array('media_batch','posts_batch','postmeta_batch','termmeta_batch','options_batch','files_batch','woo_batch','request_delay_ms','time_budget_seconds'),20);} }
class AMF_Actions {function deletion_enabled(){return $GLOBALS['allow_delete'];}}
class AMF_Reference_Parser {static function scan_is_current($s){return (bool)$s && $GLOBALS['scan_current'];} static function shortcode_attribute_role($a,$b){return 'Image';}}
class AMF_Product_Usage {
 function available(){return $GLOBALS['woo'];}
 function status(){return array('available'=>$this->available(),'status'=>isset($_GET['stale'])?'stale':'complete','ready'=>!isset($_GET['stale']),'checked_at'=>'2026-10-22 15:10:00','message'=>'Product fixture','working'=>false);}
 function aggregate_sql(){return 'SELECT 1';} function references($ids){return array();}
 function source_link($id){return array('url'=>'/page','title'=>'Page exemple','state'=>'','variation'=>'');}
}
require __DIR__.'/../includes/class-amf-utils.php';
require __DIR__.'/../includes/class-amf-usage-view.php';
require __DIR__.'/../includes/class-amf-admin.php';
class Fixture_View extends AMF_Usage_View {
 function list_data($scan,$mode,$filters,$ready){
  if(isset($_GET['empty'])) return array('rows'=>array(),'total'=>0,'paged'=>1);
  $rows=array();
  foreach(array('Visuel principal','Image produit','Illustration article') as $i=>$title){
   $id=$i+1; $key=md5('attachment:'.$id);
   $rows[]=array('attachment_id'=>$id,'group_key'=>$key,'family_key'=>$key,'filename'=>isset($_GET['xss'])?'<script>alert(1)</script>':$title,'title'=>$title,'family_stem'=>'image-'.$id,'sample_rel_path'=>'2026/10/image-'.$id.'.webp','rel_path'=>'2026/10/image-'.$id.'.webp','max_confidence'=>100,'min_confidence'=>100,'ambiguous_count'=>0,'owner_count'=>1,'wc_count'=>0,'other_count'=>0,'direct_usage_count'=>0,'file_count'=>3,'total_bytes'=>45312,'file_exists'=>1,'sample_file_id'=>$id);
  }
  return array('rows'=>$rows,'total'=>3,'paged'=>1);
 }
 function other_references($a,$b,$c=array()){return array();}
 function other_aggregate_sql($id){return 'SELECT 1';}
}
$db=new AMF_Database();$scanner=new AMF_Scanner();$actions=new AMF_Actions();$products=new AMF_Product_Usage();
$admin=new AMF_Admin($db,$scanner,$actions,$products);
$prop=new ReflectionProperty(AMF_Admin::class,'usage_view');$prop->setAccessible(true);$prop->setValue($admin,new Fixture_View($db,$products));
ob_start();$admin->render_page();$html=ob_get_clean();
$status=array('exists'=>(bool)$GLOBALS['scan'],'id'=>7,'status'=>isset($_GET['running'])?'running':'complete','phase'=>'files','phase_total'=>100,'phase_current'=>100);
$data=array('ajaxUrl'=>'/fixture-ajax','nonce'=>'fixture-nonce','status'=>$status,'performance'=>array(),'presets'=>array(),'productIndex'=>$products->status(),'deletionEnabled'=>$actions->deletion_enabled(),'i18n'=>array('error'=>'Fixture error','confirmRestart'=>'Restart?','confirmDeleteFamily'=>'Delete?'));
echo '<!doctype html><html lang="fr"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Audit Medias - fixture UI</title><link rel="stylesheet" href="wp-fixture.css"><link rel="stylesheet" href="admin.css"><link rel="stylesheet" href="admin-tables.css"><link rel="stylesheet" href="admin-client.css"><body>'.$html.'<script src="jquery.js"></script><script>window.AMF_DATA='.json_encode($data).';</script><script src="admin.js"></script></body></html>';
