# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: tools/perf/issue_434_tk_report.py
# Module Type: Offline Benchmark Report Generator
# Purpose: Build a Czech, source-data-driven HTML report for issue 434.
# Responsibilities:
#   - Read hosted benchmark summaries, raw child records and optional CI evidence.
#   - Generate standalone accessible SVG plots and architecture diagrams.
#   - Disclose unavailable evidence without inferring results or implementation.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)
"""Generate the offline Czech issue 434 performance report from hosted evidence."""

import argparse
import html
import json
import math
import os
import re
import sys
import xml.sax.saxutils as xml_utils
from pathlib import Path
from textwrap import wrap
from typing import TypeAlias

JsonValue: TypeAlias = (
    str | int | float | bool | None | list["JsonValue"] | dict[str, "JsonValue"]
)
JsonObject: TypeAlias = dict[str, JsonValue]

# Purpose: Refuse evidence files too large for a hosted report worker.
# Type: int. Units: bytes. Scope: one JSON evidence file.
# Consumers: load_json. Rationale: 512 MiB accommodates retained child event
# arrays while bounding accidental artifact expansion in the report job.
MAX_EVIDENCE_BYTES = 536870912
# Purpose: Keep a raw-distribution SVG readable and reasonably sized.
# Type: int. Units: plotted points. Scope: each empirical CDF chart.
# Consumers: ecdf_svg. Rationale: 500 evenly selected observations preserve
# the curve shape without emitting an SVG path for every raw callback.
ECDF_POINT_LIMIT = 500
# Purpose: Fix the report's responsive SVG plot canvas.
# Type: tuple[int, int]. Units: SVG user units. Scope: generated charts.
# Consumers: chart renderers. Rationale: 960 by 440 leaves room for labels and
# fits common desktop previews while remaining vector-scalable.
SVG_SIZE = (960, 440)
# Purpose: Bound confidence-interval rows in one plot for legible labels.
# Type: int. Units: paired metrics. Scope: one CI SVG.
# Consumers: confidence interval chart selection. Rationale: 24 rows retain a
# useful cross-workload sample without shrinking labels below readable size.
CI_CHART_POINT_LIMIT = 24
# Purpose: Bound simultaneous lines in a long-session memory plot.
# Type: int. Units: child records. Scope: one RSS trend SVG.
# Consumers: memory_trend_svg. Rationale: Eight lines fit the compact legend
# while preserving all individual files in the source evidence.
RSS_SERIES_LIMIT = 8
# Purpose: Define recognized GUI stall histogram boundaries.
# Type: tuple[float, ...]. Units: milliseconds. Scope: raw event-loop drift.
# Consumers: histogram_svg. Rationale: Bands mirror the issue 434 acceptance
# thresholds from one frame through increasingly disruptive pauses.
REPORT_STALL_THRESHOLDS_MS = (16.7, 33.3, 50.0, 100.0, 250.0, 500.0)
# Purpose: Summarize raw Lightbox publication latency at a stable upper tail.
# Type: float. Units: quantile fraction. Scope: executive raw-event summary.
# Consumers: _raw_percentile_summary. Rationale: p95 surfaces slow software-side
# publications while remaining meaningful for the bounded trial event samples.
EXECUTIVE_RAW_PERCENTILE = 0.95
# Purpose: Prefer the final paired checkpoint and otherwise the latest named phase.
# Type: tuple[str, ...]. Units: checkpoint identifiers. Scope: executive summary.
# Consumers: executive_summary_lines. Rationale: preserve explicit phase ordering
# from the issue protocol when a final BF comparison is not among supplied files.
EXECUTIVE_CHECKPOINT_ORDER = ("B0", "B1", "B2", "B3", "BF")
# Purpose: Fit readable node and caption text in the architecture SVG.
# Type: tuple[int, int]. Units: characters. Scope: architecture diagrams.
# Consumers: architecture_svg. Rationale: 28 fits a node and 100 fits a caption.
ARCHITECTURE_WRAP_WIDTHS = (28, 100)

HEATMAP_METRICS = {
    "event_loop_drift_p95_ms": "Drift p95",
    "event_loop_drift_p99_ms": "Drift p99",
    "interaction_feedback_p95_ms": "Odezva p95",
    "lightbox_preview_request_to_canvas_publication_p95_ms": "Náhled p95",
    "lightbox_final_request_to_canvas_publication_p95_ms": "Finální rastr p95",
    "import_items_per_second": "Import / s",
    "process_peak_rss_bytes": "Špičková RSS",
}
CELL_IDENTITY_FIELDS = (
    "run_id", "run_attempt", "cell", "checkpoint", "baseline_sha",
    "candidate_sha", "harness_sha256", "fixture_configuration_sha256",
)

EXPECTED_METRICS = {
    "event_loop_drift_p50_ms": "Drift event loopu p50",
    "event_loop_drift_p95_ms": "Drift event loopu p95",
    "event_loop_drift_p99_ms": "Drift event loopu p99",
    "event_loop_drift_max_ms": "Nejdelší prodleva GUI",
    "interaction_feedback_p95_ms": "Vstup až odezva p95",
    "input_handler_p95_ms": "Vstup až obsluha p95",
    "first_useful_image_ms": "První užitečný obraz",
    "viewport_settle_ms": "Ustálení viewportu",
    "process_cpu_ms": "Čas CPU procesu",
    "process_cpu_fraction_of_one_core": "Podíl CPU jednoho jádra",
    "process_peak_rss_bytes": "Špičková RSS",
    "process_steady_rss_bytes": "Ustálená RSS",
    "worker_to_gui_p95_ms": "Worker až GUI p95",
    "worker_enqueue_to_consume_p95_ms": "Worker: vložení až zpracování p95",
    "worker_events_count": "Zpracované události workeru",
    "watcher_input_events_per_second": "Nabízené Watcher události za sekundu",
    "watcher_events_per_second": "Zpracované Watcher události za sekundu",
    "workload_step_count": "Dokončené kroky zátěže",
    "queue_peak": "Maximální hloubka fronty",
    "treeview_insert_count": "Vložení řádků Treeview",
    "treeview_item_update_count": "Aktualizace řádků Treeview",
    "json_decode_count": "Počet JSON deserializací",
    "upload_state_read_count": "Čtení upload_state.json",
    "upload_state_json_decode_count": "Deserializace upload_state.json",
    "upload_state_path_read_count": "Čtení cesty upload_state.json",
    "upload_state_path_read_total_ms": "Čas čtení upload_state.json",
    "upload_state_read_total_ms": "Celkový čas čtení upload_state.json",
    "upload_state_json_decode_total_ms": "Celkový čas deserializace upload_state.json",
    "gui_upload_state_path_read_count": "Čtení upload_state.json na GUI vlákně",
    "gui_upload_state_path_read_total_ms": "Čas čtení upload_state.json na GUI vlákně",
    "gui_upload_state_json_decode_count": "Deserializace upload_state.json na GUI vlákně",
    "gui_upload_state_json_decode_total_ms": "Čas deserializace na GUI vlákně",
    "gui_upload_state_read_count": "Čtení upload_state.json na GUI vlákně",
    "gui_upload_state_read_total_ms": "Celkový čas čtení na GUI vlákně",
    "worker_upload_state_path_read_count": "Čtení upload_state.json ve workeru",
    "worker_upload_state_path_read_total_ms": "Čas čtení upload_state.json ve workeru",
    "worker_upload_state_json_decode_count": "Deserializace upload_state.json ve workeru",
    "worker_upload_state_json_decode_total_ms": "Čas deserializace ve workeru",
    "worker_upload_state_read_count": "Čtení upload_state.json ve workeru",
    "worker_upload_state_read_total_ms": "Celkový čas čtení ve workeru",
    "upload_history_list_count": "Načtení historie uploadů",
    "upload_history_list_total_ms": "Čas načítání historie uploadů",
    "gui_upload_history_list_count": "Načtení historie na GUI vlákně",
    "gui_upload_history_list_total_ms": "Čas načítání historie na GUI vlákně",
    "worker_upload_history_list_count": "Načtení historie ve workeru",
    "worker_upload_history_list_total_ms": "Čas načítání historie ve workeru",
    "upload_state_read_p95_ms": "Čtení upload_state.json p95",
    "upload_state_json_decode_p95_ms": "Deserializace upload_state.json p95",
    "accepted_work_owner_entry_p95_ms": "Vstup do kontextu accepted-work p95",
    "accepted_work_owner_hold_p95_ms": "Práce pod ochranou accepted-work p95",
    "worker_accepted_work_owner_entry_total_ms": "Čas workerů při vstupu do accepted-work",
    "worker_accepted_work_owner_hold_total_ms": "Čas workerů pod ochranou accepted-work",
    "upload_state_atomic_write_p95_ms": "Atomický zápis upload_state.json p95",
    "worker_upload_state_atomic_write_total_ms": "Čas atomických zápisů ve workerech",
    "legacy_read_p95_ms": "Načtení historie legacy cestou p95",
    "inspection_read_p95_ms": "Přísná kontrola historie p95",
    "image_resize_gui_count": "Pillow resize na GUI vlákně",
    "image_resize_worker_count": "Pillow resize ve workeru",
    "pillow_photoimage_count": "Vytvoření PhotoImage",
    "canvas_create_count": "Vytvoření položek Canvas",
    "canvas_delete_count": "Odstranění položek Canvas",
    "canvas_itemconfigure_count": "Volání Canvas itemconfigure",
    "canvas_coords_count": "Změny souřadnic Canvas",
    "stringvar_set_count": "Změny StringVar",
    "stringvar_set_calls": "Volání StringVar.set",
    "stringvar_repeated_observed_assignments": "Opakovaná přiřazení StringVar",
    "widget_configure_calls": "Volání widget.configure",
    "widget_state_mutations": "Změny stavu widgetů",
    "widget_map_calls": "Zobrazení widgetů",
    "widget_unmap_calls": "Skrytí widgetů",
    "geometry_manager_count": "Volání geometry manageru",
    "photoimage_release_count": "Uvolnění PhotoImage",
    "lightbox_preview_p95_ms": "Náhled Lightboxu p95",
    "lightbox_final_p95_ms": "Finální vykreslení Lightboxu p95",
    "lightbox_preview_request_to_canvas_publication_p95_ms": (
        "Lightbox: požadavek až zveřejnění náhledu v Canvasu p95"
    ),
    "lightbox_final_request_to_canvas_publication_p95_ms": (
        "Lightbox: požadavek až zveřejnění finálního rastru v Canvasu p95"
    ),
    "import_progress_queue_handoff_p95_ms": (
        "Import: předání průběhové události z fronty Tk p95"
    ),
    "import_progress_text_publication_p95_ms": (
        "Import: zápis textu průběhu do Tk p95"
    ),
    "import_items_per_second": "Propustnost importu",
}

ARCHITECTURES: dict[str, tuple[str, ...]] = {
    "gui-pipeline": (
        "Tok událostí GUI",
        "Události z workerů", "Společná FIFO", "Obsluha události v Tk",
        "Obnova částí GUI v Tk",
        "Události z workerů", "Stejná FIFO", "Doménová obsluha v Tk",
        "Změny prezentace po dávce",
    ),
    "dirty-rendering": (
        "Vykreslení změněných prvků",
        "Tick nebo změna stavu", "Příprava popisků", "Opakovaný StringVar.set",
        "Opakovaná konfigurace widgetu",
        "Tick nebo změna stavu", "Porovnání s publikovanou hodnotou",
        "Kontrola viditelnosti", "Tk aktualizuje změněný prvek",
    ),
    "import-progress": (
        "Průběh importu",
        "Worker uloží job", "Událost job_progress", "Tk znovu čte uložený job",
        "Tk připraví celou tabulku",
        "Worker uloží a ověří job", "Událost s revizí a změnami",
        "Tk sloučí běžný průběh", "Viditelná tabulka změní řádky",
    ),
    "immutable-snapshots": (
        "Neměnné snapshoty UI",
        "Worker mění ImportJob", "Nahlásí základní počty", "Tk načte uložený job",
        "Tk sestaví prezentační stav",
        "Ověřená revize workeru", "Neměnné statistiky a řádky",
        "Tk ověří generaci a identitu", "Při mezeře získá celý snapshot",
    ),
    "persistence-presentation": (
        "Perzistence a prezentace",
        "JobStateStore ověří nový stav", "Trvalý upload_state.json",
        "Tk čte a dekóduje JSON", "Tk zobrazí historii a průběh",
        "JobStateStore ověří nový stav", "Trvalý upload_state.json",
        "Workery připraví projekce", "Tk zobrazí neměnné projekce",
    ),
    "async-lightbox": (
        "Asynchronní vykreslení Lightboxu",
        "Vstup Tk", "Výpočet viewportu v Tk", "Pillow resize v Tk",
        "Tk zveřejní PhotoImage",
        "Vstup a viewport v Tk", "Ořez a resize ve workeru",
        "Tk ověří přístup a požadavek", "Tk zveřejní PhotoImage",
    ),
    "latest-wins": (
        "Plánování latest-wins",
        "Vstup nebo dřívější resize timer", "Geometrie v Tk", "Rastr obsadí callback",
        "Tk obslouží další vstup",
        "Identita zdroje a požadavku", "Jeden běžící Pillow job",
        "Jeden nahraditelný požadavek", "Tk zobrazí aktuální výsledek",
    ),
    "retained-canvas": (
        "Stálá obrazová položka Canvasu",
        "Tk vytvoří PhotoImage", "Canvas delete(all)", "Canvas create_image",
        "Pozice, stav a HDR",
        "Tk vytvoří PhotoImage", "Stálá položka itemconfigure",
        "Souřadnice a samostatný stav", "HDR podle aktuální fotografie",
    ),
}


def load_json(path: Path) -> JsonValue:
    """Read a bounded JSON evidence file.

    Args:
        path (Path): Summary or raw child JSON path.

    Returns:
        JsonValue: Decoded JSON structure.

    Raises:
        ValueError: The evidence exceeds the declared size bound or is invalid.
        OSError: The evidence cannot be read.
    """
    if path.stat().st_size > MAX_EVIDENCE_BYTES:
        raise ValueError(f"Podklad překračuje limit reportu: {path.name}")
    return json.loads(path.read_text(encoding="utf-8"))


def as_object(value: JsonValue) -> JsonObject | None:
    """Return a JSON object value when the decoded shape is an object.

    Args:
        value (JsonValue): Decoded evidence value.

    Returns:
        JsonObject | None: Object value, or None for another JSON shape.
    """
    return value if isinstance(value, dict) else None


def number(value: JsonValue) -> float | None:
    """Return only finite JSON numeric measurements.

    Args:
        value (JsonValue): Candidate scalar from evidence.

    Returns:
        float | None: Finite number, excluding bool and absent values.
    """
    if isinstance(value, bool) or not isinstance(value, (int, float)):
        return None
    return float(value) if math.isfinite(float(value)) else None


def format_measure(value: JsonValue, digits: int = 3) -> str:
    """Format a finite measured number or show an explicit unavailable mark.

    Args:
        value (JsonValue): Candidate numeric evidence.
        digits (int): Significant digits to display.

    Returns:
        str: Formatted finite value, or an em dash when absent.
    """
    measured = number(value)
    return f"{measured:.{digits}g}" if measured is not None else "—"


def text(value: JsonValue, fallback: str = "") -> str:
    """Convert safe scalar metadata into display text.

    Args:
        value (JsonValue): Candidate JSON value.
        fallback (str): Text used for missing or structured values.

    Returns:
        str: String or numeric scalar, otherwise the fallback.
    """
    if isinstance(value, (str, int, float)) and not isinstance(value, bool):
        return str(value)
    return fallback


def evidence_paths(inputs: list[str]) -> list[Path]:
    """Resolve summary files from explicit paths or evidence directories.

    Args:
        inputs (list[str]): Repeated summary JSON paths or directories.

    Returns:
        list[Path]: Unique summary or cell-report paths in stable order.
    """
    found: set[Path] = set()
    for raw_path in inputs:
        path = Path(raw_path)
        if path.is_file():
            found.add(path.resolve())
        elif path.is_dir():
            summaries = sorted(path.rglob("summary.json"))
            if summaries:
                found.update(item.resolve() for item in summaries)
            else:
                found.update(item.resolve() for item in sorted(path.rglob("report.json")))
    return sorted(found, key=lambda item: str(item).casefold())


def collect_cells(paths: list[Path]) -> tuple[list[JsonObject], list[str]]:
    """Collect cell reports without treating missing data as a result.

    Args:
        paths (list[Path]): Summary or cell report paths.

    Returns:
        tuple[list[JsonObject], list[str]]: Unique cell reports and load issues.
    """
    cells: list[JsonObject] = []
    issues: list[str] = []
    seen: set[tuple[str, ...]] = set()
    for path in paths:
        try:
            root = as_object(load_json(path))
        except (OSError, ValueError, json.JSONDecodeError) as error:
            issues.append(f"{path.name}: {error}")
            continue
        if root is None:
            issues.append(f"{path.name}: expected a JSON object")
            continue
        raw_cases = root.get("cases")
        candidates = raw_cases if isinstance(raw_cases, list) else [root]
        for candidate in candidates:
            cell = as_object(candidate)
            if cell is None or "cell" not in cell or "statistics" not in cell:
                continue
            identity = cell_identity(cell)
            if identity not in seen:
                seen.add(identity)
                cells.append(cell)
    cells.sort(key=lambda cell: (
        text(cell.get("checkpoint")), text(cell.get("cell")), text(cell.get("stage")),
    ))
    return cells, issues


def cell_identity(cell: JsonObject) -> tuple[str, ...]:
    """Identify a cell by measurement run, attempt, fixture and source roles.

    Args:
        cell (JsonObject): Benchmark cell with central identity fields.

    Returns:
        tuple[str, ...]: Stable identity shared by its summary and raw artifact.
    """
    return tuple(text(cell.get(field)) for field in CELL_IDENTITY_FIELDS)


def collect_raw(
    paths: list[str], cells: list[JsonObject],
) -> tuple[list[JsonObject], list[str]]:
    """Load raw children with the checkpoint and reference of their own cell.

    Args:
        paths (list[str]): Repeated JSON paths or artifact directories.
        cells (list[JsonObject]): Collected cell summaries that own each trial.

    Returns:
        tuple[list[JsonObject], list[str]]: Raw child records and load issues.
    """
    candidates: set[Path] = set()
    for raw in paths:
        path = Path(raw)
        if path.is_file():
            candidates.add(path.resolve())
        elif path.is_dir():
            candidates.update(item.resolve() for item in path.rglob("*.json"))
    accepted = {cell_identity(cell): cell for cell in cells}
    by_directory: dict[Path, JsonObject] = {}
    records: list[JsonObject] = []
    issues: list[str] = []
    for path in sorted(candidates, key=lambda item: str(item).casefold()):
        if path.parent.name != "trials" or path.parent.parent.name != "issue-434":
            continue
        directory = path.parent.parent
        if directory not in by_directory:
            try:
                artifact = as_object(load_json(directory / "report.json")) or {}
                by_directory[directory] = accepted.get(cell_identity(artifact), {})
            except (OSError, ValueError, json.JSONDecodeError) as error:
                by_directory[directory] = {}
                issues.append(f"{directory.parent.name}: {error}")
        cell = by_directory[directory]
        if not cell:
            continue
        try:
            record = as_object(load_json(path))
        except (OSError, ValueError, json.JSONDecodeError) as error:
            issues.append(f"{path.name}: {error}")
            continue
        case = cell_case(cell)
        runs = cell.get("records")
        if (record is None or record.get("status") != "PASS"
                or not isinstance(record.get("raw_events"), list)
                or not isinstance(record.get("metrics"), dict)
                or not isinstance(runs, list)):
            continue
        role = path.stem.rsplit("-", 1)[-1]
        matched = any(
            isinstance(run, dict) and run.get("status") == "PASS"
            and run.get("source_sha") == record.get("source")
            and run.get("trial") == record.get("trial") and run.get("role") == role
            for run in runs
        )
        if (not matched or record.get("cache") != cell.get("cache")
                or any(record.get(key) != case.get(key)
                       for key in ("scenario", "scale", "raster_size"))):
            issues.append(f"{cell.get('cell')} / {path.name}: identita měření nesouhlasí.")
            continue
        record.update({
            "checkpoint": cell.get("checkpoint"), "reference": cell.get("reference"),
            "cell": cell.get("cell"), "role": role, "run_id": cell.get("run_id"),
        })
        records.append(record)
    return records, issues


def cell_case(cell: JsonObject) -> JsonObject:
    """Return the protocol case dictionary from a cell report.

    Args:
        cell (JsonObject): One compact benchmark cell report.

    Returns:
        JsonObject: Case configuration or an empty mapping.
    """
    case = as_object(cell.get("case"))
    return case if case is not None else {}


def cell_label(cell: JsonObject) -> str:
    """Build a concise label that keeps scenario and cache distinct.

    Args:
        cell (JsonObject): One compact benchmark cell report.

    Returns:
        str: Checkpoint, scenario, and cache label.
    """
    case = cell_case(cell)
    checkpoint = text(cell.get("checkpoint"), text(cell.get("reference"), "checkpoint?") )
    reference = text(cell.get("reference"))
    stage_label = checkpoint if not reference or reference == checkpoint else f"{checkpoint} (reference {reference})"
    pieces = [stage_label]
    pieces.append(text(case.get("id"), text(cell.get("cell"), "case?")))
    pieces.append(text(cell.get("cache"), "cache?"))
    return " / ".join(pieces)


def summary_for(cell: JsonObject, metric: str, leg: str) -> float | None:
    """Read a median for one source leg or paired comparison.

    Args:
        cell (JsonObject): Cell report carrying process statistics.
        metric (str): Metric key in the benchmark summary.
        leg (str): ``baseline`` or ``candidate`` source leg.

    Returns:
        float | None: Observed median or None when the metric is absent.
    """
    stats = as_object(cell.get("statistics"))
    if stats is None:
        return None
    if leg == "baseline":
        summaries = as_object(stats.get("baseline"))
        observed = as_object(summaries.get(metric)) if summaries else None
        return number(observed.get("median")) if observed else None
    paired = as_object(stats.get("paired"))
    comparison = as_object(paired.get(metric)) if paired else None
    side = as_object(comparison.get(leg)) if comparison else None
    return number(side.get("median")) if side else None


def paired_values(cell: JsonObject, metric: str) -> tuple[float, float, float, float] | None:
    """Return candidate median, paired delta and its confidence interval.

    Args:
        cell (JsonObject): Cell report carrying paired statistics.
        metric (str): Metric key.

    Returns:
        tuple[float, float, float, float] | None: Candidate median, paired mean
            difference, and the lower and upper confidence bounds.
    """
    stats = as_object(cell.get("statistics"))
    paired = as_object(stats.get("paired")) if stats else None
    comparison = as_object(paired.get(metric)) if paired else None
    candidate = as_object(comparison.get("candidate")) if comparison else None
    ci = comparison.get("paired_delta_ci95") if comparison else None
    candidate_median = number(candidate.get("median")) if candidate else None
    delta = number(comparison.get("paired_delta_mean")) if comparison else None
    if (candidate_median is None or delta is None or not isinstance(ci, list)
            or len(ci) != 2):
        return None
    lower, upper = number(ci[0]), number(ci[1])
    return (candidate_median, delta, lower, upper) if lower is not None and upper is not None else None


def _executive_pair_cells(cells: list[JsonObject]) -> tuple[str | None, list[JsonObject]]:
    """Select the newest supplied comparison against B0 for the summary.

    Args:
        cells (list[JsonObject]): Loaded benchmark cell reports.

    Returns:
        tuple[str | None, list[JsonObject]]: Selected checkpoint and its paired
            cells, or None and an empty list when no B0 comparison was supplied.
    """
    paired = [
        cell for cell in cells
        if text(cell.get("reference")) == "B0"
        and text(cell.get("checkpoint")) != "B0"
    ]
    if not paired:
        return None, []
    order = {name: index for index, name in enumerate(EXECUTIVE_CHECKPOINT_ORDER)}
    checkpoint = max(
        (text(cell.get("checkpoint")) for cell in paired),
        key=lambda value: (order.get(value, -1), value),
    )
    return checkpoint, [
        cell for cell in paired if text(cell.get("checkpoint")) == checkpoint
    ]


def _executive_cell_matches(
    cell: JsonObject, scenario: str, cache: str | None = None,
    scale: int | None = None, raster_size: tuple[int, int] | None = None,
) -> bool:
    """Match one paired cell to a concrete workload and fixture extent.

    Args:
        cell (JsonObject): One paired benchmark report.
        scenario (str): Exact workload scenario identifier.
        cache (str | None): Required cache condition when supplied.
        scale (int | None): Required fixture extent when supplied.
        raster_size (tuple[int, int] | None): Required source raster when supplied.

    Returns:
        bool: True when the cell config matches every supplied selector.
    """
    case = cell_case(cell)
    if text(case.get("scenario")) != scenario:
        return False
    if cache is not None and text(cell.get("cache")) != cache:
        return False
    if scale is not None and number(case.get("scale")) != scale:
        return False
    if raster_size is not None:
        dimensions = case.get("raster_size")
        if dimensions != [raster_size[0], raster_size[1]]:
            return False
    return True


def _executive_metric_line(
    cells: list[JsonObject], metric: str, label: str,
) -> str:
    """Render measured B0 and candidate medians with the paired 95% interval.

    Args:
        cells (list[JsonObject]): Cells selected for one scenario/cache pair.
        metric (str): Exact statistical metric name.
        label (str): Czech human-readable metric label.

    Returns:
        str: Evidence-backed comparison, or a clear missing-data statement.
    """
    for cell in cells:
        baseline = summary_for(cell, metric, "baseline")
        pair = paired_values(cell, metric)
        if baseline is None or pair is None:
            continue
        candidate, delta, lower, upper = pair
        unit = " ms" if metric.endswith("_ms") else ""
        return (
            f"{label}. Medián B0 {baseline:.4g}{unit} → kandidát {candidate:.4g}{unit}. "
            f"Průměrný párový rozdíl {delta:+.4g}{unit} s 95% CI "
            f"[{lower:.4g}, {upper:.4g}]{unit}."
        )
    return f"{label}. Párová měření nejsou k dispozici."


def _raw_percentile_summary(values: list[float]) -> str:
    """Summarize a pooled raw-event sample using the named nearest-rank p95.

    Args:
        values (list[float]): Software-side event durations in milliseconds.

    Returns:
        str: Sample count and p95, or an explicit unavailable indication.
    """
    ordered = sorted(value for value in values if math.isfinite(value) and value >= 0)
    if not ordered:
        return "nedostupné"
    index = max(0, math.ceil(EXECUTIVE_RAW_PERCENTILE * len(ordered)) - 1)
    return f"n={len(ordered)}, p95={ordered[index]:.4g} ms"


def _lightbox_raw_values(
    records: list[JsonObject], checkpoint: str, role: str, cache: str,
    metric: str,
) -> list[float]:
    """Collect exact UHD regular-Lightbox publication samples by source role.

    Args:
        records (list[JsonObject]): Raw child records with exact cell identities.
        checkpoint (str): Selected B0 comparison checkpoint.
        role (str): ``baseline`` or ``candidate`` source role.
        cache (str): Exact cache condition to keep paired distributions distinct.
        metric (str): Exact request-to-Canvas publication event key.

    Returns:
        list[float]: Retained durations converted from nanoseconds to milliseconds.
    """
    values: list[float] = []
    for record in records:
        if (text(record.get("checkpoint")) != checkpoint
                or record.get("reference") != "B0"
                or text(record.get("role")) != role
                or text(record.get("scenario")) != "lightbox"
                or text(record.get("cache")) != cache
                or record.get("raster_size") != [3840, 2160]):
            continue
        events = record.get("raw_events")
        if not isinstance(events, list):
            continue
        for event_value in events:
            event = as_object(event_value)
            if event is None or text(event.get("metric")) != metric:
                continue
            duration = number(event.get("ns"))
            if duration is not None and duration >= 0:
                values.append(duration / 1_000_000)
    return values


def executive_summary_lines(
    cells: list[JsonObject], raw_records: list[JsonObject],
    status_counts: dict[str, int], checkpoints: list[str],
) -> list[str]:
    """Build a practical summary from supplied pair statistics and raw samples.

    Args:
        cells (list[JsonObject]): Loaded benchmark cell reports.
        raw_records (list[JsonObject]): Raw child records with paired identities.
        status_counts (dict[str, int]): Counts of exact cell process statuses.
        checkpoints (list[str]): Supplied checkpoint identifiers.

    Returns:
        list[str]: Czech executive findings with explicit unavailable evidence.
    """
    checkpoint, pairs = _executive_pair_cells(cells)
    paired_cell_count = 0
    for cell in cells:
        stats = as_object(cell.get("statistics"))
        if (text(cell.get("reference")) == "B0" and stats is not None
                and as_object(stats.get("paired"))):
            paired_cell_count += 1
    lines = [
        f"Podklady zahrnují {len(cells)} buněk, z toho {paired_cell_count} "
        f"přímo párovaných proti B0. Raw záznamy procesů {len(raw_records)}. "
        f"Kontrolní body {', '.join(checkpoints) or 'žádné'}."
    ]
    if status_counts:
        lines.append(
            "Výsledky provedení buněk " + ", ".join(
                f"{status} {count}" for status, count in sorted(status_counts.items())
            ) + ". Výkon posuzují samostatné statistické brány."
        )
    if checkpoint is None:
        lines.append(
            "Chybí přímé statistické porovnání kandidáta s B0; přínosy ani "
            "regrese proto z dodaných buněk nelze vyhodnotit."
        )
        return lines

    gates = {
        "regression": 0, "inconclusive": 0, "investigate": 0,
        "within_margin": 0, "observed": 0,
    }
    for cell in pairs:
        stats = as_object(cell.get("statistics"))
        paired = as_object(stats.get("paired")) if stats else None
        if not paired:
            continue
        for comparison_value in paired.values():
            comparison = as_object(comparison_value)
            if comparison is None:
                continue
            gate = text(comparison.get("gate"))
            if gate in gates:
                gates[gate] += 1
    gate_count = sum(gates.values()) - gates["observed"]
    lines.append(
        f"Porovnání B0 a {checkpoint} má {gate_count} statistických bran. "
        f"Regrese {gates['regression']}, neprůkazné {gates['inconclusive']}, "
        f"RSS k prověření {gates['investigate']} a v mezích {gates['within_margin']}."
    )

    idle_cold = [
        cell for cell in pairs
        if _executive_cell_matches(cell, "idle", cache="cold")
    ]
    idle_warm = [
        cell for cell in pairs
        if _executive_cell_matches(cell, "idle", cache="warm")
    ]
    for idle_cells in (idle_cold, idle_warm):
        if not idle_cells:
            continue
        suffix = text(idle_cells[0].get("cache"), "idle")
        lines.append(_executive_metric_line(
            idle_cells, "event_loop_drift_p95_ms", f"Idle drift p95 ({suffix})",
        ))
        lines.append(_executive_metric_line(
            idle_cells, "stringvar_repeated_observed_assignments",
            f"Opakovaná přiřazení StringVar ({suffix})",
        ))
        lines.append(_executive_metric_line(
            idle_cells, "process_cpu_fraction_of_one_core",
            f"CPU jednoho jádra ({suffix})",
        ))

    for scenario, label in (("watcher", "Běžný Watcher"),
                            ("watcher-burst", "Watcher při přetížení")):
        watcher = [cell for cell in pairs if _executive_cell_matches(cell, scenario, cache="warm")]
        if watcher:
            lines.append(_executive_metric_line(
                watcher, "event_loop_drift_p95_ms", f"{label} · warm drift p95",
            ))
            lines.append(_executive_metric_line(
                watcher, "watcher_events_per_second", f"{label} · události za sekundu",
            ))

    import_cells = [
        cell for cell in pairs
        if _executive_cell_matches(cell, "import", cache="warm", scale=1000)
    ]
    for metric, label in (
        ("event_loop_drift_p95_ms", "Import 1000 · drift p95"),
        ("gui_upload_state_json_decode_total_ms", "Import 1000 · GUI deserializace upload_state.json"),
        ("gui_upload_state_path_read_total_ms", "Import 1000 · GUI čtení upload_state.json"),
        ("import_items_per_second", "Import 1000 · propustnost"),
    ):
        lines.append(_executive_metric_line(import_cells, metric, label))

    for metric, label in (
        ("lightbox_preview_request_to_canvas_publication", "UHD Lightbox · software p95 náhledu"),
        ("lightbox_final_request_to_canvas_publication", "UHD Lightbox · software p95 finálního rastru"),
    ):
        for cache in ("cold", "warm"):
            baseline_values = _lightbox_raw_values(
                raw_records, checkpoint, "baseline", cache, metric,
            )
            candidate_values = _lightbox_raw_values(
                raw_records, checkpoint, "candidate", cache, metric,
            )
            if baseline_values or candidate_values:
                lines.append(
                    f"{label} ({cache}): B0 "
                    f"{_raw_percentile_summary(baseline_values)}; kandidát "
                    f"{_raw_percentile_summary(candidate_values)}."
                )
        if not any(
            record.get("scenario") == "lightbox"
            and record.get("raster_size") == [3840, 2160]
            and record.get("checkpoint") == checkpoint
            for record in raw_records
        ):
            lines.append(f"{label}: v dodaných UHD raw událostech není měření.")

    memory_cells = [cell for cell in pairs if _executive_cell_matches(cell, "long-session")]
    for metric, label in (
        ("process_steady_rss_bytes", "Dlouhá relace · ustálená RSS"),
        ("process_peak_rss_bytes", "Dlouhá relace · špičková RSS"),
        ("workload_step_count", "Dlouhá relace · dokončené kroky zátěže"),
        ("watcher_input_events_per_second", "Dlouhá relace · nabízené Watcher události za sekundu"),
        ("watcher_events_per_second", "Dlouhá relace · zpracované Watcher události za sekundu"),
        ("import_items_per_second", "Dlouhá relace · dokončené položky Importu za sekundu"),
        ("import_progress_queue_handoff_p95_ms", "Dlouhá relace · předání průběhu do Tk p95"),
    ):
        lines.append(_executive_metric_line(memory_cells, metric, label))
    if not import_cells:
        lines.append(
            "Warm Import v rozsahu 1000 položek není mezi dodanými párovanými buňkami."
        )
    if not memory_cells:
        lines.append("Dlouhá relace pro porovnání RSS není dodána.")
    if not any(record.get("scenario") == "lightbox"
               and record.get("raster_size") == [3840, 2160]
               and record.get("checkpoint") == checkpoint for record in raw_records):
        lines.append("UHD Lightbox raw evidence chybí; fyzické vykreslení monitoru tato metrika neměří.")
    return lines


def xml_escape(value: str) -> str:
    """Escape plain text for generated SVG markup.

    Args:
        value (str): Label or annotation text.

    Returns:
        str: XML-escaped text.
    """
    return xml_utils.escape(value, {"\"": "&quot;", "'": "&apos;"})


def svg_shell(title: str, description: str, body: str, height: int = SVG_SIZE[1]) -> str:
    """Wrap SVG content with accessible title and description elements.

    Args:
        title (str): Accessible chart title.
        description (str): Accessible chart summary and data semantics.
        body (str): SVG elements inside the viewBox.
        height (int): SVG height. Defaults to the ordinary figure canvas.

    Returns:
        str: Standalone, accessible SVG document.
    """
    safe_title = xml_escape(title)
    safe_description = xml_escape(description)
    return (
        '<svg xmlns="http://www.w3.org/2000/svg" role="img" '
        f'aria-labelledby="svg-title svg-desc" viewBox="0 0 {SVG_SIZE[0]} {height}">'
        f'<title id="svg-title">{safe_title}</title>'
        f'<desc id="svg-desc">{safe_description}</desc>'
        f'<rect width="{SVG_SIZE[0]}" height="{height}" rx="22" fill="#101d2d"/>'
        '<g font-family="system-ui, sans-serif" fill="#eef4f7">'
        f'{body}</g></svg>'
    )


def bar_svg(
    title: str, points: list[tuple[str, float | None, float | None]], unit: str,
) -> str:
    """Create a vector paired bar chart from available source medians.

    Args:
        title (str): Chart title.
        points (list[tuple[str, float | None, float | None]]): Labels and
            baseline/candidate medians. Missing values render as absent bars.
        unit (str): Value unit printed on the axis.

    Returns:
        str: Standalone SVG string with no inferred values.
    """
    observed = [value for _, left, right in points for value in (left, right)
                if value is not None and value >= 0]
    if not observed:
        return svg_shell(title, "V dodaných podkladech nejsou naměřené hodnoty.",
                         '<text x="480" y="220" text-anchor="middle" font-size="20">'
                         'Žádné naměřené hodnoty</text>')
    max_value = max(observed) or 1.0
    left_edge, right_edge, top_edge, bottom_edge = 90, 930, 95, 335
    plot_height = bottom_edge - top_edge
    chart: list[str] = [
        f'<text x="42" y="47" font-size="23" font-weight="700">{xml_escape(title)}</text>',
        f'<text x="90" y="77" font-size="13" fill="#a9bdca">{xml_escape(unit)}</text>',
        f'<line x1="{left_edge}" y1="{bottom_edge}" x2="{right_edge}" y2="{bottom_edge}" stroke="#587083"/>',
        '<rect x="690" y="45" width="12" height="12" rx="2" fill="#71a9d5"/>',
        '<text x="710" y="56" font-size="12">Výchozí běh</text>',
        '<rect x="800" y="45" width="12" height="12" rx="2" fill="#45c29a"/>',
        '<text x="820" y="56" font-size="12">Kandidát</text>',
    ]
    for tick in range(5):
        y = bottom_edge - (plot_height * tick / 4)
        value = max_value * tick / 4
        chart.append(
            f'<line x1="{left_edge}" y1="{y:.1f}" x2="{right_edge}" y2="{y:.1f}" '
            'stroke="#263b4b"/><text x="78" y="'
            f'{y + 4:.1f}" text-anchor="end" font-size="11" fill="#a9bdca">'
            f'{value:.3g}</text>'
        )
    slot = (right_edge - left_edge) / max(1, len(points))
    bar_width = min(24.0, slot * 0.25)
    for index, (label, baseline, candidate) in enumerate(points):
        center = left_edge + slot * (index + 0.5)
        for offset, value, color in (
            (-bar_width / 2, baseline, "#71a9d5"),
            (bar_width / 2, candidate, "#45c29a"),
        ):
            if value is None or value < 0:
                continue
            height = plot_height * value / max_value
            chart.append(
                f'<rect x="{center + offset - bar_width / 2:.1f}" '
                f'y="{bottom_edge - height:.1f}" width="{bar_width:.1f}" '
                f'height="{height:.1f}" rx="3" fill="{color}"/>'
            )
        chart.append(
            f'<text x="{center:.1f}" y="355" text-anchor="end" '
            f'transform="rotate(-28 {center:.1f} 355)" font-size="11" '
            f'fill="#d1dce3">{xml_escape(label)}</text>'
        )
    desc = "Pouze pozorované mediány; chybějící sloupec znamená nedostupnou metriku."
    return svg_shell(title, desc, "".join(chart))


def ci_svg(title: str, points: list[tuple[str, float, float, float, str]]) -> str:
    """Create a paired delta plot with the recorded 95% confidence intervals.

    Args:
        title (str): Chart title.
        points (list[tuple[str, float, float, float, str]]): Workload label,
            paired mean, lower and upper bounds in milliseconds, and status.

    Returns:
        str: Standalone SVG with a zero reference line.
    """
    if not points:
        return svg_shell(title, "Párové intervaly spolehlivosti nebyly dodány.",
                         '<text x="480" y="220" text-anchor="middle" font-size="20">'
                         'Párové intervaly nejsou k dispozici</text>')
    values = [value for _, delta, low, high, _ in points for value in (delta, low, high)]
    bound = max(1.0, max(abs(value) for value in values))
    x0, x1 = 220.0, 910.0
    y0, gap = 112.0, min(45.0, 280.0 / max(1, len(points)))
    xmap = lambda value: x0 + ((value + bound) / (2 * bound)) * (x1 - x0)
    body = [
        f'<text x="42" y="47" font-size="23" font-weight="700">{xml_escape(title)}</text>',
        f'<line x1="{xmap(0):.1f}" y1="82" x2="{xmap(0):.1f}" y2="390" stroke="#f4cb79" stroke-dasharray="5 5"/>',
        f'<text x="{xmap(-bound):.1f}" y="410" font-size="12">{(-bound):.3g}</text>',
        f'<text x="{xmap(0):.1f}" y="410" font-size="12" text-anchor="middle">0</text>',
        f'<text x="{xmap(bound):.1f}" y="410" font-size="12" text-anchor="end">{bound:.3g}</text>',
    ]
    colors = {"pass": "#45c29a", "fail": "#f07878"}
    for index, (label, delta, low, high, status) in enumerate(points):
        y = y0 + index * gap
        color = colors.get(status, "#a9bdca")
        body.append(
            f'<text x="205" y="{y + 4:.1f}" text-anchor="end" font-size="12">'
            f'{xml_escape(label)}</text><line x1="{xmap(low):.1f}" y1="{y:.1f}" '
            f'x2="{xmap(high):.1f}" y2="{y:.1f}" stroke="{color}" stroke-width="3"/>'
            f'<circle cx="{xmap(delta):.1f}" cy="{y:.1f}" r="5" fill="{color}"/>'
        )
    return svg_shell(
        title,
        "Průměr párových rozdílů kandidát minus B0 a 95% interval spolehlivosti. "
        "Záporný rozdíl odpovídá kratšímu driftu.",
        "".join(body),
    )


def ecdf_svg(title: str, series: dict[str, list[float]], unit: str) -> str:
    """Create a sampled empirical CDF using only retained raw event durations.

    Args:
        title (str): Chart title.
        series (dict[str, list[float]]): Series labels and measured values.
        unit (str): Horizontal-axis unit.

    Returns:
        str: Standalone SVG string or an explicit empty-data notice.
    """
    all_values = [value for values in series.values() for value in values if value >= 0]
    if not all_values:
        return svg_shell(title, "Nejsou k dispozici raw vzorky událostí.",
                         '<text x="480" y="220" text-anchor="middle" font-size="20">'
                         'Raw vzorky nejsou dostupné</text>')
    maximum = max(all_values) or 1.0
    x0, x1, y0, y1 = 90.0, 910.0, 92.0, 350.0
    colors = ("#71a9d5", "#45c29a", "#f4cb79", "#e08eb4")
    body = [
        f'<text x="42" y="47" font-size="23" font-weight="700">{xml_escape(title)}</text>',
        f'<line x1="{x0}" y1="{y1}" x2="{x1}" y2="{y1}" stroke="#587083"/>',
        f'<line x1="{x0}" y1="{y0}" x2="{x0}" y2="{y1}" stroke="#587083"/>',
        f'<text x="{x0}" y="382" font-size="12">0 {xml_escape(unit)}</text>',
        f'<text x="{x1}" y="382" text-anchor="end" font-size="12">{maximum:.3g} {xml_escape(unit)}</text>',
        '<text x="48" y="100" font-size="12">100%</text>',
        '<text x="48" y="350" font-size="12">0%</text>',
    ]
    for series_index, (label, raw_values) in enumerate(sorted(series.items())):
        ordered = sorted(value for value in raw_values if value >= 0)
        if not ordered:
            continue
        stride = max(1, math.ceil(len(ordered) / ECDF_POINT_LIMIT))
        selected = ordered[::stride]
        if selected[-1] != ordered[-1]:
            selected.append(ordered[-1])
        coords: list[str] = []
        for point_index, value in enumerate(selected):
            x = x0 + min(1.0, value / maximum) * (x1 - x0)
            quantile = min(1.0, (point_index * stride + 1) / len(ordered))
            y = y1 - quantile * (y1 - y0)
            coords.append(f"{x:.1f},{y:.1f}")
        color = colors[series_index % len(colors)]
        body.append(
            f'<polyline points="{" ".join(coords)}" fill="none" '
            f'stroke="{color}" stroke-width="2.5"/>'
            f'<line x1="{110 + series_index * 180}" y1="415" '
            f'x2="{135 + series_index * 180}" y2="415" stroke="{color}" stroke-width="3"/>'
            f'<text x="142" y="420" font-size="11">{xml_escape(label)}</text>'
        )
    return svg_shell(title, "Empirická distribuční funkce z uložených raw událostí.", "".join(body))


def histogram_svg(title: str, values_ms: list[float]) -> str:
    """Create explicit event-loop stall threshold buckets from raw samples.

    Args:
        title (str): Chart title.
        values_ms (list[float]): Measured event-loop drift samples in milliseconds.

    Returns:
        str: Standalone SVG histogram or explicit unavailability text.
    """
    thresholds = REPORT_STALL_THRESHOLDS_MS
    labels = ("<16.7", "16.7–33.3", "33.3–50", "50–100", "100–250", "250–500", "≥500")
    if not values_ms:
        return svg_shell(title, "Nejsou k dispozici raw vzorky driftu event loopu.",
                         '<text x="480" y="220" text-anchor="middle" font-size="20">'
                         'Raw vzorky nejsou dostupné</text>')
    counts: list[int] = []
    lower = 0.0
    for upper in thresholds:
        counts.append(sum(lower <= value < upper for value in values_ms))
        lower = upper
    counts.append(sum(value >= lower for value in values_ms))
    high = max(counts) or 1
    slot = 820 / len(counts)
    body = [f'<text x="42" y="47" font-size="23" font-weight="700">{xml_escape(title)}</text>']
    for index, (label, count) in enumerate(zip(labels, counts, strict=True)):
        height = 250 * count / high
        x = 100 + slot * index
        body.append(
            f'<rect x="{x + 8:.1f}" y="{350 - height:.1f}" width="{slot - 16:.1f}" '
            f'height="{height:.1f}" rx="4" fill="#71a9d5"/>'
            f'<text x="{x + slot / 2:.1f}" y="370" text-anchor="middle" font-size="12">'
            f'{xml_escape(label)} ms</text><text x="{x + slot / 2:.1f}" '
            f'y="{340 - height:.1f}" text-anchor="middle" font-size="12">{count}</text>'
        )
    return svg_shell(title, f"{len(values_ms)} raw pozorování driftu event loopu rozdělených do pevných pásem v milisekundách.", "".join(body))


def architecture_svg(key: str, title: str, nodes: tuple[str, ...], notes: JsonObject) -> str:
    """Render the reviewed B0 and candidate paths in separate SVG lanes.

    Args:
        key (str): Stable diagram identifier for optional notes overrides.
        title (str): Diagram heading.
        nodes (tuple[str, ...]): Original and target lane node labels.
        notes (JsonObject): Reviewed static context supplied by the report owner.

    Returns:
        str: Accessible source-level flow diagram and optional context.
    """
    overrides = as_object(notes.get("architecture"))
    statement = text(overrides.get(key)) if overrides else ""
    original = nodes[:4]
    target = nodes[4:]
    y_rows = (112, 222)
    bodies = [
        f'<text x="42" y="47" font-size="23" font-weight="700">{xml_escape(title)}</text>',
        '<text x="42" y="98" font-size="15" fill="#f4cb79">Výchozí tok v B0</text>',
        '<text x="42" y="208" font-size="15" fill="#45c29a">Tok v kandidátovi</text>',
    ]
    for row_index, row in enumerate((original, target)):
        y = y_rows[row_index]
        fill = "#263b4b" if row_index == 0 else "#183b38"
        gap = 875 / max(1, len(row))
        for index, label in enumerate(row):
            x = 42 + index * gap
            center = x + (gap - 14) / 2
            bodies.append(
                f'<rect x="{x:.1f}" y="{y}" width="{gap - 14:.1f}" height="55" '
                f'rx="9" fill="{fill}" stroke="#587083"/>'
            )
            # Keep labels inside their fixed-width node without shrinking type.
            lines = wrap(label, width=ARCHITECTURE_WRAP_WIDTHS[0])
            first_line = y + 32 - (len(lines) - 1) * 7
            for offset, line in enumerate(lines):
                bodies.append(
                    f'<text x="{center:.1f}" y="{first_line + offset * 14}" '
                    f'text-anchor="middle" font-size="12">{xml_escape(line)}</text>'
                )
            if index + 1 < len(row):
                bodies.append(
                    f'<path d="M{x + gap - 12:.1f} {y + 27} h 10" '
                    'stroke="#a9bdca" stroke-width="2" marker-end="url(#arrow)"/>'
                )
    bodies.insert(0,
        '<defs><marker id="arrow" markerWidth="8" markerHeight="8" refX="6" refY="3" '
        'orient="auto"><path d="M0,0 L0,6 L7,3 z" fill="#a9bdca"/></marker></defs>'
    )
    caption = statement or "Zjednodušený tok podle zdrojů obou kontrolních bodů."
    for offset, line in enumerate(wrap(caption, width=ARCHITECTURE_WRAP_WIDTHS[1])):
        bodies.append(
            f'<text x="42" y="{320 + offset * 21}" font-size="15" fill="#a9bdca">'
            f'{xml_escape(line)}</text>'
        )
    return svg_shell(title, caption, "".join(bodies))


def write_svg(directory: Path, name: str, svg: str) -> Path:
    """Write a standalone SVG asset with a safe deterministic filename.

    Args:
        directory (Path): Report asset directory.
        name (str): Human-readable plot or diagram name.
        svg (str): Complete SVG document.

    Returns:
        Path: Written SVG file.
    """
    safe_name = re.sub(r"[^a-z0-9-]+", "-", name.casefold()).strip("-") or "figure"
    target = directory / f"{safe_name}.svg"
    target.write_text(svg, encoding="utf-8")
    return target


def collect_raw_series(
    records: list[JsonObject], metric: str,
) -> dict[str, list[float]]:
    """Group raw event durations by checkpoint, scenario and source role.

    Args:
        records (list[JsonObject]): Child records with event arrays.
        metric (str): Exact raw event name.

    Returns:
        dict[str, list[float]]: Series in milliseconds from actual observations.
    """
    grouped: dict[str, list[float]] = {}
    for record in records:
        role = text(record.get("role"), "source")
        case = text(record.get("scenario"), "case")
        cache = text(record.get("cache"), "cache")
        scale = text(record.get("scale"), "—")
        raster_value = record.get("raster_size")
        raster_label = (
            " × ".join(str(dimension) for dimension in raster_value)
            if isinstance(raster_value, list) else ""
        )
        label = (
            f"{text(record.get('checkpoint'), 'stage?')} / "
            f"{text(record.get('reference'), 'reference?')} · {role} · {case} "
            f"· scale {scale} · {raster_label} · {cache}"
        )
        events = record.get("raw_events")
        if not isinstance(events, list):
            continue
        for raw_event in events:
            event = as_object(raw_event)
            if event is None or event.get("metric") != metric:
                continue
            nanoseconds = number(event.get("ns"))
            if nanoseconds is not None:
                grouped.setdefault(label, []).append(nanoseconds / 1_000_000)
    return grouped


def cell_has_metric(cell: JsonObject, metric: str) -> bool:
    """Check whether either recorded source leg contains a metric.

    Args:
        cell (JsonObject): Benchmark cell report.
        metric (str): Required metric identifier.

    Returns:
        bool: True when baseline or paired candidate statistics exist.
    """
    stats = as_object(cell.get("statistics"))
    if stats is None:
        return False
    baseline = as_object(stats.get("baseline"))
    paired = as_object(stats.get("paired"))
    return bool((baseline and metric in baseline) or (paired and metric in paired))


def render_table(headers: list[str], rows: list[list[str]]) -> str:
    """Render escaped semantic table markup.

    Args:
        headers (list[str]): Column headings.
        rows (list[list[str]]): Already escaped cell strings.

    Returns:
        str: Accessible HTML table.
    """
    head = "".join(f"<th scope=\"col\">{item}</th>" for item in headers)
    body = "".join("<tr>" + "".join(f"<td>{item}</td>" for item in row) + "</tr>"
                   for row in rows)
    return f"<div class=\"table-wrap\"><table><thead><tr>{head}</tr></thead><tbody>{body}</tbody></table></div>"


def operation_evidence_table(records: list[JsonObject]) -> str:
    """Render measured media startup operations and deterministic fixture IDs.

    Args:
        records (list[JsonObject]): Raw child records with operation mappings.

    Returns:
        str: Compact table or an explicit notice when operation data is absent.
    """
    elapsed_keys = (
        "media_first_image_elapsed_ms", "media_grid_settled_elapsed_ms",
        "media_first_detail_elapsed_ms", "media_settled_elapsed_ms",
    )
    fixture_keys = ("media_photo_fixture_sha256", "media_detail_fixture_sha256")
    rows: list[list[str]] = []
    for record in records:
        operations = as_object(record.get("operations")) or {}
        identity = " · ".join((
            text(record.get("checkpoint"), "—"),
            text(record.get("scenario"), "—"),
            f"{text(record.get('role'), '—')} · trial {text(record.get('trial'), '—')}",
        ))
        for key in (*elapsed_keys, *fixture_keys):
            value = operations.get(key)
            if value is None:
                continue
            label = {
                "media_first_image_elapsed_ms": "Čas do prvního obrazu (ms)",
                "media_grid_settled_elapsed_ms": "Čas do ustálení mřížky (ms)",
                "media_first_detail_elapsed_ms": "Čas do prvního detailu (ms)",
                "media_settled_elapsed_ms": "Čas do ustálení detailu (ms)",
                "media_photo_fixture_sha256": "SHA-256 fixture fotografií",
                "media_detail_fixture_sha256": "SHA-256 fixture detailu",
            }[key]
            rows.append([html.escape(identity), html.escape(label), html.escape(text(value))])
    if not rows:
        return '<p class="muted">Časové body ani identifikátory fixture nebyly v raw podkladech zaznamenány.</p>'
    return render_table(["Scénář a běh", "Operace", "Naměřená hodnota"], rows)


def import_progress_evidence_table(records: list[JsonObject]) -> str:
    """Show measured queue and publication timings with import fixture counters.

    Args:
        records (list[JsonObject]): Raw child records with metrics and operations.

    Returns:
        str: Evidence table populated only from supplied import child records.
    """
    operation_labels = {
        "persisted_job_snapshots": "Uložené revize úlohy",
        "confirmed_import_items": "Potvrzené položky importu",
        "events_produced": "Vytvořené události",
        "events_drained": "Zpracované události",
        "events_pending": "Události čekající ve frontě",
        "event_queue_peak": "Největší hloubka fronty",
        "event_order": "Pořadí událostí",
        "progress_route": "Cesta průběhové události",
    }
    metric_labels = {
        "import_progress_queue_handoff_p95_ms": (
            "Předání průběhové události z fronty Tk p95 (ms)"
        ),
        "import_progress_text_publication_p95_ms": (
            "Zápis textu průběhu do Tk p95 (ms)"
        ),
    }
    rows: list[list[str]] = []
    import_scenarios = {"import", "import-gui", "long-session"}
    for record in records:
        if text(record.get("scenario")) not in import_scenarios:
            continue
        identity = " · ".join((
            text(record.get("checkpoint"), "—"),
            text(record.get("scenario"), "—"),
            f"{text(record.get('role'), '—')} · trial {text(record.get('trial'), '—')}",
        ))
        operations = as_object(record.get("operations")) or {}
        metrics = as_object(record.get("metrics")) or {}
        for key, label in operation_labels.items():
            value = operations.get(key)
            if value is not None:
                rows.append([
                    html.escape(identity), html.escape(label),
                    html.escape(text(value)),
                ])
        for key, label in metric_labels.items():
            value = metrics.get(key)
            if value is not None:
                rows.append([
                    html.escape(identity), html.escape(label),
                    html.escape(text(value)),
                ])
    if not rows:
        return '<p class="muted">Importní časování ani čítače nebyly v raw podkladech zaznamenány.</p>'
    return render_table(["Scénář a běh", "Operace", "Naměřená hodnota"], rows)


def status_badge(status: str) -> str:
    """Render a status with text and a matching color class.

    Args:
        status (str): Exact status token from evidence.

    Returns:
        str: Escaped status badge HTML.
    """
    normalized = status.upper()
    css = "unknown"
    if normalized in {"PASS", "SUCCESS"}:
        css = "pass"
    elif normalized in {"FAIL", "FAILURE", "CANCELLED", "TIMED_OUT"}:
        css = "fail"
    return f'<span class="badge {css}">{html.escape(normalized or "MISSING")}</span>'


def paired_outcome(metric: str, comparison: JsonObject) -> tuple[str, str]:
    """Translate the recorded gate and interval into a report label and color.

    Args:
        metric (str): Metric identifier whose direction determines improvement.
        comparison (JsonObject): One benchmark comparison and its stored gate.

    Returns:
        tuple[str, str]: Czech outcome label and report color class.
    """
    gate = text(comparison.get("gate"))
    if gate == "regression":
        return "Regrese", "fail"
    if gate == "investigate":
        return "Prověřit RSS", "investigate"
    if gate == "inconclusive":
        return "Neprůkazné", "unknown"
    if gate == "within_margin":
        bounds = comparison.get("paired_delta_ci95")
        if isinstance(bounds, list) and len(bounds) == 2:
            lower, upper = number(bounds[0]), number(bounds[1])
            if metric == "import_items_per_second":
                if lower is not None and lower > 0:
                    return "Zlepšení", "pass"
            elif upper is not None and upper < 0:
                return "Zlepšení", "pass"
        return "V toleranci", "unknown"
    return "Pozorováno", "unknown"


def load_optional(path_text: str | None) -> tuple[JsonObject, str | None]:
    """Read one optional functional or reviewed-notes JSON object.

    Args:
        path_text (str | None): Optional path supplied on the command line.

    Returns:
        tuple[JsonObject, str | None]: Decoded object and a visible issue if invalid.
    """
    if not path_text:
        return {}, None
    path = Path(path_text)
    try:
        value = as_object(load_json(path))
    except (OSError, ValueError, json.JSONDecodeError) as error:
        return {}, f"{path.name}: {error}"
    return (value, None) if value is not None else ({}, f"{path.name}: expected object")


def safe_link(value: JsonValue, label: str | None = None) -> str:
    """Render only HTTP(S) links from reviewed report context.

    Args:
        value (JsonValue): Candidate URL string.
        label (str | None): Optional visible link text.

    Returns:
        str: Escaped link or plain escaped text when scheme is not allowed.
    """
    url = text(value)
    if not url.startswith(("https://", "http://")):
        return html.escape(label or url or "—")
    return f'<a href="{html.escape(url, quote=True)}">{html.escape(label or url)}</a>'


def note_section(notes: JsonObject, key: str, title: str) -> str:
    """Render reviewed static text without synthesizing missing context.

    Args:
        notes (JsonObject): Optional reviewed issue/run context.
        key (str): Context key to read.
        title (str): Visible section heading.

    Returns:
        str: Escaped prose, list, or a clear unavailable notice.
    """
    value = notes.get(key)
    if isinstance(value, str) and value.strip():
        content = f"<p>{html.escape(value)}</p>"
    elif isinstance(value, list) and value:
        content = "<ul>" + "".join(
            f"<li>{html.escape(text(item, json.dumps(item, ensure_ascii=False)))}</li>"
            for item in value
        ) + "</ul>"
    else:
        content = '<p class="muted">Poznámky nebyly dodány.</p>'
    return f"<article class=\"card\"><h3>{html.escape(title)}</h3>{content}</article>"


def build_report(
    output: Path, evidence: list[str], raw_cells: list[str],
    functional_path: str | None, notes_path: str | None,
) -> int:
    """Generate the offline HTML report and standalone SVG collection.

    Args:
        output (Path): Final HTML output path.
        evidence (list[str]): Repeated benchmark summary files or directories.
        raw_cells (list[str]): Repeated directories or child JSON files.
        functional_path (str | None): Optional regression/functional JSON evidence.
        notes_path (str | None): Optional reviewed context JSON.

    Returns:
        int: Zero when inputs were read and the report was written.
    """
    cell_files = evidence_paths(evidence)
    cells, evidence_issues = collect_cells(cell_files)
    raw_records, raw_issues = collect_raw(raw_cells, cells)
    functional, functional_issue = load_optional(functional_path)
    notes, notes_issue = load_optional(notes_path)
    output.parent.mkdir(parents=True, exist_ok=True)
    asset_dir = output.parent / f"{output.stem}-assets"
    asset_dir.mkdir(parents=True, exist_ok=True)

    generated: list[tuple[str, str]] = []
    all_metric_names: set[str] = set()
    for cell in cells:
        stats = as_object(cell.get("statistics"))
        if stats:
            for group_name in ("baseline", "paired"):
                group = as_object(stats.get(group_name))
                if group:
                    all_metric_names.update(str(key) for key in group)

    executive_checkpoint, baseline_to_final = _executive_pair_cells(cells)
    chart_cells = baseline_to_final or cells
    # The named report metrics have individual charts. All observations, with
    # their original cells and control legs, remain in the statistics table.
    for metric in sorted(all_metric_names & EXPECTED_METRICS.keys()):
        points: list[tuple[str, float | None, float | None]] = []
        for cell in chart_cells:
            base = summary_for(cell, metric, "baseline")
            candidate = summary_for(cell, metric, "candidate")
            if base is not None or candidate is not None:
                label = f"{text(cell_case(cell).get('id'))} · {text(cell.get('cache'))}"
                points.append((label, base, candidate))
        if points:
            filename = write_svg(
                asset_dir, f"metric-{metric}",
                bar_svg(
                    "Měřená metrika · " + EXPECTED_METRICS.get(metric, metric),
                    points, metric_unit(metric),
                ),
            )
            generated.append((f"Metrika: {metric}", filename.name))

    # Keep intermediate checkpoint values paired with their own B0 controls.
    for case_id, cache in (("idle", "cold"), ("mixed", "warm"),
                           ("import-1000", "warm"), ("lightbox-4k", "cold")):
        progression = [
            (text(cell.get("checkpoint")),
             summary_for(cell, "event_loop_drift_p95_ms", "baseline"),
             summary_for(cell, "event_loop_drift_p95_ms", "candidate"))
            for cell in cells if cell_case(cell).get("id") == case_id
            and cell.get("cache") == cache and cell.get("reference") == "B0"
        ]
        if progression:
            phase_title = f"Drift p95 podle fáze · {case_id} · {cache}"
            figure = write_svg(
                asset_dir, f"checkpoint-drift-{case_id}-{cache}",
                bar_svg(phase_title, progression, "milisekundy"),
            )
            generated.append((phase_title, figure.name))

    ci_points: list[tuple[str, float, float, float, str]] = []
    comparison_rows: list[list[str]] = []
    for cell in cells:
        stats = as_object(cell.get("statistics"))
        paired = as_object(stats.get("paired")) if stats else None
        if not paired:
            continue
        for metric, comparison_value in paired.items():
            comparison = as_object(comparison_value)
            if comparison is None:
                continue
            delta = number(comparison.get("paired_delta_mean"))
            ci = comparison.get("paired_delta_ci95")
            baseline_stats = as_object(comparison.get("baseline")) or {}
            candidate_stats = as_object(comparison.get("candidate")) or {}
            ci_text = (
                f"[{format_measure(ci[0])}, {format_measure(ci[1])}]"
                if isinstance(ci, list) and len(ci) == 2 else "—"
            )
            outcome, css = paired_outcome(metric, comparison)
            relative = number(comparison.get("relative_delta"))
            comparison_rows.append([
                html.escape(cell_label(cell)),
                html.escape(str(metric)),
                html.escape(format_measure(baseline_stats.get("median"))),
                html.escape(format_measure(candidate_stats.get("median"))),
                html.escape(format_measure(comparison.get("paired_delta_mean"))),
                html.escape(format_measure(relative * 100) if relative is not None else "—"),
                html.escape(format_measure(comparison.get("baseline_to_candidate_ratio"))),
                html.escape(ci_text),
                f'<span class="badge {css}">{html.escape(outcome)}</span>',
            ])
            if (metric == "event_loop_drift_p95_ms" and delta is not None
                    and text(cell.get("checkpoint")) == executive_checkpoint
                    and text(cell.get("reference")) == "B0"
                    and isinstance(ci, list) and len(ci) == 2
                    and len(ci_points) < CI_CHART_POINT_LIMIT):
                low, high = number(ci[0]), number(ci[1])
                if low is not None and high is not None:
                    label = f"{cell_case(cell).get('id')} · {cell.get('cache')}"
                    ci_points.append((label, delta, low, high, css))
    ci_file = write_svg(asset_dir, "paired-delta-confidence-intervals", ci_svg(
        f"Drift p95 proti B0 · {executive_checkpoint or 'kandidát'} · 95% CI (ms)",
        ci_points,
    ))
    generated.append(("Párové rozdíly s 95% CI", ci_file.name))

    raw_drift = collect_raw_series(raw_records, "event_loop_drift")
    drift_values = [value for values in raw_drift.values() for value in values]
    histogram = write_svg(
        asset_dir, "event-loop-stall-histogram",
        histogram_svg("Pásma prodlev event loopu", drift_values),
    )
    generated.append(("Histogram prodlev event loopu", histogram.name))
    ecdf = write_svg(
        asset_dir, "event-loop-drift-ecdf",
        ecdf_svg("Rozdělení driftu event loopu", raw_drift, "ms"),
    )
    generated.append(("ECDF driftu event loopu", ecdf.name))
    raw_metric_names: set[str] = set()
    for record in raw_records:
        events = record.get("raw_events")
        if not isinstance(events, list):
            continue
        for event_value in events:
            event = as_object(event_value)
            if event is not None:
                raw_metric_names.add(text(event.get("metric")))
    raw_plot_metrics = {
        "callback", "interaction_feedback", "worker_enqueue_to_consume",
        "image_resize_gui", "image_resize_worker", "image_publication",
        "upload_state_read", "upload_state_json_decode", "thumbnail_prepare",
        "detail_prepare", "lightbox_preview", "lightbox_final",
    }
    raw_plot_metrics.update(
        metric for metric in raw_metric_names
        if metric.startswith(("lightbox_", "photos_", "import_progress_"))
    )
    raw_metric_labels = {
        "lightbox_preview_request_to_canvas_publication": (
            "Požadavek až zveřejnění náhledu Lightboxu v Canvasu"
        ),
        "lightbox_final_request_to_canvas_publication": (
            "Požadavek až zveřejnění finálního rastru Lightboxu v Canvasu"
        ),
        "import_progress_queue_handoff": (
            "Předání průběhové události z fronty Tk"
        ),
        "import_progress_text_publication": (
            "Zápis textu průběhu do Tk"
        ),
    }
    for metric in sorted(raw_plot_metrics & raw_metric_names):
        raw_series = collect_raw_series(raw_records, metric)
        metric_label = raw_metric_labels.get(
            metric, EXPECTED_METRICS.get(metric, metric),
        )
        figure = write_svg(
            asset_dir, f"raw-ecdf-{metric}",
            ecdf_svg(f"Rozdělení latencí · {metric_label}", raw_series, "ms"),
        )
        generated.append((f"Rozdělení (raw ECDF): {metric}", figure.name))

    memory_svg = memory_trend_svg(raw_records, executive_checkpoint)
    memory_file = write_svg(asset_dir, "long-session-rss-trend", memory_svg)
    generated.append(("Průběh RSS během dlouhé relace", memory_file.name))
    heatmap = heatmap_svg(baseline_to_final)
    heat_file = write_svg(asset_dir, "scenario-regression-heatmap", heatmap)
    generated.append(("Tepelná mapa změn podle scénářů", heat_file.name))

    architecture_links: list[tuple[str, str]] = []
    for key, spec in ARCHITECTURES.items():
        title, *nodes = spec
        figure = write_svg(
            asset_dir, f"architecture-{key}",
            architecture_svg(key, title, tuple(nodes), notes),
        )
        architecture_links.append((title, figure.name))

    sections = []
    checkpoints = sorted({text(cell.get("checkpoint")) for cell in cells if text(cell.get("checkpoint"))})
    stages = sorted({text(cell.get("stage")) for cell in cells if text(cell.get("stage"))})
    status_counts: dict[str, int] = {}
    for cell in cells:
        status = text(cell.get("status"), "MISSING").upper()
        status_counts[status] = status_counts.get(status, 0) + 1
    if not cells:
        coverage_card = '<p class="warn">Souhrny benchmarkových buněk nebyly dodány. Grafy výsledků proto nejsou k dispozici.</p>'
    else:
        coverage_card = render_table(
            ["Buňka", "Kontrolní bod", "Stav", "Procesy reference", "Páry",
             "SHA kandidáta", "SHA harnessu"],
            [[
                html.escape(cell_label(cell)),
                html.escape(text(cell.get("checkpoint"), "—")),
                status_badge(text(cell.get("status"), "MISSING")),
                html.escape(text((as_object(cell.get("statistics")) or {}).get(
                    "successful_baselines",
                ), "0")),
                html.escape(text(as_object(cell.get("statistics")).get("pairs")
                                 if as_object(cell.get("statistics")) else None, "0")),
                html.escape(text(cell.get("candidate_sha"), "—")),
                html.escape(text(cell.get("harness_sha"), "—")),
            ] for cell in cells],
        )
    environment_rows: list[list[str]] = []
    for cell in cells:
        host = as_object(cell.get("host")) or {}
        runs = cell.get("records")
        first_run = (as_object(runs[0]) or {}) if isinstance(runs, list) and runs else {}
        metadata = as_object(first_run.get("metadata")) or {}
        display = as_object(metadata.get("display")) or {}
        display_size = " × ".join(text(display.get(dimension), "—") for dimension in ("width", "height"))
        environment_rows.append([
            html.escape(cell_label(cell)),
            html.escape(text(host.get("windows_build"), text(host.get("windows_release"), "—"))),
            html.escape(text(host.get("runner_image_label"), "—")),
            html.escape(text(host.get("runner_image_version"), "—")),
            html.escape(text(host.get("cpu_model"), "—")),
            html.escape(text(host.get("logical_cores"), "—")),
            html.escape(text(host.get("memory_total_bytes"), "—")),
            html.escape(text(metadata.get("python"), "—")),
            html.escape(text(metadata.get("tcl"), "—")),
            html.escape(text(metadata.get("tk"), "—")),
            html.escape(text(metadata.get("pillow"), text(host.get("pillow_version"), "—"))),
            html.escape(display_size),
            html.escape(format_measure(display.get("dpi"))),
        ])
    environment_table = render_table(
        ["Buňka", "Windows", "Obraz runneru", "Verze obrazu", "CPU", "Logická jádra",
         "RAM (bajty)", "Python", "Tcl", "Tk", "Pillow", "Virtuální displej (px)", "DPI"],
        environment_rows,
    ) if environment_rows else '<p class="muted">Metadata prostředí nejsou k dispozici.</p>'
    identity_rows = []
    for cell in cells:
        case = cell_case(cell)
        identity_rows.append([
            html.escape(cell_label(cell)),
            html.escape(text(cell.get("run_id"), "—")),
            html.escape(text(cell.get("run_attempt"), "—")),
            html.escape(text(cell.get("baseline_sha"), "—")),
            html.escape(text(cell.get("candidate_sha"), "—")),
            html.escape(text(cell.get("harness_sha256"), "—")),
            html.escape(text(cell.get("protocol_sha256"), "—")),
            html.escape(text(cell.get("fixture_configuration_sha256"), "—")),
            html.escape(json.dumps(case.get("raster_size"), ensure_ascii=False)
                        if case.get("raster_size") is not None else "—"),
        ])
    identity_table = render_table(
        ["Buňka", "Běh Actions", "Pokus", "SHA výchozí verze", "SHA kandidáta", "SHA-256 harnessu",
         "SHA-256 protokolu", "SHA-256 konfigurace fixture", "Rozměry rastru"],
        identity_rows,
    ) if identity_rows else '<p class="muted">Identifikátory zdrojů ani fixture nebyly dodány.</p>'
    sections.append(section("coverage", "Pokrytí benchmarkem", coverage_card +
        f'<p class="muted">Dodané fáze: {html.escape(", ".join(stages) or "žádné")}; '
        f'dodané kontrolní body: {html.escape(", ".join(checkpoints) or "žádné")}.</p>'
        '<h3>Testovací prostředí</h3>' + environment_table
        + '<h3>Identita zdroje a fixture</h3>' + identity_table))

    missing_rows = []
    for metric, label in EXPECTED_METRICS.items():
        have = [cell for cell in cells if cell_has_metric(cell, metric)]
        if have:
            missing_rows.append([html.escape(label), f"Naměřeno v {len(have)} buňkách"])
        else:
            missing_rows.append([html.escape(label), '<span class="muted">V dodaných podkladech není k dispozici</span>'])
    missing_html = render_table(["Požadovaná skupina metrik", "Podklady"], missing_rows)
    if evidence_issues or raw_issues:
        issue_items = evidence_issues + raw_issues
        missing_html += '<ul class="issue-list">' + "".join(
            f"<li>{html.escape(issue)}</li>" for issue in issue_items
        ) + "</ul>"
    missing_html += '<h3>Časové body médií a identita fixture</h3>' + operation_evidence_table(raw_records)
    missing_html += '<h3>Operace průběhu importu</h3>' + import_progress_evidence_table(raw_records)
    missing_html += (
        '<p class="muted">Předání průběhové události měří čas od vložení do fronty '
        'po její vyzvednutí vláknem Tk. Publikace textu měří volání '
        '<code>StringVar.set</code>. Zveřejnění rastru v Canvasu je softwarová '
        'hranice aplikace; žádná z těchto hodnot neměří fyzické vykreslení '
        'monitoru.</p>'
    )
    sections.append(section("metrics", "Dostupnost metrik", missing_html))
    comparison_table = render_table(
        ["Buňka", "Metrika", "Medián výchozí verze", "Medián kandidáta",
         "Průměrný rozdíl kandidát − reference", "Relativní rozdíl (%)", "Poměr reference / kandidát",
         "Párový 95% CI", "Brána"],
        comparison_rows,
    ) if comparison_rows else '<p class="muted">Párové statistické porovnání nebylo dodáno.</p>'
    sections.append(section(
        "statistics", "Párové rozdíly a statistická nejistota",
        comparison_table + '<p class="muted">Rozdíl odpovídá hodnotě kandidáta minus reference. '
        'Kratší latence a nižší RSS mají záporné rozdíly. Vyšší propustnost má kladný rozdíl. '
        'Brány používají párový interval a předem stanovenou toleranci.</p>',
    ))

    plot_items = generated + architecture_links
    gallery_items: list[str] = []
    for label, asset in plot_items:
        svg_text = (asset_dir / asset).read_text(encoding="utf-8")
        safe_suffix = re.sub(r"[^a-z0-9-]+", "-", asset.casefold())
        asset_href = f"{asset_dir.name}/{asset}"
        svg_text = svg_text.replace("svg-title", "svg-title-" + safe_suffix)
        svg_text = svg_text.replace("svg-desc", "svg-desc-" + safe_suffix)
        svg_text = svg_text.replace('id="arrow"', 'id="arrow-' + safe_suffix + '"')
        svg_text = svg_text.replace("url(#arrow)", "url(#arrow-" + safe_suffix + ")")
        gallery_items.append(
            '<figure class="figure-card"><div class="inline-svg">' + svg_text
            + '</div><figcaption>' + html.escape(label) + ' · '
            + f'<a href="{html.escape(asset_href, quote=True)}" download>Samostatný soubor SVG</a>'
            + '</figcaption></figure>'
        )
    gallery = "".join(gallery_items) or '<p class="muted">Podklady pro grafy nejsou k dispozici.</p>'
    sections.append(section("figures", "Grafy a vektorové diagramy", f'<div class="figure-grid">{gallery}</div>'))

    executive = []
    executive.extend(
        executive_summary_lines(cells, raw_records, status_counts, checkpoints),
    )
    exec_cards = "".join(f'<p>{html.escape(item)}</p>' for item in executive)
    summary_svg = write_svg(
        asset_dir, "b0-versus-selected-checkpoint-event-loop-p95",
        bar_svg(
            "Drift event loopu p95: B0 a "
            + (executive_checkpoint or "kandidát"), [
            (f"{text(cell_case(cell).get('id'))} · {text(cell.get('cache'))}",
             summary_for(cell, "event_loop_drift_p95_ms", "baseline"),
             summary_for(cell, "event_loop_drift_p95_ms", "candidate"))
            for cell in baseline_to_final
            if "event_loop_drift_p95_ms" in all_metric_names
            ], "milisekundy",
        ),
    )
    summary_markup = summary_svg.read_text(encoding="utf-8")
    summary_markup = summary_markup.replace("svg-title", "svg-title-summary")
    summary_markup = summary_markup.replace("svg-desc", "svg-desc-summary")
    summary_graph = (
        f'<figure class="hero-plot"><div class="inline-svg">{summary_markup}</div><figcaption>'
        'Hodnoty jsou zobrazeny pouze tehdy, pokud je obsahují dodané statistiky benchmarku.</figcaption></figure>'
    )
    sections.insert(0, section("summary", "Shrnutí", exec_cards + summary_graph))

    functional_content = functional_status_content(functional, functional_issue)
    sections.append(section("functional", "Funkční a regresní evidence", functional_content))
    context_map = (
        ("baseline_context", "Výchozí stav a úzká místa"),
        ("optimizations", "Optimalizace a jejich skutečný přínos"),
        ("variants", "Experimentální varianty"),
        ("reverted", "Odmítnuté nebo vrácené varianty"),
        ("methodology", "Metodika"),
        ("known_limits", "Známé limity měření"),
        ("remaining_problems", "Zbývající problémy"),
        ("recommendations", "Doporučení pro budoucí optimalizace"),
    )
    if notes.get("evidence_gaps"):
        context_map += (("evidence_gaps", "Chybějící nebo neověřené běhy"),)
    context = "".join(note_section(notes, key, title) for key, title in context_map)
    context += notes_links(notes)
    if notes_issue:
        context += f'<p class="warn">{html.escape(notes_issue)}</p>'
    sections.append(section("context", "Metodika, architektura a rozhodnutí", f'<div class="context-grid">{context}</div>'))

    nav = [
        ("summary", "Souhrn"), ("coverage", "Pokrytí"), ("metrics", "Metriky"),
        ("statistics", "Statistiky"), ("figures", "Grafy"),
        ("functional", "Funkční CI"), ("context", "Kontext"),
    ]
    generated_at = "Běh GitHub Actions" + (f" {html.escape(os.environ.get('GITHUB_RUN_ID', 'unknown'))}" if os.environ.get("GITHUB_RUN_ID") else "")
    html_doc = html_document(output.name, generated_at, nav, sections)
    output.write_text(html_doc, encoding="utf-8")
    return 0


def metric_unit(metric: str) -> str:
    """Infer display units from the metric's explicit name suffix.

    Args:
        metric (str): Evidence metric identifier.

    Returns:
        str: Unit label, or "value" when the name carries none.
    """
    if metric.endswith("_ms"):
        return "milisekundy"
    if metric.endswith("_bytes"):
        return "bajty"
    if metric.endswith("_count"):
        return "operace / události"
    if metric in {"watcher_events_per_second", "watcher_input_events_per_second"}:
        return "události za sekundu"
    if "per_second" in metric:
        return "položky za sekundu"
    return "hodnota"


def memory_trend_svg(records: list[JsonObject], checkpoint: str | None) -> str:
    """Plot sampled process RSS against elapsed time from raw resource records.

    Args:
        records (list[JsonObject]): Raw child records with resource samples.
        checkpoint (str | None): Current paired checkpoint, or None for B0.

    Returns:
        str: SVG with observed time series or an explicit missing-data card.
    """
    points: list[tuple[str, list[tuple[float, float]]]] = []
    selected = [
        record for record in records if record.get("scenario") == "long-session"
        and record.get("checkpoint") == checkpoint
    ]
    if not selected:
        selected = [record for record in records if record.get("scenario") == "long-session"
                    and record.get("checkpoint") == "B0"]
    for record in selected:
        metadata = as_object(record.get("metadata"))
        samples = metadata.get("resource_samples") if metadata else None
        if not isinstance(samples, list):
            continue
        values: list[tuple[float, float]] = []
        for sample_value in samples:
            sample = as_object(sample_value)
            if sample is None:
                continue
            elapsed = number(sample.get("elapsed_ms"))
            rss = number(sample.get("rss_bytes"))
            if elapsed is not None and rss is not None:
                values.append((elapsed / 1000, rss / (1024 * 1024)))
        if values:
            leg = "B0" if record.get("role") == "baseline" else text(record.get("checkpoint"))
            points.append((f"{leg} · pokus {text(record.get('trial'))}", values))
    if not points:
        return svg_shell("Vzorkovaný průběh RSS při dlouhé relaci", "Periodické vzorky zdrojů nebyly dodány.",
                         '<text x="480" y="220" text-anchor="middle" font-size="20">'
                         'Vzorky zdrojů nejsou dostupné</text>')
    max_x = max(x for _, values in points for x, _ in values) or 1.0
    max_y = max(y for _, values in points for _, y in values) or 1.0
    x0, x1, y0, y1 = 95.0, 910.0, 90.0, 350.0
    colors = ("#71a9d5", "#45c29a", "#f4cb79", "#e08eb4")
    body = [
        '<text x="42" y="47" font-size="23" font-weight="700">Vzorkovaný průběh RSS při dlouhé relaci</text>',
        f'<text x="95" y="382" font-size="12">0 s</text><text x="910" y="382" text-anchor="end" font-size="12">{max_x:.3g} s</text>',
        f'<text x="45" y="96" font-size="12">{max_y:.3g} MiB</text>',
    ]
    for index, (label, values) in enumerate(points[:RSS_SERIES_LIMIT]):
        coords = " ".join(
            f"{x0 + x / max_x * (x1 - x0):.1f},{y1 - y / max_y * (y1 - y0):.1f}"
            for x, y in values
        )
        color = colors[index % len(colors)]
        body.append(
            f'<polyline points="{coords}" fill="none" stroke="{color}" stroke-width="2"/>'
            f'<text x="{100 + (index % 4) * 200}" y="{410 + (index // 4) * 18}" '
            f'font-size="11" fill="{color}">{xml_escape(label)}</text>'
        )
    return svg_shell("Vzorkovaný průběh RSS při dlouhé relaci", "Naměřené vzorky RSS procesu během měřené části child procesu.", "".join(body))


def heatmap_svg(cells: list[JsonObject]) -> str:
    """Show the paired performance gates across workloads and cache modes.

    Args:
        cells (list[JsonObject]): Current checkpoint cells paired against B0.

    Returns:
        str: SVG with relative changes and distinct evidence states.
    """
    if not cells:
        return svg_shell("Tepelná mapa rozdílů scénářů", "Párové rozdíly scénářů nebyly dodány.",
                         '<text x="480" y="220" text-anchor="middle" font-size="20">'
                         'Párové rozdíly nejsou dostupné</text>')
    x_start, y_start, row_height = 245, 125, 23
    cell_width = (SVG_SIZE[0] - x_start - 35) / len(HEATMAP_METRICS)
    height = max(SVG_SIZE[1], y_start + len(cells) * row_height + 35)
    colors = {"pass": "#26765f", "fail": "#904b55", "investigate": "#765924"}
    body = [
        '<text x="42" y="47" font-size="23" font-weight="700">Výkonové brány podle scénáře</text>',
        '<text x="42" y="74" font-size="13" fill="#a9bdca">Kandidát minus B0 v procentech. '
        'Zelená zlepšení · červená regrese · zlatá prověřit RSS.</text>',
    ]
    for column, label in enumerate(HEATMAP_METRICS.values()):
        x = x_start + (column + 0.5) * cell_width
        body.append(f'<text x="{x:.1f}" y="111" text-anchor="middle" '
                    f'font-size="11">{xml_escape(label)}</text>')
    for row, cell in enumerate(cells):
        y = y_start + row * row_height
        case_label = f"{text(cell_case(cell).get('id'))} · {text(cell.get('cache'))}"
        body.append(f'<text x="{x_start - 12}" y="{y + 15}" text-anchor="end" '
                    f'font-size="12">{xml_escape(case_label)}</text>')
        statistics = as_object(cell.get("statistics")) or {}
        paired = as_object(statistics.get("paired")) or {}
        for column, metric in enumerate(HEATMAP_METRICS):
            comparison = as_object(paired.get(metric)) or {}
            outcome, css = paired_outcome(metric, comparison)
            relative = number(comparison.get("relative_delta"))
            label = f"{relative * 100:+.1f}%" if relative is not None else "—"
            color = colors.get(css, "#263b4b")
            x = x_start + column * cell_width
            body.append(
                f'<g><title>{xml_escape(f"{case_label} · {metric} · {outcome}")}</title>'
                f'<rect x="{x:.1f}" y="{y}" width="{cell_width - 3:.1f}" '
                f'height="{row_height - 3}" rx="3" fill="{color}"/>'
                f'<text x="{x + (cell_width - 3) / 2:.1f}" y="{y + 14}" '
                f'text-anchor="middle" font-size="11">{xml_escape(label)}</text></g>'
            )
    return svg_shell(
        "Tepelná mapa rozdílů scénářů",
        "Relativní párové změny kandidáta proti B0. Barvy odpovídají "
        "95% intervalu a stanovené toleranci. Prázdná pole označují nedostupné porovnání.",
        "".join(body), height,
    )


def functional_status_content(functional: JsonObject, issue: str | None) -> str:
    """Present functional CI evidence separately from benchmark coverage.

    Args:
        functional (JsonObject): Optional regression evidence JSON.
        issue (str | None): Loading problem for the optional evidence file.

    Returns:
        str: Status summary and exact supplied regression result rows.
    """
    if issue:
        return f'<p class="warn">Funkční podklady nelze načíst: {html.escape(issue)}</p>'
    if not functional:
        return '<p class="muted">JSON s funkční/regresní evidencí nebyl dodán. Stav benchmarku PASS sám o sobě nepotvrzuje pokrytí funkčními testy.</p>'
    status = text(functional.get("status"), "UNKNOWN")
    note = text(functional.get("note"))
    scopes = {"required": "Povinná", "advisory": "Advisory", "additional": "Doplňková"}
    details: list[list[str]] = []
    for key, value in sorted(functional.items()):
        if key in ("cases", "checks", "tests", "results", "jobs") and isinstance(value, list):
            for item in value:
                row = as_object(item)
                if row:
                    row_status = text(row.get("conclusion"), text(row.get("status"), "UNKNOWN"))
                    row_summary = text(row.get("summary"), "")
                    if not row_summary:
                        row_summary = " · ".join(
                            part for part in (
                                text(row.get("status")), text(row.get("conclusion")),
                                text(row.get("audit_report")),
                            ) if part
                        )
                    summary_markup = html.escape(row_summary)
                    if row.get("url"):
                        summary_markup += " · " + safe_link(row.get("url"), "GitHub Actions")
                    details.append([
                        html.escape(text(row.get("name"), text(row.get("id"), "kontrola"))),
                        html.escape(scopes.get(text(row.get("qualification")), "—")),
                        status_badge(row_status),
                        summary_markup,
                    ])
    table = render_table(
        ["Funkční kontrola", "Rozsah", "Stav", "Shrnutí evidence"], details,
    ) if details else ""
    auditor = ""
    if functional.get("audit_gaps"):
        auditor = note_section(
            functional, "audit_gaps", "Mezery v uložených auditních podkladech",
        )
    explanation = f"<p>{html.escape(note)}</p>" if note else ""
    return f'<p>Stav povinných kontrol {status_badge(status)}</p>{explanation}{table}{auditor}'


def notes_links(notes: JsonObject) -> str:
    """Render issue, workflow, raw artifact, commit, and run links if supplied.

    Args:
        notes (JsonObject): Reviewed context values from a static JSON file.

    Returns:
        str: Escaped link table or a transparent no-links note.
    """
    labels = {
        "issue_url": "Issue na GitHubu", "run_url": "Běh GitHub Actions",
        "raw_artifact_url": "Raw artefakt", "html_artifact_url": "HTML artefakt",
        "baseline_commit_url": "Commit výchozí verze", "candidate_commit_url": "Commit kandidáta",
        "baseline_run_url": "Původní běh měření B0",
    }
    rows = [[html.escape(label), safe_link(notes.get(key))]
            for key, label in labels.items() if notes.get(key)]
    checkpoint_links = notes.get("checkpoint_run_urls")
    if isinstance(checkpoint_links, dict):
        rows.extend([
            html.escape(f"Původní běh měření {checkpoint}"), safe_link(url)
        ] for checkpoint, url in sorted(checkpoint_links.items()) if url)
    related_links = notes.get("related_run_urls")
    if isinstance(related_links, dict):
        rows.extend([
            html.escape(label), safe_link(url)
        ] for label, url in sorted(related_links.items()) if url)
    if not rows:
        return '<p class="muted">Odkazy na issue, Actions, raw artefakt a commity nebyly dodány.</p>'
    return render_table(["Odkaz", "Cíl"], rows)


def section(identifier: str, title: str, content: str) -> str:
    """Wrap content in a navigable semantic report section.

    Args:
        identifier (str): Stable section fragment identifier.
        title (str): Visible section heading.
        content (str): Prepared report markup.

    Returns:
        str: Semantic section markup.
    """
    return f'<section id="{html.escape(identifier, quote=True)}"><h2>{html.escape(title)}</h2>{content}</section>'


def html_document(title: str, generated: str, nav: list[tuple[str, str]], sections: list[str]) -> str:
    """Assemble a responsive offline document with print styling and navigation.

    Args:
        title (str): Output filename shown in the document title.
        generated (str): Hosted run identity or non-assertive generation marker.
        nav (list[tuple[str, str]]): Section IDs and Czech navigation labels.
        sections (list[str]): Complete semantic section HTML.

    Returns:
        str: Standalone HTML with inline CSS and no external resources.
    """
    links = "".join(
        f'<a href="#{html.escape(identifier, quote=True)}">{html.escape(label)}</a>'
        for identifier, label in nav
    )
    css = r"""
:root {
    color-scheme: dark;
    --ink: #eef4f7;
    --muted: #a9bdca;
    --panel: #162536;
    --line: #294155;
    --accent: #45c29a;
    --gold: #f4cb79;
    --danger: #f07878;
    font-family: Inter, ui-sans-serif, system-ui, -apple-system, "Segoe UI", sans-serif;
}
* { box-sizing: border-box; }
body {
    margin: 0;
    background: #0b1420;
    color: var(--ink);
    line-height: 1.6;
}
.top {
    padding: 3rem max(1.2rem, calc((100vw - 1320px) / 2));
    background: radial-gradient(ellipse at 15% 0, #1a4052, #0b1420 62%);
    border-bottom: 1px solid var(--line);
}
.top h1 {
    max-width: 1000px;
    font-size: clamp(2rem, 4vw, 3.7rem);
    line-height: 1.12;
    margin: .25rem 0 1rem;
}
.eyebrow {
    color: var(--accent);
    font-size: .8rem;
    letter-spacing: .16em;
    text-transform: uppercase;
    font-weight: 700;
}
.top p { color: var(--muted); max-width: 820px; }
.shell {
    max-width: 1500px;
    margin: auto;
    display: grid;
    grid-template-columns: 230px minmax(0, 1fr);
    gap: 2rem;
    padding: 1.5rem;
}
nav {
    position: sticky;
    top: 1rem;
    align-self: start;
    display: grid;
    gap: .35rem;
    padding: 1rem;
    background: var(--panel);
    border: 1px solid var(--line);
    border-radius: 16px;
}
nav a {
    color: var(--muted);
    text-decoration: none;
    padding: .45rem .65rem;
    border-radius: 8px;
}
nav a:hover { background: #21384a; color: var(--ink); }
main { min-width: 0; }
section { scroll-margin-top: 1rem; margin: 0 0 2.5rem; }
h2 { font-size: 1.8rem; border-bottom: 1px solid var(--line); padding-bottom: .45rem; }
h3 { margin-top: 0; }
.card, .figure-card, .hero-plot {
    background: var(--panel);
    border: 1px solid var(--line);
    border-radius: 16px;
    padding: 1rem;
}
.context-grid, .figure-grid { display: grid; gap: 1rem; }
.context-grid { grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); }
.figure-grid { grid-template-columns: repeat(auto-fit, minmax(360px, 1fr)); }
.figure-card { margin: 0; overflow: hidden; }
.inline-svg svg { width: 100%; height: auto; display: block; border-radius: 10px; }
.figure-card figcaption, .hero-plot figcaption {
    font-size: .9rem;
    color: var(--muted);
    padding-top: .65rem;
}
.table-wrap {
    width: 100%;
    overflow: auto;
    border: 1px solid var(--line);
    border-radius: 12px;
}
table {
    width: 100%;
    border-collapse: collapse;
    min-width: 680px;
    background: var(--panel);
}
th, td {
    text-align: left;
    padding: .7rem .8rem;
    border-bottom: 1px solid var(--line);
    vertical-align: top;
}
th { color: #b9d0df; background: #1d3041; font-weight: 650; }
td { overflow-wrap: anywhere; }
.badge {
    display: inline-block;
    padding: .1rem .55rem;
    border-radius: 999px;
    font-size: .78rem;
    font-weight: 750;
}
.badge.pass { background: #174c3e; color: #8be2c2; }
.badge.fail { background: #5b2e38; color: #ffabb1; }
.badge.investigate { background: #554422; color: #f4cb79; }
.badge.unknown { background: #364251; color: #c3d0d9; }
.muted { color: var(--muted); }
.warn {
    padding: .8rem 1rem;
    border-left: 3px solid var(--gold);
    background: #29291f;
    border-radius: 6px;
}
.issue-list { color: #ffabb1; }
.footer {
    color: var(--muted);
    font-size: .85rem;
    border-top: 1px solid var(--line);
    padding: 1.5rem 0;
    margin-top: 3rem;
}
a { color: #8fd4f5; }
.hero-plot { margin-top: 1.3rem; }
@media (max-width: 850px) {
    .shell { grid-template-columns: 1fr; padding: 1rem; }
    nav { position: static; grid-template-columns: repeat(3, minmax(0, 1fr)); }
    .figure-grid { grid-template-columns: 1fr; }
}
@media (max-width: 480px) {
    nav { grid-template-columns: repeat(2, minmax(0, 1fr)); }
    .context-grid, .figure-grid { display: block; }
    .context-grid > *, .figure-card { margin-bottom: 1rem; }
}
@media print {
    body { background: #fff; color: #152231; }
    .top { background: #fff; border-color: #bbb; padding: 1rem; }
    .top p, .muted { color: #44515c; }
    .shell { display: block; max-width: none; padding: 0; }
    nav { display: none; }
    .card, .figure-card, .hero-plot, table {
        background: #fff;
        color: #152231;
        border-color: #bbb;
        break-inside: avoid;
    }
    .table-wrap { overflow: visible; }
    table { min-width: 0; }
    th { background: #eef2f4; color: #152231; }
    section { break-inside: auto; }
    a { color: #125b82; text-decoration: none; }
    h2 { border-color: #bbb; }
}
"""
    return (
        '<!doctype html><html lang="cs"><head><meta charset="utf-8">'
        '<meta name="viewport" content="width=device-width,initial-scale=1">'
        f'<title>{html.escape(title)}</title><style>{css}</style></head><body>'
        '<header class="top"><div class="eyebrow">PHP Gallery · WinApp · issue #434</div>'
        '<h1>Odezva GUI v PHP Gallery WinApp</h1>'
        '<p>Párová měření Watcheru, Importu a Lightboxu na Windows. '
        'Odezvu měříme při zpracování událostí Tk a připojení rastru do Canvasu.</p>'
        f'<p>{html.escape(generated)} · {html.escape(title)}</p></header>'
        f'<div class="shell"><nav aria-label="Navigace reportem">{links}</nav>'
        f'<main>{"".join(sections)}<footer class="footer">'
        'Čísla pocházejí z přiložených summary.json a raw JSON potomků. '
        'Diagramy zachycují zjednodušené toky v B0 a kandidátovi.'
        '</footer></main></div></body></html>'
    )


def parse_args(argv: list[str] | None = None) -> argparse.Namespace:
    """Parse the hosted report generator command line.

    Args:
        argv (list[str] | None): Optional arguments without executable name.

    Returns:
        argparse.Namespace: Output and evidence paths.
    """
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--output", required=True)
    parser.add_argument("--evidence", action="append", required=True)
    parser.add_argument("--raw-cells", action="append", default=[])
    parser.add_argument("--functional")
    parser.add_argument("--notes")
    args = parser.parse_args(argv)
    if not Path(args.output).is_absolute():
        parser.error("--output must be an absolute path")
    if os.environ.get("GITHUB_ACTIONS", "").lower() != "true":
        parser.error("Generátor reportu lze spouštět pouze v GitHub Actions.")
    return args


def main(argv: list[str] | None = None) -> int:
    """Generate the hosted HTML report and standalone graph assets.

    Args:
        argv (list[str] | None): Optional command line without executable name.

    Returns:
        int: Zero when report artifacts were written successfully.
    """
    args = parse_args(argv)
    return build_report(
        Path(args.output), args.evidence, args.raw_cells,
        args.functional, args.notes,
    )


if __name__ == "__main__":
    raise SystemExit(main())
