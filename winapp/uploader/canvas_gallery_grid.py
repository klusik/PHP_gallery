# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/uploader/canvas_gallery_grid.py
# Module Type: Windows uploader presentation support
# Purpose: Retain a bounded Canvas tile pool for the Photos gallery.
# Responsibilities:
#   - Map logical photo metadata to reusable Canvas image, placeholder and rim items.
#   - Dispatch real Canvas pointer and keyboard events to existing viewer owners.
#   - Keep active Tk images and native drawing objects within the admitted viewport.
#   - Show one shared inline tooltip for the current image or rim hover target.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)

"""Provide bounded Canvas-backed gallery tile proxies."""

from collections.abc import Callable, Mapping, Sequence
from dataclasses import dataclass
import sys
import tkinter as tk
from tkinter import font as tkfont
from typing import Protocol, TypeAlias

from .ui_theme import (
    GalleryGridTheme,
    attach_inline_tooltip,
    hide_inline_tooltip,
)


# Purpose: Bound Canvas drawing primitives for each admitted tile.
# Type: int. Units: Canvas items. Scope: one active viewport tile slot.
# Consumers: _CanvasSlot creation and canvas_items_count.
# Rationale: One image, one placeholder and one selection rim cover every tile.
GRID_PRIMITIVES_PER_SLOT = 3
# Purpose: Preserve Tk's Shift modifier behavior for tile selection.
# Type: int. Units: Tk state bit. Scope: real Canvas pointer events.
# Consumers: _on_button and _on_control_click.
# Rationale: Tk ShiftMask is bit zero.
TK_SHIFT_MASK = 0x0001
# Purpose: Preserve Tk Control and macOS Command selection modifiers.
# Type: int. Units: Tk state bit. Scope: real Canvas pointer events.
# Consumers: _on_button and _on_control_click.
# Rationale: Tk uses 0x0004 for Control.
TK_CONTROL_MASK = 0x0004
# Purpose: Preserve macOS Command-click and keyboard selection modifiers.
# Type: int. Units: Tk state bit. Scope: macOS pointer and keyboard events.
# Consumers: _has_toggle_modifier.
# Rationale: Tk maps Command to state bit 0x0008 on macOS.
TK_COMMAND_MASK = 0x0008

# Purpose: Keep rounded selection corners within the central theme radius.
# Type: int. Units: spline points per corner. Scope: one Canvas rim polygon.
# Consumers: _create_slot selection polygon.
# Rationale: Tk smooths the short corner path with a compact fixed subdivision.
RIM_CORNER_POINTS = 2

class TileImageHandle(Protocol):
    """Describe a retained Tk image accepted by Canvas image items."""

    def width(self) -> int:
        """Return the image width in Tk logical pixels.

        Returns:
            int: Width reported by the retained Tk image.
        """

    def height(self) -> int:
        """Return the image height in Tk logical pixels.

        Returns:
            int: Height reported by the retained Tk image.
        """

    def __str__(self) -> str:
        """Return the Tcl image name used by Canvas.

        Returns:
            str: Stable Tcl image handle name.
        """


CanvasOptionValue: TypeAlias = (
    str | int | bool | tuple[str, ...] | TileImageHandle | None
)
CanvasOptionQuery: TypeAlias = (
    CanvasOptionValue
    | dict[str, tuple[str, str, str, str, CanvasOptionValue]]
)


@dataclass
class _TileRecord:
    """Keep logical tile metadata independently of the bounded Canvas pool."""

    index: int
    photo_id: int
    title: str
    tooltip: "TooltipValue"
    image: TileImageHandle | None = None
    placeholder: str = ""
    compound: str = "none"
    style: str = "Placeholder.LinkedPhoto.TButton"
    selected: bool = False


@dataclass
class _CanvasSlot:
    """Bind three retained Canvas items and dirty state to one tile identity."""

    image_item: int
    placeholder_item: int
    rim_item: int
    gallery_id: int | None = None
    index: int | None = None
    photo_id: int | None = None
    epoch: int = -1
    image: TileImageHandle | None = None
    ever_bound: bool = False
    image_coords: tuple[int, int] | None = None
    placeholder_coords: tuple[int, int] | None = None
    rim_coords: tuple[int, ...] | None = None
    image_paint: tuple[str, str] | None = None
    placeholder_paint: tuple[str, str, str] | None = None
    rim_paint: tuple[str, str] | None = None


class TooltipValue:
    """Hold one plain tooltip string without a per-photo Tcl variable."""

    def __init__(
        self, value: str, on_change: Callable[[], None] | None = None,
    ) -> None:
        """Create the value holder for one tile's current description.

        Args:
            value (str): Sanitized title or thumbnail status text.
            on_change (Callable[[], None] | None): Active-owner refresh callback.

        Returns:
            None: The plain string is retained for the active tooltip owner.
        """
        self._value = value
        self._on_change = on_change

    def get(self) -> str:
        """Return the current tooltip text.

        Returns:
            str: Current plain-text description.
        """
        return self._value

    def set(self, value: str) -> None:
        """Replace the current tooltip text.

        Args:
            value (str): Updated title or thumbnail status text.

        Returns:
            None: The new text is retained without allocating a Tcl variable.
        """
        self._value = value
        if self._on_change is not None:
            self._on_change()


class CanvasGalleryGrid:
    """Render logical Photos tiles into a viewport-bounded Canvas pool."""

    def __init__(
        self,
        canvas: tk.Canvas,
        *,
        gallery_id: int,
        theme: GalleryGridTheme,
        gutter: int,
        placeholder: str,
        select: Callable[[int], bool],
        range_select: Callable[[int], bool],
        toggle: Callable[[int], bool],
        open_photo: Callable[[int], None],
        key: Callable[[int, tk.Event], str | None],
        context: Callable[[int | None, tk.Event], str | None],
        viewport: Callable[[], None],
    ) -> None:
        """Create the Canvas owner and install one set of input bindings.

        Args:
            canvas (tk.Canvas): Existing Photos viewport Canvas.
            gallery_id (int): Current authoritative gallery identity.
            theme (GalleryGridTheme): Palette and geometry tokens from ui_theme.
            gutter (int): Existing logical gap between fixed-size photo tiles.
            placeholder (str): Neutral glyph shown before an image is admitted.
            select (Callable[[int], bool]): Existing single-photo selection owner.
            range_select (Callable[[int], bool]): Existing anchored range owner.
            toggle (Callable[[int], bool]): Existing explicit toggle owner.
            open_photo (Callable[[int], None]): Existing foreground photo opener.
            key (Callable[[int, tk.Event], str | None]): Existing key dispatcher.
            context (Callable[[int | None, tk.Event], str | None]):
                Existing photo or empty-grid context owner.
            viewport (Callable[[], None]): Coalesced viewport admission callback.

        Returns:
            None: Logical metadata and a single bounded Canvas item pool are ready.
        """
        if type(gallery_id) is not int or gallery_id <= 0:
            raise ValueError("gallery_id must be a positive integer.")
        if type(gutter) is not int or gutter < 0:
            raise ValueError("gutter must be a nonnegative integer.")
        if not placeholder:
            raise ValueError("placeholder must not be empty.")
        self.canvas = canvas
        self.gallery_id = gallery_id
        self.theme = theme
        self.gutter = gutter
        self.placeholder = placeholder
        self._select = select
        self._range_select = range_select
        self._toggle = toggle
        self._open_photo = open_photo
        self._key = key
        self._context = context
        self._viewport = viewport
        self._records: dict[int, _TileRecord] = {}
        self._proxies: dict[int, CanvasTileProxy] = {}
        self._slots: list[_CanvasSlot] = []
        self._slot_by_index: dict[int, _CanvasSlot] = {}
        self._epoch = 0
        self._tile_size = (1, 1)
        self._placeholder_font = tkfont.Font(
            root=canvas, font=theme.placeholder_font,
        )
        self._width = max(1, canvas.winfo_width())
        self._height = max(1, canvas.winfo_height())
        self._last_view_top = max(0, int(canvas.canvasy(0)))
        self._columns = 1
        self._content_height = self._height
        self._focused_index: int | None = None
        self.item_reuse_count = 0
        self._closed = False
        self._extent_item = canvas.create_rectangle(
            0, 0, self._width, self._height,
            outline="", fill="",
        )
        self._tooltip_surface = canvas.winfo_toplevel()
        self._tooltip_message = tk.StringVar(master=canvas, value="")
        self._hover_identity: tuple[int, int, int, int] | None = None
        self._tooltip_refresh = attach_inline_tooltip(
            canvas,
            self._tooltip_message,
            self._tooltip_surface,
            anchor_bbox=self._tooltip_anchor_bbox,
            hover_region=self._tooltip_hover_region,
        )
        self._bindings: list[tuple[str, str]] = []
        for sequence, callback in (
            ("<Button-1>", self._on_button),
            ("<Double-Button-1>", self._on_double_click),
            ("<Button-2>", self._on_context),
            ("<Button-3>", self._on_context),
            ("<Control-Button-1>", self._on_control_click),
            ("<KeyPress>", self._on_key),
            ("<FocusIn>", self._on_focus_in),
            ("<Motion>", self._on_motion),
            ("<Leave>", self._on_leave),
        ):
            binding_id = canvas.bind(sequence, callback, add="+")
            if binding_id is not None:
                self._bindings.append((sequence, binding_id))

    @property
    def focused_index(self) -> int | None:
        """Return the logical index currently owned by Canvas keyboard focus.

        Returns:
            int | None: Current loaded photo index or None before focus is set.
        """
        return self._focused_index

    @property
    def active_indices(self) -> tuple[int, ...]:
        """Return the exact logical identities currently represented by slots.

        Returns:
            tuple[int, ...]: Ordered admitted indices with current Canvas items.
        """
        return tuple(
            slot.index for slot in self._slots if slot.index is not None
        )

    @property
    def canvas_items_count(self) -> int:
        """Return the exact retained Canvas item count, including extent sentinel.

        Returns:
            int: Zero after close; otherwise three items per slot plus the extent.
        """
        return 0 if self._closed else GRID_PRIMITIVES_PER_SLOT * len(self._slots) + 1

    def append(self, index: int, photo_id: int, title: str) -> "CanvasTileProxy":
        """Append logical metadata without creating a per-photo Tk object.

        Args:
            index (int): Zero-based contiguous position in the current gallery.
            photo_id (int): Positive server photo identity.
            title (str): Sanitized visible tooltip title.

        Returns:
            CanvasTileProxy: Lightweight adapter for the existing viewer owner.

        Raises:
            ValueError: Index is not the next position or identity is invalid.
        """
        if type(index) is not int or index != len(self._records):
            raise ValueError("Canvas gallery indices must append contiguously.")
        if type(photo_id) is not int or photo_id <= 0:
            raise ValueError("photo_id must be a positive integer.")
        if not isinstance(title, str):
            raise TypeError("title must be a string.")
        record = _TileRecord(
            index=index, photo_id=photo_id, title=title,
            tooltip=TooltipValue(
                title,
                lambda epoch=self._epoch: self._tooltip_value_changed(
                    index, photo_id, epoch,
                ),
            ),
            placeholder=self.placeholder,
        )
        self._records[index] = record
        proxy = CanvasTileProxy(self, index, photo_id, self._epoch)
        self._proxies[index] = proxy
        return proxy

    def reset_catalogue(self) -> None:
        """Invalidate logical proxies while retaining reusable Canvas items.

        Returns:
            None: The next catalogue starts in a new epoch with cleared images.
        """
        if self._closed:
            return
        self._clear_hover_tooltip()
        self._epoch += 1
        self._records.clear()
        self._proxies.clear()
        self._focused_index = None
        self._slot_by_index.clear()
        for slot in self._slots:
            self._clear_slot(slot)
        self._viewport()

    def layout(
        self,
        width: int,
        height: int,
        tile_size: tuple[int, int],
    ) -> tuple[int, int]:
        """Reflow fixed cells and preserve the logical top photo and row offset.

        Args:
            width (int): Current viewport width in Tk logical pixels.
            height (int): Current viewport height in Tk logical pixels.
            tile_size (tuple[int, int]): Current image width and height.

        Returns:
            tuple[int, int]: Responsive column count and logical content height.

        Raises:
            ValueError: Viewport or image dimensions are not positive integers.
        """
        if (type(width) is not int or width < 1 or type(height) is not int
                or height < 1 or len(tile_size) != 2
                or any(type(part) is not int or part < 1 for part in tile_size)):
            raise ValueError("Canvas grid geometry must use positive integers.")
        old_width = self._width
        old_height = self._height
        old_tile_size = self._tile_size
        old_columns = self._columns
        old_pitch_y = self._tile_size[1] + 2 * self.theme.border_inset + self.gutter
        old_top = max(0, int(self.canvas.canvasy(0)))
        top_index = 0
        row_offset = 0
        if self._records and old_columns > 0:
            old_row = old_top // max(1, old_pitch_y)
            top_index = min(
                len(self._records) - 1, old_row * old_columns,
            )
            row_offset = old_top % max(1, old_pitch_y)
        if (self._hover_identity is not None
                and (width != old_width or height != old_height
                     or tile_size != old_tile_size)):
            self._clear_hover_tooltip()
        self._width = width
        self._height = height
        self._tile_size = tile_size
        pitch_x = tile_size[0] + 2 * self.theme.border_inset + self.gutter
        self._columns = max(1, width // max(1, pitch_x))
        row_count = (len(self._records) + self._columns - 1) // self._columns
        pitch_y = tile_size[1] + 2 * self.theme.border_inset + self.gutter
        self._content_height = max(
            height, max(0, row_count * pitch_y - self.gutter // 2),
        )
        content_width = max(
            width, max(0, self._columns * pitch_x - self.gutter // 2),
        )
        self.canvas.configure(scrollregion=(0, 0, content_width, self._content_height))
        self.canvas.coords(self._extent_item, 0, 0, content_width, self._content_height)
        for slot in self._slots:
            if slot.index is not None:
                self._place_slot(slot)
        if self._records and old_columns != self._columns:
            new_row = top_index // self._columns
            target_y = new_row * pitch_y + min(row_offset, pitch_y - 1)
            max_y = max(0, self._content_height - height)
            self.canvas.yview_moveto(
                min(max_y, target_y) / max(1, self._content_height),
            )
        self._viewport()
        return self._columns, self._content_height

    def visible_indices(self) -> tuple[int, ...]:
        """Return logical tiles whose actual image/rim bounds intersect Canvas.

        Returns:
            tuple[int, ...]: Ordered visible indices, excluding fixed-cell gutters.
        """
        if not self._records or self._closed:
            return ()
        pitch_y = self._tile_size[1] + 2 * self.theme.border_inset + self.gutter
        top = max(0, int(self.canvas.canvasy(0)))
        bottom = top + max(1, self.canvas.winfo_height())
        first_row = max(0, top // max(1, pitch_y))
        last_row = min(
            (len(self._records) - 1) // self._columns,
            max(first_row, (bottom - 1) // max(1, pitch_y)),
        )
        visible: list[int] = []
        for row in range(first_row, last_row + 1):
            start = row * self._columns
            end = min(len(self._records), start + self._columns)
            for index in range(start, end):
                left, tile_top, width, height = self._actual_bounds(index)
                if (left + width > 0 and left < self._width
                        and tile_top + height > top and tile_top < bottom):
                    visible.append(index)
        return tuple(visible)

    def reconcile(self, admitted: Sequence[int]) -> None:
        """Paint exactly the caller's already-overscanned admitted band.

        Args:
            admitted (Sequence[int]): Exact sorted unique logical viewport band.

        Returns:
            None: Slots reuse retained identities; an already-focused empty
                Canvas adopts the first admitted logical focus without scrolling.

        Raises:
            ValueError: An admitted index is missing, duplicated or unordered.
        """
        if self._closed:
            return
        indices = tuple(admitted)
        if indices != tuple(sorted(set(indices))):
            raise ValueError("Admitted tile indices must be sorted and unique.")
        if any(index not in self._records for index in indices):
            raise ValueError("Admitted tile index has no logical metadata.")

        view_top = max(0, int(self.canvas.canvasy(0)))
        if self._hover_identity is not None and view_top != self._last_view_top:
            self._clear_hover_tooltip()
        self._last_view_top = view_top
        target_set = set(indices)
        hovered = self._hover_identity
        if (hovered is not None
                and (hovered[0] != self.gallery_id
                     or hovered[1] != self._epoch
                     or hovered[2] not in target_set
                     or self._record(hovered[2], hovered[3], hovered[1]) is None)):
            self._clear_hover_tooltip()
        previous_slots = dict(self._slot_by_index)
        free_slots = [slot for slot in self._slots if slot.index is None]
        for index, slot in previous_slots.items():
            if index not in target_set:
                self._slot_by_index.pop(index, None)
                self._clear_slot(slot)
                free_slots.append(slot)

        next_slots: dict[int, _CanvasSlot] = {}
        for index in indices:
            record = self._records[index]
            slot = previous_slots.get(index)
            if slot is None:
                if free_slots:
                    slot = free_slots.pop()
                else:
                    slot = self._create_slot()
                if slot.ever_bound:
                    self.item_reuse_count += 1
                slot.ever_bound = True
                slot.gallery_id = self.gallery_id
                slot.epoch = self._epoch
                slot.index = index
                slot.photo_id = record.photo_id
            elif (slot.gallery_id != self.gallery_id
                    or slot.epoch != self._epoch
                    or slot.photo_id != record.photo_id):
                self._clear_slot(slot)
                self.item_reuse_count += 1
                slot.ever_bound = True
                slot.gallery_id = self.gallery_id
                slot.epoch = self._epoch
                slot.index = index
                slot.photo_id = record.photo_id
            next_slots[index] = slot
            self._place_slot(slot)
            self._paint_slot(slot, record)

        keep = {id(slot) for slot in next_slots.values()}
        for slot in self._slots:
            if id(slot) not in keep:
                self._clear_slot(slot)
                self._delete_slot(slot)
        self._slots = [next_slots[index] for index in indices]
        self._slot_by_index = next_slots
        if self._focused_index is None and indices:
            try:
                canvas_owns_focus = self.canvas.focus_get() is self.canvas
            except tk.TclError:
                canvas_owns_focus = False
            if canvas_owns_focus:
                self._focused_index = indices[0]
                self._update_rims()

    def focus(
        self,
        index: int,
        force: bool = False,
        *,
        ensure_visible: bool = True,
    ) -> None:
        """Focus Canvas on a logical photo and optionally reveal its hitbox.

        Args:
            index (int): Current loaded photo index.
            force (bool): Force Tk focus through the normal toplevel manager.
            ensure_visible (bool): Scroll the current tile into the viewport.

        Returns:
            None: Canvas focus and the exact logical index remain paired.
        """
        if index not in self._records or self._closed:
            return
        self._focused_index = index
        if force:
            self.canvas.focus_force()
        else:
            self.canvas.focus_set()
        if ensure_visible:
            _tile_x, tile_y, _tile_width, tile_height = self._actual_bounds(index)
            top = max(0, int(self.canvas.canvasy(0)))
            bottom = top + max(1, self.canvas.winfo_height())
            tile_top = tile_y
            tile_bottom = tile_top + tile_height
            if tile_top < top or tile_bottom > bottom:
                target_y = tile_top if tile_top < top else tile_bottom - self._height
                max_y = max(0, self._content_height - self._height)
                self.canvas.yview_moveto(
                    min(max_y, max(0, target_y))
                    / max(1, self._content_height),
                )
                self._viewport()
        self._update_rims()

    def close(self) -> None:
        """Release image references, item bindings and retained Canvas items.

        Returns:
            None: Repeated close calls and already-destroyed Canvas errors are safe.
        """
        if self._closed:
            return
        self._closed = True
        self._hover_identity = None
        try:
            hide_inline_tooltip(self._tooltip_surface, trigger=self.canvas)
            self._tooltip_message.set("")
        except tk.TclError:
            pass
        for slot in self._slots:
            self._clear_slot(slot)
            self._delete_slot(slot)
        self._slots.clear()
        self._slot_by_index.clear()
        try:
            for sequence, binding_id in self._bindings:
                self.canvas.unbind(sequence, binding_id)
            self.canvas.delete(self._extent_item)
            self.canvas.configure(scrollregion=(0, 0, 0, 0))
        except tk.TclError:
            pass
        self._bindings.clear()
        self._records.clear()
        self._proxies.clear()
        self._focused_index = None
        self._tooltip_refresh = None
        del self._tooltip_message
        del self._placeholder_font

    def _create_slot(self) -> _CanvasSlot:
        """Create exactly one image, placeholder and selection rim item.

        Returns:
            _CanvasSlot: Unbound reusable Canvas item set.
        """
        image_item = self.canvas.create_image(
            0, 0, anchor="nw", image="", state="hidden",
        )
        placeholder_item = self.canvas.create_text(
            0, 0, text=self.placeholder,
            fill=self.theme.muted,
            font=self.theme.placeholder_font,
            anchor="center",
        )
        rim_item = self.canvas.create_polygon(
            0, 0, 0, 0, 0, 0,
            fill="", outline="", width=self.theme.border_inset, smooth=True,
            splinesteps=RIM_CORNER_POINTS,
        )
        return _CanvasSlot(image_item, placeholder_item, rim_item)

    def _clear_slot(self, slot: _CanvasSlot) -> None:
        """Clear the Tcl image and dirty cache before slot identity changes.

        Args:
            slot (_CanvasSlot): Current reusable Canvas item set.

        Returns:
            None: Tcl/Python image references and per-identity caches are cleared.
        """
        try:
            self.canvas.itemconfigure(slot.image_item, image="", state="hidden")
            self.canvas.itemconfigure(
                slot.placeholder_item, text="", state="hidden",
            )
            self.canvas.itemconfigure(slot.rim_item, outline="", state="hidden")
        except tk.TclError:
            pass
        slot.image = None
        slot.gallery_id = None
        slot.index = None
        slot.photo_id = None
        slot.epoch = -1
        slot.image_coords = None
        slot.placeholder_coords = None
        slot.rim_coords = None
        slot.image_paint = None
        slot.placeholder_paint = None
        slot.rim_paint = None

    def _delete_slot(self, slot: _CanvasSlot) -> None:
        """Delete the three Canvas items belonging to one retired slot.

        Args:
            slot (_CanvasSlot): Canvas slot being trimmed or closed.

        Returns:
            None: No native item remains for this slot.
        """
        try:
            self.canvas.delete(slot.image_item, slot.placeholder_item, slot.rim_item)
        except tk.TclError:
            return

    def _place_slot(self, slot: _CanvasSlot) -> None:
        """Update only Canvas coordinates changed by this tile's geometry.

        Args:
            slot (_CanvasSlot): Bound current slot.

        Returns:
            None: Cached image, placeholder and rim coordinates stay current.
        """
        if slot.index is None:
            return
        x, y, width, height = self._actual_bounds(slot.index)
        inset = self.theme.border_inset
        record = self._records.get(slot.index)
        if record is not None and record.image is not None:
            image_width = record.image.width()
            image_height = record.image.height()
            image_coords = (
                x + inset + max(0, (width - 2 * inset - image_width) // 2),
                y + inset + max(0, (height - 2 * inset - image_height) // 2),
            )
        else:
            image_coords = (x + inset, y + inset)
        placeholder_coords = (x + width // 2, y + height // 2)
        rim_coords = _rim_coordinates(
            x, y, width, height, self.theme.corner_radius,
        )
        if slot.image_coords != image_coords:
            self.canvas.coords(slot.image_item, *image_coords)
            slot.image_coords = image_coords
        if slot.placeholder_coords != placeholder_coords:
            self.canvas.coords(
                slot.placeholder_item, *placeholder_coords,
            )
            slot.placeholder_coords = placeholder_coords
        if slot.rim_coords != rim_coords:
            self.canvas.coords(slot.rim_item, *rim_coords)
            slot.rim_coords = rim_coords


    def _paint_slot(self, slot: _CanvasSlot, record: _TileRecord) -> None:
        """Update only image, placeholder or rim state that actually changed.

        Args:
            slot (_CanvasSlot): Bound Canvas slot for the tile.
            record (_TileRecord): Current logical metadata and presentation.

        Returns:
            None: Current visual state matches the record without redundant Tcl calls.
        """
        if (slot.gallery_id != self.gallery_id or slot.index != record.index
                or slot.photo_id != record.photo_id):
            return
        slot.image = record.image
        image_value = record.image if record.image is not None else ""
        image_paint = (
            str(record.image) if record.image is not None else "",
            "normal" if record.image is not None else "hidden",
        )
        if slot.image_paint != image_paint:
            self.canvas.itemconfigure(
                slot.image_item,
                image=image_value,
                state=image_paint[1],
            )
            slot.image_paint = image_paint

        placeholder_paint = (
            "" if record.image is not None
            else (record.placeholder or self.placeholder),
            "hidden" if record.image is not None else "normal",
            self.theme.muted,
        )
        if slot.placeholder_paint != placeholder_paint:
            self.canvas.itemconfigure(
                slot.placeholder_item,
                text=placeholder_paint[0],
                state=placeholder_paint[1],
                fill=placeholder_paint[2],
            )
            slot.placeholder_paint = placeholder_paint

        rim_paint = (
            self.theme.primary if record.selected else "",
            "normal" if record.selected else "hidden",
        )
        if slot.rim_paint != rim_paint:
            self.canvas.itemconfigure(
                slot.rim_item,
                outline=rim_paint[0],
                state=rim_paint[1],
            )
            slot.rim_paint = rim_paint


    def _update_rims(self) -> None:
        """Refresh selected outlines for currently active slots only.

        Returns:
            None: Logical selection remains owned by the viewer callbacks.
        """
        for slot in self._slots:
            if slot.index is not None:
                record = self._records.get(slot.index)
                if record is not None:
                    self._paint_slot(slot, record)

    def _tile_origin(self, index: int) -> tuple[int, int]:
        """Return a fixed cell's logical Canvas origin.

        Args:
            index (int): Current logical tile index.

        Returns:
            tuple[int, int]: Cell origin in scrollable Canvas coordinates.
        """
        inset = self.theme.border_inset
        pitch_x = self._tile_size[0] + 2 * inset + self.gutter
        pitch_y = self._tile_size[1] + 2 * inset + self.gutter
        return (
            (index % self._columns) * pitch_x + self.gutter // 2,
            (index // self._columns) * pitch_y + self.gutter // 2,
        )

    def _actual_bounds(self, index: int) -> tuple[int, int, int, int]:
        """Return centered outer bounds for the actual image or placeholder.

        Args:
            index (int): Current logical tile index.

        Returns:
            tuple[int, int, int, int]: Outer rim x, y, width and height in Canvas pixels.
        """
        record = self._records[index]
        if record.image is not None:
            content_width = record.image.width()
            content_height = record.image.height()
        else:
            content_width = self._placeholder_font.measure(
                record.placeholder or self.placeholder,
            )
            content_height = self._placeholder_font.metrics("linespace")
        content_width = max(1, min(self._tile_size[0], content_width))
        content_height = max(1, min(self._tile_size[1], content_height))
        cell_x, cell_y = self._tile_origin(index)
        x = cell_x + (self._tile_size[0] - content_width) // 2
        y = cell_y + (self._tile_size[1] - content_height) // 2
        inset = self.theme.border_inset
        return x, y, content_width + 2 * inset, content_height + 2 * inset

    def _record(self, index: int, photo_id: int, epoch: int) -> _TileRecord | None:
        """Return a proxy record only while its captured identity remains current.

        Args:
            index (int): Logical tile index captured by the proxy.
            photo_id (int): Captured photo identity.
            epoch (int): Captured catalogue generation.

        Returns:
            _TileRecord | None: Current record, or None for a stale proxy.
        """
        if epoch != self._epoch:
            return None
        record = self._records.get(index)
        if (record is None or record.photo_id != photo_id
                or self._proxies.get(index) is None
                or self._proxies[index]._epoch != epoch):
            return None
        return record

    def _configure_proxy(
        self,
        proxy: "CanvasTileProxy",
        cnf: str | Mapping[str, CanvasOptionValue] | None,
        options: Mapping[str, CanvasOptionValue],
    ) -> CanvasOptionQuery | None:
        """Apply or query the supported subset of native tile options.

        Args:
            proxy (CanvasTileProxy): Exact current proxy requesting access.
            cnf (str | Mapping[str, CanvasOptionValue] | None):
                Optional native-style option query or option mapping.
            options (Mapping[str, CanvasOptionValue]): Options to update.

        Returns:
            CanvasOptionQuery | None: Current option or compact option map.

        Raises:
            tk.TclError: A stale proxy or unsupported option was accessed.
        """
        record = self._record(proxy._index, proxy._photo_id, proxy._epoch)
        if record is None or self._proxies.get(proxy._index) is not proxy:
            raise tk.TclError("tile proxy belongs to an expired catalogue")
        if isinstance(cnf, str):
            return self._cget_proxy(record, cnf)
        merged = dict(cnf) if isinstance(cnf, Mapping) else {}
        merged.update(options)
        if not merged:
            return {
                name: ("", "", "", "", self._cget_proxy(record, name))
                for name in ("image", "text", "compound", "style")
            }
        for name, value in merged.items():
            if name == "image":
                slot = self._slot_by_index.get(proxy._index)
                image = value if _is_tile_image(value) else None
                record.image = image
                if slot is not None and slot.epoch == proxy._epoch and slot.photo_id == proxy._photo_id:
                    slot.image = image
                    self._place_slot(slot)
                    self._paint_slot(slot, record)
            elif name == "text":
                if not isinstance(value, str):
                    raise tk.TclError("tile text must be a string")
                record.placeholder = value
                slot = self._slot_by_index.get(proxy._index)
                if slot is not None:
                    self._place_slot(slot)
                    self._paint_slot(slot, record)
            elif name == "compound":
                if not isinstance(value, str):
                    raise tk.TclError("tile compound must be a string")
                record.compound = value
            elif name == "style":
                if not isinstance(value, str):
                    raise tk.TclError("tile style must be a string")
                record.style = value
                record.selected = value == "Selected.LinkedPhoto.TButton"
                slot = self._slot_by_index.get(proxy._index)
                if slot is not None:
                    self._paint_slot(slot, record)
            else:
                raise tk.TclError(f"unsupported tile option: {name}")
        return None

    def _cget_proxy(self, record: _TileRecord, option: str) -> CanvasOptionValue:
        """Read one current ttk-compatible tile option.

        Args:
            record (_TileRecord): Current tile metadata.
            option (str): Option name requested by the viewer.

        Returns:
            CanvasOptionValue: Current value for the requested option.

        Raises:
            tk.TclError: The option is outside the narrow proxy contract.
        """
        if option == "image":
            return record.image if record.image is not None else ""
        if option == "text":
            return record.placeholder
        if option == "compound":
            return record.compound
        if option == "style":
            return record.style
        raise tk.TclError(f"unsupported tile option: {option}")

    def _state_proxy(
        self,
        proxy: "CanvasTileProxy",
        statespec: Sequence[str] | None,
    ) -> tuple[str, ...]:
        """Get or update selected and focused state flags.

        Args:
            proxy (CanvasTileProxy): Exact current proxy requesting state.
            statespec (Sequence[str] | None): Optional selected or focus changes.

        Returns:
            tuple[str, ...]: Current positive state flags.

        Raises:
            tk.TclError: The proxy has been invalidated.
        """
        record = self._record(proxy._index, proxy._photo_id, proxy._epoch)
        if record is None or self._proxies.get(proxy._index) is not proxy:
            raise tk.TclError("tile proxy belongs to an expired catalogue")
        if statespec is not None:
            for state in statespec:
                if state == "selected":
                    record.selected = True
                elif state == "!selected":
                    record.selected = False
                elif state == "focus":
                    self._focused_index = proxy._index
                elif state == "!focus":
                    if self._focused_index == proxy._index:
                        self._focused_index = None
                else:
                    raise tk.TclError(f"unsupported tile state: {state}")
            slot = self._slot_by_index.get(proxy._index)
            if slot is not None:
                self._paint_slot(slot, record)
        current: list[str] = []
        if record.selected:
            current.append("selected")
        if self._focused_index == proxy._index:
            current.append("focus")
        return tuple(current)

    def _detach_proxy(self, proxy: "CanvasTileProxy") -> None:
        """Clear a proxy only if its exact gallery, epoch and record still match.

        Args:
            proxy (CanvasTileProxy): Adapter requesting destruction.

        Returns:
            None: A stale proxy cannot detach a replacement at the same index.
        """
        record = self._record(proxy._index, proxy._photo_id, proxy._epoch)
        if record is None or self._proxies.get(proxy._index) is not proxy:
            return
        slot = self._slot_by_index.pop(proxy._index, None)
        if slot is not None:
            self._clear_slot(slot)
            self._slots.remove(slot)
            self._delete_slot(slot)
        record.image = None
        record.selected = False
        self._records.pop(proxy._index, None)
        self._proxies.pop(proxy._index, None)
        if self._focused_index == proxy._index:
            self._focused_index = None

    def _on_focus_in(self, _event: tk.Event) -> None:
        """Initialize logical focus once when Canvas receives initial focus.

        Args:
            _event (tk.Event): Native Canvas FocusIn event.

        Returns:
            None: Existing keyboard focus survives later focus restoration.
        """
        if self._focused_index is None and self._records:
            self._focused_index = min(self._records)

    def _on_motion(self, event: tk.Event) -> None:
        """Refresh the single tooltip for the actual image or rim under pointer.

        Args:
            event (tk.Event): Mapped Canvas pointer motion event.

        Returns:
            None: Only a current photo identity can activate inline help.
        """
        if self._closed:
            return
        index = self._hit_event(event)
        if index is None:
            self._clear_hover_tooltip()
            return
        record = self._records.get(index)
        if record is None:
            self._clear_hover_tooltip()
            return
        identity = (self.gallery_id, self._epoch, index, record.photo_id)
        if identity == self._hover_identity:
            tooltip_state = getattr(
                self._tooltip_surface, "_inline_tooltip_state", None,
            )
            if (tooltip_state is not None
                    and tooltip_state.trigger is self.canvas):
                return
        else:
            self._hover_identity = identity
        try:
            self._tooltip_message.set(record.tooltip.get())
            if self._tooltip_refresh is not None:
                self._tooltip_refresh()
        except tk.TclError:
            self._clear_hover_tooltip()

    def _on_leave(self, _event: tk.Event) -> None:
        """Dismiss inline help when the pointer leaves the Canvas.

        Args:
            _event (tk.Event): Canvas leave event.

        Returns:
            None: The active photo identity and shared tooltip text are cleared.
        """
        if not self._closed:
            self._clear_hover_tooltip()

    def _clear_hover_tooltip(self) -> None:
        """Clear this Canvas trigger's active tooltip without affecting others.

        Returns:
            None: Stale hover ownership and text are removed safely.
        """
        self._hover_identity = None
        if self._closed:
            return
        try:
            self._tooltip_message.set("")
            hide_inline_tooltip(self._tooltip_surface, trigger=self.canvas)
        except tk.TclError:
            pass

    def _tooltip_value_changed(
        self, index: int, photo_id: int, epoch: int,
    ) -> None:
        """Refresh live tooltip text only for the still-hovered tile identity.

        Args:
            index (int): Logical tile index captured by the value holder.
            photo_id (int): Photo identity captured by the value holder.
            epoch (int): Catalogue generation captured by the value holder.

        Returns:
            None: Recycled or stale proxies cannot update the active help.
        """
        identity = (self.gallery_id, epoch, index, photo_id)
        if self._closed or identity != self._hover_identity:
            return
        record = self._record(index, photo_id, epoch)
        if record is None:
            self._clear_hover_tooltip()
            return
        try:
            self._tooltip_message.set(record.tooltip.get())
            if self._tooltip_refresh is not None:
                self._tooltip_refresh()
        except tk.TclError:
            self._clear_hover_tooltip()

    def _tooltip_anchor_bbox(self) -> tuple[int, int, int, int] | None:
        """Return the current actual photo bounds in root-window coordinates.

        Returns:
            tuple[int, int, int, int] | None: Hovered image/rim bounds or None.
        """
        identity = self._hover_identity
        if identity is None or self._closed:
            return None
        gallery_id, epoch, index, photo_id = identity
        if gallery_id != self.gallery_id or self._record(index, photo_id, epoch) is None:
            return None
        try:
            x, y, width, height = self._actual_bounds(index)
            left = self.canvas.winfo_rootx() + x - self.canvas.canvasx(0)
            top = self.canvas.winfo_rooty() + y - self.canvas.canvasy(0)
            return int(left), int(top), width, height
        except tk.TclError:
            return None

    def _tooltip_hover_region(self) -> bool:
        """Check that pointer remains on this exact current photo hitbox.

        Returns:
            bool: True only while the pointer stays within the active image/rim.
        """
        identity = self._hover_identity
        if identity is None or self._closed:
            return False
        try:
            if not self.canvas.winfo_exists() or not self.canvas.winfo_ismapped():
                return False
            pointer_x, pointer_y = self.canvas.winfo_pointerxy()
            x = self.canvas.canvasx(pointer_x - self.canvas.winfo_rootx())
            y = self.canvas.canvasy(pointer_y - self.canvas.winfo_rooty())
            index = self._hit(x, y)
            return index == identity[2] and identity == (
                self.gallery_id,
                self._epoch,
                index,
                self._records[index].photo_id if index in self._records else -1,
            )
        except (tk.TclError, KeyError):
            return False

    def _on_button(self, event: tk.Event) -> str | None:
        """Route a real left-click to the current image or rim hitbox.

        Args:
            event (tk.Event): Canvas pointer event with client coordinates.

        Returns:
            str | None: Handled tile events stop propagation.
        """
        if sys.platform == "darwin" and event.state & TK_CONTROL_MASK:
            return self._dispatch_context(self._hit_event(event), event)
        index = self._hit_event(event)
        if index is None:
            return None
        if event.state & TK_SHIFT_MASK:
            self._range_select(index)
        elif self._has_toggle_modifier(event.state):
            self._toggle(index)
        else:
            self._select(index)
        return "break"

    def _on_double_click(self, event: tk.Event) -> str | None:
        """Open the photo under a real double-click through its current owner.

        Args:
            event (tk.Event): Canvas double-click event.

        Returns:
            str | None: Handled photo events stop propagation.
        """
        index = self._hit_event(event)
        if index is None:
            return None
        if sys.platform == "darwin" and event.state & TK_CONTROL_MASK:
            return self._dispatch_context(index, event)
        if event.state & TK_SHIFT_MASK:
            self._range_select(index)
        elif self._has_toggle_modifier(event.state):
            self._toggle(index)
        else:
            self._open_photo(index)
        return "break"

    def _on_control_click(self, event: tk.Event) -> str | None:
        """Apply the platform-specific Control-click action.

        Args:
            event (tk.Event): Control-modified Canvas click.

        Returns:
            str | None: Handled events stop propagation.
        """
        if sys.platform == "darwin":
            return self._dispatch_context(self._hit_event(event), event)
        index = self._hit_event(event)
        if index is None:
            return None
        self._toggle(index)
        return "break"

    def _on_context(self, event: tk.Event) -> str | None:
        """Route pointer context actions from a photo or unused grid space.

        Args:
            event (tk.Event): Native right or middle click.

        Returns:
            str | None: Existing menu callback result or None.
        """
        return self._dispatch_context(self._hit_event(event), event)

    def _dispatch_context(
        self,
        index: int | None,
        event: tk.Event,
    ) -> str | None:
        """Call the existing context owner with a current logical identity.

        Args:
            index (int | None): Photo index under the image/rim or None for empty.
            event (tk.Event): Original Tk pointer event.

        Returns:
            str | None: Existing Tk break marker or no handling.
        """
        return self._context(index, event)

    def _on_key(self, event: tk.Event) -> str | None:
        """Forward a key event to the current logical focus owner.

        Args:
            event (tk.Event): Key event received by the focused Canvas.

        Returns:
            str | None: Existing viewer key dispatcher result.
        """
        index = self._focused_index
        context_key = (
            event.keysym == "Menu"
            or (event.keysym == "F10" and event.state & TK_SHIFT_MASK)
        )
        if index is None or index not in self._records:
            if context_key:
                return self._context(None, event)
            return None
        if context_key:
            return self._context(index, self._keyboard_context_event(event, index))
        return self._key(index, event)

    def _keyboard_context_event(self, event: tk.Event, index: int) -> tk.Event:
        """Place keyboard context menus at the focused tile's actual hitbox.

        Args:
            event (tk.Event): Original keyboard context event.
            index (int): Current focused tile.

        Returns:
            tk.Event: Canvas event with root coordinates at the tile center.
        """
        proxy = self._proxies.get(index)
        root_x = (
            proxy.winfo_rootx() + proxy.winfo_width() // 2
            if proxy is not None else self.canvas.winfo_rootx()
        )
        root_y = (
            proxy.winfo_rooty() + proxy.winfo_height() // 2
            if proxy is not None else self.canvas.winfo_rooty()
        )
        result = tk.Event()
        result.widget = self.canvas
        result.x_root = root_x
        result.y_root = root_y
        result.x = root_x - self.canvas.winfo_rootx()
        result.y = root_y - self.canvas.winfo_rooty()
        result.state = event.state
        result.keysym = event.keysym
        result.type = event.type
        return result

    def _hit_event(self, event: tk.Event) -> int | None:
        """Find a tile only when the pointer is on its image or selection rim.

        Args:
            event (tk.Event): Canvas event with viewport-relative x and y.

        Returns:
            int | None: Current logical tile identity, excluding gutters.
        """
        return self._hit(
            self.canvas.canvasx(event.x),
            self.canvas.canvasy(event.y),
        )

    def _hit(self, x: float, y: float) -> int | None:
        """Return the active tile hit by one scrollable Canvas point.

        Args:
            x (float): Horizontal Canvas content coordinate.
            y (float): Vertical Canvas content coordinate.

        Returns:
            int | None: Current visible image/rim tile index or None.
        """
        for index, slot in self._slot_by_index.items():
            if (slot.gallery_id != self.gallery_id or slot.epoch != self._epoch
                    or slot.index != index or index not in self._records):
                continue
            left, top, width, height = self._actual_bounds(index)
            if left <= x < left + width and top <= y < top + height:
                return index
        return None

    def _has_toggle_modifier(self, state: int) -> bool:
        """Check platform-specific additive-selection modifier bits.

        Args:
            state (int): Tk event state bit field.

        Returns:
            bool: True for Control or macOS Command.
        """
        mask = TK_COMMAND_MASK if sys.platform == "darwin" else TK_CONTROL_MASK
        return bool(state & mask)


class CanvasTileProxy:
    """Expose the narrow current-owner widget surface for one photo record."""

    def __init__(
        self,
        grid: CanvasGalleryGrid,
        index: int,
        photo_id: int,
        epoch: int,
    ) -> None:
        """Create a lightweight reference to one logical tile identity.

        Args:
            grid (CanvasGalleryGrid): Bounded Canvas renderer owning the tile.
            index (int): Zero-based logical gallery index.
            photo_id (int): Positive gallery photo identity.
            epoch (int): Catalogue generation captured at append.

        Returns:
            None: No Tk widget or Tcl variable is allocated.
        """
        self._grid = grid
        self._index = index
        self._photo_id = photo_id
        self._epoch = epoch
        self.tooltip = grid._records[index].tooltip

    def configure(
        self,
        cnf: str | Mapping[str, CanvasOptionValue] | None = None,
        **kwargs: CanvasOptionValue,
    ) -> CanvasOptionQuery | None:
        """Configure or query supported native tile display options.

        Args:
            cnf (str | Mapping[str, CanvasOptionValue] | None):
                Optional option name or mapping.
            **kwargs (CanvasOptionValue): Image, placeholder text, compound or style.

        Returns:
            CanvasOptionQuery | None: Queried option values or None after update.
        """
        return self._grid._configure_proxy(self, cnf, kwargs)

    config = configure

    def cget(self, option: str) -> CanvasOptionValue:
        """Return one supported native-style tile option.

        Args:
            option (str): Image, text, compound or style option name.

        Returns:
            CanvasOptionValue: Current option value.
        """
        result = self.configure(option)
        return result if result is not None else None

    def state(self, statespec: Sequence[str] | None = None) -> tuple[str, ...]:
        """Read or update selected and focused state flags.

        Args:
            statespec (Sequence[str] | None): Optional selected or focus changes.

        Returns:
            tuple[str, ...]: Current positive state flags.
        """
        return self._grid._state_proxy(self, statespec)

    def focus_set(self) -> None:
        """Give Canvas keyboard focus while retaining the logical tile index.

        Returns:
            None: The Canvas receives focus and remembers this tile index.
        """
        self._grid.focus(self._index, ensure_visible=False)

    def focus_force(self) -> None:
        """Force Canvas focus while retaining the logical tile index.

        Returns:
            None: The Canvas receives forced focus and remembers this tile index.
        """
        self._grid.focus(self._index, force=True, ensure_visible=False)

    def event_generate(
        self,
        sequence: str,
        **kwargs: str | int | bool,
    ) -> None:
        """Translate tile-local coordinates into a real Canvas event.

        Args:
            sequence (str): Tk event sequence such as Button-1 or Right.
            **kwargs (str | int | bool): Native Tk event fields in tile-local units.

        Returns:
            None: Existing Canvas bindings receive the translated event directly.

        Raises:
            tk.TclError: The proxy identity is stale.
        """
        if not self.winfo_exists():
            raise tk.TclError("tile proxy belongs to an expired catalogue")
        values: dict[str, str | int | bool] = dict(kwargs)
        is_key = sequence in (
            "<Left>", "<Right>", "<Up>", "<Down>", "<space>",
            "<Return>", "<KP_Enter>", "<Home>", "<End>", "<Prior>",
            "<Next>", "<F10>", "<Shift-F10>", "<Menu>",
        ) or sequence.startswith("<Key")
        if is_key:
            self._grid.focus(
                self._index, force=True, ensure_visible=False,
            )
        else:
            local_x = int(values.get("x", self.winfo_width() // 2))
            local_y = int(values.get("y", self.winfo_height() // 2))
            canvas_x = self.winfo_x() + local_x
            canvas_y = self.winfo_y() + local_y
            values["x"] = canvas_x
            values["y"] = canvas_y
            values.setdefault(
                "rootx", self._grid.canvas.winfo_rootx() + canvas_x,
            )
            values.setdefault(
                "rooty", self._grid.canvas.winfo_rooty() + canvas_y,
            )
        self._grid.canvas.event_generate(sequence, **values)

    def winfo_x(self) -> int:
        """Return the current tile rim's x coordinate in Canvas content space.

        Returns:
            int: Horizontal hitbox coordinate in scrollable Canvas pixels.
        """
        self._require_current()
        x, _y, _width, _height = self._grid._actual_bounds(self._index)
        return x

    def winfo_y(self) -> int:
        """Return the current tile rim's viewport-relative y coordinate.

        Returns:
            int: Vertical hitbox coordinate after the current Canvas scroll offset.
        """
        self._require_current()
        _x, y, _width, _height = self._grid._actual_bounds(self._index)
        return y - int(self._grid.canvas.canvasy(0))

    def winfo_width(self) -> int:
        """Return the current image or placeholder rim width.

        Returns:
            int: Actual visible width including the theme border.
        """
        self._require_current()
        _x, _y, width, _height = self._grid._actual_bounds(self._index)
        return width

    def winfo_height(self) -> int:
        """Return the current image or placeholder rim height.

        Returns:
            int: Actual visible height including the theme border.
        """
        self._require_current()
        _x, _y, _width, height = self._grid._actual_bounds(self._index)
        return height

    def winfo_rootx(self) -> int:
        """Return the tile's actual root-window x coordinate.

        Returns:
            int: Root-relative Tk pixel coordinate.
        """
        return self._grid.canvas.winfo_rootx() + self.winfo_x()

    def winfo_rooty(self) -> int:
        """Return the tile's actual root-window y coordinate.

        Returns:
            int: Root-relative Tk pixel coordinate.
        """
        return self._grid.canvas.winfo_rooty() + self.winfo_y()

    def winfo_exists(self) -> bool:
        """Report whether this exact proxy still belongs to the live gallery.

        Returns:
            bool: True only for the original current catalogue identity.
        """
        return (
            not self._grid._closed
            and self._grid._record(
                self._index, self._photo_id, self._epoch,
            ) is not None
            and self._grid._proxies.get(self._index) is self
        )

    def destroy(self) -> None:
        """Invalidate this tile adapter and release its current active image.

        Returns:
            None: Other logical indices and shared Canvas bindings remain intact.
        """
        self._grid._detach_proxy(self)

    def _require_current(self) -> None:
        """Reject stale geometry queries after refresh or gallery replacement.

        Returns:
            None: Current proxies continue to expose their real hitbox geometry.

        Raises:
            tk.TclError: The proxy identity no longer belongs to the live catalog.
        """
        if not self.winfo_exists():
            raise tk.TclError("tile proxy belongs to an expired catalogue")


def _rim_coordinates(
    x: int,
    y: int,
    width: int,
    height: int,
    radius: int,
) -> tuple[int, ...]:
    """Return a compact closed path for one rounded selection rim.

    Args:
        x (int): Left rim coordinate.
        y (int): Top rim coordinate.
        width (int): Rim width in Canvas pixels.
        height (int): Rim height in Canvas pixels.
        radius (int): Central rounded-corner radius.

    Returns:
        tuple[int, ...]: Polygon coordinates accepted by Canvas.
    """
    r = max(0, min(radius, width // 2, height // 2))
    return (
        x + r, y, x + width - r, y, x + width, y + r,
        x + width, y + height - r, x + width - r, y + height,
        x + r, y + height, x, y + height - r,
        x, y + r, x + r, y,
    )


def _is_tile_image(value: CanvasOptionValue) -> bool:
    """Check whether a value has the minimal live Tk image interface.

    Args:
        value (CanvasOptionValue): Candidate image option.

    Returns:
        bool: True for image handles accepted by Canvas.
    """
    if value is None or value == "":
        return False
    return (
        callable(getattr(value, "width", None))
        and callable(getattr(value, "height", None))
        and callable(getattr(value, "__str__", None))
    )
