# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: tools/perf/issue_399_tk_plot.py
# Module Type: Post-Run Performance Report Plotter
# Purpose: Plot existing issue 399 paired-comparison statistics.
# Responsibilities:
#   - Read the central audit's already validated compare output
#   - Plot its paired means and existing 95 percent confidence intervals
#   - Save a paired-delta chart and all-metric Czech report alongside audit output
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)
"""Render issue 399 paired comparison charts without recomputing statistics."""

import argparse
import json
import math
import sys
from pathlib import Path
from typing import TypedDict, cast

import matplotlib
matplotlib.use('Agg')
import matplotlib.pyplot as plt

from issue_361_tk_benchmark import ComparisonSummary


# Purpose: Preserve the current compare protocol's run-pair threshold.
# Type: int. Units: independent process pairs per scenario/cache cell.
# Scope: plotted issue 399 comparison. Consumers: validate_report.
# Rationale: The qualified comparison protocol requires ten pairs per cell.
MINIMUM_PAIRS = 10

# Purpose: Select the seven requested GUI and resource metrics.
# Type: dict[str, tuple[str, str]]. Units: metric key, title and display unit.
# Scope: every present scenario/cache group. Consumers: render_comparison.
# Rationale: Missing metrics fail by key lookup; the plot never inserts zeroes.
PLOT_METRICS: dict[str, tuple[str, str]] = {
    'wheel_slow_settled_p95_ms': ('Slow wheel to viewport settled', 'ms'),
    'wheel_fast_settled_p95_ms': ('Fast wheel to viewport settled', 'ms'),
    'drag_settled_p95_ms': ('Drag to viewport settled', 'ms'),
    'viewport_settle_p95_ms': ('Viewport settlement', 'ms'),
    'interaction_feedback_p95_ms': ('Input feedback to Tk idle', 'ms'),
    'process_cpu_ms': ('Measured interaction CPU time', 'ms'),
    'process_peak_rss_bytes': ('Process peak working set', 'bytes'),
}

# Purpose: Fix the report panel arrangement and readable export size.
# Type: tuple[int, int, float, float]. Units: rows, columns, figure inches.
# Scope: one comparison figure. Consumers: render_comparison.
# Rationale: Seven panels fit in four rows and two columns.
FIGURE_LAYOUT = (4, 2, 16.0, 18.0)

# Purpose: Keep exported raster previews readable in GitHub artifacts.
# Type: int. Units: dots per inch. Scope: PNG output.
# Consumers: render_comparison. Rationale: Retain legible labels when previewed.
PNG_DPI = 160

# Purpose: Define interval, mean marker and no-change reference styling.
# Type: tuple[float, float, float]. Units: points. Scope: all metric panels.
# Consumers: render_comparison. Rationale: Distinguish interval, mean and reference.
LINE_AND_MARKER_SIZES = (2.0, 5.0, 1.0)

# Purpose: Keep grid guides subtle behind plotted intervals.
# Type: float. Units: alpha fraction. Scope: metric panels.
# Consumers: render_comparison. Rationale: Aid reading without masking data.
GRID_ALPHA = 0.25

# Purpose: Mark the no-change point on each candidate-minus-baseline axis.
# Type: float. Units: metric units. Scope: every plotted panel.
# Consumers: render_comparison. Rationale: Zero is the paired-delta reference.
ZERO_DELTA = 0.0

# Purpose: Reserve title space for source identity and method.
# Type: tuple[float, float, float, float]. Units: figure fractions.
# Scope: one comparison figure. Consumers: render_comparison.
# Rationale: Separate provenance text from the metric panels.
LAYOUT_RECT = (0.0, 0.0, 1.0, 0.90)

# Purpose: Keep numeric precision consistent in the generated Czech report.
# Type: int. Units: digits after the decimal point. Scope: report tables.
# Consumers: format_number. Rationale: Four places retain meaningful GUI timings.
REPORT_DECIMAL_PLACES = 4


class PlotReport(TypedDict):
    """Describe report fields required by the post-run plotter."""

    status: str
    stage: str
    targeted: bool
    valid_records: int
    required_records: int
    baseline_sha: str
    candidate_sha: str
    comparison: ComparisonSummary


def find_latest_report(audit_root: Path) -> Path:
    """Find the latest report beneath the central audit output directory.

    Args:
        audit_root (Path): Root containing timestamped audit result directories.

    Returns:
        Path: The latest issue 399 report.json written by the registered suite.

    Raises:
        FileNotFoundError: No report exists under the audit root.
    """
    reports = [
        directory / 'issue-361' / 'report.json'
        for directory in audit_root.iterdir()
        if directory.is_dir()
        and not directory.is_symlink()
        and (directory / 'issue-361' / 'report.json').is_file()
    ]
    if not reports:
        raise FileNotFoundError(f'No benchmark report found under {audit_root}.')
    return max(reports, key=lambda path: path.stat().st_mtime_ns)


def read_report(path: Path) -> PlotReport:
    """Read the report using the concrete central-audit output shape.

    Args:
        path (Path): Report.json path from the selected audit directory.

    Returns:
        PlotReport: Driver report fields consumed by this chart exporter.
    """
    return cast(PlotReport, json.loads(path.read_text(encoding='utf-8')))


def read_comparison(path: Path) -> ComparisonSummary:
    """Read the driver's existing typed scenario/cache comparison output.

    Args:
        path (Path): comparison.json path adjacent to the report.

    Returns:
        ComparisonSummary: Precomputed paired comparison statistics.
    """
    return cast(ComparisonSummary, json.loads(path.read_text(encoding='utf-8')))


def validate_report(report: PlotReport, comparison: ComparisonSummary) -> None:
    """Require successful, complete compare output consistent across both files.

    Args:
        report (PlotReport): Central audit report already validated by its owner.
        comparison (ComparisonSummary): Driver-generated paired comparison.

    Returns:
        None: The report and comparison are safe to plot.

    Raises:
        ValueError: The compare report is incomplete or inconsistent.
    """
    if (
        report['status'] != 'PASS'
        or report['stage'] != 'compare'
        or report['targeted']
        or report['valid_records'] != report['required_records']
        or report['comparison'] != comparison
    ):
        raise ValueError('Plots require one complete PASS compare report.')
    if not comparison:
        raise ValueError('Comparison contains no scenario/cache groups.')
    for group_name, group in comparison.items():
        if group['pairs'] < MINIMUM_PAIRS:
            raise ValueError(f'{group_name} has fewer than ten process pairs.')
        for metric_name in PLOT_METRICS:
            metric = group['metrics'][metric_name]
            interval = metric['paired_delta_ci95']
            if (
                not math.isfinite(metric['absolute_delta_mean'])
                or len(interval) != 2
                or not all(math.isfinite(bound) for bound in interval)
                or interval[0] > interval[1]
            ):
                raise ValueError(f'{group_name}/{metric_name} has invalid statistics.')


def format_number(value: float | None) -> str:
    """Format an existing numeric result or explicitly label it unavailable.

    Args:
        value (float | None): Precomputed metric, ratio or percentage.

    Returns:
        str: Fixed-precision number, or N/A for an undefined ratio.
    """
    if value is None:
        return 'N/A'
    return f'{value:.{REPORT_DECIMAL_PLACES}f}'


def write_czech_report(
    report: PlotReport,
    comparison: ComparisonSummary,
    output_path: Path,
) -> Path:
    """Write all precomputed comparison metrics as a Czech Markdown artifact.

    Args:
        report (PlotReport): Validated report metadata and exact source identities.
        comparison (ComparisonSummary): Driver-generated scenario/cache statistics.
        output_path (Path): Destination for comparison-report-cs.md.

    Returns:
        Path: The UTF-8 Markdown report path.
    """
    lines = [
        '# Issue 399: porovnání výkonu',
        '',
        f"- **A — baseline:** `{report['baseline_sha']}`",
        f"- **B — candidate:** `{report['candidate_sha']}`",
        f"- **Dokončené / požadované procesy:** "
        f"{report['valid_records']} / {report['required_records']}",
        '',
        'Hodnoty pocházejí z předem vypočteného souhrnu nezávislých procesů. '
        'U latencí p95 je průměr průměrem procesních percentilů p95; události '
        'se neslučují do jednoho vzorku. Delta je B − A. Interval 95 % je '
        'předem vypočtený párový bootstrap interval z benchmark driveru; '
        'zde se statistiky nepřepočítávají.',
        '',
        'Zobrazené interakční latence jsou proxy Tk idle/settlement. '
        'Fyzická prezentační latence ani přístupnost nebyly tímto měřením '
        'ověřeny.',
        '',
        'Úplná data a surové podrobnosti: [comparison.json](../comparison.json).',
    ]
    for group_name, group in comparison.items():
        lines.extend([
            '',
            f'## {group_name}',
            '',
            f"Počet párových procesů: **{group['pairs']}**.",
            '',
            '| Metrika | A průměr | B průměr | Změna % | Delta B − A | '
            '95% CI delta | Poměr B/A |',
            '| --- | ---: | ---: | ---: | ---: | ---: | ---: |',
        ])
        for metric_name, metric in group['metrics'].items():
            lower, upper = metric['paired_delta_ci95']
            interval = f'[{format_number(lower)}, {format_number(upper)}]'
            lines.append(
                f"| {metric_name} "
                f"| {format_number(metric['baseline']['mean'])} "
                f"| {format_number(metric['candidate']['mean'])} "
                f"| {format_number(metric['percent_delta_mean'])} "
                f"| {format_number(metric['absolute_delta_mean'])} "
                f"| {interval} "
                f"| {format_number(metric['candidate_to_baseline_ratio'])} |"
            )
    output_path.parent.mkdir(parents=True, exist_ok=True)
    output_path.write_text('\n'.join(lines) + '\n', encoding='utf-8')
    return output_path


def render_comparison(
    comparison: ComparisonSummary,
    baseline_sha: str,
    candidate_sha: str,
    output_directory: Path,
) -> tuple[Path, Path]:
    """Render only the paired means and confidence bounds already in the report.

    Args:
        comparison (ComparisonSummary): Driver-computed statistics to display.
        baseline_sha (str): Exact source SHA for side A.
        candidate_sha (str): Exact source SHA for side B.
        output_directory (Path): Directory receiving the PNG and SVG.

    Returns:
        tuple[Path, Path]: Paths of the generated PNG and SVG figures.
    """
    groups = list(comparison.items())
    labels = [
        f'{name.replace("/", " / ")} (n={group["pairs"]})'
        for name, group in groups
    ]
    rows, columns, width, height = FIGURE_LAYOUT
    figure, axes = plt.subplots(rows, columns, figsize=(width, height), squeeze=False)
    interval_width, marker_size, reference_width = LINE_AND_MARKER_SIZES
    for panel, (metric_name, (title, unit)) in enumerate(PLOT_METRICS.items()):
        axis = axes.flat[panel]
        axis.axvline(
            ZERO_DELTA, color='#555555', linewidth=reference_width,
            linestyle='--',
        )
        for position, (_, group) in enumerate(groups):
            metric = group['metrics'][metric_name]
            mean = metric['absolute_delta_mean']
            lower, upper = metric['paired_delta_ci95']
            axis.hlines(
                position, lower, upper, color='#3973ac', linewidth=interval_width,
            )
            axis.plot(
                mean, position, marker='o', color='#b33b32',
                markersize=marker_size,
            )
        axis.set_yticks(range(len(groups)), labels)
        axis.invert_yaxis()
        axis.set_title(f'{title} ({unit})')
        axis.set_xlabel(f'Candidate − baseline ({unit}); lower is generally better')
        axis.grid(axis='x', alpha=GRID_ALPHA)
    axes.flat[-1].set_visible(False)
    figure.suptitle(
        'Issue 399 paired comparison: mean delta and existing driver 95% CI\n'
        f'A baseline {baseline_sha} | B candidate {candidate_sha}\n'
        'Intervals are driver-computed paired bootstrap percentile bounds. '
        'Physical presentation latency was not measured.',
    )
    figure.tight_layout(rect=LAYOUT_RECT)
    output_directory.mkdir(parents=True, exist_ok=True)
    png_path = output_directory / 'paired-comparison.png'
    svg_path = output_directory / 'paired-comparison.svg'
    figure.savefig(png_path, dpi=PNG_DPI)
    figure.savefig(svg_path, format='svg')
    plt.close(figure)
    return png_path, svg_path


def main() -> int:
    """Export charts for the latest complete compare report.

    Returns:
        int: Zero after chart export, or one when report data is invalid.
    """
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument(
        '--audit-root', required=True, type=Path,
        help='Central cache/test-audit directory for the completed compare run.',
    )
    arguments = parser.parse_args()
    try:
        report_path = find_latest_report(arguments.audit_root)
        report = read_report(report_path)
        comparison = read_comparison(report_path.with_name('comparison.json'))
        validate_report(report, comparison)
        png_path, svg_path = render_comparison(
            comparison,
            report['baseline_sha'],
            report['candidate_sha'],
            report_path.parent / 'charts',
        )
        write_czech_report(
            report, comparison, report_path.parent / 'charts' / 'comparison-report-cs.md',
        )
    except (KeyError, OSError, ValueError) as error:
        print(f'Issue 399 comparison plot export failed: {error}', file=sys.stderr)
        return 1
    print(f'Wrote comparison plots: {png_path} and {svg_path}')
    return 0


if __name__ == '__main__':
    raise SystemExit(main())
