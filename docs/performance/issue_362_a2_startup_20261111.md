# Issue #362: A2.5 client/server response timing and first-use milestones

**Scope:** This is the small client-side A2.5 diagnostics slice on the existing #362 feature branch. It does not finish parent A2, implement thumbnail HTTP batching or modify the protected `develop` branch.

## Request-local PHP observation

The PHP A2.4 profiler is still disabled unless the server owner explicitly sets `PHP_GALLERY_A2_PASSIVE_PROFILE=1`. The WinApp does not send a profiling toggle, request correlation header, credential, nonce, polling request or additional API call. It strictly parses at most one existing `Server-Timing` value from **the exact successfully validated HTTP response**, with six fixed numeric metrics, the existing SQL 512-operation cap and 64 MiB hash-byte cap. Malformed, duplicated, unknown, excessively long or unavailable headers produce **missing** measurements, never a transport/authentication failure.

Successful `gallery_tree` and `gallery_page` JSON GET results carry their own numeric PHP interval and measured client send-to-headers interval through the existing worker control; the containing `LinkingSession` owns only a 300-second/256-observation rolling aggregate. Accepted native `thumbnail_single`, `photo_single`, `media_page` and `revision` 200/304 responses can likewise use caller-owned `MediaReadTimings`. Failed/unauthorized HTTP responses never grant PHP telemetry or authorize cached bytes. Repeated/stale-socket GETs still count physical HTTP attempts, but a comparable PHP/client interval delta is calculated **only for one known physical send**.

`unattributed_to_measured_php_ms = client_send_to_headers_ms - php_header_ms` is a conditional, measured remainder, **not** direct evidence of network delay, WEDOS queueing, PHP-FPM worker pressure, HTTPS handshake latency or a gateway malfunction. The PHP interval starts only after its profiler is initialized and ends before header emission. It does not include earlier bootstrap or later streaming, so the remainder can contain other non-PHP, pre-profiler or transport phases. If the server PHP interval exceeds the client measured interval, either value is missing, or the request was retried, that derived number is unknown.

The safe `Photos PHP timing comparison` output retains fixed per-route aggregate counts, PHP present/missing, nearest-rank PHP/SQL p50/p95 and the count/p95 of comparable **unattributed** durations. It never exports raw headers, gallery names, photo IDs, token values, issuer URLs, SQL, filenames or response contents.

## User-visible startup milestones

The existing `CataloguePerformanceWindow` records one monotonic pass start for a newly linked session or manual tree refresh. Only its Tk owner can mark each first-once, fixed-labelled event:

- `cached_rows_mounted`: first cached catalogue rows attached to Tk, **display-only** and not proof of authorization
- `fresh_rows_confirmed`: first server-authorized tree or legacy page rows accepted and present in Tk
- `gallery_view_mounted`: first embedded album viewer constructed and attached to Tk, not proof of a decoded photograph
- `first_thumbnail_visible`: first successfully created `ImageTk.PhotoImage` associated with an actually viewable admitted tile, never a placeholder or failed read

The thumbnail callback checks the exact active session, catalogue generation, cancellation and selected gallery before changing the private numeric recorder. A refreshed catalogue resets first-once milestones without deleting the bounded historical phase samples. The derived `fresh_to_first_thumbnail_ms` is available **only if** a fresh confirmed-row milestone preceded the accepted image milestone. All other missing or reversed measurements are displayed as unknown. Tk timing marks widget operations and visible widget state, **not OS compositor scan-out or physical pixel presentation**, which cannot be proven by Tkinter alone.

## Remote qualification and limits

The existing issue-scoped A2 GitHub workflow checks the strict parser and its optional response handling with portable Python 3.14 on Linux and Windows, plus genuine Tk widget tests on Windows. The same workflow continues to qualify the independently isolated PHP 8.1/8.5 + MariaDB 10.11 authorized 200/304 and denied 401/404 routes. All input data are synthetic on GitHub-owned runners. The ordinary release FULL matrix and any approved WEDOS staging measurement remain separate acceptance work.

Do not extrapolate PHP worker concurrency, proxy header preservation, provider 502/503 causes or WEDOS performance from these ephemeral GitHub loopback tests. Parent A2 stays unchecked until its full requirements and integration qualification are complete. No new public legacy API-key endpoint, TeX manual change or server configuration default is introduced.
