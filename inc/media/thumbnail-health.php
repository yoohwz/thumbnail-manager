<?php
/**
 * Read-only inspection of enabled attachment thumbnails.
 *
 * @package Thumbnail_Manager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Inspect raw attachment state without changing files or metadata.
 *
 * The report is public. Snapshot and requested_sizes are private transaction
 * inputs and must not be included in browser-facing results.
 *
 * @param int $attachment_id Attachment ID.
 * @return array
 */
function yotm_thumbnail_health_inspect( $attachment_id ) {
	$report   = array(
		'attachment_id' => absint( $attachment_id ),
		'status'        => 'blocked',
		'message'       => '',
		'reason'        => '',
		'action'        => '',
		'missing_count' => 0,
		'checked_count' => 0,
		'sizes'         => array(),
	);
	$result   = array(
		'report'          => $report,
		'snapshot'        => null,
		'requested_sizes' => array(),
	);
	$snapshot = yotm_regenerate_preflight( $attachment_id, true );
	if ( is_wp_error( $snapshot ) ) {
		$code                        = $snapshot->get_error_code();
		$result['report']['status']  = 'yotm_regenerate_not_raster' === $code ? 'unsupported' : 'blocked';
		$result['report']['reason']  = $code;
		$result['report']['message'] = $snapshot->get_error_message();
		if ( 'yotm_regenerate_metadata_state' === $code ) {
			$result['report']['message'] = __( 'Attachment metadata is missing or invalid.', 'thumbnail-manager' );
		} elseif ( 'yotm_regenerate_attached_state' === $code ) {
			$result['report']['message'] = __( 'The attached local image cannot be resolved safely.', 'thumbnail-manager' );
		}
		$result['report']['action']  = 'yotm_regenerate_metadata_state' === $code
			? __( 'Restore valid attachment metadata from a backup before checking or repairing thumbnails.', 'thumbnail-manager' )
			: __( 'Restore the local full image and its declared source files, or review the storage integration and attachment metadata.', 'thumbnail-manager' );
		return $result;
	}
	if ( ! wp_image_editor_supports( array( 'mime_type' => get_post_mime_type( $attachment_id ) ) ) ) {
		$result['report']['status']  = 'unsupported';
		$result['report']['reason']  = 'image_editor_unsupported';
		$result['report']['message'] = __( 'The server image editor does not support this image type.', 'thumbnail-manager' );
		$result['report']['action']  = __( 'Enable a compatible WordPress image editor before repairing this image.', 'thumbnail-manager' );
		return $result;
	}
	$metadata = $snapshot['metadata'];
	if ( isset( $metadata['sizes'] ) && ! is_array( $metadata['sizes'] ) ) {
		$result['report']['reason']  = 'invalid_size_metadata';
		$result['report']['message'] = __( 'The attachment thumbnail metadata is malformed.', 'thumbnail-manager' );
		$result['report']['action']  = __( 'Restore valid attachment metadata before repairing thumbnails.', 'thumbnail-manager' );
		return $result;
	}
	foreach ( (array) ( $metadata['sizes'] ?? array() ) as $slug => $size ) {
		$filename = is_array( $size ) ? ( $size['file'] ?? '' ) : '';
		if ( ! is_string( $slug ) || sanitize_key( $slug ) !== $slug || ! is_string( $filename ) || '' === $filename || wp_basename( $filename ) !== $filename || false !== strpos( $filename, '\\' ) ) {
			$result['report']['reason']  = 'invalid_size_metadata';
			$result['report']['message'] = __( 'The attachment contains an unsafe thumbnail metadata entry.', 'thumbnail-manager' );
			$result['report']['action']  = __( 'Restore valid attachment metadata before repairing thumbnails.', 'thumbnail-manager' );
			return $result;
		}
	}
	$registered = wp_get_registered_image_subsizes();
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Match the existing WordPress generation permission surface.
	$permitted = apply_filters( 'intermediate_image_sizes_advanced', $registered, $metadata, $attachment_id );
	if ( ! is_array( $permitted ) ) {
		$result['report']['reason']  = 'invalid_size_policy';
		$result['report']['message'] = __( 'An image-size filter returned an invalid definition.', 'thumbnail-manager' );
		$result['report']['action']  = __( 'Review image-size filters before repairing thumbnails.', 'thumbnail-manager' );
		return $result;
	}
	$permitted = yotm_apply_enabled_size_policy( array_intersect_key( $permitted, $registered ) );
	$blocked   = false;
	foreach ( $registered as $slug => $definition ) {
		$entry = array(
			'status' => 'disabled',
			'reason' => 'size_not_enabled',
			'action' => '',
		);
		if ( ! isset( $permitted[ $slug ] ) ) {
			$result['report']['sizes'][ $slug ] = $entry;
			continue;
		}
		++$result['report']['checked_count'];
		$definition = $permitted[ $slug ];
		if ( ! is_array( $definition ) || ! isset( $definition['width'], $definition['height'] ) || ! is_numeric( $definition['width'] ) || ! is_numeric( $definition['height'] ) || (int) $definition['width'] < 0 || (int) $definition['height'] < 0 ) {
			$blocked                            = true;
			$result['report']['sizes'][ $slug ] = array(
				'status' => 'blocked',
				'reason' => 'invalid_size_definition',
				'action' => __( 'Review this registered image-size definition.', 'thumbnail-manager' ),
			);
			continue;
		}
		$existing = $metadata['sizes'][ $slug ] ?? null;
		if ( is_array( $existing ) ) {
			$path = yotm_media_source_canonical_path( dirname( $snapshot['full'] ) . '/' . $existing['file'] );
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Distinguish true absence from unsafe filesystem nodes.
			$node = is_wp_error( $path ) ? false : @lstat( $path );
			if ( is_wp_error( $path ) || isset( $snapshot['protected'][ $path ] ) || ( false !== $node && ( is_link( $path ) || ! is_file( $path ) ) ) ) {
				$blocked                            = true;
				$result['report']['sizes'][ $slug ] = array(
					'status' => 'blocked',
					'reason' => 'unsafe_thumbnail_path',
					'action' => __( 'Review the thumbnail path and restore safe attachment metadata.', 'thumbnail-manager' ),
				);
				continue;
			}
			if ( false !== $node ) {
				$result['report']['sizes'][ $slug ] = array(
					'status' => 'healthy',
					'reason' => 'file_present',
					'action' => '',
				);
				continue;
			}
		}//end if
		$resize = image_resize_dimensions( (int) $snapshot['source_dimensions'][0], (int) $snapshot['source_dimensions'][1], (int) $definition['width'], (int) $definition['height'], $definition['crop'] ?? false );
		if ( ! is_array( $resize ) ) {
			$result['report']['sizes'][ $slug ] = array(
				'status' => 'not_needed',
				'reason' => 'source_size_not_resizable',
				'action' => '',
			);
			continue;
		}
		if ( (int) ( $resize[4] ?? 0 ) > (int) $snapshot['source_dimensions'][0] || (int) ( $resize[5] ?? 0 ) > (int) $snapshot['source_dimensions'][1] ) {
			$blocked                            = true;
			$result['report']['sizes'][ $slug ] = array(
				'status' => 'blocked',
				'reason' => 'upscaled_size_definition',
				'action' => __( 'Review image-resize filters that request upscaling.', 'thumbnail-manager' ),
			);
			continue;
		}
		$result['report']['sizes'][ $slug ] = array(
			'status' => 'missing',
			'reason' => is_array( $existing ) ? 'file_missing' : 'metadata_missing',
			'action' => __( 'Repair this missing thumbnail from the current full image.', 'thumbnail-manager' ),
		);
		$result['requested_sizes'][ $slug ] = $definition;
		++$result['report']['missing_count'];
	}//end foreach
	$result['report']['status']  = $blocked ? 'blocked' : ( $result['report']['missing_count'] ? 'missing' : 'healthy' );
	$result['report']['reason']  = $blocked ? 'unsafe_thumbnail_state' : ( $result['report']['missing_count'] ? 'missing_thumbnails' : 'enabled_thumbnails_present' );
	$result['report']['message'] = $blocked ? __( 'Some thumbnail paths or size definitions require manual review.', 'thumbnail-manager' ) : ( $result['report']['missing_count'] ? __( 'Enabled thumbnails are missing.', 'thumbnail-manager' ) : __( 'No required enabled thumbnails are missing.', 'thumbnail-manager' ) );
	$result['report']['action']  = $blocked ? __( 'Resolve the reported unsafe thumbnail state before repair.', 'thumbnail-manager' ) : ( $result['report']['missing_count'] ? __( 'Repair the missing thumbnails.', 'thumbnail-manager' ) : '' );
	if ( ! $blocked ) {
		$snapshot['requested_sizes'] = $result['requested_sizes'];
		$result['snapshot']          = $snapshot;
	} else {
		$result['requested_sizes'] = array();
	}
	return $result;
}
