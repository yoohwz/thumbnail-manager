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
}
