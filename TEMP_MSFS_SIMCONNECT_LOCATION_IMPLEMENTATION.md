# WinApp 0.3.1 — FS2020 SimConnect response investigation

## Evidence and current findings

- Robert's acquisition reports successful OPEN and aircraft request, two dispatch packets and `last_recv_id=8`, followed by `Aircraft data timeout`.
- The SDK receive ID 8 is `SIMCONNECT_RECV_ID_SIMOBJECT_DATA`; the current implementation already routes it to the aircraft handler.
- Request and definition IDs are both 1. The callback runs synchronously while the acquisition lock is held; cleanup follows response pumping. No premature-close race was found.
- The aircraft handler silently rejects request, definition or object ID mismatches. Its `dwObjectID == 0` requirement confuses the request's USER selector with the returned object identifier. The request SDK documents that the actual user-aircraft object ID can change.
- Current metadata/payload layout is ten DWORDs (40 bytes), followed by latitude, longitude and altitude as three FLOAT64 values. Existing fake replies reproduce the production structure and zero object ID, hiding this rejection.
- Robert's log does not contain the returned metadata fields. A nonzero object ID is therefore a supported bug explanation, not an observed field value. New diagnostics will identify his exact rejection if another mismatch remains.
- The DLL path precedes failure details in the summary; Windows-path redaction can consume those later details. The activity formatter also duplicates the `[simconnect]` routing marker.

## Implementation plan and scope

1. Correlate aircraft replies by the submitted request and definition IDs; decode the bounded SDK payload beginning at `dwData`.
2. Record bounded receive metadata, dispatch sequence and explicit ignored/malformed/invalid reasons; retain native exception details and existing deadlines.
3. Preserve FS2024 camera selection and aircraft fallback. Remove duplicate display prefixes without losing event classification.
4. Add independent wire-format regression packets, including nonzero object IDs, mismatches, short payloads and valid zero coordinates.
5. Prepare version 0.3.1, rebuild the installer and matching update metadata, review independently and run final verification.

No upload API, simulator DLL or installer packaging changes are planned.

## Sources

- [Microsoft: RequestDataOnSimObject](https://docs.flightsimulator.com/html/Programming_Tools/SimConnect/API_Reference/Events_And_Data/SimConnect_RequestDataOnSimObject.htm)
- [Microsoft: SIMOBJECT_DATA](https://docs.flightsimulator.com/html/Programming_Tools/SimConnect/API_Reference/Structures_And_Enumerations/SIMCONNECT_RECV_SIMOBJECT_DATA.htm)

## Progress and verification

Initial dispatch, FFI and regression-harness investigations completed independently by three `6.1-sol-low` agents.

The implemented fix removes only the incorrect zero-object filter, retaining request/definition correlation and SDK flags/count/entry checks. Metadata is copied only within both callback and declared packet bounds; three little-endian doubles are decoded from bytes 40-63. Foreign IDs remain nonterminal so a later matching reply can succeed. At the deadline, an observed rejection remains explicit instead of becoming a generic timeout. Existing native exception attribution remains authoritative.

New diagnostics include `dispatch_sequence` (last 12 numeric receive IDs), `aircraft_data_packets`, and `aircraft_packets` (last 8 records). Each record includes callback/declared size, receive ID, expected request/definition IDs, metadata size, payload offset/available bytes, and a rejection reason. Safely readable packets additionally include received request/object/definition IDs, flags, entry/total and count. Decoded coordinates and metadata are logged at debug level without binary dumps.

Changed implementation/test files:

- `winapp/uploader/simconnect_location.py`: response correlation, bounded copies and concrete diagnostics.
- `winapp/gallery_watch_upload.pyw`: routing marker retained for classification and removed from visible/persisted messages.
- `winapp/tests/test_simconnect.py`: independent literal-ID wire replies and eight regression tests.
- `winapp/tests/test_redesign.py`: two routing/display/redaction tests.

Focused verification: 32 SimConnect tests passed; two activity tests passed. The final portable Windows-path test also passed individually after strengthening its metadata assertions. The wire fixture reproduces a valid nonzero object ID (73) that the old filter rejected. All new declarations have docstrings/type hints and no future imports.

Independent `6.1-sol-low` review found no actionable issues with memory bounds, foreign reply correlation, exception precedence, zeros, history/reset or MSFS 2024 behavior. Main-agent review also checked logging classification and the SDK's required 1/1 entry fields. MSFS 2024 camera ABI, camera-first strategy, aircraft fallback, connection lifecycle and timeout remain unchanged; upload API and packaging are untouched.

Version 0.3.1 and operational documentation are prepared. All four matching manuals/PDFs compiled successfully with only their known underfull/Czech microtype warnings. Inno Setup 7 built `winapp/dist/PHPGalleryUploader-0.3.1-Setup.exe`; the build validated the embedded runtime and isolated EXE startup. Matching `winapp/dist/winapp-update.json` contains the actual size and SHA-256. The managed-file manifest and release-consistency preflight passed.

Final `php scripts/audit.php --profile=release` completed with 265 PHP, 25 Node, 128 WinApp and 11 Chromium browser tests passing, zero test failures, and the native installer version smoke enabled. Complete PHP/JavaScript syntax, MVC boundaries, mutation/runtime contracts, release consistency, manifest freshness and Git whitespace checks passed. Seven PHP disposable-database/HTTP workflows remained skipped. Overall status is `BLOCKED` solely because the changed-declaration documentation checker has no Python parser for the four edited Python/PYW bodies. New declarations were checked for documented, typed definitions during implementation and review; that does not replace the missing central parser. Evidence: `cache/test-audit/latest.md`.

The main agent's final diff review found no remaining code or integration issue. This TEMP report is outside the managed manifest and release-fingerprint root-file list; updating these final results does not change the qualified source inputs.

No live FS2020 result is claimed. Robert must install 0.3.1 and verify that location succeeds; if it fails, copy the new aircraft packet metadata and exact reason. His supplied old log does not reveal the actual response object/request/definition IDs.
