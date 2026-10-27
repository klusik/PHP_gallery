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
- Use standard switches and immediate autosave for binary Admin preferences,
  following the [Admin settings reference](#admin-settings-reference).
  Show saving, saved, and failure states beside the affected setting.
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
- Use the Features group surfaces and one to three flowing columns for Admin
  settings. Keep individual controls unboxed and each group's height independent
  of its neighbors. The detailed reference below defines the approved density,
  heading treatment, row order and persistence interaction.
- When a setting has its own detailed configuration, link both its visible
  name and its trailing settings arrow to the same prepared destination.
  Keep the switch and help trigger as independent controls.
- Keep common actions and important status visible. Put technical detail behind
  contextual help or a disclosure when it is not needed for the ordinary task.

## Admin settings reference

This is the default authoring pattern for new or changed Admin preference
interfaces. Its approved visual reference is **Appearance > Features**, with
the specialist settings interaction demonstrated by
**Dashboard > Maintenance > Content and display**, reached from the Public live
search settings link.
Use this reference automatically when editing the owning settings interface.
Older specialist pages may still await migration; the rule does not claim that
all existing Admin forms already autosave or authorize a wider redesign.

The pattern combines compact switches, immediate persistence, clear group
headings and one to three independent columns. Keep ordinary preferences
visible at a glance. Use the existing Theme tokens and the feature's existing
configuration/mutation owners when applying it elsewhere.

### Group surfaces and headings

Wrap related preferences in one semantic section with a short heading. Use the
heading level appropriate to its parent; the Content reference uses `h3`.
Frame the whole group once. Individual rows, switches and question marks retain
their flat appearance, with no nested cards or decorative shadows.

The following values describe the approved reference. CSS pixels (`px`) apply
to geometry and interaction targets; `rem` values follow the user's base text
size. All color mixes use the active Theme, including dark themes.

| Part | Reference treatment |
| --- | --- |
| Group surface | `color-mix(in srgb, var(--panel) 42%, transparent)`; `backdrop-filter: blur(12px)` |
| Group outline and row lines | `1px solid color-mix(in srgb, var(--ink) 12%, transparent)` |
| Group rounding | `min(var(--radius, 8px), 8px)`; respect a smaller Theme radius |
| Group inner spacing | `.2rem .65rem .35rem` at top, sides and bottom |
| Space between groups/columns | `.65rem`; no stretched group heights |
| Heading strip | At least `38px` high; `.25rem .65rem` padding; span the group's inner width |
| Heading tint | `color-mix(in srgb, var(--accent) 6%, transparent)` |
| Heading left accent | `2px solid color-mix(in srgb, var(--accent) 65%, transparent)` |
| Heading type | `var(--ink)`, `.85rem`, weight `650`, line height `1.25` |
| Setting rows | At least `32px` high on desktop; thin bottom separator, omitted on the last row |
| Setting names | Reference `.82rem`, weight `600`; permit natural wrapping |
| Subordinate information | Quiet foreground/panel mix; counts and compatibility remain readable |

Let a long heading or translated label grow vertically. Minimum heights are
density targets, not clipping boxes. Use a short page toolbar for the section
title, help and common actions; avoid repeated introductory paragraphs above
each group. The group heading is a label by default. A group master switch
belongs only to a real supported bulk preference operation, as in Features;
do not infer such an operation from matching the visual style.

### Flow and responsive behavior

Use whole groups in a top-to-bottom column flow, then continue in the next
column. Preserve this group order in the DOM and keyboard navigation. The Content
reference uses CSS multicolumn layout with `column-width: 300px`,
`column-count: 3` and `column-gap: .65rem`. The preferred width leaves room for
short names, help and compact controls; the count caps the workspace at three
columns. The browser fits one or two columns when three will not fit. Choose
the usable width from the actual workspace after Admin navigation and panel
padding, rather than assuming a viewport breakpoint alone determines it.

Keep each group together with `break-inside: avoid` and retain its natural
height. Do not arrange the groups in a shared table or shared grid rows, impose
equal heights, or reserve blank space beneath a short group to match a taller
neighbor. A local grid/flex row inside one group is appropriate for aligning its
own controls. Do not split a group or make each preference its own card merely
to fill columns.

On narrow screens, use a single column that can shrink below the preferred
column width. Allow labels, compound controls and the toolbar to wrap without
horizontal scrolling. At `max-width: 640px` or `pointer: coarse`, surrounding
switch labels, name links, help and action controls have at least `44px`
interaction height. The visible switch track remains compact. Avoid fixed
group heights and independent scroll areas inside ordinary settings blocks.

System health follows these flowing groups by user task: runtime/recovery,
access/sign-in, galleries/files, maps/routes, public features/reports, and tools.
Healthy storage rows use a quiet checkmark with an accessible state name;
configured-off integrations use a dash. Keep the check's purpose and test limits
in anchored question-mark help, and safe database identifiers/references in
Details. Put missing/unknown records before healthy peers and keep their impact
and next step visible. A storage snapshot must never imply an end-to-end test,
verified external connectivity, or blanket system readiness. Viewer diagnostics
retains guarded progressive autosave and its native POST/Save fallback.

Edit tags keeps the catalog, selected editor and usage references in independent
compact translucent groups. The catalog stays beside the editor on desktop;
the editor and usage can share the remaining row when their preferred 300px
width fits. Stack complete groups on narrow screens and retain an editor jump
beside the catalog heading. Catalog rows remain native links with an accessible
current selection. Repeated identical name/slug and filtered media-type labels
need no duplicate text. Field help opens beside the label; content edits retain
explicit Save, existing POST/CSRF fields and media-specific public actions.
Images and Galleries are direct-assignment filters over the shared visual tag
vocabulary; the filter help explains global edit scope and scoped counts.
Filter and sort selections submit their native GET form immediately after a
change; hide Apply only after enhancement, retaining it without JavaScript.
Changing usage clears the prior selection; sorting retains the selected tag.
Keep Documents visibly reserved and disabled until PDF tagging is implemented;
never present its placeholder as an available editor or verified empty catalog.


### Switches and row anatomy

Render binary preferences as switches, with no visible square checkbox in
normal themes. Retain the native input's checked/disabled state, associated
label, keyboard activation and form submission; a styled `input[type=checkbox]`
is the reference implementation. Under forced colors, preserve the visible
native control when needed for system contrast.

The reference switch has a `26px` by `14px` track and an `8px` thumb. Its quiet
off track mixes `--ink` at 8%; the on track mixes `--accent` at 23%, with a
restrained accent border. State remains distinguishable by the thumb position.
The label supplies a minimum `28px` desktop interaction height and the larger
narrow/touch target. Do not enlarge the visible track to create that target.

A single preference row reads visually as **switch, name, plain question mark,
subordinate status, trailing settings arrow** where those items apply. Keep
the name and its help close together. Avoid repeating Enabled/Disabled in a
separate visible column when the switch already conveys it; retain accessible
state and name. A compound setting keeps a short shared title and separately
labelled switches, wrapping onto a second line when useful. Each choice retains
its own meaning; the shared title does not become a new master preference.

Keep catalog/item selection controls governed by their selection semantics;
selecting a row is not a persisted binary preference. Explicit tasks such as
resetting gallery overrides or regenerating paths remain separate actions with
their existing safety requirements. The autosave rule concerns preferences and
does not turn bulk/destructive operations into switches.

### Names link to detailed settings

Whenever an item has a dedicated configuration destination, make the visible
item name a native link and retain the trailing settings arrow as a second
shortcut to **the same URL**. This is a general Admin rule, not an exception
for Public search. Use the prepared canonical destination for both links,
including the correct section, installation mount and locale. Do not maintain
two independently assembled routes for the same row.

Both links preserve the compact name typography and normal Theme foreground,
without an underline, filled button or extra explanatory text. Retain a visible
keyboard focus outline and accessible destination name for the icon-only
arrow. The link target grows to the narrow/touch minimum without enlarging the
glyph. Normal browser link behavior, including opening in another tab, remains
available with and without JavaScript.

The name opens configuration; the switch changes the preference; `?` opens
help. Keep these three interactions separate. Do not wrap the switch or help
inside the anchor, turn the entire row into a navigation click target, or use
the linked name as a switch label that also toggles on navigation. Where there
is no configuration destination, retain a plain name with no dead link/arrow.

### Immediate save and factory information

With JavaScript enabled, toggling a binary preference immediately sends its
change through the owning authenticated, CSRF-protected mutation route.
There is no second Save button, checkmark action, confirmation dialog or
page navigation for that preference. Enhance the existing form only when
autosave is bound; retain its classic Save/POST fallback without JavaScript.
Independent help and link navigation never submit the preference form.

Use compact, localized feedback beside the affected control, with an accessible
live status. Idle rows do not reserve an explanatory/status paragraph. During
saving, visibly mark the pending operation and temporarily disable the controls
in that form to serialize its write. Other groups remain usable. Show Saved
only after the server confirms the canonical persisted state; apply the
returned values and existing shared mutation-completion contract.

An authoritative refusal shows its reason and returned persisted values. A
timeout, lost response or malformed result leaves completion explicitly
unverified: keep the displayed intent, lock the affected form and offer a
passive state reload. Do not silently retry a write, report success or invent
a rollback when the server may already have committed it. The Content reference
bounds a write at `15000ms`; preserve the owning route's safety and reconciliation
contract when adopting this interaction elsewhere. Coupled fields travel with
their peer state rather than overwriting it with a guessed default.

The switch/label tooltip states **Factory default: Enabled/Disabled** using
localized text and canonical factory metadata from the setting's owner. Expose
the same information to assistive technology, as the reference does with an
accessible description. Factory means the declared installation default,
independent of the current saved value, effective capability state and upgrade
fallback. Never infer it from whether the switch is checked. Missing metadata
does not justify inventing a factory value. Do not add a permanent explanatory
paragraph or a duplicate factory column to this compact specialist layout.

### Context help and review

Routine descriptions, technical details and crawler sampling prose belong
behind the adjacent plain `?`. Use the approved nonblocking translucent popover,
anchored to its own trigger and bounded by the visible viewport. It closes on
Escape or outside click and follows scroll/resize without shifting the rows.
Retain the native disclosure fallback when enhancement is unavailable. Counts,
compatibility warnings, disabled reasons and save failures needed for a safe
decision remain visible in the row.

For each migrated owner, verify the actual production cascade and rendered
desktop/narrow result. Check one-, two- and three-column flow, unequal group
heights, translated/wrapping labels, keyboard focus, narrow/touch targets and
viewport-bounded help. Confirm the title and arrow resolve to the same settings
destination and neither changes the preference. Verify immediate save feedback,
authoritative refusal and uncertain-result handling through the existing hosted
coverage; follow the repository's CI-first verification contract. Preserve
no-script forms, Theme colors and forced-color controls. Record any missing
manual/device coverage rather than treating source review as rendered proof.

The concrete owners are:

- `public/assets/styles/admin.css`: Features group reference.
- `public/assets/styles/admin-feature-settings.css`: scoped specialist group,
  row, switch, help and responsive styling.
- `app/views/admin_dashboard.php` and
  `app/views/admin_dashboard_sections.php`: semantic Content groups, links,
  forms and canonical factory tooltip attributes.
- `public/assets/gallery-modules/admin-dashboard-workspace.js`: progressive
  autosave, visible completion states and trigger-anchored help.
- [Admin settings inventory](ADMIN_SETTINGS_INVENTORY.md): migration scope,
  prepared settings destinations and preference owners.

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

## Theme Language workspace

Language follows the Features surfaces with independent interface-language and
viewer-choice groups. All native checkboxes, including compound designer drafts,
use the compact switch treatment. Keep longer scope explanations in anchored
question-mark help. Language choices use bundled flag assets in both the closed
combobox and its keyboard-accessible list; retain the original named select as
the submission owner and unsupported-browser fallback.

Admin language, public default and the coupled viewer enabled/language list save
separately. Exclude neighboring Theme, design and JSON drafts from these requests.
Use canonical response values and the shared mutation completion owner. An
uncertain or refused write locks only its group, blocks legacy full-form replay,
and offers passive reload without automatic retry. Admin language offers an
explicit interface reload so unrelated drafts are not lost unexpectedly.
The designer remains one staged object with explicit Save theme, local preview
and resets. Pack and diagnostic actions retain their explicit authority.
Opt the nested Language tabs into `language_subtab` URL persistence, preserving
the primary hash, other query fields, reload and browser history.

## Theme Appearance workspace

Apply compact flowing groups to all five Appearance subsections while keeping
one shared preview and its existing browser-local bounded divider. Group height
is independent; labels, colors and precise numeric alternatives remain readable
at the minimum settings width. Keep swatch/HEX ownership and staged preview
semantics intact. Routine scope and animation explanations use anchored plain
question marks. Display numeric units and retain explicit Save theme for colors,
text, selects and ranges.

The five binary preferences use native compact switches with canonical factory
descriptions and isolated immediate saves. Each request carries one allowlisted
setting, desired boolean and CSRF token. Reuse existing layout/advanced owners,
capability gates and hero-tag public revision effects. Reconcile canonical
values through existing preview events; refusal/uncertainty locks its switch
and prevents full-form replay until passive reload. Other drafts remain intact.
Native forms remain the permanent no-script fallback. Appearance opts into the
shared URL-backed subtab owner, accepting old full panel IDs and short values.
