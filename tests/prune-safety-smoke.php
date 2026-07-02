<?php
/**
 * WP-CLI smoke tests for destructive prune safety.
 *
 * Run from the WordPress root:
 * wp eval-file wp-content/plugins/thumbnail-manager/tests/prune-safety-smoke.php
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function yotm_smoke_assert( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

$uploads = wp_get_upload_dir();
$base    = trailingslashit( $uploads['basedir'] );
$dir     = $base . 'yotm-smoke-' . wp_generate_uuid4();
$orig    = trailingslashit( $dir ) . 'image-300x300.jpg';
$thumb   = trailingslashit( $dir ) . 'image-300x300-150x150.jpg';
$outside = trailingslashit( sys_get_temp_dir() ) . 'yotm-outside-' . wp_generate_uuid4() . '.jpg';
$id      = 0;

try {
	wp_mkdir_p( $dir );
	file_put_contents( $orig, 'original' );
	file_put_contents( $thumb, 'thumb' );
	file_put_contents( $outside, 'outside' );

	$delete_guard = yotm_prune_validate_delete_meta(
		[
			'mode'      => 'dry',
			'scan_done' => 1,
		]
	);
	yotm_smoke_assert( is_wp_error( $delete_guard ), 'Dry-run metadata must not be allowed to delete.' );

	$scan_guard = yotm_prune_validate_delete_meta(
		[
			'mode'      => 'delete',
			'scan_done' => 0,
		]
	);
	yotm_smoke_assert( is_wp_error( $scan_guard ), 'Delete metadata must not be allowed before scan is complete.' );

	$outside_result = yotm_delete_file_and_count( $outside );
	yotm_smoke_assert( 0 === $outside_result, 'Path outside uploads must not be deleted.' );
	yotm_smoke_assert( file_exists( $outside ), 'Outside file should still exist after delete attempt.' );

	$id = wp_insert_attachment(
		[
			'post_mime_type' => 'image/jpeg',
			'post_title'     => 'YOTM Smoke',
			'post_status'    => 'inherit',
		],
		$orig
	);
	yotm_smoke_assert( ! is_wp_error( $id ) && $id, 'Could not insert smoke attachment.' );

	$relative = ltrim( str_replace( $base, '', $orig ), '/' );
	wp_update_attachment_metadata(
		$id,
		[
			'file'  => $relative,
			'sizes' => [
				'thumbnail' => [
					'file'      => basename( $thumb ),
					'width'     => 150,
					'height'    => 150,
					'mime-type' => 'image/jpeg',
				],
			],
		]
	);

	$candidates     = [];
	$orphan_summary = yotm_initial_orphan_summary();
	yotm_collect_metadata_prune_candidates_for_ids(
		[ $id ],
		trailingslashit( yotm_normalize_filesystem_path( $dir ) ),
		[],
		[ 'thumbnail' ],
		[
			'thumbnail' => [
				'width'  => 150,
				'height' => 150,
				'crop'   => true,
			],
		],
		false,
		$candidates,
		$orphan_summary
	);

	$paths = array_column( array_values( $candidates ), 'path' );
	yotm_smoke_assert( ! in_array( yotm_normalize_filesystem_path( $orig ), $paths, true ), 'Original image-300x300.jpg must not be a prune candidate.' );
	yotm_smoke_assert( in_array( yotm_normalize_filesystem_path( $thumb ), $paths, true ), 'Metadata thumbnail should be a prune candidate.' );

	$thumb_candidate = null;
	foreach ( $candidates as $candidate ) {
		if ( $candidate['path'] === yotm_normalize_filesystem_path( $thumb ) ) {
			$thumb_candidate = $candidate;
			break;
		}
	}

	yotm_smoke_assert( is_array( $thumb_candidate ), 'Could not find exact thumbnail candidate.' );

	$delete_result = yotm_delete_prune_item( $thumb_candidate, $base );
	$metadata      = wp_get_attachment_metadata( $id );

	yotm_smoke_assert( ! empty( $delete_result['deleted'] ), 'Thumbnail candidate should be deleted.' );
	yotm_smoke_assert( file_exists( $orig ), 'Original should still exist after thumbnail delete.' );
	yotm_smoke_assert( empty( $metadata['sizes']['thumbnail'] ), 'Deleted thumbnail metadata should be removed.' );

	echo "YOTM prune safety smoke tests passed.\n";
} finally {
	if ( $id ) {
		wp_delete_post( $id, true );
	}

	foreach ( [ $orig, $thumb, $outside ] as $file ) {
		if ( $file && file_exists( $file ) ) {
			wp_delete_file( $file );
		}
	}

	if ( is_dir( $dir ) ) {
		@rmdir( $dir );
	}
}
