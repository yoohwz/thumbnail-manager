<?php
/**
 * Read-only thumbnail health and missing-only media primitives.
 */
require_once dirname( __DIR__, 2 ) . '/inc/media/thumbnail-health.php';

class YOTM_Thumbnail_Health_Test extends WP_UnitTestCase {
	private $attachment_id = 0;
	private $directory     = '';
	private $stages        = array();
	private $tamper        = '';

	public function setUp(): void {
		parent::setUp();
		delete_option( 'yotm_disabled_sizes' );
		$uploads         = wp_get_upload_dir();
		$this->directory = trailingslashit( $uploads['basedir'] ) . 'yotm-health-' . wp_generate_uuid4();
		$this->assertTrue( wp_mkdir_p( $this->directory ) );
		add_image_size( 'yotm_health_small', 120, 120, true );
		add_image_size( 'yotm_health_other', 160, 100, true );
		add_image_size( 'yotm_health_large', 5000, 5000, false );
		add_image_size( 'yotm_health_ratio', 140, 0, false );
		add_filter( 'intermediate_image_sizes_advanced', array( $this, 'health_sizes' ), PHP_INT_MAX );
	}

	public function tearDown(): void {
		remove_filter( 'intermediate_image_sizes_advanced', array( $this, 'health_sizes' ), PHP_INT_MAX );
		remove_filter( 'wp_update_attachment_metadata', array( $this, 'tamper_metadata' ), 10 );
		foreach ( $this->stages as $stage ) {
			yotm_regenerate_remove_stage( $stage, $this->directory );
		}
		if ( $this->attachment_id ) {
			wp_delete_attachment( $this->attachment_id, true );
		}
		if ( is_dir( $this->directory ) ) {
			foreach ( scandir( $this->directory ) as $entry ) {
				if ( '.' !== $entry && '..' !== $entry ) {
					$path = $this->directory . '/' . $entry;
					if ( is_link( $path ) || is_file( $path ) ) {
						unlink( $path );
					} elseif ( is_dir( $path ) ) {
						rmdir( $path );
					}
				}
			}
			rmdir( $this->directory );
		}
		foreach ( array( 'yotm_health_small', 'yotm_health_other', 'yotm_health_large', 'yotm_health_ratio' ) as $slug ) {
			remove_image_size( $slug );
		}
		delete_option( 'yotm_disabled_sizes' );
		parent::tearDown();
	}

	public function test_inspection_detects_missing_disk_file_without_mutation_and_preserves_policy() {
		$metadata     = $this->create_fixture();
		$missing_path = $this->directory . '/' . $metadata['sizes']['yotm_health_small']['file'];
		$this->assertTrue( unlink( $missing_path ) );
		yotm_update_disabled_sizes_option( array( 'yotm_health_other' ) );
		$before     = $this->file_hashes();
		$inspection = yotm_thumbnail_health_inspect( $this->attachment_id );
		$this->assertSame( 'missing', $inspection['report']['status'] );
		$this->assertSame( 'file_missing', $inspection['report']['sizes']['yotm_health_small']['reason'] );
		$this->assertSame( 'disabled', $inspection['report']['sizes']['yotm_health_other']['status'] );
		$this->assertSame( 'not_needed', $inspection['report']['sizes']['yotm_health_large']['status'] );
		$this->assertSame( array( 'yotm_health_small' ), array_keys( $inspection['requested_sizes'] ) );
		$this->assertSame( $metadata, wp_get_attachment_metadata( $this->attachment_id ) );
		$this->assertSame( $before, $this->file_hashes() );
		$this->assertStringNotContainsString( $this->directory, wp_json_encode( $inspection['report'] ) );
	}

	public function test_missing_metadata_size_is_detected_and_oversized_sizes_are_not_required() {
		$metadata = $this->create_fixture();
		unlink( $this->directory . '/' . $metadata['sizes']['yotm_health_small']['file'] );
		unset( $metadata['sizes']['yotm_health_small'] );
		wp_update_attachment_metadata( $this->attachment_id, $metadata );
		$inspection = yotm_thumbnail_health_inspect( $this->attachment_id );
		$this->assertSame( 'metadata_missing', $inspection['report']['sizes']['yotm_health_small']['reason'] );
		$this->assertSame( 'not_needed', $inspection['report']['sizes']['yotm_health_large']['status'] );
		$this->assertCount( 1, $inspection['requested_sizes'] );
	}

	public function test_staging_and_finalizing_only_missing_sizes_preserves_exact_other_metadata_and_bytes() {
		$metadata = $this->create_fixture();
		unlink( $this->directory . '/' . $metadata['sizes']['yotm_health_small']['file'] );
		$before     = $this->file_hashes();
		$inspection = yotm_thumbnail_health_inspect( $this->attachment_id );
		$staged     = $this->stage( $inspection['snapshot'] );
		$this->assertSame( array( 'yotm_health_small' ), array_keys( $staged['outputs'] ) );
		$final = yotm_regenerate_finalize_metadata( $inspection['snapshot'], $staged );
		$this->assertIsArray( $final );
		$expected                               = $metadata;
		$expected['sizes']['yotm_health_small'] = $final['metadata']['sizes']['yotm_health_small'];
		$this->assertSame( $expected, $final['metadata'] );
		$this->assertSame( array( 'yotm_health_small' ), array_keys( $final['map'] ) );
		$this->assertSame( $before, $this->file_hashes() );
		$this->assertSame( $metadata, wp_get_attachment_metadata( $this->attachment_id ) );
		$this->assertFileDoesNotExist( $final['map']['yotm_health_small']['destination'] );
		$this->assertSame( 120, wp_getimagesize( $final['map']['yotm_health_small']['source'] )[0] );
	}

	/** @dataProvider protected_metadata_changes */
	public function test_repair_rejects_filter_changes_outside_missing_targets( $tamper ) {
		$metadata = $this->create_fixture();
		unlink( $this->directory . '/' . $metadata['sizes']['yotm_health_small']['file'] );
		$inspection   = yotm_thumbnail_health_inspect( $this->attachment_id );
		$staged       = $this->stage( $inspection['snapshot'] );
		$this->tamper = $tamper;
		add_filter( 'wp_update_attachment_metadata', array( $this, 'tamper_metadata' ), 10 );
		$final = yotm_regenerate_finalize_metadata( $inspection['snapshot'], $staged );
		$this->assertWPError( $final );
		$this->assertContains( $final->get_error_code(), array( 'yotm_repair_preserved_metadata', 'yotm_repair_target_removed' ) );
		$this->assertSame( $metadata, wp_get_attachment_metadata( $this->attachment_id ) );
	}

	public function protected_metadata_changes() {
		return array( array( 'custom' ), array( 'healthy' ), array( 'full' ), array( 'remove_target' ), array( 'add_size' ) );
	}

	public function test_current_edited_full_is_the_repair_source_and_original_is_preserved() {
		$metadata = $this->create_fixture();
		$full     = $this->directory . '/source.jpg';
		$original = $this->directory . '/original.jpg';
		$this->assertTrue( copy( $full, $original ) );
		$editor = wp_get_image_editor( $full );
		$this->assertNotWPError( $editor );
		$this->assertNotWPError( $editor->resize( 400, 240, true ) );
		$this->assertNotWPError( $editor->save( $full ) );
		$metadata['original_image'] = 'original.jpg';
		unlink( $this->directory . '/' . $metadata['sizes']['yotm_health_ratio']['file'] );
		wp_update_attachment_metadata( $this->attachment_id, $metadata );
		$before     = $this->file_hashes();
		$inspection = yotm_thumbnail_health_inspect( $this->attachment_id );
		$this->assertSame( $full, $inspection['snapshot']['source'] );
		$this->assertSame( $original, yotm_regenerate_preflight( $this->attachment_id )['source'] );
		$staged = $this->stage( $inspection['snapshot'] );
		$output = $staged['outputs']['yotm_health_ratio']['metadata'];
		$this->assertSame( 140, $output['width'] );
		$this->assertSame( 84, $output['height'] );
		$this->assertSame( $before, $this->file_hashes() );
	}

	public function test_missing_entire_metadata_is_blocked_with_restore_action() {
		$this->create_fixture();
		delete_post_meta( $this->attachment_id, '_wp_attachment_metadata' );
		$inspection = yotm_thumbnail_health_inspect( $this->attachment_id );
		$this->assertSame( 'blocked', $inspection['report']['status'] );
		$this->assertSame( 'yotm_regenerate_metadata_state', $inspection['report']['reason'] );
		$this->assertStringContainsString( 'Restore', $inspection['report']['action'] );
		$this->assertNull( $inspection['snapshot'] );
	}

	public function test_missing_full_source_is_blocked_and_svg_is_unsupported() {
		$this->create_fixture();
		unlink( $this->directory . '/source.jpg' );
		$inspection = yotm_thumbnail_health_inspect( $this->attachment_id );
		$this->assertSame( 'blocked', $inspection['report']['status'] );
		$this->assertSame( 'yotm_regenerate_full_missing', $inspection['report']['reason'] );
		wp_update_post(
			array(
				'ID'             => $this->attachment_id,
				'post_mime_type' => 'image/svg+xml',
			)
		);
		$inspection = yotm_thumbnail_health_inspect( $this->attachment_id );
		$this->assertSame( 'unsupported', $inspection['report']['status'] );
	}

	public function test_symlink_is_blocked_instead_of_being_repairable_missing() {
		$metadata = $this->create_fixture();
		$path     = $this->directory . '/' . $metadata['sizes']['yotm_health_small']['file'];
		unlink( $path );
		if ( ! symlink( $this->directory . '/source.jpg', $path ) ) {
			$this->markTestSkipped( 'Symlink fixture is unavailable.' );
		}
		$inspection = yotm_thumbnail_health_inspect( $this->attachment_id );
		$this->assertSame( 'blocked', $inspection['report']['status'] );
		$this->assertSame( 'blocked', $inspection['report']['sizes']['yotm_health_small']['status'] );
		$this->assertSame( array(), $inspection['requested_sizes'] );
		$this->assertTrue( is_link( $path ) );
	}

	public function test_thumbnail_directory_is_blocked_and_preserved() {
		$metadata = $this->create_fixture();
		$path     = $this->directory . '/' . $metadata['sizes']['yotm_health_small']['file'];
		unlink( $path );
		$this->assertTrue( mkdir( $path ) );
		$inspection = yotm_thumbnail_health_inspect( $this->attachment_id );
		$this->assertSame( 'blocked', $inspection['report']['status'] );
		$this->assertSame( 'unsafe_thumbnail_path', $inspection['report']['sizes']['yotm_health_small']['reason'] );
		$this->assertDirectoryExists( $path );
	}

	/** @dataProvider unsafe_metadata_entries */
	public function test_unsafe_metadata_is_blocked_without_mutation( $filename ) {
		$metadata                                       = $this->create_fixture();
		$metadata['sizes']['yotm_health_small']['file'] = $filename;
		wp_update_attachment_metadata( $this->attachment_id, $metadata );
		$before     = $this->file_hashes();
		$inspection = yotm_thumbnail_health_inspect( $this->attachment_id );
		$this->assertSame( 'blocked', $inspection['report']['status'] );
		$this->assertSame( array(), $inspection['requested_sizes'] );
		$this->assertSame( $metadata, wp_get_attachment_metadata( $this->attachment_id ) );
		$this->assertSame( $before, $this->file_hashes() );
	}

	public function unsafe_metadata_entries() {
		return array( array( '../outside.jpg' ), array( 'sub\\outside.jpg' ), array( 'source.jpg' ) );
	}

	public function health_sizes( $sizes ) {
		return array_intersect_key( $sizes, array_fill_keys( array( 'yotm_health_small', 'yotm_health_other', 'yotm_health_large', 'yotm_health_ratio' ), true ) );
	}

	public function tamper_metadata( $metadata ) {
		if ( 'custom' === $this->tamper ) {
			$metadata['health_custom'] = false;
		} elseif ( 'healthy' === $this->tamper ) {
			$metadata['sizes']['yotm_health_other']['width'] = 1;
		} elseif ( 'full' === $this->tamper ) {
			$metadata['width'] = 1;
		} elseif ( 'remove_target' === $this->tamper ) {
			unset( $metadata['sizes']['yotm_health_small'] );
		} else {
			$metadata['sizes']['unexpected'] = $metadata['sizes']['yotm_health_small'];
		}
		return $metadata;
	}

	private function stage( $snapshot ) {
		$this->assertIsArray( $snapshot );
		$staged = yotm_regenerate_stage_outputs( $snapshot );
		$this->assertIsArray( $staged, is_wp_error( $staged ) ? $staged->get_error_message() : '' );
		$this->stages[] = $staged['stage'];
		return $staged;
	}

	private function file_hashes() {
		$hashes = array();
		foreach ( scandir( $this->directory ) as $entry ) {
			$path = $this->directory . '/' . $entry;
			if ( is_file( $path ) && ! is_link( $path ) ) {
				$hashes[ $entry ] = hash_file( 'sha256', $path );
			}
		}
		return $hashes;
	}

	private function create_fixture() {
		$full = $this->directory . '/source.jpg';
		$this->assertTrue( copy( DIR_TESTDATA . '/images/2004-07-22-DSC_0007.jpg', $full ) );
		$this->attachment_id = wp_insert_attachment(
			array(
				'post_mime_type' => 'image/jpeg',
				'post_title'     => 'Thumbnail health fixture',
				'post_status'    => 'inherit',
			),
			$full
		);
		$metadata            = wp_generate_attachment_metadata( $this->attachment_id, $full );
		$this->assertIsArray( $metadata );
		$metadata['health_custom'] = array( 'preserve' => true );
		$this->assertTrue( copy( $full, $this->directory . '/historical.jpg' ) );
		$metadata['sizes']['historical_unregistered'] = array(
			'file'      => 'historical.jpg',
			'width'     => 1,
			'height'    => 1,
			'mime-type' => 'image/jpeg',
		);
		$this->assertNotFalse( wp_update_attachment_metadata( $this->attachment_id, $metadata ) );
		return $metadata;
	}
}
