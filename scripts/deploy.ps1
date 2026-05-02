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
    [string]$UpdateManifest
)

if (-not $Mode) {
    # Variable $answer stores this scripts working value.
    $answer = Read-Host "Deployment mode: local deploy folder or FTP upload? [L/f]"
    # Variable $Mode stores this scripts working value.
    $Mode = if ($answer -match '^[Ff]') { 'ftp' } else { 'local' }
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
if (-not $includeMedia) { $excludeDirs += 'galleries' }
# Variable $excludeFiles stores this scripts working value.
$excludeFiles = @('.gitignore', 'config.php', '.env', '*.log', '*.tmp')


# Function `Invoke-ManifestGenerator` handles manifest refresh before deployment.
function Invoke-ManifestGenerator {
    # Variable $phpCommand stores this scripts working value.
    $phpCommand = Get-Command php -ErrorAction SilentlyContinue
    if (-not $phpCommand) {
        throw "PHP executable was not found in PATH. Cannot update app/core-manifest.json."
    }

    # Variable $manifestScript stores this scripts working value.
    $manifestScript = Join-Path $root 'scripts\generate_manifest.php'
    if (-not (Test-Path $manifestScript)) {
        throw "Manifest generator was not found: $manifestScript"
    }

    Write-Host "Updating integrity manifest..."
    & php $manifestScript
    if ($LASTEXITCODE -ne 0) {
        throw "Manifest generator failed with exit code $LASTEXITCODE."
    }
}

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
    if ($portableRelative -eq 'cache/.htaccess' -or $portableRelative -eq 'galleries/.htaccess') {
        return $false
    }
    foreach ($dir in $excludeDirs) {
        if ($relative -match "^[.\\/]?$([regex]::Escape($dir))([\\/]|$)") { return $true }
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

    Add-Type -AssemblyName System.IO.Compression.FileSystem

    # NoCompression creates a plain stored ZIP archive. That keeps the file format simple for older hosting tools.
    [System.IO.Compression.ZipFile]::CreateFromDirectory(
        $SourceDirectory,
        $DestinationZip,
        [System.IO.Compression.CompressionLevel]::NoCompression,
        $false
    )
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

# Variable $refreshManifest stores this scripts working value.
$refreshManifest = $true
if ($PSBoundParameters.ContainsKey('UpdateManifest')) {
    # Variable $refreshManifest stores this scripts working value.
    $refreshManifest = ($UpdateManifest -match '^(1|true|yes|y)$')
} else {
    # Variable $manifestAnswer stores this scripts working value.
    $manifestAnswer = Read-Host "Update integrity manifest before deploy? Y/n"
    # Variable $refreshManifest stores this scripts working value.
    $refreshManifest = -not ($manifestAnswer -match '^[Nn]')
}

if ($refreshManifest) {
    Invoke-ManifestGenerator
}
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
