<?php
/**
 * Read-only query benchmark for the bounded scan primitives.
 *
 * Run from the WordPress root:
 * wp eval-file wp-content/plugins/thumbnail-manager/tests/performance/scan-benchmark.php
 *
 * @package Thumbnail_Manager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Benchmark one callable and print deterministic query/timing evidence.
 *
 * @param string   $label Benchmark label.
 * @param callable $callback Operation under test.
 * @return mixed
 * @throws RuntimeException When the operation returns a WordPress error.
 */
function yotm_scan_benchmark_run( $label, $callback ) {
	global $wpdb;

	$before_queries = (int) $wpdb->num_queries;
	$before_time    = microtime( true );
	$result         = $callback();
	$elapsed_ms     = ( microtime( true ) - $before_time ) * 1000;
	$query_count    = (int) $wpdb->num_queries - $before_queries;

	if ( is_wp_error( $result ) ) {
		throw new RuntimeException( esc_html( $label . ': ' . $result->get_error_message() ) );
	}

	WP_CLI::log( sprintf( '%s queries=%d elapsed_ms=%.2f', $label, $query_count, $elapsed_ms ) );

	return $result;
}

$limit = 500;
$ids   = get_posts(
	array(
		'post_type'              => 'attachment',
		'post_status'            => 'inherit',
		'post_mime_type'         => 'image',
		'posts_per_page'         => $limit,
		'fields'                 => 'ids',
		'orderby'                => 'ID',
		'order'                  => 'ASC',
		'no_found_rows'          => true,
		'update_post_meta_cache' => false,
		'update_post_term_cache' => false,
	)
);
$ids   = array_values( array_filter( array_map( 'absint', $ids ) ) );

if ( empty( $ids ) ) {
	WP_CLI::warning( 'No image attachments are available; scan benchmark was not run.' );
	return;
}

$usage = array_fill_keys(
	array_keys( yotm_get_registered_sizes() ),
	array(
		'count' => 0,
		'bytes' => 0,
	)
);
yotm_scan_benchmark_run(
	'attachment_metadata_batch_' . count( $ids ),
	static function () use ( $ids, &$usage ) {
		yotm_recommend_scan_attachment_metadata_ids( $ids, $usage );
		return true;
	}
);

yotm_scan_benchmark_run(
	'raw_postmeta_batch_' . count( $ids ),
	static function () use ( $ids ) {
		return yotm_media_reference_raw_postmeta_rows_batch( $ids, array( '_wp_attached_file', '_wp_attachment_metadata' ) );
	}
);

$post_ids      = get_posts(
	array(
		'post_type'              => 'any',
		'post_status'            => 'any',
		'posts_per_page'         => $limit,
		'fields'                 => 'ids',
		'orderby'                => 'ID',
		'order'                  => 'ASC',
		'no_found_rows'          => true,
		'update_post_meta_cache' => false,
		'update_post_term_cache' => false,
	)
);
$content_usage = array_fill_keys( array_keys( yotm_get_registered_sizes() ), 0 );
yotm_scan_benchmark_run(
	'content_match_batch_' . count( $post_ids ),
	static function () use ( $post_ids, &$content_usage ) {
		return yotm_recommend_scan_content_ids( $post_ids, array_keys( $content_usage ), $content_usage );
	}
);

WP_CLI::success( 'Thumbnail Manager scan benchmark completed.' );
