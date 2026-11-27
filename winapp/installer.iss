; Project: PHP Gallery
; Repository: https://github.com/klusik/PHP_gallery
; File: winapp/installer.iss
; Module Type: Windows installer
; Purpose: Install the standalone uploader and its desktop lifecycle hooks.
; Responsibilities: Replace verified binaries and register original-user startup.
; Author: Rudolf Klusal
; License: MIT License (see LICENSE file in repository)
;
; PHP Gallery Uploader has its own version, independent of the PHP Gallery CMS.
; build_installer.py supplies all paths and the version to this script.
#ifndef AppVersion
  #error Run build.bat to build the installer.
#endif
#ifndef AppBundle
  #error The standard installer requires its common onedir runtime.
#endif
#ifndef QtNotice
  #error The standard installer requires its Qt source and license notice.
#endif
#ifndef InstallerLicense
  #error The standard installer requires its combined license page.
#endif

[Setup]
AppId={{E7CEB8DB-A422-44D2-8114-FAEFA25E62DC}
AppName=PHP Gallery Uploader
; Both current versions and installer entry points guard the same installed tree.
SetupMutex=PHPGalleryUploaderSetup-E7CEB8DB,Global\PHPGalleryUploaderSetup-E7CEB8DB
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
LicenseFile={#InstallerLicense}
VersionInfoVersion={#AppVersion}.0
Compression=lzma2
SolidCompression=yes
WizardStyle=modern
; PrepareToInstall owns exact-path quiescence. Restart Manager must never
; force terminate a runtime that can still be holding writer leases.
CloseApplications=no
CloseApplicationsFilter=PHPGalleryUploader.exe
RestartApplications=no

[Languages]
Name: "english"; MessagesFile: "compiler:Default.isl"
Name: "czech"; MessagesFile: "compiler:Languages\Czech.isl"

[CustomMessages]
english.UploaderShutdownFailed=Setup could not verify that the installed PHP Gallery Uploader has stopped. Close the uploader, including its tray icon, and retry. No application files have been replaced.
czech.UploaderShutdownFailed=Instalátor nemohl ověřit ukončení nainstalovaného PHP Gallery Uploaderu. Ukončete aplikaci včetně ikony v oznamovací oblasti a zkuste to znovu. Soubory aplikace nebyly nahrazeny.
english.UploaderRuntimeUnverified=Setup could not verify the existing WinApp runtime or its replacement access. The installation was stopped before replacing files.
czech.UploaderRuntimeUnverified=Instalátor nemohl ověřit stávající runtime WinApp nebo přístup pro jeho výměnu. Instalace byla zastavena před výměnou souborů.
english.UploaderRuntimeMigrationFailed=Setup could not move the previous WinApp runtime into its protected recovery slot. See the setup log for details.
czech.UploaderRuntimeMigrationFailed=Instalátor nemohl přesunout předchozí runtime WinApp do jeho chráněného místa pro obnovu. Podrobnosti jsou v protokolu instalace.

[Tasks]
Name: "desktopicon"; Description: "{cm:CreateDesktopIcon}"; GroupDescription: "{cm:AdditionalIcons}"; Flags: unchecked

[Files]
Source: "{#AppBundle}\*"; DestDir: "{app}"; Flags: ignoreversion recursesubdirs createallsubdirs
Source: "{#QtNotice}"; DestDir: "{app}\licenses"; Flags: ignoreversion

[Icons]
Name: "{group}\PHP Gallery Uploader"; Filename: "{app}\PHPGalleryUploader.exe"
Name: "{autodesktop}\PHP Gallery Uploader"; Filename: "{app}\PHPGalleryUploader.exe"; Tasks: desktopicon

[Run]
; Apply defaults/retained opt-out even when the final Launch checkbox is cleared.
; A self-update already has an original-user helper that owns the final restart;
; its elevated installer must not configure the administrator's user profile.
Filename: "{app}\PHPGalleryUploader.exe"; Parameters: "--configure-startup"; Flags: runhidden runasoriginaluser waituntilterminated; Check: not IsSelfUpdate
Filename: "{app}\PHPGalleryUploader.exe"; Description: "{cm:LaunchProgram,PHP Gallery Uploader}"; Flags: nowait postinstall skipifsilent

[Registry]
; No ValueType/ValueData: setup logs uninstall cleanup without setting a value.
; The original-user app owns HKCU contents, including an existing opt-out.
Root: HKCU; Subkey: "Software\Microsoft\Windows\CurrentVersion\Run"; ValueName: "PHPGalleryUploader"; Flags: uninsdeletevalue

[Code]
const
  RuntimeDirectory = '\_internal';
  LegacyQtDirectory = '\qt';
  RetiredSuffix = '.winapp-previous';

var
  PreviousLauncherDigest: String;
  PreviousRuntimeMoved, PreviousQtMoved, InstallationSucceeded: Boolean;

function CreateFileW(FileName: String; DesiredAccess, ShareMode: LongWord;
  SecurityAttributes: LongWord; CreationDisposition, Flags: LongWord;
  TemplateFile: LongWord): THandle;
  external 'CreateFileW@kernel32.dll stdcall';
function WriteFile(Handle: THandle; Buffer: AnsiString; Count: LongWord;
  var Written: LongWord; Overlapped: LongWord): Boolean;
  external 'WriteFile@kernel32.dll stdcall';
function CloseHandle(Handle: THandle): Boolean;
  external 'CloseHandle@kernel32.dll stdcall';
function GetFileAttributesW(FileName: String): LongWord;
  external 'GetFileAttributesW@kernel32.dll stdcall';
function CompareStringOrdinal(Left: String; LeftLength: Integer; Right: String;
  RightLength: Integer; IgnoreCase: LongWord): Integer;
  external 'CompareStringOrdinal@kernel32.dll stdcall';

function IsSelfUpdate: Boolean;
begin
  Result := ExpandConstant('{param:SELFUPDATE|0}') = '1';
end;

{ The running app owns graceful shutdown for either backend. Setup only
  observes the registered path and refuses writes until the owner exits. }
procedure WriteUpdateStatus(Phase, Percent: Integer);
var
  SessionName: String;
  Index: Integer;
  PipeHandle: THandle;
  RecordText: AnsiString;
  Written: LongWord;
begin
  if not IsSelfUpdate then Exit;
  SessionName := ExpandConstant('{param:UPDATESTATUS|}');
  if Length(SessionName) <> 32 then Exit;
  for Index := 1 to Length(SessionName) do
    if Pos(SessionName[Index], '0123456789abcdef') = 0 then Exit;
  { Only an opaque local session ID is accepted, never a filesystem path.
    Numeric status travels to the original user's helper-owned named pipe. }
  PipeHandle := CreateFileW('\\.\pipe\PHPGalleryUploaderUpdate-' + SessionName,
    $40000000, 0, 0, 3, 0, 0);
  if PipeHandle = THandle(-1) then Exit;
  try
    RecordText := IntToStr(Phase) + ' ' + IntToStr(Percent);
    WriteFile(PipeHandle, RecordText, Length(RecordText), Written, 0);
  finally
    CloseHandle(PipeHandle);
  end;
end;

procedure CurInstallProgressChanged(CurProgress, MaxProgress: Integer);
begin
  if MaxProgress > 0 then
    WriteUpdateStatus(2, Round(100.0 * CurProgress / MaxProgress));
end;

{ Inspect every existing ancestor of the installer-owned runtime path. The
  values are Win32 INVALID_FILE_ATTRIBUTES and FILE_ATTRIBUTE_REPARSE_POINT. }
function RuntimePathIsLocal(const Candidate: String): Boolean;
var
  Name, Parent: String;
  Attributes: LongWord;
begin
  Name := RemoveBackslashUnlessRoot(Candidate);
  Result := False;
  while Name <> '' do
  begin
    Attributes := GetFileAttributesW(Name);
    if (Attributes <> $FFFFFFFF) and ((Attributes and $400) <> 0) then Exit;
    Parent := ExtractFileDir(Name);
    if Parent = Name then Break;
    Name := Parent;
  end;
  Result := True;
end;

{ Compare complete paths ordinally, using Win32's null-terminated length (-1),
  case-insensitive flag (1) and CSTR_EQUAL result (2). }
function SamePathSpelling(const Left, Right: String): Boolean;
begin
  Result := CompareStringOrdinal(Left, -1, Right, -1, 1) = 2;
end;

{ Inno Setup may retain the original short directory spelling in HKLM.
  Windows resolves both spellings through the filesystem after the ancestor
  guards. A missing alias cannot establish equivalence between two paths. }
function SameWinAppPath(const Left, Right: String): Boolean;
begin
  Result := SamePathSpelling(Left, Right);
  if Result then Exit;
  if not FileOrDirExists(Left) or not FileOrDirExists(Right) then Exit;
  Result := SamePathSpelling(GetShortName(Left), GetShortName(Right));
end;

{ Treat a staged onedir slot as our old runtime only when its installed
  executable, app-local version and PyInstaller base archive all agree. }
function IsOwnedRuntime(const Directory: String): Boolean;
var
  Version: String;
  VersionBytes: AnsiString;
begin
  Result := RuntimePathIsLocal(Directory + '\_internal\VERSION') and
    RuntimePathIsLocal(Directory + '\PHPGalleryUploader.exe') and
    RuntimePathIsLocal(Directory + '\_internal\base_library.zip') and
    FileExists(Directory + '\_internal\base_library.zip') and
    GetVersionNumbersString(Directory + '\PHPGalleryUploader.exe', Version) and
    LoadStringFromFile(Directory + '\_internal\VERSION', VersionBytes) and
    (Version = Trim(String(VersionBytes)) + '.0');
end;

{ A current onefile installation has no app-local runtime to retire. Both
  recognized onedir slots must belong to this exact registered installation. }
function RuntimeLayoutIsOwned(const Root: String): Boolean;
var
  Registered, RegisteredRoot, InstallRoot: String;
begin
  Result := False;
  if not RuntimePathIsLocal(Root + '\PHPGalleryUploader.exe') or
     not RuntimePathIsLocal(Root + RuntimeDirectory) or
     not RuntimePathIsLocal(Root + LegacyQtDirectory) or
     not RuntimePathIsLocal(Root + RuntimeDirectory + RetiredSuffix) or
     not RuntimePathIsLocal(Root + LegacyQtDirectory + RetiredSuffix) then
  begin
    Log('WinApp preflight rejected: redirected-runtime-path');
    Exit;
  end;
  { An interrupted or unknown staging slot needs separate verification. }
  if FileOrDirExists(Root + RuntimeDirectory + RetiredSuffix) or
     FileOrDirExists(Root + LegacyQtDirectory + RetiredSuffix) or
     FileExists(Root + RuntimeDirectory) or FileExists(Root + LegacyQtDirectory) then
  begin
    Log('WinApp preflight rejected: occupied-runtime-slot');
    Exit;
  end;
  if not DirExists(Root + RuntimeDirectory) and not DirExists(Root + LegacyQtDirectory) then
  begin
    Result := True;
    Exit;
  end;
  if not RegQueryStringValue(HKLM64,
    'SOFTWARE\Microsoft\Windows\CurrentVersion\Uninstall\{E7CEB8DB-A422-44D2-8114-FAEFA25E62DC}_is1',
    'InstallLocation', Registered) then
  begin
    Log('WinApp preflight rejected: missing-install-registration');
    Exit;
  end;
  RegisteredRoot := RemoveBackslashUnlessRoot(Registered);
  InstallRoot := RemoveBackslashUnlessRoot(Root);
  if not SamePathSpelling(ExpandFileName(RegisteredRoot), RegisteredRoot) or
     not RuntimePathIsLocal(RegisteredRoot) or
     not SameWinAppPath(RegisteredRoot, InstallRoot) then
  begin
    Log('WinApp preflight rejected: install-location-mismatch');
    Exit;
  end;
  if not SamePathSpelling(RegisteredRoot, InstallRoot) then Log('WinApp installer verified an existing Windows path alias');
  if (DirExists(Root + RuntimeDirectory) and not IsOwnedRuntime(Root)) or
     (DirExists(Root + LegacyQtDirectory) and not IsOwnedRuntime(Root + LegacyQtDirectory)) then
  begin
    Log('WinApp preflight rejected: runtime-version-unverified');
    Exit;
  end;
  Result := FileExists(Root + '\PHPGalleryUploader.exe');
end;

{ Confirm that a shared-open launcher permits replacement before removing any
  old runtime. The Win32 flags are DELETE, the three FILE_SHARE bits and
  OPEN_EXISTING. Opening for DELETE does not delete the file. }
function LauncherAllowsReplacement(const TargetPath: String): Boolean;
var
  Handle: THandle;
begin
  Result := True;
  if not FileExists(TargetPath) then Exit;
  Handle := CreateFileW(TargetPath, $10000, 7, 0, 3, 0, 0);
  Result := Handle <> THandle(-1);
  if Result then CloseHandle(Handle);
end;

{ Move only recognized, closed runtimes aside on the same filesystem. Setup
  writes a complete clean runtime. The old one remains available until the
  install succeeds or can prove it is safe to restore the original paths. }
procedure RetireOwnedRuntimes;
var
  Root: String;
begin
  Root := RemoveBackslashUnlessRoot(ExpandConstant('{app}'));
  if not RuntimeLayoutIsOwned(Root) or
     not LauncherAllowsReplacement(Root + '\PHPGalleryUploader.exe') or
     not LauncherAllowsReplacement(Root + LegacyQtDirectory + '\PHPGalleryUploader.exe') then
    RaiseException(CustomMessage('UploaderRuntimeUnverified'));
  if not DirExists(Root + RuntimeDirectory) and not DirExists(Root + LegacyQtDirectory) then Exit;
  PreviousLauncherDigest := GetSHA256OfFile(Root + '\PHPGalleryUploader.exe');
  if DirExists(Root + RuntimeDirectory) then
  begin
    PreviousRuntimeMoved := RenameFile(Root + RuntimeDirectory, Root + RuntimeDirectory + RetiredSuffix);
    if not PreviousRuntimeMoved then RaiseException(CustomMessage('UploaderRuntimeMigrationFailed'));
  end;
  if DirExists(Root + LegacyQtDirectory) then
  begin
    PreviousQtMoved := RenameFile(Root + LegacyQtDirectory, Root + LegacyQtDirectory + RetiredSuffix);
    if not PreviousQtMoved then RaiseException(CustomMessage('UploaderRuntimeMigrationFailed'));
  end;
end;

{ Remove the previous runtime only on success. On failure restore an empty
  original slot only when the previous launcher is byte-identical. The updater
  still compares its full preinstall fingerprint before restarting anything. }
procedure CompleteRuntimeRetirement(const Original: String);
var
  Retired: String;
begin
  Retired := Original + RetiredSuffix;
  if not RuntimePathIsLocal(Retired) or not DirExists(Retired) then Exit;
  if InstallationSucceeded then
  begin
    { Inno's DelTree removes nested reparse links without following targets. }
    if not DelTree(Retired, True, True, True) then Log('Could not remove retired WinApp runtime: ' + Retired);
  end
  else if not FileOrDirExists(Original) then
  begin
    if not RenameFile(Retired, Original) then Log('Could not restore previous WinApp runtime: ' + Retired);
  end
  else Log('Leaving previous WinApp runtime aside because its original slot is occupied: ' + Retired);
end;

procedure DeinitializeSetup;
var
  Root, Launcher: String;
begin
  if not PreviousRuntimeMoved and not PreviousQtMoved then Exit;
  Root := RemoveBackslashUnlessRoot(ExpandConstant('{app}'));
  Launcher := Root + '\PHPGalleryUploader.exe';
  if not InstallationSucceeded and
     (not RuntimePathIsLocal(Launcher) or not FileExists(Launcher) or
      (GetSHA256OfFile(Launcher) <> PreviousLauncherDigest)) then
  begin
    Log('Leaving previous runtime aside because launcher integrity is unknown.');
    Exit;
  end;
  if PreviousRuntimeMoved then CompleteRuntimeRetirement(Root + RuntimeDirectory);
  if PreviousQtMoved then CompleteRuntimeRetirement(Root + LegacyQtDirectory);
end;

procedure CurStepChanged(CurStep: TSetupStep);
begin
  if CurStep = ssInstall then
  begin
    RetireOwnedRuntimes;
    WriteUpdateStatus(2, 0);
  end
  else if CurStep = ssPostInstall then WriteUpdateStatus(3, 100)
  else if CurStep = ssDone then
  begin
    InstallationSucceeded := True;
    WriteUpdateStatus(4, 100);
  end;
end;

{ Both backends and the startup supervisor share the same installed path.
  Inspect every exact match, including a legacy onefile parent and child.
  An unavailable path cannot prove that the installed runtime has stopped. }
function InspectUploaderProcesses(const Services: Variant; const TargetPath, LegacyQtPath: String): Integer;
var
  Processes, Process: Variant;
  Index: Integer;
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
    ProcessPath := ExpandFileName(ProcessPath);
    if SameWinAppPath(ProcessPath, TargetPath) or SameWinAppPath(ProcessPath, LegacyQtPath) then
      Result := Result + 1;
  end;
end;

{ PrepareToInstall runs before application files are written, including silent
  setup. Poll up to three seconds for the installed runtime to disappear. A nonempty result prevents
  installation; raw COM/WMI exceptions stay out of the user-facing message.
  File locks remain a secondary safeguard. [Run] owns direct-install relaunch. }
function PrepareToInstall(var NeedsRestart: Boolean): String;
var
  Locator, Services: Variant;
  TargetPath, LegacyQtPath: String;
  Attempt: Integer;
begin
  Result := CustomMessage('UploaderShutdownFailed');
  try
    TargetPath := ExpandFileName(ExpandConstant('{app}\PHPGalleryUploader.exe'));
    LegacyQtPath := ExpandFileName(ExpandConstant('{app}') + LegacyQtDirectory + '\PHPGalleryUploader.exe');
    Locator := CreateOleObject('WbemScripting.SWbemLocator');
    Services := Locator.ConnectServer('', 'root\CIMV2');
    WriteUpdateStatus(1, 0);
    for Attempt := 0 to 30 do
    begin
      if InspectUploaderProcesses(Services, TargetPath, LegacyQtPath) = 0 then
      begin
        Result := CustomMessage('UploaderRuntimeUnverified');
        if not RuntimeLayoutIsOwned(ExpandConstant('{app}')) then Exit;
        if not LauncherAllowsReplacement(TargetPath) or not LauncherAllowsReplacement(LegacyQtPath) then
        begin
          Log('WinApp preflight rejected: launcher-replacement-blocked');
          Exit;
        end;
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
