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
		$disabled = yotm_get_disabled_sizes_option( array() );

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
