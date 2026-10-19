#!/usr/bin/env python3
# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: scripts/wrap_markdown.py
# Module Type: Markdown Readability Helper
# Purpose: Wrap long prose lines in every tracked Markdown file.
# Responsibilities: Preserve literal syntax, list indentation and line endings.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)

"""Wrap tracked Markdown prose at word boundaries while preserving literals."""

import argparse
from pathlib import Path
import re
import subprocess

from readability_documents import markdown_structure


# Prefer short prose without splitting a word, link or inline code fragment.
# Type: int. Units: characters. Scope: tracked Markdown prose lines.
# Consumers: wrap_line(). Rationale: 80 matches the readability preference;
# longer indivisible fragments remain intact rather than changing their text.
WRAP_WIDTH: int = 80

# Bound local tracked-path discovery without imposing an audit deadline.
# Type: int. Units: seconds. Scope: the helper's single git ls-files call.
# Consumers: main(). Rationale: 30 allows a slow local checkout to enumerate
# paths while preventing an indefinitely blocked development command.
GIT_TIMEOUT_SECONDS: int = 30


def wrap_line(line: str, literals: list[str]) -> list[str]:
    """Split one prose line only at spaces outside protected Markdown atoms.

    Args:
        line (str): Source line without its physical line ending.
        literals (list[str]): Ordered code, link or HTML fragments to preserve.
    Returns:
        list[str]: Wrapped lines with original text and continuation indentation.
    """
    protected = []
    cursor = 0
    for literal in filter(None, literals):
        start = line.find(literal, cursor)
        if start < 0:
            return [line]
        cursor = start + len(literal)
        protected.append((start, cursor))

    # Up to three leading spaces are Markdown block indentation; four is code.
    # Quote/list markers repeat only as required by Markdown continuation.
    prefix = re.match(
        r"( {0,3}(?:>[ \t]?)*[ \t]*)(?:([-+*]|\d+[.)])[ \t]+)?", line,
    )
    assert prefix is not None
    continuation = prefix[1] + " " * (prefix.end() - len(prefix[1]))
    breaks = []
    for space in re.finditer(r" +", line):
        if space.start() < prefix.end() or space.end() == len(line):
            continue
        if any(space.start() < end and space.end() > start
               for start, end in protected):
            continue
        # A new line must not turn prose into a heading, list, HTML or hard break.
        if line[space.start() - 1:space.start()] == "\\" or re.match(
            r"(?:#{1,6}(?:\s|$)|[-+*]\s|\d+[.)]\s|[<>|]|[-=~`]{3,}(?:\s|$))",
            line[space.end():],
        ):
            continue
        breaks.append((space.start(), space.end()))

    result = []
    offset = 0
    indent = ""
    while len(indent + line[offset:]) > WRAP_WIDTH:
        available = [(start, end) for start, end in breaks if start > offset]
        if not available:
            break
        fitting = [pair for pair in available
                   if len(indent) + pair[0] - offset <= WRAP_WIDTH]
        start, end = fitting[-1] if fitting else available[0]
        result.append(indent + line[offset:start])
        offset, indent = end, continuation
    return result + [indent + line[offset:]]


def wrap_markdown(source: str) -> str:
    """Wrap prose using the existing scanner's protected Markdown boundaries.

    Args:
        source (str): Complete UTF-8 document, possibly with a leading BOM.
    Returns:
        str: Document preserving blocks, literal text, hard breaks and endings.
    """
    bom = "\ufeff" if source.startswith("\ufeff") else ""
    source = source.removeprefix(bom) if bom else source
    structure = markdown_structure(source)
    result = []
    for index, physical in enumerate(source.splitlines(keepends=True)):
        line = physical.rstrip("\r\n")
        ending = physical[len(line):]
        if len(line) <= WRAP_WIDTH or not structure["code"][index].strip():
            result.append(physical)
            continue
        parts = wrap_line(line, structure["literals"].get(index, []))
        result.append((ending or "\n").join(parts) + ending)
    return bom + "".join(result)


def main() -> int:
    """Wrap every tracked Markdown file or report outstanding changes.

    Returns:
        int: Zero after writing or a clean check; one for pending check changes.
    """
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--check", action="store_true", help="Do not write files.")
    options = parser.parse_args()
    root = Path(__file__).resolve().parent.parent
    tracked = subprocess.check_output(
        ["git", "ls-files", "-z"], cwd=root, timeout=GIT_TIMEOUT_SECONDS,
    ).decode("utf-8").split("\0")
    paths = [
        root / path for path in tracked if Path(path).suffix.lower() == ".md"
    ]
    changed = 0
    for path in paths:
        if path.is_symlink():
            raise ValueError(f"Refusing to rewrite a Markdown symlink: {path}")
        source = path.read_bytes().decode("utf-8")
        wrapped = wrap_markdown(source)
        if wrapped != source:
            changed += 1
            if not options.check:
                path.write_bytes(wrapped.encode("utf-8"))
    state = "pending" if options.check else "changed"
    print(f"Markdown files: {len(paths)}; {state}: {changed}")
    return int(options.check and changed > 0)


if __name__ == "__main__":
    raise SystemExit(main())
