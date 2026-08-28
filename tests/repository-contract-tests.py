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


plugin = read("thumbnail-manager.php")
uninstall_php = read("uninstall.php")
job_storage = read("inc/job-storage.php")
lifecycle_php = read("inc/data-lifecycle.php")
readme = read("readme.txt")
changelog = read("changelog.txt")
agents = read("AGENTS.md")
safety = read("docs/media-safety-contract.md")
lifecycle_doc = read("docs/data-lifecycle.md")
pr_template = read(".github/pull_request_template.md")
ci_workflow = read(".github/workflows/ci.yml")
prepare_workflow = read(".github/workflows/release-prepare.yml")
publish_workflow = read(".github/workflows/publish-wordpress-org.yml")
payload_manifest = read("release/payload-manifest.txt")
plugin_check_baseline = json.loads(read("release/plugin-check-baseline.json"))
wporg_policy = json.loads(read("release/wporg-policy.json"))
wporg_helper = read("bin/wporg-release.py")
release_validator = read("bin/validate-release.py")
phpcs_config = read("phpcs.xml.dist")
admin_php = read("inc/admin-menu.php")
admin_js = read("js/admin.js")
pot = read("languages/thumbnail-manager.pot")

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
if changelog_version != plugin_version:
    fail(
        "latest changelog release must exactly match plugin version: "
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
    ("Uninstall boundary", "uninstall no-media boundary"),
    ("plugin-owned", "regeneration staging ownership classification"),
):
    if needle not in safety:
        fail(f"media safety contract is missing {label}")

for heading in ("## Goal", "## Risk lane", "## Validation", "## Release boundary"):
    if heading not in pr_template:
        fail(f"pull request template is missing {heading}")

expected_payload_entries = {
    "css/**",
    "inc/**",
    "js/**",
    "languages/**",
    "thumbnail-manager.php",
    "uninstall.php",
    "readme.txt",
    "changelog.txt",
    "license.txt",
}
actual_payload_entries = {
    line.strip()
    for line in payload_manifest.splitlines()
    if line.strip() and not line.lstrip().startswith("#")
}
if actual_payload_entries != expected_payload_entries:
    fail(
        "release payload allowlist differs: "
        f"expected={sorted(expected_payload_entries)}, actual={sorted(actual_payload_entries)}"
    )

for required_scope in ("<file>thumbnail-manager.php</file>", "<file>uninstall.php</file>", "<file>inc</file>"):
    if required_scope not in phpcs_config:
        fail(f"PHPCS production coverage is missing {required_scope}")
if re.search(r'<exclude\s+name="WordPress\.DB\.', phpcs_config):
    fail("PHPCS must use file-specific or inline DB exceptions, not global DB-sniff exclusions")

job_schema_version = match(
    r"define\(\s*'YOTM_JOB_DB_VERSION'\s*,\s*'([^']+)'\s*\)", job_storage, "job schema version"
)
lifecycle_schema_version = match(
    r"define\(\s*'YOTM_DATA_LIFECYCLE_SCHEMA_VERSION'\s*,\s*'([^']+)'\s*\)",
    lifecycle_php,
    "standalone lifecycle schema version",
)
if lifecycle_schema_version != job_schema_version:
    fail(
        "standalone uninstall schema version differs from runtime: "
        f"lifecycle={lifecycle_schema_version}, runtime={job_schema_version}"
    )

for needle, label in (
    ("defined( 'WP_UNINSTALL_PLUGIN' )", "WordPress uninstall guard"),
    ("'/inc/data-lifecycle.php'", "standalone lifecycle helper load"),
    ("yotm_data_lifecycle_uninstall();", "uninstall coordinator call"),
):
    if needle not in uninstall_php:
        fail(f"uninstall entrypoint is missing {label}")
for forbidden in ("thumbnail-manager.php", "wp_die(", "wp_delete_file", "wp_delete_attachment", "delete_post_meta"):
    if forbidden in uninstall_php:
        fail(f"standalone uninstall entrypoint must not contain {forbidden}")

for artifact in (
    "yotm_jobs",
    "yotm_job_items",
    "yotm_media_sources",
    "yotm_disabled_sizes",
    "yotm_job_db_version",
    "yotm_media_source_index_dirty",
    "yotm_media_reference_index_state",
    "yotm_job_db_migration_failure",
    "yotm_cleanup_jobs",
    "yotm_uninstall_cleanup_intent",
):
    if artifact not in lifecycle_php:
        fail(f"lifecycle cleanup contract is missing exact artifact {artifact}")
for invariant in (
    "scanning",
    "awaiting_approval",
    "processing",
    "delete_reconciled",
    "cleanup_complete",
    "max_sites",
    "max_items",
    "max_seconds",
    "before_commit_recheck",
):
    if invariant not in lifecycle_php:
        fail(f"lifecycle safety implementation is missing {invariant}")
for forbidden in ("wp_delete_file", "wp_delete_attachment", "delete_post_meta", "unlink(", "rmdir("):
    if forbidden in lifecycle_php:
        fail(f"database lifecycle module must not contain media/filesystem mutation {forbidden}")
if "include_once plugin_dir_path( __FILE__ ) . 'inc/data-lifecycle.php'" not in plugin:
    fail("normal plugin bootstrap is missing lifecycle helper")
if "function yotm_deactivate_job_cleanup( $network_deactivating = false )" not in lifecycle_php:
    fail("deactivation lifecycle must accept the network-deactivation boundary")

for needle, label in (
    ("conditional safe purge", "A2 policy"),
    ("retain-all", "unsafe retention"),
    ("100 sites", "site bound"),
    ("10,000 item rows", "item bound"),
    (".yotm-regenerate-*", "retained plugin-owned recovery files"),
    ("never uninstall cleanup targets", "no-media uninstall boundary"),
):
    if needle not in lifecycle_doc:
        fail(f"data lifecycle contract is missing {label}")

js_i18n_keys = set(re.findall(r"\bt\(\s*'([^']+)'", admin_js))
php_i18n_keys = set(
    re.findall(r"'([A-Za-z][A-Za-z0-9]*)'\s*=>\s*(?:__|esc_html__|esc_attr__)\(", admin_php)
)
missing_i18n_keys = sorted(js_i18n_keys - php_i18n_keys)
if missing_i18n_keys:
    fail(f"JavaScript translation keys are missing from the PHP localization map: {missing_i18n_keys}")

for msgid in ('msgid "%s (year)"', 'msgid "— %s"', 'msgid "Scanning attachment rows… %s checked"'):
    if msgid not in pot:
        fail(f"translation template is missing shipped source string: {msgid}")

if plugin_check_baseline.get("schema_version") != 1:
    fail("Plugin Check baseline schema must be version 1")
fingerprints = plugin_check_baseline.get("fingerprints", [])
if sum(int(item.get("count", 0)) for item in fingerprints) != 101:
    fail("Plugin Check baseline must preserve the reviewed 101-warning capacity")
for item in fingerprints:
    if "line" in item or "column" in item:
        fail("Plugin Check fingerprint identity must exclude line and column")
    for key in ("path", "code", "message", "count"):
        if key not in item:
            fail(f"Plugin Check fingerprint is missing {key}")

if wporg_policy.get("schema_version") != 1:
    fail("WordPress.org policy schema must remain version 1")
if wporg_policy.get("slug") != "thumbnail-manager":
    fail("WordPress.org policy slug must remain thumbnail-manager")
if wporg_policy.get("svn_url") != "https://plugins.svn.wordpress.org/thumbnail-manager":
    fail("WordPress.org policy must use the canonical HTTPS SVN URL")
if wporg_policy.get("assets_mode") != "unchanged":
    fail("TM-WF-0002 must keep WordPress.org assets unchanged")
confirmation = wporg_policy.get("release_confirmation", {})
if confirmation.get("mode") not in {"enabled", "disabled", "unknown"}:
    fail("Release Confirmation mode must be enabled, disabled, or unknown")
if confirmation.get("mode") == "unknown":
    if confirmation.get("observed_at") is not None:
        fail("unknown Release Confirmation state must not claim an observation time")
elif not confirmation.get("observed_at"):
    fail("an enabled/disabled Release Confirmation state requires observation provenance")
if not confirmation.get("source"):
    fail("Release Confirmation policy requires a durable Human-verification source")

for workflow, path in (
    (prepare_workflow, ".github/workflows/release-prepare.yml"),
    (publish_workflow, ".github/workflows/publish-wordpress-org.yml"),
):
    trigger_block = workflow.split("permissions:", 1)[0]
    if "workflow_dispatch:" not in trigger_block:
        fail(f"{path} must be explicitly Human-dispatched")
    for forbidden in ("pull_request:", "push:", "schedule:", "workflow_run:", "repository_dispatch:", "workflow_call:"):
        if forbidden in trigger_block:
            fail(f"{path} must not expose production/release preparation through {forbidden[:-1]}")
    if "refs/heads/main" not in workflow or "GITHUB_WORKFLOW_REF" not in workflow:
        fail(f"{path} must assert protected-main workflow execution")
    if "persist-credentials: false" not in workflow:
        fail(f"{path} checkouts must not persist credentials")

for needle, label in (
    ("ref: ${{ inputs.candidate_sha }}", "candidate SHA as data"),
    ("path: candidate-data", "isolated candidate checkout"),
    ("READ_ONLY_PUBLICATION_PREFLIGHT", "read-only preflight state"),
    ("environment: wordpress-org-production", "Human publication environment gate"),
    ("Final pre-mutation remote recheck and staging", "post-approval final recheck"),
    ("Seal annotated Git tag after final recheck", "post-recheck tag sealing"),
    ("Commit trunk and new SVN tag atomically", "atomic SVN commit"),
    ("WPORG_RELEASE_CONFIRMATION_PENDING", "WordPress.org confirmation pending state"),
    ("WPORG_PROPAGATION_PENDING", "WordPress.org propagation pending state"),
    ("operation == 'verify-only'", "verification-only continuation"),
    ("verify-svn-publication", "SVN ambiguous-outcome verification"),
    ("cancel-in-progress: false", "non-cancellable serialized publisher"),
):
    if needle not in publish_workflow:
        fail(f"publisher workflow is missing {label}")

ordering = [
    publish_workflow.index("Read-only publication preflight"),
    publish_workflow.index("environment: wordpress-org-production"),
    publish_workflow.index("Final pre-mutation remote recheck and staging"),
    publish_workflow.index("Seal annotated Git tag after final recheck"),
    publish_workflow.index("Commit trunk and new SVN tag atomically"),
]
if ordering != sorted(ordering):
    fail("publisher state ordering must be preflight -> Human gate -> final recheck -> tag -> SVN commit")

preflight_job = publish_workflow.split("  preflight:", 1)[1].split("  dry-run:", 1)[0]
if "environment:" in preflight_job or "WPORG_SVN_PASSWORD" in preflight_job:
    fail("read-only publication preflight must run before the production environment and secrets")
if "validate-control-binding" not in preflight_job:
    fail("publisher preflight must bind RC artifacts to the current trusted release-control bundle")
for expected_binding in ("control_sha", "payload_contract_sha256", "release_control"):
    if expected_binding not in preflight_job and expected_binding not in release_validator:
        fail(f"publisher preflight is missing trusted RC binding for {expected_binding}")

production_job = publish_workflow.split("  production-publish:", 1)[1].split("  verify-public:", 1)[0]
for required in (
    "Authenticate committed SVN release before public release checks",
    "verify-svn-publication",
    '--expected-revision "$SVN_REVISION"',
    'verification["assets"]["tree_sha256"]',
    'verification["assets"]["file_count"]',
):
    if required not in production_job:
        fail(f"direct production path is missing authenticated post-SVN evidence: {required}")
post_svn_order = [
    production_job.index("Commit trunk and new SVN tag atomically"),
    production_job.index("Authenticate committed SVN release before public release checks"),
    production_job.index("Resolve WordPress.org confirmation or propagation state"),
    production_job.index("Write durable publication record"),
]
if post_svn_order != sorted(post_svn_order):
    fail("direct production state must be SVN commit -> authenticated verification -> release state -> record")

verify_job = publish_workflow.split("  verify-public:", 1)[1].split("  github-release:", 1)[0]
for forbidden in (
    "svn commit",
    "WPORG_SVN_PASSWORD: ${{ secrets.",
    "--method POST",
    "--method PATCH",
    "--method DELETE",
    "contents: write",
):
    if forbidden in verify_job:
        fail(f"verification-only continuation must not contain {forbidden}")

if "WPORG_SVN_PASSWORD: ${{ secrets.WPORG_SVN_PASSWORD }}" not in publish_workflow:
    fail("SVN credential must be mapped explicitly only for the atomic commit step")
if publish_workflow.count("WPORG_SVN_PASSWORD: ${{ secrets.WPORG_SVN_PASSWORD }}") != 1:
    fail("SVN credential mapping must appear exactly once")
if 'run_svn("commit"' in wporg_helper or "svn commit" in wporg_helper:
    fail("trusted WordPress.org helper must remain structurally non-mutating for remote SVN")

for component in (
    ".github/workflows/release-prepare.yml",
    "bin/build-release.sh",
    "bin/validate-plugin-check.py",
    "bin/validate-release.py",
    "release/payload-manifest.txt",
    "release/plugin-check-baseline.json",
):
    if f'"{component}"' not in release_validator:
        fail(f"release-control bundle is missing {component}")
if "historical_compatibility" in release_validator:
    fail("release metadata validator must not retain a shortened-version compatibility escape hatch")
if "must exactly equal active release version" not in release_validator:
    fail("future release metadata must use exact active version strings")

if "thumbnail-manager.php|uninstall.php|inc/*" not in ci_workflow:
    fail("CI classification must include the shipped uninstall entrypoint")
if "composer test -- --filter test_multisite_" not in ci_workflow:
    fail("CI must execute all multisite storage/lifecycle regressions")

for needle, label in (
    ("bash bin/build-release.sh", "shared deterministic builder"),
    ("bin/validate-plugin-check.py", "reviewed Plugin Check baseline enforcement"),
):
    if needle not in ci_workflow:
        fail(f"CI workflow is missing {label}")

print(
    "Repository contracts passed: "
    f"version={plugin_version}, wp>={plugin_requires_wp}, php>={plugin_requires_php}, tested={tested_up_to}"
)
