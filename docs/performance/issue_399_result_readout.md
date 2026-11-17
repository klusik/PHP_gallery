<!--
Project: PHP Gallery
Repository: https://github.com/klusik/PHP_gallery
File: docs/performance/issue_399_result_readout.md
Module Type: Immutable Benchmark Result Readout Note
Purpose: Explain the isolated hosted readout of the qualified Canvas experiment.
Responsibilities:
- Preserve the measured source and its immutable artifact identity.
- Distinguish data inspection from candidate qualification.
Author: Rudolf Klusal
License: MIT License (see LICENSE file in repository)
-->

# Issue 399 stored result readout

The measured historical Canvas candidate is
`5e9a050df977dae9423096e4df77864c625b8441`, compared with optimized control
`ab245719ddf65ccba51a83ac10564a795a03dee9`. Its [comparison run](https://github.com/klusik/PHP_gallery/actions/runs/44052468689/attempts/2)
passed all 120 scheduled children, ten balanced process pairs per main cell.
[Artifact 14317966343](https://github.com/klusik/PHP_gallery/actions/runs/44052468689/artifacts/14317966343)
retains raw data, precomputed statistics, Czech Markdown and PNG/SVG.
Its ZIP SHA-256 is
`6d4791dd65bac19b8369972c81882b4637df6c9b031525aa8c3a4060711ea102`.

GitHub rejected the combined Markdown plus JSON step summary at its display
size limit. This did not remove the immutable artifact or change the completed
audit result. The isolated `feature/399-canvas-result-readout` branch reuses the
existing authenticated Actions artifact reader to expose the stored Markdown.
It runs no application, test, benchmark, bootstrap or plot generation. The
reader branch starts from verified develop at the control SHA and is not the
measured candidate. Its diagnostic-only status never qualifies that branch.
That SHA identifies the measured historical candidate; later renderer commits require their own qualification.

The central audit passed all 120 scheduled processes, but the stored
`scroll100/cold` cell is a **NO-GO for general/default Canvas adoption**.
Reported A/B values are means of per-process p95 observations; the intervals
below are the existing paired bootstrap 95% confidence bounds for B − A
(`n=10` matched process pairs):

| Metric | Optimized control A | Canvas candidate B | Existing paired 95% CI for B − A |
| --- | ---: | ---: | ---: |
| `wheel_slow_settled_p95_ms` | 46.855 ms | 78.149 ms | [22.277, 40.705] ms |
| `wheel_fast_settled_p95_ms` | 26.608 ms | 43.017 ms | [11.994, 22.815] ms |

Both regressions exceed the 10 ms and 20% G4 adoption guard
([issue #399 result details](https://github.com/klusik/PHP_gallery/issues/399#issuecomment-6945325780)).
The native renderer remains the default; Canvas remains opt-in. No physical
display presentation or accessibility acceptance is claimed. The original
failed and partial cohorts remain separately invalid.

The permanent issue 399 profile remains stage `compare` against optimized
control A, with 100/500/2000-photo extents, cold/warm cache states, ten paired
processes per cell, and 120 scheduled child processes. The one-artifact readout selection
and `diagnostics_only` setting are not retained in the permanent protocol. The
readout workflow deliberately ends as `NOT TESTED` for its current candidate.

No protected branch write, merge, squash, tag, release or local execution.
No TeX manual impact: internal retrieval of existing hosted evidence only,
with unchanged product behavior and documentation commands.
