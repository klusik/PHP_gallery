# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/uploader/qt_gallery_editor.py
# Module Type: Qt Photos gallery metadata editor
# Purpose: Show exact server values before editing one gallery field.
# Responsibilities: Bind the native draft to authorized read and mutation
#   services, retain rejected text and fence conflicting owner changes.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)

"""Present a gallery's current metadata and keep one explicitly submitted draft."""

from dataclasses import dataclass
from threading import Event
from typing import Callable

from PySide6.QtCore import QPoint, Qt, Signal
from PySide6.QtGui import QCloseEvent, QKeySequence, QShortcut
from PySide6.QtWidgets import (
    QComboBox, QDialog, QHBoxLayout, QLabel, QLineEdit, QPlainTextEdit, QVBoxLayout, QWidget,
)

from .linking import LinkingGalleryEditFailure, LinkingSession, gallery_edit_text_valid
from .flight_report_translations import tr
from .localization import gettext
from .qt_read_lane import QtReadLane
from .qt_theme import ActionButton, ToggleSwitch

# Purpose: Keep the anchored native editor readable while allowing localization.
# Type: int. Units: logical pixels. Scope: one Photos metadata form.
# Consumers: QtGalleryEditor.__init__. Rationale: 360 fits a title or short paragraph.
EDITOR_WIDTH = 360


@dataclass(frozen=True)
class GalleryEditResult:
    """Deliver a bounded editor value or an explicit mutation outcome."""

    value: str | bool | None = None
    message: str = ''
    saved: bool = False


class QtGalleryEditor(QDialog):
    """Compose the scoped gallery draft around the existing revision owner."""

    changed = Signal()
    writing_changed = Signal(bool)

    def __init__(
        self, parent: QWidget, session: LinkingSession, gallery: int, field: str,
        caption: str, language: str, current: Callable[[], bool],
    ) -> None:
        """Create an anchored editor before asking the active session for data.

        Args:
            parent (QWidget): Current Photos workspace owning the dialog.
            session (LinkingSession): Verified gallery session captured at opening.
            gallery (int): Exact physical gallery ID.
            field (str): Current editable metadata or visibility field.
            caption (str): Localized action heading.
            language (str): Current per-profile UI language.
            current (Callable[[], bool]): UI-owned recheck of gallery and writer scope.
        Returns:
            None: Nothing is read or written until the owner shows the form.
        """
        super().__init__(parent, Qt.WindowType.Dialog | Qt.WindowType.FramelessWindowHint)
        self.setObjectName('photosGalleryEditor')
        self.setWindowTitle(caption)
        self.setProperty('shellCard', True)
        self.setAttribute(Qt.WidgetAttribute.WA_DeleteOnClose)
        self.setWindowModality(Qt.WindowModality.WindowModal)
        self.setMinimumWidth(EDITOR_WIDTH)
        self.session, self.gallery, self.field = session, gallery, field
        self.language, self._current = language, current
        self._ready = self._writing = self._saved = False
        self._lane = QtReadLane[GalleryEditResult](self._receive, self._settled, self)
        self.finished.connect(lambda _result: self._lane.close())

        layout = QVBoxLayout(self)
        headline = QLabel(caption)
        headline.setObjectName('heading')
        layout.addWidget(headline)
        if field == 'title':
            self.entry: QLineEdit | QPlainTextEdit | ToggleSwitch | QComboBox = QLineEdit()
            self.entry.returnPressed.connect(self._save)
        elif field == 'description':
            self.entry = QPlainTextEdit()
            shortcut = QShortcut(QKeySequence('Ctrl+Return'), self.entry)
            shortcut.activated.connect(self._save)
        elif field == 'visibility':
            self.entry = QComboBox()
            for label, state in (
                (gettext('Public'), 'public'), (gettext('Private'), 'private'),
                (gettext('Unpublished'), 'unpublished'),
            ):
                self.entry.addItem(label, state)
        else:
            self.entry = ToggleSwitch(caption)
        self.entry.setObjectName('photosGalleryEditorValue')
        self.entry.setAccessibleName(caption)
        self.entry.setEnabled(False)
        layout.addWidget(self.entry)
        self.status = QLabel(self._text('working'))
        self.status.setObjectName('status')
        self.status.setTextFormat(Qt.TextFormat.PlainText)
        self.status.setWordWrap(True)
        layout.addWidget(self.status)
        actions = QHBoxLayout()
        actions.addStretch()
        self.save_button = ActionButton(gettext('Save'), self._save, primary=True)
        self.save_button.setEnabled(False)
        self.cancel_button = ActionButton(self._text('cancel'), self.reject)
        actions.addWidget(self.cancel_button)
        actions.addWidget(self.save_button)
        layout.addLayout(actions)

    def _text(self, key: str) -> str:
        """Resolve a caption from the shared localized Photos catalogue.

        Args:
            key (str): Stable suffix of a Photos message.
        Returns:
            str: Localized text for the current studio session.
        """
        return tr(self.language, 'photos_workspace_' + key)

    def present(self, anchor: QPoint) -> None:
        """Show the form at its trigger and request the current server value.

        Args:
            anchor (QPoint): Global logical position of the initiating action.
        Returns:
            None: Starts a cancellable read without delaying the GUI event loop.
        """
        self.adjustSize()
        screen = self.parentWidget().screen().availableGeometry()
        x = min(max(screen.left(), anchor.x()), screen.right() - self.width())
        y = min(max(screen.top(), anchor.y()), screen.bottom() - self.height())
        self.move(x, y)
        self.show()
        self._lane.submit(self._read)

    def busy(self) -> bool:
        """Report whether an authorized write still owns the form.

        Returns:
            bool: True from explicit admission through the terminal callback.
        """
        return self._writing

    def _read(self, cancel: Event, publish: Callable[[GalleryEditResult], None]) -> None:
        """Read only from the currently captured session and physical row.

        Args:
            cancel (Event): Fence for the current editor lifetime.
            publish (Callable[[GalleryEditResult], None]): GUI result receiver.
        Returns:
            None: Supplies the full current field rather than abbreviated tree text.
        """
        if self.field == 'visibility':
            detail = self.session.gallery_detail(self.gallery, cancel)
            publish(GalleryEditResult(value=detail.visibility))
        else:
            snapshot = self.session.read_gallery_edit(self.gallery, cancel)
            if self.field == 'listed' and snapshot.visibility != 'public':
                publish(GalleryEditResult(message='info_unavailable'))
            else:
                publish(GalleryEditResult(value=getattr(snapshot, self.field)))

    def _save(self) -> None:
        """Admit one reviewed local value and protect its pending mutation.

        Returns:
            None: Invalid input or a changed context leaves the existing draft visible.
        """
        if not self._ready or self._writing or not self._current():
            return
        entry = self.entry
        value: str | bool
        if isinstance(entry, QLineEdit):
            value = entry.text()
        elif isinstance(entry, QPlainTextEdit):
            value = entry.toPlainText()
        elif isinstance(entry, ToggleSwitch):
            value = entry.isChecked()
        else:
            submitted = entry.currentData()
            if not isinstance(submitted, str):
                return
            value = submitted
        if self.field in ('title', 'description') and (
            not isinstance(value, str) or not gallery_edit_text_valid(self.field, value)
        ):
            self.status.setText(self._text('edit_invalid'))
            return
        self._writing = True
        self.entry.setEnabled(False)
        self.save_button.setEnabled(False)
        self.cancel_button.setEnabled(False)
        self.status.setText(self._text('working'))
        self.writing_changed.emit(True)
        self._lane.submit(lambda cancel, publish: self._write(value, cancel, publish))

    def _write(
        self, value: str | bool, cancel: Event, publish: Callable[[GalleryEditResult], None],
    ) -> None:
        """Use only the existing field-specific write and reconciliation rules.

        Args:
            value (str | bool): Explicit frontend-local intent.
            cancel (Event): Current operation's ownership fence.
            publish (Callable[[GalleryEditResult], None]): Scoped GUI completion receiver.
        Returns:
            None: Posts a confirmed change, or a conflict message without dropping the draft.
        """
        try:
            if self.field == 'visibility' and isinstance(value, str):
                detail = self.session.gallery_detail(self.gallery, cancel)
                self.session.change_gallery_visibility(detail, value, cancel)
            else:
                self.session.apply_gallery_edit(self.gallery, self.field, value, cancel)
        except LinkingGalleryEditFailure as error:
            publish(GalleryEditResult(message='edit_conflict' if error.conflict else 'edit_uncertain'))
        else:
            publish(GalleryEditResult(saved=True))

    def _receive(self, result: GalleryEditResult) -> None:
        """Populate only the current authorized form and keep rejected drafts intact.

        Args:
            result (GalleryEditResult): Current read value or confirmed write outcome.
        Returns:
            None: Updates fields only on read and surfaces a scoped error on failure.
        """
        if result.message:
            self.status.setText(self._text(result.message))
        self._saved = result.saved
        entry, value = self.entry, result.value
        if value is None or not self._current():
            return
        if isinstance(entry, QLineEdit):
            entry.setText(str(value))
        elif isinstance(entry, QPlainTextEdit):
            entry.setPlainText(str(value))
        elif isinstance(entry, ToggleSwitch):
            entry.setChecked(value is True)
        else:
            entry.setCurrentIndex(entry.findData(value))
        self.status.setText(self._text('edit_online'))
        self._ready = True

    def _settled(self, failure: str) -> None:
        """Release the write barrier and acknowledge only a confirmed change.

        Args:
            failure (str): Sanitized worker failure type, or an empty result.
        Returns:
            None: Restores the editable draft or hands a confirmed refresh to Photos.
        """
        writing, self._writing = self._writing, False
        if writing:
            self.writing_changed.emit(False)
        if failure:
            self.status.setText(self._text('edit_uncertain' if writing else 'info_unavailable'))
        if self._saved:
            self.changed.emit()
            self.accept()
            return
        self.cancel_button.setEnabled(True)
        enabled = self._ready and self._current()
        self.entry.setEnabled(enabled)
        self.save_button.setEnabled(enabled)
        if enabled:
            self.entry.setFocus(Qt.FocusReason.OtherFocusReason)

    def reject(self) -> None:
        """Ignore dismissal while a request still owns its mutation barrier.

        Returns:
            None: Closes an idle or read-only form without submitting anything.
        """
        if not self._writing:
            super().reject()

    def closeEvent(self, event: QCloseEvent) -> None:
        """Keep native dismissal subject to the same accepted-write barrier.

        Args:
            event (QCloseEvent): Native close request from the current parent window.
        Returns:
            None: Closes only after the shared mutation lane has published terminal state.
        """
        if self._writing:
            event.ignore()
        else:
            super().closeEvent(event)
