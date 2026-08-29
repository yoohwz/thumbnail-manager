<?php
/**
 * Thumbnail Manager administration compatibility bootstrap.
 *
 * @package Thumbnail_Manager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'YOTM_PLUGIN_URL' ) ) {
	define( 'YOTM_PLUGIN_URL', plugin_dir_url( __DIR__ ) );
}
if ( ! defined( 'YOTM_PLUGIN_PATH' ) ) {
	define( 'YOTM_PLUGIN_PATH', plugin_dir_path( __DIR__ ) );
}

require_once __DIR__ . '/admin/assets.php';
require_once __DIR__ . '/admin/page.php';
require_once __DIR__ . '/admin/view.php';

add_action( 'admin_enqueue_scripts', 'yotm_admin_enqueue_assets' );
add_action( 'admin_menu', 'yotm_register_admin_page' );

/**
 * Preserve the historical administration page callback.
 */
function yotm_manage_thumbnails_page() {
	yotm_admin_render_page();
}
