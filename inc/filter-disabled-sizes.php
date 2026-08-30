<?php
/**
 * Disable selected image sizes for future uploads.
 *
 * @package Thumbnail_Manager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Apply the saved policy to one Core-provided size subset.
 *
 * The additional arguments deliberately remain unused so the same adapter can
 * serve both Core generation hooks without changing their composition rules.
 *
 * @param array $sizes Core/plugin-permitted image sizes.
 * @return array
 */
function yotm_filter_enabled_image_sizes( $sizes ) {
	return yotm_apply_enabled_size_policy( $sizes );
}

// Future uploads and Force regeneration use the Core generation-size surface.
add_filter( 'intermediate_image_sizes_advanced', 'yotm_filter_enabled_image_sizes', PHP_INT_MAX, 3 );

// Missing-only regeneration has a separate Core filter surface.
add_filter( 'wp_get_missing_image_subsizes', 'yotm_filter_enabled_image_sizes', PHP_INT_MAX, 3 );
