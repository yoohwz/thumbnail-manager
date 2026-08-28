<?php
/**
 * Disable selected image sizes for future uploads.
 *
 * @package Thumbnail_Manager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Disable selected sizes for future uploads.
add_filter(
	'intermediate_image_sizes_advanced',
	function ( $sizes ) {
		$disabled = get_option( 'yotm_disabled_sizes', array() );

		if ( ! is_array( $disabled ) || empty( $disabled ) ) {
			return $sizes;
		}

		foreach ( $disabled as $name ) {
			unset( $sizes[ $name ] );
		}

		return $sizes;
	},
	9999
);
