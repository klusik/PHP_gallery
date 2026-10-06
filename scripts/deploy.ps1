<#
  Project: PHP Gallery
  Repository: https://github.com/klusik/PHP_gallery

  File: scripts/deploy.ps1
  Module Type: Deployment Script

  Purpose:
    Builds or uploads the canonical PHP Gallery production file set.

  Responsibilities:
    - Check release integrity and canonical package membership
    - Build local folders or ZIP archives from validated paths
    - Upload only validated files through FTP

  Author:
    Rudolf Klusal

  Contact:
    https://github.com/klusik

  License:
    MIT License (see LICENSE file)
#>

[CmdletBinding()]
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
    [string]$IncludeTests
)

$ErrorActionPreference = 'Stop'
$root = [System.IO.Path]::GetFullPath((Join-Path $PSScriptRoot '..')).TrimEnd('\', '/')
$includeMedia = $false
$includeRepositoryTests = $false
$zipDeploy = $false

# Return true when a command-line value uses a recognized affirmative spelling.
function Test-Truthy {
    param([string]$Value)

    return $Value -match '^(1|true|yes|y)$'
}

# Choose the package profile shared with the release-file resolver.
function Get-PackageProfile {
    if ($includeRepositoryTests) {
        return 'source-review'
    }

    return 'production'
}

# Check that the current integrity manifest matches the selected source root.
function Test-CurrentManifest {
    & php (Join-Path $root 'scripts/generate_manifest.php') --check "--root=$root" | Out-Host
    if ($LASTEXITCODE -ne 0) {
        throw 'The core manifest is missing or stale. Run php scripts/generate_manifest.php and retry.'
    }
}

# Return the sorted canonical relative paths from the shared release-file policy.
function Get-PackagePaths {
    $profile = Get-PackageProfile
    $arguments = @(
        (Join-Path $root 'scripts/release_files.php'),
        'list',
        "--root=$root",
        "--profile=$profile",
        '--format=json'
    )
    if ($includeMedia) {
        $arguments += '--include-media'
    }

    $json = & php @arguments
    if ($LASTEXITCODE -ne 0) {
        throw 'The canonical production file list could not be resolved.'
    }

    $document = ($json -join [Environment]::NewLine) | ConvertFrom-Json
    if ($null -eq $document.files -or $document.files -isnot [System.Array]) {
        throw 'The release-file resolver returned an invalid file list.'
    }

    return ,$document.files
}

# Compare a staged package with the canonical policy and selected optional media.
function Test-PackageTree {
    param(
        [string]$StageRoot
    )

    $arguments = @(
        (Join-Path $root 'scripts/release_files.php'),
        'verify',
        "--root=$StageRoot",
        "--source-root=$root",
        "--profile=$(Get-PackageProfile)"
    )
    if ($includeMedia) {
        $arguments += '--include-media'
    }

    & php @arguments | Out-Host
    if ($LASTEXITCODE -ne 0) {
        throw "The staged package does not match the canonical file set: $StageRoot"
    }
}

# Copy only the canonical listed paths into a new staging tree and verify the result.
function New-PackageStage {
    param(
        [string]$StageRoot
    )

    New-Item -ItemType Directory -Path $StageRoot -Force | Out-Null
    $paths = Get-PackagePaths
    foreach ($relativePath in $paths) {
        $portablePath = [string]$relativePath
        $sourcePath = Join-Path $root ($portablePath.Replace('/', [System.IO.Path]::DirectorySeparatorChar))
        $destinationPath = Join-Path $StageRoot ($portablePath.Replace('/', [System.IO.Path]::DirectorySeparatorChar))
        $destinationDirectory = Split-Path $destinationPath -Parent
        New-Item -ItemType Directory -Path $destinationDirectory -Force | Out-Null
        Copy-Item -LiteralPath $sourcePath -Destination $destinationPath
    }

    Test-PackageTree -StageRoot $StageRoot
    return ,$paths
}

# Claim a new output folder and copy only paths from a verified staging tree.
function Publish-PackageFolder {
    param(
        [string]$StageRoot,
        [string]$DestinationRoot,
        [string[]]$PackagePaths
    )

    New-Item -ItemType Directory -Path $DestinationRoot -ErrorAction Stop | Out-Null
    foreach ($relativePath in $PackagePaths) {
        $portablePath = [string]$relativePath
        $sourcePath = Join-Path $StageRoot ($portablePath.Replace('/', [System.IO.Path]::DirectorySeparatorChar))
        $destinationPath = Join-Path $DestinationRoot ($portablePath.Replace('/', [System.IO.Path]::DirectorySeparatorChar))
        $destinationDirectory = Split-Path $destinationPath -Parent
        New-Item -ItemType Directory -Path $destinationDirectory -Force | Out-Null
        [System.IO.File]::Copy($sourcePath, $destinationPath, $false)
    }

    Test-PackageTree -StageRoot $DestinationRoot
}

# Resolve a user-selected destination to an absolute path without following its final link.
function Get-AbsoluteDestination {
    param(
        [string]$Path
    )

    if ([System.IO.Path]::IsPathRooted($Path)) {
        return [System.IO.Path]::GetFullPath($Path)
    }

    return [System.IO.Path]::GetFullPath((Join-Path $root $Path))
}

# Reject an existing destination ancestor that could redirect output through a junction or symlink.
function Assert-NoReparseAncestors {
    param(
        [string]$Path
    )

    $currentPath = Split-Path ([System.IO.Path]::GetFullPath($Path)) -Parent
    while ($currentPath) {
        if (Test-Path -LiteralPath $currentPath) {
            $currentItem = Get-Item -LiteralPath $currentPath -Force
            if ($currentItem.Attributes -band [System.IO.FileAttributes]::ReparsePoint) {
                throw "Local deploy destination cannot pass through a symbolic link or junction: $currentPath"
            }
        }

        $parentPath = Split-Path $currentPath -Parent
        if (-not $parentPath -or $parentPath -eq $currentPath) {
            break
        }
        $currentPath = $parentPath
    }
}

# Upload one validated relative path to the requested FTP folder.
function Send-DeployFile {
    param(
        [string]$RelativePath,
        [string]$StageRoot
    )

    $portablePath = $RelativePath.Replace('\', '/')
    $remoteBase = ("ftp://{0}/{1}" -f $HostName, $RemoteFolder.Trim('/')).TrimEnd('/')
    $relativeDirectory = Split-Path $portablePath -Parent
    if ($relativeDirectory) {
        Ensure-RemoteDirectory "$remoteBase/$($relativeDirectory.Replace('\', '/'))"
    }

    $request = [System.Net.FtpWebRequest]::Create("$remoteBase/$portablePath")
    $request.Method = [System.Net.WebRequestMethods+Ftp]::UploadFile
    $request.Credentials = [System.Net.NetworkCredential]::new($UserName, $Password)
    $bytes = [System.IO.File]::ReadAllBytes((Join-Path $StageRoot ($portablePath.Replace('/', [System.IO.Path]::DirectorySeparatorChar))))
    $request.ContentLength = $bytes.Length
    $stream = $request.GetRequestStream()
    try {
        $stream.Write($bytes, 0, $bytes.Length)
    } finally {
        $stream.Dispose()
    }
    $response = $request.GetResponse()
    $response.Dispose()
    Write-Host "Uploaded $portablePath"
}

# Build an uncompressed ZIP with portable file names from a verified staging tree.
function New-CompatibleZipArchive {
    param(
        [string]$SourceDirectory,
        [string]$DestinationZip
    )

    Add-Type -AssemblyName System.IO.Compression
    Add-Type -AssemblyName System.IO.Compression.FileSystem
    $sourcePath = [System.IO.Path]::GetFullPath($SourceDirectory).TrimEnd('\', '/')
    $archive = [System.IO.Compression.ZipFile]::Open($DestinationZip, [System.IO.Compression.ZipArchiveMode]::Create)
    try {
        Get-ChildItem -LiteralPath $sourcePath -Force -Recurse -File | ForEach-Object {
            $entryName = $_.FullName.Substring($sourcePath.Length).TrimStart('\', '/').Replace('\', '/')
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

# Create remote FTP directories required by a validated file path.
function Ensure-RemoteDirectory {
    param(
        [string]$Uri
    )

    $parts = ([Uri]$Uri).AbsolutePath.Trim('/').Split('/')
    $current = "ftp://$HostName"
    foreach ($part in $parts) {
        if (-not $part) {
            continue
        }

        $current = "$current/$part"
        try {
            $request = [System.Net.FtpWebRequest]::Create($current)
            $request.Method = [System.Net.WebRequestMethods+Ftp]::MakeDirectory
            $request.Credentials = [System.Net.NetworkCredential]::new($UserName, $Password)
            $response = $request.GetResponse()
            $response.Dispose()
        } catch {
            # The directory may already exist; the following upload reports real failures.
        }
    }
}

if (-not $Mode) {
    $answer = Read-Host "Deployment mode: local deploy folder or FTP upload? [L/f]"
    $Mode = if ($answer -match '^[Ff]') { 'ftp' } else { 'local' }
}
if ($PSBoundParameters.ContainsKey('UploadMedia')) {
    $includeMedia = Test-Truthy -Value $UploadMedia
} else {
    $includeMedia = (Read-Host "Upload media folders? y/N") -match '^[Yy]'
}
if ($PSBoundParameters.ContainsKey('IncludeTests')) {
    $includeRepositoryTests = Test-Truthy -Value $IncludeTests
} elseif ($Mode -eq 'local') {
    $includeRepositoryTests = (Read-Host "Include tests folder? y/N") -match '^[Yy]'
}
if ($includeRepositoryTests -and $Mode -eq 'ftp') {
    throw 'Tests may be included only in local deployment folders or ZIP packages.'
}
if ($Mode -eq 'ftp') {
    if (-not $HostName) { $HostName = Read-Host "FTP host" }
    if (-not $UserName) { $UserName = Read-Host "FTP user" }
    if (-not $Password) { $Password = Read-Host "FTP password" }
    if (-not $RemoteFolder) { $RemoteFolder = Read-Host "Remote folder" }
} elseif (-not $DeployFolder) {
    $DeployFolder = Read-Host "Local deploy folder [deploy/package]"
    if (-not $DeployFolder) { $DeployFolder = 'deploy/package' }
}
if ($Mode -eq 'local') {
    if ($PSBoundParameters.ContainsKey('MakeZipDeploy')) {
        $zipDeploy = Test-Truthy -Value $MakeZipDeploy
    } else {
        $zipAnswer = Read-Host "Make a zip deploy? Y/n"
        $zipDeploy = -not ($zipAnswer -match '^[Nn]')
    }
}

Test-CurrentManifest
if ($Mode -eq 'local') {
    $deployTarget = Get-AbsoluteDestination -Path $DeployFolder
    if ($deployTarget -eq $root -or $root.StartsWith($deployTarget.TrimEnd('\', '/') + [System.IO.Path]::DirectorySeparatorChar, [System.StringComparison]::OrdinalIgnoreCase)) {
        throw 'Local deploy target cannot be the project root or one of its parent directories.'
    }
    if ($includeMedia) {
        $galleryRoot = [System.IO.Path]::GetFullPath((Join-Path $root 'galleries')).TrimEnd('\', '/')
        if ($deployTarget.Equals($galleryRoot, [System.StringComparison]::OrdinalIgnoreCase) -or $deployTarget.StartsWith($galleryRoot + [System.IO.Path]::DirectorySeparatorChar, [System.StringComparison]::OrdinalIgnoreCase)) {
            throw 'Media-enabled local output cannot be created inside the source galleries tree.'
        }
    }
    Assert-NoReparseAncestors -Path $deployTarget
    if ((Test-Path -LiteralPath $deployTarget) -and ((Get-Item -LiteralPath $deployTarget -Force).Attributes -band [System.IO.FileAttributes]::ReparsePoint)) {
        throw "Local deploy target cannot be a symbolic link or junction: $deployTarget"
    }

    if ($zipDeploy) {
        if ((Test-Path -LiteralPath $deployTarget) -and -not (Test-Path -LiteralPath $deployTarget -PathType Container)) {
            throw "ZIP deploy destination must be a directory: $deployTarget"
        }
        $zipPath = Join-Path $deployTarget 'php-gallery-deploy.zip'
        if (Test-Path -LiteralPath $zipPath) {
            throw "Refusing to overwrite an existing ZIP deploy: $zipPath"
        }
    } elseif (Test-Path -LiteralPath $deployTarget) {
        throw "Refusing to replace an existing deploy directory: $deployTarget"
    }
}
$stageParent = [System.IO.Path]::GetTempPath()
if ($Mode -eq 'local' -and -not $zipDeploy) {
    $stageParent = Split-Path $deployTarget -Parent
    if (-not (Test-Path -LiteralPath $stageParent -PathType Container)) {
        New-Item -ItemType Directory -Path $stageParent -Force | Out-Null
    }
}
$stagePath = Join-Path $stageParent ('.php-gallery-stage-{0}' -f [guid]::NewGuid().ToString('N'))
$stageOwned = $false
$temporaryArchiveDirectory = $null
$temporaryArchiveOwned = $false
try {
    New-Item -ItemType Directory -Path $stagePath -ErrorAction Stop | Out-Null
    $stageOwned = $true
    $paths = New-PackageStage -StageRoot $stagePath

    if ($Mode -eq 'local') {
        $deployTarget = Get-AbsoluteDestination -Path $DeployFolder
        if ($deployTarget -eq $root) {
            throw 'Local deploy target cannot be the project root.'
        }
        if ((Test-Path -LiteralPath $deployTarget) -and ((Get-Item -LiteralPath $deployTarget -Force).Attributes -band [System.IO.FileAttributes]::ReparsePoint)) {
            throw "Local deploy target cannot be a symbolic link or junction: $deployTarget"
        }

        if ($zipDeploy) {
            if ((Test-Path -LiteralPath $deployTarget) -and -not (Test-Path -LiteralPath $deployTarget -PathType Container)) {
                throw "ZIP deploy destination must be a directory: $deployTarget"
            }
            New-Item -ItemType Directory -Path $deployTarget -Force | Out-Null
            $zipPath = Join-Path $deployTarget 'php-gallery-deploy.zip'
            if (Test-Path -LiteralPath $zipPath) {
                throw "Refusing to overwrite an existing ZIP deploy: $zipPath"
            }

            $temporaryArchiveDirectory = Join-Path $deployTarget ('.php-gallery-zip-{0}' -f [guid]::NewGuid().ToString('N'))
            New-Item -ItemType Directory -Path $temporaryArchiveDirectory -ErrorAction Stop | Out-Null
            $temporaryArchiveOwned = $true
            $temporaryArchive = Join-Path $temporaryArchiveDirectory 'php-gallery-deploy.zip'
            New-CompatibleZipArchive -SourceDirectory $stagePath -DestinationZip $temporaryArchive
            $archiveItem = Get-Item -LiteralPath $temporaryArchive -Force
            if ($archiveItem.PSIsContainer -or ($archiveItem.Attributes -band [System.IO.FileAttributes]::ReparsePoint)) {
                throw 'The ZIP builder did not create a regular archive file.'
            }
            Add-Type -AssemblyName System.IO.Compression
            $archiveCheck = [System.IO.Compression.ZipFile]::OpenRead($temporaryArchive)
            $archiveCheck.Dispose()
            [System.IO.File]::Move($temporaryArchive, $zipPath)
            Remove-Item -LiteralPath $temporaryArchiveDirectory -Recurse -Force
            $temporaryArchiveDirectory = $null
            $temporaryArchiveOwned = $false
            Write-Host "Local zip deploy created at $zipPath"
        } else {
            if (Test-Path -LiteralPath $deployTarget) {
                throw "Refusing to replace an existing deploy directory: $deployTarget"
            }
            Publish-PackageFolder -StageRoot $stagePath -DestinationRoot $deployTarget -PackagePaths $paths
            Write-Host "Local deploy folder created at $deployTarget"
        }
    } else {
        foreach ($relativePath in $paths) {
            Send-DeployFile -RelativePath ([string]$relativePath) -StageRoot $stagePath
        }
    }
} finally {
    # Remove only the unique temporary paths created by this invocation.
    if ($stageOwned -and (Test-Path -LiteralPath $stagePath)) {
        Remove-Item -LiteralPath $stagePath -Recurse -Force
    }
    if ($temporaryArchiveOwned -and $temporaryArchiveDirectory -and (Test-Path -LiteralPath $temporaryArchiveDirectory)) {
        Remove-Item -LiteralPath $temporaryArchiveDirectory -Recurse -Force
    }
}
