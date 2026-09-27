@echo off

rem Project: PHP Gallery
rem Module Type: Windows Tooling
rem Purpose: Start the isolated Windows installer build with the selected Python.
rem Responsibilities:
rem   - Resolve Python and propagate the build result to the calling shell.
rem Repository: https://github.com/klusik/PHP_gallery
rem
rem File: winapp/build.bat
rem
rem Author:
rem   Rudolf Klusal
rem
rem License:
rem   MIT License (see LICENSE file in repository)

setlocal
rem Build the standalone uploader and its Inno Setup installer.
rem PHP_GALLERY_PYTHON may select an exact 64-bit Python executable.
if defined PHP_GALLERY_PYTHON goto selected_python
where py >nul 2>nul
if errorlevel 1 goto path_python
py -3 "%~dp0build_installer.py" %*
exit /b %errorlevel%

:selected_python
"%PHP_GALLERY_PYTHON%" "%~dp0build_installer.py" %*
exit /b %errorlevel%

:path_python
python "%~dp0build_installer.py" %*
exit /b %errorlevel%
