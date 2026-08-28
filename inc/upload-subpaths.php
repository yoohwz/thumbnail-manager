<?php
/**
 * Upload subfolder discovery for admin selectors.
 *
 * @package Thumbnail_Manager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * List available year and month upload subpaths.
 *
 * @param string $base_dir Uploads base directory.
 * @return string[] Labels keyed by uploads-relative subpath.
 */
function yotm_list_upload_subpaths( $base_dir ) {
	$opts = array( '' => __( 'All uploads', 'thumbnail-manager' ) );
	if ( ! is_dir( $base_dir ) ) {
		return $opts;
	}

	$years = array();
	try {
		$it = new DirectoryIterator( $base_dir );
		foreach ( $it as $fi ) {
			if ( ! $fi->isDir() || $fi->isDot() ) {
				continue;
			}
			$name = $fi->getFilename();
			if ( preg_match( '/^\d{4}$/', $name ) ) {
				$years[] = $name;
			}
		}
	} catch ( Throwable $e ) {
		// Ignore an unreadable base and return only the default option.
		$years = array();
	}
	rsort( $years );
	foreach ( $years as $y ) {
		$opts[ $y ] = sprintf(
			/* translators: %s: four-digit uploads year. */
			__( '%s (year)', 'thumbnail-manager' ),
			$y
		);
		$year_path = trailingslashit( $base_dir ) . $y;
		try {
			$it2    = new DirectoryIterator( $year_path );
			$months = array();
			foreach ( $it2 as $fi2 ) {
				if ( ! $fi2->isDir() || $fi2->isDot() ) {
					continue;
				}
				$m = $fi2->getFilename();
				if ( preg_match( '/^(0[1-9]|1[0-2])$/', $m ) ) {
					$months[] = $m;
				}
			}
			sort( $months );
			foreach ( $months as $m ) {
				$year_month          = $y . '/' . $m;
				$opts[ $year_month ] = sprintf(
					/* translators: %s: year/month uploads path. */
					__( '— %s', 'thumbnail-manager' ),
					$year_month
				);
			}
		} catch ( Throwable $e ) {
			// Ignore unreadable entries and continue with the next year.
			continue;
		}//end try
	}//end foreach
	return $opts;
}
