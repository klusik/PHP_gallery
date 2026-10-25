# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/tests/test_flight_render.py
# Module Type: Windows Tooling Regression Test
# Purpose: Verify simultaneous process rendering and complete-set barriers.
# Responsibilities:
#   - Prove language workers overlap in distinct spawned child processes.
#   - Retain successful outputs and drain running work on failure/cancellation.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)
"""Exercise the rendering coordinator independently of expensive PDF layout."""

import os
import tempfile
import threading
import time
import unittest
from concurrent.futures import ThreadPoolExecutor
from pathlib import Path
from unittest.mock import patch

from uploader.flight_data import FlightAnalysis
from uploader import flight_render

# Purpose: Bound process startup/barriers on slower hosted Windows machines.
# Type: float; Units: seconds; Scope: this concurrency fixture.
# Consumers: probe workers, event waits and joins. Rationale: 30 seconds allows
# spawned imports while a serial implementation must fail its first barrier.
PROBE_TIMEOUT_SECONDS = 30.0
# Purpose: Avoid busy-waiting while child marker files become visible.
# Type: float; Units: seconds; Scope: process overlap probe.
# Consumers: _probe_render. Rationale: 0.02 seconds keeps a small bounded probe
# responsive without depending on a timing-based performance assertion.
PROBE_POLL_SECONDS = 0.02


def _probe_render(
    analysis: FlightAnalysis, language: str, destination: Path,
    map_images: tuple[bytes, bytes],
) -> Path:
    """Require every selected language to start before any PDF can finish.

    Args:
        analysis (FlightAnalysis): Serializable flight fixture, unused here.
        language (str): Unique language marker filename.
        destination (Path): Probe output in the common temporary directory.
        map_images (tuple[bytes, bytes]): First entry encodes the barrier size;
            second entry is an unused map placeholder.
    Returns:
        Path: Completed probe containing its child process identity.
    Raises:
        TimeoutError: Not all language workers overlapped before the deadline.
    """
    destination.with_suffix(".ready").write_text(language, encoding="ascii")
    deadline = time.monotonic() + PROBE_TIMEOUT_SECONDS
    while len(list(destination.parent.glob("*.ready"))) != int(map_images[0]):
        if time.monotonic() >= deadline:
            raise TimeoutError("Language workers did not overlap")
        time.sleep(PROBE_POLL_SECONDS)
    destination.write_text(str(os.getpid()), encoding="ascii")
    return destination


class FlightRenderTests(unittest.TestCase):
    """Check real spawn overlap and deterministic cancellation/failure barriers."""

    def _analysis(self) -> FlightAnalysis:
        """Create credential-free data with no map/network prerequisites.

        Returns:
            FlightAnalysis: Empty normalized recording for coordinator probes.
        """
        return FlightAnalysis("probe", "MSFS", None, False, 0.0, None, [], [], [])

    def test_all_languages_overlap_in_distinct_spawned_processes(self) -> None:
        """Prove four language jobs really run together outside the parent.

        Returns:
            None: All children cross a shared barrier; maps prepare once and
                all progress callbacks run on the calling parent thread.
        """
        with tempfile.TemporaryDirectory() as temporary:
            destinations = {code: Path(temporary) / (code + ".pdf")
                            for code in ("en", "cs", "de", "sv")}
            callback_threads = []
            with patch("uploader.flight_report.prepare_report_maps",
                       return_value=(str(len(destinations)).encode("ascii"), b"")) as maps, \
                    patch.object(flight_render, "_render_language", _probe_render):
                outputs = flight_render.render_report_variants(
                    self._analysis(), destinations,
                    on_completed=lambda code, pdf: callback_threads.append(threading.get_ident()),
                )
            self.assertEqual(list(destinations), list(outputs))
            pids = {int(pdf.read_text(encoding="ascii")) for pdf in outputs.values()}
            self.assertEqual(len(destinations), len(pids))
            self.assertNotIn(os.getpid(), pids)
            self.assertEqual([threading.get_ident()] * len(destinations), callback_threads)
            maps.assert_called_once()

    def _assert_draining(self, fail: bool) -> None:
        """Keep one child running after its sibling fails or requests cancel.

        Args:
            fail (bool): Raise a renderer error rather than cancel after success.
        Returns:
            None: Coordinator cannot return until the surviving child finishes;
                every successful artifact reaches the parent completion hook.
        """
        with tempfile.TemporaryDirectory() as temporary:
            destinations = {code: Path(temporary) / (code + ".pdf")
                            for code in ("en", "cs")}
            barrier = threading.Barrier(len(destinations))
            release = threading.Event()
            first_settled = threading.Event()
            finished = threading.Event()
            cancel = threading.Event()
            completed = []
            errors = []

            def render(
                analysis: FlightAnalysis, language: str, destination: Path,
                map_images: tuple[bytes, bytes],
            ) -> Path:
                """Hold Czech work while English succeeds or fails.

                Args:
                    analysis (FlightAnalysis): Unused normalized fixture.
                    language (str): English trigger or Czech survivor.
                    destination (Path): Unique temporary probe output.
                    map_images (tuple[bytes, bytes]): Unused prepared PNG stubs.
                Returns:
                    Path: Successful probe file; English may raise RuntimeError.
                """
                barrier.wait(PROBE_TIMEOUT_SECONDS)
                if language == "en" and fail:
                    first_settled.set()
                    raise RuntimeError("English render failed")
                if language == "cs" and not release.wait(PROBE_TIMEOUT_SECONDS):
                    raise TimeoutError("Surviving renderer was not released")
                destination.write_bytes(b"%PDF-probe")
                return destination

            def retain(language: str, pdf: Path) -> None:
                """Record all successful editions even after stop is requested.

                Args:
                    language (str): Completed language.
                    pdf (Path): Existing probe output.
                Returns:
                    None: Cancels after English success in the cancellation case.
                """
                completed.append((language, pdf))
                if language == "en":
                    cancel.set()
                    first_settled.set()

            def run() -> None:
                """Capture the coordinator's terminal error on its owner thread.

                Returns:
                    None: Signals termination only after all children are joined.
                """
                try:
                    flight_render.render_report_variants(
                        self._analysis(), destinations, cancel, on_completed=retain,
                    )
                except Exception as exc:
                    errors.append(exc)
                finally:
                    finished.set()

            with patch("uploader.flight_report.prepare_report_maps", return_value=(b"", b"")), \
                    patch.object(flight_render, "_render_language", render), \
                    patch.object(flight_render, "ProcessPoolExecutor",
                        side_effect=lambda max_workers, mp_context:
                            ThreadPoolExecutor(max_workers=max_workers)):
                owner = threading.Thread(target=run)
                owner.start()
                try:
                    self.assertTrue(first_settled.wait(PROBE_TIMEOUT_SECONDS))
                    self.assertFalse(finished.is_set())
                finally:
                    release.set()
                    owner.join(PROBE_TIMEOUT_SECONDS)
                self.assertFalse(owner.is_alive())
            self.assertEqual(1, len(errors))
            self.assertIsInstance(errors[0], RuntimeError if fail else InterruptedError)
            self.assertEqual({"cs"} if fail else {"en", "cs"},
                             {code for code, pdf in completed if pdf.is_file()})

    def test_failure_drains_other_renderers_and_retains_successes(self) -> None:
        """Retain completed files without returning a partial successful set.

        Returns:
            None: The error follows the running-child barrier.
        """
        self._assert_draining(True)

    def test_cancellation_drains_running_renderers_and_retains_successes(self) -> None:
        """Keep both running editions when cancellation follows the first PDF.

        Returns:
            None: Cancellation follows all successful completion callbacks.
        """
        self._assert_draining(False)

    def test_invalid_batch_is_rejected_before_map_or_process_work(self) -> None:
        """Reject unsupported languages and aliased destinations before startup.

        Returns:
            None: No child or map preparation occurs for an invalid batch.
        """
        with tempfile.TemporaryDirectory() as temporary:
            pdf = Path(temporary) / "same.pdf"
            with patch("uploader.flight_report.prepare_report_maps") as maps, \
                    patch.object(flight_render, "ProcessPoolExecutor") as pool:
                for destinations in ({}, {"xx": pdf}, {"en": pdf, "cs": pdf}):
                    with self.subTest(destinations=destinations), self.assertRaises(ValueError):
                        flight_render.render_report_variants(self._analysis(), destinations)
                maps.assert_not_called()
                pool.assert_not_called()
