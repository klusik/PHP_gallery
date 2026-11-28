# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/uploader/launcher.py
# Module Type: Windows Application Launcher
# Purpose: Own CLI dispatch, process coordination and desktop startup policy.
# Responsibilities:
#   - Preserve the public source/frozen launch path and command-line modes.
#   - Admit one profile owner before loading the Tk or Qt desktop.
#   - Supervise Qt startup and fall back through the existing Tk entrypoint.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)
"""Dispatch command-line modes and start the compatible desktop backend."""

import argparse
import logging
from logging.handlers import RotatingFileHandler
import os
import queue
import re
import subprocess
import sys
import threading
import time
from contextlib import ExitStack
from dataclasses import replace
from pathlib import Path
from typing import Callable, List, Optional, Tuple

from . import http_transport, update_helper
from .ai import dependencies as ai_dependencies
from .ai.worker import AIAnalysisWorkerThread, create_ai_worker
from .config import load_ui_language
from .credential_store import WindowsUploadModeStore
from .desktop_connection import DependencyActions
from .diagnostics import format_watch_recovery_diagnostic, redact_text
from .flight_jobs import ImportBindings, WorkspaceLease, connection_binding
from .gui_backend import (
    GUI_BACKENDS,
    GUI_CHILD_OPTION,
    GUI_FALLBACK_REASONS,
    LEGACY_GUI_BACKEND,
    GuiFallback,
    configure_frozen_qt_environment,
    gui_startup_directory,
    launcher_command,
    launcher_environment,
    load_gui_backend,
    report_gui_startup,
    supervise_gui_startup,
)
from .gui_backend_labels import fallback_notice
from .gui_backend_profile import inspect_gui_profile_work
from .import_commands import (
    ImportActions, ImportApproval, ImportEvents, ImportSource,
    admit_reviewed_import, read_verified_import_retry, select_retry_sources,
)
from .imports.manual_upload import ManualUploadThread, create_import_worker
from .imports.preflight import ImportPreflightThread
from .linked_upload import LinkedUploadTarget, UploadPipelineLease, linked_target_binding
from .localization import configure_language, gettext
from .macos_mode import MacUploadModeStore, assert_local_macos_profile
from .media import detect_suffix_capabilities
from .mode_admission import LEGACY_UPLOAD_STATES, WorkKind
from .mode_coordinator import GuiModeOwner, ModeCoordinator, ModeCoordinatorError
from .state_store import JobStateStore
from .models import ImportJob, RECOVERABLE_ITEM_STATES
from .watcher import runtime, state as watcher_state
from .watcher.configuration import DesktopConfiguration
from .watcher.desktop import DesktopCapture
from .watcher.profile import WATCHER_ONCE_LOCK, WatcherProfile
from .watcher.state import LOG_PATH, capture_worker_config
from .watcher.ui.app import WatcherApp
from .watcher.workers.watcher import WatcherThread
from .windows_file_identity import assert_local_windows_profile
from .windows_startup import ACTIVATION_POLL_MS, WindowsInstance, synchronize_startup

LAUNCHER_PATH = runtime.APP_DIR / "gallery_watch_upload.pyw"

# Qt loads only inside the supervised child requested by the stable launcher.
run_qt = None
LinkedAiModeRefusal = RuntimeError
if GUI_CHILD_OPTION in sys.argv[1:] and gui_startup_directory() is not None:
    try:
        configure_frozen_qt_environment()
        from .qt_application import run_qt
        from .qt_ai import LinkedAiModeRefusal
    except (ImportError, OSError):
        pass

def setup_logging() -> None:
    """
    Configure file logging for watcher diagnostics.

    The GUI shows recent status in the window, but the file log survives restarts
    and is more useful when diagnosing upload or hosting issues later.

    Returns:
        None: Configures the root diagnostic log when needed.
    """
    watcher_state.CONFIG_DIR.mkdir(parents=True, exist_ok=True)
    root_logger = logging.getLogger()
    if root_logger.handlers:
        return
    root_logger.setLevel(logging.INFO)
    handler = RotatingFileHandler(str(LOG_PATH), maxBytes=2 * 1024 * 1024, backupCount=3, encoding="utf-8")
    handler.setFormatter(logging.Formatter("%(asctime)s %(levelname)s %(message)s"))
    root_logger.addHandler(handler)


def desktop_import_actions(
    store: watcher_state.ConfigStore, config: watcher_state.WatcherConfig | Callable[[], watcher_state.WatcherConfig],
    mode: Callable[[], GuiModeOwner], linked: Callable[[], LinkedUploadTarget | None] | None = None,
) -> ImportActions:
    """Adapt explicit native Import commands to the existing upload worker.

    Args:
        store (ConfigStore): One owner for the loaded desktop configuration.
        config (WatcherConfig | Callable[[], WatcherConfig]): Verified saved
            settings or their live owner for an editable desktop.
        mode (Callable[[], GuiModeOwner]): Lazy shared profile mode owner.
        linked (Callable[[], LinkedUploadTarget | None] | None): Current
            server-verified linked destination, if the frontend supports it.
    Returns:
        ImportActions: Shared discovery, admission, retry and retirement commands.
    """
    current = config if callable(config) else lambda: config
    job_store = JobStateStore(watcher_state.STATE_PATH, current().activity_history_limit)
    bindings = ImportBindings(watcher_state.CONFIG_DIR / 'flights')

    def saved_connection() -> tuple[GuiModeOwner, watcher_state.WatcherConfig, str, LinkedUploadTarget | None, WorkKind]:
        """Resolve the exact selected mode without falling back across connections.

        Returns:
            tuple[GuiModeOwner, WatcherConfig, str, LinkedUploadTarget | None, WorkKind]:
                Mode owner, captured configuration, binding, target and work kind.
        Raises:
            ModeCoordinatorError: No verified saved connection can admit this work.
        """
        owner = mode()
        captured = capture_worker_config(current())
        state = owner.view().state
        if state == 'linked_active' and linked is not None:
            target = linked()
            if target is not None:
                return owner, captured, target.binding(), target, 'linked'
        url, key = http_transport.normalize_upload_url(captured.gallery_url), captured.api_key.strip()
        if not url or not key or state not in LEGACY_UPLOAD_STATES:
            raise ModeCoordinatorError('Saved upload mode is unavailable.')
        return owner, captured, connection_binding(url, key), None, 'legacy'

    def prepare(source: ImportSource, events: ImportEvents) -> ImportPreflightThread:
        """Create the shared discovery worker for the explicit native selection.

        Args:
            source (ImportSource): Exactly one local source and discovery options.
            events (ImportEvents): Current preflight event destination.
        Returns:
            ImportPreflightThread: Unstarted preflight using the shared leases.
        """
        owner, captured, _binding, target, _kind = saved_connection()
        return ImportPreflightThread(
            captured, source.kind(), list(source.files), source.folder, source.archive,
            source.recursive, False, events, job_store, linked_target=target,
            gui_admission=(owner, store.mode_port(captured)),
        )

    def admit(
        job: ImportJob, approval: ImportApproval, local: bool, events: ImportEvents,
    ) -> ManualUploadThread:
        """Commit a newly reviewed plan before the first real worker dispatch.

        Args:
            job (ImportJob): Reviewed plan from the exact current preflight.
            approval (ImportApproval): Exact preflight job, settings and destination.
            local (bool): User's requested local thumbnail preference.
            events (ImportEvents): Current worker generation's ordered event queue.
        Returns:
            ManualUploadThread: Unstarted worker after durable job admission.
        Raises:
            ValueError: The profile, exact review or selected originals changed.
        """
        owner, captured, binding, target, kind = saved_connection()
        paths = [Path(item.path) for item in job.items
                 if item.state in RECOVERABLE_ITEM_STATES and Path(item.path).is_file()]
        if not paths:
            raise ValueError('This job has no queued or recoverable files to upload.')
        # Admission itself records a durable target even before dispatch.
        # Refresh exact mode evidence under the ordinary owner before doing so.
        with owner.acquire(kind, store.mode_port(captured)):
            admit_reviewed_import(
                job_store, bindings, job, binding=binding,
                generation=captured._snapshot_token, approval=approval,
            )
        return create_import_worker(
            store, captured, owner, job_store, job, paths, target,
            local and runtime.local_thumbnail_supported(), events,
        )

    def retry(job_id: str, local: bool, events: ImportEvents) -> tuple[ManualUploadThread | None, int]:
        """Prepare a retry only for a freshly verified accepted job.

        Args:
            job_id (str): Exact selected durable history identity.
            local (bool): User's requested local thumbnail preference.
            events (ImportEvents): Current worker generation's ordered event queue.
        Returns:
            tuple[ManualUploadThread | None, int]: Unstarted retry worker, if
                paths remain, and the excluded unknown-outcome count.
        """
        owner, captured, binding, target, kind = saved_connection()
        with owner.acquire(kind, store.mode_port(captured)):
            job = read_verified_import_retry(
                job_store, bindings, job_id, binding=binding,
                generation=captured._snapshot_token, approval=None,
            )
            selected = select_retry_sources(job_store, job)
            if not selected.paths:
                return None, selected.uncertain
            job.finished_at = 0.0
            admit_reviewed_import(
                job_store, bindings, job, binding=binding,
                generation=captured._snapshot_token, approval=None,
            )
        return create_import_worker(
            store, captured, owner, job_store, job, list(selected.paths), target,
            local and runtime.local_thumbnail_supported(), events,
        ), selected.uncertain

    return ImportActions(
        prepare, admit, retry,
        lambda job_id: bindings.discard_unbound_preflight(job_store, job_id),
        current().manual_thumbnail_mode == 'local',
        local_thumbnail_available=runtime.local_thumbnail_supported(),
        media_capabilities=detect_suffix_capabilities(watcher_state.SUPPORTED_SUFFIXES, runtime.Image),
    )


def run_once(config: watcher_state.WatcherConfig, config_store: watcher_state.ConfigStore) -> int:
    """
    Run one scan without showing the GUI.

    This is mostly useful for testing and scheduled execution. Note that the
    live watcher behavior of ignoring pre-existing files is not applied here
    unless initial_paths is explicitly populated before calling scan_once().

    Args:
        config (WatcherConfig): Configuration to use for the scan.
        config_store (ConfigStore): Owner of the exact loaded configuration;
            mode evidence is checked before constructing the worker.

    Returns:
        int: Zero after a completed FIFO; one while work waits or evidence fails.
    """
    try:
        if type(config_store) is not watcher_state.ConfigStore:
            raise ValueError('Configuration owner is unavailable.')
        coordinator = ModeCoordinator(
            config_store.mode_port(config), WindowsUploadModeStore(),
        )
        view = coordinator.refresh()
        with coordinator.acquire_work(view, 'legacy') as work, ExitStack() as ownership:
            setup_logging()
            once_lease = WorkspaceLease(watcher_state.CONFIG_DIR, lock_name=WATCHER_ONCE_LOCK)
            ownership.callback(once_lease.release)
            profile = WatcherProfile.load(watcher_state.CONFIG_DIR / 'flights')
            if profile is not None:
                if (profile.pending or not profile.binding
                        or profile.binding != connection_binding(
                            http_transport.normalize_upload_url(config.gallery_url), config.api_key.strip(),
                        )):
                    logging.warning(
                        '[watcher-recovery] stage=once; reason=watcher_connection_not_selected',
                    )
                    return 1
                config = replace(config, **profile.preferences)
                if not config.watched_folder:
                    logging.warning('[watcher-recovery] stage=once; reason=watch_folder_unset')
                    return 1
            events: "queue.Queue[Tuple[str, str]]" = queue.Queue()
            worker = WatcherThread(config, events, once_work=work)
            folder = Path(config.watched_folder)
            upload_url = http_transport.normalize_upload_url(config.gallery_url)
            pipeline = UploadPipelineLease(watcher_state.CONFIG_DIR)
            pipeline.acquire()
            try:
                worker.scan_once(folder, upload_url)
            finally:
                if worker._pending_transport is not None:
                    worker._pending_transport.wait()
                pipeline.release()

            while not events.empty():
                event = events.get()
                if isinstance(event, tuple):
                    level, message = event
                    print(f"{level.upper()}: {message}")
            if worker._queue is not None and worker._queue.head() is not None:
                return 1
        return 0
    except Exception:  # noqa: BLE001
        # Never expose config contents or interpret failed evidence as key mode.
        if sys.stderr is not None:
            print('Upload mode or one-scan work could not be verified.', file=sys.stderr)
        return 1


def parse_args(argv: List[str]) -> argparse.Namespace:
    """
    Parse optional command-line switches.

    Args:
        argv (List[str]): Command-line argument list without the executable
            name.

    Returns:
        argparse.Namespace: once/startup/configure_startup boolean mode flags,
            optional after_update and gui_child nonces, validated GUI override
            and fallback code, and original config path. Operating modes are
            mutually exclusive.
    """
    parser = argparse.ArgumentParser(description="Watch a folder and upload new images to PHP Gallery.")
    modes = parser.add_mutually_exclusive_group()
    modes.add_argument("--once", action="store_true", help=gettext("Run one scan using saved configuration and exit."))
    modes.add_argument(
        "--startup", action="store_true",
        help=gettext("Start the installed app in the tray after Windows logon."),
    )
    modes.add_argument(
        "--configure-startup", action="store_true",
        help=gettext("Apply the saved per-user Windows startup preference without a UI."),
    )
    modes.add_argument('--after-update', metavar='SESSION', help=argparse.SUPPRESS)
    parser.add_argument("--config", default=str(watcher_state.CONFIG_PATH), help=gettext("Path to a config JSON file."))
    parser.add_argument(
        "--gui-backend", choices=GUI_BACKENDS,
        help=gettext("Use a GUI backend for this launch only."),
    )
    parser.add_argument(GUI_CHILD_OPTION, help=argparse.SUPPRESS)
    parser.add_argument("--gui-fallback", choices=GUI_FALLBACK_REASONS, help=argparse.SUPPRESS)
    result = parser.parse_args(argv)
    if (result.after_update is not None
            and re.fullmatch(r'[0-9a-f]{32}', result.after_update) is None):
        parser.error('Invalid update restart identity.')
    if result.gui_child is not None and (
        re.fullmatch(r"[0-9a-f]{32}", result.gui_child) is None
        or result.once or result.configure_startup
        or result.gui_backend is not None or result.gui_fallback is not None
        or gui_startup_directory() is None
    ):
        parser.error("Invalid supervised desktop startup.")
    return result


def _launch_desktop(config: str, arguments: list[str]) -> int:
    """Start an independent desktop process after releasing its profile owner.

    Args:
        config (str): Config path from the original command line.
        arguments (list[str]): Additional allowlisted startup or recovery flags.
    Returns:
        int: Zero if the process was launched, or one if launch failed.
    """
    command = launcher_command(LAUNCHER_PATH, config)
    try:
        subprocess.Popen([*command, *arguments], env=launcher_environment())
    except OSError as exc:
        logging.error("WinApp relaunch failed: %s", type(exc).__name__)
        return 1
    return 0


def _start_qt_supervisor(
    config: str, startup: bool, after_update: str | None = None,
) -> int:
    """Start the same onedir launcher and recover to Tk if Qt startup fails.

    Args:
        config (str): Config path from the original command line.
        startup (bool): Retain the original minimized logon intent.
        after_update (str | None): Exact installer continuation nonce when supplied.
    Returns:
        int: Zero after readiness, activation or a launched recovery process;
            one if a safe process exit or the recovery launch cannot be proven.
    """
    arguments = ["--startup"] if startup else []
    if after_update is not None:
        arguments.extend(('--after-update', after_update))
    command = launcher_command(LAUNCHER_PATH, config)
    try:
        reason = supervise_gui_startup([*command, *arguments])
    except OSError:
        if sys.stderr is not None:
            print("Could not verify Qt startup process exit.", file=sys.stderr)
        return 1
    if reason is None:
        return 0
    return _launch_desktop(config, [
        *arguments, "--gui-backend", LEGACY_GUI_BACKEND, "--gui-fallback", reason,
    ])


def main(argv: Optional[List[str]] = None) -> int:
    """
    Application entry point.

    Args:
        argv (Optional[List[str]]): Optional command-line argument list. When
            omitted, sys.argv is.

    Returns:
        int: Zero after a normal exit, relaunch, activation or startup
            reconciliation. One after a failed launch, configuration or
            coordination, or --once overlapping a running app.
    """
    args = parse_args(list(argv) if argv is not None else sys.argv[1:])
    instance = WindowsInstance(watcher_state.CONFIG_DIR)
    handshake = gui_startup_directory() if args.gui_child is not None else None
    if args.gui_child is not None and handshake is None:
        return 2
    transferred = False
    try:
        if not instance.acquire():
            if args.once:
                return 1
            if not args.startup and not args.configure_startup:
                instance.request_activation()
            if handshake is not None:
                report_gui_startup(handshake, args.gui_child, "activated")
            return 0
        store = watcher_state.ConfigStore(args.config)
        reason: GuiFallback | None = args.gui_fallback
        if handshake is not None:
            if run_qt is None:
                report_gui_startup(handshake, args.gui_child, "refused", "dependency_missing")
                return 1
        elif not args.once and not args.configure_startup:
            requested = args.gui_backend or load_gui_backend(watcher_state.CONFIG_DIR)
            if requested == "pyside6":
                # A damaged shared configuration cannot be repaired by changing
                # toolkits. Report it before passing ownership to either child.
                try:
                    store.load(read_only=True)
                except OSError:
                    setup_logging()
                    raise
                # The child uses this same app-local runtime and independently
                # reacquires the profile and verifies its current configuration.
                instance.close()
                transferred = True
                return _start_qt_supervisor(args.config, args.startup, args.after_update)
        # A second launcher never opens/rotates logs or reads/writes app state.
        setup_logging()
        config = store.load(read_only=True) if handshake is not None else store.load()
        if args.once:
            return run_once(config, store)
        if args.configure_startup:
            synchronize_startup(config.start_with_windows)
            return 0
        if args.startup and not config.start_with_windows:
            synchronize_startup(False)
            if handshake is not None:
                report_gui_startup(handshake, args.gui_child, "activated")
            return 0
        launch = ('update' if args.after_update is not None else
                  'windows_sign_in' if args.startup else 'manual')
        logging.info(
            '[watcher-process] phase=start; reason=%s; pid=%d; version=%s',
            launch, os.getpid(), runtime.APP_VERSION,
        )
        if handshake is not None:
            qt_mode: GuiModeOwner | None = None
            qt_mode_lock = threading.Lock()

            def saved_gui_mode() -> GuiModeOwner:
                """Initialize the shared Qt mode owner before work admission.

                Returns:
                    GuiModeOwner: Verified profile owner reused across active AI
                        and Import work.
                Raises:
                    ModeCoordinatorError: Current saved mode cannot be verified.
                """
                nonlocal qt_mode
                with qt_mode_lock:
                    if qt_mode is None:
                        owner = GuiModeOwner(
                            store.mode_port(config),
                            (lambda: MacUploadModeStore(watcher_state.CONFIG_DIR))
                            if sys.platform == 'darwin' else WindowsUploadModeStore,
                        )
                        owner.initialize()
                        qt_mode = owner
                    return qt_mode

            def prepare_ai(events: "queue.Queue[tuple[str, str]]") -> AIAnalysisWorkerThread:
                """Admit an explicit Qt AI start against the saved legacy mode.

                Args:
                    events (queue.Queue[tuple[str, str]]): Current bounded
                        severity and status queue owned by the Qt view.
                Returns:
                    AIAnalysisWorkerThread: Existing unstarted worker.
                Raises:
                    ModeCoordinatorError: Saved mode, configuration or
                        credentials cannot admit the AI worker.
                    LinkedAiModeRefusal: The verified saved mode is linked and
                        cannot authorize the API-key AI worker.
                """
                saved = desktop.current() if desktop is not None else config
                if not saved.ai_worker_enabled or not saved.gallery_url.strip() or not saved.api_key.strip():
                    raise ModeCoordinatorError("Saved AI configuration is unavailable.")
                owner = saved_gui_mode()
                mode_view = owner.view()
                if mode_view.state in ('linked_active', 'linked_disconnected'):
                    raise LinkedAiModeRefusal()
                if mode_view.state not in LEGACY_UPLOAD_STATES:
                    raise ModeCoordinatorError("Saved AI mode is unavailable.")
                return create_ai_worker(store, saved, owner, events)

            import_actions = None
            import_binding = None
            capture = None
            settings = None
            connections = None
            capture_binding = None
            capture_scope = ''
            installer_restart = None
            desktop: DesktopConfiguration | None = None
            api_url = http_transport.normalize_upload_url(config.gallery_url)
            api_key = config.api_key.strip()
            if sys.platform in {'win32', 'darwin'}:
                try:
                    if sys.platform == 'darwin':
                        assert_local_macos_profile(watcher_state.CONFIG_DIR)
                        assert_local_macos_profile(store.path.parent)
                    else:
                        assert_local_windows_profile(watcher_state.CONFIG_DIR)
                        assert_local_windows_profile(store.path.parent)
                    owner = saved_gui_mode()
                    desktop = DesktopConfiguration(store, config, owner)
                    settings, connections = desktop.settings, desktop.connections
                    view = owner.view()
                    if view.state in LEGACY_UPLOAD_STATES and api_url and api_key:
                        import_binding = connection_binding(api_url, api_key)
                        capture_binding = import_binding
                    elif view.record is not None and view.record.mode == 'linked':
                        record = view.record
                        assert record.issuer is not None and record.account_id is not None and record.gallery_id is not None
                        capture_binding = linked_target_binding(record.issuer, record.account_id, record.gallery_id)
                        capture_scope = record.scope or ''
                    import_actions = desktop_import_actions(store, desktop.current, saved_gui_mode, desktop.active)
                    capture = DesktopCapture(
                        store, desktop.current, owner, desktop.active,
                        desktop.restore_capture_access, desktop.persist_revoked_config,
                        desktop.persist_abandoned_config,
                        desktop.save_watcher_preference,
                    )
                except (OSError, ModeCoordinatorError):
                    logging.warning('Qt local upload settings could not be verified.')
            if args.after_update is not None:
                try:
                    ticket = update_helper.load_ticket(
                        update_helper.updates_root() / args.after_update / 'ticket.json',
                    )
                    if (not ticket.resume_capture
                            or Path(ticket.installed_executable) != update_helper.installed_identity()):
                        raise ValueError('Update continuation ticket does not match this installation.')
                    installer_restart = ticket.session_id, ticket.target_version
                except (OSError, RuntimeError, ValueError):
                    report_gui_startup(handshake, args.gui_child, 'refused', 'update_continuation')
                    return 1
            start_work = inspect_gui_profile_work(
                watcher_state.CONFIG_DIR, legacy_import_binding=import_binding,
                capture_binding=capture_binding, capture_scope=capture_scope,
                restart_version=runtime.APP_VERSION if capture is not None else None,
                installer_restart=installer_restart,
                linked_history_recovery=connections is not None and connections.recovery is not None,
            )
            if start_work.blocked:
                logging.warning('Qt profile admission refused: %s', start_work.refusal_stage)
                report_gui_startup(handshake, args.gui_child, 'refused', 'pending_work')
                return 1
            resume_capture = (
                start_work.restart is not None
                and start_work.restart.phase == 'prepared'
            )
            if installer_restart is not None and start_work.restart is None:
                # Older idle Qt updaters used the ticket's active-capture default.
                # The shared admission above already checked the retained profile.
                logging.info(
                    'Qt update restart accepted with Watcher resume disabled.'
                )
            if capture is not None:
                capture.restart_record = start_work.restart
                capture.restart_ordinary = installer_restart is None
            restart = run_qt(
                profile=watcher_state.CONFIG_DIR,
                version=runtime.APP_VERSION,
                gallery_address=config.gallery_url,
                history_limit=config.activity_history_limit,
                close_to_tray=config.close_to_tray,
                start_minimized=args.startup,
                activation_requested=instance.activation_requested,
                ready=lambda: report_gui_startup(handshake, args.gui_child, "ready"),
                ai_factory=prepare_ai if desktop is not None else None,
                import_actions=import_actions,
                recoverable_imports=start_work.recoverable_imports,
                capture=capture,
                recoverable_capture=start_work.recoverable_capture,
                resume_capture=resume_capture,
                reset_watcher=start_work.reset_pending,
                runtime_settings=settings,
                connections=connections,
                dependencies=DependencyActions(
                    status=lambda: runtime.thumbnail_runtime_status(for_display=True),
                    repair=runtime.install_dependencies_for_current_runtime,
                    install_ai=ai_dependencies.install_semantic_ai_dependencies_for_current_runtime,
                    bundled=getattr(sys, 'frozen', False),
                ),
                redact_ai=lambda message: redact_text(
                    message, [(desktop.current() if desktop is not None else config).api_key.strip()],
                    redact_paths=False,
                ),
            )
        else:
            if args.after_update is None:
                app = WatcherApp(config_store=store, start_minimized=args.startup)
            else:
                app = WatcherApp(
                    config_store=store, start_minimized=False,
                    after_update=args.after_update,
                )
            app.instance = instance
            if getattr(sys, "frozen", False):
                qt_loaded = any(name == "PySide6" or name.startswith("PySide6.") for name in sys.modules)
                logging.info("Frozen Tk startup imported Qt: %s", "yes" if qt_loaded else "no")
            logging.info("Desktop backend ready: tkinter; pid=%d", os.getpid())
            app.root.after(ACTIVATION_POLL_MS, app.poll_instance_activation)
            if reason is not None:
                logging.warning("Qt startup fallback: %s", reason)
                if not args.startup and args.after_update is None:
                    app.root.after_idle(lambda: runtime.messagebox.showwarning(
                        gettext("GUI backend"), fallback_notice(reason), parent=app.root,
                    ))
            app.run()
            restart = getattr(app, "_restart_requested", False) is True
        logging.info('[watcher-process] phase=mainloop_returned; pid=%d', os.getpid())
    except OSError as exc:
        # Windowed builds may have no stderr. Native coordination failure must
        # never admit a second writer, and needs a visible manual-launch error.
        logging.error('%s; pid=%d', format_watch_recovery_diagnostic(
            'process', 'io_error', error=exc,
        ), os.getpid())
        if handshake is not None:
            return 1
        if not args.configure_startup and not args.once and runtime.messagebox is not None:
            configure_language(load_ui_language(watcher_state.CONFIG_DIR))
            runtime.messagebox.showerror(gettext("Application startup"), gettext(str(exc)))
        elif sys.stderr is not None:
            print("Application startup failed: " + str(exc), file=sys.stderr)
        return 1
    except Exception as exc:  # noqa: BLE001
        logging.error('%s; pid=%d', format_watch_recovery_diagnostic(
            'process', 'unhandled_exception', error=exc,
        ), os.getpid())
        if handshake is not None:
            return 1
        raise
    finally:
        if not transferred:
            instance.close()
    if restart:
        return _launch_desktop(args.config, [])
    return 0
