<?php
/**
 * Narrow WordPress options adapter.
 *
 * @package Thumbnail_Manager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Read the saved disabled image-size names.
 *
 * @param mixed $fallback Value returned when the option does not exist.
 * @return mixed
 */
function yotm_get_disabled_sizes_option( $fallback = array() ) {
	return get_option( 'yotm_disabled_sizes', $fallback );
}

/**
 * Persist the disabled image-size names for future uploads.
 *
 * @param string[] $disabled Disabled registered-size names.
 * @return bool
 */
function yotm_update_disabled_sizes_option( $disabled ) {
	return update_option( 'yotm_disabled_sizes', array_values( $disabled ), true );
}
