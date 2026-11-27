# Project: PHP Gallery
# Module Type: Windows Tooling
# Purpose: Build the standalone uploader and its Inno Setup installer.
# Responsibilities:
#   - Isolate build dependencies, publish paired release files and clean staging.
# Repository: https://github.com/klusik/PHP_gallery
#
# File: winapp/build_installer.py
#
# Author:
#   Rudolf Klusal
#
# License:
#   MIT License (see LICENSE file in repository)
"""Build a Windows installer with matching JSON metadata; discard build staging."""

import argparse
import hashlib
import json
import os
from pathlib import Path
import platform
import re
import shutil
import struct
import subprocess
import sys
import tempfile

from qt_installer_tools import installed_error_dialog, verify_installation
from uploader.runtime_check import runtime_info, validate_runtime_info
from uploader.update_helper import stage_update_helper_runtime


WINAPP_DIR = Path(__file__).resolve().parent
APP_EXE = "PHPGalleryUploader.exe"
SIMCONNECT_BUNDLE_PATH = "runtime/simconnect/SimConnect.dll"
SIMCONNECT_EXPORTS = {
    "SimConnect_Open", "SimConnect_Close", "SimConnect_CallDispatch",
    "SimConnect_GetLastSentPacketID", "SimConnect_AddToDataDefinition",
    "SimConnect_RequestDataOnSimObject", "SimConnect_CameraAcquire",
    "SimConnect_CameraRelease", "SimConnect_CameraGetStatus", "SimConnect_CameraGet",
    "SimConnect_SubscribeToSystemEvent", "SimConnect_RequestSystemState",
    "SimConnect_MapClientEventToSimEvent", "SimConnect_TransmitClientEvent",
}

# Type: int; Units: seconds; Scope: build; Consumers: frozen runtime smoke.
# Rationale: 60 seconds bounds cold runtime startup and attestation.
FROZEN_RUNTIME_TIMEOUT_SECONDS = 60


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


def verify_frozen_simconnect(bundle: Path, expected_sha256: str) -> None:
    """Check the actual app-local SimConnect payload before building Setup.

    Args:
        bundle (Path): The one complete staged PyInstaller directory.
        expected_sha256 (str): SHA-256 hex digest of the validated source DLL.
    Returns:
        None: Exactly the validated runtime DLL is present in its approved slot.
    Raises:
        RuntimeError: The bundled location, uniqueness or payload is invalid.
    """
    target = bundle / "_internal" / SIMCONNECT_BUNDLE_PATH
    if tuple(bundle.rglob("SimConnect.dll")) != (target,) or target.is_symlink():
        raise RuntimeError("Bundled SimConnect.dll is missing, duplicated or redirected.")
    if hashlib.sha256(target.read_bytes()).hexdigest() != expected_sha256:
        raise RuntimeError("Bundled SimConnect.dll hash mismatch.")


def verify_frozen_directory(bundle: Path) -> None:
    """Require exactly one application and one bundled versioned Python runtime.

    Args:
        bundle (Path): The staged common onedir payload used by the installer.
    Returns:
        None: Both frontends' libraries and the project QML source are present.
    Raises:
        RuntimeError: The application, versioned Python or a required GUI file
            is missing or duplicated.
    """
    runtime = bundle / "_internal"
    python_dll = f"python{sys.version_info.major}{sys.version_info.minor}.dll"
    if tuple(bundle.rglob("*.exe")) != (bundle / APP_EXE,):
        raise RuntimeError("Expected only the registered WinApp executable.")
    versioned_runtimes = tuple(
        path for path in bundle.rglob("*.dll")
        if re.fullmatch(r"python\d{2,}\.dll", path.name.casefold())
    )
    if versioned_runtimes != (runtime / python_dll,):
        raise RuntimeError("Expected one versioned Python runtime beside the shared dependencies.")
    for filename in ("qwindows.dll", "qjpeg.dll", "qwebp.dll", "Qt6Core.dll", "Qt6Quick.dll"):
        if len(tuple(runtime.rglob(filename))) != 1:
            raise RuntimeError("Expected one app-local Qt runtime file: " + filename)
    if not (runtime / "uploader" / "resources" / "qml" / "Photos.qml").is_file():
        raise RuntimeError("The shared WinApp bundle omitted its Photos QML scene.")


def run_frozen_smoke(arguments: list[str], work: Path, environment: dict[str, str]) -> None:
    """Exercise one frozen command and expose any native failure to the build log.

    Args:
        arguments (list[str]): Freshly built launcher and isolated smoke arguments.
        work (Path): Current builder-owned staging directory.
        environment (dict[str, str]): Disposable smoke environment and profile.
    Returns:
        None: The exact frozen command exited successfully.
    Raises:
        RuntimeError: The command timed out, including any owned native dialog.
        subprocess.CalledProcessError: The command failed without waiting.
    """
    with subprocess.Popen(arguments, cwd=work, env=environment) as child:
        try:
            status = child.wait(timeout=FROZEN_RUNTIME_TIMEOUT_SECONDS)
        except subprocess.TimeoutExpired as error:
            try:
                explanation = installed_error_dialog(child.pid)
            finally:
                child.kill()
                child.wait()
            raise RuntimeError(f"Frozen {arguments[1]} smoke timed out. {explanation}") from error
        if status != 0:
            raise subprocess.CalledProcessError(status, arguments)


def installed_dependencies_code() -> str:
    """Check existing build packages without installing or changing anything.

    Return subprocess source that enforces the exact qualified package versions,
    imports Tk and Qt components, and creates a Tcl interpreter without opening
    a window. Missing packages, unsupported versions,
    or broken imports fail before PyInstaller starts. This probe never invokes
    pip, creates a venv, downloads packages, or alters installed dependencies.

    Returns:
        str: Subprocess source validating the declared frozen-runtime packages.
    """
    requirements = WINAPP_DIR / "requirements-build.txt"
    freeze_pin = re.search(
        r"^PyInstaller==(\d+\.\d+\.\d+)$", requirements.read_text(encoding="utf-8"), re.M,
    )
    if freeze_pin is None:
        raise RuntimeError("The installer requires one exact PyInstaller pin.")
    constraint_text = (WINAPP_DIR / "constraints.txt").read_text(encoding="utf-8")
    constraint_pins = {
        match.group(1).casefold(): match.group(2)
        for match in re.finditer(
            r"^([A-Za-z0-9_.-]+)==([A-Za-z0-9_.+-]+)(?:\s*;.*)?$",
            constraint_text, re.M,
        )
    }
    qt_text = (WINAPP_DIR / "requirements-qt.txt").read_text(encoding="utf-8")
    qt_pins = {
        match.group(1).casefold(): match.group(2)
        for match in re.finditer(
            r"^([A-Za-z0-9_.-]+)==([A-Za-z0-9_.+-]+)$", qt_text, re.M,
        )
    }
    package_names = (
        "Pillow", "pystray", "reportlab", "pypdf", "polib",
        "PySide6-Essentials", "shiboken6", "pyinstaller-hooks-contrib",
        "pefile", "pywin32-ctypes", "altgraph", "packaging",
        "charset-normalizer", "six", "setuptools",
    )
    all_pins = {**constraint_pins, **qt_pins}
    expected = {
        name: (freeze_pin.group(1) if name == "PyInstaller" else all_pins.get(name.casefold()))
        for name in (*package_names, "PyInstaller")
    }
    if any(value is None for value in expected.values()):
        raise RuntimeError("Build requirements are missing an exact qualified package pin.")
    return (
        "import sys, tkinter, tkinter.ttk; from importlib.metadata import version; "
        f"expected = {expected!r}; "
        "actual = {name: version(name) for name in expected}; "
        "invalid = [name for name, value in expected.items() if actual[name] != value]; "
        "not invalid or sys.exit('Unsupported installed build dependencies: ' + ', '.join(invalid)); "
        "import PIL.Image, pystray, PyInstaller, reportlab, pypdf, polib, shiboken6; "
        "from PySide6 import QtCore, QtGui, QtQml, QtQuick, QtWidgets; "
        "root = tkinter.Tcl(); print('Installed build dependencies OK')"
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


def build_installer(
    iscc: str | Path,
    source: Path = WINAPP_DIR,
    use_installed_dependencies: bool = False,
    *,
    verify_installed: bool = False,
) -> Path:
    """Publish a complete installer and matching metadata in one version folder.

    ``iscc`` selects an existing Inno Setup compiler and ``source`` identifies
    the WinApp source directory. The default builds in a temporary venv;
    ``use_installed_dependencies`` instead validates and reuses this process's
    interpreter without installing anything, including its installed user-site
    packages. Both modes isolate custom Python paths, caches, smoke-test profile,
    and intermediate outputs in an owned temporary directory on the destination
    volume. Only the default venv mode disables user-site package discovery.

    Validate the source SimConnect runtime, verify its digest in the app-local
    bundle, and freeze both frontends against the same Python and dependencies.
    Validate x64 CPython 3.14.x in both this interpreter and the actual frozen
    executable before invoking Inno Setup; their concrete patch versions are
    printed separately. A missing/incorrect frozen report refuses publication.
    Return the installer Path under dist/<version>, beside winapp-update.json
    containing its filename, version, byte size and SHA-256 digest. Source files
    and the DLL are read only; final files are replaced after every check passes.
    OSError, RuntimeError, and subprocess errors propagate after staging cleanup,
    restoring the previous installer if metadata publication fails. Other
    versions, historical dist-root artifacts and unrelated files are untouched.

    Args:
        iscc (str | Path): Existing Inno Setup compiler executable.
        source (Path): WinApp source directory containing VERSION and its DLL.
        use_installed_dependencies (bool): Reuse checked packages without downloads.
        verify_installed (bool): Install and exercise the requested variant on
            a disposable Windows machine owned by the hosted audit.

    Returns:
        Path: Published installer beside its matching update metadata.
    """
    source = Path(source).resolve()
    version = (source / "VERSION").read_text(encoding="utf-8").strip()
    if not re.fullmatch(r"\d+\.\d+\.\d+", version) or any(int(p) > 65535 for p in version.split(".")):
        raise RuntimeError("winapp/VERSION must contain a numeric major.minor.patch version.")
    runtime_hash = validate_simconnect_runtime(source / "SimConnect.dll")
    print("Build interpreter: " + json.dumps(runtime_info()), flush=True)
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

            Args:
                arguments (list[str|Path]): Ordered executable and arguments.

            Returns:
                None: Runs successfully or propagates the subprocess error.
            """
            subprocess.run([str(arg) for arg in arguments], cwd=work, env=env, check=True)

        print(f"Building PHP Gallery Uploader {version} with {iscc}", flush=True)
        if use_installed_dependencies:
            python = sys.executable
        else:
            run([sys.executable, "-m", "venv", work / "venv"])
            python = work / "venv" / "Scripts" / "python.exe"
            run([python, "-m", "pip", "install", "-r", source / "requirements-build.txt"])
        run([python, "-m", "pip", "check"])
        run([python, "-c", installed_dependencies_code()])
        versions_code = (
            "import json, sys; sys.path.insert(0, " + repr(str(source)) + "); "
            "from uploader.dependency_versions import dependency_versions; "
            "print('WinApp dependency versions: ' + json.dumps("
            "dependency_versions(), sort_keys=True, separators=(',', ':')))"
        )
        run([python, "-c", versions_code])
        notices = work / "qt-notices"
        run([python, source / "qt_installer_tools.py", notices])
        version_file = work / "version-info.txt"
        write_version_resource(version_file, version)
        env["PHP_GALLERY_VERSION_RESOURCE"] = str(version_file)
        run([
            python, "-m", "PyInstaller", "--noconfirm", "--clean",
            "--distpath", work / "app", "--workpath", work / "pyinstaller-work",
            source / "uploader.spec",
        ])
        bundle = work / "app" / Path(APP_EXE).stem
        app = bundle / APP_EXE
        verify_frozen_directory(bundle)
        verify_frozen_simconnect(bundle, runtime_hash)
        shutil.copytree(notices, bundle / "licenses")
        run([python, source / "frozen_windows_libraries.py", bundle])
        runtime_report = work / "frozen-runtime.json"
        run_frozen_smoke(
            [str(app), "--runtime-info", str(runtime_report)],
            work, {**env, "APPDATA": str(work / "smoke-profile")},
        )
        frozen_runtime = json.loads(runtime_report.read_text(encoding="utf-8"))
        validate_runtime_info(frozen_runtime)
        print("Frozen interpreter: " + json.dumps(frozen_runtime), flush=True)
        # Import/startup smoke check without opening the GUI or reading the
        # developer's saved gallery credentials. Logs stay inside staging.
        run_frozen_smoke(
            [str(app), "--help"], work,
            {**env, "APPDATA": str(work / "smoke-profile")},
        )
        # Exercise the real frozen native version reader from a copied helper,
        # before setup packaging. This never installs or starts the application UI.
        helper_directory = work / "helper-smoke"
        helper_directory.mkdir()
        helper = helper_directory / "helper.exe"
        stage_update_helper_runtime(app, helper)
        run_frozen_smoke(
            [str(helper), "--self-update-smoke"], work,
            {**env, "APPDATA": str(work / "smoke-profile"), "PYINSTALLER_RESET_ENVIRONMENT": "1",
             "LOCALAPPDATA": str(work / "smoke-local-profile")},
        )
        notice = source / "QT-LICENSE.txt"
        installer_license = work / "setup-license.txt"
        # Keep the existing license step and append the component notice there.
        installer_license.write_text(
            (source.parent / "LICENSE").read_text(encoding="utf-8").rstrip()
            + "\n\n" + notice.read_text(encoding="utf-8"),
            encoding="utf-8-sig",
        )
        run([
            iscc, f"/DAppVersion={version}", f"/DSourceRoot={source}",
            f"/DAppBundle={bundle}", f"/DQtNotice={notice}",
            f"/DInstallerLicense={installer_license}",
            f"/DInstallerOutput={work / 'installer'}",
            source / "installer.iss",
        ])
        installer = work / "installer" / output_name
        if not installer.is_file() or installer.stat().st_size == 0:
            raise RuntimeError("Inno Setup did not produce the installer EXE.")
        if verify_installed:
            verify_installation(installer, work, env, version=version)
        digest = hashlib.sha256()
        with installer.open("rb") as handle:
            while chunk := handle.read(1024 * 1024):
                digest.update(chunk)
        metadata = installer.with_name("winapp-update.json")
        metadata.write_text(json.dumps({"assets": [{
            "name": output_name,
            "version": version,
            "size": installer.stat().st_size,
            "sha256": digest.hexdigest(),
        }]}, indent=2) + "\n", encoding="utf-8")
        output = source / "dist" / version / output_name
        output.parent.mkdir(parents=True, exist_ok=True)
        previous = work / "previous-installer.exe"
        if output.exists():
            shutil.copyfile(output, previous)
        # Prepare both files before publishing. If the JSON cannot be replaced,
        # restore the old EXE so its existing metadata remains a matching pair.
        os.replace(installer, output)
        try:
            os.replace(metadata, output.with_name(metadata.name))
        except OSError:
            if previous.exists():
                os.replace(previous, output)
            else:
                output.unlink()
            raise
    return output


def main() -> int:
    """Require Windows x64 Python and report build failures with a nonzero exit.

    Returns:
        int: Zero on successful publication, one on a refused or failed build.
    """
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--iscc", help="Path to the installed Inno Setup ISCC.exe")
    parser.add_argument(
        "--verify-installed", action="store_true",
        help="Install and verify the requested setup on a disposable Windows runner",
    )
    parser.add_argument(
        "--use-installed-dependencies", action="store_true",
        help="Use checked packages in the selected Python without installing dependencies",
    )
    args = parser.parse_args()
    try:
        if sys.platform != "win32" or struct.calcsize("P") != 8 or platform.machine().lower() not in {"amd64", "x86_64"}:
            raise RuntimeError("Build with 64-bit x64 CPython 3.14 on Windows.")
        if sys.version_info[:2] != (3, 14):
            raise RuntimeError("Build with the qualified CPython 3.14 series.")
        output = build_installer(
            find_iscc(args.iscc), use_installed_dependencies=args.use_installed_dependencies,
            verify_installed=args.verify_installed,
        )
    except (OSError, RuntimeError, subprocess.SubprocessError) as exc:
        print(f"Build failed: {exc}", file=sys.stderr)
        return 1
    descriptor = output.with_name("winapp-update.json")
    print(f"Installer ready: {output}\nSetup metadata ready: {descriptor}\nTemporary build files removed.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
