#!/usr/bin/env bash
set -euo pipefail

if (( $# == 0 )); then
  echo "Usage: $0 <wp-command> [wp-global-arguments...]" >&2
  exit 64
fi

script_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
state_file="$(mktemp "${TMPDIR:-/tmp}/yotm-source-lock.XXXXXX")"
holder_log="$(mktemp "${TMPDIR:-/tmp}/yotm-source-lock-holder.XXXXXX")"
holder_pid=""
wp_command=("$@")

cleanup() {
  if [[ -n "$holder_pid" ]] && kill -0 "$holder_pid" 2>/dev/null; then
    kill -KILL "$holder_pid" 2>/dev/null || true
    wait "$holder_pid" 2>/dev/null || true
  fi
  if [[ -s "$state_file" ]]; then
    "${wp_command[@]}" eval-file "$script_dir/source-lock-concurrency-smoke.php" cleanup "$state_file" >/dev/null 2>&1 || true
  fi
  rm -f "$state_file" "$holder_log"
}
trap cleanup EXIT

"${wp_command[@]}" eval-file "$script_dir/source-lock-concurrency-smoke.php" setup "$state_file"
"${wp_command[@]}" eval-file "$script_dir/source-lock-concurrency-smoke.php" hold_baseline "$state_file" >"$holder_log" 2>&1 &
holder_pid=$!

for _ in {1..100}; do
  if grep -q '"baseline_ready":1' "$state_file"; then
    break
  fi
  sleep 0.1
done

grep -q '"baseline_ready":1' "$state_file"
kill -0 "$holder_pid"
"${wp_command[@]}" eval-file "$script_dir/source-lock-concurrency-smoke.php" contend_baseline "$state_file"
wait "$holder_pid"
holder_pid=""
"${wp_command[@]}" eval-file "$script_dir/source-lock-concurrency-smoke.php" promote_source "$state_file"
"${wp_command[@]}" eval-file "$script_dir/source-lock-concurrency-smoke.php" baseline_after_promotion "$state_file"
"${wp_command[@]}" eval-file "$script_dir/source-lock-concurrency-smoke.php" reset_source "$state_file"

"${wp_command[@]}" eval-file "$script_dir/source-lock-concurrency-smoke.php" hold_delete "$state_file" >"$holder_log" 2>&1 &
holder_pid=$!

for _ in {1..100}; do
  if grep -q '"holder_ready":1' "$state_file"; then
    break
  fi
  sleep 0.1
done

grep -q '"holder_ready":1' "$state_file"
kill -0 "$holder_pid"
"${wp_command[@]}" eval-file "$script_dir/source-lock-concurrency-smoke.php" contend "$state_file"
kill -KILL "$holder_pid"
wait "$holder_pid" 2>/dev/null || true
holder_pid=""
"${wp_command[@]}" eval-file "$script_dir/source-lock-concurrency-smoke.php" promote_then_delete "$state_file"

echo "YOTM source-lock concurrency smoke tests passed."
