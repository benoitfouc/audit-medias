<?php
/**
 * Plugin Name: Benfou - Diagnostic des mises a jour Audit Medias
 * Description: Verification temporaire du client deja installe, sans installer de mise a jour.
 * Version: 1.0.0
 * Author: Benfou
 * Author URI: https://www.benfou.fr/
 * Requires PHP: 7.4
 */
if (!defined('ABSPATH')) { exit; }
class Benfou_AMF_Update_Probe_235 {
 public static function register() {
  add_action('admin_menu',array(__CLASS__,'menu'));
  add_action('admin_post_bfamf_probe',array(__CLASS__,'run'));
 }
 public static function menu() { add_management_page('Diagnostic Audit Medias','Diagnostic Audit Medias','update_plugins','bfamf-probe',array(__CLASS__,'page')); }
 private static function allowed() { return current_user_can('manage_options') && current_user_can('update_plugins'); }
 public static function run() {
  if (!self::allowed()) { wp_die('Acces refuse.'); }
  check_admin_referer('bfamf_probe');
  $log=array();
  $observer=function($response,$context,$class,$args,$url) use (&$log) {
   $host=wp_parse_url($url,PHP_URL_HOST); $path=wp_parse_url($url,PHP_URL_PATH);
   if ('response'!==$context || !(('api.github.com'===$host && '/repos/benoitfouc/audit-medias/releases/latest'===$path) || ('api.wordpress.org'===$host && strpos($path,'/plugins/update-check/')===0))) { return; }
   $log[]=array('service'=>'api.github.com'===$host?'GitHub':'WordPress.org','result'=>is_wp_error($response)?$response->get_error_code():'HTTP '.(int)wp_remote_retrieve_response_code($response), 'quota'=>is_wp_error($response)?'':wp_remote_retrieve_header($response,'x-ratelimit-remaining'));
  };
  add_action('http_api_debug',$observer,10,5);
  delete_site_transient('amf_github_release_v1');
  wp_cache_delete('plugins','plugins');
  $current=get_site_transient('update_plugins');
  if (is_object($current)) { $current->last_checked=0;set_site_transient('update_plugins',$current); }
  wp_update_plugins();
  remove_action('http_api_debug',$observer,10);
  $updates=get_site_transient('update_plugins');$cache=get_site_transient('amf_github_release_v1');
  $file='audit-medias/audit-medias.php';
  $offered=is_object($updates)&&isset($updates->response[$file]->new_version)?$updates->response[$file]->new_version:'';
  $detected=is_array($cache)&&isset($cache['release']['version'])?$cache['release']['version']:'';
  set_transient('bfamf_probe_'.get_current_user_id(),array('time'=>time(),'requests'=>$log,'detected'=>$detected,'offered'=>$offered),10*MINUTE_IN_SECONDS);
  wp_safe_redirect(admin_url('tools.php?page=bfamf-probe'));exit;
 }
 public static function page() {
  if(!self::allowed()) { wp_die('Acces refuse.'); }
  if(!function_exists('get_plugins')) { require_once ABSPATH.'wp-admin/includes/plugin.php'; }
  echo '<div class="wrap"><h1>Diagnostic Audit Medias</h1><p>Cet outil efface uniquement les caches de verification et relance le mecanisme WordPress existant. Il ne fabrique aucune offre, ne modifie pas Audit Medias et n\'installe aucune mise a jour.</p>';
  echo '<h2>Copies installees</h2><ul>';
  foreach(get_plugins() as $file=>$data) { if(basename($file)!=='audit-medias.php')continue;
   echo '<li><code>'.esc_html($file).'</code> - version '.esc_html($data['Version']).' - '.(is_plugin_active($file)?'active':'inactive').' - Update URI : '.esc_html(isset($data['UpdateURI'])?$data['UpdateURI']:'absent').'</li>';
  }
  echo '</ul><form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="bfamf_probe">';wp_nonce_field('bfamf_probe');echo '<button class="button button-primary">Verifier avec le client actuel</button></form>';
  $r=get_transient('bfamf_probe_'.get_current_user_id());
  if(is_array($r)) {
   echo '<h2>Resultat</h2><p>'.esc_html(wp_date('d/m/Y H:i:s T',$r['time'])).'</p><ul>';
   foreach($r['requests'] as $v)echo '<li>'.esc_html($v['service'].' : '.$v['result'].($v['quota']!==''?' - quota restant : '.$v['quota']:'' )).'</li>';
   echo '</ul><p>Version dans le cache GitHub : <strong>'.esc_html($r['detected']?:'aucune').'</strong></p><p>Version reellement proposee par WordPress : <strong>'.esc_html($r['offered']?:'aucune').'</strong></p>';
   if(!$r['offered'])echo '<p>Conservez cette page pour le diagnostic. Une absence de proposition ne prouve pas que la version distante est absente.</p>';
  }
  echo '<p><a class="button" href="'.esc_url(self_admin_url('update-core.php')).'">Ouvrir les mises a jour WordPress</a></p><p>Apres le test, desactivez puis supprimez cette extension temporaire. Le rapport expire apres dix minutes.</p></div>';
 }
}
Benfou_AMF_Update_Probe_235::register();
