# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/frozen_windows_libraries.py
# Module Type: Windows packaging support
# Purpose: Resolve imported Microsoft VC14x DLLs from licensed build tools.
# Responsibilities: Inspect frozen PE imports, add app-local CRT files and
#   record compact source, digest and redistribution provenance.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)

"""Make only PE-imported Microsoft VC14x runtime DLLs app-local."""

import argparse
import hashlib
import os
from pathlib import Path
import re
import shutil
import subprocess
import sys
from typing import Iterable

import pefile

# Purpose: Identify the app-local x64 CRT files with imports in bundled PEs.
# Type: re.Pattern[str]. Units: DLL basenames. Scope: Microsoft VC14x only.
# Consumers: _is_vc14x_runtime.
# Rationale: Match redistributable v14 runtime DLL families, not system or
#   third-party DLLs with unrelated names.
VC14X_RUNTIME_PATTERN = re.compile(
    r"^(?:vcruntime|msvcp|concrt|vcomp)140(?:_[0-9]+)?"
    r"(?:_[a-z0-9]+)*\.dll$",
    re.IGNORECASE,
)

# Purpose: Refuse impractically large or unrelated PyInstaller payload walks.
# Type: int. Units: PE files. Scope: one frozen bundle inspection.
# Consumers: inspect_bundle_imports.
# Rationale: 20,000 executables and native libraries exceed this app's runtime
#   inventory while bounding accidental recursive scans.
PE_FILE_LIMIT = 20_000

# Purpose: Keep Visual Studio discovery from blocking a package build.
# Type: int. Units: seconds. Scope: one vswhere query.
# Consumers: _visual_studio_redist_roots.
# Rationale: A local installer query should return promptly; timeout fails closed.
VSWHERE_TIMEOUT_SECONDS = 10

# Purpose: Identify x64 PE files expected in this Windows installer build.
# Type: int. Units: IMAGE_FILE_HEADER.Machine value. Scope: bundled PE files.
# Consumers: inspect_bundle_imports.
# Rationale: The installer and WinApp build target x64-compatible Windows.
EXPECTED_PE_MACHINE = 0x8664

# Purpose: Limit the generated attribution exhibit to a compact build record.
# Type: int. Units: bytes. Scope: one provenance document.
# Consumers: write_provenance.
# Rationale: The exhibit contains paths, DLL names and hashes only.
PROVENANCE_MAX_BYTES = 65_536

# Purpose: Bound memory used to hash each redistributable DLL.
# Type: int. Units: bytes per read. Scope: one SHA-256 stream read.
# Consumers: _sha256. Rationale: 1 MiB is ample for sequential file I/O.
HASH_READ_CHUNK_BYTES = 1_048_576

VISUAL_STUDIO_REDIST_TERMS = (
    "https://learn.microsoft.com/en-us/cpp/windows/redistributing-visual-cpp-files"
    "?view=msvc-170"
)
VISUAL_STUDIO_REDIST_LIST = (
    "https://learn.microsoft.com/en-us/visualstudio/releases/2022/redistribution"
)


def _is_vc14x_runtime(name: str) -> bool:
    """Return whether a DLL basename belongs to the VC14x runtime families.

    Args:
        name (str): Imported native DLL basename.
    Returns:
        bool: True for supported v14 runtime DLL naming families.
    """
    return VC14X_RUNTIME_PATTERN.fullmatch(name) is not None


def _pe_files(bundle: Path) -> list[Path]:
    """Collect the bounded set of actual PE image candidates in a bundle.

    Args:
        bundle (Path): Frozen application directory containing _internal.
    Returns:
        list[Path]: Sorted .exe, .pyd and .dll paths under the bundle.
    Raises:
        OSError: The bundle or its native files cannot be enumerated.
        ValueError: The bundle shape or native file count is unsupported.
    """
    internal = bundle / "_internal"
    if not bundle.is_dir() or not internal.is_dir():
        raise ValueError("Expected a PyInstaller onedir bundle with _internal.")
    files: list[Path] = []
    for path in bundle.rglob("*"):
        if path.is_file() and path.suffix.casefold() in {".exe", ".pyd", ".dll"}:
            files.append(path)
            if len(files) > PE_FILE_LIMIT:
                raise ValueError("Frozen bundle exceeds its native PE file limit.")
    if not files:
        raise ValueError("Frozen bundle contains no PE image candidates.")
    return sorted(files, key=lambda path: str(path).casefold())


def inspect_bundle_imports(bundle: Path) -> tuple[set[str], list[Path]]:
    """Read normal and delayed imports from every bundled x64 PE image.

    Args:
        bundle (Path): Frozen application directory containing _internal.
    Returns:
        tuple[set[str], list[Path]]: Lowercase imported VC14x DLL names and
            the inspected native PE paths.
    Raises:
        OSError: A PE image cannot be read.
        ValueError: A candidate is malformed or is not an x64 PE image.
    """
    required: set[str] = set()
    files = _pe_files(bundle)
    import_directories = (
        pefile.DIRECTORY_ENTRY["IMAGE_DIRECTORY_ENTRY_IMPORT"],
        pefile.DIRECTORY_ENTRY["IMAGE_DIRECTORY_ENTRY_DELAY_IMPORT"],
    )
    for path in files:
        try:
            image = pefile.PE(str(path), fast_load=True)
            image.parse_data_directories(directories=list(import_directories))
        except (OSError, pefile.PEFormatError) as error:
            raise ValueError("Invalid bundled PE image: " + path.name) from error
        try:
            if image.FILE_HEADER.Machine != EXPECTED_PE_MACHINE:
                raise ValueError("Bundled PE image is not x64: " + path.name)
            for directory_name in (
                "DIRECTORY_ENTRY_IMPORT", "DIRECTORY_ENTRY_DELAY_IMPORT",
            ):
                for entry in getattr(image, directory_name, ()):
                    imported_name = entry.dll.decode("ascii", errors="replace")
                    basename = Path(imported_name).name.casefold()
                    if _is_vc14x_runtime(basename):
                        required.add(basename)
        finally:
            image.close()
    return required, files


def _msvc_redist_root(path: Path) -> Path | None:
    """Resolve a path only when it sits under a Visual Studio VC redist tree.

    Args:
        path (Path): Environment hint or Visual Studio installation path.
    Returns:
        Path | None: Existing ``VC/Redist/MSVC`` directory, or None when the
            path is not an ordinary tree with the expected layout.
    """
    try:
        resolved = path.resolve(strict=True)
    except OSError:
        return None
    current = resolved if resolved.is_dir() else resolved.parent
    while current != current.parent:
        if (current.name.casefold() == "msvc"
                and current.parent.name.casefold() == "redist"
                and current.parent.parent.name.casefold() == "vc"
                and current.is_dir()):
            return current
        current = current.parent
    return None


def _vswhere_paths() -> tuple[Path, ...]:
    """Find installed Visual Studio roots through its official vswhere utility.

    Returns:
        tuple[Path, ...]: Installation roots reported by vswhere, or an empty
            tuple when the installer utility is unavailable or fails.
    """
    program_files = os.environ.get("ProgramFiles(x86)")
    if not program_files:
        return ()
    vswhere = Path(program_files) / "Microsoft Visual Studio" / "Installer" / "vswhere.exe"
    if not vswhere.is_file():
        return ()
    try:
        result = subprocess.run(
            [str(vswhere), "-all", "-products", "*", "-property", "installationPath"],
            check=False, capture_output=True, text=True,
            timeout=VSWHERE_TIMEOUT_SECONDS, shell=False,
        )
    except (OSError, subprocess.TimeoutExpired):
        return ()
    if result.returncode != 0:
        return ()
    return tuple(
        Path(line.strip()) for line in result.stdout.splitlines() if line.strip()
    )


def visual_studio_redist_roots() -> tuple[Path, ...]:
    """Resolve trusted build-tool redist roots without inspecting system DLLs.

    Returns:
        tuple[Path, ...]: De-duplicated VC redist roots, newest version last
            resolution is handled by the versioned candidate ordering.
    """
    roots: list[Path] = []
    environment_hint = os.environ.get("VCToolsRedistDir")
    if environment_hint:
        root = _msvc_redist_root(Path(environment_hint))
        if root is not None:
            roots.append(root)
    for installation in _vswhere_paths():
        root = _msvc_redist_root(installation / "VC" / "Redist" / "MSVC")
        if root is not None:
            roots.append(root)
    unique: dict[str, Path] = {}
    for root in roots:
        unique[os.path.normcase(str(root))] = root
    return tuple(unique.values())


def _version_key(path: Path) -> tuple[int, ...]:
    """Sort a Visual Studio redist version directory numerically.

    Args:
        path (Path): Candidate version directory.
    Returns:
        tuple[int, ...]: Numeric components, or an empty tuple for invalid names.
    """
    if re.fullmatch(r"\d+(?:\.\d+){1,3}", path.name) is None:
        return ()
    return tuple(int(part) for part in path.name.split("."))


def _redist_directories(root: Path) -> list[Path]:
    """List x64 release CRT directories beneath one verified VS tree.

    Args:
        root (Path): Existing VC/Redist/MSVC directory.
    Returns:
        list[Path]: Newest-first x64 Microsoft.VC*.CRT directories.
    """
    versions = sorted(
        (path for path in root.iterdir() if path.is_dir()),
        key=_version_key,
        reverse=True,
    )
    directories: list[Path] = []
    for version in versions:
        x64 = version / "x64"
        if not x64.is_dir():
            continue
        directories.extend(sorted(
            (path for path in x64.glob("Microsoft.VC*.CRT") if path.is_dir()),
            key=lambda path: path.name.casefold(),
        ))
    return directories


def _available_in_bundle(bundle: Path, name: str) -> Path | None:
    """Find a direct app-local copy in the exe or PyInstaller runtime folder.

    Args:
        bundle (Path): Frozen application directory.
        name (str): Lowercase imported DLL basename.
    Returns:
        Path | None: Direct app-local file that the Windows loader can resolve.
    """
    for candidate in (bundle / "_internal" / name, bundle / name):
        if candidate.is_file():
            return candidate
    return None


def resolve_runtime_sources(required: set[str]) -> tuple[dict[str, Path], str]:
    """Resolve every imported VC DLL from a VS redist directory.

    Args:
        required (set[str]): Lowercase VC14x DLL imports from bundled PEs.
    Returns:
        tuple[dict[str, Path], str]: Source paths keyed by imported DLL name
            and the selected Visual Studio redist tree.
    Raises:
        FileNotFoundError: No licensed Visual Studio tree supplies an import.
    """
    if not required:
        return {}, "no VC14x imports detected"
    for root in visual_studio_redist_roots():
        for directory in _redist_directories(root):
            sources = {name: directory / name for name in required}
            if all(path.is_file() for path in sources.values()):
                return sources, str(directory)
    names = ", ".join(sorted(required))
    raise FileNotFoundError(
        "Visual Studio x64 VC redist files are missing for imports: " + names,
    )


def _sha256(path: Path) -> str:
    """Hash one DLL using bounded streaming reads.

    Args:
        path (Path): Existing regular runtime file.
    Returns:
        str: Lowercase SHA-256 digest.
    Raises:
        OSError: The file cannot be read.
    """
    digest = hashlib.sha256()
    with path.open("rb") as source:
        for chunk in iter(lambda: source.read(HASH_READ_CHUNK_BYTES), b""):
            digest.update(chunk)
    return digest.hexdigest()


def write_provenance(
    licenses: Path, source_directory: str, files: dict[str, Path],
) -> Path:
    """Write a compact redistribution source and digest exhibit.

    Args:
        licenses (Path): Build licenses directory adjacent to the app runtime.
        source_directory (str): Selected Visual Studio redist source or existing
            app-local status.
        files (dict[str, Path]): Exact imported DLL names and source paths.
    Returns:
        Path: Written provenance exhibit.
    Raises:
        OSError: The exhibit cannot be created.
        ValueError: Its content exceeds the declared byte budget.
    """
    lines = [
        "Microsoft VC14x app-local runtime provenance",
        "Source: " + source_directory,
        "Redistribution terms: " + VISUAL_STUDIO_REDIST_TERMS,
        "Visual Studio distributable list: " + VISUAL_STUDIO_REDIST_LIST,
        "Redistribution assumes a licensed Visual Studio build environment.",
        "Only x64 VC14x DLLs imported by bundled PE files are recorded.",
    ]
    for name, path in sorted(files.items()):
        lines.append(f"{name}  sha256={_sha256(path)}")
    payload = ("\n".join(lines) + "\n").encode("utf-8")
    if len(payload) > PROVENANCE_MAX_BYTES:
        raise ValueError("VC runtime provenance exhibit exceeds its byte limit.")
    licenses.mkdir(parents=True, exist_ok=True)
    destination = licenses / "VC-REDIST-PROVENANCE.txt"
    if destination.exists() or destination.is_symlink():
        raise FileExistsError("VC runtime provenance exhibit already exists.")
    destination.write_bytes(payload)
    return destination


def prepare_bundle(bundle: Path) -> Path:
    """Copy imported x64 CRT DLLs and record verified app-local provenance.

    Args:
        bundle (Path): Built PyInstaller onedir directory.
    Returns:
        Path: Provenance exhibit under the bundle's licenses directory.
    Raises:
        FileNotFoundError: Required DLLs are absent from both bundle and VS.
        OSError: PE or runtime files cannot be accessed or copied.
        ValueError: Bundle PE format, architecture or constraints are invalid.
    """
    required, _ = inspect_bundle_imports(bundle)
    sources, source_directory = resolve_runtime_sources(required)
    runtime = bundle / "_internal"
    runtime.mkdir(parents=True, exist_ok=True)
    provenance_sources: dict[str, Path] = {}
    for name, source in sources.items():
        destination = runtime / name
        if destination.is_symlink():
            raise ValueError("Runtime destination is redirected: " + name)
        shutil.copyfile(source, destination)
        if _sha256(destination) != _sha256(source):
            raise OSError("App-local runtime copy did not preserve its source digest.")
        provenance_sources[name] = destination
    for name in required:
        local = _available_in_bundle(bundle, name)
        if local is None:
            raise FileNotFoundError("Required runtime DLL is not app-local: " + name)
        if local != runtime / name:
            raise ValueError("Required runtime DLL was not placed in _internal: " + name)
    return write_provenance(
        bundle / "licenses", source_directory, provenance_sources,
    )


def main(arguments: Iterable[str] | None = None) -> int:
    """Provide a Windows build CLI for the onedir runtime preparation.

    Args:
        arguments (Iterable[str] | None): Optional CLI arguments for controlled
            packaging callers.
    Returns:
        int: Zero after the bounded dependency audit and provenance write.
    """
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("bundle", type=Path, help="Built PyInstaller onedir directory")
    options = parser.parse_args(list(arguments) if arguments is not None else None)
    if sys.platform != "win32":
        parser.error("VC runtime preparation is supported only on Windows builds.")
    try:
        exhibit = prepare_bundle(options.bundle.resolve(strict=True))
    except (OSError, ValueError) as error:
        parser.error(str(error))
    print(str(exhibit), flush=True)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
