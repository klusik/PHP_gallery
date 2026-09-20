# Server image decode admission

`app/services/image_decode_policy.php` owns pre-allocation admission for the shared
GD raster decoder. `thumbnail_generation.php` requires it explicitly, preserving
standalone thumbnail consumers without changing the services loader. The policy
does not read request globals, query a database, change authorization, or delete
originals. Deployment defaults remain in `app/configuration_defaults.php`.

## Admission and memory accounting

The policy accepts observed positive integer dimensions for JPEG, PNG, GIF, and
WebP. It checks each side, then an overflow-safe pixel product, then all memory
products and additions. Unknown metadata, unsupported formats, arithmetic
overflow, and insufficient headroom refuse decoding before the GD call.
The path boundary rereads metadata rather than trusting image-row dimensions or
caller-supplied MIME alone, and includes the compressed source file size.

| `runtime_limits` key | Default | Meaning |
| --- | ---: | --- |
| `image_decode.max_dimension` | 32768 | Maximum width or height in pixels |
| `image_decode.max_pixels` | 60000000 | Maximum source pixel count |
| `image_decode.max_memory_bytes` | 536870912 (512 MiB) | Maximum estimated request memory ceiling |
| `image_decode.memory_reserve_bytes` | 16777216 (16 MiB) | Headroom for the rest of the PHP request |

Positive integer overrides use the existing runtime configuration mechanism.
Invalid, zero, negative, fractional, and overflowing values fall back to the
canonical defaults. The limit cannot be disabled through a zero or `-1` override.
No local configuration file needs modification after an update, and this policy
does not reduce upload-byte limits.

The effective request ceiling is the smaller of the configured ceiling and a
known positive PHP `memory_limit`. PHP `memory_limit=-1` still uses the configured
ceiling. Missing, zero, malformed, or unrepresentable PHP limits defer decoding;
they never mean unlimited. Current `memory_get_usage(true)` and the configured
reserve are subtracted before admitting additional work.

The additional working estimate is:

```text
source pixels * backend bytes per pixel
+ optional full source surface for rotation/intermediate work
+ largest target pixels * backend bytes per pixel
+ two copies of the compressed source bytes
+ any retained GD source pixels * GD bytes per pixel
+ fixed codec/row/metadata allowance
```

GD reserves 8 bytes per pixel; the optional ImageMagick writer reserves 16.
The fixed codec/row/metadata allowance is 8 MiB. These conservative assumptions
are documented Core constants in `app/policy_constants.php`, imported by the
shared policy: `IMAGE_DECODE_GD_BYTES_PER_PIXEL`,
`IMAGE_DECODE_IMAGICK_BYTES_PER_PIXEL`, `IMAGE_DECODE_COMPRESSED_COPY_COUNT`, and
`IMAGE_DECODE_FIXED_OVERHEAD_BYTES`. The compressed-copy count remains two.
They are implementation assumptions, not administrator controls or claims about
precise library allocations. Deployment overrides remain the runtime-limit keys
above, not mutable copies of these constants.

Thumbnail targets are written sequentially, so admission reserves the largest
requested target. Target dimensions round upward for accounting and never upscale.
JPEG orientation work reserves a second source surface before decoding. Existing
two-argument `image_create_from_path()` callers reserve both a full-size target
and a full-size intermediate surface because their later work is not specified.
Callers using its optional arguments must honestly describe their largest target
and intermediate work; these arguments must not come directly from an HTTP request.

With 16 MiB of existing PHP allocation, the defaults admit metadata for a 6000x4000
JPEG with an 8 MiB compressed input and a 1600-pixel thumbnail, including rotation.
A 48 MP image without rotation can also fit; rotating that image exceeds the
default ceiling. These are reproducible admission examples, not measurements of
actual large native decodes. A 128 MiB hosting limit will admit considerably less.

## Covered call chains

| Entry point | Shared boundary |
| --- | --- |
| Admin generation, scheduled maintenance, public warmup | `create_image_thumbnails_result()` uses `image_decode_gd_path_result()` |
| Public missing-thumbnail repair | `thumbnail_ensure_image_thumbnail_variant_file()` reuses the same generator |
| Classic upload requesting server derivatives | `admin_uploads.php` invokes the same generator after originals are stored |
| Upload automation with prepared client variants and server completion | `upload_automation.php` invokes the same generator; existing valid variants are reused |
| Uploaded gallery cover, public cover resize, file-based favicon source | Existing `image_create_from_path()` calls inherit conservative admission |
| Optional JPEG-to-WebP Imagick EXIF writer | A separate admission check accounts for any GD source still alive before `new Imagick()` |

Strict browser-prepared ZIP ingestion in `browser_uploads/pipeline.php` installs
validated prepared derivatives and does not start a hidden GD fallback. Missing
required prepared variants remain a validation failure. If a user chooses the
server path instead, or a later public/maintenance request repairs derivatives,
the shared generator applies this policy. Do not add an unguarded decoder to a
controller or prepared-upload handler.

Imagick refusal returns to the existing GD WebP writer, which reuses the already
decoded source. It does not decode the original again or switch the configured
thumbnail format policy. Standalone calls to the Imagick writer are guarded too.

## Failure behavior

`image_decode_gd_path_result()` returns a source plus status on success, or `false`
plus a closed reason on refusal/failure. The historical `image_create_from_path()`
wrapper retains its `GdImage|false` contract. Thumbnail generation returns its
existing counters and errors, adding `decode_status` and `message` on decode
failure. Required variants count as failed instead of appearing to be successful
zero work; an unreadable source header reports at least one failure.
Ordinary processing failure keeps the historical `source_decode_failed` error;
admission refusals have distinct `source_decode_<reason>` errors.

Reasons distinguish `unsupported_format`, `metadata_unavailable`,
`invalid_dimensions`, `dimension_limit`, `pixel_limit`, `arithmetic_overflow`,
`memory_budget`, `memory_limit_unknown`, and `processing_failed`. Messages use
`t('thumbnail.decode.' . reason, safe English fallback)`. The EN/CS/DE/SV catalogs
register these reasons. Controller/panel presentation of the structured message
requires consumer integration verification; legacy consumers that copy only the `errors` array continue to receive
machine reasons. Native exception messages and source paths never enter this
status. The policy adds no diagnostic log containing source information.

The generator retains accepted originals on refusal and ordinary decode failure.
It also postpones its invalid-geometry cache cleanup until decoding and orientation
succeed, while already-valid cache hits remain usable without decode admission.
The public resolver retains its existing invalid-cache validation/removal policy;
an invalid derivative is not an authorization fallback to a private original.
Media authorization and HTTP fallback decisions remain with their existing owners.

## Limits and separate converters

This is a conservative admission estimate, not a hard process memory sandbox.
PHP may not account for every native allocation. Codec implementations, metadata
parsers, animation/delegate behavior, other live native surfaces, image replacement
between inspection and decode, host process limits, and concurrent workers can
exceed what this calculation observes. Catching `Throwable` contains ordinary
decode exceptions; it cannot recover a worker killed by OOM. No real OOM or huge
native allocation is needed to test the policy.

Separate conversion paths remain outside this change:

- `dng_derivatives.php` performs full RAW Imagick conversion, embedded-preview
  Imagick conversion, and a direct JPEG GD decode. The existing DNG extractor in
  `uploads.php` has a 220 MiB compressed-file check; that is not a decoded-pixel or
  process-memory guarantee.
- `uploads.php::dng_image_metadata()` calls `Imagick::pingImage()` to obtain RAW
  dimensions. Ping is metadata-oriented but may invoke native RAW/delegate work;
  it is not established here as an allocation-free preflight. Dimensions from an
  embedded JPEG preview cannot establish the full RAW surface dimensions.
- The favicon data-URL crop and gallery-background upload decode strings directly.
  Existing compressed-input checks on those paths do not inherit this policy.
- Browser preparation uses its own client/browser and upload package limits.

These paths must not be described as protected by this shared GD change. A future
heavier-conversion worker needs explicit timeout, native memory/disk/delegate
controls, and recoverable input ownership before it can offer stronger isolation.
This change does not introduce such a worker or claim protection from every native
decoder vulnerability.

### Reviewed decoder inventory

The follow-up inspected direct `imagecreatefrom*`, Imagick construction/read and
ping calls in services/controllers. Query-format/version capability checks were
separated from pixel decoding. The unresolved paths below are explicit gaps,
not exceptions silently accepted by a new regression baseline.

| Owner/caller | Observed state and remaining integration |
| --- | --- |
| `image_decode_policy.php::image_decode_gd_path_result()` | Central JPEG/PNG/GIF/WebP dispatch, admitted from freshly observed local metadata. |
| `thumbnail_generation.php::write_resized_webp_with_imagick_exif()` | Shared Imagick estimate before construction, including retained GD when invoked by the fallback selector. |
| `admin_uploads.php` classic controller | Executed native-form route, real validator/storage facade, then shared generator; SQL/scanner/upload-provenance seams remain isolated. |
| Client-prepared thumbnails and server completion | Executed `upload_automation_install_client_thumbnails()` followed by the authenticated `cms_admin_create_thumbnails()` endpoint and real shared generator. Existing prepared variants are reused. The upload-automation HTTP endpoint itself retains source call-chain checks. |
| `browser_uploads/pipeline.php` | Strict prepared coverage is validated before storing originals. ZIP installation has no implicit GD/Imagick fallback. Later repair/server completion is a separate shared-generator call. |
| Public missing-thumbnail repair | Executed small-image pipeline coverage exists. Dispatcher authorization is unchanged and not recreated by the fixture. |
| Uploaded/public gallery cover and file-based favicon | Inherit the conservative `image_create_from_path()` contract. |
| `dng_derivatives.php::write_dng_imagick_derivative()` | Full RAW construction remains unadmitted; frame-zero selection after construction is not pre-allocation protection. |
| `dng_derivatives.php::write_dng_preview_derivative_with_imagick()` and embedded-preview GD branch | Extracted JPEG is still decoded directly; it should enter shared JPEG admission. Full RAW limits remain separate. |
| `uploads.php::dng_image_metadata()` | Native metadata/delegate behavior of `pingImage()` remains unresolved. |
| `favicon.php::store_uploaded_favicon()` cropped data URL | Direct string decode remains outside admission; compressed bounds do not bound pixel allocation. |
| `gallery_backgrounds.php` optimized-background generator | Full compressed-file read and direct string decode remain outside admission. |

This follow-up does not alter DNG, favicon or gallery-background owners. Their
wiring requires coordinated ownership before claiming whole-application decoder
coverage. No RAW delegate, string decoder or large native surface was exercised
as a resource-exhaustion experiment.

## Verification

`tests/image_decode_policy_test.php` is a pure metadata/arithmetic fixture with no
GD requirement. It covers realistic admission examples, rotation, retained GD
surfaces, compressed-input accounting, unknown/unlimited/low memory, dimension and
pixel limits, integer multiplication/addition overflow, invalid configuration,
and bounded reasons.

`tests/image_decode_pipeline_test.php` uses disposable 64x48 images, injected
metadata, and counted decoder calls. It exercises real supported GD codecs,
public repair, valid cache reuse, refusal without original/cache mutation,
translation routing, malformed-image failure, simulated native exceptions, and
upload-controller call-chain checks. The latter are source contracts, not a live
upload, browser, or database acceptance claim. GD with JPEG/PNG is required; the
fixture reports SKIP when unavailable. Register that requirement in the central
audit registry when integrating it, so missing required GD coverage is BLOCKED.

`tests/image_decode_imagick_fallback_test.php` executes the real optional writer
and its GD fallback with a 64x48 JPEG and 32x24 WebP output. Synthetic extreme
dimensions, unknown/low limits and retained-GD accounting refuse optional Imagick
before target changes. Fallback reuses the existing GD surface without another
JPEG decode. A constructor trap is used when Imagick is absent; this is not
native Imagick codec/delegate success or memory-isolation evidence. It requires
GD JPEG/WebP plus EXIF for the real fallback selector; register those requirements
with the parent audit owner. The coding agent ran this new test successfully
without a live configuration/database or central audit.

`tests/image_decode_upload_pipeline_test.php` adds executed upload-to-decoder
coverage using 64x48 PNG originals and 32/64-pixel JPEG targets:

- Native-form `cms_admin_upload()` runs the actual upload validator, storage
  facade, replay ledger and shared generator. Synthetic extreme dimensions and
  a one-byte configured ceiling both refuse before GD; the accepted original,
  completed redirect and safe partial-success counts/filenames remain available.
  An ordinary request decodes once and generates both targets.
- The real client-prepared installer associates one 32-pixel thumbnail with an
  accepted original. The AJAX server-completion route then reuses that variant
  while shared admission refuses its missing 64-pixel target. Both extreme
  metadata and a low budget leave the prepared bytes and original unchanged.
  Restoring an admissible budget generates only the missing target with one
  decode. Invalid authentication/CSRF stops before metadata inspection.

SQL, schema observation, scanner rows, administrator identity and native HTTP
upload provenance are isolated seams. The classic redirect suspends a PHP Fiber
after durable completion and writer release; it does not start a real HTTP server.
The prepared leg exercises the client-variant installer and the explicit shared
server-completion endpoint, not automatic fallback from strict browser ZIP
preparation. It does not run ZIP packaging/worker JavaScript, the automation HTTP
endpoint, a production scanner or real MySQL. PHP GD PNG/JPEG is required; the
parent should register `extensions => ['gd']` and `missing_status => 'BLOCKED'`.
The coding agent ran this new fixture successfully. No giant pixel surface or
real OOM was attempted.

Shared-raster and representative pipeline evidence does not establish whole-app
native-memory isolation. Separate RAW/string converters remain outside the
retained shared-decoder scope as recorded above. Integrated central/browser
qualification remains with the parent; these focused results do not claim all
acceptance criteria for every upload mode or library configuration.

Use `php scripts/audit.php --profile=full` for the integrated handoff. Focused test
commands are for developing these new fixtures or diagnosing an audit failure;
parallel agents must leave central audit orchestration to their parent.
