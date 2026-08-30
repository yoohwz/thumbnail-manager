<?php

/**
 * Persistent job storage tests.
 */
class YOTM_Job_Storage_Test extends WP_UnitTestCase {
	/**
	 * Administrator ID used by tests.
	 *
	 * @var int
	 */
	private $administrator_id;

	/**
	 * Temporary files created by integration tests.
	 *
	 * @var string[]
	 */
	private $files = array();

	/**
	 * Temporary directories created by integration tests.
	 *
	 * @var string[]
	 */
	private $directories = array();

	/**
	 * Manifest page query stage to fail.
	 *
	 * @var string
	 */
	private $manifest_page_failure_stage = '';

	public function setUp(): void {
		parent::setUp();
		yotm_install_job_tables();
		delete_option( YOTM_MEDIA_SOURCE_DIRTY_OPTION );
		$this->assertTrue( yotm_media_source_clear_index() );
		$reference_state = yotm_media_reference_index_state();
		$this->assertIsArray( $reference_state );
		$this->assertTrue( yotm_media_reference_baseline_complete( $reference_state['baseline_token'] ) );
		$this->administrator_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->administrator_id );
		$this->clear_jobs();
	}

	public function tearDown(): void {
		remove_filter( 'query', array( $this, 'fail_named_lock_query' ) );
		remove_filter( 'query', array( $this, 'fail_worker_cas_query' ) );
		remove_filter( 'query', array( $this, 'fail_historical_evidence_read' ) );
		remove_filter( 'query', array( $this, 'fail_manifest_page_read' ) );
		$this->manifest_page_failure_stage = '';
		remove_filter( 'query', array( $this, 'force_named_lock_contention' ) );
		$this->clear_jobs();
		foreach ( array_unique( $this->files ) as $file ) {
			if ( file_exists( $file ) ) {
				wp_delete_file( $file );
			}
		}
		foreach ( array_reverse( array_unique( $this->directories ) ) as $directory ) {
			if ( is_dir( $directory ) ) {
				rmdir( $directory );
			}
		}
		wp_set_current_user( 0 );
		delete_option( YOTM_MEDIA_REFERENCE_STATE_OPTION );
		delete_option( YOTM_MEDIA_SOURCE_DIRTY_OPTION );
		parent::tearDown();
	}

	public function test_job_is_bound_to_creating_user() {
		$job = yotm_job_create( 'recommendation', array(), array( 'exclusive' => false ) );
		$this->assertIsArray( $job );

		$other_admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $other_admin );

		$result = yotm_job_get( $job['token'] );
		$this->assertWPError( $result );
		$this->assertSame( 'yotm_job_missing', $result->get_error_code() );
	}

	public function test_pre_extraction_persisted_job_and_item_remain_resumable_and_cancellable() {
		global $wpdb;

		$tables       = yotm_job_table_names();
		$token        = wp_generate_uuid4();
		$now          = gmdate( 'Y-m-d H:i:s' );
		$item_key     = hash( 'sha256', 'pre-extraction-item' );
		$item_payload = array( 'record_id' => 'persisted-A' );
		$payload      = array(
			'fixture' => 'pre-extraction-job',
			'cursor'  => 'opaque-17',
		);

		$this->assertNotFalse(
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Exact persisted compatibility fixture.
			$wpdb->insert(
				$tables['jobs'],
				array(
					'token'                   => $token,
					'blog_id'                 => get_current_blog_id(),
					'user_id'                 => $this->administrator_id,
					'type'                    => 'bounded_export',
					'status'                  => 'scanning',
					'phase'                   => 'metadata',
					'counter_mode'            => 'item_v2',
					'manifest_hash'           => '',
					'payload'                 => wp_json_encode( $payload ),
					'total'                   => 1,
					'processed'               => 0,
					'succeeded'               => 0,
					'failed'                  => 0,
					'bytes'                   => 0,
					'cursor_id'               => 17,
					'max_id'                  => 30,
					'worker_token'            => '',
					'worker_generation'       => 0,
					'worker_lease_expires_at' => null,
					'created_at'              => $now,
					'updated_at'              => $now,
					'expires_at'              => gmdate( 'Y-m-d H:i:s', time() + HOUR_IN_SECONDS ),
				)
			)
		);
		$job_id = (int) $wpdb->insert_id;
		$this->assertNotFalse(
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Exact persisted compatibility fixture.
			$wpdb->insert(
				$tables['items'],
				array(
					'job_id'           => $job_id,
					'item_key'         => $item_key,
					'status'           => 'queued',
					'payload'          => wp_json_encode( $item_payload ),
					'error'            => '',
					'bytes'            => 13,
					'claim_token'      => '',
					'claim_generation' => 4,
					'claim_expires_at' => null,
					'attempts'         => 1,
					'created_at'       => $now,
					'updated_at'       => $now,
				)
			)
		);

		$persisted = yotm_job_get( $token );
		$this->assertIsArray( $persisted );
		$this->assertSame( $job_id, $persisted['id'] );
		$this->assertSame( 'bounded_export', $persisted['type'] );
		$this->assertSame( $payload, $persisted['payload'] );
		$this->assertSame( 17, $persisted['cursor'] );
		$persisted_item = yotm_job_get_item_by_key( $persisted['id'], $item_key );
		$this->assertIsArray( $persisted_item );
		$this->assertSame( $item_payload, $persisted_item['payload'] );
		$this->assertSame( 4, $persisted_item['claim_generation'] );
		$this->assertSame( 1, $persisted_item['attempts'] );

		$worker = yotm_job_acquire_worker( $persisted['id'], array( 'scanning' ), array( 'metadata' ) );
		$this->assertIsArray( $worker );
		$items = yotm_job_claim_items( $worker, 1 );
		$this->assertCount( 1, $items );
		$this->assertSame( $item_payload, $items[0]['payload'] );
		$this->assertSame( 5, $items[0]['claim_generation'] );
		$this->assertSame( 2, $items[0]['attempts'] );
		$stale_item                     = $items[0];
		$stale_item['claim_generation'] = 4;
		$this->assertFalse( yotm_job_finish_item( $stale_item, 'done', '', 13 ) );
		$this->assertTrue( yotm_job_finish_item( $items[0], 'done', '', 13 ) );
		$this->assertFalse( yotm_job_finish_item( $items[0], 'done', '', 13 ) );
		$synced = yotm_job_sync_item_counters( $persisted['id'] );
		$this->assertIsArray( $synced );
		$this->assertSame( 1, $synced['processed'] );
		$this->assertSame( 1, $synced['succeeded'] );
		$this->assertSame( 0, $synced['failed'] );
		$this->assertSame( 13, $synced['bytes'] );
		$this->assertTrue( yotm_job_worker_update( $worker, array( 'cursor' => 18 ) ) );
		yotm_job_release_worker( $worker );

		$cancelled = yotm_job_cancel( yotm_job_get( $token ) );
		$this->assertIsArray( $cancelled );
		$this->assertSame( 'cancelled', $cancelled['status'] );
		$this->assertSame( 'cancelled', $cancelled['phase'] );
		$this->assertSame( 18, $cancelled['cursor'] );
		$this->assertSame( 'pre-extraction-job', $cancelled['payload']['fixture'] );
		$this->assertNotEmpty( $cancelled['payload']['cancelled_at'] );
		$this->assertGreaterThan( time() + DAY_IN_SECONDS, strtotime( $cancelled['expires_at'] ) );
	}

	public function test_destructive_jobs_are_locked_per_site() {
		$prune = yotm_job_create(
			'prune',
			array(),
			array(
				'status' => 'scanning',
				'phase'  => 'metadata',
			)
		);
		$this->assertIsArray( $prune );

		$regenerate = yotm_job_create( 'regenerate', array(), array( 'status' => 'running' ) );
		$this->assertWPError( $regenerate );
		$this->assertSame( 'yotm_job_locked', $regenerate->get_error_code() );
	}

	public function test_force_source_index_phase_can_materialize_explicit_items() {
		$job = yotm_job_create(
			'regenerate',
			array( 'force_all' => 1 ),
			array(
				'status' => 'running',
				'phase'  => 'source_index',
			)
		);
		$this->assertIsArray( $job );
		$this->assertTrue( yotm_job_add_item( $job['id'], 'force-explicit-17', array( 'attachment_id' => 17 ) ) );
		$this->assertTrue( yotm_job_item_exists( $job['id'], 'force-explicit-17' ) );
	}

	public function test_only_one_active_job_per_user_site_and_type() {
		$first = yotm_job_create(
			'recommendation',
			array(),
			array(
				'status'    => 'scanning',
				'phase'     => 'metadata',
				'exclusive' => false,
			)
		);
		$this->assertIsArray( $first );

		$duplicate = yotm_job_create(
			'recommendation',
			array(),
			array(
				'status'    => 'scanning',
				'phase'     => 'metadata',
				'exclusive' => false,
			)
		);
		$this->assertWPError( $duplicate );
		$this->assertSame( 'yotm_job_exists', $duplicate->get_error_code() );
		$this->assertSame( $first['token'], $duplicate->get_error_data()['token'] );
	}

	public function test_worker_generation_fences_a_stale_request_after_takeover() {
		global $wpdb;

		$job          = yotm_job_create(
			'recommendation',
			array(),
			array(
				'status'       => 'scanning',
				'phase'        => 'metadata',
				'counter_mode' => 'item_v2',
				'exclusive'    => false,
			)
		);
		$first_worker = yotm_job_acquire_worker( $job['id'], array( 'scanning' ), array( 'metadata' ) );
		$this->assertIsArray( $first_worker );
		$this->assertTrue( yotm_job_worker_update( $first_worker, array( 'processed' => 1 ) ) );

		$tables = yotm_job_table_names();
		$wpdb->update(
			$tables['jobs'],
			array( 'worker_lease_expires_at' => gmdate( 'Y-m-d H:i:s', time() - MINUTE_IN_SECONDS ) ),
			array( 'id' => $job['id'] )
		);
		yotm_job_release_named_lock( $first_worker['lock_name'] );

		$second_worker = yotm_job_acquire_worker( $job['id'], array( 'scanning' ), array( 'metadata' ) );
		$this->assertIsArray( $second_worker );
		$this->assertGreaterThan( $first_worker['generation'], $second_worker['generation'] );
		$this->assertFalse( yotm_job_worker_update( $first_worker, array( 'processed' => 99 ) ) );
		$this->assertTrue( yotm_job_worker_update( $second_worker, array( 'processed' => 2 ) ) );
		$this->assertSame( 2, yotm_job_get_by_id( $job['id'] )['processed'] );

		yotm_job_release_worker( $second_worker );
	}

	public function test_historical_evidence_mutation_and_cleanup_are_exact_worker_fenced() {
		global $wpdb;

		$job = yotm_job_create(
			'prune',
			array(),
			array(
				'status'       => 'scanning',
				'phase'        => 'cohort_aggregate',
				'counter_mode' => 'item_v3',
			)
		);
		$this->assertIsArray( $job );
		$first_worker = yotm_job_acquire_worker( $job['id'], array( 'scanning' ), array( 'cohort_aggregate', 'cohort_materialize' ) );
		$this->assertIsArray( $first_worker );

		$tables = yotm_job_table_names();
		$wpdb->update(
			$tables['jobs'],
			array( 'worker_lease_expires_at' => gmdate( 'Y-m-d H:i:s', time() - MINUTE_IN_SECONDS ) ),
			array( 'id' => $job['id'] )
		);
		yotm_job_release_named_lock( $first_worker['lock_name'] );
		$second_worker = yotm_job_acquire_worker( $job['id'], array( 'scanning' ), array( 'cohort_aggregate', 'cohort_materialize' ) );
		$this->assertIsArray( $second_worker );

		$this->assertFalse( yotm_job_add_item( $job['id'], 'late-generic-row', array( 'late' => 1 ), 'queued' ) );
		$this->assertFalse( yotm_job_worker_add_item( $second_worker, 'invalid-worker-row', array( 'late' => 1 ), 'queued' ) );
		$stale_key = hash( 'sha256', 'stale-historical-row' );
		$this->assertFalse( yotm_job_worker_add_item( $first_worker, $stale_key, array( 'stale' => 1 ), 'historical_cohort' ) );
		$this->assertFalse( yotm_job_item_exists( $job['id'], $stale_key ) );

		$item_key = hash( 'sha256', 'current-historical-row' );
		$this->assertTrue( yotm_job_worker_add_item( $second_worker, $item_key, array( 'sealed' => 0 ), 'historical_cohort' ) );
		$item = yotm_job_get_item_by_key( $job['id'], $item_key );
		$this->assertIsArray( $item );
		$this->assertFalse( yotm_job_worker_replace_item( $second_worker, $item['id'], 'historical_cohort', 'queued', array( 'sealed' => 1 ) ) );
		$this->assertSame( 1, yotm_job_count_items_by_status( $job['id'], array( 'historical_cohort' ) ) );
		$suppressing = $wpdb->suppress_errors();
		add_filter( 'query', array( $this, 'fail_historical_evidence_read' ) );
		$this->assertWPError( yotm_job_count_items_by_status( $job['id'], array( 'historical_cohort' ) ) );
		$this->assertWPError( yotm_job_get_status_rows_after_id( $job['id'], 'historical_cohort', 0, 10 ) );
		$this->assertWPError( yotm_job_get_manifest_rows_after( $job['id'], '', 10 ) );
		remove_filter( 'query', array( $this, 'fail_historical_evidence_read' ) );
		$wpdb->suppress_errors( $suppressing );

		$this->assertTrue( yotm_job_worker_update( $second_worker, array( 'phase' => 'cohort_materialize' ) ) );
		$this->assertWPError( yotm_job_worker_delete_status_batch( $second_worker, array( 'queued' ), 1 ) );
		$this->assertSame( 1, yotm_job_worker_delete_status_batch( $second_worker, array( 'historical_cohort' ), 1 ) );
		$this->assertSame( 0, yotm_job_count_items_by_status( $job['id'], array( 'historical_cohort' ) ) );
		yotm_job_release_worker( $second_worker );
	}

	public function test_partial_historical_cleanup_advances_without_a_noop_storage_error() {
		$job = yotm_job_create(
			'prune',
			array(
				'scan_phase'               => 'cohort_materialize',
				'cohort_materialize_stage' => 'cleanup',
			),
			array(
				'status'       => 'scanning',
				'phase'        => 'cohort_materialize',
				'counter_mode' => 'item_v3',
			)
		);
		$this->assertIsArray( $job );

		$worker = yotm_job_acquire_worker( $job['id'], array( 'scanning' ), array( 'cohort_materialize' ) );
		$this->assertIsArray( $worker );

		for ( $index = 1; $index <= 3; $index++ ) {
			$this->assertTrue(
				yotm_job_worker_add_item(
					$worker,
					hash( 'sha256', 'partial-cleanup-' . $index ),
					array( 'evidence' => $index ),
					'historical_cohort'
				)
			);
		}

		$first = yotm_prune_historical_materialize_batch( yotm_job_get_by_id( $job['id'] ), 1, $worker );
		$this->assertIsArray( $first );
		$this->assertFalse( $first['done'] );
		$this->assertSame( 2, yotm_job_count_items_by_status( $job['id'], array( 'historical_cohort' ) ) );
		$this->assertSame( 'cohort_materialize', $first['job']['phase'] );

		$second = yotm_prune_historical_materialize_batch( $first['job'], 1, $worker );
		$this->assertIsArray( $second );
		$this->assertFalse( $second['done'] );
		$this->assertSame( 1, yotm_job_count_items_by_status( $job['id'], array( 'historical_cohort' ) ) );

		$final = yotm_prune_historical_materialize_batch( $second['job'], 1, $worker );
		$this->assertIsArray( $final );
		$this->assertTrue( $final['done'] );
		$this->assertSame( 0, yotm_job_count_items_by_status( $job['id'], array( 'historical_cohort' ) ) );
		$this->assertSame( 'manifest', $final['job']['phase'] );

		yotm_job_release_worker( $worker );
	}

	public function test_manifest_review_page_reads_fail_closed() {
		global $wpdb;

		$job = yotm_job_create(
			'prune',
			array(),
			array(
				'status'       => 'scanning',
				'phase'        => 'metadata',
				'counter_mode' => 'item_v3',
			)
		);
		$this->assertIsArray( $job );
		$this->assertTrue( yotm_job_add_item( $job['id'], 'manifest-page-item', array( 'path' => '/uploads/reviewed.jpg' ), 'queued', 1 ) );

		$suppressing = $wpdb->suppress_errors();
		add_filter( 'query', array( $this, 'fail_manifest_page_read' ) );
		try {
			$this->manifest_page_failure_stage = 'count';
			$this->assertWPError( yotm_job_get_item_rows_page( $job['id'], 1, 25 ) );
			$this->assertWPError( yotm_prune_get_items_page( $job['id'], 1, 25 ) );
			$this->manifest_page_failure_stage = 'list';
			$this->assertWPError( yotm_job_get_item_rows_page( $job['id'], 1, 25 ) );
			$this->assertWPError( yotm_prune_get_items_page( $job['id'], 1, 25 ) );
		} finally {
			remove_filter( 'query', array( $this, 'fail_manifest_page_read' ) );
			$this->manifest_page_failure_stage = '';
			$wpdb->suppress_errors( $suppressing );
		}
	}

	public function test_failed_named_lock_query_is_not_reported_as_worker_contention() {
		global $wpdb;

		$job = yotm_job_create(
			'recommendation',
			array(),
			array(
				'status'    => 'scanning',
				'phase'     => 'metadata',
				'exclusive' => false,
			)
		);
		$this->assertIsArray( $job );

		$suppressing = $wpdb->suppress_errors();
		add_filter( 'query', array( $this, 'fail_named_lock_query' ) );

		try {
			$worker = yotm_job_acquire_worker( $job['id'], array( 'scanning' ), array( 'metadata' ) );
			$this->assertWPError( $worker );
			$this->assertSame( 'yotm_job_storage_unavailable', $worker->get_error_code() );
			$this->assertNotSame( 'yotm_job_worker_busy', $worker->get_error_code() );
		} finally {
			remove_filter( 'query', array( $this, 'fail_named_lock_query' ) );
			$wpdb->suppress_errors( $suppressing );
		}
	}

	public function test_failed_worker_compare_and_swap_is_not_reported_as_worker_contention() {
		global $wpdb;

		$job = yotm_job_create(
			'recommendation',
			array(),
			array(
				'status'    => 'scanning',
				'phase'     => 'metadata',
				'exclusive' => false,
			)
		);
		$this->assertIsArray( $job );

		$suppressing = $wpdb->suppress_errors();
		add_filter( 'query', array( $this, 'fail_worker_cas_query' ) );

		try {
			$worker = yotm_job_acquire_worker( $job['id'], array( 'scanning' ), array( 'metadata' ) );
			$this->assertWPError( $worker );
			$this->assertSame( 'yotm_job_storage_unavailable', $worker->get_error_code() );
			$this->assertNotSame( 'yotm_job_worker_busy', $worker->get_error_code() );
		} finally {
			remove_filter( 'query', array( $this, 'fail_worker_cas_query' ) );
			$wpdb->suppress_errors( $suppressing );
		}

		$worker = yotm_job_acquire_worker( $job['id'], array( 'scanning' ), array( 'metadata' ) );
		$this->assertIsArray( $worker, 'The failed CAS must release its named lock.' );
		yotm_job_release_worker( $worker );
	}

	public function test_item_claims_are_owned_and_counters_are_derived_once() {
		$job = yotm_job_create(
			'recommendation',
			array(),
			array(
				'status'       => 'scanning',
				'phase'        => 'metadata',
				'counter_mode' => 'item_v2',
				'exclusive'    => false,
			)
		);
		yotm_job_add_item( $job['id'], 'first-claim', array( 'attachment_id' => 1 ) );
		yotm_job_add_item( $job['id'], 'second-claim', array( 'attachment_id' => 2 ) );
		$worker = yotm_job_acquire_worker( $job['id'], array( 'scanning' ), array( 'metadata' ) );
		$items  = yotm_job_claim_items( $worker, 2 );

		$this->assertCount( 2, $items );
		$this->assertSame( 1, $items[0]['attempts'] );
		$stale_item                     = $items[0];
		$stale_item['claim_token']      = wp_generate_uuid4();
		$stale_item['claim_generation'] = $items[0]['claim_generation'] + 1;
		$this->assertFalse( yotm_job_finish_item( $stale_item, 'done', '', 99 ) );
		$this->assertTrue( yotm_job_finish_item( $items[0], 'done', '', 12 ) );
		$this->assertFalse( yotm_job_finish_item( $items[0], 'done', '', 12 ) );
		$this->assertTrue( yotm_job_finish_item( $items[1], 'failed', 'expected failure' ) );

		$counters = yotm_job_item_counters( $job['id'] );
		$this->assertSame( 2, $counters['processed'] );
		$this->assertSame( 1, $counters['succeeded'] );
		$this->assertSame( 1, $counters['failed'] );
		$this->assertSame( 12, $counters['bytes'] );
		$this->assertSame( 0, $counters['remaining'] );
		$stored = yotm_job_sync_item_counters( $job['id'] );
		$this->assertSame( 2, $stored['processed'] );
		$this->assertSame( 1, $stored['succeeded'] );
		$this->assertSame( 1, $stored['failed'] );

		yotm_job_release_worker( $worker );
	}

	public function test_generic_non_media_bounded_job_uses_shared_lifecycle() {
		$payload = array(
			'format' => 'csv',
			'scope'  => 'catalog',
		);
		$job     = yotm_job_create(
			'bounded_export',
			$payload,
			array(
				'status'       => 'scanning',
				'phase'        => 'metadata',
				'counter_mode' => 'item_v2',
				'exclusive'    => false,
				'total'        => 2,
			)
		);
		$this->assertIsArray( $job );
		$this->assertSame( $payload, $job['payload'] );
		$this->assertSame( 'bounded_export', $job['type'] );

		$this->assertTrue( yotm_job_add_item( $job['id'], 'record-1', array( 'record_id' => 'A-1' ) ) );
		$this->assertTrue( yotm_job_add_item( $job['id'], 'record-2', array( 'record_id' => 'A-2' ) ) );
		$worker = yotm_job_acquire_worker( $job['id'], array( 'scanning' ), array( 'metadata' ) );
		$this->assertIsArray( $worker );
		$items = yotm_job_claim_items( $worker, 2 );
		$this->assertCount( 2, $items );
		$this->assertTrue( yotm_job_finish_item( $items[0], 'done', '', 7 ) );
		$this->assertTrue( yotm_job_finish_item( $items[1], 'done', '', 9 ) );

		$stored = yotm_job_sync_item_counters( $job['id'] );
		$this->assertIsArray( $stored );
		$this->assertSame( 2, $stored['processed'] );
		$this->assertSame( 2, $stored['succeeded'] );
		$this->assertSame( 0, $stored['failed'] );
		$this->assertSame( 16, $stored['bytes'] );
		$this->assertTrue(
			yotm_job_worker_update(
				$worker,
				array(
					'status' => 'completed',
					'phase'  => 'completed',
				)
			)
		);
		yotm_job_release_worker( $worker );

		$completed = yotm_job_get( $job['token'] );
		$this->assertSame( 'completed', $completed['status'] );
		$this->assertSame( 'completed', $completed['phase'] );
		$this->assertSame( $payload, $completed['payload'] );
	}

	public function test_cancelled_job_rejects_late_worker_state_transition() {
		$job       = yotm_job_create(
			'recommendation',
			array(),
			array(
				'status'    => 'scanning',
				'phase'     => 'metadata',
				'exclusive' => false,
			)
		);
		$worker    = yotm_job_acquire_worker( $job['id'], array( 'scanning' ), array( 'metadata' ) );
		$cancelled = yotm_job_cancel( yotm_job_get_by_id( $job['id'] ) );

		$this->assertSame( 'cancelled', $cancelled['status'] );
		$this->assertFalse(
			yotm_job_worker_update(
				$worker,
				array(
					'status' => 'completed',
					'phase'  => 'completed',
				)
			)
		);
		$this->assertSame( 'cancelled', yotm_job_get_by_id( $job['id'] )['status'] );

		yotm_job_release_worker( $worker );
	}

	public function test_item_v3_finalizes_item_and_job_counters_exactly_once() {
		$job = yotm_job_create(
			'regenerate',
			array( 'discovery_done' => 1 ),
			array(
				'status'       => 'running',
				'phase'        => 'regenerate',
				'counter_mode' => 'item_v3',
				'total'        => 1,
			)
		);
		$this->assertTrue( yotm_job_add_item( $job['id'], 'item-v3-once', array( 'attachment_id' => 77 ), 'queued', 12 ) );
		$worker = yotm_job_acquire_worker( $job['id'], array( 'running' ), array( 'regenerate' ) );
		$item   = yotm_job_claim_items( $worker, 1 )[0];

		$this->assertTrue( yotm_job_finish_item_v3( $item, $worker, 'done', '', 12 ) );
		$this->assertFalse( yotm_job_finish_item_v3( $item, $worker, 'done', '', 12 ) );

		$current = yotm_job_get_by_id( $job['id'] );
		$this->assertSame( 1, $current['processed'] );
		$this->assertSame( 1, $current['succeeded'] );
		$this->assertSame( 0, $current['failed'] );
		$this->assertSame( 12, $current['bytes'] );
		$this->assertFalse( yotm_job_has_remaining_items( $job['id'] ) );
		$this->assertSame( 1, yotm_job_item_counters( $job['id'] )['processed'] );

		yotm_job_release_worker( $worker );
	}

	public function test_regeneration_journal_checkpoint_is_exact_claim_fenced() {
		$job = yotm_job_create(
			'regenerate',
			array( 'discovery_done' => 1 ),
			array(
				'status'       => 'running',
				'phase'        => 'regenerate',
				'counter_mode' => 'item_v3',
				'total'        => 1,
			)
		);
		$this->assertTrue( yotm_job_add_item( $job['id'], 'journal-fence', array( 'attachment_id' => 77 ) ) );
		$worker  = yotm_job_acquire_worker( $job['id'], array( 'running' ), array( 'regenerate' ) );
		$item    = yotm_job_claim_items( $worker, 1 )[0];
		$journal = array(
			'version'       => YOTM_REGENERATE_JOURNAL_VERSION,
			'phase'         => 'prepared',
			'attachment_id' => 77,
		);

		$this->assertTrue( yotm_regenerate_persist_journal( $item, $journal ) );
		$current = yotm_job_get_item_by_key( $job['id'], 'journal-fence' );
		$this->assertSame( $journal, $current['payload']['regeneration_journal'] );
		$this->assertTrue( yotm_job_release_item_claim( $item ) );

		$stale_journal          = $journal;
		$stale_journal['phase'] = 'files_promoted';
		$this->assertFalse( yotm_regenerate_persist_journal( $item, $stale_journal ) );
		$current = yotm_job_get_item_by_key( $job['id'], 'journal-fence' );
		$this->assertSame( $journal, $current['payload']['regeneration_journal'] );
		yotm_job_release_worker( $worker );
	}

	public function test_requeued_journal_blocks_cancel_and_expiry_claims_only_recovery_item() {
		global $wpdb;

		$job = yotm_job_create(
			'regenerate',
			array( 'discovery_done' => 1 ),
			array(
				'status'       => 'running',
				'phase'        => 'regenerate',
				'counter_mode' => 'item_v3',
				'total'        => 2,
			)
		);
		$this->assertTrue( yotm_job_add_item( $job['id'], 'first-unjournaled', array( 'attachment_id' => 10 ) ) );
		$this->assertTrue(
			yotm_job_add_item(
				$job['id'],
				'second-journaled',
				array(
					'attachment_id'        => 20,
					'regeneration_journal' => array( 'phase' => 'promoted' ),
				)
			)
		);

		$worker = yotm_job_acquire_worker( $job['id'], array( 'running' ), array( 'regenerate' ) );
		$items  = yotm_job_claim_items( $worker, 2 );
		$this->assertCount( 2, $items );
		foreach ( $items as $item ) {
			$this->assertTrue( yotm_job_release_item_claim( $item ) );
		}
		yotm_job_release_worker( $worker );

		$this->assertTrue( yotm_job_has_recovery_journals( $job['id'] ) );
		$tables = yotm_job_table_names();
		$wpdb->update(
			$tables['jobs'],
			array( 'expires_at' => gmdate( 'Y-m-d H:i:s', time() - MINUTE_IN_SECONDS ) ),
			array( 'id' => $job['id'] )
		);
		$recovering = yotm_job_expire_if_inactive( yotm_job_get_by_id( $job['id'] ) );
		$this->assertSame( 'running', $recovering['status'] );
		$this->assertSame( 1, $recovering['payload']['recovery_only'] );
		$this->assertSame( 'expired', $recovering['payload']['recovery_terminal_status'] );

		$cancelled = yotm_job_cancel( $recovering );
		$this->assertWPError( $cancelled );
		$this->assertSame( 'yotm_job_cancel_busy', $cancelled->get_error_code() );
		$recovering = yotm_job_get_by_id( $job['id'] );
		$this->assertSame( 1, $recovering['payload']['recovery_only'] );
		$this->assertSame( 'cancelled', $recovering['payload']['recovery_terminal_status'] );
		$this->assertNotEmpty( $recovering['payload']['cancel_requested_at'] );
		$this->assertSame( 'cancelled', yotm_job_recovery_terminal_status( $recovering ) );

		$worker = yotm_job_acquire_worker( $job['id'], array( 'running' ), array( 'regenerate' ) );
		$items  = yotm_job_claim_items( $worker, 1, true );
		$this->assertCount( 1, $items );
		$this->assertSame( 20, $items[0]['payload']['attachment_id'] );
		yotm_job_release_item_claim( $items[0] );
		yotm_job_release_worker( $worker );
	}

	public function test_cancel_is_retryable_while_worker_lock_is_contended() {
		$job = yotm_job_create(
			'recommendation',
			array(),
			array(
				'status'    => 'scanning',
				'phase'     => 'metadata',
				'exclusive' => false,
			)
		);
		add_filter( 'query', array( $this, 'force_named_lock_contention' ) );
		$result = yotm_job_cancel( $job );
		remove_filter( 'query', array( $this, 'force_named_lock_contention' ) );
		$this->assertWPError( $result );
		$this->assertSame( 'yotm_job_cancel_busy', $result->get_error_code() );
		$this->assertSame( 'scanning', yotm_job_get_by_id( $job['id'] )['status'] );
	}

	public function test_expired_active_job_is_retained_before_terminal_cleanup() {
		global $wpdb;

		$job    = yotm_job_create( 'recommendation', array(), array( 'exclusive' => false ) );
		$tables = yotm_job_table_names();
		$wpdb->update(
			$tables['jobs'],
			array( 'expires_at' => gmdate( 'Y-m-d H:i:s', time() - MINUTE_IN_SECONDS ) ),
			array( 'id' => $job['id'] )
		);

		$this->assertTrue( yotm_cleanup_expired_jobs() );
		$expired = yotm_job_get_by_id( $job['id'] );
		$this->assertSame( 'expired', $expired['status'] );
		$this->assertGreaterThan( time(), strtotime( $expired['expires_at'] . ' UTC' ) );
		$this->assertWPError( yotm_job_get( $job['token'] ) );

		$wpdb->update(
			$tables['jobs'],
			array( 'expires_at' => gmdate( 'Y-m-d H:i:s', time() - MINUTE_IN_SECONDS ) ),
			array( 'id' => $job['id'] )
		);
		$this->assertTrue( yotm_cleanup_expired_jobs() );
		$this->assertFalse( yotm_job_get_by_id( $job['id'] ) );
	}

	public function test_legacy_cursor_regenerate_resumes_with_job_counters() {
		$first_id  = self::factory()->post->create(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'post_mime_type' => 'image/jpeg',
			)
		);
		$second_id = self::factory()->post->create(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'post_mime_type' => 'image/jpeg',
			)
		);
		update_post_meta( $first_id, '_wp_attached_file', 'missing-first.jpg' );
		update_post_meta( $second_id, '_wp_attached_file', 'missing-second.jpg' );

		$job = yotm_job_create(
			'regenerate',
			array(
				'cursor_mode' => 1,
				'query_args'  => array(),
			),
			array(
				'status' => 'running',
				'phase'  => 'regenerate',
				'total'  => 2,
				'cursor' => $first_id,
				'max_id' => $second_id,
			)
		);
		yotm_job_update(
			$job['id'],
			array(
				'processed' => 1,
				'failed'    => 1,
			)
		);
		yotm_job_add_item(
			$job['id'],
			hash( 'sha256', 'regenerate-failure:' . $first_id ),
			array( 'attachment_id' => $first_id ),
			'failed'
		);
		$worker = yotm_job_acquire_worker( $job['id'], array( 'running' ), array( 'regenerate' ) );

		$first_batch = yotm_regenerate_legacy_batch( yotm_job_get_by_id( $job['id'] ), $worker, 10 );
		$this->assertFalse( $first_batch['done'] );
		$this->assertSame( 2, $first_batch['processed'] );
		$this->assertSame( 2, $first_batch['failed'] );
		$this->assertSame( $second_id, yotm_job_get_by_id( $job['id'] )['cursor'] );

		$final_batch = yotm_regenerate_legacy_batch( yotm_job_get_by_id( $job['id'] ), $worker, 10 );
		$this->assertTrue( $final_batch['done'] );
		$this->assertSame( 2, $final_batch['processed'] );
		$this->assertSame( 2, $final_batch['failed'] );
		$this->assertSame( 'legacy_v1', yotm_job_get_by_id( $job['id'] )['counter_mode'] );

		yotm_job_release_worker( $worker );
	}

	public function test_manifest_is_stable_and_cannot_grow_after_review() {
		global $wpdb;

		$job = yotm_job_create(
			'prune',
			array(
				'ownership_schema'      => 'generated_file_v1',
				'source_index_complete' => 1,
			),
			array(
				'status' => 'scanning',
				'phase'  => 'metadata',
			)
		);
		$this->assertTrue( yotm_job_add_item( $job['id'], 'second', array( 'path' => '/tmp/second.jpg' ) ) );
		$this->assertTrue( yotm_job_add_item( $job['id'], 'first', array( 'path' => '/tmp/first.jpg' ) ) );
		yotm_job_update( $job['id'], array( 'phase' => 'manifest' ) );
		$this->assertFalse( yotm_job_merge_item_payload( $job['id'], 'first', array( 'metadata_refs' => array() ) ) );
		$tables = yotm_job_table_names();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Exact persisted manifest characterization fixture.
		$persisted_rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT item_key,payload FROM {$tables['items']} WHERE job_id = %d ORDER BY item_key ASC",
				$job['id']
			)
		);

		$expected_digest = hash( 'sha256', 'yotm-manifest-v1' );
		foreach ( $persisted_rows as $persisted_row ) {
			$expected_digest = hash( 'sha256', $expected_digest . ':' . $persisted_row->item_key . ':' . hash( 'sha256', (string) $persisted_row->payload ) );
		}

		do {
			$manifest = yotm_job_build_manifest_batch( yotm_job_get_by_id( $job['id'] ), 10 );
		} while ( ! $manifest['done'] );

		$job = $manifest['job'];
		$this->assertMatchesRegularExpression( '/^[a-f0-9]{64}$/', $job['manifest_hash'] );
		$this->assertSame( $expected_digest, $job['manifest_hash'] );
		$this->assertSame( $expected_digest, $job['payload']['manifest_digest'] );

		yotm_job_update(
			$job['id'],
			array(
				'status' => 'awaiting_approval',
				'phase'  => 'review',
			)
		);
		$this->assertFalse( yotm_job_add_item( $job['id'], 'late-item', array( 'path' => '/tmp/late.jpg' ) ) );
		$this->assertSame( 2, yotm_job_count_items( $job['id'] ) );
	}

	public function test_manifest_class_counts_persist_on_partial_checkpoint_without_double_counting_on_resume() {
		$job = yotm_job_create(
			'prune',
			array(
				'manifest_class_counts' => array(
					'metadata_backed' => 0,
					'verified_legacy' => 0,
				),
			),
			array(
				'status' => 'scanning',
				'phase'  => 'manifest',
			)
		);
		$this->assertIsArray( $job );
		$digest = yotm_job_manifest_digest_seed();
		$counts = array(
			'metadata_backed' => 7,
			'verified_legacy' => 3,
		);

		$partial = yotm_job_update_manifest_checkpoint( $job, 'row-10', $digest, false, null, array( 'manifest_class_counts' => $counts ) );
		$this->assertFalse( $partial['done'] );
		$this->assertSame( $counts, $partial['job']['payload']['manifest_class_counts'] );
		$this->assertSame( 'row-10', $partial['job']['payload']['manifest_after'] );
		$this->assertSame( $counts, yotm_job_public_data( $partial['job'] )['context']['manifest_class_counts'] );

		$resumed = yotm_job_update_manifest_checkpoint( $partial['job'], 'row-10', $digest, true, null, array( 'manifest_class_counts' => $counts ) );
		$this->assertTrue( $resumed['done'] );
		$this->assertSame( $counts, $resumed['job']['payload']['manifest_class_counts'] );
		$this->assertSame( $digest, $resumed['job']['manifest_hash'] );
	}

	public function test_manifest_items_are_paged_searchable_and_sum_estimated_bytes() {
		$job = yotm_job_create(
			'prune',
			array(),
			array(
				'status' => 'scanning',
				'phase'  => 'metadata',
			)
		);

		for ( $index = 1; $index <= 12; ++$index ) {
			$size = 1 === $index ? '100x100' : '200x200';
			$this->assertTrue(
				yotm_job_add_item(
					$job['id'],
					'manifest-item-' . $index,
					array(
						'path'            => '/tmp/image-' . $index . '-' . $size . '.jpg',
						'size'            => $size,
						'estimated_bytes' => 100,
					),
					'queued',
					100
				)
			);
		}

		$this->assertSame( 1200, yotm_job_sum_item_bytes( $job['id'] ) );
		$second_page = yotm_job_get_items_page( $job['id'], 2, 10 );
		$this->assertSame( 12, $second_page['total'] );
		$this->assertSame( 2, $second_page['pages'] );
		$this->assertCount( 2, $second_page['items'] );

		$filtered = yotm_job_get_items_page( $job['id'], 1, 10, '100x100' );
		$this->assertSame( 1, $filtered['total'] );
		$this->assertSame( '/tmp/image-1-100x100.jpg', $filtered['items'][0]['path'] );
		$this->assertSame( 100, $filtered['items'][0]['estimated_bytes'] );
	}

	public function test_stopping_retains_audit_items_and_is_user_scoped_in_recent_jobs() {
		$job = yotm_job_create(
			'prune',
			array(),
			array(
				'status' => 'scanning',
				'phase'  => 'metadata',
			)
		);
		yotm_job_add_item( $job['id'], 'retained-item', array( 'path' => '/tmp/retained.jpg' ), 'queued', 42 );

		$stopped = yotm_job_cancel( $job );
		$this->assertSame( 'cancelled', $stopped['status'] );
		$this->assertSame( 'cancelled', $stopped['phase'] );
		$this->assertNotEmpty( $stopped['payload']['cancelled_at'] );
		$this->assertSame( 1, yotm_job_count_items( $job['id'] ) );
		$this->assertSame( 42, yotm_job_sum_item_bytes( $job['id'] ) );

		$recent = yotm_job_get_recent_for_current_user();
		$this->assertSame( $job['token'], $recent[0]['token'] );
		$this->assertSame( 'cancelled', $recent[0]['status'] );

		$other_admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $other_admin );
		$this->assertSame( array(), yotm_job_get_recent_for_current_user() );
	}

	public function test_review_and_delete_require_the_same_manifest() {
		$job = yotm_job_create(
			'prune',
			array(
				'ownership_schema'      => 'generated_file_v1',
				'source_index_complete' => 1,
			),
			array(
				'status' => 'scanning',
				'phase'  => 'metadata',
				'total'  => 1,
			)
		);
		yotm_job_add_item( $job['id'], 'candidate', array( 'path' => '/tmp/candidate.jpg' ) );
		yotm_job_update( $job['id'], array( 'phase' => 'manifest' ) );
		$manifest = yotm_job_build_manifest_batch( yotm_job_get_by_id( $job['id'] ), 10 );
		$job      = $manifest['job'];
		yotm_job_update(
			$job['id'],
			array(
				'status'     => 'awaiting_approval',
				'phase'      => 'review',
				'total'      => 1,
				'expires_at' => gmdate( 'Y-m-d H:i:s', time() + HOUR_IN_SECONDS ),
			)
		);
		$job = yotm_job_get_by_id( $job['id'] );

		$this->assertTrue( yotm_prune_validate_review_job( $job, $job['manifest_hash'], true ) );
		$this->assertWPError( yotm_prune_validate_review_job( $job, str_repeat( '0', 64 ), true ) );

		$mismatch = yotm_prune_approve_application( $job['token'], str_repeat( '0', 64 ), true );
		$this->assertFalse( $mismatch['success'] );
		$this->assertSame( 409, $mismatch['status'] );
		$this->assertSame( 'awaiting_approval', yotm_job_get_by_id( $job['id'] )['status'] );

		$approval = yotm_prune_approve_application( $job['token'], $job['manifest_hash'], true );
		$this->assertTrue( $approval['success'] );
		$this->assertSame( $job['manifest_hash'], $approval['data']['manifest_hash'] );
		$job = yotm_job_get_by_id( $job['id'] );
		$this->assertSame( 'approved', $job['status'] );
		$this->assertSame( 'delete', $job['phase'] );
		$this->assertTrue( yotm_prune_validate_delete_job( $job, $job['manifest_hash'] ) );
		$this->assertWPError( yotm_prune_validate_delete_job( $job, str_repeat( 'f', 64 ) ) );

		$delete_mismatch = yotm_prune_delete_application( $job['token'], str_repeat( 'f', 64 ), 1 );
		$this->assertFalse( $delete_mismatch['success'] );
		$this->assertSame( 409, $delete_mismatch['status'] );
		$this->assertSame( 'approved', yotm_job_get_by_id( $job['id'] )['status'] );
	}

	public function test_manifest_delete_can_resume_from_persistent_items() {
		$uploads             = wp_get_upload_dir();
		$directory           = trailingslashit( $uploads['basedir'] ) . 'yotm-resume-' . wp_generate_uuid4();
		$this->directories[] = $directory;
		$first               = trailingslashit( $directory ) . 'image-100x100.jpg';
		$second              = trailingslashit( $directory ) . 'image-200x200.jpg';
		$this->files[]       = $first;
		$this->files[]       = $second;
		wp_mkdir_p( $directory );
		file_put_contents( $first, 'first' );
		file_put_contents( $second, 'second' );

		$job = yotm_job_create(
			'prune',
			array(
				'base'                  => $uploads['basedir'],
				'ownership_schema'      => 'generated_file_v1',
				'source_index_complete' => 1,
			),
			array(
				'status' => 'scanning',
				'phase'  => 'metadata',
			)
		);
		yotm_job_add_item( $job['id'], hash( 'sha256', $first ), array( 'path' => $first ) );
		yotm_job_add_item( $job['id'], hash( 'sha256', $second ), array( 'path' => $second ) );
		yotm_job_update(
			$job['id'],
			array(
				'phase' => 'manifest',
				'total' => 2,
			)
		);
		$manifest = yotm_job_build_manifest_batch( yotm_job_get_by_id( $job['id'] ), 10 );
		$job      = $manifest['job'];
		yotm_job_update(
			$job['id'],
			array(
				'status'     => 'awaiting_approval',
				'phase'      => 'review',
				'expires_at' => gmdate( 'Y-m-d H:i:s', time() + HOUR_IN_SECONDS ),
			)
		);
		$job = yotm_job_get_by_id( $job['id'] );
		$this->assertTrue( yotm_prune_validate_review_job( $job, $job['manifest_hash'], true ) );

		yotm_job_update(
			$job['id'],
			array(
				'status' => 'deleting',
				'phase'  => 'delete',
			)
		);
		$item   = yotm_job_get_items( $job['id'], array( 'queued' ), 1 )[0];
		$result = yotm_delete_prune_item( $item['payload'], $uploads['basedir'] );
		$this->assertTrue( $result['deleted'] );
		yotm_job_update_item( $item['id'], 'done', '', $result['bytes'] );
		yotm_job_update(
			$job['id'],
			array(
				'processed' => 1,
				'succeeded' => 1,
			)
		);

		$resumed   = yotm_job_get( $job['token'] );
		$remaining = yotm_job_get_items( $resumed['id'], array( 'queued', 'processing' ), 10 );
		$this->assertSame( 'deleting', $resumed['status'] );
		$this->assertCount( 1, $remaining );

		$result = yotm_delete_prune_item( $remaining[0]['payload'], $uploads['basedir'] );
		$this->assertTrue( $result['deleted'] );
		yotm_job_update_item( $remaining[0]['id'], 'done', '', $result['bytes'] );
		yotm_job_update(
			$job['id'],
			array(
				'status'    => 'completed',
				'phase'     => 'completed',
				'processed' => 2,
				'succeeded' => 2,
			)
		);
		$this->assertSame( 'completed', yotm_job_get( $job['token'] )['status'] );
	}

	public function test_disk_orphan_scan_uses_a_persistent_bounded_cursor() {
		$uploads             = wp_get_upload_dir();
		$directory           = trailingslashit( $uploads['basedir'] ) . 'yotm-disk-cursor-' . wp_generate_uuid4();
		$second_directory    = trailingslashit( $uploads['basedir'] ) . 'yotm-disk-cursor-' . wp_generate_uuid4();
		$nested_directory    = trailingslashit( $second_directory ) . 'nested';
		$this->directories[] = $directory;
		$this->directories[] = $second_directory;
		$this->directories[] = $nested_directory;
		wp_mkdir_p( $directory );
		wp_mkdir_p( $second_directory );
		wp_mkdir_p( $nested_directory );

		for ( $index = 1; $index <= 5; ++$index ) {
			$file          = trailingslashit( $directory ) . 'image-' . $index . '-100x100.jpg';
			$this->files[] = $file;
			file_put_contents( $file, 'thumbnail' );
		}
		for ( $index = 1; $index <= 3; ++$index ) {
			$file          = trailingslashit( $second_directory ) . 'second-' . $index . '-200x200.jpg';
			$this->files[] = $file;
			file_put_contents( $file, 'thumbnail' );
		}
		$sidecar       = trailingslashit( $directory ) . 'side-100x100.jpg.webp';
		$backup        = trailingslashit( $directory ) . 'side-100x100.bak.jpg';
		$this->files[] = $sidecar;
		$this->files[] = $backup;
		file_put_contents( $sidecar, 'sidecar' );
		file_put_contents( $backup, 'backup' );
		$nested_file   = trailingslashit( $nested_directory ) . 'nested-300x300.jpg';
		$this->files[] = $nested_file;
		file_put_contents( $nested_file, 'thumbnail' );

		$job = yotm_job_create(
			'prune',
			array(
				'scan_base'              => trailingslashit( $directory ),
				'scan_bases'             => array( trailingslashit( $directory ), trailingslashit( $second_directory ) ),
				'disk_queue'             => array(
					array(
						'path'   => $directory,
						'root'   => $directory,
						'offset' => 0,
					),
					array(
						'path'   => $second_directory,
						'root'   => $second_directory,
						'offset' => 0,
					),
				),
				'disk_entries_processed' => 0,
				'disk_cursor_version'    => 'dfs_v2',
				'orphan_summary'         => yotm_initial_orphan_summary(),
			),
			array(
				'status' => 'scanning',
				'phase'  => 'disk',
			)
		);

		$batch = yotm_prune_scan_disk_batch( $job, 3 );
		$this->assertFalse( $batch['done'] );
		$this->assertSame( 3, $batch['job']['payload']['disk_entries_processed'] );

		$loops = 0;
		do {
			$batch = yotm_prune_scan_disk_batch( $batch['job'], 3 );
			++$loops;
		} while ( ! $batch['done'] && $loops < 10 );

		$this->assertTrue( $batch['done'] );
		$this->assertSame( 11, $batch['job']['payload']['orphan_summary']['total_files'] );
		$this->assertSame( 11, $batch['job']['payload']['orphan_summary']['unmapped_skipped'] );
		$this->assertSame( 1, $batch['job']['payload']['orphan_summary']['unverified_sidecars'] );
		$this->assertSame( 1, $batch['job']['payload']['orphan_summary']['ambiguous_siblings'] );
	}

	public function test_public_recommendation_result_projects_current_registered_sizes_without_persisting() {
		$legacy_result = array(
			'items'            => array(),
			'recommended_keep' => array( 'thumbnail' ),
		);
		add_image_size( 'tm_aud_public_projection', 333, 222, false );

		try {
			$job = yotm_job_create(
				'recommendation',
				array( 'result' => $legacy_result ),
				array(
					'status'    => 'completed',
					'phase'     => 'completed',
					'exclusive' => false,
				)
			);
			$this->assertIsArray( $job );

			$public = yotm_job_public_data( $job );
			$this->assertContains( 'tm_aud_public_projection', $public['context']['result']['recommended_keep'] );
			$this->assertSame( $legacy_result, yotm_job_get_by_id( $job['id'] )['payload']['result'] );
		} finally {
			remove_image_size( 'tm_aud_public_projection' );
		}
	}

	public function test_public_projection_is_recommendation_only_and_fails_closed_without_callable() {
		$this->assertNull( yotm_job_public_recommendation_result( array( 'recommended_keep' => array( 'thumbnail' ) ), 'yotm_missing_projector' ) );
		$projector = static function ( $value ) {
			return array( 'projected' => $value );
		};
		$this->assertSame(
			yotm_recommendation_public_result_application( 'persisted-result', $projector ),
			yotm_job_public_recommendation_result( 'persisted-result', $projector )
		);

		$result = array( 'recommended_keep' => array( 'legacy-only' ) );
		$job    = yotm_job_create(
			'regenerate',
			array( 'result' => $result ),
			array(
				'status' => 'completed',
				'phase'  => 'completed',
			)
		);
		$this->assertIsArray( $job );
		$this->assertSame( $result, yotm_job_public_data( $job )['context']['result'] );
	}

	public function test_completed_recommendation_public_data_projects_missing_and_scalar_results_safely() {
		add_image_size( 'tm_aud_malformed_public', 334, 223, false );

		try {
			foreach ( array( '__missing__', 'malformed-result' ) as $stored_result ) {
				$payload = array();
				if ( '__missing__' !== $stored_result ) {
					$payload['result'] = $stored_result;
				}

				$job = yotm_job_create(
					'recommendation',
					$payload,
					array(
						'status'    => 'completed',
						'phase'     => 'completed',
						'exclusive' => false,
					)
				);
				$this->assertIsArray( $job );

				$public  = yotm_job_public_data( $job );
				$current = array_keys( yotm_get_registered_sizes() );
				$keep    = $public['context']['result']['recommended_keep'];

				$this->assertSame( array(), array_values( array_diff( $current, $keep ) ) );
				$this->assertSame( $payload, yotm_job_get_by_id( $job['id'] )['payload'] );
			}
		} finally {
			remove_image_size( 'tm_aud_malformed_public' );
		}
	}

	private function clear_jobs() {
		global $wpdb;

		$tables = yotm_job_table_names();
		$wpdb->query( "DELETE FROM {$tables['items']}" );
		$wpdb->query( "DELETE FROM {$tables['jobs']}" );
	}

	public function fail_named_lock_query( $query ) {
		if ( preg_match( '/^SELECT GET_LOCK\(/i', ltrim( $query ) ) ) {
			return 'SELECT * FROM yotm_forced_missing_table';
		}

		return $query;
	}

	public function fail_worker_cas_query( $query ) {
		$jobs_table = yotm_job_table_names()['jobs'];
		$pattern    = '/^UPDATE\s+' . preg_quote( $jobs_table, '/' ) . '\s+SET worker_token\s*=\s*/i';

		if ( preg_match( $pattern, ltrim( $query ) ) ) {
			return 'UPDATE yotm_forced_missing_table SET worker_token = \'failed\'';
		}

		return $query;
	}

	public function fail_historical_evidence_read( $query ) {
		$is_historical = false !== strpos( $query, 'historical_cohort' );
		$is_manifest   = false !== strpos( $query, 'SELECT item_key,payload' ) && false !== strpos( $query, "status = 'queued'" );
		return ( $is_historical || $is_manifest ) && 0 === stripos( ltrim( $query ), 'SELECT' )
			? 'SELECT * FROM yotm_missing_historical_evidence_table'
			: $query;
	}

	public function fail_manifest_page_read( $query ) {
		$is_count = 'count' === $this->manifest_page_failure_stage && false !== strpos( $query, 'SELECT COUNT(*)' ) && false === strpos( $query, 'status IN' );
		$is_list  = 'list' === $this->manifest_page_failure_stage && false !== strpos( $query, 'SELECT *' ) && false !== strpos( $query, 'ORDER BY id ASC LIMIT' );
		return ( $is_count || $is_list )
			? 'SELECT * FROM yotm_missing_manifest_page_table'
			: $query;
	}

	public function force_named_lock_contention( $query ) {
		return preg_match( '/^SELECT GET_LOCK\(/i', ltrim( $query ) ) ? 'SELECT 0' : $query;
	}
}
