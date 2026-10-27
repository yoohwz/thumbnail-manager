<?php
/**
 * Optional/older optimizer behavior; run separately with that plugin skipped.
 *
 * Run: wp --skip-plugins=yoohw-media-optimizer eval-file tests/class-yoohw-media-optimizer.php standalone
 * Run: wp --skip-plugins=yoohw-media-optimizer eval-file tests/class-yoohw-media-optimizer.php legacy
 *
 * @package Thumbnail_Manager
 */

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	exit( "Run this file through WP-CLI.\n" );
}
if ( class_exists( 'YoOhw_Media_Optimizer' ) || ! function_exists( 'yotm_with_optimizer_guard' ) ) {
	WP_CLI::error( 'Run with Thumbnail Manager active and Media Optimizer skipped.' );
}
$yotm_optional_mode = $args[0] ?? 'standalone';
if ( 'legacy' === $yotm_optional_mode ) {
	/** Legacy counterpart without the versioned API. */
	class YoOhw_Media_Optimizer {}
}
$yotm_optional_called = false;
$yotm_optional_result = yotm_with_optimizer_guard(
	static function () use ( &$yotm_optional_called ) {
		$yotm_optional_called = true;
		return 'standalone-operation';
	}
);
if ( 'legacy' === $yotm_optional_mode ) {
	if ( ! is_wp_error( $yotm_optional_result ) || 'yotm_optimizer_update_required' !== $yotm_optional_result->get_error_code() || $yotm_optional_called ) {
		WP_CLI::error( 'Older active optimizer did not fail closed before the callback.' );
	}
} elseif ( 'standalone-operation' !== $yotm_optional_result || ! $yotm_optional_called ) {
	WP_CLI::error( 'Standalone execution changed.' );
}
if ( yotm_optimizer_mutation_active() ) {
	WP_CLI::error( 'Optional optimizer scope leaked.' );
}
WP_CLI::success( 'Optional optimizer compatibility: ' . $yotm_optional_mode );
