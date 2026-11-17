# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/tests/test_tk_performance_protocol.py
# Module Type: Tk Performance Protocol Regression Tests
# Purpose: Protect experimental validity and paired statistics for issue 361.
# Responsibilities:
#   - Reject mismatched child identity and invalid required observations
#   - Preserve optional physical-presentation gaps and instrumentation-off data
#   - Reject incomplete, duplicate and mismatched independent trial pairs
#   - Verify known paired confidence bounds and baseline/candidate ratios
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)
"""Portable checks for the Tk benchmark's evidence and comparison contract."""

import copy
import csv
import importlib.util
import json
from pathlib import Path
import sys
import tempfile
from unittest import mock
from types import ModuleType, SimpleNamespace
import unittest
from typing import TypedDict, cast


# Purpose: Set the minimum complete-pair vector accepted by the comparison fixture.
# Type: int.
# Units: independent process trial pairs.
# Scope: synthetic issue 361 comparison regressions.
# Consumers: TkPerformanceProtocolTests._comparison_records().
# Value: 10.
# Rationale: exercise the reviewed ten-pair minimum with deterministic known deltas.
EXPECTED_PAIR_COUNT = 10

# Purpose: Bind test reports to the current benchmark child schema.
# Type: int.
# Units: schema version.
# Scope: synthetic issue 361 child reports.
# Consumers: TkPerformanceProtocolTests._make_child_report().
# Value: 1.
# Rationale: detect accidental report contract changes alongside driver updates.
REPORT_SCHEMA_VERSION = 1

# Purpose: Bind synthetic child reports to the approved deterministic fixture seed.
# Type: int.
# Units: random seed value.
# Scope: every synthetic issue 361 child report.
# Consumers: TkPerformanceProtocolTests._make_child_report() and the seed guard.
# Value: 3612026.
# Rationale: ensure summary and validator fixtures use the protocol's fixed workload.
EXPECTED_WORKLOAD_SEED = 3612026

# Purpose: Use zero-based independent trial identities like the benchmark schedule.
# Type: int.
# Units: trial identifier.
# Scope: synthetic issue 361 child and comparison records.
# Consumers: TkPerformanceProtocolTests._make_child_report() and
# TkPerformanceProtocolTests._comparison_records().
# Value: 0.
# Rationale: match run_stage()'s zero-based independent process identities.
FIRST_FIXTURE_TRIAL = 0

# Purpose: Keep all valid synthetic duration metrics positive and distinct by trial.
# Type: float.
# Units: milliseconds.
# Scope: synthetic child and paired comparison reports.
# Consumers: TkPerformanceProtocolTests._make_child_report() and
# TkPerformanceProtocolTests._comparison_records().
# Value: 1.0.
# Rationale: a positive starting observation avoids a zero denominator in ratio checks.
FIXTURE_METRIC_BASE_MS = 1.0

# Purpose: Preserve a concrete optional dispatch delay through validation.
# Type: int. Units: nanoseconds. Scope: combined callback-record regression.
# Consumers: test_combined_callback_event_extras_are_validated_and_preserved.
# Rationale: 125000 is positive and distinct from the generic elapsed fixture.
FIXTURE_DISPATCH_DELAY_NS = 125000

# Purpose: Set the known paired candidate-minus-baseline fixture delta.
# Type: float.
# Units: milliseconds.
# Scope: exact paired confidence interval regression.
# Consumers: TkPerformanceProtocolTests.test_constant_delta_has_exact_paired_ci().
# Value: 7.0.
# Rationale: every synthetic pair uses this same exactly representable delta.
KNOWN_DELTA_MS = 7.0

# Purpose: Set the known candidate-to-baseline fixture multiplier.
# Type: float.
# Units: unitless ratio.
# Scope: independent-source comparison regression.
# Consumers: TkPerformanceProtocolTests.test_ratio_uses_baseline_over_candidate_means().
# Value: 2.0.
# Rationale: a doubled candidate mean must produce a ratio of exactly two.
CANDIDATE_RATIO = 2.0

# Purpose: Set the expected baseline mean divided by the doubled candidate mean.
# Type: float.
# Units: unitless ratio.
# Scope: baseline-to-candidate statistic regression.
# Consumers: TkPerformanceProtocolTests.test_ratio_uses_baseline_over_candidate_means().
# Value: 0.5.
# Rationale: a two-times candidate duration makes baseline/candidate equal one half.
BASELINE_TO_CANDIDATE_RATIO = 0.5

# Structural protocol matrix: four named workloads and two cache-state labels.
# Purpose: Exercise issue 399 without changing issue 361 default expectations.
# Type: tuple[str, ...].
# Units: scenario identifiers.
# Scope: synthetic issue 399 scroll matrix regressions.
# Consumers: TkPerformanceProtocolTests test methods.
# Rationale: Match the four protocol extents while isolating the legacy matrix.
ISSUE_399_SCENARIOS = (
    'scroll100', 'scroll500', 'scroll2000', 'scroll5000',
)

EXPECTED_SCENARIOS = ('dashboard', 'import', 'gallery', 'lightbox')
EXPECTED_CACHE_STATES = ('cold', 'warm')
# Forty hexadecimal characters are structural synthetic Git SHA-1 identities.
BASELINE_SOURCE_SHA = '1' * 40
CANDIDATE_SOURCE_SHA = '2' * 40
# Purpose: Set the expected process count for the two-scenario targeted pilot.
# Type: int.
# Units: independent workload processes.
# Scope: gallery/lightbox targeted pilot filter regression.
# Consumers: TkPerformanceProtocolTests.test_targeted_pilot_filter_is_canonical_and_incomplete().
# Value: 8.
# Rationale: two selected scenarios, two cache states, and two modes require eight children.
TARGETED_PILOT_RECORDS = 8

# Purpose: Set the number of independently committed checkpoint fixtures.
# Type: int. Units: child records. Scope: incremental evidence regression.
# Consumers: checkpoint save/export protocol tests.
# Value: 2. Rationale: two calls prove new rows append without rewriting prior ones.
CHECKPOINT_RECORD_COUNT = 2

# Purpose: Keep each checkpoint fixture's raw-event batch small and distinct.
# Type: int. Units: raw events per process. Scope: incremental CSV regression.
# Consumers: checkpoint save/export protocol tests.
# Value: 3. Rationale: retain all required dashboard probes and prove batch ownership.
CHECKPOINT_EVENTS_PER_RECORD = 3

# Purpose: Define a stable nonzero elapsed value in checkpoint fixture records.
# Type: float. Units: seconds. Scope: progress JSONL fixture rows.
# Consumers: checkpoint save/export protocol tests.
# Value: 0.25. Rationale: a finite process duration survives CSV and JSONL output.
CHECKPOINT_ELAPSED_SECONDS = 0.25

# Purpose: Represent successful child exit in checkpoint fixture records.
# Type: int. Units: process exit code. Scope: valid synthetic child records.
# Consumers: checkpoint save/export protocol tests.
# Value: 0. Rationale: valid workload reports in this fixture exited successfully.
CHECKPOINT_SUCCESS_RETURN_CODE = 0

VALID_METRIC_NAMES = (
    'event_loop_drift_p95_ms',
    'callback_p95_ms',
    'interaction_feedback_p95_ms',
    'image_publication_p95_ms',
)


class ChildReportFixture(TypedDict):
    """Describe the complete synthetic child report passed to validation."""

    schema_version: int
    status: str
    scenario: str
    cache: str
    trial: int
    seed: int
    source: str
    metrics: dict[str, float]
    operations: dict[str, int]
    resources: dict[str, float | int | str | bool | None]
    metadata: dict[str, float | int | str | bool | None]
    raw_events: list[dict[str, str | int]]
    raw_events_truncated: int
    failure: str | None
    missing_metrics: list[str]
    missing_required_metrics: list[str]


class RunRecordFixture(TypedDict):
    """Describe one synthetic independent process record for evidence export."""

    source: str
    source_sha: str
    scenario: str
    cache: str
    instrumentation: str
    trial: int
    elapsed_seconds: float
    returncode: int
    status: str
    report: ChildReportFixture


class ProgressCheckpointFixture(TypedDict):
    """Describe one compact progress line from a completed child record."""

    source: str
    source_sha: str
    scenario: str
    cache: str
    instrumentation: str
    trial: int
    elapsed_seconds: float
    returncode: int
    status: str
    raw_event_count: int


def _load_benchmark_driver() -> ModuleType:
    """Load the benchmark driver from the repository checkout.

    Returns:
        ModuleType: Importable issue 361 benchmark driver module.

    Raises:
        ImportError: If the tracked driver source is unavailable or unloadable.
    """
    repository_root = Path(__file__).resolve().parents[2]
    driver_path = (
        repository_root
        / 'tools'
        / 'perf'
        / 'issue_361_tk_benchmark.py'
    )
    spec = importlib.util.spec_from_file_location(
        'php_gallery_issue_361_tk_benchmark',
        driver_path,
    )
    if spec is None or spec.loader is None:
        raise ImportError(f'Cannot load benchmark driver from {driver_path}.')
    module = importlib.util.module_from_spec(spec)
    sys.modules[spec.name] = module
    spec.loader.exec_module(module)
    return module


BENCHMARK = _load_benchmark_driver()


class TkPerformanceProtocolTests(unittest.TestCase):
    """Verify report admission and independent paired comparison semantics."""

    def test_targeted_pilot_filter_is_canonical_and_incomplete(self) -> None:
        """Validate targeted pilot ordering, cell count, and invalid subsets.

        Returns:
            None: The filter contract assertions complete.
        """
        selected = BENCHMARK.select_pilot_scenarios(['lightbox', 'gallery'])

        self.assertEqual(selected, ('gallery', 'lightbox'))
        self.assertEqual(
            len(BENCHMARK.matrix_cells(selected)) * 2,
            TARGETED_PILOT_RECORDS,
        )
        self.assertNotEqual(selected, BENCHMARK.SCENARIOS)
        for invalid in ([], ['gallery', 'gallery'], ['unknown']):
            with self.subTest(invalid=invalid):
                with self.assertRaises(ValueError):
                    BENCHMARK.select_pilot_scenarios(invalid)

    def setUp(self) -> None:
        """Initialize stable source identities used by each test.

        Returns:
            None: The fixture setup or behavioral assertions complete.
        """
        self.baseline_sha = BASELINE_SOURCE_SHA
        self.candidate_sha = CANDIDATE_SOURCE_SHA

    def _make_child_report(
        self,
        scenario: str = 'dashboard',
        cache: str = 'cold',
        trial: int = FIRST_FIXTURE_TRIAL,
        source_sha: str = BASELINE_SOURCE_SHA,
        instrumentation: str = 'on',
    ) -> ChildReportFixture:
        """Build one complete deterministic report for a protocol case.

        Args:
            scenario (str): Expected scenario; defaults to dashboard.
            cache (str): Expected cache state; defaults to cold.
            trial (int): Expected zero-based process identity; defaults to its first trial.
            source_sha (str): Application SHA; defaults to the synthetic baseline.
            instrumentation (str): Callback mode; defaults to on.

        Returns:
            ChildReportFixture: Synthetic report with mode-appropriate metrics.
        """
        metric_names = list(VALID_METRIC_NAMES)
        if scenario not in ('gallery', 'lightbox') and not scenario.startswith('scroll'):
            metric_names = metric_names[:-1]
        if scenario.startswith('scroll'):
            metric_names.extend([
                'viewport_settle_p95_ms',
                'wheel_slow_p95_ms',
                'wheel_fast_p95_ms',
                'drag_p95_ms',
                'resize_p95_ms',
            ])
        if instrumentation == 'off':
            metrics = {
                'scenario_elapsed_ms': (
                    FIXTURE_METRIC_BASE_MS
                    + float(trial - FIRST_FIXTURE_TRIAL)
                ),
            }
            raw_events: list[dict[str, str | int]] = []
        else:
            metrics = {
                name: (
                    FIXTURE_METRIC_BASE_MS
                    + float(trial - FIRST_FIXTURE_TRIAL)
                )
                for name in metric_names
            }
            # Child event IDs omit the summary metric's `_p95_ms` suffix.
            # Zero is the valid timestamp boundary for synthetic event vectors.
            raw_events = [
                {'metric': name.removesuffix('_p95_ms'), 'ns': 0, 'phase': 'measured'}
                for name in metric_names
            ]
        return {
            'schema_version': REPORT_SCHEMA_VERSION,
            'status': 'PASS',
            'scenario': scenario,
            'cache': cache,
            'trial': trial,
            'seed': EXPECTED_WORKLOAD_SEED,
            'source': source_sha,
            'metrics': metrics,
            'operations': {},
            'resources': {},
            'metadata': {'instrumentation': instrumentation},
            'raw_events': raw_events,
            'raw_events_truncated': 0,
            'failure': None,
            'missing_metrics': [],
            'missing_required_metrics': [],
        }

    def _validate_child_report(
        self,
        report: ChildReportFixture,
        scenario: str = 'dashboard',
        cache: str = 'cold',
        trial: int = FIRST_FIXTURE_TRIAL,
        source_sha: str = BASELINE_SOURCE_SHA,
        instrumentation: str = 'on',
    ) -> ChildReportFixture:
        """Submit a report with the exact requested identity and mode.

        Args:
            report (ChildReportFixture): Synthetic child JSON evidence.
            scenario (str): Expected scenario; defaults to dashboard.
            cache (str): Expected cache state; defaults to cold.
            trial (int): Expected zero-based process identity; defaults to its first trial.
            source_sha (str): Expected SHA; defaults to the synthetic baseline.
            instrumentation (str): Expected mode; defaults to on.

        Returns:
            ChildReportFixture: Validated report.

        Raises:
            RuntimeError: If the driver rejects the supplied evidence.
        """
        return BENCHMARK.validate_report(
            report,
            scenario,
            cache,
            trial,
            source_sha,
            instrumentation,
        )

    def _comparison_records(
        self,
        candidate_scale: float = 1.0,
        candidate_delta_ms: float = 0.0,
    ) -> list[RunRecordFixture]:
        """Build full-matrix independent baseline/candidate process pairs.

        Args:
            candidate_scale (float): Candidate multiplier; defaults to 1.0.
            candidate_delta_ms (float): Candidate offset; defaults to 0.0 milliseconds.

        Returns:
            list[RunRecordFixture]: Ten pair identities for every protocol cell.
        """
        records: list[RunRecordFixture] = []
        for scenario in EXPECTED_SCENARIOS:
            for cache in EXPECTED_CACHE_STATES:
                for trial in range(
                    FIRST_FIXTURE_TRIAL,
                    FIRST_FIXTURE_TRIAL + EXPECTED_PAIR_COUNT,
                ):
                    for source_label, source_sha in (
                        ('baseline', self.baseline_sha),
                        ('candidate', self.candidate_sha),
                    ):
                        baseline_value = (
                            FIXTURE_METRIC_BASE_MS
                            + float(trial - FIRST_FIXTURE_TRIAL)
                        )
                        observed_value = (
                            baseline_value
                            if source_label == 'baseline'
                            else (
                                baseline_value * candidate_scale
                                + candidate_delta_ms
                            )
                        )
                        report = self._make_child_report(
                            scenario=scenario,
                            cache=cache,
                            trial=trial,
                            source_sha=source_sha,
                            instrumentation='off',
                        )
                        report['metrics'] = {
                            'scenario_elapsed_ms': observed_value,
                        }
                        records.append({
                            'source': source_label,
                            'source_sha': source_sha,
                            'scenario': scenario,
                            'cache': cache,
                            'instrumentation': 'off',
                            'trial': trial,
                            'status': 'PASS',
                            'report': report,
                        })
        return records

    def _checkpoint_records(self) -> list[RunRecordFixture]:
        """Build two valid records with the complete raw GUI probe batches.

        Returns:
            list[RunRecordFixture]: Stable records for incremental CSV and JSONL
                persistence checks.
        """
        records: list[RunRecordFixture] = []
        for trial_offset in range(CHECKPOINT_RECORD_COUNT):
            trial = FIRST_FIXTURE_TRIAL + trial_offset
            report = self._make_child_report(trial=trial)
            records.append({
                "source": "baseline",
                "source_sha": self.baseline_sha,
                "scenario": "dashboard",
                "cache": "cold",
                "instrumentation": "on",
                "trial": trial,
                "elapsed_seconds": CHECKPOINT_ELAPSED_SECONDS,
                "returncode": CHECKPOINT_SUCCESS_RETURN_CODE,
                "status": "PASS",
                "report": report,
            })
        return records

    def test_record_checkpoints_append_once_and_final_export_has_no_duplicates(
        self,
    ) -> None:
        """Append each completed process once, then preserve final export schemas.

        Returns:
            None: Headers, child events and progress identities match two records.
        """
        records = self._checkpoint_records()
        with tempfile.TemporaryDirectory() as directory:
            output = Path(directory)
            report_path = output / "report.json"
            report_path.write_text('{"sentinel": true}\n', encoding="utf-8")

            for record in records:
                BENCHMARK.save_records(output, [record], append=True)

            self.assertEqual(
                {"sentinel": True},
                json.loads(report_path.read_text(encoding="utf-8")),
            )
            with (output / "raw_records.csv").open(
                newline="", encoding="utf-8",
            ) as stream:
                incremental_records = list(csv.DictReader(stream))
            with (output / "raw_events.csv").open(
                newline="", encoding="utf-8",
            ) as stream:
                incremental_events = list(csv.DictReader(stream))
            progress_lines = (
                output / "progress.jsonl"
            ).read_text(encoding="utf-8").splitlines()
            checkpoints = [
                cast(ProgressCheckpointFixture, json.loads(line))
                for line in progress_lines
            ]
            expected_checkpoints: list[ProgressCheckpointFixture] = [
                {
                    "source": record["source"],
                    "source_sha": record["source_sha"],
                    "scenario": record["scenario"],
                    "cache": record["cache"],
                    "instrumentation": record["instrumentation"],
                    "trial": record["trial"],
                    "elapsed_seconds": CHECKPOINT_ELAPSED_SECONDS,
                    "returncode": CHECKPOINT_SUCCESS_RETURN_CODE,
                    "status": "PASS",
                    "raw_event_count": CHECKPOINT_EVENTS_PER_RECORD,
                }
                for record in records
            ]

            self.assertEqual(CHECKPOINT_RECORD_COUNT, len(incremental_records))
            self.assertEqual(
                CHECKPOINT_RECORD_COUNT * CHECKPOINT_EVENTS_PER_RECORD,
                len(incremental_events),
            )
            self.assertEqual(CHECKPOINT_RECORD_COUNT, len(checkpoints))
            self.assertEqual(expected_checkpoints, checkpoints)
            self.assertEqual(
                [str(record["trial"]) for record in records],
                [record["trial"] for record in incremental_records],
            )
            self.assertEqual(
                [
                    (str(record["trial"]), event["metric"], str(event["ns"]))
                    for record in records
                    for event in record["report"]["raw_events"]
                ],
                [
                    (event["trial"], event["metric"], event["ns"])
                    for event in incremental_events
                ],
            )

            BENCHMARK.save_records(output, records)

            final_report = json.loads(report_path.read_text(encoding="utf-8"))
            self.assertEqual(REPORT_SCHEMA_VERSION, final_report["schema_version"])
            self.assertEqual(records, final_report["records"])
            with (output / "raw_records.csv").open(
                newline="", encoding="utf-8",
            ) as stream:
                final_records = list(csv.DictReader(stream))
            with (output / "raw_events.csv").open(
                newline="", encoding="utf-8",
            ) as stream:
                final_events = list(csv.DictReader(stream))
            self.assertEqual(CHECKPOINT_RECORD_COUNT, len(final_records))
            self.assertEqual(
                CHECKPOINT_RECORD_COUNT * CHECKPOINT_EVENTS_PER_RECORD,
                len(final_events),
            )
            final_progress_lines = (
                output / "progress.jsonl"
            ).read_text(encoding="utf-8").splitlines()
            self.assertEqual(CHECKPOINT_RECORD_COUNT, len(final_progress_lines))

    def test_partial_checkpoint_final_export_keeps_only_completed_record(
        self,
    ) -> None:
        """Finalize one completed child without inventing a timed-out sample.

        Returns:
            None: Final records and CSV rows include only the committed child;
                per-child report and console files remain available.
        """
        checkpoint_records = self._checkpoint_records()
        # Positional selection identifies the completed first trial and final
        # child whose report/log files survive without a completed RunRecord.
        completed_record = checkpoint_records[0]
        interrupted_record = checkpoint_records[-1]
        with tempfile.TemporaryDirectory() as directory:
            output = Path(directory)
            BENCHMARK.save_records(
                output, [completed_record], append=True,
            )
            interrupted_stem = (
                f"{interrupted_record['source']}-{interrupted_record['scenario']}-"
                f"{interrupted_record['cache']}-"
                f"{interrupted_record['instrumentation']}-"
                f"{interrupted_record['trial']:03d}"
            )
            child_report = output / f"{interrupted_stem}.json"
            stdout_path = output / f"{interrupted_stem}.stdout.txt"
            stderr_path = output / f"{interrupted_stem}.stderr.txt"
            child_report.write_text(
                json.dumps(interrupted_record["report"]) + "\n",
                encoding="utf-8",
            )
            stdout_path.write_text("partial child stdout\n", encoding="utf-8")
            stderr_path.write_text("partial child stderr\n", encoding="utf-8")

            BENCHMARK.save_records(output, [completed_record])

            report = json.loads(
                (output / "report.json").read_text(encoding="utf-8"),
            )
            self.assertEqual([completed_record], report["records"])
            self.assertEqual(
                [completed_record["trial"]],
                [
                    checkpoint["trial"]
                    for checkpoint in (
                        cast(ProgressCheckpointFixture, json.loads(line))
                        for line in (output / "progress.jsonl")
                        .read_text(encoding="utf-8").splitlines()
                    )
                ],
            )
            with (output / "raw_records.csv").open(
                newline="", encoding="utf-8",
            ) as stream:
                self.assertEqual(1, len(list(csv.DictReader(stream))))
            with (output / "raw_events.csv").open(
                newline="", encoding="utf-8",
            ) as stream:
                self.assertEqual(
                    CHECKPOINT_EVENTS_PER_RECORD,
                    len(list(csv.DictReader(stream))),
                )
            self.assertEqual(
                interrupted_record["report"],
                json.loads(child_report.read_text(encoding="utf-8")),
            )
            self.assertEqual(
                "partial child stdout\n",
                stdout_path.read_text(encoding="utf-8"),
            )
            self.assertEqual(
                "partial child stderr\n",
                stderr_path.read_text(encoding="utf-8"),
            )

    def test_driver_exposes_complete_protocol_matrix(self) -> None:
        """Keep synthetic statistics representative of every scenario/cache cell.

        Returns:
            None: The fixture setup or behavioral assertions complete.
        """
        expected_cells = [
            (scenario, cache)
            for scenario in EXPECTED_SCENARIOS
            for cache in EXPECTED_CACHE_STATES
        ]
        self.assertEqual(BENCHMARK.matrix_cells(), expected_cells)
        self.assertEqual(BENCHMARK.WORKLOAD_SEED, EXPECTED_WORKLOAD_SEED)

    def test_issue_399_matrix_is_isolated_from_issue_361_defaults(self) -> None:
        """Select scroll extents while retaining the legacy default matrix.

        Returns:
            None: Both independent scenario tuples produce only their own cells.
        """
        self.assertEqual(
            BENCHMARK.matrix_cells(ISSUE_399_SCENARIOS),
            [
                (scenario, cache)
                for scenario in ISSUE_399_SCENARIOS
                for cache in EXPECTED_CACHE_STATES
            ],
        )
        self.assertEqual(
            BENCHMARK.select_pilot_scenarios(
                ['scroll5000', 'scroll100'],
                ISSUE_399_SCENARIOS,
            ),
            ('scroll100', 'scroll5000'),
        )
        self.assertEqual(
            BENCHMARK.matrix_cells(),
            [
                (scenario, cache)
                for scenario in EXPECTED_SCENARIOS
                for cache in EXPECTED_CACHE_STATES
            ],
        )

    def test_issue_399_comparison_keeps_pair_and_minimum_count_rules(self) -> None:
        """Apply strict paired identity and ten-pair admission to scroll cells.

        Returns:
            None: Exact scroll pairs summarize, while missing, duplicate, and
                under-counted pairs are rejected.
        """
        records: list[RunRecordFixture] = []
        for scenario in ISSUE_399_SCENARIOS:
            for cache in EXPECTED_CACHE_STATES:
                for trial_offset in range(EXPECTED_PAIR_COUNT):
                    trial = FIRST_FIXTURE_TRIAL + trial_offset
                    for source, source_sha in (
                        ('baseline', self.baseline_sha),
                        ('candidate', self.candidate_sha),
                    ):
                        report = self._make_child_report(
                            scenario=scenario,
                            cache=cache,
                            trial=trial,
                            source_sha=source_sha,
                            instrumentation='off',
                        )
                        records.append({
                            'source': source,
                            'source_sha': source_sha,
                            'scenario': scenario,
                            'cache': cache,
                            'instrumentation': 'off',
                            'trial': trial,
                            'elapsed_seconds': CHECKPOINT_ELAPSED_SECONDS,
                            'returncode': CHECKPOINT_SUCCESS_RETURN_CODE,
                            'status': 'PASS',
                            'report': report,
                        })

        result = BENCHMARK.summarize_comparison(records, ISSUE_399_SCENARIOS)
        self.assertEqual(
            set(result),
            {
                f'{scenario}/{cache}'
                for scenario in ISSUE_399_SCENARIOS
                for cache in EXPECTED_CACHE_STATES
            },
        )
        self.assertTrue(
            all(group['pairs'] == EXPECTED_PAIR_COUNT for group in result.values()),
        )

        for invalid in (
            records[:-2],
            records + [copy.deepcopy(records[0])],
            [
                row for row in records
                if not (
                    row['source'] == 'candidate'
                    and row['scenario'] == ISSUE_399_SCENARIOS[0]
                    and row['trial'] == FIRST_FIXTURE_TRIAL
                )
            ],
        ):
            with self.subTest(record_count=len(invalid)):
                with self.assertRaises(RuntimeError):
                    BENCHMARK.summarize_comparison(invalid, ISSUE_399_SCENARIOS)

    def test_issue_399_protocol_selection_rejects_unknown_and_traversal_values(
        self,
    ) -> None:
        """Refuse unlisted protocol names and harness paths before execution.

        Returns:
            None: Unknown protocol and traversal harness selections raise errors.
        """
        with tempfile.TemporaryDirectory() as directory:
            output = Path(directory)
            with mock.patch.object(
                BENCHMARK,
                'parse_args',
                return_value=SimpleNamespace(output=output),
            ):
                with mock.patch.dict(
                    BENCHMARK.os.environ,
                    {'PHP_GALLERY_TK_PERF_PROTOCOL': '../issue_399_tk_protocol.json'},
                    clear=False,
                ):
                    with self.assertRaisesRegex(ValueError, 'protocol'):
                        BENCHMARK.run_stage()

                legacy_protocol = {
                    'stage': 'pilot',
                    'baseline_sha': BASELINE_SOURCE_SHA,
                    'matrix': {'scenarios': list(EXPECTED_SCENARIOS)},
                    'harness': (
                        'issue_361_tk_workloads.py; same checked-out bytes '
                        'for both source versions'
                    ),
                }
                with mock.patch.dict(
                    BENCHMARK.os.environ,
                    {'PHP_GALLERY_TK_PERF_PROTOCOL': 'issue_361_tk_protocol.json'},
                    clear=False,
                ):
                    with mock.patch.object(
                        Path,
                        'read_text',
                        return_value=json.dumps(legacy_protocol),
                    ):
                        with mock.patch.object(Path, 'is_file', return_value=False):
                            with self.assertRaisesRegex(
                                RuntimeError,
                                'harness is absent',
                            ):
                                BENCHMARK.run_stage()

                unsafe_protocol = {
                    'stage': 'pilot',
                    'baseline_sha': BASELINE_SOURCE_SHA,
                    'matrix': {'scenarios': list(ISSUE_399_SCENARIOS)},
                    'harness': 'descriptive historical text',
                    'workload_script': '../issue_399_tk_scroll.py',
                }
                with mock.patch.dict(
                    BENCHMARK.os.environ,
                    {'PHP_GALLERY_TK_PERF_PROTOCOL': 'issue_399_tk_protocol.json'},
                    clear=False,
                ):
                    with mock.patch.object(
                        Path,
                        'read_text',
                        return_value=json.dumps(unsafe_protocol),
                    ):
                        with self.assertRaisesRegex(ValueError, 'harness'):
                            BENCHMARK.run_stage()

    def test_issue_399_child_source_sha_mismatch_is_rejected(self) -> None:
        """Refuse child evidence claiming another immutable source revision.

        Returns:
            None: A mismatched child source identity fails admission.
        """
        report = self._make_child_report(
            scenario=ISSUE_399_SCENARIOS[0],
            source_sha=self.baseline_sha,
            instrumentation='off',
        )
        with self.assertRaisesRegex(RuntimeError, 'source'):
            BENCHMARK.validate_report(
                report,
                ISSUE_399_SCENARIOS[0],
                'cold',
                FIRST_FIXTURE_TRIAL,
                self.candidate_sha,
                'off',
            )

    def test_child_identity_mismatches_are_rejected(self) -> None:
        """Reject source, scenario, cache and process-trial identity drift.

        Returns:
            None: The fixture setup or behavioral assertions complete.
        """
        mismatches: dict[str, str | int] = {
            'scenario': 'import',
            'cache': 'warm',
            'trial': FIRST_FIXTURE_TRIAL + EXPECTED_PAIR_COUNT,
            'source': self.candidate_sha,
        }
        for field, mismatch in mismatches.items():
            report = self._make_child_report()
            report[field] = mismatch
            with self.subTest(field=field):
                with self.assertRaisesRegex(RuntimeError, field):
                    self._validate_child_report(report)

    def test_empty_nonfinite_negative_and_unitless_metrics_are_rejected(
        self,
    ) -> None:
        """Reject absent, malformed or physically meaningless required metrics.

        Returns:
            None: The fixture setup or behavioral assertions complete.
        """
        invalid_cases: list[tuple[str, ChildReportFixture]] = []
        empty_report = self._make_child_report()
        empty_report['metrics'] = {}
        invalid_cases.append(('empty', empty_report))

        # These values are deliberate invalid vectors, not runtime thresholds:
        # NaN and infinity are non-finite; the negative value violates units.
        for label, value in (
            ('nan', float('nan')),
            ('infinity', float('inf')),
            ('negative', -1.0),
        ):
            report = self._make_child_report()
            report['metrics']['callback_p95_ms'] = value
            invalid_cases.append((label, report))

        unitless_report = self._make_child_report()
        # The numeric value is valid, but this metric name has no physical unit.
        unitless_report['metrics']['callback_latency'] = FIXTURE_METRIC_BASE_MS
        invalid_cases.append(('unitless', unitless_report))

        for label, report in invalid_cases:
            with self.subTest(metric_case=label):
                with self.assertRaises(RuntimeError):
                    self._validate_child_report(report)

    def test_optional_physical_presentation_gap_remains_advisory(self) -> None:
        """Allow valid callback data when physical-monitor evidence is unavailable.

        Returns:
            None: The fixture setup or behavioral assertions complete.
        """
        report = self._make_child_report()
        report['missing_metrics'] = ['physical_presentation_ms']

        validated = self._validate_child_report(report)

        self.assertEqual(
            validated['missing_metrics'],
            ['physical_presentation_ms'],
        )
        self.assertEqual(validated['status'], 'PASS')

    def test_instrumented_runs_reject_missing_required_callbacks(self) -> None:
        """Reject both omitted callback metrics and child-declared callback gaps.

        Returns:
            None: The fixture setup or behavioral assertions complete.
        """
        missing_metric = self._make_child_report(instrumentation='on')
        del missing_metric['metrics']['callback_p95_ms']
        with self.subTest(case='metric absent'):
            with self.assertRaises(RuntimeError):
                self._validate_child_report(
                    missing_metric,
                    instrumentation='on',
                )

        declared_gap = self._make_child_report(instrumentation='on')
        declared_gap['missing_required_metrics'] = ['callback_p95_ms']
        eventless_instrumented = self._make_child_report(instrumentation='on')
        eventless_instrumented['raw_events'] = []
        with self.subTest(case='measured callback events absent'):
            with self.assertRaises(RuntimeError):
                self._validate_child_report(
                    eventless_instrumented,
                    instrumentation='on',
                )

        with self.subTest(case='required callback reported missing'):
            with self.assertRaises(RuntimeError):
                self._validate_child_report(
                    declared_gap,
                    instrumentation='on',
                )

    def test_scroll_metrics_require_matching_raw_events(self) -> None:
        """Require every scroll summary and its corresponding raw observations.

        Returns:
            None: Complete evidence passes; any absent summary or raw kind fails.
        """
        metric_events = {
            'viewport_settle_p95_ms': 'viewport_settle',
            'wheel_slow_p95_ms': 'wheel_slow',
            'wheel_fast_p95_ms': 'wheel_fast',
            'drag_p95_ms': 'drag',
            'resize_p95_ms': 'resize',
        }
        complete = self._make_child_report(
            scenario='scroll100',
            instrumentation='on',
        )
        accepted = self._validate_child_report(
            complete,
            scenario='scroll100',
            instrumentation='on',
        )
        self.assertTrue(
            all(metric in accepted['metrics'] for metric in metric_events),
        )
        self.assertTrue(
            all(
                any(
                    event['metric'] == raw_name
                    and event['phase'] == 'measured'
                    for event in accepted['raw_events']
                )
                for raw_name in metric_events.values()
            ),
        )

        for summary_name, raw_name in metric_events.items():
            missing_summary = copy.deepcopy(complete)
            del missing_summary['metrics'][summary_name]
            with self.subTest(missing_summary=summary_name):
                with self.assertRaisesRegex(RuntimeError, 'metrics'):
                    self._validate_child_report(
                        missing_summary,
                        scenario='scroll100',
                        instrumentation='on',
                    )

            missing_raw = copy.deepcopy(complete)
            missing_raw['raw_events'] = [
                event for event in missing_raw['raw_events']
                if event['metric'] != raw_name
            ]
            with self.subTest(missing_raw=raw_name):
                with self.assertRaisesRegex(RuntimeError, 'raw events'):
                    self._validate_child_report(
                        missing_raw,
                        scenario='scroll100',
                        instrumentation='on',
                    )

    def test_combined_callback_event_extras_are_validated_and_preserved(self) -> None:
        """Accept combined callback events and reject malformed optional context.

        Returns:
            None: The child event extension assertions complete.
        """
        report = self._make_child_report(instrumentation='on')
        callback = next(
            event for event in report['raw_events']
            if event['metric'] == 'callback'
        )
        callback['callback_name'] = 'WatcherApp.refresh_activity_drawer'
        callback['dispatch_delay_ns'] = FIXTURE_DISPATCH_DELAY_NS

        validated = self._validate_child_report(report)

        retained = next(
            event for event in validated['raw_events']
            if event['metric'] == 'callback'
        )
        self.assertEqual(
            retained['callback_name'],
            'WatcherApp.refresh_activity_drawer',
        )
        self.assertEqual(retained['dispatch_delay_ns'], FIXTURE_DISPATCH_DELAY_NS)

        invalid_values = (
            ('callback_name', ''),
            ('dispatch_delay_ns', True),
            ('dispatch_delay_ns', -1),
            ('dispatch_delay_ns', str(FIXTURE_DISPATCH_DELAY_NS)),
        )
        for field, value in invalid_values:
            invalid = self._make_child_report(instrumentation='on')
            callback_event = next(
                event for event in invalid['raw_events']
                if event['metric'] == 'callback'
            )
            callback_event[field] = value
            with self.subTest(field=field, value=value):
                with self.assertRaises(RuntimeError):
                    self._validate_child_report(invalid)

    def test_uninstrumented_runs_need_no_fake_callback_measurements(self) -> None:
        """Accept an off run containing only its observed scenario elapsed time.

        Returns:
            None: The fixture setup or behavioral assertions complete.
        """
        report = self._make_child_report(instrumentation='off')

        validated = self._validate_child_report(
            report,
            instrumentation='off',
        )

        self.assertEqual(
            validated['metrics'],
            {'scenario_elapsed_ms': FIXTURE_METRIC_BASE_MS},
        )
        self.assertEqual(validated['raw_events'], [])
        self.assertEqual(validated['missing_required_metrics'], [])

    def test_comparison_rejects_incomplete_duplicate_and_mismatched_pairs(
        self,
    ) -> None:
        """Reject missing, duplicated and disjoint independent pair identities.

        Returns:
            None: The fixture setup or behavioral assertions complete.
        """
        incomplete = self._comparison_records()
        incomplete = [
            record for record in incomplete
            if not (
                record['source'] == 'candidate'
                and record['scenario'] == EXPECTED_SCENARIOS[0]
                and record['cache'] == EXPECTED_CACHE_STATES[0]
                and record['trial'] == FIRST_FIXTURE_TRIAL
            )
        ]
        with self.subTest(pair_case='incomplete'):
            with self.assertRaises(RuntimeError):
                BENCHMARK.summarize_comparison(incomplete)

        duplicate = self._comparison_records()
        duplicate.append(copy.deepcopy(duplicate[0]))
        with self.subTest(pair_case='duplicate'):
            with self.assertRaises(RuntimeError):
                BENCHMARK.summarize_comparison(duplicate)

        mismatched = self._comparison_records()
        for record in mismatched:
            if (
                record['source'] == 'candidate'
                and record['scenario'] == EXPECTED_SCENARIOS[0]
                and record['cache'] == EXPECTED_CACHE_STATES[0]
            ):
                record['trial'] += EXPECTED_PAIR_COUNT
                record['report']['trial'] += EXPECTED_PAIR_COUNT
        with self.subTest(pair_case='disjoint identities'):
            with self.assertRaises(RuntimeError):
                BENCHMARK.summarize_comparison(mismatched)

    def test_comparison_rejects_nested_run_identity_mismatch(self) -> None:
        """Reject a process record whose nested report identifies another run.

        Returns:
            None: The fixture setup or behavioral assertions complete.
        """
        records = self._comparison_records()
        records[0]['scenario'] = EXPECTED_SCENARIOS[1]
        with self.assertRaises(RuntimeError):
            BENCHMARK.summarize_comparison(records)

        records = self._comparison_records()
        records[0]['source_sha'] = self.candidate_sha
        with self.assertRaises(RuntimeError):
            BENCHMARK.summarize_comparison(records)

    def test_constant_delta_has_exact_paired_ci(self) -> None:
        """Verify ten identical paired deltas produce a point confidence interval.

        Returns:
            None: The fixture setup or behavioral assertions complete.
        """
        summary = BENCHMARK.summarize_comparison(
            self._comparison_records(candidate_delta_ms=KNOWN_DELTA_MS),
        )
        first_cell = summary['dashboard/cold']
        self.assertIsInstance(first_cell, dict)
        cell_metrics = first_cell['metrics']
        self.assertIsInstance(cell_metrics, dict)
        elapsed = cell_metrics['scenario_elapsed_ms']
        self.assertIsInstance(elapsed, dict)
        self.assertEqual(
            elapsed['absolute_delta_mean'],
            KNOWN_DELTA_MS,
        )
        self.assertEqual(
            elapsed['paired_delta_ci95'],
            [KNOWN_DELTA_MS, KNOWN_DELTA_MS],
        )

    def test_ratio_uses_baseline_over_candidate_means(self) -> None:
        """Check independent reports produce the baseline-to-candidate ratio.

        Returns:
            None: The fixture setup or behavioral assertions complete.
        """
        summary = BENCHMARK.summarize_comparison(
            self._comparison_records(candidate_scale=CANDIDATE_RATIO),
        )
        first_cell = summary['dashboard/cold']
        self.assertIsInstance(first_cell, dict)
        cell_metrics = first_cell['metrics']
        self.assertIsInstance(cell_metrics, dict)
        elapsed = cell_metrics['scenario_elapsed_ms']
        self.assertIsInstance(elapsed, dict)
        self.assertEqual(
            elapsed['baseline_to_candidate_ratio'],
            BASELINE_TO_CANDIDATE_RATIO,
        )
        self.assertEqual(
            elapsed['candidate_to_baseline_ratio'],
            CANDIDATE_RATIO,
        )


if __name__ == '__main__':
    unittest.main()
