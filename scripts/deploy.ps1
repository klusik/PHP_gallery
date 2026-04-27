param(
    [ValidateSet('ftp', 'local')]
    [string]$Mode,
    [string]$HostName,
    [string]$UserName,
    [string]$Password,
    [string]$RemoteFolder,
    [string]$DeployFolder,
    [string]$UploadMedia
)

if (-not $Mode) {
    # Variable $answer stores this scripts working value.
    $answer = Read-Host "Deployment mode: FTP upload or local deploy folder? [F/l]"
    # Variable $Mode stores this scripts working value.
    $Mode = if ($answer -match '^[Ll]') { 'local' } else { 'ftp' }
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

# Variable $root stores this scripts working value.
$root = Resolve-Path "$PSScriptRoot\.."
# Variable $excludeDirs stores this scripts working value.
$excludeDirs = @('.git', 'cache', 'logs', 'tmp', 'deploy')
if (-not $includeMedia) { $excludeDirs += 'galleries' }
# Variable $excludeFiles stores this scripts working value.
$excludeFiles = @('.gitignore', 'config.php', '.env', '*.log', '*.tmp')

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
# Variable $files stores this scripts working value.
$files = Get-ChildItem -Recurse -File | Where-Object { -not (Should-Skip $_.FullName) }

if ($Mode -eq 'local') {
    $script:DeployTarget = if ([System.IO.Path]::IsPathRooted($DeployFolder)) {
        $DeployFolder
    } else {
        Join-Path $root $DeployFolder
    }
    if (Test-Path $script:DeployTarget) {
        Remove-Item -LiteralPath $script:DeployTarget -Recurse -Force
    }
    New-Item -ItemType Directory -Path $script:DeployTarget -Force | Out-Null
    $files | ForEach-Object {
        Copy-DeployFile $_.FullName
    }
    Write-Host "Local deploy folder created at $script:DeployTarget"
} else {
    $files | ForEach-Object {
        Upload-File $_.FullName
    }
}
