<?php
/**
 * Regeneration media primitives and attachment operations.
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
 * @param int  $attachment_id Attachment ID.
 * @param bool $repair Whether missing-only repair uses the current full image.
 * @return array|WP_Error
 */
function yotm_regenerate_preflight( $attachment_id, $repair = false ) {
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
		if ( 'original_image' === $field && ! $repair ) {
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
	$dimensions = $repair ? wp_getimagesize( $source ) : array();
	if ( $repair && ( ! is_array( $dimensions ) || empty( $dimensions[0] ) || empty( $dimensions[1] ) ) ) {
		return new WP_Error( 'yotm_regenerate_source_dimensions', __( 'The current full image cannot be decoded. Restore a readable local source image before repair.', 'thumbnail-manager' ) );
	}

	return array(
		'attachment_id'     => $attachment_id,
		'attached_meta_id'  => absint( $attached_rows[0]['meta_id'] ?? 0 ),
		'metadata_meta_id'  => absint( $metadata_rows[0]['meta_id'] ?? 0 ),
		'attached_raw'      => $attached_values[0],
		'metadata'          => $metadata,
		'backups'           => array_values( $backup_values ),
		'full'              => $full,
		'source'            => $source,
		'protected'         => $protected,
		'metadata_hash'     => yotm_regenerate_metadata_hash( $metadata ),
		'source_hash'       => $source_hash,
		'repair'            => (bool) $repair,
		'source_dimensions' => $dimensions,
	);
}

/**
 * Resolve current permitted definitions within an explicit job-local scope.
 *
 * @param array $snapshot Validated attachment snapshot with selected_sizes.
 * @return array|WP_Error
 */
function yotm_regenerate_scoped_sizes( $snapshot ) {
	$registered = wp_get_registered_image_subsizes();
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Existing generation permission surface.
	$sizes = apply_filters( 'intermediate_image_sizes_advanced', $registered, $snapshot['metadata'], $snapshot['attachment_id'] );
	if ( ! is_array( $sizes ) ) {
		return new WP_Error( 'yotm_regenerate_sizes_invalid', __( 'The filtered image-size definition is invalid.', 'thumbnail-manager' ) );
	}
	return array_intersect_key( yotm_apply_enabled_size_policy( $sizes ), $registered, array_flip( $snapshot['selected_sizes'] ) );
}

/**
 * Prove scoped metadata and destination preservation, including recovery.
 *
 * @param array $old Original metadata.
 * @param array $final_metadata Exact final metadata.
 * @param array $selected_sizes Job-local scope.
 * @param array $destinations Exact staged or journal destination map.
 * @return true|WP_Error
 */
function yotm_regenerate_validate_scope( $old, $final_metadata, $selected_sizes, $destinations ) {
	if ( ! is_array( $selected_sizes ) || empty( $selected_sizes ) || array_diff( array_keys( $destinations ), $selected_sizes ) ) {
		return new WP_Error( 'yotm_regenerate_scope_invalid', __( 'The regeneration size scope is invalid.', 'thumbnail-manager' ) );
	}
	if ( ! is_array( $old['sizes'] ?? array() ) || ! is_array( $final_metadata['sizes'] ?? array() ) ) {
		return new WP_Error( 'yotm_regenerate_scope_metadata', __( 'The scoped thumbnail metadata is malformed.', 'thumbnail-manager' ) );
	}
	$preserved_old            = $old;
	$preserved_final          = $final_metadata;
	$preserved_old['sizes']   = $preserved_old['sizes'] ?? array();
	$preserved_final['sizes'] = $preserved_final['sizes'] ?? array();
	foreach ( $destinations as $slug => $entry ) {
		if ( ! isset( $final_metadata['sizes'][ $slug ] ) ) {
			return new WP_Error( 'yotm_regenerate_scope_target_removed', __( 'A metadata filter removed a selected generated size.', 'thumbnail-manager' ) );
		}
		unset( $preserved_old['sizes'][ $slug ], $preserved_final['sizes'][ $slug ] );
		foreach ( (array) ( $old['sizes'] ?? array() ) as $old_slug => $size ) {
			if ( ! isset( $destinations[ $old_slug ] ) && is_array( $size ) && wp_basename( $entry['destination'] ) === ( $size['file'] ?? '' ) ) {
				return new WP_Error( 'yotm_regenerate_scope_alias', __( 'A selected destination is shared with an unselected size. Existing thumbnails were preserved.', 'thumbnail-manager' ) );
			}
		}
	}
	if ( $preserved_old !== $preserved_final ) {
		return new WP_Error( 'yotm_regenerate_scope_metadata', __( 'A metadata filter changed data outside the selected sizes. Existing thumbnails were preserved.', 'thumbnail-manager' ) );
	}
	return true;
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
	$rotated = empty( $snapshot['repair'] ) ? $editor->maybe_exif_rotate() : true;
	if ( is_wp_error( $rotated ) ) {
		yotm_regenerate_remove_stage( $stage, $parent );
		return $rotated;
	}
	if ( ! empty( $snapshot['repair'] ) ) {
		$sizes = $snapshot['requested_sizes'] ?? array();
	} elseif ( null !== ( $snapshot['selected_sizes'] ?? null ) ) {
		$permitted = yotm_regenerate_scoped_sizes( $snapshot );
		$sizes     = $permitted;
	} else {
		$sizes = wp_get_registered_image_subsizes();
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core-compatible generation surface.
		$sizes = apply_filters( 'intermediate_image_sizes_advanced', $sizes, $snapshot['metadata'], $snapshot['attachment_id'] );
		$sizes = yotm_apply_enabled_size_policy( $sizes );
	}
	if ( null !== ( $snapshot['selected_sizes'] ?? null ) ) {
		$permitted = $permitted ?? yotm_regenerate_scoped_sizes( $snapshot );
		if ( is_wp_error( $permitted ) ) {
			yotm_regenerate_remove_stage( $stage, $parent );
			return $permitted;
		}
		$sizes = ! empty( $snapshot['repair'] ) ? array_intersect_key( $sizes, $permitted ) : $permitted;
	}
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
	if ( ! empty( $snapshot['repair'] ) && array_diff_key( $sizes, $outputs ) ) {
		yotm_regenerate_remove_stage( $stage, $parent );
		return new WP_Error( 'yotm_repair_output_missing', __( 'The image editor did not create every requested missing thumbnail.', 'thumbnail-manager' ) );
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
		if ( ! empty( $snapshot['repair'] ) && ( (int) ( $output['width'] ?? 0 ) > (int) $snapshot['source_dimensions'][0] || (int) ( $output['height'] ?? 0 ) > (int) $snapshot['source_dimensions'][1] ) ) {
			yotm_regenerate_remove_stage( $stage, $parent );
			return new WP_Error( 'yotm_repair_output_upscaled', __( 'A repair output would upscale the current full image.', 'thumbnail-manager' ) );
		}
		$validated[ $slug ] = array(
			'metadata' => $output,
			'path'     => $path,
		);
	}

	return array(
		'stage'              => $stage,
		'outputs'            => $validated,
		'scoped_definitions' => $permitted ?? null,
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
	$repair   = ! empty( $snapshot['repair'] );
	$scoped   = null !== ( $snapshot['selected_sizes'] ?? null );
	$proposed = $snapshot['metadata'];
	if ( ! $repair && ! $scoped ) {
		$proposed['width']    = (int) $dimensions[0];
		$proposed['height']   = (int) $dimensions[1];
		$proposed['filesize'] = filesize( $snapshot['full'] );
		$proposed['sizes']    = array();
	} else {
		$proposed['sizes'] = $proposed['sizes'] ?? array();
	}
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
	if ( $repair ) {
		$preserved_old          = $snapshot['metadata'];
		$preserved_final        = $final;
		$preserved_old['sizes'] = $preserved_old['sizes'] ?? array();
		foreach ( array_keys( $staged['outputs'] ) as $slug ) {
			unset( $preserved_old['sizes'][ $slug ], $preserved_final['sizes'][ $slug ] );
			if ( ! isset( $final['sizes'][ $slug ] ) ) {
				return new WP_Error( 'yotm_repair_target_removed', __( 'A metadata filter removed a required repair size.', 'thumbnail-manager' ) );
			}
		}
		if ( $preserved_old !== $preserved_final ) {
			return new WP_Error( 'yotm_repair_preserved_metadata', __( 'A metadata filter changed data outside the missing sizes. No live file was replaced.', 'thumbnail-manager' ) );
		}
	}

	$protected_fields = array( 'file', 'original_image', 'source_image', 'thumb', 'animated_video', 'animated_video_poster' );
	foreach ( $protected_fields as $field ) {
		if ( array_key_exists( $field, $snapshot['metadata'] ) !== array_key_exists( $field, $final ) || ( array_key_exists( $field, $snapshot['metadata'] ) && $snapshot['metadata'][ $field ] !== $final[ $field ] ) ) {
			return new WP_Error( 'yotm_regenerate_protected_metadata', __( 'A metadata filter changed a protected attachment file declaration.', 'thumbnail-manager' ) );
		}
	}
	if ( ! $repair && ! $scoped && ( (int) ( $final['width'] ?? 0 ) !== $proposed['width'] || (int) ( $final['height'] ?? 0 ) !== $proposed['height'] || (int) ( $final['filesize'] ?? 0 ) !== $proposed['filesize'] ) ) {
		return new WP_Error( 'yotm_regenerate_full_metadata', __( 'A metadata filter changed verified current-full properties.', 'thumbnail-manager' ) );
	}

	$map  = array();
	$seen = array();
	foreach ( $final['sizes'] as $slug => &$size ) {
		if ( ( $repair || $scoped ) && ! isset( $staged['outputs'][ $slug ] ) ) {
			continue;
		}
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

	if ( $scoped && array_diff_key( $staged['outputs'], $map ) ) {
		return new WP_Error( 'yotm_regenerate_scope_target_removed', __( 'A metadata filter removed a selected generated size.', 'thumbnail-manager' ) );
	}
	if ( $scoped ) {
		$scope_valid = yotm_regenerate_validate_scope( $snapshot['metadata'], $final, $snapshot['selected_sizes'], $map );
		if ( is_wp_error( $scope_valid ) ) {
			return $scope_valid;
		}
	}
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
		if ( null !== ( $snapshot['selected_sizes'] ?? null ) && ! in_array( $owner['size'] ?? '', $snapshot['selected_sizes'], true ) ) {
			return new WP_Error( 'yotm_regenerate_scope_owner', __( 'An unselected size owns this destination. Its file was preserved.', 'thumbnail-manager' ) );
		}
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
		if ( ( 'repair' === ( $journal['mode'] ?? '' ) && ! yotm_thumbnail_health_owns_output( $entry ) ) || ! yotm_regenerate_file_hash_matches( $path, (string) ( $entry['promoted_hash'] ?? '' ) ) ) {
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
 * Promote one exact staged destination and record observed promotion state.
 *
 * @param array $entry Journal destination entry, updated by reference.
 * @return bool
 */
function yotm_regenerate_promote_destination( &$entry ) {
	if ( ! @rename( $entry['source'], $entry['destination'] ) || ! yotm_regenerate_file_hash_matches( $entry['destination'], $entry['promoted_hash'] ) ) {
		$entry['promoted'] = yotm_regenerate_file_hash_matches( $entry['destination'], $entry['promoted_hash'] );
		return false;
	}

	$entry['promoted'] = true;
	return true;
}

/**
 * Classify exact raw metadata against the two journaled transaction states.
 *
 * @param array $journal Exact regeneration journal.
 * @return string|WP_Error Either final or old.
 */
function yotm_regenerate_recovery_metadata_state( $journal ) {
	$raw_rows = yotm_media_reference_raw_postmeta_rows( absint( $journal['attachment_id'] ), '_wp_attachment_metadata' );
	if ( is_wp_error( $raw_rows ) ) {
		return $raw_rows;
	}
	$raw = array_column( $raw_rows, 'value' );
	if ( 1 !== count( $raw ) || ! is_array( $raw[0] ) ) {
		return new WP_Error( 'yotm_regenerate_recovery_metadata_state', __( 'Current metadata does not match either journaled transaction state.', 'thumbnail-manager' ) );
	}
	if ( $raw[0] === $journal['final_metadata'] && hash_equals( (string) $journal['new_metadata_hash'], yotm_regenerate_metadata_hash( $raw[0] ) ) ) {
		return 'final';
	}
	if ( $raw[0] === $journal['old_metadata'] && hash_equals( (string) $journal['old_metadata_hash'], yotm_regenerate_metadata_hash( $raw[0] ) ) ) {
		return 'old';
	}

	return new WP_Error( 'yotm_regenerate_recovery_metadata_state', __( 'Current metadata does not match either journaled transaction state.', 'thumbnail-manager' ) );
}

/**
 * Restore every destination from an interrupted promotion journal.
 *
 * The caller must hold the deterministic destination locks.
 *
 * @param array $journal Exact regeneration journal.
 * @return true|WP_Error
 */
function yotm_regenerate_recover_destinations( $journal ) {
	foreach ( $journal['destinations'] as $slug => $entry ) {
		$owners = yotm_media_reference_path_owners( $entry['destination'] ?? '' );
		if ( is_wp_error( $owners ) || ! empty( $owners['protected'] ) ) {
			return is_wp_error( $owners ) ? $owners : new WP_Error( 'yotm_regenerate_recovery_protected', __( 'Recovery found a newly protected destination.', 'thumbnail-manager' ) );
		}
		$actual = array();
		foreach ( $owners['generated'] as $owner ) {
			$actual[] = absint( $owner['attachment_id'] ?? 0 ) . ':' . sanitize_key( $owner['size'] ?? '' ) . ':' . (string) ( $owner['filename'] ?? '' );
		}
		sort( $actual );
		$expected = array_values( (array) ( $entry['owners'] ?? array() ) );
		sort( $expected );
		if ( $actual !== $expected ) {
			return new WP_Error( 'yotm_regenerate_recovery_ownership', __( 'Destination ownership changed after the interrupted regeneration.', 'thumbnail-manager' ) );
		}
		$promoted = ! empty( $entry['promoted'] ) || (string) ( $journal['promotion_slug'] ?? '' ) === (string) $slug;
		$path     = (string) ( $entry['destination'] ?? '' );
		if ( $promoted ) {
			if ( ! yotm_regenerate_file_hash_matches( $path, (string) ( $entry['promoted_hash'] ?? '' ) ) ) {
				return new WP_Error( 'yotm_regenerate_recovery_promoted_changed', __( 'A promoted artifact changed after the interrupted regeneration.', 'thumbnail-manager' ) );
			}
			if ( ! empty( $entry['old_absent'] ) ) {
				if ( ! @unlink( $path ) ) {
					return new WP_Error( 'yotm_regenerate_recovery_remove_failed', __( 'Could not remove an exact interrupted promoted artifact.', 'thumbnail-manager' ) );
				}
			} elseif ( empty( $entry['backup'] ) || ! yotm_regenerate_file_hash_matches( $entry['backup'], (string) $entry['old_hash'] ) || ! @rename( $entry['backup'], $path ) ) {
				return new WP_Error( 'yotm_regenerate_recovery_restore_failed', __( 'Could not restore an exact interrupted generated-file backup.', 'thumbnail-manager' ) );
			}
		} elseif ( ! empty( $entry['old_absent'] ) ? false !== @lstat( $path ) : ! yotm_regenerate_file_hash_matches( $path, (string) $entry['old_hash'] ) ) {
			return new WP_Error( 'yotm_regenerate_recovery_destination_changed', __( 'A non-promoted destination changed after the interrupted regeneration.', 'thumbnail-manager' ) );
		}
	}//end foreach

	return true;
}

/**
 * Commit exact attachment metadata and restore the old transaction state on failure.
 *
 * @param int   $attachment_id Attachment ID.
 * @param array $journal Exact regeneration journal.
 * @param array $old_metadata Exact old metadata.
 * @return string Either committed, restored, or incomplete.
 */
function yotm_regenerate_commit_metadata( $attachment_id, $journal, $old_metadata ) {
	$written  = update_post_meta( $attachment_id, '_wp_attachment_metadata', $journal['final_metadata'] );
	$raw_rows = yotm_media_reference_raw_postmeta_rows( $attachment_id, '_wp_attachment_metadata' );
	$raw      = is_wp_error( $raw_rows ) ? array() : array_column( $raw_rows, 'value' );
	if ( ( false === $written && ( 1 !== count( $raw ) || $raw[0] !== $journal['final_metadata'] ) ) || 1 !== count( $raw ) || $raw[0] !== $journal['final_metadata'] ) {
		update_post_meta( $attachment_id, '_wp_attachment_metadata', $old_metadata );
		$restored_rows = yotm_media_reference_raw_postmeta_rows( $attachment_id, '_wp_attachment_metadata' );
		$restored_raw  = is_wp_error( $restored_rows ) ? array() : array_column( $restored_rows, 'value' );
		$rolled_back   = yotm_regenerate_rollback( $journal );
		return $rolled_back && 1 === count( $restored_raw ) && $restored_raw[0] === $old_metadata ? 'restored' : 'incomplete';
	}

	return 'committed';
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
 * Regenerate one attachment with an original-image source for force mode.
 *
 * @param int  $attachment_id Attachment ID.
 * @param bool $only_missing Only create missing sizes.
 * @param bool $force_all Force full metadata regeneration.
 * @return array{status:string,message:string}
 */
function yotm_regenerate_attachment( $attachment_id, $only_missing, $force_all ) {
	if ( $force_all ) {
		return yotm_regenerate_failure( __( 'Force regeneration requires a persistent claimed job item.', 'thumbnail-manager' ) );
	}
	$file = get_attached_file( $attachment_id );
	$mime = get_post_mime_type( $attachment_id );

	if ( ! $file || ! file_exists( $file ) ) {
		return array(
			'status'  => 'failed',
			'message' => __( 'Attached image file is missing.', 'thumbnail-manager' ),
		);
	}

	if ( ! is_string( $mime ) || 0 !== strpos( $mime, 'image/' ) || 'image/svg+xml' === $mime ) {
		return array(
			'status'  => 'skipped',
			'message' => __( 'Attachment is not a raster image.', 'thumbnail-manager' ),
		);
	}

	if ( $only_missing && ! $force_all && function_exists( 'wp_get_missing_image_subsizes' ) && function_exists( 'wp_update_image_subsizes' ) ) {
		$missing = yotm_apply_enabled_size_policy( wp_get_missing_image_subsizes( $attachment_id ) );

		if ( empty( $missing ) ) {
			return array(
				'status'  => 'skipped',
				'message' => __( 'No image sizes are missing.', 'thumbnail-manager' ),
			);
		}

		$result = wp_update_image_subsizes( $attachment_id );

		return is_wp_error( $result )
			? array(
				'status'  => 'failed',
				'message' => $result->get_error_message(),
			)
			: array(
				'status'  => 'regenerated',
				'message' => '',
			);
	}//end if

	$old_metadata = wp_get_attachment_metadata( $attachment_id );
	$source_file  = function_exists( 'wp_get_original_image_path' ) ? wp_get_original_image_path( $attachment_id ) : $file;

	if ( ! is_string( $source_file ) || ! file_exists( $source_file ) ) {
		$source_file = $file;
	}

	$metadata = wp_generate_attachment_metadata( $attachment_id, $source_file );

	if ( is_wp_error( $metadata ) || empty( $metadata ) || ! is_array( $metadata ) ) {
		$message = is_wp_error( $metadata ) ? $metadata->get_error_message() : __( 'WordPress returned empty image metadata.', 'thumbnail-manager' );

		return array(
			'status'  => 'failed',
			'message' => $message,
		);
	}

	if ( is_array( $old_metadata ) ) {
		$metadata = array_merge( $old_metadata, $metadata );
	}

	if ( false === wp_update_attachment_metadata( $attachment_id, $metadata ) ) {
		return array(
			'status'  => 'failed',
			'message' => __( 'Could not update attachment metadata.', 'thumbnail-manager' ),
		);
	}

	return array(
		'status'  => 'regenerated',
		'message' => '',
	);
}

/**
 * Build normalized generated-file paths from attachment metadata.
 *
 * @param string $attached_file Current attached file path.
 * @param array  $metadata Attachment metadata.
 * @return array<string,string>
 */
function yotm_regenerate_metadata_file_map( $attached_file, $metadata ) {
	$paths = array();

	if ( ! is_string( $attached_file ) || '' === $attached_file || empty( $metadata['sizes'] ) || ! is_array( $metadata['sizes'] ) ) {
		return $paths;
	}

	$directory = trailingslashit( dirname( yotm_normalize_filesystem_path( $attached_file ) ) );

	foreach ( $metadata['sizes'] as $size_data ) {
		if ( ! is_array( $size_data ) ) {
			continue;
		}

		$filename = $size_data['file'] ?? ( $size_data['filename'] ?? '' );
		if ( ! is_string( $filename ) || '' === $filename ) {
			continue;
		}

		$path           = yotm_normalize_filesystem_path( $directory . wp_basename( $filename ) );
		$paths[ $path ] = $path;
	}

	return $paths;
}

/**
 * Delete stale generated files after replacement metadata has been saved.
 *
 * Files still referenced by the new metadata and all original/attached paths
 * remain protected.
 *
 * @param string $attached_file Current attached file path.
 * @param string $source_file Original source used to regenerate metadata.
 * @param array  $old_metadata Previous attachment metadata.
 * @param array  $new_metadata Replacement attachment metadata.
 */
function yotm_cleanup_obsolete_generated_files( $attached_file, $source_file, $old_metadata, $new_metadata ) {
	$uploads = wp_get_upload_dir();
	$base    = (string) ( $uploads['basedir'] ?? '' );

	if ( '' === $base ) {
		return;
	}

	$old_paths = yotm_regenerate_metadata_file_map( $attached_file, $old_metadata );
	$new_paths = yotm_regenerate_metadata_file_map( $attached_file, $new_metadata );
	$protected = array();

	foreach ( array( $attached_file, $source_file ) as $path ) {
		if ( is_string( $path ) && '' !== $path ) {
			$protected[ yotm_normalize_filesystem_path( $path ) ] = true;
		}
	}

	foreach ( array_diff_key( $old_paths, $new_paths ) as $path ) {
		if ( isset( $protected[ $path ] ) || ! yotm_is_path_inside_dir( $path, $base ) || ! is_file( $path ) ) {
			continue;
		}

		wp_delete_file( $path );
	}
}
