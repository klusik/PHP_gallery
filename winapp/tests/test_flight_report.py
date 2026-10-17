# Project: PHP Gallery
# Module Type: Windows Tooling Regression Test
# Purpose: Verify multilingual postflight PDF output and retry identity.
# Responsibilities:
#   - Exercise the normalized renderer with both supported simulator labels.
#   - Verify embedded Unicode font metadata and checksummed output manifests.
#   - Guard unavailable measurements and localization behavior.
# Repository: https://github.com/klusik/PHP_gallery
#
# File: winapp/tests/test_flight_report.py
#
# Author:
#   Rudolf Klusal
#
# License:
#   MIT License (see LICENSE file in repository)

"""Focused regression coverage for local multilingual postflight reports."""

import hashlib
import json
import os
import shutil
import tempfile
import unittest
from pathlib import Path
from dataclasses import replace
from typing import Optional
from types import SimpleNamespace
from unittest.mock import Mock

from reportlab.pdfbase.ttfonts import TTFont

from uploader.flight_data import (
    FlightAnalysis,
    FlightPhaseEvent,
    FlightSample,
    Landing,
)
from uploader.flight_report import (
    _COLOR_BLUE,
    _COLOR_GREEN,
    _FONT_NAME,
    _REQUIRED_GLYPHS,
    KNOTS_PER_MPS,
    _number,
    _register_font,
    _resource_directory,
    _contact_sensor_tables,
    _draw_page,
    _landing_rows,
    _methodology_notes,
    _quality_notes,
    _profile_chart,
    _phase_event_table,
    _recorded_flight_date,
    _route_chart,
    _styles,
    render_report,
    render_reports,
)
from uploader.flight_report_translations import supported_languages, tr


# Type: int; Units: samples; Scope: synthetic report fixture;
# Consumers: _analysis; Rationale: two samples produce a visible vector track
# and profile while remaining a small deterministic boundary; value: 2.
SAMPLE_COUNT = 2

# Type: int; Units: samples; Scope: optional hosted visual-QA flight fixture.
# Consumers: _dense_multilanding_analysis. Rationale: provide enough profile
# observations for multiple report pages; value: 360.
DENSE_SAMPLE_COUNT = 360
# Type: float; Units: seconds between samples; Scope: visual-QA fixture.
# Consumers: _dense_multilanding_analysis. Rationale: create a compact but
# readable continuous track; value: 0.5.
DENSE_SAMPLE_INTERVAL_SECONDS = 0.5
# Type: int; Units: observations per contact window; Scope: visual-QA fixture.
# Consumers: _dense_multilanding_analysis. Rationale: force contact tables to
# span pages and repeat their headers; value: 50.
DENSE_CONTACT_SAMPLE_COUNT = 50
# Type: float; Units: seconds between contact observations; Scope: visual-QA fixture.
# Consumers: _dense_multilanding_analysis. Rationale: represent dense retained
# contact evidence without expanding the full chart track; value: 0.1.
DENSE_CONTACT_INTERVAL_SECONDS = 0.1
# Type: tuple[float, float]; Units: seconds after recorded start.
# Scope: visual-QA fixture; Consumers: _dense_multilanding_analysis.
# Rationale: create two distinct landing windows in one long report;
# value: (60.0, 140.0).
DENSE_LANDING_STARTS_SECONDS = (60.0, 140.0)


class FlightReportTests(unittest.TestCase):
    """Verify the renderer produces complete, stable multilingual reports."""

    def _analysis(
        self,
        simulator: str,
        include_measurements: bool = True,
    ) -> FlightAnalysis:
        """Build a small normalized flight model for one simulator generation.

        Args:
            simulator (str): Simulator identity stored in the analysis.
            include_measurements (bool): Whether derived optional values are
                present in samples and landing evidence.

        Returns:
            FlightAnalysis: Deterministic flight with one landing window.
        """
        optional_speed: Optional[float] = 45.0 if include_measurements else None
        optional_altitude: Optional[float] = 950.0 if include_measurements else None
        samples = [
            FlightSample(
                session_elapsed_seconds=0.0,
                recorded_utc="2026-05-10T12:30:00Z",
                simulator_elapsed_seconds=None,
                simulator_rate=1.0,
                latitude_deg=49.2,
                longitude_deg=16.6,
                altitude_m=optional_altitude,
                agl_m=None,
                vertical_speed_mps=None,
                ground_speed_mps=optional_speed,
                true_airspeed_mps=None,
                indicated_airspeed_mps=optional_speed,
                load_factor_g=1.0 if include_measurements else None,
                pitch_deg=-1.0 if include_measurements else None,
                bank_deg=0.5 if include_measurements else None,
                heading_deg=270.0 if include_measurements else None,
                on_ground=False,
                lights={},
                gear_position=100.0,
                flaps_position=0.0,
                engine_rpm=(),
                fuel_gallons=38.0 if include_measurements else None,
                discontinuity_before=None,
                wind_velocity_mps=5.0 if include_measurements else None,
                wind_direction_deg=180.0 if include_measurements else None,
                left_brake_position=0.0 if include_measurements else None,
                right_brake_position=0.0 if include_measurements else None,
                autopilot_master=False if include_measurements else None,
            ),
            FlightSample(
                session_elapsed_seconds=120.0,
                recorded_utc="2026-05-10T12:30:00Z",
                simulator_elapsed_seconds=None,
                latitude_deg=49.3,
                longitude_deg=16.8,
                altitude_m=optional_altitude,
                agl_m=None,
                vertical_speed_mps=None,
                ground_speed_mps=optional_speed,
                true_airspeed_mps=None,
                indicated_airspeed_mps=optional_speed,
                load_factor_g=1.0 if include_measurements else None,
                pitch_deg=1.0 if include_measurements else None,
                bank_deg=0.0 if include_measurements else None,
                heading_deg=271.0 if include_measurements else None,
                on_ground=True,
                lights={},
                gear_position=25.0,
                flaps_position=50.0,
                engine_rpm=(),
                fuel_gallons=35.0 if include_measurements else None,
                discontinuity_before=None,
                wind_velocity_mps=4.0 if include_measurements else None,
                wind_direction_deg=190.0 if include_measurements else None,
                left_brake_position=0.75 if include_measurements else None,
                right_brake_position=0.75 if include_measurements else None,
                autopilot_master=False if include_measurements else None,
            ),
        ]
        landing = Landing(
            index=1,
            first_contact_elapsed_seconds=118.0,
            last_contact_elapsed_seconds=120.0,
            latitude_deg=49.3 if include_measurements else None,
            longitude_deg=16.8 if include_measurements else None,
            altitude_m=optional_altitude,
            descent_rate_fpm=250.0 if include_measurements else None,
            contact_count=2,
            bounce_possible=False,
            evidence=["on_ground", "vertical_speed"],
            distance_from_previous_nm=None,
            confidence="medium",
            contact_samples=[samples[-1]] if include_measurements else [],
            contact_descent_rates_fpm=[250.0] if include_measurements else [],
            rollout_seconds=2.0 if include_measurements else None,
            rollout_distance_nm=None,
        )
        self.assertEqual(len(samples), SAMPLE_COUNT)
        analysis = FlightAnalysis(
            session_id="session-flight-254",
            simulator=simulator,
            aircraft="Cessna 172",
            completed=True,
            duration_seconds=120.0,
            distance_nm=12.5,
            samples=samples,
            landings=[landing],
            warnings=[],
            simulator_build="SimConnect API 1.0",
        )
        analysis.events = [
            FlightPhaseEvent(
                phase="touchdown",
                elapsed_seconds=118.0,
                confidence="moderate",
                evidence=["on_ground", "vertical_speed"],
            )
        ]
        return analysis

    def _dense_multilanding_analysis(
        self,
        simulator: str,
    ) -> FlightAnalysis:
        """Build the optional multi-page landing fixture for hosted visual QA.

        Args:
            simulator (str): Simulator label used to select the artifact folder.

        Returns:
            FlightAnalysis: Continuous three-minute profile with two landings
                and fifty retained observations for each contact window.
        """
        analysis = self._analysis(simulator)
        template = analysis.samples[-1]
        starts = DENSE_LANDING_STARTS_SECONDS
        contact_window_seconds = (
            DENSE_CONTACT_SAMPLE_COUNT - 1
        ) * DENSE_CONTACT_INTERVAL_SECONDS
        analysis.samples = [
            replace(
                template,
                session_elapsed_seconds=(
                    frame * DENSE_SAMPLE_INTERVAL_SECONDS
                ),
                latitude_deg=49.0 + frame * 0.0001,
                longitude_deg=16.0 + frame * 0.0001,
                altitude_m=(
                    1200.0
                    if not any(
                        start
                        <= frame * DENSE_SAMPLE_INTERVAL_SECONDS
                        <= start + contact_window_seconds
                        for start in starts
                    )
                    else 140.0
                ),
                agl_m=(
                    1000.0
                    if not any(
                        start
                        <= frame * DENSE_SAMPLE_INTERVAL_SECONDS
                        <= start + contact_window_seconds
                        for start in starts
                    )
                    else 0.0
                ),
                vertical_speed_mps=(
                    -1.2
                    if not any(
                        start
                        <= frame * DENSE_SAMPLE_INTERVAL_SECONDS
                        <= start + contact_window_seconds
                        for start in starts
                    )
                    else 0.0
                ),
                ground_speed_mps=35.0,
                indicated_airspeed_mps=38.0,
                on_ground=any(
                    start
                    <= frame * DENSE_SAMPLE_INTERVAL_SECONDS
                    <= start + contact_window_seconds
                    for start in starts
                ),
                discontinuity_before=None,
            )
            for frame in range(DENSE_SAMPLE_COUNT)
        ]
        contact_samples = lambda start: [
            replace(
                template,
                session_elapsed_seconds=(
                    start + index * DENSE_CONTACT_INTERVAL_SECONDS
                ),
                latitude_deg=49.2 + index * 0.00001,
                longitude_deg=16.2 + index * 0.00001,
                altitude_m=140.0,
                agl_m=0.0,
                vertical_speed_mps=0.2,
                ground_speed_mps=30.0 - index * 0.2,
                indicated_airspeed_mps=32.0 - index * 0.2,
                pitch_deg=1.0 + index * 0.02,
                bank_deg=0.1,
                heading_deg=271.0,
                on_ground=True,
                discontinuity_before=None,
            )
            for index in range(DENSE_CONTACT_SAMPLE_COUNT)
        ]
        analysis.landings = [
            replace(
                analysis.landings[0],
                index=index,
                first_contact_elapsed_seconds=start,
                last_contact_elapsed_seconds=(
                    start + contact_window_seconds
                ),
                latitude_deg=49.2,
                longitude_deg=16.2,
                altitude_m=140.0,
                descent_rate_fpm=245.0 + index,
                contact_count=DENSE_CONTACT_SAMPLE_COUNT,
                evidence=["on_ground", "retained contact observations"],
                distance_from_previous_nm=(
                    None if index == 1 else 15.6
                ),
                confidence="moderate",
                contact_samples=contact_samples(start),
                contact_descent_rates_fpm=[
                    245.0 + index * 0.1
                    for index in range(DENSE_CONTACT_SAMPLE_COUNT)
                ],
                rollout_seconds=35.0 + index,
                rollout_distance_nm=0.6 + index * 0.1,
            )
            for index, start in enumerate(starts, start=1)
        ]
        analysis.duration_seconds = (
            DENSE_SAMPLE_COUNT * DENSE_SAMPLE_INTERVAL_SECONDS
        )
        analysis.distance_nm = 42.0
        analysis.events = [
            replace(
                analysis.events[0],
                elapsed_seconds=start,
                evidence=["on_ground", "retained contact observations"],
            )
            for start in starts
        ]
        return analysis

    def test_all_languages_render_for_both_simulators(self) -> None:
        """Render all four languages from each normalized simulator model.

        Returns:
            None: Assert PDF, font metadata and checksum manifests. If
                PHP_GALLERY_FLIGHT_REPORT_QA_DIR is set, copy the eight
                simulator/language PDFs and their manifests plus dense
                multi-landing samples in all four languages to CI artifacts;
                no user telemetry is uploaded.
        """
        _register_font()
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            for simulator in ("MSFS 2020", "MSFS 2024"):
                with self.subTest(simulator=simulator):
                    analysis = self._analysis(simulator)
                    outputs = render_reports(
                        analysis,
                        supported_languages(),
                        root / simulator.replace(" ", "_"),
                    )
                    self.assertEqual(len(outputs), len(supported_languages()))
                    qa_directory = os.environ.get(
                        "PHP_GALLERY_FLIGHT_REPORT_QA_DIR"
                    )
                    if qa_directory:
                        artifact_directory = (
                            Path(qa_directory).expanduser().resolve()
                            / simulator.replace(" ", "_")
                        )
                        artifact_directory.mkdir(parents=True, exist_ok=True)
                        for report_path in outputs:
                            shutil.copy2(report_path, artifact_directory)
                            shutil.copy2(
                                report_path.with_suffix(
                                    report_path.suffix + ".manifest.json"
                                ),
                                artifact_directory,
                            )
                        group_manifest = next(
                            (root / simulator.replace(" ", "_")).glob(
                                "postflight-manifest-*.json"
                            )
                        )
                        shutil.copy2(group_manifest, artifact_directory)
                        if simulator == "MSFS 2024":
                            dense_directory = (
                                artifact_directory / "dense-multiple-landings"
                            )
                            render_reports(
                                self._dense_multilanding_analysis(simulator),
                                supported_languages(),
                                dense_directory,
                            )
                    output_hashes = {
                        hashlib.sha256(path.read_bytes()).hexdigest()
                        for path in outputs
                    }
                    self.assertEqual(
                        len(output_hashes),
                        len(supported_languages()),
                    )
                    for path, language in zip(outputs, supported_languages()):
                        pdf_bytes = path.read_bytes()
                        self.assertTrue(pdf_bytes.startswith(b"%PDF-"))
                        self.assertTrue(
                            b"DejaVuSans" in pdf_bytes,
                            "embedded DejaVu font missing",
                        )
                        self.assertTrue(
                            b"/FontFile2" in pdf_bytes,
                            "embedded TrueType font stream missing",
                        )
                        self.assertTrue(
                            b"/ToUnicode" in pdf_bytes,
                            "font Unicode map missing",
                        )
                        self.assertTrue(
                            (
                                b"/Lang ("
                                + language.encode("ascii")
                                + b")"
                            ) in pdf_bytes,
                            "PDF catalog language is missing",
                        )
                        self.assertEqual(
                            hashlib.sha256(pdf_bytes).hexdigest(),
                            json.loads(
                                path.with_suffix(
                                    path.suffix + ".manifest.json"
                                ).read_text(encoding="utf-8")
                            )["pdf_sha256"],
                        )
                        manifest = json.loads(
                            path.with_suffix(
                                path.suffix + ".manifest.json"
                            ).read_text(encoding="utf-8")
                        )
                        self.assertEqual(manifest["language"], language)
                        self.assertTrue(
                            b"/Title" in pdf_bytes,
                            "PDF title metadata is missing",
                        )

    def test_rerender_is_idempotent_and_group_manifest_is_complete(self) -> None:
        """Keep report identities and bytes stable across retry calls.

        Returns:
            None: Assert filenames, bytes and grouped checksums are stable.
        """
        analysis = self._analysis("MSFS 2024")
        with tempfile.TemporaryDirectory() as temporary:
            directory = Path(temporary)
            first = render_reports(analysis, ("en", "cs", "de", "sv"), directory)
            first_hashes = [
                hashlib.sha256(path.read_bytes()).hexdigest()
                for path in first
            ]
            second = render_reports(analysis, ("en", "cs", "de", "sv"), directory)
            self.assertEqual(first, second)
            second_hashes = [
                hashlib.sha256(path.read_bytes()).hexdigest()
                for path in second
            ]
            self.assertEqual(first_hashes, second_hashes)
            manifest_path = next(directory.glob("postflight-manifest-*.json"))
            manifest = json.loads(
                manifest_path.read_text(encoding="utf-8")
            )
            self.assertEqual(manifest["session_id"], analysis.session_id)
            self.assertEqual(
                [entry["language"] for entry in manifest["reports"]],
                ["en", "cs", "de", "sv"],
            )
            self.assertEqual(
                [entry["pdf_sha256"] for entry in manifest["reports"]],
                [
                    hashlib.sha256(path.read_bytes()).hexdigest()
                    for path in second
                ],
            )

    def test_render_after_manifest_loss_keeps_server_receipt_hash(self) -> None:
        """Regenerate identical bytes when only the local sidecar was lost.

        Returns:
            None: Assert a retry keeps the previously acknowledged PDF checksum.
        """
        analysis = self._analysis("MSFS 2024")
        with tempfile.TemporaryDirectory() as temporary:
            destination = Path(temporary) / "flight-en.pdf"
            first_path = render_report(analysis, "en", destination)
            first_hash = hashlib.sha256(first_path.read_bytes()).hexdigest()
            first_manifest = first_path.with_suffix(
                first_path.suffix + ".manifest.json"
            )
            first_manifest.unlink()
            second_path = render_report(analysis, "en", destination)
            second_hash = hashlib.sha256(
                second_path.read_bytes()
            ).hexdigest()
            self.assertEqual(second_hash, first_hash)
            self.assertEqual(
                second_hash,
                json.loads(first_manifest.read_text(encoding="utf-8"))["pdf_sha256"],
            )

    def test_missing_measurement_stays_unavailable(self) -> None:
        """Preserve absent values as unavailable instead of displaying zero.

        Returns:
            None: Assert format behavior and a complete missing-data report.
        """
        self.assertEqual(_number(None, "en"), "Unavailable")
        self.assertNotEqual(_number(None, "en"), _number(0.0, "en"))
        analysis = self._analysis("MSFS 2020", include_measurements=False)
        self.assertIsNone(analysis.samples[0].altitude_m)
        second_landing = replace(
            analysis.landings[0],
            index=2,
            first_contact_elapsed_seconds=119.0,
            last_contact_elapsed_seconds=120.0,
        )
        analysis.landings.append(second_landing)
        rows = _landing_rows(analysis, "en", _styles())
        self.assertEqual(len(rows), 5)
        self.assertIn("Latitude", rows[2][0].text)
        self.assertIn("Latitude", rows[4][0].text)
        with tempfile.TemporaryDirectory() as temporary:
            outputs = render_reports(analysis, ("en", "cs", "de", "sv"), Path(temporary))
            self.assertEqual(len(outputs), 4)

    def test_contact_tables_use_full_resolution_sensor_rows(self) -> None:
        """Expose retained contact location, local rate and sensor context.

        Returns:
            None: Assert contact tables use dedicated retained observations.
        """
        analysis = self._analysis("MSFS 2024")
        tables = _contact_sensor_tables(
            analysis.landings[0],
            "en",
            _styles(),
        )
        table_values = []
        for table in tables:
            if hasattr(table, "_cellvalues"):
                for row in table._cellvalues:
                    for cell in row:
                        if hasattr(cell, "text"):
                            table_values.append(cell.text)
        self.assertTrue(any("49.30000" in value for value in table_values))
        self.assertTrue(any("250.0 ft/min" in value for value in table_values))
        self.assertTrue(any("87.5 kt" in value for value in table_values))
        self.assertTrue(any("271.0°" in value for value in table_values))
        self.assertTrue(any("190.0°" in value for value in table_values))
        self.assertTrue(any("Gear position 25.0%" in value for value in table_values))
        self.assertTrue(any("1.0 g" in value for value in table_values))

    def test_route_chart_unwraps_dateline_within_a_contiguous_track(self) -> None:
        """Keep a dateline crossing local to its continuous recorded segment.

        Returns:
            None: Assert adjacent positions draw in the same longitude direction.
        """
        analysis = self._analysis("MSFS 2024")
        analysis.samples[0].longitude_deg = 179.8
        analysis.samples[1].longitude_deg = -179.8
        chart = _route_chart(analysis, "en")
        route_lines = [
            item
            for item in chart.contents
            if hasattr(item, "points")
        ]
        self.assertEqual(len(route_lines), 1)
        points = route_lines[0].points
        self.assertGreater(points[2], points[0])



    def test_contact_and_event_times_preserve_hundredths(self) -> None:
        """Keep 20 Hz contact and phase observations temporally distinct.

        Returns:
            None: Assert adjacent 0.05-second samples remain distinguishable.
        """
        analysis = self._analysis("MSFS 2024")
        landing = analysis.landings[0]
        template = landing.contact_samples[0]
        landing.contact_samples = [
            replace(template, session_elapsed_seconds=10.0),
            replace(template, session_elapsed_seconds=10.05),
            replace(template, session_elapsed_seconds=10.15),
        ]
        landing.first_contact_elapsed_seconds = 10.0
        contact_tables = _contact_sensor_tables(
            landing,
            "en",
            _styles(),
        )
        motion_table = contact_tables[1]
        state_table = contact_tables[2]
        self.assertEqual(
            [row[0].text for row in motion_table._cellvalues[1:]],
            ["+0.00 s", "+0.05 s", "+0.15 s"],
        )
        self.assertEqual(
            [row[0].text for row in state_table._cellvalues[1:]],
            ["+0.00 s", "+0.05 s", "+0.15 s"],
        )
        analysis.events = [
            FlightPhaseEvent(
                phase="touchdown",
                elapsed_seconds=10.0,
                confidence="high",
                evidence=["contact"],
            ),
            FlightPhaseEvent(
                phase="rollout",
                elapsed_seconds=10.05,
                confidence="high",
                evidence=["groundspeed"],
            ),
        ]
        timeline = _phase_event_table(analysis, "en", _styles())
        self.assertEqual(
            [row[0].text for row in timeline._cellvalues[1:]],
            ["+10.00 s", "+10.05 s"],
        )

    def test_full_analysis_hash_is_present_on_every_page(self) -> None:
        """Keep full report identity in the repeated footer.

        Returns:
            None: Assert the shared analysis checksum is drawn on each page.
        """
        canvas = Mock()
        document = SimpleNamespace(page=2)
        analysis_sha256 = "a" * 64
        _draw_page(canvas, document, "en", analysis_sha256)
        self.assertTrue(
            any(
                analysis_sha256 in str(call.args)
                for call in canvas.drawString.call_args_list
            )
        )

    def test_profile_titles_do_not_duplicate_units(self) -> None:
        """Keep unit-bearing profile titles readable in every language.

        Returns:
            None: Assert chart titles contain the localized label once.
        """
        analysis = self._analysis("MSFS 2024")
        for language in supported_languages():
            altitude = _profile_chart(
                analysis,
                "altitude_m",
                tr(language, "altitude"),
                _COLOR_BLUE,
                1.0,
                "m",
                language,
            )
            speed = _profile_chart(
                analysis,
                "ground_speed_mps",
                tr(language, "ground_speed"),
                _COLOR_GREEN,
                KNOTS_PER_MPS,
                "kt",
                language,
            )
            titles = [
                item.text
                for item in altitude.contents + speed.contents
                if getattr(item, "text", None) is not None
            ]
            self.assertIn(tr(language, "altitude"), titles)
            self.assertIn(tr(language, "ground_speed"), titles)
            self.assertNotIn(tr(language, "altitude") + " (m)", titles)
            self.assertNotIn(
                tr(language, "ground_speed") + " (kt)",
                titles,
            )

    def test_contact_headers_are_compact_and_localized(self) -> None:
        """Keep motion-table headers inside their columns in all languages.

        Returns:
            None: Assert each compact header is translated and rendered.
        """
        expected_keys = (
            "contact_elapsed_short",
            "latitude_short",
            "longitude_short",
            "contact_descent_short",
            "contact_ias_short",
            "contact_ground_speed_short",
            "contact_vertical_speed_short",
        )
        for language in supported_languages():
            table = _contact_sensor_tables(
                self._analysis("MSFS 2024").landings[0],
                language,
                _styles(),
            )[1]
            actual = [cell.text for cell in table._cellvalues[0]]
            expected = [tr(language, key) for key in expected_keys]
            self.assertEqual(actual, expected)

    def test_report_notes_redact_paths_and_credential_values(self) -> None:
        """Keep local paths and credential values out of report paragraphs.

        Returns:
            None: Assert sanitized warning text contains no private source value.
        """
        analysis = self._analysis("MSFS 2024")
        analysis.warnings = [
            r"Failed at C:\Users\Rudolf\flight.dll",
            "api_key=secret-marker",
        ]
        notes = _quality_notes(analysis, "en", _styles())
        rendered_text = "\n".join(note.text for note in notes)
        self.assertNotIn(r"C:\Users\Rudolf\flight.dll", rendered_text)
        self.assertNotIn("secret-marker", rendered_text)

    def test_method_notes_use_localized_algorithm_limits(self) -> None:
        """Expose the descent fit and capture limits in every report language.

        Returns:
            None: Assert the method disclosures are available and localized.
        """
        for language in supported_languages():
            notes = _methodology_notes(language, _styles())
            rendered_notes = "\n".join(note.text for note in notes)
            separator = "," if language in ("cs", "de", "sv") else "."
            for key in (
                "descent_method",
                "reference_method",
                "runway_method",
                "gap_method",
                "sampling_method",
                "contact_method",
                "landing_window_method",
            ):
                expected = tr(
                    language,
                    key,
                ).format(
                    window="1" + separator + "00",
                    minimum="0" + separator + "30",
                    maximum_gap="0" + separator + "25",
                    rate="20",
                    precontact="60" + separator + "00",
                    rollout="120" + separator + "00",
                )
                self.assertIn(expected, rendered_notes)

    def test_translation_fallback_and_central_european_glyphs(self) -> None:
        """Keep supported labels translated and required glyphs registered.

        Returns:
            None: Assert expected translations and bundled font registration.
        """
        self.assertEqual(tr("cs", "title"), "Zpráva po letu")
        self.assertEqual(tr("de", "title"), "Flugbericht")
        self.assertEqual(tr("sv", "title"), "Flygrapport")
        self.assertEqual(tr("xx", "title"), "Postflight report")
        for language in supported_languages():
            for key in (
                "flight_date",
                "analysis_version",
                "shared_checksum",
                "contact_observations",
                "location_caveat",
                "descent_method",
                "landing_window_method",
                "rollout_duration",
                "rollout_distance",
            ):
                self.assertNotEqual(tr(language, key), key)
        _register_font()
        font_path = _resource_directory() / "DejaVuSans.ttf"
        font = TTFont(_FONT_NAME, str(font_path))
        glyph_map = font.face.charToGlyph
        for character in _REQUIRED_GLYPHS:
            if character != " ":
                self.assertNotEqual(glyph_map.get(ord(character), 0), 0)


if __name__ == "__main__":
    unittest.main()
