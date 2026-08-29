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
