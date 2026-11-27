# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/uploader.spec
# Module Type: Windows PyInstaller application specification
# Purpose: Freeze both WinApp frontends into one shared onedir application.
# Responsibilities: Collect the app-local dependencies, native runtimes and
#   resources required by the common launcher and its verified update helper.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)

import os
from pathlib import Path

from PyInstaller.utils.hooks import collect_all

source = Path(SPECPATH)
version_resource = os.environ["PHP_GALLERY_VERSION_RESOURCE"]
report_data, report_binaries, report_imports = collect_all("reportlab")

analysis = Analysis(
    [str(source / "gallery_watch_upload.pyw")],
    pathex=[str(source)],
    binaries=[(str(source / "SimConnect.dll"), "runtime/simconnect"), *report_binaries],
    datas=[
        (str(source / "assets"), "assets"),
        (str(source / "uploader" / "resources"), "uploader/resources"),
        (str(source / "VERSION"), "."),
        *report_data,
    ],
    hiddenimports=[
        "tkinter",
        "pystray._win32",
        "uploader.qt_application",
        "uploader.update_helper",
        *report_imports,
    ],
)
# PyInstaller's stock PySide6 hook imports QtCore before the launcher can
# select a backend. Keep its analysis and collection. The application entry
# point establishes the frozen Qt paths after identifying the supervised child.
stock_qt_hook = [entry for entry in analysis.scripts
                 if Path(entry[1]).name == "pyi_rth_pyside6.py"]
if len(stock_qt_hook) != 1:
    raise SystemExit("Unexpected PyInstaller PySide6 runtime hook inventory.")
analysis.scripts = [entry for entry in analysis.scripts if entry not in stock_qt_hook]
archive = PYZ(analysis.pure)
executable = EXE(
    archive, analysis.scripts, [],
    exclude_binaries=True,
    name="PHPGalleryUploader",
    console=False,
    icon=str(source / "assets" / "tray-icon.ico"),
    version=version_resource,
    upx=False,
)
bundle = COLLECT(
    executable, analysis.binaries, analysis.datas,
    name="PHPGalleryUploader",
    upx=False,
)
