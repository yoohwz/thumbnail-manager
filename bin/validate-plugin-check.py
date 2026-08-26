#!/usr/bin/env python3
"""Compare Plugin Check output with the reviewed legacy warning baseline."""

from __future__ import annotations

import argparse
import html
import json
import sys
from collections import Counter
from pathlib import Path
from typing import Any


def normalize_message(message: str) -> str:
    value = html.unescape(str(message)).replace("\\n", " ").replace("\\t", " ")
    return " ".join(value.split())


def fingerprint(path: str, finding: dict[str, Any]) -> tuple[str, str, str]:
    return (path.replace("\\", "/"), str(finding.get("code", "")), normalize_message(finding.get("message", "")))


def read_results(path: Path) -> list[tuple[str, dict[str, Any]]]:
    if not path.exists():
        raise FileNotFoundError(f"Plugin Check result file is missing: {path}")
    current_file = ""
    findings: list[tuple[str, dict[str, Any]]] = []
    for line_number, raw in enumerate(path.read_text(encoding="utf-8").splitlines(), 1):
        if raw.startswith("FILE: "):
            current_file = raw[6:].strip().replace("\\", "/")
            continue
        if not raw.startswith("["):
            continue
        if not current_file:
            raise ValueError(f"finding array at line {line_number} has no FILE header")
        data = json.loads(raw)
        if not isinstance(data, list):
            raise ValueError(f"finding payload at line {line_number} is not a list")
        findings.extend((current_file, item) for item in data if isinstance(item, dict))
    return findings


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--results", required=True)
    parser.add_argument("--baseline", required=True)
    args = parser.parse_args()

    baseline = json.loads(Path(args.baseline).read_text(encoding="utf-8"))
    if baseline.get("schema_version") != 1:
        print("ERROR: unsupported Plugin Check baseline schema", file=sys.stderr)
        return 1
    approved = Counter(
        {
            (item["path"], item["code"], item["message"]): int(item["count"])
            for item in baseline.get("fingerprints", [])
        }
    )

    try:
        findings = read_results(Path(args.results))
    except (OSError, ValueError, json.JSONDecodeError) as error:
        print(f"ERROR: could not parse Plugin Check results: {error}", file=sys.stderr)
        return 1

    errors = [(path, finding) for path, finding in findings if str(finding.get("type", "")).upper() == "ERROR"]
    warnings = [
        (path, finding) for path, finding in findings if str(finding.get("type", "")).upper() == "WARNING"
    ]
    unexpected_types = sorted(
        {
            str(finding.get("type", ""))
            for _, finding in findings
            if str(finding.get("type", "")).upper() not in {"WARNING", "ERROR"}
        }
    )
    current = Counter(fingerprint(path, finding) for path, finding in warnings)
    new_or_increased = {
        key: count - approved.get(key, 0) for key, count in current.items() if count > approved.get(key, 0)
    }

    summary = {
        "status": "PLUGIN_CHECK_BASELINE_VALIDATED",
        "total_findings": len(findings),
        "warnings": len(warnings),
        "errors": len(errors),
        "approved_warning_capacity": sum(approved.values()),
        "removed_warnings": sum(max(approved[key] - current.get(key, 0), 0) for key in approved),
        "new_or_increased_fingerprints": len(new_or_increased),
    }
    print(json.dumps(summary, indent=2, sort_keys=True))

    if errors:
        for path, finding in errors:
            print(f"ERROR: Plugin Check error: {path}: {finding.get('code')}: {normalize_message(finding.get('message', ''))}", file=sys.stderr)
    for (path, code, message), increase in sorted(new_or_increased.items()):
        print(f"ERROR: new/increased Plugin Check warning (+{increase}): {path}: {code}: {message}", file=sys.stderr)
    if unexpected_types:
        print(f"ERROR: unsupported Plugin Check finding types: {unexpected_types}", file=sys.stderr)

    return 1 if errors or new_or_increased or unexpected_types else 0


if __name__ == "__main__":
    raise SystemExit(main())
