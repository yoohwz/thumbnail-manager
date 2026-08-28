#!/usr/bin/env bash
set -euo pipefail

if (( $# == 0 )); then
  echo "Usage: $0 <wp-command> [wp-global-arguments...]" >&2
  exit 64
fi

script_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
wp_command=("$@")

run_case() (
  local intent="$1"
  local state_file
  local armer_log
  local armer_pid=""
  state_file="$(mktemp "${TMPDIR:-/tmp}/yotm-recovery-intent.XXXXXX")"
  armer_log="$(mktemp "${TMPDIR:-/tmp}/yotm-recovery-intent-armer.XXXXXX")"

  cleanup_case() {
    if [[ -n "$armer_pid" ]] && kill -0 "$armer_pid" 2>/dev/null; then
      kill -KILL "$armer_pid" 2>/dev/null || true
      wait "$armer_pid" 2>/dev/null || true
    fi
    if [[ -s "$state_file" ]]; then
      "${wp_command[@]}" eval-file "$script_dir/prune-recovery-intent-smoke.php" cleanup "$state_file" >/dev/null 2>&1 || true
    fi
    rm -f "$state_file" "$armer_log"
  }
  trap cleanup_case EXIT

  "${wp_command[@]}" eval-file "$script_dir/prune-recovery-intent-smoke.php" setup "$state_file" "$intent"
  "${wp_command[@]}" eval-file "$script_dir/prune-recovery-intent-smoke.php" arm "$state_file" >"$armer_log" 2>&1 &
  armer_pid=$!

  for _ in {1..100}; do
    if grep -q '"armed_ready":1' "$state_file"; then
      break
    fi
    sleep 0.1
  done

  grep -q '"armed_ready":1' "$state_file"
  kill -0 "$armer_pid"
  kill -KILL "$armer_pid"
  wait "$armer_pid" 2>/dev/null || true
  armer_pid=""
  "${wp_command[@]}" eval-file "$script_dir/prune-recovery-intent-smoke.php" recover "$state_file"
)

run_case expired
run_case cancelled

echo "YOTM cross-process prune recovery-intent smoke tests passed."
