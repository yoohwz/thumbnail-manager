<?php
/**
 * Media-owned runtime access to the authoritative source table.
 *
 * Physical DDL and readiness remain in Jobs for installation compatibility.
 *
 * @package Thumbnail_Manager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once dirname( __DIR__ ) . '/infrastructure/database.php';

/**
 * Return the authoritative Media source table name for the current site.
 *
 * @return string
 */
function yotm_media_source_table_name() {
	return yotm_database_table_name( 'yotm_media_sources' );
}

/**
 * Build the existing fail-closed source-store error.
 *
 * @param string $database_error Optional internal database error.
 * @return WP_Error
 */
function yotm_media_source_storage_error( $database_error = '' ) {
	return yotm_database_storage_error( 'yotm_job_storage_unavailable', $database_error );
}

/**
 * Insert conservative positive source rows.
 *
 * @param array[] $aliases Source aliases.
 * @return true|WP_Error
 */
function yotm_media_source_upsert_aliases( $aliases ) {
	global $wpdb;

	$table = yotm_media_source_table_name();
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
			return yotm_media_source_storage_error( (string) $wpdb->last_error );
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

	$table = yotm_media_source_table_name();
	$keep  = array();
	foreach ( $aliases as $alias ) {
		$keep[] = sanitize_key( $alias['source_kind'] ?? '' ) . ':' . (string) ( $alias['path_hash'] ?? '' );
	}

	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Plugin-owned table name is trusted; values use placeholders and current rows must be uncached.
	$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id,source_kind,path_hash FROM {$table} WHERE attachment_id = %d", $attachment_id ) );
	if ( '' !== (string) $wpdb->last_error ) {
		return yotm_media_source_storage_error( (string) $wpdb->last_error );
	}

	foreach ( $rows as $row ) {
		if ( in_array( $row->source_kind . ':' . $row->path_hash, $keep, true ) ) {
			continue;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned source-index row.
		if ( false === $wpdb->delete( $table, array( 'id' => (int) $row->id ), array( '%d' ) ) ) {
			return yotm_media_source_storage_error( (string) $wpdb->last_error );
		}
	}

	return true;
}

/**
 * Clear all authoritative Media source rows for the current site.
 *
 * @return true|WP_Error
 */
function yotm_media_source_store_clear() {
	global $wpdb;

	$table = yotm_media_source_table_name();
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Site-specific plugin table is intentionally rebuilt before a prune baseline.
	$result = $wpdb->query( "DELETE FROM {$table}" );

	return false === $result ? yotm_media_source_storage_error( (string) $wpdb->last_error ) : true;
}

/**
 * Delete authoritative source rows for one attachment.
 *
 * @param int $attachment_id Attachment ID.
 * @return true|WP_Error
 */
function yotm_media_source_store_delete_attachment( $attachment_id ) {
	global $wpdb;

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned source-index rows.
	$deleted = $wpdb->delete( yotm_media_source_table_name(), array( 'attachment_id' => absint( $attachment_id ) ), array( '%d' ) );

	return false === $deleted ? yotm_media_source_storage_error( (string) $wpdb->last_error ) : true;
}

/**
 * Return indexed rows matching one path hash, including one overflow row.
 *
 * @param string $path_hash Canonical path hash.
 * @param int    $limit Maximum indexed rows to inspect.
 * @return object[]|WP_Error
 */
function yotm_media_source_store_path_rows( $path_hash, $limit ) {
	global $wpdb;

	$table = yotm_media_source_table_name();
	$limit = max( 1, absint( $limit ) );
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Plugin-owned table name is trusted; live source veto is uncached and values use placeholders.
	$rows = $wpdb->get_results( $wpdb->prepare( "SELECT attachment_id,source_kind,path FROM {$table} WHERE path_hash = %s ORDER BY attachment_id ASC,source_kind ASC LIMIT %d", (string) $path_hash, $limit + 1 ) );
	if ( '' !== (string) $wpdb->last_error ) {
		return yotm_media_source_storage_error( (string) $wpdb->last_error );
	}

	return $rows;
}

/**
 * Return indexed rows for a bounded set of exact path hashes.
 *
 * This deliberately remains an exact-hash lookup. It must not become a path
 * prefix or directory scan because the source table is an authority index, not
 * a generic media-family catalogue.
 *
 * @param string[] $path_hashes Exact SHA-256 path hashes.
 * @param int      $per_hash_limit Maximum rows accepted for one hash.
 * @return array<string,object[]>|WP_Error Rows grouped by path hash.
 */
function yotm_media_source_store_paths_rows( $path_hashes, $per_hash_limit = 100 ) {
	global $wpdb;

	$hashes = array();
	foreach ( (array) $path_hashes as $path_hash ) {
		$path_hash = strtolower( (string) $path_hash );
		if ( preg_match( '/^[a-f0-9]{64}$/', $path_hash ) ) {
			$hashes[ $path_hash ] = $path_hash;
		}
	}
	$hashes = array_values( $hashes );
	if ( count( $hashes ) > 4000 ) {
		return new WP_Error( 'yotm_media_source_bulk_limit', __( 'Too many exact media-source paths were requested in one batch.', 'thumbnail-manager' ) );
	}
	if ( empty( $hashes ) ) {
		return array();
	}

	$table          = yotm_media_source_table_name();
	$per_hash_limit = max( 1, absint( $per_hash_limit ) );
	$grouped        = array();
	foreach ( array_chunk( $hashes, 200 ) as $chunk ) {
		$placeholders = implode( ',', array_fill( 0, count( $chunk ), '%s' ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Exact bounded hashes use placeholders; table is plugin-owned.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT attachment_id,source_kind,path_hash,path FROM {$table} WHERE path_hash IN ({$placeholders}) ORDER BY path_hash ASC,attachment_id ASC,source_kind ASC", $chunk ) );
		if ( '' !== (string) $wpdb->last_error ) {
			return yotm_media_source_storage_error( (string) $wpdb->last_error );
		}
		foreach ( $rows as $row ) {
			$hash = strtolower( (string) $row->path_hash );
			if ( ! isset( $grouped[ $hash ] ) ) {
				$grouped[ $hash ] = array();
			}
			$grouped[ $hash ][] = $row;
			if ( count( $grouped[ $hash ] ) > $per_hash_limit ) {
				return new WP_Error( 'yotm_media_source_fanout', __( 'Too many authoritative media aliases matched one exact path.', 'thumbnail-manager' ) );
			}
		}
	}

	return $grouped;
}
