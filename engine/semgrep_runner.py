#!/usr/bin/env python3
"""
NexaPatch AI - Optional Semgrep & Gitleaks Adapter
Translates Semgrep static analysis and Gitleaks secret scan JSON outputs
into the unified NexaPatch finding format.

Used as an optional security engine adapter; the native PHP scanner continues
to work standalone if Python or these CLI tools are not installed.
"""

import sys
import os
import json
import shutil
import subprocess
import tempfile
import hashlib
import re
from pathlib import Path
from typing import Any, Dict, List, Tuple


def is_tool_available(tool_name: str) -> bool:
    return shutil.which(tool_name) is not None


def relative_path(path: str, target_dir: str) -> str:
    root = Path(target_dir).resolve()
    candidate = Path(path)
    if not candidate.is_absolute():
        candidate = root / candidate
    try:
        return candidate.resolve().relative_to(root).as_posix()
    except ValueError:
        return ""


def metadata_value(value: Any) -> str:
    if isinstance(value, list):
        return str(value[0]) if value else ""
    return str(value or "")


def cwe_reference(cwe: str) -> List[str]:
    match = re.search(r"CWE-(\d+)", cwe, re.IGNORECASE)
    return [f"https://cwe.mitre.org/data/definitions/{match.group(1)}.html"] if match else []


def map_semgrep_finding(hit: Dict[str, Any], target_dir: str) -> Dict[str, Any] | None:
    extra = hit.get("extra")
    if not isinstance(extra, dict):
        extra = {}
    metadata = extra.get("metadata")
    if not isinstance(metadata, dict):
        metadata = {}
    path = relative_path(str(hit.get("path", "")), target_dir)
    if not path:
        return None

    severity_name = str(extra.get("severity", "")).upper()
    severity = {"ERROR": "high", "WARNING": "medium", "INFO": "low"}.get(severity_name, "low")
    confidence_name = metadata_value(metadata.get("confidence")).upper()
    cwe = metadata_value(metadata.get("cwe"))
    cwe_match = re.search(r"CWE-\d+", cwe, re.IGNORECASE)
    cwe = cwe_match.group(0).upper() if cwe_match else "CWE-699"
    rule_id = str(hit.get("check_id") or "SEMGREP-RULE")
    start = hit.get("start")
    end = hit.get("end")
    if not isinstance(start, dict):
        start = {}
    if not isinstance(end, dict):
        end = {}
    line = start.get("line")
    line_end = end.get("line")
    fingerprint = hashlib.sha1(f"{rule_id}|{path}|{line}".encode("utf-8")).hexdigest()
    safe_title = f"Semgrep finding: {rule_id.rsplit('.', 1)[-1]}"
    safe_description = f"Semgrep rule {rule_id} matched. Review the affected code and confirm whether it is exploitable."

    return {
        "rule_id": rule_id[:255],
        "title": safe_title[:150],
        "description": safe_description[:4000],
        "severity": severity,
        "confidence": "confirmed" if confidence_name == "HIGH" else "potential",
        "category": "static_analysis",
        "cwe_id": cwe,
        "owasp_category": metadata_value(metadata.get("owasp"))[:60] or None,
        "impact": "The matched pattern may expose the application to a security weakness.",
        "remediation": "Review the rule and replace the risky pattern with a context-appropriate secure implementation.",
        "references": cwe_reference(cwe),
        "file_path": path,
        "line_start": line if isinstance(line, int) and line > 0 else None,
        "line_end": line_end if isinstance(line_end, int) and line_end > 0 else None,
        "snippet": None,
        "engine": "semgrep",
        "fingerprint": fingerprint,
    }


def run_semgrep(target_dir: str) -> Tuple[str, List[Dict[str, Any]]]:
    if not is_tool_available("semgrep"):
        return "unavailable", []

    cmd = [
        "semgrep", "scan", "--config", os.environ.get("SEMGREP_CONFIG", "p/security-audit"),
        "--metrics=off", "--json", "--quiet", target_dir,
    ]
    try:
        proc = subprocess.run(cmd, capture_output=True, text=True, timeout=300, check=False)
    except subprocess.TimeoutExpired:
        return "failed", []
    except OSError:
        return "failed", []

    if proc.returncode not in (0, 1) or not proc.stdout.strip():
        return "failed", []
    try:
        data = json.loads(proc.stdout)
    except json.JSONDecodeError:
        return "failed", []
    if not isinstance(data, dict) or not isinstance(data.get("results"), list):
        return "failed", []

    findings = [
        finding
        for hit in data.get("results", [])
        if (finding := map_semgrep_finding(hit, target_dir)) is not None
    ]
    return "completed", findings


def map_gitleaks_finding(hit: Dict[str, Any], target_dir: str) -> Dict[str, Any] | None:
    path = relative_path(str(hit.get("File", "")), target_dir)
    if not path:
        return None
    rule_id = str(hit.get("RuleID") or "SECRET")[:200]
    line = hit.get("StartLine")
    fingerprint = hashlib.sha1(f"GITLEAKS-{rule_id}|{path}|{line}".encode("utf-8")).hexdigest()
    return {
        "rule_id": f"GITLEAKS-{rule_id}",
        "title": "Potential hard-coded secret detected",
        "description": "A credential-like value was detected. The secret value is intentionally not stored in the scan report.",
        "severity": "high",
        "confidence": "confirmed",
        "category": "hardcoded_secret",
        "cwe_id": "CWE-798",
        "owasp_category": "A07:2021 Identification and Authentication Failures",
        "impact": "Exposed credentials may allow unauthorized access to systems or data.",
        "remediation": "Revoke and rotate the exposed credential, then store its replacement in a secret manager or environment configuration.",
        "references": cwe_reference("CWE-798"),
        "file_path": path,
        "line_start": line if isinstance(line, int) and line > 0 else None,
        "line_end": hit.get("EndLine") if isinstance(hit.get("EndLine"), int) and hit.get("EndLine") > 0 else None,
        "snippet": None,
        "engine": "gitleaks",
        "fingerprint": fingerprint,
    }


def run_gitleaks(target_dir: str) -> Tuple[str, List[Dict[str, Any]]]:
    if not is_tool_available("gitleaks"):
        return "unavailable", []

    report_file = None
    try:
        with tempfile.NamedTemporaryFile(prefix="nexapatch-gitleaks-", suffix=".json", delete=False) as report:
            report_file = report.name
        cmd = [
            "gitleaks", "dir", "--report-format=json", f"--report-path={report_file}",
            "--redact=100", "--no-banner", target_dir,
        ]
        proc = subprocess.run(cmd, capture_output=True, text=True, timeout=120, check=False)
        if proc.returncode not in (0, 1) or not os.path.isfile(report_file):
            return "failed", []
        with open(report_file, "r", encoding="utf-8") as f:
            data = json.load(f)
    except (OSError, subprocess.TimeoutExpired, json.JSONDecodeError):
        return "failed", []
    finally:
        if report_file and os.path.exists(report_file):
            os.remove(report_file)

    if not isinstance(data, list):
        return "failed", []
    findings = [
        finding
        for hit in data
        if isinstance(hit, dict)
        if (finding := map_gitleaks_finding(hit, target_dir)) is not None
    ]
    return "completed", findings


def main():
    if len(sys.argv) < 2:
        print(json.dumps({"error": "Usage: semgrep_runner.py <target_directory>"}))
        sys.exit(1)

    target = sys.argv[1]
    if not os.path.isdir(target):
        print(json.dumps({"error": f"Directory not found: {target}"}))
        sys.exit(1)

    findings = []
    engines = {}
    if os.environ.get("SEMGREP_ENABLED", "true").lower() in ("1", "true", "yes", "on"):
        status, semgrep_findings = run_semgrep(target)
        engines["semgrep"] = status
        findings.extend(semgrep_findings)
    else:
        engines["semgrep"] = "disabled"

    if os.environ.get("GITLEAKS_ENABLED", "true").lower() in ("1", "true", "yes", "on"):
        status, secret_findings = run_gitleaks(target)
        engines["gitleaks"] = status
        findings.extend(secret_findings)
    else:
        engines["gitleaks"] = "disabled"

    findings = findings[:max(0, int(os.environ.get("MAX_FINDINGS", "3000")))]
    result = {
        "engines": engines,
        "findings_count": len(findings),
        "findings": findings,
    }
    print(json.dumps(result, indent=2))


if __name__ == "__main__":
    main()
