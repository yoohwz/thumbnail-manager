<?php
/**
 * Cross-process smoke worker for persistent job liveness and takeover.
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
	$job = yotm_job_create(
		'prune',
		array(),
		array(
			'status'       => 'deleting',
			'phase'        => 'delete',
			'counter_mode' => 'item_v2',
		)
	);
	yotm_concurrency_smoke_assert( is_array( $job ), 'Could not create the concurrency smoke job.' );
	yotm_concurrency_smoke_write_state(
		$state_path,
		array(
			'token'  => $job['token'],
			'job_id' => $job['id'],
		)
	);
	echo "Concurrency smoke job created.\n";
	return;
}//end if

$state = yotm_concurrency_smoke_read_state( $state_path );

if ( 'cleanup' === $smoke_role ) {
	$job_id = absint( $state['job_id'] ?? 0 );
	if ( $job_id && yotm_job_get_by_id( $job_id ) ) {
		yotm_job_delete( $job_id );
	}
	return;
}

$job = yotm_job_get( $state['token'] ?? '' );
yotm_concurrency_smoke_assert( is_array( $job ), 'Concurrency smoke job could not be resumed.' );

if ( 'hold' === $smoke_role ) {
	global $wpdb;

	$worker = yotm_job_acquire_worker( $job['id'], array( 'deleting' ), array( 'delete' ) );
	yotm_concurrency_smoke_assert( is_array( $worker ), 'Worker A could not acquire the destructive batch.' );
	$tables = yotm_job_table_names();
	$wpdb->update(
		$tables['jobs'],
		array( 'worker_lease_expires_at' => gmdate( 'Y-m-d H:i:s', time() - MINUTE_IN_SECONDS ) ),
		array( 'id' => $job['id'] )
	);
	$state['holder_generation'] = $worker['generation'];
	$state['holder_ready']      = 1;
	yotm_concurrency_smoke_write_state( $state_path, $state );

	// The runner terminates this process after worker B proves it is blocked.
	sleep( 30 );
	return;
}

if ( 'contend' === $smoke_role ) {
	$worker = yotm_job_acquire_worker( $job['id'], array( 'deleting' ), array( 'delete' ) );
	yotm_concurrency_smoke_assert( is_wp_error( $worker ), 'Worker B acquired an over-TTL job while worker A was alive.' );
	yotm_concurrency_smoke_assert( 'yotm_job_worker_busy' === $worker->get_error_code(), 'Worker B returned the wrong contention error.' );
	echo "Worker B blocked while worker A remained alive.\n";
	return;
}

if ( 'recover' === $smoke_role ) {
	$worker = yotm_job_acquire_worker( $job['id'], array( 'deleting' ), array( 'delete' ) );
	yotm_concurrency_smoke_assert( is_array( $worker ), 'Worker B could not recover after worker A exited.' );
	yotm_concurrency_smoke_assert( (int) $worker['generation'] > (int) ( $state['holder_generation'] ?? 0 ), 'Recovery did not advance the fencing generation.' );
	yotm_job_release_worker( $worker );
	yotm_job_delete( $job['id'] );
	echo "Worker B recovered after worker A exited.\n";
	return;
}

throw new RuntimeException( 'Unknown concurrency smoke role.' );
