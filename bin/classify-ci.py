#!/usr/bin/env python3
"""Classify Thumbnail Manager CI intensity and affected validation surfaces."""

from __future__ import annotations

import argparse
import json
from pathlib import Path
from typing import Iterable

CI_CONTROL_PLANE_PATHS = {
    ".github/workflows/ci.yml",
    "bin/classify-ci.py",
    "bin/validate-ci-gate.sh",
}


def unique_matrix(items: Iterable[dict[str, str]]) -> dict[str, list[dict[str, str]]]:
    """Return a GitHub Actions include matrix without duplicate WP/PHP pairs."""
    seen: set[tuple[str, str]] = set()
    include: list[dict[str, str]] = []
    for item in items:
        key = (item["wordpress"], item["php"])
        if key in seen:
            continue
        seen.add(key)
        include.append(item)
    return {"include": include}


def classify(
    event_name: str,
    pr_draft: bool,
    changed_files: Iterable[str],
    minimum_wp: str,
    tested_wp: str,
) -> dict[str, object]:
    """Return CI decisions for one workflow event and changed-file set."""
    paths = {path.strip() for path in changed_files if path.strip()}
    full = event_name != "pull_request" or not pr_draft
    control_plane = bool(paths & CI_CONTROL_PLANE_PATHS)

    quality = False
    integration = False
    plugin_check_applicable = False
    javascript = False

    for path in paths:
        php_source = path == "thumbnail-manager.php" or (
            (path.startswith("inc/") or path.startswith("tests/")) and path.endswith(".php")
        )

        if php_source or path in {
            "composer.json",
            "composer.lock",
            "phpunit.xml.dist",
            "phpcs.xml.dist",
        }:
            quality = True

        if php_source or path in {
            "bin/install-wp-tests.sh",
            "composer.json",
            "composer.lock",
            "phpunit.xml.dist",
        }:
            integration = True

        if (
            path == "thumbnail-manager.php"
            or path.startswith(("inc/", "css/", "js/", "languages/", "release/"))
            or path
            in {
                "readme.txt",
                "changelog.txt",
                "license.txt",
                "bin/build-release.sh",
                "bin/validate-release.py",
                "bin/validate-plugin-check.py",
                ".github/workflows/release-prepare.yml",
                ".github/workflows/publish-wordpress-org.yml",
            }
        ):
            plugin_check_applicable = True

        if (
            path.startswith(("js/", "tests/js/"))
            or path
            in {
                "bin/build-release.sh",
                "bin/validate-release.py",
                "release/payload-manifest.txt",
                ".github/workflows/release-prepare.yml",
                ".github/workflows/publish-wordpress-org.yml",
            }
        ):
            javascript = True

    if event_name == "workflow_dispatch" or (full and control_plane):
        quality = True
        integration = True
        plugin_check_applicable = True
        javascript = True

    if full:
        matrix = unique_matrix(
            [
                {"wordpress": minimum_wp, "php": "7.4", "label": "minimum"},
                {
                    "wordpress": minimum_wp,
                    "php": "8.2",
                    "label": "minimum-high-php",
                },
                {
                    "wordpress": tested_wp,
                    "php": "7.4",
                    "label": "tested-min-php",
                },
                {"wordpress": tested_wp, "php": "8.4", "label": "tested-modern"},
            ]
        )
    else:
        matrix = unique_matrix(
            [
                {
                    "wordpress": tested_wp,
                    "php": "8.2",
                    "label": "iteration",
                }
            ]
        )

    return {
        "intensity": "final" if full else "iteration",
        "full": full,
        "control_plane": control_plane,
        "quality": quality,
        "integration": integration,
        "plugin_check_applicable": plugin_check_applicable,
        "plugin_check": full and plugin_check_applicable,
        "javascript": javascript,
        "matrix": matrix,
    }


def output_value(value: object) -> str:
    if isinstance(value, bool):
        return "true" if value else "false"
    if isinstance(value, (dict, list)):
        return json.dumps(value, separators=(",", ":"))
    return str(value)


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument(
        "--event-name",
        required=True,
        choices=("pull_request", "push", "workflow_dispatch"),
    )
    parser.add_argument("--pr-draft", choices=("true", "false"), default="false")
    parser.add_argument("--changed-files", required=True, type=Path)
    parser.add_argument("--minimum-wp", required=True)
    parser.add_argument("--tested-wp", required=True)
    parser.add_argument("--github-output", type=Path)
    args = parser.parse_args()

    result = classify(
        event_name=args.event_name,
        pr_draft=args.pr_draft == "true",
        changed_files=args.changed_files.read_text(encoding="utf-8").splitlines(),
        minimum_wp=args.minimum_wp,
        tested_wp=args.tested_wp,
    )

    if args.github_output:
        with args.github_output.open("a", encoding="utf-8") as output:
            for key, value in result.items():
                output.write(f"{key}={output_value(value)}\n")
    else:
        print(json.dumps(result, sort_keys=True))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
