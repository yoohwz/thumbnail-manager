#!/usr/bin/env python3
"""Regression tests for deterministic release and fail-closed SVN tooling."""

from __future__ import annotations

import importlib.util
import json
import os
import shutil
import subprocess
import tempfile
import unittest
import zipfile
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
FULL_SHA = "423b4cb5dac8657bdf298457a199a9137810a8f2"


def run(*arguments: str, cwd: Path | None = None, expect_success: bool = True) -> subprocess.CompletedProcess[str]:
    result = subprocess.run(
        list(arguments),
        cwd=str(cwd or ROOT),
        text=True,
        stdout=subprocess.PIPE,
        stderr=subprocess.PIPE,
        check=False,
    )
    if expect_success and result.returncode != 0:
        raise AssertionError(f"command failed: {arguments}\nstdout={result.stdout}\nstderr={result.stderr}")
    if not expect_success and result.returncode == 0:
        raise AssertionError(f"command unexpectedly succeeded: {arguments}\nstdout={result.stdout}")
    return result


def load_module(name: str, path: Path):
    specification = importlib.util.spec_from_file_location(name, path)
    assert specification and specification.loader
    module = importlib.util.module_from_spec(specification)
    specification.loader.exec_module(module)
    return module


class ReleasePackageTests(unittest.TestCase):
    def setUp(self) -> None:
        self.temporary = tempfile.TemporaryDirectory(prefix="tm-release-tests-")
        self.temp = Path(self.temporary.name)

    def tearDown(self) -> None:
        self.temporary.cleanup()

    def build(self, output: Path) -> tuple[Path, Path]:
        run(
            "bash",
            "bin/build-release.sh",
            "--source",
            ".",
            "--output-dir",
            str(output),
            "--version",
            "1.4.0",
            "--source-sha",
            FULL_SHA,
            "--preparation-run-id",
            "local",
        )
        return output / "thumbnail-manager-1.4.0.zip", output / "release-manifest.json"

    def test_build_is_byte_deterministic_and_exact(self) -> None:
        first_package, first_manifest = self.build(self.temp / "first")
        second_package, second_manifest = self.build(self.temp / "second")
        self.assertEqual(first_package.read_bytes(), second_package.read_bytes())
        self.assertEqual(first_manifest.read_bytes(), second_manifest.read_bytes())

        manifest = json.loads(first_manifest.read_text(encoding="utf-8"))
        self.assertEqual(17, manifest["file_count"])
        paths = {item["path"] for item in manifest["files"]}
        self.assertIn("thumbnail-manager.php", paths)
        self.assertIn("inc/job-storage.php", paths)
        self.assertNotIn("AGENTS.md", paths)
        self.assertFalse(any(path.startswith(("tests/", ".github/", "vendor/", "release/")) for path in paths))

        with zipfile.ZipFile(first_package) as archive:
            self.assertEqual(
                {f"thumbnail-manager/{path}" for path in paths},
                set(archive.namelist()),
            )

    def test_package_tampering_fails_closed(self) -> None:
        package, manifest = self.build(self.temp / "tamper")
        with package.open("ab") as handle:
            handle.write(b"tampered")
        run(
            "python3",
            "bin/validate-release.py",
            "validate-package",
            "--package",
            str(package),
            "--manifest",
            str(manifest),
            expect_success=False,
        )

    def test_symlink_payload_is_rejected(self) -> None:
        source = self.temp / "candidate"
        shutil.copytree(ROOT, source, ignore=shutil.ignore_patterns(".git", "vendor"))
        (source / "css" / "style.css").unlink()
        (source / "css" / "style.css").symlink_to(source / "readme.txt")
        run(
            "bash",
            "bin/build-release.sh",
            "--source",
            str(source),
            "--output-dir",
            str(self.temp / "symlink-output"),
            "--version",
            "1.4.0",
            "--source-sha",
            FULL_SHA,
            expect_success=False,
        )

    def test_plugin_check_fingerprint_ignores_line_movement_and_blocks_new_warning(self) -> None:
        baseline = json.loads((ROOT / "release/plugin-check-baseline.json").read_text(encoding="utf-8"))
        item = baseline["fingerprints"][0]
        results = self.temp / "plugin-check-results.txt"
        finding = {
            "line": 9999,
            "column": 42,
            "type": "WARNING",
            "code": item["code"],
            "message": item["message"],
            "docs": "",
        }
        results.write_text(f"FILE: {item['path']}\n{json.dumps([finding])}\n", encoding="utf-8")
        run(
            "python3",
            "bin/validate-plugin-check.py",
            "--results",
            str(results),
            "--baseline",
            "release/plugin-check-baseline.json",
        )
        finding["code"] = "PluginCheck.New.BlockingFinding"
        results.write_text(f"FILE: {item['path']}\n{json.dumps([finding])}\n", encoding="utf-8")
        run(
            "python3",
            "bin/validate-plugin-check.py",
            "--results",
            str(results),
            "--baseline",
            "release/plugin-check-baseline.json",
            expect_success=False,
        )
        run(
            "python3",
            "bin/validate-plugin-check.py",
            "--results",
            str(self.temp / "missing-plugin-check-results.txt"),
            "--baseline",
            "release/plugin-check-baseline.json",
            expect_success=False,
        )


@unittest.skipUnless(shutil.which("svn") and shutil.which("svnadmin"), "Subversion CLI is not available")
class LocalSvnStateTests(unittest.TestCase):
    def setUp(self) -> None:
        self.temporary = tempfile.TemporaryDirectory(prefix="tm-svn-tests-")
        self.temp = Path(self.temporary.name)
        self.repository = self.temp / "repository"
        self.import_tree = self.temp / "import"
        (self.import_tree / "trunk").mkdir(parents=True)
        (self.import_tree / "tags").mkdir()
        (self.import_tree / "assets").mkdir()
        (self.import_tree / "trunk" / "old.php").write_text("old\n", encoding="utf-8")
        (self.import_tree / "assets" / "icon-128x128.png").write_bytes(b"asset-v1")
        run("svnadmin", "create", str(self.repository))
        self.svn_url = self.repository.as_uri()
        run("svn", "import", "--quiet", str(self.import_tree), self.svn_url, "-m", "initial fixture")
        self.working_copy = self.temp / "working-copy"
        run("svn", "checkout", "--quiet", self.svn_url, str(self.working_copy))
        self.policy = self.temp / "policy.json"
        self.policy.write_text(
            json.dumps(
                {
                    "schema_version": 1,
                    "slug": "thumbnail-manager",
                    "svn_url": self.svn_url,
                    "assets_mode": "unchanged",
                    "release_confirmation": {"mode": "unknown", "observed_at": None, "source": "test"},
                }
            ),
            encoding="utf-8",
        )
        self.build = self.temp / "build"
        run(
            "bash",
            "bin/build-release.sh",
            "--source",
            ".",
            "--output-dir",
            str(self.build),
            "--version",
            "1.4.0",
            "--source-sha",
            FULL_SHA,
        )

    def tearDown(self) -> None:
        self.temporary.cleanup()

    def test_stage_is_local_only_and_final_recheck_detects_relevant_drift(self) -> None:
        preflight = self.temp / "preflight.json"
        run(
            "python3",
            "bin/wporg-release.py",
            "stage-svn",
            "--working-copy",
            str(self.working_copy),
            "--policy",
            str(self.policy),
            "--version",
            "1.4.0",
            "--package",
            str(self.build / "thumbnail-manager-1.4.0.zip"),
            "--manifest",
            str(self.build / "release-manifest.json"),
            "--output",
            str(preflight),
        )
        self.assertFalse((self.temp / "remote-check" / "tags" / "1.4.0").exists())
        remote_check = self.temp / "remote-check"
        run("svn", "checkout", "--quiet", self.svn_url, str(remote_check))
        self.assertFalse((remote_check / "tags" / "1.4.0").exists(), "local staging must not mutate remote SVN")
        run(
            "python3",
            "bin/wporg-release.py",
            "compare-snapshot",
            "--working-copy",
            str(remote_check),
            "--policy",
            str(self.policy),
            "--version",
            "1.4.0",
            "--snapshot",
            str(preflight),
        )

        (remote_check / "assets" / "icon-128x128.png").write_bytes(b"asset-v2")
        run("svn", "commit", "--quiet", str(remote_check / "assets"), "-m", "asset drift")
        drifted = self.temp / "drifted"
        run("svn", "checkout", "--quiet", self.svn_url, str(drifted))
        run(
            "python3",
            "bin/wporg-release.py",
            "compare-snapshot",
            "--working-copy",
            str(drifted),
            "--policy",
            str(self.policy),
            "--version",
            "1.4.0",
            "--snapshot",
            str(preflight),
            expect_success=False,
        )

    def test_ambiguous_commit_can_be_verified_from_exact_svn_log_and_trees(self) -> None:
        preflight = self.temp / "preflight.json"
        run(
            "python3",
            "bin/wporg-release.py",
            "stage-svn",
            "--working-copy",
            str(self.working_copy),
            "--policy",
            str(self.policy),
            "--version",
            "1.4.0",
            "--package",
            str(self.build / "thumbnail-manager-1.4.0.zip"),
            "--manifest",
            str(self.build / "release-manifest.json"),
            "--output",
            str(preflight),
        )
        manifest = json.loads((self.build / "release-manifest.json").read_text(encoding="utf-8"))
        publish_run_id = "12345"
        message = (
            f"Release 1.4.0 from Git {FULL_SHA}; manifest {manifest['manifest_sha256']}; "
            f"GitHub run https://github.com/yoohwz/thumbnail-manager/actions/runs/{publish_run_id}"
        )
        run(
            "svn",
            "commit",
            "--quiet",
            str(self.working_copy / "trunk"),
            str(self.working_copy / "tags" / "1.4.0"),
            "-m",
            message,
        )
        verified_copy = self.temp / "verified-copy"
        run("svn", "checkout", "--quiet", self.svn_url, str(verified_copy))
        verification = self.temp / "svn-verification.json"
        run(
            "python3",
            "bin/wporg-release.py",
            "verify-svn-publication",
            "--working-copy",
            str(verified_copy),
            "--policy",
            str(self.policy),
            "--version",
            "1.4.0",
            "--candidate-sha",
            FULL_SHA,
            "--publish-run-id",
            publish_run_id,
            "--manifest",
            str(self.build / "release-manifest.json"),
            "--preflight-record",
            str(preflight),
            "--output",
            str(verification),
        )
        record = json.loads(verification.read_text(encoding="utf-8"))
        self.assertEqual("SVN_COMMITTED_VERIFIED", record["status"])
        self.assertEqual(manifest["tree_sha256"], record["tag"]["tree_sha256"])
        run(
            "python3",
            "bin/wporg-release.py",
            "verify-svn-publication",
            "--working-copy",
            str(verified_copy),
            "--policy",
            str(self.policy),
            "--version",
            "1.4.0",
            "--candidate-sha",
            FULL_SHA,
            "--publish-run-id",
            publish_run_id,
            "--manifest",
            str(self.build / "release-manifest.json"),
            "--preflight-record",
            str(preflight),
            "--expected-revision",
            "999",
            "--output",
            str(self.temp / "invalid-verification.json"),
            expect_success=False,
        )


if __name__ == "__main__":
    unittest.main()
