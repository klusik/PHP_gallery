# Shared public and Admin UI design contract

This document is the mandatory visual design authority for **every new or
changed public and Admin interface**. Apply it automatically before writing UI,
without asking the user to repeat the design requirements. Existing global
Admin styles are legacy
implementation constraints, not the design reference. Scope CSS to the feature
and override those legacy rules where needed; do not redesign unrelated Admin
pages as part of a focused change.

Contextual notifications, help and small action menus use a compact translucent
panel anchored beside or below the control that opened it. Keep the surrounding
page visible and usable, dismiss on outside click/Escape, and return keyboard
focus appropriately. Do not replace this interaction with a centered blocking
modal or dimmed page unless the task explicitly requires that interaction.
Use small transparent actions, mild rounding and Theme foreground/panel tokens.
Automatically inspect actual desktop and narrow-screen rendering, including the
production cascade, before handoff; source review alone is insufficient.

The approved visitor notification panel is the **default reference for new
contextual panels**, together with the PDF/music launchers. Reuse its production
markup/CSS treatment automatically; do not invent a different popup skin.
The panel follows the PDF/music launcher surface: mix the
Theme panel at 42% over the page, retain readable backdrop blur, and use Theme
line separators and compact 0.85rem heading text with a small accent icon.
Give the read-all action the presentation launcher's transparent outlined
treatment directly beside the notification list, shown only with unread news.
Keep the independent visit-completion toggle in the footer and the local-storage
note subordinate. Default to marking all galleries seen; offer the reverse only
when every authorized gallery is seen. Neither action changes the other state.
Omit technical index warnings from this public overview; unavailable evidence
must not be labelled as an empty verified notification list.
Without unread news, omit its whole heading/section and retain only the compact
visit action, adjacent Close control and subordinate local-storage note.
Keep the bell a quick overview with these two distinct bulk actions; individual
branch visit changes belong directly on the inline title status icon. Status
glyphs stay small but distinct: dotted circle, half-filled circle, checked circle
and sparkle. Give the unvisited state the strongest Theme accent and restrained
glow; keep completed checkmarks quieter. Center glyphs optically on the final
title line rather than aligning their bottoms with the text baseline.
Let notification panels grow naturally until they fill the viewport height,
keeping a small edge gap. Reposition upward before clipping a long list. Only
then scroll the notification list, with the heading and bulk actions retained.

## Visual language

- Use a compact, icon-first layout with translucent panels, mild rounding,
  a restrained active accent line, and a subtle active tint. The public PDF,
  music and presentation panels are the visual reference for these surfaces.
- Avoid framing individual controls or task rows. Help question marks are plain
  glyphs without circles, borders or button backgrounds. Separate content with
  space and subtle surface changes; reserve outlines for meaningful boundaries
  and visible keyboard focus.
- Follow the media player and WinApp visual language. Use
  `public/assets/styles/admin-gallery-media.css` and
  `public/assets/styles/admin-music.css` as the in-repository references, along
  with the current WinApp interface.
- Do not use large blue buttons with white text as prominent calls to action.
  Avoid hero panels and metric tiles for simple actions.
- Keep the toolbar on one level. Its control target is 28 px with a 16 px glyph;
  preserve comfortable, accessible tap interaction on mobile.
- Public-page Admin, Test run, Updates and Logout actions use compact,
  32 px-high transparent outlined controls with a 16 px icon and localized
  label. The Admin login action shown to anonymous visitors, including in
  anonymous previews, uses the same treatment and alignment as signed-in
  actions. Match the Show gallery map control, keep the treatment unfilled, and
  leave language choices governed by Theme settings.
- Do not underline Admin UI text, labels, links, or controls. Use color,
  spacing, icons, and borders to communicate hierarchy and interaction.

## Public search and pagination

Public search and pagination follow the same compact visual language as the
media panels and public header actions. Their shared surface tokens belong to
`public/assets/styles/public-shared.css`; search layout belongs to
`public/assets/styles/public.css`. Keep changes scoped to public pages.

Public tags, search text, map chrome and PDF/music panels use the Theme
foreground/panel pair. Accent hues remain decoration where small text would
otherwise lose contrast. Expanded information panels retain enough panel backing
to obscure underlying text. The map Close action uses the same transparent,
compact treatment as other public actions. Floating Top/Down controls use icons
with retained accessible labels; the widget geometry owner keeps floating panels
clear of primary gallery/photo previews as well as header and action controls.

- Use a translucent Theme panel surface, a quiet foreground-derived border,
  and rounding capped at 8 px while respecting smaller Theme radii. Text,
  borders and accents follow the selected Theme, including dark themes.
- Pagination controls have a 32 px minimum target on desktop. Indicate the
  current page with a restrained accent tint and a muted 1 px inset line;
  keep the label in the normal foreground color. Disabled
  controls remain visibly muted; the page count has a small readable surface
  over photographic backgrounds.
- Keep search flat and compact, with the scope switch and clear action in
  the same row on desktop. The selected scope has a subtle accent tint. The
  clear action has a transparent resting surface and is absent when hidden.
  Result panels use the same border and radius with a denser readable surface.
  Desktop search is 32 px high, including its border and visible clear action.
  On the administrator's home-page creation toolbar, keep the adjacent create
  action at the same height, including when search is disabled. The mobile
  interaction minimum remains at least 44 px.
- Show a visible keyboard outline on pagination links, the scope option and
  the clear action. Search focus highlights the field boundary and accent line.
- At viewport widths up to 640 px, use 44 px minimum interaction targets and
  place the scope option on its own row. Pagination wraps naturally without
  horizontal scrolling. These changes preserve the existing search behavior,
  native checkbox semantics, page URLs and no-JavaScript pagination.

## Public Picture manager HUD

Hide an empty manager above the viewport, retaining an invisible 8px top-edge
hover target. Hover reveals and opens it; a nonempty selection keeps it visible.
Visible keyboard focus and an open help popover also preserve access. Touch
users reveal it by selecting a card. Keep the summary narrow when collapsed
and the expanded task surface bounded independently of gallery page width.
Selection actions stay on one
line where space allows, followed by the destination picker and Move/Copy.
Creation uses a native disclosure rather than a permanently visible second
form. The parent picker, gallery title and optional folder remain available
inside it. Keep field labels and accessible names independent of placeholders.

Selection gestures and copy/move explanations belong behind a plain `?`
auto-popover, with native keyboard activation, Escape and outside-click
dismissal. The shared picker retains its search, directory link and failure
feedback; routine instructions and its directory link appear while focused.
Idle status is quiet; progress, success and errors remain visible. Scope all
styles to the Picture manager and use Theme `--ink`, panel and accent tokens.
Use 28px desktop and 44px mobile controls with visible keyboard focus.

## Controls and layout

The presentation share overlay follows these same public/Admin tokens: a
translucent Theme surface, at most 8px rounding, quiet foreground-derived lines,
26 by 14px switches and thin unboxed ranges. Copy/Close are transparent 28px
icon actions with 16px glyphs; narrow/touch layouts use 44px interaction targets.
The URL field keeps a restrained translucent surface and enough width for a
short root link. Preserve visible keyboard focus and native forced-color
controls. Scope overrides to this overlay, including its retained Admin
ancestors, so legacy white fields and filled blue buttons cannot take over.

The presentation launch confirmation uses the same translucent Theme panel and
compact transparent Start/Close actions, with 28px desktop targets, 16px glyphs
and 44px narrow/touch targets. Keep the localized Start label, visible keyboard
focus, muted disabled state and system colors in forced-color mode.

- Use short labels with small `?` context-help controls for longer explanations.
  Help must open by mouse click and keyboard activation, close on outside click
  and Escape, and expose an accessible name and relationship to its content.
- Use standard switches for binary settings. An autosaved setting has no second
  Save button; show its saving, saved, and failure state where users can see it.
- Progressively replace visible checkbox boxes with small switches when their
  owning interface is edited. Apply this direction across public and Admin
  interfaces, preserving native input behavior, labels, keyboard operation,
  checked/disabled state and form submission. Use a compact 26 by 14 px track
  with an 8 px thumb as the public search reference; the surrounding label
  provides the comfortable click/tap target. Keep the active tint restrained,
  including in dark themes. Respect forced-color settings with a visible native
  control. Keep each migration within its owning feature's scope.
- Group work into logical tabs or sections. Use a fluid grid that gives each
  control the space it needs; keep items inline when they fit and do not impose
  a two-column cap.
- Keep common actions and important status visible. Put technical detail behind
  contextual help or a disclosure when it is not needed for the ordinary task.

## Responsive Admin navigation

Preserve the existing expanded vertical menu as the desktop default. Its compact
transparent double-chevron action collapses labels into the existing icon rail;
group separators, accessible names and current-page indication remain available.
Both desktop states scroll with the document, with no independent menu
scrollbar or viewport-height clipping. The final account action must remain
reachable through ordinary page scrolling and keyboard focus.
Keep this preference in browser-local storage, scoped to the installation asset
mount. Do not add an application setting or database write.

At the existing 1100px single-column workspace breakpoint, show a labelled
hamburger with a 44px target. Keep the same grouped navigation in a translucent
panel anchored directly below that trigger. Opening it overlays the content
without pushing the page down. Bound its own scrolling to the available viewport
and account for the real header height. Use the approved 42% Theme panel backing
and backdrop blur; avoid an ancestor blur that prevents the child panel from
blurring underlying content. Group headings and actions must remain readable.

Use ordinary labelled navigation landmarks and links. The mobile trigger exposes
`aria-controls` and `aria-expanded`; Tab/Shift+Tab retain native navigation.
Escape, outside click, leaving the navigation and selecting a destination close
the panel. Escape and an activated same-document destination restore trigger
focus. Returning to desktop restores its independent expanded/collapsed choice.
A new page always starts with the mobile disclosure closed. Preserve all links
when JavaScript is unavailable; unavailable storage keeps changes in memory.

## Implementation review

Inspect the actual production CSS cascade and computed specificity for the
changed controls. Legacy global Admin selectors can override new feature styles;
confirm the scoped feature rules win in the rendered interface. Review desktop
and mobile layouts, keyboard access, tap targets, focus visibility, and reduced
motion where animation is present. Keep the change within its feature scope.
