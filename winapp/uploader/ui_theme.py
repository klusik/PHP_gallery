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

# Purpose: Choose the source size for stretchable glass button assets.
# Type: tuple[int, int]; Units: pixels; Scope: nine-slice button artwork.
# Consumers: Pillow image generation and ttk image elements.
# Rationale: 52 by 30 px gives compact buttons a lightly rounded silhouette.
_BUTTON_IMAGE_SIZE = (52, 30)

# Purpose: Choose the source size for softly rounded frosted panel assets.
# Type: tuple[int, int]; Units: pixels; Scope: nine-slice frame artwork.
# Consumers: Pillow image generation and ttk image elements.
# Rationale: 68 by 38 px preserves smooth curves without raising card minima.
_PANEL_IMAGE_SIZE = (68, 38)

# Purpose: Keep toolbar glyph controls compact while retaining a circular end.
# Type: tuple[int, int]; Units: pixels; Scope: icon-only button artwork.
# Consumers: compact ttk icon button layouts.
# Rationale: 20 by 20 px plus a 6 px nine-slice rim yields a 32 px glyph pad.
_ICON_IMAGE_SIZE = (20, 20)

# Purpose: Preserve the rounded boundary of a compact icon image.
# Type: int; Units: pixels; Scope: icon button nine-slice artwork.
# Consumers: Gloss.icon and Gloss.iconPrimary ttk elements.
# Rationale: 6 px insets retain circular corners at the 20 px source size.
_ICON_BORDER = 6

# Purpose: Keep compact icon artwork circular after supersampling.
# Type: int; Units: pixels; Scope: icon button source artwork.
# Consumers: icon material image composition.
# Rationale: 5 px stays inside the 6 px nine-slice rim on every side.
_ICON_CORNER_RADIUS = 5

# Purpose: Preserve lightly rounded corners while stretching button images.
# Type: int; Units: pixels; Scope: nine-slice button artwork.
# Consumers: ttk button image element borders.
# Rationale: 8 px insets preserve corners without inflating button heights.
_BUTTON_BORDER = 8

# Purpose: Preserve rounded panel corners while stretching card images.
# Type: int; Units: pixels; Scope: nine-slice frame artwork.
# Consumers: Glass.TFrame and Footer.TFrame image element borders.
# Rationale: 12 px protects the card radius without inflating compact frames.
_PANEL_BORDER = 12

# Purpose: Lightly round compact buttons without a tall capsule silhouette.
# Type: int; Units: pixels; Scope: generated button artwork.
# Consumers: Pillow rounded rectangle drawing.
# Rationale: 6 px softens the rectangle while retaining compact toolbars.
_BUTTON_CORNER_RADIUS = 6

# Purpose: Give cards a softer silhouette than standard ttk frames.
# Type: int; Units: pixels; Scope: generated frosted panel artwork.
# Consumers: Pillow rounded rectangle drawing.
# Rationale: 10 px softens corners without wasting panel content area.
_PANEL_CORNER_RADIUS = 10

# Purpose: Render antialiased edges for the compact source theme images.
# Type: int; Units: scale factor; Scope: Pillow artwork generation.
# Consumers: button and panel resampling before Tk image creation.
# Rationale: 3x provides clean rounded edges at ordinary Windows DPI.
_ARTWORK_SCALE = 3

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

# Purpose: Blend cool ambient color into the opaque frosted panel surface.
# Type: float; Units: normalized blend amount; Scope: panel RGB composition.
# Consumers: Pillow card and inset panel renderer.
# Rationale: 0.55 leaves visible cool color while preserving dark-text contrast.
_FROST_TINT_BLEND = 0.55

# Purpose: Choose the uniform RGB body between the frosted edge gradients.
# Type: float; Units: normalized blend amount; Scope: nine-slice center pixels.
# Consumers: uniform card, inset and button element centers.
# Rationale: 0.5 balances the light top and cool lower rim without tile bands.
_MATERIAL_CENTER_BLEND = 0.5

# Purpose: Compose a soft cool shadow into opaque frame art before Tk display.
# Type: float; Units: normalized color blend; Scope: panel outer edge shading.
# Consumers: frosted panel artwork composition.
# Rationale: 0.10 adds separation without a dark baseline or alpha pixels.
_PANEL_SHADOW_BLEND = 0.10

# Purpose: Soften the composited panel shadow at its edge.
# Type: int; Units: source pixels; Scope: panel RGB artwork.
# Consumers: Pillow Gaussian blur radius after supersampling.
# Rationale: 2 px yields a quiet halo after 3x source rendering.
_PANEL_SHADOW_BLUR = 2

# Purpose: Define shared colors for the uploader's dark glass hierarchy.
# Type: dict[str, str]; Units: color strings; Scope: uploader widget palette.
# Consumers: ttk style registration and card defaults.
# Rationale: light text and cyan actions remain legible on slate panels;
#     semantic colors distinguish success, attention and failures.
_PALETTE = {
    "background": "#131B26",
    "surface": "#182330",
    "surface_alt": "#223344",
    "card_surface": "#253443",
    "card_surface_alt": "#2B3D4D",
    "ink": "#F2F6FA",
    "muted": "#AABCCB",
    "line": "#3D5264",
    "primary": "#08AED0",
    "primary_hover": "#22C3E2",
    "primary_pressed": "#008AA8",
    "focus": "#8BE4F2",
    "success": "#46D68C",
    "warning": "#F2C581",
    "danger": "#FF91A2",
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

# Purpose: Give all boolean controls the same compact sliding-switch shape.
# Type: tuple[int, int]; Units: pixels; Scope: shared ttk switch artwork.
# Consumers: _install_switch_elements.
# Rationale: 38 by 22 px leaves a visible thumb and comfortable label spacing.
_SWITCH_IMAGE_SIZE = (38, 22)

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
    # Secondary Tk windows and native menu/popdown surfaces do not inherit ttk
    # frame colors. Set their defaults before any settings sheets are created.
    root.option_add("*Toplevel.background", colors["background"])
    root.option_add("*Menu.background", colors["surface"])
    root.option_add("*Menu.foreground", colors["ink"])
    root.option_add("*Menu.activeBackground", colors["surface_alt"])
    root.option_add("*Menu.activeForeground", colors["ink"])
    root.option_add("*TCombobox*Listbox.background", colors["surface"])
    root.option_add("*TCombobox*Listbox.foreground", colors["ink"])
    style.configure(".", background=colors["background"],
                    foreground=colors["ink"], font=("Segoe UI", 10))
    # Unqualified inner frames live inside cards. App/page containers select
    # App.TFrame or Backdrop.TFrame explicitly so toolbars never cut dark strips
    # through an otherwise continuous panel surface.
    style.configure("TFrame", background=colors["card_surface"])
    style.configure("TLabel", background=colors["card_surface"],
                    foreground=colors["ink"])
    style.configure("App.TLabel", background=colors["background"],
                    foreground=colors["ink"])
    style.configure("Card.TLabel", background=colors["card_surface"],
                    foreground=colors["ink"])
    style.configure("Muted.TLabel", background=colors["card_surface"],
                    foreground=colors["muted"])
    style.configure("Title.TLabel", font=("Segoe UI Semibold", 20),
                    background=colors["card_surface"],
                    foreground=colors["ink"])
    style.configure("Glass.Title.TLabel",
                    font=("Segoe UI Semibold", 20),
                    background=colors["card_surface"],
                    foreground=colors["ink"])
    style.configure("Brand.TLabel", font=("Segoe UI Semibold", 18),
                    background=colors["background"],
                    foreground=colors["ink"])
    style.configure("Eyebrow.TLabel", font=("Segoe UI Semibold", 8),
                    background=colors["card_surface"],
                    foreground=colors["muted"])
    style.configure("Status.TLabel", font=("Segoe UI Semibold", 10),
                    background=colors["card_surface"],
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
    style.configure("Warning.TLabel",
                    background=colors["card_surface_alt"],
                    foreground=colors["warning"], padding=(10, 8))
    style.configure("Body.TLabel", background=colors["card_surface"],
                    foreground=colors["ink"])
    style.configure("Footer.TLabel", background=colors["card_surface"],
                    foreground=colors["muted"])
    style.configure("Hero.TLabel", font=("Segoe UI Semibold", 22),
                    background=colors["card_surface"],
                    foreground=colors["ink"])
    style.configure("HeroTarget.TLabel",
                    font=("Segoe UI Semibold", 11),
                    background=colors["card_surface"],
                    foreground=colors["primary"])
    style.configure("Large.TEntry", font=("Segoe UI", 11),
                    padding=(10, 9))
    style.configure("App.TFrame", background=colors["background"])
    style.configure("Backdrop.TFrame", background=colors["background"],
                    relief="flat", borderwidth=0, padding=0)
    style.configure("Footer.TFrame",
                    background=colors["card_surface"],
                    relief="flat", borderwidth=0, padding=(12, 8))
    style.configure("Section.TLabel", font=("Segoe UI Semibold", 12),
                    background=colors["card_surface"],
                    foreground=colors["ink"])
    style.configure("Glass.TFrame", background=colors["card_surface"],
                    relief="flat", borderwidth=0)
    style.configure("GlassInset.TFrame",
                    background=colors["card_surface_alt"],
                    relief="flat", borderwidth=0)
    style.configure("TButton", padding=(12, 6), borderwidth=0,
                    background=colors["card_surface"],
                    foreground=colors["ink"])
    style.configure("Card.TFrame", background=colors["card_surface"],
                    relief="flat", borderwidth=0, padding=0)
    style.map("TButton",
              background=[("pressed", colors["line"]),
                          ("active", colors["surface_alt"])],
              foreground=[("disabled", colors["muted"])])
    style.configure("Primary.TButton", padding=(12, 6), borderwidth=0,
                    background=colors["primary"], foreground="#F2F6FA",
                    font=("Segoe UI Semibold", 10))
    style.map("Primary.TButton",
              background=[("pressed", colors["primary_pressed"]),
                          ("active", colors["primary_hover"])],
              foreground=[("disabled", "#D8D7FA")])
    style.configure("Icon.TButton", padding=(2, 2),
                    font=("Segoe UI Symbol", 12),
                    background=colors["card_surface"],
                    foreground=colors["ink"])
    style.configure("IconPrimary.TButton", padding=(3, 3),
                    font=("Segoe UI Symbol", 12),
                    background=colors["primary"], foreground="#F2F6FA")
    style.map("IconPrimary.TButton",
              foreground=[("disabled", "#F0EFFC")],
              background=[("pressed", colors["primary_pressed"]),
                          ("active", colors["primary_hover"])])
    style.map("Icon.TButton",
              foreground=[("disabled", colors["muted"])])
    style.configure("TEntry", fieldbackground=colors["surface"],
                    foreground=colors["ink"], insertcolor=colors["ink"],
                    bordercolor=colors["line"], lightcolor=colors["line"],
                    darkcolor=colors["line"], padding=(9, 7))
    style.map("TEntry", bordercolor=[("focus", colors["focus"])])
    style.configure("TCombobox", fieldbackground=colors["surface"],
                    background=colors["surface"], foreground=colors["ink"],
                    padding=(8, 6), arrowcolor=colors["muted"],
                    bordercolor=colors["line"], lightcolor=colors["line"],
                    darkcolor=colors["line"])
    style.map("TCombobox",
              fieldbackground=[("readonly", colors["surface"])],
              foreground=[("readonly", colors["ink"])],
              selectbackground=[("readonly", colors["surface_alt"])],
              selectforeground=[("readonly", colors["ink"])])
    style.configure("TMenubutton", background=colors["surface"],
                    foreground=colors["ink"], arrowcolor=colors["muted"],
                    padding=(8, 6), borderwidth=0, relief="flat")
    style.configure("TCheckbutton", background=colors["card_surface"],
                    foreground=colors["ink"], padding=4)
    style.configure("App.TCheckbutton",
                    background=colors["background"],
                    foreground=colors["ink"], padding=4)
    style.configure("Card.TCheckbutton",
                    background=colors["card_surface"],
                    foreground=colors["ink"], padding=4)
    style.map("TCheckbutton", foreground=[("disabled", colors["muted"])])
    _install_switch_elements(root, style)
    # The icon rail owns app navigation; nested Activity tabs stay visible.
    style.layout("App.TNotebook.Tab", [])
    style.configure("TRadiobutton", background=colors["card_surface"],
                    foreground=colors["ink"], padding=4)
    style.configure("App.TRadiobutton",
                    background=colors["background"],
                    foreground=colors["ink"], padding=4)
    style.configure("Card.TRadiobutton",
                    background=colors["card_surface"],
                    foreground=colors["ink"], padding=4)
    style.configure(
        "Language.TRadiobutton", padding=(10, 5),
        background=colors["card_surface"], foreground=colors["muted"],
        font=("Segoe UI Semibold", 9),
    )
    style.map("Language.TRadiobutton", foreground=[
        ("disabled", colors["muted"]),
        ("selected", colors["ink"]),
        ("focus", colors["focus"]),
        ("active", colors["focus"]),
    ])
    segment_content = [("Radiobutton.padding", {
        "sticky": "nswe", "children": [
            ("Radiobutton.label", {"sticky": "nswe"}),
        ],
    })]
    style.layout("Language.TRadiobutton", (
        [("Gloss.segment", {"sticky": "nswe", "children": segment_content})]
        if "Gloss.segment" in style.element_names() else segment_content
    ))
    style.configure("TLabelframe", background=colors["card_surface"],
                    bordercolor=colors["line"], relief="flat")
    style.configure("TLabelframe.Label",
                    background=colors["card_surface"],
                    foreground=colors["muted"],
                    font=("Segoe UI Semibold", 10))
    style.configure("TNotebook", background=colors["background"],
                    borderwidth=0, bordercolor=colors["background"],
                    lightcolor=colors["background"],
                    darkcolor=colors["background"],
                    tabmargins=(0, 0, 0, 0))
    style.configure("App.TNotebook", background=colors["background"],
                    borderwidth=0, bordercolor=colors["background"],
                    lightcolor=colors["background"],
                    darkcolor=colors["background"],
                    tabmargins=(0, 0, 0, 0))
    style.configure("TNotebook.Tab", padding=(10, 6),
                    background=colors["card_surface_alt"],
                    foreground=colors["muted"])
    style.map("TNotebook.Tab",
              background=[("selected", colors["surface"])],
              foreground=[("selected", colors["primary"])])
    style.configure("Treeview", rowheight=30, borderwidth=0,
                    background=colors["surface"],
                    fieldbackground=colors["surface"],
                    foreground=colors["ink"],
                    bordercolor=colors["surface"],
                    lightcolor=colors["surface"],
                    darkcolor=colors["surface"])
    style.configure("Treeview.Heading", padding=(9, 7),
                    background=colors["surface_alt"],
                    foreground=colors["muted"],
                    bordercolor=colors["surface_alt"],
                    lightcolor=colors["surface_alt"],
                    darkcolor=colors["surface_alt"], borderwidth=0,
                    relief="flat",
                    font=("Segoe UI Semibold", 9))
    style.map("Treeview.Heading",
              background=[("active", colors["line"])],
              foreground=[("active", colors["ink"])],
              relief=[("active", "flat"), ("!active", "flat")])
    style.map("Treeview", background=[("selected", colors["surface_alt"])],
              foreground=[("selected", colors["ink"])])
    style.configure("Horizontal.TProgressbar",
                    background=colors["primary"],
                    troughcolor=colors["line"], borderwidth=0,
                    thickness=8)
    style.configure("Vertical.TScrollbar",
                    background=colors["card_surface_alt"],
                    troughcolor=colors["background"],
                    bordercolor=colors["background"], borderwidth=0,
                    arrowsize=12, arrowcolor=colors["muted"])
    style.configure("Horizontal.TScrollbar",
                    background=colors["card_surface_alt"],
                    troughcolor=colors["background"],
                    bordercolor=colors["background"], borderwidth=0,
                    arrowsize=12, arrowcolor=colors["muted"])
    for orientation, sticky in (("Vertical", "ns"), ("Horizontal", "ew")):
        name = orientation + ".TScrollbar"
        style.configure(
            name, arrowsize=10, relief="flat", gripcount=0,
            lightcolor=colors["line"], darkcolor=colors["line"],
        )
        style.map(name, background=[
            ("active", colors["muted"]),
            ("!active", colors["line"]),
        ])
        style.layout(name, [(orientation + ".Scrollbar.trough", {
            "sticky": sticky, "children": [
                (orientation + ".Scrollbar.thumb", {
                    "sticky": "nswe", "expand": True,
                }),
            ],
        })])
    _install_focus_styles(style)
    # Context actions are glyphs, not miniature boxed buttons. Preserve ttk
    # keyboard activation and focus while painting only a quiet hover surface.
    for name in ("Icon.TButton", "App.Icon.TButton", "IconPrimary.TButton"):
        style.layout(name, [("Button.padding", {
            "sticky": "nswe", "children": [
                ("Button.label", {"sticky": "nswe"}),
            ],
        })])
        style.configure(name, padding=(3, 3), borderwidth=0, relief="flat")
        style.map(name, foreground=[
            ("disabled", colors["muted"]),
            ("focus", colors["focus"]),
            ("active", colors["focus"]),
        ])
    style.configure("App.Icon.TButton", background=colors["background"])
    style.map("App.Icon.TButton", background=[
        ("active", colors["surface_alt"]),
        ("pressed", colors["surface"]),
    ])


def paint_backdrop(widget: ttk.Frame) -> None:
    """Match the container to the rounded artwork's opaque exterior matte.

    Args:
        widget (ttk.Frame): App, page or scrolling-content container.

    Returns:
        None: Applies the uniform backing color without resize canvases or
            rectangular color tiles around rounded card corners.
    """
    widget.configure(style="Backdrop.TFrame")


def _install_glossy_elements(root: tk.Tk, style: ttk.Style) -> None:
    """Register opaque glass artwork and bind it to reusable ttk styles.

    Args:
        root (tk.Tk): Tk interpreter that owns generated PhotoImage objects.
        style (ttk.Style): Theme registry receiving the image elements.

    Returns:
        None: Material elements and image references are installed, or ttk
            uses readable flat styles when optional Pillow is unavailable.
    """
    if "Gloss.card" in style.element_names():
        return
    try:
        from PIL import Image, ImageDraw, ImageFilter, ImageTk
    except ImportError:
        # Uploader runtime compatibility owner: Pillow is visual-only, so
        # source mode, --once and native repair stay supported without it.
        return

    scale = _ARTWORK_SCALE
    # Material artwork is composited into opaque RGB images before Tk sees it.
    # That keeps Windows ttk from treating transparent image pixels as black.
    frost_tint = _hex_rgb("#2A4353")
    glass_top = _blend_rgb(
        _hex_rgb("#243442"), frost_tint, _FROST_TINT_BLEND
    )
    glass_bottom = _blend_rgb(
        _hex_rgb("#263541"), _hex_rgb("#233544"), _FROST_TINT_BLEND
    )
    inset_top = _blend_rgb(
        _hex_rgb("#2B3D4D"), frost_tint, _FROST_TINT_BLEND
    )
    inset_bottom = _blend_rgb(
        _hex_rgb("#293B4A"), _hex_rgb("#2A4050"), _FROST_TINT_BLEND
    )
    asset_specs = {
        "button": (("#2B4051", "#263A4B"), _PALETTE["card_surface"],
                   _BUTTON_BORDER, _BUTTON_CORNER_RADIUS,
                   _BUTTON_IMAGE_SIZE),
        "button_active": (("#3A556A", "#33536A"), _PALETTE["card_surface"],
                          _BUTTON_BORDER, _BUTTON_CORNER_RADIUS,
                          _BUTTON_IMAGE_SIZE),
        "button_pressed": (("#1E3445", "#1B3040"), _PALETTE["card_surface"],
                           _BUTTON_BORDER, _BUTTON_CORNER_RADIUS,
                           _BUTTON_IMAGE_SIZE),
        "button_focus": (("#2B4051", "#263A4B"), _PALETTE["card_surface"],
                         _BUTTON_BORDER, _BUTTON_CORNER_RADIUS,
                         _BUTTON_IMAGE_SIZE),
        "button_disabled": (("#2B3742", "#26333E"), _PALETTE["card_surface"],
                             _BUTTON_BORDER, _BUTTON_CORNER_RADIUS,
                             _BUTTON_IMAGE_SIZE),
        "primary": (("#16BBDC", "#089ABA"), _PALETTE["card_surface"],
                    _BUTTON_BORDER, _BUTTON_CORNER_RADIUS,
                    _BUTTON_IMAGE_SIZE),
        "primary_active": (("#39CDE8", "#0EAACB"), _PALETTE["card_surface"],
                           _BUTTON_BORDER, _BUTTON_CORNER_RADIUS,
                           _BUTTON_IMAGE_SIZE),
        "primary_pressed": (("#079AB9", "#007F9D"), _PALETTE["card_surface"],
                            _BUTTON_BORDER, _BUTTON_CORNER_RADIUS,
                            _BUTTON_IMAGE_SIZE),
        "primary_focus": (("#16BBDC", "#089ABA"), _PALETTE["card_surface"],
                          _BUTTON_BORDER, _BUTTON_CORNER_RADIUS,
                          _BUTTON_IMAGE_SIZE),
        "primary_disabled": (("#446C79", "#345764"), _PALETTE["card_surface"],
                             _BUTTON_BORDER, _BUTTON_CORNER_RADIUS,
                             _BUTTON_IMAGE_SIZE),
        "icon": (("#2B4051", "#263A4B"), _PALETTE["card_surface"],
                 _ICON_BORDER, _ICON_CORNER_RADIUS, _ICON_IMAGE_SIZE),
        "icon_active": (("#3A556A", "#33536A"), _PALETTE["card_surface"],
                        _ICON_BORDER, _ICON_CORNER_RADIUS, _ICON_IMAGE_SIZE),
        "icon_pressed": (("#1E3445", "#1B3040"), _PALETTE["card_surface"],
                         _ICON_BORDER, _ICON_CORNER_RADIUS, _ICON_IMAGE_SIZE),
        "icon_focus": (("#2B4051", "#263A4B"), _PALETTE["card_surface"],
                       _ICON_BORDER, _ICON_CORNER_RADIUS, _ICON_IMAGE_SIZE),
        "icon_disabled": (("#2B3742", "#26333E"), _PALETTE["card_surface"],
                          _ICON_BORDER, _ICON_CORNER_RADIUS, _ICON_IMAGE_SIZE),
        "icon_primary": (("#16BBDC", "#089ABA"), _PALETTE["card_surface"],
                         _ICON_BORDER, _ICON_CORNER_RADIUS, _ICON_IMAGE_SIZE),
        "icon_primary_active": (("#39CDE8", "#0EAACB"), _PALETTE["card_surface"],
                                _ICON_BORDER, _ICON_CORNER_RADIUS,
                                _ICON_IMAGE_SIZE),
        "icon_primary_pressed": (("#079AB9", "#007F9D"), _PALETTE["card_surface"],
                                 _ICON_BORDER, _ICON_CORNER_RADIUS,
                                 _ICON_IMAGE_SIZE),
        "icon_primary_focus": (("#16BBDC", "#089ABA"), _PALETTE["card_surface"],
                               _ICON_BORDER, _ICON_CORNER_RADIUS,
                               _ICON_IMAGE_SIZE),
        "icon_primary_disabled": (("#446C79", "#345764"), _PALETTE["card_surface"],
                                  _ICON_BORDER, _ICON_CORNER_RADIUS,
                                  _ICON_IMAGE_SIZE),
        "tab": (("#243745", "#213241"), _PALETTE["background"],
                _BUTTON_BORDER, _BUTTON_CORNER_RADIUS, _BUTTON_IMAGE_SIZE),
        "tab_active": (("#365768", "#2B485C"), _PALETTE["background"],
                       _BUTTON_BORDER, _BUTTON_CORNER_RADIUS,
                       _BUTTON_IMAGE_SIZE),
        "panel": ((glass_top, glass_bottom), "#131B26",
                  _PANEL_BORDER, _PANEL_CORNER_RADIUS, _PANEL_IMAGE_SIZE),
        "inset": ((inset_top, inset_bottom), "#131B26",
                  _PANEL_BORDER, _PANEL_CORNER_RADIUS, _PANEL_IMAGE_SIZE),
    }
    for name, (gradient, matte, border, radius, size) in asset_specs.items():
        width, height = size
        scaled_size = (width * scale, height * scale)
        matte_rgb = _hex_rgb(matte)
        artwork = Image.new("RGB", scaled_size, matte_rgb)
        if name in ("panel", "inset"):
            shadow = _blend_rgb(
                matte_rgb, _hex_rgb("#0D131C"), _PANEL_SHADOW_BLEND
            )
            shadow_mask = Image.new("L", scaled_size, 0)
            shadow_draw = ImageDraw.Draw(shadow_mask)
            shadow_draw.rounded_rectangle(
                (scale * 2, scale * 2,
                 scaled_size[0] - scale * 2 - 1,
                 scaled_size[1] - scale * 2 - 1),
                radius=radius * scale,
                fill=255,
            )
            shadow_mask = shadow_mask.filter(
                ImageFilter.GaussianBlur(_PANEL_SHADOW_BLUR * scale)
            )
            artwork.paste(shadow, mask=shadow_mask)
        mask = Image.new("L", scaled_size, 0)
        mask_draw = ImageDraw.Draw(mask)
        scaled_radius = radius * scale
        mask_draw.rounded_rectangle(
            (scale, scale, scaled_size[0] - scale - 1,
             scaled_size[1] - scale - 1),
            radius=scaled_radius,
            fill=255,
        )
        top = (
            gradient[0] if isinstance(gradient[0], tuple)
            else _hex_rgb(gradient[0])
        )
        bottom = (
            gradient[1] if isinstance(gradient[1], tuple)
            else _hex_rgb(gradient[1])
        )
        center = (
            _hex_rgb(_PALETTE["card_surface"])
            if name == "panel" else
            _hex_rgb(_PALETTE["card_surface_alt"])
            if name == "inset" else
            _blend_rgb(top, bottom, _MATERIAL_CENTER_BLEND)
        )
        gradient_image = Image.new("RGB", scaled_size, center)
        gradient_draw = ImageDraw.Draw(gradient_image)
        scaled_border = border * scale
        for y in range(scaled_size[1]):
            if y < scaled_border:
                progress = y / max(1, scaled_border)
                row = _blend_rgb(top, center, progress)
            elif y >= scaled_size[1] - scaled_border:
                progress = (
                    y - (scaled_size[1] - scaled_border)
                ) / max(1, scaled_border - 1)
                row = _blend_rgb(center, bottom, progress)
            else:
                row = center
            gradient_draw.line(
                (0, y, scaled_size[0] - 1, y), fill=row
            )
        artwork.paste(gradient_image, mask=mask)
        draw = ImageDraw.Draw(artwork)
        draw.rounded_rectangle(
            (scale, scale, scaled_size[0] - scale - 1,
             scaled_size[1] - scale - 1),
            radius=scaled_radius,
            outline=_hex_rgb(_PALETTE["line"]),
            width=max(1, scale),
        )
        # The quiet slate edge supplies the rounded rim. Keep every
        # stretchable slice uniform to avoid dashed highlights from tiling.
        if name in (
            "button_focus", "primary_focus", "icon_focus",
            "icon_primary_focus",
        ):
            draw.rounded_rectangle(
                (0, 0, scaled_size[0] - 1, scaled_size[1] - 1),
                radius=scaled_radius,
                outline=_hex_rgb(_PALETTE["focus"]),
                width=scale * 2,
            )
        artwork = artwork.resize(size, Image.Resampling.LANCZOS)
        _THEME_IMAGES[name] = ImageTk.PhotoImage(artwork, master=root)

    button_border = _BUTTON_BORDER
    panel_border = _PANEL_BORDER
    button_options = {
        "border": (button_border, button_border,
                   button_border, button_border),
        "sticky": "nsew",
    }
    panel_options = {
        "border": (panel_border, panel_border,
                   panel_border, panel_border),
        "sticky": "nsew",
    }
    style.element_create(
        "Gloss.button", "image", _THEME_IMAGES["button"],
        ("disabled", _THEME_IMAGES["button_disabled"]),
        ("pressed", _THEME_IMAGES["button_pressed"]),
        ("active", _THEME_IMAGES["button_active"]),
        ("focus", _THEME_IMAGES["button_focus"]), **button_options,
    )
    style.element_create(
        "Gloss.primary", "image", _THEME_IMAGES["primary"],
        ("disabled", _THEME_IMAGES["primary_disabled"]),
        ("pressed", _THEME_IMAGES["primary_pressed"]),
        ("active", _THEME_IMAGES["primary_active"]),
        ("focus", _THEME_IMAGES["primary_focus"]), **button_options,
    )
    style.element_create(
        "Gloss.segment", "image", _THEME_IMAGES["button"],
        ("disabled", _THEME_IMAGES["button_disabled"]),
        ("selected", "focus", _THEME_IMAGES["primary_focus"]),
        ("selected", _THEME_IMAGES["primary"]),
        ("focus", _THEME_IMAGES["button_focus"]),
        ("active", _THEME_IMAGES["button_active"]), **button_options,
    )
    style.element_create(
        "Gloss.icon", "image", _THEME_IMAGES["icon"],
        ("disabled", _THEME_IMAGES["icon_disabled"]),
        ("pressed", _THEME_IMAGES["icon_pressed"]),
        ("active", _THEME_IMAGES["icon_active"]),
        ("focus", _THEME_IMAGES["icon_focus"]), **{
            "border": (_ICON_BORDER, _ICON_BORDER,
                       _ICON_BORDER, _ICON_BORDER),
            "sticky": "nsew",
        },
    )
    style.element_create(
        "Gloss.iconPrimary", "image", _THEME_IMAGES["icon_primary"],
        ("disabled", _THEME_IMAGES["icon_primary_disabled"]),
        ("pressed", _THEME_IMAGES["icon_primary_pressed"]),
        ("active", _THEME_IMAGES["icon_primary_active"]),
        ("focus", _THEME_IMAGES["icon_primary_focus"]), **{
            "border": (_ICON_BORDER, _ICON_BORDER,
                       _ICON_BORDER, _ICON_BORDER),
            "sticky": "nsew",
        },
    )
    style.element_create("Gloss.card", "image", _THEME_IMAGES["panel"],
                         **panel_options)
    style.element_create("Gloss.inset", "image", _THEME_IMAGES["inset"],
                         **panel_options)
    style.element_create(
        "Gloss.tab", "image", _THEME_IMAGES["tab"],
        ("selected", _THEME_IMAGES["tab_active"]),
        ("active", _THEME_IMAGES["tab_active"]), **button_options,
    )
    style.layout("Glass.TFrame", [("Gloss.card", {"sticky": "nsew"})])
    style.layout("Footer.TFrame", [("Gloss.card", {"sticky": "nsew"})])
    style.layout("GlassInset.TFrame", [("Gloss.inset", {"sticky": "nsew"})])
    style.layout("TNotebook.Tab", [(
        "Gloss.tab", {"sticky": "nsew", "children": [
            ("Notebook.padding", {"sticky": "nsew", "children": [
                ("Notebook.label", {"sticky": "nsew"}),
            ]}),
        ]},
    )])
    for name, element in (
        ("TButton", "Gloss.button"),
        ("Primary.TButton", "Gloss.primary"),
        ("Icon.TButton", "Gloss.icon"),
        ("IconPrimary.TButton", "Gloss.iconPrimary"),
    ):
        style.layout(name, [(
            element,
            {"sticky": "nsew", "children": [
                ("Button.padding", {"sticky": "nsew", "children": [
                    ("Button.label", {"sticky": "nsew"}),
                ]}),
            ]},
        )])


def _install_switch_elements(root: tk.Tk, style: ttk.Style) -> None:
    """Replace square checkbox indicators with keyboard-native pill switches.

    Args:
        root (tk.Tk): Interpreter owning the retained switch images.
        style (ttk.Style): Registry shared by all uploader boolean controls.

    Returns:
        None: Registers selected, disabled and focused switch states. Without
            Pillow, the existing ttk indicator remains usable in source mode.
    """
    if "Switch.indicator" in style.element_names():
        return
    try:
        from PIL import Image, ImageDraw, ImageTk
    except ImportError:
        # Presentation owner: source-mode dependency repair must stay usable
        # without Pillow. Retire only if Pillow becomes mandatory at launch.
        return
    width, height = _SWITCH_IMAGE_SIZE
    scale = _ARTWORK_SCALE
    for selected in (False, True):
        for state in ("normal", "focus", "disabled"):
            name = f"switch_{selected}_{state}"
            artwork = Image.new(
                "RGB", (width * scale, height * scale),
                _PALETTE["card_surface"],
            )
            draw = ImageDraw.Draw(artwork)
            track = _PALETTE["success"] if selected else "#607182"
            if state == "disabled":
                track = "#40525F"
            # Two-pixel inset leaves room for the keyboard-focus outline.
            draw.rounded_rectangle(
                (2 * scale, 2 * scale,
                 (width - 2) * scale, (height - 2) * scale),
                radius=(height // 2) * scale, fill=track,
            )
            thumb = height - 8
            left = width - thumb - 4 if selected else 4
            draw.ellipse(
                (left * scale, 4 * scale,
                 (left + thumb) * scale, (height - 4) * scale),
                fill="#F1F6FA" if state != "disabled" else "#8B9AA6",
            )
            if state == "focus":
                draw.rounded_rectangle(
                    (0, 0, width * scale - 1, height * scale - 1),
                    radius=(height // 2) * scale,
                    outline=_PALETTE["focus"], width=scale,
                )
            _THEME_IMAGES[name] = ImageTk.PhotoImage(
                artwork.resize((width, height), Image.Resampling.LANCZOS),
                master=root,
            )
    style.element_create(
        "Switch.indicator", "image", _THEME_IMAGES["switch_False_normal"],
        ("disabled", "selected", _THEME_IMAGES["switch_True_disabled"]),
        ("disabled", _THEME_IMAGES["switch_False_disabled"]),
        ("focus", "selected", _THEME_IMAGES["switch_True_focus"]),
        ("focus", _THEME_IMAGES["switch_False_focus"]),
        ("selected", _THEME_IMAGES["switch_True_normal"]),
        width=width + 6, sticky="w",
    )
    for name in ("TCheckbutton", "App.TCheckbutton", "Card.TCheckbutton"):
        style.layout(name, [("Checkbutton.padding", {
            "sticky": "nswe", "children": [
                ("Switch.indicator", {"side": "left", "sticky": "w"}),
                ("Checkbutton.label", {"side": "left", "sticky": "w"}),
            ],
        })])
        style.map(name, background=[("active", _PALETTE["card_surface"])])


def _hex_rgb(value: str) -> tuple[int, int, int]:
    """Convert a six-digit hexadecimal color to its RGB channels.

    Args:
        value (str): Color in ``#RRGGBB`` form.

    Returns:
        tuple[int, int, int]: Red, green and blue channels from 0 to 255.
    """
    return (int(value[1:3], 16), int(value[3:5], 16),
            int(value[5:7], 16))


def _blend_rgb(
    base: tuple[int, int, int],
    tint: tuple[int, int, int],
    amount: float,
) -> tuple[int, int, int]:
    """Blend two opaque RGB colors for a frosted surface tint.

    Args:
        base (tuple[int, int, int]): Opaque source channels in the 0–255 range.
        tint (tuple[int, int, int]): Ambient color channels in the 0–255 range.
        amount (float): Tint contribution from 0.0 through 1.0.

    Returns:
        tuple[int, int, int]: Rounded opaque RGB channels after composition.
    """
    return (
        round(base[0] * (1.0 - amount) + tint[0] * amount),
        round(base[1] * (1.0 - amount) + tint[1] * amount),
        round(base[2] * (1.0 - amount) + tint[2] * amount),
    )


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
        primary (bool): Whether to use the cyan primary action style.

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
    if icon_only and not primary:
        container_style = str(parent.cget("style"))
        background = ttk.Style(parent).lookup(container_style, "background")
        if background == _PALETTE["background"]:
            style = "App.Icon.TButton"
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
    """Frosted tinted ttk frame for grouping uploader controls.

    The rounded RGB material simulates a translucent card while keeping all
    actual Tk pixels opaque. Callers can pass standard ttk.Frame options.
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
                bg=_PALETTE["surface"],
                fg=_PALETTE["ink"],
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


def place_dialog(dialog: tk.Toplevel, owner: tk.Misc) -> None:
    """Center a child window over the window that opened it.

    Args:
        dialog (tk.Toplevel): Fully populated child window awaiting display.
        owner (tk.Misc): Invoking widget or its active application toplevel.
    Returns:
        None: Sets transient ownership and nearby requested-size geometry.
    """
    parent = owner.winfo_toplevel()
    dialog.transient(parent)
    dialog.update_idletasks()
    width, height = dialog.winfo_reqwidth(), dialog.winfo_reqheight()
    x = parent.winfo_rootx() + max(0, (parent.winfo_width() - width) // 2)
    y = parent.winfo_rooty() + max(0, (parent.winfo_height() - height) // 2)
    # Preserve the parent's monitor, including negative desktop coordinates.
    # winfo_screenwidth can describe only the primary monitor on Windows.
    # Tk's negative geometry offsets are measured from the right/bottom edge.
    # Convert absolute negative monitor coordinates instead of emitting '+-'.
    horizontal = f"+{x}" if x >= 0 else f"-{dialog.winfo_screenwidth() - width - x}"
    vertical = f"+{y}" if y >= 0 else f"-{dialog.winfo_screenheight() - height - y}"
    dialog.geometry(f"{width}x{height}{horizontal}{vertical}")


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
    footer = ttk.Frame(shell, style="Card.TFrame")
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


