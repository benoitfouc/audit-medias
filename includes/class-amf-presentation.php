<?php
if (!defined('ABSPATH')) { exit; }

/** Plugin-list conveniences. Never creates an update offer or performs HTTP. */
class AMF_Presentation {
    public static function register() {
        $file=plugin_basename(AMF_PLUGIN_FILE);
        add_filter('plugin_action_links_'.$file,array(__CLASS__,'action_links'));
        add_filter('network_admin_plugin_action_links_'.$file,array(__CLASS__,'action_links'));
        add_filter('site_transient_update_plugins',array(__CLASS__,'decorate_updates'),20);
    }
    public static function action_links($links) {
        if (current_user_can('manage_options')) {
            $links=array_merge(array('amf_open'=>'<a href="'.esc_url(admin_url('tools.php?page=audit-medias')).'">Ouvrir Audit Médias</a>'),$links);
        }
        return $links;
    }
    public static function icons() {
        $root=plugin_dir_url(AMF_PLUGIN_FILE).'assets/brand/';
        return array('1x'=>$root.'audit-medias-icon-128.png','2x'=>$root.'audit-medias-icon-256.png');
    }
    public static function decorate_updates($value) {
        if (!is_object($value)) { return $value; }
        $file=plugin_basename(AMF_PLUGIN_FILE);
        foreach(array('response','no_update') as $key) {
            if (isset($value->$key[$file]) && is_object($value->$key[$file])) {
                $value->$key[$file]->icons=self::icons();
            }
        }
        return $value;
    }
}
