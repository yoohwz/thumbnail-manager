<?php
/**
 * Transport-neutral Recommendations application sequencing and policy.
 *
 * @package Thumbnail_Manager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'YOTM_RECOMMENDATION_SCHEMA_VERSION' ) ) {
	define( 'YOTM_RECOMMENDATION_SCHEMA_VERSION', 2 );
}

/**
 * Build a successful transport-neutral application outcome.
 *
 * @param mixed $data Response data.
 * @param int   $status HTTP status.
 * @return array{success:bool,data:mixed,status:int}
 */
function yotm_recommend_application_success( $data, $status = 200 ) {
	return array(
		'success' => true,
		'data'    => $data,
		'status'  => absint( $status ),
	);
}

/**
 * Build a failed transport-neutral application outcome.
 *
 * @param mixed $data Response data.
 * @param int   $status HTTP status.
 * @return array{success:bool,data:mixed,status:int}
 */
function yotm_recommend_application_error( $data, $status = 400 ) {
	return array(
		'success' => false,
		'data'    => $data,
		'status'  => absint( $status ),
	);
}

/**
 * Prepare a batched recommendation scan without transport concerns.
 *
 * @return array{success:bool,data:mixed,status:int}
 */
function yotm_recommend_prepare_application() {
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
			'content_samples'               => array(),
			'statistics_schema_version'     => 1,
			'attachments_scanned'           => 0,
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
		return yotm_recommend_application_error(
			array(
				'msg'          => $job->get_error_message(),
				'resume_token' => is_array( $data ) ? ( $data['token'] ?? '' ) : '',
			),
			409
		);
	}

	return yotm_recommend_application_success( yotm_build_recommendation_progress_response( $job, false ) );
}

/**
 * Process recommendation metadata/content in cursor batches without transport concerns.
 *
 * @param string $token Job token.
 * @param int    $batch Batch size.
 * @return array{success:bool,data:mixed,status:int}
 */
function yotm_recommend_batch_application( $token, $batch ) {
	$token = sanitize_text_field( (string) $token );
	$batch = max( 10, min( 500, absint( $batch ) ) );
	$job   = yotm_job_get( $token );

	if ( is_wp_error( $job ) ) {
		return yotm_recommend_application_error( array( 'msg' => $job->get_error_message() ), 400 );
	}

	if ( 'recommendation' !== $job['type'] ) {
		return yotm_recommend_application_error( array( 'msg' => __( 'Invalid recommendation job.', 'thumbnail-manager' ) ), 400 );
	}

	if ( 'completed' === $job['status'] ) {
		return yotm_recommend_application_success( yotm_build_recommendation_progress_response( $job, true ) );
	}

	if ( 'scanning' !== $job['status'] ) {
		return yotm_recommend_application_error( array( 'msg' => __( 'This recommendation job is not scannable.', 'thumbnail-manager' ) ), 409 );
	}

	$worker = yotm_job_acquire_worker( $job['id'], array( 'scanning' ), array( 'metadata', 'content' ) );
	if ( is_wp_error( $worker ) ) {
		if ( 'yotm_job_worker_busy' !== $worker->get_error_code() ) {
			return yotm_recommend_application_error( array( 'msg' => $worker->get_error_message() ), 503 );
		}

		$data                       = $worker->get_error_data();
		$current                    = is_array( $data ) && is_array( $data['job'] ?? null ) ? $data['job'] : $job;
		$response                   = yotm_build_recommendation_progress_response( $current, ( $current['status'] ?? '' ) === 'completed' );
		$response['retry_after_ms'] = 'scanning' === ( $current['status'] ?? '' ) ? 250 : 0;
		return yotm_recommend_application_success( $response );
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
			$scanned = yotm_recommend_scan_attachment_metadata_ids( $ids, $payload['metadata_usage'], $worker );
			if ( is_wp_error( $scanned ) ) {
				return yotm_recommend_application_error( array( 'msg' => $scanned->get_error_message() ), 503 );
			}
			$payload['attachments_scanned'] = (int) ( $payload['attachments_scanned'] ?? 0 ) + count( $ids );
			$payload['attachment_after']    = max( array_map( 'absint', $ids ) );
			$payload['scan_processed']      = (int) ( $payload['scan_processed'] ?? 0 ) + count( $ids );
			yotm_job_worker_update(
				$worker,
				array(
					'payload'   => $payload,
					'processed' => (int) $payload['scan_processed'],
				)
			);
			return yotm_recommend_application_success( yotm_build_recommendation_progress_response( yotm_job_get_by_id( $job['id'] ), false ) );
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
		$scanned = yotm_recommend_scan_content_ids( $ids, $payload['size_names'], $payload['content_usage'], $payload['content_samples'] );
		if ( is_wp_error( $scanned ) ) {
			return yotm_recommend_application_error( array( 'msg' => $scanned->get_error_message() ), 503 );
		}
		$payload['content_after']  = max( array_map( 'absint', $ids ) );
		$payload['scan_processed'] = (int) ( $payload['scan_processed'] ?? 0 ) + count( $ids );
		yotm_job_worker_update(
			$worker,
			array(
				'payload'   => $payload,
				'processed' => (int) $payload['scan_processed'],
			)
		);
		return yotm_recommend_application_success( yotm_build_recommendation_progress_response( yotm_job_get_by_id( $job['id'] ), false ) );
	}

	$current = yotm_job_get_by_id( $job['id'] );
	if ( is_array( $current ) && 'cancelled' === $current['status'] ) {
		return yotm_recommend_application_success( yotm_build_recommendation_progress_response( $current, false ) );
	}

	$statistics = null;
	if ( 1 === (int) ( $payload['statistics_schema_version'] ?? 0 ) ) {
		$statistics = yotm_recommend_statistics( $job['id'], $payload );
		if ( is_wp_error( $statistics ) ) {
			return yotm_recommend_application_error( array( 'msg' => $statistics->get_error_message() ), 503 );
		}
	}

	$payload['result']     = yotm_build_recommendation_result(
		$payload['sizes'],
		$payload['metadata_usage'],
		$payload['content_usage'],
		array(
			'registered_sizes_signature' => $payload['registered_sizes_signature'] ?? '',
			'content_samples'            => $payload['content_samples'] ?? array(),
			'statistics'                 => $statistics,
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

	return yotm_recommend_application_success( yotm_build_recommendation_progress_response( yotm_job_get_by_id( $job['id'] ), true ) );
}

/**
 * Scan attachment metadata for a bounded set of IDs.
 *
 * @param int[]      $ids Attachment IDs.
 * @param array      $usage Usage counters, passed by reference.
 * @param array|null $worker Optional current worker for persisted observations.
 * @return true|WP_Error
 */
function yotm_recommend_scan_attachment_metadata_ids( $ids, &$usage, $worker = null ) {
	$ids = array_values( array_filter( array_map( 'absint', (array) $ids ) ) );
	update_meta_cache( 'post', $ids );
	foreach ( $ids as $attachment_id ) {
		$file     = get_attached_file( $attachment_id );
		$metadata = wp_get_attachment_metadata( $attachment_id );
		foreach ( (array) ( $metadata['sizes'] ?? array() ) as $size_name => $size_data ) {
			if ( ! isset( $usage[ $size_name ] ) ) {
				continue;
			}
			++$usage[ $size_name ]['count'];
			$filename = is_array( $size_data ) ? ( $size_data['file'] ?? ( $size_data['filename'] ?? '' ) ) : '';
			$path     = $file && is_string( $filename ) && '' !== $filename && wp_basename( $filename ) === $filename
				? yotm_media_source_canonical_path( trailingslashit( dirname( $file ) ) . $filename ) : new WP_Error( 'unverified' );
			if ( is_wp_error( $path ) ) {
				$usage[ $size_name ]['unverified_count'] = (int) ( $usage[ $size_name ]['unverified_count'] ?? 0 ) + 1;
				continue;
			}
			clearstatcache( true, $path );
			$state = 'missing';
			$bytes = 0;
			if ( file_exists( $path ) ) {
				if ( ! is_file( $path ) ) {
					$usage[ $size_name ]['unverified_count'] = (int) ( $usage[ $size_name ]['unverified_count'] ?? 0 ) + 1;
					continue;
				}
				$observed_bytes = is_readable( $path ) ? filesize( $path ) : false;
				$state          = false === $observed_bytes ? 'unreadable' : 'checked';
				$bytes          = false === $observed_bytes ? 0 : max( 0, (int) $observed_bytes );
			}
			$usage[ $size_name ]['bytes'] += $bytes;
			if ( is_array( $worker ) ) {
				$stored = yotm_recommend_record_file_observation( $worker, $path, $state, $bytes );
				if ( is_wp_error( $stored ) ) {
					return $stored;
				}
			}
		}//end foreach
	}//end foreach
	return true;
}

/**
 * Store an inert, unique canonical-path observation behind the current worker.
 * No filesystem path or destructive authority is stored in this evidence row.
 *
 * @param array  $worker Worker fence.
 * @param string $path Canonical observed path.
 * @param string $state Observation state.
 * @param int    $bytes Readable bytes.
 * @return true|WP_Error
 */
function yotm_recommend_record_file_observation( $worker, $path, $state, $bytes ) {
	global $wpdb;
	$tables = yotm_job_table_names();
	$now    = gmdate( 'Y-m-d H:i:s' );
	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Plugin-owned table names.
	$sql = $wpdb->prepare(
		"INSERT IGNORE INTO {$tables['items']} (job_id,item_key,status,payload,error,bytes,created_at,updated_at)
		SELECT id,%s,'recommend_observed',%s,'',%d,%s,%s FROM {$tables['jobs']}
		WHERE id=%d AND type='recommendation' AND status='scanning' AND phase='metadata'
		AND worker_token=%s AND worker_generation=%d AND expires_at >= %s",
		hash( 'sha256', 'recommend-file:' . $path ),
		wp_json_encode( array( 'observation' => $state ) ),
		$bytes,
		$now,
		$now,
		$worker['job_id'],
		$worker['token'],
		$worker['generation'],
		$now
	);
	// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Prepared exact-worker inert observation.
	$inserted = $wpdb->query( $sql );
	if ( false === $inserted || ( 0 === $inserted && ! yotm_job_refresh_worker( $worker ) ) ) {
		return new WP_Error( 'yotm_recommend_observation_failed', __( 'Could not retain file observations. Retry this scan batch.', 'thumbnail-manager' ) );
	}
	return true;
}

/**
 * Aggregate retained observations without loading the path set into PHP memory.
 *
 * @param int   $job_id Job ID.
 * @param array $payload Scan counters.
 * @return array|WP_Error
 */
function yotm_recommend_statistics( $job_id, $payload ) {
	global $wpdb;
	$tables = yotm_job_table_names();
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Plugin-owned table; bounded aggregate result.
	$rows = $wpdb->get_results( $wpdb->prepare( "SELECT payload, COUNT(*) file_count, SUM(bytes) byte_count FROM {$tables['items']} WHERE job_id=%d AND status='recommend_observed' GROUP BY payload", $job_id ), ARRAY_A );
	if ( '' !== (string) $wpdb->last_error ) {
		return new WP_Error( 'yotm_recommend_statistics_failed', __( 'Could not read file observations.', 'thumbnail-manager' ) );
	}
	$stats = array(
		'schema_version'      => 1,
		'attachments_scanned' => absint( $payload['attachments_scanned'] ?? 0 ),
		'metadata_entries'    => 0,
		'checked_files'       => 0,
		'checked_bytes'       => 0,
		'missing_files'       => 0,
		'unreadable_files'    => 0,
		'unverified_entries'  => 0,
		'complete'            => true,
		'identity'            => 'canonical_path',
		'limitations'         => array( 'registered_metadata_only', 'observed_during_scan_not_live_snapshot', 'canonical_paths_not_inode_identity' ),
	);
	foreach ( (array) ( $payload['metadata_usage'] ?? array() ) as $usage ) {
		$stats['metadata_entries']   += absint( $usage['count'] ?? 0 );
		$stats['unverified_entries'] += absint( $usage['unverified_count'] ?? 0 );
	}
	foreach ( $rows as $row ) {
		$observation = json_decode( $row['payload'], true );
		$state       = $observation['observation'] ?? '';
		if ( in_array( $state, array( 'checked', 'missing', 'unreadable' ), true ) ) {
			$stats[ $state . '_files' ] += absint( $row['file_count'] );
			if ( 'checked' === $state ) {
				$stats['checked_bytes'] += absint( $row['byte_count'] );
			}
		}
	}
	return $stats;
}

/**
 * Load raw post content for one already-bounded set of post IDs.
 *
 * @param int[] $post_ids Post IDs selected by the recommendation cursor.
 * @return array<int,string>|WP_Error
 */
function yotm_recommend_load_content_map( $post_ids ) {
	global $wpdb;

	$post_ids = array_values( array_unique( array_filter( array_map( 'absint', (array) $post_ids ) ) ) );
	if ( empty( $post_ids ) ) {
		return array();
	}

	$placeholders = implode( ',', array_fill( 0, count( $post_ids ), '%d' ) );
	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Placeholder list is generated from the bounded ID array.
	$sql = $wpdb->prepare(
		"SELECT ID, post_content FROM {$wpdb->posts} WHERE ID IN ({$placeholders})",
		...$post_ids
	);
	// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Cursor-selected raw content must be read in one bounded query; prepared above.
	$rows = $wpdb->get_results( $sql, ARRAY_A );

	if ( '' !== (string) $wpdb->last_error ) {
		return new WP_Error( 'yotm_recommend_content_read_failed', __( 'Could not read the selected post content batch.', 'thumbnail-manager' ) );
	}

	$content = array();
	foreach ( (array) $rows as $row ) {
		$content[ absint( $row['ID'] ?? 0 ) ] = (string) ( $row['post_content'] ?? '' );
	}

	return $content;
}

/**
 * Match registered size references using the exact legacy boundaries.
 *
 * The combined patterns reduce normal work to two content traversals. If the
 * pattern cannot be compiled, the caller falls back to the legacy per-size
 * matcher instead of changing recommendation evidence.
 *
 * @param string   $content Post content.
 * @param string[] $size_names Registered size names.
 * @return string[]|false Matched names, or false when the legacy fallback is required.
 */
function yotm_recommend_extract_content_size_names( $content, $size_names ) {
	$names = array_values(
		array_unique(
			array_filter(
				array_map(
					static function ( $name ) {
						return (string) $name;
					},
					(array) $size_names
				),
				static function ( $name ) {
					return '' !== $name;
				}
			)
		)
	);
	if ( '' === (string) $content || empty( $names ) ) {
		return array();
	}

	usort(
		$names,
		static function ( $left, $right ) {
			$length = strlen( $right ) <=> strlen( $left );
			return 0 !== $length ? $length : strcmp( $left, $right );
		}
	);
	$alternation   = implode(
		'|',
		array_map(
			static function ( $name ) {
				return preg_quote( $name, '/' );
			},
			$names
		)
	);
	$class_pattern = '/\bsize-(' . $alternation . ')\b/';
	$json_pattern  = '/["\'](?:size|sizeSlug|image_size|thumbnail_size)["\']\s*:\s*["\'](' . $alternation . ')["\']/';
	$matched       = array();
	$class_result  = preg_match_all( $class_pattern, $content, $class_matches, PREG_OFFSET_CAPTURE );
	if ( false === $class_result ) {
		return false;
	}

	foreach ( (array) ( $class_matches[0] ?? array() ) as $class_match ) {
		$tail = substr( $content, absint( $class_match[1] ?? 0 ) );
		foreach ( $names as $name ) {
			if ( 1 === preg_match( '/^size-' . preg_quote( $name, '/' ) . '\b/', $tail ) ) {
				$matched[ $name ] = true;
			}
		}
	}

	$json_result = preg_match_all( $json_pattern, $content, $json_matches );
	if ( false === $json_result ) {
		return false;
	}
	foreach ( (array) ( $json_matches[1] ?? array() ) as $name ) {
		$matched[ (string) $name ] = true;
	}

	return array_keys( $matched );
}

/**
 * Return whether one size matches the original recommendation patterns.
 *
 * @param string $content Post content.
 * @param string $name Registered size name.
 * @return bool
 */
function yotm_recommend_content_matches_size( $content, $name ) {
	$name_rx = preg_quote( (string) $name, '/' );

	return 1 === preg_match( '/\bsize-' . $name_rx . '\b/', (string) $content )
		|| 1 === preg_match( '/["\'](?:size|sizeSlug|image_size|thumbnail_size)["\']\s*:\s*["\']' . $name_rx . '["\']/', (string) $content );
}

/**
 * Scan content references for a bounded set of post IDs.
 *
 * @param int[]      $post_ids Post IDs.
 * @param string[]   $size_names Registered size names.
 * @param array      $usage Usage counters, passed by reference.
 * @param array|null $samples Optional bounded evidence samples, passed by reference.
 * @return true|WP_Error
 */
function yotm_recommend_scan_content_ids( $post_ids, $size_names, &$usage, &$samples = null ) {
	$content_map = yotm_recommend_load_content_map( $post_ids );
	if ( is_wp_error( $content_map ) ) {
		return $content_map;
	}

	foreach ( $post_ids as $post_id ) {
		$content = (string) ( $content_map[ absint( $post_id ) ] ?? '' );

		if ( '' === $content ) {
			continue;
		}

		$matched = yotm_recommend_extract_content_size_names( $content, $size_names );
		if ( false === $matched ) {
			$matched = array_filter(
				(array) $size_names,
				static function ( $name ) use ( $content ) {
					return yotm_recommend_content_matches_size( $content, $name );
				}
			);
		}

		foreach ( $matched as $name ) {
			if ( array_key_exists( $name, $usage ) ) {
				++$usage[ $name ];
				if ( is_array( $samples ) && count( $samples[ $name ] ?? array() ) < 5 ) {
					$kinds = array();
					if ( 1 === preg_match( '/\\bsize-' . preg_quote( $name, '/' ) . '\\b/', $content ) ) {
						$kinds[] = 'class_size_hint';
					}
					if ( 1 === preg_match( '/["\'](?:size|sizeSlug|image_size|thumbnail_size)["\']\\s*:\\s*["\']' . preg_quote( $name, '/' ) . '["\']/', $content ) ) {
						$kinds[] = 'json_size_hint';
					}
					$samples[ $name ][ absint( $post_id ) ] = array(
						'post_id' => absint( $post_id ),
						'kinds'   => $kinds,
					);
				}
			}
		}
	}//end foreach

	return true;
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
			'content_samples' => array_values( $coverage_context['content_samples'][ $name ] ?? array() ),
			'bytes'           => $bytes,
		);
	}//end foreach

	$signature = (string) ( $coverage_context['registered_sizes_signature'] ?? '' );
	if ( '' === $signature ) {
		$signature = yotm_recommend_registered_sizes_signature( $sizes );
	}

	$statistics = is_array( $coverage_context['statistics'] ?? null ) ? $coverage_context['statistics'] : null;
	if ( $statistics ) {
		$generated_bytes = $statistics['checked_bytes'];
	}

	return array(
		'statistics'                 => $statistics,
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
 * @param mixed $result Persisted recommendation result.
 * @return array
 */
function yotm_recommendation_result_for_response( $result ) {
	$root_keys = array( 'schema_version', 'registered_sizes_signature', 'items', 'statistics', 'protected_count', 'detected_reference_count', 'unknown_count', 'generated_bytes', 'generated_bytes_human', 'coverage', 'keep_count', 'unused_count', 'savings' );
	$projected = is_array( $result ) ? array_intersect_key( $result, array_flip( $root_keys ) ) : array();
	foreach ( (array) ( $projected['items'] ?? array() ) as $index => $item ) {
		$allowed            = array( 'name', 'dimensions', 'status', 'confidence', 'apply_action', 'label', 'reason', 'recommendation', 'evidence', 'generated_count', 'content_refs', 'bytes' );
		$public             = is_array( $item ) ? array_intersect_key( $item, array_flip( $allowed ) ) : array();
		$public['evidence'] = array();
		foreach ( array_slice( (array) ( $item['evidence'] ?? array() ), 0, 5 ) as $evidence ) {
			if ( is_array( $evidence ) ) {
				$public['evidence'][] = array_intersect_key( $evidence, array_flip( array( 'code', 'strength', 'count', 'bytes', 'description' ) ) );
			}
		}
		$public['content_samples'] = array();
		foreach ( array_slice( (array) ( $item['content_samples'] ?? array() ), 0, 5 ) as $sample ) {
			$post_id = absint( $sample['post_id'] ?? 0 );
			if ( ! $post_id ) {
				continue;
			}
			// Core rechecks the current user's edit capability before returning a link.
			$url = get_edit_post_link( $post_id, 'raw' );
			if ( ! $url ) {
				continue;
			}
			$public['content_samples'][] = array(
				'post_id' => $post_id,
				'title'   => get_the_title( $post_id ),
				'url'     => esc_url_raw( $url, array( 'http', 'https' ) ),
				'kinds'   => array_values( array_intersect( (array) ( $sample['kinds'] ?? array() ), array( 'class_size_hint', 'json_size_hint' ) ) ),
			);
		}
		$projected['items'][ $index ] = $public;
	}//end foreach
	if ( is_array( $projected['statistics'] ?? null ) ) {
		$projected['statistics'] = array_intersect_key( $projected['statistics'], array_flip( array( 'schema_version', 'attachments_scanned', 'metadata_entries', 'checked_files', 'checked_bytes', 'missing_files', 'unreadable_files', 'unverified_entries', 'complete', 'identity', 'limitations' ) ) );
	}
	$projected['recommended_keep'] = array_values( array_keys( yotm_get_registered_sizes() ) );

	return $projected;
}

/**
 * Project a recommendation result for public delivery.
 *
 * @param mixed         $result Persisted recommendation result.
 * @param callable|null $projector Optional projector override for tests.
 * @return array|null
 */
function yotm_recommendation_public_result_application( $result, $projector = null ) {
	if ( null === $projector ) {
		if ( ! function_exists( 'yotm_recommendation_result_for_response' ) ) {
			return null;
		}

		$projector = 'yotm_recommendation_result_for_response';
	}

	if ( ! is_callable( $projector ) ) {
		return null;
	}

	$projected = call_user_func( $projector, $result );

	return is_array( $projected ) ? $projected : null;
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

	if ( $done ) {
		$result = yotm_recommendation_result_for_response( $payload['result'] ?? array() );
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
