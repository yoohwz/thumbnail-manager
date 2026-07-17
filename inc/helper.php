<?php

if (!defined('ABSPATH')) {
	exit;
}

/** ===== Discovery helpers ===== */
function yotm_discover_dims_in_folder($scan_base, $limit_preview=400){
    // Returns ['dims'=>['300x300'=>count,...], 'sample'=>['300x300'=>['path1','path2'...]], 'total_files'=>N]
    $dims = []; $sample=[]; $total=0;
    $rx = '/-(\d+)x(\d+)(?:@\d+x)?(?:-\d+)?(?:\.(?:jpg|jpeg|png|gif|avif|bak|backup|orig|original|old|tmp|temp))*\.(?:jpg|jpeg|png|gif|webp|avif)$/i';
    try {
        $rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($scan_base, FilesystemIterator::SKIP_DOTS));
        foreach ($rii as $f){
            if (!$f->isFile()) continue;
            $path = $f->getPathname();
            if (strpos($path, DIRECTORY_SEPARATOR.'imagify-backups'.DIRECTORY_SEPARATOR)!==false) continue;
            $total++;
            if (preg_match($rx,$path,$m)){
                $dim = $m[1].'x'.$m[2];
                if (!isset($dims[$dim])) $dims[$dim]=0;
                $dims[$dim]++;
                if (!isset($sample[$dim])) $sample[$dim]=[];
                if (count($sample[$dim]) < $limit_preview) $sample[$dim][] = $path;
            }
        }
    } catch (Throwable $e) {}
    arsort($dims); // most common first
    return ['dims'=>$dims, 'sample'=>$sample, 'total_files'=>$total];
}

function yotm_keep_dims_from_sizes($keep_names, $sizes){
    // Returns ['exact'=>['300x300','1200x675'], 'width_any'=>[768, ...]]
    $exact=[]; $width_any=[];
    foreach ($keep_names as $name){
        if (!isset($sizes[$name])) continue;
        $w=(int)($sizes[$name]['width']??0);
        $h=(int)($sizes[$name]['height']??0);
        if (preg_match('/^(\d+)x(\d+)$/',$name,$m)){ $w=$w?: (int)$m[1]; $h=$h?: (int)$m[2]; }
        if ($w<=0) continue;
        if ($h>0) $exact[] = "{$w}x{$h}"; else $width_any[]=$w;
    }
    return ['exact'=>$exact, 'width_any'=>$width_any];
}

function yotm_patterns_for_dims($dims_to_delete, $final_rx, $inner_rx){
    $after = '(?:@\d+x)?(?:-\d+)?';
    $tail  = '(?:\.(?:'.$inner_rx.'))*\.('.$final_rx.')$';
    $patterns=[];
    foreach ($dims_to_delete as $dim){
        $patterns[]='/-'.preg_quote($dim,'/').$after.$tail.'/i';
    }
    return $patterns;
}

function yotm_normalize_filesystem_path( $path ) {
    $path = (string) $path;
    $real = realpath( $path );

    if ( false !== $real ) {
        return untrailingslashit( wp_normalize_path( $real ) );
    }

    return untrailingslashit( wp_normalize_path( $path ) );
}

function yotm_is_path_inside_dir( $path, $dir ) {
    $path = yotm_normalize_filesystem_path( $path );
    $dir  = yotm_normalize_filesystem_path( $dir );

    return 0 === strpos( $path, trailingslashit( $dir ) );
}

function yotm_clean_upload_subpath( $subpath ) {
    $subpath = trim( str_replace( '\\', '/', (string) $subpath ), '/' );
    $parts   = [];

    foreach ( explode( '/', $subpath ) as $part ) {
        $part = sanitize_file_name( $part );

        if ( '' === $part || '.' === $part || '..' === $part ) {
            continue;
        }

        $parts[] = $part;
    }

    return implode( '/', $parts );
}

function yotm_resolve_upload_scan_base( $base, $subpath ) {
    $base_real = realpath( $base );

    if ( false === $base_real || ! is_dir( $base_real ) ) {
        return new WP_Error( 'yotm_uploads_missing', __( 'Uploads folder not found.', 'thumbnail-manager' ) );
    }

    $base_real = yotm_normalize_filesystem_path( $base_real );
    $subpath   = yotm_clean_upload_subpath( $subpath );
    $target    = '' === $subpath ? $base_real : trailingslashit( $base_real ) . $subpath;
    $scan_real = realpath( $target );

    if ( false === $scan_real || ! is_dir( $scan_real ) ) {
        return new WP_Error( 'yotm_subfolder_missing', __( 'Subfolder not found.', 'thumbnail-manager' ) );
    }

    $scan_real = yotm_normalize_filesystem_path( $scan_real );

    if ( $scan_real !== $base_real && ! yotm_is_path_inside_dir( $scan_real, $base_real ) ) {
        return new WP_Error( 'yotm_subfolder_outside_uploads', __( 'Subfolder must be inside uploads.', 'thumbnail-manager' ) );
    }

    return trailingslashit( $scan_real );
}

/**
 * Normalize selected uploads subpaths and remove descendants already covered
 * by a selected parent directory.
 *
 * An empty result means the entire uploads directory.
 *
 * @param string|string[] $subpaths Selected relative uploads paths.
 * @return string[]
 */
function yotm_normalize_upload_subpaths( $subpaths ) {
	$subpaths = is_array( $subpaths ) ? $subpaths : array( $subpaths );
	$cleaned  = array();

	foreach ( $subpaths as $subpath ) {
		$subpath = yotm_clean_upload_subpath( $subpath );

		if ( '' === $subpath ) {
			return array();
		}

		$cleaned[ $subpath ] = $subpath;
	}

	$cleaned = array_values( $cleaned );
	usort(
		$cleaned,
		static function ( $left, $right ) {
			$left_depth  = substr_count( $left, '/' );
			$right_depth = substr_count( $right, '/' );

			if ( $left_depth === $right_depth ) {
				return strcmp( $left, $right );
			}

			return $left_depth <=> $right_depth;
		}
	);

	$normalized = array();
	foreach ( $cleaned as $candidate ) {
		$covered = false;
		foreach ( $normalized as $parent ) {
			if ( 0 === strpos( $candidate, trailingslashit( $parent ) ) ) {
				$covered = true;
				break;
			}
		}

		if ( ! $covered ) {
			$normalized[] = $candidate;
		}
	}

	return $normalized;
}

/**
 * Resolve multiple user-selected uploads subpaths to disjoint real paths.
 *
 * @param string          $base Uploads base directory.
 * @param string|string[] $subpaths Relative uploads paths; empty means all uploads.
 * @return string[]|WP_Error
 */
function yotm_resolve_upload_scan_bases( $base, $subpaths ) {
	$subpaths = yotm_normalize_upload_subpaths( $subpaths );

	if ( empty( $subpaths ) ) {
		$resolved = yotm_resolve_upload_scan_base( $base, '' );

		return is_wp_error( $resolved ) ? $resolved : array( $resolved );
	}

	$resolved = array();
	foreach ( $subpaths as $subpath ) {
		$scan_base = yotm_resolve_upload_scan_base( $base, $subpath );
		if ( is_wp_error( $scan_base ) ) {
			return new WP_Error(
				$scan_base->get_error_code(),
				sprintf(
					/* translators: 1: uploads subfolder, 2: validation error. */
					__( 'Could not use uploads/%1$s: %2$s', 'thumbnail-manager' ),
					$subpath,
					$scan_base->get_error_message()
				)
			);
		}

		$resolved[] = $scan_base;
	}

	return $resolved;
}

/**
 * Return whether a path is inside any selected scan directory.
 *
 * @param string          $path Path to test.
 * @param string|string[] $scan_bases Selected scan directories.
 * @return bool
 */
function yotm_is_path_inside_any_dir( $path, $scan_bases ) {
	foreach ( (array) $scan_bases as $scan_base ) {
		if ( yotm_is_path_inside_dir( $path, $scan_base ) ) {
			return true;
		}
	}

	return false;
}

/**
 * Find the selected scan root that contains a path.
 *
 * @param string          $path Path to test.
 * @param string|string[] $scan_bases Selected scan directories.
 * @return string
 */
function yotm_find_scan_root_for_path( $path, $scan_bases ) {
	$path = yotm_normalize_filesystem_path( $path );

	foreach ( (array) $scan_bases as $scan_base ) {
		$scan_base = trailingslashit( yotm_normalize_filesystem_path( $scan_base ) );
		if ( untrailingslashit( $scan_base ) === $path || yotm_is_path_inside_dir( $path, $scan_base ) ) {
			return $scan_base;
		}
	}

	return '';
}

/**
 * Build one indexed attachment-meta condition for all selected subpaths.
 *
 * @param string[] $subpaths Normalized uploads subpaths.
 * @return array
 */
function yotm_attachment_query_args_for_upload_subpaths( $subpaths ) {
	$subpaths = yotm_normalize_upload_subpaths( $subpaths );

	if ( empty( $subpaths ) ) {
		return array();
	}

	$patterns = array_map(
		static function ( $subpath ) {
			return preg_quote( $subpath );
		},
		$subpaths
	);

	return array(
		'meta_query' => array(
			array(
				'key'     => '_wp_attached_file',
				'value'   => '^(' . implode( '|', $patterns ) . ')/',
				'compare' => 'REGEXP',
			),
		),
	);
}

/**
 * Build a compact, readable label for one or more selected uploads roots.
 *
 * @param string   $base Uploads base directory.
 * @param string[] $paths Resolved scan roots.
 * @return string
 */
function yotm_uploads_scope_label( $base, $paths ) {
	$labels = array_map(
		static function ( $path ) use ( $base ) {
			return yotm_uploads_relative_label( $base, $path );
		},
		(array) $paths
	);

	if ( count( $labels ) <= 3 ) {
		return implode( ', ', $labels );
	}

	return sprintf(
		/* translators: 1: first selected uploads folders, 2: number of additional folders. */
		__( '%1$s and %2$d more', 'thumbnail-manager' ),
		implode( ', ', array_slice( $labels, 0, 3 ) ),
		count( $labels ) - 3
	);
}

function yotm_uploads_relative_label( $base, $path ) {
    $base = yotm_normalize_filesystem_path( $base );
    $path = yotm_normalize_filesystem_path( $path );

    if ( $path === $base ) {
        return 'uploads/';
    }

    if ( yotm_is_path_inside_dir( $path, $base ) ) {
        return 'uploads/' . ltrim( substr( $path, strlen( $base ) ), '/' );
    }

    return 'uploads/';
}

function yotm_base_image_attachment_query_args( $overrides = [] ) {
    return array_merge(
        [
            'post_type'      => 'attachment',
            'post_status'    => 'inherit',
            'post_mime_type' => 'image',
            'fields'         => 'ids',
            'orderby'        => 'ID',
            'order'          => 'ASC',
        ],
        $overrides
    );
}

function yotm_each_image_attachment_id_paged( $args = [], $callback = null, $page_size = 500 ) {
    $paged     = 1;
    $page_size = max( 1, min( 1000, absint( $page_size ) ) );

    if ( ! is_callable( $callback ) ) {
        return;
    }

    do {
        $query_args = yotm_base_image_attachment_query_args(
            array_merge(
                $args,
                [
                    'posts_per_page' => $page_size,
                    'paged'          => $paged,
                    'no_found_rows'  => true,
                ]
            )
        );

        $page_ids = get_posts( $query_args );

        if ( ! is_array( $page_ids ) || empty( $page_ids ) ) {
            break;
        }

        foreach ( $page_ids as $attachment_id ) {
            $attachment_id = absint( $attachment_id );

            if ( ! $attachment_id ) {
                continue;
            }

            call_user_func( $callback, $attachment_id );
        }

        $count = count( $page_ids );
        $paged++;
    } while ( $count === $page_size );
}

function yotm_get_image_attachment_ids_paged( $args = [], $callback = null, $page_size = 500 ) {
    $ids = [];

    yotm_each_image_attachment_id_paged(
        $args,
        static function ( $attachment_id ) use ( &$ids, $callback ) {
            if ( is_callable( $callback ) && ! call_user_func( $callback, $attachment_id ) ) {
                return;
            }

            $ids[] = $attachment_id;
        },
        $page_size
    );

    return $ids;
}

function yotm_count_image_attachments( $args = [] ) {
    $query = new WP_Query(
        yotm_base_image_attachment_query_args(
            array_merge(
                $args,
                [
                    'posts_per_page' => 1,
                    'paged'          => 1,
                    'no_found_rows'  => false,
                ]
            )
        )
    );

    return (int) $query->found_posts;
}

function yotm_get_max_image_attachment_id( $args = [] ) {
    $query = new WP_Query(
        yotm_base_image_attachment_query_args(
            array_merge(
                $args,
                [
                    'posts_per_page' => 1,
                    'paged'          => 1,
                    'orderby'        => 'ID',
                    'order'          => 'DESC',
                    'no_found_rows'  => true,
                ]
            )
        )
    );

    if ( empty( $query->posts ) || ! is_array( $query->posts ) ) {
        return 0;
    }

    return absint( $query->posts[0] );
}

function yotm_get_image_attachment_ids_after( $args = [], $after_id = 0, $limit = 20, $max_id = 0 ) {
    global $wpdb;

    $after_id = absint( $after_id );
    $limit    = max( 1, min( 500, absint( $limit ) ) );
    $max_id   = absint( $max_id );

    $where_filter = static function ( $where ) use ( $wpdb, $after_id, $max_id ) {
        if ( $after_id > 0 ) {
            $where .= $wpdb->prepare( " AND {$wpdb->posts}.ID > %d", $after_id );
        }

        if ( $max_id > 0 ) {
            $where .= $wpdb->prepare( " AND {$wpdb->posts}.ID <= %d", $max_id );
        }

        return $where;
    };

    add_filter( 'posts_where', $where_filter );

    try {
        $query = new WP_Query(
            yotm_base_image_attachment_query_args(
                array_merge(
                    $args,
                    [
                        'posts_per_page' => $limit,
                        'paged'          => 1,
                        'no_found_rows'  => true,
                    ]
                )
            )
        );

        $ids = is_array( $query->posts ) ? array_map( 'absint', $query->posts ) : [];
    } finally {
        remove_filter( 'posts_where', $where_filter );
    }

    return array_values( array_filter( $ids ) );
}
