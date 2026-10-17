# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/uploader/ui_theme.py
# Module Type: Windows uploader presentation support
# Purpose: Provide a cohesive, accessible Tkinter theme for the uploader.
# Responsibilities: Configure shared ttk styles, icon buttons, cards and help.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)

"""Shared visual styling and accessible presentation helpers for the uploader."""

from collections.abc import Callable
from dataclasses import dataclass
import tkinter as tk
from tkinter import ttk
from typing import TYPE_CHECKING, TypeAlias

if TYPE_CHECKING:
    from PIL import ImageTk

    ThemeImage: TypeAlias = tk.PhotoImage | ImageTk.PhotoImage
else:
    ThemeImage: TypeAlias = tk.PhotoImage


# Purpose: Set the shared delay before hover or focus help appears.
# Type: int; Units: milliseconds; Scope: transient tooltip lifecycle.
# Consumers: tooltip scheduling and cancellation callbacks.
# Rationale: 550 ms avoids flicker while retaining timely discovery.
_TOOLTIP_DELAY_MS = 550

# Purpose: Keep help instructions comfortably readable in a compact overlay.
# Type: int; Units: pixels; Scope: help window minimum content width.
# Consumers: help overlay geometry.
# Rationale: 380 px supports concise instructions on standard displays.
_HELP_MIN_WIDTH = 380

# Purpose: Choose the source size for stretchable glossy assets.
# Type: tuple[int, int]; Units: pixels; Scope: nine-slice glossy assets.
# Consumers: Pillow image generation and ttk image elements.
# Rationale: 42 by 30 px keeps icon controls compact while retaining corners.
_GLOSS_IMAGE_SIZE = (42, 30)

# Purpose: Preserve rounded corners while stretching theme images.
# Type: int; Units: pixels; Scope: nine-slice glossy assets.
# Consumers: rounded button and card image element borders.
# Rationale: 8 px insets protect the rounded corners without adding height.
_GLOSS_BORDER = 8

# Purpose: Keep visual corners consistent across cards and buttons.
# Type: int; Units: pixels; Scope: generated rounded Tk theme artwork.
# Consumers: Pillow masks and outlines for button and card images.
# Rationale: 8 px provides a visible curve at the compact source size.
_GLOSS_CORNER_RADIUS = 8

# Purpose: Bound help text and window geometry to comfortable dimensions.
# Type: int; Units: pixels; Scope: help overlay sizing.
# Consumers: tooltip wrapping and help overlay minimum and initial geometry.
# Rationale: 320 px wrap, 180 px minimum and 200 px initial height stay compact.
_HELP_MIN_HEIGHT = 180
_HELP_TEXT_WRAP = 320
_HELP_INITIAL_HEIGHT = 200

# Purpose: Define the initial text area before content-driven resizing.
# Type: tuple[int, int]; Units: text rows and columns; Scope: help overlay.
# Consumers: help Text widget requested geometry.
# Rationale: 7 by 46 characters fits concise guidance without excess space.
_HELP_TEXT_SIZE = (7, 46)

# Purpose: Keep icon-only actions compact while leaving labeled buttons natural.
# Type: int; Units: character columns; Scope: icon-only ttk button width.
# Consumers: icon_button when passed a semantic key or single glyph.
# Rationale: 2 columns fit one line glyph without claiming toolbar width.
_ICON_BUTTON_COLUMNS = 2

# Purpose: Keep rounded corners clean in the compact opaque source artwork.
# Type: tuple[int, ...]; Units: pixels; Scope: top and bottom image rows.
# Consumers: RGB gradient renderer for button and card element images.
# Rationale: row insets approximate an 8 px curve without alpha compositing.
_GLOSS_CORNER_ROW_INSETS = (6, 3, 2, 1, 1, 0, 0, 0)

# Purpose: Leave a stable opaque edge below each rounded control surface.
# Type: int; Units: pixels; Scope: generated ttk image height.
# Consumers: RGB gradient renderer and nine-slice image layout.
# Rationale: 2 px protects the lower outline from edge clipping.
_GLOSS_BOTTOM_INSET = 2

# Purpose: Define shared colors for the uploader's light visual hierarchy.
# Type: dict[str, str]; Units: color strings; Scope: uploader widget palette.
# Consumers: ttk style registration and card defaults.
# Rationale: a restrained cool palette supports contrast and visual hierarchy.
_PALETTE = {
    "background": "#EAF1FA",
    "surface": "#FFFFFF",
    "surface_alt": "#F5F8FD",
    "ink": "#18263D",
    "muted": "#63738D",
    "line": "#D9E3F0",
    "primary": "#4F46E5",
    "primary_hover": "#4338CA",
    "primary_pressed": "#3730A3",
    "focus": "#818CF8",
    "success": "#16835D",
    "warning": "#A85E12",
    "danger": "#B42335",
}

# Purpose: Map common actions to consistent single-line icon glyphs.
# Type: dict[str, str]; Units: icon glyphs; Scope: common uploader actions.
# Consumers: icon_button callers using a semantic icon key.
# Rationale: Unicode line symbols render consistently without emoji assets.
_ICON_GLYPHS = {
    "add": "+",
    "back": "‹",
    "check": "✓",
    "close": "×",
    "help": "?",
    "info": "i",
    "pause": "Ⅱ",
    "play": "▶",
    "refresh": "↻",
    "settings": "⚙",
    "stop": "■",
    "upload": "↑",
}

# Purpose: Retain generated Tk images while ttk styles may use them.
# Type: dict[str, ThemeImage]; Units: retained image objects; Scope: process.
# Consumers: ttk layouts using generated glossy elements.
# Rationale: Tk image handles must stay referenced for the widget lifetime.
_THEME_IMAGES: dict[str, ThemeImage] = {}


def configure_theme(root: tk.Tk) -> None:
    """Apply the uploader palette and shared ttk widget styles.

    Args:
        root (tk.Tk): Application root whose ttk style and base background
            should receive the theme.

    Returns:
        None: The root and its shared ttk style are configured in place.
    """
    style = ttk.Style(root)
    if "clam" in style.theme_names():
        style.theme_use("clam")

    colors = _PALETTE
    _install_glossy_elements(root, style)
    root.configure(background=colors["background"])
    style.configure(".", background=colors["background"],
                    foreground=colors["ink"], font=("Segoe UI", 10))
    style.configure("TFrame", background=colors["background"])
    style.configure("TLabel", background=colors["surface"],
                    foreground=colors["ink"])
    style.configure("App.TLabel", background=colors["background"],
                    foreground=colors["ink"])
    style.configure("Card.TLabel", background=colors["surface"],
                    foreground=colors["ink"])
    style.configure("Muted.TLabel", background=colors["surface"],
                    foreground=colors["muted"])
    style.configure("Title.TLabel", font=("Segoe UI Semibold", 20),
                    foreground=colors["ink"])
    style.configure("Glass.Title.TLabel",
                    font=("Segoe UI Semibold", 20),
                    background=colors["surface"],
                    foreground=colors["ink"])
    style.configure("Brand.TLabel", font=("Segoe UI Semibold", 18),
                    background=colors["background"],
                    foreground=colors["ink"])
    style.configure("Eyebrow.TLabel", font=("Segoe UI Semibold", 8),
                    background=colors["surface"],
                    foreground=colors["muted"])
    style.configure("Status.TLabel", font=("Segoe UI Semibold", 10),
                    background=colors["surface"],
                    foreground=colors["ink"])
    style.configure("App.Status.TLabel",
                    font=("Segoe UI Semibold", 10),
                    background=colors["background"],
                    foreground=colors["ink"])
    style.configure("App.Muted.TLabel", background=colors["background"],
                    foreground=colors["muted"])
    style.configure("App.Eyebrow.TLabel",
                    font=("Segoe UI Semibold", 8),
                    background=colors["background"],
                    foreground=colors["muted"])
    style.configure("Warning.TLabel", background=colors["surface_alt"],
                    foreground=colors["warning"], padding=(10, 8))
    style.configure("Body.TLabel", background=colors["surface"],
                    foreground=colors["ink"])
    style.configure("Footer.TLabel", background=colors["surface"],
                    foreground=colors["muted"])
    style.configure("Hero.TLabel", font=("Segoe UI Semibold", 22),
                    background=colors["surface"],
                    foreground=colors["ink"])
    style.configure("HeroTarget.TLabel",
                    font=("Segoe UI Semibold", 11),
                    background=colors["surface"],
                    foreground=colors["primary"])
    style.configure("Large.TEntry", font=("Segoe UI", 11),
                    padding=(10, 9))
    style.configure("App.TFrame", background=colors["background"])
    style.configure("Footer.TFrame", background=colors["surface"],
                    relief="flat", borderwidth=0, padding=(12, 8))
    style.configure("Section.TLabel", font=("Segoe UI Semibold", 12),
                    foreground=colors["ink"])
    style.configure("Glass.TFrame", background=colors["surface"],
                    relief="flat", borderwidth=0)
    style.configure("GlassInset.TFrame", background=colors["surface_alt"],
                    relief="flat", borderwidth=0)
    style.configure("TButton", padding=(12, 8), borderwidth=0,
                    background=colors["surface"], foreground=colors["ink"])
    style.configure("Card.TFrame", background=colors["surface"],
                    relief="flat", borderwidth=0, padding=0)
    style.map("TButton",
              background=[("pressed", colors["line"]),
                          ("active", colors["surface_alt"])],
              foreground=[("disabled", colors["muted"])])
    style.configure("Primary.TButton", padding=(12, 6), borderwidth=0,
                    background=colors["primary"], foreground="#FFFFFF",
                    font=("Segoe UI Semibold", 10))
    style.map("Primary.TButton",
              background=[("pressed", colors["primary_pressed"]),
                          ("active", colors["primary_hover"])],
              foreground=[("disabled", "#D8D7FA")])
    style.configure("Icon.TButton", padding=(2, 2),
                    font=("Segoe UI Symbol", 12),
                    background=colors["surface"], foreground=colors["ink"])
    style.configure("IconPrimary.TButton", padding=(3, 3),
                    font=("Segoe UI Symbol", 12),
                    background=colors["primary"], foreground="#FFFFFF")
    style.map("IconPrimary.TButton",
              foreground=[("disabled", "#F0EFFC")],
              background=[("pressed", colors["primary_pressed"]),
                          ("active", colors["primary_hover"])])
    style.map("Icon.TButton",
              foreground=[("disabled", colors["muted"])])
    style.configure("TEntry", fieldbackground=colors["surface"],
                    foreground=colors["ink"], insertcolor=colors["ink"],
                    bordercolor=colors["line"], padding=(9, 7))
    style.map("TEntry", bordercolor=[("focus", colors["focus"])])
    style.configure("TCombobox", fieldbackground=colors["surface"],
                    background=colors["surface"], foreground=colors["ink"],
                    padding=(8, 6), arrowcolor=colors["muted"])
    style.configure("TCheckbutton", background=colors["surface"],
                    foreground=colors["ink"], padding=4)
    style.configure("App.TCheckbutton",
                    background=colors["background"],
                    foreground=colors["ink"], padding=4)
    style.configure("Card.TCheckbutton",
                    background=colors["surface"],
                    foreground=colors["ink"], padding=4)
    style.map("TCheckbutton", foreground=[("disabled", colors["muted"])])
    style.configure("TRadiobutton", background=colors["surface"],
                    foreground=colors["ink"], padding=4)
    style.configure("App.TRadiobutton",
                    background=colors["background"],
                    foreground=colors["ink"], padding=4)
    style.configure("Card.TRadiobutton",
                    background=colors["surface"],
                    foreground=colors["ink"], padding=4)
    style.configure("TLabelframe", background=colors["surface"],
                    bordercolor=colors["line"], relief="flat")
    style.configure("TLabelframe.Label",
                    background=colors["surface"],
                    foreground=colors["muted"],
                    font=("Segoe UI Semibold", 10))
    style.configure("TNotebook", background=colors["background"],
                    borderwidth=0, tabmargins=(0, 0, 0, 0))
    style.configure("App.TNotebook", background=colors["background"],
                    borderwidth=0, tabmargins=(0, 0, 0, 0))
    style.configure("TNotebook.Tab", padding=(14, 9),
                    background=colors["surface_alt"],
                    foreground=colors["muted"])
    style.map("TNotebook.Tab",
              background=[("selected", colors["surface"])],
              foreground=[("selected", colors["primary"])])
    style.configure("Treeview", rowheight=30,
                    background=colors["surface"],
                    fieldbackground=colors["surface"],
                    foreground=colors["ink"], borderwidth=0)
    style.configure("Treeview.Heading", padding=(9, 7),
                    background=colors["surface_alt"],
                    foreground=colors["muted"],
                    font=("Segoe UI Semibold", 9))
    style.map("Treeview", background=[("selected", "#E0E7FF")],
              foreground=[("selected", colors["ink"])])
    style.configure("Horizontal.TProgressbar",
                    background=colors["primary"],
                    troughcolor=colors["line"], borderwidth=0,
                    thickness=8)
    style.configure("Vertical.TScrollbar", background=colors["surface_alt"],
                    troughcolor=colors["background"], borderwidth=0,
                    arrowsize=12)
    style.configure("Horizontal.TScrollbar",
                    background=colors["surface_alt"],
                    troughcolor=colors["background"], borderwidth=0,
                    arrowsize=12)
    _install_focus_styles(style)


def _install_glossy_elements(root: tk.Tk, style: ttk.Style) -> None:
    """Register rounded nine-slice elements when bundled Pillow is available.

    Args:
        root (tk.Tk): Tk interpreter that owns generated PhotoImage objects.
        style (ttk.Style): Theme registry receiving the image elements.

    Returns:
        None: Glossy elements and their image references are installed, or
            ttk keeps its native rendering when Pillow is unavailable. Pillow
            remains optional so a minimal Tk source run still starts cleanly.
    """
    if "Gloss.button" in style.element_names():
        return
    try:
        from PIL import Image, ImageDraw, ImageTk
    except ImportError:
        # Pillow only adds polish; source mode, --once and native ttk remain
        # supported without image artwork, so keep the existing fallback.
        return

    width, height = _GLOSS_IMAGE_SIZE
    border = _GLOSS_BORDER
    asset_colors = {
        "button": ("#FFFFFF", "#F7FAFF"),
        "active": ("#FFFFFF", "#EFF5FF"),
        "pressed": ("#EBF1FA", "#E3EBF7"),
        "focus": ("#FFFFFF", "#F7FAFF"),
        "primary": ("#645BF2", "#5148E2"),
        "primary_active": ("#716AF6", "#5C53EB"),
        "primary_pressed": ("#5148DD", "#4239CC"),
        "primary_focus": ("#645BF2", "#5148E2"),
        "disabled": ("#EEF1F6", "#E3E8F0"),
        "disabled_primary": ("#BDBAF2", "#AAA7E8"),
        "card": ("#FFFFFF", "#FFFFFF"),
    }
    for name, (top_color, bottom_color) in asset_colors.items():
        # Ttk's Windows image element can render transparent PhotoImage edges
        # as black; draw onto an opaque RGB surface to avoid that artifact.
        colors_background = (
            _PALETTE["background"] if name == "card" else "#F5F8FD"
        )
        image = Image.new("RGB", (width, height), colors_background)
        draw = ImageDraw.Draw(image)
        red_top, green_top, blue_top = _hex_rgb(top_color)
        red_bottom, green_bottom, blue_bottom = _hex_rgb(bottom_color)
        left = 1
        right = width - 2
        bottom = height - _GLOSS_BOTTOM_INSET
        for y in range(bottom + 1):
            corner_row = min(
                y,
                bottom - y,
                len(_GLOSS_CORNER_ROW_INSETS) - 1,
            )
            inset = _GLOSS_CORNER_ROW_INSETS[corner_row]
            progress = y / max(1, bottom)
            color = (
                round(red_top + (red_bottom - red_top) * progress),
                round(green_top + (green_bottom - green_top) * progress),
                round(blue_top + (blue_bottom - blue_top) * progress),
            )
            draw.line((left + inset, y, right - inset, y), fill=color)
        draw.rounded_rectangle(
            (1, 0, width - 2, bottom),
            radius=_GLOSS_CORNER_RADIUS,
            outline=(255, 255, 255) if name != "card"
            else (220, 230, 242),
            width=1,
        )
        if name in ("focus", "primary_focus"):
            draw.rounded_rectangle(
                (0, 0, width - 1, bottom),
                radius=_GLOSS_CORNER_RADIUS,
                outline=(129, 140, 248), width=2,
            )
        _THEME_IMAGES[name] = ImageTk.PhotoImage(image, master=root)

    options = {
        "border": (border, border, border, border),
        "sticky": "nsew",
    }
    style.element_create(
        "Gloss.button", "image", _THEME_IMAGES["button"],
        ("disabled", _THEME_IMAGES["disabled"]),
        ("pressed", _THEME_IMAGES["pressed"]),
        ("active", _THEME_IMAGES["active"]),
        ("focus", _THEME_IMAGES["focus"]),
        **options,
    )
    style.element_create(
        "Gloss.primary", "image", _THEME_IMAGES["primary"],
        ("disabled", _THEME_IMAGES["disabled_primary"]),
        ("pressed", _THEME_IMAGES["primary_pressed"]),
        ("active", _THEME_IMAGES["primary_active"]),
        ("focus", _THEME_IMAGES["primary_focus"]),
        **options,
    )
    style.element_create(
        "Gloss.card", "image", _THEME_IMAGES["card"], **options
    )
    style.layout("Glass.TFrame", [("Gloss.card", {"sticky": "nsew"})])
    style.layout("Footer.TFrame", [("Gloss.card", {"sticky": "nsew"})])
    for name, element in (
        ("TButton", "Gloss.button"),
        ("Primary.TButton", "Gloss.primary"),
        ("Icon.TButton", "Gloss.button"),
        ("IconPrimary.TButton", "Gloss.primary"),
    ):
        style.layout(name, [(
            element,
            {"sticky": "nsew", "children": [
                ("Button.padding", {"sticky": "nsew", "children": [
                    ("Button.label", {"sticky": "nsew"}),
                ]}),
            ]},
        )])


def _hex_rgb(value: str) -> tuple[int, int, int]:
    """Convert a six-digit hexadecimal color to its RGB channels.

    Args:
        value (str): Color in ``#RRGGBB`` form.

    Returns:
        tuple[int, int, int]: Red, green and blue channels from 0 to 255.
    """
    return (int(value[1:3], 16), int(value[3:5], 16),
            int(value[5:7], 16))


def _install_focus_styles(style: ttk.Style) -> None:
    """Set visible focus cues for keyboard users across interactive controls.

    Args:
        style (ttk.Style): Style registry configured for the application.

    Returns:
        None: Focus maps are added to the supplied style registry.
    """
    focus = _PALETTE["focus"]
    for name in ("TButton", "Primary.TButton", "Icon.TButton",
                 "IconPrimary.TButton", "TEntry", "TCombobox"):
        style.map(name, bordercolor=[("focus", focus)])


def icon_button(parent: tk.Misc, icon: str,
                command: Callable[[], None], tooltip: str,
                primary: bool = False) -> ttk.Button:
    """Create a compact icon button with an accessible tooltip.

    Args:
        parent (tk.Misc): Tk widget that owns the new button.
        icon (str): Semantic icon key, single glyph or short visible label.
        command (Callable[[], None]): Action invoked by mouse or keyboard.
        tooltip (str): Concise accessible description shown on hover/focus.
        primary (bool): Whether to use the indigo primary button style.

    Returns:
        ttk.Button: The themed, bound button widget.
    """
    glyph = _ICON_GLYPHS.get(icon, icon)
    icon_only = icon in _ICON_GLYPHS or (
        len(icon.strip()) == 1
        and not any(character.isspace() for character in icon)
    )
    style = (
        "IconPrimary.TButton" if primary else "Icon.TButton"
    ) if icon_only else ("Primary.TButton" if primary else "TButton")
    button = ttk.Button(parent, text=glyph, command=command, style=style,
                        takefocus=True,
                        width=_ICON_BUTTON_COLUMNS if icon_only else -1)
    button.configure(cursor="hand2")
    if tooltip:
        _attach_tooltip(button, tooltip)
    return button


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
                         tooltip=f"Help: {title}")
    return button


class GlassCard(ttk.Frame):
    """White, padded ttk frame for grouping related uploader controls.

    The card uses the shared white surface style. Callers can pass standard
    ttk.Frame keyword arguments such as ``padding`` and ``width``.
    """

    def __init__(
        self,
        parent: tk.Misc,
        **kwargs: str | int | bool | tuple[int, ...] | tk.Misc | ThemeImage,
    ) -> None:
        """Create a themed card frame.

        Args:
            parent (tk.Misc): Parent widget that owns the card.
            **kwargs (str | int | bool | tuple[int, ...] | tk.Misc | ThemeImage):
                Standard ttk.Frame options such as style, padding, dimensions
                and an optional Tk image.

        Returns:
            None: The initialized frame is ready to receive child widgets.
        """
        kwargs.setdefault("style", "Glass.TFrame")
        super().__init__(parent, **kwargs)


@dataclass(slots=True)
class _TooltipState:
    """Track the exact delayed callback and popup owned by one tooltip."""

    timer: str | None = None
    window: tk.Toplevel | None = None


def _attach_tooltip(widget: ttk.Button, message: str) -> None:
    """Bind a delayed tooltip to pointer and keyboard focus events.

    Args:
        widget (ttk.Button): Interactive widget that owns tooltip bindings.
        message (str): Short text displayed in the tooltip window.

    Returns:
        None: Bindings manage popup creation, dismissal and destruction.
    """
    state = _TooltipState()

    def hide(_event: tk.Event | None = None) -> None:
        """Cancel pending display and close the tooltip popup.

        Args:
            _event (tk.Event | None): Optional Tk event that caused
                dismissal.

        Returns:
            None: Any scheduled callback and popup are safely discarded.
        """
        timer = state.timer
        if timer is not None:
            try:
                widget.after_cancel(timer)
            except tk.TclError:
                pass
        state.timer = None
        popup = state.window
        if popup is not None:
            try:
                if popup.winfo_exists():
                    popup.destroy()
            except tk.TclError:
                pass
        state.window = None

    def show() -> None:
        """Create the tooltip if the widget remains alive and eligible.

        Returns:
            None: A small tooltip is shown beside the active widget.
        """
        state.timer = None
        popup: tk.Toplevel | None = None
        try:
            if not widget.winfo_exists() or not widget.winfo_ismapped():
                return
            popup = tk.Toplevel(widget)
            state.window = popup
            popup.wm_overrideredirect(True)
            popup.attributes("-topmost", True)
            label = tk.Label(
                popup,
                text=message,
                bg=_PALETTE["ink"],
                fg="#FFFFFF",
                padx=9,
                pady=6,
                font=("Segoe UI", 9),
                justify="left",
                wraplength=_HELP_TEXT_WRAP,
            )
            label.pack()
            x = widget.winfo_rootx() + 8
            y = widget.winfo_rooty() + widget.winfo_height() + 6
            popup.geometry(f"+{x}+{y}")
            state.window = popup
        except tk.TclError:
            if popup is not None:
                try:
                    if popup.winfo_exists():
                        popup.destroy()
                except tk.TclError:
                    pass
            state.window = None

    def schedule(_event: tk.Event | None = None) -> None:
        """Schedule tooltip display after the shared hover delay.

        Args:
            _event (tk.Event | None): Optional event that triggered
                pointer entry or keyboard focus.

        Returns:
            None: Existing tooltip state is replaced with one safe timer.
        """
        hide()
        try:
            state.timer = widget.after(_TOOLTIP_DELAY_MS, show)
        except tk.TclError:
            state.timer = None

    widget.bind("<Enter>", schedule, add="+")
    widget.bind("<Leave>", hide, add="+")
    widget.bind("<FocusIn>", schedule, add="+")
    widget.bind("<FocusOut>", hide, add="+")
    widget.bind("<Destroy>", hide, add="+")


def _show_help(owner: tk.Misc, title: str, body: str) -> None:
    """Display a modal-style help panel with selectable content.

    Args:
        owner (tk.Misc): Widget whose toplevel owns the help dialog.
        title (str): Concise title shown in the dialog heading and chrome.
        body (str): Plain text content users may select or copy.

    Returns:
        None: The help overlay is focused and dismissed by Escape or close.
    """
    try:
        parent = owner.winfo_toplevel()
        previous_grab = parent.grab_current()
        previous_focus = parent.focus_get()
        dialog = tk.Toplevel(parent)
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

    dialog.title(title or "Help")
    dialog.configure(background=_PALETTE["surface"])
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
        font=("Segoe UI", 10),
        takefocus=True,
    )
    text_box.insert("1.0", body)
    text_box.pack(fill="both", expand=True, anchor="w")
    text_box.bind("<Control-a>", lambda _event: _select_help_text(text_box))
    text_box.bind("<Key>", lambda event: _guard_help_text(event))
    text_box.bind("<<Paste>>", lambda _event: "break")
    text_box.bind("<<Cut>>", lambda _event: "break")
    text_box.bind("<Button-2>", lambda _event: "break")
    footer = ttk.Frame(shell, style="Glass.TFrame")
    footer.pack(fill="x", pady=(14, 0))
    close_button = ttk.Button(footer, text="Close", command=close_help)
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


