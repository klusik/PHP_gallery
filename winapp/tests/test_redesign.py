# Project: PHP Gallery
# Module Type: Windows Tooling
# Purpose: Verify deterministic Windows uploader redesign behavior.
# Responsibilities:
#   - Exercise import, state and interface helpers without a live gallery server.
# Repository: https://github.com/klusik/PHP_gallery
#
# File: winapp/tests/test_redesign.py
#
# Author:
#   Rudolf Klusal
#
# License:
#   MIT License (see LICENSE file in repository)
#
# Notes:
#   - Keep comments and docstrings intact when modifying this file.
"""Focused deterministic tests for the Windows uploader redesign."""

import importlib.machinery
import importlib.util
import io
import json
import os
import shutil
import sys
import tempfile
import time
import types
import unittest
from unittest import mock
import zipfile
from pathlib import Path

WINAPP_DIR = Path(__file__).resolve().parents[1]
if str(WINAPP_DIR) not in sys.path:
    sys.path.insert(0, str(WINAPP_DIR))

from uploader.config import CONFIG_SCHEMA_VERSION, DEFAULTS, migrate_config_payload
from uploader.diagnostics import redact_text
from uploader.discovery import ArchiveLimits, cleanup_stale_staging, discover_folder, discover_selected_files, stage_zip_archive
from uploader.media import detect_suffix_capabilities, probe_file
from uploader.models import ImportItem, ImportJob, RECOVERABLE_ITEM_STATES
from uploader.state_store import JobStateStore, atomic_write_json, load_upload_state


def load_main_module():
    """Load the compatibility .pyw entry point without starting Tkinter."""
    path = WINAPP_DIR / "gallery_watch_upload.pyw"
    loader = importlib.machinery.SourceFileLoader("gallery_watch_upload_test", str(path))
    spec = importlib.util.spec_from_loader(loader.name, loader)
    if spec is None:
        raise RuntimeError("Unable to create test module spec")
    module = importlib.util.module_from_spec(spec)
    loader.exec_module(module)
    return module


MAIN = load_main_module()


class FrozenDependencyTests(unittest.TestCase):
    """A frozen executable must never relaunch itself as a pip interpreter."""

    def test_frozen_dependency_actions_never_spawn_pip(self):
        with mock.patch.object(MAIN.sys, "frozen", True, create=True), mock.patch.object(MAIN.subprocess, "run") as run:
            with mock.patch.object(MAIN, "Image", object()), mock.patch.object(MAIN, "pystray", object()):
                self.assertTrue(MAIN.install_dependencies_for_current_runtime()[0])
            with mock.patch.object(MAIN, "Image", None):
                ok, message = MAIN.install_dependencies_for_current_runtime()
                self.assertFalse(ok)
                self.assertIn("Reinstall", message)
            ok, message = MAIN.install_semantic_ai_dependencies_for_current_runtime()
            self.assertFalse(ok)
            self.assertIn("Ollama", message)
            run.assert_not_called()


class ConfigStateTests(unittest.TestCase):
    """Configuration, persistence, migration, and redaction contracts."""

    def test_restart_restores_saved_paths_and_local_options(self) -> None:
        """Keep prior folder, runtime and uploader choices across a new store.

        Returns:
            None: A fresh app configuration reader recovers the saved settings.
        """
        with tempfile.TemporaryDirectory() as tmp:
            path = Path(tmp) / "config.json"
            saved = MAIN.WatcherConfig(
                watched_folder=str(Path(tmp) / "screenshots"),
                simconnect_dll_path=str(Path(tmp) / "SimConnect.dll"),
                gallery_url="https://gallery.test/index.php?page=upload_automation_upload",
                api_key="test-gallery-key",
                watch_recursive=True,
                sync_utc_time=True,
                delete_uploaded_files=False,
            )
            MAIN.ConfigStore(path).save(saved)
            restored = MAIN.ConfigStore(path).load()
            self.assertEqual(saved.to_dict(), restored.to_dict())

    def test_config_migration_preserves_legacy_keys_and_clamps_new_values(self):
        payload = {
            "gallery_url": "https://example.test",
            "api_key": "secret",
            "archive_max_entries": -4,
            "archive_max_compression_ratio": "999999",
            "manual_thumbnail_mode": "nonsense",
        }
        migrated, changed = migrate_config_payload(payload)
        self.assertTrue(changed)
        self.assertEqual(CONFIG_SCHEMA_VERSION, migrated["schema_version"])
        self.assertEqual("https://example.test", migrated["gallery_url"])
        self.assertEqual("secret", migrated["api_key"])
        self.assertEqual(10, migrated["archive_max_entries"])
        self.assertEqual(10000.0, migrated["archive_max_compression_ratio"])
        self.assertEqual("server", migrated["manual_thumbnail_mode"])

    def test_config_defaults_are_conservative(self):
        migrated, _ = migrate_config_payload({})
        self.assertFalse(migrated["watch_recursive"])
        self.assertEqual("server", migrated["manual_thumbnail_mode"])
        self.assertTrue(migrated["close_to_tray"])
        self.assertEqual(DEFAULTS["activity_history_limit"], migrated["activity_history_limit"])

    def test_atomic_state_write_and_restart_job_recovery(self):
        with tempfile.TemporaryDirectory() as tmp:
            state = Path(tmp) / "state.json"
            atomic_write_json(state, {"schema_version": 2, "uploaded_paths": {}, "uploaded_hashes": {}, "failures": {}, "jobs": {}, "recent_events": []})
            store = JobStateStore(state)
            source = Path(tmp) / "photo.jpg"
            source.write_bytes(b"jpeg-like")
            job = ImportJob.create("files", "one file", "gallery")
            item = ImportItem.create(source)
            item.transition("queued")
            job.items = [item]
            job.bytes_total = item.size
            store.save_job(job)
            recovered = store.recoverable_jobs()
            self.assertEqual([job.id], [entry.id for entry in recovered])
            self.assertEqual("queued", recovered[0].items[0].state)

    def test_malformed_state_is_quarantined(self):
        with tempfile.TemporaryDirectory() as tmp:
            state = Path(tmp) / "upload_state.json"
            state.write_text("{broken", encoding="utf-8")
            loaded = load_upload_state(state, quarantine_bad=True)
            self.assertEqual(2, loaded["schema_version"])
            quarantined = list(Path(tmp).glob("upload_state.json.malformed-*"))
            self.assertEqual(1, len(quarantined))

    def test_secret_and_path_redaction(self):
        secret = "API-KEY-123"
        source = f"API key: {secret}\nAuthorization=Bearer-X\nC:\\Users\\Someone\\Pictures\\private.jpg"
        redacted = redact_text(source, [secret], redact_paths=True)
        self.assertNotIn(secret, redacted)
        self.assertNotIn("private.jpg", redacted)
        self.assertIn("[REDACTED]", redacted)
        self.assertIn("[LOCAL PATH]", redacted)


class URLAndUploadStateTests(unittest.TestCase):
    """Endpoint normalization, stability, deduplication, and retry behavior."""

    def test_url_normalization(self):
        self.assertEqual("", MAIN.normalize_upload_url("ftp://example.test/gallery"))
        self.assertEqual("https://example.test/index.php?page=upload_automation_upload", MAIN.normalize_upload_url("example.test"))
        self.assertEqual("https://example.test/index.php?page=upload_automation_upload", MAIN.normalize_upload_url("https://example.test/index.php"))
        self.assertEqual("https://example.test/index.php?page=upload_automation_upload", MAIN.normalize_upload_url("https://example.test/api/upload"))

    def test_config_store_migrates_clean_api_alias_to_canonical_query_endpoint(self):
        with tempfile.TemporaryDirectory() as tmp:
            path = Path(tmp) / "config.json"
            config = MAIN.WatcherConfig(gallery_url="https://example.test/api/upload", api_key="pgu_secret")
            path.write_text(json.dumps(config.to_dict()), encoding="utf-8")
            loaded = MAIN.ConfigStore(path).load()
            self.assertEqual("https://example.test/index.php?page=upload_automation_upload", loaded.gallery_url)
            persisted = json.loads(path.read_text(encoding="utf-8"))
            self.assertEqual("https://example.test/index.php?page=upload_automation_upload", persisted["gallery_url"])

    def test_endpoint_candidates_canonicalize_clean_route_and_add_safe_alternate(self):
        self.assertEqual(
            [
                "https://example.test/index.php?page=upload_automation_upload",
                "https://example.test/api/upload",
            ],
            MAIN.upload_endpoint_candidates("https://example.test/index.php?page=upload_automation_upload"),
        )
        self.assertEqual(
            [
                "https://example.test/gallery/index.php?page=upload_automation_upload",
                "https://example.test/gallery/api/upload",
            ],
            MAIN.upload_endpoint_candidates("https://example.test/gallery/api/upload"),
        )

    def test_extension_filtering_and_recursion_default(self):
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp)
            (root / "a.jpg").write_bytes(b"a")
            (root / "b.txt").write_text("x", encoding="utf-8")
            child = root / "child"
            child.mkdir()
            (child / "c.png").write_bytes(b"c")
            flat = discover_folder(root, {".jpg", ".png"}, recursive=False)
            recursive = discover_folder(root, {".jpg", ".png"}, recursive=True)
            self.assertEqual(1, len(flat.items))
            self.assertEqual(2, len(recursive.items))
            self.assertEqual(1, flat.unsupported.get(".txt", 0))

    def test_file_stability_waits_for_unchanged_interval(self):
        with tempfile.TemporaryDirectory() as tmp:
            path = Path(tmp) / "copy.jpg"
            path.write_bytes(b"first")
            tracker = MAIN.FileStabilityTracker(0.5)
            self.assertFalse(tracker.stable(path))
            tracker._seen[path] = (path.stat().st_size, path.stat().st_mtime, time.time() - 1.0)
            self.assertTrue(tracker.stable(path))
            path.write_bytes(b"changed content")
            self.assertFalse(tracker.stable(path))

    def test_multipart_upload_falls_back_from_routing_404(self):
        with tempfile.TemporaryDirectory() as tmp:
            path = Path(tmp) / "photo.jpg"
            path.write_bytes(b"jpeg-like")
            calls = []

            class FakeResponse:
                def __enter__(self):
                    return self

                def __exit__(self, _exc_type, _exc, _tb):
                    return False

                def read(self):
                    return b'{"ok":true,"uploaded":1,"stored":["photo.jpg"]}'

            original = MAIN.request.urlopen

            def fake_urlopen(http_request, timeout):
                calls.append(http_request.full_url)
                if len(calls) == 1:
                    raise MAIN.error.HTTPError(
                        http_request.full_url,
                        404,
                        "Not Found",
                        {},
                        io.BytesIO(b"<html><body>Not Found</body></html>"),
                    )
                return FakeResponse()

            MAIN.request.urlopen = fake_urlopen
            try:
                result = MAIN.multipart_upload(
                    "https://example.test/index.php?page=upload_automation_upload",
                    "pgu_secret",
                    path,
                    False,
                )
            finally:
                MAIN.request.urlopen = original

            self.assertTrue(result["ok"])
            self.assertEqual(
                [
                    "https://example.test/index.php?page=upload_automation_upload",
                    "https://example.test/api/upload",
                ],
                calls,
            )

    def test_upload_state_hash_dedup_and_retry_backoff(self):
        with tempfile.TemporaryDirectory() as tmp:
            state_path = Path(tmp) / "state.json"
            source = Path(tmp) / "photo.jpg"
            source.write_bytes(b"photo")
            state = MAIN.UploadState(state_path)
            file_hash = MAIN.sha256_file(source)
            state.mark_uploaded(source, file_hash, source.stat().st_size, {"ok": True, "uploaded": 1})
            self.assertTrue(state.already_uploaded_path(source, file_hash))
            self.assertTrue(state.already_uploaded_hash(file_hash))
            other = Path(tmp) / "other.jpg"
            other.write_bytes(b"other")
            other_hash = MAIN.sha256_file(other)
            state.mark_failure(other, other_hash, "temporary")
            self.assertFalse(state.retry_allowed(other, other_hash))
            self.assertTrue(state.retry_allowed(other, "different-hash"))


class ZipSafetyTests(unittest.TestCase):
    """Archive staging rejects unsafe paths and bounded-resource violations."""

    def limits(self, **overrides):
        values = dict(max_entries=50, max_uncompressed_bytes=1024 * 1024, max_file_bytes=256 * 1024, max_compression_ratio=100.0)
        values.update(overrides)
        return ArchiveLimits(**values)

    def test_zip_rejects_traversal_absolute_drive_and_symlink_entries(self):
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp)
            archive_path = root / "input.zip"
            symlink = zipfile.ZipInfo("link.jpg")
            symlink.create_system = 3
            symlink.external_attr = (0o120777 << 16)
            with zipfile.ZipFile(archive_path, "w") as archive:
                archive.writestr("good/photo.jpg", b"good")
                archive.writestr("../escape.jpg", b"bad")
                archive.writestr("/absolute.jpg", b"bad")
                archive.writestr("C:/drive.jpg", b"bad")
                archive.writestr(symlink, b"target")
            result = stage_zip_archive(archive_path, root / "staging", "job1", {".jpg"}, self.limits())
            self.assertEqual(1, len(result.items))
            self.assertGreaterEqual(len(result.unsafe_entries), 4)
            self.assertFalse((root / "escape.jpg").exists())

    def test_zip_skips_unsupported_without_extracting(self):
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp)
            archive_path = root / "input.zip"
            with zipfile.ZipFile(archive_path, "w") as archive:
                archive.writestr("photo.jpg", b"good")
                archive.writestr("notes.txt", b"secret notes")
            result = stage_zip_archive(archive_path, root / "staging", "job2", {".jpg"}, self.limits())
            self.assertEqual(1, len(result.items))
            self.assertEqual(1, result.unsupported.get(".txt", 0))
            staged = Path(result.staging_dir)
            self.assertEqual(["photo.jpg"], sorted(path.name for path in staged.iterdir()))

    def test_zip_entry_count_limit_extracts_nothing(self):
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp)
            archive_path = root / "many.zip"
            with zipfile.ZipFile(archive_path, "w") as archive:
                for index in range(3):
                    archive.writestr(f"{index}.jpg", b"x")
            result = stage_zip_archive(archive_path, root / "staging", "job3", {".jpg"}, self.limits(max_entries=2))
            self.assertEqual([], result.items)
            self.assertEqual("", result.staging_dir)
            self.assertTrue(any("entr" in warning.lower() for warning in result.warnings))

    def test_zip_per_file_and_ratio_limits(self):
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp)
            big = root / "big.zip"
            with zipfile.ZipFile(big, "w", compression=zipfile.ZIP_DEFLATED) as archive:
                archive.writestr("big.jpg", b"A" * 8192)
            size_limited = stage_zip_archive(big, root / "staging1", "job4", {".jpg"}, self.limits(max_file_bytes=1024))
            self.assertEqual([], size_limited.items)
            ratio_limited = stage_zip_archive(big, root / "staging2", "job5", {".jpg"}, self.limits(max_compression_ratio=2.0))
            self.assertEqual([], ratio_limited.items)
            self.assertTrue(ratio_limited.warnings or ratio_limited.unsafe_entries)

    def test_malformed_zip_and_stale_cleanup(self):
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp)
            bad = root / "bad.zip"
            bad.write_bytes(b"not a zip")
            result = stage_zip_archive(bad, root / "staging", "job6", {".jpg"}, self.limits())
            self.assertEqual([], result.items)
            self.assertTrue(result.warnings)
            old = root / "cleanup" / "job-old"
            active = root / "cleanup" / "job-active"
            old.mkdir(parents=True)
            active.mkdir(parents=True)
            timestamp = time.time() - 48 * 3600
            os.utime(old, (timestamp, timestamp))
            os.utime(active, (timestamp, timestamp))
            removed = cleanup_stale_staging(root / "cleanup", 24.0, ["active"])
            self.assertIn("job-old", removed)
            self.assertTrue(active.exists())


class MediaJobLifecycleTests(unittest.TestCase):
    """Media capability and durable job state contracts."""

    class FakeImageModule:
        """Minimal Pillow-like module for deterministic capability tests."""

        @staticmethod
        def registered_extensions():
            return {".jpg": "JPEG", ".heic": "HEIC"}

        @staticmethod
        def open(_path):
            raise ValueError("decoder failed")

    def test_heic_dng_capabilities_do_not_conflate_server_and_local_support(self):
        caps = detect_suffix_capabilities({".jpg", ".heic", ".dng"}, self.FakeImageModule)
        self.assertTrue(caps[".heic"].server_uploadable)
        self.assertTrue(caps[".heic"].local_thumbnailable)
        self.assertTrue(caps[".dng"].server_uploadable)
        self.assertFalse(caps[".dng"].local_previewable)

    def test_decoder_failure_is_isolated_and_still_server_uploadable(self):
        with tempfile.TemporaryDirectory() as tmp:
            source = Path(tmp) / "broken.heic"
            source.write_bytes(b"broken")
            cap = probe_file(source, {".heic"}, self.FakeImageModule)
            self.assertTrue(cap.server_uploadable)
            self.assertFalse(cap.local_previewable)
            self.assertIn("failed", cap.detail.lower())

    def test_job_transitions_progress_and_recovery(self):
        with tempfile.TemporaryDirectory() as tmp:
            source = Path(tmp) / "a.jpg"
            source.write_bytes(b"1234")
            item = ImportItem.create(source)
            item.transition("queued")
            job = ImportJob.create("files", "a.jpg", "gallery")
            job.items = [item]
            job.bytes_total = item.size
            self.assertTrue(job.recoverable())
            item.transition("uploading")
            self.assertIn(item.state, RECOVERABLE_ITEM_STATES)
            item.transition("failed_retryable", "network")
            self.assertEqual(1, job.counts()["failed"])
            self.assertTrue(job.recoverable())
            item.transition("confirmed")
            job.bytes_sent = item.size
            job.finished_at = time.time()
            self.assertEqual(1, job.counts()["uploaded"])
            self.assertFalse(job.recoverable())

    def test_manual_worker_pause_resume_cancel_are_explicit_events(self):
        with tempfile.TemporaryDirectory() as tmp:
            source = Path(tmp) / "a.jpg"
            source.write_bytes(b"x")
            job = ImportJob.create("files", "a.jpg", "gallery")
            job.items = [ImportItem.create(source)]
            events = MAIN.queue.Queue()
            config = MAIN.WatcherConfig(gallery_url="https://example.test", api_key="key")
            worker = MAIN.ManualUploadThread(config, [source], False, 1, 1, events, job=job, job_store=None)
            worker.pause()
            self.assertTrue(worker.pause_event.is_set())
            worker.resume()
            self.assertFalse(worker.pause_event.is_set())
            worker.stop()
            self.assertTrue(worker.stop_event.is_set())


class IsolationAndCallbackTests(unittest.TestCase):
    """No-live-gallery isolation checks for optional integrations and tray bridge."""

    def test_remote_confirmation_object_starts_empty(self):
        events = []
        session = MAIN.RemoteInventorySession(30.0, lambda level, message: events.append((level, message)))
        self.assertFalse(session.has_hash("a" * 64))

    def test_remote_confirmation_after_ambiguous_failure(self):
        events = []
        digest = "b" * 64
        session = MAIN.RemoteInventorySession(30.0, lambda level, message: events.append((level, message)))
        original = MAIN.post_json
        MAIN.post_json = lambda *_args, **_kwargs: {"ok": True, "checked": 1, "existing": [{"sha256": digest}]}
        try:
            confirmed = session.confirm_after_failure("https://example.test/api/upload", "key", {"sha256": digest, "name": "a.jpg", "size": 1})
        finally:
            MAIN.post_json = original
        self.assertTrue(confirmed)
        self.assertTrue(session.has_hash(digest))

    def test_logging_uses_bounded_rotation(self):
        import logging
        from logging.handlers import RotatingFileHandler

        with tempfile.TemporaryDirectory() as tmp:
            root_logger = logging.getLogger()
            original_handlers = list(root_logger.handlers)
            original_dir = MAIN.CONFIG_DIR
            original_log = MAIN.LOG_PATH
            for handler in list(root_logger.handlers):
                root_logger.removeHandler(handler)
            MAIN.CONFIG_DIR = Path(tmp)
            MAIN.LOG_PATH = Path(tmp) / "watcher.log"
            try:
                MAIN.setup_logging()
                handlers = [handler for handler in root_logger.handlers if isinstance(handler, RotatingFileHandler)]
                self.assertEqual(1, len(handlers))
                self.assertEqual(2 * 1024 * 1024, handlers[0].maxBytes)
                self.assertEqual(3, handlers[0].backupCount)
            finally:
                for handler in list(root_logger.handlers):
                    root_logger.removeHandler(handler)
                    handler.close()
                for handler in original_handlers:
                    root_logger.addHandler(handler)
                MAIN.CONFIG_DIR = original_dir
                MAIN.LOG_PATH = original_log

    def test_simconnect_missing_override_is_nonfatal(self):
        missing_path = Path("Z:/definitely/missing/SimConnect.dll")
        client = MAIN.SimConnectCameraClient(str(missing_path))

        # Keep the test independent from the developer workstation. A Windows host may have a
        # valid SimConnect.dll in an SDK/common location, so an invalid override alone does not
        # guarantee that automatic fallback discovery will fail.
        with mock.patch.object(MAIN.os, "name", "nt"), mock.patch.object(
            client,
            "resolve_dll_path",
            return_value=(None, [missing_path]),
        ):
            location, message = client.current_camera_location()

        self.assertIsNone(location)
        self.assertIn("SimConnect.dll is unavailable", message)
        self.assertIn(str(missing_path), message)

    def test_ai_stop_is_independent_from_upload_worker_state(self):
        config = MAIN.WatcherConfig(gallery_url="https://example.test", api_key="key", ai_worker_enabled=True)
        events = MAIN.queue.Queue()
        worker = MAIN.AIAnalysisWorkerThread(config, events)
        worker.stop()
        self.assertTrue(worker.stop_event.is_set())

    def test_tray_callbacks_schedule_on_ui_thread(self):
        called = []

        class FakeRoot:
            def after(self, _delay, callback):
                called.append(callback)

        fake = object.__new__(MAIN.WatcherApp)
        fake.exiting = False
        fake.root = FakeRoot()
        fake.start = lambda: called.append("started")
        MAIN.WatcherApp.tray_start_watching(fake)
        self.assertEqual(1, len(called))
        called[0]()
        self.assertIn("started", called)

    def test_api_key_request_blockers_are_specific(self):
        class AliveWorker:
            def is_alive(self):
                return True

        fake = object.__new__(MAIN.WatcherApp)
        fake.worker = AliveWorker()
        fake.manual_worker = None
        fake.preflight_worker = AliveWorker()
        fake.ai_worker = None
        fake.connection_test_running = True
        blockers = MAIN.WatcherApp.api_key_request_blockers(fake)
        self.assertEqual(
            [
                "watch-folder uploader is running",
                "import preflight is running",
                "connection test is running",
            ],
            blockers,
        )

    def test_revoke_upload_key_uses_current_key_and_revoke_action(self):
        captured = {}

        class FakeResponse:
            def __enter__(self):
                return self

            def __exit__(self, _exc_type, _exc, _tb):
                return False

            def read(self):
                return b'{"ok":true,"action":"revoke","message":"API key revoked."}'

        original = MAIN.request.urlopen

        def fake_urlopen(http_request, timeout):
            captured["url"] = http_request.full_url
            captured["body"] = json.loads(http_request.data.decode("utf-8"))
            captured["api_key"] = http_request.get_header("X-gallery-api-key")
            captured["timeout"] = timeout
            return FakeResponse()

        MAIN.request.urlopen = fake_urlopen
        try:
            result = MAIN.revoke_upload_key("https://example.test/api/upload", "pgu_secret")
        finally:
            MAIN.request.urlopen = original

        self.assertTrue(result["ok"])
        self.assertEqual("https://example.test/index.php?page=upload_automation_upload", captured["url"])
        self.assertEqual({"action": "revoke"}, captured["body"])
        self.assertEqual("pgu_secret", captured["api_key"])
        self.assertGreater(captured["timeout"], 0)

    def test_revoke_falls_back_from_non_json_index_404_to_clean_route(self):
        calls = []

        class FakeResponse:
            def __enter__(self):
                return self

            def __exit__(self, _exc_type, _exc, _tb):
                return False

            def read(self):
                return b'{"ok":true,"action":"revoke","message":"API key revoked."}'

        original = MAIN.request.urlopen

        def fake_urlopen(http_request, timeout):
            calls.append(http_request.full_url)
            if len(calls) == 1:
                raise MAIN.error.HTTPError(
                    http_request.full_url,
                    404,
                    "Not Found",
                    {},
                    io.BytesIO(b"<!DOCTYPE HTML><html><body>Not Found</body></html>"),
                )
            return FakeResponse()

        MAIN.request.urlopen = fake_urlopen
        try:
            result = MAIN.revoke_upload_key(
                "https://example.test/index.php?page=upload_automation_upload",
                "pgu_secret",
            )
        finally:
            MAIN.request.urlopen = original

        self.assertTrue(result["ok"])
        self.assertEqual(
            [
                "https://example.test/index.php?page=upload_automation_upload",
                "https://example.test/api/upload",
            ],
            calls,
        )

    def test_upload_worker_count_is_single_request_for_shared_hosting(self):
        self.assertEqual(1, MAIN.DEFAULT_UPLOAD_WORKERS)
        self.assertEqual(1, MAIN.MAX_UPLOAD_WORKERS)
        self.assertEqual(1, MAIN.resolve_upload_worker_count(0))
        self.assertEqual(1, MAIN.resolve_upload_worker_count(8))

    def test_inventory_reconciliation_only_runs_for_ambiguous_transfer_failures(self):
        self.assertTrue(MAIN.upload_failure_needs_inventory_reconciliation(RuntimeError("HTTP 502: Bad Gateway")))
        self.assertTrue(MAIN.upload_failure_needs_inventory_reconciliation(RuntimeError("The read operation timed out")))
        self.assertTrue(MAIN.upload_failure_needs_inventory_reconciliation(RuntimeError("Remote end closed connection without response")))
        self.assertFalse(MAIN.upload_failure_needs_inventory_reconciliation(RuntimeError("HTTP 401: <html>Unauthorized</html>")))
        self.assertFalse(MAIN.upload_failure_needs_inventory_reconciliation(RuntimeError("HTTP 403: Forbidden")))
        self.assertFalse(MAIN.upload_failure_needs_inventory_reconciliation(RuntimeError("Invalid or revoked API key.")))

    def test_remote_inventory_is_database_only_until_failure_reconciliation(self):
        payloads = []
        original_post_json = MAIN.post_json

        def fake_post_json(upload_url, api_key, payload, timeout_seconds=MAIN.DEFAULT_TIMEOUT_SECONDS):
            payloads.append(payload)
            return {"ok": True, "existing": [], "checked": len(payload.get("files", []))}

        MAIN.post_json = fake_post_json
        try:
            session = MAIN.RemoteInventorySession(1, lambda _level, _message: None)
            candidate = {"client_id": "x", "filename": "x.jpg", "size": 123, "sha256": "a" * 64}
            self.assertTrue(session.refresh("https://example.test/api/upload", "pgu_secret", [candidate], force=True))
            session.confirm_after_failure("https://example.test/api/upload", "pgu_secret", candidate)
        finally:
            MAIN.post_json = original_post_json

        self.assertFalse(payloads[0]["deep_check"])
        self.assertTrue(payloads[1]["deep_check"])

    def test_revoke_retries_one_gateway_failure_and_accepts_revoked_confirmation(self):
        calls = []
        original_post_json = MAIN.post_json
        original_sleep = MAIN.time.sleep

        def fake_post_json(upload_url, api_key, payload, timeout_seconds=MAIN.DEFAULT_TIMEOUT_SECONDS):
            calls.append((upload_url, api_key, payload))
            if len(calls) == 1:
                raise RuntimeError("HTTP 502: <html><body><h1>502 Bad Gateway</h1></body></html>")
            raise RuntimeError("Invalid or revoked API key.")

        MAIN.post_json = fake_post_json
        MAIN.time.sleep = lambda _seconds: None
        try:
            result = MAIN.revoke_upload_key("https://example.test/api/upload", "pgu_secret")
        finally:
            MAIN.post_json = original_post_json
            MAIN.time.sleep = original_sleep

        self.assertTrue(result["ok"])
        self.assertTrue(result["reconciled_after_gateway_error"])
        self.assertEqual(2, len(calls))
        self.assertEqual({"action": "revoke"}, calls[0][2])
        self.assertEqual({"action": "revoke"}, calls[1][2])


    def test_watcher_uses_local_thumbnails_to_avoid_server_batch_generation(self):
        with tempfile.TemporaryDirectory() as tmp:
            source = Path(tmp) / "photo.jpg"
            source.write_bytes(b"source")
            calls = []
            messages = []
            original_supported = MAIN.local_thumbnail_supported
            original_generate = MAIN.generate_local_thumbnails
            original_upload = MAIN.multipart_upload

            def fake_generate(path, output_root, client_upload_id):
                generated = output_root / client_upload_id / "photo_thumb300.jpg"
                generated.parent.mkdir(parents=True, exist_ok=True)
                generated.write_bytes(b"thumb")
                return [MAIN.LocalThumbnail(path=generated, size=300, format="jpg")]

            def fake_upload(upload_url, api_key, path, create_thumbnails, thumbnails=None, client_upload_id=None, metadata_fields=None):
                calls.append({
                    "upload_url": upload_url,
                    "api_key": api_key,
                    "path": path,
                    "create_thumbnails": create_thumbnails,
                    "thumbnail_count": len(thumbnails or []),
                    "client_upload_id": client_upload_id,
                    "metadata_fields": metadata_fields,
                })
                return {"ok": True, "uploaded": 1, "scanned": 1}

            MAIN.local_thumbnail_supported = lambda: True
            MAIN.generate_local_thumbnails = fake_generate
            MAIN.multipart_upload = fake_upload
            watcher = object.__new__(MAIN.WatcherThread)
            watcher.config = MAIN.WatcherConfig(api_key="pgu_secret", create_thumbnails=True)
            watcher.emit = lambda level, message: messages.append((level, message))
            try:
                result = watcher.upload_watched_file("https://example.test/api/upload", source, {"sim_camera_lat": "49.0"})
            finally:
                MAIN.local_thumbnail_supported = original_supported
                MAIN.generate_local_thumbnails = original_generate
                MAIN.multipart_upload = original_upload

            self.assertTrue(result["ok"])
            self.assertEqual(1, len(calls))
            self.assertFalse(calls[0]["create_thumbnails"])
            self.assertEqual(1, calls[0]["thumbnail_count"])
            self.assertTrue(calls[0]["client_upload_id"])
            self.assertEqual({"sim_camera_lat": "49.0"}, calls[0]["metadata_fields"])
            self.assertTrue(any("server-side batch generation skipped" in message for _level, message in messages))

    def test_gallery_json_404_does_not_trigger_route_fallback(self):
        calls = []
        original = MAIN.request.urlopen

        def fake_urlopen(http_request, timeout):
            calls.append(http_request.full_url)
            raise MAIN.error.HTTPError(
                http_request.full_url,
                404,
                "Not Found",
                {"Content-Type": "application/json"},
                io.BytesIO(b'{"ok":false,"error":"The gallery assigned to this API key no longer exists."}'),
            )

        MAIN.request.urlopen = fake_urlopen
        try:
            with self.assertRaisesRegex(RuntimeError, "gallery assigned"):
                MAIN.post_json(
                    "https://example.test/index.php?page=upload_automation_upload",
                    "pgu_secret",
                    {"action": "inventory", "files": []},
                )
        finally:
            MAIN.request.urlopen = original

        self.assertEqual(["https://example.test/index.php?page=upload_automation_upload"], calls)


class SimConnectActivityTests(unittest.TestCase):
    """Keep queue routing separate from visible and persisted activity text."""

    def test_simconnect_event_routes_before_visible_marker_removal(self) -> None:
        """Keep the SimConnect operation and severity while cleaning its UI text."""
        app = mock.Mock()
        app.exiting = False
        app.events = MAIN.queue.Queue()
        app.events.put(("warning", "[simconnect] Aircraft data packet rejected."))
        app.events.put(("info", "Watching folder"))
        app.manual_worker = None
        app.current_job = None
        app.classify_log_level.side_effect = ["warning", "success"]

        MAIN.WatcherApp.drain_events(app)

        app.classify_log_level.assert_any_call("warning", "[simconnect] Aircraft data packet rejected.")
        app.write_log.assert_any_call("WARNING: Aircraft data packet rejected.", "warning")
        app.record_activity.assert_any_call("warning", "[simconnect] Aircraft data packet rejected.", "simconnect", job_id="")
        app.write_log.assert_any_call("INFO: Watching folder", "success")
        app.record_activity.assert_any_call("success", "Watching folder", "watcher", job_id="")

    def test_simconnect_activity_persists_without_duplicate_marker(self) -> None:
        """Store clean activity and publish its safe text in the live footer.

        Returns:
            None: Confirms the event, footer, and durable store use the safe text.
        """
        app = mock.Mock()
        app.api_key_var.get.return_value = "private-key"
        app.activity_events = []
        app.config.activity_history_limit = 50
        app.latest_activity_var = mock.Mock()

        MAIN.WatcherApp.record_activity(app, "warning", "[simconnect] Failure private-key", "simconnect")

        event = app.activity_events[0]
        self.assertEqual("simconnect", event.operation)
        self.assertEqual("warning", event.level)
        self.assertEqual("Failure [REDACTED]", event.message)
        app.latest_activity_var.set.assert_called_once_with("Simconnect · Failure [REDACTED]")
        app.job_store.append_event.assert_called_once_with(event)


class NavigationTests(unittest.TestCase):
    """Keep screen navigation driven by stable task keys."""

    def test_select_tab_uses_the_registered_navigation_index(self) -> None:
        """Select a registered screen and ignore an unknown task key.

        Returns:
            None: Confirms selection is delegated to the registered notebook index.
        """
        app = mock.Mock()
        # Notebook indices are zero-based and follow the Watcher-first task order.
        app.tab_indices = {
            "watch": 0,
            "import": 1,
            "activity": 2,
            "ai": 3,
            "settings": 4,
        }

        MAIN.WatcherApp.select_tab(app, "activity")
        MAIN.WatcherApp.select_tab(app, "missing")

        app.notebook.select.assert_called_once_with(2)


class FlightDeliveryCoordinationTests(unittest.TestCase):
    """Preserve accepted import and connection state during finalization."""

    def test_begin_flight_opens_connection_setup_when_no_key_is_saved(self) -> None:
        """Make the main action useful after scoped credentials were revoked.

        Returns:
            None: Missing-key admission opens API settings; a saved key reuses
                the one-click finish/PDF/revoke controller.
        """
        app = mock.Mock()
        app.updates.blocked.return_value = False
        app.config.api_key = ""
        MAIN.WatcherApp.flight_action(app)
        app.open_connection_dialog.assert_called_once_with()
        app.revoke_api_key.assert_not_called()
        app.config.api_key = "saved-key"
        MAIN.WatcherApp.flight_action(app)
        app.flights.finish_report.assert_called_once_with()

    def test_finish_flight_is_below_gallery_and_title_can_shrink(self) -> None:
        """Keep the finish action visible independently of gallery title length.

        Returns:
            None: Independent widget spies verify the action container below
                the title, Finish beside SimBrief within it, and the constrained
                expanding title; no real Tk window is constructed.
        """
        app = mock.Mock()
        app.flights.text.return_value = "Finish flight…"
        widgets = mock.Mock()
        # Distinct widgets preserve the real parent/child grid hierarchy.
        widgets.Frame.side_effect = lambda *_args, **_kwargs: mock.Mock()
        widgets.Button.side_effect = lambda *_args, **_kwargs: mock.Mock()
        with (
            mock.patch.object(MAIN, "ttk", widgets),
            mock.patch.object(MAIN, "GlassCard", side_effect=lambda *_args, **_kwargs: mock.Mock()),
            mock.patch.object(MAIN, "icon_button"),
            mock.patch.object(MAIN, "help_button"),
        ):
            MAIN.WatcherApp.build_watch_tab(app, mock.Mock())
        finish = widgets.Button.call_args_list[0]
        self.assertEqual("Finish flight…", finish.kwargs["text"])
        self.assertEqual(app.flight_action, finish.kwargs["command"])
        title = widgets.Label.call_args_list[0]
        self.assertEqual(1, title.kwargs["width"])
        actions = finish.args[0]
        self.assertIsNot(actions, title.args[0])
        title.args[0].grid.assert_called_once_with(row=0, column=0, sticky="ew")
        actions.grid.assert_called_once_with(row=1, column=0, sticky="w", pady=(6, 0))
        app.finish_flight_button.grid.assert_called_once_with(row=0, column=0, sticky="w")
        simbrief = widgets.Button.call_args_list[1]
        self.assertIs(actions, simbrief.args[0])
        self.assertEqual(app.flights.toggle_simbrief, simbrief.kwargs["command"])

    def test_watcher_run_reports_inventory_and_camera_startup_state(self) -> None:
        """Keep the original endpoint and SimConnect startup diagnostics visible.

        Returns:
            None: Confirms enabled and disabled metadata configurations are logged.
        """
        for camera_metadata_enabled in (True, False):
            with self.subTest(camera_metadata_enabled=camera_metadata_enabled):
                config = types.SimpleNamespace(
                    watched_folder=str(WINAPP_DIR),
                    gallery_url="https://gallery.example/",
                    api_key="gallery-key",
                    watch_recursive=False,
                    inventory_refresh_seconds=17.0,
                    attach_sim_camera_metadata=camera_metadata_enabled,
                )
                worker = object.__new__(MAIN.WatcherThread)
                worker.config = config
                worker.stop_event = mock.Mock()
                worker.stop_event.is_set.return_value = True
                worker.initial_captured = True
                worker.initial_paths = set()
                worker.emit = mock.Mock()
                worker.persist_drain = mock.Mock()
                worker.simconnect_client = mock.Mock()
                worker.simconnect_client.dll_resolution_message.return_value = (
                    "SimConnect.dll resolved"
                )

                MAIN.WatcherThread.run(worker)

                messages = [
                    call.args[1]
                    for call in worker.emit.call_args_list
                    if call.args[0] == "info"
                ]
                self.assertIn(
                    "Remote inventory reconnect interval: 17 seconds.",
                    messages,
                )
                if camera_metadata_enabled:
                    self.assertIn(
                        "[simconnect] Flight Simulator location metadata enabled. "
                        "SimConnect.dll resolved",
                        messages,
                    )
                    worker.simconnect_client.dll_resolution_message.assert_called_once_with()
                else:
                    self.assertIn(
                        "[simconnect] Flight Simulator location metadata disabled.",
                        messages,
                    )
                    worker.simconnect_client.dll_resolution_message.assert_not_called()

    def test_manual_job_admission_refuses_during_key_revocation(self) -> None:
        """Keep durable import work unbound until key revocation finishes.

        Returns:
            None: Confirms no endpoint binding or upload worker starts.
        """
        app = object.__new__(MAIN.WatcherApp)
        app.api_key_revoke_running = True
        app.flights = mock.Mock()
        app.write_log = mock.Mock()
        job = types.SimpleNamespace(id="pending-job")
        paths = [Path("photo.jpg")]
        config = types.SimpleNamespace()

        with mock.patch.object(MAIN, "ManualUploadThread") as worker_factory:
            MAIN.WatcherApp.launch_manual_job(app, job, paths, config)

        app.flights.import_bindings.bind.assert_not_called()
        app.flights.binding.assert_not_called()
        worker_factory.assert_not_called()
        app.write_log.assert_called_once()
        log_message = app.write_log.call_args.args[0].lower()
        self.assertIn("finishing", log_message)
        self.assertNotIn("revocation", log_message)

    def test_late_connection_ready_does_not_persist_during_revocation(self) -> None:
        """Do not replace the captured connection while accepted work drains.

        Returns:
            None: Confirms a late resolved URL remains display-only during revoke.
        """
        saved_url = "https://gallery.example/old"
        resolved_url = "https://gallery.example/new"
        api_key = "gallery-key"
        app = object.__new__(MAIN.WatcherApp)
        app.exiting = False
        app.api_key_revoke_running = True
        app.connection_test_running = True
        app.events = MAIN.queue.Queue()
        app.events.put({
            "kind": "connection_ready",
            "payload": {
                "gallery_label": "Gallery",
                "normalized_url": resolved_url,
                "explicit_test": True,
            },
        })
        app.config = types.SimpleNamespace(gallery_url=saved_url, api_key=api_key)
        app.config_store = mock.Mock()
        app.gallery_label_var = mock.Mock()
        app.connection_status_var = mock.Mock()
        app.connection_detail_var = mock.Mock()
        app.gallery_url_var = mock.Mock()
        app.gallery_url_var.get.return_value = saved_url
        app.api_key_var = mock.Mock()
        app.api_key_var.get.return_value = api_key
        app.write_log = mock.Mock()
        app.record_activity = mock.Mock()
        app.refresh_revoke_button_state = mock.Mock()
        app.refresh_activity_monitor_state = mock.Mock()
        app.utc_sync = mock.Mock()
        app.utc_sync.status.return_value = "Idle"
        app.utc_sync_status_var = mock.Mock()
        app.root = mock.Mock()

        MAIN.WatcherApp.drain_events(app)

        app.config_store.save.assert_not_called()
        self.assertEqual(saved_url, app.config.gallery_url)
        app.gallery_url_var.set.assert_not_called()
        app.connection_status_var.set.assert_called_once_with("Connected")
        app.root.after.assert_called_once()

class WatcherActionTests(unittest.TestCase):
    """Keep the primary watcher action aligned with worker state."""

    def test_primary_action_starts_when_the_worker_is_stopped(self) -> None:
        """Start the watcher when no worker is active.

        Returns:
            None: Confirms the contextual action uses the existing start path.
        """
        app = mock.Mock()
        app.worker = None

        MAIN.WatcherApp.toggle_watcher(app)

        app.start.assert_called_once_with()
        app.stop.assert_not_called()

    def test_primary_action_stops_an_active_worker(self) -> None:
        """Stop the watcher when its worker is already active.

        Returns:
            None: Confirms the contextual action uses the existing stop path.
        """
        app = mock.Mock()
        app.worker.is_alive.return_value = True

        MAIN.WatcherApp.toggle_watcher(app)

        app.stop.assert_called_once_with()
        app.start.assert_not_called()

    def test_watcher_settings_require_stopping_the_live_worker(self) -> None:
        """Refuse edits while the watcher owns its active configuration.

        Returns:
            None: Confirms the stop-first message appears without opening a sheet.
        """
        app = mock.Mock()
        app.updates = None
        app.worker.is_alive.return_value = True

        with mock.patch.object(MAIN, "messagebox") as dialogs, mock.patch.object(
            MAIN,
            "tk",
        ) as tkinter:
            MAIN.WatcherApp.open_watcher_settings(app)

        dialogs.showinfo.assert_called_once()
        self.assertIn("Stop the watcher", dialogs.showinfo.call_args.args[1])
        tkinter.Toplevel.assert_not_called()


class ScrollableFrameWheelTests(unittest.TestCase):
    """Route page wheel input without stealing other controls' scrolling."""

    def test_scrollbar_appears_only_for_overflowing_content(self) -> None:
        """Reclaim scrollbar width when the full page fits the viewport.

        Returns:
            None: Overflow shows the scrollbar and a full visible interval hides it.
        """
        frame = mock.Mock()
        frame.scrollbar.winfo_manager.return_value = ""
        MAIN.ScrollableFrame._set_scrollbar(frame, "0.0", "0.7")
        frame.scrollbar.pack.assert_called_once_with(side="right", fill="y")
        frame.scrollbar.winfo_manager.return_value = "pack"
        MAIN.ScrollableFrame._set_scrollbar(frame, "0.0", "1.0")
        frame.scrollbar.pack_forget.assert_called_once_with()

    def test_owned_child_wheel_scrolls_its_page_in_units(self) -> None:
        """Send a full wheel notch from a child to the owning page canvas.

        Returns:
            None: Confirms owned wheel input scrolls the page canvas.
        """
        frame = mock.Mock()
        frame._wheel_remainder = 0
        child = mock.Mock()
        child.master = frame
        event = mock.Mock()
        event.widget = child
        event.delta = MAIN.WINDOWS_MOUSEWHEEL_DELTA

        # Built-in identities cover isinstance checks without loading Tk widgets.
        fake_tk = types.SimpleNamespace(Text=list, Listbox=dict)
        fake_ttk = types.SimpleNamespace(Treeview=set, Combobox=tuple)
        with mock.patch.object(MAIN, "tk", fake_tk), mock.patch.object(
            MAIN,
            "ttk",
            fake_ttk,
        ):
            result = MAIN.ScrollableFrame._on_mousewheel(frame, event)

        self.assertEqual("break", result)
        frame.canvas.yview_scroll.assert_called_once_with(-1, "units")

    def test_unrelated_view_wheel_does_not_scroll_this_page(self) -> None:
        """Leave wheel events from another page unconsumed.

        Returns:
            None: Confirms unrelated widget ancestry is ignored.
        """
        frame = mock.Mock()
        frame._wheel_remainder = 0
        unrelated_view = mock.Mock()
        unrelated_view.master = None
        event = mock.Mock()
        event.widget = unrelated_view
        event.delta = MAIN.WINDOWS_MOUSEWHEEL_DELTA

        fake_tk = types.SimpleNamespace(Text=list, Listbox=dict)
        fake_ttk = types.SimpleNamespace(Treeview=set, Combobox=tuple)
        with mock.patch.object(MAIN, "tk", fake_tk), mock.patch.object(
            MAIN,
            "ttk",
            fake_ttk,
        ):
            result = MAIN.ScrollableFrame._on_mousewheel(frame, event)

        self.assertIsNone(result)
        frame.canvas.yview_scroll.assert_not_called()

    def test_native_inner_scrollers_keep_their_wheel_input(self) -> None:
        """Do not intercept wheel events owned by native scroll controls.

        Returns:
            None: Confirms Text, Listbox, Treeview and Combobox retain input.
        """
        frame = mock.Mock()
        frame._wheel_remainder = 0
        native_names = (
            "TextWidget",
            "ListboxWidget",
            "TreeviewWidget",
            "ComboboxWidget",
        )
        native_types = tuple(
            type(name, (), {})
            for name in native_names
        )
        fake_tk = types.SimpleNamespace(
            Text=native_types[0],
            Listbox=native_types[1],
        )
        fake_ttk = types.SimpleNamespace(
            Treeview=native_types[2],
            Combobox=native_types[3],
        )

        with mock.patch.object(MAIN, "tk", fake_tk), mock.patch.object(
            MAIN,
            "ttk",
            fake_ttk,
        ):
            for native_type in native_types:
                with self.subTest(widget_type=native_type.__name__):
                    child = native_type()
                    child.master = frame
                    event = mock.Mock()
                    event.widget = child
                    event.delta = MAIN.WINDOWS_MOUSEWHEEL_DELTA

                    result = MAIN.ScrollableFrame._on_mousewheel(frame, event)

                    self.assertIsNone(result)

        frame.canvas.yview_scroll.assert_not_called()


class TrayUpdateIndicatorTests(unittest.TestCase):
    """Keep an available update visible and actionable on app and tray surfaces."""

    def test_badge_uses_fresh_pixels_without_mutating_or_accumulating(self) -> None:
        """Compose marked and unmarked images from the unchanged source art.

        Returns:
            None: Confirms badge pixels are deterministic and source art survives.
        """
        if MAIN.Image is None or MAIN.ImageDraw is None:
            # Pillow remains optional for portable app runtimes; the dedicated
            # CI job sets this flag so pixel coverage cannot silently skip.
            if os.environ.get("PHP_GALLERY_REQUIRE_PILLOW") == "1":
                self.fail(
                    "Pillow is required for tray badge pixel coverage in this CI job."
                )
            self.skipTest("Pillow is unavailable")
        base = MAIN.Image.new("RGBA", (32, 32), "#203040")
        original_pixels = base.tobytes()

        marked = MAIN.render_tray_icon(base, True)
        marked_again = MAIN.render_tray_icon(base, True)
        plain = MAIN.render_tray_icon(base, False)

        self.assertIsNot(marked, base)
        self.assertEqual(original_pixels, base.tobytes())
        self.assertEqual(marked.tobytes(), marked_again.tobytes())
        self.assertEqual(base.convert("RGBA").tobytes(), plain.tobytes())
        self.assertNotEqual(plain.tobytes(), marked.tobytes())
        self.assertEqual((217, 45, 63, 255), marked.getpixel((22, 11)))

        # The bundled artwork is 256 px; Windows tray display downsamples it
        # to 16 px, so inspect the rendered badge at its actual target size.
        large_base = MAIN.Image.new("RGBA", (256, 256), "#203040")
        large_original_pixels = large_base.tobytes()
        large_marked = MAIN.render_tray_icon(large_base, True)
        large_plain = MAIN.render_tray_icon(large_base, False)
        resampling = MAIN.Image.Resampling.LANCZOS
        marked_16 = large_marked.resize((16, 16), resampling)
        plain_16 = large_plain.resize((16, 16), resampling)
        red_pixels = [
            marked_16.getpixel((x, y))
            for x in range(9, 16)
            for y in range(0, 8)
        ]
        glyph_pixels = [
            marked_16.getpixel((x, y))
            for x in range(12, 14)
            for y in range(3, 6)
        ]

        self.assertEqual(large_original_pixels, large_base.tobytes())
        self.assertNotEqual(plain_16.tobytes(), marked_16.tobytes())
        # Red dominance detects the badge; a bright interior pixel detects the
        # exclamation stem or dot away from the circle's outer rim.
        self.assertTrue(
            any(red > green * 1.35 and red > blue * 1.2 for red, green, blue, _ in red_pixels)
        )
        self.assertTrue(
            any(red > 180 and green > 180 and blue > 180 for red, green, blue, _ in glyph_pixels)
        )

    def test_header_state_and_notice_route_survive_later_until_cleared(self) -> None:
        """Expose known version and route its click to the shared updater entry.

        Returns:
            None: Confirms Later leaves state and a clean check clears it.
        """
        app = object.__new__(MAIN.WatcherApp)
        app.update_available_version = None
        app.check_updates_button = mock.Mock()
        app.update_banner = mock.Mock()
        app.update_banner_label = mock.Mock()
        app.tray_icon = mock.Mock()
        app.make_tray_icon_image = mock.Mock(
            side_effect=("marked-image", "plain-image")
        )
        app.tray_tooltip_text = mock.Mock(return_value="Update 0.4.1")
        app.build_tray_menu = mock.Mock(return_value="update-menu")
        app.updates = mock.Mock()
        app.restore_from_tray = mock.Mock()

        MAIN.WatcherApp.set_update_available(app, "  0.4.1  ")

        self.assertEqual("0.4.1", app.update_available_version)
        self.assertEqual("↑", app.check_updates_button.configure.call_args.kwargs["text"])
        header_command = app.check_updates_button.configure.call_args.kwargs["command"]
        self.assertIs(header_command.__self__, app)
        self.assertIs(header_command.__func__, MAIN.WatcherApp.open_update_notice)
        app.update_banner.grid.assert_called_once_with()
        self.assertEqual(
            "Update 0.4.1 available",
            app.update_banner_label.configure.call_args.kwargs["text"],
        )
        self.assertEqual("marked-image", app.tray_icon.icon)
        self.assertEqual("Update 0.4.1", app.tray_icon.title)
        self.assertEqual("update-menu", app.tray_icon.menu)
        app.tray_icon.update_menu.assert_called_once_with()
        MAIN.WatcherApp.open_update_notice(app)
        app.restore_from_tray.assert_called_once_with()
        app.updates.open_updates.assert_called_once_with()
        self.assertEqual("0.4.1", app.update_available_version)

        MAIN.WatcherApp.set_update_available(app, None)

        self.assertIsNone(app.update_available_version)
        self.assertEqual(
            "↻",
            app.check_updates_button.configure.call_args.kwargs["text"],
        )
        self.assertIs(
            app.updates.check,
            app.check_updates_button.configure.call_args.kwargs["command"],
        )
        app.update_banner.grid_remove.assert_called_once_with()
        self.assertEqual("plain-image", app.tray_icon.icon)
        self.assertEqual(2, app.tray_icon.update_menu.call_count)

    def test_tray_menu_and_tooltip_expose_version_within_windows_budget(self) -> None:
        """Show the version in the first tray action and bounded tooltip text.

        Returns:
            None: Confirms both available and no-update tray captions.
        """
        app = object.__new__(MAIN.WatcherApp)
        app.update_available_version = "0.4.1"
        app.worker = None
        app.manual_worker = None
        app.ai_worker = None
        app.ai_worker_stop_requested = False
        app.current_job = None
        app.tray_open_updates = mock.Mock()
        app.tray_restore_window = mock.Mock()
        app.tray_open_activity = mock.Mock()
        app.tray_open_connection = mock.Mock()
        app.tray_revoke_api_key = mock.Mock()
        app.tray_toggle_import_pause = mock.Mock()
        app.tray_start_watching = mock.Mock()
        app.tray_stop_watching = mock.Mock()
        app.tray_start_ai_worker = mock.Mock()
        app.tray_stop_ai_worker = mock.Mock()
        app.tray_open_log_folder = mock.Mock()
        app.tray_exit_application = mock.Mock()

        menu_factory = mock.Mock(return_value="built-menu")
        menu_item_factory = mock.Mock(
            side_effect=lambda text, action, **kwargs: (text, action, kwargs)
        )
        menu_factory.SEPARATOR = object()
        with mock.patch.object(
            MAIN,
            "pystray",
            types.SimpleNamespace(Menu=menu_factory, MenuItem=menu_item_factory),
        ):
            menu = MAIN.WatcherApp.build_tray_menu(app)

        self.assertEqual("built-menu", menu)
        first_item = menu_item_factory.call_args_list[0].args
        self.assertEqual("Update 0.4.1", first_item[0])
        self.assertIs(app.tray_open_updates, first_item[1])
        self.assertIn("Update 0.4.1", MAIN.WatcherApp.tray_tooltip_text(app))
        self.assertLessEqual(
            len(MAIN.WatcherApp.tray_tooltip_text(app)),
            MAIN.TRAY_TOOLTIP_MAX_LENGTH,
        )

        app.update_available_version = "v" * 200
        self.assertLessEqual(
            len(MAIN.WatcherApp.tray_tooltip_text(app)),
            MAIN.TRAY_TOOLTIP_MAX_LENGTH,
        )
        app.update_available_version = None
        self.assertEqual("Check for updates", MAIN.WatcherApp.tray_update_menu_label(app))

    def test_tray_menu_activation_queues_shared_update_notice(self) -> None:
        """Marshal tray menu activation onto the Tk UI thread.

        Returns:
            None: Confirms the tray callback schedules the shared notice route.
        """
        app = object.__new__(MAIN.WatcherApp)
        app.schedule_ui = mock.Mock()
        app.open_update_notice = mock.Mock()

        MAIN.WatcherApp.tray_open_updates(app)

        app.schedule_ui.assert_called_once_with(app.open_update_notice)

    def test_monitor_refresh_updates_tooltip_without_replacing_badged_icon(self) -> None:
        """Keep operational status refreshes from replacing update pixels.

        Returns:
            None: Confirms routine worker updates refresh only tray text.
        """
        app = object.__new__(MAIN.WatcherApp)
        app.worker = None
        app.manual_worker = None
        app.ai_worker = None
        app.preflight_worker = None
        app.ai_worker_stop_requested = False
        app.watcher_activity_var = mock.Mock()
        app.watcher_activity_var.get.return_value = "Watch: stopped"
        app.import_activity_var = mock.Mock()
        app.import_activity_var.get.return_value = "Import: idle"
        app.ai_activity_var = mock.Mock()
        app.refresh_watcher_action = mock.Mock()
        app.update_monitor_state = mock.Mock()
        app.tray_icon = mock.Mock()
        app.tray_icon.icon = "badged-image"
        app.tray_tooltip_text = mock.Mock(return_value="Update 0.4.1")

        MAIN.WatcherApp.refresh_activity_monitor_state(app)

        self.assertEqual("badged-image", app.tray_icon.icon)
        self.assertEqual("Update 0.4.1", app.tray_icon.title)

    def test_close_blocked_by_key_revocation_keeps_update_offer(self) -> None:
        """Keep the verified update visible when key revocation blocks exit.

        Returns:
            None: Confirms a rejected close retains the available version.
        """
        app = object.__new__(MAIN.WatcherApp)
        app.flights = mock.Mock()
        app.flights.is_alive.return_value = False
        app.utc_sync = mock.Mock()
        app.updates = mock.Mock()
        app.updates.request_close.return_value = False
        app.api_key_revoke_running = True
        app.exiting = False
        app.update_available_version = "0.4.1"
        app.check_updates_button = None
        app.update_banner = None
        app.tray_icon = None

        with mock.patch.object(MAIN, "messagebox") as dialogs:
            MAIN.WatcherApp.close(app)
        app.flights.stop.assert_called_once_with()

        self.assertFalse(app.exiting)
        self.assertEqual("0.4.1", app.update_available_version)
        dialogs.showwarning.assert_called_once()

    def test_close_canceled_to_keep_tray_keeps_update_offer(self) -> None:
        """Keep update state when a worker timeout cancels application exit.

        Returns:
            None: Confirms the keep-running choice leaves app and offer active.
        """
        app = object.__new__(MAIN.WatcherApp)
        app.flights = mock.Mock()
        app.flights.is_alive.return_value = False
        app.utc_sync = mock.Mock()
        app.utc_sync.is_alive.return_value = False
        app.updates = mock.Mock()
        app.updates.request_close.return_value = False
        app.api_key_revoke_running = False
        app.exiting = False
        app.update_available_version = "0.4.1"
        app.preflight_worker = None
        app.worker = mock.Mock()
        app.worker.is_alive.return_value = True
        app.manual_worker = None
        app.ai_worker = None
        app.api_key_revoke_thread = None
        app.ai_worker_stop_requested = False
        app.tray_icon = mock.Mock()
        app.write_log = mock.Mock()
        app.hide_to_tray = mock.Mock()
        app.root = mock.Mock()
        app.stop_tray_icon = mock.Mock()

        with mock.patch.object(MAIN, "messagebox") as dialogs:
            dialogs.askyesno.return_value = True
            MAIN.WatcherApp.close(app)
        app.flights.stop.assert_called_once_with()

        self.assertFalse(app.exiting)
        self.assertEqual("0.4.1", app.update_available_version)
        app.hide_to_tray.assert_called_once_with()
        app.stop_tray_icon.assert_not_called()
        app.root.destroy.assert_not_called()

    def test_confirmed_close_clears_update_offer_before_teardown(self) -> None:
        """Clear the persistent offer only when shutdown reaches teardown.

        Returns:
            None: Confirms confirmed shutdown clears the version and destroys Tk.
        """
        app = object.__new__(MAIN.WatcherApp)
        app.flights = mock.Mock()
        app.flights.is_alive.return_value = False
        app.utc_sync = mock.Mock()
        app.utc_sync.is_alive.return_value = False
        app.updates = mock.Mock()
        app.updates.request_close.return_value = False
        app.api_key_revoke_running = False
        app.exiting = False
        app.update_available_version = "0.4.1"
        app.check_updates_button = None
        app.update_banner = None
        app.tray_icon = None
        app.preflight_worker = None
        app.worker = None
        app.manual_worker = None
        app.ai_worker = None
        app.api_key_revoke_thread = None
        app.write_log = mock.Mock()
        app.stop_tray_icon = mock.Mock(
            side_effect=lambda: self.assertIsNone(app.update_available_version)
        )
        app.root = mock.Mock()
        app.root.destroy.side_effect = lambda: self.assertIsNone(
            app.update_available_version
        )

        MAIN.WatcherApp.close(app)
        app.flights.stop.assert_called_once_with()

        self.assertTrue(app.exiting)
        self.assertIsNone(app.update_available_version)
        app.stop_tray_icon.assert_called_once_with()
        app.root.destroy.assert_called_once_with()

if __name__ == "__main__":
    unittest.main(verbosity=2)
