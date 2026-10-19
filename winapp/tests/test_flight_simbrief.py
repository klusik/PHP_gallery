# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/tests/test_flight_simbrief.py
# Module Type: Flight Dispatch Regression Tests
# Purpose: Verify optional dispatch evidence and offline map behavior.
# Responsibilities:
#   - Cover identity classification, archived imports and session isolation.
#   - Exercise real map projection, offline rendering and AIRAC cache identity.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)
"""Portable dispatch tests without a SimBrief account or live tile service."""

import io
import json
import sqlite3
import tempfile
import unittest
from dataclasses import replace
from pathlib import Path
from unittest.mock import patch

from PIL import Image

from uploader.flight_maps import MAP_SIZE, MAP_DPI, map_viewport, print_size, project, render_map
from uploader.flight_navdata import remember_plan
from uploader.flight_simbrief import (
    DispatchPlan, DispatchPoint, fetch_dispatch, identity_query,
    import_dispatch, load_dispatch, parse_dispatch,
)


class FlightDispatchTests(unittest.TestCase):
    """Keep planned values optional, recoverable and separate from telemetry."""

    def plan(self) -> DispatchPlan:
        """Build explicit dispatch evidence for one short European route.

        Returns:
            DispatchPlan: LKPR to EDDF with a planned zero passenger quantity.
        """
        return parse_dispatch({
            "origin": {"icao_code": "LKPR", "pos_lat": "50.1", "pos_long": "14.2"},
            "destination": {"icao_code": "EDDF", "pos_lat": "50.0", "pos_long": "8.5"},
            "general": {"route": "DCT TEST DCT", "units": "kgs"},
            "weights": {"pax_count": "0"}, "params": {"airac": "2610"},
            "navlog": {"fix": {"ident": "TEST", "pos_lat": "50.0", "pos_long": "12.0"}},
        })

    def test_identity_query_is_one_encoded_provider_selector(self) -> None:
        """Distinguish numeric IDs and usernames while rejecting URL injection.

        Returns:
            None: IDs, usernames and malformed identities retain strict semantics.
        """
        self.assertEqual(identity_query(" 12345 "), {"userid": "12345", "json": "1"})
        self.assertEqual(identity_query("pilot.name"), {"username": "pilot.name", "json": "1"})
        for value in ("", "pilot&userid=2", "https://example.test", "１２３"):
            with self.assertRaises(ValueError):
                identity_query(value)

    def test_extended_dispatch_keeps_flight_identity_and_timed_profile_values(self) -> None:
        """Normalize OFP identity/loading plus measured-unit navlog plan fields.

        Returns:
            None: Leading zeroes, original mass units, zero counts and timed
                altitude/fuel evidence survive save/reload and legacy defaults.
        """
        plan = parse_dispatch({
            "origin": {"icao_code": "LKPR", "pos_lat": "50", "pos_long": "14"},
            "destination": {"icao_code": "EDDF", "pos_lat": "50", "pos_long": "8"},
            "general": {"iata_airline": "CY", "flight_number": "0734", "initial_altitude": "35000"},
            "weights": {"pax_count": "0", "cargo": "1200", "payload": "16000"},
            "params": {"units": "lbs"},
            "alternate": {"0": {"icao_code": "EDDL"}},
            "times": {"est_time_enroute": "3600", "sched_out": "1792300000"},
            "navlog": {"fix": {"ident": "TEST", "pos_lat": "50", "pos_long": "12",
                "time_total": "00:10:00", "altitude_feet": "30000", "fuel_plan_onboard": "16000"}},
        })
        self.assertEqual("CY0734", plan.flight_number)
        self.assertEqual("0", plan.passengers)
        self.assertEqual("lbs", plan.units)
        self.assertEqual("EDDL", plan.alternate)
        self.assertEqual((600.0, 30000.0, 16000.0), (
            plan.points[1].elapsed_seconds, plan.points[1].altitude_ft,
            plan.points[1].fuel_onboard,
        ))
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            plan.save(root)
            self.assertEqual(plan, load_dispatch(root))
            old = json.loads((root / "simbrief.json").read_text(encoding="utf-8"))
            del old["flight_number"]
            for point in old["points"]:
                point.pop("elapsed_seconds")
                point.pop("altitude_ft")
                point.pop("fuel_onboard")
            (root / "simbrief.json").write_text(json.dumps(old), encoding="utf-8")
            recovered = load_dispatch(root)
            self.assertIsNotNone(recovered)
            self.assertEqual("", recovered.flight_number)
            self.assertIsNone(recovered.points[1].elapsed_seconds)

    def test_map_print_size_has_native_pixels_and_density_metadata(self) -> None:
        """Create a ten-by-five-inch map directly at the requested print density.

        Returns:
            None: PNG carries 3000 by 1500 native pixels and 300-dpi metadata;
                no preview resize or external tile request occurs.
        """
        size = print_size(720, 360)  # PDF points: ten by five physical inches.
        self.assertEqual((3000, 1500), size)
        raw = render_map([(50, 14), (49, 12)], None, size=size, draw_track=False)
        with Image.open(io.BytesIO(raw)) as image:
            self.assertEqual(size, image.size)
            self.assertAlmostEqual(MAP_DPI, image.info["dpi"][0], delta=0.1)

    def test_route_fit_fills_both_print_viewports(self) -> None:
        """Fit short, long and dateline routes independently of print density.

        Returns:
            None: Full routes stay centered within margins and occupy eighty
                percent of at least one axis in preview and print canvases.
        """
        # Geographic vectors cover Japan, intercontinental and dateline flights.
        for points in (
            [(34.8, 135.4), (35.55, 139.78)],
            [(50.1, 14.2), (35.55, 139.78)],
            [(50.0, 179.0), None, (50.0, -179.0)],
        ):
            for size in (MAP_SIZE, print_size(720, 300)):
                viewport = map_viewport(points, size)
                self.assertIsNotNone(viewport)
                xs = [(p[0] * viewport.world - viewport.left) / size[0]
                      for p in viewport.points if p is not None]
                ys = [(p[1] * viewport.world - viewport.top) / size[1]
                      for p in viewport.points if p is not None]
                self.assertAlmostEqual(0.8, max(max(xs) - min(xs), max(ys) - min(ys)))
                self.assertAlmostEqual(1.0, min(xs) + max(xs))
                self.assertAlmostEqual(1.0, min(ys) + max(ys))
                self.assertGreaterEqual(min(xs + ys), 0.1 - 1e-9)
                self.assertLessEqual(max(xs + ys), 0.9 + 1e-9)

    def test_snapshots_are_optional_and_session_scoped(self) -> None:
        """Attach one plan without manufacturing data for another recording.

        Returns:
            None: Save/reload preserves zero passengers and exact route coordinates.
        """
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            self.assertIsNone(load_dispatch(root))
            plan = self.plan()
            plan.save(root / "first")
            self.assertEqual(load_dispatch(root / "first"), plan)
            self.assertIsNone(load_dispatch(root / "second"))
            self.assertEqual(plan.passengers, "0")
            self.assertEqual([p.ident for p in plan.points], ["LKPR", "TEST", "EDDF"])

    def test_archived_xml_and_json_share_normalization(self) -> None:
        """Load historic machine-readable OFPs without querying latest flight.

        Returns:
            None: Both formats preserve airport identities; DTD input is rejected.
        """
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            path = root / "ofp.xml"
            path.write_text("<OFP><origin><icao_code>LKPR</icao_code></origin>"
                "<destination><icao_code>EDDF</icao_code></destination></OFP>", encoding="utf-8")
            self.assertEqual(import_dispatch(path).origin, "LKPR")
            path.write_text('<!DOCTYPE OFP [<!ENTITY x "bad">]><OFP/>', encoding="utf-8")
            with self.assertRaises(ValueError):
                import_dispatch(path)
            path = root / "ofp.json"
            path.write_text(json.dumps({"origin": {"icao_code": "LKPR"},
                "destination": {"icao_code": "EDDF"}}), encoding="utf-8")
            self.assertEqual(import_dispatch(path).destination, "EDDF")

    def test_provider_error_does_not_become_a_plan(self) -> None:
        """Reject a successful HTTP transport carrying an OFP error object.

        Returns:
            None: Missing airport identity cannot be persisted as success.
        """
        with patch("uploader.flight_simbrief.urlopen") as transport:
            transport.return_value.__enter__.return_value.read.return_value = b'{"fetch":{"status":"Error"}}'
            with self.assertRaises(ValueError):
                fetch_dispatch("12345")

    def test_airac_cache_preserves_ambiguous_coordinates(self) -> None:
        """Keep same-name evidence distinct across cycles and locations.

        Returns:
            None: Navigation cache retains all three explicit coordinate variants.
        """
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            plan = replace(self.plan(), points=[DispatchPoint("TEST", 50, 12)])
            remember_plan(root, plan)
            remember_plan(root, replace(plan, airac="2611"))
            remember_plan(root, replace(plan, points=[DispatchPoint("TEST", 51, 13)]))
            with sqlite3.connect(root / "navdata.sqlite") as database:
                self.assertEqual(database.execute("SELECT COUNT(*) FROM fixes").fetchone()[0], 3)

    def test_offline_dateline_map_remains_printable(self) -> None:
        """Retain route graphics without tiles and wrap the international dateline.

        Returns:
            None: Real PNG has expected dimensions and bounded tile requests.
        """
        with tempfile.TemporaryDirectory() as temporary, patch(
            "uploader.flight_maps.urlopen", side_effect=OSError("offline")
        ) as transport:
            raw = render_map([(50, 179), None, (50, -179)], Path(temporary))
            self.assertIsNotNone(raw)
            with Image.open(io.BytesIO(raw)) as image:
                self.assertEqual(image.size, MAP_SIZE)
            # The overview viewport is three by two tiles plus edge overlap.
            self.assertLessEqual(transport.call_count, 12)
            self.assertIsNone(render_map([None], Path(temporary)))
            self.assertAlmostEqual(project(0, 0)[0], .5)
            self.assertAlmostEqual(project(0, 0)[1], .5)
