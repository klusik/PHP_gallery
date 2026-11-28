# WinApp GUI backends

The baseline for issue [#445](https://github.com/klusik/PHP_gallery/issues/445)
was WinApp 1.7.1, commit `b63d6d5e5bee014ab3750080833f49dcc27e9a34`, with
Tkinter/ttk as the default. Issue [#442](https://github.com/klusik/PHP_gallery/issues/442)
supplies the optimized Tk shell and thumbnail baseline. The widget and retained Canvas
renderers are both relevant controls. The #399 hosted audit replays its
100/500/2,000-photo cold and warm Canvas workloads on the immutable source.

## Composition

The stable compatibility bootstrap is `winapp/gallery_watch_upload.pyw`; it
handles early flags before desktop imports. `uploader/launcher.py` owns CLI and
backend assembly, instance activation and shutdown. `--once` uses the same
configuration and uploader worker without a GUI event loop. Runtime, installer
helper and version commands enter before desktop composition. On Windows the
single-instance lease and activation event are owned by `windows_startup.py`.

| Area | Tk presentation and lifecycle | Shared owners |
| --- | --- | --- |
| Shell, Settings and tray | `watcher/ui/shell.py` creates the Tk root and variables, registers timers, consumes the worker event queue, and coordinates tray, modal focus and restart. `ui_theme`, `ui_shell`, `app_header` and `ui_file_picker` own controls. | `config`, `ConfigStore`, `localization`, `windows_startup`, `uploader/launcher.py` |
| Photos and Lightbox | `photos/tk/workspace/`, `photos/tk/viewer/` and `photos/qt/` own toolkit-specific presentation; Tk schedules progressive publication and owns image handles. | `LinkingSession`, gallery catalogue/media caches, thumbnail protocol, gallery preferences and raster services |
| Import and Activity | `watcher/ui/imports_activity.py` coordinates preflight, admission, progress drain, history and user decisions. | `imports/policy.py`, `imports/preflight.py`, `imports/manual_upload.py`, `discovery`, `media`, `JobStateStore`, `ImportHistoryReader`, `import_ui_snapshot`, upload journal and mode owners |
| Watcher and recovery | `watcher/ui/watcher.py` coordinates start, finish, tray, key changes and continuation; `watcher/recovery_ui.py` presents decisions. | `watcher/workers/`, `watcher/runtime.py`, `watcher/state.py`, `watcher/profile.py`, `watcher/queue.py`, `watcher/recovery.py`, `watcher/capture_session.py`, `ModeCoordinator`, `UploadPipelineLease` |
| Flight and SimConnect | `FlightUI` owns the Tk dialogs, countdowns and operator interactions. | `FlightRecorder`, `FinalizationWorker`, flight jobs, delivery, rendering, SimBrief and SimConnect services |
| Linking and legacy API | `linking/ui/manager.py` owns Tk dialogs, grants/refresh timers and action callbacks; launcher composition owns upload mode admission. | `linking/` transport/session owners, `offline_linking`, incoming credential stores and mode fences |
| AI and updates | `uploader/ai/` owns analysis, worker and dependency support; `UpdateController` owns Tk prompts and the shutdown gate. | Shared AI configuration, `self_update`, `update_helper`, update and ordinary restart checkpoints |

The toolkit boundary is process startup and feature presentation. A Qt session
uses a single `QApplication`; service extraction for each feature follows
[#351](https://github.com/klusik/PHP_gallery/issues/351). Both frontends
submit work through the existing durable admission, event and shutdown
contracts. A backend switch requires quiescence and verified recovery state.

Qt Widgets provides the native form and table controls. Qt Quick provides a
retained scene graph for image-heavy views. An embedded `QQuickWidget` adds an
offscreen pass and disables the threaded render loop; a separate Quick window
can use the native render loop at the cost of separate focus and window
management. API reported by the scene graph and device/adapter evidence are
distinct. Windows Direct3D 11 can run on WARP.
[Qt Quick Widget limitations](https://doc.qt.io/qt-6/qquickwidget.html#performance-considerations),
[Qt Windows graphics](https://doc.qt.io/qt-6/windows-graphics.html).

The hosted Windows Quick control compares the same viewport and retained photo
source in fresh `QQuickWidget` and `QQuickView` processes. Alternating trials
verify matching visible photo identities at each jump before reporting renderer,
scene completion, process CPU, and sampled working set. The widget remains the
production choice for shell-owned focus, overlays, and shutdown.

The Qt photo grid derives its work window from the current row stride and
scroll origin. The texture-slot ceiling covers a 4K grid even with 21:9 cards,
using the worst supported QImage pixel format and the existing decoded-memory
budget. Framed requests, single GETs and local decode publish exact active IDs.
One shared animation rotates the refresh glyph on active visible rows.
Moving off Photos or cancelling the request retires the animation.
The viewport and catalogue reset withdraw model URLs before discarding their
provider slots. Reusable delegates release their image source while pooled.
When a visible group loses its thumbnail proof, Qt renews the exact authorized
pages needed by a bounded group and retries once. The catalogue restores
current descriptors in place. A removed photo or changed proof retires its
old raster, and confirmed live frames keep their slots. A group that finishes
after scrolling only records misses from the current viewport, so returning
to its departed rows can fetch them again.
The gallery heading leaves spare width beside the title. Ratio controls sit
next to the other gallery actions and move below the title at the same narrow
widths as Tk, preserving readable labels in that layout.
The gallery address prompt shows live links and recent addresses as expanded
native lists in both frontends. Selecting a row prefills the address. A saved
or Admin request keeps its verified address locked through the consent step.

## Visual and interaction reference

The production Tk shell defines the left icon rail, the adaptive brand,
connection, Watcher and updater header, the workspace and the latest Activity
strip. The six top-level workspaces are Photos, Watcher, Import, Activity, AI
and Settings. Flight and its saved reports belong to the Watcher workspace.
Watcher opens capture progress and saved reports separately, using nonmodal
panels so reviewing live progress never owns a capture decision by itself.
Saved Reports shows a sortable flight tree, an optional flights-only filter,
the verified upload destination, and a verified local folder action. Each selected flight
opens its saved revisions and their available language editions. Report delivery
confirms the exact selected editions from one stopped flight and its destination. The Qt
progress panels display per-edition status from the shared finalizer snapshots.
An explicitly selected stopped flight supports both SimBrief retrieval and OFP
attachment. The web path retains the same flight directory and prior dispatch
identity across the request and verifies them again before writing.
`ui_layout.py` owns the common shell spacing and window sizes. `ui_palette.py`
owns the text and color roles. `ui_icons.py` draws the application geometry
from the original Tk artwork. Tk wraps the resulting raster in a Tk image and
Qt registers its density and state alternatives with `QIcon`. Both frontends
use the same switch artwork with native Boolean input, keyboard focus and a
readable label.

The hosted visual evidence uses real Tk and Qt windows in independent processes,
an identical synthetic profile, one display and the same logical client sizes.
Photos and Lightbox use the same read-only gallery and exact JPEG bytes in both
processes. The capture report records the fixture identities and media digests.
The reference includes standard and narrow layouts, followed by additional
states where supported. Review the recorded placement, actions and screenshots
together. Native text shaping and OS-provided decorations retain their platform
behavior. The P6 gate covers per-panel interaction and performance as well as
these visual observations.

### Tkinter layout reference

`ui_theme.py`, `ui_layout.py`, `ui_palette.py`, and `ui_icons.py` define the
reference typography, slate surfaces, teal primary/focus states and native
icon artwork. The compact rail uses six consecutive icon targets. The header
groups identity, connection, Watcher queue and scan metrics, storage, updater
and help. Shorter layouts move connection controls to a lower row. The
latest-activity footer stays anchored at the bottom. Windows uses the native
rounded card treatment. The hosted Linux and macOS references show the flat
card treatment available on those systems.
The clean Photos header retains the idle connection slot on macOS, where
the Tk reference reserves it. The hosted Windows and Linux Tk headers collapse
the empty slot. The Qt adapter measures the corresponding native controls.
On Windows and Linux, the Qt connection, updater and help icons use the
compact Tk header cells. macOS keeps natural control widths around the
reserved slot. The native narrow Photos and Watcher comparison records
whether the linked address shares the brand row in each backend.

Legacy Tk Photos displays an amber interface notice above the gallery heading.
Its action saves Qt as the requested interface through the existing Settings
owner and requests the coordinated restart.

| Workspace | Reference structure and state treatments | Source |
| --- | --- | --- |
| Photos | Disconnected Photos displays a small Connect card at the upper left. The compact connected state has a left gallery tree with expand, collapse, add and refresh icons. In the viewer, the gallery name and pencil sit beside the format strip and contextual controls. Photo and subgallery order and aspect ratios are independent. The photo strip has visible Default, 16:9, 21:9, 4:3, 1:1, 4:5 and 9:16 captions below their icons. Mixed galleries put folder cards above the photo grid. The selected raster has its own outline. A right-side inspector shows the indexed original size before full details, with a partial-data notice if the lookup fails. Inline action cards follow their trigger and close when focus moves outside. | `photos/tk/workspace/`, `photos/tk/viewer/`, `photos/qt/`; gallery connection composition in `watcher/ui/photos_connection.py` |
| Lightbox | A full-bleed dark canvas keeps the retained photo centered. Previous and next sit at the left and right edges. The compact centered top bar holds title, ordinal, zoom, fit, fullscreen, map, close and track controls. Fullscreen idle dismisses the chrome. The grid opens the track selection beside its Music trigger. | `gallery_lightbox.py`, `native_gallery_viewer.py` |
| Watcher and Flight | The upload-gallery card orders target, connection settings and lifecycle review. The Watch Folder card groups Choose, settings, help and reset above the current directory, scan/readiness status and Start or Continue actions. Record activity, flight settings, right-aligned instruments and PDF sit together. Separate progress and saved-flight windows retain current phase actions and saved revision/edition selection. | `watcher/ui/watcher.py`, `watcher/desktop.py`, `FlightUI.controls`, `FlightUI.refresh_progress_actions`, `FlightUI.show_reports` |
| Import | A single scrollable card leads with the exact upload target, followed by compact Files, Folder and ZIP icons. Source selection precedes Review, progress and explicit Start. Pause, Resume, Cancel, Retry failed and current status share a row. The collapsed options switch reveals recursion and item details. | `watcher/ui/imports_activity.py`, `imports/preflight.py`, `imports/manual_upload.py` |
| Activity | Current import leads with an explicit no-selection or job summary, actions and File, State, Tries, Result / error columns. Job history, recent-event filters and diagnostics follow in their own cards. | `watcher/ui/imports_activity.py`, `import_history_reader.py` |
| Settings | Gallery Connection leads with URL, masked key, reveal/paste, key status, Save & Test, credential editor, revoke and link management. App & Integrations has independent language and thumbnail controls, runtime diagnostics/repair, SimConnect DLL, tray and startup. Interface contains the backend choice. Advanced configuration keeps its storage, import and worker controls within their existing sections. | `watcher/ui/settings_lifecycle.py`, `watcher/ui/photos_connection.py`, `watcher/desktop.py` |
| AI | The card uses an AI metadata eyebrow and one explanatory heading. Its enable switch precedes compact Backend and Model version fields. Worker state and dependency controls precede Start/Stop and the collapsed advanced switch. | `watcher/ui/watcher.py`, `ai/worker.py`, `ai/image_analysis.py`, `ai/dependencies.py` |

Native reference images and typed action geometry accompany the issue's
hosted visual artifacts. The fullscreen pair records the same retained photo
after idle, then captures the controls after pointer motion reveals them.
A different loaded state, such as blocked recovery
versus idle or a different current connection, stays recorded as a fixture
or behavior difference until a matching state is observed.

A disconnected Photos workspace shows the same single Connect action as Tk.
The connection menu owns local files and saved upload links. Gallery actions
remain in the selected gallery's menu and follow the authenticated session's
permissions. Both frontends show the stored card-format choices in their shared
order, with the label below each icon. Qt wraps the choices as space narrows.
The compact add, refresh and gallery menu buttons leave space for a single
heading row when it fits, and the format indicator occupies space only when it is visible.
The saved gallery-tree width applies inside the Photos split and is retained
when the operator switches between live gallery sites. Recent connection
addresses appear in expandable groups, with selection following operator input.
Gallery-tree markers preserve their shared cache colors on open and selected
Qt rows. Their localized tooltips and accessible descriptions explain the cache
state, pin and Watcher overlays, including a target inside a collapsed branch.
Linked-photo metadata now opens in the right pane. Both frontends use the
shared saved pane width and field visibility rules. Qt reserves the final
splitter width at reveal and moves the card inside it for 180 ms. A small
overshoot settles once. Reduced-motion preferences skip the motion. This
keeps viewport layout and thumbnail admission independent of animation frames.
The right inspector retains its dragged width when space is temporarily
restricted. A larger thumbnail above the metadata uses the existing authorized
media path in a separate bounded read lane. The heading shortens long titles.
The compact, selectable detail rows align values in one column. A long title
or filename takes one line until its arrow expands that field. The expanded
name wraps and can be copied. Closing the pane cancels both reads and returns
focus to the same selected photo.
Reopening the same recent metadata keeps the reference's 32-entry in-memory
cache and one-minute age limit. Qt checks the exact session and Admin scope
before reuse and drops the gallery's entries on refresh.
The connected header shows local thumbnail totals. Its menu uses the same
storage accounting and quota controls as Settings.
During launch the supervisor waits for the first real Quick frame before the
disconnected workspace presents its normal layout.
The supervised Qt process routes native Qt messages through the shared
rotating log from application initialization. It keeps the Qt category and
severity, filters sensitive diagnostic text, and uses the same log tail for
the in-app and saved support report.

The Qt Lightbox routes keyboard actions through the native scene host. Its
toolbar occupies only the top strip, leaving the image available for wheel zoom
and drag panning. The retained image occupies the application client while the
desktop chrome is hidden. Fullscreen controls return on input after idle hiding.
Opening an unselected photo from the grid selects it. An existing selection
containing that photo is retained while navigating or closing the Lightbox.
The anchored photo confirmation uses the shared raised surface with Cancel
focused on opening. Metadata failures and accepted retained-queue inspections
reach the latest-activity strip and the Activity history.
Both viewers use the shared black-matte detector. Qt samples the retained
detail frame off the GUI thread, within a 256-pixel long-edge budget. The
Lightbox can switch between the crop and the original without a new media
request. A crop uses the Qt raster and the original can use native macOS EDR.
Gallery creation from the photo menu uses the existing linked
gallery permission check. A refused creation retains the exact draft for the
shared journal's retry. A verified receipt admits its child into the current
tree immediately while retaining the selected parent. The native metadata editor reads the current complete
server value, retains the draft after a rejected edit, and keeps the accepted
request fenced until the shared field-specific owner responds. Both frontends
build the browser editor route from the same verified site and gallery identity.
The Watcher folder card opens the corresponding field
in Settings and can save an explicitly chosen folder through the same profile owner.
Its inspection action counts supported and excluded files using the saved
recursion preference without admitting uploads or touching the active journal.
The Watcher capture row keeps Record activity, Flight settings, telemetry and
Saved Reports together. The separate Flight settings window exposes report
languages and the existing capture actions.
The capture progress dialog exposes the shared finalizer's elapsed time, phase
time and available ETA. The Watcher displays the cached UTC sync status from the
existing simulator worker without an additional simulator read.
When capture is idle, Qt offers the same Begin or Reconnect action in Watcher
and Import as Tk. Begin opens a scoped connection dialog seeded from the saved
URL/key and commits only those two fields; cancellation and unrelated unsaved
Settings drafts remain untouched. Reconnect follows the existing linked-photo
owner and never starts recording or transfers work by itself.
Qt uses the same Windows game-context observer as Tk while Watcher is visible
and active. Hiding it or minimizing the application suspends presence probes.
Contextual wording and UTC presentation use that cache. The Watcher also
shows the source-deletion warning from the effective saved Watcher profile.

Photos uses the same per-account tree and per-gallery photo and folder settings.
Direct subgalleries have a separate wrapping shelf, format and order controls,
and a divider whose drag and keyboard adjustments persist in the shared view
preferences. Native folder cards activate the existing tree route with the
original gallery ID. Their first-photo cover service checks the same live
validator as Tk and retains a bounded visible-paint cache instead of requesting
the full child catalogue. The native shelf stops requesting previews when
hidden. The retained Quick grid continues to own the gallery's photos.
The Lightbox keeps directional actions on the image edges and music actions
in its top overlay. The grid offers the same playlist through a compact
trigger and anchored panel. Opening it never starts playback. The current
verified gallery owns track bytes and tempo, including after pause or fullscreen
navigation. The optional macOS EDR overlay masks the control regions without
redecoding the active raster. Physical EDR display behavior requires a capable
runner and is outside the hosted software-image evidence.

Activity presents the current Import, selected-job history with retry, recent
event filters and local diagnostic log in the same reading order as Tk. The
current Import card uses the same headings, keeps a visible no-selection
message, and shows the selected job's loading and error states. The
Import workspace keeps source choice, preflight review and progress in one
scrollable card. The selected folder can rebuild its review with recursion
changed. A separate preparation indicator leaves the determinate progress
visible. The current item table opens under the details control, and durable
job history remains in Activity. The review reports the accepted byte count,
unsupported groups, a bounded preview of rejected ZIP paths and warnings, the
selected thumbnail mode and HEIC, HEIF and DNG decoder availability. The two
thumbnail choices remain separate, with only server generation available when
the runtime lacks local support. The live view coalesces
refreshes while visible. Both frontends use the same bounded
redacted log reader for offline support reports. Settings' Save & Test issues
the same authenticated empty-inventory read for an explicitly selected API-key
connection. It fences competing changes until the request terminates. The menu
and tray open the protected masked credential editor, with changes applied by
the shared Settings configuration owner on Save.
The connection card separates the saved and unsaved key status from linking
and test feedback. It enables revocation only for the exact saved legacy draft.
The saved legacy key can be revoked from Settings or the tray after explicit
confirmation. The shared finalizer preserves the admitted Watcher and Import
boundaries. Local cleanup uses a positive receipt for the exact key and saved
mode and can retry a local write after the worker has stopped.
Watcher also offers an explicit local abandonment action. It settles the
current writer and retains the sources. A linked capture keeps its saved
connection. In legacy mode, local cleanup clears only the exact captured key.
Force Finish claims the drain before the finalizer can start report work and
writes terminal markers for the admitted plans under the existing leases. A
request already in transport keeps its original owner until transport ends.
Continue in the capture progress panel resumes a verified restart checkpoint
or opens the existing review for a retained queue before work is admitted.
The raised Qt progress card groups the available footer actions under the
current operation using the application border and shadow roles.
Connection, App & Integrations and Interface lead the Qt Settings page.
App & Integrations places dependency repair before the SimConnect path, then
its save action and the advanced import disclosure. The advanced worker
selectors reuse the Tk presets and preserve previously saved custom counts.
The Windows startup switch keeps the original immediate-save behavior. Users
with a saved API key can choose it from the gallery connections menu while a
linked upload gallery is selected.
The Watcher folder gear opens its own preferences sheet over the current
workspace. Save applies the watched directory, timing and source rules through
the Watcher owner. Escape or Cancel discards this sheet's changes, and the main
Settings card keeps any unsaved connection draft.
The Qt Photos connection keeps its address, localized request status, Cancel
and submit action in the modal through browser approval. Enter uses the same
address normalization as Tk. An idle live address shows Switch to website.
Connect again replaces only that dialog's pending browsing attempt. Existing
upload and permission admission rules still apply.
Previously verified addresses appear from the shared profile history. Removing
an inactive address edits that history only.
The Qt Lightbox keeps the location pin in the same toolbar position even when
the current photo has no coordinates. Photo information and slideshow controls
remain available in the image context menu. Escape dismisses an open menu,
exits fullscreen and then returns to the grid on successive presses. Exiting
fullscreen retains the displayed image and its texture.
When an authorized catalogue is empty, Photos shows the localized empty state
inside the gallery card. A visible subgallery shelf takes its place when there
are child galleries. Photo-format controls appear when photo rows are ready.
Both Photos inspectors present the same binary size captions from exact byte
counts, including a single decimal place for binary units above bytes.
The address chooser and Linked now menu can reopen a still-authorized site.
The previous site stays visible through approval of another site. After a
verified switch, only its grant is kept in memory and the shared Photos view
releases its previous catalogue and textures. This leaves the explicit upload
destination with its existing owner.
The compact header keeps its connection actions in the same positions while
switching workspaces. Its gallery action opens a scoped native picker over
the active workspace and filters the authorized tree. The Tools menu and its
Ctrl+Shift+G shortcut open the same picker in both frontends. A listing-only approval
can choose a Photos gallery. A separate upload action revalidates the site,
account, scope and destination through the shared admission owner.
After native startup on Windows or macOS, Photos can restore browsing from
the same verified gallery binding. On macOS an existing short-lived approval
can also be read from Keychain until its original expiry. If the Keychain
requires an unlock or permission prompt, startup leaves manual approval to the
operator. An explicit Photos connection can use temporary browser consent
while the existing saved credential and upload selection stay available for
recovery. When startup detects a pending refresh for the saved upload link,
Photos offers **Reapprove saved link**. After browser consent, WinApp checks
the saved account, gallery and scope before replacing the local credential.
Explicitly reconnecting a saved upload destination always verifies the precise gallery,
account and scope. It can use an already verified live grant for those checks.
Idle rolling sessions use the shared identity route at the existing cadence
through one cancellable read lane. A due check waits behind foreground
authorization and upload pipeline demand.
Fresh browser-approved Photos access can use the existing native vault with
its exact approved scope, including gallery editing when explicitly granted.
The address dialog keeps its retry action and reports the failed step when
approval, verification, catalogue loading or storage cannot complete. Its
support log uses fixed step names and sanitized protocol fields. Choosing upload
permission is a separate approval and keeps the previous credential if
replacement fails. The header's
Disconnect command removes only the chosen grant, reports the server outcome,
and leaves the saved upload destination and any queued work available for
verified reconnect.
Settings' Manage link action opens the same scope controls as Tk. Discovery
gates unsupported permissions, and an active Photos grant is shown read-only
until it is disconnected. Remember securely is available only when the current
platform vault and server scope support it. Scope approval changes the Photos
grant only; upload activation still requires the separate verified gallery
selection. Manage gallery links opens the validated issuer's browser grant page.
Both gallery pickers can observe or review a single retained linked upload
outcome after the exact target is reconnected. A previous target's standalone
marker stays in its own ledger while the desktop opens. The original file
and its digest remain fenced. Review permits other files to continue without
confirming or resending the uncertain original.
The Saved flights and PDFs view can browse the full filtered history in bounded
pages. Its modal puts the flight tree before selection details and the Watcher
destination. The existing report metadata owner supplies edition creation times
and page counts for the selected flight. Activity refreshes leave the Flight archive
alone while its viewer is hidden or minimized. The retained recovery panel groups pause
status, quantity and first filename above its Continue and Close actions.
Activity's ordered read models stay mounted while the tables preserve the
reference base column widths and share extra space between the matching
file/result, source/result and file/event columns. The quick AI form keeps
its labelled backend and model fields at the reference reading widths.
Watcher and Import use the compact card insets, standard action heights
and shared inactive primary treatment from the Tk reference. Import keeps
distinct review, progress and transfer rows with room for the details control
at the minimum window size.
Empty Watcher notices collapse to leave room for the recording controls and
source-deletion warning in a short window.
The AI workspace groups Enable, Backend, Model version, runtime status, Start
and Stop in the same quick card as Tk. The Advanced AI settings switch opens
a separate tuning card with explanations beside the corresponding fields.
Both cards use the shared profile owner. The runtime status and Repair
dependencies action are shared with Settings. Source installation of optional
AI packages requires explicit confirmation. A frozen application reports its
bundled availability through the same action.

## Qualification gates

Functional parity and shared state invariants are prerequisites for a
production-profile switch with writers. Benchmark comparisons use identical
datasets, input operations and budgets on the same runner with alternating
order, independent processes and at least ten pairs per comparable cold/warm
cell. Retain raw evidence with the exact application and harness revisions.
Use the #434 latency margin (greater of 5 ms or 15%), throughput margin (10%)
and memory margin (greater of 128 MiB or 30%) to flag regressions. Meaningful
improvement requires the confidence interval to clear the relevant margin in
at least one graphics workload without breaching another guard. Headless
paint and present proxies do not establish physical-display performance.

WinApp commonly runs beside a game or simulator. Capture and FIFO boundaries,
telemetry and writer lifetimes take priority. The decoded-thumbnail reservoir
remains bounded by the existing 256 MiB and 4,096-entry policy. This is a cache
budget distinct from working-set size. Compare steady-state memory alongside
decode reuse, CPU, I/O and responsiveness. The shell benchmark separately
observes visible-idle, inactive Photos, minimized and native tray states where
the runner supports them. Upgrade qualification also samples the actual resumed
Watcher waiting on its empty fixture directory after verifying the frontend's
native window and exact continuation nonce. A separate shell scenario feeds
synthetic full-HD screenshots to the production Watcher and observes FIFO
acknowledgement, retained originals, Qt paint events, CPU, and working set.
Its inventory and upload transport are local test stubs.
Physical GPU utilization, VRAM, display-presentation latency, and MSFS or
game frametimes including 1% lows are **BLOCKED** on the hosted virtual
display configuration. Qt's reported graphics API and adapter describe the
test environment. The synthetic capture stream does not start MSFS or obtain
SimConnect samples from a live simulator. See the
[companion performance decision](https://github.com/klusik/PHP_gallery/issues/445#performance-priorities-for-companion-use).

Distribution needs a separate decision. Prefer the minimal PySide6 Essentials
wheel with Qt Core, Gui, Widgets, Qml, Quick and their required plugins.
PySide6/Qt LGPL compliance for a frozen bundle requires visible notices,
license texts, corresponding Qt/PySide and third-party source/notice coverage
and practical library replacement. A source installation can obtain the
unmodified shared libraries independently. An opaque onefile distribution
does not establish a replaceable installation.
[PySide6 Essentials](https://pypi.org/project/PySide6-Essentials/6.10.3/),
[Qt LGPL obligations](https://www.qt.io/development/open-source-lgpl-obligations).

## Source checkpoint and supported operations

The fixed source set is PySide6 Essentials and shiboken6 6.10.3. Its published
CPython stable ABI wheels include Windows x64/ARM64, Linux x64 with glibc 2.34
and ARM64 with glibc 2.39, and a macOS 13+ universal wheel. Hosted source
qualification uses the project's CPython 3.14 runner version. Native plugin
availability still needs to be observed on every target.
[PySide6 wheel tags and sizes](https://pypi.org/project/PySide6-Essentials/6.10.3/#files).

The source entry point for pip is `winapp/requirements.txt`, which includes the
independent core and Qt manifests. Core alone supports Tk and CLI tests without
loading PySide6 or Shiboken6. `winapp/constraints.txt` supplies the shared
qualified versions, with platform markers for components such as PyObjC. The
Qt requirement restricts binary-wheel selection to its two native packages.
The one Windows builder includes the complete source manifest and the pinned
PyInstaller. The benchmark manifest adds only the sampling dependency. See the
[source and build commands](../winapp/README.md#dependency-installation).

The CMS production archive retains the source and build inputs referenced by
those commands in `companion_backend_files`. Its exact entries include the
recursive requirements and constraints, Qt Quick scene, Qt licenses and
common onedir build files. CMS activation draws from its existing updater
inventory. See the [package policy](PRODUCTION_FILES.md).

The profile saves the independent `ui-backend.json` choice atomically.
From WinApp 2.0.0, an unconfigured profile opens Qt. The Settings choices
are Qt / PySide6 and Legacy Tk. An
ordinary alternate-backend launch starts a bounded source child. The child
acquires the existing desktop instance owner before logging or profile
admission. The supervisor sees only a nonce and fixed disposition. It waits
for the child's first scene frame, activation of the existing window, or a
refusal. Crash, timeout and missing dependencies terminate or reap the
original attempt before one fresh Tk launch. The saved selection is retained
for an explicit retry or cancellation. A compatible installed update ticket
travels through the same supervisor and is available to the stable fallback.
Admission recognizes a stopped flight with an explicitly abandoned, completed
local plan and its matching frozen pre-Force Finish journal as archived history.
The journals and sources remain byte-identical and are excluded from recovery.
A refusal logs a fixed admission stage without profile or target details.

| Workflow | Qt source checkpoint | Qualification boundary |
| --- | --- | --- |
| Settings, shell and lifecycle | Locale and backend choices, Tk rollback, shared geometry, thumbnail request and disk limits, ordinary and retained cache observations, native tray, restart, diagnostics and common Windows profile settings for Watcher, Import and ZIP limits, connection and AI | Windows activation and settings publication use the existing profile owner |
| Photos | Read-only and upload browser linking, Admin consent, verified saved upload reconnect, explicit reapproval and local removal, gallery hierarchy, local previews, viewport batches and model identity. Explicit gallery creation, field edits, photo visibility, confirmed single/batch deletion and authorized metadata reuse the existing APIs. Full/thumbnail preload and retention use the common cache worker. Both frontends share the verified account's selection and display preferences. | `photos/tk/workspace/`, `photos/tk/viewer/`, `photos/qt/`; gallery connection composition in `watcher/ui/photos_connection.py` |
| Lightbox | Asynchronous bounded raster reads, retained frames, authorized oversized-image fallback, exact photo metadata, zoom, pan, fullscreen, next/previous and manual fullscreen slideshow with the authorized gallery's interval. On macOS, supported bounded sources use Core Image and a native EDR overlay. On Windows and macOS, approved gallery music uses native MP3/WAV playback and stops when the live permission ends. | Native overlay lifecycle and source-level Core Image fixtures are separate from physical HDR/EDR and speaker output. |
| Import and Activity | Bounded model/view history and live job snapshots. A verified API-key or linked target on Windows can run file, folder and ZIP preflight, explicit import, Pause, Resume, Cancel and verified retry through the existing worker and job binding. A restart opens the original verified job, including a held uncertain receipt with matching source and digest. The Import target row exposes the current capture lifecycle actions while capture holds new Import admission. | Unverified evidence retains the stable compatibility path. |
| Watcher and Flight | On Windows a verified API-key or linked connection can run the original capture worker, record SimConnect telemetry, opt into UTC synchronization, finish retained queues in order and interrupt with originals retained. The Photos gallery action verifies and issues one temporary Watcher key. Watcher supports FIFO review, local closure, factory reset and one-use continuation. Flight uses the existing SimBrief client, OFP parser, local report worker and independent upload of selected saved editions through the active Watcher. The active Photos gallery menu can offer SimBrief using the same cached simulator observer, which suspends its Photos demand when the menu closes. | `watcher/ui/watcher.py`, `watcher/desktop.py`, `FlightUI.controls`, `FlightUI.refresh_progress_actions`, `FlightUI.show_reports` |
| AI and update | The Windows API-key AI worker uses its shared configuration, Start, Stop and Activity. Qt shows verified update offers, byte progress, cancellation and installed helper handoff. An admitted active Watcher can pause for the matching setup and resume from its exact verified ticket. | Both frontends discover the same canonical setup and verify the same metadata. Older incompatible continuations retain the stable path. |

The central WinApp report records these acceptance paths by test ID. Native
screenshots and interaction timings remain separate evidence of the visible
states and their costs.
The source and paired visual evidence below is bound to exact candidate
`070d4d498b337f608d67be86835db36b67d6bf86` in run
[45866705246](https://github.com/klusik/PHP_gallery/actions/runs/45866705246).
The separate Windows run
[45860420889](https://github.com/klusik/PHP_gallery/actions/runs/45860420889)
covers installed lifecycle and settings/fault evidence. The active/idle Watcher
resource soak is run
[45867603481](https://github.com/klusik/PHP_gallery/actions/runs/45867603481).

| Operator path | Tk owner | Qt owner | Canonical source tests and hosted evidence | Boundary or remaining gap |
| --- | --- | --- | --- | --- |
| Select, defer, cancel or switch the desktop backend | `uploader/launcher.py`, `uploader/gui_backend.py`; stable `.pyw` entry for early flags | `QtWindow` in `qt_application.py`; `qt_shell.py`; `gui_backend.py` | `test_gui_backend.py::test_preferences_are_independent_and_fail_closed`, `test_gui_backend.py::test_supervisor_waits_for_child_death_before_fallback`, `test_qt_backend.py::test_backend_later_and_cancel_keep_the_current_qt_session`; source and paired visual run [45866705246](https://github.com/klusik/PHP_gallery/actions/runs/45866705246) at `070d4d4` | Installed transitions and missing-library fallback are covered on Windows by run [45860420889](https://github.com/klusik/PHP_gallery/actions/runs/45860420889); other installed platforms are not established. |
| Start, reactivate or recover the desktop | `uploader/launcher.py`, `uploader/watcher/ui/app.py`, `uploader/windows_startup.py`, `uploader/gui_backend.py` | `QtWindow`; `qt_application.py`; `gui_backend.py` | `test_source_backend_environment.py`, `test_gui_backend.py`, `test_windows_startup.py`; installed startup/fault cases in [45860420889](https://github.com/klusik/PHP_gallery/actions/runs/45860420889) | Source readiness is distinct from installed package qualification. |
| Browse Photos, select collections, edit media or restore layout | `uploader/photos/tk/workspace/`, `uploader/photos/tk/viewer/`, `uploader/photos/qt/`, `uploader/gallery_folders.py`; composition in `uploader/watcher/ui/photos_connection.py` | `QtPhotos` in `qt_photos.py`; `QtWindow` composition | `test_qt_backend.py::test_restores_stable_gallery_selection_and_remembered_photo_focus`, `test_qt_backend.py::test_gallery_create_keeps_failed_draft_and_admits_the_verified_child`, `test_qt_backend.py::test_admin_media_confirmation_refresh_and_metadata_scope`; paired native visual evidence in [45866705246](https://github.com/klusik/PHP_gallery/actions/runs/45866705246) | Fullscreen software behavior is covered; physical display HDR/EDR and device audio are not established by hosted screenshots. |
| Use the Lightbox, cache, preload or slideshow | `uploader/photos/tk/viewer/`, `uploader/photos/qt/media.py`, `uploader/gallery_lightbox.py`, `uploader/gallery_preload.py`, shared media/cache policy owners | `qt_photos.py`, shared preload and image-policy owners | `test_qt_backend.py::test_gallery_music_and_slideshow_use_the_current_presentation_grant`, `test_qt_backend.py::test_viewport_reuses_decoded_frames_without_retaining_old_scene_slots`, `test_qt_hdr.py`; hosted Photos interaction evidence in [45866705246](https://github.com/klusik/PHP_gallery/actions/runs/45866705246) | Physical HDR/EDR output, speaker output and GPU performance need suitable hardware; the software runner does not prove them. |
| Preflight, pause, resume, cancel or retry Import; inspect Activity | `uploader/watcher/ui/imports_activity.py`, `uploader/imports/preflight.py`, `uploader/imports/manual_upload.py`, `uploader/import_history_reader.py`, `uploader/import_ui_snapshot.py` | `QtImport` in `qt_import.py`; `QtOperatorViews` in `qt_operator_views.py` | `test_qt_backend.py::test_import_options_expand_to_native_mode_choices_and_snapshot_rows`, `test_qt_backend.py::test_import_preflight_summary_keeps_shared_detail_and_preview_limits`, `test_qt_backend.py::test_import_owns_review_pause_cancel_and_retry`, `test_qt_backend.py::test_activity_event_table_keeps_reference_labels_and_column_bases` | Synthetic imports use isolated sources and fixture transport; this is not evidence of a live gallery upload. |
| Start, finish, interrupt, review or continue Watcher work | `uploader/watcher/ui/watcher.py`, `uploader/watcher/workers/`, `uploader/watcher/capture_session.py`, `uploader/watcher/queue.py`, `uploader/watcher/profile.py`, `uploader/watcher/recovery.py`, `uploader/watcher/recovery_ui.py` | `QtCapture` in `qt_capture.py`; shared `watcher/capture_session.py`, `qt_application.py` | `test_qt_capture_panel.py::test_watcher_stop_matches_live_worker_and_server_request_fence`, `test_watcher_continuation.py`, `test_qt_backend.py::test_watcher_controls_keep_the_capture_owner_through_interrupt`; active/idle native resource evidence in [45867603481](https://github.com/klusik/PHP_gallery/actions/runs/45867603481) | Hosted active processing uses synthetic screenshots and local transport stubs. The original binding and verified FIFO order admit compatible legacy journals. Unbound or mismatched work requires explicit recovery. |
| Record, review and deliver saved Flight reports | `FlightUI` in `flight_ui.py`; `FlightRecorder`, `flight_workflow.py` | `QtCapture`, `QtFlightReports` in `qt_flight_reports.py`; shared recorder/workflow | `test_qt_saved_reports.py::test_catalog_upload_selection_keeps_exact_editions_and_one_flight`, `test_qt_backend.py::test_local_flight_report_holds_workspace_and_preserves_previous_editions`, and existing `test_flight_*` suites | Live SimConnect needs a simulator. Hosted report editions and delivery use isolated local fixtures. |
| Link, reconnect or forget saved access and choose the upload target | `uploader/linking/ui/manager.py`, `uploader/linking/ui/dialog.py`, `uploader/linking/ui/connection.py`, `uploader/linking/` transport/session owners; historical facades `app_linking_ui.py` and `app_linking.py`; `desktop_connection.py` | `QtPhotos`, `QtSettings`, `desktop_connection.py` | `test_qt_link_management.py`, `test_app_linking.py`, `test_mode_coordinator.py`; installed same-EXE journey in [45860420889](https://github.com/klusik/PHP_gallery/actions/runs/45860420889) | Passive browsing and explicit upload activation have separate grants. The loopback fixture uses synthetic credentials for one exact account, scope and gallery. |
| Review retained linked uploads or approve Admin Photos | `LinkingManager`, `PhotosWorkspace`; `desktop_connection.py`, `mode_coordinator.py` | `QtPhotos`, `QtSettings`; shared target lease and journal | `test_qt_backend.py::test_gallery_picker_recovery_refuses_a_target_replaced_while_open`, `test_qt_link_management.py`, `test_mode_coordinator.py`; prior-target ledger preservation in [45860420889](https://github.com/klusik/PHP_gallery/actions/runs/45860420889) | `uploader/linking/ui/manager.py`, `uploader/photos/tk/workspace/`, `uploader/desktop_connection.py`, `uploader/mode_coordinator.py` |
| Save credentials, test the connection, manage AI and runtime dependencies | `uploader/watcher/ui/settings_lifecycle.py`, `uploader/watcher/desktop.py`, `uploader/ai/`; Qt owners `qt_settings.py` and `qt_ai.py` | `QtSettings` in `qt_settings.py`; `QtAiWorker` in `qt_ai.py`; `desktop_connection.py` | `test_qt_backend.py::test_profile_settings_save_only_changes_to_the_existing_owner`, `test_qt_backend.py::test_ai_settings_and_dependency_actions_share_the_profile_and_fence`, `test_qt_backend.py::test_ai_start_reports_verified_linked_mode_without_exposing_details` | Linked mode cannot authorize key-only AI. Optional AI installation is source-runtime dependent; the frozen build reports its bundled state. |
| Diagnose failures and install, upgrade, repair or roll back | `UpdateController`, `self_update.py`, `update_helper.py`, `diagnostics.py` | `QtUpdater` in `qt_update.py`; shared updater and diagnostic owners | `test_update_ui.py::test_worker_diagnostic_is_queued_without_exception_text`, `test_update_ui.py::test_diagnostic_snapshot_keeps_failure_after_later_success`, `test_self_update.py`, `test_update_helper.py`; 17-scenario real installed update/settings/fault qualification in [45860420889](https://github.com/klusik/PHP_gallery/actions/runs/45860420889) | Installed evidence is Windows-only. Authentic refusal/rollback fixtures do not establish behavior on every Windows configuration or a required reboot. |

Qt Quick is embedded in the Widgets shell for this checkpoint. The first
visible test exercises the actual retained scene and native image codecs.
The Quick API name is logged. A Linux software run or Windows virtual adapter
can establish functional behavior and cannot establish physical GPU speed.
Full operator parity and candidate performance acceptance remain open.

The Qt gallery menu shows the same local cache state and retained pin as the
selected tree row. The tree alone includes the scoped Watcher pulse. The
current gallery uses the unknown state until an authorized row snapshot or an
explicit preload supplies cache evidence.

Import startup review verifies the saved mode, original job binding,
source membership and FIFO with the shared queue parser. Queue inspection
retains the original bytes, including an interrupted dispatch that normal
worker restoration would normalize to uncertain. Startup selects the newest
verified job for review, including an interrupted dispatch with exact matching
source and digest evidence. Unknown outcomes are held for manual reconciliation
using the shared worker's existing policy. The user still explicitly chooses
Retry. Verified interrupted local report generation remains available in the
saved Flight row. The next explicit render creates a new edition. Capture
admission checks the saved target, plan, source boundary and journal order.
A matching retained session opens the Watcher tab without dispatching.
Review, Finish, and Continue use the original queue and preserve its source
and destination identity.

A first-format Watcher journal can open for explicit review with a matching
legacy API-key plan and at most one pending path. A multi-path journal lacks
provable first-seen order. A journal from before linked scopes were recorded
cannot validate a linked capture. Read-only admission leaves both untouched
for the existing compatibility path.

In Qt, a stopped flight can create a local revision with **Generate PDFs only**.
**Send PDFs only…** delivers selected existing editions to the confirmed
destination. The sender borrows the live Watcher lease when available, or
acquires its own workspace lease for a connected upload destination while
Watcher is idle. **Create and upload** uses the live Watcher destination to
create and deliver a revision in one action.

An explicit ordinary restart during admissible live capture saves the
existing matching continuation pair after all writers settle. The original
capture stays unfrozen and the configured backend can restore its exact
recorder UUID, screenshot boundary and upload target. The consumer opens
only after the one-use checkpoint is durably consumed. Linked restoration
requires the previously approved account, gallery, scope and a current
native credential or renewed browser grant. Incompatible checkpoints keep
the stable fallback available.
When Photos started the capture with a temporary key, the checkpoint waits
for confirmed closure of that key. Continuation requests a fresh key for the
same verified gallery, and only then resumes the original queue. A failed
closure remains an explicit retry and cannot replace the original key.

An installed update can use the same original capture boundary. The native
helper must supply the exact declared session nonce and installed target
version. Both a successful version change and a verified restart on the
source version can continue that same checkpoint. A foreign or unbound
ticket leaves the capture for explicit review.

A definitive failed setup can resume the original capture only when the
registered launcher and every file in its common onedir runtime still match
their original byte fingerprint and no installed uploader process is active.
A changed runtime or an unobserved setup exit keeps the saved continuation
for later recovery. A required Windows restart retains its existing behavior.

The hosted paired Photos experiment uses the existing deterministic #361
image generator and #397 confidence-interval helper. Each cold/warm and
100/500/2,000-photo cell launches twelve independent groups of three
processes, with each renderer order represented twice.
Tk widgets and Tk Canvas use unmodified `b63d6d5e` source. The Qt process
uses the exact workflow candidate. Children verify the imported production
modules resolve inside the selected source tree. Stable fixture hashes and
renderer identities must agree with the requested cell. Each jump checks the same
viewport footprint and photo IDs, and records decoded data, resource
publication and the renderer's frame or idle-paint proxy separately.
The measured jumps report the first resource observation for each newly
visible ID in the requested viewport. Divide the total number of those IDs
by the summed time from move to final resource observation across the four
jumps. The warm replay and initial viewport are excluded. A ready cache entry
counts when the renderer exposes it in the target viewport. This measures
visible publication on the synthetic renderer workload.
The 2,000-photo case prepares all 2,000. The paired scroll targets use the
first 1,000 photos because the hosted reference Tk Widgets run returned row
207 when asked for row 248. This comparison covers the common viewport reach.
Qt coalesces verified warm and departed viewport roles into contiguous
model ranges. Cold results still appear as soon as each permitted image
arrives. The viewport and decoded cache retain their existing budgets.
The report retains raw JSON/CSV, CPU and sampled peak RSS. The warm case replays its jump
sequence before measurement. The post-authorization media fixture marks
the same synthetic set as eligible for each frontend's decoded cache.
The 2,000-photo warm lane also retains a separate Import comparison. The
native Tk and Qt views observe the same 100-item synthetic Import fixture
through the production worker's durable writes and local event delivery.
Each item starts at least 50 ms after the previous item started. If the
durable writes take longer, their completion governs the next admission.
The fixture detects a stalled native view after 20 seconds without confirmed
progress. Its child process has a 90-second deadline. The report verifies
the final item states and records confirmed items per measured second with
synthetic local outcomes. The paired 95%
interval for the relative Qt rate is evaluated against the existing #434 10%
loss margin. The active Qt shell soak records native timer lateness separately
from worker-event delivery, with missed nominal heartbeat periods retained.
The reference's 2,000-row widget path exceeded the initial 20-second
preparation window. The comparison allows 60 seconds for preparation within
the original #399 90-second child ceiling. Per-jump deadlines are unchanged.
Overall backend acceptance still needs the remaining operator, device
and workload evidence.

The standard Windows setup uses one versioned PyInstaller specification with
one onedir application. The common executable and `_internal` install at the
registered path, with replaceable Qt libraries and `licenses`. Setup checks
the installed process path and waits for quiescence before replacing stock
runtime files. The supervisor binds the child to the same public executable,
keeps the startup nonce and reaps a failed Qt process before opening Tk.
The confirmation keeps Later as its default and Ctrl+Enter selects Restart now.
The ordinary Watcher startup check releases the restart guard when it finds no
recovery offer. An in-progress check retains the guard until its result arrives.
An active Watcher with the original verified workspace can checkpoint for a
backend change. Independent writers and unresolved recovery still retain their
existing barriers. Qt normal close uses the same checkpoint outlet and keeps
the last backend selected. Only an explicit restart launches a new process.
The existing Setup license page includes the Qt notice alongside the app license.
The installer uses the pinned PyInstaller Windows hooks for both on-disk Tcl/Tk
data and the Tcl/Tk 9 libraries with embedded scripts in current CPython builds.
The native Tk-only smoke verifies the supported runtime in the resulting bundle.

The two frontends share the updater, the standard setup and its one descriptor.
An idle Qt update records its idle intent before starting the helper. The
relaunch uses the saved interface selection. For a verified older helper
ticket, Qt first admits the profile and resumes only an exact matching capture
checkpoint.
An onedir helper stages a verified, bounded copy of the same executable and
runtime outside the install directory. The published 1.7.1 onefile helper
already understands the common setup name and installs it at the same
registered path. The existing profile and credentials keep the same owners.
Setup replaces a verified app-local runtime as a whole so incompatible
libraries cannot remain mixed. It moves recognized previous runtime directories
aside on the same volume after checking both the public launcher and the older
sidecar path. A successful install retires those directories. On a failed
install it can restore a vacant slot only when the previous launcher is
byte-identical. The updater separately verifies its full pre-install fingerprint
before it restarts the application after a definite setup refusal or cancelled
elevation, including an idle installation.
Older registry entries can use a Windows 8.3 directory spelling. The installer
verifies equivalent existing path names after checking the exact registration
and refusing redirected paths. It records fixed preflight refusal codes for
hosted debugging without putting registry paths into the qualification report.
Inno's product setup mutex prevents overlapping instances of the common setup.
Qualification exercises the native refusal while holding the mutex and then
confirms the ordinary update in the same isolated install. The rollback and
successful Qt journeys retain their respective capture profiles independently.

The hosted Windows audit verifies application and runtime uniqueness,
SimConnect bytes, Qt platform, image and Quick libraries, both Settings
transitions, normal shutdown, fault fallback and standard setup repair.
It exercises the public 1.7.1 installer and two monotonic onedir builds for
installed updates while preserving exact artifact, profile and ticket evidence.
The hosted observer retries incomplete process snapshots within the existing
deadline and only closes windows with a verified helper image and ticket.
The active Watcher switch journeys seed and verify the starting backend choice,
then enter Settings after consuming an updater ticket. They require a normal
exit from the original process and both ordinary restart markers consumed by
the new frontend for the same flight. The fixture
uses an empty authorized queue and retains the original update measurement.
The comparison audit installs the published 1.7.1 setup, the authenticated
historical dual build and the common candidate on the same hosted Windows VM.
It records installed sizes, native first and repeated start readiness, sampled
working sets, process I/O writes and temporary files. The Settings smoke supplies
separate measurements for both safe restart directions.
