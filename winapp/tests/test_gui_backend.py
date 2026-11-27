# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/tests/test_gui_backend.py
# Module Type: WinApp desktop lifecycle tests
# Purpose: Verify stable selection, safe startup evidence and pending-work refusal.
# Responsibilities: Use only isolated profiles and bounded child processes.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)

"""Exercise backend composition without requiring optional Qt libraries."""

import hashlib
import json
import os
from pathlib import Path
from queue import Queue
import sys
import tempfile
from threading import Event
import time
import unittest
from unittest.mock import MagicMock, call, patch

from uploader import gui_backend
from uploader.app_linking import (
    ADMIN_DELETE_SCOPE_SUFFIX, ADMIN_PRESENTATION_SCOPE_SUFFIX, GALLERY_EDIT_SCOPE_SUFFIX,
    GALLERY_SEARCH_SUFFIX, LinkedIdentity, LinkingError, LinkingSession,
    READ_UPLOAD_OFFLINE_SCOPE, ROLLING_SCOPE_SUFFIX, CREATE_VISIBILITY_SCOPE,
    ROLLING_GALLERIES_SCOPE, ROLLING_GALLERY_SEARCH_SCOPES,
    ROLLING_UPLOAD_SCOPE,
)
from uploader.credential_store import WindowsCredentialStore
from uploader.desktop_connection import (
    open_saved_upload_session, persist_browser_gallery_grant,
    persist_browser_scope_upgrade, persist_browser_upload_grant,
    revoke_saved_gallery_grant,
)
from uploader.flight_jobs import (
    ImportBindings, SessionPlan, WatcherContinuation, WorkspaceLease, pending_sessions,
)
from uploader.gui_backend_profile import inspect_gui_profile_work
from uploader.import_commands import ImportSource
from uploader.linked_upload import LinkedUploadTarget
from uploader.models import ImportItem, ImportJob, JsonValue
from uploader.offline_linking import (
    OfflineAccess, OfflineBinding, load_offline_binding, offline_binding_path, save_offline_binding,
)
from uploader.state_store import JobStateStore, linked_upload_state_path
from uploader.upload_mode import ModeRecord
from uploader.watch_queue import WatchQueueStore, observe_watch_source
from test_redesign import MAIN

# Purpose: Keep child crash/hang checks bounded inside the normal WinApp suite.
# Type: float. Units: seconds. Scope: one synthetic startup.
# Consumers: BackendPreferenceTests.
# Rationale: Two seconds allows a cold interpreter to exit or publish readiness
# and makes a hung child terminate well within its existing partition deadline.
FIXTURE_STARTUP_SECONDS = 2.0

# Purpose: Give a synthetic, freshly approved grant a known storage lifetime.
# Type: int. Units: seconds. Scope: portable browser-consent fixture only.
# Consumers: BackendPreferenceTests.test_fresh_rolling_grant_keeps_exact_native_binding.
# Rationale: A minute is positive and comfortably inside the allowed server lease.
FIXTURE_REFRESH_LIFETIME_SECONDS = 60


def _write_linked_uncertain_history(
    profile: Path, binding: str, failures: dict[str, JsonValue],
) -> Path:
    """Write one isolated target ledger for profile-admission regression cases.

    Args:
        profile (Path): Disposable uploader profile root.
        binding (str): Structural target fingerprint used by the ledger path.
        failures (dict[str, JsonValue]): Exact source-keyed failure rows.
    Returns:
        Path: Written target-specific ledger whose original bytes can be checked.
    """
    path = linked_upload_state_path(profile, binding)
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(json.dumps({
        'schema_version': 2,
        'uploaded_paths': {},
        'uploaded_hashes': {},
        'failures': failures,
        'jobs': {},
        'recent_events': [],
    }), encoding='utf-8')
    return path


class BackendPreferenceTests(unittest.TestCase):
    """Verify preferences and startup decisions independently of toolkit imports."""

    def test_preferences_are_independent_and_fail_closed(self) -> None:
        """Round-trip both choices and reject duplicate or malformed values.

        Returns:
            None: Qt starts by default. Valid choices persist and malformed
                preferences keep the Legacy Tk recovery path.
        """
        with tempfile.TemporaryDirectory() as folder:
            profile = Path(folder)
            preference = profile / gui_backend.GUI_BACKEND_FILE
            config = profile / "config.json"
            config.write_bytes(b'{"sentinel": true}')
            self.assertEqual("pyside6", gui_backend.load_gui_backend(profile))
            self.assertFalse(preference.exists())
            gui_backend.save_gui_backend(profile, "tkinter")
            self.assertEqual("tkinter", gui_backend.load_gui_backend(profile))
            gui_backend.save_gui_backend(profile, "pyside6")
            self.assertEqual("pyside6", gui_backend.load_gui_backend(profile))
            with patch.object(Path, "replace", side_effect=OSError("synthetic interruption")):
                with self.assertRaises(OSError):
                    gui_backend.save_gui_backend(profile, "tkinter")
            self.assertEqual("pyside6", gui_backend.load_gui_backend(profile))
            self.assertEqual(b'{"sentinel": true}', config.read_bytes())
            for raw in (
                b'{"backend":"pyside6","backend":"tkinter"}', b'{"backend":"pyside6"',
                b'{"backend":["pyside6"]}', b'{"backend":"other"}',
            ):
                with self.subTest(raw=raw):
                    preference.write_bytes(raw)
                    self.assertEqual("tkinter", gui_backend.load_gui_backend(profile))

    def test_cli_override_keeps_saved_choice(self) -> None:
        """Verify the stable override reaches Tk without writing the preference.

        Returns:
            None: A single process override has no persistent effect.
        """
        with tempfile.TemporaryDirectory() as folder:
            profile = Path(folder)
            gui_backend.save_gui_backend(profile, "pyside6")
            with (
                patch.object(MAIN, "CONFIG_DIR", profile),
                patch.object(MAIN, "WindowsInstance") as instance,
                patch.object(MAIN, "ConfigStore"),
                patch.object(MAIN, "setup_logging"),
                patch.object(MAIN, "WatcherApp") as app,
                patch.object(MAIN, "supervise_gui_startup") as supervise,
            ):
                instance.return_value.acquire.return_value = True
                self.assertEqual(0, MAIN.main(["--gui-backend", "tkinter"]))
                app.return_value.run.assert_called_once_with()
                supervise.assert_not_called()
            self.assertEqual("pyside6", gui_backend.load_gui_backend(profile))

    def test_supervisor_waits_for_child_death_before_fallback(self) -> None:
        """Recover from abnormal process termination and a hung startup.

        Returns:
            None: Reports a bounded, sanitized refusal in both cases.
        """
        with patch.object(gui_backend, "GUI_STARTUP_SECONDS", FIXTURE_STARTUP_SECONDS):
            for command in ("os._exit(9)", "threading.Event().wait()"):
                with self.subTest(command=command):
                    self.assertEqual("startup_failed", gui_backend.supervise_gui_startup([
                        sys.executable, "-c", "import os, threading\n" + command,
                    ]))

    def test_supervisor_accepts_exact_ready_and_activation_responses(self) -> None:
        """Keep a successful Qt start or concurrent activation out of fallback.

        Returns:
            None: Only the exact private launch nonce can acknowledge either state.
        """
        for state in ("ready", "activated"):
            with self.subTest(state=state):
                child = (
                    "import json, os, pathlib, sys\n"
                    f"directory = pathlib.Path(os.environ[{gui_backend.GUI_STARTUP_DIRECTORY!r}])\n"
                    f"path = directory / {gui_backend.GUI_STARTUP_RESPONSE!r}\n"
                    "path.write_text(json.dumps({"
                    f"'nonce': sys.argv[-1], 'state': {state!r}, 'reason': None"
                    "}), encoding='utf-8')\n"
                )
                with patch.object(
                    gui_backend, "GUI_STARTUP_SECONDS", FIXTURE_STARTUP_SECONDS,
                ):
                    self.assertIsNone(gui_backend.supervise_gui_startup([
                        sys.executable, "-c", child,
                    ]))

    def test_failed_qt_restart_falls_back_with_the_update_nonce(self) -> None:
        """Retain an updater continuation when supervised Qt cannot start.

        Returns:
            None: A fresh profile starts Qt and falls back once with the same nonce.
        """
        with tempfile.TemporaryDirectory() as folder:
            profile = Path(folder)
            nonce = "b" * 32
            config = profile / "config.json"
            with (
                patch.object(MAIN, "CONFIG_DIR", profile),
                patch.object(MAIN, "WindowsInstance") as instance,
                patch.object(
                    MAIN, "supervise_gui_startup", return_value="startup_failed",
                ) as supervise,
                patch.object(MAIN, "_launch_desktop", return_value=0) as launch,
            ):
                instance.return_value.acquire.return_value = True
                self.assertEqual(0, MAIN.main([
                    "--config", str(config), "--after-update", nonce,
                ]))
            supervised_command = supervise.call_args.args[0]
            self.assertIn("--after-update", supervised_command)
            self.assertEqual(
                nonce, supervised_command[supervised_command.index("--after-update") + 1],
            )
            self.assertEqual(1, launch.call_count)
            fallback_arguments = launch.call_args.args[1]
            self.assertIn("--after-update", fallback_arguments)
            self.assertEqual(
                nonce, fallback_arguments[fallback_arguments.index("--after-update") + 1],
            )
            self.assertEqual("tkinter", fallback_arguments[
                fallback_arguments.index("--gui-backend") + 1
            ])
            self.assertEqual("startup_failed", fallback_arguments[
                fallback_arguments.index("--gui-fallback") + 1
            ])
            self.assertFalse((profile / gui_backend.GUI_BACKEND_FILE).exists())
            instance.return_value.close.assert_called_once_with()

    def test_frozen_qt_reuses_stable_launcher_and_requires_current_runtime(self) -> None:
        """Verify Tk and supervised Qt relaunch the one public executable.

        Returns:
            None: Both selections use the same frozen application identity.
        """
        with tempfile.TemporaryDirectory() as folder:
            root = Path(folder)
            launcher = root / 'PHPGalleryUploader.exe'
            contents = root / '_internal'
            contents.mkdir(parents=True)
            launcher.write_bytes(b'synthetic common bundle identity')
            with patch.object(sys, 'frozen', True, create=True):
                with patch.object(sys, 'executable', str(launcher)):
                    self.assertEqual(launcher, gui_backend.public_executable())
                    self.assertEqual(
                        str(launcher),
                        gui_backend.launcher_command(root, 'fixture.json')[0],
                    )
                with patch.object(sys, 'executable', str(launcher)), patch.object(
                    sys, '_MEIPASS', str(contents), create=True,
                ):
                    self.assertEqual(launcher, gui_backend.public_executable())
                    self.assertEqual(
                        str(launcher),
                        gui_backend.launcher_command(root, 'fixture.json')[0],
                    )

    def test_active_recovery_refuses_without_editing_its_plan(self) -> None:
        """Prove that an unreadable or pending continuation remains untouched.

        Returns:
            None: An empty profile is eligible and a continuation prevents Qt.
        """
        with tempfile.TemporaryDirectory() as folder:
            profile = Path(folder)
            self.assertFalse(inspect_gui_profile_work(profile).blocked)
            session = profile / "flights" / "synthetic"
            session.mkdir(parents=True)
            plan = session / "watcher-restart.json"
            plan.write_bytes(b'{"synthetic":true}')
            self.assertTrue(inspect_gui_profile_work(profile).blocked)
            self.assertEqual(b'{"synthetic":true}', plan.read_bytes())
            plan.unlink()
            report = session / "manual-report.json"
            report.write_bytes(b'{"phase":"generating"}')
            self.assertTrue(inspect_gui_profile_work(profile).blocked)
            self.assertEqual(b'{"phase":"generating"}', report.read_bytes())
            report.write_bytes(b'{"phase":"completed"}')
            self.assertFalse(inspect_gui_profile_work(profile).blocked)

    def test_legacy_recovery_reuses_exact_binding_and_never_rewrites_unknown_work(self) -> None:
        """Prove Qt distinguishes an exact held import from unverified work.

        Returns:
            None: A verified interrupted dispatch opens read-only for review.
                A foreign target or unconfirmed digest stays with its old owner.
        """
        with tempfile.TemporaryDirectory() as folder:
            profile = Path(folder).resolve()
            flights = profile / 'flights'
            flights.mkdir()
            source = profile / 'synthetic.jpg'
            source.write_bytes(b'synthetic import input')
            store = JobStateStore(profile / 'upload_state.json')
            binding = 'a' * 64  # Structural SHA-256 spelling for an isolated target.
            job = ImportJob.create('files', source.name, 'Fixture gallery')
            item = ImportItem.create(source)
            item.sha256 = 'b' * 64  # Fixture preflight pins a distinct source digest.
            item.transition('queued')
            job.items = [item]
            store.save_job(job)
            ImportBindings(flights).admit_job(store, job, binding)
            job.started_at = time.time()
            store.save_job(job)
            queue_dir = profile / 'import-queues' / job.id
            queue_dir.mkdir(parents=True)
            path = queue_dir / 'watch-drain.json'
            queue = WatchQueueStore(path, binding, profile, False, allowed_paths=(str(source),))
            queue.initialize([])
            queue.reconcile([observe_watch_source(source, time.time())])
            queued = path.read_bytes()
            eligible = inspect_gui_profile_work(profile, legacy_import_binding=binding)
            self.assertFalse(eligible.blocked)
            self.assertEqual((job.id,), eligible.recoverable_imports)
            self.assertEqual(queued, path.read_bytes())
            other = 'c' * 64  # A distinct structural SHA-256 target binding.
            self.assertTrue(inspect_gui_profile_work(profile, legacy_import_binding=other).blocked)

            head = queue.head()
            assert head is not None
            queue.update(head, state='dispatching', sha256=binding)
            unrelated = path.read_bytes()
            self.assertTrue(inspect_gui_profile_work(profile, legacy_import_binding=binding).blocked)
            self.assertEqual(unrelated, path.read_bytes())
            # The job owner's accepted source digest must agree with the FIFO.
            item.attempt_count += 1
            item.transition('uploading')
            store.save_job(job)
            queue.update(head, state='dispatching', sha256=item.sha256)
            pending = path.read_bytes()
            exact = inspect_gui_profile_work(profile, legacy_import_binding=binding)
            self.assertFalse(exact.blocked)
            self.assertEqual((job.id,), exact.recoverable_imports)
            self.assertEqual(pending, path.read_bytes())
            self.assertEqual('dispatching', WatchQueueStore.read_journal(path)['items'][0]['state'])

    def test_interrupted_local_report_keeps_its_stopped_flight_in_qt(self) -> None:
        """Keep a verified partial report readable in the alternate backend.

        Returns:
            None: Safe local preview accepts its original stopped session and
                leaves render evidence and existing PDF bytes unchanged.
        """
        with tempfile.TemporaryDirectory() as folder:
            profile = Path(folder)
            session = profile / 'flights' / 'finished'
            session.mkdir(parents=True)
            SessionPlan(session, 'a' * 64, (), False, completed=True).save()
            (session / 'session.json').write_text('{"recording_ended":true}', encoding='ascii')
            manual = session / 'manual-report.json'
            # The initial render schema uses a 32-digit UUID manual edition.
            evidence = '{"schema_version":1,"phase":"generating","revision":"manual-' + 'b' * 32 + '"}'
            manual.write_text(evidence, encoding='ascii')
            self.assertFalse(inspect_gui_profile_work(profile).blocked)
            self.assertEqual(evidence, manual.read_text(encoding='ascii'))
            (session / 'session.json').write_text('{"recording_ended":false}', encoding='ascii')
            self.assertTrue(inspect_gui_profile_work(profile).blocked)

    def test_capture_admission_preserves_original_queue_without_dispatch(self) -> None:
        """Allow review of one matching capture and refuse a foreign target.

        Returns:
            None: Original FIFO, cutoff and plan bytes survive backend selection.
        """
        with tempfile.TemporaryDirectory() as folder:
            profile = Path(folder).resolve()
            source_folder = profile / 'photos'
            source_folder.mkdir()
            source = source_folder / 'synthetic.jpg'
            source.write_bytes(b'captured photograph')
            session = profile / 'flights' / 'captured'
            session.mkdir(parents=True)
            binding = 'a' * 64  # Exact structural SHA-256 target identity.
            plan = SessionPlan(session, binding, (), False)
            plan.save()
            path = session / 'watch-drain.json'
            queue = WatchQueueStore(path, binding, source_folder, False)
            queue.initialize([])
            queue.reconcile([observe_watch_source(source, time.time())])
            queue.stop_and_retain(time.time())
            saved = path.read_bytes()
            work = inspect_gui_profile_work(profile, capture_binding=binding)
            self.assertFalse(work.blocked)
            self.assertTrue(work.recoverable_capture)
            self.assertTrue(inspect_gui_profile_work(profile).blocked)
            self.assertEqual(saved, path.read_bytes())
            self.assertEqual(plan, SessionPlan.load(session))

    def test_pre_force_finish_local_flight_is_archived_in_both_backends(self) -> None:
        """Recognize an older closed flight with unsubmitted photos still on disk.

        Returns:
            None: The shared scheduler and Qt admission agree on terminal state.
                Unfinished or mismatched evidence remains fenced from startup.
        """
        with tempfile.TemporaryDirectory() as folder:
            profile = Path(folder).resolve()
            root = profile / 'flights'
            session = root / 'closed'
            session.mkdir(parents=True)
            source = profile / 'original.jpg'
            original = b'historic photo bytes'
            source.write_bytes(original)
            binding = hashlib.sha256(b'synthetic linked capture').hexdigest()
            plan = SessionPlan(
                session, binding, (), False, completed=True, local_only=True, abandoned=True,
            )
            plan.save()
            completion = session / 'completion.json'
            metadata = session / 'session.json'
            metadata.write_text('{"recording_ended":true}', encoding='ascii')
            journal = session / 'watch-drain.json'
            queue = WatchQueueStore(journal, binding, profile, False, scope=ROLLING_UPLOAD_SCOPE)
            queue.initialize([])
            queue.reconcile([observe_watch_source(source, time.time())])
            queue.finish(time.time())
            legacy = WatchQueueStore.read_journal(journal)
            self.assertEqual([str(source)], legacy['pending'])
            # The historical schema-2 writer predates these explicit closure fields.
            for key in ('force_finished', 'force_finished_at', 'retained_stop'):
                legacy.pop(key)
            journal.write_text(json.dumps(legacy), encoding='utf-8')
            retained = journal.read_bytes(), completion.read_bytes(), metadata.read_bytes()

            admitted = inspect_gui_profile_work(profile)
            self.assertFalse(admitted.blocked)
            self.assertIsNone(admitted.refusal_stage)
            self.assertFalse(admitted.recoverable_capture)
            self.assertIsNone(admitted.restart)
            self.assertEqual([], pending_sessions(root, binding))
            self.assertEqual(retained, (
                journal.read_bytes(), completion.read_bytes(), metadata.read_bytes(),
            ))

            foreign = hashlib.sha256(b'unrelated capture').hexdigest()
            for label, changed in (
                ('different target', {**legacy, 'binding': foreign}),
                ('different flight', {**legacy, 'session_id': 'another'}),
                ('unfrozen queue', {**legacy, 'frozen': False}),
                ('invalid source', {**legacy, 'pending': ['relative.jpg']}),
                ('current unfinished queue', {**legacy, 'force_finished': False}),
            ):
                with self.subTest(label=label):
                    journal.write_text(json.dumps(changed), encoding='utf-8')
                    before = journal.read_bytes()
                    refused = inspect_gui_profile_work(profile)
                    self.assertTrue(refused.blocked)
                    self.assertEqual('flight_journal', refused.refusal_stage)
                    self.assertEqual(before, journal.read_bytes())
            journal.write_bytes(retained[0])
            metadata.write_text('{"recording_ended":false}', encoding='ascii')
            self.assertTrue(inspect_gui_profile_work(profile).blocked)
            metadata.write_bytes(retained[2])
            plan.completed = False
            plan.save()
            self.assertTrue(inspect_gui_profile_work(profile, capture_binding=binding).blocked)
            self.assertEqual(retained[0], journal.read_bytes())
            self.assertEqual(original, source.read_bytes())

    def test_linked_uncertainty_admission_is_review_only_per_target(self) -> None:
        """Admit one exact linked marker in each target's safe history.

        Returns:
            None: Default startup still refuses and admission leaves ledger bytes intact.
        """
        with tempfile.TemporaryDirectory() as folder:
            profile = Path(folder).resolve()
            source_paths = (profile / 'first.jpg', profile / 'second.jpg')
            target_bindings = ('b' * 64, 'c' * 64)
            original_ledgers = []
            for source, binding, content in zip(
                source_paths, target_bindings, (b'first photo', b'second photo'),
            ):
                source.write_bytes(content)
                digest = hashlib.sha256(content).hexdigest()
                path = _write_linked_uncertain_history(profile, binding, {
                    str(source): {
                        'sha256': digest, 'uncertain': True,
                        'last_error': 'Synthetic outcome unknown.',
                        'last_failed_at': 1.0,
                    },
                })
                original_ledgers.append((path, path.read_bytes()))

            self.assertTrue(inspect_gui_profile_work(profile).blocked)
            admitted = inspect_gui_profile_work(
                profile, capture_binding='a' * 64,
                capture_scope=ROLLING_UPLOAD_SCOPE, linked_history_recovery=True,
            )

            self.assertFalse(admitted.blocked)
            self.assertEqual(
                tuple(raw for _path, raw in original_ledgers),
                tuple(path.read_bytes() for path, _raw in original_ledgers),
            )

    def test_linked_history_refuses_multiple_or_malformed_markers(self) -> None:
        """Keep unknown, ambiguous and ownerless linked outcomes with Tk.

        Returns:
            None: Only a single structurally valid row per target can be admitted.
        """
        with tempfile.TemporaryDirectory() as folder:
            profile = Path(folder).resolve()
            first = str((profile / 'first.jpg').resolve())
            second = str((profile / 'second.jpg').resolve())
            digest = hashlib.sha256(b'synthetic source').hexdigest()
            two_rows = _write_linked_uncertain_history(profile, 'b' * 64, {
                first: {'sha256': digest, 'uncertain': True},
                second: {'sha256': digest, 'uncertain': True},
            })
            two_rows_bytes = two_rows.read_bytes()
            self.assertTrue(inspect_gui_profile_work(
                profile, linked_history_recovery=True,
            ).blocked)
            self.assertEqual(two_rows_bytes, two_rows.read_bytes())

        invalid_rows = (
            ('relative-source', 'relative.jpg', {'sha256': 'a' * 64, 'uncertain': True}),
            ('uppercase-digest', str(Path('absolute.jpg').resolve()), {
                'sha256': 'A' * 64, 'uncertain': True,
            }),
            ('unknown-field', str(Path('absolute.jpg').resolve()), {
                'sha256': 'a' * 64, 'uncertain': True, 'unknown': 'value',
            }),
            ('invalid-reviewed', str(Path('absolute.jpg').resolve()), {
                'sha256': 'a' * 64, 'uncertain': True, 'reviewed': False,
            }),
        )
        for case_name, source, failure in invalid_rows:
            with self.subTest(case=case_name), tempfile.TemporaryDirectory() as folder:
                profile = Path(folder).resolve()
                path = _write_linked_uncertain_history(
                    profile, 'd' * 64, {source: failure},
                )
                original = path.read_bytes()
                self.assertTrue(inspect_gui_profile_work(
                    profile, linked_history_recovery=True,
                ).blocked)
                self.assertEqual(original, path.read_bytes())

    def test_legacy_uncertainty_stays_blocked_with_linked_review_opt_in(self) -> None:
        """Do not turn an unbound legacy marker into a linked target owner.

        Returns:
            None: The legacy state remains refused and its original bytes survive.
        """
        with tempfile.TemporaryDirectory() as folder:
            profile = Path(folder).resolve()
            source = str((profile / 'legacy.jpg').resolve())
            payload = {
                'schema_version': 2, 'uploaded_paths': {}, 'uploaded_hashes': {},
                'failures': {source: {
                    'sha256': hashlib.sha256(b'legacy source').hexdigest(),
                    'uncertain': True,
                }},
                'jobs': {}, 'recent_events': [],
            }
            history = profile / 'upload_state.json'
            history.write_text(json.dumps(payload), encoding='utf-8')
            original = history.read_bytes()

            self.assertTrue(inspect_gui_profile_work(
                profile, linked_history_recovery=True,
            ).blocked)
            self.assertEqual(original, history.read_bytes())

    def test_standalone_marker_blocks_automatic_same_target_continuation(self) -> None:
        """Keep restart auto-resume blocked when its target has standalone uncertainty.

        Returns:
            None: Admission refuses without changing either marker or continuation.
        """
        with tempfile.TemporaryDirectory() as folder:
            profile = Path(folder).resolve()
            flights = profile / 'flights'
            session = flights / ('a' * 32)
            session.mkdir(parents=True)
            images = profile / 'photos'
            images.mkdir()
            binding = 'b' * 64
            SessionPlan(session, binding, (), False).save()
            queue_path = session / 'watch-drain.json'
            queue = WatchQueueStore(
                queue_path, binding, images, False, scope=ROLLING_UPLOAD_SCOPE,
            )
            queue.initialize([])
            continuation = WatcherContinuation(
                session.name, binding, WatcherContinuation.profile_digest(flights),
                '1.7.1', '1.7.1', 'c' * 32, 'linked', False, time.time(),
                'prepared', ROLLING_UPLOAD_SCOPE,
            )
            lease = WorkspaceLease(flights)
            try:
                continuation.save(lease)
                continuation.save(lease, ordinary_restart=True)
            finally:
                lease.release()
            without_uncertainty = inspect_gui_profile_work(
                profile, capture_binding=binding,
                capture_scope=ROLLING_UPLOAD_SCOPE, restart_version='1.7.1',
                linked_history_recovery=True,
            )
            self.assertFalse(without_uncertainty.blocked)
            self.assertEqual(continuation, without_uncertainty.restart)
            source = str((profile / 'uncertain.jpg').resolve())
            linked_history = _write_linked_uncertain_history(profile, binding, {
                source: {
                    'sha256': hashlib.sha256(b'uncertain source').hexdigest(),
                    'uncertain': True,
                },
            })
            snapshots = (
                queue_path.read_bytes(),
                (flights / 'watcher-continuation.json').read_bytes(),
                (flights / 'watcher-restart.json').read_bytes(),
                linked_history.read_bytes(),
            )

            result = inspect_gui_profile_work(
                profile, capture_binding=binding,
                capture_scope=ROLLING_UPLOAD_SCOPE, restart_version='1.7.1',
                linked_history_recovery=True,
            )

            self.assertTrue(result.blocked)
            self.assertEqual(snapshots, (
                queue_path.read_bytes(),
                (flights / 'watcher-continuation.json').read_bytes(),
                (flights / 'watcher-restart.json').read_bytes(),
                linked_history.read_bytes(),
            ))

    def test_verified_solo_terminal_continuation_needs_no_current_target(self) -> None:
        """Allow verified terminal primary or ordinary markers without a link.

        Returns:
            None: Solo and paired terminal evidence is admitted without mutation.
        """
        for marker_shape in ('primary', 'ordinary', 'paired'):
            with self.subTest(shape=marker_shape), tempfile.TemporaryDirectory() as folder:
                profile = Path(folder).resolve()
                flights = profile / 'flights'
                flights.mkdir()
                record = WatcherContinuation(
                    'a' * 32, 'b' * 64, WatcherContinuation.profile_digest(flights),
                    '1.7.1', '1.7.1', 'c' * 32, 'linked', False, time.time(),
                    'cancelled', ROLLING_UPLOAD_SCOPE,
                )
                lease = WorkspaceLease(flights)
                try:
                    if marker_shape in ('primary', 'paired'):
                        record.save(lease)
                    if marker_shape in ('ordinary', 'paired'):
                        ordinary_record = record
                        if marker_shape == 'paired':
                            ordinary_record = WatcherContinuation(
                                'd' * 32, 'e' * 64,
                                WatcherContinuation.profile_digest(flights),
                                '1.6.0', '1.6.1', 'f' * 32, 'legacy', False,
                                time.time(), 'consumed', '',
                            )
                        ordinary_record.save(lease, ordinary_restart=True)
                finally:
                    lease.release()
                marker_paths = tuple(
                    flights / name for name in (
                        'watcher-continuation.json', 'watcher-restart.json',
                    ) if (flights / name).is_file()
                )
                original = tuple(path.read_bytes() for path in marker_paths)

                result = inspect_gui_profile_work(profile)

                self.assertFalse(result.blocked)
                self.assertEqual(original, tuple(path.read_bytes() for path in marker_paths))

    def test_legacy_capture_admission_uses_only_provable_fifo_and_original_binding(self) -> None:
        """Present a verified older API-key queue without rewriting its source.

        Returns:
            None: One pending item can be shown for review. Multiple pending
                paths or a subsequently linked scope retain compatibility handling.
        """
        with tempfile.TemporaryDirectory() as folder:
            profile = Path(folder).resolve()
            session = profile / 'flights' / 'legacy'
            session.mkdir(parents=True)
            images = profile / 'photos'
            images.mkdir()
            first = images / 'first.jpg'
            first.write_bytes(b'original capture bytes')
            second = images / 'second.jpg'
            second.write_bytes(b'other capture bytes')
            binding = 'a' * 64  # Structural SHA-256 spelling for the saved plan.
            SessionPlan(session, binding, (), False).save()
            journal = session / 'watch-drain.json'
            # Schema 1 records pending paths without a durable first-seen sequence.
            row = {
                'schema_version': 1, 'folder': str(images), 'recursive': False,
                'initial': [], 'pending': [str(first)], 'initial_captured': True,
                'frozen': False, 'cutoff': 0.0, 'checkpoint': time.time(),
            }
            journal.write_text(json.dumps(row), encoding='utf-8')
            original = journal.read_bytes()
            compatible = inspect_gui_profile_work(profile, capture_binding=binding)
            self.assertFalse(compatible.blocked)
            self.assertTrue(compatible.recoverable_capture)
            self.assertTrue(inspect_gui_profile_work(
                profile, capture_binding=binding, capture_scope=ROLLING_UPLOAD_SCOPE,
            ).blocked)
            self.assertEqual(original, journal.read_bytes())
            row['pending'].append(str(second))
            journal.write_text(json.dumps(row), encoding='utf-8')
            ambiguous = journal.read_bytes()
            self.assertTrue(inspect_gui_profile_work(profile, capture_binding=binding).blocked)
            self.assertEqual(ambiguous, journal.read_bytes())
            self.assertEqual(b'original capture bytes', first.read_bytes())
            self.assertEqual(b'other capture bytes', second.read_bytes())

    def test_installer_continuation_requires_the_exact_verified_helper(self) -> None:
        """Admit only an installer handoff matching its original capture and build.

        Returns:
            None: A foreign or absent helper leaves its checkpoint and queue intact.
        """
        with tempfile.TemporaryDirectory() as folder:
            profile = Path(folder).resolve()
            root = profile / 'flights'
            session = root / ('c' * 32)  # Structural session UUID spelling.
            session.mkdir(parents=True)
            images = profile / 'images'
            images.mkdir()
            binding = 'a' * 64  # Structural target fingerprint.
            SessionPlan(session, binding, (), False).save()
            journal = WatchQueueStore(session / 'watch-drain.json', binding, images, False)
            journal.initialize([])
            nonce = 'b' * 32  # Structural helper ticket nonce spelling.
            source_version, target_version = '1.7.1', '1.7.2'
            prepared = WatcherContinuation(
                session.name, binding, WatcherContinuation.profile_digest(root),
                source_version, target_version, nonce, 'legacy', False, time.time(), 'prepared',
            )
            lease = WorkspaceLease(root)
            try:
                prepared.save(lease)
            finally:
                lease.release()
            journal_bytes = journal.path.read_bytes()
            accepted = inspect_gui_profile_work(
                profile, capture_binding=binding, restart_version=target_version,
                installer_restart=(nonce, target_version),
            )
            self.assertEqual(prepared, accepted.restart)
            for helper in (None, (nonce, source_version), (session.name, target_version)):
                with self.subTest(helper=helper):
                    refused = inspect_gui_profile_work(
                        profile, capture_binding=binding, restart_version=target_version,
                        installer_restart=helper,
                    )
                    self.assertTrue(refused.blocked)
            self.assertEqual(journal_bytes, journal.path.read_bytes())

    def test_native_import_preflight_keeps_the_selected_linked_target(self) -> None:
        """Exercise connection selection without issuing a browser or upload request.

        Returns:
            None: A linked worker owns its scoped target, and an absent grant refuses.
        """
        with tempfile.TemporaryDirectory() as folder:
            profile = Path(folder).resolve()
            store = MAIN.ConfigStore(profile / 'config.json')
            config = store.load()
            config.gallery_url = 'https://legacy.example.test/'
            config.api_key = 'non-network fixture key'
            store.save(config)
            mode = MagicMock(spec=MAIN.GuiModeOwner)
            mode.view.return_value.state = 'linked_active'
            target = MAIN.LinkedUploadTarget(
                MagicMock(), 'https://linked.example.test/', 3, 9, 'Fixture target',
            )
            source = ImportSource(files=(profile / 'example.jpg',))
            with patch.multiple(MAIN, CONFIG_DIR=profile, STATE_PATH=profile / 'upload_state.json'):
                actions = MAIN.desktop_import_actions(store, config, lambda: mode, lambda: target)
                worker = actions.prepare(source, Queue())
                self.assertIs(target, worker.linked_target)
                self.assertFalse(worker.is_alive())
                missing = MAIN.desktop_import_actions(store, config, lambda: mode, lambda: None)
                with self.assertRaises(MAIN.ModeCoordinatorError):
                    missing.prepare(source, Queue())

    def test_fresh_rolling_grant_keeps_exact_native_binding(self) -> None:
        """Persist one browser-proved upload grant without exposing its refresh token.

        Returns:
            None: Secure storage, exact non-secret hint and same-scope renewal
                succeed. A different account or a cancelled approval cannot write.
        """
        with tempfile.TemporaryDirectory() as folder:
            profile = Path(folder)
            issuer = 'https://gallery.example.test'
            identity = LinkedIdentity(1, 'synthetic')
            store = MagicMock(spec=WindowsCredentialStore)
            store.read.return_value = None
            session = MagicMock(spec=LinkingSession)
            session.approved_scope.return_value = ROLLING_UPLOAD_SCOPE
            session.can_upload.return_value = True
            session.available.return_value = True
            session.matches_issuer.return_value = True
            cancelled = Event()
            # 43 canonical Base64URL characters encode the fixture's 256-bit token.
            refresh = 'pglr_' + 'A' * 43
            with patch('uploader.desktop_connection.enable_rolling_renewal') as renewal:
                persist_browser_upload_grant(
                    profile, store, issuer, identity, session, refresh, FIXTURE_REFRESH_LIFETIME_SECONDS, cancelled,
                )
                binding = OfflineBinding(issuer, identity.user_id, ROLLING_UPLOAD_SCOPE)
                path = offline_binding_path(profile / 'offline-link.json', issuer)
                self.assertEqual(binding, load_offline_binding(path, issuer))
                self.assertNotIn(refresh.encode('ascii'), path.read_bytes())
                store.write.assert_called_once()
                renewal.assert_called_once_with(session, store, path, binding)
                with self.assertRaises(LinkingError):
                    persist_browser_upload_grant(
                        profile, store, issuer, LinkedIdentity(2, 'other'), session, refresh,
                        FIXTURE_REFRESH_LIFETIME_SECONDS, cancelled,
                    )
                cancelled.set()
                with self.assertRaises(LinkingError):
                    persist_browser_upload_grant(
                        profile, store, issuer, identity, session, refresh, FIXTURE_REFRESH_LIFETIME_SECONDS, cancelled,
                    )
                store.write.assert_called_once()
                self.assertEqual(binding, load_offline_binding(path, issuer))

    def test_read_only_rolling_grant_persists_only_allowlisted_listing_scope(self) -> None:
        """Persist a read-only rolling grant without selecting upload mode.

        Returns:
            None: Exact identity, scope and live deadline are bound in the vault.
                Upload scope and mismatched identity are refused before writes.
        """
        with tempfile.TemporaryDirectory() as folder:
            profile = Path(folder)
            issuer = 'https://gallery.example.test'
            identity = LinkedIdentity(7, 'synthetic')
            scope = ROLLING_GALLERIES_SCOPE
            store = MagicMock(spec=WindowsCredentialStore)
            store.read.side_effect = [None, b'synthetic-record']
            session = MagicMock(spec=LinkingSession)
            session.approved_scope.return_value = scope
            session.can_list_galleries.return_value = True
            session.can_upload.return_value = False
            session.available.return_value = True
            session.matches_issuer.return_value = True
            session.identity.return_value = identity
            session.transient_snapshot.return_value.issuer = issuer
            session.transient_snapshot.return_value.scope = scope
            session.transient_snapshot.return_value.expires_at = time.time() + 60
            refresh = 'pglr_' + 'A' * 43
            with (
                patch('uploader.desktop_connection.store_approved_refresh') as save_refresh,
                patch('uploader.desktop_connection.enable_rolling_renewal') as renewal,
            ):
                binding = persist_browser_gallery_grant(
                    profile, store, issuer, identity, session, refresh,
                    FIXTURE_REFRESH_LIFETIME_SECONDS, Event(),
                )
            expected = OfflineBinding(issuer, identity.user_id, scope)
            path = offline_binding_path(profile / 'offline-link.json', issuer)
            self.assertEqual(expected, binding)
            self.assertEqual(expected, load_offline_binding(path, issuer))
            self.assertNotIn(refresh.encode('ascii'), path.read_bytes())
            save_refresh.assert_called_once_with(
                store, issuer, identity.user_id, scope, refresh,
                FIXTURE_REFRESH_LIFETIME_SECONDS,
            )
            renewal.assert_called_once_with(session, store, path, expected)

            session.can_upload.return_value = True
            with self.assertRaises(LinkingError):
                persist_browser_gallery_grant(
                    profile, store, issuer, identity, session, refresh,
                    FIXTURE_REFRESH_LIFETIME_SECONDS, Event(),
                )
            session.can_upload.return_value = False
            session.identity.return_value = LinkedIdentity(8, 'other')
            with self.assertRaises(LinkingError):
                persist_browser_gallery_grant(
                    profile, store, issuer, identity, session, refresh,
                    FIXTURE_REFRESH_LIFETIME_SECONDS, Event(),
                )
            self.assertEqual(expected, load_offline_binding(path, issuer))
            self.assertEqual(1, save_refresh.call_count)

    def test_read_only_rolling_grant_refuses_wider_existing_binding(self) -> None:
        """Keep a saved upload binding when a read-only consent is offered.

        Returns:
            None: A broader exact hint remains byte-for-byte unchanged and no
                new credential is written to the occupied native slot.
        """
        with tempfile.TemporaryDirectory() as folder:
            profile = Path(folder)
            issuer = 'https://gallery.example.test'
            identity = LinkedIdentity(7, 'synthetic')
            wider = OfflineBinding(issuer, identity.user_id, ROLLING_UPLOAD_SCOPE)
            target = OfflineBinding(issuer, identity.user_id, ROLLING_GALLERIES_SCOPE)
            path = offline_binding_path(profile / 'offline-link.json', issuer)
            save_offline_binding(path, wider)
            original_hint = path.read_bytes()
            store = MagicMock(spec=WindowsCredentialStore)
            store.read.return_value = b'existing-wider-record'
            session = MagicMock(spec=LinkingSession)
            session.approved_scope.return_value = target.scope
            session.can_list_galleries.return_value = True
            session.can_upload.return_value = False
            session.available.return_value = True
            session.matches_issuer.return_value = True
            session.identity.return_value = identity
            session.transient_snapshot.return_value.issuer = issuer
            session.transient_snapshot.return_value.scope = target.scope
            session.transient_snapshot.return_value.expires_at = time.time() + 60
            with patch('uploader.desktop_connection.store_approved_refresh') as save_refresh:
                with self.assertRaises(LinkingError):
                    persist_browser_gallery_grant(
                        profile, store, issuer, identity, session,
                        'pglr_' + 'A' * 43,
                        FIXTURE_REFRESH_LIFETIME_SECONDS, Event(),
                    )
            self.assertEqual(original_hint, path.read_bytes())
            store.write.assert_not_called()
            save_refresh.assert_not_called()

    def test_rolling_gallery_edit_consent_persists_its_exact_localhost_scope(self) -> None:
        """Retain an approved gallery-edit grant from a mounted local website.

        Returns:
            None: Registered Admin Photos editing can be restored, while the
                exact deletion bundle still requires its separate storage path.
        """
        issuer = 'http://localhost:8888/galerie'
        identity = LinkedIdentity(7, 'synthetic')
        admin_photos = (
            CREATE_VISIBILITY_SCOPE + GALLERY_SEARCH_SUFFIX + ADMIN_PRESENTATION_SCOPE_SUFFIX
        )
        for delete in ('', ADMIN_DELETE_SCOPE_SUFFIX):
            scope = admin_photos + delete + GALLERY_EDIT_SCOPE_SUFFIX + ROLLING_SCOPE_SUFFIX
            with self.subTest(delete=bool(delete)), tempfile.TemporaryDirectory() as folder:
                profile = Path(folder)
                store = MagicMock(spec=WindowsCredentialStore)
                store.read.side_effect = [None, b'synthetic-credential']
                session = LinkingSession(
                    issuer, 'pgla_' + 'A' * 43, time.monotonic() + FIXTURE_REFRESH_LIFETIME_SECONDS,
                    scope,
                )
                self.addCleanup(session.close)
                with patch.object(session, 'identity', return_value=identity):
                    self.assertTrue(session.can_edit_gallery_metadata())
                    self.assertFalse(session.can_upload())
                    path = offline_binding_path(profile / 'offline-link.json', issuer)
                    if delete:
                        with self.assertRaises(LinkingError):
                            persist_browser_gallery_grant(
                                profile, store, issuer, identity, session, 'pglr_' + 'A' * 43,
                                FIXTURE_REFRESH_LIFETIME_SECONDS, Event(),
                            )
                        self.assertFalse(path.exists())
                        store.write.assert_not_called()
                    else:
                        binding = persist_browser_gallery_grant(
                            profile, store, issuer, identity, session, 'pglr_' + 'A' * 43,
                            FIXTURE_REFRESH_LIFETIME_SECONDS, Event(),
                        )
                        self.assertEqual(OfflineBinding(issuer, identity.user_id, scope), binding)
                        self.assertEqual(binding, load_offline_binding(path, issuer))
                        store.write.assert_called_once()

    def test_shared_photos_scope_upgrade_rolls_back_on_mode_refusal(self) -> None:
        """Keep the previous binding when the shared host mode commit refuses.

        Returns:
            None: A failed exact mode publication restores original vault bytes
                and the original rolling hint through the common desktop owner.
        """
        with tempfile.TemporaryDirectory() as folder:
            profile = Path(folder)
            issuer = 'https://gallery.example.test'
            identity = LinkedIdentity(7, 'synthetic')
            legacy_hint = profile / 'legacy' / 'approved-gallery.json'
            previous = OfflineBinding(issuer, identity.user_id, ROLLING_GALLERIES_SCOPE)
            upgraded = OfflineBinding(
                issuer, identity.user_id, ROLLING_GALLERY_SEARCH_SCOPES[0],
            )
            path = offline_binding_path(legacy_hint, issuer)
            save_offline_binding(path, previous)
            original_record = b'original-refresh-record'
            published_record = b'new-refresh-record'
            store = MagicMock(spec=WindowsCredentialStore)
            store.read.side_effect = [original_record, published_record, published_record]
            commit = MagicMock(return_value=False)
            with patch(
                'uploader.desktop_connection.store_approved_refresh',
                side_effect=lambda owner, *args: owner.write(
                    issuer, identity.user_id, published_record,
                ),
            ):
                with self.assertRaises(LinkingError):
                    persist_browser_scope_upgrade(
                        legacy_hint, store, upgraded, previous.scope,
                        'pglr_' + 'A' * 43, FIXTURE_REFRESH_LIFETIME_SECONDS, commit,
                    )
            self.assertEqual(previous, load_offline_binding(path, issuer))
            self.assertEqual([
                call(issuer, identity.user_id, published_record),
                call(issuer, identity.user_id, original_record),
            ], store.write.call_args_list)
            commit.assert_called_once_with()

    def test_saved_gallery_unlink_requires_exact_hint_and_revokes_once(self) -> None:
        """Remove only the selected binding and attempt one captured revoke.

        Returns:
            None: Exact owner cleanup returns its revocation outcome; stale
                account selection performs no local removal or server request.
        """
        with tempfile.TemporaryDirectory() as folder:
            profile = Path(folder)
            issuer = 'https://gallery.example.test'
            binding = OfflineBinding(issuer, 7, ROLLING_GALLERIES_SCOPE)
            path = offline_binding_path(profile / 'offline-link.json', issuer)
            save_offline_binding(path, binding)
            store = MagicMock(spec=WindowsCredentialStore)
            record = b'captured-refresh-record'
            with (
                patch(
                    'uploader.desktop_connection.remove_saved_offline_link',
                    return_value=record,
                ) as remove,
                patch('uploader.desktop_connection.revoke_saved_refresh') as revoke,
            ):
                self.assertIs(
                    True, revoke_saved_gallery_grant(profile, issuer, 7, binding, store),
                )
            remove.assert_called_once()
            self.assertEqual(store, remove.call_args.args[0])
            self.assertEqual(binding, remove.call_args.args[2])
            self.assertTrue(remove.call_args.kwargs['capture_refresh'])
            remove.call_args.kwargs['verify']()
            revoke.assert_called_once_with(binding, record)

            with (
                patch('uploader.desktop_connection.remove_saved_offline_link') as remove,
                patch('uploader.desktop_connection.revoke_saved_refresh') as revoke,
            ):
                with self.assertRaises(LinkingError):
                    revoke_saved_gallery_grant(profile, issuer, 8, binding, store)
            remove.assert_not_called()
            revoke.assert_not_called()

    def test_saved_gallery_unlink_returns_access_fallback_or_revoke_failure(self) -> None:
        """Report missing refresh and failed remote revoke without replay.

        Returns:
            None: None leaves one access-token revoke to the live owner; False
                records one failed captured-refresh attempt after local removal.
        """
        with tempfile.TemporaryDirectory() as folder:
            profile = Path(folder)
            issuer = 'https://gallery.example.test'
            binding = OfflineBinding(issuer, 7, ROLLING_GALLERIES_SCOPE)
            path = offline_binding_path(profile / 'offline-link.json', issuer)
            save_offline_binding(path, binding)
            store = MagicMock(spec=WindowsCredentialStore)
            with (
                patch(
                    'uploader.desktop_connection.remove_saved_offline_link',
                    return_value=None,
                ) as remove,
                patch('uploader.desktop_connection.revoke_saved_refresh') as revoke,
            ):
                self.assertIsNone(
                    revoke_saved_gallery_grant(profile, issuer, 7, binding, store),
                )
            remove.assert_called_once()
            revoke.assert_not_called()
            with (
                patch(
                    'uploader.desktop_connection.remove_saved_offline_link',
                    return_value=b'captured-refresh-record',
                ),
                patch(
                    'uploader.desktop_connection.revoke_saved_refresh',
                    side_effect=LinkingError('synthetic refusal'),
                ) as revoke,
            ):
                self.assertIs(
                    False, revoke_saved_gallery_grant(profile, issuer, 7, binding, store),
                )
            revoke.assert_called_once_with(binding, b'captured-refresh-record')

    def test_read_only_persistence_accepts_real_offline_session(self) -> None:
        """Use a real offline-scoped session for read-only saved-grant consent.

        Returns:
            None: Production scope/deadline checks accept the exact session and
                its real identity method uses only a mocked request boundary.
        """
        with tempfile.TemporaryDirectory() as folder:
            profile = Path(folder)
            issuer = 'https://gallery.example.test'
            identity = LinkedIdentity(7, 'synthetic')
            scope = ROLLING_GALLERIES_SCOPE
            session = LinkingSession(
                issuer, 'pgla_' + 'A' * 43,
                time.monotonic() + FIXTURE_REFRESH_LIFETIME_SECONDS, scope,
            )
            self.addCleanup(session.close)
            request = MagicMock(return_value={
                'user': {'id': identity.user_id, 'username': identity.username},
                'scope': scope,
            })
            session._request = request
            store = MagicMock(spec=WindowsCredentialStore)
            store.read.side_effect = [None, b'synthetic-record']
            with (
                patch('uploader.desktop_connection.store_approved_refresh') as save_refresh,
                patch('uploader.desktop_connection.enable_rolling_renewal') as renewal,
            ):
                saved = persist_browser_gallery_grant(
                    profile, store, issuer, identity, session,
                    'pglr_' + 'B' * 43, FIXTURE_REFRESH_LIFETIME_SECONDS, Event(),
                )
            self.assertEqual(OfflineBinding(issuer, 7, scope), saved)
            request.assert_called_once_with('app_linking_identity')
            save_refresh.assert_called_once()
            renewal.assert_called_once()

    def test_historical_upload_hint_keeps_its_original_vault_and_scope(self) -> None:
        """Read the old global hint and refuse an account or capability mismatch.

        Returns:
            None: No newer hint is created and a mismatched caller performs no rotation.
        """
        with tempfile.TemporaryDirectory() as folder:
            profile = Path(folder).resolve()
            issuer = 'https://gallery.example.test'
            identity = LinkedIdentity(7, 'legacy admin')
            scope = READ_UPLOAD_OFFLINE_SCOPE
            mode = ModeRecord('a' * 32, 'b' * 32, 'linked', 'c' * 64, issuer, identity.user_id, scope, 9)
            original_hint = profile / 'offline-link.json'
            binding = OfflineBinding(issuer, identity.user_id, scope)
            save_offline_binding(original_hint, binding)
            original_bytes = original_hint.read_bytes()
            credential = 'pgla_' + 'A' * 43  # Canonical synthetic 256-bit access token.
            access = OfflineAccess(
                issuer, identity.user_id, scope,
                time.monotonic() + FIXTURE_REFRESH_LIFETIME_SECONDS, credential, identity.username,
            )
            vault = MagicMock(spec=WindowsCredentialStore)
            session = MagicMock(spec=LinkingSession)
            session.identity.return_value = identity
            cancelled = Event()
            with (
                patch('uploader.desktop_connection.desktop_upload_vault', return_value=vault),
                patch('uploader.offline_linking.discover_linking_capabilities',
                      return_value=frozenset({'rolling_access'})) as discovery,
                patch('uploader.offline_linking.rotate_saved_offline_access', return_value=access) as rotate,
                patch('uploader.offline_linking.LinkingSession', return_value=session),
            ):
                with self.assertRaises(LinkingError):
                    open_saved_upload_session(profile, mode, cancelled)
                wrong_mode = ModeRecord(
                    mode.profile_id, mode.generation, 'linked', mode.profile_path_hash, issuer,
                    8, scope, mode.gallery_id,
                )
                discovery.return_value = frozenset({'offline_access'})
                with self.assertRaises(LinkingError):
                    open_saved_upload_session(profile, wrong_mode, cancelled)
                rotate.assert_not_called()
                self.assertIs(session, open_saved_upload_session(profile, mode, cancelled))
                rotate.assert_called_once_with(vault, original_hint, binding, cancelled)
                session.enable_renewal.assert_not_called()
                session.identity.return_value = LinkedIdentity(8, 'different account')
                with self.assertRaises(LinkingError):
                    open_saved_upload_session(profile, mode, cancelled)
                session.close.assert_called_once_with()
            self.assertEqual(original_bytes, original_hint.read_bytes())
            self.assertFalse(offline_binding_path(original_hint, issuer).exists())

    def test_historical_upload_reapproval_is_explicit_and_exact(self) -> None:
        """Require the previously selected scope before updating its native record.

        Returns:
            None: Explicit reapproval replaces only the original binding's secret.
        """
        with tempfile.TemporaryDirectory() as folder:
            profile = Path(folder).resolve()
            issuer = 'https://gallery.example.test'
            identity = LinkedIdentity(7, 'legacy admin')
            scope = READ_UPLOAD_OFFLINE_SCOPE
            mode = ModeRecord('a' * 32, 'b' * 32, 'linked', 'c' * 64, issuer, identity.user_id, scope, 9)
            hint = profile / 'offline-link.json'
            binding = OfflineBinding(issuer, identity.user_id, scope)
            save_offline_binding(hint, binding)
            original = hint.read_bytes()
            vault = MagicMock(spec=WindowsCredentialStore)
            session = MagicMock(spec=LinkingSession)
            session.approved_scope.return_value = scope
            # Canonical Base64URL spelling for the fixture's 256-bit secret.
            refresh = 'pglr_' + 'A' * 43
            arguments = profile, vault, issuer, identity, session, refresh, FIXTURE_REFRESH_LIFETIME_SECONDS, Event()
            with self.assertRaises(LinkingError):
                persist_browser_upload_grant(*arguments)
            vault.discard_pending_refresh.assert_not_called()
            vault.write.assert_not_called()
            rolling_hint = offline_binding_path(hint, issuer)
            save_offline_binding(rolling_hint, OfflineBinding(issuer, identity.user_id, ROLLING_UPLOAD_SCOPE))
            with self.assertRaises(LinkingError):
                persist_browser_upload_grant(*arguments, previous_mode=mode)
            vault.discard_pending_refresh.assert_not_called()
            rolling_hint.unlink()
            persist_browser_upload_grant(*arguments, previous_mode=mode)
            vault.discard_pending_refresh.assert_called_once_with(issuer, identity.user_id)
            vault.write.assert_called_once()
            session.enable_renewal.assert_not_called()
            self.assertEqual(original, hint.read_bytes())

    def test_temporary_watcher_close_preserves_the_key_until_confirmed(self) -> None:
        """Use the real capture owner to retain an unconfirmed key closure.

        Returns:
            None: A suspended dependent writer prevents asynchronous closure.
                Retry carries the original key until server confirmation.
        """
        with tempfile.TemporaryDirectory() as folder:
            profile = Path(folder)
            session = MagicMock(spec=LinkingSession)
            session.can_upload.return_value = True
            session.matches_issuer.return_value = True
            target = LinkedUploadTarget(session, 'https://gallery.example.test', 1, 1, 'Root', 'synthetic key')
            with patch.object(MAIN, 'CONFIG_DIR', profile):
                owner = MAIN.DesktopCapture(MagicMock(), MAIN.WatcherConfig, MagicMock(), lambda: None)
                owner.contextual_target = target
                owner._context_cleanup_due = True
                owner.reconcile_workers(retire_context=False)
                session.close_watch_key.assert_not_called()
                session.close_watch_key.side_effect = LinkingError('Synthetic uncertain reply.')
                with self.assertRaises(ValueError):
                    owner.retry_context_cleanup()
                self.assertIs(target, owner.contextual_target)
                self.assertTrue(owner.snapshot().context_cleanup_failed)
                session.close_watch_key.side_effect = None
                owner.retry_context_cleanup()
                self.assertIsNone(owner.contextual_target)
                self.assertFalse(owner.snapshot().context_cleanup_failed)
                self.assertEqual([((1, 'synthetic key'), {})] * 2, session.close_watch_key.call_args_list)

    def test_pdf_creation_requires_the_exact_active_watcher_binding(self) -> None:
        """Prepare an independent report through the existing local writer.

        Returns:
            None: A foreign destination or active recorder is refused, while
                the stopped flight receives the real render-and-upload worker.
        """
        with tempfile.TemporaryDirectory() as folder:
            profile = Path(folder)
            root = profile / 'flights'
            directory = root / 'saved-flight'
            directory.mkdir(parents=True)
            plan = SessionPlan(directory, 'a' * 64, (), False, completed=True)
            plan.save()
            metadata = directory / 'session.json'
            metadata.write_text('{"recording_ended":true}', encoding='ascii')
            target = MAIN.WatchedReportTarget(
                'Fixture gallery', upload_url='https://gallery.example.test', api_key='fixture',
                gui_admission=(MagicMock(), MagicMock()),
            )
            with patch.object(MAIN, 'CONFIG_DIR', profile):
                owner = MAIN.DesktopCapture(MagicMock(), MAIN.WatcherConfig, MagicMock(), lambda: None)
                lease = WorkspaceLease(root)
                owner.lease = lease
                try:
                    with patch.object(owner, '_watched_report_target', return_value=target):
                        with self.assertRaises(ValueError):
                            owner.prepare_report_creation(directory, plan.binding)
                        worker = owner.prepare_report_creation(directory, target.binding())
                        self.assertTrue(worker.local_reports)
                        self.assertIs(target, worker.watched_target)
                        self.assertEqual(directory, worker.plans[0].directory)
                        metadata.write_text('{"recording_ended":false}', encoding='ascii')
                        with self.assertRaises(ValueError):
                            owner.prepare_report_creation(directory, target.binding())
                finally:
                    owner.close()

    def test_startup_handshake_never_accepts_unrelated_nonce(self) -> None:
        """Reject unrelated ready evidence from a previous or foreign child.

        Returns:
            None: The child must respond with this exact launch identity.
        """
        script = (
            "import json, os, pathlib\n"
            f"directory = pathlib.Path(os.environ[{gui_backend.GUI_STARTUP_DIRECTORY!r}])\n"
            f"path = directory / {gui_backend.GUI_STARTUP_RESPONSE!r}\n"
            "path.write_text(json.dumps({'nonce': 'stale', 'state': 'ready', 'reason': None}))\n"
        )
        self.assertEqual("startup_failed", gui_backend.supervise_gui_startup([
            sys.executable, "-c", script,
        ]))
