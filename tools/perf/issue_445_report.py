# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: tools/perf/issue_445_report.py
# Module Type: Hosted desktop qualification evidence report
# Purpose: Render the exact issue 445 evidence as a standalone Czech report.
# Responsibilities: Compare renderer trials and publication rates, present
#   captures, and retain raw hosted JSON and CSV beside the report.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)
"""Render the hosted issue 445 evidence without repeating any measurement."""

import argparse
from base64 import b64encode
from html import escape
import hashlib
import json
import math
import os
from pathlib import Path
import re
import shutil
from statistics import median, quantiles

BASELINE = "b63d6d5e5bee014ab3750080833f49dcc27e9a34"
RENDERERS = (
    ("tk-widgets", "Tk Widgets"), ("tk-canvas", "Tk Canvas"), ("qt", "Qt Quick"),
)
CONFIGURATIONS = (
    ("Windows-tkinter", "Windows · Tk bez Qt"),
    ("Windows-pyside6", "Windows · Tk a Qt"),
    ("Linux-pyside6", "Linux · Tk a Qt"),
    ("macOS-pyside6", "macOS · Tk a Qt (advisory)"),
)
SETUPS = (
    ("earlier", "Dřívější kvalifikační onedir"),
    ("candidate", "Standardní onedir"),
)
BUILD_VERSIONS_PREFIX = "WinApp dependency versions: "
CORE_VERSION_FIELDS = (
    ("python", "Python"), ("pillow", "Pillow"), ("pystray", "pystray"),
    ("reportlab", "ReportLab"), ("pypdf", "pypdf"), ("polib", "polib"),
    ("charset_normalizer", "charset-normalizer"), ("six", "six"),
)
QT_VERSION_FIELDS = (("pyside6_essentials", "PySide6 Essentials"), ("shiboken6", "Shiboken6"))
TK_BUILD_VERSION_FIELDS = (("tcl", "Tcl"), ("tk", "Tk"))
TK_VERSION_FIELDS = (*TK_BUILD_VERSION_FIELDS, ("tk_patchlevel", "Tk patch"))
MAC_VERSION_FIELDS = (("pyobjc_core", "PyObjC"), ("pyobjc_framework_cocoa", "Cocoa"),
                      ("pyobjc_framework_quartz", "Quartz"))
LAYOUTS = {
    "published-1.7.1-onefile-tk": ("Vydaný onefile 1.7.1", "published_setup"),
    "historical-96e7-dual-build": ("Dřívější dvojitý build", "prior_setup"),
    "candidate-unified": ("Společný onedir", "candidate_setup"),
}
LAUNCH_PHASES = {
    "cold_process": "První po instalaci",
    "warm_process": "Druhý",
    "third_process": "Třetí",
}
# Purpose: Evaluate paired latency against the existing #434 practical guard.
# Type: tuple[float, float]. Units: milliseconds and relative fraction.
# Scope: paired viewport regressions. Consumers: render_photos.
# Rationale: the established margin is the larger of 5 ms and 15%.
LATENCY_GUARD = (5.0, 0.15)
# Purpose: Display the established practical comparison for observed peak RSS.
# Type: tuple[float, float]. Units: MiB and relative fraction.
# Scope: same-case renderer resources. Consumers: render_photos.
# Rationale: the #434 memory threshold is the larger of 128 MiB and 30%.
MEMORY_GUARD = (128.0, 0.30)
# Purpose: Classify practical Photos publication-throughput differences.
# Type: float. Units: percent. Scope: paired Qt-to-Tk publication rate.
# Consumers: publication_throughput_rows. Rationale: 10% is the requested
# practical guard for material differences in hosted throughput evidence.
PUBLICATION_THROUGHPUT_GUARD_PERCENT = 10.0
# Purpose: Keep the separate synthetic Import loss margin visible in the report.
# Type: float. Units: percent. Scope: paired synthetic Import items per second.
# Consumers: render_import_throughput. Rationale: match issue 434's 10% loss guard.
IMPORT_THROUGHPUT_LOSS_GUARD_PERCENT = 10.0
# Purpose: Use the exact original matrix scale and cache regimes in the report.
# Type: tuple[int, ...]. Units: photos per synthetic catalogue.
# Scope: complete Photos matrix. Consumers: render_photos.
# Rationale: 100, 500 and 2000 mirror the three paired benchmark scales.
PHOTO_COUNTS = (100, 500, 2000)
# Purpose: Require each report to cover the complete interleaved trial set.
# Type: int. Units: independent paired groups. Scope: Photos and Import.
# Consumers: paired_performance_problems and render_import_throughput.
# Rationale: Twelve matches the paired runner and balances the renderer orders.
PERFORMANCE_PAIRED_GROUPS = 12
# Purpose: Bind Import evidence to the documented deterministic workload.
# Type: int. Units: confirmed items per trial. Scope: the warm 2,000-photo lane.
# Consumers: render_import_throughput. Rationale: Matches the #434 small extent.
PERFORMANCE_IMPORT_ITEMS = 100
# Convert observed working-set bytes to a binary mebibyte for display.
BYTES_PER_MIB = 1024 * 1024
# Purpose: Keep the report independent of a self-declared visual manifest scope.
# Type: tuple[str, ...]. Units: viewport IDs. Scope: complete issue 445 visual matrix.
# Consumers: verify_visual_slice_manifest. Rationale: match the four native sizes.
VISUAL_REPORT_SIZES = ("standard", "narrow", "wide", "maximized")
# Purpose: Enumerate every ordinary panel independently of visual slice metadata.
# Type: tuple[str, ...]. Units: workspace IDs. Scope: every native visual size.
# Consumers: verify_visual_slice_manifest. Rationale: match the Tk/Qt workspace matrix.
VISUAL_REPORT_WORKSPACES = (
    "photos", "photos-lightbox", "watcher", "flight", "import", "activity",
    "ai", "settings",
)
# Purpose: Enumerate every additional standard-size Photos state in the report contract.
# Type: tuple[str, ...]. Units: workspace IDs. Scope: issue 445 visual scenarios.
# Consumers: verify_visual_slice_manifest. Rationale: preserve the fourteen explicit states.
VISUAL_REPORT_SCENARIOS = (
    "photos-disconnected", "photos-ratio-16-9", "photos-selected",
    "photos-multiselected", "photos-linked-empty", "photos-hierarchy",
    "photos-info", "photos-context-menu", "photos-connecting",
    "photos-delete-confirmation", "photos-folder-shelf", "photos-music-panel",
    "photos-info-unavailable", "photos-lightbox-fullscreen",
)
# Purpose: Require the ordered scenario partitions produced by native visual children.
# Type: tuple[str, ...]. Units: slice IDs. Scope: issue 445 paired visual evidence.
# Consumers: verify_visual_slice_manifest. Rationale: bind nine fixed child cases.
VISUAL_REPORT_SLICE_IDS = (
    "workspace-standard", "workspace-narrow", "workspace-wide",
    "workspace-maximized", "photos-connect", "photos-selection",
    "photos-tree", "photos-details", "photos-lightbox",
)

CSS = """
:root{color-scheme:light;--paper:#f3f5f3;--ink:#122625;--edge:#d5ded9;
--muted:#4c625c;--sea:#00665f;--orange:#904112;--surface:#fff}
*{box-sizing:border-box}body{margin:0;background:var(--paper);color:var(--ink);
font:16px/1.6 system-ui,sans-serif}main{max-width:80rem;margin:auto;padding:2rem 1rem 4rem}
header,section{padding:clamp(1rem,3vw,2rem);border:1px solid var(--edge);
border-radius:.85rem;background:var(--surface);margin-bottom:1rem}
header{border-top:.38rem solid var(--sea)}h1,h2,h3{line-height:1.23;margin:.25em 0 .65em}
h1{font-size:clamp(2rem,5vw,3.5rem);letter-spacing:-.035em}h2{font-size:1.65rem}
p,figure{margin:.7em 0}a{color:var(--sea);text-underline-offset:.15em}
code{overflow-wrap:anywhere}th,td{text-align:left;vertical-align:top;padding:.45em .65em;
border-bottom:1px solid var(--edge)}thead th{background:#edf4f0}
table{border-collapse:collapse;width:100%;font-variant-numeric:tabular-nums}
.scroll{overflow-x:auto}.measure{display:grid;grid-template-columns:7.5em 1fr 6em;
gap:.5em;align-items:center;margin:.23em 0}.measure span:last-child{text-align:right}
meter{width:100%;height:1.1rem;accent-color:var(--sea)}
.guard{color:var(--orange)}.muted,figcaption{color:var(--muted)}
.cells,.captures{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,22rem),1fr));
gap:1rem}.cell{border:1px solid var(--edge);border-radius:.55rem;padding:1rem}
img{max-width:100%;height:auto;border:1px solid var(--edge);border-radius:.45rem}
.shots{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:.8rem}
.visual-pair{margin:.9rem 0;padding:.65rem;border:1px solid var(--edge);border-radius:.55rem}
@media(max-width:42rem){.shots{grid-template-columns:1fr}.measure{grid-template-columns:6em 1fr 5em}}
"""


def load(path: Path) -> dict[str, object]:
    """Read an audit-generated JSON object for the current workflow.

    Args:
        path (Path): Exact retained JSON file under the workflow artifact root.
    Returns:
        dict[str, object]: Parsed JSON keys and values, or an empty result.
    """
    value = json.loads(path.read_text(encoding="utf-8-sig"))
    return value if isinstance(value, dict) else {}


def table(headers: tuple[str, ...], rows: list[tuple[str, ...]]) -> str:
    """Build a horizontally scrollable table with named columns.

    Args:
        headers (tuple[str, ...]): Czech plain-text column labels.
        rows (list[tuple[str, ...]]): HTML-safe cells in the same column order.
    Returns:
        str: One responsive HTML table.
    """
    columns = "".join(f'<th scope="col">{escape(name)}</th>' for name in headers)
    entries = "".join(
        "<tr>" + "".join(f"<td>{cell}</td>" for cell in row) + "</tr>" for row in rows
    )
    return f'<div class="scroll"><table><thead><tr>{columns}</tr></thead><tbody>{entries}</tbody></table></div>'


def is_finite_number(value: object) -> bool:
    """Validate one JSON numeric value without accepting booleans or overflow.

    Args:
        value (object{JSON scalar, array, or string-keyed mapping}): Field read
            from retained evidence before numeric validation.
    Returns:
        bool: Whether the value is a finite integer or floating-point number.
    """
    if not isinstance(value, (int, float)) or isinstance(value, bool):
        return False
    try:
        return math.isfinite(float(value))
    except OverflowError:
        return False


def keep(path: Path, output: Path, name: str) -> str:
    """Retain one raw diagnostic inside the downloadable report artifact.

    Args:
        path (Path): JSON or CSV from an actual hosted child.
        output (Path): Report artifact root.
        name (str): Fixed evidence basename containing the workload identity.
    Returns:
        str: A relative sibling URL or an empty string when the input is missing.
    """
    if not path.is_file():
        return ""
    relative = "raw/" + name
    shutil.copyfile(path, output / relative)
    return f'<a href="{relative}">{path.suffix.lstrip(".").upper()}</a>'


def render_deep_photos(raw: dict[str, object], source: Path, output: Path) -> str:
    """Display the independent Canvas and Qt navigation checks for the full catalogue.

    Args:
        raw (dict[str, object]): Exact 2,000-photo benchmark report object.
        source (Path): Directory containing its additional deep probe CSV.
        output (Path): Standalone report directory for the retained CSV.
    Returns:
        str: Czech HTML table or an explicit notice of missing deep evidence.
    """
    deep = raw.get("deep_navigation", {})
    if deep.get("status") != "PASS":
        return "<h3>Hluboký posuv</h3><p>Samostatné měření chybí nebo neprošlo.</p>"
    targets = {"beyond_shared_range": "Za společným rozsahem", "near_end": "U konce katalogu"}
    names = dict(RENDERERS)
    rows = []
    for trial in deep["runs"]:
        for jump in trial["deep_navigation"]:
            frame = jump.get("rendered_frame_proxy_ms", jump.get("rendered_idle_proxy_ms", 0))
            rows.append((
                names[trial["renderer"]], targets[jump["label"]], str(jump["target_first_index"]),
                f'{jump["actual_first_id"]}–{jump["actual_last_id"]}',
                str(jump["visible_photo_count"]), f'{jump["data_ready_ms"]:.2f}',
                f'{jump["resource_published_ms"]:.2f}', f"{frame:.2f}",
                f'{trial["peak_rss_bytes"] / BYTES_PER_MIB:.1f}',
            ))
    return (
        "<h3>Hluboký posuv mimo párové srovnání</h3>"
        "<p>Dva čerstvé procesy ověřily přesná ID za hranicí prvních 1 000 snímků "
        "a u konce katalogu. Každý cíl navštívily poprvé. Jde o ověření funkce "
        "a jednotlivé časy, bez odhadu opakovatelného rozdílu mezi renderery.</p>"
        + table(("Renderer", "Cíl", "Index", "Viditelná ID", "Počet", "Data (ms)",
                 "Publikace (ms)", "Vykreslovací krok (ms)", "Peak RSS (MiB)"), rows)
        + f'<p>{keep(source / "deep-navigation.csv", output, "photos-deep-navigation.csv")}</p>'
    )


def publication_throughput_rows(
    raw: dict[str, object], caption: str,
) -> tuple[list[tuple[str, ...]], list[tuple[str, ...]], str, bool]:
    """Format valid publication-rate evidence and identify absent or invalid fields.

    Args:
        raw (dict[str, object]): Exact retained Photos benchmark JSON object.
        caption (str): Workload size and cache regime shown in the report.
    Returns:
        tuple[list[tuple[str, ...]], list[tuple[str, ...]], str, bool]: Renderer
            rows, paired rows, notice, and validity of the complete record.
    """
    if "publication_throughput" not in raw:
        return [], [], f"{caption}: pole publication_throughput v historickém artefaktu chybí.", False
    evidence = raw["publication_throughput"]
    if not isinstance(evidence, dict):
        return [], [], f"{caption}: publication_throughput má neplatný typ.", False
    renderer_summaries = evidence.get("renderers")
    paired_summaries = evidence.get("paired")
    units = evidence.get("units")
    scope = evidence.get("scope")
    if (not isinstance(renderer_summaries, dict)
            or not isinstance(paired_summaries, dict)
            or not isinstance(units, str) or not units
            or not isinstance(scope, str) or not scope):
        return [], [], f"{caption}: publication_throughput má neplatnou strukturu.", False

    runs = raw.get("runs")
    if not isinstance(runs, list):
        return [], [], f"{caption}: pole runs chybí nebo má neplatný typ.", False
    scale = raw.get("count")
    if not isinstance(scale, int) or isinstance(scale, bool) or scale < 0:
        return [], [], f"{caption}: pole count chybí nebo má neplatnou hodnotu.", False

    def distribution(value: object) -> tuple[float, float, float, float, float] | None:
        """Read the required finite distribution fields, retaining numeric zero.

        Args:
            value (object{p50: float, min: float, p95: float, p99: float, max: float}):
                One renderer or paired distribution before validation.
        Returns:
            tuple[float, float, float, float, float] | None: Median, minimum,
                p95, p99, and maximum, or None when a field is invalid.
        """
        if not isinstance(value, dict):
            return None
        fields = ("p50", "min", "p95", "p99", "max")
        values = tuple(value.get(field) for field in fields)
        if not all(is_finite_number(item) for item in values):
            return None
        normalized = (
            float(values[0]), float(values[1]), float(values[2]),
            float(values[3]), float(values[4]),
        )
        if not (normalized[1] <= normalized[0] <= normalized[2]
                <= normalized[3] <= normalized[4]):
            return None
        return normalized

    rate_rows: list[tuple[str, ...]] = []
    notices: list[str] = []
    for key, title in RENDERERS:
        stats = distribution(renderer_summaries.get(key))
        if stats is not None and stats[1] < 0:
            stats = None
        renderer_runs = [
            run for run in runs
            if isinstance(run, dict) and run.get("renderer") == key
        ]
        published_total = 0
        sample_total = 0
        run_data_valid = bool(renderer_runs)
        for run in renderer_runs:
            throughput = run.get("publication_throughput")
            if not isinstance(throughput, dict):
                run_data_valid = False
                break
            published_count = throughput.get("published_count")
            samples = throughput.get("samples")
            total_ms = throughput.get("total_ms")
            rate = throughput.get("photos_per_second")
            if (
                not isinstance(published_count, int)
                or isinstance(published_count, bool) or published_count < 0
                or not isinstance(samples, list)
                or not is_finite_number(total_ms)
                or total_ms < 0
                or not is_finite_number(rate) or rate < 0
            ):
                run_data_valid = False
                break
            for sample in samples:
                if not isinstance(sample, dict):
                    run_data_valid = False
                    break
                target_index = sample.get("target_first_index")
                published_ids = sample.get("published_ids")
                first_available_ms = sample.get("first_available_ms")
                elapsed_ms = sample.get("elapsed_ms")
                if (
                    not isinstance(target_index, int) or isinstance(target_index, bool)
                    or target_index < 0 or not isinstance(published_ids, list)
                    or any(not isinstance(identity, int) or isinstance(identity, bool)
                           for identity in published_ids)
                    or not isinstance(first_available_ms, list)
                    or len(first_available_ms) != len(published_ids)
                    or any(not is_finite_number(duration) or duration < 0
                           for duration in first_available_ms)
                    or not is_finite_number(elapsed_ms) or elapsed_ms < 0
                ):
                    run_data_valid = False
                    break
            if not run_data_valid:
                break
            published_total += published_count
            sample_total += len(samples)
        if not run_data_valid:
            notices.append(
                f"{caption}, {title}: pole publication_throughput v bězích "
                "chybí nebo je neplatné."
            )
        scale_text = (
            f"{scale} fot. / {published_total} zveřejněno"
            if run_data_valid else f"{scale} fot. / běhová data chybí či jsou neplatná"
        )
        sample_text = (
            f"{len(renderer_runs)} běhů / {sample_total} dílčích vzorků"
            if run_data_valid else "—"
        )
        if stats is None:
            notices.append(f"{caption}, {title}: renderer summary chybí nebo je neplatné.")
            rate_rows.append((caption, title, scale_text, sample_text, "—", "—"))
        else:
            p50, minimum, p95, p99, maximum = stats
            safe_units = escape(units)
            rate_rows.append((
                caption, title, scale_text, sample_text,
                f"{p50:.1f} {safe_units}",
                f"{minimum:.1f} / {p95:.1f} / {p99:.1f} / "
                f"{maximum:.1f} {safe_units}",
            ))

    paired_rows: list[tuple[str, ...]] = []
    for key, title in RENDERERS[:-1]:
        pair = paired_summaries.get(key)
        delta = distribution(pair.get("percent_delta")) if isinstance(pair, dict) else None
        interval = pair.get("confidence_95_percent") if isinstance(pair, dict) else None
        if (delta is None or not isinstance(interval, list) or len(interval) != 2
                or any(
                    not is_finite_number(item)
                    for item in interval
                ) or interval[0] > interval[1]):
            notices.append(
                f"{caption}, Qt proti {title}: párová změna nebo 95% interval "
                "chybí či je neplatný."
            )
            paired_rows.append((caption, f"Qt proti {title}", "—", "—", "Neprůkazné"))
            continue
        p50 = delta[0]
        low, high = float(interval[0]), float(interval[1])
        if high < -PUBLICATION_THROUGHPUT_GUARD_PERCENT:
            verdict = "Regrese nad 10 %"
        elif low > PUBLICATION_THROUGHPUT_GUARD_PERCENT:
            verdict = "Praktický zisk nad 10 %"
        elif (low >= -PUBLICATION_THROUGHPUT_GUARD_PERCENT
                and high <= PUBLICATION_THROUGHPUT_GUARD_PERCENT):
            verdict = "V toleranci ±10 %"
        else:
            verdict = "Neprůkazné"
        paired_rows.append((
            caption, f"Qt proti {title}", f"{p50:+.1f} %",
            f"{low:+.1f} až {high:+.1f} %", verdict,
        ))
    notice = "<br>".join(escape(line) for line in notices)
    scope_text = f"Rozsah: {escape(scope)}. Jednotky: {escape(units)}."
    return rate_rows, paired_rows, " ".join(filter(None, (scope_text, notice))), not notices


def render_import_throughput(
    inputs: Path, output: Path, candidate: str,
) -> tuple[str, list[str]]:
    """Render paired synthetic Import throughput from the warm 2,000-photo cell.

    Args:
        inputs (Path): Attached hosted benchmark artifact directory.
        output (Path): Destination for report and standalone raw evidence copies.
        candidate (str): Exact prepared Qt candidate source SHA.
    Returns:
        tuple[str, list[str]]: Czech section HTML and validation problems for an
            explicitly required final performance report.
    """
    artifact = inputs / f"issue-445-photos-2000-warm-{candidate}"
    report_path = next(artifact.glob("**/issue-445/report.json"), None)
    json_path = next(artifact.glob("**/import-throughput.json"), None)
    csv_path = next(artifact.glob("**/import-throughput.csv"), None)
    links = " · ".join(filter(None, (
        keep(json_path, output, "import-throughput.json") if json_path else "",
        keep(csv_path, output, "import-throughput.csv") if csv_path else "",
    )))
    problems: list[str] = []
    if json_path is None:
        problems.append("Warm 2 000 Photos: standalone import-throughput.json chybí.")
    if csv_path is None:
        problems.append("Warm 2 000 Photos: standalone import-throughput.csv chybí.")
    if report_path is None:
        problems.append("Warm 2 000 Photos: raw report.json chybí.")
        return (
            "<section><h2>Import · syntetická propustnost</h2>"
            f"<p>{'<br>'.join(escape(item) for item in problems)}</p>"
            f"<p>{links}</p></section>",
            problems,
        )

    try:
        raw = load(report_path)
    except (OSError, UnicodeDecodeError, json.JSONDecodeError):
        problems.append("Warm 2 000 Photos: report.json je nečitelný nebo neplatný.")
        return (
            "<section><h2>Import · syntetická propustnost</h2>"
            f"<p>{'<br>'.join(escape(item) for item in problems)}</p>"
            f"<p>{links}</p></section>",
            problems,
        )
    if raw.get("candidate_sha") != candidate or raw.get("baseline_sha") != BASELINE:
        problems.append("Warm 2 000 Photos: zdrojový SHA nebo Tk baseline nesouhlasí.")
    if raw.get("count") != 2000 or raw.get("cache") != "warm":
        problems.append("Import throughput nepochází z očekávané warm 2 000 Photos buňky.")
    if raw.get("status") != "PASS":
        problems.append("Warm 2 000 Photos běh neprošel; Import throughput nelze kvalifikovat.")

    throughput = raw.get("import_throughput")
    rows: list[tuple[str, ...]] = []
    paired_rows: list[tuple[str, ...]] = []
    item_count_text = "—"
    group_count_text = "—"
    if not isinstance(throughput, dict):
        problems.append("Pole import_throughput v warm 2 000 Photos artefaktu chybí nebo je neplatné.")
        status_text = "Chybí nebo neplatné"
    else:
        status = throughput.get("status")
        status_text = escape(str(status if status is not None else "chybí status"))
        if status != "PASS":
            problems.append(f"Import throughput: status {status_text}; očekává se PASS.")
        if (throughput.get("candidate_sha") != candidate
                or throughput.get("baseline_sha") != BASELINE):
            problems.append("Import throughput: candidate/baseline SHA chybí nebo nesouhlasí.")
        item_count = throughput.get("item_count")
        group_count = throughput.get("group_count")
        if type(item_count) is not int or item_count != PERFORMANCE_IMPORT_ITEMS:
            problems.append("Import throughput: item_count chybí nebo je neplatné.")
        else:
            item_count_text = str(item_count)
        if type(group_count) is not int or group_count != PERFORMANCE_PAIRED_GROUPS:
            problems.append(
                f"Import throughput: group_count není přesně {PERFORMANCE_PAIRED_GROUPS}.",
            )
        else:
            group_count_text = str(group_count)
        samples = throughput.get("samples")
        if not isinstance(samples, list) or len(samples) != PERFORMANCE_PAIRED_GROUPS:
            problems.append("Import throughput: chybí úplné párové vzorky.")

        renderers = throughput.get("renderers")
        if not isinstance(renderers, dict):
            problems.append("Import throughput: renderer souhrny chybí nebo jsou neplatné.")
            renderers = {}
        for key, title in (("tkinter", "Tkinter"), ("pyside6", "PySide6")):
            summary = renderers.get(key)
            fields = ("p50", "min", "p95", "p99", "max")
            values = (
                tuple(summary.get(field) for field in fields)
                if isinstance(summary, dict) else ()
            )
            if (len(values) != len(fields) or any(
                not is_finite_number(value) or value < 0 for value in values
            )):
                problems.append(f"Import throughput: souhrn {title} chybí nebo je neplatný.")
                rows.append((title, "—", "—", "—", "—", "—"))
                continue
            numeric = tuple(float(value) for value in values)
            if not (numeric[1] <= numeric[0] <= numeric[2]
                    <= numeric[3] <= numeric[4]):
                problems.append(f"Import throughput: souhrn {title} má neplatné pořadí percentilů.")
                rows.append((title, "—", "—", "—", "—", "—"))
                continue
            rows.append((
                title, f"{numeric[0]:.1f}", f"{numeric[1]:.1f}",
                f"{numeric[2]:.1f}", f"{numeric[3]:.1f}", f"{numeric[4]:.1f}",
            ))

        pair = throughput.get("paired")
        delta = pair.get("percent_delta") if isinstance(pair, dict) else None
        interval = pair.get("confidence_95_percent") if isinstance(pair, dict) else None
        delta_fields = ("p50", "min", "p95", "p99", "max")
        delta_values = (
            tuple(delta.get(field) for field in delta_fields)
            if isinstance(delta, dict) else ()
        )
        delta_summary = None
        if len(delta_values) == len(delta_fields) and all(
            is_finite_number(value) for value in delta_values
        ):
            candidate_delta = tuple(float(value) for value in delta_values)
            if (candidate_delta[1] <= candidate_delta[0] <= candidate_delta[2]
                    <= candidate_delta[3] <= candidate_delta[4]):
                delta_summary = candidate_delta
        elif is_finite_number(delta):
            delta_summary = (float(delta),)
        interval_valid = (
            isinstance(interval, list) and len(interval) == 2
            and all(is_finite_number(value) for value in interval)
            and interval[0] <= interval[1]
        )
        if delta_summary is None or not interval_valid:
            problems.append("Import throughput: paired delta nebo 95% interval chybí či je neplatný.")
            paired_rows.append(("—", "—", "Neprůkazné"))
        else:
            low, high = float(interval[0]), float(interval[1])
            if high < -IMPORT_THROUGHPUT_LOSS_GUARD_PERCENT:
                verdict = "Regrese nad 10% ztráty"
            elif low > IMPORT_THROUGHPUT_LOSS_GUARD_PERCENT:
                verdict = "Praktický zisk nad 10 %"
            elif low >= -IMPORT_THROUGHPUT_LOSS_GUARD_PERCENT:
                verdict = "V toleranci 10% ztráty"
            else:
                verdict = "Neprůkazné"
            paired_rows.append((
                f"{delta_summary[0]:+.1f} %",
                f"{low:+.1f} až {high:+.1f} %", verdict,
            ))

    valid = not problems and status_text == "PASS"
    displayed_status = (
        "PASS" if valid else "Neúplné" if status_text == "PASS" else status_text
    )
    detail = "<br>".join(escape(problem) for problem in problems)
    return (
        "<section><h2>Import · syntetická propustnost</h2>"
        f"<p>Výsledek: {displayed_status}. Měřítko: {item_count_text} položek, "
        f"{group_count_text} párových skupin. Jde o syntetické import položky za sekundu "
        "v tomto lokálním testovacím workloadu; není to síťový přenos ani rychlost "
        "zpracování skutečných uživatelských souborů. Měření vychází z warm buňky "
        f"Photos s 2 000 položkami. Raw evidence: {links or 'samostatné soubory chybí'}.</p>"
        + (f"<p>{detail}</p>" if detail else "")
        + table(("Renderer", "p50 items/s", "Min", "p95", "p99", "Max"), rows)
        + "<p>Rozhodovací mez pro ztrátu propustnosti je 10 % dle scope #434; "
        "tato Import metrika je oddělená od Photos thumbnail publication throughput.</p>"
        + table(("Průměrné Δ Qt vůči Tk", "95% CI průměru Δ", "Vyhodnocení"), paired_rows)
        + "</section>",
        problems,
    )


def render_event_loop_drift(raw: dict[str, object]) -> tuple[str, bool]:
    """Summarize native Qt timer lateness separately from queue and paint events.

    Args:
        raw (dict[str, object]): Exact Qt shell interaction report object.
    Returns:
        tuple[str, bool]: Czech HTML subsection and whether all drift fields are valid.
    """
    timings = raw.get("timings_ms")
    resources = raw.get("resources")
    notices: list[str] = []
    drift: list[float] = []
    if not isinstance(timings, dict) or "event_loop_tick_drift" not in timings:
        notices.append("Historický artefakt neobsahuje event_loop_tick_drift.")
    else:
        values = timings["event_loop_tick_drift"]
        if (not isinstance(values, list) or not values or any(
            not is_finite_number(value) or value < 0 for value in values
        )):
            notices.append("Pole event_loop_tick_drift je prázdné nebo neplatné.")
        else:
            drift = [float(value) for value in values]

    heartbeat: object = None
    coalesced: object = None
    if isinstance(resources, dict):
        heartbeat = resources.get("event_loop_heartbeat_ms")
        coalesced = resources.get("event_loop_coalesced_ticks")
    if not is_finite_number(heartbeat) or heartbeat <= 0:
        notices.append("event_loop_heartbeat_ms chybí nebo je neplatné.")
        heartbeat_text = "—"
    else:
        heartbeat_text = f"{float(heartbeat):.1f}"
    if not isinstance(coalesced, int) or isinstance(coalesced, bool) or coalesced < 0:
        notices.append("event_loop_coalesced_ticks chybí nebo je neplatné.")
        coalesced_text = "—"
    else:
        coalesced_text = str(coalesced)

    drift_text = "—"
    sample_count = "—"
    if drift:
        ordered = sorted(drift)
        sample_count = str(len(ordered))
        p95 = quantiles(ordered, n=100, method="inclusive")[94] if len(ordered) > 1 else ordered[0]
        p99 = quantiles(ordered, n=100, method="inclusive")[98] if len(ordered) > 1 else ordered[0]
        drift_text = " / ".join(f"{value:.2f}" for value in (
            median(ordered), p95, p99, ordered[-1],
        ))
    row = ((sample_count, drift_text, heartbeat_text, coalesced_text),)
    details = "<br>".join(escape(notice) for notice in notices)
    valid = not notices
    return (
        "<h4>Nativní Qt event loop během aktivního soaku</h4>"
        + (f"<p>{details}</p>" if details else "")
        + table(("Počet callbacků", "p50 / p95 / p99 / max drift (ms)",
                 "Nominální perioda (ms)", "Sloučené nominální periody"), list(row))
        + "<p>Drift popisuje zpoždění nativního Qt timer callbacku vůči jeho plánu. "
        "Sloučené periody počítají nominální intervaly, které callback přeskočil; "
        "nejde o queue-to-signal Import/Watcher latenci ani paint/scene eventy.</p>",
        valid,
    )


def paired_performance_problems(inputs: Path, candidate: str) -> list[str]:
    """Require the complete exact-SHA throughput and native-soak evidence set.

    Args:
        inputs (Path): Attached hosted evidence artifact directory.
        candidate (str): Exact candidate SHA named by the report job.
    Returns:
        list[str]: Missing, stale, failed, or invalid mandatory evidence details.
    """
    problems: list[str] = []
    for count in PHOTO_COUNTS:
        for cache in ("cold", "warm"):
            label = f"Photos {count} {cache}"
            artifact = inputs / f"issue-445-photos-{count}-{cache}-{candidate}"
            path = next(artifact.glob("**/issue-445/report.json"), None)
            if path is None:
                problems.append(f"{label}: report.json chybí.")
                continue
            try:
                raw = load(path)
            except (OSError, UnicodeDecodeError, json.JSONDecodeError):
                problems.append(f"{label}: report.json je nečitelný nebo neplatný.")
                continue
            if (raw.get("candidate_sha") != candidate or raw.get("baseline_sha") != BASELINE
                    or raw.get("count") != count or raw.get("cache") != cache):
                problems.append(f"{label}: evidence nesouhlasí s candidate/baseline/scale.")
            if raw.get("status") != "PASS":
                problems.append(f"{label}: status není PASS.")
            if raw.get("group_count") != PERFORMANCE_PAIRED_GROUPS:
                problems.append(f"{label}: group_count není {PERFORMANCE_PAIRED_GROUPS}.")
            runs = raw.get("runs")
            if not isinstance(runs, list):
                problems.append(f"{label}: pole runs chybí nebo je neplatné.")
            else:
                for renderer, _title in RENDERERS:
                    renderer_runs = [
                        run for run in runs
                        if isinstance(run, dict) and run.get("renderer") == renderer
                    ]
                    if len(renderer_runs) != PERFORMANCE_PAIRED_GROUPS:
                        problems.append(
                            f"{label}: {renderer} nemá {PERFORMANCE_PAIRED_GROUPS} běhů."
                        )
            _rates, _pairs, _notice, throughput_valid = publication_throughput_rows(
                raw, label,
            )
            if not throughput_valid:
                problems.append(f"{label}: publication_throughput záznam není úplný a validní.")

    shell_artifact = inputs / f"issue-445-qt-interactions-shell-{candidate}"
    shell_path = next(shell_artifact.glob("**/issue-445-qt-shell/report.json"), None)
    if shell_path is None:
        problems.append("Qt shell: report pro event loop drift chybí.")
    else:
        try:
            shell = load(shell_path)
        except (OSError, UnicodeDecodeError, json.JSONDecodeError):
            problems.append("Qt shell: report.json je nečitelný nebo neplatný.")
            return problems
        if shell.get("source_sha") != candidate or shell.get("status") != "PASS":
            problems.append("Qt shell: status nebo source SHA není PASS/current candidate.")
        _drift_html, drift_valid = render_event_loop_drift(shell)
        if not drift_valid:
            problems.append("Qt shell: event-loop tick drift/heartbeat/coalescing data chybí či jsou neplatná.")
    return problems


def render_photos(inputs: Path, output: Path, candidate: str) -> str:
    """Show all paired cells using the actual embedded-viewport measurements.

    Args:
        inputs (Path): Attached benchmark artifacts of the current workflow.
        output (Path): Destination for the report and raw samples.
        candidate (str): Exact prepared candidate Git SHA.
    Returns:
        str: Charts, resource table and measured regression decisions in Czech.
    """
    cells = []
    deltas = []
    resources = []
    timing_rows = []
    workload_rows = []
    throughput_rate_rows = []
    throughput_pair_rows = []
    throughput_notices = []
    guards = []
    devices = []
    deep = "<h3>Hluboký posuv</h3><p>Samostatné měření zatím chybí.</p>"
    for count in PHOTO_COUNTS:
        for cache in ("cold", "warm"):
            name = f"{count}-{cache}"
            artifact = inputs / f"issue-445-photos-{name}-{candidate}"
            path = next(artifact.glob("**/issue-445/report.json"), None)
            caption = f"{count:,} fotografií · {cache}".replace(",", " ")
            if path is None:
                cells.append(f'<div class="cell"><h3>{caption}</h3><p>Chybí měření.</p></div>')
                throughput_notices.append(f"{caption}: Photos artefakt chybí.")
                continue
            try:
                raw = load(path)
            except (OSError, UnicodeDecodeError, json.JSONDecodeError):
                cells.append(
                    f'<div class="cell"><h3>{caption}</h3>'
                    '<p>Nečitelný nebo neplatný report.json.</p></div>'
                )
                throughput_notices.append(f"{caption}: report.json je nečitelný nebo neplatný.")
                continue
            if raw.get("candidate_sha") != candidate or raw.get("baseline_sha") != BASELINE:
                cells.append(
                    f'<div class="cell"><h3>{caption}</h3>'
                    '<p>Stale nebo cizí paired benchmark evidence.</p></div>'
                )
                throughput_notices.append(
                    f"{caption}: candidate/baseline SHA neodpovídá reportu."
                )
                continue
            raw_links = " · ".join(filter(None, (
                keep(path, output, f"photos-{name}.json"),
                keep(path.with_name("samples.csv"), output, f"photos-{name}.csv"),
            )))
            if raw.get("status") != "PASS":
                detail = escape(str(raw.get("summary", "")))
                cells.append(f'<div class="cell"><h3>{caption}</h3><p>FAIL · {detail}</p>{raw_links}</div>')
                throughput_notices.append(
                    f"{caption}: neúspěšný Photos běh neposkytuje kvalifikovanou throughput statistiku."
                )
                continue
            summary, runs = raw["summary_metrics"], raw["runs"]
            throughput_rates, throughput_pairs, throughput_notice, _throughput_valid = (
                publication_throughput_rows(raw, caption)
            )
            throughput_rate_rows.extend(throughput_rates)
            throughput_pair_rows.extend(throughput_pairs)
            if throughput_notice:
                throughput_notices.append(throughput_notice)
            host = raw["host"]
            workload_rows.append((
                caption, escape(host["platform"]), escape(host["cpu_model"]),
                str(host["logical_cores"]), f'{host["memory_total_bytes"] / BYTES_PER_MIB:.0f}',
                " × ".join(map(str, raw["expected_viewport_footprint"])),
                str(len(runs[0]["initial_visible_ids"])),
                ", ".join(map(str, runs[0]["viewport_target_first_indices"])),
                f'<code>{escape(runs[0]["dataset_sha256"])}</code>',
            ))
            if raw.get("deep_navigation", {}).get("runs"):
                deep = render_deep_photos(raw, path.parent, output)
            values = {key: float(summary[key]["p50"]) for key, _title in RENDERERS}
            axis = max(values.values())
            bars = []
            memory = {}
            for key, title in RENDERERS:
                value = values[key]
                bars.append(
                    f'<div class="measure"><span>{title}</span>'
                    f'<meter aria-label="{title}" max="{axis}" value="{value}"></meter>'
                    f'<span>{value:.1f} ms</span></div>'
                )
                app = [run for run in runs if run["renderer"] == key]
                initial_metrics = (
                    "initial_data_ready_ms", "initial_resource_published_ms",
                    "initial_rendered_frame_proxy_ms",
                )
                startup = median(sum(run[phase] for phase in initial_metrics) for run in app)
                catalogue = summary.get(f"{key}-catalogue_ready_ms", {}).get("p50")
                initial = " / ".join(f'{summary[f"{key}-{phase}"]["p50"]:.2f}' for phase in initial_metrics)
                jump_ms = " / ".join(
                    f"{median(samples):.1f}" for samples in zip(*(run["viewport_settle_ms"] for run in app))
                )
                worst_ms = " / ".join(f'{summary[key][marker]:.1f}' for marker in ("p50", "p95", "p99", "max"))
                phases = " / ".join(f"{median(value for run in app for value in run[phase]):.2f}" for phase in (
                    "viewport_data_ready_ms", "viewport_resource_published_ms", "viewport_rendered_frame_proxy_ms",
                ))
                timing_rows.append((caption, title, "—" if catalogue is None else f"{catalogue:.1f}",
                                    initial, jump_ms, worst_ms, phases))
                cpu = median(run["cpu_ms"] for run in app)
                sampled = [run["peak_rss_bytes"] / BYTES_PER_MIB for run in app]
                memory[key] = median(sampled)
                resources.append((caption, title, f"{startup:.1f}", f"{cpu:.1f}",
                                  f"{memory[key]:.1f} / {max(sampled):.1f}"))
            for key, title in RENDERERS[:-1]:
                change = values["qt"] - values[key]
                relative = change / values[key] * 100 if values[key] else 0.0
                low, high = summary[f"qt-minus-{key}"]["confidence_95_ms"]
                guard = max(LATENCY_GUARD[0], LATENCY_GUARD[1] * values[key])
                if low > guard:
                    verdict = "prokázaná regrese"
                    guards.append(f"{caption}, Qt proti {title} ({low:+.1f} až {high:+.1f} ms)")
                elif high < -guard:
                    verdict = "praktické zrychlení"
                elif high <= guard:
                    verdict = "v toleranci"
                else:
                    verdict = "interval protíná mez"
                memory_delta = memory["qt"] - memory[key]
                memory_margin = max(MEMORY_GUARD[0], MEMORY_GUARD[1] * memory[key])
                deltas.append((
                    caption, title, f"{change:+.1f}", f"{relative:+.1f}",
                    f"{low:+.1f} až {high:+.1f}", f"{guard:.1f}", verdict,
                    f"{memory_delta:+.1f} / {memory_margin:.1f}",
                ))
            cells.append(f'<div class="cell"><h3>{caption}</h3>{"".join(bars)}<p>{raw_links}</p></div>')
            driver_log = next(path.parent.glob("group-*-qt.stderr.txt"), None)
            if driver_log is not None:
                for line in driver_log.read_text(encoding="utf-8", errors="replace").splitlines():
                    if re.search(r"Adapter|WARP|Direct3D|Basic Render Driver|Driver version", line, re.I):
                        text = escape(line.strip())
                        if text not in devices:
                            devices.append(text)
    if guards:
        regression = '<p class="guard">Nad praktickou mezí vychází ' + " · ".join(guards) + ".</p>"
    elif deltas:
        regression = "<p>U platných buněk žádný celý interval neleží nad mezí regrese.</p>"
    else:
        regression = "<p>Pro vyhodnocení meze regrese chybí platné párové měření.</p>"
    device_proof = "<br>".join(devices)
    return (
        '<section><h2>Photos a načtení scény</h2>'
        '<p>Grafy zobrazují medián nejpomalejšího skoku v každém procesu. '
        'Dvanáct skupin používá každé pořadí rendererů dvakrát. Warm měření má '
        'oddělený zácvik shodných čtyř skoků. Cold používá první průchod po načtení '
        'úvodní scény v čistém aplikačním profilu. Stav systémové cache zůstává '
        'mimo řízení. P95 a p99 z dvanácti skupin slouží jako popis vzorku.</p>'
        '<p>Scénář s 2 000 snímky načítá celý katalog. Pro společný posuv používá '
        'cíle v prvních 1 000 snímcích. Při původním pokusu na runneru vrátil '
        'Tk Widgets u hlubšího cíle jinou řadu než požadovanou.</p>'
        f'<div class="cells">{"".join(cells)}</div>'
        '<h3>Metodika a data</h3><p>Hostované Windows zpracovávají deterministické '
        'PNG náhledy ze syntetického transportu. Každá trojice porovnává stejné '
        'pozice a přesná ID. První viewport je součástí ověření. ID a kontrolní '
        'součty jsou v JSON.</p>'
        + table(("Scénář", "OS", "CPU", "Logická CPU", "RAM (MiB)", "Viewport",
                 "Viditelné snímky", "Indexy skoků", "SHA-256 dat"), workload_rows)
        + '<h3>Qt proti Tk</h3><p>Změna vychází z mediánů a interval z párových '
        'rozdílů průměrů. Mez vychází z #434. Změna RAM uvádí rozdíl mediánů '
        'vzorkované špičky a příslušnou praktickou mez.</p>'
        + table(("Scénář", "Kontrola", "Δ ms", "Δ %", "95% CI (ms)", "Mez (ms)",
                 "Vyhodnocení", "Δ RAM / mez (MiB)"), deltas)
        + regression
        + '<h3>Latence po fázích</h3><p>Katalog měří příchod všech položek. Data zahrnují '
        'čekání na dekódované viditelné náhledy včetně plánování a cache. Publikace '
        'měří další čekání na zpřístupnění prostředku. Qt sleduje signál po '
        'vykreslení scény a Tk dokončení idle kroku. Fyzická prezentace leží mimo tato měření.</p>'
        + table(("Scénář", "Renderer", "Katalog (ms)", "Úvodní data / publikace / krok (ms)",
                 "Čtyři skoky p50 (ms)", "Nejpomalejší p50 / p95 / p99 / max (ms)",
                 "Skoky, data / publikace / krok p50 (ms)"), timing_rows)
        + '<h3>První viewport, CPU a špičková RAM</h3><p>První viewport sčítá fáze v každém '
        'procesu a ukazuje jejich medián. RSS se vzorkuje a může minout krátkou špičku.</p>'
        + table(("Scénář", "Renderer", "První scéna (ms)", "CPU (ms)", "Peak RSS p50 / max (MiB)"), resources)
        + '<h3>Rychlost zpřístupnění prostředků</h3>'
        + '<p>Počet zveřejněných prostředků za sekundu měří publikační tok v rendereru. '
        'Nejde o síťový přenos, rychlost dekódování ani FPS; fyzické vykreslování GPU '
        'tato měření neurčují. Rozsah a jednotky přebírá tabulka z raw JSON. Chybějící '
        'historická pole zůstávají označena jako chybějící, ne jako nulová.</p>'
        + ("<p>" + "<br>".join(dict.fromkeys(throughput_notices)) + "</p>"
           if throughput_notices else "")
        + table(("Scénář", "Renderer", "Rozměr / zveřejnění", "Běhy / dílčí vzorky",
                 "p50", "Min / p95 / p99 / max"), throughput_rate_rows)
        + '<h4>Párové Qt vůči Tk</h4><p>Δ % = Qt/Tk − 1; kladná hodnota značí '
        'vyšší publikační rychlost Qt. Tabulka uvádí medián párových změn a 95% '
        'bootstrap interval pro jejich průměr. Rozhodnutí používá celý interval a mez ±10 %.</p>'
        + table(("Scénář", "Pár", "Medián Δ", "95% CI", "Vyhodnocení"), throughput_pair_rows)
        + deep
        + (f'<h3>Qt nahlášené API a adapter text</h3><p><code>{device_proof}</code></p>'
           if devices else "")
        + "</section>"
    )


def render_interactions(inputs: Path, output: Path, candidate: str) -> str:
    """Describe native interaction, Quick host and Watcher workload observations.

    Args:
        inputs (Path): Attached workflow artifacts.
        output (Path): Report and raw evidence destination.
        candidate (str): Expected source SHA for the interaction child.
    Returns:
        str: Representative Qt workload results with their actual scope.
    """
    names = {
        "startup_to_qquick_after_rendering_proxy": "Start Qt do dokončení scény",
        "workspace_switch_action_to_qt_state": "Přepnutí workspace",
        "watcher_queue_to_signal": "Watcher od fronty k signálu",
        "import_queue_to_signal": "Import od fronty k signálu",
        "grid_wheel_to_content_y_ms": "Posun kolečkem",
        "grid_drag_to_content_y_ms": "Tažení galerie",
        "grid_scrollbar_jump_to_content_y_ms": "Skok pomocí posuvníku",
        "lightbox_detail_data_ready_ms": "Lightbox · připravená data",
        "lightbox_resource_published_ms": "Lightbox · publikace prostředků",
        "lightbox_rendered_frame_proxy_ms": "Lightbox · dokončení scény",
        "lightbox_zoom_key_to_zoom_state_ms": "Lightbox · zoom",
        "lightbox_pan_drag_to_content_state_ms": "Lightbox · tažení snímku",
        "lightbox_resize_to_scene_frame_proxy_ms": "Lightbox · změna velikosti",
        "lightbox_fullscreen_action_to_qt_state_ms": "Lightbox · fullscreen",
    }
    sections = []
    for key, folder, label in (
        ("shell", "issue-445-qt-shell", "Workspace, Watcher a Import"),
        ("photos", "issue-445-qt-photos-interactions", "Ovládání Photos a Lightboxu"),
    ):
        artifact = inputs / f"issue-445-qt-interactions-{key}-{candidate}"
        path = next(artifact.glob(f"**/{folder}/report.json"), None)
        heading = f"<h3>{label}</h3>"
        if path is None:
            sections.append(heading + "<p>Chybí měření.</p>")
            continue
        sources = " · ".join(filter(None, (
            keep(path, output, f"qt-interactions-{key}.json"),
            keep(path.with_suffix(".csv"), output, f"qt-interactions-{key}.csv"),
        )))
        try:
            raw = load(path)
        except (OSError, UnicodeDecodeError, json.JSONDecodeError):
            sections.append(heading + f"<p>Nečitelný nebo neplatný report.json.</p><p>{sources}</p>")
            continue
        if raw.get("source_sha") != candidate:
            sections.append(heading + f"<p>Stale nebo cizí interaction evidence.</p><p>{sources}</p>")
            continue
        if raw.get("status") != "PASS":
            detail = escape(str(raw.get("failure", "")))
            sections.append(heading + f"<p>FAIL · {detail}</p><p>{sources}</p>")
            continue
        rows = []
        for name, value in raw.get("timings_ms", {}).items():
            if name == "event_loop_tick_drift":
                continue
            if isinstance(value, (float, int)):
                rows.append((names.get(name, escape(name)), f"{value:.1f}"))
            elif isinstance(value, list) and value and isinstance(value[0], (float, int)):
                rows.append((names.get(name, escape(name)), f"{median(value):.1f}"))
        usage = raw["resources"]
        peak = usage["rss_peak_sampled_bytes"] / BYTES_PER_MIB
        soak = ""
        if key == "shell":
            elapsed = usage["event_soak_elapsed_seconds"]
            soak = f"Běh se syntetickými událostmi trval {elapsed:.1f} s. "
            cpu_ms = usage.get("event_soak_cpu_ms")
            if is_finite_number(cpu_ms) and elapsed > 0:
                # Convert the retained process CPU milliseconds to seconds.
                cpu_seconds = cpu_ms / 1000
                soak += (f"Proces spotřeboval {cpu_seconds:.1f} s CPU "
                         f"({cpu_seconds / elapsed:.1%} jednoho logického jádra). ")
        idle = []
        quiet_labels = {
            "photos-visible-idle": "Photos bez interakce", "photos-inactive": "Neaktivní Photos",
            "window-minimized": "Minimalizované okno", "hidden-to-tray": "Skryté v tray",
        }
        for observation in raw.get("quiet_shell", []):
            name = quiet_labels[observation["state"]]
            if observation["status"] == "PASS":
                restored = observation.get("restore_to_visible_ms")
                idle.append((
                    name, f'{observation["wall_seconds"]:.2f}',
                    f'{observation["process_tree_cpu_ms"]:.2f}',
                    f'{observation["cpu_percent_of_one_logical_core"]:.2f}',
                    str(observation["visible_scene_frame_events"]),
                    "—" if restored is None else f"{restored:.2f}",
                ))
            else:
                idle.append((name, escape(observation["status"]), "—", "—", "—", "—"))
        idle_section = ""
        if idle:
            idle_section = (
                "<h4>Viditelné okno, pozadí a obnovení</h4>"
                + table(("Stav", "Délka (s)", "CPU (ms)", "% jednoho logického jádra",
                         "Qt scéna · události", "Návrat k oknu (ms)"), idle)
                + "<p>Izolovaný zdrojový proces měří nativní event loop bez dalších procesů. "
                "Viditelnost po návratu potvrzuje stav okna. Přenos screenshotů, výkon "
                "fyzického GPU a souběh se simulátorem mají samostatný rozsah.</p>"
            )
        active = raw.get("active_watcher", {})
        active_section = ""
        if active.get("status") == "PASS":
            fixture = active["fixture"]
            readback = active["durable_readback"]
            ui = active["ui"]
            timing = active["timings"]
            rss = active["resources"]["rss_peak_sampled_bytes"] / BYTES_PER_MIB
            active_section = (
                "<h4>Aktivní Watcher s lokálním testovacím transportem</h4>"
                + table(("Vstupy", "Potvrzené položky", "Čeká ve frontě", "Čas (s)",
                         "CPU (ms)", "Peak RSS (MiB)", "Události vykreslení widgetů"), [(
                            str(fixture["screenshots_admitted"]), str(readback["acknowledged_count"]),
                            str(readback["pending_count"]), f'{timing["wall_seconds"]:.2f}',
                            f'{timing["process_cpu_ms"]:.1f}', f'{rss:.1f}',
                            str(ui["watcher_widget_paint_events"]),
                         )])
                + "<p>Produkční Watcher zpracoval syntetické screenshoty v pořadí přijetí. "
                "Inventář i přenos dodal lokální testovací transport. Běh sleduje "
                "události GUI a spotřebu procesu na runneru.</p>"
            )
        host = raw.get("quick_host_comparison", {})
        host_section = ""
        if host.get("status") == "PASS":
            timing_rows = []
            samples = host["samples"]
            for role, label in (("embedded", "QQuickWidget v okně"), ("window", "Samostatné QQuickView")):
                observed = samples[0][role]["graphics_api"]
                timing_rows.append((label, escape(observed), f'{host["median_worst_jump_ms"][role]:.2f}'))
            host_section = (
                "<h4>Stejná scéna ve dvou nativních Quick hostech</h4>"
                + table(("Quick host", "Grafické API", "Medián nejpomalejšího skoku (ms)"), timing_rows)
                + "<p>Oba procesy vykreslily totožný výřez a stejné položky. Čas končí "
                "pozorovanou událostí dokončení scény. Použití threaded render loopu "
                "a fyzický výstup nebyly měřeny.</p>"
            )
        event_loop_section = (
            render_event_loop_drift(raw)[0] if key == "shell" else ""
        )
        sections.append(
            heading + table(("Měření", "Medián nebo jednotlivý čas (ms)"), rows)
            + f"<p>{soak}Peak RSS {peak:.1f} MiB.</p>"
            + event_loop_section + idle_section + active_section + host_section
            + f"<p>{sources}</p>"
        )
    return (
        "<section><h2>Ovládání a dlouhý běh</h2>" + "".join(sections)
        + '<p>Watcher a Import používají syntetické události ve skutečných Qt '
        'frontách. Tyto údaje nemají párový protějšek Tk. Starší Tk výsledky '
        'poskytuje <a href="https://github.com/klusik/PHP_gallery/issues/434">issue #434</a>.'
        '</p></section>'
    )


def render_installers(inputs: Path, output: Path, candidate: str) -> str:
    """Report authentic setup sizes, verified startup and update results.

    Args:
        inputs (Path): Downloaded compact package and update artifacts.
        output (Path): Report and raw evidence destination.
        candidate (str): Exact SHA named by the source-controlled workflow.
    Returns:
        str: Installer facts, or explicit missing/failed scenarios.
    """
    setups = []
    switches = []
    for key, title in SETUPS:
        root = inputs / f"issue-445-{key}-package-evidence-{candidate}"
        metadata = next(root.glob("**/winapp-update.json"), None)
        if metadata is None:
            setups.append((title, "Chybí", "—", "—", "—", "—"))
            continue
        asset = load(metadata)["assets"][0]
        log_root = inputs / f"issue-445-installed-ui-{candidate}" if key == "candidate" else root
        log = next(log_root.glob("**/winapp-frozen-installer.log"), None)
        trace = log.read_text(encoding="utf-8", errors="replace") if log is not None else ""
        tk_start = re.search(r"Installed Tk visible window and normal close verified in ([0-9.]+)s", trace)
        qt_start = re.search(r"Installed Qt first scene and normal close verified in ([0-9.]+)s", trace)
        setups.append((
            title, escape(asset["version"]), f'{asset["size"]:,}'.replace(",", " "),
            tk_start.group(1) if tk_start is not None else "—",
            qt_start.group(1) if qt_start is not None else "—",
            keep(metadata, output, f"setup-{key}.json"),
        ))
        switches.extend((title, backend, seconds) for backend, seconds in re.findall(
            r"Installed Settings switched to (pyside6|tkinter) in ([0-9.]+)s", trace,
        ))
    root = inputs / f"issue-445-installed-updates-{candidate}"
    report = next(root.glob("**/winapp-update-qualification/report.json"), None)
    if report is None:
        updates = "<p>Chybí výsledek průchodu updaterem.</p>"
    else:
        evidence = load(report)
        if evidence.get("source_sha") != candidate:
            evidence = {"scenarios": [{"name": "Zdroj auditu", "status": "BLOCKED",
                                       "detail": "Výsledek nepatří k tomuto kandidátovi."}]}
        details = [(escape(row["name"]), escape(row["status"]), escape(row["detail"]))
                   for row in evidence["scenarios"]]
        updates = table(("Scénář upgradu", "Výsledek", "Důkaz"), details)
        staging = [(escape(row["scenario"]), str(row["duration_ms"]), str(row["runtime_bytes"]),
                    str(row["temporary_bytes"]), str(row.get("peak_observed_update_bytes", "—")),
                    str(row.get("setup_wait_ms", "—")),
                    escape(row.get('usage_sample_status', '—')),
                    escape(row.get('setup_preflight', '—')))
                   for row in evidence.get("helper_staging", [])]
        if staging:
            updates += table(("Kopie helperu", "Příprava (ms)", "Runtime (B)", "Se setupem (B)",
                              "Vzorkovaná špička (B)", "Čekání na setup (ms)",
                              "Rozsah vzorkování", "Kontrola setupu"), staging)
            updates += (
                "<p>Špička odhaduje součet logických velikostí souborů v dočasné složce, "
                "update relaci a instalaci. Čas setupu běží od povolení handoffu po zápis "
                "koncového markeru helperu. Krátké špičky a zápisy systému mimo fixture "
                "zůstávají mimo měření.</p>"
            )
        waiting = []
        for row in evidence.get('helper_staging', []):
            if not row.get('watcher_idle_status'):
                continue
            measured = row['watcher_idle_status'] == 'measured'
            waiting.append((
                escape(row['scenario']), escape(row.get('resumed_backend', '—')),
                str(row.get('watcher_idle_processes', '—')),
                str(row.get('watcher_idle_wall_ms', '—')),
                str(row.get('watcher_idle_cpu_ms', '—')),
                f"{row['watcher_idle_rss_before_bytes'] / BYTES_PER_MIB:.1f} → "
                f"{row['watcher_idle_rss_after_bytes'] / BYTES_PER_MIB:.1f}" if measured else '—',
                'Změřeno' if measured else 'Nedostupné',
            ))
        if waiting:
            updates += table((
                'Navázaný Watcher', 'Skutečné rozhraní', 'Procesy', 'Interval (ms)',
                'CPU (ms)', 'Pracovní sada (MiB)', 'Stav',
            ), waiting)
            updates += (
                '<p>Watcher po navázání běží v nainstalované aplikaci v prázdné testovací složce. '
                'Součet zahrnuje ověřené procesy instalačního EXE a jeho startup supervisor, pokud ještě běží. '
                'Počítá se skutečný procesorový čas od Windows a pracovní sada na začátku a konci intervalu.</p>'
            )
        updates += f'<p>{keep(report, output, "installed-updates.json")}</p>'
    return (
        "<section><h2>Instalátory a upgrady</h2>"
        + table(("Sestavení", "Verze", "Bajty", "Tk okno (s)", "Qt scéna (s)", "Metadata"), setups)
        + table(("Přepnutí v Settings", "Cílový backend", "Čas do připravenosti (s)"), switches)
        + updates + "</section>"
    )


def render_packaging(inputs: Path, output: Path, candidate: str) -> str:
    """Compare native startup and size observations of the three real layouts.

    Args:
        inputs (Path): Compact GitHub Actions artifact root for the current run.
        output (Path): Standalone report and sibling raw evidence destination.
        candidate (str): Exact source checked out by the hosted workflow.
    Returns:
        str: Czech tables and links to recorded values, or the failure status.
    """
    root = inputs / f"issue-445-packaging-metrics-{candidate}"
    path = next(root.glob("**/winapp-packaging-metrics/report.json"), None)
    heading = "<section><h2>Tři instalační layouty</h2>"
    if path is None:
        return heading + "<p>Chybí výsledky společného měření.</p></section>"
    recorded = load(path)
    evidence = " · ".join(link for link in (
        keep(path, output, "packaging-layouts.json"),
        keep(path.with_suffix(".csv"), output, "packaging-samples.csv"),
    ) if link)
    if recorded.get("source_sha") != candidate or recorded.get("status") != "PASS":
        failure = escape(str(recorded.get("failure", "Měření neprokázalo úplný výsledek.")))
        return heading + f"<p>{failure} {evidence}</p></section>"
    packages = []
    startups = []
    labels = {"tk": "Tkinter", "qt": "PySide6"}
    for layout in recorded["layouts"]:
        name, setup_key = LAYOUTS[layout["layout"]]
        files = layout["root"]
        separate = layout.get("separate_qt_executable", {})
        measurements = layout["setup_install"]
        runtime = layout.get("python_runtimes", {})
        packages.append((
            name, str(recorded[setup_key]["size_bytes"]), str(files["public_executable_bytes"]),
            str(separate.get("size_bytes", "—")), str(files["tree_bytes"]),
            str(files["file_count"]), str(files["dll_count"]), str(files["dll_bytes"]),
            str(runtime.get("total", "—")),
            f'{measurements["operation_lifecycle_seconds"]:.2f}',
        ))
        for run in layout["frontend_startups"]:
            startups.append((
                name, labels[run["backend"]], LAUNCH_PHASES[run["launch_phase"]],
                f'{run["observer_readiness_seconds"]:.2f}',
                f'{run["child_process_rss_peak_sampled_bytes"] / BYTES_PER_MIB:.1f}',
                str(run["child_process_write_bytes_peak_sampled"]),
                str(run["workspace_bytes_peak_sampled"]),
            ))
    migration = recorded.get("historical_dual_upgrade")
    migrated = ""
    if isinstance(migration, dict):
        migrated = (
            f'<p>Přechod z dřívější dvojité instalace trval {migration["install_seconds"]:.2f} s. '
            f'Tk okno bylo připravené za {migration["tk_ready_seconds"]:.2f} s a Qt za '
            f'{migration["qt_ready_seconds"]:.2f} s. Staré runtime složky byly odstraněny.</p>'
        )
    return (
        heading + table(("Layout", "Setup (B)", "Hlavní EXE (B)", "Samostatné Qt EXE (B)",
                         "Strom (B)", "Soubory", "DLL", "DLL (B)", "Python runtime", "Instalace (s)"), packages)
        + "<p>Tabulka porovnává skutečné instalační EXE a rozbalený strom. Vydaný onefile "
        "při startu rozbaluje runtime. Historický setup přidal vlastní Qt runtime. "
        "Společný onedir používá jednu kopii sdíleného prostředí a ponechává Qt DLL vyměnitelné.</p>"
        + "<h3>Start nainstalovaného rozhraní</h3>"
        + table(("Layout", "Backend", "Spuštění", "Připravenost (s)", "Špička RSS (MiB)",
                 "Zápisy procesů (B)", "Dočasné soubory (B)"), startups)
        + "<p>Všechna tři porovnání běžela na jednom Windows runneru. Každé spuštění "
        "mělo samostatný profil a stav systémové cache nebyl řízený. RSS je vzorkovaný "
        "součet pracovních sad živých procesů. Zápisy zahrnují veškeré zápisy pozorovaných "
        "procesů. Přepnutí v Settings měří zvláštní průchod instalátorem.</p>"
        + migrated
        + f"<p>{evidence}</p></section>"
    )


def render_backends(
    inputs: Path, output: Path, candidate: str,
    require_fresh_macos_visual: bool,
) -> str:
    """Display platform audit outcomes and unaltered hosted Settings captures.

    Args:
        inputs (Path): Current workflow desktop artifacts.
        output (Path): Report and raw evidence destination.
        candidate (str): Exact expected workflow source SHA.
        require_fresh_macos_visual (bool): Whether this report requires the
            separate fresh-host macOS visual artifact.
    Returns:
        str: Source test table and embedded hosted images.
    """
    configurations = []
    screenshots = []
    for key, title in CONFIGURATIONS:
        root = inputs / f"issue-445-{key}-{candidate}"
        path = next(root.glob("**/winapp-partitioned-report.json"), None)
        audit_sources = (
            ("winapp-ordinary-audit.json", "Regrese"),
            ("winapp-visual-audit.json", "Vizuální testy"),
        )
        split_path_rows = []
        for filename, label in audit_sources:
            report_path = next(root.glob(f"**/{filename}"), None)
            if key == "macOS-pyside6" and filename == "winapp-visual-audit.json":
                fresh_root = inputs / f"issue-445-macOS-visual-{candidate}"
                fresh_path = next(fresh_root.glob(f"**/{filename}"), None)
                if fresh_path is not None:
                    report_path = fresh_path
                elif require_fresh_macos_visual:
                    report_path = None
            split_path_rows.append((report_path, label, filename))
        split_paths = tuple(split_path_rows)
        if any(split_path is not None for split_path, _, _ in split_paths):
            summaries = []
            raw = []
            for split_path, label, filename in split_paths:
                if split_path is None:
                    summaries.append(f"{label} · chybí")
                    continue
                audit = load(split_path)
                source = audit.get("source")
                exact = isinstance(source, dict) and source.get("head_sha") == candidate
                tasks = audit.get("tasks")
                task = tasks[0] if isinstance(tasks, list) and len(tasks) == 1 else {}
                counts = task.get("counts", {}) if isinstance(task, dict) else {}
                status = (
                    escape(str(audit.get("status", "Chybí"))) if exact
                    else "Jiná revize"
                )
                summaries.append(
                    f"{label} · {status} · {int(counts.get('passed', 0))} prošlo / "
                    f"{int(counts.get('skipped', 0))} přeskočeno"
                )
                if exact:
                    raw.append(keep(split_path, output, f"{key}-{filename}"))
            configurations.append((title, "<br>".join(summaries), " · ".join(raw) or "—"))
        elif path is None:
            configurations.append((title, "Chybí", "—"))
        else:
            result = load(path)
            tests = result.get("tests", [])
            status = "PASS" if result.get("successful") else "FAIL"
            configurations.append((
                title, f"{status} · {len(tests)} testů", keep(path, output, f"winapp-{key}.json"),
            ))
        for size, label in (("desktop", "desktop"), ("narrow", "úzké okno")):
            capture = next(root.glob(f"**/settings-{size}.png"), None)
            if capture is None:
                continue
            image = b64encode(capture.read_bytes()).decode("ascii")
            alt = escape(f"{title} · {label}")
            screenshots.append(f'<figure><img src="data:image/png;base64,{image}" alt="{alt}"><figcaption>{alt}</figcaption></figure>')
    return (
        "<section><h2>Testovací konfigurace</h2>"
        + table(("Prostředí", "Centrální WinApp sada", "Výsledky podle testu"), configurations)
        + f'<div class="shots">{"".join(screenshots)}</div></section>'
    )


def _case_id_list(value: list[list[str]] | None) -> list[tuple[str, str]] | None:
    """Normalize a JSON case-identity list while rejecting malformed or duplicate IDs.

    Args:
        value (list[list[str]] | None): Retained JSON array of two-string case
            IDs, or None when the field is absent or invalid.
    Returns:
        list[tuple[str, str]] | None: Ordered unique identities, or None if malformed.
    """
    if not isinstance(value, list):
        return None
    identities: list[tuple[str, str]] = []
    for item in value:
        if (not isinstance(item, list) or len(item) != 2
                or not all(isinstance(part, str) and part for part in item)):
            return None
        identities.append((item[0], item[1]))
    if len(identities) != len(set(identities)):
        return None
    return identities


def verify_visual_slice_manifest(
    aggregate_path: Path,
    aggregate: dict[str, object],
    manifest_path: Path,
    candidate: str,
) -> tuple[list[str], list[Path]]:
    """Verify the visual slice manifest and aggregate form one exact evidence set.

    Args:
        aggregate_path (Path): Backward-compatible paired capture aggregate.
        aggregate (dict[str, object]): Parsed aggregate JSON object.
        manifest_path (Path): Exact sibling manifest named by the aggregate.
        candidate (str): Expected source revision for every slice.
    Returns:
        tuple[list[str], list[Path]]: Specific verification problems and verified
            per-slice report paths available for retention.
    """
    issues: list[str] = []
    slice_reports: list[Path] = []
    try:
        manifest_bytes = manifest_path.read_bytes()
        manifest = json.loads(manifest_bytes.decode("utf-8-sig"))
    except (OSError, UnicodeDecodeError, json.JSONDecodeError):
        return ["manifest JSON is missing or malformed"], slice_reports
    expected_manifest_hash = aggregate.get("manifest_sha256")
    if (not isinstance(expected_manifest_hash, str)
            or not re.fullmatch(r"[a-f0-9]{64}", expected_manifest_hash)
            or hashlib.sha256(manifest_bytes).hexdigest() != expected_manifest_hash):
        issues.append("manifest hash does not match the aggregate")
    if not isinstance(manifest, dict):
        return [*issues, "manifest root is not an object"], slice_reports
    if manifest.get("schema_version") != 1:
        issues.append("manifest schema is unsupported")
    if manifest.get("status") != "PASS" or aggregate.get("status") != "PASS":
        issues.append("manifest or aggregate status is not PASS")
    expected_slice_ids = manifest.get("expected_slice_ids")
    slices = manifest.get("slices")
    if (expected_slice_ids != list(VISUAL_REPORT_SLICE_IDS)
            or not all(isinstance(item, str) and item for item in expected_slice_ids)
            or len(expected_slice_ids) != len(set(expected_slice_ids))
            or not isinstance(slices, list)
            or [item.get("slice_id") for item in slices if isinstance(item, dict)]
            != expected_slice_ids):
        return [*issues, "manifest slice inventory is incomplete or unordered"], slice_reports

    manifest_expected = _case_id_list(manifest.get("expected_case_ids"))
    manifest_completed = _case_id_list(manifest.get("completed_case_ids"))
    if (manifest_expected is None or manifest_completed is None
            or manifest_expected != manifest_completed):
        issues.append("manifest case inventory is incomplete or malformed")
    seen_cases: list[tuple[str, str]] = []
    slice_pairs: list[object] = []
    seed_hashes: set[str] = set()
    for entry in slices:
        if not isinstance(entry, dict):
            issues.append("slice manifest entry is malformed")
            continue
        slice_id = entry.get("slice_id")
        filename = entry.get("file")
        digest = entry.get("sha256")
        if (not isinstance(slice_id, str) or not isinstance(filename, str)
                or Path(filename).name != filename
                or filename != f"paired-captures-{slice_id}.json"
                or not isinstance(digest, str)
                or not re.fullmatch(r"[a-f0-9]{64}", digest)):
            issues.append(f"slice {slice_id!r} has an unsafe name or invalid hash")
            continue
        slice_path = aggregate_path.parent / filename
        if (slice_path.resolve().parent != aggregate_path.resolve().parent
                or not slice_path.is_file()):
            issues.append(f"slice {slice_id} file is missing")
            continue
        try:
            slice_bytes = slice_path.read_bytes()
            record = json.loads(slice_bytes.decode("utf-8-sig"))
        except (OSError, UnicodeDecodeError, json.JSONDecodeError):
            issues.append(f"slice {slice_id} JSON is malformed")
            continue
        slice_reports.append(slice_path)
        if hashlib.sha256(slice_bytes).hexdigest() != digest:
            issues.append(f"slice {slice_id} hash does not match")
        if not isinstance(record, dict):
            issues.append(f"slice {slice_id} root is not an object")
            continue
        if (record.get("schema_version") != 1
                or record.get("slice_id") != slice_id
                or record.get("source_identity") != candidate
                or record.get("status") != "PASS"
                or entry.get("status") != record.get("status")):
            issues.append(f"slice {slice_id} status or source identity is invalid")
            for failure in record.get("failures", []):
                if isinstance(failure, str) and failure:
                    issues.append(f"slice {slice_id}: {failure}")
        seed_hash = record.get("seed_profile_sha256")
        if (not isinstance(seed_hash, str)
                or not re.fullmatch(r"[a-f0-9]{64}", seed_hash)
                or entry.get("seed_profile_sha256") != seed_hash):
            issues.append(f"slice {slice_id} profile fingerprint is invalid")
        else:
            seed_hashes.add(seed_hash)
        if seed_hash != aggregate.get("seed_profile_sha256"):
            issues.append(f"slice {slice_id} uses a different seed profile")
        for backend in ("tk", "qt"):
            report_name = record.get(f"{backend}_report")
            if not isinstance(report_name, str) or not report_name:
                issues.append(f"slice {slice_id} {backend} child report is not named")
                continue
            report_path = aggregate_path.parent / report_name
            resolved_report = report_path.resolve()
            resolved_root = aggregate_path.parent.resolve()
            if (Path(report_name).is_absolute()
                    or re.match(r"^(?:[A-Za-z]:[\\/]|[/\\]{2})", report_name)
                    or not resolved_report.is_relative_to(resolved_root)
                    or report_path.suffix.lower() != ".json"
                    or not report_path.is_file()):
                issues.append(f"slice {slice_id} {backend} child report is unavailable")
            else:
                slice_reports.append(report_path)
        expected = _case_id_list(record.get("expected_case_ids"))
        completed = _case_id_list(record.get("completed_case_ids"))
        entry_expected = _case_id_list(entry.get("expected_case_ids"))
        entry_completed = _case_id_list(entry.get("completed_case_ids"))
        if (expected is None or completed is None or expected != completed
                or entry_expected != expected or entry_completed != completed):
            issues.append(f"slice {slice_id} case inventory is incomplete")
        pairs = record.get("pairs")
        if not isinstance(pairs, list):
            issues.append(f"slice {slice_id} pair records are missing")
            continue
        pair_ids = _case_id_list([
            [pair.get("size_name"), pair.get("workspace")]
            for pair in pairs if isinstance(pair, dict)
        ])
        if (pair_ids is None or len(pair_ids) != len(pairs)
                or expected is None or pair_ids != expected):
            issues.append(f"slice {slice_id} pair records do not match its case IDs")
        seen_cases.extend(pair_ids or [])
        slice_pairs.extend(pairs)
    if len(seed_hashes) != 1:
        issues.append("slice profile fingerprints do not form one shared profile")
    canonical_cases = sorted([
        (size, workspace)
        for size in VISUAL_REPORT_SIZES
        for workspace in VISUAL_REPORT_WORKSPACES
    ] + [("standard", workspace) for workspace in VISUAL_REPORT_SCENARIOS])
    if manifest_expected != canonical_cases:
        issues.append("manifest does not declare the full canonical visual case inventory")
    if (manifest_expected is None or sorted(seen_cases) != manifest_expected
            or len(seen_cases) != len(set(seen_cases))):
        issues.append("slices do not cover the exact unique manifest case inventory")
    aggregate_cases = _case_id_list([
        [pair.get("size_name"), pair.get("workspace")]
        for pair in aggregate.get("pairs", []) if isinstance(pair, dict)
    ]) if isinstance(aggregate.get("pairs"), list) else None
    if (aggregate_cases is None or aggregate_cases != seen_cases
            or aggregate.get("pairs") != slice_pairs):
        issues.append("aggregate pairs differ from the ordered verified slices")
    return issues, slice_reports


def render_visual_parity(
    inputs: Path, output: Path, candidate: str,
    require_fresh_macos_visual: bool,
) -> tuple[str, list[str]]:
    """Pair native Tk and Qt evidence from the same hosted runner and profile.

    Args:
        inputs (Path): Downloaded feature/prepared-candidate source artifacts.
        output (Path): Standalone report directory receiving evidence links.
        candidate (str): Exact SHA whose frontend implementations were captured.
        require_fresh_macos_visual (bool): Whether to require isolated fresh-host
            macOS captures from the current exact candidate.
    Returns:
        tuple[str, list[str]]: Czech visual evidence and missing required evidence.
    """
    headings = {
        "photos": "Galerie", "watcher": "Watcher", "flight": "Lety ve Watcheru",
        "import": "Import", "activity": "Aktivita", "ai": "AI", "settings": "Nastavení",
        "photos-lightbox": "Lightbox",
        "photos-disconnected": "Galerie · čisté odpojené spuštění",
        "photos-connecting": "Galerie · skutečné fáze připojení a zrušení",
        "photos-ratio-16-9": "Galerie · poměr 16:9",
        "photos-selected": "Galerie · jeden vybraný snímek",
        "photos-multiselected": "Galerie · dva vybrané snímky",
        "photos-linked-empty": "Galerie · prázdná propojená galerie",
        "photos-hierarchy": "Galerie · rozbalená hierarchie",
        "photos-lightbox-fullscreen": "Galerie · celoobrazovkový Lightbox",
        "photos-info": "Galerie · informace o vybraném snímku",
        "photos-context-menu": "Galerie · kontextová karta snímku",
        "photos-info-unavailable": "Galerie · nedostupná metadata",
        "photos-delete-confirmation": "Galerie · potvrzení s bezpečným zrušením",
        "photos-folder-shelf": "Galerie · dva podsložkové náhledy",
        "photos-music-panel": "Galerie · hudební panel",
    }
    problems: list[str] = []
    reports: list[str] = []
    for platform_name in ("Windows", "Linux", "macOS"):
        base = inputs / f"issue-445-{platform_name}-pyside6-{candidate}"
        path = next(base.glob("**/visual-parity/paired-captures.json"), None)
        if platform_name == "macOS":
            fresh_base = inputs / f"issue-445-macOS-visual-{candidate}"
            fresh_path = next(
                fresh_base.glob("**/visual-parity/paired-captures.json"), None,
            )
            if fresh_path is not None:
                base = fresh_base
                path = fresh_path
            elif require_fresh_macos_visual:
                problems.append("macOS · chybí čerstvé vizuální měření na odděleném hostu")
                reports.append(
                    "<h3>macOS</h3><p>Chybí vizuální artefakt z čerstvého hostu.</p>"
                )
                continue
        limited_scope = path is None
        slice_state = "legacy" if limited_scope else "unverified"
        slice_note = "Důkaz neúplný: chybí manifest rozdělených vizuálních testů."
        slice_reports: list[Path] = []
        if path is None:
            localized = next(base.glob("**/visual-parity/czech-paired-captures.json"), None)
            if platform_name != "macOS":
                problems.append(f"{platform_name} · chybí P6 snímky")
            if localized is None:
                reports.append(f"<h3>{platform_name}</h3><p>Chybí dvojice skutečných snímků.</p>")
                continue
            path = localized.with_name('paired-captures.json')
        raw = load(path) if not limited_scope else {}
        if not limited_scope and raw.get('source_identity') != candidate:
            raise ValueError('Visual evidence belongs to a different source revision.')
        if not limited_scope and raw.get('schema_version') != 2:
            problems.append(f"{platform_name} · chybí ověření obsahu snímků")
            continue
        if not limited_scope:
            manifest_name = raw.get("manifest_file")
            manifest_path = path.parent / str(manifest_name)
            if (manifest_name != "paired-captures-manifest.json"
                    or manifest_path.resolve().parent != path.resolve().parent
                    or not manifest_path.is_file()):
                issues = ["manifest vizuálních částí chybí nebo má neplatný název"]
            else:
                issues, slice_reports = verify_visual_slice_manifest(
                    path, raw, manifest_path, candidate,
                )
            if issues:
                problems.extend(f"{platform_name} · {issue}" for issue in issues)
                slice_note = "Důkaz neúplný: " + "; ".join(issues)
            else:
                slice_state = "complete"
                slice_note = "Všechny vizuální části mají ověřený hash a přesnou úplnou množinu případů."
        if (slice_state != "complete"
                and (platform_name != "macOS" or require_fresh_macos_visual)):
            problems.append(f"{platform_name} · vizuální důkaz není úplný")
        display = raw.get('display', {})
        content = []
        rows = []
        platforms = []
        seen: dict[tuple[str, str], dict[str, str]] = {}
        visual_failures: list[str] = []
        for key, name in (("tk", "Tkinter"), ("qt", "PySide6")):
            record = display.get(key, {})
            if 'screen_size' not in record or 'dpi' not in record:
                continue
            pixels = " × ".join(map(str, record["screen_size"]))
            units = 'hlášené px/palec Tk' if key == 'tk' else 'logických bodů/palec Qt'
            platforms.append(f'{name} · {pixels}, {record["dpi"]:.1f} {units}')
        for case in raw.get('pairs', []):
            caption = headings.get(case["workspace"], escape(case["workspace"]))
            tk_size = ' × '.join(map(str, case['tk_observed_client_size']))
            qt_size = ' × '.join(map(str, case['qt_observed_client_size']))
            comparable = case['geometry_comparable']
            size = tk_size if comparable else f'Tk {tk_size} · Qt {qt_size}'
            images = []
            supplemental_pairs: dict[str, list[str]] = {}
            for toolkit, label in (("tk", "Tkinter reference"), ("qt", "PySide6")):
                if case["workspace"] != "flight":
                    fingerprints = seen.setdefault(
                        (toolkit, case["size_name"]), {},
                    )
                    digest = case[f"{toolkit}_image_pixel_sha256"]
                    previous_workspace = next(
                        (
                            workspace for workspace, prior_digest
                            in fingerprints.items() if prior_digest == digest
                        ),
                        None,
                    )
                    if previous_workspace is not None:
                        failure = (
                            f"{toolkit} {case['size_name']} {previous_workspace}/"
                            f"{case['workspace']} repeat decoded pixels"
                        )
                        visual_failures.append(failure)
                        problems.append(f"{platform_name} · {failure}")
                    else:
                        fingerprints[case["workspace"]] = digest
                name = case[toolkit + "_image"]
                image = path.parent / name
                if image.parent != path.parent or image.suffix.lower() != ".png" or not image.is_file():
                    raise ValueError("A visual evidence image is missing or outside the artifact.")
                data_url = b64encode(image.read_bytes()).decode("ascii")
                realized = case[f'{toolkit}_actual_window_state']
                window = {'normal': 'okno', 'maximized': 'maximalizované',
                          'screen-sized-fallback': 'velikost obrazovky'}.get(realized, realized)
                coordinates = tk_size if toolkit == 'tk' else qt_size
                alt = escape(f'{caption} · {label} · {coordinates} · {window}')
                images.append(f'<figure><img loading="lazy" src="data:image/png;base64,{data_url}" '
                              f'alt="{alt}"><figcaption>{alt}</figcaption></figure>')
                for extra in case.get(f'{toolkit}_supplemental_images', []):
                    overlay = path.parent / extra['image']
                    if overlay.parent != path.parent or overlay.suffix.lower() != '.png' or not overlay.is_file():
                        raise ValueError('A visual evidence overlay is missing or outside the artifact.')
                    data_url = b64encode(overlay.read_bytes()).decode('ascii')
                    extra_name = extra.get('name', '')
                    image_label = {
                        'flight-progress-overlay': 'průběh záznamu',
                        'saved-flight-archive': 'archiv uložených letů',
                        'music-panel': 'hudební panel',
                        'fullscreen-active-controls': 'ovládání po pohybu ukazatele',
                    }.get(extra_name, 'doplňující snímek')
                    alt = escape(f'{caption} · {label} · {image_label}')
                    supplemental_pairs.setdefault(extra_name, []).append(
                        f'<figure><img loading="lazy" src="data:image/png;base64,{data_url}" '
                        f'alt="{alt}"><figcaption>{alt}</figcaption></figure>',
                    )
                if case["workspace"] == "watcher" and case["size_name"] == "standard":
                    recovery_name = case.get(f"{toolkit}_recovery_watcher_image", "")
                    recovery_image = path.parent / str(recovery_name)
                    if (not recovery_name or recovery_image.parent != path.parent
                            or recovery_image.suffix.lower() != ".png"
                            or not recovery_image.is_file()):
                        problems.append(
                            f"{platform_name} · chybí původní recovery snímek {toolkit}"
                        )
                    else:
                        data_url = b64encode(recovery_image.read_bytes()).decode("ascii")
                        alt = escape(f"{caption} · {label} · původní recovery stav")
                        supplemental_pairs.setdefault('watcher-recovery', []).append(
                            f'<figure><img loading="lazy" src="data:image/png;base64,{data_url}" '
                            f'alt="{alt}"><figcaption>{alt}</figcaption></figure>'
                        )
            for pair in supplemental_pairs.values():
                images.extend(pair)
            count_tk, count_qt = len(case["tk_actions"]), len(case["qt_actions"])
            measured_tk = ' × '.join(map(str, case['tk_capture_pixel_scale']))
            measured_qt = ' × '.join(map(str, case['qt_capture_pixel_scale']))
            scale = measured_tk if comparable else f'Tk {measured_tk} · Qt {measured_qt}'
            rows.append((caption, escape(case['size_name']), size, scale, str(count_tk), str(count_qt)))
            ratio_captions = case.get("tk_ratio_captions", [])
            ratio_note = (
                f'<p>Poměry karet v pořadí: {escape(" → ".join(ratio_captions))}; '
                f'vybráno Tk {escape(str(case.get("tk_selected_ratio")))} / '
                f'Qt {escape(str(case.get("qt_selected_ratio")))}</p>'
                if ratio_captions else ""
            )
            fullscreen_note = (
                '<p>První dvojice zachycuje celou fotografii po skrytí ovládání. '
                'Druhá dvojice zachycuje stejné okno po pohybu ukazatele '
                'a návratu ovládacích prvků.</p>'
                if 'fullscreen-active-controls' in supplemental_pairs else ''
            )
            tk_grid = case.get("tk_photo_grid")
            qt_grid = case.get("qt_photo_grid")
            grid_note = (
                f'<p>Počet karet v první řadě. Tk {tk_grid["first_row_cards"]}, '
                f'Qt {qt_grid["first_row_cards"]}. Šířka plochy pro karty je '
                f'{tk_grid["viewport_width"]} px v Tk a {qt_grid["viewport_width"]:g} px v Qt.</p>'
                if tk_grid and qt_grid else ""
            )
            heading_note = ""
            if (tk_grid and qt_grid and "heading_stacked" in tk_grid
                    and "heading_stacked" in qt_grid):
                tk_heading = "ve více řádcích" if tk_grid["heading_stacked"] else "v jednom řádku"
                qt_heading = "ve více řádcích" if qt_grid["heading_stacked"] else "v jednom řádku"
                heading_note = f'<p>Hlavička galerie je v Tk {tk_heading} a v Qt {qt_heading}.</p>'
            activity_note = (
                f'<p>Text patičky Aktivity se liší: Tk „{escape(str(case.get("tk_latest_activity_text", "")))}“; '
                f'Qt „{escape(str(case.get("qt_latest_activity_text", "")))}“.</p>'
                if case.get("latest_activity_text_differs") else ""
            )
            activity_state_note = (
                f'<p>Stav Aktivity: souhrn importu Tk „{escape(str(case.get("tk_activity_import_summary", "")))}“ / '
                f'Qt „{escape(str(case.get("qt_activity_import_summary", "")))}“; '
                f'vybraná úloha Tk {escape(str(case.get("tk_activity_selected_job")))} / '
                f'Qt {escape(str(case.get("qt_activity_selected_job")))}; '
                f'řádky historie Tk {escape(str(case.get("tk_activity_history_row_count")))} / '
                f'Qt {escape(str(case.get("qt_activity_history_row_count")))}; '
                f'události Tk {escape(str(case.get("tk_activity_event_row_count")))} / '
                f'Qt {escape(str(case.get("qt_activity_event_row_count")))}.</p>'
                if case.get("workspace") == "activity" else ""
            )
            watcher_state_note = (
                f'<p>Obnova pending Watcher položky z odděleného legacy profilu: Tk '
                f'{escape(str(case.get("tk_watcher_fixture_state")))} '
                f'({escape(", ".join(case.get("tk_watcher_action_captions", [])))}) / '
                f'Qt {escape(str(case.get("qt_watcher_fixture_state")))} '
                f'({escape(", ".join(case.get("qt_watcher_action_captions", [])))}). '
                f'Shodný profil SHA-256: {escape(str(case.get("tk_watcher_probe_profile_sha256", "")))}. '
                f'Continue viditelné/povolené Tk {escape(str(case.get("tk_watcher_continue_visible")))} / '
                f'{escape(str(case.get("tk_watcher_continue_enabled")))}; Qt '
                f'{escape(str(case.get("qt_watcher_continue_visible")))} / '
                f'{escape(str(case.get("qt_watcher_continue_enabled")))}. '
                f'Start/Interrupt aktivní Tk {escape(str(case.get("tk_watcher_start_action_enabled")))} / '
                f'{escape(str(case.get("tk_watcher_interrupt_action_enabled")))}; Qt '
                f'{escape(str(case.get("qt_watcher_start_action_enabled")))} / '
                f'{escape(str(case.get("qt_watcher_interrupt_action_enabled")))}. '
                f'Session Tk {escape(str(case.get("tk_watcher_pending_session_ids", [])))} / '
                f'Qt {escape(str(case.get("qt_watcher_pending_session_ids", [])))}; '
                f'fingerprint Tk {escape(str(case.get("tk_watcher_target_binding_sha256", "")))} / '
                f'Qt {escape(str(case.get("qt_watcher_target_binding_sha256", "")))}; '
                f'item Tk {escape(str(case.get("tk_watcher_pending_item_ids", [])))} / '
                f'Qt {escape(str(case.get("qt_watcher_pending_item_ids", [])))}. '
                f'Nezměněný journal Tk {escape(str(case.get("tk_watcher_queue_unchanged")))} / '
                f'Qt {escape(str(case.get("qt_watcher_queue_unchanged")))}; síťové/upload akce '
                f'Tk „{escape(str(case.get("tk_watcher_probe_actions", "")))}“ / Qt '
                f'„{escape(str(case.get("qt_watcher_probe_actions", "")))}“. '
                f'Původní hlavní profil (samostatný kontext, nikoli tento pending pár): Tk '
                f'{escape(str(case.get("tk_recovery_watcher_fixture_state")))} '
                f'({escape(", ".join(case.get("tk_recovery_watcher_action_captions", [])))}) / '
                f'Qt {escape(str(case.get("qt_recovery_watcher_fixture_state")))} '
                f'({escape(", ".join(case.get("qt_recovery_watcher_action_captions", [])))}); '
                f'load error Tk „{escape(str(case.get("tk_recovery_watcher_load_error", "")))}“, '
                f'lease Tk {escape(str(case.get("tk_recovery_watcher_lease_owner_state", "")))}. '
                f'Rozdíl původních vlastníků: '
                f'{escape(str(case.get("watcher_recovery_state_differs", False)))}; '
                'jejich původní snímky zůstávají přiloženy jako samostatný kontext.</p>'
                if case["workspace"] == "watcher" and case["size_name"] == "standard" else ""
            )
            header_link_note = (
                f'<p>Stav odkazu v hlavičce: Tk „{escape(str(case.get("tk_header_link_address", "")))}“ / '
                f'Qt „{escape(str(case.get("qt_header_link_address", "")))}“. '
                'Tento údaj zachycuje skutečný stav vlastníka odkazu; případné rozdíly '
                'nejsou označeny jako shodné.</p>'
                if case.get("header_link_state_differs") else ""
            )
            inspector_note = (
                f'<p>Inspektor snímku (x, y, šířka, výška): Tk '
                f'{escape(str(case.get("tk_photo_info_geometry", [])))} / Qt '
                f'{escape(str(case.get("qt_photo_info_geometry", [])))}. '
                f'Shrnutí Tk: „{escape(str(case.get("tk_photo_info_summary", "")))}“; '
                f'Qt: „{escape(str(case.get("qt_photo_info_summary", "")))}“. '
                f'Text Tk: „{escape(str(case.get("tk_photo_info_text", "")))}“; '
                f'Qt: „{escape(str(case.get("qt_photo_info_text", "")))}“.</p>'
                if case.get("workspace") in (
                    "photos-info", "photos-info-unavailable",
                ) else ""
            )
            music_panel_note = (
                f'<p>Hudba: Tk „{escape(str(case.get("tk_music_panel_text", "")))}“ '
                f'({escape(str(case.get("tk_music_panel_geometry")))}) / '
                f'Qt „{escape(str(case.get("qt_music_panel_text", "")))}“ '
                f'({escape(str(case.get("qt_music_panel_geometry")))}). '
                'Samostatné výřezy popupů jsou přiloženy níže.</p>'
                if case.get("workspace") == "photos-music-panel" else ""
            )
            confirmation_note = (
                f'<p>Potvrzovací text: Tk „{escape(str(case.get("tk_confirmation_text", "")))}“; '
                f'Qt „{escape(str(case.get("qt_confirmation_text", "")))}“. '
                "Obě akce skončily volbou Zrušit a bez zápisu.</p>"
                if case.get("tk_confirmation_cancelled")
                and case.get("qt_confirmation_cancelled") else ""
            )
            connect_card_note = (
                f'<p>Karta připojení (x, y, šířka, výška / pracovní plocha): '
                f'Tk {escape(str(case.get("tk_connect_card_geometry")))} / '
                f'{escape(str(case.get("tk_connect_card_workspace_size")))}; '
                f'Qt {escape(str(case.get("qt_connect_card_geometry")))} / '
                f'{escape(str(case.get("qt_connect_card_workspace_size")))}. '
                "Drobné rozdíly v paddingu jsou nativní pro toolkit.</p>"
                if case.get("workspace") == "photos-disconnected" else ""
            )
            linking_note = (
                f'<p>Hlavní dvojice zachycuje fázi žádosti; Qt ji dále ověřilo stavem '
                f'„{escape(str(case.get("qt_pending_request_status", "")))}“ a následným '
                f'schválením v prohlížeči „{escape(str(case.get("qt_pending_approval_status", "")))}“. '
                f'Bylo použito tlačítko „{escape(str(case.get("qt_cancel_action_caption", "")))}“; '
                f'zrušení potvrzeno: {escape(str(case.get("qt_cancelled_state", False)))} '
                f'(„{escape(str(case.get("qt_cancelled_status", "")))}“). '
                f'Čekání na callback zahájeno/zrušeno: '
                f'{escape(str(case.get("qt_callback_wait_entered", False)))} / '
                f'{escape(str(case.get("qt_callback_wait_cancelled", False)))}. '
                'Doplňující snímek ukazuje schválení. Žádost i URL byly syntetické; '
                'žádný síťový požadavek nebyl odeslán.</p>'
                if case.get("workspace") == "photos-connecting" else ""
            )
            empty_gallery_note = (
                f'<p>Viditelná zpráva v prázdné galerii. Tkinter '
                f'„{escape(str(case.get("tk_empty_gallery_message", "")))}“. PySide6 '
                f'„{escape(str(case.get("qt_empty_gallery_message", "")))}“. '
                'Ověření porovnává text a vyžaduje skrytí formátů karet v obou oknech.</p>'
                if (case.get("workspace") == "photos-linked-empty"
                    and case.get("tk_empty_gallery_message")
                    and case.get("qt_empty_gallery_message")) else ""
            )
            maximize_note = (
                f'<p>Maximizace po předchozí běžné velikosti (px): Tk '
                f'{escape(str(case.get("tk_max_reference_client_size", [])))} → '
                f'{escape(str(case.get("tk_observed_client_size", [])))} '
                f'({escape(str(case.get("tk_actual_window_state")))}); Qt '
                f'{escape(str(case.get("qt_max_reference_client_size", [])))} → '
                f'{escape(str(case.get("qt_observed_client_size", [])))} '
                f'({escape(str(case.get("qt_actual_window_state")))}).</p>'
                if case.get("size_name") == "maximized" else ""
            )
            tk_folder_controls = "; ".join(
                f'{item.get("caption")}: icon {item.get("icon_size")}, '
                f'text {item.get("text_height")} px, '
                f'height {item.get("control_device_height_px")} device px'
                for item in case.get("tk_folder_ratio_layout", [])
            )
            qt_folder_controls = "; ".join(
                f'{item.get("caption")}: icon {item.get("icon_size")}, '
                f'text {item.get("text_height")} px, '
                f'height {item.get("control_device_height_px")} device px'
                for item in case.get("qt_folder_ratio_layout", [])
            )
            folder_shelf_note = (
                f'<p>Podsložky: Tk {escape(str(case.get("tk_folder_cards", [])))}; '
                f'Qt {escape(str(case.get("qt_folder_cards", [])))}. '
                f'Poměr karet Tk {escape(str(case.get("tk_folder_selected_ratio")))} / '
                f'Qt {escape(str(case.get("qt_folder_selected_ratio")))}. '
                f'Pásy formátů (x, y, šířka, výška): Tk '
                f'{escape(str(case.get("tk_folder_strip_geometry", [])))} / Qt '
                f'{escape(str(case.get("qt_folder_strip_geometry", [])))}. '
                f'Ovládací prvky Tk (text, ikona px, text px, výška zařízení px): '
                f'{escape(tk_folder_controls)}. Qt: '
                f'{escape(qt_folder_controls)}.</p>'
                if case.get("workspace") == "photos-folder-shelf" else ""
            )
            content.append(f'<details class="visual-pair"><summary>{caption} · {size}</summary>'
                           f'{ratio_note}{grid_note}{heading_note}{activity_note}'
                           f'{activity_state_note}{watcher_state_note}'
                           f'{music_panel_note}{header_link_note}{inspector_note}'
                           f'{confirmation_note}{connect_card_note}{linking_note}{empty_gallery_note}'
                           f'{folder_shelf_note}{maximize_note}{fullscreen_note}'
                           f'<div class="shots">{"".join(images)}</div></details>')
        czech_path = path.with_name("czech-paired-captures.json")
        czech_evidence = ""
        if not czech_path.is_file():
            problems.append(f"{platform_name} · chybí nativní české snímky")
        else:
            czech = load(czech_path)
            if czech.get("source_identity") != candidate:
                raise ValueError("Czech visual evidence belongs to another source revision.")
            czech_pairs = czech.get("pairs", [])
            required_czech = {
                ("narrow-cs", "photos-disconnected"),
                ("narrow-cs", "settings"),
                ("narrow-cs", "photos-ratio-16-9"),
            }
            observed_czech = {
                (item.get("size_name"), item.get("workspace"))
                for item in czech_pairs
            }
            if czech.get("locale") != "cs" or not required_czech <= observed_czech:
                problems.append(f"{platform_name} · neúplné české stavy")
            if czech.get("status") != "PASS":
                problems.append(f"{platform_name} · české zachycení selhalo")
            for case in czech_pairs:
                caption = headings.get(case["workspace"], escape(case["workspace"]))
                images = []
                for toolkit, label in (("tk", "Tkinter"), ("qt", "PySide6")):
                    name = case.get(toolkit + "_image")
                    image = czech_path.parent / str(name)
                    if (not name or image.parent != czech_path.parent
                            or image.suffix.lower() != ".png" or not image.is_file()):
                        problems.append(
                            f"{platform_name} · chybí český snímek {case.get('workspace')} {toolkit}"
                        )
                        continue
                    data_url = b64encode(image.read_bytes()).decode("ascii")
                    alt = escape(f"{caption} · {label} · čeština · úzké okno")
                    images.append(
                        f'<figure><img loading="lazy" src="data:image/png;base64,{data_url}" '
                        f'alt="{alt}"><figcaption>{alt}</figcaption></figure>'
                    )
                tk_size = " × ".join(map(str, case.get("tk_observed_client_size", [])))
                qt_size = " × ".join(map(str, case.get("qt_observed_client_size", [])))
                size = f"Tk „{tk_size}“ / Qt „{qt_size}“"
                settings_note = (
                    f'<p>Viditelné tlačítko Uložit nastavení: Tk '
                    f'{escape(", ".join(case.get("tk_settings_captions", [])))}; '
                    f'Qt {escape(", ".join(case.get("qt_settings_captions", [])))}.</p>'
                    if case.get("workspace") == "settings" else ""
                )
                connect_note = (
                    f'<p>Viditelné tlačítko připojení: Tk '
                    f'{escape(", ".join(case.get("tk_connect_captions", [])))}; '
                    f'Qt {escape(", ".join(case.get("qt_connect_captions", [])))}.</p>'
                    if case.get("workspace") == "photos-disconnected" else ""
                )
                ratio_note = (
                    f'<p>Viditelné poměry karet: Tk '
                    f'{escape(" → ".join(case.get("tk_ratio_captions", [])))}; Qt '
                    f'{escape(" → ".join(case.get("qt_ratio_captions", [])))}. '
                    f'Vybráno Tk {escape(str(case.get("tk_selected_ratio")))} / '
                    f'Qt {escape(str(case.get("qt_selected_ratio")))}.</p>'
                    if case.get("workspace") == "photos-ratio-16-9" else ""
                )
                content.append(
                    f'<details class="visual-pair"><summary>{caption} · čeština · {escape(size)}</summary>'
                    f'{connect_note}{settings_note}{ratio_note}'
                    f'<div class="shots">{"".join(images)}</div></details>'
                )
                rows.append((f"Čeština · {caption}", "narrow-cs", escape(size), "—", "—", "—"))
            czech_evidence = keep(
                czech_path, output, f"visual-{platform_name}-czech-pairs.json",
            )
        native_feedback = []
        for filename, alt in (
            ("wide-21-9-loading.png", "Qt · šipky u právě načítané dávky 21:9"),
            ("wide-21-9-bottom.png", "Qt · vykreslené náhledy na konci galerie 21:9"),
            ("watcher-force-finish.png", "Qt · Vynutit dokončení v kartě Watcheru se stínem"),
        ):
            image = next(base.glob(f"**/{filename}"), None)
            if image is None:
                continue
            data_url = b64encode(image.read_bytes()).decode("ascii")
            native_feedback.append(
                f'<figure><img loading="lazy" src="data:image/png;base64,{data_url}" '
                f'alt="{alt}"><figcaption>{alt}</figcaption></figure>'
            )
        if native_feedback:
            content.append(
                '<details class="visual-pair"><summary>Doplňující nativní stavy Qt</summary>'
                '<p>Samostatné záběry z nativních funkčních testů se syntetickými daty.</p>'
                f'<div class="shots">{"".join(native_feedback)}</div></details>'
            )
        scale_evidence = ''
        scale_path = path.with_name('synthetic-150-dpi-pair.json')
        if scale_path.is_file():
            scale = load(scale_path)
            if scale.get('source_identity') != candidate:
                raise ValueError('Synthetic scaling evidence belongs to another source revision.')
            if scale.get('status') != 'PASS':
                problems.append(f'{platform_name} · syntetické škálování selhalo')
            scale_images = []
            for toolkit, label in (('tk', 'Tkinter'), ('qt', 'PySide6')):
                name = scale.get('raw_images', {}).get(toolkit)
                picture = scale_path.parent / str(name)
                if (not name or picture.parent != scale_path.parent
                        or picture.suffix.lower() != '.png' or not picture.is_file()):
                    continue
                data_url = b64encode(picture.read_bytes()).decode('ascii')
                alt = escape(f'Galerie · {label} · simulované měřítko')
                scale_images.append(
                    f'<figure><img loading="lazy" src="data:image/png;base64,{data_url}" '
                    f'alt="{alt}"><figcaption>{alt}</figcaption></figure>'
                )
            captions = escape(' → '.join(scale.get('ratio_captions', [])))
            content.append(
                '<details class="visual-pair"><summary>Galerie · simulované měřítko 150 %</summary>'
                '<p>Linux/Xvfb. Měřítko bylo nastaveno samostatně v obou toolkitech. '
                'Fyzický displej nebyl měřen.</p>'
                f'<p>Výsledek {escape(str(scale.get("status", "")))}. Popisky {captions}.</p>'
                f'<div class="shots">{"".join(scale_images)}</div></details>'
            )
            scale_evidence = keep(scale_path, output, f'visual-{platform_name}-synthetic-dpi.json')
        if not limited_scope and (raw.get("status") != "PASS" or not raw['pairs']):
            problems.append(f"{platform_name} · neúplný výsledek P6")
        evidence = " · ".join(filter(None, (
            keep(path, output, f"visual-{platform_name}-pairs.json"),
            keep(manifest_path, output, f"visual-{platform_name}-slice-manifest.json")
            if not limited_scope and manifest_path.is_file() else "",
            keep(path.with_name("tk-capture.json"), output, f"visual-{platform_name}-Tk.json"),
            keep(path.with_name("qt-capture.json"), output, f"visual-{platform_name}-Qt.json"),
            *(
                keep(report, output, f"visual-{platform_name}-slice-evidence-{index:02d}.json")
                for index, report in enumerate(slice_reports, start=1)
            ),
            czech_evidence,
            scale_evidence,
            keep(path.parent / str(raw.get("contact_sheet", "")), output,
                 f"visual-{platform_name}-contact-sheet.png"),
        )))
        coverage = raw.get("scenario_coverage", [])
        gaps = raw.get("coverage_gaps", [])
        coverage_text = " | ".join(
            f'{item["workspace"]}: {item["state"]} ({item["proof"]})'
            for item in coverage
        )
        coverage_html = (
            f'<p>Pokryté stavy: {escape(coverage_text) or "neuvedeno"}</p>'
            f'<p>Neověřené stavy: {escape(", ".join(gaps)) or "žádné"}</p>'
        )
        failure_text = "; ".join([
            *(str(item) for item in raw.get("failures", [])),
            *visual_failures,
        ])
        failure_html = (
            f'<p>Neúplné zachycení: {escape(failure_text)}</p>'
            if failure_text else ""
        )
        reports.append(
            f"<h3>{platform_name}</h3><p>{escape(' | '.join(platforms))}</p>"
            + f'<p class="{"muted" if slice_state == "complete" else "guard"}">{escape(slice_note)}</p>'
            + ('<p>K dispozici jsou samostatné české stavy. Úplná matice chybí.</p>' if limited_scope else '')
            + table(("Panel", "Varianta", "Klient (px)", "Pixely snímku na px klienta", "Akce Tk", "Akce Qt"), rows)
            + f"<p>{evidence}</p>" + coverage_html + failure_html + "".join(content)
        )
    return (
        "<section><h2>P6 · Vizuální a uživatelská shoda</h2>"
        "<p>Páry vznikly v nativních oknech na stejném runneru ze stejného "
        "syntetického profilu. Počty akcí a geometrie slouží k posouzení rozložení. "
        "Test ověřuje vybraný panel a odmítá opakovaný obraz jiných panelů. "
        "Tk a Qt hlásí DPI v odlišných jednotkách. Měřítko dvojic vychází z rozměrů "
        "nativního snímku a klientské plochy. U nativní maximalizace se eviduje "
        "skutečná velikost jednotlivě včetně případného režimu s velikostí obrazovky. "
        "Barvy, typografické role a kresby ikon "
        "pocházejí ze společných zdrojů. Oba toolkity kreslí text a nativní rámy samostatně. "
        "V úzkých oknech se kvůli nativním rozměrům ovládacích prvků může lišit "
        "viditelná část další řady. Další fotografie jsou dostupné posunem mřížky. "
        "Galerie nadále používá virtualizovaný Qt Quick GridView.</p>"
        "<p>Galerie používá levý strom a horní lištu tvarů. Lightbox přebírá klient "
        "a při zavření obnoví panely se zachovaným výběrem. Potvrzení mazání "
        "používá stejnou ukotvenou kartu a při otevření zvýrazní Zrušit. Selhání metadat "
        "a ověřená kontrola fronty Watcheru jsou vidět v Aktivitě. Qt přepíná ořez černých okrajů "
        "a celý snímek ze stejného dekódovaného zdroje. Ořez používá SDR, celý snímek na "
        "podporovaném macOS může použít nativní EDR. Průběh letu má v obou rozhraních "
        "vlastní snímek. Activity používá historii, filtry a redigovaný lokální log. "
        "Dialogy Settings používají sdíleného vlastníka profilu. Úplnost stavů a akcí "
        "se posuzuje společně s funkčními výsledky.</p>"
        + "".join(reports) + "</section>",
        problems,
    )


def render_dependencies(inputs: Path, output: Path, candidate: str) -> tuple[str, list[str]]:
    """Compare exact environment versions retained by builder and desktop jobs.

    Args:
        inputs (Path): Evidence from the current hosted workflow only.
        output (Path): Destination for the linked clean source environment JSON.
        candidate (str): Exact feature or prepared candidate SHA being compared.
    Returns:
        tuple[str, list[str]]: Czech evidence section and mandatory coverage or
            version faults that disqualify prepared candidates.
    """
    problems: list[str] = []
    rows: list[tuple[str, ...]] = []
    builds: dict[str, dict[str, str | None]] = {}
    for key, title in SETUPS:
        root = inputs / f"issue-445-{key}-package-evidence-{candidate}"
        log = next(root.glob("**/winapp-frozen-installer.log"), None)
        if log is None:
            problems.append(f"{title} · chybí log buildu")
            continue
        lines = log.read_text(encoding="utf-8-sig", errors="replace").splitlines()
        records = [line.removeprefix(BUILD_VERSIONS_PREFIX) for line in lines
                   if line.startswith(BUILD_VERSIONS_PREFIX)]
        if len(records) != 1:
            problems.append(f"{title} · chybí jednoznačný záznam verzí")
            continue
        versions = json.loads(records[0])
        builds[key] = versions
        fields = (*CORE_VERSION_FIELDS, *QT_VERSION_FIELDS, *TK_BUILD_VERSION_FIELDS,
                  ("pyinstaller", "PyInstaller"), ("pyinstaller_hooks_contrib", "hooks-contrib"))
        if any(not versions.get(name) for name, _ in fields):
            problems.append(f"{title} · neúplné verze buildu")
        rows.append((title, format_versions(versions, fields), keep(log, output, f"{key}-build.log")))

    reference = builds.get("candidate", {})
    shared = (*CORE_VERSION_FIELDS, *QT_VERSION_FIELDS)
    windows = (*shared, *TK_BUILD_VERSION_FIELDS)
    if builds.get("earlier") and any(builds["earlier"].get(name) != reference.get(name)
                                    for name, _ in (*windows, ("pyinstaller", "PyInstaller"))):
        problems.append("Dřívější onedir a kandidát používají různé verze")
    for key, title in CONFIGURATIONS:
        root = inputs / f"issue-445-{key}-{candidate}"
        path = next(root.glob("**/source-dependencies.json"), None)
        if path is None:
            problems.append(f"{title} · chybí výsledek čistého prostředí")
            continue
        raw = load(path)
        versions = raw["dependency_versions"]
        flags = raw.get("ready_seconds", {})
        full = key.endswith("-pyside6")
        requested = {"tkinter", "pyside6"} if full else {"tkinter"}
        names = windows if key.startswith("Windows-") else shared
        qt_names = {name for name, _ in QT_VERSION_FIELDS}
        compared = [name for name, _ in names if full or name not in qt_names]
        if (set(flags) != requested or not versions.get("tk_patchlevel")
                or any(not versions.get(name) or versions[name] != reference.get(name) for name in compared)
                or (full and raw.get("qt_runtime") != versions.get("pyside6_essentials"))
                or (not full and any(versions.get(name) for name, _ in QT_VERSION_FIELDS))):
            problems.append(f"{title} · neshoda verzí nebo spuštění")
        fields = (*shared, *TK_VERSION_FIELDS)
        if key.startswith("macOS-"):
            fields += MAC_VERSION_FIELDS
            if any(not versions.get(name) for name, _ in MAC_VERSION_FIELDS):
                problems.append(f"{title} · chybí verze PyObjC")
        rows.append((title, format_versions(versions, fields), keep(path, output, f"deps-{key}.json")))

    for count in PHOTO_COUNTS:
        for cache in ("cold", "warm"):
            cell = f"{count}-{cache}"
            root = inputs / f"issue-445-photos-{cell}-{candidate}"
            path = next(root.glob("**/issue-445/report.json"), None)
            if path is None:
                problems.append(f"Photos {cell} · chybí měření")
                continue
            raw = load(path)
            versions = raw.get("dependency_versions", {})
            runs = raw.get("runs", [])
            mismatched = any(
                run.get("dependency_versions", {}).get(name) != reference.get(name)
                for run in runs for name, _ in windows
            )
            if (raw.get("status") != "PASS" or not runs or mismatched
                    or any(not versions.get(name) or versions[name] != reference.get(name)
                           for name, _ in windows)):
                problems.append(f"Photos {cell} · neshoda verzí nebo měření")
            fields = (*windows, ("psutil", "psutil"))
            rows.append((f"Photos {cell}", format_versions(versions, fields),
                         f'<a href="raw/photos-{cell}.json">JSON</a>'))

    if problems:
        description = "<ul>" + "".join(f"<li>{escape(problem)}</li>" for problem in problems) + "</ul>"
    else:
        description = "<p>Verze aplikačních závislostí se shodují v buildu, čistých zdrojových prostředích a všech šesti měřeních Photos.</p>"
    return (
        "<section><h2>Ověřené verze závislostí</h2>" + description
        + table(("Prostředí", "Zjištěné verze", "Záznam"), rows) + "</section>",
        problems,
    )


def format_versions(
    versions: dict[str, str | None], fields: tuple[tuple[str, str], ...],
) -> str:
    """List relevant installed versions without implying optional packages exist.

    Args:
        versions (dict[str, str | None]): Runtime or builder facts by package key.
        fields (tuple[tuple[str, str], ...]): Ordered fact keys and display labels.
    Returns:
        str: HTML-safe package labels and versions in one table cell.
    """
    return " · ".join(escape(f"{label} {versions[key]}") for key, label in fields if versions.get(key))


def main() -> int:
    """Generate one offline HTML report and its companion diagnostic inputs.

    Returns:
        int: Zero for a written report without a mandatory dependency mismatch.
    """
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--inputs", type=Path, required=True)
    parser.add_argument("--output", type=Path, required=True)
    parser.add_argument("--candidate", required=True)
    args = parser.parse_args()
    if os.environ.get("GITHUB_ACTIONS") != "true" or not re.fullmatch(r"[a-f0-9]{40}", args.candidate):
        parser.error("An exact hosted issue 445 candidate is required.")
    candidate = args.candidate
    run_url = escape(
        f'{os.environ["GITHUB_SERVER_URL"]}/{os.environ["GITHUB_REPOSITORY"]}'
        f'/actions/runs/{os.environ["GITHUB_RUN_ID"]}'
    )
    repository = "https://github.com/klusik/PHP_gallery"
    (args.output / "raw").mkdir(parents=True, exist_ok=True)
    require_fresh_macos_visual = (
        os.environ.get("REQUIRE_FRESH_MAC_VISUAL") == "1"
        or os.environ.get("REQUIRE_DEPENDENCIES_MATCH") == "1"
    )
    dependencies, dependency_problems = render_dependencies(args.inputs, args.output, candidate)
    visual, visual_problems = render_visual_parity(
        args.inputs, args.output, candidate, require_fresh_macos_visual,
    )
    import_throughput, import_throughput_problems = render_import_throughput(
        args.inputs, args.output, candidate,
    )
    require_paired_performance = (
        os.environ.get("REQUIRE_PAIRED_PERFORMANCE") == "1"
    )
    performance_problems = []
    performance_section = ""
    if require_paired_performance:
        performance_problems = paired_performance_problems(
            args.inputs, candidate,
        )
        performance_problems.extend(import_throughput_problems)
        gate_status = "FAIL" if performance_problems else "PASS"
        gate_detail = (
            "<p>" + "<br>".join(escape(problem) for problem in performance_problems)
            + "</p>" if performance_problems
            else "<p>Šest Photos buněk, warm Import a nativní Qt soak mají úplnou "
            "validní exact-SHA evidenci.</p>"
        )
        performance_section = (
            f"<section><h2>Párová performance kvalifikace · {gate_status}</h2>"
            + gate_detail + "</section>"
        )
    pages = [
        render_backends(
            args.inputs, args.output, candidate, require_fresh_macos_visual,
        ),
        visual,
        dependencies,
        render_photos(args.inputs, args.output, candidate),
        import_throughput,
        render_interactions(args.inputs, args.output, candidate),
        render_installers(args.inputs, args.output, candidate),
        render_packaging(args.inputs, args.output, candidate),
        performance_section,
    ]
    html = f"""<!doctype html>
<html lang="cs"><head><meta charset="utf-8"><meta name="viewport"
content="width=device-width, initial-scale=1"><title>WinApp · kvalifikace #445</title>
<style>{CSS}</style></head><body><main>
<header><h1>WinApp · kvalifikace PySide6</h1>
<p>Tkinter zůstává výchozí. Volitelný Qt backend používá stejný profil a sdílené workery.</p>
<p>Standardní Windows setup obsahuje jeden aplikační EXE a společný onedir runtime.
Oba backendy používají jediný update asset a stejný restart.</p>
<p>Qt kandidát <a href="{repository}/commit/{candidate}"><code>{candidate}</code></a><br>
Tk baseline <a href="{repository}/commit/{BASELINE}"><code>{BASELINE}</code></a></p>
<p><a href="{run_url}">Joby a artefakty na GitHubu</a> ·
<a href="{repository}/issues/445">Akceptační checklist a evidence</a></p></header>
{''.join(pages)}
<section><h2>Operátorské cesty</h2>
<p>Rozsah zahrnuje Photos a Lightbox, galerijní správu, uložené připojení,
Watcher a FIFO recovery, Import, Flight a SimConnect, Activity, AI, Settings,
diagnostiku, tray a updater. Výsledek každé konkrétní zkoušky je v JSON
příslušné konfigurace. Kompatibilní a výjimečné scénáře popisuje
<a href="{repository}/blob/{candidate}/docs/WINAPP_GUI_BACKENDS.md">dokumentace kandidáta</a>.</p>
</section>
<section><h2>Fyzické vlastnosti</h2>
<p>Ověření fyzického GPU, modelu a VRAM, prezentace na monitoru, HDR/EDR panelu,
audio výstupu a živého MSFS/SimConnect je BLOCKED. Hostované aplikační renderer
evidence, scene graph, adapter text, Core Image ani transportní fixture samy
neprokazují skutečný hardware nebo jeho paměť. Repozitář při zahájení
kvalifikace neměl připojený odpovídající runner. Raw JSON uvádí pouze backend,
který měřicí proces dokázal přečíst.</p></section>
</main></body></html>"""
    (args.output / "index.html").write_text(html, encoding="utf-8")
    problems = list(visual_problems) if require_fresh_macos_visual else []
    if os.environ.get("REQUIRE_DEPENDENCIES_MATCH") == "1":
        problems.extend(dependency_problems)
    if require_paired_performance:
        problems.extend(performance_problems)
    if problems:
        print("Exact hosted evidence failed: " + " | ".join(problems))
        return 1
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
