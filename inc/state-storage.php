<?php

if (!defined('ABSPATH')) {
	exit;
}

/** ===== Prune state storage ===== */
function yotm_store_list($token, $list, $meta) {
	set_transient("yotm_prune_list_{$token}", $list, HOUR_IN_SECONDS);
	set_transient("yotm_prune_meta_{$token}", $meta, HOUR_IN_SECONDS);
}

function yotm_fetch_list($token) {
	return get_transient("yotm_prune_list_{$token}");
}

function yotm_fetch_meta($token) {
	return get_transient("yotm_prune_meta_{$token}");
}

function yotm_update_list($token, $list) {
	set_transient("yotm_prune_list_{$token}", $list, HOUR_IN_SECONDS);
}

function yotm_update_meta($token, $meta) {
	set_transient("yotm_prune_meta_{$token}", $meta, HOUR_IN_SECONDS);
}

function yotm_delete_all_state($token) {
	delete_transient("yotm_prune_list_{$token}");
	delete_transient("yotm_prune_meta_{$token}");
}

/** ===== Regenerate state storage ===== */
function yotm_store_regen_list($token, $list, $meta) {
	set_transient("yotm_regen_list_{$token}", $list, HOUR_IN_SECONDS);
	set_transient("yotm_regen_meta_{$token}", $meta, HOUR_IN_SECONDS);
}

function yotm_fetch_regen_list($token) {
	return get_transient("yotm_regen_list_{$token}");
}

function yotm_fetch_regen_meta($token) {
	return get_transient("yotm_regen_meta_{$token}");
}

function yotm_update_regen_list($token, $list) {
	set_transient("yotm_regen_list_{$token}", $list, HOUR_IN_SECONDS);
}

function yotm_update_regen_meta($token, $meta) {
	set_transient("yotm_regen_meta_{$token}", $meta, HOUR_IN_SECONDS);
}

function yotm_delete_regen_state($token) {
	delete_transient("yotm_regen_list_{$token}");
	delete_transient("yotm_regen_meta_{$token}");
}
