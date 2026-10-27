<?php
/**
 * Real both-active Prune -> owned sidecar cleanup and source freshness regression.
 * Run with wp eval-file on a disposable, idle site; fixtures/options are restored.
 *
 * @package Thumbnail_Manager
 */

// phpcs:disable WordPress.WP.AlternativeFunctions -- Disposable real filesystem/image/identity fixtures.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
$checks = 0;
$assert = static function ( $ok, $why ) use ( &$checks ) {
	++$checks;
	if ( ! $ok ) {
		throw new RuntimeException( esc_html( $why ) );
	}
};
$assert( yotm_optimizer_available() && is_callable( array( YoOhw_Media_Optimizer::instance(), 'prepare_prune_cleanup' ) ), 'Both Stage 2 plugins must be active.' );
$mo       = YoOhw_Media_Optimizer::instance();
$old_user = get_current_user_id();
$admins   = get_users(
	array(
		'role'   => 'administrator',
		'fields' => 'ids',
		'number' => 1,
	)
);
wp_set_current_user( (int) $admins[0] );
$missing = new stdClass();
$saved   = array();
foreach ( array( YoOhw_Media_Optimizer::OPTION_KEY, YoOhw_Media_Optimizer::PRUNE_CLEANUP_OPTION, YoOhw_Media_Optimizer::SOURCE_REVISION_OPTION, 'yotm_disabled_sizes' ) as $key ) {
	$saved[ $key ] = get_option( $key, $missing );
}
$assert( false === yotm_optimizer_active_job(), 'No unfinished TM job may exist on the disposable site.' );
$ids          = array();
$jobs         = array();
$dir          = trailingslashit( wp_get_upload_dir()['basedir'] ) . 'stage2-cooperation-' . wp_generate_uuid4();
$relative_dir = str_replace( trailingslashit( wp_get_upload_dir()['basedir'] ), '', $dir );
$only_sizes   = static function ( $sizes ) {
	return array_intersect_key( $sizes, array_flip( array( 'stage2_remove', 'stage2_keep' ) ) );
};
$finish_check = static function ( $fixture_id ) use ( &$jobs, $assert ) {
	$prepared = yotm_regenerate_prepare_application( 'ids', '', (string) $fixture_id, false, true, 'check', '', array( 'stage2_keep' ) );
	$assert( ! empty( $prepared['success'] ), 'Check preparation failed.' );
	$token  = $prepared['data']['token'];
	$jobs[] = yotm_job_get_audit( $token )['id'];
	for ( $n = 0; $n < 100; ++$n ) {
		$result = yotm_regenerate_batch_application( $token, 10 );
		yotm_job_release_all_workers();
		$assert( ! empty( $result['success'] ), 'Check batch failed.' );
		if ( ! empty( $result['data']['done'] ) ) {
			return $token; }
	}
	throw new RuntimeException( 'Check did not complete.' );
};
try {
	$assert( true === yotm_run_job_table_migration(), 'Job tables must be ready.' );
	delete_option( YoOhw_Media_Optimizer::PRUNE_CLEANUP_OPTION );
	delete_option( 'yotm_disabled_sizes' );
	$options                           = $mo->get_options();
	$options['auto_optimize_uploads']  = 0;
	$options['optimize_originals']     = 0;
	$options['generate_webp_sidecars'] = 1;
	$options['generate_avif_sidecars'] = 0;
	$options['skip_larger_files']      = 0;
	update_option( YoOhw_Media_Optimizer::OPTION_KEY, $options, false );
	$assert( wp_mkdir_p( $dir ), 'Fixture folder creation failed.' );
	add_image_size( 'stage2_remove', 120, 120, true );
	add_image_size( 'stage2_keep', 160, 100, true );
	add_filter( 'intermediate_image_sizes_advanced', $only_sizes, PHP_INT_MAX );
	require_once ABSPATH . 'wp-admin/includes/image.php';
	$fixture_targets = array();
	$preserved       = array();
	foreach ( array( 'valid', 'legacy', 'changed', 'replacement', 'source', 'alias', 'symlink' ) as $name ) {
		$source = $dir . '/' . $name . '.png';
		$image  = imagecreatetruecolor( 640, 480 );
		for ( $x = 0; $x < 640; ++$x ) {
			imageline( $image, $x, 0, $x, 479, imagecolorallocate( $image, $x % 256, ( $x * 3 ) % 256, ( $x * 7 ) % 256 ) );
		}
		$assert( imagepng( $image, $source ), 'PNG fixture write failed.' );
		imagedestroy( $image );
		$fixture_id   = wp_insert_attachment(
			array(
				'post_mime_type' => 'image/png',
				'post_status'    => 'inherit',
				'post_title'     => 'Stage 2 ' . $name,
			),
			$source
		);
		$ids[ $name ] = $fixture_id;
		$metadata     = wp_generate_attachment_metadata( $fixture_id, $source );
		wp_update_attachment_metadata( $fixture_id, $metadata );
		$assert( isset( $metadata['sizes']['stage2_remove'], $metadata['sizes']['stage2_keep'] ), 'Both thumbnail sizes must exist.' );
		$fixture_targets[ $name ] = $dir . '/' . $metadata['sizes']['stage2_remove']['file'];
		$result                   = $mo->optimize_attachment( $fixture_id, true );
		$assert( empty( $result['error'] ) && is_file( $fixture_targets[ $name ] . '.webp' ), 'Real WebP generation failed.' );
		$tracking = get_post_meta( $fixture_id, YoOhw_Media_Optimizer::META_KEY, true );
		$proofs   = array_filter(
			$tracking['files'],
			static function ( $row ) {
				return ! empty( $row['sidecar_ownership']['webp'] );
			}
		);
		$assert( count( $proofs ) >= 3, 'Successful publishers must record ownership for each file.' );
		foreach ( array( $source, $source . '.webp', $dir . '/' . $metadata['sizes']['stage2_keep']['file'], $dir . '/' . $metadata['sizes']['stage2_keep']['file'] . '.webp' ) as $keep ) {
			$preserved[ $keep ] = hash_file( 'sha256', $keep );
		}
		if ( 'legacy' === $name ) {
			foreach ( $tracking['files'] as &$row ) {
				unset( $row['sidecar_ownership'] ); }
			unset( $row );
			update_post_meta( $fixture_id, YoOhw_Media_Optimizer::META_KEY, $tracking );
		}
	}//end foreach
	$check_token  = $finish_check( $ids['valid'] );
	$before_check = yotm_job_results_application( $check_token );
	$assert( '' === $before_check['data']['items'][0]['health']['freshness_note'], 'Fresh check was incorrectly marked stale.' );
	$prepared = yotm_prune_prepare_application( array_values( array_diff( array_keys( yotm_get_registered_sizes() ), array( 'stage2_remove' ) ) ), array( $relative_dir ), false );
	$assert( ! empty( $prepared['success'] ), 'Prune preparation failed: ' . wp_json_encode( $prepared ) );
	$token  = $prepared['data']['token'];
	$jobs[] = yotm_job_get_audit( $token )['id'];
	for ( $n = 0; $n < 100; ++$n ) {
		$scan = yotm_prune_scan_application( $token, 100 );
		yotm_job_release_all_workers();
		$assert( ! empty( $scan['success'] ), 'Prune scan failed.' );
		if ( 'awaiting_approval' === yotm_job_get_audit( $token )['status'] ) {
			break; }
	}
	$job = yotm_job_get_audit( $token );
	$assert( 7 === (int) $job['total'], 'Prune must select only seven fixture thumbnail files.' );
	$approved = yotm_prune_approve_application( $token, $job['manifest_hash'], true );
	$assert( ! empty( $approved['success'] ), 'Prune approval failed.' );
	for ( $n = 0; $n < 20; ++$n ) {
		$deleted = yotm_prune_delete_application( $token, $job['manifest_hash'], 2 );
		yotm_job_release_all_workers();
		$assert( ! empty( $deleted['success'] ), 'Prune delete failed: ' . wp_json_encode( $deleted ) );
		if ( 'completed' === yotm_job_get_audit( $token )['status'] ) {
			break; }
	}
	foreach ( $fixture_targets as $target ) {
		$assert( ! file_exists( $target ) && is_file( $target . '.webp' ), 'Prune must delete thumbnails and preserve all sidecars.' ); }
	$evidence = yotm_optimizer_prune_handoff( $token );
	$assert( is_array( $evidence ) && 7 === count( $evidence['items'] ), 'Completed exact deletion evidence is unavailable.' );
	$probe = yotm_with_optimizer_guard(
		static function () use ( $ids, $fixture_targets, $assert ) {
			return yotm_optimizer_with_cleanup_guard(
				array( $fixture_targets['valid'], $fixture_targets['valid'] . '.webp' ),
				static function ( $checkpoint ) use ( $ids, $fixture_targets, $assert ) {
					$code = 'require $argv[1]."wp-load.php"; $id=(int)$argv[2]; $before=get_attached_file($id); update_attached_file($id,$argv[3]); echo json_encode(array("preserved"=>get_attached_file($id)===$before));';
					// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_proc_open -- Real concurrent WordPress metadata mutation must honor the cleanup fence.
					$child = proc_open( array( PHP_BINARY, '-d', 'display_errors=stderr', '-r', $code, ABSPATH, (string) $ids['valid'], $fixture_targets['valid'] . '.webp' ), array( array( 'pipe', 'r' ), array( 'pipe', 'w' ), array( 'pipe', 'w' ) ), $pipes );
					$assert( is_resource( $child ), 'Could not start metadata alias contender.' );
					fclose( $pipes[0] );
					$output = stream_get_contents( $pipes[1] );
					$stderr = stream_get_contents( $pipes[2] );
					fclose( $pipes[1] );
					fclose( $pipes[2] );
					$assert( 0 === proc_close( $child ), 'Metadata contender failed: ' . $stderr );
					$assert( true === $checkpoint(), 'Cleanup named-lock ownership must survive contention.' );
					return json_decode( $output, true );
				}
			);
		}
	);
	$assert( is_array( $probe ) && ! empty( $probe['preserved'] ), 'Cleanup must prevent concurrent promotion of a sidecar to a WordPress source.' );
	$lost_checkpoint = yotm_with_optimizer_guard(
		static function () use ( $fixture_targets, $assert ) {
			return yotm_optimizer_with_cleanup_guard(
				array( $fixture_targets['valid'] . '.webp' ),
				static function ( $checkpoint ) use ( $fixture_targets, $assert ) {
					$assert( true === $checkpoint(), 'Initial named-lock checkpoint failed.' );
					$canonical = yotm_media_source_canonical_path( $fixture_targets['valid'] . '.webp' );
					$assert( yotm_database_release_named_lock( yotm_media_path_lock_name( $canonical ) ), 'Failed to inject path-lock loss.' );
					return $checkpoint();
				}
			);
		}
	);
	$assert( is_wp_error( $lost_checkpoint ), 'Lost path lock must revoke the cleanup checkpoint.' );
	file_put_contents( $fixture_targets['changed'] . '.webp', 'replacement bytes' );
	unlink( $fixture_targets['symlink'] . '.webp' );
	$assert( symlink( $dir . '/valid.png.webp', $fixture_targets['symlink'] . '.webp' ), 'Symlink fixture creation failed.' );
	$alias                  = wp_insert_attachment(
		array(
			'post_mime_type' => 'image/webp',
			'post_status'    => 'inherit',
		),
		$fixture_targets['alias'] . '.webp'
	);
	$ids['protected_alias'] = $alias;
	$assert( ! is_wp_error( yotm_media_source_sync_attachment( $alias, null, true ) ), 'Alias index sync failed.' );
	$cleanup_preview = $mo->prepare_prune_cleanup( $token, 1 );
	$assert( empty( $cleanup_preview['error'] ) && 3 === count( $cleanup_preview['items'] ), 'Only valid, source-recreated-later and inode-replaced-later files may enter preview: ' . wp_json_encode( $cleanup_preview ) );
	$assert( ! empty( $mo->process_prune_cleanup( $cleanup_preview['id'], $cleanup_preview['hash'] )['error'] ), 'Preview is not delete approval.' );
	$assert( ! empty( $mo->approve_prune_cleanup( $cleanup_preview['id'], str_repeat( 'f', 64 ) )['error'] ), 'Tampered hash must fail.' );
	$owner = get_current_user_id();
	wp_set_current_user( 0 );
	$assert( ! empty( $mo->approve_prune_cleanup( $cleanup_preview['id'], $cleanup_preview['hash'] )['error'] ), 'Anonymous approval must fail.' );
	wp_set_current_user( $owner );
	$grant = $mo->approve_prune_cleanup( $cleanup_preview['id'], $cleanup_preview['hash'] );
	$assert( empty( $grant['error'] ), 'Exact separate cleanup approval failed.' );
	copy( $dir . '/source.png', $fixture_targets['source'] );
	copy( $fixture_targets['replacement'] . '.webp', $fixture_targets['replacement'] . '.webp.new' );
	rename( $fixture_targets['replacement'] . '.webp.new', $fixture_targets['replacement'] . '.webp' );
	$done = $mo->process_prune_cleanup( $cleanup_preview['id'], $cleanup_preview['hash'] );
	$assert( empty( $done['error'] ) && count( $done['items'] ) === $done['cursor'], 'Bounded cleanup did not finish.' );
	$assert( ! file_exists( $fixture_targets['valid'] . '.webp' ), 'The only unchanged approved sidecar must be deleted.' );
	foreach ( array( 'legacy', 'changed', 'replacement', 'source', 'alias', 'symlink' ) as $name ) {
		$assert( file_exists( $fixture_targets[ $name ] . '.webp' ) || is_link( $fixture_targets[ $name ] . '.webp' ), 'Unproven/replaced/referenced sidecar was deleted: ' . $name );
	}
	$replay = $mo->process_prune_cleanup( $cleanup_preview['id'], $cleanup_preview['hash'] );
	$assert( $replay['outcomes'] === $done['outcomes'], 'Completed replay must not repeat effects or change outcomes.' );
	foreach ( $preserved as $fixture_path => $hash ) {
		$assert( hash_file( 'sha256', $fixture_path ) === $hash, 'Source, kept thumbnail or unrelated sidecar changed.' ); }
	$assert( yotm_job_get_audit( $token )['succeeded'] === $job['total'], 'MO cleanup must not change successful TM outcomes.' );
	$assert( empty( $mo->discard_prune_cleanup( $cleanup_preview['id'] )['error'] ), 'Explicit owner discard failed.' );
	// A sidecar-only optimization must not stale the saved source observation.
	$mo->optimize_attachment( $ids['valid'], false );
	$after_sidecars = yotm_job_results_application( $check_token );
	$assert( '' === $after_sidecars['data']['items'][0]['health']['freshness_note'], 'Sidecar-only work must not invalidate source observations.' );
	$mo->optimize_attachment(
		$ids['valid'],
		true,
		null,
		array(
			'optimize_originals' => 1,
			'max_width'          => 320,
		)
	);
	$stale = yotm_job_results_application( $check_token );
	$assert( '' !== $stale['data']['items'][0]['health']['freshness_note'], 'Original optimization must mark saved observations stale.' );
	$new_check = $finish_check( $ids['valid'] );
	$assert( '' === yotm_job_results_application( $new_check )['data']['items'][0]['health']['freshness_note'], 'A fresh check must capture the current revision.' );
	$restore = $mo->restore_attachment( $ids['valid'] );
	$assert( empty( $restore['error'] ) && $restore['restored'] > 0, 'Real restore failed.' );
	$assert( '' !== yotm_job_results_application( $new_check )['data']['items'][0]['health']['freshness_note'], 'Restore must stale a prior check even when old bytes return.' );
	$marker = array(
		'token'    => wp_generate_uuid4(),
		'changing' => true,
	);
	update_post_meta( $ids['valid'], YoOhw_Media_Optimizer::SOURCE_REVISION_META, $marker );
	$in_progress_check = $finish_check( $ids['valid'] );
	$assert( '' !== yotm_job_results_application( $in_progress_check )['data']['items'][0]['health']['freshness_note'], 'An observation captured during a source attempt must remain uncertain even with the same token.' );
	update_post_meta(
		$ids['valid'],
		YoOhw_Media_Optimizer::SOURCE_REVISION_META,
		array(
			'token'    => wp_generate_uuid4(),
			'changing' => false,
		)
	);
	$assert( '' !== yotm_job_results_application( $in_progress_check )['data']['items'][0]['health']['freshness_note'], 'A completed attempt must not make its in-progress observation fresh.' );
	echo 'PASS — ' . esc_html( $checks ) . " assertions: real Prune, separate sidecar approval, legacy/hash/node/source/alias/symlink preservation, replay, scope and source freshness.\n";
} finally {
	yotm_job_release_all_workers();
	foreach ( $jobs as $fixture_id ) {
		yotm_job_delete( $fixture_id ); }
	remove_filter( 'intermediate_image_sizes_advanced', $only_sizes, PHP_INT_MAX );
	remove_image_size( 'stage2_remove' );
	remove_image_size( 'stage2_keep' );
	foreach ( $ids as $fixture_id ) {
		wp_delete_attachment( $fixture_id, true ); }
	if ( is_dir( $dir ) ) {
		foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST ) as $file ) {
			$file->isDir() && ! $file->isLink() ? rmdir( $file->getPathname() ) : unlink( $file->getPathname() );
		}
		rmdir( $dir );
	}
	foreach ( $saved as $key => $value ) {
		$missing === $value ? delete_option( $key ) : update_option( $key, $value, false ); }
	wp_set_current_user( $old_user );
}//end try
