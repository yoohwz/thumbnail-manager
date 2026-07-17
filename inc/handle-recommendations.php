<?php
/**
 * Batched thumbnail-size recommendation scans.
 *
 * @package Thumbnail_Manager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'wp_ajax_yotm_recommend_prepare', 'yotm_recommend_prepare' );
add_action( 'wp_ajax_yotm_recommend_batch', 'yotm_recommend_batch' );

/**
 * Prepare a batched recommendation scan.
 */
function yotm_recommend_prepare() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'msg' => __( 'No permission.', 'thumbnail-manager' ) ), 403 );
	}

	check_ajax_referer( 'yotm_prune_nonce', 'nonce' );
	$sizes          = yotm_get_registered_sizes();
	$size_names     = array_keys( $sizes );
	$metadata_usage = array();
	$content_usage  = array();

	foreach ( $size_names as $name ) {
		$metadata_usage[ $name ] = array(
			'count' => 0,
			'bytes' => 0,
		);
		$content_usage[ $name ]  = 0;
	}

	$attachment_total = yotm_count_image_attachments();
	$content_total    = yotm_recommend_count_content_posts();
	$job              = yotm_job_create(
		'recommendation',
		array(
			'sizes'                  => $sizes,
			'size_names'             => $size_names,
			'metadata_usage'         => $metadata_usage,
			'content_usage'          => $content_usage,
			'scan_phase'             => 'metadata',
			'attachment_after'       => 0,
			'attachment_max'         => yotm_get_max_image_attachment_id(),
			'attachment_total'       => $attachment_total,
			'content_after'          => 0,
			'content_max'            => yotm_recommend_get_max_content_post_id(),
			'content_total'          => $content_total,
			'scan_processed'         => 0,
			'scan_total_attachments' => $attachment_total + $content_total,
		),
		array(
			'status'    => 'scanning',
			'phase'     => 'metadata',
			'total'     => $attachment_total + $content_total,
			'ttl'       => DAY_IN_SECONDS,
			'exclusive' => false,
		)
	);

	if ( is_wp_error( $job ) ) {
		$data = $job->get_error_data();
		wp_send_json_error(
			array(
				'msg'          => $job->get_error_message(),
				'resume_token' => is_array( $data ) ? ( $data['token'] ?? '' ) : '',
			),
			409
		);
	}

	wp_send_json_success( yotm_build_recommendation_progress_response( $job, false ) );
}

/**
 * Process recommendation metadata/content in cursor batches.
 */
function yotm_recommend_batch() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'msg' => __( 'No permission.', 'thumbnail-manager' ) ), 403 );
	}

	check_ajax_referer( 'yotm_prune_nonce', 'nonce' );
	$token = sanitize_text_field( wp_unslash( $_POST['token'] ?? '' ) );
	$batch = isset( $_POST['batch'] ) ? absint( wp_unslash( $_POST['batch'] ) ) : 100;
	$batch = max( 10, min( 500, $batch ) );
	$job   = yotm_job_get( $token );

	if ( is_wp_error( $job ) ) {
		wp_send_json_error( array( 'msg' => $job->get_error_message() ), 400 );
	}

	if ( 'recommendation' !== $job['type'] ) {
		wp_send_json_error( array( 'msg' => __( 'Invalid recommendation job.', 'thumbnail-manager' ) ), 400 );
	}

	if ( 'completed' === $job['status'] ) {
		wp_send_json_success( yotm_build_recommendation_progress_response( $job, true ) );
	}

	if ( 'scanning' !== $job['status'] ) {
		wp_send_json_error( array( 'msg' => __( 'This recommendation job is not scannable.', 'thumbnail-manager' ) ), 409 );
	}

	$payload = $job['payload'];
	$phase   = $payload['scan_phase'] ?? 'metadata';

	if ( 'metadata' === $phase ) {
		$ids = yotm_get_image_attachment_ids_after(
			array(),
			absint( $payload['attachment_after'] ?? 0 ),
			$batch,
			absint( $payload['attachment_max'] ?? 0 )
		);

		if ( ! empty( $ids ) ) {
			yotm_recommend_scan_attachment_metadata_ids( $ids, $payload['metadata_usage'] );
			$payload['attachment_after'] = max( array_map( 'absint', $ids ) );
			$payload['scan_processed']   = (int) ( $payload['scan_processed'] ?? 0 ) + count( $ids );
			yotm_job_update(
				$job['id'],
				array(
					'payload'   => $payload,
					'processed' => (int) $payload['scan_processed'],
				)
			);
			wp_send_json_success( yotm_build_recommendation_progress_response( yotm_job_get_by_id( $job['id'] ), false ) );
		}

		$payload['scan_phase'] = 'content';
		yotm_job_update(
			$job['id'],
			array(
				'payload' => $payload,
				'phase'   => 'content',
			)
		);
		$job     = yotm_job_get_by_id( $job['id'] );
		$payload = $job['payload'];
	}//end if

	$ids = yotm_recommend_get_content_post_ids_after(
		absint( $payload['content_after'] ?? 0 ),
		$batch,
		absint( $payload['content_max'] ?? 0 )
	);

	if ( ! empty( $ids ) ) {
		yotm_recommend_scan_content_ids( $ids, $payload['size_names'], $payload['content_usage'] );
		$payload['content_after']  = max( array_map( 'absint', $ids ) );
		$payload['scan_processed'] = (int) ( $payload['scan_processed'] ?? 0 ) + count( $ids );
		yotm_job_update(
			$job['id'],
			array(
				'payload'   => $payload,
				'processed' => (int) $payload['scan_processed'],
			)
		);
		wp_send_json_success( yotm_build_recommendation_progress_response( yotm_job_get_by_id( $job['id'] ), false ) );
	}

	$current = yotm_job_get_by_id( $job['id'] );
	if ( is_array( $current ) && 'cancelled' === $current['status'] ) {
		wp_send_json_success( yotm_build_recommendation_progress_response( $current, false ) );
	}

	$payload['result']     = yotm_build_recommendation_result( $payload['sizes'], $payload['metadata_usage'], $payload['content_usage'] );
	$payload['scan_phase'] = 'completed';
	yotm_job_update(
		$job['id'],
		array(
			'payload'    => $payload,
			'status'     => 'completed',
			'phase'      => 'completed',
			'processed'  => $job['total'],
			'expires_at' => gmdate( 'Y-m-d H:i:s', time() + 7 * DAY_IN_SECONDS ),
		)
	);

	wp_send_json_success( yotm_build_recommendation_progress_response( yotm_job_get_by_id( $job['id'] ), true ) );
}

/**
 * Scan attachment metadata for a bounded set of IDs.
 *
 * @param int[] $ids Attachment IDs.
 * @param array $usage Usage counters, passed by reference.
 */
function yotm_recommend_scan_attachment_metadata_ids( $ids, &$usage ) {
	foreach ( $ids as $attachment_id ) {
		$file = get_attached_file( $attachment_id );
		if ( ! $file ) {
			continue;
		}

		$metadata = wp_get_attachment_metadata( $attachment_id );
		if ( empty( $metadata['sizes'] ) || ! is_array( $metadata['sizes'] ) ) {
			continue;
		}

		$upload_dir = trailingslashit( dirname( yotm_normalize_filesystem_path( $file ) ) );

		foreach ( $metadata['sizes'] as $size_name => $size_data ) {
			if ( ! isset( $usage[ $size_name ] ) || ! is_array( $size_data ) ) {
				continue;
			}

			$filename = $size_data['file'] ?? ( $size_data['filename'] ?? '' );
			if ( '' === $filename ) {
				continue;
			}

			$path = $upload_dir . wp_basename( $filename );
			++$usage[ $size_name ]['count'];
			if ( is_file( $path ) && is_readable( $path ) ) {
				$bytes                         = filesize( $path );
				$usage[ $size_name ]['bytes'] += false === $bytes ? 0 : (int) $bytes;
			}
		}
	}//end foreach
}

/**
 * Scan content references for a bounded set of post IDs.
 *
 * @param int[]    $post_ids Post IDs.
 * @param string[] $size_names Registered size names.
 * @param array    $usage Usage counters, passed by reference.
 */
function yotm_recommend_scan_content_ids( $post_ids, $size_names, &$usage ) {
	foreach ( $post_ids as $post_id ) {
		$content = (string) get_post_field( 'post_content', $post_id, 'raw' );

		if ( '' === $content ) {
			continue;
		}

		foreach ( $size_names as $name ) {
			$name_rx = preg_quote( $name, '/' );

			if (
				preg_match( '/\bsize-' . $name_rx . '\b/', $content )
				|| preg_match( '/["\'](?:size|sizeSlug|image_size|thumbnail_size)["\']\s*:\s*["\']' . $name_rx . '["\']/', $content )
			) {
				++$usage[ $name ];
			}
		}
	}
}

/**
 * Build final recommendation rows.
 *
 * @param array $sizes Registered sizes.
 * @param array $metadata_usage Metadata counters.
 * @param array $content_usage Content counters.
 * @return array
 */
function yotm_build_recommendation_result( $sizes, $metadata_usage, $content_usage ) {
	$size_names       = array_keys( $sizes );
	$protected_map    = yotm_recommend_protected_sizes( $size_names );
	$recommended_keep = array();
	$items            = array();
	$potential_bytes  = 0;
	$protected_count  = 0;
	$unused_count     = 0;

	foreach ( $sizes as $name => $data ) {
		$width           = absint( $data['width'] ?? 0 );
		$height          = absint( $data['height'] ?? 0 );
		$generated_count = (int) ( $metadata_usage[ $name ]['count'] ?? 0 );
		$bytes           = (int) ( $metadata_usage[ $name ]['bytes'] ?? 0 );
		$content_refs    = (int) ( $content_usage[ $name ] ?? 0 );
		$protected_type  = $protected_map[ $name ] ?? '';
		$status          = 'unused';
		$label           = __( 'No files', 'thumbnail-manager' );
		$reason          = __( 'No generated files or content references were detected.', 'thumbnail-manager' );
		$recommendation  = __( 'Disable if not needed', 'thumbnail-manager' );

		if ( '' !== $protected_type ) {
			$status             = 'protected';
			$label              = $protected_type;
			$reason             = __( 'Protected because this is a core or integration-defined image size.', 'thumbnail-manager' );
			$recommendation     = __( 'Keep', 'thumbnail-manager' );
			$recommended_keep[] = $name;
			++$protected_count;
		} elseif ( $content_refs > 0 ) {
			$status = 'used';
			$label  = __( 'Referenced', 'thumbnail-manager' );
			$reason = sprintf(
				/* translators: 1: content reference count, 2: generated file count. */
				_n(
					'Found %1$d content reference and %2$d generated file.',
					'Found %1$d content references and %2$d generated files.',
					$content_refs,
					'thumbnail-manager'
				),
				$content_refs,
				$generated_count
			);
			$recommendation     = __( 'Keep', 'thumbnail-manager' );
			$recommended_keep[] = $name;
		} elseif ( $generated_count > 0 ) {
			$status = 'warning';
			$label  = __( 'Generated', 'thumbnail-manager' );
			$reason = sprintf(
				/* translators: 1: generated file count, 2: formatted disk usage. */
				_n(
					'Found %1$d generated file using about %2$s, but no content reference.',
					'Found %1$d generated files using about %2$s, but no content reference.',
					$generated_count,
					'thumbnail-manager'
				),
				$generated_count,
				size_format( $bytes )
			);
			$recommendation   = __( 'Review before pruning', 'thumbnail-manager' );
			$potential_bytes += $bytes;
		} else {
			++$unused_count;
		}//end if

		$items[] = array(
			'name'            => $name,
			'dimensions'      => $width . '×' . $height,
			'status'          => $status,
			'label'           => $label,
			'reason'          => $reason,
			'recommendation'  => $recommendation,
			'generated_count' => $generated_count,
			'content_refs'    => $content_refs,
			'bytes'           => $bytes,
		);
	}//end foreach

	$recommended_keep = array_values( array_unique( $recommended_keep ) );

	return array(
		'items'            => $items,
		'keep_count'       => count( $recommended_keep ),
		'unused_count'     => $unused_count,
		'protected_count'  => $protected_count,
		'savings'          => $potential_bytes > 0 ? size_format( $potential_bytes ) : '0 B',
		'recommended_keep' => $recommended_keep,
	);
}

/**
 * Build recommendation progress/final response.
 *
 * @param array $job Job row.
 * @param bool  $done Whether complete.
 * @return array
 */
function yotm_build_recommendation_progress_response( $job, $done ) {
	$payload   = $job['payload'];
	$total     = max( 1, (int) $job['total'] );
	$processed = min( (int) $job['processed'], $total );

	return array(
		'token'     => $job['token'],
		'status'    => $job['status'],
		'phase'     => (string) ( $payload['scan_phase'] ?? $job['phase'] ),
		'processed' => $processed,
		'total'     => (int) $job['total'],
		'percent'   => $done ? 100 : min( 99, ( $processed / $total ) * 100 ),
		'done'      => (bool) $done,
		'stopped'   => 'cancelled' === $job['status'],
		'result'    => $done && is_array( $payload['result'] ?? null ) ? $payload['result'] : null,
	);
}

/**
 * Base query args for content-reference scans.
 *
 * @param array $overrides Query overrides.
 * @return array
 */
function yotm_recommend_content_query_args( $overrides = array() ) {
	return array_merge(
		array(
			'post_type'              => 'any',
			'post_status'            => array( 'publish', 'private', 'draft', 'future', 'pending' ),
			'fields'                 => 'ids',
			'orderby'                => 'ID',
			'order'                  => 'ASC',
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
		),
		$overrides
	);
}

/**
 * Count content posts once when preparing a job.
 *
 * @return int
 */
function yotm_recommend_count_content_posts() {
	$query = new WP_Query(
		yotm_recommend_content_query_args(
			array(
				'posts_per_page' => 1,
				'no_found_rows'  => false,
			)
		)
	);

	return (int) $query->found_posts;
}

/**
 * Get the content scan upper ID boundary.
 *
 * @return int
 */
function yotm_recommend_get_max_content_post_id() {
	$query = new WP_Query(
		yotm_recommend_content_query_args(
			array(
				'posts_per_page' => 1,
				'orderby'        => 'ID',
				'order'          => 'DESC',
				'no_found_rows'  => true,
			)
		)
	);

	return empty( $query->posts ) ? 0 : absint( $query->posts[0] );
}

/**
 * Fetch content post IDs after a stable cursor.
 *
 * @param int $after_id Cursor.
 * @param int $limit Batch size.
 * @param int $max_id Upper boundary.
 * @return int[]
 */
function yotm_recommend_get_content_post_ids_after( $after_id, $limit, $max_id ) {
	global $wpdb;

	$after_id = absint( $after_id );
	$limit    = max( 1, min( 500, absint( $limit ) ) );
	$max_id   = absint( $max_id );
	$filter   = static function ( $where ) use ( $wpdb, $after_id, $max_id ) {
		if ( $after_id > 0 ) {
			$where .= $wpdb->prepare( " AND {$wpdb->posts}.ID > %d", $after_id );
		}
		if ( $max_id > 0 ) {
			$where .= $wpdb->prepare( " AND {$wpdb->posts}.ID <= %d", $max_id );
		}

		return $where;
	};

	add_filter( 'posts_where', $filter );

	try {
		$query = new WP_Query(
			yotm_recommend_content_query_args(
				array(
					'posts_per_page' => $limit,
					'no_found_rows'  => true,
				)
			)
		);
		$ids   = is_array( $query->posts ) ? array_map( 'absint', $query->posts ) : array();
	} finally {
		remove_filter( 'posts_where', $filter );
	}

	return array_values( array_filter( $ids ) );
}

/**
 * Return image size names that should not be auto-disabled.
 *
 * @param string[] $size_names Registered names.
 * @return array
 */
function yotm_recommend_protected_sizes( $size_names ) {
	$protected = array();
	$core      = array( 'thumbnail', 'medium', 'medium_large', 'large', '1536x1536', '2048x2048' );
	$woo       = array( 'woocommerce_thumbnail', 'woocommerce_single', 'woocommerce_gallery_thumbnail', 'shop_catalog', 'shop_single', 'shop_thumbnail' );

	foreach ( $size_names as $name ) {
		if ( in_array( $name, $core, true ) ) {
			$protected[ $name ] = __( 'Core', 'thumbnail-manager' );
			continue;
		}

		if ( in_array( $name, $woo, true ) || false !== strpos( $name, 'woocommerce' ) ) {
			$protected[ $name ] = __( 'WooCommerce', 'thumbnail-manager' );
		}
	}

	return $protected;
}
