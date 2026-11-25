# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/uploader/ui_shell.py
# Module Type: Windows Client Interface
# Purpose: Host application panels and modal prompts inside the main window.
# Responsibilities: Maintain panel stacking, focus and synchronous dialog results.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)
"""Embedded window and prompt adapters for the uploader's Tk root."""

import tkinter as tk
from tkinter import font as tkfont, ttk
from typing import Callable

from uploader.localization import gettext
from uploader.ui_theme import (
    Button, DialogSurface, ModalScrim, RaisedDialogSurface, _PALETTE, icon_button,
)

# Purpose: Keep the root visible around embedded dialogs at supported sizes.
# Type: int. Units: Tk pixels per edge. Scope: active modal placement.
# Consumers: EmbeddedWindow._fit.
# Rationale: 20 px leaves context even at the 840 by 500 application minimum.
_DIALOG_MARGIN = 20

# Purpose: Give content-heavy dialogs room for their existing scroll controls.
# Type: tuple[int, int]. Units: Tk pixels. Scope: preferred dialog size.
# Consumers: EmbeddedWindow. Rationale: 900 by 620 accommodates file pickers,
# reports and diagnostics while preserving margins on a narrow root.
_DIALOG_SIZE = (900, 620)

# Purpose: Center questions and text-entry prompts in a compact card.
# Type: tuple[int, int]. Units: Tk pixels. Scope: one synchronous prompt.
# Consumers: _prompt. Rationale: 620 by 360 leaves space for wrapping and
# the response row, with long text contained by its existing scrollbar.
_PROMPT_SIZE = (620, 360)

# Purpose: Keep help instructions comfortably readable in a compact overlay.
# Type: int; Units: pixels; Scope: help window minimum content width.
# Consumers: help overlay geometry.
# Rationale: 380 px supports concise instructions on standard displays.
_HELP_MIN_WIDTH = 380

# Purpose: Bound help text and window geometry to comfortable dimensions.
# Type: int; Units: pixels; Scope: help overlay sizing.
# Consumers: help overlay minimum and initial geometry.
# Rationale: 180 px minimum and 200 px initial height keep the overlay compact.
_HELP_MIN_HEIGHT = 180
_HELP_INITIAL_HEIGHT = 200

# Purpose: Define the initial text area before content-driven resizing.
# Type: tuple[int, int]; Units: text rows and columns; Scope: help overlay.
# Consumers: help Text widget requested geometry.
# Rationale: 7 by 46 characters fits concise guidance without excess space.
_HELP_TEXT_SIZE = (7, 46)

# Purpose: Bound notice text while retaining long diagnostic explanations.
# Type: int. Units: text rows. Scope: one embedded notice or question.
# Consumers: _prompt. Rationale: 12 scrollable rows leave room for response
# controls at the minimum supported main-window height.
PROMPT_TEXT_ROWS = 12

# Purpose: Keep routine notices compact above the current screen footer.
# Type: int. Units: text rows. Scope: one in-window notification strip.
# Consumers: _render_notice. Rationale: Four scrollable rows retain long errors
# without covering the main screen or moving the user's keyboard focus.
NOTICE_TEXT_ROWS = 4

# Purpose: Bound queued transient notices independently of durable diagnostics.
# Type: int. Units: notices. Scope: one running application root.
# Consumers: _show_notice. Rationale: 32 pending notices preserve normal bursts;
# omitted older notices are counted visibly instead of growing memory forever.
NOTICE_QUEUE_LIMIT = 32


def initialize_shell(root: tk.Tk) -> None:
    """Initialize panel ownership on the application root.

    Args:
        root (tk.Tk): Existing application window hosting all embedded panels.
    Returns:
        None: Installs the panel stack if it does not already exist.
    """
    if not hasattr(root, "_embedded_panels"):
        root._embedded_panels = []
        root._embedded_notices = []
        root._embedded_notice_widget = None
        root._embedded_notices_omitted = 0
        root._embedded_scrim = None
        root.bind("<Escape>", lambda _event: _close_active_panel(root), add="+")
        root.bind("<Map>", _scope_mapped_widget, add="+")
        root.bind("<Configure>", _fit_active_panel, add="+")
        root.bind_class("EmbeddedPanelKeys", "<Tab>", lambda event: _tab_active_panel(root, event, False))
        root.bind_class("EmbeddedPanelKeys", "<Shift-Tab>", lambda event: _tab_active_panel(root, event, True))
        root.bind_class("EmbeddedPanelKeys", "<Escape>", lambda _event: _close_active_panel(root))


def _scope_mapped_widget(event: tk.Event) -> None:
    """Scope keyboard events for controls created after a panel's initial build.

    Args:
        event (tk.Event): Map event identifying a newly displayed widget.
    Returns:
        None: Adds the shared panel key tag only to panel-owned descendants.
    """
    widget = event.widget
    if (not isinstance(widget, tk.Misc)
            or not getattr(widget._root(), "_embedded_panels", ())):
        return
    surface_owner = getattr(widget, "_embedded_owner", None)
    if surface_owner is not None:
        surface_owner.grab_set()
    owner = widget
    while owner is not None:
        if isinstance(owner, EmbeddedWindow) or hasattr(owner, "_embedded_owner"):
            tags = widget.bindtags()
            if "EmbeddedPanelKeys" not in tags:
                widget.bindtags(("EmbeddedPanelKeys",) + tags)
            return
        owner = getattr(owner, "master", None)


def _fit_active_panel(event: tk.Event) -> None:
    """Fit the centered card only after real main-window geometry changes.

    Args:
        event (tk.Event): Root or descendant Configure event.
    Returns:
        None: The current card retains its margins without responding to
            progress text and other internal layout events.
    """
    root = event.widget
    if isinstance(root, tk.Tk):
        panel = active_panel(root)
        if panel is not None:
            panel._fit()


def _tab_active_panel(root: tk.Tk, event: tk.Event, reverse: bool) -> str | None:
    """Route a traversal key to the panel currently owning interaction.

    Args:
        root (tk.Tk): Application root owning shared keyboard bindings.
        event (tk.Event): Key event identifying the currently focused control.
        reverse (bool): Whether to traverse backward through panel controls.
    Returns:
        str | None: Break when a panel handled the key, otherwise None.
    """
    panel = active_panel(root)
    return panel._tab_focus(event, reverse) if panel is not None else None


def _close_active_panel(root: tk.Tk) -> str | None:
    """Close only the currently active panel after an Escape key press.

    Args:
        root (tk.Tk): Main application window receiving descendant key events.
    Returns:
        str | None: Break when a panel handled Escape; otherwise None.
    """
    panel = active_panel(root)
    if panel is not None:
        panel._close()
        return "break"
    return None


def active_panel(root: tk.Misc) -> "EmbeddedWindow | None":
    """Return the topmost visible embedded surface for command gating.

    Args:
        root (tk.Misc): Main root or a widget within it.
    Returns:
        EmbeddedWindow | None: Most recently raised visible panel, if any.
    """
    panels = getattr(root.winfo_toplevel(), "_embedded_panels", ())
    return next((panel for panel in reversed(panels) if not panel._hidden), None)


def _show_notice(title: str, message: str, parent: tk.Misc | None) -> str:
    """Queue a routine notice without waiting or changing keyboard ownership.

    Args:
        title (str): Information, warning or error heading supplied by its owner.
        message (str): Complete user-facing explanation, scrollable in place.
        parent (tk.Misc | None): Invoking widget, or the existing default root.
    Returns:
        str: Compatibility ok token after presentation; no acknowledgement wait.
    """
    owner = parent or tk._default_root
    if owner is None:
        # Startup failures have no application main loop to keep a notice alive.
        # This one-root prompt remains until dismissed, then disposes its root.
        return _prompt(title, message, None, (("OK", "ok"),)) or "ok"
    root = owner.winfo_toplevel()
    initialize_shell(root)
    notices = root._embedded_notices
    if len(notices) >= NOTICE_QUEUE_LIMIT:
        notices.pop(0)
        root._embedded_notices_omitted += 1
    notices.append((title, message))
    _render_notice(root)
    return "ok"


def _dismiss_notice(root: tk.Tk) -> None:
    """Dismiss the displayed notice and reveal any remaining queued message.

    Args:
        root (tk.Tk): Main application owning the notice queue.
    Returns:
        None: Advances the transient queue without touching jobs or credentials.
    """
    if root._embedded_notices:
        root._embedded_notices.pop(0)
    if not root._embedded_notices:
        root._embedded_notices_omitted = 0
    _render_notice(root)


def _render_notice(root: tk.Tk) -> None:
    """Render pending notices inside the currently interactive root surface.

    Args:
        root (tk.Tk): Root whose active panel or main screen hosts the strip.
    Returns:
        None: Refreshes a dismissible, scrollable strip without taking focus.
    """
    previous = root._embedded_notice_widget
    if previous is not None and previous.winfo_exists():
        previous.destroy()
    root._embedded_notice_widget = None
    if not root._embedded_notices:
        return
    owner = active_panel(root) or root
    strip = RaisedDialogSurface(owner, padding=(12, 8))
    strip.columnconfigure(0, weight=1)
    title, message = root._embedded_notices[0]
    label = title + f" · {len(root._embedded_notices)} notice(s)"
    if root._embedded_notices_omitted:
        label += f" · {root._embedded_notices_omitted} earlier notice(s) omitted"
    ttk.Label(strip, text=label, style="Section.TLabel").grid(row=0, column=0, sticky="w")
    Button(strip, text=gettext("Dismiss"), command=lambda: _dismiss_notice(root)).grid(row=0, column=1, sticky="e")
    text = tk.Text(
        strip, height=NOTICE_TEXT_ROWS, wrap="word", relief="flat",
        font="TkDefaultFont", background=_PALETTE["card_surface"],
        foreground=_PALETTE["ink"], borderwidth=0, highlightthickness=0,
        takefocus=True,
    )
    text.insert("1.0", message)
    text.configure(state="disabled")
    scroll = ttk.Scrollbar(strip, orient="vertical", command=text.yview)
    text.configure(yscrollcommand=scroll.set)
    text.grid(row=1, column=0, sticky="ew", pady=(6, 0))
    scroll.grid(row=1, column=1, sticky="ns", pady=(6, 0))
    strip.place(
        relx=0.5, rely=1, relwidth=1, width=-2 * _DIALOG_MARGIN,
        y=-_DIALOG_MARGIN, anchor="s",
    )
    strip.lift()
    root._embedded_notice_widget = strip


class EmbeddedWindow(DialogSurface):
    """Host panel behavior on the inherited central dialog surface.

    Theme, rounded clipping and platform fallbacks belong to DialogSurface;
    this adapter owns stacking, keyboard traversal and window compatibility.
    """

    def __init__(
        self, parent: tk.Misc, *, size: tuple[int, int] = _DIALOG_SIZE,
    ) -> None:
        """Create and display a stacked panel owned by the root.

        Args:
            parent (tk.Misc): Invoking widget within the main root.
            size (tuple[int, int]): Preferred card width and height in Tk pixels.
        Returns:
            None: Creates a visible panel with a title and close control.
        """
        root = parent.winfo_toplevel()
        initialize_shell(root)
        self._previous_focus = root.focus_get()
        self._previous_grab = root.grab_current()
        owner = parent
        self._owner_panel = None
        while owner is not None:
            if isinstance(owner, EmbeddedWindow):
                self._owner_panel = owner
                break
            owner = getattr(owner, "master", None)
        self._close_callback = self.destroy
        self._preferred_size = size
        self._placed_size: tuple[int, int] | None = None
        self._hidden = True
        self._ready = False
        self._destroying = False
        self._surface = RaisedDialogSurface(root)
        self._surface._embedded_owner = self
        super().__init__(self._surface, borderwidth=0)
        self._header = ttk.Frame(self._surface, padding=(12, 7), style="Card.TFrame")
        self._title_label = ttk.Label(self._header, text="", style="Section.TLabel")
        self._title_label.pack(side="left", fill="x", expand=True)
        icon_button(
            self._header, "close", self._close, gettext("Close"),
        ).pack(side="right")
        self._header.pack(fill="x")
        self.pack(fill="both", expand=True)
        root._embedded_panels.append(self)
        self.deiconify()
        self._scope_after = self.after_idle(self._scope_keyboard)

    def _fit(self) -> None:
        """Place the modal at its preferred size within the current client area.

        Returns:
            None: The center remains stable and available margins constrain
                both dimensions, including after a root resize.
        """
        root = self.winfo_toplevel()
        width = max(1, min(self._preferred_size[0], root.winfo_width() - 2 * _DIALOG_MARGIN))
        height = max(1, min(self._preferred_size[1], root.winfo_height() - 2 * _DIALOG_MARGIN))
        if (width, height) != self._placed_size:
            self._surface.place(
                relx=0.5, rely=0.5, anchor="center", width=width, height=height,
            )
            self._placed_size = (width, height)

    def _scope_keyboard(self) -> None:
        """Keep keyboard traversal inside the active panel and its close control.

        Returns:
            None: Adds an early key bind tag to the populated panel descendants.
        """
        if self._scope_after is not None:
            self.after_cancel(self._scope_after)
            self._scope_after = None
        if not self.winfo_exists():
            return
        tag = "EmbeddedPanelKeys"
        widgets = [self._surface]
        while widgets:
            widget = widgets.pop()
            tags = widget.bindtags()
            if tag not in tags:
                widget.bindtags((tag,) + tags)
            widgets.extend(widget.winfo_children())

    def _tab_focus(self, event: tk.Event, reverse: bool) -> str:
        """Move focus among controls belonging to the topmost panel.

        Args:
            event (tk.Event): Key event identifying the current focus widget.
            reverse (bool): Whether Shift requests backward traversal.
        Returns:
            str: Tk break marker preventing traversal into background controls.
        """
        panel = active_panel(self.winfo_toplevel())
        if panel is not self:
            return "break"
        candidate = event.widget
        visited = set()
        while str(candidate) not in visited:
            visited.add(str(candidate))
            candidate = candidate.tk_focusPrev() if reverse else candidate.tk_focusNext()
            if candidate is None:
                break
            if str(candidate).startswith(str(self._surface) + "."):
                candidate.focus_set()
                break
        return "break"

    def grab_set(self) -> None:
        """Make both the panel content and its heading own local interaction.

        Returns:
            None: Grabs the encompassing surface, keeping Close clickable.
        """
        root = self.winfo_toplevel()
        if (not self._hidden and active_panel(root) is self
                and root.state() not in ("withdrawn", "iconic")):
            self._surface.grab_set()

    def grab_release(self) -> None:
        """Release local interaction for the encompassing panel surface.

        Returns:
            None: Allows the previous panel or root to receive input.
        """
        self._surface.grab_release()

    def grab_current(self) -> tk.Misc | None:
        """Return the semantic panel owning the current local interaction grab.

        Returns:
            tk.Misc | None: Panel owner, native widget owner or no active grab.
        """
        owner = super().grab_current()
        return getattr(owner, "_embedded_owner", owner)

    def _close(self) -> None:
        """Invoke the current window-close action.

        Returns:
            None: Runs the registered close callback.
        """
        self._close_callback()

    def title(self, value: str) -> None:
        """Set the panel heading.

        Args:
            value (str): Heading displayed above the panel content.
        Returns:
            None: Updates the visible heading.
        """
        self._title_label.configure(text=value)

    def protocol(self, name: str, callback: Callable[[], None]) -> None:
        """Register the action for a window-manager close request.

        Args:
            name (str): Protocol name; WM_DELETE_WINDOW owns the close control.
            callback (Callable[[], None]): Action invoked by close or Escape.
        Returns:
            None: Stores supported close behavior.
        """
        if name == "WM_DELETE_WINDOW":
            self._close_callback = callback

    def transient(self, owner: tk.Misc) -> None:
        """Accept an owner already represented by the root panel stack.

        Args:
            owner (tk.Misc): Invoking widget in the same root.
        Returns:
            None: Ownership is established when the panel is constructed.
        """

    def resizable(self, width: bool, height: bool) -> None:
        """Accept native resize preferences for the host-sized card.

        Args:
            width (bool): Whether a native window would resize horizontally.
            height (bool): Whether a native window would resize vertically.
        Returns:
            None: The root controls available panel dimensions.
        """

    def minsize(self, width: int, height: int) -> None:
        """Accept native minimum dimensions without resizing the root.

        Args:
            width (int): Requested minimum width in pixels.
            height (int): Requested minimum height in pixels.
        Returns:
            None: The parent root determines the available dimensions.
        """

    def geometry(self, value: str = "") -> str:
        """Keep panel geometry within the main window.

        Args:
            value (str): Native geometry request, ignored for embedded panels.
        Returns:
            str: Current widget geometry for callers querying placement.
        """
        return self.winfo_geometry()

    def withdraw(self) -> None:
        """Hide this panel and reveal the previously stacked surface.

        Returns:
            None: Removes both content and heading from display.
        """
        was_active = active_panel(self.winfo_toplevel()) is self
        self._ready = False
        self._hidden = True
        self._surface.place_forget()
        self._placed_size = None
        if was_active:
            self.grab_release()
            self._restore_interaction()

    def deiconify(self) -> None:
        """Show the panel within the available root area.

        Returns:
            None: Places the panel and its heading above earlier surfaces.
        """
        self._ready = True
        current = active_panel(self.winfo_toplevel())
        if current is None or current is self._owner_panel:
            self._activate()

    def _activate(self) -> None:
        """Reveal an admitted panel and restore its local interaction ownership.

        Returns:
            None: Places the complete surface above the root without a new window.
        """
        root = self.winfo_toplevel()
        self._hidden = False
        self._fit()
        if root._embedded_scrim is None:
            root._embedded_scrim = ModalScrim(root)
        root._embedded_scrim.show(self._surface)
        panels = root._embedded_panels
        panels.remove(self)
        panels.append(self)
        self.grab_set()
        self.focus_set()
        if root._embedded_notices:
            _render_notice(root)

    def _restore_interaction(self) -> None:
        """Reveal the next ready panel after the active one closes or hides.

        Returns:
            None: Restores a live panel or the root's previous focus and grab.
        """
        root = self.winfo_toplevel()
        next_panel = next(
            (item for item in reversed(root._embedded_panels)
             if item is not self and item._ready and not item._destroying),
            None,
        )
        if next_panel is not None:
            next_panel._activate()
        if next_panel is None:
            root._embedded_scrim.place_forget()
            if (self._previous_grab is not None
                    and not hasattr(self._previous_grab, "_embedded_owner")
                    and self._previous_grab.winfo_exists()):
                self._previous_grab.grab_set()
            if root._embedded_notices:
                _render_notice(root)
        if (self._previous_focus is not None
                and self._previous_focus.winfo_exists()
                and self._previous_focus.winfo_viewable()):
            if next_panel is None or str(self._previous_focus).startswith(str(next_panel._surface) + "."):
                self._previous_focus.focus_set()

    def lift(self, aboveThis: tk.Misc | None = None) -> None:
        """Raise this panel and its heading together.

        Args:
            aboveThis (tk.Misc | None): Optional sibling above which to raise.
        Returns:
            None: The panel is raised in the root stacking order.
        """
        if self._hidden or active_panel(self.winfo_toplevel()) is not self:
            return
        self._surface.lift(aboveThis)
        panels = self.winfo_toplevel()._embedded_panels
        if self in panels:
            panels.remove(self)
            panels.append(self)

    def destroy(self) -> None:
        """Remove the panel and restore the previous focus and local grab.

        Returns:
            None: Destroys both panel widgets and restores live prior owners.
        """
        if self._destroying:
            return
        self._destroying = True
        root = self.winfo_toplevel()
        was_active = active_panel(root) is self
        if self._scope_after is not None:
            self.after_cancel(self._scope_after)
            self._scope_after = None
        # Closing an owner also closes panels it spawned; abandoned nested
        # prompts must not survive after their stateful controller is gone.
        for child in tuple(reversed(root._embedded_panels)):
            if child._owner_panel is self:
                child.destroy()
        try:
            root._embedded_panels.remove(self)
        except ValueError:
            pass
        try:
            if was_active:
                self.grab_release()
                self._restore_interaction()
        except tk.TclError:
            pass
        super().destroy()
        self._surface.destroy()


def help_button(parent: tk.Misc, title: str, body: str) -> ttk.Button:
    """Create a help button that opens a focused, selectable help overlay.

    Args:
        parent (tk.Misc): Tk widget that owns the new button.
        title (str): Short heading displayed in the help window.
        body (str): Plain text instructions that users can select and copy.

    Returns:
        ttk.Button: A themed question-mark button that opens the overlay.
    """
    button: ttk.Button

    def open_help() -> None:
        """Open the help window and move keyboard focus into its text.

        Returns:
            None: A transient help window is shown when its owner exists.
        """
        if not button.winfo_exists():
            return
        _show_help(button, title, body)

    button = icon_button(parent, "help", open_help,
                         tooltip=gettext("Help: {title}").format(title=title))
    return button


def _show_help(owner: tk.Misc, title: str, body: str) -> None:
    """Display a modal-style help panel with selectable content.

    Args:
        owner (tk.Misc): Invoking widget owning the embedded help panel.
        title (str): Concise title shown in the dialog heading and chrome.
        body (str): Plain text content users may select or copy.

    Returns:
        None: The help overlay is focused and dismissed by Escape or close.
    """
    # The shell owns focus and dismissal; construction stays in the theme.
    try:
        parent = owner.winfo_toplevel()
        previous_grab = parent.grab_current()
        previous_focus = parent.focus_get()
        dialog = EmbeddedWindow(owner)
    except tk.TclError:
        return

    def close_help() -> None:
        """Close help and return grab and focus to their previous owners.

        Returns:
            None: The dialog is dismissed and prior live owners are restored.
        """
        try:
            if dialog.winfo_exists():
                dialog.destroy()
        except tk.TclError:
            return
        try:
            if previous_grab is not None and previous_grab.winfo_exists():
                previous_grab.grab_set()
            if previous_focus is not None and previous_focus.winfo_exists():
                previous_focus.focus_set()
        except tk.TclError:
            pass

    dialog.title(title or gettext("Help"))
    dialog.transient(parent)
    dialog.resizable(True, True)
    dialog.minsize(_HELP_MIN_WIDTH, _HELP_MIN_HEIGHT)
    shell = ttk.Frame(dialog, style="Glass.TFrame", padding=22)
    shell.pack(fill="both", expand=True)
    ttk.Label(shell, text=title, style="Glass.Title.TLabel").pack(
        anchor="w", pady=(0, 10))
    text_rows, text_columns = _HELP_TEXT_SIZE
    text_box = tk.Text(
        shell,
        wrap="word",
        height=text_rows,
        width=text_columns,
        relief="flat",
        borderwidth=0,
        padx=2,
        pady=2,
        background=_PALETTE["surface"],
        foreground=_PALETTE["ink"],
        font=(tkfont.nametofont("TkDefaultFont", root=parent).actual("family"), 10),
        takefocus=True,
    )
    text_box.insert("1.0", body)
    text_box.pack(fill="both", expand=True, anchor="w")
    text_box.bind("<Control-a>", lambda _event: _select_help_text(text_box))
    text_box.bind("<Key>", lambda event: _guard_help_text(event))
    text_box.bind("<<Paste>>", lambda _event: "break")
    text_box.bind("<<Cut>>", lambda _event: "break")
    text_box.bind("<Button-2>", lambda _event: "break")
    footer = ttk.Frame(shell, style="Card.TFrame")
    footer.pack(fill="x", pady=(14, 0))
    close_button = Button(footer, text=gettext("Close"), command=close_help)
    close_button.pack(side="right")
    dialog.bind("<Escape>", lambda _event: close_help())
    dialog.protocol("WM_DELETE_WINDOW", close_help)
    dialog.update_idletasks()
    width = max(_HELP_MIN_WIDTH, dialog.winfo_reqwidth())
    height = max(_HELP_INITIAL_HEIGHT, dialog.winfo_reqheight())
    x = parent.winfo_rootx() + max(0, (parent.winfo_width() - width) // 2)
    y = parent.winfo_rooty() + max(0, (parent.winfo_height() - height) // 3)
    dialog.geometry(f"{width}x{height}+{x}+{y}")
    dialog.grab_set()
    text_box.focus_set()


def _select_help_text(text_box: tk.Text) -> str:
    """Select all help text and allow the normal copy shortcut to continue.

    Args:
        text_box (tk.Text): Read-only text widget containing help content.

    Returns:
        str: ``break`` to stop the default Control+A binding after selection.
    """
    text_box.tag_add("sel", "1.0", "end-1c")
    return "break"


def _guard_help_text(event: tk.Event) -> str | None:
    """Keep help content read-only while preserving select and copy keys.

    Args:
        event (tk.Event): Key event sent to the selectable help text widget.

    Returns:
        str | None: ``break`` prevents edits; ``None`` permits copy and select.
    """
    if event.state & 0x4 and event.keysym.lower() in ("a", "c"):
        return None
    return "break"


def _prompt(
    title: str, message: str, parent: tk.Misc | None,
    buttons: tuple[tuple[str, str], ...], initial: str | None = None,
    minimum: int | None = None, maximum: int | None = None,
    integer: bool = False, default: str = "no",
) -> str | None:
    """Show a synchronous embedded prompt and return its selected value.

    Args:
        title (str): Prompt heading.
        message (str): User-facing question or notice.
        parent (tk.Misc | None): Invoking widget, or the existing default root.
        buttons (tuple[tuple[str, str], ...]): Button labels and result tokens.
        initial (str | None): Initial entry value, or None for no entry.
        minimum (int | None): Minimum accepted integer, if applicable.
        maximum (int | None): Maximum accepted integer, if applicable.
        integer (bool): Whether the entry requires an integer in range.
        default (str): Result token focused initially for keyboard activation.
    Returns:
        str | None: Selected token or entry text, or None on cancellation.
    """
    owner = parent or tk._default_root
    temporary_root = owner is None
    if owner is None:
        # Permanent startup support: a coordination/configuration error can
        # precede WatcherApp construction. A single root still hosts its notice;
        # no hidden Tk root plus secondary dialog window is created.
        owner = tk.Tk()
    # Legacy no-parent/root-parent calls are user decisions in the currently
    # active context. Explicit child owners remain authoritative for nesting.
    if isinstance(owner, tk.Tk):
        owner = active_panel(owner) or owner
    panel = EmbeddedWindow(owner, size=_PROMPT_SIZE)
    panel.title(title)
    body = ttk.Frame(panel, padding=20)
    body.pack(fill="both", expand=True)
    body.columnconfigure(0, weight=1)
    body.rowconfigure(0, weight=1)
    text_frame = ttk.Frame(body)
    text_frame.grid(row=0, column=0, sticky="nsew")
    text = tk.Text(
        text_frame, wrap="word", height=PROMPT_TEXT_ROWS, relief="flat",
        font="TkDefaultFont", background=_PALETTE["card_surface"],
        foreground=_PALETTE["ink"], borderwidth=0, highlightthickness=0,
        padx=8, pady=8,
    )
    text.insert("1.0", message)
    text.configure(state="disabled")
    scroll = ttk.Scrollbar(text_frame, orient="vertical", command=text.yview)
    text.configure(yscrollcommand=scroll.set)
    text.pack(side="left", fill="both", expand=True)
    scroll.pack(side="right", fill="y")
    value = tk.StringVar(master=panel, value=initial or "")
    entry = None
    if initial is not None:
        entry = ttk.Entry(body, textvariable=value)
        entry.grid(row=1, column=0, sticky="ew", pady=(8, 0))
    error = ttk.Label(body, text="")
    error.grid(row=2, column=0, sticky="w")
    completed = tk.BooleanVar(master=owner, value=False)
    result: list[str | None] = []

    def finish(token: str) -> None:
        """Validate the selected entry and release the waiting caller.

        Args:
            token (str): Selected button result token.
        Returns:
            None: Stores a result and closes the prompt when valid.
        """
        if result:
            return
        if token == "value" and integer:
            try:
                number = int(value.get())
                if (minimum is not None and number < minimum) or (maximum is not None and number > maximum):
                    raise ValueError
            except ValueError:
                error.configure(text=gettext("Enter a valid number within the allowed range."))
                return
        result.append(
            value.get() if token == "value"
            else None if token == "__cancel__" else token
        )
        completed.set(True)
        panel.destroy()

    def destroyed(event: tk.Event) -> None:
        """Cancel a prompt whose owning surface or root was destroyed.

        Args:
            event (tk.Event): Tk destruction event for the prompt content.
        Returns:
            None: Releases the waiting caller exactly once with cancellation.
        """
        if event.widget is panel and not result:
            result.append(None)
            completed.set(True)

    footer = ttk.Frame(body)
    footer.grid(row=3, column=0, sticky="ew", pady=(8, 0))
    focused = None
    for label, token in buttons:
        button = Button(
            footer, text=label, command=lambda item=token: finish(item),
        )
        button.pack(side="right", padx=4)
        if token == default or len(buttons) == 1:
            focused = button
    panel.protocol("WM_DELETE_WINDOW", lambda: finish("__cancel__"))
    panel.bind("<Destroy>", destroyed, add="+")
    panel.grab_set()
    if entry is not None:
        entry.focus_set()
        entry.bind("<Return>", lambda _event: finish("value"))
    elif focused is not None:
        focused.focus_set()
    panel.wait_variable(completed)
    if temporary_root:
        try:
            owner.winfo_toplevel().destroy()
        except tk.TclError:
            pass
    return result[0]


class _MessageBox:
    """Offer the messagebox operations used by the uploader."""

    NO = "no"

    def showinfo(self, title: str, message: str, parent: tk.Misc | None = None, **_options: str) -> str:
        """Display a nonblocking notice within the current application surface.

        Args:
            title (str): Notice heading.
            message (str): Notice text.
            parent (tk.Misc | None): Invoking widget, or the existing default root.
            **_options (str): Compatibility options supplied by Tk callers.
        Returns:
            str: Compatibility ok token; the notice remains until dismissed.
        """
        return _show_notice(title, message, parent)

    showerror = showinfo
    showwarning = showinfo

    def askyesno(self, title: str, message: str, parent: tk.Misc | None = None, **_options: str) -> bool:
        """Request a yes or no decision.

        Args:
            title (str): Question heading.
            message (str): Question text.
            parent (tk.Misc | None): Invoking widget, or the existing default root.
            **_options (str): Default focus token and optional yes/no captions.
        Returns:
            bool: True only when the user chooses Yes.
        """
        return _prompt(
            title, message, parent, (
                (_options.get("yes_label") or gettext("Yes"), "yes"),
                (_options.get("no_label") or gettext("No"), "no"),
            ),
            default=_options.get("default", "no"),
        ) == "yes"

    def askyesnocancel(self, title: str, message: str, parent: tk.Misc | None = None, **_options: str) -> bool | None:
        """Request a yes, no or cancellation decision.

        Args:
            title (str): Question heading.
            message (str): Question text.
            parent (tk.Misc | None): Invoking widget, or the existing default root.
            **_options (str): Compatibility options supplied by Tk callers.
        Returns:
            bool | None: True for Yes, False for No, or None for cancellation.
        """
        result = _prompt(title, message, parent or tk._default_root, (
            (gettext("Yes"), "yes"),
            (gettext("No"), "no"),
            (gettext("Cancel"), "__cancel__"),
        ))
        return None if result is None else result == "yes"


class _SimpleDialog:
    """Offer the text and integer prompts used by the uploader."""

    def askstring(self, title: str, prompt: str, parent: tk.Misc, initialvalue: str = "") -> str | None:
        """Request a text value.

        Args:
            title (str): Prompt heading.
            prompt (str): Input description.
            parent (tk.Misc): Invoking widget.
            initialvalue (str): Initial entry text.
        Returns:
            str | None: Entered text or None when cancelled.
        """
        return _prompt(title, prompt, parent, (
            (gettext("OK"), "value"), (gettext("Cancel"), "__cancel__"),
        ), initialvalue)

    def askinteger(self, title: str, prompt: str, parent: tk.Misc, minvalue: int | None = None, maxvalue: int | None = None) -> int | None:
        """Request an integer within optional bounds.

        Args:
            title (str): Prompt heading.
            prompt (str): Input description.
            parent (tk.Misc): Invoking widget.
            minvalue (int | None): Inclusive lower bound, if present.
            maxvalue (int | None): Inclusive upper bound, if present.
        Returns:
            int | None: Entered integer or None when cancelled.
        """
        result = _prompt(title, prompt, parent, (
            (gettext("OK"), "value"), (gettext("Cancel"), "__cancel__"),
        ), "", minvalue, maxvalue, True)
        return None if result is None else int(result)


messagebox = _MessageBox()
simpledialog = _SimpleDialog()
