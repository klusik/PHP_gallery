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

Update `\version` and `\manualdate` once near the beginning of `PHP_Gallery_Manual.tex` when preparing a new edition. The title page, footer, introductory text, and references reuse those commands. Review references, index entries, screenshots, command examples, and implementation descriptions against the local project before publishing a rebuilt PDF.
