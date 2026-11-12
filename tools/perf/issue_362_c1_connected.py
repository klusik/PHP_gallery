# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: tools/perf/issue_362_c1_connected.py
# Module Type: Disposable Connected Client Evidence
# Purpose: Verify current gallery-tree negotiation and fallback through WinApp.
# Responsibilities:
#   - Use the production LinkingSession and discovery parser against the owned fixture.
#   - Prove full/unchanged tree, 503-to-page fallback and authentication refusal behavior.
#   - Persist only safe booleans, counts and source coordinates.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)
"""Connected C1 client proofs against one owned loopback fixture."""

import json
import os
from pathlib import Path
import sys
import time
from urllib.parse import urlsplit


_ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(_ROOT / "winapp"))

from uploader.app_linking import (
    GALLERY_TREE_CAPABILITY,
    LinkingGrantRejected,
    LinkingResponseError,
    LinkingSession,
    discover_linking_capabilities,
)


# Purpose: Bound client session lifetime below the fixture grant's 2400 seconds.
# Type: int. Units: seconds. Scope: one C1 connected child.
# Consumers: _session. Rationale: 1800 seconds comfortably covers its few requests.
C1_SESSION_LIFETIME_SECONDS = 1800

# Purpose: Limit child selection to explicitly owned C1 client proofs.
# Type: frozenset[str]. Units: scenario identifiers. Scope: CLI entry point.
# Consumers: main. Rationale: reject arbitrary routes, paths and test modes.
C1_SCENARIOS = frozenset({
    "full", "overflow-row", "overflow-byte", "denied-401", "denied-403",
})

# Purpose: Match the fixed current-SHA tree sizing cases used by the PHP owner.
# Type: tuple[int, ...]. Units: total gallery rows. Scope: C1 connected client.
# Consumers: main. Rationale: compare real LinkingSession parsing at the same
# 500/2000/10000 row checkpoints without inventing another population.
C1_TREE_POPULATIONS = (500, 2000, 10000)

# Purpose: Preserve the existing bounded gallery-page fallback size.
# Type: int. Units: galleries per legacy HTTP page. Scope: C1 overflow proof.
# Consumers: _overflow_proof. Rationale: the production page contract is 50.
C1_LEGACY_PAGE_ROWS = 50


def _session(issuer: str, token: str, scope: str) -> LinkingSession:
    """Create the real client session with tree support after public negotiation.

    Args:
        issuer (str): Exact fixture origin validated by the owner marker.
        token (str): Synthetic bearer held only in this process environment.
        scope (str): Exact fixture scope bound to this token.
    Returns:
        LinkingSession: Real session configured for the advertised capability.
    """
    return LinkingSession(
        issuer,
        token,
        time.monotonic() + C1_SESSION_LIFETIME_SECONDS,
        scope=scope,
        gallery_tree_supported=True,
    )


def _full_proof(session: LinkingSession, rows: int) -> dict[str, bool | int]:
    """Prove real discovery, live identity, complete tree and unchanged confirmation.

    Args:
        session (LinkingSession): Real session for the exact approved fixture scope.
        rows (int): Expected bounded full catalogue population.

    Returns:
        dict[str, bool | int]: Sanitized proof flags and count; no row identities.

    Raises:
        AssertionError: Any capability, identity, tree or unchanged proof differs.
    """
    identity = session.identity()
    tree = session.gallery_tree()
    if tree is None or tree.unchanged or len(tree.galleries) != rows:
        raise AssertionError("C1 client full tree proof failed.")
    unchanged = session.gallery_tree(revision=tree.revision)
    if (unchanged is None or not unchanged.unchanged
            or unchanged.revision != tree.revision
            or unchanged.galleries):
        raise AssertionError("C1 client unchanged proof failed.")
    return {
        "identity_live": identity.user_id > 0,
        "tree_complete": True,
        "tree_rows": len(tree.galleries),
        "unchanged_confirmed": True,
        "unchanged_rows": len(unchanged.galleries),
    }


def _overflow_proof(session: LinkingSession) -> dict[str, bool | int]:
    """Prove optional 503 tree fallback resumes through two fresh pages.

    Args:
        session (LinkingSession): Real tree-capable gallery-list session.

    Returns:
        dict[str, bool | int]: Sanitized fallback status and page counts.

    Raises:
        AssertionError: The optional tree does not return None or pages are invalid.
    """
    tree = session.gallery_tree()
    if tree is not None:
        raise AssertionError("C1 optional overflow tree was not unavailable.")
    first = session.gallery_page()
    if (len(first.galleries) != C1_LEGACY_PAGE_ROWS
            or first.next_after is None):
        raise AssertionError("C1 first legacy page proof failed.")
    second = session.gallery_page(first.next_after)
    first_ids = {row.gallery_id for row in first.galleries}
    second_ids = {row.gallery_id for row in second.galleries}
    if (len(second.galleries) != C1_LEGACY_PAGE_ROWS
            or second.next_after is None
            or first_ids.intersection(second_ids)
            or second.next_after <= first.next_after):
        raise AssertionError("C1 second legacy page proof failed.")
    return {
        "optional_tree_unavailable": True,
        "fallback_page_count": 2,
        "fallback_rows": len(first.galleries) + len(second.galleries),
        "cursor_advanced": True,
        "duplicate_ids": False,
    }


def _denial_proof(
    session: LinkingSession,
    expected_status: int,
) -> dict[str, int | bool]:
    """Require bearer or scope refusal without fetching legacy pages.

    The 403 scenario retains the client's prior gallery-list consent while
    the disposable server grant has been downgraded, ensuring HTTP is reached.

    Args:
        session (LinkingSession): Real client session with the supplied test bearer.
        expected_status (int): Exact HTTP status to observe, 401 or 403.

    Returns:
        dict[str, int | bool]: Status-free rejection flags for 401, or actual
            response status and no-fallback flag for 403.

    Raises:
        AssertionError: The response status differs or the client accepts it.
        LinkingError: Other session or transport failures propagate unchanged.
    """
    try:
        session.gallery_tree()
    except LinkingGrantRejected as error:
        if (expected_status != 401 or not error.request_started
                or session.available()):
            raise AssertionError(
                "C1 client grant rejection state differed.",
            ) from None
        return {
            "grant_rejected": True,
            "session_expired": True,
            "request_started": True,
            "caller_fallback_attempted": False,
        }
    except LinkingResponseError as error:
        if expected_status != 403 or error.status != expected_status:
            raise AssertionError("C1 client denial status differed.") from None
        return {
            "refusal_status": error.status,
            "caller_fallback_attempted": False,
        }
    raise AssertionError("C1 client accepted an unauthorized tree request.")


def main() -> int:
    """Run exactly one allowlisted client proof and save sanitized evidence.

    Returns:
        int: 0 when the proof passes; 1 when validation or execution fails.

    All errors persist only a fixed result code and safe source coordinate.
    """
    scenario = sys.argv[1] if len(sys.argv) == 2 else "invalid"
    if scenario not in C1_SCENARIOS:
        scenario = "invalid"
    report_path: Path | None = None
    session: LinkingSession | None = None
    try:
        if (os.getenv("GITHUB_ACTIONS") != "true"
                or os.getenv("GALLERY_WORKFLOW_ENABLE") != "disposable-only"
                or scenario == "invalid"):
            raise RuntimeError("Owned C1 GitHub Actions scenario required.")
        sha = os.getenv("GITHUB_SHA", "")
        source_base = os.getenv("PHP_GALLERY_SOURCE_BASE", "")
        run_id = os.getenv("GITHUB_RUN_ID", "")
        if (not _is_sha(sha) or not _is_sha(source_base)
                or not run_id.isdecimal()):
            raise RuntimeError("C1 connected provenance was invalid.")
        issuer = os.getenv("C1_CLIENT_ISSUER", "")
        token = os.getenv("C1_CLIENT_BEARER", "")
        scope = os.getenv("C1_CLIENT_SCOPE", "")
        fixture = Path(os.environ["GALLERY_WORKFLOW_FIXTURE"])
        owner_token = os.environ["GALLERY_WORKFLOW_TOKEN"]
        parsed = urlsplit(issuer)
        if (parsed.scheme != "http" or parsed.hostname != "localhost"
                or parsed.port is None or parsed.username is not None
                or parsed.password is not None or parsed.path or parsed.query
                or parsed.fragment
                or fixture.joinpath(".workflow-owner").read_text(encoding="utf-8")
                != owner_token):
            raise RuntimeError("Nonowned loopback C1 fixture refused.")
        rows_text = os.getenv("C1_CLIENT_ROWS", "")
        rows = None
        if scenario == "full":
            if (not rows_text.isdecimal()
                    or str(int(rows_text)) != rows_text
                    or int(rows_text) not in C1_TREE_POPULATIONS):
                raise RuntimeError("C1 connected row selector was invalid.")
            rows = int(rows_text)
        expected_status = int(os.getenv("C1_CLIENT_EXPECT_STATUS", "0"))
        if (scenario == "denied-401" and expected_status != 401
                or scenario == "denied-403" and expected_status != 403):
            raise RuntimeError("C1 client refusal selector was invalid.")
        capabilities = discover_linking_capabilities(issuer)
        if GALLERY_TREE_CAPABILITY not in capabilities:
            raise AssertionError("C1 tree capability was not discovered.")
        session = _session(issuer, token, scope)
        if scenario == "full":
            result = _full_proof(session, rows or 0)
        elif scenario.startswith("overflow-"):
            result = _overflow_proof(session)
        else:
            result = _denial_proof(session, expected_status)
        report = {
            "schema": "php-gallery-c1-connected-v1",
            "scenario": scenario,
            "status": "PASS",
            "head_sha": sha,
            "source_base": source_base,
            "run_id": run_id,
            "result": result,
        }
        suffix = "-" + rows_text if scenario == "full" else ""
        report_path = _ROOT / "cache" / "test-audit" / (
            "issue-362-c1-connected-" + scenario + suffix + ".json"
        )
        report_path.parent.mkdir(parents=True, exist_ok=True)
        report_path.write_text(
            json.dumps(report, indent=2) + "\n", encoding="utf-8",
        )
        print("PASS C1 connected " + scenario)
        return 0
    except Exception as error:
        trace = error.__traceback__
        while trace is not None and trace.tb_next is not None:
            trace = trace.tb_next
        source = (Path(trace.tb_frame.f_code.co_filename).name
                  if trace is not None else "UNKNOWN")
        line = trace.tb_lineno if trace is not None else None
        report_path = report_path or (
            _ROOT / "cache" / "test-audit"
            / "issue-362-c1-connected-failure.json"
        )
        report_path.parent.mkdir(parents=True, exist_ok=True)
        report_path.write_text(json.dumps({
            "schema": "php-gallery-c1-connected-v1",
            "scenario": scenario,
            "status": "FAIL",
            "head_sha": os.getenv("GITHUB_SHA", "UNKNOWN"),
            "source_base": os.getenv("PHP_GALLERY_SOURCE_BASE", "UNKNOWN"),
            "run_id": os.getenv("GITHUB_RUN_ID", "UNKNOWN"),
            "failure": {
                "class": type(error).__name__,
                "source": source,
                "line": line,
            },
        }, indent=2) + "\n", encoding="utf-8")
        print("FAIL C1 connected " + scenario + " at " + source + ":" + str(line))
        return 1
    finally:
        if session is not None:
            session.close()


def _is_sha(value: str) -> bool:
    """Require a forty-character hexadecimal source identity.

    Args:
        value (str): Candidate source or base SHA from GitHub Actions.

    Returns:
        bool: True only when the value has the exact Git SHA shape.
    """
    return (len(value) == 40
            and all(character in "0123456789abcdefABCDEF" for character in value))


if __name__ == "__main__":
    raise SystemExit(main())
