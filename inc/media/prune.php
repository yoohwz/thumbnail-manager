<?php
/**
 * Pure prune candidate, evidence, filesystem, and metadata primitives.
 *
 * @package Thumbnail_Manager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Deduplicate and sort immutable ownership evidence before persistence.
 *
 * @param array $candidate Candidate payload.
 * @return array
 */
function yotm_prune_normalize_candidate_evidence( $candidate ) {
	foreach ( array( 'metadata_refs', 'ownership_evidence' ) as $field ) {
		$normalized = array();
		foreach ( (array) ( $candidate[ $field ] ?? array() ) as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}
			$key                = absint( $entry['attachment_id'] ?? 0 ) . ':' . sanitize_key( $entry['size'] ?? '' ) . ':' . sanitize_file_name( $entry['filename'] ?? '' ) . ':' . sanitize_key( $entry['selection'] ?? '' );
			$normalized[ $key ] = $entry;
		}
		ksort( $normalized );
		$candidate[ $field ] = array_values( $normalized );
	}

	return $candidate;
}

/**
 * Return the initial disk-orphan reporting counters.
 *
 * @return array
 */
function yotm_initial_orphan_summary() {
	return array(
		'found'                   => array(),
		'delete'                  => array(),
		'kept_match'              => array(),
		'total_files'             => 0,
		'skipped_original'        => 0,
		'skipped_original_sample' => array(),
		'unmapped'                => 0,
		'unmapped_sample'         => array(),
		'unmapped_skipped'        => 0,
		'unverified_sidecars'     => 0,
		'ambiguous_siblings'      => 0,
		'protected_sources'       => 0,
		'source_errors'           => 0,
	);
}

/**
 * Add or merge one normalized path into the candidate map.
 *
 * @param array  $candidates Candidate map, passed by reference.
 * @param string $path Candidate filesystem path.
 * @param array  $args Candidate metadata.
 */
function yotm_add_prune_candidate( &$candidates, $path, $args = array() ) {
	$path  = yotm_normalize_filesystem_path( $path );
	$proof = array(
		'attachment_id' => absint( $args['attachment_id'] ?? 0 ),
		'size'          => sanitize_key( $args['size'] ?? '' ),
		'filename'      => wp_basename( $path ),
		'mime'          => sanitize_mime_type( $args['mime'] ?? '' ),
		'selection'     => sanitize_key( $args['selection'] ?? '' ),
	);

	if ( isset( $candidates[ $path ] ) ) {
		if ( ! empty( $args['remove_metadata'] ) ) {
			$candidates[ $path ]['remove_metadata'] = 1;
		}

		if ( empty( $candidates[ $path ]['attachment_id'] ) && ! empty( $args['attachment_id'] ) ) {
			$candidates[ $path ]['attachment_id'] = absint( $args['attachment_id'] );
		}

		if ( empty( $candidates[ $path ]['size'] ) && ! empty( $args['size'] ) ) {
			$candidates[ $path ]['size'] = sanitize_key( $args['size'] );
		}

		if ( ! empty( $args['attachment_id'] ) && ! empty( $args['size'] ) && ! empty( $args['remove_metadata'] ) ) {
			$candidates[ $path ]['metadata_refs'][] = array(
				'attachment_id' => absint( $args['attachment_id'] ),
				'size'          => sanitize_key( $args['size'] ),
				'filename'      => wp_basename( $path ),
			);
		}
		$candidates[ $path ]['ownership_evidence'][] = $proof;

		return;
	}//end if

	$candidates[ $path ] = array(
		'path'               => $path,
		'attachment_id'      => isset( $args['attachment_id'] ) ? absint( $args['attachment_id'] ) : 0,
		'size'               => isset( $args['size'] ) ? sanitize_key( $args['size'] ) : '',
		'source'             => isset( $args['source'] ) ? sanitize_key( $args['source'] ) : 'metadata',
		'ownership_schema'   => 'generated_file_v1',
		'ownership'          => 'metadata_size',
		'ownership_evidence' => array( $proof ),
		'remove_metadata'    => ! empty( $args['remove_metadata'] ) ? 1 : 0,
		'metadata_refs'      => ! empty( $args['attachment_id'] ) && ! empty( $args['size'] ) && ! empty( $args['remove_metadata'] )
			? array(
				array(
					'attachment_id' => absint( $args['attachment_id'] ),
					'size'          => sanitize_key( $args['size'] ),
					'filename'      => wp_basename( $path ),
				),
			)
			: array(),
	);
}

/**
 * Collect metadata-backed prune candidates for a bounded attachment set.
 *
 * @param int[]           $ids Attachment IDs.
 * @param string|string[] $scan_bases Validated scan roots.
 * @param string[]        $keep Registered sizes to keep.
 * @param string[]        $to_remove Registered sizes to remove.
 * @param array           $sizes Registered size definitions.
 * @param bool            $discover_orphans Whether legacy metadata sizes are included.
 * @param array           $candidates Candidate map, passed by reference.
 * @param array           $orphan_summary Orphan counters, passed by reference.
 */
function yotm_collect_metadata_prune_candidates_for_ids( $ids, $scan_bases, $keep, $to_remove, $sizes, $discover_orphans, &$candidates, &$orphan_summary ) {
	$scan_bases = (array) $scan_bases;
	$keep_dims  = yotm_keep_dims_from_sizes( $keep, $sizes );
	$delete_map = array();
	$raw_batch  = yotm_media_reference_raw_postmeta_rows_batch( $ids, array( '_wp_attached_file', '_wp_attachment_metadata' ) );
	if ( is_wp_error( $raw_batch ) ) {
		$orphan_summary['source_errors'] = (int) ( $orphan_summary['source_errors'] ?? 0 ) + count( (array) $ids );
		return;
	}

	if ( isset( $orphan_summary['delete'] ) && is_array( $orphan_summary['delete'] ) ) {
		foreach ( $orphan_summary['delete'] as $dim ) {
			if ( is_string( $dim ) && '' !== $dim ) {
				$delete_map[ $dim ] = true;
			}
		}
	}

	foreach ( $ids as $attachment_id ) {
		$attachment_id = absint( $attachment_id );
		if ( ! $attachment_id ) {
			continue;
		}
		$attached_rows = $raw_batch[ $attachment_id ]['_wp_attached_file'] ?? array();
		$metadata_rows = $raw_batch[ $attachment_id ]['_wp_attachment_metadata'] ?? array();
		if (
			1 !== count( $attached_rows )
			|| 1 !== count( $metadata_rows )
			|| ! is_string( $attached_rows[0]['value'] )
			|| '' === $attached_rows[0]['value']
			|| ! is_array( $metadata_rows[0]['value'] )
		) {
			$orphan_summary['source_errors'] = (int) ( $orphan_summary['source_errors'] ?? 0 ) + 1;
			continue;
		}
		$uploads  = wp_get_upload_dir();
		$base     = (string) ( $uploads['basedir'] ?? '' );
		$raw_file = $attached_rows[0]['value'];
		$file     = preg_match( '#^(?:[A-Za-z]:)?/#', $raw_file ) ? $raw_file : trailingslashit( $base ) . ltrim( $raw_file, '/\\' );
		$file     = yotm_media_source_canonical_path( $file );
		if ( is_wp_error( $file ) ) {
			$orphan_summary['source_errors'] = (int) ( $orphan_summary['source_errors'] ?? 0 ) + 1;
			continue;
		}

		$original_path = yotm_normalize_filesystem_path( $file );

		if ( ! yotm_is_path_inside_any_dir( $original_path, $scan_bases ) ) {
			continue;
		}

		$metadata = $metadata_rows[0]['value'];
		if ( empty( $metadata['sizes'] ) || ! is_array( $metadata['sizes'] ) ) {
			continue;
		}

		$metadata_file = ! empty( $metadata['file'] ) && is_string( $metadata['file'] )
			? trailingslashit( $base ) . ltrim( $metadata['file'], '/\\' )
			: $original_path;
		$metadata_file = yotm_media_source_canonical_path( $metadata_file );
		if ( is_wp_error( $metadata_file ) ) {
			$orphan_summary['source_errors'] = (int) ( $orphan_summary['source_errors'] ?? 0 ) + 1;
			continue;
		}
		$upload_dir = trailingslashit( dirname( yotm_normalize_filesystem_path( $metadata_file ) ) );

		foreach ( $metadata['sizes'] as $size_name => $size_data ) {
			if ( ! is_array( $size_data ) ) {
				continue;
			}

			$filename = $size_data['file'] ?? ( $size_data['filename'] ?? '' );
			if ( '' === $filename ) {
				continue;
			}

			$thumb_path = yotm_normalize_filesystem_path( $upload_dir . wp_basename( $filename ) );

			if ( $thumb_path === $original_path || ! yotm_is_path_inside_any_dir( $thumb_path, $scan_bases ) ) {
				continue;
			}

			if ( in_array( $size_name, $keep, true ) ) {
				continue;
			}

			$registered_remove = in_array( $size_name, $to_remove, true );
			$orphan_remove     = false;
			$source            = 'metadata';

			if ( ! $registered_remove && $discover_orphans && ! isset( $sizes[ $size_name ] ) ) {
				$dim = yotm_dimension_from_size_data( $size_data );

				if ( '' !== $dim ) {
					$orphan_summary['found'][ $dim ] = ( $orphan_summary['found'][ $dim ] ?? 0 ) + 1;

					if ( yotm_dimension_matches_keep( $dim, $keep_dims ) ) {
						$orphan_summary['kept_match'][] = $dim;
					} else {
						$delete_map[ $dim ] = true;
						$orphan_remove      = true;
						$source             = 'metadata_orphan';
					}
				}
			}

			if ( ! $registered_remove && ! $orphan_remove ) {
				continue;
			}

			if ( ! is_file( $thumb_path ) ) {
				continue;
			}

			$protected = yotm_media_source_path_is_authoritative( $thumb_path );
			if ( is_wp_error( $protected ) ) {
				$orphan_summary['source_errors'] = (int) ( $orphan_summary['source_errors'] ?? 0 ) + 1;
				continue;
			}
			if ( $protected ) {
				$orphan_summary['protected_sources'] = (int) ( $orphan_summary['protected_sources'] ?? 0 ) + 1;
				continue;
			}

			yotm_add_prune_candidate(
				$candidates,
				$thumb_path,
				array(
					'attachment_id'   => $attachment_id,
					'size'            => (string) $size_name,
					'source'          => $source,
					'remove_metadata' => true,
					'mime'            => (string) ( $size_data['mime-type'] ?? '' ),
					'selection'       => $registered_remove ? 'registered_remove' : 'metadata_orphan',
				)
			);
		}//end foreach
	}//end foreach

	$orphan_summary['delete'] = array_keys( $delete_map );

	if ( isset( $orphan_summary['kept_match'] ) && is_array( $orphan_summary['kept_match'] ) ) {
		$orphan_summary['kept_match'] = array_values( array_unique( $orphan_summary['kept_match'] ) );
	}
}

/**
 * Convert metadata dimensions to a stable width-by-height key.
 *
 * @param array $size_data Attachment size metadata.
 * @return string
 */
function yotm_dimension_from_size_data( $size_data ) {
	$width  = isset( $size_data['width'] ) ? absint( $size_data['width'] ) : 0;
	$height = isset( $size_data['height'] ) ? absint( $size_data['height'] ) : 0;

	if ( $width <= 0 || $height <= 0 ) {
		return '';
	}

	return $width . 'x' . $height;
}

/**
 * Check whether a legacy dimension matches a retained registered size.
 *
 * @param string $dim Width-by-height key.
 * @param array  $keep_dims Dimension lookup maps.
 * @return bool
 */
function yotm_dimension_matches_keep( $dim, $keep_dims ) {
	if ( in_array( $dim, $keep_dims['exact'], true ) ) {
		return true;
	}

	if ( preg_match( '/^(\d+)x(\d+)$/', $dim, $matches ) ) {
		return in_array( (int) $matches[1], array_map( 'intval', $keep_dims['width_any'] ), true );
	}

	return false;
}

/**
 * Find the primary thumbnail and safe extension variants beside it.
 *
 * @param string $thumb_path Primary thumbnail path.
 * @return string[]
 */
function yotm_get_thumbnail_file_variants( $thumb_path ) {
	$thumb_path = yotm_normalize_filesystem_path( $thumb_path );

	return is_file( $thumb_path ) ? array( $thumb_path ) : array();
}

/**
 * Backward-compatible validation helper retained for older integrations.
 *
 * @param array $meta Legacy metadata.
 * @return true|WP_Error
 */
function yotm_prune_validate_delete_meta( $meta ) {
	if ( ! is_array( $meta ) || ( $meta['mode'] ?? '' ) !== 'delete' ) {
		return new WP_Error( 'yotm_not_delete_mode', __( 'This token was not prepared for deletion.', 'thumbnail-manager' ) );
	}

	if ( empty( $meta['scan_done'] ) ) {
		return new WP_Error( 'yotm_scan_not_done', __( 'Scan is still running.', 'thumbnail-manager' ) );
	}

	return true;
}

/**
 * Delete a file via the WordPress API and return bytes freed.
 *
 * @param string $path File path.
 * @return int
 */
function yotm_delete_file_and_count( $path ) {
	$uploads = wp_get_upload_dir();
	$base    = $uploads['basedir'] ?? '';

	if ( '' === $base || ! yotm_is_path_inside_dir( $path, $base ) ) {
		return 0;
	}

	$result = yotm_delete_file_with_result( $path );

	return ! empty( $result['deleted'] ) ? (int) $result['bytes'] : 0;
}

/**
 * Normalize an immutable prune path without resolving its final filesystem node.
 *
 * Journal identity and lstat inspection must address the reviewed path itself;
 * resolving a replacement symlink here would inspect its target instead.
 *
 * @param string $path Reviewed candidate path.
 * @return string
 */
function yotm_prune_journal_lexical_path( $path ) {
	return untrailingslashit( wp_normalize_path( (string) $path ) );
}

/**
 * Fingerprint one regular filesystem node from a single lstat snapshot.
 *
 * Device and inode bind the node across requests. Mode, link count, owner,
 * device type, and ctime make inode reuse or intervening stat changes fail
 * closed. Platforms that cannot expose a usable inode cannot authorize prune.
 *
 * @param array $stat lstat result.
 * @return string|WP_Error
 */
function yotm_prune_journal_node_fingerprint( $stat ) {
	$fields   = array( 'dev', 'ino', 'mode', 'nlink', 'uid', 'gid', 'rdev', 'ctime' );
	$identity = array();

	foreach ( $fields as $field ) {
		if ( ! array_key_exists( $field, $stat ) || ! is_int( $stat[ $field ] ) ) {
			return new WP_Error( 'yotm_prune_node_identity_unavailable', __( 'The reviewed prune file identity could not be established safely.', 'thumbnail-manager' ) );
		}
		$identity[] = $field . '=' . (string) $stat[ $field ];
	}
	if ( 0 >= $stat['ino'] ) {
		return new WP_Error( 'yotm_prune_node_identity_unavailable', __( 'The reviewed prune file identity could not be established safely.', 'thumbnail-manager' ) );
	}

	return hash( 'sha256', implode( '|', $identity ) );
}

/**
 * Inspect the exact filesystem node at an armed prune path without following symlinks.
 *
 * @param string $path Reviewed candidate path.
 * @return array{state:string,bytes:int,fingerprint?:string}|WP_Error
 */
function yotm_prune_journal_path_state( $path ) {
	$path = yotm_prune_journal_lexical_path( $path );
	clearstatcache( true, $path );
	// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- A false lstat result is classified explicitly below and must not emit during recovery.
	$stat = @lstat( $path );

	if ( false === $stat ) {
		$parent = dirname( $path );
		if ( ! is_dir( $parent ) || ! is_readable( $parent ) || ! is_executable( $parent ) ) {
			return new WP_Error( 'yotm_prune_path_inspection_failed', __( 'The reviewed prune path could not be inspected safely.', 'thumbnail-manager' ) );
		}
		return array(
			'state' => 'absent',
			'bytes' => 0,
		);
	}

	$mode = absint( $stat['mode'] ?? 0 ) & 0170000;
	if ( 0100000 !== $mode || is_link( $path ) || ! is_file( $path ) ) {
		return array(
			'state' => 'changed',
			'bytes' => 0,
		);
	}
	$fingerprint = yotm_prune_journal_node_fingerprint( $stat );
	if ( is_wp_error( $fingerprint ) ) {
		return $fingerprint;
	}

	return array(
		'state'       => 'regular',
		'bytes'       => absint( $stat['size'] ?? 0 ),
		'fingerprint' => $fingerprint,
	);
}

/**
 * Prove that immutable candidate evidence covers every current generated owner.
 *
 * Source/protected owners always veto. Extra historical candidate evidence is
 * allowed so a repointed size does not prevent deleting its reviewed old file.
 *
 * @param array  $item Immutable candidate payload.
 * @param string $path Canonical candidate path.
 * @return true|WP_Error
 */
function yotm_prune_validate_live_reference_evidence( $item, $path ) {
	$owners = yotm_media_reference_path_owners( $path );
	if ( is_wp_error( $owners ) ) {
		return $owners;
	}
	if ( ! empty( $owners['protected'] ) ) {
		return new WP_Error( 'yotm_prune_path_protected', __( 'The file is now a protected attachment source or companion and was preserved.', 'thumbnail-manager' ) );
	}

	$represented = array();
	foreach ( (array) ( $item['metadata_refs'] ?? array() ) as $ref ) {
		if ( ! is_array( $ref ) ) {
			continue;
		}
		$key                 = absint( $ref['attachment_id'] ?? 0 ) . ':' . sanitize_key( $ref['size'] ?? '' ) . ':' . wp_basename( (string) ( $ref['filename'] ?? '' ) );
		$represented[ $key ] = true;
	}

	foreach ( $owners['generated'] as $owner ) {
		$key = absint( $owner['attachment_id'] ?? 0 ) . ':' . sanitize_key( $owner['size'] ?? '' ) . ':' . wp_basename( (string) ( $owner['filename'] ?? '' ) );
		if ( empty( $represented[ $key ] ) ) {
			return new WP_Error( 'yotm_prune_generated_owner_changed', __( 'A generated-file reference outside the reviewed manifest now owns this path.', 'thumbnail-manager' ) );
		}
	}

	return true;
}

/**
 * Validate immutable exact generated-file ownership before a side effect.
 *
 * @param array  $item Item payload.
 * @param string $uploads_base Uploads base path.
 * @return string|WP_Error Canonical candidate path.
 */
function yotm_validate_prune_item_ownership( $item, $uploads_base ) {
	if ( ! is_array( $item ) || 'generated_file_v1' !== ( $item['ownership_schema'] ?? '' ) || 'metadata_size' !== ( $item['ownership'] ?? '' ) ) {
		return new WP_Error( 'yotm_prune_ownership_invalid', __( 'The prune item lacks exact generated-file ownership evidence.', 'thumbnail-manager' ) );
	}

	$path = yotm_media_source_canonical_path( $item['path'] ?? '' );
	if ( is_wp_error( $path ) || ! yotm_is_path_inside_dir( is_wp_error( $path ) ? '' : $path, $uploads_base ) ) {
		return is_wp_error( $path ) ? $path : new WP_Error( 'yotm_prune_path_invalid', __( 'File path is outside uploads.', 'thumbnail-manager' ) );
	}

	$filename = wp_basename( $path );
	$evidence = is_array( $item['ownership_evidence'] ?? null ) ? $item['ownership_evidence'] : array();
	$refs     = is_array( $item['metadata_refs'] ?? null ) ? $item['metadata_refs'] : array();
	if ( empty( $evidence ) || empty( $refs ) ) {
		return new WP_Error( 'yotm_prune_ownership_invalid', __( 'The prune item lacks exact generated-file ownership evidence.', 'thumbnail-manager' ) );
	}

	$proof_keys = array();
	foreach ( $evidence as $proof ) {
		if (
			empty( $proof['attachment_id'] )
			|| '' === sanitize_key( $proof['size'] ?? '' )
			|| ! hash_equals( $filename, wp_basename( (string) ( $proof['filename'] ?? '' ) ) )
			|| ! in_array( $proof['selection'] ?? '', array( 'registered_remove', 'metadata_orphan' ), true )
		) {
			return new WP_Error( 'yotm_prune_ownership_invalid', __( 'The prune item contains malformed ownership evidence.', 'thumbnail-manager' ) );
		}
		$proof_keys[ absint( $proof['attachment_id'] ) . ':' . sanitize_key( $proof['size'] ) . ':' . $filename ] = true;
	}

	$ref_keys = array();
	foreach ( $refs as $ref ) {
		if ( empty( $ref['attachment_id'] ) || '' === sanitize_key( $ref['size'] ?? '' ) || ! hash_equals( $filename, wp_basename( (string) ( $ref['filename'] ?? '' ) ) ) ) {
			return new WP_Error( 'yotm_prune_ownership_invalid', __( 'The prune item contains a mismatched metadata reference.', 'thumbnail-manager' ) );
		}
		$ref_keys[ absint( $ref['attachment_id'] ) . ':' . sanitize_key( $ref['size'] ) . ':' . $filename ] = true;
	}

	ksort( $proof_keys );
	ksort( $ref_keys );
	if ( array_keys( $proof_keys ) !== array_keys( $ref_keys ) ) {
		return new WP_Error( 'yotm_prune_ownership_invalid', __( 'The prune item ownership evidence does not match its metadata references.', 'thumbnail-manager' ) );
	}

	return $path;
}

/**
 * Delete a manifest item and reconcile every attachment metadata reference.
 *
 * @param array|string $item Item payload.
 * @param string       $uploads_base Uploads base path.
 * @return array
 */
function yotm_delete_prune_item( $item, $uploads_base ) {
	$path = is_array( $item ) ? ( $item['path'] ?? '' ) : $item;
	$path = yotm_normalize_filesystem_path( $path );

	if ( '' === $path || ! yotm_is_path_inside_dir( $path, $uploads_base ) ) {
		return array(
			'deleted' => false,
			'skipped' => false,
			'bytes'   => 0,
			'error'   => __( 'File path is outside uploads.', 'thumbnail-manager' ),
		);
	}

	if ( ! is_file( $path ) ) {
		$reconciled = yotm_reconcile_prune_item_metadata( $item, $path );
		if ( is_wp_error( $reconciled ) ) {
			return $reconciled;
		}

		return array(
			'deleted' => false,
			'skipped' => true,
			'bytes'   => 0,
			'message' => __( 'File was already missing; metadata was reconciled.', 'thumbnail-manager' ),
		);
	}

	$result = yotm_delete_file_with_result( $path );

	if ( ! empty( $result['deleted'] ) ) {
		$reconciled = yotm_reconcile_prune_item_metadata( $item, $path );
		if ( is_wp_error( $reconciled ) ) {
			return $reconciled;
		}
	}

	return $result;
}

/**
 * Remove all metadata references recorded for a deleted path.
 *
 * @param array|string $item Item payload.
 * @param string       $path File path.
 */
function yotm_reconcile_prune_item_metadata( $item, $path ) {
	if ( ! is_array( $item ) ) {
		return true;
	}

	$refs = is_array( $item['metadata_refs'] ?? null ) ? $item['metadata_refs'] : array();

	if ( empty( $refs ) && ! empty( $item['remove_metadata'] ) && ! empty( $item['attachment_id'] ) && ! empty( $item['size'] ) ) {
		$refs[] = array(
			'attachment_id' => $item['attachment_id'],
			'size'          => $item['size'],
			'filename'      => wp_basename( $path ),
		);
	}

	$seen = array();
	foreach ( $refs as $ref ) {
		$attachment_id = absint( $ref['attachment_id'] ?? 0 );
		$size          = sanitize_key( $ref['size'] ?? '' );
		$filename      = sanitize_file_name( $ref['filename'] ?? wp_basename( $path ) );
		$key           = $attachment_id . ':' . $size . ':' . $filename;

		if ( ! $attachment_id || '' === $size || isset( $seen[ $key ] ) ) {
			continue;
		}

		$seen[ $key ] = true;
		$removed      = yotm_remove_attachment_size_metadata( $attachment_id, $size, $filename );
		if ( is_wp_error( $removed ) ) {
			return $removed;
		}
	}

	return true;
}

/**
 * Delete a local file and report a useful failure.
 *
 * @param string $path File path.
 * @return array
 */
function yotm_delete_file_with_result( $path ) {
	$path = yotm_normalize_filesystem_path( $path );

	if ( ! is_file( $path ) ) {
		return array(
			'deleted' => false,
			'skipped' => true,
			'bytes'   => 0,
			'message' => __( 'File does not exist.', 'thumbnail-manager' ),
		);
	}

	if ( ! is_readable( $path ) ) {
		return array(
			'deleted' => false,
			'skipped' => false,
			'bytes'   => 0,
			'error'   => __( 'File is not readable.', 'thumbnail-manager' ),
		);
	}

	$bytes = filesize( $path );
	$bytes = false === $bytes ? 0 : $bytes;
	wp_delete_file( $path );

	if ( file_exists( $path ) ) {
		return array(
			'deleted' => false,
			'skipped' => false,
			'bytes'   => 0,
			'error'   => __( 'WordPress could not delete the file.', 'thumbnail-manager' ),
		);
	}

	return array(
		'deleted' => true,
		'skipped' => false,
		'bytes'   => $bytes,
		'error'   => '',
	);
}

/**
 * Remove a generated size reference from attachment metadata.
 *
 * @param int    $attachment_id Attachment ID.
 * @param string $size_name Size name.
 * @param string $filename Generated filename.
 * @return bool|WP_Error
 */
function yotm_remove_attachment_size_metadata( $attachment_id, $size_name, $filename ) {
	$rows = yotm_media_reference_raw_postmeta_rows( $attachment_id, '_wp_attachment_metadata' );
	if ( is_wp_error( $rows ) ) {
		return $rows;
	}
	if ( 1 !== count( $rows ) || ! is_array( $rows[0]['value'] ) ) {
		return new WP_Error( 'yotm_prune_metadata_state_ambiguous', __( 'The file was removed, but raw attachment metadata could not be reconciled safely.', 'thumbnail-manager' ) );
	}
	$metadata = $rows[0]['value'];

	if ( empty( $metadata['sizes'] ) || ! is_array( $metadata['sizes'] ) ) {
		return false;
	}

	if ( ! isset( $metadata['sizes'][ $size_name ] ) || ! is_array( $metadata['sizes'][ $size_name ] ) ) {
		return true;
	}

	$size_file = $metadata['sizes'][ $size_name ]['file'] ?? ( $metadata['sizes'][ $size_name ]['filename'] ?? '' );
	if ( '' === $size_file || ! hash_equals( wp_basename( (string) $size_file ), wp_basename( (string) $filename ) ) ) {
		return true;
	}

	unset( $metadata['sizes'][ $size_name ] );
	unset( $GLOBALS['yotm_media_source_last_error'] );
	$updated = wp_update_attachment_metadata( $attachment_id, $metadata );
	if ( false === $updated ) {
		if ( isset( $GLOBALS['yotm_media_source_last_error'] ) && is_wp_error( $GLOBALS['yotm_media_source_last_error'] ) ) {
			return $GLOBALS['yotm_media_source_last_error'];
		}
		return new WP_Error( 'yotm_prune_metadata_update_failed', __( 'The file was removed, but its exact metadata reference could not be reconciled.', 'thumbnail-manager' ) );
	}

	return true;
}
