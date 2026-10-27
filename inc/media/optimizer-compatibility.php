<?php
/**
 * Optional Media Optimizer interoperability using its existing mutation mutex.
 *
 * @package Thumbnail_Manager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Check support for this integration.
 *
 * @return bool
 */
function yotm_optimizer_available() {
	return class_exists( 'YoOhw_Media_Optimizer' )
		&& defined( 'YoOhw_Media_Optimizer::THUMBNAIL_MANAGER_API' )
		&& 1 === YoOhw_Media_Optimizer::THUMBNAIL_MANAGER_API
		&& is_callable( array( YoOhw_Media_Optimizer::instance(), 'begin_thumbnail_manager_mutation' ) );
}

/**
 * Check whether this request owns a Thumbnail Manager mutation scope.
 *
 * @return bool
 */
function yotm_optimizer_mutation_active() {
	return ! empty( $GLOBALS['yotm_optimizer_scope'] );
}

/**
 * Read-only exclusion check called by Optimizer while holding its own mutex.
 *
 * @return false|WP_Error False only when no Thumbnail Manager authority is active.
 */
function yotm_optimizer_active_job() {
	$ready = yotm_job_storage_ready();
	if ( is_wp_error( $ready ) ) {
		return $ready;
	}
	$job = yotm_job_find_destructive_lock();
	return is_wp_error( $job ) ? $job : ( $job ? new WP_Error( 'yotm_optimizer_job_active', __( 'A Thumbnail Manager job is unfinished. Complete or cancel it before optimizing media.', 'thumbnail-manager' ) ) : false );
}

/**
 * Verify the exact external owner immediately before a media side effect.
 *
 * @return true|WP_Error
 */
function yotm_optimizer_checkpoint() {
	$scope = $GLOBALS['yotm_optimizer_scope'] ?? array();
	return empty( $scope['token'] ) ? true : $scope['optimizer']->checkpoint_thumbnail_manager_mutation( $scope['token'] );
}

/**
 * Acquire the existing optimizer mutex before any Thumbnail Manager locks.
 *
 * @param callable $callback Existing owned operation.
 * @return mixed|WP_Error Existing result or an exclusion/ownership failure.
 */
function yotm_with_optimizer_guard( $callback ) {
	if ( yotm_optimizer_mutation_active() || ! class_exists( 'YoOhw_Media_Optimizer' ) ) {
		return call_user_func( $callback );
	}
	if ( ! yotm_optimizer_available() ) {
		return new WP_Error( 'yotm_optimizer_update_required', __( 'Update Media Optimizer before running Thumbnail Manager media operations with both plugins active.', 'thumbnail-manager' ) );
	}
	$optimizer = YoOhw_Media_Optimizer::instance();
	$token     = $optimizer->begin_thumbnail_manager_mutation();
	if ( is_wp_error( $token ) ) {
		return $token;
	}
	$GLOBALS['yotm_optimizer_scope'] = array(
		'optimizer' => $optimizer,
		'token'     => $token,
	);
	try {
		$checkpoint = yotm_optimizer_checkpoint();
		$result     = is_wp_error( $checkpoint ) ? $checkpoint : call_user_func( $callback );
	} finally {
		unset( $GLOBALS['yotm_optimizer_scope'] );
		$released = $optimizer->end_thumbnail_manager_mutation( $token );
	}
	return is_wp_error( $released ) ? $released : $result;
}

/**
 * Read one immutable completed job's generated-file evidence for the optimizer.
 * No browser-supplied path or attachment list is authoritative.
 *
 * @param string $token Owned job token.
 * @param int    $page Bounded done-item page.
 * @param int    $attachment_id Optional exact item lookup while executing a queue.
 * @return array|WP_Error
 */
function yotm_optimizer_handoff( $token, $page = 1, $attachment_id = 0 ) {
	if ( ! current_user_can( 'manage_options' ) ) {
		return new WP_Error( 'yotm_optimizer_forbidden', __( 'No permission.', 'thumbnail-manager' ) );
	}
	$job = yotm_job_get_audit( $token );
	if ( is_wp_error( $job ) ) {
		return $job;
	}
	if ( 'regenerate' !== $job['type'] || 'completed' !== $job['status'] || $job['expires_at'] < gmdate( 'Y-m-d H:i:s' ) || ( 'repair' !== ( $job['payload']['operation'] ?? '' ) && empty( $job['payload']['force_all'] ) ) || ! empty( $job['payload']['recovery_only'] ) || yotm_job_has_terminal_recovery_evidence( $job['id'] ) || yotm_job_has_recovery_journals( $job['id'] ) ) {
		return new WP_Error( 'yotm_optimizer_result_unavailable', __( 'Use a completed, unexpired Repair or Force regeneration result without pending recovery. Run a new check if this result is no longer available.', 'thumbnail-manager' ) );
	}
	$data = yotm_job_get_item_rows_page( $job['id'], max( 1, absint( $page ) ), 100, '', array( 'done' ) );
	if ( is_wp_error( $data ) ) {
		return $data;
	}
	if ( $attachment_id ) {
		$row = yotm_job_get_item_by_key( $job['id'], hash( 'sha256', 'attachment:' . absint( $attachment_id ) ) );
		if ( ! is_array( $row ) || 'done' !== $row['status'] ) {
			return new WP_Error( 'yotm_optimizer_item_unavailable', __( 'The selected successful thumbnail result is unavailable.', 'thumbnail-manager' ) );
		}
		$data['items'] = array( $row );
	}
	$items = array();
	foreach ( $data['items'] as $row ) {
		$id      = absint( $row['payload']['attachment_id'] ?? 0 );
		$journal = $row['payload']['regeneration_journal'] ?? array();
		if ( $journal && ( YOTM_REGENERATE_JOURNAL_VERSION !== ( $journal['version'] ?? 0 ) || 'cleanup_complete' !== ( $journal['phase'] ?? '' ) || absint( $journal['attachment_id'] ?? 0 ) !== $id ) ) {
			return new WP_Error( 'yotm_optimizer_evidence_invalid', __( 'Saved generated-file evidence is incomplete. Run a new check before optimizing these thumbnails.', 'thumbnail-manager' ) );
		}
		$files = array();
		$base  = trailingslashit( wp_normalize_path( wp_get_upload_dir()['basedir'] ) );
		if ( $id && 'cleanup_complete' === ( $journal['phase'] ?? '' ) && absint( $journal['attachment_id'] ?? 0 ) === $id ) {
			foreach ( (array) ( $journal['destinations'] ?? array() ) as $slug => $entry ) {
				$path = wp_normalize_path( (string) ( $entry['destination'] ?? '' ) );
				$hash = (string) ( $entry['promoted_hash'] ?? '' );
				$name = $journal['final_metadata']['sizes'][ $slug ]['file'] ?? '';
				if ( ! is_string( $name ) || '' === $name || wp_basename( $path ) !== $name || 0 !== strpos( $path, $base ) || ! preg_match( '/^[a-f0-9]{64}$/', $hash ) || wp_normalize_path( (string) ( $journal['full'] ?? '' ) ) === $path ) {
					continue;
				}
				$files[] = array(
					'size'   => (string) $slug,
					'path'   => substr( $path, strlen( $base ) ),
					'sha256' => $hash,
				);
			}
		}
		$items[] = array(
			'attachment_id' => $id,
			'title'         => get_the_title( $id ),
			'files'         => $files,
		);
	}//end foreach
	return array(
		'schema'      => 1,
		'job_token'   => $job['token'],
		'source_url'  => add_query_arg(
			array(
				'page'              => 'yo-manage-thumbnails',
				'yotm_result_token' => $job['token'],
			),
			admin_url( 'tools.php' )
		),
		'scope_label' => $job['payload']['scope_label'] ?? __( 'All media', 'thumbnail-manager' ),
		'page'        => $data['page'],
		'pages'       => $data['pages'],
		'total'       => $data['total'],
		'items'       => $items,
	);
}

/**
 * Read a source revision without starting or resuming media work.
 *
 * @param int $attachment_id Attachment, or zero for the site observation scope.
 * @return array|null Null means the optional revision API is unavailable.
 */
function yotm_optimizer_source_revision( $attachment_id = 0 ) {
	if ( ! yotm_optimizer_available() || ! is_callable( array( YoOhw_Media_Optimizer::instance(), 'get_source_revision' ) ) ) {
		return null;
	}
	return YoOhw_Media_Optimizer::instance()->get_source_revision( absint( $attachment_id ) );
}

/**
 * Explain an observation's freshness without changing its recorded outcome.
 *
 * @param array|null $baseline Saved revision, absent for older observations.
 * @param int        $attachment_id Attachment, or zero for site scope.
 * @return string Empty when no known change affects the observation.
 */
function yotm_optimizer_freshness_note( $baseline, $attachment_id = 0 ) {
	$current = yotm_optimizer_source_revision( $attachment_id );
	if ( null === $current ) {
		return is_array( $baseline ) && ! empty( $baseline['token'] ) ? __( 'Media Optimizer source freshness cannot currently be verified. Run a new check when both plugins are available.', 'thumbnail-manager' ) : '';
	}
	if ( ! empty( $current['changing'] ) || ! empty( $baseline['changing'] ) ) {
		return __( 'Media Optimizer source work was in progress when this observation was made or is still unresolved. Finish that work, then run a new check.', 'thumbnail-manager' );
	}
	if ( ! is_array( $baseline ) ) {
		return ! empty( $current['token'] ) ? __( 'This older result has no source revision snapshot. Media Optimizer has attempted source optimization or restore; run a new check to confirm the current state.', 'thumbnail-manager' ) : '';
	}
	return ( $baseline['token'] ?? '' ) !== ( $current['token'] ?? '' )
		? __( 'Media Optimizer has attempted source optimization or restore since this observation. Results may be out of date, including after a partial or no-change attempt. Run a new check; saved outcomes remain unchanged.', 'thumbnail-manager' ) : '';
}

/**
 * Export only reconciled deletions from an owned, completed Prune result.
 * This evidence grants no authority to delete sidecars.
 *
 * @param string $token Owned job token.
 * @param int    $page Bounded deleted-source page.
 * @param string $item_key Optional exact frozen source lookup.
 * @return array|WP_Error
 */
function yotm_optimizer_prune_handoff( $token, $page = 1, $item_key = '' ) {
	if ( ! current_user_can( 'manage_options' ) ) {
		return new WP_Error( 'yotm_optimizer_forbidden', __( 'No permission.', 'thumbnail-manager' ) );
	}
	$job = yotm_job_get_audit( $token );
	if ( is_wp_error( $job ) ) {
		return $job;
	}
	if ( 'prune' !== $job['type'] || 'completed' !== $job['status'] || $job['expires_at'] < gmdate( 'Y-m-d H:i:s' ) || ! empty( $job['payload']['recovery_only'] ) || yotm_job_has_terminal_recovery_evidence( $job['id'] ) || yotm_job_has_recovery_journals( $job['id'] ) ) {
		return new WP_Error( 'yotm_optimizer_prune_unavailable', __( 'Use an available completed Prune result without pending recovery. Sidecar cleanup requires a separate preview and approval in Media Optimizer.', 'thumbnail-manager' ) );
	}
	$data = yotm_job_get_item_rows_page( $job['id'], max( 1, absint( $page ) ), 100, '', array( 'done', 'deleted' ) );
	if ( is_wp_error( $data ) ) {
		return $data;
	}
	if ( '' !== $item_key ) {
		if ( ! is_string( $item_key ) || ! preg_match( '/^[a-f0-9]{64}$/', $item_key ) ) {
			return new WP_Error( 'yotm_optimizer_prune_item', __( 'The deleted source evidence is unavailable.', 'thumbnail-manager' ) );
		}
		$row = yotm_job_get_item_by_key( $job['id'], $item_key );
		if ( ! is_array( $row ) || ! in_array( $row['status'], array( 'done', 'deleted' ), true ) ) {
			return new WP_Error( 'yotm_optimizer_prune_item', __( 'The deleted source evidence is unavailable.', 'thumbnail-manager' ) );
		}
		$data['items'] = array( $row );
	}
	$base  = trailingslashit( wp_normalize_path( wp_get_upload_dir()['basedir'] ) );
	$items = array();
	foreach ( $data['items'] as $row ) {
		$journal = $row['payload']['prune_operation_journal_v1'] ?? array();
		$path    = wp_normalize_path( (string) ( $row['payload']['path'] ?? '' ) );
		if ( 1 !== ( $journal['version'] ?? 0 ) || 'delete_reconciled' !== ( $journal['outcome'] ?? '' ) || ! hash_equals( $path, wp_normalize_path( (string) ( $journal['path'] ?? '' ) ) ) || 0 !== strpos( $path, $base ) || ! preg_match( '/^[a-f0-9]{64}$/', (string) ( $journal['file_hash'] ?? '' ) ) ) {
			// Older/path-only evidence never becomes sidecar deletion authority.
			continue;
		}
		$items[] = array(
			'key'           => $row['item_key'],
			'attachment_id' => absint( $row['payload']['attachment_id'] ?? 0 ),
			'path'          => substr( $path, strlen( $base ) ),
			'sha256'        => $journal['file_hash'],
		);
	}
	return array(
		'schema'      => 1,
		'job_token'   => $job['token'],
		'source_url'  => add_query_arg(
			array(
				'page'              => 'yo-manage-thumbnails',
				'yotm_result_token' => $job['token'],
			),
			admin_url( 'tools.php' )
		),
		'scope_label' => $job['payload']['scope_label'] ?? __( 'Pruned thumbnail files', 'thumbnail-manager' ),
		'page'        => $data['page'],
		'pages'       => $data['pages'],
		'total'       => $data['total'],
		'items'       => $items,
		'limitations' => __( 'Only deleted files with complete recovery evidence are included. Older deleted items without that evidence remain outside this handoff.', 'thumbnail-manager' ),
	);
}

/**
 * Fence an Optimizer-owned cleanup against WordPress reference changes.
 * The caller already holds the Optimizer native mutex; this grants no deletion.
 *
 * @param string[] $paths Exact source and sidecar paths.
 * @param callable $callback Operation receiving a named-lock checkpoint.
 * @return mixed|WP_Error
 */
function yotm_optimizer_with_cleanup_guard( $paths, $callback ) {
	if ( ! current_user_can( 'manage_options' ) || ! yotm_optimizer_available() || ! is_array( $paths ) || count( $paths ) > 2 || empty( $paths ) || ! is_callable( $callback ) ) {
		return new WP_Error( 'yotm_optimizer_cleanup_invalid', __( 'Sidecar cleanup safety checks are unavailable.', 'thumbnail-manager' ) );
	}
	$fence = yotm_media_source_fence_acquire();
	if ( is_wp_error( $fence ) ) {
		return $fence;
	}
	$handles = array();
	try {
		$handles = yotm_media_path_lock_aliases(
			array_map(
				static function ( $path ) {
					return array( 'path' => $path );
				},
				$paths
			)
		);
		if ( is_wp_error( $handles ) ) {
			return $handles;
		}
		$checkpoint = static function () use ( $fence, $handles ) {
			global $wpdb;
			foreach ( array_merge( array( $fence ), $handles ) as $handle ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Connection-owned locks must be verified without cache.
				$owned = $wpdb->get_var( $wpdb->prepare( 'SELECT IS_USED_LOCK(%s) = CONNECTION_ID()', $handle['name'] ) );
				if ( '1' !== (string) $owned || $wpdb->last_error ) {
					return new WP_Error( 'yotm_optimizer_cleanup_lock_lost', __( 'Media reference lock ownership was lost. Cleanup stopped.', 'thumbnail-manager' ) );
				}
			}
			return true;
		};
		$owned      = $checkpoint();
		return is_wp_error( $owned ) ? $owned : call_user_func( $callback, $checkpoint );
	} finally {
		foreach ( is_array( $handles ) ? array_reverse( $handles ) : array() as $handle ) {
			yotm_media_path_lock_release( $handle );
		}
		yotm_media_source_fence_release( $fence );
	}//end try
}
