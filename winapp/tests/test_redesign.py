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


class ConfigStateTests(unittest.TestCase):
    """Configuration, persistence, migration, and redaction contracts."""

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


if __name__ == "__main__":
    unittest.main(verbosity=2)
