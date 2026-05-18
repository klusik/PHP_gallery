@echo off
setlocal EnableExtensions EnableDelayedExpansion

rem PHP Gallery Uploader installer.
rem
rem This script creates a Start Menu shortcut that starts the uploader through
rem the exact Python runtime used during dependency installation. This is needed
rem on Windows because a .pyw file association can point to a different Python
rem version, including Microsoft Store app aliases.
rem
rem @param APP_NAME Human-readable Start Menu shortcut name.
rem @param APP_SCRIPT Full path to gallery_watch_upload.pyw.
rem @param REQUIREMENTS_FILE Optional pip requirements file used for Pillow.

set "APP_NAME=PHP Gallery Uploader"
set "SCRIPT_DIR=%~dp0"
set "APP_SCRIPT=%SCRIPT_DIR%gallery_watch_upload.pyw"
set "REQUIREMENTS_FILE=%SCRIPT_DIR%requirements.txt"
set "START_MENU_DIR=%APPDATA%\Microsoft\Windows\Start Menu\Programs"
set "SHORTCUT_PATH=%START_MENU_DIR%\%APP_NAME%.lnk"

set "SCRIPT_DIR_DISPLAY=%SCRIPT_DIR%"
if "%SCRIPT_DIR_DISPLAY:~-1%"=="\" set "SCRIPT_DIR_DISPLAY=%SCRIPT_DIR_DISPLAY:~0,-1%"

if not exist "%APP_SCRIPT%" (
    echo ERROR: Cannot find gallery_watch_upload.pyw.
    echo Expected file:
    echo   "%APP_SCRIPT%"
    exit /b 1
)

rem Prefer the python.exe that the user gets from the command line. This matches
rem normal testing such as: python --version. Fall back to the Python launcher
rem when python.exe is not available on PATH.
set "PYTHON_CMD="
where python >nul 2>nul
if not errorlevel 1 set "PYTHON_CMD=python"

if not defined PYTHON_CMD (
    where py >nul 2>nul
    if not errorlevel 1 set "PYTHON_CMD=py"
)

if not defined PYTHON_CMD (
    echo ERROR: Python was not found on PATH.
    echo Install Python, then run this installer again from the winapp folder.
    exit /b 1
)

rem Resolve the real interpreter path. This avoids shortcuts that use a generic
rem file association and accidentally launch another Python version.
set "PYTHON_EXE="
for /f "usebackq delims=" %%I in (`%PYTHON_CMD% -c "import sys; print(sys.executable)" 2^>nul`) do set "PYTHON_EXE=%%I"

if not defined PYTHON_EXE (
    echo ERROR: Could not resolve the Python executable path.
    echo Command used:
    echo   %PYTHON_CMD% -c "import sys; print(sys.executable)"
    exit /b 1
)

for %%I in ("%PYTHON_EXE%") do set "PYTHON_DIR=%%~dpI"
set "PYTHONW_EXE=%PYTHON_DIR%pythonw.exe"
if not exist "%PYTHONW_EXE%" set "PYTHONW_EXE=%PYTHON_EXE%"

if exist "%REQUIREMENTS_FILE%" (
    echo Installing Python dependencies into this runtime:
    echo   "%PYTHON_EXE%"
    "%PYTHON_EXE%" -m pip install --user -r "%REQUIREMENTS_FILE%"
    if errorlevel 1 (
        echo WARNING: Dependency installation failed. The uploader will still run,
        echo but client-side thumbnail generation will remain unavailable.
    )
) else (
    echo WARNING: requirements.txt was not found. Skipping dependency installation.
)

echo Verifying Pillow in this runtime:
echo   "%PYTHON_EXE%"
"%PYTHON_EXE%" -c "from PIL import Image; print('Pillow OK:', Image.__version__)"
if errorlevel 1 (
    echo WARNING: Pillow is still unavailable in this runtime.
    echo Open the uploader and use the Manual upload tab button named:
    echo   Install or repair Pillow
)

if not exist "%START_MENU_DIR%" (
    mkdir "%START_MENU_DIR%"
    if errorlevel 1 (
        echo ERROR: Could not create the Start Menu folder.
        echo Folder:
        echo   "%START_MENU_DIR%"
        exit /b 1
    )
)

rem Create a real .lnk file. The target is pythonw.exe and the argument is the
rem .pyw script, so the app starts without a console and without relying on the
rem .pyw file association.
set "INSTALL_PS1=%TEMP%\php_gallery_uploader_install_%RANDOM%_%RANDOM%.ps1"

> "%INSTALL_PS1%" echo $ErrorActionPreference = 'Stop'
>> "%INSTALL_PS1%" echo $shortcutPath = $env:PHPGALLERY_SHORTCUT_PATH
>> "%INSTALL_PS1%" echo $targetPath = $env:PHPGALLERY_SHORTCUT_TARGET
>> "%INSTALL_PS1%" echo $scriptPath = $env:PHPGALLERY_APP_SCRIPT
>> "%INSTALL_PS1%" echo $workingDirectory = $env:PHPGALLERY_WORKING_DIRECTORY
>> "%INSTALL_PS1%" echo $shell = New-Object -ComObject WScript.Shell
>> "%INSTALL_PS1%" echo $shortcut = $shell.CreateShortcut($shortcutPath)
>> "%INSTALL_PS1%" echo $shortcut.TargetPath = $targetPath
>> "%INSTALL_PS1%" echo $shortcut.Arguments = '"' + $scriptPath + '"'
>> "%INSTALL_PS1%" echo $shortcut.WorkingDirectory = $workingDirectory
>> "%INSTALL_PS1%" echo $shortcut.Description = 'Starts the PHP Gallery uploader.'
>> "%INSTALL_PS1%" echo if (Test-Path -LiteralPath $targetPath^) { $shortcut.IconLocation = $targetPath }
>> "%INSTALL_PS1%" echo $shortcut.Save(^)

set "PHPGALLERY_SHORTCUT_PATH=%SHORTCUT_PATH%"
set "PHPGALLERY_SHORTCUT_TARGET=%PYTHONW_EXE%"
set "PHPGALLERY_APP_SCRIPT=%APP_SCRIPT%"
set "PHPGALLERY_WORKING_DIRECTORY=%SCRIPT_DIR_DISPLAY%"

powershell.exe -NoProfile -ExecutionPolicy Bypass -File "%INSTALL_PS1%"
set "POWERSHELL_EXIT=%ERRORLEVEL%"

if exist "%INSTALL_PS1%" del /f /q "%INSTALL_PS1%" >nul 2>nul

if not "%POWERSHELL_EXIT%"=="0" (
    echo ERROR: Could not create the Start Menu shortcut.
    echo PowerShell exit code: %POWERSHELL_EXIT%
    exit /b %POWERSHELL_EXIT%
)

if not exist "%SHORTCUT_PATH%" (
    echo ERROR: PowerShell finished, but the shortcut was not created.
    echo Expected shortcut:
    echo   "%SHORTCUT_PATH%"
    exit /b 1
)

echo Installed Start Menu shortcut successfully.
echo.
echo Shortcut name:
echo   %APP_NAME%
echo.
echo Shortcut target:
echo   "%PYTHONW_EXE%"
echo.
echo Shortcut argument:
echo   "%APP_SCRIPT%"
echo.
echo Application folder:
echo   "%SCRIPT_DIR_DISPLAY%"
echo.
echo Press the Windows key and search for:
echo   %APP_NAME%

exit /b 0
