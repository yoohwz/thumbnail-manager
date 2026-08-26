#!/usr/bin/env python3
"""Trusted WordPress.org SVN staging and verification helpers.

This module deliberately contains no SVN commit, Git tag, or GitHub Release
creation command. Those mutations remain visible and gated in the protected
main-branch publisher workflow.
"""

from __future__ import annotations

import argparse
import hashlib
import importlib.util
import json
import os
import re
import shutil
import stat
import subprocess
import sys
import zipfile
import xml.etree.ElementTree as ElementTree
from pathlib import Path, PurePosixPath
from typing import Any, Iterable

CONTROL_ROOT = Path(__file__).resolve().parents[1]
VALIDATOR_PATH = CONTROL_ROOT / "bin" / "validate-release.py"


class WporgReleaseError(RuntimeError):
    """A fail-closed WordPress.org release contract violation."""


def fail(message: str) -> None:
    raise WporgReleaseError(message)


def run_svn(*arguments: str, cwd: Path | None = None, check: bool = True) -> subprocess.CompletedProcess[str]:
    result = subprocess.run(
        ["svn", *arguments],
        cwd=str(cwd) if cwd else None,
        check=False,
        text=True,
        stdout=subprocess.PIPE,
        stderr=subprocess.PIPE,
    )
    if check and result.returncode != 0:
        fail(f"svn {' '.join(arguments)} failed: {result.stderr.strip()}")
    return result


def load_validator() -> Any:
    specification = importlib.util.spec_from_file_location("tm_release_validator", VALIDATOR_PATH)
    if specification is None or specification.loader is None:
        fail("could not load trusted release validator")
    module = importlib.util.module_from_spec(specification)
    specification.loader.exec_module(module)
    return module


def load_policy(path: Path) -> dict[str, Any]:
    try:
        policy = json.loads(path.read_text(encoding="utf-8"))
    except (OSError, json.JSONDecodeError) as error:
        fail(f"could not read WordPress.org policy: {error}")
    confirmation = policy.get("release_confirmation", {})
    if policy.get("schema_version") != 1:
        fail("unsupported WordPress.org policy schema")
    if policy.get("slug") != "thumbnail-manager":
        fail("WordPress.org policy slug must be thumbnail-manager")
    if policy.get("svn_url") != "https://plugins.svn.wordpress.org/thumbnail-manager" and not str(
        policy.get("svn_url", "")
    ).startswith("file://"):
        fail("WordPress.org policy must use the canonical HTTPS SVN URL")
    if policy.get("assets_mode") != "unchanged":
        fail("TM-WF-0002 permits only assets_mode=unchanged")
    if confirmation.get("mode") not in {"enabled", "disabled", "unknown"}:
        fail("release confirmation mode must be enabled, disabled, or unknown")
    if confirmation.get("mode") == "unknown" and confirmation.get("observed_at") is not None:
        fail("unknown release confirmation state must not claim an observation time")
    if confirmation.get("mode") != "unknown" and not confirmation.get("observed_at"):
        fail("enabled/disabled release confirmation state requires observation provenance")
    if not confirmation.get("source"):
        fail("release confirmation policy source is missing")
    if not isinstance(policy.get("svn_url"), str) or not policy["svn_url"]:
        fail("WordPress.org policy SVN URL is missing")
    return policy


def validate_version(version: str) -> None:
    import re

    if not re.fullmatch(r"[0-9]+(?:\.[0-9]+){1,2}", version):
        fail("SVN release version must contain two or three numeric components")


def working_copy_url(working_copy: Path) -> str:
    if not working_copy.is_dir() or not (working_copy / ".svn").is_dir():
        fail(f"not an SVN working-copy root: {working_copy}")
    return run_svn("info", "--show-item", "url", str(working_copy)).stdout.strip().rstrip("/")


def validate_working_copy(working_copy: Path, policy: dict[str, Any]) -> None:
    expected = str(policy["svn_url"]).rstrip("/")
    actual = working_copy_url(working_copy)
    if actual != expected:
        fail(f"unexpected SVN working-copy URL: expected {expected}, got {actual}")
    required = {"trunk", "tags", "assets"}
    present = {path.name for path in working_copy.iterdir() if path.is_dir() and path.name != ".svn"}
    if not required.issubset(present):
        fail(f"SVN layout is missing required directories: {sorted(required - present)}")
    unknown = present - {"trunk", "tags", "assets", "branches"}
    if unknown:
        fail(f"unexpected directories at WordPress.org SVN root: {sorted(unknown)}")


def regular_tree(directory: Path) -> list[dict[str, Any]]:
    if not directory.is_dir() or directory.is_symlink():
        fail(f"tree root is missing or unsafe: {directory}")
    files: list[dict[str, Any]] = []
    for current_root, directory_names, file_names in os.walk(directory, followlinks=False):
        current = Path(current_root)
        directory_names[:] = [name for name in directory_names if name != ".svn"]
        for directory_name in directory_names:
            target = current / directory_name
            if target.is_symlink() or directory_name.startswith("."):
                fail(f"unsafe directory in tree: {target.relative_to(directory)}")
        for file_name in file_names:
            target = current / file_name
            if ".svn" in target.relative_to(directory).parts:
                continue
            relative = target.relative_to(directory).as_posix()
            if target.is_symlink() or not stat.S_ISREG(target.stat().st_mode):
                fail(f"tree entry is not a regular file: {relative}")
            digest = hashlib.sha256(target.read_bytes()).hexdigest()
            files.append({"path": relative, "mode": "0644", "size": target.stat().st_size, "sha256": digest})
    return sorted(files, key=lambda item: item["path"])


def digest_tree(files: Iterable[dict[str, Any]]) -> str:
    digest = hashlib.sha256()
    for item in sorted(files, key=lambda value: value["path"]):
        digest.update(item["path"].encode("utf-8"))
        digest.update(b"\0")
        digest.update(item["sha256"].encode("ascii"))
        digest.update(b"\0")
        digest.update(str(item["mode"]).encode("ascii"))
        digest.update(b"\n")
    return digest.hexdigest()


def last_changed_revision(path: Path) -> int:
    value = run_svn("info", "--show-item", "last-changed-revision", str(path)).stdout.strip()
    try:
        return int(value)
    except ValueError:
        fail(f"invalid SVN last-changed revision for {path}: {value!r}")
    raise AssertionError("unreachable")


def snapshot(working_copy: Path, policy: dict[str, Any], version: str) -> dict[str, Any]:
    validate_version(version)
    validate_working_copy(working_copy, policy)
    tag_path = working_copy / "tags" / version
    trunk_files = regular_tree(working_copy / "trunk")
    assets_files = regular_tree(working_copy / "assets")
    root_revision = int(run_svn("info", "--show-item", "revision", str(working_copy)).stdout.strip())
    return {
        "schema_version": 1,
        "slug": policy["slug"],
        "svn_url": str(policy["svn_url"]).rstrip("/"),
        "working_copy_revision": root_revision,
        "version": version,
        "target_tag_exists": tag_path.exists(),
        "trunk": {
            "last_changed_revision": last_changed_revision(working_copy / "trunk"),
            "file_count": len(trunk_files),
            "tree_sha256": digest_tree(trunk_files),
        },
        "assets": {
            "mode": "unchanged",
            "last_changed_revision": last_changed_revision(working_copy / "assets"),
            "file_count": len(assets_files),
            "tree_sha256": digest_tree(assets_files),
        },
        "release_confirmation": policy["release_confirmation"],
    }


def clear_trunk(trunk: Path) -> None:
    if trunk.name != "trunk" or not trunk.is_dir() or trunk.is_symlink():
        fail(f"refusing to replace unsafe trunk path: {trunk}")
    for child in trunk.iterdir():
        if child.name == ".svn":
            fail("unexpected nested .svn directory under trunk")
        deletion = run_svn("delete", "--force", str(child), check=False)
        if deletion.returncode == 0:
            continue
        if child.is_dir() and not child.is_symlink():
            shutil.rmtree(child)
        elif child.exists() or child.is_symlink():
            child.unlink()


def extract_manifest_payload(package: Path, manifest: dict[str, Any], trunk: Path) -> None:
    expected = {item["path"]: item for item in manifest["files"]}
    with zipfile.ZipFile(package, "r") as archive:
        for relative, item in sorted(expected.items()):
            pure = PurePosixPath(relative)
            if pure.is_absolute() or ".." in pure.parts:
                fail(f"unsafe manifest path: {relative}")
            destination = trunk / relative
            destination.parent.mkdir(parents=True, exist_ok=True)
            data = archive.read(f"thumbnail-manager/{relative}")
            if hashlib.sha256(data).hexdigest() != item["sha256"]:
                fail(f"package content changed while staging: {relative}")
            destination.write_bytes(data)
            destination.chmod(0o644)


def validate_status_paths(working_copy: Path, version: str) -> list[str]:
    status = run_svn("status", str(working_copy)).stdout.splitlines()
    normalized: list[str] = []
    allowed_tag = f"tags/{version}"
    for raw in status:
        if not raw.strip():
            continue
        path_text = raw[8:].strip() if len(raw) >= 8 else ""
        target = Path(path_text)
        try:
            relative = target.resolve().relative_to(working_copy.resolve()).as_posix()
        except (OSError, ValueError):
            fail(f"SVN status escaped the working copy: {raw}")
        if relative != "trunk" and not relative.startswith("trunk/") and relative != allowed_tag and not relative.startswith(f"{allowed_tag}/"):
            fail(f"unexpected SVN publication delta outside trunk/new tag: {raw}")
        normalized.append(raw)
    if not normalized:
        fail("SVN staging produced no publication delta")
    return normalized


def stage_svn(args: argparse.Namespace) -> dict[str, Any]:
    working_copy = Path(args.working_copy).resolve()
    policy = load_policy(Path(args.policy).resolve())
    validator = load_validator()
    package = Path(args.package).resolve()
    manifest_path = Path(args.manifest).resolve()
    try:
        manifest = validator.validate_package(package, manifest_path)
    except Exception as error:  # The trusted validator owns the specific error type.
        fail(f"release package validation failed: {error}")
    if manifest["version"] != args.version:
        fail("release manifest version does not match requested SVN tag")
    before = snapshot(working_copy, policy, args.version)
    if before["target_tag_exists"]:
        fail(f"WordPress.org SVN tag already exists: tags/{args.version}")

    trunk = working_copy / "trunk"
    clear_trunk(trunk)
    extract_manifest_payload(package, manifest, trunk)
    run_svn("add", "--force", "--no-ignore", str(trunk))
    run_svn("copy", str(trunk), str(working_copy / "tags" / args.version))
    status_lines = validate_status_paths(working_copy, args.version)

    staged_files = regular_tree(trunk)
    if digest_tree(staged_files) != manifest["tree_sha256"] or len(staged_files) != manifest["file_count"]:
        fail("staged SVN trunk does not match the release manifest")
    tag_files = regular_tree(working_copy / "tags" / args.version)
    if digest_tree(tag_files) != manifest["tree_sha256"]:
        fail("staged SVN tag does not match the release manifest")

    result = {
        "status": "READ_ONLY_PUBLICATION_PREFLIGHT",
        "snapshot": before,
        "manifest_sha256": manifest["manifest_sha256"],
        "source_sha": manifest["source_sha"],
        "tree_sha256": manifest["tree_sha256"],
        "version": args.version,
        "svn_status": status_lines,
    }
    Path(args.output).write_text(json.dumps(result, indent=2, sort_keys=True) + "\n", encoding="utf-8")
    return result


def compare_snapshot(args: argparse.Namespace) -> dict[str, Any]:
    working_copy = Path(args.working_copy).resolve()
    policy = load_policy(Path(args.policy).resolve())
    expected_record = json.loads(Path(args.snapshot).read_text(encoding="utf-8"))
    expected = expected_record.get("snapshot", expected_record)
    current = snapshot(working_copy, policy, args.version)
    mismatches: list[str] = []
    if current["target_tag_exists"]:
        mismatches.append("target SVN tag now exists")
    for surface in ("trunk", "assets"):
        for field in ("last_changed_revision", "file_count", "tree_sha256"):
            if current[surface][field] != expected[surface][field]:
                mismatches.append(
                    f"{surface}.{field}: expected {expected[surface][field]}, got {current[surface][field]}"
                )
    if mismatches:
        fail("final pre-mutation remote recheck failed: " + "; ".join(mismatches))
    return {"status": "FINAL_PRE_MUTATION_REMOTE_RECHECK", "snapshot": current}


def validate_tree_against_manifest(directory: Path, manifest_path: Path) -> dict[str, Any]:
    validator = load_validator()
    try:
        manifest = validator.load_and_authenticate_manifest(manifest_path)
    except Exception as error:
        fail(f"release manifest validation failed: {error}")
    actual = regular_tree(directory)
    expected = manifest["files"]
    if actual != expected or digest_tree(actual) != manifest["tree_sha256"]:
        actual_by_path = {item["path"]: item for item in actual}
        expected_by_path = {item["path"]: item for item in expected}
        missing = sorted(expected_by_path.keys() - actual_by_path.keys())
        extra = sorted(actual_by_path.keys() - expected_by_path.keys())
        changed = sorted(
            path
            for path in actual_by_path.keys() & expected_by_path.keys()
            if actual_by_path[path] != expected_by_path[path]
        )
        fail(
            f"published tree does not match release manifest: {directory}; "
            f"missing={missing}, extra={extra}, changed={changed}"
        )
    return {
        "status": "TREE_VERIFIED",
        "directory": str(directory),
        "file_count": len(actual),
        "tree_sha256": manifest["tree_sha256"],
    }


def verify_svn_publication(args: argparse.Namespace) -> dict[str, Any]:
    validate_version(args.version)
    if not re.fullmatch(r"[0-9a-f]{40}", args.candidate_sha):
        fail("candidate SHA must be a full lowercase 40-character Git SHA")
    if not re.fullmatch(r"[0-9]+", args.publish_run_id):
        fail("publication run ID must be numeric")
    working_copy = Path(args.working_copy).resolve()
    policy = load_policy(Path(args.policy).resolve())
    manifest_path = Path(args.manifest).resolve()
    preflight_record = json.loads(Path(args.preflight_record).read_text(encoding="utf-8"))
    approved = preflight_record.get("snapshot", preflight_record)
    preflight_expected = {
        "status": "READ_ONLY_PUBLICATION_PREFLIGHT",
        "source_sha": args.candidate_sha,
        "version": args.version,
    }
    if any(preflight_record.get(key) != value for key, value in preflight_expected.items()):
        fail("approved preflight candidate identity mismatch")
    if preflight_record.get("manifest_sha256") is None or approved.get("target_tag_exists") is not False:
        fail("approved preflight record is incomplete or did not prove target-tag absence")
    current = snapshot(working_copy, policy, args.version)
    if not current["target_tag_exists"]:
        fail(f"WordPress.org SVN tag is missing: tags/{args.version}")

    trunk_result = validate_tree_against_manifest(working_copy / "trunk", manifest_path)
    tag_result = validate_tree_against_manifest(working_copy / "tags" / args.version, manifest_path)
    for field in ("file_count", "tree_sha256"):
        if current["assets"][field] != approved["assets"][field]:
            fail(f"WordPress.org assets changed since approved preflight: assets.{field}")

    validator = load_validator()
    manifest = validator.load_and_authenticate_manifest(manifest_path)
    if preflight_record["manifest_sha256"] != manifest["manifest_sha256"]:
        fail("approved preflight manifest identity mismatch")
    log_result = run_svn("log", "--xml", "--limit", "1", str(working_copy / "tags" / args.version))
    try:
        entry = ElementTree.fromstring(log_result.stdout).find("logentry")
        if entry is None:
            fail("WordPress.org SVN tag has no log entry")
        revision = int(entry.attrib["revision"])
        message = entry.findtext("msg") or ""
    except (ElementTree.ParseError, KeyError, ValueError) as error:
        fail(f"could not authenticate WordPress.org SVN release log: {error}")

    expected_fragments = (
        f"Release {args.version} from Git {args.candidate_sha}",
        f"manifest {manifest['manifest_sha256']}",
        f"/actions/runs/{args.publish_run_id}",
    )
    missing = [fragment for fragment in expected_fragments if fragment not in message]
    if missing:
        fail(f"WordPress.org SVN release log identity mismatch: {missing}")
    if args.expected_revision is not None and revision != args.expected_revision:
        fail(f"WordPress.org SVN revision mismatch: expected {args.expected_revision}, got {revision}")

    result = {
        "status": "SVN_COMMITTED_VERIFIED",
        "candidate_sha": args.candidate_sha,
        "version": args.version,
        "manifest_sha256": manifest["manifest_sha256"],
        "svn_revision": revision,
        "trunk": trunk_result,
        "tag": tag_result,
        "assets": current["assets"],
    }
    Path(args.output).write_text(json.dumps(result, indent=2, sort_keys=True) + "\n", encoding="utf-8")
    return result


def command_snapshot(args: argparse.Namespace) -> None:
    result = snapshot(Path(args.working_copy).resolve(), load_policy(Path(args.policy).resolve()), args.version)
    if args.output:
        Path(args.output).write_text(json.dumps(result, indent=2, sort_keys=True) + "\n", encoding="utf-8")
    print(json.dumps(result, indent=2, sort_keys=True))


def command_stage(args: argparse.Namespace) -> None:
    print(json.dumps(stage_svn(args), indent=2, sort_keys=True))


def command_compare(args: argparse.Namespace) -> None:
    print(json.dumps(compare_snapshot(args), indent=2, sort_keys=True))


def command_validate_tree(args: argparse.Namespace) -> None:
    print(
        json.dumps(
            validate_tree_against_manifest(Path(args.directory).resolve(), Path(args.manifest).resolve()),
            indent=2,
            sort_keys=True,
        )
    )


def command_verify_svn_publication(args: argparse.Namespace) -> None:
    print(json.dumps(verify_svn_publication(args), indent=2, sort_keys=True))


def parser() -> argparse.ArgumentParser:
    root = argparse.ArgumentParser(description=__doc__)
    subparsers = root.add_subparsers(dest="command", required=True)

    snapshot_parser = subparsers.add_parser("snapshot")
    snapshot_parser.add_argument("--working-copy", required=True)
    snapshot_parser.add_argument("--policy", required=True)
    snapshot_parser.add_argument("--version", required=True)
    snapshot_parser.add_argument("--output")
    snapshot_parser.set_defaults(handler=command_snapshot)

    stage_parser = subparsers.add_parser("stage-svn")
    stage_parser.add_argument("--working-copy", required=True)
    stage_parser.add_argument("--policy", required=True)
    stage_parser.add_argument("--version", required=True)
    stage_parser.add_argument("--package", required=True)
    stage_parser.add_argument("--manifest", required=True)
    stage_parser.add_argument("--output", required=True)
    stage_parser.set_defaults(handler=command_stage)

    compare_parser = subparsers.add_parser("compare-snapshot")
    compare_parser.add_argument("--working-copy", required=True)
    compare_parser.add_argument("--policy", required=True)
    compare_parser.add_argument("--version", required=True)
    compare_parser.add_argument("--snapshot", required=True)
    compare_parser.set_defaults(handler=command_compare)

    tree_parser = subparsers.add_parser("validate-tree")
    tree_parser.add_argument("--directory", required=True)
    tree_parser.add_argument("--manifest", required=True)
    tree_parser.set_defaults(handler=command_validate_tree)

    verification_parser = subparsers.add_parser("verify-svn-publication")
    verification_parser.add_argument("--working-copy", required=True)
    verification_parser.add_argument("--policy", required=True)
    verification_parser.add_argument("--version", required=True)
    verification_parser.add_argument("--candidate-sha", required=True)
    verification_parser.add_argument("--publish-run-id", required=True)
    verification_parser.add_argument("--manifest", required=True)
    verification_parser.add_argument("--preflight-record", required=True)
    verification_parser.add_argument("--expected-revision", type=int)
    verification_parser.add_argument("--output", required=True)
    verification_parser.set_defaults(handler=command_verify_svn_publication)
    return root


def main() -> int:
    args = parser().parse_args()
    try:
        args.handler(args)
    except (WporgReleaseError, OSError, json.JSONDecodeError, zipfile.BadZipFile) as error:
        print(f"ERROR: {error}", file=sys.stderr)
        return 1
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
