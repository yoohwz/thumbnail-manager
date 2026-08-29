<?php

/**
 * Filesystem and metadata safety tests.
 */
class YOTM_Prune_Safety_Test extends WP_UnitTestCase {
	/** @var string */
	private $uploads_base;

	/** @var string */
	private $test_dir;

	/** @var string[] */
	private $files = array();

	/** @var string[] */
	private $directories = array();

	/** @var int */
	private $attachment_id = 0;

	/** @var int[] */
	private $job_ids = array();

	public function setUp(): void {
		parent::setUp();
		unset( $GLOBALS['yotm_job_storage_readiness'] );
		$this->assertTrue( yotm_run_job_table_migration() );
		delete_option( YOTM_MEDIA_SOURCE_DIRTY_OPTION );
		$this->assertTrue( yotm_media_source_clear_index() );
		$reference_state = yotm_media_reference_index_state();
		$this->assertIsArray( $reference_state );
		$this->assertTrue( yotm_media_reference_baseline_complete( $reference_state['baseline_token'] ) );
		$uploads            = wp_get_upload_dir();
		$this->uploads_base = trailingslashit( $uploads['basedir'] );
		$this->test_dir     = $this->uploads_base . 'yotm-phpunit-' . wp_generate_uuid4();
		wp_mkdir_p( $this->test_dir );
		$this->directories[] = $this->test_dir;
	}

	public function tearDown(): void {
		foreach ( $this->job_ids as $job_id ) {
			yotm_job_delete( $job_id );
		}

		if ( $this->attachment_id ) {
			wp_delete_post( $this->attachment_id, true );
		}

		foreach ( array_unique( $this->files ) as $file ) {
			if ( is_link( $file ) || file_exists( $file ) ) {
				@unlink( $file );
			}
		}

		foreach ( array_reverse( array_unique( $this->directories ) ) as $directory ) {
			if ( is_dir( $directory ) ) {
				@rmdir( $directory );
			}
		}
		delete_option( YOTM_MEDIA_REFERENCE_STATE_OPTION );
		delete_option( YOTM_MEDIA_SOURCE_DIRTY_OPTION );

		parent::tearDown();
	}

	public function test_path_safety_functions_are_owned_by_media_paths_module() {
		$expected  = wp_normalize_path( dirname( __DIR__, 2 ) . '/inc/media/paths.php' );
		$functions = array(
			'yotm_normalize_filesystem_path',
			'yotm_is_path_inside_dir',
			'yotm_resolve_upload_scan_bases',
			'yotm_media_source_canonical_path',
		);

		foreach ( $functions as $function ) {
			$reflection = new ReflectionFunction( $function );
			$this->assertSame( $expected, wp_normalize_path( $reflection->getFileName() ), $function );
		}
	}

	public function test_prune_media_primitives_have_one_media_owner_without_jobs_or_transport_calls() {
		$root         = dirname( __DIR__, 2 );
		$media_module = wp_normalize_path( $root . '/inc/media/prune.php' );
		$functions    = array(
			'yotm_prune_normalize_candidate_evidence',
			'yotm_initial_orphan_summary',
			'yotm_add_prune_candidate',
			'yotm_collect_metadata_prune_candidates_for_ids',
			'yotm_dimension_from_size_data',
			'yotm_dimension_matches_keep',
			'yotm_get_thumbnail_file_variants',
			'yotm_prune_validate_delete_meta',
			'yotm_delete_file_and_count',
			'yotm_prune_journal_lexical_path',
			'yotm_prune_journal_node_fingerprint',
			'yotm_prune_journal_path_state',
			'yotm_prune_validate_live_reference_evidence',
			'yotm_validate_prune_item_ownership',
			'yotm_delete_prune_item',
			'yotm_reconcile_prune_item_metadata',
			'yotm_delete_file_with_result',
			'yotm_remove_attachment_size_metadata',
		);

		foreach ( $functions as $function ) {
			$reflection = new ReflectionFunction( $function );
			$this->assertSame( $media_module, wp_normalize_path( $reflection->getFileName() ), $function );
		}

		$source = file_get_contents( $media_module );
		$this->assertIsString( $source );
		$this->assertDoesNotMatchRegularExpression( '/\byotm_job_/', $source );
		$this->assertDoesNotMatchRegularExpression( '/\$_POST|\bwp_send_json_/', $source );

		$delete_coordinator = new ReflectionFunction( 'yotm_delete_prune_item_recoverable' );
		$scan_coordinator   = new ReflectionFunction( 'yotm_prune_source_index_batch' );
		$this->assertStringEndsWith( '/inc/handle-delete.php', wp_normalize_path( $delete_coordinator->getFileName() ) );
		$this->assertStringEndsWith( '/inc/handle-prune.php', wp_normalize_path( $scan_coordinator->getFileName() ) );
	}

	public function test_path_outside_uploads_cannot_be_deleted() {
		$outside       = trailingslashit( sys_get_temp_dir() ) . 'yotm-outside-' . wp_generate_uuid4() . '.jpg';
		$this->files[] = $outside;
		file_put_contents( $outside, 'outside' );

		$this->assertSame( 0, yotm_delete_file_and_count( $outside ) );
		$this->assertFileExists( $outside );
	}

	public function test_symlink_to_outside_uploads_is_rejected() {
		if ( ! function_exists( 'symlink' ) ) {
			$this->markTestSkipped( 'Symlinks are unavailable.' );
		}

		$outside       = trailingslashit( sys_get_temp_dir() ) . 'yotm-target-' . wp_generate_uuid4() . '.jpg';
		$link          = trailingslashit( $this->test_dir ) . 'linked-150x150.jpg';
		$this->files[] = $outside;
		$this->files[] = $link;
		file_put_contents( $outside, 'outside' );

		if ( ! @symlink( $outside, $link ) ) {
			$this->markTestSkipped( 'The environment refused symlink creation.' );
		}

		$this->assertFalse( yotm_is_path_inside_dir( $link, $this->uploads_base ) );
		$result = yotm_delete_prune_item( array( 'path' => $link ), $this->uploads_base );
		$this->assertFalse( $result['deleted'] );
		$this->assertFileExists( $outside );
	}

	public function test_multiple_upload_scopes_are_deduplicated_and_resolved_safely() {
		$year_2020  = trailingslashit( $this->test_dir ) . '2020';
		$month_2020 = trailingslashit( $year_2020 ) . '01';
		$year_2021  = trailingslashit( $this->test_dir ) . '2021';
		$month_2021 = trailingslashit( $year_2021 ) . '02';
		wp_mkdir_p( $month_2020 );
		wp_mkdir_p( $month_2021 );
		$this->directories[] = $year_2020;
		$this->directories[] = $month_2020;
		$this->directories[] = $year_2021;
		$this->directories[] = $month_2021;

		$normalized = yotm_normalize_upload_subpaths( array( '2020/01', '2020', '2021/02', '2021/02' ) );
		$this->assertSame( array( '2020', '2021/02' ), $normalized );

		$resolved = yotm_resolve_upload_scan_bases( $this->test_dir, $normalized );
		$this->assertIsArray( $resolved );
		$this->assertCount( 2, $resolved );
		$this->assertSame( trailingslashit( yotm_normalize_filesystem_path( $year_2020 ) ), $resolved[0] );
		$this->assertSame( trailingslashit( yotm_normalize_filesystem_path( $month_2021 ) ), $resolved[1] );
		$this->assertTrue( yotm_is_path_inside_any_dir( $month_2020 . '/image.jpg', $resolved ) );
		$this->assertFalse( yotm_is_path_inside_any_dir( $year_2021 . '/03/image.jpg', $resolved ) );

		$query_args = yotm_attachment_query_args_for_upload_subpaths( $normalized );
		$this->assertSame( '^(2020|2021/02)/', $query_args['meta_query'][0]['value'] );
	}

	public function test_original_is_protected_and_deleted_thumbnail_metadata_is_removed() {
		$original      = trailingslashit( $this->test_dir ) . 'image-300x300.jpg';
		$thumbnail     = trailingslashit( $this->test_dir ) . 'image-300x300-150x150.jpg';
		$this->files[] = $original;
		$this->files[] = $thumbnail;
		file_put_contents( $original, 'original' );
		file_put_contents( $thumbnail, 'thumbnail' );

		$this->attachment_id = wp_insert_attachment(
			array(
				'post_mime_type' => 'image/jpeg',
				'post_title'     => 'YOTM PHPUnit',
				'post_status'    => 'inherit',
			),
			$original
		);
		$relative            = ltrim( str_replace( $this->uploads_base, '', $original ), '/' );
		wp_update_attachment_metadata(
			$this->attachment_id,
			array(
				'file'  => $relative,
				'sizes' => array(
					'thumbnail' => array(
						'file'      => basename( $thumbnail ),
						'width'     => 150,
						'height'    => 150,
						'mime-type' => 'image/jpeg',
					),
				),
			)
		);

		$candidates     = array();
		$orphan_summary = yotm_initial_orphan_summary();
		yotm_collect_metadata_prune_candidates_for_ids(
			array( $this->attachment_id ),
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
			$orphan_summary
		);

		$paths = array_column( array_values( $candidates ), 'path' );
		$this->assertNotContains( yotm_normalize_filesystem_path( $original ), $paths );
		$this->assertContains( yotm_normalize_filesystem_path( $thumbnail ), $paths );

		$candidate = $candidates[ yotm_normalize_filesystem_path( $thumbnail ) ];
		$result    = yotm_delete_prune_item( $candidate, $this->uploads_base );
		$metadata  = wp_get_attachment_metadata( $this->attachment_id );

		$this->assertTrue( $result['deleted'] );
		$this->assertFileExists( $original );
		$this->assertArrayNotHasKey( 'thumbnail', $metadata['sizes'] );
	}

	public function test_recovery_only_preserves_intact_armed_file() {
		$fixture = $this->create_armed_prune_claim( 'recovery-only' );
		$result  = yotm_delete_prune_item_recoverable( $fixture['item'], $fixture['payload'], $this->uploads_base, true );

		$this->assertIsArray( $result );
		$this->assertFalse( $result['deleted'] );
		$this->assertFalse( $result['skipped'] );
		$this->assertFileExists( $fixture['path'] );
		$this->assertArrayHasKey( 'thumbnail', wp_get_attachment_metadata( $this->attachment_id )['sizes'] );
		$this->assertSame( '', $fixture['item']['payload']['prune_operation_journal_v1']['outcome'] );
		$this->assertTrue( yotm_job_finish_item_v3( $fixture['item'], $fixture['worker'], 'failed', $result['error'] ) );
		$this->assertSame( 'failed', yotm_job_get_item_by_key( $fixture['job']['id'], $fixture['item_key'] )['status'] );
		yotm_job_release_worker( $fixture['worker'] );
	}

	public function test_armed_path_true_absence_reconciles_post_unlink_outcome() {
		$fixture = $this->create_armed_prune_claim( 'absent' );
		$this->assertTrue( unlink( $fixture['path'] ) );

		$result = yotm_delete_prune_item_recoverable( $fixture['item'], $fixture['payload'], $this->uploads_base, true );

		$this->assertIsArray( $result );
		$this->assertTrue( $result['deleted'] );
		$this->assertSame( 'delete_reconciled', $fixture['item']['payload']['prune_operation_journal_v1']['outcome'] );
		$this->assertArrayNotHasKey( 'thumbnail', wp_get_attachment_metadata( $this->attachment_id )['sizes'] );
		yotm_job_release_item_claim( $fixture['item'] );
		yotm_job_release_worker( $fixture['worker'] );
	}

	public function test_armed_path_directory_replacement_fails_closed() {
		$fixture = $this->create_armed_prune_claim( 'directory' );
		$this->assertTrue( unlink( $fixture['path'] ) );
		$this->assertTrue( mkdir( $fixture['path'] ) );
		$this->directories[] = $fixture['path'];

		$result = yotm_delete_prune_item_recoverable( $fixture['item'], $fixture['payload'], $this->uploads_base, true );

		$this->assertIsArray( $result );
		$this->assertFalse( $result['deleted'] );
		$this->assertTrue( is_dir( $fixture['path'] ) );
		$this->assertArrayHasKey( 'thumbnail', wp_get_attachment_metadata( $this->attachment_id )['sizes'] );
		$this->assertSame( '', $fixture['item']['payload']['prune_operation_journal_v1']['outcome'] );
		yotm_job_release_item_claim( $fixture['item'] );
		yotm_job_release_worker( $fixture['worker'] );
	}

	public function test_armed_path_symlink_and_broken_symlink_replacements_fail_closed() {
		if ( ! function_exists( 'symlink' ) ) {
			$this->markTestSkipped( 'Symlinks are unavailable.' );
		}

		$fixture       = $this->create_armed_prune_claim( 'symlink' );
		$target        = trailingslashit( $this->test_dir ) . 'replacement-target.jpg';
		$this->files[] = $target;
		file_put_contents( $target, 'replacement' );
		$this->assertTrue( unlink( $fixture['path'] ) );
		$this->assertTrue( symlink( $target, $fixture['path'] ) );

		$result = yotm_delete_prune_item_recoverable( $fixture['item'], $fixture['payload'], $this->uploads_base, true );
		$this->assertIsArray( $result );
		$this->assertFalse( $result['deleted'] );
		$this->assertTrue( is_link( $fixture['path'] ) );
		$this->assertArrayHasKey( 'thumbnail', wp_get_attachment_metadata( $this->attachment_id )['sizes'] );

		$this->assertTrue( unlink( $fixture['path'] ) );
		$this->assertTrue( unlink( $target ) );
		$this->assertTrue( symlink( $target, $fixture['path'] ) );
		$result = yotm_delete_prune_item_recoverable( $fixture['item'], $fixture['payload'], $this->uploads_base, true );
		$this->assertIsArray( $result );
		$this->assertFalse( $result['deleted'] );
		$this->assertTrue( is_link( $fixture['path'] ) );
		$this->assertArrayHasKey( 'thumbnail', wp_get_attachment_metadata( $this->attachment_id )['sizes'] );
		yotm_job_release_item_claim( $fixture['item'] );
		yotm_job_release_worker( $fixture['worker'] );
	}

	public function test_armed_path_changed_regular_file_fails_closed() {
		$fixture = $this->create_armed_prune_claim( 'changed-file' );
		file_put_contents( $fixture['path'], 'changed replacement data' );

		$result = yotm_delete_prune_item_recoverable( $fixture['item'], $fixture['payload'], $this->uploads_base, true );

		$this->assertIsArray( $result );
		$this->assertFalse( $result['deleted'] );
		$this->assertFileExists( $fixture['path'] );
		$this->assertArrayHasKey( 'thumbnail', wp_get_attachment_metadata( $this->attachment_id )['sizes'] );
		$this->assertSame( '', $fixture['item']['payload']['prune_operation_journal_v1']['outcome'] );
		yotm_job_release_item_claim( $fixture['item'] );
		yotm_job_release_worker( $fixture['worker'] );
	}

	public function test_armed_path_byte_identical_regular_file_replacement_fails_closed() {
		$fixture          = $this->create_armed_prune_claim( 'same-content-replacement' );
		$replacement      = trailingslashit( $this->test_dir ) . 'same-content-replacement.tmp';
		$this->files[]    = $replacement;
		$reviewed_content = file_get_contents( $fixture['path'] );
		$this->assertIsString( $reviewed_content );
		$this->assertSame( strlen( $reviewed_content ), file_put_contents( $replacement, $reviewed_content ) );

		$replacement_node = yotm_prune_journal_path_state( $replacement );
		$this->assertIsArray( $replacement_node );
		$this->assertNotSame( $fixture['item']['payload']['prune_operation_journal_v1']['node_fingerprint'], $replacement_node['fingerprint'] );
		$this->assertTrue( unlink( $fixture['path'] ) );
		$this->assertTrue( rename( $replacement, $fixture['path'] ) );

		$result = yotm_delete_prune_item_recoverable( $fixture['item'], $fixture['payload'], $this->uploads_base, false );

		$this->assertIsArray( $result );
		$this->assertFalse( $result['deleted'] );
		$this->assertFileExists( $fixture['path'] );
		$this->assertSame( $reviewed_content, file_get_contents( $fixture['path'] ) );
		$this->assertArrayHasKey( 'thumbnail', wp_get_attachment_metadata( $this->attachment_id )['sizes'] );
		$this->assertSame( '', $fixture['item']['payload']['prune_operation_journal_v1']['outcome'] );
		yotm_job_release_item_claim( $fixture['item'] );
		yotm_job_release_worker( $fixture['worker'] );
	}

	public function test_armed_unchanged_regular_file_can_complete_active_delete() {
		$fixture = $this->create_armed_prune_claim( 'unchanged-active-delete' );
		$result  = yotm_delete_prune_item_recoverable( $fixture['item'], $fixture['payload'], $this->uploads_base, false );

		$this->assertIsArray( $result );
		$this->assertTrue( $result['deleted'] );
		$this->assertFileDoesNotExist( $fixture['path'] );
		$this->assertArrayNotHasKey( 'thumbnail', wp_get_attachment_metadata( $this->attachment_id )['sizes'] );
		$this->assertSame( 'delete_reconciled', $fixture['item']['payload']['prune_operation_journal_v1']['outcome'] );
		yotm_job_release_item_claim( $fixture['item'] );
		yotm_job_release_worker( $fixture['worker'] );
	}

	public function test_armed_regular_file_without_node_fingerprint_fails_closed() {
		$fixture = $this->create_armed_prune_claim( 'legacy-journal' );
		unset( $fixture['item']['payload']['prune_operation_journal_v1']['node_fingerprint'] );
		$result = yotm_delete_prune_item_recoverable( $fixture['item'], $fixture['payload'], $this->uploads_base, false );

		$this->assertIsArray( $result );
		$this->assertFalse( $result['deleted'] );
		$this->assertFileExists( $fixture['path'] );
		$this->assertArrayHasKey( 'thumbnail', wp_get_attachment_metadata( $this->attachment_id )['sizes'] );
		yotm_job_release_item_claim( $fixture['item'] );
		yotm_job_release_worker( $fixture['worker'] );
	}

	public function test_force_regenerate_cleanup_removes_only_obsolete_generated_files() {
		$original      = trailingslashit( $this->test_dir ) . 'force-image.jpg';
		$stale         = trailingslashit( $this->test_dir ) . 'force-image-100x100.jpg';
		$current       = trailingslashit( $this->test_dir ) . 'force-image-200x200.jpg';
		$this->files[] = $original;
		$this->files[] = $stale;
		$this->files[] = $current;
		$old_metadata  = array(
			'sizes' => array(
				'old-size'     => array( 'file' => basename( $stale ) ),
				'current-size' => array( 'file' => basename( $current ) ),
			),
		);
		$new_metadata  = array(
			'sizes' => array(
				'current-size' => array( 'file' => basename( $current ) ),
			),
		);

		file_put_contents( $original, 'original' );
		file_put_contents( $stale, 'stale' );
		file_put_contents( $current, 'current' );
		yotm_cleanup_obsolete_generated_files( $original, $original, $old_metadata, $new_metadata );

		$this->assertFileDoesNotExist( $stale );
		$this->assertFileExists( $current );
		$this->assertFileExists( $original );
	}

	/**
	 * Create one claimed item with an armed pre-delete journal.
	 *
	 * @param string $suffix Fixture suffix.
	 * @return array
	 */
	private function create_armed_prune_claim( $suffix ) {
		$original      = trailingslashit( $this->test_dir ) . $suffix . '-source.jpg';
		$candidate     = trailingslashit( $this->test_dir ) . $suffix . '-source-150x150.jpg';
		$data          = 'reviewed prune bytes';
		$this->files[] = $original;
		$this->files[] = $candidate;
		file_put_contents( $original, 'source' );
		file_put_contents( $candidate, $data );

		$this->attachment_id = wp_insert_attachment(
			array(
				'post_mime_type' => 'image/jpeg',
				'post_title'     => 'YOTM recovery journal fixture',
				'post_status'    => 'inherit',
			),
			$original
		);
		$relative            = ltrim( str_replace( $this->uploads_base, '', $original ), '/' );
		update_attached_file( $this->attachment_id, $relative );
		wp_update_attachment_metadata(
			$this->attachment_id,
			array(
				'file'  => $relative,
				'sizes' => array(
					'thumbnail' => array(
						'file'      => wp_basename( $candidate ),
						'width'     => 150,
						'height'    => 150,
						'mime-type' => 'image/jpeg',
					),
				),
			)
		);

		$payload         = array(
			'path'          => $candidate,
			'metadata_refs' => array(
				array(
					'attachment_id' => $this->attachment_id,
					'size'          => 'thumbnail',
					'filename'      => wp_basename( $candidate ),
				),
			),
		);
		$job             = yotm_job_create(
			'prune',
			array( 'base' => $this->uploads_base ),
			array(
				'status'       => 'scanning',
				'phase'        => 'metadata',
				'counter_mode' => 'item_v3',
			)
		);
		$this->job_ids[] = $job['id'];
		$item_key        = hash( 'sha256', $candidate );
		$this->assertTrue( yotm_job_add_item( $job['id'], $item_key, $payload ) );
		$this->assertTrue(
			yotm_job_update_where(
				$job['id'],
				array(
					'status' => 'deleting',
					'phase'  => 'delete',
					'total'  => 1,
				),
				array(
					'status'        => array( 'scanning' ),
					'phase'         => array( 'metadata' ),
					'require_match' => true,
				)
			)
		);
		$job    = yotm_job_get_by_id( $job['id'] );
		$worker = yotm_job_acquire_worker( $job['id'], array( 'deleting' ), array( 'delete' ) );
		$item   = yotm_job_claim_items( $worker, 1 )[0];
		$node   = yotm_prune_journal_path_state( $candidate );
		$this->assertIsArray( $node );
		$this->assertSame( 'regular', $node['state'] );
		$payload_with_journal = $item['payload'];

		$payload_with_journal['prune_operation_journal_v1'] = array(
			'version'          => 1,
			'path'             => yotm_normalize_filesystem_path( $candidate ),
			'file_hash'        => hash( 'sha256', $data ),
			'node_fingerprint' => $node['fingerprint'],
			'bytes'            => strlen( $data ),
			'outcome'          => '',
		);
		$this->assertTrue( yotm_job_update_claimed_item_payload( $item, $payload_with_journal ) );
		$item['payload'] = $payload_with_journal;

		return array(
			'job'      => $job,
			'item_key' => $item_key,
			'worker'   => $worker,
			'item'     => $item,
			'payload'  => $payload,
			'path'     => $candidate,
		);
	}
}
