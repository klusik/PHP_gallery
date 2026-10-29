# WinApp game detection and contextual labels

The Watcher and Import Begin/Finish controls retain their original connection,
stop, report and key-revocation actions. Only their text changes. The existing
saved key determines Begin versus Finish; game detection never changes
credentials, starts watching, records a flight or authorizes an operation.

## Detection order and account rights

The background `GamePresence` observer tries a read-only SimConnect open/close
first. Success selects the ordinary flight labels even when another game is
running. No telemetry, location request or simulator command is issued.

When SimConnect is unavailable, `windows_game_processes.py` takes a process-name
snapshot and checks only known executable names. It opens these processes with
`PROCESS_QUERY_LIMITED_INFORMATION`, reads their `TokenUser` with `TOKEN_QUERY`,
and compares its SID with the uploader account. Other users, inaccessible/exited
processes and an unprovable current identity produce no match. It requests no
admin elevation or debug privilege, and never reads game memory. Snapshot,
process and token handles are closed. A partial/failed snapshot is not used.

This uses Microsoft's documented [process snapshots](https://learn.microsoft.com/en-us/windows/win32/api/tlhelp32/nf-tlhelp32-createtoolhelp32snapshot)
and [same-account token access](https://learn.microsoft.com/en-us/windows/win32/api/processthreadsapi/nf-processthreadsapi-openprocesstoken).
Standard accounts can inspect ordinary processes they own. An anti-cheat or
other process ACL can still deny identity access; detection remains optional.

An unambiguous Microsoft Flight Simulator process also selects flight wording
when SimConnect fails. Other games use general game wording unless a reviewed
custom phrase exists. Multiple games or an executable shared by different games
keep the general wording. Multiple processes assigned to one explicit family,
such as the Mass Effect trilogy, share that family's phrases. Andromeda is not
assigned Shepard's phrases. Launchers alone do not select these reviewed families.

The same cached flight context controls SimBrief visibility: its Load/Unload
button, settings icon and status are shown only for a detected flight and are
hidden for ordinary, unknown or ambiguous games. Detection changes restore
their original grid positions; hiding never unloads a saved plan. Watcher,
Import and progress use **Abandon game…** outside a flight context, with matching
confirmation, phase and completion text in all four languages. These actions
retain the existing local abandonment/key cleanup and leave the game running.

The observer waits three seconds between probes, including while the window is
in the tray or watching/recording/UTC sync are off. The existing Tk refresh loop
reads one cached context; it performs no native calls, file reads or networking.
Exiting stops the observer. Process inventories stay in memory and are never
logged, saved or uploaded. Missing APIs/DLLs/catalogs retain general wording as
permanent support for ordinary games and PCs without the simulator.

## Bundled catalog and source limitations

`winapp/uploader/resources/game-processes.json` is an offline snapshot of factual
names from Discord's public
[detectable games endpoint](https://discord.com/api/v10/games/detectable),
downloaded on 2026-10-29. It records the original SHA-256 and counts.
The source contained 24,957 applications; the filtered snapshot contains 10,405
games and 10,888 Windows executable rules. Records without usable Windows
executables, declared launchers, known launcher/updater/config/setup/crash
helpers and shared runtimes are excluded. Only basenames are compared,
case-insensitively, with no wildcard or substring matching. Ambiguous names
never establish a particular game.

The source is broad, incomplete and sometimes inaccurate. A process name is a
wording hint, not proof of a running playable session or executable authenticity.
The [endpoint is undocumented](https://github.com/discord/discord-api-docs/issues/1862);
its future availability is not required for the installed app. There are no
runtime downloads, Discord login or new Python dependencies.

To refresh deliberately, download the complete response to ignored cache, then
run the importer with its actual download date:

```powershell
Invoke-WebRequest https://discord.com/api/v10/games/detectable `
  -OutFile cache/game-presence-source/discord-games.json
python scripts/import_winapp_game_catalog.py `
  cache/game-presence-source/discord-games.json `
  winapp/uploader/resources/game-processes.json --retrieved-date 2026-10-29
```

Review the resulting source, exclusions, counts and changed names; do not ship
the raw download. The importer is an explicit maintenance tool and never runs
from the app. Installer generation already includes the resources directory.

## Add a game or phrase without rebuilding

Bundled reviewed overrides live in
`winapp/uploader/resources/game-contexts.json`. For personal additions, create
`%APPDATA%\PHPGalleryUploader\game-contexts.json` and restart the app:

```json
{
  "schema_version": 1,
  "games": [
    {
      "id": "my-game",
      "name": "My game",
      "executables": ["mygame.exe"],
      "begin": {"en": "Let's begin…", "cs": "Jdeme na to…"},
      "finish": {"en": "Until next time…", "cs": "Zase příště…"}
    }
  ]
}
```

Use exact executable basenames, including `.exe`; paths and wildcards are
ignored. `id` groups processes into one game/family. `name` is a descriptive
title. `activity` defaults to `game`; `flight` selects standard flight labels
without custom phrases. `begin` and `finish` are optional objects with
`en`, `cs`, `de`, `sv` strings. Missing translations use English, then ordinary
game/flight labels. Phrases must be single-line and at most 64 Unicode
characters so the existing compact controls remain usable.

Rules load once per app run. Per-user entries override bundled entries for
their listed process names; omitted processes retain their defaults. Copy an
existing bundled entry and keep its ID when extending that family. A missing or
malformed custom file leaves defaults intact; malformed rows are skipped.
The file is ordinary data, cannot execute commands and needs no admin rights.
The profile survives upgrades. Initial easter eggs cover Cyberpunk 2077,
the original/Legendary Mass Effect trilogy, Skyrim and the four Dragon Age
protagonists; both Begin and Finish are localized in all four interface languages.
