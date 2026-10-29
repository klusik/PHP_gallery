# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: scripts/import_winapp_game_catalog.py
# Module Type: Offline Catalog Import Tool
# Purpose: Import factual Windows game executable names from a saved Discord list.
# Responsibilities:
#   - Preserve source provenance and exclude launchers and shared runtimes.
#   - Emit deterministic, reviewable JSON without downloading data at runtime.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)
"""Refresh the bundled optional game catalog from an explicitly downloaded file."""

import argparse
import hashlib
import json
import re
from pathlib import Path

SOURCE_URL = "https://discord.com/api/v10/games/detectable"
EXCLUDED_HELPERS = re.compile(
    r"launcher|updater|crash|^setup|^unins|config(?:uration)?\.exe$|[\*\?]"
)
SHARED_RUNTIMES = {
    "java.exe", "javaw.exe", "python.exe", "pythonw.exe", "dotnet.exe",
    "rundll32.exe", "steam.exe", "origin.exe", "eadesktop.exe",
    "galaxyclient.exe", "battle.net.exe",
}


def import_catalog(source: Path, target: Path, retrieved_date: str) -> tuple[int, int]:
    """Convert a complete saved response into a small offline Windows catalog.

    Args:
        source (Path): Complete UTF-8 JSON list from the public Discord endpoint.
            Rows have id/name strings and executables with name/os/is_launcher.
        target (Path): Tracked JSON destination for normalized process rules.
        retrieved_date (str): Actual download date in YYYY-MM-DD format.
    Returns:
        tuple[int, int]: Included game count and executable-rule count.
    Raises:
        ValueError: The source is not a list of application records.
        OSError: Source reading or destination writing fails.
    """
    raw = source.read_bytes()
    applications = json.loads(raw)
    if not isinstance(applications, list):
        raise ValueError("Expected the complete Discord application list.")
    rows = []
    for application in applications:
        if not isinstance(application, dict):
            continue
        identity, name = application.get("id"), application.get("name")
        executables = application.get("executables")
        if (
            not isinstance(identity, str) or not isinstance(name, str)
            or not isinstance(executables, list)
        ):
            continue
        names = set()
        for executable in executables:
            if (
                not isinstance(executable, dict)
                or executable.get("os") != "win32"
                or executable.get("is_launcher") is not False
                or not isinstance(executable.get("name"), str)
            ):
                continue
            basename = executable["name"].replace("\\", "/").split("/")[-1].casefold()
            if (
                basename.endswith(".exe") and not EXCLUDED_HELPERS.search(basename)
                and basename not in SHARED_RUNTIMES
            ):
                names.add(basename)
        if names:
            rows.append({"id": identity, "name": name, "executables": sorted(names)})
    rows.sort(key=lambda row: (row["name"].casefold(), row["id"]))
    rule_count = sum(len(row["executables"]) for row in rows)
    metadata = {
        "source_url": SOURCE_URL,
        "retrieved_date": retrieved_date,
        "source_sha256": hashlib.sha256(raw).hexdigest(),
        "source_applications": len(applications),
        "included_games": len(rows),
        "included_executable_rules": rule_count,
        "platform": "win32",
        "policy": (
            "Snapshot of factual names only; non-launcher Windows executable "
            "basenames, excluding launcher/updater/config/setup/crash helpers "
            "and shared runtimes. Source is incomplete and may be inaccurate. "
            "Ambiguous basenames are never assigned a specific game at runtime."
        ),
    }
    lines = [
        "{", '  "schema_version": 1,',
        '  "source": ' + json.dumps(metadata, ensure_ascii=False) + ",",
        '  "games": [',
        ",\n".join("    " + json.dumps(row, ensure_ascii=False) for row in rows),
        "  ]", "}", "",
    ]
    target.write_text("\n".join(lines), encoding="utf-8")
    return len(rows), rule_count


def main() -> None:
    """Import an explicit source snapshot and report its retained coverage.

    Returns:
        None: Writes the requested catalog or exits with a source/path error.
    """
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("source", type=Path)
    parser.add_argument("target", type=Path)
    parser.add_argument("--retrieved-date", required=True)
    arguments = parser.parse_args()
    games, rules = import_catalog(
        arguments.source, arguments.target, arguments.retrieved_date
    )
    print(f"Imported {games} games and {rules} Windows executable rules.")


if __name__ == "__main__":
    main()
