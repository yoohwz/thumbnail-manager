<?php
/**
 * Persistent inspection/repair selection, outcomes and recovery.
 */
class YOTM_Thumbnail_Health_Application_Test extends WP_UnitTestCase {
	private $id;
	private $dir;
	private $metadata;
	private $admin;

	public function setUp(): void {
		parent::setUp();
		unset( $GLOBALS['yotm_job_storage_readiness'], $GLOBALS['yotm_media_source_last_error'] );
		$this->assertTrue( yotm_run_job_table_migration() );
		yotm_media_source_shutdown_cleanup();
		$this->admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin );
		delete_option( 'yotm_disabled_sizes' );
		delete_option( YOTM_MEDIA_SOURCE_DIRTY_OPTION );
		$this->assertTrue( yotm_media_source_clear_index() );
		$state = yotm_media_reference_index_state();
		$this->assertTrue( yotm_media_reference_baseline_complete( $state['baseline_token'] ) );
		$this->dir = trailingslashit( wp_get_upload_dir()['basedir'] ) . 'health-app-' . wp_generate_uuid4();
		wp_mkdir_p( $this->dir );
		$this->dir = realpath( $this->dir );
		add_image_size( 'health_app', 120, 120, true );
		add_image_size( 'health_keep', 160, 100, true );
		add_filter( 'intermediate_image_sizes_advanced', array( $this, 'sizes' ), PHP_INT_MAX );
		copy( DIR_TESTDATA . '/images/2004-07-22-DSC_0007.jpg', $this->dir . '/source.jpg' );
		$this->id                 = wp_insert_attachment(
			array(
				'post_mime_type' => 'image/jpeg',
				'post_title'     => 'Health application fixture',
			),
			$this->dir . '/source.jpg'
		);
		$this->metadata           = wp_generate_attachment_metadata( $this->id, $this->dir . '/source.jpg' );
		$this->metadata['custom'] = array( 'preserve' => true );
		wp_update_attachment_metadata( $this->id, $this->metadata );
		$this->assertTrue( yotm_media_source_sync_attachment( $this->id, null, true ) );
	}

	public function tearDown(): void {
		yotm_job_release_all_workers();
		yotm_media_source_shutdown_cleanup();
		remove_filter( 'intermediate_image_sizes_advanced', array( $this, 'sizes' ), PHP_INT_MAX );
		remove_image_size( 'health_app' );
		remove_image_size( 'health_keep' );
		wp_delete_attachment( $this->id, true );
		foreach ( glob( $this->dir . '/.yotm-regenerate-*' ) as $stage ) {
			yotm_regenerate_remove_stage( $stage, $this->dir );
		}
		foreach ( glob( $this->dir . '/*' ) as $file ) {
			if ( is_file( $file ) ) {
				unlink( $file );
			}
		}
		@rmdir( $this->dir );
		delete_option( 'yotm_disabled_sizes' );
		delete_option( YOTM_MEDIA_REFERENCE_STATE_OPTION );
		delete_option( YOTM_MEDIA_SOURCE_DIRTY_OPTION );
		parent::tearDown();
	}

	public function sizes( $sizes ) {
		return array_intersect_key( $sizes, array_flip( array( 'health_app', 'health_keep' ) ) );
	}

	private function finish( $token ) {
		for ( $i = 0; $i < 30; ++$i ) {
			$result = yotm_regenerate_batch_application( $token, 1 );
			yotm_job_release_all_workers();
			$this->assertTrue( $result['success'], wp_json_encode( $result ) );
			if ( $result['data']['done'] ) {
				return $result['data'];
			}
		}
		$this->fail( 'Job did not complete within bounded batches.' );
	}

	private function check() {
		$result = yotm_regenerate_prepare_application( 'ids', '', (string) $this->id, false, true, 'check' );
		$this->assertTrue( $result['success'], wp_json_encode( $result ) );
		return $this->finish( $result['data']['token'] );
	}

	public function test_check_then_explicit_repair_preserves_healthy_files_and_metadata() {
		$path = $this->dir . '/' . $this->metadata['sizes']['health_app']['file'];
		unlink( $path );
		$source_hash  = hash_file( 'sha256', $this->dir . '/source.jpg' );
		$healthy_path = $this->dir . '/' . $this->metadata['sizes']['health_keep']['file'];
		$healthy_hash = hash_file( 'sha256', $healthy_path );
		$check        = $this->check();
		$this->assertFileDoesNotExist( $path );
		$this->assertSame( $this->metadata, get_post_meta( $this->id, '_wp_attachment_metadata', true ) );
		$report = yotm_thumbnail_health_results_application( $check['token'] );
		$this->assertSame( 'missing', $report['data']['items'][0]['status'] );
		$this->assertStringNotContainsString( $this->dir, wp_json_encode( $report ) );
		$prepare = yotm_regenerate_prepare_application( 'ids', '', '999999', true, false, 'repair', $check['token'] );
		$this->assertTrue( $prepare['success'], wp_json_encode( $prepare ) );
		$repair = $this->finish( $prepare['data']['token'] );
		$this->assertSame( 1, $repair['regenerated'] );
		$this->assertSame( 1, $repair['total'] );
		$this->assertFileExists( $path );
		$this->assertSame( $source_hash, hash_file( 'sha256', $this->dir . '/source.jpg' ) );
		$this->assertSame( $healthy_hash, hash_file( 'sha256', $healthy_path ) );
		$metadata                        = get_post_meta( $this->id, '_wp_attachment_metadata', true );
		$expected                        = $this->metadata;
		$expected['sizes']['health_app'] = $metadata['sizes']['health_app'];
		$this->assertSame( $expected, $metadata );
		$results = yotm_thumbnail_health_results_application( $repair['token'] );
		$this->assertSame( array( 'health_app' ), $results['data']['items'][0]['restored_sizes'] );
		$this->assertSame( 1, $results['data']['items'][0]['restored_count'] );
		$this->assertStringNotContainsString( 'regeneration_journal', wp_json_encode( $results ) );
		$replay = yotm_regenerate_batch_application( $repair['token'], 1 );
		$this->assertTrue( $replay['data']['done'] );
		$this->assertSame( 1, $replay['data']['regenerated'] );
	}

	public function test_repair_rechecks_current_enabled_policy_and_skips_newly_disabled_size() {
		$path = $this->dir . '/' . $this->metadata['sizes']['health_app']['file'];
		unlink( $path );
		$check = $this->check();
		yotm_update_disabled_sizes_option( array( 'health_app' ) );
		$prepare = yotm_thumbnail_health_prepare_repair( $check['token'] );
		$this->assertTrue( $prepare['success'] );
		$result = $this->finish( $prepare['data']['token'] );
		$this->assertSame( 1, $result['skipped'] );
		$this->assertFileDoesNotExist( $path );
		$this->assertSame( $this->metadata, get_post_meta( $this->id, '_wp_attachment_metadata', true ) );
	}

	public function test_existing_unmapped_destination_is_preserved_and_reported_failed() {
		$path     = $this->dir . '/' . $this->metadata['sizes']['health_app']['file'];
		$hash     = hash_file( 'sha256', $path );
		$metadata = $this->metadata;
		unset( $metadata['sizes']['health_app'] );
		wp_update_attachment_metadata( $this->id, $metadata );
		$check   = $this->check();
		$prepare = yotm_thumbnail_health_prepare_repair( $check['token'] );
		$result  = $this->finish( $prepare['data']['token'] );
		$this->assertSame( 1, $result['failed'] );
		$this->assertSame( $hash, hash_file( 'sha256', $path ) );
		$this->assertSame( $metadata, get_post_meta( $this->id, '_wp_attachment_metadata', true ) );
	}

	public function test_report_ownership_and_completed_check_are_required() {
		$this->assertFalse( yotm_thumbnail_health_prepare_repair( '' )['success'] );
		$pending = yotm_regenerate_prepare_application( 'ids', '', (string) $this->id, false, true, 'check' );
		$this->assertFalse( yotm_thumbnail_health_prepare_repair( $pending['data']['token'] )['success'] );
		$this->finish( $pending['data']['token'] );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->assertFalse( yotm_thumbnail_health_prepare_repair( $pending['data']['token'] )['success'] );
		$this->assertFalse( yotm_thumbnail_health_results_application( $pending['data']['token'] )['success'] );
		wp_set_current_user( $this->admin );
	}

	public function test_cancelled_inspection_cannot_replay_or_authorize_repair() {
		$pending = yotm_regenerate_prepare_application( 'ids', '', (string) $this->id, false, true, 'check' );
		$job     = yotm_job_get( $pending['data']['token'] );
		$this->assertIsArray( yotm_job_cancel( $job ) );
		$this->assertFalse( yotm_regenerate_batch_application( $job['token'], 1 )['success'] );
		$this->assertFalse( yotm_thumbnail_health_prepare_repair( $job['token'] )['success'] );
	}

	public function test_oversized_explicit_selection_is_rejected_without_job() {
		$result = yotm_regenerate_prepare_application( 'ids', '', implode( ',', range( 1, 501 ) ), false, true, 'check' );
		$this->assertFalse( $result['success'] );
		$this->assertSame( 400, $result['status'] );
	}

	/** @dataProvider recovery_states */
	public function test_equal_metadata_recovery_requires_actual_generated_files( $phase, $promoted, $intent ) {
		$path  = $this->dir . '/' . $this->metadata['sizes']['health_app']['file'];
		$hash  = hash_file( 'sha256', $path );
		$stage = $this->dir . '/.yotm-regenerate-' . wp_generate_uuid4();
		wp_mkdir_p( $stage );
		$staged = $stage . '/' . basename( $path );
		link( $path, $staged );
		if ( ! $promoted ) {
			unlink( $path );
		}
		$journal = array(
			'version'           => YOTM_REGENERATE_JOURNAL_VERSION,
			'mode'              => 'repair',
			'phase'             => $phase,
			'attachment_id'     => $this->id,
			'old_metadata'      => $this->metadata,
			'final_metadata'    => $this->metadata,
			'old_metadata_hash' => yotm_regenerate_metadata_hash( $this->metadata ),
			'new_metadata_hash' => yotm_regenerate_metadata_hash( $this->metadata ),
			'full'              => $this->dir . '/source.jpg',
			'stage'             => $stage,
			'promotion_slug'    => $intent ? 'health_app' : '',
			'destinations'      => array(
				'health_app' => array(
					'slug'          => 'health_app',
					'source'        => $staged,
					'destination'   => $path,
					'mode'          => 'replaceable_old_generated',
					'owners'        => array( $this->id . ':health_app:' . basename( $path ) ),
					'old_hash'      => '',
					'old_absent'    => true,
					'backup'        => '',
					'promoted_hash' => $hash,
					'promoted'      => $promoted && ! $intent,
				),
			),
		);
		$job     = yotm_job_create(
			'regenerate',
			array(
				'operation'      => 'repair',
				'discovery_done' => 1,
			),
			array(
				'status'       => 'running',
				'phase'        => 'regenerate',
				'counter_mode' => 'item_v3',
				'total'        => 1,
			)
		);
		$this->assertIsArray( $job );
		yotm_job_add_item(
			$job['id'],
			'recover',
			array(
				'attachment_id'        => $this->id,
				'regeneration_journal' => $journal,
			)
		);
		$worker = yotm_job_acquire_worker( $job['id'], array( 'running' ), array( 'regenerate' ) );
		$item   = yotm_job_claim_items( $worker, 1 )[0];
		$result = yotm_regenerate_force_attachment( $this->id, $item, $worker, true );
		$this->assertSame( $promoted ? 'regenerated' : 'retry', $result['status'], $result['message'] );
		if ( $promoted ) {
			$this->assertFileExists( $path );
			$this->assertSame( $hash, hash_file( 'sha256', $path ) );
		} else {
			$this->assertFileDoesNotExist( $path );
			$current = yotm_job_get_item_by_key( $job['id'], 'recover' );
			$this->assertArrayNotHasKey( 'regeneration_journal', $current['payload'] );
		}
		$this->assertSame( $this->metadata, get_post_meta( $this->id, '_wp_attachment_metadata', true ) );
	}

	public function test_atomic_publication_preserves_an_existing_identical_file() {
		$source = $this->dir . '/source.jpg';
		$target = $this->dir . '/already-present.jpg';
		copy( $source, $target );
		$node  = yotm_thumbnail_health_file_node( $target );
		$entry = array(
			'source'        => $source,
			'destination'   => $target,
			'promoted_hash' => hash_file( 'sha256', $source ),
		);
		$this->assertFalse( yotm_thumbnail_health_promote_destination( $entry ) );
		$this->assertFalse( $entry['promoted'] );
		$this->assertSame( $node, yotm_thumbnail_health_file_node( $target ) );
		$this->assertFalse( yotm_thumbnail_health_owns_output( $entry ) );
	}

	/** @dataProvider interrupted_repair_modes */
	public function test_partial_publication_recovers_without_creating_remaining_outputs( $cancel ) {
		foreach ( $this->metadata['sizes'] as $size ) {
			unlink( $this->dir . '/' . $size['file'] );
		}
		$health = yotm_thumbnail_health_inspect( $this->id );
		$staged = yotm_regenerate_stage_outputs( $health['snapshot'] );
		$this->assertIsArray( $staged );
		$final = yotm_regenerate_finalize_metadata( $health['snapshot'], $staged );
		$this->assertIsArray( $final );
		$owners       = yotm_regenerate_old_owners( $health['snapshot'] );
		$destinations = array();
		foreach ( $final['map'] as $slug => $entry ) {
			$prestate = yotm_regenerate_destination_prestate( $entry, $health['snapshot'], $owners, $staged['stage'] );
			$this->assertIsArray( $prestate );
			$destinations[ $slug ] = array_merge( $entry, $prestate, array( 'promoted_hash' => $entry['hash'] ) );
		}
		$journal = array(
			'version'           => YOTM_REGENERATE_JOURNAL_VERSION,
			'mode'              => 'repair',
			'phase'             => 'prepared',
			'attachment_id'     => $this->id,
			'old_metadata'      => $this->metadata,
			'final_metadata'    => $final['metadata'],
			'old_metadata_hash' => yotm_regenerate_metadata_hash( $this->metadata ),
			'new_metadata_hash' => yotm_regenerate_metadata_hash( $final['metadata'] ),
			'full'              => $health['snapshot']['full'],
			'stage'             => $staged['stage'],
			'destinations'      => $destinations,
			'promotion_slug'    => 'health_app',
		);
		$job     = yotm_job_create(
			'regenerate',
			array(
				'operation'      => 'repair',
				'discovery_done' => 1,
			),
			array(
				'status'       => 'running',
				'phase'        => 'regenerate',
				'counter_mode' => 'item_v3',
				'total'        => 1,
			)
		);
		$this->assertIsArray( $job );
		yotm_job_add_item( $job['id'], 'partial', array( 'attachment_id' => $this->id ) );
		$worker = yotm_job_acquire_worker( $job['id'], array( 'running' ), array( 'regenerate' ) );
		$item   = yotm_job_claim_items( $worker, 1 )[0];
		$this->assertTrue( yotm_regenerate_persist_journal( $item, $journal ) );
		$this->assertTrue( yotm_thumbnail_health_promote_destination( $journal['destinations']['health_app'] ) );
		// Simulate interruption before recording observed publication in the journal.
		if ( $cancel ) {
			$this->assertTrue( yotm_job_release_item_claim( $item ) );
			yotm_job_release_all_workers();
			$stopped = yotm_job_cancel( yotm_job_get_by_id( $job['id'] ) );
			$this->assertWPError( $stopped );
			$this->assertSame( 'yotm_job_cancel_busy', $stopped->get_error_code() );
			$this->assertTrue( ! empty( yotm_job_get_by_id( $job['id'] )['payload']['recovery_only'] ) );
			for ( $i = 0; $i < 5; ++$i ) {
				yotm_regenerate_batch_application( $job['token'], 1 );
				yotm_job_release_all_workers();
				if ( 'cancelled' === yotm_job_get_by_id( $job['id'] )['status'] ) {
					break; }
			}
			$this->assertSame( 'cancelled', yotm_job_get_by_id( $job['id'] )['status'] );
		} else {
			$result = yotm_regenerate_force_attachment( $this->id, $item, $worker, true );
			$this->assertSame( 'retry', $result['status'], $result['message'] );
		}
		foreach ( $destinations as $entry ) {
			$this->assertFileDoesNotExist( $entry['destination'] );
		}
		$this->assertSame( $this->metadata, get_post_meta( $this->id, '_wp_attachment_metadata', true ) );
	}

	public function interrupted_repair_modes() {
		return array( array( false ), array( true ) );
	}

	public function recovery_states() {
		return array(
			array( 'prepared', false, false ),
			array( 'prepared', false, true ),
			array( 'prepared', true, true ),
			array( 'files_promoted', true, false ),
			array( 'metadata_committed', true, false ),
			array( 'cleanup_complete', true, false ),
		);
	}
}
