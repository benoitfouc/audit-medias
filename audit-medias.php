<?php
/**
 * Plugin Name: Audit Médias
 * Description: Audit, regroupement et suppression contrôlée des médias WordPress, WooCommerce et des fichiers physiques de wp-content/uploads.
 * Version: 2.3.10
 * Author: Benfou
 * Author URI: https://www.benfou.fr/
 * Plugin URI: https://github.com/benoitfouc/audit-medias
 * Update URI: https://github.com/benoitfouc/audit-medias
 * Requires at least: 6.2
 * Requires PHP: 7.4
 * Text Domain: audit-medias
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'AMF_VERSION', '2.3.10' );
define( 'AMF_PAGE_SLUG', 'audit-medias' );
define( 'AMF_PLUGIN_FILE', __FILE__ );
define( 'AMF_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'AMF_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once AMF_PLUGIN_DIR . 'includes/class-amf-database.php';
require_once AMF_PLUGIN_DIR . 'includes/class-amf-utils.php';
require_once AMF_PLUGIN_DIR . 'includes/class-amf-reference-parser.php';
require_once AMF_PLUGIN_DIR . 'includes/class-amf-file-scanner.php';
require_once AMF_PLUGIN_DIR . 'includes/class-amf-usage-scanner.php';
require_once AMF_PLUGIN_DIR . 'includes/class-amf-woocommerce-audit.php';
require_once AMF_PLUGIN_DIR . 'includes/class-amf-scanner.php';
require_once AMF_PLUGIN_DIR . 'includes/class-amf-actions.php';
require_once AMF_PLUGIN_DIR . 'includes/class-amf-product-usage.php';
require_once AMF_PLUGIN_DIR . 'includes/class-amf-usage-view.php';
require_once AMF_PLUGIN_DIR . 'includes/class-amf-admin.php';
require_once AMF_PLUGIN_DIR . 'includes/class-amf-updates.php';

function amf_activate_plugin() {
	$db = new AMF_Database();
	$db->install();
}
register_activation_hook( __FILE__, 'amf_activate_plugin' );

function amf_boot_plugin() {
	$db = new AMF_Database();
	$db->maybe_upgrade();

	$scanner = new AMF_Scanner( $db );
	$actions = new AMF_Actions( $db );
	$product_usage = new AMF_Product_Usage( $db );
	$product_usage->register_hooks();
	if ( is_admin() ) {
		new AMF_Admin( $db, $scanner, $actions, $product_usage );
	}
}
add_action( 'plugins_loaded', 'amf_boot_plugin', 20 );
AMF_Updates::register();
