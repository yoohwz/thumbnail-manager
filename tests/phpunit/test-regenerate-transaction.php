<?php
/**
 * Transactional Force regeneration tests.
 */
class YOTM_Regenerate_Transaction_Test extends WP_UnitTestCase {
	/** @var int */
	private $administrator_id = 0;

	/** @var int */
	private $attachment_id = 0;

	/** @var string */
	private $test_dir = '';

	/** @var int */
	private $update_filter_calls = 0;

	/** @var array */
	private $thumbnail_options = array();

	/** @var int */
	private $filtered_commit_attachment_id = 0;

	/** @var array */
	private $filtered_commit_metadata = array();

	public function setUp(): void {
		parent::setUp();
		yotm_data_lifecycle_release_request_fences();
		unset( $GLOBALS['yotm_job_storage_readiness'], $GLOBALS['yotm_job_logical_locks'], $GLOBALS['yotm_media_source_last_error'] );
		$this->assertTrue( yotm_run_job_table_migration() );
		yotm_media_source_shutdown_cleanup();
		$this->administrator_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->administrator_id );
		$this->thumbnail_options = array(
			'w'    => get_option( 'thumbnail_size_w' ),
			'h'    => get_option( 'thumbnail_size_h' ),
			'crop' => get_option( 'thumbnail_crop' ),
		);
		update_option( 'thumbnail_size_w', 100 );
		update_option( 'thumbnail_size_h', 100 );
		update_option( 'thumbnail_crop', 1 );
		delete_option( YOTM_MEDIA_SOURCE_DIRTY_OPTION );
		update_option( 'thumbnail_size_w', $this->thumbnail_options['w'] );
		update_option( 'thumbnail_size_h', $this->thumbnail_options['h'] );
		update_option( 'thumbnail_crop', $this->thumbnail_options['crop'] );
		$this->assertTrue( yotm_media_source_clear_index() );
		$state = yotm_media_reference_index_state();
		$this->assertIsArray( $state );
		$this->assertTrue( yotm_media_reference_baseline_complete( $state['baseline_token'] ) );
		$uploads        = wp_get_upload_dir();
		$this->test_dir = trailingslashit( $uploads['basedir'] ) . 'yotm-force-' . wp_generate_uuid4();
		wp_mkdir_p( $this->test_dir );
	}

	public function tearDown(): void {
		remove_filter( 'intermediate_image_sizes_advanced', array( $this, 'only_thumbnail' ), PHP_INT_MAX );
		remove_filter( 'wp_update_attachment_metadata', array( $this, 'count_update_filter' ), 10 );
		remove_filter( 'wp_update_attachment_metadata', array( $this, 'arm_filtered_commit_lie' ), PHP_INT_MAX );
		remove_filter( 'get_post_metadata', array( $this, 'lie_about_committed_metadata' ), 10 );
		remove_filter( 'update_post_metadata', array( $this, 'short_circuit_metadata_commit' ), 10 );
		if ( $this->attachment_id ) {
			wp_delete_attachment( $this->attachment_id, true );
		}
		if ( is_dir( $this->test_dir ) ) {
			$entries = scandir( $this->test_dir );
			foreach ( false === $entries ? array() : $entries as $entry ) {
				if ( '.' !== $entry && '..' !== $entry && is_file( $this->test_dir . '/' . $entry ) ) {
					wp_delete_file( $this->test_dir . '/' . $entry );
				}
			}
			@rmdir( $this->test_dir );
		}
		delete_option( YOTM_MEDIA_REFERENCE_STATE_OPTION );
		delete_option( YOTM_MEDIA_SOURCE_DIRTY_OPTION );
		wp_set_current_user( 0 );
		unset( $GLOBALS['yotm_job_logical_locks'] );
		yotm_data_lifecycle_release_request_fences();
		parent::tearDown();
	}

	public function test_force_commits_one_filtered_payload_and_preserves_current_full() {
		add_filter( 'intermediate_image_sizes_advanced', array( $this, 'only_thumbnail' ), PHP_INT_MAX );
		$full                = $this->test_dir . '/force-source.jpg';
		$this->attachment_id = $this->create_image_attachment( $full );
		$metadata            = wp_generate_attachment_metadata( $this->attachment_id, $full );
		$this->assertIsArray( $metadata );
		$metadata['yotm_custom'] = array( 'keep' => true );
		$this->assertNotFalse( wp_update_attachment_metadata( $this->attachment_id, $metadata ) );
		$this->assertTrue( yotm_media_source_sync_attachment( $this->attachment_id, null, true ) );
		$full_hash = hash_file( 'sha256', $full );

		$job = yotm_job_create(
			'regenerate',
			array(
				'force_all'      => 1,
				'only_missing'   => 0,
				'discovery_done' => 1,
			),
			array(
				'status'       => 'running',
				'phase'        => 'regenerate',
				'counter_mode' => 'item_v2',
			)
		);
		$this->assertIsArray( $job );
		$this->assertTrue( yotm_job_add_item( $job['id'], 'force:' . $this->attachment_id, array( 'attachment_id' => $this->attachment_id ) ) );
		$worker = yotm_job_acquire_worker( $job['id'], array( 'running' ), array( 'regenerate' ) );
		$this->assertIsArray( $worker );
		$item = yotm_job_claim_items( $worker, 1 )[0];
		add_filter( 'wp_update_attachment_metadata', array( $this, 'count_update_filter' ), 10, 2 );

		$result = yotm_regenerate_force_attachment( $this->attachment_id, $item, $worker );
		remove_filter( 'wp_update_attachment_metadata', array( $this, 'count_update_filter' ), 10 );
		$this->assertSame( 'regenerated', $result['status'], $result['message'] );
		$this->assertSame( 1, $this->update_filter_calls );
		$this->assertSame( $full_hash, hash_file( 'sha256', $full ) );
		$stored = get_metadata_raw( 'post', $this->attachment_id, '_wp_attachment_metadata', true );
		$this->assertSame( array( 'keep' => true ), $stored['yotm_custom'] );
		$this->assertArrayHasKey( 'thumbnail', $stored['sizes'] );
		$current_item = yotm_job_get_item_by_key( $job['id'], 'force:' . $this->attachment_id );
		$this->assertSame( 'cleanup_complete', $current_item['payload']['regeneration_journal']['phase'] );
		yotm_job_release_worker( $worker );
	}

	public function test_existing_unreferenced_destination_is_never_replaceable() {
		$destination = $this->test_dir . '/unmapped-150x150.jpg';
		file_put_contents( $destination, 'unmapped-bytes' );
		$entry    = array( 'destination' => yotm_media_source_canonical_path( $destination ) );
		$snapshot = array( 'attachment_id' => 12345 );
		$result   = yotm_regenerate_destination_prestate( $entry, $snapshot, array(), $this->test_dir );
		$this->assertWPError( $result );
		$this->assertSame( 'yotm_regenerate_unmapped_destination', $result->get_error_code() );
		$this->assertSame( 'unmapped-bytes', file_get_contents( $destination ) );
	}

	public function test_rollback_removes_only_an_exact_promoted_artifact() {
		$destination = $this->test_dir . '/promoted-100x100.jpg';
		file_put_contents( $destination, 'plugin-promoted' );
		$journal = array(
			'destinations' => array(
				array(
					'destination'   => $destination,
					'mode'          => 'expected_absent',
					'old_absent'    => true,
					'promoted'      => true,
					'promoted_hash' => hash_file( 'sha256', $destination ),
				),
			),
		);
		$this->assertTrue( yotm_regenerate_rollback( $journal ) );
		$this->assertFileDoesNotExist( $destination );

		file_put_contents( $destination, 'unexpected-owner' );
		$this->assertFalse( yotm_regenerate_rollback( $journal ) );
		$this->assertSame( 'unexpected-owner', file_get_contents( $destination ) );
	}

	public function test_filtered_commit_readback_cannot_mint_success() {
		add_filter( 'intermediate_image_sizes_advanced', array( $this, 'only_thumbnail' ), PHP_INT_MAX );
		$full                = $this->test_dir . '/filtered-commit.jpg';
		$this->attachment_id = $this->create_image_attachment( $full );
		$metadata            = wp_generate_attachment_metadata( $this->attachment_id, $full );
		$this->assertIsArray( $metadata );
		$metadata['yotm_commit_sentinel'] = 'raw-old';
		$this->assertNotFalse( wp_update_attachment_metadata( $this->attachment_id, $metadata ) );
		$this->assertTrue( yotm_media_source_sync_attachment( $this->attachment_id, null, true ) );
		$old_rows = yotm_media_reference_raw_postmeta_rows( $this->attachment_id, '_wp_attachment_metadata' );
		$this->assertIsArray( $old_rows );
		$this->assertCount( 1, $old_rows );

		$job = yotm_job_create(
			'regenerate',
			array(
				'force_all'      => 1,
				'only_missing'   => 0,
				'discovery_done' => 1,
			),
			array(
				'status'       => 'running',
				'phase'        => 'regenerate',
				'counter_mode' => 'item_v2',
			)
		);
		$this->assertIsArray( $job );
		$this->assertTrue( yotm_job_add_item( $job['id'], 'force-filter:' . $this->attachment_id, array( 'attachment_id' => $this->attachment_id ) ) );
		$worker = yotm_job_acquire_worker( $job['id'], array( 'running' ), array( 'regenerate' ) );
		$this->assertIsArray( $worker );
		$item = yotm_job_claim_items( $worker, 1 )[0];

		$this->filtered_commit_attachment_id = $this->attachment_id;
		add_filter( 'wp_update_attachment_metadata', array( $this, 'arm_filtered_commit_lie' ), PHP_INT_MAX, 2 );

		$result = yotm_regenerate_force_attachment( $this->attachment_id, $item, $worker );
		$this->assertSame( 'failed', $result['status'] );
		$this->assertNotEmpty( $this->filtered_commit_metadata );
		$this->assertSame( $this->filtered_commit_metadata, get_post_meta( $this->attachment_id, '_wp_attachment_metadata', true ) );
		$current_rows = yotm_media_reference_raw_postmeta_rows( $this->attachment_id, '_wp_attachment_metadata' );
		$this->assertIsArray( $current_rows );
		$this->assertCount( 1, $current_rows );
		$this->assertSame( $old_rows[0]['value'], $current_rows[0]['value'] );
		yotm_job_release_worker( $worker );
	}

	public function only_thumbnail( $sizes ) {
		return isset( $sizes['thumbnail'] ) ? array( 'thumbnail' => $sizes['thumbnail'] ) : array();
	}

	public function count_update_filter( $metadata ) {
		++$this->update_filter_calls;
		return $metadata;
	}

	public function arm_filtered_commit_lie( $metadata, $attachment_id ) {
		remove_filter( 'wp_update_attachment_metadata', array( $this, 'arm_filtered_commit_lie' ), PHP_INT_MAX );
		$metadata['yotm_commit_sentinel']    = 'filtered-final';
		$this->filtered_commit_attachment_id = (int) $attachment_id;
		$this->filtered_commit_metadata      = $metadata;
		add_filter( 'get_post_metadata', array( $this, 'lie_about_committed_metadata' ), 10, 5 );
		add_filter( 'update_post_metadata', array( $this, 'short_circuit_metadata_commit' ), 10, 5 );
		return $metadata;
	}

	public function lie_about_committed_metadata( $check, $object_id, $meta_key, $single, $meta_type ) {
		unset( $meta_type, $single );
		if ( $this->filtered_commit_attachment_id !== (int) $object_id || '_wp_attachment_metadata' !== $meta_key ) {
			return $check;
		}
		return array( $this->filtered_commit_metadata );
	}

	public function short_circuit_metadata_commit( $check, $object_id, $meta_key, $meta_value, $prev_value ) {
		unset( $meta_value, $prev_value );
		return $this->filtered_commit_attachment_id === (int) $object_id && '_wp_attachment_metadata' === $meta_key ? true : $check;
	}

	private function create_image_attachment( $destination ) {
		$source = DIR_TESTDATA . '/images/2004-07-22-DSC_0007.jpg';
		$this->assertTrue( copy( $source, $destination ) );
		$attachment_id = wp_insert_attachment(
			array(
				'post_mime_type' => 'image/jpeg',
				'post_title'     => 'Force transaction fixture',
				'post_status'    => 'inherit',
			),
			$destination
		);
		$this->assertIsInt( $attachment_id );
		// phpcs:ignore WordPress.WP.GetMetaSingle.Missing -- The transaction requires proving exactly one raw row.
		$this->assertSame( 1, count( get_metadata_raw( 'post', $attachment_id, '_wp_attached_file' ) ) );
		return $attachment_id;
	}
}
