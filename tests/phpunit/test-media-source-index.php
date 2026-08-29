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

	/** @var array */
	private $nested_guard = array();

	/** @var int */
	private $fallback_attachment_id = 0;

	/** @var string */
	private $fallback_file = '';

	/** @var string */
	private $failed_source_hash = '';

	/** @var callable|null */
	private $filtered_source_callback;

	/** @var array */
	private $metadata_filter_fixture = array();

	public function setUp(): void {
		parent::setUp();
		unset( $GLOBALS['yotm_job_storage_readiness'], $GLOBALS['yotm_media_source_last_error'] );
		yotm_media_source_shutdown_cleanup();
		delete_option( YOTM_MEDIA_SOURCE_DIRTY_OPTION );
		$this->assertTrue( yotm_run_job_table_migration() );
		$uploads            = wp_get_upload_dir();
		$this->uploads_base = trailingslashit( $uploads['basedir'] );
		$this->test_dir     = $this->uploads_base . 'yotm-source-' . wp_generate_uuid4();
		wp_mkdir_p( $this->test_dir );
		yotm_media_source_clear_index();
		$state = yotm_media_reference_index_state();
		$this->assertIsArray( $state );
		$this->assertTrue( yotm_media_reference_baseline_complete( $state['baseline_token'] ) );
	}

	public function tearDown(): void {
		remove_filter( 'query', array( $this, 'force_named_lock_contention' ) );
		remove_filter( 'query', array( $this, 'fail_second_named_lock' ) );
		remove_filter( 'query', array( $this, 'force_source_upsert_failure' ) );
		remove_filter( 'query', array( $this, 'force_source_resync_failure' ) );
		remove_filter( 'query', array( $this, 'force_filtered_source_upsert_failure' ) );
		remove_filter( 'query', array( $this, 'force_dirty_state_persist_failure' ) );
		remove_filter( 'update_post_metadata', array( $this, 'short_circuit_source_update' ), PHP_INT_MAX - 1 );
		remove_filter( 'update_post_metadata', array( $this, 'nest_by_mid_inside_regular_update' ), 10 );
		remove_filter( 'update_post_metadata_by_mid', array( $this, 'nest_regular_inside_by_mid_update' ), 10 );
		remove_filter( 'get_attached_file', array( $this, 'supply_attached_file_for_fallback' ), 10 );
		remove_filter( 'get_post_metadata', array( $this, 'filter_authoritative_metadata' ), 10 );
		if ( is_callable( $this->filtered_source_callback ) ) {
			remove_filter( 'get_attached_file', $this->filtered_source_callback, 10 );
		}
		remove_action( 'delete_attachment', array( $this, 'abort_attachment_deletion' ), PHP_INT_MAX );
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
		delete_option( YOTM_MEDIA_SOURCE_DIRTY_OPTION );
		delete_option( YOTM_MEDIA_REFERENCE_STATE_OPTION );
		parent::tearDown();
	}

	public function test_attachment_and_registered_size_functions_have_media_owners() {
		$root               = dirname( __DIR__, 2 );
		$attachment_module  = wp_normalize_path( $root . '/inc/media/attachments.php' );
		$registered_module  = wp_normalize_path( $root . '/inc/media/registered-sizes.php' );
		$attachment_helpers = array(
			'yotm_normalize_attached_file_relative_path',
			'yotm_get_attached_file_selector_rows_after',
			'yotm_media_reference_raw_postmeta_rows',
			'yotm_media_reference_is_image_attachment',
			'yotm_authorize_attached_file_selector_scope',
		);

		foreach ( $attachment_helpers as $function ) {
			$reflection = new ReflectionFunction( $function );
			$this->assertSame( $attachment_module, wp_normalize_path( $reflection->getFileName() ), $function );
		}

		$reflection = new ReflectionFunction( 'yotm_get_registered_sizes' );
		$this->assertSame( $registered_module, wp_normalize_path( $reflection->getFileName() ) );
	}

	public function test_source_index_coordinator_has_application_owner_and_legacy_delegate() {
		$expected  = wp_normalize_path( dirname( __DIR__, 2 ) . '/inc/application/media-source-indexing.php' );
		$canonical = new ReflectionFunction( 'yotm_application_media_source_index_batch' );
		$legacy    = new ReflectionFunction( 'yotm_prune_source_index_batch' );

		$this->assertSame( $expected, wp_normalize_path( $canonical->getFileName() ) );
		$this->assertSame( $expected, wp_normalize_path( $legacy->getFileName() ) );

		$source = file_get_contents( $expected );
		$this->assertIsString( $source );
		$this->assertDoesNotMatchRegularExpression(
			'/\$_POST|\bwp_send_json_|\bcurrent_user_can\b|\bcheck_ajax_referer\b/',
			$source
		);
	}

	public function test_source_store_and_mutation_locks_have_media_owners_without_jobs_runtime_calls() {
		$root         = dirname( __DIR__, 2 );
		$store_module = wp_normalize_path( $root . '/inc/media/source-store.php' );
		$locks_module = wp_normalize_path( $root . '/inc/media/source-locks.php' );

		foreach ( array( 'yotm_media_source_table_name', 'yotm_media_source_upsert_aliases', 'yotm_media_source_replace_attachment' ) as $function ) {
			$reflection = new ReflectionFunction( $function );
			$this->assertSame( $store_module, wp_normalize_path( $reflection->getFileName() ), $function );
		}

		foreach ( array( 'yotm_media_source_fence_acquire', 'yotm_media_attachment_lock_acquire', 'yotm_media_path_lock_acquire' ) as $function ) {
			$reflection = new ReflectionFunction( $function );
			$this->assertSame( $locks_module, wp_normalize_path( $reflection->getFileName() ), $function );
		}

		foreach ( array( 'attachments.php', 'source-store.php', 'source-locks.php' ) as $file ) {
			$source = file_get_contents( $root . '/inc/media/' . $file );
			$this->assertIsString( $source );
			$this->assertDoesNotMatchRegularExpression(
				'/\byotm_job_(?:table_names|storage_error|acquire_named_lock|release_named_lock)\b/',
				$source,
				$file
			);
		}

		$index_source = file_get_contents( $root . '/inc/media-source-index.php' );
		$this->assertIsString( $index_source );
		$this->assertDoesNotMatchRegularExpression(
			'/\byotm_job_(?:table_names|storage_error|acquire_named_lock|release_named_lock)\b/',
			$index_source
		);
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

	public function test_raw_postmeta_batch_preserves_exact_rows_in_one_query() {
		$first  = $this->create_attachment_with_thumbnail( 'raw-batch-first.jpg', 'raw-batch-first-150x150.jpg' );
		$second = $this->create_attachment_with_thumbnail( 'raw-batch-second.jpg', 'raw-batch-second-150x150.jpg' );

		global $wpdb;
		$this->assertSame(
			1,
			$wpdb->insert(
				$wpdb->postmeta,
				array(
					'post_id'    => $second['attachment_id'],
					'meta_key'   => '_wp_attached_file',
					'meta_value' => 'ambiguous/raw-batch-second.jpg',
				),
				array( '%d', '%s', '%s' )
			)
		);

		$before = (int) $wpdb->num_queries;
		$rows   = yotm_media_reference_raw_postmeta_rows_batch(
			array( $first['attachment_id'], $second['attachment_id'] ),
			array( '_wp_attached_file', '_wp_attachment_metadata' )
		);

		$this->assertNotWPError( $rows );
		$this->assertSame( 1, (int) $wpdb->num_queries - $before );
		$this->assertCount( 1, $rows[ $first['attachment_id'] ]['_wp_attached_file'] );
		$this->assertCount( 1, $rows[ $first['attachment_id'] ]['_wp_attachment_metadata'] );
		$this->assertCount( 2, $rows[ $second['attachment_id'] ]['_wp_attached_file'] );
		$this->assertCount( 1, $rows[ $second['attachment_id'] ]['_wp_attachment_metadata'] );
		$this->assertLessThan(
			$rows[ $second['attachment_id'] ]['_wp_attached_file'][1]['meta_id'],
			$rows[ $second['attachment_id'] ]['_wp_attached_file'][0]['meta_id']
		);
	}

	public function test_exact_avif_metadata_output_remains_eligible() {
		$fixture    = $this->create_attachment_with_thumbnail( 'exact-avif.jpg', 'exact-avif-150x150.avif', 'image/avif' );
		$candidates = $this->collect_candidates( $fixture['attachment_id'] );
		$this->assertCount( 1, $candidates );
		$candidate = reset( $candidates );
		$this->assertSame( yotm_normalize_filesystem_path( $fixture['thumbnail'] ), $candidate['path'] );
		$this->assertSame( 'image/avif', $candidate['ownership_evidence'][0]['mime'] );
	}

	public function test_strict_disk_only_thumbnail_gets_versioned_legacy_evidence() {
		$fixture = $this->create_real_legacy_fixture( 'legacy-valid' );
		$policy  = $this->legacy_policy( array(), array( 'legacy_remove' ) );
		$result  = yotm_classify_legacy_disk_candidates( array( $fixture['candidate'] ), $policy );

		$this->assertIsArray( $result );
		$item = $result[ yotm_prune_journal_lexical_path( $fixture['candidate'] ) ];
		$this->assertNotWPError( $item );
		$this->assertSame( 'legacy_generated_v1', $item['ownership_schema'] );
		$this->assertSame( 'legacy_disk', $item['ownership'] );
		$this->assertSame( 0, $item['remove_metadata'] );
		$this->assertSame( array(), $item['metadata_refs'] );
		$this->assertSame( array( 'legacy_remove' ), $item['matched_remove_sizes'] );
		$this->assertSame( 'image/jpeg', $item['observed_mime'] );
		$this->assertSame( 'image/jpeg', yotm_legacy_extension_mime( 'jpeg' ) );
		$this->assertTrue( yotm_prune_validate_legacy_evidence( $item, $policy ) );

		$legacy_job = $policy;
		unset( $legacy_job['legacy_policy'] );
		$this->assertWPError( yotm_classify_legacy_disk_candidates( array( $fixture['candidate'] ), $legacy_job ) );
		$changed_policy                                    = $policy;
		$changed_policy['sizes']['legacy_remove']['width'] = 151;
		$error = yotm_classify_legacy_disk_candidates( array( $fixture['candidate'] ), $changed_policy );
		$this->assertWPError( $error );
		$this->assertSame( 'yotm_legacy_policy_changed', $error->get_error_code() );
	}

	public function test_bulk_source_lookup_is_exact_bounded_and_fanout_limited() {
		$fixture = $this->create_real_legacy_fixture( 'legacy-bulk-source' );
		$hash    = hash( 'sha256', yotm_normalize_filesystem_path( $fixture['source'] ) );
		$missing = hash( 'sha256', 'missing-exact-path' );
		global $wpdb;
		$before_queries = (int) $wpdb->num_queries;
		$rows           = yotm_media_source_store_paths_rows( array( $hash, $missing ) );

		$this->assertNotWPError( $rows );
		$this->assertSame( 1, (int) $wpdb->num_queries - $before_queries );
		$this->assertArrayHasKey( $hash, $rows );
		$this->assertArrayNotHasKey( $missing, $rows );
		foreach ( $rows[ $hash ] as $row ) {
			$this->assertSame( $hash, $row->path_hash );
		}

		$this->assertWPError( yotm_media_source_store_paths_rows( array( $hash ), 1 ) );
		$this->assertStringContainsString( 'LIMIT 2', $wpdb->last_query );
		$too_many = array();
		for ( $index = 0; $index < 4001; ++$index ) {
			$too_many[] = hash( 'sha256', 'bounded-' . $index );
		}
		$this->assertWPError( yotm_media_source_store_paths_rows( $too_many ) );
	}

	public function test_kept_size_match_vetoes_legacy_candidate_even_when_removed_size_matches() {
		$fixture = $this->create_real_legacy_fixture( 'legacy-kept-veto' );
		$policy  = $this->legacy_policy( array( 'legacy_keep' ), array( 'legacy_remove' ) );
		$result  = yotm_classify_legacy_disk_candidates( array( $fixture['candidate'] ), $policy );
		$this->assertNotWPError( $result );
		$error = $result[ yotm_prune_journal_lexical_path( $fixture['candidate'] ) ];

		$this->assertWPError( $error );
		$this->assertSame( 'yotm_legacy_kept_dimension', $error->get_error_code() );
	}

	public function test_legacy_candidate_mime_mismatch_and_duplicate_family_fail_closed() {
		$fixture  = $this->create_real_legacy_fixture( 'legacy-ambiguous' );
		$mismatch = preg_replace( '/\.jpg$/', '.png', $fixture['candidate'] );
		copy( $fixture['candidate'], $mismatch );
		$this->files[] = $mismatch;
		$policy        = $this->legacy_policy( array(), array( 'legacy_remove' ) );
		$result        = yotm_classify_legacy_disk_candidates( array( $mismatch ), $policy );
		$this->assertNotWPError( $result );
		$error = $result[ yotm_prune_journal_lexical_path( $mismatch ) ];
		$this->assertWPError( $error );
		$this->assertSame( 'yotm_legacy_image_mismatch', $error->get_error_code() );

		$this->assertTrue(
			yotm_media_source_upsert_aliases(
				array(
					array(
						'attachment_id' => $fixture['attachment_id'] + 1000000,
						'source_kind'   => 'attached',
						'path_hash'     => hash( 'sha256', $fixture['source'] ),
						'path'          => $fixture['source'],
					),
				)
			)
		);
		$result = yotm_classify_legacy_disk_candidates( array( $fixture['candidate'] ), $policy );
		$error  = $result[ yotm_prune_journal_lexical_path( $fixture['candidate'] ) ];
		$this->assertWPError( $error );
		$this->assertSame( 'yotm_legacy_family_ambiguous', $error->get_error_code() );
	}

	public function test_legacy_evidence_is_revoked_when_candidate_gains_metadata_owner() {
		$fixture = $this->create_real_legacy_fixture( 'legacy-late-owner' );
		$policy  = $this->legacy_policy( array(), array( 'legacy_remove' ) );
		$result  = yotm_classify_legacy_disk_candidates( array( $fixture['candidate'] ), $policy );
		$this->assertNotWPError( $result );
		$item = $result[ yotm_prune_journal_lexical_path( $fixture['candidate'] ) ];
		$this->assertNotWPError( $item );

		$metadata                     = wp_get_attachment_metadata( $fixture['attachment_id'] );
		$metadata['sizes']['claimed'] = array(
			'file'      => wp_basename( $fixture['candidate'] ),
			'width'     => 150,
			'height'    => 150,
			'mime-type' => 'image/jpeg',
		);
		$this->assertNotFalse( wp_update_attachment_metadata( $fixture['attachment_id'], $metadata ) );
		$this->assertTrue( yotm_media_source_sync_attachment( $fixture['attachment_id'] ) );

		$error = yotm_prune_validate_legacy_evidence( $item, $policy );
		$this->assertWPError( $error );
		$this->assertSame( 'yotm_legacy_path_owned', $error->get_error_code() );
	}

	public function test_legacy_evidence_rejects_byte_identical_node_replacement() {
		$fixture = $this->create_real_legacy_fixture( 'legacy-node-replacement' );
		$policy  = $this->legacy_policy( array(), array( 'legacy_remove' ) );
		$result  = yotm_classify_legacy_disk_candidates( array( $fixture['candidate'] ), $policy );
		$item    = $result[ yotm_prune_journal_lexical_path( $fixture['candidate'] ) ];
		$this->assertNotWPError( $item );

		$replacement   = trailingslashit( dirname( $fixture['candidate'] ) ) . 'legacy-node-replacement.tmp';
		$this->files[] = $replacement;
		$this->assertTrue( copy( $fixture['candidate'], $replacement ) );
		$replacement_state = yotm_prune_journal_path_state( $replacement );
		$this->assertIsArray( $replacement_state );
		$this->assertNotSame( $item['candidate_node_fingerprint'], $replacement_state['fingerprint'] );
		$this->assertTrue( unlink( $fixture['candidate'] ) );
		$this->assertTrue( rename( $replacement, $fixture['candidate'] ) );

		$error = yotm_prune_validate_legacy_evidence( $item, $policy );
		$this->assertWPError( $error );
		$this->assertSame( 'yotm_legacy_evidence_changed', $error->get_error_code() );
	}

	public function test_legacy_reconciliation_has_no_metadata_authority() {
		$fixture = $this->create_real_legacy_fixture( 'legacy-no-metadata' );
		$before  = get_post_meta( $fixture['attachment_id'], '_wp_attachment_metadata', true );
		$item    = array(
			'ownership_schema' => 'legacy_generated_v1',
			'ownership'        => 'legacy_disk',
			'remove_metadata'  => 0,
			'metadata_refs'    => array(),
		);

		$this->assertTrue( yotm_reconcile_prune_item_metadata( $item, $fixture['candidate'] ) );
		$this->assertSame( $before, get_post_meta( $fixture['attachment_id'], '_wp_attachment_metadata', true ) );
		$item['metadata_refs'][] = array(
			'attachment_id' => $fixture['attachment_id'],
			'size'          => 'legacy_remove',
			'filename'      => wp_basename( $fixture['candidate'] ),
		);
		$this->assertWPError( yotm_reconcile_prune_item_metadata( $item, $fixture['candidate'] ) );
		$this->assertSame( $before, get_post_meta( $fixture['attachment_id'], '_wp_attachment_metadata', true ) );
	}

	public function test_legacy_family_uses_original_generation_basename_not_scaled_current_full_basename() {
		$original = trailingslashit( $this->test_dir ) . 'basename-diverge.jpg';
		$scaled   = trailingslashit( $this->test_dir ) . 'basename-diverge-scaled.jpg';
		copy( DIR_TESTDATA . '/images/2004-07-22-DSC_0007.jpg', $original );
		copy( $original, $scaled );
		$this->files[] = $original;
		$this->files[] = $scaled;
		$image         = wp_getimagesize( $original );
		$attachment_id = $this->create_attachment(
			$scaled,
			array(
				'file'           => $this->relative_path( $scaled ),
				'original_image' => wp_basename( $original ),
				'width'          => absint( $image[0] ),
				'height'         => absint( $image[1] ),
				'sizes'          => array(),
			)
		);
		$editor        = wp_get_image_editor( $original );
		$this->assertNotWPError( $editor );
		$created = $editor->make_subsize(
			array(
				'width'  => 150,
				'height' => 150,
				'crop'   => true,
			)
		);
		$this->assertNotWPError( $created );
		$candidate     = trailingslashit( $this->test_dir ) . $created['file'];
		$this->files[] = $candidate;
		$this->assertTrue( yotm_media_source_sync_attachment( $attachment_id ) );

		$policy    = $this->legacy_policy( array(), array( 'legacy_remove' ) );
		$candidate = yotm_media_source_canonical_path( $candidate );
		$result    = yotm_classify_legacy_disk_candidates( array( $candidate ), $policy );
		$this->assertNotWPError( $result );
		$item = $result[ yotm_prune_journal_lexical_path( $candidate ) ];
		$this->assertNotWPError( $item );
		$this->assertSame( yotm_media_source_canonical_path( $original ), $item['source_path'] );

		$scaled_candidate = trailingslashit( $this->test_dir ) . 'basename-diverge-scaled-150x150.jpg';
		copy( $candidate, $scaled_candidate );
		$this->files[]    = $scaled_candidate;
		$scaled_candidate = yotm_media_source_canonical_path( $scaled_candidate );
		$result           = yotm_classify_legacy_disk_candidates( array( $scaled_candidate ), $policy );
		$this->assertWPError( $result[ yotm_prune_journal_lexical_path( $scaled_candidate ) ] );
	}

	public function test_reviewed_legacy_manifest_lifecycle_deletes_file_without_metadata_reconciliation() {
		$fixture = $this->create_real_legacy_fixture( 'legacy-lifecycle' );
		$keep    = array_values( array_diff( array_keys( yotm_get_registered_sizes() ), array( 'thumbnail' ) ) );
		$before  = get_metadata_raw( 'post', $fixture['attachment_id'], '_wp_attachment_metadata', true );
		$prepare = yotm_prune_prepare_application( $keep, array( wp_basename( $this->test_dir ) ), true );
		$this->assertTrue( $prepare['success'], $prepare['data']['msg'] ?? '' );
		$token = $prepare['data']['token'];

		for ( $batch = 0; $batch < 30; ++$batch ) {
			$scan = yotm_prune_scan_application( $token, 500 );
			yotm_job_release_all_workers();
			$this->assertTrue( $scan['success'], $scan['data']['msg'] ?? '' );
			if ( ! empty( $scan['data']['scan_done'] ) ) {
				break;
			}
		}
		$this->assertNotEmpty( $scan['data']['scan_done'] );
		$this->assertSame( 1, $scan['data']['total'] );
		$this->assertSame( 1, $scan['data']['manifest_class_counts']['verified_legacy'] );
		$this->assertSame( 0, $scan['data']['manifest_class_counts']['metadata_backed'] );
		$this->assertFileExists( $fixture['candidate'] );

		$approval = yotm_prune_approve_application( $token, $scan['data']['manifest_hash'], false );
		$this->assertFalse( $approval['success'] );
		$this->assertTrue( yotm_media_source_dirty_mark( 'legacy-review-dirty', $fixture['attachment_id'] ) );
		$approval = yotm_prune_approve_application( $token, $scan['data']['manifest_hash'], true );
		$this->assertFalse( $approval['success'] );
		$this->assertTrue( yotm_media_source_sync_attachment( $fixture['attachment_id'], null, true ) );
		$approval = yotm_prune_approve_application( $token, $scan['data']['manifest_hash'], true );
		$this->assertTrue( $approval['success'], $approval['data']['msg'] ?? '' );
		$this->assertTrue( yotm_media_source_dirty_mark( 'legacy-delete-dirty', $fixture['attachment_id'] ) );
		$delete = yotm_prune_delete_application( $token, $scan['data']['manifest_hash'], 10 );
		$this->assertFalse( $delete['success'] );
		$this->assertFileExists( $fixture['candidate'] );
		$this->assertTrue( yotm_media_source_sync_attachment( $fixture['attachment_id'], null, true ) );

		for ( $batch = 0; $batch < 10; ++$batch ) {
			$delete = yotm_prune_delete_application( $token, $scan['data']['manifest_hash'], 10 );
			yotm_job_release_all_workers();
			$this->assertTrue( $delete['success'], $delete['data']['msg'] ?? '' );
			if ( ! empty( $delete['data']['done'] ) ) {
				break;
			}
		}
		$this->assertNotEmpty( $delete['data']['done'] );
		$this->assertSame( 1, $delete['data']['deleted'] );
		$this->assertFileDoesNotExist( $fixture['candidate'] );
		$this->assertSame( $before, get_metadata_raw( 'post', $fixture['attachment_id'], '_wp_attachment_metadata', true ) );
	}

	public function test_cross_attachment_source_is_excluded_even_outside_candidate_attachment() {
		$fixture = $this->create_attachment_with_thumbnail( 'owner.jpg', 'owner-150x150.jpg' );
		$source  = $this->create_attachment( $fixture['thumbnail'], array( 'file' => $this->relative_path( $fixture['thumbnail'] ) ) );
		$this->assertTrue( yotm_media_source_sync_attachment( $source ) );

		$this->assertTrue( yotm_media_source_path_is_authoritative( $fixture['thumbnail'] ) );
		$this->assertSame( array(), $this->collect_candidates( $fixture['attachment_id'] ) );
	}

	public function test_generated_ownership_is_not_a_source_veto_but_remains_queryable() {
		$fixture = $this->create_attachment_with_thumbnail( 'generated-owner.jpg', 'generated-owner-150x150.jpg' );
		$this->assertFalse( yotm_media_source_path_is_authoritative( $fixture['thumbnail'] ) );
		$owners = yotm_media_reference_path_owners( $fixture['thumbnail'] );
		$this->assertIsArray( $owners );
		$this->assertSame( array(), $owners['protected'] );
		$this->assertSame( $fixture['attachment_id'], $owners['generated'][0]['attachment_id'] );
		$this->assertSame( 'thumbnail', $owners['generated'][0]['size'] );
	}

	public function test_filtered_metadata_cannot_hide_or_mint_destructive_ownership() {
		$fixture      = $this->create_attachment_with_thumbnail( 'raw-owner.jpg', 'raw-owner-150x150.jpg' );
		$virtual_full = trailingslashit( $this->test_dir ) . 'virtual-full.jpg';
		$virtual      = trailingslashit( $this->test_dir ) . 'virtual-150x150.jpg';
		$this->write_file( $virtual_full, 'virtual-full' );
		$this->write_file( $virtual, 'virtual' );
		$this->metadata_filter_fixture = array(
			'attachment_id' => $fixture['attachment_id'],
			'metadata'      => array(
				'file'  => $this->relative_path( $virtual_full ),
				'sizes' => array(
					'virtual' => array(
						'file'      => wp_basename( $virtual ),
						'width'     => 150,
						'height'    => 150,
						'mime-type' => 'image/jpeg',
					),
				),
			),
		);
		add_filter( 'get_post_metadata', array( $this, 'filter_authoritative_metadata' ), 10, 5 );

		$this->assertTrue( yotm_media_source_sync_attachment( $fixture['attachment_id'], null, true ) );
		$real_owners = yotm_media_reference_path_owners( $fixture['thumbnail'] );
		$this->assertIsArray( $real_owners );
		$this->assertSame( $fixture['attachment_id'], $real_owners['generated'][0]['attachment_id'] );
		$this->assertTrue( yotm_media_source_path_is_authoritative( $fixture['original'] ) );
		$this->assertTrue( yotm_media_source_path_is_authoritative( $virtual_full ) );

		$virtual_owners = yotm_media_reference_path_owners( $virtual );
		$this->assertIsArray( $virtual_owners );
		$this->assertSame( array(), $virtual_owners['generated'] );

		$candidates = $this->collect_candidates( $fixture['attachment_id'] );
		$this->assertArrayHasKey( yotm_normalize_filesystem_path( $fixture['thumbnail'] ), $candidates );
		$this->assertArrayNotHasKey( yotm_normalize_filesystem_path( $virtual ), $candidates );
	}

	public function test_source_image_and_edit_backup_are_protected_companions() {
		$fixture      = $this->create_attachment_with_thumbnail( 'companions.jpg', 'companions-150x150.jpg' );
		$source_image = trailingslashit( $this->test_dir ) . 'companions-source.heic';
		$edit_backup  = trailingslashit( $this->test_dir ) . 'companions-e123.jpg';
		$this->write_file( $source_image, 'source-image' );
		$this->write_file( $edit_backup, 'edit-backup' );
		$metadata                 = get_post_meta( $fixture['attachment_id'], '_wp_attachment_metadata', true );
		$metadata['source_image'] = wp_basename( $source_image );
		$this->assertNotFalse( update_post_meta( $fixture['attachment_id'], '_wp_attachment_metadata', $metadata ) );
		$this->assertNotFalse(
			update_post_meta(
				$fixture['attachment_id'],
				'_wp_attachment_backup_sizes',
				array(
					'full-orig' => array(
						'file'   => wp_basename( $edit_backup ),
						'width'  => 800,
						'height' => 600,
					),
				)
			)
		);
		$this->assertTrue( yotm_media_source_path_is_authoritative( $source_image ) );
		$this->assertTrue( yotm_media_source_path_is_authoritative( $edit_backup ) );
	}

	public function test_filtered_attached_file_cannot_bypass_authoritative_mutation_guards() {
		$fixture = $this->create_attachment_with_thumbnail( 'hidden-guard.jpg', 'hidden-guard-150x150.jpg' );
		$paths   = array();
		foreach ( array( 'add', 'regular-update', 'by-mid-update', 'by-mid-delete' ) as $name ) {
			$paths[ $name ] = trailingslashit( $this->test_dir ) . 'hidden-guard-' . $name . '.jpg';
			$this->write_file( $paths[ $name ], $name );
		}
		$backup = static function ( $path ) {
			return array(
				'full-orig' => array(
					'file'   => wp_basename( $path ),
					'width'  => 800,
					'height' => 600,
				),
			);
		};
		$filter = static function ( $file, $attachment_id ) use ( $fixture ) {
			return $fixture['attachment_id'] === (int) $attachment_id ? false : $file;
		};

		add_filter( 'get_attached_file', $filter, 10, 2 );
		try {
			$meta_id = add_post_meta( $fixture['attachment_id'], '_wp_attachment_backup_sizes', $backup( $paths['add'] ) );
			$this->assertIsInt( $meta_id );
			$this->assertTrue( yotm_media_source_path_is_authoritative( $paths['add'] ) );

			$this->assertNotFalse( update_post_meta( $fixture['attachment_id'], '_wp_attachment_backup_sizes', $backup( $paths['regular-update'] ) ) );
			$this->assertFalse( yotm_media_source_path_is_authoritative( $paths['add'] ) );
			$this->assertTrue( yotm_media_source_path_is_authoritative( $paths['regular-update'] ) );

			$this->assertTrue( update_metadata_by_mid( 'post', $meta_id, $backup( $paths['by-mid-update'] ), false ) );
			$this->assertFalse( yotm_media_source_path_is_authoritative( $paths['regular-update'] ) );
			$this->assertTrue( yotm_media_source_path_is_authoritative( $paths['by-mid-update'] ) );

			$this->assertTrue( delete_post_meta( $fixture['attachment_id'], '_wp_attachment_backup_sizes' ) );
			$this->assertFalse( yotm_media_source_path_is_authoritative( $paths['by-mid-update'] ) );

			$meta_id = add_post_meta( $fixture['attachment_id'], '_wp_attachment_backup_sizes', $backup( $paths['by-mid-delete'] ) );
			$this->assertIsInt( $meta_id );
			$this->assertTrue( yotm_media_source_path_is_authoritative( $paths['by-mid-delete'] ) );
			$this->assertTrue( delete_metadata_by_mid( 'post', $meta_id ) );
			$this->assertFalse( yotm_media_source_path_is_authoritative( $paths['by-mid-delete'] ) );
			$this->assertTrue( yotm_media_source_require_clean_index() );
			$this->assert_guard_state_clean();
		} finally {
			remove_filter( 'get_attached_file', $filter, 10 );
		}
	}

	public function test_import_attachments_are_fenced_when_mutations_create_image_applicability() {
		$add_path    = trailingslashit( $this->test_dir ) . 'import-add.jpg';
		$old_path    = trailingslashit( $this->test_dir ) . 'import-old.txt';
		$update_path = trailingslashit( $this->test_dir ) . 'import-update.jpg';
		$by_mid_path = trailingslashit( $this->test_dir ) . 'import-by-mid.jpg';
		foreach ( array( $add_path, $old_path, $update_path, $by_mid_path ) as $path ) {
			$this->write_file( $path, wp_basename( $path ) );
		}

		$add_id  = $this->create_import_attachment();
		$meta_id = add_post_meta( $add_id, '_wp_attached_file', $this->relative_path( $add_path ) );
		$this->assertIsInt( $meta_id );
		$this->assertTrue( yotm_media_source_path_is_authoritative( $add_path ) );

		$update_id = $this->create_import_attachment();
		$this->assertIsInt( add_post_meta( $update_id, '_wp_attached_file', $this->relative_path( $old_path ) ) );
		$this->assertNotFalse( update_post_meta( $update_id, '_wp_attached_file', $this->relative_path( $update_path ) ) );
		$this->assertFalse( yotm_media_source_path_is_authoritative( $old_path ) );
		$this->assertTrue( yotm_media_source_path_is_authoritative( $update_path ) );

		$by_mid_id = $this->create_import_attachment();
		$meta_id   = add_post_meta( $by_mid_id, 'yotm_import_transition', 'ordinary' );
		$this->assertIsInt( $meta_id );
		$this->assertTrue( update_metadata_by_mid( 'post', $meta_id, $this->relative_path( $by_mid_path ), '_wp_attached_file' ) );
		$this->assertTrue( yotm_media_source_path_is_authoritative( $by_mid_path ) );
		$this->assertTrue( yotm_media_source_require_clean_index() );
		$this->assert_guard_state_clean();
	}

	public function test_ambiguous_import_attached_file_rows_fail_closed() {
		$attachment_id = $this->create_import_attachment();
		$first         = trailingslashit( $this->test_dir ) . 'import-multi-first.txt';
		$second        = trailingslashit( $this->test_dir ) . 'import-multi-second.txt';
		$proposed      = trailingslashit( $this->test_dir ) . 'import-multi-proposed.jpg';
		foreach ( array( $first, $second, $proposed ) as $path ) {
			$this->write_file( $path, wp_basename( $path ) );
		}

		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.SlowDBQuery.slow_db_query_meta_key,WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- The fixture intentionally creates an ambiguous authoritative raw state outside the guarded API.
		foreach ( array( $first, $second ) as $path ) {
			$inserted = $wpdb->insert(
				$wpdb->postmeta,
				array(
					'post_id'    => $attachment_id,
					'meta_key'   => '_wp_attached_file',
					'meta_value' => $this->relative_path( $path ),
				),
				array( '%d', '%s', '%s' )
			);
			$this->assertSame( 1, $inserted );
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.SlowDBQuery.slow_db_query_meta_key,WordPress.DB.SlowDBQuery.slow_db_query_meta_value
		wp_cache_delete( $attachment_id, 'post_meta' );
		$this->assertTrue( yotm_media_source_sync_attachment( $attachment_id, null, true ) );

		$this->assertFalse( update_post_meta( $attachment_id, '_wp_attached_file', $this->relative_path( $proposed ) ) );
		$this->assertWPError( $GLOBALS['yotm_media_source_last_error'] );
		$rows = yotm_media_reference_raw_postmeta_rows( $attachment_id, '_wp_attached_file' );
		$this->assertIsArray( $rows );
		$this->assertCount( 2, $rows );
		$this->assertSame( array( $this->relative_path( $first ), $this->relative_path( $second ) ), wp_list_pluck( $rows, 'value' ) );
		$this->assertFalse( yotm_media_source_path_is_authoritative( $proposed ) );
		$this->assertTrue( yotm_media_source_require_clean_index() );
		$this->assert_guard_state_clean();
	}

	public function test_stateful_metadata_accessor_cannot_turn_raw_update_into_unfenced_write() {
		$fixture  = $this->create_attachment_with_thumbnail( 'stateful-write.jpg', 'stateful-write-150x150.jpg' );
		$old      = $this->relative_path( $fixture['original'] );
		$new_path = trailingslashit( $this->test_dir ) . 'stateful-write-new.jpg';
		$new      = $this->relative_path( $new_path );
		$this->write_file( $new_path, 'new' );
		$calls  = 0;
		$filter = static function ( $check, $object_id, $meta_key ) use ( &$calls, $fixture, $old, $new ) {
			if ( $fixture['attachment_id'] !== (int) $object_id || '_wp_attached_file' !== $meta_key ) {
				return $check;
			}
			++$calls;
			return array( 1 === $calls ? $new : $old );
		};

		add_filter( 'get_post_metadata', $filter, 10, 3 );
		try {
			$this->assertNotFalse( update_post_meta( $fixture['attachment_id'], '_wp_attached_file', $new ) );
			$this->assertGreaterThanOrEqual( 2, $calls );
			$rows = yotm_media_reference_raw_postmeta_rows( $fixture['attachment_id'], '_wp_attached_file' );
			$this->assertIsArray( $rows );
			$this->assertSame( $new, $rows[0]['value'] );
			$this->assertTrue( yotm_media_source_path_is_authoritative( $new_path ) );
			$this->assertTrue( yotm_media_source_require_clean_index() );
			$this->assert_guard_state_clean();
		} finally {
			remove_filter( 'get_post_metadata', $filter, 10 );
		}
		$this->assertTrue( yotm_media_source_sync_attachment( $fixture['attachment_id'], null, true ) );
		$this->assertTrue( yotm_media_source_path_is_authoritative( $new_path ) );
	}

	public function test_stateful_metadata_accessor_no_write_reconciles_without_permanent_dirty_state() {
		$fixture  = $this->create_attachment_with_thumbnail( 'stateful-no-write.jpg', 'stateful-no-write-150x150.jpg' );
		$old      = array(
			'full-orig' => array(
				'file'   => 'stateful-no-write-old.jpg',
				'width'  => 800,
				'height' => 600,
			),
		);
		$new      = array(
			'full-orig' => array(
				'file'   => 'stateful-no-write-new.jpg',
				'width'  => 800,
				'height' => 600,
			),
		);
		$old_path = trailingslashit( $this->test_dir ) . $old['full-orig']['file'];
		$this->write_file( $old_path, 'old' );
		$this->write_file( trailingslashit( $this->test_dir ) . $new['full-orig']['file'], 'new' );
		$this->assertNotFalse( update_post_meta( $fixture['attachment_id'], '_wp_attachment_backup_sizes', $old ) );
		$calls  = 0;
		$filter = static function ( $check, $object_id, $meta_key ) use ( &$calls, $fixture, $old, $new ) {
			if ( $fixture['attachment_id'] !== (int) $object_id || '_wp_attachment_backup_sizes' !== $meta_key ) {
				return $check;
			}
			++$calls;
			return array( 1 === $calls ? $new : $old );
		};

		add_filter( 'get_post_metadata', $filter, 10, 3 );
		try {
			$this->assertFalse( update_post_meta( $fixture['attachment_id'], '_wp_attachment_backup_sizes', $new ) );
		} finally {
			remove_filter( 'get_post_metadata', $filter, 10 );
		}
		$this->assertSame( 1, $calls );
		$this->assertNotEmpty( $GLOBALS['yotm_media_source_frames'] );
		yotm_media_source_shutdown_cleanup();
		$rows = yotm_media_reference_raw_postmeta_rows( $fixture['attachment_id'], '_wp_attachment_backup_sizes' );
		$this->assertIsArray( $rows );
		$this->assertSame( $old, $rows[0]['value'] );
		$this->assertTrue( yotm_media_source_path_is_authoritative( $old_path ) );
		$this->assertTrue( yotm_media_source_require_clean_index() );
		$this->assert_guard_state_clean();
	}

	public function test_source_baseline_is_bounded_resumable_and_completes_before_metadata_phase() {
		$first  = $this->create_attachment_with_thumbnail( 'batch-first.jpg', 'batch-first-150x150.jpg' );
		$second = $this->create_attachment_with_thumbnail( 'batch-second.jpg', 'batch-second-150x150.jpg' );
		$this->assertTrue( yotm_media_source_clear_index() );
		$source_fence = yotm_media_source_fence_acquire();
		$this->assertIsArray( $source_fence );
		$this->assertTrue( yotm_media_source_dirty_mark( 'yotm-test-baseline-repair', $first['attachment_id'] ) );
		yotm_media_source_fence_release( $source_fence );
		$this->assertWPError( yotm_media_source_require_clean_index() );
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
		$this->assertTrue( yotm_media_source_require_clean_index() );
		yotm_job_release_worker( $worker );
	}

	public function test_source_baseline_restarts_for_dirty_attachment_outside_snapshot() {
		$first = $this->create_attachment_with_thumbnail( 'restart-first.jpg', 'restart-first-150x150.jpg' );
		$this->assertTrue( yotm_media_source_clear_index() );
		$job    = yotm_job_create(
			'prune',
			array(
				'scan_phase'               => 'source_index',
				'source_index_initialized' => 0,
				'source_index_complete'    => 0,
				'source_index_cursor'      => 0,
				'source_index_max_id'      => 0,
			),
			array(
				'status' => 'scanning',
				'phase'  => 'source_index',
			)
		);
		$worker = yotm_job_acquire_worker( $job['id'], array( 'scanning' ), array( 'source_index', 'metadata' ) );
		$this->assertIsArray( $worker );
		$batch = yotm_prune_source_index_batch( $job, 500, $worker );
		$this->assertFalse( $batch['done'] );

		$late         = $this->create_attachment_with_thumbnail( 'restart-late.jpg', 'restart-late-150x150.jpg' );
		$source_fence = yotm_media_source_fence_acquire();
		$this->assertIsArray( $source_fence );
		$this->assertTrue( yotm_media_source_dirty_mark( 'yotm-test-late-dirty', $late['attachment_id'] ) );
		yotm_media_source_fence_release( $source_fence );
		$batch = yotm_prune_source_index_batch( $batch['job'], 500, $worker );
		$this->assertFalse( $batch['done'] );
		$this->assertSame( 0, $batch['job']['payload']['source_index_initialized'] );
		$this->assertSame( 0, $batch['job']['payload']['source_index_complete'] );

		$current = $batch['job'];
		do {
			$batch   = yotm_prune_source_index_batch( $current, 500, $worker );
			$current = $batch['job'];
		} while ( ! $batch['done'] );

		$this->assertSame( 1, $current['payload']['source_index_complete'] );
		$this->assertTrue( yotm_media_source_require_clean_index() );
		$this->assertTrue( yotm_media_source_path_is_authoritative( $first['original'] ) );
		$this->assertTrue( yotm_media_source_path_is_authoritative( $late['original'] ) );
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
		$item         = yotm_job_claim_items( $worker, 1 )[0];
		$source_fence = yotm_media_source_fence_acquire();
		$this->assertIsArray( $source_fence );
		add_filter( 'query', array( $this, 'force_named_lock_contention' ) );
		$result = yotm_process_claimed_prune_item( $item, yotm_job_get_by_id( $job['id'] ), $worker, $this->uploads_base );
		remove_filter( 'query', array( $this, 'force_named_lock_contention' ) );
		yotm_media_source_fence_release( $source_fence );
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
		$this->assertEmpty( $GLOBALS['yotm_media_attachment_locks'] );
	}

	public function test_nested_regular_then_by_mid_update_completes_exact_frames() {
		$fixture = $this->create_attachment_with_thumbnail( 'nested-regular.jpg', 'nested-regular-150x150.jpg' );
		$outer   = trailingslashit( $this->test_dir ) . 'nested-regular-outer.jpg';
		$inner   = trailingslashit( $this->test_dir ) . 'nested-regular-inner.jpg';
		$this->write_file( $outer, 'outer' );
		$this->write_file( $inner, 'inner' );
		global $wpdb;
		$meta_id            = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT meta_id FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = '_wp_attached_file' LIMIT 1", $fixture['attachment_id'] )
		);
		$this->nested_guard = array(
			'attachment_id'  => $fixture['attachment_id'],
			'meta_id'        => $meta_id,
			'outer'          => $outer,
			'inner_relative' => $this->relative_path( $inner ),
			'active'         => false,
			'nested_result'  => false,
			'outer_held'     => false,
		);
		add_filter( 'update_post_metadata', array( $this, 'nest_by_mid_inside_regular_update' ), 10, 5 );
		$this->assertNotFalse( update_post_meta( $fixture['attachment_id'], '_wp_attached_file', $this->relative_path( $outer ) ) );
		remove_filter( 'update_post_metadata', array( $this, 'nest_by_mid_inside_regular_update' ), 10 );

		$this->assertTrue( $this->nested_guard['nested_result'] );
		$this->assertTrue( $this->nested_guard['outer_held'] );
		$this->assertSame( 1, $this->nested_guard['outer_attachment_refs'] );
		$this->assertSame( $this->relative_path( $outer ), get_post_meta( $fixture['attachment_id'], '_wp_attached_file', true ) );
		$this->assertTrue( yotm_media_source_path_is_authoritative( $outer ) );
		$this->assertFalse( yotm_media_source_path_is_authoritative( $inner ) );
		$this->assert_guard_state_clean();
	}

	public function test_nested_by_mid_then_regular_update_completes_exact_frames() {
		$fixture = $this->create_attachment_with_thumbnail( 'nested-bymid.jpg', 'nested-bymid-150x150.jpg' );
		$outer   = trailingslashit( $this->test_dir ) . 'nested-bymid-outer.jpg';
		$inner   = trailingslashit( $this->test_dir ) . 'nested-bymid-inner.jpg';
		$this->write_file( $outer, 'outer' );
		$this->write_file( $inner, 'inner' );
		global $wpdb;
		$meta_id            = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT meta_id FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = '_wp_attached_file' LIMIT 1", $fixture['attachment_id'] )
		);
		$this->nested_guard = array(
			'attachment_id'  => $fixture['attachment_id'],
			'meta_id'        => $meta_id,
			'outer'          => $outer,
			'inner_relative' => $this->relative_path( $inner ),
			'active'         => false,
			'nested_result'  => false,
			'outer_held'     => false,
		);
		add_filter( 'update_post_metadata_by_mid', array( $this, 'nest_regular_inside_by_mid_update' ), 10, 4 );
		$this->assertTrue( update_metadata_by_mid( 'post', $meta_id, $this->relative_path( $outer ), false ) );
		remove_filter( 'update_post_metadata_by_mid', array( $this, 'nest_regular_inside_by_mid_update' ), 10 );

		$this->assertTrue( $this->nested_guard['nested_result'] );
		$this->assertFalse( $this->nested_guard['outer_held'] );
		$this->assertSame( 1, $this->nested_guard['outer_attachment_refs'] );
		$this->assertSame( $this->relative_path( $outer ), get_post_meta( $fixture['attachment_id'], '_wp_attached_file', true ) );
		$this->assertTrue( yotm_media_source_path_is_authoritative( $outer ) );
		$this->assertFalse( yotm_media_source_path_is_authoritative( $inner ) );
		$this->assert_guard_state_clean();
	}

	public function test_regular_update_add_fallback_releases_parent_and_child_once() {
		$file = trailingslashit( $this->test_dir ) . 'update-add-fallback.jpg';
		$this->write_file( $file, 'fallback' );
		$attachment_id       = wp_insert_attachment(
			array(
				'post_mime_type' => 'image/jpeg',
				'post_title'     => 'YOTM update add fallback',
				'post_status'    => 'inherit',
			)
		);
		$this->attachments[] = $attachment_id;

		$this->fallback_attachment_id = $attachment_id;
		$this->fallback_file          = $file;
		add_filter( 'get_attached_file', array( $this, 'supply_attached_file_for_fallback' ), 10, 2 );
		$this->assertNotFalse( update_post_meta( $attachment_id, '_wp_attached_file', $this->relative_path( $file ) ) );
		remove_filter( 'get_attached_file', array( $this, 'supply_attached_file_for_fallback' ), 10 );
		$this->assertTrue( yotm_media_source_path_is_authoritative( $file ) );
		$this->assert_guard_state_clean();
	}

	public function test_proposed_attached_file_uses_filtered_live_semantics_before_write() {
		$fixture  = $this->create_attachment_with_thumbnail( 'filtered-file.jpg', 'filtered-file-150x150.jpg' );
		$raw      = trailingslashit( $this->test_dir ) . 'filtered-file-raw.jpg';
		$filtered = trailingslashit( $this->test_dir ) . 'filtered-file-authoritative.jpg';
		$this->write_file( $raw, 'raw' );
		$this->write_file( $filtered, 'filtered' );
		$filter   = static function ( $file, $attachment_id ) use ( $fixture, $raw, $filtered ) {
			return $fixture['attachment_id'] === (int) $attachment_id && wp_normalize_path( $raw ) === wp_normalize_path( (string) $file ) ? $filtered : $file;
		};
		$locked   = false;
		$observer = static function ( $check, $object_id, $meta_key ) use ( &$locked, $fixture, $filtered ) {
			if ( $fixture['attachment_id'] === (int) $object_id && '_wp_attached_file' === $meta_key ) {
				$canonical_path = yotm_media_source_canonical_path( $filtered );
				$path_lock      = yotm_media_path_lock_name( $canonical_path );
				$frames         = $GLOBALS['yotm_media_source_frames'];
				$frame          = end( $frames );
				$locked         = isset( $GLOBALS['yotm_media_path_locks'][ $path_lock ] )
					&& ! empty( $GLOBALS['yotm_media_source_fence_locks'] )
					&& in_array( $canonical_path, wp_list_pluck( $frame['protected_aliases'] ?? array(), 'path' ), true );
			}
			return $check;
		};
		add_filter( 'get_attached_file', $filter, 10, 2 );
		add_filter( 'update_post_metadata', $observer, 10, 3 );
		try {
			$proposed = yotm_media_source_proposed_aliases( $fixture['attachment_id'], '_wp_attached_file', '_wp_attached_file', $this->relative_path( $raw ) );
			$this->assertIsArray( $proposed );
			$this->assertContains( yotm_media_source_canonical_path( $filtered ), wp_list_pluck( $proposed, 'path' ) );
			$this->assertNotFalse( update_post_meta( $fixture['attachment_id'], '_wp_attached_file', $this->relative_path( $raw ) ) );
			$this->assertTrue( $locked );
			$this->assert_live_aliases_were_protected( $fixture['attachment_id'], $proposed );
			$this->assertTrue( yotm_media_source_path_is_authoritative( $filtered ) );
			$this->assert_guard_state_clean();
		} finally {
			remove_filter( 'get_attached_file', $filter, 10 );
			remove_filter( 'update_post_metadata', $observer, 10 );
		}
	}

	public function test_proposed_attachment_metadata_uses_filtered_live_semantics_before_write() {
		$fixture           = $this->create_attachment_with_thumbnail( 'filtered-meta.jpg', 'filtered-meta-150x150.jpg' );
		$raw               = trailingslashit( $this->test_dir ) . 'filtered-meta-raw.jpg';
		$filtered          = trailingslashit( $this->test_dir ) . 'filtered-meta-authoritative.jpg';
		$raw_relative      = $this->relative_path( $raw );
		$filtered_relative = $this->relative_path( $filtered );
		$this->write_file( $raw, 'raw' );
		$this->write_file( $filtered, 'filtered' );
		$filter            = static function ( $metadata, $attachment_id ) use ( $fixture, $raw_relative, $filtered_relative ) {
			if ( $fixture['attachment_id'] === (int) $attachment_id && ( $metadata['file'] ?? '' ) === $raw_relative ) {
				$metadata['file'] = $filtered_relative;
			}
			return $metadata;
		};
		$locked            = false;
		$observer          = static function ( $check, $object_id, $meta_key ) use ( &$locked, $fixture, $filtered ) {
			if ( $fixture['attachment_id'] === (int) $object_id && '_wp_attachment_metadata' === $meta_key ) {
				$canonical_path = yotm_media_source_canonical_path( $filtered );
				$path_lock      = yotm_media_path_lock_name( $canonical_path );
				$frames         = $GLOBALS['yotm_media_source_frames'];
				$frame          = end( $frames );
				$locked         = isset( $GLOBALS['yotm_media_path_locks'][ $path_lock ] )
					&& ! empty( $GLOBALS['yotm_media_source_fence_locks'] )
					&& in_array( $canonical_path, wp_list_pluck( $frame['protected_aliases'] ?? array(), 'path' ), true );
			}
			return $check;
		};
		$proposed_metadata = array(
			'file'  => $raw_relative,
			'sizes' => array(),
		);
		add_filter( 'wp_get_attachment_metadata', $filter, 10, 2 );
		add_filter( 'update_post_metadata', $observer, 10, 3 );
		try {
			$proposed = yotm_media_source_proposed_aliases( $fixture['attachment_id'], '_wp_attachment_metadata', '_wp_attachment_metadata', $proposed_metadata );
			$this->assertIsArray( $proposed );
			$this->assertContains( yotm_media_source_canonical_path( $filtered ), wp_list_pluck( $proposed, 'path' ) );
			$this->assertNotFalse( wp_update_attachment_metadata( $fixture['attachment_id'], $proposed_metadata ) );
			$this->assertTrue( $locked );
			$this->assert_live_aliases_were_protected( $fixture['attachment_id'], $proposed );
			$this->assertTrue( yotm_media_source_path_is_authoritative( $filtered ) );
			$this->assert_guard_state_clean();
		} finally {
			remove_filter( 'wp_get_attachment_metadata', $filter, 10 );
			remove_filter( 'update_post_metadata', $observer, 10 );
		}
	}

	public function test_aborted_attachment_delete_preserves_positive_source_rows() {
		$fixture = $this->create_attachment_with_thumbnail( 'delete-abort.jpg', 'delete-abort-150x150.jpg' );
		$this->assertTrue( yotm_media_source_sync_attachment( $fixture['attachment_id'] ) );
		add_action( 'delete_attachment', array( $this, 'abort_attachment_deletion' ), PHP_INT_MAX );
		try {
			wp_delete_attachment( $fixture['attachment_id'], true );
			$this->fail( 'Expected the later delete_attachment callback to abort deletion.' );
		} catch ( RuntimeException $exception ) {
			$this->assertSame( 'yotm-test-delete-abort', $exception->getMessage() );
		} finally {
			remove_action( 'delete_attachment', array( $this, 'abort_attachment_deletion' ), PHP_INT_MAX );
		}

		$this->assertInstanceOf( WP_Post::class, get_post( $fixture['attachment_id'] ) );
		$this->assertTrue( yotm_media_source_path_is_authoritative( $fixture['original'] ) );
	}

	public function test_completed_attachment_delete_removes_source_rows_post_delete() {
		$fixture = $this->create_attachment_with_thumbnail( 'delete-complete.jpg', 'delete-complete-150x150.jpg' );
		$this->assertTrue( yotm_media_source_sync_attachment( $fixture['attachment_id'] ) );
		global $wpdb;
		$table = yotm_media_source_table_name();
		$this->assertGreaterThan(
			0,
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Test inspects plugin-owned source rows directly.
			(int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE attachment_id = %d", $fixture['attachment_id'] ) )
		);

		$this->assertInstanceOf( WP_Post::class, wp_delete_attachment( $fixture['attachment_id'], true ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Test inspects plugin-owned source rows directly.
		$this->assertSame( 0, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE attachment_id = %d", $fixture['attachment_id'] ) ) );
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

	public function test_dirty_state_persistence_failure_aborts_source_mutation() {
		$fixture = $this->create_attachment_with_thumbnail( 'dirty-persist.jpg', 'dirty-persist-150x150.jpg' );
		$before  = get_post_meta( $fixture['attachment_id'], '_wp_attached_file', true );
		global $wpdb;
		$suppressing = $wpdb->suppress_errors();
		add_filter( 'query', array( $this, 'force_dirty_state_persist_failure' ) );
		try {
			$this->assertFalse( update_post_meta( $fixture['attachment_id'], '_wp_attached_file', $this->relative_path( $fixture['thumbnail'] ) ) );
		} finally {
			remove_filter( 'query', array( $this, 'force_dirty_state_persist_failure' ) );
			$wpdb->suppress_errors( $suppressing );
		}
		$this->assertSame( $before, get_post_meta( $fixture['attachment_id'], '_wp_attached_file', true ) );
		$this->assertWPError( $GLOBALS['yotm_media_source_last_error'] );
		$this->assertSame( 'yotm_media_source_dirty_persist_failed', $GLOBALS['yotm_media_source_last_error']->get_error_code() );
		$this->assertTrue( yotm_media_source_require_clean_index() );
		$this->assert_guard_state_clean();
		$wpdb->last_error = '';
	}

	public function test_later_short_circuit_retains_positive_rows_until_shutdown_cleanup() {
		$fixture = $this->create_attachment_with_thumbnail( 'short-circuit.jpg', 'short-circuit-150x150.jpg' );
		$before  = get_post_meta( $fixture['attachment_id'], '_wp_attached_file', true );
		add_filter( 'update_post_metadata', array( $this, 'short_circuit_source_update' ), PHP_INT_MAX - 1, 5 );
		$this->assertFalse( update_post_meta( $fixture['attachment_id'], '_wp_attached_file', $this->relative_path( $fixture['thumbnail'] ) ) );
		remove_filter( 'update_post_metadata', array( $this, 'short_circuit_source_update' ), PHP_INT_MAX - 1 );
		$this->assertSame( $before, get_post_meta( $fixture['attachment_id'], '_wp_attached_file', true ) );
		$this->assertCount( 1, $GLOBALS['yotm_media_source_frames'] );
		$this->assertFalse( $GLOBALS['yotm_media_source_frames'][0]['ready'] );
		$this->assertWPError( yotm_media_source_require_clean_index() );
		$lock_names            = array_keys( $GLOBALS['yotm_media_path_locks'] );
		$attachment_lock_names = array_keys( $GLOBALS['yotm_media_attachment_locks'] );
		global $wpdb;
		$table = yotm_media_source_table_name();
		$hash  = hash( 'sha256', yotm_media_source_canonical_path( $fixture['thumbnail'] ) );
		$this->assertGreaterThan(
			0,
			(int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE path_hash = %s", $hash ) )
		);
		yotm_media_source_shutdown_cleanup();
		foreach ( $lock_names as $lock_name ) {
			$this->assertSame( '1', (string) $wpdb->get_var( $wpdb->prepare( 'SELECT IS_FREE_LOCK(%s)', $lock_name ) ) );
		}
		foreach ( $attachment_lock_names as $lock_name ) {
			$this->assertSame( '1', (string) $wpdb->get_var( $wpdb->prepare( 'SELECT IS_FREE_LOCK(%s)', $lock_name ) ) );
		}
		$this->assertTrue( yotm_media_source_require_clean_index() );
		$this->assert_guard_state_clean();
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
		$dirty = yotm_media_source_require_clean_index();
		$this->assertWPError( $dirty );
		$this->assertSame( 'yotm_media_source_index_dirty', $dirty->get_error_code() );
		$lock_names            = array_keys( $GLOBALS['yotm_media_path_locks'] );
		$attachment_lock_names = array_keys( $GLOBALS['yotm_media_attachment_locks'] );
		yotm_media_source_shutdown_cleanup();
		foreach ( $lock_names as $lock_name ) {
			$this->assertSame( '1', (string) $wpdb->get_var( $wpdb->prepare( 'SELECT IS_FREE_LOCK(%s)', $lock_name ) ) );
		}
		foreach ( $attachment_lock_names as $lock_name ) {
			$this->assertSame( '1', (string) $wpdb->get_var( $wpdb->prepare( 'SELECT IS_FREE_LOCK(%s)', $lock_name ) ) );
		}
		$this->assert_guard_state_clean();
		$dirty = yotm_media_source_require_clean_index();
		$this->assertWPError( $dirty );
		$this->assertSame( 'yotm_media_source_index_dirty', $dirty->get_error_code() );
		$this->assertTrue( yotm_media_source_sync_attachment( $fixture['attachment_id'], null, true ) );
		$this->assertTrue( yotm_media_source_require_clean_index() );
		$wpdb->last_error = '';
	}

	public function test_nondeterministic_filtered_alias_failure_stays_dirty_until_repair() {
		$owner      = $this->create_attachment_with_thumbnail( 'dirty-owner.jpg', 'dirty-owner-150x150.jpg' );
		$candidates = $this->collect_candidates( $owner['attachment_id'] );
		$candidate  = reset( $candidates );
		$initial    = trailingslashit( $this->test_dir ) . 'dirty-other.jpg';
		$raw        = trailingslashit( $this->test_dir ) . 'dirty-raw.jpg';
		$p1         = trailingslashit( $this->test_dir ) . 'dirty-filter-p1.jpg';
		$p2         = $owner['thumbnail'];
		$this->write_file( $initial, 'initial' );
		$this->write_file( $raw, 'raw' );
		$this->write_file( $p1, 'p1' );
		$other_id = $this->create_attachment( $initial, array( 'file' => $this->relative_path( $initial ) ) );
		$calls    = 0;
		$filter   = static function ( $file, $attachment_id ) use ( &$calls, $other_id, $raw, $p1, $p2 ) {
			if ( $other_id === (int) $attachment_id && wp_normalize_path( $raw ) === wp_normalize_path( (string) $file ) ) {
				++$calls;
				return 1 === $calls ? $p1 : $p2;
			}
			return $file;
		};

		$this->failed_source_hash       = hash( 'sha256', yotm_media_source_canonical_path( $p2 ) );
		$this->filtered_source_callback = $filter;
		add_filter( 'get_attached_file', $filter, 10, 2 );
		global $wpdb;
		$suppressing = $wpdb->suppress_errors();
		add_filter( 'query', array( $this, 'force_filtered_source_upsert_failure' ) );
		try {
			$this->assertNotFalse( update_post_meta( $other_id, '_wp_attached_file', $this->relative_path( $raw ) ) );
		} finally {
			remove_filter( 'query', array( $this, 'force_filtered_source_upsert_failure' ) );
			$wpdb->suppress_errors( $suppressing );
		}

		$this->assertGreaterThanOrEqual( 2, $calls );
		$this->assertSame( $this->relative_path( $raw ), get_post_meta( $other_id, '_wp_attached_file', true ) );
		$this->assertWPError( $GLOBALS['yotm_media_source_last_error'] );
		$table = yotm_media_source_table_name();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- A conservative pre-write positive may remain, but the unresolved mutation must stay dirty.
		$this->assertGreaterThanOrEqual( 1, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE path_hash = %s", $this->failed_source_hash ) ) );
		$this->assertNotEmpty( yotm_media_source_dirty_state()['entries'] );

		yotm_media_source_shutdown_cleanup();
		wp_cache_delete( YOTM_MEDIA_SOURCE_DIRTY_OPTION, 'options' );
		$this->assertNotEmpty( yotm_media_source_dirty_state()['entries'] );
		$protected = yotm_media_source_path_is_authoritative( $p2 );
		$this->assertWPError( $protected );
		$this->assertSame( 'yotm_media_source_index_dirty', $protected->get_error_code() );
		$this->assertSame( array(), $this->collect_candidates( $owner['attachment_id'] ) );

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
		$this->assertIsArray( $job );
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
		$delete_validation = yotm_prune_validate_delete_job( yotm_job_get_by_id( $job['id'] ), 'dirty-index' );
		$this->assertWPError( $delete_validation );
		$this->assertSame( 'yotm_media_source_index_dirty', $delete_validation->get_error_code() );
		$worker = yotm_job_acquire_worker( $job['id'], array( 'deleting' ), array( 'delete' ) );
		$this->assertIsArray( $worker );
		$item   = yotm_job_claim_items( $worker, 1 )[0];
		$result = yotm_process_claimed_prune_item( $item, yotm_job_get_by_id( $job['id'] ), $worker, $this->uploads_base );
		$this->assertIsArray( $result );
		$this->assertFalse( $result['deleted'] );
		$this->assertFalse( $result['skipped'] );
		$this->assertFileExists( $p2 );
		yotm_job_release_item_claim( $item );
		yotm_job_release_worker( $worker );

		$this->assertTrue( yotm_media_source_sync_attachment( $other_id, null, true ) );
		$this->assertTrue( yotm_media_source_require_clean_index() );
		$this->assertTrue( yotm_media_source_path_is_authoritative( $p2 ) );
		$this->assertFalse( yotm_media_source_path_is_authoritative( $p1 ) );
		$this->assert_guard_state_clean();
		remove_filter( 'get_attached_file', $filter, 10 );
		$this->filtered_source_callback = null;
		$this->failed_source_hash       = '';
		$wpdb->last_error               = '';
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

	public function test_attachment_lock_is_request_reentrant_and_balanced() {
		$fixture = $this->create_attachment_with_thumbnail( 'attachment-lock.jpg', 'attachment-lock-150x150.jpg' );
		$first   = yotm_media_attachment_lock_acquire( $fixture['attachment_id'] );
		$second  = yotm_media_attachment_lock_acquire( $fixture['attachment_id'] );
		$this->assertIsArray( $first );
		$this->assertSame( $first['name'], $second['name'] );
		$this->assertSame( 2, $GLOBALS['yotm_media_attachment_locks'][ $first['name'] ]['refs'] );
		yotm_media_attachment_lock_release( $second );
		$this->assertSame( 1, $GLOBALS['yotm_media_attachment_locks'][ $first['name'] ]['refs'] );
		yotm_media_attachment_lock_release( $first );
		$this->assertArrayNotHasKey( $first['name'], $GLOBALS['yotm_media_attachment_locks'] );
	}

	public function test_source_fence_is_request_reentrant_and_balanced() {
		$first  = yotm_media_source_fence_acquire();
		$second = yotm_media_source_fence_acquire();
		$this->assertIsArray( $first );
		$this->assertSame( $first['name'], $second['name'] );
		$this->assertSame( 2, $GLOBALS['yotm_media_source_fence_locks'][ $first['name'] ]['refs'] );
		yotm_media_source_fence_release( $second );
		$this->assertSame( 1, $GLOBALS['yotm_media_source_fence_locks'][ $first['name'] ]['refs'] );
		yotm_media_source_fence_release( $first );
		$this->assertArrayNotHasKey( $first['name'], $GLOBALS['yotm_media_source_fence_locks'] );
	}

	public function nest_by_mid_inside_regular_update( $check, $object_id, $meta_key, $meta_value, $prev_value ) {
		unset( $meta_value, $prev_value );
		if (
			! empty( $this->nested_guard['active'] )
			|| (int) ( $this->nested_guard['attachment_id'] ?? 0 ) !== (int) $object_id
			|| '_wp_attached_file' !== $meta_key
		) {
			return $check;
		}

		$this->nested_guard['active']                = true;
		$this->nested_guard['nested_result']         = update_metadata_by_mid( 'post', $this->nested_guard['meta_id'], $this->nested_guard['inner_relative'], false );
		$outer_lock                                  = yotm_media_path_lock_name( yotm_media_source_canonical_path( $this->nested_guard['outer'] ) );
		$this->nested_guard['outer_held']            = isset( $GLOBALS['yotm_media_path_locks'][ $outer_lock ] );
		$attachment_lock                             = yotm_media_attachment_lock_name( $this->nested_guard['attachment_id'] );
		$this->nested_guard['outer_attachment_refs'] = (int) ( $GLOBALS['yotm_media_attachment_locks'][ $attachment_lock ]['refs'] ?? 0 );
		$this->nested_guard['active']                = false;

		return $check;
	}

	public function nest_regular_inside_by_mid_update( $check, $meta_id, $meta_value, $meta_key ) {
		unset( $meta_value, $meta_key );
		if ( ! empty( $this->nested_guard['active'] ) || (int) ( $this->nested_guard['meta_id'] ?? 0 ) !== (int) $meta_id ) {
			return $check;
		}

		$this->nested_guard['active']                = true;
		$this->nested_guard['nested_result']         = update_post_meta( $this->nested_guard['attachment_id'], '_wp_attached_file', $this->nested_guard['inner_relative'] );
		$outer_lock                                  = yotm_media_path_lock_name( yotm_media_source_canonical_path( $this->nested_guard['outer'] ) );
		$this->nested_guard['outer_held']            = isset( $GLOBALS['yotm_media_path_locks'][ $outer_lock ] );
		$attachment_lock                             = yotm_media_attachment_lock_name( $this->nested_guard['attachment_id'] );
		$this->nested_guard['outer_attachment_refs'] = (int) ( $GLOBALS['yotm_media_attachment_locks'][ $attachment_lock ]['refs'] ?? 0 );
		$this->nested_guard['active']                = false;

		return $check;
	}

	public function abort_attachment_deletion() {
		throw new RuntimeException( 'yotm-test-delete-abort' );
	}

	public function supply_attached_file_for_fallback( $file, $attachment_id ) {
		return $this->fallback_attachment_id === (int) $attachment_id && empty( $file ) ? $this->fallback_file : $file;
	}

	public function filter_authoritative_metadata( $check, $object_id, $meta_key, $single, $meta_type ) {
		unset( $meta_type, $single );
		if ( (int) ( $this->metadata_filter_fixture['attachment_id'] ?? 0 ) !== (int) $object_id ) {
			return $check;
		}
		if ( '_wp_attached_file' === $meta_key ) {
			return array( '' );
		}
		if ( '_wp_attachment_metadata' === $meta_key ) {
			$value = $this->metadata_filter_fixture['metadata'];
			return array( $value );
		}
		return $check;
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
		return false !== strpos( $query, 'INSERT INTO ' . yotm_media_source_table_name() ) ? 'SELECT * FROM yotm_missing_source_table' : $query;
	}

	public function force_source_resync_failure( $query ) {
		return false !== strpos( $query, 'SELECT id,source_kind,path_hash FROM ' . yotm_media_source_table_name() ) ? 'SELECT * FROM yotm_missing_source_table' : $query;
	}

	public function force_filtered_source_upsert_failure( $query ) {
		return '' !== $this->failed_source_hash
			&& false !== strpos( $query, 'INSERT INTO ' . yotm_media_source_table_name() )
			&& false !== strpos( $query, $this->failed_source_hash )
			? 'SELECT * FROM yotm_missing_source_table'
			: $query;
	}

	public function force_dirty_state_persist_failure( $query ) {
		global $wpdb;

		return false !== strpos( $query, $wpdb->options ) && false !== strpos( $query, YOTM_MEDIA_SOURCE_DIRTY_OPTION )
			? 'SELECT * FROM yotm_missing_source_table'
			: $query;
	}

	public function short_circuit_source_update() {
		return false;
	}

	private function assert_guard_state_clean() {
		$this->assertEmpty( $GLOBALS['yotm_media_source_frames'] ?? array() );
		$this->assertEmpty( $GLOBALS['yotm_media_source_invocations'] ?? array() );
		$this->assertEmpty( $GLOBALS['yotm_media_path_locks'] ?? array() );
		$this->assertEmpty( $GLOBALS['yotm_media_attachment_locks'] ?? array() );
		$this->assertEmpty( $GLOBALS['yotm_media_source_fence_locks'] ?? array() );
	}

	private function assert_live_aliases_were_protected( $attachment_id, $proposed ) {
		$protected = array();
		foreach ( $proposed as $alias ) {
			$protected[] = $alias['source_kind'] . ':' . $alias['path_hash'];
		}
		$live = yotm_media_source_aliases( $attachment_id );
		$this->assertIsArray( $live );
		foreach ( $live as $alias ) {
			$this->assertContains( $alias['source_kind'] . ':' . $alias['path_hash'], $protected );
		}
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

	private function create_real_legacy_fixture( $stem ) {
		$source = trailingslashit( $this->test_dir ) . $stem . '.jpg';
		copy( DIR_TESTDATA . '/images/2004-07-22-DSC_0007.jpg', $source );
		$this->files[] = $source;
		$image         = wp_getimagesize( $source );
		$attachment_id = $this->create_attachment(
			$source,
			array(
				'file'   => $this->relative_path( $source ),
				'width'  => absint( $image[0] ),
				'height' => absint( $image[1] ),
				'sizes'  => array(),
			)
		);
		$editor        = wp_get_image_editor( $source );
		$this->assertNotWPError( $editor );
		$created = $editor->make_subsize(
			array(
				'width'  => 150,
				'height' => 150,
				'crop'   => true,
			)
		);
		$this->assertNotWPError( $created );
		$candidate     = trailingslashit( $this->test_dir ) . $created['file'];
		$this->files[] = $candidate;
		$this->assertTrue( yotm_media_source_sync_attachment( $attachment_id ) );

		return array(
			'attachment_id' => $attachment_id,
			'source'        => yotm_media_source_canonical_path( $source ),
			'source_width'  => absint( $image[0] ),
			'source_height' => absint( $image[1] ),
			'candidate'     => yotm_media_source_canonical_path( $candidate ),
		);
	}

	private function legacy_policy( $keep, $remove ) {
		$scan_base                = trailingslashit( yotm_normalize_filesystem_path( realpath( $this->test_dir ) ) );
		$payload                  = array(
			'scan_base'          => $scan_base,
			'scan_bases'         => array( $scan_base ),
			'selector'           => 'attachment_id_v1',
			'selection_meta_max' => 0,
			'selection_subpaths' => array(),
			'keep'               => $keep,
			'remove'             => $remove,
			'sizes'              => array(
				'legacy_keep'   => array(
					'width'  => 150,
					'height' => 150,
					'crop'   => true,
				),
				'legacy_remove' => array(
					'width'  => 150,
					'height' => 150,
					'crop'   => true,
				),
			),
			'discover_orphans'   => 1,
		);
		$payload['legacy_policy'] = array(
			'version' => 1,
			'enabled' => 1,
			'hash'    => yotm_legacy_policy_hash( $payload ),
		);
		return $payload;
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

	private function create_import_attachment() {
		$attachment_id = wp_insert_attachment(
			array(
				'post_mime_type' => 'import',
				'post_title'     => 'YOTM import source test',
				'post_status'    => 'inherit',
			)
		);
		$this->assertIsInt( $attachment_id );
		$this->attachments[] = $attachment_id;
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
