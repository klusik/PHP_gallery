# Prepared OCI CI environment experiment: archived

**Status: CLOSED / NOT ADOPTED** (2026-10-16). See
[GitHub issue #232](https://github.com/klusik/PHP_gallery/issues/232) and
[RESULTS.json](RESULTS.json) for the source-bound evidence, immutable
identities, complete A/B table, failure diagnostics and costs.

## Why this is not the requested persistent environment

The requested operation was to prepare all CI tools once and keep them locally
available for subsequent clean test runs without reinstalling tools or
downloading a large image again.

The experiment used a GHCR OCI image with tools installed at **build time**,
then a fresh standard GitHub-hosted machine in each job. The fresh machines
still pulled image layers, typically costing tens of seconds per job, sometimes
alongside fresh DB service image pulls. The approach was measurably **slower
end-to-end** than the currently installed, standard GitHub Free CI.

The six ordered paired runs (three A/B pairs) kept application T
`4d373023b96554364e295fb76d931ff3535f7c5a`, immutable B
`e4abeb386354e7254a9083dbae5e16e8131ee314`, and all four image digests fixed.
The proposed environment preserved the 28 required FULL owners and a separately
measured four-manual build.

| Pair | Baseline A overall / runner seconds | Prepared B overall / runner seconds | Result |
| --- | ---: | ---: | --- |
| 1 | 181 / 1404 | 222 / 1759 | Both PASS, B +41 s |
| 2 | 188 / 1387 | 209 / 1794 | B FAIL in 1 of 34 required Chromium scenarios; B +21 s |
| 3 | 167 / 1386 | 215 / 1794 | Both PASS, B +48 s |

Median end-to-end regression B-A: **+41 seconds**. Median additional runner
time: **+407 seconds**. One-time verified hosted OCI build: **257 seconds**
(Actions #38817076697). The four locked EN/CS/DE/SV manuals built successfully
in both arms; the prepared toolchain shortened the isolated manual job on these
cache-miss baselines. A separate browser test failed in B2; the failure and
original logs remain preserved. These three pairs are insufficient for reliable
p95 or flakiness claims.

## Code retention and safety

- `archived-workflows/` contains the original experimental workflow definitions
  as inert reference files. **No workflows from this experiment remain under
  `.github/workflows/`** after archival, so they cannot auto-run on future
  feature pushes, be required by the existing CI, or publish packages.
- `config.env` is explicitly set to `MODE=archived`. It is not an operational
  workflow switch.
- `Dockerfile`, database Dockerfile, test adapter, pinned `image-lock.json`,
  provenance-aware TinyTeX verification and scoped Chromium seccomp policy are
  retained for analysis only. They are not production release inputs.
- No installed CI workflow, audit entrypoint, test, registry, branch-protection
  setting, release script, release asset or protected ref is modified by the
  experiment source itself.
- No tool packages are installed in a regular PHP Gallery runtime because the
  archived files are never executed during application requests.
- Reactivation requires a separately reviewed maintainer decision, **reinstating
  and updating** archived workflow definitions and validating their required
  authority boundary before any hosted run. The frozen versions/SHAs are
  historical, not a rolling reusable environment.

**No TeX manual impact:** no supported CI execution contract, application
behavior, deployment model, developer command or release behavior changes. This
repository path is evidence-only. The existing manuals continue to describe the
installed CI. Any future CI infrastructure adoption must update its permanent
Markdown and all four TeX edition sources during its own change.

## Follow-up

Optimize the actual native GitHub Actions CI and the already existing locked
TinyTeX cache under [#217](https://github.com/klusik/PHP_gallery/issues/217). Do
not adopt the GHCR image path as the default CI. Paid organizational VM custom
images and persistent self-hosted runners need separate infrastructure approval
and are not part of this archived experiment.
