<?php

/**
 * Persistent job storage installation and readiness tests.
 */
class YOTM_Job_Storage_Installation_Test extends WP_UnitTestCase {
	/**
	 * Administrator used by job-creation assertions.
	 *
	 * @var int
	 */
	private $administrator_id;

	/**
	 * Captured database queries.
	 *
	 * @var string[]
	 */
	private $queries = array();

	public function setUp(): void {
		parent::setUp();
		$this->restore_storage();
		$this->administrator_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->administrator_id );
		$this->clear_jobs();
	}

	public function tearDown(): void {
		remove_filter( 'query', array( $this, 'capture_query' ) );
		remove_filter( 'query', array( $this, 'fail_items_table_creation' ) );
		remove_filter( 'query', array( $this, 'fail_job_lookup' ) );
		$this->restore_storage();
		$this->clear_jobs();
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	public function test_plugin_load_has_no_schema_install_hook() {
		$plugin_file      = dirname( __DIR__, 2 ) . '/thumbnail-manager.php';
		$activation_hook  = 'activate_' . plugin_basename( $plugin_file );
		$activation_bound = has_action( $activation_hook, 'yotm_activate_job_storage' );

		$this->assertFalse( has_action( 'plugins_loaded', 'yotm_maybe_install_job_tables' ) );
		$this->assertSame( 10, $activation_bound );
	}

	public function test_compatibility_entrypoint_loads_each_job_layer_once() {
		$storage  = new ReflectionFunction( 'yotm_job_table_names' );
		$engine   = new ReflectionFunction( 'yotm_job_create' );
		$cancel   = new ReflectionFunction( 'yotm_job_cancel' );
		$cleanup  = new ReflectionFunction( 'yotm_cleanup_expired_jobs' );
		$items    = new ReflectionFunction( 'yotm_job_add_item' );
		$claims   = new ReflectionFunction( 'yotm_job_claim_items' );
		$merge    = new ReflectionFunction( 'yotm_job_merge_item_payload' );
		$page     = new ReflectionFunction( 'yotm_job_get_items_page' );
		$errors   = new ReflectionFunction( 'yotm_job_get_error_sample' );
		$manifest = new ReflectionFunction( 'yotm_job_build_manifest_batch' );
		$mutable  = new ReflectionFunction( 'yotm_job_get_mutable_item' );
		$rows     = new ReflectionFunction( 'yotm_job_get_manifest_rows_after' );
		$digest   = new ReflectionFunction( 'yotm_job_manifest_digest_advance' );
		$prune    = new ReflectionFunction( 'yotm_prune_build_manifest_batch' );

		$this->assertStringEndsWith( '/inc/jobs/storage.php', wp_normalize_path( $storage->getFileName() ) );
		$this->assertStringEndsWith( '/inc/jobs/engine.php', wp_normalize_path( $engine->getFileName() ) );
		$this->assertStringEndsWith( '/inc/jobs/engine.php', wp_normalize_path( $cancel->getFileName() ) );
		$this->assertStringEndsWith( '/inc/jobs/engine.php', wp_normalize_path( $cleanup->getFileName() ) );
		$this->assertStringEndsWith( '/inc/jobs/items.php', wp_normalize_path( $items->getFileName() ) );
		$this->assertStringEndsWith( '/inc/jobs/items.php', wp_normalize_path( $claims->getFileName() ) );
		$this->assertStringEndsWith( '/inc/job-storage.php', wp_normalize_path( $merge->getFileName() ) );
		$this->assertStringEndsWith( '/inc/job-storage.php', wp_normalize_path( $page->getFileName() ) );
		$this->assertStringEndsWith( '/inc/job-storage.php', wp_normalize_path( $errors->getFileName() ) );
		$this->assertStringEndsWith( '/inc/job-storage.php', wp_normalize_path( $manifest->getFileName() ) );
		$this->assertStringEndsWith( '/inc/jobs/items.php', wp_normalize_path( $mutable->getFileName() ) );
		$this->assertStringEndsWith( '/inc/jobs/items.php', wp_normalize_path( $rows->getFileName() ) );
		$this->assertStringEndsWith( '/inc/jobs/items.php', wp_normalize_path( $digest->getFileName() ) );
		$this->assertStringEndsWith( '/inc/application/prune.php', wp_normalize_path( $prune->getFileName() ) );
		$this->assertSame( 10, has_action( 'yotm_cleanup_jobs', 'yotm_cleanup_expired_jobs' ) );
	}

	public function test_database_and_source_table_ownership_preserve_installation_compatibility() {
		$root           = dirname( __DIR__, 2 );
		$database       = new ReflectionFunction( 'yotm_database_acquire_named_lock' );
		$job_lock       = new ReflectionFunction( 'yotm_job_acquire_named_lock' );
		$source_table   = new ReflectionFunction( 'yotm_media_source_table_name' );
		$source_replace = new ReflectionFunction( 'yotm_media_source_replace_attachment' );

		$this->assertSame( wp_normalize_path( $root . '/inc/infrastructure/database.php' ), wp_normalize_path( $database->getFileName() ) );
		$this->assertSame( wp_normalize_path( $root . '/inc/jobs/engine.php' ), wp_normalize_path( $job_lock->getFileName() ) );
		$this->assertSame( wp_normalize_path( $root . '/inc/media/source-store.php' ), wp_normalize_path( $source_table->getFileName() ) );
		$this->assertSame( wp_normalize_path( $root . '/inc/media/source-store.php' ), wp_normalize_path( $source_replace->getFileName() ) );
		$this->assertSame( yotm_job_table_names()['sources'], yotm_media_source_table_name() );
		$this->assertSame( YOTM_JOB_DB_VERSION, get_option( 'yotm_job_db_version' ) );

		$job_error      = yotm_job_storage_error( 'yotm_job_storage_unavailable', 'compatibility-fixture' );
		$database_error = yotm_database_storage_error( 'yotm_job_storage_unavailable', 'compatibility-fixture' );
		$this->assertSame( $job_error->get_error_code(), $database_error->get_error_code() );
		$this->assertSame( $job_error->get_error_message(), $database_error->get_error_message() );
		$this->assertSame( $job_error->get_error_data(), $database_error->get_error_data() );
	}

	public function test_extracted_job_layers_do_not_depend_on_transport_or_feature_projection() {
		$job_directory = dirname( __DIR__, 2 ) . '/inc/jobs/';

		foreach ( array( 'storage.php', 'engine.php', 'items.php' ) as $file ) {
			$source = file_get_contents( $job_directory . $file );
			$this->assertIsString( $source );
			$this->assertStringNotContainsString( '$_POST', $source, $file );
			$this->assertStringNotContainsString( 'wp_send_json', $source, $file );
			$this->assertDoesNotMatchRegularExpression( '/\byotm_job_public_|\byotm_recommendation_result_for_response\b/', $source, $file );
		}

		$item_source = file_get_contents( $job_directory . 'items.php' );
		$this->assertIsString( $item_source );
		$this->assertDoesNotMatchRegularExpression(
			'/\b(?:metadata_refs|ownership_evidence|ownership_schema|remove_metadata|attachment_id|estimated_bytes)\b/',
			$item_source,
			'items.php must remain opaque to Prune/Media payload fields.'
		);

		$application_source = file_get_contents( dirname( __DIR__, 2 ) . '/inc/application/prune.php' );
		$this->assertIsString( $application_source );
		$this->assertDoesNotMatchRegularExpression(
			'/\$_POST|\bwp_send_json_|\bcurrent_user_can\b|\bcheck_ajax_referer\b/',
			$application_source,
			'Prune Application sequencing must remain transport-neutral.'
		);

		foreach ( array( 'handle-prune.php', 'handle-delete.php' ) as $file ) {
			$source = file_get_contents( dirname( __DIR__, 2 ) . '/inc/' . $file );
			$this->assertIsString( $source );
			$this->assertDoesNotMatchRegularExpression( '/\byotm_(?:job|media)_/', $source, $file );
		}
	}

	public function test_reactivation_restores_cleanup_schedule_without_ddl() {
		wp_clear_scheduled_hook( 'yotm_cleanup_jobs' );
		$this->reset_request_state();
		$this->start_query_capture();

		$this->assertTrue( yotm_activate_job_storage() );
		$scheduled = wp_next_scheduled( 'yotm_cleanup_jobs' );
		$this->assertNotFalse( $scheduled );
		$this->assertTrue( yotm_activate_job_storage() );
		$this->assertSame( $scheduled, wp_next_scheduled( 'yotm_cleanup_jobs' ) );

		$this->stop_query_capture();
		$this->assertSame( 3, $this->count_presence_queries() );
		$this->assertSame( 0, $this->count_queries( '/^(CREATE|ALTER) TABLE/i' ) );
	}

	public function test_deactivation_clears_only_cleanup_cron_and_retains_persistent_and_media_state() {
		global $wpdb;

		$job = yotm_job_create(
			'recommendation',
			array( 'retention' => 'job' ),
			array(
				'exclusive' => false,
				'phase'     => 'metadata',
				'status'    => 'scanning',
			)
		);
		$this->assertIsArray( $job );
		$this->assertTrue( yotm_job_add_item( $job['id'], 'retained-item', array( 'retention' => 'item' ), 'done', 17 ) );

		$uploads      = wp_upload_dir();
		$fixture_name = 'yotm-retention-' . wp_generate_uuid4() . '.jpg';
		$fixture_path = trailingslashit( $uploads['path'] ) . $fixture_name;
		$recovery_dir = trailingslashit( $uploads['basedir'] ) . '.yotm-regenerate-retention-' . wp_generate_uuid4();
		$recovery     = trailingslashit( $recovery_dir ) . 'journal.json';
		$this->assertTrue( wp_mkdir_p( dirname( $fixture_path ) ) );
		$this->assertNotFalse( file_put_contents( $fixture_path, 'retained attachment fixture' ) );
		$this->assertTrue( wp_mkdir_p( $recovery_dir ) );
		$this->assertNotFalse( file_put_contents( $recovery, '{"retained":true}' ) );

		$attachment_id = wp_insert_attachment(
			array(
				'post_mime_type' => 'image/jpeg',
				'post_status'    => 'inherit',
				'post_title'     => 'Retention fixture',
			),
			$fixture_path
		);
		$this->assertIsInt( $attachment_id );
		update_attached_file( $attachment_id, $fixture_path );
		$metadata = array(
			'file'  => _wp_relative_upload_path( $fixture_path ),
			'sizes' => array(
				'thumbnail' => array(
					'file'   => $fixture_name,
					'width'  => 1,
					'height' => 1,
				),
			),
		);
		wp_update_attachment_metadata( $attachment_id, $metadata );

		$tables    = yotm_job_table_names();
		$path_hash = hash( 'sha256', $fixture_path );
		$this->assertNotFalse(
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Test fixture for retained plugin-owned derived state.
			$wpdb->insert(
				$tables['sources'],
				array(
					'attachment_id' => $attachment_id,
					'source_kind'   => 'retention_test',
					'path_hash'     => $path_hash,
					'path'          => $fixture_path,
					'updated_at'    => gmdate( 'Y-m-d H:i:s' ),
				)
			)
		);

		update_option( 'yotm_disabled_sizes', array( 'thumbnail' ), false );
		set_transient( 'yotm_job_db_migration_failure', array( 'retention' => true ), HOUR_IN_SECONDS );
		wp_clear_scheduled_hook( 'yotm_cleanup_jobs' );
		yotm_schedule_job_cleanup();

		try {
			$this->assertNotFalse( wp_next_scheduled( 'yotm_cleanup_jobs' ) );
			yotm_deactivate_job_cleanup( false );
			yotm_deactivate_job_cleanup( false );

			$this->assertFalse( wp_next_scheduled( 'yotm_cleanup_jobs' ) );
			$this->assertTrue( yotm_job_tables_exist() );
			$this->assertSame( YOTM_JOB_DB_VERSION, get_option( 'yotm_job_db_version' ) );
			$this->assertSame( array( 'thumbnail' ), get_option( 'yotm_disabled_sizes' ) );
			$this->assertSame( array( 'retention' => true ), get_transient( 'yotm_job_db_migration_failure' ) );
			$this->assertSame( 'job', yotm_job_get_by_id( $job['id'] )['payload']['retention'] );
			$this->assertSame( 'item', yotm_job_get_item_by_key( $job['id'], 'retained-item' )['payload']['retention'] );
			$this->assertSame(
				'1',
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Exact test-fixture presence assertion.
				$wpdb->get_var(
					$wpdb->prepare(
						"SELECT COUNT(*) FROM {$tables['sources']} WHERE path_hash = %s AND source_kind = %s",
						$path_hash,
						'retention_test'
					)
				)
			);
			$this->assertSame( $metadata, wp_get_attachment_metadata( $attachment_id ) );
			$this->assertFileExists( $fixture_path );
			$this->assertFileExists( $recovery );
		} finally {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Remove the exact test fixture only.
			$wpdb->delete( $tables['sources'], array( 'path_hash' => $path_hash ) );
			wp_delete_attachment( $attachment_id, true );
			if ( file_exists( $fixture_path ) ) {
				unlink( $fixture_path );
			}
			if ( file_exists( $recovery ) ) {
				unlink( $recovery );
			}
			if ( is_dir( $recovery_dir ) ) {
				rmdir( $recovery_dir );
			}
			delete_option( 'yotm_disabled_sizes' );
			delete_transient( 'yotm_job_db_migration_failure' );
		}
	}

	public function test_current_storage_is_checked_once_per_request() {
		$this->reset_request_state();
		$this->start_query_capture();

		$this->assertTrue( yotm_job_storage_ready() );
		$this->assertSame( array(), yotm_job_get_recent_for_current_user() );
		$this->assertTrue( yotm_cleanup_expired_jobs() );

		$this->stop_query_capture();
		$this->assertSame( 3, $this->count_presence_queries() );
		$this->assertSame( 0, $this->count_queries( '/^(CREATE|ALTER) TABLE/i' ) );
	}

	public function test_current_marker_and_both_tables_missing_fail_closed() {
		$this->drop_tables( array( 'jobs', 'items', 'sources' ) );
		update_option( 'yotm_job_db_version', YOTM_JOB_DB_VERSION, false );
		$this->reset_request_state();
		$this->start_query_capture();

		try {
			$ready = yotm_job_storage_ready();
			$this->assert_storage_inconsistent( $ready );
			$this->assert_storage_inconsistent( yotm_job_create( 'recommendation', array(), array( 'exclusive' => false ) ) );
			$this->assert_storage_inconsistent( yotm_job_get_recent_for_current_user() );
			$this->assert_storage_inconsistent( yotm_cleanup_expired_jobs() );
			$this->assertSame( YOTM_JOB_DB_VERSION, get_option( 'yotm_job_db_version' ) );
			$this->assertSame( 3, $this->count_presence_queries() );
			$this->assertSame( 0, $this->count_queries( '/^(CREATE|ALTER) TABLE/i' ) );
		} finally {
			$this->stop_query_capture();
		}
	}

	/**
	 * @dataProvider partial_storage_provider
	 */
	public function test_partial_storage_fails_closed_for_any_marker( $stored_version, $missing_table ) {
		$this->drop_tables( array( $missing_table ) );

		if ( null === $stored_version ) {
			delete_option( 'yotm_job_db_version' );
		} else {
			update_option( 'yotm_job_db_version', $stored_version, false );
		}

		$this->reset_request_state();
		$this->start_query_capture();

		try {
			$this->assert_storage_inconsistent( yotm_job_storage_ready() );
			$this->assertSame( 3, $this->count_presence_queries() );
			$this->assertSame( 0, $this->count_queries( '/^(CREATE|ALTER) TABLE/i' ) );
		} finally {
			$this->stop_query_capture();
		}
	}

	public function partial_storage_provider() {
		return array(
			'current marker, jobs missing'    => array( YOTM_JOB_DB_VERSION, 'jobs' ),
			'current marker, items missing'   => array( YOTM_JOB_DB_VERSION, 'items' ),
			'current marker, sources missing' => array( YOTM_JOB_DB_VERSION, 'sources' ),
			'older marker, jobs missing'      => array( '1.0.0', 'jobs' ),
			'absent marker, items missing'    => array( null, 'items' ),
		);
	}

	public function test_absent_marker_and_both_tables_absent_install_once() {
		$this->drop_tables( array( 'jobs', 'items', 'sources' ) );
		delete_option( 'yotm_job_db_version' );
		delete_transient( 'yotm_job_db_migration_failure' );
		wp_clear_scheduled_hook( 'yotm_cleanup_jobs' );
		$this->reset_request_state();
		$this->start_query_capture();

		try {
			$this->assertTrue( yotm_activate_job_storage() );
			$this->assertTrue( yotm_activate_job_storage() );
			$this->stop_query_capture();
			$this->assertSame( YOTM_JOB_DB_VERSION, get_option( 'yotm_job_db_version' ) );
			$this->assertTrue( yotm_job_tables_exist() );
			$this->assertNotFalse( wp_next_scheduled( 'yotm_cleanup_jobs' ) );
			$this->assertSame( 9, $this->count_presence_queries() );
			$this->assertSame( 3, $this->count_queries( '/^CREATE(?: TEMPORARY)? TABLE/i' ) );
		} finally {
			$this->stop_query_capture();
		}
	}

	public function test_older_marker_migrates_without_losing_job_or_item_data() {
		$job = yotm_job_create(
			'prune',
			array( 'proof' => 'preserved' ),
			array(
				'status' => 'scanning',
				'phase'  => 'metadata',
				'total'  => 1,
			)
		);
		$this->assertIsArray( $job );
		$this->assertTrue( yotm_job_add_item( $job['id'], 'preserved-item', array( 'path' => '/tmp/preserved.jpg' ), 'queued', 42 ) );

		$this->drop_concurrency_columns();
		update_option( 'yotm_job_db_version', '1.0.1', false );
		$this->reset_request_state();
		$this->start_query_capture();

		$this->assertTrue( yotm_job_storage_ready() );
		$this->stop_query_capture();

		$stored_job  = yotm_job_get_by_id( $job['id'] );
		$stored_item = yotm_job_get_item_by_key( $job['id'], 'preserved-item' );
		$this->assertSame( YOTM_JOB_DB_VERSION, get_option( 'yotm_job_db_version' ) );
		$this->assertSame( 'preserved', $stored_job['payload']['proof'] );
		$this->assertSame( 1, $stored_job['total'] );
		$this->assertSame( 'legacy_v1', $stored_job['counter_mode'] );
		$this->assertSame( '', $stored_job['worker_token'] );
		$this->assertSame( 0, $stored_job['worker_generation'] );
		$this->assertSame( 42, $stored_item['bytes'] );
		$this->assertSame( '', $stored_item['claim_token'] );
		$this->assertSame( 0, $stored_item['claim_generation'] );
		$this->assertSame( 0, $stored_item['attempts'] );
		$this->assertSame( 9, $this->count_presence_queries() );
	}

	/**
	 * @dataProvider two_table_predecessor_provider
	 */
	public function test_two_table_predecessor_adds_source_table_without_losing_data( $stored_version ) {
		$job = yotm_job_create(
			'prune',
			array( 'proof' => 'two-table-predecessor' ),
			array(
				'status' => 'scanning',
				'phase'  => 'metadata',
			)
		);
		$this->assertIsArray( $job );
		$this->assertTrue( yotm_job_add_item( $job['id'], 'two-table-item', array( 'proof' => $stored_version ), 'queued', 17 ) );
		$this->drop_tables( array( 'sources' ) );
		if ( '1.0.1' === $stored_version ) {
			$this->drop_concurrency_columns();
		}
		update_option( 'yotm_job_db_version', $stored_version, false );
		$this->reset_request_state();

		$this->assertTrue( yotm_job_storage_ready() );
		$this->assertTrue( yotm_job_tables_exist() );
		$this->assertSame( 'two-table-predecessor', yotm_job_get_by_id( $job['id'] )['payload']['proof'] );
		$this->assertSame( $stored_version, yotm_job_get_item_by_key( $job['id'], 'two-table-item' )['payload']['proof'] );
		$this->assertSame( 17, yotm_job_get_item_by_key( $job['id'], 'two-table-item' )['bytes'] );
		$this->assertSame( YOTM_JOB_DB_VERSION, get_option( 'yotm_job_db_version' ) );
	}

	public function two_table_predecessor_provider() {
		return array(
			'first persistent schema' => array( '1.0.1' ),
			'pre-source schema'       => array( YOTM_JOB_DB_PRE_SOURCE_VERSION ),
		);
	}

	public function test_failed_install_is_memoized_for_later_operations_in_same_request() {
		global $wpdb;

		$this->drop_tables( array( 'jobs', 'items', 'sources' ) );
		delete_option( 'yotm_job_db_version' );
		delete_transient( 'yotm_job_db_migration_failure' );
		$this->reset_request_state();
		$this->queries = array();
		$suppressing   = $wpdb->suppress_errors();
		add_filter( 'query', array( $this, 'fail_items_table_creation' ) );

		try {
			$ready = yotm_job_storage_ready();
			$this->assert_storage_unavailable( $ready );
			$this->assert_storage_unavailable( yotm_job_create( 'recommendation', array(), array( 'exclusive' => false ) ) );
			$this->assert_storage_unavailable( yotm_job_get_recent_for_current_user() );
			$this->assert_storage_unavailable( yotm_cleanup_expired_jobs() );
			$this->assertFalse( get_option( 'yotm_job_db_version' ) );
			$this->assertIsArray( get_transient( 'yotm_job_db_migration_failure' ) );
			$this->assertSame( 6, $this->count_presence_queries() );
			$this->assertSame( 3, $this->count_queries( '/^CREATE(?: TEMPORARY)? TABLE/i' ) );
		} finally {
			remove_filter( 'query', array( $this, 'fail_items_table_creation' ) );
			$wpdb->suppress_errors( $suppressing );
		}
	}

	public function test_migration_backoff_blocks_ddl_on_a_later_request() {
		update_option( 'yotm_job_db_version', '1.0.0', false );
		set_transient(
			'yotm_job_db_migration_failure',
			array(
				'version'   => YOTM_JOB_DB_VERSION,
				'failed_at' => time(),
			),
			YOTM_JOB_DB_MIGRATION_BACKOFF
		);
		$this->reset_request_state();
		$this->start_query_capture();

		$this->assert_storage_unavailable( yotm_job_storage_ready() );
		$this->stop_query_capture();

		$this->assertSame( '1.0.0', get_option( 'yotm_job_db_version' ) );
		$this->assertSame( 3, $this->count_presence_queries() );
		$this->assertSame( 0, $this->count_queries( '/^(CREATE|ALTER) TABLE/i' ) );
	}

	public function test_operational_database_failure_is_not_reported_as_a_missing_job() {
		global $wpdb;

		$this->reset_request_state();
		$this->assertTrue( yotm_job_storage_ready() );
		$suppressing = $wpdb->suppress_errors();
		add_filter( 'query', array( $this, 'fail_job_lookup' ) );

		try {
			$result = yotm_job_get( wp_generate_uuid4() );
			$this->assert_storage_unavailable( $result );
			$this->assertNotSame( 'yotm_job_missing', $result->get_error_code() );
		} finally {
			remove_filter( 'query', array( $this, 'fail_job_lookup' ) );
			$wpdb->suppress_errors( $suppressing );
		}
	}

	public function test_multisite_storage_state_is_isolated_between_sites() {
		global $wpdb;

		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Requires WordPress Multisite.' );
		}

		$primary_blog_id = get_current_blog_id();
		$primary_tables  = yotm_job_table_names();
		$primary_failure = array(
			'version'   => YOTM_JOB_DB_VERSION,
			'failed_at' => 111,
		);
		$second_blog_id  = self::factory()->blog->create();

		$this->assertNotWPError( $second_blog_id );

		$this->reset_request_state();
		$this->assertTrue( yotm_job_storage_ready() );
		$this->assertSame( YOTM_JOB_DB_VERSION, get_option( 'yotm_job_db_version' ) );
		set_transient( 'yotm_job_db_migration_failure', $primary_failure, YOTM_JOB_DB_MIGRATION_BACKOFF );

		try {
			switch_to_blog( $second_blog_id );

			$second_tables = yotm_job_table_names();
			$this->drop_tables( array( 'jobs', 'items', 'sources' ) );
			delete_option( 'yotm_job_db_version' );
			delete_transient( 'yotm_job_db_migration_failure' );

			$this->assertSame( $wpdb->get_blog_prefix( $second_blog_id ) . 'yotm_jobs', $second_tables['jobs'] );
			$this->assertSame( $wpdb->get_blog_prefix( $second_blog_id ) . 'yotm_media_sources', $second_tables['sources'] );
			$this->assertNotSame( $primary_tables['jobs'], $second_tables['jobs'] );
			$this->assertFalse( get_option( 'yotm_job_db_version', false ) );
			$this->assertFalse( get_transient( 'yotm_job_db_migration_failure' ) );
			$this->assertFalse( yotm_job_tables_exist() );

			$this->assertTrue( yotm_job_storage_ready() );
			$this->assertSame( YOTM_JOB_DB_VERSION, get_option( 'yotm_job_db_version' ) );
			$this->assertTrue( yotm_job_tables_exist() );

			set_transient(
				'yotm_job_db_migration_failure',
				array(
					'version'   => YOTM_JOB_DB_VERSION,
					'failed_at' => 222,
				),
				YOTM_JOB_DB_MIGRATION_BACKOFF
			);
			restore_current_blog();

			$this->assertSame( $primary_blog_id, get_current_blog_id() );
			$this->assertSame( $primary_tables, yotm_job_table_names() );
			$this->assertSame( YOTM_JOB_DB_VERSION, get_option( 'yotm_job_db_version' ) );
			$this->assertSame( $primary_failure, get_transient( 'yotm_job_db_migration_failure' ) );
			$this->assertTrue( yotm_job_storage_ready() );
		} finally {
			if ( get_current_blog_id() !== $primary_blog_id ) {
				restore_current_blog();
			}

			switch_to_blog( $second_blog_id );
			$this->drop_tables( array( 'jobs', 'items', 'sources' ) );
			delete_option( 'yotm_job_db_version' );
			delete_transient( 'yotm_job_db_migration_failure' );
			restore_current_blog();
			wp_delete_site( $second_blog_id );
			delete_transient( 'yotm_job_db_migration_failure' );
			$this->reset_request_state();
		}
	}

	public function test_multisite_site_deactivation_clears_only_the_current_site_schedule() {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Requires WordPress Multisite.' );
		}

		$primary_blog_id = get_current_blog_id();
		$second_blog_id  = self::factory()->blog->create();
		$this->assertNotWPError( $second_blog_id );

		wp_clear_scheduled_hook( 'yotm_cleanup_jobs' );
		yotm_schedule_job_cleanup();
		$this->assertNotFalse( wp_next_scheduled( 'yotm_cleanup_jobs' ) );

		try {
			switch_to_blog( $second_blog_id );
			$this->reset_request_state();
			$this->assertTrue( yotm_job_storage_ready() );
			wp_clear_scheduled_hook( 'yotm_cleanup_jobs' );
			yotm_schedule_job_cleanup();
			yotm_deactivate_job_cleanup( false );
			$this->assertFalse( wp_next_scheduled( 'yotm_cleanup_jobs' ) );
			restore_current_blog();

			$this->assertSame( $primary_blog_id, get_current_blog_id() );
			$this->assertNotFalse( wp_next_scheduled( 'yotm_cleanup_jobs' ) );
		} finally {
			if ( get_current_blog_id() !== $primary_blog_id ) {
				restore_current_blog();
			}
			$this->remove_test_site( $second_blog_id );
			wp_clear_scheduled_hook( 'yotm_cleanup_jobs' );
			$this->reset_request_state();
		}
	}

	public function test_multisite_network_deactivation_clears_all_schedules_and_retains_site_state() {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Requires WordPress Multisite.' );
		}

		$primary_blog_id = get_current_blog_id();
		$site_ids        = array( $primary_blog_id );
		$markers         = array();
		for ( $index = 1; $index <= 2; ++$index ) {
			$site_id = self::factory()->blog->create();
			$this->assertNotWPError( $site_id );
			$site_ids[] = $site_id;
		}

		try {
			foreach ( $site_ids as $site_id ) {
				if ( $site_id !== $primary_blog_id ) {
					switch_to_blog( $site_id );
				}
				$this->reset_request_state();
				$this->assertTrue( yotm_job_storage_ready() );
				$marker = 'retained-site-' . $site_id;
				update_option( 'yotm_disabled_sizes', array( $marker ), false );
				set_transient( 'yotm_job_db_migration_failure', array( 'marker' => $marker ), HOUR_IN_SECONDS );
				wp_clear_scheduled_hook( 'yotm_cleanup_jobs' );
				yotm_schedule_job_cleanup();
				$markers[ $site_id ] = $marker;
				if ( $site_id !== $primary_blog_id ) {
					restore_current_blog();
				}
			}

			switch_to_blog( $site_ids[1] );
			$call_blog_id = get_current_blog_id();
			$was_switched = ms_is_switched();
			yotm_deactivate_job_cleanup( true );
			$this->assertSame( $call_blog_id, get_current_blog_id() );
			$this->assertSame( $was_switched, ms_is_switched() );
			restore_current_blog();
			$this->assertSame( $primary_blog_id, get_current_blog_id() );

			foreach ( $site_ids as $site_id ) {
				if ( $site_id !== $primary_blog_id ) {
					switch_to_blog( $site_id );
				}
				$this->reset_request_state();
				$this->assertFalse( wp_next_scheduled( 'yotm_cleanup_jobs' ) );
				$this->assertTrue( yotm_job_tables_exist() );
				$this->assertSame( YOTM_JOB_DB_VERSION, get_option( 'yotm_job_db_version' ) );
				$this->assertSame( array( $markers[ $site_id ] ), get_option( 'yotm_disabled_sizes' ) );
				$this->assertSame( array( 'marker' => $markers[ $site_id ] ), get_transient( 'yotm_job_db_migration_failure' ) );
				if ( $site_id !== $primary_blog_id ) {
					restore_current_blog();
				}
			}
		} finally {
			while ( ms_is_switched() ) {
				restore_current_blog();
			}
			foreach ( array_reverse( array_slice( $site_ids, 1 ) ) as $site_id ) {
				$this->remove_test_site( $site_id );
			}
			delete_option( 'yotm_disabled_sizes' );
			delete_transient( 'yotm_job_db_migration_failure' );
			wp_clear_scheduled_hook( 'yotm_cleanup_jobs' );
			$this->reset_request_state();
		}
	}

	public function capture_query( $query ) {
		$this->queries[] = $query;

		return $query;
	}

	public function fail_items_table_creation( $query ) {
		$this->queries[] = $query;
		$items_table     = yotm_job_table_names()['items'];

		if ( preg_match( '/^CREATE(?: TEMPORARY)? TABLE\s+' . preg_quote( $items_table, '/' ) . '/i', $query ) ) {
			return 'SELECT * FROM yotm_forced_missing_table';
		}

		return $query;
	}

	public function fail_job_lookup( $query ) {
		$jobs_table = yotm_job_table_names()['jobs'];

		if ( preg_match( '/^SELECT \* FROM\s+' . preg_quote( $jobs_table, '/' ) . '\s+WHERE token/i', $query ) ) {
			return 'SELECT * FROM yotm_forced_missing_table';
		}

		return $query;
	}

	private function restore_storage() {
		remove_filter( 'query', array( $this, 'capture_query' ) );
		remove_filter( 'query', array( $this, 'fail_items_table_creation' ) );
		remove_filter( 'query', array( $this, 'fail_job_lookup' ) );
		$this->reset_request_state();
		delete_transient( 'yotm_job_db_migration_failure' );
		delete_option( 'yotm_job_db_version' );
		$result = yotm_run_job_table_migration();
		$this->assertTrue( $result );
		$this->reset_request_state();
	}

	private function reset_request_state() {
		unset( $GLOBALS['yotm_job_storage_readiness'] );
	}

	private function clear_jobs() {
		global $wpdb;

		$tables = yotm_job_table_names();
		$wpdb->query( "DELETE FROM {$tables['items']}" );
		$wpdb->query( "DELETE FROM {$tables['jobs']}" );
	}

	private function remove_test_site( $site_id ) {
		switch_to_blog( $site_id );
		$this->drop_tables( array( 'jobs', 'items', 'sources' ) );
		delete_option( 'yotm_job_db_version' );
		delete_option( 'yotm_disabled_sizes' );
		delete_transient( 'yotm_job_db_migration_failure' );
		wp_clear_scheduled_hook( 'yotm_cleanup_jobs' );
		restore_current_blog();
		wp_delete_site( $site_id );
	}

	private function drop_tables( $keys ) {
		global $wpdb;

		$tables = yotm_job_table_names();
		foreach ( $keys as $key ) {
			$wpdb->query( "DROP TABLE IF EXISTS {$tables[ $key ]}" );
		}
	}

	private function drop_concurrency_columns() {
		global $wpdb;

		$tables = yotm_job_table_names();
		$wpdb->query(
			"ALTER TABLE {$tables['jobs']}
			DROP INDEX worker_lease,
			DROP COLUMN counter_mode,
			DROP COLUMN worker_token,
			DROP COLUMN worker_generation,
			DROP COLUMN worker_lease_expires_at"
		);
		$wpdb->query(
			"ALTER TABLE {$tables['items']}
			DROP INDEX job_claim,
			DROP COLUMN claim_token,
			DROP COLUMN claim_generation,
			DROP COLUMN claim_expires_at,
			DROP COLUMN attempts"
		);
	}

	private function start_query_capture() {
		$this->queries = array();
		add_filter( 'query', array( $this, 'capture_query' ) );
	}

	private function stop_query_capture() {
		remove_filter( 'query', array( $this, 'capture_query' ) );
	}

	private function count_queries( $pattern ) {
		return count(
			array_filter(
				$this->queries,
				static function ( $query ) use ( $pattern ) {
					return 1 === preg_match( $pattern, ltrim( $query ) );
				}
			)
		);
	}

	private function count_presence_queries() {
		return $this->count_queries( '/^DESCRIBE\s+\S*yotm_(jobs|job_items|media_sources)/i' );
	}

	private function assert_storage_inconsistent( $result ) {
		$this->assertWPError( $result );
		$this->assertSame( 'yotm_job_storage_inconsistent', $result->get_error_code() );
	}

	private function assert_storage_unavailable( $result ) {
		$this->assertWPError( $result );
		$this->assertSame( 'yotm_job_storage_unavailable', $result->get_error_code() );
	}
}
