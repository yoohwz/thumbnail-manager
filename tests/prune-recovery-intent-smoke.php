<?php
/**
 * Cross-process fault injection for prune expiry/cancellation recovery intent.
 *
 * This file is orchestrated by tests/run-prune-recovery-intent-smoke.sh.
 *
 * @package Thumbnail_Manager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Fail one recovery-intent smoke assertion.
 *
 * @param bool   $condition Condition.
 * @param string $message Failure message.
 * @throws RuntimeException When the condition fails.
 */
function yotm_recovery_intent_smoke_assert( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( esc_html( $message ) );
	}
}

/**
 * Read shared smoke state.
 *
 * @param string $path State path.
 * @return array
 */
function yotm_recovery_intent_smoke_read( $path ) {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local temporary coordination file, not a URL.
	$state = json_decode( (string) file_get_contents( $path ), true );
	yotm_recovery_intent_smoke_assert( is_array( $state ), 'Recovery-intent smoke state is unavailable.' );

	return $state;
}

/**
 * Persist shared smoke state.
 *
 * @param string $path State path.
 * @param array  $state State data.
 * @return void
 */
function yotm_recovery_intent_smoke_write( $path, $state ) {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Local temporary coordination file owned by the smoke runner.
	yotm_recovery_intent_smoke_assert( false !== file_put_contents( $path, wp_json_encode( $state ) ), 'Could not persist recovery-intent smoke state.' );
}

/**
 * Return whether the fixture metadata still references the candidate.
 *
 * @param int $attachment_id Attachment ID.
 * @return bool
 */
function yotm_recovery_intent_smoke_has_reference( $attachment_id ) {
	$metadata = wp_get_attachment_metadata( $attachment_id );

	return is_array( $metadata ) && isset( $metadata['sizes']['yotm_recovery'] );
}

$smoke_role = sanitize_key( $args[0] ?? '' );
$state_path = (string) ( $args[1] ?? '' );
$intent     = sanitize_key( $args[2] ?? '' );
$admin_ids  = get_users(
	array(
		'role'   => 'administrator',
		'fields' => 'ids',
		'number' => 1,
	)
);

yotm_recovery_intent_smoke_assert( '' !== $state_path, 'A recovery-intent smoke state path is required.' );
yotm_recovery_intent_smoke_assert( ! empty( $admin_ids ), 'An administrator account is required.' );
wp_set_current_user( absint( $admin_ids[0] ) );

if ( 'setup' === $smoke_role ) {
	yotm_recovery_intent_smoke_assert( in_array( $intent, array( 'expired', 'cancelled' ), true ), 'A valid recovery terminal intent is required.' );
	yotm_install_job_tables();
	$uploads        = wp_get_upload_dir();
	$smoke_dir      = trailingslashit( $uploads['basedir'] ) . 'yotm-recovery-intent-' . wp_generate_uuid4();
	$original       = trailingslashit( $smoke_dir ) . 'source.png';
	$candidate      = trailingslashit( $smoke_dir ) . 'source-100x100.png';
	$candidate_data = hex2bin( '89504e470d0a1a0a0000000d4948445200000001000000010804000000b51c0c020000000b4944415478da6364f80f00010501012718e3660000000049454e44ae426082' );
	yotm_recovery_intent_smoke_assert( is_string( $candidate_data ), 'Could not decode the PNG fixture.' );
	yotm_recovery_intent_smoke_assert( wp_mkdir_p( $smoke_dir ), 'Could not create the recovery-intent fixture directory.' );
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- The smoke intentionally creates uploads fixtures.
	yotm_recovery_intent_smoke_assert( strlen( $candidate_data ) === file_put_contents( $original, $candidate_data ), 'Could not create the source fixture.' );
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- The smoke intentionally creates uploads fixtures.
	yotm_recovery_intent_smoke_assert( strlen( $candidate_data ) === file_put_contents( $candidate, $candidate_data ), 'Could not create the prune fixture.' );

	$attachment_id = wp_insert_attachment(
		array(
			'post_mime_type' => 'image/png',
			'post_status'    => 'inherit',
			'post_title'     => 'YOTM recovery-intent smoke fixture',
		),
		$original
	);
	yotm_recovery_intent_smoke_assert( ! is_wp_error( $attachment_id ) && 0 < $attachment_id, 'Could not create the recovery-intent attachment.' );
	$relative = ltrim( str_replace( wp_normalize_path( trailingslashit( $uploads['basedir'] ) ), '', wp_normalize_path( $original ) ), '/' );
	update_attached_file( $attachment_id, $relative );
	yotm_recovery_intent_smoke_assert(
		false !== wp_update_attachment_metadata(
			$attachment_id,
			array(
				'file'  => $relative,
				'sizes' => array(
					'yotm_recovery' => array(
						'file'      => wp_basename( $candidate ),
						'width'     => 100,
						'height'    => 100,
						'mime-type' => 'image/png',
					),
				),
			)
		),
		'Could not persist recovery-intent attachment metadata.'
	);

	$payload = array(
		'path'          => $candidate,
		'metadata_refs' => array(
			array(
				'attachment_id' => $attachment_id,
				'size'          => 'yotm_recovery',
				'filename'      => wp_basename( $candidate ),
			),
		),
	);
	$job     = yotm_job_create(
		'prune',
		array( 'base' => $uploads['basedir'] ),
		array(
			'status'       => 'scanning',
			'phase'        => 'metadata',
			'counter_mode' => 'item_v3',
		)
	);
	yotm_recovery_intent_smoke_assert( is_array( $job ), 'Could not create the recovery-intent job.' );
	$item_key = hash( 'sha256', wp_normalize_path( $candidate ) );
	yotm_recovery_intent_smoke_assert( yotm_job_add_item( $job['id'], $item_key, $payload, 'queued', strlen( $candidate_data ) ), 'Could not persist the recovery-intent item.' );
	yotm_recovery_intent_smoke_assert(
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
		'Could not make the recovery-intent job deletable.'
	);
	yotm_recovery_intent_smoke_write(
		$state_path,
		array(
			'intent'        => $intent,
			'job_id'        => $job['id'],
			'item_key'      => $item_key,
			'attachment_id' => $attachment_id,
			'candidate'     => $candidate,
			'original'      => $original,
			'smoke_dir'     => $smoke_dir,
		)
	);
	echo esc_html( "Recovery-intent fixture ready: {$intent}.\n" );
	return;
}//end if

$state = yotm_recovery_intent_smoke_read( $state_path );

if ( 'cleanup' === $smoke_role ) {
	if ( ! empty( $state['job_id'] ) && yotm_job_get_by_id( $state['job_id'] ) ) {
		yotm_job_delete( $state['job_id'] );
	}
	if ( ! empty( $state['attachment_id'] ) && get_post( $state['attachment_id'] ) ) {
		wp_delete_attachment( $state['attachment_id'], true );
	}
	foreach ( array( 'candidate', 'original' ) as $path_key ) {
		$fixture_path = (string) ( $state[ $path_key ] ?? '' );
		if ( '' !== $fixture_path && ( is_link( $fixture_path ) || file_exists( $fixture_path ) ) ) {
			wp_delete_file( $fixture_path );
		}
	}
	if ( ! empty( $state['smoke_dir'] ) && is_dir( $state['smoke_dir'] ) ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Removes the empty local fixture directory.
		rmdir( $state['smoke_dir'] );
	}
	return;
}//end if

if ( 'arm' === $smoke_role ) {
	global $wpdb;

	$job    = yotm_job_get_by_id( $state['job_id'] );
	$worker = yotm_job_acquire_worker( $job['id'], array( 'deleting' ), array( 'delete' ) );
	yotm_recovery_intent_smoke_assert( is_array( $worker ), 'The arming worker could not acquire the job.' );
	$items = yotm_job_claim_items( $worker, 1 );
	yotm_recovery_intent_smoke_assert( is_array( $items ) && 1 === count( $items ), 'The arming worker could not claim the item.' );
	$item   = $items[0];
	$tables = yotm_job_table_names();

	$barrier = static function ( $armed_item ) use ( $wpdb, $tables, $state, $state_path ) {
		$past = gmdate( 'Y-m-d H:i:s', time() - MINUTE_IN_SECONDS );
		yotm_recovery_intent_smoke_assert( 1 === $wpdb->update( $tables['jobs'], array( 'worker_lease_expires_at' => $past ), array( 'id' => $armed_item['job_id'] ) ), 'Could not expire the arming worker lease.' );
		yotm_recovery_intent_smoke_assert( 1 === $wpdb->update( $tables['items'], array( 'claim_expires_at' => $past ), array( 'id' => $armed_item['id'] ) ), 'Could not expire the armed item claim.' );
		$state['armed_ready'] = 1;
		yotm_recovery_intent_smoke_write( $state_path, $state );
		sleep( 30 );
	};

	$result = yotm_delete_prune_item_recoverable( $item, $item['payload'], (string) $job['payload']['base'], false, $barrier );
	throw new RuntimeException( 'The arming worker crossed the fault-injection barrier: ' . wp_json_encode( $result ) );
}//end if

if ( 'recover' === $smoke_role ) {
	global $wpdb;

	$job = yotm_job_get_by_id( $state['job_id'] );
	if ( 'expired' === $state['intent'] ) {
		$tables = yotm_job_table_names();
		yotm_recovery_intent_smoke_assert( 1 === $wpdb->update( $tables['jobs'], array( 'expires_at' => gmdate( 'Y-m-d H:i:s', time() - MINUTE_IN_SECONDS ) ), array( 'id' => $job['id'] ) ), 'Could not expire the armed prune job.' );
		$job = yotm_job_expire_if_inactive( yotm_job_get_by_id( $job['id'] ) );
		yotm_recovery_intent_smoke_assert( is_array( $job ), 'Expiry did not retain a recoverable job.' );
	} else {
		$cancelled = yotm_job_cancel( $job );
		yotm_recovery_intent_smoke_assert( is_wp_error( $cancelled ) && 'yotm_job_cancel_busy' === $cancelled->get_error_code(), 'Cancel did not persist recovery-only intent.' );
		$job = yotm_job_get_by_id( $job['id'] );
	}

	yotm_recovery_intent_smoke_assert( 1 === (int) $job['payload']['recovery_only'], 'Recovery-only intent was not persisted.' );
	yotm_recovery_intent_smoke_assert( yotm_job_recovery_terminal_status( $job ) === $state['intent'], 'The requested terminal intent was not preserved.' );
	$worker = yotm_job_acquire_worker( $job['id'], array( 'deleting' ), array( 'delete' ) );
	yotm_recovery_intent_smoke_assert( is_array( $worker ), 'The recovery worker could not take over the armed job.' );
	$items = yotm_job_claim_items( $worker, 1, true );
	yotm_recovery_intent_smoke_assert( is_array( $items ) && 1 === count( $items ), 'The recovery worker could not claim the journaled item.' );
	$item   = $items[0];
	$result = yotm_delete_prune_item_recoverable( $item, $item['payload'], (string) $job['payload']['base'], true );
	yotm_recovery_intent_smoke_assert( is_array( $result ) && empty( $result['deleted'] ) && empty( $result['skipped'] ), 'Recovery-only processing did not fail closed on the intact prestate.' );
	yotm_recovery_intent_smoke_assert( file_exists( $state['candidate'] ), 'Recovery-only processing deleted the intact candidate.' );
	yotm_recovery_intent_smoke_assert( yotm_recovery_intent_smoke_has_reference( $state['attachment_id'] ), 'Recovery-only processing reconciled metadata without a delete.' );
	yotm_recovery_intent_smoke_assert( yotm_job_finish_item_v3( $item, $worker, 'failed', $result['error'] ), 'Could not persist the fail-closed recovery item result.' );
	$terminal = yotm_job_recovery_terminal_status( $job );
	yotm_recovery_intent_smoke_assert(
		yotm_job_worker_update(
			$worker,
			array(
				'status'     => $terminal,
				'phase'      => $terminal,
				'expires_at' => gmdate( 'Y-m-d H:i:s', time() + YOTM_JOB_AUDIT_RETENTION_SECONDS ),
			)
		),
		'Could not persist the requested terminal recovery state.'
	);
	$current = yotm_job_get_by_id( $job['id'] );
	$item    = yotm_job_get_item_by_key( $job['id'], $state['item_key'] );
	yotm_recovery_intent_smoke_assert( $terminal === $current['status'] && $terminal === $current['phase'], 'The job did not reach its requested terminal state.' );
	yotm_recovery_intent_smoke_assert( 'failed' === $item['status'], 'The intact armed item was not retained as a fail-closed audit result.' );
	yotm_job_release_worker( $worker );
	echo esc_html( "Recovery-only {$terminal}: zero new delete side effects; candidate and metadata preserved.\n" );
	return;
}//end if

throw new RuntimeException( 'Unknown recovery-intent smoke role.' );
