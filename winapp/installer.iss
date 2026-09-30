; PHP Gallery Uploader has its own version, independent of the PHP Gallery CMS.
; build_installer.py supplies all paths and the version to this script.
#ifndef AppVersion
  #error Run build.bat to build the installer.
#endif

[Setup]
AppId={{E7CEB8DB-A422-44D2-8114-FAEFA25E62DC}
AppName=PHP Gallery Uploader
AppVersion={#AppVersion}
AppPublisher=Rudolf Klusal
AppPublisherURL=https://github.com/klusik/PHP_gallery
DefaultDirName={autopf}\PHP Gallery Uploader
DefaultGroupName=PHP Gallery Uploader
DisableProgramGroupPage=yes
PrivilegesRequired=admin
ArchitecturesAllowed=x64compatible
ArchitecturesInstallIn64BitMode=x64compatible
MinVersion=10.0
OutputDir={#InstallerOutput}
OutputBaseFilename=PHPGalleryUploader-{#AppVersion}-Setup
SetupIconFile={#SourceRoot}\assets\tray-icon.ico
UninstallDisplayIcon={app}\PHPGalleryUploader.exe
LicenseFile={#SourceRoot}\..\LICENSE
VersionInfoVersion={#AppVersion}.0
Compression=lzma2
SolidCompression=yes
WizardStyle=modern
CloseApplications=force
CloseApplicationsFilter=PHPGalleryUploader.exe
RestartApplications=no

[Languages]
Name: "english"; MessagesFile: "compiler:Default.isl"
Name: "czech"; MessagesFile: "compiler:Languages\Czech.isl"

[CustomMessages]
english.UploaderShutdownFailed=Setup could not verify that the installed PHP Gallery Uploader has stopped. Close the uploader, including its tray icon, and retry. No application files have been replaced.
czech.UploaderShutdownFailed=Instalátor nemohl ověřit ukončení nainstalovaného PHP Gallery Uploaderu. Ukončete aplikaci včetně ikony v oznamovací oblasti a zkuste to znovu. Soubory aplikace nebyly nahrazeny.

[Tasks]
Name: "desktopicon"; Description: "{cm:CreateDesktopIcon}"; GroupDescription: "{cm:AdditionalIcons}"; Flags: unchecked

[Files]
Source: "{#AppExe}"; DestDir: "{app}"; Flags: ignoreversion

[Icons]
Name: "{group}\PHP Gallery Uploader"; Filename: "{app}\PHPGalleryUploader.exe"
Name: "{autodesktop}\PHP Gallery Uploader"; Filename: "{app}\PHPGalleryUploader.exe"; Tasks: desktopicon

[Run]
Filename: "{app}\PHPGalleryUploader.exe"; Description: "{cm:LaunchProgram,PHP Gallery Uploader}"; Flags: nowait postinstall skipifsilent

[Code]
{ Inspect only uploader processes and compare the absolute executable path.
  Unknown paths fail closed; copies running from other directories are untouched.
  Both PyInstaller onefile processes have the same executable path, so enumerate
  every match rather than closing only the visible window or one process ID. }
function InspectUploaderProcesses(const Services: Variant;
  const TargetPath: String; TerminateMatches: Boolean): Integer;
var
  Processes, Process: Variant;
  Index, TerminateResult: Integer;
  ProcessPath: String;
begin
  Result := 0;
  Processes := Services.ExecQuery(
    'SELECT * FROM Win32_Process WHERE Name = ''PHPGalleryUploader.exe''');
  for Index := 0 to Processes.Count - 1 do
  begin
    Process := Processes.ItemIndex(Index);
    if VarIsNull(Process.ExecutablePath) or VarIsEmpty(Process.ExecutablePath) then
      RaiseException('Uploader executable path unavailable');
    ProcessPath := Process.ExecutablePath;
    if ProcessPath = '' then
      RaiseException('Uploader executable path unavailable');
    if CompareText(ExpandFileName(ProcessPath), TargetPath) = 0 then
    begin
      Result := Result + 1;
      if TerminateMatches then
      begin
        { A process may exit between enumeration and termination. The caller
          verifies the fresh process list before allowing any file replacement. }
        try
          TerminateResult := Process.Terminate(0);
          if TerminateResult <> 0 then
            Log('Uploader termination did not report success; verifying process state');
        except
          Log('Uploader termination unavailable; verifying process state');
        end;
      end;
    end;
  end;
end;

{ PrepareToInstall runs before application files are written, including silent
  setup. Force-stop only this installation's uploader, then poll up to three
  seconds for both onefile processes to disappear. A nonempty result prevents
  installation; raw COM/WMI exceptions stay out of the user-facing message.
  Restart Manager remains a secondary file-lock safeguard. [Run] owns relaunch. }
function PrepareToInstall(var NeedsRestart: Boolean): String;
var
  Locator, Services: Variant;
  TargetPath: String;
  Attempt: Integer;
begin
  Result := CustomMessage('UploaderShutdownFailed');
  try
    TargetPath := ExpandFileName(ExpandConstant('{app}\PHPGalleryUploader.exe'));
    Locator := CreateOleObject('WbemScripting.SWbemLocator');
    Services := Locator.ConnectServer('', 'root\CIMV2');
    InspectUploaderProcesses(Services, TargetPath, True);
    for Attempt := 0 to 30 do
    begin
      if InspectUploaderProcesses(Services, TargetPath, False) = 0 then
      begin
        Result := '';
        Exit;
      end;
      if Attempt < 30 then
        Sleep(100);
    end;
    Log('Uploader shutdown verification timed out; installation refused');
  except
    Log('Uploader shutdown verification unavailable; installation refused');
  end;
end;
