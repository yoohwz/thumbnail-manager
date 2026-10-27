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
