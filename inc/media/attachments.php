<?php
/**
 * Authoritative attachment and selector Media primitives.
 *
 * @package Thumbnail_Manager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once dirname( __DIR__ ) . '/infrastructure/database.php';

// Exact raw metadata reads require uncached row queries and generated placeholder lists.
// phpcs:disable WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.SlowDBQuery.slow_db_query_meta_key,WordPress.DB.SlowDBQuery.slow_db_query_meta_value,WordPress.WP.GetMetaSingle.Missing

/**
 * Build one indexed attachment-meta condition for all selected subpaths.
 *
 * @param string[] $subpaths Normalized uploads subpaths.
 * @return array
 */
function yotm_attachment_query_args_for_upload_subpaths( $subpaths ) {
	$subpaths = yotm_normalize_upload_subpaths( $subpaths );

	if ( empty( $subpaths ) ) {
		return array();
	}

	$patterns = array_map(
		static function ( $subpath ) {
			// phpcs:ignore WordPress.PHP.PregQuoteDelimiter.Missing -- The result is passed to MySQL REGEXP, not a PHP-delimited pattern.
			return preg_quote( $subpath );
		},
		$subpaths
	);

	return array(
		// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Legacy query fallback retained for compatibility; bounded selectors do not use it.
		'meta_query' => array(
			array(
				'key'     => '_wp_attached_file',
				'value'   => '^(' . implode( '|', $patterns ) . ')/',
				'compare' => 'REGEXP',
			),
		),
	);
}

/**
 * Normalize one raw `_wp_attached_file` value for scope authorization.
 *
 * This deliberately does not sanitize or repair path components. A value is
 * either an unambiguous uploads-relative path or it is rejected.
 *
 * @param mixed $value Raw authoritative value.
 * @return string|WP_Error
 */
function yotm_normalize_attached_file_relative_path( $value ) {
	if ( ! is_string( $value ) || '' === $value || false !== strpos( $value, "\0" ) ) {
		return new WP_Error( 'yotm_attached_file_scope_invalid', __( 'The authoritative attached-file path is malformed.', 'thumbnail-manager' ) );
	}

	$value = str_replace( '\\', '/', $value );
	if ( preg_match( '#^(?:[A-Za-z]:)?/#', $value ) ) {
		return new WP_Error( 'yotm_attached_file_scope_absolute', __( 'The authoritative attached-file path is not uploads-relative.', 'thumbnail-manager' ) );
	}

	$parts = explode( '/', $value );
	foreach ( $parts as $part ) {
		if ( '' === $part || '.' === $part || '..' === $part ) {
			return new WP_Error( 'yotm_attached_file_scope_invalid', __( 'The authoritative attached-file path is malformed.', 'thumbnail-manager' ) );
		}
	}

	return implode( '/', $parts );
}

/**
 * Test literal uploads-subpath membership without wildcard interpretation.
 *
 * @param string   $relative_path Normalized uploads-relative file path.
 * @param string[] $subpaths Normalized selected folders.
 * @return bool
 */
function yotm_attached_file_is_in_subpaths( $relative_path, $subpaths ) {
	$relative_path = (string) $relative_path;
	foreach ( yotm_normalize_upload_subpaths( $subpaths ) as $subpath ) {
		if ( 0 === strpos( $relative_path, trailingslashit( $subpath ) ) ) {
			return true;
		}
	}

	return false;
}

/**
 * Return the frozen upper boundary for attached-file selector rows.
 *
 * @return int|WP_Error
 */
function yotm_get_max_attached_file_meta_id() {
	global $wpdb;

	$wpdb->last_error = '';
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Core postmeta table is trusted; this is a bounded selector snapshot read.
	$maximum = $wpdb->get_var( $wpdb->prepare( "SELECT MAX(meta_id) FROM {$wpdb->postmeta} WHERE meta_key = %s", '_wp_attached_file' ) );
	if ( '' !== (string) $wpdb->last_error ) {
		return new WP_Error( 'yotm_attached_file_selector_failed', __( 'Could not establish the attachment selector boundary.', 'thumbnail-manager' ) );
	}

	return absint( $maximum );
}

/**
 * Read one bounded keyset page of raw attached-file selector rows.
 *
 * @param int $after_meta_id Last scanned meta ID.
 * @param int $max_meta_id Frozen maximum meta ID.
 * @param int $limit Row limit.
 * @return array<int,array{meta_id:int,attachment_id:int,value:string}>|WP_Error
 */
function yotm_get_attached_file_selector_rows_after( $after_meta_id, $max_meta_id, $limit = 100 ) {
	global $wpdb;

	$after_meta_id = absint( $after_meta_id );
	$max_meta_id   = absint( $max_meta_id );
	$limit         = max( 1, min( 500, absint( $limit ) ) );
	if ( 0 === $max_meta_id || $after_meta_id >= $max_meta_id ) {
		return array();
	}

	$wpdb->last_error = '';
	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Core tables are trusted; selector is bounded by exact prepared meta IDs.
	$stored = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT pm.meta_id,pm.post_id attachment_id,pm.meta_value
			FROM {$wpdb->postmeta} pm
			INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
			WHERE pm.meta_key = %s AND pm.meta_id > %d AND pm.meta_id <= %d
			AND p.post_type = 'attachment' AND p.post_status = 'inherit' AND p.post_mime_type LIKE %s
			ORDER BY pm.meta_id ASC LIMIT %d",
			'_wp_attached_file',
			$after_meta_id,
			$max_meta_id,
			$wpdb->esc_like( 'image/' ) . '%',
			$limit
		),
		ARRAY_A
	);
	// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	if ( '' !== (string) $wpdb->last_error || ! is_array( $stored ) ) {
		return new WP_Error( 'yotm_attached_file_selector_failed', __( 'Could not read the attachment selector batch.', 'thumbnail-manager' ) );
	}

	$rows = array();
	foreach ( $stored as $row ) {
		$rows[] = array(
			'meta_id'       => absint( $row['meta_id'] ?? 0 ),
			'attachment_id' => absint( $row['attachment_id'] ?? 0 ),
			'value'         => (string) ( $row['meta_value'] ?? '' ),
		);
	}

	return $rows;
}

// Authoritative raw reads deliberately bypass the filterable metadata cache.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

/**
 * Read exact, unfiltered postmeta rows for an authoritative attachment key.
 *
 * The returned list preserves row cardinality and order. Values are decoded
 * exactly once with maybe_unserialize(), matching Core's stored-value shape
 * without invoking the short-circuitable metadata accessor layer.
 *
 * @param int    $attachment_id Attachment ID.
 * @param string $meta_key Authoritative meta key.
 * @return array<int,array{meta_id:int,raw_value:string,value:mixed}>|WP_Error
 */
function yotm_media_reference_raw_postmeta_rows( $attachment_id, $meta_key ) {
	global $wpdb;

	$attachment_id = absint( $attachment_id );
	$meta_key      = (string) $meta_key;
	$allowed       = array( '_wp_attached_file', '_wp_attachment_metadata', '_wp_attachment_backup_sizes' );
	if ( ! $attachment_id || ! in_array( $meta_key, $allowed, true ) ) {
		return new WP_Error( 'yotm_media_raw_meta_invalid', __( 'Authoritative attachment metadata could not be identified.', 'thumbnail-manager' ) );
	}

	$wpdb->last_error = '';
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Core postmeta table is trusted; destructive decisions require exact uncached rows that cannot be short-circuited by metadata filters.
	$stored = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT meta_id,meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s ORDER BY meta_id ASC",
			$attachment_id,
			$meta_key
		),
		ARRAY_A
	);
	if ( '' !== (string) $wpdb->last_error || ! is_array( $stored ) ) {
		return yotm_database_storage_error( 'yotm_job_storage_unavailable', (string) $wpdb->last_error );
	}

	$rows = array();
	foreach ( $stored as $row ) {
		$raw_value = (string) ( $row['meta_value'] ?? '' );
		$rows[]    = array(
			'meta_id'   => absint( $row['meta_id'] ?? 0 ),
			'raw_value' => $raw_value,
			'value'     => maybe_unserialize( $raw_value ),
		);
	}

	return $rows;
}

/**
 * Read exact authoritative postmeta rows for one bounded attachment batch.
 *
 * @param int[]    $attachment_ids Attachment IDs.
 * @param string[] $meta_keys Allowed authoritative keys.
 * @return array<int,array<string,array<int,array{meta_id:int,raw_value:string,value:mixed}>>>|WP_Error
 */
function yotm_media_reference_raw_postmeta_rows_batch( $attachment_ids, $meta_keys ) {
	global $wpdb;

	$attachment_ids = array_values( array_unique( array_filter( array_map( 'absint', (array) $attachment_ids ) ) ) );
	$allowed        = array( '_wp_attached_file', '_wp_attachment_metadata', '_wp_attachment_backup_sizes' );
	$meta_keys      = array_values( array_unique( array_intersect( array_map( 'strval', (array) $meta_keys ), $allowed ) ) );
	if ( empty( $attachment_ids ) || empty( $meta_keys ) || count( $attachment_ids ) > 500 ) {
		return new WP_Error( 'yotm_media_raw_meta_batch_invalid', __( 'The authoritative attachment metadata batch is invalid.', 'thumbnail-manager' ) );
	}

	$id_placeholders  = implode( ',', array_fill( 0, count( $attachment_ids ), '%d' ) );
	$key_placeholders = implode( ',', array_fill( 0, count( $meta_keys ), '%s' ) );
	$args             = array_merge( $attachment_ids, $meta_keys );
	$wpdb->last_error = '';
	$query            = "SELECT post_id,meta_id,meta_key,meta_value FROM {$wpdb->postmeta} " .
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Placeholder lists are generated from validated bounded arrays.
		"WHERE post_id IN ({$id_placeholders}) AND meta_key IN ({$key_placeholders}) " .
		'ORDER BY post_id ASC,meta_key ASC,meta_id ASC';
	// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Query contains only the trusted core table plus validated generated placeholders.
	$prepared = $wpdb->prepare( $query, ...$args );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Bounded exact raw rows are required and must bypass object caching; prepared above.
	$stored = $wpdb->get_results(
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared immediately above from the bounded validated query.
		$prepared,
		ARRAY_A
	);
	if ( '' !== (string) $wpdb->last_error || ! is_array( $stored ) ) {
		return yotm_database_storage_error( 'yotm_job_storage_unavailable', (string) $wpdb->last_error );
	}

	$grouped = array();
	foreach ( $attachment_ids as $attachment_id ) {
		foreach ( $meta_keys as $meta_key ) {
			$grouped[ $attachment_id ][ $meta_key ] = array();
		}
	}
	foreach ( $stored as $row ) {
		$attachment_id = absint( $row['post_id'] ?? 0 );
		$meta_key      = (string) ( $row['meta_key'] ?? '' );
		$raw_value     = (string) ( $row['meta_value'] ?? '' );
		if ( ! isset( $grouped[ $attachment_id ][ $meta_key ] ) ) {
			continue;
		}
		$grouped[ $attachment_id ][ $meta_key ][] = array(
			'meta_id'   => absint( $row['meta_id'] ?? 0 ),
			'raw_value' => $raw_value,
			'value'     => maybe_unserialize( $raw_value ),
		);
	}

	return $grouped;
}

/**
 * Classify an image attachment without filterable attachment accessors.
 *
 * Core historically permits imported image attachments whose MIME type is
 * `import`. Guard every import attachment conservatively because an
 * authoritative metadata mutation can itself introduce the image extension;
 * classifying only the pre-write attached-file value would be fail-open.
 *
 * @param int $attachment_id Attachment ID.
 * @return bool|WP_Error
 */
function yotm_media_reference_is_image_attachment( $attachment_id ) {
	global $wpdb;

	$attachment_id    = absint( $attachment_id );
	$wpdb->last_error = '';
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Guard applicability must use the exact unfiltered post row.
	$post = $attachment_id ? $wpdb->get_row( $wpdb->prepare( "SELECT post_type,post_mime_type FROM {$wpdb->posts} WHERE ID = %d", $attachment_id ), ARRAY_A ) : null;
	if ( '' !== (string) $wpdb->last_error ) {
		return yotm_database_storage_error( 'yotm_job_storage_unavailable', (string) $wpdb->last_error );
	}
	if ( ! is_array( $post ) || 'attachment' !== (string) ( $post['post_type'] ?? '' ) ) {
		return false;
	}

	$mime_type = strtolower( (string) ( $post['post_mime_type'] ?? '' ) );
	if ( 0 === strpos( $mime_type, 'image/' ) ) {
		return true;
	}

	return 'import' === $mime_type;
}

/**
 * Authorize one attachment against its exact frozen selector row.
 *
 * @param int      $attachment_id Attachment ID.
 * @param int      $selected_meta_id Meta ID observed within the frozen snapshot.
 * @param int      $selection_meta_max Frozen selector maximum.
 * @param string[] $subpaths Selected folder roots.
 * @return string|WP_Error Normalized authoritative relative file path.
 */
function yotm_authorize_attached_file_selector_scope( $attachment_id, $selected_meta_id, $selection_meta_max, $subpaths ) {
	$rows = yotm_media_reference_raw_postmeta_rows( $attachment_id, '_wp_attached_file' );
	if ( is_wp_error( $rows ) ) {
		return $rows;
	}
	if ( 1 !== count( $rows ) ) {
		return new WP_Error( 'yotm_attached_file_scope_ambiguous', __( 'The authoritative attached-file row is missing or ambiguous.', 'thumbnail-manager' ) );
	}

	$meta_id = absint( $rows[0]['meta_id'] ?? 0 );
	if ( 0 === $meta_id || absint( $selected_meta_id ) !== $meta_id || absint( $selection_meta_max ) < $meta_id ) {
		return new WP_Error( 'yotm_attached_file_scope_replaced', __( 'The authoritative attached-file row changed after folder selection.', 'thumbnail-manager' ) );
	}

	$relative = yotm_normalize_attached_file_relative_path( $rows[0]['value'] ?? null );
	if ( is_wp_error( $relative ) ) {
		return $relative;
	}
	if ( ! yotm_attached_file_is_in_subpaths( $relative, $subpaths ) ) {
		return new WP_Error( 'yotm_attached_file_scope_outside', __( 'The attachment moved outside the selected uploads folder.', 'thumbnail-manager' ) );
	}

	return $relative;
}
