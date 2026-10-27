<?php
/**
 * Both-active Thumbnail Manager / Media Optimizer runtime regression.
 *
 * Run only on a disposable, idle WordPress site with both current plugins active:
 * wp eval-file wp-content/plugins/thumbnail-manager/tests/optimizer-cooperation-smoke.php
 *
 * Fixtures and touched settings are restored. Existing jobs/media are never used.
 * This certifies PHP/filesystem orchestration, not browser transport or all codecs.
 *
 * @package Thumbnail_Manager
 */

// phpcs:disable WordPress.WP.AlternativeFunctions -- Intentional local fixture I/O, image creation and cross-process mutex probe.

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$assertions = 0;
$assert     = static function ( $condition, $message ) use ( &$assertions ) {
	++$assertions;
	if ( ! $condition ) {
		throw new RuntimeException( esc_html( $message ) );
	}
};
$pass       = static function ( $message ) {
	echo 'PASS — ' . esc_html( $message ) . "\n";
};
$assert( function_exists( 'yotm_optimizer_handoff' ) && yotm_optimizer_available(), 'Both compatible plugins must be active.' );
$assert( function_exists( 'imagecreatetruecolor' ) && function_exists( 'imagewebp' ), 'GD PNG/WebP support is required; this smoke must not silently skip encoding.' );
$assert( false === yotm_optimizer_active_job(), 'The disposable site must have no unfinished Thumbnail Manager job.' );
$optimizer = YoOhw_Media_Optimizer::instance();
$missing   = new stdClass();
$keys      = array( YoOhw_Media_Optimizer::OPTION_KEY, YoOhw_Media_Optimizer::QUEUE_OPTION, YoOhw_Media_Optimizer::QUEUE_LOCK_OPTION, 'yotm_disabled_sizes' );
$saved     = array();
foreach ( $keys as $key ) {
	$saved[ $key ] = get_option( $key, $missing );
}
$assert( empty( $saved[ YoOhw_Media_Optimizer::QUEUE_LOCK_OPTION ] ) || $missing === $saved[ YoOhw_Media_Optimizer::QUEUE_LOCK_OPTION ], 'An optimizer worker must not be active.' );
$old_user = get_current_user_id();
$admins   = get_users(
	array(
		'role'   => 'administrator',
		'fields' => 'ids',
		'number' => 1,
	)
);
$assert( ! empty( $admins ), 'A disposable-site administrator is required.' );
wp_set_current_user( (int) $admins[0] );
$owner         = get_current_user_id();
$jobs          = array();
$fixture_ids   = array();
$dir           = trailingslashit( wp_get_upload_dir()['basedir'] ) . 'yotm-optimizer-cooperation-' . wp_generate_uuid4();
$filter_hits   = 0;
$loss_filter   = null;
$commit_filter = null;
$child         = null;
$other_user    = 0;
$pipes         = array();
$size_filter   = static function ( $sizes ) {
	return array_intersect_key( $sizes, array_flip( array( 'yotm_coop_selected', 'yotm_coop_keep' ) ) );
};
$editor_filter = static function () {
	return array( 'WP_Image_Editor_GD' );
};
$invoke        = static function ( $name, ...$parameters ) use ( $optimizer ) {
	$method = new ReflectionMethod( $optimizer, $name );
	$method->setAccessible( true );
	return $method->invokeArgs( $optimizer, $parameters );
};
$track_job     = static function ( $response ) use ( $assert, &$jobs ) {
	$assert( ! empty( $response['success'] ) && ! empty( $response['data']['token'] ), 'Job preparation failed: ' . wp_json_encode( $response ) );
	$job = yotm_job_get( $response['data']['token'] );
	$assert( is_array( $job ), 'Prepared job is unavailable.' );
	$jobs[] = $job['id'];
	return $job;
};
$finish        = static function ( $job ) use ( $assert ) {
	for ( $step = 0; $step < 100; ++$step ) {
		$result = yotm_regenerate_batch_application( $job['token'], 2 );
		yotm_job_release_all_workers();
		$assert( ! empty( $result['success'] ), 'Thumbnail batch failed: ' . wp_json_encode( $result ) );
		if ( ! empty( $result['data']['done'] ) ) {
			$final = yotm_job_get_by_id( $job['id'] );
			$assert( 'completed' === $final['status'] && 0 === (int) $final['failed'], 'Thumbnail job did not complete successfully: ' . wp_json_encode( $result ) );
			return $final;
		}
	}
	throw new RuntimeException( 'Thumbnail job exceeded bounded smoke iterations.' );
};
$files         = static function ( $root ) {
	$result = array();
	foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ) ) as $file ) {
		if ( $file->isFile() ) {
			$result[ $file->getPathname() ] = hash_file( 'sha256', $file->getPathname() );
		}
	}
	ksort( $result );
	return $result;
};
$prepare       = static function ( $fixture_id, $force, $operation = '' ) use ( $track_job ) {
	return $track_job( yotm_regenerate_prepare_application( 'ids', '', (string) $fixture_id, $force, ! $force, $operation, '', array( 'yotm_coop_selected' ) ) );
};

try {
	$assert( true === yotm_run_job_table_migration(), 'Job storage must be ready.' );
	delete_option( YoOhw_Media_Optimizer::QUEUE_OPTION );
	delete_option( 'yotm_disabled_sizes' );
	$options                           = $optimizer->get_options();
	$options['auto_optimize_uploads']  = 0;
	$options['optimize_originals']     = 0;
	$options['generate_webp_sidecars'] = 1;
	$options['generate_avif_sidecars'] = 0;
	$options['skip_larger_files']      = 0;
	update_option( YoOhw_Media_Optimizer::OPTION_KEY, $options, false );
	$assert( wp_mkdir_p( $dir ), 'Could not create the fixture directory.' );
	add_image_size( 'yotm_coop_selected', 120, 120, true );
	add_image_size( 'yotm_coop_keep', 160, 100, true );
	add_filter( 'intermediate_image_sizes_advanced', $size_filter, PHP_INT_MAX );
	add_filter( 'wp_image_editors', $editor_filter, PHP_INT_MAX );
	require_once ABSPATH . 'wp-admin/includes/image.php';
	foreach ( array( 'repair', 'force', 'changed', 'ownership' ) as $name ) {
		$fixture_path = $dir . '/' . $name . '.png';
		$image        = imagecreatetruecolor( 640, 480 );
		for ( $x = 0; $x < 640; ++$x ) {
			$color = imagecolorallocate( $image, $x % 256, ( $x * 3 ) % 256, ( $x * 7 ) % 256 );
			imageline( $image, $x, 0, $x, 479, $color );
		}
		$assert( imagepng( $image, $fixture_path ), 'Could not write the image fixture.' );
		imagedestroy( $image );
		$fixture_id = wp_insert_attachment(
			array(
				'post_mime_type' => 'image/png',
				'post_status'    => 'inherit',
				'post_title'     => 'Optimizer cooperation ' . $name,
			),
			$fixture_path
		);
		$assert( is_int( $fixture_id ) && $fixture_id > 0, 'Could not create a fixture attachment.' );
		$fixture_ids[ $name ] = $fixture_id;
		$metadata             = wp_generate_attachment_metadata( $fixture_id, $fixture_path );
		$assert( isset( $metadata['sizes']['yotm_coop_selected'], $metadata['sizes']['yotm_coop_keep'] ), 'Both fixture sizes must be generated.' );
		$metadata['cooperation_sentinel'] = array( 'keep' => $name );
		wp_update_attachment_metadata( $fixture_id, $metadata );
		$assert( ! is_wp_error( yotm_media_source_sync_attachment( $fixture_id, null, true ) ), 'Could not index the fixture.' );
	}//end foreach
	$options['auto_optimize_uploads'] = 1;
	$options['optimize_originals']    = 1;
	update_option( YoOhw_Media_Optimizer::OPTION_KEY, $options, false );

	foreach ( array( 'repair', 'force' ) as $name ) {
		$fixture_id = $fixture_ids[ $name ];
		$before     = get_post_meta( $fixture_id, '_wp_attachment_metadata', true );
		$source     = get_attached_file( $fixture_id );
		$sourcehash = hash_file( 'sha256', $source );
		$keep       = $dir . '/' . $before['sizes']['yotm_coop_keep']['file'];
		$keephash   = hash_file( 'sha256', $keep );
		$selected   = $dir . '/' . $before['sizes']['yotm_coop_selected']['file'];
		$tracking   = array(
			'schema'             => 3,
			'options'            => array(
				'optimize_originals' => 1,
				'jpeg_quality'       => 61,
			),
			'fixture_provenance' => 'retained',
			'files'              => array(
				array(
					'source'                       => ltrim( str_replace( wp_get_upload_dir()['basedir'], '', $source ), '/' ),
					'backup_path'                  => 'private-fixture-reference',
					'optimized_hash'               => $sourcehash,
					'original_input_hash'          => $sourcehash,
					'original_options_fingerprint' => 'fixture-fingerprint',
					'original_status'              => 'existing',
				),
			),
		);
		update_post_meta( $fixture_id, YoOhw_Media_Optimizer::META_KEY, $tracking );
		if ( 'repair' === $name ) {
			$assert( unlink( $selected ), 'Could not create the missing-size fixture.' );
			$check = $finish( $prepare( $fixture_id, false, 'check' ) );
			$assert( is_wp_error( yotm_optimizer_handoff( $check['token'] ) ), 'Check results must never authorize optimization.' );
			$job = $track_job( yotm_thumbnail_health_prepare_repair( $check['token'] ) );
		} else {
			$job = $prepare( $fixture_id, true );
		}
		// TM persisted authority must exclude MO even between separate TM batches.
		$busy = $optimizer->optimize_attachment( $fixture_id );
		$assert( ! empty( $busy['error'] ) && 0 === (int) $busy['processed'], 'MO must reject media writes while a TM job is active.' );
		$job   = $finish( $job );
		$after = get_post_meta( $fixture_id, '_wp_attachment_metadata', true );
		$assert( $before === $after && hash_file( 'sha256', $source ) === $sourcehash && hash_file( 'sha256', $keep ) === $keephash, 'Scoped TM operation changed a source, unrelated size or metadata.' );
		$assert( get_post_meta( $fixture_id, YoOhw_Media_Optimizer::META_KEY, true ) === $tracking, 'TM metadata filters triggered MO tracking/optimization.' );
		$assert( ! glob( $dir . '/*.webp' ) && ! glob( $dir . '/*.avif' ), 'TM must not automatically create sidecars.' );
		$before_preview = $files( $dir );
		$queue_before   = get_option( YoOhw_Media_Optimizer::QUEUE_OPTION, $missing );
		$handoff        = $invoke( 'thumbnail_manager_source', $job['token'] );
		$assert( 1 === (int) $handoff['total'] && 1 === count( $handoff['items'][0]['files'] ) && 'yotm_coop_selected' === $handoff['items'][0]['files'][0]['size'], 'Handoff selection broadened beyond exact scoped output.' );
		$assert( $files( $dir ) === $before_preview && get_option( YoOhw_Media_Optimizer::QUEUE_OPTION, $missing ) === $queue_before, 'Opening the preview source must be read-only.' );
		// An apparently fresh old sidecar must still be rebuilt after an exact TM handoff.
		$old_sidecar = imagecreatetruecolor( 120, 120 );
		imagefill( $old_sidecar, 0, 0, imagecolorallocate( $old_sidecar, 230, 20, 20 ) );
		$assert( imagewebp( $old_sidecar, $selected . '.webp', 82 ), 'Could not create an old sidecar fixture.' );
		imagedestroy( $old_sidecar );
		touch( $selected . '.webp', time() + 30 );
		$old_sidecar_hash = hash_file( 'sha256', $selected . '.webp' );
		$run              = $optimizer->run_thumbnail_manager_batch( $job['token'], '', true, 1 );
		$assert( empty( $run['error'] ) && empty( $run['hasMore'] ) && 1 === (int) $run['processedTotal'], 'Explicit scoped run did not finish: ' . wp_json_encode( $run ) );
		$assert( is_file( $selected . '.webp' ) && 'image/webp' === wp_get_image_mime( $selected . '.webp' ), 'Explicit run did not create a real selected WebP sidecar.' );
		$assert( hash_file( 'sha256', $selected . '.webp' ) !== $old_sidecar_hash, 'Exact handoff must rebuild even an apparently fresh existing sidecar.' );
		$assert( ! is_file( $source . '.webp' ) && ! is_file( $keep . '.webp' ), 'Scoped handoff expanded to original or unrelated size.' );
		$assert( get_post_meta( $fixture_id, '_wp_attachment_metadata', true ) === $after && hash_file( 'sha256', $source ) === $sourcehash && hash_file( 'sha256', $keep ) === $keephash, 'Explicit handoff changed attachment metadata or preserved bytes.' );
		$updated = get_post_meta( $fixture_id, YoOhw_Media_Optimizer::META_KEY, true );
		$assert( $updated['options'] === $tracking['options'] && $updated['files'][0] === $tracking['files'][0] && 'retained' === $updated['fixture_provenance'], 'Handoff erased unrelated backup/fingerprint/option provenance.' );
		$queue = get_option( YoOhw_Media_Optimizer::QUEUE_OPTION );
		$assert( 'thumbnail_manager' === $queue['kind'] && array( $fixture_id ) === array_keys( $queue['items'] ), 'Scoped queue imported other library images.' );
		$stale = $optimizer->run_thumbnail_manager_batch( $job['token'], '', true, 1 );
		$assert( ! empty( $stale['error'] ), 'A replayed start must not replace a completed handoff with a library run.' );
		$wrong = $optimizer->run_thumbnail_manager_batch( $job['token'], wp_generate_uuid4(), false, 1 );
		$assert( ! empty( $wrong['error'] ), 'A tampered run ID must fail closed.' );
		$pass( $name . ': auto/originals enabled, scoped TM commit, read-only source, explicit WebP, source/unrelated metadata/backup provenance, replay and wrong run' );
		$assert( unlink( $selected . '.webp' ), 'Could not remove the owned sidecar between fixtures.' );
		delete_option( YoOhw_Media_Optimizer::QUEUE_OPTION );
	}//end foreach

	$fixture_id    = $fixture_ids['changed'];
	$job           = $finish( $prepare( $fixture_id, true ) );
	$metadata      = get_post_meta( $fixture_id, '_wp_attachment_metadata', true );
	$selected      = $dir . '/' . $metadata['sizes']['yotm_coop_selected']['file'];
	$selected_hash = hash_file( 'sha256', $selected );
	$alias_id      = wp_insert_attachment(
		array(
			'post_mime_type' => 'image/png',
			'post_status'    => 'inherit',
			'post_title'     => 'Optimizer protected source alias',
		),
		$selected
	);
	$assert( is_int( $alias_id ) && $alias_id > 0, 'Could not create the protected-source alias attachment.' );
	$fixture_ids['source_alias'] = $alias_id;
	$alias_relative              = ltrim( str_replace( trailingslashit( wp_get_upload_dir()['basedir'] ), '', $selected ), '/' );
	$alias_metadata              = array(
		'file'   => $alias_relative,
		'width'  => 120,
		'height' => 120,
		'sizes'  => array(),
	);
	update_post_meta( $alias_id, '_wp_attachment_metadata', $alias_metadata );
	$assert( ! is_wp_error( yotm_media_source_sync_attachment( $alias_id, null, true ) ), 'Could not index the cross-attachment protected source.' );
	$assert( true === yotm_media_source_path_is_authoritative( $selected ), 'Alias fixture must be a proven protected full source, not only an unknown index.' );
	$run   = $optimizer->run_thumbnail_manager_batch( $job['token'], '', true, 1 );
	$queue = get_option( YoOhw_Media_Optimizer::QUEUE_OPTION );
	$assert( 'failed' === $queue['items'][ $fixture_id ]['status'] && ! is_file( $selected . '.webp' ), 'A generated path that is another attachment full source must fail without encoding.' );
	$assert( hash_file( 'sha256', $selected ) === $selected_hash && get_post_meta( $fixture_id, '_wp_attachment_metadata', true ) === $metadata && get_post_meta( $alias_id, '_wp_attachment_metadata', true ) === $alias_metadata, 'Cross-attachment veto changed source bytes or either attachment metadata.' );
	// Detach the borrowed source before deleting this script's alias record.
	delete_post_meta( $alias_id, '_wp_attached_file' );
	delete_post_meta( $alias_id, '_wp_attachment_metadata' );
	wp_delete_attachment( $alias_id, true );
	unset( $fixture_ids['source_alias'] );
	delete_option( YoOhw_Media_Optimizer::QUEUE_OPTION );
	$assert( false === yotm_media_source_path_is_authoritative( $selected ), 'Removing the alias must restore the independent changed-hash fixture precondition.' );
	$pass( 'exact generated output newly referenced as another attachment full source is protected despite unchanged bytes' );
	$original = file_get_contents( $selected );
	$mtime    = filemtime( $selected );
	$assert( strlen( $original ) > 40, 'Changed-source fixture is too short.' );
	$changed                           = $original;
	$changed[ strlen( $changed ) - 1 ] = chr( ord( $changed[ strlen( $changed ) - 1 ] ) ^ 1 );
	$assert( strlen( $original ) === file_put_contents( $selected, $changed ) && touch( $selected, $mtime ), 'Could not change source while preserving byte count and mtime.' );
	clearstatcache( true, $selected );
	$assert( filemtime( $selected ) === $mtime && strlen( $original ) === filesize( $selected ), 'Changed-source fixture lost its identical size/mtime precondition.' );
	$run   = $optimizer->run_thumbnail_manager_batch( $job['token'], '', true, 1 );
	$queue = get_option( YoOhw_Media_Optimizer::QUEUE_OPTION );
	$assert( 'failed' === $queue['items'][ $fixture_id ]['status'] && ! is_file( $selected . '.webp' ) && get_post_meta( $fixture_id, '_wp_attachment_metadata', true ) === $metadata, 'Changed hash with identical mtime/size must reject without file/metadata expansion.' );
	file_put_contents( $selected, $original );
	touch( $selected, $mtime );
	delete_option( YoOhw_Media_Optimizer::QUEUE_OPTION );
	$before = $files( $dir );
	wp_set_current_user( 0 );
	$assert( is_wp_error( yotm_optimizer_handoff( $job['token'] ) ) && ! empty( $optimizer->run_thumbnail_manager_batch( $job['token'], '', true, 1 )['error'] ), 'An anonymous caller must not reuse saved authority.' );
	wp_set_current_user( $owner );
	$other_user = wp_insert_user(
		array(
			'user_login' => 'yotm-coop-' . wp_generate_uuid4(),
			'user_pass'  => wp_generate_password(),
			'role'       => 'administrator',
		)
	);
	$assert( is_int( $other_user ) && $other_user > 0, 'Could not create an alternate owner fixture.' );
	wp_set_current_user( $other_user );
	$assert( is_wp_error( yotm_optimizer_handoff( $job['token'] ) ) && ! empty( $optimizer->run_thumbnail_manager_batch( $job['token'], '', true, 1 )['error'] ), 'Wrong owner must fail closed.' );
	wp_set_current_user( $owner );
	yotm_job_update( $job['id'], array( 'expires_at' => gmdate( 'Y-m-d H:i:s', time() - 60 ) ) );
	$assert( is_wp_error( yotm_optimizer_handoff( $job['token'] ) ) && ! empty( $optimizer->run_thumbnail_manager_batch( $job['token'], '', true, 1 )['error'] ), 'Expired authority must fail closed.' );
	$assert( ! empty( $optimizer->run_thumbnail_manager_batch( wp_generate_uuid4(), '', true, 1 )['error'] ) && $files( $dir ) === $before, 'Invalid token must not produce media effects.' );
	$pass( 'changed sha256 with identical size/mtime, anonymous caller, wrong owner, expiry and invalid token fail closed' );

	// A second process owns the exact native mutex used by MO, while TM tries to create work.
	$lock_path = trailingslashit( realpath( wp_get_upload_dir()['basedir'] ) ) . '.yoohw-media-optimizer.lock';
	$barrier   = $dir . '/native-ready';
	$release   = $dir . '/native-release';
	$code      = '$h=fopen($argv[1],"rb");if(!$h||!flock($h,LOCK_EX|LOCK_NB)){exit(2);}file_put_contents($argv[2],"ready");$until=microtime(true)+15;while(!file_exists($argv[3])&&microtime(true)<$until){usleep(10000);}flock($h,LOCK_UN);fclose($h);';
	// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_proc_open -- Separate native owner is required to prove actual flock contention.
	$child = proc_open( array( PHP_BINARY, '-r', $code, $lock_path, $barrier, $release ), array( array( 'pipe', 'r' ), array( 'pipe', 'w' ), array( 'pipe', 'w' ) ), $pipes );
	$assert( is_resource( $child ), 'Could not start the native mutex contender.' );
	fclose( $pipes[0] );
	$deadline = microtime( true ) + 5;
	while ( ! is_file( $barrier ) && microtime( true ) < $deadline ) {
		usleep( 10000 );
	}
	$assert( is_file( $barrier ), 'Native child failed to acquire the mutex.' );
	$before = $files( $dir );
	$busy   = yotm_regenerate_prepare_application( 'ids', '', (string) $fixture_ids['force'], true, false, '', '', array( 'yotm_coop_selected' ) );
	$assert( empty( $busy['success'] ) && 409 === $busy['status'] && $files( $dir ) === $before, 'TM must fail closed before job creation while another process owns MO mutex.' );
	file_put_contents( $release, 'release' );
	$stderr = stream_get_contents( $pipes[2] );
	fclose( $pipes[1] );
	fclose( $pipes[2] );
	$assert( 0 === proc_close( $child ), 'Native mutex child failed: ' . $stderr );
	$child = null;
	$pass( 'cross-process MO native mutex excludes TM job creation; persisted TM authority excludes MO between batches' );
	$probe = yotm_with_optimizer_guard(
		static function () use ( $assert, $fixture_ids, $owner, &$child, &$pipes ) {
			$probe_code = 'require $argv[1]."wp-load.php";wp_set_current_user((int)$argv[2]);$r=YoOhw_Media_Optimizer::instance()->optimize_attachment((int)$argv[3]);echo json_encode(array("error"=>!empty($r["error"]),"processed"=>$r["processed"]??-1));';
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_proc_open -- A separately bootstrapped WordPress worker proves cross-process exclusion.
			$child = proc_open( array( PHP_BINARY, '-d', 'display_errors=stderr', '-r', $probe_code, ABSPATH, (string) $owner, (string) $fixture_ids['force'] ), array( array( 'pipe', 'r' ), array( 'pipe', 'w' ), array( 'pipe', 'w' ) ), $pipes );
			$assert( is_resource( $child ), 'Could not start the optimizer contender.' );
			fclose( $pipes[0] );
			$output = stream_get_contents( $pipes[1] );
			$stderr = stream_get_contents( $pipes[2] );
			fclose( $pipes[1] );
			fclose( $pipes[2] );
			$assert( 0 === proc_close( $child ), 'Optimizer contender failed: ' . $stderr );
			$child = null;
			return json_decode( $output, true );
		}
	);
	$assert( is_array( $probe ) && ! empty( $probe['error'] ) && 0 === (int) $probe['processed'], 'A TM-held shared mutex must exclude an MO worker in another WordPress process.' );
	$pass( 'TM-held shared mutex excludes a real MO optimize_attachment worker in another WordPress process' );

	// Fault injection at the last metadata filter, before any live promotion.
	$fixture_id  = $fixture_ids['ownership'];
	$before      = $files( $dir );
	$metadata    = get_post_meta( $fixture_id, '_wp_attachment_metadata', true );
	$loss_filter = static function ( $value, $attachment_id ) use ( $fixture_id, &$filter_hits ) {
		if ( $fixture_id === (int) $attachment_id && yotm_optimizer_mutation_active() ) {
			++$filter_hits;
			update_option(
				YoOhw_Media_Optimizer::QUEUE_LOCK_OPTION,
				array(
					'token'       => 'lost-owner-fixture',
					'native'      => 1,
					'acquired_at' => time(),
				),
				false
			);
		}
		return $value;
	};
	add_filter( 'wp_update_attachment_metadata', $loss_filter, PHP_INT_MAX, 2 );
	$job = $prepare( $fixture_id, true );
	for ( $step = 0; $step < 100 && 0 === $filter_hits; ++$step ) {
		$result = yotm_regenerate_batch_application( $job['token'], 2 );
		yotm_job_release_all_workers();
		if ( 0 === $filter_hits ) {
			$assert( ! empty( $result['success'] ), 'Preparation failed before ownership injection.' );
		}
	}
	remove_filter( 'wp_update_attachment_metadata', $loss_filter, PHP_INT_MAX );
	$loss_filter = null;
	$assert( 1 === $filter_hits && empty( $result['success'] ), 'External ownership loss must surface as failure.' );
	$assert( get_post_meta( $fixture_id, '_wp_attachment_metadata', true ) === $metadata, 'Lost ownership must stop metadata commit.' );
	$item = yotm_job_get_item_by_key( $job['id'], hash( 'sha256', 'attachment:' . $fixture_id ) );
	$assert( ! empty( $item['payload']['regeneration_journal'] ), 'Lost promotion/rollback ownership must retain recovery evidence.' );
	foreach ( $before as $fixture_path => $hash ) {
		$assert( is_file( $fixture_path ) && hash_file( 'sha256', $fixture_path ) === $hash, 'Lost ownership changed an existing live fixture.' );
	}
	$pass( 'ownership loss before TM promotion stops live writes/rollback and retains regeneration recovery evidence' );

	// A failed final metadata write must recheck ownership before trying to restore old metadata.
	$commit_calls   = 0;
	$commit_result  = '';
	$end_result     = null;
	$commit_journal = $item['payload']['regeneration_journal'];
	$commit_journal['final_metadata']['cooperation_failed_commit'] = 'must-not-persist';
	$before_commit_files = $files( $dir );
	$before_commit_item  = yotm_job_get_item_by_key( $job['id'], hash( 'sha256', 'attachment:' . $fixture_id ) );
	$commit_filter       = static function ( $check, $attachment_id, $meta_key ) use ( $fixture_id, $optimizer, &$commit_calls, &$end_result ) {
		if ( $fixture_id === (int) $attachment_id && '_wp_attachment_metadata' === $meta_key ) {
			++$commit_calls;
			if ( 1 === $commit_calls ) {
				$end_result = $optimizer->end_thumbnail_manager_mutation( $GLOBALS['yotm_optimizer_scope']['token'] );
			}
			return false;
		}
		return $check;
	};
	add_filter( 'update_post_metadata', $commit_filter, PHP_INT_MAX, 3 );
	$commit_guard = yotm_with_optimizer_guard(
		static function () use ( $fixture_id, $commit_journal, $metadata, &$commit_result ) {
			$commit_result = yotm_regenerate_commit_metadata( $fixture_id, $commit_journal, $metadata );
			return $commit_result;
		}
	);
	remove_filter( 'update_post_metadata', $commit_filter, PHP_INT_MAX );
	$commit_filter = null;
	$assert( true === $end_result, 'Fault injection must actually release the real optimizer token during the failed final write.' );
	$assert( 1 === $commit_calls && 'incomplete' === $commit_result && is_wp_error( $commit_guard ), 'Lost metadata-commit ownership must stop before a second old-metadata restoration write.' );
	$assert( get_post_meta( $fixture_id, '_wp_attachment_metadata', true ) === $metadata, 'Failed metadata commit changed the old metadata.' );
	$assert( yotm_job_get_item_by_key( $job['id'], hash( 'sha256', 'attachment:' . $fixture_id ) ) === $before_commit_item, 'Failed metadata commit must retain the unchanged persisted recovery journal.' );
	$assert( $files( $dir ) === $before_commit_files, 'Lost metadata-commit ownership must not roll back or remove staged/live files.' );
	$pass( 'token loss while final metadata write is refused blocks the second restore write and file rollback, retaining recovery evidence' );
	echo 'PASS — ' . (int) $assertions . " runtime assertions; both current plugins active; real GD PNG/WebP path.\n";
	echo "NOT RUN — browser HTTP/nonce/capability transport, multisite switch, hostile filesystem races, process death after promotion and full codec matrix; covered separately where applicable.\n";
} finally {
	if ( is_resource( $child ) ) {
		proc_terminate( $child, 9 );
		foreach ( $pipes as $pipe ) {
			if ( is_resource( $pipe ) ) {
				fclose( $pipe );
			}
		}
		proc_close( $child );
	}
	if ( null !== $loss_filter ) {
		remove_filter( 'wp_update_attachment_metadata', $loss_filter, PHP_INT_MAX );
	}
	if ( null !== $commit_filter ) {
		remove_filter( 'update_post_metadata', $commit_filter, PHP_INT_MAX );
	}
	yotm_job_release_all_workers();
	yotm_media_source_shutdown_cleanup();
	remove_filter( 'intermediate_image_sizes_advanced', $size_filter, PHP_INT_MAX );
	remove_filter( 'wp_image_editors', $editor_filter, PHP_INT_MAX );
	remove_image_size( 'yotm_coop_selected' );
	remove_image_size( 'yotm_coop_keep' );
	// Disable MO auto processing only while removing this script's own fixtures.
	$options['auto_optimize_uploads'] = 0;
	update_option( YoOhw_Media_Optimizer::OPTION_KEY, $options, false );
	foreach ( $jobs as $job_id ) {
		yotm_job_delete( $job_id );
	}
	if ( isset( $fixture_ids['source_alias'] ) ) {
		delete_post_meta( $fixture_ids['source_alias'], '_wp_attached_file' );
		delete_post_meta( $fixture_ids['source_alias'], '_wp_attachment_metadata' );
	}
	foreach ( $fixture_ids as $fixture_id ) {
		wp_delete_attachment( $fixture_id, true );
	}
	if ( is_dir( $dir ) ) {
		$nodes = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
		foreach ( $nodes as $node ) {
			if ( $node->isDir() && ! $node->isLink() ) {
				rmdir( $node->getPathname() );
			} else {
				unlink( $node->getPathname() );
			}
		}
		rmdir( $dir );
	}
	foreach ( $saved as $key => $value ) {
		if ( $missing === $value ) {
			delete_option( $key );
		} else {
			update_option( $key, $value, false );
		}
	}
	if ( $other_user ) {
		require_once ABSPATH . 'wp-admin/includes/user.php';
		wp_delete_user( $other_user );
	}
	wp_set_current_user( $old_user );
}//end try
