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


def write_version_resource(path, version):
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


def build_installer(iscc, source=WINAPP_DIR):
    """Publish only a complete installer; clean staging on success or failure."""
    source = Path(source).resolve()
    version = (source / "VERSION").read_text(encoding="utf-8").strip()
    if not re.fullmatch(r"\d+\.\d+\.\d+", version) or any(int(p) > 65535 for p in version.split(".")):
        raise RuntimeError("winapp/VERSION must contain a numeric major.minor.patch version.")
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

        def run(arguments):
            subprocess.run([str(arg) for arg in arguments], cwd=work, env=env, check=True)

        print(f"Building PHP Gallery Uploader {version} with {iscc}", flush=True)
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
            "--add-binary", f"{source / 'SimConnect.dll'}{os.pathsep}.",
            "--hidden-import", "pystray._win32",
            source / "gallery_watch_upload.pyw",
        ])
        app = work / "app" / APP_EXE
        if not app.is_file():
            raise RuntimeError("PyInstaller did not produce the application EXE.")
        # Import/startup smoke check without opening the GUI or reading the
        # developer's saved gallery credentials. Logs stay inside staging.
        subprocess.run(
            [str(app), "--help"], cwd=work, check=True, timeout=60,
            env={**env, "APPDATA": str(work / "smoke-profile")},
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
    args = parser.parse_args()
    try:
        if sys.platform != "win32" or struct.calcsize("P") != 8 or platform.machine().lower() not in {"amd64", "x86_64"}:
            raise RuntimeError("Build with 64-bit x64 Python on Windows (Python 3.10 or newer).")
        if sys.version_info < (3, 10):
            raise RuntimeError("Python 3.10 or newer is required.")
        output = build_installer(find_iscc(args.iscc))
    except (OSError, RuntimeError, subprocess.SubprocessError) as exc:
        print(f"Build failed: {exc}", file=sys.stderr)
        return 1
    print(f"Installer ready: {output}\nTemporary build files removed.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
