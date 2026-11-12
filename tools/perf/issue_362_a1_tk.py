# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: tools/perf/issue_362_a1_tk.py
# Module Type: Disposable Windows Tk Benchmark
# Purpose: Measure bounded native Treeview mounting in GitHub-hosted Windows.
# Responsibilities:
#   - Create an isolated synthetic Tk tree without an API identity.
#   - Record callback slice latency and first/complete tree display timings.
#   - Separate generic Tk cost from the real Photos viewer regression suite.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)

"""Bounded Tkinter Treeview microbenchmark for issue #362 A1 (synthetic only)."""

import json
import math
import os
from pathlib import Path
import statistics
import sys
import time
import tkinter as tk
from tkinter import ttk
from datetime import datetime, timezone


# Purpose: Cover the existing tree-v1 row capacities with synthetic Tk widgets.
# Type: tuple[int, ...]. Units: rows. Scope: A1 Windows-only benchmark.
# Consumers: main. Rationale: 500, 2000, 10000 match the A1 acceptance matrix.
A1_ROW_COUNTS = (500, 2000, 10000)

# Purpose: Mirror the currently defined local Photos catalogue mount batch size.
# Type: int. Units: rows per callback. Scope: one generic Treeview fixture.
# Consumers: run_tree_fixture. Rationale: the current Photos mount uses 64.
A1_BATCH_SIZE = 64

# Purpose: Mirror Photos' paced Tk Treeview row mount cadence.
# Type: int. Units: milliseconds. Scope: callback scheduling.
# Consumers: run_tree_fixture. Rationale: the Photos mount uses 16 ms.
A1_SLICE_DELAY_MS = 16

# Purpose: Bound each native UI test's measured repetitions.
# Type: int. Units: trials. Scope: A1 Windows Tk fixture.
# Consumers: main. Rationale: one warmup plus three observations.
A1_TRIAL_COUNT = 3


def nearest_rank(samples: list[float], percentile: float) -> float:
    """Return a percentile from individually measured callback durations.

    Args:
        samples (list[float]): Nonempty measured callback times in milliseconds.
        percentile (float): Probability from zero through one, inclusive.

    Returns:
        float: Observed nearest-rank percentile; never derived from totals.
    """
    if not samples:
        raise ValueError("No callback samples.")
    ordered = sorted(samples)
    rank = max(0, min(len(ordered) - 1, int(math.ceil(percentile * len(ordered)) - 1)))
    return ordered[rank]


def run_tree_fixture(row_count: int, trial: int) -> dict[str, object]:
    """Mount a synthetic 64-row-per-callback Treeview on the Tk owner thread.

    Args:
        row_count (int): Exact synthetic row count to insert.
        trial (int): Nonnegative trial identifier, zero is a discarded warmup.

    Returns:
        dict[str, object]: Validated bounded Tk metrics with phase durations.

    Raises:
        RuntimeError: When the fixture failed to mount all requested rows.
    """
    window = tk.Tk()
    window.geometry("900x650")
    window.title("PHP Gallery A1 isolated Tk fixture")
    tree = ttk.Treeview(window, columns=("title",), show="tree")
    tree.pack(fill="both", expand=True)
    window.update()

    durations: list[float] = []
    inserted = [0]
    first_row_ms = [None]
    total_ms = [None]
    errors: list[str] = []
    started = time.perf_counter()

    def mount_slice() -> None:
        """Insert one bounded row slice and yield the thread to Tk afterward.

        Returns:
            None: Schedules the next slice or exits the current Tcl event loop.
        """
        try:
            slice_started = time.perf_counter()
            stop = min(row_count, inserted[0] + A1_BATCH_SIZE)
            while inserted[0] < stop:
                number = inserted[0]
                tree.insert("", "end", iid=str(number),
                            text="Synthetic gallery " + str(number))
                inserted[0] += 1
            duration = (time.perf_counter() - slice_started) * 1000
            durations.append(duration)
            if first_row_ms[0] is None and inserted[0] > 0:
                first_row_ms[0] = (time.perf_counter() - started) * 1000
            if inserted[0] < row_count:
                window.after(A1_SLICE_DELAY_MS, mount_slice)
            else:
                total_ms[0] = (time.perf_counter() - started) * 1000
                window.quit()
        except (tk.TclError, ValueError, RuntimeError) as error:
            errors.append(type(error).__name__)
            window.quit()

    window.after(0, mount_slice)
    try:
        window.mainloop()
        if errors or inserted[0] != row_count or total_ms[0] is None:
            raise RuntimeError("Native Tk fixture did not mount every row.")
        return {
            "rows": row_count, "trial": trial, "slices": len(durations),
            "first_row_ms": round(float(first_row_ms[0]), 6),
            "complete_ms": round(float(total_ms[0]), 6),
            "slice_total_ms": round(sum(durations), 6),
            "slice_p50_ms": round(nearest_rank(durations, 0.50), 6),
            "slice_p95_ms": round(nearest_rank(durations, 0.95), 6),
            "slice_p99_ms": round(nearest_rank(durations, 0.99), 6),
            "slice_max_ms": round(max(durations), 6),
        }
    finally:
        window.destroy()


def utc_now_seconds() -> str:
    """Return a genuine current wall-clock timestamp with a UTC Z suffix.

    Returns:
        str: ISO 8601 event timestamp, seconds precision in UTC.
    """
    return datetime.now(timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ")


def main() -> int:
    """Run bounded synthetic Treeview samples on hosted Windows.

    Returns:
        int: Zero after report publication; errors propagate as nonzero exit.
    """
    if sys.platform != "win32":
        raise RuntimeError("Run the native Treeview fixture on Windows only.")
    started_utc = utc_now_seconds()
    records = []
    for count in A1_ROW_COUNTS:
        for trial in range(A1_TRIAL_COUNT + 1):
            metrics = run_tree_fixture(count, trial)
            if trial != 0:
                records.append(metrics)
    result = {
        "schema": "php-gallery-a1-tk-v1",
        "started_utc": started_utc,
        "ended_utc": utc_now_seconds(),
        "head_sha": os.getenv("GITHUB_SHA", "UNKNOWN"),
        "platform": sys.platform,
        "python": sys.version.split()[0],
        "scope": "synthetic Tk Treeview, not the production Photos widget",
        "warmups_discarded": 1,
        "records": records,
        "limits": [
            "No PHP/SQL/HTTP/thumbnail data are present in these Tk samples.",
            "No Windows-to-PHP end-to-end benchmark is implied.",
            "Measured slices contain only insertion, not Photos reconciliation.",
            "Callback scheduling includes hosted Windows runner jitter.",
        ],
    }
    path = Path("cache/test-audit/issue-362-a1-tk.json")
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(json.dumps(result, indent=2) + "\n", encoding="utf-8")
    print("PASS A1 Windows synthetic Tk benchmark; saved " + str(path))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
