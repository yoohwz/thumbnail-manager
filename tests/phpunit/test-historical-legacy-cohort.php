<?php
/**
 * Historical legacy cohort safety tests.
 *
 * @package Thumbnail_Manager
 */

class YOTM_Historical_Legacy_Cohort_Test extends WP_UnitTestCase {
	/**
	 * Historical policy version 2 is exact, while version 1 cannot authorize it.
	 */
	public function test_historical_policy_requires_exact_version_two_state() {
		$payload = $this->historical_policy_payload();
		$this->assertTrue( yotm_historical_policy_validate( $payload ) );

		$tampered = $payload;
		$tampered['legacy_policy']['constants']['min_families'] = 4;
		$this->assertWPError( yotm_historical_policy_validate( $tampered ) );

		$version_one                          = $payload;
		$version_one['legacy_policy']         = array(
			'version' => 1,
			'enabled' => 1,
			'hash'    => '',
		);
		$version_one['legacy_policy']['hash'] = yotm_legacy_policy_hash( $version_one );
		$error                                = yotm_historical_policy_validate( $version_one );
		$this->assertWPError( $error );
		$this->assertSame( 'yotm_historical_policy_unavailable', $error->get_error_code() );
	}

	/**
	 * Exact ratios use reduced integer buckets without float rounding.
	 */
	public function test_historical_ratio_key_is_exact_and_reduced() {
		$this->assertSame( '4:3', yotm_historical_ratio_key( 4000, 3000 ) );
		$this->assertSame( '4:3', yotm_historical_ratio_key( 800, 600 ) );
		$this->assertSame( '4032:3025', yotm_historical_ratio_key( 4032, 3025 ) );
		$this->assertSame( '', yotm_historical_ratio_key( 0, 200 ) );
	}

	/**
	 * Shuffled/replayed evidence must seal to one deterministic exact proof.
	 */
	public function test_cohort_seal_is_order_independent_and_replay_safe() {
		$anchors      = array(
			$this->witness( 'historical_anchor_v1', 1, '4:3', 'dir-a', 'a1' ),
			$this->witness( 'historical_anchor_v1', 2, '3:2', 'dir-b', 'a2' ),
			$this->witness( 'historical_anchor_v1', 6, '5:4', 'dir-c', 'a3' ),
		);
		$observations = array(
			$this->witness( 'historical_observation_v1', 3, '16:9', 'dir-a', 'o1' ),
			$this->witness( 'historical_observation_v1', 4, '1:1', 'dir-b', 'o2' ),
			$this->witness( 'historical_observation_v1', 5, '5:4', 'dir-a', 'o3' ),
			$this->witness( 'historical_observation_v1', 7, '7:5', 'dir-c', 'o4' ),
		);
		$policy_hash  = hash( 'sha256', 'historical-policy' );
		$first        = yotm_historical_seal_cohort( $anchors, $observations, 'retired_square', $policy_hash );
		$this->assertIsArray( $first );

		$replayed = array_merge( $observations, array( $observations[0], $observations[1] ) );
		shuffle( $anchors );
		shuffle( $replayed );
		$second = yotm_historical_seal_cohort( $anchors, $replayed, 'retired_square', $policy_hash );
		$this->assertSame( $first, $second );
		$this->assertTrue( yotm_validate_historical_cohort_proof( $second, $policy_hash ) );
	}

	/**
	 * Every fixed threshold is required, including distinct attachment/source families.
	 */
	public function test_cohort_rejects_insufficient_or_duplicate_families() {
		$anchors      = array(
			$this->witness( 'historical_anchor_v1', 1, '4:3', 'dir-a', 'a1' ),
			$this->witness( 'historical_anchor_v1', 2, '3:2', 'dir-b', 'a2' ),
		);
		$observations = array(
			$this->witness( 'historical_observation_v1', 3, '16:9', 'dir-a', 'o1' ),
			$this->witness( 'historical_observation_v1', 4, '1:1', 'dir-b', 'o2' ),
		);
		$this->assertWPError( yotm_historical_seal_cohort( $anchors, $observations, 'retired_square', hash( 'sha256', 'policy' ) ) );

		$observations[]                   = $this->witness( 'historical_observation_v1', 5, '5:4', 'dir-a', 'o3' );
		$observations[2]['attachment_id'] = 4;
		$this->assertWPError( yotm_historical_seal_cohort( $anchors, $observations, 'retired_square', hash( 'sha256', 'policy' ) ) );
	}

	/**
	 * Promoted historical items remain disk-only and proof tampering is rejected.
	 */
	public function test_promoted_item_has_zero_metadata_authority_and_tampering_fails() {
		$policy_hash  = hash( 'sha256', 'historical-policy' );
		$anchors      = array(
			$this->witness( 'historical_anchor_v1', 1, '4:3', 'dir-a', 'a1' ),
			$this->witness( 'historical_anchor_v1', 2, '3:2', 'dir-b', 'a2' ),
		);
		$observations = array(
			$this->witness( 'historical_observation_v1', 3, '16:9', 'dir-a', 'o1' ),
			$this->witness( 'historical_observation_v1', 4, '1:1', 'dir-b', 'o2' ),
			$this->witness( 'historical_observation_v1', 5, '5:4', 'dir-a', 'o3' ),
		);
		$proof        = yotm_historical_seal_cohort( $anchors, $observations, 'retired_square', $policy_hash );
		$this->assertIsArray( $proof );

		$observation                       = $observations[0];
		$observation['ownership_schema']   = 'historical_observation_v1';
		$observation['legacy_policy_hash'] = $policy_hash;
		$observation['path']               = '/uploads/dir-a/photo-150x150.jpg';
		$observation['observed_mime']      = 'image/jpeg';
		$observation['selection_meta_id']  = 123;
		$item                              = yotm_build_historical_legacy_item( $observation, $proof );
		$this->assertIsArray( $item );
		$this->assertSame( 'historical_legacy_generated_v1', $item['ownership_schema'] );
		$this->assertSame( 0, $item['remove_metadata'] );
		$this->assertSame( array(), $item['metadata_refs'] );
		$this->assertSame( 123, $item['ownership_evidence'][0]['selection_meta_id'] );

		$tampered                                  = $proof;
		$tampered['witnesses'][0]['directory_key'] = hash( 'sha256', 'changed' );
		$this->assertWPError( yotm_validate_historical_cohort_proof( $tampered, $policy_hash ) );
	}

	/**
	 * Return one exact version-two policy payload.
	 *
	 * @return array
	 */
	private function historical_policy_payload() {
		$payload                          = array(
			'discover_orphans'   => 1,
			'keep'               => array(),
			'remove'             => array(),
			'sizes'              => array(),
			'selector'           => 'attached_meta_v2',
			'selection_meta_max' => 100,
			'selection_subpaths' => array(),
			'scan_bases'         => array( '/uploads' ),
			'legacy_policy'      => array(
				'version'                         => 2,
				'current_disabled_enabled'        => 0,
				'historical_unregistered_enabled' => 1,
				'constants'                       => yotm_historical_cohort_constants(),
				'ratio_schema'                    => 'integer_gcd_v1',
				'hash'                            => '',
			),
		);
		$payload['legacy_policy']['hash'] = yotm_legacy_policy_hash( $payload );
		return $payload;
	}

	/**
	 * Build one synthetic exact witness.
	 *
	 * @param string $kind Evidence kind.
	 * @param int    $attachment_id Attachment ID.
	 * @param string $ratio Source ratio bucket.
	 * @param string $directory Directory bucket seed.
	 * @param string $seed Unique witness seed.
	 * @return array
	 */
	private function witness( $kind, $attachment_id, $ratio, $directory, $seed ) {
		return array(
			'evidence_kind'          => $kind,
			'ownership_schema'       => $kind,
			'historical_signature'   => '150x150:image/jpeg',
			'historical_family_key'  => hash( 'sha256', 'family-' . $seed ),
			'historical_witness_key' => hash( 'sha256', 'witness-' . $seed ),
			'attachment_id'          => $attachment_id,
			'source_path_hash'       => hash( 'sha256', 'path-' . $seed ),
			'source_file_hash'       => hash( 'sha256', 'file-' . $seed ),
			'source_ratio_key'       => $ratio,
			'directory_key'          => hash( 'sha256', $directory ),
		);
	}
}
