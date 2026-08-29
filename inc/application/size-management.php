<?php
/**
 * Size Management application use case.
 *
 * @package Thumbnail_Manager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Persist one mapped Size Management decision.
 *
 * Persisted disabled names are always derived from the authoritative current
 * registered-size set. The submitted count remains the legacy presentation
 * count, including duplicate or unknown mapped values.
 *
 * @param string[] $enabled                     Mapped submitted enabled names.
 * @param bool     $run_regenerate_after_save Whether the explicit follow-on was requested.
 * @return array{success:bool,data:array{enabled_count:int,disabled_count:int,disabled:string[],run_regenerate_after_save:bool},status:int}
 */
function yotm_size_management_save_application( $enabled, $run_regenerate_after_save = false ) {
	$enabled    = is_array( $enabled ) ? array_values( $enabled ) : array();
	$registered = array_keys( yotm_get_registered_sizes() );
	$disabled   = array_values( array_diff( $registered, $enabled ) );

	yotm_update_disabled_sizes_option( $disabled );

	return array(
		'success' => true,
		'data'    => array(
			'enabled_count'             => count( $enabled ),
			'disabled_count'            => count( $disabled ),
			'disabled'                  => $disabled,
			'run_regenerate_after_save' => (bool) $run_regenerate_after_save,
		),
		'status'  => 200,
	);
}
