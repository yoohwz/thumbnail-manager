<?php
/**
 * Persistent job-item queue and result storage.
 *
 * @package Thumbnail_Manager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
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
			'attachment_id'     => absint( $ref['attachment_id'] ?? 0 ),
			'size'              => sanitize_key( $ref['size'] ?? '' ),
			'filename'          => sanitize_file_name( $ref['filename'] ?? '' ),
			'selection_meta_id' => absint( $ref['selection_meta_id'] ?? 0 ),
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
			'attachment_id'     => $attachment_id,
			'size'              => $size,
			'filename'          => $filename,
			'mime'              => sanitize_mime_type( $proof['mime'] ?? '' ),
			'selection'         => $selection,
			'selection_meta_id' => absint( $proof['selection_meta_id'] ?? 0 ),
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
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Exact existing-key lookup must bypass object caching.
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
 * @param bool  $recovery_only Claim only items containing a persisted recovery journal.
 * @return array[]|WP_Error
 */
function yotm_job_claim_items( $worker, $limit = 100, $recovery_only = false ) {
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

	$job_where  .= ' AND jobs.expires_at >= %s';
	$job_args[]  = $now;
	$args        = array( $claim_token, $claim_expires, $now, absint( $worker['job_id'] ?? 0 ), $now );
	$journal_sql = '';
	if ( $recovery_only ) {
		$journal_sql = ' AND (payload LIKE %s OR payload LIKE %s)';
		$args[]      = '%' . $wpdb->esc_like( '"prune_operation_journal_v1"' ) . '%';
		$args[]      = '%' . $wpdb->esc_like( '"regeneration_journal"' ) . '%';
	}
	$args = array_merge( $args, $job_args, array( $limit ) );
	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Tables and worker predicates are plugin-owned; arguments are assembled above.
	$sql = $wpdb->prepare(
		"UPDATE {$tables['items']}
		SET status = 'processing', claim_token = %s, claim_generation = claim_generation + 1,
		claim_expires_at = %s, attempts = attempts + 1, updated_at = %s
		WHERE job_id = %d
		AND (status = 'queued' OR (status = 'processing'
			AND (claim_token = '' OR claim_expires_at IS NULL OR claim_expires_at < %s)))
		{$journal_sql}
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
	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is plugin-owned.
	$sql = $wpdb->prepare(
		"SELECT 1 FROM {$tables['items']} WHERE job_id = %d AND status IN ('queued','processing') LIMIT 1",
		absint( $job_id )
	);
	// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Existing indexed job/status lookup; prepared above.
	return (bool) $wpdb->get_var( $sql );
}

/**
 * Return whether an in-flight item has a recoverable media journal.
 *
 * A journal may be processing or requeued after a retryable storage/fence
 * failure. The rare cancel/expiry boundary scans plugin-owned JSON keys and
 * deliberately treats a false positive as recovery-required.
 *
 * @param int $job_id Job ID.
 * @return bool
 */
function yotm_job_has_recovery_journals( $job_id ) {
	global $wpdb;

	$tables           = yotm_job_table_names();
	$wpdb->last_error = '';
	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is plugin-owned.
	$sql = $wpdb->prepare(
		"SELECT 1 FROM {$tables['items']} WHERE job_id = %d AND status IN ('queued','processing')
		AND (payload LIKE %s OR payload LIKE %s) LIMIT 1",
		absint( $job_id ),
		'%' . $wpdb->esc_like( '"prune_operation_journal_v1"' ) . '%',
		'%' . $wpdb->esc_like( '"regeneration_journal"' ) . '%'
	);
	// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Rare recovery boundary must include requeued journals; plugin-owned JSON keys fail closed on false positives.
	$found = $wpdb->get_var( $sql );

	return '' !== (string) $wpdb->last_error || (bool) $found;
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
