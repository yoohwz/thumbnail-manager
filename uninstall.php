<?php
/**
 * Thumbnail Manager uninstall entry point.
 *
 * @package Thumbnail_Manager
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

require_once __DIR__ . '/inc/data-lifecycle.php';

yotm_data_lifecycle_uninstall();
