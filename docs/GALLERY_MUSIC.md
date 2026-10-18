# Gallery music

**Admin → Features → Gallery music** is the independent global master. It starts
ON on new installations and upgrades without a saved preference. Explicit OFF
preserves all originals, playlist order, download permissions and gallery/Smart
slideshow defaults, but hides players/tables, removes audio chooser hints and
rejects music editor actions and protected audio/revision delivery. Enabling it
again exposes the same stored playlist. The gallery's autoplay preference remains
independently OFF by default. PDF availability uses its own separate capability.

Storage statistics count each stored physical copy, including hidden and
download-locked tracks, even when this master is OFF. Smart deduplication changes
playback membership, never physical byte accounting. Run **Update all** to measure
the originals; see [accounting scope](GALLERY_PDF.md#admin-storage-accounting).

Music belongs to one physical gallery. `app/services/gallery_music.php` owns
validation, confinement, upload quotas and serialized playlist changes;
`app/models/gallery_music.php` owns the versioned JSON index. No database
migration is required. This foundation is tracked in issue #247.

Administrators can use the ordinary Upload photos chooser/drop queue for a
mixed selection of photographs, PDFs and music. Audio goes to protected original
attachment storage and never enters image decoding, thumbnails or PDF conversion.
Audio-only submissions work with image preparation disabled. The editor's
Documents / PDFs tab also retains independent upload and playlist controls.

The music launcher shows the authorized track count. Clicking it opens a small
overlay with a bounded, scrollable list; closing it leaves playback running.
Its translucent card also keeps the track count and complete playlist duration
visible while the list is closed. One silent metadata reader visits all public
rows in saved order; missing duration produces an explicit unknown total. Cards
wrap according to the gallery's available width, including a resized window.

Physical galleries with authorized photographs and enabled lightbox controls
offer **Presentation** below the attachment cards, even without attachments.
This explicit action opens photo one and applies its configured soundtrack and
speed defaults. In **Edit gallery → PDF · Music · Presentation**, the translucent
Presentation card configures public launcher visibility, autoplay, music and both
speeds. Autoplay defaults ON for every gallery until explicitly changed.
Its Save icon uses the same protected gallery form and stale-edit checks as the
other settings. Preview and Share use the current selections, including before
saving. Hiding public buttons does not revoke authorized links or hide photos.
It does not change the saved slideshow music preference. Normal viewer slideshow
controls continue honoring that preference. Photo one can be loaded through the
existing sparse metadata endpoint when another pagination page is visible.

The adjacent share icon opens a small translucent overlay with a selectable URL,
two discrete speed sliders and switches for autoplay, music, fullscreen and
**Without controls**. Copy uses the configured URL; changing a share draft does
not save gallery settings. Autoplay defaults ON, while Without controls defaults
OFF. The canonical gallery URL retains the active language and `presentation=1`,
plus `autoplay=1|0`, `presentation_fullscreen=1|0`,
`presentation_exclusive=1|0`, `presentation_music=1|0`,
`presentation_interval=slow|medium|fast` and matching `presentation_transition`.
The link snapshots these preferences even if the gallery defaults later change.
`autoplay=1` is an equivalent launch flag. Legacy links without explicit presets
use current gallery defaults. Pagination and administrator/preview state are
omitted. The link retains ordinary gallery access requirements and starts
the same presentation once after loading. Clipboard refusal exposes a selectable
URL. Browser autoplay/fullscreen refusal holds the black introduction and offers
**Start presentation**; a link cannot bypass browser policy. The confirmation
invokes the shared audio owner before requesting native fullscreen in the same
click task. It is available even with Without controls selected and disappears
before photo one appears. Esc or the close icon cancels the pending launch.
With autoplay OFF the link opens photo one paused and silent. Without controls
hides all viewer controls, metadata and diagnostics; Esc exits. This changes
presentation only and grants no access. Browser sound blocking still applies.

Each launch holds a black stage for at least one second and until photo one is
decoded. Requested fullscreen and playback must also settle; activation refusal
extends black until confirmation. The selected transition then reveals photo one
with requested music when autoplay is ON. The hold is not part of photo ordering
and never repeats at a loop.
Stop, Close and manual navigation cancel pending launch work. The existing
slideshow timer owns both the opening hold and normal advances.
The public request guard accepts the presentation flag only on gallery pages.
Fresh anonymous sessions use the same visibility/password/share/NSFW checks as
ordinary gallery links. When desktop native fullscreen is denied, the existing
desktop viewer fills the browser viewport. After explicit confirmation, an
unsupported native request keeps that shell rather than repeating the prompt.
Codec/media failures retain normal player feedback. Explicit Play can retry
later soundtrack failures.
The pending soundtrack also resumes on a trusted click on the photo. Its glass
player hides with the idle HUD even while activation is required; movement restores
the normal controls. A trusted photo/Play click also requests native fullscreen
when the link requested it and the viewer is using the viewport fallback.
Stop, Close, explicit silence and teardown cancel pending activation. A silent
presentation releases already playing page music while preserving manual Play
ownership during that presentation. No silent-audio trick or browser-policy
bypass is used; fully automatic audible playback depends on browser permission.
PDFs use the same disclosure. The existing miniplayer remains visible outside
the list and retains its four controls. The table shows titles, browser-reported
durations and one context-sensitive Play/Stop icon **before** each title, with
no playback column or Pause action. Long titles use an ellipsis and full tooltip;
only the playing, overflowing title scrolls horizontally. Reduced-motion users
retain static ellipsis. An exclusive shared player also offers
Previous/Play/Stop/Next on the page and in the photo-viewer HUD whenever music
exists, including outside slideshow. Buttons expose translated accessible names
and tooltips. No duplicate visitor enable checkbox is shown.

Administrator icons in each row toggle download permission; the header icon
allows or denies downloads for all **current** tracks. Every new upload starts
locked even after a bulk allow. Public download icons appear only for allowed
tracks. The download endpoint rechecks the saved permission as well as gallery
access, returning an indistinct 404 when either fails. Playback still delivers
original bytes to the browser: this controls download affordances and attachment
responses, not capture of playable bytes by a technically capable visitor.

With JavaScript, independent table forms replace only their card through
canonical Admin completion; the panel and browser URL remain in place.
Direct POST/redirect supports these editor forms without JavaScript. Mixed
Upload photos submissions require the browser attachment workflow; classic
server upload refuses audio/PDF before admitting any photograph.

Upload retries retain exact original File identities and each acknowledged
mutation envelope. A later refusal does not replay acknowledged photographs,
PDFs or audio. A lost/invalid audio response remains unresolved and blocks
another write until the administrator reconciles the gallery; it cannot safely
infer success from the playlist tail. Revision reads are administrator-only;
each upload returns its exact revision and new identity from the locked write.

`gallery_music` is a read-only protected same-origin GET/HEAD route. Dispatcher
schema preflight precedes output, then canonical no-admin-bypass visitor access
enforces gallery visibility, password/share and NSFW policy. Verified adults
with administrator authority retain the normal bypass outside anonymous preview.
Denials and missing originals share a no-store plain-text 404. Public streaming
uses indexed MIME, SHA-256 and size validation, nosniff and single byte ranges;
malformed, multiple and unsatisfiable ranges return 416. HEAD ignores Range;
If-Range requests receive the complete representation because no validators are
published. Session locks are released before output. Static gallery protection
also denies `.bin` originals in both Apache authorization modes.

Original bytes are stored under the gallery's denied `.gallery-music/` sidecar
as opaque 128-bit identities with a `.bin` suffix. Filenames remain metadata.
The ordered index stores a revision, the gallery's default slideshow-music
preference (initially off), and track identities, names, titles, MIME types,
sizes, SHA-256 digests and per-track download permission (initially false).
Older v1 records without that field remain locked; no rewrite or migration is
needed. The model owns this permanent conservative compatibility default.
Visibility is independent: missing `hidden` means false (visible), and new
tracks remain visible and locked for downloads. Public presentation/streaming
exclude hidden tracks; verified administrators can inspect them. Hiding editor
controls are future work. The model maps old and current records to the same
closed field shape and key order;
merely reading a legacy index does not rewrite its stored bytes. Invalid existing
indexes are preserved and refused, never reset to an empty playlist.

Admission requires a matching extension and server-detected MIME type using
PHP fileinfo. Supported containers are MP3, M4A, AAC, Ogg/OGA/Opus, WAV, FLAC
and WebM/WEBA. No transcoding is performed; browser codec support determines
whether a particular original can play. See the
[MDN audio codec reference](https://developer.mozilla.org/en-US/docs/Web/Media/Guides/Formats/Audio_codecs).

Limits are 64 MiB per original, 100 tracks and 512 MiB of indexed audio per
gallery, with a 256 KiB index and 240-byte filenames/titles. Lower PHP upload
and request-body limits still apply. The limits bound shared-host storage and
metadata work; they are not browser decoding guarantees.

Every mutation takes an exclusive sidecar lock and requires the last observed
revision. Stale edits refuse the complete operation. Originals are copied with
a byte ceiling and verified digest before the atomic index replacement makes
them visible. Removing a track publishes the new index before unlinking the
original. A process crash or failed unlink can leave an inaccessible orphan;
it never authorizes an unindexed original. There is no automatic orphan cleanup.
Concurrent gallery moves are governed by the existing gallery mutation owner.

Whole-gallery filesystem moves and Trash restoration carry this sidecar.
Filesystem backups must include hidden directories. Photo-only ZIP exports,
gallery transfer packages and sidecar metadata imports do not transfer music;
there is no separate music restore/import pipeline. The static server must
retain the existing hidden-directory denial for galleries.

Hosted tests own executable validation. The feature remains unqualified until
the final prepared candidate passes fresh BASIC and the complete hosted FULL
matrix through the approved integration workflow. Live codec/browser/device
acceptance remains separate from synthetic fixtures.

## Runtime impact review

Before adding the music owner, the tracked generated plan contains 98 modules,
4,052 direct file entries, 431 unique files and 3,621 duplicated entries.
The complete `public-gallery` closure contains eight modules / 164 files with
zero internal duplicates; `public-home` contains six / 117 and `public-media`
six / 85. The editor contains eight / 181. These are source-plan observations,
not executed runtime evidence. The gallery baseline permits eight modules plus
two and 156 files plus eight: its file allowance is already consumed.

Music presentation is therefore deferred until a gallery index exists and does
not add files to ordinary gallery closures. PDF and audio reuse the existing
attachment owner, whose historical machine name is `gallery-pdf-documents`.
Its whole routing/access closure is retained; no dependency edge borrows only
part of an existing owner. The global baseline permits 96 logical modules plus
two, already consumed by the pre-music plan. The combined attachment owner
keeps the estimate at 98 modules
and adds four unique PHP files, with unchanged probe roots, limits and ratchets.
Hosted preparation/FULL must establish final counts and loaded-route behavior.

## Playback and duration lifecycle

`gallery-music-player.js` owns a single audible native Audio instance per page,
shared across public/editor tables, the photo viewer and slideshow. Selecting
another track or gallery releases the old source before playback. Physical
galleries remain the storage owners. Smart Galleries use the read-only source
union below; no transcoding or artwork is added.

Gallery autoplay defaults off and changes only through the editor preference.
Slideshow startup applies that preference synchronously before fullscreen awaits,
preserving its initiating gesture. An enabled slideshow replaces any table music
and starts at the first track. The playlist repeats in saved order; both navigation
directions wrap. Stop releases its source. Closing/stopping slideshow releases
music owned by that viewer; independently requested table playback can continue.
Photo/speed changes do not restart audio. Manual Play remains available regardless
of the autoplay preference and never changes the saved administrator setting.
Page teardown releases the source and pending callbacks; back/forward restoration
rebinds observation with no automatic playback.

Native autoplay rejection exposes a translated explicit-Play retry. Failed sources
advance through at most one bounded pass before stopping. Generation guards
ignore late promise failures after stop/source changes. A successful track starts
a fresh failure pass on natural advance. These are permanent browser compatibility
behaviors owned by the music player. Live codec/device adoption remains unknown.

Durations come from a separate **silent** metadata-only Audio reader, one visible
row at a time, with a ten-second deadline. It never calls Play. Failed, infinite or
unavailable durations show a dash rather than an estimate. IntersectionObserver
admits visible rows; browsers without it use the same serialized bounded reader
for connected rows. This permanent fallback preserves duration availability
without concurrent metadata allocation. Removed cards and page teardown discard
queued work and release the active reader. Metadata may transfer audio bytes as
needed by the browser; the server performs no decoding or tag extraction.

## Compact attachment presentation

The existing shared lightbox view owns native disclosure markup; the new
`gallery-media-panels.js` browser owner upgrades it to a manual top-layer popover
without moving forms or playlist nodes out of their gallery. Only one applet is
open. Escape, the close icon, its launcher or an outside click dismiss it;
Escape/close restores launcher focus. PDF dialogs keep priority over Escape.
The body is bounded to 12rem (roughly five compact rows) and scrolls independently;
the floating panel also fits the current visual viewport. Public description
layout groups PDF/music launchers in one column. Canonical attachment replacement
reopens the same-identity applet and retains its last connected list scroll;
removing a top-layer node otherwise resets its layout scroll to zero. Removing the last
PDF preserves music and its surrounding layout.

Without JavaScript or Popover API support, the native details disclosure keeps
the same bounded list inline. Without Web Animations, opening/closing is immediate.
Reduced-motion preference disables disclosure motion and title scrolling.
ResizeObserver measures title overflow; without it, play, panel-open and window
resize events provide the permanent measurement fallback. These paths retain
one Audio owner, authorization and editor mutation semantics.

This redesign adds one browser module and no PHP source file, route, schema or
logical runtime owner. The existing attachment closure already contains the
shared view helper. Expected PHP module/file deltas are zero against the prepared
music baseline; global logical-module allowance remains fully consumed. Hosted
preparation/FULL owns the actual unchanged-budget proof.

## Smart Gallery source union

Smart Galleries have no audio directory. Their complete canonical grouped photo
result supplies source IDs, including sources outside the current photo page.
Temporary source filtering changes photos, not this union. The Smart service
rechecks public listing and visitor access against the request-cached source
inventory, and only sources with an index load the existing attachment owner.
A source must contribute a matching accessible photo to the canonical result;
an unrelated or empty physical gallery contributes no music merely because it
exists. Protected source URLs recheck access when audio is requested.

`gallery_music_merge_playlists()` reads bounded indexes in ascending source-ID
order, preserving each source's track order. Hidden tracks are excluded; visible
locked tracks are included. Existing SHA-256 digests identify identical original
bytes without decoding or hashing every original during page rendering. Renamed
copies appear once; different same-name/same-duration files remain separate.
A visible downloadable copy may represent a duplicate at its first list position;
its own endpoint remains the permission authority. Re-encoded songs or changed
embedded tags have different digests: acoustic/content fingerprinting is future
work, and matching name or duration alone is deliberately insufficient.

A damaged source index is counted as unavailable and omitted without rewriting
it; other independent sources remain playable. The merge service owns this
permanent read-only degradation. Output memory/markup scales with distinct tracks
and source count; per-source admission limits remain unchanged. No gallery-wide
audio download/decoding, metadata-reader fan-out or additional codec is introduced.

The same applet, miniplayer and exclusive slideshow owner render the union.
Negative Smart presentation IDs and source-qualified row IDs avoid collisions
with physical galleries; delivery always uses the positive physical ID and
original track ID. Subdirectory mounts and anonymous preview are preserved.
`music_enabled` in existing Smart presentation JSON independently controls
automatic slideshow music and initially defaults false. Enable presentation
overrides and the music preference in the Smart editor; manual Play remains
available when music exists. This does not inherit a source's autoplay setting,
copy originals or alter per-source download permission.
