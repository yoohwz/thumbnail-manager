<?php
/**
 * Plugin Name: Thumbnail Manager
 * Plugin URI:  https://wordpress.org/plugins/thumbnail-manager
 * Description: Clean, control, and regenerate thumbnails with precision - remove unused sizes, prevent bloat, and rebuild what matters.
 * Version:     1.3
 * Author:      YoOhw.com
 * Author URI:  https://yoohw.com
 * License:     GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Requires at least: 6.3
 * Requires PHP:      7.4
 * Text Domain: thumbnail-manager
 * Domain Path: /languages
 */

if (!defined('ABSPATH')) {
	exit;
}

if ( ! defined( 'YOTM_VERSION' ) ) {
	define( 'YOTM_VERSION', '1.3' );
}

class Yo_Thumbnail_Manager {

	public function __construct() {
		$this->include_files();
	}

	private function include_files() {
		include_once plugin_dir_path(__FILE__) . 'inc/admin-menu.php';
		include_once plugin_dir_path(__FILE__) . 'inc/handle-regenerate.php';
		include_once plugin_dir_path(__FILE__) . 'inc/handle-recommendations.php';
		include_once plugin_dir_path(__FILE__) . 'inc/handle-prune.php';
		include_once plugin_dir_path(__FILE__) . 'inc/handle-delete.php';
        include_once plugin_dir_path(__FILE__) . 'inc/state-storage.php';
		include_once plugin_dir_path(__FILE__) . 'inc/filter-disabled-sizes.php';
		include_once plugin_dir_path(__FILE__) . 'inc/sizes-patterns.php';
        include_once plugin_dir_path(__FILE__) . 'inc/upload-subpaths.php';
		include_once plugin_dir_path(__FILE__) . 'inc/helper.php';
	}
}

new Yo_Thumbnail_Manager();
