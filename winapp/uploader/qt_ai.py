# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/uploader/qt_ai.py
# Module Type: Qt AI worker presentation
# Purpose: Control the existing mode-admitted AI worker from Qt.
# Responsibilities: Expose start, stop and progress and keep the profile owned
#   until the worker and its claimed server request have finished.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)

"""Present the current AI worker lifecycle without replacing its request owner."""

from queue import Empty, Queue
from typing import Callable, Protocol

from PySide6.QtCore import Qt, QTimer, Signal
from PySide6.QtWidgets import (
    QComboBox, QFormLayout, QFrame, QHBoxLayout, QLabel, QLineEdit,
    QMessageBox, QProgressBar, QSizePolicy, QVBoxLayout, QWidget,
)

from .config import ai_backend_labels
from .desktop_connection import DependencyActions, RuntimeSettings, RuntimeValues
from .localization import gettext
from .qt_read_lane import QtReadLane
from .qt_theme import ActionButton, ToggleSwitch

# Purpose: Bound AI events and drain work without changing worker FIFO order.
# Type: int. Units: ordered events. Scope: one active Qt AI view.
# Consumers: QtAiWorker.
# Rationale: 64 matches the existing desktop worker event processing budget.
AI_EVENT_LIMIT = 64
# Purpose: Observe local progress and completed worker lifecycle.
# Type: int. Units: milliseconds. Scope: one active worker presentation.
# Consumers: QtAiWorker._observe.
# Rationale: 200 ms matches the ordinary stable WinApp event observation.
AI_PRESENTATION_MS = 200
# Purpose: Retain the relevant end of a failed source dependency repair.
# Type: int. Units: characters. Scope: one redacted dependency dialog.
# Consumers: QtAiWorker._show_dependency_result. Rationale: Match Tk's
# 1,200-character tail bound without exposing an entire pip transcript.
AI_DEPENDENCY_OUTPUT_LIMIT = 1200
# Purpose: Match the Tk AI model-version field's visible character capacity.
# Type: int. Units: characters. Scope: the quick AI settings form.
# Consumers: QtAiWorker.model_version. Rationale: Tk uses width=24.
AI_MODEL_VERSION_CHARACTERS = 24
# Purpose: Match the Tk field padding around the selected backend label.
# Type: int. Units: logical pixels. Scope: AI backend combobox width.
# Consumers: QtAiWorker.backend. Rationale: Tk's OptionMenu adds its native arrow and padding.
AI_BACKEND_CHROME_ALLOWANCE_PX = 34
# Purpose: Match the Tk Entry's 24-character width without excess Qt padding.
# Type: int. Units: logical pixels. Scope: AI model-version field width.
# Consumers: QtAiWorker.model_version. Rationale: Tk declares width=24 characters.
AI_MODEL_CHROME_ALLOWANCE_PX = 22
# Purpose: Match Tk's label column and eight-pixel field inset in the quick form.
# Type: int. Units: logical pixels. Scope: AI quick preference rows.
# Consumers: QtAiWorker. Rationale: The hosted reference shows Qt fields starting 11px early.
AI_FORM_COLUMN_GAP_PX = 17
# Purpose: Preserve the reference AI pane's outer gutter and inner card padding.
# Type: tuple[int, int, int, int]. Units: logical pixels. Scope: each AI card.
# Consumers: QtAiWorker and its advanced settings form.
# Rationale: Tk uses 6 by 4 outside and 14 by 10 inside each card.
AI_VIEW_INSETS = (6, 4, 6, 4)
AI_CARD_INSETS = (14, 10, 14, 10)
# Purpose: Keep the two quick preference rows close while separating the status rows.
# Type: int. Units: logical pixels. Scope: backend and model form only.
# Consumers: QtAiWorker choices. Rationale: Tk uses compact per-row padding.
AI_QUICK_FORM_SPACING_PX = 10
# Purpose: Retain the visual grouping of the Tk AI card's status and commands.
# Type: int. Units: logical pixels. Scope: the quick card's content flow.
# Consumers: QtAiWorker. Rationale: Match the explicit Tk grid and pack margins.
AI_PURPOSE_GAP_PX = 3
AI_EXPLANATION_GAP_PX = 2
AI_ENABLE_GAP_PX = 11
AI_PREFERENCE_GAP_PX = 6
AI_STATUS_GAP_PX = 8
AI_RUNTIME_GAP_PX = 10
AI_ACTIONS_GAP_PX = 12
AI_STOP_GAP_PX = 7
# Purpose: Keep AI commands usable without the default compact button stride.
# Type: int. Units: logical pixels. Scope: the three ordinary AI action buttons.
# Consumers: QtAiWorker. Rationale: Tk uses full-height actions in the quick card.
AI_ACTION_MIN_HEIGHT_PX = 42
AI_ACTION_SIDE_ALLOWANCE_PX = 12
# Purpose: Wrap long runtime and tuning explanations at the reference reading widths.
# Type: int. Units: logical pixels. Scope: AI descriptions adjacent to actions or fields.
# Consumers: QtAiWorker. Rationale: Tk wraps these captions at 550 and 360 pixels.
AI_RUNTIME_TEXT_WIDTH_PX = 550
AI_ADVANCED_HELP_WIDTH_PX = 360

AI_ADVANCED_FIELDS = (
    ('ai_worker_poll_seconds', 'Poll seconds when idle'),
    ('ai_worker_lease_seconds', 'Lease seconds per job'),
    ('ai_model_name', 'Model or analyzer name'),
    ('ai_transformers_caption_model', 'Transformers caption model'),
    ('ai_transformers_detector_model', 'Transformers object detector'),
    ('ai_transformers_detection_threshold', 'Detector threshold'),
    ('ai_transformers_object_labels', 'Object labels'),
    ('ai_ollama_url', 'Local Ollama URL'),
    ('ai_ollama_model', 'Ollama vision model'),
    ('ai_semantic_prompt', 'Semantic prompt'),
    ('ai_external_command', 'External analyzer command'),
)
AI_ADVANCED_HELP = {
    'ai_worker_poll_seconds': 'Default 60. Larger values keep the server quieter.',
    'ai_worker_lease_seconds': 'Default 600. Heartbeats extend the lease while analysis runs.',
    'ai_transformers_caption_model': 'First use may download model files locally.',
    'ai_transformers_detector_model': 'Zero-shot object labels such as person, bridge, guitar, house.',
    'ai_transformers_detection_threshold': 'Default 0.18. Lower sees more objects but may add noise.',
    'ai_ollama_url': 'Optional fallback when you intentionally run Ollama.',
    'ai_ollama_model': 'Examples: llava:latest or llama3.2-vision:latest.',
}

AIEvents = Queue[tuple[str, str]]


class AiWorkerPort(Protocol):
    """The shared worker methods required to present claims and safe stop."""

    def start(self) -> None:
        """Start the user-approved worker thread.

        Returns:
            None: The owner's existing mode and lease checks run on the worker.
        """

    def stop(self) -> None:
        """Request stop through the worker's own cancellation mechanism.

        Returns:
            None: The claimed request remains worker-owned until it returns.
        """

    def is_alive(self) -> bool:
        """Check whether the existing worker can still own a request.

        Returns:
            bool: True until final worker teardown has completed.
        """

    def current_progress(self) -> tuple[int, str]:
        """Read the current thread-safe progress projection.

        Returns:
            tuple[int, str]: Reported progress and current worker caption.
        """


class LinkedAiModeRefusal(RuntimeError):
    """Identify verified linked mode as ineligible for the legacy AI worker."""


AiWorkerFactory = Callable[[AIEvents], AiWorkerPort]


class QtAiWorker(QWidget):
    """Present one operator-started worker and its shutdown barrier."""

    stopped = Signal()
    status_event = Signal(str, str)
    runtime_changed = Signal(str)
    work_changed = Signal(bool)

    def __init__(
        self, factory: AiWorkerFactory | None, sanitize: Callable[[str], str],
        runtime: RuntimeSettings | None, dependencies: DependencyActions | None,
        protected: Callable[[], bool],
    ) -> None:
        """Build worker controls independent of the active credential type.

        Args:
            factory (AiWorkerFactory | None): Shared worker factory that can
                prepare the exact verified saved configuration.
            sanitize (Callable[[str], str]): Existing local secret redactor.
            runtime (RuntimeSettings | None): Shared profile owner for the AI form.
            dependencies (DependencyActions | None): Existing source and bundled repair actions.
            protected (Callable[[], bool]): Active writer or update that fences profile changes.
        Returns:
            None: Initializes idle status and bounded event delivery.
        """
        super().__init__()
        self._factory = factory
        self._sanitize = sanitize
        self._runtime = runtime
        self._dependencies = dependencies
        self._protected = protected
        self._dependency_kind: str | None = None
        self._values: RuntimeValues = runtime.load() if runtime is not None else {}
        self._advanced_fields: dict[str, QLineEdit] = {}
        self._events: AIEvents = Queue(maxsize=AI_EVENT_LIMIT)
        self._worker: AiWorkerPort | None = None
        self._preparing = False
        self._stopping = False
        self._reported_running = False
        self._lane = QtReadLane[AiWorkerPort](self._start_prepared, self._prepare_finished, self)
        self._dependency_lane = QtReadLane[tuple[bool, str]](
            self._show_dependency_result, self._dependency_finished, self,
        )
        self._observe_timer = QTimer(self)
        self._observe_timer.setInterval(AI_PRESENTATION_MS)
        self._observe_timer.timeout.connect(self._observe)
        layout = QVBoxLayout(self)
        layout.setContentsMargins(*AI_VIEW_INSETS)
        layout.setSpacing(AI_STATUS_GAP_PX)
        quick = QFrame()
        quick.setProperty('settingsCard', True)
        form = QVBoxLayout(quick)
        form.setContentsMargins(*AI_CARD_INSETS)
        form.setSpacing(0)
        eyebrow = QLabel(gettext('AI METADATA'))
        eyebrow.setProperty('eyebrow', True)
        form.addWidget(eyebrow)
        form.addSpacing(AI_PURPOSE_GAP_PX)
        purpose = QLabel(gettext("Optional, server-assigned image analysis."))
        purpose.setObjectName("heading")
        purpose.setWordWrap(True)
        form.addWidget(purpose)
        form.addSpacing(AI_EXPLANATION_GAP_PX)
        explanation = QLabel(gettext(
            "The gallery assigns one job at a time; this PC returns internal search metadata.",
        ))
        explanation.setWordWrap(True)
        explanation.setObjectName("status")
        form.addWidget(explanation)
        form.addSpacing(AI_ENABLE_GAP_PX)
        self.enabled = ToggleSwitch(gettext('Enable AI metadata worker on this PC'))
        self.enabled.setEnabled(runtime is not None)
        form.addWidget(self.enabled, alignment=Qt.AlignmentFlag.AlignLeft)
        form.addSpacing(AI_PREFERENCE_GAP_PX)
        choices = QFormLayout()
        choices.setRowWrapPolicy(QFormLayout.RowWrapPolicy.WrapLongRows)
        choices.setFieldGrowthPolicy(QFormLayout.FieldGrowthPolicy.FieldsStayAtSizeHint)
        choices.setHorizontalSpacing(AI_FORM_COLUMN_GAP_PX)
        choices.setVerticalSpacing(AI_QUICK_FORM_SPACING_PX)
        self.backend = QComboBox()
        self.backend.setAccessibleName(gettext('Backend'))
        for code, label in ai_backend_labels().items():
            self.backend.addItem(label, code)
        backend_width = max(
            self.backend.fontMetrics().horizontalAdvance(self.backend.itemText(index))
            for index in range(self.backend.count())
        ) + AI_BACKEND_CHROME_ALLOWANCE_PX
        self.backend.setFixedWidth(backend_width)
        choices.addRow(gettext('Backend'), self.backend)
        self.model_version = QLineEdit()
        self.model_version.setAccessibleName(gettext('Model version'))
        model_width = (
            self.model_version.fontMetrics().horizontalAdvance(
                '0' * AI_MODEL_VERSION_CHARACTERS,
            ) + AI_MODEL_CHROME_ALLOWANCE_PX
        )
        self.model_version.setFixedWidth(model_width)
        choices.addRow(gettext('Model version'), self.model_version)
        self.backend.setEnabled(runtime is not None)
        self.model_version.setEnabled(runtime is not None)
        form.addLayout(choices)
        form.addSpacing(AI_STATUS_GAP_PX)
        self.status = QLabel(gettext("AI metadata worker idle"))
        self.status.setWordWrap(True)
        self.status.setTextFormat(Qt.TextFormat.PlainText)
        status_font = self.status.font()
        status_font.setBold(True)
        self.status.setFont(status_font)
        form.addWidget(self.status)
        self._runtime_label = QLabel()
        self._runtime_label.setTextFormat(Qt.TextFormat.PlainText)
        self._runtime_label.setObjectName('status')
        self._runtime_label.setWordWrap(True)
        if dependencies is not None:
            self._runtime_label.setText(self._sanitize(dependencies.status()))
            self._runtime_label.setMinimumWidth(0)
            self._runtime_label.setMaximumWidth(AI_RUNTIME_TEXT_WIDTH_PX)
            self._runtime_label.setSizePolicy(
                QSizePolicy.Policy.Ignored, QSizePolicy.Policy.Preferred,
            )
            runtime_row = QHBoxLayout()
            runtime_row.addWidget(self._runtime_label, stretch=1)
            self._install_ai = ActionButton(
                gettext('Install local AI module'), self.install_local_ai,
            )
            self._repair = ActionButton(gettext('Repair dependencies'), self.repair_dependencies)
            runtime_row.addWidget(self._install_ai)
            runtime_row.addWidget(self._repair)
            form.addSpacing(AI_RUNTIME_GAP_PX)
            form.addLayout(runtime_row)
        else:
            self._install_ai = self._repair = None
        actions = QHBoxLayout()
        actions.setSpacing(AI_STOP_GAP_PX)
        self.start_button = ActionButton(gettext("▶  Start AI worker"), self.start, primary=True)
        start_font = self.start_button.font()
        start_font.setBold(True)
        self.start_button.setFont(start_font)
        for action in (self.start_button, self._install_ai, self._repair):
            if action is not None:
                action.setMinimumHeight(AI_ACTION_MIN_HEIGHT_PX)
                action.setMinimumWidth(action.sizeHint().width() + AI_ACTION_SIDE_ALLOWANCE_PX)
        self.stop_button = ActionButton(
            gettext("Stop AI metadata worker"), self.stop, icon='stop', icon_only=True,
        )
        actions.addWidget(self.start_button)
        actions.addWidget(self.stop_button)
        actions.addStretch()
        form.addSpacing(AI_ACTIONS_GAP_PX)
        form.addLayout(actions)
        self.progress = QProgressBar()
        self.progress.setAccessibleName(gettext("AI metadata worker"))
        form.addWidget(self.progress)
        layout.addWidget(quick)
        if runtime is not None:
            self._build_advanced_form(form, layout)
        self._fill_preferences()
        self._show_actions()

    def _build_advanced_form(self, quick: QVBoxLayout, layout: QVBoxLayout) -> None:
        """Place the stable AI tuning inputs behind the existing advanced choice.

        Args:
            quick (QVBoxLayout): Quick AI card owning the disclosure control.
            layout (QVBoxLayout): Workspace layout owning both cards.
        Returns:
            None: Adds the collapsed, shared-profile settings form.
        """
        toggle = ToggleSwitch(gettext('Advanced AI settings'))
        toggle.setObjectName('advancedAiSettings')
        panel = QFrame()
        panel.setObjectName('advancedAiFields')
        panel.setProperty('settingsCard', True)
        form = QFormLayout(panel)
        form.setContentsMargins(*AI_CARD_INSETS)
        form.setVerticalSpacing(AI_QUICK_FORM_SPACING_PX)
        form.setRowWrapPolicy(QFormLayout.RowWrapPolicy.WrapLongRows)
        for key, label in AI_ADVANCED_FIELDS:
            field = QLineEdit()
            field.setAccessibleName(gettext(label))
            self._advanced_fields[key] = field
            if key in AI_ADVANCED_HELP:
                help_text = gettext(AI_ADVANCED_HELP[key])
                field.setToolTip(help_text)
                field.setAccessibleDescription(help_text)
                hint = QLabel(help_text)
                hint.setObjectName('status')
                hint.setWordWrap(True)
                hint.setMaximumWidth(AI_ADVANCED_HELP_WIDTH_PX)
                row = QHBoxLayout()
                row.addWidget(field, stretch=1)
                row.addWidget(hint)
                form.addRow(gettext(label), row)
            else:
                form.addRow(gettext(label), field)
        note = QLabel(gettext(
            'Optional. Use {image_path}, {filename}, and {job_id}. External analyzer output '
            'is treated as untrusted JSON and validated before use. Leave blank unless you '
            'use your own local analyzer.',
        ))
        note.setWordWrap(True)
        note.setObjectName('status')
        form.addRow(note)
        form.addRow(ActionButton(gettext('✓  Save settings'), self._save_preferences))
        quick.addSpacing(AI_STATUS_GAP_PX)
        quick.addWidget(toggle, alignment=Qt.AlignmentFlag.AlignLeft)
        layout.addWidget(panel)
        toggle.toggled.connect(panel.setVisible)
        panel.hide()

    def _fill_preferences(self) -> None:
        """Reflect the saved AI profile in its only editable Qt workspace.

        Returns:
            None: Updates only AI controls and keeps credentials outside this view.
        """
        self.enabled.setChecked(self._values.get('ai_worker_enabled') is True)
        self.backend.setCurrentIndex(self.backend.findData(self._values.get('ai_vision_backend', 'auto')))
        self.model_version.setText(str(self._values.get('ai_model_version', '')))
        for key, editor in self._advanced_fields.items():
            editor.setText(str(self._values.get(key, '')))

    def _save_preferences(self) -> bool:
        """Publish changed AI settings through the exact saved profile owner.

        Returns:
            bool: True when the draft is already current or has safely persisted.
        """
        if self._runtime is None:
            return True
        draft: RuntimeValues = {
            'ai_worker_enabled': self.enabled.isChecked(),
            'ai_vision_backend': str(self.backend.currentData()),
            'ai_model_version': self.model_version.text().strip(),
        }
        try:
            for key, editor in self._advanced_fields.items():
                value = self._values[key]
                text = editor.text().strip()
                draft[key] = text if isinstance(value, str) else type(value)(text)
            current = self._runtime.load()
            draft = {key: value for key, value in draft.items() if value != current[key]}
            if draft:
                if self._protected():
                    self.status.setText(gettext('Wait for the current operation to finish.'))
                    return False
                self._values = self._runtime.save(draft)
                self._fill_preferences()
                self.status.setText(gettext('Configuration saved.'))
        except (OSError, RuntimeError, ValueError, TypeError, KeyError):
            self.status.setText(gettext('Configuration error'))
            return False
        return True

    def dependency_status(self) -> str:
        """Expose the same reported runtime details to the Settings card.

        Returns:
            str: Current redacted dependency caption, or empty when unavailable.
        """
        return self._runtime_label.text()

    def repair_dependencies(self) -> None:
        """Invoke the shared runtime repair action from AI or Settings.

        Returns:
            None: The source repair or installed-runtime information runs off the GUI.
        """
        if self._dependencies is not None:
            self._run_dependency_action('repair', self._dependencies.repair)

    def install_local_ai(self) -> None:
        """Request explicit consent before installing optional source packages.

        Returns:
            None: Bundled applications report availability through the same command.
        """
        dependencies = self._dependencies
        if dependencies is None or self._protected():
            return
        if not dependencies.bundled and QMessageBox.question(
            self, gettext('Install local AI module'), gettext(
                'This installs large optional Python packages such as PyTorch and Transformers '
                'into the current Python runtime. Using those backends can later download large '
                'model files. Continue?',
            ), QMessageBox.StandardButton.Yes | QMessageBox.StandardButton.No,
            QMessageBox.StandardButton.No,
        ) != QMessageBox.StandardButton.Yes:
            return
        self._run_dependency_action('ai', dependencies.install_ai)

    def _run_dependency_action(self, kind: str, action: Callable[[], tuple[bool, str]]) -> None:
        """Keep one explicit dependency operation inside the shutdown fence.

        Args:
            kind (str): The runtime repair or optional AI installation identifier.
            action (Callable[[], tuple[bool, str]]): Existing operation with success and safe output.
        Returns:
            None: Runs only while no writer or dependency operation is active.
        """
        if self.has_work() or self._protected():
            return
        self._dependency_kind = kind
        self._show_actions()
        self._dependency_lane.submit(lambda cancel, publish: publish(action()) if not cancel.is_set() else None)

    def _show_dependency_result(self, result: tuple[bool, str]) -> None:
        """Present bounded output from the selected repair or installation.

        Args:
            result (tuple[bool, str]): Operation success and retained console output.
        Returns:
            None: Shows a native result dialog and refreshes the shared runtime caption.
        """
        dependencies = self._dependencies
        assert dependencies is not None
        ok, output = result
        details = self._sanitize(output[-AI_DEPENDENCY_OUTPUT_LIMIT:])
        if dependencies.bundled:
            title = gettext('Local AI module') if self._dependency_kind == 'ai' else gettext('Bundled dependencies')
            message = gettext(details)
        elif self._dependency_kind == 'ai':
            title = gettext('Local AI module')
            message = gettext(
                'Optional local AI module dependency installation finished successfully. '
                'Restart the app before running the Transformers backend for the first time.',
            ) if ok else (details or gettext('pip failed without output'))
        elif ok:
            title = gettext('Dependency repair')
            message = gettext('Required dependencies are available for this Python runtime.')
        else:
            title = gettext('Dependency repair failed')
            message = details or gettext('pip failed without output')
        icon = QMessageBox.Icon.Information if ok or dependencies.bundled else QMessageBox.Icon.Warning
        dialog = QMessageBox(icon, title, message, QMessageBox.StandardButton.Ok, self)
        dialog.setTextFormat(Qt.TextFormat.PlainText)
        dialog.setAttribute(Qt.WidgetAttribute.WA_DeleteOnClose)
        dialog.open()
        status = self._sanitize(dependencies.status())
        self._runtime_label.setText(status)
        self.runtime_changed.emit(status)

    def _dependency_finished(self, failure: str) -> None:
        """Release dependency ownership after the actual operation has ended.

        Args:
            failure (str): Exception category, or empty after the normal result.
        Returns:
            None: Restores commands and redacts infrastructure failure details.
        """
        self._dependency_kind = None
        if failure:
            self.status.setText(gettext('Dependency repair failed'))
        self._show_actions()
        self.stopped.emit()

    def running(self) -> bool:
        """Check whether a real worker can still own its server claim.

        Returns:
            bool: True until the worker's actual request and thread have ended.
        """
        return self._worker is not None and self._worker.is_alive()

    def has_work(self) -> bool:
        """Observe whether worker completion and terminal output are pending.

        Returns:
            bool: True from admitted preparation through worker exit and delivery.
        """
        return self._preparing or self._worker is not None or self._dependency_kind is not None

    def start(self) -> None:
        """Prepare the current explicit AI action off the GUI thread.

        Returns:
            None: Leaves the worker idle if another attempt owns the view.
        """
        factory = self._factory
        if factory is None or self.has_work() or not self.isEnabled():
            return
        if not self._save_preferences():
            return
        if self._runtime is not None and not self.enabled.isChecked():
            self.status.setText(gettext('Enable the AI metadata worker before starting it.'))
            return
        self._preparing = True
        self.status.setText(gettext("Loading…"))
        self._show_actions()
        self._lane.submit(lambda cancel, publish: publish(factory(self._events)) if not cancel.is_set() else None)

    def _start_prepared(self, worker: AiWorkerPort) -> None:
        """Start the shared worker only for the current prepared action.

        Args:
            worker (AiWorkerPort): Saved-settings-bound worker with no active claim.
        Returns:
            None: Worker thread retains all existing server and mode admission.
        """
        try:
            worker.start()
        except RuntimeError:
            self.status.setText(gettext("AI metadata worker stopped"))
            return
        self._worker = worker
        self._observe_timer.start()
        self.status.setText(gettext("AI metadata worker running"))
        self._show_actions()

    def _prepare_finished(self, failure: str) -> None:
        """Expose a refused start without guessing at saved credentials.

        Args:
            failure (str): Sanitized preparation error class or empty on success.
        Returns:
            None: Restores idle controls when the mode cannot admit work.
        """
        self._preparing = False
        if failure == LinkedAiModeRefusal.__name__:
            self.status.setText(gettext(
                'Linked gallery upload does not grant AI access. Switch explicitly '
                'to the saved API-key connection to use this worker.',
            ))
        elif failure:
            self.status.setText(gettext(
                "Enable the AI worker in Settings and verify the saved gallery connection.",
            ))
        self._show_actions()

    def stop(self) -> None:
        """Cancel preparation or ask the current worker to finish its request.

        Returns:
            None: Keeps the Qt main loop available while the worker exits.
        """
        if self._preparing:
            self._lane.cancel()
            self._preparing = False
        if self.running() and not self._stopping:
            self._stopping = True
            self._worker.stop()
            self.status.setText(gettext("AI metadata worker stopping"))
        self._show_actions()

    def _observe(self) -> None:
        """Drain bounded status events and observe actual worker completion.

        Returns:
            None: The visible worker state never outlives its real thread.
        """
        worker = self._worker
        if worker is None:
            return
        # Backpressure preserves every event. A fixed GUI slice prevents one
        # observer turn from starving control or close actions.
        for _index in range(AI_EVENT_LIMIT):
            try:
                level, message = self._events.get_nowait()
            except Empty:
                break
            self.status_event.emit(level, self._sanitize(message))
        if worker.is_alive():
            percent, message = worker.current_progress()
            if self.progress.value() != percent:
                self.progress.setValue(percent)
            if not self._stopping:
                caption = gettext(self._sanitize(message))
                if caption != self.status.text():
                    self.status.setText(caption)
            return
        if not self._events.empty():
            return
        self._observe_timer.stop()
        self._worker = None
        self._stopping = False
        self.status.setText(gettext("AI metadata worker stopped"))
        self.progress.reset()
        self._show_actions()
        self.stopped.emit()

    def _show_actions(self) -> None:
        """Keep command availability tied to the exact worker lifecycle.

        Returns:
            None: Projects action availability and the current background monitor state.
        """
        running = self.running()
        reports_running = running and not self._stopping
        if reports_running != self._reported_running:
            self._reported_running = reports_running
            self.work_changed.emit(reports_running)
        self.start_button.setEnabled(
            self._factory is not None and not self.has_work(),
        )
        self.stop_button.setEnabled(self._preparing or reports_running)
        self.progress.setVisible(running)
        if self._install_ai is not None and self._repair is not None:
            self._install_ai.setEnabled(not self.has_work())
            self._repair.setEnabled(not self.has_work())

    def close_view(self) -> None:
        """Close visual preparation after the application certifies quiescence.

        Returns:
            None: No further readiness or Qt callbacks are admitted.
        """
        self._observe_timer.stop()
        self._lane.close()
        self._dependency_lane.close()
