# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/uploader/ui_palette.py
# Module Type: Desktop theme tokens
# Purpose: Preserve common color roles across both WinApp frontends.
# Responsibilities: Own the slate and teal palette independently of toolkit.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)

"""Define the shared desktop color roles."""

# Purpose: Select the same installed text face for the Windows UI.
# Type: tuple[str, ...]. Units: font names. Scope: both desktop toolkits.
# Consumers: configure_theme and apply_qt_theme. Rationale: preserves the
# original Tk font order, with platform defaults where unavailable.
UI_FONT_PREFERENCES = ("Segoe UI Variable Text", "Segoe UI Variable", "Segoe UI")
# Purpose: Share the reference text hierarchy without fixing native rendering.
# Type: int. Units: points. Scope: WinApp text, brand and small-label roles.
# Consumers: Tk and Qt themes. Rationale: preserve the Tk 10/18/8-point scale.
UI_BODY_POINTS = 10
UI_BRAND_POINTS = 18
UI_EYEBROW_POINTS = 8
# Purpose: Keep every card-format caption legible below its aspect icon.
# Type: int. Units: points. Scope: Tk and Qt Photos format buttons.
# Consumers: ui_theme and QtPhotos._make_format_button.
# Rationale: Eight points is the compact reference caption above the gallery grid.
UI_PHOTO_FORMAT_LABEL_POINTS = 8
# Purpose: Use the same rectangular format artwork in both gallery toolbars.
# Type: tuple[int, int]. Units: logical pixels. Scope: one format icon.
# Consumers: Tk PhotosWorkspace and QtPhotos.
# Rationale: The reference 28 by 18 silhouette fits above the readable label.
PHOTOS_CARD_FORMAT_ICON_SIZE = (28, 18)
# Purpose: Give native boolean controls the same slider proportions.
# Type: tuple[int, int]. Units: logical pixels. Scope: Tk and Qt switches.
# Consumers: ui_icons.render_toggle_switch, ui_theme, qt_theme.ToggleSwitch.
# Rationale: The 38 by 22 indicator leaves room for the thumb and focus rim.
UI_SWITCH_SIZE = (38, 22)
# Purpose: Separate the slider artwork from its readable caption.
# Type: int. Units: logical pixels. Scope: Tk and Qt switch layouts.
# Consumers: ui_theme._install_switch_elements, qt_theme.ToggleSwitch.
# Rationale: 6 matches the current Tk indicator-to-caption spacing.
UI_SWITCH_LABEL_GAP = 6

PALETTE: dict[str, str] = {
    "background": "#192330",
    "surface": "#202D3C",
    "surface_alt": "#283A4C",
    "card_surface": "#253443",
    "card_surface_alt": "#2B3D4D",
    "ink": "#F2F6FA",
    "muted": "#BAC8D6",
    "line": "#3D5264",
    "scrim": "#101923",
    "primary": "#08AED0",
    "primary_hover": "#22C3E2",
    "primary_pressed": "#008AA8",
    "primary_inactive_top": "#446C79",
    "primary_inactive_bottom": "#345764",
    "focus": "#8BE4F2",
    "success": "#46D68C",
    "warning": "#F2C581",
    "danger": "#FF91A2",
    "recording": "#FF6673",
    "recording_dim": "#B93A45",
}
