# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/uploader/qt_settings.py
# Module Type: Qt interface settings
# Purpose: Configure the frontend with the same locale and restart preferences.
# Responsibilities: Save independent interface choices, offer restart and
#   cancel pending changes while retaining stable Tkinter rollback.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)

"""Present independent profile preferences in Qt."""

from dataclasses import dataclass
import logging
from pathlib import Path
from threading import Event
from typing import Callable

from PySide6.QtCore import QLocale, Qt, QTimer, QUrl, Signal
from PySide6.QtGui import QDesktopServices, QFontMetrics, QKeySequence
from PySide6.QtWidgets import (
    QApplication, QCheckBox, QComboBox, QDialog, QFileDialog, QFormLayout, QFrame,
    QHBoxLayout, QLabel, QLineEdit, QMessageBox, QScrollArea, QSizePolicy, QVBoxLayout, QWidget,
)

from .config import (
    BYTES_PER_GIB, MAX_THUMBNAIL_WORKERS, MAX_UPLOAD_WORKERS, THUMBNAIL_BATCH_CHOICES,
    THUMBNAIL_CACHE_LIMIT_CHOICES_GIB,
    load_thumbnail_batch_size, load_ui_language, save_thumbnail_batch_size,
    save_ui_language, set_thumbnail_cache_limit_gib, worker_choices,
)
from .desktop_connection import (
    ConnectionActions, ConnectionTestResult, RuntimeSettings, RuntimeValues,
)
from .flight_report_translations import tr
from .gallery_information import format_gallery_bytes
from .gallery_media_cache import GalleryMediaCache, MEDIA_CACHE_MAX_BYTES
from .gui_backend import GUI_BACKENDS, GuiBackend, load_gui_backend, save_gui_backend
from .gui_backend_labels import backend_label, backend_status
from .localization import LANGUAGE_NAMES, current_language, gettext, gettext_noop
from .qt_read_lane import QtReadLane
from .qt_theme import ActionButton, ToggleSwitch
from .ui_layout import WATCHER_SETTINGS_MINIMUM
from .watcher_profile import WATCHER_PREFERENCE_TYPES
from .windows_startup import startup_supported

ARCHIVE_BYTE_FIELDS = frozenset(('archive_max_uncompressed_bytes', 'archive_max_file_bytes'))

# Purpose: Match the Tk settings card's quiet content inset.
# Type: tuple[int, int, int, int]. Units: logical pixels, left/top/right/bottom.
# Scope: Qt Settings cards. Consumers: QtSettings section forms.
# Rationale: 14/10 px keeps headings and fields compact inside the card border.
SETTINGS_CARD_INSETS = (14, 10, 14, 10)
# Purpose: Align Settings editors to the reference Tk label column.
# Type: int. Units: logical pixels. Scope: paired Settings labels and editors.
# Consumers: QtSettings._add_runtime_forms. Rationale: 127 px reserves the
# shared column for short setting labels without stretching input controls.
SETTINGS_LABEL_MIN_WIDTH_PX = 127
# Purpose: Preserve the field separation used by the Tk Settings section.
# Type: int. Units: logical pixels. Scope: Qt Settings integration fields.
# Consumers: QtSettings._integration_form. Rationale: Tk uses an 8 px field inset.
SETTINGS_EDITOR_GAP_PX = 8
# Purpose: Bound pickers that are compact in the stable Tk settings card.
# Type: int. Units: logical pixels. Scope: language selector.
# Consumers: QtSettings.__init__. Rationale: 175 px fits localized language names.
SETTINGS_LANGUAGE_WIDTH_PX = 175
# Purpose: Keep the thumbnail batch picker at its compact reference width.
# Type: int. Units: logical pixels. Scope: thumbnails-per-request selector.
# Consumers: QtSettings.__init__. Rationale: 96 px fits all supported counts.
SETTINGS_BATCH_WIDTH_PX = 96
# Purpose: Keep worker and numeric settings fields from filling the card.
# Type: int. Units: logical pixels. Scope: bounded numeric form editors.
# Consumers: QtSettings._add_runtime_forms. Rationale: 180 px matches the
# reference entry width while leaving space for field labels and helper text.
SETTINGS_NUMERIC_FIELD_WIDTH_PX = 180
# Purpose: Give explicit primary Settings saves the same substantial target as Tk.
# Type: tuple[int, int]. Units: logical pixels. Scope: Save & Test and Save.
# Consumers: QtSettings._add_runtime_forms. Rationale: 128×42 matches the
# compact primary action in the hosted Tk Settings reference.
SETTINGS_PRIMARY_MINIMUM = (128, 42)
# Purpose: Match the compact row rhythm in the Tk connection card.
# Type: int. Units: logical pixels. Scope: Qt Gallery Connection form rows.
# Consumers: QtSettings._add_runtime_forms. Rationale: 7 px aligns the spaced
# connection rows after removing the empty group-box legend's unused inset.
SETTINGS_CONNECTION_FORM_SPACING_PX = 7


@dataclass(frozen=True)
class StorageObservation:
    """Carry only counts and byte totals from the existing thumbnail cache owner."""

    ordinary: tuple[int | None, int | None, int | None, bool, str | None]
    retained: tuple[int | None, int | None, int | None, int | None, int | None, bool]


class QtSettings(QScrollArea):
    """Offer one authoritative persisted selection for each interface setting."""

    batch_changed = Signal(int)
    runtime_changed = Signal(str, bool)
    manage_links_requested = Signal()
    connection_changed = Signal()
    revoke_requested = Signal()
    storage_observed = Signal(str)

    def __init__(
        self, profile: Path, restart: Callable[[], None], runtime: RuntimeSettings | None = None,
        protected: Callable[[], bool] | None = None,
        connections: ConnectionActions | None = None,
    ) -> None:
        """Compose interface choices and the admitted shared uploader settings.

        Args:
            profile (Path): Shared per-user interface preference directory.
            restart (Callable[[], None]): Existing coordinated desktop relaunch.
            runtime (RuntimeSettings | None): Access to the shared saved profile.
            protected (Callable[[], bool] | None): True while shared work is active.
            connections (ConnectionActions | None): Existing saved and legacy
                upload-mode transitions, or None when unavailable.
        Returns:
            None: Builds the accessible controls with current saved values.
        """
        super().__init__()
        self.profile = profile
        self.restart = restart
        self.runtime = runtime
        self.connections = connections
        self.protected = protected or (lambda: False)
        self._api_key_visible = False
        self._connection_test_running = False
        self._linked_connection_selected = False
        self._saved_api_key_selectable = False
        self._runtime_cards: list[QFrame] = []
        self._runtime_action_buttons: list[ActionButton] = []
        self._dependency_caption: QLabel | None = None
        self._watcher_dialog: QDialog | None = None
        self._watcher_status: QLabel | None = None
        self.manage_links_button: ActionButton | None = None
        self.requested = load_gui_backend(profile)
        self.saved_language = load_ui_language(profile)
        self.batch = load_thumbnail_batch_size(profile)
        body = QWidget()
        layout = QVBoxLayout(body)
        card = QFrame()
        card.setProperty('settingsCard', True)
        card_layout = QVBoxLayout(card)
        card_layout.setContentsMargins(*SETTINGS_CARD_INSETS)
        self._add_section_heading(card_layout, gettext('Interface'))
        form = QFormLayout()
        card_layout.addLayout(form)
        form.setRowWrapPolicy(QFormLayout.RowWrapPolicy.WrapLongRows)
        self.backend = QComboBox()
        self.backend.setSizeAdjustPolicy(QComboBox.SizeAdjustPolicy.AdjustToContents)
        for candidate in GUI_BACKENDS:
            self.backend.addItem(backend_label(candidate), candidate)
        # Reserve the actual localized label, including the PySide6 disclosure.
        self.backend.setFixedWidth(self.backend.sizeHint().width())
        self.backend.setAccessibleName(gettext("GUI backend"))
        self.backend.activated.connect(self.choose_backend)
        form.addRow(self._settings_field_label('GUI backend'), self.backend)
        self.status = QLabel()
        self.status.setWordWrap(True)
        self.status.setObjectName("status")
        form.addRow(self.status)
        controls = QWidget()
        row = QHBoxLayout(controls)
        row.setContentsMargins(0, 0, 0, 0)
        self.restart_button = ActionButton(gettext("Restart WinApp"), restart, primary=True)
        self.cancel_button = ActionButton(
            gettext("Cancel pending change"), lambda: self.save_backend("pyside6"),
        )
        row.addWidget(self.restart_button)
        row.addWidget(self.cancel_button)
        row.addStretch()
        form.addRow(controls)
        integrations = QFrame()
        integrations.setProperty('settingsCard', True)
        integration_layout = QVBoxLayout(integrations)
        integration_layout.setContentsMargins(*SETTINGS_CARD_INSETS)
        self._add_section_heading(integration_layout, gettext('APP & INTEGRATIONS'))
        self._integration_form = QFormLayout()
        self._integration_form.setHorizontalSpacing(SETTINGS_EDITOR_GAP_PX)
        integration_layout.addLayout(self._integration_form)
        self._integration_form.setRowWrapPolicy(QFormLayout.RowWrapPolicy.WrapLongRows)
        self._integration_label_width = max(
            SETTINGS_LABEL_MIN_WIDTH_PX,
            QFontMetrics(self.font()).horizontalAdvance(gettext('Server thumbnails per request')),
        )
        self.language = QComboBox()
        for code, language in LANGUAGE_NAMES.items():
            self.language.addItem(language, code)
        self.language.setAccessibleName(gettext("Language"))
        self.language.setFixedWidth(SETTINGS_LANGUAGE_WIDTH_PX)
        self.language.setCurrentIndex(self.language.findData(self.saved_language))
        self.language.activated.connect(self.choose_language)
        self._integration_form.addRow(
            self._settings_field_label('Language', self._integration_label_width),
            self.language,
        )
        self.batch_size = QComboBox()
        for option in THUMBNAIL_BATCH_CHOICES:
            self.batch_size.addItem(str(option), option)
        self.batch_size.setAccessibleName(gettext("Server thumbnails per request"))
        self.batch_size.setFixedWidth(SETTINGS_BATCH_WIDTH_PX)
        self.batch_size.setCurrentIndex(self.batch_size.findData(self.batch))
        self.batch_size.activated.connect(self.choose_batch)
        self._integration_form.addRow(
            self._settings_field_label(
                'Server thumbnails per request', self._integration_label_width,
            ), self.batch_size,
        )
        batch_note = QLabel(gettext('Saves immediately. The first thumbnail request stays small.'))
        batch_note.setWordWrap(True)
        batch_note.setObjectName('status')
        self._integration_form.addRow('', batch_note)
        self._dependency_row = self._integration_form.rowCount()
        self._cache_writing = False
        self._storage_ready = False
        self._observed_cache_limit: int | None = None
        self._storage_lane = QtReadLane[StorageObservation](
            self._show_storage, self._storage_finished, self,
        )
        self._connection_lane = QtReadLane[ConnectionTestResult](
            self._show_connection_test, self._connection_test_finished, self,
        )
        self.fields: dict[str, QCheckBox | QComboBox | QLineEdit] = {}
        self._values: RuntimeValues = {}
        if runtime is not None:
            self._add_runtime_forms(layout, integrations, card)
        else:
            layout.addWidget(integrations)
            layout.addWidget(card)
        self._add_storage_form(layout)
        layout.addStretch()
        self.setWidget(body)
        self.setWidgetResizable(True)
        self.refresh()

    def _add_section_heading(self, layout: QVBoxLayout, title: str) -> None:
        """Place an internal Settings heading instead of a group-box legend.

        Args:
            layout (QVBoxLayout): Card content layout receiving the heading.
            title (str): Localized section caption from the shared Settings UI.
        Returns:
            None: Adds a compact eyebrow label above the form controls.
        """
        heading = QLabel(title)
        heading.setProperty('eyebrow', True)
        heading.setObjectName('settingsSectionHeading')
        layout.addWidget(heading)

    def _settings_field_label(
        self, caption: str, minimum_width: int = SETTINGS_LABEL_MIN_WIDTH_PX,
    ) -> QLabel:
        """Reserve the stable label column used by the Settings forms.

        Args:
            caption (str): Localized field caption from the existing form.
            minimum_width (int): Logical-pixel minimum for this section's label column.
        Returns:
            QLabel: A minimum-width label aligned with the Tk editor column.
        """
        label = QLabel(gettext(caption))
        label.setMinimumWidth(minimum_width)
        label.setWordWrap(True)
        return label

    def _add_settings_save(self, form: QFormLayout, *, align_with_fields: bool) -> None:
        """Place the shared Settings action inside its reference card.

        Args:
            form (QFormLayout): Integration or advanced input card.
            align_with_fields (bool): Align beneath the editors on the integration card.
        Returns:
            None: Registers the button with existing save and read fences.
        """
        save = ActionButton(gettext('✓  Save settings'), self._save_runtime, primary=True)
        save.setObjectName('saveSettings')
        save.setMinimumSize(*SETTINGS_PRIMARY_MINIMUM)
        save.setSizePolicy(QSizePolicy.Policy.Fixed, QSizePolicy.Policy.Preferred)
        save.setStyleSheet('padding: 6px 12px; font-weight: 600;')
        self._runtime_action_buttons.append(save)
        if align_with_fields:
            form.addRow('', save)
        else:
            form.addRow(save)

    def _storage_text(self, key: str) -> str:
        """Use the same translated cache facts as the stable Photos view.

        Args:
            key (str): Existing Photos storage translation suffix.
        Returns:
            str: Localized heading, label or status.
        """
        return tr(current_language(), 'photos_workspace_storage_' + key)

    def _add_storage_form(self, layout: QVBoxLayout) -> None:
        """Present ordinary and retained cache observations without reading on Qt.

        Args:
            layout (QVBoxLayout): Scrollable Settings form owner.
        Returns:
            None: Exposes one explicit observation and one shared quota selector.
        """
        card = QFrame()
        card.setProperty('settingsCard', True)
        card_layout = QVBoxLayout(card)
        card_layout.setContentsMargins(*SETTINGS_CARD_INSETS)
        self._add_section_heading(card_layout, self._storage_text('scope'))
        form = QFormLayout()
        card_layout.addLayout(form)
        self.storage_usage = QLabel()
        self.storage_usage.setTextFormat(Qt.TextFormat.PlainText)
        self.storage_usage.setWordWrap(True)
        self.storage_limit = QComboBox()
        self.storage_limit.setAccessibleName(self._storage_text('limit'))
        for option in THUMBNAIL_CACHE_LIMIT_CHOICES_GIB:
            self.storage_limit.addItem(f'{option} GiB', option)
        self.storage_limit.setCurrentIndex(-1)
        self.storage_limit.setEnabled(False)
        self.storage_limit.activated.connect(self.choose_storage_limit)
        self.storage_note = QLabel()
        self.storage_note.setWordWrap(True)
        self.storage_note.setText(self._storage_text('scope_hint'))
        form.addRow(ActionButton(gettext('Refresh'), self.refresh_storage))
        form.addRow(self.storage_usage)
        form.addRow(self._storage_text('limit'), self.storage_limit)
        form.addRow(self.storage_note)
        layout.addWidget(card)

    def refresh_storage(self) -> None:
        """Request current ordinary and retained file counts off the GUI thread.

        Returns:
            None: Leaves shared preferences untouched.
        """
        if not self._cache_writing:
            self._request_storage()

    def choose_storage_limit(self, index: int) -> None:
        """Save one explicitly selected cache quota through the physical owner.

        Args:
            index (int): Selected item carrying one supported integer GiB limit.
        Returns:
            None: Keeps the last verified choice if persistence fails.
        """
        limit = self.storage_limit.itemData(index)
        if (not self._cache_writing and not self.protected() and type(limit) is int
                and limit in THUMBNAIL_CACHE_LIMIT_CHOICES_GIB):
            self._cache_writing = True
            self._request_storage(limit)
        else:
            self.storage_limit.setCurrentIndex(self.storage_limit.findData(self._observed_cache_limit))

    def _request_storage(self, limit: int | None = None) -> None:
        """Read the shared storage pools after an optional quota transaction.

        Args:
            limit (int | None): Explicit requested quota, or None for observation.
        Returns:
            None: Submits one cancellable operation and admits no repeated save.
        """
        self.storage_limit.setEnabled(False)
        self.storage_note.setText(self._storage_text('loading'))

        def read(cancelled: Event, publish: Callable[[StorageObservation], None]) -> None:
            """Inspect only safe local counts under the existing cache owner.

            Args:
                cancelled (Event): Current view's storage observation lifetime.
                publish (Callable[[StorageObservation], None]): Typed widget result.
            Returns:
                None: Reports verified counts or holds the unknown state.
            """
            if limit is not None and not set_thumbnail_cache_limit_gib(limit):
                raise RuntimeError('Thumbnail cache quota could not be saved.')
            cache = GalleryMediaCache.for_current_user(MEDIA_CACHE_MAX_BYTES, thumbnails=True)
            publish(StorageObservation(cache.thumbnail_storage_snapshot(cancelled), cache.retained_storage_snapshot(cancelled)))

        self._storage_lane.submit(read)

    def _show_storage(self, result: StorageObservation) -> None:
        """Show physically measured cache use and the verified configured limit.

        Args:
            result (StorageObservation): Fixed counts and byte totals or unknown results.
        Returns:
            None: Updates the selector and surfaces only non-sensitive storage facts.
        """
        count, used, limit, ready, _error = result.ordinary
        ordinary_ready = ready
        locale = QLocale()
        ordinary = self._storage_text('ordinary_unknown')
        self._observed_cache_limit = None
        self._storage_ready = False
        if ready and count is not None and used is not None and limit is not None:
            self._storage_ready = True
            if limit % BYTES_PER_GIB == 0:
                self._observed_cache_limit = limit // BYTES_PER_GIB
            ordinary = self._storage_text('ordinary_detail').format(
                count=count, used=locale.formattedDataSize(used), limit=locale.formattedDataSize(limit),
            )
        galleries, thumbs, thumb_bytes, others, other_bytes, ready = result.retained
        retained = self._storage_text('keep_unknown')
        if (ready and galleries is not None and thumbs is not None and thumb_bytes is not None
                and others is not None and other_bytes is not None):
            retained = self._storage_text('keep_detail').format(
                galleries=galleries, thumbs=thumbs, thumb_bytes=locale.formattedDataSize(thumb_bytes),
                others=others, other_bytes=locale.formattedDataSize(other_bytes),
            )
        self.storage_usage.setText(ordinary + '\n' + retained)
        summary = '—'
        if (ordinary_ready and ready and count is not None and used is not None
                and thumbs is not None and thumb_bytes is not None):
            summary = f'{locale.toString(count + thumbs)} · {format_gallery_bytes(used + thumb_bytes)}'
        self.storage_observed.emit(summary)

    def _storage_finished(self, failure: str) -> None:
        """Release the storage command only after its actual terminal event.

        Args:
            failure (str): Safe exception category, or empty after completion.
        Returns:
            None: Restores the verified selector and displays the save outcome.
        """
        saved = self._cache_writing
        self._cache_writing = False
        index = self.storage_limit.findData(self._observed_cache_limit)
        self.storage_limit.setCurrentIndex(index)
        self.storage_limit.setEnabled(self._storage_ready)
        key = 'save_failed' if saved and failure else 'saved' if saved else 'unavailable' if failure else ''
        self.storage_note.setText(self._storage_text(key) if key else '')

    def writes_storage(self) -> bool:
        """Identify an accepted cache quota write for shutdown coordination.

        Returns:
            bool: True until the current physical cache owner settles.
        """
        return self._cache_writing

    def focus_storage(self) -> None:
        """Reveal the existing cache quota controls from the Photos header.

        Returns:
            None: Scrolls to the shared storage surface and focuses its selector.
        """
        self.ensureWidgetVisible(self.storage_limit)
        self.storage_limit.setFocus(Qt.FocusReason.ShortcutFocusReason)

    def close_view(self) -> None:
        """Retire passive cache observation when the desktop really closes.

        Returns:
            None: Shutdown waits for an admitted write before this call.
        """
        self._storage_lane.close()
        self._connection_lane.close()

    def _add_runtime_forms(self, layout: QVBoxLayout, integrations: QFrame, interface: QFrame) -> None:
        """Mount compact forms backed by current shared profile values.

        Args:
            layout (QVBoxLayout): Scrollable Settings content layout.
            integrations (QFrame): Settings card holding language and thumbnail batches.
            interface (QFrame): Independent GUI backend chooser and pending restart.
        Returns:
            None: Adds connection, Watcher and import fields and Save.
        """
        assert self.runtime is not None
        self._values = self.runtime.load()
        groups = (
            ('GALLERY CONNECTION', (
                ('gallery_url', 'Gallery URL'), ('api_key', 'API key'),
            )),
            ('APP & INTEGRATIONS', (
                ('simconnect_dll_path', 'Custom SimConnect.dll'),
                ('close_to_tray', 'Keep working in the system tray when the window closes'),
                ('start_with_windows', 'Start with Windows (minimized to tray)'),
            )),
            ('Advanced import and archive settings', (
                ('inventory_refresh_seconds', 'Remote inventory reconnect seconds'),
                ('archive_max_entries', 'ZIP max entries'),
                ('archive_max_uncompressed_bytes', 'ZIP max total uncompressed GiB'),
                ('archive_max_file_bytes', 'ZIP max single file GiB'),
                ('archive_max_compression_ratio', 'ZIP max compression ratio'),
                ('staging_cleanup_age_hours', 'Staging cleanup age hours'),
                ('manual_thumbnail_workers', 'Local thumbnail processes'),
                ('manual_upload_workers', gettext_noop('Upload threads')),
                ('create_thumbnails', 'Server creates thumbnails'),
                ('manual_thumbnail_mode', 'Server creates thumbnails'),
            )),
        )
        for title, fields in groups:
            integrated = title == 'APP & INTEGRATIONS'
            advanced = title == 'Advanced import and archive settings'
            if integrated:
                card = integrations
                form = self._integration_form
            else:
                card = QFrame()
                card.setProperty('settingsCard', True)
                card_layout = QVBoxLayout(card)
                card_layout.setContentsMargins(*SETTINGS_CARD_INSETS)
                if not advanced:
                    self._add_section_heading(card_layout, gettext(title))
                form = QFormLayout()
                card_layout.addLayout(form)
                if title == 'GALLERY CONNECTION':
                    form.setVerticalSpacing(SETTINGS_CONNECTION_FORM_SPACING_PX)
            if advanced:
                toggle = ToggleSwitch(gettext(title))
                toggle.toggled.connect(card.setVisible)
                self._integration_form.addRow(toggle)
                card.hide()
            form.setRowWrapPolicy(QFormLayout.RowWrapPolicy.WrapLongRows)
            for key, caption in fields:
                value = self._values[key]
                if isinstance(value, bool):
                    editor = ToggleSwitch(gettext(caption))
                    if key == 'start_with_windows':
                        editor.setEnabled(startup_supported())
                        editor.clicked.connect(self._save_startup_choice)
                elif key == 'manual_thumbnail_mode':
                    editor = QComboBox()
                    editor.addItem(gettext('Server creates thumbnails'), 'server')
                    editor.addItem(gettext('This PC creates thumbnails when supported'), 'local')
                elif key in ('manual_thumbnail_workers', 'manual_upload_workers'):
                    editor = QComboBox()
                    maximum = (
                        MAX_THUMBNAIL_WORKERS if key == 'manual_thumbnail_workers'
                        else MAX_UPLOAD_WORKERS
                    )
                    for count, label in worker_choices(maximum):
                        editor.addItem(label, count)
                else:
                    editor = QLineEdit()
                    if key == 'api_key':
                        editor.setEchoMode(QLineEdit.EchoMode.Password)
                editor.setAccessibleName(gettext(caption))
                if isinstance(editor, QLineEdit) and key in (
                    'inventory_refresh_seconds', 'archive_max_entries',
                    'archive_max_uncompressed_bytes', 'archive_max_file_bytes',
                    'archive_max_compression_ratio', 'staging_cleanup_age_hours',
                ):
                    editor.setMaximumWidth(SETTINGS_NUMERIC_FIELD_WIDTH_PX)
                if isinstance(editor, QComboBox) and key in (
                    'manual_thumbnail_workers', 'manual_upload_workers',
                ):
                    editor.setMaximumWidth(SETTINGS_NUMERIC_FIELD_WIDTH_PX)
                self.fields[key] = editor
                if key == 'api_key':
                    row = QWidget()
                    controls = QHBoxLayout(row)
                    controls.setContentsMargins(0, 0, 0, 0)
                    controls.addWidget(editor, stretch=1)
                    controls.addWidget(ActionButton(
                        gettext('Reveal or mask API key'),
                        self._toggle_api_key_visibility, icon='info', icon_only=True,
                    ))
                    controls.addWidget(ActionButton(
                        gettext('Paste API key'), self._paste_api_key,
                        icon='add', icon_only=True,
                    ))
                    label_width = (
                        self._integration_label_width
                        if integrated else SETTINGS_LABEL_MIN_WIDTH_PX
                    )
                    form.addRow(self._settings_field_label(caption, label_width), row)
                elif key == 'simconnect_dll_path':
                    row = QWidget()
                    controls = QHBoxLayout(row)
                    controls.setContentsMargins(0, 0, 0, 0)
                    controls.addWidget(editor, stretch=1)
                    controls.addWidget(ActionButton(
                        gettext('Choose'), lambda field=key: self._pick_path(field),
                        icon='add',
                    ))
                    label_width = (
                        self._integration_label_width
                        if integrated else SETTINGS_LABEL_MIN_WIDTH_PX
                    )
                    form.addRow(self._settings_field_label(caption, label_width), row)
                elif isinstance(editor, QCheckBox):
                    form.addRow('', editor)
                else:
                    label_width = (
                        self._integration_label_width
                        if integrated else SETTINGS_LABEL_MIN_WIDTH_PX
                    )
                    form.addRow(self._settings_field_label(caption, label_width), editor)
                if key == 'start_with_windows':
                    startup_note = QLabel(gettext('Installed Windows app only. This switch saves immediately.'))
                    startup_note.setObjectName('status')
                    startup_note.setWordWrap(True)
                    form.addRow('', startup_note)
            if title == 'GALLERY CONNECTION' and self.connections is not None:
                self.key_status = QLabel()
                self.key_status.setWordWrap(True)
                self.key_status.setObjectName('status')
                form.addRow('', self.key_status)
                self.connection_status = QLabel()
                self.connection_status.setWordWrap(True)
                self.connection_status.setObjectName('status')
                self.connection_status.setTextFormat(Qt.TextFormat.PlainText)
                form.addRow('', self.connection_status)
                connection_actions = QWidget()
                actions = QHBoxLayout(connection_actions)
                actions.setContentsMargins(0, 0, 0, 0)
                if self.connections.test_legacy is not None:
                    save_and_test = gettext('✓  Save & Test')
                    test_button = ActionButton(
                        save_and_test.replace('&', '&&'), self._save_and_test_connection, primary=True,
                    )
                    test_button.setMinimumSize(*SETTINGS_PRIMARY_MINIMUM)
                    test_button.setStyleSheet('padding: 6px 12px; font-weight: 600;')
                    test_button.setAccessibleName(save_and_test)
                    actions.addWidget(test_button)
                actions.addWidget(ActionButton(
                    gettext('Open protected credential editor'), self.open_connection_editor,
                    icon='add', icon_only=True,
                ))
                self.revoke_button = ActionButton(gettext('Revoke key…'), self.revoke_requested.emit)
                self.manage_links_button = ActionButton(
                    gettext('Manage link…'), self.manage_links_requested.emit,
                )
                for command in (self.revoke_button, self.manage_links_button):
                    command.setMinimumHeight(SETTINGS_PRIMARY_MINIMUM[1])
                    actions.addWidget(command)
                actions.addStretch()
                form.addRow('', connection_actions)
                self.connection_guidance = QLabel()
                self.connection_guidance.setWordWrap(True)
                self.connection_guidance.setObjectName('status')
                form.addRow('', self.connection_guidance)
                for key in ('gallery_url', 'api_key'):
                    editor = self.fields[key]
                    assert isinstance(editor, QLineEdit)
                    editor.textChanged.connect(self._refresh_key_summary)
                self._refresh_connection_status()
            layout.addWidget(card)
            self._runtime_cards.append(card)
            if integrated:
                self._add_settings_save(form, align_with_fields=True)
                self.runtime_status = QLabel()
                self.runtime_status.setWordWrap(True)
                self.runtime_status.setTextFormat(Qt.TextFormat.PlainText)
                form.addRow('', self.runtime_status)
                layout.addWidget(interface)
            if advanced:
                self._add_settings_save(form, align_with_fields=False)
        self._fill_runtime(self._values)

    def _save_startup_choice(self, enabled: bool) -> None:
        """Apply the Windows tray startup switch through its existing saved setting.

        Args:
            enabled (bool): The exact explicitly clicked startup preference.
        Returns:
            None: Retains the saved check state when the owner refuses publication.
        """
        if self.runtime is None or not startup_supported():
            return
        field = 'start_with_windows'
        previous = self._values[field]
        if self.protected():
            self._fill_runtime({field: previous})
            self.runtime_status.setText(gettext('Wait for the current operation to finish.'))
            return
        try:
            accepted = self.runtime.save({field: enabled})
        except (OSError, RuntimeError, ValueError):
            self._fill_runtime({field: previous})
            self.runtime_status.setText(gettext('Configuration error'))
        else:
            self._values[field] = accepted[field]
            self._fill_runtime({field: accepted[field]})

    def open_watcher_settings(self) -> None:
        """Keep the current workspace active while editing Watcher preferences.

        Returns:
            None: Opens the scoped sheet or reports an active writer's fence.
        """
        if self.runtime is None or self.protected():
            QMessageBox.information(
                self.window(), gettext('Watcher settings'),
                gettext('Wait for the current operation to finish.'),
            )
            return
        if self._watcher_dialog is None:
            self._watcher_dialog = self._build_watcher_dialog()
        dialog = self._watcher_dialog
        if dialog.isVisible():
            dialog.raise_()
            dialog.activateWindow()
            return
        try:
            self.reload_watcher()
        except (OSError, RuntimeError, ValueError):
            QMessageBox.warning(self.window(), gettext('Watcher settings'), gettext('Configuration error'))
            return
        assert self._watcher_status is not None
        self._watcher_status.clear()
        dialog.open()
        self.fields['watched_folder'].setFocus()

    def _build_watcher_dialog(self) -> QDialog:
        """Build the same focused Watcher preferences and explicit footer as Tk.

        Returns:
            QDialog: One scrollable sheet under the current desktop window.
        """
        dialog = QDialog(self.window())
        dialog.setWindowTitle(gettext('Watcher settings'))
        dialog.setObjectName('watcherSettingsDialog')
        dialog.resize(*WATCHER_SETTINGS_MINIMUM)
        body = QVBoxLayout(dialog)
        scroll = QScrollArea()
        scroll.setWidgetResizable(True)
        scroll.setFrameShape(QFrame.Shape.NoFrame)
        card = QFrame()
        card.setProperty('settingsCard', True)
        layout = QVBoxLayout(card)
        layout.setContentsMargins(*SETTINGS_CARD_INSETS)
        self._add_section_heading(layout, gettext('WATCHER PREFERENCES'))
        layout.addWidget(QLabel(gettext('Watch folder')))
        folder = QLineEdit()
        folder.setAccessibleName(gettext('Watch folder'))
        self.fields['watched_folder'] = folder
        folder_actions = QHBoxLayout()
        folder_actions.addWidget(folder, stretch=1)
        folder_actions.addWidget(ActionButton(
            gettext('Choose watched folder'), lambda: self._pick_path('watched_folder'),
            icon='add', icon_only=True,
        ))
        folder_actions.addWidget(ActionButton(
            gettext('Open watched folder'), self._open_watcher_folder, icon='external', icon_only=True,
        ))
        layout.addLayout(folder_actions)
        recursive = ToggleSwitch(gettext('Include subfolders'))
        self.fields['watch_recursive'] = recursive
        layout.addWidget(recursive)
        for key, caption in (('scan_interval_seconds', 'Scan folder every'), ('stable_seconds', 'File stable for')):
            row = QHBoxLayout()
            row.addWidget(QLabel(gettext(caption)))
            value = QLineEdit()
            value.setAccessibleName(gettext(caption))
            value.setMaximumWidth(SETTINGS_NUMERIC_FIELD_WIDTH_PX)
            self.fields[key] = value
            row.addWidget(value)
            row.addWidget(QLabel(gettext('seconds')))
            row.addStretch()
            layout.addLayout(row)
        for key, caption in (
            ('attach_sim_camera_metadata', 'Attach simulator location'),
            ('delete_uploaded_files', 'Delete uploaded photos from this PC'),
        ):
            toggle = ToggleSwitch(gettext(caption))
            self.fields[key] = toggle
            layout.addWidget(toggle)
        guidance = QLabel(gettext('After confirmed upload. Existing and changed files are kept.'))
        guidance.setWordWrap(True)
        guidance.setObjectName('status')
        layout.addWidget(guidance)
        scroll.setWidget(card)
        body.addWidget(scroll, stretch=1)
        self._watcher_status = QLabel()
        self._watcher_status.setWordWrap(True)
        body.addWidget(self._watcher_status)
        footer = QHBoxLayout()
        footer.addStretch()
        save = ActionButton(gettext('Save watcher settings'), self._save_watcher_settings, primary=True)
        save.setObjectName('saveWatcherSettings')
        save.setMinimumHeight(SETTINGS_PRIMARY_MINIMUM[1])
        footer.addWidget(save)
        footer.addWidget(ActionButton(gettext('Cancel'), dialog.reject))
        body.addLayout(footer)
        return dialog

    def _open_watcher_folder(self) -> None:
        """Open only the explicitly entered existing Watcher directory.

        Returns:
            None: Uses the native file manager or shows a local missing-folder hint.
        """
        editor = self.fields['watched_folder']
        assert isinstance(editor, QLineEdit) and self._watcher_status is not None
        text = editor.text().strip()
        try:
            directory = Path(text).expanduser()
            if text and directory.is_dir():
                QDesktopServices.openUrl(QUrl.fromLocalFile(str(directory.absolute())))
                return
        except (OSError, ValueError):
            pass
        self._watcher_status.setText(gettext('No watched folder selected.'))

    def _save_watcher_settings(self) -> None:
        """Publish only the dialog's Watcher fields and close on readback.

        Returns:
            None: Keeps API-key and integration drafts in their original form.
        """
        if self._watcher_dialog is not None and self._save_runtime_draft(watcher_only=True):
            self._watcher_dialog.accept()

    def open_connection_editor(self) -> None:
        """Offer a modal masked draft and commit it through the primary Settings owner.

        Returns:
            None: Cancel leaves both the original Settings draft and saved profile intact.
        """
        self._show_connection_editor(saved_values_only=False)

    def open_begin_connection_editor(self, parent: QWidget | None = None) -> None:
        """Open the scoped connection editor used by the idle Begin action.

        Args:
            parent (QWidget | None): Optional active operator panel that owns the
                modal dialog; defaults to this Settings widget.

        Returns:
            None: Reads saved URL/key values and never saves unrelated Settings drafts.
        """
        self._show_connection_editor(saved_values_only=True, parent=parent)

    def _show_connection_editor(
        self, saved_values_only: bool, parent: QWidget | None = None,
    ) -> None:
        """Build the shared masked editor with an optional connection-only save.

        Args:
            saved_values_only (bool): Seed from persistence and save only URL/key,
                preserving every unrelated unsaved Settings form value.
            parent (QWidget | None): Optional owner for the modal dialog.
        Returns:
            None: Commits through RuntimeSettings and the existing connection test owner.
        """
        address = self.fields.get('gallery_url')
        credential = self.fields.get('api_key')
        if (self.connections is None or self.runtime is None
                or not isinstance(address, QLineEdit)
                or not isinstance(credential, QLineEdit)):
            return
        if self.protected():
            self.connection_status.setText(gettext('Wait for the current operation to finish.'))
            return
        if saved_values_only:
            try:
                original_values = self.runtime.load()
            except (OSError, RuntimeError, ValueError):
                self.runtime_status.setText(gettext('Gallery connection is unavailable.'))
                return
            initial_address = str(original_values.get('gallery_url', ''))
            initial_key = str(original_values.get('api_key', ''))
        else:
            initial_address = address.text()
            initial_key = credential.text()
        dialog = QDialog(parent or self)
        dialog.setObjectName('scopedConnectionEditor')
        dialog.setWindowTitle(gettext('Connection'))
        form = QFormLayout(dialog)
        temporary_address = QLineEdit(initial_address)
        temporary_key = QLineEdit(initial_key)
        temporary_key.setEchoMode(QLineEdit.EchoMode.Password)
        temporary_key.setAccessibleName(gettext('API key'))
        temporary_address.setAccessibleName(gettext('Gallery URL'))
        form.addRow(gettext('Gallery URL'), temporary_address)
        form.addRow(gettext('API key'), temporary_key)
        reveal = QCheckBox(gettext('Reveal'))
        reveal.toggled.connect(lambda checked: temporary_key.setEchoMode(
            QLineEdit.EchoMode.Normal if checked else QLineEdit.EchoMode.Password,
        ))
        form.addRow(reveal)
        buttons = QHBoxLayout()
        buttons.addStretch()

        def commit(test_after: bool) -> None:
            """Publish the temporary fields only after explicit Save or Save & Test.

            Args:
                test_after (bool): The operator also requested a legacy inventory check.
            Returns:
                None: The previous draft remains available when persistence refuses.
            """
            if self.protected():
                return
            if saved_values_only:
                try:
                    saved_values = self.runtime.save({
                        'gallery_url': temporary_address.text().strip(),
                        'api_key': temporary_key.text().strip(),
                    })
                except (OSError, RuntimeError, ValueError, TypeError):
                    self.runtime_status.setText(gettext('Configuration error'))
                    QMessageBox.warning(
                        dialog, gettext('Configuration error'),
                        self.runtime_status.text(),
                    )
                    return
                connection_values = {
                    key: saved_values[key] for key in ('gallery_url', 'api_key')
                }
                self._values.update(connection_values)
                self._fill_runtime(connection_values)
                self.runtime_status.setText(gettext('Configuration saved.'))
                self._refresh_connection_status()
                self.runtime_changed.emit(
                    str(saved_values['gallery_url']),
                    bool(saved_values['close_to_tray']),
                )
                self.connection_changed.emit()
                dialog.accept()
                if test_after:
                    self._test_saved_connection()
                return
            original = address.text(), credential.text()
            address.setText(temporary_address.text())
            credential.setText(temporary_key.text())
            if not self._save_runtime_draft():
                address.setText(original[0])
                credential.setText(original[1])
                QMessageBox.warning(dialog, gettext('Configuration error'), self.runtime_status.text())
                return
            dialog.accept()
            if test_after:
                self._test_saved_connection()

        buttons.addWidget(ActionButton(gettext('Cancel'), dialog.reject))
        save = ActionButton(gettext('Save'), lambda: commit(False))
        save.setObjectName('saveScopedConnection')
        save.setDefault(True)
        buttons.addWidget(save)
        if self.connections is not None and self.connections.test_legacy is not None:
            buttons.addWidget(ActionButton(gettext('✓  Save & Test'), lambda: commit(True), primary=True))
        form.addRow(buttons)
        focused = temporary_key if temporary_address.text() else temporary_address
        focused.setFocus()
        dialog.exec()
        dialog.deleteLater()

    def _pick_path(self, key: str) -> None:
        """Select one native directory or SimConnect DLL for the current draft.

        Args:
            key (str): Exact field owning this picker.
        Returns:
            None: Stages the selected path in the form until Save.
        """
        editor = self.fields[key]
        assert isinstance(editor, QLineEdit)
        if key == 'watched_folder':
            parent = self._watcher_dialog or self
            selected = QFileDialog.getExistingDirectory(parent, gettext('Watch folder'), editor.text())
        else:
            selected, _filter = QFileDialog.getOpenFileName(
                self, gettext('Select SimConnect.dll'), editor.text(), 'SimConnect.dll (SimConnect.dll)',
            )
        if selected:
            editor.setText(selected)

    def ready_for_legacy_revocation(self) -> bool:
        """Verify that the form still shows the exact saved connection draft.

        Returns:
            bool: True only when the displayed endpoint and key match the saved values.
        """
        url = self.fields.get('gallery_url')
        secret = self.fields.get('api_key')
        if (self.connections is None or self.runtime is None
                or not isinstance(url, QLineEdit) or not isinstance(secret, QLineEdit)):
            return False
        try:
            saved = self.connections.saved()
            values = self.runtime.load()
        except (OSError, RuntimeError, ValueError):
            self.connection_status.setText(gettext('Gallery connection is unavailable.'))
            return False
        if saved is not None and saved.mode == 'linked':
            self.connection_status.setText(tr(current_language(), 'revoke_temporarily_unavailable'))
            return False
        expected_url = str(values.get('gallery_url', '')).strip()
        expected_key = str(values.get('api_key', '')).strip()
        if (url.text().strip(), secret.text().strip()) != (expected_url, expected_key):
            self.connection_status.setText(gettext(
                'Save or restore connection fields before revoking the saved key.',
            ))
            return False
        if not (expected_url and expected_key):
            self.connection_status.setText(gettext('A saved connection is required.'))
            return False
        return True

    def reload_saved_connection(self) -> None:
        """Replace only the retired credentials after the shared persistence handoff.

        Returns:
            None: Refreshes the matched endpoint and key while preserving other drafts.
        """
        if self.runtime is None:
            return
        try:
            values = self.runtime.load()
            connection = {field: values[field] for field in ('gallery_url', 'api_key')}
        except (OSError, RuntimeError, ValueError):
            self.connection_status.setText(gettext('Configuration error'))
        else:
            self._values.update(connection)
            self._fill_runtime(connection)
            self._refresh_connection_status()

    def show_runtime_dependencies(self, status: str, repair: Callable[[], None]) -> None:
        """Show the shared local runtime state and its repair command in Settings.

        Args:
            status (str): Redacted and localized local dependency caption.
            repair (Callable[[], None]): Same admitted operation as in the AI workspace.
        Returns:
            None: Mounts the app integration row once during composition.
        """
        self._dependency_caption = QLabel()
        self._dependency_caption.setObjectName('status')
        self._dependency_caption.setTextFormat(Qt.TextFormat.PlainText)
        self._dependency_caption.setWordWrap(True)
        self.refresh_dependency_status(status)
        button = ActionButton(gettext('Repair dependencies'), repair)
        controls = QWidget()
        layout = QHBoxLayout(controls)
        layout.setContentsMargins(0, 0, 0, 0)
        layout.addWidget(self._dependency_caption, stretch=1)
        layout.addWidget(button)
        self._integration_form.insertRow(self._dependency_row, controls)
        self._runtime_action_buttons.append(button)

    def refresh_dependency_status(self, status: str) -> None:
        """Refresh the existing caption after a dependency operation.

        Args:
            status (str): Confirmed current runtime status.
        Returns:
            None: Leaves connection fields and pending changes alone.
        """
        if self._dependency_caption is not None:
            self._dependency_caption.setText(status)

    def _toggle_api_key_visibility(self) -> None:
        """Toggle only the in-memory Settings editor's masked presentation.

        Returns:
            None: Keeps the credential in the existing editor and profile owner.
        """
        editor = self.fields.get('api_key')
        if not isinstance(editor, QLineEdit):
            return
        self._api_key_visible = not self._api_key_visible
        editor.setEchoMode(
            QLineEdit.EchoMode.Normal if self._api_key_visible
            else QLineEdit.EchoMode.Password,
        )

    def _paste_api_key(self) -> None:
        """Paste clipboard text into the unsaved credential editor only.

        Returns:
            None: Leaves persistence and connection selection to explicit actions.
        """
        editor = self.fields.get('api_key')
        if not isinstance(editor, QLineEdit):
            return
        value = QApplication.clipboard().text().strip()
        if not value:
            self.runtime_status.setText(gettext('The clipboard does not contain an API key.'))
            return
        editor.setText(value)
        self.runtime_status.setText(gettext('API key entered but not saved.'))

    def _refresh_connection_status(self) -> None:
        """Show the selected connection type without exposing account details.

        Returns:
            None: Reflects the current shared mode or a fixed unavailable state.
        """
        if self.connections is None or not hasattr(self, 'connection_status'):
            return
        try:
            saved = self.connections.saved()
        except (OSError, RuntimeError, ValueError):
            self._linked_connection_selected = True
            self._saved_api_key_selectable = False
            self.connection_status.setText(gettext('Gallery connection is unavailable.'))
            self._refresh_key_summary()
            return
        self._linked_connection_selected = saved is not None and saved.mode == 'linked'
        if saved is None:
            label = gettext('Save or test the gallery connection in Settings.')
        elif self._linked_connection_selected:
            label = gettext('A linked gallery connection is selected.')
        else:
            label = gettext('The saved API-key connection is selected.')
        self.connection_status.setText(label)
        self._saved_api_key_selectable = (
            self._linked_connection_selected and bool(self._values.get('api_key'))
        )
        self._refresh_key_summary()

    def _refresh_key_summary(self) -> None:
        """Render the stable saved/draft distinction without displaying the key.

        Returns:
            None: Shows the credential guidance and enables only an eligible revoke.
        """
        url = self.fields.get('gallery_url')
        editor = self.fields.get('api_key')
        if not isinstance(url, QLineEdit) or not isinstance(editor, QLineEdit):
            return
        entered_key = editor.text().strip()
        exact = (
            url.text().strip() == str(self._values.get('gallery_url', '')).strip()
            and entered_key == str(self._values.get('api_key', '')).strip()
        )
        if not entered_key:
            status = gettext('API key not configured')
            guidance = gettext(
                'Paste a gallery-scoped API key generated in PHP Gallery Admin '
                'before testing or uploading.',
            )
        elif not exact:
            status = gettext('API key or gallery URL changed, not saved')
            guidance = gettext(
                'Revocation unavailable because the connection fields contain '
                'unsaved changes. Save or restore the saved connection first.',
            )
        else:
            status = gettext('API key configured and saved')
            guidance = ''
        self.key_status.setText(status)
        self.connection_guidance.setText(guidance)
        self.connection_guidance.setVisible(bool(guidance) and not self._linked_connection_selected)
        self.revoke_button.setEnabled(
            bool(entered_key) and exact and not self._linked_connection_selected
            and not self._connection_test_running,
        )

    def can_select_saved_api_key(self) -> bool:
        """Expose the saved API-key choice only in gallery connections.

        Returns:
            bool: A linked mode is selected and a saved API key exists.
        """
        return self._saved_api_key_selectable

    def select_saved_api_key(self) -> None:
        """Select the existing saved API-key mode through its shared owner.

        Returns:
            None: Reports only a fixed local result and never tests the network.
        """
        if self.connections is None or not self.can_select_saved_api_key():
            return
        if self.protected():
            self.connection_status.setText(
                gettext('Wait for the current operation to finish.'),
            )
            return
        try:
            self.connections.legacy()
        except (OSError, RuntimeError, ValueError):
            self.connection_status.setText(
                gettext('Gallery connection could not be changed.'),
            )
            return
        self._refresh_connection_status()
        self.connection_changed.emit()

    def _save_and_test_connection(self) -> None:
        """Save the exact form generation, then test its API-key authority.

        Returns:
            None: Starts one authenticated empty-inventory read when saved.
        """
        if (self.connections is None or self.connections.test_legacy is None
                or self._connection_test_running):
            return
        if not self._save_runtime_draft():
            return
        self._test_saved_connection()

    def _test_saved_connection(self) -> None:
        """Begin the read-only test for the saved form generation and its authority.

        Returns:
            None: Runs one authenticated check or reports a linked-mode refusal.
        """
        if (self.connections is None or self.connections.test_legacy is None
                or self._connection_test_running):
            return
        try:
            saved = self.connections.saved()
        except (OSError, RuntimeError, ValueError):
            self.runtime_status.setText(gettext('Gallery connection is unavailable.'))
            return
        if saved is not None and saved.mode == 'linked':
            QMessageBox.information(
                self, gettext('Connection'), gettext(
                    'This tests the saved API key. Select the API-key connection '
                    'explicitly before running this test.'
                ),
            )
            return
        self._connection_test_running = True
        self._set_connection_test_controls(False)
        self.runtime_status.setText(gettext('Testing'))
        self.connection_status.setText(gettext('Testing'))

        def check(
            cancelled: Event, publish: Callable[[ConnectionTestResult], None],
        ) -> None:
            """Run the existing exact-generation legacy API-key check.

            Args:
                cancelled (Event): Current Settings read lifetime.
                publish (Callable[[ConnectionTestResult], None]): Safe labels.
            Returns:
                None: Publishes the result after the authenticated request ends.
            """
            assert self.connections is not None and self.connections.test_legacy is not None
            publish(self.connections.test_legacy(cancelled))

        self._connection_lane.submit(check)

    def _set_connection_test_controls(self, enabled: bool) -> None:
        """Lock shared settings editors until the authenticated read settles.

        Args:
            enabled (bool): Whether runtime forms can be edited or saved.
        Returns:
            None: Leaves status reporting available during the request.
        """
        for card in self._runtime_cards:
            card.setEnabled(enabled)
        for button in self._runtime_action_buttons:
            button.setEnabled(enabled)
        for control in (
            self.backend, self.cancel_button, self.language, self.batch_size,
            self.restart_button,
        ):
            control.setEnabled(enabled)

    def _show_connection_test(self, result: ConnectionTestResult) -> None:
        """Report the verified gallery name without exposing request details.

        Args:
            result (ConnectionTestResult): Non-secret server gallery labels.
        Returns:
            None: Shows one plain-text success result.
        """
        label = result.gallery_title or result.gallery_label
        message = gettext('Connection test succeeded for gallery {gallery}.').format(gallery=label)
        self.runtime_status.setText(message)
        self.connection_status.setText(message)

    def _connection_test_finished(self, failure: str) -> None:
        """Release the edit guard after ordered request completion.

        Args:
            failure (str): Safe exception class from the read lane, or empty.
        Returns:
            None: Restores controls and displays a bounded failure reason.
        """
        self._connection_test_running = False
        self._set_connection_test_controls(True)
        self._refresh_key_summary()
        if failure:
            message = gettext('Connection test failed: {detail}').format(detail=failure)
            self.runtime_status.setText(message)
            self.connection_status.setText(message)

    def writes_connection_test(self) -> bool:
        """Expose test ownership to coordinated Settings protection.

        Returns:
            bool: True until the API-key request reaches its terminal event.
        """
        return self._connection_test_running

    def _fill_runtime(self, values: RuntimeValues) -> None:
        """Reflect the committed shared values in the native fields.

        Args:
            values (RuntimeValues): Current persisted settings keyed by field.
        Returns:
            None: Updates checkboxes, choices and editable text.
        """
        for key, editor in self.fields.items():
            if key not in values:
                continue
            value = values[key]
            if isinstance(editor, QCheckBox):
                editor.setChecked(bool(value))
            elif isinstance(editor, QComboBox):
                if (key in ('manual_thumbnail_workers', 'manual_upload_workers')
                        and editor.findData(value) == -1):
                    # Keep a previously saved explicit count outside the presets.
                    editor.addItem(str(value), value)
                editor.setCurrentIndex(editor.findData(value))
            elif key in ARCHIVE_BYTE_FIELDS:
                editor.setText(str(int(value) / BYTES_PER_GIB))
            else:
                editor.setText(str(value))

    def reload_watcher(self) -> None:
        """Reload only the Watcher fields affected by a confirmed factory reset.

        Returns:
            None: Other tabs' unsaved settings remain in their existing editors.
        """
        if self.runtime is not None:
            values = self.runtime.load()
            watcher = {key: values[key] for key in WATCHER_PREFERENCE_TYPES}
            self._values.update(watcher)
            self._fill_runtime(watcher)

    def _save_runtime(self) -> None:
        """Save one explicit typed settings draft from the form.

        Returns:
            None: Presents the verified result or leaves the draft editable.
        """
        self._save_runtime_draft()

    def _save_runtime_draft(self, *, watcher_only: bool = False) -> bool:
        """Submit one explicit typed settings draft using its existing owner.

        Args:
            watcher_only (bool): Save and read back the Watcher sheet fields only.
        Returns:
            bool: True after save/readback, otherwise false with the draft retained.
        """
        if self.runtime is None:
            return False
        status = self._watcher_status if watcher_only else self.runtime_status
        assert status is not None
        if self.protected():
            status.setText(gettext('Wait for the current operation to finish.'))
            return False
        draft: RuntimeValues = {}
        try:
            for key, editor in self.fields.items():
                if (key in WATCHER_PREFERENCE_TYPES) != watcher_only:
                    continue
                previous = self._values[key]
                if isinstance(editor, QCheckBox):
                    value = editor.isChecked()
                elif isinstance(editor, QComboBox):
                    value = editor.currentData()
                else:
                    text = editor.text().strip()
                    if key in ARCHIVE_BYTE_FIELDS:
                        value = int(float(text) * BYTES_PER_GIB)
                    else:
                        value = type(previous)(text) if not isinstance(previous, str) else text
                if value != previous:
                    draft[key] = value
            self._values = self.runtime.save(draft)
        except (OSError, RuntimeError, ValueError, TypeError, OverflowError):
            status.setText(gettext('Configuration error'))
            return False
        readback = {key: value for key, value in self._values.items()
                    if (key in WATCHER_PREFERENCE_TYPES) == watcher_only}
        self._fill_runtime(readback)
        status.setText(gettext('Configuration saved.'))
        if not watcher_only:
            self._refresh_connection_status()
        self.runtime_changed.emit(
            str(self._values['gallery_url']), bool(self._values['close_to_tray']),
        )
        return True

    def refresh(self) -> None:
        """Synchronize the saved values and available restart actions.

        Returns:
            None: Updates the effective toolkit status and pending language.
        """
        self.backend.setCurrentIndex(self.backend.findData(self.requested))
        self.language.setCurrentIndex(self.language.findData(self.saved_language))
        self.batch_size.setCurrentIndex(self.batch_size.findData(self.batch))
        pending_backend = self.requested != "pyside6"
        pending_language = self.saved_language != current_language()
        label = backend_status("pyside6", self.requested)
        if pending_language:
            label += "\n" + gettext("Restart to apply the selected language.")
        self.status.setText(label)
        self.restart_button.setVisible(pending_backend or pending_language)
        self.cancel_button.setVisible(pending_backend)
        self._refresh_connection_status()

    def focus_backend_picker(self) -> None:
        """Scroll the saved GUI backend choice into view and focus it.

        Returns:
            None: Leaves selection and restart confirmation to the real picker.
        """
        self.ensureWidgetVisible(self.backend)
        self.backend.setFocus(Qt.FocusReason.ShortcutFocusReason)

    def save_backend(self, backend: GuiBackend) -> bool:
        """Persist the toolkit through the shared preference owner.

        Args:
            backend (GuiBackend): Supported choice or cancellation target.
        Returns:
            bool: True if the requested backend is durably saved.
        """
        try:
            save_gui_backend(self.profile, backend)
        except OSError:
            self.refresh()
            QMessageBox.warning(self, gettext("Settings"), gettext("Could not save the GUI backend."))
            return False
        self.requested = backend
        self.refresh()
        return True

    def choose_backend(self, index: int) -> None:
        """Offer Restart now or Later after a new toolkit selection.

        Args:
            index (int): Activated item in the GUI backend picker.
        Returns:
            None: Leaves a cancellable pending choice if restart is deferred.
        """
        backend = self.backend.itemData(index)
        if backend != self.requested and self.save_backend(backend) and backend != "pyside6":
            self.offer_restart(gettext("The GUI backend was saved. Restart WinApp now to apply it?"))

    def choose_language(self, index: int) -> None:
        """Save a locale using the same catalogue preference as Tkinter.

        Args:
            index (int): Activated item in the locale picker.
        Returns:
            None: Applies the saved language at the next coordinated restart.
        """
        language = self.language.itemData(index)
        try:
            save_ui_language(self.profile, language)
        except OSError:
            self.refresh()
            QMessageBox.warning(self, gettext("Settings"), gettext("Could not save the interface language."))
            return
        self.saved_language = language
        self.refresh()
        if language != current_language():
            self.offer_restart(gettext("The language was saved. Restart WinApp now to apply it?"))

    def choose_batch(self, index: int) -> None:
        """Persist the next steady request limit for both photo frontends.

        Args:
            index (int): Activated item in the shared thumbnail limit picker.
        Returns:
            None: Subsequent batches read the new value on successful save.
        """
        size = self.batch_size.itemData(index)
        try:
            save_thumbnail_batch_size(self.profile, size)
        except OSError:
            self.refresh()
            QMessageBox.warning(self, gettext("Settings"), gettext("Could not save the thumbnail request size."))
            return
        self.batch = size
        self.batch_changed.emit(size)
        self.refresh()

    def offer_restart(self, text: str) -> None:
        """Offer localized restart timing with Later as the safe default.

        Args:
            text (str): Localized explanation of why restart is available.
        Returns:
            None: Invokes the existing relaunch action if the operator accepts.
        """
        dialog = QMessageBox(self)
        dialog.setWindowTitle(gettext("Restart WinApp"))
        dialog.setText(text)
        restart = dialog.addButton(gettext("Restart now"), QMessageBox.ButtonRole.AcceptRole)
        restart.setShortcut(QKeySequence("Ctrl+Return"))
        later = dialog.addButton(gettext("Later"), QMessageBox.ButtonRole.RejectRole)
        dialog.setDefaultButton(later)
        dialog.setEscapeButton(later)
        QTimer.singleShot(0, lambda: logging.info(
            "Desktop restart confirmation ready; backend=pyside6",
        ))
        dialog.exec()
        if dialog.clickedButton() is restart:
            logging.info('Desktop restart accepted; backend=pyside6')
            self.restart()
