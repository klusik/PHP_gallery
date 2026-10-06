# Breadcrumb navigation

`app/services/breadcrumbs.php` owns the presentation registry, style inheritance,
and normalized view model. `app/views/breadcrumbs.php` renders the prepared data as
`nav.breadcrumbs > ol.breadcrumbs__list > li.breadcrumbs__item`, using
`.breadcrumbs__link` for links and `.breadcrumbs__current[aria-current="page"]`
for the current page. Visual separators belong to CSS. The view preserves the
provided URL and escapes it when rendering; it does not construct routes or call
domain services.

The old generic `.breadcrumbs` and `.breadcrumbs a` rules were removed from
`public/assets/styles/public.css` when the shared stylesheet was introduced.
`public/assets/styles/public-shared.css` still gives breadcrumbs inside a public
hero their established zero margin and compact font size. The same hero selectors
remain in the bundled legacy `admin.css` and `admin-layout.css` stylesheets for
their combined-page compatibility paths; their old `opacity: 0.7` treatment was
removed so ancestor labels use the full theme foreground color. The public page
layout does not expose an Admin breadcrumb navigation landmark today.

## Style contract

The stable style IDs are `minimal`, `chevron`, `pills`, `surface`, `ribbon`,
`nodes`, `tabs`, `tiles`, and `gradient`; the original four IDs remain unchanged.
The built-in theme default is `chevron`. Physical galleries may store `inherit`
or one of those IDs. `inherit` resolves through the selected theme style. Unknown
theme values fall back to `chevron`; unknown gallery values behave as `inherit`.
`minimal` is the retained low-decoration style for existing breadcrumb
presentation. `ribbon` uses arrow segments, `nodes` connects decorative dots,
`tabs` uses compact tabs, `tiles` uses raised rectangular cards, and `gradient`
adds a theme-colored surface with an accent stripe.
Smart Galleries store only explicit registered IDs in the `breadcrumb_style`
field of their versioned presentation JSON. Their effective default inherits
the current theme style, so a Smart Gallery ID never addresses the physical
gallery settings namespace.

The style registry's labels use the translation keys
`breadcrumbs.style.<style-id>`. Persisted IDs and CSS modifier classes are machine
contracts; translated labels are presentation only.

## Admin visual selection

Theme, physical-gallery, and Smart Gallery breadcrumb selectors present the
registered styles as native radio cards with an actual breadcrumb example
rendered through the production component and matching CSS. Example ancestors
are decorative, non-link samples marked `aria-hidden`; they do not create fake
navigation or destinations. The physical-gallery and Smart Gallery
`Default / inherit` choice previews the currently effective Theme style. Native
radio controls support keyboard selection and remain usable without JavaScript.

## Existing integration inventory

- `app/controllers/public_gallery_controls.php` prepares breadcrumbs for public
  physical gallery pages and the root gallery index. Its existing renderer is
  also captured into HTML by `picture_game` and gallery-page composition.
- `app/controllers/public_tags.php` prepares a home/current breadcrumb pair
  using the shared view-model API; `app/views/public_tags.php` uses the shared
  renderer.
- `app/controllers/smart_galleries.php` prepares a Home/current path for public
  Smart Gallery pages and resolves its style through the Smart Gallery's own
  effective presentation preferences, with the theme as its default.
- `app/views/picture_game.php` receives captured breadcrumb HTML from its
  controller.
- Smart Gallery source provenance in `app/services/smart_galleries.php`,
  `app/controllers/gallery_lightbox.php`, `app/controllers/public_gallery_lightbox.php`,
  and the lightbox/map views describes an image's physical source path. Those
  labels and links provide image provenance, not page navigation.
- Search results currently do not render breadcrumbs.

## Compatibility lifecycle

The established `render_breadcrumbs()` helper remains as a compatibility entry
point for public gallery pages and embedded page composition. It delegates to
the shared renderer, protecting the existing server-rendered routes and prepared
URLs while keeping those callers stable. Its owner is the public gallery
controller/view integration. The helper is permanent compatibility support;
existing usage volume is `unknown`.

The nine registry styles and `inherit` sentinel are permanent supported IDs.
They preserve a stable stored preference contract across theme, physical-gallery,
and Smart Gallery configuration. Retaining those IDs is the permanent-support
rationale; presentation may evolve through CSS while stored values remain valid.
