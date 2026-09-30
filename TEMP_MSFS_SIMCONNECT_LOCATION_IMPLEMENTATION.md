# MSFS SimConnect location implementation checkpoint

## Status

- 2026-09-30: implementation, independent/main review and actual WinApp 0.2.0 build complete. All executed central suites passed; overall audit remains BLOCKED solely by unsupported Python documentation coverage. Live simulator/update acceptance is explicitly unverified.
- User requests one SimConnect transport, automatic simulator detection, preserved MSFS 2024 camera world position, MSFS 2020 aircraft position, bounded fallback, diagnostics, predictable bundled runtime, tests and independent review.
- Main agent owns this checkpoint and final architecture/review. Three parallel `gpt-6.1-sol` / `low` agents analyze transport, packaging, and tests/diagnostics.

## Relevant files found

- `winapp/gallery_watch_upload.pyw`: ctypes camera transport, DLL discovery, location DTO, watcher capture and multipart upload integration.
- `winapp/SimConnect.dll`: existing bundled native binary; architecture/version/exports under investigation.
- `winapp/build_installer.py`, `winapp/installer.iss`, `winapp/build.bat`: PyInstaller/Inno Setup distribution.
- `winapp/uploader/diagnostics.py`: diagnostic redaction.
- `winapp/tests/test_redesign.py`, `winapp/tests/test_build_installer.py`: current regression coverage.
- `winapp/README.md`, `TESTING.md`, `scripts/audit.php`: documentation and authoritative verification interface.

## Initial observations (before implementation)

- Existing camera path uses `CameraAcquire`, `CameraGetStatus`, `CameraGet(WORLD)` on a short-lived connection per screenshot, with a one-second query timeout.
- All camera exports are bound before opening the transport. No aircraft strategy or handshake parsing exists yet.
- EXCEPTION packets already stop the wait, but lack send-ID attribution.
- Packaging already includes one SimConnect DLL; exact runtime verification is pending.
- Upload metadata remains optional; server contract will be preserved unless evidence requires a change.

## Initial decisions and investigation work

- Read exact transport, metadata, packaging and tests before editing.
- Verify native ABI and generation/capability evidence against official simulator SDK documentation.
- Preserve existing camera coordinates/units and server fields; represent actual position source internally and in logs.
- Use central `php scripts/audit.php` for required regression/syntax checks. Full profile is required before handoff. Refresh/check core manifest after final managed-source edits.
- User subsequently requested WinApp version `0.2.0` (previous `0.1.0`). No CMS release, commit, tag, publication or deployment requested.

## Initial verification and risks

- No tests run yet.
- Real MSFS 2020/2024 availability and live native runtime backward compatibility remain to be established.
- Avoid overlapping subagent edits. Existing Git status initially clean (host Git ignore-path permission warnings only).

## Confirmed analysis and architecture

- Bundled DLL: existing tracked x64 PE (`0x8664`), SHA256 `9eb25bf07371fb8f36494ae609bafb12fb0f8dc64ef966361cd5080ce26f6e66`, without a Windows version resource. Camera and classic data-request exports exist. Binary remains unchanged; no second runtime.
- Build: PyInstaller onefile embeds DLL at extraction root; Inno packages that EXE. Change extraction destination to `runtime/simconnect`, validate PE/exports and archived DLL hash before installer creation. Inno Setup 7 is present. Saved Python/PHP paths are available in ignored agent state.
- Transport implementation delegated to `transport`: `winapp/uploader/simconnect_location.py` only. Main integrates reexports/watcher/report. Packaging agent owns build script and its tests; test agent owns new `winapp/tests/test_simconnect.py`.
- One isolated connection per acquisition avoids cross-photo replies. Shared dispatch parses OPEN, camera/status/exception and user-aircraft packets. Core API signatures are mandatory; camera signatures optional. Use send-ID attribution, aircraft request/definition correlation, instance serialization and reset state between requests.
- OPEN application name/version drives generation detection. Official SDK describes server application and SimConnect version fields; Asobo DevSupport identifies application major 11 as 2020 and 12 as 2024. Ambiguous metadata remains unknown and uses bounded camera capability probe with aircraft fallback.
- Preserve packed camera ABI, `CameraAcquire -> CameraGetStatus -> CameraGet(WORLD)` and existing latitude/longitude/altitude mapping. MSFS 2020 skips camera. Aircraft uses `PLANE LATITUDE` / `PLANE LONGITUDE` in degrees and `PLANE ALTITUDE` in feet, FLOAT64, USER/ONCE.
- Common monotonic one-second dispatch budget reserves aircraft time rather than adding multiple long waits. Persistent connection/cache deliberately avoided as unrelated complexity and stale-coordinate risk.
- Server accepts only `sim_location_source=simconnect_camera`. Keep historical wire fields for compatibility with deployed galleries; actual `camera_world_position`, `aircraft_position` or `aircraft_position_fallback` source remains internal/logged. No PHP API changes.
- All geolocation exceptions remain soft failures at watcher boundary. UI diagnostic copy consumes a last-completed snapshot without opening a connection and retains existing path/secret redaction.
- Existing Microsoft binary has no accompanying provenance/licensing record; retain it without claiming newly verified redistribution licensing or downloading replacement SDK.

## Official references consulted

- https://docs.flightsimulator.com/msfs2024/html/6_Programming_APIs/SimConnect/API_Reference/Structures_And_Enumerations/SIMCONNECT_RECV_OPEN.htm
- https://docs.flightsimulator.com/msfs2024/html/6_Programming_APIs/SimConnect/API_Reference/Camera/SimConnect_CameraGet.htm
- https://docs.flightsimulator.com/msfs2024/html/6_Programming_APIs/SimConnect/API_Reference/Structures_And_Enumerations/SIMCONNECT_DATA_CAMERA.htm
- https://docs.flightsimulator.com/msfs2024/html/6_Programming_APIs/SimConnect/API_Reference/Structures_And_Enumerations/SIMCONNECT_EXCEPTION.htm
- https://devsupport.flightsimulator.com/t/documentation-for-simconnect-recv-exception-is-incorrect/13555
- https://devsupport.flightsimulator.com/t/simconnect-recv-open-reports-different-version-number-to-fs/11727

## Implemented changes and user steering

- Main `.pyw` now reexports the compatibility client/DTO from the isolated module, creates a reusable serialized client per watcher, catches the entire optional metadata workflow and publishes completed diagnostics by assignment. Logs/activity use the `simconnect` operation; the UI label covers simulator location rather than camera only.
- Diagnostics copy contains the last acquisition snapshot with the existing redaction. No UI-thread SimConnect probe.
- User initially requested a physical DLL beside the EXE, then explicitly withdrew that request after learning about embedding. Final installer file payload remains one application EXE; there is no sidecar duplication. Loader prefers the embedded `runtime/simconnect/SimConnect.dll` after explicit overrides. Source Python launch retains the existing source-tree DLL; adjacent/legacy DLLs remain discovery fallbacks.
- User explicitly prohibited installing tools. Existing Inno Setup 7 and Python 3.14 provide all required packages (PyInstaller 6.19.0, Pillow 12.2.0, pystray 0.19.5, Tcl 8.6.15). Added `--use-installed-dependencies` to reuse them without pip/venv installations; default build remains isolated.
- Build preflight checks x64 PE/export requirements, then actual onefile archive payload SHA256. Installer tests verify EXE-only installation, archive hash verification, cleanup, atomic publication and installed-dependency mode.
- New native-fake tests cover the requested success/fallback/failure, exceptions, packet validation/correlation, repeated/concurrent requests, cleanup, diagnostic logs and actual watcher upload tolerance.
- Independent low-agent review found no confirmed blocker but correctly recommended a protected ctypes callback; implemented `_dispatch_safe` so callback Python errors cannot be silently converted into timeouts.
- Main review found actual native handshake product names `KittyHawk` and `SunRise` in SDK DevSupport evidence. Added recognition and realistic tests; relying only on the retail product name would leave actual 2020 connections unknown. The reported version is internal server version, not retail version.
- Development tests initially exposed malformed camera-packet errors incorrectly blocking fallback and thrown camera-call errors escaping to the top-level acquisition catch. Both fixed in transport before final audit.

## Validation so far

- New SimConnect test development: expanded to 23/23 passing tests, including actual native names, callback exceptions and a deterministic shared deadline. Final loader-priority steering is reflected in fixtures before the central audit.
- Build test development: 11/11 passed, including the offline reuse mode. Installed Python 3.14 dependency preflight passed.
- Native DLL smoke check passed: original x64 DLL loaded, all required exports bound; without a running server, `SimConnect_Open` returned `E_FAIL` and acquisition returned a nonfatal unavailable result with complete snapshot.
- Central release audit and actual no-install build pending. Independent final review completed without remaining confirmed findings.

## Final version and qualification scope

- Read `RELEASE.md` before metadata edits. Its `prepare_release.php` only registers CMS version/manual/release metadata; it does not own `winapp/VERSION`. Running it with `0.2.0` would incorrectly change the independent CMS release. Update WinApp VERSION and its README markers directly; CMS markers/manual/history remain untouched.
- Final validation profile is now `release` (no quick/full audit was run), following the mandatory alternatives rule after the user requested the WinApp versioned installer. Current CMS release consistency is checked by that profile as additional repository coverage.
- Reviewed all source changes and completed independent low-agent re-review: no remaining confirmed actionable findings. Callback protection, actual native identities and successful-send-only correlation are verified in code and fixtures. Main final review also restored detailed migrated docstrings and clear product labels in failure diagnostics.
- User rejected `from __future__ import annotations`; removed it. No new future import remains. All new transport/build/test helpers now have docstrings, including native ABI, units, deadline, send/request correlation and test limitations. The requested `SimulatorInfo.from_open()` documentation is detailed and its selection branches are explicit.
- Release preflight found a pre-existing stale CMS architecture example (`0.110` versus runtime `0.112`). Used registered `prepare_release.php 0.112 --released-at="2026-09-30 16:29:51"` to correct only that marker, preserving every other CMS version/date/history file; also documented the new WinApp owner in the existing architecture section. Regenerated and checked the manifest. Release consistency now passes 10/10.
- User additionally requested enforced app shutdown during installer/update. Packaging agent is implementing a bounded pre-install close/verification path scoped to the selected installation's EXE, with installer failure if the target remains running. This must handle tray mode, onefile parent/child processes and silent installs before target files are changed.
- Installer shutdown implemented and documented: `PrepareToInstall` uses WMI to match absolute executable paths, terminates all matching processes, then checks a fresh process list for up to three seconds. Unknown/failure refuses installation in English/Czech before file replacement; other-directory copies are untouched. Restart Manager force-close is a secondary safeguard and `[Run]` retains relaunch ownership. OS-controlled WMI calls themselves are not hard bounded by the polling deadline.
- Real Inno Setup 7 compiled the final script successfully using a harmless `where.exe` payload fixture (no installer execution and no tools installed). The real product build will independently compile/package the actual uploader after the audit. Running-uploader upgrade behavior remains a manual acceptance item.

## Final audit and last review correction

- First central release pass inside the filesystem sandbox passed PHP, Node, WinApp and syntax suites but could not launch Chromium/CDP or read an existing owner-restricted installer for qualification identity. Reproduced one affected browser suite outside the sandbox: PASS. Read-only hashing of the existing installer also succeeded there. Repeated the same release profile outside the sandbox; all executable suites passed (261 PHP PASS / 7 fixture-dependent SKIP, 25 Node PASS, 72 WinApp PASS, 8 browser PASS; 968 PHP / 116 JavaScript syntax checks).
- The central result is BLOCKED, not PASS: its changed-declaration documentation checker has no Python declaration parser and reports five changed Python files as unsupported. This is tooling coverage, not evidence of missing docstrings. Every class/function in the new transport and changed Python build/test modules has a docstring; main integration retained/extended its existing documentation. No audit bypass or baseline suppression was introduced.
- Main final review found a late uncorrelated CAMERA_API exception could be attributed to an already active aircraft request. Preserve authoritative known send-ID attribution, but classify exception 46 as camera when the send ID is missing/unrecognized. Independent regression reproduces then fixes both send ID 0 and 9999; valid aircraft data remains accepted. Focused development test PASS (both cases), bringing the new SimConnect file to 24 test methods.
- A fresh central release profile and actual WinApp 0.2.0 installer build are pending after this final source correction. No live simulator or installation/update execution is claimed.

## Actual installer build and final input freeze

- After the exception correction, central release audit again completed every executable suite successfully: 261 PHP PASS / 7 SKIP, 25 Node PASS, 73 WinApp PASS / 0 failures/errors/skips, 8 browser PASS, syntax 968 PHP / 116 JavaScript, release consistency, manifest, MVC and whitespace PASS. Overall BLOCKED remains limited to unsupported Python documentation coverage (five files). Duration 179.22 seconds.
- Actual first no-install build exposed that PYTHONNOUSERSITE hid all three pre-existing user-site packages. Fixed only explicit installed-dependency mode to remove that variable, including an inherited value; the default isolated venv still sets it to 1. Both modes continue removing custom PYTHONPATH/PYTHONHOME. Two focused environment regression cases PASS; independent low-agent review confirmed the fix.
- Actual second build PASS with Python 3.14.4 and installed PyInstaller/Pillow/pystray, Inno Setup 7. Preflight, embedded DLL digest verification, frozen EXE --help smoke and real-product installer compilation all completed. No tools/packages installed and no installer executed. Published only `winapp/dist/PHPGalleryUploader-0.2.0-Setup.exe`; intermediate staging was removed and existing 0.1.0 artifact was preserved.
- Final release-profile audit is being repeated after this build-environment source correction. No further product-source edits are planned.
- Final native aircraft packet review agrees with the official [SIMOBJECT_DATA structure](https://docs.flightsimulator.com/msfs2024/html/6_Programming_APIs/SimConnect/API_Reference/Structures_And_Enumerations/SIMCONNECT_RECV_SIMOBJECT_DATA.htm): request-on-object entry/count are 1, three untagged FLOAT64 values start at byte 40 without padding.

## Changed file inventory

- `winapp/uploader/simconnect_location.py`: transport, identity, location strategies, packet/exception handling, diagnostics and documented ABI.
- `winapp/gallery_watch_upload.pyw`: compatibility imports, reusable watcher client, nonfatal metadata integration, logs and redacted diagnostics.
- `winapp/build_installer.py`: runtime/archive validation and build using existing dependencies.
- `winapp/installer.iss`: verified enforced shutdown before installation/update file replacement.
- `winapp/tests/test_simconnect.py`, `winapp/tests/test_build_installer.py`: provider/native-fake/upload and runtime/packaging/environment/installer contracts.
- `winapp/VERSION`, `winapp/README.md`: independent WinApp 0.2.0 and product/build documentation.
- `ARCHITECTURE.md`, `TESTING.md`: architecture and automated/manual verification; existing CMS architecture marker corrected through the registered release tool.
- `app/core-manifest.json`: refreshed managed-document digest, CMS version remains 0.112.
- `TEMP_MSFS_SIMCONNECT_LOCATION_IMPLEMENTATION.md`: requested continuous technical checkpoint.

## Known practical limits

- No running MSFS 2020/2024 was available. Deterministic packet/native-fake tests establish logic and ABI layout; real cross-generation server compatibility, camera latency and map coordinates still require the documented live acceptance matrix.
- No installer was executed, respecting the no-install request. Compilation and source contracts cover the shutdown implementation; running/tray/silent update behavior and WMI-unavailable refusal still require live installer acceptance. WMI/native loading calls are OS-controlled; only dispatch/poll waits are bounded by our deadlines.
- The single pre-existing Microsoft DLL has no provenance/license record or file-version resource in this repository; binary unchanged. Camera and transport capability are intentionally distinct.
- Central Python docstring coverage is unsupported; it was not silenced or treated as PASS. Seven existing PHP tests need external disposable MySQL/HTTP fixtures. No CMS manual/publication qualification, commit, tag, push or release publication was performed.

## Final completion record

- Final authoritative `php scripts/audit.php --profile=release` after the last product edit and successful build: duration 178.36 seconds. PHP 261 PASS / 7 SKIP; Node 25 PASS; WinApp 73 PASS / 0 failures/errors/skips; browser 8 PASS; PHP syntax 968 PASS and JavaScript syntax 116 PASS. MVC boundaries, mutation/hardening contracts, release consistency, manifest freshness and Git whitespace PASS. Overall BLOCKED: five Python declaration-parser coverage gaps, with no documentation findings. Report: `cache/test-audit/latest.md`.
- Independent `gpt-6.1-sol` low reviewer examined the final implementation and each subsequent correction. No remaining confirmed actionable finding. Main review covered all tracked diff and all new source/test files; no unrelated runtime/server changes. No new future import remains. Existing docstrings retained and new helpers documented, especially OPEN identity reasoning, native layouts, units, provider deadlines, cleanup and correlation.
- Installer artifact: `winapp/dist/PHPGalleryUploader-0.2.0-Setup.exe`, 33,525,804 bytes; file version 0.2.0.0, product version 0.2.0. SHA256 `87cf1379f0fab104fe9dd66036ffc796904e3e07c46a87036fb57c6083a7435e`. Actual source-DLL/embedded-DLL digest equality passed. Build log: `cache/winapp-build-0.2.0.log`; no remaining `.build-*` directory.
- Installer/update forcibly stops this installation's uploader before file writes and refuses on unverifiable exit; distribution remains a single installer with one application EXE containing its runtime. No installed sidecar DLL, tools installation, application installation or external publication occurred.
- Final checkpoint retained at the user's explicit request; it records both completed work and the practical verification limits above. No product-source edits after the final audit.
