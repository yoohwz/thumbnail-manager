<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'wp_ajax_yotm_regenerate_prepare', 'yotm_regenerate_prepare' );
add_action( 'wp_ajax_yotm_regenerate_batch', 'yotm_regenerate_batch' );

/**
 * Prepare regeneration queue.
 */
function yotm_regenerate_prepare() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( [ 'msg' => 'No permission' ], 403 );
	}

	check_ajax_referer( 'yotm_prune_nonce', 'nonce' );

	$scope = sanitize_text_field( wp_unslash( $_POST['scope'] ?? 'all' ) );
	if ( ! in_array( $scope, [ 'all', 'year', 'subpath', 'ids' ], true ) ) {
		$scope = 'all';
	}

	$subpath      = isset( $_POST['subpath'] ) ? yotm_clean_upload_subpath( sanitize_text_field( wp_unslash( $_POST['subpath'] ) ) ) : '';
	$ids_raw      = isset( $_POST['attachment_ids'] ) ? sanitize_textarea_field( wp_unslash( $_POST['attachment_ids'] ) ) : '';
	$force_all    = ! empty( $_POST['force_all'] );
	$only_missing = ! empty( $_POST['only_missing'] ) && ! $force_all;

	$query_args  = [];
	$scope_label = 'All media';
	$cursor_mode = true;
	$ids         = [];
	$total       = 0;

	if ( 'year' === $scope ) {
		$query_args['date_query'] = [
			[
				'year' => (int) current_time( 'Y' ),
			],
		];
		$scope_label = 'Current year';
	} elseif ( 'subpath' === $scope ) {
		$uploads = wp_get_upload_dir();
		$base    = trailingslashit( yotm_normalize_filesystem_path( $uploads['basedir'] ) );

		if ( '' === $subpath ) {
			wp_send_json_error( [ 'msg' => 'Please choose an uploads folder.' ], 400 );
		}

		$target_dir = yotm_resolve_upload_scan_base( $base, $subpath );
		if ( is_wp_error( $target_dir ) ) {
			wp_send_json_error( [ 'msg' => $target_dir->get_error_message() ], 400 );
		}

		$cursor_mode = false;
		$ids         = yotm_get_image_attachment_ids_paged(
			$query_args,
			static function ( $attachment_id ) use ( $target_dir ) {
				$file = get_attached_file( $attachment_id );

				return $file && yotm_is_path_inside_dir( $file, $target_dir );
			}
		);
		$total       = count( $ids );
		$scope_label = yotm_uploads_relative_label( $base, $target_dir );
	} elseif ( 'ids' === $scope ) {
		preg_match_all( '/\d+/', $ids_raw, $matches );
		$raw_ids = isset( $matches[0] ) ? array_map( 'absint', $matches[0] ) : [];
		$raw_ids = array_values( array_filter( array_unique( $raw_ids ) ) );

		if ( empty( $raw_ids ) ) {
			wp_send_json_error( [ 'msg' => 'Please enter at least one valid attachment ID.' ], 400 );
		}

		foreach ( $raw_ids as $attachment_id ) {
			$post = get_post( $attachment_id );
			$mime = get_post_mime_type( $attachment_id );

			if (
				$post
				&& 'attachment' === $post->post_type
				&& is_string( $mime )
				&& 0 === strpos( $mime, 'image/' )
			) {
				$ids[] = $attachment_id;
			}
		}

		$cursor_mode = false;
		$total       = count( $ids );
		$scope_label = 'Specific attachment IDs';
	}

	if ( $cursor_mode ) {
		$total = yotm_count_image_attachments( $query_args );
		$max_id = yotm_get_max_image_attachment_id( $query_args );
	} else {
		$max_id = 0;
	}

	$ids = array_values( array_map( 'absint', $ids ) );

	$token = wp_generate_uuid4();

	yotm_store_regen_list(
		$token,
		$ids,
		[
			'total'        => $total,
			'processed'    => 0,
			'regenerated'  => 0,
			'skipped'      => 0,
			'failed'       => 0,
			'only_missing' => $only_missing ? 1 : 0,
			'force_all'    => $force_all ? 1 : 0,
			'scope'        => $scope,
			'scope_label'  => $scope_label,
			'subpath'      => $subpath,
			'cursor_mode'  => $cursor_mode ? 1 : 0,
			'query_args'   => $query_args,
			'last_id'      => 0,
			'max_id'       => $max_id,
		]
	);

	wp_send_json_success(
		[
			'token'        => $token,
			'total'        => $total,
			'scope'        => $scope,
			'scope_label'  => $scope_label,
			'only_missing' => $only_missing ? 1 : 0,
			'force_all'    => $force_all ? 1 : 0,
		]
	);
}

/**
 * Process regeneration in batches.
 */
function yotm_regenerate_batch() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( [ 'msg' => 'No permission' ], 403 );
	}

	check_ajax_referer( 'yotm_prune_nonce', 'nonce' );

	$token = sanitize_text_field( wp_unslash( $_POST['token'] ?? '' ) );
	$batch = isset( $_POST['batch'] ) ? absint( wp_unslash( $_POST['batch'] ) ) : 20;
	$batch = max( 1, min( 200, $batch ) );

	$list = yotm_fetch_regen_list( $token );
	$meta = yotm_fetch_regen_meta( $token );

	if ( false === $list || false === $meta || ! is_array( $list ) || ! is_array( $meta ) ) {
		wp_send_json_error( [ 'msg' => 'State expired. Please run again.' ], 400 );
	}

	$cursor_mode = ! empty( $meta['cursor_mode'] );

	if ( $cursor_mode ) {
		$chunk = yotm_get_image_attachment_ids_after(
			isset( $meta['query_args'] ) && is_array( $meta['query_args'] ) ? $meta['query_args'] : [],
			(int) ( $meta['last_id'] ?? 0 ),
			$batch,
			(int) ( $meta['max_id'] ?? 0 )
		);
	} else {
		$chunk = array_splice( $list, 0, $batch );
	}

	if ( empty( $chunk ) ) {
		yotm_delete_regen_state( $token );
		wp_send_json_success(
			[
				'processed'   => (int) $meta['processed'],
				'total'       => (int) $meta['total'],
				'regenerated' => (int) $meta['regenerated'],
				'skipped'     => (int) $meta['skipped'],
				'failed'      => (int) $meta['failed'],
				'remaining'   => 0,
				'percent'     => 100,
				'done'        => true,
				'scope_label' => isset( $meta['scope_label'] ) ? $meta['scope_label'] : 'All media',
			]
		);
	}

	$processed_now   = 0;
	$regenerated_now = 0;
	$skipped_now     = 0;
	$failed_now      = 0;

	foreach ( $chunk as $attachment_id ) {
		$attachment_id = absint( $attachment_id );
		if ( ! $attachment_id ) {
			continue;
		}

		if ( $cursor_mode && $attachment_id > (int) ( $meta['last_id'] ?? 0 ) ) {
			$meta['last_id'] = $attachment_id;
		}

		$processed_now++;

		$file = get_attached_file( $attachment_id );
		if ( ! $file || ! file_exists( $file ) ) {
			$failed_now++;
			continue;
		}

		$mime = get_post_mime_type( $attachment_id );
		if ( ! is_string( $mime ) || 0 !== strpos( $mime, 'image/' ) ) {
			$skipped_now++;
			continue;
		}

		if ( 'image/svg+xml' === $mime ) {
			$skipped_now++;
			continue;
		}

		$only_missing = ! empty( $meta['only_missing'] );
		$force_all    = ! empty( $meta['force_all'] );

		if (
			$only_missing
			&& ! $force_all
			&& function_exists( 'wp_get_missing_image_subsizes' )
			&& function_exists( 'wp_update_image_subsizes' )
		) {
			$missing = wp_get_missing_image_subsizes( $attachment_id );

			if ( empty( $missing ) ) {
				$skipped_now++;
				continue;
			}

			$result = wp_update_image_subsizes( $attachment_id );

			if ( is_wp_error( $result ) ) {
				$failed_now++;
			} else {
				$regenerated_now++;
			}
		} else {
			$metadata = wp_generate_attachment_metadata( $attachment_id, $file );

			if ( is_wp_error( $metadata ) || empty( $metadata ) ) {
				$failed_now++;
				continue;
			}

			$updated = wp_update_attachment_metadata( $attachment_id, $metadata );

			if ( false === $updated ) {
				$failed_now++;
			} else {
				$regenerated_now++;
			}
		}
	}

	$meta['processed']   = (int) $meta['processed'] + $processed_now;
	$meta['regenerated'] = (int) $meta['regenerated'] + $regenerated_now;
	$meta['skipped']     = (int) $meta['skipped'] + $skipped_now;
	$meta['failed']      = (int) $meta['failed'] + $failed_now;

	if ( ! $cursor_mode ) {
		yotm_update_regen_list( $token, $list );
	}
	yotm_update_regen_meta( $token, $meta );

	$total     = max( 1, (int) $meta['total'] );
	$processed = (int) $meta['processed'];
	$remaining = $cursor_mode ? max( 0, (int) $meta['total'] - $processed ) : count( $list );
	$percent   = min( 100, ( $processed / $total ) * 100 );

	$resp = [
		'processed'   => $processed,
		'total'       => (int) $meta['total'],
		'regenerated' => (int) $meta['regenerated'],
		'skipped'     => (int) $meta['skipped'],
		'failed'      => (int) $meta['failed'],
		'remaining'   => $remaining,
		'percent'     => $percent,
		'done'        => false,
		'scope_label' => isset( $meta['scope_label'] ) ? $meta['scope_label'] : 'All media',
	];

	if ( 0 === $remaining || ( $cursor_mode && $processed >= (int) $meta['total'] ) ) {
		yotm_delete_regen_state( $token );
		$resp['done'] = true;
	}

	wp_send_json_success( $resp );
}
