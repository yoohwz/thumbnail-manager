<?php
/**
 * Compatibility loader for regeneration transaction globals.
 *
 * @package Thumbnail_Manager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/media/regeneration.php';
require_once __DIR__ . '/application/regenerate.php';
