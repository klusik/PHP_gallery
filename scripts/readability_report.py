#!/usr/bin/env python3
# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: scripts/readability_report.py
# Module Type: Advisory Readability Reporter
# Purpose: Report source readability from immutable Git blobs without rewrites.
# Responsibilities:
#   - Apply language-aware width and declaration spacing suggestions.
#   - Publish complete HTML, Markdown, JSON and per-file diagnostic accounting.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)

import argparse
import ast
from collections import Counter
from dataclasses import asdict, dataclass
import fnmatch
import hashlib
import html
import io
import json
import os
from pathlib import Path
import re
import subprocess
import sys
import time
import tokenize
import unicodedata
from urllib.parse import quote
from typing import NotRequired, TypedDict

from readability_documents import markdown_structure, tex_structure


# Bound Git/token-adapter commands owned by this immutable-tree reporter.
# Type: int. Units: seconds. Scope: one command() subprocess invocation.
# Consumers: command() for Git discovery/blob reads and the PHP token adapter.
# Rationale: preserve the 180-second child budget below the audit's 240-second
# report deadline, leaving time for process failure diagnostics and report output.
READABILITY_TOOL_TIMEOUT_SECONDS: int = 180

# Require positive tab stops so width measurement always advances.
# Type: int. Units: display columns. Scope: selected readability rules.
# Consumers: validate_rules() before display_width() receives configured stops.
# Rationale: preserve the minimum of one column; zero cannot define a tab stop.
MIN_TAB_STOP_COLUMNS: int = 1

# Bound selectable tab stops to the supported source-viewing range.
# Type: int. Units: display columns. Scope: selected readability rules.
# Consumers: validate_rules() before width measurement in every language.
# Rationale: preserve the existing upper bound of 16 columns and reject
# implausibly large settings that would dominate source-width recommendations.
MAX_TAB_STOP_COLUMNS: int = 16


class LanguageRules(TypedDict):
    """Language extensions and provisional display-column/spacing thresholds."""

    extensions: list[str]
    preferred: int
    prominent: int
    priority: int
    blank_lines: int


class ExceptionRule(TypedDict):
    """One explained exception matching exactly one path/rule/source fragment."""

    path: str
    rule: str
    contains: str
    reason: str


class Rules(TypedDict):
    """Versioned selected-branch advisory configuration."""

    schema_version: int
    tab_size: int
    languages: dict[str, LanguageRules]
    exclude_globs: dict[str, str]
    exceptions: list[ExceptionRule]


class SourceRow(TypedDict):
    """One decoded immutable source blob with its configured language."""

    source: str
    language: str


class Declaration(TypedDict, total=False):
    """Named source span and attached-doc anchor; Python supplies sibling spacing."""

    start: int
    end: int
    anchor: int
    name: str
    spacing: int


class Structure(TypedDict):
    """Literal-free code, literal fragments and named declaration spans."""

    code: list[str]
    literals: dict[int, list[str]]
    declarations: list[Declaration]
    embedded_lines: NotRequired[list[int]]


class AdapterResult(TypedDict):
    """Existing PHP/JS scanner output with canonical excluded directories."""

    files: dict[str, Structure]
    excluded_directories: list[str]


class FindingRow(TypedDict):
    """Serialized advisory location, measured width, context and suggestion."""

    path: str
    line: int
    language: str
    rule: str
    tier: str
    width: int
    threshold: int
    suggestion: str
    context: str


class SuppressedFinding(FindingRow):
    """Suppressed suggestion retaining its documented exception rationale."""

    reason: str


class StaleException(ExceptionRule):
    """Unapplied exception with the number of matched suggestions."""

    matches: int


class InventoryRow(TypedDict, total=False):
    """Per-file diagnostics, including explicit unavailable parser coverage."""

    path: str
    language: str
    lines: int
    findings: int
    suppressed_noise: dict[str, int]
    coverage: str


class CoverageGap(TypedDict):
    """One file whose Python syntax could not be analyzed."""

    path: str
    reason: str


class Identity(TypedDict):
    """Immutable source/evaluator and provider identity of one manual run."""

    branch: str
    sha: str
    workflow_ref: str
    workflow_sha: str
    run_id: str
    rules_sha256: str


class Report(TypedDict):
    """Complete advisory publication with coverage and noise accounting."""

    schema_version: int
    advisory_only: bool
    identity: Identity
    rules: Rules
    inventory: list[InventoryRow]
    excluded: dict[str, str]
    findings: list[FindingRow]
    suppressed: list[SuppressedFinding]
    stale_exceptions: list[StaleException]
    noise_suppression: dict[str, int]
    coverage_gaps: list[CoverageGap]
    duration_seconds: float
    coverage_limits: list[str]


@dataclass
class Finding:
    """One contextual suggestion; severity never represents a CI error."""

    path: str
    line: int
    language: str
    rule: str
    tier: str
    width: int
    threshold: int
    suggestion: str
    context: str


def command(root: Path, arguments: list[str], data: bytes | None = None) -> bytes:
    """Run a bounded tool and retain its failure as an operational error.

    @param Path root Repository or fixture working directory.
    @param list[str] arguments Executable and literal argument vector.
    @param bytes|None data Optional complete standard input bytes.
    @return bytes Standard output; raise RuntimeError on tool failure.
    """
    result = subprocess.run(
        arguments, cwd=root, input=data, capture_output=True,
        timeout=READABILITY_TOOL_TIMEOUT_SECONDS,
        check=False,
    )
    if result.returncode:
        raise RuntimeError(result.stderr.decode("utf-8", errors="replace"))
    return result.stdout


def display_width(text: str, tab_size: int) -> int:
    """Measure deterministic Unicode terminal columns, excluding line endings.

    @param str text One source line without its newline.
    @param int tab_size Tab stop interval in display columns.
    @return int Width; combining/format marks zero, wide/fullwidth two.
    """
    width = 0
    for character in text:
        if character == "\t":
            width += tab_size - width % tab_size
        elif unicodedata.category(character) in ("Mn", "Me", "Cf"):
            continue
        else:
            width += 2 if unicodedata.east_asian_width(character) in "WF" else 1
    return width


def token_adapter(root: Path, sources: dict[str, SourceRow]) -> AdapterResult:
    """Obtain existing PHP/JS token boundaries and shared directory exclusions.

    @param Path root Checkout containing this branch's token adapter.
    @param dict[str,SourceRow] sources Paths to source/language input fields.
    @return AdapterResult Decoded token structures and directory exclusions.
    """
    payload = json.dumps(sources).encode()
    result = json.loads(command(root, [
        os.environ.get("PHP_GALLERY_PHP", "php"),
        "scripts/readability_tokens.php",
    ], payload))
    result["files"] = result["files"] or {}
    for structure in result["files"].values():
        literals = structure["literals"]
        entries = (enumerate(literals) if isinstance(literals, list)
                   else literals.items())
        structure["literals"] = {int(index): parts for index, parts in entries}
    return result


def exclusion(
    path: str, mode: str, rules: Rules, directories: list[str],
) -> str:
    """Explain why a tracked path is outside authored-source reporting.

    @param str path Git-relative path with forward slashes.
    @param str mode Git tree mode, including symlink and submodule modes.
    @param Rules rules Readability configuration with exclusion glob reasons.
    @param list[str] directories Canonical private/generated directory names.
    @return str Exclusion reason, or empty string for eligible source.
    """
    # Git's regular-file modes: non-executable 100644 and executable 100755.
    if mode != "100644" and mode != "100755":
        return "Symlink/submodule; never followed."
    if Path(path).name.startswith(".env"):
        return "Private environment configuration; never read."
    if any(part in directories for part in Path(path).parts[:-1]):
        return "Canonical private, generated or third-party directory."
    for pattern, reason in rules["exclude_globs"].items():
        if fnmatch.fnmatchcase(path, pattern):
            return reason
    return ""


def snapshot(
    root: Path, sha: str, rules: Rules, directories: list[str],
) -> tuple[dict[str, SourceRow], dict[str, str]]:
    """Read eligible immutable Git blobs in one batch, never installation files.

    @param Path root Git repository checkout.
    @param str sha Immutable commit being analyzed.
    @param Rules rules Language extensions and documented exclusions.
    @param list[str] directories Canonical excluded directory basenames.
    @return tuple[dict[str,SourceRow],dict[str,str]] Source rows and exclusions.
    """
    extensions = {
        extension: language
        for language, settings in rules["languages"].items()
        for extension in settings["extensions"]
    }
    entries = []
    excluded = {}
    tree = command(root, ["git", "ls-tree", "-rz", "--full-tree", sha])
    for entry in tree.split(b"\0"):
        if not entry:
            continue
        metadata, raw_path = entry.split(b"\t", 1)
        mode, kind, oid = metadata.decode().split()
        path = raw_path.decode("utf-8")
        language = extensions.get(Path(path).suffix.lstrip("."))
        if not language:
            continue
        reason = exclusion(path, mode, rules, directories)
        if reason:
            excluded[path] = reason
        elif kind == "blob":
            entries.append((path, language, oid))
    payload = "".join(oid + "\n" for _, _, oid in entries).encode()
    blobs = io.BytesIO(command(root, ["git", "cat-file", "--batch"], payload))
    sources = {}
    for path, language, oid in entries:
        actual, kind, size = blobs.readline().decode().split()
        if actual != oid or kind != "blob":
            raise RuntimeError("Git blob identity mismatch: " + path)
        content = blobs.read(int(size))
        if blobs.read(1) != b"\n":
            raise RuntimeError("Truncated Git blob: " + path)
        sources[path] = {"language": language, "source": content.decode("utf-8-sig")}
    return sources, excluded


def python_structure(source: str) -> Structure:
    """Use native AST and tokens for Python literals and sibling declarations.

    @param str source Complete Python module; never imported or executed.
    @return Structure Code skeletons, literal fragments and sibling spans.
    """
    lines = source.splitlines()
    code = [""] * len(lines)
    literals = {}
    fstring_depth = 0
    for token in tokenize.generate_tokens(io.StringIO(source).readline):
        if token.type in (tokenize.COMMENT, tokenize.ENCODING, tokenize.ENDMARKER):
            continue
        if token.type == getattr(tokenize, "FSTRING_START", -1):
            fstring_depth += 1
        literal = token.type == tokenize.STRING or fstring_depth > 0
        for offset, part in enumerate(token.string.splitlines()):
            index = token.start[0] + offset - 1
            if index >= len(code):
                continue
            if literal:
                literals.setdefault(index, []).append(part)
                code[index] += " L "
            else:
                code[index] += part + " "
        if token.type == getattr(tokenize, "FSTRING_END", -1):
            fstring_depth -= 1
    declarations = []
    tree = ast.parse(source)
    for owner in ast.walk(tree):
        if not isinstance(owner, (ast.Module, ast.ClassDef)):
            continue
        for node in owner.body:
            if not isinstance(node, (ast.FunctionDef, ast.AsyncFunctionDef, ast.ClassDef)):
                continue
            anchor = min([node.lineno] + [item.lineno for item in node.decorator_list])
            declarations.append({
                "start": node.lineno, "end": node.end_lineno,
                "anchor": anchor, "name": node.name,
                "spacing": 2 if isinstance(owner, ast.Module) else 1,
            })
    return {"code": code, "literals": literals, "declarations": declarations}


def css_structure(source: str) -> Structure:
    """Mask CSS comments, quoted values and URLs while preserving line numbers.

    @param str source Authored stylesheet text.
    @return Structure Conservative CSS skeleton; no declaration spacing inference.
    """
    pattern = re.compile(
        r'/\*.*?\*/|url\((?:[^)\\]|\\.)*\)|"(?:[^"\\]|\\.)*"'
        r"|'(?:[^'\\]|\\.)*'", re.S | re.I,
    )
    literals = {}
    pieces = []
    cursor = 0
    for match in pattern.finditer(source):
        pieces.append(source[cursor:match.start()])
        comment = match[0].startswith("/*")
        start = source.count("\n", 0, match.start())
        parts = match[0].split("\n")
        for offset, part in enumerate(parts):
            if not comment:
                literals.setdefault(start + offset, []).append(part)
        pieces.append("\n".join("" if comment else " L " for _ in parts))
        cursor = match.end()
    pieces.append(source[cursor:])
    return {"code": "".join(pieces).splitlines(), "literals": literals,
            "declarations": []}


def analyze(
    path: str, language: str, source: str, structure: Structure, rules: Rules,
) -> tuple[list[Finding], dict[str, int]]:
    """Produce deduplicated width/spacing advice without judging literal contents.

    @param str path Immutable Git-relative source path.
    @param str language Configured language identifier.
    @param str source Complete source text.
    @param Structure structure Code lines, literals and declaration spans.
    @param Rules rules Advisory language thresholds and tab stops.
    @return tuple[list[Finding],dict[str,int]] Findings and noise-suppression counts.
    """
    settings = rules["languages"][language]
    preferred = settings["preferred"]
    lines = source.splitlines()
    findings = []
    skipped = Counter()
    literals = {int(key): value for key, value in structure["literals"].items()}
    embedded = set(structure.get("embedded_lines", []))
    for index, line in enumerate(lines):
        width = display_width(line, rules["tab_size"])
        if width <= preferred:
            continue
        if index in embedded:
            skipped["mixed_php_template"] += 1
            continue
        code = structure["code"][index] if index < len(structure["code"]) else ""
        longest = max((display_width(token, rules["tab_size"])
                       for token in re.findall(r"[\w$]+", code)), default=0)
        literal_width = max((display_width(part, rules["tab_size"])
                             for part in literals.get(index, [])), default=0)
        if literal_width > preferred or longest > preferred:
            skipped["indivisible_literal_or_identifier"] += 1
            continue
        commas = code.count(",")
        boolean = bool(re.search(r"&&|\|\||\b(?:and|or)\b", code))
        if not code.strip() or code.strip() == "L":
            skipped["comment_literal_or_non_code"] += 1
            continue
        prose = language in ("tex", "markdown")
        prose_break = prose and len(code.split()) >= 2
        if not (prose_break or commas or boolean or code.count("(") > 1
                or code.count("->") > 1 or code.count("?.") > 1
                or (language == "css" and (";" in code or ":" in code))):
            skipped["no_confident_semantic_break"] += 1
            continue
        suggestion = "Wrap call/signature arguments or collection entries one per line."
        if language == "tex":
            suggestion = (
                "Wrap prose at existing whitespace outside commands; "
                "preserve paragraph breaks, '~', literals and math."
            )
        elif language == "markdown":
            suggestion = (
                "Wrap prose at existing whitespace with the correct list indent; "
                "preserve paragraphs, hard breaks, links and block syntax."
            )
        elif boolean:
            suggestion = "Group condition terms on separate indented lines; preserve precedence."
        elif language == "css":
            suggestion = (
                "Put selector entries or declarations on separate lines; "
                "preserve CSS tokens."
            )
        elif code.count("->") > 1 or code.count("?.") > 1:
            suggestion = "Split the fluent chain at language-safe member boundaries."
        elif language == "php" and code.count(" . ") > 1:
            suggestion = (
                "Split concatenated presentation parts at expression boundaries; "
                "retain escaping and output order."
            )
        tier = "info"
        threshold = preferred
        if width > settings["prominent"]:
            tier, threshold = "advisory", settings["prominent"]
        if width > settings["priority"]:
            tier, threshold = "priority", settings["priority"]
        findings.append(Finding(path, index + 1, language, "line-width", tier,
                                width, threshold, suggestion, line))
    previous = {}
    for declaration in sorted(structure["declarations"], key=lambda row: row["start"]):
        start, end, anchor = (declaration[key] for key in ("start", "end", "anchor"))
        indent = re.match(r"\s*", lines[start - 1])[0]
        prior = previous.get(indent)
        spacing = declaration.get("spacing", settings["blank_lines"])
        if prior and prior["end"] < anchor and spacing:
            between = lines[prior["end"]:anchor - 1]
            # Only adjacent declarations: intervening executable code owns its layout.
            if all(not text.strip() for text in between) and len(between) < spacing:
                findings.append(Finding(
                    path, start, language, "declaration-spacing", "info",
                    display_width(lines[start - 1], rules["tab_size"]), spacing,
                    f"Consider {spacing} blank line(s) before the attached docs/decorators of "
                    + declaration["name"] + "; keep documentation attached.",
                    lines[start - 1],
                ))
        previous[indent] = declaration
    return findings, dict(skipped)


def apply_exceptions(
    findings: list[Finding], exceptions: list[ExceptionRule],
) -> tuple[list[Finding], list[SuppressedFinding], list[StaleException]]:
    """Apply exact path/rule/context exceptions with a mandatory factual reason.

    @param list[Finding] findings Complete unsuppressed advisory suggestions.
    @param list[ExceptionRule] exceptions Exact path/rule/context/reason records.
    @return tuple[list[Finding],list[SuppressedFinding],list[StaleException]]
        Retained suggestions, explained suppressions and stale exceptions.
    """
    remaining, suppressed, stale = list(findings), [], []
    for exception in exceptions:
        if not all(isinstance(exception.get(key), str) and exception[key].strip()
                   for key in ("path", "rule", "contains", "reason")):
            raise ValueError("Each exception needs exact path, rule, contains and reason.")
        matches = [finding for finding in remaining if (
            finding.path == exception["path"] and finding.rule == exception["rule"]
            and exception["contains"] in finding.context
        )]
        if len(matches) != 1:
            stale.append({**exception, "matches": len(matches)})
            continue
        remaining.remove(matches[0])
        suppressed.append({**asdict(matches[0]), "reason": exception["reason"]})
    return remaining, suppressed, stale


def validate_rules(rules: Rules) -> None:
    """Reject corrupt configuration without inventing a successful report.

    @param Rules rules Selected branch's decoded JSON configuration.
    @return None Raise ValueError for unsupported schema or invalid thresholds.
    """
    # Schema 1 is the current JSON wire format, independent of policy bounds.
    if (rules["schema_version"] != 1 or not (
        MIN_TAB_STOP_COLUMNS <= rules["tab_size"] <= MAX_TAB_STOP_COLUMNS
    )):
        raise ValueError("Unsupported rules schema/tab size.")
    for settings in rules["languages"].values():
        if not 0 < settings["preferred"] < settings["prominent"] < settings["priority"]:
            raise ValueError("Width tiers must be positive and increasing.")


def write_reports(output: Path, report: Report) -> None:
    """Publish escaped, complete human reports plus machine and diagnostic data.

    @param Path output Dedicated audit report directory.
    @param Report report Identity, inventory, suggestions, exceptions and coverage.
    @return None Write HTML, Markdown, JSON, summary and JSONL diagnostics.
    """
    output.mkdir(parents=True, exist_ok=True)
    identity = report["identity"]
    findings = report["findings"]
    title = "Readability recommendations"
    disclaimer = (
        "Suggestions only. No source rewrites, CI errors or merge blockers. "
        "Reasonable exceptions are allowed."
    )
    metadata = (f"Branch: {identity['branch']} · SHA: {identity['sha']} · "
                f"{len(report['inventory'])} files · {len(findings)} suggestions · "
                f"{report['duration_seconds']:.2f} seconds")
    measurement = (
        "Display columns: tabs to configured stops; Unicode combining/format "
        "marks 0, wide/fullwidth 2, other code points 1. "
        "Font shaping and emoji grapheme clusters may differ."
    )
    scope = "Full selected-commit scan; no comparison base or qualification claim."
    markdown = [
        f"# {title}", "", disclaimer, "", metadata, "", measurement, "", scope, "",
    ]
    body = [
        f"<h1>{title}</h1><p class=notice>{disclaimer}</p>",
        f"<p>{html.escape(metadata)}</p><p>{measurement}</p><p>{scope}</p>",
        "<p>Search by file or suggestion. "
        "Each file opens independently; all findings are included.</p>",
        '<input id="search" aria-label="Filter files" '
        'placeholder="Filter files or suggestions">',
    ]
    tiers = dict(Counter(row["tier"] for row in findings))
    markdown += [f"Tiers: {tiers}", "", "[Examples and interpretation](guide.md)", ""]
    body.append(f"<p>Tiers: {html.escape(str(tiers))}</p>"
                '<p><a href="guide.md">Before/after examples and interpretation</a></p>')
    for language, count in sorted(Counter(row["language"] for row in report["inventory"]).items()):
        settings = report["rules"]["languages"][language]
        description = (f"{language}: {count} files; preferred ≤{settings['preferred']}, "
                       f"informational through {settings['prominent']}, "
                       f"prominent above {settings['prominent']}, "
                       f"priority above {settings['priority']} columns.")
        markdown += [description, ""]
        body.append(f"<p>{html.escape(description)}</p>")
    for path in sorted({finding["path"] for finding in findings}):
        rows = [finding for finding in findings if finding["path"] == path]
        markdown += [f"## {path}", ""]
        body.append(f"<details class=file><summary>{html.escape(path)} · {len(rows)}</summary>")
        for finding in rows:
            url = (f"https://github.com/klusik/PHP_gallery/blob/{identity['sha']}/"
                   f"{quote(path, safe='/')}#L{finding['line']}")
            unit = "blank lines" if finding["rule"] == "declaration-spacing" else "columns"
            caption = (f"L{finding['line']} · {finding['language']} · {finding['rule']} · "
                       f"{finding['tier']} · width {finding['width']} · "
                       f"threshold {finding['threshold']} {unit}")
            context = finding["context"]
            # Markdown fences require at least three backticks; use one more
            # than any source run so context cannot close the report's fence.
            runs = (len(run) + 1 for run in re.findall(r"`+", context))
            fence = "`" * max(3, max(runs, default=3))
            markdown += [f"[{caption}]({url})", "", finding["suggestion"], "",
                         fence + finding["language"], context, fence, ""]
            body.append(f'<article><a href="{url}">{html.escape(caption)}</a>'
                        f'<p>{html.escape(finding["suggestion"])}</p>'
                        f'<pre>{html.escape(context)}</pre></article>')
        body.append("</details>")
    accounting = {key: report[key] for key in (
        "coverage_limits", "excluded", "noise_suppression", "suppressed",
        "stale_exceptions", "coverage_gaps",
    )}
    markdown += ["## Coverage and noise accounting", "", "```json",
                 json.dumps(accounting, ensure_ascii=False, indent=2), "```", ""]
    body.append("<details><summary>Coverage and noise accounting</summary><pre>"
                + html.escape(json.dumps(accounting, ensure_ascii=False, indent=2))
                + "</pre></details>")
    style = """
body {
  font: 16px/1.5 system-ui;
  margin: 2rem auto;
  padding: 0 1rem;
  max-width: 1000px;
  color: #18263a;
  background: #f8fafc;
}
.notice {
  padding: 1rem;
  background: #e0f2fe;
  border-left: 4px solid #0284c7;
}
summary { cursor: pointer; font-weight: 600; padding: .6rem; }
details {
  margin: 1rem 0;
  background: white;
  border: 1px solid #cbd5e1;
  border-radius: .5rem;
}
article { padding: 1rem; border-top: 1px solid #e2e8f0; }
pre {
  white-space: pre-wrap;
  overflow-wrap: anywhere;
  font: 13px/1.6 monospace;
  background: #f1f5f9;
  padding: .8rem;
}
input { padding: .7rem; width: 90%; }
a { color: #075985; }
"""
    document = ('<!doctype html><html lang="en"><meta charset="utf-8">'
                '<meta name="viewport" content="width=device-width,initial-scale=1">'
                f"<title>{title}</title><style>{style}</style><body>"
                + "\n".join(body)
                + '<script>document.querySelector("#search").oninput=e=>{'
                'for(const d of document.querySelectorAll(".file"))'
                'd.hidden=!d.textContent.toLowerCase().includes(e.target.value.toLowerCase())}'
                '</script></body></html>')
    (output / "report.html").write_text(document, encoding="utf-8")
    (output / "report.md").write_text("\n".join(markdown), encoding="utf-8")
    (output / "report.json").write_text(
        json.dumps(report, ensure_ascii=False, indent=2), encoding="utf-8",
    )
    (output / "diagnostics.jsonl").write_text("\n".join(
        json.dumps(row, ensure_ascii=False) for row in report["inventory"]
    ) + "\n", encoding="utf-8")
    summary = [f"### {title}", "", disclaimer, "", metadata, "",
               f"Tiers: {dict(Counter(row['tier'] for row in findings))}", "",
               f"Coverage gaps: {len(report['coverage_gaps'])}; "
               f"stale exceptions: {len(report['stale_exceptions'])}.", "",
               "Download the readability-report artifact and open report.html or report.md. "
               "report.json and diagnostics.jsonl retain complete inventory and accounting.", ""]
    (output / "summary.md").write_text("\n".join(summary), encoding="utf-8")


def main() -> int:
    """Bind the selected commit, scan immutable blobs and publish advice.

    @return int Zero regardless of findings; operational/configuration errors raise.
    """
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--output", type=Path, required=True)
    args = parser.parse_args()
    root = Path(__file__).resolve().parent.parent
    started = time.monotonic()
    sha = command(root, ["git", "rev-parse", "HEAD"]).decode().strip()
    expected = os.environ.get("GITHUB_SHA", sha)
    branch = os.environ.get("GITHUB_REF_NAME") or command(
        root, ["git", "branch", "--show-current"],
    ).decode().strip() or "detached"
    if sha != expected:
        raise RuntimeError("Checkout does not match the selected event SHA.")
    if command(root, ["git", "diff", "HEAD", "--", "scripts/readability_report.py",
                      "scripts/readability_tokens.php", "scripts/readability_rules.json",
                      "scripts/readability_documents.py",
                      "scripts/source_contracts", "scripts/cli_guard.php",
                      ".github/workflows/readability-report.yml"]):
        raise RuntimeError("Checker/rules differ from the selected commit.")
    rules_bytes = command(root, ["git", "show", sha + ":scripts/readability_rules.json"])
    rules = json.loads(rules_bytes)
    validate_rules(rules)
    adapter = token_adapter(root, {})
    sources, excluded = snapshot(root, sha, rules, adapter["excluded_directories"])
    structures = token_adapter(root, {
        path: row for path, row in sources.items()
        if row["language"] in ("php", "js", "mjs")
    })["files"]
    findings, inventory, gaps = [], [], []
    noise = Counter()
    for path, row in sorted(sources.items()):
        source, language = row["source"], row["language"]
        try:
            structure = structures.get(path)
            if language == "python":
                structure = python_structure(source)
            elif language == "css":
                structure = css_structure(source)
            elif language == "tex":
                structure = tex_structure(source)
            elif language == "markdown":
                structure = markdown_structure(source)
            selected, skipped = analyze(path, language, source, structure, rules)
            findings.extend(selected)
            noise.update(skipped)
            inventory.append({"path": path, "language": language,
                              "lines": len(source.splitlines()),
                              "findings": len(selected), "suppressed_noise": skipped})
        except (SyntaxError, tokenize.TokenError, IndentationError) as error:
            gaps.append({"path": path, "reason": str(error)})
            inventory.append({"path": path, "language": language, "coverage": "unavailable"})
    findings, suppressed, stale = apply_exceptions(findings, rules["exceptions"])
    rank = {"priority": 0, "advisory": 1, "info": 2}
    findings.sort(key=lambda item: (rank[item.tier], -item.width, item.path, item.line))
    # Version 1 identifies the report wire format, not a tunable policy bound.
    report = {
        "schema_version": 1, "advisory_only": True,
        "identity": {"branch": branch, "sha": sha,
                     "workflow_ref": os.environ.get("GITHUB_WORKFLOW_REF", ""),
                     "workflow_sha": os.environ.get("GITHUB_WORKFLOW_SHA", ""),
                     "run_id": os.environ.get("GITHUB_RUN_ID", ""),
                     "rules_sha256": hashlib.sha256(rules_bytes).hexdigest()},
        "rules": rules, "inventory": inventory, "excluded": excluded,
        "findings": [asdict(item) for item in findings],
        "suppressed": suppressed, "stale_exceptions": stale,
        "noise_suppression": dict(noise), "coverage_gaps": gaps,
        "duration_seconds": round(time.monotonic() - started, 3),
        "coverage_limits": [
            "PHP uses token_get_all; JS/MJS reuse the conservative existing "
            "lexer, not a complete ECMAScript parser.",
            "Python uses AST/tokenize; CSS masks comments/strings/URLs without a full CSS parser.",
            "PHP embedded HTML/JS/CSS is excluded; standalone authored assets are analyzed.",
            "Long literals/identifiers and uncertain semantic breaks "
            "are counted as noise exclusions.",
            "Spacing checks adjacent named declaration siblings, "
            "with attached docs/decorators; CSS has no spacing rule.",
            "TeX/Markdown use conservative prose scanners, not complete parsers. "
            "Commands, math, tables, links and literal blocks are protected; "
            "document paragraph spacing is never inferred.",
            "No formatter is applied. False-positive rate and split-diff "
            "user acceptance require maintainer sampling.",
        ],
    }
    write_reports(args.output, report)
    guide = command(root, ["git", "show", sha + ":docs/CODE_READABILITY.md"])
    (args.output / "guide.md").write_bytes(guide)
    print(f"ADVISORY: {len(findings)} suggestions across {len(inventory)} files at {sha}.")
    print(f"Reports: {args.output}; coverage gaps: {len(gaps)}; source unchanged.")
    return 0


if __name__ == "__main__":
    try:
        sys.exit(main())
    except (RuntimeError, ValueError, OSError, subprocess.SubprocessError) as failure:
        print("Readability report unavailable: " + str(failure), file=sys.stderr)
        sys.exit(2)
