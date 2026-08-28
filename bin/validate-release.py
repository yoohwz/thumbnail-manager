#!/usr/bin/env python3
"""Build and validate deterministic Thumbnail Manager release artifacts."""

from __future__ import annotations

import argparse
import hashlib
import json
import os
import re
import shutil
import stat
import sys
import zipfile
from pathlib import Path, PurePosixPath
from typing import Any, Iterable

CONTROL_ROOT = Path(__file__).resolve().parents[1]
DEFAULT_PAYLOAD_MANIFEST = CONTROL_ROOT / "release" / "payload-manifest.txt"
SLUG = "thumbnail-manager"
FIXED_ZIP_TIME = (1980, 1, 1, 0, 0, 0)
SHA_PATTERN = re.compile(r"[0-9a-f]{40}")
VERSION_PATTERN = re.compile(r"[0-9]+(?:\.[0-9]+){1,2}")
RELEASE_CONTROL_COMPONENTS = (
    ".github/workflows/release-prepare.yml",
    "bin/build-release.sh",
    "bin/validate-plugin-check.py",
    "bin/validate-release.py",
    "release/payload-manifest.txt",
    "release/plugin-check-baseline.json",
)


class ReleaseValidationError(RuntimeError):
    """A fail-closed release contract violation."""


def fail(message: str) -> None:
    raise ReleaseValidationError(message)


def sha256_bytes(data: bytes) -> str:
    return hashlib.sha256(data).hexdigest()


def sha256_file(path: Path) -> str:
    digest = hashlib.sha256()
    with path.open("rb") as handle:
        for chunk in iter(lambda: handle.read(1024 * 1024), b""):
            digest.update(chunk)
    return digest.hexdigest()


def canonical_json_bytes(value: Any) -> bytes:
    return json.dumps(value, ensure_ascii=False, sort_keys=True, separators=(",", ":")).encode("utf-8")


def release_control_identity(
    control_root: Path = CONTROL_ROOT, payload_manifest_path: Path | None = None
) -> dict[str, Any]:
    root = control_root.resolve()
    components: list[dict[str, str]] = []
    for relative in RELEASE_CONTROL_COMPONENTS:
        target = payload_manifest_path.resolve() if relative == "release/payload-manifest.txt" and payload_manifest_path else root / relative
        if not target.is_file():
            fail(f"release-control component is missing: {relative}")
        components.append({"path": relative, "sha256": sha256_file(target)})
    identity: dict[str, Any] = {"schema_version": 1, "components": components}
    identity["bundle_sha256"] = sha256_bytes(canonical_json_bytes(identity))
    return identity


def output_json(value: Any) -> None:
    print(json.dumps(value, ensure_ascii=False, indent=2, sort_keys=True))


def validate_relative_path(raw: str, *, allow_glob: bool = False) -> str:
    value = raw.strip().replace("\\", "/")
    if not value or value.startswith("/"):
        fail(f"invalid relative path: {raw!r}")
    if "\x00" in value:
        fail("NUL byte is not allowed in a payload path")
    parts = PurePosixPath(value).parts
    if any(part in ("", ".", "..") for part in parts):
        fail(f"unsafe payload path: {raw!r}")
    if any(part.startswith(".") for part in parts):
        fail(f"hidden payload path is not allowed: {raw!r}")
    if not allow_glob and any(char in value for char in "*?["):
        fail(f"glob syntax is not allowed here: {raw!r}")
    return value


def load_payload_contract(path: Path) -> list[str]:
    if not path.is_file():
        fail(f"payload manifest is missing: {path}")
    entries: list[str] = []
    for line_number, raw in enumerate(path.read_text(encoding="utf-8").splitlines(), 1):
        value = raw.strip()
        if not value or value.startswith("#"):
            continue
        if value.endswith("/**"):
            prefix = validate_relative_path(value[:-3])
            value = f"{prefix}/**"
        else:
            value = validate_relative_path(value)
        if value in entries:
            fail(f"duplicate payload manifest entry at line {line_number}: {value}")
        entries.append(value)
    if not entries:
        fail("payload manifest is empty")
    return entries


def reject_symlink(path: Path, label: str) -> None:
    if path.is_symlink():
        fail(f"symlink is not distributable ({label}): {path}")


def collect_payload_files(source: Path, entries: Iterable[str]) -> list[tuple[str, Path]]:
    source = source.resolve()
    if not source.is_dir():
        fail(f"candidate source is not a directory: {source}")

    collected: dict[str, Path] = {}
    for entry in entries:
        if entry.endswith("/**"):
            relative_root = entry[:-3]
            target_root = source / relative_root
            reject_symlink(target_root, relative_root)
            if not target_root.is_dir():
                fail(f"required payload directory is missing: {relative_root}")
            for current_root, directory_names, file_names in os.walk(target_root, followlinks=False):
                current = Path(current_root)
                for directory_name in list(directory_names):
                    directory = current / directory_name
                    reject_symlink(directory, directory.relative_to(source).as_posix())
                    if directory_name.startswith("."):
                        fail(f"hidden payload directory is not allowed: {directory.relative_to(source)}")
                for file_name in file_names:
                    candidate = current / file_name
                    relative = candidate.relative_to(source).as_posix()
                    validate_relative_path(relative)
                    reject_symlink(candidate, relative)
                    mode = candidate.stat().st_mode
                    if not stat.S_ISREG(mode):
                        fail(f"payload entry is not a regular file: {relative}")
                    collected[relative] = candidate
        else:
            target = source / entry
            reject_symlink(target, entry)
            if not target.is_file() or not stat.S_ISREG(target.stat().st_mode):
                fail(f"required payload file is missing or not regular: {entry}")
            collected[entry] = target

    folded: dict[str, str] = {}
    for relative in collected:
        key = relative.casefold()
        if key in folded and folded[key] != relative:
            fail(f"case-colliding payload paths: {folded[key]} and {relative}")
        folded[key] = relative
    return sorted(collected.items())


def require_match(pattern: str, text: str, label: str) -> str:
    found = re.search(pattern, text, re.MULTILINE)
    if not found:
        fail(f"could not read {label}")
    return found.group(1).strip()


def metadata_from_texts(plugin: str, readme: str, changelog: str, pot: str) -> dict[str, str]:
    metadata = {
        "version": require_match(r"^\s*\*\s*Version:\s*(\S+)", plugin, "plugin header Version"),
        "constant_version": require_match(
            r"define\(\s*['\"]YOTM_VERSION['\"]\s*,\s*['\"]([^'\"]+)['\"]\s*\)",
            plugin,
            "YOTM_VERSION",
        ),
        "stable_tag": require_match(r"^Stable tag:\s*(\S+)", readme, "readme Stable tag"),
        "requires_wp": require_match(r"^\s*\*\s*Requires at least:\s*(\S+)", plugin, "plugin Requires at least"),
        "readme_requires_wp": require_match(r"^Requires at least:\s*(\S+)", readme, "readme Requires at least"),
        "requires_php": require_match(r"^\s*\*\s*Requires PHP:\s*(\S+)", plugin, "plugin Requires PHP"),
        "readme_requires_php": require_match(r"^Requires PHP:\s*(\S+)", readme, "readme Requires PHP"),
        "tested_up_to": require_match(r"^Tested up to:\s*(\S+)", readme, "readme Tested up to"),
        "changelog_version": require_match(
            r"^=\s*([0-9]+(?:\.[0-9]+){1,2})\s*\(", changelog, "latest changelog version"
        ),
        "readme_changelog_version": require_match(
            r"^=\s*([0-9]+(?:\.[0-9]+){1,2})\s*\(", readme, "latest readme changelog version"
        ),
        "upgrade_notice_version": require_match(
            r"^== Upgrade Notice ==\s*\n\s*=\s*([0-9]+(?:\.[0-9]+){1,2})\s*=",
            readme,
            "readme Upgrade Notice version",
        ),
        "pot_version": require_match(
            r'^"Project-Id-Version:\s*Thumbnail Manager\s+([^\\]+)\\n"', pot, "POT Project-Id-Version"
        ),
    }
    exact_versions = {metadata["version"], metadata["constant_version"], metadata["stable_tag"]}
    if len(exact_versions) != 1:
        fail(
            "version metadata differs: "
            f"header={metadata['version']}, constant={metadata['constant_version']}, stable={metadata['stable_tag']}"
        )
    if metadata["requires_wp"] != metadata["readme_requires_wp"]:
        fail("WordPress minimum differs between plugin header and readme")
    if metadata["requires_php"] != metadata["readme_requires_php"]:
        fail("PHP minimum differs between plugin header and readme")
    if not VERSION_PATTERN.fullmatch(metadata["tested_up_to"]):
        fail(f"invalid Tested up to version: {metadata['tested_up_to']}")
    for field in ("changelog_version", "readme_changelog_version", "upgrade_notice_version", "pot_version"):
        if metadata[field] != metadata["version"]:
            fail(f"{field} must exactly equal active release version {metadata['version']}; got {metadata[field]}")
    return metadata


def validate_source_metadata(source: Path, expected_version: str | None = None) -> dict[str, str]:
    required = {
        "plugin": source / "thumbnail-manager.php",
        "readme": source / "readme.txt",
        "changelog": source / "changelog.txt",
        "pot": source / "languages" / "thumbnail-manager.pot",
    }
    for label, path in required.items():
        if not path.is_file():
            fail(f"required {label} metadata file is missing: {path}")
    metadata = metadata_from_texts(
        required["plugin"].read_text(encoding="utf-8"),
        required["readme"].read_text(encoding="utf-8"),
        required["changelog"].read_text(encoding="utf-8"),
        required["pot"].read_text(encoding="utf-8"),
    )
    if expected_version is not None and metadata["version"] != expected_version:
        fail(f"candidate version {metadata['version']} does not match requested version {expected_version}")
    return metadata


def tree_digest(files: Iterable[dict[str, Any]]) -> str:
    digest = hashlib.sha256()
    for item in sorted(files, key=lambda value: value["path"]):
        digest.update(item["path"].encode("utf-8"))
        digest.update(b"\0")
        digest.update(item["sha256"].encode("ascii"))
        digest.update(b"\0")
        digest.update(str(item["mode"]).encode("ascii"))
        digest.update(b"\n")
    return digest.hexdigest()


def ensure_empty_output(path: Path, source: Path) -> None:
    resolved = path.resolve()
    if resolved == source.resolve() or resolved == CONTROL_ROOT.resolve() or resolved == Path(resolved.anchor):
        fail(f"unsafe output directory: {resolved}")
    if resolved.exists() and any(resolved.iterdir()):
        fail(f"output directory must be absent or empty: {resolved}")
    resolved.mkdir(parents=True, exist_ok=True)


def build_release(args: argparse.Namespace) -> dict[str, Any]:
    source = Path(args.source).resolve()
    output = Path(args.output_dir).resolve()
    if not SHA_PATTERN.fullmatch(args.source_sha):
        fail("source SHA must be a full lowercase 40-character Git SHA")
    if not VERSION_PATTERN.fullmatch(args.version):
        fail("version must contain two or three numeric components")
    if not re.fullmatch(r"[0-9]+|local", args.preparation_run_id):
        fail("preparation run ID must be numeric or 'local'")

    contract_path = Path(args.payload_manifest).resolve()
    contract_entries = load_payload_contract(contract_path)
    control_identity = release_control_identity(payload_manifest_path=contract_path)
    metadata = validate_source_metadata(source, args.version)
    payload_files = collect_payload_files(source, contract_entries)
    ensure_empty_output(output, source)

    staged_root = output / "staged" / SLUG
    staged_root.mkdir(parents=True)
    manifest_files: list[dict[str, Any]] = []
    for relative, candidate in payload_files:
        destination = staged_root / relative
        destination.parent.mkdir(parents=True, exist_ok=True)
        shutil.copyfile(candidate, destination)
        destination.chmod(0o644)
        data = destination.read_bytes()
        manifest_files.append(
            {"path": relative, "mode": "0644", "size": len(data), "sha256": sha256_bytes(data)}
        )

    package_name = f"{SLUG}-{args.version}.zip"
    package_path = output / package_name
    with zipfile.ZipFile(package_path, "w", compression=zipfile.ZIP_DEFLATED, compresslevel=9) as archive:
        for item in manifest_files:
            relative = item["path"]
            info = zipfile.ZipInfo(f"{SLUG}/{relative}", FIXED_ZIP_TIME)
            info.compress_type = zipfile.ZIP_DEFLATED
            info.create_system = 3
            info.external_attr = (stat.S_IFREG | 0o644) << 16
            info.flag_bits |= 0x800
            archive.writestr(info, (staged_root / relative).read_bytes(), compress_type=zipfile.ZIP_DEFLATED, compresslevel=9)

    manifest: dict[str, Any] = {
        "schema_version": 1,
        "slug": SLUG,
        "version": args.version,
        "source_sha": args.source_sha,
        "preparation_run_id": args.preparation_run_id,
        "payload_contract_sha256": sha256_file(contract_path),
        "release_control": control_identity,
        "metadata": metadata,
        "file_count": len(manifest_files),
        "files": manifest_files,
        "tree_sha256": tree_digest(manifest_files),
        "package": {
            "name": package_name,
            "size": package_path.stat().st_size,
            "sha256": sha256_file(package_path),
        },
    }
    manifest["manifest_sha256"] = sha256_bytes(canonical_json_bytes(manifest))
    manifest_path = output / "release-manifest.json"
    manifest_path.write_text(json.dumps(manifest, ensure_ascii=False, indent=2, sort_keys=True) + "\n", encoding="utf-8")
    validate_package(package_path, manifest_path)
    return manifest


def safe_archive_member(name: str) -> str:
    if name.endswith("/"):
        fail(f"explicit directory entry is not part of the deterministic package contract: {name}")
    normalized = name.replace("\\", "/")
    parts = PurePosixPath(normalized).parts
    if len(parts) < 2 or parts[0] != SLUG:
        fail(f"archive entry must be rooted at {SLUG}/: {name}")
    relative = PurePosixPath(*parts[1:]).as_posix()
    return validate_relative_path(relative)


def load_and_authenticate_manifest(path: Path) -> dict[str, Any]:
    try:
        manifest = json.loads(path.read_text(encoding="utf-8"))
    except (OSError, json.JSONDecodeError) as error:
        fail(f"could not read release manifest: {error}")
    if manifest.get("schema_version") != 1 or manifest.get("slug") != SLUG:
        fail("unsupported release manifest identity")
    recorded = manifest.get("manifest_sha256")
    identity = dict(manifest)
    identity.pop("manifest_sha256", None)
    actual = sha256_bytes(canonical_json_bytes(identity))
    if recorded != actual:
        fail(f"release manifest digest mismatch: expected {recorded}, got {actual}")
    return manifest


def validate_package(package_path: Path, manifest_path: Path) -> dict[str, Any]:
    manifest = load_and_authenticate_manifest(manifest_path)
    if not package_path.is_file():
        fail(f"release package is missing: {package_path}")
    package = manifest.get("package", {})
    if package.get("name") != package_path.name:
        fail("package filename does not match release manifest")
    if package.get("size") != package_path.stat().st_size or package.get("sha256") != sha256_file(package_path):
        fail("package size or SHA-256 does not match release manifest")

    expected_files = {item["path"]: item for item in manifest.get("files", [])}
    if len(expected_files) != manifest.get("file_count") or len(expected_files) != len(manifest.get("files", [])):
        fail("release manifest contains duplicate paths or an invalid file count")
    actual_files: dict[str, dict[str, Any]] = {}
    folded: dict[str, str] = {}
    with zipfile.ZipFile(package_path, "r") as archive:
        for info in archive.infolist():
            relative = safe_archive_member(info.filename)
            folded_key = relative.casefold()
            if relative in actual_files or (folded_key in folded and folded[folded_key] != relative):
                fail(f"duplicate or case-colliding archive entry: {relative}")
            folded[folded_key] = relative
            mode = (info.external_attr >> 16) & 0o7777
            if mode != 0o644:
                fail(f"archive file mode must be 0644: {relative} has {mode:04o}")
            data = archive.read(info)
            actual_files[relative] = {
                "path": relative,
                "mode": "0644",
                "size": len(data),
                "sha256": sha256_bytes(data),
            }

    if set(actual_files) != set(expected_files):
        missing = sorted(set(expected_files) - set(actual_files))
        extra = sorted(set(actual_files) - set(expected_files))
        fail(f"archive inventory mismatch: missing={missing}, extra={extra}")
    for relative, expected in expected_files.items():
        if actual_files[relative] != expected:
            fail(f"archive content mismatch for {relative}")
    if tree_digest(actual_files.values()) != manifest.get("tree_sha256"):
        fail("archive tree digest does not match release manifest")

    with zipfile.ZipFile(package_path, "r") as archive:
        metadata = metadata_from_texts(
            archive.read(f"{SLUG}/thumbnail-manager.php").decode("utf-8"),
            archive.read(f"{SLUG}/readme.txt").decode("utf-8"),
            archive.read(f"{SLUG}/changelog.txt").decode("utf-8"),
            archive.read(f"{SLUG}/languages/thumbnail-manager.pot").decode("utf-8"),
        )
    if metadata["version"] != manifest.get("version") or metadata != manifest.get("metadata"):
        fail("archive release metadata does not match release manifest")
    return manifest


def validate_control_binding(
    manifest_path: Path, preparation_record_path: Path, control_root: Path = CONTROL_ROOT
) -> dict[str, Any]:
    manifest = load_and_authenticate_manifest(manifest_path)
    try:
        preparation = json.loads(preparation_record_path.read_text(encoding="utf-8"))
    except (OSError, json.JSONDecodeError) as error:
        fail(f"could not read preparation record: {error}")
    expected = release_control_identity(control_root)
    if manifest.get("release_control") != expected:
        fail("release candidate was prepared with a stale or tampered release-control bundle")
    if preparation.get("release_control") != expected:
        fail("preparation record release-control identity mismatch")
    payload_digest = next(
        item["sha256"] for item in expected["components"] if item["path"] == "release/payload-manifest.txt"
    )
    if manifest.get("payload_contract_sha256") != payload_digest:
        fail("release candidate payload contract differs from trusted protected-main control")
    if preparation.get("manifest_sha256") != manifest.get("manifest_sha256"):
        fail("preparation record does not bind the authenticated manifest")
    return expected


def command_metadata(args: argparse.Namespace) -> None:
    output_json(validate_source_metadata(Path(args.source).resolve(), args.version))


def command_build(args: argparse.Namespace) -> None:
    manifest = build_release(args)
    output_json(
        {
            "status": "RC_PREPARED",
            "package": manifest["package"],
            "manifest_sha256": manifest["manifest_sha256"],
            "tree_sha256": manifest["tree_sha256"],
            "file_count": manifest["file_count"],
        }
    )


def command_validate_package(args: argparse.Namespace) -> None:
    manifest = validate_package(Path(args.package).resolve(), Path(args.manifest).resolve())
    output_json(
        {
            "status": "PACKAGE_VALIDATED",
            "version": manifest["version"],
            "source_sha": manifest["source_sha"],
            "manifest_sha256": manifest["manifest_sha256"],
            "tree_sha256": manifest["tree_sha256"],
        }
    )


def command_control_identity(args: argparse.Namespace) -> None:
    identity = release_control_identity(Path(args.control_root).resolve())
    if args.output:
        Path(args.output).write_text(json.dumps(identity, ensure_ascii=False, indent=2, sort_keys=True) + "\n", encoding="utf-8")
    output_json(identity)


def command_validate_control_binding(args: argparse.Namespace) -> None:
    identity = validate_control_binding(
        Path(args.manifest).resolve(),
        Path(args.preparation_record).resolve(),
        Path(args.control_root).resolve(),
    )
    output_json({"status": "RELEASE_CONTROL_BOUND", **identity})


def parser() -> argparse.ArgumentParser:
    root = argparse.ArgumentParser(description=__doc__)
    subparsers = root.add_subparsers(dest="command", required=True)

    metadata = subparsers.add_parser("metadata", help="validate candidate release metadata")
    metadata.add_argument("--source", required=True)
    metadata.add_argument("--version")
    metadata.set_defaults(handler=command_metadata)

    build = subparsers.add_parser("build", help="build a deterministic release package")
    build.add_argument("--source", required=True)
    build.add_argument("--output-dir", required=True)
    build.add_argument("--version", required=True)
    build.add_argument("--source-sha", required=True)
    build.add_argument("--preparation-run-id", default="local")
    build.add_argument("--payload-manifest", default=str(DEFAULT_PAYLOAD_MANIFEST))
    build.set_defaults(handler=command_build)

    validate = subparsers.add_parser("validate-package", help="validate an existing package and manifest")
    validate.add_argument("--package", required=True)
    validate.add_argument("--manifest", required=True)
    validate.set_defaults(handler=command_validate_package)

    control = subparsers.add_parser("control-identity", help="compute the trusted release-control identity")
    control.add_argument("--control-root", default=str(CONTROL_ROOT))
    control.add_argument("--output")
    control.set_defaults(handler=command_control_identity)

    binding = subparsers.add_parser(
        "validate-control-binding", help="bind an RC artifact to the current trusted release-control identity"
    )
    binding.add_argument("--manifest", required=True)
    binding.add_argument("--preparation-record", required=True)
    binding.add_argument("--control-root", default=str(CONTROL_ROOT))
    binding.set_defaults(handler=command_validate_control_binding)
    return root


def main() -> int:
    args = parser().parse_args()
    try:
        args.handler(args)
    except ReleaseValidationError as error:
        print(f"ERROR: {error}", file=sys.stderr)
        return 1
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
