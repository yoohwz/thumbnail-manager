<?php
/**
 * Transactional force regeneration.
 *
 * @package Thumbnail_Manager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Transactional media promotion requires same-filesystem atomic renames, exact lstat checks, and private backups.
// phpcs:disable WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.unlink_unlink,WordPress.WP.AlternativeFunctions.rename_rename,WordPress.WP.AlternativeFunctions.file_system_operations_rmdir,WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize,WordPress.WP.GetMetaSingle.Missing

if ( ! defined( 'YOTM_REGENERATE_JOURNAL_VERSION' ) ) {
	define( 'YOTM_REGENERATE_JOURNAL_VERSION', 1 );
}

/**
 * Return a deterministic hash for an exact metadata structure.
 *
 * @param mixed $metadata Metadata value.
 * @return string
 */
function yotm_regenerate_metadata_hash( $metadata ) {
	return hash( 'sha256', serialize( $metadata ) );
}

/**
 * Return a SHA-256 hash or an empty string when a file cannot be read.
 *
 * @param string $path File path.
 * @return string
 */
function yotm_regenerate_file_hash( $path ) {
	$hash = is_string( $path ) && is_file( $path ) && ! is_link( $path ) ? hash_file( 'sha256', $path ) : false;
	return is_string( $hash ) ? $hash : '';
}

/**
 * Prove that a regular file has one exact expected hash.
 *
 * @param string $path File path.
 * @param string $expected Expected SHA-256 hash.
 * @return bool
 */
function yotm_regenerate_file_hash_matches( $path, $expected ) {
	$actual = yotm_regenerate_file_hash( $path );
	return is_string( $expected ) && '' !== $expected && '' !== $actual && hash_equals( $expected, $actual );
}

/**
 * Build a standard regeneration failure result.
 *
 * @param string|WP_Error $message Failure message.
 * @param bool            $retryable Whether the item should be requeued.
 * @return array
 */
function yotm_regenerate_failure( $message, $retryable = false ) {
	return array(
		'status'    => $retryable ? 'retry' : 'failed',
		'message'   => is_wp_error( $message ) ? $message->get_error_message() : (string) $message,
		'retryable' => (bool) $retryable,
	);
}

/**
 * Read and validate the raw authoritative state used by Force.
 *
 * @param int $attachment_id Attachment ID.
 * @return array|WP_Error
 */
function yotm_regenerate_preflight( $attachment_id ) {
	$attachment_id = absint( $attachment_id );
	$uploads       = wp_get_upload_dir();
	$base          = (string) ( $uploads['basedir'] ?? '' );
	$mime          = get_post_mime_type( $attachment_id );
	if ( ! $attachment_id || '' === $base || ! is_string( $mime ) || 0 !== strpos( $mime, 'image/' ) || 'image/svg+xml' === $mime ) {
		return new WP_Error( 'yotm_regenerate_not_raster', __( 'Attachment is not a local raster image.', 'thumbnail-manager' ) );
	}

	$attached_rows = yotm_media_reference_raw_postmeta_rows( $attachment_id, '_wp_attached_file' );
	$metadata_rows = yotm_media_reference_raw_postmeta_rows( $attachment_id, '_wp_attachment_metadata' );
	$backup_rows   = yotm_media_reference_raw_postmeta_rows( $attachment_id, '_wp_attachment_backup_sizes' );
	if ( is_wp_error( $attached_rows ) || is_wp_error( $metadata_rows ) || is_wp_error( $backup_rows ) ) {
		return is_wp_error( $attached_rows ) ? $attached_rows : ( is_wp_error( $metadata_rows ) ? $metadata_rows : $backup_rows );
	}
	$attached_values = array_column( $attached_rows, 'value' );
	$metadata_values = array_column( $metadata_rows, 'value' );
	$backup_values   = array_column( $backup_rows, 'value' );
	if ( 1 !== count( $attached_values ) || ! is_string( $attached_values[0] ) || '' === $attached_values[0] ) {
		return new WP_Error( 'yotm_regenerate_attached_state', __( 'Force requires exactly one valid raw attached-file row.', 'thumbnail-manager' ) );
	}
	if ( 1 !== count( $metadata_values ) || ! is_array( $metadata_values[0] ) || empty( $metadata_values[0]['file'] ) || ! is_string( $metadata_values[0]['file'] ) ) {
		return new WP_Error( 'yotm_regenerate_metadata_state', __( 'Force requires exactly one valid raw attachment-metadata row.', 'thumbnail-manager' ) );
	}
	foreach ( $backup_values as $backup_row ) {
		if ( ! is_array( $backup_row ) ) {
			return new WP_Error( 'yotm_regenerate_backup_state', __( 'Attachment edit-backup state could not be resolved.', 'thumbnail-manager' ) );
		}
	}

	$attached = preg_match( '#^(?:[A-Za-z]:)?/#', $attached_values[0] ) ? $attached_values[0] : trailingslashit( $base ) . ltrim( $attached_values[0], '/\\' );
	$full     = trailingslashit( $base ) . ltrim( $metadata_values[0]['file'], '/\\' );
	$attached = yotm_media_source_canonical_path( $attached );
	$full     = yotm_media_source_canonical_path( $full );
	if ( is_wp_error( $attached ) || is_wp_error( $full ) || ! hash_equals( (string) $attached, (string) $full ) ) {
		return new WP_Error( 'yotm_regenerate_full_mismatch', __( 'Raw attached-file and metadata full-image paths do not match.', 'thumbnail-manager' ) );
	}
	if ( is_link( $full ) || ! is_file( $full ) ) {
		return new WP_Error( 'yotm_regenerate_full_missing', __( 'The current full image is missing or unsafe.', 'thumbnail-manager' ) );
	}

	$metadata  = $metadata_values[0];
	$directory = dirname( $full );
	$source    = $full;
	$protected = array( $full => 'metadata_full' );
	$fields    = array( 'original_image', 'source_image', 'thumb', 'animated_video', 'animated_video_poster' );
	foreach ( $fields as $field ) {
		if ( ! array_key_exists( $field, $metadata ) ) {
			continue;
		}
		$value = $metadata[ $field ];
		if ( ! is_string( $value ) || '' === $value || wp_basename( $value ) !== $value || false !== strpos( $value, '\\' ) ) {
			return new WP_Error( 'yotm_regenerate_companion_invalid', __( 'A declared attachment companion is malformed.', 'thumbnail-manager' ) );
		}
		$path = yotm_media_source_canonical_path( trailingslashit( $directory ) . $value );
		if ( is_wp_error( $path ) || is_link( $path ) || ! is_file( $path ) ) {
			return new WP_Error( 'yotm_regenerate_companion_missing', __( 'A declared attachment companion is missing or unsafe.', 'thumbnail-manager' ) );
		}
		$protected[ $path ] = $field;
		if ( 'original_image' === $field ) {
			$source = $path;
		}
	}
	foreach ( $backup_values as $backup_row ) {
		foreach ( $backup_row as $backup ) {
			$filename = is_array( $backup ) ? ( $backup['file'] ?? '' ) : '';
			if ( ! is_string( $filename ) || '' === $filename || wp_basename( $filename ) !== $filename || false !== strpos( $filename, '\\' ) ) {
				return new WP_Error( 'yotm_regenerate_backup_state', __( 'Attachment edit-backup state could not be resolved.', 'thumbnail-manager' ) );
			}
			$path = yotm_media_source_canonical_path( trailingslashit( $directory ) . $filename );
			if ( is_wp_error( $path ) ) {
				return $path;
			}
			if ( false !== @lstat( $path ) && ( is_link( $path ) || ! is_file( $path ) ) ) {
				return new WP_Error( 'yotm_regenerate_backup_state', __( 'Attachment edit-backup state could not be resolved.', 'thumbnail-manager' ) );
			}
			$protected[ $path ] = 'edit_backup';
		}
	}
	$source_hash = yotm_regenerate_file_hash( $source );
	if ( ! is_string( $source_hash ) || '' === $source_hash ) {
		return new WP_Error( 'yotm_regenerate_source_unreadable', __( 'The selected regeneration source could not be read safely.', 'thumbnail-manager' ) );
	}

	return array(
		'attachment_id' => $attachment_id,
		'attached_raw'  => $attached_values[0],
		'metadata'      => $metadata,
		'backups'       => array_values( $backup_values ),
		'full'          => $full,
		'source'        => $source,
		'protected'     => $protected,
		'metadata_hash' => yotm_regenerate_metadata_hash( $metadata ),
		'source_hash'   => $source_hash,
	);
}

/**
 * Recursively remove only a validated private Force staging directory.
 *
 * @param string $stage Staging path.
 * @param string $parent_dir Expected parent directory.
 * @return bool
 */
function yotm_regenerate_remove_stage( $stage, $parent_dir ) {
	$stage      = yotm_normalize_filesystem_path( (string) $stage );
	$parent_dir = trailingslashit( yotm_normalize_filesystem_path( (string) $parent_dir ) );
	if ( '' === $stage || 0 !== strpos( trailingslashit( $stage ), $parent_dir . '.yotm-regenerate-' ) || ! is_dir( $stage ) || is_link( $stage ) ) {
		return false;
	}
	$entries = scandir( $stage );
	if ( false === $entries ) {
		return false;
	}
	foreach ( $entries as $entry ) {
		if ( '.' === $entry || '..' === $entry ) {
			continue;
		}
		$path = $stage . '/' . $entry;
		if ( is_dir( $path ) && ! is_link( $path ) ) {
			return false;
		}
		if ( ! @unlink( $path ) ) {
			return false;
		}
	}
	return @rmdir( $stage );
}

/**
 * Generate all requested sub-sizes inside same-filesystem staging.
 *
 * @param array $snapshot Validated snapshot.
 * @return array|WP_Error
 */
function yotm_regenerate_stage_outputs( $snapshot ) {
	$parent = dirname( $snapshot['full'] );
	$stage  = $parent . '/.yotm-regenerate-' . wp_generate_uuid4();
	if ( ! wp_mkdir_p( $stage ) || is_link( $stage ) ) {
		return new WP_Error( 'yotm_regenerate_stage_create', __( 'Could not create the private regeneration staging directory.', 'thumbnail-manager' ) );
	}
	$stage_source = $stage . '/' . wp_basename( $snapshot['source'] );
	if ( ! @copy( $snapshot['source'], $stage_source ) || ! is_file( $stage_source ) || is_link( $stage_source ) ) {
		yotm_regenerate_remove_stage( $stage, $parent );
		return new WP_Error( 'yotm_regenerate_stage_copy', __( 'Could not stage the authoritative source image.', 'thumbnail-manager' ) );
	}

	$editor = wp_get_image_editor( $stage_source );
	if ( is_wp_error( $editor ) ) {
		yotm_regenerate_remove_stage( $stage, $parent );
		return $editor;
	}
	$rotated = $editor->maybe_exif_rotate();
	if ( is_wp_error( $rotated ) ) {
		yotm_regenerate_remove_stage( $stage, $parent );
		return $rotated;
	}
	$sizes = wp_get_registered_image_subsizes();
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core-compatible generation surface.
	$sizes = apply_filters( 'intermediate_image_sizes_advanced', $sizes, $snapshot['metadata'], $snapshot['attachment_id'] );
	if ( ! is_array( $sizes ) ) {
		yotm_regenerate_remove_stage( $stage, $parent );
		return new WP_Error( 'yotm_regenerate_sizes_invalid', __( 'The filtered image-size definition is invalid.', 'thumbnail-manager' ) );
	}

	$outputs = array();
	if ( method_exists( $editor, 'make_subsize' ) ) {
		foreach ( $sizes as $slug => $size ) {
			$result = $editor->make_subsize( $size );
			if ( is_wp_error( $result ) && 'error_getting_dimensions' === $result->get_error_code() && ( $editor instanceof WP_Image_Editor_GD || $editor instanceof WP_Image_Editor_Imagick ) ) {
				continue;
			}
			if ( is_wp_error( $result ) || ! is_array( $result ) ) {
				yotm_regenerate_remove_stage( $stage, $parent );
				return is_wp_error( $result ) ? $result : new WP_Error( 'yotm_regenerate_output_invalid', __( 'The image editor returned an invalid sub-size.', 'thumbnail-manager' ) );
			}
			$outputs[ sanitize_key( $slug ) ] = $result;
		}
	} else {
		$outputs = $editor->multi_resize( $sizes );
		if ( is_wp_error( $outputs ) || ! is_array( $outputs ) ) {
			yotm_regenerate_remove_stage( $stage, $parent );
			return is_wp_error( $outputs ) ? $outputs : new WP_Error( 'yotm_regenerate_output_invalid', __( 'The image editor returned invalid sub-sizes.', 'thumbnail-manager' ) );
		}
	}

	$validated = array();
	foreach ( $outputs as $slug => $output ) {
		$slug     = sanitize_key( $slug );
		$filename = is_array( $output ) ? ( $output['file'] ?? '' ) : '';
		$path     = is_string( $filename ) ? $stage . '/' . $filename : '';
		if ( '' === $slug || ! isset( $sizes[ $slug ] ) || ! is_string( $filename ) || '' === $filename || wp_basename( $filename ) !== $filename || ! is_file( $path ) || is_link( $path ) ) {
			yotm_regenerate_remove_stage( $stage, $parent );
			return new WP_Error( 'yotm_regenerate_output_invalid', __( 'A staged sub-size is missing or unsafe.', 'thumbnail-manager' ) );
		}
		$output['file']     = $filename;
		$output['filesize'] = filesize( $path );
		$validated[ $slug ] = array(
			'metadata' => $output,
			'path'     => $path,
		);
	}

	return array(
		'stage'   => $stage,
		'outputs' => $validated,
	);
}

/**
 * Evaluate both update filters once and bind every final size to one staged artifact.
 *
 * @param array $snapshot Snapshot.
 * @param array $staged Staging result.
 * @return array|WP_Error
 */
function yotm_regenerate_finalize_metadata( $snapshot, $staged ) {
	$dimensions = wp_getimagesize( $snapshot['full'] );
	if ( ! is_array( $dimensions ) || empty( $dimensions[0] ) || empty( $dimensions[1] ) ) {
		return new WP_Error( 'yotm_regenerate_full_dimensions', __( 'Current full-image dimensions could not be verified.', 'thumbnail-manager' ) );
	}
	$proposed             = $snapshot['metadata'];
	$proposed['width']    = (int) $dimensions[0];
	$proposed['height']   = (int) $dimensions[1];
	$proposed['filesize'] = filesize( $snapshot['full'] );
	$proposed['sizes']    = array();
	foreach ( $staged['outputs'] as $slug => $output ) {
		$proposed['sizes'][ $slug ] = $output['metadata'];
	}

	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Deliberate single Core-compatible update evaluation.
	$final = apply_filters( 'wp_generate_attachment_metadata', $proposed, $snapshot['attachment_id'], 'update' );
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Deliberate single Core-compatible persistence-filter evaluation.
	$final = apply_filters( 'wp_update_attachment_metadata', $final, $snapshot['attachment_id'] );
	if ( ! is_array( $final ) || empty( $final ) || ! is_array( $final['sizes'] ?? null ) ) {
		return new WP_Error( 'yotm_regenerate_filtered_metadata', __( 'A metadata filter returned an invalid result.', 'thumbnail-manager' ) );
	}

	$protected_fields = array( 'file', 'original_image', 'source_image', 'thumb', 'animated_video', 'animated_video_poster' );
	foreach ( $protected_fields as $field ) {
		if ( array_key_exists( $field, $snapshot['metadata'] ) !== array_key_exists( $field, $final ) || ( array_key_exists( $field, $snapshot['metadata'] ) && $snapshot['metadata'][ $field ] !== $final[ $field ] ) ) {
			return new WP_Error( 'yotm_regenerate_protected_metadata', __( 'A metadata filter changed a protected attachment file declaration.', 'thumbnail-manager' ) );
		}
	}
	if ( (int) ( $final['width'] ?? 0 ) !== $proposed['width'] || (int) ( $final['height'] ?? 0 ) !== $proposed['height'] || (int) ( $final['filesize'] ?? 0 ) !== $proposed['filesize'] ) {
		return new WP_Error( 'yotm_regenerate_full_metadata', __( 'A metadata filter changed verified current-full properties.', 'thumbnail-manager' ) );
	}

	$map  = array();
	$seen = array();
	foreach ( $final['sizes'] as $slug => &$size ) {
		$slug     = sanitize_key( $slug );
		$filename = is_array( $size ) ? ( $size['file'] ?? '' ) : '';
		if ( '' === $slug || ! isset( $staged['outputs'][ $slug ] ) || ! is_string( $filename ) || '' === $filename || wp_basename( $filename ) !== $filename || false !== strpos( $filename, '\\' ) ) {
			return new WP_Error( 'yotm_regenerate_filtered_size', __( 'A metadata filter introduced an ungenerated or unsafe sub-size.', 'thumbnail-manager' ) );
		}
		$source = $staged['outputs'][ $slug ]['path'];
		$target = $staged['stage'] . '/' . $filename;
		if ( ! hash_equals( yotm_normalize_filesystem_path( $source ), yotm_normalize_filesystem_path( $target ) ) ) {
			if ( false !== @lstat( $target ) || ! @rename( $source, $target ) ) {
				return new WP_Error( 'yotm_regenerate_stage_rename', __( 'A filtered staged-file rename could not be applied safely.', 'thumbnail-manager' ) );
			}
		}
		$destination = yotm_media_source_canonical_path( dirname( $snapshot['full'] ) . '/' . $filename );
		if ( is_wp_error( $destination ) || isset( $snapshot['protected'][ $destination ] ) || isset( $seen[ $destination ] ) || ! is_file( $target ) || is_link( $target ) ) {
			return new WP_Error( 'yotm_regenerate_destination_invalid', __( 'A generated destination collides with protected or duplicate media.', 'thumbnail-manager' ) );
		}
		$image_info = wp_getimagesize( $target );
		$file_type  = wp_check_filetype( $filename );
		if (
			! is_array( $image_info )
			|| (int) ( $size['width'] ?? 0 ) !== (int) ( $image_info[0] ?? 0 )
			|| (int) ( $size['height'] ?? 0 ) !== (int) ( $image_info[1] ?? 0 )
			|| sanitize_mime_type( $size['mime-type'] ?? '' ) !== sanitize_mime_type( $image_info['mime'] ?? '' )
			|| sanitize_mime_type( $file_type['type'] ?? '' ) !== sanitize_mime_type( $image_info['mime'] ?? '' )
		) {
			return new WP_Error( 'yotm_regenerate_output_metadata', __( 'A generated sub-size does not match its filtered metadata.', 'thumbnail-manager' ) );
		}
		$size['file']         = $filename;
		$size['filesize']     = filesize( $target );
		$seen[ $destination ] = true;
		$output_hash          = yotm_regenerate_file_hash( $target );
		if ( '' === $output_hash ) {
			return new WP_Error( 'yotm_regenerate_output_unreadable', __( 'A generated sub-size could not be read safely.', 'thumbnail-manager' ) );
		}
		$map[ $slug ] = array(
			'slug'        => $slug,
			'source'      => $target,
			'destination' => $destination,
			'hash'        => $output_hash,
		);
	}//end foreach
	unset( $size );

	return array(
		'metadata' => $final,
		'map'      => $map,
	);
}

/**
 * Return exact old generated tuple keys grouped by canonical path.
 *
 * @param array $snapshot Validated snapshot.
 * @return array|WP_Error
 */
function yotm_regenerate_old_owners( $snapshot ) {
	$owners = array();
	foreach ( (array) ( $snapshot['metadata']['sizes'] ?? array() ) as $slug => $size ) {
		$filename = is_array( $size ) ? ( $size['file'] ?? '' ) : '';
		if ( ! is_string( $filename ) || '' === $filename || wp_basename( $filename ) !== $filename ) {
			return new WP_Error( 'yotm_regenerate_old_size_invalid', __( 'Existing generated-size metadata is malformed.', 'thumbnail-manager' ) );
		}
		$path = yotm_media_source_canonical_path( dirname( $snapshot['full'] ) . '/' . $filename );
		if ( is_wp_error( $path ) ) {
			return $path;
		}
		$key                     = $snapshot['attachment_id'] . ':' . sanitize_key( $slug ) . ':' . $filename;
		$owners[ $path ][ $key ] = true;
	}
	return $owners;
}

/**
 * Strictly capture one destination's authorized pre-state.
 *
 * @param array  $entry Destination entry.
 * @param array  $snapshot Validated snapshot.
 * @param array  $old_owners Old owner map.
 * @param string $stage Staging directory.
 * @param bool   $make_backup Whether to create the verified private backup.
 * @return array|WP_Error
 */
function yotm_regenerate_destination_prestate( $entry, $snapshot, $old_owners, $stage, $make_backup = true ) {
	$owners = yotm_media_reference_path_owners( $entry['destination'] );
	if ( is_wp_error( $owners ) ) {
		return $owners;
	}
	if ( ! empty( $owners['protected'] ) ) {
		return new WP_Error( 'yotm_regenerate_destination_protected', __( 'A generated destination is protected by attachment source metadata.', 'thumbnail-manager' ) );
	}
	$expected = array_keys( $old_owners[ $entry['destination'] ] ?? array() );
	$actual   = array();
	foreach ( $owners['generated'] as $owner ) {
		$key            = absint( $owner['attachment_id'] ?? 0 ) . ':' . sanitize_key( $owner['size'] ?? '' ) . ':' . (string) ( $owner['filename'] ?? '' );
		$actual[ $key ] = true;
		if ( (int) $owner['attachment_id'] !== (int) $snapshot['attachment_id'] || ! in_array( $key, $expected, true ) ) {
			return new WP_Error( 'yotm_regenerate_destination_owned', __( 'Another or unexpected generated-file reference owns this destination.', 'thumbnail-manager' ) );
		}
	}
	ksort( $actual );
	$node = @lstat( $entry['destination'] );
	if ( empty( $actual ) ) {
		if ( false !== $node ) {
			return new WP_Error( 'yotm_regenerate_unmapped_destination', __( 'An existing unreferenced destination was preserved.', 'thumbnail-manager' ) );
		}
		return array(
			'mode'       => 'expected_absent',
			'owners'     => array(),
			'old_hash'   => '',
			'backup'     => '',
			'old_absent' => true,
			'promoted'   => false,
		);
	}
	if ( false !== $node && ( is_link( $entry['destination'] ) || ! is_file( $entry['destination'] ) ) ) {
		return new WP_Error( 'yotm_regenerate_destination_node', __( 'A generated destination is not a regular file.', 'thumbnail-manager' ) );
	}
	$backup = '';
	$hash   = '';
	if ( false !== $node ) {
		$hash = yotm_regenerate_file_hash( $entry['destination'] );
		if ( '' === $hash ) {
			return new WP_Error( 'yotm_regenerate_destination_unreadable', __( 'A replaceable generated file could not be read safely.', 'thumbnail-manager' ) );
		}
		if ( $make_backup ) {
			$backup = $stage . '/backup-' . hash( 'sha256', $entry['destination'] );
			if ( ! @copy( $entry['destination'], $backup ) || ! yotm_regenerate_file_hash_matches( $backup, $hash ) ) {
				return new WP_Error( 'yotm_regenerate_backup_failed', __( 'Could not verify a private backup of the replaceable generated file.', 'thumbnail-manager' ) );
			}
		}
	}
	return array(
		'mode'       => 'replaceable_old_generated',
		'owners'     => array_keys( $actual ),
		'old_hash'   => $hash,
		'backup'     => $backup,
		'old_absent' => false === $node,
		'promoted'   => false,
	);
}

/**
 * Persist one exact journal phase under the current item claim.
 *
 * @param array $item Claimed item, updated by reference.
 * @param array $journal Exact journal.
 * @return bool
 */
function yotm_regenerate_persist_journal( &$item, $journal ) {
	$payload                         = $item['payload'];
	$payload['regeneration_journal'] = $journal;
	if ( ! yotm_job_update_claimed_item_payload( $item, $payload ) ) {
		return false;
	}
	$item['payload'] = $payload;
	return true;
}

/**
 * Roll back only artifacts strictly proven by the journal.
 *
 * @param array $journal Exact journal.
 * @return bool
 */
function yotm_regenerate_rollback( $journal ) {
	$complete = true;
	foreach ( array_reverse( (array) ( $journal['destinations'] ?? array() ) ) as $entry ) {
		$path = (string) ( $entry['destination'] ?? '' );
		if ( empty( $entry['promoted'] ) ) {
			continue;
		}
		if ( ! yotm_regenerate_file_hash_matches( $path, (string) ( $entry['promoted_hash'] ?? '' ) ) ) {
			$complete = false;
			continue;
		}
		if ( 'expected_absent' === ( $entry['mode'] ?? '' ) || ! empty( $entry['old_absent'] ) ) {
			$complete = @unlink( $path ) && $complete;
		} elseif ( ! empty( $entry['backup'] ) && yotm_regenerate_file_hash_matches( $entry['backup'], (string) $entry['old_hash'] ) ) {
			$complete = @rename( $entry['backup'], $path ) && $complete;
		} else {
			$complete = false;
		}
	}
	return $complete;
}

/**
 * Resume or roll back a journal left by an interrupted Force request.
 *
 * @param array $item Claimed item, updated by reference.
 * @return array
 */
function yotm_regenerate_recover_journal( &$item ) {
	$journal = $item['payload']['regeneration_journal'] ?? array();
	if ( empty( $journal ) ) {
		return array(
			'status'  => 'none',
			'message' => '',
		);
	}
	if (
		YOTM_REGENERATE_JOURNAL_VERSION !== absint( $journal['version'] ?? 0 )
		|| empty( $journal['attachment_id'] )
		|| ! is_array( $journal['old_metadata'] ?? null )
		|| ! is_array( $journal['final_metadata'] ?? null )
		|| ! is_array( $journal['destinations'] ?? null )
		|| empty( $journal['full'] )
	) {
		return yotm_regenerate_failure( __( 'The regeneration recovery journal is malformed and requires manual inspection.', 'thumbnail-manager' ) );
	}
	$full  = yotm_media_source_canonical_path( $journal['full'] );
	$stage = yotm_normalize_filesystem_path( (string) ( $journal['stage'] ?? '' ) );
	if ( is_wp_error( $full ) || ! hash_equals( (string) $full, (string) $journal['full'] ) || 0 !== strpos( trailingslashit( $stage ), trailingslashit( dirname( $full ) ) . '.yotm-regenerate-' ) ) {
		return yotm_regenerate_failure( __( 'The regeneration recovery paths are invalid and require manual inspection.', 'thumbnail-manager' ) );
	}

	$aliases = array();
	foreach ( $journal['destinations'] as $entry ) {
		$destination = yotm_media_source_canonical_path( $entry['destination'] ?? '' );
		$backup      = yotm_normalize_filesystem_path( (string) ( $entry['backup'] ?? '' ) );
		if (
			is_wp_error( $destination )
			|| ! hash_equals( (string) $destination, (string) ( $entry['destination'] ?? '' ) )
			|| ( '' !== $backup && 0 !== strpos( $backup, trailingslashit( $stage ) ) )
		) {
			return yotm_regenerate_failure( __( 'The regeneration recovery paths are invalid and require manual inspection.', 'thumbnail-manager' ) );
		}
		$aliases[] = array( 'path' => $destination );
	}
	$locks = yotm_media_path_lock_aliases( $aliases );
	if ( is_wp_error( $locks ) ) {
		return yotm_regenerate_failure( $locks, true );
	}
	try {
		$raw_rows = yotm_media_reference_raw_postmeta_rows( absint( $journal['attachment_id'] ), '_wp_attachment_metadata' );
		if ( is_wp_error( $raw_rows ) ) {
			return yotm_regenerate_failure( $raw_rows, true );
		}
		$raw = array_column( $raw_rows, 'value' );
		if ( 1 !== count( $raw ) || ! is_array( $raw[0] ) ) {
			return yotm_regenerate_failure( __( 'Current metadata does not match either journaled transaction state.', 'thumbnail-manager' ) );
		}

		if ( $raw[0] === $journal['final_metadata'] && hash_equals( (string) $journal['new_metadata_hash'], yotm_regenerate_metadata_hash( $raw[0] ) ) ) {
			$synced = yotm_media_source_sync_attachment( absint( $journal['attachment_id'] ), null, true );
			if ( is_wp_error( $synced ) ) {
				return yotm_regenerate_failure( $synced, true );
			}
			$snapshot = array(
				'full'     => (string) $journal['full'],
				'metadata' => $journal['old_metadata'],
			);
			$cleaned  = yotm_regenerate_cleanup_obsolete( $snapshot, $journal['final_metadata'] );
			if ( is_wp_error( $cleaned ) ) {
				return yotm_regenerate_failure( $cleaned, true );
			}
			$journal['phase'] = 'cleanup_complete';
			if ( ! yotm_regenerate_persist_journal( $item, $journal ) ) {
				return yotm_regenerate_failure( __( 'Could not finalize the recovered regeneration journal.', 'thumbnail-manager' ), true );
			}
			yotm_regenerate_remove_stage( (string) ( $journal['stage'] ?? '' ), dirname( (string) $journal['full'] ) );
			return array(
				'status'  => 'regenerated',
				'message' => '',
			);
		}//end if

		if ( $raw[0] !== $journal['old_metadata'] || ! hash_equals( (string) $journal['old_metadata_hash'], yotm_regenerate_metadata_hash( $raw[0] ) ) ) {
			return yotm_regenerate_failure( __( 'Current metadata does not match either journaled transaction state.', 'thumbnail-manager' ) );
		}
		foreach ( $journal['destinations'] as $slug => $entry ) {
			$owners = yotm_media_reference_path_owners( $entry['destination'] ?? '' );
			if ( is_wp_error( $owners ) || ! empty( $owners['protected'] ) ) {
				return yotm_regenerate_failure( is_wp_error( $owners ) ? $owners : __( 'Recovery found a newly protected destination.', 'thumbnail-manager' ) );
			}
			$actual = array();
			foreach ( $owners['generated'] as $owner ) {
				$actual[] = absint( $owner['attachment_id'] ?? 0 ) . ':' . sanitize_key( $owner['size'] ?? '' ) . ':' . (string) ( $owner['filename'] ?? '' );
			}
			sort( $actual );
			$expected = array_values( (array) ( $entry['owners'] ?? array() ) );
			sort( $expected );
			if ( $actual !== $expected ) {
				return yotm_regenerate_failure( __( 'Destination ownership changed after the interrupted regeneration.', 'thumbnail-manager' ) );
			}
			$promoted = ! empty( $entry['promoted'] ) || (string) ( $journal['promotion_slug'] ?? '' ) === (string) $slug;
			$path     = (string) ( $entry['destination'] ?? '' );
			if ( $promoted ) {
				if ( ! yotm_regenerate_file_hash_matches( $path, (string) ( $entry['promoted_hash'] ?? '' ) ) ) {
					return yotm_regenerate_failure( __( 'A promoted artifact changed after the interrupted regeneration.', 'thumbnail-manager' ) );
				}
				if ( ! empty( $entry['old_absent'] ) ) {
					if ( ! @unlink( $path ) ) {
						return yotm_regenerate_failure( __( 'Could not remove an exact interrupted promoted artifact.', 'thumbnail-manager' ) );
					}
				} elseif ( empty( $entry['backup'] ) || ! yotm_regenerate_file_hash_matches( $entry['backup'], (string) $entry['old_hash'] ) || ! @rename( $entry['backup'], $path ) ) {
					return yotm_regenerate_failure( __( 'Could not restore an exact interrupted generated-file backup.', 'thumbnail-manager' ) );
				}
			} elseif ( ! empty( $entry['old_absent'] ) ? false !== @lstat( $path ) : ! yotm_regenerate_file_hash_matches( $path, (string) $entry['old_hash'] ) ) {
				return yotm_regenerate_failure( __( 'A non-promoted destination changed after the interrupted regeneration.', 'thumbnail-manager' ) );
			}
		}//end foreach

		$payload = $item['payload'];
		unset( $payload['regeneration_journal'] );
		if ( ! yotm_job_update_claimed_item_payload( $item, $payload ) ) {
			return yotm_regenerate_failure( __( 'Could not clear the rolled-back regeneration journal.', 'thumbnail-manager' ), true );
		}
		$item['payload'] = $payload;
		yotm_regenerate_remove_stage( (string) ( $journal['stage'] ?? '' ), dirname( (string) $journal['full'] ) );
		return yotm_regenerate_failure( __( 'The interrupted regeneration was rolled back and will be retried.', 'thumbnail-manager' ), true );
	} finally {
		foreach ( array_reverse( $locks ) as $lock ) {
			yotm_media_path_lock_release( $lock );
		}
	}//end try
}

/**
 * Delete exact obsolete old generated files after metadata commit.
 *
 * @param array $snapshot Validated old snapshot.
 * @param array $final_metadata Exact committed metadata.
 * @return true|WP_Error
 */
function yotm_regenerate_cleanup_obsolete( $snapshot, $final_metadata ) {
	$old = yotm_regenerate_metadata_file_map( $snapshot['full'], $snapshot['metadata'] );
	$new = yotm_regenerate_metadata_file_map( $snapshot['full'], $final_metadata );
	foreach ( array_diff_key( $old, $new ) as $path ) {
		$lock = yotm_media_path_lock_acquire( $path );
		if ( is_wp_error( $lock ) ) {
			return $lock;
		}
		try {
			$owners = yotm_media_reference_path_owners( $path );
			if ( is_wp_error( $owners ) ) {
				return $owners;
			}
			if ( ! empty( $owners['protected'] ) || ! empty( $owners['generated'] ) ) {
				continue;
			}
			if ( false === @lstat( $path ) ) {
				continue;
			}
			if ( is_link( $path ) || ! is_file( $path ) ) {
				return new WP_Error( 'yotm_regenerate_cleanup_failed', __( 'An obsolete generated file could not be deleted safely.', 'thumbnail-manager' ) );
			}
			wp_delete_file( $path );
			if ( false !== @lstat( $path ) ) {
				return new WP_Error( 'yotm_regenerate_cleanup_failed', __( 'An obsolete generated file could not be deleted safely.', 'thumbnail-manager' ) );
			}
		} finally {
			yotm_media_path_lock_release( $lock );
		}//end try
	}//end foreach
	return true;
}

/**
 * Execute one claim-fenced force-regeneration transaction.
 *
 * @param int   $attachment_id Attachment ID.
 * @param array $item Claimed item.
 * @param array $worker Worker ownership.
 * @return array{status:string,message:string,retryable?:bool}
 */
function yotm_regenerate_force_attachment( $attachment_id, $item, $worker ) {
	if ( empty( $item['id'] ) || ! yotm_job_refresh_worker( $worker ) || ! yotm_job_refresh_item_claim( $item ) ) {
		return yotm_regenerate_failure( __( 'The regeneration claim is stale.', 'thumbnail-manager' ), true );
	}
	$complete = yotm_media_reference_require_complete_index();
	if ( is_wp_error( $complete ) ) {
		return yotm_regenerate_failure( $complete, true );
	}
	$source_fence = yotm_media_source_fence_acquire();
	if ( is_wp_error( $source_fence ) ) {
		return yotm_regenerate_failure( $source_fence, true );
	}
	$attachment_lock = array();
	$path_locks      = array();
	$stage           = '';
	try {
		$attachment_lock = yotm_media_attachment_lock_acquire( $attachment_id );
		if ( is_wp_error( $attachment_lock ) ) {
			return yotm_regenerate_failure( $attachment_lock, true );
		}
		if ( ! empty( $item['payload']['regeneration_journal'] ) ) {
			return yotm_regenerate_recover_journal( $item );
		}
		$snapshot = yotm_regenerate_preflight( $attachment_id );
		if ( is_wp_error( $snapshot ) ) {
			return yotm_regenerate_failure( $snapshot );
		}
		$staged = yotm_regenerate_stage_outputs( $snapshot );
		if ( is_wp_error( $staged ) ) {
			return yotm_regenerate_failure( $staged );
		}
		$stage     = $staged['stage'];
		$finalized = yotm_regenerate_finalize_metadata( $snapshot, $staged );
		if ( is_wp_error( $finalized ) ) {
			return yotm_regenerate_failure( $finalized );
		}
		$aliases = array();
		foreach ( $finalized['map'] as $entry ) {
			$aliases[] = array( 'path' => $entry['destination'] );
		}
		$path_locks = yotm_media_path_lock_aliases( $aliases );
		if ( is_wp_error( $path_locks ) ) {
			return yotm_regenerate_failure( $path_locks, true );
		}
		$locked_paths = array_values( array_unique( array_column( $path_locks, 'path' ) ) );
		$final_paths  = array_values( array_unique( array_column( $finalized['map'], 'destination' ) ) );
		sort( $locked_paths );
		sort( $final_paths );
		if ( $locked_paths !== $final_paths ) {
			return yotm_regenerate_failure( __( 'The acquired destination-lock set does not match the final generated-file map.', 'thumbnail-manager' ) );
		}

		$current = yotm_regenerate_preflight( $attachment_id );
		if ( is_wp_error( $current ) || $current['attached_raw'] !== $snapshot['attached_raw'] || $current['metadata'] !== $snapshot['metadata'] || $current['backups'] !== $snapshot['backups'] || ! hash_equals( $snapshot['source_hash'], $current['source_hash'] ) ) {
			return yotm_regenerate_failure( __( 'Attachment state changed during regeneration; no live file was replaced.', 'thumbnail-manager' ), true );
		}
		$old_owners = yotm_regenerate_old_owners( $snapshot );
		if ( is_wp_error( $old_owners ) ) {
			return yotm_regenerate_failure( $old_owners );
		}
		$destinations = array();
		foreach ( $finalized['map'] as $slug => $entry ) {
			$prestate = yotm_regenerate_destination_prestate( $entry, $snapshot, $old_owners, $stage );
			if ( is_wp_error( $prestate ) ) {
				return yotm_regenerate_failure( $prestate );
			}
			$destinations[ $slug ] = array_merge( $entry, $prestate, array( 'promoted_hash' => $entry['hash'] ) );
		}
		$journal = array(
			'version'           => YOTM_REGENERATE_JOURNAL_VERSION,
			'phase'             => 'prepared',
			'attachment_id'     => $attachment_id,
			'old_metadata'      => $snapshot['metadata'],
			'old_metadata_hash' => $snapshot['metadata_hash'],
			'final_metadata'    => $finalized['metadata'],
			'new_metadata_hash' => yotm_regenerate_metadata_hash( $finalized['metadata'] ),
			'full'              => $snapshot['full'],
			'stage'             => $stage,
			'destinations'      => $destinations,
			'promotion_slug'    => '',
		);
		if ( ! yotm_regenerate_persist_journal( $item, $journal ) ) {
			return yotm_regenerate_failure( __( 'Could not persist the regeneration journal.', 'thumbnail-manager' ), true );
		}

		foreach ( $journal['destinations'] as $slug => &$entry ) {
			$owners_now = yotm_regenerate_destination_prestate( $entry, $snapshot, $old_owners, $stage, false );
			if ( is_wp_error( $owners_now ) || $owners_now['mode'] !== $entry['mode'] || $owners_now['owners'] !== $entry['owners'] || $owners_now['old_hash'] !== $entry['old_hash'] ) {
				return yotm_regenerate_failure( __( 'A generated destination changed before promotion.', 'thumbnail-manager' ), true );
			}
			$journal['promotion_slug'] = (string) $slug;
			if ( ! yotm_regenerate_persist_journal( $item, $journal ) ) {
				return yotm_regenerate_failure( __( 'Could not persist the next promotion intent.', 'thumbnail-manager' ), true );
			}
			if ( ! @rename( $entry['source'], $entry['destination'] ) || ! yotm_regenerate_file_hash_matches( $entry['destination'], $entry['promoted_hash'] ) ) {
				$entry['promoted'] = yotm_regenerate_file_hash_matches( $entry['destination'], $entry['promoted_hash'] );
				yotm_regenerate_rollback( $journal );
				return yotm_regenerate_failure( __( 'Could not promote all staged generated files.', 'thumbnail-manager' ) );
			}
			$entry['promoted']         = true;
			$journal['promotion_slug'] = '';
			if ( ! yotm_regenerate_persist_journal( $item, $journal ) ) {
				return yotm_regenerate_failure( __( 'Could not persist promoted-file evidence.', 'thumbnail-manager' ), true );
			}
		}
		unset( $entry );
		$journal['phase'] = 'files_promoted';
		if ( ! yotm_regenerate_persist_journal( $item, $journal ) ) {
			yotm_regenerate_rollback( $journal );
			return yotm_regenerate_failure( __( 'Could not persist the promoted-files journal.', 'thumbnail-manager' ) );
		}

		$written  = update_post_meta( $attachment_id, '_wp_attachment_metadata', $journal['final_metadata'] );
		$raw_rows = yotm_media_reference_raw_postmeta_rows( $attachment_id, '_wp_attachment_metadata' );
		$raw      = is_wp_error( $raw_rows ) ? array() : array_column( $raw_rows, 'value' );
		if ( ( false === $written && ( 1 !== count( $raw ) || $raw[0] !== $journal['final_metadata'] ) ) || 1 !== count( $raw ) || $raw[0] !== $journal['final_metadata'] ) {
			update_post_meta( $attachment_id, '_wp_attachment_metadata', $snapshot['metadata'] );
			$restored_rows = yotm_media_reference_raw_postmeta_rows( $attachment_id, '_wp_attachment_metadata' );
			$restored_raw  = is_wp_error( $restored_rows ) ? array() : array_column( $restored_rows, 'value' );
			$rolled_back   = yotm_regenerate_rollback( $journal );
			if ( ! $rolled_back || 1 !== count( $restored_raw ) || $restored_raw[0] !== $snapshot['metadata'] ) {
				return yotm_regenerate_failure( __( 'Metadata commit and automatic rollback were incomplete; journal evidence was retained for manual recovery.', 'thumbnail-manager' ) );
			}
			return yotm_regenerate_failure( __( 'Could not commit exact regenerated attachment metadata; the old state was restored.', 'thumbnail-manager' ) );
		}
		$journal['phase'] = 'metadata_committed';
		if ( ! yotm_regenerate_persist_journal( $item, $journal ) ) {
			return yotm_regenerate_failure( __( 'Metadata committed, but its recovery journal could not be advanced.', 'thumbnail-manager' ) );
		}
		$synced = yotm_media_source_sync_attachment( $attachment_id, null, true );
		if ( is_wp_error( $synced ) ) {
			return yotm_regenerate_failure( $synced, true );
		}
		$cleaned = yotm_regenerate_cleanup_obsolete( $snapshot, $journal['final_metadata'] );
		if ( is_wp_error( $cleaned ) ) {
			return yotm_regenerate_failure( $cleaned, true );
		}
		$journal['phase'] = 'cleanup_complete';
		if ( ! yotm_regenerate_persist_journal( $item, $journal ) ) {
			return yotm_regenerate_failure( __( 'Could not finalize the regeneration journal.', 'thumbnail-manager' ), true );
		}
		yotm_regenerate_remove_stage( $stage, dirname( $snapshot['full'] ) );
		$stage = '';
		return array(
			'status'  => 'regenerated',
			'message' => '',
		);
	} finally {
		foreach ( array_reverse( is_array( $path_locks ) ? $path_locks : array() ) as $handle ) {
			yotm_media_path_lock_release( $handle );
		}
		if ( is_array( $attachment_lock ) ) {
			yotm_media_attachment_lock_release( $attachment_lock );
		}
		yotm_media_source_fence_release( $source_fence );
		if ( '' !== $stage && is_dir( $stage ) && empty( $item['payload']['regeneration_journal'] ) ) {
			yotm_regenerate_remove_stage( $stage, dirname( $stage ) );
		}
	}//end try
}
