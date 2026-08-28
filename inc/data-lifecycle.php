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
 * Return the exact validated options table for the current site.
 *
 * @return string|WP_Error
 */
function yotm_data_lifecycle_options_table() {
	global $wpdb;

	$table = (string) $wpdb->options;
	if ( '' === $table || ! preg_match( '/^[A-Za-z0-9_]+$/', $table ) ) {
		return new WP_Error( 'yotm_uninstall_options_table', 'Could not validate the site options table.' );
	}

	return $table;
}

/**
 * Read one exact persisted option without filters or object-cache state.
 *
 * Duplicate rows and database errors are ambiguous and therefore fail closed.
 *
 * @param string $name Exact option name.
 * @return array{exists:bool,value:mixed}|WP_Error
 */
function yotm_data_lifecycle_read_option( $name ) {
	global $wpdb;

	$table = yotm_data_lifecycle_options_table();
	if ( is_wp_error( $table ) || ! is_string( $name ) || '' === $name ) {
		return is_wp_error( $table ) ? $table : new WP_Error( 'yotm_uninstall_option_name', 'Could not validate an option name.' );
	}

	$suppressing      = $wpdb->suppress_errors();
	$wpdb->last_error = '';
	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Exact validated options table; value is prepared.
	$sql = $wpdb->prepare( "SELECT option_value FROM `{$table}` WHERE option_name = %s LIMIT 2", $name );
	// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Persisted lifecycle authority must bypass filters and caches.
	$rows  = $wpdb->get_col( $sql );
	$error = (string) $wpdb->last_error;
	$wpdb->suppress_errors( $suppressing );
	if ( '' !== $error || ! is_array( $rows ) ) {
		return new WP_Error( 'yotm_uninstall_database_read', 'Could not read exact persisted lifecycle options.' );
	}
	if ( 1 < count( $rows ) ) {
		return new WP_Error( 'yotm_uninstall_option_duplicate', 'A lifecycle option has ambiguous duplicate rows.' );
	}
	if ( empty( $rows ) ) {
		return array(
			'exists' => false,
			'value'  => null,
		);
	}

	return array(
		'exists' => true,
		'value'  => maybe_unserialize( $rows[0] ),
	);
}

/**
 * Clear WordPress caches after an authoritative direct option mutation.
 *
 * @param string $name Exact option name.
 * @return void
 */
function yotm_data_lifecycle_clean_option_cache( $name ) {
	wp_cache_delete( $name, 'options' );
	wp_cache_delete( 'alloptions', 'options' );
	wp_cache_delete( 'notoptions', 'options' );
}

/**
 * Persist one exact option and verify the durable value without filters.
 *
 * @param string $name Exact option name.
 * @param mixed  $value Exact value.
 * @return true|WP_Error
 */
function yotm_data_lifecycle_write_option( $name, $value ) {
	global $wpdb;

	$table  = yotm_data_lifecycle_options_table();
	$before = yotm_data_lifecycle_read_option( $name );
	if ( is_wp_error( $table ) || is_wp_error( $before ) ) {
		return is_wp_error( $table ) ? $table : $before;
	}

	$serialized       = maybe_serialize( $value );
	$suppressing      = $wpdb->suppress_errors();
	$wpdb->last_error = '';
	if ( $before['exists'] ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Exact lifecycle row is verified below.
		$result = $wpdb->update( $table, array( 'option_value' => $serialized ), array( 'option_name' => $name ), array( '%s' ), array( '%s' ) );
	} else {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Exact lifecycle row is verified below.
		$result = $wpdb->insert(
			$table,
			array(
				'option_name'  => $name,
				'option_value' => $serialized,
				'autoload'     => 'no',
			),
			array( '%s', '%s', '%s' )
		);
	}
	$error = (string) $wpdb->last_error;
	$wpdb->suppress_errors( $suppressing );
	if ( false === $result || '' !== $error ) {
		return new WP_Error( 'yotm_uninstall_option_write', 'Could not persist an exact lifecycle option.' );
	}

	yotm_data_lifecycle_clean_option_cache( $name );
	$after = yotm_data_lifecycle_read_option( $name );
	if ( is_wp_error( $after ) || ! $after['exists'] || $after['value'] !== $value ) {
		return new WP_Error( 'yotm_uninstall_option_write', 'Could not verify an exact lifecycle option.' );
	}

	return true;
}

/**
 * Delete one exact option and verify durable absence without filters.
 *
 * @param string $name Exact option name.
 * @return true|WP_Error
 */
function yotm_data_lifecycle_delete_option( $name ) {
	global $wpdb;

	$table = yotm_data_lifecycle_options_table();
	if ( is_wp_error( $table ) ) {
		return $table;
	}

	$suppressing      = $wpdb->suppress_errors();
	$wpdb->last_error = '';
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Exact allowlisted lifecycle row is verified below.
	$result = $wpdb->delete( $table, array( 'option_name' => $name ), array( '%s' ) );
	$error  = (string) $wpdb->last_error;
	$wpdb->suppress_errors( $suppressing );
	if ( false === $result || '' !== $error ) {
		return new WP_Error( 'yotm_uninstall_option_delete', 'Could not delete an exact lifecycle option.' );
	}

	yotm_data_lifecycle_clean_option_cache( $name );
	$after = yotm_data_lifecycle_read_option( $name );
	if ( is_wp_error( $after ) || $after['exists'] ) {
		return new WP_Error( 'yotm_uninstall_option_delete', 'Could not verify exact lifecycle option deletion.' );
	}

	return true;
}

/**
 * Return the database-stable plugin lifecycle lock name.
 *
 * One physical name deliberately covers topology, every site, workers, and
 * media-source mutations. WordPress supports database versions where taking a
 * second named lock releases the first one, so lifecycle safety must never
 * depend on one connection owning multiple distinct GET_LOCK() names.
 *
 * @param string $kind Lock kind.
 * @param int    $blog_id Optional blog ID.
 * @return string
 */
function yotm_data_lifecycle_lock_name( $kind, $blog_id = 0 ) {
	global $wpdb;

	unset( $kind, $blog_id );
	$database = defined( 'DB_NAME' ) ? DB_NAME : 'WordPress';
	$scope    = $database . '|' . (string) $wpdb->base_prefix . '|plugin';

	return 'yotm_lifecycle_' . md5( $scope );
}

/**
 * Return the current connection and exact owner of a named lock.
 *
 * The single SELECT also detects an automatic wpdb reconnect: the new
 * connection ID cannot match a request-local handle captured before the loss.
 *
 * @param string $name Lock name.
 * @return array{connection_id:string,owner_id:string,owned:bool}|WP_Error
 */
function yotm_data_lifecycle_named_lock_state( $name ) {
	global $wpdb;

	$wpdb->last_error = '';
	$sql              = $wpdb->prepare(
		'SELECT CONNECTION_ID() AS connection_id, IS_USED_LOCK(%s) AS owner_id',
		sanitize_text_field( $name )
	);
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Advisory lock ownership is connection-local and uncached.
	$row = $wpdb->get_row( $sql, ARRAY_A );
	if ( '' !== (string) $wpdb->last_error || ! is_array( $row ) || ! is_numeric( $row['connection_id'] ?? null ) ) {
		return new WP_Error( 'yotm_uninstall_fence_database', 'Could not verify the lifecycle database fence.' );
	}

	$connection_id = (string) $row['connection_id'];
	$owner_id      = is_numeric( $row['owner_id'] ?? null ) ? (string) $row['owner_id'] : '';

	return array(
		'connection_id' => $connection_id,
		'owner_id'      => $owner_id,
		'owned'         => '' !== $owner_id && hash_equals( $connection_id, $owner_id ),
	);
}

/**
 * Verify one request-local lifecycle handle against the live DB connection.
 *
 * @param string $name Lock name.
 * @return true|false|WP_Error
 */
function yotm_data_lifecycle_request_fence_owned( $name ) {
	$handle = $GLOBALS['yotm_data_lifecycle_request_fences'][ $name ] ?? null;
	if ( ! is_array( $handle ) || ! is_string( $handle['connection_id'] ?? null ) ) {
		return false;
	}

	$state = yotm_data_lifecycle_named_lock_state( $name );
	if ( is_wp_error( $state ) ) {
		return $state;
	}
	if ( ! $state['owned'] || ! hash_equals( $handle['connection_id'], $state['connection_id'] ) ) {
		return new WP_Error( 'yotm_uninstall_fence_lost', 'The request lost its lifecycle database fence and cannot resume persistent mutations.' );
	}

	return true;
}

/** Register the single lifecycle shutdown coordinator once. */
function yotm_data_lifecycle_register_shutdown() {
	if ( empty( $GLOBALS['yotm_data_lifecycle_fence_shutdown'] ) ) {
		$GLOBALS['yotm_data_lifecycle_fence_shutdown'] = true;
		register_shutdown_function( 'yotm_data_lifecycle_shutdown' );
	}
}

/**
 * Record one newly acquired request-local lifecycle fence.
 *
 * @param string $name Lock name.
 * @return true|WP_Error
 */
function yotm_data_lifecycle_record_request_fence( $name ) {
	$state = yotm_data_lifecycle_named_lock_state( $name );
	if ( is_wp_error( $state ) || ! $state['owned'] ) {
		yotm_data_lifecycle_release_named_lock( $name );

		return is_wp_error( $state ) ? $state : new WP_Error( 'yotm_uninstall_fence_lost', 'The lifecycle database fence was lost after acquisition.' );
	}
	if ( ! isset( $GLOBALS['yotm_data_lifecycle_request_fences'] ) || ! is_array( $GLOBALS['yotm_data_lifecycle_request_fences'] ) ) {
		$GLOBALS['yotm_data_lifecycle_request_fences'] = array();
	}
	$GLOBALS['yotm_data_lifecycle_request_fences'][ $name ] = array(
		'connection_id' => $state['connection_id'],
	);
	yotm_data_lifecycle_register_shutdown();

	return true;
}

/**
 * Acquire one exact MySQL lifecycle lock.
 *
 * @param string $name Lock name.
 * @param int    $wait Maximum wait seconds.
 * @return true|false|WP_Error
 */
function yotm_data_lifecycle_acquire_named_lock( $name, $wait = 0 ) {
	global $wpdb;

	$wpdb->last_error = '';
	$sql              = $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', sanitize_text_field( $name ), max( 0, absint( $wait ) ) );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Advisory lock state is connection-local and uncached.
	$result = $wpdb->get_var( $sql );
	if ( '' !== (string) $wpdb->last_error || null === $result ) {
		return new WP_Error( 'yotm_uninstall_fence_database', 'Could not acquire the lifecycle database fence.' );
	}

	return '1' === (string) $result;
}

/**
 * Release one exact MySQL lifecycle lock.
 *
 * @param string $name Lock name.
 * @return bool
 */
function yotm_data_lifecycle_release_named_lock( $name ) {
	global $wpdb;

	$sql = $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', sanitize_text_field( $name ) );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Advisory lock state is connection-local and uncached.
	return '1' === (string) $wpdb->get_var( $sql );
}

/**
 * Hold the current site's lifecycle fence for the rest of this request.
 *
 * Every runtime mutation boundary uses this before it can create or advance
 * persistent work. An uninstall intent, read error, or contended fence blocks
 * the mutation without touching job state.
 *
 * @return true|WP_Error
 */
function yotm_data_lifecycle_require_runtime_fence() {
	$blog_id = absint( get_current_blog_id() );
	$name    = yotm_data_lifecycle_lock_name( 'site', $blog_id );
	$owned   = yotm_data_lifecycle_request_fence_owned( $name );
	if ( is_wp_error( $owned ) ) {
		return $owned;
	}
	if ( $owned ) {
		return true;
	}

	$acquired = yotm_data_lifecycle_acquire_named_lock( $name, 0 );
	if ( is_wp_error( $acquired ) ) {
		return $acquired;
	}
	if ( ! $acquired ) {
		return new WP_Error( 'yotm_uninstall_fence_busy', 'Persistent work is blocked by the uninstall lifecycle fence.' );
	}

	$intent = yotm_data_lifecycle_read_option( YOTM_UNINSTALL_INTENT_OPTION );
	if ( is_wp_error( $intent ) || $intent['exists'] ) {
		yotm_data_lifecycle_release_named_lock( $name );

		return is_wp_error( $intent ) ? $intent : new WP_Error( 'yotm_uninstall_in_progress', 'Persistent work is blocked while uninstall cleanup is pending.' );
	}

	return yotm_data_lifecycle_record_request_fence( $name );
}

/**
 * Release lifecycle locks held by normal runtime work in this request.
 *
 * @return void
 */
function yotm_data_lifecycle_release_request_fences() {
	if ( empty( $GLOBALS['yotm_data_lifecycle_request_fences'] ) || ! is_array( $GLOBALS['yotm_data_lifecycle_request_fences'] ) ) {
		return;
	}
	foreach ( array_reverse( array_keys( $GLOBALS['yotm_data_lifecycle_request_fences'] ) ) as $name ) {
		yotm_data_lifecycle_release_named_lock( $name );
	}
	$GLOBALS['yotm_data_lifecycle_request_fences'] = array();
}

/**
 * Drain mutation-bearing shutdown work before releasing lifecycle ownership.
 *
 * @return void
 */
function yotm_data_lifecycle_shutdown() {
	if ( function_exists( 'yotm_media_source_shutdown_cleanup' ) ) {
		yotm_media_source_shutdown_cleanup();
	}
	if ( function_exists( 'yotm_job_release_all_workers' ) ) {
		yotm_job_release_all_workers();
	}
	yotm_data_lifecycle_release_request_fences();
}

/**
 * Acquire the request topology fence before Core changes site membership.
 *
 * @return true|WP_Error
 */
function yotm_data_lifecycle_require_topology_fence() {
	if ( ! is_multisite() ) {
		return true;
	}
	$name  = yotm_data_lifecycle_lock_name( 'topology' );
	$owned = yotm_data_lifecycle_request_fence_owned( $name );
	if ( is_wp_error( $owned ) ) {
		return $owned;
	}
	if ( $owned ) {
		return true;
	}
	$acquired = yotm_data_lifecycle_acquire_named_lock( $name, 0 );
	if ( is_wp_error( $acquired ) ) {
		return $acquired;
	}
	if ( ! $acquired ) {
		return new WP_Error( 'yotm_uninstall_topology_busy', 'Site topology changes are blocked by the plugin lifecycle fence.' );
	}

	return yotm_data_lifecycle_record_request_fence( $name );
}

/**
 * Fail closed before Core inserts a new multisite row.
 *
 * @param WP_Error     $errors Validation errors.
 * @param array        $data Prepared site data.
 * @param WP_Site|null $old_site Existing site for updates.
 * @return void
 */
function yotm_data_lifecycle_validate_site_insert( $errors, $data, $old_site ) {
	unset( $data );
	if ( null !== $old_site || ! $errors instanceof WP_Error ) {
		return;
	}
	$fence = yotm_data_lifecycle_require_topology_fence();
	if ( is_wp_error( $fence ) ) {
		$errors->add( $fence->get_error_code(), $fence->get_error_message() );
	}
}

/**
 * Fail closed before Core deletes a multisite row or uninitializes its tables.
 *
 * @param WP_Error $errors Validation errors.
 * @param WP_Site  $old_site Site being deleted.
 * @return void
 */
function yotm_data_lifecycle_validate_site_deletion( $errors, $old_site ) {
	unset( $old_site );
	if ( ! $errors instanceof WP_Error ) {
		return;
	}
	$fence = yotm_data_lifecycle_require_topology_fence();
	if ( is_wp_error( $fence ) ) {
		$errors->add( $fence->get_error_code(), $fence->get_error_message() );
	}
}

add_action( 'wp_validate_site_data', 'yotm_data_lifecycle_validate_site_insert', 0, 3 );
add_action( 'wp_validate_site_deletion', 'yotm_data_lifecycle_validate_site_deletion', 0, 2 );

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
			$last_id  = $id;
			$raw      = is_string( $row['payload'] ?? null ) ? $row['payload'] : '';
			$analysis = yotm_data_lifecycle_decode_item_payload( $raw );
			if ( is_wp_error( $analysis ) ) {
				return $analysis;
			}
			$payload     = $analysis['payload'];
			$prune_token = ! empty( $analysis['keys']['prune_operation_journal_v1'] );
			$force_token = ! empty( $analysis['keys']['regeneration_journal'] );
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

	$present       = array_keys( array_filter( wp_list_pluck( $inspected, 'present' ) ) );
	$version_state = yotm_data_lifecycle_read_option( 'yotm_job_db_version' );
	$intent_state  = yotm_data_lifecycle_read_option( YOTM_UNINSTALL_INTENT_OPTION );
	if ( is_wp_error( $version_state ) || is_wp_error( $intent_state ) ) {
		return is_wp_error( $version_state ) ? $version_state : $intent_state;
	}
	$version      = $version_state['exists'] ? $version_state['value'] : null;
	$intent       = $intent_state['exists'] ? $intent_state['value'] : null;
	$valid_intent = $intent_state['exists'] && yotm_data_lifecycle_valid_intent( $intent, $blog_id, $scope_hash, array_values( $tables ) );
	if ( $intent_state['exists'] && ! $valid_intent ) {
		return new WP_Error( 'yotm_uninstall_intent_invalid', 'Cleanup intent does not match the exact uninstall scope.' );
	}

	$all_present = 3 === count( $present );
	$all_absent  = 0 === count( $present );
	$current     = YOTM_DATA_LIFECYCLE_SCHEMA_VERSION === $version;
	$clean       = $all_absent && ! $version_state['exists'];
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
 * @param array[] $locks Lock handles that authorize intent mutation.
 * @return array|WP_Error Previous intent state for rollback.
 */
function yotm_data_lifecycle_prepare_intents( $plans, $locks ) {
	$previous = array();
	foreach ( $plans as $plan ) {
		$valid = yotm_data_lifecycle_verify_scope_fences( $locks );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}
		$result = yotm_data_lifecycle_in_blog(
			$plan['blog_id'],
			static function () use ( $plan ) {
				$before = yotm_data_lifecycle_read_option( YOTM_UNINSTALL_INTENT_OPTION );
				if ( is_wp_error( $before ) ) {
					return $before;
				}
				$written = yotm_data_lifecycle_write_option( YOTM_UNINSTALL_INTENT_OPTION, $plan['intent'] );

				return array(
					'ok'     => ! is_wp_error( $written ),
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
			$valid = yotm_data_lifecycle_verify_scope_fences( $locks );
			if ( is_wp_error( $valid ) ) {
				return $valid;
			}
			$restored = yotm_data_lifecycle_restore_intents( $previous );

			return $restored
				? new WP_Error( 'yotm_uninstall_intent_write', 'Could not persist the complete uninstall intent scope.' )
				: new WP_Error( 'yotm_uninstall_intent_rollback', 'Could not verify cleanup-intent rollback after a coordination failure.' );
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
 * @return bool
 */
function yotm_data_lifecycle_restore_intents( $previous ) {
	$restored = true;
	foreach ( array_reverse( $previous ) as $entry ) {
		$result = yotm_data_lifecycle_in_blog(
			$entry['blog_id'],
			static function () use ( $entry ) {
				if ( empty( $entry['value']['exists'] ) ) {
					return yotm_data_lifecycle_delete_option( YOTM_UNINSTALL_INTENT_OPTION );
				} else {
					return yotm_data_lifecycle_write_option( YOTM_UNINSTALL_INTENT_OPTION, $entry['value']['value'] );
				}
			}
		);
		if ( is_wp_error( $result ) ) {
			$restored = false;
		}
	}

	return $restored;
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
 * Inspect the raw cron option for one exact hook.
 *
 * @param string $hook Exact cron hook.
 * @return bool|WP_Error
 */
function yotm_data_lifecycle_cron_has_hook( $hook ) {
	$state = yotm_data_lifecycle_read_option( 'cron' );
	if ( is_wp_error( $state ) || ! $state['exists'] ) {
		return is_wp_error( $state ) ? $state : false;
	}
	if ( ! is_array( $state['value'] ) ) {
		return new WP_Error( 'yotm_uninstall_cron_read', 'Could not classify the persisted cron schedule.' );
	}
	foreach ( $state['value'] as $timestamp => $events ) {
		if ( 'version' === (string) $timestamp ) {
			continue;
		}
		if ( ! is_array( $events ) ) {
			return new WP_Error( 'yotm_uninstall_cron_read', 'Could not classify the persisted cron schedule.' );
		}
		if ( array_key_exists( $hook, $events ) ) {
			return true;
		}
	}

	return false;
}

/**
 * Clean one preflight-approved site exactly.
 *
 * @param array $plan Site cleanup plan.
 * @return true|WP_Error
 */
function yotm_data_lifecycle_cleanup_site( $plan ) {
	global $wpdb;

	$intent = yotm_data_lifecycle_read_option( YOTM_UNINSTALL_INTENT_OPTION );
	if ( is_wp_error( $intent ) || ! $intent['exists'] || $intent['value'] !== $plan['intent'] ) {
		return new WP_Error( 'yotm_uninstall_intent_changed', 'Cleanup intent changed before mutation.' );
	}

	if ( false === wp_clear_scheduled_hook( 'yotm_cleanup_jobs' ) ) {
		return new WP_Error( 'yotm_uninstall_cron_cleanup', 'Could not clear the plugin cleanup schedule.' );
	}
	delete_transient( 'yotm_job_db_migration_failure' );
	foreach ( array( '_transient_yotm_job_db_migration_failure', '_transient_timeout_yotm_job_db_migration_failure' ) as $transient_option ) {
		$deleted = yotm_data_lifecycle_delete_option( $transient_option );
		if ( is_wp_error( $deleted ) ) {
			return new WP_Error( 'yotm_uninstall_transient_cleanup', 'Could not clear the plugin migration transient.' );
		}
	}
	foreach ( array( 'yotm_disabled_sizes', 'yotm_job_db_version', 'yotm_media_source_index_dirty', 'yotm_media_reference_index_state' ) as $option ) {
		$deleted = yotm_data_lifecycle_delete_option( $option );
		if ( is_wp_error( $deleted ) ) {
			return new WP_Error( 'yotm_uninstall_option_cleanup', 'Could not clear an exact plugin option.' );
		}
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
		$state = yotm_data_lifecycle_read_option( $option );
		if ( is_wp_error( $state ) || $state['exists'] ) {
			return new WP_Error( 'yotm_uninstall_option_cleanup', 'Could not verify exact plugin option cleanup.' );
		}
	}
	$cron = yotm_data_lifecycle_cron_has_hook( 'yotm_cleanup_jobs' );
	if ( is_wp_error( $cron ) || $cron ) {
		return new WP_Error( 'yotm_uninstall_cron_cleanup', 'Could not verify plugin cleanup schedule removal.' );
	}
	foreach ( array( '_transient_yotm_job_db_migration_failure', '_transient_timeout_yotm_job_db_migration_failure' ) as $transient_option ) {
		$state = yotm_data_lifecycle_read_option( $transient_option );
		if ( is_wp_error( $state ) || $state['exists'] ) {
			return new WP_Error( 'yotm_uninstall_transient_cleanup', 'Could not verify plugin transient cleanup.' );
		}
	}
	if ( wp_using_ext_object_cache() ) {
		$found = false;
		wp_cache_get( 'yotm_job_db_migration_failure', 'transient', false, $found );
		if ( $found ) {
			return new WP_Error( 'yotm_uninstall_transient_cleanup', 'Could not verify plugin transient cache cleanup.' );
		}
	}

	$deleted_intent = yotm_data_lifecycle_delete_option( YOTM_UNINSTALL_INTENT_OPTION );
	if ( is_wp_error( $deleted_intent ) ) {
		return new WP_Error( 'yotm_uninstall_intent_cleanup', 'Could not clear the completed cleanup intent.' );
	}

	return true;
}

/**
 * Acquire the topology and every exact site fence for uninstall.
 *
 * Locks already held by normal work in this same request are borrowed rather
 * than recursively acquired. Production uninstall loads this module alone and
 * therefore owns every returned lock.
 *
 * @param int[] $site_ids Exact ordered site scope.
 * @return array[]|WP_Error
 */
function yotm_data_lifecycle_acquire_scope_fences( $site_ids ) {
	$names = array();
	if ( is_multisite() ) {
		$names[] = yotm_data_lifecycle_lock_name( 'topology' );
	}
	foreach ( $site_ids as $blog_id ) {
		$names[] = yotm_data_lifecycle_lock_name( 'site', $blog_id );
	}
	$names = array_values( array_unique( $names ) );

	$locks = array();
	foreach ( $names as $name ) {
		$borrowed = yotm_data_lifecycle_request_fence_owned( $name );
		if ( is_wp_error( $borrowed ) ) {
			yotm_data_lifecycle_release_scope_fences( $locks );

			return $borrowed;
		}
		if ( ! $borrowed ) {
			$acquired = yotm_data_lifecycle_acquire_named_lock( $name, 0 );
			if ( is_wp_error( $acquired ) || ! $acquired ) {
				yotm_data_lifecycle_release_scope_fences( $locks );

				return is_wp_error( $acquired ) ? $acquired : new WP_Error( 'yotm_uninstall_fence_busy', 'An in-flight request requires retained plugin data.' );
			}
		}
		$state = yotm_data_lifecycle_named_lock_state( $name );
		if ( is_wp_error( $state ) || ! $state['owned'] ) {
			if ( ! $borrowed ) {
				yotm_data_lifecycle_release_named_lock( $name );
			}
			yotm_data_lifecycle_release_scope_fences( $locks );

			return is_wp_error( $state ) ? $state : new WP_Error( 'yotm_uninstall_fence_lost', 'The lifecycle database fence was lost after acquisition.' );
		}
		$locks[] = array(
			'name'          => $name,
			'owned'         => ! $borrowed,
			'connection_id' => $state['connection_id'],
		);
	}//end foreach

	return $locks;
}

/**
 * Verify every uninstall scope handle against the current DB connection.
 *
 * @param array[] $locks Lock handles.
 * @return true|WP_Error
 */
function yotm_data_lifecycle_verify_scope_fences( $locks ) {
	foreach ( $locks as $lock ) {
		if ( ! is_string( $lock['name'] ?? null ) || ! is_string( $lock['connection_id'] ?? null ) ) {
			return new WP_Error( 'yotm_uninstall_fence_lost', 'A lifecycle database fence handle is invalid.' );
		}
		$state = yotm_data_lifecycle_named_lock_state( $lock['name'] );
		if ( is_wp_error( $state ) ) {
			return $state;
		}
		if ( ! $state['owned'] || ! hash_equals( $lock['connection_id'], $state['connection_id'] ) ) {
			return new WP_Error( 'yotm_uninstall_fence_lost', 'A lifecycle database fence was lost before cleanup completed.' );
		}
	}

	return true;
}

/**
 * Roll back intents only while every scope fence is still owned.
 *
 * A lost DB connection also loses its named lock. In that state the durable
 * intents must remain as a recovery blocker; attempting an unfenced rollback
 * would reopen the mutation window that the intents closed.
 *
 * @param array[] $previous Previous intent states.
 * @param string  $reason Failure reason.
 * @param array[] $locks Lock handles.
 * @return array{status:string,reason:string}
 */
function yotm_data_lifecycle_rollback_with_fences( $previous, $reason, $locks ) {
	$valid = yotm_data_lifecycle_verify_scope_fences( $locks );
	if ( is_wp_error( $valid ) ) {
		return array(
			'status' => 'partial',
			'reason' => $valid->get_error_code(),
		);
	}

	return yotm_data_lifecycle_rollback_result( $previous, $reason );
}

/**
 * Release uninstall-owned scope fences.
 *
 * @param array[] $locks Lock handles.
 * @return void
 */
function yotm_data_lifecycle_release_scope_fences( $locks ) {
	foreach ( array_reverse( $locks ) as $lock ) {
		if ( ! empty( $lock['owned'] ) ) {
			yotm_data_lifecycle_release_named_lock( $lock['name'] );
		}
	}
}

/**
 * Restore pre-intent state and return one retained result.
 *
 * @param array[] $previous Previous intent states.
 * @param string  $reason Original failure reason.
 * @return array{status:string,reason:string}
 */
function yotm_data_lifecycle_rollback_result( $previous, $reason ) {
	return array(
		'status' => 'retained',
		'reason' => yotm_data_lifecycle_restore_intents( $previous ) ? $reason : 'yotm_uninstall_intent_rollback',
	);
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

	$fences = yotm_data_lifecycle_acquire_scope_fences( $sites );
	if ( is_wp_error( $fences ) ) {
		return array(
			'status' => 'retained',
			'reason' => $fences->get_error_code(),
		);
	}

	try {
		$fences_valid = yotm_data_lifecycle_verify_scope_fences( $fences );
		if ( is_wp_error( $fences_valid ) ) {
			return array(
				'status' => 'retained',
				'reason' => $fences_valid->get_error_code(),
			);
		}
		$verified_sites = yotm_data_lifecycle_uninstall_site_ids( $limits, $started );
		if ( is_wp_error( $verified_sites ) ) {
			return array(
				'status' => 'retained',
				'reason' => $verified_sites->get_error_code(),
			);
		}
		if ( $verified_sites !== $sites || ! hash_equals( $scope_hash, yotm_data_lifecycle_scope_hash( $verified_sites ) ) ) {
			return array(
				'status' => 'retained',
				'reason' => 'yotm_uninstall_scope_changed',
			);
		}

		$previous = yotm_data_lifecycle_prepare_intents( $plans, $fences );
		if ( is_wp_error( $previous ) ) {
			return array(
				'status' => 'yotm_uninstall_fence_lost' === $previous->get_error_code() ? 'partial' : 'retained',
				'reason' => $previous->get_error_code(),
			);
		}

		if ( is_callable( $options['after_intents'] ?? null ) ) {
			call_user_func( $options['after_intents'], $plans );
		}
		$fences_valid = yotm_data_lifecycle_verify_scope_fences( $fences );
		if ( is_wp_error( $fences_valid ) ) {
			return array(
				'status' => 'partial',
				'reason' => $fences_valid->get_error_code(),
			);
		}

		$final_sites = yotm_data_lifecycle_uninstall_site_ids( $limits, $started );
		if ( is_wp_error( $final_sites ) ) {
			return yotm_data_lifecycle_rollback_with_fences( $previous, $final_sites->get_error_code(), $fences );
		}
		if ( $final_sites !== $sites || ! hash_equals( $scope_hash, yotm_data_lifecycle_scope_hash( $final_sites ) ) ) {
			return yotm_data_lifecycle_rollback_with_fences( $previous, 'yotm_uninstall_scope_changed', $fences );
		}

		$verified = yotm_data_lifecycle_preflight_scope( $final_sites, $scope_hash, $limits, $started );
		if ( is_wp_error( $verified ) ) {
			return yotm_data_lifecycle_rollback_with_fences( $previous, $verified->get_error_code(), $fences );
		}
		if ( ! yotm_data_lifecycle_within_deadline( $started, $limits['max_seconds'] ) ) {
			return yotm_data_lifecycle_rollback_with_fences( $previous, 'yotm_uninstall_time_limit', $fences );
		}

		foreach ( $verified as $plan ) {
			$fences_valid = yotm_data_lifecycle_verify_scope_fences( $fences );
			if ( is_wp_error( $fences_valid ) ) {
				return array(
					'status' => 'partial',
					'reason' => $fences_valid->get_error_code(),
				);
			}
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
		}//end foreach

		return array(
			'status' => 'purged',
			'reason' => '',
		);
	} finally {
		yotm_data_lifecycle_release_scope_fences( $fences );
	}//end try
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

/**
 * Skip JSON whitespace from one parser cursor.
 *
 * @param string $raw JSON document.
 * @param int    $cursor Parser cursor.
 * @return void
 */
function yotm_data_lifecycle_json_skip_space( $raw, &$cursor ) {
	$length = strlen( $raw );
	while ( $cursor < $length && false !== strpos( " \t\r\n", $raw[ $cursor ] ) ) {
		++$cursor;
	}
}

/**
 * Parse and decode one JSON string token.
 *
 * @param string $raw JSON document.
 * @param int    $cursor Parser cursor.
 * @return string|WP_Error
 */
function yotm_data_lifecycle_json_string( $raw, &$cursor ) {
	$length = strlen( $raw );
	if ( $cursor >= $length || '"' !== $raw[ $cursor ] ) {
		return new WP_Error( 'yotm_uninstall_item_payload', 'An item payload contains invalid JSON.' );
	}
	$start = $cursor++;
	while ( $cursor < $length ) {
		$char = $raw[ $cursor++ ];
		if ( '"' === $char ) {
			$token   = substr( $raw, $start, $cursor - $start );
			$decoded = json_decode( $token );

			return JSON_ERROR_NONE === json_last_error() && is_string( $decoded )
				? $decoded
				: new WP_Error( 'yotm_uninstall_item_payload', 'An item payload contains an invalid JSON string.' );
		}
		if ( '\\' === $char ) {
			if ( $cursor >= $length ) {
				break;
			}
			$escape = $raw[ $cursor++ ];
			if ( 'u' === $escape ) {
				if ( 4 > $length - $cursor || ! ctype_xdigit( substr( $raw, $cursor, 4 ) ) ) {
					break;
				}
				$cursor += 4;
			} elseif ( false === strpos( '"\\/bfnrt', $escape ) ) {
				break;
			}
		} elseif ( ord( $char ) < 0x20 ) {
			break;
		}
	}//end while

	return new WP_Error( 'yotm_uninstall_item_payload', 'An item payload contains an unterminated JSON string.' );
}

/**
 * Parse one strict JSON value and reject duplicate object names.
 *
 * Decoded names are compared so literal and Unicode-escaped equivalents are
 * the same key. Every object level is checked independently.
 *
 * @param string $raw JSON document.
 * @param int    $cursor Parser cursor.
 * @param int    $depth Current nesting depth.
 * @param array  $keys All decoded object-name counts.
 * @return true|WP_Error
 */
function yotm_data_lifecycle_json_value( $raw, &$cursor, $depth, &$keys ) {
	if ( 64 < $depth ) {
		return new WP_Error( 'yotm_uninstall_item_payload', 'An item payload exceeds the safe JSON nesting limit.' );
	}
	yotm_data_lifecycle_json_skip_space( $raw, $cursor );
	$length = strlen( $raw );
	if ( $cursor >= $length ) {
		return new WP_Error( 'yotm_uninstall_item_payload', 'An item payload contains incomplete JSON.' );
	}

	$char = $raw[ $cursor ];
	if ( '"' === $char ) {
		$value = yotm_data_lifecycle_json_string( $raw, $cursor );

		return is_wp_error( $value ) ? $value : true;
	}
	if ( '{' === $char ) {
		++$cursor;
		$object_keys = array();
		yotm_data_lifecycle_json_skip_space( $raw, $cursor );
		if ( $cursor < $length && '}' === $raw[ $cursor ] ) {
			++$cursor;

			return true;
		}
		while ( $cursor < $length ) {
			yotm_data_lifecycle_json_skip_space( $raw, $cursor );
			$key = yotm_data_lifecycle_json_string( $raw, $cursor );
			if ( is_wp_error( $key ) ) {
				return $key;
			}
			if ( array_key_exists( $key, $object_keys ) ) {
				return new WP_Error( 'yotm_uninstall_json_duplicate_key', 'An item payload contains an ambiguous duplicate JSON object name.' );
			}
			$object_keys[ $key ] = true;
			$keys[ $key ]        = isset( $keys[ $key ] ) ? $keys[ $key ] + 1 : 1;
			yotm_data_lifecycle_json_skip_space( $raw, $cursor );
			if ( $cursor >= $length || ':' !== $raw[ $cursor++ ] ) {
				return new WP_Error( 'yotm_uninstall_item_payload', 'An item payload contains invalid JSON object syntax.' );
			}
			$value = yotm_data_lifecycle_json_value( $raw, $cursor, $depth + 1, $keys );
			if ( is_wp_error( $value ) ) {
				return $value;
			}
			yotm_data_lifecycle_json_skip_space( $raw, $cursor );
			if ( $cursor < $length && '}' === $raw[ $cursor ] ) {
				++$cursor;

				return true;
			}
			if ( $cursor >= $length || ',' !== $raw[ $cursor++ ] ) {
				return new WP_Error( 'yotm_uninstall_item_payload', 'An item payload contains invalid JSON object separators.' );
			}
		}//end while

		return new WP_Error( 'yotm_uninstall_item_payload', 'An item payload contains an unterminated JSON object.' );
	}//end if
	if ( '[' === $char ) {
		++$cursor;
		yotm_data_lifecycle_json_skip_space( $raw, $cursor );
		if ( $cursor < $length && ']' === $raw[ $cursor ] ) {
			++$cursor;

			return true;
		}
		while ( $cursor < $length ) {
			$value = yotm_data_lifecycle_json_value( $raw, $cursor, $depth + 1, $keys );
			if ( is_wp_error( $value ) ) {
				return $value;
			}
			yotm_data_lifecycle_json_skip_space( $raw, $cursor );
			if ( $cursor < $length && ']' === $raw[ $cursor ] ) {
				++$cursor;

				return true;
			}
			if ( $cursor >= $length || ',' !== $raw[ $cursor++ ] ) {
				return new WP_Error( 'yotm_uninstall_item_payload', 'An item payload contains invalid JSON array separators.' );
			}
		}

		return new WP_Error( 'yotm_uninstall_item_payload', 'An item payload contains an unterminated JSON array.' );
	}//end if

	foreach ( array( 'true', 'false', 'null' ) as $literal ) {
		if ( 0 === strncmp( substr( $raw, $cursor ), $literal, strlen( $literal ) ) ) {
			$cursor += strlen( $literal );

			return true;
		}
	}
	$remaining = substr( $raw, $cursor );
	if ( preg_match( '/^-?(?:0|[1-9][0-9]*)(?:\.[0-9]+)?(?:[eE][+\-]?[0-9]+)?/', $remaining, $number ) ) {
		$cursor += strlen( $number[0] );

		return true;
	}

	return new WP_Error( 'yotm_uninstall_item_payload', 'An item payload contains an invalid JSON value.' );
}

/**
 * Decode one strict item payload with duplicate-name and token evidence.
 *
 * @param string $raw Raw JSON.
 * @return array{payload:array,keys:array<string,int>}|WP_Error
 */
function yotm_data_lifecycle_decode_item_payload( $raw ) {
	if ( ! is_string( $raw ) || '' === $raw || 1048576 < strlen( $raw ) ) {
		return new WP_Error( 'yotm_uninstall_item_payload', 'An item payload exceeds the safe JSON boundary.' );
	}
	$cursor = 0;
	$keys   = array();
	$valid  = yotm_data_lifecycle_json_value( $raw, $cursor, 0, $keys );
	yotm_data_lifecycle_json_skip_space( $raw, $cursor );
	if ( is_wp_error( $valid ) || strlen( $raw ) !== $cursor ) {
		return is_wp_error( $valid ) ? $valid : new WP_Error( 'yotm_uninstall_item_payload', 'An item payload contains trailing JSON data.' );
	}
	$payload = json_decode( $raw, true );
	if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $payload ) ) {
		return new WP_Error( 'yotm_uninstall_item_payload', 'An item payload could not be classified safely.' );
	}

	return array(
		'payload' => $payload,
		'keys'    => $keys,
	);
}
