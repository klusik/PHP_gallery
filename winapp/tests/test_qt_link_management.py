# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/tests/test_qt_link_management.py
# Module Type: Qt link-management regression
# Purpose: Qualify scoped grant controls through the real Settings action.
# Responsibilities: Guard capability completion, stale snapshots and target ownership.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)

"""Exercise the native Settings-to-Photos grant management path."""

import os
from dataclasses import replace
from pathlib import Path
import sys
import tempfile
import time
import unittest
from typing import Callable
from unittest import mock

WINAPP_DIR = Path(__file__).resolve().parents[1]
if str(WINAPP_DIR) not in sys.path:
    sys.path.insert(0, str(WINAPP_DIR))

from native_qt_fixture import NativeQtTestCase
from test_redesign import MAIN
from uploader.credential_store import NativeCredentialStore

QT_LOAD_ERROR: ImportError | OSError | None = None
try:
    from PySide6.QtCore import QEventLoop, QTimer
    from PySide6.QtWidgets import (
        QApplication, QCheckBox, QDialog, QLabel, QPushButton,
    )
    from uploader.linking import (
        ADMIN_MEDIA_SCOPE_SUFFIX, GALLERY_SEARCH_SUFFIX, ROLLING_SCOPE_SUFFIX,
        ROLLING_UPLOAD_SCOPE, LinkingSession, UPLOAD_SCOPE,
    )
    from uploader.config import save_ui_language
    from uploader.desktop_connection import ConnectionActions, RuntimeSettings
    from uploader.flight_report_translations import tr
    from uploader.gallery_view_preferences import PhotosTreePreferences
    from uploader.linked_upload import LinkedUploadTarget
    from uploader.localization import LANGUAGE_NAMES, configure_language, current_language, gettext
    from uploader.offline_linking import OfflineBinding
    from uploader.qt_application import QtWindow
    from uploader.qt_photos import PhotosUploadUpgrade
    from uploader.qt_theme import apply_qt_theme
    from uploader.upload_mode import ModeRecord
except (ImportError, OSError) as error:
    QT_LOAD_ERROR = error
    QApplication = None
    QDialog = object
    QtWindow = object
    PhotosUploadUpgrade = object
    ModeRecord = object
    LinkedUploadTarget = object
    LinkingSession = object
    OfflineBinding = object
    PhotosTreePreferences = object
    ConnectionActions = object

# Purpose: Bound the real public-capability fixture without making network calls.
# Type: int. Units: milliseconds. Scope: one capability result delivery.
# Consumers: QtLinkManagementTests.wait_for.
# Rationale: Five seconds covers hosted Qt startup and queued lane delivery.
CAPABILITY_WAIT_MS = 5000
# Purpose: Yield between checks while the real Qt event loop delivers worker results.
# Type: int. Units: milliseconds. Scope: one management-dialog observation.
# Consumers: QtLinkManagementTests.wait_for.
# Rationale: 20 ms avoids a busy loop while keeping the native test responsive.
CAPABILITY_POLL_MS = 20
# Purpose: Keep the synthetic refresh valid while testing the normal GUI handoff.
# Type: int. Units: seconds. Scope: native linked Admin consent fixture.
# Consumers: QtLinkManagementTests._admin_upgrade_handoff. Rationale: 60
# exceeds the native dialog and source fixture's setup time without waiting.
SYNTHETIC_REFRESH_LIFETIME = 60


@unittest.skipIf(
    QApplication is None and os.environ.get('PHP_GALLERY_REQUIRE_QT') != '1',
    'Optional Qt dependencies are absent.',
)
class QtLinkManagementTests(NativeQtTestCase):
    """Keep the explicit grant UI tied to current shared connection owners."""

    @classmethod
    def setUpClass(cls) -> None:
        """Create the isolated fixture's QApplication and shared theme.

        Returns:
            None: The native child owns all modal widget interactions.
        """
        if QApplication is None:
            raise AssertionError('Hosted Qt dependencies are required.') from QT_LOAD_ERROR
        if cls.delegate_native_cases():
            return
        cls.application = QApplication.instance() or QApplication([])
        cls.application.setQuitOnLastWindowClosed(False)
        apply_qt_theme(cls.application)

    def wait_for(self, condition: Callable[[], bool]) -> None:
        """Wait for queued capability discovery to update native controls.

        Args:
            condition (Callable[[], bool]): The exact requested widget state.
        Returns:
            None: Returns when the condition is true or fails at the bound.
        """
        if condition():
            return
        loop = QEventLoop()
        poll = QTimer()
        poll.setInterval(CAPABILITY_POLL_MS)
        poll.timeout.connect(lambda: loop.quit() if condition() else None)
        deadline = QTimer()
        deadline.setSingleShot(True)
        deadline.timeout.connect(loop.quit)
        poll.start()
        deadline.start(CAPABILITY_WAIT_MS)
        loop.exec()
        poll.stop()
        deadline.stop()
        self.assertTrue(condition())

    def _window(
        self, folder: str, state: dict[str, object], selected: mock.Mock,
        *, language: str = 'en',
    ) -> QtWindow:
        """Create a real Settings and Photos shell with observable mode owners.

        Args:
            folder (str): Temporary profile directory for this native fixture.
            state (dict[str, object]): Mutable saved and active snapshots.
            selected (mock.Mock): Upload target writer boundary to monitor.
            language (str): Persisted native application language for this profile.
        Returns:
            QtWindow: Visible shell using the real Settings Manage link button.
        """
        connections = ConnectionActions(
            selected, mock.Mock(), lambda: state['saved'], lambda: state['active'],
        )
        address = 'https://gallery.example.test/index.php'
        config = MAIN.WatcherConfig()
        config.gallery_url = address
        profile = Path(folder)
        save_ui_language(profile, language)
        settings = RuntimeSettings(
            config.to_dict, lambda values: {**config.to_dict(), **values},
        )
        window = QtWindow(
            profile, '1.8.0', address, 100, False,
            connections=connections, runtime_settings=settings,
        )
        self.addCleanup(window.shutdown)
        self.addCleanup(window.close)
        window.show()
        window.show_page('settings')
        QApplication.processEvents()
        return window

    def _open_manage_link(self, window: QtWindow) -> QDialog:
        """Open the modal from its real Settings action and return its dialog.

        Args:
            window (QtWindow): Visible native shell with a Manage link action.
        Returns:
            QDialog: The visible, issuer-bound Photos scope dialog.
        """
        button = window.settings.manage_links_button
        self.assertIsNotNone(button)
        assert button is not None
        button.click()
        dialog = window.photos._scope_dialog
        self.assertIsNotNone(dialog)
        assert dialog is not None
        self.assertTrue(dialog.isVisible(), 'The Settings action did not show its permission window.')
        self.assertIs(dialog.parentWidget(), window)
        return dialog

    def _admin_upgrade_handoff(
        self, window: QtWindow, state: dict[str, object],
    ) -> tuple[
        PhotosUploadUpgrade, ModeRecord, LinkedUploadTarget,
        LinkingSession, LinkingSession, OfflineBinding, mock.Mock,
    ]:
        """Prepare a real modal and typed renewal result for one owner fixture.

        Args:
            window (QtWindow): Visible Qt shell retaining the existing Photos grant.
            state (dict[str, object]): Current mode/target owner snapshots.
        Returns:
            tuple: Handoff, prior mode/target, sessions, binding, and owner callback.
        """
        issuer, account, gallery = 'https://gallery.example.test', 7, 31
        previous_scope = ROLLING_UPLOAD_SCOPE
        approved_scope = UPLOAD_SCOPE + ADMIN_MEDIA_SCOPE_SUFFIX + ROLLING_SCOPE_SUFFIX
        previous_session = mock.Mock(spec=LinkingSession)
        previous_session.available.return_value = True
        previous_session.matches_issuer.return_value = True
        previous_session.can_upload.return_value = True
        previous_session.approved_scope.return_value = previous_scope
        previous_target = LinkedUploadTarget(
            previous_session, issuer, account, gallery, 'Target gallery',
        )
        previous_mode = ModeRecord(
            'a' * 32, 'b' * 32, 'linked', 'c' * 64, issuer, account,
            previous_scope, gallery,
        )
        new_session = mock.Mock(spec=LinkingSession)
        new_session.available.return_value = True
        new_session.matches_issuer.return_value = True
        new_session.can_upload.return_value = True
        new_session.can_read_admin_media.return_value = True
        new_session.can_list_galleries.return_value = True
        new_session.approved_scope.return_value = approved_scope
        new_target = LinkedUploadTarget(
            new_session, issuer, account, gallery, 'Target gallery',
        )
        binding = OfflineBinding(issuer, account, approved_scope)
        updated_mode = ModeRecord(
            previous_mode.profile_id, 'd' * 32, 'linked',
            previous_mode.profile_path_hash, issuer, account, approved_scope, gallery,
        )
        state['saved'] = previous_mode
        state['active'] = previous_target

        def commit_scope_upgrade(
            expected_mode: ModeRecord, expected_target: LinkedUploadTarget,
            target: LinkedUploadTarget,
        ) -> bool:
            """Publish only the exact same selected issuer/account/gallery fixture.

            Args:
                expected_mode (ModeRecord): Captured prior linked selection.
                expected_target (LinkedUploadTarget): Captured live target.
                target (LinkedUploadTarget): Fresh server-verified replacement.
            Returns:
                bool: True only after the fixture's exact expected state matches.
            """
            if (state['saved'] != expected_mode or state['active'] is not expected_target
                    or target is not new_target):
                return False
            state['saved'] = updated_mode
            state['active'] = new_target
            return True

        commit = mock.Mock(side_effect=commit_scope_upgrade)
        window.photos.connections = ConnectionActions(
            mock.Mock(), mock.Mock(), lambda: state['saved'],
            lambda: state['active'], commit_scope_upgrade=commit,
        )
        window.photos._session = previous_session
        window.photos._identity = issuer, account
        window.photos._open_authorization_dialog(
            True, previous_mode, (issuer, account), False,
        )
        dialog = window.photos._authorization_dialog
        self.assertIsNotNone(dialog)
        assert dialog is not None
        self.assertTrue(dialog.isVisible())
        handoff = PhotosUploadUpgrade(
            new_session, previous_session, new_target, previous_mode,
            previous_target, binding, mock.Mock(), previous_scope,
            'refresh-fixture-only', time.monotonic() + SYNTHETIC_REFRESH_LIFETIME,
            (), PhotosTreePreferences(),
        )
        return (
            handoff, previous_mode, previous_target, previous_session,
            new_session, binding, commit,
        )

    def test_admin_scope_upgrade_commits_same_target_through_held_dialog(self) -> None:
        """Replace rolling scope and mode only after the exact modal handoff.

        Returns:
            None: The native vault transaction invokes the same-target owner commit.
        """
        state: dict[str, object] = {'saved': None, 'active': None}
        with tempfile.TemporaryDirectory() as folder:
            window = self._window(folder, state, mock.Mock())
            (handoff, previous_mode, previous_target, previous_session,
             new_session, _binding, commit) = self._admin_upgrade_handoff(window, state)

            def persist_upgrade(
                _path: Path, _store: NativeCredentialStore, _binding: OfflineBinding,
                previous_scope: str, _refresh: str, _remaining: int,
                *, commit: Callable[[], bool] | None = None,
            ) -> None:
                """Exercise the mode publication callback at the vault boundary.

                Args:
                    _path (Path): Existing profile hint owner supplied by Photos.
                    _store (NativeCredentialStore): Existing native vault owner.
                    _binding (OfflineBinding): New exact saved scope binding.
                    previous_scope (str): Scope whose native credential is replaced.
                    _refresh (str): New fixture refresh value.
                    _remaining (int): Remaining fixture lifetime in seconds.
                    commit (Callable[[], bool] | None): Exact existing mode owner.
                Returns:
                    None: Fails unless the captured mode and target commit succeeds.
                """
                self.assertEqual(previous_mode.scope, previous_scope)
                self.assertIsNotNone(commit)
                assert commit is not None
                self.assertTrue(commit())

            with (
                mock.patch(
                    'uploader.photos.qt.media.saved_desktop_gallery_binding',
                    return_value=OfflineBinding(
                        handoff.binding.issuer, handoff.binding.account_id,
                        handoff.previous_scope,
                    ),
                ),
                mock.patch(
                    'uploader.photos.qt.media.persist_browser_scope_upgrade',
                    side_effect=persist_upgrade,
                ) as persist,
            ):
                dialog = window.photos._authorization_dialog
                assert dialog is not None
                self.assertTrue(dialog.isVisible())
                window.photos._finish_admin_upload_upgrade(handoff)

            self.assertEqual(previous_mode.scope, handoff.previous_scope)
            self.assertEqual(state['saved'].scope, handoff.binding.scope)
            self.assertIs(state['active'].session, new_session)
            self.assertIs(window.photos._session, new_session)
            self.assertFalse(dialog.isVisible())
            persist.assert_called_once()
            commit.assert_called_once_with(previous_mode, previous_target, handoff.target)
            previous_session.disable_renewal.assert_called_once()
            new_session.enable_renewal.assert_called_once()
            new_session.close.assert_not_called()

    def test_admin_scope_upgrade_refuses_stale_mode_target_and_binding(self) -> None:
        """Keep the old target when any captured renewal owner has changed.

        Returns:
            None: Each stale case leaves the modal and old Photos session intact.
        """
        stale_mode = ModeRecord(
            'e' * 32, 'f' * 32, 'linked', 'c' * 64,
            'https://gallery.example.test', 7,
            ROLLING_UPLOAD_SCOPE, 31,
        )
        for changed in ('mode', 'target', 'binding', 'deadline'):
            with self.subTest(changed=changed), tempfile.TemporaryDirectory() as folder:
                state: dict[str, object] = {'saved': None, 'active': None}
                window = self._window(folder, state, mock.Mock())
                (handoff, previous_mode, previous_target, previous_session,
                 new_session, _binding, commit) = self._admin_upgrade_handoff(window, state)
                binding = OfflineBinding(
                    handoff.binding.issuer, handoff.binding.account_id,
                    handoff.previous_scope,
                )
                if changed == 'mode':
                    state['saved'] = stale_mode
                elif changed == 'target':
                    state['active'] = object()
                elif changed == 'binding':
                    binding = OfflineBinding(
                        binding.issuer, binding.account_id,
                        UPLOAD_SCOPE + GALLERY_SEARCH_SUFFIX + ROLLING_SCOPE_SUFFIX,
                    )
                else:
                    handoff = replace(handoff, refresh_deadline=time.monotonic())
                with (
                    mock.patch(
                        'uploader.photos.qt.media.saved_desktop_gallery_binding',
                        return_value=binding,
                    ),
                    mock.patch(
                        'uploader.photos.qt.media.persist_browser_scope_upgrade',
                    ) as persist,
                ):
                    dialog = window.photos._authorization_dialog
                    assert dialog is not None
                    self.assertTrue(dialog.isVisible())
                    window.photos._finish_admin_upload_upgrade(handoff)
                persist.assert_not_called()
                commit.assert_not_called()
                self.assertIs(window.photos._session, previous_session)
                self.assertTrue(dialog.isVisible())
                new_session.close.assert_called_once()
                expected_mode = stale_mode if changed == 'mode' else previous_mode
                self.assertEqual(expected_mode, state['saved'])
                if changed == 'target':
                    self.assertIsNot(state['active'], previous_target)
                else:
                    self.assertIs(state['active'], previous_target)
                dialog.reject()

    def test_capabilities_enable_explicit_scope_without_selecting_upload_target(self) -> None:
        """Drive capability discovery, permission dependencies and exact start.

        Returns:
            None: Explicit consent starts with the chosen scope and does not
                write or replace the shared physical upload target.
        """
        capabilities = frozenset({
            'galleries:list', 'gallery:upload', 'gallery:read',
            'galleries:create', 'gallery:visibility', 'media:read',
        })
        state: dict[str, object] = {'saved': None, 'active': None}
        selected = mock.Mock()
        with tempfile.TemporaryDirectory() as folder:
            window = self._window(folder, state, selected)
            with mock.patch(
                'uploader.photos.qt.link_management.discover_linking_capabilities',
                return_value=capabilities,
            ):
                dialog = self._open_manage_link(window)
                update = dialog.findChild(QPushButton, 'photosLinkScopeUpdate')
                browse = dialog.findChild(QCheckBox, 'photosLinkScopeBrowse')
                upload = dialog.findChild(QCheckBox, 'photosLinkScopeUpload')
                self.assertIsNotNone(update)
                self.assertIsNotNone(browse)
                self.assertIsNotNone(upload)
                assert update is not None and browse is not None and upload is not None
                self.wait_for(update.isEnabled)
                self.assertTrue(upload.isEnabled(), 'Discovered gallery uploads remain disabled.')
                upload.click()
                self.assertTrue(upload.isChecked(), 'The operator upload checkbox did not toggle.')
                self.assertTrue(browse.isChecked(), 'Upload must also request gallery listing.')
                self.assertTrue(update.isEnabled(), 'A valid upload scope must remain submittable.')
                with mock.patch.object(window.photos, '_authorize') as authorize:
                    update.click()
                    authorize.assert_called_once_with(
                        True,
                        address_override='https://gallery.example.test',
                        requested_scope=UPLOAD_SCOPE,
                    )
                self.assertIsNone(window.photos._scope_dialog)
                self.assertIsNone(state['saved'])
                self.assertIsNone(state['active'])
                selected.assert_not_called()

    def test_stale_active_target_refuses_scope_start(self) -> None:
        """Reject a grant submission after the shared target snapshot changes.

        Returns:
            None: The dialog remains available and no authorization or target
                writer runs against stale mode state.
        """
        state: dict[str, object] = {'saved': None, 'active': None}
        selected = mock.Mock()
        with tempfile.TemporaryDirectory() as folder:
            window = self._window(folder, state, selected)
            with mock.patch(
                'uploader.photos.qt.link_management.discover_linking_capabilities',
                return_value=frozenset({'galleries:list', 'gallery:upload'}),
            ):
                dialog = self._open_manage_link(window)
                update = dialog.findChild(QPushButton, 'photosLinkScopeUpdate')
                self.assertIsNotNone(update)
                assert update is not None
                self.wait_for(update.isEnabled)
                state['active'] = object()
                with mock.patch.object(window.photos, '_authorize') as authorize:
                    update.click()
                    authorize.assert_not_called()
                self.assertTrue(dialog.isVisible())
                status = dialog.findChild(QLabel, 'photosLinkManagementStatus')
                self.assertIsNotNone(status)
                assert status is not None
                self.assertEqual('Gallery connection is unavailable.', status.text())
                selected.assert_not_called()

    def test_closing_read_only_live_grant_does_not_disconnect_it(self) -> None:
        """Keep an existing current grant unchanged when its dialog is closed.

        Returns:
            None: Granted permissions are disabled and dismissing the dialog
                does not close or replace the active Photos session.
        """
        state: dict[str, object] = {'saved': None, 'active': None}
        selected = mock.Mock()
        session = mock.Mock(spec=LinkingSession)
        session.available.return_value = True
        session.approved_scope.return_value = UPLOAD_SCOPE
        session.can_list_galleries.return_value = True
        session.can_upload.return_value = True
        session.can_read_gallery_detail.return_value = False
        session.can_create_gallery.return_value = False
        session.can_change_gallery_visibility.return_value = False
        session.can_read_media.return_value = False
        with tempfile.TemporaryDirectory() as folder:
            window = self._window(folder, state, selected)
            window.photos._session = session
            window.photos._identity = ('https://gallery.example.test', 7)
            dialog = self._open_manage_link(window)
            upload = dialog.findChild(QCheckBox, 'photosLinkScopeUpload')
            self.assertIsNotNone(upload)
            assert upload is not None
            self.assertTrue(upload.isChecked(), 'The active upload permission is missing from the dialog.')
            self.assertFalse(upload.isEnabled(), 'The current live grant must be read-only.')
            dialog.reject()
            QApplication.processEvents()
            self.assertIs(window.photos._session, session)
            session.close.assert_not_called()
            selected.assert_not_called()

    def test_permission_captions_are_localized_in_every_desktop_language(self) -> None:
        """Open the native Settings grant editor under each registered language.

        Returns:
            None: Window title and visible permission controls use the catalogue
                of the current native frontend without choosing an upload target.
        """
        self.addCleanup(configure_language, current_language())
        for language in LANGUAGE_NAMES:
            with self.subTest(language=language), tempfile.TemporaryDirectory() as folder:
                configure_language(language)
                selected = mock.Mock()
                window = self._window(
                    folder, {'saved': None, 'active': None}, selected, language=language,
                )
                try:
                    with mock.patch(
                        'uploader.photos.qt.link_management.discover_linking_capabilities',
                        return_value=frozenset({'galleries:list', 'gallery:upload'}),
                    ):
                        dialog = self._open_manage_link(window)
                        upload = dialog.findChild(QCheckBox, 'photosLinkScopeUpload')
                        update = dialog.findChild(QPushButton, 'photosLinkScopeUpdate')
                        assert upload is not None and update is not None
                        self.wait_for(update.isEnabled)
                        self.assertEqual(gettext('Manage link'), dialog.windowTitle())
                        self.assertEqual(gettext('Allow gallery uploads'), upload.text())
                        self.assertEqual(tr(language, 'linking_start'), update.text())
                        self.assertTrue(upload.isVisibleTo(dialog))
                        self.assertTrue(update.isVisibleTo(dialog))
                        if language != 'en':
                            self.assertNotEqual('Manage link', dialog.windowTitle())
                        dialog.reject()
                        selected.assert_not_called()
                finally:
                    window.exit_window()
