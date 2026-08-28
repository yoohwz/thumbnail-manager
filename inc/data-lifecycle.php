<?php
/**
 * Deactivation and uninstall data-lifecycle policy.
 *
 * This module is intentionally safe to load without the normal plugin
 * bootstrap so WordPress uninstall can inspect persistent state without
 * activating runtime, media, AJAX, or migration behavior.
 *
 * @package Thumbnail_Manager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'YOTM_DATA_LIFECYCLE_SCHEMA_VERSION' ) ) {
	define( 'YOTM_DATA_LIFECYCLE_SCHEMA_VERSION', '1.2.0' );
}

if ( ! defined( 'YOTM_UNINSTALL_INTENT_OPTION' ) ) {
	define( 'YOTM_UNINSTALL_INTENT_OPTION', 'yotm_uninstall_cleanup_intent' );
}

/**
 * Return the immutable uninstall limits, optionally lowered for tests.
 *
 * Callers may lower a limit but cannot expand the reviewed production
 * boundary.
 *
 * @param array $overrides Optional lower limits.
 * @return array{site_batch:int,max_sites:int,item_batch:int,max_items:int,max_seconds:float}
 */
function yotm_data_lifecycle_limits( $overrides = array() ) {
	$defaults = array(
		'site_batch'  => 25,
		'max_sites'   => 100,
		'item_batch'  => 250,
		'max_items'   => 10000,
		'max_seconds' => 10.0,
	);

	foreach ( $defaults as $key => $default ) {
		if ( ! array_key_exists( $key, $overrides ) || ! is_numeric( $overrides[ $key ] ) ) {
			continue;
		}

		$value = (float) $overrides[ $key ];
		if ( 'max_seconds' === $key ) {
			$defaults[ $key ] = max( 0.001, min( $default, $value ) );
		} else {
			$defaults[ $key ] = max( 1, min( $default, (int) $value ) );
		}
	}

	return $defaults;
}

/**
 * Return exact plugin-owned table names for one site.
 *
 * @param int $blog_id Blog ID.
 * @return array{jobs:string,items:string,sources:string}|WP_Error
 */
function yotm_data_lifecycle_table_names( $blog_id ) {
	global $wpdb;

	$blog_id = absint( $blog_id );
	$prefix  = $blog_id ? (string) $wpdb->get_blog_prefix( $blog_id ) : '';
	if ( ! $blog_id || '' === $prefix || ! preg_match( '/^[A-Za-z0-9_]+$/', $prefix ) ) {
		return new WP_Error( 'yotm_uninstall_table_prefix', 'Unsafe or invalid site table prefix.' );
	}

	return array(
		'jobs'    => $prefix . 'yotm_jobs',
		'items'   => $prefix . 'yotm_job_items',
		'sources' => $prefix . 'yotm_media_sources',
	);
}

/**
 * Return whether a preflight deadline remains available.
 *
 * @param float $started Request start time.
 * @param float $seconds Maximum seconds.
 * @return bool
 */
function yotm_data_lifecycle_within_deadline( $started, $seconds ) {
	return microtime( true ) - (float) $started <= (float) $seconds;
}

/**
 * Read the physical presence and columns of one exact table.
 *
 * @param string $table Validated table name.
 * @return array{present:bool,columns:string[]}|WP_Error
 */
function yotm_data_lifecycle_inspect_table( $table ) {
	global $wpdb;

	if ( ! is_string( $table ) || ! preg_match( '/^[A-Za-z0-9_]+$/', $table ) ) {
		return new WP_Error( 'yotm_uninstall_table_name', 'Unsafe table identifier.' );
	}

	$suppressing      = $wpdb->suppress_errors();
	$wpdb->last_error = '';
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Identifier is strictly validated above.
	$columns = $wpdb->get_col( "SHOW COLUMNS FROM `{$table}`", 0 );
	$error   = (string) $wpdb->last_error;
	// phpcs:ignore WordPress.DB.RestrictedFunctions.mysql_mysqli_errno -- Error 1146 is the only fail-closed proof that an exact table is absent after DESCRIBE.
	$errno = $wpdb->dbh instanceof mysqli ? mysqli_errno( $wpdb->dbh ) : 0;
	$wpdb->suppress_errors( $suppressing );
	if ( '' === $error && is_array( $columns ) && ! empty( $columns ) ) {
		return array(
			'present' => true,
			'columns' => array_map( 'strval', $columns ),
		);
	}

	if ( 1146 !== $errno ) {
		return new WP_Error( 'yotm_uninstall_database_read', 'Could not inspect plugin table presence or columns.' );
	}

	return array(
		'present' => false,
		'columns' => array(),
	);
}

/**
 * Return the exact scope digest used by interruption markers.
 *
 * @param int[] $site_ids Ordered site IDs.
 * @return string
 */
function yotm_data_lifecycle_scope_hash( $site_ids ) {
	return hash( 'sha256', wp_json_encode( array_values( array_map( 'absint', $site_ids ) ) ) );
}

/**
 * Return the exact deterministic cleanup intent for one site.
 *
 * @param int      $blog_id Blog ID.
 * @param string   $scope_hash Scope digest.
 * @param string[] $tables Exact table names.
 * @return array
 */
function yotm_data_lifecycle_intent( $blog_id, $scope_hash, $tables ) {
	return array(
		'version'    => 1,
		'blog_id'    => absint( $blog_id ),
		'scope_hash' => (string) $scope_hash,
		'tables'     => array_values( array_map( 'strval', $tables ) ),
	);
}

/**
 * Return whether a persisted cleanup intent matches the exact current scope.
 *
 * @param mixed    $intent Stored value.
 * @param int      $blog_id Blog ID.
 * @param string   $scope_hash Scope digest.
 * @param string[] $tables Exact table names.
 * @return bool
 */
function yotm_data_lifecycle_valid_intent( $intent, $blog_id, $scope_hash, $tables ) {
	return is_array( $intent )
		&& 1 === ( $intent['version'] ?? null )
		&& absint( $blog_id ) === ( $intent['blog_id'] ?? null )
		&& is_string( $intent['scope_hash'] ?? null )
		&& hash_equals( (string) $scope_hash, $intent['scope_hash'] )
		&& array_values( array_map( 'strval', $tables ) ) === ( $intent['tables'] ?? null );
}

/**
 * Return whether a value is one lowercase SHA-256 hash.
 *
 * @param mixed $value Candidate.
 * @return bool
 */
function yotm_data_lifecycle_sha256( $value ) {
	return is_string( $value ) && 1 === preg_match( '/^[a-f0-9]{64}$/', $value );
}

/**
 * Reproduce the current Force journal metadata hash contract.
 *
 * @param array $metadata Attachment metadata.
 * @return string
 */
function yotm_data_lifecycle_metadata_hash( $metadata ) {
	// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- Must match the existing V1 Force journal producer exactly.
	return hash( 'sha256', serialize( $metadata ) );
}

/**
 * Prove one completed prune journal from persisted evidence only.
 *
 * @param mixed  $journal Journal value.
 * @param string $status Item status.
 * @return bool
 */
function yotm_data_lifecycle_prune_journal_resolved( $journal, $status ) {
	return 'done' === $status
		&& is_array( $journal )
		&& 1 === ( $journal['version'] ?? null )
		&& 'delete_reconciled' === ( $journal['outcome'] ?? null )
		&& is_string( $journal['path'] ?? null )
		&& '' !== $journal['path']
		&& yotm_data_lifecycle_sha256( $journal['file_hash'] ?? null )
		&& yotm_data_lifecycle_sha256( $journal['node_fingerprint'] ?? null )
		&& is_int( $journal['bytes'] ?? null )
		&& 0 <= $journal['bytes'];
}

/**
 * Prove one completed Force destination record.
 *
 * @param string $slug Destination key.
 * @param mixed  $entry Destination evidence.
 * @return bool
 */
function yotm_data_lifecycle_force_destination_resolved( $slug, $entry ) {
	if (
		! is_string( $slug )
		|| '' === $slug
		|| sanitize_key( $slug ) !== $slug
		|| ! is_array( $entry )
		|| ( $entry['slug'] ?? null ) !== $slug
		|| ! is_string( $entry['source'] ?? null )
		|| '' === $entry['source']
		|| ! is_string( $entry['destination'] ?? null )
		|| '' === $entry['destination']
		|| ! yotm_data_lifecycle_sha256( $entry['hash'] ?? null )
		|| ! is_string( $entry['promoted_hash'] ?? null )
		|| ! hash_equals( $entry['hash'], $entry['promoted_hash'] )
		|| true !== ( $entry['promoted'] ?? null )
		|| ! is_array( $entry['owners'] ?? null )
		|| ! is_string( $entry['backup'] ?? null )
	) {
		return false;
	}

	$mode = $entry['mode'] ?? null;
	if ( 'expected_absent' === $mode ) {
		return array() === $entry['owners']
			&& '' === ( $entry['old_hash'] ?? null )
			&& '' === $entry['backup']
			&& true === ( $entry['old_absent'] ?? null );
	}

	if ( 'replaceable_old_generated' !== $mode || false !== ( $entry['old_absent'] ?? null ) ) {
		return false;
	}
	if ( empty( $entry['owners'] ) || ! yotm_data_lifecycle_sha256( $entry['old_hash'] ?? null ) || '' === $entry['backup'] ) {
		return false;
	}
	foreach ( $entry['owners'] as $owner ) {
		if ( ! is_string( $owner ) || '' === $owner ) {
			return false;
		}
	}

	return true;
}

/**
 * Prove one completed Force journal from persisted evidence only.
 *
 * @param mixed  $journal Journal value.
 * @param string $status Item status.
 * @return bool
 */
function yotm_data_lifecycle_force_journal_resolved( $journal, $status ) {
	if (
		'done' !== $status
		|| ! is_array( $journal )
		|| 1 !== ( $journal['version'] ?? null )
		|| 'cleanup_complete' !== ( $journal['phase'] ?? null )
		|| ! is_int( $journal['attachment_id'] ?? null )
		|| 0 >= $journal['attachment_id']
		|| ! is_array( $journal['old_metadata'] ?? null )
		|| ! is_array( $journal['final_metadata'] ?? null )
		|| ! is_array( $journal['destinations'] ?? null )
		|| ! is_string( $journal['full'] ?? null )
		|| '' === $journal['full']
		|| ! is_string( $journal['stage'] ?? null )
		|| '' === $journal['stage']
		|| '' !== ( $journal['promotion_slug'] ?? null )
		|| ! yotm_data_lifecycle_sha256( $journal['old_metadata_hash'] ?? null )
		|| ! yotm_data_lifecycle_sha256( $journal['new_metadata_hash'] ?? null )
		|| ! hash_equals( $journal['old_metadata_hash'], yotm_data_lifecycle_metadata_hash( $journal['old_metadata'] ) )
		|| ! hash_equals( $journal['new_metadata_hash'], yotm_data_lifecycle_metadata_hash( $journal['final_metadata'] ) )
	) {
		return false;
	}

	foreach ( $journal['destinations'] as $slug => $entry ) {
		if ( ! yotm_data_lifecycle_force_destination_resolved( $slug, $entry ) ) {
			return false;
		}
	}

	return true;
}

/**
 * Enumerate one bounded, exact uninstall site snapshot.
 *
 * @param array $limits Reviewed limits.
 * @param float $started Preflight start time.
 * @return int[]|WP_Error
 */
function yotm_data_lifecycle_uninstall_site_ids( $limits, $started ) {
	global $wpdb;

	if ( ! is_multisite() ) {
		$blog_id = absint( get_current_blog_id() );

		return $blog_id ? array( $blog_id ) : new WP_Error( 'yotm_uninstall_site_scope', 'Could not resolve the current site.' );
	}

	$blogs_table = (string) $wpdb->blogs;
	if ( ! preg_match( '/^[A-Za-z0-9_]+$/', $blogs_table ) ) {
		return new WP_Error( 'yotm_uninstall_site_scope', 'Could not validate the multisite table.' );
	}

	$wpdb->last_error = '';
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Core blogs-table identifier is validated above.
	$high_water = $wpdb->get_var( "SELECT MAX(blog_id) FROM `{$blogs_table}`" );
	if ( '' !== (string) $wpdb->last_error || ! is_numeric( $high_water ) || 0 >= (int) $high_water ) {
		return new WP_Error( 'yotm_uninstall_site_scope', 'Could not snapshot multisite sites.' );
	}

	$site_ids = array();
	$last_id  = 0;
	do {
		if ( ! yotm_data_lifecycle_within_deadline( $started, $limits['max_seconds'] ) ) {
			return new WP_Error( 'yotm_uninstall_time_limit', 'Uninstall preflight exceeded its time budget.' );
		}

		$wpdb->last_error = '';
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Core table is validated; row values are prepared.
		$sql = $wpdb->prepare(
			"SELECT blog_id FROM `{$blogs_table}` WHERE blog_id > %d AND blog_id <= %d ORDER BY blog_id ASC LIMIT %d",
			$last_id,
			(int) $high_water,
			$limits['site_batch']
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Prepared above; authoritative bounded site enumeration.
		$page = $wpdb->get_col( $sql );
		if ( '' !== (string) $wpdb->last_error || ! is_array( $page ) ) {
			return new WP_Error( 'yotm_uninstall_site_scope', 'Could not enumerate multisite sites.' );
		}

		$page_count = count( $page );
		foreach ( $page as $raw_id ) {
			$blog_id = absint( $raw_id );
			if ( ! $blog_id || $blog_id <= $last_id || in_array( $blog_id, $site_ids, true ) ) {
				return new WP_Error( 'yotm_uninstall_site_scope', 'Multisite enumeration was inconsistent.' );
			}
			$site_ids[] = $blog_id;
			$last_id    = $blog_id;
			if ( count( $site_ids ) > $limits['max_sites'] ) {
				return new WP_Error( 'yotm_uninstall_site_limit', 'The network exceeds the bounded uninstall site limit.' );
			}
		}
	} while ( $limits['site_batch'] === $page_count && $last_id < (int) $high_water );

	return empty( $site_ids ) ? new WP_Error( 'yotm_uninstall_site_scope', 'The multisite scope was empty.' ) : $site_ids;
}

/**
 * Run a callback in one blog context and always restore the caller.
 *
 * @param int      $blog_id Blog ID.
 * @param callable $callback Callback.
 * @return mixed
 */
function yotm_data_lifecycle_in_blog( $blog_id, $callback ) {
	$current  = absint( get_current_blog_id() );
	$switched = absint( $blog_id ) !== $current;
	if ( $switched && ! switch_to_blog( absint( $blog_id ) ) ) {
		return new WP_Error( 'yotm_uninstall_site_switch', 'Could not switch to an uninstall site.' );
	}

	try {
		return call_user_func( $callback );
	} finally {
		if ( $switched ) {
			restore_current_blog();
		}
	}
}

/**
 * Return whether one complete Force/prune site is quiescent and resolved.
 *
 * @param array $tables Exact table names.
 * @param array $limits Reviewed limits.
 * @param float $started Preflight start time.
 * @param int   $scanned Reference to the cross-site item counter.
 * @return true|WP_Error
 */
function yotm_data_lifecycle_site_recovery_safe( $tables, $limits, $started, &$scanned ) {
	global $wpdb;

	$active           = array( 'scanning', 'running', 'awaiting_approval', 'approved', 'deleting' );
	$marks            = implode( ',', array_fill( 0, count( $active ), '%s' ) );
	$wpdb->last_error = '';
	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Validated plugin table; status values are prepared dynamically.
	$sql = $wpdb->prepare( "SELECT 1 FROM `{$tables['jobs']}` WHERE status IN ({$marks}) LIMIT 1", ...$active );
	// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
	// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Prepared above; authoritative quiescence read.
	$active_job = $wpdb->get_var( $sql );
	if ( '' !== (string) $wpdb->last_error ) {
		return new WP_Error( 'yotm_uninstall_database_read', 'Could not inspect active jobs.' );
	}
	if ( $active_job ) {
		return new WP_Error( 'yotm_uninstall_active_job', 'An active job requires retained data.' );
	}

	$wpdb->last_error = '';
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Validated exact table; authoritative processing-state read.
	$processing = $wpdb->get_var( "SELECT 1 FROM `{$tables['items']}` WHERE status = 'processing' LIMIT 1" );
	if ( '' !== (string) $wpdb->last_error ) {
		return new WP_Error( 'yotm_uninstall_database_read', 'Could not inspect processing items.' );
	}
	if ( $processing ) {
		return new WP_Error( 'yotm_uninstall_processing_item', 'A processing item requires retained data.' );
	}

	$last_id = 0;
	do {
		if ( ! yotm_data_lifecycle_within_deadline( $started, $limits['max_seconds'] ) ) {
			return new WP_Error( 'yotm_uninstall_time_limit', 'Uninstall preflight exceeded its time budget.' );
		}
		$wpdb->last_error = '';
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Validated plugin table; row values are prepared.
		$sql = $wpdb->prepare(
			"SELECT id,status,payload FROM `{$tables['items']}` WHERE id > %d ORDER BY id ASC LIMIT %d",
			$last_id,
			$limits['item_batch']
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Prepared above; bounded authoritative journal scan.
		$page = $wpdb->get_results( $sql, ARRAY_A );
		if ( '' !== (string) $wpdb->last_error || ! is_array( $page ) ) {
			return new WP_Error( 'yotm_uninstall_database_read', 'Could not scan recovery journals.' );
		}

		$page_count = count( $page );
		foreach ( $page as $row ) {
			++$scanned;
			if ( $scanned > $limits['max_items'] ) {
				return new WP_Error( 'yotm_uninstall_item_limit', 'The uninstall item scan exceeded its bounded limit.' );
			}
			$id = absint( $row['id'] ?? 0 );
			if ( ! $id || $id <= $last_id ) {
				return new WP_Error( 'yotm_uninstall_item_scan', 'Recovery journal ordering was inconsistent.' );
			}
			$last_id = $id;
			$raw     = is_string( $row['payload'] ?? null ) ? $row['payload'] : '';
			$payload = json_decode( $raw, true );
			if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $payload ) ) {
				return new WP_Error( 'yotm_uninstall_item_payload', 'An item payload could not be classified safely.' );
			}

			$prune_token = array_key_exists( 'prune_operation_journal_v1', $payload ) || false !== strpos( $raw, '"prune_operation_journal_v1"' );
			$force_token = array_key_exists( 'regeneration_journal', $payload ) || false !== strpos( $raw, '"regeneration_journal"' );
			if ( $prune_token && $force_token ) {
				return new WP_Error( 'yotm_uninstall_journal_conflict', 'An item contains contradictory recovery journals.' );
			}
			$status = (string) ( $row['status'] ?? '' );
			if ( $prune_token && ( ! array_key_exists( 'prune_operation_journal_v1', $payload ) || ! yotm_data_lifecycle_prune_journal_resolved( $payload['prune_operation_journal_v1'], $status ) ) ) {
				return new WP_Error( 'yotm_uninstall_prune_recovery', 'A prune recovery journal requires retained data.' );
			}
			if ( $force_token && ( ! array_key_exists( 'regeneration_journal', $payload ) || ! yotm_data_lifecycle_force_journal_resolved( $payload['regeneration_journal'], $status ) ) ) {
				return new WP_Error( 'yotm_uninstall_force_recovery', 'A Force recovery journal requires retained data.' );
			}
		}//end foreach
	} while ( $limits['item_batch'] === $page_count );
	if ( ! yotm_data_lifecycle_within_deadline( $started, $limits['max_seconds'] ) ) {
		return new WP_Error( 'yotm_uninstall_time_limit', 'Uninstall preflight exceeded its time budget.' );
	}

	return true;
}

/**
 * Preflight one site and return its exact cleanup plan.
 *
 * @param int    $blog_id Blog ID.
 * @param string $scope_hash Scope digest.
 * @param array  $limits Reviewed limits.
 * @param float  $started Preflight start time.
 * @param int    $scanned Reference to cross-site item count.
 * @return array|WP_Error
 */
function yotm_data_lifecycle_preflight_site( $blog_id, $scope_hash, $limits, $started, &$scanned ) {
	$tables = yotm_data_lifecycle_table_names( $blog_id );
	if ( is_wp_error( $tables ) ) {
		return $tables;
	}

	$inspected = array();
	foreach ( $tables as $key => $table ) {
		$state = yotm_data_lifecycle_inspect_table( $table );
		if ( is_wp_error( $state ) ) {
			return $state;
		}
		$inspected[ $key ] = $state;
	}

	$present      = array_keys( array_filter( wp_list_pluck( $inspected, 'present' ) ) );
	$version      = get_option( 'yotm_job_db_version', null );
	$intent       = get_option( YOTM_UNINSTALL_INTENT_OPTION, null );
	$valid_intent = yotm_data_lifecycle_valid_intent( $intent, $blog_id, $scope_hash, array_values( $tables ) );
	if ( null !== $intent && ! $valid_intent ) {
		return new WP_Error( 'yotm_uninstall_intent_invalid', 'Cleanup intent does not match the exact uninstall scope.' );
	}

	$all_present = 3 === count( $present );
	$all_absent  = 0 === count( $present );
	$current     = YOTM_DATA_LIFECYCLE_SCHEMA_VERSION === $version;
	$clean       = $all_absent && null === $version;
	$interrupted = $valid_intent;

	if ( ! ( ( $all_present && $current ) || $clean || $interrupted ) ) {
		return new WP_Error( 'yotm_uninstall_schema_ambiguous', 'Plugin storage ownership is incomplete or ambiguous.' );
	}

	$required = array(
		'jobs'    => array( 'id', 'token', 'blog_id', 'type', 'status', 'payload' ),
		'items'   => array( 'id', 'job_id', 'status', 'payload' ),
		'sources' => array( 'id', 'attachment_id', 'source_kind', 'path_hash', 'path' ),
	);
	foreach ( $required as $key => $columns ) {
		if ( $inspected[ $key ]['present'] && array_diff( $columns, $inspected[ $key ]['columns'] ) ) {
			return new WP_Error( 'yotm_uninstall_schema_fingerprint', 'Plugin table ownership could not be proven.' );
		}
	}

	if ( $inspected['jobs']['present'] ) {
		$wpdb_tables = $tables;
		if ( ! $inspected['items']['present'] ) {
			global $wpdb;
			$active           = array( 'scanning', 'running', 'awaiting_approval', 'approved', 'deleting' );
			$marks            = implode( ',', array_fill( 0, count( $active ), '%s' ) );
			$wpdb->last_error = '';
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Validated plugin table; values are prepared dynamically.
			$sql = $wpdb->prepare( "SELECT 1 FROM `{$wpdb_tables['jobs']}` WHERE status IN ({$marks}) LIMIT 1", ...$active );
			// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Prepared above.
			$active_job = $wpdb->get_var( $sql );
			if ( '' !== (string) $wpdb->last_error || $active_job ) {
				return new WP_Error( 'yotm_uninstall_active_job', 'An active or unreadable interrupted job requires retained data.' );
			}
		} else {
			$recovery = yotm_data_lifecycle_site_recovery_safe( $tables, $limits, $started, $scanned );
			if ( is_wp_error( $recovery ) ) {
				return $recovery;
			}
		}
	} elseif ( $inspected['items']['present'] ) {
		return new WP_Error( 'yotm_uninstall_schema_ambiguous', 'Item recovery ownership cannot be proven without its job table.' );
	}//end if

	return array(
		'blog_id' => absint( $blog_id ),
		'tables'  => $tables,
		'intent'  => yotm_data_lifecycle_intent( $blog_id, $scope_hash, array_values( $tables ) ),
	);
}

/**
 * Run a complete read-only scope preflight.
 *
 * @param int[]  $site_ids Ordered site IDs.
 * @param string $scope_hash Scope digest.
 * @param array  $limits Reviewed limits.
 * @param float  $started Preflight start time.
 * @return array|WP_Error
 */
function yotm_data_lifecycle_preflight_scope( $site_ids, $scope_hash, $limits, $started ) {
	$plans   = array();
	$scanned = 0;
	foreach ( $site_ids as $blog_id ) {
		if ( ! yotm_data_lifecycle_within_deadline( $started, $limits['max_seconds'] ) ) {
			return new WP_Error( 'yotm_uninstall_time_limit', 'Uninstall preflight exceeded its time budget.' );
		}
		$plan = yotm_data_lifecycle_in_blog(
			$blog_id,
			static function () use ( $blog_id, $scope_hash, $limits, $started, &$scanned ) {
				return yotm_data_lifecycle_preflight_site( $blog_id, $scope_hash, $limits, $started, $scanned );
			}
		);
		if ( is_wp_error( $plan ) ) {
			return $plan;
		}
		$plans[] = $plan;
	}

	return $plans;
}

/**
 * Persist all exact cleanup intents before any deletion.
 *
 * @param array[] $plans Site plans.
 * @return array|WP_Error Previous intent state for rollback.
 */
function yotm_data_lifecycle_prepare_intents( $plans ) {
	$previous = array();
	foreach ( $plans as $plan ) {
		$result = yotm_data_lifecycle_in_blog(
			$plan['blog_id'],
			static function () use ( $plan ) {
				$before = get_option( YOTM_UNINSTALL_INTENT_OPTION, null );
				update_option( YOTM_UNINSTALL_INTENT_OPTION, $plan['intent'], false );
				$after = get_option( YOTM_UNINSTALL_INTENT_OPTION, null );

				return array(
					'ok'     => $after === $plan['intent'],
					'before' => $before,
				);
			}
		);
		if ( is_wp_error( $result ) || empty( $result['ok'] ) ) {
			if ( ! is_wp_error( $result ) ) {
				$previous[] = array(
					'blog_id' => $plan['blog_id'],
					'value'   => $result['before'],
				);
			}
			yotm_data_lifecycle_restore_intents( $previous );

			return new WP_Error( 'yotm_uninstall_intent_write', 'Could not persist the complete uninstall intent scope.' );
		}
		$previous[] = array(
			'blog_id' => $plan['blog_id'],
			'value'   => $result['before'],
		);
	}//end foreach

	return $previous;
}

/**
 * Restore prior intent state after a pre-delete coordination failure.
 *
 * @param array[] $previous Previous intent values.
 * @return void
 */
function yotm_data_lifecycle_restore_intents( $previous ) {
	foreach ( array_reverse( $previous ) as $entry ) {
		yotm_data_lifecycle_in_blog(
			$entry['blog_id'],
			static function () use ( $entry ) {
				if ( null === $entry['value'] ) {
					delete_option( YOTM_UNINSTALL_INTENT_OPTION );
				} else {
					update_option( YOTM_UNINSTALL_INTENT_OPTION, $entry['value'], false );
				}
			}
		);
	}
}

/**
 * Return whether one exact table is absent after cleanup.
 *
 * @param string $table Validated table name.
 * @return bool
 */
function yotm_data_lifecycle_table_absent( $table ) {
	$state = yotm_data_lifecycle_inspect_table( $table );

	return ! is_wp_error( $state ) && ! $state['present'];
}

/**
 * Clean one preflight-approved site exactly.
 *
 * @param array $plan Site cleanup plan.
 * @return true|WP_Error
 */
function yotm_data_lifecycle_cleanup_site( $plan ) {
	global $wpdb;

	if ( get_option( YOTM_UNINSTALL_INTENT_OPTION, null ) !== $plan['intent'] ) {
		return new WP_Error( 'yotm_uninstall_intent_changed', 'Cleanup intent changed before mutation.' );
	}

	if ( false === wp_clear_scheduled_hook( 'yotm_cleanup_jobs' ) ) {
		return new WP_Error( 'yotm_uninstall_cron_cleanup', 'Could not clear the plugin cleanup schedule.' );
	}
	delete_transient( 'yotm_job_db_migration_failure' );
	foreach ( array( 'yotm_disabled_sizes', 'yotm_job_db_version', 'yotm_media_source_index_dirty', 'yotm_media_reference_index_state' ) as $option ) {
		delete_option( $option );
	}

	foreach ( array( 'sources', 'items', 'jobs' ) as $key ) {
		$table = $plan['tables'][ $key ];
		if ( ! preg_match( '/^[A-Za-z0-9_]+$/', $table ) ) {
			return new WP_Error( 'yotm_uninstall_table_name', 'Unsafe table identifier at cleanup.' );
		}
		$suppressing      = $wpdb->suppress_errors();
		$wpdb->last_error = '';
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Exact validated uninstall allowlist only.
		$dropped = $wpdb->query( "DROP TABLE IF EXISTS `{$table}`" );
		$error   = (string) $wpdb->last_error;
		$wpdb->suppress_errors( $suppressing );
		if ( false === $dropped || '' !== $error || ! yotm_data_lifecycle_table_absent( $table ) ) {
			return new WP_Error( 'yotm_uninstall_table_drop', 'Could not verify exact plugin table cleanup.' );
		}
	}

	foreach ( array( 'yotm_disabled_sizes', 'yotm_job_db_version', 'yotm_media_source_index_dirty', 'yotm_media_reference_index_state' ) as $option ) {
		if ( null !== get_option( $option, null ) ) {
			return new WP_Error( 'yotm_uninstall_option_cleanup', 'Could not verify exact plugin option cleanup.' );
		}
	}
	if ( false !== wp_next_scheduled( 'yotm_cleanup_jobs' ) ) {
		return new WP_Error( 'yotm_uninstall_cron_cleanup', 'Could not verify plugin cleanup schedule removal.' );
	}
	if ( false !== get_transient( 'yotm_job_db_migration_failure' ) ) {
		return new WP_Error( 'yotm_uninstall_transient_cleanup', 'Could not verify plugin transient cleanup.' );
	}

	delete_option( YOTM_UNINSTALL_INTENT_OPTION );
	if ( null !== get_option( YOTM_UNINSTALL_INTENT_OPTION, null ) ) {
		return new WP_Error( 'yotm_uninstall_intent_cleanup', 'Could not clear the completed cleanup intent.' );
	}

	return true;
}

/**
 * Execute the approved A2 uninstall policy.
 *
 * Unsafe state returns normally with retained data. Optional callbacks and
 * lower limits exist for isolated failure-injection tests only.
 *
 * @param array $options Optional test controls.
 * @return array{status:string,reason:string}
 */
function yotm_data_lifecycle_uninstall( $options = array() ) {
	$started = microtime( true );
	$limits  = yotm_data_lifecycle_limits( $options['limits'] ?? array() );
	$sites   = yotm_data_lifecycle_uninstall_site_ids( $limits, $started );
	if ( is_wp_error( $sites ) ) {
		return array(
			'status' => 'retained',
			'reason' => $sites->get_error_code(),
		);
	}
	$scope_hash = yotm_data_lifecycle_scope_hash( $sites );
	$plans      = yotm_data_lifecycle_preflight_scope( $sites, $scope_hash, $limits, $started );
	if ( is_wp_error( $plans ) ) {
		return array(
			'status' => 'retained',
			'reason' => $plans->get_error_code(),
		);
	}

	if ( is_callable( $options['before_commit_recheck'] ?? null ) ) {
		call_user_func( $options['before_commit_recheck'], $plans );
	}

	$verified_sites = yotm_data_lifecycle_uninstall_site_ids( $limits, $started );
	if ( is_wp_error( $verified_sites ) || $verified_sites !== $sites || ! hash_equals( $scope_hash, yotm_data_lifecycle_scope_hash( $verified_sites ) ) ) {
		return array(
			'status' => 'retained',
			'reason' => 'yotm_uninstall_scope_changed',
		);
	}
	$verified = yotm_data_lifecycle_preflight_scope( $verified_sites, $scope_hash, $limits, $started );
	if ( is_wp_error( $verified ) ) {
		return array(
			'status' => 'retained',
			'reason' => $verified->get_error_code(),
		);
	}
	if ( ! yotm_data_lifecycle_within_deadline( $started, $limits['max_seconds'] ) ) {
		return array(
			'status' => 'retained',
			'reason' => 'yotm_uninstall_time_limit',
		);
	}

	$intents = yotm_data_lifecycle_prepare_intents( $verified );
	if ( is_wp_error( $intents ) ) {
		return array(
			'status' => 'retained',
			'reason' => $intents->get_error_code(),
		);
	}

	foreach ( $verified as $plan ) {
		$result = yotm_data_lifecycle_in_blog(
			$plan['blog_id'],
			static function () use ( $plan ) {
				return yotm_data_lifecycle_cleanup_site( $plan );
			}
		);
		if ( is_wp_error( $result ) ) {
			return array(
				'status' => 'partial',
				'reason' => $result->get_error_code(),
			);
		}
	}

	return array(
		'status' => 'purged',
		'reason' => '',
	);
}

/**
 * Clear cleanup cron while retaining all persistent data on deactivation.
 *
 * @param bool $network_deactivating Whether the plugin is network-deactivated.
 * @return true|WP_Error
 */
function yotm_deactivate_job_cleanup( $network_deactivating = false ) {
	global $wpdb;

	if ( ! $network_deactivating || ! is_multisite() ) {
		if ( false === wp_clear_scheduled_hook( 'yotm_cleanup_jobs' ) ) {
			return new WP_Error( 'yotm_deactivate_cron_cleanup', 'Could not clear the plugin cleanup schedule.' );
		}

		return true;
	}

	$blogs_table = (string) $wpdb->blogs;
	if ( ! preg_match( '/^[A-Za-z0-9_]+$/', $blogs_table ) ) {
		return new WP_Error( 'yotm_deactivate_site_scope', 'Could not validate the multisite table.' );
	}
	$wpdb->last_error = '';
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Core table identifier is validated above.
	$high_water = $wpdb->get_var( "SELECT MAX(blog_id) FROM `{$blogs_table}`" );
	if ( '' !== (string) $wpdb->last_error || ! is_numeric( $high_water ) ) {
		return new WP_Error( 'yotm_deactivate_site_scope', 'Could not snapshot multisite sites.' );
	}

	$last_id = 0;
	do {
		$wpdb->last_error = '';
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Core table is validated; row values are prepared.
		$sql = $wpdb->prepare(
			"SELECT blog_id FROM `{$blogs_table}` WHERE blog_id > %d AND blog_id <= %d ORDER BY blog_id ASC LIMIT 25",
			$last_id,
			(int) $high_water
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Prepared above; bounded authoritative site enumeration.
		$page = $wpdb->get_col( $sql );
		if ( '' !== (string) $wpdb->last_error || ! is_array( $page ) ) {
			return new WP_Error( 'yotm_deactivate_site_scope', 'Could not enumerate multisite sites.' );
		}
		$page_count = count( $page );
		foreach ( $page as $raw_id ) {
			$blog_id = absint( $raw_id );
			if ( ! $blog_id || $blog_id <= $last_id ) {
				return new WP_Error( 'yotm_deactivate_site_scope', 'Multisite enumeration was inconsistent.' );
			}
			$result = yotm_data_lifecycle_in_blog(
				$blog_id,
				static function () {
					if ( false === wp_clear_scheduled_hook( 'yotm_cleanup_jobs' ) ) {
						return new WP_Error( 'yotm_deactivate_cron_cleanup', 'Could not clear the plugin cleanup schedule.' );
					}

					return true;
				}
			);
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			$last_id = $blog_id;
		}
	} while ( 25 === $page_count && $last_id < (int) $high_water );

	return true;
}
