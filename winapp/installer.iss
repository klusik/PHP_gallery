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
CloseApplications=yes

[Languages]
Name: "english"; MessagesFile: "compiler:Default.isl"
Name: "czech"; MessagesFile: "compiler:Languages\Czech.isl"

[Tasks]
Name: "desktopicon"; Description: "{cm:CreateDesktopIcon}"; GroupDescription: "{cm:AdditionalIcons}"; Flags: unchecked

[Files]
Source: "{#AppExe}"; DestDir: "{app}"; Flags: ignoreversion

[Icons]
Name: "{group}\PHP Gallery Uploader"; Filename: "{app}\PHPGalleryUploader.exe"
Name: "{autodesktop}\PHP Gallery Uploader"; Filename: "{app}\PHPGalleryUploader.exe"; Tasks: desktopicon

[Run]
Filename: "{app}\PHPGalleryUploader.exe"; Description: "{cm:LaunchProgram,PHP Gallery Uploader}"; Flags: nowait postinstall skipifsilent
