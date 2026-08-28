<?php

/**
 * Deterministic query-shape and bounded-selector regressions.
 */
class YOTM_Scan_Performance_Test extends WP_UnitTestCase {
	public function test_literal_subpath_membership_does_not_interpret_wildcards() {
		$this->assertTrue( yotm_attached_file_is_in_subpaths( '2026/08/photo.jpg', array( '2026/08' ) ) );
		$this->assertFalse( yotm_attached_file_is_in_subpaths( '2026/080/photo.jpg', array( '2026/08' ) ) );
		$this->assertTrue( yotm_attached_file_is_in_subpaths( 'sale_x/photo.jpg', array( 'sale_x' ) ) );
		$this->assertFalse( yotm_attached_file_is_in_subpaths( 'saleAx/photo.jpg', array( 'sale_x' ) ) );
		$this->assertTrue( yotm_attached_file_is_in_subpaths( 'regex.x/photo.jpg', array( 'regex.x' ) ) );
	}

	public function test_attached_file_normalization_accepts_relative_separators_and_rejects_unsafe_values() {
		$this->assertSame( '2026/08/photo.jpg', yotm_normalize_attached_file_relative_path( '2026\\08\\photo.jpg' ) );
		$this->assertWPError( yotm_normalize_attached_file_relative_path( '/2026/08/photo.jpg' ) );
		$this->assertWPError( yotm_normalize_attached_file_relative_path( 'C:\\uploads\\photo.jpg' ) );
		$this->assertWPError( yotm_normalize_attached_file_relative_path( '2026/../photo.jpg' ) );
		$this->assertWPError( yotm_normalize_attached_file_relative_path( '2026//photo.jpg' ) );
	}

	public function test_selector_uses_frozen_meta_id_boundary_and_advances_across_nonmatches() {
		$outside = $this->create_raw_attachment( '2025/01/outside.jpg' );
		$inside  = $this->create_raw_attachment( '2026/08/inside.jpg' );
		$maximum = yotm_get_max_attached_file_meta_id();
		$later   = $this->create_raw_attachment( '2026/08/later.jpg' );

		$this->assertIsInt( $maximum );
		$rows = yotm_get_attached_file_selector_rows_after( 0, $maximum, 100 );
		$this->assertNotWPError( $rows );
		$this->assertContains( $outside['attachment_id'], wp_list_pluck( $rows, 'attachment_id' ) );
		$this->assertContains( $inside['attachment_id'], wp_list_pluck( $rows, 'attachment_id' ) );
		$this->assertNotContains( $later['attachment_id'], wp_list_pluck( $rows, 'attachment_id' ) );
		$this->assertLessThanOrEqual( $maximum, max( array_map( 'absint', wp_list_pluck( $rows, 'meta_id' ) ) ) );
	}

	public function test_execution_authorization_rejects_delete_add_replacement_even_when_in_scope() {
		$fixture = $this->create_raw_attachment( '2026/08/original.jpg' );

		global $wpdb;
		$this->assertSame( 1, $wpdb->delete( $wpdb->postmeta, array( 'meta_id' => $fixture['meta_id'] ), array( '%d' ) ) );
		$this->assertSame(
			1,
			$wpdb->insert(
				$wpdb->postmeta,
				array(
					'post_id'    => $fixture['attachment_id'],
					'meta_key'   => '_wp_attached_file',
					'meta_value' => '2026/08/replacement.jpg',
				),
				array( '%d', '%s', '%s' )
			)
		);

		$result = yotm_authorize_attached_file_selector_scope(
			$fixture['attachment_id'],
			$fixture['meta_id'],
			$fixture['meta_id'],
			array( '2026/08' )
		);
		$this->assertWPError( $result );
		$this->assertSame( 'yotm_attached_file_scope_replaced', $result->get_error_code() );
	}

	public function test_execution_authorization_rejects_multiple_rows_and_filtered_scope_lie() {
		$multiple = $this->create_raw_attachment( '2026/08/multiple.jpg' );

		global $wpdb;
		$wpdb->insert(
			$wpdb->postmeta,
			array(
				'post_id'    => $multiple['attachment_id'],
				'meta_key'   => '_wp_attached_file',
				'meta_value' => '2025/01/conflict.jpg',
			),
			array( '%d', '%s', '%s' )
		);
		$result = yotm_authorize_attached_file_selector_scope( $multiple['attachment_id'], $multiple['meta_id'], $multiple['meta_id'], array( '2026/08' ) );
		$this->assertWPError( $result );
		$this->assertSame( 'yotm_attached_file_scope_ambiguous', $result->get_error_code() );

		$filtered = $this->create_raw_attachment( '2025/01/raw-outside.jpg' );
		$filter   = static function ( $file, $attachment_id ) use ( $filtered ) {
			return $filtered['attachment_id'] === (int) $attachment_id ? '2026/08/filter-lie.jpg' : $file;
		};
		add_filter( 'get_attached_file', $filter, 10, 2 );
		try {
			$result = yotm_authorize_attached_file_selector_scope( $filtered['attachment_id'], $filtered['meta_id'], $filtered['meta_id'], array( '2026/08' ) );
			$this->assertWPError( $result );
			$this->assertSame( 'yotm_attached_file_scope_outside', $result->get_error_code() );
		} finally {
			remove_filter( 'get_attached_file', $filter, 10 );
		}
	}

	public function test_prune_selector_binding_rejects_reinserted_attached_file_row() {
		$fixture = $this->create_raw_attachment( '2026/08/prune-bound.jpg' );
		$item    = array(
			'ownership_evidence' => array(
				array(
					'attachment_id'     => $fixture['attachment_id'],
					'selection_meta_id' => $fixture['meta_id'],
				),
			),
		);
		$job     = array(
			'selector'           => 'attached_meta_v2',
			'selection_meta_max' => $fixture['meta_id'],
			'selection_subpaths' => array( '2026/08' ),
		);
		$this->assertTrue( yotm_prune_validate_selector_bindings( $item, $job ) );

		global $wpdb;
		$this->assertSame( 1, $wpdb->delete( $wpdb->postmeta, array( 'meta_id' => $fixture['meta_id'] ), array( '%d' ) ) );
		$this->assertSame(
			1,
			$wpdb->insert(
				$wpdb->postmeta,
				array(
					'post_id'    => $fixture['attachment_id'],
					'meta_key'   => '_wp_attached_file',
					'meta_value' => '2026/08/prune-replacement.jpg',
				),
				array( '%d', '%s', '%s' )
			)
		);

		$result = yotm_prune_validate_selector_bindings( $item, $job );
		$this->assertWPError( $result );
		$this->assertSame( 'yotm_attached_file_scope_replaced', $result->get_error_code() );
	}

	/**
	 * Create an attachment with one exact raw attached-file row.
	 *
	 * @param string $relative Relative attached-file value.
	 * @return array{attachment_id:int,meta_id:int}
	 */
	private function create_raw_attachment( $relative ) {
		$attachment_id = self::factory()->post->create(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'post_mime_type' => 'image/jpeg',
			)
		);

		global $wpdb;
		$this->assertSame(
			1,
			$wpdb->insert(
				$wpdb->postmeta,
				array(
					'post_id'    => $attachment_id,
					'meta_key'   => '_wp_attached_file',
					'meta_value' => $relative,
				),
				array( '%d', '%s', '%s' )
			)
		);

		return array(
			'attachment_id' => $attachment_id,
			'meta_id'       => absint( $wpdb->insert_id ),
		);
	}
}
