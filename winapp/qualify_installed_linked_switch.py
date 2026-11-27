# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/qualify_installed_linked_switch.py
# Module Type: Hosted Windows Qualification
# Purpose: Verify a synthetic saved linked grant across installed GUI switches.
# Responsibilities:
#   - Obtain real fixture consent through the production linking and mode owners.
#   - Use the current-user Windows vault and exact installed Tk/Qt restart path.
#   - Prove passive Qt browsing and explicit target activation without dispatch.
#   - Remove only credentials and mode state created for this isolated profile.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)
"""Qualify an installed Tk/Qt round trip with an isolated linked target."""

import ctypes
from ctypes import wintypes
import json
import os
from http.server import BaseHTTPRequestHandler
from pathlib import Path
import re
import subprocess
import threading
import time
from types import TracebackType
from unittest import mock
from urllib.parse import parse_qs, urlsplit
from uuid import uuid4

from PIL import ImageGrab

from qualify_loopback_authority import (
    FIXTURE_ADMIN_ID, FIXTURE_CALLBACK_WAIT_SECONDS,
    FIXTURE_GALLERY_ID, FIXTURE_OPERATION_TIMEOUT_SECONDS,
    LoopbackLinkingAuthority,
)
from qt_installer_tools import (
    INPUT_SETTLE_SECONDS, INSTALLED_SMOKE_SECONDS, SYSTEM32_LIBRARY_FLAG,
    choose_installed_backend, installed_window, request_window_close,
    send_installed_keys, wait_for_desktop_exit, wait_installed_backend,
)
from uploader import app_linking, credential_store
from uploader.config_snapshot import ConfigSnapshot, ConfigSnapshotStore
from uploader.credential_store import (
    WindowsCredentialStore, WindowsUploadModeStore,
)
from uploader.desktop_connection import persist_browser_upload_grant
from uploader.gui_backend import load_gui_backend
from uploader.linked_upload import (
    LinkedUploadTarget, linked_target_binding, verify_linked_upload_target,
)
from uploader.mode_coordinator import ModeCoordinator
from uploader.offline_linking import (
    load_offline_binding, offline_binding_path,
    restore_saved_offline_session, saved_offline_hint_path,
)
from uploader.upload_mode import ModeRecord, profile_path_digest
from uploader.config import APP_PROFILE_NAME
from uploader.state_store import atomic_write_json, linked_upload_state_path


# Purpose: Bound a visible installed linked restore after each fresh launch.
# Type: int. Units: seconds. Scope: one native backend restart.
# Consumers: _wait_for_link_restore. Rationale: The same local refresh and
# identity checks must complete before this qualification accepts a switch.
LINKED_RESTORE_WAIT_SECONDS = 45
# Purpose: Observe only the loopback authority and installed app lifecycle.
# Type: float. Units: seconds. Scope: one hosted profile transition.
# Consumers: _wait_for_link_restore. Rationale: Polling must not become a busy loop.
LINKED_RESTORE_POLL_SECONDS = 0.1
# Purpose: Name the bounded update cache entry that suppresses unrelated checks.
# Type: str. Units: relative path. Scope: disposable installed profile.
# Consumers: qualify_installed_linked_switch. Rationale: Keep this journey focused
# on the saved linked credential and native backend switch.
LINKED_UPDATE_STATE_PATH = Path("updates") / "state.json"
# Purpose: Limit unrelated update-cache bytes in the fixture profile.
# Type: int. Units: bytes. Scope: one local update suppression marker.
# Consumers: qualify_installed_linked_switch. Rationale: Marker holds fixed JSON.
LINKED_UPDATE_STATE_MAX_BYTES = 512
# Purpose: Bound the installed startup log observation used for explicit-link proof.
# Type: int. Units: bytes. Scope: one disposable profile log tail.
# Consumers: _read_log_tail. Rationale: The fixture needs one fixed startup marker.
LINKED_LOG_TAIL_MAX_BYTES = 256 * 1024
# Purpose: Bound startup and native action observation without busy polling.
# Type: int. Units: seconds. Scope: one installed linked UI action.
# Consumers: installed startup and native linked UI wait helpers.
# Rationale: Hosted callback and desktop actions normally settle well within this window.
LINKED_UI_ACTION_WAIT_SECONDS = 30
# Purpose: Poll native UI state while the installed process owns the desktop.
# Type: float. Units: seconds. Scope: one bounded hosted action wait.
# Consumers: native linked UI wait helpers. Rationale: Keeps observation responsive
# without a busy loop.
LINKED_UI_POLL_SECONDS = 0.1
# Purpose: Let Qt passive restore finish before requiring explicit target activation.
# Type: float. Units: seconds. Scope: one fresh Qt process in this fixture.
# Consumers: _wait_for_qt_passive_restore. Rationale: Covers startup event turns
# without imposing the full action wait on ordinary launch.
LINKED_QT_STARTUP_OBSERVE_SECONDS = 2.0
# Purpose: Let each installed event loop render the delivered local list response.
# Type: float. Units: seconds. Scope: one hosted gallery-list observation.
# Consumers: _wait_for_gallery_read, _wait_for_identity_read.
# Rationale: Read counters change only after the full HTTP response is written.
LINKED_ROUTE_SETTLE_SECONDS = 1.25
# Purpose: Require the same two independent Admin reads used by target verification.
# Type: int. Units: identity requests. Scope: one installed Tk/Qt target selection.
# Consumers: _wait_for_target_verification. Rationale: A single list render does
# not prove that the installed app accepted and verified the chosen upload target.
LINKED_TARGET_IDENTITY_CONFIRMATIONS = 2
# Purpose: Identify the exclusive gallery cursor used by exact target verification.
# Type: int. Units: gallery ID. Scope: one synthetic installed upload target.
# Consumers: _install_gallery_read_counter and _wait_for_target_verification.
# Rationale: The verifier requests the page immediately before the chosen ID.
LINKED_TARGET_GALLERY_CURSOR = FIXTURE_GALLERY_ID - 1
# Purpose: Require one vault rotation for Qt's passive startup restoration.
# Type: int. Units: refresh-token exchanges. Scope: one Qt backend restart.
# Consumers: _wait_for_qt_passive_restore. Rationale: The fixture has one saved
# credential and startup must not loop or request fresh browser consent.
LINKED_PASSIVE_REFRESH_EXCHANGES = 1
# Purpose: Address native input with the documented Windows virtual-key codes.
# Type: int. Units: Win32 virtual-key codes. Scope: hosted installed GUI actions.
# Consumers: _open_tk_gallery_selection, _open_qt_saved_reconnect.
# Rationale: Use advertised English menu mnemonics and native activation.
_VK_MENU, _VK_T, _VK_R, _VK_RETURN = 0x12, 0x54, 0x52, 0x0D
# Purpose: Invoke the same guarded gallery-picker action in installed Tk and Qt.
# Type: tuple[int, int, int]. Units: Win32 virtual-key chord. Scope: one owned app.
# Consumers: _open_tk_gallery_selection. Rationale: Avoid toolkit child captions.
_VK_CONTROL, _VK_SHIFT, _VK_G = 0x11, 0x10, 0x47
# Purpose: Bound native screenshots retained around the picker transition.
# Type: int. Units: pixels. Scope: one installed app window capture.
# Consumers: _capture_linked_window. Rationale: Keep diagnostics small and
# restrict retained evidence to the isolated qualification application.
LINKED_SCREEN_MAX_PIXELS = 4_000_000
# Purpose: Bound native screenshot files stored in the qualification work tree.
# Type: int. Units: bytes. Scope: one installed app window capture.
# Consumers: _capture_linked_window. Rationale: PNG evidence must remain bounded.
LINKED_SCREEN_MAX_BYTES = 8 * 1024 * 1024
# Purpose: Give prior-target history a binding distinct from the active fixture.
# Type: int. Units: gallery ID offset. Scope: one synthetic previous selection.
# Consumers: qualify_installed_linked_switch. Rationale: Preserve a second local
# target ledger without claiming the loopback authority offered that gallery.
LINKED_PRIOR_GALLERY_ID_OFFSET = 1
# Purpose: Identify deterministic synthetic bytes in the prior-target journal.
# Type: str. Units: lowercase SHA-256 characters. Scope: one unreviewed marker.
# Consumers: qualify_installed_linked_switch. Rationale: No source file or real
# user photo is needed to prove passive profile preservation.
LINKED_PRIOR_MARKER_SHA256 = "e" * 64
# Purpose: Represent the absent source path recorded by the synthetic marker.
# Type: Path. Units: relative path. Scope: disposable qualification work tree.
# Consumers: qualify_installed_linked_switch. Rationale: Startup must leave the
# unresolved source untouched and must not open a real or fixture photo.
LINKED_PRIOR_MARKER_SOURCE = Path("prior-target") / "unreviewed-photo.jpg"
# Purpose: Bound exact bytes read back from the synthetic linked ledger.
# Type: int. Units: bytes. Scope: one tiny installed-switch fixture marker.
# Consumers: _verify_profile_after_switch and fixture seeding.
# Rationale: The marker contains one fixed unresolved-source record only.
LINKED_PRIOR_HISTORY_MAX_BYTES = 4096


def _capture_linked_window(pid: int, work: Path, label: str) -> Path | None:
    """Retain visible installed pixels, including native child controls.

    Args:
        pid (int): Effective PID that must own the foreground desktop window.
        work (Path): Isolated qualification artifact/work directory.
        label (str): Fixed filename stem for the transition being observed.
    Returns:
        Path | None: Saved PNG, or None when evidence capture is unavailable.
    """
    folder = work / 'linked-window-captures'
    path = folder / f'{label}.png'
    try:
        window = installed_window(pid)
        if window is None:
            return None
        user = ctypes.WinDLL('user32.dll', use_last_error=True, winmode=SYSTEM32_LIBRARY_FLAG)
        user.GetForegroundWindow.restype = wintypes.HWND
        user.GetWindowThreadProcessId.argtypes = [wintypes.HWND, ctypes.POINTER(wintypes.DWORD)]
        user.GetWindowRect.argtypes = [wintypes.HWND, ctypes.POINTER(wintypes.RECT)]
        owner = wintypes.DWORD()
        user.GetWindowThreadProcessId(user.GetForegroundWindow(), ctypes.byref(owner))
        bounds = wintypes.RECT()
        if owner.value != pid or not user.GetWindowRect(window, ctypes.byref(bounds)):
            return None
        width, height = bounds.right - bounds.left, bounds.bottom - bounds.top
        if width <= 0 or height <= 0 or width * height > LINKED_SCREEN_MAX_PIXELS:
            return None
        folder.mkdir(exist_ok=True)
        # A window-only GDI buffer can omit child widgets. Crop the actual
        # visible desktop inside the verified fixture window instead.
        with ImageGrab.grab(bbox=(bounds.left, bounds.top, bounds.right, bounds.bottom)) as capture:
            capture.save(path, format='PNG')
        size = path.stat().st_size
    except Exception as error:
        if path.exists():
            try:
                path.unlink()
            except OSError:
                pass
        print(
            'Native installed screenshot unavailable: capture error='
            + type(error).__name__ + '.', flush=True,
        )
        return None
    if size > LINKED_SCREEN_MAX_BYTES:
        try:
            path.unlink(missing_ok=True)
        except OSError:
            pass
        print('Native installed screenshot unavailable: byte bound exceeded.', flush=True)
        return None
    return path


def _press_installed_key(pid: int, key: int) -> None:
    """Send one native key to the exact installed application window.

    Args:
        pid (int): Exact effective desktop process selected by the hosted fixture.
        key (int): One Win32 virtual key accepted by the existing input helper.
    Returns:
        None: The key was sent to the foreground fixture window.
    """
    send_installed_keys(pid, (key,))
    time.sleep(INPUT_SETTLE_SECONDS)


def _read_log_tail(path: Path) -> str:
    """Read only a bounded tail from the isolated installed process log.

    Args:
        path (Path): Exact watcher log inside the fixture-owned profile.
    Returns:
        str: UTF-8 replacement-decoded bounded tail, or an empty string if absent.
    """
    if not path.is_file():
        return ""
    with path.open("rb") as source:
        source.seek(0, os.SEEK_END)
        size = source.tell()
        if size > LINKED_LOG_TAIL_MAX_BYTES:
            source.seek(size - LINKED_LOG_TAIL_MAX_BYTES)
        else:
            source.seek(0)
        return source.read(LINKED_LOG_TAIL_MAX_BYTES).decode("utf-8", errors="replace")


def _startup_wait_marker_count(log_path: Path) -> int:
    """Count the fixed waiting marker in the bounded current log tail.

    Args:
        log_path (Path): Isolated installed process log.
    Returns:
        int: Number of observed linked-startup waiting diagnostics.
    """
    return _read_log_tail(log_path).count("saved_linked_mode_waiting_for_live_target")


def _wait_for_startup_wait_marker(log_path: Path, prior: int = 0) -> None:
    """Require normal linked startup to wait for explicit upload-target choice.

    Args:
        log_path (Path): Isolated installed process log containing fixed diagnostics.
        prior (int): Marker count before this specific Tk process launched.
    Returns:
        None: The process recorded its safe saved-mode waiting state.
    Raises:
        RuntimeError: The bounded startup observation lacks the fixed marker.
    """
    deadline = time.monotonic() + LINKED_UI_ACTION_WAIT_SECONDS
    while time.monotonic() < deadline:
        if _startup_wait_marker_count(log_path) > prior:
            return
        time.sleep(LINKED_UI_POLL_SECONDS)
    raise RuntimeError("Installed startup did not record its explicit-target wait state.")


def _install_gallery_read_counter(
    authority: LoopbackLinkingAuthority, gallery_reads: list[int],
    target_gallery_reads: list[int], identity_reads: list[int],
    operation_attempts: list[int],
) -> None:
    """Count delivered safe reads and forbidden dispatch attempts unchanged.

    Args:
        authority (LoopbackLinkingAuthority): Disposable hosted HTTP authority.
        gallery_reads (list[int]): Gallery-list count updated by server threads.
        target_gallery_reads (list[int]): Counts only exact verifier cursor requests.
        identity_reads (list[int]): Identity count updated by server threads.
        operation_attempts (list[int]): Local upload/inventory attempt count.
    Returns:
        None: The original handler remains responsible for every response.
    """
    handler_type = authority.server.RequestHandlerClass
    original_get = handler_type.do_GET

    def observe_get(handler: BaseHTTPRequestHandler) -> None:
        """Delegate the exact local read before publishing its completion count.

        Args:
            handler (BaseHTTPRequestHandler): One server-owned HTTP request.
        Returns:
            None: The original fixture route writes the response.
        """
        query = parse_qs(urlsplit(handler.path).query)
        route = query.get("page", [""])[0]
        if route in ("app_linking_inventory", "app_linking_upload"):
            operation_attempts[0] += 1
        original_get(handler)
        if route == "app_linking_galleries":
            gallery_reads[0] += 1
            if query.get("after", [""])[0] == str(LINKED_TARGET_GALLERY_CURSOR):
                target_gallery_reads[0] += 1
        elif route == "app_linking_identity":
            identity_reads[0] += 1

    handler_type.do_GET = observe_get
    original_post = handler_type.do_POST

    def observe_post(handler: BaseHTTPRequestHandler) -> None:
        """Count only forbidden synthetic dispatch routes then delegate unchanged.

        Args:
            handler (BaseHTTPRequestHandler): One server-owned HTTP request.
        Returns:
            None: The original fixture route remains responsible for the response.
        """
        route = parse_qs(urlsplit(handler.path).query).get("page", [""])[0]
        if route in ("app_linking_inventory", "app_linking_upload"):
            operation_attempts[0] += 1
        original_post(handler)

    handler_type.do_POST = observe_post


def _wait_for_gallery_read(
    counter: list[int], prior: int, *, description: str = "The explicit Tk gallery picker",
) -> None:
    """Wait for the installed gallery surface to read its server list.

    Args:
        counter (list[int]): Request counter installed on the local fixture server.
        prior (int): Gallery-list count before opening the visible picker.
        description (str): Fixed operation label for safe timeout diagnostics.
    Returns:
        None: A new exact loopback list request reached the fixture.
    Raises:
        RuntimeError: No gallery-list request arrived within the bounded window.
    """
    deadline = time.monotonic() + LINKED_UI_ACTION_WAIT_SECONDS
    while time.monotonic() < deadline:
        if counter[0] > prior:
            time.sleep(LINKED_ROUTE_SETTLE_SECONDS)
            return
        time.sleep(LINKED_UI_POLL_SECONDS)
    raise RuntimeError(description + " did not read its list.")


def _wait_for_identity_read(counter: list[int], prior: int) -> None:
    """Wait until the current process verifies the fixture Admin identity.

    Args:
        counter (list[int]): Identity request count from the fixture server.
        prior (int): Count before the current installed restore attempt.
    Returns:
        None: A new identity check reached the synthetic authority.
    Raises:
        RuntimeError: The identity route was not reached in time.
    """
    deadline = time.monotonic() + LINKED_UI_ACTION_WAIT_SECONDS
    while time.monotonic() < deadline:
        if counter[0] > prior:
            time.sleep(LINKED_ROUTE_SETTLE_SECONDS)
            return
        time.sleep(LINKED_UI_POLL_SECONDS)
    raise RuntimeError("The installed linked action did not verify fixture identity.")


def _wait_for_target_verification(
    identity_reads: list[int], identity_prior: int,
    target_gallery_reads: list[int], target_gallery_prior: int,
) -> None:
    """Require the real target verifier to read identity twice and the gallery.

    Args:
        identity_reads (list[int]): Loopback count for identity verification calls.
        identity_prior (int): Identity count after the visible gallery list loaded.
        target_gallery_reads (list[int]): Count for exact exclusive-cursor requests.
        target_gallery_prior (int): Cursor count before explicit target activation.
    Returns:
        None: The installed UI verified the selected target through production.
    Raises:
        RuntimeError: The selected-target identity or gallery reads did not arrive.
    """
    identity_target = identity_prior + LINKED_TARGET_IDENTITY_CONFIRMATIONS
    gallery_target = target_gallery_prior + 1
    deadline = time.monotonic() + LINKED_UI_ACTION_WAIT_SECONDS
    while time.monotonic() < deadline:
        if (identity_reads[0] >= identity_target
                and target_gallery_reads[0] >= gallery_target):
            time.sleep(LINKED_ROUTE_SETTLE_SECONDS)
            return
        time.sleep(LINKED_UI_POLL_SECONDS)
    raise RuntimeError(
        "Installed gallery selection did not finish fresh Admin/gallery verification: "
        f"identity={identity_reads[0] - identity_prior}/"
        f"{LINKED_TARGET_IDENTITY_CONFIRMATIONS}, "
        f"target_gallery={target_gallery_reads[0] - target_gallery_prior}/1."
    )


def _open_tk_gallery_selection(
    pid: int, identity_reads: list[int], gallery_reads: list[int],
    target_gallery_reads: list[int],
    capture_root: Path, phase: str, capture_paths: list[str],
) -> None:
    """Open the restored gallery picker and verify explicit selection behavior.

    Args:
        pid (int): Exact installed Tk process owning the main window.
        identity_reads (list[int]): Loopback count for exact Admin identity reads.
        gallery_reads (list[int]): Fixture server counter for gallery-list reads.
        target_gallery_reads (list[int]): Exact target-verification cursor count.
        capture_root (Path): Uploaded evidence directory for bounded native captures.
        phase (str): Fixed journey label for the captured transition.
        capture_paths (list[str]): Relative image paths retained for the report.
    Returns:
        None: The production shortcut selected the exact listed upload target.
    Raises:
        RuntimeError: A visible native action or the exact row activation failed.
    """
    # The caller already observed passive saved-grant restoration and the
    # corresponding loopback list read. Do not reopen the address manager.
    # The existing Tools shortcut invokes the same guarded header command.
    # Capture the actual installed window at each keyboard stage; Tk themed
    # controls may share a native child, so HWND focus is not a widget oracle.
    gallery_prior = gallery_reads[0]
    before_capture = _capture_linked_window(pid, capture_root, f'{phase}-before-picker')
    send_installed_keys(pid, (_VK_CONTROL, _VK_SHIFT, _VK_G))
    time.sleep(INPUT_SETTLE_SECONDS)
    after_chord_capture = _capture_linked_window(
        pid, capture_root, f'{phase}-after-shortcut',
    )
    _wait_for_gallery_read(gallery_reads, gallery_prior)
    ready_capture = _capture_linked_window(
        pid, capture_root, f'{phase}-picker-ready',
    )
    identity_prior = identity_reads[0]
    target_gallery_prior = target_gallery_reads[0]
    _press_installed_key(pid, _VK_RETURN)
    after_selection_capture = _capture_linked_window(
        pid, capture_root, f'{phase}-after-selection',
    )
    capture_names = tuple(
        capture.name if capture is not None else 'unavailable'
        for capture in (
            before_capture, after_chord_capture, ready_capture, after_selection_capture,
        )
    )
    capture_paths.extend(
        capture.relative_to(capture_root).as_posix()
        for capture in (
            before_capture, after_chord_capture, ready_capture, after_selection_capture,
        )
        if capture is not None
    )
    print('Installed Tk picker captures: ' + ','.join(capture_names), flush=True)
    # The source-defined first Return moves from the query to a selected row;
    # the second Return is the explicit result-row activation.
    time.sleep(LINKED_ROUTE_SETTLE_SECONDS)
    if (identity_reads[0] != identity_prior
            or target_gallery_reads[0] != target_gallery_prior):
        raise RuntimeError(
            'The Tk picker row selection verified a target before explicit activation: '
            f'identity_delta={identity_reads[0] - identity_prior}; '
            'target_cursor_delta='
            f'{target_gallery_reads[0] - target_gallery_prior}; '
            f'captures={capture_names!r}.'
        )
    _press_installed_key(pid, _VK_RETURN)
    try:
        _wait_for_target_verification(
            identity_reads, identity_prior,
            target_gallery_reads, target_gallery_prior,
        )
    except RuntimeError as error:
        raise RuntimeError(
            f'{error} Tk picker screenshots: {capture_names!r}.'
        ) from None


def _open_qt_saved_reconnect(
    pid: int, identity_reads: list[int], target_gallery_reads: list[int],
    gallery_reads: list[int],
) -> None:
    """Invoke Qt's existing Gallery connections → Reconnect saved link action.

    Args:
        pid (int): Exact installed Qt process owning the visible main window.
        identity_reads (list[int]): Fixture identity route counter.
        target_gallery_reads (list[int]): Exact target-verification cursor count.
        gallery_reads (list[int]): All fixture gallery-list reads, including the
            final catalogue read after the exact target has been verified.
    Returns:
        None: The Qt action reverified the exact saved target through production.
    Raises:
        RuntimeError: The visible action did not produce fresh target checks.
    """
    prior_identity = identity_reads[0]
    prior_gallery = target_gallery_reads[0]
    prior_all_gallery_reads = gallery_reads[0]
    send_installed_keys(pid, (_VK_MENU, _VK_T))
    _press_installed_key(pid, _VK_G)
    _press_installed_key(pid, _VK_R)
    _wait_for_target_verification(
        identity_reads, prior_identity, target_gallery_reads, prior_gallery,
    )
    # The target verifier's own cursor GET is the first list read. The
    # production saved-link flow then reads the gallery tree before it
    # publishes completion to the Photos lane. Wait for that second request
    # so the next Settings restart cannot collide with owned picker work.
    _wait_for_gallery_read(
        gallery_reads, prior_all_gallery_reads + 1,
        description="The Qt saved-link reconnect",
    )


def _wait_for_qt_passive_restore(
    authority: LoopbackLinkingAuthority, refresh_prior: int,
    identity_reads: list[int], identity_prior: int,
    gallery_reads: list[int], gallery_prior: int,
    target_gallery_reads: list[int], target_gallery_prior: int,
    operation_attempts: list[int],
) -> None:
    """Wait for Qt's passive vault restore and browse listing before activation.

    Args:
        authority (LoopbackLinkingAuthority): Local protocol exchange observer.
        refresh_prior (int): Refresh count before Qt ordinary startup.
        identity_reads (list[int]): Identity route count from the fixture server.
        identity_prior (int): Identity count before the Qt process launched.
        gallery_reads (list[int]): Gallery route count from the fixture server.
        gallery_prior (int): Gallery count before the Qt process launched.
        target_gallery_reads (list[int]): Exact selected-target cursor requests.
        target_gallery_prior (int): Cursor count before the Qt process launched.
        operation_attempts (list[int]): Local upload/inventory attempt count.
    Returns:
        None: Qt restored one vault session and listed galleries without dispatch.
    Raises:
        RuntimeError: Restore, browse-only state or no-dispatch checks failed.
    """
    _wait_for_link_restore(authority, refresh_prior)
    _wait_for_identity_read(identity_reads, identity_prior)
    _wait_for_gallery_read(gallery_reads, gallery_prior)
    time.sleep(LINKED_QT_STARTUP_OBSERVE_SECONDS)
    refresh_delta = authority.refresh_exchange_count - refresh_prior
    identity_delta = identity_reads[0] - identity_prior
    target_gallery_delta = target_gallery_reads[0] - target_gallery_prior
    if refresh_delta != LINKED_PASSIVE_REFRESH_EXCHANGES:
        raise RuntimeError(
            "Qt passive vault restore used an unexpected refresh count: "
            f"{refresh_delta}/{LINKED_PASSIVE_REFRESH_EXCHANGES}."
        )
    # A complete target verification is identified by the exact selected
    # gallery cursor request, not identity reads alone. Passive restoration
    # already performs one identity check, and other read-only health checks
    # must not be misclassified as upload-target activation.
    if target_gallery_delta != 0:
        raise RuntimeError(
            "Qt passive startup verified the selected upload gallery before "
            "explicit reconnect: "
            f"identity_reads={identity_delta}; "
            f"target_gallery_reads={target_gallery_delta}."
        )
    if operation_attempts[0] != 0:
        raise RuntimeError(
            "Qt passive startup attempted synthetic inventory/upload dispatch: "
            f"count={operation_attempts[0]}."
        )


class _SnapshotModePort:
    """Expose the real config snapshot primitive to a prelaunch mode owner."""

    def __init__(self, path: Path) -> None:
        """Bind one isolated config snapshot before the installed app starts.

        Args:
            path (Path): Exact default config path the installed launcher will use.
        Returns:
            None: Creates a source fixture port without process ownership.
        """
        self.config_path = str(path)
        self._store = ConfigSnapshotStore(path, frozenset({"api_key"}))

    def mode_transaction(self) -> "_SnapshotModePort":
        """Return the fixture context for one serialized prelaunch operation.

        Returns:
            _SnapshotModePort: This isolated snapshot adapter.
        """
        return self

    def __enter__(self) -> "_SnapshotModePort":
        """Enter the fixture adapter without an external app process.

        Returns:
            _SnapshotModePort: This adapter.
        """
        return self

    def __exit__(
        self, exc_type: type[BaseException] | None,
        exc_value: BaseException | None, traceback: TracebackType | None,
    ) -> bool:
        """Propagate all mode fixture failures.

        Args:
            exc_type (type[BaseException] | None): Raised exception class.
            exc_value (BaseException | None): Raised exception instance.
            traceback (TracebackType | None): Raised exception traceback.
        Returns:
            bool: False so fixture failures remain visible.
        """
        return False

    def snapshot(self) -> ConfigSnapshot:
        """Read exact config evidence using the production snapshot owner.

        Returns:
            ConfigSnapshot: Complete isolated file observation.
        """
        return self._store.inspect()

    def read_mode_anchor(self, snapshot: ConfigSnapshot) -> bytes | None:
        """Read the production mode field from the owned snapshot.

        Args:
            snapshot (ConfigSnapshot): Current immutable config observation.
        Returns:
            bytes | None: Exact mode anchor or confirmed absence.
        """
        return self._store.read_mode_anchor(snapshot)

    def write_mode_anchor(
        self, snapshot: ConfigSnapshot, payload: bytes,
    ) -> ConfigSnapshot:
        """Publish the mode field through the production atomic snapshot writer.

        Args:
            snapshot (ConfigSnapshot): Pre-publication config evidence.
            payload (bytes): Encoded owner-issued mode record.
        Returns:
            ConfigSnapshot: Verified post-publication snapshot.
        """
        return self._store.save_mode_anchor(snapshot, payload)


def _read_mode_record(store: WindowsUploadModeStore) -> ModeRecord | None:
    """Read one validated global mode record under its normal vault mutex.

    Args:
        store (WindowsUploadModeStore): Current-user mode-only credential owner.
    Returns:
        ModeRecord | None: Validated record or confirmed absence.
    """
    with store.transaction() as transaction:
        return transaction.read()


def _assert_mode_record(
    store: WindowsUploadModeStore, expected: ModeRecord,
    config_path: Path, issuer: str,
) -> None:
    """Require the exact linked account, scope, target and profile binding.

    Args:
        store (WindowsUploadModeStore): Current-user mode-only vault.
        expected (ModeRecord): Owner-issued fixture mode generation.
        config_path (Path): Exact isolated configuration path.
        issuer (str): Exact synthetic localhost installation.
    Returns:
        None: Stored mode still matches the captured verified selection.
    Raises:
        RuntimeError: The mode record changed or belongs to another profile.
    """
    current = _read_mode_record(store)
    if (current is None or current != expected or current.mode != "linked"
            or current.profile_path_hash != profile_path_digest(str(config_path))
            or current.issuer != issuer or current.account_id != FIXTURE_ADMIN_ID
            or current.gallery_id != FIXTURE_GALLERY_ID
            or not current.scope or not current.scope.endswith(
                app_linking.ROLLING_SCOPE_SUFFIX,
            )):
        raise RuntimeError("The installed linked mode record changed across a restart.")


def _wait_for_link_restore(
    authority: LoopbackLinkingAuthority, prior_count: int,
) -> int:
    """Wait for the exact installed process to rotate the saved local grant.

    Args:
        authority (LoopbackLinkingAuthority): Disposable authority recording
            only protocol exchange counts.
        prior_count (int): Successful fixture refresh count before this launch.
    Returns:
        int: Increased count proving the new installed process restored the grant.
    Raises:
        RuntimeError: The installed app did not restore its exact saved link.
    """
    deadline = time.monotonic() + LINKED_RESTORE_WAIT_SECONDS
    while time.monotonic() < deadline:
        count = authority.refresh_exchange_count
        if count > prior_count:
            return count
        time.sleep(LINKED_RESTORE_POLL_SECONDS)
    raise RuntimeError("Installed backend did not restore the saved linked grant.")


def _remove_exact_mode_record(
    store: WindowsUploadModeStore, expected: ModeRecord,
) -> None:
    """Delete only the exact test-created global mode value under its mutex.

    Args:
        store (WindowsUploadModeStore): Current-user mode-only vault.
        expected (ModeRecord): Exact unique profile record created by this run.
    Returns:
        None: Removes the fixture-owned record or confirms it is already absent.
    Raises:
        RuntimeError: Another mode record replaced the fixture's value.
    """
    with store.transaction() as transaction:
        current = transaction.read()
        if current is None:
            return
        if current != expected:
            raise RuntimeError("Refusing to remove a different Windows mode record.")
        if not store._api.delete(credential_store._UPLOAD_MODE_TARGET):
            raise RuntimeError("The exact qualification mode record was not deleted.")
        if transaction.read() is not None:
            raise RuntimeError("The qualification mode record remained after cleanup.")


def _discover_owned_mode_record(
    store: WindowsUploadModeStore, config_path: Path, issuer: str,
) -> ModeRecord | None:
    """Identify a partially published record by this run's unique profile.

    Args:
        store (WindowsUploadModeStore): Current-user mode-only vault.
        config_path (Path): Unique fixture config path used for publication.
        issuer (str): Ephemeral loopback issuer created by this run.
    Returns:
        ModeRecord | None: Exact linked record owned by this fixture, or None.
    """
    current = _read_mode_record(store)
    if (current is not None and current.mode == "linked"
            and current.profile_path_hash == profile_path_digest(str(config_path))
            and current.issuer == issuer
            and current.account_id == FIXTURE_ADMIN_ID
            and current.gallery_id == FIXTURE_GALLERY_ID):
        return current
    return None


def _cleanup_exact_vault(
    credential: WindowsCredentialStore, mode_store: WindowsUploadModeStore,
    issuer: str, account_id: int, owned_mode: ModeRecord | None,
) -> None:
    """Remove only this run's linked refresh and mode records.

    Args:
        credential (WindowsCredentialStore): Current-user refresh credential owner.
        mode_store (WindowsUploadModeStore): Current-user mode-only owner.
        issuer (str): Unique disposable loopback installation issuer.
        account_id (int): Fixture Admin ID checked before any write.
        owned_mode (ModeRecord | None): Exact mode selection this run published.
    Returns:
        None: The owned vault records are absent and unrelated values are untouched.
    Raises:
        RuntimeError: Exact cleanup cannot be proven.
    """
    try:
        current_credential = credential.read(issuer, account_id)
    except RuntimeError:
        if not credential.discard_pending_refresh(issuer, account_id):
            raise RuntimeError("Qualification refresh cleanup is uncertain.") from None
        current_credential = credential.read(issuer, account_id)
    if current_credential is not None:
        if not credential.delete(issuer, account_id):
            raise RuntimeError("Qualification refresh credential was not deleted.")
    if credential.read(issuer, account_id) is not None:
        raise RuntimeError("Qualification refresh credential remained after cleanup.")
    if owned_mode is not None:
        _remove_exact_mode_record(mode_store, owned_mode)
    elif _read_mode_record(mode_store) is not None:
        raise RuntimeError("Refusing to remove an unowned Windows mode record.")


def _start_installed_tk(
    executable: Path, config_path: Path, environment: dict[str, str],
    log_path: Path,
) -> tuple[subprocess.Popen[bytes], int]:
    """Start the exact installed Tk executable in its isolated profile.

    Args:
        executable (Path): Common installed PHP Gallery launcher.
        config_path (Path): Profile-bound config with the verified mode anchor.
        environment (dict[str, str]): Hosted environment with isolated profiles.
        log_path (Path): Product log where the exact GUI PID is published.
    Returns:
        tuple[subprocess.Popen[bytes], int]: Launcher and visible Tk window PID.
    Raises:
        RuntimeError: The installed Tk process fails to reach visible readiness.
    """
    child_environment = {
        **environment, "PYINSTALLER_RESET_ENVIRONMENT": "1",
    }
    launcher = subprocess.Popen(
        [str(executable), "--config", str(config_path)],
        cwd=executable.parent, env=child_environment,
    )
    try:
        pid = wait_installed_backend(log_path, "tkinter", None)
        return launcher, pid
    except BaseException:
        if launcher.poll() is None:
            launcher.kill()
            launcher.wait(timeout=INSTALLED_SMOKE_SECONDS)
        raise


def _close_owned_gui(pid: int) -> None:
    """Close one exact fixture GUI normally, force-ending only after timeout.

    Args:
        pid (int): GUI process ID previously returned by the installed observer.
    Returns:
        None: The exact process is no longer live.
    Raises:
        RuntimeError: The exact process could not be reaped by the fixture.
    """
    window = installed_window(pid)
    if window is not None:
        request_window_close(pid)
    try:
        wait_for_desktop_exit(pid)
        return
    except (OSError, RuntimeError):
        pass
    terminated = subprocess.run(
        ["taskkill.exe", "/PID", str(pid), "/F"],
        capture_output=True, text=True, timeout=INSTALLED_SMOKE_SECONDS,
        check=False,
    )
    if terminated.returncode != 0:
        raise RuntimeError("The exact qualification GUI could not be reaped.")
    wait_for_desktop_exit(pid)


def _verify_profile_after_switch(
    profile: Path, credential: WindowsCredentialStore,
    mode_store: WindowsUploadModeStore, mode_record: ModeRecord,
    config_path: Path, issuer: str, history_path: Path,
    history_bytes: bytes, history_source: Path,
) -> None:
    """Check the saved grant and unrelated unresolved history after a restart.

    Args:
        profile (Path): Isolated APPDATA product directory.
        credential (WindowsCredentialStore): Current-user refresh vault.
        mode_store (WindowsUploadModeStore): Current-user mode-only vault.
        mode_record (ModeRecord): Exact owner-issued linked mode generation.
        config_path (Path): Exact profile-bound configuration.
        issuer (str): Synthetic localhost issuer from the approved grant.
        history_path (Path): Ledger for the separate synthetic prior target.
        history_bytes (bytes): Exact initial serialized unresolved marker.
        history_source (Path): Absent synthetic photo path recorded by the marker.
    Returns:
        None: Active grant is exact and unrelated marker remains unreviewed.
    Raises:
        RuntimeError: The restart changed grant identity or local ledger bytes.
    """
    _assert_mode_record(mode_store, mode_record, config_path, issuer)
    scope = mode_record.scope
    if not isinstance(scope, str):
        raise RuntimeError("The saved linked scope is unavailable.")
    hint = saved_offline_hint_path(
        profile / "offline-link.json", issuer, scope,
    )
    binding = load_offline_binding(hint, issuer)
    if (binding is None
            or (binding.issuer, binding.account_id, binding.scope)
            != (issuer, FIXTURE_ADMIN_ID, mode_record.scope)
            or credential.read(issuer, FIXTURE_ADMIN_ID) is None):
        raise RuntimeError("Installed restart lost its exact offline linked grant.")
    if (history_path.is_symlink() or history_path.parent.is_symlink()
            or not history_path.is_file()
            or history_path.stat().st_size > LINKED_PRIOR_HISTORY_MAX_BYTES):
        raise RuntimeError("Prior-target linked history became unavailable or unbounded.")
    with history_path.open("rb") as source:
        current_history = source.read(LINKED_PRIOR_HISTORY_MAX_BYTES + 1)
    if (len(current_history) > LINKED_PRIOR_HISTORY_MAX_BYTES
            or current_history != history_bytes or history_source.exists()):
        raise RuntimeError(
            "Installed restart changed or touched the separate unresolved history."
        )


def qualify_installed_linked_switch(
    executable: Path, work: Path, version: str, *, evidence_dir: Path | None = None,
    progress: dict[str, str] | None = None,
) -> dict[str, str]:
    """Create a real loopback linked profile and switch the installed GUI twice.

    Args:
        executable (Path): Exact common installed PHP Gallery executable.
        work (Path): New disposable root owned by the hosted Windows runner.
        version (str): Installed version used to suppress unrelated updates.
        evidence_dir (Path | None): Existing uploaded audit evidence directory,
            or None to place screenshots in the disposable work tree.
        progress (dict[str, str] | None): Optional caller-owned sanitized milestones.
    Returns:
        dict[str, str]: Sanitized target, native vault, restore and switch evidence.
    Raises:
        RuntimeError: Any linking, native mode, credential or GUI check fails.
    """
    if (os.name != "nt" or not executable.is_file() or work.exists()
            or not re.fullmatch(r"\d+\.\d+\.\d+", version)):
        raise RuntimeError("Installed linked qualification inputs are unavailable.")
    work.mkdir(parents=True)
    capture_root = evidence_dir or work
    credential = WindowsCredentialStore()
    mode_store = WindowsUploadModeStore()
    authority = LoopbackLinkingAuthority()
    gallery_reads = [0]
    target_gallery_reads = [0]
    identity_reads = [0]
    operation_attempts = [0]
    _install_gallery_read_counter(
        authority, gallery_reads, target_gallery_reads,
        identity_reads, operation_attempts,
    )
    mode_record: ModeRecord | None = None
    issuer = authority.issuer
    config_path: Path | None = None
    profile: Path | None = None
    restored_session = None
    picker_capture_paths: list[str] = []
    owned_launcher: subprocess.Popen[bytes] | None = None
    gui_pid: int | None = None
    credential_slot_owned = False
    try:
        if _read_mode_record(mode_store) is not None:
            raise RuntimeError("Hosted runner already has a global Windows mode record.")
        if credential.read(issuer, FIXTURE_ADMIN_ID) is not None:
            raise RuntimeError("Unique loopback credential unexpectedly exists before the fixture.")
        credential_slot_owned = True
        roaming_root = work / "roaming"
        local_root = work / "local"
        profile = roaming_root / APP_PROFILE_NAME
        profile.mkdir(parents=True)
        local_profile = local_root / APP_PROFILE_NAME
        local_profile.mkdir(parents=True)
        watch_folder = work / "synthetic-watch-folder"
        watch_folder.mkdir()
        config_path = profile / "config.json"
        config_path.write_text(json.dumps({
            "gallery_url": issuer,
            "api_key": "qualification-only-unused-api-key",
            "watched_folder": str(watch_folder),
            "close_to_tray": False,
            "start_with_windows": False,
            "watch_recursive": False,
        }), encoding="utf-8")
        prior_gallery_id = FIXTURE_GALLERY_ID + LINKED_PRIOR_GALLERY_ID_OFFSET
        prior_binding = linked_target_binding(
            issuer, FIXTURE_ADMIN_ID, prior_gallery_id,
        )
        active_binding = linked_target_binding(
            issuer, FIXTURE_ADMIN_ID, FIXTURE_GALLERY_ID,
        )
        if prior_binding == active_binding:
            raise RuntimeError("Synthetic prior-target history aliases the active target.")
        prior_history_path = linked_upload_state_path(profile, prior_binding)
        prior_history_source = (
            work.resolve() / LINKED_PRIOR_MARKER_SOURCE
        )
        atomic_write_json(prior_history_path, {
            "schema_version": 2,
            "uploaded_paths": {},
            "uploaded_hashes": {},
            "failures": {
                str(prior_history_source): {
                    "sha256": LINKED_PRIOR_MARKER_SHA256,
                    "uncertain": True,
                    "last_error": (
                        "Upload result uncertain; review the gallery manually."
                    ),
                    "last_failed_at": 1.0,
                },
            },
            "jobs": {},
            "recent_events": [],
            "reviewed_upload_attempts": [],
        })
        if (prior_history_path.stat().st_size > LINKED_PRIOR_HISTORY_MAX_BYTES
                or prior_history_source.exists()):
            raise RuntimeError("Synthetic prior-target history setup is invalid.")
        with prior_history_path.open("rb") as source:
            prior_history_bytes = source.read(
                LINKED_PRIOR_HISTORY_MAX_BYTES + 1,
            )
        if len(prior_history_bytes) > LINKED_PRIOR_HISTORY_MAX_BYTES:
            raise RuntimeError("Synthetic prior-target history exceeded its bound.")
        update_state = local_profile / LINKED_UPDATE_STATE_PATH
        update_state.parent.mkdir(parents=True)
        update_state.write_text(json.dumps({
            "last_check": time.time(), "version": version, "available": None,
        }), encoding="utf-8")
        if update_state.stat().st_size > LINKED_UPDATE_STATE_MAX_BYTES:
            raise RuntimeError("Synthetic update suppression marker exceeded its bound.")

        native_environment = {
            **os.environ,
            "APPDATA": str(roaming_root),
            "LOCALAPPDATA": str(local_root),
            "PYINSTALLER_RESET_ENVIRONMENT": "1",
        }
        attempt = app_linking.LinkingAttempt(
            issuer, scope=app_linking.UPLOAD_SCOPE,
        )
        live_session = None
        try:
            native_getaddrinfo = app_linking.socket.getaddrinfo

            def resolve_fixture_host(
                host: str | bytes, port: int | str | None,
                family: int = 0, kind: int = 0, protocol: int = 0,
                flags: int = 0,
            ) -> list[tuple[int, int, int, str, tuple[str, int]]]:
                """Constrain only the disposable issuer to its IPv4 listener.

                Args:
                    host (str | bytes): Requested resolver hostname.
                    port (int | str | None): Requested resolver port.
                    family (int): Requested address family.
                    kind (int): Requested socket kind.
                    protocol (int): Requested transport protocol.
                    flags (int): Native resolver flags.
                Returns:
                    list[tuple[int, int, int, str, tuple[str, int]]]: Fixture
                    listener answer or untouched system resolution.
                """
                if host == "localhost" and port == authority.server.server_port:
                    return native_getaddrinfo(
                        "127.0.0.1", port, family, kind, protocol, flags,
                    )
                return native_getaddrinfo(host, port, family, kind, protocol, flags)

            with mock.patch.object(
                app_linking.socket, "getaddrinfo",
                side_effect=resolve_fixture_host,
            ):
                attempt.push_request(prefer_rolling=True)
                consent_errors: list[BaseException] = []

                def deliver_consent() -> None:
                    """Deliver only the exact synthetic approved callback.

                    Returns:
                        None: Captures a fixture callback failure for the caller.
                    """
                    try:
                        authority.grant_consent()
                    except BaseException as error:
                        consent_errors.append(error)

                consent_thread = threading.Thread(
                    target=deliver_consent, name="QualificationConsentCallback",
                    daemon=True,
                )
                consent_thread.start()
                attempt._deadline = time.monotonic() + FIXTURE_CALLBACK_WAIT_SECONDS
                if attempt.wait_for_callback() != "approved":
                    raise RuntimeError("Local consent did not approve the exact request.")
                consent_thread.join(timeout=FIXTURE_OPERATION_TIMEOUT_SECONDS)
                if consent_thread.is_alive() or consent_errors:
                    raise RuntimeError("Local consent callback did not finish cleanly.")
                live_session, refresh, remaining = attempt.exchange_offline_once()
                identity = live_session.identity()
                target = verify_linked_upload_target(
                    live_session, issuer, identity.user_id,
                    live_session.approved_scope(), FIXTURE_GALLERY_ID,
                )
                persist_browser_upload_grant(
                    profile, credential, issuer, identity, live_session,
                    refresh, remaining, threading.Event(),
                )
                if credential.read(issuer, identity.user_id) is None:
                    raise RuntimeError("The Windows vault did not retain the exact fixture grant.")
                binding_path = offline_binding_path(
                    profile / "offline-link.json", issuer,
                )
                binding = load_offline_binding(binding_path, issuer)
                if (binding is None
                        or (binding.issuer, binding.account_id, binding.scope)
                        != (issuer, identity.user_id, live_session.approved_scope())):
                    raise RuntimeError("The exact offline binding did not read back.")
                mode_owner = ModeCoordinator(
                    _SnapshotModePort(config_path), mode_store,
                )
                selected = mode_owner.select_linked(mode_owner.refresh(), target)
                mode_record = selected.record
                if (selected.state != "linked_active" or mode_record is None
                        or mode_record.mode != "linked"
                        or mode_record.profile_path_hash
                        != profile_path_digest(str(config_path))
                        or mode_record.issuer != issuer
                        or mode_record.account_id != identity.user_id
                        or mode_record.scope != live_session.approved_scope()
                        or mode_record.gallery_id != target.gallery_id):
                    raise RuntimeError("Production mode owner refused the exact fixture target.")
                if mode_record is None:
                    raise RuntimeError("The production mode owner returned no linked record.")
                live_session.close()
                live_session = None
            attempt.close()
        finally:
            if live_session is not None:
                live_session.close()
            attempt.close()

        if mode_record is None or config_path is None or profile is None:
            raise RuntimeError("The exact linked fixture profile was not selected.")
        log_path = profile / "watcher.log"
        initial_refresh_count = authority.refresh_exchange_count
        initial_identity_reads = identity_reads[0]
        initial_gallery_reads = gallery_reads[0]
        launcher, gui_pid = _start_installed_tk(
            executable, config_path, native_environment, log_path,
        )
        owned_launcher = launcher
        _wait_for_link_restore(authority, initial_refresh_count)
        _wait_for_identity_read(identity_reads, initial_identity_reads)
        _wait_for_gallery_read(gallery_reads, initial_gallery_reads)
        _wait_for_startup_wait_marker(log_path)
        _verify_profile_after_switch(
            profile, credential, mode_store, mode_record, config_path, issuer,
            prior_history_path, prior_history_bytes, prior_history_source,
        )
        for backend in ("pyside6", "tkinter"):
            prior_refresh_count = authority.refresh_exchange_count
            prior_identity_reads = identity_reads[0]
            prior_gallery_reads = gallery_reads[0]
            prior_target_gallery_reads = target_gallery_reads[0]
            prior_wait_markers = _startup_wait_marker_count(log_path)
            if gui_pid is None:
                raise RuntimeError("The current installed GUI identity is unavailable.")
            choose_installed_backend(gui_pid, profile, backend, log_path)
            gui_pid = wait_installed_backend(log_path, backend, gui_pid)
            _verify_profile_after_switch(
                profile, credential, mode_store, mode_record, config_path, issuer,
                prior_history_path, prior_history_bytes, prior_history_source,
            )
            if operation_attempts[0] != 0:
                raise RuntimeError("An installed restart dispatched synthetic upload work.")
            if progress is not None:
                progress[f"{backend}_ready_ledger"] = (
                    "Installed GUI ready with the same linked mode, credential, "
                    "previous-target history bytes and zero dispatch."
                )
            if backend == "pyside6":
                _wait_for_qt_passive_restore(
                    authority, prior_refresh_count,
                    identity_reads, prior_identity_reads,
                    gallery_reads, prior_gallery_reads,
                    target_gallery_reads, prior_target_gallery_reads,
                    operation_attempts,
                )
                if progress is not None:
                    progress["qt_passive_restore"] = "Listed the same fixture gallery with one refresh."
                    progress["qt_passive_restore_reads"] = (
                        f"identity={identity_reads[0] - prior_identity_reads}; "
                        "target_gallery="
                        f"{target_gallery_reads[0] - prior_target_gallery_reads}"
                    )
                capture = _capture_linked_window(gui_pid, capture_root, 'qt-ready')
                if capture is not None:
                    picker_capture_paths.append(capture.relative_to(capture_root).as_posix())
                if gui_pid is None:
                    raise RuntimeError("The installed Qt process identity is unavailable.")
                _open_qt_saved_reconnect(
                    gui_pid, identity_reads, target_gallery_reads, gallery_reads,
                )
            else:
                _wait_for_link_restore(authority, prior_refresh_count)
                _wait_for_identity_read(identity_reads, prior_identity_reads)
                _wait_for_gallery_read(gallery_reads, prior_gallery_reads)
                _wait_for_startup_wait_marker(log_path, prior_wait_markers)
                if gui_pid is None:
                    raise RuntimeError("The final installed Tk process identity is unavailable.")
                _open_tk_gallery_selection(
                    gui_pid, identity_reads, gallery_reads, target_gallery_reads,
                    capture_root, 'returned-tk', picker_capture_paths,
                )
            _verify_profile_after_switch(
                profile, credential, mode_store, mode_record, config_path, issuer,
                prior_history_path, prior_history_bytes, prior_history_source,
            )
        if load_gui_backend(profile) != "tkinter":
            raise RuntimeError("The linked backend round trip did not retain its final Tk preference.")
        if operation_attempts[0] != 0:
            raise RuntimeError(
                "Installed linked journey attempted synthetic dispatch: "
                f"count={operation_attempts[0]}."
            )
        if not request_window_close(gui_pid):
            raise RuntimeError("The final linked Tk window refused normal close.")
        wait_for_desktop_exit(gui_pid)
        gui_pid = None
        if launcher.poll() is None:
            try:
                launcher.wait(timeout=INSTALLED_SMOKE_SECONDS)
            except subprocess.TimeoutExpired as error:
                raise RuntimeError("The initial linked Tk launcher remained alive.") from error
        if launcher.returncode != 0:
            raise RuntimeError("The linked Tk launcher did not exit normally.")

        binding_path = offline_binding_path(profile / "offline-link.json", issuer)
        binding = load_offline_binding(binding_path, issuer)
        if binding is None:
            raise RuntimeError("The offline linked binding disappeared after native restarts.")
        if mode_record is None or not isinstance(mode_record.scope, str):
            raise RuntimeError("The exact saved mode scope is unavailable after restarts.")
        restored = restore_saved_offline_session(
            credential, binding_path, binding, threading.Event(),
        )
        if restored is None:
            raise RuntimeError("The native vault grant could not restore after GUI switches.")
        restored_session, restored_identity = restored
        if (restored_identity.user_id != FIXTURE_ADMIN_ID
                or restored_session.approved_scope() != mode_record.scope):
            raise RuntimeError("The restored identity or approved scope changed.")
        verified_target: LinkedUploadTarget = verify_linked_upload_target(
            restored_session, issuer, restored_identity.user_id,
            restored_session.approved_scope(), FIXTURE_GALLERY_ID,
        )
        if (verified_target.gallery_id != mode_record.gallery_id
                or verified_target.user_id != mode_record.account_id
                or verified_target.issuer != mode_record.issuer):
            raise RuntimeError("The installed target did not survive exact re-verification.")
        restored_session.close()
        restored_session = None
        _cleanup_exact_vault(
            credential, mode_store, issuer, FIXTURE_ADMIN_ID, mode_record,
        )
        mode_record = None
        credential_slot_owned = False
        return {
            "initial_backend": "tkinter",
            "switches": "tkinter-to-pyside6-to-tkinter",
            "target_identity": "verified-fixture-account-and-gallery",
            "approved_scope": "rolling-upload-scope-preserved",
            "native_vault": "real-windows-refresh-and-mode-record-read-back",
            "ordinary_startup": (
                "Tk-wait-marker-and-Photos-browse-restore; "
                "Qt-no-refresh-before-action"
            ),
            "native_user_actions": "Tk-exact-gallery-selection-and-Qt-saved-reconnect",
            "native_picker_captures": ";".join(picker_capture_paths) or "unavailable",
            "installed_restore": (
                "Tk-browse-session-reused; "
                "Qt-explicit-refresh-and-identity-read"
            ),
            "target_reverification": "same-issuer-account-scope-and-gallery",
            "separate_unreviewed_history": (
                "prior-target-ledger-byte-identical-after-tk-qt-tk-startups"
            ),
            "credential_cleanup": "exact-owned-record-removed",
        }
    finally:
        if restored_session is not None:
            restored_session.close()
        try:
            if gui_pid is not None:
                _close_owned_gui(gui_pid)
            if owned_launcher is not None and owned_launcher.poll() is None:
                owned_launcher.terminate()
                try:
                    owned_launcher.wait(timeout=INSTALLED_SMOKE_SECONDS)
                except subprocess.TimeoutExpired:
                    owned_launcher.kill()
                    owned_launcher.wait(timeout=INSTALLED_SMOKE_SECONDS)
            if credential_slot_owned or mode_record is not None:
                cleanup_mode = mode_record
                if cleanup_mode is None and config_path is not None:
                    cleanup_mode = _discover_owned_mode_record(
                        mode_store, config_path, issuer,
                    )
                _cleanup_exact_vault(
                    credential, mode_store, issuer,
                    FIXTURE_ADMIN_ID, cleanup_mode,
                )
        finally:
            authority.close()
