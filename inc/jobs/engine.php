<?php
/**
 * Persistent job and worker lifecycle.
 *
 * @package Thumbnail_Manager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'YOTM_JOB_WORKER_LEASE_SECONDS' ) ) {
	define( 'YOTM_JOB_WORKER_LEASE_SECONDS', 2 * MINUTE_IN_SECONDS );
}

if ( ! defined( 'YOTM_JOB_AUDIT_RETENTION_SECONDS' ) ) {
	define( 'YOTM_JOB_AUDIT_RETENTION_SECONDS', 7 * DAY_IN_SECONDS );
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
	return yotm_database_acquire_named_lock( $lock_name );
}

/**
 * Release a named MySQL lock owned by this connection.
 *
 * @param string $lock_name Lock name.
 * @return bool
 */
function yotm_job_release_named_lock( $lock_name ) {
	return yotm_database_release_named_lock( $lock_name );
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
		$current = yotm_job_get_by_id( $job['id'] );
		if ( ! is_array( $current ) ) {
			return new WP_Error( 'yotm_job_missing', __( 'Job not found.', 'thumbnail-manager' ) );
		}
		if (
			! in_array( $current['status'] ?? '', yotm_job_active_statuses(), true )
			|| strtotime( ( $current['expires_at'] ?? '' ) . ' UTC' ) >= time()
		) {
			return $current;
		}
		$job = $current;

		if ( yotm_job_has_recovery_journals( $job['id'] ) ) {
			$payload                             = $job['payload'];
			$payload['recovery_only']            = 1;
			$payload['recovery_terminal_status'] = 'cancelled' === ( $payload['recovery_terminal_status'] ?? '' ) ? 'cancelled' : 'expired';
			$updated                             = yotm_job_update(
				$job['id'],
				array(
					'payload'    => $payload,
					'expires_at' => gmdate( 'Y-m-d H:i:s', time() + YOTM_JOB_WORKER_LEASE_SECONDS ),
				)
			);
			if ( ! $updated ) {
				return new WP_Error( 'yotm_job_recovery_intent_failed', __( 'Could not persist the expired recovery-only state.', 'thumbnail-manager' ) );
			}
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
		$current = yotm_job_get_by_id( $job['id'] );
		if ( ! is_array( $current ) ) {
			return new WP_Error( 'yotm_job_missing', __( 'Job not found.', 'thumbnail-manager' ) );
		}
		if ( in_array( $current['status'], array( 'completed', 'cancelled', 'expired' ), true ) ) {
			return $current;
		}
		$job = $current;

		if ( yotm_job_has_recovery_journals( $job['id'] ) ) {
			$payload                             = $job['payload'];
			$payload['recovery_only']            = 1;
			$payload['recovery_terminal_status'] = 'cancelled';
			$payload['cancel_requested_at']      = gmdate( 'c' );
			$updated                             = yotm_job_update(
				$job['id'],
				array(
					'payload'    => $payload,
					'expires_at' => gmdate( 'Y-m-d H:i:s', time() + YOTM_JOB_WORKER_LEASE_SECONDS ),
				)
			);
			if ( ! $updated ) {
				return new WP_Error( 'yotm_job_recovery_intent_failed', __( 'Could not persist the cancellation recovery-only state.', 'thumbnail-manager' ) );
			}
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
 * Return the terminal state requested after recovery-only processing.
 *
 * @param array $job Job row.
 * @return string
 */
function yotm_job_recovery_terminal_status( $job ) {
	$status = sanitize_key( $job['payload']['recovery_terminal_status'] ?? '' );

	return 'cancelled' === $status ? 'cancelled' : 'expired';
}
