# Compact Admin test-run listing metadata

The existing Admin test-run storage owner publishes `report-summary.json` in
each existing `cache/admin-test-runs/<token>` directory after atomically replacing
`report.json`. The summary contains only administrator ID, created/finalized
strings, target page/request target, completed request count, peak concurrency
and clean-completion status. Browser traces, request bodies and client data are
excluded. The cache tree is already ignored and excluded from production packages.
No database, route, UI control or additional runtime module is introduced.

Listing reads at most 65,537 bytes per summary (64 KiB plus an overflow sentinel),
checks its structural version, exact projected field types and report stat
identity, and verifies that identity again before accepting it. New report writes
invalidate the prior sidecar before publishing the report, preventing stale
same-size/same-second projections through the supported writer. Summary publication
failure leaves the authoritative report intact and uses compatibility reads.
A per-report publication lock serializes writers and summary readers, including
same-second/same-size replacement on Windows. Legacy reads without that producer
lock use the full report without creating files. The lock stays in the existing
run tree and retention removes it together with the report.
Stat identity is a consistency fence, not a cryptographic integrity claim against
external writers preserving size/timestamps/inode. Application-owned cache files
must retain their existing filesystem access protection. External changes outside
the supported writer must remove the sidecar. No listing field authorizes export;
downloads retain their existing authenticated authoritative-report checks.

Missing, malformed, oversized, stale or unavailable summaries fall back to the
full historical report. This permanent compatibility path preserves retained
runs without read-time migration or silent omission. Its owner is the Admin
test-run storage service. Deployment prevalence and production savings are
unknown. Retirement requires evidence that every retained run was migrated or
expired and explicit owner approval; no release number establishes that proof.
Legacy-heavy installations therefore retain the previous full-read cost.

Sorting remains finalized-at descending with existing equal-time scan ordering;
filtering still uses the current administrator before the requested limit.
Latest-for-target still searches the same newest twenty administrator-owned runs.
ZIP availability is checked from the actual file. Full JSON bytes, downloads and
ZIP contents are unchanged. Retention accounts for sidecar bytes and deletes
them with their existing run tree.

Verification belongs to the hosted test-run fixture and exact-candidate CI.
No TeX manual impact: this is an internal representation optimization; the
existing diagnostics controls, download workflow and retention limits remain
unchanged. Rendered UI behavior is owned by the enclosing task's verification.
