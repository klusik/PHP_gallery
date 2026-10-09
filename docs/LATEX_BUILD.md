# Building the PHP Gallery manual

During ordinary development, treat all four TeX manual sources as living documentation. When source changes affect public or administrator behavior, architecture, security, deployment, runtime dependencies, CI qualification, or developer/maintainer workflows, update the corresponding current-behavior sections in all four languages as part of the same source candidate. Review that impact before final candidate preparation, even for internal-only tooling fixes. If no TeX content is affected, record a brief reason in the issue or handoff. Do not rely on the release workflow to compose documentation: it updates edition metadata and builds existing sources, but does not write technical prose. Do not alter `PATCH_NOTES.md` for routine development. Do not compile PDFs after ordinary code or documentation edits; tracked PDFs may lag until final release preparation, and a local TeX installation is not required.

The GitHub-hosted release workflow compiles all four manuals together after the final release source/metadata edits and before the final manifest and exact-candidate audit. It uses the locked TinyTeX/TeX Live toolchain documented in `RELEASE.md`.

For an explicitly requested local build or diagnosis of a concrete compiler/layout failure, the manual also supports a standard MiKTeX or TeX Live installation with `pdflatex` and `makeindex`. Run the commands from the `docs/` directory:

```text
pdflatex PHP_Gallery_Manual.tex
makeindex PHP_Gallery_Manual.idx
pdflatex PHP_Gallery_Manual.tex
pdflatex PHP_Gallery_Manual.tex
```

The first pass writes cross-reference and index data. `makeindex` creates the sorted keyword index. The final two passes resolve the table of contents, citations, index page numbers, PDF bookmarks, and internal links.

The expected deliverable is `PHP_Gallery_Manual.pdf`. LaTeX intermediate files are excluded by the opt-in policy in `.gitignore`.

The document uses A4 paper and the `article` class. It requires common LaTeX packages supplied by normal MiKTeX and TeX Live installations. Package installation prompts may appear during the first compilation on a minimal MiKTeX setup.

Update `\version` and `\manualdate` once near the beginning of `PHP_Gallery_Manual.tex` when preparing a new edition. The title page, footer, introductory text, and references reuse those commands. Review references, index entries, screenshots, command examples, and implementation descriptions against the local project before publishing a rebuilt PDF. Keep the manual as permanent product documentation: do not add a version-by-version “What is new” or patch-note section at the beginning. Release history belongs in `PATCH_NOTES.md`; a manual appendix is the only appropriate place for historical material if it is deliberately required later.

## Release-documentation workflow

`RELEASE.md` is authoritative for the complete release sequence. This section documents only the manual-specific portion and must not be interpreted as a second release checklist. `scripts/prepare_release.php <version>` updates `\version` and `\manualdate` mechanically; maintainers still review all four manual sources before the final release batch build.

For a release, start from a clean release branch and compare the working tree with the exact previous release tag before editing the manual. Review every intervening commit and changed path, especially migrations, browser entrypoints/cache keys, translations, generated files, and packaging policy. Update the runtime version in `app/bootstrap.php`, the release entry and `v_<version>` tag in `release-metadata.json`, and the newest `PATCH_NOTES.md` entry before changing the edition metadata above. Review the release-specific sections of `README.md`, `ARCHITECTURE.md`, `DATABASE.md`, `TESTING.md`, `CODEMAP.md`, and `docs/ADMIN_SETTINGS_INVENTORY.md` for stale behavior descriptions.

At final release preparation, build all four PDFs together on GitHub; when a local build is explicitly requested, repeat the four commands above for every basename from `docs/`. Check compiler success and final reference/index/layout warnings. Do not routinely render or read the generated PDFs; visual review is limited to an explicit request or a concrete layout failure. Confirm that the opening guide flows from the purpose/reading guide directly into “How to use this manual” and contains no release-news block. Intermediate `.aux`, `.idx`, `.ilg`, `.log`, and related files are disposable and remain ignored; `docs/PHP_Gallery_Manual.pdf` is the tracked English release artifact.

The manual build does not refresh application integrity data. Hosted candidate preparation regenerates and checks the manifest after ordinary source edits; final GitHub-hosted release preparation rebuilds all four PDFs and then regenerates the final manifest before exact-SHA qualification. Local `generate_manifest.php` and `--check` are reserved for explicitly requested offline recovery/diagnosis. A clean generated-state check is required before a deployment ZIP or release publication, but a local PASS is not a replacement for hosted qualification. Inspect the final archive listing and confirm the manual PDF, migrations, patch notes, release metadata, and manifest are present. The `CMS_VERSION`, patch-note heading, release-metadata key/tag, PDF edition, archive/release name, and annotated Git tag must all agree before publishing. After publication, smoke-test an updater upgrade from the previous stable tag and verify migrations, Admin login, public rendering, and the integrity page.

## Translated editions

Czech, German and Swedish editions use `PHP_Gallery_Manual_CZ`,
`PHP_Gallery_Manual_DE` and `PHP_Gallery_Manual_SV` as their source/PDF basenames.
Run the same four-pass sequence for each basename, including `makeindex` on its
own `.idx` file. Update each translated source's `\version` and localized
`\manualdate` when preparing a release: `prepare_release.php` updates the
English edition automatically, while translated edition markers require review.
Compile all four PDFs in the final release batch and check compiler diagnostics before the final manifest and release audit. Source edits during development do not trigger this build.
