@echo off
rem This wrapper forwards command-line deployment flags to the PowerShell deploy script.
powershell -ExecutionPolicy Bypass -File "%~dp0scripts\deploy.ps1" %*
