<?php
/**
 * Admin and generic Jobs AJAX adapter boundary tests.
 *
 * @package Thumbnail_Manager
 */

class YOTM_Admin_Adapters_Test extends WP_UnitTestCase {

	/**
	 * Administrator used for form and rendering tests.
	 *
	 * @var int
	 */
	private $administrator_id;

	/**
	 * Set up an authorized administration request context.
	 */
	public function set_up() {
		parent::set_up();
		$this->administrator_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->administrator_id );
		$_POST    = array();
		$_REQUEST = array();
	}

	/**
	 * Restore request globals and test-owned settings.
	 */
	public function tear_down() {
		$_POST    = array();
		$_REQUEST = array();
		delete_option( 'yotm_disabled_sizes' );
		wp_dequeue_script( 'yotm-prune-admin' );
		wp_deregister_script( 'yotm-prune-admin' );
		wp_dequeue_style( 'yotm-prune-admin' );
		wp_deregister_style( 'yotm-prune-admin' );
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	/**
	 * Historical hooks, callbacks, and priorities remain registered once.
	 */
	public function test_admin_and_jobs_ajax_hooks_preserve_callbacks() {
		$this->assertSame( 10, has_action( 'admin_enqueue_scripts', 'yotm_admin_enqueue_assets' ) );
		$this->assertSame( 10, has_action( 'admin_menu', 'yotm_register_admin_page' ) );

		$callbacks = array(
			'wp_ajax_yotm_job_status'  => 'yotm_job_ajax_status',
			'wp_ajax_yotm_job_items'   => 'yotm_job_ajax_items',
			'wp_ajax_yotm_jobs_recent' => 'yotm_job_ajax_recent',
			'wp_ajax_yotm_job_cancel'  => 'yotm_job_ajax_cancel',
		);

		foreach ( $callbacks as $hook => $callback ) {
			$this->assertSame( 10, has_action( $hook, $callback ), $hook );
			$reflection = new ReflectionFunction( $callback );
			$this->assertSame( wp_normalize_path( dirname( __DIR__, 2 ) . '/inc/handle-jobs.php' ), wp_normalize_path( $reflection->getFileName() ) );
		}
	}

	/**
	 * The stable administration callback remains a facade over the page adapter.
	 */
	public function test_admin_callback_remains_in_compatibility_bootstrap() {
		$callback = new ReflectionFunction( 'yotm_manage_thumbnails_page' );

		$this->assertSame( wp_normalize_path( dirname( __DIR__, 2 ) . '/inc/admin-menu.php' ), wp_normalize_path( $callback->getFileName() ) );
	}

	/**
	 * The authenticated form adapter maps legacy fields without changing counts.
	 */
	public function test_size_form_maps_to_application_and_preserves_follow_on_intent() {
		$_POST = array(
			'yotm_save_and_regenerate' => '1',
			'yotm_sizes_save_nonce'    => wp_create_nonce( 'yotm_sizes_save_nonce' ),
			'yotm_enable_sizes'        => array( 'thumbnail', 'unknown-forged-size', 'thumbnail' ),
		);
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Test mirrors authenticated request data.
		$_REQUEST = $_POST;

		$outcome = yotm_admin_handle_size_management_request();

		$this->assertTrue( $outcome['success'] );
		$this->assertSame( 3, $outcome['data']['enabled_count'] );
		$this->assertTrue( $outcome['data']['run_regenerate_after_save'] );
		$this->assertNotContains( 'unknown-forged-size', get_option( 'yotm_disabled_sizes' ), true );
	}

	/**
	 * View-model defaults and notice text preserve the prior page behavior.
	 */
	public function test_page_view_model_preserves_defaults_and_notice_counts() {
		$default = yotm_admin_build_page_view_model();
		$this->assertSame( array( 'thumbnail', 'medium', 'large' ), $default['default_keep'] );

		$outcome = array(
			'success' => true,
			'data'    => array(
				'enabled_count'             => 3,
				'disabled_count'            => 7,
				'run_regenerate_after_save' => true,
			),
		);
		$view    = yotm_admin_build_page_view_model( $outcome );

		$this->assertStringContainsString( '3 size(s) enabled, 7 disabled', $view['sizes_saved_notice'] );
		$this->assertTrue( $view['run_regenerate_after_save'] );
	}

	/**
	 * Renderer keeps the stable DOM and accessibility anchors.
	 */
	public function test_renderer_preserves_dom_and_accessibility_contracts() {
		ob_start();
		yotm_render_admin_view( yotm_admin_build_page_view_model() );
		$html = ob_get_clean();

		foreach ( array(
			'id="yotm_tabs"',
			'role="tablist"',
			'role="tabpanel"',
			'id="yotm_active_job"',
			'aria-live="polite"',
			'id="yotm_review_confirm"',
			'id="yotm_approve_delete"',
			'name="yotm_enable_sizes[]"',
		) as $needle ) {
			$this->assertStringContainsString( $needle, $html );
		}
	}

	/**
	 * Asset handle/path/dependencies and localized contract remain stable.
	 */
	public function test_assets_preserve_handle_and_localized_contract() {
		yotm_admin_enqueue_assets( 'tools_page_yo-manage-thumbnails' );
		$script = wp_scripts()->registered['yotm-prune-admin'];
		$style  = wp_styles()->registered['yotm-prune-admin'];

		$this->assertStringEndsWith( '/js/admin.js', $script->src );
		$this->assertSame( array( 'jquery' ), $script->deps );
		$this->assertStringEndsWith( '/css/style.css', $style->src );
		$this->assertStringContainsString( 'var YOTM =', $script->extra['data'] );
		foreach ( array( 'ajaxurl', 'nonce', 'siteId', 'registeredSizesSignature', 'i18n' ) as $key ) {
			$this->assertStringContainsString( '"' . $key . '"', $script->extra['data'] );
		}
	}

	/**
	 * The generic AJAX adapter source pins auth, nonce, fields, defaults, and statuses.
	 */
	public function test_generic_ajax_source_preserves_transport_contract() {
		$source = file_get_contents( dirname( __DIR__, 2 ) . '/inc/handle-jobs.php' );

		foreach ( array(
			"current_user_can( 'manage_options' )",
			"check_ajax_referer( 'yotm_prune_nonce', 'nonce' )",
			"\$_POST['token']",
			"\$_POST['page']",
			"\$_POST['per_page']",
			"\$_POST['search']",
			"wp_send_json_error( array( 'msg' => __( 'No permission.'",
			'), 403 )',
			'), 404 )',
			'), 400 )',
			'), 503 )',
			"\n\t\t\t409\n\t\t);",
			"'retry_after_ms' => 250",
		) as $needle ) {
			$this->assertStringContainsString( $needle, $source, $needle );
		}
	}

	/**
	 * Prune AJAX remains administrator/nonce gated and forwards discovery only to prepare.
	 */
	public function test_prune_ajax_source_preserves_review_first_authorization_contract() {
		$source  = file_get_contents( dirname( __DIR__, 2 ) . '/inc/handle-prune.php' );
		$source .= file_get_contents( dirname( __DIR__, 2 ) . '/inc/handle-delete.php' );

		foreach ( array(
			"current_user_can( 'manage_options' )",
			"check_ajax_referer( 'yotm_prune_nonce', 'nonce' )",
			"! empty( \$_POST['discover_orphans'] )",
			"! empty( \$_POST['discover_historical'] )",
			'yotm_prune_prepare_application(',
			'yotm_prune_scan_application(',
			'yotm_prune_approve_application(',
			'yotm_prune_delete_application(',
		) as $needle ) {
			$this->assertStringContainsString( $needle, $source, $needle );
		}
	}
}
