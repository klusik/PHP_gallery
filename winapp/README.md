# PHP Gallery uploader for Windows

The Windows companion app is a desktop import client for one PHP Gallery target gallery. PHP Gallery remains authoritative for authentication, validation, duplicate decisions, storage, image records, thumbnail acceptance, metadata, and AI job ownership.

The application keeps the existing `gallery_watch_upload.pyw` launcher, `run_gallery_watcher.bat`, and `--once` compatibility. Normal operation needs only Python plus the lightweight dependencies in `requirements.txt`.

## Updating the Windows application

The application checks public GitHub releases at startup and at most once hourly
while it remains open. **Check for updates** also runs an explicit check. Checks
use no Gallery API key; their timestamp and any available offer are stored
separately in `%LOCALAPPDATA%\PHPGalleryUploader\updates`, so restarting within
the hourly interval can still display an already discovered update.

Discovery examines paginated releases for the highest newer
`PHPGalleryUploader-X.Y.Z-Setup.exe` asset. CMS release tags are not the uploader
version. The highest candidate must have a trusted published SHA-256 digest;
missing digest information refuses the update rather than selecting an older
installer. Downloaded bytes, file size and SHA-256 are verified before Setup is
allowed to run. This verifies consistency with the GitHub release metadata; it
is not an independent publisher code-signing guarantee. Older release assets
without digest metadata may therefore require a manual installer download.

The nonmodal notice offers **Update** and **Later**. Update downloads with actual
byte progress and **Cancel download**, verifies the installer, then safely stops
new work and waits for uploads, thumbnail workers, preflight, AI analysis,
connection tests, key revocation and dependency repair to finish. Tk stays
responsive. A drain timeout refuses installation and leaves the app usable.
Update shutdown does not save unsaved Settings fields. Import workers preserve
recoverable job state through their ordinary stop path; saved credentials,
settings and job data remain under `%APPDATA%\PHPGalleryUploader`.

For the installed standalone EXE, a copied external helper acknowledges readiness
before the application completely exits, including its tray icon. The helper
waits for both PyInstaller processes from that installation to exit, invokes
Setup with the existing installation directory, and verifies the installed
version before restarting the app. Windows may request administrator approval.
Canceling that approval keeps the old application and permits its restart.
Source-mode Python runs can check and download updates, but offer **Open installer**
for manual installation and never overwrite the Python source folder. Only a
frozen `PHPGalleryUploader.exe` outside a PyInstaller extraction directory is
eligible for automatic handoff; helper validation also protects the target path.

The frozen updater explicitly loads `version.dll`, `kernel32.dll` and
`shell32.dll` using the Windows System32 search policy. This prevents
PyInstaller from redirecting the extensionless `version` request to the bundled
`VERSION` text file on case-insensitive Windows; no native DLL bundling is needed.
Before Inno Setup compilation, the build runs a read-only native API smoke check
inside a copied frozen helper outside the original executable directory.

For an upgrade from 0.3.1, manually launch the verified 0.3.2 installer. The running
older executable copies its helper before installation, so its old helper can
still report the native-DLL error after replacing the app. Future updates started
from the corrected 0.3.2 executable use the repaired helper. Retain the original
0.3.1 installer and its update metadata; the new installer has distinct metadata
at `winapp/dist/0.3.2/winapp-update.json`.

## Windows installer (winapp 0.3.2)

The companion app has its own version in `winapp/VERSION`, independent of the PHP
Gallery CMS release. The installer includes Python, Tkinter, Pillow, pystray,
the tray icons, and an embedded `SimConnect.dll` inside one application EXE.
There is no separate DLL to install or distribute. End users do not
need Python. Installation requests administrator rights and defaults to
`Program Files\PHP Gallery Uploader`, with a Start Menu shortcut, an optional
desktop shortcut for all users, and a normal Windows uninstaller. Existing settings and jobs
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

To build on Windows, install x64 Python 3.10+ with Tkinter and Inno Setup 6.3+
(including Inno Setup 7), then run from the repository root:

```bat
winapp\build.bat
```

The script downloads the build dependencies into a temporary virtual environment,
validates the x64 SimConnect DLL and required exports, uses PyInstaller
`--onefile --windowed`, verifies the embedded DLL against the source SHA256,
checks the EXE startup with `--help`
using a disposable app-data directory, then calls the installed `ISCC.exe`.
Internet access to the configured Python package index is needed for each build.
When the required dependencies are already installed, build without downloads or
package installation using the existing Python and Inno Setup:

```bat
python winapp\build_installer.py --use-installed-dependencies
```

This mode verifies the installed dependency versions and keeps all intermediate
files and caches in the same disposable staging directory.
The build script produces this installer:

```text
winapp/dist/PHPGalleryUploader-0.3.2-Setup.exe
```

The build script produces only this EXE. Attach it to the same GitHub release as
the CMS release. The script does not generate or upload the optional metadata
file. You can also attach `winapp-update.json` to that same release to provide
trusted installer metadata when GitHub's asset digest is unavailable. Its schema is:

```json
{
  "assets": [
    {
      "name": "PHPGalleryUploader-0.3.2-Setup.exe",
      "version": "0.3.2",
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
build returns a nonzero exit code and preserves any previous successful installer.
Its unique `winapp/.build-*` staging directory (virtual environment, downloads,
caches, spec file, work files, intermediate application EXE and partial installer)
is removed on success, errors and normal Ctrl+C cancellation. Forced process
termination or power loss can leave a staging directory; remove that `.build-*`
directory manually before retrying if needed. Existing unrelated files are never
cleaned. Add `dist/`, `build/`, `.build-*/`, and Python build caches to the local
`.git/info/exclude` to keep generated files out of Git.

The compiler is discovered on PATH and in standard Inno Setup installation
folders. Custom tool locations can be selected explicitly:

```bat
set "PHP_GALLERY_PYTHON=C:\Program Files\Python313\python.exe"
winapp\build.bat --iscc "C:\Program Files\Inno Setup 7\ISCC.exe"
```

`ISCC_EXE` is also accepted as a compiler path override. Alternatively, run
`python winapp/build_installer.py --iscc "C:\path\ISCC.exe"` directly.

The standalone build includes the normal uploader and Pillow AI fallback. Optional
in-process Transformers/PyTorch packages are not bundled or installable into the
frozen EXE; use Ollama, an external analyzer, or the Python source installation for
that optional backend. Dependency repair in the EXE reports bundled dependency
status without starting pip. The separate `gallery_http_monitor.py` diagnostic
tool remains a source-only utility.

## Main window

The redesigned window is organized around five tasks:

- **Import**: confirm the target gallery/API key, choose files, a folder, or a ZIP archive, review preflight, then explicitly start the import.
- **Watch folder**: configure and start or stop the live drop-folder watcher.
- **Activity**: inspect the current durable job, per-file state, attempts, result/error, throughput, ETA, and recent job history.
- **AI metadata**: optional server-leased image analysis. Disabled by default.
- **Settings**: gallery connection, API key, runtime, SimConnect, tray behavior, diagnostics, and advanced limits.

The header reports connection state, target gallery label when the endpoint returns it, and watcher/import/AI activity independently.

The lower Recent activity panel keeps a bounded durable event history. It can be filtered by active, succeeded, skipped, failed, and warning events. Diagnostic copying removes credentials and local paths.

## Setup

1. Open the target gallery in PHP Gallery admin.
2. Generate a gallery-scoped upload automation API key.
3. Copy the key when it is shown.
4. Run `install.bat` from `winapp`, or start `gallery_watch_upload.pyw` directly.
5. On the default **Import** screen, use **Set / replace API key...** in the **Connection and API key** card. The same action is available from the header as **Connection / API key** and from the tray menu.
6. Enter the gallery site URL or upload endpoint.
7. Paste the API key with the **Paste** button or type it into the masked field.
8. Press **Save & Test**.
9. Confirm that the header reports **Connected** and the expected target gallery.

PHP Gallery advertises the portable query-string front-controller endpoint as the canonical Windows-uploader URL:

```text
https://example.com/index.php?page=upload_automation_upload
```

A site root or the optional clean alias `https://example.com/api/upload` can still be entered. The desktop app canonicalizes either form to the query-string endpoint before saving or sending authenticated work. This is deliberate: some shared-hosting proxies and WAF configurations route ordinary clean URLs correctly but mishandle authenticated or multipart POST requests under `/api/`. If the canonical query-string endpoint is rejected by the web server with a non-JSON HTTP 404 before PHP Gallery is reached, the client may retry `/api/upload` as a compatibility fallback. Gallery-generated JSON errors such as invalid/revoked keys, forbidden access, or a missing target gallery remain authoritative and are not hidden by route fallback.

### API key insertion and replacement

API-key setup is intentionally not hidden in advanced settings. It is available in three places:

- the default **Import > Connection and API key** card through **Set / replace API key...**;
- the always-visible header through **Connection / API key**;
- **Settings > Connection**, which also has a direct masked API-key field and **Paste API key** button.

The compact connection dialog keeps edits temporary until **Save** or **Save & Test** is pressed. Closing the dialog cannot replace a working saved key accidentally. The key is generated in PHP Gallery Admin and is gallery-scoped; the Windows app does not ask for or store an Admin password.

If the Settings URL/key fields differ from the saved connection, the app marks them as unsaved. Upload work may use explicitly saved settings, and API-key revocation is blocked until the connection fields are saved or restored.

### Connection test

**Test connection** sends a minimal authenticated `inventory` request with no media files. It verifies endpoint reachability and API-key authorization without uploading an image.

The API key is masked by default. Reveal and Copy require explicit button presses. Redacted diagnostics never include the raw key.

### Revoke API key

**Revoke API key...** is available directly on the Import connection card, in **Settings > Connection**, and in the tray menu. Revocation uses the existing saved key to authenticate an `action=revoke` JSON request through the same upload API used by connection/inventory operations, so no Admin credential or token ID is required in the Windows app. If the canonical `index.php?page=upload_automation_upload` endpoint is rejected by the hosting layer with a non-JSON HTTP 404, revocation may retry the corresponding `/api/upload` alias as a compatibility fallback. A gateway 502/503/504 is treated as an ambiguous upstream failure: the client waits briefly and retries exactly once. If that retry reports that the key is already invalid/revoked, the desired server state is accepted as confirmed. Otherwise the local key is retained and the app reports that server revocation could not be confirmed.

Revocation is deliberately guarded:

- it is disabled while the watch-folder uploader, manual import, import preflight, AI metadata worker, or connection test can still issue authenticated requests;
- the UI states the exact activity preventing revocation;
- unsaved URL/API-key edits must be saved or reverted before revocation;
- the network request runs off the Tkinter UI thread;
- the local API key is cleared only after the gallery confirms server-side revocation;
- if the request fails, the local key is retained so the user can retry;
- closing the app is blocked while revocation is awaiting the server response, preventing a server-revoked key from remaining silently stored locally.

Revocation is permanent for that key. To reconnect afterwards, generate a new gallery-scoped upload automation key in PHP Gallery Admin and insert it with **Set / replace API key...**.

## Import workflow

The Import tab uses one preflight and queue model for all manual sources.

### Choose files

Select one or more files. Supported image suffixes are discovered, unsupported selections are reported, and content hashes are compared with local history and, when a connection is configured, the remote gallery inventory.

### Choose folder

Select a folder. The default is non-recursive. Enable **Include subfolders when importing a folder** only when recursion is intended.

### Choose ZIP archive

ZIP mode accepts `.zip` only. The archive is inspected and supported media is extracted to a per-job staging directory under the app data folder.

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

Unsupported archive entries are not extracted. Supported entries are staged with collision-safe local names while the original archive-relative name is retained for diagnostics.

The original ZIP is kept by default. If **Delete original ZIP only after every accepted entry succeeds** is enabled, deletion occurs only after all accepted items finish as confirmed uploads or confirmed duplicates. Failed or cancelled jobs keep the source archive.

Stale staging directories are cleaned on later startup after the configured conservative age threshold. Staging owned by a recoverable job is retained.

## Preflight

Selecting a source never starts an upload. Preflight performs discovery, safe ZIP staging when applicable, hashing, duplicate checks, and local media capability checks in a background thread.

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

Each item stores a stable item id, local/staged path, source label, size, SHA-256 when known, attempt count, result/error, last update time, local decoder capability, and a small safe subset of server result data.

Each job stores its source, target gallery label, accepted items, skipped count, byte totals, timestamps, ZIP staging ownership, and optional source-ZIP deletion policy.

### Pause and resume

**Pause** stops submission of new work. HTTP requests already in flight are allowed to complete. **Resume** continues queued work.

### Cancel

**Cancel** requests an orderly stop. In-flight requests may finish. Items that did not reach a terminal state are persisted as cancelled so they can be reviewed or explicitly retried.

### Retry failed

**Retry failed** retries locally available failed, cancelled, or crash-interrupted items. A permanent item can be retried only because the user explicitly requested it. Confirmed items are not silently resent.

### Restart recovery

If the app finds an unfinished durable job at startup, it offers to open that job for review. Recovery never auto-starts an upload. Remote inventory remains authoritative for uncertain transfers.

If a request fails ambiguously after bytes may have reached the server, such as a 502/503/504, timeout, or dropped connection, the client performs one fresh remote inventory check before deciding to retry. Explicit client/authentication failures such as HTTP 401/403 do not trigger a second inventory request. Routine inventory and preflight are database-only. Only the narrow ambiguous-failure reconciliation request asks PHP Gallery for the bounded filesystem fallback, capped to a small candidate set. If the content hash is already present, the item is confirmed instead of uploaded again.

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

The lower Recent activity drawer stores bounded human-readable events separately from the rotating log.

## Thumbnail modes

Manual import exposes two normal choices:

### Server creates thumbnails

This is the default and most compatible mode. The original is uploaded and PHP Gallery creates derivatives through the existing server pipeline.

### This PC creates thumbnails

When Pillow and the local decoder are available, the client creates responsive JPG/WebP derivatives locally using bounded worker processes and sends them with the original. Originals and PHP Gallery validation remain authoritative.

If local decoding or local thumbnail generation fails for one item, that item falls back to server thumbnail generation. One bad HEIC/DNG decoder result does not abort the batch.

Thumbnail sizes, thumbnail process count, remote inventory refresh interval, and ZIP limits are under **Settings > Show advanced settings**. Network upload concurrency is intentionally fixed to one request per gallery. PHP Gallery serializes mutations for a gallery under a server-side lock, so parallel upload requests do not increase write throughput and can instead occupy PHP workers needed by normal gallery pages on shared hosting.

## HEIC, HEIF, and DNG

The Windows uploader distinguishes three concepts:

1. the suffix is accepted for submission to PHP Gallery;
2. this Python/Pillow runtime can locally preview the file;
3. this Python/Pillow runtime can locally create thumbnails for the file.

A file is not rejected solely because the local machine lacks a decoder when it can still use the server-thumbnail path. Preflight reports HEIC/HEIF/DNG as local-decoder capable or server-upload-only on this PC.

Per-file decoder failures are isolated and shown as item capability/result information.

Large RAW/HEIC conversion stacks are not mandatory dependencies.

## Watch folder

Watch-folder behavior keeps the established safety semantics:

- files present when the watcher starts are ignored;
- only files appearing after start are considered;
- recursion is disabled by default and must be explicitly enabled;
- partially copied files wait until size and modification time remain stable for the configured period;
- SHA-256 upload history prevents repeated local sends;
- routine remote inventory checks are database-only and prevent re-sending indexed content already present on the gallery;
- transient failures use backoff;
- ambiguous gateway/timeout requests are checked against remote inventory before retry, while explicit 401/403 failures are not doubled with an immediate inventory call;
- when Pillow can decode a watched JPG/PNG/GIF/WebP, responsive thumbnails are generated locally and uploaded with the original so PHP does not stay busy generating the full derivative set synchronously; unsupported local formats continue through the server path;
- server-side validation remains authoritative.

The Watch folder tab shows how many existing supported files will be ignored at startup, the last successful scan time, and a **Check folder only** dry run that performs no uploads.

### Optional source deletion

**Delete source only after the gallery confirms a successful upload** is destructive and disabled unless selected by the user. It removes only a source that PHP Gallery has confirmed as stored. Duplicates, skipped files, and failed files are kept.

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
supports it. See the [OPEN structure](https://docs.flightsimulator.com/msfs2024/html/6_Programming_APIs/SimConnect/API_Reference/Structures_And_Enumerations/SIMCONNECT_RECV_OPEN.htm)
and [Asobo's version clarification](https://devsupport.flightsimulator.com/t/documentation-for-simconnect-recv-exception-is-incorrect/13555).
The reported application version is the SimConnect server's internal version,
which can differ from the retail version shown in the simulator's title bar.
The native server names are illustrated in the [server-version discussion](https://devsupport.flightsimulator.com/t/simconnect-recv-open-reports-different-version-number-to-fs/11727).

One short-lived connection and one shared dispatch loop serve both strategies.
The default total response-wait budget is one second, shared by handshake,
camera and aircraft attempts. Requests are serialized per client, exception
send IDs are attributed to their operation, and aircraft responses must match
the active request and definition IDs. The USER selector passed to a request
is not a requirement that the reply object ID be zero. No old location is reused for a
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
3. embedded `runtime/simconnect/SimConnect.dll` in PyInstaller's extraction root;
4. source-tree/legacy local DLL locations (including an adjacent EXE DLL if
   present), then common SDK locations.

Normal installed use requires no SDK, manual DLL copying, simulator selection or
SimConnect configuration. The installer and embedded fallback use the same existing
x64 `winapp/SimConnect.dll`; no second generation-specific runtime is introduced.
Build checks reject missing/wrong-architecture DLLs, missing required exports or
an embedded payload whose SHA256 differs from that source DLL. This Microsoft
native binary is retained unchanged; the repository does not contain its original
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

AI metadata processing remains optional and disabled by default. It is visually separated from upload/import actions.

The simple AI tab exposes:

- enable/disable;
- vision backend;
- model version;
- Start;
- Stop;
- current worker/job status.

Detailed model identifiers, object labels, thresholds, semantic prompt, Ollama URL, and external command are under **Show advanced AI settings**.

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

The normal requirements remain lightweight. The optional Transformers path can install large packages such as PyTorch and Transformers. The UI warns before starting that installation because package and later model downloads can be large.

The first semantic model use can populate the current Windows user's Hugging Face cache.

### External analyzer command

The external command can use:

- `{image_path}`
- `{filename}`
- `{job_id}`

Its stdout is treated as untrusted JSON and parsed/validated by the client/server workflow. Leave the command empty unless you intentionally use a local analyzer.

## System tray and shutdown

When tray support is available and close/minimize-to-tray is enabled, the main window can be hidden without stopping background work.

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

**Exit** follows one coordinated shutdown path. It requests worker cancellation, waits for a bounded period, preserves durable recoverable state, and then exits. If an in-flight operation does not stop within the timeout and tray support exists, the UI can keep the process running in the tray instead of forcing it closed.

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

`config.json` and `upload_state.json` use explicit schema versions. Existing upload-path/hash/failure history is migrated without invalidating successful upload history.

Writes use a same-directory temporary file and atomic replace. Malformed config/state files are preserved as timestamped `.malformed-*` copies before safe defaults are used.

The log uses rotation instead of growing without a bound.

## Dependency installation

`install.bat` installs `requirements.txt` into the Python runtime used by the Windows shortcut. Current normal dependencies are Pillow and pystray.

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

`gallery_watch_upload.pyw` remains the compatibility launcher and currently retains legacy integration code while redesign services are extracted incrementally.

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

This keeps versioned persistence, ZIP safety, media capability detection, job models, and diagnostic redaction independent from Tkinter. `self_update.py` owns release discovery, verified downloads and download-session storage; `update_helper.py` owns external installer handoff; `update_ui.py` coordinates the nonmodal notice and safe worker drain.

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

The tests do not require a live gallery. They cover configuration/state migration, redaction, URL normalization, file stability, dedup/retry state, ZIP safety and cleanup, HEIC/DNG capability behavior, durable jobs, pause/cancel controls, SimConnect-unavailable handling, AI shutdown isolation, tray callback scheduling, update integrity, cancellation, worker admission and helper readiness.

For a release build, also smoke-test on Windows with the real target gallery, tray, local thumbnail runtime, optional SimConnect, and any intentionally enabled AI backend.
