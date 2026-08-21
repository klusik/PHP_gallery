# TEMPORARY DESIGN BRIEF — Windows watcher redesign

> **Release housekeeping (read first):** This is a temporary implementation brief. Delete this file from the repository before the final release commit that contains the completed watcher redesign. The release agent must explicitly check for and remove all `TEMP_*.md` files under `winapp/` (and the repository root) before updating release notes, generating the core manifest, building a deploy archive, or tagging the release. Do not copy this temporary brief into the permanent manual or patch notes; migrate only the final, user-facing behavior and setup instructions.

## Purpose

Redesign the Windows companion uploader so it feels like a reliable desktop import tool rather than a large collection of technical controls. Preserve the existing server-owned upload contract, gallery-scoped API-key security, retry/deduplication behavior, optional SimConnect metadata, local thumbnail pipeline, system-tray operation, and optional AI worker. The work should improve discoverability, safety, progress visibility, recovery, and maintainability without creating a second gallery implementation.

This brief is based on the complete current `winapp/` folder:

- `winapp/gallery_watch_upload.pyw` is the single 4,670-line Tkinter application containing configuration models, HTTP/multipart helpers, watcher and manual-upload workers, local thumbnail generation, SimConnect integration, AI analysis backends, tray lifecycle, logging, and all views.
- `winapp/README.md` documents watch-folder, manual upload, local thumbnails, SimConnect, tray behavior, runtime files, and the optional AI metadata worker.
- `winapp/install.bat` resolves the active Python runtime, installs `requirements.txt`, verifies Pillow/pystray, and creates a Start Menu shortcut through a temporary PowerShell script.
- `winapp/run_gallery_watcher.bat` starts the `.pyw` application with `pythonw`.
- `winapp/requirements.txt` contains Pillow and pystray.
- `winapp/assets/tray-icon.png` and `winapp/assets/tray-icon.ico` are the tray assets.
- `winapp/SimConnect.dll` is the bundled optional Flight Simulator integration library. Treat it as a binary dependency, not source code.

## Current design assessment

### What is already strong

1. PHP Gallery remains authoritative for validation, permissions, storage, duplicate decisions, thumbnails, metadata, and AI job ownership.
2. The watcher ignores files present at startup, waits for stable files, hashes content, records local state atomically, retries failures, and confirms uncertain transfers against remote inventory.
3. Manual uploads use bounded producer/consumer pipelines: local thumbnail processes are separate from network upload threads, and temporary derivatives are cleaned up.
4. The application supports JPEG, PNG, GIF, WebP, HEIC/HEIF, and DNG suffixes; unsupported files are filtered rather than blindly submitted.
5. The tray and event queue keep Tkinter updates on the UI thread, and the close/revoke guards prevent revoking a key while work is active.
6. The AI worker uses server leases, heartbeats, retryable failures, and local analyzer fallbacks rather than claiming global ownership locally.

### Design problems to address

- The single Python file mixes presentation, persistence, transport, image processing, worker orchestration, native DLL calls, and AI providers. It is difficult to test or change safely.
- The window opens with a large technical form. First-time users must understand endpoint normalization, API keys, watcher semantics, thumbnail modes, worker counts, SimConnect, tray behavior, and AI before they can import one photo.
- Watch-folder, manual upload, and AI activity share one narrow status strip and one log. A user can see text, but not a clear queue, current item, throughput, remaining estimate, retry reason, or actionable error.
- Configuration values are loaded from a JSON file under the user profile, but the UI does not clearly distinguish secrets, connection state, per-job settings, and advanced diagnostics.
- Several operations are long-running but have no unified cancellation, pause, retry-now, or “open containing folder” workflow.
- The manual picker is image-oriented. ZIP imports and archive validation need an explicit staging/preview experience so a large archive cannot surprise the user with hundreds of uploads.
- HEIC/HEIF and DNG support is suffix-level support. Pillow availability, decoder support, embedded thumbnails, RAW conversion quality, and server acceptance can differ by machine; the UI needs capability-aware messaging instead of implying every installed decoder is guaranteed.
- The AI tab exposes powerful model and prompt settings beside ordinary upload controls. This makes the default workflow intimidating and increases the risk of accidental expensive model downloads or unintended external commands.
- The app uses polling and worker threads/processes correctly enough for current scale, but lifecycle ownership is spread across callbacks. A future design should make jobs, cancellation, cleanup, and shutdown explicit state transitions.
- There is no durable human-readable job history separate from the rolling log. Restarting the app makes it harder to answer “what happened to this file?”

## Target user experience

The primary path should be:

1. Open the app.
2. Select or confirm a gallery connection.
3. Choose **Watch folder** or **Import files**.
4. Select a folder/files/archive.
5. Review a compact import summary.
6. Start the job.
7. Watch clear progress and resolve only actionable exceptions.

Advanced controls remain available, but behind an **Advanced settings** disclosure. The first screen should not require users to understand Python runtimes, process counts, SimConnect, AI models, or multipart API details.

### Proposed window structure

Use a stable three-part layout that works at ordinary laptop sizes:

1. **Header/status bar**
   - Connection indicator: disconnected, ready, validating, or authentication error.
   - Target gallery name when returned safely by the endpoint; never display the raw API key.
   - Current activity indicator for watcher, manual import, and AI worker independently.
   - Tray/minimize affordance and a link to open the log/configuration folder.

2. **Main navigation**
   - **Import**: the default, task-focused screen for folder, files, and ZIP imports.
   - **Watch folder**: folder path, start/stop, startup-file policy, stability policy, and source-file deletion policy.
   - **Activity**: current queue, completed/failed/skipped counts, throughput, and item details.
   - **AI metadata**: disabled by default and visually separated from upload actions.
   - **Settings**: connection, runtime, thumbnail, SimConnect, tray, and advanced diagnostics.

3. **Activity drawer or lower panel**
   - Persistent recent events with severity, timestamp, filename, operation, and retry action.
   - Filters for all, active, succeeded, skipped, failed, and warnings.
   - “Copy diagnostic details” must redact API keys, tokens, credentials, private prompts where appropriate, and local paths unless the user explicitly chooses a local-support export.

Use standard Tkinter/ttk controls and a scrollable frame; do not introduce a required Node build or a new UI framework.

## Import workflow design

### Unified source selection

Expose three source cards with the same preview and queue pipeline:

- **Choose files**: multi-select supported images.
- **Choose folder**: recursively discover supported images only when the user explicitly enables recursion.
- **Choose ZIP archive**: stage an archive, inspect entries, and import valid supported files without requiring manual extraction.

The existing browser-side ZIP requirement is separate from this Windows application. This brief covers the Python companion app only; do not alter PHP upload semantics as part of the UI redesign.

### Preflight summary before upload

Before starting, show:

- supported files found;
- unsupported entries skipped, grouped by extension/reason;
- suspicious or unsafe archive entries rejected;
- duplicate candidates already known locally or remotely;
- estimated total bytes;
- whether local thumbnail generation is available;
- whether HEIC/HEIF and DNG can be decoded locally, server-side only, or not previewed;
- the selected target gallery (without exposing the API key);
- source deletion behavior.

The user must explicitly start the import after this summary. Do not upload while a picker is still being inspected.

### ZIP staging and safety

Implement archive handling as a bounded staging service, not as ad-hoc extraction inside the GUI callback:

- Accept `.zip` only in the Windows manual import flow.
- Inspect names before extraction and reject absolute paths, drive-letter paths, `..` traversal, NUL bytes, and entries that resolve outside a per-job temporary directory.
- Reject or skip symlink/reparse-point-like entries where the Python ZIP API exposes them; never follow an extracted link outside staging.
- Enforce configurable limits for archive entry count, uncompressed bytes, compression ratio, and per-file size to reduce zip-bomb risk.
- Preserve the archive-relative filename for diagnostics, but generate safe upload filenames according to the existing server contract.
- Extract only candidate supported media entries into a job-owned temporary directory.
- Do not extract unsupported entries or retain the complete archive after the job is cleaned up.
- Keep the original ZIP unless the user explicitly requests deletion after all accepted entries succeed.
- Make cleanup resumable after crashes; stale staging directories should be detected and safely removed on next startup after a conservative age threshold.

## Progress, queue, and recovery design

Introduce a small explicit job model, even if it initially remains implemented in the same Python file:

```text
Job
  id, source kind, source label, target gallery label
  discovered, accepted, skipped, uploaded, failed, cancelled
  bytes total, bytes sent, started_at, finished_at

Item
  stable id, original path/archive entry, size, hash
  state, attempt count, reason, server result, last update
```

Use a state machine with terminal states. Suggested item states:

`discovered → validating → staged → hashing → queued → uploading → confirmed`

and terminal/alternate states:

`skipped_duplicate`, `skipped_unsupported`, `failed_retryable`, `failed_permanent`, `cancelled`.

Requirements:

- UI updates are event-driven through the existing queue; workers never mutate Tk widgets.
- Show determinate progress when total work is known and indeterminate activity only while discovery/preflight is running.
- Provide pause/resume for manual imports where safe. Pause should stop submitting new work and allow in-flight requests to finish; it must not corrupt state.
- Provide cancel. Cancellation should stop new work, preserve recoverable item records, clean temporary thumbnails after workers exit, and tell the user which uploads may still have completed remotely.
- Provide retry-failed and retry-selected actions without reselecting the source.
- Keep remote inventory confirmation after uncertain HTTP failures.
- Show aggregate and per-item progress, but throttle UI refreshes to avoid freezing large imports.
- Preserve the current bounded queue limits and make them visible only in advanced settings.
- On restart, offer to resume recoverable jobs from local state rather than silently resending or silently discarding them.

## Connection and secret handling

- Keep gallery URL and API key in a dedicated **Connection** card.
- Add a **Test connection** action that performs the smallest safe authenticated request and reports endpoint normalization separately from authentication failure.
- Mask the API key by default, provide explicit reveal/copy controls, and never write it to ordinary activity messages.
- Keep revocation disabled while any worker can still issue requests; show the exact activity preventing revocation.
- Validate URL schemes and reject unsupported schemes before network access.
- Consider Windows Credential Manager/DPAPI-backed secret storage as the preferred future option, with an explicit migration path from the existing JSON key. Do not silently break existing installations; retain a documented fallback when secure storage is unavailable.
- Use atomic configuration writes, restrictive user-profile permissions where possible, and a schema/version field for future config migrations.

## Watch-folder design

Keep the current safe semantics prominent:

- Existing files are ignored when **Start watching** is pressed.
- A file is eligible only after size and modification time remain stable.
- The remote gallery is authoritative for duplicate confirmation.
- Failed files remain available for retry.
- Source deletion is opt-in and happens only after a confirmed upload.

Improve the watch tab with:

- a clear folder status and **Open folder** button;
- a compact stability policy control with an explanation of partially copied files;
- a switch for recursive subfolders, defaulting to the current behavior unless explicitly changed;
- an optional filename/extension filter summary;
- a “recently ignored at startup” count;
- a dry-run/preflight button that does not upload;
- a visible warning when source deletion is enabled;
- a per-folder state summary and last successful scan time.

Do not make watcher polling more aggressive by default. Preserve the existing interval and use filesystem notifications only as an optional optimization with polling fallback, not as a reliability requirement.

## HEIC/HEIF and DNG capability design

The current suffix allowlist includes `.heic`, `.heif`, and `.dng`, but local decoding depends on installed Pillow plugins/codecs and the server’s accepted formats. Make this explicit:

- Detect local open/thumbnail capability at startup and per file type.
- Distinguish “accepted for server upload,” “locally previewable,” and “locally thumbnailable.”
- Never reject a server-uploadable file solely because local preview/thumbnail generation is unavailable when server-side thumbnails are enabled.
- For unsupported local decoding, show a neutral placeholder and explain that the original will still be uploaded if the server accepts it.
- Catch decoder failures per item; one malformed HEIC/DNG must not abort the batch.
- Include actual detected Pillow/plugin capability in diagnostics, without requiring a heavy dependency for ordinary uploads.
- Keep the default upload path lightweight. Do not bundle large RAW/HEIC conversion stacks unless a separately selected optional feature installs them.

## Manual thumbnails and quality controls

Present the existing local-thumbnail option as a clear choice:

- **Server creates thumbnails**: simplest and most compatible.
- **This PC creates thumbnails**: faster for repeated imports when Pillow and codecs are available.

Explain that local generation affects derivatives only; originals and server validation remain authoritative. Add a small result report for rejected client thumbnails and automatic fallback to server generation. Keep thumbnail sizes, process count, and upload threads under Advanced settings with safe bounded defaults.

## AI worker redesign

Keep AI processing opt-in and visually separate:

- Default AI worker state remains disabled.
- The simple tab should expose enable/disable, backend, model version, start/stop, and current lease/job status.
- Move model paths, detector labels, thresholds, prompts, Ollama URL, and external command into an advanced expander.
- Show warnings before installing large optional packages or downloading model files.
- Clearly label external commands and show that their output is untrusted JSON validated by the client/server.
- Preserve server lease ownership, heartbeat, retry, and no-gallery-scan architecture.
- Do not allow an AI worker failure to affect upload workers or tray shutdown.

## Tray and lifecycle behavior

- Preserve minimize-to-tray and close-to-tray behavior, but show a concise tray tooltip with active worker counts and failures.
- Tray menu should include Open, Pause/Resume current import, Start/Stop watcher, Start/Stop AI worker when enabled, Open activity, Open log folder, and Exit.
- Exit must use one coordinated shutdown path: request cancellation, wait for workers within a bounded timeout, clean temporary resources, save state, then destroy Tk.
- If work cannot stop within the timeout, explain that in-flight network requests may finish and offer to keep running in the tray.
- Do not revoke credentials or delete source files during forced shutdown.

## Maintainability plan

Split the monolithic module incrementally, preserving import compatibility during the transition. A possible repository-relative structure is:

```text
winapp/
  gallery_watch_upload.pyw          # thin launcher/compatibility entry point
  uploader/
    __init__.py
    config.py                        # versioned config, defaults, migrations
    models.py                        # Job, Item, capability and event models
    state_store.py                   # atomic config/state/job persistence
    transport.py                     # URL normalization, JSON and multipart HTTP
    inventory.py                     # remote duplicate inventory and confirmation
    discovery.py                     # files, folders, ZIP staging and safety limits
    media.py                         # suffix allowlist, capability checks, thumbnails
    workers.py                       # watcher/manual/AI orchestration and lifecycle
    simconnect.py                    # ctypes structures and camera client
    ai.py                            # analyzer adapters and dependency checks
    ui.py                             # Tkinter shell, tabs, activity views, tray bridge
```

Use small commits/phases. Keep the existing `.pyw` entry point working for Start Menu shortcuts, `--once`, and `run_gallery_watcher.bat`. Avoid a mandatory package installer or build step; plain Python execution must remain sufficient.

## Persistence and migration

Add explicit schema versions to `config.json` and `upload_state.json`. Migrate old keys defensively:

- missing new keys use documented defaults;
- invalid values are clamped or replaced safely;
- unknown keys are preserved only if harmless, otherwise ignored;
- API keys are never copied into logs or job exports;
- old upload history remains valid and is not invalidated by a UI redesign;
- stale temporary job directories are recoverable or cleaned safely.

Use atomic write-then-replace operations and tolerate interrupted writes. If a state file is malformed, preserve a quarantined copy for diagnostics before starting with safe defaults.

## Testing and verification plan

Add focused plain-Python tests or deterministic helper tests without requiring a live gallery for:

1. Config defaults, schema migration, invalid values, atomic writes, and secret redaction.
2. URL normalization and endpoint/authentication error classification.
3. File stability, startup inventory, extension filtering, hash deduplication, retry backoff, and restart recovery.
4. ZIP path traversal, absolute paths, symlink-like entries, archive count/size/ratio limits, unsupported entries, malformed archives, and cleanup after cancellation.
5. HEIC/HEIF/DNG capability detection and per-file decoder failure isolation.
6. Job/item state transitions, progress aggregation, pause/cancel behavior, bounded queues, and worker shutdown.
7. Remote-inventory confirmation after timeout or ambiguous HTTP responses.
8. Thumbnail fallback and temporary-directory cleanup.
9. SimConnect unavailable/override/invalid-location paths without requiring Flight Simulator.
10. AI lease/heartbeat/retry isolation from upload workers.
11. Tray callbacks and dynamic status updates without direct Tk calls from worker threads.
12. Redacted diagnostic export and log rotation/size bounds.

Manual smoke test on Windows should cover:

- fresh install through `install.bat` and Start Menu launch;
- upgrade with an existing config/state file;
- watch-folder startup ignoring existing files;
- a partially copied file becoming stable;
- manual mixed selection with JPEG, HEIC/HEIF, DNG, unsupported files, and a ZIP;
- duplicate and failed upload retry;
- pause/cancel/retry after a large batch;
- server-side versus local thumbnails;
- minimize, close-to-tray, reopen, and clean exit;
- SimConnect absent and present;
- AI disabled by default and optional dependency installation;
- revoked/invalid API key without leaking it in UI/logs.

Run Python compilation checks on every changed `.py`/`.pyw` file and retain the project’s existing PHP regression checks when the feature changes only `winapp/`. Update `winapp/README.md` only during implementation, not while this temporary brief is being prepared.

## Phased implementation sequence

### Phase 1 — Baseline and safety contracts

- Add tests around current config/state, discovery, upload state, and worker lifecycle.
- Define versioned models and event vocabulary without changing visible behavior.
- Capture current endpoint, API-key, no-JavaScript/server-authority, duplicate, retry, and source-deletion contracts.

### Phase 2 — Discovery and import preflight

- Extract file discovery and ZIP staging into testable services.
- Add archive safety limits and capability-aware HEIC/DNG reporting.
- Add the preflight summary and explicit Start action.

### Phase 3 — Job queue and progress UX

- Introduce Job/Item states and bounded queue reporting.
- Add determinate progress, per-item outcomes, pause/cancel, retry-failed, and restart recovery.
- Keep existing workers behind adapters until behavior is covered by tests.

### Phase 4 — Window and settings redesign

- Implement the compact Import, Watch folder, Activity, AI metadata, and Settings views.
- Move advanced controls behind disclosures and improve connection/secret presentation.
- Preserve tray and direct `--once` compatibility.

### Phase 5 — Module extraction

- Split transport, persistence, discovery, media, workers, SimConnect, AI, and UI modules.
- Keep `gallery_watch_upload.pyw` as a stable launcher and avoid broad unrelated formatting changes.

### Phase 6 — Final documentation and release cleanup

- Update permanent `winapp/README.md` with implemented behavior, setup, migration, ZIP safety, capability limitations, and recovery instructions.
- Run all focused tests and Windows smoke tests.
- Delete this temporary file and any other temporary feature briefs before release metadata, patch notes, manifest, and packaging are finalized.

## Definition of done

The redesign is complete when a new user can import a folder, selected files, or a safe ZIP through a short understandable flow; see what will happen before upload; monitor progress; recover from duplicates, failures, cancellation, and restart; and use the tray without losing state. Existing users retain their gallery endpoint, API-key behavior, watch-folder semantics, optional local thumbnails, SimConnect metadata, AI worker, and upload history. All source-sensitive operations remain server-authoritative, and the release process removes this temporary brief before shipping.
