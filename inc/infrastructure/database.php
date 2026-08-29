<?php
/**
 * Shared database primitives used by sibling Jobs and Media modules.
 *
 * @package Thumbnail_Manager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Return one current-site plugin table name.
 *
 * @param string $suffix Fixed plugin table suffix.
 * @return string
 */
function yotm_database_table_name( $suffix ) {
	global $wpdb;

	return $wpdb->prefix . (string) $suffix;
}

/**
 * Build the existing fail-closed persistent-storage error.
 *
 * @param string $code Database error code.
 * @param string $database_error Optional internal database error.
 * @return WP_Error
 */
function yotm_database_storage_error( $code = 'yotm_job_storage_unavailable', $database_error = '' ) {
	$message = 'yotm_job_storage_inconsistent' === $code
		? __( 'Persistent job storage is incomplete. Restore the job database tables before continuing.', 'thumbnail-manager' )
		: __( 'Persistent job storage is unavailable. Please try again later or ask an administrator to check the database.', 'thumbnail-manager' );

	$data = array();
	if ( '' !== $database_error ) {
		$data['database_error'] = $database_error;
	}

	return new WP_Error( $code, $message, $data );
}

/**
 * Return a persistence error for the most recent database query, if any.
 *
 * @return WP_Error|false
 */
function yotm_database_last_error() {
	global $wpdb;

	if ( '' === (string) $wpdb->last_error ) {
		return false;
	}

	return yotm_database_storage_error( 'yotm_job_storage_unavailable', (string) $wpdb->last_error );
}

/**
 * Acquire a named MySQL lock without waiting.
 *
 * @param string $lock_name Lock name.
 * @return bool|WP_Error True when acquired, false for contention, or a persistence error.
 */
function yotm_database_acquire_named_lock( $lock_name ) {
	global $wpdb;

	$sql = $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', sanitize_text_field( $lock_name ) );

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Advisory locks are connection state and cannot use the object cache.
	$result      = $wpdb->get_var( $sql );
	$query_error = yotm_database_last_error();

	if ( is_wp_error( $query_error ) ) {
		return $query_error;
	}

	if ( null === $result || ! in_array( (string) $result, array( '0', '1' ), true ) ) {
		return yotm_database_storage_error();
	}

	return '1' === (string) $result;
}

/**
 * Release a named MySQL lock owned by this connection.
 *
 * @param string $lock_name Lock name.
 * @return bool
 */
function yotm_database_release_named_lock( $lock_name ) {
	global $wpdb;

	$sql = $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', sanitize_text_field( $lock_name ) );

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Advisory locks are connection state and cannot use the object cache.
	return 1 === (int) $wpdb->get_var( $sql );
}
