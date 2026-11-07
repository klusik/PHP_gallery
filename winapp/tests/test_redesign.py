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
import threading
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
from uploader.flight_jobs import ImportBindings
from uploader.media import detect_suffix_capabilities, probe_file
from uploader.models import ImportItem, ImportJob, JsonValue, RECOVERABLE_ITEM_STATES
from uploader.state_store import (
    JobStateStore, atomic_write_json, default_upload_state, load_upload_state,
)

# Purpose: Bound synchronization with the fixture's blocked heartbeat request.
# Type: float. Units: seconds. Scope: one in-memory heartbeat synchronization.
# Consumers: heartbeat regression. Rationale: five seconds permits loaded CI
# workers to schedule without letting a failed fixture wait indefinitely.
TEST_HEARTBEAT_WAIT_SECONDS = 5.0

# Purpose: Give black-bar trimming a known symmetric fixture boundary.
# Type: tuple[int, int]; Units: pixels; Scope: source-raster edge policy test.
# Consumers: PhotoPresentationPolicyTests.test_symmetric_black_edges_are_trimmed.
# Rationale: 120 by 100 makes the 10 px bars safely exceed the minimum width.
TEST_BLACK_BAR_IMAGE_SIZE = (120, 100)

# Purpose: Define clear symmetric letterbox bars in the trim fixture.
# Type: int; Units: pixels; Scope: synthetic source-raster edge test.
# Consumers: PhotoPresentationPolicyTests.
# Rationale: Ten pixel bands exceed the minimum while remaining under 12%.
TEST_BLACK_BAR_WIDTH = 10

# Purpose: Represent matched black sides around a dim flat photo scene.
# Type: tuple[int, int]. Units: pixels. Scope: low-texture matte regression.
# Consumers: PhotoPresentationPolicyTests flat-matte regression.
# Rationale: The 250 by 90 frame gives 20 px sides and a 21:9 interior.
TEST_FLAT_DARK_MATTE_SIZE = (250, 90)

# Purpose: Give the synthetic flat-scene mattes a known symmetric width.
# Type: int. Units: pixels. Scope: low-texture matte regression.
# Consumers: PhotoPresentationPolicyTests flat-matte regression.
# Rationale: 20 px yields a visible paired band within the 30% scan cap.
TEST_FLAT_DARK_MATTE_WIDTH = 20

# Purpose: Exercise a common 4:3 frame letterboxed inside a 16:9 raster.
# Type: tuple[int, int]; Units: pixels; Scope: combined matte fixture.
# Consumers: PhotoPresentationPolicyTests.
# Rationale: Side mattes are 12.5%; top mattes are 12.5%.
TEST_COMBINED_MATTE_SIZE = (160, 90)


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


class PhotoPresentationPolicyTests(unittest.TestCase):
    """Keep Photos crops conservative and tooltip surfaces in-window."""

    def test_symmetric_black_edges_are_trimmed_without_mutating_source(self) -> None:
        """Trim only clear opposing bars and retain original source pixels.

        Returns:
            None: Crop bounds and untouched normalized source are verified.
        """
        try:
            from PIL import Image, ImageDraw
            from uploader.gallery_lightbox import PhotoLightbox
        except ImportError:
            self.skipTest("Pillow is an optional visual enhancement.")

        width, height = TEST_BLACK_BAR_IMAGE_SIZE
        band = TEST_BLACK_BAR_WIDTH
        source = Image.new("RGB", (width, height), "black")
        ImageDraw.Draw(source).rectangle(
            (0, band, width - 1, height - band - 1),
            fill="#b83d55",
        )
        lightbox = object.__new__(PhotoLightbox)
        self.assertEqual(
            (0, band, width, height - band),
            lightbox._trim_black_bars(source),
        )
        self.assertEqual((0, 0, 0), source.getpixel((0, 0)))
        self.assertEqual((184, 61, 85), source.getpixel((0, band)))

        one_sided = Image.new("RGB", (width, height), "#b83d55")
        ImageDraw.Draw(one_sided).rectangle(
            (0, 0, width - 1, band - 1),
            fill="black",
        )
        self.assertEqual(
            (0, 0, width, height),
            lightbox._trim_black_bars(one_sided),
        )
        dark = Image.new("RGB", (width, height), "black")
        self.assertEqual(
            (0, 0, width, height),
            lightbox._trim_black_bars(dark),
        )

    def test_content_fit_toggle_restores_original_frame(self) -> None:
        """Switch between detected content bounds and the complete frame.

        Returns:
            None: Both fit states retain the original Pillow image dimensions.
        """
        try:
            from PIL import Image, ImageDraw
            from uploader.gallery_lightbox import PhotoLightbox
        except ImportError:
            self.skipTest("Pillow is an optional visual enhancement.")

        width, height = TEST_BLACK_BAR_IMAGE_SIZE
        band = TEST_BLACK_BAR_WIDTH
        source = Image.new("RGB", (width, height), "black")
        ImageDraw.Draw(source).rectangle(
            (0, band, width - 1, height - band - 1), fill="#b83d55",
        )
        lightbox = object.__new__(PhotoLightbox)
        lightbox._source_image = source
        lightbox._crop_box = lightbox._trim_black_bars(source)
        lightbox._content_crop_enabled = True
        lightbox._zoom = 2.0
        lightbox._fullscreen_center = None
        lightbox._fit_tooltip = mock.Mock()
        lightbox.text = lambda key: key
        lightbox._render_image = lambda: None

        lightbox._fit_image()
        self.assertEqual((0, 0, width, height), lightbox._crop_box)
        self.assertFalse(lightbox._content_crop_enabled)
        self.assertEqual(1.0, lightbox._zoom)
        lightbox._fit_tooltip.set.assert_called_with("fit_content")
        lightbox._fit_image()
        self.assertEqual((0, band, width, height - band), lightbox._crop_box)
        self.assertTrue(lightbox._content_crop_enabled)
        lightbox._fit_tooltip.set.assert_called_with("show_original_frame")
        source.close()

    def test_combined_side_and_top_mattes_are_detected(self) -> None:
        """Detect paired side and top bars without changing source pixels.

        Returns:
            None: The fitted box excludes all four synthetic mattes.
        """
        try:
            from PIL import Image, ImageDraw
            from uploader.gallery_lightbox import PhotoLightbox
        except ImportError:
            self.skipTest("Pillow is an optional visual enhancement.")

        width, height = TEST_COMBINED_MATTE_SIZE
        source = Image.new("RGB", (width, height), "black")
        ImageDraw.Draw(source).rectangle(
            (20, 11, width - 21, height - 12), fill="#376b91",
        )
        crop = object.__new__(PhotoLightbox)._trim_black_bars(source)
        self.assertEqual((20, 11, width - 20, height - 11), crop)
        self.assertEqual((0, 0, 0), source.getpixel((0, height // 2)))
        source.close()

    def test_cinematic_letterbox_is_detected(self) -> None:
        """Detect wide cinematic content without cropping its scene.

        Returns:
            None: Only the clear top and bottom matte bands are removed.
        """
        try:
            from PIL import Image, ImageDraw
            from uploader.gallery_lightbox import PhotoLightbox
        except ImportError:
            self.skipTest("Pillow is an optional visual enhancement.")

        source = Image.new("RGB", (220, 100), "black")
        ImageDraw.Draw(source).rectangle(
            (0, 20, 219, 79), fill="#376b91",
        )
        crop = object.__new__(PhotoLightbox)._trim_black_bars(source)
        self.assertEqual((0, 20, 220, 80), crop)
        source.close()

    def test_clean_21_by_9_photo_keeps_its_full_frame(self) -> None:
        """Keep a clean cinematic photograph untrimmed for laptop fit.

        Returns:
            None: Natural dark scene edges do not qualify as mattes.
        """
        try:
            from PIL import Image, ImageDraw
            from uploader.gallery_lightbox import PhotoLightbox
        except ImportError:
            self.skipTest("Pillow is an optional visual enhancement.")

        source = Image.new("RGB", (210, 90), "#07090b")
        ImageDraw.Draw(source).rectangle(
            (5, 5, 204, 84), fill="#111820",
        )
        crop = object.__new__(PhotoLightbox)._trim_black_bars(source)
        self.assertEqual((0, 0, 210, 90), crop)
        source.close()

    def test_flat_dark_scene_with_paired_black_mattes_is_trimmed(self) -> None:
        """Trim balanced black sides before a dim, low-texture scene.

        Returns:
            None: Both mattes are removed while the flat scene remains intact.
        """
        try:
            from PIL import Image
            from uploader.gallery_lightbox import PhotoLightbox
        except ImportError:
            self.skipTest("Pillow is an optional visual enhancement.")

        width, height = TEST_FLAT_DARK_MATTE_SIZE
        band = TEST_FLAT_DARK_MATTE_WIDTH
        source = Image.new("RGB", (width, height), "black")
        for x in range(band, width - band):
            for y in range(height):
                source.putpixel((x, y), (16, 16, 16))
        crop = object.__new__(PhotoLightbox)._trim_black_bars(source)
        self.assertEqual((band, 0, width - band, height), crop)
        source.close()

    def test_inline_tooltip_uses_existing_surface_and_photo_style(self) -> None:
        """Host hover help in its existing glass surface without a Toplevel.

        Returns:
            None: The themed label is placed beside the trigger in-window.
        """
        from uploader import ui_theme

        widget = mock.Mock()
        widget.winfo_exists.return_value = True
        widget.winfo_ismapped.return_value = True
        widget.winfo_rootx.return_value = 30
        widget.winfo_rooty.return_value = 40
        widget.winfo_height.return_value = 24
        widget.after.return_value = "tooltip-timer"
        surface = mock.Mock()
        surface._inline_tooltip_state = None
        surface.winfo_exists.return_value = True
        surface.winfo_ismapped.return_value = True
        surface.winfo_rootx.return_value = 0
        surface.winfo_rooty.return_value = 0
        surface.winfo_width.return_value = 500
        surface.winfo_height.return_value = 300
        label = mock.Mock()
        label.winfo_reqwidth.return_value = 100
        label.winfo_reqheight.return_value = 30

        with (
            mock.patch.object(ui_theme.ttk, "Label", return_value=label),
            mock.patch.object(ui_theme.tk, "Toplevel") as transient_window,
        ):
            ui_theme.attach_inline_tooltip(widget, "Open photo", surface)
            enter = next(
                call.args[1]
                for call in widget.bind.call_args_list
                if call.args[0] == "<Enter>"
            )
            enter()
            show = widget.after.call_args.args[1]
            show()

        transient_window.assert_not_called()
        label.place.assert_called_once_with(x=36, y=70, width=100)
        label.lift.assert_called_once_with()

    def test_icon_button_accepts_scoped_photo_style_and_tooltip_surface(self) -> None:
        """Route Photos icon controls through the themed in-window help path.

        Returns:
            None: Scoped style and existing tooltip host reach the button owner.
        """
        from uploader import ui_theme

        parent = mock.Mock()
        surface = mock.Mock()
        button = mock.Mock()
        with (
            mock.patch.object(ui_theme.ttk, "Button", return_value=button) as build,
            mock.patch.object(ui_theme, "attach_inline_tooltip") as inline,
            mock.patch.object(ui_theme, "_attach_tooltip") as popup,
        ):
            result = ui_theme.icon_button(
                parent,
                "□",
                lambda: None,
                "Fit photo",
                tooltip_surface=surface,
                style_override="Photos.Icon.TButton",
            )

        self.assertIs(button, result)
        self.assertEqual(
            "Photos.Icon.TButton",
            build.call_args.kwargs["style"],
        )
        inline.assert_called_once_with(button, "Fit photo", surface)
        popup.assert_not_called()

    def test_shared_tooltip_uses_the_existing_toplevel_surface(self) -> None:
        """Keep legacy tooltip callers inside their owning window.

        Returns:
            None: Shared help binding delegates to the inline themed surface.
        """
        from uploader import ui_theme

        widget = mock.Mock()
        surface = mock.Mock()
        widget.winfo_toplevel.return_value = surface
        with (
            mock.patch.object(ui_theme, "attach_inline_tooltip") as inline,
            mock.patch.object(ui_theme.tk, "Toplevel") as transient_window,
        ):
            ui_theme._attach_tooltip(widget, "Open photo")

        inline.assert_called_once_with(widget, "Open photo", surface)
        transient_window.assert_not_called()


class LinkedUploadHistoryTests(unittest.TestCase):
    """Preserve uncertainty across restarts and reject lost local history."""

    @unittest.skipUnless(sys.platform == 'darwin', 'Native macOS mode storage')
    def test_macos_local_profile_can_activate_a_browser_approved_target(self) -> None:
        """Persist the selected gallery and refuse key work after evidence loss.

        Returns:
            None: An empty profile admits the exact linked target and saves
                the watch folder. Restart needs a fresh live link.
        """
        from uploader.app_linking import LinkingSession, UPLOAD_SCOPE

        with tempfile.TemporaryDirectory(prefix='phpgallery-mac-mode-') as directory:
            profile = Path(directory).resolve()
            path = profile / 'config.json'
            store = MAIN.ConfigStore(path)
            config = store.load()
            mode = MAIN.GuiModeOwner(
                store.mode_port(config), lambda: MAIN.MacUploadModeStore(profile),
            )
            mode.initialize()
            self.assertEqual('first_setup', mode.view().state)
            saved = MAIN.replace(config, watched_folder=str(profile))

            def save_folder() -> MAIN.ModeConfigPort:
                """Store the folder through the verified configuration owner.

                Returns:
                    MAIN.ModeConfigPort: Port for the newly saved configuration.
                """
                store.save(saved)
                return store.mode_port(saved)

            mode.save(save_folder, first_setup=True)
            session = mock.Mock(spec=LinkingSession)
            session.matches_issuer.return_value = True
            session.can_upload.return_value = True
            session.approved_scope.return_value = UPLOAD_SCOPE
            target = MAIN.LinkedUploadTarget(
                session, 'http://localhost:8888/galerie', 7, 3, 'Mac gallery',
            )
            self.assertEqual('linked_active', mode.select_linked(target).state)
            with mode.acquire('linked', store.mode_port(saved)) as work:
                work.checkpoint()

            reopened = store.load()
            self.assertEqual(str(profile), reopened.watched_folder)
            self.assertEqual('', reopened.api_key)
            port = store.mode_port(reopened)
            restarted = MAIN.GuiModeOwner(
                port, lambda: MAIN.MacUploadModeStore(profile),
            )
            restarted.initialize()
            self.assertEqual('linked_disconnected', restarted.view().state)
            with self.assertRaises(MAIN.ModeCoordinatorError):
                restarted.acquire('legacy', port)

            (profile / 'upload-mode' / 'mode.json').unlink()
            with self.assertRaises(MAIN.ModeCoordinatorError):
                restarted.select_linked(target)

    def test_target_switch_blocks_foreign_pending_import_after_restart(self) -> None:
        """Allow exact reconnect but not an unrelated linked or key destination.

        Returns:
            None: Durable accepted import ownership survives a lost local token.
        """
        app = object.__new__(MAIN.WatcherApp)
        app.background_work_active = lambda: False
        app.config = MAIN.WatcherConfig()
        app.mode_owner = mock.Mock()
        issuer = 'http://localhost:8888/galerie'
        app.mode_owner.view.return_value = mock.Mock(record=mock.Mock(
            mode='linked', issuer=issuer, account_id=7, gallery_id=29,
        ))
        binding = MAIN.linked_target_binding(issuer, 7, 29)
        app.flights = mock.Mock()
        app.flights.is_alive.return_value = False
        app.flights.busy.return_value = False
        app.flights.pending.return_value = []
        app.flights.binding.side_effect = ValueError('No live linked session.')
        app.flights.import_bindings.entries.return_value = {'pending': binding}
        temporary = tempfile.TemporaryDirectory(prefix='phpgallery-target-switch-')
        self.addCleanup(temporary.cleanup)
        app.job_store = JobStateStore(Path(temporary.name) / 'upload_state.json')
        pending = ImportJob.create('files', 'image.jpg', 'Display label only')
        pending.id = 'pending'
        pending.items = [
            ImportItem('item-1', '/unused/image.jpg', 'image.jpg', state='uploading'),
        ]
        document = default_upload_state()
        document['jobs'] = {pending.id: pending.to_dict()}
        atomic_write_json(app.job_store.path, document)
        with mock.patch.object(MAIN, 'CONFIG_DIR', Path(temporary.name)):
            self.assertIsNone(app.linked_target_change_refusal(binding))
            app.flights.pending.assert_called_with(binding=binding)
            app.flights.binding.assert_not_called()
            self.assertIn('unfinished imports', app.linked_target_change_refusal('c' * 64))
            self.assertIn('unfinished imports', app.linked_target_change_refusal(None))
            app.flights.import_bindings.entries.return_value = {}
            self.assertIsNotNone(app.linked_target_change_refusal(binding))
            app.flights.import_bindings.entries.return_value = {'pending': binding}
            app.job_store.path.write_text('{bad JSON', encoding='utf-8')
            self.assertIn('history could not be verified',
                          app.linked_target_change_refusal(binding))
        app.flights.import_bindings.resolve.assert_not_called()

    def test_linked_zip_preflight_is_only_a_preview(self) -> None:
        """Recheck a preflight match and retain the ZIP on uncertain results.

        Returns:
            None: Missing remote content uploads, while a failed check keeps staging.
        """
        for unavailable in (False, True):
            with self.subTest(unavailable=unavailable), tempfile.TemporaryDirectory() as directory:
                root = Path(directory)
                archive = root / 'source.zip'
                contents = b'synthetic image'
                with zipfile.ZipFile(archive, 'w') as handle:
                    handle.writestr('image.jpg', contents)
                digest = MAIN.hashlib.sha256(contents).hexdigest()
                target = mock.Mock()
                target.binding.return_value = 'a' * 64
                target.issuer, target.title, target.gallery_id = (
                    'https://gallery.example', 'Gallery', 7,
                )
                target.inventory.side_effect = [
                    {'gallery_title': 'Gallery', 'existing': [{'sha256': digest}]},
                    RuntimeError('unavailable') if unavailable else {'existing': []},
                ]
                target.upload.return_value = {'uploaded': 1, 'scanned': 1}
                config = MAIN.WatcherConfig()
                events = MAIN.queue.Queue()
                store = MAIN.JobStateStore(root / 'jobs.json')
                with mock.patch.object(MAIN, 'CONFIG_DIR', root), mock.patch.object(
                    MAIN, 'STAGING_ROOT', root / 'staging',
                ):
                    preflight = MAIN.ImportPreflightThread(
                        config, 'zip', [], None, archive, False, True,
                        events, store, target,
                    )
                    preflight.run()
                    emitted = []
                    while not events.empty():
                        emitted.append(events.get_nowait())
                    plan = next(event['payload']['plan'] for event in emitted
                                if event['kind'] == 'preflight_complete')
                    self.assertEqual(1, plan.duplicate_remote)
                    item = plan.job.items[0]
                    self.assertEqual('queued', item.state)
                    self.assertFalse(plan.job.delete_source_zip_after_success)
                    source = Path(item.path)
                    self.assertTrue(source.is_file())
                    self.assertTrue(archive.is_file())
                    worker = MAIN.ManualUploadThread(
                        config, [source], False, 1, 1, events,
                        plan.job, store, target,
                    )
                    worker.linked_state = MAIN.LinkedUploadState(target)
                    worker.upload_one('', source, 1, [], None, False)
                    self.assertEqual(2, target.inventory.call_count)
                    if unavailable:
                        target.upload.assert_not_called()
                        self.assertEqual('failed_retryable', item.state)
                        worker.finalize_job()
                        self.assertTrue(source.is_file())
                    else:
                        target.upload.assert_called_once()
                        self.assertEqual('', item.reason)
                        self.assertEqual('confirmed', item.state)
                    self.assertTrue(archive.is_file())

    def test_linked_source_replaced_during_inventory_is_not_skipped(self) -> None:
        """Keep a changed staged source unresolved after a remote match.

        Returns:
            None: The source, staged directory and archive remain untouched.
        """
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            staging = root / 'stage'
            staging.mkdir()
            source = staging / 'image.jpg'
            source.write_bytes(b'original')
            archive = root / 'source.zip'
            archive.write_bytes(b'archive')
            target = mock.Mock()
            target.binding.return_value = 'b' * 64
            target.issuer, target.title, target.gallery_id = (
                'https://gallery.example', 'Gallery', 7,
            )
            digest = MAIN.hashlib.sha256(b'original').hexdigest()

            def replace_during_inventory(
                candidates: list[dict[str, MAIN.JsonValue]],
            ) -> dict[str, MAIN.JsonValue]:
                """Replace the source while the remote observation is in flight.

                Args:
                    candidates (list[dict[str, MAIN.JsonValue]]): Unused candidate
                        objects with client_id, filename, size and sha256 fields.

                Returns:
                    dict[str, MAIN.JsonValue]: An existing list containing one
                        object with the previously observed sha256 string.
                """
                replacement = root / 'replacement.jpg'
                replacement.write_bytes(b'original')
                replacement.replace(source)
                return {'existing': [{'sha256': digest}]}

            target.inventory.side_effect = replace_during_inventory
            job = MAIN.ImportJob.create('zip', str(archive), 'Gallery')
            job.staging_dir, job.source_zip = str(staging), str(archive)
            item = MAIN.ImportItem.create(source, 'image.jpg', from_archive=True)
            item.transition('queued')
            job.items = [item]
            with mock.patch.object(MAIN, 'CONFIG_DIR', root):
                worker = MAIN.ManualUploadThread(
                    MAIN.WatcherConfig(), [source], False, 1, 1, MAIN.queue.Queue(),
                    job, MAIN.JobStateStore(root / 'jobs.json'), target,
                )
                worker.linked_state = MAIN.LinkedUploadState(target)
                worker.upload_one('', source, 1, [], None, False)
                worker.finalize_job()
            self.assertEqual('failed_retryable', item.state)
            target.upload.assert_not_called()
            self.assertTrue(source.is_file())
            self.assertTrue(staging.is_dir())
            self.assertTrue(archive.is_file())

    def test_uncertain_zip_staging_survives_restart_cleanup(self) -> None:
        """Protect blocked linked work even when it is not retryable.

        Returns:
            None: The failed linked source remains identifiable after cleanup.
        """
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            uncertain = MAIN.ImportJob.create('zip', 'archive.zip', 'Gallery')
            item = MAIN.ImportItem.create(root / 'missing.jpg', 'image.jpg', from_archive=True)
            item.transition('failed_permanent', MAIN.LINKED_UPLOAD_UNCERTAIN_REASON)
            uncertain.items = [item]
            uncertain.finished_at = time.time()
            finished = MAIN.ImportJob.create('zip', 'other.zip', 'Gallery')
            done = MAIN.ImportItem.create(root / 'other.jpg', 'other.jpg', from_archive=True)
            done.transition('confirmed')
            finished.items = [done]
            finished.finished_at = time.time()
            for job in (uncertain, finished):
                folder = root / ('job-' + job.id)
                folder.mkdir()
                old = time.time() - 48 * 3600
                os.utime(folder, (old, old))
            protected = MAIN.protected_staging_job_ids([uncertain, finished])
            self.assertEqual([uncertain.id], protected)
            removed = cleanup_stale_staging(root, 24.0, protected)
            self.assertEqual([], removed)
            self.assertTrue((root / ('job-' + uncertain.id)).is_dir())
            self.assertTrue((root / ('job-' + finished.id)).is_dir())

    def test_recovery_probe_preserves_journal_and_checks_marker_identity(self) -> None:
        """Observe one hash without reading its source or resolving its marker.

        Returns:
            None: Only the unchanged exact marker can publish an observation.
        """
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            target = mock.Mock()
            target.binding.return_value = 'b' * 64
            target.issuer = 'https://gallery.example'
            target.user_id = 9
            target.gallery_id = 4
            target.session.matches_issuer.return_value = True
            target.session.identity.return_value.user_id = 9
            target.session.probe_uncertain_upload.return_value = 'present'
            app = object.__new__(MAIN.WatcherApp)
            app.linking_manager = mock.Mock()
            app.linking_manager.selected_upload_target.return_value = target
            with mock.patch.object(MAIN, 'CONFIG_DIR', root):
                state = MAIN.LinkedUploadState(target)
                # The source intentionally does not exist and must never be opened.
                state.mark_uncertain(root / 'missing.jpg', 'a' * 64)
                original = state.path.read_bytes()
                result, marker = app.probe_linked_uncertain_upload(target, 123)
                self.assertEqual('present', result)
                target.session.probe_uncertain_upload.assert_called_once_with(4, 'a' * 64, 123)
                self.assertEqual(2, target.session.identity.call_count)
                self.assertEqual(original, state.path.read_bytes())
                publish = mock.Mock()
                self.assertTrue(app.publish_linked_recovery(target, marker, publish))
                publish.assert_called_once_with()
                changed = json.loads(original)
                key = next(iter(changed['failures']))
                changed['failures'][key]['last_error'] = 'different marker'
                state.path.write_text(json.dumps(changed), encoding='utf-8')
                self.assertFalse(app.publish_linked_recovery(target, marker, mock.Mock()))
                self.assertTrue(MAIN.LinkedUploadState(target).has_unresolved_upload())
                target.session.identity.return_value.user_id = 10
                target.session.probe_uncertain_upload.reset_mock()
                with self.assertRaises(ValueError):
                    app.probe_linked_uncertain_upload(target, 123)
                target.session.probe_uncertain_upload.assert_not_called()

    def test_recovery_refuses_races_and_a_busy_target(self) -> None:
        """Reject changed markers, identity loss and another target lease.

        Returns:
            None: Each refusal retains the unresolved source evidence.
        """
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            target = mock.Mock()
            target.binding.return_value = 'd' * 64
            target.issuer = 'https://gallery.example'
            target.user_id, target.gallery_id = 9, 4
            target.session.matches_issuer.return_value = True
            target.session.identity.return_value.user_id = 9
            app = object.__new__(MAIN.WatcherApp)
            with mock.patch.object(MAIN, 'CONFIG_DIR', root):
                state = MAIN.LinkedUploadState(target)
                state.mark_uncertain(root / 'missing.jpg', 'a' * 64)
                original = state.path.read_bytes()

                def change_marker(gallery_id: int, file_hash: str, size: int) -> str:
                    """Replace marker metadata during the simulated request.

                    Args:
                        gallery_id (int): Synthetic selected gallery identifier.
                        file_hash (str): Synthetic journaled SHA-256 digest.
                        size (int): Synthetic user-supplied size in bytes.

                    Returns:
                        str: A matching-bytes observation.
                    """
                    changed = json.loads(original)
                    key = next(iter(changed['failures']))
                    changed['failures'][key]['last_error'] = 'changed while probing'
                    state.path.write_text(json.dumps(changed), encoding='utf-8')
                    return 'present'

                target.session.probe_uncertain_upload.side_effect = change_marker
                with self.assertRaises(ValueError):
                    app.probe_linked_uncertain_upload(target, 123)
                state.path.write_bytes(original)
                target.session.probe_uncertain_upload.side_effect = None
                target.session.probe_uncertain_upload.return_value = 'unknown'
                first, second = mock.Mock(user_id=9), mock.Mock(user_id=10)
                target.session.identity.side_effect = [first, second]
                with self.assertRaises(ValueError):
                    app.probe_linked_uncertain_upload(target, 123)
                self.assertEqual(original, state.path.read_bytes())
                target.session.identity.side_effect = None
                target.session.probe_uncertain_upload.reset_mock()
                lease = MAIN.LinkedTargetLease(root, target.binding())
                lease.acquire()
                try:
                    with self.assertRaises(RuntimeError):
                        app.probe_linked_uncertain_upload(target, 123)
                    target.session.probe_uncertain_upload.assert_not_called()
                finally:
                    lease.release()
                self.assertEqual(original, state.path.read_bytes())

    def test_recovery_requires_exactly_one_valid_uncertain_marker(self) -> None:
        """Refuse missing, duplicate and malformed marker histories.

        Returns:
            None: No recovery hash can be selected from ambiguous history.
        """
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            target = mock.Mock()
            target.binding.return_value = 'c' * 64
            with mock.patch.object(MAIN, 'CONFIG_DIR', root):
                state = MAIN.LinkedUploadState(target)
                with self.assertRaises(ValueError):
                    state.recovery_marker()
                state.mark_uncertain(root / 'a.jpg', 'a' * 64)
                data = json.loads(state.path.read_text(encoding='utf-8'))
                data['failures']['another'] = {'uncertain': True, 'sha256': 'a' * 64}
                state.path.write_text(json.dumps(data), encoding='utf-8')
                with self.assertRaises(ValueError):
                    MAIN.LinkedUploadState(target).recovery_marker()

    def test_uncertain_hash_survives_restart_and_missing_history_fails_closed(self) -> None:
        """Keep an ambiguous upload blocked even under a different source path.

        Returns:
            None: A missing state document is never silently recreated.
        """
        with tempfile.TemporaryDirectory() as directory:
            target = mock.Mock()
            target.binding.return_value = 'b' * 64
            digest = 'c' * 64
            with mock.patch.object(MAIN, 'CONFIG_DIR', Path(directory)):
                state = MAIN.LinkedUploadState(target)
                state.mark_uncertain(Path(directory) / 'source.jpg', digest)
                restored = MAIN.LinkedUploadState(target)
                self.assertTrue(restored.has_uncertain_hash(digest))
                self.assertFalse(restored.retry_allowed(Path(directory) / 'source.jpg', digest))
                restored.path.unlink()
                with self.assertRaises(ValueError):
                    restored.save()

    def test_review_retains_unknown_but_allows_other_source(self) -> None:
        """Acknowledge one exact marker without permitting its source to replay.

        Returns:
            None: Review permits a different file, preserves the old record
                across restart, and cannot be reused to rewrite its outcome.
        """
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            source = root / 'source.jpg'
            source.write_bytes(b'original retained bytes')
            digest = MAIN.sha256_file(source)
            target = mock.Mock()
            target.binding.return_value = 'b' * 64
            with mock.patch.object(MAIN, 'CONFIG_DIR', root):
                lease = MAIN.LinkedTargetLease(root, target.binding())
                lease.acquire()
                try:
                    state = MAIN.LinkedUploadState(target)
                    state.mark_uncertain(source, digest)
                    old_bytes = state.path.read_bytes()
                    with self.assertRaises(ValueError):
                        state.review_uncertainty('stale review')
                    self.assertEqual(old_bytes, state.path.read_bytes())
                    marker = state.recovery_marker()[1]
                    state.review_uncertainty(marker)
                    restarted = MAIN.LinkedUploadState(target)
                    self.assertFalse(restarted.has_unresolved_upload())
                    self.assertTrue(restarted.has_uncertain_hash(digest))
                    self.assertFalse(restarted.retry_allowed(root / 'copy.jpg', digest))
                    self.assertFalse(restarted.retry_allowed(source, 'f' * 64))
                    with self.assertRaises(ValueError):
                        restarted.mark_uploaded(source, digest, source.stat().st_size, {})
                    with self.assertRaises(ValueError):
                        restarted.mark_uncertain(root / 'copy.jpg', digest)
                    with self.assertRaises(ValueError):
                        restarted.review_uncertainty(marker)
                    fresh = root / 'fresh.jpg'
                    self.assertTrue(restarted.retry_allowed(fresh, 'f' * 64))
                    restarted.mark_uncertain(fresh, 'f' * 64)
                    self.assertTrue(restarted.has_unresolved_upload())
                    self.assertTrue(restarted.has_uncertain_hash(digest))
                    self.assertEqual(b'original retained bytes', source.read_bytes())
                finally:
                    lease.release()

    def test_reviewed_unknown_is_excluded_from_new_import_admission(self) -> None:
        """Keep an unknown original out of new jobs without blocking new files.

        Returns:
            None: First binding succeeds for the fresh source only, while the
                excluded bytes and uncertain journal remain unchanged.
        """
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            source = root / 'uncertain.jpg'
            fresh = root / 'new.jpg'
            source.write_bytes(b'retained original')
            fresh.write_bytes(b'other original')
            binding = 'a' * 64
            target = mock.Mock()
            target.binding.return_value = binding
            target.issuer, target.title, target.gallery_id = (
                'https://gallery.example', 'Gallery', 7,
            )
            target.inventory.return_value = {'existing': []}
            store = JobStateStore(root / 'upload_state.json')
            bindings = ImportBindings(root / 'flights')
            events = MAIN.queue.Queue()
            with mock.patch.object(MAIN, 'CONFIG_DIR', root):
                lease = MAIN.LinkedTargetLease(root, binding)
                lease.acquire()
                try:
                    state = MAIN.LinkedUploadState(target)
                    state.mark_uncertain(source, MAIN.sha256_file(source))
                    state.review_uncertainty(state.recovery_marker()[1])
                    journal = state.path.read_bytes()
                finally:
                    lease.release()
                preflight = MAIN.ImportPreflightThread(
                    MAIN.WatcherConfig(), 'files', [source, fresh], None, None,
                    False, False, events, store, target,
                )
                preflight.run()
                plan = next(event['payload']['plan'] for event in events.queue
                            if event['kind'] == 'preflight_complete')
                bindings.admit_job(store, plan.job, binding)
                self.assertEqual({plan.job.id: binding}, bindings.entries())
                self.assertEqual([fresh], [Path(item.path) for item in plan.job.items])
                self.assertEqual(1, plan.job.skipped)
                self.assertEqual(journal, state.path.read_bytes())
                self.assertEqual(b'retained original', source.read_bytes())

    def test_linked_recovery_refuses_duplicate_and_nonfinite_json(self) -> None:
        """Never let another JSON field hide an uncertain linked upload.

        Returns:
            None: Ambiguous bytes are retained and startup fails closed.
        """
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            target = mock.Mock()
            target.binding.return_value = 'e' * 64
            with mock.patch.object(MAIN, 'CONFIG_DIR', root):
                state = MAIN.LinkedUploadState(target)
                state.mark_uncertain(root / 'sent.jpg', 'a' * 64)
                original = json.dumps(json.loads(state.path.read_text(encoding='utf-8')))
                anchor = '"recent_events": []'
                self.assertIn(anchor, original)
                cases = (
                    original.replace(anchor, '"failures": {}, ' + anchor, 1),
                    original.replace(anchor, '"recent_events": [NaN]', 1),
                    original.replace(anchor, '"recent_events": [1e999]', 1),
                )
                for tampered in cases:
                    with self.subTest(tampered=hash(tampered)):
                        state.path.write_text(tampered, encoding='utf-8')
                        with self.assertRaises(ValueError):
                            MAIN.LinkedUploadState(target)
                        self.assertEqual(tampered, state.path.read_text(encoding='utf-8'))

    def test_uncertain_upload_blocks_different_content_at_same_path(self) -> None:
        """Keep A unresolved when the source changes A to B and back to A.

        Returns:
            None: Neither B nor the restored A may replace the recovery marker.
        """
        with tempfile.TemporaryDirectory() as directory:
            target = mock.Mock()
            target.binding.return_value = 'd' * 64
            path = Path(directory) / 'camera.jpg'
            digest_a, digest_b = 'a' * 64, 'b' * 64
            with mock.patch.object(MAIN, 'CONFIG_DIR', Path(directory)):
                state = MAIN.LinkedUploadState(target)
                state.mark_uncertain(path, digest_a)
                with self.assertRaises(ValueError):
                    state.mark_uncertain(path, digest_b)
                with self.assertRaises(ValueError):
                    state.mark_uploaded(path, digest_b, 1, {'uploaded': 1})
                restored = MAIN.LinkedUploadState(target)
                self.assertTrue(restored.has_unresolved_upload())
                self.assertTrue(restored.has_uncertain_hash(digest_a))
                self.assertFalse(restored.has_uncertain_hash(digest_b))

    def test_fresh_linked_inventory_removes_previous_match(self) -> None:
        """Forget a deleted server image only after a successful fresh query.

        Returns:
            None: An inventory failure never counts as a successful absence.
        """
        digest = 'a' * 64
        target = mock.Mock()
        target.inventory.side_effect = [
            {'existing': [{'sha256': digest}]},
            {'existing': []},
            RuntimeError('offline'),
        ]
        inventory = MAIN.RemoteInventorySession(1, mock.Mock(), target)
        candidate = {'sha256': digest}
        self.assertTrue(inventory.refresh('', '', [candidate], force=True))
        self.assertTrue(inventory.has_hash(digest))
        self.assertTrue(inventory.refresh('', '', [candidate], force=True))
        self.assertFalse(inventory.has_hash(digest))
        self.assertFalse(inventory.refresh('', '', [candidate], force=True))

    def test_linked_inventory_failure_cannot_reuse_cached_match(self) -> None:
        """Forget an earlier positive match when the next check is uncertain.

        Returns:
            None: A failed refresh or recovery helper never confirms the upload.
        """
        digest = 'a' * 64
        target = mock.Mock()
        target.inventory.side_effect = [
            {'existing': [{'sha256': digest}]}, RuntimeError('unavailable'),
        ]
        inventory = MAIN.RemoteInventorySession(1, mock.Mock(), target)
        candidate = {'sha256': digest}
        self.assertTrue(inventory.refresh('', '', [candidate], force=True))
        self.assertTrue(inventory.has_hash(digest))
        self.assertFalse(inventory.refresh('', '', [candidate], force=True))
        self.assertFalse(inventory.has_hash(digest))
        self.assertFalse(inventory.confirm_after_failure('', '', candidate))
        self.assertEqual(2, target.inventory.call_count)

    def test_uncertain_target_blocks_watcher_and_manual_upload(self) -> None:
        """Prevent either producer sending B while an earlier A is unresolved.

        Returns:
            None: Both workflows retain the source and avoid all remote calls.
        """
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            source = root / 'b.jpg'
            source.write_bytes(b'B')
            target = mock.Mock()
            target.binding.return_value = 'e' * 64
            with mock.patch.object(MAIN, 'CONFIG_DIR', root):
                state = MAIN.LinkedUploadState(target)
                state.mark_uncertain(root / 'a.jpg', 'a' * 64)
                watcher = object.__new__(MAIN.WatcherThread)
                watcher._once_work = None
                watcher.linked_target = target
                watcher.state = state
                watcher.drain_only = True
                watcher.accepted_paths = {source}
                watcher.initial_paths = set()
                watcher._admission_lock = threading.RLock()
                watcher.drain_event = threading.Event()
                watcher.stop_event = threading.Event()
                watcher.stability = mock.Mock()
                watcher.stability.stable.return_value = True
                watcher.persist_drain = mock.Mock()
                watcher.remote_inventory = mock.Mock()
                watcher.emit = mock.Mock()
                watcher.scan_once(root, '')
                self.assertTrue(watcher.stop_event.is_set())
                watcher.remote_inventory.refresh.assert_not_called()

                manual = object.__new__(MAIN.ManualUploadThread)
                manual._redirect_refused = threading.Event()
                manual.linked_target = target
                manual.linked_state = MAIN.LinkedUploadState(target)
                manual.remote_inventory = mock.Mock()
                manual.wait_if_paused = mock.Mock(return_value=True)
                manual.update_item = mock.Mock()
                manual.emit = mock.Mock()
                manual.stop_event = threading.Event()
                manual.upload_one('', source, 1, [], None, False)
                self.assertTrue(manual.stop_event.is_set())
                manual.remote_inventory.refresh.assert_not_called()
                target.upload.assert_not_called()
                self.assertEqual(b'B', source.read_bytes())

    def test_linked_thumbnail_snapshot_and_changed_source_block_upload(self) -> None:
        """Generate from captured A and refuse upload if the source becomes B.

        Returns:
            None: No remote operation runs with mismatched thumbnails.
        """
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            source = root / 'photo.jpg'
            source.write_bytes(b'A')
            digest = MAIN.hashlib.sha256(b'A').hexdigest()

            def generate(
                snapshot: Path, _root: Path, _client_id: str,
            ) -> list[MAIN.LocalThumbnail]:
                """Change the original while inspecting the independent copy.

                Args:
                    snapshot (Path): Verified private source copy.
                    _root (Path): Unused fixture output root.
                    _client_id (str): Unused request identifier.

                Returns:
                    list[MAIN.LocalThumbnail]: Empty thumbnail descriptor list.
                """
                self.assertEqual(b'A', snapshot.read_bytes())
                source.write_bytes(b'B')
                return []

            with mock.patch.object(MAIN, 'generate_local_thumbnails', side_effect=generate):
                MAIN.generate_linked_thumbnails(source, root, 'request', digest)
            self.assertFalse((root / 'request' / 'source-snapshot').exists())

            worker = object.__new__(MAIN.ManualUploadThread)
            worker._redirect_refused = threading.Event()
            worker.linked_target = mock.Mock()
            worker.linked_state = mock.Mock()
            worker.remote_inventory = mock.Mock()
            worker.wait_if_paused = mock.Mock(return_value=True)
            worker.update_item = mock.Mock()
            worker.emit = mock.Mock()
            worker.progress_lock = threading.Lock()
            worker.stop_event = threading.Event()
            worker.failed = worker.completed_uploads = 0
            worker.upload_one('', source, 1, [], None, False, digest)
            worker.remote_inventory.refresh.assert_not_called()
            worker.linked_target.upload.assert_not_called()
            self.assertTrue(worker.stop_event.is_set())
            self.assertEqual(b'B', source.read_bytes())

    def test_thumbnail_pool_startup_failure_cleans_owned_directory(self) -> None:
        """Clean staging and any created process pool if pool creation fails.

        Returns:
            None: Both executor construction failure paths retain no staging.
        """
        for failure_at in ('process', 'upload'):
            with self.subTest(failure_at=failure_at):
                with tempfile.TemporaryDirectory() as directory:
                    staging = Path(directory) / 'thumbnails'
                    staging.mkdir()
                    worker = object.__new__(MAIN.ManualUploadThread)
                    worker._redirect_refused = threading.Event()
                    worker.thumbnail_workers = worker.upload_workers = 1
                    worker.emit = mock.Mock()
                    pool = mock.Mock()
                    process_error = RuntimeError('process pool failed')
                    upload_error = RuntimeError('upload pool failed')
                    process = mock.Mock(side_effect=process_error) if failure_at == 'process' else mock.Mock(return_value=pool)
                    upload = mock.Mock(side_effect=upload_error)
                    with mock.patch.object(MAIN, 'local_thumbnail_supported', return_value=True), \
                            mock.patch.object(MAIN.tempfile, 'mkdtemp', return_value=str(staging)), \
                            mock.patch.object(MAIN.concurrent.futures, 'ProcessPoolExecutor', process), \
                            mock.patch.object(MAIN.concurrent.futures, 'ThreadPoolExecutor', upload):
                        with self.assertRaises(RuntimeError):
                            worker.run_with_client_thumbnails('')
                    self.assertFalse(staging.exists())
                    if failure_at == 'upload':
                        pool.shutdown.assert_called_once_with(wait=True, cancel_futures=True)
                    else:
                        upload.assert_not_called()


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

    def test_captured_worker_config_is_independent_and_owner_checked(self) -> None:
        """Bind the exact sealed worker copy to the real saved config owner.

        Returns:
            None: Rejects mutation, stale copies and mismatched credentials.
        """
        with tempfile.TemporaryDirectory() as directory:
            store = MAIN.ConfigStore(Path(directory) / 'config.json')
            config = store.load()
            config.gallery_url = 'http://localhost:8888/Galerie'
            config.api_key = 'pgu_fixture'
            store.save(config)
            captured = MAIN.capture_worker_config(config)
            self.assertIsNot(captured, config)
            self.assertIs(captured._snapshot_token, config._snapshot_token)
            self.assertNotIn('pgu_fixture', repr(captured))
            with self.assertRaises(AttributeError):
                captured.api_key = 'pgu_other'
            with self.assertRaises(AttributeError):
                del captured._capture_sealed
            vault = mock.MagicMock()
            vault.transaction.return_value.__enter__.return_value.read.return_value = None
            owner = MAIN.GuiModeOwner(store.mode_port(config), lambda: vault)
            owner.initialize()
            work = owner.acquire('legacy', store.mode_port(captured))
            work.checkpoint()
            config.api_key = 'pgu_other'
            with self.assertRaises(MAIN.ModeCoordinatorError):
                work.checkpoint()
            work.close()
            self.assertEqual('pgu_fixture', captured.api_key)

    def test_mode_coordinator_uses_real_config_port_and_preserves_key(self) -> None:
        """Exercise first setup, legacy and publication through the real port.

        Returns:
            None: A fake vault and real ConfigStore retain exact key and anchor.
        """
        from uploader.app_linking import UPLOAD_SCOPE
        from uploader.mode_coordinator import ModeCoordinator, ModeCoordinatorError
        from uploader.upload_mode import ModeRecord, encode_mode_record, profile_path_digest

        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / 'config.json'
            store = MAIN.ConfigStore(path)
            config = store.load()
            config_path = r'C:\Users\Tester\config.json'
            vault = mock.MagicMock()
            transaction = vault.transaction.return_value.__enter__.return_value
            saved: list[ModeRecord | None] = [None]
            transaction.read.side_effect = lambda: saved[0]

            def write_mode(record: ModeRecord) -> None:
                """Store one typed mode record in the isolated vault double.

                Args:
                    record (ModeRecord): Exact selected mode generation.

                Returns:
                    None: Retains the record for subsequent reads.
                """
                saved[0] = record

            transaction.write.side_effect = write_mode
            with mock.patch.object(
                MAIN._ConfigModeTransaction, 'config_path',
                new_callable=mock.PropertyMock, return_value=config_path,
            ):
                coordinator = ModeCoordinator(store.mode_port(config), vault)
                first = coordinator.refresh()
                self.assertEqual('first_setup', first.state)
                with self.assertRaises(ModeCoordinatorError):
                    coordinator.acquire_work(first, 'legacy')
                config.api_key = 'pgu_fixture'
                store.save(config)
                legacy = coordinator.refresh()
                self.assertEqual('legacy_unmigrated', legacy.state)
                with coordinator.acquire_work(legacy, 'legacy'):
                    pass
                record = ModeRecord(
                    'a' * 32, 'b' * 32, 'linked',
                    profile_path_digest(config_path),
                    'https://gallery.example', 7, UPLOAD_SCOPE, 9,
                )
                linked = coordinator.publish(legacy, record)
                self.assertEqual('linked_disconnected', linked.state)
                self.assertEqual(record, saved[0])
                config.watch_recursive = True
                store.save(config)
                with self.assertRaises(ModeCoordinatorError):
                    coordinator.acquire_work(linked, 'reconnect')
                self.assertEqual('linked_disconnected', coordinator.refresh().state)
                with store.mode_port(config).mode_transaction() as config_tx:
                    self.assertEqual(
                        encode_mode_record(record),
                        config_tx.read_mode_anchor(config_tx.snapshot()),
                    )
            self.assertEqual('pgu_fixture', json.loads(path.read_text())['api_key'])

    def test_mode_anchor_transaction_refreshes_only_bound_config(self) -> None:
        """Keep the live config usable while rejecting older settings copies.

        Returns:
            None: Verifies anchor CAS, token refresh and ordinary-save retention.
        """
        from uploader.app_linking import UPLOAD_SCOPE
        from uploader.upload_mode import (
            ModeRecord, encode_mode_record, profile_path_digest,
        )

        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / 'config.json'
            store = MAIN.ConfigStore(path)
            config = store.load()
            config.api_key = 'pgu_fixture'
            store.save(config)
            stale = MAIN.replace(config, watch_recursive=True)
            record = ModeRecord(
                'a' * 32, 'b' * 32, 'linked',
                profile_path_digest(r'C:\Users\Tester\config.json'),
                'https://gallery.example', 7, UPLOAD_SCOPE, 9,
            )
            payload = encode_mode_record(record)
            port = store.mode_port(config)
            with port.mode_transaction() as transaction:
                before = transaction.snapshot()
                self.assertEqual(str(path), transaction.config_path)
                self.assertIsNone(transaction.read_mode_anchor(before))
                after = transaction.write_mode_anchor(before, payload)
                self.assertEqual(payload, transaction.read_mode_anchor(after))
                with self.assertRaises(OSError):
                    transaction.read_mode_anchor(before)
            with self.assertRaises(OSError):
                store.save(stale)
            config.watch_recursive = True
            store.save(config)
            with store.mode_port(config).mode_transaction() as transaction:
                self.assertEqual(payload, transaction.read_mode_anchor(transaction.snapshot()))
            self.assertEqual('pgu_fixture', json.loads(path.read_text())['api_key'])

    def test_mode_anchor_transaction_refuses_missing_or_stale_config(self) -> None:
        """Do not infer an anchor from first-run or a stale config object.

        Returns:
            None: Checks missing-file and stale-generation transaction refusal.
        """
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / 'config.json'
            store = MAIN.ConfigStore(path)
            config = store.load()
            with store.mode_port(config).mode_transaction() as transaction:
                missing = transaction.snapshot()
                self.assertEqual('missing', missing.state)
                with self.assertRaises(OSError):
                    transaction.read_mode_anchor(missing)
                with self.assertRaises(OSError):
                    transaction.write_mode_anchor(missing, b'{}')
            old = MAIN.replace(config)
            store.save(config)
            with self.assertRaises(OSError):
                with store.mode_port(old).mode_transaction():
                    pass

    def test_mode_anchor_transaction_refuses_reentrant_config_operations(self) -> None:
        """A mode transaction excludes reentrant normal config operations.

        Returns:
            None: Refusals and caller failure still release the shared lock.
        """
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / 'config.json'
            store = MAIN.ConfigStore(path)
            config = store.load()
            store.save(config)
            with self.assertRaises(RuntimeError):
                with store.mode_port(config).mode_transaction() as transaction:
                    transaction.snapshot()
                    with self.assertRaises(OSError):
                        store.save(MAIN.replace(config, api_key='pgu_new'))
                    with self.assertRaises(OSError):
                        store.load()
                    with self.assertRaises(OSError):
                        with store.mode_port(config).mode_transaction():
                            pass
                    self.assertEqual('valid', transaction.snapshot().state)
                    raise RuntimeError('fixture failure')
            self.assertEqual('', store.load().api_key)

    def test_mode_port_pins_both_config_owner_paths(self) -> None:
        """Refuse mismatched or subsequently changed config-owner paths.

        Returns:
            None: Stale paths cannot read or write the mode anchor.
        """
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / 'config.json'
            other = Path(directory) / 'other.json'
            store = MAIN.ConfigStore(path)
            config = store.load()
            store.save(config)
            other.write_text('{"api_key":"other"}', encoding='utf-8')
            store._snapshot_store.path = other
            with self.assertRaises(OSError):
                store.mode_port(config)
            store._snapshot_store.path = path
            port = store.mode_port(config)
            with port.mode_transaction() as transaction:
                snapshot = transaction.snapshot()
                store.path = other
                with self.assertRaises(OSError):
                    transaction.config_path
                with self.assertRaises(OSError):
                    transaction.snapshot()
                with self.assertRaises(OSError):
                    transaction.read_mode_anchor(snapshot)
                with self.assertRaises(OSError):
                    transaction.write_mode_anchor(snapshot, b'{}')
                store.path = path
                self.assertIs(snapshot, transaction.snapshot())
            self.assertEqual('other', json.loads(other.read_text())['api_key'])

    def test_mode_port_preserves_raw_argument_and_pins_owner(self) -> None:
        """Keep pre-Path syntax while observing the actual owned config path.

        Returns:
            None: Raw dot spelling survives and later substitution is refused.
        """
        with tempfile.TemporaryDirectory() as directory:
            raw = directory + os.sep + '.' + os.sep + 'config.json'
            path = Path(raw)
            args = MAIN.parse_args(['--config', raw])
            store = MAIN.ConfigStore(args.config)
            config = store.load()
            store.save(config)
            port = store.mode_port(config)
            with port.mode_transaction() as transaction:
                snapshot = transaction.snapshot()
                self.assertEqual(raw, transaction.config_path)
                self.assertNotEqual(raw, str(store.path))
                store._mode_raw_path = str(path)
                with self.assertRaises(OSError):
                    transaction.read_mode_anchor(snapshot)
                store._mode_raw_path = raw
                self.assertIsNone(transaction.read_mode_anchor(snapshot))
            path_store = MAIN.ConfigStore(path)
            path_config = path_store.load()
            with path_store.mode_port(path_config).mode_transaction() as transaction:
                self.assertEqual(str(path), transaction.config_path)

    def test_raw_windows_aliases_remain_visible_without_touching_unc(self) -> None:
        """Preserve UNC and alias bytes without opening their remote paths.

        Returns:
            None: Legacy absence is allowed; invalid v2 bindings are refused.
        """
        from uploader.upload_mode import UploadModeError, profile_path_digest, resolve_mode

        aliases = (r'C:\gallery\.\config.json', r'C:\gallery\..\config.json',
                   r'\\server\share\config.json')
        for raw in aliases:
            with self.subTest(raw=raw):
                args = MAIN.parse_args(['--config', raw])
                store = MAIN.ConfigStore(args.config)
                port = store.mode_port(MAIN.WatcherConfig())
                self.assertEqual(raw, port._raw_path)
                self.assertEqual(('legacy_unmigrated', None), resolve_mode(
                    None, None, config_verified=True, config_path=raw,
                ))
                with self.assertRaises(UploadModeError):
                    profile_path_digest(raw)

    def test_mode_port_refuses_unpersisted_live_config_changes(self) -> None:
        """Never bind mode evidence to different in-memory connection fields.

        Returns:
            None: Unsaved first-run and existing-config changes are refused.
        """
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / 'config.json'
            store = MAIN.ConfigStore(path)
            config = store.load()
            config.api_key = 'pgu_unsaved'
            with self.assertRaises(OSError):
                with store.mode_port(config).mode_transaction():
                    pass
            self.assertFalse(path.exists())
            store.save(config)
            with store.mode_port(config).mode_transaction() as transaction:
                self.assertEqual('valid', transaction.snapshot().state)
                config.api_key = 'pgu_changed_without_save'
                with self.assertRaises(OSError):
                    transaction.snapshot()
            self.assertEqual('pgu_unsaved', store.load().api_key)

    def test_first_launch_creates_parent_and_saves_legacy_config(self) -> None:
        """Allow a first-run or explicitly selected not-yet-created directory.

        Returns:
            None: Loads defaults and saves a gallery-scoped legacy key.
        """
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / 'new-profile' / 'config.json'
            store = MAIN.ConfigStore(path)
            config = store.load()
            config.api_key = 'pgu_fixture'
            config.gallery_url = 'http://localhost:8888/Galerie'
            store.save(config)
            self.assertEqual('pgu_fixture', MAIN.ConfigStore(path).load().api_key)

    def test_migration_and_save_preserve_unowned_config_values(self) -> None:
        """Keep saved credentials and opaque metadata through normal rewrites.

        Returns:
            None: Asserts migration and ordinary saving preserve unknown data.
        """
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / 'config.json'
            opaque = b'{"fraction":0.1234567890123456789,"s":"\\u0041"}'
            path.write_bytes(
                b'{"gallery_url":"http://localhost:8888/Galerie",'
                b'"api_key":"pgu_fixture","future_mode":' + opaque + b'}'
            )
            store = MAIN.ConfigStore(path)
            config = store.load()
            self.assertEqual('pgu_fixture', config.api_key)
            self.assertIn(b'"future_mode":' + opaque, path.read_bytes())
            store.save(MAIN.replace(config, watch_recursive=True))
            self.assertIn(b'"future_mode":' + opaque, path.read_bytes())
            self.assertEqual('pgu_fixture', json.loads(path.read_text())['api_key'])

    def test_direct_first_save_retry_requires_unchanged_missing_snapshot(self) -> None:
        """Allow retry after pre-publication failure without ignoring a new file.

        Returns:
            None: Retries an unchanged missing path and refuses a raced creation.
        """
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / 'config.json'
            store = MAIN.ConfigStore(path)
            config = MAIN.WatcherConfig(api_key='pgu_fixture')
            with mock.patch('uploader.config_snapshot.os.fsync', side_effect=OSError):
                with self.assertRaises(OSError):
                    store.save(config)
            self.assertFalse(path.exists())
            store.save(config)
            self.assertEqual('pgu_fixture', json.loads(path.read_text())['api_key'])

            other_path = Path(directory) / 'other.json'
            other = MAIN.ConfigStore(other_path)
            other_config = MAIN.WatcherConfig(api_key='pgu_fixture')
            with mock.patch('uploader.config_snapshot.os.fsync', side_effect=OSError):
                with self.assertRaises(OSError):
                    other.save(other_config)
            other_path.write_text('{"api_key":"newer"}', encoding='utf-8')
            with self.assertRaises(OSError):
                other.save(other_config)
            self.assertEqual('newer', json.loads(other_path.read_text())['api_key'])

    def test_stale_config_copy_cannot_overwrite_newer_save(self) -> None:
        """Reject old UI copies even when the owner has a newer snapshot.

        Returns:
            None: Retains the current key and the latest validated file.
        """
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / 'config.json'
            store = MAIN.ConfigStore(path)
            current = store.load()
            old = MAIN.replace(current, watch_recursive=True)
            latest = MAIN.replace(current, api_key='pgu_new')
            store.save(latest)
            self.assertNotIn('_snapshot_token', latest.to_dict())
            self.assertNotIn('_snapshot_token', repr(latest))
            with self.assertRaises(OSError):
                store.save(old)
            self.assertEqual('pgu_new', json.loads(path.read_text())['api_key'])

    def test_corrupt_config_refuses_gui_and_command_admission(self) -> None:
        """Keep damaged configuration unchanged and prevent startup workers.

        Returns:
            None: Checks normal, once, startup and startup-registration modes.
        """
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / 'config.json'
            original = b'{"api_key":"pgu_fixture",'
            path.write_bytes(original)
            for arguments in ([], ['--once'], ['--startup'], ['--configure-startup']):
                with (
                    self.subTest(arguments=arguments),
                    mock.patch.object(MAIN, 'WindowsInstance') as instance,
                    mock.patch.object(MAIN, 'setup_logging'),
                    mock.patch.object(MAIN, 'WatcherApp') as app,
                    mock.patch.object(MAIN, 'run_once') as once,
                    mock.patch.object(MAIN, 'synchronize_startup') as startup,
                    mock.patch.object(MAIN, 'messagebox') as messages,
                    mock.patch.object(MAIN.sys, 'stderr', io.StringIO()),
                ):
                    instance.return_value.acquire.return_value = True
                    self.assertEqual(1, MAIN.main(arguments + ['--config', str(path)]))
                    app.assert_not_called()
                    once.assert_not_called()
                    startup.assert_not_called()
                    if '--once' in arguments or '--configure-startup' in arguments:
                        messages.showerror.assert_not_called()
                    self.assertEqual(original, path.read_bytes())

    def test_once_requires_real_mode_admission_for_the_complete_scan(self) -> None:
        """Hold the verified legacy work lease until the worker has returned.

        Returns:
            None: An unmigrated config uses the existing API-key worker once.
        """
        from uploader.mode_coordinator import (
            ModeConfigPort, ModeCoordinator, ModeCoordinatorError, ModeVaultPort,
        )

        with tempfile.TemporaryDirectory() as directory:
            store = MAIN.ConfigStore(Path(directory) / 'config.json')
            config = store.load()
            config.gallery_url = 'http://localhost:8888/Galerie'
            config.api_key = 'pgu_fixture'
            store.save(config)
            vault = mock.MagicMock()
            vault.transaction.return_value.__enter__.return_value.read.return_value = None
            observed: list[ModeCoordinator] = []

            def coordinator_factory(port: ModeConfigPort, owner: ModeVaultPort) -> ModeCoordinator:
                """Keep the actual coordinator available for lease assertions.

                Args:
                    port (ModeConfigPort): ConfigStore-owned transaction port.
                    owner (ModeVaultPort): Synthetic mode vault transaction port.

                Returns:
                    ModeCoordinator: Actual coordinator used by the scan.
                """
                coordinator = ModeCoordinator(port, owner)
                observed.append(coordinator)
                return coordinator

            with mock.patch.object(MAIN, 'WindowsUploadModeStore', return_value=vault), \
                    mock.patch.object(MAIN, 'ModeCoordinator', side_effect=coordinator_factory), \
                    mock.patch.object(MAIN, 'setup_logging'), \
                    mock.patch.object(MAIN, 'WatcherThread') as worker:
                def scan(_folder: Path, _url: str) -> None:
                    """Verify a transition cannot start while the scan is active.

                    Args:
                        _folder (Path): Unused fixture watch folder.
                        _url (str): Unused normalized upload endpoint.

                    Returns:
                        None: The actual admission lease is still held.
                    """
                    with self.assertRaises(ModeCoordinatorError):
                        observed[0].refresh()

                worker.return_value.scan_once.side_effect = scan
                self.assertEqual(0, MAIN.run_once(config, store))
                worker.assert_called_once()
                self.assertIs(config, worker.call_args.args[0])
                worker.return_value.scan_once.assert_called_once()
                self.assertEqual('legacy_unmigrated', observed[0].refresh().state)
        with self.assertRaises(TypeError):
            MAIN.run_once(MAIN.WatcherConfig())

    def test_once_refuses_unavailable_or_first_setup_before_worker(self) -> None:
        """Treat missing config or a vault failure as no authority to scan.

        Returns:
            None: The worker is never constructed for either unavailable state.
        """
        for scenario in ('first_setup', 'vault_unavailable', 'vault_open_failure',
                         'mismatched_config', 'bad_anchor'):
            with self.subTest(scenario=scenario), tempfile.TemporaryDirectory() as directory:
                store = MAIN.ConfigStore(Path(directory) / 'config.json')
                config = store.load()
                if scenario != 'first_setup':
                    store.save(config)
                if scenario == 'bad_anchor':
                    path = store.path
                    document = json.loads(path.read_text(encoding='utf-8'))
                    document['upload_mode_v2'] = {'invalid': True}
                    path.write_text(json.dumps(document), encoding='utf-8')
                    config = store.load()
                vault = mock.MagicMock()
                vault.transaction.return_value.__enter__.return_value.read.return_value = None
                if scenario == 'vault_unavailable':
                    vault.transaction.side_effect = OSError('fixture vault unavailable')
                if scenario == 'mismatched_config':
                    config = MAIN.replace(config, api_key='pgu_unsaved')
                with mock.patch.object(MAIN, 'WindowsUploadModeStore', return_value=vault), \
                        mock.patch.object(MAIN, 'WatcherThread') as worker, \
                        mock.patch.object(MAIN.sys, 'stderr', io.StringIO()):
                    if scenario == 'vault_open_failure':
                        MAIN.WindowsUploadModeStore.side_effect = OSError('fixture vault open failed')
                    self.assertEqual(1, MAIN.run_once(config, store))
                    worker.assert_not_called()

    def test_once_rechecks_evidence_for_each_network_attempt(self) -> None:
        """Stop alternate routes, redirects and thumbnail fallback on mode drift.

        Returns:
            None: A real coordinator uses a fake vault and no network or
                Windows API; only verified attempts reach the fake transport.
        """
        scenarios = (
            'healthy', 'after_inventory', 'inventory_route', 'redirect',
            'upload_route', 'thumbnail_fallback', 'reconcile',
        )
        for scenario in scenarios:
            with self.subTest(scenario=scenario), tempfile.TemporaryDirectory() as directory:
                root = Path(directory)
                source = root / 'photo.jpg'
                source.write_bytes(b'synthetic fixture photo')
                store = MAIN.ConfigStore(root / 'config.json')
                config = store.load()
                config.watched_folder = str(root)
                config.gallery_url = 'http://localhost:8888/Galerie'
                config.api_key = 'pgu_fixture'
                config.create_thumbnails = scenario == 'thumbnail_fallback'
                store.save(config)
                vault = mock.MagicMock()
                vault_reader = vault.transaction.return_value.__enter__.return_value
                vault_reader.read.return_value = None
                state = mock.MagicMock()
                state.already_uploaded_path.return_value = False
                state.already_uploaded_hash.return_value = False
                state.retry_allowed.return_value = True
                attempts: list[str] = []
                handlers: list[MAIN._GalleryKeyRedirectHandler] = []
                opener = mock.MagicMock()

                def make_opener(handler: MAIN._GalleryKeyRedirectHandler) -> mock.MagicMock:
                    """Capture the real redirect guard and return a fake opener.

                    Args:
                        handler (MAIN._GalleryKeyRedirectHandler): Guard for
                            the synthetic request's redirects.

                    Returns:
                        mock.MagicMock: Opener that never contacts a network.
                    """
                    handlers.append(handler)
                    return opener

                def open_request(http_request: MAIN.request.Request, timeout: float) -> mock.MagicMock:
                    """Simulate one request and optionally invalidate evidence.

                    Args:
                        http_request (MAIN.request.Request): Inventory or
                            multipart request submitted by the worker.
                        timeout (float): Existing socket timeout, unused here.

                    Returns:
                        mock.MagicMock: Context-managed synthetic JSON response.

                    Raises:
                        MAIN.error.HTTPError: Synthetic routing-only 404.
                        MAIN.error.URLError: Synthetic failed upload transport.
                        MAIN.ModeCoordinatorError: Redirect evidence changed.
                    """
                    is_inventory = http_request.get_header('Content-type').startswith('application/json')
                    attempts.append('inventory' if is_inventory else 'upload')
                    if scenario == 'redirect' and is_inventory:
                        vault_reader.read.return_value = 'unavailable fixture evidence'
                        handlers[-1].redirect_request(
                            http_request, io.BytesIO(), 302, 'Found',
                            MAIN.email.message.Message(),
                            'http://localhost:8888/Galerie/redirected',
                        )
                    if ((scenario == 'inventory_route' and is_inventory)
                            or (scenario == 'upload_route' and not is_inventory)):
                        vault_reader.read.return_value = 'unavailable fixture evidence'
                        raise MAIN.error.HTTPError(
                            http_request.full_url, 404, 'Not Found', {},
                            io.BytesIO(b'non-JSON routing error'),
                        )
                    if scenario == 'thumbnail_fallback' and not is_inventory:
                        vault_reader.read.return_value = 'unavailable fixture evidence'
                        raise MAIN.error.URLError('synthetic transport failure')
                    if scenario == 'reconcile' and not is_inventory:
                        vault_reader.read.return_value = 'unavailable fixture evidence'
                        raise MAIN.error.URLError('Remote end closed connection without response')
                    if scenario == 'after_inventory' and is_inventory:
                        vault_reader.read.return_value = 'unavailable fixture evidence'
                    response = mock.MagicMock()
                    response.__enter__.return_value.read.return_value = (
                        b'{"ok":true,"existing":[],"checked":1}' if is_inventory
                        else b'{"ok":true,"uploaded":1,"scanned":1}'
                    )
                    return response

                opener.open.side_effect = open_request
                with mock.patch.object(MAIN, 'WindowsUploadModeStore', return_value=vault), \
                        mock.patch.object(MAIN, 'UploadState', return_value=state), \
                        mock.patch.object(MAIN, 'FileStabilityTracker') as stability, \
                        mock.patch.object(MAIN, 'SimConnectCameraClient'), \
                        mock.patch.object(MAIN, 'setup_logging'), \
                        mock.patch.object(MAIN, 'local_thumbnail_supported', return_value=True), \
                        mock.patch.object(MAIN, 'generate_local_thumbnails', return_value=[]), \
                        mock.patch.object(MAIN.request, 'build_opener', side_effect=make_opener), \
                        mock.patch.object(MAIN.sys, 'stderr', io.StringIO()):
                    stability.return_value.stable.return_value = True
                    result = MAIN.run_once(config, store)
                if scenario == 'healthy':
                    self.assertEqual(0, result)
                    self.assertEqual(['inventory', 'upload'], attempts)
                    state.mark_uploaded.assert_not_called()
                else:
                    self.assertEqual(1, result)
                    expected = (['inventory', 'upload'] if scenario in
                                ('upload_route', 'thumbnail_fallback', 'reconcile')
                                else ['inventory'])
                    self.assertEqual(expected, attempts)
                    state.mark_uploaded.assert_not_called()
                    state.mark_failure.assert_not_called()

    def test_once_main_preserves_raw_config_argument(self) -> None:
        """Pass the original CLI spelling into the owned once-mode config port.

        Returns:
            None: The raw dot component survives while legacy scanning is admitted.
        """
        with tempfile.TemporaryDirectory() as directory:
            raw = directory + os.sep + '.' + os.sep + 'config.json'
            store = MAIN.ConfigStore(raw)
            config = store.load()
            store.save(config)
            vault = mock.MagicMock()
            vault.transaction.return_value.__enter__.return_value.read.return_value = None
            with mock.patch.object(MAIN, 'WindowsInstance') as instance, \
                    mock.patch.object(MAIN, 'WindowsUploadModeStore', return_value=vault), \
                    mock.patch.object(MAIN, 'ModeCoordinator', wraps=MAIN.ModeCoordinator) as owner, \
                    mock.patch.object(MAIN, 'setup_logging'), \
                    mock.patch.object(MAIN, 'WatcherThread') as worker:
                instance.return_value.acquire.return_value = True
                self.assertEqual(0, MAIN.main(['--once', '--config', raw]))
                self.assertEqual(raw, owner.call_args.args[0]._raw_path)
                worker.return_value.scan_once.assert_called_once()
                instance.return_value.close.assert_called_once()

    def test_once_refuses_persisted_linked_mode_before_worker(self) -> None:
        """An owned linked record cannot fall back to saved API-key scanning.

        Returns:
            None: Matching linked config and vault are observed but never scanned.
        """
        from uploader.app_linking import UPLOAD_SCOPE
        from uploader.upload_mode import ModeRecord, encode_mode_record, profile_path_digest

        with tempfile.TemporaryDirectory() as directory:
            store = MAIN.ConfigStore(Path(directory) / 'config.json')
            config = store.load()
            store.save(config)
            raw = r'C:\Users\Tester\config.json'
            record = ModeRecord('a' * 32, 'b' * 32, 'linked', profile_path_digest(raw),
                                'https://gallery.example', 7, UPLOAD_SCOPE, 9)
            with store.mode_port(config).mode_transaction() as transaction:
                transaction.write_mode_anchor(transaction.snapshot(), encode_mode_record(record))
            vault = mock.MagicMock()
            vault.transaction.return_value.__enter__.return_value.read.return_value = record
            with mock.patch.object(MAIN._ConfigModeTransaction, 'config_path',
                                   new_callable=mock.PropertyMock, return_value=raw), \
                    mock.patch.object(MAIN, 'WindowsUploadModeStore', return_value=vault), \
                    mock.patch.object(MAIN, 'WatcherThread') as worker, \
                    mock.patch.object(MAIN.sys, 'stderr', io.StringIO()):
                self.assertEqual(1, MAIN.run_once(config, store))
                worker.assert_not_called()

    def test_once_accepts_confirmed_legacy_and_unmigrated_unc(self) -> None:
        """Permit only validated legacy evidence, including an unbound UNC path.

        Returns:
            None: Both cases admit the API-key scan with a synthetic vault.
        """
        from uploader.upload_mode import ModeRecord, encode_mode_record, profile_path_digest

        for bound in (False, True):
            with self.subTest(bound=bound), tempfile.TemporaryDirectory() as directory:
                store = MAIN.ConfigStore(Path(directory) / 'config.json')
                config = store.load()
                store.save(config)
                raw = r'C:\Users\Tester\config.json' if bound else r'\\server\share\config.json'
                record = None
                if bound:
                    record = ModeRecord('a' * 32, 'b' * 32, 'legacy', profile_path_digest(raw))
                    with store.mode_port(config).mode_transaction() as transaction:
                        transaction.write_mode_anchor(transaction.snapshot(), encode_mode_record(record))
                vault = mock.MagicMock()
                vault.transaction.return_value.__enter__.return_value.read.return_value = record
                with mock.patch.object(MAIN._ConfigModeTransaction, 'config_path',
                                       new_callable=mock.PropertyMock, return_value=raw), \
                        mock.patch.object(MAIN, 'WindowsUploadModeStore', return_value=vault), \
                        mock.patch.object(MAIN, 'setup_logging'), \
                        mock.patch.object(MAIN, 'WatcherThread') as worker:
                    self.assertEqual(0, MAIN.run_once(config, store))
                    worker.return_value.scan_once.assert_called_once()

    def test_once_releases_lease_when_scan_fails(self) -> None:
        """Close admission after a failed scan without printing private values.

        Returns:
            None: Failure returns nonzero and coordinator can observe again.
        """
        from uploader.mode_coordinator import ModeCoordinator

        with tempfile.TemporaryDirectory() as directory:
            store = MAIN.ConfigStore(Path(directory) / 'config.json')
            config = store.load()
            config.api_key = 'pgu_sensitive_fixture'
            store.save(config)
            vault = mock.MagicMock()
            vault.transaction.return_value.__enter__.return_value.read.return_value = None
            coordinator = ModeCoordinator(store.mode_port(config), vault)
            stderr = io.StringIO()
            with mock.patch.object(MAIN, 'WindowsUploadModeStore', return_value=vault), \
                    mock.patch.object(MAIN, 'ModeCoordinator', return_value=coordinator), \
                    mock.patch.object(MAIN, 'setup_logging'), \
                    mock.patch.object(MAIN, 'WatcherThread') as worker, \
                    mock.patch.object(MAIN.sys, 'stderr', stderr):
                worker.return_value.scan_once.side_effect = OSError('pgu_sensitive_fixture')
                self.assertEqual(1, MAIN.run_once(config, store))
            self.assertNotIn('pgu_sensitive_fixture', stderr.getvalue())
            self.assertEqual('legacy_unmigrated', coordinator.refresh().state)

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
    """Check URL handling and legacy upload state and inventory behavior."""

    def test_failed_fresh_inventory_cannot_reuse_an_older_match(self) -> None:
        """Discard stale positive hashes when the next legacy request fails.

        Returns:
            None: The same candidate is no longer a usable duplicate match.
        """
        inventory = MAIN.RemoteInventorySession(MAIN.DEFAULT_INVENTORY_REFRESH_SECONDS, mock.Mock())
        digest = 'a' * 64
        candidate = {'client_id': 'fixture', 'filename': 'photo.jpg',
                     'size': 5, 'sha256': digest}
        responses = [
            ({'ok': True, 'action': 'inventory',
              'existing': [{'sha256': digest}]},
             'https://gallery.example/api/upload'),
            RuntimeError('synthetic unavailable server'),
        ]
        with mock.patch.object(MAIN, 'post_json_resolved', side_effect=responses):
            self.assertTrue(inventory.refresh('https://gallery.example', 'key',
                                              [candidate], force=True))
            self.assertTrue(inventory.has_hash(digest))
            self.assertEqual('https://gallery.example/api/upload',
                             inventory.resolved_endpoint)
            self.assertFalse(inventory.refresh('https://gallery.example', 'key',
                                               [candidate], force=True))
        self.assertFalse(inventory.has_hash(digest))
        self.assertEqual('', inventory.resolved_endpoint)

    """Exercise legacy endpoint, credential redirect and upload state behavior."""

    def test_watcher_retains_pending_queue_after_unsafe_redirect(self) -> None:
        """Publish a safe failure and keep accepted photos for finalization.

        Returns:
            None: The persistent queue retains its exact pending photo and error.
        """
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            source = root / 'photo.jpg'
            source.write_bytes(b'fixture photo')
            watcher = object.__new__(MAIN.WatcherThread)
            watcher._linked_lease = None
            watcher._once_work = None
            watcher._admission_lock = threading.RLock()
            watcher.stop_event = threading.Event()
            watcher.config = MAIN.WatcherConfig(watched_folder=str(root))
            watcher.accepted_paths = {source}
            watcher.initial_paths = set()
            watcher.drain_manifest = root / 'watch-drain.json'
            watcher.drain_only = True
            watcher.drain_requested_at = 0.0
            watcher.drain_error = ''
            watcher.emit = mock.Mock()
            watcher._run_owned = mock.Mock(
                side_effect=MAIN.GalleryKeyRedirectError('unsafe URL and key'),
            )
            MAIN.WatcherThread.run(watcher)
            saved = json.loads(watcher.drain_manifest.read_text(encoding='utf-8'))
            self.assertEqual([str(source)], saved['pending'])
            self.assertIn('redirect was refused', saved['error'])
            self.assertNotIn('unsafe URL and key', saved['error'])
            self.assertTrue(watcher.stop_event.is_set())
            watcher.emit.assert_called_once_with('error', saved['error'])

    def test_unsafe_redirect_does_not_trigger_thumbnail_fallback(self) -> None:
        """Refuse a second upload after a thumbnail request redirect fails.

        Returns:
            None: The redirect error escapes after exactly one multipart call.
        """
        with tempfile.TemporaryDirectory() as directory:
            source = Path(directory) / 'photo.jpg'
            source.write_bytes(b'fixture photo')
            watcher = object.__new__(MAIN.WatcherThread)
            watcher._once_work = None
            watcher.linked_target = None
            watcher.config = MAIN.WatcherConfig(api_key='pgu_fixture', create_thumbnails=True)
            watcher.emit = mock.Mock()
            with mock.patch.object(MAIN, 'local_thumbnail_supported', return_value=True), \
                    mock.patch.object(MAIN, 'generate_local_thumbnails', return_value=[]), \
                    mock.patch.object(MAIN, 'multipart_upload',
                                      side_effect=MAIN.GalleryKeyRedirectError('refused')) as upload:
                with self.assertRaises(MAIN.GalleryKeyRedirectError):
                    watcher.upload_watched_file('http://localhost:8888/Galerie', source, {})
                upload.assert_called_once()

    def test_manual_redirect_refusal_stops_new_work_and_retains_source(self) -> None:
        """Keep ambiguous manual transfers unresolved after any redirect refusal.

        Returns:
            None: Inventory, upload and reconciliation failures stop the batch
                without confirming or retrying the retained original.
        """
        for scenario in ('inventory', 'upload'):
            with self.subTest(scenario=scenario), tempfile.TemporaryDirectory() as directory:
                source = Path(directory) / 'photo.jpg'
                source.write_bytes(b'fixture photo')
                worker = object.__new__(MAIN.ManualUploadThread)
                worker._redirect_refused = threading.Event()
                worker.stop_event = threading.Event()
                worker.progress_lock = threading.Lock()
                worker.failed = worker.completed_uploads = 0
                worker.linked_target = None
                worker.linked_state = None
                worker.config = MAIN.WatcherConfig(api_key='pgu_fixture')
                worker.paths = [source]
                worker.wait_if_paused = mock.Mock(return_value=True)
                worker.update_item = mock.Mock()
                worker.emit = mock.Mock()
                worker.remote_inventory = mock.Mock()
                worker.remote_inventory.has_hash.return_value = False
                if scenario == 'inventory':
                    worker.remote_inventory.refresh.side_effect = MAIN.GalleryKeyRedirectError('unsafe details')
                upload_error = MAIN.GalleryKeyRedirectError('unsafe details')
                with mock.patch.object(MAIN, 'multipart_upload', side_effect=upload_error) as upload:
                    worker.upload_one('http://localhost:8888/Galerie', source, 1, [], None, False)
                self.assertEqual(0 if scenario == 'inventory' else 1, upload.call_count)
                self.assertTrue(worker.stop_event.is_set())
                self.assertTrue(worker._redirect_refused.is_set())
                self.assertEqual(b'fixture photo', source.read_bytes())
                final = worker.update_item.call_args
                self.assertEqual('failed_permanent', final.args[1])
                self.assertEqual(MAIN.LEGACY_UPLOAD_UNCERTAIN_REASON, final.args[2])
                with mock.patch.object(MAIN.request, 'build_opener') as opener:
                    with self.assertRaises(MAIN.GalleryKeyRedirectError):
                        MAIN._gallery_key_open(
                            MAIN.request.Request('http://localhost:8888/Galerie'),
                            1.0, worker._redirect_checkpoint,
                        )
                    opener.return_value.open.assert_not_called()

    def test_manual_422_after_dispatch_stays_uncertain(self) -> None:
        """Never treat a post-dispatch HTTP 422 as proof of no effect.

        Returns:
            None: The persisted uncertainty blocks retry and retains the source.
        """
        with tempfile.TemporaryDirectory() as directory:
            source = Path(directory) / 'photo.jpg'
            source.write_bytes(b'fixture photo')
            worker = object.__new__(MAIN.ManualUploadThread)
            worker._redirect_refused = threading.Event()
            worker.stop_event = threading.Event()
            worker.progress_lock = threading.Lock()
            worker.failed = worker.completed_uploads = 0
            worker.linked_target = None
            worker.linked_state = None
            worker.config = MAIN.WatcherConfig(api_key='pgu_fixture')
            worker.paths = [source]
            worker.wait_if_paused = mock.Mock(return_value=True)
            worker.update_item = mock.Mock()
            worker.emit = mock.Mock()
            worker.remote_inventory = mock.Mock()
            worker.remote_inventory.refresh.return_value = False
            with mock.patch.object(MAIN, 'multipart_upload',
                                   side_effect=RuntimeError('HTTP 422: fixture')) as upload:
                worker.upload_one('https://example.test', source, 1, [], None, False)
            upload.assert_called_once()
            self.assertEqual(MAIN.LEGACY_UPLOAD_UNCERTAIN_REASON,
                             worker.update_item.call_args.args[2])
            self.assertTrue(worker.stop_event.is_set())
            self.assertTrue(source.is_file())

    def test_manual_preload_refusal_stops_before_upload(self) -> None:
        """Retain a manual batch when its first inventory redirect is unsafe.

        Returns:
            None: No upload mode is started and pending work is finalized safely.
        """
        with tempfile.TemporaryDirectory() as directory:
            source = Path(directory) / 'photo.jpg'
            source.write_bytes(b'fixture photo')
            worker = object.__new__(MAIN.ManualUploadThread)
            worker._redirect_refused = threading.Event()
            worker.stop_event = threading.Event()
            worker.linked_target = None
            worker.linked_state = None
            worker.job = None
            worker.config = MAIN.WatcherConfig(
                api_key='pgu_fixture', gallery_url='http://localhost:8888/Galerie',
            )
            worker.paths = [source]
            worker.client_thumbnails = False
            worker.uploaded = worker.skipped_existing = worker.failed = 0
            worker.emit = mock.Mock()
            worker.preload_remote_inventory = mock.Mock(
                side_effect=MAIN.GalleryKeyRedirectError('unsafe details'),
            )
            worker.run_with_server_thumbnails = mock.Mock()
            worker.mark_remaining_cancelled = mock.Mock()
            worker.finalize_job = mock.Mock()
            MAIN.ManualUploadThread._run_owned(worker)
            self.assertTrue(worker.stop_event.is_set())
            self.assertTrue(worker._redirect_refused.is_set())
            worker.run_with_server_thumbnails.assert_not_called()
            worker.mark_remaining_cancelled.assert_called_once()
            worker.finalize_job.assert_called_once()
            self.assertEqual(b'fixture photo', source.read_bytes())
            self.assertTrue(any(call.args[0] == 'error' and 'unsafe details' not in call.args[1]
                                for call in worker.emit.call_args_list))

    def test_gallery_key_redirects_are_bounded_for_all_legacy_transports(self) -> None:
        """Keep key-bearing JSON, binary and multipart redirects on safe origins.

        Returns:
            None: Synthetic redirects and route fallback never contact a server.
        """
        scenarios = {
            'same': ('http://localhost:8888', 'http://localhost:8888/next', True),
            'upgrade': ('http://localhost:8888', 'https://localhost:8888/next', True),
            'standard_upgrade': ('http://localhost', 'https://localhost/next', True),
            'foreign': ('http://localhost:8888', 'http://other.example:8888/next', False),
            'port': ('http://localhost:8888', 'http://localhost:8889/next', False),
            'downgrade': ('https://localhost:8888', 'http://localhost:8888/next', False),
            'userinfo': ('http://localhost:8888', 'http://user@localhost:8888/next', False),
            'ftp': ('http://localhost:8888', 'ftp://localhost:8888/next', False),
            'chain': ('http://localhost:8888', 'https://localhost:8888/next', False),
            'fallback': ('http://localhost:8888', 'http://other.example:8888/next', False),
        }
        for transport in ('json', 'binary', 'multipart'):
            for scenario, (origin, destination, allowed) in scenarios.items():
                with self.subTest(transport=transport, scenario=scenario), \
                        tempfile.TemporaryDirectory() as directory:
                    source = Path(directory) / 'photo.jpg'
                    source.write_bytes(b'fixture photo')
                    target = Path(directory) / 'download.bin'
                    sent: list[MAIN.request.Request] = []
                    handlers: list[MAIN._GalleryKeyRedirectHandler] = []
                    opener = mock.MagicMock()

                    def make_opener(handler: MAIN._GalleryKeyRedirectHandler) -> mock.MagicMock:
                        """Capture the real credential policy for one fake opener.

                        Args:
                            handler (MAIN._GalleryKeyRedirectHandler): Policy
                                used for subsequent synthetic redirects.

                        Returns:
                            mock.MagicMock: Transport without network access.
                        """
                        handlers.append(handler)
                        return opener

                    def open_request(http_request: MAIN.request.Request, timeout: float) -> mock.MagicMock:
                        """Simulate route fallback and follow only approved hops.

                        Args:
                            http_request (MAIN.request.Request): API request.
                            timeout (float): Unused requested socket timeout.

                        Returns:
                            mock.MagicMock: Successful synthetic API response.

                        Raises:
                            MAIN.error.HTTPError: Synthetic routing-only 404.
                            MAIN.GalleryKeyRedirectError: Unsafe redirect.
                        """
                        sent.append(http_request)
                        if scenario == 'fallback' and len(sent) == 1:
                            raise MAIN.error.HTTPError(
                                http_request.full_url, 404, 'Not Found', {},
                                io.BytesIO(b'non-JSON route error'),
                            )
                        redirected = handlers[-1].redirect_request(
                            http_request, io.BytesIO(), 302, 'Found',
                            MAIN.email.message.Message(), destination,
                        )
                        self.assertIsNotNone(redirected)
                        sent.append(redirected)
                        if scenario == 'chain':
                            handlers[-1].redirect_request(
                                redirected, io.BytesIO(), 302, 'Found',
                                MAIN.email.message.Message(),
                                'https://other.example:8888/next',
                            )
                        response = mock.MagicMock()
                        response.__enter__.return_value = response
                        response.headers.get.return_value = 'application/octet-stream'
                        response.read.side_effect = (
                            [b'binary fixture', b''] if transport == 'binary'
                            else [b'{"ok":true,"uploaded":1}']
                        )
                        return response

                    opener.open.side_effect = open_request

                    def invoke() -> None:
                        """Call the selected real API transport with fixture data.

                        Returns:
                            None: Completes only if the fake exchange succeeds.
                        """
                        if transport == 'json':
                            MAIN.post_json(origin, 'pgu_fixture', {'action': 'inventory'})
                        elif transport == 'binary':
                            MAIN.post_binary_to_file(origin, 'pgu_fixture', {'action': 'download'}, target)
                        else:
                            MAIN.multipart_upload(origin, 'pgu_fixture', source, False)

                    with mock.patch.object(MAIN.request, 'build_opener', side_effect=make_opener):
                        if allowed:
                            invoke()
                        else:
                            with self.assertRaises(MAIN.GalleryKeyRedirectError):
                                invoke()
                    self.assertEqual(2 if scenario in ('fallback', 'chain') or allowed else 1, len(sent))
                    self.assertTrue(all(
                        item.get_header('X-gallery-api-key') == 'pgu_fixture'
                        for item in sent
                    ))
                    self.assertTrue(all(
                        MAIN.parse.urlsplit(item.full_url).hostname == 'localhost'
                        for item in sent
                    ))
                    if transport == 'binary':
                        self.assertEqual(allowed, target.exists())

    """Endpoint normalization, stability, deduplication, and retry behavior."""

    def test_legacy_upload_survives_unavailable_identity_linking(self) -> None:
        """Keep a saved legacy key usable on a nonlocal HTTP server.

        Returns:
            None: Config reload and multipart upload preserve the same API key.
        """
        from uploader.app_linking import linking_issuer

        with tempfile.TemporaryDirectory() as tmp:
            folder = Path(tmp)
            photo = folder / 'photo.jpg'
            photo.write_bytes(b'legacy-image-bytes')
            store = MAIN.ConfigStore(folder / 'config.json')
            store.save(MAIN.WatcherConfig(
                gallery_url='http://old-gallery.example/Galerie',
                api_key='pgu_fixture_legacy',
            ))
            saved = store.load()
            self.assertEqual('', linking_issuer(saved.gallery_url))
            self.assertEqual('pgu_fixture_legacy', saved.api_key)

            response = mock.MagicMock()
            response.__enter__.return_value = response
            response.read.return_value = b'{"ok":true,"uploaded":1}'
            with mock.patch.object(
                MAIN, '_gallery_key_open', return_value=response,
            ) as open_url:
                result = MAIN.multipart_upload(
                    saved.gallery_url, saved.api_key, photo, False,
                )
            self.assertTrue(result['ok'])
            sent = open_url.call_args.args[0]
            self.assertEqual(
                'http://old-gallery.example/Galerie/index.php?page=upload_automation_upload',
                sent.full_url,
            )
            self.assertEqual('pgu_fixture_legacy', sent.get_header('X-gallery-api-key'))
            self.assertIn(b'legacy-image-bytes', sent.data)
            self.assertEqual('pgu_fixture_legacy', store.load().api_key)

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

    def test_multipart_upload_falls_back_from_routing_404(self) -> None:
        """Retry multipart only for an external non-JSON routing 404.

        Returns:
            None: The alternate endpoint receives the original upload.
        """
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

            original = MAIN._gallery_key_open

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

            MAIN._gallery_key_open = fake_urlopen
            try:
                result = MAIN.multipart_upload(
                    "https://example.test/index.php?page=upload_automation_upload",
                    "pgu_secret",
                    path,
                    False,
                )
            finally:
                MAIN._gallery_key_open = original

            self.assertTrue(result["ok"])
            self.assertEqual(
                [
                    "https://example.test/index.php?page=upload_automation_upload",
                    "https://example.test/api/upload",
                ],
                calls,
            )

    def test_multipart_rejects_changed_bytes_before_network(self) -> None:
        """Compare the bytes in the multipart body with the selected source.

        Returns:
            None: A changed source causes no HTTP request.
        """
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / 'photo.jpg'
            path.write_bytes(b'changed')
            digest = MAIN.hashlib.sha256(b'original').hexdigest()
            with mock.patch.object(MAIN, '_gallery_key_open') as send:
                with self.assertRaises(ValueError):
                    MAIN.multipart_upload(
                        'https://example.test/gallery', 'test-key', path, False,
                        expected_sha256=digest, expected_size=len(b'original'),
                    )
            send.assert_not_called()

    def test_bound_multipart_never_replays_routing_404(self) -> None:
        """Do not send another image POST after an uncertain routing error.

        Returns:
            None: The selected alternate route is attempted exactly once.
        """
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / 'photo.jpg'
            path.write_bytes(b'image')
            digest = MAIN.hashlib.sha256(b'image').hexdigest()
            endpoint = 'https://example.test/api/upload'
            failure = MAIN.error.HTTPError(endpoint, 404, 'Not Found', {},
                                           io.BytesIO(b'<html>not found</html>'))
            with mock.patch.object(MAIN, '_gallery_key_open', side_effect=failure) as send:
                with self.assertRaises(RuntimeError):
                    MAIN.multipart_upload(
                        'https://example.test', 'test-key', path, False,
                        expected_sha256=digest, expected_size=5,
                        single_dispatch=True, selected_endpoint=endpoint,
                    )
            self.assertEqual(1, send.call_count)
            self.assertEqual(endpoint, send.call_args.args[0].full_url)
            self.assertFalse(send.call_args.kwargs['allow_redirects'])

    def test_bound_multipart_rejects_incomplete_ack(self) -> None:
        """Reject a successful-looking legacy reply without one upload.

        Returns:
            None: Neither a missing nor a zero upload count is accepted.
        """
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / 'photo.jpg'
            path.write_bytes(b'image')
            digest = MAIN.hashlib.sha256(b'image').hexdigest()
            for payload in (b'{"ok":true}', b'{"ok":true,"uploaded":0}'):
                response = mock.MagicMock()
                response.__enter__.return_value.read.return_value = payload
                with mock.patch.object(MAIN, '_gallery_key_open', return_value=response):
                    with self.assertRaises(RuntimeError):
                        MAIN.multipart_upload(
                            'https://example.test', 'test-key', path, False,
                            expected_sha256=digest, expected_size=5,
                            single_dispatch=True,
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

    def test_zip_entry_count_limit_extracts_nothing(self) -> None:
        """Refuse an over-limit archive while retaining its empty staging owner.

        Returns:
            None: No entry is extracted and the rejected staging path survives.
        """
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp)
            archive_path = root / "many.zip"
            with zipfile.ZipFile(archive_path, "w") as archive:
                for index in range(3):
                    archive.writestr(f"{index}.jpg", b"x")
            result = stage_zip_archive(archive_path, root / "staging", "job3", {".jpg"}, self.limits(max_entries=2))
            self.assertEqual([], result.items)
            self.assertTrue(result.staging_dir)
            self.assertTrue(Path(result.staging_dir).is_dir())
            self.assertEqual([], list(Path(result.staging_dir).iterdir()))
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

    def test_malformed_zip_and_cleanup_retains_unverified_staging(self) -> None:
        """Keep unverified staging regardless of age and visible active job IDs.

        Returns:
            None: Malformed archives are refused and no aged directory is erased.
        """
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
            self.assertEqual([], removed)
            self.assertTrue(old.exists())
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


    def test_remote_confirmation_after_ambiguous_failure(self) -> None:
        """Legacy failure inventory checks current presence, not HTTP causality.

        Returns:
            None: Current presence is observed without claiming HTTP causality.
        """
        events = []
        digest = "b" * 64
        session = MAIN.RemoteInventorySession(30.0, lambda level, message: events.append((level, message)))
        original = MAIN.post_json_resolved
        MAIN.post_json_resolved = lambda url, *_args, **_kwargs: (
            {"ok": True, "action": "inventory", "checked": 1,
             "existing": [{"sha256": digest}]},
            MAIN.upload_endpoint_candidates(url)[0],
        )
        try:
            confirmed = session.confirm_after_failure("https://example.test/api/upload", "key", {"sha256": digest, "name": "a.jpg", "size": 1})
        finally:
            MAIN.post_json_resolved = original
        # This legacy inventory result establishes presence, not HTTP causality.
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

    def test_heartbeat_stop_waits_for_inflight_request_to_finish(self) -> None:
        """Signal a blocked heartbeat without pretending the request ended.

        Returns:
            None: No subsequent heartbeat is sent after the request returns.
        """
        entered = threading.Event()
        release = threading.Event()

        def request(_url: str, _key: str, _payload: dict[str, JsonValue],
                    timeout_seconds: float = MAIN.DEFAULT_TIMEOUT_SECONDS) -> dict[str, JsonValue]:
            """Hold one synthetic HTTP request until the fixture releases it.

            Args:
                _url (str): Unused fixture endpoint.
                _key (str): Unused synthetic credential.
                _payload (dict[str, JsonValue]): Unused heartbeat request fields.
                timeout_seconds (float): Unused request timeout.

            Returns:
                dict[str, JsonValue]: An empty synthetic response.
            """
            entered.set()
            if not release.wait(TEST_HEARTBEAT_WAIT_SECONDS):
                raise RuntimeError('Fixture heartbeat was not released.')
            return {}

        heartbeat = MAIN.PeriodicAIHeartbeat(
            'https://gallery.example', 'fixture-key', 7, 'fixture-claim', 60,
            lambda: (0, 'working'), lambda _level, _message: None,
        )
        # A zero fixture interval starts the controlled request without a sleep.
        heartbeat.interval = 0.0
        with mock.patch.object(MAIN, 'post_json', side_effect=request) as post:
            heartbeat.start()
            try:
                self.assertTrue(entered.wait(TEST_HEARTBEAT_WAIT_SECONDS))
                heartbeat.stop()
                self.assertTrue(heartbeat.stop_event.is_set())
                self.assertTrue(heartbeat.is_alive())
            finally:
                release.set()
                heartbeat.join(TEST_HEARTBEAT_WAIT_SECONDS)
            self.assertFalse(heartbeat.is_alive())
            post.assert_called_once()

    def test_ai_worker_retains_active_state_until_heartbeat_returns(self) -> None:
        """Keep the parent worker visible while its heartbeat request is blocked.

        Returns:
            None: Admission stays blocked until the real heartbeat thread ends.
        """
        entered = threading.Event()
        release = threading.Event()
        failed = threading.Event()
        job = {'job_id': 7, 'claim_token': 'fixture-claim', 'image': {}}
        config = MAIN.WatcherConfig(
            gallery_url='https://gallery.example', api_key='fixture-key',
            ai_worker_enabled=True,
        )
        with mock.patch.object(MAIN, 'ai_worker_id', return_value='fixture-worker'):
            worker = MAIN.AIAnalysisWorkerThread(config, MAIN.queue.Queue())
        heartbeat = MAIN.PeriodicAIHeartbeat(
            'https://gallery.example', 'fixture-key', 7, 'fixture-claim', 60,
            worker.current_progress, worker.emit,
        )
        # Start the synthetic heartbeat immediately without a timing sleep.
        heartbeat.interval = 0.0

        def request(_url: str, _key: str, payload: dict[str, JsonValue],
                    timeout_seconds: float = MAIN.DEFAULT_TIMEOUT_SECONDS,
                    before_request: MAIN.Optional[MAIN.Callable[[], None]] = None) -> dict[str, JsonValue]:
            """Supply one synthetic claim and hold its heartbeat in flight.

            Args:
                _url (str): Unused endpoint from the worker.
                _key (str): Unused synthetic credential.
                payload (dict[str, JsonValue]): Request action and job fields.
                timeout_seconds (float): Unused request timeout.
                before_request (Optional[Callable[[], None]]): Work checkpoint.

            Returns:
                dict[str, JsonValue]: Synthetic claim or empty heartbeat response.
            """
            if before_request is not None:
                before_request()
            if payload['action'] == 'ai_next_job':
                return {'job': job}
            if payload['action'] != 'ai_heartbeat':
                raise AssertionError('Unexpected AI fixture request.')
            entered.set()
            if not release.wait(TEST_HEARTBEAT_WAIT_SECONDS):
                raise RuntimeError('Fixture heartbeat was not released.')
            return {}

        def fail_download(_url: str, _key: str, _fields: dict[str, str],
                          _target: Path,
                          timeout_seconds: float = MAIN.DEFAULT_TIMEOUT_SECONDS) -> tuple[str, int]:
            """Fail the synthetic download only after its heartbeat starts.

            Args:
                _url (str): Unused fixture endpoint.
                _key (str): Unused synthetic credential.
                _fields (dict[str, str]): Unused synthetic form fields.
                _target (Path): Unused fixture output path.
                timeout_seconds (float): Unused request timeout.

            Returns:
                tuple[str, int]: This fixture always raises instead.
            """
            if not entered.wait(TEST_HEARTBEAT_WAIT_SECONDS):
                raise RuntimeError('Fixture heartbeat did not start.')
            raise OSError('Synthetic download failure.')

        def report_failure(_url: str, _key: str, _job: int, _claim: str,
                           _filename: str, _exception: Exception) -> None:
            """Stop polling after the synthetic job fails, without network I/O.

            Args:
                _url (str): Unused fixture endpoint.
                _key (str): Unused synthetic credential.
                _job (int): Unused synthetic job ID.
                _claim (str): Unused synthetic claim.
                _filename (str): Unused asset name.
                _exception (Exception): Unused synthetic failure.

            Returns:
                None: Signals the fixture and stops the parent loop.
            """
            worker.stop()
            failed.set()

        app = object.__new__(MAIN.WatcherApp)
        app.worker = app.manual_worker = app.preflight_worker = None
        app.ai_worker = worker
        app.connection_test_running = False
        app.semantic_ai_install_running = False
        app.api_key_revoke_running = False
        with mock.patch.object(MAIN, 'PeriodicAIHeartbeat', return_value=heartbeat), \
                mock.patch.object(MAIN, 'post_json', side_effect=request), \
                mock.patch.object(MAIN, 'post_binary_to_file', side_effect=fail_download), \
                mock.patch.object(worker, 'report_failure', side_effect=report_failure), \
                mock.patch.object(MAIN, 'enter_background_thread_mode'):
            worker.start()
            try:
                self.assertTrue(failed.wait(TEST_HEARTBEAT_WAIT_SECONDS))
                self.assertTrue(worker.is_alive())
                self.assertTrue(heartbeat.is_alive())
                self.assertTrue(app.background_work_active())
            finally:
                release.set()
                worker.join(TEST_HEARTBEAT_WAIT_SECONDS)
            self.assertFalse(worker.is_alive())
            self.assertFalse(heartbeat.is_alive())
            self.assertFalse(app.background_work_active())

    def test_heartbeat_refusal_stops_parent_before_completion_request(self) -> None:
        """Propagate a terminal heartbeat refusal to the parent AI request.

        Returns:
            None: An already-running download may finish but completion cannot.
        """
        worker = MAIN.AIAnalysisWorkerThread(MAIN.WatcherConfig(), MAIN.queue.Queue())
        heartbeat = MAIN.PeriodicAIHeartbeat(
            'https://gallery.example', 'fixture-key', 7, 'fixture-claim', 60,
            worker.current_progress, worker.emit,
            before_request=worker._request_checkpoint,
            terminal_event=worker._terminal_refused,
        )
        heartbeat.interval = 0.0
        actions: list[str] = []
        entered_download = threading.Event()

        def post(_url: str, _key: str, payload: dict[str, JsonValue],
                 timeout_seconds: float = MAIN.DEFAULT_TIMEOUT_SECONDS,
                 before_request: MAIN.Optional[MAIN.Callable[[], None]] = None) -> dict[str, JsonValue]:
            """Deny the heartbeat while enforcing the parent request checkpoint.

            Args:
                _url (str): Unused fixture endpoint.
                _key (str): Unused fixture key.
                payload (dict[str, JsonValue]): AI action and its request fields.
                timeout_seconds (float): Unused request timeout.
                before_request (Optional[Callable[[], None]]): Admission check.

            Returns:
                dict[str, JsonValue]: Empty synthetic response if admitted.

            Raises:
                MAIN.GalleryKeyRedirectError: Terminal synthetic heartbeat refusal.
            """
            if before_request is not None:
                before_request()
            action = str(payload['action'])
            actions.append(action)
            if action == 'ai_heartbeat':
                if not entered_download.wait(TEST_HEARTBEAT_WAIT_SECONDS):
                    raise RuntimeError('Fixture download did not start.')
                raise MAIN.GalleryKeyRedirectError('fixture refusal')
            return {}

        def download(_url: str, _key: str, _fields: dict[str, str], path: Path,
                     timeout_seconds: float = MAIN.DEFAULT_TIMEOUT_SECONDS,
                     before_request: MAIN.Optional[MAIN.Callable[[], None]] = None) -> tuple[str, int]:
            """Let one admitted synthetic download finish after the refusal.

            Args:
                _url (str): Unused fixture endpoint.
                _key (str): Unused fixture key.
                _fields (dict[str, str]): Unused AI asset request fields.
                path (Path): Owned fixture output path.
                timeout_seconds (float): Unused request timeout.
                before_request (Optional[Callable[[], None]]): Admission check.

            Returns:
                tuple[str, int]: Synthetic content type and byte length.
            """
            if before_request is not None:
                before_request()
            entered_download.set()
            self.assertTrue(worker._terminal_refused.wait(TEST_HEARTBEAT_WAIT_SECONDS))
            path.write_bytes(b'fixture')
            return 'image/jpeg', len(b'fixture')

        analyzer = mock.Mock()
        analyzer.analyze.return_value = ({}, '')
        with mock.patch.object(MAIN, 'PeriodicAIHeartbeat', return_value=heartbeat), \
                mock.patch.object(MAIN, 'post_json', side_effect=post), \
                mock.patch.object(MAIN, 'post_binary_to_file', side_effect=download), \
                mock.patch.object(MAIN, 'AIImageAnalyzer', return_value=analyzer), \
                mock.patch.object(worker, 'report_failure') as failure:
            with self.assertRaises(MAIN.GalleryKeyRedirectError):
                worker.process_job('https://gallery.example', 'fixture-key',
                                   {'job_id': 7, 'claim_token': 'fixture-claim'}, 60)
        self.assertEqual(['ai_heartbeat'], actions)
        self.assertTrue(worker._terminal_refused.is_set())
        self.assertFalse(heartbeat.is_alive())
        failure.assert_not_called()

    def test_ai_heartbeat_start_failure_cleans_its_temp_directory(self) -> None:
        """Do not join an unstarted heartbeat or retain the owned temporary data.

        Returns:
            None: Failure is reported and the fixture directory is removed.
        """
        with mock.patch.object(MAIN, 'ai_worker_id', return_value='fixture-worker'):
            worker = MAIN.AIAnalysisWorkerThread(MAIN.WatcherConfig(), MAIN.queue.Queue())
        heartbeat = mock.Mock()
        heartbeat.start.side_effect = RuntimeError('Synthetic start failure.')
        with tempfile.TemporaryDirectory() as directory:
            owned = Path(directory) / 'job'
            owned.mkdir()
            with mock.patch.object(MAIN, 'PeriodicAIHeartbeat', return_value=heartbeat), \
                    mock.patch.object(MAIN.tempfile, 'mkdtemp', return_value=str(owned)), \
                    mock.patch.object(worker, 'report_failure') as report:
                worker.process_job('https://gallery.example', 'fixture-key',
                                   {'job_id': 7, 'claim_token': 'fixture-claim'}, 60)
            heartbeat.stop.assert_called_once()
            heartbeat.join.assert_not_called()
            report.assert_called_once()
            self.assertFalse(owned.exists())

    def test_ai_asset_path_failure_cleans_before_heartbeat_starts(self) -> None:
        """Clean an owned temp directory when choosing the asset path fails.

        Returns:
            None: No heartbeat is started or joined and the directory is removed.
        """
        with mock.patch.object(MAIN, 'ai_worker_id', return_value='fixture-worker'):
            worker = MAIN.AIAnalysisWorkerThread(MAIN.WatcherConfig(), MAIN.queue.Queue())
        heartbeat = mock.Mock()
        with tempfile.TemporaryDirectory() as directory:
            owned = Path(directory) / 'job'
            owned.mkdir()
            with mock.patch.object(MAIN, 'PeriodicAIHeartbeat', return_value=heartbeat), \
                    mock.patch.object(MAIN.tempfile, 'mkdtemp', return_value=str(owned)), \
                    mock.patch.object(MAIN, 'extension_for_content_type',
                                      side_effect=ValueError('Synthetic path failure.')), \
                    mock.patch.object(worker, 'report_failure') as report:
                worker.process_job('https://gallery.example', 'fixture-key',
                                   {'job_id': 7, 'claim_token': 'fixture-claim'}, 60)
            heartbeat.start.assert_not_called()
            heartbeat.stop.assert_called_once()
            heartbeat.join.assert_not_called()
            report.assert_called_once()
            self.assertFalse(owned.exists())

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

    def test_revoke_upload_key_uses_current_key_and_revoke_action(self) -> None:
        """Send the saved key and revoke action to the canonical endpoint.

        Returns:
            None: The synthetic response confirms the request fields.
        """
        captured = {}

        class FakeResponse:
            def __enter__(self):
                return self

            def __exit__(self, _exc_type, _exc, _tb):
                return False

            def read(self):
                return b'{"ok":true,"action":"revoke","message":"API key revoked."}'

        original = MAIN._gallery_key_open

        def fake_urlopen(http_request, timeout):
            captured["url"] = http_request.full_url
            captured["body"] = json.loads(http_request.data.decode("utf-8"))
            captured["api_key"] = http_request.get_header("X-gallery-api-key")
            captured["timeout"] = timeout
            return FakeResponse()

        MAIN._gallery_key_open = fake_urlopen
        try:
            result = MAIN.revoke_upload_key("https://example.test/api/upload", "pgu_secret")
        finally:
            MAIN._gallery_key_open = original

        self.assertTrue(result["ok"])
        self.assertEqual("https://example.test/index.php?page=upload_automation_upload", captured["url"])
        self.assertEqual({"action": "revoke"}, captured["body"])
        self.assertEqual("pgu_secret", captured["api_key"])
        self.assertGreater(captured["timeout"], 0)

    def test_revoke_falls_back_from_non_json_index_404_to_clean_route(self) -> None:
        """Retry revocation on the alternate route after a non-JSON 404.

        Returns:
            None: Both attempted endpoints are checked in their expected order.
        """
        calls = []

        class FakeResponse:
            def __enter__(self):
                return self

            def __exit__(self, _exc_type, _exc, _tb):
                return False

            def read(self):
                return b'{"ok":true,"action":"revoke","message":"API key revoked."}'

        original = MAIN._gallery_key_open

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

        MAIN._gallery_key_open = fake_urlopen
        try:
            result = MAIN.revoke_upload_key(
                "https://example.test/index.php?page=upload_automation_upload",
                "pgu_secret",
            )
        finally:
            MAIN._gallery_key_open = original

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


    def test_remote_inventory_is_database_only_until_failure_reconciliation(self) -> None:
        """Use the actual resolved metadata transport for both legacy probes.

        Returns:
            None: Routine inventory stays shallow; uncertain recovery checks deeply.
        """
        payloads = []
        original_post_json_resolved = MAIN.post_json_resolved

        def fake_post_json_resolved(
            upload_url: str, api_key: str, payload: dict[str, object],
            timeout_seconds: float = MAIN.DEFAULT_TIMEOUT_SECONDS,
        ) -> tuple[dict[str, object], str]:
            """Return a valid action and same-origin endpoint for the inventory.

            Args:
                upload_url (str): Legacy Gallery API address being queried.
                api_key (str): Mocked legacy key, never used over a network.
                payload (dict[str, object]): Exact action and deep-check request.
                timeout_seconds (float): Captured bounded request timeout.

            Returns:
                tuple[dict[str, object], str]: Inventory payload and the accepted resolved endpoint.
            """
            payloads.append(payload)
            return (
                {"ok": True, "action": "inventory", "existing": [],
                 "checked": len(payload.get("files", []))},
                MAIN.upload_endpoint_candidates(upload_url)[0],
            )

        MAIN.post_json_resolved = fake_post_json_resolved
        try:
            session = MAIN.RemoteInventorySession(1, lambda _level, _message: None)
            candidate = {"client_id": "x", "filename": "x.jpg", "size": 123, "sha256": "a" * 64}
            self.assertTrue(session.refresh("https://example.test/api/upload", "pgu_secret", [candidate], force=True))
            session.confirm_after_failure("https://example.test/api/upload", "pgu_secret", candidate)
        finally:
            MAIN.post_json_resolved = original_post_json_resolved

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


    def test_watcher_uses_local_thumbnails_to_avoid_server_batch_generation(self) -> None:
        """Send locally generated watcher thumbnails with the legacy upload.

        Returns:
            None: Verifies thumbnail mapping and server generation settings.
        """
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
            watcher._once_work = None
            watcher.linked_target = None
            watcher._linked_lease = None
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

    def test_gallery_json_404_does_not_trigger_route_fallback(self) -> None:
        """Keep a gallery-generated JSON 404 authoritative without retrying.

        Returns:
            None: Only the original endpoint receives the inventory request.
        """
        calls = []
        original = MAIN._gallery_key_open

        def fake_urlopen(http_request, timeout):
            calls.append(http_request.full_url)
            raise MAIN.error.HTTPError(
                http_request.full_url,
                404,
                "Not Found",
                {"Content-Type": "application/json"},
                io.BytesIO(b'{"ok":false,"error":"The gallery assigned to this API key no longer exists."}'),
            )

        MAIN._gallery_key_open = fake_urlopen
        try:
            with self.assertRaisesRegex(RuntimeError, "gallery assigned"):
                MAIN.post_json(
                    "https://example.test/index.php?page=upload_automation_upload",
                    "pgu_secret",
                    {"action": "inventory", "files": []},
                )
        finally:
            MAIN._gallery_key_open = original

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
        # Notebook indices are zero-based and start with the Photos workspace.
        app.tab_indices = {
            "photos": 0,
            "watch": 1,
            "import": 2,
            "activity": 3,
            "ai": 4,
            "settings": 5,
        }

        MAIN.WatcherApp.select_tab(app, "photos")
        MAIN.WatcherApp.select_tab(app, "activity")
        MAIN.WatcherApp.select_tab(app, "missing")

        self.assertEqual(
            [mock.call(0), mock.call(3)],
            app.notebook.select.call_args_list,
        )


class FlightDeliveryCoordinationTests(unittest.TestCase):
    """Preserve accepted import and connection state during finalization."""

    def test_abandon_action_respects_update_gate_and_needs_no_connection_test(self) -> None:
        """Make offline abandonment available for an untested saved key.

        Returns:
            None: A saved key opens abandonment directly; update ownership or
                a missing key prevents another lifecycle action.
        """
        app = mock.Mock()
        app.updates.blocked.return_value = False
        app.config.api_key = "revoked-key"
        app.linking_manager.linked_upload_mode_selected.return_value = False
        MAIN.WatcherApp.abandon_flight(app)
        app.flights.abandon.assert_called_once_with()
        app.test_connection.assert_not_called()
        app.flights.abandon.reset_mock()
        app.updates.blocked.return_value = True
        MAIN.WatcherApp.abandon_flight(app)
        app.updates.blocked.return_value = False
        app.config.api_key = ""
        MAIN.WatcherApp.abandon_flight(app)
        app.flights.abandon.assert_not_called()

    def test_forget_connection_persists_before_clearing_key_and_preserves_settings(self) -> None:
        """Keep the saved key on failure and remove only the confirmed credential.

        Returns:
            None: A mismatched connection and disk failure leave memory/UI
                untouched; success saves an empty key with other options intact.
        """
        app = mock.Mock()
        original = MAIN.WatcherConfig(
            gallery_url="https://deleted.test/", api_key="revoked-key",
            watched_folder="retained-folder", watch_recursive=True,
        )
        app.config = original
        app.flights.binding.return_value = "captured-binding"
        # Execute the real host save through the mocked mode owner; otherwise
        # a persistence error is not exercised by this uninitialized app.
        app.mode_owner.save.side_effect = lambda save, **_kwargs: save()
        app._save_owned_config.side_effect = (
            lambda config: MAIN.WatcherApp._save_owned_config(app, config)
        )
        with self.assertRaises(ValueError):
            MAIN.WatcherApp.forget_flight_connection(app, "another-binding")
        app.config_store.save.assert_not_called()
        app.config_store.save.side_effect = OSError("disk full")
        with self.assertRaises(OSError):
            MAIN.WatcherApp.forget_flight_connection(app, "captured-binding")
        self.assertIs(original, app.config)
        self.assertEqual("revoked-key", original.api_key)
        app.api_key_var.set.assert_not_called()
        app.config_store.save.side_effect = None
        MAIN.WatcherApp.forget_flight_connection(app, "captured-binding")
        self.assertEqual("", app.config.api_key)
        self.assertEqual("retained-folder", app.config.watched_folder)
        self.assertTrue(app.config.watch_recursive)
        self.assertEqual("https://deleted.test/", app.config.gallery_url)
        app.api_key_var.set.assert_called_once_with("")
        app.flights.revoked.assert_not_called()

    def test_abandon_event_routes_only_the_captured_binding_to_local_cleanup(self) -> None:
        """Deliver worker abandonment to Tk without asserting server revocation.

        Returns:
            None: The structured event invokes the local acknowledgement and
                never enters the remote-revocation completion branch.
        """
        app = mock.Mock()
        app.exiting = False
        app.events = MAIN.queue.Queue()
        app.events.put({"kind": "flight_abandoned",
                        "payload": {"binding": "captured-binding"}})
        MAIN.WatcherApp.drain_events(app)
        app.flights.forgotten.assert_called_once_with("captured-binding")
        app.flights.revoked.assert_not_called()
        app.config_store.save.assert_not_called()

    def test_begin_flight_opens_connection_setup_when_no_key_is_saved(self) -> None:
        """Keep connection setup separate from active and recoverable uploads.

        Returns:
            None: Begin is available for unconfigured key mode. A linked
                capture finishes or opens progress and recovery without a key.
        """
        app = mock.Mock()
        app.flight_action_state = MAIN.WatcherApp.flight_action_state.__get__(app)
        app.updates.blocked.return_value = False
        app.config.api_key = ""
        app.manual_worker = None
        app.flights.session_state.return_value = "idle"
        app.linking_manager.selected_upload_target.return_value = None
        app.linking_manager.linked_upload_mode_selected.return_value = False
        app.linking_manager.linked_address.get.return_value = ""
        MAIN.WatcherApp.flight_action(app)
        app.open_connection_dialog.assert_called_once_with()
        app.revoke_api_key.assert_not_called()

        app.linking_manager.linked_upload_mode_selected.return_value = True
        app.linking_manager.selected_upload_target.return_value = mock.Mock()
        self.assertEqual("idle", app.flight_action_state())
        app.flights.session_state.return_value = "active"
        MAIN.WatcherApp.flight_action(app)
        app.flights.finish_report.assert_called_once_with()
        app.flights.session_state.return_value = "working"
        MAIN.WatcherApp.flight_action(app)
        app.flights.show_progress.assert_called_once_with()
        app.flights.session_state.return_value = "pending"
        MAIN.WatcherApp.flight_action(app)
        app.flights.show_recovery.assert_called_once_with()
        app.open_connection_dialog.assert_called_once_with()

    def test_action_wording_tracks_simulator_and_saved_key_in_all_languages(self) -> None:
        """Keep Watcher and Import actions identical across presence changes.

        Returns:
            None: Both controls show localized game or flight Begin/Finish
                and Abandon wording while retaining their enabled state and
                saved key. SimBrief widgets follow flight detection reversibly.
        """
        from uploader.game_context import GameContext

        expected = {
            "en": (
                "Begin game…", "Finish game…", "Begin flight…", "Finish flight…",
            ),
            "cs": (
                "Zahájit hru…", "Dokončit hru…", "Zahájit let…", "Dokončit let…",
            ),
            "de": (
                "Spiel beginnen…", "Spiel beenden…",
                "Flug beginnen…", "Flug abschließen…",
            ),
            "sv": (
                "Börja spel…", "Avsluta spel…",
                "Börja flygning…", "Slutför flygning…",
            ),
        }
        for language, labels in expected.items():
            app = mock.Mock()
            app.config = MAIN.WatcherConfig(gallery_url="https://gallery.test/")
            app.gallery_url_var.get.return_value = app.config.gallery_url
            app.api_key_revoke_running = False
            app.api_key_request_blockers.return_value = []
            app.flights.ui_language.get.return_value = language
            app.simulator_presence = MAIN.GamePresence(lambda: "", WINAPP_DIR)
            app.flights.text.side_effect = lambda key: app.simulator_presence.text(
                key, language
            )
            app.flight_action_state = MAIN.WatcherApp.flight_action_state.__get__(app)
            app.manual_worker = None
            app.linking_manager.selected_upload_target.return_value = None
            app.linking_manager.linked_upload_mode_selected.return_value = False
            app.linking_manager.linked_address.get.return_value = ""
            # For each presence context, check setup and live capture wording.
            states = ((False, ""), (False, "saved"), (True, ""), (True, "saved"))
            for (available, key), label in zip(states, labels):
                with self.subTest(language=language, available=available, key=key):
                    app.simulator_presence.available = available
                    app.simulator_presence.context = (
                        GameContext("simconnect", "Flight Simulator", "flight")
                        if available else None
                    )
                    app.config.api_key = key
                    app.flights.session_state.return_value = "active" if key else "idle"
                    app.api_key_var.get.return_value = key
                    simbrief_widgets = (
                        app.simbrief_button, app.simbrief_settings_button,
                        app.simbrief_status_label,
                    )
                    for widget in simbrief_widgets:
                        widget.reset_mock()
                    MAIN.WatcherApp.refresh_connection_controls(app)
                    for button in (app.finish_flight_button, app.import_revoke_button):
                        button.configure.assert_called_with(text=label)
                        button.state.assert_called_with(["!disabled"])
                    for button in (app.abandon_flight_button, app.import_abandon_button):
                        if key:
                            button.configure.assert_called_with(
                                text=app.simulator_presence.text("abandon", language)
                            )
                            button.state.assert_called_with(["!disabled"])
                        else:
                            button.grid_remove.assert_called()
                    for widget in simbrief_widgets:
                        if available:
                            widget.grid.assert_called_once_with()
                            widget.grid_remove.assert_not_called()
                        else:
                            widget.grid_remove.assert_called_once_with()
                            widget.grid.assert_not_called()
                    self.assertEqual(key, app.config.api_key)

    def test_finish_flight_is_below_gallery_and_title_can_shrink(self) -> None:
        """Keep the finish action visible independently of gallery title length.

        Returns:
            None: Independent widget spies verify the action container below
                the title and recording state, Finish beside SimBrief within
                it, the expanding identity's source/title bindings, and the
                offline Abandon action. No Tk root is built.
        """
        app = mock.Mock()
        app.flights.text.return_value = "Finish flight…"
        widgets = mock.Mock()
        # Distinct widgets preserve the real parent/child grid hierarchy.
        widgets.Frame.side_effect = lambda *_args, **_kwargs: mock.Mock()
        widgets.Button.side_effect = lambda *_args, **_kwargs: mock.Mock()
        widgets.Label.side_effect = lambda *_args, **_kwargs: mock.Mock()
        title_widget = mock.Mock()
        with (
            mock.patch.object(MAIN, "ttk", widgets),
            mock.patch.object(
                MAIN, "GalleryIdentityLabel", return_value=title_widget,
            ) as title_factory,
            mock.patch.object(MAIN, "GlassCard", side_effect=lambda *_args, **_kwargs: mock.Mock()),
            mock.patch.object(MAIN, "icon_button"),
            mock.patch.object(MAIN, "help_button"),
        ):
            MAIN.WatcherApp.build_watch_tab(app, mock.Mock())
        buttons = {call.kwargs.get("command"): call
                   for call in widgets.Button.call_args_list}
        finish = buttons[app.flight_action]
        self.assertEqual("Finish flight…", finish.kwargs["text"])
        self.assertEqual(app.flight_action, finish.kwargs["command"])
        title = title_factory.call_args
        self.assertIs(app.gallery_label_var, title.args[1])
        self.assertIs(app.gallery_title_var, title.args[2])
        self.assertNotIn(app.choose_linked_gallery, buttons)
        title_widget.grid.assert_called_once_with(
            row=0, column=0, sticky="ew",
        )
        actions = finish.args[0]
        self.assertIsNot(actions, title.args[0])
        title.args[0].columnconfigure.assert_called_once_with(0, weight=1)
        title.args[0].grid.assert_called_once_with(row=0, column=0, sticky="ew")
        actions.grid.assert_called_once_with(
            row=1, column=0, columnspan=2, sticky="w", pady=(6, 0),
        )
        capture = next(
            call for call in widgets.Label.call_args_list
            if call.kwargs.get("style") == "Watcher.Active.TLabel"
        )
        self.assertEqual("● REC", capture.kwargs["text"])
        capture.args[0].grid.assert_called_once_with(
            row=0, column=1, sticky="e", padx=(16, 0),
        )
        app.watcher_capture_label.grid.assert_called_once_with(
            row=0, column=1, padx=(8, 0),
        )
        app.watcher_capture_label.grid_remove.assert_called_once_with()
        app.finish_flight_button.grid.assert_called_once_with(row=0, column=0, sticky="w")
        simbrief = buttons[app.flights.toggle_simbrief]
        self.assertIs(actions, simbrief.args[0])
        self.assertEqual(app.flights.toggle_simbrief, simbrief.kwargs["command"])
        abandon = buttons[app.abandon_flight]
        self.assertIs(actions, abandon.args[0])
        self.assertEqual(app.abandon_flight, abandon.kwargs["command"])
        app.abandon_flight_button.grid.assert_called_once_with(
            row=0, column=3, padx=(6, 0),
        )

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
                worker._once_work = None
                worker.linked_target = None
                worker._linked_lease = None
                worker.state = mock.Mock()
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

    def test_changed_preflight_binding_retains_zip_before_job_start(self) -> None:
        """Never apply gallery A's preflight to gallery B or delete its ZIP.

        Returns:
            None: A stale unstarted job is refused before binding or upload.
        """
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            archive = root / 'source.zip'
            archive.write_bytes(b'synthetic archive')
            staging = root / 'stage'
            staging.mkdir()
            source = staging / 'photo.jpg'
            source.write_bytes(b'synthetic image')
            job = MAIN.ImportJob.create('zip', str(archive), 'Gallery A')
            job.staging_dir, job.source_zip = str(staging), str(archive)
            item = MAIN.ImportItem.create(source, 'photo.jpg', from_archive=True)
            item.transition('queued')
            job.items = [item]
            old_generation, new_generation = object(), object()
            app = object.__new__(MAIN.WatcherApp)
            app.api_key_revoke_running = False
            app.linking_manager = mock.Mock()
            app.linking_manager.linked_upload_mode_selected.return_value = False
            app.manual_local_thumbnails_var = mock.Mock()
            app.manual_local_thumbnails_var.get.return_value = False
            app.flights = mock.Mock()
            app.flights.binding.return_value = 'b' * 64
            app.flights.import_bindings.entries.return_value = {}
            app._preflight_approved = (job.id, old_generation, 'a' * 64)
            app.job_store = mock.Mock()
            config = MAIN.WatcherConfig(_snapshot_token=new_generation)
            with mock.patch.object(MAIN.messagebox, 'showerror') as error, \
                    mock.patch.object(MAIN, 'ManualUploadThread') as worker:
                MAIN.WatcherApp.launch_manual_job(app, job, [source], config)
            error.assert_called_once()
            app.flights.import_bindings.bind.assert_not_called()
            app.job_store.save_job.assert_not_called()
            worker.assert_not_called()
            self.assertEqual(b'synthetic archive', archive.read_bytes())
            self.assertEqual(b'synthetic image', source.read_bytes())

    def test_legacy_duplicate_or_unproven_zip_is_not_removed(self) -> None:
        """Keep old ZIPs even if a legacy job claims all entries succeeded.

        Returns:
            None: Both confirmed-only and duplicate jobs retain their source.
        """
        for state in ('confirmed', 'skipped_duplicate'):
            with self.subTest(state=state), tempfile.TemporaryDirectory() as directory:
                root = Path(directory)
                archive = root / 'source.zip'
                archive.write_bytes(b'synthetic archive')
                staging = root / 'stage'
                staging.mkdir()
                source = staging / 'photo.jpg'
                source.write_bytes(b'synthetic image')
                job = MAIN.ImportJob.create('zip', str(archive), 'Gallery')
                job.staging_dir, job.source_zip = str(staging), str(archive)
                job.delete_source_zip_after_success = True
                item = MAIN.ImportItem.create(source, 'photo.jpg', from_archive=True)
                item.transition(state)
                job.items = [item]
                worker = MAIN.ManualUploadThread(
                    MAIN.WatcherConfig(), [], False, 1, 1, MAIN.queue.Queue(),
                    job, MAIN.JobStateStore(root / 'jobs.json'),
                )
                state_path = root / 'jobs.json'
                existing = default_upload_state()
                existing['jobs'] = {job.id: job.to_dict()}
                atomic_write_json(state_path, existing)
                worker.finalize_job()
                self.assertEqual(b'synthetic archive', archive.read_bytes())
                if state == 'skipped_duplicate':
                    self.assertTrue(source.exists())

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
        generation = object()
        app.events.put({
            "kind": "connection_ready",
            "payload": {
                "gallery_label": "parent/006",
                "gallery_title": "Renamed gallery / chapter",
                "normalized_url": resolved_url,
                "explicit_test": True,
                "config_generation": generation,
            },
        })
        app.config = types.SimpleNamespace(gallery_url=saved_url, api_key=api_key,
                                           _snapshot_token=generation)
        app.config_store = mock.Mock()
        app.gallery_label_var = mock.Mock()
        app.gallery_title_var = mock.Mock()
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
        app.gallery_label_var.set.assert_called_once_with("Target gallery: parent/006")
        app.gallery_title_var.set.assert_called_once_with("Renamed gallery / chapter")
        app.root.after.assert_called_once()

    def test_stale_connection_event_does_not_replace_current_gallery(self) -> None:
        """Discard metadata from a previously captured config generation.

        Returns:
            None: A stale event neither changes the label nor retains busy UI.
        """
        app = object.__new__(MAIN.WatcherApp)
        app.exiting = False
        app.connection_test_running = True
        app.events = MAIN.queue.Queue()
        app.events.put({'kind': 'connection_ready', 'payload': {
            'gallery_label': 'Old gallery', 'explicit_test': True,
            'config_generation': object(),
        }})
        app.config = types.SimpleNamespace(_snapshot_token=object())
        app.refresh_connection_controls = mock.Mock()
        app.gallery_label_var = mock.Mock()
        app.gallery_title_var = mock.Mock()
        app.refresh_revoke_button_state = mock.Mock()
        app.refresh_activity_monitor_state = mock.Mock()
        app.utc_sync = mock.Mock()
        app.utc_sync_status_var = mock.Mock()
        app.root = mock.Mock()
        MAIN.WatcherApp.drain_events(app)
        app.gallery_label_var.set.assert_not_called()
        app.gallery_title_var.set.assert_not_called()
        self.assertFalse(app.connection_test_running)
        app.refresh_connection_controls.assert_called_once()

    def test_stale_preflight_event_retains_sources_and_does_not_publish_plan(self) -> None:
        """Do not publish gallery A's finished preflight after switching to B.

        Returns:
            None: The original ZIP and staging survive the discarded event.
        """
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            archive = root / 'source.zip'
            archive.write_bytes(b'archive')
            staged = root / 'staged.jpg'
            staged.write_bytes(b'image')
            app = object.__new__(MAIN.WatcherApp)
            app.exiting = False
            app.events = MAIN.queue.Queue()
            ticket, old_generation, current_generation = object(), object(), object()
            app.preflight_worker = types.SimpleNamespace(_preflight_owner=ticket)
            app.config = types.SimpleNamespace(_snapshot_token=current_generation)
            app.events.put({'kind': 'preflight_complete', 'payload': {
                'preflight_owner': ticket, 'config_generation': old_generation,
                'preflight_binding': 'a' * 64,
            }})
            app._preflight_approved = ('old-job', old_generation, 'a' * 64)
            app.manual_plan = None
            app.preflight_progress = mock.Mock()
            app.manual_status_var = mock.Mock()
            app.manual_preflight_var = mock.Mock()
            app.refresh_revoke_button_state = mock.Mock()
            app.refresh_activity_monitor_state = mock.Mock()
            app.utc_sync = mock.Mock()
            app.utc_sync_status_var = mock.Mock()
            app.root = mock.Mock()
            MAIN.WatcherApp.drain_events(app)
            self.assertIsNone(app._preflight_approved)
            self.assertIsNone(app.manual_plan)
            self.assertIsNone(app.preflight_worker)
            self.assertEqual(b'archive', archive.read_bytes())
            self.assertEqual(b'image', staged.read_bytes())

    def test_unsaved_connection_is_rejected_before_mode_admission(self) -> None:
        """Keep unsaved credentials out of the authoritative mode owner.

        Returns:
            None: Connection testing asks for Save and starts no worker.
        """
        app = mock.Mock()
        app.updates = None
        app.linking_manager.linked_upload_mode_selected.return_value = False
        app.api_key_revoke_running = False
        app.connection_test_running = False
        app.gallery_url_var.get.return_value = 'https://example.test/gallery'
        app.api_key_var.get.return_value = 'edited-key'
        app.current_config.return_value.to_dict.return_value = {'api_key': 'edited-key'}
        app.config.to_dict.return_value = {'api_key': 'saved-key'}
        with mock.patch.object(MAIN.messagebox, 'showerror') as error, \
                mock.patch.object(MAIN.threading, 'Thread') as worker:
            MAIN.WatcherApp.test_connection(app)
        error.assert_called_once()
        app.config_store.mode_port.assert_not_called()
        worker.assert_not_called()

    def test_source_deletion_keeps_replaced_photo_after_ack(self) -> None:
        """Keep the current path even when an earlier upload was acknowledged.

        Returns:
            None: A replacement photo survives and the user receives a warning.
        """
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / 'photo.jpg'
            path.write_bytes(b'replacement')
            worker = mock.Mock()
            MAIN.WatcherThread.delete_uploaded_file(worker, path, {
                'ok': True, 'uploaded': 1,
            })
            self.assertEqual(b'replacement', path.read_bytes())
            worker.emit.assert_called_once()

    def test_replaced_source_remains_in_watcher_drain_after_upload(self) -> None:
        """Do not acknowledge a path replaced while its upload was in flight.

        Returns:
            None: The replacement and its accepted drain entry remain pending.
        """
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            source = root / 'photo.jpg'
            source.write_bytes(b'original')
            worker = object.__new__(MAIN.WatcherThread)
            worker.config = MAIN.WatcherConfig(api_key='test-key')
            worker._once_work = None
            worker.linked_target = None
            worker.drain_only = True
            worker.accepted_paths = {source}
            worker.initial_paths = set()
            worker._admission_lock = threading.RLock()
            worker.drain_event = threading.Event()
            worker.stop_event = threading.Event()
            worker.stability = mock.Mock()
            worker.stability.stable.return_value = True
            worker.persist_drain = mock.Mock()
            worker.remote_inventory = mock.Mock()
            worker.remote_inventory.refresh.return_value = False
            worker.remote_skipped_paths = set()
            worker.emit = mock.Mock()
            worker.sim_camera_metadata_fields = mock.Mock(return_value={})

            def replace_source(
                upload_url: str, path: Path, metadata: dict[str, str],
                digest: str, size: int,
            ) -> dict[str, MAIN.JsonValue]:
                """Replace the photo before returning the earlier upload ACK.

                Args:
                    upload_url (str): Captured upload URL.
                    path (Path): Source path to replace.
                    metadata (dict[str, str]): Submitted metadata fields.
                    digest (str): Digest of the original bytes.
                    size (int): Length of the original bytes.

                Returns:
                    dict[str, MAIN.JsonValue]: Synthetic successful ACK fields.
                """
                replacement = root / 'replacement.jpg'
                replacement.write_bytes(b'replacement')
                replacement.replace(path)
                return {'ok': True, 'uploaded': 1}

            worker.upload_watched_file = replace_source
            worker.scan_once(root, 'https://example.test/gallery')
            self.assertIn(source, worker.accepted_paths)
            self.assertTrue(worker.stop_event.is_set())
            self.assertEqual(b'replacement', source.read_bytes())

class WatcherActionTests(unittest.TestCase):
    """Keep the primary watcher action aligned with worker state."""

    def test_contextual_watcher_finish_latches_a_departed_simulator(self) -> None:
        """Use flight Finish only when this capture observed a simulator.

        Returns:
            None: Explicit game Finish and Stop both suppress report rendering.
        """
        app = mock.Mock()
        app._contextual_watch_target = MAIN.LinkedUploadTarget(
            mock.Mock(), "https://gallery.example", 7, 9, "Screenshots",
            "synthetic watcher key",
        )
        app._contextual_watch_is_flight = False
        app.updates.blocked.return_value = False
        app.flight_action_state.return_value = "finish"
        app.contextual_watcher_is_flight = (
            MAIN.WatcherApp.contextual_watcher_is_flight.__get__(app)
        )
        app.simbrief_available.return_value = False
        app.flights.recorder.diagnostics_snapshot.return_value = {"sample_count": 0}
        MAIN.WatcherApp.flight_action(app)
        app.flights.begin.assert_called_once_with(False, automatic=True, photos_only=True)
        app.flights.begin.reset_mock()
        MAIN.WatcherApp.stop(app)
        app.flights.begin.assert_called_once_with(False, photos_only=True)
        app.flights.finish_report.assert_not_called()

        app.simbrief_available.return_value = True
        self.assertTrue(app.contextual_watcher_is_flight())
        app.simbrief_available.return_value = False
        MAIN.WatcherApp.flight_action(app)
        app.flights.finish_report.assert_called_once_with()

    def test_contextual_watcher_closes_only_after_the_owned_queue_finishes(self) -> None:
        """Retain the issued key while the shared flight workflow still owns work.

        Returns:
            None: Idle cleanup revokes the exact gallery key off Tk.
        """
        app = mock.Mock()
        app.events = MAIN.queue.Queue()
        app._watcher_key_closing = False
        session = mock.Mock()
        key = "synthetic watcher key"
        target = MAIN.LinkedUploadTarget(
            session, "https://gallery.example", 7, 9, "Screenshots", key,
        )
        app._contextual_watch_target = target
        with mock.patch.object(MAIN.threading, "Thread") as thread:
            for state in ("active", "working", "pending", "blocked"):
                app.flights.session_state.return_value = state
                MAIN.WatcherApp.release_contextual_watcher(app)
            thread.assert_not_called()
            app.flights.session_state.return_value = "idle"
            MAIN.WatcherApp.release_contextual_watcher(app)
            self.assertTrue(app._watcher_key_closing)
            thread.call_args.kwargs["target"]()
        session.close_watch_key.assert_called_once_with(target.gallery_id, key)
        self.assertEqual({
            "kind": "photos_watch_key_closed",
            "payload": {"target": target, "closed": True},
        }, app.events.get_nowait())

    def test_primary_action_starts_when_the_worker_is_stopped(self) -> None:
        """Start the ordinary Watcher even while a Photos gallery is selected.

        Returns:
            None: The ordinary action uses its saved destination path.
        """
        app = mock.Mock()
        app.worker = None
        app.flights.session_state.return_value = "idle"
        app.linking_manager.selected_upload_target.return_value = None
        app.photos_workspace.selected_watcher_gallery.return_value = (
            mock.Mock(), mock.Mock(),
        )

        MAIN.WatcherApp.toggle_watcher(app)

        app.start.assert_called_once_with()
        app.stop.assert_not_called()
        app.open_gallery_watcher.assert_not_called()

    def test_primary_action_stops_an_active_worker(self) -> None:
        """Stop the watcher when its worker is already active.

        Returns:
            None: Confirms the contextual action uses the existing stop path.
        """
        app = mock.Mock()
        app.worker.is_alive.return_value = True
        app.flights.session_state.return_value = "active"

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

    def test_start_without_folder_explains_what_to_choose(self) -> None:
        """Handle the empty watch folder before any upload admission.

        Returns:
            None: Shows the missing-folder message without starting work.
        """
        app = mock.Mock()
        app.updates = None
        app.worker = None
        app.api_key_revoke_running = False
        app.watched_folder_var.get.return_value = ''
        with mock.patch.object(MAIN, 'messagebox') as dialogs:
            MAIN.WatcherApp.start(app)
        dialogs.showinfo.assert_called_once_with(
            'Watch folder', 'Choose a folder to watch before starting.',
            parent=app.root,
        )
        app.flights.can_start.assert_not_called()
        app.capture_linked_target.assert_not_called()


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

    def test_macos_source_skips_background_pystray_startup(self) -> None:
        """Keep the macOS source window alive without entering AppKit on a worker.

        Returns:
            None: A present pystray dependency is never started on macOS.
        """
        app = object.__new__(MAIN.WatcherApp)
        icon_factory = mock.Mock()
        tray = types.SimpleNamespace(Icon=icon_factory)
        platform = types.SimpleNamespace(platform="darwin")

        with mock.patch.object(MAIN, "sys", platform), \
                mock.patch.object(MAIN, "pystray", tray), \
                mock.patch.object(MAIN, "Image", object()):
            MAIN.WatcherApp.start_tray_icon(app)

        icon_factory.assert_not_called()

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
        app.flights = mock.Mock()
        app.flights.session_state.return_value = "idle"
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
        app.simulator_presence = mock.Mock()
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
        app.simulator_presence.stop.assert_not_called()
        self.assertEqual("0.4.1", app.update_available_version)
        dialogs.showwarning.assert_called_once()

    def test_close_canceled_to_keep_tray_keeps_update_offer(self) -> None:
        """Keep update state when a worker timeout cancels application exit.

        Returns:
            None: Confirms the keep-running choice leaves app and offer active.
        """
        app = object.__new__(MAIN.WatcherApp)
        app.simulator_presence = mock.Mock()
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
        app.worker.stop_event.is_set.return_value = False
        app.worker.drain_only = False
        app.worker.linked_target = None
        app.manual_worker = None
        app.ai_worker = None
        app.api_key_revoke_thread = None
        app.ai_worker_stop_requested = False
        app.tray_icon = mock.Mock()
        app.write_log = mock.Mock()
        app.hide_to_tray = mock.Mock()
        app.root = mock.Mock()
        app._watcher_recording_after = "watcher-recording-pulse"
        app._watcher_recording_active = True
        app._watcher_recording_pulse = True
        app._watcher_recording_target = None
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
        app.root.after_cancel.assert_called_once_with(
            "watcher-recording-pulse",
        )
        self.assertIsNotNone(app._watcher_recording_after)
        self.assertTrue(app._watcher_recording_active)
        self.assertTrue(
            any(
                call.args[0] == MAIN.WATCHER_RECORDING_PULSE_MS
                and call.args[1] == app._pulse_watcher_recording
                for call in app.root.after.call_args_list
            )
        )
        app.simulator_presence.stop.assert_not_called()

    def test_update_close_exits_all_threads_without_worker_checks(self) -> None:
        """Kill app-owned children and exit despite active or unknown workers.

        Returns:
            None: Confirms no drain, revocation prompt or updater recursion.
        """
        app = object.__new__(MAIN.WatcherApp)
        app.exiting = False
        app.updates = mock.Mock()
        app.api_key_revoke_running = True
        app.flights = mock.Mock()
        app.simulator_presence = mock.Mock()
        child = mock.Mock()
        failed_child = mock.Mock()
        failed_child.terminate.side_effect = OSError('already exited')
        with mock.patch.object(
            MAIN.multiprocessing, 'active_children',
            return_value=[failed_child, child],
        ), mock.patch.object(
            MAIN.os, '_exit', side_effect=SystemExit(0),
        ) as exit_process, mock.patch.object(MAIN, 'messagebox') as dialogs:
            with self.assertRaises(SystemExit):
                MAIN.WatcherApp.close(app, for_update=True)
        self.assertTrue(app.exiting)
        failed_child.terminate.assert_called_once_with()
        child.terminate.assert_called_once_with()
        exit_process.assert_called_once_with(0)
        app.flights.stop.assert_not_called()
        app.simulator_presence.join.assert_not_called()
        app.updates.request_close.assert_not_called()
        dialogs.showwarning.assert_not_called()
        dialogs.askyesno.assert_not_called()

    def test_confirmed_close_clears_update_offer_before_teardown(self) -> None:
        """Clear the persistent offer only when shutdown reaches teardown.

        Returns:
            None: Confirms shutdown clears the version, stops presence checks
                and destroys Tk.
        """
        app = object.__new__(MAIN.WatcherApp)
        app.linking_manager = mock.Mock()
        app.simulator_presence = mock.Mock()
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
        app._watcher_recording_after = "watcher-recording-pulse"
        app._watcher_recording_active = True
        app._watcher_recording_pulse = True
        app._watcher_recording_target = None
        app.root.destroy.side_effect = lambda: self.assertIsNone(
            app.update_available_version
        )

        MAIN.WatcherApp.close(app)
        app.flights.stop.assert_called_once_with()

        self.assertTrue(app.exiting)
        self.assertIsNone(app.update_available_version)
        app.stop_tray_icon.assert_called_once_with()
        app.root.destroy.assert_called_once_with()
        app.root.after_cancel.assert_called_once_with(
            "watcher-recording-pulse",
        )
        self.assertIsNone(app._watcher_recording_after)
        self.assertFalse(app._watcher_recording_active)
        app.linking_manager.close.assert_called_once_with()
        app.simulator_presence.stop.assert_called_once_with()

class GalleryLinkActionTests(unittest.TestCase):
    """Keep the app-wide linking action independent of API-key setup."""

    def test_link_actions_do_not_use_the_api_key_connection(self) -> None:
        """Route linking, switching and access management to their own addresses.

        Returns:
            None: The main action links or unlinks as appropriate; the picker
                and access controls use only the linking workspace's address.
        """
        for issuer in ("", "https://gallery.example/galerie"):
            with self.subTest(issuer=issuer):
                app = mock.Mock()
                app.updates.blocked.return_value = False
                app.gallery_url_var.get.return_value = "https://upload.example"
                app.api_key_var.get.return_value = "pgu_fixture"
                app.linking_manager.address.return_value = issuer
                app.linking_manager.linked_address.get.return_value = issuer

                MAIN.WatcherApp.link_gallery(app)
                if issuer:
                    MAIN.WatcherApp.choose_linked_gallery(app)
                    MAIN.WatcherApp.open_gallery_links(app)
                MAIN.WatcherApp.manage_gallery_link(app)

                expected = [mock.call(app.root, issuer, manage=True)]
                if issuer:
                    app.linking_manager.unlink.assert_called_once_with()
                    expected.insert(0, mock.call(app.root, issuer))
                else:
                    app.linking_manager.unlink.assert_not_called()
                app.linking_manager.open_address_dialog.assert_called_once_with()
                self.assertEqual(expected, app.linking_manager.open.call_args_list)
                app.linking_manager.link.assert_not_called()
                app.gallery_url_var.get.assert_not_called()
                app.open_connection_dialog.assert_not_called()
                app.api_key_var.get.assert_not_called()
                app.save_config.assert_not_called()


if __name__ == "__main__":
    unittest.main(verbosity=2)
