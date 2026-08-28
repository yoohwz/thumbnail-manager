<?php
/**
 * Persistent job and job-item storage.
 *
 * @package Thumbnail_Manager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'YOTM_JOB_DB_VERSION' ) ) {
	define( 'YOTM_JOB_DB_VERSION', '1.2.0' );
}

if ( ! defined( 'YOTM_JOB_DB_PRE_SOURCE_VERSION' ) ) {
	define( 'YOTM_JOB_DB_PRE_SOURCE_VERSION', '1.1.0' );
}

if ( ! defined( 'YOTM_JOB_DB_MIGRATION_BACKOFF' ) ) {
	define( 'YOTM_JOB_DB_MIGRATION_BACKOFF', 5 * MINUTE_IN_SECONDS );
}

if ( ! defined( 'YOTM_JOB_WORKER_LEASE_SECONDS' ) ) {
	define( 'YOTM_JOB_WORKER_LEASE_SECONDS', 2 * MINUTE_IN_SECONDS );
}

if ( ! defined( 'YOTM_JOB_AUDIT_RETENTION_SECONDS' ) ) {
	define( 'YOTM_JOB_AUDIT_RETENTION_SECONDS', 7 * DAY_IN_SECONDS );
}

/**
 * Return the persistent job table names for the current site.
 *
 * @return array{jobs:string,items:string,sources:string}
 */
function yotm_job_table_names() {
	global $wpdb;

	return array(
		'jobs'    => $wpdb->prefix . 'yotm_jobs',
		'items'   => $wpdb->prefix . 'yotm_job_items',
		'sources' => $wpdb->prefix . 'yotm_media_sources',
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
function yotm_job_last_database_error() {
	global $wpdb;

	if ( '' === (string) $wpdb->last_error ) {
		return false;
	}

	return yotm_job_storage_error( 'yotm_job_storage_unavailable', (string) $wpdb->last_error );
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
 */
function yotm_deactivate_job_cleanup() {
	wp_clear_scheduled_hook( 'yotm_cleanup_jobs' );
}

/**
 * Return statuses that still own a concurrency lock.
 *
 * @return string[]
 */
function yotm_job_active_statuses() {
	return array( 'scanning', 'running', 'awaiting_approval', 'approved', 'deleting' );
}

/**
 * Decode a stored job payload.
 *
 * @param string|null $payload JSON payload.
 * @return array
 */
function yotm_job_decode_payload( $payload ) {
	$decoded = json_decode( (string) $payload, true );

	return is_array( $decoded ) ? $decoded : array();
}

/**
 * Normalize a job database row.
 *
 * @param object|null $row Database row.
 * @return array|false
 */
function yotm_job_normalize_row( $row ) {
	if ( ! is_object( $row ) ) {
		return false;
	}

	return array(
		'id'                      => (int) $row->id,
		'token'                   => (string) $row->token,
		'blog_id'                 => (int) $row->blog_id,
		'user_id'                 => (int) $row->user_id,
		'type'                    => (string) $row->type,
		'status'                  => (string) $row->status,
		'phase'                   => (string) $row->phase,
		'counter_mode'            => (string) $row->counter_mode,
		'manifest_hash'           => (string) $row->manifest_hash,
		'payload'                 => yotm_job_decode_payload( $row->payload ),
		'total'                   => (int) $row->total,
		'processed'               => (int) $row->processed,
		'succeeded'               => (int) $row->succeeded,
		'failed'                  => (int) $row->failed,
		'bytes'                   => (int) $row->bytes,
		'cursor'                  => (int) $row->cursor_id,
		'max_id'                  => (int) $row->max_id,
		'worker_token'            => (string) $row->worker_token,
		'worker_generation'       => (int) $row->worker_generation,
		'worker_lease_expires_at' => null === $row->worker_lease_expires_at ? '' : (string) $row->worker_lease_expires_at,
		'created_at'              => (string) $row->created_at,
		'updated_at'              => (string) $row->updated_at,
		'expires_at'              => (string) $row->expires_at,
	);
}

/**
 * Fetch a job by numeric ID without applying ownership checks.
 *
 * @param int $job_id Job ID.
 * @return array|false
 */
function yotm_job_get_by_id( $job_id ) {
	global $wpdb;

	$tables = yotm_job_table_names();
	$row    = $wpdb->get_row(
		$wpdb->prepare( "SELECT * FROM {$tables['jobs']} WHERE id = %d", absint( $job_id ) )
	);

	return yotm_job_normalize_row( $row );
}

/**
 * Return the site/job-scoped named worker lock.
 *
 * @param int $job_id Job ID.
 * @return string
 */
function yotm_job_worker_lock_name( $job_id ) {
	global $wpdb;

	$database = defined( 'DB_NAME' ) ? DB_NAME : 'WordPress';
	$scope    = $database . '|' . $wpdb->prefix . '|' . get_current_blog_id() . '|' . absint( $job_id );

	return 'yotm_worker_' . md5( $scope );
}

/**
 * Acquire a named MySQL lock without waiting.
 *
 * @param string $lock_name Lock name.
 * @return bool|WP_Error True when acquired, false for contention, or a persistence error.
 */
function yotm_job_acquire_named_lock( $lock_name ) {
	global $wpdb;

	$sql = $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', sanitize_text_field( $lock_name ) );

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Advisory locks are connection state and cannot use the object cache.
	$result      = $wpdb->get_var( $sql );
	$query_error = yotm_job_last_database_error();

	if ( is_wp_error( $query_error ) ) {
		return $query_error;
	}

	if ( null === $result || ! in_array( (string) $result, array( '0', '1' ), true ) ) {
		return yotm_job_storage_error();
	}

	return '1' === (string) $result;
}

/**
 * Release a named MySQL lock owned by this connection.
 *
 * @param string $lock_name Lock name.
 * @return bool
 */
function yotm_job_release_named_lock( $lock_name ) {
	global $wpdb;

	$sql = $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', sanitize_text_field( $lock_name ) );

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Advisory locks are connection state and cannot use the object cache.
	return 1 === (int) $wpdb->get_var( $sql );
}

/**
 * Register one worker for request-shutdown cleanup.
 *
 * @param array $worker Worker ownership data.
 */
function yotm_job_register_worker( $worker ) {
	if ( ! isset( $GLOBALS['yotm_job_workers'] ) || ! is_array( $GLOBALS['yotm_job_workers'] ) ) {
		$GLOBALS['yotm_job_workers'] = array();
		register_shutdown_function( 'yotm_job_release_all_workers' );
	}

	$GLOBALS['yotm_job_workers'][ $worker['lock_name'] ] = $worker;
}

/**
 * Release all worker locks still held at request shutdown.
 */
function yotm_job_release_all_workers() {
	if ( empty( $GLOBALS['yotm_job_workers'] ) || ! is_array( $GLOBALS['yotm_job_workers'] ) ) {
		return;
	}

	foreach ( array_reverse( $GLOBALS['yotm_job_workers'] ) as $worker ) {
		yotm_job_release_worker( $worker );
	}
}

/**
 * Acquire the request-liveness lock and persisted worker generation.
 *
 * The named lock remains authoritative even after the persisted TTL elapses.
 * A second request cannot take over while the previous DB connection is alive.
 *
 * @param int      $job_id Job ID.
 * @param string[] $statuses Allowed statuses.
 * @param string[] $phases Allowed phases.
 * @return array|WP_Error
 */
function yotm_job_acquire_worker( $job_id, $statuses, $phases = array() ) {
	global $wpdb;

	$job_id    = absint( $job_id );
	$statuses  = array_values( array_filter( array_map( 'sanitize_key', (array) $statuses ) ) );
	$phases    = array_values( array_filter( array_map( 'sanitize_key', (array) $phases ) ) );
	$lock_name = yotm_job_worker_lock_name( $job_id );

	if ( empty( $statuses ) ) {
		return new WP_Error(
			'yotm_job_worker_busy',
			__( 'Another request is processing this job. Retrying shortly.', 'thumbnail-manager' ),
			array( 'job' => yotm_job_get_by_id( $job_id ) )
		);
	}

	$lock_acquired = yotm_job_acquire_named_lock( $lock_name );
	if ( is_wp_error( $lock_acquired ) ) {
		return $lock_acquired;
	}

	if ( ! $lock_acquired ) {
		return new WP_Error(
			'yotm_job_worker_busy',
			__( 'Another request is processing this job. Retrying shortly.', 'thumbnail-manager' ),
			array( 'job' => yotm_job_get_by_id( $job_id ) )
		);
	}

	$tables              = yotm_job_table_names();
	$token               = wp_generate_uuid4();
	$now                 = gmdate( 'Y-m-d H:i:s' );
	$lease_expires       = gmdate( 'Y-m-d H:i:s', time() + YOTM_JOB_WORKER_LEASE_SECONDS );
	$status_placeholders = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );
	$args                = array( $token, $lease_expires, $now, $job_id, get_current_blog_id(), get_current_user_id() );
	$where               = "id = %d AND blog_id = %d AND user_id = %d AND status IN ({$status_placeholders})";
	$args                = array_merge( $args, $statuses );

	if ( ! empty( $phases ) ) {
		$phase_placeholders = implode( ',', array_fill( 0, count( $phases ), '%s' ) );
		$where             .= " AND phase IN ({$phase_placeholders})";
		$args               = array_merge( $args, $phases );
	}

	$where .= " AND expires_at >= %s
		AND (worker_token = '' OR worker_lease_expires_at IS NULL OR worker_lease_expires_at < %s)";
	$args[] = $now;
	$args[] = $now;
	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Table/WHERE fragments are plugin-owned or built from fixed predicates; arguments are assembled above.
	$sql = $wpdb->prepare(
		"UPDATE {$tables['jobs']}
		SET worker_token = %s, worker_generation = worker_generation + 1,
		worker_lease_expires_at = %s, updated_at = %s
		WHERE {$where}",
		...$args
	);
	// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Atomic worker ownership requires an uncached conditional update; prepared above.
	$updated = $wpdb->query( $sql );
	if ( false === $updated ) {
		$query_error = yotm_job_last_database_error();
		yotm_job_release_named_lock( $lock_name );

		return is_wp_error( $query_error ) ? $query_error : yotm_job_storage_error();
	}

	if ( 0 === $updated ) {
		yotm_job_release_named_lock( $lock_name );

		return new WP_Error(
			'yotm_job_worker_busy',
			__( 'Another request is processing this job. Retrying shortly.', 'thumbnail-manager' ),
			array( 'job' => yotm_job_get_by_id( $job_id ) )
		);
	}

	$job    = yotm_job_get_by_id( $job_id );
	$worker = array(
		'job_id'     => $job_id,
		'token'      => $token,
		'generation' => (int) $job['worker_generation'],
		'lock_name'  => $lock_name,
		'statuses'   => $statuses,
		'phases'     => $phases,
	);

	yotm_job_register_worker( $worker );

	return $worker;
}

/**
 * Refresh a worker lease while retaining its generation and liveness lock.
 *
 * @param array $worker Worker ownership data.
 * @return bool
 */
function yotm_job_refresh_worker( $worker ) {
	global $wpdb;

	$tables              = yotm_job_table_names();
	$statuses            = (array) ( $worker['statuses'] ?? array() );
	$phases              = (array) ( $worker['phases'] ?? array() );
	$status_placeholders = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );
	$now                 = gmdate( 'Y-m-d H:i:s' );
	$args                = array(
		gmdate( 'Y-m-d H:i:s', time() + YOTM_JOB_WORKER_LEASE_SECONDS ),
		$now,
		absint( $worker['job_id'] ?? 0 ),
		sanitize_text_field( $worker['token'] ?? '' ),
		absint( $worker['generation'] ?? 0 ),
	);
	$where               = "id = %d AND worker_token = %s AND worker_generation = %d AND status IN ({$status_placeholders})";
	$args                = array_merge( $args, $statuses );

	if ( ! empty( $phases ) ) {
		$phase_placeholders = implode( ',', array_fill( 0, count( $phases ), '%s' ) );
		$where             .= " AND phase IN ({$phase_placeholders})";
		$args               = array_merge( $args, $phases );
	}

	$where .= ' AND expires_at >= %s';
	$args[] = $now;
	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Table/WHERE fragments are plugin-owned or built from fixed predicates; arguments are assembled above.
	$sql = $wpdb->prepare(
		"UPDATE {$tables['jobs']} SET worker_lease_expires_at = %s, updated_at = %s WHERE {$where}",
		...$args
	);
	// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Lease refresh requires an uncached conditional update; prepared above.
	$updated = $wpdb->query( $sql );
	if ( false === $updated ) {
		return false;
	}

	if ( 1 === $updated ) {
		return true;
	}

	$job = yotm_job_get_by_id( absint( $worker['job_id'] ?? 0 ) );

	return is_array( $job )
		&& hash_equals( (string) $job['worker_token'], (string) ( $worker['token'] ?? '' ) )
		&& absint( $worker['generation'] ?? 0 ) === (int) $job['worker_generation']
		&& in_array( $job['status'], $statuses, true )
		&& ( empty( $phases ) || in_array( $job['phase'], $phases, true ) )
		&& strtotime( $job['expires_at'] . ' UTC' ) >= time();
}

/**
 * Release persisted worker ownership, then the request-liveness lock.
 *
 * @param array $worker Worker ownership data.
 * @return void
 */
function yotm_job_release_worker( $worker ) {
	global $wpdb;

	if ( empty( $worker['lock_name'] ) ) {
		return;
	}

	$tables = yotm_job_table_names();
	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is plugin-owned.
	$sql = $wpdb->prepare(
		"UPDATE {$tables['jobs']} SET worker_token = '', worker_lease_expires_at = NULL, updated_at = %s
		WHERE id = %d AND worker_token = %s AND worker_generation = %d",
		gmdate( 'Y-m-d H:i:s' ),
		absint( $worker['job_id'] ?? 0 ),
		sanitize_text_field( $worker['token'] ?? '' ),
		absint( $worker['generation'] ?? 0 )
	);
	// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Request shutdown must clear persisted ownership without caching; prepared above.
	$wpdb->query( $sql );
	yotm_job_release_named_lock( $worker['lock_name'] );

	if ( isset( $GLOBALS['yotm_job_workers'][ $worker['lock_name'] ] ) ) {
		unset( $GLOBALS['yotm_job_workers'][ $worker['lock_name'] ] );
	}
}

/**
 * Expire an overdue active job only when no request still owns its liveness lock.
 *
 * @param array $job Job row.
 * @return array|WP_Error
 */
function yotm_job_expire_if_inactive( $job ) {
	if (
		! is_array( $job )
		|| ! in_array( $job['status'] ?? '', yotm_job_active_statuses(), true )
		|| strtotime( ( $job['expires_at'] ?? '' ) . ' UTC' ) >= time()
	) {
		return $job;
	}

	$lock_name = yotm_job_worker_lock_name( $job['id'] );
	$lock      = yotm_job_acquire_named_lock( $lock_name );
	if ( is_wp_error( $lock ) ) {
		return $lock;
	}

	if ( ! $lock ) {
		return $job;
	}

	try {
		if ( yotm_job_has_recovery_journals( $job['id'] ) ) {
			$payload                  = $job['payload'];
			$payload['recovery_only'] = 1;
			yotm_job_update(
				$job['id'],
				array(
					'payload'    => $payload,
					'expires_at' => gmdate( 'Y-m-d H:i:s', time() + YOTM_JOB_WORKER_LEASE_SECONDS ),
				)
			);
			return yotm_job_get_by_id( $job['id'] );
		}

		$expired = yotm_job_transition(
			$job['id'],
			yotm_job_active_statuses(),
			array(),
			array(
				'status'                  => 'expired',
				'phase'                   => 'expired',
				'worker_token'            => '',
				'worker_lease_expires_at' => null,
				'expires_at'              => gmdate( 'Y-m-d H:i:s', time() + YOTM_JOB_AUDIT_RETENTION_SECONDS ),
			),
			array( 'expired_deadline' => true )
		);

		return $expired ? $expired : yotm_job_get_by_id( $job['id'] );
	} finally {
		yotm_job_release_named_lock( $lock_name );
	}//end try
}

/**
 * Fetch a job token owned by the current user and site.
 *
 * @param string $token Job token.
 * @return array|WP_Error
 */
function yotm_job_get( $token ) {
	global $wpdb;

	$ready = yotm_job_storage_ready();
	if ( is_wp_error( $ready ) ) {
		return $ready;
	}

	$tables      = yotm_job_table_names();
	$token       = sanitize_text_field( (string) $token );
	$row         = $wpdb->get_row(
		$wpdb->prepare(
			"SELECT * FROM {$tables['jobs']} WHERE token = %s AND blog_id = %d AND user_id = %d",
			$token,
			get_current_blog_id(),
			get_current_user_id()
		)
	);
	$query_error = yotm_job_last_database_error();
	if ( is_wp_error( $query_error ) ) {
		return $query_error;
	}

	$job = yotm_job_normalize_row( $row );

	if ( false === $job ) {
		return new WP_Error( 'yotm_job_missing', __( 'Job not found or it belongs to another user.', 'thumbnail-manager' ) );
	}

	$job = yotm_job_expire_if_inactive( $job );
	if ( is_wp_error( $job ) ) {
		return $job;
	}

	if ( 'expired' === ( $job['status'] ?? '' ) ) {
		return new WP_Error( 'yotm_job_expired', __( 'This job has expired. Please start a new scan.', 'thumbnail-manager' ) );
	}

	return $job;
}

/**
 * Find an active job owned by the current user.
 *
 * @param string $type Job type.
 * @return array|false|WP_Error
 */
function yotm_job_get_active_for_current_user( $type ) {
	global $wpdb;

	$tables       = yotm_job_table_names();
	$statuses     = yotm_job_active_statuses();
	$placeholders = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );
	$args         = array_merge( array( get_current_blog_id(), get_current_user_id(), sanitize_key( $type ) ), $statuses );
	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Table and placeholder list are plugin-owned; arguments are assembled above.
	$sql = $wpdb->prepare(
		"SELECT * FROM {$tables['jobs']}
		WHERE blog_id = %d AND user_id = %d AND type = %s
		AND status IN ({$placeholders})
		ORDER BY id DESC LIMIT 1",
		...$args
	);
	// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Active persistent jobs must be read uncached; prepared above.
	$row         = $wpdb->get_row( $sql );
	$query_error = yotm_job_last_database_error();

	return is_wp_error( $query_error ) ? $query_error : yotm_job_normalize_row( $row );
}

/**
 * Create a persistent job and enforce the destructive-operation lock.
 *
 * @param string $type    Job type.
 * @param array  $payload Job payload.
 * @param array  $args    Job columns and options.
 * @return array|WP_Error
 */
function yotm_job_create( $type, $payload = array(), $args = array() ) {
	global $wpdb;

	$ready = yotm_job_storage_ready();
	if ( is_wp_error( $ready ) ) {
		return $ready;
	}

	$cleanup = yotm_cleanup_expired_jobs();
	if ( is_wp_error( $cleanup ) ) {
		return $cleanup;
	}

	$type      = sanitize_key( $type );
	$exclusive = isset( $args['exclusive'] ) ? (bool) $args['exclusive'] : in_array( $type, array( 'prune', 'regenerate' ), true );
	$lock_name = '';
	$active    = yotm_job_get_active_for_current_user( $type );
	if ( is_wp_error( $active ) ) {
		return $active;
	}

	if ( $active ) {
		return new WP_Error(
			'yotm_job_exists',
			__( 'An unfinished job of this type already exists. Resume or cancel it before starting another.', 'thumbnail-manager' ),
			array(
				'token' => $active['token'],
				'type'  => $active['type'],
			)
		);
	}

	if ( $exclusive ) {
		$database  = defined( 'DB_NAME' ) ? DB_NAME : 'WordPress';
		$lock_name = 'yotm_' . md5( $database . '|' . $wpdb->prefix . '|' . get_current_blog_id() );
		$acquired  = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 5)', $lock_name ) );

		if ( 1 !== $acquired ) {
			return new WP_Error( 'yotm_lock_unavailable', __( 'Could not acquire the media maintenance lock. Please try again.', 'thumbnail-manager' ) );
		}

		$locked = yotm_job_find_destructive_lock();
		if ( is_wp_error( $locked ) ) {
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock_name ) );

			return $locked;
		}

		if ( $locked ) {
			$message = get_current_user_id() === (int) $locked['user_id']
				? __( 'A media maintenance job is already active. Resume or cancel it before starting another.', 'thumbnail-manager' )
				: __( 'Another administrator is already running media maintenance on this site.', 'thumbnail-manager' );

			$error = new WP_Error(
				'yotm_job_locked',
				$message,
				array(
					'token' => get_current_user_id() === (int) $locked['user_id'] && $type === $locked['type'] ? $locked['token'] : '',
					'type'  => $locked['type'],
				)
			);
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock_name ) );

			return $error;
		}
	}//end if

	$tables       = yotm_job_table_names();
	$now          = gmdate( 'Y-m-d H:i:s' );
	$ttl          = isset( $args['ttl'] ) ? max( 15 * MINUTE_IN_SECONDS, absint( $args['ttl'] ) ) : DAY_IN_SECONDS;
	$token        = wp_generate_uuid4();
	$data         = array(
		'token'                   => $token,
		'blog_id'                 => get_current_blog_id(),
		'user_id'                 => get_current_user_id(),
		'type'                    => $type,
		'status'                  => sanitize_key( $args['status'] ?? 'running' ),
		'phase'                   => sanitize_key( $args['phase'] ?? '' ),
		'counter_mode'            => sanitize_key( $args['counter_mode'] ?? 'legacy_v1' ),
		'manifest_hash'           => '',
		'payload'                 => wp_json_encode( is_array( $payload ) ? $payload : array() ),
		'total'                   => absint( $args['total'] ?? 0 ),
		'processed'               => 0,
		'succeeded'               => 0,
		'failed'                  => 0,
		'bytes'                   => 0,
		'cursor_id'               => absint( $args['cursor'] ?? 0 ),
		'max_id'                  => absint( $args['max_id'] ?? 0 ),
		'worker_token'            => '',
		'worker_generation'       => 0,
		'worker_lease_expires_at' => null,
		'created_at'              => $now,
		'updated_at'              => $now,
		'expires_at'              => gmdate( 'Y-m-d H:i:s', time() + $ttl ),
	);
	$insert       = $wpdb->insert( $tables['jobs'], $data );
	$insert_error = $wpdb->last_error;

	if ( '' !== $lock_name ) {
		$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock_name ) );
	}

	if ( false === $insert ) {
		return yotm_job_storage_error( 'yotm_job_storage_unavailable', $insert_error );
	}

	return yotm_job_get_by_id( (int) $wpdb->insert_id );
}

/**
 * Find the current site-wide destructive job lock.
 *
 * @return array|false|WP_Error
 */
function yotm_job_find_destructive_lock() {
	global $wpdb;

	$tables       = yotm_job_table_names();
	$statuses     = yotm_job_active_statuses();
	$placeholders = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );
	$args         = array_merge( array( get_current_blog_id(), 'prune', 'regenerate' ), $statuses );
	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Table and placeholder list are plugin-owned; arguments are assembled above.
	$sql = $wpdb->prepare(
		"SELECT * FROM {$tables['jobs']}
		WHERE blog_id = %d AND type IN (%s,%s)
		AND status IN ({$placeholders})
		ORDER BY id DESC LIMIT 1",
		...$args
	);
	// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Destructive lock state must be read uncached; prepared above.
	$row         = $wpdb->get_row( $sql );
	$query_error = yotm_job_last_database_error();

	return is_wp_error( $query_error ) ? $query_error : yotm_job_normalize_row( $row );
}

/**
 * Sanitize job fields for persistence.
 *
 * @param array $fields Requested fields.
 * @return array
 */
function yotm_job_prepare_update_data( $fields ) {
	$allowed = array( 'status', 'phase', 'manifest_hash', 'payload', 'total', 'processed', 'succeeded', 'failed', 'bytes', 'cursor', 'max_id', 'expires_at', 'worker_token', 'worker_lease_expires_at' );
	$data    = array();

	foreach ( $allowed as $key ) {
		if ( ! array_key_exists( $key, $fields ) ) {
			continue;
		}

		if ( 'payload' === $key ) {
			$data[ $key ] = wp_json_encode( is_array( $fields[ $key ] ) ? $fields[ $key ] : array() );
		} elseif ( in_array( $key, array( 'total', 'processed', 'succeeded', 'failed', 'bytes', 'cursor', 'max_id' ), true ) ) {
			$column          = 'cursor' === $key ? 'cursor_id' : $key;
			$data[ $column ] = absint( $fields[ $key ] );
		} elseif ( in_array( $key, array( 'status', 'phase' ), true ) ) {
			$data[ $key ] = sanitize_key( $fields[ $key ] );
		} elseif ( 'worker_lease_expires_at' === $key && null === $fields[ $key ] ) {
			$data[ $key ] = null;
		} else {
			$data[ $key ] = sanitize_text_field( $fields[ $key ] );
		}
	}

	return $data;
}

/**
 * Conditionally update a job row.
 *
 * @param int   $job_id Job ID.
 * @param array $fields Fields to update.
 * @param array $conditions Expected state and ownership.
 * @return bool
 */
function yotm_job_update_where( $job_id, $fields, $conditions = array() ) {
	global $wpdb;

	$data = yotm_job_prepare_update_data( $fields );
	if ( empty( $data ) ) {
		return true;
	}

	$data['updated_at'] = gmdate( 'Y-m-d H:i:s' );
	$set                = array();
	$args               = array();

	foreach ( $data as $column => $value ) {
		if ( null === $value ) {
			$set[] = "{$column} = NULL";
		} elseif ( in_array( $column, array( 'total', 'processed', 'succeeded', 'failed', 'bytes', 'cursor_id', 'max_id' ), true ) ) {
			$set[]  = "{$column} = %d";
			$args[] = $value;
		} else {
			$set[]  = "{$column} = %s";
			$args[] = $value;
		}
	}

	$where  = array( 'id = %d' );
	$args[] = absint( $job_id );

	foreach ( array( 'status', 'phase' ) as $state_key ) {
		if ( empty( $conditions[ $state_key ] ) ) {
			continue;
		}

		$values       = array_values( array_filter( array_map( 'sanitize_key', (array) $conditions[ $state_key ] ) ) );
		$placeholders = implode( ',', array_fill( 0, count( $values ), '%s' ) );
		$where[]      = "{$state_key} IN ({$placeholders})";
		$args         = array_merge( $args, $values );
	}

	if ( isset( $conditions['worker_token'] ) ) {
		$where[] = 'worker_token = %s';
		$args[]  = sanitize_text_field( $conditions['worker_token'] );
	}

	if ( isset( $conditions['worker_generation'] ) ) {
		$where[] = 'worker_generation = %d';
		$args[]  = absint( $conditions['worker_generation'] );
	}

	if ( isset( $conditions['manifest_hash'] ) ) {
		$where[] = 'manifest_hash = %s';
		$args[]  = sanitize_text_field( $conditions['manifest_hash'] );
	}

	if ( ! empty( $conditions['active_deadline'] ) ) {
		$where[] = 'expires_at >= %s';
		$args[]  = gmdate( 'Y-m-d H:i:s' );
	}

	if ( ! empty( $conditions['expired_deadline'] ) ) {
		$where[] = 'expires_at < %s';
		$args[]  = gmdate( 'Y-m-d H:i:s' );
	}

	$tables = yotm_job_table_names();
	// phpcs:disable WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- The query fragments contain allowlisted columns and the placeholders are assembled above.
	$sql = $wpdb->prepare(
		'UPDATE ' . $tables['jobs'] . ' SET ' . implode( ', ', $set ) . ' WHERE ' . implode( ' AND ', $where ),
		...$args
	);
	// phpcs:enable WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Compare-and-swap persistence must be uncached; prepared from allowlisted columns above.
	$updated = $wpdb->query( $sql );

	return false !== $updated && ( empty( $conditions['require_match'] ) || 1 === $updated );
}

/**
 * Update safe job columns.
 *
 * @param int   $job_id Job ID.
 * @param array $fields Fields to update.
 * @return bool
 */
function yotm_job_update( $job_id, $fields ) {
	return yotm_job_update_where( $job_id, $fields );
}

/**
 * Update a job only while the caller owns its worker generation.
 *
 * @param array $worker Worker ownership data.
 * @param array $fields Fields to update.
 * @return bool
 */
function yotm_job_worker_update( $worker, $fields ) {
	return yotm_job_update_where(
		absint( $worker['job_id'] ?? 0 ),
		$fields,
		array(
			'status'            => (array) ( $worker['statuses'] ?? array() ),
			'phase'             => (array) ( $worker['phases'] ?? array() ),
			'worker_token'      => (string) ( $worker['token'] ?? '' ),
			'worker_generation' => absint( $worker['generation'] ?? 0 ),
			'active_deadline'   => true,
			'require_match'     => true,
		)
	);
}

/**
 * Commit legacy counters after an in-flight unit without changing job state.
 *
 * Cancellation clears the token but retains the generation. A worker from an
 * older generation therefore cannot overwrite counters after a real takeover.
 *
 * @param array $worker Worker ownership data.
 * @param array $fields Legacy counter/cursor fields.
 * @return bool
 */
function yotm_job_legacy_worker_update( $worker, $fields ) {
	$allowed  = array( 'payload', 'processed', 'succeeded', 'failed', 'bytes', 'cursor' );
	$fields   = array_intersect_key( $fields, array_flip( $allowed ) );
	$statuses = array_merge( (array) ( $worker['statuses'] ?? array() ), array( 'cancelled', 'expired' ) );

	return yotm_job_update_where(
		absint( $worker['job_id'] ?? 0 ),
		$fields,
		array(
			'status'            => array_values( array_unique( $statuses ) ),
			'worker_generation' => absint( $worker['generation'] ?? 0 ),
			'require_match'     => true,
		)
	);
}

/**
 * Perform a compare-and-swap job transition.
 *
 * @param int      $job_id Job ID.
 * @param string[] $statuses Allowed source statuses.
 * @param string[] $phases Allowed source phases.
 * @param array    $fields Transition fields.
 * @param array    $conditions Extra exact conditions.
 * @return array|false
 */
function yotm_job_transition( $job_id, $statuses, $phases, $fields, $conditions = array() ) {
	$conditions['status']        = (array) $statuses;
	$conditions['require_match'] = true;
	if ( ! empty( $phases ) ) {
		$conditions['phase'] = (array) $phases;
	}

	if ( ! yotm_job_update_where( $job_id, $fields, $conditions ) ) {
		return false;
	}

	return yotm_job_get_by_id( $job_id );
}

/**
 * Add a unique item to a job.
 *
 * @param int    $job_id  Job ID.
 * @param string $item_key Stable item key.
 * @param array  $payload Item payload.
 * @param string $status  Initial status.
 * @param int    $bytes   Estimated item bytes.
 * @return bool True only when a new row was inserted.
 */
function yotm_job_add_item( $job_id, $item_key, $payload, $status = 'queued', $bytes = 0 ) {
	global $wpdb;

	$tables   = yotm_job_table_names();
	$now      = gmdate( 'Y-m-d H:i:s' );
	$item_key = preg_match( '/^[a-f0-9]{64}$/', (string) $item_key ) ? (string) $item_key : hash( 'sha256', (string) $item_key );
	$sql      = $wpdb->prepare(
		"INSERT IGNORE INTO {$tables['items']}
		(job_id,item_key,status,payload,error,bytes,created_at,updated_at)
		SELECT %d,%s,%s,%s,'',%d,%s,%s FROM {$tables['jobs']}
		WHERE id = %d
		AND ((status = 'scanning' AND phase IN ('selection','metadata','disk'))
		OR (status = 'running' AND phase IN ('source_index','selection','regenerate')))",
		absint( $job_id ),
		$item_key,
		sanitize_key( $status ),
		wp_json_encode( is_array( $payload ) ? $payload : array() ),
		absint( $bytes ),
		$now,
		$now,
		absint( $job_id )
	);

	// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Prepared above; table names are plugin-owned.
	return 1 === $wpdb->query( $sql );
}

/**
 * Merge metadata references into an existing prune item.
 *
 * @param int    $job_id Job ID.
 * @param string $item_key Item key.
 * @param array  $payload New item payload.
 * @return bool
 */
function yotm_job_merge_item_payload( $job_id, $item_key, $payload ) {
	global $wpdb;

	$tables   = yotm_job_table_names();
	$item_key = preg_match( '/^[a-f0-9]{64}$/', (string) $item_key ) ? (string) $item_key : hash( 'sha256', (string) $item_key );
	$row      = $wpdb->get_row(
		$wpdb->prepare(
			"SELECT items.id,items.payload FROM {$tables['items']} items
			INNER JOIN {$tables['jobs']} jobs ON jobs.id = items.job_id
			WHERE items.job_id = %d AND items.item_key = %s
			AND jobs.status = 'scanning' AND jobs.phase IN ('selection','metadata','disk') LIMIT 1",
			absint( $job_id ),
			$item_key
		)
	);

	if ( ! $row ) {
		return false;
	}

	$current  = yotm_job_decode_payload( $row->payload );
	$refs     = array();
	$evidence = array();

	foreach ( array_merge( (array) ( $current['metadata_refs'] ?? array() ), (array) ( $payload['metadata_refs'] ?? array() ) ) as $ref ) {
		if ( ! is_array( $ref ) ) {
			continue;
		}

		$key          = absint( $ref['attachment_id'] ?? 0 ) . ':' . sanitize_key( $ref['size'] ?? '' ) . ':' . sanitize_file_name( $ref['filename'] ?? '' );
		$refs[ $key ] = array(
			'attachment_id' => absint( $ref['attachment_id'] ?? 0 ),
			'size'          => sanitize_key( $ref['size'] ?? '' ),
			'filename'      => sanitize_file_name( $ref['filename'] ?? '' ),
		);
	}

	$current['metadata_refs'] = array_values( $refs );

	foreach ( array_merge( (array) ( $current['ownership_evidence'] ?? array() ), (array) ( $payload['ownership_evidence'] ?? array() ) ) as $proof ) {
		if ( ! is_array( $proof ) ) {
			continue;
		}

		$attachment_id = absint( $proof['attachment_id'] ?? 0 );
		$size          = sanitize_key( $proof['size'] ?? '' );
		$filename      = sanitize_file_name( $proof['filename'] ?? '' );
		$selection     = sanitize_key( $proof['selection'] ?? '' );
		$key           = $attachment_id . ':' . $size . ':' . $filename . ':' . $selection;

		if ( ! $attachment_id || '' === $size || '' === $filename || '' === $selection ) {
			continue;
		}

		$evidence[ $key ] = array(
			'attachment_id' => $attachment_id,
			'size'          => $size,
			'filename'      => $filename,
			'mime'          => sanitize_mime_type( $proof['mime'] ?? '' ),
			'selection'     => $selection,
		);
	}//end foreach

	ksort( $refs );
	ksort( $evidence );
	$current['metadata_refs']      = array_values( $refs );
	$current['ownership_evidence'] = array_values( $evidence );
	$current['ownership_schema']   = sanitize_key( $payload['ownership_schema'] ?? ( $current['ownership_schema'] ?? '' ) );
	$current['ownership']          = sanitize_key( $payload['ownership'] ?? ( $current['ownership'] ?? '' ) );

	if ( empty( $current['remove_metadata'] ) && ! empty( $payload['remove_metadata'] ) ) {
		$current['remove_metadata'] = 1;
	}

	$sql = $wpdb->prepare(
		"UPDATE {$tables['items']} items
		INNER JOIN {$tables['jobs']} jobs ON jobs.id = items.job_id
		SET items.payload = %s, items.updated_at = %s
		WHERE items.id = %d AND jobs.status = 'scanning' AND jobs.phase IN ('selection','metadata','disk')",
		wp_json_encode( $current ),
		gmdate( 'Y-m-d H:i:s' ),
		(int) $row->id
	);

	// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Prepared above; table names are plugin-owned.
	return 1 === $wpdb->query( $sql );
}

/**
 * Check whether an item exists in a job.
 *
 * @param int    $job_id Job ID.
 * @param string $item_key Item key.
 * @return bool
 */
function yotm_job_item_exists( $job_id, $item_key ) {
	global $wpdb;

	$tables   = yotm_job_table_names();
	$item_key = preg_match( '/^[a-f0-9]{64}$/', (string) $item_key ) ? (string) $item_key : hash( 'sha256', (string) $item_key );

	return (bool) $wpdb->get_var(
		$wpdb->prepare( "SELECT id FROM {$tables['items']} WHERE job_id = %d AND item_key = %s LIMIT 1", absint( $job_id ), $item_key )
	);
}

/**
 * Return existing stable item keys from one bounded candidate set.
 *
 * @param int      $job_id Job ID.
 * @param string[] $item_keys Candidate SHA-256 keys.
 * @return array<string,bool>
 */
function yotm_job_existing_item_keys( $job_id, $item_keys ) {
	global $wpdb;

	$keys = array();
	foreach ( (array) $item_keys as $item_key ) {
		if ( preg_match( '/^[a-f0-9]{64}$/', (string) $item_key ) ) {
			$keys[ (string) $item_key ] = (string) $item_key;
		}
	}
	$keys = array_values( $keys );
	if ( empty( $keys ) ) {
		return array();
	}
	$keys = array_slice( $keys, 0, 1000 );

	$tables       = yotm_job_table_names();
	$placeholders = implode( ',', array_fill( 0, count( $keys ), '%s' ) );
	$args         = array_merge( array( absint( $job_id ) ), $keys );
	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Bounded placeholder list is generated from validated hashes.
	$found = $wpdb->get_col(
		$wpdb->prepare(
			"SELECT item_key FROM {$tables['items']} WHERE job_id = %d AND item_key IN ({$placeholders})",
			...$args
		)
	);
	// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

	return array_fill_keys( array_map( 'strval', (array) $found ), true );
}

/**
 * Normalize one raw job-item row returned by plugin-owned SQL.
 *
 * @param object|array $row Raw database row.
 * @return array|false
 */
function yotm_job_normalize_item_row( $row ) {
	$row = is_object( $row ) ? get_object_vars( $row ) : $row;
	if ( ! is_array( $row ) || empty( $row['id'] ) || empty( $row['job_id'] ) ) {
		return false;
	}

	return array(
		'id'               => (int) $row['id'],
		'job_id'           => (int) $row['job_id'],
		'item_key'         => (string) ( $row['item_key'] ?? '' ),
		'status'           => (string) ( $row['status'] ?? '' ),
		'payload'          => yotm_job_decode_payload( $row['payload'] ?? '' ),
		'error'            => (string) ( $row['error'] ?? '' ),
		'bytes'            => (int) ( $row['bytes'] ?? 0 ),
		'claim_token'      => (string) ( $row['claim_token'] ?? '' ),
		'claim_generation' => (int) ( $row['claim_generation'] ?? 0 ),
		'claim_expires_at' => null === ( $row['claim_expires_at'] ?? null ) ? '' : (string) $row['claim_expires_at'],
		'attempts'         => (int) ( $row['attempts'] ?? 0 ),
	);
}

/**
 * Fetch a job item by its stable key.
 *
 * @param int    $job_id Job ID.
 * @param string $item_key Item key.
 * @return array|false
 */
function yotm_job_get_item_by_key( $job_id, $item_key ) {
	global $wpdb;

	$tables   = yotm_job_table_names();
	$item_key = preg_match( '/^[a-f0-9]{64}$/', (string) $item_key ) ? (string) $item_key : hash( 'sha256', (string) $item_key );
	$row      = $wpdb->get_row(
		$wpdb->prepare(
			"SELECT * FROM {$tables['items']} WHERE job_id = %d AND item_key = %s LIMIT 1",
			absint( $job_id ),
			$item_key
		)
	);

	if ( ! $row ) {
		return false;
	}

	return yotm_job_normalize_item_row( $row );
}

/**
 * Fetch job items by status.
 *
 * @param int      $job_id Job ID.
 * @param string[] $statuses Statuses.
 * @param int      $limit Maximum items.
 * @return array[]
 */
function yotm_job_get_items( $job_id, $statuses = array( 'queued' ), $limit = 100 ) {
	global $wpdb;

	$tables       = yotm_job_table_names();
	$statuses     = array_values( array_filter( array_map( 'sanitize_key', (array) $statuses ) ) );
	$limit        = max( 1, min( 1000, absint( $limit ) ) );
	$placeholders = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );
	$args         = array_merge( array( absint( $job_id ) ), $statuses, array( $limit ) );
	$rows         = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT * FROM {$tables['items']} WHERE job_id = %d AND status IN ({$placeholders}) ORDER BY id ASC LIMIT %d",
			...$args
		)
	);
	$out          = array();

	foreach ( $rows as $row ) {
		$item = yotm_job_normalize_item_row( $row );
		if ( $item ) {
			$out[] = $item;
		}
	}

	return $out;
}

/**
 * Atomically claim queued or abandoned items for the current worker.
 *
 * @param array $worker Worker ownership data.
 * @param int   $limit Maximum items.
 * @return array[]|WP_Error
 */
function yotm_job_claim_items( $worker, $limit = 100 ) {
	global $wpdb;

	if ( ! yotm_job_refresh_worker( $worker ) ) {
		return new WP_Error( 'yotm_job_worker_stale', __( 'This job worker no longer owns the current batch.', 'thumbnail-manager' ) );
	}

	$tables              = yotm_job_table_names();
	$limit               = max( 1, min( 1000, absint( $limit ) ) );
	$claim_token         = wp_generate_uuid4();
	$now                 = gmdate( 'Y-m-d H:i:s' );
	$claim_expires       = gmdate( 'Y-m-d H:i:s', time() + YOTM_JOB_WORKER_LEASE_SECONDS );
	$statuses            = (array) ( $worker['statuses'] ?? array() );
	$phases              = (array) ( $worker['phases'] ?? array() );
	$status_placeholders = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );
	$job_where           = "jobs.id = %d AND jobs.worker_token = %s AND jobs.worker_generation = %d
		AND jobs.status IN ({$status_placeholders})";
	$job_args            = array(
		absint( $worker['job_id'] ?? 0 ),
		sanitize_text_field( $worker['token'] ?? '' ),
		absint( $worker['generation'] ?? 0 ),
	);
	$job_args            = array_merge( $job_args, $statuses );

	if ( ! empty( $phases ) ) {
		$phase_placeholders = implode( ',', array_fill( 0, count( $phases ), '%s' ) );
		$job_where         .= " AND jobs.phase IN ({$phase_placeholders})";
		$job_args           = array_merge( $job_args, $phases );
	}

	$job_where .= ' AND jobs.expires_at >= %s';
	$job_args[] = $now;
	$args       = array( $claim_token, $claim_expires, $now, absint( $worker['job_id'] ?? 0 ), $now );
	$args       = array_merge( $args, $job_args, array( $limit ) );
	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Tables and worker predicates are plugin-owned; arguments are assembled above.
	$sql = $wpdb->prepare(
		"UPDATE {$tables['items']}
		SET status = 'processing', claim_token = %s, claim_generation = claim_generation + 1,
		claim_expires_at = %s, attempts = attempts + 1, updated_at = %s
		WHERE job_id = %d
		AND (status = 'queued' OR (status = 'processing'
			AND (claim_token = '' OR claim_expires_at IS NULL OR claim_expires_at < %s)))
		AND EXISTS (SELECT 1 FROM {$tables['jobs']} jobs WHERE {$job_where})
		ORDER BY id ASC LIMIT %d",
		...$args
	);
	// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Atomic queue claims must be uncached; prepared above.
	$claimed = $wpdb->query( $sql );
	if ( false === $claimed ) {
		return yotm_job_storage_error( 'yotm_job_storage_unavailable', (string) $wpdb->last_error );
	}

	if ( 0 === $claimed ) {
		return array();
	}

	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is plugin-owned.
	$sql = $wpdb->prepare(
		"SELECT * FROM {$tables['items']} WHERE job_id = %d AND claim_token = %s ORDER BY id ASC",
		absint( $worker['job_id'] ?? 0 ),
		$claim_token
	);
	// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Newly claimed persistent rows must be read uncached; prepared above.
	$rows = $wpdb->get_results( $sql );
	$out  = array();

	foreach ( $rows as $row ) {
		$item = yotm_job_normalize_item_row( $row );
		if ( $item ) {
			$out[] = $item;
		}
	}

	return $out;
}

/**
 * Refresh one claimed item immediately before its work unit.
 *
 * @param array $item Claimed item.
 * @return bool
 */
function yotm_job_refresh_item_claim( $item ) {
	global $wpdb;

	$tables = yotm_job_table_names();
	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is plugin-owned.
	$sql = $wpdb->prepare(
		"UPDATE {$tables['items']} SET claim_expires_at = %s, updated_at = %s
		WHERE id = %d AND status = 'processing' AND claim_token = %s AND claim_generation = %d",
		gmdate( 'Y-m-d H:i:s', time() + YOTM_JOB_WORKER_LEASE_SECONDS ),
		gmdate( 'Y-m-d H:i:s' ),
		absint( $item['id'] ?? 0 ),
		sanitize_text_field( $item['claim_token'] ?? '' ),
		absint( $item['claim_generation'] ?? 0 )
	);
	// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Claim ownership refresh must be uncached; prepared above.
	$updated = $wpdb->query( $sql );

	if ( false === $updated ) {
		return false;
	}

	if ( 1 === $updated ) {
		return true;
	}

	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is plugin-owned.
	$sql = $wpdb->prepare(
		"SELECT status,claim_token,claim_generation FROM {$tables['items']} WHERE id = %d",
		absint( $item['id'] ?? 0 )
	);
	// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Current claim ownership must be read uncached; prepared above.
	$current = $wpdb->get_row( $sql, ARRAY_A );

	return is_array( $current )
		&& 'processing' === $current['status']
		&& hash_equals( (string) $current['claim_token'], (string) ( $item['claim_token'] ?? '' ) )
		&& absint( $item['claim_generation'] ?? 0 ) === (int) $current['claim_generation'];
}

/**
 * Persist a claimed item's exact payload without weakening claim ownership.
 *
 * @param array $item Claimed item.
 * @param array $payload Replacement payload.
 * @return bool
 */
function yotm_job_update_claimed_item_payload( $item, $payload ) {
	global $wpdb;

	$tables = yotm_job_table_names();
	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Plugin-owned table name; values use placeholders.
	$sql = $wpdb->prepare(
		"UPDATE {$tables['items']} SET payload = %s, updated_at = %s
		WHERE id = %d AND status = 'processing' AND claim_token = %s AND claim_generation = %d",
		wp_json_encode( is_array( $payload ) ? $payload : array() ),
		gmdate( 'Y-m-d H:i:s' ),
		absint( $item['id'] ?? 0 ),
		sanitize_text_field( $item['claim_token'] ?? '' ),
		absint( $item['claim_generation'] ?? 0 )
	);
	// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

	// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Claim-fenced journal persistence must be uncached; prepared above.
	return 1 === $wpdb->query( $sql );
}

/**
 * Return a transiently blocked item to the queue for an immediate safe retry.
 *
 * @param array $item Claimed item.
 * @return bool
 */
function yotm_job_release_item_claim( $item ) {
	global $wpdb;

	$tables = yotm_job_table_names();
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Plugin-owned table name is derived from the trusted WordPress prefix; values use placeholders.
	$sql = $wpdb->prepare(
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Plugin-owned table name is trusted; values use placeholders.
		"UPDATE {$tables['items']} SET status = 'queued', claim_token = '', claim_expires_at = NULL, updated_at = %s
		WHERE id = %d AND status = 'processing' AND claim_token = %s AND claim_generation = %d",
		gmdate( 'Y-m-d H:i:s' ),
		absint( $item['id'] ?? 0 ),
		sanitize_text_field( $item['claim_token'] ?? '' ),
		absint( $item['claim_generation'] ?? 0 )
	);

	// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Claim-fenced transient requeue must be uncached; prepared above.
	return 1 === $wpdb->query( $sql );
}

/**
 * Finalize an item only for the current claim generation.
 *
 * @param array    $item Claimed item.
 * @param string   $status Terminal status.
 * @param string   $error Error or skip reason.
 * @param int|null $bytes Actual bytes, or null to preserve.
 * @return bool
 */
function yotm_job_finish_item( $item, $status, $error = '', $bytes = null ) {
	global $wpdb;

	if ( ! in_array( $status, array( 'done', 'skipped', 'failed' ), true ) ) {
		return false;
	}

	$data = array(
		'sanitize_status' => sanitize_key( $status ),
		'error'           => sanitize_text_field( $error ),
		'updated_at'      => gmdate( 'Y-m-d H:i:s' ),
	);
	$set  = "status = %s, error = %s, claim_token = '', claim_expires_at = NULL, updated_at = %s";
	$args = array( $data['sanitize_status'], $data['error'], $data['updated_at'] );

	if ( null !== $bytes ) {
		$set   .= ', bytes = %d';
		$args[] = absint( $bytes );
	}

	$args[] = absint( $item['id'] ?? 0 );
	$args[] = sanitize_text_field( $item['claim_token'] ?? '' );
	$args[] = absint( $item['claim_generation'] ?? 0 );
	$tables = yotm_job_table_names();
	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Table and SET fragments are plugin-owned; arguments are assembled above.
	$sql = $wpdb->prepare(
		"UPDATE {$tables['items']} SET {$set}
		WHERE id = %d AND status = 'processing' AND claim_token = %s AND claim_generation = %d",
		...$args
	);
	// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Claim-fenced result persistence must be uncached; prepared above.
	return 1 === $wpdb->query( $sql );
}

/**
 * Finalize an item and increment item-v3 job counters in one fenced statement.
 *
 * @param array    $item Claimed item.
 * @param array    $worker Current worker ownership.
 * @param string   $status Terminal status.
 * @param string   $error Error or skip reason.
 * @param int|null $bytes Actual bytes for a successful item.
 * @return bool
 */
function yotm_job_finish_item_v3( $item, $worker, $status, $error = '', $bytes = null ) {
	global $wpdb;

	if ( ! in_array( $status, array( 'done', 'skipped', 'failed' ), true ) ) {
		return false;
	}

	$statuses = array_values( array_filter( array_map( 'sanitize_key', (array) ( $worker['statuses'] ?? array() ) ) ) );
	$phases   = array_values( array_filter( array_map( 'sanitize_key', (array) ( $worker['phases'] ?? array() ) ) ) );
	if ( empty( $statuses ) ) {
		return false;
	}

	$status_placeholders = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );
	$phase_sql           = '';
	$args                = array(
		sanitize_key( $status ),
		sanitize_text_field( $error ),
		absint( $bytes ),
		gmdate( 'Y-m-d H:i:s' ),
		'done' === $status ? 1 : 0,
		'failed' === $status ? 1 : 0,
		'done' === $status ? absint( $bytes ) : 0,
		absint( $item['id'] ?? 0 ),
		sanitize_text_field( $item['claim_token'] ?? '' ),
		absint( $item['claim_generation'] ?? 0 ),
		absint( $worker['job_id'] ?? 0 ),
		sanitize_text_field( $worker['token'] ?? '' ),
		absint( $worker['generation'] ?? 0 ),
	);
	$args                = array_merge( $args, $statuses );
	if ( ! empty( $phases ) ) {
		$phase_placeholders = implode( ',', array_fill( 0, count( $phases ), '%s' ) );
		$phase_sql          = " AND jobs.phase IN ({$phase_placeholders})";
		$args               = array_merge( $args, $phases );
	}
	$args[] = gmdate( 'Y-m-d H:i:s' );

	$tables = yotm_job_table_names();
	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Tables and allowlisted predicates are plugin-owned; values are assembled above.
	$sql = $wpdb->prepare(
		"UPDATE {$tables['items']} items
		INNER JOIN {$tables['jobs']} jobs ON jobs.id = items.job_id
		SET items.status = %s,items.error = %s,items.bytes = %d,items.claim_token = '',
		items.claim_expires_at = NULL,items.updated_at = %s,
		jobs.processed = jobs.processed + 1,jobs.succeeded = jobs.succeeded + %d,
		jobs.failed = jobs.failed + %d,jobs.bytes = jobs.bytes + %d,jobs.updated_at = items.updated_at
		WHERE items.id = %d AND items.status = 'processing' AND items.claim_token = %s
		AND items.claim_generation = %d AND jobs.id = %d AND jobs.counter_mode = 'item_v3'
		AND jobs.worker_token = %s AND jobs.worker_generation = %d
		AND jobs.status IN ({$status_placeholders}){$phase_sql} AND jobs.expires_at >= %s",
		...$args
	);
	// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Exact item/job counter finalization must be one uncached prepared statement.
	$updated = $wpdb->query( $sql );

	return false !== $updated && $updated > 0;
}

/**
 * Return whether a job still has queued or processing items.
 *
 * @param int $job_id Job ID.
 * @return bool
 */
function yotm_job_has_remaining_items( $job_id ) {
	global $wpdb;

	$tables = yotm_job_table_names();
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Existing indexed job/status lookup; prepared below.
	return (bool) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT 1 FROM {$tables['items']} WHERE job_id = %d AND status IN ('queued','processing') LIMIT 1",
			absint( $job_id )
		)
	);
}

/**
 * Return whether an in-flight item has a recoverable media journal.
 *
 * The processing set is bounded by the maximum claim batch. Payloads are read
 * only at cancel/expiry boundaries, never on a normal queue batch.
 *
 * @param int $job_id Job ID.
 * @return bool
 */
function yotm_job_has_recovery_journals( $job_id ) {
	global $wpdb;

	$tables = yotm_job_table_names();
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Bounded recovery guard reads plugin-owned payload rows directly.
	$payloads = $wpdb->get_col(
		$wpdb->prepare(
			"SELECT payload FROM {$tables['items']} WHERE job_id = %d AND status = 'processing' ORDER BY id ASC LIMIT 1000",
			absint( $job_id )
		)
	);

	foreach ( (array) $payloads as $payload_json ) {
		$payload = yotm_job_decode_payload( $payload_json );
		if ( ! empty( $payload['prune_operation_journal_v1'] ) || ! empty( $payload['regeneration_journal'] ) ) {
			return true;
		}
	}

	return false;
}

/**
 * Derive item-backed job counters from terminal item rows.
 *
 * @param int $job_id Job ID.
 * @return array{processed:int,succeeded:int,failed:int,bytes:int,remaining:int}
 */
function yotm_job_item_counters( $job_id ) {
	global $wpdb;

	$tables = yotm_job_table_names();
	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is plugin-owned.
	$sql = $wpdb->prepare(
		"SELECT
		SUM(CASE WHEN status IN ('done','skipped','failed') THEN 1 ELSE 0 END) processed,
		SUM(CASE WHEN status = 'done' THEN 1 ELSE 0 END) succeeded,
		SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) failed,
		SUM(CASE WHEN status = 'done' THEN bytes ELSE 0 END) bytes,
		SUM(CASE WHEN status IN ('queued','processing') THEN 1 ELSE 0 END) remaining
		FROM {$tables['items']} WHERE job_id = %d",
		absint( $job_id )
	);
	// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Counters must reflect current terminal item rows; prepared above.
	$row = $wpdb->get_row( $sql, ARRAY_A );

	return array(
		'processed' => (int) ( $row['processed'] ?? 0 ),
		'succeeded' => (int) ( $row['succeeded'] ?? 0 ),
		'failed'    => (int) ( $row['failed'] ?? 0 ),
		'bytes'     => (int) ( $row['bytes'] ?? 0 ),
		'remaining' => (int) ( $row['remaining'] ?? 0 ),
	);
}

/**
 * Persist authoritative item aggregates without changing job state.
 *
 * This remains safe after cancellation because terminal item rows are the
 * source of truth and no status/phase transition is performed.
 *
 * @param int $job_id Job ID.
 * @return array|false
 */
function yotm_job_sync_item_counters( $job_id ) {
	$counters = yotm_job_item_counters( $job_id );
	$updated  = yotm_job_update_where(
		$job_id,
		array(
			'processed' => $counters['processed'],
			'succeeded' => $counters['succeeded'],
			'failed'    => $counters['failed'],
			'bytes'     => $counters['bytes'],
		)
	);

	return $updated ? yotm_job_get_by_id( $job_id ) : false;
}

/**
 * Update a job item result.
 *
 * @param int      $item_id Item ID.
 * @param string   $status New status.
 * @param string   $error Error message.
 * @param int|null $bytes Bytes affected, or null to preserve the current value.
 * @return bool
 */
function yotm_job_update_item( $item_id, $status, $error = '', $bytes = null ) {
	global $wpdb;

	$data = array(
		'status'     => sanitize_key( $status ),
		'error'      => sanitize_text_field( $error ),
		'updated_at' => gmdate( 'Y-m-d H:i:s' ),
	);

	if ( null !== $bytes ) {
		$data['bytes'] = absint( $bytes );
	}

	return false !== $wpdb->update(
		yotm_job_table_names()['items'],
		$data,
		array( 'id' => absint( $item_id ) )
	);
}

/**
 * Count job items, optionally filtered by status.
 *
 * @param int         $job_id Job ID.
 * @param string|null $status Optional status.
 * @return int
 */
function yotm_job_count_items( $job_id, $status = null ) {
	global $wpdb;

	$tables = yotm_job_table_names();

	if ( null === $status ) {
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$tables['items']} WHERE job_id = %d", absint( $job_id ) ) );
	}

	return (int) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT COUNT(*) FROM {$tables['items']} WHERE job_id = %d AND status = %s",
			absint( $job_id ),
			sanitize_key( $status )
		)
	);
}

/**
 * Sum the current byte estimates for all items in a job.
 *
 * @param int $job_id Job ID.
 * @return int
 */
function yotm_job_sum_item_bytes( $job_id ) {
	global $wpdb;

	$tables = yotm_job_table_names();

	return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(bytes),0) FROM {$tables['items']} WHERE job_id = %d", absint( $job_id ) ) );
}

/**
 * Fetch one filtered page of job items for manifest review.
 *
 * @param int    $job_id Job ID.
 * @param int    $page Current page.
 * @param int    $per_page Items per page.
 * @param string $search Optional path/size search.
 * @return array{items:array,total:int,pages:int,page:int}
 */
function yotm_job_get_items_page( $job_id, $page = 1, $per_page = 25, $search = '' ) {
	global $wpdb;

	$tables   = yotm_job_table_names();
	$page     = max( 1, absint( $page ) );
	$per_page = max( 10, min( 100, absint( $per_page ) ) );
	$where    = 'job_id = %d';
	$args     = array( absint( $job_id ) );
	$search   = sanitize_text_field( $search );

	if ( '' !== $search ) {
		$where .= ' AND payload LIKE %s';
		$args[] = '%' . $wpdb->esc_like( $search ) . '%';
	}

	// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- The dynamic WHERE clause always contains the job ID placeholder and may include the search placeholder.
	$count_sql = $wpdb->prepare( "SELECT COUNT(*) FROM {$tables['items']} WHERE {$where}", ...$args );
	// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Prepared above; table name is plugin-owned.
	$total      = (int) $wpdb->get_var( $count_sql );
	$pages      = max( 1, (int) ceil( $total / $per_page ) );
	$page       = min( $page, $pages );
	$offset     = ( $page - 1 ) * $per_page;
	$query_args = array_merge( $args, array( $per_page, $offset ) );
	$list_sql   = $wpdb->prepare( "SELECT * FROM {$tables['items']} WHERE {$where} ORDER BY id ASC LIMIT %d OFFSET %d", ...$query_args );
	// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Prepared above; table name is plugin-owned.
	$rows  = $wpdb->get_results( $list_sql );
	$items = array();

	foreach ( $rows as $row ) {
		$payload = yotm_job_decode_payload( $row->payload );
		$items[] = array(
			'id'                 => (int) $row->id,
			'path'               => (string) ( $payload['path'] ?? '' ),
			'attachment_id'      => absint( $payload['attachment_id'] ?? 0 ),
			'size'               => (string) ( $payload['size'] ?? '' ),
			'source'             => (string) ( $payload['source'] ?? '' ),
			'ownership'          => (string) ( $payload['ownership'] ?? '' ),
			'ownership_schema'   => (string) ( $payload['ownership_schema'] ?? '' ),
			'ownership_evidence' => is_array( $payload['ownership_evidence'] ?? null ) ? $payload['ownership_evidence'] : array(),
			'status'             => (string) $row->status,
			'error'              => (string) $row->error,
			'estimated_bytes'    => absint( $payload['estimated_bytes'] ?? $row->bytes ),
			'bytes'              => (int) $row->bytes,
		);
	}

	return array(
		'items' => $items,
		'total' => $total,
		'pages' => $pages,
		'page'  => $page,
	);
}

/**
 * Build an immutable manifest hash in bounded batches.
 *
 * @param array      $job Job row.
 * @param int        $limit Hash batch size.
 * @param array|null $worker Optional worker ownership data.
 * @return array{done:bool,job:array}
 */
function yotm_job_build_manifest_batch( $job, $limit = 1000, $worker = null ) {
	global $wpdb;

	$payload = $job['payload'];
	$after   = isset( $payload['manifest_after'] ) ? (string) $payload['manifest_after'] : '';
	$digest  = isset( $payload['manifest_digest'] ) ? (string) $payload['manifest_digest'] : hash( 'sha256', 'yotm-manifest-v1' );
	$tables  = yotm_job_table_names();
	$limit   = max( 10, min( 5000, absint( $limit ) ) );
	$rows    = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT item_key,payload FROM {$tables['items']} WHERE job_id = %d AND item_key > %s ORDER BY item_key ASC LIMIT %d",
			$job['id'],
			$after,
			$limit
		)
	);

	foreach ( $rows as $row ) {
		$item_payload = yotm_job_decode_payload( $row->payload );
		if (
			'prune' === ( $job['type'] ?? '' )
			&& 'generated_file_v1' === ( $item_payload['ownership_schema'] ?? '' )
			&& function_exists( 'yotm_prune_validate_live_reference_evidence' )
		) {
			$source_fence = yotm_media_source_fence_acquire();
			if ( is_wp_error( $source_fence ) ) {
				return array(
					'done' => false,
					'job'  => yotm_job_get_by_id( $job['id'] ),
				);
			}
			try {
				$reference_check = yotm_prune_validate_live_reference_evidence( $item_payload, $item_payload['path'] ?? '' );
			} finally {
				yotm_media_source_fence_release( $source_fence );
			}
			if ( is_wp_error( $reference_check ) ) {
				// Mutable scan item: unsafe ownership is removed before immutable hashing/review.
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned pre-manifest item.
				$removed = $wpdb->delete(
					$tables['items'],
					array(
						'job_id'   => (int) $job['id'],
						'item_key' => (string) $row->item_key,
					),
					array( '%d', '%s' )
				);
				if ( 1 !== $removed ) {
					return array(
						'done' => false,
						'job'  => yotm_job_get_by_id( $job['id'] ),
					);
				}
				$after = (string) $row->item_key;
				continue;
			}
		}//end if
		$digest = hash( 'sha256', $digest . ':' . $row->item_key . ':' . hash( 'sha256', (string) $row->payload ) );
		$after  = (string) $row->item_key;
	}//end foreach

	$payload['manifest_after']  = $after;
	$payload['manifest_digest'] = $digest;
	$done                       = count( $rows ) < $limit;
	$fields                     = array( 'payload' => $payload );

	if ( $done ) {
		$fields['manifest_hash']         = $digest;
		$payload['reference_generation'] = defined( 'YOTM_MEDIA_REFERENCE_GENERATION' ) ? YOTM_MEDIA_REFERENCE_GENERATION : 0;
		$fields['payload']               = $payload;
	}

	$updated = is_array( $worker )
		? yotm_job_worker_update( $worker, $fields )
		: yotm_job_update( $job['id'], $fields );

	return array(
		'done' => $done && $updated,
		'job'  => yotm_job_get_by_id( $job['id'] ),
	);
}

/**
 * Return recent item errors for reporting.
 *
 * @param int $job_id Job ID.
 * @param int $limit Maximum errors.
 * @return array
 */
function yotm_job_get_error_sample( $job_id, $limit = 20 ) {
	global $wpdb;

	$tables = yotm_job_table_names();
	$rows   = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT item_key,payload,error FROM {$tables['items']} WHERE job_id = %d AND status = 'failed' ORDER BY id DESC LIMIT %d",
			absint( $job_id ),
			max( 1, min( 100, absint( $limit ) ) )
		)
	);
	$out    = array();

	foreach ( $rows as $row ) {
		$payload = yotm_job_decode_payload( $row->payload );
		$out[]   = array(
			'item_key' => (string) $row->item_key,
			'path'     => (string) ( $payload['path'] ?? '' ),
			'id'       => absint( $payload['attachment_id'] ?? 0 ),
			'error'    => (string) $row->error,
		);
	}

	return $out;
}

/**
 * Delete a job and its item queue.
 *
 * @param int $job_id Job ID.
 * @return void
 */
function yotm_job_delete( $job_id ) {
	global $wpdb;

	$tables = yotm_job_table_names();
	$wpdb->delete( $tables['items'], array( 'job_id' => absint( $job_id ) ) );
	$wpdb->delete( $tables['jobs'], array( 'id' => absint( $job_id ) ) );
}

/**
 * Delete expired jobs and their queues.
 *
 * @return true|WP_Error
 */
function yotm_cleanup_expired_jobs() {
	global $wpdb;

	$ready = yotm_job_storage_ready();
	if ( is_wp_error( $ready ) ) {
		return $ready;
	}

	$tables              = yotm_job_table_names();
	$active_statuses     = yotm_job_active_statuses();
	$active_placeholders = implode( ',', array_fill( 0, count( $active_statuses ), '%s' ) );
	$active_args         = array_merge( $active_statuses, array( gmdate( 'Y-m-d H:i:s' ) ) );
	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Table and status placeholders are plugin-owned; arguments are assembled above.
	$sql = $wpdb->prepare(
		"SELECT id FROM {$tables['jobs']} WHERE status IN ({$active_placeholders}) AND expires_at < %s LIMIT 100",
		...$active_args
	);
	// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Expiry candidates must reflect current persistent state; prepared above.
	$active_ids  = $wpdb->get_col( $sql );
	$query_error = yotm_job_last_database_error();
	if ( is_wp_error( $query_error ) ) {
		return $query_error;
	}

	foreach ( $active_ids as $job_id ) {
		$job = yotm_job_get_by_id( (int) $job_id );
		if ( $job ) {
			$expired = yotm_job_expire_if_inactive( $job );
			if ( is_wp_error( $expired ) ) {
				return $expired;
			}
		}
	}

	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is plugin-owned.
	$sql = $wpdb->prepare(
		"SELECT id FROM {$tables['jobs']}
		WHERE status IN ('completed','cancelled','expired') AND expires_at < %s LIMIT 100",
		gmdate( 'Y-m-d H:i:s' )
	);
	// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Audit-retention cleanup must reflect current persistent state; prepared above.
	$terminal_ids = $wpdb->get_col( $sql );
	$query_error  = yotm_job_last_database_error();
	if ( is_wp_error( $query_error ) ) {
		return $query_error;
	}

	foreach ( $terminal_ids as $job_id ) {
		$lock_name = yotm_job_worker_lock_name( (int) $job_id );
		$lock      = yotm_job_acquire_named_lock( $lock_name );
		if ( is_wp_error( $lock ) ) {
			return $lock;
		}

		if ( ! $lock ) {
			continue;
		}

		try {
			yotm_job_delete( (int) $job_id );
		} finally {
			yotm_job_release_named_lock( $lock_name );
		}
	}

	return true;
}
add_action( 'yotm_cleanup_jobs', 'yotm_cleanup_expired_jobs' );

/**
 * Project a recommendation result for public delivery.
 *
 * @param mixed         $result Persisted recommendation result.
 * @param callable|null $projector Optional projector override for tests.
 * @return array|null
 */
function yotm_job_public_recommendation_result( $result, $projector = null ) {
	if ( null === $projector ) {
		if ( ! function_exists( 'yotm_recommendation_result_for_response' ) ) {
			return null;
		}

		$projector = 'yotm_recommendation_result_for_response';
	}

	if ( ! is_callable( $projector ) ) {
		return null;
	}

	$projected = call_user_func( $projector, $result );

	return is_array( $projected ) ? $projected : null;
}

/**
 * Return safe job state for browser resume.
 *
 * @param array $job Job row.
 * @return array
 */
function yotm_job_public_data( $job ) {
	$payload = $job['payload'];
	$context = array();
	$allowed = array(
		'scope',
		'scope_label',
		'only_missing',
		'force_all',
		'scan_processed',
		'scan_total_attachments',
		'sample',
		'keep',
		'remove',
		'scan_base_label',
		'scan_base_labels',
		'scan_subpaths',
		'orphan_summary',
		'result',
		'scan_phase',
		'estimated_bytes',
		'disk_entries_processed',
		'selection_done',
		'selection_meta_after',
		'selection_meta_max',
		'selection_scanned',
		'selection_matched',
		'selector',
		'cancelled_at',
	);

	foreach ( $allowed as $key ) {
		if ( array_key_exists( $key, $payload ) ) {
			$context[ $key ] = $payload[ $key ];
		}
	}

	if (
		'recommendation' === ( $job['type'] ?? '' )
		&& 'completed' === ( $job['status'] ?? '' )
	) {
		$projected_result = yotm_job_public_recommendation_result( $context['result'] ?? array() );
		if ( is_array( $projected_result ) ) {
			$context['result'] = $projected_result;
		} else {
			unset( $context['result'] );
		}
	}

	$public = array(
		'token'         => $job['token'],
		'type'          => $job['type'],
		'status'        => $job['status'],
		'phase'         => $job['phase'],
		'manifest_hash' => $job['manifest_hash'],
		'total'         => $job['total'],
		'processed'     => $job['processed'],
		'succeeded'     => $job['succeeded'],
		'failed'        => $job['failed'],
		'bytes'         => $job['bytes'],
		'bytes_human'   => size_format( $job['bytes'] ),
		'created_at'    => $job['created_at'],
		'updated_at'    => $job['updated_at'],
		'expires_at'    => $job['expires_at'],
		'context'       => $context,
	);

	if ( 'regenerate' === ( $job['type'] ?? '' ) && 'attached_meta_v2' === ( $context['selector'] ?? '' ) ) {
		$public['total_known'] = ! empty( $context['selection_done'] );
	}

	return $public;
}

/**
 * Return recent jobs owned by the current user on this site.
 *
 * @param int $limit Maximum rows.
 * @return array[]|WP_Error
 */
function yotm_job_get_recent_for_current_user( $limit = 10 ) {
	global $wpdb;

	$ready = yotm_job_storage_ready();
	if ( is_wp_error( $ready ) ) {
		return $ready;
	}

	$tables      = yotm_job_table_names();
	$rows        = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT * FROM {$tables['jobs']} WHERE blog_id = %d AND user_id = %d ORDER BY id DESC LIMIT %d",
			get_current_blog_id(),
			get_current_user_id(),
			max( 1, min( 25, absint( $limit ) ) )
		)
	);
	$query_error = yotm_job_last_database_error();
	if ( is_wp_error( $query_error ) ) {
		return $query_error;
	}

	$out = array();

	foreach ( $rows as $row ) {
		$job = yotm_job_normalize_row( $row );
		if ( $job ) {
			$job = yotm_job_expire_if_inactive( $job );
		}
		if ( is_wp_error( $job ) ) {
			return $job;
		}
		if ( $job ) {
			$out[] = yotm_job_public_data( $job );
		}
	}

	return $out;
}

/**
 * Mark a job stopped while retaining its audit data.
 *
 * @param array $job Job row.
 * @return array|false|WP_Error
 */
function yotm_job_cancel( $job ) {
	if ( ! is_array( $job ) || empty( $job['id'] ) ) {
		return false;
	}

	if ( in_array( $job['status'], array( 'completed', 'cancelled', 'expired' ), true ) ) {
		return $job;
	}

	$lock_name = yotm_job_worker_lock_name( $job['id'] );
	$locked    = yotm_job_acquire_named_lock( $lock_name );
	if ( is_wp_error( $locked ) ) {
		return $locked;
	}
	if ( ! $locked ) {
		return new WP_Error( 'yotm_job_cancel_busy', __( 'The current attachment transaction is still finishing. Retry stop shortly.', 'thumbnail-manager' ) );
	}

	try {
		if ( yotm_job_has_recovery_journals( $job['id'] ) ) {
			return new WP_Error( 'yotm_job_cancel_busy', __( 'The current attachment transaction still requires recovery. Resume the job, then retry stop.', 'thumbnail-manager' ) );
		}

		$payload                 = $job['payload'];
		$payload['cancelled_at'] = gmdate( 'c' );
		$cancelled               = yotm_job_transition(
			$job['id'],
			yotm_job_active_statuses(),
			array(),
			array(
				'payload'                 => $payload,
				'status'                  => 'cancelled',
				'phase'                   => 'cancelled',
				'worker_token'            => '',
				'worker_lease_expires_at' => null,
				'expires_at'              => gmdate( 'Y-m-d H:i:s', time() + YOTM_JOB_AUDIT_RETENTION_SECONDS ),
			)
		);
	} finally {
		yotm_job_release_named_lock( $lock_name );
	}//end try

	if ( $cancelled ) {
		return $cancelled;
	}

	$current = yotm_job_get_by_id( $job['id'] );

	return is_array( $current ) && in_array( $current['status'], array( 'completed', 'cancelled', 'expired' ), true ) ? $current : false;
}

/**
 * AJAX job status for safe browser resume.
 */
function yotm_job_ajax_status() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'msg' => __( 'No permission.', 'thumbnail-manager' ) ), 403 );
	}

	check_ajax_referer( 'yotm_prune_nonce', 'nonce' );
	$token = sanitize_text_field( wp_unslash( $_POST['token'] ?? '' ) );
	$job   = yotm_job_get( $token );

	if ( is_wp_error( $job ) ) {
		wp_send_json_error( array( 'msg' => $job->get_error_message() ), 404 );
	}

	wp_send_json_success( yotm_job_public_data( $job ) );
}
add_action( 'wp_ajax_yotm_job_status', 'yotm_job_ajax_status' );

/**
 * AJAX paginated manifest items for a job owned by the current user.
 */
function yotm_job_ajax_items() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'msg' => __( 'No permission.', 'thumbnail-manager' ) ), 403 );
	}

	check_ajax_referer( 'yotm_prune_nonce', 'nonce' );
	$token = sanitize_text_field( wp_unslash( $_POST['token'] ?? '' ) );
	$job   = yotm_job_get( $token );

	if ( is_wp_error( $job ) ) {
		wp_send_json_error( array( 'msg' => $job->get_error_message() ), 404 );
	}

	if ( 'prune' !== $job['type'] ) {
		wp_send_json_error( array( 'msg' => __( 'Manifest items are only available for prune jobs.', 'thumbnail-manager' ) ), 400 );
	}

	$page     = isset( $_POST['page'] ) ? absint( wp_unslash( $_POST['page'] ) ) : 1;
	$per_page = isset( $_POST['per_page'] ) ? absint( wp_unslash( $_POST['per_page'] ) ) : 25;
	$search   = sanitize_text_field( wp_unslash( $_POST['search'] ?? '' ) );
	$data     = yotm_job_get_items_page( $job['id'], $page, $per_page, $search );

	wp_send_json_success( $data );
}
add_action( 'wp_ajax_yotm_job_items', 'yotm_job_ajax_items' );

/**
 * AJAX recent jobs for the active-job banner and history table.
 */
function yotm_job_ajax_recent() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'msg' => __( 'No permission.', 'thumbnail-manager' ) ), 403 );
	}

	check_ajax_referer( 'yotm_prune_nonce', 'nonce' );
	$jobs = yotm_job_get_recent_for_current_user( 10 );

	if ( is_wp_error( $jobs ) ) {
		wp_send_json_error( array( 'msg' => $jobs->get_error_message() ), 503 );
	}

	wp_send_json_success( array( 'jobs' => $jobs ) );
}
add_action( 'wp_ajax_yotm_jobs_recent', 'yotm_job_ajax_recent' );

/**
 * AJAX server-side stop that retains audit data.
 */
function yotm_job_ajax_cancel() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'msg' => __( 'No permission.', 'thumbnail-manager' ) ), 403 );
	}

	check_ajax_referer( 'yotm_prune_nonce', 'nonce' );
	$token = sanitize_text_field( wp_unslash( $_POST['token'] ?? '' ) );
	$job   = yotm_job_get( $token );

	if ( is_wp_error( $job ) ) {
		wp_send_json_error( array( 'msg' => $job->get_error_message() ), 404 );
	}

	$job = yotm_job_cancel( $job );
	if ( is_wp_error( $job ) ) {
		wp_send_json_error(
			array(
				'msg'            => $job->get_error_message(),
				'retry_after_ms' => 250,
			),
			409
		);
	}
	wp_send_json_success(
		array(
			'cancelled' => true,
			'job'       => yotm_job_public_data( $job ),
		)
	);
}
add_action( 'wp_ajax_yotm_job_cancel', 'yotm_job_ajax_cancel' );
