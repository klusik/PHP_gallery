# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/uploader/flight_render.py
# Module Type: Parallel Flight Report Service
# Purpose: Render independent language editions in isolated spawned processes.
# Responsibilities:
#   - Prepare shared map backgrounds once and isolate ReportLab/font state.
#   - Drain every child before delivery, cancellation or failure is returned.
#   - Publish completed editions through parent-owned progress/persistence hooks.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)
"""Credential-free process rendering with a complete-set delivery barrier."""

import multiprocessing
import threading
from concurrent.futures import FIRST_COMPLETED, ProcessPoolExecutor, wait
from pathlib import Path
from typing import Callable

from uploader.flight_data import FlightAnalysis
from uploader.flight_jobs import REPORT_LANGUAGES

ReportMaps = Callable[[FlightAnalysis, Path], tuple[bytes, bytes]]
ReportRenderer = Callable[[FlightAnalysis, str, Path, tuple[bytes, bytes]], Path]

# Purpose: Notice cancellation without busy-waiting while PDF children run.
# Type: float; Units: seconds; Scope: one language-render batch.
# Consumers: render_report_variants. Rationale: 0.1 seconds keeps cancellation
# responsive; already-running atomic renders finish and remain recoverable.
RENDER_POLL_SECONDS = 0.1


def render_report_variants(
    analysis: FlightAnalysis, destinations: dict[str, Path],
    cancel: threading.Event | None = None,
    on_started: Callable[[str], None] | None = None,
    on_completed: Callable[[str, Path], None] | None = None,
    *,
    prepare_maps: ReportMaps,
    render: ReportRenderer,
) -> dict[str, Path]:
    """Run all selected language editions concurrently and join before returning.

    Args:
        analysis (FlightAnalysis): Shared serializable recording and dispatch.
        destinations (dict[str, Path]): Supported language keys mapped to unique
            reserved PDF paths under one common output directory.
        cancel (threading.Event | None): Optional parent-side cancellation signal.
        on_started (Callable[[str], None] | None): Parent-thread language-start hook.
        on_completed (Callable[[str, Path], None] | None): Parent-thread hook for
            each successful PDF, including work completed during cancellation.
        prepare_maps (ReportMaps): Render overview and full PNG bytes once.
        render (ReportRenderer): Spawn-safe renderer for one language edition.
    Returns:
        dict[str, Path]: Complete PDFs in requested language order, after every
            child exits. Partial sets are never returned as complete success.
    Raises:
        ValueError: Languages, duplicate/redirected paths or output roots differ.
        InterruptedError: Cancellation joined all children and retained finished
            PDFs; no delivery or credential operation occurs in this service.
        Exception: Preparation, a renderer or a completion hook failed; other
            running children are drained and their finished artifacts retained.
    """
    if not destinations or any(code not in REPORT_LANGUAGES for code in destinations):
        raise ValueError("Select supported report languages before rendering.")
    paths = list(destinations.values())
    if any(path.is_symlink() or path.parent.is_symlink()
           or path.suffix.lower() != ".pdf" for path in paths):
        raise ValueError("Report destinations must be confined PDF files.")
    if len({path.resolve() for path in paths}) != len(paths):
        raise ValueError("Each report language needs a unique destination.")
    roots = {path.parent.resolve() for path in paths}
    if len(roots) != 1:
        raise ValueError("A report batch must use one output directory.")
    if cancel is not None and cancel.is_set():
        raise InterruptedError("PDF creation cancelled; finished reports retained.")
    maps = prepare_maps(analysis, paths[0].parent)
    if cancel is not None and cancel.is_set():
        raise InterruptedError("PDF creation cancelled; finished reports retained.")
    results: dict[str, Path] = {}
    failure: Exception | None = None
    # The supported language set bounds the pool to four processes. Spawn keeps
    # GUI threads, native simulator handles, credentials and font registries out
    # of child state and works with the launcher's existing freeze_support path.
    with ProcessPoolExecutor(
        max_workers=len(destinations),
        mp_context=multiprocessing.get_context("spawn"),
    ) as pool:
        pending = {}
        try:
            for language, destination in destinations.items():
                future = pool.submit(render, analysis, language, destination, maps)
                pending[future] = language
                if on_started is not None:
                    on_started(language)
        except Exception as exc:
            failure = exc
        while pending:
            stopping = failure is not None or (cancel is not None and cancel.is_set())
            if stopping:
                for future in pending:
                    future.cancel()
            pending = {future: code for future, code in pending.items()
                       if not future.cancelled()}
            if not pending:
                break
            finished, _remaining = wait(
                pending, timeout=RENDER_POLL_SECONDS, return_when=FIRST_COMPLETED,
            )
            for future in finished:
                language = pending.pop(future)
                try:
                    pdf = future.result()
                    expected = destinations[language]
                    if pdf.resolve() != expected.resolve() or pdf.is_symlink() or not pdf.is_file():
                        raise ValueError("Renderer returned an unexpected report file.")
                    results[language] = pdf
                    if on_completed is not None:
                        on_completed(language, pdf)
                except Exception as exc:
                    if failure is None:
                        failure = exc
    if failure is not None:
        raise failure
    if cancel is not None and cancel.is_set():
        raise InterruptedError("PDF creation cancelled; finished reports retained.")
    if len(results) != len(destinations):
        raise RuntimeError("The complete language report set was not produced.")
    return {language: results[language] for language in destinations}
