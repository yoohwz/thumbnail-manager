<?php
/**
 * Cross-process smoke worker for destructive prune liveness and takeover.
 *
 * This file is orchestrated by tests/run-job-concurrency-smoke.sh.
 *
 * @package Thumbnail_Manager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Fail a concurrency smoke assertion.
 *
 * @param bool   $condition Condition.
 * @param string $message Failure message.
 * @throws RuntimeException When the condition fails.
 */
function yotm_concurrency_smoke_assert( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( esc_html( $message ) );
	}
}

/**
 * Read the shared smoke state.
 *
 * @param string $path State path.
 * @return array
 * @throws RuntimeException When state cannot be read.
 */
function yotm_concurrency_smoke_read_state( $path ) {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local temporary coordination file, not a URL.
	$state = json_decode( (string) file_get_contents( $path ), true );
	if ( ! is_array( $state ) ) {
		throw new RuntimeException( 'Concurrency smoke state is unavailable.' );
	}

	return $state;
}

/**
 * Persist the shared smoke state.
 *
 * @param string $path State path.
 * @param array  $state State data.
 * @throws RuntimeException When state cannot be written.
 */
function yotm_concurrency_smoke_write_state( $path, $state ) {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Local temporary coordination file owned by the smoke runner.
	if ( false === file_put_contents( $path, wp_json_encode( $state ) ) ) {
		throw new RuntimeException( 'Concurrency smoke state could not be written.' );
	}
}

/**
 * Return whether the smoke attachment still references its generated size.
 *
 * @param int $attachment_id Attachment ID.
 * @return bool
 */
function yotm_concurrency_smoke_has_metadata_reference( $attachment_id ) {
	$metadata = wp_get_attachment_metadata( $attachment_id );

	return is_array( $metadata ) && isset( $metadata['sizes']['yotm_smoke'] );
}

$smoke_role = sanitize_key( $args[0] ?? '' );
$state_path = (string) ( $args[1] ?? '' );
$admin_ids  = get_users(
	array(
		'role'   => 'administrator',
		'fields' => 'ids',
		'number' => 1,
	)
);

yotm_concurrency_smoke_assert( '' !== $state_path, 'A smoke state path is required.' );
yotm_concurrency_smoke_assert( ! empty( $admin_ids ), 'An administrator account is required.' );
wp_set_current_user( absint( $admin_ids[0] ) );

if ( 'setup' === $smoke_role ) {
	yotm_install_job_tables();

	$uploads        = wp_get_upload_dir();
	$smoke_dir      = trailingslashit( $uploads['basedir'] ) . 'yotm-concurrency-' . wp_generate_uuid4();
	$original_path  = trailingslashit( $smoke_dir ) . 'source.png';
	$candidate      = trailingslashit( $smoke_dir ) . 'source-100x100.png';
	$original_data  = hex2bin( '89504e470d0a1a0a0000000d4948445200000001000000010804000000b51c0c020000000b4944415478da6364f80f00010501012718e3660000000049454e44ae426082' );
	$candidate_data = $original_data;
	yotm_concurrency_smoke_assert( is_string( $original_data ), 'Could not decode the PNG fixture.' );

	yotm_concurrency_smoke_assert( wp_mkdir_p( $smoke_dir ), 'Could not create the uploads smoke directory.' );
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- The smoke intentionally creates local uploads fixtures.
	yotm_concurrency_smoke_assert( strlen( $original_data ) === file_put_contents( $original_path, $original_data ), 'Could not create the original fixture.' );
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- The smoke intentionally creates local uploads fixtures.
	yotm_concurrency_smoke_assert( strlen( $candidate_data ) === file_put_contents( $candidate, $candidate_data ), 'Could not create the prune candidate.' );
	$smoke_state = array(
		'original_path'   => $original_path,
		'candidate_path'  => $candidate,
		'candidate_bytes' => strlen( $candidate_data ),
		'smoke_dir'       => $smoke_dir,
	);
	yotm_concurrency_smoke_write_state( $state_path, $smoke_state );

	$relative_original = ltrim( str_replace( wp_normalize_path( trailingslashit( $uploads['basedir'] ) ), '', wp_normalize_path( $original_path ) ), '/' );
	$attachment_id     = wp_insert_attachment(
		array(
			'post_mime_type' => 'image/png',
			'post_status'    => 'inherit',
			'post_title'     => 'YOTM concurrency smoke fixture',
		),
		$original_path
	);
	yotm_concurrency_smoke_assert( ! is_wp_error( $attachment_id ) && 0 < $attachment_id, 'Could not create the smoke attachment.' );
	$smoke_state['attachment_id'] = $attachment_id;
	yotm_concurrency_smoke_write_state( $state_path, $smoke_state );
	update_attached_file( $attachment_id, $relative_original );
	yotm_concurrency_smoke_assert(
		false !== wp_update_attachment_metadata(
			$attachment_id,
			array(
				'file'   => $relative_original,
				'width'  => 800,
				'height' => 600,
				'sizes'  => array(
					'yotm_smoke' => array(
						'file'      => wp_basename( $candidate ),
						'width'     => 100,
						'height'    => 100,
						'mime-type' => 'image/png',
					),
				),
			)
		),
		'Could not persist the smoke attachment metadata.'
	);

	$job = yotm_job_create(
		'prune',
		array(
			'base'                  => $uploads['basedir'],
			'ownership_schema'      => 'generated_file_v1',
			'source_index_complete' => 1,
		),
		array(
			'status'       => 'scanning',
			'phase'        => 'metadata',
			'counter_mode' => 'item_v2',
		)
	);
	yotm_concurrency_smoke_assert( is_array( $job ), 'Could not create the concurrency smoke job.' );
	$smoke_state['token']  = $job['token'];
	$smoke_state['job_id'] = $job['id'];
	yotm_concurrency_smoke_write_state( $state_path, $smoke_state );

	$item_key = hash( 'sha256', wp_normalize_path( $candidate ) );
	$payload  = array(
		'path'               => $candidate,
		'ownership_schema'   => 'generated_file_v1',
		'ownership'          => 'metadata_size',
		'attachment_id'      => $attachment_id,
		'size'               => 'yotm_smoke',
		'remove_metadata'    => 1,
		'metadata_refs'      => array(
			array(
				'attachment_id' => $attachment_id,
				'size'          => 'yotm_smoke',
				'filename'      => wp_basename( $candidate ),
			),
		),
		'ownership_evidence' => array(
			array(
				'attachment_id' => $attachment_id,
				'size'          => 'yotm_smoke',
				'filename'      => wp_basename( $candidate ),
				'mime'          => 'image/png',
				'selection'     => 'registered_remove',
			),
		),
	);
	yotm_concurrency_smoke_assert( yotm_job_add_item( $job['id'], $item_key, $payload, 'queued', strlen( $candidate_data ) ), 'Could not persist the prune item.' );
	yotm_concurrency_smoke_assert(
		yotm_job_update_where(
			$job['id'],
			array(
				'status' => 'deleting',
				'phase'  => 'delete',
				'total'  => 1,
			),
			array(
				'status'        => array( 'scanning' ),
				'phase'         => array( 'metadata' ),
				'require_match' => true,
			)
		),
		'Could not make the smoke job deletable.'
	);

	$smoke_state['item_key'] = $item_key;
	yotm_concurrency_smoke_write_state( $state_path, $smoke_state );
	echo "Fixture ready: one prune candidate with attachment metadata.\n";
	return;
}//end if

$state = yotm_concurrency_smoke_read_state( $state_path );

if ( 'cleanup' === $smoke_role ) {
	$job_id = absint( $state['job_id'] ?? 0 );
	if ( $job_id && yotm_job_get_by_id( $job_id ) ) {
		yotm_job_delete( $job_id );
	}

	$attachment_id = absint( $state['attachment_id'] ?? 0 );
	if ( $attachment_id && get_post( $attachment_id ) ) {
		wp_delete_attachment( $attachment_id, true );
	}

	foreach ( array( 'candidate_path', 'original_path' ) as $path_key ) {
		$fixture_path = (string) ( $state[ $path_key ] ?? '' );
		if ( '' !== $fixture_path && file_exists( $fixture_path ) ) {
			wp_delete_file( $fixture_path );
		}
	}

	$smoke_dir = (string) ( $state['smoke_dir'] ?? '' );
	if ( '' !== $smoke_dir && is_dir( $smoke_dir ) ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Removes the empty local uploads fixture directory.
		rmdir( $smoke_dir );
	}
	return;
}//end if

$job = yotm_job_get( $state['token'] ?? '' );
yotm_concurrency_smoke_assert( is_array( $job ), 'Concurrency smoke job could not be resumed.' );

if ( 'hold' === $smoke_role ) {
	global $wpdb;

	$worker = yotm_job_acquire_worker( $job['id'], array( 'deleting' ), array( 'delete' ) );
	yotm_concurrency_smoke_assert( is_array( $worker ), 'Worker A could not acquire the destructive batch.' );
	$items = yotm_job_claim_items( $worker, 1 );
	yotm_concurrency_smoke_assert( is_array( $items ) && 1 === count( $items ), 'Worker A could not claim the prune item.' );
	$item   = $items[0];
	$tables = yotm_job_table_names();

	$barrier = static function ( $claimed_item, $current_job, $current_worker ) use ( $wpdb, $tables, $state_path, $state ) {
		$past = gmdate( 'Y-m-d H:i:s', time() - MINUTE_IN_SECONDS );
		yotm_concurrency_smoke_assert(
			1 === $wpdb->update( $tables['jobs'], array( 'worker_lease_expires_at' => $past ), array( 'id' => $current_job['id'] ) ),
			'Could not force the persisted worker lease past due.'
		);
		yotm_concurrency_smoke_assert(
			1 === $wpdb->update( $tables['items'], array( 'claim_expires_at' => $past ), array( 'id' => $claimed_item['id'] ) ),
			'Could not force the persisted item claim past due.'
		);

		$state['holder_generation']       = $current_worker['generation'];
		$state['holder_claim_generation'] = $claimed_item['claim_generation'];
		$state['holder_claim_token']      = $claimed_item['claim_token'];
		$state['holder_attempts']         = $claimed_item['attempts'];
		$state['holder_ready']            = 1;
		yotm_concurrency_smoke_write_state( $state_path, $state );

		// The runner kills this process while it is inside the real pre-delete boundary.
		sleep( 30 );
	};

	$result = yotm_process_claimed_prune_item( $item, $job, $worker, (string) $job['payload']['base'], $barrier );
	throw new RuntimeException( 'Worker A crossed the test barrier without being terminated: ' . wp_json_encode( $result ) );
}//end if

if ( 'contend' === $smoke_role ) {
	$worker = yotm_job_acquire_worker( $job['id'], array( 'deleting' ), array( 'delete' ) );
	yotm_concurrency_smoke_assert( is_wp_error( $worker ), 'Worker B acquired an over-TTL job while worker A was alive.' );
	yotm_concurrency_smoke_assert( 'yotm_job_worker_busy' === $worker->get_error_code(), 'Worker B returned the wrong contention error.' );

	$current  = yotm_job_get_by_id( $job['id'] );
	$item     = yotm_job_get_item_by_key( $job['id'], $state['item_key'] );
	$counters = yotm_job_item_counters( $job['id'] );
	yotm_concurrency_smoke_assert( file_exists( $state['candidate_path'] ), 'Worker B changed the candidate while worker A was alive.' );
	yotm_concurrency_smoke_assert( file_exists( $state['original_path'] ), 'Worker B changed the original while worker A was alive.' );
	yotm_concurrency_smoke_assert( yotm_concurrency_smoke_has_metadata_reference( $state['attachment_id'] ), 'Worker B changed attachment metadata while worker A was alive.' );
	yotm_concurrency_smoke_assert( 'processing' === $item['status'], 'Worker B changed the claimed item while worker A was alive.' );
	yotm_concurrency_smoke_assert( $state['holder_claim_token'] === $item['claim_token'], 'Worker B replaced worker A\'s item claim.' );
	yotm_concurrency_smoke_assert( (int) $state['holder_claim_generation'] === $item['claim_generation'], 'Worker B advanced the item claim generation.' );
	yotm_concurrency_smoke_assert( (int) $state['holder_attempts'] === $item['attempts'], 'Worker B advanced the item attempts.' );
	yotm_concurrency_smoke_assert( (int) $state['holder_generation'] === (int) $current['worker_generation'], 'Worker B advanced the worker generation.' );
	yotm_concurrency_smoke_assert( 0 === $counters['processed'] && 1 === $counters['remaining'], 'Worker B changed the authoritative counters.' );
	printf(
		"%s\n",
		esc_html( "Live-owner contention: busy; file=present metadata=present item=processing processed=0 remaining=1 generation={$current['worker_generation']} attempts={$item['attempts']}." )
	);
	return;
}//end if

if ( 'recover' === $smoke_role ) {
	$before = yotm_job_get_by_id( $job['id'] );
	$item   = yotm_job_get_item_by_key( $job['id'], $state['item_key'] );
	yotm_concurrency_smoke_assert( strtotime( $before['worker_lease_expires_at'] . ' UTC' ) < time(), 'Worker lease is not recovery-eligible.' );
	yotm_concurrency_smoke_assert( strtotime( $item['claim_expires_at'] . ' UTC' ) < time(), 'Item claim is not recovery-eligible.' );

	$worker = yotm_job_acquire_worker( $job['id'], array( 'deleting' ), array( 'delete' ) );
	yotm_concurrency_smoke_assert( is_array( $worker ), 'Worker B could not recover after worker A exited.' );
	yotm_concurrency_smoke_assert( (int) $worker['generation'] > (int) $state['holder_generation'], 'Recovery did not advance the worker fencing generation.' );

	$items = yotm_job_claim_items( $worker, 1 );
	yotm_concurrency_smoke_assert( is_array( $items ) && 1 === count( $items ), 'Worker B could not reclaim the abandoned prune item.' );
	$item = $items[0];
	yotm_concurrency_smoke_assert( (int) $item['claim_generation'] > (int) $state['holder_claim_generation'], 'Recovery did not advance the item fencing generation.' );
	yotm_concurrency_smoke_assert( 2 === $item['attempts'], 'The recovered item must record exactly two attempts.' );

	$result = yotm_process_claimed_prune_item( $item, $job, $worker, (string) $job['payload']['base'] );
	yotm_concurrency_smoke_assert( is_array( $result ) && ! empty( $result['deleted'] ), 'Worker B did not delete the reclaimed candidate.' );
	yotm_concurrency_smoke_assert( yotm_job_finish_item( $item, 'done', '', (int) $result['bytes'] ), 'Worker B could not persist the authoritative item result.' );
	$current = yotm_job_sync_item_counters( $job['id'] );
	yotm_concurrency_smoke_assert( is_array( $current ), 'Worker B could not synchronize authoritative counters.' );
	yotm_concurrency_smoke_assert(
		yotm_job_worker_update(
			$worker,
			array(
				'status'     => 'completed',
				'phase'      => 'completed',
				'expires_at' => gmdate( 'Y-m-d H:i:s', time() + YOTM_JOB_AUDIT_RETENTION_SECONDS ),
			)
		),
		'Worker B could not persist the terminal job state.'
	);

	$current  = yotm_job_get_by_id( $job['id'] );
	$item     = yotm_job_get_item_by_key( $job['id'], $state['item_key'] );
	$counters = yotm_job_item_counters( $job['id'] );
	yotm_concurrency_smoke_assert( ! file_exists( $state['candidate_path'] ), 'The recovered candidate still exists.' );
	yotm_concurrency_smoke_assert( file_exists( $state['original_path'] ), 'Recovery deleted the authoritative original.' );
	yotm_concurrency_smoke_assert( ! yotm_concurrency_smoke_has_metadata_reference( $state['attachment_id'] ), 'Recovery did not reconcile attachment metadata.' );
	yotm_concurrency_smoke_assert( 'done' === $item['status'], 'The recovered item does not have one terminal result.' );
	yotm_concurrency_smoke_assert( 2 === $item['attempts'], 'Recovery changed the item attempt count unexpectedly.' );
	yotm_concurrency_smoke_assert( 'completed' === $current['status'], 'Recovery did not complete the prune job.' );
	yotm_concurrency_smoke_assert( 1 === $counters['processed'] && 1 === $counters['succeeded'] && 0 === $counters['failed'] && 0 === $counters['remaining'], 'Recovery persisted incorrect authoritative counters.' );
	yotm_concurrency_smoke_assert( (int) $state['candidate_bytes'] === $counters['bytes'], 'Recovery persisted the wrong deleted byte count.' );
	yotm_job_release_worker( $worker );
	printf(
		"%s\n",
		esc_html( "Abandonment recovery: file=deleted original=present metadata=removed item=done processed=1 succeeded=1 failed=0 remaining=0 bytes={$counters['bytes']} generation={$current['worker_generation']} attempts={$item['attempts']}." )
	);
	return;
}//end if

throw new RuntimeException( 'Unknown concurrency smoke role.' );
