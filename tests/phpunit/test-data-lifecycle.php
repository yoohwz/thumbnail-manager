<?php
/**
 * Deactivation and uninstall lifecycle tests.
 */
class YOTM_Data_Lifecycle_Test extends WP_UnitTestCase {
	/** @var int */
	private $primary_blog_id = 0;

	/** @var int[] */
	private $created_blogs = array();

	/** @var int */
	private $attachment_id = 0;

	/** @var string */
	private $test_dir = '';

	/** @var string */
	private $failed_drop_table = '';

	/** @var string */
	private $failed_inspection_table = '';

	/** @var string */
	private $failed_option_read = '';

	/** @var string */
	private $failed_option_write = '';

	/** @var string */
	private $failed_option_delete = '';

	/** @var int */
	private $named_lock_queries = 0;

	/** @var string[] */
	private $shutdown_queries = array();

	public function setUp(): void {
		parent::setUp();
		$this->primary_blog_id = get_current_blog_id();
		$this->restore_current_site_storage();
		$this->clear_current_site_storage();
		$this->seed_owned_options();
	}

	public function tearDown(): void {
		remove_filter( 'query', array( $this, 'fail_selected_drop' ), 1 );
		remove_filter( 'query', array( $this, 'fail_selected_inspection' ), 1 );
		remove_filter( 'query', array( $this, 'fail_selected_option_read' ), 1 );
		remove_filter( 'query', array( $this, 'fail_selected_option_write' ), 1 );
		remove_filter( 'query', array( $this, 'fail_selected_option_delete' ), 1 );
		remove_filter( 'query', array( $this, 'force_lifecycle_lock_contention' ), 1 );
		remove_filter( 'query', array( $this, 'count_named_lock_queries' ), 1 );
		remove_filter( 'query', array( $this, 'record_shutdown_queries' ), 1 );
		remove_filter( 'pre_option_yotm_job_db_version', array( $this, 'virtual_current_schema' ) );
		remove_filter( 'pre_option_' . YOTM_UNINSTALL_INTENT_OPTION, array( $this, 'hide_cleanup_intent' ) );
		yotm_data_lifecycle_release_request_fences();
		while ( is_multisite() && ms_is_switched() ) {
			restore_current_blog();
		}
		foreach ( array_reverse( $this->created_blogs ) as $blog_id ) {
			switch_to_blog( $blog_id );
			$this->drop_current_site_storage();
			$this->clear_owned_options();
			restore_current_blog();
			wp_delete_site( $blog_id );
		}
		$this->created_blogs = array();

		if ( get_current_blog_id() !== $this->primary_blog_id ) {
			switch_to_blog( $this->primary_blog_id );
		}
		$this->restore_current_site_storage();
		$this->clear_current_site_storage();
		$this->clear_owned_options();
		$this->drop_sentinel_table();
		delete_option( 'yotm_neighbor_option' );

		if ( $this->attachment_id ) {
			wp_delete_attachment( $this->attachment_id, true );
			$this->attachment_id = 0;
		}
		if ( $this->test_dir && is_dir( $this->test_dir ) ) {
			$this->remove_test_tree( $this->test_dir );
		}
		$this->test_dir = '';
		unset( $GLOBALS['yotm_job_storage_readiness'] );
		unset( $GLOBALS['yotm_job_logical_locks'] );
		yotm_data_lifecycle_release_request_fences();
		parent::tearDown();

		// DDL commits break the Core test transaction, so normalize shared state after its rollback.
		$this->clear_current_site_storage();
		$this->clear_owned_options();
		update_option( 'yotm_job_db_version', YOTM_JOB_DB_VERSION, false );
		unset( $GLOBALS['yotm_job_storage_readiness'] );
		unset( $GLOBALS['yotm_job_logical_locks'] );
		yotm_data_lifecycle_release_request_fences();
	}

	public function test_single_site_deactivation_clears_cron_and_retains_data() {
		$job = $this->create_job( 'completed' );
		$this->assertIsArray( $job );
		yotm_schedule_job_cleanup();
		$this->assertNotFalse( wp_next_scheduled( 'yotm_cleanup_jobs' ) );

		$this->assertTrue( yotm_deactivate_job_cleanup( false ) );
		$this->assertFalse( wp_next_scheduled( 'yotm_cleanup_jobs' ) );
		$this->assertTrue( yotm_job_tables_exist() );
		$this->assertSame( YOTM_JOB_DB_VERSION, get_option( 'yotm_job_db_version' ) );
		$this->assertSame( array( 'thumbnail' ), get_option( 'yotm_disabled_sizes' ) );
		$this->assertSame( array( 'fixture' => true ), get_transient( 'yotm_job_db_migration_failure' ) );
		$this->assertIsArray( yotm_job_get_by_id( $job['id'] ) );
	}

	/**
	 * @dataProvider active_job_status_provider
	 */
	public function test_active_job_without_journal_retains_everything( $status ) {
		$this->create_job( $status );

		$result = yotm_data_lifecycle_uninstall();

		$this->assertRetained( $result, 'yotm_uninstall_active_job' );
	}

	public function active_job_status_provider() {
		return array(
			'scanning'          => array( 'scanning' ),
			'running'           => array( 'running' ),
			'awaiting approval' => array( 'awaiting_approval' ),
			'approved'          => array( 'approved' ),
			'deleting'          => array( 'deleting' ),
		);
	}

	public function test_processing_item_without_journal_retains_everything() {
		$job = $this->create_job_with_item( array( 'attachment_id' => 123 ), 'processing', 'completed' );
		$this->assertIsArray( $job );

		$result = yotm_data_lifecycle_uninstall();

		$this->assertRetained( $result, 'yotm_uninstall_processing_item' );
	}

	/**
	 * @dataProvider unresolved_journal_provider
	 */
	public function test_unresolved_journal_in_any_non_done_status_retains_everything( $kind, $status ) {
		$journal = 'prune' === $kind ? $this->resolved_prune_journal() : $this->resolved_force_journal();
		$key     = 'prune' === $kind ? 'prune_operation_journal_v1' : 'regeneration_journal';
		$this->create_job_with_item( array( $key => $journal ), $status, 'completed' );

		$result = yotm_data_lifecycle_uninstall();

		$reason = 'prune' === $kind ? 'yotm_uninstall_prune_recovery' : 'yotm_uninstall_force_recovery';
		$this->assertRetained( $result, 'processing' === $status ? 'yotm_uninstall_processing_item' : $reason );
	}

	public function unresolved_journal_provider() {
		$cases = array();
		foreach ( array( 'prune', 'force' ) as $kind ) {
			foreach ( array( 'queued', 'processing', 'failed', 'skipped' ) as $status ) {
				$cases[ $kind . ' ' . $status ] = array( $kind, $status );
			}
		}

		return $cases;
	}

	public function test_terminal_failed_force_manual_inspection_journal_retains_everything() {
		$journal          = $this->resolved_force_journal();
		$journal['phase'] = 'metadata_committed';
		$this->create_job_with_item( array( 'regeneration_journal' => $journal ), 'failed', 'completed' );

		$result = yotm_data_lifecycle_uninstall();

		$this->assertRetained( $result, 'yotm_uninstall_force_recovery' );
	}

	public function test_exact_done_journals_allow_exact_purge_and_preserve_media_and_sentinels() {
		$job = $this->create_job_with_item(
			array( 'prune_operation_journal_v1' => $this->resolved_prune_journal() ),
			'done',
			'completed'
		);
		$this->add_item( $job['id'], array( 'regeneration_journal' => $this->resolved_force_journal() ), 'done', 'force-resolved' );
		$this->create_no_media_sentinels();

		$result = yotm_data_lifecycle_uninstall();

		$this->assertSame(
			array(
				'status' => 'purged',
				'reason' => '',
			),
			$result
		);
		$this->assertFalse( yotm_job_tables_exist() );
		foreach ( array( 'yotm_disabled_sizes', 'yotm_job_db_version', 'yotm_media_source_index_dirty', 'yotm_media_reference_index_state' ) as $option ) {
			$this->assertNull( get_option( $option, null ) );
		}
		$this->assertFalse( get_transient( 'yotm_job_db_migration_failure' ) );
		$this->assertFalse( wp_next_scheduled( 'yotm_cleanup_jobs' ) );
		$this->assertNull( get_option( YOTM_UNINSTALL_INTENT_OPTION, null ) );
		$this->assertSame( 'preserve', get_option( 'yotm_neighbor_option' ) );
		$this->assertTrue( $this->sentinel_table_exists() );
		$this->assertSame( 'keep-meta', get_post_meta( $this->attachment_id, '_yotm_lifecycle_sentinel', true ) );
		$this->assertFileExists( $this->test_dir . '/ordinary-upload.bin' );
		$this->assertFileExists( $this->test_dir . '/.yotm-regenerate-test/staged.bin' );
	}

	public function test_malformed_item_payload_retains_everything() {
		$job = $this->create_job_with_item( array( 'proof' => 'valid-first' ), 'done', 'completed' );
		global $wpdb;
		$tables = yotm_job_table_names();
		$wpdb->update( $tables['items'], array( 'payload' => '{' ), array( 'job_id' => $job['id'] ) );

		$result = yotm_data_lifecycle_uninstall();

		$this->assertRetained( $result, 'yotm_uninstall_item_payload' );
	}

	public function test_escaped_journal_key_cannot_bypass_classification() {
		$job = $this->create_job_with_item( array( 'proof' => true ), 'done', 'completed' );
		global $wpdb;
		$table = yotm_job_table_names()['items'];
		$raw   = '{"prune_operation_journal_v\\u0031":{"version":1,"outcome":"delete_reconciled"}}';
		$wpdb->update( $table, array( 'payload' => $raw ), array( 'job_id' => $job['id'] ) );

		$result = yotm_data_lifecycle_uninstall();

		$this->assertRetained( $result, 'yotm_uninstall_prune_recovery' );
	}

	public function test_nested_escaped_journal_key_cannot_bypass_classification() {
		$job = $this->create_job_with_item( array( 'proof' => true ), 'done', 'completed' );
		global $wpdb;
		$table   = yotm_job_table_names()['items'];
		$raw     = '{"nested":{"prune_operation_journal_v\\u0031":{"version":1,"outcome":"delete_reconciled"}}}';
		$updated = $wpdb->update( $table, array( 'payload' => $raw ), array( 'job_id' => $job['id'] ) );
		$this->assertNotFalse( $updated );

		$result = yotm_data_lifecycle_uninstall();

		$this->assertRetained( $result, 'yotm_uninstall_prune_recovery' );
	}

	/**
	 * @dataProvider duplicate_journal_key_provider
	 */
	public function test_duplicate_json_keys_retain_everything( $second_key ) {
		$job = $this->create_job_with_item( array( 'proof' => true ), 'done', 'completed' );
		global $wpdb;
		$table   = yotm_job_table_names()['items'];
		$valid   = wp_json_encode( $this->resolved_prune_journal() );
		$raw     = '{"prune_operation_journal_v1":{"version":999},"' . $second_key . '":' . $valid . '}';
		$updated = $wpdb->update( $table, array( 'payload' => $raw ), array( 'job_id' => $job['id'] ) );
		$this->assertNotFalse( $updated );

		$result = yotm_data_lifecycle_uninstall();

		$this->assertRetained( $result, 'yotm_uninstall_json_duplicate_key' );
	}

	public function duplicate_journal_key_provider() {
		return array(
			'literal duplicate'            => array( 'prune_operation_journal_v1' ),
			'escaped-equivalent duplicate' => array( 'prune_operation_journal_v\\u0031' ),
		);
	}

	public function test_duplicate_structural_key_inside_resolved_journal_retains_everything() {
		$job = $this->create_job_with_item( array( 'proof' => true ), 'done', 'completed' );
		global $wpdb;
		$table   = yotm_job_table_names()['items'];
		$journal = $this->resolved_prune_journal();
		unset( $journal['version'] );
		$raw     = '{"prune_operation_journal_v1":{"version":999,"version":1,' . substr( wp_json_encode( $journal ), 1 ) . '}';
		$updated = $wpdb->update( $table, array( 'payload' => $raw ), array( 'job_id' => $job['id'] ) );
		$this->assertNotFalse( $updated );

		$result = yotm_data_lifecycle_uninstall();

		$this->assertRetained( $result, 'yotm_uninstall_json_duplicate_key' );
	}

	public function test_contradictory_done_journals_retain_everything() {
		$this->create_job_with_item(
			array(
				'prune_operation_journal_v1' => $this->resolved_prune_journal(),
				'regeneration_journal'       => $this->resolved_force_journal(),
			),
			'done',
			'completed'
		);

		$result = yotm_data_lifecycle_uninstall();

		$this->assertRetained( $result, 'yotm_uninstall_journal_conflict' );
	}

	/**
	 * @dataProvider malformed_done_journal_provider
	 */
	public function test_malformed_done_journal_retains_everything( $kind, $mutation ) {
		$journal = 'prune' === $kind ? $this->resolved_prune_journal() : $this->resolved_force_journal();
		$key     = 'prune' === $kind ? 'prune_operation_journal_v1' : 'regeneration_journal';
		if ( 'version' === $mutation ) {
			$journal['version'] = 999;
		} elseif ( 'hash' === $mutation && 'prune' === $kind ) {
			$journal['file_hash'] = 'invalid';
		} elseif ( 'hash' === $mutation ) {
			$journal['new_metadata_hash'] = hash( 'sha256', 'not-the-final-metadata' );
		} elseif ( 'phase' === $mutation ) {
			$journal['phase'] = 'metadata_committed';
		}
		$this->create_job_with_item( array( $key => $journal ), 'done', 'completed' );

		$result = yotm_data_lifecycle_uninstall();

		$this->assertRetained( $result, 'prune' === $kind ? 'yotm_uninstall_prune_recovery' : 'yotm_uninstall_force_recovery' );
	}

	public function malformed_done_journal_provider() {
		return array(
			'prune unknown version' => array( 'prune', 'version' ),
			'prune invalid hash'    => array( 'prune', 'hash' ),
			'force unknown version' => array( 'force', 'version' ),
			'force invalid hash'    => array( 'force', 'hash' ),
			'force incomplete'      => array( 'force', 'phase' ),
		);
	}

	public function test_safety_drift_before_commit_recheck_causes_zero_deletion() {
		$result = yotm_data_lifecycle_uninstall(
			array(
				'before_commit_recheck' => function () {
					$this->create_job( 'running' );
				},
			)
		);

		$this->assertRetained( $result, 'yotm_uninstall_active_job' );
		$this->assertNull( get_option( YOTM_UNINSTALL_INTENT_OPTION, null ) );
	}

	public function test_late_active_job_after_intents_is_caught_by_final_proof_and_rolled_back() {
		$job = $this->create_job( 'completed' );
		$this->assertIsArray( $job );
		$result = yotm_data_lifecycle_uninstall(
			array(
				'after_intents' => static function () use ( $job ) {
					global $wpdb;
					$wpdb->update( yotm_job_table_names()['jobs'], array( 'status' => 'running' ), array( 'id' => $job['id'] ) );
				},
			)
		);

		$this->assertRetained( $result, 'yotm_uninstall_active_job' );
	}

	public function test_late_processing_item_after_intents_is_caught_by_final_proof() {
		$job    = $this->create_job_with_item( array( 'proof' => true ), 'done', 'completed' );
		$result = yotm_data_lifecycle_uninstall(
			array(
				'after_intents' => static function () use ( $job ) {
					global $wpdb;
					$wpdb->update( yotm_job_table_names()['items'], array( 'status' => 'processing' ), array( 'job_id' => $job['id'] ) );
				},
			)
		);

		$this->assertRetained( $result, 'yotm_uninstall_processing_item' );
	}

	public function test_late_recovery_journal_after_intents_is_caught_by_final_proof() {
		$job    = $this->create_job_with_item( array( 'proof' => true ), 'done', 'completed' );
		$result = yotm_data_lifecycle_uninstall(
			array(
				'after_intents' => static function () use ( $job ) {
					global $wpdb;
					$wpdb->update(
						yotm_job_table_names()['items'],
						array( 'payload' => wp_json_encode( array( 'prune_operation_journal_v1' => array( 'version' => 1 ) ) ) ),
						array( 'job_id' => $job['id'] )
					);
				},
			)
		);

		$this->assertRetained( $result, 'yotm_uninstall_prune_recovery' );
	}

	public function test_lost_scope_fence_after_intents_keeps_durable_recovery_blocker() {
		$this->create_job( 'completed' );
		$result = yotm_data_lifecycle_uninstall(
			array(
				'after_intents' => static function () {
					$name = yotm_data_lifecycle_lock_name( 'site', get_current_blog_id() );
					yotm_data_lifecycle_release_named_lock( $name );
				},
			)
		);

		$this->assertSame( 'partial', $result['status'] );
		$this->assertSame( 'yotm_uninstall_fence_lost', $result['reason'] );
		$this->assertIsArray( get_option( YOTM_UNINSTALL_INTENT_OPTION, null ) );
		$this->assertTrue( yotm_job_tables_exist() );
	}

	public function test_persisted_cleanup_intent_blocks_new_runtime_job() {
		yotm_data_lifecycle_release_request_fences();
		$intent = yotm_data_lifecycle_intent(
			get_current_blog_id(),
			yotm_data_lifecycle_scope_hash( array( get_current_blog_id() ) ),
			array_values( yotm_job_table_names() )
		);
		$this->assertTrue( yotm_data_lifecycle_write_option( YOTM_UNINSTALL_INTENT_OPTION, $intent ) );
		unset( $GLOBALS['yotm_job_storage_readiness'] );

		$job = $this->create_job( 'running' );

		$this->assertWPError( $job );
		$this->assertSame( 'yotm_uninstall_in_progress', $job->get_error_code() );
		$this->assertTrue( yotm_job_tables_exist() );
	}

	public function test_inflight_request_fence_blocks_uninstall_without_writes() {
		yotm_data_lifecycle_release_request_fences();
		$other = new wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
		$this->assertTrue( $other->ready );
		$name = yotm_data_lifecycle_lock_name( 'site', get_current_blog_id() );
		$this->assertSame( '1', (string) $other->get_var( $other->prepare( 'SELECT GET_LOCK(%s, 0)', $name ) ) );

		try {
			$result = yotm_data_lifecycle_uninstall();

			$this->assertRetained( $result, 'yotm_uninstall_fence_busy' );
		} finally {
			$other->get_var( $other->prepare( 'SELECT RELEASE_LOCK(%s)', $name ) );
			$other->close();
		}
	}

	public function test_inflight_uninstall_fence_blocks_new_runtime_job() {
		yotm_data_lifecycle_release_request_fences();
		$other = new wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
		$this->assertTrue( $other->ready );
		$name = yotm_data_lifecycle_lock_name( 'site', get_current_blog_id() );
		$this->assertSame( '1', (string) $other->get_var( $other->prepare( 'SELECT GET_LOCK(%s, 0)', $name ) ) );

		try {
			$job = $this->create_job( 'running' );

			$this->assertWPError( $job );
			$this->assertSame( 'yotm_uninstall_fence_busy', $job->get_error_code() );
		} finally {
			$other->get_var( $other->prepare( 'SELECT RELEASE_LOCK(%s)', $name ) );
			$other->close();
		}
	}

	public function test_stale_request_handle_after_lost_connection_cannot_authorize_mutation() {
		yotm_data_lifecycle_release_request_fences();
		$this->assertTrue( yotm_data_lifecycle_require_runtime_fence() );
		$name = yotm_data_lifecycle_lock_name( 'site', get_current_blog_id() );
		$this->assertTrue( yotm_data_lifecycle_release_named_lock( $name ) );

		$other = new wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
		$this->assertTrue( $other->ready );
		$this->assertSame( '1', (string) $other->get_var( $other->prepare( 'SELECT GET_LOCK(%s, 0)', $name ) ) );

		try {
			$job = $this->create_job( 'running' );

			$this->assertWPError( $job );
			$this->assertSame( 'yotm_uninstall_fence_lost', $job->get_error_code() );
		} finally {
			$other->get_var( $other->prepare( 'SELECT RELEASE_LOCK(%s)', $name ) );
			$other->close();
		}
	}

	public function test_logical_locks_reuse_one_physical_lifecycle_fence() {
		yotm_data_lifecycle_release_request_fences();
		$this->assertTrue( yotm_data_lifecycle_require_runtime_fence() );
		$name   = yotm_data_lifecycle_lock_name( 'site', get_current_blog_id() );
		$before = yotm_data_lifecycle_named_lock_state( $name );
		$this->assertTrue( $before['owned'] );

		$this->named_lock_queries = 0;
		add_filter( 'query', array( $this, 'count_named_lock_queries' ), 1 );
		$logical = yotm_job_acquire_named_lock( 'yotm_test_distinct_logical_name' );
		remove_filter( 'query', array( $this, 'count_named_lock_queries' ), 1 );

		$this->assertTrue( $logical );
		$this->assertSame( 0, $this->named_lock_queries );
		$after = yotm_data_lifecycle_named_lock_state( $name );
		$this->assertTrue( $after['owned'] );
		$this->assertSame( $before['connection_id'], $after['connection_id'] );
		$this->assertTrue( yotm_job_release_named_lock( 'yotm_test_distinct_logical_name' ) );
	}

	public function test_shutdown_drains_worker_mutation_before_lifecycle_release() {
		$job    = $this->create_job( 'running' );
		$worker = yotm_job_acquire_worker( $job['id'], array( 'running' ), array( 'running' ) );
		$this->assertIsArray( $worker );

		$this->shutdown_queries = array();
		add_filter( 'query', array( $this, 'record_shutdown_queries' ), 1 );
		yotm_data_lifecycle_shutdown();
		remove_filter( 'query', array( $this, 'record_shutdown_queries' ), 1 );

		$this->assertSame( array( 'worker_release', 'lifecycle_release' ), $this->shutdown_queries );
		$stored = yotm_job_get_by_id( $job['id'] );
		$this->assertSame( '', $stored['worker_token'] );
		$this->assertEmpty( $GLOBALS['yotm_data_lifecycle_request_fences'] );
	}

	public function test_item_limit_retains_everything() {
		$job = $this->create_job_with_item( array( 'one' => true ), 'done', 'completed' );
		$this->add_item( $job['id'], array( 'two' => true ), 'done', 'second' );

		$result = yotm_data_lifecycle_uninstall( array( 'limits' => array( 'max_items' => 1 ) ) );

		$this->assertRetained( $result, 'yotm_uninstall_item_limit' );
	}

	public function test_time_limit_before_commit_retains_everything() {
		$result = yotm_data_lifecycle_uninstall(
			array(
				'limits'                => array( 'max_seconds' => 0.001 ),
				'before_commit_recheck' => static function () {
					usleep( 5000 );
				},
			)
		);

		$this->assertRetained( $result, 'yotm_uninstall_time_limit' );
	}

	public function test_database_inspection_failure_retains_everything() {
		$this->failed_inspection_table = yotm_job_table_names()['jobs'];
		add_filter( 'query', array( $this, 'fail_selected_inspection' ), 1 );

		$result = yotm_data_lifecycle_uninstall();

		remove_filter( 'query', array( $this, 'fail_selected_inspection' ), 1 );
		$this->assertRetained( $result, 'yotm_uninstall_database_read' );
	}

	public function test_raw_option_read_failure_retains_everything() {
		$this->failed_option_read = 'yotm_job_db_version';
		add_filter( 'query', array( $this, 'fail_selected_option_read' ), 1 );

		$result = yotm_data_lifecycle_uninstall();

		remove_filter( 'query', array( $this, 'fail_selected_option_read' ), 1 );
		$this->assertRetained( $result, 'yotm_uninstall_database_read' );
	}

	public function test_option_filter_cannot_virtualize_wrong_schema_marker() {
		update_option( 'yotm_job_db_version', '0.0.0', false );
		add_filter( 'pre_option_yotm_job_db_version', array( $this, 'virtual_current_schema' ) );

		$result = yotm_data_lifecycle_uninstall();

		remove_filter( 'pre_option_yotm_job_db_version', array( $this, 'virtual_current_schema' ) );
		$this->assertSame( 'retained', $result['status'] );
		$this->assertSame( 'yotm_uninstall_schema_ambiguous', $result['reason'] );
		$this->assertTrue( yotm_job_tables_exist() );
	}

	public function test_option_filter_cannot_hide_persisted_cleanup_intent() {
		update_option( YOTM_UNINSTALL_INTENT_OPTION, array( 'version' => 999 ), false );
		add_filter( 'pre_option_' . YOTM_UNINSTALL_INTENT_OPTION, array( $this, 'hide_cleanup_intent' ) );

		$result = yotm_data_lifecycle_uninstall();

		remove_filter( 'pre_option_' . YOTM_UNINSTALL_INTENT_OPTION, array( $this, 'hide_cleanup_intent' ) );
		$this->assertSame( 'retained', $result['status'] );
		$this->assertSame( 'yotm_uninstall_intent_invalid', $result['reason'] );
		$this->assertTrue( yotm_job_tables_exist() );
	}

	public function test_intent_write_failure_is_rolled_back_before_deletion() {
		$this->failed_option_write = YOTM_UNINSTALL_INTENT_OPTION;
		add_filter( 'query', array( $this, 'fail_selected_option_write' ), 1 );

		$result = yotm_data_lifecycle_uninstall();

		remove_filter( 'query', array( $this, 'fail_selected_option_write' ), 1 );
		$this->assertRetained( $result, 'yotm_uninstall_intent_write' );
	}

	public function test_option_delete_failure_keeps_cleanup_intent_for_retry() {
		$this->failed_option_delete = 'yotm_disabled_sizes';
		add_filter( 'query', array( $this, 'fail_selected_option_delete' ), 1 );

		$result = yotm_data_lifecycle_uninstall();

		remove_filter( 'query', array( $this, 'fail_selected_option_delete' ), 1 );
		$this->assertSame( 'partial', $result['status'] );
		$this->assertSame( 'yotm_uninstall_option_cleanup', $result['reason'] );
		$this->assertIsArray( get_option( YOTM_UNINSTALL_INTENT_OPTION, null ) );
		$this->assertTrue( yotm_job_tables_exist() );
	}

	public function test_interrupted_allowlisted_drop_keeps_intent_and_retries_idempotently() {
		$this->create_job( 'completed' );
		$this->failed_drop_table = yotm_job_table_names()['items'];
		add_filter( 'query', array( $this, 'fail_selected_drop' ), 1 );

		$first = yotm_data_lifecycle_uninstall();

		remove_filter( 'query', array( $this, 'fail_selected_drop' ), 1 );
		$this->assertSame( 'partial', $first['status'] );
		$this->assertSame( 'yotm_uninstall_table_drop', $first['reason'] );
		$this->assertIsArray( get_option( YOTM_UNINSTALL_INTENT_OPTION, null ) );
		$this->assertFalse( yotm_data_lifecycle_table_absent( yotm_job_table_names()['items'] ) );

		$second = yotm_data_lifecycle_uninstall();

		$this->assertSame(
			array(
				'status' => 'purged',
				'reason' => '',
			),
			$second
		);
		$this->assertNull( get_option( YOTM_UNINSTALL_INTENT_OPTION, null ) );
		$this->assertFalse( yotm_job_tables_exist() );
	}

	public function test_unexplained_partial_schema_retains_everything() {
		global $wpdb;
		$table = yotm_job_table_names()['sources'];
		$wpdb->query( "DROP TABLE `{$table}`" );

		$result = yotm_data_lifecycle_uninstall();

		$this->assertSame( 'retained', $result['status'] );
		$this->assertSame( 'yotm_uninstall_schema_ambiguous', $result['reason'] );
		$this->assertTrue( yotm_data_lifecycle_inspect_table( yotm_job_table_names()['jobs'] )['present'] );
		$this->assertTrue( yotm_data_lifecycle_inspect_table( yotm_job_table_names()['items'] )['present'] );
		$this->assertFalse( yotm_data_lifecycle_inspect_table( $table )['present'] );
		$this->assertSame( YOTM_JOB_DB_VERSION, get_option( 'yotm_job_db_version' ) );
		$this->assertSame( array( 'thumbnail' ), get_option( 'yotm_disabled_sizes' ) );
		$this->assertNull( get_option( YOTM_UNINSTALL_INTENT_OPTION, null ) );
	}

	/**
	 * @dataProvider invalid_schema_marker_provider
	 */
	public function test_invalid_schema_marker_retains_everything( $version ) {
		if ( null === $version ) {
			delete_option( 'yotm_job_db_version' );
		} else {
			update_option( 'yotm_job_db_version', $version, false );
		}

		$result = yotm_data_lifecycle_uninstall();

		$this->assertSame( 'retained', $result['status'] );
		$this->assertSame( 'yotm_uninstall_schema_ambiguous', $result['reason'] );
		$this->assertTrue( yotm_job_tables_exist() );
		$this->assertSame( array( 'thumbnail' ), get_option( 'yotm_disabled_sizes' ) );
	}

	public function invalid_schema_marker_provider() {
		return array(
			'missing' => array( null ),
			'wrong'   => array( '0.0.0' ),
		);
	}

	public function test_invalid_cleanup_intent_retains_everything() {
		update_option( YOTM_UNINSTALL_INTENT_OPTION, array( 'version' => 999 ), false );

		$result = yotm_data_lifecycle_uninstall();

		$this->assertSame( 'retained', $result['status'] );
		$this->assertSame( 'yotm_uninstall_intent_invalid', $result['reason'] );
		$this->assertTrue( yotm_job_tables_exist() );
		$this->assertSame( array( 'version' => 999 ), get_option( YOTM_UNINSTALL_INTENT_OPTION ) );
	}

	public function test_interrupted_remaining_table_still_requires_schema_fingerprint() {
		global $wpdb;
		$tables = yotm_job_table_names();
		$intent = yotm_data_lifecycle_intent(
			get_current_blog_id(),
			yotm_data_lifecycle_scope_hash( array( get_current_blog_id() ) ),
			array_values( $tables )
		);
		update_option( YOTM_UNINSTALL_INTENT_OPTION, $intent, false );
		$wpdb->query( "ALTER TABLE `{$tables['sources']}` DROP COLUMN `path`" );

		$result = yotm_data_lifecycle_uninstall();

		$this->assertSame( 'retained', $result['status'] );
		$this->assertSame( 'yotm_uninstall_schema_fingerprint', $result['reason'] );
		$this->assertTrue( yotm_data_lifecycle_inspect_table( $tables['sources'] )['present'] );
		$this->assertSame( $intent, get_option( YOTM_UNINSTALL_INTENT_OPTION ) );
	}

	public function test_successful_purge_is_idempotent_and_reactivation_creates_fresh_storage() {
		$this->create_job( 'completed' );

		$purged = array(
			'status' => 'purged',
			'reason' => '',
		);
		$this->assertSame( $purged, yotm_data_lifecycle_uninstall() );
		$this->assertSame( $purged, yotm_data_lifecycle_uninstall() );
		$this->assertFalse( yotm_job_tables_exist() );
		unset( $GLOBALS['yotm_job_storage_readiness'] );
		$this->assertTrue( yotm_job_storage_ready() );
		$this->assertTrue( yotm_job_tables_exist() );
		$this->assertSame( YOTM_JOB_DB_VERSION, get_option( 'yotm_job_db_version' ) );
	}

	public function test_uninstall_entrypoint_is_guarded_and_does_not_bootstrap_plugin() {
		$uninstall = file_get_contents( dirname( __DIR__, 2 ) . '/uninstall.php' );
		$this->assertIsString( $uninstall );
		$this->assertStringContainsString( "defined( 'WP_UNINSTALL_PLUGIN' )", $uninstall );
		$this->assertStringContainsString( "'/inc/data-lifecycle.php'", $uninstall );
		$this->assertStringNotContainsString( 'thumbnail-manager.php', $uninstall );
		$this->assertStringNotContainsString( 'wp_die', $uninstall );
	}

	public function test_multisite_blocker_on_last_site_keeps_every_site_untouched() {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Requires WordPress Multisite.' );
		}
		$primary_tables = yotm_job_table_names();
		$second_blog_id = $this->create_second_site();
		switch_to_blog( $second_blog_id );
		$this->create_job( 'running' );
		$second_tables = yotm_job_table_names();
		restore_current_blog();

		$result = yotm_data_lifecycle_uninstall();

		$this->assertSame( 'retained', $result['status'] );
		$this->assertTrue( $this->table_exists( $primary_tables['jobs'] ) );
		$this->assertTrue( $this->table_exists( $second_tables['jobs'] ) );
		$this->assertSame( YOTM_JOB_DB_VERSION, get_option( 'yotm_job_db_version' ) );
	}

	public function test_multisite_insert_fails_before_row_creation_when_topology_fence_is_contended() {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Requires WordPress Multisite.' );
		}
		yotm_data_lifecycle_release_request_fences();
		$domain = 'blocked-' . wp_generate_password( 8, false, false ) . '.example.org';
		add_filter( 'query', array( $this, 'force_lifecycle_lock_contention' ), 1 );
		$result = wp_insert_site(
			array(
				'domain'     => $domain,
				'path'       => '/',
				'network_id' => get_current_network_id(),
			)
		);
		remove_filter( 'query', array( $this, 'force_lifecycle_lock_contention' ), 1 );

		$this->assertWPError( $result );
		$this->assertSame( 'yotm_uninstall_topology_busy', $result->get_error_code() );
		$this->assertSame( array(), get_sites( array( 'domain' => $domain ) ) );
	}

	public function test_multisite_delete_fails_before_uninitialization_when_topology_fence_is_contended() {
		global $wpdb;

		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Requires WordPress Multisite.' );
		}
		$blog_id = $this->create_second_site();
		yotm_data_lifecycle_release_request_fences();
		add_filter( 'query', array( $this, 'force_lifecycle_lock_contention' ), 1 );
		$result = wp_delete_site( $blog_id );
		remove_filter( 'query', array( $this, 'force_lifecycle_lock_contention' ), 1 );

		$this->assertWPError( $result );
		$this->assertSame( 'yotm_uninstall_topology_busy', $result->get_error_code() );
		$this->assertInstanceOf( WP_Site::class, get_site( $blog_id ) );
		$this->assertTrue( $this->table_exists( $wpdb->get_blog_prefix( $blog_id ) . 'options' ) );
	}

	public function test_multisite_site_cap_retains_every_site() {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Requires WordPress Multisite.' );
		}
		$this->create_second_site();

		$result = yotm_data_lifecycle_uninstall( array( 'limits' => array( 'max_sites' => 1 ) ) );

		$this->assertRetained( $result, 'yotm_uninstall_site_limit' );
	}

	public function test_multisite_network_deactivation_clears_all_cron_and_retains_storage() {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Requires WordPress Multisite.' );
		}
		$second_blog_id = $this->create_second_site();
		yotm_schedule_job_cleanup();
		switch_to_blog( $second_blog_id );
		yotm_schedule_job_cleanup();
		restore_current_blog();

		$this->assertTrue( yotm_deactivate_job_cleanup( true ) );
		$this->assertFalse( wp_next_scheduled( 'yotm_cleanup_jobs' ) );
		$this->assertTrue( yotm_job_tables_exist() );
		switch_to_blog( $second_blog_id );
		$this->assertFalse( wp_next_scheduled( 'yotm_cleanup_jobs' ) );
		$this->assertTrue( yotm_job_tables_exist() );
		$this->assertSame( array( 'thumbnail' ), get_option( 'yotm_disabled_sizes' ) );
		restore_current_blog();
		$this->assertSame( $this->primary_blog_id, get_current_blog_id() );
	}

	public function test_multisite_safe_uninstall_purges_every_exact_site_scope() {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Requires WordPress Multisite.' );
		}
		$primary_tables = yotm_job_table_names();
		$second_blog_id = $this->create_second_site();
		switch_to_blog( $second_blog_id );
		$second_tables = yotm_job_table_names();
		$this->create_job( 'completed' );
		restore_current_blog();

		$result = yotm_data_lifecycle_uninstall();

		$this->assertSame(
			array(
				'status' => 'purged',
				'reason' => '',
			),
			$result
		);
		$this->assertFalse( $this->table_exists( $primary_tables['jobs'] ) );
		$this->assertNull( get_option( 'yotm_job_db_version', null ) );
		switch_to_blog( $second_blog_id );
		$this->assertFalse( $this->table_exists( $second_tables['jobs'] ) );
		$this->assertNull( get_option( 'yotm_job_db_version', null ) );
		restore_current_blog();
		$this->assertSame( $this->primary_blog_id, get_current_blog_id() );
	}

	public function test_multisite_scope_change_before_commit_keeps_original_site_untouched() {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Requires WordPress Multisite.' );
		}
		$primary_tables = yotm_job_table_names();

		$result = yotm_data_lifecycle_uninstall(
			array(
				'before_commit_recheck' => function () {
					$this->create_second_site();
				},
			)
		);

		$this->assertSame( 'retained', $result['status'] );
		$this->assertSame( 'yotm_uninstall_scope_changed', $result['reason'] );
		$this->assertTrue( $this->table_exists( $primary_tables['jobs'] ) );
		$this->assertSame( YOTM_JOB_DB_VERSION, get_option( 'yotm_job_db_version' ) );
	}

	public function test_multisite_scope_change_after_intents_rolls_back_original_scope() {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Requires WordPress Multisite.' );
		}
		$primary_tables = yotm_job_table_names();

		$result = yotm_data_lifecycle_uninstall(
			array(
				'after_intents' => function () {
					$this->create_second_site();
				},
			)
		);

		$this->assertSame( 'retained', $result['status'] );
		$this->assertSame( 'yotm_uninstall_scope_changed', $result['reason'] );
		$this->assertTrue( $this->table_exists( $primary_tables['jobs'] ) );
		$this->assertNull( get_option( YOTM_UNINSTALL_INTENT_OPTION, null ) );
	}

	public function fail_selected_drop( $query ) {
		if ( $this->failed_drop_table && false !== strpos( $query, "DROP TABLE IF EXISTS `{$this->failed_drop_table}`" ) ) {
			return 'SELECT * FROM yotm_forced_lifecycle_failure';
		}

		return $query;
	}

	public function force_lifecycle_lock_contention( $query ) {
		return preg_match( '/^SELECT GET_LOCK\(/i', ltrim( $query ) ) ? 'SELECT 0' : $query;
	}

	public function count_named_lock_queries( $query ) {
		if ( preg_match( '/^SELECT GET_LOCK\(/i', ltrim( $query ) ) ) {
			++$this->named_lock_queries;
		}

		return $query;
	}

	public function record_shutdown_queries( $query ) {
		if ( preg_match( '/^UPDATE\s+\S+\s+SET worker_token = \'\'/i', ltrim( $query ) ) ) {
			$this->shutdown_queries[] = 'worker_release';
		} elseif ( preg_match( '/^SELECT RELEASE_LOCK\(/i', ltrim( $query ) ) ) {
			$this->shutdown_queries[] = 'lifecycle_release';
		}

		return $query;
	}

	public function fail_selected_inspection( $query ) {
		if ( $this->failed_inspection_table && false !== strpos( $query, "SHOW COLUMNS FROM `{$this->failed_inspection_table}`" ) ) {
			return 'SELECT FROM';
		}

		return $query;
	}

	public function fail_selected_option_read( $query ) {
		if ( $this->failed_option_read && false !== strpos( $query, 'SELECT option_value' ) && false !== strpos( $query, $this->failed_option_read ) ) {
			return 'SELECT FROM';
		}

		return $query;
	}

	public function fail_selected_option_write( $query ) {
		$write = 0 === stripos( ltrim( $query ), 'INSERT INTO' ) || 0 === stripos( ltrim( $query ), 'UPDATE ' );
		if ( $write && $this->failed_option_write && false !== strpos( $query, $this->failed_option_write ) ) {
			return 'SELECT FROM';
		}

		return $query;
	}

	public function fail_selected_option_delete( $query ) {
		if ( $this->failed_option_delete && 0 === stripos( ltrim( $query ), 'DELETE FROM' ) && false !== strpos( $query, $this->failed_option_delete ) ) {
			return 'SELECT FROM';
		}

		return $query;
	}

	public function virtual_current_schema() {
		return YOTM_JOB_DB_VERSION;
	}

	public function hide_cleanup_intent() {
		return null;
	}

	private function create_second_site() {
		$blog_id = self::factory()->blog->create();
		$this->assertNotWPError( $blog_id );
		$this->created_blogs[] = $blog_id;
		switch_to_blog( $blog_id );
		$this->restore_current_site_storage();
		$this->clear_current_site_storage();
		$this->seed_owned_options();
		restore_current_blog();

		return $blog_id;
	}

	private function create_job( $status ) {
		return yotm_job_create(
			'recommendation',
			array( 'fixture' => true ),
			array(
				'exclusive' => false,
				'status'    => $status,
				'phase'     => $status,
			)
		);
	}

	private function create_job_with_item( $payload, $item_status, $final_job_status ) {
		$job = $this->create_job( 'scanning' );
		$this->assertIsArray( $job );
		$this->add_item( $job['id'], $payload, $item_status, 'first' );
		$this->assertTrue(
			yotm_job_update(
				$job['id'],
				array(
					'status' => $final_job_status,
					'phase'  => $final_job_status,
				)
			)
		);

		return yotm_job_get_by_id( $job['id'] );
	}

	private function add_item( $job_id, $payload, $status, $key ) {
		global $wpdb;
		$tables = yotm_job_table_names();
		$now    = gmdate( 'Y-m-d H:i:s' );
		$insert = $wpdb->insert(
			$tables['items'],
			array(
				'job_id'     => $job_id,
				'item_key'   => hash( 'sha256', $key ),
				'status'     => $status,
				'payload'    => wp_json_encode( $payload ),
				'error'      => '',
				'bytes'      => 0,
				'created_at' => $now,
				'updated_at' => $now,
			)
		);
		$this->assertNotFalse( $insert );
	}

	private function resolved_prune_journal() {
		return array(
			'version'          => 1,
			'path'             => '/uploads/resolved.jpg',
			'file_hash'        => hash( 'sha256', 'resolved-file' ),
			'node_fingerprint' => hash( 'sha256', 'resolved-node' ),
			'bytes'            => 13,
			'outcome'          => 'delete_reconciled',
		);
	}

	private function resolved_force_journal() {
		$old   = array(
			'file'  => 'source.jpg',
			'sizes' => array(),
		);
		$final = array(
			'file'  => 'source.jpg',
			'sizes' => array(),
		);

		return array(
			'version'           => 1,
			'phase'             => 'cleanup_complete',
			'attachment_id'     => 123,
			'old_metadata'      => $old,
			'old_metadata_hash' => yotm_data_lifecycle_metadata_hash( $old ),
			'final_metadata'    => $final,
			'new_metadata_hash' => yotm_data_lifecycle_metadata_hash( $final ),
			'full'              => '/uploads/source.jpg',
			'stage'             => '/uploads/.yotm-regenerate-test',
			'destinations'      => array(),
			'promotion_slug'    => '',
		);
	}

	private function assertRetained( $result, $reason ) {
		$this->assertSame( 'retained', $result['status'] );
		$this->assertSame( $reason, $result['reason'] );
		$this->assertTrue( yotm_job_tables_exist() );
		$this->assertSame( YOTM_JOB_DB_VERSION, get_option( 'yotm_job_db_version' ) );
		$this->assertSame( array( 'thumbnail' ), get_option( 'yotm_disabled_sizes' ) );
		$this->assertNull( get_option( YOTM_UNINSTALL_INTENT_OPTION, null ) );
	}

	private function restore_current_site_storage() {
		delete_option( YOTM_UNINSTALL_INTENT_OPTION );
		unset( $GLOBALS['yotm_job_storage_readiness'] );
		$this->assertTrue( yotm_run_job_table_migration() );
		unset( $GLOBALS['yotm_job_storage_readiness'] );
	}

	private function clear_current_site_storage() {
		global $wpdb;
		$tables = yotm_job_table_names();
		$wpdb->query( "DELETE FROM {$tables['items']}" );
		$wpdb->query( "DELETE FROM {$tables['jobs']}" );
		$wpdb->query( "DELETE FROM {$tables['sources']}" );
	}

	private function drop_current_site_storage() {
		global $wpdb;
		foreach ( yotm_job_table_names() as $table ) {
			$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
		}
	}

	private function seed_owned_options() {
		update_option( 'yotm_disabled_sizes', array( 'thumbnail' ) );
		update_option( 'yotm_job_db_version', YOTM_JOB_DB_VERSION, false );
		update_option( 'yotm_media_source_index_dirty', array( 'attachment_ids' => array( 1 ) ), false );
		update_option( 'yotm_media_reference_index_state', array( 'generation' => 'test' ), false );
		set_transient( 'yotm_job_db_migration_failure', array( 'fixture' => true ), MINUTE_IN_SECONDS );
	}

	private function clear_owned_options() {
		foreach ( array( 'yotm_disabled_sizes', 'yotm_job_db_version', 'yotm_media_source_index_dirty', 'yotm_media_reference_index_state', YOTM_UNINSTALL_INTENT_OPTION ) as $option ) {
			delete_option( $option );
		}
		delete_transient( 'yotm_job_db_migration_failure' );
		wp_clear_scheduled_hook( 'yotm_cleanup_jobs' );
	}

	private function create_no_media_sentinels() {
		global $wpdb;
		$sentinel = $wpdb->prefix . 'yotm_jobs_archive';
		$wpdb->query( "CREATE TABLE {$sentinel} (id bigint unsigned NOT NULL PRIMARY KEY)" );
		$wpdb->insert( $sentinel, array( 'id' => 1 ) );
		update_option( 'yotm_neighbor_option', 'preserve' );

		$this->attachment_id = wp_insert_attachment(
			array(
				'post_title'     => 'Lifecycle sentinel',
				'post_status'    => 'inherit',
				'post_mime_type' => 'image/jpeg',
			)
		);
		$this->assertNotWPError( $this->attachment_id );
		update_post_meta( $this->attachment_id, '_yotm_lifecycle_sentinel', 'keep-meta' );

		$uploads        = wp_get_upload_dir();
		$this->test_dir = trailingslashit( $uploads['basedir'] ) . 'yotm-lifecycle-' . wp_generate_uuid4();
		wp_mkdir_p( $this->test_dir . '/.yotm-regenerate-test' );
		file_put_contents( $this->test_dir . '/ordinary-upload.bin', 'ordinary' );
		file_put_contents( $this->test_dir . '/.yotm-regenerate-test/staged.bin', 'staged' );
	}

	private function sentinel_table_exists() {
		global $wpdb;
		return $this->table_exists( $wpdb->prefix . 'yotm_jobs_archive' );
	}

	private function table_exists( $table ) {
		$state = yotm_data_lifecycle_inspect_table( $table );

		return ! is_wp_error( $state ) && $state['present'];
	}

	private function drop_sentinel_table() {
		global $wpdb;
		$table = $wpdb->prefix . 'yotm_jobs_archive';
		$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
	}

	private function remove_test_tree( $path ) {
		$entries = scandir( $path );
		foreach ( false === $entries ? array() : $entries as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}
			$node = $path . '/' . $entry;
			if ( is_dir( $node ) ) {
				$this->remove_test_tree( $node );
			} else {
				wp_delete_file( $node );
			}
		}
		rmdir( $path );
	}
}
