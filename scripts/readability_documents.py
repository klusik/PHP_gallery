#!/usr/bin/env python3
# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: scripts/readability_documents.py
# Module Type: Advisory Document Source Scanner
# Purpose: Find prose wrapping opportunities while preserving document syntax.
# Responsibilities:
#   - Mask TeX commands, math and literal environments.
#   - Mask Markdown code, tables, headings and link destinations.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)

import re
from typing import TYPE_CHECKING

if TYPE_CHECKING:
    from readability_report import Structure


def group_end(source: str, start: int) -> int:
    """Find a nested bracket group's end without interpreting escaped brackets.

    @param str source Complete document source.
    @param int start Offset of an opening brace, bracket or parenthesis.
    @return int Exclusive end, or end of source for an unterminated group.
    """
    closing = {"{": "}", "[": "]", "(": ")"}
    opening = source[start]
    closing_character = closing[opening]
    depth = 1
    cursor = start + 1
    while cursor < len(source) and depth:
        character = source[cursor]
        if character == "\\":
            # An escape consumes the backslash and its following character.
            cursor += 2
            continue
        if character == opening:
            depth += 1
        elif character == closing_character:
            depth -= 1
        cursor += 1
    return min(cursor, len(source))


def document_structure(
    source: str, spans: list[tuple[int, int]],
) -> "Structure":
    """Mask ordered literal spans while keeping exact prose line coordinates.

    @param str source Complete TeX or Markdown document.
    @param list[tuple[int,int]] spans Disjoint start/exclusive-end literal offsets.
    @return Structure Prose skeleton, original literal fragments and no declarations.
    """
    pieces = []
    literals = {}
    cursor = 0
    line = 0
    for start, end in spans:
        prefix = source[cursor:start]
        pieces.append(prefix)
        line += prefix.count("\n")
        literal = source[start:end]
        for offset, part in enumerate(literal.split("\n")):
            literals.setdefault(line + offset, []).append(part)
        pieces.append(re.sub(r"[^\n]", " ", literal))
        line += literal.count("\n")
        cursor = end
    pieces.append(source[cursor:])
    return {
        "code": "".join(pieces).splitlines(),
        "literals": literals,
        "declarations": [],
    }


def tex_structure(source: str) -> "Structure":
    """Recognize ordinary TeX prose without proposing splits inside syntax.

    @param str source Authored TeX document; commands are never executed.
    @return Structure Prose outside comments, command arguments, math and literals.
    """
    literal_environments = {
        "verbatim", "verbatim*", "Verbatim", "BVerbatim", "LVerbatim",
        "lstlisting", "minted", "alltt", "comment", "filecontents",
        "filecontents*", "equation", "equation*", "align", "align*",
        "alignat", "alignat*", "gather", "gather*", "multline", "multline*",
        "displaymath", "math", "array", "tabular", "tabularx", "longtable",
    }
    spans = []
    cursor = 0
    while cursor < len(source):
        start = cursor
        character = source[cursor]
        if character == "%":
            end = source.find("\n", cursor)
            cursor = end if end >= 0 else len(source)
        elif character == "$":
            delimiter = "$$" if source.startswith("$$", cursor) else "$"
            cursor += len(delimiter)
            while cursor < len(source):
                if source[cursor] == "\\":
                    # A two-character escape cannot close the math delimiter.
                    cursor += 2
                elif source.startswith(delimiter, cursor):
                    cursor += len(delimiter)
                    break
                else:
                    cursor += 1
        elif character == "\\":
            command = re.match(r"\\([A-Za-z@]+)\*?", source[cursor:])
            # TeX's escaped math delimiters each occupy two source characters.
            if source[cursor:cursor + 2] in (r"\(", r"\["):
                closing = r"\)" if source[cursor + 1] == "(" else r"\]"
                end = source.find(closing, cursor + 2)
                cursor = end + 2 if end >= 0 else len(source)
            elif command:
                cursor += len(command[0])
                if command[1] == "verb" and cursor < len(source):
                    delimiter = source[cursor]
                    end = source.find(delimiter, cursor + 1)
                    cursor = end + 1 if end >= 0 else len(source)
                else:
                    environment = re.match(r"\s*\{([^{}]+)\}", source[cursor:])
                    if (command[1] == "begin" and environment
                            and environment[1] in literal_environments):
                        closing = "\\end{" + environment[1] + "}"
                        end = source.find(closing, cursor + len(environment[0]))
                        cursor = (end + len(closing) if end >= 0
                                  else len(source))
                    else:
                        # Keep all arguments opaque, including nested URL/code macros.
                        while cursor < len(source):
                            argument = cursor
                            while (argument < len(source)
                                   and source[argument].isspace()):
                                argument += 1
                            if (argument >= len(source)
                                    or source[argument] not in "[{"):
                                break
                            cursor = group_end(source, argument)
            else:
                # A control symbol is a backslash plus one escaped character.
                cursor = min(len(source), cursor + 2)
        else:
            cursor += 1
            continue
        spans.append((start, min(cursor, len(source))))
    return document_structure(source, spans)


def markdown_structure(source: str) -> "Structure":
    """Recognize Markdown prose without reflowing structural or literal blocks.

    @param str source Authored Markdown source, including optional fenced examples.
    @return Structure Prose outside code, tables, headings, HTML and links.
    """
    spans = []
    offset = 0
    fence_character = ""
    fence_length = 0
    html_end = ""
    front_matter = False
    for number, line in enumerate(source.splitlines(keepends=True)):
        start = offset
        offset += len(line)
        # Markdown fences use at least three markers and at most three spaces.
        fence = re.match(r" {0,3}(`{3,}|~{3,})(.*)", line)
        if fence_character:
            spans.append((start, offset))
            if (fence and fence[1][0] == fence_character
                    and len(fence[1]) >= fence_length and not fence[2].strip()):
                fence_character = ""
            continue
        if fence:
            fence_character, fence_length = fence[1][0], len(fence[1])
            spans.append((start, offset))
            continue
        if number == 0 and line.strip() == "---":
            front_matter = True
            spans.append((start, offset))
            continue
        if front_matter:
            spans.append((start, offset))
            if line.strip() in ("---", "..."):
                front_matter = False
            continue
        if html_end:
            spans.append((start, offset))
            if html_end in line.lower():
                html_end = ""
            continue
        html_block = re.match(
            r"\s*<(script|style|pre|table|div|section|details)\b", line, re.I,
        )
        if html_block or "<!--" in line:
            html_end = "</" + html_block[1].lower() + ">" if html_block else "-->"
            spans.append((start, offset))
            if html_end in line.lower():
                html_end = ""
            continue
        # Four spaces/tab mark indented code; ATX headings use one to six #.
        if (line.startswith(("    ", "\t"))
                or re.match(r" {0,3}(?:#{1,6}\s|\[[^\]]+\]:)", line)
                or re.search(r"(?<!\\)\|", line)):
            spans.append((start, offset))
            continue
        cursor = 0
        while cursor < len(line):
            fragment = cursor
            if line[cursor] == "\\":
                # Skip both characters of the Markdown escape as one unit.
                cursor += 2
                continue
            if line[cursor] == "`":
                marker = re.match(r"`+", line[cursor:])[0]
                end = line.find(marker, cursor + len(marker))
                cursor = end + len(marker) if end >= 0 else len(line)
            elif line[cursor] == "[":
                cursor = group_end(line, cursor)
                if cursor < len(line) and line[cursor] in "([":
                    cursor = group_end(line, cursor)
            elif line[cursor] == "<":
                end = line.find(">", cursor + 1)
                cursor = end + 1 if end >= 0 else len(line)
            elif re.match(r"https?://", line[cursor:]):
                cursor += len(re.match(r"\S+", line[cursor:])[0])
            else:
                cursor += 1
                continue
            spans.append((start + fragment, start + min(cursor, len(line))))
    return document_structure(source, spans)
