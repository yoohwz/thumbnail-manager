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

	public function setUp(): void {
		parent::setUp();
		yotm_install_job_tables();
		$this->administrator_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->administrator_id );
		$this->clear_jobs();
	}

	public function tearDown(): void {
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

	public function test_manifest_is_stable_and_cannot_grow_after_review() {
		$job = yotm_job_create(
			'prune',
			array(),
			array(
				'status' => 'scanning',
				'phase'  => 'metadata',
			)
		);
		$this->assertTrue( yotm_job_add_item( $job['id'], 'second', array( 'path' => '/tmp/second.jpg' ) ) );
		$this->assertTrue( yotm_job_add_item( $job['id'], 'first', array( 'path' => '/tmp/first.jpg' ) ) );
		yotm_job_update( $job['id'], array( 'phase' => 'manifest' ) );
		$this->assertFalse( yotm_job_merge_item_payload( $job['id'], 'first', array( 'metadata_refs' => array() ) ) );

		do {
			$manifest = yotm_job_build_manifest_batch( yotm_job_get_by_id( $job['id'] ), 10 );
		} while ( ! $manifest['done'] );

		$job = $manifest['job'];
		$this->assertMatchesRegularExpression( '/^[a-f0-9]{64}$/', $job['manifest_hash'] );

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
			array(),
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

		yotm_job_update(
			$job['id'],
			array(
				'status' => 'approved',
				'phase'  => 'delete',
			)
		);
		$job = yotm_job_get_by_id( $job['id'] );
		$this->assertTrue( yotm_prune_validate_delete_job( $job, $job['manifest_hash'] ) );
		$this->assertWPError( yotm_prune_validate_delete_job( $job, str_repeat( 'f', 64 ) ) );
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
			array( 'base' => $uploads['basedir'] ),
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
		$this->directories[] = $directory;
		$this->directories[] = $second_directory;
		wp_mkdir_p( $directory );
		wp_mkdir_p( $second_directory );

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
		$this->assertSame( 8, $batch['job']['payload']['orphan_summary']['total_files'] );
		$this->assertSame( 8, $batch['job']['payload']['orphan_summary']['unmapped_skipped'] );
	}

	private function clear_jobs() {
		global $wpdb;

		$tables = yotm_job_table_names();
		$wpdb->query( "DELETE FROM {$tables['items']}" );
		$wpdb->query( "DELETE FROM {$tables['jobs']}" );
	}
}
