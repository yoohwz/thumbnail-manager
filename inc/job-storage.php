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
	define( 'YOTM_JOB_DB_VERSION', '1.0.1' );
}

/**
 * Return the persistent job table names for the current site.
 *
 * @return array{jobs:string,items:string}
 */
function yotm_job_table_names() {
	global $wpdb;

	return array(
		'jobs'  => $wpdb->prefix . 'yotm_jobs',
		'items' => $wpdb->prefix . 'yotm_job_items',
	);
}

/**
 * Install or update the job tables.
 */
function yotm_install_job_tables() {
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
		manifest_hash char(64) NOT NULL DEFAULT '',
		payload longtext NULL,
		total bigint(20) unsigned NOT NULL DEFAULT 0,
		processed bigint(20) unsigned NOT NULL DEFAULT 0,
		succeeded bigint(20) unsigned NOT NULL DEFAULT 0,
		failed bigint(20) unsigned NOT NULL DEFAULT 0,
		bytes bigint(20) unsigned NOT NULL DEFAULT 0,
		cursor_id bigint(20) unsigned NOT NULL DEFAULT 0,
		max_id bigint(20) unsigned NOT NULL DEFAULT 0,
		created_at datetime NOT NULL,
		updated_at datetime NOT NULL,
		expires_at datetime NOT NULL,
		PRIMARY KEY  (id),
		UNIQUE KEY token (token),
		KEY owner_status (blog_id,user_id,type,status),
		KEY expires_at (expires_at)
	) {$charset_collate};";
	$items_sql       = "CREATE TABLE {$tables['items']} (
		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		job_id bigint(20) unsigned NOT NULL,
		item_key char(64) NOT NULL,
		status varchar(24) NOT NULL DEFAULT 'queued',
		payload longtext NULL,
		error text NULL,
		bytes bigint(20) unsigned NOT NULL DEFAULT 0,
		created_at datetime NOT NULL,
		updated_at datetime NOT NULL,
		PRIMARY KEY  (id),
		UNIQUE KEY job_item (job_id,item_key),
		KEY job_status (job_id,status,id)
	) {$charset_collate};";

	dbDelta( $jobs_sql );
	dbDelta( $items_sql );

	if ( ! yotm_job_tables_exist() ) {
		return new WP_Error( 'yotm_job_tables_missing', __( 'Could not install the persistent job tables.', 'thumbnail-manager' ) );
	}

	update_option( 'yotm_job_db_version', YOTM_JOB_DB_VERSION, false );

	if ( ! wp_next_scheduled( 'yotm_cleanup_jobs' ) ) {
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'yotm_cleanup_jobs' );
	}

	return true;
}

/**
 * Check that both persistent storage tables exist.
 *
 * @return bool
 */
function yotm_job_tables_exist() {
	global $wpdb;

	foreach ( yotm_job_table_names() as $table ) {
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
		if ( $table !== $found ) {
			return false;
		}
	}

	return true;
}

/**
 * Install tables for existing activations after an upgrade.
 */
function yotm_maybe_install_job_tables() {
	if ( YOTM_JOB_DB_VERSION !== get_option( 'yotm_job_db_version' ) || ! yotm_job_tables_exist() ) {
		return yotm_install_job_tables();
	}

	return true;
}
add_action( 'plugins_loaded', 'yotm_maybe_install_job_tables', 5 );

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
		'id'            => (int) $row->id,
		'token'         => (string) $row->token,
		'blog_id'       => (int) $row->blog_id,
		'user_id'       => (int) $row->user_id,
		'type'          => (string) $row->type,
		'status'        => (string) $row->status,
		'phase'         => (string) $row->phase,
		'manifest_hash' => (string) $row->manifest_hash,
		'payload'       => yotm_job_decode_payload( $row->payload ),
		'total'         => (int) $row->total,
		'processed'     => (int) $row->processed,
		'succeeded'     => (int) $row->succeeded,
		'failed'        => (int) $row->failed,
		'bytes'         => (int) $row->bytes,
		'cursor'        => (int) $row->cursor_id,
		'max_id'        => (int) $row->max_id,
		'created_at'    => (string) $row->created_at,
		'updated_at'    => (string) $row->updated_at,
		'expires_at'    => (string) $row->expires_at,
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
 * Fetch a job token owned by the current user and site.
 *
 * @param string $token Job token.
 * @return array|WP_Error
 */
function yotm_job_get( $token ) {
	global $wpdb;

	$tables = yotm_job_table_names();
	$token  = sanitize_text_field( (string) $token );
	$row    = $wpdb->get_row(
		$wpdb->prepare(
			"SELECT * FROM {$tables['jobs']} WHERE token = %s AND blog_id = %d AND user_id = %d",
			$token,
			get_current_blog_id(),
			get_current_user_id()
		)
	);
	$job    = yotm_job_normalize_row( $row );

	if ( false === $job ) {
		return new WP_Error( 'yotm_job_missing', __( 'Job not found or it belongs to another user.', 'thumbnail-manager' ) );
	}

	if ( strtotime( $job['expires_at'] . ' UTC' ) < time() && in_array( $job['status'], yotm_job_active_statuses(), true ) ) {
		yotm_job_update(
			$job['id'],
			array(
				'status' => 'expired',
				'phase'  => 'expired',
			)
		);

		return new WP_Error( 'yotm_job_expired', __( 'This job has expired. Please start a new scan.', 'thumbnail-manager' ) );
	}

	return $job;
}

/**
 * Find an active job owned by the current user.
 *
 * @param string $type Job type.
 * @return array|false
 */
function yotm_job_get_active_for_current_user( $type ) {
	global $wpdb;

	$tables       = yotm_job_table_names();
	$statuses     = yotm_job_active_statuses();
	$placeholders = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );
	$args         = array_merge(
		array( get_current_blog_id(), get_current_user_id(), sanitize_key( $type ) ),
		$statuses,
		array( gmdate( 'Y-m-d H:i:s' ) )
	);
	$sql          = $wpdb->prepare(
		"SELECT * FROM {$tables['jobs']}
		WHERE blog_id = %d AND user_id = %d AND type = %s
		AND status IN ({$placeholders}) AND expires_at >= %s
		ORDER BY id DESC LIMIT 1",
		...$args
	);

	// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Prepared above; table names are plugin-owned.
	return yotm_job_normalize_row( $wpdb->get_row( $sql ) );
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

	$installed = yotm_maybe_install_job_tables();
	if ( is_wp_error( $installed ) ) {
		return $installed;
	}
	yotm_cleanup_expired_jobs();

	$type      = sanitize_key( $type );
	$exclusive = isset( $args['exclusive'] ) ? (bool) $args['exclusive'] : in_array( $type, array( 'prune', 'regenerate' ), true );
	$lock_name = '';
	$active    = yotm_job_get_active_for_current_user( $type );

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
		'token'         => $token,
		'blog_id'       => get_current_blog_id(),
		'user_id'       => get_current_user_id(),
		'type'          => $type,
		'status'        => sanitize_key( $args['status'] ?? 'running' ),
		'phase'         => sanitize_key( $args['phase'] ?? '' ),
		'manifest_hash' => '',
		'payload'       => wp_json_encode( is_array( $payload ) ? $payload : array() ),
		'total'         => absint( $args['total'] ?? 0 ),
		'processed'     => 0,
		'succeeded'     => 0,
		'failed'        => 0,
		'bytes'         => 0,
		'cursor_id'     => absint( $args['cursor'] ?? 0 ),
		'max_id'        => absint( $args['max_id'] ?? 0 ),
		'created_at'    => $now,
		'updated_at'    => $now,
		'expires_at'    => gmdate( 'Y-m-d H:i:s', time() + $ttl ),
	);
	$insert       = $wpdb->insert( $tables['jobs'], $data );
	$insert_error = $wpdb->last_error;

	if ( '' !== $lock_name ) {
		$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock_name ) );
	}

	if ( false === $insert ) {
		return new WP_Error( 'yotm_job_create_failed', __( 'Could not create the persistent job.', 'thumbnail-manager' ), array( 'database_error' => $insert_error ) );
	}

	return yotm_job_get_by_id( (int) $wpdb->insert_id );
}

/**
 * Find the current site-wide destructive job lock.
 *
 * @return array|false
 */
function yotm_job_find_destructive_lock() {
	global $wpdb;

	$tables       = yotm_job_table_names();
	$statuses     = yotm_job_active_statuses();
	$placeholders = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );
	$args         = array_merge( array( get_current_blog_id(), 'prune', 'regenerate' ), $statuses, array( gmdate( 'Y-m-d H:i:s' ) ) );
	$sql          = $wpdb->prepare(
		"SELECT * FROM {$tables['jobs']}
		WHERE blog_id = %d AND type IN (%s,%s)
		AND status IN ({$placeholders}) AND expires_at >= %s
		ORDER BY id DESC LIMIT 1",
		...$args
	);

	// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Prepared above; table names are plugin-owned.
	return yotm_job_normalize_row( $wpdb->get_row( $sql ) );
}

/**
 * Update safe job columns.
 *
 * @param int   $job_id Job ID.
 * @param array $fields Fields to update.
 * @return bool
 */
function yotm_job_update( $job_id, $fields ) {
	global $wpdb;

	$allowed = array( 'status', 'phase', 'manifest_hash', 'payload', 'total', 'processed', 'succeeded', 'failed', 'bytes', 'cursor', 'max_id', 'expires_at' );
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
		} else {
			$data[ $key ] = sanitize_text_field( $fields[ $key ] );
		}
	}

	if ( empty( $data ) ) {
		return true;
	}

	$data['updated_at'] = gmdate( 'Y-m-d H:i:s' );

	return false !== $wpdb->update( yotm_job_table_names()['jobs'], $data, array( 'id' => absint( $job_id ) ) );
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
		AND ((status = 'scanning' AND phase IN ('metadata','disk'))
		OR (status = 'running' AND phase = 'regenerate'))",
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
			AND jobs.status = 'scanning' AND jobs.phase IN ('metadata','disk') LIMIT 1",
			absint( $job_id ),
			$item_key
		)
	);

	if ( ! $row ) {
		return false;
	}

	$current = yotm_job_decode_payload( $row->payload );
	$refs    = array();

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

	if ( empty( $current['remove_metadata'] ) && ! empty( $payload['remove_metadata'] ) ) {
		$current['remove_metadata'] = 1;
	}

	$sql = $wpdb->prepare(
		"UPDATE {$tables['items']} items
		INNER JOIN {$tables['jobs']} jobs ON jobs.id = items.job_id
		SET items.payload = %s, items.updated_at = %s
		WHERE items.id = %d AND jobs.status = 'scanning' AND jobs.phase IN ('metadata','disk')",
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

	return array(
		'id'       => (int) $row->id,
		'job_id'   => (int) $row->job_id,
		'item_key' => (string) $row->item_key,
		'status'   => (string) $row->status,
		'payload'  => yotm_job_decode_payload( $row->payload ),
		'error'    => (string) $row->error,
		'bytes'    => (int) $row->bytes,
	);
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
		$out[] = array(
			'id'       => (int) $row->id,
			'job_id'   => (int) $row->job_id,
			'item_key' => (string) $row->item_key,
			'status'   => (string) $row->status,
			'payload'  => yotm_job_decode_payload( $row->payload ),
			'error'    => (string) $row->error,
			'bytes'    => (int) $row->bytes,
		);
	}

	return $out;
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
			'id'              => (int) $row->id,
			'path'            => (string) ( $payload['path'] ?? '' ),
			'attachment_id'   => absint( $payload['attachment_id'] ?? 0 ),
			'size'            => (string) ( $payload['size'] ?? '' ),
			'source'          => (string) ( $payload['source'] ?? '' ),
			'status'          => (string) $row->status,
			'error'           => (string) $row->error,
			'estimated_bytes' => absint( $payload['estimated_bytes'] ?? $row->bytes ),
			'bytes'           => (int) $row->bytes,
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
 * @param array $job Job row.
 * @param int   $limit Hash batch size.
 * @return array{done:bool,job:array}
 */
function yotm_job_build_manifest_batch( $job, $limit = 1000 ) {
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
		$digest = hash( 'sha256', $digest . ':' . $row->item_key . ':' . hash( 'sha256', (string) $row->payload ) );
		$after  = (string) $row->item_key;
	}

	$payload['manifest_after']  = $after;
	$payload['manifest_digest'] = $digest;
	$done                       = count( $rows ) < $limit;
	$fields                     = array( 'payload' => $payload );

	if ( $done ) {
		$fields['manifest_hash'] = $digest;
	}

	yotm_job_update( $job['id'], $fields );

	return array(
		'done' => $done,
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
 */
function yotm_cleanup_expired_jobs() {
	global $wpdb;

	$tables = yotm_job_table_names();
	$ids    = $wpdb->get_col(
		$wpdb->prepare( "SELECT id FROM {$tables['jobs']} WHERE expires_at < %s LIMIT 100", gmdate( 'Y-m-d H:i:s' ) )
	);

	foreach ( $ids as $job_id ) {
		yotm_job_delete( (int) $job_id );
	}
}
add_action( 'yotm_cleanup_jobs', 'yotm_cleanup_expired_jobs' );

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
		'cancelled_at',
	);

	foreach ( $allowed as $key ) {
		if ( array_key_exists( $key, $payload ) ) {
			$context[ $key ] = $payload[ $key ];
		}
	}

	return array(
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
}

/**
 * Return recent jobs owned by the current user on this site.
 *
 * @param int $limit Maximum rows.
 * @return array[]
 */
function yotm_job_get_recent_for_current_user( $limit = 10 ) {
	global $wpdb;

	$tables = yotm_job_table_names();
	$rows   = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT * FROM {$tables['jobs']} WHERE blog_id = %d AND user_id = %d ORDER BY id DESC LIMIT %d",
			get_current_blog_id(),
			get_current_user_id(),
			max( 1, min( 25, absint( $limit ) ) )
		)
	);
	$out    = array();

	foreach ( $rows as $row ) {
		$job = yotm_job_normalize_row( $row );
		if ( $job && in_array( $job['status'], yotm_job_active_statuses(), true ) && strtotime( $job['expires_at'] . ' UTC' ) < time() ) {
			yotm_job_update(
				$job['id'],
				array(
					'status'     => 'expired',
					'phase'      => 'expired',
					'expires_at' => gmdate( 'Y-m-d H:i:s', time() + 7 * DAY_IN_SECONDS ),
				)
			);
			$job = yotm_job_get_by_id( $job['id'] );
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
 * @return array|false
 */
function yotm_job_cancel( $job ) {
	if ( ! is_array( $job ) || empty( $job['id'] ) ) {
		return false;
	}

	if ( in_array( $job['status'], array( 'completed', 'cancelled', 'expired' ), true ) ) {
		return $job;
	}

	$payload                 = $job['payload'];
	$payload['cancelled_at'] = gmdate( 'c' );
	yotm_job_update(
		$job['id'],
		array(
			'payload'    => $payload,
			'status'     => 'cancelled',
			'phase'      => 'cancelled',
			'expires_at' => gmdate( 'Y-m-d H:i:s', time() + 7 * DAY_IN_SECONDS ),
		)
	);

	return yotm_job_get_by_id( $job['id'] );
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
	wp_send_json_success( array( 'jobs' => yotm_job_get_recent_for_current_user( 10 ) ) );
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
	wp_send_json_success(
		array(
			'cancelled' => true,
			'job'       => yotm_job_public_data( $job ),
		)
	);
}
add_action( 'wp_ajax_yotm_job_cancel', 'yotm_job_ajax_cancel' );
