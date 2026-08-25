# Building the PHP Gallery manual

The manual is designed for a standard MiKTeX or TeX Live installation and uses `pdflatex` with `makeindex`. Run the commands from the `docs/` directory:

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

For a release, start from a clean release branch and compare the working tree with the exact previous release tag before editing the manual. Review every intervening commit and changed path, especially migrations, browser entrypoints/cache keys, translations, generated files, and packaging policy. Update the runtime version in `app/bootstrap.php`, the release entry and `v_<version>` tag in `release-metadata.json`, and the newest `PATCH_NOTES.md` entry before changing the edition metadata above. Review the release-specific sections of `README.md`, `ARCHITECTURE.md`, `DATABASE.md`, `TESTING.md`, `CODEMAP.md`, and `docs/ADMIN_SETTINGS_INVENTORY.md` for stale behavior descriptions.

After the final source and documentation edits, build the PDF with the four commands above from `docs/`. Inspect the generated PDF title page, table of contents, bookmarks, index, page breaks, and feature documentation. Confirm that the opening guide flows from the purpose/reading guide directly into “How to use this manual” and contains no release-news block. Intermediate `.aux`, `.idx`, `.ilg`, `.log`, and related files are disposable and remain ignored; `docs/PHP_Gallery_Manual.pdf` is the tracked release artifact.

The manual build does not refresh application integrity data. Run `php scripts/generate_manifest.php` from the repository root only after all source edits are complete, then run `php scripts/generate_manifest.php --check`. The manifest check must pass before a deployment ZIP, release commit, tag, or handoff is created. Inspect the final archive listing and confirm the manual PDF, migrations, patch notes, release metadata, and manifest are present. The `CMS_VERSION`, patch-note heading, release-metadata key/tag, PDF edition, archive/release name, and annotated Git tag must all agree before publishing. After publication, smoke-test an updater upgrade from the previous stable tag and verify migrations, Admin login, public rendering, and the integrity page.
