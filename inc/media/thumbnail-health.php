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
 * @param int        $attachment_id Attachment ID.
 * @param array|null $selected_sizes Optional job-local size scope.
 * @return array
 */
function yotm_thumbnail_health_inspect( $attachment_id, $selected_sizes = null ) {
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
		$result['report']['action'] = 'yotm_regenerate_metadata_state' === $code
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
	if ( array_key_exists( 'sizes', $metadata ) && ! is_array( $metadata['sizes'] ) ) {
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
	if ( null !== $selected_sizes ) {
		$registered = array_intersect_key( $registered, array_flip( $selected_sizes ) );
		foreach ( array_diff( $selected_sizes, array_keys( $registered ) ) as $slug ) {
			$result['report']['sizes'][ $slug ] = array(
				'status' => 'disabled',
				'reason' => 'size_no_longer_registered',
				'action' => __( 'This size is no longer registered. Its existing files were preserved.', 'thumbnail-manager' ),
			);
		}
	}
	$blocked = false;
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
		if ( ! is_array( $resize ) && is_array( $existing ) ) {
			$blocked = true;
			++$result['report']['missing_count'];
			$result['report']['sizes'][ $slug ] = array(
				'status' => 'blocked',
				'reason' => 'missing_file_source_not_resizable',
				'action' => __( 'The recorded thumbnail file is missing, and the current full image cannot recreate this size. Restore a suitable full image or review this size setting, then run a new check.', 'thumbnail-manager' ),
			);
			continue;
		}
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
	$result['report']['message'] = $blocked ? __( 'Some thumbnails require manual attention before they can be repaired.', 'thumbnail-manager' ) : ( $result['report']['missing_count'] ? __( 'Enabled thumbnails are missing.', 'thumbnail-manager' ) : __( 'No required enabled thumbnails are missing.', 'thumbnail-manager' ) );
	$result['report']['action']  = $blocked ? __( 'Resolve the reported unsafe thumbnail state before repair.', 'thumbnail-manager' ) : ( $result['report']['missing_count'] ? __( 'Repair the missing thumbnails.', 'thumbnail-manager' ) : '' );
	if ( ! $blocked ) {
		$snapshot['requested_sizes'] = $result['requested_sizes'];
		$result['snapshot']          = $snapshot;
	} else {
		$result['requested_sizes'] = array();
	}
	return $result;
}

/**
 * Verify missing-only journal destinations, including no-op metadata recovery.
 *
 * @param array $journal Persisted transaction.
 * @return bool|WP_Error True only when every exact output is present.
 */
function yotm_thumbnail_health_recovery_postcondition( $journal ) {
	if ( empty( $journal['destinations'] ) || ! in_array( $journal['phase'] ?? '', array( 'prepared', 'files_promoted', 'metadata_committed', 'cleanup_complete' ), true ) ) {
		return new WP_Error( 'yotm_health_journal_invalid', __( 'The repair recovery record is incomplete. Inspect the retained recovery record before retrying.', 'thumbnail-manager' ) );
	}
	$complete = true;
	foreach ( $journal['destinations'] as $entry ) {
		if ( empty( $entry['old_absent'] ) || ! in_array( $entry['mode'] ?? '', array( 'expected_absent', 'replaceable_old_generated' ), true ) || ! empty( $entry['backup'] ) ) {
			return new WP_Error( 'yotm_health_journal_overwrite', __( 'The repair recovery record does not prove an absent destination.', 'thumbnail-manager' ) );
		}
		$complete = yotm_thumbnail_health_owns_output( $entry ) && yotm_regenerate_file_hash_matches( $entry['destination'], $entry['promoted_hash'] ?? '' ) && $complete;
	}
	return $complete;
}

/**
 * Normalize an interrupted absent-destination intent for rollback only.
 *
 * @param array $journal Exact repair journal.
 * @return array|WP_Error Validated rollback journal or ownership failure.
 */
function yotm_thumbnail_health_rollback_journal( $journal ) {
	foreach ( $journal['destinations'] as $slug => &$entry ) {
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Absence means the recorded promotion intent never ran or was already rolled back.
		if ( false === @lstat( $entry['destination'] ) ) {
			$entry['promoted'] = false;
			if ( (string) $slug === (string) $journal['promotion_slug'] ) {
				$journal['promotion_slug'] = '';
			}
		} elseif ( ! yotm_thumbnail_health_owns_output( $entry ) ) {
			return new WP_Error( 'yotm_health_output_changed', __( 'A repair destination was created or replaced by another process. It was preserved; inspect the retained recovery record.', 'thumbnail-manager' ) );
		}
	}
	unset( $entry );
	return $journal;
}

/**
 * Publish a missing output without replacing a concurrently created file.
 *
 * Keep the staged hard link until transaction cleanup so interrupted intent
 * can be distinguished from an unrelated file with identical bytes.
 *
 * @param array $entry Journal destination, updated by reference.
 * @return bool
 */
function yotm_thumbnail_health_promote_destination( &$entry ) {
	$checkpoint = yotm_optimizer_checkpoint();
	if ( is_wp_error( $checkpoint ) ) {
		return false;
	}
	$source_node            = yotm_thumbnail_health_file_node( $entry['source'] );
	$entry['promoted_node'] = $source_node;
	// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Atomic no-clobber publication on the same filesystem; failure preserves the existing destination.
	if ( ! $source_node || ! @link( $entry['source'], $entry['destination'] ) ) {
		$entry['promoted'] = false;
		return false;
	}
	$entry['promoted'] = yotm_thumbnail_health_owns_output( $entry );
	return $entry['promoted'] && yotm_regenerate_file_hash_matches( $entry['destination'], $entry['promoted_hash'] );
}

/**
 * Capture a regular file identity for exact repair rollback evidence.
 *
 * @param string $path File path.
 * @return array|null Device and inode, or null when unsafe/unavailable.
 */
function yotm_thumbnail_health_file_node( $path ) {
	clearstatcache( true, $path );
	// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- A missing or unsafe node provides no rollback authority.
	$node = @lstat( $path );
	return is_array( $node ) && ! empty( $node['ino'] ) && ! is_link( $path ) && is_file( $path )
		? array(
			'dev' => (string) $node['dev'],
			'ino' => (string) $node['ino'],
		)
		: null;
}

/**
 * Prove a repair output belongs to the staged publication, not a later writer.
 *
 * @param array $entry Journal destination.
 * @return bool
 */
function yotm_thumbnail_health_owns_output( $entry ) {
	$actual   = yotm_thumbnail_health_file_node( $entry['destination'] );
	$expected = $entry['promoted_node'] ?? yotm_thumbnail_health_file_node( $entry['source'] );
	return is_array( $actual ) && is_array( $expected ) && $actual === $expected;
}
