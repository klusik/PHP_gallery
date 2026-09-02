<#
  Project: PHP Gallery
  Repository: https://github.com/klusik/PHP_gallery

  File: scripts/deploy.ps1
  Module Type: Deployment Script

  Purpose:
    Automates deployment packaging or upload workflows for PHP Gallery.

  Responsibilities:
    - Collect deployment inputs safely
    - Prepare files for local or remote deployment
    - Report deployment failures clearly

  Author:
    Rudolf Klusal

  Contact:
    https://github.com/klusik

  License:
    MIT License (see LICENSE file in repository)

  Notes:
    - Keep comments and docstrings intact when modifying this file.
    - Prefer small, readable changes over broad rewrites.

  Last Updated:
    2026-05-04
#>

param(
    [ValidateSet('ftp', 'local')]
    [string]$Mode,
    [string]$HostName,
    [string]$UserName,
    [string]$Password,
    [string]$RemoteFolder,
    [string]$DeployFolder,
    [string]$UploadMedia,
    [string]$MakeZipDeploy,
    [string]$IncludeTests = 'false'
)

if (-not $Mode) {
    # Variable $answer stores this scripts working value.
    $answer = Read-Host "Deployment mode: local deploy folder or FTP upload? [L/f]"
    # Variable $Mode stores this scripts working value.
    $Mode = if ($answer -match '^[Ff]') { 'ftp' } else { 'local' }
}
# Tests are source-review material, not production deployment content. Keep the
# default exclusion and refuse an FTP opt-in so the suite cannot be uploaded by accident.
$includeRepositoryTests = ($IncludeTests -match '^(1|true|yes|y)$')
if ($includeRepositoryTests -and $Mode -eq 'ftp') {
    throw 'Tests may be included only in local deployment folders or ZIP packages.'
}
# Variable $includeMedia stores this scripts working value.
$includeMedia = $false
if ($PSBoundParameters.ContainsKey('UploadMedia')) {
    # Variable $includeMedia stores this scripts working value.
    $includeMedia = ($UploadMedia -match '^(1|true|yes|y)$')
} else {
    # Variable $includeMedia stores this scripts working value.
    $includeMedia = ((Read-Host "Upload media folders? y/N") -match '^[Yy]')
}
if ($Mode -eq 'ftp') {
    if (-not $HostName) { $HostName = Read-Host "FTP host" }
    if (-not $UserName) { $UserName = Read-Host "FTP user" }
    if (-not $Password) { $Password = Read-Host "FTP password" }
    if (-not $RemoteFolder) { $RemoteFolder = Read-Host "Remote folder" }
}
if ($Mode -eq 'local' -and -not $DeployFolder) {
    # Variable $DeployFolder stores this scripts working value.
    $DeployFolder = Read-Host "Local deploy folder [deploy]"
    if (-not $DeployFolder) { $DeployFolder = 'deploy' }
}

# Variable $zipDeploy stores this scripts working value.
$zipDeploy = $false
if ($Mode -eq 'local') {
    if ($PSBoundParameters.ContainsKey('MakeZipDeploy')) {
        # Variable $zipDeploy stores this scripts working value.
        $zipDeploy = ($MakeZipDeploy -match '^(1|true|yes|y)$')
    } else {
        # Variable $zipAnswer stores this scripts working value.
        $zipAnswer = Read-Host "Make a zip deploy? Y/n"
        # Variable $zipDeploy stores this scripts working value.
        $zipDeploy = -not ($zipAnswer -match '^[Nn]')
    }
}

# Variable $root stores this scripts working value.
$root = Resolve-Path "$PSScriptRoot\.."
# Variable $excludeDirs stores this scripts working value.
$excludeDirs = @('.git', 'cache', 'logs', 'tmp', 'deploy')
# Variable $excludeDirNamesAnywhere stores folder names skipped wherever they appear in the repository tree.
$excludeDirNamesAnywhere = @('__pycache__', '.pytest_cache', 'tests', 'http_monitor_logs')
if ($includeRepositoryTests) {
    $excludeDirNamesAnywhere = @($excludeDirNamesAnywhere | Where-Object { $_ -ne 'tests' })
}
if (-not $includeMedia) { $excludeDirs += 'galleries' }
# Variable $excludeFiles stores this scripts working value.
$excludeFiles = @('.gitignore', '.DS_Store', 'config.php', '.env', '*.log', '*.tmp', '*.pyc', '*.aux', '*.idx', '*.ilg', '*.ind', '*.out', '*.toc')
# Variable $alwaysIncludeRelatives stores deploy paths that must stay packaged even as filters evolve.
$alwaysIncludeRelatives = @('app')


# Function `Get-DeployRelativePath` handles this script step.
function Get-DeployRelativePath($Path) {
    # Variable $fullPath stores this scripts working value.
    $fullPath = [System.IO.Path]::GetFullPath($Path)
    # Variable $rootPath stores this scripts working value.
    $rootPath = [System.IO.Path]::GetFullPath($root)
    return $fullPath.Substring($rootPath.Length).TrimStart('\', '/')
}

# Function `Should-Skip` handles this script step.
function Should-Skip($Path) {
    if ($Mode -eq 'local' -and $script:DeployTarget) {
        # Variable $fullPath stores this scripts working value.
        $fullPath = [System.IO.Path]::GetFullPath($Path).TrimEnd('\', '/')
        # Variable $deployTargetPath stores this scripts working value.
        $deployTargetPath = [System.IO.Path]::GetFullPath($script:DeployTarget).TrimEnd('\', '/')
        if ($fullPath -eq $deployTargetPath -or $fullPath.StartsWith($deployTargetPath + [System.IO.Path]::DirectorySeparatorChar)) {
            return $true
        }
    }

    # Variable $relative stores this scripts working value.
    $relative = Get-DeployRelativePath $Path
    # Variable $portableRelative stores this scripts working value.
    $portableRelative = $relative.Replace('\', '/')
    $protectedDeployPaths = @(
        'cache/.htaccess',
        'galleries/.htaccess',
        'data/admin-log-archives/.htaccess'
    )
    if ($protectedDeployPaths -contains $portableRelative) {
        return $false
    }

    # Runtime/user data must never be copied into a deployment package. The protected
    # admin-log archive .htaccess above is the only data/ exception.
    if ($portableRelative -eq 'data' -or $portableRelative.StartsWith('data/')) {
        return $true
    }
    foreach ($alwaysIncludeRelative in $alwaysIncludeRelatives) {
        # Variable $portableAlwaysInclude stores one deploy path that must not be filtered out.
        $portableAlwaysInclude = $alwaysIncludeRelative.Replace('\', '/').Trim('/')
        if ($portableRelative -eq $portableAlwaysInclude -or $portableRelative.StartsWith($portableAlwaysInclude + '/')) {
            return $false
        }
    }
    foreach ($dir in $excludeDirs) {
        if ($relative -match "^[.\\/]?$([regex]::Escape($dir))([\\/]|$)") { return $true }
    }
    foreach ($dirName in $excludeDirNamesAnywhere) {
        # Variable $escapedDirName stores this scripts working value.
        $escapedDirName = [regex]::Escape($dirName)
        if ($portableRelative -match "(^|/)$escapedDirName(/|$)") { return $true }
    }
    foreach ($pattern in $excludeFiles) {
        if ([System.Management.Automation.WildcardPattern]::new($pattern).IsMatch((Split-Path $Path -Leaf))) { return $true }
    }
    return $false
}

# Function `Upload-File` handles this script step.
function Upload-File($LocalPath) {
    # Variable $relative stores this scripts working value.
    $relative = (Get-DeployRelativePath $LocalPath).Replace('\', '/')
    # Variable $remoteBase stores this scripts working value.
    $remoteBase = ("ftp://{0}/{1}" -f $HostName, $RemoteFolder.Trim('/')).TrimEnd('/')
    # Variable $relativeDir stores this scripts working value.
    $relativeDir = Split-Path $relative -Parent
    if ($relativeDir) {
        Ensure-RemoteDirectory "$remoteBase/$($relativeDir.Replace('\', '/'))"
    }
    # Variable $uri stores this scripts working value.
    $uri = "$remoteBase/$relative"
    # Variable $request stores this scripts working value.
    $request = [System.Net.FtpWebRequest]::Create($uri)
    $request.Method = [System.Net.WebRequestMethods+Ftp]::UploadFile
    $request.Credentials = New-Object System.Net.NetworkCredential($UserName, $Password)
    # Variable $bytes stores this scripts working value.
    $bytes = [System.IO.File]::ReadAllBytes($LocalPath)
    $request.ContentLength = $bytes.Length
    # Variable $stream stores this scripts working value.
    $stream = $request.GetRequestStream()
    $stream.Write($bytes, 0, $bytes.Length)
    $stream.Close()
    # Variable $response stores this scripts working value.
    $response = $request.GetResponse()
    $response.Close()
    Write-Host "Uploaded $relative"
}

# Function `Copy-DeployFile` handles this script step.
function Copy-DeployFile($LocalPath) {
    # Variable $relative stores this scripts working value.
    $relative = Get-DeployRelativePath $LocalPath
    # Variable $destination stores this scripts working value.
    $destination = Join-Path $script:DeployTarget $relative
    # Variable $destinationDir stores this scripts working value.
    $destinationDir = Split-Path $destination -Parent
    if (-not (Test-Path $destinationDir)) {
        New-Item -ItemType Directory -Path $destinationDir -Force | Out-Null
    }
    Copy-Item -LiteralPath $LocalPath -Destination $destination -Force
    Write-Host "Copied $relative"
}

# Function `New-CompatibleZipArchive` handles this script step.
function New-CompatibleZipArchive($SourceDirectory, $DestinationZip) {
    if (Test-Path $DestinationZip) {
        Remove-Item -LiteralPath $DestinationZip -Force
    }

    Add-Type -AssemblyName System.IO.Compression
    Add-Type -AssemblyName System.IO.Compression.FileSystem
    # Variable $sourcePath stores the normalized staging directory used to derive portable entry names.
    $sourcePath = [System.IO.Path]::GetFullPath($SourceDirectory).TrimEnd('\', '/')
    # Variable $archive stores the writable ZIP container.
    $archive = [System.IO.Compression.ZipFile]::Open($DestinationZip, [System.IO.Compression.ZipArchiveMode]::Create)
    try {
        Get-ChildItem -LiteralPath $sourcePath -Recurse -File | ForEach-Object {
            # ZIP entry paths must use forward slashes. Some web extractors treat Windows backslashes as literal filename characters.
            $entryName = $_.FullName.Substring($sourcePath.Length).TrimStart('\', '/').Replace('\', '/')
            # NoCompression creates plain stored entries that remain compatible with older hosting tools.
            [System.IO.Compression.ZipFileExtensions]::CreateEntryFromFile(
                $archive,
                $_.FullName,
                $entryName,
                [System.IO.Compression.CompressionLevel]::NoCompression
            ) | Out-Null
        }
    } finally {
        $archive.Dispose()
    }
}

# Function `Ensure-RemoteDirectory` handles this script step.
function Ensure-RemoteDirectory($Uri) {
    # Variable $parts stores this scripts working value.
    $parts = ([Uri]$Uri).AbsolutePath.Trim('/').Split('/')
    # Variable $current stores this scripts working value.
    $current = "ftp://$HostName"
    foreach ($part in $parts) {
        if (-not $part) { continue }
        # Variable $current stores this scripts working value.
        $current = "$current/$part"
        try {
            # Variable $request stores this scripts working value.
            $request = [System.Net.FtpWebRequest]::Create($current)
            $request.Method = [System.Net.WebRequestMethods+Ftp]::MakeDirectory
            $request.Credentials = New-Object System.Net.NetworkCredential($UserName, $Password)
            # Variable $response stores this scripts working value.
            $response = $request.GetResponse()
            $response.Close()
        } catch {
        }
    }
}

Set-Location $root

if ($Mode -eq 'local') {
    $script:DeployTarget = if ([System.IO.Path]::IsPathRooted($DeployFolder)) {
        $DeployFolder
    } else {
        Join-Path $root $DeployFolder
    }

    # Variable $rootPath stores this scripts working value.
    $rootPath = [System.IO.Path]::GetFullPath($root).TrimEnd('\', '/')
    # Variable $deployTargetPath stores this scripts working value.
    $deployTargetPath = [System.IO.Path]::GetFullPath($script:DeployTarget).TrimEnd('\', '/')
    if ($deployTargetPath -eq $rootPath) {
        throw "Local deploy folder cannot be the project root."
    }
}

# Variable $files stores this scripts working value.
$files = Get-ChildItem -Recurse -File | Where-Object { -not (Should-Skip $_.FullName) }

if ($Mode -eq 'local') {
    if (Test-Path $script:DeployTarget) {
        Remove-Item -LiteralPath $script:DeployTarget -Recurse -Force
    }
    New-Item -ItemType Directory -Path $script:DeployTarget -Force | Out-Null

    if ($zipDeploy) {
        # Variable $deployStaging stores this scripts working value.
        $deployStaging = Join-Path $env:TEMP ("php-gallery-deploy-{0}" -f ([guid]::NewGuid().ToString('N')))
        # Variable $previousDeployTarget stores this scripts working value.
        $previousDeployTarget = $script:DeployTarget
        try {
            $script:DeployTarget = $deployStaging
            New-Item -ItemType Directory -Path $script:DeployTarget -Force | Out-Null
            $files | ForEach-Object {
                Copy-DeployFile $_.FullName
            }

            # Variable $zipPath stores this scripts working value.
            $zipPath = Join-Path $previousDeployTarget 'php-gallery-deploy.zip'
            New-CompatibleZipArchive -SourceDirectory $deployStaging -DestinationZip $zipPath
            Write-Host "Local zip deploy created at $zipPath"
        } finally {
            $script:DeployTarget = $previousDeployTarget
            if (Test-Path $deployStaging) {
                Remove-Item -LiteralPath $deployStaging -Recurse -Force
            }
        }
    } else {
        $files | ForEach-Object {
            Copy-DeployFile $_.FullName
        }
        Write-Host "Local deploy folder created at $script:DeployTarget"
    }
} else {
    $files | ForEach-Object {
        Upload-File $_.FullName
    }
}
