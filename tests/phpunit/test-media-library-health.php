<?php
/**
 * Media Library thumbnail inspection navigation contracts.
 *
 * @package Thumbnail_Manager
 */

class YOTM_Media_Library_Health_Test extends WP_UnitTestCase {

	/** @var array Saved request selection. */
	private $request_get;

	/** Restore request and user state around each test. */
	public function set_up() {
		parent::set_up();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Preserve test request state.
		$this->request_get = $_GET;
		$_GET              = array();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	/** Restore request and user state. */
	public function tear_down() {
		$_GET = $this->request_get;
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	/** @return int Image attachment ID. */
	private function image_id() {
		return self::factory()->post->create(
			array(
				'post_type'      => 'attachment',
				'post_mime_type' => 'image/jpeg',
				'post_status'    => 'inherit',
			)
		);
	}

	/** Navigation reads a complete validated selection and never starts a job. */
	public function test_get_selection_is_read_only_and_preserves_exact_unique_ids() {
		$first                       = $this->image_id();
		$second                      = $this->image_id();
		$_GET['yotm_attachment_ids'] = $first . ',' . $second . ',' . $first;
		$selection                   = yotm_admin_requested_media_selection();
		$this->assertTrue( $selection['requested'] );
		$this->assertSame( array( $first, $second ), $selection['ids'] );
		$this->assertSame( '', $selection['error'] );
		$this->assertSame( '', get_post_meta( $first, '_wp_attachment_metadata', true ) );
	}

	/** Invalid, empty, non-image, and excessive selections stay explicit and empty. */
	public function test_invalid_selections_never_fall_back_to_all_or_truncate() {
		$image = $this->image_id();
		$other = self::factory()->post->create(
			array(
				'post_type'      => 'attachment',
				'post_mime_type' => 'application/pdf',
			)
		);
		foreach ( array( '', '0', '-1', '1x', array( array( 1 ) ), $image . ',' . $other, range( 1, 501 ) ) as $raw ) {
			$selection = yotm_admin_media_selection( $raw );
			$this->assertTrue( $selection['requested'] );
			$this->assertSame( array(), $selection['ids'] );
			$this->assertNotEmpty( $selection['error'] );
		}
	}

	/** Administrators get single-image and bulk navigation actions only. */
	public function test_authorized_media_entry_points_route_to_the_same_selection() {
		$image   = $this->image_id();
		$actions = yotm_admin_media_health_row_action( array(), get_post( $image ) );
		$this->assertStringContainsString( 'yotm_attachment_ids=' . $image, $actions['yotm_thumbnail_health'] );
		$this->assertArrayHasKey( 'yotm_thumbnail_health', yotm_admin_media_health_bulk_action( array() ) );
		$this->assertArrayHasKey( 'yotm_thumbnail_health', yotm_admin_media_health_attachment_field( array(), get_post( $image ) ) );
		$redirect = yotm_admin_media_health_bulk_redirect( 'original', 'yotm_thumbnail_health', array( $image ) );
		$this->assertSame( yotm_admin_media_health_url( array( $image ) ), $redirect );
		$this->assertSame( 'original', yotm_admin_media_health_bulk_redirect( 'original', 'other_action', array( $image ) ) );
		$this->assertStringContainsString( 'yotm_attachment_ids=invalid', yotm_admin_media_health_bulk_redirect( 'original', 'yotm_thumbnail_health', range( 1, 501 ) ) );
	}

	/** The existing administrator capability gates every navigation entry point. */
	public function test_unauthorized_user_has_no_entry_points_or_get_selection() {
		$image = $this->image_id();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'author' ) ) );
		$_GET['yotm_attachment_ids'] = (string) $image;
		$this->assertFalse( yotm_admin_requested_media_selection()['requested'] );
		$this->assertSame( array(), yotm_admin_media_health_row_action( array(), get_post( $image ) ) );
		$this->assertSame( array(), yotm_admin_media_health_bulk_action( array() ) );
		$this->assertSame( array(), yotm_admin_media_health_attachment_field( array(), get_post( $image ) ) );
		$this->assertSame( 'original', yotm_admin_media_health_bulk_redirect( 'original', 'yotm_thumbnail_health', array( $image ) ) );
	}

	/** Register native WordPress list, bulk, and attachment-detail integrations. */
	public function test_native_media_hooks_are_registered() {
		$this->assertSame( 10, has_filter( 'media_row_actions', 'yotm_admin_media_health_row_action' ) );
		$this->assertSame( 10, has_filter( 'bulk_actions-upload', 'yotm_admin_media_health_bulk_action' ) );
		$this->assertSame( 10, has_filter( 'handle_bulk_actions-upload', 'yotm_admin_media_health_bulk_redirect' ) );
		$this->assertSame( 10, has_filter( 'attachment_fields_to_edit', 'yotm_admin_media_health_attachment_field' ) );
	}
}
