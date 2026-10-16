#!/usr/bin/env bash
# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: .github/ci-experiment/manuals.sh
# Module Type: Archived CI Experiment Shell Tool
# Purpose: Build all four locked manuals without mutating immutable test sources.
# Responsibilities:
#   - Copy TeX inputs into an isolated output directory.
#   - Compile, validate and checksum English, Czech, German and Swedish PDFs.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)
set -euo pipefail
source_dir="${1:?source checkout}"
output_dir="${2:?isolated output directory}"
mkdir -p "${output_dir}/work"
cp -a "${source_dir}/docs/." "${output_dir}/work/"
rm -f "${output_dir}/work"/PHP_Gallery_Manual*.pdf
export SOURCE_DATE_EPOCH
SOURCE_DATE_EPOCH="$(git -C "${source_dir}" show -s --format=%ct HEAD)"
export FORCE_SOURCE_DATE=1
cd "${output_dir}/work"
for base in PHP_Gallery_Manual PHP_Gallery_Manual_CZ PHP_Gallery_Manual_DE PHP_Gallery_Manual_SV; do
    printf 'MANUAL %s: BEGIN\n' "$base"
    pdflatex -interaction=nonstopmode -halt-on-error "$base.tex" > "${output_dir}/${base}.log" 2>&1
    makeindex "$base.idx" >> "${output_dir}/${base}.log" 2>&1
    pdflatex -interaction=nonstopmode -halt-on-error "$base.tex" >> "${output_dir}/${base}.log" 2>&1
    pdflatex -interaction=nonstopmode -halt-on-error "$base.tex" >> "${output_dir}/${base}.log" 2>&1
    test -s "$base.pdf"
    # The installed release build requires pdflatex/makeindex, not poppler-utils.
    # Validate equal portable PDF structure on both the stock host and the OCI image.
    # A missing optional pdfinfo executable must not invalidate arm A.
    php -r '
        $path = $argv[1];
        $handle = fopen($path, "rb");
        if ($handle === false) { exit(2); }
        $size = filesize($path);
        $header = fread($handle, 8);
        if ($size === false || $size < 1024 || $header === false
            || preg_match("/^%PDF-[0-9][.][0-9]$/", $header) !== 1) { exit(3); }
        if (fseek($handle, -min(2048, $size), SEEK_END) !== 0) { exit(4); }
        $trailer = stream_get_contents($handle);
        fclose($handle);
        if (!is_string($trailer) || !str_contains($trailer, "%%EOF")) { exit(5); }
        printf("PDF version=%s bytes=%d EOF=present\n", substr($header, 5), $size);
    ' "$base.pdf" > "${output_dir}/${base}-verification.txt"
    # Edition/version expectations come from the immutable T manual sources.
    grep -Fq '\newcommand{\version}{0.132.9}' "$base.tex"
    cp "$base.pdf" "${output_dir}/${base}.pdf"
    sha256sum "$base.pdf"
    printf 'MANUAL %s: COMPLETE\n' "$base"
done
test "$(find "${output_dir}" -maxdepth 1 -type f -name '*.pdf' | wc -l)" -eq 4
