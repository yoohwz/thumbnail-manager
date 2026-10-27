<?php
/**
 * Saved-result isolation and fresh selected attempts.
 */
class YOTM_Job_Results_Test extends WP_UnitTestCase {
	private $admin;
	private $jobs = array();

	public function setUp(): void {
		parent::setUp();
		unset( $GLOBALS['yotm_job_storage_readiness'] );
		$this->assertTrue( yotm_run_job_table_migration() );
		$this->admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin );
	}

	public function tearDown(): void {
		yotm_job_release_all_workers();
		foreach ( $this->jobs as $id ) {
			yotm_job_delete( $id );
		}
		parent::tearDown();
	}

	private function source( $type = 'regenerate', $payload = array() ) {
		$job = yotm_job_create(
			$type,
			$payload,
			array(
				'status' => 'completed',
				'phase'  => 'completed',
				'ttl'    => DAY_IN_SECONDS,
				'total'  => 3,
			)
		);
		$this->assertIsArray( $job );
		$this->jobs[] = $job['id'];
		return $job;
	}

	private function item( $job, $name, $status, $extra = array() ) {
		$key    = hash( 'sha256', $name );
		$before = yotm_job_get_by_id( $job['id'] );
		yotm_job_update(
			$job['id'],
			array(
				'status' => 'regenerate' === $job['type'] ? 'running' : 'scanning',
				'phase'  => 'regenerate' === $job['type'] ? 'regenerate' : 'metadata',
			)
		);
		$this->assertTrue(
			yotm_job_add_item(
				$job['id'],
				$key,
				array_merge(
					array(
						'attachment_id' => 123,
						'path'          => '/private/internal/source.jpg',
					),
					$extra
				),
				$status
			)
		);
		yotm_job_update(
			$job['id'],
			array(
				'status' => $before['status'],
				'phase'  => $before['phase'],
			)
		);
		return $key;
	}

	public function test_results_read_expired_state_without_mutation_and_hide_private_authority() {
		$job = $this->source(
			'regenerate',
			array(
				'operation'      => 'check',
				'selected_sizes' => array( 'thumbnail' ),
				'sample'         => array( '/private/internal' ),
				'orphan_summary' => array(
					'unmapped_sample'         => array( '/private/internal/unmapped.jpg' ),
					'skipped_original_sample' => array( '/private/internal/original.jpg' ),
				),
			)
		);
		$this->item(
			$job,
			'done',
			'done',
			array(
				'regeneration_journal' => array( 'private' => 'secret' ),
				'health_report'        => array(
					'status'   => 'healthy',
					'message'  => 'OK',
					'snapshot' => 'secret',
				),
			)
		);
		yotm_job_update(
			$job['id'],
			array(
				'status'     => 'running',
				'expires_at' => gmdate( 'Y-m-d H:i:s', time() - 60 ),
			)
		);
		$before = yotm_job_get_by_id( $job['id'] );
		$result = yotm_job_results_application( $job['token'] );
		$this->assertTrue( $result['success'] );
		$this->assertSame( $before, yotm_job_get_by_id( $job['id'] ) );
		$this->assertFalse( $result['data']['complete'] );
		$this->assertFalse( $result['data']['can_retry'] );
		$this->assertStringNotContainsString( 'secret', wp_json_encode( $result ) );
		$this->assertStringNotContainsString( '/private/', wp_json_encode( $result ) );
		$this->assertSame( array( 'thumbnail' ), $result['data']['job']['context']['selected_sizes'] );
	}

	public function test_filters_paginate_only_public_outcomes_and_retry_does_not_copy_source_authority() {
		$job = $this->source(
			'regenerate',
			array(
				'operation'          => 'check',
				'selected_sizes'     => array( 'thumbnail' ),
				'selector'           => 'attached_meta_v2',
				'selection_meta_max' => 77,
				'selection_subpaths' => array( '2026' ),
			)
		);
		$this->item( $job, 'done', 'done' );
		$key = $this->item(
			$job,
			'failed',
			'failed',
			array(
				'selection_meta_id' => 42,
				'health_report'     => array( 'message' => 'old error' ),
			)
		);
		$this->item( $job, 'skipped', 'skipped' );
		$this->item( $job, 'evidence', 'recommend_observed' );
		$result = yotm_job_results_application( $job['token'], 1, 'unsuccessful' );
		$this->assertSame( 2, $result['data']['total'] );
		$this->assertSame( 3, yotm_job_results_application( $job['token'] )['data']['total'] );
		$before = yotm_job_get_by_id( $job['id'] );
		$retry  = yotm_job_retry_application( $job['token'], array( $key ) );
		$this->assertTrue( $retry['success'], wp_json_encode( $retry ) );
		$new          = yotm_job_get( $retry['data']['token'] );
		$this->jobs[] = $new['id'];
		$this->assertSame( $before, yotm_job_get_by_id( $job['id'] ) );
		$this->assertNotSame( $job['token'], $new['token'] );
		$this->assertSame( 1, $new['total'] );
		$this->assertSame( array( 'thumbnail' ), $new['payload']['selected_sizes'] );
		$this->assertSame( 'attached_meta_v2', $new['payload']['source_selector'] );
		$this->assertSame( 77, $new['payload']['selection_meta_max'] );
		$row = yotm_job_get_item_by_key( $new['id'], $key );
		$this->assertSame(
			array(
				'attachment_id'     => 123,
				'selection_meta_id' => 42,
			),
			$row['payload']
		);
		$this->assertSame( '', $new['manifest_hash'] );
		$this->assertSame( 'queued', $row['status'] );
		$this->assertFalse( yotm_job_retry_application( $job['token'], array( $key ) )['success'], 'Concurrent retry must use existing site lock.' );
	}

	public function test_retry_rejects_success_cross_job_unknown_active_expired_and_recovery_rows() {
		$job  = $this->source();
		$done = $this->item( $job, 'done', 'done' );
		$bad  = $this->item( $job, 'bad', 'failed' );
		foreach ( array( array(), array( $done ), array( hash( 'sha256', 'foreign' ) ), array_fill( 0, 101, $bad ) ) as $keys ) {
			$this->assertFalse( yotm_job_retry_application( $job['token'], $keys )['success'] );
		}
		yotm_job_update( $job['id'], array( 'status' => 'running' ) );
		$this->assertFalse( yotm_job_retry_application( $job['token'], array( $bad ) )['success'] );
		yotm_job_update(
			$job['id'],
			array(
				'status'     => 'completed',
				'expires_at' => gmdate( 'Y-m-d H:i:s', time() - 60 ),
			)
		);
		$this->assertFalse( yotm_job_retry_application( $job['token'], array( $bad ) )['success'] );
		yotm_job_update( $job['id'], array( 'expires_at' => gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS ) ) );
		$this->item( $job, 'recovery', 'failed', array( 'regeneration_journal' => array( 'phase' => 'files_promoted' ) ) );
		$this->assertFalse( yotm_job_retry_application( $job['token'], array( $bad ) )['success'] );
		$this->assertFalse( yotm_job_results_application( $job['token'] )['data']['can_retry'] );
	}

	public function test_all_result_types_are_owned_and_recommendation_evidence_is_not_an_item_queue() {
		foreach ( array( 'regenerate', 'recommendation', 'prune' ) as $type ) {
			$job = $this->source( $type );
			$this->assertTrue( yotm_job_results_application( $job['token'] )['success'] );
			wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
			$this->assertFalse( yotm_job_results_application( $job['token'] )['success'] );
			$this->assertFalse( yotm_job_retry_application( $job['token'], array( hash( 'sha256', 'bad' ) ) )['success'] );
			wp_set_current_user( $this->admin );
		}
	}

	public function test_prune_retry_creates_new_preview_and_filters_before_manifest_hash() {
		$job   = $this->source(
			'prune',
			array(
				'keep'                    => array( 'thumbnail' ),
				'remove'                  => array( 'medium' ),
				'scan_subpaths'           => array(),
				'discover_current_legacy' => 0,
			)
		);
		$key   = $this->item( $job, 'selected-path', 'failed' );
		$retry = yotm_job_retry_application( $job['token'], array( $key ) );
		$this->assertTrue( $retry['success'], wp_json_encode( $retry ) );
		$new          = yotm_job_get( $retry['data']['token'] );
		$this->jobs[] = $new['id'];
		$this->assertSame( 'scanning', $new['status'] );
		$this->assertSame( '', $new['manifest_hash'] );
		$this->assertSame( array( $key ), $new['payload']['retry_keys'] );
		$this->assertSame( array( 'medium' ), $new['payload']['remove'] );
		$this->assertArrayNotHasKey( 'approved_at', $new['payload'] );
		$this->item( $new, 'selected-path', 'queued' );
		$this->item( $new, 'outside-selection', 'queued' );
		yotm_job_update( $new['id'], array( 'phase' => 'manifest' ) );
		$built = yotm_prune_build_manifest_batch( yotm_job_get_by_id( $new['id'] ), 10 );
		$this->assertTrue( $built['done'] );
		$this->assertSame( 1, yotm_job_count_items( $new['id'] ) );
		$this->assertNotFalse( yotm_job_get_item_by_key( $new['id'], $key ) );
		$this->assertFalse( yotm_job_get_item_by_key( $new['id'], hash( 'sha256', 'outside-selection' ) ) );
		$this->assertSame( 'failed', yotm_job_get_item_by_key( $job['id'], $key )['status'] );
	}

	public function die_handler() {
		return array( $this, 'capture_die' );
	}

	public function capture_die( $message = '' ) {
		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Capture the exact AJAX response.
		throw new RuntimeException( (string) $message );
	}

	private function ajax( $callback, $token, $nonce = null ) {
		$_POST = array(
			'token' => $token,
			'nonce' => null === $nonce ? wp_create_nonce( 'yotm_prune_nonce' ) : $nonce,
		);
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Deliberate authenticated transport fixture.
		$_REQUEST = $_POST;
		ob_start();
		try {
			call_user_func( $callback );
		} catch ( RuntimeException $error ) {
			$body = ob_get_clean();
			return '' === $body ? $error->getMessage() : json_decode( $body, true );
		}
		ob_end_clean();
		$this->fail( 'The adapter did not terminate.' );
	}

	public function test_results_and_retry_adapters_require_capability_nonce_and_owner() {
		$job    = $this->source();
		$before = yotm_job_get_by_id( $job['id'] );
		add_filter( 'wp_doing_ajax', '__return_true' );
		add_filter( 'wp_die_ajax_handler', array( $this, 'die_handler' ) );
		try {
			foreach ( array( 'yotm_job_ajax_results', 'yotm_job_ajax_retry' ) as $callback ) {
				wp_set_current_user( 0 );
				$this->assertFalse( $this->ajax( $callback, $job['token'] )['success'] );
				wp_set_current_user( $this->admin );
				$this->assertSame( '-1', $this->ajax( $callback, $job['token'], 'invalid' ) );
				wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
				$this->assertFalse( $this->ajax( $callback, $job['token'] )['success'] );
			}
		} finally {
			remove_filter( 'wp_doing_ajax', '__return_true' );
			remove_filter( 'wp_die_ajax_handler', array( $this, 'die_handler' ) );
			$_POST    = array();
			$_REQUEST = array();
			wp_set_current_user( $this->admin );
		}
		$this->assertSame( $before, yotm_job_get_by_id( $job['id'] ) );
	}

	private function scan_prune( $token ) {
		for ( $i = 0; $i < 60; ++$i ) {
			$result = yotm_prune_scan_application( $token, 100 );
			yotm_job_release_all_workers();
			$this->assertTrue( $result['success'], wp_json_encode( $result ) );
			if ( ! empty( $result['data']['scan_done'] ) ) {
				return yotm_job_get( $token );
			}
		}
		$this->fail( 'Prune did not reach review.' );
	}

	public function test_prune_retry_rebuilds_real_selected_file_evidence_and_requires_new_approval() {
		require_once ABSPATH . 'wp-admin/includes/image.php';
		delete_option( YOTM_MEDIA_SOURCE_DIRTY_OPTION );
		$this->assertTrue( yotm_media_source_clear_index() );
		$state = yotm_media_reference_index_state();
		$this->assertTrue( yotm_media_reference_baseline_complete( $state['baseline_token'] ) );
		$dir = trailingslashit( wp_get_upload_dir()['basedir'] ) . 'retry-real-' . wp_generate_uuid4();
		wp_mkdir_p( $dir );
		copy( DIR_TESTDATA . '/images/2004-07-22-DSC_0007.jpg', $dir . '/source.jpg' );
		$id       = wp_insert_attachment(
			array(
				'post_mime_type' => 'image/jpeg',
				'post_title'     => 'Selected retry source',
			),
			$dir . '/source.jpg'
		);
		$metadata = wp_generate_attachment_metadata( $id, $dir . '/source.jpg' );
		wp_update_attachment_metadata( $id, $metadata );
		$target        = wp_normalize_path( $dir . '/' . $metadata['sizes']['thumbnail']['file'] );
		$other         = wp_normalize_path( $dir . '/' . $metadata['sizes']['medium']['file'] );
		$original_hash = hash_file( 'sha256', $dir . '/source.jpg' );
		$other_hash    = hash_file( 'sha256', $other );
		try {
			$keep     = array_values( array_diff( array_keys( yotm_get_registered_sizes() ), array( 'thumbnail', 'medium' ) ) );
			$prepared = yotm_prune_prepare_application( $keep, array(), false );
			$this->assertTrue( $prepared['success'], wp_json_encode( $prepared ) );
			$source       = $this->scan_prune( $prepared['data']['token'] );
			$this->jobs[] = $source['id'];
			$rows         = yotm_job_get_item_rows_page( $source['id'], 1, 100 );
			$this->assertCount( 2, $rows['items'] );
			$key = hash( 'sha256', $target );
			foreach ( $rows['items'] as $row ) {
				yotm_job_update_item( $row['id'], $key === $row['item_key'] ? 'failed' : 'done', 'Fixture terminal outcome' );
			}
			yotm_job_update(
				$source['id'],
				array(
					'status'    => 'completed',
					'phase'     => 'completed',
					'processed' => 2,
					'succeeded' => 1,
					'failed'    => 1,
				)
			);
			$retry = yotm_job_retry_application( $source['token'], array( $key ) );
			$this->assertTrue( $retry['success'], wp_json_encode( $retry ) );
			$new          = $this->scan_prune( $retry['data']['token'] );
			$this->jobs[] = $new['id'];
			$this->assertSame( 1, $new['total'] );
			$this->assertSame( 'awaiting_approval', $new['status'] );
			$this->assertNotSame( $source['manifest_hash'], $new['manifest_hash'] );
			$this->assertSame( $target, yotm_job_get_item_by_key( $new['id'], $key )['payload']['path'] );
			$this->assertFalse( yotm_prune_delete_application( $new['token'], $new['manifest_hash'], 10 )['success'] );
			$this->assertTrue( yotm_prune_approve_application( $new['token'], $new['manifest_hash'], true )['success'] );
			$deleted = yotm_prune_delete_application( $new['token'], $new['manifest_hash'], 10 );
			yotm_job_release_all_workers();
			$this->assertTrue( $deleted['success'], wp_json_encode( $deleted ) );
			$this->assertFileDoesNotExist( $target );
			$this->assertSame( $other_hash, hash_file( 'sha256', $other ) );
			$this->assertSame( $original_hash, hash_file( 'sha256', $dir . '/source.jpg' ) );
			$current = wp_get_attachment_metadata( $id );
			$this->assertArrayNotHasKey( 'thumbnail', $current['sizes'] );
			$this->assertSame( $metadata['sizes']['medium'], $current['sizes']['medium'] );
			$this->assertSame( 'failed', yotm_job_get_item_by_key( $source['id'], $key )['status'] );
		} finally {
			yotm_job_release_all_workers();
			wp_delete_attachment( $id, true );
			foreach ( glob( $dir . '/*' ) as $file ) {
				if ( is_file( $file ) ) {
					unlink( $file ); }
			}
			rmdir( $dir );
			delete_option( YOTM_MEDIA_REFERENCE_STATE_OPTION );
			delete_option( YOTM_MEDIA_SOURCE_DIRTY_OPTION );
		}
	}
}
