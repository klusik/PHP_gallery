# Production file policy and packaging

`app/production-files.json` is the reviewed, sorted, exact positive inventory for production packaging, integrity ownership and updater activation. A file ships because its relative path is listed. Directory discovery and local ignore rules cannot admit extra application files. `app/release_file_policy.php` owns path, membership and updater policy; `scripts/package_production.php` is the single portable PHP implementation of copying, ZIP creation and content verification. Historical shell/PowerShell deploy entrypoints, interactive menus and FTP credential/upload code have been removed.

## Preparing reviewed inputs

Stage new/deleted source membership and push the authorized working branch. Hosted candidate preparation generates runtime modules, production inventory and the core manifest in that order, then checks freshness for the exact candidate. It uses Git-index membership and approved source surfaces. Packaging does not regenerate inventory, repair metadata or discover additional source files.

Local `php scripts/prepare_candidate.php` and its `--check` are exceptional maintainer recovery commands. The package builder itself works on a validated extracted source package without Git metadata.

Runtime-plan freshness accepts Git's CRLF checkout transformation only; bare CR,
content, schema, whitespace and truncation differences remain failures. The
compiler emits canonical LF. This presentation normalization never substitutes
for the package builder's exact source-byte SHA-256 verification.

## Creating an explicitly requested artifact

```text
php scripts/package_production.php --root=<source> --directory=<new-directory> --zip=<new-archive.zip> --timestamp=<candidate-epoch>
```

Supply either output or both. Use PHP 8.1+, ZipArchive for ZIP output and mbstring for Unicode path validation. The release pipeline passes the immutable candidate commit epoch; use the same epoch for reproducible metadata. The builder reports actual copy, archive and verification timings and complete archive SHA-256. It preserves file permissions and rejects unsafe output paths and existing artifacts.

Before publication it verifies the current core manifest against source bytes, copies only explicit inventory paths into private staging, and verifies exact staged and archive membership and SHA-256 of every selected file. The complete-byte comparison also covers companion binaries and PDFs outside the core manifest's historical hashing scope. Corrupt, missing or stale inputs fail closed. Symlinks and redirected ancestors are refused; Windows checks reparse points through the native platform attribute reader in one bounded batch. Temporary work is removed on failure and existing outputs are never overwritten. No external compression executable or per-file copy process is required.

`config.php`, active custom CSS, live gallery media, cache/log/tmp payloads, persisted data, agent folders, IDE metadata and build products are outside production membership. Only explicitly listed directory protection files ship from writable trees, including `deploy/.htaccess`. Empty runtime directories remain the responsibility of the existing setup/storage owners. There is no media, source-review, FTP or interactive mode in the production builder.

## Inventory and updater compatibility

Production packaging uses the sorted union of `production_files`,
`companion_files` and optional `companion_resource_files`. Companion resources
currently admit only the reviewed DejaVu font and its license. All lists are
disjoint; neither companion field grants CMS updater ownership. WinApp private
settings, logs, caches, tests, unlisted modules and build/dist outputs are
excluded. Installers retain their independent build and version ownership.

`updater_files` is a sorted subset of `production_files`. Installed custom CSS presets remain protected. The schema-1 `source_review_files` field remains for installed-reader compatibility; it does not enable source-review packaging. The additive companion field remains readable by the frozen pre-WinApp reader, which ignores it before installing the new reader. Malformed present fields fail closed; no incoming archive PHP is executed to obtain a newer validator.

Keep the serialized `companion_files` vocabulary readable by installed
companion-aware validators. Newly approved resources belong in the separate
optional field; current readers validate its exact paths, ordering, collisions
and non-updater scope, while older readers ignore it. Current readers also retain
the previously published shape with resources in `companion_files`. A present
invalid resource field never behaves as an absent one. The compatibility fixture
reproduces the reported resource rejection with trusted installed-reader code;
the fix adds no runtime version table and does not execute incoming PHP.

The updater supports direct upgrades using a complete newer package and all
pending migrations, rather than requiring every intermediate release. This
inventory compatibility proof does not qualify every historical database or
hosting environment. A failed pre-activation job can be cancelled before
selecting a corrected newer release; retrying a pinned incompatible package
cannot repair its inventory. Published releases remain immutable.

Historical deploy path spellings remain parseable where necessary to read previous installed ownership evidence. They do not ship in new inventories. Updater-owned retired paths are removed only through the existing prior `updater_files`/core-manifest ownership proof and protected rollback path. Root `deploy.sh` was not updater-owned, so its name alone cannot authorize deletion. Unknown/user files are preserved. See [the compatibility register](COMPATIBILITY_LIFECYCLE.md).

The updater may receive a complete Git source archive containing development files. Only its canonical updater subset is activated. Older stable archives without the sidecar retain the bounded manifest-based compatibility path; a malformed present inventory cannot fall back. Invalid prior ownership yields an empty deletion set. Partial rollback snapshots use their server-written activation/obsolete index, preserving configuration, runtime data and media.

## Inspection and required proof

```text
php scripts/release_files.php list --root=<source> --profile=production --format=json
php scripts/release_files.php verify --root=<stage> --source-root=<source> --profile=production
```

These bridge commands inspect paths. A membership PASS alone is not content integrity proof. The builder's real directory/ZIP validation owns that proof. Fast policy tests, a compact real-builder safety fixture and complete positive production packages on Ubuntu, Windows and macOS have distinct central audit owners. SQL-independent packaging is not replayed in each database profile.
