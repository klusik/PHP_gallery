# Admin UI design direction

This document is the visual design authority for new and materially changed
PHP Gallery Admin interfaces. Existing global Admin styles are legacy
implementation constraints, not the design reference. Scope CSS to the feature
and override those legacy rules where needed; do not redesign unrelated Admin
pages as part of a focused change.

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

## Controls and layout

- Use short labels with small `?` context-help controls for longer explanations.
  Help must open by mouse click and keyboard activation, close on outside click
  and Escape, and expose an accessible name and relationship to its content.
- Use standard switches for binary settings. An autosaved setting has no second
  Save button; show its saving, saved, and failure state where users can see it.
- Group work into logical tabs or sections. Use a fluid grid that gives each
  control the space it needs; keep items inline when they fit and do not impose
  a two-column cap.
- Keep common actions and important status visible. Put technical detail behind
  contextual help or a disclosure when it is not needed for the ordinary task.

## Implementation review

Inspect the actual production CSS cascade and computed specificity for the
changed controls. Legacy global Admin selectors can override new feature styles;
confirm the scoped feature rules win in the rendered interface. Review desktop
and mobile layouts, keyboard access, tap targets, focus visibility, and reduced
motion where animation is present. Keep the change within its feature scope.
