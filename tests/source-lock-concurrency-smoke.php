<?php
/**
 * Cross-process source-mutation/path-lock smoke worker.
 *
 * @package Thumbnail_Manager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Fail the smoke run when an invariant is not satisfied.
 *
 * @param bool   $condition Assertion condition.
 * @param string $message Failure message.
 * @return void
 * @throws RuntimeException When the assertion fails.
 */
function yotm_source_lock_smoke_assert( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( esc_html( $message ) );
	}
}

/**
 * Read the shared smoke state.
 *
 * @param string $path State file path.
 * @return array
 */
function yotm_source_lock_smoke_state( $path ) {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local runner coordination file.
	$state = json_decode( (string) file_get_contents( $path ), true );
	yotm_source_lock_smoke_assert( is_array( $state ), 'Source-lock smoke state is unavailable.' );
	return $state;
}

/**
 * Persist the shared smoke state.
 *
 * @param string $path State file path.
 * @param array  $state State data.
 * @return void
 */
function yotm_source_lock_smoke_write( $path, $state ) {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Local runner coordination file.
	yotm_source_lock_smoke_assert( false !== file_put_contents( $path, wp_json_encode( $state ) ), 'Could not persist source-lock smoke state.' );
}

/**
 * Map a proposed raw attached file to the protected candidate.
 *
 * @param string|false $file Attached file path.
 * @param int          $attachment_id Attachment ID.
 * @return string|false
 */
function yotm_source_lock_smoke_filter_file( $file, $attachment_id ) {
	$state = $GLOBALS['yotm_source_lock_filter_state'] ?? array();
	if (
		(int) ( $state['other_id'] ?? 0 ) === (int) $attachment_id
		&& wp_normalize_path( (string) ( $state['raw_get'] ?? '' ) ) === wp_normalize_path( (string) $file )
	) {
		return $state['candidate'];
	}
	return $file;
}

/**
 * Map proposed raw attachment metadata to the protected candidate.
 *
 * @param array $metadata Attachment metadata.
 * @param int   $attachment_id Attachment ID.
 * @return array
 */
function yotm_source_lock_smoke_filter_metadata( $metadata, $attachment_id ) {
	$state = $GLOBALS['yotm_source_lock_filter_state'] ?? array();
	if (
		(int) ( $state['other_id'] ?? 0 ) === (int) $attachment_id
		&& ( $metadata['file'] ?? '' ) === ( $state['relative_raw_meta'] ?? '' )
	) {
		$metadata['file'] = $state['relative_candidate'];
	}
	return $metadata;
}

/**
 * Enable deterministic filtered-source mappings for this worker request.
 *
 * @param array $state Shared smoke state.
 * @return void
 */
function yotm_source_lock_smoke_enable_filters( $state ) {
	$GLOBALS['yotm_source_lock_filter_state'] = $state;
	add_filter( 'get_attached_file', 'yotm_source_lock_smoke_filter_file', 10, 2 );
	add_filter( 'wp_get_attachment_metadata', 'yotm_source_lock_smoke_filter_metadata', 10, 2 );
}

$smoke_role = sanitize_key( $args[0] ?? '' );
$state_path = (string) ( $args[1] ?? '' );
$admins     = get_users(
	array(
		'role'   => 'administrator',
		'fields' => 'ids',
		'number' => 1,
	)
);
yotm_source_lock_smoke_assert( '' !== $state_path && ! empty( $admins ), 'Source-lock smoke prerequisites are missing.' );
wp_set_current_user( absint( $admins[0] ) );

if ( 'setup' === $smoke_role ) {
	yotm_source_lock_smoke_assert( true === yotm_install_job_tables(), 'Could not install source-index storage.' );
	$uploads   = wp_get_upload_dir();
	$base      = trailingslashit( $uploads['basedir'] );
	$directory = $base . 'yotm-source-lock-' . wp_generate_uuid4();
	$original  = trailingslashit( $directory ) . 'owner.png';
	$candidate = trailingslashit( $directory ) . 'owner-100x100.png';
	$other     = trailingslashit( $directory ) . 'other.png';
	$raw_get   = trailingslashit( $directory ) . 'other-filter-raw.png';
	$raw_meta  = trailingslashit( $directory ) . 'other-metadata-raw.png';
	$data      = hex2bin( '89504e470d0a1a0a0000000d4948445200000001000000010804000000b51c0c020000000b4944415478da6364f80f00010501012718e3660000000049454e44ae426082' );
	yotm_source_lock_smoke_assert( wp_mkdir_p( $directory ) && is_string( $data ), 'Could not prepare source-lock fixtures.' );
	foreach ( array( $original, $candidate, $other, $raw_get, $raw_meta ) as $fixture_path ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Intentional uploads fixture.
		yotm_source_lock_smoke_assert( strlen( $data ) === file_put_contents( $fixture_path, $data ), 'Could not create a source-lock fixture.' );
	}
	$relative_original  = ltrim( str_replace( wp_normalize_path( $base ), '', wp_normalize_path( $original ) ), '/' );
	$relative_other     = ltrim( str_replace( wp_normalize_path( $base ), '', wp_normalize_path( $other ) ), '/' );
	$relative_candidate = ltrim( str_replace( wp_normalize_path( $base ), '', wp_normalize_path( $candidate ) ), '/' );
	$relative_raw_get   = ltrim( str_replace( wp_normalize_path( $base ), '', wp_normalize_path( $raw_get ) ), '/' );
	$relative_raw_meta  = ltrim( str_replace( wp_normalize_path( $base ), '', wp_normalize_path( $raw_meta ) ), '/' );
	$owner              = wp_insert_attachment(
		array(
			'post_mime_type' => 'image/png',
			'post_status'    => 'inherit',
			'post_title'     => 'YOTM source owner',
		),
		$original
	);
	$other_id           = wp_insert_attachment(
		array(
			'post_mime_type' => 'image/png',
			'post_status'    => 'inherit',
			'post_title'     => 'YOTM source mutator',
		),
		$other
	);
	yotm_source_lock_smoke_assert( $owner && $other_id, 'Could not create source-lock attachments.' );
	update_attached_file( $owner, $relative_original );
	update_attached_file( $other_id, $relative_other );
	yotm_source_lock_smoke_assert(
		false !== wp_update_attachment_metadata(
			$owner,
			array(
				'file'  => $relative_original,
				'sizes' => array(
					'yotm_smoke' => array(
						'file'      => wp_basename( $candidate ),
						'width'     => 100,
						'height'    => 100,
						'mime-type' => 'image/png',
					),
				),
			)
		),
		'Could not persist owner metadata.'
	);
	yotm_source_lock_smoke_assert(
		false !== wp_update_attachment_metadata(
			$other_id,
			array(
				'file'  => $relative_other,
				'sizes' => array(),
			)
		),
		'Could not persist mutator metadata.'
	);
	yotm_media_source_sync_attachment( $owner );
	yotm_media_source_sync_attachment( $other_id );

	$job = yotm_job_create(
		'prune',
		array(
			'base'                  => $base,
			'ownership_schema'      => 'generated_file_v1',
			'source_index_complete' => 1,
		),
		array(
			'status'       => 'scanning',
			'phase'        => 'metadata',
			'counter_mode' => 'item_v2',
		)
	);
	yotm_source_lock_smoke_assert( is_array( $job ), 'Could not create source-lock prune job.' );
	$payload  = array(
		'path'               => $candidate,
		'attachment_id'      => $owner,
		'size'               => 'yotm_smoke',
		'remove_metadata'    => 1,
		'ownership_schema'   => 'generated_file_v1',
		'ownership'          => 'metadata_size',
		'metadata_refs'      => array(
			array(
				'attachment_id' => $owner,
				'size'          => 'yotm_smoke',
				'filename'      => wp_basename( $candidate ),
			),
		),
		'ownership_evidence' => array(
			array(
				'attachment_id' => $owner,
				'size'          => 'yotm_smoke',
				'filename'      => wp_basename( $candidate ),
				'mime'          => 'image/png',
				'selection'     => 'registered_remove',
			),
		),
	);
	$item_key = hash( 'sha256', wp_normalize_path( $candidate ) );
	yotm_source_lock_smoke_assert( yotm_job_add_item( $job['id'], $item_key, $payload ), 'Could not persist source-lock item.' );
	yotm_source_lock_smoke_assert(
		yotm_job_update(
			$job['id'],
			array(
				'status' => 'deleting',
				'phase'  => 'delete',
				'total'  => 1,
			)
		),
		'Could not arm source-lock job.'
	);
	global $wpdb;
	$meta_id         = (int) $wpdb->get_var( $wpdb->prepare( "SELECT meta_id FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = '_wp_attached_file' LIMIT 1", $other_id ) );
	$state           = compact( 'directory', 'original', 'candidate', 'other', 'raw_get', 'raw_meta', 'owner', 'other_id', 'relative_other', 'relative_candidate', 'relative_raw_get', 'relative_raw_meta', 'meta_id', 'item_key' );
	$state['job_id'] = $job['id'];
	$state['token']  = $job['token'];
	yotm_source_lock_smoke_write( $state_path, $state );
	echo "Source-lock fixture ready.\n";
	return;
}//end if

$state = yotm_source_lock_smoke_state( $state_path );

if ( 'hold_baseline' === $smoke_role ) {
	$barrier = static function ( $attachment_id, $aliases, $attachment_lock ) use ( $state_path, $state ) {
		unset( $attachment_id, $aliases, $attachment_lock );
		$state['baseline_ready'] = 1;
		yotm_source_lock_smoke_write( $state_path, $state );
		sleep( 3 );
	};
	$result  = yotm_media_source_sync_attachment( $state['other_id'], $barrier );
	yotm_source_lock_smoke_assert( true === $result, 'Held baseline could not complete after the contended mutation.' );
	yotm_source_lock_smoke_assert( true === yotm_media_source_path_is_authoritative( $state['other'] ), 'Held baseline lost the original source.' );
	yotm_source_lock_smoke_assert( false === yotm_media_source_path_is_authoritative( $state['candidate'] ), 'Held baseline installed the blocked candidate.' );
	echo "S-first baseline completed without a stale replacement.\n";
	return;
}

if ( 'contend_baseline' === $smoke_role ) {
	$before = get_post_meta( $state['other_id'], '_wp_attached_file', true );
	yotm_source_lock_smoke_assert( false === update_metadata_by_mid( 'post', $state['meta_id'], $state['relative_candidate'], false ), 'By-mid mutation bypassed the held attachment baseline lock.' );
	yotm_source_lock_smoke_assert( isset( $GLOBALS['yotm_media_source_last_error'] ) && is_wp_error( $GLOBALS['yotm_media_source_last_error'] ), 'Contended baseline mutation did not report a guard error.' );
	yotm_source_lock_smoke_assert( 'yotm_media_attachment_busy' === $GLOBALS['yotm_media_source_last_error']->get_error_code(), 'Contended baseline mutation reported the wrong lock error.' );
	yotm_source_lock_smoke_assert( get_post_meta( $state['other_id'], '_wp_attached_file', true ) === $before, 'Contended baseline mutation changed the authoritative row.' );
	echo "S-first contention: source mutation blocked by attachment lock.\n";
	return;
}

if ( 'promote_source' === $smoke_role ) {
	yotm_source_lock_smoke_assert( true === update_metadata_by_mid( 'post', $state['meta_id'], $state['relative_candidate'], false ), 'M-first source promotion failed.' );
	yotm_source_lock_smoke_assert( true === yotm_media_source_path_is_authoritative( $state['candidate'] ), 'M-first source promotion was not indexed.' );
	echo "M-first mutation committed and indexed.\n";
	return;
}

if ( 'baseline_after_promotion' === $smoke_role ) {
	yotm_source_lock_smoke_assert( true === yotm_media_source_sync_attachment( $state['other_id'] ), 'M-first follow-up baseline failed.' );
	yotm_source_lock_smoke_assert( true === yotm_media_source_path_is_authoritative( $state['candidate'] ), 'M-first follow-up baseline removed the committed source.' );
	echo "M-first baseline retained the committed source.\n";
	return;
}

if ( 'reset_source' === $smoke_role ) {
	yotm_source_lock_smoke_assert( true === update_metadata_by_mid( 'post', $state['meta_id'], $state['relative_other'], false ), 'Could not reset the source-lock fixture.' );
	yotm_source_lock_smoke_assert( false === yotm_media_source_path_is_authoritative( $state['candidate'] ), 'Reset source still protects the candidate.' );
	echo "Source-lock fixture reset.\n";
	return;
}

if ( 'hold_delete' === $smoke_role ) {
	yotm_source_lock_smoke_enable_filters( $state );
	global $wpdb;
	$job    = yotm_job_get( $state['token'] );
	$worker = yotm_job_acquire_worker( $job['id'], array( 'deleting' ), array( 'delete' ) );
	yotm_source_lock_smoke_assert( is_array( $worker ), 'Delete holder could not acquire the worker.' );
	$items = yotm_job_claim_items( $worker, 1 );
	yotm_source_lock_smoke_assert( is_array( $items ) && 1 === count( $items ), 'Delete holder could not claim the item.' );
	$tables  = yotm_job_table_names();
	$barrier = static function ( $item, $current_job, $current_worker ) use ( $wpdb, $tables, $state_path, $state ) {
		unset( $current_worker );
		$past = gmdate( 'Y-m-d H:i:s', time() - MINUTE_IN_SECONDS );
		$wpdb->update( $tables['jobs'], array( 'worker_lease_expires_at' => $past ), array( 'id' => $current_job['id'] ) );
		$wpdb->update( $tables['items'], array( 'claim_expires_at' => $past ), array( 'id' => $item['id'] ) );
		$state['holder_ready'] = 1;
		yotm_source_lock_smoke_write( $state_path, $state );
		sleep( 30 );
	};
	yotm_process_claimed_prune_item( $items[0], $job, $worker, $job['payload']['base'], $barrier );
	throw new RuntimeException( 'Delete holder crossed the source-lock barrier.' );
}//end if

if ( 'contend' === $smoke_role ) {
	yotm_source_lock_smoke_enable_filters( $state );
	$before_file     = get_post_meta( $state['other_id'], '_wp_attached_file', true );
	$before_metadata = get_post_meta( $state['other_id'], '_wp_attachment_metadata', true );
	yotm_source_lock_smoke_assert( false === update_metadata_by_mid( 'post', $state['meta_id'], $state['relative_raw_get'], false ), 'By-mid filtered-file update bypassed the held source fence.' );
	yotm_source_lock_smoke_assert( is_wp_error( $GLOBALS['yotm_media_source_last_error'] ?? null ) && 'yotm_media_source_fence_busy' === $GLOBALS['yotm_media_source_last_error']->get_error_code(), 'D-first filtered-file mutation returned the wrong fence error.' );
	require_once ABSPATH . 'wp-admin/includes/post.php';
	yotm_source_lock_smoke_assert( false === update_meta( $state['meta_id'], '_wp_attached_file', wp_slash( $state['relative_raw_get'] ) ), 'Legacy filtered-file update bypassed the held source fence.' );
	$proposed_metadata = array(
		'file'  => $state['relative_raw_meta'],
		'sizes' => array(),
	);
	yotm_source_lock_smoke_assert( false === wp_update_attachment_metadata( $state['other_id'], $proposed_metadata ), 'Filtered-metadata update bypassed the held source fence.' );
	yotm_source_lock_smoke_assert( get_post_meta( $state['other_id'], '_wp_attached_file', true ) === $before_file, 'Contended filtered-file mutation changed the authoritative row.' );
	yotm_source_lock_smoke_assert( get_post_meta( $state['other_id'], '_wp_attachment_metadata', true ) === $before_metadata, 'Contended filtered-metadata mutation changed attachment metadata.' );
	yotm_source_lock_smoke_assert( file_exists( $state['candidate'] ), 'Contended mutation/delete changed the candidate.' );
	echo "D-first contention: filtered file and metadata mutations blocked by source fence.\n";
	return;
}

if ( 'promote_then_delete' === $smoke_role ) {
	yotm_source_lock_smoke_enable_filters( $state );
	yotm_source_lock_smoke_assert( true === update_metadata_by_mid( 'post', $state['meta_id'], $state['relative_raw_get'], false ), 'M-first filtered-file promotion failed after holder exit.' );
	yotm_source_lock_smoke_assert( true === yotm_media_source_path_is_authoritative( $state['candidate'] ), 'M-first filtered-file promotion was not indexed.' );
	$job    = yotm_job_get( $state['token'] );
	$worker = yotm_job_acquire_worker( $job['id'], array( 'deleting' ), array( 'delete' ) );
	yotm_source_lock_smoke_assert( is_array( $worker ), 'Delete worker could not recover the abandoned worker.' );
	$items = yotm_job_claim_items( $worker, 1 );
	yotm_source_lock_smoke_assert( is_array( $items ) && 1 === count( $items ), 'Delete worker could not recover the abandoned item.' );
	$result = yotm_process_claimed_prune_item( $items[0], $job, $worker, $job['payload']['base'] );
	yotm_source_lock_smoke_assert( is_array( $result ) && ! empty( $result['skipped'] ), 'Delete worker did not veto the filtered-file source.' );
	yotm_source_lock_smoke_assert( file_exists( $state['candidate'] ), 'Filtered-file source was deleted.' );
	yotm_source_lock_smoke_assert( isset( wp_get_attachment_metadata( $state['owner'] )['sizes']['yotm_smoke'] ), 'Protected candidate metadata was reconciled.' );
	$payload = $items[0]['payload'];
	yotm_job_finish_item( $items[0], 'skipped', $result['message'] );
	yotm_job_release_worker( $worker );
	global $wpdb;
	$canonical = yotm_media_source_canonical_path( $state['candidate'] );
	$free      = $wpdb->get_var( $wpdb->prepare( 'SELECT IS_FREE_LOCK(%s)', yotm_media_path_lock_name( $canonical ) ) );
	yotm_source_lock_smoke_assert( '1' === (string) $free, 'Candidate path lock leaked after source veto.' );
	yotm_source_lock_smoke_assert( true === update_metadata_by_mid( 'post', $state['meta_id'], $state['relative_other'], false ), 'Could not reset the filtered-file source.' );
	yotm_source_lock_smoke_assert( true === yotm_media_source_sync_attachment( $state['other_id'] ), 'Could not resync the reset filtered-file source.' );
	yotm_source_lock_smoke_assert( false === yotm_media_source_path_is_authoritative( $state['candidate'] ), 'Reset filtered-file source still protects the candidate.' );
	yotm_job_delete( $job['id'] );
	yotm_source_lock_smoke_assert( false === yotm_job_get_by_id( $job['id'] ), 'Could not remove the first filtered-source job.' );
	$next_job = yotm_job_create(
		'prune',
		array(
			'base'                  => $job['payload']['base'],
			'ownership_schema'      => 'generated_file_v1',
			'source_index_complete' => 1,
		),
		array(
			'status'       => 'scanning',
			'phase'        => 'metadata',
			'counter_mode' => 'item_v2',
		)
	);
	yotm_source_lock_smoke_assert( is_array( $next_job ), 'Could not create the filtered-metadata prune job.' );
	yotm_source_lock_smoke_assert( yotm_job_add_item( $next_job['id'], $state['item_key'], $payload ), 'Could not add the filtered-metadata prune item.' );
	yotm_source_lock_smoke_assert(
		yotm_job_update(
			$next_job['id'],
			array(
				'status' => 'deleting',
				'phase'  => 'delete',
				'total'  => 1,
			)
		),
		'Could not arm the filtered-metadata prune job.'
	);
	$state['job_id'] = $next_job['id'];
	$state['token']  = $next_job['token'];
	yotm_source_lock_smoke_write( $state_path, $state );
	echo "M-first filtered-file promotion: live veto preserved the candidate and locks released.\n";
	return;
}//end if

if ( 'promote_metadata_then_delete' === $smoke_role ) {
	yotm_source_lock_smoke_enable_filters( $state );
	$proposed_metadata = array(
		'file'  => $state['relative_raw_meta'],
		'sizes' => array(),
	);
	yotm_source_lock_smoke_assert( false !== wp_update_attachment_metadata( $state['other_id'], $proposed_metadata ), 'M-first filtered-metadata promotion failed.' );
	yotm_source_lock_smoke_assert( true === yotm_media_source_path_is_authoritative( $state['candidate'] ), 'M-first filtered-metadata promotion was not indexed.' );
	$job    = yotm_job_get( $state['token'] );
	$worker = yotm_job_acquire_worker( $job['id'], array( 'deleting' ), array( 'delete' ) );
	yotm_source_lock_smoke_assert( is_array( $worker ), 'Filtered-metadata delete worker could not acquire the job.' );
	$items = yotm_job_claim_items( $worker, 1 );
	yotm_source_lock_smoke_assert( is_array( $items ) && 1 === count( $items ), 'Filtered-metadata delete worker could not claim the item.' );
	$result = yotm_process_claimed_prune_item( $items[0], $job, $worker, $job['payload']['base'] );
	yotm_source_lock_smoke_assert( is_array( $result ) && ! empty( $result['skipped'] ), 'Delete worker did not veto the filtered-metadata source.' );
	yotm_source_lock_smoke_assert( file_exists( $state['candidate'] ), 'Filtered-metadata source was deleted.' );
	yotm_source_lock_smoke_assert( isset( wp_get_attachment_metadata( $state['owner'] )['sizes']['yotm_smoke'] ), 'Filtered-metadata veto reconciled protected metadata.' );
	yotm_job_finish_item( $items[0], 'skipped', $result['message'] );
	yotm_job_release_worker( $worker );
	global $wpdb;
	$canonical  = yotm_media_source_canonical_path( $state['candidate'] );
	$path_free  = $wpdb->get_var( $wpdb->prepare( 'SELECT IS_FREE_LOCK(%s)', yotm_media_path_lock_name( $canonical ) ) );
	$fence_free = $wpdb->get_var( $wpdb->prepare( 'SELECT IS_FREE_LOCK(%s)', yotm_media_source_fence_lock_name() ) );
	yotm_source_lock_smoke_assert( '1' === (string) $path_free && '1' === (string) $fence_free, 'Filtered-metadata veto leaked a source or path lock.' );
	echo "M-first filtered-metadata promotion: live veto preserved the candidate and locks released.\n";
	return;
}//end if

if ( 'cleanup' === $smoke_role ) {
	update_attached_file( $state['other_id'], $state['relative_other'] );
	foreach ( array( 'owner', 'other_id' ) as $id_key ) {
		if ( get_post( $state[ $id_key ] ) ) {
			wp_delete_attachment( $state[ $id_key ], true );
		}
	}
	if ( ! empty( $state['job_id'] ) && yotm_job_get_by_id( $state['job_id'] ) ) {
		yotm_job_delete( $state['job_id'] );
	}
	foreach ( array( 'candidate', 'original', 'other', 'raw_get', 'raw_meta' ) as $path_key ) {
		if ( file_exists( $state[ $path_key ] ) ) {
			wp_delete_file( $state[ $path_key ] );
		}
	}
	if ( is_dir( $state['directory'] ) ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Intentional smoke fixture cleanup.
		rmdir( $state['directory'] );
	}
	return;
}//end if

throw new RuntimeException( 'Unknown source-lock smoke role.' );
