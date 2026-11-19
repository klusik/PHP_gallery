<!--
Project: PHP Gallery
Repository: https://github.com/klusik/PHP_gallery
File: docs/performance/gallery-http-comparison.md
Module Type: Developer Measurement Reference
Purpose: Describe guarded source-qualified localhost HTTP comparisons.
Responsibilities: Explain invocation, privacy, sampling and restoration limits.
Author: Rudolf Klusal
License: MIT License (see LICENSE file in repository)
-->

# Source-qualified loopback HTTP comparison

`gallery_http_compare.py` is a user-invoked experimental measurement tool. It is
not registered with the audit runner and does not replace hosted qualification.
Run the whole Python process **outside the sandbox**: every Git subprocess inherits
that execution boundary, as required by the repository's Git policy. Do not run
it while another process edits, commits, prepares or switches the checkout.

Finish and commit the complete candidate on its existing `feature/...` branch
first. The candidate argument must equal initial HEAD; the baseline must be an
existing full commit SHA and an ancestor of that candidate. Both source arms
remain unchanged. No ref is reset, forced or rewritten. The tool verifies the
canonical `klusik/PHP_gallery` origin, current feature branch, source identities,
clean index/worktree (including untracked files), unchanged feature ref and
expected HEAD before switching. It uses detached checkouts for collection and
restores the original branch in `finally`. Unexpected edits or HEAD/ref movement
stop restoration rather than overwriting the new state; inspect `metadata.json`
and retain that state for manual resolution. An interrupted/killed process cannot
guarantee restoration. Ignored installation state remains present throughout;
source switching does not roll back database state, PHP sessions or caches.

The server must already serve this checkout through its normal loopback mount.
The runner never starts a server, modifies configuration, imports a database,
changes galleries or loads browser/user cookies. Verify that both source arms
support the same installed runtime/configuration before running. PHP OPcache
settings must detect source changes; otherwise HTTP responses cannot establish
which PHP code actually executed. Exact Git/tree identities establish checkout
provenance, not independent server process provenance. Record the server setup
and any unverified runtime cache behavior with the results.

Create a JSON case list outside the checkout:

```json
[
  {"name":"gallery-c1-fresh","url":"http://127.0.0.1/Galerie/?page=gallery&gallery=example","concurrency":1,"session":"fresh"},
  {"name":"gallery-c8-same","url":"http://127.0.0.1/Galerie/?page=gallery&gallery=example","concurrency":8,"session":"same"}
]
```

Only literal loopback IPs and the exact hostname `localhost` are accepted.
For localhost, curl receives `--resolve localhost:<effective-port>:127.0.0.1`,
retaining the original HTTP Host/mount/canonical cohort without DNS drift.
All other hostnames are refused. HTTP redirects, URL credentials, fragments, control characters and
query keys outside public navigation and duplicate decoded query keys are refused.
Decoded page selectors accept only `gallery` and `smart_gallery`; encoded keys
cannot bypass the same duplicate and selector checks. The query allowlist is page,
gallery, id, slug, sort, order, lang, tag, offset and limit, with restricted plain
navigation values. Never put secrets in navigation values or paths. Curl bypasses
proxies and its user configuration, follows no redirects and accepts only HTTP(S).
HTTPS uses normal certificate verification. An unavailable curl.exe, timeout,
non-2xx response, invalid case or source-state mismatch terminates the comparison.

```powershell
python scripts/gallery_http_compare.py --baseline <full-baseline-sha> --candidate <full-candidate-sha> --branch feature/example --cases C:\Temp\gallery-cases.json --session-cookie-name gallery_admin_session --output C:\Temp\gallery-comparison-new
```

The output directory must be new, its parent must exist, and it must be outside
the checkout. The runner refuses overwrite. Temporary curl headers/bodies live
in private per-response subdirectories and are removed after each response;
HTML and response header values are never written to evidence records. Only
header-name counts, HTTP status, byte count, connect/TTFB/total times, public
img/source tag hashes/count and ordered data-image-id SHA-256/count survive.
`raw_tags_sha256` hashes concatenated original UTF-8 img/source start-tag bytes
in encounter order, with original whitespace/quotes/entities/line endings and
without inserted separators. Non-UTF-8 HTML fails rather than silently altering
those bytes. `tags_sha256` separately hashes parsed, normalized tag/attribute
JSON records with newline separators; it is not a raw-byte equivalence claim.
Comparing either hash with historical hashes requires the identical recipe.
Any run containing a `same` case requires an explicit
`--session-cookie-name` argument. There is no PHPSESSID default. Use the actual
installation's session cookie name; names accept ASCII alphanumerics and
underscore only (at most 128 characters). The runner reads neither installation
configuration nor browser cookies to infer it.

Each `same` case begins a new isolated session per arm/block with a cookieless
warmup. That response must issue exactly the configured cookie name, matched
case sensitively. Unrelated cookies cannot initialize the cohort. Missing,
malformed, duplicate or conflicting configured cookies fail the run. Tokens are
bounded to 256 ASCII alphanumerics/comma/hyphen characters, the accepted transport
alphabet for PHP session IDs. Measured requests send that configured name with
the server-issued value held only in memory; a repeated identical Set-Cookie or
absence of Set-Cookie preserves continuity. A malformed, duplicated, conflicting
or rotated configured response cookie fails immediately. A `fresh` request sends
no cookie and never adopts any response cookie, including the configured one.

Only nonsensitive transport evidence is persisted: configured name, protocol
`response-initialized-v1`, whether a cookie was sent, initialized-session status
and consistency status. Fresh rows cannot claim session consistency. The private
session value is removed before request evidence serialization. No session value
or session-value hash is emitted. Temporary response headers remain private and
are removed with their per-response temporary directory.

Earlier runner revisions hardcoded PHPSESSID and sent a prechosen token even
when the application used another cookie name. Those `same` labels do not prove
same-session transport and must not be reused as same-session or session-lock
contention evidence. Fresh cohorts did not send cookies and retain their original
meaning. Re-measure affected same-session cases with this explicit initialization
protocol; preserve original results as corrected historical evidence. A tool-only
commit can reuse a prior fresh source measurement only with separately recorded
runtime-source equivalence; its earlier SHA never becomes qualification of a
changed application candidate.

`tests/gallery_http_compare_cookie_test.php` uses the existing central Python
locator/process helper to run the offline Python fixture. It imports the actual
runner and mocks only curl subprocess transport, writing responses into private
temporary directories. It verifies the custom cookie is actually sent, missing
or wrong-name initialization fails, malformed/duplicate/conflicting/rotated
tokens fail, fresh requests never send/adopt cookies, and retained evidence
contains neither value nor token hash. No HTTP/Git process is executed by this
fixture; it is a normal standalone PHP regression, not another test registry.


Five blocks alternate baseline/candidate then candidate/baseline. Every case has
one discarded warmup after each switch and six measured concurrent waves per
arm/block. Each wave launches C1/C2/C4/C8 requests. Consequently there are thirty
wave samples per case/arm and thirty times concurrency response samples. C8 must
issue eight requests in a wave: six total requests per block would never reach
C8. The first wave includes worker startup and curl process overhead; wave wall
time is descriptive, distinct from curl's per-response timings. Useful progress
prints at arm start/completion, case warmup and each completed wave, as well as
block completion. It includes no URLs, cookie values or response content;
request subprocesses have finite timeouts.

`requests.jsonl` is flushed as each response finishes, including UTC timestamps,
exact source SHA, arm, block, case, wave, worker slot, concurrency, session mode
and arm order. `metadata.json` contains source/tree identities, timestamps and
restoration status. `summary.json` retains median, nearest-rank p95 and maximum
for per-response timing and whole-wave completion, with actual sample counts.
At n30 nearest-rank p99 would equal the observed maximum, so no stable p99 tail
claim is made. Markup equality is compared in memory across every response and
both arms; only consistency flags and hashes/counts are retained. A mismatch
must be investigated before treating timing differences as comparable behavior.
Failed runs retain partial observations and failure/restoration state; they
produce no successful summary and cannot be reported as completed comparisons.

Policy defaults are five blocks, six waves, at most sixteen cases, 45-second
request/3-second connect timeouts, 60-second Git operations and 32 MiB maximum
response bodies. These bound an explicitly invoked local experiment; they do
not change application limits or hosted test baselines.

The enclosing implementation batch maintains the corresponding developer
reference in all four TeX manual source editions.
