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
		curl -sSL "$1" -o "$2"
	else
		wget -nv -O "$2" "$1"
	fi
}

if [[ ! -d "$WP_CORE_DIR/wp-admin" ]]; then
	mkdir -p "$WP_CORE_DIR"
	archive="$(mktemp)"
	if [[ "$WP_VERSION" == "latest" ]]; then
		download "https://wordpress.org/latest.tar.gz" "$archive"
	else
		download "https://wordpress.org/wordpress-${WP_VERSION}.tar.gz" "$archive"
	fi
	tar --strip-components=1 -C "$WP_CORE_DIR" -zxf "$archive"
	rm -f "$archive"
fi

if [[ ! -d "$WP_TESTS_DIR/includes" ]]; then
	mkdir -p "$WP_TESTS_DIR"
	if [[ "$WP_VERSION" == "latest" ]]; then
		tests_tag="trunk"
	else
		tests_tag="tags/${WP_VERSION}"
	fi
	svn export --quiet "https://develop.svn.wordpress.org/${tests_tag}/tests/phpunit/includes/" "$WP_TESTS_DIR/includes"
	svn export --quiet "https://develop.svn.wordpress.org/${tests_tag}/tests/phpunit/data/" "$WP_TESTS_DIR/data"
fi

if [[ ! -f "$WP_TESTS_DIR/wp-tests-config.php" ]]; then
	download "https://develop.svn.wordpress.org/trunk/wp-tests-config-sample.php" "$WP_TESTS_DIR/wp-tests-config.php"
	sed -i.bak "s/youremptytestdbnamehere/${DB_NAME}/" "$WP_TESTS_DIR/wp-tests-config.php"
	sed -i.bak "s/yourusernamehere/${DB_USER}/" "$WP_TESTS_DIR/wp-tests-config.php"
	sed -i.bak "s/yourpasswordhere/${DB_PASS}/" "$WP_TESTS_DIR/wp-tests-config.php"
	sed -i.bak "s|localhost|${DB_HOST}|" "$WP_TESTS_DIR/wp-tests-config.php"
	sed -i.bak "s|dirname( __FILE__ ) . '/src/'|'${WP_CORE_DIR}/'|" "$WP_TESTS_DIR/wp-tests-config.php"
	rm -f "$WP_TESTS_DIR/wp-tests-config.php.bak"
fi

mysqladmin --host="$DB_HOST" --user="$DB_USER" --password="$DB_PASS" create "$DB_NAME" >/dev/null 2>&1 || true
