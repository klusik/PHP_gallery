# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/tests/test_game_presence.py
# Module Type: Game Presence Regression Tests
# Purpose: Verify optional process fallback, account isolation and action phrases.
# Responsibilities:
#   - Cover offline rules, ambiguity, custom additions and SimConnect priority.
#   - Verify least-privilege process queries and native handle cleanup.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)
"""Exercise game context without launching games, elevation or network access."""

import ctypes
import json
import os
import sys
import tempfile
import unittest
from pathlib import Path
from unittest import mock

WINAPP_DIR = Path(__file__).resolve().parents[1]
if str(WINAPP_DIR) not in sys.path:
    sys.path.insert(0, str(WINAPP_DIR))

from uploader import game_context as CONTEXT
from uploader import game_presence as PRESENCE
from uploader import windows_game_processes as PROCESSES


class GameContextTests(unittest.TestCase):
    """Keep offline matching, family-specific phrases and extension rules stable."""

    def test_abandonment_wording_and_flight_visibility_follow_the_cached_context(self) -> None:
        """Keep Abandon and its confirmation consistent with flight detection.

        Returns:
            None: Unknown/game contexts select four-language game vocabulary;
                native/process flight contexts restore all existing flight text.
        """
        from uploader.flight_report_translations import tr

        observer = PRESENCE.GamePresence(lambda: "", WINAPP_DIR)
        contexts = (
            None,
            CONTEXT.GameContext("cyberpunk-2077", "Cyberpunk 2077"),
            CONTEXT.GameContext("simconnect", "Flight Simulator", "flight"),
            CONTEXT.GameContext("microsoft-flight-simulator", "MSFS", "flight"),
            None,
        )
        key_pairs = {
            "abandon": "abandon_game",
            "confirm_abandon": "confirm_abandon_game",
            "phase_abandoning": "phase_abandoning_game",
            "flight_abandoned": "game_abandoned",
        }
        for context in contexts:
            observer.context = context
            flight = context is not None and context.activity == "flight"
            self.assertEqual(flight, observer.flight_detected)
            for language in ("en", "cs", "de", "sv"):
                for flight_key, game_key in key_pairs.items():
                    expected_key = flight_key if flight else game_key
                    self.assertEqual(
                        tr(language, expected_key), observer.text(flight_key, language)
                    )
                self.assertEqual(tr(language, "retry"), observer.text("retry", language))

    def test_bundled_catalog_has_source_bound_counts_and_requested_families(self) -> None:
        """Retain the downloaded coverage and reviewed game/family overrides.

        Returns:
            None: Metadata counts describe complete bundled data; requested
                games match while launchers and shared runtimes do not.
        """
        with tempfile.TemporaryDirectory() as directory:
            catalog = CONTEXT.GameCatalog(Path(directory))
        data = json.loads((CONTEXT.RESOURCES / "game-processes.json").read_text("utf-8"))
        self.assertEqual(data["source"]["included_games"], len(data["games"]))
        self.assertEqual(
            data["source"]["included_executable_rules"],
            sum(len(row["executables"]) for row in data["games"]),
        )
        expected = {
            "Cyberpunk2077.EXE": "cyberpunk-2077",
            "MassEffect1.exe": "mass-effect-trilogy",
            "MassEffect2.exe": "mass-effect-trilogy",
            "ME2Game.exe": "mass-effect-trilogy",
            "MassEffect3.exe": "mass-effect-trilogy",
            "SkyrimSE.exe": "skyrim",
            "DAOrigins.exe": "dragon-age-origins",
            "DragonAge2.exe": "dragon-age-2",
            "DragonAgeInquisition.exe": "dragon-age-inquisition",
            "FlightSimulator2024.exe": "microsoft-flight-simulator",
        }
        for process, identity in expected.items():
            with self.subTest(process=process):
                self.assertEqual(identity, catalog.detect({process}).identity)
        for launcher in ("steam.exe", "skyrimlauncher.exe", "javaw.exe", "masseffectlauncher.exe"):
            self.assertIsNone(catalog.detect({launcher}))
        overrides = json.loads(
            (CONTEXT.RESOURCES / "game-contexts.json").read_text("utf-8")
        )
        for row in overrides["games"]:
            context = catalog.detect(set(row["executables"]))
            for action in ("begin", "finish"):
                for language, phrase in row.get(action, {}).items():
                    self.assertEqual(phrase, context.label(action, language))
        self.assertIsNone(catalog.detect({"unknown.exe"}))
        self.assertIsNone(catalog.detect({"cyberpunk2077.exe", "masseffect3.exe"}))
        self.assertNotEqual(
            "mass-effect-trilogy", catalog.detect({"masseffectandromeda.exe"}).identity
        )

    def test_user_json_adds_processes_and_replaces_phrases_without_a_build(self) -> None:
        """Make optional local rules easy to add and malformed rows harmless.

        Returns:
            None: A profile JSON replaces a default phrase, adds a game and
                ignores malformed rows; missing translations fall back safely.
        """
        with tempfile.TemporaryDirectory() as directory:
            profile = Path(directory)
            rows = [
                {"id": "my-cyberpunk", "name": "Cyberpunk", "executables": ["cyberpunk2077.exe"],
                 "begin": {"en": "Wake up, choom…", "cs": "Více\nřádků"}},
                {"id": "my-game", "name": "My game", "executables": ["mygame.exe"]},
                {"id": "bad-row", "name": "Bad row", "executables": ["*.exe", "folder/game.exe"]},
            ]
            (profile / "game-contexts.json").write_text(json.dumps({
                "schema_version": 1, "games": rows,
            }), encoding="utf-8")
            catalog = CONTEXT.GameCatalog(profile)
            cyberpunk = catalog.detect({"cyberpunk2077.exe"})
            self.assertEqual("Wake up, choom…", cyberpunk.label("begin", "cs"))
            self.assertEqual("Finish game…", cyberpunk.label("finish", "en"))
            self.assertEqual("my-game", catalog.detect({"mygame.exe"}).identity)
            self.assertIsNone(catalog.detect({"anything.exe"}))
            (profile / "game-contexts.json").write_text("{broken", encoding="utf-8")
            self.assertEqual(
                "cyberpunk-2077",
                CONTEXT.GameCatalog(profile).detect({"cyberpunk2077.exe"}).identity,
            )

    def test_shared_executable_name_is_not_assigned_to_one_game(self) -> None:
        """Avoid a misleading easter egg for ambiguous catalog basenames.

        Returns:
            None: Two distinct games sharing a name remain unclassified,
                while multiple processes of the same family are admitted.
        """
        with tempfile.TemporaryDirectory() as directory:
            catalog = CONTEXT.GameCatalog(Path(directory))
        catalog.by_process["shared.exe"] = {
            "one": CONTEXT.GameContext("one", "One"),
            "two": CONTEXT.GameContext("two", "Two"),
        }
        self.assertIsNone(catalog.detect({"shared.exe"}))
        self.assertEqual(
            "mass-effect-trilogy",
            catalog.detect({"masseffect.exe", "masseffect3.exe"}).identity,
        )

    def test_simconnect_wins_and_process_fallback_updates_both_action_labels(self) -> None:
        """Switch between contextual games, simulator fallback and unknown state.

        Returns:
            None: A successful native probe skips process inspection; later
                observations replace stale context and preserve localized labels.
        """
        with tempfile.TemporaryDirectory() as directory:
            observer = PRESENCE.GamePresence(lambda: "", Path(directory))
            observer.cancelled = mock.Mock()
            # Four observations cover game, process-only MSFS, native MSFS and exit.
            observer.cancelled.is_set.side_effect = [False, False, False, False, True]
            labels = []
            observer.cancelled.wait.side_effect = lambda _delay: labels.append((
                observer.action_label("begin", "en"),
                observer.action_label("finish", "en"),
            ))
            with (
                mock.patch.object(observer, "probe", side_effect=[False, False, True, False]),
                mock.patch.object(PRESENCE, "current_user_game_processes", side_effect=[
                    {"cyberpunk2077.exe"}, {"flightsimulator2024.exe"}, set(),
                ]) as processes,
            ):
                observer.run()
            self.assertEqual(3, processes.call_count)  # Native success skips fallback.
            self.assertEqual([
                ("Let's begin, choom…", "Catch you later, choom…"),
                ("Begin flight…", "Finish flight…"),
                ("Begin flight…", "Finish flight…"),
                ("Begin game…", "Finish game…"),
            ], labels)
            self.assertEqual("Zahájit hru…", observer.action_label("begin", "cs"))
            self.assertIsNone(observer.context)


class WindowsGameProcessTests(unittest.TestCase):
    """Constrain fallback presence to readable candidate processes of this user."""

    def test_other_accounts_and_denied_processes_are_excluded_and_handles_close(self) -> None:
        """Use limited queries and exact SID equality without enabling privileges.

        Returns:
            None: Other-account, denied and unrelated processes cannot select
                context; every acquired process/snapshot handle is closed.
        """
        reader = object.__new__(PROCESSES.WindowsGameProcesses)
        reader.kernel = mock.Mock()
        reader.kernel.GetCurrentProcess.return_value = 100
        reader.kernel.CreateToolhelp32Snapshot.return_value = 900
        # PIDs/handles are deliberately distinct structural native test identities.
        rows = iter([
            (10, "Cyberpunk2077.exe"), (20, "MassEffect3.exe"),
            (30, "SkyrimSE.exe"), (40, "unrelated.exe"),
        ])

        def advance(
            snapshot: int, entry_pointer: ctypes.POINTER(PROCESSES.ProcessEntry)
        ) -> int:
            """Copy one snapshot row into the native by-reference output.

            Args:
                snapshot (int): Fake snapshot handle retained for cleanup.
                entry_pointer (ctypes.POINTER(PROCESSES.ProcessEntry)): Output
                    PROCESSENTRY32W passed through ctypes.byref.
            Returns:
                int: SDK-style success flag, zero at snapshot exhaustion.
            """
            self.assertEqual(900, snapshot)
            row = next(rows, None)
            if row is None:
                return 0
            entry = ctypes.cast(entry_pointer, ctypes.POINTER(PROCESSES.ProcessEntry)).contents
            entry.pid, entry.exe = row
            return 1

        reader.kernel.Process32FirstW.side_effect = advance
        reader.kernel.Process32NextW.side_effect = advance
        reader.kernel.OpenProcess.side_effect = lambda _access, _inherit, pid: {
            10: 110, 20: 120, 30: None,
        }[pid]
        with (
            mock.patch.object(reader, "user_sid", side_effect=lambda handle: {
                100: b"current-user", 110: b"other-user", 120: b"current-user",
            }[handle]),
            mock.patch.object(
                PROCESSES.ctypes, "get_last_error",
                return_value=PROCESSES.ERROR_NO_MORE_FILES, create=True,
            ),
        ):
            self.assertEqual({"masseffect3.exe"}, reader.names({
                "cyberpunk2077.exe", "masseffect3.exe", "skyrimse.exe",
            }))
        self.assertEqual([
            mock.call(PROCESSES.PROCESS_QUERY_LIMITED_INFORMATION, False, pid)
            for pid in (10, 20, 30)
        ], reader.kernel.OpenProcess.call_args_list)
        self.assertEqual([
            mock.call(110), mock.call(120), mock.call(900),
        ], reader.kernel.CloseHandle.call_args_list)

    def test_unknown_current_account_never_inspects_other_processes(self) -> None:
        """Keep optional detection general when this account cannot be proven.

        Returns:
            None: Missing current SID prevents snapshot creation altogether.
        """
        reader = object.__new__(PROCESSES.WindowsGameProcesses)
        reader.kernel = mock.Mock()
        with mock.patch.object(reader, "user_sid", return_value=None):
            self.assertEqual(set(), reader.names({"cyberpunk2077.exe"}))
        reader.kernel.CreateToolhelp32Snapshot.assert_not_called()

    @unittest.skipUnless(os.name == "nt", "Native process visibility requires Windows")
    def test_native_current_process_is_visible_with_existing_account_rights(self) -> None:
        """Exercise real process enumeration and SID reading on Windows.

        Returns:
            None: The interpreter is visible without elevation requests or
                privileges beyond the account running this test.
        """
        executable = Path(sys.executable).name.casefold()
        self.assertIn(executable, PROCESSES.WindowsGameProcesses().names({executable}))


if __name__ == "__main__":
    unittest.main()
