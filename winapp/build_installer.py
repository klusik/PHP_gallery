# Project: PHP Gallery
# Module Type: Windows Tooling
# Purpose: Build the standalone uploader and its Inno Setup installer.
# Responsibilities:
#   - Isolate build dependencies, publish the installer and clean temporary files.
# Repository: https://github.com/klusik/PHP_gallery
#
# File: winapp/build_installer.py
#
# Author:
#   Rudolf Klusal
#
# License:
#   MIT License (see LICENSE file in repository)
"""Build one Windows installer; temporary dependencies and outputs are discarded."""

import argparse
import hashlib
import os
from pathlib import Path
import platform
import re
import shutil
import struct
import subprocess
import sys
import tempfile


WINAPP_DIR = Path(__file__).resolve().parent
APP_EXE = "PHPGalleryUploader.exe"
SIMCONNECT_ARCHIVE_PATH = "runtime/simconnect/SimConnect.dll"
SIMCONNECT_EXPORTS = {
    "SimConnect_Open", "SimConnect_Close", "SimConnect_CallDispatch",
    "SimConnect_GetLastSentPacketID", "SimConnect_AddToDataDefinition",
    "SimConnect_RequestDataOnSimObject", "SimConnect_CameraAcquire",
    "SimConnect_CameraRelease", "SimConnect_CameraGetStatus", "SimConnect_CameraGet",
}


def validate_simconnect_runtime(path):
    """Require the bundled x64 DLL to retain transport and camera exports.

    ``path`` identifies the repository-owned DLL that will be embedded unchanged.
    Return its SHA256 hex digest after checking PE32+ architecture, DLL flags,
    and the exports needed by both aircraft transport and the existing 2024
    camera strategy. Camera functions are optional when loading a user-selected
    runtime, but this distribution must preserve the known camera capability.

    Read only the supplied file. Raise OSError for inaccessible input and
    RuntimeError for an invalid image or missing required export before any
    dependency installation, staging, or application build starts.
    """
    data = Path(path).read_bytes()
    try:
        if data[:2] != b"MZ":
            raise ValueError("missing DOS signature")
        pe = struct.unpack_from("<I", data, 60)[0]
        if data[pe:pe + 4] != b"PE\0\0":
            raise ValueError("missing PE signature")
        machine, count = struct.unpack_from("<HH", data, pe + 4)
        optional_size = struct.unpack_from("<H", data, pe + 20)[0]
        optional = pe + 24
        if machine != 0x8664 or struct.unpack_from("<H", data, optional)[0] != 0x20B:
            raise ValueError("expected x64 PE32+ DLL")
        characteristics = struct.unpack_from("<H", data, pe + 22)[0]
        if not characteristics & 0x2000:
            raise ValueError("PE image is not a DLL")
        if optional_size < 120:
            raise ValueError("missing export directory")
        export_rva, export_size = struct.unpack_from("<II", data, optional + 112)
        sections = optional + optional_size

        def offset(rva, size=1):
            """Map an export RVA range to bounded file-backed section bytes.

            Return its raw offset; reject unmapped or truncated ranges rather
            than treating virtual-only section memory as bytes in the DLL.
            """
            for index in range(count):
                section = sections + index * 40
                virtual_size, address, raw_size, raw = struct.unpack_from("<IIII", data, section + 8)
                if address <= rva and rva + size <= address + raw_size:
                    result = raw + rva - address
                    if result + size <= len(data):
                        return result
            raise ValueError("export address outside file sections")

        if not export_rva or export_size < 40:
            raise ValueError("missing export directory")
        exports = offset(export_rva, 40)
        names_count = struct.unpack_from("<I", data, exports + 24)[0]
        names_rva = struct.unpack_from("<I", data, exports + 32)[0]
        names = offset(names_rva, names_count * 4)
        available = set()
        for index in range(names_count):
            name_rva = struct.unpack_from("<I", data, names + index * 4)[0]
            start = offset(name_rva)
            end = data.find(b"\0", start, min(start + 256, len(data)))
            if end < 0:
                raise ValueError("unterminated export name")
            available.add(data[start:end].decode("ascii"))
        missing = SIMCONNECT_EXPORTS - available
        if missing:
            raise ValueError("missing exports: " + ", ".join(sorted(missing)))
    except (struct.error, ValueError, UnicodeDecodeError) as exc:
        raise RuntimeError(f"Invalid bundled SimConnect runtime: {exc}") from exc
    return hashlib.sha256(data).hexdigest()


def archive_verification_code():
    """Verify the actual onefile binary payload inside the staged build venv.

    Return Python source for the selected build interpreter, where PyInstaller
    is available. Its arguments are EXE path, normalized archive entry path,
    and the preflight SHA256 digest. CArchiveReader extracts the actual bundled
    bytes; Windows archive separators are normalized for entry selection.
    Missing/duplicate entries or changed bytes terminate the subprocess before
    Inno Setup can publish an installer. This also runs with an explicitly
    selected existing interpreter in the no-install build mode.
    """
    return (
        "import hashlib, sys; from PyInstaller.archive.readers import CArchiveReader; "
        "archive = CArchiveReader(sys.argv[1]); "
        "names = [name for name in archive.toc if name.replace('\\\\', '/') == sys.argv[2]]; "
        "len(names) == 1 or sys.exit('Bundled SimConnect.dll missing or duplicated'); "
        "payload = archive.extract(names[0]); "
        "hashlib.sha256(payload).hexdigest() == sys.argv[3] or sys.exit('Bundled SimConnect.dll hash mismatch'); "
        "print('Bundled SimConnect runtime verified')"
    )


def installed_dependencies_code():
    """Check existing build packages without installing or changing anything.

    Return subprocess source that enforces the supported PyInstaller range and
    minimum Pillow/pystray versions, imports those packages, and creates a Tcl
    interpreter without opening a window. Missing packages, unsupported versions,
    or broken imports fail before PyInstaller starts. This probe never invokes
    pip, creates a venv, downloads packages, or alters installed dependencies.
    """
    return (
        "import re, tkinter; from importlib.metadata import version; "
        "requirements = [('PyInstaller', (6, 19), (7,)), ('Pillow', (10,), None), "
        "('pystray', (0, 19, 5), None)]; "
        "versions = [(name, tuple(int(p) for p in re.match(r'^([0-9]+(?:\\.[0-9]+)*)', version(name)).group(1).split('.')), low, high) "
        "for name, low, high in requirements]; "
        "invalid = [name for name, value, low, high in versions if value < low or (high is not None and value >= high)]; "
        "not invalid or __import__('sys').exit('Unsupported installed build dependencies: ' + ', '.join(invalid)); "
        "import PIL.Image, pystray, PyInstaller; root = tkinter.Tcl(); print('Installed build dependencies OK')"
    )


def find_iscc(explicit=None):
    """Find the installed Inno compiler, allowing a path override."""
    override = explicit or os.environ.get("ISCC_EXE")
    if override:
        path = Path(override).expanduser().resolve()
        if not path.is_file():
            raise RuntimeError(f"Inno Setup compiler does not exist: {path}")
        return path
    command = shutil.which("ISCC.exe")
    if command:
        return Path(command).resolve()
    roots = [os.environ.get(name) for name in ("ProgramFiles", "ProgramFiles(x86)")]
    if os.environ.get("LOCALAPPDATA"):
        roots.append(str(Path(os.environ["LOCALAPPDATA"]) / "Programs"))
    candidates = []
    for root in roots:
        if root:
            candidates.extend(Path(root).glob("Inno Setup */ISCC.exe"))
    for path in sorted(candidates, reverse=True):
        if path.is_file():
            return path.resolve()
    raise RuntimeError("Inno Setup was not found. Pass --iscc PATH or set ISCC_EXE.")


def write_version_resource(path: Path, version: str) -> None:
    """Give the application EXE the same independent version as the installer."""
    numbers = tuple(int(part) for part in version.split(".")) + (0,)
    path.write_text(
        "VSVersionInfo(\n"
        f"  ffi=FixedFileInfo(filevers={numbers!r}, prodvers={numbers!r},\n"
        "    mask=0x3f, flags=0, OS=0x40004, fileType=0x1, subtype=0, date=(0, 0)),\n"
        "  kids=[StringFileInfo([StringTable('040904B0', [\n"
        "    StringStruct('CompanyName', 'Rudolf Klusal'),\n"
        "    StringStruct('FileDescription', 'PHP Gallery Uploader'),\n"
        f"    StringStruct('FileVersion', '{version}'),\n"
        "    StringStruct('ProductName', 'PHP Gallery Uploader'),\n"
        f"    StringStruct('ProductVersion', '{version}'),\n"
        f"    StringStruct('OriginalFilename', '{APP_EXE}')\n"
        "  ])]), VarFileInfo([VarStruct('Translation', [1033, 1200])])]\n"
        ")\n",
        encoding="utf-8",
    )


def build_installer(iscc: str | Path, source: Path = WINAPP_DIR, use_installed_dependencies: bool = False) -> Path:
    """Publish only a complete installer; clean staging on success or failure.

    ``iscc`` selects an existing Inno Setup compiler and ``source`` identifies
    the WinApp source directory. The default builds in a temporary venv;
    ``use_installed_dependencies`` instead validates and reuses this process's
    interpreter without installing anything, including its installed user-site
    packages. Both modes isolate custom Python paths, caches, smoke-test profile,
    and intermediate outputs in an owned temporary directory on the destination
    volume. Only the default venv mode disables user-site package discovery.

    Validate the source runtime, embed it at runtime/simconnect inside the
    single application EXE, verify its archived digest, and package that EXE.
    Return the published installer Path. Source files and the DLL are read only;
    only the final dist installer is atomically replaced after every check passes.
    OSError, RuntimeError, and subprocess errors propagate after staging cleanup,
    preserving any previously successful installer on failure.
    """
    source = Path(source).resolve()
    version = (source / "VERSION").read_text(encoding="utf-8").strip()
    if not re.fullmatch(r"\d+\.\d+\.\d+", version) or any(int(p) > 65535 for p in version.split(".")):
        raise RuntimeError("winapp/VERSION must contain a numeric major.minor.patch version.")
    runtime_hash = validate_simconnect_runtime(source / "SimConnect.dll")
    output_name = f"PHPGalleryUploader-{version}-Setup.exe"
    # Everything is staged on the destination volume, including the venv, pip
    # cache, PyInstaller cache/spec/work files and the unpublished installer.
    # TemporaryDirectory deletes only this uniquely owned tree, even on errors.
    with tempfile.TemporaryDirectory(prefix=".build-", dir=source) as temporary:
        work = Path(temporary).resolve()
        env = os.environ.copy()
        env.update({
            "PYTHONDONTWRITEBYTECODE": "1",
            "PYTHONNOUSERSITE": "1",
            "PIP_DISABLE_PIP_VERSION_CHECK": "1",
            "PIP_NO_CACHE_DIR": "1",
            "PYINSTALLER_CONFIG_DIR": str(work / "pyinstaller-cache"),
            "TEMP": str(work / "temp"),
            "TMP": str(work / "temp"),
        })
        if use_installed_dependencies:
            # Explicit reuse includes packages already installed for this user.
            # Remove inherited isolation too, so subprocesses see the same
            # package set as the selected interpreter's normal startup.
            env.pop("PYTHONNOUSERSITE", None)
        # A developer's custom PYTHONPATH must not leak packages into the EXE.
        env.pop("PYTHONPATH", None)
        env.pop("PYTHONHOME", None)
        # Some Windows venvs cannot locate the base interpreter's Tcl/Tk data.
        # Pass the matching data directories explicitly; PyInstaller's Tk hook
        # then collects them and supplies its own paths in the frozen process.
        for variable, pattern in (("TCL_LIBRARY", "tcl*/init.tcl"), ("TK_LIBRARY", "tk*/tk.tcl")):
            env.pop(variable, None)
            libraries = sorted((Path(sys.base_prefix) / "tcl").glob(pattern))
            if libraries:
                env[variable] = str(libraries[-1].parent)
        (work / "temp").mkdir()

        def run(arguments: list[str | Path]) -> None:
            """Run one build step in owned staging with the isolated environment.

            Convert Path arguments to strings and propagate a nonzero exit so
            the enclosing temporary-directory context cleans incomplete output.
            """
            subprocess.run([str(arg) for arg in arguments], cwd=work, env=env, check=True)

        print(f"Building PHP Gallery Uploader {version} with {iscc}", flush=True)
        if use_installed_dependencies:
            python = sys.executable
            run([python, "-c", installed_dependencies_code()])
        else:
            run([sys.executable, "-m", "venv", work / "venv"])
            python = work / "venv" / "Scripts" / "python.exe"
            run([python, "-m", "pip", "install", "-r", source / "requirements-build.txt"])
            run([python, "-c", "import tkinter, PIL.Image, pystray; root = tkinter.Tcl(); print('Build dependencies OK')"])
        version_file = work / "version-info.txt"
        write_version_resource(version_file, version)
        run([
            python, "-m", "PyInstaller", "--noconfirm", "--clean", "--onefile", "--windowed",
            "--name", Path(APP_EXE).stem, "--noupx",
            "--distpath", work / "app", "--workpath", work / "pyinstaller-work",
            "--specpath", work, "--paths", source,
            "--icon", source / "assets" / "tray-icon.ico", "--version-file", version_file,
            "--add-data", f"{source / 'assets'}{os.pathsep}assets",
            "--add-data", f"{source / 'VERSION'}{os.pathsep}.",
            "--add-binary", f"{source / 'SimConnect.dll'}{os.pathsep}runtime/simconnect",
            "--hidden-import", "pystray._win32",
            "--hidden-import", "uploader.update_helper",
            source / "gallery_watch_upload.pyw",
        ])
        app = work / "app" / APP_EXE
        if not app.is_file():
            raise RuntimeError("PyInstaller did not produce the application EXE.")
        run([python, "-c", archive_verification_code(), app, SIMCONNECT_ARCHIVE_PATH, runtime_hash])
        # Import/startup smoke check without opening the GUI or reading the
        # developer's saved gallery credentials. Logs stay inside staging.
        subprocess.run(
            [str(app), "--help"], cwd=work, check=True, timeout=60,
            env={**env, "APPDATA": str(work / "smoke-profile")},
        )
        # Exercise the real frozen native version reader from a copied helper,
        # before setup packaging. This never installs or starts the application UI.
        helper_directory = work / "helper-smoke"
        helper_directory.mkdir()
        helper = helper_directory / "helper.exe"
        shutil.copyfile(app, helper)
        subprocess.run(
            [str(helper), "--self-update-smoke"], cwd=work, check=True, timeout=60,
            env={**env, "APPDATA": str(work / "smoke-profile"),
                 "LOCALAPPDATA": str(work / "smoke-local-profile")},
        )
        run([
            iscc, f"/DAppVersion={version}", f"/DSourceRoot={source}",
            f"/DAppExe={app}", f"/DInstallerOutput={work / 'installer'}",
            source / "installer.iss",
        ])
        installer = work / "installer" / output_name
        if not installer.is_file() or installer.stat().st_size == 0:
            raise RuntimeError("Inno Setup did not produce the installer EXE.")
        output = source / "dist" / output_name
        output.parent.mkdir(exist_ok=True)
        # Atomic replacement preserves an earlier successful build on failure.
        os.replace(installer, output)
    return output


def main():
    """Require Windows x64 Python and report build failures with a nonzero exit."""
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--iscc", help="Path to the installed Inno Setup ISCC.exe")
    parser.add_argument(
        "--use-installed-dependencies", action="store_true",
        help="Use checked packages in the selected Python without installing dependencies",
    )
    args = parser.parse_args()
    try:
        if sys.platform != "win32" or struct.calcsize("P") != 8 or platform.machine().lower() not in {"amd64", "x86_64"}:
            raise RuntimeError("Build with 64-bit x64 Python on Windows (Python 3.10 or newer).")
        if sys.version_info < (3, 10):
            raise RuntimeError("Python 3.10 or newer is required.")
        output = build_installer(find_iscc(args.iscc), use_installed_dependencies=args.use_installed_dependencies)
    except (OSError, RuntimeError, subprocess.SubprocessError) as exc:
        print(f"Build failed: {exc}", file=sys.stderr)
        return 1
    print(f"Installer ready: {output}\nTemporary build files removed.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
