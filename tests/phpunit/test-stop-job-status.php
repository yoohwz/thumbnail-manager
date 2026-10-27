<?php
/**
 * Stop transport evidence and safe recovery-state projection.
 *
 * @package Thumbnail_Manager
 */

class YOTM_Stop_Job_Status_Test extends WP_UnitTestCase {

	private $administrator_id;
	private $job_ids = array();
	private $lock_result = '0';

	public function set_up() {
		parent::set_up();
		yotm_install_job_tables();
		$this->administrator_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->administrator_id );
		add_filter( 'wp_doing_ajax', '__return_true' );
		add_filter( 'wp_die_ajax_handler', array( $this, 'die_handler' ) );
	}

	public function tear_down() {
		remove_filter( 'query', array( $this, 'replace_lock_result' ) );
		remove_filter( 'wp_doing_ajax', '__return_true' );
		remove_filter( 'wp_die_ajax_handler', array( $this, 'die_handler' ) );
		yotm_job_release_all_workers();
		foreach ( $this->job_ids as $job_id ) {
			yotm_job_delete( $job_id );
		}
		$_POST    = array();
		$_REQUEST = array();
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	public function die_handler() {
		return array( $this, 'capture_die' );
	}

	public function capture_die( $message = '' ) {
		throw new RuntimeException( (string) $message );
	}

	private function create_job( $payload = array(), $status = 'running' ) {
		$job = yotm_job_create(
			'regenerate',
			$payload,
			array(
				'status'       => $status,
				'phase'        => 'regenerate',
				'counter_mode' => 'item_v3',
				'exclusive'    => false,
			)
		);
		$this->assertIsArray( $job );
		$this->job_ids[] = $job['id'];
		return $job;
	}

	private function ajax( $callback, $token, $nonce = null ) {
		$_POST = array(
			'token' => $token,
			'nonce' => null === $nonce ? wp_create_nonce( 'yotm_prune_nonce' ) : $nonce,
		);
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Deliberate AJAX request fixture.
		$_REQUEST = $_POST;
		ob_start();
		try {
			call_user_func( $callback );
		} catch ( RuntimeException $error ) {
			$body = ob_get_clean();
			return '' === $body ? $error->getMessage() : json_decode( $body, true );
		}
		ob_end_clean();
		$this->fail( 'The AJAX adapter did not terminate its response.' );
	}

	public function replace_lock_result( $query ) {
		return preg_match( '/^SELECT GET_LOCK\(/i', ltrim( $query ) ) ? 'SELECT ' . $this->lock_result : $query;
	}

	public function test_stop_confirms_cancelled_state_and_retains_audit_without_private_payload() {
		$job      = $this->create_job( array( 'base' => '/private/uploads', 'private_journal' => array( 'path' => '/private/staging' ) ) );
		$response = $this->ajax( 'yotm_job_ajax_cancel', $job['token'] );

		$this->assertTrue( $response['success'] );
		$this->assertTrue( $response['data']['cancelled'] );
		$this->assertSame( 'cancelled', $response['data']['job']['status'] );
		$this->assertNotEmpty( $response['data']['job']['context']['cancelled_at'] );
		$this->assertSame( 'cancelled', yotm_job_get_by_id( $job['id'] )['status'] );
		$this->assertStringNotContainsString( '/private/', wp_json_encode( $response ) );
	}

	public function test_terminal_completion_and_expiry_are_not_reported_as_cancelled() {
		foreach ( array( 'completed', 'expired', 'cancelled' ) as $status ) {
			$job      = $this->create_job( array(), $status );
			$response = $this->ajax( 'yotm_job_ajax_cancel', $job['token'] );
			$this->assertTrue( $response['success'] );
			$this->assertSame( 'cancelled' === $status, $response['data']['cancelled'] );
			$this->assertSame( $status, $response['data']['job']['status'] );
		}
	}

	public function test_busy_stop_is_identifiable_and_does_not_claim_persisted_cancellation() {
		$job = $this->create_job();
		add_filter( 'query', array( $this, 'replace_lock_result' ) );
		$response = $this->ajax( 'yotm_job_ajax_cancel', $job['token'] );
		remove_filter( 'query', array( $this, 'replace_lock_result' ) );

		$this->assertFalse( $response['success'] );
		$this->assertSame( 'yotm_job_cancel_busy', $response['data']['code'] );
		$this->assertSame( 250, $response['data']['retry_after_ms'] );
		$current = yotm_job_get_by_id( $job['id'] );
		$this->assertSame( 'running', $current['status'] );
		$this->assertArrayNotHasKey( 'recovery_only', $current['payload'] );
	}

	public function test_storage_error_does_not_advertise_automatic_busy_retry() {
		$job               = $this->create_job();
		$this->lock_result = 'NULL';
		add_filter( 'query', array( $this, 'replace_lock_result' ) );
		$response = $this->ajax( 'yotm_job_ajax_cancel', $job['token'] );
		remove_filter( 'query', array( $this, 'replace_lock_result' ) );

		$this->assertFalse( $response['success'] );
		$this->assertNotSame( 'yotm_job_cancel_busy', $response['data']['code'] );
		$this->assertArrayNotHasKey( 'retry_after_ms', $response['data'] );
		$this->assertSame( 'running', yotm_job_get_by_id( $job['id'] )['status'] );
	}

	public function test_status_exposes_persisted_recovery_intent_without_journal_or_source_paths() {
		$job = $this->create_job( array( 'base' => '/private/uploads' ) );
		$this->assertTrue(
			yotm_job_add_item(
				$job['id'],
				hash( 'sha256', 'armed-repair' ),
				array( 'attachment_id' => 42, 'regeneration_journal' => array( 'path' => '/private/staging' ) )
			)
		);
		$stop = $this->ajax( 'yotm_job_ajax_cancel', $job['token'] );
		$this->assertFalse( $stop['success'] );
		$this->assertSame( 'yotm_job_cancel_busy', $stop['data']['code'] );

		$response = $this->ajax( 'yotm_job_ajax_status', $job['token'] );
		$this->assertTrue( $response['success'] );
		$this->assertSame( 'running', $response['data']['status'] );
		$this->assertSame( 1, $response['data']['context']['recovery_only'] );
		$this->assertSame( 'cancelled', $response['data']['context']['recovery_terminal_status'] );
		$this->assertStringNotContainsString( '/private/', wp_json_encode( $response ) );
		$this->assertArrayNotHasKey( 'regeneration_journal', $response['data']['context'] );
		$this->assertCount( 1, yotm_job_get_items( $job['id'] ) );
	}

	public function test_stop_and_status_reject_another_administrator_without_changing_the_job() {
		$job = $this->create_job( array( 'recovery_only' => 1, 'recovery_terminal_status' => 'cancelled' ) );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		foreach ( array( 'yotm_job_ajax_cancel', 'yotm_job_ajax_status' ) as $callback ) {
			$response = $this->ajax( $callback, $job['token'] );
			$this->assertFalse( $response['success'] );
			$this->assertArrayNotHasKey( 'context', $response['data'] );
			$this->assertArrayNotHasKey( 'job', $response['data'] );
		}
		$this->assertSame( 'running', yotm_job_get_by_id( $job['id'] )['status'] );
	}

	public function test_stop_requires_administrator_capability_and_valid_nonce() {
		$job = $this->create_job();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$response = $this->ajax( 'yotm_job_ajax_cancel', $job['token'] );
		$this->assertFalse( $response['success'] );
		$this->assertSame( 'running', yotm_job_get_by_id( $job['id'] )['status'] );

		wp_set_current_user( $this->administrator_id );
		$this->assertSame( '-1', $this->ajax( 'yotm_job_ajax_cancel', $job['token'], 'invalid-nonce' ) );
		$this->assertSame( 'running', yotm_job_get_by_id( $job['id'] )['status'] );
	}
}
