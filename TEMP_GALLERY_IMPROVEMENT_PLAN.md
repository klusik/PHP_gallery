# Gallery improvement priorities

## Implementation progress - 20 September 2026

Code and tooling for all six retained priorities are integrated, with the final full audit passing without skips. The original success criteria are not all operationally proven: no real off-host backup was supplied, and physical-device or assistive-technology results must not be inferred from automated checks. Additional test scenarios and the replay-protection choice remain explicit below. Existing localhost/Galerie data was not used as a disposable test fixture.

Work log: repository began clean. The original review below is retained for backward searchability. No release version change or Git commit is requested.

### Implementation ledger

| Priority | Implemented | Verification / remaining work |
| --- | --- | --- |
| 1. Recovery | CLI recovery-set inventory, isolated-file validation, original/manifest hashes, explicit operator evidence, synthetic corruption/omission drill, off-host runbook. | 67 focused assertions passed; central recovery contracts pass. Real provider/off-host restore, agreed recovery-time/data-loss targets, restored DB/login/access/Trash checks remain pending. The tool cannot certify provider snapshots or enforce network isolation. |
| 2. Real workflows | Disposable generated MySQL database/application fixture; real HTTP persistence, upload retry, access/CSRF/session/Trash checks; real browser create, multipart upload, edit, visibility, Trash restore, stale editor-response and expired-session journey; MySQL/MariaDB CI job; central-audit registration. | Final central full audit passes with real MySQL/Chromium workflows and the existing database concurrency test enabled; zero skips. Generated databases, application copies and private MySQL directories were cleaned. Hosted CI has not run. Prepared-worker browser uploads, reordered POST responses and physical disconnect injection remain additional coverage gaps; see the permanent guide. |
| 3. Completion correctness | Grapheme-safe NFKC suffix mapping; conservative old-browser fallback; IME/modifier/selection guards; two-stage Escape; accessible help and live announcement in all four maintained languages; delegated replacement-form handling. | Actual Chromium DOM-event fixture passed during development. Human screen-reader/platform-IME review remains pending; synthetic events are not proof of assistive-technology behavior. |
| 4. Completion scale | Admin-only no-store JSON endpoint; strict MVC; no embedded title catalog; at most eight results, 1,024 normalized titles, three page queries, 16 KiB JSON; debounce/cancellation/stale suppression. | Real model/service/controller fixtures at 100/1,000/10,000 rows pass. SQLite synthetic common-prefix response at 10,000 rows: 749 bytes / 2.057 ms median, versus 1,957,789 bytes in the reconstructed old escaped catalog. Complete page/phone latency and production MySQL plans are not measured. Bounded scans deliberately omit old matches; SQL LIMIT does not bound engine scan/sort time. |
| 5. Viewer lifecycle | One nearby-preview queue owner, explicit reset/disposal, shared cancellation and bounded concurrency; existing foreground/media/zoom owners preserved; complete import cache-revision chain updated. | 12 runtime cases pass, including repeated teardown/reopen and both renderer labels. Combined compressed payload grows slightly; no startup speedup is claimed. Physical phone/desktop profiling remains pending; no speculative lazy loading added. |
| 6. Release evidence | Artifact-bound human review ledger, PDF page-render command, exact before/after audit fingerprints, stale approval rejection, pending/failed/manual states separate from automated PASS, compact release handoff. | Isolated contracts pass and preview rendering works. No actual PDF visual approval, release audit, publication, tag, or commit is claimed for this implementation task. |

### Permanent implementation references

- Recovery: [CLI/evidence contract](docs/RECOVERY_ASSURANCE.md), [off-host procedure](docs/RECOVERY_OFF_HOST.md).
- Isolated workflow/CI setup: [GALLERY_WORKFLOWS.md](docs/GALLERY_WORKFLOWS.md).
- Completion budgets, normalization and measurements: [TITLE_COMPLETION.md](docs/TITLE_COMPLETION.md).
- Viewer ownership and honest compression accounting: [BROWSER_LIFECYCLE.md](docs/BROWSER_LIFECYCLE.md).
- Qualification commands and artifact invalidation: [RELEASE_QUALIFICATION.md](docs/RELEASE_QUALIFICATION.md), [RELEASE.md](RELEASE.md).

### Verification history

- Read-only local checks: localhost/Galerie returned HTTP 200; anonymous title-completion endpoint returned JSON 401 with private/no-store caching. No live content mutation was used as a test.
- Initial central quick run (`20260919-223451-22492`): integration failed on stale import-version assertions and missing source documentation. A verbose Node assertion also exposed a Windows pipe deadlock; only that owned stalled child was stopped. The runner now uses temporary file-backed output streams and tests large dual-stream output plus hard timeouts.
- Second quick run (`20260919-224402-45624`): 187 PHP passed / one header failure / three environment skips; 17 Node and 36 WinApp tests passed; MVC, mutation contracts and syntax passed. The remaining size-helper header was corrected. Skips were the two opt-in disposable workflow tests and the existing database concurrency test.
- First isolated full audit (`20260919-230210-48240`): PASS, 191 PHP / 18 Node / 36 WinApp / two standalone Chromium fixtures, zero skips; real workflow and database-concurrency coverage ran. The disposable database, application copy and private MySQL directory were cleaned. Final markup review then added an explicit translated input label so the nested live region cannot change the field's accessible name.
- Final isolated full audit (`20260919-230636-38872`): PASS after that correction, all nine suites, 191 PHP / 18 Node / 36 WinApp / two standalone Chromium fixtures, zero skips or failures. PHP syntax: 771 files; JavaScript syntax: 90 files. MVC: zero strict violations, with 27 existing advisory review candidates. Duration 145.34 seconds. [Immutable final report](cache/test-audit/20260919-230636-38872/report.md).
- Final managed source changes were followed by manifest generation and `--check`: current version 0.104, 672 managed files. No release metadata, Git staging, commit, tag, push or publication was performed. Existing site configuration, media and database were not modified.
- The final fixture owner reported successful cleanup of the generated database/application copy and shutdown/removal of the private MySQL instance. These were synthetic, regenerable test data only.
- Initialized actual 0.104 qualification evidence for source fingerprint `bb1716a7958381b559933784e28d0d31674fe746ecada988f7d4e031a9bb5b9d`. [Local record](cache/release-qualification/0.104/bb1716a7958381b559933784e28d0d31674fe746ecada988f7d4e031a9bb5b9d/record.json). Qualification correctly remains INCOMPLETE: all human checks are pending and this implementation's full audit is not substituted for a release audit.

- Browser CPU measurement: the same newest ASCII title was selected at all fixture sizes. In Chromium 153, the old 10,000-title matcher took 0.7914 ms per lookup versus 0.0020 ms with eight returned candidates. This excludes rendering, debounce and HTTP latency. See the permanent completion guide and ignored `cache/benchmarks/title-completion-browser.json`.

### Follow-up choice

Creation and classic multipart upload have no general server-side replay key.
Browser double-click suppression is covered; prepared ZIP batch retry is covered.
Replaying an already completed create POST can still create a suffixed copy.
The user has been asked whether to add persistent server-side retry protection
or preserve that established behavior. No new replay-storage policy has been
silently introduced as part of the test work.

## Original source review (retained for history)

Prepared 20 September 2026. This is a source-based review of the current 0.104 repository, not a live production security audit or a measured performance assessment. No application changes are proposed as already implemented.

The latest existing release audit passed all 12 suites, with one optional database concurrency test skipped. That is useful evidence, but does not establish production restore readiness, accessibility, or complete browser workflow correctness. Priorities below weigh potential harm first, then directly observed defects, scale, and maintenance cost. Effort estimates are rough engineering estimates, not commitments.

**How to review:** delete any complete `## Priority ...` section you do not want. Every section contains its own evidence, implementation steps, and acceptance criteria; remaining sections can be implemented independently. Retaining a section expresses interest, not permission to change production data. There is deliberately no duplicate checklist at the bottom to reconcile after deletion.

## Priority 1 - Prove complete recovery of photographs and database state

**Why first:** Losing photographs, metadata, or access settings has a larger impact than a slow screen. The product coordinates filesystem content and relational state; a backup of only one side is insufficient.

**Evidence and confidence:** The manual's Backup and recovery checklist and updater sections explicitly say updater rollback restores application files but does not reverse database migrations. They also require coordinated backups of database, galleries, installation assets, Trash, and log archives. `RELEASE.md` requires post-update smoke checks when practical. These establish the recovery requirements, not evidence that your actual backups are missing or broken. No production backup inventory or restore result was supplied.

**Implementation steps:**

1. Inventory the existing hosting backup process first. Reuse it if it already provides coordinated, off-host backups and successful restore evidence; avoid building a competing backup subsystem.
2. Define one recovery set: database, originals, installation configuration/secrets, relevant `data/` storage, custom assets, and exact application version. Document which derivatives are safely regenerable and the time/cost of regeneration.
3. Establish a consistent snapshot method for writes spanning database and files. Use provider snapshots or a bounded maintenance window where necessary; copying a changing folder tree and dumping SQL independently is not proof of consistency.
4. Add a repeatable restore drill into an isolated installation. Prevent restored email, scheduled jobs, integrations, and uploads from contacting production destinations.
5. Verify image counts and sampled original hashes, gallery relationships, private-gallery denial, login, Trash restoration, and current migrations after restore. Record recovery duration and backup age without exposing secrets.
6. Document update rollback decisions: when file rollback is compatible with the migrated schema and when the complete recovery set must be restored. Start with CLI/operator documentation; add Admin status only if reliable backup evidence can be obtained.

**Success criteria:** Restore one representative installation from the off-host recovery set, prove protected content remains protected, and meet explicitly agreed recovery-time and maximum-data-loss targets. A successful file copy or checksum check alone does not pass.

**Scope and effort:** Medium, approximately 3-6 engineering days once the hosting backup mechanism is known. This is recovery assurance, not a request to implement automatic destructive restore in Admin.

## Priority 2 - Exercise critical workflows in a real browser and database

**Why now:** The code has substantial structural coverage, but users experience interactions across HTTP, JavaScript, persistence, and fragment replacement. Passing source contracts cannot prove those interactions work together.

**Evidence and confidence:** `scripts/audit_registry.php` registers browser map integration, not a full authenticated gallery CRUD journey. `TESTING.md` explicitly limits that browser fixture to synthetic photographs and controlled metadata rather than live database/media authorization. `tests/admin_gallery_title_completion_test.mjs` checks matching functions, but keyboard and pointer coverage uses `browserSource.includes(...)`. The existing audit report skips `viewer_phase07_mysql_concurrency_test.php` because `GALLERY_TEST_MYSQL_DSN` is absent. These are confirmed coverage gaps, not proof of faulty authentication.

**Implementation steps:**

1. Provide a disposable, seeded MySQL/MariaDB installation with a minimal gallery tree, protected gallery, admin account, and a few generated test images. Isolate credentials and test data from the real installation.
2. Extend the existing Chromium fixture infrastructure with actual create-gallery, upload, edit, visibility change, and recoverable-delete journeys. Register coverage in the central audit, keeping its compact reporting.
3. Assert database/file postconditions as well as UI results. For every panel mutation assert unchanged URL, open panel, refreshed content, and working controls after fragment replacement.
4. Add failure scenarios for session expiry, invalid CSRF, delayed/reordered responses, and retry after a response is lost. Verify creation/upload is not duplicated and protected content is never exposed.
5. Run the existing real-database concurrency suite in an isolated CI job. Clearly distinguish mandatory CI coverage from unavailable optional developer coverage; never point it at production.
6. Keep source contracts that protect architectural boundaries, but use runtime assertions for claims about keyboard events, focus, submission, or network behavior.

**Success criteria:** The registered tests catch deliberately introduced broken delegation, duplicate submission, and stale-response bugs; the isolated database job runs without the current DSN skip. Release reports distinguish synthetic browser coverage from full-stack coverage.

**Scope and effort:** Medium/large, approximately 4-8 days for a small high-value baseline. Avoid attempting to automate every Admin control initially.

## Priority 3 - Correct title-completion keyboard, Unicode, and accessibility behavior

**Why now:** This is a concrete issue in the newest feature and a relatively small repair. Suggestions should not surprise keyboard users or become incorrect for international titles.

**Evidence and confidence:** `admin-gallery-title-completion.js` normalizes matching text with NFKC but builds the displayed tail using `suggestion.slice(input.value.length)`. Normalization can change length: a stored title beginning with the single ligature character U+FB00 matches typed `ff`, but slicing the raw title by two characters removes part of the actual suffix. The keydown handler has no composition guard and accepts ArrowRight regardless of Ctrl/Alt/Meta. `app/views/admin_gallery_forms.php` marks the suggestion overlay `aria-hidden=true` without an accessible suggestion alternative. These are source-confirmed design issues; browser and assistive-technology impact still needs reproduction.

**Implementation steps:**

1. Add focused cases for decomposed accents, compatibility ligatures, emoji, exact matches, selected text, mid-input caret, IME composition, and modified navigation keys.
2. Keep normalized matching separate from visible text geometry. Map normalized prefix boundaries back to original text, or decline a ghost-text match when a safe boundary cannot be established. Never slice raw strings using an unrelated normalized length.
3. Suspend completion acceptance during composition and preserve browser/platform modifier shortcuts. Define exactly when Tab accepts, when it moves focus, and how Escape interacts with the surrounding drawer.
4. Provide an accessible description and suggestion announcement, or a properly implemented autocomplete interaction. Add maintained-language text through the existing translation system. Avoid announcing every keystroke noisily.
5. Verify real key and pointer events on dynamically inserted forms; confirm accepting a suggestion changes only the title field and does not submit or close the panel. Update the owning module cache revision.

**Success criteria:** The ligature and decomposed-accent examples produce a correct suffix or no suggestion; composition and modified arrows are unaffected; keyboard and screen-reader users can discover, accept, and dismiss suggestions. A newly replaced panel control still works.

**Scope and effort:** Small/medium, approximately 1-3 days plus assistive-technology verification. Preserve ordinary no-JavaScript creation and the existing mutation envelope.

## Priority 4 - Bound gallery-title suggestion data and computation

**Why now:** The title field currently scales with the total gallery catalog rather than the small number of suggestions it displays. This is an avoidable cost on shared hosting and large archives.

**Evidence and confidence:** `gallery_model_title_completion_rows()` in `app/models/galleries.php` selects all gallery rows without a limit and calls `fetchAll()`. `gallery_title_completion_candidates()` includes folder path and creation date; `admin_gallery_forms.php` embeds the catalog as JSON in the control attribute. `findGalleryTitleCompletion()` scans candidates and sorts matches on input. The browser does not use the candidate `path` field. The mechanism is confirmed; actual latency and memory impact have not been measured.

**Implementation steps:**

1. Measure form response bytes, query time, PHP peak memory, and typing cost with synthetic 100, 1,000, and 10,000-gallery datasets. Include full-page and dynamically fetched forms.
2. Remove unused presentation fields and select only data required for ranking/display. Avoid exposing folder paths where the UI does not use them.
3. Choose a bounded design from the measurements: a small initial sibling candidate set plus an authenticated search endpoint when needed, or a bounded indexed in-memory strategy for installations where that is enough.
4. Keep Controller -> Service -> Model ownership. Use semantic prefix/parent inputs, parameterized queries, result limits, request cancellation, and stale-response suppression. Reproduce the existing sibling/newest/fallback ranking deliberately.
5. Define normalization behavior consistently between client and database. Inspect query plans before adding any index; add a new timestamped migration only if an index is justified.

**Success criteria:** No unbounded catalog is embedded in every create form; response size and browser work remain within documented budgets at 10,000 galleries; sibling priority and fallback still work; endpoint access requires Admin authentication. Establish numerical budgets from the baseline rather than claiming an unmeasured speedup.

**Scope and effort:** Medium, approximately 2-4 days. This can ship independently of the keyboard/Unicode repair.

## Priority 5 - Reduce browser lifecycle coupling and measure loading cost

**Why it matters:** A small change to a very large viewer or drawer module can affect unrelated navigation, cleanup, or quality state. Clear ownership lowers regression risk, while loading only needed features can improve Admin startup.

**Evidence and confidence:** The reviewed `lightbox.js` is approximately 361 KB of source and `admin-side-panel.js` approximately 135 KB. `public/assets/gallery.js` has 34 static imports and invokes many setup functions. These are source sizes, not compressed transfer sizes or proof of poor live performance. The 0.103.1 history documents actual navigation, cache, and stale-request corrections in the viewer.

**Implementation steps:**

1. Measure compressed transfer, parse/evaluation time, and module initialization on a representative phone and desktop. Compare anonymous gallery, logged-in gallery, and Admin pages before proposing lazy loading.
2. Extract one high-coupling responsibility at a time behind explicit lifecycle APIs: for example viewer request ownership, decoded-cache management, or drawer mounting/disposal. Keep a single state authority rather than duplicating request generations across modules.
3. Document initialization, event registration, cancellation, and disposal contracts. Add runtime teardown/reopen and fragment-replacement tests at each seam.
4. Lazy-load only measured expensive optional workflows, preserving dynamic panel boot, error reporting, and cache-busting import chains. Do not replace the framework-free architecture or introduce a build dependency solely for this work.
5. Protect both permanent thumbnail renderers, authorized media, synchronous active-original zoom promotion, fullscreen hit testing, and existing no-JavaScript behavior.

**Success criteria:** Extracted components have clear lifecycle owners and pass existing central contracts plus meaningful runtime checks; repeated open/close/refresh does not accumulate handlers or requests. Claim startup improvement only with before/after measurements. Smaller files alone are not the acceptance criterion.

**Scope and effort:** Large if applied broadly; start with one 3-5 day slice. Do not undertake a whole-viewer rewrite.

## Priority 6 - Make release qualification expose outstanding manual checks

**Why it matters:** An automated PASS is easy to mistake for complete release readiness. The prior release preparations still had outstanding PDF visual inspection despite successful compiler and consistency checks.

**Evidence and confidence:** `RELEASE.md` requires visual PDF inspection and post-publication smoke checks, and distinguishes skipped environment coverage. The previous 0.103.1 and 0.104 preparation handoffs explicitly reported blocked visual inspection. The current audit summary records automated suites and the database skip; it does not constitute evidence those manual actions happened.

**Implementation steps:**

1. Add a small release qualification record associated with the intended version and exact source revision/content fingerprint. Keep human review state separate from automated PASS.
2. Record PDF title, contents, index, changed-page visual checks, and applicable browser smoke results as pending/passed/failed with concise evidence. Do not mark them passed from PDF text extraction alone.
3. Provide a repeatable render command writing page previews to ignored cache storage so visual review is easy on another workstation when local tools fail.
4. Make the release handoff summarize unresolved manual checks and environment skips. Invalidate previous sign-off when relevant artifacts change. Keep publication and Git actions explicitly separate from qualification.
5. Preserve the central audit as the only automated orchestration interface; extend its reporting or the existing release tooling instead of creating a second test runner.

**Success criteria:** A maintainer can see exactly which checks ran, which were skipped, and which still require review for a specific artifact. Rebuilding the manual cannot silently reuse old visual approval. Existing release automation stays deterministic.

**Scope and effort:** Small/medium, approximately 1-2 days. No new product setting or public interface is required.
