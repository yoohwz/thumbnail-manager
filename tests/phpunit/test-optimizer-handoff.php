<?php
/**
 * Exact saved output authority for optional Media Optimizer handoffs.
 */
class YOTM_Optimizer_Handoff_Test extends WP_UnitTestCase {
	private $jobs = array();
	private $admin;

	public function setUp(): void {
		parent::setUp();
		unset( $GLOBALS['yotm_job_storage_readiness'] );
		$this->assertTrue( yotm_run_job_table_migration() );
		$this->admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin );
	}

	public function tearDown(): void {
		yotm_job_release_all_workers();
		foreach ( $this->jobs as $id ) {
			yotm_job_delete( $id );
		}
		parent::tearDown();
	}

	private function source( $extra = array(), $phase = 'cleanup_complete' ) {
		$job = yotm_job_create( 'regenerate', array_merge( array( 'force_all' => 1, 'selected_sizes' => array( 'thumbnail' ), 'scope_label' => 'Selected images' ), $extra ), array( 'status' => 'running', 'phase' => 'regenerate', 'counter_mode' => 'item_v3', 'total' => 1 ) );
		$this->assertIsArray( $job );
		$this->jobs[] = $job['id'];
		$base = trailingslashit( wp_get_upload_dir()['basedir'] );
		$this->assertTrue( yotm_job_add_item( $job['id'], hash( 'sha256', 'attachment:123' ), array( 'attachment_id' => 123, 'regeneration_journal' => array( 'version' => YOTM_REGENERATE_JOURNAL_VERSION, 'attachment_id' => 123, 'phase' => $phase, 'full' => $base . 'example.jpg', 'destinations' => array( 'thumbnail' => array( 'destination' => $base . 'example-150x150.jpg', 'promoted_hash' => str_repeat( 'a', 64 ) ) ), 'final_metadata' => array( 'sizes' => array( 'thumbnail' => array( 'file' => 'example-150x150.jpg' ) ) ) ) ), 'done' ) );
		yotm_job_update( $job['id'], array( 'status' => 'completed', 'phase' => 'completed', 'succeeded' => 1, 'processed' => 1 ) );
		return yotm_job_get_by_id( $job['id'] );
	}

	public function test_read_returns_only_persisted_generated_outputs_without_execution_or_state_changes() {
		$job = $this->source();
		$out = yotm_optimizer_handoff( $job['token'] );
		$this->assertIsArray( $out );
		$this->assertSame( 1, $out['total'] );
		$this->assertSame( array( array( 'size' => 'thumbnail', 'path' => 'example-150x150.jpg', 'sha256' => str_repeat( 'a', 64 ) ) ), $out['items'][0]['files'] );
		$this->assertStringContainsString( 'yotm_result_token=' . $job['token'], $out['source_url'] );
		$this->assertSame( $job, yotm_job_get_by_id( $job['id'] ) );
		$exact = yotm_optimizer_handoff( $job['token'], 1, 123 );
		$this->assertSame( $out['items'], $exact['items'] );
		$this->assertWPError( yotm_optimizer_handoff( $job['token'], 1, 999 ) );
	}

	public function test_wrong_owner_no_capability_and_expiry_cannot_reuse_source() {
		$job = $this->source();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->assertWPError( yotm_optimizer_handoff( $job['token'] ) );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$this->assertWPError( yotm_optimizer_handoff( $job['token'] ) );
		wp_set_current_user( $this->admin );
		yotm_job_update( $job['id'], array( 'expires_at' => gmdate( 'Y-m-d H:i:s', time() - 1 ) ) );
		$this->assertWPError( yotm_optimizer_handoff( $job['token'] ) );
		$this->assertSame( 'completed', yotm_job_get_by_id( $job['id'] )['status'] );
	}

	public function test_check_job_and_pending_recovery_are_never_handoff_authority() {
		$job = $this->source( array( 'force_all' => 0, 'operation' => 'check' ) );
		$this->assertWPError( yotm_optimizer_handoff( $job['token'] ) );
		$pending = $this->source( array(), 'files_promoted' );
		$this->assertWPError( yotm_optimizer_handoff( $pending['token'] ) );
	}

	public function test_active_job_check_is_read_only_and_excludes_unfinished_authority() {
		$this->assertFalse( yotm_optimizer_active_job() );
		$job = $this->source();
		yotm_job_update( $job['id'], array( 'status' => 'running', 'phase' => 'regenerate' ) );
		$this->assertWPError( yotm_optimizer_active_job() );
		$this->assertSame( 'running', yotm_job_get_by_id( $job['id'] )['status'] );
	}
}
