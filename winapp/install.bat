@echo off
setlocal EnableExtensions EnableDelayedExpansion

rem This installer creates a Windows Start Menu shortcut for the PHP Gallery
rem watched-folder uploader companion app.
rem
rem Expected layout:
rem   install.bat
rem   gallery_watch_upload.pyw
rem   run_gallery_watcher.bat
rem
rem Run this file from the folder where the uploader files are stored.
rem The installer does not copy or modify photos, gallery data, configuration,
rem API keys, or PHP Gallery server files. It only creates a Start Menu entry.
rem
rem The shortcut is installed for the current Windows user only. That keeps the
rem script suitable for normal non-admin use on Windows desktops and avoids UAC
rem prompts on machines where the user does not have administrator rights.

set "APP_NAME=PHP Gallery Watched Uploader"
set "SCRIPT_DIR=%~dp0"
set "APP_SCRIPT=%SCRIPT_DIR%gallery_watch_upload.pyw"
set "RUNNER_SCRIPT=%SCRIPT_DIR%run_gallery_watcher.bat"
set "START_MENU_DIR=%APPDATA%\Microsoft\Windows\Start Menu\Programs"
set "SHORTCUT_PATH=%START_MENU_DIR%\%APP_NAME%.lnk"

rem Normalize SCRIPT_DIR by removing a trailing backslash only for display text.
rem The original SCRIPT_DIR variable intentionally keeps the trailing backslash,
rem because Windows batch path concatenation is safer when %~dp0 is used as-is.
set "SCRIPT_DIR_DISPLAY=%SCRIPT_DIR%"
if "%SCRIPT_DIR_DISPLAY:~-1%"=="\" set "SCRIPT_DIR_DISPLAY=%SCRIPT_DIR_DISPLAY:~0,-1%"

rem Validate that the main GUI application exists before creating the shortcut.
rem A missing .pyw file would create a broken Start Menu entry, so fail early.
if not exist "%APP_SCRIPT%" (
    echo ERROR: Cannot find gallery_watch_upload.pyw.
    echo.
    echo Expected file:
    echo   "%APP_SCRIPT%"
    echo.
    echo Place install.bat next to gallery_watch_upload.pyw and run it again.
    exit /b 1
)

rem Validate the optional runner script. The shortcut can target the .pyw file
rem directly, but the runner keeps startup behavior explicit and easy to adjust.
rem If the runner is missing, the installer falls back to the .pyw file itself.
if exist "%RUNNER_SCRIPT%" (
    set "SHORTCUT_TARGET=%RUNNER_SCRIPT%"
    set "SHORTCUT_ARGUMENTS="
) else (
    set "SHORTCUT_TARGET=%APP_SCRIPT%"
    set "SHORTCUT_ARGUMENTS="
)

rem Ensure the per-user Start Menu Programs folder exists. It should normally
rem exist already, but creating it makes the installer more robust on fresh or
rem restricted Windows profiles.
if not exist "%START_MENU_DIR%" (
    mkdir "%START_MENU_DIR%"
    if errorlevel 1 (
        echo ERROR: Could not create the Start Menu folder.
        echo.
        echo Folder:
        echo   "%START_MENU_DIR%"
        exit /b 1
    )
)

rem Use PowerShell only to create the .lnk file through the standard Windows
rem WScript.Shell COM object. Batch files cannot create real .lnk shortcuts by
rem themselves without either PowerShell, VBScript, or an external utility.
rem
rem @param APP_NAME         Human-readable Start Menu entry name.
rem @param SHORTCUT_PATH    Full path of the .lnk file to create or replace.
rem @param SHORTCUT_TARGET  File started by the shortcut.
rem @param SHORTCUT_ARGS    Arguments passed to the shortcut target.
rem @param SCRIPT_DIR       Working directory for the uploader process.
set "INSTALL_PS1=%TEMP%\php_gallery_uploader_install_%RANDOM%_%RANDOM%.ps1"

> "%INSTALL_PS1%" echo $ErrorActionPreference = 'Stop'
>> "%INSTALL_PS1%" echo $shortcutPath = $env:PHPGALLERY_SHORTCUT_PATH
>> "%INSTALL_PS1%" echo $targetPath = $env:PHPGALLERY_SHORTCUT_TARGET
>> "%INSTALL_PS1%" echo $arguments = $env:PHPGALLERY_SHORTCUT_ARGUMENTS
>> "%INSTALL_PS1%" echo $workingDirectory = $env:PHPGALLERY_WORKING_DIRECTORY
>> "%INSTALL_PS1%" echo $description = 'Starts the PHP Gallery watched-folder uploader.'
>> "%INSTALL_PS1%" echo $shell = New-Object -ComObject WScript.Shell
>> "%INSTALL_PS1%" echo $shortcut = $shell.CreateShortcut($shortcutPath)
>> "%INSTALL_PS1%" echo $shortcut.TargetPath = $targetPath
>> "%INSTALL_PS1%" echo $shortcut.Arguments = $arguments
>> "%INSTALL_PS1%" echo $shortcut.WorkingDirectory = $workingDirectory
>> "%INSTALL_PS1%" echo $shortcut.Description = $description
>> "%INSTALL_PS1%" echo if (Test-Path -LiteralPath $targetPath^) { $shortcut.IconLocation = $targetPath }
>> "%INSTALL_PS1%" echo $shortcut.Save(^)

rem Pass data to PowerShell through environment variables. This avoids fragile
rem command-line quoting when paths contain spaces, parentheses, ampersands, or
rem non-English characters.
set "PHPGALLERY_SHORTCUT_PATH=%SHORTCUT_PATH%"
set "PHPGALLERY_SHORTCUT_TARGET=%SHORTCUT_TARGET%"
set "PHPGALLERY_SHORTCUT_ARGUMENTS=%SHORTCUT_ARGUMENTS%"
set "PHPGALLERY_WORKING_DIRECTORY=%SCRIPT_DIR_DISPLAY%"

powershell.exe -NoProfile -ExecutionPolicy Bypass -File "%INSTALL_PS1%"
set "POWERSHELL_EXIT=%ERRORLEVEL%"

rem Remove the temporary PowerShell file even when the shortcut creation fails.
rem Keeping the temporary script would be confusing during repeated installs.
if exist "%INSTALL_PS1%" del /f /q "%INSTALL_PS1%" >nul 2>nul

if not "%POWERSHELL_EXIT%"=="0" (
    echo ERROR: Could not create the Start Menu shortcut.
    echo.
    echo PowerShell exit code: %POWERSHELL_EXIT%
    echo Target:
    echo   "%SHORTCUT_TARGET%"
    echo Shortcut:
    echo   "%SHORTCUT_PATH%"
    exit /b %POWERSHELL_EXIT%
)

rem Confirm the shortcut exists after PowerShell reports success. This catches
rem unusual policy or filesystem situations where no error is thrown but the
rem output file is not created.
if not exist "%SHORTCUT_PATH%" (
    echo ERROR: PowerShell finished, but the shortcut was not created.
    echo.
    echo Expected shortcut:
    echo   "%SHORTCUT_PATH%"
    exit /b 1
)

echo Installed Start Menu shortcut successfully.
echo.
echo Shortcut name:
echo   %APP_NAME%
echo.
echo Shortcut path:
echo   "%SHORTCUT_PATH%"
echo.
echo Application folder:
echo   "%SCRIPT_DIR_DISPLAY%"
echo.
echo Press the Windows key and search for:
echo   %APP_NAME%

exit /b 0
