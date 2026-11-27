# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/uploader/qt_update.py
# Module Type: Qt update presentation
# Purpose: Discover, verify, download and hand off supported WinApp updates.
# Responsibilities: Present progress, require quiescence and use the existing
#   verified native installer helper without a second lifecycle owner.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)

"""Present the shared verified updater through native Qt controls."""

import os
from pathlib import Path
import sys
from threading import Event
import time
from typing import Callable, Literal

from PySide6.QtCore import Qt, QTimer, QUrl, Signal
from PySide6.QtGui import QDesktopServices
from PySide6.QtWidgets import (
    QDialog, QHBoxLayout, QLabel, QMessageBox, QProgressBar, QVBoxLayout, QWidget,
)

from . import self_update, update_helper
from .config import APP_PROFILE_NAME
from .localization import gettext
from .qt_read_lane import QtReadLane
from .qt_theme import ActionButton
from .self_update import InstallGate, ReleaseAsset
from .windows_startup import ACTIVATION_POLL_MS

UpdateOperation = Literal['', 'check', 'download', 'install']
UpdateMessage = ReleaseAsset | Path | bool


class QtUpdater(QDialog):
    """Keep the operator-visible update state with the shared installer owner."""

    gate_changed = Signal(bool)
    ready_to_exit = Signal()
    release_changed = Signal(str)

    def __init__(
        self, profile: Path, version: str, protected: Callable[[], bool], parent: QWidget,
    ) -> None:
        """Create one native update surface and an independent worker lane.

        Args:
            profile (Path): Current WinApp profile used for source-mode fallback.
            version (str): Current application version.
            protected (Callable[[], bool]): Whether accepted work still has a writer.
            parent (QWidget): Owning native application window.
        Returns:
            None: Assumes no update and performs no automatic request.
        """
        super().__init__(parent)
        self.version = version
        self.protected = protected
        self.installed = bool(getattr(sys, 'frozen', False))
        local = Path(os.environ.get('LOCALAPPDATA') or profile.parent)
        self.directory = local / APP_PROFILE_NAME / 'updates'
        self.gate = InstallGate()
        self.operation: UpdateOperation = ''
        self.asset: ReleaseAsset | None = None
        self.installer: Path | None = None
        self._progress = (0, 0)
        self._download_cancel = Event()
        self._helper_ready = False
        self._external_opened = False
        self.capture_checkpoint: Callable[[], Callable[[Path], None] | None] = lambda: None
        self._lane = QtReadLane[UpdateMessage](self._receive, self._finished, self)
        self._poll = QTimer(self)
        self._poll.setInterval(ACTIVATION_POLL_MS)
        self._poll.timeout.connect(self._show_progress)
        self.setWindowTitle(gettext('PHP Gallery Uploader updates'))
        layout = QVBoxLayout(self)
        self.status = QLabel()
        self.status.setWordWrap(True)
        self.status.setTextFormat(Qt.TextFormat.PlainText)
        layout.addWidget(self.status)
        self.progress = QProgressBar()
        self.progress.setAccessibleName(gettext('Update'))
        self.progress.hide()
        layout.addWidget(self.progress)
        buttons = QHBoxLayout()
        self.check_button = ActionButton(gettext('Check for updates'), self.check)
        self.download_button = ActionButton(gettext('Download update'), self.download, primary=True)
        self.install_button = ActionButton(
            gettext('Install update') if self.installed else gettext('Open installer'),
            self.install, primary=True,
        )
        self.cancel_button = ActionButton(gettext('Cancel download'), self.cancel_download)
        for control in (self.check_button, self.download_button, self.install_button, self.cancel_button):
            buttons.addWidget(control)
        layout.addLayout(buttons)
        self._controls()

    def busy(self) -> bool:
        """Keep the owner alive while its update operation is still in progress.

        Returns:
            bool: A request, download or prepared handoff owns work.
        """
        return bool(self.operation)

    def present(self) -> None:
        """Show current status and start discovery when no offer is present.

        Returns:
            None: Reveals the single persistent update surface.
        """
        self.show()
        self.raise_()
        if not self.busy() and self.asset is None:
            self.check()

    def _controls(self) -> None:
        """Reflect readiness in the actionable update controls.

        Returns:
            None: Exposes only actions that the current stage can admit.
        """
        idle = not self.busy()
        self.check_button.setEnabled(idle)
        self.download_button.setVisible(self.asset is not None and self.installer is None)
        self.download_button.setEnabled(idle)
        self.install_button.setVisible(self.installer is not None)
        self.install_button.setEnabled(idle)
        self.cancel_button.setVisible(self.operation == 'download')
        self.cancel_button.setEnabled(not self._download_cancel.is_set())

    def check(self) -> None:
        """Discover the latest eligible release for the executing installation.

        Returns:
            None: Fetches bounded public metadata using the existing client.
        """
        if self.busy():
            return
        self.operation = 'check'
        self.status.setText(gettext('Checking for updates…'))
        self._controls()
        version, installed = self.version, self.installed
        self._lane.submit(lambda cancelled, publish: publish(
            self_update.find_update(version) or False,
        ) if not cancelled.is_set() else None)

    def download(self) -> None:
        """Download only the current validated release into an owned session.

        Returns:
            None: Leaves a verified installer path or retires a failed partial.
        """
        asset = self.asset
        if asset is None or self.busy() or self.installer is not None:
            return
        self.operation = 'download'
        self._download_cancel.clear()
        self._progress = (0, asset.size)
        self.progress.show()
        self._show_progress()
        self._poll.start()
        self._controls()

        def run(_cancelled: Event, publish: Callable[[UpdateMessage], None]) -> None:
            """Stream and verify a downloaded installer off the event loop.

            Args:
                _cancelled (Event): Lane lifecycle fence.
                publish (Callable[[UpdateMessage], None]): Exact verified path sink.
            Returns:
                None: Transfers ownership of a verified download to this view.
            """
            self_update.cleanup_old_download_sessions(self.directory, time.time())
            directory = self_update.create_download_session(self.directory)
            try:
                publish(self_update.download_update(
                    asset, directory, self._store_progress, self._download_cancel,
                ))
            except Exception:
                self_update.retire_download_session(directory, self.directory)
                raise

        self._lane.submit(run)

    def _store_progress(self, done: int, total: int) -> None:
        """Coalesce replaceable network byte progress on the worker thread.

        Args:
            done (int): Verified transfer bytes currently written.
            total (int): Published installer byte count.
        Returns:
            None: Replaces one immutable scalar sample without GUI access.
        """
        self._progress = (done, total)

    def _show_progress(self) -> None:
        """Display the latest transfer sample using the existing byte caption.

        Returns:
            None: Updates Qt controls on their owning GUI thread.
        """
        done, total = self._progress
        self.progress.setRange(0, total)
        self.progress.setValue(done)
        self.status.setText(gettext('Downloading installer: {done:,} / {total:,} bytes').format(
            done=done, total=total,
        ))

    def cancel_download(self) -> None:
        """Request cancellation before the verified artifact is published.

        Returns:
            None: Leaves cleanup with the worker that owns the partial download.
        """
        if self.operation == 'download':
            self._download_cancel.set()
            self.cancel_button.setEnabled(False)

    def install(self) -> None:
        """Verify and hand off the downloaded installer under a real work gate.

        Returns:
            None: Starts the installed helper or explicitly opens Setup from source.
        """
        if self.installer is None or self.asset is None or self.busy():
            return
        if self.protected():
            self.status.setText(gettext('Wait for the current operation to finish.'))
            return
        caption = self.install_button.text()
        if QMessageBox.question(
            self, caption, self.status.text(), QMessageBox.StandardButton.Yes | QMessageBox.StandardButton.No,
            QMessageBox.StandardButton.No,
        ) != QMessageBox.StandardButton.Yes or self.protected():
            return
        checkpoint = self.capture_checkpoint() if self.installed else None
        self.operation = 'install'
        self.gate.begin()
        self.gate_changed.emit(True)
        self._controls()
        self.status.setText(gettext('Preparing installation…'))
        installer, asset = self.installer, self.asset

        def run(_cancelled: Event, publish: Callable[[UpdateMessage], None]) -> None:
            """Revalidate the download and let the stable native helper install.

            Args:
                _cancelled (Event): Exact action lifetime.
                publish (Callable[[UpdateMessage], None]): Confirmed handoff sink.
            Returns:
                None: The helper is ready, or the source dialog can open Setup.
            """
            self_update.verify_installer(installer, asset)
            if checkpoint is not None:
                ticket = update_helper.prepare_helper(installer, asset.version, asset.sha256, asset.size)
                checkpoint(ticket)
                update_helper.launch_helper(ticket)
                publish(True)
            elif self.installed:
                update_helper.launch_update(installer, asset)
                publish(True)
            else:
                publish(installer)

        self._lane.submit(run)

    def _receive(self, result: UpdateMessage) -> None:
        """Admit only the current release, verified file or helper completion.

        Args:
            result (UpdateMessage): Release, installer, or stage completion flag.
        Returns:
            None: Presents the next eligible explicit update action.
        """
        if isinstance(result, ReleaseAsset):
            if result != self.asset:
                self._retire_download()
            self.asset = result
            self.status.setText(gettext('Update available: {version}').format(version=result.version))
            self.release_changed.emit(result.version)
        elif result is False:
            self._retire_download()
            self.asset = None
            self.status.setText(gettext('WinApp is up to date.'))
            self.release_changed.emit('')
        elif result is True:
            self._helper_ready = True
        elif self.operation == 'install':
            self._external_opened = QDesktopServices.openUrl(QUrl.fromLocalFile(str(result)))
        else:
            self.installer = result

    def _finished(self, failure: str) -> None:
        """Reopen commands after the current phase has definitively settled.

        Args:
            failure (str): Safe exception category or empty on success.
        Returns:
            None: Reports status, restores gating, or initiates normal exit.
        """
        operation, self.operation = self.operation, ''
        self._poll.stop()
        self.progress.hide()
        if failure:
            if operation == 'download' and self._download_cancel.is_set():
                self.status.setText(gettext('Update canceled before installation.'))
            else:
                self.status.setText(gettext('Could not check for updates.') if operation == 'check'
                                    else gettext('Update stopped: {detail}').format(detail=failure))
        elif operation == 'download' and self.installer is not None:
            self.status.setText(gettext('Installer verified.\nUpdate closes, installs and restarts the app.')
                                if self.installed else gettext(
                                    'Installer verified. Open installer starts manual installation; this source application remains open.',
                                ))
        if self.gate.active:
            self.gate.cancel()
            self.gate_changed.emit(False)
        self._controls()
        if self._helper_ready:
            self.hide()
            self.ready_to_exit.emit()

    def shutdown(self) -> None:
        """Withdraw the updater after admitted work has completed.

        Returns:
            None: Leaves an external installer intact and retires owned downloads.
        """
        self._poll.stop()
        self._download_cancel.set()
        self._lane.close()
        self._retire_download()
        self.hide()

    def _retire_download(self) -> None:
        """Retire only a superseded or consumed session still owned by Qt.

        Returns:
            None: A manually opened installer keeps its path through launch.
        """
        installer, self.installer = self.installer, None
        if installer is not None and not self._external_opened:
            try:
                self_update.retire_download_session(installer.parent, self.directory)
            except (OSError, RuntimeError, ValueError):
                pass
        self._external_opened = False
