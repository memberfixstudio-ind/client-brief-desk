#!/usr/bin/env bash
set -euo pipefail
# Run with wp on PATH; arguments are forwarded to every WP-CLI process (e.g. --path=...).
plugin_dir="$(cd "$(dirname "$0")/.." && pwd)"
export CBD_TEST_DIR
CBD_TEST_DIR="$(mktemp -d "${TMPDIR:-/tmp}/cbd-concurrency.XXXXXXXX")"
wp_args=("$@")
workers=()

wait_worker() {
  local pid="$1"
  for ((i=0;i<300;i++)); do
    if ! kill -0 "$pid" 2>/dev/null; then wait "$pid"; return; fi
    sleep 0.1
  done
  echo "FAIL: WP worker $pid exceeded 30 seconds" >&2
  kill -KILL "$pid" 2>/dev/null || true
  wait "$pid" 2>/dev/null || true
  return 1
}

cleanup() {
  local status=$?
  trap - EXIT INT TERM HUP
  for pid in "${workers[@]}"; do
    if kill -0 "$pid" 2>/dev/null; then kill -KILL "$pid" 2>/dev/null || true; fi
    wait "$pid" 2>/dev/null || true
  done
  CBD_TEST_MODE=cleanup wp "${wp_args[@]}" eval-file "$plugin_dir/tests/concurrency.php" &
  if wait_worker "$!"; then
    rm -rf -- "$CBD_TEST_DIR"
  else
    echo "FAIL: fixture cleanup failed; recovery manifest retained at $CBD_TEST_DIR" >&2
    status=1
  fi
  exit "$status"
}
trap cleanup EXIT
trap 'exit 130' INT
trap 'exit 143' TERM HUP

CBD_TEST_MODE=setup wp "${wp_args[@]}" eval-file "$plugin_dir/tests/concurrency.php" &
workers+=("$!")
wait_worker "${workers[0]}"
CBD_TEST_MODE=first wp "${wp_args[@]}" eval-file "$plugin_dir/tests/concurrency.php" &
workers+=("$!")
for ((i=0;i<100;i++)); do
  [[ -s "$CBD_TEST_DIR/locked.json" ]] && break
  kill -0 "${workers[1]}" 2>/dev/null || { wait "${workers[1]}"; exit 1; }
  sleep 0.1
done
[[ -s "$CBD_TEST_DIR/locked.json" ]] || { echo 'FAIL: first worker lock barrier timed out' >&2; exit 1; }
CBD_TEST_MODE=second wp "${wp_args[@]}" eval-file "$plugin_dir/tests/concurrency.php" &
workers+=("$!")
wait_worker "${workers[1]}"
wait_worker "${workers[2]}"
CBD_TEST_MODE=verify wp "${wp_args[@]}" eval-file "$plugin_dir/tests/concurrency.php" &
workers+=("$!")
wait_worker "${workers[3]}"
