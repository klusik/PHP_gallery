@echo off
powershell -ExecutionPolicy Bypass -File "%~dp0scripts\deploy.ps1" %*
