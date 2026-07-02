<?php

if (!defined('ABSPATH')) {
	exit;
}

/** ===== Sizes + Patterns ===== */
function yotm_get_registered_sizes() {
    if (function_exists('wp_get_registered_image_subsizes')) {
        $sizes = wp_get_registered_image_subsizes();
    } else {
        global $_wp_additional_image_sizes;
        $sizes = [];
        foreach (get_intermediate_image_sizes() as $s) {
            if (isset($_wp_additional_image_sizes[$s])) {
                $sizes[$s] = [
                    'width'=>(int)$_wp_additional_image_sizes[$s]['width'],
                    'height'=>(int)$_wp_additional_image_sizes[$s]['height'],
                    'crop'=>(bool)$_wp_additional_image_sizes[$s]['crop'],
                ];
            } else {
                $sizes[$s] = [
                    'width'=>(int)get_option("{$s}_size_w"),
                    'height'=>(int)get_option("{$s}_size_h"),
                    'crop'=>(bool)get_option("{$s}_crop"),
                ];
            }
        }
    }
    foreach (['thumbnail','medium','medium_large','large','1536x1536','2048x2048'] as $c) {
        if (!isset($sizes[$c])) $sizes[$c] = [
            'width'=>(int)get_option("{$c}_size_w",0),
            'height'=>(int)get_option("{$c}_size_h",0),
            'crop'=>(bool)get_option("{$c}_crop",false),
        ];
    }
    return $sizes;
}

function yotm_patterns_for_size($name,$def,$final_exts_rx,$inner_exts_rx){
    $w = isset($def['width']) ? (int)$def['width'] : 0;
    $h = isset($def['height'])? (int)$def['height']: 0;
    if (preg_match('/^(\d+)x(\d+)$/',$name,$m)){ $w=$w?: (int)$m[1]; $h=$h?: (int)$m[2]; }
    if ($w<=0) return [];
    $after = '(?:@\d+x)?(?:-\d+)?';
    // allow zero or more “inner” segments: .jpg.webp, .bak, .orig, etc.
    $tail  = '(?:\.(?:'.$inner_exts_rx.'))*\.('.$final_exts_rx.')$';
    if ($h>0) return ['/-'.preg_quote($w.'x'.$h,'/').$after.$tail.'/i'];
    return ['/-'.preg_quote((string)$w,'/').'x\d+'.$after.$tail.'/i'];
}

function yotm_build_patterns($to_remove_names,$sizes){
    $final_exts = ['jpg','jpeg','png','gif','webp','avif'];
    // include backup markers so things like .bak.jpg match
    $inner_exts = ['jpg','jpeg','png','gif','avif','bak','backup','orig','original','old','tmp','temp'];

    $final_rx = implode('|', array_map(function($e){ return preg_quote($e,'/'); }, $final_exts));
    $inner_rx = implode('|', array_map(function($e){ return preg_quote($e,'/'); }, $inner_exts));

    $patterns=[];
    foreach ($to_remove_names as $name){
        if (!isset($sizes[$name])) continue;
        foreach (yotm_patterns_for_size($name,$sizes[$name],$final_rx,$inner_rx) as $p){ $patterns[]=$p; }
    }
    return [$patterns, $final_rx, $inner_rx];
}