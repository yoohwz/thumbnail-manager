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
		$job = yotm_job_create(
			'regenerate',
			array_merge(
				array(
					'force_all'      => 1,
					'selected_sizes' => array( 'thumbnail' ),
					'scope_label'    => 'Selected images',
				),
				$extra
			),
			array(
				'status'       => 'running',
				'phase'        => 'regenerate',
				'counter_mode' => 'item_v3',
				'total'        => 1,
			)
		);
		$this->assertIsArray( $job );
		$this->jobs[] = $job['id'];
		$base         = trailingslashit( wp_get_upload_dir()['basedir'] );
		$this->assertTrue(
			yotm_job_add_item(
				$job['id'],
				hash( 'sha256', 'attachment:123' ),
				array(
					'attachment_id'        => 123,
					'regeneration_journal' => array(
						'version'        => YOTM_REGENERATE_JOURNAL_VERSION,
						'attachment_id'  => 123,
						'phase'          => $phase,
						'full'           => $base . 'example.jpg',
						'destinations'   => array(
							'thumbnail' => array(
								'destination'   => $base . 'example-150x150.jpg',
								'promoted_hash' => str_repeat( 'a', 64 ),
							),
						),
						'final_metadata' => array( 'sizes' => array( 'thumbnail' => array( 'file' => 'example-150x150.jpg' ) ) ),
					),
				),
				'done'
			)
		);
		yotm_job_update(
			$job['id'],
			array(
				'status'    => 'completed',
				'phase'     => 'completed',
				'succeeded' => 1,
				'processed' => 1,
			)
		);
		return yotm_job_get_by_id( $job['id'] );
	}

	public function test_read_returns_only_persisted_generated_outputs_without_execution_or_state_changes() {
		$job = $this->source();
		$out = yotm_optimizer_handoff( $job['token'] );
		$this->assertIsArray( $out );
		$this->assertSame( 1, $out['total'] );
		$this->assertSame(
			array(
				array(
					'size'   => 'thumbnail',
					'path'   => 'example-150x150.jpg',
					'sha256' => str_repeat( 'a', 64 ),
				),
			),
			$out['items'][0]['files']
		);
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
		$job = $this->source(
			array(
				'force_all' => 0,
				'operation' => 'check',
			)
		);
		$this->assertWPError( yotm_optimizer_handoff( $job['token'] ) );
		$pending = $this->source( array(), 'files_promoted' );
		$this->assertWPError( yotm_optimizer_handoff( $pending['token'] ) );
	}

	public function test_active_job_check_is_read_only_and_excludes_unfinished_authority() {
		$this->assertFalse( yotm_optimizer_active_job() );
		$job = $this->source();
		yotm_job_update(
			$job['id'],
			array(
				'status' => 'running',
				'phase'  => 'regenerate',
			)
		);
		$this->assertWPError( yotm_optimizer_active_job() );
		$this->assertSame( 'running', yotm_job_get_by_id( $job['id'] )['status'] );
	}

	private function prune_source() {
		$job = yotm_job_create(
			'prune',
			array(),
			array(
				'status'       => 'scanning',
				'phase'        => 'metadata',
				'counter_mode' => 'item_v3',
			)
		);
		$this->assertIsArray( $job );
		$this->jobs[] = $job['id'];
		$base         = trailingslashit( wp_get_upload_dir()['basedir'] );
		foreach ( array(
			'deleted' => 'delete_reconciled',
			'skipped' => '',
			'failed'  => '',
		) as $status => $outcome ) {
			$path    = $base . 'example-' . $status . '-150x150.jpg';
			$payload = array(
				'attachment_id' => 123,
				'path'          => $path,
			);
			if ( $outcome ) {
				$payload['prune_operation_journal_v1'] = array(
					'version'   => 1,
					'outcome'   => $outcome,
					'path'      => $path,
					'file_hash' => str_repeat( 'a', 64 ),
					'bytes'     => 100,
				);
			}
			$this->assertTrue( yotm_job_add_item( $job['id'], hash( 'sha256', $status ), $payload, $status ) );
		}
		// A legacy deleted row without a journal remains outside the handoff.
		$this->assertTrue(
			yotm_job_add_item(
				$job['id'],
				hash( 'sha256', 'legacy' ),
				array(
					'attachment_id' => 123,
					'path'          => $base . 'legacy-150x150.jpg',
				),
				'deleted'
			)
		);
		yotm_job_update(
			$job['id'],
			array(
				'status'    => 'completed',
				'phase'     => 'completed',
				'succeeded' => 2,
				'processed' => 4,
			)
		);
		return yotm_job_get_by_id( $job['id'] );
	}

	public function test_prune_handoff_exports_only_exact_reconciled_deletions_read_only() {
		$job = $this->prune_source();
		$out = yotm_optimizer_prune_handoff( $job['token'] );
		$this->assertIsArray( $out );
		$this->assertCount( 1, $out['items'] );
		$this->assertSame( 'example-deleted-150x150.jpg', $out['items'][0]['path'] );
		$this->assertSame( str_repeat( 'a', 64 ), $out['items'][0]['sha256'] );
		$this->assertSame( $job, yotm_job_get_by_id( $job['id'] ) );
		$this->assertSame( $out['items'], yotm_optimizer_prune_handoff( $job['token'], 1, hash( 'sha256', 'deleted' ) )['items'] );
		$this->assertWPError( yotm_optimizer_prune_handoff( $job['token'], 1, hash( 'sha256', 'skipped' ) ) );
		$this->assertWPError( yotm_optimizer_prune_handoff( $job['token'], 1, '../untrusted' ) );
	}

	public function test_prune_handoff_rejects_active_expired_wrong_owner_and_recovery() {
		$job = $this->prune_source();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->assertWPError( yotm_optimizer_prune_handoff( $job['token'] ) );
		wp_set_current_user( $this->admin );
		yotm_job_update( $job['id'], array( 'status' => 'deleting' ) );
		$this->assertWPError( yotm_optimizer_prune_handoff( $job['token'] ) );
		yotm_job_update(
			$job['id'],
			array(
				'status'     => 'completed',
				'expires_at' => gmdate( 'Y-m-d H:i:s', time() - 1 ),
			)
		);
		$this->assertWPError( yotm_optimizer_prune_handoff( $job['token'] ) );
		$this->assertSame( 'completed', yotm_job_get_by_id( $job['id'] )['status'] );
	}
}
