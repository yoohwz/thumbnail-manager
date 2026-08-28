<?php
/**
 * Media path and uploads-scope safety primitives.
 *
 * @package Thumbnail_Manager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Normalize a filesystem path, resolving it when the node exists.
 *
 * @param string $path Filesystem path.
 * @return string
 */
function yotm_normalize_filesystem_path( $path ) {
	$path = (string) $path;
	$real = realpath( $path );

	if ( false !== $real ) {
		return untrailingslashit( wp_normalize_path( $real ) );
	}

	return untrailingslashit( wp_normalize_path( $path ) );
}

/**
 * Determine whether a canonical path is inside a directory.
 *
 * @param string $path Candidate path.
 * @param string $dir Parent directory.
 * @return bool
 */
function yotm_is_path_inside_dir( $path, $dir ) {
	$path = yotm_normalize_filesystem_path( $path );
	$dir  = yotm_normalize_filesystem_path( $dir );

	return 0 === strpos( $path, trailingslashit( $dir ) );
}

/**
 * Sanitize one uploads-relative subpath.
 *
 * @param string $subpath Raw relative path.
 * @return string
 */
function yotm_clean_upload_subpath( $subpath ) {
	$subpath = trim( str_replace( '\\', '/', (string) $subpath ), '/' );
	$parts   = array();

	foreach ( explode( '/', $subpath ) as $part ) {
		$part = sanitize_file_name( $part );

		if ( '' === $part || '.' === $part || '..' === $part ) {
			continue;
		}

		$parts[] = $part;
	}

	return implode( '/', $parts );
}

/**
 * Resolve and validate one selected uploads scan root.
 *
 * @param string $base Uploads base directory.
 * @param string $subpath Selected uploads-relative path.
 * @return string|WP_Error Canonical directory with a trailing slash.
 */
function yotm_resolve_upload_scan_base( $base, $subpath ) {
	$base_real = realpath( $base );

	if ( false === $base_real || ! is_dir( $base_real ) ) {
		return new WP_Error( 'yotm_uploads_missing', __( 'Uploads folder not found.', 'thumbnail-manager' ) );
	}

	$base_real = yotm_normalize_filesystem_path( $base_real );
	$subpath   = yotm_clean_upload_subpath( $subpath );
	$target    = '' === $subpath ? $base_real : trailingslashit( $base_real ) . $subpath;
	$scan_real = realpath( $target );

	if ( false === $scan_real || ! is_dir( $scan_real ) ) {
		return new WP_Error( 'yotm_subfolder_missing', __( 'Subfolder not found.', 'thumbnail-manager' ) );
	}

	$scan_real = yotm_normalize_filesystem_path( $scan_real );

	if ( $scan_real !== $base_real && ! yotm_is_path_inside_dir( $scan_real, $base_real ) ) {
		return new WP_Error( 'yotm_subfolder_outside_uploads', __( 'Subfolder must be inside uploads.', 'thumbnail-manager' ) );
	}

	return trailingslashit( $scan_real );
}

/**
 * Normalize selected uploads subpaths and remove descendants already covered
 * by a selected parent directory.
 *
 * An empty result means the entire uploads directory.
 *
 * @param string|string[] $subpaths Selected relative uploads paths.
 * @return string[]
 */
function yotm_normalize_upload_subpaths( $subpaths ) {
	$subpaths = is_array( $subpaths ) ? $subpaths : array( $subpaths );
	$cleaned  = array();

	foreach ( $subpaths as $subpath ) {
		$subpath = yotm_clean_upload_subpath( $subpath );

		if ( '' === $subpath ) {
			return array();
		}

		$cleaned[ $subpath ] = $subpath;
	}

	$cleaned = array_values( $cleaned );
	usort(
		$cleaned,
		static function ( $left, $right ) {
			$left_depth  = substr_count( $left, '/' );
			$right_depth = substr_count( $right, '/' );

			if ( $left_depth === $right_depth ) {
				return strcmp( $left, $right );
			}

			return $left_depth <=> $right_depth;
		}
	);

	$normalized = array();
	foreach ( $cleaned as $candidate ) {
		$covered = false;
		foreach ( $normalized as $parent ) {
			if ( 0 === strpos( $candidate, trailingslashit( $parent ) ) ) {
				$covered = true;
				break;
			}
		}

		if ( ! $covered ) {
			$normalized[] = $candidate;
		}
	}

	return $normalized;
}

/**
 * Resolve multiple user-selected uploads subpaths to disjoint real paths.
 *
 * @param string          $base Uploads base directory.
 * @param string|string[] $subpaths Relative uploads paths; empty means all uploads.
 * @return string[]|WP_Error
 */
function yotm_resolve_upload_scan_bases( $base, $subpaths ) {
	$subpaths = yotm_normalize_upload_subpaths( $subpaths );

	if ( empty( $subpaths ) ) {
		$resolved = yotm_resolve_upload_scan_base( $base, '' );

		return is_wp_error( $resolved ) ? $resolved : array( $resolved );
	}

	$resolved = array();
	foreach ( $subpaths as $subpath ) {
		$scan_base = yotm_resolve_upload_scan_base( $base, $subpath );
		if ( is_wp_error( $scan_base ) ) {
			return new WP_Error(
				$scan_base->get_error_code(),
				sprintf(
					/* translators: 1: uploads subfolder, 2: validation error. */
					__( 'Could not use uploads/%1$s: %2$s', 'thumbnail-manager' ),
					$subpath,
					$scan_base->get_error_message()
				)
			);
		}

		$resolved[] = $scan_base;
	}

	return $resolved;
}

/**
 * Return whether a path is inside any selected scan directory.
 *
 * @param string          $path Path to test.
 * @param string|string[] $scan_bases Selected scan directories.
 * @return bool
 */
function yotm_is_path_inside_any_dir( $path, $scan_bases ) {
	foreach ( (array) $scan_bases as $scan_base ) {
		if ( yotm_is_path_inside_dir( $path, $scan_base ) ) {
			return true;
		}
	}

	return false;
}

/**
 * Find the selected scan root that contains a path.
 *
 * @param string          $path Path to test.
 * @param string|string[] $scan_bases Selected scan directories.
 * @return string
 */
function yotm_find_scan_root_for_path( $path, $scan_bases ) {
	$path = yotm_normalize_filesystem_path( $path );

	foreach ( (array) $scan_bases as $scan_base ) {
		$scan_base = trailingslashit( yotm_normalize_filesystem_path( $scan_base ) );
		if ( untrailingslashit( $scan_base ) === $path || yotm_is_path_inside_dir( $path, $scan_base ) ) {
			return $scan_base;
		}
	}

	return '';
}

/**
 * Resolve a path to a canonical, uploads-contained path.
 *
 * @param string $path Absolute or uploads-relative path.
 * @return string|WP_Error
 */
function yotm_media_source_canonical_path( $path ) {
	$uploads = wp_get_upload_dir();
	$base    = realpath( (string) ( $uploads['basedir'] ?? '' ) );
	$path    = str_replace( '\\', '/', (string) $path );

	if ( false === $base || '' === $path || false !== strpos( $path, "\0" ) ) {
		return new WP_Error( 'yotm_media_source_path_unresolved', __( 'An authoritative media path could not be resolved safely.', 'thumbnail-manager' ) );
	}

	$base = untrailingslashit( wp_normalize_path( $base ) );
	if ( ! preg_match( '#^(?:[A-Za-z]:)?/#', $path ) ) {
		$path = trailingslashit( $base ) . ltrim( $path, '/' );
	}

	$path = wp_normalize_path( $path );
	if ( is_link( $path ) ) {
		return new WP_Error( 'yotm_media_source_symlink', __( 'A symbolic-link media path cannot be used for destructive work.', 'thumbnail-manager' ) );
	}

	$real = realpath( $path );
	if ( false === $real ) {
		$parent = realpath( dirname( $path ) );
		if ( false === $parent || basename( $path ) !== wp_basename( $path ) ) {
			return new WP_Error( 'yotm_media_source_path_unresolved', __( 'An authoritative media path could not be resolved safely.', 'thumbnail-manager' ) );
		}
		$real = trailingslashit( wp_normalize_path( $parent ) ) . wp_basename( $path );
	}

	$real = untrailingslashit( wp_normalize_path( $real ) );
	if ( $real === $base || 0 !== strpos( $real, trailingslashit( $base ) ) ) {
		return new WP_Error( 'yotm_media_source_outside_uploads', __( 'An authoritative media path is outside uploads.', 'thumbnail-manager' ) );
	}

	return $real;
}
