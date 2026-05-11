@echo off

rem Project: PHP Gallery
rem Repository: https://github.com/klusik/PHP_gallery
rem
rem File: deploy.bat
rem Module Type: Windows Wrapper Script
rem
rem Purpose:
rem   Provides a Windows command wrapper for PHP Gallery tooling.
rem
rem Responsibilities:
rem   - Forward command-line arguments to the main script
rem   - Keep Windows invocation simple
rem   - Ensure deployment packaging continues through the PowerShell workflow
rem   - Avoid duplicating script logic
rem
rem Author:
rem   Rudolf Klusal
rem
rem Contact:
rem   https://github.com/klusik
rem
rem License:
rem   MIT License (see LICENSE file in repository)
rem
rem Notes:
rem   - Keep comments and docstrings intact when modifying this file.
rem   - Prefer small, readable changes over broad rewrites.
rem
rem Last Updated:
rem   2026-05-04

rem This wrapper forwards command-line deployment flags to the PowerShell deploy script.
powershell -ExecutionPolicy Bypass -File "%~dp0scripts\deploy.ps1" %*
