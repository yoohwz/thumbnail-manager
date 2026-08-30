#!/usr/bin/env bash

set -euo pipefail

require_result() {
	local name="$1"
	local required="$2"
	local result="$3"

	if [[ "$required" == "true" ]]; then
		if [[ "$result" != "success" ]]; then
			echo "$name is required but concluded: $result" >&2
			exit 1
		fi
	elif [[ "$result" != "success" && "$result" != "skipped" ]]; then
		echo "$name unexpectedly concluded: $result" >&2
		exit 1
	fi
}

require_result "Classify change" true "$CLASSIFY"
require_result "Repository contracts" true "$CONTRACTS"
require_result "Coding standards" "$QUALITY_REQUIRED" "$QUALITY"
require_result "JavaScript syntax" "$JAVASCRIPT_REQUIRED" "$JAVASCRIPT"
require_result "WordPress/PHP integration" "$INTEGRATION_REQUIRED" "$PHPUNIT"
require_result "WordPress Plugin Check" "$PLUGIN_CHECK_REQUIRED" "$PLUGIN_CHECK"
