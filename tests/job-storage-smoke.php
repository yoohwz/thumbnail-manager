<?php
/**
 * WP-CLI smoke test for persistent job storage and manifest locking.
 *
 * Run from the WordPress root:
 * wp eval-file wp-content/plugins/thumbnail-manager/tests/job-storage-smoke.php
 *
 * @package Thumbnail_Manager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Throw when a smoke-test condition fails.
 *
 * @param bool   $condition Condition.
 * @param string $message Failure message.
 * @throws RuntimeException When the condition fails.
 */
function yotm_job_smoke_assert( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( esc_html( $message ) );
	}
}

$created_jobs           = array();
$smoke_files            = array();
$uploads                = wp_get_upload_dir();
$smoke_directory        = trailingslashit( $uploads['basedir'] ) . 'yotm-job-smoke-' . wp_generate_uuid4();
$smoke_second_directory = $smoke_directory . '-second';
$smoke_nested_directory = trailingslashit( $smoke_second_directory ) . 'nested';
$admin_ids              = get_users(
	array(
		'role'   => 'administrator',
		'fields' => 'ids',
		'number' => 1,
	)
);

yotm_job_smoke_assert( ! empty( $admin_ids ), 'An administrator account is required.' );
wp_set_current_user( absint( $admin_ids[0] ) );
yotm_install_job_tables();
wp_mkdir_p( $smoke_directory );
wp_mkdir_p( $smoke_second_directory );
wp_mkdir_p( $smoke_nested_directory );

for ( $index = 1; $index <= 5; ++$index ) {
	$file          = trailingslashit( $smoke_directory ) . 'image-' . $index . '-100x100.jpg';
	$smoke_files[] = $file;
	file_put_contents( $file, 'thumbnail' );
}
for ( $index = 1; $index <= 3; ++$index ) {
	$file          = trailingslashit( $smoke_second_directory ) . 'second-' . $index . '-200x200.jpg';
	$smoke_files[] = $file;
	file_put_contents( $file, 'thumbnail' );
}
$nested_file   = trailingslashit( $smoke_nested_directory ) . 'nested-300x300.jpg';
$smoke_files[] = $nested_file;
file_put_contents( $nested_file, 'thumbnail' );

try {
	$job = yotm_job_create(
		'prune',
		array(
			'base'                   => $uploads['basedir'],
			'ownership_schema'       => 'generated_file_v1',
			'source_index_complete'  => 1,
			'reference_generation'   => YOTM_MEDIA_REFERENCE_GENERATION,
			'scan_base'              => trailingslashit( $smoke_directory ),
			'scan_bases'             => array( trailingslashit( $smoke_directory ), trailingslashit( $smoke_second_directory ) ),
			'disk_queue'             => array(
				array(
					'path'   => $smoke_directory,
					'root'   => $smoke_directory,
					'offset' => 0,
				),
				array(
					'path'   => $smoke_second_directory,
					'root'   => $smoke_second_directory,
					'offset' => 0,
				),
			),
			'disk_cursor_version'    => 'dfs_v2',
			'disk_entries_processed' => 0,
			'orphan_summary'         => yotm_initial_orphan_summary(),
		),
		array(
			'status' => 'scanning',
			'phase'  => 'metadata',
			'total'  => 2,
		)
	);
	yotm_job_smoke_assert( is_array( $job ), 'Could not create a persistent prune job.' );
	$created_jobs[] = $job['id'];

	yotm_job_smoke_assert(
		yotm_job_add_item(
			$job['id'],
			'first',
			array(
				'path' => '/tmp/yotm-first.jpg',
				'size' => '100x100',
			),
			'queued',
			100
		),
		'Could not add the first job item.'
	);
	yotm_job_smoke_assert(
		yotm_job_add_item(
			$job['id'],
			'second',
			array(
				'path' => '/tmp/yotm-second.jpg',
				'size' => '200x200',
			),
			'queued',
			200
		),
		'Could not add the second job item.'
	);
	yotm_job_smoke_assert( ! yotm_job_add_item( $job['id'], 'first', array( 'path' => '/tmp/yotm-first.jpg' ) ), 'Duplicate item keys must be rejected.' );
	yotm_job_smoke_assert( 300 === yotm_job_sum_item_bytes( $job['id'] ), 'Estimated manifest bytes were not persisted.' );
	$manifest_page = yotm_job_get_items_page( $job['id'], 1, 10, '100x100' );
	yotm_job_smoke_assert( 1 === $manifest_page['total'] && '/tmp/yotm-first.jpg' === $manifest_page['items'][0]['path'], 'Manifest search did not return the expected item.' );

	yotm_job_update( $job['id'], array( 'phase' => 'disk' ) );
	$disk = yotm_prune_scan_disk_batch( yotm_job_get_by_id( $job['id'] ), 3 );
	yotm_job_smoke_assert( ! $disk['done'] && 3 === $disk['job']['payload']['disk_entries_processed'], 'Disk scan did not stop at its persisted batch cursor.' );

	$loops = 0;
	do {
		$disk = yotm_prune_scan_disk_batch( $disk['job'], 3 );
		++$loops;
	} while ( ! $disk['done'] && $loops < 10 );
	yotm_job_smoke_assert( $disk['done'] && 9 === $disk['job']['payload']['orphan_summary']['total_files'], 'Multi-root DFS cursor did not resume through every selected file.' );

	yotm_job_update( $job['id'], array( 'phase' => 'manifest' ) );
	yotm_job_smoke_assert( ! yotm_job_add_item( $job['id'], 'late', array( 'path' => '/tmp/yotm-late.jpg' ) ), 'The manifest queue must be immutable.' );
	$manifest = yotm_job_build_manifest_batch( yotm_job_get_by_id( $job['id'] ), 10 );
	$job      = $manifest['job'];
	yotm_job_smoke_assert( ! empty( $manifest['done'] ) && 1 === preg_match( '/^[a-f0-9]{64}$/', $job['manifest_hash'] ), 'Manifest hash was not finalized.' );

	yotm_job_update(
		$job['id'],
		array(
			'status'     => 'awaiting_approval',
			'phase'      => 'review',
			'expires_at' => gmdate( 'Y-m-d H:i:s', time() + HOUR_IN_SECONDS ),
		)
	);
	$job = yotm_job_get( $job['token'] );
	yotm_job_smoke_assert( true === yotm_prune_validate_review_job( $job, $job['manifest_hash'], true ), 'Reviewed manifest validation failed.' );
	yotm_job_smoke_assert( is_wp_error( yotm_prune_validate_review_job( $job, str_repeat( '0', 64 ), true ) ), 'A mismatched manifest must be rejected.' );

	$locked = yotm_job_create(
		'regenerate',
		array(),
		array(
			'status' => 'running',
			'phase'  => 'regenerate',
		)
	);
	yotm_job_smoke_assert( is_wp_error( $locked ) && 'yotm_job_locked' === $locked->get_error_code(), 'Concurrent destructive jobs must be locked.' );

	yotm_job_update(
		$job['id'],
		array(
			'status' => 'completed',
			'phase'  => 'completed',
		)
	);
	$regenerate = yotm_job_create(
		'regenerate',
		array(),
		array(
			'status' => 'running',
			'phase'  => 'regenerate',
		)
	);
	yotm_job_smoke_assert( is_array( $regenerate ), 'The operation lock was not released after completion.' );
	$created_jobs[] = $regenerate['id'];
	$stopped        = yotm_job_cancel( $regenerate );
	yotm_job_smoke_assert( is_array( $stopped ) && 'cancelled' === $stopped['status'], 'Stopping a job did not retain a cancelled audit state.' );
	yotm_job_smoke_assert( is_array( yotm_job_get( $regenerate['token'] ) ), 'A stopped job was deleted instead of retained for audit.' );

	echo "YOTM persistent job smoke tests passed.\n";
} finally {
	foreach ( $created_jobs as $job_id ) {
		yotm_job_delete( $job_id );
	}
	foreach ( $smoke_files as $file ) {
		if ( file_exists( $file ) ) {
			wp_delete_file( $file );
		}
	}
	if ( is_dir( $smoke_directory ) ) {
		rmdir( $smoke_directory );
	}
	if ( is_dir( $smoke_nested_directory ) ) {
		rmdir( $smoke_nested_directory );
	}
	if ( is_dir( $smoke_second_directory ) ) {
		rmdir( $smoke_second_directory );
	}
	wp_set_current_user( 0 );
}//end try
