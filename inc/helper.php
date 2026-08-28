<?php
/**
 * Shared scan, query, and display helpers.
 *
 * @package Thumbnail_Manager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/media/paths.php';
require_once __DIR__ . '/media/attachments.php';

/**
 * Convert selected size definitions to exact and width-only dimensions.
 *
 * @param string[] $keep_names Selected image-size names.
 * @param array    $sizes Registered image-size definitions.
 * @return array{exact:string[],width_any:int[]}
 */
function yotm_keep_dims_from_sizes( $keep_names, $sizes ) {
	$exact     = array();
	$width_any = array();
	foreach ( $keep_names as $name ) {
		if ( ! isset( $sizes[ $name ] ) ) {
			continue;
		}
		$w = (int) ( $sizes[ $name ]['width'] ?? 0 );
		$h = (int) ( $sizes[ $name ]['height'] ?? 0 );
		if ( preg_match( '/^(\d+)x(\d+)$/', $name, $m ) ) {
			$w = 0 === $w ? (int) $m[1] : $w;
			$h = 0 === $h ? (int) $m[2] : $h;
		}
		if ( $w <= 0 ) {
			continue;
		}
		if ( $h > 0 ) {
			$exact[] = "{$w}x{$h}";
		} else {
			$width_any[] = $w;
		}
	}
	return array(
		'exact'     => $exact,
		'width_any' => $width_any,
	);
}

/**
 * Build a compact, readable label for one or more selected uploads roots.
 *
 * @param string   $base Uploads base directory.
 * @param string[] $paths Resolved scan roots.
 * @return string
 */
function yotm_uploads_scope_label( $base, $paths ) {
	$labels = array_map(
		static function ( $path ) use ( $base ) {
			return yotm_uploads_relative_label( $base, $path );
		},
		(array) $paths
	);

	if ( count( $labels ) <= 3 ) {
		return implode( ', ', $labels );
	}

	return sprintf(
		/* translators: 1: first selected uploads folders, 2: number of additional folders. */
		__( '%1$s and %2$d more', 'thumbnail-manager' ),
		implode( ', ', array_slice( $labels, 0, 3 ) ),
		count( $labels ) - 3
	);
}

/**
 * Return a compact uploads-relative display label.
 *
 * @param string $base Uploads base directory.
 * @param string $path Resolved uploads path.
 * @return string
 */
function yotm_uploads_relative_label( $base, $path ) {
	$base = yotm_normalize_filesystem_path( $base );
	$path = yotm_normalize_filesystem_path( $path );

	if ( $base === $path ) {
		return 'uploads/';
	}

	if ( yotm_is_path_inside_dir( $path, $base ) ) {
		return 'uploads/' . ltrim( substr( $path, strlen( $base ) ), '/' );
	}

	return 'uploads/';
}

/**
 * Build common WP_Query arguments for image attachments.
 *
 * @param array $overrides Query-argument overrides.
 * @return array
 */
function yotm_base_image_attachment_query_args( $overrides = array() ) {
	return array_merge(
		array(
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'post_mime_type' => 'image',
			'fields'         => 'ids',
			'orderby'        => 'ID',
			'order'          => 'ASC',
		),
		$overrides
	);
}

/**
 * Iterate through image attachment IDs in bounded pages.
 *
 * @param array         $args Additional query arguments.
 * @param callable|null $callback Attachment callback.
 * @param int           $page_size Maximum IDs per page.
 * @return void
 */
function yotm_each_image_attachment_id_paged( $args = array(), $callback = null, $page_size = 500 ) {
	$paged     = 1;
	$page_size = max( 1, min( 1000, absint( $page_size ) ) );

	if ( ! is_callable( $callback ) ) {
		return;
	}

	do {
		$query_args = yotm_base_image_attachment_query_args(
			array_merge(
				$args,
				array(
					'posts_per_page' => $page_size,
					'paged'          => $paged,
					'no_found_rows'  => true,
				)
			)
		);

		$page_ids = get_posts( $query_args );

		if ( ! is_array( $page_ids ) || empty( $page_ids ) ) {
			break;
		}

		foreach ( $page_ids as $attachment_id ) {
			$attachment_id = absint( $attachment_id );

			if ( ! $attachment_id ) {
				continue;
			}

			call_user_func( $callback, $attachment_id );
		}

		$count = count( $page_ids );
		++$paged;
	} while ( $count === $page_size );
}

/**
 * Collect image attachment IDs from bounded pages.
 *
 * @param array         $args Additional query arguments.
 * @param callable|null $callback Optional per-ID inclusion callback.
 * @param int           $page_size Maximum IDs per page.
 * @return int[]
 */
function yotm_get_image_attachment_ids_paged( $args = array(), $callback = null, $page_size = 500 ) {
	$ids = array();

	yotm_each_image_attachment_id_paged(
		$args,
		static function ( $attachment_id ) use ( &$ids, $callback ) {
			if ( is_callable( $callback ) && ! call_user_func( $callback, $attachment_id ) ) {
				return;
			}

			$ids[] = $attachment_id;
		},
		$page_size
	);

	return $ids;
}

/**
 * Count image attachments matching the supplied query arguments.
 *
 * @param array $args Additional query arguments.
 * @return int
 */
function yotm_count_image_attachments( $args = array() ) {
	$query = new WP_Query(
		yotm_base_image_attachment_query_args(
			array_merge(
				$args,
				array(
					'posts_per_page' => 1,
					'paged'          => 1,
					'no_found_rows'  => false,
				)
			)
		)
	);

	return (int) $query->found_posts;
}

/**
 * Return the greatest matching image attachment ID.
 *
 * @param array $args Additional query arguments.
 * @return int
 */
function yotm_get_max_image_attachment_id( $args = array() ) {
	$query = new WP_Query(
		yotm_base_image_attachment_query_args(
			array_merge(
				$args,
				array(
					'posts_per_page' => 1,
					'paged'          => 1,
					'orderby'        => 'ID',
					'order'          => 'DESC',
					'no_found_rows'  => true,
				)
			)
		)
	);

	if ( empty( $query->posts ) || ! is_array( $query->posts ) ) {
		return 0;
	}

	return absint( $query->posts[0] );
}

/**
 * Return a bounded keyset page of image attachment IDs.
 *
 * @param array $args Additional query arguments.
 * @param int   $after_id Last processed attachment ID.
 * @param int   $limit Maximum IDs to return.
 * @param int   $max_id Optional inclusive upper ID boundary.
 * @return int[]
 */
function yotm_get_image_attachment_ids_after( $args = array(), $after_id = 0, $limit = 20, $max_id = 0 ) {
	global $wpdb;

	$after_id = absint( $after_id );
	$limit    = max( 1, min( 500, absint( $limit ) ) );
	$max_id   = absint( $max_id );

	$where_filter = static function ( $where ) use ( $wpdb, $after_id, $max_id ) {
		if ( $after_id > 0 ) {
			$where .= $wpdb->prepare( " AND {$wpdb->posts}.ID > %d", $after_id );
		}

		if ( $max_id > 0 ) {
			$where .= $wpdb->prepare( " AND {$wpdb->posts}.ID <= %d", $max_id );
		}

		return $where;
	};

	add_filter( 'posts_where', $where_filter );

	try {
		$query = new WP_Query(
			yotm_base_image_attachment_query_args(
				array_merge(
					$args,
					array(
						'posts_per_page' => $limit,
						'paged'          => 1,
						'no_found_rows'  => true,
					)
				)
			)
		);

		$ids = is_array( $query->posts ) ? array_map( 'absint', $query->posts ) : array();
	} finally {
		remove_filter( 'posts_where', $where_filter );
	}

	return array_values( array_filter( $ids ) );
}
