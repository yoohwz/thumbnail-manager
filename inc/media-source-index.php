<?php
/**
 * Authoritative attachment-source index and path-scoped safety locks.
 *
 * @package Thumbnail_Manager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/media/paths.php';
require_once __DIR__ . '/media/attachments.php';
require_once __DIR__ . '/media/source-store.php';
require_once __DIR__ . '/media/source-locks.php';

// Exact raw metadata fencing requires uncached row queries and generated placeholder lists.
// phpcs:disable WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.SlowDBQuery.slow_db_query_meta_key,WordPress.DB.SlowDBQuery.slow_db_query_meta_value,WordPress.WP.GetMetaSingle.Missing

if ( ! defined( 'YOTM_MEDIA_SOURCE_FANOUT_LIMIT' ) ) {
	define( 'YOTM_MEDIA_SOURCE_FANOUT_LIMIT', 100 );
}

if ( ! defined( 'YOTM_MEDIA_SOURCE_DIRTY_OPTION' ) ) {
	define( 'YOTM_MEDIA_SOURCE_DIRTY_OPTION', 'yotm_media_source_index_dirty' );
}

if ( ! defined( 'YOTM_MEDIA_REFERENCE_STATE_OPTION' ) ) {
	define( 'YOTM_MEDIA_REFERENCE_STATE_OPTION', 'yotm_media_reference_index_state' );
}

if ( ! defined( 'YOTM_MEDIA_REFERENCE_GENERATION' ) ) {
	define( 'YOTM_MEDIA_REFERENCE_GENERATION', 2 );
}

if ( ! defined( 'YOTM_MEDIA_REFERENCE_STATE_VERSION' ) ) {
	define( 'YOTM_MEDIA_REFERENCE_STATE_VERSION', 1 );
}

if ( ! defined( 'YOTM_MEDIA_REFERENCE_PROTECTED_KINDS' ) ) {
	define( 'YOTM_MEDIA_REFERENCE_PROTECTED_KINDS', array( 'attached', 'metadata_full', 'original', 'source_image', 'thumb', 'animated_video', 'animated_video_poster', 'edit_backup' ) );
}

add_filter( 'add_post_metadata', 'yotm_guard_add_post_metadata', PHP_INT_MIN, 5 );
add_filter( 'update_post_metadata', 'yotm_guard_update_post_metadata', PHP_INT_MIN, 5 );
add_filter( 'update_post_metadata_by_mid', 'yotm_guard_update_post_metadata_by_mid', PHP_INT_MIN, 4 );
add_filter( 'add_post_metadata', 'yotm_finalize_add_post_metadata_guard', PHP_INT_MAX, 5 );
add_filter( 'update_post_metadata', 'yotm_finalize_update_post_metadata_guard', PHP_INT_MAX, 5 );
add_filter( 'update_post_metadata_by_mid', 'yotm_finalize_update_post_metadata_by_mid_guard', PHP_INT_MAX, 4 );
add_filter( 'delete_post_metadata', 'yotm_guard_delete_post_metadata', PHP_INT_MIN, 5 );
add_filter( 'delete_post_metadata', 'yotm_finalize_delete_post_metadata_guard', PHP_INT_MAX, 5 );
add_filter( 'delete_post_metadata_by_mid', 'yotm_guard_delete_post_metadata_by_mid', PHP_INT_MIN, 2 );
add_filter( 'delete_post_metadata_by_mid', 'yotm_finalize_delete_post_metadata_by_mid_guard', PHP_INT_MAX, 2 );
add_action( 'added_post_meta', 'yotm_media_source_complete_added_meta_guard', PHP_INT_MIN, 4 );
add_action( 'updated_post_meta', 'yotm_media_source_complete_updated_meta_guard', PHP_INT_MIN, 4 );
add_action( 'deleted_post_meta', 'yotm_media_source_resync_after_meta_delete', 10, 4 );
add_action( 'deleted_post', 'yotm_media_source_delete_attachment_rows', 10, 2 );

/**
 * Return whether guarded source persistence is installed for this site.
 *
 * An older marker cannot have an approved generated_file_v1 prune job. It is
 * therefore safe to defer guards until the coordinated migration runs. A
 * current marker with a missing table remains fail-closed through DB errors.
 *
 * @return bool
 */
function yotm_media_source_guard_enabled() {
	return YOTM_JOB_DB_VERSION === get_option( 'yotm_job_db_version' );
}

/**
 * Return the durable pending source-index mutations for this site.
 *
 * @return array{version:int,entries:array}|WP_Error
 */
function yotm_media_source_dirty_state() {
	$state = get_option( YOTM_MEDIA_SOURCE_DIRTY_OPTION, false );
	if ( false === $state ) {
		return array(
			'version' => 1,
			'entries' => array(),
		);
	}
	if ( ! is_array( $state ) || 1 !== (int) ( $state['version'] ?? 0 ) || ! is_array( $state['entries'] ?? null ) ) {
		return new WP_Error( 'yotm_media_source_dirty_invalid', __( 'The authoritative media source index has an invalid pending state. Run a new prune scan to repair it.', 'thumbnail-manager' ) );
	}

	$entries = array();
	foreach ( $state['entries'] as $token => $entry ) {
		if ( ! is_string( $token ) || '' === $token || ! is_array( $entry ) || empty( $entry['attachment_id'] ) ) {
			return new WP_Error( 'yotm_media_source_dirty_invalid', __( 'The authoritative media source index has an invalid pending state. Run a new prune scan to repair it.', 'thumbnail-manager' ) );
		}
		$entries[ $token ] = array(
			'attachment_id' => absint( $entry['attachment_id'] ),
			'marked_at'     => sanitize_text_field( $entry['marked_at'] ?? '' ),
		);
	}
	ksort( $entries );

	return array(
		'version' => 1,
		'entries' => $entries,
	);
}

/**
 * Persist the normalized site source-index dirty state.
 *
 * Callers that mutate this state must own the site source fence.
 *
 * @param array{version:int,entries:array} $state Dirty state.
 * @return true|WP_Error
 */
function yotm_media_source_dirty_persist( $state ) {
	if ( empty( $state['entries'] ) ) {
		delete_option( YOTM_MEDIA_SOURCE_DIRTY_OPTION );
		if ( false === get_option( YOTM_MEDIA_SOURCE_DIRTY_OPTION, false ) ) {
			return true;
		}
	} else {
		ksort( $state['entries'] );
		update_option( YOTM_MEDIA_SOURCE_DIRTY_OPTION, $state, false );
		$stored = yotm_media_source_dirty_state();
		if ( ! is_wp_error( $stored ) && $state === $stored ) {
			return true;
		}
	}

	return new WP_Error( 'yotm_media_source_dirty_persist_failed', __( 'The authoritative media source index could not record its pending state.', 'thumbnail-manager' ) );
}

/**
 * Mark one guarded mutation dirty before allowing its authoritative write.
 *
 * @param string $token Guard frame token.
 * @param int    $attachment_id Attachment ID.
 * @return true|WP_Error
 */
function yotm_media_source_dirty_mark( $token, $attachment_id ) {
	$state = yotm_media_source_dirty_state();
	if ( is_wp_error( $state ) ) {
		return $state;
	}

	$token         = sanitize_text_field( $token );
	$attachment_id = absint( $attachment_id );
	if ( '' === $token || ! $attachment_id ) {
		return new WP_Error( 'yotm_media_source_dirty_invalid', __( 'The authoritative media source index could not identify its pending mutation.', 'thumbnail-manager' ) );
	}

	$state['entries'][ $token ] = array(
		'attachment_id' => $attachment_id,
		'marked_at'     => gmdate( 'Y-m-d H:i:s' ),
	);

	return yotm_media_source_dirty_persist( $state );
}

/**
 * Clear exact guarded-mutation tokens after their live aliases are durable.
 *
 * @param string[] $tokens Guard frame tokens.
 * @return true|WP_Error
 */
function yotm_media_source_dirty_clear_tokens( $tokens ) {
	$state = yotm_media_source_dirty_state();
	if ( is_wp_error( $state ) ) {
		return $state;
	}

	foreach ( (array) $tokens as $token ) {
		unset( $state['entries'][ (string) $token ] );
	}

	return yotm_media_source_dirty_persist( $state );
}

/**
 * Clear all pending tokens for an attachment after a repair sync or deletion.
 *
 * @param int $attachment_id Attachment ID.
 * @return true|WP_Error
 */
function yotm_media_source_dirty_clear_attachment( $attachment_id ) {
	$state = yotm_media_source_dirty_state();
	if ( is_wp_error( $state ) ) {
		return $state;
	}

	$attachment_id = absint( $attachment_id );
	foreach ( $state['entries'] as $token => $entry ) {
		if ( $attachment_id === (int) $entry['attachment_id'] ) {
			unset( $state['entries'][ $token ] );
		}
	}

	return yotm_media_source_dirty_persist( $state );
}

/**
 * Require a clean source index before trusting any negative lookup.
 *
 * @return true|WP_Error
 */
function yotm_media_source_require_clean_index() {
	$state = yotm_media_source_dirty_state();
	if ( is_wp_error( $state ) ) {
		return $state;
	}
	if ( ! empty( $state['entries'] ) ) {
		return new WP_Error( 'yotm_media_source_index_dirty', __( 'The authoritative media source index has a pending mutation. Run a new prune scan before destructive work.', 'thumbnail-manager' ) );
	}

	return true;
}

/**
 * Return the site-wide reverse-reference completeness state.
 *
 * @return array{version:int,semantic_generation:int,status:string,baseline_token:string,completed_at:string}|WP_Error
 */
function yotm_media_reference_index_state() {
	$state = get_option( YOTM_MEDIA_REFERENCE_STATE_OPTION, false );
	if ( ! is_array( $state ) ) {
		return new WP_Error( 'yotm_media_reference_index_incomplete', __( 'The media reference index needs a complete site scan before destructive work.', 'thumbnail-manager' ) );
	}

	$normalized = array(
		'version'             => absint( $state['version'] ?? 0 ),
		'semantic_generation' => absint( $state['semantic_generation'] ?? 0 ),
		'status'              => sanitize_key( $state['status'] ?? '' ),
		'baseline_token'      => sanitize_text_field( $state['baseline_token'] ?? '' ),
		'completed_at'        => sanitize_text_field( $state['completed_at'] ?? '' ),
	);

	if (
		YOTM_MEDIA_REFERENCE_STATE_VERSION !== $normalized['version']
		|| ! in_array( $normalized['status'], array( 'building', 'complete' ), true )
		|| '' === $normalized['baseline_token']
	) {
		return new WP_Error( 'yotm_media_reference_index_invalid', __( 'The media reference index completeness state is invalid.', 'thumbnail-manager' ) );
	}

	return $normalized;
}

/**
 * Persist one exact reverse-reference completeness state.
 *
 * Callers must own the site source fence.
 *
 * @param array $state State to persist.
 * @return true|WP_Error
 */
function yotm_media_reference_index_persist_state( $state ) {
	$state = array(
		'version'             => YOTM_MEDIA_REFERENCE_STATE_VERSION,
		'semantic_generation' => absint( $state['semantic_generation'] ?? YOTM_MEDIA_REFERENCE_GENERATION ),
		'status'              => sanitize_key( $state['status'] ?? 'building' ),
		'baseline_token'      => sanitize_text_field( $state['baseline_token'] ?? '' ),
		'completed_at'        => sanitize_text_field( $state['completed_at'] ?? '' ),
	);
	if ( '' === $state['baseline_token'] || ! in_array( $state['status'], array( 'building', 'complete' ), true ) ) {
		return new WP_Error( 'yotm_media_reference_index_invalid', __( 'The media reference index completeness state is invalid.', 'thumbnail-manager' ) );
	}

	update_option( YOTM_MEDIA_REFERENCE_STATE_OPTION, $state, false );
	$stored = yotm_media_reference_index_state();
	if ( ! is_wp_error( $stored ) && $state === $stored ) {
		return true;
	}

	return new WP_Error( 'yotm_media_reference_index_persist_failed', __( 'The media reference index completeness state could not be persisted.', 'thumbnail-manager' ) );
}

/**
 * Require a current complete baseline and no pending mutation.
 *
 * @return true|WP_Error
 */
function yotm_media_reference_require_complete_index() {
	$state = yotm_media_reference_index_state();
	if ( is_wp_error( $state ) ) {
		return $state;
	}
	if ( YOTM_MEDIA_REFERENCE_GENERATION !== (int) $state['semantic_generation'] || 'complete' !== $state['status'] ) {
		return new WP_Error( 'yotm_media_reference_index_incomplete', __( 'The media reference index needs a complete site scan before destructive work.', 'thumbnail-manager' ) );
	}

	return yotm_media_source_require_clean_index();
}

/**
 * Resolve an attachment state into authoritative source aliases.
 *
 * @param int         $attachment_id Attachment ID.
 * @param string|null $attached_value Attached-file value, null for live value.
 * @param array|null  $metadata_value Metadata value, null for live value.
 * @param bool        $filter_proposed Whether to apply live WordPress filters to explicit proposed values.
 * @param array|null  $backup_value Explicit edit-backup value, null for live value.
 * @return array[]|WP_Error
 */
function yotm_media_source_aliases( $attachment_id, $attached_value = null, $metadata_value = null, $filter_proposed = false, $backup_value = null ) {
	$attachment_id = absint( $attachment_id );
	$uploads       = wp_get_upload_dir();
	$base          = (string) ( $uploads['basedir'] ?? '' );

	if ( ! $attachment_id || '' === $base ) {
		return new WP_Error( 'yotm_media_source_state_unresolved', __( 'Attachment source state could not be resolved.', 'thumbnail-manager' ) );
	}

	$live            = null === $attached_value && null === $metadata_value && null === $backup_value;
	$attached_values = array();
	$metadata_values = array();
	$backup_values   = array();
	if ( $live ) {
		$attached_rows = yotm_media_reference_raw_postmeta_rows( $attachment_id, '_wp_attached_file' );
		$metadata_rows = yotm_media_reference_raw_postmeta_rows( $attachment_id, '_wp_attachment_metadata' );
		$backup_rows   = yotm_media_reference_raw_postmeta_rows( $attachment_id, '_wp_attachment_backup_sizes' );
		if ( is_wp_error( $attached_rows ) || is_wp_error( $metadata_rows ) || is_wp_error( $backup_rows ) ) {
			return is_wp_error( $attached_rows ) ? $attached_rows : ( is_wp_error( $metadata_rows ) ? $metadata_rows : $backup_rows );
		}
		foreach ( $attached_rows as $stored_row ) {
			$stored_file = $stored_row['value'];
			if ( ! is_string( $stored_file ) || '' === $stored_file ) {
				return new WP_Error( 'yotm_media_source_state_unresolved', __( 'Attachment source state could not be resolved.', 'thumbnail-manager' ) );
			}
			$attached_values[] = $stored_file;
		}
		$filtered_file = get_attached_file( $attachment_id );
		if ( is_string( $filtered_file ) && '' !== $filtered_file ) {
			$attached_values[] = $filtered_file;
		}
		foreach ( $metadata_rows as $stored_row ) {
			$stored_metadata = $stored_row['value'];
			if ( ! is_array( $stored_metadata ) ) {
				return new WP_Error( 'yotm_media_generated_state_unresolved', __( 'Attachment generated-file state could not be resolved.', 'thumbnail-manager' ) );
			}
			$metadata_values[] = array(
				'value'             => $stored_metadata,
				'include_generated' => true,
			);
		}
		$filtered_metadata = wp_get_attachment_metadata( $attachment_id );
		if ( is_array( $filtered_metadata ) ) {
			$metadata_values[] = array(
				'value'             => $filtered_metadata,
				'include_generated' => false,
			);
		}
		foreach ( $backup_rows as $stored_row ) {
			$stored_backups = $stored_row['value'];
			if ( ! is_array( $stored_backups ) ) {
				return new WP_Error( 'yotm_media_backup_state_unresolved', __( 'Attachment edit-backup state could not be resolved.', 'thumbnail-manager' ) );
			}
			$backup_values[] = $stored_backups;
		}
	} else {
		$attached_values = is_string( $attached_value ) && '' !== $attached_value ? array( $attached_value ) : array();
		$metadata_values = is_array( $metadata_value )
			? array(
				array(
					'value'             => $metadata_value,
					'include_generated' => true,
				),
			)
			: array();
		if ( null === $backup_value ) {
			$backup_rows = yotm_media_reference_raw_postmeta_rows( $attachment_id, '_wp_attachment_backup_sizes' );
			if ( is_wp_error( $backup_rows ) ) {
				return $backup_rows;
			}
			foreach ( $backup_rows as $stored_row ) {
				$stored_backups = $stored_row['value'];
				if ( ! is_array( $stored_backups ) ) {
					return new WP_Error( 'yotm_media_backup_state_unresolved', __( 'Attachment edit-backup state could not be resolved.', 'thumbnail-manager' ) );
				}
				$backup_values[] = $stored_backups;
			}
		} elseif ( is_array( $backup_value ) ) {
			$backup_values[] = $backup_value;
		} else {
			return new WP_Error( 'yotm_media_backup_state_unresolved', __( 'Attachment edit-backup state could not be resolved.', 'thumbnail-manager' ) );
		}
		if ( $filter_proposed ) {
			$filtered_file = yotm_media_source_filter_proposed_file( $attachment_id, $attached_value );
			if ( is_string( $filtered_file ) && '' !== $filtered_file ) {
				$attached_values[] = $filtered_file;
			}
			$filtered_metadata = yotm_media_source_filter_proposed_metadata( $attachment_id, $metadata_value );
			if ( is_array( $filtered_metadata ) ) {
				$metadata_values[] = array(
					'value'             => $filtered_metadata,
					'include_generated' => false,
				);
			}
		}
	}//end if

	$raw            = array();
	$attached_paths = array();
	foreach ( $attached_values as $value ) {
		if ( '' !== $value ) {
			$attached_path    = preg_match( '#^(?:[A-Za-z]:)?/#', $value ) ? $value : trailingslashit( $base ) . ltrim( $value, '/\\' );
			$attached_paths[] = $attached_path;
			$raw[]            = array(
				'kind' => 'attached',
				'path' => $attached_path,
			);
		}
	}
	foreach ( $metadata_values as $metadata_entry ) {
		$metadata          = $metadata_entry['value'];
		$include_generated = ! empty( $metadata_entry['include_generated'] );
		if ( isset( $metadata['sizes'] ) && ! is_array( $metadata['sizes'] ) ) {
			return new WP_Error( 'yotm_media_generated_state_unresolved', __( 'Attachment generated-file state could not be resolved.', 'thumbnail-manager' ) );
		}

		$metadata_dir = '';
		if ( ! empty( $metadata['file'] ) && is_string( $metadata['file'] ) ) {
			$metadata_path = trailingslashit( $base ) . ltrim( $metadata['file'], '/\\' );
			$metadata_dir  = dirname( $metadata_path );
			$raw[]         = array(
				'kind' => 'metadata_full',
				'path' => $metadata_path,
			);
		}
		if ( '' === $metadata_dir && ! empty( $attached_paths ) ) {
			$metadata_dir = dirname( reset( $attached_paths ) );
		}

		$companions = array(
			'original_image'        => 'original',
			'source_image'          => 'source_image',
			'thumb'                 => 'thumb',
			'animated_video'        => 'animated_video',
			'animated_video_poster' => 'animated_video_poster',
		);
		foreach ( $companions as $field => $kind ) {
			if ( ! array_key_exists( $field, $metadata ) || '' === $metadata[ $field ] || null === $metadata[ $field ] ) {
				continue;
			}
			$value = $metadata[ $field ];
			if ( ! is_string( $value ) || '' === $metadata_dir || wp_basename( $value ) !== $value || false !== strpos( $value, '\\' ) ) {
				return new WP_Error( 'yotm_media_companion_state_unresolved', __( 'Attachment companion-file state could not be resolved.', 'thumbnail-manager' ) );
			}
			$raw[] = array(
				'kind' => $kind,
				'path' => trailingslashit( $metadata_dir ) . $value,
			);
		}

		foreach ( $include_generated ? (array) ( $metadata['sizes'] ?? array() ) : array() as $size_data ) {
			if ( ! is_array( $size_data ) ) {
				return new WP_Error( 'yotm_media_generated_state_unresolved', __( 'Attachment generated-file state could not be resolved.', 'thumbnail-manager' ) );
			}
			$filename = $size_data['file'] ?? ( $size_data['filename'] ?? '' );
			if ( ! is_string( $filename ) || '' === $filename || '' === $metadata_dir || wp_basename( $filename ) !== $filename || false !== strpos( $filename, '\\' ) ) {
				return new WP_Error( 'yotm_media_generated_state_unresolved', __( 'Attachment generated-file state could not be resolved.', 'thumbnail-manager' ) );
			}
			$raw[] = array(
				'kind' => 'generated',
				'path' => trailingslashit( $metadata_dir ) . $filename,
			);
		}
	}//end foreach

	$backup_dir = ! empty( $attached_paths ) ? dirname( reset( $attached_paths ) ) : '';
	foreach ( $backup_values as $backup_sizes ) {
		foreach ( $backup_sizes as $backup ) {
			if ( ! is_array( $backup ) ) {
				return new WP_Error( 'yotm_media_backup_state_unresolved', __( 'Attachment edit-backup state could not be resolved.', 'thumbnail-manager' ) );
			}
			$filename = $backup['file'] ?? '';
			if ( ! is_string( $filename ) || '' === $filename || '' === $backup_dir || wp_basename( $filename ) !== $filename || false !== strpos( $filename, '\\' ) ) {
				return new WP_Error( 'yotm_media_backup_state_unresolved', __( 'Attachment edit-backup state could not be resolved.', 'thumbnail-manager' ) );
			}
			$raw[] = array(
				'kind' => 'edit_backup',
				'path' => trailingslashit( $backup_dir ) . $filename,
			);
		}
	}
	if ( $live && function_exists( 'wp_get_original_image_path' ) ) {
		$live_original = wp_get_original_image_path( $attachment_id );
		if ( is_string( $live_original ) && '' !== $live_original ) {
			$raw[] = array(
				'kind' => 'original',
				'path' => $live_original,
			);
		}
	} elseif ( $filter_proposed ) {
		$proposed_original = yotm_media_source_filter_proposed_original( $attachment_id, $filtered_file ?? false, $filtered_metadata ?? false );
		if ( is_string( $proposed_original ) && '' !== $proposed_original ) {
			$raw[] = array(
				'kind' => 'original',
				'path' => $proposed_original,
			);
		}
	}

	$aliases = array();
	foreach ( $raw as $source ) {
		$kind      = $source['kind'];
		$path      = $source['path'];
		$canonical = yotm_media_source_canonical_path( $path );
		if ( is_wp_error( $canonical ) ) {
			return $canonical;
		}
		$key             = $kind . ':' . hash( 'sha256', $canonical );
		$aliases[ $key ] = array(
			'attachment_id' => $attachment_id,
			'source_kind'   => $kind,
			'path_hash'     => hash( 'sha256', $canonical ),
			'path'          => $canonical,
		);
	}

	ksort( $aliases );
	return array_values( $aliases );
}

/**
 * Apply get_attached_file semantics to an explicit proposed raw value.
 *
 * @param int          $attachment_id Attachment ID.
 * @param string|false $attached_value Proposed raw attached-file value.
 * @return string|false
 */
function yotm_media_source_filter_proposed_file( $attachment_id, $attached_value ) {
	$file = is_string( $attached_value ) && '' !== $attached_value ? $attached_value : false;
	if ( $file && ! preg_match( '#^(?:[A-Za-z]:)?/#', $file ) ) {
		$uploads = wp_get_upload_dir();
		if ( false === ( $uploads['error'] ?? false ) ) {
			$file = trailingslashit( (string) $uploads['basedir'] ) . ltrim( $file, '/\\' );
		}
	}

	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Intentionally mirror the WordPress Core accessor filter.
	return apply_filters( 'get_attached_file', $file, absint( $attachment_id ) );
}

/**
 * Apply wp_get_attachment_metadata semantics to an explicit proposed value.
 *
 * @param int   $attachment_id Attachment ID.
 * @param mixed $metadata_value Proposed raw metadata value.
 * @return array|false
 */
function yotm_media_source_filter_proposed_metadata( $attachment_id, $metadata_value ) {
	if ( ! is_array( $metadata_value ) || empty( $metadata_value ) ) {
		return false;
	}

	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Intentionally mirror the WordPress Core accessor filter.
	$metadata = apply_filters( 'wp_get_attachment_metadata', $metadata_value, absint( $attachment_id ) );
	if ( ! is_array( $metadata ) ) {
		return false;
	}
	if ( array_key_exists( 'sizes', $metadata ) && ! is_array( $metadata['sizes'] ) ) {
		$metadata['sizes'] = array();
	}

	return $metadata;
}

/**
 * Apply wp_get_original_image_path semantics to proposed filtered state.
 *
 * @param int          $attachment_id Attachment ID.
 * @param string|false $filtered_file Proposed filtered attached file.
 * @param array|false  $filtered_metadata Proposed filtered metadata.
 * @return string|false
 */
function yotm_media_source_filter_proposed_original( $attachment_id, $filtered_file, $filtered_metadata ) {
	if ( ! is_string( $filtered_file ) || '' === $filtered_file ) {
		return false;
	}

	$original = empty( $filtered_metadata['original_image'] )
		? $filtered_file
		: path_join( dirname( $filtered_file ), $filtered_metadata['original_image'] );

	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Intentionally mirror the WordPress Core accessor filter.
	return apply_filters( 'wp_get_original_image_path', $original, absint( $attachment_id ) );
}

/**
 * Synchronize one image attachment from live source metadata.
 *
 * @param int           $attachment_id Attachment ID.
 * @param callable|null $barrier Optional test-only barrier after the live read.
 * @param bool          $repair_dirty Whether a successful live replacement repairs this attachment's dirty tokens.
 * @return true|WP_Error
 */
function yotm_media_source_sync_attachment( $attachment_id, $barrier = null, $repair_dirty = false ) {
	$source_fence = yotm_media_source_fence_acquire();
	if ( is_wp_error( $source_fence ) ) {
		return $source_fence;
	}

	try {
		$attachment_lock = yotm_media_attachment_lock_acquire( $attachment_id );
		if ( is_wp_error( $attachment_lock ) ) {
			return $attachment_lock;
		}

		try {
			$aliases = yotm_media_source_aliases( $attachment_id );
			if ( is_wp_error( $aliases ) ) {
				return $aliases;
			}
			if ( is_callable( $barrier ) ) {
				call_user_func( $barrier, $attachment_id, $aliases, $attachment_lock );
			}

			$replaced = yotm_media_source_replace_attachment( $attachment_id, $aliases );
			if ( is_wp_error( $replaced ) || ! $repair_dirty ) {
				return $replaced;
			}

			return yotm_media_source_dirty_clear_attachment( $attachment_id );
		} finally {
			yotm_media_attachment_lock_release( $attachment_lock );
		}
	} finally {
		yotm_media_source_fence_release( $source_fence );
	}//end try
}

/**
 * Remove all current-site source rows before a bounded baseline rebuild.
 *
 * @return true|WP_Error
 */
function yotm_media_source_clear_index() {
	$begun = yotm_media_reference_baseline_begin();
	return is_wp_error( $begun ) ? $begun : true;
}

/**
 * Invalidate and clear the current-site reference index for a bounded rebuild.
 *
 * @param string $token Optional exact baseline token.
 * @return array|WP_Error Building state.
 */
function yotm_media_reference_baseline_begin( $token = '' ) {
	$source_fence = yotm_media_source_fence_acquire();
	if ( is_wp_error( $source_fence ) ) {
		return $source_fence;
	}

	try {
		$token     = is_string( $token ) && '' !== $token ? $token : wp_generate_uuid4();
		$state     = array(
			'semantic_generation' => YOTM_MEDIA_REFERENCE_GENERATION,
			'status'              => 'building',
			'baseline_token'      => $token,
			'completed_at'        => '',
		);
		$persisted = yotm_media_reference_index_persist_state( $state );
		if ( is_wp_error( $persisted ) ) {
			return $persisted;
		}
		$cleared = yotm_media_source_store_clear();
		if ( is_wp_error( $cleared ) ) {
			return $cleared;
		}

		return yotm_media_reference_index_state();
	} finally {
		yotm_media_source_fence_release( $source_fence );
	}//end try
}

/**
 * Mark one exact current-generation baseline complete under the source fence.
 *
 * @param string $token Exact baseline token.
 * @return true|WP_Error
 */
function yotm_media_reference_baseline_complete( $token ) {
	$source_fence = yotm_media_source_fence_acquire();
	if ( is_wp_error( $source_fence ) ) {
		return $source_fence;
	}

	try {
		$state = yotm_media_reference_index_state();
		if (
			is_wp_error( $state )
			|| 'building' !== $state['status']
			|| YOTM_MEDIA_REFERENCE_GENERATION !== (int) $state['semantic_generation']
			|| ! hash_equals( (string) $state['baseline_token'], (string) $token )
		) {
			return new WP_Error( 'yotm_media_reference_baseline_stale', __( 'The media reference baseline is stale and must restart.', 'thumbnail-manager' ) );
		}
		$clean = yotm_media_source_require_clean_index();
		if ( is_wp_error( $clean ) ) {
			return $clean;
		}

		return yotm_media_reference_index_persist_state(
			array(
				'semantic_generation' => YOTM_MEDIA_REFERENCE_GENERATION,
				'status'              => 'complete',
				'baseline_token'      => (string) $token,
				'completed_at'        => gmdate( 'Y-m-d H:i:s' ),
			)
		);
	} finally {
		yotm_media_source_fence_release( $source_fence );
	}//end try
}

/**
 * Build the proposed source state for a metadata mutation.
 *
 * @param int    $attachment_id Attachment ID.
 * @param string $original_key Original meta key.
 * @param string $effective_key Effective post-write key.
 * @param mixed  $meta_value Proposed value.
 * @return array[]|WP_Error
 */
function yotm_media_source_proposed_aliases( $attachment_id, $original_key, $effective_key, $meta_value ) {
	$current       = yotm_media_source_aliases( $attachment_id );
	$attached_rows = yotm_media_reference_raw_postmeta_rows( $attachment_id, '_wp_attached_file' );
	$metadata_rows = yotm_media_reference_raw_postmeta_rows( $attachment_id, '_wp_attachment_metadata' );
	$backup_rows   = yotm_media_reference_raw_postmeta_rows( $attachment_id, '_wp_attachment_backup_sizes' );
	if ( is_wp_error( $current ) || is_wp_error( $attached_rows ) || is_wp_error( $metadata_rows ) || is_wp_error( $backup_rows ) ) {
		return is_wp_error( $current ) ? $current : ( is_wp_error( $attached_rows ) ? $attached_rows : ( is_wp_error( $metadata_rows ) ? $metadata_rows : $backup_rows ) );
	}
	if ( count( $attached_rows ) > 1 || count( $metadata_rows ) > 1 || count( $backup_rows ) > 1 ) {
		return new WP_Error( 'yotm_media_source_state_ambiguous', __( 'Attachment source metadata contains multiple authoritative rows and cannot be mutated safely.', 'thumbnail-manager' ) );
	}

	$attached = ! empty( $attached_rows ) ? $attached_rows[0]['value'] : '';
	$metadata = ! empty( $metadata_rows ) ? $metadata_rows[0]['value'] : array();
	$metadata = is_array( $metadata ) ? $metadata : array();
	$backups  = ! empty( $backup_rows ) ? $backup_rows[0]['value'] : array();
	$backups  = is_array( $backups ) ? $backups : array();

	if ( '_wp_attached_file' === $original_key && '_wp_attached_file' !== $effective_key ) {
		$attached = '';
	}
	if ( '_wp_attachment_metadata' === $original_key && '_wp_attachment_metadata' !== $effective_key ) {
		$metadata = array();
	}
	if ( '_wp_attached_file' === $effective_key ) {
		$attached = is_string( $meta_value ) ? $meta_value : '';
	}
	if ( '_wp_attachment_metadata' === $effective_key ) {
		$metadata = is_array( $meta_value ) ? $meta_value : array();
	}
	if ( '_wp_attachment_backup_sizes' === $original_key && '_wp_attachment_backup_sizes' !== $effective_key ) {
		$backups = array();
	}
	if ( '_wp_attachment_backup_sizes' === $effective_key ) {
		$backups = is_array( $meta_value ) ? $meta_value : array();
	}

	$proposed = yotm_media_source_aliases( $attachment_id, $attached, $metadata, true, $backups );
	if ( is_wp_error( $proposed ) ) {
		return $proposed;
	}

	$aliases = array();
	foreach ( array_merge( $current, $proposed ) as $alias ) {
		$key             = sanitize_key( $alias['source_kind'] ?? '' ) . ':' . (string) ( $alias['path_hash'] ?? '' );
		$aliases[ $key ] = $alias;
	}
	ksort( $aliases );

	return array_values( $aliases );
}

/**
 * Start a fail-closed metadata source guard.
 *
 * @param string $channel Mutation channel.
 * @param int    $attachment_id Attachment ID.
 * @param string $original_key Original key.
 * @param string $effective_key Effective key.
 * @param mixed  $meta_value Proposed value.
 * @param int    $meta_id Optional meta ID.
 * @param bool   $had_meta Whether the regular update key existed at guard time.
 * @param bool   $defer_proposed Whether Core must be the sole evaluator of the proposed value.
 * @return string|WP_Error Frame ID, empty string for an unrelated key, or error.
 */
function yotm_media_source_begin_guard( $channel, $attachment_id, $original_key, $effective_key, $meta_value, $meta_id = 0, $had_meta = true, $defer_proposed = false ) {
	$authoritative = array( '_wp_attached_file', '_wp_attachment_metadata', '_wp_attachment_backup_sizes' );
	if ( ! in_array( $original_key, $authoritative, true ) && ! in_array( $effective_key, $authoritative, true ) ) {
		return '';
	}

	$source_fence = yotm_media_source_fence_acquire();
	if ( is_wp_error( $source_fence ) ) {
		return $source_fence;
	}

	$attachment_handle = yotm_media_attachment_lock_acquire( $attachment_id );
	if ( is_wp_error( $attachment_handle ) ) {
		yotm_media_source_fence_release( $source_fence );
		return $attachment_handle;
	}
	$regular_raw_rows = array();
	if ( 'update' === $channel ) {
		$regular_raw_rows = yotm_media_reference_raw_postmeta_rows( $attachment_id, $original_key );
		if ( is_wp_error( $regular_raw_rows ) ) {
			yotm_media_attachment_lock_release( $attachment_handle );
			yotm_media_source_fence_release( $source_fence );
			return $regular_raw_rows;
		}
		$had_meta = ! empty( $regular_raw_rows );
	}
	$aliases = $defer_proposed
		? yotm_media_source_aliases( $attachment_id )
		: yotm_media_source_proposed_aliases( $attachment_id, $original_key, $effective_key, $meta_value );
	if ( is_wp_error( $aliases ) ) {
		yotm_media_attachment_lock_release( $attachment_handle );
		yotm_media_source_fence_release( $source_fence );
		return $aliases;
	}
	$handles = yotm_media_path_lock_aliases( $aliases );
	if ( is_wp_error( $handles ) ) {
		yotm_media_attachment_lock_release( $attachment_handle );
		yotm_media_source_fence_release( $source_fence );
		return $handles;
	}

	$upserted = yotm_media_source_upsert_aliases( $aliases );
	if ( is_wp_error( $upserted ) ) {
		foreach ( array_reverse( $handles ) as $handle ) {
			yotm_media_path_lock_release( $handle );
		}
		yotm_media_attachment_lock_release( $attachment_handle );
		yotm_media_source_fence_release( $source_fence );
		return $upserted;
	}
	$frame_id = wp_generate_uuid4();
	$marked   = yotm_media_source_dirty_mark( $frame_id, $attachment_id );
	if ( is_wp_error( $marked ) ) {
		foreach ( array_reverse( $handles ) as $handle ) {
			yotm_media_path_lock_release( $handle );
		}
		yotm_media_attachment_lock_release( $attachment_handle );
		yotm_media_source_fence_release( $source_fence );
		return $marked;
	}

	if ( ! isset( $GLOBALS['yotm_media_source_frames'] ) || ! is_array( $GLOBALS['yotm_media_source_frames'] ) ) {
		$GLOBALS['yotm_media_source_frames'] = array();
	}
	$fallback_parent_id = '';
	if ( 'add' === $channel ) {
		for ( $index = count( $GLOBALS['yotm_media_source_frames'] ) - 1; $index >= 0; --$index ) {
			$parent = $GLOBALS['yotm_media_source_frames'][ $index ];
			if (
				'update' === $parent['channel']
				&& ! empty( $parent['ready'] )
				&& empty( $parent['had_meta'] )
				&& (int) $parent['attachment_id'] === (int) $attachment_id
				&& $parent['effective_key'] === (string) $effective_key
			) {
				$fallback_parent_id = $parent['id'];
				break;
			}
		}
	}
	$GLOBALS['yotm_media_source_frames'][] = array(
		'id'                 => $frame_id,
		'channel'            => sanitize_key( $channel ),
		'meta_id'            => absint( $meta_id ),
		'attachment_id'      => absint( $attachment_id ),
		'original_key'       => (string) $original_key,
		'effective_key'      => (string) $effective_key,
		'handles'            => $handles,
		'attachment_handle'  => $attachment_handle,
		'source_fence'       => $source_fence,
		'protected_aliases'  => $aliases,
		'had_meta'           => (bool) $had_meta,
		'ready'              => false,
		'completion_seen'    => false,
		'fallback_parent_id' => $fallback_parent_id,
		'raw_row_snapshot'   => array(),
		'raw_rows_snapshot'  => array(),
		'regular_raw_rows'   => $regular_raw_rows,
	);

	return $frame_id;
}

/**
 * Track one filter invocation so the tail callback marks the exact frame.
 *
 * @param string $channel Mutation channel.
 * @param string $frame_id Optional guarded frame ID.
 * @return void
 */
function yotm_media_source_push_guard_invocation( $channel, $frame_id ) {
	$channel = sanitize_key( $channel );
	if ( ! isset( $GLOBALS['yotm_media_source_invocations'] ) || ! is_array( $GLOBALS['yotm_media_source_invocations'] ) ) {
		$GLOBALS['yotm_media_source_invocations'] = array();
	}
	if ( ! isset( $GLOBALS['yotm_media_source_invocations'][ $channel ] ) ) {
		$GLOBALS['yotm_media_source_invocations'][ $channel ] = array();
	}
	$GLOBALS['yotm_media_source_invocations'][ $channel ][] = (string) $frame_id;
}

/**
 * Mark the exact frame that reached the end of its Core pre-write filter.
 *
 * @param mixed  $check Final short-circuit value seen by this tail callback.
 * @param string $channel Mutation channel.
 * @return mixed
 */
function yotm_media_source_finalize_guard_filter( $check, $channel ) {
	$channel  = sanitize_key( $channel );
	$stack    = $GLOBALS['yotm_media_source_invocations'][ $channel ] ?? array();
	$frame_id = ! empty( $stack ) ? array_pop( $stack ) : '';
	if ( empty( $stack ) ) {
		unset( $GLOBALS['yotm_media_source_invocations'][ $channel ] );
	} else {
		$GLOBALS['yotm_media_source_invocations'][ $channel ] = $stack;
	}

	if ( '' !== $frame_id && ! empty( $GLOBALS['yotm_media_source_frames'] ) ) {
		foreach ( $GLOBALS['yotm_media_source_frames'] as &$frame ) {
			if ( $frame['id'] === $frame_id ) {
				$frame['ready'] = null === $check;
				break;
			}
		}
		unset( $frame );
	}

	return $check;
}

/**
 * Guard Core add_metadata().
 *
 * @param mixed  $check Short-circuit value.
 * @param int    $object_id Post ID.
 * @param string $meta_key Meta key.
 * @param mixed  $meta_value Proposed value.
 * @param bool   $unique Whether the key is unique.
 * @return mixed
 */
function yotm_guard_add_post_metadata( $check, $object_id, $meta_key, $meta_value, $unique ) {
	unset( $unique );
	return yotm_media_source_guard_filter( $check, 'add', $object_id, '', $meta_key, $meta_value, true );
}

/**
 * Guard Core update_metadata().
 *
 * @param mixed  $check Short-circuit value.
 * @param int    $object_id Post ID.
 * @param string $meta_key Meta key.
 * @param mixed  $meta_value Proposed value.
 * @param mixed  $prev_value Previous value constraint.
 * @return mixed
 */
function yotm_guard_update_post_metadata( $check, $object_id, $meta_key, $meta_value, $prev_value ) {
	$protected_keys = array( '_wp_attached_file', '_wp_attachment_metadata', '_wp_attachment_backup_sizes' );
	if (
		null === $check
		&& yotm_media_source_guard_enabled()
		&& in_array( $meta_key, $protected_keys, true )
		&& empty( $prev_value )
	) {
		$applicable = yotm_media_reference_is_image_attachment( $object_id );
		if ( is_wp_error( $applicable ) ) {
			$GLOBALS['yotm_media_source_last_error'] = $applicable;
			yotm_media_source_push_guard_invocation( 'update', '' );
			return false;
		}
		$old_rows = $applicable ? yotm_media_reference_raw_postmeta_rows( $object_id, $meta_key ) : array();
		if ( is_wp_error( $old_rows ) ) {
			$GLOBALS['yotm_media_source_last_error'] = $old_rows;
			yotm_media_source_push_guard_invocation( 'update', '' );
			return false;
		}
		if ( $applicable && 1 === count( $old_rows ) && $old_rows[0]['value'] === $meta_value ) {
			yotm_media_source_push_guard_invocation( 'update', '' );
			return $check;
		}
	}

	return yotm_media_source_guard_filter( $check, 'update', $object_id, $meta_key, $meta_key, $meta_value, true );
}

/**
 * Shared non-by-mid source guard filter.
 *
 * @param mixed  $check Short-circuit value.
 * @param string $channel Mutation channel.
 * @param int    $object_id Post ID.
 * @param string $original_key Original key.
 * @param string $effective_key Effective key.
 * @param mixed  $meta_value Proposed value.
 * @param bool   $had_meta Whether the regular update key existed at guard time.
 * @return mixed
 */
function yotm_media_source_guard_filter( $check, $channel, $object_id, $original_key, $effective_key, $meta_value, $had_meta ) {
	$frame_id      = '';
	$authoritative = array( '_wp_attached_file', '_wp_attachment_metadata', '_wp_attachment_backup_sizes' );
	if ( null !== $check || ! yotm_media_source_guard_enabled() || ( ! in_array( $original_key, $authoritative, true ) && ! in_array( $effective_key, $authoritative, true ) ) ) {
		yotm_media_source_push_guard_invocation( $channel, $frame_id );
		return $check;
	}
	$applicable = yotm_media_reference_is_image_attachment( $object_id );
	if ( is_wp_error( $applicable ) ) {
		$GLOBALS['yotm_media_source_last_error'] = $applicable;
		yotm_media_source_push_guard_invocation( $channel, $frame_id );
		return false;
	}
	if ( ! $applicable ) {
		yotm_media_source_push_guard_invocation( $channel, $frame_id );
		return $check;
	}
	$guarded = yotm_media_source_begin_guard( $channel, $object_id, $original_key, $effective_key, $meta_value, 0, $had_meta );
	if ( is_wp_error( $guarded ) ) {
		$GLOBALS['yotm_media_source_last_error'] = $guarded;
		yotm_media_source_push_guard_invocation( $channel, $frame_id );
		return false;
	}
	$frame_id = $guarded;
	yotm_media_source_push_guard_invocation( $channel, $frame_id );
	return null;
}

/**
 * Tail callback for add_post_metadata.
 *
 * @param mixed  $check Short-circuit value.
 * @param int    $object_id Post ID.
 * @param string $meta_key Meta key.
 * @param mixed  $meta_value Proposed value.
 * @param bool   $unique Whether the key is unique.
 * @return mixed
 */
function yotm_finalize_add_post_metadata_guard( $check, $object_id, $meta_key, $meta_value, $unique ) {
	unset( $object_id, $meta_key, $meta_value, $unique );
	return yotm_media_source_finalize_guard_filter( $check, 'add' );
}

/**
 * Tail callback for update_post_metadata.
 *
 * @param mixed  $check Short-circuit value.
 * @param int    $object_id Post ID.
 * @param string $meta_key Meta key.
 * @param mixed  $meta_value Proposed value.
 * @param mixed  $prev_value Previous value constraint.
 * @return mixed
 */
function yotm_finalize_update_post_metadata_guard( $check, $object_id, $meta_key, $meta_value, $prev_value ) {
	unset( $object_id, $meta_key, $meta_value, $prev_value );
	return yotm_media_source_finalize_guard_filter( $check, 'update' );
}

/**
 * Tail callback for update_post_metadata_by_mid.
 *
 * @param mixed        $check Short-circuit value.
 * @param int          $meta_id Meta row ID.
 * @param mixed        $meta_value Proposed value.
 * @param string|false $meta_key Optional replacement key.
 * @return mixed
 */
function yotm_finalize_update_post_metadata_by_mid_guard( $check, $meta_id, $meta_value, $meta_key ) {
	unset( $meta_id, $meta_value, $meta_key );
	return yotm_media_source_finalize_guard_filter( $check, 'by_mid' );
}

/**
 * Guard Core delete_metadata() for protected attachment reference keys.
 *
 * @param mixed  $check Short-circuit value.
 * @param int    $object_id Object ID.
 * @param string $meta_key Metadata key.
 * @param mixed  $meta_value Optional exact value.
 * @param bool   $delete_all Whether Core requested a global delete.
 * @return mixed
 */
function yotm_guard_delete_post_metadata( $check, $object_id, $meta_key, $meta_value, $delete_all ) {
	global $wpdb;

	$frame_id       = '';
	$protected_keys = array( '_wp_attached_file', '_wp_attachment_metadata', '_wp_attachment_backup_sizes' );
	if ( null !== $check || ! yotm_media_source_guard_enabled() || ! in_array( $meta_key, $protected_keys, true ) ) {
		yotm_media_source_push_guard_invocation( 'delete', $frame_id );
		return $check;
	}
	if ( $delete_all ) {
		$GLOBALS['yotm_media_source_last_error'] = new WP_Error( 'yotm_media_source_delete_all', __( 'Site-wide deletion of protected attachment metadata is not allowed.', 'thumbnail-manager' ) );
		yotm_media_source_push_guard_invocation( 'delete', $frame_id );
		return false;
	}
	$applicable = yotm_media_reference_is_image_attachment( $object_id );
	if ( is_wp_error( $applicable ) ) {
		$GLOBALS['yotm_media_source_last_error'] = $applicable;
		yotm_media_source_push_guard_invocation( 'delete', $frame_id );
		return false;
	}
	if ( ! $applicable ) {
		yotm_media_source_push_guard_invocation( 'delete', $frame_id );
		return $check;
	}

	if ( '' !== $meta_value && null !== $meta_value && false !== $meta_value ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Guard needs exact rows matching Core's delete predicate.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT meta_id,post_id,meta_key,meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s AND meta_value = %s ORDER BY meta_id ASC",
				absint( $object_id ),
				(string) $meta_key,
				maybe_serialize( $meta_value )
			),
			ARRAY_A
		);
	} else {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Guard needs exact rows matching Core's delete predicate.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT meta_id,post_id,meta_key,meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s ORDER BY meta_id ASC",
				absint( $object_id ),
				(string) $meta_key
			),
			ARRAY_A
		);
	}//end if
	if ( empty( $rows ) ) {
		yotm_media_source_push_guard_invocation( 'delete', $frame_id );
		return $check;
	}

	$guarded = yotm_media_source_begin_guard( 'delete', $object_id, $meta_key, '', null, 0, true, true );
	if ( is_wp_error( $guarded ) ) {
		$GLOBALS['yotm_media_source_last_error'] = $guarded;
		yotm_media_source_push_guard_invocation( 'delete', $frame_id );
		return false;
	}
	$frame_id = $guarded;
	foreach ( $GLOBALS['yotm_media_source_frames'] as &$frame ) {
		if ( $frame_id === $frame['id'] ) {
			$frame['raw_rows_snapshot'] = array_values( $rows );
			break;
		}
	}
	unset( $frame );
	yotm_media_source_push_guard_invocation( 'delete', $frame_id );
	return null;
}

/**
 * Tail callback for delete_post_metadata.
 *
 * @param mixed  $check Short-circuit value.
 * @param int    $object_id Object ID.
 * @param string $meta_key Metadata key.
 * @param mixed  $meta_value Optional exact value.
 * @param bool   $delete_all Whether Core requested a global delete.
 * @return mixed
 */
function yotm_finalize_delete_post_metadata_guard( $check, $object_id, $meta_key, $meta_value, $delete_all ) {
	unset( $object_id, $meta_key, $meta_value, $delete_all );
	return yotm_media_source_finalize_guard_filter( $check, 'delete' );
}

/**
 * Guard Core delete_metadata_by_mid() for protected attachment reference keys.
 *
 * @param mixed $check Short-circuit value.
 * @param int   $meta_id Metadata row ID.
 * @return mixed
 */
function yotm_guard_delete_post_metadata_by_mid( $check, $meta_id ) {
	global $wpdb;

	$frame_id = '';
	if ( null !== $check || ! yotm_media_source_guard_enabled() ) {
		yotm_media_source_push_guard_invocation( 'delete_by_mid', $frame_id );
		return $check;
	}
	$meta_id = absint( $meta_id );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Guard needs the exact raw row without running stateful accessors twice.
	$meta = $meta_id ? $wpdb->get_row( $wpdb->prepare( "SELECT meta_id,post_id,meta_key,meta_value FROM {$wpdb->postmeta} WHERE meta_id = %d", $meta_id ), ARRAY_A ) : null;
	if ( ! is_array( $meta ) ) {
		yotm_media_source_push_guard_invocation( 'delete_by_mid', $frame_id );
		return false;
	}
	if ( ! in_array( $meta['meta_key'], array( '_wp_attached_file', '_wp_attachment_metadata', '_wp_attachment_backup_sizes' ), true ) ) {
		yotm_media_source_push_guard_invocation( 'delete_by_mid', $frame_id );
		return $check;
	}
	$applicable = yotm_media_reference_is_image_attachment( (int) $meta['post_id'] );
	if ( is_wp_error( $applicable ) ) {
		$GLOBALS['yotm_media_source_last_error'] = $applicable;
		yotm_media_source_push_guard_invocation( 'delete_by_mid', $frame_id );
		return false;
	}
	if ( ! $applicable ) {
		yotm_media_source_push_guard_invocation( 'delete_by_mid', $frame_id );
		return $check;
	}
	if ( has_filter( 'get_post_metadata_by_mid' ) ) {
		$GLOBALS['yotm_media_source_last_error'] = new WP_Error( 'yotm_media_source_by_mid_accessor', __( 'A custom by-meta-ID accessor prevents a safe authoritative metadata deletion.', 'thumbnail-manager' ) );
		yotm_media_source_push_guard_invocation( 'delete_by_mid', $frame_id );
		return false;
	}

	$guarded = yotm_media_source_begin_guard( 'delete_by_mid', (int) $meta['post_id'], (string) $meta['meta_key'], '', null, $meta_id, true, true );
	if ( is_wp_error( $guarded ) ) {
		$GLOBALS['yotm_media_source_last_error'] = $guarded;
		yotm_media_source_push_guard_invocation( 'delete_by_mid', $frame_id );
		return false;
	}
	$frame_id = $guarded;
	foreach ( $GLOBALS['yotm_media_source_frames'] as &$frame ) {
		if ( $frame_id === $frame['id'] ) {
			$frame['raw_row_snapshot'] = array_map( 'strval', $meta );
			break;
		}
	}
	unset( $frame );
	yotm_media_source_push_guard_invocation( 'delete_by_mid', $frame_id );
	return null;
}

/**
 * Tail callback for delete_post_metadata_by_mid.
 *
 * @param mixed $check Short-circuit value.
 * @param int   $meta_id Metadata row ID.
 * @return mixed
 */
function yotm_finalize_delete_post_metadata_by_mid_guard( $check, $meta_id ) {
	unset( $meta_id );
	return yotm_media_source_finalize_guard_filter( $check, 'delete_by_mid' );
}

/**
 * Guard Core update_metadata_by_mid().
 *
 * @param mixed        $check Short-circuit value.
 * @param int          $meta_id Meta row ID.
 * @param mixed        $meta_value Proposed value.
 * @param string|false $meta_key Optional replacement key.
 * @return mixed
 */
function yotm_guard_update_post_metadata_by_mid( $check, $meta_id, $meta_value, $meta_key ) {
	global $wpdb;

	$frame_id = '';
	if ( null !== $check || ! yotm_media_source_guard_enabled() ) {
		yotm_media_source_push_guard_invocation( 'by_mid', $frame_id );
		return $check;
	}

	$meta_id = absint( $meta_id );
	if ( ! $meta_id ) {
		yotm_media_source_push_guard_invocation( 'by_mid', $frame_id );
		return false;
	}
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Guard needs the exact raw row without running stateful by-mid accessors twice.
	$meta = $wpdb->get_row( $wpdb->prepare( "SELECT meta_id,post_id,meta_key,meta_value FROM {$wpdb->postmeta} WHERE meta_id = %d", $meta_id ), ARRAY_A );
	if ( ! is_array( $meta ) || empty( $meta['post_id'] ) || ! isset( $meta['meta_key'], $meta['meta_value'] ) ) {
		yotm_media_source_push_guard_invocation( 'by_mid', $frame_id );
		return false;
	}
	if ( false === $meta_key ) {
		$effective_key = (string) $meta['meta_key'];
	} elseif ( is_string( $meta_key ) ) {
		$effective_key = $meta_key;
	} else {
		yotm_media_source_push_guard_invocation( 'by_mid', $frame_id );
		return false;
	}

	$protected_keys = array( '_wp_attached_file', '_wp_attachment_metadata', '_wp_attachment_backup_sizes' );
	if ( ! in_array( (string) $meta['meta_key'], $protected_keys, true ) && ! in_array( $effective_key, $protected_keys, true ) ) {
		yotm_media_source_push_guard_invocation( 'by_mid', $frame_id );
		return $check;
	}
	$applicable = yotm_media_reference_is_image_attachment( (int) $meta['post_id'] );
	if ( is_wp_error( $applicable ) ) {
		$GLOBALS['yotm_media_source_last_error'] = $applicable;
		yotm_media_source_push_guard_invocation( 'by_mid', $frame_id );
		return false;
	}
	if ( ! $applicable ) {
		yotm_media_source_push_guard_invocation( 'by_mid', $frame_id );
		return $check;
	}
	if ( has_filter( 'get_post_metadata_by_mid' ) ) {
		$GLOBALS['yotm_media_source_last_error'] = new WP_Error( 'yotm_media_source_by_mid_accessor', __( 'A custom by-meta-ID accessor prevents a safe authoritative metadata update.', 'thumbnail-manager' ) );
		yotm_media_source_push_guard_invocation( 'by_mid', $frame_id );
		return false;
	}

	$guarded = yotm_media_source_begin_guard( 'by_mid', (int) $meta['post_id'], (string) $meta['meta_key'], $effective_key, $meta_value, $meta_id, true, true );
	if ( is_wp_error( $guarded ) ) {
		$GLOBALS['yotm_media_source_last_error'] = $guarded;
		yotm_media_source_push_guard_invocation( 'by_mid', $frame_id );
		return false;
	}
	$frame_id = $guarded;
	if ( '' !== $frame_id ) {
		foreach ( $GLOBALS['yotm_media_source_frames'] as &$frame ) {
			if ( $frame_id === $frame['id'] ) {
				$frame['raw_row_snapshot'] = array(
					'meta_id'    => (string) $meta['meta_id'],
					'post_id'    => (string) $meta['post_id'],
					'meta_key'   => (string) $meta['meta_key'],
					'meta_value' => (string) $meta['meta_value'],
				);
				break;
			}
		}
		unset( $frame );
	}
	yotm_media_source_push_guard_invocation( 'by_mid', $frame_id );
	return null;
}

/**
 * Release one exact metadata guard frame without recomputing source state.
 *
 * @param string $frame_id Frame ID.
 * @return void
 */
function yotm_media_source_release_guard_frame( $frame_id ) {
	if ( empty( $GLOBALS['yotm_media_source_frames'] ) ) {
		return;
	}
	foreach ( $GLOBALS['yotm_media_source_frames'] as $index => $frame ) {
		if ( $frame['id'] !== $frame_id ) {
			continue;
		}
		foreach ( array_reverse( (array) $frame['handles'] ) as $handle ) {
			yotm_media_path_lock_release( $handle );
		}
		yotm_media_attachment_lock_release( $frame['attachment_handle'] ?? array() );
		yotm_media_source_fence_release( $frame['source_fence'] ?? array() );
		array_splice( $GLOBALS['yotm_media_source_frames'], $index, 1 );
		return;
	}
}

/**
 * Complete one exact metadata guard frame after Core writes successfully.
 *
 * @param string $event Modern Core action channel: add or update.
 * @param int    $meta_id Meta row ID.
 * @param int    $object_id Post ID.
 * @param string $meta_key Effective key.
 */
function yotm_media_source_complete_meta_guard( $event, $meta_id, $object_id, $meta_key ) {
	if ( empty( $GLOBALS['yotm_media_source_frames'] ) ) {
		return;
	}

	for ( $index = count( $GLOBALS['yotm_media_source_frames'] ) - 1; $index >= 0; --$index ) {
		$frame = $GLOBALS['yotm_media_source_frames'][ $index ];
		if ( empty( $frame['ready'] ) ) {
			continue;
		}
		if ( 'add' === $event && 'add' !== $frame['channel'] ) {
			continue;
		}
		if ( 'update' === $event && ! in_array( $frame['channel'], array( 'update', 'by_mid' ), true ) ) {
			continue;
		}
		if ( 'delete' === $event && ! in_array( $frame['channel'], array( 'delete', 'delete_by_mid' ), true ) ) {
			continue;
		}
		$frame_key = 'delete' === $event ? $frame['original_key'] : $frame['effective_key'];
		if ( (int) $frame['attachment_id'] !== (int) $object_id || $frame_key !== (string) $meta_key ) {
			continue;
		}
		if ( in_array( $frame['channel'], array( 'by_mid', 'delete_by_mid' ), true ) && (int) $frame['meta_id'] !== (int) $meta_id ) {
			continue;
		}

		$GLOBALS['yotm_media_source_frames'][ $index ]['completion_seen'] = true;
		$synced = yotm_media_source_sync_attachment( $object_id );
		if ( is_wp_error( $synced ) ) {
			$GLOBALS['yotm_media_source_last_error'] = $synced;
			return;
		}
		$frame_id  = $frame['id'];
		$parent_id = (string) ( $frame['fallback_parent_id'] ?? '' );
		$tokens    = array( $frame_id );
		if ( '' !== $parent_id ) {
			$tokens[] = $parent_id;
		}
		$cleared = yotm_media_source_dirty_clear_tokens( $tokens );
		if ( is_wp_error( $cleared ) ) {
			$GLOBALS['yotm_media_source_last_error'] = $cleared;
			return;
		}
		yotm_media_source_release_guard_frame( $frame_id );
		if ( '' !== $parent_id ) {
			yotm_media_source_release_guard_frame( $parent_id );
		}
		return;
	}//end for
}

/**
 * Modern added_post_meta completion wrapper.
 *
 * @param int    $meta_id Meta row ID.
 * @param int    $object_id Post ID.
 * @param string $meta_key Meta key.
 * @param mixed  $meta_value Added value.
 */
function yotm_media_source_complete_added_meta_guard( $meta_id, $object_id, $meta_key, $meta_value ) {
	unset( $meta_value );
	yotm_media_source_complete_meta_guard( 'add', $meta_id, $object_id, $meta_key );
}

/**
 * Modern updated_post_meta completion wrapper.
 *
 * @param int    $meta_id Meta row ID.
 * @param int    $object_id Post ID.
 * @param string $meta_key Meta key.
 * @param mixed  $meta_value Updated value.
 */
function yotm_media_source_complete_updated_meta_guard( $meta_id, $object_id, $meta_key, $meta_value ) {
	unset( $meta_value );
	yotm_media_source_complete_meta_guard( 'update', $meta_id, $object_id, $meta_key );
}

/**
 * Resynchronize after authoritative metadata deletion.
 *
 * @param int[]  $meta_ids Deleted meta row IDs.
 * @param int    $object_id Post ID.
 * @param string $meta_key Deleted key.
 * @param mixed  $meta_value Deleted value.
 */
function yotm_media_source_resync_after_meta_delete( $meta_ids, $object_id, $meta_key, $meta_value ) {
	unset( $meta_value );
	if ( yotm_media_source_guard_enabled() && in_array( $meta_key, array( '_wp_attached_file', '_wp_attachment_metadata', '_wp_attachment_backup_sizes' ), true ) ) {
		$meta_id = ! empty( $meta_ids ) ? absint( reset( $meta_ids ) ) : 0;
		yotm_media_source_complete_meta_guard( 'delete', $meta_id, $object_id, $meta_key );
	}
}

/**
 * Remove source rows after attachment deletion.
 *
 * @param int          $attachment_id Attachment ID.
 * @param WP_Post|null $post Deleted post object supplied by Core.
 */
function yotm_media_source_delete_attachment_rows( $attachment_id, $post = null ) {
	if ( ! yotm_media_source_guard_enabled() || ! $post instanceof WP_Post || 'attachment' !== $post->post_type ) {
		return;
	}

	$source_fence = yotm_media_source_fence_acquire();
	if ( is_wp_error( $source_fence ) ) {
		$GLOBALS['yotm_media_source_last_error'] = $source_fence;
		return;
	}

	try {
		$deleted = yotm_media_source_store_delete_attachment( $attachment_id );
		if ( is_wp_error( $deleted ) ) {
			$GLOBALS['yotm_media_source_last_error'] = $deleted;
			return;
		}

		$cleared = yotm_media_source_dirty_clear_attachment( $attachment_id );
		if ( is_wp_error( $cleared ) ) {
			$GLOBALS['yotm_media_source_last_error'] = $cleared;
		}
	} finally {
		yotm_media_source_fence_release( $source_fence );
	}
}

/** Release unmatched guard frames and locks exactly once at shutdown. */
function yotm_media_source_shutdown_cleanup() {
	if ( ! empty( $GLOBALS['yotm_media_source_frames'] ) ) {
		$unwritten_tokens = array();
		foreach ( $GLOBALS['yotm_media_source_frames'] as $frame ) {
			if ( empty( $frame['ready'] ) && ! empty( $frame['id'] ) ) {
				$unwritten_tokens[] = $frame['id'];
			} elseif ( in_array( $frame['channel'] ?? '', array( 'by_mid', 'delete_by_mid' ), true ) && ! empty( $frame['id'] ) && yotm_media_source_reconcile_by_mid_frame( $frame ) ) {
				$unwritten_tokens[] = $frame['id'];
			} elseif ( 'delete' === ( $frame['channel'] ?? '' ) && ! empty( $frame['id'] ) && yotm_media_source_reconcile_delete_frame( $frame ) ) {
				$unwritten_tokens[] = $frame['id'];
			} elseif ( 'update' === ( $frame['channel'] ?? '' ) && ! empty( $frame['id'] ) && yotm_media_source_reconcile_regular_update_frame( $frame ) ) {
				$unwritten_tokens[] = $frame['id'];
			}
		}
		if ( ! empty( $unwritten_tokens ) ) {
			$cleared = yotm_media_source_dirty_clear_tokens( $unwritten_tokens );
			if ( is_wp_error( $cleared ) ) {
				$GLOBALS['yotm_media_source_last_error'] = $cleared;
			}
		}
		foreach ( array_reverse( $GLOBALS['yotm_media_source_frames'] ) as $frame ) {
			foreach ( array_reverse( (array) $frame['handles'] ) as $handle ) {
				yotm_media_path_lock_release( $handle );
			}
			yotm_media_attachment_lock_release( $frame['attachment_handle'] ?? array() );
			yotm_media_source_fence_release( $frame['source_fence'] ?? array() );
		}
		$GLOBALS['yotm_media_source_frames'] = array();
	}//end if
	$GLOBALS['yotm_media_source_invocations'] = array();
	if ( ! empty( $GLOBALS['yotm_media_path_locks'] ) ) {
		foreach ( array_keys( $GLOBALS['yotm_media_path_locks'] ) as $name ) {
			yotm_database_release_named_lock( $name );
		}
		$GLOBALS['yotm_media_path_locks'] = array();
	}
	if ( ! empty( $GLOBALS['yotm_media_attachment_locks'] ) ) {
		foreach ( array_keys( $GLOBALS['yotm_media_attachment_locks'] ) as $name ) {
			yotm_database_release_named_lock( $name );
		}
		$GLOBALS['yotm_media_attachment_locks'] = array();
	}
	if ( ! empty( $GLOBALS['yotm_media_source_fence_locks'] ) ) {
		foreach ( array_keys( $GLOBALS['yotm_media_source_fence_locks'] ) as $name ) {
			yotm_database_release_named_lock( $name );
		}
		$GLOBALS['yotm_media_source_fence_locks'] = array();
	}
}

/**
 * Reconcile a by-meta-ID frame which reached Core but emitted no completion action.
 *
 * The frame still owns the source fence and attachment lock. An unchanged raw
 * row proves no write; a changed/missing row is clean only after a live sync.
 *
 * @param array $frame Guard frame.
 * @return bool Whether the exact dirty token may be cleared.
 */
function yotm_media_source_reconcile_by_mid_frame( $frame ) {
	global $wpdb;
	if ( ! empty( $frame['completion_seen'] ) ) {
		return false;
	}

	$snapshot = $frame['raw_row_snapshot'] ?? array();
	$meta_id  = absint( $snapshot['meta_id'] ?? 0 );
	if ( ! $meta_id || ! isset( $snapshot['post_id'], $snapshot['meta_key'], $snapshot['meta_value'] ) ) {
		return false;
	}

	$wpdb->last_error = '';
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Exact raw reconciliation cannot use filtered/cached metadata.
	$current = $wpdb->get_row( $wpdb->prepare( "SELECT meta_id,post_id,meta_key,meta_value FROM {$wpdb->postmeta} WHERE meta_id = %d", $meta_id ), ARRAY_A );
	if ( '' !== (string) $wpdb->last_error ) {
		return false;
	}

	$normalized = is_array( $current )
		? array(
			'meta_id'    => (string) $current['meta_id'],
			'post_id'    => (string) $current['post_id'],
			'meta_key'   => (string) $current['meta_key'],
			'meta_value' => (string) $current['meta_value'],
		)
		: array();
	if ( $snapshot === $normalized ) {
		return true;
	}

	$synced = yotm_media_source_sync_attachment( absint( $frame['attachment_id'] ?? 0 ) );
	if ( is_wp_error( $synced ) ) {
		$GLOBALS['yotm_media_source_last_error'] = $synced;
		return false;
	}

	return true;
}

/**
 * Reconcile an object-scoped delete which emitted no completion action.
 *
 * @param array $frame Guard frame.
 * @return bool
 */
function yotm_media_source_reconcile_delete_frame( $frame ) {
	global $wpdb;
	if ( ! empty( $frame['completion_seen'] ) ) {
		return false;
	}

	$snapshot = is_array( $frame['raw_rows_snapshot'] ?? null ) ? $frame['raw_rows_snapshot'] : array();
	if ( empty( $snapshot ) ) {
		return false;
	}
	$ids = array_map(
		static function ( $row ) {
			return absint( $row['meta_id'] ?? 0 );
		},
		$snapshot
	);
	$ids = array_values( array_filter( $ids ) );
	if ( count( $ids ) !== count( $snapshot ) ) {
		return false;
	}

	$placeholders     = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
	$wpdb->last_error = '';
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared -- Exact raw reconciliation uses a generated placeholder list.
	$current = $wpdb->get_results( $wpdb->prepare( "SELECT meta_id,post_id,meta_key,meta_value FROM {$wpdb->postmeta} WHERE meta_id IN ({$placeholders}) ORDER BY meta_id ASC", $ids ), ARRAY_A );
	if ( '' !== (string) $wpdb->last_error ) {
		return false;
	}

	$normalize = static function ( $rows ) {
		$result = array();
		foreach ( $rows as $row ) {
			$result[] = array(
				'meta_id'    => (string) $row['meta_id'],
				'post_id'    => (string) $row['post_id'],
				'meta_key'   => (string) $row['meta_key'],
				'meta_value' => (string) $row['meta_value'],
			);
		}
		return $result;
	};
	if ( $normalize( $snapshot ) === $normalize( $current ) ) {
		return true;
	}

	$synced = yotm_media_source_sync_attachment( absint( $frame['attachment_id'] ?? 0 ) );
	if ( is_wp_error( $synced ) ) {
		$GLOBALS['yotm_media_source_last_error'] = $synced;
		return false;
	}

	return true;
}

/**
 * Reconcile a regular update which reached Core but emitted no completion action.
 *
 * @param array $frame Guard frame.
 * @return bool Whether the exact dirty token may be cleared.
 */
function yotm_media_source_reconcile_regular_update_frame( $frame ) {
	if ( ! empty( $frame['completion_seen'] ) ) {
		return false;
	}
	$snapshot      = $frame['regular_raw_rows'] ?? null;
	$attachment_id = absint( $frame['attachment_id'] ?? 0 );
	$meta_key      = (string) ( $frame['original_key'] ?? '' );
	if ( ! is_array( $snapshot ) || ! $attachment_id || '' === $meta_key ) {
		return false;
	}

	$current = yotm_media_reference_raw_postmeta_rows( $attachment_id, $meta_key );
	if ( is_wp_error( $current ) ) {
		$GLOBALS['yotm_media_source_last_error'] = $current;
		return false;
	}
	$normalize = static function ( $rows ) {
		$result = array();
		foreach ( $rows as $row ) {
			$result[] = array(
				'meta_id'   => absint( $row['meta_id'] ?? 0 ),
				'raw_value' => (string) ( $row['raw_value'] ?? '' ),
			);
		}
		return $result;
	};
	if ( $normalize( $snapshot ) === $normalize( $current ) ) {
		return true;
	}

	$synced = yotm_media_source_sync_attachment( $attachment_id );
	if ( is_wp_error( $synced ) ) {
		$GLOBALS['yotm_media_source_last_error'] = $synced;
		return false;
	}

	return true;
}

/**
 * Check whether a canonical path is currently authoritative for any attachment.
 *
 * @param string $path Candidate path.
 * @param int    $limit Maximum aliases to inspect.
 * @return bool|WP_Error
 */
function yotm_media_source_path_is_authoritative( $path, $limit = YOTM_MEDIA_SOURCE_FANOUT_LIMIT ) {
	$owners = yotm_media_reference_path_owners( $path, $limit );
	if ( is_wp_error( $owners ) ) {
		return $owners;
	}

	return ! empty( $owners['protected'] );
}

/**
 * Return exact live protected and generated references for one canonical path.
 *
 * @param string $path Candidate path.
 * @param int    $limit Maximum indexed rows to inspect.
 * @return array{path:string,protected:array,generated:array}|WP_Error
 */
function yotm_media_reference_path_owners( $path, $limit = YOTM_MEDIA_SOURCE_FANOUT_LIMIT ) {
	$canonical = yotm_media_source_canonical_path( $path );
	if ( is_wp_error( $canonical ) ) {
		return $canonical;
	}
	$clean = yotm_media_reference_require_complete_index();
	if ( is_wp_error( $clean ) ) {
		return $clean;
	}
	$hash  = hash( 'sha256', $canonical );
	$limit = max( 1, absint( $limit ) );
	$rows  = yotm_media_source_store_path_rows( $hash, $limit );
	if ( is_wp_error( $rows ) ) {
		return $rows;
	}
	if ( count( $rows ) > $limit ) {
		return new WP_Error( 'yotm_media_source_fanout', __( 'Too many authoritative media aliases matched this path.', 'thumbnail-manager' ) );
	}

	$indexed = array();
	$ids     = array();
	foreach ( $rows as $row ) {
		if ( hash_equals( $canonical, (string) $row->path ) ) {
			$attachment_id                           = (int) $row->attachment_id;
			$kind                                    = sanitize_key( $row->source_kind );
			$ids[ $attachment_id ]                   = $attachment_id;
			$indexed[ $attachment_id . ':' . $kind ] = true;
		}
	}

	$protected = array();
	$generated = array();
	foreach ( $ids as $attachment_id ) {
		$aliases = yotm_media_source_aliases( $attachment_id );
		if ( is_wp_error( $aliases ) ) {
			return $aliases;
		}
		$live_kinds = array();
		foreach ( $aliases as $alias ) {
			if ( ! hash_equals( $canonical, $alias['path'] ) ) {
				continue;
			}
			$kind                = sanitize_key( $alias['source_kind'] );
			$live_kinds[ $kind ] = true;
			if ( 'generated' !== $kind ) {
				if ( ! in_array( $kind, YOTM_MEDIA_REFERENCE_PROTECTED_KINDS, true ) ) {
					return new WP_Error( 'yotm_media_reference_kind_unknown', __( 'The media reference index contains an unknown reference kind.', 'thumbnail-manager' ) );
				}
				$protected[ $attachment_id . ':' . $kind ] = array(
					'attachment_id' => $attachment_id,
					'kind'          => $kind,
					'path'          => $canonical,
				);
			}
		}
		foreach ( $live_kinds as $kind => $unused ) {
			unset( $unused );
			if ( empty( $indexed[ $attachment_id . ':' . $kind ] ) ) {
				return new WP_Error( 'yotm_media_reference_index_stale', __( 'The media reference index does not match live attachment metadata.', 'thumbnail-manager' ) );
			}
		}
		foreach ( $indexed as $indexed_key => $unused ) {
			unset( $unused );
			if ( 0 === strpos( $indexed_key, $attachment_id . ':' ) ) {
				$kind = substr( $indexed_key, strlen( $attachment_id . ':' ) );
				if ( empty( $live_kinds[ $kind ] ) ) {
					return new WP_Error( 'yotm_media_reference_index_stale', __( 'The media reference index does not match live attachment metadata.', 'thumbnail-manager' ) );
				}
			}
		}

		if ( ! empty( $live_kinds['generated'] ) ) {
			$metadata_rows = yotm_media_reference_raw_postmeta_rows( $attachment_id, '_wp_attachment_metadata' );
			if ( is_wp_error( $metadata_rows ) ) {
				return $metadata_rows;
			}
			foreach ( $metadata_rows as $stored_row ) {
				$metadata = $stored_row['value'];
				if ( ! is_array( $metadata ) || empty( $metadata['file'] ) || ! is_string( $metadata['file'] ) || ! is_array( $metadata['sizes'] ?? null ) ) {
					return new WP_Error( 'yotm_media_generated_state_unresolved', __( 'Attachment generated-file state could not be resolved.', 'thumbnail-manager' ) );
				}
				$uploads = wp_get_upload_dir();
				$dir     = dirname( trailingslashit( (string) $uploads['basedir'] ) . ltrim( $metadata['file'], '/\\' ) );
				foreach ( $metadata['sizes'] as $size_name => $size_data ) {
					$filename = is_array( $size_data ) ? ( $size_data['file'] ?? ( $size_data['filename'] ?? '' ) ) : '';
					if ( ! is_string( $filename ) || '' === $filename ) {
						return new WP_Error( 'yotm_media_generated_state_unresolved', __( 'Attachment generated-file state could not be resolved.', 'thumbnail-manager' ) );
					}
					$size_path = yotm_media_source_canonical_path( trailingslashit( $dir ) . $filename );
					if ( ! is_wp_error( $size_path ) && hash_equals( $canonical, $size_path ) ) {
						$key               = $attachment_id . ':' . sanitize_key( $size_name ) . ':' . wp_basename( $filename );
						$generated[ $key ] = array(
							'attachment_id' => $attachment_id,
							'size'          => sanitize_key( $size_name ),
							'filename'      => wp_basename( $filename ),
							'path'          => $canonical,
						);
					}
				}
			}//end foreach
		}//end if
	}//end foreach

	ksort( $protected );
	ksort( $generated );
	return array(
		'path'      => $canonical,
		'protected' => array_values( $protected ),
		'generated' => array_values( $generated ),
	);
}
