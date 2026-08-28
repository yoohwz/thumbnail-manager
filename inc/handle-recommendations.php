<?php
/**
 * Batched thumbnail-size recommendation scans.
 *
 * @package Thumbnail_Manager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'YOTM_RECOMMENDATION_SCHEMA_VERSION' ) ) {
	define( 'YOTM_RECOMMENDATION_SCHEMA_VERSION', 2 );
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
			'recommendation_schema_version' => YOTM_RECOMMENDATION_SCHEMA_VERSION,
			'registered_sizes_signature'    => yotm_recommend_registered_sizes_signature( $sizes ),
			'sizes'                         => $sizes,
			'size_names'                    => $size_names,
			'metadata_usage'                => $metadata_usage,
			'content_usage'                 => $content_usage,
			'scan_phase'                    => 'metadata',
			'attachment_after'              => 0,
			'attachment_max'                => yotm_get_max_image_attachment_id(),
			'attachment_total'              => $attachment_total,
			'content_after'                 => 0,
			'content_max'                   => yotm_recommend_get_max_content_post_id(),
			'content_total'                 => $content_total,
			'scan_processed'                => 0,
			'scan_total_attachments'        => $attachment_total + $content_total,
		),
		array(
			'status'       => 'scanning',
			'phase'        => 'metadata',
			'counter_mode' => 'cursor_v2',
			'total'        => $attachment_total + $content_total,
			'ttl'          => DAY_IN_SECONDS,
			'exclusive'    => false,
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

	$worker = yotm_job_acquire_worker( $job['id'], array( 'scanning' ), array( 'metadata', 'content' ) );
	if ( is_wp_error( $worker ) ) {
		if ( 'yotm_job_worker_busy' !== $worker->get_error_code() ) {
			wp_send_json_error( array( 'msg' => $worker->get_error_message() ), 503 );
		}

		$data                       = $worker->get_error_data();
		$current                    = is_array( $data ) && is_array( $data['job'] ?? null ) ? $data['job'] : $job;
		$response                   = yotm_build_recommendation_progress_response( $current, ( $current['status'] ?? '' ) === 'completed' );
		$response['retry_after_ms'] = 'scanning' === ( $current['status'] ?? '' ) ? 250 : 0;
		wp_send_json_success( $response );
	}

	$job = yotm_job_get_by_id( $job['id'] );

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
			yotm_job_worker_update(
				$worker,
				array(
					'payload'   => $payload,
					'processed' => (int) $payload['scan_processed'],
				)
			);
			wp_send_json_success( yotm_build_recommendation_progress_response( yotm_job_get_by_id( $job['id'] ), false ) );
		}

		$payload['scan_phase'] = 'content';
		yotm_job_worker_update(
			$worker,
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
		yotm_job_worker_update(
			$worker,
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

	$payload['result']     = yotm_build_recommendation_result(
		$payload['sizes'],
		$payload['metadata_usage'],
		$payload['content_usage'],
		array(
			'registered_sizes_signature' => $payload['registered_sizes_signature'] ?? '',
			'attachment_after'           => $payload['attachment_after'] ?? 0,
			'attachment_max'             => $payload['attachment_max'] ?? 0,
			'attachment_total'           => $payload['attachment_total'] ?? 0,
			'content_after'              => $payload['content_after'] ?? 0,
			'content_max'                => $payload['content_max'] ?? 0,
			'content_total'              => $payload['content_total'] ?? 0,
		)
	);
	$payload['scan_phase'] = 'completed';
	yotm_job_worker_update(
		$worker,
		array(
			'payload'    => $payload,
			'status'     => 'completed',
			'phase'      => 'completed',
			'processed'  => $job['total'],
			'expires_at' => gmdate( 'Y-m-d H:i:s', time() + YOTM_JOB_AUDIT_RETENTION_SECONDS ),
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
 * Normalize registered sizes for stable recommendation signatures.
 *
 * @param array $sizes Registered sizes.
 * @return array
 */
function yotm_recommend_normalize_registered_sizes( $sizes ) {
	$normalized = array();

	foreach ( (array) $sizes as $name => $data ) {
		$crop = $data['crop'] ?? false;
		if ( is_array( $crop ) ) {
			$crop = array_values( array_map( 'sanitize_key', $crop ) );
		} else {
			$crop = (bool) $crop;
		}

		$normalized[ (string) $name ] = array(
			'width'  => absint( $data['width'] ?? 0 ),
			'height' => absint( $data['height'] ?? 0 ),
			'crop'   => $crop,
		);
	}

	ksort( $normalized, SORT_STRING );

	return $normalized;
}

/**
 * Build a stable signature for a registered-size snapshot.
 *
 * @param array $sizes Registered sizes.
 * @return string
 */
function yotm_recommend_registered_sizes_signature( $sizes ) {
	$encoded = wp_json_encode( yotm_recommend_normalize_registered_sizes( $sizes ) );

	return hash( 'sha256', false === $encoded ? '[]' : $encoded );
}

/**
 * Classify a recommendation from positive evidence only.
 *
 * @param string $protected_type Policy protection label.
 * @param int    $content_refs Number of detected content references.
 * @return array
 */
function yotm_recommend_classify_item( $protected_type, $content_refs ) {
	if ( '' !== $protected_type ) {
		return array(
			'status'       => 'protected',
			'confidence'   => 'high',
			'apply_action' => 'enable',
		);
	}

	if ( $content_refs > 0 ) {
		return array(
			'status'       => 'detected_reference',
			'confidence'   => 'medium',
			'apply_action' => 'enable',
		);
	}

	return array(
		'status'       => 'unknown',
		'confidence'   => 'low',
		'apply_action' => 'preserve',
	);
}

/**
 * Build structured evidence for one registered size.
 *
 * @param string $protected_type Policy protection label.
 * @param int    $content_refs Number of detected content references.
 * @param int    $generated_count Number of generated metadata entries.
 * @param int    $bytes Readable generated bytes.
 * @return array
 */
function yotm_recommend_build_evidence( $protected_type, $content_refs, $generated_count, $bytes ) {
	$evidence = array();

	if ( 'core' === $protected_type ) {
		$evidence[] = array(
			'code'        => 'policy_core',
			'strength'    => 'strong',
			'description' => __( 'WordPress Core policy protects this size.', 'thumbnail-manager' ),
		);
	} elseif ( 'woocommerce' === $protected_type ) {
		$evidence[] = array(
			'code'        => 'policy_woocommerce',
			'strength'    => 'strong',
			'description' => __( 'WooCommerce naming policy protects this size; runtime use is not asserted.', 'thumbnail-manager' ),
		);
	}

	if ( $content_refs > 0 ) {
		$evidence[] = array(
			'code'        => 'content_reference_detected',
			'strength'    => 'positive_heuristic',
			'count'       => $content_refs,
			'description' => sprintf(
				/* translators: %d: number of content references detected. */
				_n( '%d content reference was detected.', '%d content references were detected.', $content_refs, 'thumbnail-manager' ),
				$content_refs
			),
		);
	} else {
		$evidence[] = array(
			'code'        => 'content_reference_not_detected',
			'strength'    => 'inconclusive',
			'count'       => 0,
			'description' => __( 'No matching content reference was detected in this bounded scan; dynamic use remains possible.', 'thumbnail-manager' ),
		);
	}

	if ( $generated_count > 0 ) {
		$evidence[] = array(
			'code'        => 'generated_metadata_present',
			'strength'    => 'historical',
			'count'       => $generated_count,
			'bytes'       => $bytes,
			'description' => sprintf(
				/* translators: 1: generated metadata entry count, 2: formatted readable bytes. */
				_n(
					'%1$d generated metadata entry was found using about %2$s.',
					'%1$d generated metadata entries were found using about %2$s.',
					$generated_count,
					'thumbnail-manager'
				),
				$generated_count,
				size_format( $bytes )
			),
		);
	} else {
		$evidence[] = array(
			'code'        => 'generated_metadata_not_detected',
			'strength'    => 'inconclusive',
			'count'       => 0,
			'bytes'       => 0,
			'description' => __( 'No generated metadata entry was detected; this does not prove the size is unused.', 'thumbnail-manager' ),
		);
	}//end if

	return $evidence;
}

/**
 * Build final recommendation rows.
 *
 * @param array $sizes Registered sizes.
 * @param array $metadata_usage Metadata counters.
 * @param array $content_usage Content counters.
 * @param array $coverage_context Scan coverage context.
 * @return array
 */
function yotm_build_recommendation_result( $sizes, $metadata_usage, $content_usage, $coverage_context = array() ) {
	$size_names               = array_keys( $sizes );
	$protected_map            = yotm_recommend_protected_sizes( $size_names );
	$items                    = array();
	$generated_bytes          = 0;
	$protected_count          = 0;
	$detected_reference_count = 0;
	$unknown_count            = 0;

	foreach ( $sizes as $name => $data ) {
		$width           = absint( $data['width'] ?? 0 );
		$height          = absint( $data['height'] ?? 0 );
		$generated_count = (int) ( $metadata_usage[ $name ]['count'] ?? 0 );
		$bytes           = (int) ( $metadata_usage[ $name ]['bytes'] ?? 0 );
		$content_refs    = (int) ( $content_usage[ $name ] ?? 0 );
		$protected_type  = (string) ( $protected_map[ $name ] ?? '' );
		$classification  = yotm_recommend_classify_item( $protected_type, $content_refs );
		$label           = __( 'Unknown', 'thumbnail-manager' );
		$reason          = __( 'Usage remains unresolved because absence of detected references is not proof that this size is unused.', 'thumbnail-manager' );
		$recommendation  = __( 'Preserve current setting and review', 'thumbnail-manager' );

		if ( 'protected' === $classification['status'] ) {
			$label          = 'core' === $protected_type ? __( 'Core', 'thumbnail-manager' ) : __( 'WooCommerce', 'thumbnail-manager' );
			$reason         = __( 'Protected by an established Core or integration naming policy.', 'thumbnail-manager' );
			$recommendation = __( 'Keep enabled', 'thumbnail-manager' );
			++$protected_count;
		} elseif ( 'detected_reference' === $classification['status'] ) {
			$label          = __( 'Reference detected', 'thumbnail-manager' );
			$reason         = __( 'A positive content heuristic supports keeping this size, but does not prove current runtime use.', 'thumbnail-manager' );
			$recommendation = __( 'Keep enabled', 'thumbnail-manager' );
			++$detected_reference_count;
		} else {
			++$unknown_count;
		}

		$generated_bytes += max( 0, $bytes );
		$items[]          = array(
			'name'            => $name,
			'dimensions'      => $width . '×' . $height,
			'status'          => $classification['status'],
			'confidence'      => $classification['confidence'],
			'apply_action'    => $classification['apply_action'],
			'label'           => $label,
			'reason'          => $reason,
			'recommendation'  => $recommendation,
			'evidence'        => yotm_recommend_build_evidence( $protected_type, $content_refs, $generated_count, $bytes ),
			'generated_count' => $generated_count,
			'content_refs'    => $content_refs,
			'bytes'           => $bytes,
		);
	}//end foreach

	$signature = (string) ( $coverage_context['registered_sizes_signature'] ?? '' );
	if ( '' === $signature ) {
		$signature = yotm_recommend_registered_sizes_signature( $sizes );
	}

	return array(
		'schema_version'             => YOTM_RECOMMENDATION_SCHEMA_VERSION,
		'registered_sizes_signature' => $signature,
		'items'                      => $items,
		'protected_count'            => $protected_count,
		'detected_reference_count'   => $detected_reference_count,
		'unknown_count'              => $unknown_count,
		'generated_bytes'            => $generated_bytes,
		'generated_bytes_human'      => $generated_bytes > 0 ? size_format( $generated_bytes ) : '0 B',
		'coverage'                   => array(
			'metadata'    => array(
				'complete'         => true,
				'cursor'           => absint( $coverage_context['attachment_after'] ?? 0 ),
				'max_id'           => absint( $coverage_context['attachment_max'] ?? 0 ),
				'total_at_prepare' => absint( $coverage_context['attachment_total'] ?? 0 ),
			),
			'content'     => array(
				'complete'         => true,
				'cursor'           => absint( $coverage_context['content_after'] ?? 0 ),
				'max_id'           => absint( $coverage_context['content_max'] ?? 0 ),
				'total_at_prepare' => absint( $coverage_context['content_total'] ?? 0 ),
			),
			'limitations' => array( 'bounded_id_snapshot', 'post_content_patterns_only', 'dynamic_usage_not_observed' ),
		),
		// Conservative legacy summaries for cached v1 presentation.
		'keep_count'                 => $protected_count + $detected_reference_count,
		'unused_count'               => 0,
		'savings'                    => '0 B',
	);
}

/**
 * Project a recommendation result safely for old browser clients.
 *
 * This projection is response-only. It must never be persisted back to the job.
 *
 * @param array $result Persisted recommendation result.
 * @return array
 */
function yotm_recommendation_result_for_response( $result ) {
	$projected                     = is_array( $result ) ? $result : array();
	$projected['recommended_keep'] = array_values( array_keys( yotm_get_registered_sizes() ) );

	return $projected;
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
	$result    = null;

	if ( $done && is_array( $payload['result'] ?? null ) ) {
		$result = yotm_recommendation_result_for_response( $payload['result'] );
	}

	return array(
		'token'     => $job['token'],
		'status'    => $job['status'],
		'phase'     => (string) ( $payload['scan_phase'] ?? $job['phase'] ),
		'processed' => $processed,
		'total'     => (int) $job['total'],
		'percent'   => $done ? 100 : min( 99, ( $processed / $total ) * 100 ),
		'done'      => (bool) $done,
		'stopped'   => in_array( $job['status'], array( 'cancelled', 'expired' ), true ),
		'result'    => $result,
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
			$protected[ $name ] = 'core';
			continue;
		}

		if ( in_array( $name, $woo, true ) || false !== strpos( $name, 'woocommerce' ) ) {
			$protected[ $name ] = 'woocommerce';
		}
	}

	return $protected;
}
