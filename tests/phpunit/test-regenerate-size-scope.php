<?php
/**
 * Job-local size scope across inspection, repair, Force and journal recovery.
 */
class YOTM_Regenerate_Size_Scope_Test extends WP_UnitTestCase {
	private $id;
	private $dir;
	private $metadata;

	public function setUp(): void {
		parent::setUp();
		unset( $GLOBALS['yotm_job_storage_readiness'], $GLOBALS['yotm_media_source_last_error'] );
		$this->assertTrue( yotm_run_job_table_migration() );
		yotm_media_source_shutdown_cleanup();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		delete_option( 'yotm_disabled_sizes' );
		delete_option( YOTM_MEDIA_SOURCE_DIRTY_OPTION );
		$this->assertTrue( yotm_media_source_clear_index() );
		$state = yotm_media_reference_index_state();
		$this->assertTrue( yotm_media_reference_baseline_complete( $state['baseline_token'] ) );
		$this->dir = trailingslashit( wp_get_upload_dir()['basedir'] ) . 'size-scope-' . wp_generate_uuid4();
		wp_mkdir_p( $this->dir );
		$this->dir = realpath( $this->dir );
		add_image_size( 'scope_a', 120, 120, true );
		add_image_size( 'scope_b', 160, 100, true );
		add_filter( 'intermediate_image_sizes_advanced', array( $this, 'sizes' ), PHP_INT_MAX );
		copy( DIR_TESTDATA . '/images/2004-07-22-DSC_0007.jpg', $this->dir . '/source.jpg' );
		$this->id = wp_insert_attachment( array( 'post_mime_type' => 'image/jpeg', 'post_title' => 'Scoped fixture' ), $this->dir . '/source.jpg' );
		$this->metadata = wp_generate_attachment_metadata( $this->id, $this->dir . '/source.jpg' );
		$this->metadata['custom'] = array( 'preserve' => true );
		wp_update_attachment_metadata( $this->id, $this->metadata );
		$this->assertTrue( yotm_media_source_sync_attachment( $this->id, null, true ) );
	}

	public function tearDown(): void {
		yotm_job_release_all_workers();
		yotm_media_source_shutdown_cleanup();
		remove_filter( 'intermediate_image_sizes_advanced', array( $this, 'sizes' ), PHP_INT_MAX );
		remove_filter( 'wp_update_attachment_metadata', array( $this, 'tamper' ), 20 );
		remove_image_size( 'scope_a' );
		remove_image_size( 'scope_b' );
		wp_delete_attachment( $this->id, true );
		foreach ( glob( $this->dir . '/.yotm-regenerate-*' ) as $stage ) {
			yotm_regenerate_remove_stage( $stage, $this->dir );
		}
		foreach ( glob( $this->dir . '/*' ) as $file ) {
			if ( is_file( $file ) ) { unlink( $file ); }
		}
		@rmdir( $this->dir );
		delete_option( 'yotm_disabled_sizes' );
		delete_option( YOTM_MEDIA_REFERENCE_STATE_OPTION );
		delete_option( YOTM_MEDIA_SOURCE_DIRTY_OPTION );
		parent::tearDown();
	}

	public function sizes( $sizes ) {
		return array_intersect_key( $sizes, array_flip( array( 'scope_a', 'scope_b' ) ) );
	}

	private function prepare( $selected = array( 'scope_a' ), $force = false ) {
		return yotm_regenerate_prepare_application( 'ids', '', (string) $this->id, $force, ! $force, $force ? '' : 'check', '', $selected );
	}

	private function finish( $token ) {
		for ( $i = 0; $i < 40; ++$i ) {
			$result = yotm_regenerate_batch_application( $token, 1 );
			yotm_job_release_all_workers();
			$this->assertTrue( $result['success'], wp_json_encode( $result ) );
			if ( $result['data']['done'] ) { return $result['data']; }
		}
		$this->fail( 'Scoped job did not complete.' );
	}

	public function test_scoped_check_and_repair_preserve_unselected_missing_size_and_global_policy() {
		foreach ( $this->metadata['sizes'] as $size ) { unlink( $this->dir . '/' . $size['file'] ); }
		$prepared = $this->prepare();
		$this->assertTrue( $prepared['success'] );
		$check = $this->finish( $prepared['data']['token'] );
		$report = yotm_thumbnail_health_results_application( $check['token'] )['data'];
		$this->assertSame( array( 'scope_a' ), array_keys( $report['items'][0]['sizes'] ) );
		$this->assertSame( 1, $report['items'][0]['missing_count'] );
		$repair = yotm_thumbnail_health_prepare_repair( $check['token'] );
		$this->assertTrue( $repair['success'] );
		$result = $this->finish( $repair['data']['token'] );
		$this->assertSame( 1, $result['regenerated'] );
		$this->assertFileExists( $this->dir . '/' . $this->metadata['sizes']['scope_a']['file'] );
		$this->assertFileDoesNotExist( $this->dir . '/' . $this->metadata['sizes']['scope_b']['file'] );
		$this->assertSame( $this->metadata['sizes']['scope_b'], get_post_meta( $this->id, '_wp_attachment_metadata', true )['sizes']['scope_b'] );
		$this->assertFalse( get_option( 'yotm_disabled_sizes' ) );
	}

	public function test_empty_unknown_disabled_and_scalar_selection_are_rejected_without_job() {
		foreach ( array( array(), array( 'unknown_size' ), 'scope_a', array( array( 'scope_a' ) ) ) as $bad ) {
			$this->assertFalse( $this->prepare( $bad )['success'] );
		}
		yotm_update_disabled_sizes_option( array( 'scope_a' ) );
		$this->assertFalse( $this->prepare()['success'] );
		$this->assertCount( 0, yotm_job_get_recent_for_current_user() );
	}

	public function test_default_scope_still_checks_all_enabled_sizes() {
		$prepared = $this->prepare( null );
		$check = $this->finish( $prepared['data']['token'] );
		$report = yotm_thumbnail_health_results_application( $check['token'] )['data']['items'][0];
		$this->assertSame( 2, $report['checked_count'] );
		$this->assertSame( 'healthy', $report['sizes']['scope_a']['status'] );
		$this->assertSame( 'healthy', $report['sizes']['scope_b']['status'] );
	}

	public function test_scoped_force_preserves_other_metadata_and_files_and_cleans_only_replaced_size() {
		$old_path = $this->dir . '/' . $this->metadata['sizes']['scope_a']['file'];
		$keep_path = $this->dir . '/' . $this->metadata['sizes']['scope_b']['file'];
		$keep_hash = hash_file( 'sha256', $keep_path );
		$source_hash = hash_file( 'sha256', $this->dir . '/source.jpg' );
		add_image_size( 'scope_a', 130, 130, true );
		$prepared = $this->prepare( array( 'scope_a' ), true );
		$result = $this->finish( $prepared['data']['token'] );
		$this->assertSame( 1, $result['regenerated'] );
		$final = get_post_meta( $this->id, '_wp_attachment_metadata', true );
		$expected = $this->metadata;
		$expected['sizes']['scope_a'] = $final['sizes']['scope_a'];
		$this->assertSame( $expected, $final );
		$this->assertFileDoesNotExist( $old_path );
		$this->assertSame( $keep_hash, hash_file( 'sha256', $keep_path ) );
		$this->assertSame( $source_hash, hash_file( 'sha256', $this->dir . '/source.jpg' ) );
	}

	public function test_disabled_or_unregistered_after_prepare_preserves_that_size_without_expanding() {
		$prepared = $this->prepare( array( 'scope_a' ), true );
		$hash = hash_file( 'sha256', $this->dir . '/' . $this->metadata['sizes']['scope_a']['file'] );
		remove_image_size( 'scope_a' );
		$result = $this->finish( $prepared['data']['token'] );
		$this->assertSame( 1, $result['skipped'] );
		$this->assertSame( $this->metadata, get_post_meta( $this->id, '_wp_attachment_metadata', true ) );
		$this->assertSame( $hash, hash_file( 'sha256', $this->dir . '/' . $this->metadata['sizes']['scope_a']['file'] ) );
	}

	public function test_partial_scope_after_disable_preserves_restricted_size_and_only_rebuilds_remaining_target() {
		$prepared = $this->prepare( array( 'scope_a', 'scope_b' ), true );
		$keep_path = $this->dir . '/' . $this->metadata['sizes']['scope_b']['file'];
		$keep_hash = hash_file( 'sha256', $keep_path );
		yotm_update_disabled_sizes_option( array( 'scope_b' ) );
		add_image_size( 'scope_a', 130, 130, true );
		$result = $this->finish( $prepared['data']['token'] );
		$this->assertSame( 1, $result['regenerated'] );
		$this->assertSame( $keep_hash, hash_file( 'sha256', $keep_path ) );
		$this->assertSame( $this->metadata['sizes']['scope_b'], get_post_meta( $this->id, '_wp_attachment_metadata', true )['sizes']['scope_b'] );
		$this->assertSame( array( 'scope_b' ), yotm_get_disabled_sizes_option() );
	}

	public function test_recovery_rejects_journal_changes_outside_saved_scope() {
		$final = $this->metadata;
		$final['custom'] = array( 'tampered' => true );
		$result = yotm_regenerate_validate_scope( $this->metadata, $final, array( 'scope_a' ), array() );
		$this->assertWPError( $result );
		$this->assertSame( 'yotm_regenerate_scope_metadata', $result->get_error_code() );
	}

	public function tamper( $metadata ) {
		$metadata['sizes']['scope_b']['width'] = 999;
		return $metadata;
	}

	public function test_filter_cannot_mutate_unselected_metadata() {
		$hash = hash_file( 'sha256', $this->dir . '/' . $this->metadata['sizes']['scope_b']['file'] );
		add_filter( 'wp_update_attachment_metadata', array( $this, 'tamper' ), 20 );
		$prepared = $this->prepare( array( 'scope_a' ), true );
		$result = $this->finish( $prepared['data']['token'] );
		$this->assertSame( 1, $result['failed'] );
		$this->assertSame( $this->metadata, get_post_meta( $this->id, '_wp_attachment_metadata', true ) );
		$this->assertSame( $hash, hash_file( 'sha256', $this->dir . '/' . $this->metadata['sizes']['scope_b']['file'] ) );
	}

	public function test_selected_destination_aliasing_unselected_size_fails_before_replacement() {
		$this->metadata['sizes']['scope_b'] = $this->metadata['sizes']['scope_a'];
		wp_update_attachment_metadata( $this->id, $this->metadata );
		$this->assertTrue( yotm_media_source_sync_attachment( $this->id, null, true ) );
		$path = $this->dir . '/' . $this->metadata['sizes']['scope_a']['file'];
		$hash = hash_file( 'sha256', $path );
		$prepared = $this->prepare( array( 'scope_a' ), true );
		$result = $this->finish( $prepared['data']['token'] );
		$this->assertSame( 1, $result['failed'] );
		$this->assertSame( $this->metadata, get_post_meta( $this->id, '_wp_attachment_metadata', true ) );
		$this->assertSame( $hash, hash_file( 'sha256', $path ) );
	}

	public function test_committed_scoped_recovery_cleans_target_obsolete_file_and_preserves_unselected() {
		$old_path = $this->dir . '/' . $this->metadata['sizes']['scope_a']['file'];
		$old_bytes = file_get_contents( $old_path );
		$keep_path = $this->dir . '/' . $this->metadata['sizes']['scope_b']['file'];
		$keep_hash = hash_file( 'sha256', $keep_path );
		add_image_size( 'scope_a', 130, 130, true );
		$prepared = $this->prepare( array( 'scope_a' ), true );
		$job = yotm_job_get( $prepared['data']['token'] );
		// Source index is already complete for this fixture; exercise the transaction directly.
		$this->assertTrue( yotm_job_update( $job['id'], array( 'phase' => 'regenerate' ) ) );
		$worker = yotm_job_acquire_worker( $job['id'], array( 'running' ), array( 'regenerate' ) );
		$item = yotm_job_claim_items( $worker, 1 )[0];
		$result = yotm_regenerate_force_attachment( $this->id, $item, $worker, false, array( 'scope_a' ) );
		$this->assertSame( 'regenerated', $result['status'], $result['message'] );
		$item = yotm_job_get_item_by_key( $job['id'], $item['item_key'] );
		$journal = $item['payload']['regeneration_journal'];
		$journal['phase'] = 'metadata_committed';
		file_put_contents( $old_path, $old_bytes );
		$this->assertTrue( yotm_regenerate_persist_journal( $item, $journal ) );
		$recovered = yotm_regenerate_recover_journal( $item );
		$this->assertSame( 'regenerated', $recovered['status'], $recovered['message'] );
		$this->assertFileDoesNotExist( $old_path );
		$this->assertSame( $keep_hash, hash_file( 'sha256', $keep_path ) );
		$this->assertSame( $this->metadata['sizes']['scope_b'], get_post_meta( $this->id, '_wp_attachment_metadata', true )['sizes']['scope_b'] );
	}
}
