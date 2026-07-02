<?php

if (!defined('ABSPATH')) {
	exit;
}

add_action('wp_ajax_yotm_prune_delete_batch', 'yotm_prune_delete_batch');

/** ===== AJAX: Delete in batches ===== */
function yotm_prune_delete_batch(){
    if (!current_user_can('manage_options')) wp_send_json_error(['msg'=>'No permission'],403);
    check_ajax_referer('yotm_prune_nonce','nonce');

    $token = sanitize_text_field(wp_unslash($_POST['token'] ?? ''));
	$batch = isset($_POST['batch']) ? absint( wp_unslash( $_POST['batch'] ) ) : 200;
	if ( $batch <= 0 ) { $batch = 200; }           // fallback if bad input
	$batch = max( 1, min( 1000, $batch ) ); 

	    $list = yotm_fetch_list($token);
	    $meta = yotm_fetch_meta($token);
	    if ($list===false || $meta===false) wp_send_json_error(['msg'=>'State expired. Please run again.'],400);
	    $delete_allowed = yotm_prune_validate_delete_meta( $meta );
	    if ( is_wp_error( $delete_allowed ) ) {
	        wp_send_json_error( [ 'msg' => $delete_allowed->get_error_message() ], 400 );
	    }

    $total    = (int)($meta['total'] ?? count($list));
    $processed0 = (int)($meta['processed'] ?? 0);
    $deleted0 = (int)($meta['deleted'] ?? 0);
    $bytes0   = (int)($meta['bytes'] ?? 0);
    $base     = isset( $meta['base'] ) ? $meta['base'] : wp_get_upload_dir()['basedir'];

    $processed_now = 0;
    $deleted_now   = 0;
    $freed    = 0;

    $chunk = array_splice($list, 0, $batch);
	foreach ( $chunk as $path ) {
		$result = yotm_delete_prune_item( $path, $base );
        $processed_now++;

		if ( ! empty( $result['deleted'] ) ) {
			$deleted_now++;
			$freed += (int) $result['bytes'];
		}
	}

	    $processed = $processed0 + $processed_now;
    $deleted = $deleted0 + $deleted_now;
    $bytes   = $bytes0 + $freed;
    $remaining = count($list);
    $percent = $total>0 ? ($processed/$total*100) : 100;

    yotm_update_list($token,$list);
    set_transient("yotm_prune_meta_$token", array_merge($meta,[
        'processed'=>$processed,
        'deleted'=>$deleted,
        'bytes'=>$bytes,
    ]), HOUR_IN_SECONDS);

    $resp = [
        'processed'=>$processed,
        'deleted'=>$deleted,
        'deleted_count'=>$deleted_now,
        'bytes'=>$bytes,
        'bytes_human'=>size_format($bytes),
        'remaining'=>$remaining,
        'percent'=>$percent,
        'done'=> false,
    ];

    if ($remaining===0){
        yotm_delete_all_state($token);
        $resp['done'] = true;
    }

	    wp_send_json_success($resp);
	}

function yotm_prune_validate_delete_meta( $meta ) {
    if ( ! is_array( $meta ) || ( $meta['mode'] ?? '' ) !== 'delete' ) {
        return new WP_Error( 'yotm_not_delete_mode', __( 'This token was not prepared for deletion. Run again with Delete selected.', 'thumbnail-manager' ) );
    }

    if ( empty( $meta['scan_done'] ) ) {
        return new WP_Error( 'yotm_scan_not_done', __( 'Scan is still running. Please wait for the scan to finish before deleting.', 'thumbnail-manager' ) );
    }

    return true;
}

/**
 * Delete a file via WP API and return bytes freed.
 */
function yotm_delete_file_and_count( $path ) {
    $uploads = wp_get_upload_dir();
    $base    = $uploads['basedir'] ?? '';

    if ( '' === $base || ! yotm_is_path_inside_dir( $path, $base ) ) {
        return 0;
    }

    $result = yotm_delete_file_with_result( $path );
    return ! empty( $result['deleted'] ) ? (int) $result['bytes'] : 0;
}

function yotm_delete_prune_item( $item, $uploads_base ) {
    $path = is_array( $item ) ? ( $item['path'] ?? '' ) : $item;
    $path = yotm_normalize_filesystem_path( $path );

    if ( '' === $path || ! yotm_is_path_inside_dir( $path, $uploads_base ) ) {
        return [ 'deleted' => false, 'bytes' => 0 ];
    }

    $result = yotm_delete_file_with_result( $path );

    if (
        ! empty( $result['deleted'] )
        && is_array( $item )
        && ! empty( $item['remove_metadata'] )
        && ! empty( $item['attachment_id'] )
        && ! empty( $item['size'] )
    ) {
        yotm_remove_attachment_size_metadata(
            absint( $item['attachment_id'] ),
            sanitize_key( $item['size'] ),
            wp_basename( $path )
        );
    }

    return $result;
}

function yotm_delete_file_with_result( $path ) {
    $path = yotm_normalize_filesystem_path( $path );

    if ( ! is_file( $path ) || ! is_readable( $path ) ) {
        return [ 'deleted' => false, 'bytes' => 0 ];
    }

    $bytes = @filesize( $path ) ?: 0;
    wp_delete_file( $path );

    return [
        'deleted' => ! file_exists( $path ),
        'bytes'   => file_exists( $path ) ? 0 : $bytes,
    ];
}

function yotm_remove_attachment_size_metadata( $attachment_id, $size_name, $filename ) {
    $metadata = wp_get_attachment_metadata( $attachment_id );

    if ( empty( $metadata['sizes'] ) || ! is_array( $metadata['sizes'] ) ) {
        return false;
    }

    $changed = false;

    foreach ( $metadata['sizes'] as $key => $size_data ) {
        if ( ! is_array( $size_data ) ) {
            continue;
        }

        $size_file = $size_data['file'] ?? ( $size_data['filename'] ?? '' );

        if ( $key === $size_name || ( '' !== $size_file && wp_basename( $size_file ) === $filename ) ) {
            unset( $metadata['sizes'][ $key ] );
            $changed = true;
        }
    }

    if ( ! $changed ) {
        return false;
    }

    return false !== wp_update_attachment_metadata( $attachment_id, $metadata );
}
