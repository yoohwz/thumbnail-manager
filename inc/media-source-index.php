<?php
/**
 * Authoritative attachment-source index and path-scoped safety locks.
 *
 * @package Thumbnail_Manager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'YOTM_MEDIA_SOURCE_FANOUT_LIMIT' ) ) {
	define( 'YOTM_MEDIA_SOURCE_FANOUT_LIMIT', 100 );
}

add_filter( 'add_post_metadata', 'yotm_guard_add_post_metadata', PHP_INT_MIN, 5 );
add_filter( 'update_post_metadata', 'yotm_guard_update_post_metadata', PHP_INT_MIN, 5 );
add_filter( 'update_post_metadata_by_mid', 'yotm_guard_update_post_metadata_by_mid', PHP_INT_MIN, 4 );
add_action( 'added_post_meta', 'yotm_media_source_complete_meta_guard', PHP_INT_MIN, 4 );
add_action( 'updated_post_meta', 'yotm_media_source_complete_meta_guard', PHP_INT_MIN, 4 );
add_action( 'updated_postmeta', 'yotm_media_source_complete_legacy_meta_guard', PHP_INT_MIN, 4 );
add_action( 'deleted_post_meta', 'yotm_media_source_resync_after_meta_delete', 10, 4 );
add_action( 'delete_attachment', 'yotm_media_source_delete_attachment_rows', 10, 1 );

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
 * Resolve a path to a canonical, uploads-contained path.
 *
 * @param string $path Absolute or uploads-relative path.
 * @return string|WP_Error
 */
function yotm_media_source_canonical_path( $path ) {
	$uploads = wp_get_upload_dir();
	$base    = realpath( (string) ( $uploads['basedir'] ?? '' ) );
	$path    = str_replace( '\\', '/', (string) $path );

	if ( false === $base || '' === $path || false !== strpos( $path, "\0" ) ) {
		return new WP_Error( 'yotm_media_source_path_unresolved', __( 'An authoritative media path could not be resolved safely.', 'thumbnail-manager' ) );
	}

	$base = untrailingslashit( wp_normalize_path( $base ) );
	if ( ! preg_match( '#^(?:[A-Za-z]:)?/#', $path ) ) {
		$path = trailingslashit( $base ) . ltrim( $path, '/' );
	}

	$path = wp_normalize_path( $path );
	if ( is_link( $path ) ) {
		return new WP_Error( 'yotm_media_source_symlink', __( 'A symbolic-link media path cannot be used for destructive work.', 'thumbnail-manager' ) );
	}

	$real = realpath( $path );
	if ( false === $real ) {
		$parent = realpath( dirname( $path ) );
		if ( false === $parent || basename( $path ) !== wp_basename( $path ) ) {
			return new WP_Error( 'yotm_media_source_path_unresolved', __( 'An authoritative media path could not be resolved safely.', 'thumbnail-manager' ) );
		}
		$real = trailingslashit( wp_normalize_path( $parent ) ) . wp_basename( $path );
	}

	$real = untrailingslashit( wp_normalize_path( $real ) );
	if ( $real === $base || 0 !== strpos( $real, trailingslashit( $base ) ) ) {
		return new WP_Error( 'yotm_media_source_outside_uploads', __( 'An authoritative media path is outside uploads.', 'thumbnail-manager' ) );
	}

	return $real;
}

/**
 * Resolve an attachment state into authoritative source aliases.
 *
 * @param int         $attachment_id Attachment ID.
 * @param string|null $attached_value Attached-file value, null for live value.
 * @param array|null  $metadata_value Metadata value, null for live value.
 * @return array[]|WP_Error
 */
function yotm_media_source_aliases( $attachment_id, $attached_value = null, $metadata_value = null ) {
	$attachment_id = absint( $attachment_id );
	$uploads       = wp_get_upload_dir();
	$base          = (string) ( $uploads['basedir'] ?? '' );

	if ( ! $attachment_id || '' === $base ) {
		return new WP_Error( 'yotm_media_source_state_unresolved', __( 'Attachment source state could not be resolved.', 'thumbnail-manager' ) );
	}

	$live            = null === $attached_value && null === $metadata_value;
	$attached_values = array();
	$metadata_values = array();
	if ( $live ) {
		$attached_values = array_filter( (array) get_post_meta( $attachment_id, '_wp_attached_file', false ), 'is_string' );
		$filtered_file   = get_attached_file( $attachment_id );
		if ( is_string( $filtered_file ) && '' !== $filtered_file ) {
			$attached_values[] = $filtered_file;
		}
		foreach ( (array) get_post_meta( $attachment_id, '_wp_attachment_metadata', false ) as $stored_metadata ) {
			if ( is_array( $stored_metadata ) ) {
				$metadata_values[] = $stored_metadata;
			}
		}
		$filtered_metadata = wp_get_attachment_metadata( $attachment_id );
		if ( is_array( $filtered_metadata ) ) {
			$metadata_values[] = $filtered_metadata;
		}
	} else {
		$attached_values = is_string( $attached_value ) && '' !== $attached_value ? array( $attached_value ) : array();
		$metadata_values = is_array( $metadata_value ) ? array( $metadata_value ) : array();
	}

	$raw = array();
	foreach ( $attached_values as $value ) {
		if ( '' !== $value ) {
			$raw[] = array(
				'kind' => 'attached',
				'path' => $value,
			);
		}
	}
	foreach ( $metadata_values as $metadata ) {
		if ( ! empty( $metadata['file'] ) && is_string( $metadata['file'] ) ) {
			$raw[] = array(
				'kind' => 'metadata_full',
				'path' => trailingslashit( $base ) . ltrim( $metadata['file'], '/\\' ),
			);
		}
		if ( ! empty( $metadata['original_image'] ) && is_string( $metadata['original_image'] ) ) {
			foreach ( $attached_values as $value ) {
				$attached_path = preg_match( '#^(?:[A-Za-z]:)?/#', $value ) ? $value : trailingslashit( $base ) . ltrim( $value, '/\\' );
				$raw[]         = array(
					'kind' => 'original',
					'path' => trailingslashit( dirname( $attached_path ) ) . wp_basename( $metadata['original_image'] ),
				);
			}
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
 * Insert conservative positive source rows.
 *
 * @param array[] $aliases Source aliases.
 * @return true|WP_Error
 */
function yotm_media_source_upsert_aliases( $aliases ) {
	global $wpdb;

	$table = yotm_job_table_names()['sources'];
	$now   = gmdate( 'Y-m-d H:i:s' );

	foreach ( (array) $aliases as $alias ) {
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Plugin-owned table name is derived from the trusted WordPress prefix; values use placeholders.
		$sql = $wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Plugin-owned table name is trusted; values use placeholders.
			"INSERT INTO {$table} (attachment_id,source_kind,path_hash,path,updated_at)
			VALUES (%d,%s,%s,%s,%s)
			ON DUPLICATE KEY UPDATE path = VALUES(path), updated_at = VALUES(updated_at)",
			absint( $alias['attachment_id'] ?? 0 ),
			sanitize_key( $alias['source_kind'] ?? '' ),
			(string) ( $alias['path_hash'] ?? '' ),
			(string) ( $alias['path'] ?? '' ),
			$now
		);
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Source fencing requires an immediate uncached upsert; prepared above.
		if ( false === $wpdb->query( $sql ) ) {
			return yotm_job_storage_error( 'yotm_job_storage_unavailable', (string) $wpdb->last_error );
		}
	}

	return true;
}

/**
 * Replace one attachment's index after positive aliases are durable.
 *
 * @param int     $attachment_id Attachment ID.
 * @param array[] $aliases Live aliases.
 * @return true|WP_Error
 */
function yotm_media_source_replace_attachment( $attachment_id, $aliases ) {
	global $wpdb;

	$attachment_id = absint( $attachment_id );
	$upserted      = yotm_media_source_upsert_aliases( $aliases );
	if ( is_wp_error( $upserted ) ) {
		return $upserted;
	}

	$table = yotm_job_table_names()['sources'];
	$keep  = array();
	foreach ( $aliases as $alias ) {
		$keep[] = sanitize_key( $alias['source_kind'] ?? '' ) . ':' . (string) ( $alias['path_hash'] ?? '' );
	}

	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Plugin-owned table name is trusted; values use placeholders and current rows must be uncached.
	$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id,source_kind,path_hash FROM {$table} WHERE attachment_id = %d", $attachment_id ) );
	if ( '' !== (string) $wpdb->last_error ) {
		return yotm_job_storage_error( 'yotm_job_storage_unavailable', (string) $wpdb->last_error );
	}

	foreach ( $rows as $row ) {
		if ( in_array( $row->source_kind . ':' . $row->path_hash, $keep, true ) ) {
			continue;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned source-index row.
		if ( false === $wpdb->delete( $table, array( 'id' => (int) $row->id ), array( '%d' ) ) ) {
			return yotm_job_storage_error( 'yotm_job_storage_unavailable', (string) $wpdb->last_error );
		}
	}

	return true;
}

/**
 * Synchronize one image attachment from live source metadata.
 *
 * @param int $attachment_id Attachment ID.
 * @return true|WP_Error
 */
function yotm_media_source_sync_attachment( $attachment_id ) {
	$aliases = yotm_media_source_aliases( $attachment_id );
	if ( is_wp_error( $aliases ) ) {
		return $aliases;
	}

	return yotm_media_source_replace_attachment( $attachment_id, $aliases );
}

/**
 * Remove all current-site source rows before a bounded baseline rebuild.
 *
 * @return true|WP_Error
 */
function yotm_media_source_clear_index() {
	global $wpdb;

	$table = yotm_job_table_names()['sources'];
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Site-specific plugin table is intentionally rebuilt before a prune baseline.
	$result = $wpdb->query( "DELETE FROM {$table}" );

	return false === $result ? yotm_job_storage_error( 'yotm_job_storage_unavailable', (string) $wpdb->last_error ) : true;
}

/**
 * Return a site-scoped named lock for a canonical media path.
 *
 * @param string $canonical_path Canonical path.
 * @return string
 */
function yotm_media_path_lock_name( $canonical_path ) {
	global $wpdb;

	$database = defined( 'DB_NAME' ) ? DB_NAME : 'WordPress';
	$scope    = $database . '|' . $wpdb->prefix . '|' . get_current_blog_id() . '|' . hash( 'sha256', (string) $canonical_path );

	return 'yotm_media_' . md5( $scope );
}

/**
 * Acquire one re-entrant request-local media path lock.
 *
 * @param string $path Media path.
 * @return array|WP_Error
 */
function yotm_media_path_lock_acquire( $path ) {
	$canonical = yotm_media_source_canonical_path( $path );
	if ( is_wp_error( $canonical ) ) {
		return $canonical;
	}

	$name = yotm_media_path_lock_name( $canonical );
	if ( ! isset( $GLOBALS['yotm_media_path_locks'] ) || ! is_array( $GLOBALS['yotm_media_path_locks'] ) ) {
		$GLOBALS['yotm_media_path_locks'] = array();
		register_shutdown_function( 'yotm_media_source_shutdown_cleanup' );
	}

	if ( isset( $GLOBALS['yotm_media_path_locks'][ $name ] ) ) {
		++$GLOBALS['yotm_media_path_locks'][ $name ]['refs'];
		return array(
			'name' => $name,
			'path' => $canonical,
		);
	}

	$acquired = yotm_job_acquire_named_lock( $name );
	if ( is_wp_error( $acquired ) ) {
		return new WP_Error( 'yotm_media_path_lock_failed', $acquired->get_error_message(), $acquired->get_error_data() );
	}
	if ( ! $acquired ) {
		return new WP_Error( 'yotm_media_path_busy', __( 'This media path is being updated by another request. Retrying shortly.', 'thumbnail-manager' ) );
	}

	$GLOBALS['yotm_media_path_locks'][ $name ] = array(
		'name' => $name,
		'path' => $canonical,
		'refs' => 1,
	);

	return array(
		'name' => $name,
		'path' => $canonical,
	);
}

/**
 * Release one request-local media path lock reference.
 *
 * @param array $handle Lock handle.
 * @return void
 */
function yotm_media_path_lock_release( $handle ) {
	$name = (string) ( $handle['name'] ?? '' );
	if ( '' === $name || empty( $GLOBALS['yotm_media_path_locks'][ $name ] ) ) {
		return;
	}

	--$GLOBALS['yotm_media_path_locks'][ $name ]['refs'];
	if ( $GLOBALS['yotm_media_path_locks'][ $name ]['refs'] <= 0 ) {
		yotm_job_release_named_lock( $name );
		unset( $GLOBALS['yotm_media_path_locks'][ $name ] );
	}
}

/**
 * Acquire a deterministic set of media path locks.
 *
 * @param array[] $aliases Source aliases.
 * @return array[]|WP_Error
 */
function yotm_media_path_lock_aliases( $aliases ) {
	$paths = array();
	foreach ( (array) $aliases as $alias ) {
		$path = (string) ( $alias['path'] ?? '' );
		if ( '' !== $path ) {
			$paths[ hash( 'sha256', $path ) ] = $path;
		}
	}
	ksort( $paths );

	$handles = array();
	foreach ( $paths as $path ) {
		$handle = yotm_media_path_lock_acquire( $path );
		if ( is_wp_error( $handle ) ) {
			foreach ( array_reverse( $handles ) as $owned ) {
				yotm_media_path_lock_release( $owned );
			}
			return $handle;
		}
		$handles[] = $handle;
	}

	return $handles;
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
	$attached = get_post_meta( $attachment_id, '_wp_attached_file', true );
	$metadata = get_post_meta( $attachment_id, '_wp_attachment_metadata', true );
	$metadata = is_array( $metadata ) ? $metadata : array();

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

	return yotm_media_source_aliases( $attachment_id, $attached, $metadata );
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
 * @return true|WP_Error
 */
function yotm_media_source_begin_guard( $channel, $attachment_id, $original_key, $effective_key, $meta_value, $meta_id = 0 ) {
	$authoritative = array( '_wp_attached_file', '_wp_attachment_metadata' );
	if ( ! in_array( $original_key, $authoritative, true ) && ! in_array( $effective_key, $authoritative, true ) ) {
		return true;
	}

	$aliases = yotm_media_source_proposed_aliases( $attachment_id, $original_key, $effective_key, $meta_value );
	if ( is_wp_error( $aliases ) ) {
		return $aliases;
	}
	$handles = yotm_media_path_lock_aliases( $aliases );
	if ( is_wp_error( $handles ) ) {
		return $handles;
	}

	$upserted = yotm_media_source_upsert_aliases( $aliases );
	if ( is_wp_error( $upserted ) ) {
		foreach ( array_reverse( $handles ) as $handle ) {
			yotm_media_path_lock_release( $handle );
		}
		return $upserted;
	}

	if ( ! isset( $GLOBALS['yotm_media_source_frames'] ) || ! is_array( $GLOBALS['yotm_media_source_frames'] ) ) {
		$GLOBALS['yotm_media_source_frames'] = array();
	}
	$GLOBALS['yotm_media_source_frames'][] = array(
		'id'            => wp_generate_uuid4(),
		'channel'       => sanitize_key( $channel ),
		'meta_id'       => absint( $meta_id ),
		'attachment_id' => absint( $attachment_id ),
		'original_key'  => (string) $original_key,
		'effective_key' => (string) $effective_key,
		'handles'       => $handles,
	);

	return true;
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
	return yotm_media_source_guard_filter( $check, 'add', $object_id, '', $meta_key, $meta_value );
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
	unset( $prev_value );
	return yotm_media_source_guard_filter( $check, 'update', $object_id, $meta_key, $meta_key, $meta_value );
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
 * @return mixed
 */
function yotm_media_source_guard_filter( $check, $channel, $object_id, $original_key, $effective_key, $meta_value ) {
	if ( null !== $check || ! yotm_media_source_guard_enabled() || ! wp_attachment_is_image( $object_id ) ) {
		return $check;
	}
	$guarded = yotm_media_source_begin_guard( $channel, $object_id, $original_key, $effective_key, $meta_value );
	if ( is_wp_error( $guarded ) ) {
		$GLOBALS['yotm_media_source_last_error'] = $guarded;
		return false;
	}
	return null;
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
	if ( null !== $check || ! yotm_media_source_guard_enabled() ) {
		return $check;
	}

	$meta = get_metadata_by_mid( 'post', $meta_id );
	if ( ! $meta || empty( $meta->post_id ) || ! isset( $meta->meta_key ) ) {
		return false;
	}
	if ( ! wp_attachment_is_image( (int) $meta->post_id ) ) {
		return $check;
	}
	if ( false === $meta_key ) {
		$effective_key = (string) $meta->meta_key;
	} elseif ( is_string( $meta_key ) ) {
		$effective_key = $meta_key;
	} else {
		return false;
	}

	$guarded = yotm_media_source_begin_guard( 'by_mid', (int) $meta->post_id, (string) $meta->meta_key, $effective_key, $meta_value, $meta_id );
	if ( is_wp_error( $guarded ) ) {
		$GLOBALS['yotm_media_source_last_error'] = $guarded;
		return false;
	}
	return null;
}

/**
 * Complete one metadata guard frame after Core writes successfully.
 *
 * @param int    $meta_id Meta row ID.
 * @param int    $object_id Post ID.
 * @param string $meta_key Effective key.
 * @param mixed  $meta_value Stored value.
 */
function yotm_media_source_complete_meta_guard( $meta_id, $object_id, $meta_key, $meta_value ) {
	unset( $meta_value );
	if ( empty( $GLOBALS['yotm_media_source_frames'] ) ) {
		return;
	}

	for ( $index = count( $GLOBALS['yotm_media_source_frames'] ) - 1; $index >= 0; --$index ) {
		$frame = $GLOBALS['yotm_media_source_frames'][ $index ];
		if ( (int) $frame['attachment_id'] !== (int) $object_id || $frame['effective_key'] !== (string) $meta_key ) {
			continue;
		}
		if ( $frame['meta_id'] && (int) $frame['meta_id'] !== (int) $meta_id ) {
			continue;
		}

		$synced = yotm_media_source_sync_attachment( $object_id );
		if ( is_wp_error( $synced ) ) {
			$GLOBALS['yotm_media_source_last_error'] = $synced;
			return;
		}
		foreach ( array_reverse( $frame['handles'] ) as $handle ) {
			yotm_media_path_lock_release( $handle );
		}
		array_splice( $GLOBALS['yotm_media_source_frames'], $index, 1 );
		return;
	}
}

/**
 * Keep legacy updated_postmeta completion idempotent.
 *
 * @param int    $meta_id Meta row ID.
 * @param int    $object_id Post ID.
 * @param string $meta_key Effective key.
 * @param mixed  $meta_value Stored value.
 */
function yotm_media_source_complete_legacy_meta_guard( $meta_id, $object_id, $meta_key, $meta_value ) {
	yotm_media_source_complete_meta_guard( $meta_id, $object_id, $meta_key, $meta_value );
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
	unset( $meta_ids, $meta_value );
	if ( yotm_media_source_guard_enabled() && in_array( $meta_key, array( '_wp_attached_file', '_wp_attachment_metadata' ), true ) && wp_attachment_is_image( $object_id ) ) {
		yotm_media_source_sync_attachment( $object_id );
	}
}

/**
 * Remove source rows after attachment deletion.
 *
 * @param int $attachment_id Attachment ID.
 */
function yotm_media_source_delete_attachment_rows( $attachment_id ) {
	global $wpdb;
	if ( yotm_media_source_guard_enabled() ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned source-index rows.
		$wpdb->delete( yotm_job_table_names()['sources'], array( 'attachment_id' => absint( $attachment_id ) ), array( '%d' ) );
	}
}

/** Release unmatched guard frames and locks exactly once at shutdown. */
function yotm_media_source_shutdown_cleanup() {
	if ( ! empty( $GLOBALS['yotm_media_source_frames'] ) ) {
		foreach ( array_reverse( $GLOBALS['yotm_media_source_frames'] ) as $frame ) {
			foreach ( array_reverse( (array) $frame['handles'] ) as $handle ) {
				yotm_media_path_lock_release( $handle );
			}
		}
		$GLOBALS['yotm_media_source_frames'] = array();
	}
	if ( ! empty( $GLOBALS['yotm_media_path_locks'] ) ) {
		foreach ( array_keys( $GLOBALS['yotm_media_path_locks'] ) as $name ) {
			yotm_job_release_named_lock( $name );
		}
		$GLOBALS['yotm_media_path_locks'] = array();
	}
}

/**
 * Check whether a canonical path is currently authoritative for any attachment.
 *
 * @param string $path Candidate path.
 * @param int    $limit Maximum aliases to inspect.
 * @return bool|WP_Error
 */
function yotm_media_source_path_is_authoritative( $path, $limit = YOTM_MEDIA_SOURCE_FANOUT_LIMIT ) {
	global $wpdb;

	$canonical = yotm_media_source_canonical_path( $path );
	if ( is_wp_error( $canonical ) ) {
		return $canonical;
	}
	$hash  = hash( 'sha256', $canonical );
	$table = yotm_job_table_names()['sources'];
	$limit = max( 1, absint( $limit ) );
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Plugin-owned table name is trusted; live source veto is uncached and values use placeholders.
	$rows = $wpdb->get_results( $wpdb->prepare( "SELECT attachment_id,path FROM {$table} WHERE path_hash = %s ORDER BY attachment_id ASC LIMIT %d", $hash, $limit + 1 ) );
	if ( '' !== (string) $wpdb->last_error ) {
		return yotm_job_storage_error( 'yotm_job_storage_unavailable', (string) $wpdb->last_error );
	}
	if ( count( $rows ) > $limit ) {
		return new WP_Error( 'yotm_media_source_fanout', __( 'Too many authoritative media aliases matched this path.', 'thumbnail-manager' ) );
	}

	$ids = array();
	foreach ( $rows as $row ) {
		if ( hash_equals( $canonical, (string) $row->path ) ) {
			$ids[ (int) $row->attachment_id ] = (int) $row->attachment_id;
		}
	}
	foreach ( $ids as $attachment_id ) {
		$aliases = yotm_media_source_aliases( $attachment_id );
		if ( is_wp_error( $aliases ) ) {
			return $aliases;
		}
		foreach ( $aliases as $alias ) {
			if ( hash_equals( $canonical, $alias['path'] ) ) {
				return true;
			}
		}
	}

	return false;
}
