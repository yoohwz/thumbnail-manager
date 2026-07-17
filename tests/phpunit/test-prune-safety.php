<?php

/**
 * Filesystem and metadata safety tests.
 */
class YOTM_Prune_Safety_Test extends WP_UnitTestCase {
	/** @var string */
	private $uploads_base;

	/** @var string */
	private $test_dir;

	/** @var string[] */
	private $files = array();

	/** @var string[] */
	private $directories = array();

	/** @var int */
	private $attachment_id = 0;

	public function setUp(): void {
		parent::setUp();
		$uploads            = wp_get_upload_dir();
		$this->uploads_base = trailingslashit( $uploads['basedir'] );
		$this->test_dir     = $this->uploads_base . 'yotm-phpunit-' . wp_generate_uuid4();
		wp_mkdir_p( $this->test_dir );
		$this->directories[] = $this->test_dir;
	}

	public function tearDown(): void {
		if ( $this->attachment_id ) {
			wp_delete_post( $this->attachment_id, true );
		}

		foreach ( array_unique( $this->files ) as $file ) {
			if ( is_link( $file ) || file_exists( $file ) ) {
				@unlink( $file );
			}
		}

		foreach ( array_reverse( array_unique( $this->directories ) ) as $directory ) {
			if ( is_dir( $directory ) ) {
				@rmdir( $directory );
			}
		}

		parent::tearDown();
	}

	public function test_path_outside_uploads_cannot_be_deleted() {
		$outside       = trailingslashit( sys_get_temp_dir() ) . 'yotm-outside-' . wp_generate_uuid4() . '.jpg';
		$this->files[] = $outside;
		file_put_contents( $outside, 'outside' );

		$this->assertSame( 0, yotm_delete_file_and_count( $outside ) );
		$this->assertFileExists( $outside );
	}

	public function test_symlink_to_outside_uploads_is_rejected() {
		if ( ! function_exists( 'symlink' ) ) {
			$this->markTestSkipped( 'Symlinks are unavailable.' );
		}

		$outside       = trailingslashit( sys_get_temp_dir() ) . 'yotm-target-' . wp_generate_uuid4() . '.jpg';
		$link          = trailingslashit( $this->test_dir ) . 'linked-150x150.jpg';
		$this->files[] = $outside;
		$this->files[] = $link;
		file_put_contents( $outside, 'outside' );

		if ( ! @symlink( $outside, $link ) ) {
			$this->markTestSkipped( 'The environment refused symlink creation.' );
		}

		$this->assertFalse( yotm_is_path_inside_dir( $link, $this->uploads_base ) );
		$result = yotm_delete_prune_item( array( 'path' => $link ), $this->uploads_base );
		$this->assertFalse( $result['deleted'] );
		$this->assertFileExists( $outside );
	}

	public function test_multiple_upload_scopes_are_deduplicated_and_resolved_safely() {
		$year_2020  = trailingslashit( $this->test_dir ) . '2020';
		$month_2020 = trailingslashit( $year_2020 ) . '01';
		$year_2021  = trailingslashit( $this->test_dir ) . '2021';
		$month_2021 = trailingslashit( $year_2021 ) . '02';
		wp_mkdir_p( $month_2020 );
		wp_mkdir_p( $month_2021 );
		$this->directories[] = $year_2020;
		$this->directories[] = $month_2020;
		$this->directories[] = $year_2021;
		$this->directories[] = $month_2021;

		$normalized = yotm_normalize_upload_subpaths( array( '2020/01', '2020', '2021/02', '2021/02' ) );
		$this->assertSame( array( '2020', '2021/02' ), $normalized );

		$resolved = yotm_resolve_upload_scan_bases( $this->test_dir, $normalized );
		$this->assertIsArray( $resolved );
		$this->assertCount( 2, $resolved );
		$this->assertSame( trailingslashit( yotm_normalize_filesystem_path( $year_2020 ) ), $resolved[0] );
		$this->assertSame( trailingslashit( yotm_normalize_filesystem_path( $month_2021 ) ), $resolved[1] );
		$this->assertTrue( yotm_is_path_inside_any_dir( $month_2020 . '/image.jpg', $resolved ) );
		$this->assertFalse( yotm_is_path_inside_any_dir( $year_2021 . '/03/image.jpg', $resolved ) );

		$query_args = yotm_attachment_query_args_for_upload_subpaths( $normalized );
		$this->assertSame( '^(2020|2021/02)/', $query_args['meta_query'][0]['value'] );
	}

	public function test_original_is_protected_and_deleted_thumbnail_metadata_is_removed() {
		$original      = trailingslashit( $this->test_dir ) . 'image-300x300.jpg';
		$thumbnail     = trailingslashit( $this->test_dir ) . 'image-300x300-150x150.jpg';
		$this->files[] = $original;
		$this->files[] = $thumbnail;
		file_put_contents( $original, 'original' );
		file_put_contents( $thumbnail, 'thumbnail' );

		$this->attachment_id = wp_insert_attachment(
			array(
				'post_mime_type' => 'image/jpeg',
				'post_title'     => 'YOTM PHPUnit',
				'post_status'    => 'inherit',
			),
			$original
		);
		$relative            = ltrim( str_replace( $this->uploads_base, '', $original ), '/' );
		wp_update_attachment_metadata(
			$this->attachment_id,
			array(
				'file'  => $relative,
				'sizes' => array(
					'thumbnail' => array(
						'file'      => basename( $thumbnail ),
						'width'     => 150,
						'height'    => 150,
						'mime-type' => 'image/jpeg',
					),
				),
			)
		);

		$candidates     = array();
		$orphan_summary = yotm_initial_orphan_summary();
		yotm_collect_metadata_prune_candidates_for_ids(
			array( $this->attachment_id ),
			trailingslashit( $this->test_dir ),
			array(),
			array( 'thumbnail' ),
			array(
				'thumbnail' => array(
					'width'  => 150,
					'height' => 150,
					'crop'   => true,
				),
			),
			false,
			$candidates,
			$orphan_summary
		);

		$paths = array_column( array_values( $candidates ), 'path' );
		$this->assertNotContains( yotm_normalize_filesystem_path( $original ), $paths );
		$this->assertContains( yotm_normalize_filesystem_path( $thumbnail ), $paths );

		$candidate = $candidates[ yotm_normalize_filesystem_path( $thumbnail ) ];
		$result    = yotm_delete_prune_item( $candidate, $this->uploads_base );
		$metadata  = wp_get_attachment_metadata( $this->attachment_id );

		$this->assertTrue( $result['deleted'] );
		$this->assertFileExists( $original );
		$this->assertArrayNotHasKey( 'thumbnail', $metadata['sizes'] );
	}

	public function test_force_regenerate_cleanup_removes_only_obsolete_generated_files() {
		$original      = trailingslashit( $this->test_dir ) . 'force-image.jpg';
		$stale         = trailingslashit( $this->test_dir ) . 'force-image-100x100.jpg';
		$current       = trailingslashit( $this->test_dir ) . 'force-image-200x200.jpg';
		$this->files[] = $original;
		$this->files[] = $stale;
		$this->files[] = $current;
		$old_metadata  = array(
			'sizes' => array(
				'old-size'     => array( 'file' => basename( $stale ) ),
				'current-size' => array( 'file' => basename( $current ) ),
			),
		);
		$new_metadata  = array(
			'sizes' => array(
				'current-size' => array( 'file' => basename( $current ) ),
			),
		);

		file_put_contents( $original, 'original' );
		file_put_contents( $stale, 'stale' );
		file_put_contents( $current, 'current' );
		yotm_cleanup_obsolete_generated_files( $original, $original, $old_metadata, $new_metadata );

		$this->assertFileDoesNotExist( $stale );
		$this->assertFileExists( $current );
		$this->assertFileExists( $original );
	}
}
