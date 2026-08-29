<?php
/**
 * Media-owned source, attachment and canonical path locks.
 *
 * @package Thumbnail_Manager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once dirname( __DIR__ ) . '/infrastructure/database.php';
require_once __DIR__ . '/paths.php';

/**
 * Return a site-scoped named lock for a canonical media path.
 *
 * @param string $canonical_path Canonical path.
 * @return string
 */
function yotm_media_path_lock_name( $canonical_path ) {
	global $wpdb;

	$database = defined( 'DB_NAME' ) ? DB_NAME : 'WordPress';
	$scope    = $database . '|' . $wpdb->prefix . '|' . get_current_blog_id() . '|' . hash( 'sha256', (string) $canonical_path );

	return 'yotm_media_' . md5( $scope );
}

/**
 * Return the site-scoped source-mutation/delete fence name.
 *
 * @return string
 */
function yotm_media_source_fence_lock_name() {
	global $wpdb;

	$database = defined( 'DB_NAME' ) ? DB_NAME : 'WordPress';
	$scope    = $database . '|' . $wpdb->prefix . '|' . get_current_blog_id();

	return 'yotm_source_fence_' . md5( $scope );
}

/**
 * Acquire the re-entrant site-wide source-mutation/delete fence.
 *
 * @return array|WP_Error
 */
function yotm_media_source_fence_acquire() {
	$name = yotm_media_source_fence_lock_name();
	if ( ! isset( $GLOBALS['yotm_media_source_fence_locks'] ) || ! is_array( $GLOBALS['yotm_media_source_fence_locks'] ) ) {
		$GLOBALS['yotm_media_source_fence_locks'] = array();
		register_shutdown_function( 'yotm_media_source_shutdown_cleanup' );
	}

	if ( isset( $GLOBALS['yotm_media_source_fence_locks'][ $name ] ) ) {
		++$GLOBALS['yotm_media_source_fence_locks'][ $name ]['refs'];
		return array( 'name' => $name );
	}

	$acquired = yotm_database_acquire_named_lock( $name );
	if ( is_wp_error( $acquired ) ) {
		return new WP_Error( 'yotm_media_source_fence_failed', $acquired->get_error_message(), $acquired->get_error_data() );
	}
	if ( ! $acquired ) {
		return new WP_Error( 'yotm_media_source_fence_busy', __( 'Media sources are being changed by another request. Retrying shortly.', 'thumbnail-manager' ) );
	}

	$GLOBALS['yotm_media_source_fence_locks'][ $name ] = array(
		'name' => $name,
		'refs' => 1,
	);

	return array( 'name' => $name );
}

/**
 * Release one request-local site-wide source fence reference.
 *
 * @param array $handle Lock handle.
 * @return void
 */
function yotm_media_source_fence_release( $handle ) {
	$name = (string) ( $handle['name'] ?? '' );
	if ( '' === $name || empty( $GLOBALS['yotm_media_source_fence_locks'][ $name ] ) ) {
		return;
	}

	--$GLOBALS['yotm_media_source_fence_locks'][ $name ]['refs'];
	if ( $GLOBALS['yotm_media_source_fence_locks'][ $name ]['refs'] <= 0 ) {
		yotm_database_release_named_lock( $name );
		unset( $GLOBALS['yotm_media_source_fence_locks'][ $name ] );
	}
}

/**
 * Return a site-scoped named lock for one attachment source state.
 *
 * Lock ordering is job worker, site-wide source fence, attachment source state,
 * then sorted media paths. Delete workers use worker, source fence, then path.
 *
 * @param int $attachment_id Attachment ID.
 * @return string
 */
function yotm_media_attachment_lock_name( $attachment_id ) {
	global $wpdb;

	$database = defined( 'DB_NAME' ) ? DB_NAME : 'WordPress';
	$scope    = $database . '|' . $wpdb->prefix . '|' . get_current_blog_id() . '|' . absint( $attachment_id );

	return 'yotm_source_' . md5( $scope );
}

/**
 * Acquire one re-entrant request-local attachment source-state lock.
 *
 * @param int $attachment_id Attachment ID.
 * @return array|WP_Error
 */
function yotm_media_attachment_lock_acquire( $attachment_id ) {
	$attachment_id = absint( $attachment_id );
	if ( ! $attachment_id ) {
		return new WP_Error( 'yotm_media_attachment_invalid', __( 'Attachment source state could not be locked.', 'thumbnail-manager' ) );
	}

	$name = yotm_media_attachment_lock_name( $attachment_id );
	if ( ! isset( $GLOBALS['yotm_media_attachment_locks'] ) || ! is_array( $GLOBALS['yotm_media_attachment_locks'] ) ) {
		$GLOBALS['yotm_media_attachment_locks'] = array();
		register_shutdown_function( 'yotm_media_source_shutdown_cleanup' );
	}

	if ( isset( $GLOBALS['yotm_media_attachment_locks'][ $name ] ) ) {
		++$GLOBALS['yotm_media_attachment_locks'][ $name ]['refs'];
		return array(
			'name'          => $name,
			'attachment_id' => $attachment_id,
		);
	}

	$acquired = yotm_database_acquire_named_lock( $name );
	if ( is_wp_error( $acquired ) ) {
		return new WP_Error( 'yotm_media_attachment_lock_failed', $acquired->get_error_message(), $acquired->get_error_data() );
	}
	if ( ! $acquired ) {
		return new WP_Error( 'yotm_media_attachment_busy', __( 'This attachment source is being updated by another request. Retrying shortly.', 'thumbnail-manager' ) );
	}

	$GLOBALS['yotm_media_attachment_locks'][ $name ] = array(
		'name'          => $name,
		'attachment_id' => $attachment_id,
		'refs'          => 1,
	);

	return array(
		'name'          => $name,
		'attachment_id' => $attachment_id,
	);
}

/**
 * Release one request-local attachment lock reference.
 *
 * @param array $handle Lock handle.
 * @return void
 */
function yotm_media_attachment_lock_release( $handle ) {
	$name = (string) ( $handle['name'] ?? '' );
	if ( '' === $name || empty( $GLOBALS['yotm_media_attachment_locks'][ $name ] ) ) {
		return;
	}

	--$GLOBALS['yotm_media_attachment_locks'][ $name ]['refs'];
	if ( $GLOBALS['yotm_media_attachment_locks'][ $name ]['refs'] <= 0 ) {
		yotm_database_release_named_lock( $name );
		unset( $GLOBALS['yotm_media_attachment_locks'][ $name ] );
	}
}

/**
 * Acquire one re-entrant request-local media path lock.
 *
 * @param string $path Media path.
 * @return array|WP_Error
 */
function yotm_media_path_lock_acquire( $path ) {
	$canonical = yotm_media_source_canonical_path( $path );
	if ( is_wp_error( $canonical ) ) {
		return $canonical;
	}

	$name = yotm_media_path_lock_name( $canonical );
	if ( ! isset( $GLOBALS['yotm_media_path_locks'] ) || ! is_array( $GLOBALS['yotm_media_path_locks'] ) ) {
		$GLOBALS['yotm_media_path_locks'] = array();
		register_shutdown_function( 'yotm_media_source_shutdown_cleanup' );
	}

	if ( isset( $GLOBALS['yotm_media_path_locks'][ $name ] ) ) {
		++$GLOBALS['yotm_media_path_locks'][ $name ]['refs'];
		return array(
			'name' => $name,
			'path' => $canonical,
		);
	}

	$acquired = yotm_database_acquire_named_lock( $name );
	if ( is_wp_error( $acquired ) ) {
		return new WP_Error( 'yotm_media_path_lock_failed', $acquired->get_error_message(), $acquired->get_error_data() );
	}
	if ( ! $acquired ) {
		return new WP_Error( 'yotm_media_path_busy', __( 'This media path is being updated by another request. Retrying shortly.', 'thumbnail-manager' ) );
	}

	$GLOBALS['yotm_media_path_locks'][ $name ] = array(
		'name' => $name,
		'path' => $canonical,
		'refs' => 1,
	);

	return array(
		'name' => $name,
		'path' => $canonical,
	);
}

/**
 * Release one request-local media path lock reference.
 *
 * @param array $handle Lock handle.
 * @return void
 */
function yotm_media_path_lock_release( $handle ) {
	$name = (string) ( $handle['name'] ?? '' );
	if ( '' === $name || empty( $GLOBALS['yotm_media_path_locks'][ $name ] ) ) {
		return;
	}

	--$GLOBALS['yotm_media_path_locks'][ $name ]['refs'];
	if ( $GLOBALS['yotm_media_path_locks'][ $name ]['refs'] <= 0 ) {
		yotm_database_release_named_lock( $name );
		unset( $GLOBALS['yotm_media_path_locks'][ $name ] );
	}
}

/**
 * Acquire a deterministic set of media path locks.
 *
 * @param array[] $aliases Source aliases.
 * @return array[]|WP_Error
 */
function yotm_media_path_lock_aliases( $aliases ) {
	$paths = array();
	foreach ( (array) $aliases as $alias ) {
		$path = (string) ( $alias['path'] ?? '' );
		if ( '' !== $path ) {
			$paths[ hash( 'sha256', $path ) ] = $path;
		}
	}
	ksort( $paths );

	$handles = array();
	foreach ( $paths as $path ) {
		$handle = yotm_media_path_lock_acquire( $path );
		if ( is_wp_error( $handle ) ) {
			foreach ( array_reverse( $handles ) as $owned ) {
				yotm_media_path_lock_release( $owned );
			}
			return $handle;
		}
		$handles[] = $handle;
	}

	return $handles;
}
