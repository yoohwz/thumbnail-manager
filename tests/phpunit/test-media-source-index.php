<?php

/**
 * Exact prune ownership, authoritative source indexing, and path-lock tests.
 */
class YOTM_Media_Source_Index_Test extends WP_UnitTestCase {
	/** @var string */
	private $uploads_base;

	/** @var string */
	private $test_dir;

	/** @var int[] */
	private $attachments = array();

	/** @var string[] */
	private $files = array();

	/** @var int */
	private $named_lock_attempts = 0;

	/** @var string[] */
	private $named_lock_names = array();

	public function setUp(): void {
		parent::setUp();
		unset( $GLOBALS['yotm_job_storage_readiness'], $GLOBALS['yotm_media_source_last_error'] );
		yotm_media_source_shutdown_cleanup();
		$this->assertTrue( yotm_run_job_table_migration() );
		$uploads            = wp_get_upload_dir();
		$this->uploads_base = trailingslashit( $uploads['basedir'] );
		$this->test_dir     = $this->uploads_base . 'yotm-source-' . wp_generate_uuid4();
		wp_mkdir_p( $this->test_dir );
		yotm_media_source_clear_index();
	}

	public function tearDown(): void {
		remove_filter( 'query', array( $this, 'force_named_lock_contention' ) );
		remove_filter( 'query', array( $this, 'fail_second_named_lock' ) );
		remove_filter( 'query', array( $this, 'force_source_upsert_failure' ) );
		remove_filter( 'query', array( $this, 'force_source_resync_failure' ) );
		remove_filter( 'update_post_metadata', array( $this, 'short_circuit_source_update' ), PHP_INT_MAX );
		yotm_media_source_shutdown_cleanup();
		foreach ( array_reverse( $this->attachments ) as $attachment_id ) {
			wp_delete_post( $attachment_id, true );
		}
		foreach ( array_unique( $this->files ) as $file ) {
			if ( is_link( $file ) || file_exists( $file ) ) {
				@unlink( $file );
			}
		}
		if ( is_dir( $this->test_dir ) ) {
			@rmdir( $this->test_dir );
		}
		parent::tearDown();
	}

	public function test_only_exact_metadata_file_becomes_a_candidate() {
		$fixture = $this->create_attachment_with_thumbnail( 'exact.jpg', 'exact-150x150.webp', 'image/webp' );
		$sidecar = $fixture['thumbnail'] . '.avif';
		$backup  = preg_replace( '/\.webp$/', '.bak.webp', $fixture['thumbnail'] );
		$this->write_file( $sidecar, 'sidecar' );
		$this->write_file( $backup, 'backup' );

		$candidates = $this->collect_candidates( $fixture['attachment_id'] );
		$this->assertCount( 1, $candidates );
		$this->assertArrayHasKey( yotm_normalize_filesystem_path( $fixture['thumbnail'] ), $candidates );
		$candidate = reset( $candidates );
		$this->assertSame( 'generated_file_v1', $candidate['ownership_schema'] );
		$this->assertSame( 'metadata_size', $candidate['ownership'] );
		$this->assertSame( 'image/webp', $candidate['ownership_evidence'][0]['mime'] );
		$this->assertArrayNotHasKey( yotm_normalize_filesystem_path( $sidecar ), $candidates );
		$this->assertArrayNotHasKey( yotm_normalize_filesystem_path( $backup ), $candidates );
	}

	public function test_exact_avif_metadata_output_remains_eligible() {
		$fixture    = $this->create_attachment_with_thumbnail( 'exact-avif.jpg', 'exact-avif-150x150.avif', 'image/avif' );
		$candidates = $this->collect_candidates( $fixture['attachment_id'] );
		$this->assertCount( 1, $candidates );
		$candidate = reset( $candidates );
		$this->assertSame( yotm_normalize_filesystem_path( $fixture['thumbnail'] ), $candidate['path'] );
		$this->assertSame( 'image/avif', $candidate['ownership_evidence'][0]['mime'] );
	}

	public function test_cross_attachment_source_is_excluded_even_outside_candidate_attachment() {
		$fixture = $this->create_attachment_with_thumbnail( 'owner.jpg', 'owner-150x150.jpg' );
		$source  = $this->create_attachment( $fixture['thumbnail'], array( 'file' => $this->relative_path( $fixture['thumbnail'] ) ) );
		$this->assertTrue( yotm_media_source_sync_attachment( $source ) );

		$this->assertTrue( yotm_media_source_path_is_authoritative( $fixture['thumbnail'] ) );
		$this->assertSame( array(), $this->collect_candidates( $fixture['attachment_id'] ) );
	}

	public function test_source_baseline_is_bounded_resumable_and_completes_before_metadata_phase() {
		$first  = $this->create_attachment_with_thumbnail( 'batch-first.jpg', 'batch-first-150x150.jpg' );
		$second = $this->create_attachment_with_thumbnail( 'batch-second.jpg', 'batch-second-150x150.jpg' );
		$this->assertTrue( yotm_media_source_clear_index() );
		$job    = yotm_job_create(
			'prune',
			array(
				'scan_phase'               => 'source_index',
				'source_index_initialized' => 0,
				'source_index_complete'    => 0,
				'source_index_cursor'      => 0,
				'source_index_max_id'      => max( $first['attachment_id'], $second['attachment_id'] ),
			),
			array(
				'status' => 'scanning',
				'phase'  => 'source_index',
			)
		);
		$worker = yotm_job_acquire_worker( $job['id'], array( 'scanning' ), array( 'source_index', 'metadata' ) );
		$this->assertIsArray( $worker );
		$after_prepare = $this->create_attachment_with_thumbnail( 'batch-after-prepare.jpg', 'batch-after-prepare-150x150.jpg' );

		$first_batch = yotm_prune_source_index_batch( $job, 1, $worker );
		$this->assertFalse( $first_batch['done'] );
		$this->assertGreaterThan( 0, $first_batch['job']['payload']['source_index_cursor'] );
		$this->assertGreaterThanOrEqual( $after_prepare['attachment_id'], $first_batch['job']['payload']['source_index_max_id'] );
		$current = $first_batch['job'];
		do {
			$batch   = yotm_prune_source_index_batch( $current, 1, $worker );
			$current = $batch['job'];
		} while ( ! $batch['done'] );

		$this->assertSame( 'metadata', $current['phase'] );
		$this->assertSame( 1, $current['payload']['source_index_complete'] );
		$this->assertTrue( yotm_media_source_path_is_authoritative( $first['original'] ) );
		$this->assertTrue( yotm_media_source_path_is_authoritative( $second['original'] ) );
		$this->assertTrue( yotm_media_source_path_is_authoritative( $after_prepare['original'] ) );
		yotm_job_release_worker( $worker );
	}

	public function test_reused_size_key_is_preserved_after_successful_delete() {
		$fixture  = $this->create_attachment_with_thumbnail( 'changed.jpg', 'changed-150x150.jpg' );
		$new_file = trailingslashit( $this->test_dir ) . 'changed-new-150x150.jpg';
		$this->write_file( $new_file, 'new' );
		$candidates                             = $this->collect_candidates( $fixture['attachment_id'] );
		$candidate                              = reset( $candidates );
		$metadata                               = wp_get_attachment_metadata( $fixture['attachment_id'] );
		$metadata['sizes']['thumbnail']['file'] = wp_basename( $new_file );
		$this->assertNotFalse( wp_update_attachment_metadata( $fixture['attachment_id'], $metadata ) );

		$result = yotm_delete_prune_item( $candidate, $this->uploads_base );
		$this->assertTrue( $result['deleted'] );
		$this->assertSame( wp_basename( $new_file ), wp_get_attachment_metadata( $fixture['attachment_id'] )['sizes']['thumbnail']['file'] );
	}

	public function test_reused_size_key_is_preserved_when_candidate_is_already_missing() {
		$fixture  = $this->create_attachment_with_thumbnail( 'missing.jpg', 'missing-150x150.jpg' );
		$new_file = trailingslashit( $this->test_dir ) . 'missing-new-150x150.jpg';
		$this->write_file( $new_file, 'new' );
		$candidates                             = $this->collect_candidates( $fixture['attachment_id'] );
		$candidate                              = reset( $candidates );
		$metadata                               = wp_get_attachment_metadata( $fixture['attachment_id'] );
		$metadata['sizes']['thumbnail']['file'] = wp_basename( $new_file );
		$this->assertNotFalse( wp_update_attachment_metadata( $fixture['attachment_id'], $metadata ) );
		unlink( $fixture['thumbnail'] );

		$result = yotm_delete_prune_item( $candidate, $this->uploads_base );
		$this->assertTrue( $result['skipped'] );
		$this->assertSame( wp_basename( $new_file ), wp_get_attachment_metadata( $fixture['attachment_id'] )['sizes']['thumbnail']['file'] );
	}

	public function test_live_source_promotion_vetoes_claimed_prune_item() {
		$fixture    = $this->create_attachment_with_thumbnail( 'promote.jpg', 'promote-150x150.jpg' );
		$candidates = $this->collect_candidates( $fixture['attachment_id'] );
		$candidate  = reset( $candidates );
		$other      = $this->create_attachment_with_thumbnail( 'other.jpg', 'other-150x150.jpg' );

		$job = yotm_job_create(
			'prune',
			array(
				'base'                  => $this->uploads_base,
				'ownership_schema'      => 'generated_file_v1',
				'source_index_complete' => 1,
			),
			array(
				'status' => 'scanning',
				'phase'  => 'metadata',
			)
		);
		$this->assertTrue( yotm_job_add_item( $job['id'], hash( 'sha256', $candidate['path'] ), $candidate ) );
		yotm_job_update(
			$job['id'],
			array(
				'status' => 'deleting',
				'phase'  => 'delete',
			)
		);
		$this->assertNotFalse( update_attached_file( $other['attachment_id'], $fixture['thumbnail'] ) );

		$worker = yotm_job_acquire_worker( $job['id'], array( 'deleting' ), array( 'delete' ) );
		$this->assertIsArray( $worker );
		$item   = yotm_job_claim_items( $worker, 1 )[0];
		$result = yotm_process_claimed_prune_item( $item, yotm_job_get_by_id( $job['id'] ), $worker, $this->uploads_base );
		$this->assertTrue( $result['skipped'] );
		$this->assertFileExists( $fixture['thumbnail'] );
		$this->assertArrayHasKey( 'thumbnail', wp_get_attachment_metadata( $fixture['attachment_id'] )['sizes'] );
		yotm_job_release_worker( $worker );
	}

	public function test_prune_path_lock_contention_is_retryable_without_side_effects() {
		$fixture    = $this->create_attachment_with_thumbnail( 'delete-busy.jpg', 'delete-busy-150x150.jpg' );
		$candidates = $this->collect_candidates( $fixture['attachment_id'] );
		$candidate  = reset( $candidates );
		$job        = yotm_job_create(
			'prune',
			array(
				'base'                  => $this->uploads_base,
				'ownership_schema'      => 'generated_file_v1',
				'source_index_complete' => 1,
			),
			array(
				'status' => 'scanning',
				'phase'  => 'metadata',
			)
		);
		$this->assertTrue( yotm_job_add_item( $job['id'], hash( 'sha256', $candidate['path'] ), $candidate ) );
		$this->assertTrue(
			yotm_job_update(
				$job['id'],
				array(
					'status' => 'deleting',
					'phase'  => 'delete',
					'total'  => 1,
				)
			)
		);
		$worker = yotm_job_acquire_worker( $job['id'], array( 'deleting' ), array( 'delete' ) );
		$this->assertIsArray( $worker );
		$item = yotm_job_claim_items( $worker, 1 )[0];
		add_filter( 'query', array( $this, 'force_named_lock_contention' ) );
		$result = yotm_process_claimed_prune_item( $item, yotm_job_get_by_id( $job['id'] ), $worker, $this->uploads_base );
		remove_filter( 'query', array( $this, 'force_named_lock_contention' ) );
		$this->assertWPError( $result );
		$this->assertSame( 'yotm_media_path_busy', $result->get_error_code() );
		$this->assertFileExists( $fixture['thumbnail'] );
		$this->assertArrayHasKey( 'thumbnail', wp_get_attachment_metadata( $fixture['attachment_id'] )['sizes'] );
		yotm_job_release_item_claim( $item );
		yotm_job_release_worker( $worker );
	}

	public function test_by_mid_source_update_fails_closed_on_path_lock_contention() {
		$fixture = $this->create_attachment_with_thumbnail( 'bymid.jpg', 'bymid-150x150.jpg' );
		global $wpdb;
		$meta_id = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT meta_id FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = '_wp_attached_file' LIMIT 1", $fixture['attachment_id'] )
		);
		$before  = get_post_meta( $fixture['attachment_id'], '_wp_attached_file', true );
		add_filter( 'query', array( $this, 'force_named_lock_contention' ) );
		$this->assertFalse( update_metadata_by_mid( 'post', $meta_id, $this->relative_path( $fixture['thumbnail'] ), false ) );
		remove_filter( 'query', array( $this, 'force_named_lock_contention' ) );
		$this->assertSame( $before, get_post_meta( $fixture['attachment_id'], '_wp_attached_file', true ) );
	}

	public function test_successful_by_mid_and_legacy_updates_are_indexed() {
		$fixture = $this->create_attachment_with_thumbnail( 'legacy.jpg', 'legacy-150x150.jpg' );
		yotm_media_source_shutdown_cleanup();
		global $wpdb;
		$meta_id = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT meta_id FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = '_wp_attached_file' LIMIT 1", $fixture['attachment_id'] )
		);
		$this->assertTrue( update_metadata_by_mid( 'post', $meta_id, $this->relative_path( $fixture['thumbnail'] ), false ) );
		$this->assertTrue( yotm_media_source_path_is_authoritative( $fixture['thumbnail'] ) );
		$this->assertEmpty( $GLOBALS['yotm_media_source_frames'] );
		$this->assertEmpty( $GLOBALS['yotm_media_path_locks'] );

		require_once ABSPATH . 'wp-admin/includes/post.php';
		$this->assertTrue( update_meta( $meta_id, '_wp_attached_file', wp_slash( $this->relative_path( $fixture['original'] ) ) ) );
		$this->assertTrue( yotm_media_source_path_is_authoritative( $fixture['original'] ) );
		$this->assertEmpty( $GLOBALS['yotm_media_source_frames'] );
		$this->assertEmpty( $GLOBALS['yotm_media_path_locks'] );
	}

	public function test_by_mid_key_replacement_into_and_away_from_authoritative_key_resynchronizes() {
		$fixture = $this->create_attachment_with_thumbnail( 'rekey.jpg', 'rekey-150x150.jpg' );
		$meta_id = add_post_meta( $fixture['attachment_id'], 'yotm_ordinary', 'ordinary' );
		$this->assertIsInt( $meta_id );
		$this->assertTrue(
			update_metadata_by_mid(
				'post',
				$meta_id,
				$this->relative_path( $fixture['thumbnail'] ),
				'_wp_attached_file'
			)
		);
		$this->assertTrue( yotm_media_source_path_is_authoritative( $fixture['thumbnail'] ) );
		$this->assertTrue( update_metadata_by_mid( 'post', $meta_id, 'ordinary-again', 'yotm_ordinary' ) );
		$this->assertFalse( yotm_media_source_path_is_authoritative( $fixture['thumbnail'] ) );
	}

	public function test_source_alias_fanout_fails_closed() {
		$path = trailingslashit( $this->test_dir ) . 'fanout.jpg';
		$this->write_file( $path, 'fanout' );
		$canonical = yotm_media_source_canonical_path( $path );
		$this->assertIsString( $canonical );
		$aliases = array();
		for ( $index = 1; $index <= YOTM_MEDIA_SOURCE_FANOUT_LIMIT + 1; ++$index ) {
			$aliases[] = array(
				'attachment_id' => 100000 + $index,
				'source_kind'   => 'attached',
				'path_hash'     => hash( 'sha256', $canonical ),
				'path'          => $canonical,
			);
		}
		$this->assertTrue( yotm_media_source_upsert_aliases( $aliases ) );
		$result = yotm_media_source_path_is_authoritative( $canonical );
		$this->assertWPError( $result );
		$this->assertSame( 'yotm_media_source_fanout', $result->get_error_code() );
	}

	public function test_invalid_by_mid_target_and_non_string_replacement_key_fail_closed() {
		$this->assertFalse( update_metadata_by_mid( 'post', 999999999, 'candidate.jpg', false ) );
		$fixture = $this->create_attachment_with_thumbnail( 'invalid-mid.jpg', 'invalid-mid-150x150.jpg' );
		global $wpdb;
		$meta_id = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT meta_id FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = '_wp_attached_file' LIMIT 1", $fixture['attachment_id'] )
		);
		$before  = get_post_meta( $fixture['attachment_id'], '_wp_attached_file', true );
		$this->assertFalse( update_metadata_by_mid( 'post', $meta_id, $this->relative_path( $fixture['thumbnail'] ), array( '_wp_attached_file' ) ) );
		$this->assertSame( $before, get_post_meta( $fixture['attachment_id'], '_wp_attached_file', true ) );
	}

	public function test_unresolvable_source_path_aborts_standard_metadata_update() {
		$fixture = $this->create_attachment_with_thumbnail( 'outside.jpg', 'outside-150x150.jpg' );
		$before  = get_post_meta( $fixture['attachment_id'], '_wp_attached_file', true );
		$outside = trailingslashit( sys_get_temp_dir() ) . 'yotm-outside-' . wp_generate_uuid4() . '.jpg';
		$this->assertFalse( update_post_meta( $fixture['attachment_id'], '_wp_attached_file', $outside ) );
		$this->assertSame( $before, get_post_meta( $fixture['attachment_id'], '_wp_attached_file', true ) );
		$this->assertWPError( $GLOBALS['yotm_media_source_last_error'] );
	}

	public function test_provisional_index_failure_aborts_source_mutation_and_releases_locks() {
		$fixture = $this->create_attachment_with_thumbnail( 'upsert.jpg', 'upsert-150x150.jpg' );
		$before  = get_post_meta( $fixture['attachment_id'], '_wp_attached_file', true );
		yotm_media_source_shutdown_cleanup();
		global $wpdb;
		$suppressing = $wpdb->suppress_errors();
		add_filter( 'query', array( $this, 'force_source_upsert_failure' ) );
		$this->assertFalse( update_post_meta( $fixture['attachment_id'], '_wp_attached_file', $this->relative_path( $fixture['thumbnail'] ) ) );
		remove_filter( 'query', array( $this, 'force_source_upsert_failure' ) );
		$wpdb->suppress_errors( $suppressing );
		$this->assertSame( $before, get_post_meta( $fixture['attachment_id'], '_wp_attached_file', true ) );
		$this->assertEmpty( $GLOBALS['yotm_media_path_locks'] );
		$wpdb->last_error = '';
	}

	public function test_later_short_circuit_retains_positive_rows_until_shutdown_cleanup() {
		$fixture = $this->create_attachment_with_thumbnail( 'short-circuit.jpg', 'short-circuit-150x150.jpg' );
		$before  = get_post_meta( $fixture['attachment_id'], '_wp_attached_file', true );
		add_filter( 'update_post_metadata', array( $this, 'short_circuit_source_update' ), PHP_INT_MAX, 5 );
		$this->assertFalse( update_post_meta( $fixture['attachment_id'], '_wp_attached_file', $this->relative_path( $fixture['thumbnail'] ) ) );
		remove_filter( 'update_post_metadata', array( $this, 'short_circuit_source_update' ), PHP_INT_MAX );
		$this->assertSame( $before, get_post_meta( $fixture['attachment_id'], '_wp_attached_file', true ) );
		$this->assertNotEmpty( $GLOBALS['yotm_media_source_frames'] );
		$lock_names = array_keys( $GLOBALS['yotm_media_path_locks'] );
		global $wpdb;
		$table = yotm_job_table_names()['sources'];
		$hash  = hash( 'sha256', yotm_media_source_canonical_path( $fixture['thumbnail'] ) );
		$this->assertGreaterThan(
			0,
			(int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE path_hash = %s", $hash ) )
		);
		yotm_media_source_shutdown_cleanup();
		foreach ( $lock_names as $lock_name ) {
			$this->assertSame( '1', (string) $wpdb->get_var( $wpdb->prepare( 'SELECT IS_FREE_LOCK(%s)', $lock_name ) ) );
		}
	}

	public function test_post_write_resync_failure_retains_positive_rows_and_locks_until_shutdown() {
		$fixture = $this->create_attachment_with_thumbnail( 'resync.jpg', 'resync-150x150.jpg' );
		yotm_media_source_shutdown_cleanup();
		global $wpdb;
		$suppressing = $wpdb->suppress_errors();
		add_filter( 'query', array( $this, 'force_source_resync_failure' ) );
		$this->assertNotFalse( update_post_meta( $fixture['attachment_id'], '_wp_attached_file', $this->relative_path( $fixture['thumbnail'] ) ) );
		remove_filter( 'query', array( $this, 'force_source_resync_failure' ) );
		$wpdb->suppress_errors( $suppressing );
		$this->assertSame( $this->relative_path( $fixture['thumbnail'] ), get_post_meta( $fixture['attachment_id'], '_wp_attached_file', true ) );
		$this->assertNotEmpty( $GLOBALS['yotm_media_source_frames'] );
		$this->assertWPError( $GLOBALS['yotm_media_source_last_error'] );
		$lock_names = array_keys( $GLOBALS['yotm_media_path_locks'] );
		yotm_media_source_shutdown_cleanup();
		foreach ( $lock_names as $lock_name ) {
			$this->assertSame( '1', (string) $wpdb->get_var( $wpdb->prepare( 'SELECT IS_FREE_LOCK(%s)', $lock_name ) ) );
		}
		$wpdb->last_error = '';
	}

	public function test_multi_alias_lock_order_is_deterministic_and_partial_acquisition_is_released() {
		$first  = trailingslashit( $this->test_dir ) . 'z-lock.jpg';
		$second = trailingslashit( $this->test_dir ) . 'a-lock.jpg';
		$this->write_file( $first, 'first' );
		$this->write_file( $second, 'second' );
		$paths  = array(
			yotm_media_source_canonical_path( $first ),
			yotm_media_source_canonical_path( $second ),
		);
		$sorted = array();
		foreach ( $paths as $path ) {
			$sorted[ hash( 'sha256', $path ) ] = $path;
		}
		ksort( $sorted );
		$expected_names = array_map( 'yotm_media_path_lock_name', array_values( $sorted ) );
		add_filter( 'query', array( $this, 'fail_second_named_lock' ) );
		$result = yotm_media_path_lock_aliases(
			array(
				array( 'path' => $first ),
				array( 'path' => $second ),
			)
		);
		remove_filter( 'query', array( $this, 'fail_second_named_lock' ) );
		$this->assertWPError( $result );
		$this->assertSame( $expected_names, $this->named_lock_names );
		$this->assertEmpty( $GLOBALS['yotm_media_path_locks'] );
		global $wpdb;
		$this->assertSame( '1', (string) $wpdb->get_var( $wpdb->prepare( 'SELECT IS_FREE_LOCK(%s)', $expected_names[0] ) ) );
	}

	public function test_lock_names_are_stable_and_site_scoped() {
		$path       = trailingslashit( $this->test_dir ) . 'scoped-lock.jpg';
		$canonical  = yotm_media_source_canonical_path( $path );
		$first_name = yotm_media_path_lock_name( $canonical );
		$this->assertSame( $first_name, yotm_media_path_lock_name( $canonical ) );
		global $wpdb;
		$prefix  = $wpdb->prefix;
		$blog_id = $GLOBALS['blog_id'] ?? 1;
		try {
			$wpdb->prefix = 'alternate_';
			$this->assertNotSame( $first_name, yotm_media_path_lock_name( $canonical ) );
			$wpdb->prefix       = $prefix;
			$GLOBALS['blog_id'] = (int) $blog_id + 1;
			$this->assertNotSame( $first_name, yotm_media_path_lock_name( $canonical ) );
		} finally {
			$wpdb->prefix       = $prefix;
			$GLOBALS['blog_id'] = $blog_id;
		}
	}

	public function test_legacy_manifest_and_forged_item_are_rejected() {
		$legacy = array(
			'type'          => 'prune',
			'status'        => 'awaiting_approval',
			'manifest_hash' => hash( 'sha256', 'legacy' ),
			'total'         => 1,
			'payload'       => array(),
		);
		$result = yotm_prune_validate_review_job( $legacy, $legacy['manifest_hash'], true );
		$this->assertWPError( $result );
		$this->assertSame( 'yotm_prune_ownership_upgrade_required', $result->get_error_code() );
		$fixture = $this->create_attachment_with_thumbnail( 'forged.jpg', 'forged-150x150.jpg' );
		$forged  = array(
			'path'             => $fixture['thumbnail'],
			'ownership_schema' => 'generated_file_v1',
			'ownership'        => 'metadata_size',
			'metadata_refs'    => array(
				array(
					'attachment_id' => $fixture['attachment_id'],
					'size'          => 'thumbnail',
					'filename'      => wp_basename( $fixture['thumbnail'] ),
				),
			),
		);
		$this->assertWPError( yotm_validate_prune_item_ownership( $forged, $this->uploads_base ) );
		$this->assertFileExists( $fixture['thumbnail'] );
	}

	public function test_path_lock_is_request_reentrant_and_balanced() {
		$path = trailingslashit( $this->test_dir ) . 'lock.jpg';
		$this->write_file( $path, 'lock' );
		$first  = yotm_media_path_lock_acquire( $path );
		$second = yotm_media_path_lock_acquire( $path );
		$this->assertIsArray( $first );
		$this->assertSame( $first['name'], $second['name'] );
		$this->assertSame( 2, $GLOBALS['yotm_media_path_locks'][ $first['name'] ]['refs'] );
		yotm_media_path_lock_release( $second );
		$this->assertSame( 1, $GLOBALS['yotm_media_path_locks'][ $first['name'] ]['refs'] );
		yotm_media_path_lock_release( $first );
		$this->assertArrayNotHasKey( $first['name'], $GLOBALS['yotm_media_path_locks'] );
	}

	public function force_named_lock_contention( $query ) {
		return preg_match( '/^SELECT GET_LOCK\(/i', ltrim( $query ) ) ? 'SELECT 0' : $query;
	}

	public function fail_second_named_lock( $query ) {
		if ( ! preg_match( "/^SELECT GET_LOCK\('([^']+)'/i", ltrim( $query ), $matches ) ) {
			return $query;
		}
		++$this->named_lock_attempts;
		$this->named_lock_names[] = $matches[1];
		return 2 === $this->named_lock_attempts ? 'SELECT 0' : $query;
	}

	public function force_source_upsert_failure( $query ) {
		return false !== strpos( $query, 'INSERT INTO ' . yotm_job_table_names()['sources'] ) ? 'SELECT * FROM yotm_missing_source_table' : $query;
	}

	public function force_source_resync_failure( $query ) {
		return false !== strpos( $query, 'SELECT id,source_kind,path_hash FROM ' . yotm_job_table_names()['sources'] ) ? 'SELECT * FROM yotm_missing_source_table' : $query;
	}

	public function short_circuit_source_update() {
		return false;
	}

	private function collect_candidates( $attachment_id ) {
		$this->assertTrue( yotm_media_source_sync_attachment( $attachment_id ) );
		$candidates = array();
		$summary    = yotm_initial_orphan_summary();
		yotm_collect_metadata_prune_candidates_for_ids(
			array( $attachment_id ),
			trailingslashit( $this->test_dir ),
			array(),
			array( 'thumbnail' ),
			array(
				'thumbnail' => array(
					'width'  => 150,
					'height' => 150,
					'crop'   => true,
				),
			),
			false,
			$candidates,
			$summary
		);
		return $candidates;
	}

	private function create_attachment_with_thumbnail( $original_name, $thumbnail_name, $thumbnail_mime = 'image/jpeg' ) {
		$original  = trailingslashit( $this->test_dir ) . $original_name;
		$thumbnail = trailingslashit( $this->test_dir ) . $thumbnail_name;
		$this->write_file( $original, 'original' );
		$this->write_file( $thumbnail, 'thumbnail' );
		$attachment_id = $this->create_attachment(
			$original,
			array(
				'file'  => $this->relative_path( $original ),
				'sizes' => array(
					'thumbnail' => array(
						'file'      => wp_basename( $thumbnail ),
						'width'     => 150,
						'height'    => 150,
						'mime-type' => $thumbnail_mime,
					),
				),
			)
		);
		return compact( 'attachment_id', 'original', 'thumbnail' );
	}

	private function create_attachment( $file, $metadata ) {
		$attachment_id       = wp_insert_attachment(
			array(
				'post_mime_type' => 'image/jpeg',
				'post_title'     => 'YOTM source test',
				'post_status'    => 'inherit',
			),
			$file
		);
		$this->attachments[] = $attachment_id;
		if ( '' === get_post_meta( $attachment_id, '_wp_attached_file', true ) ) {
			$this->assertNotFalse( update_attached_file( $attachment_id, $file ) );
		}
		$this->assertNotFalse( wp_update_attachment_metadata( $attachment_id, $metadata ) );
		return $attachment_id;
	}

	private function relative_path( $path ) {
		return ltrim( str_replace( wp_normalize_path( $this->uploads_base ), '', wp_normalize_path( $path ) ), '/' );
	}

	private function write_file( $path, $contents ) {
		file_put_contents( $path, $contents );
		$this->files[] = $path;
	}
}
