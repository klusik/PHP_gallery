# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/uploader/gallery_load_progress.py
# Module Type: Windows Client UI
# Purpose: Show bounded, translated progress while a gallery viewport loads.
# Responsibilities:
#   - Reuse one compact overlay and its themed progress controls.
#   - Track only confirmed metadata and resolved visible thumbnail progress.
#   - Own and cancel its single elapsed and retry countdown callback.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)
"""Compact progress overlay for the native gallery viewport."""

import math
import time
import tkinter as tk
from tkinter import ttk
from typing import Callable


# Purpose: Delay secondary elapsed details until a load is visibly prolonged.
# Type: int. Units: seconds. Scope: one gallery load overlay.
# Consumers: GalleryLoadProgress._tick.
# Rationale: Ten seconds avoids secondary detail during ordinary short reads.
_DETAILS_AFTER_SECONDS = 10

# Purpose: Keep elapsed and retry countdown text fresh without frequent UI work.
# Type: int. Units: milliseconds. Scope: one visible gallery load overlay.
# Consumers: GalleryLoadProgress._schedule_tick.
# Rationale: 1000 ms refreshes readable seconds without a busy polling loop.
_TICK_INTERVAL_MS = 1000

# Purpose: Animate the indeterminate bar while network work can block progress.
# Type: int. Units: milliseconds. Scope: one gallery load overlay.
# Consumers: GalleryLoadProgress._start_bar.
# Rationale: 50 ms keeps native animation perceptible during a blocked request.
_BAR_ANIMATION_INTERVAL_MS = 50

# Purpose: Fit the loading surface inside either renderer's existing viewport.
# Type: tuple[float, float]. Units: fraction of parent width.
# Scope: one gallery indicator before and after its first visible photograph.
# Consumers: GalleryLoadProgress._show and compact.
# Rationale: 0.72 leaves canvas edges visible; 0.45 moves progress aside for photos.
_OVERLAY_WIDTH_FRACTIONS = (0.72, 0.45)

# Purpose: Keep progress text and artwork clear of their containing edges.
# Type: tuple[int, int]. Units: Tk pixels. Scope: one progress surface.
# Consumers: GalleryLoadProgress.__init__ and _fit_text.
# Rationale: Twelve horizontal and nine vertical pixels match the compact viewer.
_OVERLAY_PADDING = (12, 9)

# Purpose: Leave visible canvas beside the compact progress surface.
# Type: int. Units: Tk pixels. Scope: one overlay's bottom and right placement.
# Consumers: GalleryLoadProgress._show and compact.
# Rationale: Ten pixels separates feedback from the canvas and scrollbar edges.
_OVERLAY_MARGIN = 10


class GalleryLoadProgress:
    """Own one reusable, compact gallery loading overlay.

    Args:
        parent (tk.Misc): Canvas that hosts the non-layout overlay.
        text (Callable[[str], str]): Translator for stable linking_media keys.
    """

    def __init__(self, parent: tk.Misc, text: Callable[[str], str]) -> None:
        """Create one loading overlay and its reusable themed widgets.

        Args:
            parent (tk.Misc): Canvas that hosts the non-layout overlay.
            text (Callable[[str], str]): Translator for stable linking_media keys.
        Returns:
            None: The controller owns the overlay until close or hide.
        """
        self._parent = parent
        self._text = text
        self._closed = False
        self._started_at = time.monotonic()
        self._last_progress_at = self._started_at
        self._tick_id: str | None = None
        self._wait_deadline: float | None = None
        self._wait_automatic = False
        self._bar_running = False
        self._completed = 0
        self._total = 0
        self._metadata_count = 0
        self._last_real_count = 0
        self._thumbnail_count = 0
        self._compact = False
        self.phase = 'connecting'
        self.active = True
        self.visible = False

        self.frame = ttk.Frame(
            parent, padding=_OVERLAY_PADDING, style='Card.TFrame',
        )
        self.label = ttk.Label(
            self.frame, text=text('linking_media_connecting'),
            style='TLabel',
        )
        self.label.pack(fill='x')
        self.bar = ttk.Progressbar(
            self.frame, mode='indeterminate', style='Horizontal.TProgressbar',
        )
        # Six pixels separate the phase from its bar; four pixels distinguish
        # secondary details. Both gaps are internal layout, not polling policy.
        self.bar.pack(fill='x', pady=(6, 0))
        self.details = ttk.Label(self.frame, text='', style='Muted.TLabel')
        self.details.pack(fill='x', pady=(4, 0))
        self.label.configure(justify='left')
        self.details.configure(justify='left')
        self.frame.bind('<Configure>', self._fit_text, add='+')
        self._show()

    def _fit_text(self, event: tk.Event) -> None:
        """Wrap translated labels to the actual overlay width after layout.

        Args:
            event (tk.Event): Configure event from the owned progress frame.
        Returns:
            None: Labels stay inside desktop and narrow viewport boundaries.
        """
        if self._closed:
            return
        # Two equal padding edges surround the text; one pixel is Tk's
        # smallest usable wrap width while an initially unmapped frame settles.
        wrap = max(1, event.width - 2 * _OVERLAY_PADDING[0])
        self.label.configure(wraplength=wrap)
        self.details.configure(wraplength=wrap)

    def _show(self) -> None:
        """Place the existing overlay over the canvas without changing layout.

        Returns:
            None: The same widgets become visible at the lower center.
        """
        if not self.active or self.visible:
            return
        if self._compact:
            self.frame.place(
                relx=1.0, rely=1.0, anchor='se',
                x=-_OVERLAY_MARGIN, y=-_OVERLAY_MARGIN,
                relwidth=_OVERLAY_WIDTH_FRACTIONS[1],
            )
        else:
            self.frame.place(
                relx=0.5, rely=1.0, anchor='s', y=-_OVERLAY_MARGIN,
                relwidth=_OVERLAY_WIDTH_FRACTIONS[0],
            )
        self.visible = True
        self._start_bar()
        self._schedule_tick()

    def _start_bar(self) -> None:
        """Start one independent indeterminate animation when needed.

        Returns:
            None: Repeated metadata updates reuse the current animation.
        """
        if not self._bar_running:
            self.bar.start(_BAR_ANIMATION_INTERVAL_MS)
            self._bar_running = True

    def _schedule_tick(self) -> None:
        """Own at most one bounded elapsed or countdown update.

        Returns:
            None: Hidden or inactive overlays own no pending callback.
        """
        if (self.active and self.visible and self.phase != 'error'
                and self._tick_id is None):
            try:
                self._tick_id = self._parent.after(
                    _TICK_INTERVAL_MS, self._tick,
                )
            except tk.TclError:
                self._tick_id = None

    def _tick(self) -> None:
        """Refresh long-load detail and validated retry countdown text.

        Returns:
            None: One replacement callback is scheduled while still visible.
        """
        self._tick_id = None
        if not self.active or not self.visible or self.phase == 'error':
            return
        now = time.monotonic()
        elapsed = max(0, int(now - self._started_at))
        if self.phase == 'waiting':
            if self._wait_deadline is None:
                key = (
                    'linking_media_retrying' if self._wait_automatic
                    else 'linking_media_throttled'
                )
                self.label.configure(text=self._text(key))
            else:
                remaining = max(0.0, self._wait_deadline - now)
                key = (
                    'linking_media_wait_ready'
                    if self._wait_automatic and remaining <= 0
                    else 'linking_media_wait_retry' if self._wait_automatic
                    else 'linking_media_wait_manual'
                )
                self.label.configure(
                    text=self._text(key).format(seconds=math.ceil(remaining)),
                )
        if elapsed >= _DETAILS_AFTER_SECONDS:
            detail_lines = [self._text('linking_media_elapsed').format(
                seconds=elapsed,
            )]
            if self._last_real_count:
                since_progress = max(
                    0, int(now - self._last_progress_at),
                )
                detail_lines.append(
                    self._text('linking_media_last_progress').format(
                        seconds=since_progress, count=self._last_real_count,
                    ),
                )
            self.details.configure(text='\n'.join(detail_lines))
        self._schedule_tick()

    def metadata(self, confirmed: int) -> None:
        """Display a cumulative count of validated metadata rows.

        Args:
            confirmed (int): Cumulative number of accepted photo descriptors.
        Returns:
            None: Metadata updates the label but never predicts a percentage.
        """
        if not self.active or self.phase in ('error', 'hidden'):
            return
        if self.phase == 'waiting' and confirmed <= self._metadata_count:
            return
        self.phase = 'metadata'
        if confirmed > self._metadata_count:
            self._last_progress_at = time.monotonic()
            self._last_real_count = confirmed
        # The first display page can follow a complete newest-first scan.
        # Its smaller page size must not erase that scan's confirmed count.
        self._metadata_count = max(self._metadata_count, confirmed, 0)
        self._completed = self._metadata_count
        self._show()
        key = ('linking_media_metadata_count' if self._metadata_count
               else 'linking_media_metadata')
        message = self._text(key)
        if self._metadata_count:
            message = message.format(count=f'{self._metadata_count:,}')
        self.label.configure(text=message)
        if str(self.bar['mode']) != 'indeterminate':
            self.bar.stop()
            self._bar_running = False
            self.bar.configure(mode='indeterminate')
        self._start_bar()

    def thumbnails(self, completed: int, total: int) -> None:
        """Show presented visible thumbnails against the current viewport target.

        Args:
            completed (int): Visible thumbnails actually presented in Tk.
            total (int): Frozen/current number of visible thumbnails in this viewport.
        Returns:
            None: A resolved viewport is left for the owner to hide permanently.
        """
        if not self.active or self.phase in ('error', 'hidden'):
            return
        was_waiting = self.phase == 'waiting'
        self.phase = 'thumbnails'
        old_thumbnail_count = self._thumbnail_count
        self._completed = max(0, completed)
        self._total = max(0, total)
        if was_waiting and self._completed <= old_thumbnail_count:
            self.phase = 'waiting'
            return
        if self._completed > old_thumbnail_count:
            self._last_progress_at = time.monotonic()
            self._last_real_count = self._completed
        self._thumbnail_count = self._completed
        self._show()
        self.label.configure(
            text=self._text('linking_media_preparing').format(
                done=self._completed, total=self._total,
            ),
        )
        self.bar.stop()
        self._bar_running = False
        self.bar.configure(mode='determinate', maximum=max(1, self._total))
        self.bar['value'] = min(self._completed, self._total)

    def waiting(self, seconds: int | None, automatic: bool) -> None:
        """Reflect an existing automatic or manual retry wait.

        Args:
            seconds (int | None): Validated remaining delay, or None if unknown.
            automatic (bool): Whether existing code owns the retry automatically.
        Returns:
            None: The spinner continues independently of network progress.
        """
        if not self.active or self.phase in ('error', 'hidden'):
            return
        self.phase = 'waiting'
        self._wait_automatic = automatic
        self._wait_deadline = (
            time.monotonic() + max(0, seconds) if seconds is not None else None
        )
        self._show()
        if str(self.bar['mode']) != 'indeterminate':
            self.bar.stop()
            self._bar_running = False
            self.bar.configure(mode='indeterminate')
        self._start_bar()
        if seconds is None:
            key = 'linking_media_retrying' if automatic else 'linking_media_throttled'
            self.label.configure(text=self._text(key))
        else:
            key = 'linking_media_wait_retry' if automatic else 'linking_media_wait_manual'
            self.label.configure(
                text=self._text(key).format(seconds=max(0, seconds)),
            )

    def resume(self) -> None:
        """Return to active loading after the owned retry delay expires.

        Returns:
            None: Subsequent viewport updates can report resumed work.
        """
        if not self.active or self.phase != 'waiting':
            return
        self.phase = 'thumbnails' if self._total else 'metadata'
        self._wait_deadline = None
        # Starting a retry is activity, not newly confirmed metadata or pixels.
        if self._total:
            self.thumbnails(self._thumbnail_count, self._total)
        else:
            self.metadata(self._metadata_count)

    def compact(self) -> None:
        """Move the overlay aside after a real thumbnail becomes visible.

        Returns:
            None: The same widgets occupy a smaller corner surface.
        """
        if self._compact:
            return
        self._compact = True
        if not self.active or not self.visible:
            return
        self.frame.place(
            relx=1.0, rely=1.0, anchor='se',
            x=-_OVERLAY_MARGIN, y=-_OVERLAY_MARGIN,
            relwidth=_OVERLAY_WIDTH_FRACTIONS[1],
        )

    def rearm(self) -> None:
        """Restart a failed, still-unresolved load using the same widgets.

        Returns:
            None: A failed indicator becomes active for the explicit retry.
        """
        if (self.phase != 'error'
                and not (self.phase == 'waiting' and not self._wait_automatic)):
            return
        self._cancel_tick()
        self.bar.stop()
        self.active = True
        self.visible = False
        self.phase = 'connecting'
        # Retry continues this gallery opening. Preserve its elapsed time and
        # last real progress rather than presenting retry dispatch as progress.
        self._completed = 0
        self._metadata_count = 0
        self._wait_deadline = None
        self.bar.pack(fill='x', pady=(6, 0), before=self.details)
        self.bar.configure(mode='indeterminate')
        self._bar_running = False
        self.label.configure(text=self._text('linking_media_connecting'))
        self.details.configure(text='')
        self._show()

    def fail(self, message: str) -> None:
        """Show a terminal load failure without implying successful progress.

        Args:
            message (str): Already translated error message for the gallery.
        Returns:
            None: The bar stops while the owner's Retry action remains available.
        """
        if not self.active or self.phase in ('error', 'hidden'):
            return
        self.phase = 'error'
        self._show()
        self.bar.stop()
        self._bar_running = False
        self.bar.pack_forget()
        self.details.configure(text='')
        self.label.configure(text=message)
        self._cancel_tick()

    def _cancel_tick(self) -> None:
        """Cancel the one owned UI timer when progress stops or hides.

        Returns:
            None: A stale timer cannot update a hidden overlay.
        """
        tick_id, self._tick_id = self._tick_id, None
        if tick_id is not None:
            try:
                self._parent.after_cancel(tick_id)
            except tk.TclError:
                pass

    def hide(self) -> None:
        """Permanently hide this viewer's progress overlay.

        Returns:
            None: Refresh diagnostics cannot reactivate this controller.
        """
        if self._closed:
            return
        self.active = False
        self.visible = False
        self.phase = 'hidden'
        self._cancel_tick()
        try:
            self.bar.stop()
            self._bar_running = False
            self.frame.place_forget()
        except tk.TclError:
            pass

    def close(self) -> None:
        """Release the overlay timer and widgets during viewer shutdown.

        Returns:
            None: No controller-owned Tk callback remains pending.
        """
        if self._closed:
            return
        self.hide()
        self._closed = True
        try:
            self.frame.destroy()
        except tk.TclError:
            pass
