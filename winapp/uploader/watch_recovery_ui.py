# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/uploader/watch_recovery_ui.py
# Module Type: Watcher Recovery UI
# Purpose: Present explicit, head-bound recovery choices for pending uploads.
# Responsibilities:
#   - Show exact local queue identity beside qualified remote evidence.
#   - Keep preview and decision work off Tk while creating images on Tk.
#   - Coalesce decisions and preserve pending work when the dialog closes.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)
"""Render a safe review dialog for an uncertain Watcher queue head."""

import hashlib
import io
import os
import queue
import re
import threading
import time
import tkinter as tk
from contextlib import nullcontext
from pathlib import Path
from tkinter import ttk
from typing import TYPE_CHECKING, Callable

from uploader.watch_recovery import WatchRecoveryCoordinator, WatchRecoverySnapshot
from uploader.watch_queue import observe_watch_source
from uploader.flight_report_translations import recovery_text
from uploader.ui_theme import GlassCard, place_dialog

if TYPE_CHECKING:
    from PIL import Image as PILImage

try:
    from PIL import Image as PillowImage
    from PIL import ImageTk
except ImportError:
    PillowImage = None
    ImageTk = None


# Purpose: Keep each evidence panel compact on narrow Watcher windows.
# Type: tuple[int, int]. Units: pixels. Scope: recovery evidence previews.
# Consumers: WatchRecoveryReviewDialog. Rationale: 160x120 fits compact side-by-side cards.
PREVIEW_SIZE = (160, 120)
# Purpose: Bound image bytes read for a background preview.
# Type: int. Units: bytes. Scope: recovery preview source.
# Consumers: WatchRecoveryReviewDialog.load_previews. Rationale: Avoid large-file memory spikes.
PREVIEW_MAX_BYTES = 40 * 1024 * 1024
# Purpose: Poll background outcomes without cross-thread calls into Tk.
# Type: int. Units: milliseconds. Scope: recovery dialog message pump.
# Consumers: WatchRecoveryReviewDialog. Rationale: 50 ms keeps controls responsive with low idle cost.
PREVIEW_POLL_MS = 50
# Purpose: Keep the evidence window usable on small displays.
# Type: tuple[int, int]. Units: pixels. Scope: recovery dialog geometry.
# Consumers: WatchRecoveryReviewDialog. Rationale: 360x360 permits two evidence columns and actions.
DIALOG_MIN_SIZE = (360, 360)
# Purpose: Keep evidence cards separated from dialog edges.
# Type: int. Units: pixels. Scope: recovery dialog inner padding.
# Consumers: WatchRecoveryReviewDialog. Rationale: 14 px matches compact flight panels.
DIALOG_PADDING = 14
# Purpose: Bound evidence labels to their compact panel columns.
# Type: int. Units: pixels. Scope: recovery dialog text wrapping.
# Consumers: WatchRecoveryReviewDialog. Rationale: 220 px fits a two-column desktop layout.
EVIDENCE_WRAP_PX = 220
# Purpose: Bound combined status text without widening the recovery panel.
# Type: int. Units: pixels. Scope: recovery dialog status wrapping.
# Consumers: WatchRecoveryReviewDialog. Rationale: 440 px aligns with two evidence columns.
STATUS_WRAP_PX = 440
# Purpose: Keep full file and receipt identity visible without a tall log.
# Type: int. Units: text lines. Scope: recovery metadata field.
# Consumers: WatchRecoveryReviewDialog. Rationale: Seven lines fit the identity summary.
DETAIL_TEXT_LINES = 7
# Purpose: Switch to one action column before labels become crowded.
# Type: int. Units: pixels. Scope: responsive recovery actions.
# Consumers: WatchRecoveryReviewDialog.layout_buttons. Rationale: One column preserves labels below 560 px.
NARROW_LAYOUT_BREAKPOINT_PX = 560
# Purpose: Bound untrusted filenames and target labels in the UI.
# Type: int. Units: characters. Scope: queue metadata labels.
# Consumers: sanitize_recovery_label. Rationale: 160 characters preserve context without runaway layout.
DISPLAY_TEXT_MAX_CHARS = 160


def sanitize_recovery_label(value: str) -> str:
    """Remove control characters and bound untrusted queue metadata.

    Args:
        value (str): Filename, target or reason copied from queue/service data.
    Returns:
        str: Single-line printable label bounded to the UI limit.
    """
    sanitized = re.sub(r"[\x00-\x1f\x7f]", " ", value).strip()
    if len(sanitized) > DISPLAY_TEXT_MAX_CHARS:
        return sanitized[:DISPLAY_TEXT_MAX_CHARS - 1] + "…"
    return sanitized


class WatchRecoveryReviewDialog:
    """Show an immutable head snapshot and explicit durable receipt/skip actions."""

    def __init__(
        self,
        parent: tk.Misc,
        coordinator: WatchRecoveryCoordinator,
        language: str,
        target_label: str,
        on_resolved: Callable[[], None] | None = None,
        remote_loader: Callable[
            [WatchRecoverySnapshot], bytes | tuple[bytes, str] | None
        ] | None = None,
        initial_snapshot: WatchRecoverySnapshot | None = None,
    ) -> None:
        """Create a compact responsive review dialog.

        Args:
            parent (tk.Misc): Existing application window.
            coordinator (WatchRecoveryCoordinator): Head-validating decision owner.
            language (str): Current supported locale code.
            target_label (str): Sanitized verified target description.
            on_resolved (Callable[[], None] | None): Optional continuation callback.
            remote_loader (Callable[[WatchRecoverySnapshot], bytes | tuple[bytes, str] | None] | None):
                Optional authorized loader; None means unavailable, bytes alone mean
                observation, and a tuple carries body plus receipt/observation kind.
            initial_snapshot (WatchRecoverySnapshot | None): Optional snapshot
                already prepared by a worker; omitted snapshots load in background.
        Returns:
            None: Opens a non-mutating review window.
        """
        self.coordinator = coordinator
        self.language = language
        self._target_label = sanitize_recovery_label(target_label)
        self.snapshot = initial_snapshot
        self._snapshot_pending = initial_snapshot is None
        self.on_resolved = on_resolved
        self.remote_loader = remote_loader
        self.busy = False
        self._decision_thread: threading.Thread | None = None
        self._hidden_for_decision = False
        self._messages: queue.Queue[tuple[str, object]] = queue.Queue()
        self._closed = False
        self._layout_pending = False
        self._receipt_available = False
        self._preview_generation = 0
        self._preview_workers: list[threading.Thread] = []
        self.window = tk.Toplevel(parent)
        self.window.title(self.text("watch_recovery_title"))
        self.window.transient(parent.winfo_toplevel())
        self.window.protocol("WM_DELETE_WINDOW", self.close)
        self.window.minsize(*DIALOG_MIN_SIZE)
        self.body = GlassCard(self.window, padding=DIALOG_PADDING)
        self.body.pack(fill="both", expand=True)
        self.body.columnconfigure((0, 1), weight=1, uniform="preview")
        self.body.rowconfigure(1, weight=1)
        ttk.Label(self.body, text=self.text("watch_recovery_title"),
                  style="Title.TLabel").grid(row=0, column=0, columnspan=2,
                                              sticky="w", pady=(0, 8))
        self.viewport = ttk.Frame(self.body)
        self.viewport.grid(row=1, column=0, columnspan=2, sticky="nsew")
        self.viewport.columnconfigure(0, weight=1)
        self.viewport.rowconfigure(0, weight=1)
        self.canvas = tk.Canvas(
            self.viewport, highlightthickness=0, borderwidth=0,
            background=ttk.Style(self.window).lookup("TFrame", "background"),
        )
        self.canvas.grid(row=0, column=0, sticky="nsew")
        evidence_scroll = ttk.Scrollbar(
            self.viewport, orient="vertical", command=self.canvas.yview)
        evidence_scroll.grid(row=0, column=1, sticky="ns")
        self.canvas.configure(yscrollcommand=evidence_scroll.set)
        self.evidence = ttk.Frame(self.canvas)
        self.evidence.columnconfigure((0, 1), weight=1, uniform="evidence")
        # The canvas content begins at its upper-left and follows the viewport width.
        self._evidence_window = self.canvas.create_window(
            (0, 0), window=self.evidence, anchor="nw")
        self.evidence.bind(
            "<Configure>",
            lambda _event: self.canvas.configure(
                scrollregion=self.canvas.bbox("all")),
            add="+",
        )
        self.canvas.bind(
            "<Configure>",
            lambda event: self.canvas.itemconfigure(
                self._evidence_window, width=max(1, event.width)),
            add="+",
        )
        self.window.bind(
            "<MouseWheel>",
            lambda event: self.scroll_evidence(event),
            add="+",
        )
        self.remote_var = tk.StringVar(value=self.text("watch_recovery_observation"))
        self.local_var = tk.StringVar()
        self.remote_label = ttk.Label(
            self.evidence, textvariable=self.remote_var, wraplength=EVIDENCE_WRAP_PX,
            justify="left")
        self.remote_label.grid(row=0, column=0, sticky="nsew", padx=(0, 8))
        self.local_label = ttk.Label(
            self.evidence, textvariable=self.local_var, wraplength=EVIDENCE_WRAP_PX,
            justify="left")
        self.local_label.grid(row=0, column=1, sticky="nsew", padx=(8, 0))
        self.remote_image = ttk.Label(self.evidence, anchor="center")
        self.remote_image.grid(row=1, column=0, sticky="nsew", padx=(0, 8))
        self.local_image = ttk.Label(self.evidence, anchor="center")
        self.local_image.grid(row=1, column=1, sticky="nsew", padx=(8, 0))
        theme = ttk.Style(self.window)
        details_frame = ttk.Frame(self.evidence)
        details_frame.grid(row=2, column=0, columnspan=2, sticky="ew", pady=10)
        details_frame.columnconfigure(0, weight=1)
        self.details = tk.Text(
            details_frame, height=DETAIL_TEXT_LINES, wrap="word", state="disabled",
            background=theme.lookup("TFrame", "background"),
            foreground=theme.lookup("TLabel", "foreground"),
            insertbackground=theme.lookup("TLabel", "foreground"),
            relief="flat", borderwidth=0,
        )
        self.details.pack(side="left", fill="both", expand=True)
        details_scroll = ttk.Scrollbar(
            details_frame, orient="vertical", command=self.details.yview)
        details_scroll.pack(side="right", fill="y")
        self.details.configure(yscrollcommand=details_scroll.set)
        self.status = tk.StringVar(value="")
        self.status_label = ttk.Label(
            self.evidence, textvariable=self.status, wraplength=STATUS_WRAP_PX,
            justify="left")
        self.status_label.grid(row=3, column=0, columnspan=2, sticky="ew")
        buttons = ttk.Frame(self.body)
        self.buttons = buttons
        buttons.grid(row=2, column=0, columnspan=2, sticky="ew", pady=(12, 0))
        buttons.columnconfigure((0, 1, 2, 3, 4), weight=1)
        self.confirm_button = ttk.Button(
            buttons, text=self.text("watch_recovery_confirm"), command=self.confirm)
        self.confirm_button.grid(row=0, column=0, sticky="ew", padx=(0, 4))
        self.skip_button = ttk.Button(
            buttons, text=self.text("watch_recovery_skip"), command=self.skip)
        self.skip_button.grid(row=0, column=1, sticky="ew", padx=4)
        self.prior_button = ttk.Button(
            buttons, text=self.text("watch_recovery_review_earlier"),
            command=self.review_earlier)
        self.prior_button.grid(row=0, column=2, sticky="ew", padx=4)
        self.resume_button = ttk.Button(
            buttons, text=self.text("watch_recovery_resume_pending"),
            command=self.resume_retained)
        self.resume_button.grid(row=0, column=3, sticky="ew", padx=4)
        self.cancel_button = ttk.Button(
            buttons, text=self.text("watch_recovery_cancel"), command=self.close)
        self.cancel_button.grid(row=0, column=4, sticky="ew", padx=(4, 0))
        self.action_buttons = (self.confirm_button, self.skip_button,
                               self.prior_button, self.resume_button,
                               self.cancel_button)
        self.visible_action_buttons = list(self.action_buttons)
        self.window.bind("<Configure>", self.layout_buttons)
        self.populate()
        self.window.after(PREVIEW_POLL_MS, self.poll_worker_results)
        place_dialog(self.window, parent)
        if self._snapshot_pending:
            snapshot_thread = threading.Thread(
                target=self.load_snapshot, name="watch-recovery-snapshot", daemon=True)
            try:
                snapshot_thread.start()
            except RuntimeError:
                self._messages.put(("snapshot_error", None))

    def text(self, key: str) -> str:
        """Return a translated recovery label.

        Args:
            key (str): Stable recovery translation key.
        Returns:
            str: Locale text with an English fallback.
        """
        return recovery_text(self.language, key)

    def populate(self) -> None:
        """Render sanitized metadata from the captured immutable snapshot.

        Returns:
            None: Updates dialog labels without touching queue persistence.
        """
        self._preview_generation += 1
        self.canvas.yview_moveto(0.0)
        snapshot = self.snapshot
        if snapshot is None:
            self.status.set(self.text("watch_recovery_loading"
                                      if self._snapshot_pending
                                      else "watch_recovery_clear"))
            self.confirm_button.state(["disabled"])
            self.skip_button.state(["disabled"])
            self.set_visible_actions([self.cancel_button])
            return
        name = sanitize_recovery_label(Path(snapshot.source_path).name)
        self.local_var.set(self.text("watch_recovery_local").format(
            sequence=snapshot.sequence, name=name, count=snapshot.pending_count,
            target=self.target_label()))
        self.status.set(sanitize_recovery_label(snapshot.error)
                        if snapshot.error else self.text("watch_recovery_pending"))
        prior_blocker = getattr(snapshot, "review_blocked_by_earlier_upload", False)
        safe_resume = self.safe_resume_available(snapshot)
        retained_resume = safe_resume and getattr(snapshot, "retained_stop", False)
        if prior_blocker or "Earlier linked upload has an unknown result" in snapshot.error:
            marker = getattr(snapshot, "prior_blocker_marker", "")
            prior_path = getattr(snapshot, "prior_blocker_path", "")
            prior_hash = getattr(snapshot, "prior_blocker_sha256", "")
            prior_target = getattr(snapshot, "prior_blocker_target_binding", "")
            prior_details = self.text("watch_recovery_prior_identity").format(
                marker=marker or self.text("watch_recovery_none"),
                name=(sanitize_recovery_label(Path(prior_path).name) if prior_path
                      else self.text("watch_recovery_none")),
                digest=prior_hash[:16] if prior_hash else self.text("watch_recovery_none"),
                target=sanitize_recovery_label(prior_target)
                if prior_target else self.text("watch_recovery_none"))
            self.status.set(self.text("watch_recovery_pre_dispatch_block")
                            + "\n" + prior_details)
            visible_actions = [self.prior_button, self.cancel_button]
        elif retained_resume:
            retained_status = self.text("watch_recovery_retained_stop")
            if snapshot.error:
                retained_status += "\n" + sanitize_recovery_label(snapshot.error)
            self.status.set(retained_status)
            visible_actions = [self.resume_button, self.cancel_button]
        elif safe_resume:
            pending_status = self.text("watch_recovery_resume_available")
            if snapshot.error:
                pending_status += "\n" + sanitize_recovery_label(snapshot.error)
            self.status.set(pending_status)
            visible_actions = [self.resume_button, self.cancel_button]
        else:
            visible_actions = [self.cancel_button]
            if snapshot.state == "uncertain":
                visible_actions.insert(0, self.confirm_button)
            if (snapshot.state in {"uncertain", "blocked", "auth_required"}
                    or snapshot.state == "retry_wait"
                    and getattr(snapshot, "phase", "") == "source"):
                visible_actions.insert(-1, self.skip_button)
        self.write_details(snapshot)
        self.load_previews(snapshot)
        self.confirm_button.state(["disabled"])
        if snapshot.state != "uncertain" or prior_blocker:
            if not prior_blocker and not safe_resume:
                self.status.set(self.text("watch_recovery_receipt_unavailable"))
            self.confirm_button.state(["disabled"])
        can_skip = (
            snapshot.state in {"uncertain", "blocked", "auth_required"}
            or snapshot.state == "retry_wait"
            and getattr(snapshot, "phase", "") == "source"
        ) and not prior_blocker
        if not can_skip:
            self.skip_button.state(["disabled"])
        else:
            self.skip_button.state(["!disabled"])
        self.set_visible_actions(visible_actions)

    def target_label(self) -> str:
        """Return the caller-supplied verified target label.

        Returns:
            str: Sanitized target gallery description.
        """
        return getattr(self, "_target_label", "")

    def safe_resume_available(self, snapshot: WatchRecoverySnapshot) -> bool:
        """Identify safe accepted queue states that can explicitly resume.

        Args:
            snapshot (WatchRecoverySnapshot): Current immutable queue head.
        Returns:
            bool: True for safe pending phases without prior/source blockers.
        """
        return bool(
            snapshot.state in {
                "observed", "stabilizing", "preparing", "inventory", "retry_wait"
            }
            and getattr(snapshot, "phase", "") != "source"
            and not getattr(snapshot, "review_blocked_by_earlier_upload", False)
        )

    def layout_buttons(self, _event: tk.Event) -> None:
        """Reflow actions into one or two columns at the current window width.

        Args:
            _event (tk.Event): Configure event from the review window.
        Returns:
            None: Keeps all explicit actions visible at narrow widths.
        """
        if not hasattr(self, "action_buttons"):
            return
        self.reflow_buttons()
        if not self._layout_pending:
            self._layout_pending = True
            self.window.after_idle(self.update_responsive_layout)

    def update_responsive_layout(self) -> None:
        """Set wrapping from the actual evidence and status label widths.

        Returns:
            None: Prevents text clipping after the dialog changes width.
        """
        self._layout_pending = False
        if self._closed or not self.window.winfo_exists():
            return
        for label in (self.remote_label, self.local_label, self.status_label):
            width = max(1, label.winfo_width() - 4)
            if int(label.cget("wraplength")) != width:
                label.configure(wraplength=width)

    def reflow_buttons(self) -> None:
        """Place the current action set using the window's responsive width.

        Returns:
            None: Updates row/column placement for visible controls only.
        """
        columns = (1 if self.window.winfo_width() < NARROW_LAYOUT_BREAKPOINT_PX
                   else 2)
        for index, button in enumerate(self.visible_action_buttons):
            button.grid_configure(row=index // columns, column=index % columns,
                                  sticky="ew", padx=4, pady=2)
        for column in range(5):
            self.buttons.columnconfigure(column, weight=1 if column < columns else 0)

    def scroll_evidence(self, event: tk.Event) -> str | None:
        """Scroll evidence descendants without stealing native widget gestures.

        Args:
            event (tk.Event): Native mouse-wheel event from the review dialog.
        Returns:
            str | None: Stops after evidence scrolling, or leaves native/footer
                gestures to their owning controls.
        """
        if isinstance(event.widget, (tk.Text, tk.Listbox, ttk.Treeview, ttk.Combobox)):
            return None
        widget = event.widget
        while widget is not None and widget is not self.evidence:
            widget = getattr(widget, "master", None)
        if widget is not self.evidence or not event.delta:
            return None
        self.canvas.yview_scroll(-1 if event.delta > 0 else 1, "units")
        return "break"

    def set_visible_actions(self, actions: list[ttk.Button]) -> None:
        """Keep unavailable decisions hidden while preserving visible Cancel.

        Args:
            actions (list[ttk.Button]): Explicit actions valid for this snapshot.
        Returns:
            None: Reflows only the currently available controls.
        """
        self.visible_action_buttons = actions
        for button in self.action_buttons:
            if button in actions:
                button.grid()
            else:
                button.grid_remove()
        self.reflow_buttons()
        if not self._layout_pending:
            self._layout_pending = True
            self.window.after_idle(self.update_responsive_layout)

    def write_details(self, snapshot: WatchRecoverySnapshot) -> None:
        """Show local file metadata and distinguish receipt from observation.

        Args:
            snapshot (WatchRecoverySnapshot): Captured immutable queue head.
        Returns:
            None: Displays safe stat/hash metadata or a precise read failure.
        """
        value = self.text("watch_recovery_file_details").format(
            modified=time.strftime("%Y-%m-%d %H:%M:%S",
                                   time.localtime(snapshot.captured_at)),
            size=snapshot.source_size,
            digest=getattr(snapshot, "sha256", "")[:16],
                binding=sanitize_recovery_label(snapshot.target_binding),
            confirmed=(str(getattr(snapshot, "last_confirmed_sequence", ""))
                       or snapshot.last_confirmed
                       or self.text("watch_recovery_none")),
            progress=snapshot.progress or self.text("watch_recovery_none"),
            progress_at=(time.strftime("%Y-%m-%d %H:%M:%S",
                                       time.localtime(snapshot.progress_at))
                         if snapshot.progress_at else self.text("watch_recovery_none")),
            confirmed_at=(time.strftime("%Y-%m-%d %H:%M:%S",
                                        time.localtime(getattr(
                                            snapshot, "last_confirmed_at", 0.0)))
                          if getattr(snapshot, "last_confirmed_at", 0.0)
                          else self.text("watch_recovery_none")),
            phase=getattr(snapshot, "phase", snapshot.state),
            http_status=getattr(snapshot, "http_status", ""))
        self.details.configure(state="normal")
        self.details.delete("1.0", "end")
        self.details.insert("1.0", value)
        self.details.configure(state="disabled")

    def load_previews(self, snapshot: WatchRecoverySnapshot) -> None:
        """Read local and optional remote image bytes off the Tk thread.

        Args:
            snapshot (WatchRecoverySnapshot): Exact captured head identity.
        Returns:
            None: Delivers byte results back to Tk for optional decoding.
        """
        generation = self._preview_generation
        snapshot_key = self.snapshot_key(snapshot)

        def worker() -> None:
            """Load and validate source evidence without calling Tk APIs.

            Returns:
                None: Queues exact-generation images and categorized failures.
            """
            local = None
            remote = None
            receipt_available = False
            remote_state = "unavailable"
            remote_kind = "observation"
            local_state = "replaced"
            local_digest = ""
            try:
                path = Path(snapshot.source_path)
                info = path.lstat()
                observed = observe_watch_source(path, snapshot.captured_at)
                if (observed.signature != snapshot.source_signature
                        or path.is_symlink()
                        or info.st_size > PREVIEW_MAX_BYTES
                        or info.st_size != getattr(snapshot, "source_size", None)):
                    raise OSError("Retained source generation changed.")
                expected_mtime = getattr(snapshot, "source_mtime_ns", 0)
                if expected_mtime > 0 and info.st_mtime_ns != expected_mtime:
                    raise OSError("Retained source generation changed.")
                with path.open("rb") as source_file:
                    opened = os.fstat(source_file.fileno())
                    if (opened.st_dev != info.st_dev or opened.st_ino != info.st_ino
                            or opened.st_size != info.st_size
                            or opened.st_mtime_ns != info.st_mtime_ns):
                        raise OSError("Retained source generation changed.")
                    content = source_file.read(PREVIEW_MAX_BYTES + 1)
                    after_handle = os.fstat(source_file.fileno())
                after = path.lstat()
                if (len(content) > PREVIEW_MAX_BYTES or path.is_symlink()
                        or after.st_dev != opened.st_dev or after.st_ino != opened.st_ino
                        or after_handle.st_size != opened.st_size
                        or after_handle.st_mtime_ns != opened.st_mtime_ns):
                    raise OSError("Retained source changed during preview read.")
                digest = hashlib.sha256(content).hexdigest()
                if snapshot.sha256 and digest != snapshot.sha256:
                    raise OSError("Retained source hash changed.")
                local_digest = digest
                local = self.decode_preview(content)
                local_state = "loaded"
            except FileNotFoundError:
                local_state = "missing"
            except PermissionError:
                local_state = "access"
            except ValueError:
                local_state = "decode"
            except OSError:
                local_state = "replaced"
            except Exception:
                local_state = "decode"
            receipt_probe = getattr(self.coordinator, "can_confirm_receipt", None)
            if callable(receipt_probe) and snapshot.state == "uncertain":
                try:
                    gate = getattr(self.coordinator, "decision_gate", None)
                    with (gate if gate is not None else nullcontext()):
                        receipt_available = bool(receipt_probe(snapshot))
                except Exception:
                    receipt_available = False
            if self.remote_loader is not None:
                try:
                    remote_result = self.remote_loader(snapshot)
                    if remote_result is None:
                        remote_state = "unavailable"
                    elif isinstance(remote_result, tuple):
                        remote_bytes, remote_kind = remote_result
                        remote = self.decode_preview(remote_bytes)
                        remote_state = "loaded"
                    else:
                        remote_bytes = remote_result
                        remote = self.decode_preview(remote_bytes)
                        remote_state = "loaded"
                except PermissionError:
                    remote_state = "auth"
                except FileNotFoundError:
                    remote_state = "missing"
                except (TimeoutError, ConnectionError, OSError):
                    remote_state = "transport"
                except Exception:
                    remote_state = "decode"
                    remote = None
            self._messages.put((
                "preview", (generation, snapshot_key, local, remote,
                            receipt_available, remote_state, remote_kind,
                            local_state, local_digest)))

        thread = threading.Thread(
            target=worker, name="watch-recovery-preview", daemon=True)
        self._preview_workers.append(thread)
        try:
            thread.start()
        except RuntimeError:
            self._messages.put(("preview_failed", (generation, snapshot_key)))

    def snapshot_key(self, snapshot: WatchRecoverySnapshot) -> tuple[str, ...]:
        """Return immutable generation identity for stale-result rejection.

        Args:
            snapshot (WatchRecoverySnapshot): Captured queue head.
        Returns:
            tuple[str, ...]: Stable fields that distinguish target and generation.
        """
        return tuple(str(getattr(snapshot, field, "")) for field in (
            "item_id", "sequence", "source_path", "source_signature", "sha256",
            "target_binding", "session_id", "state",
        ))

    def load_snapshot(self) -> None:
        """Fetch durable queue context off Tk, including linked history reads.

        Returns:
            None: Queues the immutable initial snapshot or a safe load failure.
        """
        try:
            snapshot = self.coordinator.snapshot()
            self._messages.put(("snapshot", snapshot))
        except Exception:
            self._messages.put(("snapshot_error", None))

    def decode_preview(self, content: bytes) -> "PILImage.Image":
        """Decode and resize image content off the Tk thread.

        Args:
            content (bytes): Source image bytes from an authorized loader.
        Returns:
            PILImage.Image: Bounded decoded image ready for Tk conversion.
        Raises:
            ValueError: The image cannot be safely decoded.
        """
        if len(content) > PREVIEW_MAX_BYTES:
            raise ValueError("Preview exceeds the local size limit.")
        if PillowImage is None:
            raise ValueError("Image preview support is unavailable.")
        with PillowImage.open(io.BytesIO(content)) as source:
            source.thumbnail(PREVIEW_SIZE)
            source.load()
            return source.convert("RGB").copy()

    def preview_result(
        self, local: "PILImage.Image | None", remote: "PILImage.Image | None",
        receipt_available: bool, remote_state: str, remote_kind: str,
        local_state: str,
        local_digest: str,
    ) -> None:
        """Create Tk image objects from worker-decoded Pillow images.

        Args:
            local (PILImage.Image | None): Decoded exact retained source image.
            remote (PILImage.Image | None): Decoded authorized remote image.
            receipt_available (bool): Exact receipt availability from background probe.
            remote_state (str): Safe categorized remote preview outcome.
            remote_kind (str): Remote evidence class; exact receipt or observation.
            local_state (str): Safe exact-source read/decode result category.
            local_digest (str): Computed local source digest, never an upload receipt.
        Returns:
            None: Updates previews or precise unavailable labels.
        """
        if not self.window.winfo_exists():
            return
        self._receipt_available = receipt_available
        self.show_image(self.local_image, local)
        self.show_image(self.remote_image, remote)
        self.local_var.set(self.local_var.get() + "\n" + self.text(
            "watch_recovery_local_" + local_state))
        if local_digest:
            self.details.configure(state="normal")
            self.details.insert("end", "\n" + self.text("watch_recovery_computed_hash")
                                .format(digest=local_digest))
            self.details.configure(state="disabled")
        if remote is None:
            self.remote_var.set(self.text("watch_recovery_remote_" + remote_state))
        else:
            evidence_key = ("watch_recovery_remote_receipt"
                            if remote_kind == "receipt"
                            else "watch_recovery_remote_observation")
            self.remote_var.set(self.text(evidence_key))
        if receipt_available:
            self.confirm_button.state(["!disabled"])
        elif (self.snapshot is not None
              and not getattr(self.snapshot, "review_blocked_by_earlier_upload", False)
              and not self.safe_resume_available(self.snapshot)):
            self.status.set(self.text("watch_recovery_receipt_unavailable"))

    def show_image(self, owner: ttk.Label, content: "PILImage.Image | None") -> None:
        """Create a Tk image from a worker-decoded Pillow image and retain it.

        Args:
            owner (ttk.Label): Evidence panel image owner.
            content (PILImage.Image | None): Decoded image from the background worker.
        Returns:
            None: Shows a bounded image or leaves the panel empty on decode failure.
        """
        if not content:
            owner.configure(image="")
            return
        try:
            if ImageTk is None:
                raise RuntimeError("Image preview support is unavailable.")
            image = ImageTk.PhotoImage(content, master=self.window)
            owner.configure(image=image)
            owner.image = image
        except Exception:
            owner.configure(image="")

    def decide(self, action: str) -> None:
        """Apply one coalesced, head-bound durable decision.

        Args:
            action (str): One of exact receipt confirmation, explicit skip,
                or review of an earlier blocking upload.
        Returns:
            None: Shows success or rejection while preserving pending work.
        """
        if self.busy or self.snapshot is None:
            return
        self.busy = True
        self.confirm_button.state(["disabled"])
        self.skip_button.state(["disabled"])
        self.cancel_button.configure(text=self.text("watch_recovery_hide_processing"))
        self.status.set(self.text("watch_recovery_decision_processing"))
        displayed = self.snapshot

        displayed_key = self.snapshot_key(displayed)

        def worker() -> None:
            """Run the selected head-validated operation outside the Tk thread.

            Returns:
                None: Queues the durable decision result for the Tk poller.
            """
            result: WatchRecoverySnapshot | None = None
            error = ""
            try:
                gate = getattr(self.coordinator, "decision_gate", None)
                with (gate if gate is not None else nullcontext()):
                    if action == "receipt":
                        result = self.coordinator.confirm_receipt(displayed)
                    elif action == "skip":
                        result = self.coordinator.skip_head(displayed)
                    elif action == "resume":
                        result = self.coordinator.resume_retained(displayed)
                    else:
                        result = self.coordinator.review_earlier_blocker(displayed)
            except (ValueError, OSError, RuntimeError):
                error = "rejected"
            except Exception:
                error = "unexpected"
            self._messages.put(("decision", (displayed_key, result, error)))

        self._decision_thread = threading.Thread(
            target=worker, name="watch-recovery-decision", daemon=True)
        try:
            self._decision_thread.start()
        except RuntimeError:
            self.busy = False
            self.decision_result(displayed_key, None, "unexpected")

    def decision_result(
        self, snapshot_key: tuple[str, ...],
        snapshot: WatchRecoverySnapshot | None, error: str,
    ) -> None:
        """Apply the background decision result on Tk and refresh the view.

        Args:
            snapshot_key (tuple[str, ...]): Identity of the action's captured head.
            snapshot (WatchRecoverySnapshot | None): Durable reread after decision.
            error (str): Failure text, or empty on success.
        Returns:
            None: Updates visible state without blocking Tk.
        """
        if not self.window.winfo_exists():
            return
        self.busy = False
        if self.snapshot is not None and snapshot_key != self.snapshot_key(self.snapshot):
            if self._hidden_for_decision:
                self._closed = True
                self.window.destroy()
            return
        if error:
            error_key = ("watch_recovery_decision_unexpected" if error == "unexpected"
                         else "watch_recovery_decision_failed")
            self.status.set(self.text(error_key))
        else:
            self.snapshot = snapshot
            if self.on_resolved is not None:
                try:
                    self.on_resolved()
                except Exception:
                    self.status.set(self.text("watch_recovery_wake_failed"))
                    self.populate()
                    if self._hidden_for_decision:
                        self._closed = True
                        self.window.destroy()
                    return
            self.status.set(self.text("watch_recovery_decision_saved"))
            self.populate()
        if self.snapshot is not None and self.snapshot.state in {
            "uncertain", "blocked", "auth_required", "retry_wait"
        }:
            if getattr(self.snapshot, "review_blocked_by_earlier_upload", False):
                self.prior_button.state(["!disabled"])
            else:
                if self._receipt_available and self.snapshot.state == "uncertain":
                        self.confirm_button.state(["!disabled"])
                can_skip = (
                    self.snapshot.state in {"uncertain", "blocked", "auth_required"}
                    or self.snapshot.state == "retry_wait"
                    and getattr(self.snapshot, "phase", "") == "source"
                )
                if can_skip:
                    self.skip_button.state(["!disabled"])
            if (getattr(self.snapshot, "retained_stop", False)
                    and self.snapshot.state == "retry_wait"
                    and getattr(self.snapshot, "phase", "") != "source"):
                self.resume_button.state(["!disabled"])
        elif (self.snapshot is not None
              and self.safe_resume_available(self.snapshot)):
            self.resume_button.state(["!disabled"])
        self.cancel_button.configure(text=self.text("watch_recovery_cancel"))
        if self._hidden_for_decision:
            self._closed = True
            self.window.destroy()

    def poll_worker_results(self) -> None:
        """Apply queued worker messages from a Tk-owned bounded poll.

        Returns:
            None: Keeps cross-thread callbacks out of Tk and reschedules while open.
        """
        if self._closed or not self.window.winfo_exists():
            return
        while True:
            try:
                kind, payload = self._messages.get_nowait()
            except queue.Empty:
                break
            if kind == "preview":
                generation, snapshot_key, *result = payload
                if (generation == self._preview_generation and self.snapshot is not None
                        and snapshot_key == self.snapshot_key(self.snapshot)):
                    self.preview_result(*result)
            elif kind == "preview_failed":
                generation, snapshot_key = payload
                if (generation == self._preview_generation and self.snapshot is not None
                        and snapshot_key == self.snapshot_key(self.snapshot)):
                    self.local_var.set(self.text("watch_recovery_local_unavailable"))
            elif kind == "decision":
                self.decision_result(*payload)
            elif kind == "snapshot":
                self._snapshot_pending = False
                self.snapshot = payload
                self.populate()
            elif kind == "snapshot_error":
                self._snapshot_pending = False
                self.status.set(self.text("watch_recovery_snapshot_failed"))
        self.window.after(PREVIEW_POLL_MS, self.poll_worker_results)

    def confirm(self) -> None:
        """Confirm an exact durable upload receipt, never an observation.

        Returns:
            None: Requests receipt-only reconciliation.
        """
        self.decide("receipt")

    def skip(self) -> None:
        """Record an explicit skip while preserving the original local source.

        Returns:
            None: Requests a durable manual skip for this exact queue head.
        """
        self.decide("skip")

    def review_earlier(self) -> None:
        """Review an earlier retained blocker while leaving this unsent item intact.

        Returns:
            None: Requests the coordinator's distinct prior-blocker workflow.
        """
        self.decide("prior")

    def resume_retained(self) -> None:
        """Explicitly resume a safe queue after a retained Stop decision.

        Returns:
            None: Clears the durable retained-stop marker through the model.
        """
        self.decide("resume")

    def close(self) -> None:
        """Close review without cancelling transport or changing queue state.

        Returns:
            None: Destroys only the review window.
        """
        if self.busy:
            self._hidden_for_decision = True
            self.window.withdraw()
            return
        self._closed = True
        self.window.destroy()
