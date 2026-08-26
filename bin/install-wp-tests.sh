#!/usr/bin/env bash

set -euo pipefail

DB_NAME="${1-wordpress_test}"
DB_USER="${2-root}"
DB_PASS="${3-root}"
DB_HOST="${4-127.0.0.1}"
WP_VERSION="${5-latest}"
WP_CORE_DIR="${WP_CORE_DIR-/tmp/wordpress}"
WP_TESTS_DIR="${WP_TESTS_DIR-/tmp/wordpress-tests-lib}"

download() {
	if command -v curl >/dev/null 2>&1; then
		curl -fsSL "$1" -o "$2"
	else
		wget -nv -O "$2" "$1"
	fi
}

if [[ "$WP_VERSION" == "latest" ]]; then
	develop_ref="trunk"
else
	develop_ref="$WP_VERSION"
fi

if [[ ! -d "$WP_CORE_DIR/wp-admin" || ! -d "$WP_TESTS_DIR/includes" || ! -f "$WP_TESTS_DIR/wp-tests-config.php" ]]; then
	archive="$(mktemp)"
	tmp_dir="$(mktemp -d)"
	develop_archive="https://github.com/WordPress/wordpress-develop/archive/refs/heads/${develop_ref}.tar.gz"

	download "$develop_archive" "$archive"
	tar -C "$tmp_dir" -zxf "$archive"
	develop_root="$(find "$tmp_dir" -mindepth 1 -maxdepth 1 -type d -print -quit)"

	if [[ -z "$develop_root" || ! -d "$develop_root/src/wp-admin" || ! -d "$develop_root/tests/phpunit/includes" || ! -d "$develop_root/tests/phpunit/data" || ! -f "$develop_root/wp-tests-config-sample.php" ]]; then
		echo "Could not locate the WordPress source and PHPUnit library for ${WP_VERSION}." >&2
		rm -rf "$tmp_dir" "$archive"
		exit 1
	fi

	if [[ ! -d "$WP_CORE_DIR/wp-admin" ]]; then
		mkdir -p "$WP_CORE_DIR"
		cp -R "$develop_root/src/." "$WP_CORE_DIR/"
	fi

	if [[ ! -d "$WP_TESTS_DIR/includes" ]]; then
		mkdir -p "$WP_TESTS_DIR"
		cp -R "$develop_root/tests/phpunit/includes" "$WP_TESTS_DIR/includes"
		cp -R "$develop_root/tests/phpunit/data" "$WP_TESTS_DIR/data"
	fi

	if [[ ! -f "$WP_TESTS_DIR/wp-tests-config.php" ]]; then
		cp "$develop_root/wp-tests-config-sample.php" "$WP_TESTS_DIR/wp-tests-config.php"
		sed -i.bak "s/youremptytestdbnamehere/${DB_NAME}/" "$WP_TESTS_DIR/wp-tests-config.php"
		sed -i.bak "s/yourusernamehere/${DB_USER}/" "$WP_TESTS_DIR/wp-tests-config.php"
		sed -i.bak "s/yourpasswordhere/${DB_PASS}/" "$WP_TESTS_DIR/wp-tests-config.php"
		sed -i.bak "s|localhost|${DB_HOST}|" "$WP_TESTS_DIR/wp-tests-config.php"
		sed -i.bak "s|dirname( __FILE__ ) . '/src/'|'${WP_CORE_DIR}/'|" "$WP_TESTS_DIR/wp-tests-config.php"
		rm -f "$WP_TESTS_DIR/wp-tests-config.php.bak"
	fi

	rm -rf "$tmp_dir" "$archive"
fi

mysqladmin --host="$DB_HOST" --user="$DB_USER" --password="$DB_PASS" create "$DB_NAME" >/dev/null 2>&1 || true
