#!/usr/bin/env python3
"""Fast repository metadata and workflow-contract checks for Thumbnail Manager."""

from __future__ import annotations

import json
import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def fail(message: str) -> None:
    print(f"ERROR: {message}", file=sys.stderr)
    raise SystemExit(1)


def read(path: str) -> str:
    target = ROOT / path
    if not target.is_file():
        fail(f"required repository file is missing: {path}")
    return target.read_text(encoding="utf-8")


def match(pattern: str, text: str, label: str) -> str:
    found = re.search(pattern, text, re.MULTILINE)
    if not found:
        fail(f"could not read {label}")
    return found.group(1).strip()


def normalized_release(version: str) -> tuple[int, int, int]:
    parts = version.split(".")
    if not all(part.isdigit() for part in parts) or not 2 <= len(parts) <= 3:
        fail(f"unsupported release version format: {version}")
    values = [int(part) for part in parts]
    if len(values) == 2:
        values.append(0)
    return tuple(values)  # type: ignore[return-value]


plugin = read("thumbnail-manager.php")
readme = read("readme.txt")
changelog = read("changelog.txt")
agents = read("AGENTS.md")
safety = read("docs/media-safety-contract.md")
pr_template = read(".github/pull_request_template.md")

plugin_version = match(r"^\s*\*\s*Version:\s*(\S+)", plugin, "plugin header Version")
constant_version = match(r"define\(\s*'YOTM_VERSION'\s*,\s*'([^']+)'\s*\)", plugin, "YOTM_VERSION")
stable_tag = match(r"^Stable tag:\s*(\S+)", readme, "readme Stable tag")

if len({plugin_version, constant_version, stable_tag}) != 1:
    fail(
        "version metadata differs: "
        f"header={plugin_version}, constant={constant_version}, stable={stable_tag}"
    )

plugin_requires_wp = match(r"^\s*\*\s*Requires at least:\s*(\S+)", plugin, "plugin Requires at least")
readme_requires_wp = match(r"^Requires at least:\s*(\S+)", readme, "readme Requires at least")
if plugin_requires_wp != readme_requires_wp:
    fail(
        "WordPress minimum differs: "
        f"plugin={plugin_requires_wp}, readme={readme_requires_wp}"
    )

plugin_requires_php = match(r"^\s*\*\s*Requires PHP:\s*(\S+)", plugin, "plugin Requires PHP")
readme_requires_php = match(r"^Requires PHP:\s*(\S+)", readme, "readme Requires PHP")
if plugin_requires_php != readme_requires_php:
    fail(
        "PHP minimum differs: "
        f"plugin={plugin_requires_php}, readme={readme_requires_php}"
    )

composer = json.loads(read("composer.json"))
platform_php = str(composer.get("config", {}).get("platform", {}).get("php", ""))
if platform_php != plugin_requires_php + ".0":
    fail(
        "Composer PHP platform must model the plugin minimum exactly: "
        f"platform={platform_php or '<missing>'}, plugin={plugin_requires_php}"
    )

# Parsing the lock verifies that the committed file remains valid JSON; Composer
# validation in CI verifies the content hash and dependency consistency.
json.loads(read("composer.lock"))

tested_up_to = match(r"^Tested up to:\s*(\S+)", readme, "readme Tested up to")
if not re.fullmatch(r"\d+\.\d+(?:\.\d+)?", tested_up_to):
    fail(f"invalid Tested up to version: {tested_up_to}")

changelog_version = match(r"^=\s*([0-9]+(?:\.[0-9]+){1,2})\s*\(", changelog, "latest changelog version")
if normalized_release(changelog_version) != normalized_release(plugin_version):
    fail(
        "latest changelog release does not match plugin version: "
        f"changelog={changelog_version}, plugin={plugin_version}"
    )

for needle, label in (
    ("Use only `Fast` and `Controlled`.", "two-lane workflow contract"),
    ("`PLAN_REVIEW_REQUIRED`", "plan-review status"),
    ("`TECHNICAL_REVIEW_REQUIRED`", "technical-review status"),
    ("`READY_FOR_HUMAN_MERGE`", "human merge status"),
    ("docs/media-safety-contract.md", "media safety contract link"),
):
    if needle not in agents:
        fail(f"AGENTS.md is missing {label}")

for needle, label in (
    ("Review-first prune lifecycle", "review-first lifecycle"),
    ("Filesystem containment", "filesystem containment"),
    ("Destructive-operation concurrency", "destructive concurrency"),
):
    if needle not in safety:
        fail(f"media safety contract is missing {label}")

for heading in ("## Goal", "## Risk lane", "## Validation", "## Release boundary"):
    if heading not in pr_template:
        fail(f"pull request template is missing {heading}")

print(
    "Repository contracts passed: "
    f"version={plugin_version}, wp>={plugin_requires_wp}, php>={plugin_requires_php}, tested={tested_up_to}"
)
