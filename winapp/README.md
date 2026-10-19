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

The app header groups PHP Gallery with “Your gallery on autopilot”. The Watcher
screen contains the gallery connection, Watch folder settings and primary
Start/Stop control. The folder path, picker and open-folder action share the
Watch folder settings sheet. Settings gears sit immediately beside their
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
the window. Watching/importing still requires its existing explicit Start action.
Source/Python runs never add an OS startup entry; their startup switch is disabled.

All Windows desktop launches sharing the app profile use one native mutex,
including copies from different executable/source locations and Windows
sessions. A second manual launch signals the existing process to restore/focus
its window, then exits before opening logs, configuration or state. Another
logon launch keeps the existing process hidden. Restoring never restarts workers.
An existing process in another Windows session must be opened in that session.
Native coordination failure refuses a second writer. Normal exit or a crash
releases ownership without a stale lock file. `--once` refuses overlapping work
with exit code 1; version/runtime probes and the independent updater helper keep
their existing early dispatch. Uninstall removes the Run value for its user;
other users' profiles remain independently managed.

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

### Manual completion and saved PDFs

**Finish flight…** sits directly below **Target gallery** in Watcher and Import;
long gallery names shrink inside their own row. One click stops acquisition,
drains accepted work, creates all four PDF editions and completes their required
delivery before revoking the saved key. No confirmation or success message box
appears; successful progress closes after durable local key cleanup. Errors
remain visible and retain work for retry. Settings retains direct key
management.
Without a saved key the enabled button reads **Begin flight…** and opens
connection/API-key settings.
If report delivery fails, **Keep locally and revoke key** finishes accepted work
and continues the already-confirmed revocation. Ordinary Stop uses a separate
local-retention confirmation that keeps the key. Retry preserves only the same
process's unsubmitted consent for the same endpoint/key; cancellation, changed
credentials and restart never restore it. Recovery actions wrap into two rows.

Open **PDF > Saved flights and PDFs**, or the same action in Flight settings.
The passive tree shows session start, aircraft, checkpoint sample count, local
PDF count and state. Expand a flight to see every retained PDF as a file,
newest versions first. File rows begin with version/language and show actual
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
groups by the newest PDF creation time/total known pages and order individual
PDFs by those same fields. Refresh retains direction, expanded flights and
multi-selection. Missing pypdf leaves page counts as `?`, preserving the file
tree, timestamps and ordinary open/upload actions. Creating new PDFs requires
ReportLab; a missing component gives an installation/repair message rather than
classifying the recording as damaged.

**Finish flight and create PDFs** uses the same one-click finish/revoke flow,
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

Local work has independent `manual-report.json` phase, edition paths and error
state. Cancellation between editions retains already completed PDFs. Local
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
validation, Enter to load and Escape to cancel. The main action shows loading,
then a checked **SimBrief loaded** state and the accepted airport pair. Failed
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
actual
byte progress and **Cancel download**, verifies the installer, then safely stops
new work and waits for uploads, thumbnail workers, preflight, AI analysis,
connection tests, key revocation and dependency repair to finish. Tk stays
responsive. A drain timeout refuses installation and leaves the app usable.
Update shutdown does not save unsaved Settings fields. Import workers preserve
recoverable job state through their ordinary stop path; saved credentials,
settings and job data remain under `%APPDATA%\PHPGalleryUploader`.

For the installed standalone EXE, a copied external helper acknowledges
readiness
before the application completely exits, including its tray icon. The helper
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

## Windows installer (next version: 0.7.0)

The companion app has its own version in `winapp/VERSION`, independent of the
PHP
Gallery CMS release. The current source targets **0.7.0**; this source version
change does not build or publish an installer. A separately requested build
will place the installer and generated `winapp-update.json` in
`winapp/dist/0.7.0/`. Existing version outputs and other version folders remain
preserved.

Develop integration compares the complete tracked installer inputs with current
`main`. If runtime, assets, dependencies, build/installer inputs or `VERSION`
changed, the candidate version must be strictly greater than main's. Tests,
`README.md` and `AGENTS.md` do not trigger this requirement. Several integrations
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
onefile parent/child processes. Self-update never force-closes these processes:
it waits for safe worker drain and refuses replacement while an exact-path
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

The window opens on **Watcher**, the first task in the navigation order:
**Watcher**, **Import**, **Activity**, **AI**, and **Settings**. The Watcher
view
shows the gallery target. The persistent header connection control opens the
compact API-key dialog from every view. The app header also has Settings,
updates, and help controls. Icon controls have
hover/focus tooltips and keyboard focus; help opens usage guidance.
Long pages and dialogs scroll with the mouse wheel or scrollbar; text and table
controls keep their own scrolling.

The Watcher screen keeps the selected folder path, watcher status, and source
deletion warning visible. **▶ Start Watcher** starts only after an explicit
click; while active it becomes **■ Stop Watcher**. **Check folder only**
performs
a non-uploading dry run and does not start watching. The dedicated Watcher gear
opens a settings sheet for folder selection, recursion, scan interval, file
stability, simulator metadata, and the opt-in source-delete setting, with
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
5. On the default **Watcher** screen, select the top connection control.
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
request runs off the Tkinter UI thread. The local API key is cleared only after
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

The original ZIP is kept by default. If **Delete original ZIP only after every
accepted entry succeeds** is enabled, deletion occurs only after all accepted
items finish as confirmed uploads or confirmed duplicates. Failed or cancelled
jobs keep the source archive.

Stale staging directories are cleaned on later startup after the configured
conservative age threshold. Staging owned by a recoverable job is retained.

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
source-deletion warning. **Check folder only** inspects existing files without
uploading or changing upload history. Folder selection, recursion, stability,
simulator metadata, and the opt-in deletion setting are in the dedicated Watcher
gear sheet, where **Save watcher settings** and **Cancel** apply.
The sheet does not permit edits while watching; it requires a stop first. Saved
changes apply on the next start, while **Cancel**, Escape, and closing the sheet
restore the opening folder and preference values.

### Optional source deletion

**Delete source only after the gallery confirms a successful upload** is
destructive and disabled unless selected by the user. It removes only a source
that PHP Gallery has confirmed as stored. Duplicates, skipped files, and failed
files are kept.

## Sync UTC time while watching

**Sync UTC time**, beside the Watch folder actions, is off by default. Selecting
it saves only that preference, preserving unsaved connection/settings fields.
When watching is active it starts a separate SimConnect worker for **both MSFS
2020 and MSFS 2024**. Toggling it takes effect immediately; stopping the watcher
from the window or tray stops UTC work but remembers the selection. Closing to
the tray keeps watching/synchronization active. Use the tray's exit action for a
complete shutdown. Installer drain cancels and waits for UTC work.

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
existing
x64 `winapp/SimConnect.dll`; no second generation-specific runtime is
introduced.
Build checks reject missing/wrong-architecture DLLs, missing required exports or
an embedded payload whose SHA256 differs from that source DLL. This Microsoft
native binary is retained unchanged; the repository does not contain its
original
SDK provenance or redistribution license, and the project's MIT license is not
a replacement for Microsoft's terms.

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

Writes use a same-directory temporary file and atomic replace. Malformed
config/state files are preserved as timestamped `.malformed-*` copies before
safe defaults are used.

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

If pystray is unavailable, the desktop window still works without tray hiding.

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
the nonmodal notice and safe worker drain.

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
