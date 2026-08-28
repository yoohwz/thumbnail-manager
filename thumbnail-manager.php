<?php
/**
 * Plugin Name: Thumbnail Manager
 * Plugin URI:  https://yoohw.com/product/thumbnail-manager/
 * Description: Clean, control, and regenerate thumbnails with precision - remove unused sizes, prevent bloat, and rebuild what matters.
 * Version:     1.4.0
 * Author:      YoOhw.com
 * Author URI:  https://yoohw.com
 * License:     GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Requires at least: 6.3
 * Requires PHP:      7.4
 * Text Domain: thumbnail-manager
 * Domain Path: /languages
 *
 * @package Thumbnail_Manager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'YOTM_VERSION' ) ) {
	define( 'YOTM_VERSION', '1.4.0' );
}

/**
 * Bootstrap the plugin modules.
 */
class Yo_Thumbnail_Manager {

	/**
	 * Load the plugin on construction.
	 */
	public function __construct() {
		$this->include_files();
	}

	/**
	 * Include the plugin modules in dependency order.
	 */
	private function include_files() {
		include_once plugin_dir_path( __FILE__ ) . 'inc/job-storage.php';
		include_once plugin_dir_path( __FILE__ ) . 'inc/media/paths.php';
		include_once plugin_dir_path( __FILE__ ) . 'inc/media/attachments.php';
		include_once plugin_dir_path( __FILE__ ) . 'inc/media/registered-sizes.php';
		include_once plugin_dir_path( __FILE__ ) . 'inc/helper.php';
		include_once plugin_dir_path( __FILE__ ) . 'inc/sizes-patterns.php';
		include_once plugin_dir_path( __FILE__ ) . 'inc/upload-subpaths.php';
		include_once plugin_dir_path( __FILE__ ) . 'inc/media-source-index.php';
		include_once plugin_dir_path( __FILE__ ) . 'inc/admin-menu.php';
		include_once plugin_dir_path( __FILE__ ) . 'inc/regenerate-transaction.php';
		include_once plugin_dir_path( __FILE__ ) . 'inc/handle-regenerate.php';
		include_once plugin_dir_path( __FILE__ ) . 'inc/handle-recommendations.php';
		include_once plugin_dir_path( __FILE__ ) . 'inc/handle-prune.php';
		include_once plugin_dir_path( __FILE__ ) . 'inc/handle-delete.php';
		include_once plugin_dir_path( __FILE__ ) . 'inc/filter-disabled-sizes.php';
	}
}

new Yo_Thumbnail_Manager();

register_activation_hook( __FILE__, 'yotm_activate_job_storage' );
register_deactivation_hook( __FILE__, 'yotm_deactivate_job_cleanup' );
