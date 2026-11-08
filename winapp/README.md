# PHP Gallery uploader for Windows

The Windows companion app is a desktop import client for one PHP Gallery target
gallery. PHP Gallery remains authoritative for authentication, validation,
duplicate decisions, storage, image records, thumbnail acceptance, metadata, and
AI job ownership.

The application keeps the existing `gallery_watch_upload.pyw` launcher,
`run_gallery_watcher.bat`, and `--once` compatibility. Normal operation needs
only Python plus the lightweight dependencies in `requirements.txt`.

## Interface and local feature settings

The compact dark interface uses slate glass panels, cyan actions, an icon
navigation rail and borderless contextual icons. Hover or focus an icon to
discover its action; the selected screen remains highlighted. Boolean
preferences use sliding switches, including
keyboard focus, Space activation and disabled states. Glass is composited into
opaque RGB artwork for reliable Windows Tk rendering; it does not change window
opacity. Source mode without Pillow retains usable native controls for repair.
Import, Activity, AI and Settings share the same card spacing, continuous
toolbar surfaces and flat table headings as the Watcher view.

Windows uses the installed Segoe UI Variable Text family when available,
with Segoe UI and the native Tk font as fallbacks. Shared fonts cover labels,
tables, inputs, menus and secondary windows; fixed-width diagnostics keep their
monospaced font. Native text respects Windows font smoothing/ClearType.
Known control, cache, pin and recording icons use supersampled artwork rather
than small emoji or font-substitution glyphs. Pillow-free source mode keeps
readable native glyphs. No font download or system preference change is needed.
Before creating Tk windows, an unaware Windows process requests system
DPI awareness; an existing manifest/host context is preserved. This avoids
startup bitmap scaling, but does not add per-monitor layout reflow. Restart on
the destination display when moving between displays with different scaling.

The app header groups PHP Gallery and the linked site. The Watcher screen
shows the upload gallery, chosen folder and primary Start/Stop control.
**Choose folder…** is on the Watcher screen; its settings sheet also offers
the picker and open-folder action. Settings gears sit immediately beside their
gallery, folder or flight control. Record flight is the only flight preference
on the main screen. Its
gear opens a local settings sheet containing automatic report creation, report
languages, interface language, progress and the report folder. Closing the sheet
never starts a flight or finalization operation. Interface language uses four
visible language segments. Tooltips, menus and secondary windows share the dark
palette; page scrollbars appear only when content exceeds the viewport.

Existing saved paths, Gallery URL, connection and local options are restored on
restart from `%APPDATA%\PHPGalleryUploader\config.json`. Flight switches and
languages use the existing `flights/preferences.json` in the same app profile.
Saving feature preferences never resets unrelated settings.

The main window remembers its normal size and position, maximized state and
Photos tree width automatically. Minimizing, fullscreen and hiding to the tray
do not overwrite the last normal rectangle. On a changed display layout, the
restored rectangle and divider are clamped to usable current bounds. These
view-only preferences are separate from upload configuration and credentials.

Switching albums cancels the old viewer without releasing a transport that is
still finishing DNS or socket work. The next request waits within its original
deadline for that single transport slot instead of showing an immediate busy
failure. Canceling the new viewer also cancels its wait; no extra network worker
is admitted while an earlier one is still running.

The Record flight row includes a single compact, background-matched instrument
line: IAS in knots, altitude in feet and signed V/S in feet per minute. It reads
the recorder's existing acquisition cache; it creates no second SimConnect
connection and performs no disk or network work on a display tick. Stopped,
paused, disconnected or more-than-three-second-old values become dashes.
The adjacent **PDF** shortcut opens saved flights and reports.

## Windows startup and a single running instance (0.7.0)

**Settings > App & integrations > Start with Windows (minimized to tray)** is
enabled by default. The switch saves immediately, preserving unrelated saved
settings and unsaved connection fields. Turning it off removes this user's
startup entry; upgrades preserve that choice. The installed executable registers
itself in `HKCU\Software\Microsoft\Windows\CurrentVersion\Run` as
`PHPGalleryUploader`, using a quoted executable path and `--startup`. This runs
after the user signs in to Windows. The installer applies the saved choice as
its original user even when **Launch application** is cleared. Self-update
leaves registration to the original-user app restart. If Setup was explicitly
started elevated or under another account, registration belongs to that account;
the next ordinary app launch also reconciles the signed-in user's preference.

Logon starts with the window withdrawn before painting and exposes the tray
icon. A missing, failed or stalled tray restores the window within five seconds
so the app remains accessible. Import-recovery prompts wait until the user opens
the window. Watching/importing still requires its existing explicit Start
action.
Source/Python runs never add an OS startup entry; their startup switch is
disabled.

All Windows desktop launches sharing the app profile use one native mutex,
including copies from different executable/source locations and Windows
sessions. A second manual launch signals the existing process to restore/focus
its window, then exits before opening logs, configuration or state. Another
logon launch keeps the existing process hidden. Restoring never restarts
workers.
An existing process in another Windows session must be opened in that session.
Native coordination failure refuses a second writer. Normal exit or a crash
releases ownership without a stale lock file. `--once` refuses overlapping work
with exit code 1. Before scanning, it also verifies saved mode evidence through
Windows Credential Manager and holds a local legacy-work lease for the scan.
An unavailable vault, first setup, linked mode or ambiguous evidence returns
nonzero without scanning. A verified legacy or unmigrated legacy configuration,
including a UNC config path, retains its API-key scan. This guard does not cover
GUI workers or fence other processes. Once-mode inventory and upload requests
recheck evidence for each endpoint attempt and redirect; an already-running
request can finish. Real Windows
behavior remains unqualified. Version/runtime probes and the independent updater helper keep
their existing early dispatch. Uninstall removes the Run value for its user;
other users' profiles remain independently managed.

Requests carrying the saved API key refuse redirects to another host, an
unapproved port, or from HTTPS to HTTP. Same-host redirects on the same effective
port and the standard HTTP-to-HTTPS upgrade remain supported. If an installation
uses a different redirect, configure its final gallery URL directly.

## Flight recording and reports

The uploader supports a shared, read-only flight-recording/reporting workflow
for
MSFS 2020 and MSFS 2024. Recording is enabled by default and has an independent
opt-out; it does not change screenshot watching or Sync UTC time. Starting the
watcher automatically arms flight recording according to that switch, with no
separate flight-start action. Switching recording off during a session pauses
acquisition; switching it back on resumes the same durable session with an
explicit track discontinuity. Starting with recording off keeps a dormant
recorder ready without opening SimConnect. Accepted photographs keep uploading.
Previously captured data remains available for the selected report policy.
The simulator provider records aircraft telemetry, while per-photo camera
coordinates remain
owned by the existing location feature. Unavailable signals are labelled and
degrade the affected metric/chart without disabling basic recording or reports.
Live simulator/aircraft/build qualification remains a separate acceptance step.

The recorder requests the SDK clock as `ABSOLUTE TIME` in `seconds`;
the `E:` prefix is only RPN expression syntax. Load factor uses the SDK unit
`GForce`. A rejected core definition prevents usable flight samples even when
the separate photo-camera connection succeeds. **Copy redacted diagnostics**
includes the app version and independent recorder state: saved sample count,
running/paused state, simulator identity and last session warning. SDK failures
identify a decoded SDK name and meaning, send ID, parameter index and original
operation with named arguments, including the rejected variable and units.
Native failures retain hexadecimal HRESULTs and Windows error names. Unknown
codes stay explicitly unknown. The message states whether core capture failed
or only one optional signal is missing; timeouts include current simulator,
loaded-flight and pause state. Changed failure reasons are also retained in
`session.json`, without repeating an identical consecutive warning. Connection,
first valid frame and first flushed sample have distinct activity messages;
no failed interval is recreated. The code meanings follow the
[SDK exception reference](https://docs.flightsimulator.com/html/Programming_Tools/SimConnect/API_Reference/Structures_And_Enumerations/SIMCONNECT_EXCEPTION.htm).

Read-only acquisition targets 20 Hz, but the observed rate can be lower. Cruise
samples persist at 1 Hz; below 300 m AGL, approach capture uses high-resolution
sampling with a five-second prebuffer (up to 100 samples). Descending below
100 ft AGL explicitly latches final-approach detail. Every acquired frame is
retained through touchdown and rollout until ten seconds continuously on the
ground at 15 kt or less; climbing above 500 m AGL exits for a go-around. Taxi
speed is an exit proxy, not a claim that a runway boundary is known. Unknown
speed keeps dense capture active. The native queue drain stops on an empty
read instead of spending a fixed 64 dispatch calls per frame. This removes
queue-poll overhead, but does not guarantee 20 Hz on a busy simulator.
Diagnostics
and session metadata expose capture mode, retained detail count and largest
gap. Raw storage is capped
at 256 MiB per session and 2 GiB in aggregate. Reaching a cap stops flight
recording with a warning while photo capture and report delivery remain usable.
Raw flight data and reports stay in the app-owned
`%APPDATA%\\PHPGalleryUploader\\flights` workspace; the Gallery API key is
not copied there. PDFs are never deleted automatically. Clean the app-owned
workspace manually only after keeping any reports you want to retain.

Derived rates use a fit over the latest second of simulator time, require at
least 0.3 seconds of valid data and reject sample gaps over 0.25 seconds.
Landing
position uses the aircraft reference point, not a gear-impact point; the report
does not guess an airport.

Automatic report creation is enabled by default. The persisted language choices
initially select English, Czech, German and Swedish (EN/CS/DE/SV). Each selected
language renders from one immutable analysis into a separate chart PDF using
bundled ReportLab and DejaVu assets. Reports are retained locally and can be
opened or retried. No raw telemetry is sent to the Gallery.

Standalone Stop and confirmed Revoke use one durable stop/finalize coordinator.
Stop ends the watcher, its owned UTC synchronizer and flight recorder, then
drains accepted photographs and completes required report delivery. It leaves
unrelated AI, manual-import and preflight workers running and keeps the key
configured. Confirmed Revoke runs that same Stop, also stops other authenticated
producers and finishes accepted work before requesting scoped server revocation.
The shared progress view shows phase and elapsed time; remaining time is shown
only when measurable. Finalization drains accepted work for up to 300 seconds;
after timeout, pending work and the API key are retained for recovery. Failures
retain pending tasks and local artifacts. After restart, pending work is
restored,
but Revoke requires renewed explicit confirmation. No simulator or valid flight
means no empty report.

Gallery delivery is an optional `flight_reports_v1` capability on the existing
gallery-scoped upload token. Inventory advertises the capability. The
`flight_report_status` JSON action takes `report_id`, `language` and
`sha256`; `flight_report` takes those values plus `session_id` and a
multipart `report` PDF. Authentication fixes the destination gallery. Server
validation, safe storage and protected PDF viewer access reuse the general
document services. Stable report identity/language/checksum plus a status check
before retry make delivery idempotent after ambiguous network outcomes. Each
report request has a 180-second HTTP timeout. Reports are distinct from OFP
documents, inherit existing gallery visibility and add no database schema.
Unsupported servers leave reports local and retryable.

An upload starts only after an unambiguous status confirms that its report is
absent. A receipt must match the report checksum and byte length. An incomplete
status or receipt is not success; if bytes may already have been sent, the
client checks status and retains the report when the outcome remains unknown.
The PDF is hashed with a bounded read before delivery and checked again before
recording its receipt. These reads do not atomically exclude an unrelated writer;
the saved-file confirmation dialog does not capture the file's bytes or hash.

### Manual completion and saved PDFs

During capture, **Finish flight…** sits below the upload gallery in Watcher
and Import. Without flight detection it uses **Finish game…**. **Begin
flight…** or **Begin game…** stays available whenever API-key mode is idle,
with or without a saved key or running game. It opens the existing connection
editor directly. A Photos browsing link does not hide this action. Explicit
linked upload mode instead offers its gallery reconnect action. Active capture,
pending work and finalization keep Finish, Review or Progress first; an active
manual import retains its own controls.
A background observer checks every three seconds, even before Watcher starts
and with recording or UTC sync disabled. SimConnect open/close takes priority;
it requests no telemetry and changes no simulator state. When it is unavailable,
known process names owned by the same Windows account provide an optional
fallback without admin rights. Microsoft Flight Simulator processes retain
flight wording. Cyberpunk 2077, the Mass Effect trilogy, Skyrim and Dragon Age
have localized Begin/Finish easter eggs; unknown/ambiguous games retain general
wording.
The SimBrief Load/Unload button, its settings icon and status appear only for
a detected flight; changing context hides/restores them without unloading a
plan.
The legacy key action uses **Abandon flight…** or **Abandon game…** in
Watcher, Import and progress. It clears the local key after its explicit
confirmation and cleanup. Linked sessions offer **Keep files locally…** in
Watcher progress.

The bundled offline catalog covers 10,405 games and 10,888 Windows executable
rules from a dated Discord snapshot; it is broad rather than complete. Detection
never downloads data or sends a process list. Add process names/phrases to
`%APPDATA%\PHPGalleryUploader\game-contexts.json` and restart, without
rebuilding.
See [game context rules and examples](../docs/WINAPP_GAME_CONTEXTS.md) for
schema,
source provenance, account filtering, ambiguity and deliberate catalog updates.

Watcher and Import show `Gallery: <title>` using the current display title from
the authenticated inventory response, with long names wrapping inside their
row. The gallery title owns its sanitized physical folder name: saving a title
change also renames the folder and updates descendant paths. Inventory keeps
the human-readable title and sanitized storage path distinct as values.
Older APIs without `gallery_title` display the final folder segment.
Hover over or focus the name to see the complete physical folder
hierarchy on separate indented lines, from the root to the selected gallery.
The heading and tooltip follow connection changes; the full identity remains
available to flight records and import history.

**Stop Watcher** finishes accepted work using the saved report preferences.
**Finish flight…** also requests all available PDF editions. Both keep the
current upload connection. Linked reports stay on the computer. Manage
server-side API key revocation separately in PHP Gallery Admin.

| Watcher state | How it starts | Next action |
| --- | --- | --- |
| Ready | Upload target and folder are chosen; the previous session is closed | **Start Watcher**, or use Import for selected files. |
| Starting | Start verifies the gallery and reads the folder's existing files | The Watcher card shows the current preparation phase and activity. Wait for REC before creating a new screenshot. |
| Watching | **Start Watcher** captures the current target and ignores files already in the folder | **Stop Watcher** or **Finish flight/game…** stops discovery and finishes the accepted queue. |
| Finishing | Stop or Finish is draining work or producing reports | **Show progress…** reopens the progress view. Success returns to **Start Watcher**. |
| Pending | WinApp restarted, the gallery became unavailable or completion failed | **Review pending uploads…**. Reconnect to the same site and choose the original gallery if prompted, then **Retry**. |
| Closed locally | Confirm **Keep files locally…** for a linked session in progress or recovery | **Start Watcher** becomes available. Sources, reports and uncertain-upload evidence stay on disk. |

The reconnect dialog prefills the verified saved installation address. The
session's queue retains its original gallery across restarts and target
changes require resolving it first. When gallery access is gone, local
closure still works. If a transfer result is uncertain, **Review retained
upload…** remains the separate decision for continuing other files. Neither
closing a session nor an inventory check retries the uncertain transfer.

**Abandon flight…** is available beside Finish in Watcher and Import and in
ordinary flight progress. Use it when the gallery was deleted, its key was
revoked, or you want to leave the current connection without completing
delivery.
After explicit confirmation it stops recording and upload producers, waits for
in-flight requests to settle, marks unresolved Watcher sessions as abandoned
and removes the saved key from this PC after checking accepted imports. No
connection test, report generation, upload or server revocation is required.
It also works when
no flight tasks remain and only the obsolete key is still configured. Raw
recordings, queued photograph sources, existing PDFs and acknowledged receipts
remain local; the main action becomes Begin for a new connection, using
flight, game or contextual wording according to the current detection.
A key that remains valid on the server must be revoked separately in Admin.
An already submitted revocation must settle before abandonment can begin.
Failed worker shutdown or local configuration persistence keeps recovery visible
and retains the key for retry. Abandonment does not claim server revocation.

Open **PDF > Saved flights and PDFs**, or the same action in Flight settings.
The passive tree shows session start, aircraft, checkpoint sample count, local
PDF count and state. A flight with one version expands directly to its PDF
files. With multiple versions it expands to version groups (v5, v4, v3…),
each containing its language files. Versions always sort numerically newest
first, independently of the selected column/direction. These are display groups;
browsing does not move or rename files. File rows show language and actual
page count (pypdf) and creation date/time including seconds in local time.
New renders retain creation UTC in their private manifest. Older PDFs use a
star-marked file modification timestamp; deterministic embedded PDF dates are
not mistaken for creation time. Unknown page counts remain visible as `?`.
Double-click or **Open selected PDF** uses the system's default PDF viewer.
Ctrl/Shift selects files across versions of one flight for upload. **Refresh**
reloads
checkpoints without analyzing samples; **Open app log** opens the existing
rotating log. Report work logs its session, phase, edition and failure details.

Click a column heading to sort flights and their retained PDFs; click again to
reverse the direction. The initial order is newest flight first. Names sort
without case differences, counts sort numerically, and dates use their actual
UTC instants. Flight start now includes seconds. Created/page columns order
flights by the newest PDF creation time/total known pages and order individual
PDFs by those same fields; version groups keep descending version order.
Refresh retains direction, expanded flights and
multi-selection, including expanded version groups. Missing pypdf leaves page
counts as `?`, preserving the file tree, timestamps and ordinary open/upload
actions. Creating new PDFs requires
ReportLab; a missing component gives an installation/repair message rather than
classifying the recording as damaged.

**Finish flight and create PDFs** uses the same Finish flow,
enabling EN/CS/DE/SV for pending sessions even when automatic creation was
disabled, without changing that saved preference. Loaded OFP is saved to the
current flight, or the sole pending flight if none is current. It is never
copied across several unrelated pending flights. Partial recordings also render
with their incomplete/unknown evidence; no takeoff or landing is fabricated.

Select a stopped session and choose **Create local PDF** or **Re-create local
PDF**. This reads saved raw data without requiring its former gallery key. It
does not stop another active flight or upload/revoke anything. Each action
automatically uses an already loaded OFP and creates four fresh numbered
editions
in the common `reports/` folder without a confirmation, preserving all earlier
PDFs,
checksums and delivery receipts. A stopped partial flight may render, but
remains
explicitly incomplete; empty recordings create no blank PDF. Active or
interrupted-unclosed recordings must first finish recording.

Every selected language renders concurrently in a separate spawned process
(one per language, currently at most four). Analysis and both map backgrounds
are prepared once in the parent; children receive normalized flight data and
map bytes, with isolated ReportLab font state. Only the parent owns gallery
credentials, progress, completion journals and uploads. Automatic finalization
waits for the complete language set before uploading any of its PDFs. A render
failure retains successful files and prevents delivery of a partial set; process
startup failures remain visible for retry without a serial fallback.

Local work has independent `manual-report.json` phase, edition paths and error
state. Cancellation stops queued renders and joins already running editions;
their completed PDFs remain available. Local
creation never resolves pending remote delivery or changes `completion.json`.
Shutdown/update cancels and joins local rendering through existing worker
ownership. Windows layout, installed-app recovery and live simulator acceptance
remain pending when this work is prepared without launching the local app.

To send previously generated PDFs, save a valid gallery key (a new key for the
same gallery is supported), test the connection and open **Saved flights and
PDFs**. Expand the stopped flight and select specific PDF files, including
edition subsets from several versions. Choose **Upload saved PDFs…** and confirm
the displayed gallery and exact file list. Server inventory must confirm that
gallery and
`flight_reports_v1` before transfer. The selected revision is uploaded as saved;
it is not rendered again. A different ongoing flight continues, and the key
stays configured. Old plans and key bindings are preserved; separate
`upload-receipts.json` records acknowledged identities/checksums. Retry checks
server status before sending any missing edition, including after a lost reply.
Retry retains the original revision even after another history row is selected.
Cancelling stops between editions and retains completed uploads and local PDFs.
This action sends only the chosen PDFs, never raw telemetry or photographs.

Report protocol errors include the action, HTTP status, Content-Type and a
bounded response excerpt with configured keys and local paths redacted. An
inventory/HTML failure therefore identifies the failed negotiation instead of
implying that the PDF renderer itself failed. Requests carry the same
`PHPGalleryUploader/1.4` User-Agent as the established working client. A
non-JSON 401/403 is classified as an access/protection gateway rejection; a
WEDOS challenge is named explicitly. TLS verification remains enabled. Log
entries identify each selected PDF/revision, confirmed receipt ID/checksum and
acknowledged count/gallery; activity alone never means upload success.

**Open folder with PDFs** opens the actual PDF directory, never the log or
raw-session root. Filenames contain recorded UTC date/time, aircraft, language
and a numbered version, for example
`2026-10-18 09-46-05 UTC - FenixA320 IAE SL - CS - v03.pdf`.
Private checksums and identities live in `reports/.metadata/`. Explicit
generation/folder opening organizes older automatic and nested manual editions
with a restartable journal and atomic renames. Report bytes, delivery IDs, raw
recordings and completion receipts are retained; a destination collision or
redirected path stops migration. Browsing history never renames files. Keep
`.metadata` when backing up the PDFs.

New report editions use A4 landscape (297 x 210 mm), a navy running header,
teal accents, pale background and white rounded panels. Embedded regular/bold
DejaVu supports every edition. Titles use 28 pt, normal text 12 pt, secondary
notes 9.5 pt and plot axes 9 pt. Overview cards sit beside the captured route;
full-flight profiles appear two per page. Each landing has a summary with its
wind card/configuration, followed by a separate page of four larger profiles.
The template identity advances to 9 so older PDFs are retained as earlier
editions; recording, analysis, report upload and completion remain unchanged.

Both route maps fit the complete recorded and optional planned geometry with
an equal margin, independently of print resolution. Short routes receive local
map detail. A detached position block bounded by jumps of at least 50 NM and
a return within 50 NM of the pre-jump position is omitted from map geometry
only when every block point is at least 50 NM from both neighboring positions.
Both maps disclose the omission; raw samples and analysis remain unchanged.
Unbounded relocations and distant starting/ending segments remain visible.
The overview omits the redundant capture/grid explanation after the
phase strip, avoiding a separate page containing only that note.

The bundled bold face is the DejaVu font distributed by the official
[Matplotlib project](https://github.com/matplotlib/matplotlib/blob/main/lib/matplotlib/mpl-data/fonts/ttf/DejaVuSans-Bold.ttf),
Git blob `1f22f07c99bd4d4b8fba9e00094df4e58e69def1`, SHA-256
`b184b89e3c1075f22f6b71575b6fc20d4972b3cfd3b23322ca6fd596dcaef167`.
The existing `uploader/resources/LICENSE_DEJAVU` accompanies both faces; the
installer's existing resource-folder inclusion bundles them together.

Reports use vector metric cards, aircraft-state symbols and observed phase
icons in a normally single-row phase strip. Date/simulator build appears in
small header text; technical versions
and full hashes remain private metadata. Altitude is feet AMSL, approach height
feet AGL, and speed IAS knots. Rounded enclosing axes use subtle minor/major
grids: V/S normally uses 200/1,000 fpm, long flights ten-minute/hourly
references.
Scalar plots linearly bridge omitted values/ordinary cadence gaps up to five
seconds in the series color; gaps of five to 300 seconds use amber dashed
segments and an explicit legend. Longer losses, pauses, reloads and position
jumps remain breaks. This is presentation only: raw data, rate fits and measured
statistics never use interpolated values. The recorded route uses a light
OpenStreetMap background with vector tracks and first/last captured-position
markers. Optional ICAO airport labels come from the OFP, not inferred airport
locations. Contact/configuration cards replace dense
raw sensor tables. Noisy change lists disclose displayed/total counts. No
runway,
touchdown score or missing descent measurement is invented. Landing rollout
deceleration cannot arm a rejected-takeoff event. Landing profiles preserve
the original samples in a one-minute pre-contact/two-minute post-contact window
before overview reduction (4,000 per landing, 20,000 per analysis; truncation is
disclosed). Landing detection and fitted descent are distinguished from the
simulator V/S at first contact. Actual pre-contact cadence/largest interval and
incomplete rollout are disclosed. A true-heading compass compares aircraft
heading with wind-from HDG/KT (for example `020/05`), including heading-relative
head/tailwind and crosswind; it does not infer a runway or magnetic heading.
Single landings have an unnumbered heading and compact paired
charts/configuration.

Fuel defaults to kg (including Airbus); Boeing/PMDG names select lb and common
Cessna/Piper/Beech names select US gallons. This display heuristic does not
identify every aircraft. New recordings request optional `FUEL TOTAL QUANTITY
WEIGHT` in pounds and retain `fuel_weight_kg`, independently of core telemetry.
The
[SDK fuel reference](https://docs.flightsimulator.com/msfs2024/retail/programming-apis/simvars/aircraft-simvars/aircraft-fuel-variables/)
defines this mass signal. Old volume-only captures use a visibly marked
0.80 kg/L estimate for mass display; measured values take priority. Raw gallons
are never overwritten and unavailable fuel stays unavailable.

## Optional SimBrief and route maps (0.6.0)

Optional SimBrief loading sits beside Begin/Finish flight, with an adjacent
identity gear. Enter one pilot ID or username; only a successful OFP fetch saves
the identity. Loading is explicit and never required for recording. The staged
plan is consumed by the next session; plans attached during recording belong
only to that session. No previous flight plan is automatically reused.
The identity sheet uses the same dark controls as the main app, with inline
validation, Enter to load and Escape to cancel. The main action switches from
**Load SimBrief** to **Unload SimBrief**, with the accepted airport pair below.
Unload clears the staged/current plan, removes the current session's OFP
snapshot and hides its route, allowing recording and new reports without
SimBrief. It preserves older sessions and existing PDFs. While loading or
finalizing, the action leaves accepted evidence unchanged. After recording
stops, the main control hides that historical route and offers loading again.
The identity gear can still reload or replace an accepted plan. Failed
reloads retain the previous accepted plan; a new unrelated flight clears it.
Historical OFP attachment appears in the selected flight's details. Successful
loading updates that status/activity without opening a message box. Flight
settings, history, progress and identity sheets open over their invoking window;
confirmation/error dialogs use explicit ownership as well.
Saved flights also offer loading the latest OFP directly into the selected
flight,
or attaching an archived SimBrief JSON/XML export. A PDF-only OFP cannot supply
machine-readable dispatch data. Create a new local PDF version afterwards;
existing PDFs and delivery receipts remain intact.
Reports include recorded OpenStreetMap route maps independently of SimBrief.
An attached OFP adds a separate planned route, planned passengers and ramp fuel.
These values never replace measured telemetry. Coordinates come from the OFP
navlog and are retained in a bounded, AIRAC-scoped local SQLite evidence cache;
ambiguous names are not guessed, and no external navigation subscription is
required. Displayed OSM tiles are cached for at least seven days, with
attribution
and a bounded cache. Missing network tiles leave the route visible on a pale
background. Mercator maps clamp polar latitudes; original geographic coordinates
remain in the saved raw recording. Online tile rendering sends viewport tile
coordinates and the app User-Agent to OSM, without Gallery keys or SimBrief
identity.

Maps use native tile pixels sized for their PDF placement at a minimum of
300 dpi, with vector recorded tracks and purple dashed OFP routes. The
first-page
map fades its background; the final map shows the same geography at full
contrast.
Only the requested viewport is fetched, with a 45-second network budget and
existing cache/attribution. The first provider failure ends new requests for
that
viewport while cached tiles and vector routes remain available.

New report filenames may add the OFP flight number and departure/arrival ICAO,
for example
`2026-10-18 09-46-05 UTC - CY0734 - LKPR-EDDF - A320 - EN - v06.pdf`.
Airline codes and leading zeroes are taken from the OFP; none are guessed.
Existing journaled filenames, prior PDFs and delivery receipts are preserved.
Two optional planning pages show passengers, cargo/payload, aircraft
registration,
runways/alternate, cruise altitude, cost index, distance/duration, scheduled UTC
out/in times, fuel components and estimated weights. Unknown fields stay
unknown.

Fuel and altitude charts can overlay timed navlog values. Detected takeoff
anchors
the OFP clock; captured simulator time maps each planned fix onto the recorder's
host-elapsed axis, including simulator-rate changes. Unit conversion is explicit
for known kg/lb mass, and the planned line breaks at capture discontinuities.
If takeoff/time alignment is unavailable, an explicitly labelled position
comparison matches planned fixes within 10 NM of captured route legs. It needs
two distinct, ordered matches and never extends across capture gaps or position
jumps. This mode compares quantities at recorded positions, without inventing
a takeoff time. Missing matching data or compatible units suppress comparison
with a visible chart explanation. Sparse OFP fixes are connected linearly and
are
always identified as planning evidence. Old dispatch snapshots remain readable,
but need an explicit OFP reload/import to obtain fields not retained previously.

### Plan-versus-actual analysis and completed flightplan

Attached dispatches add the same analysis in EN/CS/DE/SV, after the existing
maps, profiles, landing detail and planning pages. The final section is a
repeated-header flightplan retaining every original OFP point in order:
planned/actual elapsed time, simulator UTC date/time, remaining fuel, altitude,
TAS, GS, wind and closest-passage separation. Intermediate fixes require an
ordered passage within 2 NM; airport rows use detected takeoff/landing within
10 NM. Passage values are estimates interpolated only inside a continuous
original sample interval. Unmatched points keep their planned values.

`flight_comparison.py` processes the original JSONL in a bounded second pass,
independently of the 2,000-point overview. Means and distributions use simulator
time, not frame counts or wall time. Summary rows distinguish airborne/block
time, ground-track distance, trip consumption, initial/landing fuel, weights,
reserve difference and estimated schedule delay. Full flight totals require a
matched single takeoff/landing and complete relevant evidence. Route distance
is never compared to the OFP's air-distance fallback. Fuel mass is measured;
historical volume-to-mass estimates remain confined to the existing charts.

Vector error profiles show time deviation, remaining-fuel deviation,
initial-load-adjusted net consumption, pressure-height error and route
separation at projected OFP positions. Ordered fix matches in the final table
remain a separate, stricter comparison. The existing map highlights displayed
segments beyond the descriptive 5 NM route band in amber. Phase tables include
time, net fuel loss, fuel coverage, consumption per hour/NM, IAS/TAS/GS, V/S,
and track-relative head/crosswind. OFP stage totals are shown only when their
inputs are complete; phase boundaries remain inferred.

The analysis uses compact two-column panels, paired P/A/difference cells and
six operational phase groups instead of one row per transient detector state.
Takeoff joins climb; approach/flare join descent; contact/rollout join arrival
ground movement. Original phase detail and coverage remain in the analysis.
Phase variance bars compare plan with actual; speed whiskers show observed
min/max and a time-weighted mean body, with an explicit planned TAS reference.
They are descriptive ranges, not confidence intervals. Lower time/net fuel use
and higher remaining fuel are green; their opposites are red. Loading, speed
and wind changes retain neutral meaning. No empty pressure plot is allocated
for a legacy capture; available geometric height appears as AMSL with a `*`
marker in the flightplan and never substitutes for pressure-height error.
Absent simulator UTC is explained once and the UTC column shows the OFP clock;
host receipt times never become simulator time. Missing table values use a dash.

An OFP may repeat an airport in its navlog and endpoint. Verified duplicate
airport anchors may share the same observed event time, preserving both source
rows and complete summary metrics. Arbitrary coincident intermediate points
still require separate chronological passages. Planned values remain visible
when a corresponding measurement is unavailable.

Signed bias, MAE, RMSE and absolute-error P95 retain metric-specific covered
time. Time-weighted histograms use 1-second, 0.1-NM and 25-ft bins; P95 is an
upper-bin estimate except for the overflow bin's lower bound. Graph thinning
does not change statistics. Descriptive bands are 5 NM and ±300 ft; these are
not operational limits, pilot scores or causal findings. Fuel loss may also
include dumping/removal; extra fuel at takeoff is not classified as savings.

New optional subscriptions retain `PRESSURE ALTITUDE`, parking brake and the
simulator Zulu calendar independently from host UTC. Height deltas require
pressure observations above both known airport transition altitudes. Estimated
OUT/IN require five simulator seconds of stationary parking-brake/movement
evidence and do not identify a stand. Simulator dates more than a day from the
OFP suppress schedule-delay numbers. Missing data, fuel increases, clock breaks,
multiple legs and unmatched plans are disclosed and never filled with zero.
Old JSONL/OFP snapshots remain readable with unknown additive fields; new data
requires a new recording or explicit OFP reload/import. Analysis schema 4 and
template 11 distinguish new editions while preserving prior PDFs and receipts.

The main SimBrief control identifies a staged plan as belonging to the next
flight. Saved-flight details separately show whether that flight has an OFP.
When generating saved-flight PDFs, the loaded plan is attached before the
worker starts, without another confirmation. With no loaded plan the flight's
existing OFP remains unchanged. No fetch or identity prompt is triggered by
generation/Finish. Manual PDF progress also closes after success; historical
PDFs/receipts remain intact. A new explicit OFP load after Finish clears only
cancelled results from the previous completed provider worker.

## Updating the Windows application

The application checks public GitHub releases at startup and at most once hourly
while it remains open. **Check for updates** also runs an explicit check. Checks
use no Gallery API key; their timestamp and any available offer are stored
separately in `%LOCALAPPDATA%\PHPGalleryUploader\updates`, so restarting within
the hourly interval can still display an already discovered update.

A validated offer remains visible as a version banner and marked update control
in the main header across all screens. **Later** hides the notice but leaves
both
indicators available to reopen it. When tray support is available, the tray icon
shows a red update badge, its tooltip identifies the offered version, and its
first menu action becomes **Update <version>** and opens the same notice.
Without
tray support, the full in-app indicator remains available. The app does not set
a Windows taskbar overlay or show operating-system toast notifications.

A failed network recheck preserves the validated cached offer across restart;
cached release metadata passes the current validator before the offer is
restored. A successful check that finds no current offer clears the banner,
header mark, and tray badge.

Update checks and installation preparation automatically write structured
`[updater]` records to `watcher.log`. They identify the operation, stage,
endpoint role, trusted host, result and elapsed time. Failures retain HTTP
status, typed transport causes and available numeric platform/TLS codes;
transfer and verification records include known byte counts. Checkpoint,
helper handoff and cleanup have separate stages. A cleanup failure keeps the
original update failure available for diagnosis.

These records exclude credentials, signed download queries, response bodies,
raw exception messages and local paths. The notice keeps its concise status;
the ordinary log supplies the technical detail automatically, without a debug
switch or another reproduction with a patched executable.

**Copy diagnostics** includes a bounded updater snapshot and its retained last
failure even after a later successful check. It also includes current-session
Photos failures, distinguishing catalogue reads, thumbnail transport and image
decoding. Switching albums keeps those facts; switching the approved live
session resets the Photos snapshot. Full records remain in the log.
The background preload worker uses the same safe diagnostic formatter for
listing, cache checks, transfers and final verification. Its first and later
distinct failures appear immediately in the ordinary log, the visible Log and
copied diagnostics. Every terminal outcome records processed, stored and
skipped counts. An album change keeps a running preload's diagnostics within
the same approved session; another session cannot receive them.
Rejected native-media responses include bounded cache-policy facts: which
cache marker and cache-control contract matched, the parsed age and required
Vary/encoding conditions. Raw headers are not copied. These facts explain a
refusal without relaxing media admission or permitting an unapproved disk copy.

Discovery examines paginated releases for the highest newer
`PHPGalleryUploader-X.Y.Z-Setup.exe` asset. CMS release tags are not the
uploader
version. The highest candidate must have a trusted published SHA-256 digest;
missing digest information refuses the update rather than selecting an older
installer. Downloaded bytes, file size and SHA-256 are verified before Setup is
allowed to run. This verifies consistency with the GitHub release metadata; it
is not an independent publisher code-signing guarantee. Older release assets
without digest metadata may therefore require a manual installer download.

The nonmodal notice offers **Update** and **Later**. Update downloads with
actual byte progress and **Cancel download**, verifies the installer, and
prepares a safe Watcher checkpoint before launching the external helper.
The Watcher stops dispatching new files after the current upload response and
its acknowledgement/source handling finish. The recorder flushes the same
session. A 120-second checkpoint budget postpones installation if accepted work
cannot settle; the always-running game-presence observer is not a blocker.
Finish an active manual import, AI operation or finalization before updating.
Helper preparation failure leaves the app open and schedules safe continuation.

The private `flights/watcher-continuation.json` contains the exact session,
profile and target fingerprints, original linked scope, versions and one-use
helper nonce, never credentials. After installation the same queue, initial
file boundary, recording identity, preferences and telemetry are restored.
Screenshots created during installation remain eligible. Telemetry appends to
the original history with an explicit gap. The first resumed photo dispatch
waits until Tk durably consumes the checkpoint; a rejected checkpoint cannot
send a photograph. Explicit Stop or Quit cancels continuation.

Linked continuation requires the same restored account, issuer, gallery and
exact scope; it never falls back to the saved API key. A contextual Watcher key
is closed before handoff and recreated only under the existing approved grant.
Missing/revoked access keeps the original files and shows a specific reason.
An older helper that never saved active intent cannot prove whether old
unfinished work was running. A verified old-version update can offer **Resume
Watcher** for an unambiguous API session with no interrupted accepted upload;
that first compatibility continuation is explicit and does not require Abandon
or a replacement API key. Stopped/finished sessions remain stopped.

Update shutdown does not save unsaved Settings fields. Persisted settings,
credentials, import checkpoints and flight artifacts remain in the original
profile. Only after the checkpoint and helper readiness does the existing
close owner terminate child processes and exit the uploader.

For the installed standalone EXE, a copied external helper acknowledges
readiness
before the application completely exits. The helper
waits for both PyInstaller processes from that installation to exit, invokes
Setup with the existing installation directory, and verifies the installed
version before restarting the app. Windows may request administrator approval.
After the installed version is verified and the app restarts successfully,
the helper progress window closes automatically. Installation failures and
Windows-reboot requirements remain visible until dismissed.
Canceling that approval keeps the old application and permits its restart.
Source-mode Python runs can check and download updates, but offer **Open
installer**
for manual installation and never overwrite the Python source folder. Only a
frozen `PHPGalleryUploader.exe` outside a PyInstaller extraction directory is
eligible for automatic handoff; helper validation also protects the target path.

The frozen updater explicitly loads `version.dll`, `kernel32.dll` and
`shell32.dll` using the Windows System32 search policy. This prevents
PyInstaller from redirecting the extensionless `version` request to the bundled
`VERSION` text file on case-insensitive Windows; no native DLL bundling is
needed.
Before Inno Setup compilation, the build runs a read-only native API smoke check
inside a copied frozen helper outside the original executable directory.

For an upgrade from 0.3.1, manually launch the verified 0.3.2 installer. The
running
older executable copies its helper before installation, so its old helper can
still report the native-DLL error after replacing the app. Future updates
started
from the corrected 0.3.2 executable use the repaired helper. Retain the original
0.3.1 installer and its update metadata; the new installer has distinct metadata
at `winapp/dist/0.3.2/winapp-update.json`.

## Windows installer (next version: 1.0.3)

The companion app has its own version in `winapp/VERSION`, independent of the
PHP
Gallery CMS release. The current source targets **1.0.3**; this source version
change does not build or publish an installer. A separately requested build
will place the installer and generated `winapp-update.json` in
`winapp/dist/1.0.3/`. Existing version outputs and other version folders remain
preserved.

The update notice fits its wrapped status text and full button row as background
checks change the message. Text and action-row backgrounds match the window;
the primary **Update** action remains distinct.

Develop integration compares the complete tracked installer inputs with current
`main`. If runtime, assets, dependencies, build/installer inputs or `VERSION`
changed, the candidate version must be strictly greater than main's. Tests,
`README.md` and `AGENTS.md` do not trigger this requirement. Several
integrations
may retain one upcoming version while main still has its previous version.
After publication advances main, the next runtime change needs another bump.
This admission runs before FULL and is rechecked before protected publication;
release itself keeps trusting qualified develop.

The installer includes Python, Tkinter, Pillow, pystray, ReportLab,
the bundled DejaVu font and its license, the tray icons, and an embedded
`SimConnect.dll` inside one application EXE.
There is no separate DLL to install or distribute. End users do not
need Python. Installation requests administrator rights and defaults to
`Program Files\PHP Gallery Uploader`, with a Start Menu shortcut, an optional
desktop shortcut for all users, and a normal Windows uninstaller. Existing
settings and jobs
in `%APPDATA%\PHPGalleryUploader` survive upgrades and uninstalling.

Directly launched Setup closes uploader processes whose executable path matches
its selected installation directory, including the tray app and PyInstaller's
onefile parent/child processes. Self-update exits its own process and terminates
its multiprocessing children after helper readiness. The helper refuses
replacement while an exact-path
process remains. Setup checks the fresh process list for up to three seconds
and refuses installation if shutdown cannot be verified. Copies running from
another directory are untouched. These checks also apply to silent installation.
File locks remain a secondary safeguard; the final launch checkbox controls
relaunch for a directly launched installer.

To build on Windows, install x64 CPython 3.14.x with Tkinter and Inno Setup 6.3+
(including Inno Setup 7), then run from the repository root:

```bat
winapp\build.bat
```

The script downloads the build dependencies into a temporary virtual
environment,
validates the x64 SimConnect DLL and required exports, uses PyInstaller
`--onefile --windowed`, verifies the embedded DLL against the source SHA256,
checks the EXE startup with `--help`
using a disposable app-data directory, then calls the installed `ISCC.exe`.
Internet access to the configured Python package index is needed for each build.
Both hosted installer workflows select the latest available 3.14 patch for x64.
The builder prints and validates its interpreter, then runs the frozen EXE's
early `--runtime-info` diagnostic before packaging. Its fresh staging report
identifies the actual embedded patch version, implementation and pointer width,
before configuration/logging/GUI initialization. Missing or non-3.14/x64 CPython
reports fail the build; the runner version alone is insufficient. This does not
change the independent WinApp version.
When the required dependencies are already installed, build without downloads or
package installation using the existing Python and Inno Setup:

```bat
python winapp\build_installer.py --use-installed-dependencies
```

This mode verifies the installed dependency versions and keeps all intermediate
files and caches in the same disposable staging directory.
The build script produces the installer and its matching update metadata
together
in a folder named after the independent WinApp version:

```text
winapp/dist/0.6.0/PHPGalleryUploader-0.6.0-Setup.exe
winapp/dist/0.6.0/winapp-update.json
```

Attach both files from that folder to the same GitHub release as the CMS
release.
The JSON is generated automatically from the completed installer, including its
filename, version, byte size and SHA-256 digest. It provides trusted installer
metadata when GitHub's asset digest is unavailable. Its schema is:

```json
{
  "assets": [
    {
      "name": "PHPGalleryUploader-0.6.0-Setup.exe",
      "version": "0.6.0",
      "size": 12345678,
      "sha256": "<64-character hexadecimal SHA-256 digest>"
    }
  ]
}
```

The app checks for updates at startup and then at most once per hour while it
remains open. The installer is unsigned and may be uploaded manually to GitHub
Releases.
The script never changes CMS version markers or publishes a release. A failed
build returns a nonzero exit code and preserves any previous successful
installer
and its metadata. Older version folders and historical files directly under
`winapp/dist/` are left untouched; new builds publish only to their version
folder.
Its unique `winapp/.build-*` staging directory (virtual environment, downloads,
caches, spec file, work files, intermediate application EXE and partial
installer)
is removed on success, errors and normal Ctrl+C cancellation. Forced process
termination or power loss can leave a staging directory; remove that `.build-*`
directory manually before retrying if needed. Existing unrelated files are never
cleaned. Add `dist/`, `build/`, `.build-*/`, and Python build caches to the
local
`.git/info/exclude` to keep generated files out of Git.

The compiler is discovered on PATH and in standard Inno Setup installation
folders. Custom tool locations can be selected explicitly:

```bat
set "PHP_GALLERY_PYTHON=C:\Program Files\Python314\python.exe"
winapp\build.bat --iscc "C:\Program Files\Inno Setup 7\ISCC.exe"
```

`ISCC_EXE` is also accepted as a compiler path override. Alternatively, run
`python winapp/build_installer.py --iscc "C:\path\ISCC.exe"` directly.

The standalone build includes the normal uploader and Pillow AI fallback.
Optional
in-process Transformers/PyTorch packages are not bundled or installable into the
frozen EXE; use Ollama, an external analyzer, or the Python source installation
for
that optional backend. Dependency repair in the EXE reports bundled dependency
status without starting pip. The separate `gallery_http_monitor.py` diagnostic
tool remains a source-only utility.

## Main window

A normal launch opens **Photos**, the first task in the navigation order:
**Photos**, **Watcher**, **Import**, **Activity**, **AI**, and **Settings**.
With an active gallery link, Photos loads a gallery tree on the left and a
photo grid on the right. It opens an initial gallery automatically. Expand or
collapse a branch or the whole tree and click a gallery to show its photos in
the same window. When disconnected, **Connect** opens the existing address
dialog and browser approval flow. An active listing link can browse public
photos directly after approval. Large trees first load up to 500 entries, then
continue automatically from the saved cursor, one page every five seconds.
Loaded galleries remain usable while the loading status indicates more work.
An error stops continuation and offers an explicit retry; normal browsing needs
no **Load more** click. Choose the upload target in
Watcher or Import using **Use for uploads**. A logon `--startup` launch stays
in the system tray until restored. An approved saved link can resume on
startup.

The Watcher view shows the gallery target. The persistent API-key connection
control opens the compact key dialog from every view. The separate ordinary
header **Link gallery** flow remains the upload/search path. The app header
also has Settings, updates, and help controls. Icon controls have
hover/focus tooltips and keyboard focus; help opens usage guidance.
Long pages and dialogs scroll with the mouse wheel or scrollbar; text and table
controls keep their own scrolling.

The Watcher screen keeps the selected folder path, watcher status, and source
deletion warning visible. **▶ Start Watcher** starts only after an explicit
click; while active it becomes **■ Stop Watcher**. **Check folder only**
performs
a non-uploading dry run and does not start watching. The dedicated Watcher gear
opens a settings sheet for folder selection, recursion, scan interval, file
stability, simulator metadata, and the saved source-delete setting, with
explicit
**Save watcher settings** and **Cancel** actions. **Sync UTC time** remains an
opt-in control beside the primary actions, with a nearby **?** explanation.
The sheet requires the watcher to be stopped before edits. Saving stores those
settings for the next start; **Cancel**, Escape, or closing the sheet restores
its
opening folder, ignored-file count, last-scan status, and preferences.

Pillow adds an ambient cool gradient and opaque glass-inspired card, footer, and
pill-button artwork. This composited treatment simulates frosted glass without
true alpha transparency or operating-system backdrop blur; text and input/table
surfaces stay solid, high-contrast, and sharp. It uses no whole-window opacity
and adds no runtime dependencies. Without Pillow, readable flat ttk surfaces
remain, and native controls and source-mode operation, including `--once`, stay
supported.

Manual **Import** still requires preflight review and an explicit start.
**Activity**
shows the filtered recent-event list, retry controls, redacted diagnostics, and
the log panel. A compact footer on each screen shows the latest event; selecting
it opens Activity. AI metadata remains optional and disabled
by default. The app header keeps the connection and watcher states visible.

## Setup

1. Open the target gallery in PHP Gallery admin.
2. Generate a gallery-scoped upload automation API key.
3. Copy the key when it is shown.
4. Run `install.bat` from `winapp`, or start `gallery_watch_upload.pyw`
   directly.
5. Open **Photos** to browse. Use **Connect** there when disconnected. For
   upload setup, select **Watcher** in the rail and then use the header
   connection control.
6. Enter the gallery site URL or upload endpoint.
7. Paste the API key with the **Paste** button or type it into the masked field.
8. Press **Save & Test** in the compact connection dialog.
9. Confirm that the header reports **Connected** and the Watcher view shows
   the expected target gallery.

PHP Gallery advertises the portable query-string front-controller endpoint as
the canonical Windows-uploader URL:

```text
https://example.com/index.php?page=upload_automation_upload
```

A site root or the optional clean alias `https://example.com/api/upload` can
still be entered. The desktop app canonicalizes either form to the query-string
endpoint before saving or sending authenticated work. This is deliberate: some
shared-hosting proxies and WAF configurations route ordinary clean URLs
correctly but mishandle authenticated or multipart POST requests under `/api/`.
If the canonical query-string endpoint is rejected by the web server with a
non-JSON HTTP 404 before PHP Gallery is reached, the client may retry
`/api/upload` as a compatibility fallback. Gallery-generated JSON errors such as
invalid/revoked keys, forbidden access, or a missing target gallery remain
authoritative and are not hidden by route fallback.

### Browser-approved gallery account

Click **Link gallery** in the main window. Choose a recent address or paste
the gallery address from your browser and confirm **Link gallery**. The
dialog shows progress while the app checks the gallery and waits for browser
approval.
You can also type a host and optional installation path without a scheme,
such as `gallery.example/Galerie`. On confirmation, WinApp assumes `https://`
and shows the normalized address in the field before checking the gallery.
Sign in as an administrator if prompted, then choose **Approve** or **Cancel**
on the consent page. **Approval received** means you can return to WinApp.
The app verifies the loopback response and exchanges the
one-use code using PKCE. On success it closes the dialog, shows the linked
site's address and loads its first gallery page. If linking fails, the dialog
shows the error and lets you retry.

Use the **⋯** beside **Unlink gallery** to open the same chooser while
connected. **Linked now** lists the installations with live access. Select
one and click **Switch to gallery** to use its current approval. **Recent
addresses** keeps up to 20 previously used installation URLs across restarts.
Choose one to resume a saved grant or start browser approval. The power icon
unlinks the selected live
site, and the × removes a recent address from the profile. The browsing
site and the upload destination have independent selections. Use **Choose
gallery…** and **Use for uploads** to change the upload destination.

A gallery on the same computer can use its direct `http://localhost`
address. WinApp preserves the installation port and path. For other pasted
HTTP addresses it tries verified HTTPS at that host and path, preserving any
explicit port. The server uses its configured address for the exact issuer.

This action requests account and gallery-list access, including
metadata search when the server offers it. On an admitted local Windows or
macOS profile it also requests image uploads when offered. Consent shows the
actual permissions, including access to private and unpublished galleries.
On compatible servers, the browser also asks you to approve rolling access.
The approval lasts seven days from the last successful authenticated use or
refresh. WinApp renews its 15-minute access token with a credential kept in
macOS Keychain or Windows Credential Manager. This works over verified HTTPS
or direct `http://localhost`. Older servers use a temporary link lasting up
to 15 minutes. Expiry or revocation requires new browser approval. Browser
linking creates no gallery API keys. Open **Settings** → **Manage link…** for
separate permissions and saved-link controls.

Open **Choose gallery…** beside the linked address and type in **Search
galleries**. Suggestions favor names, then descriptions, then gallery tags.
Names also match a nearby spelling, for example `cyberpnuk` or `cyberpuk`
for `Cyberpunk`. Use quoted phrases, uppercase `AND` or `OR` as on the
website. Indented rows show nesting and the selected row shows its parent
titles. The arrow loads more results for the same search. A basic link or a
catalog beyond the 10,000-gallery search budget can filter the current
page by title. For catalog search on a supporting server, approve a new link.

Choose a result and click **Use for uploads**. WinApp uses the browser-approved
upload access for that gallery, including on a fresh profile without an API
key. After verification the picker closes and **Upload gallery** shows the
destination in Watcher and Import. Save a folder with **Choose folder…** in
Watcher or add files in Import. The ellipsis reveals more gallery actions.
**Unlink gallery**
ends the current link; a destination using that link needs reconnecting.
API-key uploads have their own **Gallery URL** and gallery-scoped `pgu_` key.
The gallery installer and updater provision the linking key
automatically. The server uses its saved website address, including the
installation path. Existing installations with explicit linking settings keep
those settings.

With separately approved read permission, select a gallery and choose
**Read selected details** to see its ID, title, revision and saved visibility,
access and listing settings. These are stored values, not inherited effective
access. A read already checked by the server can complete after concurrent
revocation. An older server may not support this permission or endpoint; the
client reports unsupported or unavailable without silently reducing scope.
The saved `pgu_` upload path remains available. Disposable HTTPS protocol and
desktop/narrow consent rendering have been exercised. Installed Windows and
deployed browser pairing remain unqualified. See
`docs/APP_LINKING_PHASE_0.md` for the current protocol and gates.

The separate `gallery:visibility` permission is offered only when the server
advertises it, and requires explicit browser approval. Before a change, WinApp
confirms the current gallery state; a stale revision or schedule conflict
causes a read-only refresh and never replays the change. Older servers omit
the permission. PHP Gallery's operator inspection defaults to read-only; only
an explicit current review fingerprint can reconcile eligible v2 sidecar
evidence and retire its marker without changing the database revision. Installed
GUI acceptance, MariaDB and deployed recovery behavior remain unqualified.
Candidate `8de8e50a` passed BASIC `42107055888` and WinApp `42107055797`; see
`docs/APP_LINKING_PHASE_0.md` for evidence and limits.

The active gallery listing link permits listed public, non-sensitive photos.
The same public export policy applies to every ancestor. A supported server
can request explicit administrator media consent to also show protected,
hidden and NSFW media in Photos. An earlier link keeps its original consent
until the administrator approves the added access in the browser. WinApp
verifies the new grant and replaces the saved connection for the same site
and account so Photos can use the added permissions immediately and after
restart. A selected Watcher gallery keeps its existing destination and gets
the newly approved scope. Explicit `presentation:read` permits presentation
settings and visible music.
The server verifies the active grant and direct gallery ownership for every
media request. Internal photo pages contain up to 64 items, or eight on older
servers. Each binary is
limited to 16 MiB. If the selected detail asset exceeds that limit, WinApp can
show an existing display WebP or JPG that passes the same checks and size cap.
The server serves detail as unavailable if it has no eligible rendition.
Presentation lists contain at most 32 visible MP3/WAV tracks.
WinApp uses the same verified link, Pillow for photos and native audio on
Windows and macOS.
For a source launch, use the Python interpreter where `requirements.txt` was
installed so its Pillow decoder can display thumbnails and detail images.
The responsive grid scrolls continuously and loads thumbnails in the viewport.
The default-on **Subgalleries**
switch shows direct child folder cards above the selected gallery's photos.
Each folder uses its first authorized direct thumbnail as a cover. When no
eligible image is available, the card shows a neutral folder. Photos keeps
listings in memory and uses the current link grant. If the smallest thumbnail
is missing, the server can use another existing rendition or the existing
public display file within the same byte limit. Click a thumbnail or focus it and
press Enter to open the inline lightbox. Left and Right wrap across the whole
album. F or F11 toggles fullscreen. Pointer motion briefly reveals controls
including the music transport for an authorized playlist.
Escape exits fullscreen before closing the lightbox. Closing the lightbox or
pressing Escape restores the tree and grid, including after an image error.
Use the mouse wheel or two-finger scrolling to move through the photo grid.
The same gestures and + and − zoom in either lightbox mode. Press 0 to reset
the view and drag to pan. When the server permits a
photo's GPS location, its anchored pin offers **Open in OpenStreetMap**, which
opens the system browser. The lightbox prepares the previous and next photos
in bounded memory, including at the wrap points. Thumbnails trim detected
black bars and show the entire remaining scene at its own aspect ratio.
Photo and thumbnail downloads have a separate five-minute
quota of 600 requests per IP and 3,600 globally. If the server returns HTTP 429, Photos
shows the limit and pauses speculative requests until **Retry**.
**Fit by content** crops
detected black bands in windowed and fullscreen views. **Show original frame**
brings the bands back. Both modes fit the whole resulting frame within the
available area at its original aspect ratio. The grid reflows when the number
of columns changes. The lightbox updates a preview during a window resize and
refines it after the resize settles. On macOS 15 or later, WinApp can display
HDR detail and fullscreen photos when the selected image retains HDR data,
the display supports extended dynamic range, and the Python environment has
PyObjC and a Tk build with the AppKit window bridge. Tk 9 was exercised.
Pillow/Tk provides 8-bit SDR on other configurations, including Windows.
Thumbnails use SDR.

One per-user disk cache stores opted-in photo and audio bytes with their
verified content digests. Its ordinary area keeps up to 4,096 entries or
1 GiB and evicts the
least recently accessed entries under storage pressure. A bounded decoded
thumbnail cache accelerates return visits during the current session. A
current catalogue validator lets WinApp read matching disk thumbnails before
making a binary request. Older servers use photo freshness and authorized
conditional requests. An authorized
current playlist can reuse a cached track with the same SHA-256, size and media
type. Servers returning `no-store` supply media for the current view or
playback. Administrator-only media uses this transient path by default.

Play opens the slideshow in fullscreen. It loops through the full album,
using the authorized server interval when enabled or a five-second default.
The authorized configured soundtrack starts with the presentation. Music
controls can play or pause and choose the previous or next track. Playback
reads a local file and prepares the next track while the current one plays.

Open a gallery or photo's contextual panel with right-click or Control-click
on macOS. Click elsewhere or press Escape to close it. The eye action changes
stored visibility when separately approved. Admin Information shows the
gallery's direct inventory and settings, or the photo's indexed format,
size, dimensions, capture settings and EXIF. Location appears when the
gallery's map policy permits it. JPEG and WebP quality describe the server's
current settings for generated thumbnails. The gallery card also shows its
current local cache coverage. A dotted circle marks unchecked coverage. The
cloud marks online photos, the half circle marks a partial cache, and the
filled circle marks an album with all authorized photo renditions on disk.
The tree and heading update automatically from already loaded photo metadata
and local cache headers. A partial catalogue never claims that an entire
gallery is cached. The heading's cache icon opens the same gallery menu and
explains the symbols on hover.

Windows clips the shared contextual card to its rounded boundary, so the
opaque material's dark outer matte does not cover the content behind its
corners. Resize and dismissal retain the same inline panel. When native
shaping is unavailable, a flat opaque themed surface keeps the controls
readable. Ordinary cards keep their existing material; no transparent image
pixels or operating-system appearance changes are required.

**Preload gallery** reads the selected gallery's direct photos in the
background, including thumbnails and lightbox renditions. Subgalleries are
separate targets. Progress reports photo counts and supports cancellation.
Existing verified local bodies are reused. A bounded server `Retry-After`
pauses the job with a cancelable countdown; exhausted retries leave an explicit
retry-later message. Retry continues from available cached bytes.
**Keep preloaded** also retains the successfully prepared entries in a
separate hashed gallery area. They survive ordinary eviction and restarts and
consume neither the ordinary entry count nor its byte quota. Disabling this
preference returns that gallery to ordinary LRU policy, which can evict excess
entries. Cancelling an unfinished preload keeps its completed entries and pin
preference; it does not mark the whole gallery complete.
An explicit preload with an Admin Photos link can also retain private or draft
photos when the server advertises the Admin preload capability. The gallery,
its complete ancestry and each photo must be verified as non-NSFW. The saved
opt-in and private entries are separated by installation, verified account and
gallery. Later thumbnail and lightbox reads revalidate private entries through
the live server before returning their bytes. Ordinary restricted browsing,
older servers, NSFW media and other `no-store` responses remain transient.
Preloading uses current gallery permissions; local files do not grant offline
or expired-link access. Zero stored photos are reported as unavailable for
preload, with a safe reason, rather than as a partly successful download.
One explicit preload accepts up to 100,000 direct photos as a bounded work
operation; this is separate from the ordinary cache quota.

**Edit gallery** opens the selected gallery in the system browser with the
web Admin editor in its right-side panel. The browser uses its own Admin
login; WinApp credentials are never included in the URL. Older web servers
open their ordinary full-page editor instead.
The add button in the tree toolbar creates a gallery at the site's root.
Use the add button beside the selected gallery's title, a gallery's context
menu or blank photo space to create a subgallery. Each form opens inline.
The first gallery on an empty site opens as soon as the server confirms it.
The tree restores expanded and collapsed branches for each linked site and
account. Use the circular arrow beside the album title to refresh its photos.
While an Admin album is visible, WinApp checks its indexed revision about
every 30 seconds and refreshes when it changes. The current scroll position
and unchanged thumbnail data are retained. Older servers support manual
refresh.

Select photos with Control-click on Windows or Command-click on macOS, or use
the photo context menu. Open the context menu to delete one photo or the
selection using the current Admin Photos link. Confirm each batch of up to
64 photos in WinApp. Deletion uses the same permanent file and index
operation as web Admin. If
the server response is interrupted, refresh and review the album before
confirming another deletion.

Admin album counts and bytes describe direct indexed original photo files.
Earlier listing grants show how many photos the current grant can see.
Older servers may require the legacy `media:read` permission or lack the
media route. Current installed Windows click-through and playback acceptance
require separate evidence.

With upload permission, use **Use this gallery for Watcher** from a gallery
context or the Watcher rail menu while Photos is open. WinApp verifies the
exact gallery and uses the existing Watcher folder, local thumbnail pipeline
and finish controls. The Watcher panel shows preparation or refusal for that
request. Before activating the destination, WinApp checks its accepted import
work against the local upload history. A large existing Watcher history can
be read within the same 256 MiB bound used for state updates. Unverifiable
history or unfinished work for another destination still blocks a change.
The red REC indicator shows active capture. The
Watcher rail icon pulses red on every tab while capture is active. A small
filled red light marks the exact upload gallery in the Photos tree and at its
heading. A hollow light marks a collapsed branch that hides that gallery.
The context offers SimBrief when a flight simulator is detected. Finish a game
to drain its screenshots, or finish a flight to save its available reports
locally. Finishing revokes the temporary capture key and leaves the website
linked. This action requires the server's temporary Watcher key migration.

Before sending a new gallery, Photos checks for an earlier creation in this
local profile. It checks a pending result with the original site and Admin
account; another site's pending work offers reconnection to that site. This
status check never repeats a create request. A completed earlier gallery offers
an explicit **Continue** action before the next creation. That action retires
only the matching local completion, then rechecks the current connection and
selected parent. It does not change the earlier gallery or select it for upload.
Submitting the same title and parent for the same site and account instead
rechecks the completed result and avoids creating a duplicate. An unreadable
record or failed confirmation stays blocked with a plain explanation and safe
diagnostics; it is not deleted automatically.

The source also contains an experimental **Create gallery** workflow that
requires separate `galleries:create` browser consent and verified server scope
storage. It records a local pending intent before sending and keeps an uncertain
result for manual **Check create status**. **Resume original creation** first
shows the original title and parent for confirmation, then resends the same
operation key and inputs to the same HTTPS issuer and verified Admin account.
The server admits a missing claim once; existing pending or reconciliation
claims remain blocked and may require an administrator to reconcile them.
There is no automatic retry. Deduplication requires the original server ledger;
restoring an older database backup can remove its history.
**Close unresolved creation** shows the original title and parent and requests
explicit confirmation before closing the original operation key on the server.
The unconfirmed-creation message points to both explicit original-request
resume and closure after gallery and storage review; it never retries automatically.
The local intent is removed only after the server confirms its durable closure;
an unavailable or unknown response leaves it in place. Closure does not prove
that a pending server operation has stopped; the server refuses to close a
pending claim. Closure also does not prove
that no gallery or files were created or that an earlier worker has stopped.
Verify the original work has stopped and inspect the gallery and its storage
before starting a separate creation; none is started automatically. If the server
instead verifies a completed result, its receipt remains for acknowledgement.
If the closure reply is lost, the same explicit action can be repeated for the
original key; no replacement key is generated for that request.
An unreadable or invalid local intent cannot supply a trustworthy key; it stays
blocked and requires administrator assistance rather than automatic deletion.
A confirmed receipt is kept until **Acknowledge creation**. Choose **Use created
gallery** to use the child for Watcher and Import. WinApp rechecks the original
receipt, account and exact gallery independently before publishing the target;
this requires separately approved upload permission and an admitted local
profile. The server checks
the original ID, title and parent against the current gallery. A renamed
gallery yields unknown; a moved, deleted or unverifiable reference can be
reported as unavailable. A missing or unsafely redirected gallery directory or
sidecar also leaves the result unknown; the server verifies current safe files
rather than requiring their original inode. The client keeps the receipt and does
not create another gallery automatically.
The server source refuses occupied paths, catalog drift and catalogs larger
than 10,000 rows including the proposed gallery. Slug search checks at most
128 candidates; these limits do not bound elapsed time. A local file flush
does not prove recovery after power loss.
The disposable HTTPS/database run exercised receipt recovery and upload to the
created child. Native Windows mode/receipt tests cover selection and restart.
Installed Windows click-through remains separate.

With upload permission, select an existing gallery and choose **Use for
uploads**. WinApp verifies the account and gallery on the server before
storing the choice separately from the saved API key. After restart, reconnect
to the same account and explicitly select that gallery before resuming pending
work. Use the gear beside **Upload gallery**, then **Use saved API key**, to
select the saved key explicitly. Linked flight reports stay local; AI and
flight upload actions use the legacy mode.

Linked uploads use a target-specific history and an exclusive local lease.
Use an unredirected profile on a fixed local Windows drive or an
owner-controlled local volume when running WinApp from source on macOS.
Close older WinApp versions that use the same profile. macOS keeps the second
copy of the non-secret selection in a private profile file under a native
workspace lock. A new session after restart requires fresh approval.
An uncertain upload blocks further transfers to that target; review the
server and source files before resolving it. The operating system releases
the current build's native lock when its process exits, so **Retry** can
reopen the same target. An older lock has no verified native owner. Close
all uploaders and review the gallery and sources before archiving that
specific lock. Keep the upload history and any pending transfer evidence.
Watcher source deletion on Windows follows the saved Watcher preference,
including linked captures. Manual import sources and ZIPs are retained. Durability
after a power failure and behavior on non-local filesystems remain unverified.

For exactly one uncertain linked upload, **Check uncertain upload** asks for
the file size in bytes, up to 64 MiB, and checks the stored hash once against the selected
gallery. It does not open the saved local path. A match reports observed bytes,
not confirmation of the original upload or its database registration;
unknown does not prove absence. Both outcomes leave the upload unresolved and
do not delete files, clear the history or retry the upload. Older servers may
not support this optional check.

After stopping the Watcher or Import, inspect the gallery and its original
source. The optional hash check only observes bytes. Select **Review retained
upload…** for the same gallery; the confirmation defaults to **No**. Review
preserves the uncertainty marker, original and ZIP. The same source path or
bytes stay blocked after restart, while other eligible sources can continue in
that gallery. A pending import retains its original target binding.

Linking requests have a 12-second total deadline; an unresponsive system DNS
resolver may still occupy the single transport slot until it returns. Uploads
have a separate size-based deadline. A partially sent request has an uncertain
outcome and is never automatically retried.

All direct linking and media requests identify this client with a truthful
`PHPGalleryUploader` User-Agent. Hosting protection can reject a request without
that header before PHP Gallery receives it. The link dialog, ordinary log and
copied diagnostics distinguish a rejected HTTP response from a server that
explicitly reports linking unavailable. The last linking failure remains in
the diagnostic snapshot even when later upload or simulator events arrive.
Diagnostics contain fixed stage/reason identifiers, HTTP status and a
JSON/HTML/other response classification without hosting-specific exceptions or
copying response bodies or raw headers. Credentials, callback codes,
request handles and raw exception text are excluded.

Use the public website address for linking, including its public subdirectory
when applicable. Internal hosting directories are not part of that address.
On servers with internal path rewriting, PHP Gallery 1.0.1 validates the public
request path against its configured website URL and keeps browser approval
and login returns on that same public mount. Earlier servers can report
linking unavailable in that environment even when classic API upload works.

The separate Windows HTTPS remembrance option uses the legacy offline scope
when offered. That approval expires after 30 days or seven days of inactivity,
whichever comes first. The saved binding identifies the exact issuer, Admin
account and approved scope. The `pgu_` upload key is independent.
If initial linking or secure storage fails after the browser approved it, the
server grant may still exist. Review it through **Manage gallery links**.

On startup, WinApp can restore the previously approved saved link.
**Reconnect saved link** in **Manage link…** can also start restoration.
The client rotates the refresh credential once, checks the current server
identity and scope, and saves the replacement before using the access token.
A cross-process lock and pending marker protect rotation. If the same verified
server explicitly rejects an expired or revoked rolling credential, WinApp
discards that saved credential. Choose **Link gallery** to approve a new link
in the browser. Startup leaves browser approval to you. An uncertain response
or storage failure blocks reuse of the old credential.
Review the gallery's linked-device list, then use **Forget saved link** and
relink if needed. Forgetting removes the local binding and credential only; it
does not revoke the server grant, and an active session may continue. Use
**Manage gallery links** for server-side revocation. The secure-storage and
crash-recovery paths have not been qualified on real Windows or after power
loss; the feature remains experimental.

Rolling links keep separate saved bindings for each exact issuer. To replace
the saved account for an issuer, resolve pending work and forget its local
link first. The legacy Windows HTTPS option keeps one shared binding. Local
forgetting leaves the server grant available for separate revocation and
does not affect the saved API key.

Use **Unlink gallery** for the current session, or **Manage gallery links**
to open the gallery's owner list and revoke stored grants.
The gallery lists grants associated with the same administrator, including
their original issuers; it warns if its bounded list is incomplete. Approved
codes not yet exchanged are absent from the list and remain valid for at most
120 seconds. A failed or interrupted exchange can leave a grant on the server,
so check the list if its result is uncertain. Revoking a grant does not cancel
other pending attempts. Saved API-key uploads remain independently available
when the user explicitly selects that mode.

### API key insertion and replacement

API-key setup is available from the top connection control, from
**Settings > Connection**, and from the tray menu. The compact dialog keeps
edits
temporary until **Save** or **Save & Test** is pressed. Closing the dialog
cannot
replace a working saved key accidentally. The key is masked by default,
generated
in PHP Gallery Admin, and gallery-scoped; the Windows app does not ask for or
store an Admin password.

If the Settings URL/key fields differ from the saved connection, the app marks
them as unsaved. Upload work may use explicitly saved settings, and API-key
revocation is blocked until the connection fields are saved or restored.

### Connection test

**Test connection** sends a minimal authenticated `inventory` request with no
media files. It verifies endpoint reachability and API-key authorization without
uploading an image.

The API key is masked by default. Reveal and Copy require explicit button
presses. Redacted diagnostics never include the raw key.

### Revoke API key

**Revoke API key...** is available in the connection dialog, **Settings >
Connection**, and the tray menu. Revocation uses the existing saved key to
authenticate an `action=revoke` JSON request through the same upload API used by
connection/inventory operations, so no Admin credential or token ID is required
in the Windows app. If the canonical `index.php?page=upload_automation_upload`
endpoint is rejected by the hosting layer with a non-JSON HTTP 404, revocation
may retry the corresponding `/api/upload` alias as a compatibility fallback. A
gateway 502/503/504 is treated as an ambiguous upstream failure: the client
waits briefly and retries exactly once. If that retry reports that the key is
already invalid/revoked, the desired server state is accepted as confirmed.
Otherwise the local key is retained and the app reports that server revocation
could not be confirmed.

Revoke remains available while authenticated work is active. Choosing it starts
the shared Stop flow: the watcher, its owned UTC synchronizer and flight
recorder
stop, accepted photographs and reports finish, and Revoke additionally stops
other authenticated producers before requesting server-side key revocation.
Accepted manual imports are allowed to finish; other requests must settle before
the revocation call. The UI shows active work and the current phase.

Unsaved URL/API-key edits must be saved or reverted before revocation. The
request runs off the Tkinter UI thread. Revoke clears the local API key only
after
the gallery confirms server-side revocation; if the request fails, the key is
retained for retry. Closing is blocked while the server response is pending, so
a server-revoked key cannot remain silently stored locally.

Revocation is permanent for that key. To reconnect afterwards, generate a new
gallery-scoped upload automation key in PHP Gallery Admin and insert it with
**Set / replace API key...**.

## Import workflow

The Import tab uses one preflight and queue model for all manual sources.

### Choose files

Select one or more files. Supported image suffixes are discovered, unsupported
selections are reported, and content hashes are compared with local history and,
when a connection is configured, the remote gallery inventory.

### Choose folder

Select a folder. The default is non-recursive. Enable **Include subfolders when
importing a folder** only when recursion is intended.

### Choose ZIP archive

ZIP mode accepts `.zip` only. The archive is inspected and supported media is
extracted to a per-job staging directory under the app data folder.

ZIP preflight rejects or bounds:

- absolute archive paths;
- Windows drive-letter paths;
- `..` parent traversal;
- NUL bytes;
- symbolic-link entries;
- reparse-point-like entries exposed by ZIP metadata;
- archive entry count;
- total uncompressed size;
- individual uncompressed file size;
- suspicious compression ratios.

Unsupported archive entries are not extracted. Supported entries are staged with
collision-safe local names while the original archive-relative name is retained
for diagnostics.

The original ZIP is retained for manual verification, including after legacy
API-key upload confirmation. An older saved deletion preference does not delete
the archive. A stale or unverified prior import needs a new preflight before
its duplicate decisions can be reused.

Original ZIPs and extracted staging directories are retained regardless of age
or upload confirmation for manual review. The legacy age-based staging cleanup
entry point is disabled because age and visible job IDs do not prove that
accepted history was never lost or rolled back.

## Preflight

Selecting a source never starts an upload. Preflight performs discovery, safe
ZIP staging when applicable, hashing, duplicate checks, and local media
capability checks in a background thread.

The review shows:

- supported files found;
- files ready to upload;
- local and remote duplicate candidates;
- estimated accepted bytes;
- unsupported entries grouped by reason/extension;
- unsafe ZIP entries rejected;
- thumbnail mode;
- HEIC/HEIF/DNG local capability;
- target gallery label;
- source deletion policy;
- warnings such as a temporarily unavailable remote duplicate check.

Press **Start import** only after reviewing the summary.

## Durable import jobs

Manual imports use explicit item states stored in `upload_state.json`:

```text
discovered
validating
staged
hashing
queued
uploading
confirmed
skipped_duplicate
skipped_unsupported
failed_retryable
failed_permanent
cancelled
```

Each item stores a stable item id, local/staged path, source label, size,
SHA-256 when known, attempt count, result/error, last update time, local decoder
capability, and a small safe subset of server result data.

Each job stores its source, target gallery label, accepted items, skipped count,
byte totals, timestamps, ZIP staging ownership, and optional source-ZIP deletion
policy.

### Pause and resume

**Pause** stops submission of new work. HTTP requests already in flight are
allowed to complete. **Resume** continues queued work.

### Cancel

**Cancel** requests an orderly stop. In-flight requests may finish. Items that
did not reach a terminal state are persisted as cancelled so they can be
reviewed or explicitly retried.

### Retry failed

**Retry failed** retries locally available failed, cancelled, or
crash-interrupted items. A permanent item can be retried only because the user
explicitly requested it. Confirmed items are not silently resent.

### Restart recovery

If the app finds an unfinished durable job at startup, it offers to open that
job for review. Recovery never auto-starts an upload. Remote inventory remains
authoritative for uncertain transfers.

If a request fails ambiguously after bytes may have reached the server, such as
a 502/503/504, timeout, or dropped connection, the client performs one fresh
remote inventory check before deciding to retry. Explicit client/authentication
failures such as HTTP 401/403 do not trigger a second inventory request. Routine
inventory and preflight are database-only. Only the narrow ambiguous-failure
reconciliation request asks PHP Gallery for the bounded filesystem fallback,
capped to a small candidate set. If the content hash is already present, the
item is confirmed instead of uploaded again.

## Progress and Activity

The Import and Activity views expose:

- confirmed count;
- duplicate/skipped count;
- failed count;
- cancelled count;
- active count;
- bytes confirmed versus total bytes;
- approximate throughput while a job is active;
- approximate remaining time when enough progress exists;
- per-item state;
- per-item attempt count;
- per-item result or error;
- durable previous job history.

The compact footer shows the latest human-readable event and opens **Activity**
when selected. Activity displays a filtered recent-event list and stores bounded
events separately from the rotating log.

## Thumbnail modes

Manual import exposes two normal choices:

### Server creates thumbnails

This is the default and most compatible mode. The original is uploaded and PHP
Gallery creates derivatives through the existing server pipeline.

### This PC creates thumbnails

When Pillow and the local decoder are available, the client creates responsive
JPG/WebP derivatives locally using bounded worker processes and sends them with
the original. Originals and PHP Gallery validation remain authoritative.

If local decoding or local thumbnail generation fails for one item, that item
falls back to server thumbnail generation. One bad HEIC/DNG decoder result does
not abort the batch.

Thumbnail sizes, thumbnail process count, remote inventory refresh interval, and
ZIP limits are under **Settings > Show advanced settings**. Network upload
concurrency is intentionally fixed to one request per gallery. PHP Gallery
serializes mutations for a gallery under a server-side lock, so parallel upload
requests do not increase write throughput and can instead occupy PHP workers
needed by normal gallery pages on shared hosting.

## HEIC, HEIF, and DNG

The Windows uploader distinguishes three concepts:

1. the suffix is accepted for submission to PHP Gallery;
2. this Python/Pillow runtime can locally preview the file;
3. this Python/Pillow runtime can locally create thumbnails for the file.

A file is not rejected solely because the local machine lacks a decoder when it
can still use the server-thumbnail path. Preflight reports HEIC/HEIF/DNG as
local-decoder capable or server-upload-only on this PC.

Per-file decoder failures are isolated and shown as item capability/result
information.

Large RAW/HEIC conversion stacks are not mandatory dependencies.

## Watch folder

Watch-folder behavior keeps the established safety semantics:

- files present when the watcher starts are ignored;
- only files appearing after start are considered;
- recursion is disabled by default and must be explicitly enabled;
- partially copied files wait until size and modification time remain stable for
  the configured period;
- SHA-256 upload history prevents repeated local sends;
- routine remote inventory checks are database-only and prevent re-sending
  indexed content already present on the gallery;
- transient failures use backoff;
- ambiguous gateway/timeout requests are checked against remote inventory before
  retry, while explicit 401/403 failures are not doubled with an immediate
  inventory call;
- when Pillow can decode a watched JPG/PNG/GIF/WebP, responsive thumbnails are
  generated locally and uploaded with the original so PHP does not stay busy
  generating the full derivative set synchronously; unsupported local formats
  continue through the server path;
- server-side validation remains authoritative.

The **Watcher** screen shows the selected folder path, current status, and the
source-deletion warning. **Choose folder…** saves a folder independently of
the gallery connection. **Start Watcher** asks for a folder when one is
missing.
The check icon inspects existing files without uploading or changing upload
history. Folder selection, recursion, stability, simulator metadata, and the
saved deletion setting are also in the Watcher gear sheet, where
**Save watcher settings** and **Cancel** apply.
The sheet does not permit edits while watching; it requires a stop first. Saved
changes apply on the next start, while **Cancel**, Escape, and closing the sheet
restore the opening folder and preference values.

### Watched source deletion

**Delete uploaded photos from this PC** in Watcher settings is enabled by
default for a new or missing setting. An explicitly saved off choice is kept.
On Windows, Watcher deletes a source only after a successful upload response
and durable local acknowledgement. It opens the current file through a native
handle, verifies the captured file identity and sent bytes, and deletes that
same opened file. A replaced, changed, linked, redirected or busy file stays
on disk with a warning. The native handle refuses simultaneous writers and
replacement during this final check.

An inventory match, an uncertain response or a restart never independently
authorizes source deletion. Files present at startup remain excluded. Manual
import sources and ZIPs keep their existing retention policy. Other platforms
retain watched sources until a native deletion owner is available.

Start shows an activity indicator while it verifies the destination and reads
the initial folder. REC appears after the initial exclusion boundary is ready.
Stop and Finish open progress with a preparation message and activity before
loading the pending work. Their subsequent phases describe upload drain and
any reports. A busy indicator does not estimate a completion percentage.

Recent Activity history is saved in bounded batches on one background writer.
This avoids repeatedly rewriting a large shared history on the UI thread.
Normal shutdown attempts to flush the buffer within its existing deadline.
A storage failure, expired deadline, forced exit or crash can omit the last
buffered Activity rows; a flush failure is logged and the rotating log is
written immediately. Upload acknowledgements and pending-work records
retain their existing synchronous ownership and durability checks.

## Sync UTC time while watching

**Sync UTC time**, beside the Watch folder actions, is off by default. Selecting
it saves only that preference, preserving unsaved connection/settings fields.
When watching is active it starts a separate SimConnect worker for **both MSFS
2020 and MSFS 2024**. Toggling it takes effect immediately; stopping the watcher
from the window or tray stops UTC work but remembers the selection. Closing to
the tray keeps watching/synchronization active. Use the tray's exit action for a
complete shutdown. Update shutdown exits the UTC worker with the application
process.

The worker checks every **10 seconds**, comparing `ABSOLUTE TIME` (seconds since
0001-01-01 UTC) with Windows UTC, including the date. Differences of **60
seconds
or less** cause no write. Larger differences request UTC year, ordinal day of
year (1..366), hour and minute through the four `ZULU_*_SET` events, then
require
fresh absolute-time readback within 60 seconds. The day setter uses the ordinal
day, not the day of month or the zero-based day SimVar.

Correction requires loaded-flight evidence, running/unpaused state, a flight
camera view, normal **1x** simulation rate and **5 seconds** of settled state.
Menus, loading, pause/active pause, replay and non-1x simulation do not admit
writes. Queued flight transitions invalidate prior samples. Each worker owns its
native handle; screenshot location acquisition, imports, uploads and AI remain
independent. A missing simulator waits/reconnects without upload errors.
Activity
and the adjacent status show changes/corrections without a log for every no-op.

Ignored/rejected corrections back off **30 seconds** and suspend after **three**
failed attempts for that connection. A confirmed missing native capability also
suspends that connection. The checkbox stays configurable and the saved
preference survives; reconnection probes capability again for either generation.

The common events and state notifications are documented by the
[2020 SDK](https://docs.flightsimulator.com/html/Programming_Tools/Event_IDs/Miscellaneous_Events.htm)
and
[2024 SDK](https://docs.flightsimulator.com/msfs2024/html/6_Programming_APIs/Key_Events/Miscellaneous_Events.htm).
Those event tables do not specify the ordinal's indexing. The implementation
uses the one-based setter convention, calendar vectors and mandatory readback.
**Live acceptance remains unverified for both simulators**, including exact
day-setter behavior and any effect on the Weather panel's Live Time/Live Weather
selection. No weather-mode event is sent; that alone does not establish observed
UI behavior. Record simulator build, before/after UTC and mode selections in two
separately loaded flights per available generation. Mocks cannot prove this.

## SimConnect metadata

The watcher automatically selects a location strategy immediately before each
watched upload. It reads simulator identity and version from the SimConnect OPEN
handshake; there is no manual MSFS generation selector.

- **MSFS 2024:** the existing `CameraAcquire`, `CameraGetStatus` and
  `CameraGet(WORLD)` path reads the actual camera world position. A request
  failure, unsupported API, exception, invalid response or short timeout falls
  back to user-aircraft position.
- **MSFS 2020:** reads `PLANE LATITUDE`, `PLANE LONGITUDE` and `PLANE ALTITUDE`
  through the classic user-aircraft data-definition API; it does not call the
  newer Camera API. Latitude/longitude use degrees and altitude uses feet.
- **Unknown generation:** available camera exports allow a bounded capability
  attempt; unavailable camera data always leads to an aircraft-position attempt.

Handshake application major versions 11 and 12 identify MSFS 2020 and 2024
respectively. The native server names `KittyHawk` (2020) and `SunRise` (2024),
and explicit simulator generation names, are recognized with consistent version
metadata. Other
or ambiguous identities remain unknown. The server version is separate from DLL
version and camera capability: exporting a function does not prove the server
supports it. See the
[OPEN structure](https://docs.flightsimulator.com/msfs2024/html/6_Programming_APIs/SimConnect/API_Reference/Structures_And_Enumerations/SIMCONNECT_RECV_OPEN.htm)
and
[Asobo's version clarification](https://devsupport.flightsimulator.com/t/documentation-for-simconnect-recv-exception-is-incorrect/13555).
The reported application version is the SimConnect server's internal version,
which can differ from the retail version shown in the simulator's title bar.
The native server names are illustrated in the
[server-version discussion](https://devsupport.flightsimulator.com/t/simconnect-recv-open-reports-different-version-number-to-fs/11727).

One short-lived connection and one shared dispatch loop serve both strategies.
The default total response-wait budget is one second, shared by handshake,
camera and aircraft attempts. Requests are serialized per client, exception
send IDs are attributed to their operation, and aircraft responses must match
the active request and definition IDs. The USER selector passed to a request
is not a requirement that the reply object ID be zero. No old location is reused
for a
later screenshot. SimConnect calls run in the watcher thread, not the UI thread.

Location is optional: a stopped simulator, unavailable DLL, failed dispatch or
both providers failing leaves upload, thumbnails and confirmed-source deletion
working normally. Logs identify the simulator, provider, actual position source,
fallback and failure reason. **Copy redacted diagnostics** includes the last
acquisition snapshot without opening a new simulator connection. Connections
close after each attempt, so the report distinguishes the last successful
handshake from the current closed transport. Detailed HRESULT/dispatch counters
are retained in diagnostics/debug output. Aircraft packets are bounded by the
40-byte SDK metadata header and three FLOAT64 values. Receive metadata and
dispatch sequence identify request/definition mismatches, short payloads and
invalid coordinates. The primary failure reason precedes the DLL path so path
redaction preserves it; activity messages show one SimConnect marker.
The object-ID filter was a confirmed code bug. The reported FS2020 timeout
did not record its response object ID, so its exact cause and the installed
fix still require a live target-PC retest.

DLL lookup order:

1. valid optional Settings override;
2. `SIMCONNECT_DLL` environment override;
3. embedded `runtime/simconnect/SimConnect.dll` in PyInstaller's extraction
   root;
4. source-tree/legacy local DLL locations (including an adjacent EXE DLL if
   present), then common SDK locations.

Normal installed use requires no SDK, manual DLL copying, simulator selection or
SimConnect configuration. The installer and embedded fallback use the same
unmodified
x64 `winapp/SimConnect.dll`; no second generation-specific runtime is
introduced.
Build checks reject missing/wrong-architecture DLLs, missing required exports or
an embedded payload whose SHA256 differs from that source DLL. This Microsoft
native binary is copied unchanged from `SimConnect SDK/lib/SimConnect.dll` in
the official
[MSFS 2024 SDK Core 1.7.3 archive](https://sdk.flightsimulator.com/msfs2024/files/installers/1.7.3/MSFS2024_SDK_Core_Installer_1.7.3.zip).
The 2026-10-23 update replaces the previous 85,504-byte binary with the
84,480-byte SDK copy, SHA256
`b10de7adf4c62e5f66c89dd6d01b64091bbbcec83411cfe191d6b85fbee61d15`.
Both have 117 identical named exports; static PE32+/x64 and all required-export
checks passed without loading the DLL. Neither copy has usable embedded Windows
file-version metadata, so the SDK package version identifies provenance rather
than an invented DLL version. The current
[retail SDK release notes](https://docs.flightsimulator.com/msfs2024/retail/introduction/sdk-release-notes/)
list 1.7.3. Live MSFS 2020/2024 acceptance remains separate from these checks.
The
[Microsoft SDK terms](https://docs.flightsimulator.com/msfs2024/retail/introduction/sdk-eula/)
apply; the project's MIT license does not replace them or establish separate
redistribution permission.

For compatibility with existing gallery servers, multipart fields keep the
historical names `sim_camera_latitude`, `sim_camera_longitude`,
`sim_camera_altitude` and `sim_location_source=simconnect_camera`. The actual
source (`camera_world_position`, `aircraft_position` or
`aircraft_position_fallback`) is recorded internally and in logs. The server API
is unchanged. Aircraft coordinates label the user aircraft, which can differ
from an external/drone camera's position.

## AI metadata worker

AI metadata processing remains optional and disabled by default. It is visually
separated from upload/import actions.

The simple AI tab exposes:

- enable/disable;
- vision backend;
- model version;
- Start;
- Stop;
- current worker/job status.

Detailed model identifiers, object labels, thresholds, semantic prompt, Ollama
URL, and external command are under **Show advanced AI settings**.

### Server-owned job lifecycle

PHP Gallery owns AI work allocation:

- the client asks for one `ai_next_job`;
- the server atomically leases a queued job;
- the worker downloads only the assigned authenticated image asset;
- heartbeats extend long leases;
- success/failure is returned through `ai_complete`;
- expired work can be reassigned by the server;
- the Windows client never scans the gallery to invent global job ownership.

AI worker failures do not cancel watcher/manual upload workers.

### Optional local semantic models

The normal requirements remain lightweight. The optional Transformers path can
install large packages such as PyTorch and Transformers. The UI warns before
starting that installation because package and later model downloads can be
large.

The first semantic model use can populate the current Windows user's Hugging
Face cache.

### External analyzer command

The external command can use:

- `{image_path}`
- `{filename}`
- `{job_id}`

Its stdout is treated as untrusted JSON and parsed/validated by the
client/server workflow. Leave the command empty unless you intentionally use a
local analyzer.

## System tray and shutdown

When tray support is available and close/minimize-to-tray is enabled, the main
window can be hidden without stopping background work.

The tray menu includes:

- Open;
- Open activity;
- Pause/resume current import;
- Start watching;
- Stop watching;
- Start AI metadata worker;
- Stop AI metadata worker;
- Open log folder;
- Exit.

The tooltip reports watcher, import, AI, and current failure counts separately.

**Exit** follows one coordinated shutdown path. It requests worker cancellation,
waits for a bounded period, preserves durable recoverable state, and then exits.
If an in-flight operation does not stop within the timeout and tray support
exists, the UI can keep the process running in the tray instead of forcing it
closed.

Shutdown does not revoke credentials and does not perform extra source deletion.

## Runtime files and migration

Per-user runtime files are normally stored under:

```text
%APPDATA%\PHPGalleryUploader\
```

Important files/directories:

```text
config.json
upload_state.json
watcher.log
watcher.log.1 ...
staging\
```

`config.json` and `upload_state.json` use explicit schema versions. Existing
upload-path/hash/failure history is migrated without invalidating successful
upload history.

Configuration writes preserve fields owned by newer versions and compare the
previous complete file before replacing it. If the configuration is malformed,
unreadable or cannot be verified, the uploader stops before starting work;
preserve the original file and restore a valid backup. A missing configuration
still opens with first-run defaults. Malformed upload-state files keep their
existing `.malformed-*` backup and default behavior. These checks do not exclude
older or unrelated writers, and Windows reparse points and power-loss
durability have not been qualified.

The log uses rotation instead of growing without a bound.

## Dependency installation

Install into the same Python interpreter used to launch the source app; a
different installed app or virtual environment does not supply its dependencies.
Missing ReportLab blocks only new PDF rendering. Missing pypdf does not hide
retained PDFs: page count is shown as unknown until that component is restored.

`install.bat` installs `requirements.txt` into the Python runtime used by the
Windows shortcut. Current normal dependencies are Pillow, pystray, ReportLab and
pypdf. Portable
CI installs the three image/PDF requirements; installer preflight requires
pypdf 6 or newer and the frozen application includes its ordinary import.

Manual install:

```bat
python -m pip install --user -r requirements.txt
```

If Pillow is unavailable:

- watch-folder uploads still work;
- manual imports still work with server-created thumbnails;
- local preview/thumbnail capability is reported accurately.

The macOS source build runs in its normal Tk window. The Windows tray uses
pystray when available.

## Source organization

`gallery_watch_upload.pyw` remains the compatibility launcher and currently
retains legacy integration code while redesign services are extracted
incrementally.

Testable redesign services live under:

```text
winapp/uploader/
  config.py
  diagnostics.py
  discovery.py
  media.py
  models.py
  self_update.py
  state_store.py
  update_helper.py
  update_ui.py
```

This keeps versioned persistence, ZIP safety, media capability detection, job
models, and diagnostic redaction independent from Tkinter. `self_update.py` owns
release discovery, verified downloads and download-session storage;
`update_helper.py` owns external installer handoff; `update_ui.py` coordinates
the nonmodal notice and immediate process-exit handoff.

## Verification

Use the central audit from the repository root. During implementation, run quick
when a verification pass is useful; run full once before handing off changes:

```bat
php scripts/audit.php --profile=quick
php scripts/audit.php --profile=full
```

For release preparation, follow `RELEASE.md` and use the release audit profile
instead. The central runner owns Python tests and syntax validation as well as
PHP regression checks. Read its compact summary first.

Direct Python commands are reserved for diagnosing a specific reported failure
or developing a new test before registration. For example:

```bat
python -m py_compile winapp\uploader\update_ui.py
python -m unittest discover -s winapp\tests -p test_update_ui.py -v
```

The tests do not require a live gallery. They cover configuration/state
migration, redaction, URL normalization, file stability, dedup/retry state, ZIP
safety and cleanup, HEIC/DNG capability behavior, durable jobs, pause/cancel
controls, SimConnect-unavailable handling, AI shutdown isolation, tray callback
scheduling, update integrity, cancellation, worker admission and helper
readiness.

For a release build, also smoke-test on Windows with the real target gallery,
tray, local thumbnail runtime, optional SimConnect, and any intentionally
enabled AI backend.
