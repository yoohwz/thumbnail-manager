<?php
/**
 * Registered image-size Media primitives.
 *
 * @package Thumbnail_Manager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Return every registered image size definition.
 *
 * @return array
 */
function yotm_get_registered_sizes() {
	if ( function_exists( 'wp_get_registered_image_subsizes' ) ) {
		$sizes = wp_get_registered_image_subsizes();
	} else {
		global $_wp_additional_image_sizes;
		$sizes = array();
		foreach ( get_intermediate_image_sizes() as $s ) {
			if ( isset( $_wp_additional_image_sizes[ $s ] ) ) {
				$sizes[ $s ] = array(
					'width'  => (int) $_wp_additional_image_sizes[ $s ]['width'],
					'height' => (int) $_wp_additional_image_sizes[ $s ]['height'],
					'crop'   => (bool) $_wp_additional_image_sizes[ $s ]['crop'],
				);
			} else {
				$sizes[ $s ] = array(
					'width'  => (int) get_option( "{$s}_size_w" ),
					'height' => (int) get_option( "{$s}_size_h" ),
					'crop'   => (bool) get_option( "{$s}_crop" ),
				);
			}
		}
	}//end if
	foreach ( array( 'thumbnail', 'medium', 'medium_large', 'large', '1536x1536', '2048x2048' ) as $c ) {
		if ( ! isset( $sizes[ $c ] ) ) {
			$sizes[ $c ] = array(
				'width'  => (int) get_option( "{$c}_size_w", 0 ),
				'height' => (int) get_option( "{$c}_size_h", 0 ),
				'crop'   => (bool) get_option( "{$c}_crop", false ),
			);
		}
	}
	return $sizes;
}
