<?php
/**
 * Size Management application and options-seam tests.
 *
 * @package Thumbnail_Manager
 */

class YOTM_Size_Management_Test extends WP_UnitTestCase {

	/**
	 * Restore test-owned settings and registered sizes.
	 */
	public function tear_down() {
		delete_option( 'yotm_disabled_sizes' );
		remove_image_size( 'yotm_application_test_size' );
		parent::tear_down();
	}

	/**
	 * The options seam preserves the option name, value, and autoload contract.
	 */
	public function test_options_seam_preserves_storage_contract() {
		$this->assertSame( 'missing', yotm_get_disabled_sizes_option( 'missing' ) );

		yotm_update_disabled_sizes_option( array( 'thumbnail' ) );

		$this->assertSame( array( 'thumbnail' ), yotm_get_disabled_sizes_option() );
		$this->assertArrayHasKey( 'yotm_disabled_sizes', wp_load_alloptions( true ) );
	}

	/**
	 * Persisted disabled names derive only from current registered-size truth.
	 */
	public function test_application_derives_persistence_from_authoritative_registered_sizes() {
		add_image_size( 'yotm_application_test_size', 321, 123, true );
		$registered = array_keys( yotm_get_registered_sizes() );
		$enabled    = array( 'thumbnail', 'unknown-forged-size', 'thumbnail' );
		$expected   = array_values( array_diff( $registered, $enabled ) );

		$outcome = yotm_size_management_save_application( $enabled, true );

		$this->assertTrue( $outcome['success'] );
		$this->assertSame( 200, $outcome['status'] );
		$this->assertSame( 3, $outcome['data']['enabled_count'] );
		$this->assertSame( count( $expected ), $outcome['data']['disabled_count'] );
		$this->assertSame( $expected, $outcome['data']['disabled'] );
		$this->assertSame( $expected, get_option( 'yotm_disabled_sizes' ) );
		$this->assertTrue( $outcome['data']['run_regenerate_after_save'] );
		$this->assertNotContains( 'unknown-forged-size', $outcome['data']['disabled'], true );
	}

	/**
	 * An empty selection disables every authoritative registered size.
	 */
	public function test_application_preserves_empty_selection_behavior() {
		$registered = array_keys( yotm_get_registered_sizes() );

		$outcome = yotm_size_management_save_application( array(), false );

		$this->assertSame( 0, $outcome['data']['enabled_count'] );
		$this->assertSame( $registered, $outcome['data']['disabled'] );
		$this->assertFalse( $outcome['data']['run_regenerate_after_save'] );
	}

	/**
	 * The upload-generation hook consumes the same persisted options seam.
	 */
	public function test_disabled_sizes_filter_uses_saved_decision() {
		yotm_update_disabled_sizes_option( array( 'medium' ) );
		$sizes = array(
			'thumbnail' => array( 'width' => 150 ),
			'medium'    => array( 'width' => 300 ),
		);

		$filtered = apply_filters( 'intermediate_image_sizes_advanced', $sizes );

		$this->assertArrayHasKey( 'thumbnail', $filtered );
		$this->assertArrayNotHasKey( 'medium', $filtered );
	}

	/**
	 * The canonical policy only removes names from caller-permitted subsets.
	 */
	public function test_enabled_size_policy_is_monotonic_on_all_core_surfaces() {
		yotm_update_disabled_sizes_option( array( 'medium', 'unknown-size' ) );
		$sizes = array(
			'thumbnail' => array( 'width' => 150 ),
			'medium'    => array( 'width' => 300 ),
		);

		$this->assertSame( array( 'thumbnail' => array( 'width' => 150 ) ), yotm_apply_enabled_size_policy( $sizes ) );
		$this->assertSame(
			array( 'thumbnail' => array( 'width' => 150 ) ),
			apply_filters( 'intermediate_image_sizes_advanced', $sizes, array(), 123 )
		);
		$this->assertSame(
			array( 'thumbnail' => array( 'width' => 150 ) ),
			apply_filters( 'wp_get_missing_image_subsizes', $sizes, array(), 123 )
		);
		$this->assertArrayNotHasKey( 'not-permitted', yotm_apply_enabled_size_policy( $sizes ) );
	}
}
