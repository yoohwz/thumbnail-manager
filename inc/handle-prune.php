<?php

if (!defined('ABSPATH')) {
	exit;
}

add_action('wp_ajax_yotm_prune_prepare', 'yotm_prune_prepare');
add_action('wp_ajax_yotm_prune_scan_batch', 'yotm_prune_scan_batch');

/** ===== AJAX: Prepare (scan + build list) ===== */
function yotm_prune_prepare(){
    if (!current_user_can('manage_options')) wp_send_json_error(['msg'=>'No permission'],403);
    check_ajax_referer('yotm_prune_nonce','nonce');

    $keep = isset($_POST['keep']) && is_array($_POST['keep']) ? array_map('sanitize_text_field',(array) wp_unslash($_POST['keep'])) : [];
    $mode = sanitize_text_field(wp_unslash($_POST['mode'] ?? 'dry'));
    if ( ! in_array( $mode, [ 'dry', 'delete' ], true ) ) {
        $mode = 'dry';
    }
    $limit_subpath = isset($_POST['limit_subpath']) ? yotm_clean_upload_subpath( sanitize_text_field( wp_unslash( $_POST['limit_subpath'] ) ) ) : '';
    $discover_orphans = !empty($_POST['discover_orphans']);

    $uploads = wp_get_upload_dir();
    $base = trailingslashit( yotm_normalize_filesystem_path( $uploads['basedir'] ) );
    $scan_base = yotm_resolve_upload_scan_base( $base, $limit_subpath );
    if ( is_wp_error( $scan_base ) ) {
        wp_send_json_error( [ 'msg' => $scan_base->get_error_message() ], 400 );
    }

    // Registered sizes
    $sizes = yotm_get_registered_sizes();
    $all = array_keys($sizes);
    $keep = array_values( array_unique( array_intersect( $keep, $all ) ) );
    $to_remove = array_values(array_diff($all,$keep));

    $token = wp_generate_uuid4();
    yotm_store_list($token,[],[
        'total'=>0,
        'processed'=>0,
        'deleted'=>0,
        'bytes'=>0,
        'scan_base'=>$scan_base,
        'base'=>$base,
        'mode'=>$mode,
        'scan_done'=>0,
        'scan_processed'=>0,
        'scan_total_attachments'=>yotm_count_image_attachments(),
        'scan_after_id'=>0,
        'scan_max_id'=>yotm_get_max_image_attachment_id(),
        'sample'=>[],
        'keep'=>$keep,
        'remove'=>$to_remove,
        'sizes'=>$sizes,
        'discover_orphans'=>$discover_orphans ? 1 : 0,
        'orphan_summary'=>yotm_initial_orphan_summary(),
    ]);

    wp_send_json_success([
        'token'=>$token,
        'total'=>0,
        'sample'=>[],
        'keep'=>$keep,
        'remove'=>$to_remove,
        'scan_base'=>yotm_uploads_relative_label($base,$scan_base),
        'orphan_summary'=>yotm_initial_orphan_summary(),
        'scan_required'=>true,
        'scan_done'=>false,
        'scan_processed'=>0,
        'scan_total_attachments'=>yotm_count_image_attachments(),
    ]);
}

function yotm_prune_scan_batch() {
    if (!current_user_can('manage_options')) wp_send_json_error(['msg'=>'No permission'],403);
    check_ajax_referer('yotm_prune_nonce','nonce');

    $token = sanitize_text_field(wp_unslash($_POST['token'] ?? ''));
    $batch = isset($_POST['batch']) ? absint( wp_unslash( $_POST['batch'] ) ) : 100;
    $batch = max( 1, min( 500, $batch ) );

    $list = yotm_fetch_list($token);
    $meta = yotm_fetch_meta($token);

    if ($list===false || $meta===false || !is_array($list) || !is_array($meta)) {
        wp_send_json_error(['msg'=>'State expired. Please run again.'],400);
    }

    if ( ! empty( $meta['scan_done'] ) ) {
        wp_send_json_success( yotm_build_prune_scan_response( $meta, $list, true ) );
    }

    $ids = yotm_get_image_attachment_ids_after(
        [],
        (int) ( $meta['scan_after_id'] ?? 0 ),
        $batch,
        (int) ( $meta['scan_max_id'] ?? 0 )
    );

    if ( empty( $ids ) ) {
        if ( ! empty( $meta['discover_orphans'] ) && empty( $meta['disk_orphan_scanned'] ) ) {
            yotm_collect_unmapped_disk_orphan_summary( $meta, $list );
        }

        $meta['scan_done'] = 1;
        $meta['total']     = count( $list );
        yotm_update_list($token,$list);
        yotm_update_meta($token,$meta);

        wp_send_json_success( yotm_build_prune_scan_response( $meta, $list, true ) );
    }

    $candidates = [];
    yotm_collect_metadata_prune_candidates_for_ids(
        $ids,
        $meta['scan_base'],
        isset( $meta['keep'] ) && is_array( $meta['keep'] ) ? $meta['keep'] : [],
        isset( $meta['remove'] ) && is_array( $meta['remove'] ) ? $meta['remove'] : [],
        isset( $meta['sizes'] ) && is_array( $meta['sizes'] ) ? $meta['sizes'] : [],
        ! empty( $meta['discover_orphans'] ),
        $candidates,
        $meta['orphan_summary']
    );

    foreach ( $ids as $attachment_id ) {
        if ( $attachment_id > (int) ( $meta['scan_after_id'] ?? 0 ) ) {
            $meta['scan_after_id'] = $attachment_id;
        }
    }

    $existing = [];
    foreach ( $list as $item ) {
        if ( is_array( $item ) && ! empty( $item['path'] ) ) {
            $existing[ yotm_normalize_filesystem_path( $item['path'] ) ] = true;
        } elseif ( is_string( $item ) ) {
            $existing[ yotm_normalize_filesystem_path( $item ) ] = true;
        }
    }

    foreach ( $candidates as $candidate ) {
        if ( empty( $candidate['path'] ) ) {
            continue;
        }

        $path = yotm_normalize_filesystem_path( $candidate['path'] );
        if ( isset( $existing[ $path ] ) ) {
            continue;
        }

        $existing[ $path ] = true;
        $list[] = $candidate;

        if ( count( $meta['sample'] ) < 300 ) {
            $meta['sample'][] = $path;
        }
    }

    $meta['scan_processed'] = (int) ( $meta['scan_processed'] ?? 0 ) + count( $ids );
    $meta['total']          = count( $list );

    yotm_update_list($token,$list);
    yotm_update_meta($token,$meta);

    wp_send_json_success( yotm_build_prune_scan_response( $meta, $list, false ) );
}

function yotm_build_prune_scan_response( $meta, $list, $done ) {
    $scan_total = max( 1, (int) ( $meta['scan_total_attachments'] ?? 0 ) );
    $processed  = min( (int) ( $meta['scan_processed'] ?? 0 ), $scan_total );

    return [
        'scan_done'=> (bool) $done,
        'scan_processed'=> $processed,
        'scan_total_attachments'=> (int) ( $meta['scan_total_attachments'] ?? 0 ),
        'scan_percent'=> $done ? 100 : min( 99, ( $processed / $scan_total ) * 100 ),
        'total'=> count($list),
        'sample'=> isset($meta['sample']) && is_array($meta['sample']) ? $meta['sample'] : [],
        'keep'=> isset($meta['keep']) && is_array($meta['keep']) ? $meta['keep'] : [],
        'remove'=> isset($meta['remove']) && is_array($meta['remove']) ? $meta['remove'] : [],
        'scan_base'=> yotm_uploads_relative_label($meta['base'] ?? '', $meta['scan_base'] ?? ''),
        'orphan_summary'=> isset($meta['orphan_summary']) && is_array($meta['orphan_summary']) ? $meta['orphan_summary'] : yotm_initial_orphan_summary(),
    ];
}

function yotm_initial_orphan_summary() {
    return [
        'found'                   => [],
        'delete'                  => [],
        'kept_match'              => [],
        'total_files'             => 0,
        'skipped_original'        => 0,
        'skipped_original_sample' => [],
        'unmapped'                => 0,
        'unmapped_sample'         => [],
        'unmapped_skipped'        => 0,
    ];
}

function yotm_add_prune_candidate( &$candidates, $path, $args = [] ) {
    $path = yotm_normalize_filesystem_path( $path );

    if ( isset( $candidates[ $path ] ) ) {
        if ( ! empty( $args['remove_metadata'] ) ) {
            $candidates[ $path ]['remove_metadata'] = 1;
        }

        if ( empty( $candidates[ $path ]['attachment_id'] ) && ! empty( $args['attachment_id'] ) ) {
            $candidates[ $path ]['attachment_id'] = absint( $args['attachment_id'] );
        }

        if ( empty( $candidates[ $path ]['size'] ) && ! empty( $args['size'] ) ) {
            $candidates[ $path ]['size'] = sanitize_key( $args['size'] );
        }

        return;
    }

    $candidates[ $path ] = [
        'path'            => $path,
        'attachment_id'   => isset( $args['attachment_id'] ) ? absint( $args['attachment_id'] ) : 0,
        'size'            => isset( $args['size'] ) ? sanitize_key( $args['size'] ) : '',
        'source'          => isset( $args['source'] ) ? sanitize_key( $args['source'] ) : 'metadata',
        'remove_metadata' => ! empty( $args['remove_metadata'] ) ? 1 : 0,
    ];
}

function yotm_collect_metadata_prune_candidates( $scan_base, $keep, $to_remove, &$candidates ) {
    $index = [
        'original_paths'      => [],
        'metadata_paths'      => [],
        'kept_metadata_paths' => [],
        'metadata_lookup'     => [],
    ];

    yotm_each_image_attachment_id_paged( [], static function ( $attachment_id ) use ( $scan_base, $keep, $to_remove, &$candidates, &$index ) {
        $attachment_id = absint( $attachment_id );
        $file          = get_attached_file( $attachment_id );

        if ( ! $attachment_id || ! $file ) {
            return;
        }

        $original_path = yotm_normalize_filesystem_path( $file );

        if ( ! yotm_is_path_inside_dir( $original_path, $scan_base ) ) {
            return;
        }

        $index['original_paths'][ $original_path ] = true;

        $metadata = wp_get_attachment_metadata( $attachment_id );
        if ( empty( $metadata['sizes'] ) || ! is_array( $metadata['sizes'] ) ) {
            return;
        }

        $upload_dir = trailingslashit( dirname( $original_path ) );

        foreach ( $metadata['sizes'] as $size_name => $size_data ) {
            if ( ! is_array( $size_data ) ) {
                continue;
            }

            $filename = $size_data['file'] ?? ( $size_data['filename'] ?? '' );
            if ( '' === $filename ) {
                continue;
            }

            $thumb_path = yotm_normalize_filesystem_path( $upload_dir . wp_basename( $filename ) );

            if ( $thumb_path === $original_path || ! yotm_is_path_inside_dir( $thumb_path, $scan_base ) ) {
                continue;
            }

            $index['metadata_paths'][ $thumb_path ] = true;
            $index['metadata_lookup'][ $thumb_path ][] = [
                'attachment_id' => $attachment_id,
                'size'          => (string) $size_name,
            ];

            if ( in_array( $size_name, $keep, true ) ) {
                $index['kept_metadata_paths'][ $thumb_path ] = true;
                continue;
            }

            if ( ! in_array( $size_name, $to_remove, true ) ) {
                continue;
            }

            foreach ( yotm_get_thumbnail_file_variants( $thumb_path ) as $variant_path ) {
                if (
                    $variant_path === $original_path
                    || ! yotm_is_path_inside_dir( $variant_path, $scan_base )
                    || ! is_file( $variant_path )
                ) {
                    continue;
                }

                yotm_add_prune_candidate(
                    $candidates,
                    $variant_path,
                    [
                        'attachment_id'   => $attachment_id,
                        'size'            => (string) $size_name,
                        'source'          => $variant_path === $thumb_path ? 'metadata' : 'metadata_variant',
                        'remove_metadata' => $variant_path === $thumb_path,
                    ]
                );
            }
        }
    } );

    return $index;
}

function yotm_collect_metadata_prune_candidates_for_ids( $ids, $scan_base, $keep, $to_remove, $sizes, $discover_orphans, &$candidates, &$orphan_summary ) {
    $keep_dims = yotm_keep_dims_from_sizes( $keep, $sizes );
    $delete_map = [];

    if ( isset( $orphan_summary['delete'] ) && is_array( $orphan_summary['delete'] ) ) {
        foreach ( $orphan_summary['delete'] as $dim ) {
            if ( is_string( $dim ) && '' !== $dim ) {
                $delete_map[ $dim ] = true;
            }
        }
    }

    foreach ( $ids as $attachment_id ) {
        $attachment_id = absint( $attachment_id );
        $file          = get_attached_file( $attachment_id );

        if ( ! $attachment_id || ! $file ) {
            continue;
        }

        $original_path = yotm_normalize_filesystem_path( $file );

        if ( ! yotm_is_path_inside_dir( $original_path, $scan_base ) ) {
            continue;
        }

        $metadata = wp_get_attachment_metadata( $attachment_id );
        if ( empty( $metadata['sizes'] ) || ! is_array( $metadata['sizes'] ) ) {
            continue;
        }

        $upload_dir = trailingslashit( dirname( $original_path ) );

        foreach ( $metadata['sizes'] as $size_name => $size_data ) {
            if ( ! is_array( $size_data ) ) {
                continue;
            }

            $filename = $size_data['file'] ?? ( $size_data['filename'] ?? '' );
            if ( '' === $filename ) {
                continue;
            }

            $thumb_path = yotm_normalize_filesystem_path( $upload_dir . wp_basename( $filename ) );

            if ( $thumb_path === $original_path || ! yotm_is_path_inside_dir( $thumb_path, $scan_base ) ) {
                continue;
            }

            if ( in_array( $size_name, $keep, true ) ) {
                continue;
            }

            $registered_remove = in_array( $size_name, $to_remove, true );
            $orphan_remove     = false;
            $source            = 'metadata';

            if ( ! $registered_remove && $discover_orphans && ! isset( $sizes[ $size_name ] ) ) {
                $dim = yotm_dimension_from_size_data( $size_data );

                if ( '' !== $dim ) {
                    $orphan_summary['found'][ $dim ] = ( $orphan_summary['found'][ $dim ] ?? 0 ) + 1;

                    if ( yotm_dimension_matches_keep( $dim, $keep_dims ) ) {
                        $orphan_summary['kept_match'][] = $dim;
                    } else {
                        $delete_map[ $dim ] = true;
                        $orphan_remove = true;
                        $source        = 'metadata_orphan';
                    }
                }
            }

            if ( ! $registered_remove && ! $orphan_remove ) {
                continue;
            }

            foreach ( yotm_get_thumbnail_file_variants( $thumb_path ) as $variant_path ) {
                if (
                    $variant_path === $original_path
                    || ! yotm_is_path_inside_dir( $variant_path, $scan_base )
                    || ! is_file( $variant_path )
                ) {
                    continue;
                }

                yotm_add_prune_candidate(
                    $candidates,
                    $variant_path,
                    [
                        'attachment_id'   => $attachment_id,
                        'size'            => (string) $size_name,
                        'source'          => $variant_path === $thumb_path ? $source : $source . '_variant',
                        'remove_metadata' => $variant_path === $thumb_path,
                    ]
                );
            }
        }
    }

    $orphan_summary['delete'] = array_keys( $delete_map );

    if ( isset( $orphan_summary['kept_match'] ) && is_array( $orphan_summary['kept_match'] ) ) {
        $orphan_summary['kept_match'] = array_values( array_unique( $orphan_summary['kept_match'] ) );
    }
}

function yotm_dimension_from_size_data( $size_data ) {
    $width  = isset( $size_data['width'] ) ? absint( $size_data['width'] ) : 0;
    $height = isset( $size_data['height'] ) ? absint( $size_data['height'] ) : 0;

    if ( $width <= 0 || $height <= 0 ) {
        return '';
    }

    return $width . 'x' . $height;
}

function yotm_dimension_matches_keep( $dim, $keep_dims ) {
    if ( in_array( $dim, $keep_dims['exact'], true ) ) {
        return true;
    }

    if ( preg_match( '/^(\d+)x(\d+)$/', $dim, $matches ) ) {
        return in_array( (int) $matches[1], array_map( 'intval', $keep_dims['width_any'] ), true );
    }

    return false;
}

function yotm_collect_unmapped_disk_orphan_summary( &$meta, $list ) {
    $scan_base = $meta['scan_base'] ?? '';
    if ( '' === $scan_base || ! is_dir( $scan_base ) ) {
        $meta['disk_orphan_scanned'] = 1;
        return;
    }

    $queued = [];
    foreach ( $list as $item ) {
        if ( is_array( $item ) && ! empty( $item['path'] ) ) {
            $queued[ yotm_normalize_filesystem_path( $item['path'] ) ] = true;
        }
    }

    $summary = isset( $meta['orphan_summary'] ) && is_array( $meta['orphan_summary'] ) ? $meta['orphan_summary'] : yotm_initial_orphan_summary();
    $disc_rx = '/-(\d+)x(\d+)(?:@\d+x)?(?:-\d+)?'
             . '(?:\.(?:jpg|jpeg|png|gif|avif|bak|backup|orig|original|old|tmp|temp))*'
             . '\.(?:jpg|jpeg|png|gif|webp|avif)$/i';

    try {
        $rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($scan_base, FilesystemIterator::SKIP_DOTS));
        foreach ($rii as $f){
            if (!$f->isFile()) continue;
            $path = yotm_normalize_filesystem_path( $f->getPathname() );
            if (strpos($path, '/imagify-backups/')!==false) continue;

            $summary['total_files']++;

            if ( ! preg_match($disc_rx,$path,$m) ) {
                continue;
            }

            $dim = $m[1].'x'.$m[2];
            $summary['found'][ $dim ] = ( $summary['found'][ $dim ] ?? 0 ) + 1;

            if ( isset( $queued[ $path ] ) ) {
                continue;
            }

            $summary['unmapped']++;
            $summary['unmapped_skipped']++;
            if ( count( $summary['unmapped_sample'] ) < 20 ) {
                $summary['unmapped_sample'][] = $path;
            }
        }
    } catch (Throwable $e) {}

    arsort( $summary['found'] );

    $meta['orphan_summary']      = $summary;
    $meta['disk_orphan_scanned'] = 1;
}

function yotm_get_thumbnail_file_variants( $thumb_path ) {
    $thumb_path = yotm_normalize_filesystem_path( $thumb_path );
    $dir        = dirname( $thumb_path );

    if ( ! is_dir( $dir ) ) {
        return is_file( $thumb_path ) ? [ $thumb_path ] : [];
    }

    $stem = pathinfo( $thumb_path, PATHINFO_FILENAME );
    $rx   = '/^' . preg_quote( $stem, '/' ) . '(?:\.(?:jpg|jpeg|png|gif|webp|avif|bak|backup|orig|original|old|tmp|temp))+$/i';
    $out  = [];

    try {
        $it = new DirectoryIterator( $dir );
        foreach ( $it as $file_info ) {
            if ( ! $file_info->isFile() ) {
                continue;
            }

            if ( preg_match( $rx, $file_info->getFilename() ) ) {
                $out[] = yotm_normalize_filesystem_path( $file_info->getPathname() );
            }
        }
    } catch ( Throwable $e ) {}

    $out = array_values( array_unique( $out ) );
    sort( $out );

    return $out;
}

function yotm_collect_orphan_prune_candidates( $scan_base, $keep, $sizes, $index, &$candidates, &$orphan_summary ) {
    $disc_rx = '/-(\d+)x(\d+)(?:@\d+x)?(?:-\d+)?'
             . '(?:\.(?:jpg|jpeg|png|gif|avif|bak|backup|orig|original|old|tmp|temp))*'
             . '\.(?:jpg|jpeg|png|gif|webp|avif)$/i';

    $keep_dims      = yotm_keep_dims_from_sizes( $keep, $sizes );
    $exact_keep     = array_fill_keys( $keep_dims['exact'], true );
    $width_any_keep = array_fill_keys( array_map( 'intval', $keep_dims['width_any'] ), true );
    $dims           = [];
    $dims_to_delete = [];

    try {
        $rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($scan_base, FilesystemIterator::SKIP_DOTS));
        foreach ($rii as $f){
            if (!$f->isFile()) continue;
            $path = yotm_normalize_filesystem_path( $f->getPathname() );
            if (strpos($path, '/imagify-backups/')!==false) continue;

            $orphan_summary['total_files']++;

            if ( ! preg_match($disc_rx,$path,$m) ) {
                continue;
            }

            $dim = $m[1].'x'.$m[2];
            $dims[$dim] = ($dims[$dim] ?? 0) + 1;

            if ( isset( $index['original_paths'][ $path ] ) ) {
                $orphan_summary['skipped_original']++;
                if ( count( $orphan_summary['skipped_original_sample'] ) < 20 ) {
                    $orphan_summary['skipped_original_sample'][] = $path;
                }
                continue;
            }

            if ( isset($exact_keep[$dim]) ) {
                $orphan_summary['kept_match'][] = $dim;
                continue;
            }

            if (preg_match('/^(\d+)x(\d+)$/',$dim,$mm)){
                $w = (int)$mm[1];
                if (!empty($width_any_keep[$w])) {
                    $orphan_summary['kept_match'][] = $dim;
                    continue;
                }
            }

            if ( isset( $index['metadata_lookup'][ $path ] ) ) {
                $dims_to_delete[$dim] = true;
                foreach ( $index['metadata_lookup'][ $path ] as $meta_ref ) {
                    yotm_add_prune_candidate(
                        $candidates,
                        $path,
                        [
                            'attachment_id'   => $meta_ref['attachment_id'],
                            'size'            => $meta_ref['size'],
                            'source'          => 'metadata_orphan',
                            'remove_metadata' => 1,
                        ]
                    );
                }
                continue;
            }

            $orphan_summary['unmapped']++;
            $orphan_summary['unmapped_skipped'] = (int) ( $orphan_summary['unmapped_skipped'] ?? 0 ) + 1;
            if ( count( $orphan_summary['unmapped_sample'] ) < 20 ) {
                $orphan_summary['unmapped_sample'][] = $path;
            }
        }
    } catch (Throwable $e) {}

    arsort( $dims );

    $orphan_summary['found']      = $dims;
    $orphan_summary['delete']     = array_keys( $dims_to_delete );
    $orphan_summary['kept_match'] = array_values( array_unique( $orphan_summary['kept_match'] ) );
}
