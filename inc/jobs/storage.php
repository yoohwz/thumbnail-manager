<?php
/**
 * Persistent job storage readiness and installation.
 *
 * @package Thumbnail_Manager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once dirname( __DIR__ ) . '/infrastructure/database.php';

if ( ! defined( 'YOTM_JOB_DB_VERSION' ) ) {
	define( 'YOTM_JOB_DB_VERSION', '1.2.0' );
}

if ( ! defined( 'YOTM_JOB_DB_PRE_SOURCE_VERSION' ) ) {
	define( 'YOTM_JOB_DB_PRE_SOURCE_VERSION', '1.1.0' );
}

if ( ! defined( 'YOTM_JOB_DB_MIGRATION_BACKOFF' ) ) {
	define( 'YOTM_JOB_DB_MIGRATION_BACKOFF', 5 * MINUTE_IN_SECONDS );
}

/**
 * Return the persistent job table names for the current site.
 *
 * @return array{jobs:string,items:string,sources:string}
 */
function yotm_job_table_names() {
	return array(
		'jobs'    => yotm_database_table_name( 'yotm_jobs' ),
		'items'   => yotm_database_table_name( 'yotm_job_items' ),
		'sources' => yotm_database_table_name( 'yotm_media_sources' ),
	);
}

/**
 * Return the physical presence of both job tables for the current site.
 *
 * @return array{jobs:bool,items:bool,sources:bool}
 */
function yotm_job_table_presence() {
	global $wpdb;

	$presence    = array();
	$suppressing = $wpdb->suppress_errors();

	foreach ( yotm_job_table_names() as $key => $table ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Physical schema readiness cannot use the object cache; table names are derived from the trusted WordPress prefix.
		$presence[ $key ] = ! empty( $wpdb->get_results( "DESCRIBE {$table}" ) );
	}

	$wpdb->suppress_errors( $suppressing );

	return $presence;
}

/**
 * Check that both persistent storage tables exist.
 *
 * @return bool
 */
function yotm_job_tables_exist() {
	$presence = yotm_job_table_presence();

	return $presence['jobs'] && $presence['items'] && $presence['sources'];
}

/**
 * Return the request-local storage state key for the current site.
 *
 * @return string
 */
function yotm_job_storage_request_key() {
	global $wpdb;

	return get_current_blog_id() . '|' . $wpdb->prefix;
}

/**
 * Build a fail-closed persistent-storage error.
 *
 * @param string $code Database error code.
 * @param string $database_error Optional internal database error.
 * @return WP_Error
 */
function yotm_job_storage_error( $code = 'yotm_job_storage_unavailable', $database_error = '' ) {
	return yotm_database_storage_error( $code, $database_error );
}

/**
 * Return a persistence error for the most recent database query, if any.
 *
 * @return WP_Error|false
 */
function yotm_job_last_database_error() {
	return yotm_database_last_error();
}

/**
 * Run one additive job-table install or migration attempt.
 *
 * This low-level runner must only be called by yotm_job_storage_ready().
 *
 * @return true|WP_Error
 */
function yotm_run_job_table_migration() {
	global $wpdb;

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';

	$tables          = yotm_job_table_names();
	$charset_collate = $wpdb->get_charset_collate();
	$jobs_sql        = "CREATE TABLE {$tables['jobs']} (
		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		token char(36) NOT NULL,
		blog_id bigint(20) unsigned NOT NULL DEFAULT 0,
		user_id bigint(20) unsigned NOT NULL DEFAULT 0,
		type varchar(32) NOT NULL,
		status varchar(24) NOT NULL,
		phase varchar(24) NOT NULL DEFAULT '',
		counter_mode varchar(24) NOT NULL DEFAULT 'legacy_v1',
		manifest_hash char(64) NOT NULL DEFAULT '',
		payload longtext NULL,
		total bigint(20) unsigned NOT NULL DEFAULT 0,
		processed bigint(20) unsigned NOT NULL DEFAULT 0,
		succeeded bigint(20) unsigned NOT NULL DEFAULT 0,
		failed bigint(20) unsigned NOT NULL DEFAULT 0,
		bytes bigint(20) unsigned NOT NULL DEFAULT 0,
		cursor_id bigint(20) unsigned NOT NULL DEFAULT 0,
		max_id bigint(20) unsigned NOT NULL DEFAULT 0,
		worker_token char(36) NOT NULL DEFAULT '',
		worker_generation bigint(20) unsigned NOT NULL DEFAULT 0,
		worker_lease_expires_at datetime NULL DEFAULT NULL,
		created_at datetime NOT NULL,
		updated_at datetime NOT NULL,
		expires_at datetime NOT NULL,
		PRIMARY KEY  (id),
		UNIQUE KEY token (token),
		KEY owner_status (blog_id,user_id,type,status),
		KEY expires_at (expires_at),
		KEY worker_lease (status,worker_lease_expires_at)
	) {$charset_collate};";
	$items_sql       = "CREATE TABLE {$tables['items']} (
		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		job_id bigint(20) unsigned NOT NULL,
		item_key char(64) NOT NULL,
		status varchar(24) NOT NULL DEFAULT 'queued',
		payload longtext NULL,
		error text NULL,
		bytes bigint(20) unsigned NOT NULL DEFAULT 0,
		claim_token char(36) NOT NULL DEFAULT '',
		claim_generation bigint(20) unsigned NOT NULL DEFAULT 0,
		claim_expires_at datetime NULL DEFAULT NULL,
		attempts bigint(20) unsigned NOT NULL DEFAULT 0,
		created_at datetime NOT NULL,
		updated_at datetime NOT NULL,
		PRIMARY KEY  (id),
		UNIQUE KEY job_item (job_id,item_key),
		KEY job_status (job_id,status,id),
		KEY job_claim (job_id,status,claim_expires_at,id)
	) {$charset_collate};";
	$sources_sql     = "CREATE TABLE {$tables['sources']} (
		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		attachment_id bigint(20) unsigned NOT NULL,
		source_kind varchar(24) NOT NULL,
		path_hash char(64) NOT NULL,
		path text NOT NULL,
		updated_at datetime NOT NULL,
		PRIMARY KEY  (id),
		UNIQUE KEY attachment_source (attachment_id,source_kind,path_hash),
		KEY path_attachment (path_hash,attachment_id),
		KEY attachment_id (attachment_id)
	) {$charset_collate};";

	dbDelta( $jobs_sql );
	$jobs_error = (string) $wpdb->last_error;
	dbDelta( $items_sql );
	$items_error = (string) $wpdb->last_error;
	dbDelta( $sources_sql );
	$sources_error = (string) $wpdb->last_error;

	if ( '' !== $jobs_error || '' !== $items_error || '' !== $sources_error || ! yotm_job_tables_exist() ) {
		$database_error = '' !== $jobs_error ? $jobs_error : ( '' !== $items_error ? $items_error : $sources_error );

		return yotm_job_storage_error( 'yotm_job_storage_unavailable', $database_error );
	}

	update_option( 'yotm_job_db_version', YOTM_JOB_DB_VERSION, false );
	if ( YOTM_JOB_DB_VERSION !== get_option( 'yotm_job_db_version' ) ) {
		return yotm_job_storage_error();
	}

	yotm_schedule_job_cleanup();

	return true;
}

/**
 * Ensure persistent job storage is ready for the current site.
 *
 * The result is memoized for the current site and request. Automatic DDL is
 * allowed only for an absent/older marker when all tables are either present
 * or absent, or for a known two-table predecessor. Partial storage and
 * current-marker data loss always fail closed.
 *
 * @return true|WP_Error
 */
function yotm_job_storage_ready() {
	$key = yotm_job_storage_request_key();

	if ( ! isset( $GLOBALS['yotm_job_storage_readiness'] ) || ! is_array( $GLOBALS['yotm_job_storage_readiness'] ) ) {
		$GLOBALS['yotm_job_storage_readiness'] = array();
	}

	if ( array_key_exists( $key, $GLOBALS['yotm_job_storage_readiness'] ) ) {
		return $GLOBALS['yotm_job_storage_readiness'][ $key ];
	}

	$stored_version = get_option( 'yotm_job_db_version' );
	$presence       = yotm_job_table_presence();
	$all_present    = $presence['jobs'] && $presence['items'] && $presence['sources'];
	$all_absent     = ! $presence['jobs'] && ! $presence['items'] && ! $presence['sources'];
	$predecessor    = in_array( $stored_version, array( '1.0.1', YOTM_JOB_DB_PRE_SOURCE_VERSION ), true )
		&& $presence['jobs']
		&& $presence['items']
		&& ! $presence['sources'];
	$is_current     = YOTM_JOB_DB_VERSION === $stored_version;

	if ( $is_current ) {
		$result                                        = $all_present ? true : yotm_job_storage_error( 'yotm_job_storage_inconsistent' );
		$GLOBALS['yotm_job_storage_readiness'][ $key ] = $result;

		return $result;
	}

	if ( ! $all_present && ! $all_absent && ! $predecessor ) {
		$result                                        = yotm_job_storage_error( 'yotm_job_storage_inconsistent' );
		$GLOBALS['yotm_job_storage_readiness'][ $key ] = $result;

		return $result;
	}

	$backoff = get_transient( 'yotm_job_db_migration_failure' );
	if ( is_array( $backoff ) && YOTM_JOB_DB_VERSION === ( $backoff['version'] ?? '' ) ) {
		$result                                        = yotm_job_storage_error();
		$GLOBALS['yotm_job_storage_readiness'][ $key ] = $result;

		return $result;
	}

	$result = yotm_run_job_table_migration();
	if ( is_wp_error( $result ) ) {
		set_transient(
			'yotm_job_db_migration_failure',
			array(
				'version'   => YOTM_JOB_DB_VERSION,
				'failed_at' => time(),
			),
			YOTM_JOB_DB_MIGRATION_BACKOFF
		);
	} else {
		delete_transient( 'yotm_job_db_migration_failure' );
	}

	$GLOBALS['yotm_job_storage_readiness'][ $key ] = $result;

	return $result;
}

/**
 * Install or update job tables through the guarded readiness coordinator.
 *
 * @return true|WP_Error
 */
function yotm_install_job_tables() {
	return yotm_job_storage_ready();
}

/**
 * Schedule daily job cleanup once for the current site.
 */
function yotm_schedule_job_cleanup() {
	if ( ! wp_next_scheduled( 'yotm_cleanup_jobs' ) ) {
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'yotm_cleanup_jobs' );
	}
}

/**
 * Prepare persistent storage and cleanup scheduling during activation.
 *
 * @return true|WP_Error
 */
function yotm_activate_job_storage() {
	$ready = yotm_job_storage_ready();

	if ( true === $ready ) {
		yotm_schedule_job_cleanup();
	}

	return $ready;
}

/**
 * Backward-compatible readiness entrypoint for existing integrations.
 *
 * @return true|WP_Error
 */
function yotm_maybe_install_job_tables() {
	return yotm_job_storage_ready();
}

/**
 * Remove the scheduled cleanup event when deactivating the plugin.
 *
 * @param bool $network_deactivating Whether the plugin is network-deactivated.
 */
function yotm_deactivate_job_cleanup( $network_deactivating = false ) {
	if ( ! $network_deactivating || ! is_multisite() ) {
		wp_clear_scheduled_hook( 'yotm_cleanup_jobs' );

		return;
	}

	$offset = 0;
	$limit  = 100;

	do {
		$site_ids   = get_sites(
			array(
				'fields'  => 'ids',
				'number'  => $limit,
				'offset'  => $offset,
				'orderby' => 'id',
				'order'   => 'ASC',
			)
		);
		$page_count = count( $site_ids );

		foreach ( $site_ids as $site_id ) {
			switch_to_blog( $site_id );
			try {
				wp_clear_scheduled_hook( 'yotm_cleanup_jobs' );
			} finally {
				restore_current_blog();
			}
		}

		$offset += $page_count;
	} while ( $page_count === $limit );
}
