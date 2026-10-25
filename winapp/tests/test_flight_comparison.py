# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/tests/test_flight_comparison.py
# Module Type: Postflight Comparison Regression Tests
# Purpose: Verify raw-sample statistical analysis and completed flightplans.
# Responsibilities:
#   - Distinguish simulator time, fuel loading, consumption and missing evidence.
#   - Cover localization, vector charts and the final PDF flightplan table.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)
"""Deterministic plan/actual regressions owned by central WinApp discovery."""

import io
import json
import tempfile
import unittest
from dataclasses import replace
from datetime import datetime, timedelta, timezone
from pathlib import Path
from unittest.mock import patch

from pypdf import PdfReader
from reportlab.graphics.shapes import Drawing, Line

from uploader.flight_comparison import (
    FEET_PER_METRE, FlightComparison, WeightedValue, compare_flight,
    grouped_phases, mass_kg, route_excursions,
)
from uploader.flight_data import (
    FlightAnalysis, FlightPhaseEvent, FlightSample, Landing, analyze_session,
)
from uploader.flight_report import (
    _comparison_pages, _deviation_chart, _deviation_histogram,
    _delta_color, _flightplan_pages, render_report, _COLOR_GREEN, _COLOR_RED,
)
from uploader.flight_report_translations import _TRANSLATIONS, supported_languages, tr
from uploader.flight_simbrief import DispatchPlan, DispatchPoint, load_dispatch

# Purpose: Model a short complete flight with ground evidence at both ends.
# Type: int; Units: host seconds; Scope: deterministic replay fixture.
# Consumers: recording. Rationale: 101 observations include stationary parking,
# motion, a 60-host-second airborne leg and final parked confirmation.
FIXTURE_END_SECONDS = 100
# Purpose: Prove simulator time rather than host time owns duration/weighting.
# Type: float; Units: simulator seconds per host second; Scope: replay fixture.
# Consumers: recording. Rationale: rate two doubles flight time while fuel and
# geographic observations retain their known independently specified values.
FIXTURE_SIMULATOR_RATE = 2.0


def recording() -> tuple[DispatchPlan, list[FlightSample], list[FlightPhaseEvent], list[Landing]]:
    """Build one complete equatorial flight with an extra initial fuel load.

    Returns:
        tuple[DispatchPlan, list[FlightSample], list[FlightPhaseEvent], list[Landing]]: Planned 60-second/120-kg flight, observed 120-second/
            120-kg flight with 100 kg extra fuel, and existing-owner event data.
    """
    plan = DispatchPlan("AAAA", "BBBB", "DCT FIX1 FIX2 DCT", "A320", "120",
        "1120", "kgs", "2610", "2026-10-25T09:00:00+00:00",
        points=[
            DispatchPoint("AAAA", 0, 0, 0, 10000, 1000),
            DispatchPoint("FIX1", 0, 0.01, 20, 10000, 960, 136, 54, 0, 10, "CLB"),
            DispatchPoint("FIX2", 0, 0.02, 40, 10000, 920, 136, 54, 0, 10, "CRZ"),
            DispatchPoint("BBBB", 0, 0.03, 60, 10000, 880, stage="DSC"),
        ], duration_seconds="60", fuel_trip="120", distance_nm="1.8",
        distance_source="route", takeoff_weight="6000", landing_weight="5880",
        zero_fuel_weight="5000", fuel_reserve="500", cruise_tas_kt="136",
        transition_altitude_ft="6000", arrival_transition_altitude_ft="6000",
        scheduled_departure_utc="2026-10-25T10:00:18+00:00",
        scheduled_takeoff_utc="2026-10-25T10:00:40+00:00",
        scheduled_arrival_utc="2026-10-25T10:01:50+00:00",
        block_seconds="92", taxi_out_seconds="22", taxi_in_seconds="10")
    samples = []
    for second in range(FIXTURE_END_SECONDS + 1):
        progress = max(0, min(60, second - 20))
        fuel = 1100 - progress * 2
        stamp = datetime(2026, 10, 25, 10, tzinfo=timezone.utc) + timedelta(seconds=second * FIXTURE_SIMULATOR_RATE)
        samples.append(FlightSample(
            session_elapsed_seconds=float(second), recorded_utc=stamp.isoformat(),
            simulator_elapsed_seconds=second * FIXTURE_SIMULATOR_RATE,
            simulator_rate=FIXTURE_SIMULATOR_RATE, simulator_utc=stamp.isoformat(),
            latitude_deg=0, longitude_deg=progress / 60 * 0.03,
            altitude_m=10700 / FEET_PER_METRE,
            pressure_altitude_m=10200 / FEET_PER_METRE,
            on_ground=second < 20 or second >= 80,
            parking_brake=second < 9 or second >= 85,
            ground_speed_mps=0 if second < 9 or second >= 85 else 28,
            true_airspeed_mps=70, indicated_airspeed_mps=55,
            vertical_speed_mps=5 if 20 <= second < 35 else -5 if 65 <= second < 80 else 0,
            fuel_weight_kg=fuel, fuel_gallons=300,
            total_weight_kg=5000 + fuel, wind_velocity_mps=5,
            wind_direction_deg=0, heading_deg=90,
        ))
    events = [FlightPhaseEvent(phase, second, "moderate", ["deterministic fixture"])
              for phase, second in (
                  ("preflight", 0), ("taxiout", 9), ("takeoff", 20),
                  ("climb", 20), ("cruise", 35), ("descent", 65),
                  ("approach", 75), ("touchdown", 80), ("taxiin", 82),
              )]
    landings = [Landing(1, 80, 80, 0, 0.03, 0, 200, 1, False,
                        ["confirmed fixture contact"], None, "moderate")]
    return plan, samples, events, landings


class FlightComparisonTests(unittest.TestCase):
    """Keep statistical claims tied to compatible, original recorded evidence."""

    def comparison(self) -> FlightComparison:
        """Analyze the complete deterministic replay through the production owner.

        Returns:
            FlightComparison: Shared complete-flight comparison.
        """
        plan, samples, events, landings = recording()
        return compare_flight(plan, samples, events, landings, True)

    def test_simulator_time_and_initial_fuel_are_separate(self) -> None:
        """Distinguish extra loaded fuel from net consumption and host duration.

        Returns:
            None: Flight time is 120 s; burn stays 120 kg despite +100 kg onboard.
        """
        result = self.comparison()
        metrics = {metric.key: metric for metric in result.metrics}
        self.assertTrue(result.route_accepted)
        self.assertEqual(120, metrics["airtime"].actual)
        self.assertEqual(60, metrics["airtime"].planned)
        self.assertEqual(120, metrics["trip_fuel"].actual)
        self.assertEqual(100, metrics["takeoff_fuel"].actual - metrics["takeoff_fuel"].planned)
        self.assertEqual(100, metrics["landing_fuel"].actual - metrics["landing_fuel"].planned)
        self.assertEqual(152, metrics["block"].actual)
        self.assertEqual(60, metrics["arrival_delay"].actual)
        self.assertEqual(4, result.matched_fixes)
        self.assertAlmostEqual(40, result.rows[1].actual_elapsed_seconds)
        self.assertAlmostEqual(80, result.rows[2].actual_elapsed_seconds)
        self.assertAlmostEqual(200, result.altitude_error_ft.mae)
        self.assertAlmostEqual(200, result.altitude_error_ft.rmse)
        self.assertTrue(all(abs(point.burn_delta_kg) < 0.01 for point in result.deviations if point.burn_delta_kg is not None))

    def test_histograms_use_duration_and_preserve_signed_bias(self) -> None:
        """Weight sparse/dense observations by duration rather than sample count.

        Returns:
            None: Weighted MAE, RMSE and P95 preserve known asymmetric evidence.
        """
        values = WeightedValue()
        values.add(-10, 9)
        values.add(10, 1)
        self.assertEqual(-8, values.mean)
        self.assertEqual(10, values.mae)
        self.assertEqual(10, values.rmse)
        self.assertEqual(11, values.p95)  # Upper edge of the disclosed 1-unit bin.
        self.assertEqual(10, sum(values.histogram.values()))

    def test_duplicate_destination_keeps_the_landing_anchor_and_summary(self) -> None:
        """Retain a landing shared by the navlog destination and airport endpoint.

        Returns:
            None: Both original OFP rows remain visible, share their real landing
                time, and no longer suppress otherwise complete flight totals.
        """
        plan, samples, events, landings = recording()
        plan.points.insert(-1, replace(plan.points[-1]))
        result = compare_flight(plan, samples, events, landings, True)
        self.assertEqual(len(plan.points), result.matched_fixes)
        self.assertIsNotNone(result.rows[-1].passage)
        self.assertEqual(result.rows[-2].passage.host_seconds,
                         result.rows[-1].passage.host_seconds)
        metrics = {metric.key: metric for metric in result.metrics}
        self.assertEqual(120, metrics["airtime"].actual)
        self.assertEqual(120, metrics["trip_fuel"].actual)
        # A coincident intermediate alias still needs its own later passage.
        plan.points.insert(2, replace(plan.points[1], ident="ALIAS"))
        strict = compare_flight(plan, samples, events, landings, True)
        self.assertIsNone(strict.rows[2].passage)
        plan.points.pop(2)
        plan.points.insert(1, replace(plan.points[0]))
        repeated = compare_flight(plan, samples, events, landings, True)
        self.assertEqual(len(plan.points), repeated.matched_fixes)
        self.assertEqual(repeated.rows[0].passage.host_seconds,
                         repeated.rows[1].passage.host_seconds)

    def test_grouped_phase_totals_and_semantic_colors_preserve_meaning(self) -> None:
        """Merge transient phases without losing measured totals or polarity.

        Returns:
            None: Grouping preserves time/fuel, and fuel remaining has the
                opposite favorable sign from fuel consumption and elapsed time.
        """
        result = self.comparison()
        groups = grouped_phases(result)
        self.assertAlmostEqual(sum(row.seconds for row in result.phases),
                               sum(row.seconds for row in groups))
        self.assertAlmostEqual(sum(row.fuel_kg for row in result.phases),
                               sum(row.fuel_kg for row in groups))
        self.assertEqual(_COLOR_GREEN, _delta_color("fuel_remaining", 100))
        self.assertEqual(_COLOR_RED, _delta_color("fuel_remaining", -100))
        self.assertEqual(_COLOR_GREEN, _delta_color("trip_fuel", -100))
        self.assertEqual(_COLOR_RED, _delta_color("trip_fuel", 100))
        self.assertEqual(_COLOR_GREEN, _delta_color("time_delta_seconds", -30))

    def test_refuelling_gaps_and_missing_mass_suppress_whole_flight_claims(self) -> None:
        """Reject incomplete fuel/time comparisons without hiding preserved plans.

        Returns:
            None: Refuelling, discontinuities and legacy unknowns stay explicit.
        """
        plan, samples, events, landings = recording()
        for mode in ("refuel", "gap", "legacy"):
            with self.subTest(mode=mode):
                changed = [replace(sample) for sample in samples]
                if mode == "refuel":
                    changed = [replace(sample, fuel_weight_kg=sample.fuel_weight_kg + (200 if sample.session_elapsed_seconds >= 50 else 0)) for sample in changed]
                elif mode == "gap":
                    changed[50].discontinuity_before = "flight loaded"
                else:
                    changed = [replace(sample, fuel_weight_kg=None, pressure_altitude_m=None, simulator_utc=None) for sample in changed]
                result = compare_flight(plan, changed, events, landings, True)
                metrics = {metric.key: metric for metric in result.metrics}
                self.assertIsNone(metrics["trip_fuel"].actual)
                self.assertEqual(len(plan.points), len(result.rows))
                self.assertIn({"refuel": "fuel_changed", "gap": "gaps", "legacy": "fuel_missing"}[mode], result.notes)
                if mode == "gap":
                    self.assertIsNone(metrics["airtime"].actual)
                    self.assertTrue(any(point.break_before for point in result.deviations))
                if mode == "legacy":
                    self.assertIsNone(result.altitude_error_ft.mean)
                    self.assertIsNone(result.offblock_utc)

    def test_unrelated_or_unmatched_plans_never_gain_actual_values(self) -> None:
        """Preserve every planned fix while refusing unrelated nearby evidence.

        Returns:
            None: A remote OFP has no numerical comparisons or matched rows.
        """
        plan, samples, events, landings = recording()
        plan.points = [replace(point, latitude=20) for point in plan.points]
        result = compare_flight(plan, samples, events, landings, True)
        self.assertFalse(result.route_accepted)
        self.assertTrue(all(row.passage is None for row in result.rows))
        self.assertTrue(all(metric.actual is None for metric in result.metrics))
        self.assertEqual([], result.deviations)
        self.assertIn("route_unverified", result.notes)

    def test_missing_altitude_reference_and_plot_thinning_keep_statistics_honest(self) -> None:
        """Separate pressure references and bounded plotting from raw statistics.

        Returns:
            None: Plot reduction preserves raw totals; geometric altitude never
                substitutes for missing pressure or transition references.
        """
        plan, samples, events, landings = recording()
        normal = compare_flight(plan, samples, events, landings, True)
        with patch("uploader.flight_comparison.DEVIATION_POINT_LIMIT", 8):
            thinned = compare_flight(plan, samples, events, landings, True)
        self.assertLess(len(thinned.deviations), 8)
        self.assertEqual(normal.altitude_error_ft, thinned.altitude_error_ft)
        self.assertEqual(normal.airborne_seconds, thinned.airborne_seconds)
        plan.arrival_transition_altitude_ft = ""
        missing = compare_flight(plan, samples, events, landings, True)
        self.assertIsNone(missing.altitude_error_ft.mean)
        self.assertFalse(any(row.altitude_comparable for row in missing.rows))

    def test_old_snapshots_and_raw_session_reanalysis_remain_supported(self) -> None:
        """Read additive snapshots and derive new statistics from original rows.

        Returns:
            None: Legacy defaults survive load; normal analysis attaches a
                comparison without using reduced display points as its input.
        """
        plan, samples, _events, _landings = recording()
        with tempfile.TemporaryDirectory() as temporary:
            directory = Path(temporary)
            plan.save(directory)
            self.assertEqual(plan, load_dispatch(directory))
            (directory / "samples.jsonl").write_text("\n".join(
                json.dumps({"type": "sample", "sample": sample.to_dict()}) for sample in samples
            ), encoding="utf-8")
            (directory / "session.json").write_text(json.dumps({
                "recording_ended": True, "ended_monotonic": FIXTURE_END_SECONDS,
            }), encoding="utf-8")
            analysis = analyze_session(directory)
            self.assertIsNotNone(analysis.comparison)
            payload = analysis.to_payload()
            self.assertIn("histogram", payload["comparison"]["route_error_nm"])
            saved = json.loads((directory / "simbrief.json").read_text(encoding="utf-8"))
            for point in saved["points"]:
                for key in ("true_airspeed_kt", "ground_speed_kt", "wind_direction_deg", "wind_speed_kt", "stage"):
                    del point[key]
            (directory / "simbrief.json").write_text(json.dumps(saved), encoding="utf-8")
            recovered = load_dispatch(directory)
            self.assertIsNotNone(recovered)
            self.assertIsNone(recovered.points[1].true_airspeed_kt)

    def test_dateline_geometry_and_declared_mass_units_are_explicit(self) -> None:
        """Keep antimeridian routes continuous and refuse implicit fuel density.

        Returns:
            None: Nearby dateline positions stay on-route; a 60-NM displacement
                is outside, pounds convert exactly and gallons remain unknown.
        """
        plan, _samples, _events, _landings = recording()
        plan.points = [DispatchPoint("WEST", 0, 179.9),
                       DispatchPoint("DATE", 0, 180),
                       DispatchPoint("EAST", 0, -179.9)]
        self.assertEqual([False, False, True, False], route_excursions(
            plan, [(0, 179.95), (0, -179.95), (1, 179.95), None],
        ))
        self.assertAlmostEqual(453.59237, mass_kg("1000", "lbs"))
        self.assertIsNone(mass_kg("1000", "gallons"))

    def test_skipped_fix_and_incompatible_schedule_keep_unknown_values(self) -> None:
        """Keep detoured OFP points visible without inventing passages or delays.

        Returns:
            None: The far fix remains unmatched; simulator/OFP date mismatch
                suppresses punctuality while elapsed airborne time remains valid.
        """
        plan, samples, events, landings = recording()
        plan.points[1] = replace(plan.points[1], latitude=1)
        plan.scheduled_departure_utc = "2020-01-01T10:00:00+00:00"
        result = compare_flight(plan, samples, events, landings, True)
        self.assertTrue(result.route_accepted)
        self.assertEqual("FIX1", result.rows[1].point.ident)
        self.assertIsNone(result.rows[1].passage)
        metrics = {metric.key: metric for metric in result.metrics}
        self.assertIsNone(metrics["departure_delay"].actual)
        self.assertEqual(120, metrics["airtime"].actual)
        self.assertIn("schedule_date", result.notes)

    def test_every_edition_has_localized_graphs_and_final_flightplan(self) -> None:
        """Exercise real vector charts and a complete Unicode PDF report.

        Returns:
            None: Every edition has complete comparison labels, wrapped tables
                and vector profiles; the PDF ends with the populated flightplan.
        """
        plan, samples, events, landings = recording()
        analysis = FlightAnalysis("comparison-fixture", "MSFS 2024", "A320", True,
            FIXTURE_END_SECONDS, 1.8, samples, landings, [], events=events,
            dispatch=plan, comparison=compare_flight(plan, samples, events, landings, True))
        keys = {key for key in _TRANSLATIONS["en"] if key.startswith("cmp_")}
        for language in supported_languages():
            with self.subTest(language=language):
                self.assertTrue(keys <= _TRANSLATIONS[language].keys())
                self.assertTrue(_comparison_pages(analysis, language))
                table = _flightplan_pages(analysis, language)[-1]
                self.assertEqual(len(plan.points) + 1, len(table._cellvalues))
                self.assertEqual(1, table.repeatRows)
                chart = _deviation_chart(analysis, language, "altitude_delta_ft", "Altitude", "ft")
                self.assertIsInstance(chart, Drawing)
                self.assertTrue(any(isinstance(shape, Line) for shape in chart.contents))
                self.assertIsInstance(_deviation_histogram(analysis.comparison.route_error_nm, language, "NM"), Drawing)
        with tempfile.TemporaryDirectory() as temporary, patch("uploader.flight_maps.urlopen", side_effect=OSError("offline fixture")):
            target = render_report(analysis, "cs", Path(temporary) / "comparison.pdf")
            pdf = PdfReader(io.BytesIO(target.read_bytes()))
            text = "\n".join(page.extract_text() for page in pdf.pages)
            self.assertIn(tr("cs", "cmp_title"), text)
            self.assertIn(tr("cs", "cmp_deviation_charts"), text)
            self.assertIn("FIX2", pdf.pages[-1].extract_text())
            self.assertIn("P:", pdf.pages[-1].extract_text())
            self.assertIn("A:", pdf.pages[-1].extract_text())

    def test_legacy_flightplan_uses_labelled_amsl_without_unknown_flooding(self) -> None:
        """Keep recorded legacy height useful without fabricating pressure or UTC.

        Returns:
            None: The final table contains AMSL observations with a marker,
                preserves planned UTC, and avoids repeated unavailable labels.
        """
        plan, samples, events, landings = recording()
        samples = [replace(sample, pressure_altitude_m=None, simulator_utc=None)
                   for sample in samples]
        analysis = FlightAnalysis("legacy-comparison", "MSFS 2024", "A320", True,
            FIXTURE_END_SECONDS, 1.8, samples, landings, [], events=events,
            dispatch=plan, comparison=compare_flight(plan, samples, events, landings, True))
        self.assertIsNone(analysis.comparison.altitude_error_ft.mean)
        for language in supported_languages():
            with self.subTest(language=language):
                table = _flightplan_pages(analysis, language)[-1]
                text = " ".join(cell.getPlainText() for row in table._cellvalues
                                for cell in row)
                self.assertIn("10700.0*" if language == "en" else "10700,0*", text)
                self.assertNotIn(tr(language, "unavailable"), text)
                self.assertIn(tr(language, "cmp_plan_utc"), text)


if __name__ == "__main__":
    unittest.main()
