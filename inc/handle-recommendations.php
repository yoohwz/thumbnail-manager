<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'wp_ajax_yotm_recommend_scan', 'yotm_recommend_scan' );

function yotm_recommend_scan() {

	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( [ 'msg' => 'No permission' ], 403 );
	}

	check_ajax_referer( 'yotm_prune_nonce', 'nonce' );

	$sizes            = yotm_get_registered_sizes();
	$size_names       = array_keys( $sizes );
	$metadata_usage   = yotm_recommend_scan_attachment_metadata_usage( $size_names );
	$content_usage    = yotm_recommend_scan_content_references( $size_names );
	$protected_map    = yotm_recommend_protected_sizes( $size_names );
	$recommended_keep = [];
	$items            = [];
	$potential_bytes  = 0;
	$protected_count  = 0;
	$unused_count     = 0;

	foreach ( $sizes as $name => $data ) {
		$width  = isset( $data['width'] ) ? absint( $data['width'] ) : 0;
		$height = isset( $data['height'] ) ? absint( $data['height'] ) : 0;

		$generated_count = (int) ( $metadata_usage[ $name ]['count'] ?? 0 );
		$bytes           = (int) ( $metadata_usage[ $name ]['bytes'] ?? 0 );
		$content_refs    = (int) ( $content_usage[ $name ] ?? 0 );
		$protected_type  = $protected_map[ $name ] ?? '';

		$status         = 'unused';
		$label          = 'No files';
		$reason         = 'No generated files or content references were detected.';
		$recommendation = 'Disable if not needed';

		if ( '' !== $protected_type ) {
			$status         = 'protected';
			$label          = $protected_type;
			$reason         = 'Protected because this is a core or integration-defined image size.';
			$recommendation = 'Keep';
			$recommended_keep[] = $name;
			$protected_count++;
		} elseif ( $content_refs > 0 ) {
			$status         = 'used';
			$label          = 'Referenced';
			$reason         = sprintf(
				/* translators: 1: number of content references. 2: number of generated files. */
				_n(
					'Found %1$d content reference and %2$d generated file.',
					'Found %1$d content references and %2$d generated files.',
					$content_refs,
					'thumbnail-manager'
				),
				$content_refs,
				$generated_count
			);
			$recommendation = 'Keep';
			$recommended_keep[] = $name;
		} elseif ( $generated_count > 0 ) {
			$status         = 'warning';
			$label          = 'Generated';
			$reason         = sprintf(
				/* translators: 1: number of generated files. 2: human-readable disk size. */
				_n(
					'Found %1$d generated file using about %2$s, but no content reference.',
					'Found %1$d generated files using about %2$s, but no content reference.',
					$generated_count,
					'thumbnail-manager'
				),
				$generated_count,
				size_format( $bytes )
			);
			$recommendation = 'Review before pruning';
			$potential_bytes += $bytes;
		} else {
			$unused_count++;
		}

		$items[] = [
			'name'            => $name,
			'dimensions'      => $width . '×' . $height,
			'status'          => $status,
			'label'           => $label,
			'reason'          => $reason,
			'recommendation'  => $recommendation,
			'generated_count' => $generated_count,
			'content_refs'    => $content_refs,
			'bytes'           => $bytes,
		];
	}

	$recommended_keep = array_values( array_unique( $recommended_keep ) );

	wp_send_json_success(
		[
			'items'            => $items,
			'keep_count'       => count( $recommended_keep ),
			'unused_count'     => $unused_count,
			'protected_count'  => $protected_count,
			'savings'          => $potential_bytes > 0 ? size_format( $potential_bytes ) : '0 B',
			'recommended_keep' => $recommended_keep,
		]
	);
}

function yotm_recommend_scan_attachment_metadata_usage( $size_names ) {
	$usage = [];

	foreach ( $size_names as $name ) {
		$usage[ $name ] = [
			'count' => 0,
			'bytes' => 0,
		];
	}

	yotm_each_image_attachment_id_paged( [], static function ( $attachment_id ) use ( &$usage ) {
		$file = get_attached_file( $attachment_id );
		if ( ! $file ) {
			return;
		}

		$metadata = wp_get_attachment_metadata( $attachment_id );
		if ( empty( $metadata['sizes'] ) || ! is_array( $metadata['sizes'] ) ) {
			return;
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
			$usage[ $size_name ]['count']++;
			if ( is_file( $path ) && is_readable( $path ) ) {
				$usage[ $size_name ]['bytes'] += (int) ( @filesize( $path ) ?: 0 );
			}
		}
	} );

	return $usage;
}

function yotm_recommend_scan_content_references( $size_names ) {
	$usage = [];
	foreach ( $size_names as $name ) {
		$usage[ $name ] = 0;
	}

	$page      = 1;
	$page_size = 200;

	do {
		$query = new WP_Query(
			[
				'post_type'              => 'any',
				'post_status'            => [ 'publish', 'private', 'draft', 'future', 'pending' ],
				'posts_per_page'         => $page_size,
				'paged'                  => $page,
				'fields'                 => 'ids',
				'orderby'                => 'ID',
				'order'                  => 'ASC',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			]
		);

		$post_ids = is_array( $query->posts ) ? $query->posts : [];

		foreach ( $post_ids as $post_id ) {
			$content = (string) get_post_field( 'post_content', $post_id, 'raw' );

			if ( '' === $content ) {
				continue;
			}

			foreach ( $size_names as $name ) {
				$name_rx = preg_quote( $name, '/' );

				if (
					preg_match( '/\bsize-' . $name_rx . '\b/', $content )
					|| preg_match( '/["\'](?:size|image_size|thumbnail_size)["\']\s*:\s*["\']' . $name_rx . '["\']/', $content )
				) {
					$usage[ $name ]++;
				}
			}
		}

		$count = count( $post_ids );
		$page++;
	} while ( $count === $page_size );

	return $usage;
}

function yotm_recommend_protected_sizes( $size_names ) {
	$protected = [];
	$core      = [ 'thumbnail', 'medium', 'medium_large', 'large', '1536x1536', '2048x2048' ];
	$woo       = [ 'woocommerce_thumbnail', 'woocommerce_single', 'woocommerce_gallery_thumbnail', 'shop_catalog', 'shop_single', 'shop_thumbnail' ];

	foreach ( $size_names as $name ) {
		if ( in_array( $name, $core, true ) ) {
			$protected[ $name ] = 'Core';
			continue;
		}

		if ( in_array( $name, $woo, true ) || false !== strpos( $name, 'woocommerce' ) ) {
			$protected[ $name ] = 'WooCommerce';
		}
	}

	return $protected;
}
