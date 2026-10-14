# Floating page-edge navigation

The shared public page-edge navigation (#208) consists of the existing **Top**
button and the new **Down** button, in one server-rendered
`data-floating-scroll-controls` container. It is rendered on public Home,
gallery-detail and tag listings through `render_back_to_top_button()` and
`view_render_back_to_top_button()`. Pages without the existing listing
navigation continue without either control.

The controls share `back-to-top-button` styling. The container occupies the
existing zero-width grid column beside a public listing and uses sticky
placement on desktop, aligned inward from the listing edge so visible controls cannot create horizontal page overflow. On viewports up to 980 CSS pixels it becomes a fixed,
safe-area-aware stack near the bottom-right corner. The two reserved grid rows
keep the controls in a stable order and position when either one is hidden.
The visual theme, CSS variables, labels, icons and focus/hover treatments apply
equally to both controls. Public styles live in `public-shared.css`; the
existing Admin CSS copies remain in sync. Floating content widgets already
exclude both `.back-to-top-button` rectangles from their placement solver.

The buttons use the existing photo-overlay palette: a translucent dark surface,
white labels, a light border and four-pixel backdrop blur. Each button has a
fixed-width left icon column and left-aligned text, so different translated
label lengths do not shift the arrows. Hover and keyboard focus increase the
surface and border contrast while preserving the two-slot layout.

## Browser behavior

`public/assets/gallery-modules/back-to-top.js` owns both controls:

- **Top:** retains the existing listing-specific visibility policy, including
  the scroll distance above 180 CSS pixels and the listing's viewport bounds.
  It performs `window.scrollTo({top: 0, ...})`.
- **Down:** is independently visible whenever the document extends at least
  two CSS pixels below the current scroll position plus viewport height, even
  at the beginning of a scrollable page. It hides at the document's end and
  on short pages. It scrolls to the full document height, not the end of the
  current image grid or pagination block; browsers clamp to the last reachable
  scroll position.
- Both hide during lightboxes and document fullscreen. The native controls
  support mouse, touch, Enter and Space. Smooth scrolling is disabled when
  `prefers-reduced-motion: reduce` is active.
- Listeners are abortable, scroll/resize updates are requestAnimationFrame
  coalesced, and ResizeObserver rechecks document/body/listing heights. A
  bounded MutationObserver notices the body lightbox class and direct page
  fragment changes. On panel-driven gallery refresh, the existing setup and
  teardown refresh the DOM selection and release all old observers/listeners.
- When the currently focused button hides while its counterpart is visible,
  focus transfers without scrolling to that counterpart.

## Localization

The server uses translated labels and accessible titles through the existing
`t()` helper. Keys `public.back_to_top_label` and
`public.back_to_top_short` remain unchanged. The new keys are
`public.back_to_bottom_label` and `public.back_to_bottom_short`.
English, Czech, German and Swedish complete JSON catalogs carry their own
translations. Other partial locales continue to use the established English
fallback.

## Verification

`tests/floating_scroll_controls_test.php` checks the actual view output,
HTML escaping, native button markup, supported locale strings and public/Admin
responsive style contracts. `tests/floating_scroll_controls_scenarios.mjs` runs
the real browser module in a deterministic DOM seam and verifies top/middle/
bottom visibility, scroll targets, tolerance at the page edge, resize/content
growth, lightbox/fullscreen suppression, reduced motion, focus transfers and
observer/listener cleanup. It is loaded once by the already-registered
`frontend_operational_policy_test.mjs` Node audit owner; its scenario filename keeps it outside the standalone test registry. The central hosted
candidate qualification remains authoritative; real mobile/desktop appearance
and third-party Theme overrides still require visual acceptance.

Admin mutation completion preserves the entire direct-child floating navigation
container while replacing listing children. A nested Top button cannot serve as
the frame insertion anchor: removing its parent detaches the anchor and prevents
fresh cards from being inserted. The production Theme-width browser fixture
refreshes the real listing twice and verifies both button identities and all
gallery cards. The legacy single-button shell remains supported for mounted
pre-update markup; its owner and retirement condition are recorded in
`COMPATIBILITY_LIFECYCLE.md`.
The side panel, Route Navdata and cooperative-gallery entrypoints retain their
shared mutation-coordinator URL identity when its cache key advances, preserving
cross-module stale-response suppression. Their parent imports advance with them.
