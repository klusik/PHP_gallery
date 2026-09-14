@echo off

rem Project: PHP Gallery
rem Repository: https://github.com/klusik/PHP_gallery
rem
rem File: winapp/run_gallery_watcher.bat
rem
rem Author:
rem   Rudolf Klusal
rem
rem License:
rem   MIT License (see LICENSE file in repository)
rem
rem Notes:
rem   - Keep comments and docstrings intact when modifying this file.
setlocal
cd /d "%~dp0"
start "" pythonw "%~dp0gallery_watch_upload.pyw"
