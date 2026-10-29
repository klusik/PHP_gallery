# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/uploader/game_context.py
# Module Type: Game Context Policy
# Purpose: Match offline game process rules to localized optional action text.
# Responsibilities:
#   - Load the bundled catalog and extensible game-specific overrides.
#   - Keep ambiguous detections general and preserve the original actions.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)
"""Data-driven wording for games; process names never authorize any operation."""

import json
from dataclasses import dataclass, field
from pathlib import Path

from uploader.flight_report_translations import tr

RESOURCES = Path(__file__).resolve().parent / "resources"
# Purpose: Keep custom phrases within the existing compact action row.
# Type: int; Units: Unicode characters; Scope: custom Begin/Finish labels.
# Consumers: read_contexts. Rationale: 64 characters permit short localized
# phrases without accepting arbitrary multiline UI content.
MAX_ACTION_LABEL_CHARACTERS = 64


@dataclass(frozen=True)
class GameContext:
    """Identify one game/family and its optional four-language action labels."""

    identity: str
    name: str
    activity: str = "game"
    begin: dict[str, str] = field(default_factory=dict)
    finish: dict[str, str] = field(default_factory=dict)

    def label(self, action: str, language: str) -> str:
        """Resolve an optional phrase before the ordinary translated label.

        Args:
            action (str): Existing Begin/Finish action, either begin or finish.
            language (str): Interface language code, en/cs/de/sv.
        Returns:
            str: Custom localized phrase with English fallback, otherwise the
                existing generic game/flight label for that action.
        """
        labels = self.begin if action == "begin" else self.finish
        return labels.get(language, labels.get("en", "")) or tr(
            language, action + "_" + self.activity
        )


def read_contexts(path: Path) -> list[tuple[GameContext, set[str]]]:
    """Read validated game rows without executing any catalog content.

    Args:
        path (Path): Bundled catalog, bundled overrides or optional profile JSON.
    Returns:
        list[tuple[GameContext, set[str]]]: Contexts with lowercase executable
            basenames. Missing/malformed files return no rows; malformed rows
            are skipped independently, preserving the remaining defaults.
    """
    try:
        document = json.loads(path.read_text(encoding="utf-8"))
    except (OSError, ValueError):
        return []
    if not isinstance(document, dict) or document.get("schema_version") != 1:
        return []
    rows = document.get("games")
    if not isinstance(rows, list):
        return []
    result = []
    for row in rows:
        if not isinstance(row, dict):
            continue
        identity, name = row.get("id"), row.get("name")
        names = row.get("executables")
        activity = row.get("activity", "game")
        if (
            not isinstance(identity, str) or not identity.strip()
            or not isinstance(name, str) or not name.strip()
            or not isinstance(names, list) or activity not in ("game", "flight")
        ):
            continue
        executables = {
            value.casefold() for value in names
            if isinstance(value, str) and value.lower().endswith(".exe")
            and not any(character in value for character in "\\/*?\r\n")
        }
        labels = []
        for action in ("begin", "finish"):
            phrases = row.get(action, {})
            labels.append({
                language: text for language, text in phrases.items()
                if language in ("en", "cs", "de", "sv")
                and isinstance(text, str) and text.strip()
                and len(text) <= MAX_ACTION_LABEL_CHARACTERS
                and "\n" not in text and "\r" not in text
            } if isinstance(phrases, dict) else {})
        if executables:
            result.append((
                GameContext(identity, name, activity, labels[0], labels[1]),
                executables,
            ))
    return result


class GameCatalog:
    """Index offline rules while allowing simple reviewed per-user additions."""

    def __init__(self, profile: Path) -> None:
        """Load defaults and optional profile overrides once for this app run.

        Args:
            profile (Path): Uploader profile containing optional game-contexts.json.
        Returns:
            None: Indexes bundled rules, then bundled and per-user overrides.
                Explicit overrides replace baseline matches for their processes.
        """
        self.by_process: dict[str, dict[str, GameContext]] = {}
        for context, names in read_contexts(RESOURCES / "game-processes.json"):
            for name in names:
                self.by_process.setdefault(name, {})[context.identity] = context
        for path in (RESOURCES / "game-contexts.json", profile / "game-contexts.json"):
            for context, names in read_contexts(path):
                for name in names:
                    self.by_process[name] = {context.identity: context}
        self.process_names = set(self.by_process)

    def detect(self, processes: set[str]) -> GameContext | None:
        """Select a single proven game/family, leaving ambiguity general.

        Args:
            processes (set[str]): Current-user executable basenames from one
                snapshot; matching is exact and case insensitive.
        Returns:
            GameContext | None: One unambiguous game/family, or None when
                unknown, multiple games or shared executable names are present.
        """
        found = {}
        for name in processes:
            candidates = self.by_process.get(name.casefold(), {})
            if len(candidates) > 1:
                return None
            found.update(candidates)
        return next(iter(found.values())) if len(found) == 1 else None
