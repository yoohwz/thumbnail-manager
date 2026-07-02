<?php

if (!defined('ABSPATH')) {
	exit;
}

/** ===== Uploads subfolder dropdown ===== */
function yotm_list_upload_subpaths($base_dir){
    $opts = ['' => 'All uploads'];
    if (!is_dir($base_dir)) return $opts;

    $years = [];
    try {
        $it = new DirectoryIterator($base_dir);
        foreach ($it as $fi){
            if (!$fi->isDir() || $fi->isDot()) continue;
            $name = $fi->getFilename();
            if (preg_match('/^\d{4}$/', $name)) $years[] = $name;
        }
    } catch (Throwable $e) {}
    rsort($years);
    foreach ($years as $y){
        $opts[$y] = "$y (year)";
        $year_path = trailingslashit($base_dir) . $y;
        try {
            $it2 = new DirectoryIterator($year_path);
            $months = [];
            foreach ($it2 as $fi2){
                if (!$fi2->isDir() || $fi2->isDot()) continue;
                $m = $fi2->getFilename();
                if (preg_match('/^(0[1-9]|1[0-2])$/', $m)) $months[] = $m;
            }
            sort($months);
            foreach ($months as $m){
                $opts["$y/$m"] = "— $y/$m";
            }
        } catch (Throwable $e) {}
    }
    return $opts;
}