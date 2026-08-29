<?php

/**
 * Recommendation evidence and safety tests.
 */
class YOTM_Recommendations_Test extends WP_UnitTestCase {
	/**
	 * Custom registered sizes added by a test.
	 *
	 * @var string[]
	 */
	private $added_sizes = array();

	public function tearDown(): void {
		foreach ( $this->added_sizes as $name ) {
			remove_image_size( $name );
		}
		$this->added_sizes = array();
		parent::tearDown();
	}

	public function test_recommendation_modules_enforce_application_transport_and_compatibility_boundaries() {
		$root               = dirname( __DIR__, 2 );
		$application_module = wp_normalize_path( $root . '/inc/application/recommendations.php' );
		$transport_module   = wp_normalize_path( $root . '/inc/handle-recommendations.php' );
		$jobs_compat_module = wp_normalize_path( $root . '/inc/job-storage.php' );
		$bootstrap_module   = wp_normalize_path( $root . '/thumbnail-manager.php' );

		$application_functions = array(
			'yotm_recommend_prepare_application',
			'yotm_recommend_batch_application',
			'yotm_recommend_scan_attachment_metadata_ids',
			'yotm_recommend_scan_content_ids',
			'yotm_recommend_registered_sizes_signature',
			'yotm_recommend_classify_item',
			'yotm_recommend_build_evidence',
			'yotm_build_recommendation_result',
			'yotm_recommendation_result_for_response',
			'yotm_recommendation_public_result_application',
			'yotm_build_recommendation_progress_response',
		);
		foreach ( $application_functions as $function ) {
			$reflection = new ReflectionFunction( $function );
			$this->assertSame( $application_module, wp_normalize_path( $reflection->getFileName() ), $function );
		}

		foreach ( array( 'yotm_recommend_prepare', 'yotm_recommend_batch', 'yotm_recommend_send_application_outcome' ) as $function ) {
			$reflection = new ReflectionFunction( $function );
			$this->assertSame( $transport_module, wp_normalize_path( $reflection->getFileName() ), $function );
		}
		$reflection = new ReflectionFunction( 'yotm_job_public_recommendation_result' );
		$this->assertSame( $jobs_compat_module, wp_normalize_path( $reflection->getFileName() ) );
		$this->assertSame( 10, has_action( 'wp_ajax_yotm_recommend_prepare', 'yotm_recommend_prepare' ) );
		$this->assertSame( 10, has_action( 'wp_ajax_yotm_recommend_batch', 'yotm_recommend_batch' ) );

		$application_source = file_get_contents( $application_module );
		$transport_source   = file_get_contents( $transport_module );
		$jobs_compat_source = file_get_contents( $jobs_compat_module );
		$bootstrap_source   = file_get_contents( $bootstrap_module );

		$this->assertDoesNotMatchRegularExpression( '/\\$_POST|wp_send_json_|current_user_can|check_ajax_referer|add_action\\s*\\(/', $application_source );
		$this->assertDoesNotMatchRegularExpression( '/\\b(?:unlink|rename|wp_delete_attachment|update_post_meta|delete_post_meta)\\s*\\(/', $application_source );
		$this->assertDoesNotMatchRegularExpression( '/yotm_job_(?:create|get|acquire_worker|worker_update)|yotm_build_recommendation_result|yotm_recommend_scan_/', $transport_source );
		$this->assertDoesNotMatchRegularExpression( '/is_callable|call_user_func|yotm_recommendation_result_for_response/', $jobs_compat_source );
		$this->assertStringContainsString( 'yotm_recommendation_public_result_application( $result, $projector )', $jobs_compat_source );
		$this->assertLessThan(
			strpos( $bootstrap_source, 'inc/handle-recommendations.php' ),
			strpos( $bootstrap_source, 'inc/application/recommendations.php' )
		);
	}

	public function test_application_prepare_and_completed_batch_preserve_progress_contract() {
		$GLOBALS['yotm_job_storage_readiness'] = array();
		delete_option( 'yotm_job_db_version' );
		delete_transient( 'yotm_job_db_migration_failure' );
		$this->assertTrue( yotm_install_job_tables() );
		$missing = yotm_recommend_batch_application( 'missing-recommendation-token', 100 );
		$this->assertFalse( $missing['success'] );
		$this->assertSame( 400, $missing['status'] );
		$this->assertArrayHasKey( 'msg', $missing['data'] );

		$prepared = yotm_recommend_prepare_application();
		$this->assertTrue( $prepared['success'] );
		$this->assertSame( 200, $prepared['status'] );
		$this->assertFalse( $prepared['data']['done'] );
		$this->assertSame( 'metadata', $prepared['data']['phase'] );

		$job                   = yotm_job_get( $prepared['data']['token'] );
		$payload               = $job['payload'];
		$payload['scan_phase'] = 'completed';
		$payload['result']     = array(
			'items'            => array(),
			'recommended_keep' => array( 'thumbnail' ),
		);
		$completed             = yotm_job_update(
			$job['id'],
			array(
				'payload'   => $payload,
				'status'    => 'completed',
				'phase'     => 'completed',
				'total'     => 1,
				'processed' => 1,
			)
		);
		$this->assertTrue( $completed );
		$job = yotm_job_get_by_id( $job['id'] );
		$this->assertIsArray( $job );

		$outcome = yotm_recommend_batch_application( $job['token'], 100 );
		$this->assertTrue( $outcome['success'] );
		$this->assertSame( 200, $outcome['status'] );
		$this->assertTrue( $outcome['data']['done'] );
		$this->assertSame( 100, $outcome['data']['percent'] );
		$this->assertSame( array( 'thumbnail' ), yotm_job_get_by_id( $job['id'] )['payload']['result']['recommended_keep'] );
	}

	public function test_decision_table_uses_positive_evidence_only() {
		$sizes  = array(
			'thumbnail'             => array(
				'width'  => 150,
				'height' => 150,
				'crop'   => true,
			),
			'woocommerce_thumbnail' => array(
				'width'  => 300,
				'height' => 300,
				'crop'   => true,
			),
			'custom_referenced'     => array(
				'width'  => 640,
				'height' => 480,
				'crop'   => false,
			),
			'custom_generated'      => array(
				'width'  => 800,
				'height' => 600,
				'crop'   => false,
			),
			'custom_unknown'        => array(
				'width'  => 1200,
				'height' => 900,
				'crop'   => false,
			),
		);
		$result = yotm_build_recommendation_result(
			$sizes,
			array(
				'thumbnail'         => array(
					'count' => 2,
					'bytes' => 200,
				),
				'custom_referenced' => array(
					'count' => 3,
					'bytes' => 300,
				),
				'custom_generated'  => array(
					'count' => 4,
					'bytes' => 400,
				),
			),
			array(
				'thumbnail'         => 1,
				'custom_referenced' => 2,
			)
		);
		$items  = $this->index_items( $result['items'] );

		$this->assertSame( 2, $result['schema_version'] );
		$this->assert_item_decision( $items['thumbnail'], 'protected', 'high', 'enable' );
		$this->assert_item_decision( $items['woocommerce_thumbnail'], 'protected', 'high', 'enable' );
		$this->assert_item_decision( $items['custom_referenced'], 'detected_reference', 'medium', 'enable' );
		$this->assert_item_decision( $items['custom_generated'], 'unknown', 'low', 'preserve' );
		$this->assert_item_decision( $items['custom_unknown'], 'unknown', 'low', 'preserve' );
		$this->assertSame( 2, $result['protected_count'] );
		$this->assertSame( 1, $result['detected_reference_count'] );
		$this->assertSame( 2, $result['unknown_count'] );
		$this->assertSame( 900, $result['generated_bytes'] );
		$this->assertSame( 0, $result['unused_count'] );
		$this->assertSame( '0 B', $result['savings'] );
		$this->assertArrayNotHasKey( 'recommended_keep', $result );
	}

	public function test_evidence_codes_are_deterministic_and_absence_is_inconclusive() {
		$result = yotm_build_recommendation_result(
			array(
				'custom_generated' => array(
					'width'  => 640,
					'height' => 480,
					'crop'   => false,
				),
				'custom_empty'     => array(
					'width'  => 800,
					'height' => 600,
					'crop'   => false,
				),
			),
			array(
				'custom_generated' => array(
					'count' => 2,
					'bytes' => 512,
				),
			),
			array()
		);
		$items  = $this->index_items( $result['items'] );

		$this->assertSame(
			array( 'content_reference_not_detected', 'generated_metadata_present' ),
			wp_list_pluck( $items['custom_generated']['evidence'], 'code' )
		);
		$this->assertSame(
			array( 'content_reference_not_detected', 'generated_metadata_not_detected' ),
			wp_list_pluck( $items['custom_empty']['evidence'], 'code' )
		);
		foreach ( $items as $item ) {
			$this->assertSame( 'unknown', $item['status'] );
			$this->assertSame( 'preserve', $item['apply_action'] );
			$this->assertStringNotContainsString( 'Disable', $item['recommendation'] );
		}
	}

	public function test_policy_precedence_retains_other_evidence() {
		$result = yotm_build_recommendation_result(
			array(
				'thumbnail' => array(
					'width'  => 150,
					'height' => 150,
					'crop'   => true,
				),
			),
			array(
				'thumbnail' => array(
					'count' => 4,
					'bytes' => 1024,
				),
			),
			array( 'thumbnail' => 3 )
		);
		$item   = $result['items'][0];

		$this->assert_item_decision( $item, 'protected', 'high', 'enable' );
		$this->assertSame(
			array( 'policy_core', 'content_reference_detected', 'generated_metadata_present' ),
			wp_list_pluck( $item['evidence'], 'code' )
		);
	}

	public function test_registered_size_signature_is_order_independent_and_complete() {
		$first     = array(
			'alpha' => array(
				'width'  => 100,
				'height' => 200,
				'crop'   => array( 'left', 'top' ),
			),
			'beta'  => array(
				'width'  => 300,
				'height' => 400,
				'crop'   => false,
			),
		);
		$reordered = array(
			'beta'  => $first['beta'],
			'alpha' => $first['alpha'],
		);

		$this->assertSame( yotm_recommend_registered_sizes_signature( $first ), yotm_recommend_registered_sizes_signature( $reordered ) );

		$changed_width                   = $first;
		$changed_width['alpha']['width'] = 101;
		$this->assertNotSame( yotm_recommend_registered_sizes_signature( $first ), yotm_recommend_registered_sizes_signature( $changed_width ) );

		$changed_crop                  = $first;
		$changed_crop['alpha']['crop'] = array( 'right', 'bottom' );
		$this->assertNotSame( yotm_recommend_registered_sizes_signature( $first ), yotm_recommend_registered_sizes_signature( $changed_crop ) );
	}

	public function test_response_projection_uses_current_registration_without_mutating_result() {
		$persisted = array(
			'schema_version'             => 2,
			'registered_sizes_signature' => 'older-snapshot',
			'items'                      => array(),
		);
		$before    = $persisted;
		$this->add_size( 'tm_aud_new_size', 321, 123, false );

		$projected = yotm_recommendation_result_for_response( $persisted );

		$this->assertContains( 'tm_aud_new_size', $projected['recommended_keep'] );
		$this->assertSame( $projected, yotm_job_public_recommendation_result( $persisted ) );
		$this->assertSame( $before, $persisted );
		$this->assertSame( 'older-snapshot', $projected['registered_sizes_signature'] );
	}

	public function test_completed_progress_response_projects_legacy_and_stale_results() {
		$this->add_size( 'tm_aud_response_size', 444, 222, true );
		$job = array(
			'token'     => 'recommendation-token',
			'type'      => 'recommendation',
			'status'    => 'completed',
			'phase'     => 'completed',
			'total'     => 1,
			'processed' => 1,
			'payload'   => array(
				'scan_phase' => 'completed',
				'result'     => array(
					'items'            => array(),
					'recommended_keep' => array( 'thumbnail' ),
				),
			),
		);

		$response = yotm_build_recommendation_progress_response( $job, true );

		$this->assertContains( 'tm_aud_response_size', $response['result']['recommended_keep'] );
		$this->assertSame( array( 'thumbnail' ), $job['payload']['result']['recommended_keep'] );
	}

	public function test_completed_progress_response_projects_missing_and_scalar_results_safely() {
		$this->add_size( 'tm_aud_malformed_progress', 445, 223, false );

		foreach ( array( '__missing__', 'malformed-result' ) as $stored_result ) {
			$payload = array( 'scan_phase' => 'completed' );
			if ( '__missing__' !== $stored_result ) {
				$payload['result'] = $stored_result;
			}

			$job = array(
				'token'     => 'malformed-recommendation-token',
				'type'      => 'recommendation',
				'status'    => 'completed',
				'phase'     => 'completed',
				'total'     => 1,
				'processed' => 1,
				'payload'   => $payload,
			);

			$response = yotm_build_recommendation_progress_response( $job, true );
			$this->assert_cached_v1_apply_preserves_current_sizes( $response['result'] );
			$this->assertSame( $payload, $job['payload'] );
		}
	}

	public function test_content_reference_patterns_are_bounded_and_count_once_per_post() {
		$matching_post = self::factory()->post->create(
			array( 'post_content' => '<img class="size-custom.name"> {"sizeSlug":"custom.name"} {"image_size":"other-size"}' )
		);
		$other_post    = self::factory()->post->create(
			array( 'post_content' => '{"thumbnail_size":"other-size"}' )
		);
		$usage         = array(
			'custom.name' => 0,
			'other-size'  => 0,
		);

		yotm_recommend_scan_content_ids( array( $matching_post, $other_post ), array_keys( $usage ), $usage );

		$this->assertSame( 1, $usage['custom.name'] );
		$this->assertSame( 2, $usage['other-size'] );
	}

	public function test_compiled_content_matcher_is_equivalent_to_legacy_patterns() {
		$names = array( 'thumbnail', 'custom.name', 'prefix', 'prefix-long', 'under_score', '100x100' );
		$cases = array(
			'',
			'<img class="size-thumbnail">',
			'<img class="size-custom.name other">',
			'<img class="resize-thumbnail">',
			'{"sizeSlug":"prefix-long","size":"under_score"}',
			"{'image_size' : '100x100'}",
			'{"thumbnail_size":"prefix"}{"size":"prefix"}',
			'<div class="size-prefix-longer"></div>',
		);

		mt_srand( 6006 );
		for ( $index = 0; $index < 100; ++$index ) {
			$name    = $names[ mt_rand( 0, count( $names ) - 1 ) ];
			$wrapper = mt_rand( 0, 1 ) ? ' class="size-' . $name . '"' : '{"sizeSlug":"' . $name . '"}';
			$cases[] = str_repeat( 'x ', mt_rand( 0, 8 ) ) . $wrapper . str_repeat( ' y', mt_rand( 0, 8 ) );
		}

		foreach ( $cases as $content ) {
			$expected = array();
			foreach ( $names as $name ) {
				if ( yotm_recommend_content_matches_size( $content, $name ) ) {
					$expected[] = $name;
				}
			}
			$actual = yotm_recommend_extract_content_size_names( $content, $names );
			sort( $expected );
			sort( $actual );
			$this->assertSame( $expected, $actual, 'Matcher drift for: ' . $content );
		}
	}

	public function test_content_batch_uses_one_bulk_content_query() {
		$post_ids = array();
		for ( $index = 0; $index < 6; ++$index ) {
			$post_ids[] = self::factory()->post->create(
				array( 'post_content' => '<img class="size-thumbnail">' )
			);
		}
		$usage = array( 'thumbnail' => 0 );

		global $wpdb;
		$before = (int) $wpdb->num_queries;
		$result = yotm_recommend_scan_content_ids( $post_ids, array_keys( $usage ), $usage );
		$after  = (int) $wpdb->num_queries;

		$this->assertTrue( $result );
		$this->assertSame( 6, $usage['thumbnail'] );
		$this->assertLessThanOrEqual( 1, $after - $before );
	}

	/**
	 * Add and remember a registered image size.
	 *
	 * @param string     $name Size name.
	 * @param int        $width Width.
	 * @param int        $height Height.
	 * @param bool|array $crop Crop definition.
	 */
	private function add_size( $name, $width, $height, $crop ) {
		add_image_size( $name, $width, $height, $crop );
		$this->added_sizes[] = $name;
	}

	/**
	 * Index result items by name.
	 *
	 * @param array $items Recommendation items.
	 * @return array
	 */
	private function index_items( $items ) {
		$indexed = array();
		foreach ( $items as $item ) {
			$indexed[ $item['name'] ] = $item;
		}

		return $indexed;
	}

	/**
	 * Assert machine decision fields.
	 *
	 * @param array  $item Item.
	 * @param string $status Expected status.
	 * @param string $confidence Expected confidence.
	 * @param string $action Expected action.
	 */
	private function assert_item_decision( $item, $status, $confidence, $action ) {
		$this->assertSame( $status, $item['status'] );
		$this->assertSame( $confidence, $item['confidence'] );
		$this->assertSame( $action, $item['apply_action'] );
	}

	/**
	 * Simulate cached-v1 Apply and prove every current size is re-enabled.
	 *
	 * @param array $result Browser-facing result.
	 */
	private function assert_cached_v1_apply_preserves_current_sizes( $result ) {
		$current = array_keys( yotm_get_registered_sizes() );
		$checked = array_fill_keys( $current, false );

		foreach ( $result['recommended_keep'] ?? array() as $name ) {
			if ( array_key_exists( $name, $checked ) ) {
				$checked[ $name ] = true;
			}
		}

		$this->assertSame( array(), array_keys( $checked, false, true ) );
	}
}
