<?php
/**
 * Recommendation linked evidence and unique file observations.
 */
class YOTM_Recommendation_Evidence_Test extends WP_UnitTestCase {
	private $files = array();
	private $sizes = array( 'tm_evidence_a', 'tm_evidence_b' );

	public function setUp(): void {
		parent::setUp();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		foreach ( $this->sizes as $name ) {
			add_image_size( $name, 20, 20, true );
		}
		$GLOBALS['yotm_job_storage_readiness'] = array();
		$this->assertTrue( yotm_install_job_tables() );
	}

	public function tearDown(): void {
		yotm_job_release_all_workers();
		foreach ( $this->files as $file ) {
			if ( file_exists( $file ) ) {
				chmod( $file, 0644 );
				unlink( $file );
			}
		}
		foreach ( $this->sizes as $name ) {
			remove_image_size( $name );
		}
		parent::tearDown();
	}

	private function upload_file( $content ) {
		$upload = wp_upload_bits( wp_unique_filename( wp_upload_dir()['path'], 'tm-evidence.jpg' ), null, $content );
		$this->assertEmpty( $upload['error'] );
		$this->files[] = $upload['file'];
		return $upload['file'];
	}

	private function attachment( $source, $sizes ) {
		$id = self::factory()->attachment->create( array( 'post_mime_type' => 'image/jpeg' ) );
		update_attached_file( $id, $source );
		update_post_meta(
			$id,
			'_wp_attachment_metadata',
			array(
				'file'  => _wp_relative_upload_path( $source ),
				'sizes' => $sizes,
			)
		);
		return $id;
	}

	public function test_batched_statistics_distinguish_entries_unique_paths_and_unverified_evidence() {
		$source     = $this->upload_file( 'source' );
		$shared     = $this->upload_file( '1234567' );
		$unreadable = $this->upload_file( 'not-readable' );
		chmod( $unreadable, 0000 );
		$this->assertFalse( is_readable( $unreadable ), 'Fixture must be unreadable to the runtime user.' );
		$ids             = array(
			$this->attachment(
				$source,
				array(
					'tm_evidence_a' => array( 'file' => basename( $shared ) ),
					'tm_evidence_b' => array( 'file' => basename( $shared ) ),
				)
			),
			$this->attachment(
				$source,
				array(
					'tm_evidence_a' => array( 'file' => basename( $shared ) ),
					'tm_evidence_b' => array( 'file' => 'tm-evidence-missing.jpg' ),
				)
			),
			$this->attachment(
				$source,
				array(
					'tm_evidence_a' => array( 'file' => 'unverified.jpg' ),
					'tm_evidence_b' => array( 'file' => basename( $unreadable ) ),
				)
			),
		);
		$metadata_filter = static function ( $metadata, $attachment_id ) use ( $ids ) {
			if ( $attachment_id === $ids[2] ) {
				$metadata['sizes']['tm_evidence_a']['file'] = '../outside.jpg';
			}
			return $metadata;
		};
		add_filter( 'wp_get_attachment_metadata', $metadata_filter, 10, 2 );
		$prepared = yotm_recommend_prepare_application();
		$this->assertTrue( $prepared['success'] );
		$job    = yotm_job_get( $prepared['data']['token'] );
		$worker = yotm_job_acquire_worker( $job['id'], array( 'scanning' ), array( 'metadata' ) );
		$usage  = $job['payload']['metadata_usage'];
		$this->assertTrue( yotm_recommend_scan_attachment_metadata_ids( $ids, $usage, $worker ) );
		// Replaying the same batch retains one observation per canonical path.
		$replay_usage = $job['payload']['metadata_usage'];
		$this->assertTrue( yotm_recommend_scan_attachment_metadata_ids( $ids, $replay_usage, $worker ) );
		$this->assertSame( $usage, $replay_usage );
		remove_filter( 'wp_get_attachment_metadata', $metadata_filter, 10 );
		$payload                        = $job['payload'];
		$payload['metadata_usage']      = $usage;
		$payload['attachments_scanned'] = 3;
		$stats                          = yotm_recommend_statistics( $job['id'], $payload );
		$this->assertSame( 3, $stats['attachments_scanned'] );
		$this->assertSame( 6, $stats['metadata_entries'], wp_json_encode( array_map( 'wp_get_attachment_metadata', $ids ) ) );
		$this->assertSame( 1, $stats['checked_files'] );
		$this->assertSame( 7, $stats['checked_bytes'] );
		$this->assertSame( 1, $stats['missing_files'] );
		$this->assertSame( 1, $stats['unreadable_files'] );
		$this->assertSame( 1, $stats['unverified_entries'] );
		$this->assertSame( 14, $usage['tm_evidence_a']['bytes'] );
		$this->assertSame( 7, $usage['tm_evidence_b']['bytes'] );
		$result = yotm_build_recommendation_result( $payload['sizes'], $usage, array(), array( 'statistics' => $stats ) );
		$this->assertSame( 7, $result['generated_bytes'] );
		$this->assertContains( 'observed_during_scan_not_live_snapshot', $stats['limitations'] );
		$this->assertSame( 0, yotm_job_item_counters( $job['id'] )['processed'] );
		$this->assertSame( 0, yotm_job_item_counters( $job['id'] )['remaining'] );
		$this->assertTrue( yotm_job_worker_update( $worker, array( 'status' => 'cancelled' ) ) );
		$this->assertWPError( yotm_recommend_record_file_observation( $worker, $source, 'checked', 6 ) );
	}

	public function test_completed_scan_exposes_unique_statistics_without_private_file_paths() {
		$source = $this->upload_file( 'original' );
		$shared = $this->upload_file( '1234567' );
		$this->attachment(
			$source,
			array(
				'tm_evidence_a' => array( 'file' => basename( $shared ) ),
				'tm_evidence_b' => array( 'file' => basename( $shared ) ),
			)
		);
		$prepared = yotm_recommend_prepare_application();
		for ( $batch = 0; $batch < 10; ++$batch ) {
			$outcome = yotm_recommend_batch_application( $prepared['data']['token'], 10 );
			$this->assertTrue( $outcome['success'] );
			if ( $outcome['data']['done'] ) {
				break; }
			yotm_job_release_all_workers();
		}
		$this->assertTrue( $outcome['data']['done'] );
		$this->assertSame( 1, $outcome['data']['result']['statistics']['checked_files'] );
		$this->assertSame( 7, $outcome['data']['result']['generated_bytes'] );
		$this->assertStringNotContainsString( dirname( $source ), wp_json_encode( $outcome['data']['result'] ) );
	}

	public function test_malformed_saved_items_project_as_empty_without_disabling_current_sizes() {
		$result = yotm_recommendation_result_for_response( array( 'items' => 'malformed' ) );
		$this->assertSame( array(), $result['items'] );
		$this->assertContains( 'tm_evidence_a', $result['recommended_keep'] );
	}

	public function test_content_samples_are_bounded_identify_hints_and_recheck_current_permission() {
		$post_ids = array();
		for ( $i = 0; $i < 8; ++$i ) {
			$post_ids[] = self::factory()->post->create( array( 'post_content' => '<img class="size-tm_evidence_a"> {"sizeSlug":"tm_evidence_a"}' ) );
		}
		$usage   = array( 'tm_evidence_a' => 0 );
		$samples = array();
		$this->assertTrue( yotm_recommend_scan_content_ids( array_slice( $post_ids, 0, 4 ), array_keys( $usage ), $usage, $samples ) );
		$this->assertTrue( yotm_recommend_scan_content_ids( array_slice( $post_ids, 4 ), array_keys( $usage ), $usage, $samples ) );
		$this->assertSame( 8, $usage['tm_evidence_a'] );
		$this->assertCount( 5, $samples['tm_evidence_a'] );
		$this->assertSame( array( 'class_size_hint', 'json_size_hint' ), $samples['tm_evidence_a'][ $post_ids[0] ]['kinds'] );
		$stored = array(
			'private_file_evidence' => '/secret/path',
			'items'                 => array(
				array(
					'name'            => 'tm_evidence_a',
					'path'            => '/secret/path',
					'content_samples' => array_values( $samples['tm_evidence_a'] ),
					'evidence'        => array(
						array(
							'code' => 'content_reference_detected',
							'path' => '/secret/path',
						),
					),
				),
			),
		);
		$public = yotm_recommendation_result_for_response( $stored );
		$this->assertCount( 5, $public['items'][0]['content_samples'] );
		$this->assertStringContainsString( 'post.php?post=', $public['items'][0]['content_samples'][0]['url'] );
		$this->assertStringNotContainsString( '/secret/path', wp_json_encode( $public ) );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$restricted = yotm_recommendation_result_for_response( $stored );
		$this->assertSame( array(), $restricted['items'][0]['content_samples'] );
		$this->assertCount( 5, $stored['items'][0]['content_samples'] );
	}
}
