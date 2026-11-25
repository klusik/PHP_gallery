# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/tests/test_canvas_gallery_grid.py
# Module Type: Cross-Platform Tk Regression Test
# Purpose: Verify bounded Canvas gallery tile pooling and proxy behavior.
# Responsibilities:
#   - Exercise real Tk Canvas geometry for empty through 5,000-photo catalogues.
#   - Verify scrolling, resizing, logical identity and event routing after reuse.
#   - Capture optional Windows screenshots at both supported viewport widths.
#   - Verify teardown releases active Canvas items.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)
"""Cross-platform real-Tk tests for the opt-in Canvas gallery tile pool."""

import os
import sys
import time
import unittest
from pathlib import Path
from unittest import mock

try:
    import tkinter as tk
    from tkinter import TclError, ttk
except ModuleNotFoundError as exc:
    if exc.name != 'tkinter':
        raise
    tk = None
    ttk = None
    TclError = RuntimeError

try:
    from PIL import Image, ImageGrab
except ModuleNotFoundError as exc:
    if exc.name != 'PIL':
        raise
    Image = None
    ImageGrab = None

WINAPP_DIR = Path(__file__).resolve().parents[1]
if str(WINAPP_DIR) not in sys.path:
    sys.path.insert(0, str(WINAPP_DIR))

if tk is not None:
    from uploader import ui_theme
    from uploader.canvas_gallery_grid import CanvasGalleryGrid, CanvasTileProxy
else:
    ui_theme = None
    CanvasGalleryGrid = object
    CanvasTileProxy = object


# Purpose: Exercise empty, singleton, primary and stress catalogue sizes.
# Type: tuple[int, ...]. Units: photos. Scope: Canvas tile-pool geometry tests.
# Consumers: CanvasGalleryGridTests.test_bounded_active_pool_for_catalogue_sizes.
# Rationale: 100/500/2,000 are primary sizes; 5,000 is a required cheap
# metadata/pool regression and remains separate from performance stress timing.
TEST_CATALOGUE_SIZES = (0, 1, 100, 500, 2000, 5000)

# Purpose: Keep the Canvas fixture within the supported measured viewport widths.
# Type: tuple[int, int]. Units: Tk pixels. Scope: real Tk grid test viewport.
# Consumers: CanvasGridTestCase.setUp and resize regression.
# Rationale: Exercise both supported 640 and 960 pixel widths.
TEST_VIEWPORT_WIDTHS = (640, 960)

# Purpose: Separate adjacent Canvas tiles at the tested density.
# Type: int. Units: Tk pixels. Scope: helper geometry regression fixture.
# Consumers: CanvasGridTestCase.setUp.
# Rationale: Eight pixels leave a clear interactive gap between tiles.
TEST_CANVAS_GUTTER = 8

# Purpose: Keep Canvas geometry stable across catalogue-size cases.
# Type: int. Units: Tk pixels. Scope: visible pool and scrolling regressions.
# Consumers: CanvasGridTestCase._settle.
# Rationale: 480 pixels admits multiple rows and permits deterministic scrolling.
TEST_CANVAS_HEIGHT = 480

# Purpose: Give synthetic photo IDs a stable nonzero range.
# Type: int. Units: photo identifier offset. Scope: helper metadata fixtures.
# Consumers: CanvasGridTestCase._append_catalogue.
# Rationale: Distinct IDs make accidental index/ID confusion observable.
TEST_PHOTO_ID_OFFSET = 100000

# Purpose: Exercise proxy recycling across a multi-viewport catalogue.
# Type: int. Units: photos. Scope: scroll and resize regressions.
# Consumers: CanvasGalleryGridTests.test_scroll_recycles_proxies_without_stale_logical_identity
# and test_resize_preserves_focused_logical_index.
# Rationale: 500 items provide enough rows to force meaningful recycling.
TEST_SCROLL_CATALOGUE_SIZE = 500

# Purpose: Exercise click and keyboard routing on a primary-size catalogue.
# Type: int. Units: photos. Scope: input proxy regressions.
# Consumers: CanvasGalleryGridTests click, keyboard and teardown cases.
# Rationale: The primary 100-photo extent already exceeds the viewport.
TEST_INPUT_CATALOGUE_SIZE = 100

# Purpose: Use a fixed thumbnail geometry for deterministic visible tile counts.
# Type: int. Units: Tk pixels. Scope: metadata-only Canvas pool fixture.
# Consumers: CanvasGridTestCase._append_catalogue.
# Rationale: 96 px tiles remain readable and fit multiple columns at both widths.
TEST_TILE_SIZE = 96

# Purpose: Pass image width and height as the helper's concrete geometry shape.
# Type: tuple[int, int]. Units: Tk pixels. Scope: Canvas layout calls.
# Consumers: CanvasGridTestCase._settle.
# Rationale: The viewer uses square source renditions in this fixture.
TEST_TILE_SIZE_PAIR = (TEST_TILE_SIZE, TEST_TILE_SIZE)

# Purpose: Keep real Tk image payloads minimal in stale-reference regressions.
# Type: int. Units: pixels per side. Scope: Canvas image item fixture.
# Consumers: CanvasGalleryGridTests.test_recycled_images_clear_and_stale_proxies_cannot_repaint
# Rationale: A 2x2 image exercises Tcl image ownership without large payloads.
TEST_IMAGE_DIMENSION = 2

# Purpose: Give blank image slots a visible, nonempty native glyph.
# Type: str. Units: Unicode glyph. Scope: Canvas helper fixture.
# Consumers: CanvasGridTestCase.setUp.
# Rationale: The production helper rejects an empty placeholder.
TEST_PLACEHOLDER_GLYPH = '□'

# Purpose: Identify the macOS Command modifier in generated Tk input.
# Type: int. Units: Tk state bit. Scope: Canvas click modifier regression.
# Consumers: CanvasGalleryGridTests.test_proxy_routes_click_modifiers_to_logical_selection_callbacks.
# Rationale: Tk CommandMask is bit 3 on macOS.
TEST_MAC_COMMAND_MODIFIER_MASK = 0x0008

# Purpose: Scroll to an interior row before testing responsive anchor retention.
# Type: float. Units: scroll fraction. Scope: resize/anchor regression.
# Consumers: CanvasGalleryGridTests.test_resize_preserves_focused_logical_index.
# Rationale: The halfway point exercises both preceding and following rows.
TEST_MIDPOINT_SCROLL_FRACTION = 0.5

# Purpose: Match the existing shared tooltip's scheduled visibility delay.
# Type: int. Units: milliseconds. Scope: real pointer hover regressions.
# Consumers: CanvasGridTestCase tooltip wait helper.
# Rationale: Use the theme-owned delay; fallback is used only by skipped Tk tests.
TEST_TOOLTIP_DELAY_MS = (
    ui_theme._TOOLTIP_DELAY_MS if ui_theme is not None else 550
)

# Purpose: Allow a mapped tooltip time to settle after its theme-owned delay.
# Type: int. Units: milliseconds. Scope: hover and live-text test waits.
# Consumers: CanvasGridTestCase tooltip wait helpers.
# Rationale: One second covers hosted event scheduling without long test stalls.
TEST_TOOLTIP_SETTLE_MARGIN_MS = 1000
TEST_TOOLTIP_WAIT_MS = (
    TEST_TOOLTIP_DELAY_MS + TEST_TOOLTIP_SETTLE_MARGIN_MS
)

# Purpose: Bound active-slot samples included in tooltip timeout diagnostics.
# Type: int. Units: indices per edge. Scope: failure-only hover context.
# Consumers: CanvasGridTestCase._wait_for_tooltip_text.
# Rationale: Four entries from each end preserve bounded failure messages.
TEST_TOOLTIP_DIAGNOSTIC_INDICES = 4

# Purpose: Poll Tk for asynchronous tooltip mapping at a bounded cadence.
# Type: int. Units: milliseconds. Scope: one real-Tk tooltip wait.
# Consumers: CanvasGridTestCase tooltip wait helpers.
# Rationale: Ten milliseconds services the scheduled UI without busy spinning.
TEST_TOOLTIP_POLL_MS = 10

# Purpose: Allow small style and border differences in tile-adjacent placement.
# Type: int. Units: Tk pixels. Scope: tooltip-to-photo adjacency assertion.
# Consumers: test_photo_hover_shows_live_tooltip_and_hides_in_gutter.
# Rationale: The tooltip anchor is exact; the margin permits theme surface insets.
TEST_TOOLTIP_ADJACENCY_TOLERANCE = 24

# Purpose: Warp the real pointer safely inside the unrelated toolbar control.
# Type: tuple[int, int]. Units: Tk pixels. Scope: toolbar trigger regression.
# Consumers: CanvasGalleryGridTests.test_hover_lifecycle_preserves_other_tooltips.
# Rationale: 16 px is inset from the control's edges.
TEST_TOOLBAR_HOVER_POSITION = (16, 16)

# Purpose: Scroll the Canvas to its final logical viewport.
# Type: float. Units: scroll fraction. Scope: recycle and tooltip regressions.
# Consumers: CanvasGalleryGridTests scroll and hover lifecycle cases.
# Rationale: 1.0 selects the bottom end of the scrollable catalogue.
TEST_BOTTOM_SCROLL_FRACTION = 1.0

# Purpose: Place the unrelated toolbar control below the gallery Canvas.
# Type: tuple[int, int, int, int]. Units: Tk pixels. Scope: trigger-filter test.
# Consumers: CanvasGalleryGridTests.test_hover_lifecycle_preserves_other_tooltips.
# Rationale: Keeps its hover target mapped and separate from the Canvas hitboxes.
TEST_TOOLBAR_HELP_BOUNDS = (8, 600, 200, 32)

# Purpose: Keep the unrelated trigger description distinct from photo titles.
# Type: str. Units: tooltip text. Scope: shared-surface trigger isolation test.
# Consumers: CanvasGalleryGridTests.test_hover_lifecycle_preserves_other_tooltips.
# Rationale: The visible label identifies which trigger owns the shared help.
TEST_TOOLBAR_HELP_TEXT = 'Toolbar help remains available'

# Purpose: Describe a real unavailable rendition state through the live tooltip.
# Type: str. Units: tooltip text. Scope: live Canvas tooltip update regression.
# Consumers: test_photo_hover_shows_live_tooltip_and_hides_in_gutter.
# Rationale: A changed message proves an active proxy refreshes visible help.
TEST_UNAVAILABLE_TOOLTIP_TEXT = 'Thumbnail unavailable'

# Purpose: Store optional native screenshots under the existing audit artifact.
# Type: tuple[str, ...]. Units: repository-relative path components.
# Scope: Windows-only Canvas render evidence. Consumers: screenshot test.
# Rationale: The existing workflow uploads cache/test-audit/** without new wiring.
TEST_VISUAL_EVIDENCE_PATH = (
    'cache', 'test-audit', 'canvas-399-visuals',
)

# Purpose: Name the two supported viewport captures consistently.
# Type: tuple[str, str]. Units: PNG filenames. Scope: Windows-only visual evidence.
# Consumers: test_windows_canvas_render_evidence_at_supported_viewports.
# Rationale: Filenames record the exact requested Canvas viewport width.
TEST_VISUAL_CAPTURE_NAMES = (
    'canvas-399-640.png',
    'canvas-399-960.png',
)

# Purpose: Identify the solid photo color used to verify captured rendering.
# Type: tuple[int, int, int]. Units: 8-bit RGB channels. Scope: Windows screenshot test.
# Consumers: test_windows_canvas_render_evidence_at_supported_viewports.
# Rationale: A known source color distinguishes rendered photo content from a blank capture.
TEST_RENDERED_PHOTO_RGB = (74, 144, 226)

# Purpose: Keep the fixture Canvas inside the visible desktop work area.
# Type: str. Units: Tk geometry string. Scope: real Tk test root geometry.
# Consumers: CanvasGridTestCase.setUp.
# Rationale: 960 by 680 leaves room for native window chrome on hosted desktops.
TEST_ROOT_GEOMETRY = '960x680+0+0'

# Purpose: Identify the synthetic gallery consistently across proxy callbacks.
# Type: int. Units: gallery identifier. Scope: one isolated Canvas helper.
# Consumers: CanvasGridTestCase.setUp.
# Rationale: The helper requires a stable owner ID; no network/session is used.
TEST_GALLERY_ID = 399


@unittest.skipIf(tk is None, 'Tkinter is unavailable on this runtime.')
class CanvasGridTestCase(unittest.TestCase):
    """Provide a mapped cross-platform Tk root and Canvas helper."""

    def setUp(self) -> None:
        """Create a real Canvas helper with opt-in enabled before construction.

        Returns:
            None: The mapped Tk root and Canvas grid are ready for each test.
        """
        self._environment = mock.patch.dict(
            os.environ, {'PHP_GALLERY_CANVAS_GRID': '1'},
        )
        self._environment.start()
        self.addCleanup(self._environment.stop)
        try:
            self.root = tk.Tk()
            self.addCleanup(self._destroy_root)
            self.root.geometry(TEST_ROOT_GEOMETRY)
            self.canvas = tk.Canvas(
                self.root, width=TEST_VIEWPORT_WIDTHS[0], height=TEST_CANVAS_HEIGHT,
                highlightthickness=0,
            )
            self.canvas.pack(anchor='nw')
            self.root.update()
        except TclError as exc:
            self.skipTest(f'Real Tk display is unavailable: {exc}')
        self.callbacks: list[tuple[str, int]] = []
        self.grid = CanvasGalleryGrid(
            self.canvas,
            gallery_id=TEST_GALLERY_ID,
            gutter=TEST_CANVAS_GUTTER,
            placeholder=TEST_PLACEHOLDER_GLYPH,
            theme=ui_theme.gallery_grid_theme(self.canvas),
            select=lambda index: self._record('select', index),
            range_select=lambda index: self._record('range', index),
            toggle=lambda index: self._record('toggle', index),
            open_photo=lambda index: self._record_notice('open', index),
            key=lambda index, event: self._record_key(index, event),
            context=lambda index, event: self._record_context(index),
            viewport=lambda: self._record_notice('viewport', -1),
        )
        self.addCleanup(self.grid.close)

    def _destroy_root(self) -> None:
        """Destroy the Tk root when it still exists.

        Returns:
            None: Tk resources are released on the creating thread.
        """
        try:
            if self.root.winfo_exists():
                self.root.destroy()
        except TclError:
            pass

    def _record(self, name: str, index: int) -> bool:
        """Record a helper callback and accept the interaction.

        Args:
            name (str): Callback category emitted by the grid helper.
            index (int): Current logical catalogue index.

        Returns:
            bool: The synthetic viewer accepts the callback.
        """
        self.callbacks.append((name, index))
        return True

    def _record_notice(self, name: str, index: int) -> None:
        """Record a notification callback without a selection result.

        Args:
            name (str): Notification category emitted by the grid helper.
            index (int): Current logical catalogue index or sentinel.

        Returns:
            None: The fixture retains the notification for assertions.
        """
        self.callbacks.append((name, index))

    def _record_context(self, index: int | None) -> str:
        """Record a context request and stop its Tk binding chain.

        Args:
            index (int | None): Photo index or empty-grid context sentinel.

        Returns:
            str: Stop propagation after recording the context target.
        """
        self.callbacks.append(('context', -1 if index is None else index))
        return 'break'

    def _record_key(self, index: int, event: 'tk.Event') -> str:
        """Record a keyboard event against its logical tile identity.

        Args:
            index (int): Current logical catalogue index.
            event (tk.Event): Tk key event forwarded by the tile proxy.

        Returns:
            str: Stop Tk propagation after the fixture observes the event.
        """
        self.callbacks.append((f'key:{event.keysym}', index))
        return 'break'

    def _append_catalogue(self, count: int) -> tuple[CanvasTileProxy, ...]:
        """Append deterministic metadata entries without loading image payloads.

        Args:
            count (int): Number of photos to expose to the grid.

        Returns:
            tuple[CanvasTileProxy, ...]: Proxies in catalogue index order.
        """
        return tuple(
            self.grid.append(
                index, TEST_PHOTO_ID_OFFSET + index, f'Photo {index}',
            )
            for index in range(count)
        )

    def _widget_descendant_count(self, parent: 'tk.Misc') -> int:
        """Count Tk widgets owned below the fixture root.

        Args:
            parent (tk.Misc): Widget whose child subtree is measured.

        Returns:
            int: Number of descendants, excluding the supplied parent.
        """
        children = parent.winfo_children()
        return len(children) + sum(
            self._widget_descendant_count(child) for child in children
        )

    def _active_tooltip_label(self) -> 'ttk.Label | None':
        """Return the currently placed shared inline tooltip label.

        Returns:
            ttk.Label | None: Mapped themed label, or None when help is hidden.
        """
        for widget in self.root.winfo_children():
            if (
                isinstance(widget, ttk.Label)
                and widget.cget('style') == 'Inline.Tooltip.TLabel'
                and widget.winfo_manager() == 'place'
            ):
                return widget
        return None

    def _active_tooltip_text(self) -> str | None:
        """Read visible tooltip text from its text or StringVar option.

        Returns:
            str | None: Rendered message, or None while the tooltip is hidden.
        """
        label = self._active_tooltip_label()
        if label is None:
            return None
        variable = str(label.cget('textvariable'))
        return (
            str(self.root.getvar(variable))
            if variable else str(label.cget('text'))
        )

    def _wait_for_tooltip_text(self, expected: str) -> 'ttk.Label':
        """Pump Tk until the mapped tooltip shows the requested live message.

        Args:
            expected (str): Visible text expected from the current trigger.

        Returns:
            ttk.Label: Mapped label carrying the expected tooltip message.
        """
        deadline = time.monotonic() + TEST_TOOLTIP_WAIT_MS / 1000
        while time.monotonic() < deadline:
            self.root.update()
            label = self._active_tooltip_label()
            if label is not None and self._active_tooltip_text() == expected:
                return label
            time.sleep(TEST_TOOLTIP_POLL_MS / 1000)
        pointer = self.canvas.winfo_pointerxy()
        target = self.root.winfo_containing(*pointer)
        canvas_x = self.canvas.canvasx(pointer[0] - self.canvas.winfo_rootx())
        canvas_y = self.canvas.canvasy(pointer[1] - self.canvas.winfo_rooty())
        hit_index = self.grid._hit(canvas_x, canvas_y)
        state = getattr(self.root, '_inline_tooltip_state', None)
        active = self.grid.active_indices
        message = self.grid._tooltip_message.get()
        self.fail(
            f'Tooltip missing expected={expected!r}; '
            f'actual={self._active_tooltip_text()!r}; '
            f'hover={self.grid._hover_identity!r}; hit={hit_index!r}; '
            f'pointer={pointer!r}; target={str(target)!r}; '
            f'active={active[:TEST_TOOLTIP_DIAGNOSTIC_INDICES]!r}..{active[-TEST_TOOLTIP_DIAGNOSTIC_INDICES:]!r}/{len(active)}; '
            f'text={message!r}; trigger={str(getattr(state, "trigger", None))!r}; '
            f'timer={bool(getattr(state, "timer", None))}; '
            f'visible={getattr(state, "label", None) is not None}',
        )

    def _wait_for_tooltip_hidden(self) -> None:
        """Pump Tk until no shared inline tooltip remains placed.

        Returns:
            None: The owned trigger's tooltip has been unmapped.
        """
        deadline = time.monotonic() + TEST_TOOLTIP_WAIT_MS / 1000
        while time.monotonic() < deadline:
            self.root.update()
            if self._active_tooltip_label() is None:
                return
            time.sleep(TEST_TOOLTIP_POLL_MS / 1000)
        self.fail('Inline tooltip stayed mapped after its hover owner retired.')

    def _hover_proxy(self, proxy: CanvasTileProxy) -> None:
        """Move the real pointer over a proxy through a mapped Canvas Motion.

        Args:
            proxy (CanvasTileProxy): Current visible photo receiving the pointer.

        Returns:
            None: Tk processes the generated motion and pointer warp.
        """
        proxy.event_generate('<Motion>', warp=True)
        self.root.update()

    def _canvas_image_names(self) -> tuple[str, ...]:
        """Read image handles from real Canvas image items only.

        Returns:
            tuple[str, ...]: Tcl image names attached to current Canvas items.
        """
        return tuple(
            self.canvas.itemcget(item, 'image')
            for item in self.canvas.find_all()
            if self.canvas.type(item) == 'image'
        )

    def _settle(self, width: int, height: int = TEST_CANVAS_HEIGHT) -> tuple[int, ...]:
        """Lay out the grid and reconcile exactly its visible indices.

        Args:
            width (int): Canvas width in Tk pixels.
            height (int): Canvas height in Tk pixels.

        Returns:
            tuple[int, ...]: The helper's visible logical indices.
        """
        self.canvas.configure(width=width, height=height)
        self.root.update()
        self.grid.layout(width, height, TEST_TILE_SIZE_PAIR)
        visible = self.grid.visible_indices()
        self.grid.reconcile(visible)
        self.root.update()
        return visible


class CanvasGalleryGridTests(CanvasGridTestCase):
    """Verify bounded pooling, identity, input, tooltip and teardown behavior."""

    def test_photo_hover_shows_live_tooltip_and_hides_in_gutter(self) -> None:
        """Show live photo help near its tile and dismiss it over the gutter.

        Returns:
            None: Pointer hover shows and updates help; a non-photo gap hides it.
        """
        proxies = self._append_catalogue(TEST_INPUT_CATALOGUE_SIZE)
        self._settle(TEST_VIEWPORT_WIDTHS[0])
        proxy = proxies[0]
        initial_text = proxy.tooltip.get()
        self._hover_proxy(proxy)
        label = self._wait_for_tooltip_text(initial_text)
        tile_left = proxy.winfo_rootx()
        tile_top = proxy.winfo_rooty()
        tile_right = tile_left + proxy.winfo_width()
        tile_bottom = tile_top + proxy.winfo_height()
        label_left = label.winfo_rootx()
        label_top = label.winfo_rooty()
        label_right = label_left + label.winfo_width()
        label_bottom = label_top + label.winfo_height()
        vertical_gap = min(
            abs(label_top - tile_bottom),
            abs(tile_top - label_bottom),
        )
        self.assertLessEqual(
            vertical_gap, TEST_TOOLTIP_ADJACENCY_TOLERANCE,
        )
        self.assertGreater(label_right, tile_left)
        self.assertLessEqual(label_left, tile_right)

        proxy.tooltip.set(TEST_UNAVAILABLE_TOOLTIP_TEXT)
        self._wait_for_tooltip_text(TEST_UNAVAILABLE_TOOLTIP_TEXT)
        gap_x = (
            proxy.winfo_x() + proxy.winfo_width()
            + proxies[1].winfo_x()
        ) // 2
        gap_y = proxy.winfo_y() + proxy.winfo_height() // 2
        self.canvas.event_generate(
            '<Motion>', x=gap_x, y=gap_y, warp=True,
        )
        self._wait_for_tooltip_hidden()

    def test_hover_lifecycle_preserves_other_tooltips(self) -> None:
        """Cancel stale Canvas help across cover, scroll, reset and close.

        Returns:
            None: Canvas cleanup is scoped to its trigger and leaves toolbar help.
        """
        proxies = self._append_catalogue(TEST_SCROLL_CATALOGUE_SIZE)
        self._settle(TEST_VIEWPORT_WIDTHS[0])
        toolbar = ttk.Button(self.root, text='Toolbar')
        toolbar.place(
            x=TEST_TOOLBAR_HELP_BOUNDS[0],
            y=TEST_TOOLBAR_HELP_BOUNDS[1],
            width=TEST_TOOLBAR_HELP_BOUNDS[2],
            height=TEST_TOOLBAR_HELP_BOUNDS[3],
        )
        ui_theme.attach_inline_tooltip(
            toolbar, TEST_TOOLBAR_HELP_TEXT, self.root,
        )
        self.root.update()

        first_proxy = proxies[0]
        self._hover_proxy(first_proxy)
        self._wait_for_tooltip_text(first_proxy.tooltip.get())
        cover = ttk.Label(self.root, text='Cover')
        cover.place(
            x=first_proxy.winfo_rootx() - self.root.winfo_rootx(),
            y=first_proxy.winfo_rooty() - self.root.winfo_rooty(),
            width=first_proxy.winfo_width(),
            height=first_proxy.winfo_height(),
        )
        cover.lift()
        self.root.update()
        self._hover_proxy(first_proxy)
        self._wait_for_tooltip_hidden()
        cover.destroy()

        first_proxy = proxies[0]
        self._hover_proxy(first_proxy)
        self._wait_for_tooltip_text(first_proxy.tooltip.get())
        self.canvas.yview_moveto(TEST_BOTTOM_SCROLL_FRACTION)
        self.root.update()
        self._settle(TEST_VIEWPORT_WIDTHS[0])
        self._wait_for_tooltip_hidden()

        last_proxy = proxies[-1]
        self._hover_proxy(last_proxy)
        self._wait_for_tooltip_text(last_proxy.tooltip.get())
        self.grid.reset_catalogue()
        self._wait_for_tooltip_hidden()

        self._append_catalogue(1)
        self._settle(TEST_VIEWPORT_WIDTHS[0])
        toolbar.event_generate(
            '<Motion>', x=TEST_TOOLBAR_HOVER_POSITION[0],
            y=TEST_TOOLBAR_HOVER_POSITION[1], warp=True,
        )
        self._wait_for_tooltip_text(TEST_TOOLBAR_HELP_TEXT)
        self.grid.reset_catalogue()
        self.assertEqual(
            TEST_TOOLBAR_HELP_TEXT, self._active_tooltip_text(),
        )

        replacement = self._append_catalogue(1)[0]
        self._settle(TEST_VIEWPORT_WIDTHS[0])
        self._hover_proxy(replacement)
        self._wait_for_tooltip_text(replacement.tooltip.get())
        self.grid.close()
        self._wait_for_tooltip_hidden()

    @unittest.skipUnless(
        sys.platform == 'win32' and ImageGrab is not None,
        'ImageGrab render evidence requires a Windows desktop and Pillow.',
    )
    def test_windows_canvas_render_evidence_at_supported_viewports(self) -> None:
        """Save real selected-tile Canvas captures for 640 and 960 pixel widths.

        Returns:
            None: Supported captures are retained in the existing audit artifact;
                unsupported ImageGrab/display geometry is reported as skipped.
        """
        proxies = self._append_catalogue(TEST_INPUT_CATALOGUE_SIZE)
        self._settle(TEST_VIEWPORT_WIDTHS[0])
        proxy = proxies[0]
        photo = tk.PhotoImage(
            master=self.root, width=TEST_TILE_SIZE, height=TEST_TILE_SIZE,
        )
        photo.put('#4a90e2', to=(0, 0, TEST_TILE_SIZE, TEST_TILE_SIZE))
        proxy.configure(image=photo)
        proxy.state(['selected'])
        self.root.update()

        if not self.root.winfo_ismapped() or not self.canvas.winfo_ismapped():
            self.skipTest('Tk root or Canvas is not mapped on the hosted desktop.')

        captures: list[tuple[str, 'Image.Image']] = []
        for width, filename in zip(
            TEST_VIEWPORT_WIDTHS, TEST_VISUAL_CAPTURE_NAMES,
        ):
            self._settle(width)
            self.root.update_idletasks()
            self.root.update()
            capture_width = self.canvas.winfo_width()
            capture_height = self.canvas.winfo_height()
            self.assertEqual(
                width, capture_width,
                'Captured Canvas width must match its requested viewport label.',
            )
            image_items = tuple(
                item for item in self.canvas.find_all()
                if self.canvas.type(item) == 'image'
            )
            self.assertTrue(image_items, 'The selected photo image must be on Canvas.')
            image_coords = self.canvas.coords(image_items[0])
            self.assertGreaterEqual(len(image_coords), 2)
            image_center_x = int(round(image_coords[0]))
            image_center_y = int(round(image_coords[1]))
            bbox = (
                self.canvas.winfo_rootx(),
                self.canvas.winfo_rooty(),
                self.canvas.winfo_rootx() + capture_width,
                self.canvas.winfo_rooty() + capture_height,
            )
            try:
                image = ImageGrab.grab(bbox=bbox)
            except OSError as exc:
                for captured_name, captured_image in captures:
                    captured_image.close()
                self.skipTest(
                    f'Windows ImageGrab is unavailable for Canvas render evidence: {exc}',
                )
            if image.size != (capture_width, capture_height):
                image.close()
                for captured_name, captured_image in captures:
                    captured_image.close()
                self.skipTest(
                    'The desktop clipped the requested Canvas capture bounds.',
                )
            rendered_rgb = image.convert('RGB')
            try:
                actual_rgb = rendered_rgb.getpixel((image_center_x, image_center_y))
            finally:
                rendered_rgb.close()
            self.assertEqual(
                TEST_RENDERED_PHOTO_RGB, actual_rgb,
                'Captured tile center must show the known PhotoImage color.',
            )
            captures.append((filename, image))

        output_directory = (
            Path(__file__).resolve().parents[2]
            / Path(*TEST_VISUAL_EVIDENCE_PATH)
        )
        output_directory.mkdir(parents=True, exist_ok=True)
        try:
            for filename, image in captures:
                image.save(output_directory / filename, format='PNG')
        finally:
            for captured_name, captured_image in captures:
                captured_image.close()

    def test_identical_reconcile_skips_canvas_updates(self) -> None:
        """Skip repeated remap geometry and still render a new selection.

        Returns:
            None: Stable layout and admission are inert. Selecting a tile
                still updates its rim.
        """
        proxies = self._append_catalogue(TEST_INPUT_CATALOGUE_SIZE)
        visible = self._settle(TEST_VIEWPORT_WIDTHS[0])
        self.assertTrue(visible)
        rim_items = tuple(
            item for item in self.canvas.find_all()
            if self.canvas.type(item) == 'polygon'
        )
        self.assertTrue(rim_items)
        self.assertTrue(all(
            self.canvas.itemcget(item, 'state') == 'hidden'
            for item in rim_items
        ))
        with (
            mock.patch.object(
                self.canvas, 'coords', wraps=self.canvas.coords,
            ) as coords_spy,
            mock.patch.object(
                self.canvas, 'itemconfigure',
                wraps=self.canvas.itemconfigure,
            ) as configure_spy,
            mock.patch.object(
                self.canvas, 'configure', wraps=self.canvas.configure,
            ) as scrollregion_spy,
        ):
            self.grid.layout(TEST_VIEWPORT_WIDTHS[0], TEST_CANVAS_HEIGHT, TEST_TILE_SIZE_PAIR)
            self.grid.reconcile(visible)
            scrollregion_spy.assert_not_called()
            self.assertEqual(0, coords_spy.call_count)
            self.assertEqual(0, configure_spy.call_count)

            proxies[visible[0]].state(['selected'])
            self.assertEqual(0, coords_spy.call_count)
            self.assertGreater(configure_spy.call_count, 0)

        updated_rim_states = tuple(
            self.canvas.itemcget(item, 'state') for item in rim_items
        )
        self.assertEqual(
            1, sum(state == 'normal' for state in updated_rim_states),
        )

    def test_bounded_active_pool_for_catalogue_sizes(self) -> None:
        """Keep active Canvas tiles limited to the current viewport at all sizes.

        Returns:
            None: Empty through 5,000-photo catalogues create only visible items.
        """
        baseline_item_count: int | None = None
        baseline_widget_count: int | None = None
        baseline_variable_count: int | None = None
        for count in TEST_CATALOGUE_SIZES:
            self.grid.reset_catalogue()
            self._append_catalogue(count)
            visible = self._settle(TEST_VIEWPORT_WIDTHS[0])
            self.assertEqual(tuple(visible), self.grid.active_indices)
            self.assertLessEqual(len(self.grid.active_indices), count)
            canvas_item_count = len(self.canvas.find_all())
            widget_count = self._widget_descendant_count(self.root)
            variable_count = len(self.root.tk.call('info', 'vars'))
            self.assertEqual(canvas_item_count, self.grid.canvas_items_count)
            if count == TEST_INPUT_CATALOGUE_SIZE:
                baseline_item_count = canvas_item_count
                baseline_widget_count = widget_count
                baseline_variable_count = variable_count
            elif count > TEST_INPUT_CATALOGUE_SIZE:
                self.assertIsNotNone(baseline_item_count)
                self.assertIsNotNone(baseline_widget_count)
                self.assertIsNotNone(baseline_variable_count)
                self.assertLessEqual(canvas_item_count, baseline_item_count)
                self.assertLessEqual(widget_count, baseline_widget_count)
                self.assertLessEqual(variable_count, baseline_variable_count)

    def test_scroll_recycles_proxies_without_stale_logical_identity(self) -> None:
        """Recycle visible proxies while scrolling through a large catalogue.

        Returns:
            None: Reused proxy events resolve to their newly assigned photo index.
        """
        proxies = self._append_catalogue(TEST_SCROLL_CATALOGUE_SIZE)
        first_visible = self._settle(TEST_VIEWPORT_WIDTHS[0])
        self.assertTrue(first_visible)
        self.assertIn(0, first_visible)
        initial_reuse_count = self.grid.item_reuse_count
        self.canvas.yview_moveto(TEST_BOTTOM_SCROLL_FRACTION)
        self.root.update()
        last_visible = self._settle(TEST_VIEWPORT_WIDTHS[0])
        self.assertTrue(last_visible)
        self.assertNotEqual(first_visible, last_visible)
        self.assertGreater(self.grid.item_reuse_count, initial_reuse_count)
        self.assertEqual(tuple(last_visible), self.grid.active_indices)
        self.assertNotIn(0, self.grid.active_indices)
        self.callbacks.clear()
        proxies[last_visible[-1]].event_generate('<Button-1>')
        self.root.update()
        self.assertIn(('select', last_visible[-1]), self.callbacks)

    def test_resize_preserves_focused_logical_index(self) -> None:
        """Retain focused identity while the viewport changes column count.

        Returns:
            None: Both supported widths lay out without losing the focused tile.
        """
        self._append_catalogue(TEST_SCROLL_CATALOGUE_SIZE)
        self._settle(TEST_VIEWPORT_WIDTHS[0])
        self.canvas.yview_moveto(TEST_MIDPOINT_SCROLL_FRACTION)
        self.root.update()
        narrow_visible = self._settle(TEST_VIEWPORT_WIDTHS[0])
        self.assertTrue(narrow_visible)
        anchor_index = narrow_visible[0]
        self.grid.focus(anchor_index, force=True)
        self.root.update()
        wide_visible = self._settle(TEST_VIEWPORT_WIDTHS[1])
        self.assertIn(anchor_index, wide_visible)
        self.assertEqual(anchor_index, self.grid.focused_index)
        self.assertGreaterEqual(self.canvas.winfo_width(), TEST_VIEWPORT_WIDTHS[1])
        self.assertEqual(tuple(wide_visible), self.grid.active_indices)
        narrowed_visible = self._settle(TEST_VIEWPORT_WIDTHS[0])
        self.assertIn(anchor_index, narrowed_visible)
        self.assertEqual(tuple(narrowed_visible), self.grid.active_indices)

    def test_proxy_routes_click_modifiers_to_logical_selection_callbacks(self) -> None:
        """Route ordinary, range and toggle clicks using current logical indices.

        Returns:
            None: Recycled Canvas hit targets dispatch the expected index.
        """
        proxies = self._append_catalogue(TEST_INPUT_CATALOGUE_SIZE)
        self._settle(TEST_VIEWPORT_WIDTHS[0])
        proxy = proxies[0]
        proxy.event_generate('<Button-1>')
        proxy.event_generate('<Shift-Button-1>')
        if sys.platform == 'darwin':
            proxy.event_generate('<Button-1>', state=TEST_MAC_COMMAND_MODIFIER_MASK)
        else:
            proxy.event_generate('<Control-Button-1>')
        proxy.event_generate('<Control-Button-3>')
        self.root.update()
        self.assertIn(('select', 0), self.callbacks)
        self.assertIn(('range', 0), self.callbacks)
        self.assertIn(('toggle', 0), self.callbacks)
        self.assertIn(('context', 0), self.callbacks)

    def test_canvas_focus_keyboard_events_keep_logical_identity(self) -> None:
        """Forward key events with the focused tile's current logical index.

        Returns:
            None: The keyboard callback receives the focused photo index.
        """
        proxies = self._append_catalogue(TEST_INPUT_CATALOGUE_SIZE)
        self._settle(TEST_VIEWPORT_WIDTHS[0])
        focus_index = self.grid.active_indices[-1]
        self.grid.focus(focus_index, force=True)
        self.root.update()
        proxy = proxies[focus_index]
        proxy.event_generate('<KeyPress-Return>')
        self.root.update()
        self.assertEqual(focus_index, self.grid.focused_index)
        self.assertTrue(any(
            name == 'key:Return' and index == focus_index
            for name, index in self.callbacks
        ))

    def test_recycled_images_clear_and_stale_proxies_cannot_repaint(self) -> None:
        """Clear recycled image items and reject stale catalogue mutations.

        Returns:
            None: Old Tk images cannot remain on reused slots or replace a new
                catalogue image at the same logical index.
        """
        proxies = self._append_catalogue(TEST_SCROLL_CATALOGUE_SIZE)
        self._settle(TEST_VIEWPORT_WIDTHS[0])
        old_image = tk.PhotoImage(
            master=self.root, width=TEST_IMAGE_DIMENSION,
            height=TEST_IMAGE_DIMENSION,
        )
        old_proxy = proxies[0]
        old_proxy.configure(image=old_image)
        self.assertIn(str(old_image), self._canvas_image_names())
        self.canvas.yview_moveto(1.0)
        self.root.update()
        self._settle(TEST_VIEWPORT_WIDTHS[0])
        self.assertNotIn(str(old_image), self._canvas_image_names())

        self.grid.reset_catalogue()
        replacement_proxy = self.grid.append(
            0, TEST_PHOTO_ID_OFFSET, 'Replacement photo',
        )
        self._settle(TEST_VIEWPORT_WIDTHS[0])
        replacement_image = tk.PhotoImage(
            master=self.root, width=TEST_IMAGE_DIMENSION,
            height=TEST_IMAGE_DIMENSION,
        )
        replacement_proxy.configure(image=replacement_image)
        old_proxy.destroy()
        with self.assertRaises(TclError):
            old_proxy.configure(image=old_image)
        self.assertIn(str(replacement_image), self._canvas_image_names())
        self.assertNotIn(str(old_image), self._canvas_image_names())

    def test_stale_proxy_input_and_teardown_do_not_reach_closed_canvas(self) -> None:
        """Reject proxy activity after close and release the Canvas item pool.

        Returns:
            None: Closing removes owned items and later input emits no callbacks.
        """
        proxies = self._append_catalogue(TEST_INPUT_CATALOGUE_SIZE)
        self._settle(TEST_VIEWPORT_WIDTHS[0])
        proxy = proxies[0]
        self.grid.close()
        self.callbacks.clear()
        self.assertEqual((), self.canvas.find_all())
        with self.assertRaises(TclError):
            proxy.event_generate('<Button-1>')
        self.assertEqual([], self.callbacks)


    def test_focus_entered_before_catalogue_targets_first_admitted_photo(self) -> None:
        """Assign logical focus after an empty focused Canvas receives photos.

        Returns:
            None: Actual Canvas focus stays in place and keys reach the first
                admitted logical photo without a click or scroll.
        """
        self.canvas.focus_force()
        self.root.update()
        self.assertIs(self.canvas.focus_get(), self.canvas)
        self.assertIsNone(self.grid.focused_index)

        self._append_catalogue(1)
        self._settle(TEST_VIEWPORT_WIDTHS[0])
        self.assertIs(self.canvas.focus_get(), self.canvas)
        self.assertEqual((0,), self.grid.active_indices)
        self.assertEqual(0, self.grid.focused_index)

        self.canvas.event_generate('<KeyPress-Return>')
        self.root.update()
        self.assertTrue(any(
            name == 'key:Return' and index == 0
            for name, index in self.callbacks
        ))
