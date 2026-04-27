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
    $answer = Read-Host "Deployment mode: FTP upload or local deploy folder? [F/l]"
    $Mode = if ($answer -match '^[Ll]') { 'local' } else { 'ftp' }
}
$includeMedia = $false
if ($PSBoundParameters.ContainsKey('UploadMedia')) {
    $includeMedia = ($UploadMedia -match '^(1|true|yes|y)$')
} else {
    $includeMedia = ((Read-Host "Upload media folders? y/N") -match '^[Yy]')
}
if ($Mode -eq 'ftp') {
    if (-not $HostName) { $HostName = Read-Host "FTP host" }
    if (-not $UserName) { $UserName = Read-Host "FTP user" }
    if (-not $Password) { $Password = Read-Host "FTP password" }
    if (-not $RemoteFolder) { $RemoteFolder = Read-Host "Remote folder" }
}
if ($Mode -eq 'local' -and -not $DeployFolder) {
    $DeployFolder = Read-Host "Local deploy folder [deploy]"
    if (-not $DeployFolder) { $DeployFolder = 'deploy' }
}

$root = Resolve-Path "$PSScriptRoot\.."
$excludeDirs = @('.git', 'cache', 'logs', 'tmp', 'deploy')
if (-not $includeMedia) { $excludeDirs += 'galleries' }
$excludeFiles = @('.gitignore', 'config.php', '.env', '*.log', '*.tmp')

function Get-DeployRelativePath($Path) {
    $fullPath = [System.IO.Path]::GetFullPath($Path)
    $rootPath = [System.IO.Path]::GetFullPath($root)
    return $fullPath.Substring($rootPath.Length).TrimStart('\', '/')
}

function Should-Skip($Path) {
    $relative = Get-DeployRelativePath $Path
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

function Upload-File($LocalPath) {
    $relative = (Get-DeployRelativePath $LocalPath).Replace('\', '/')
    $remoteBase = ("ftp://{0}/{1}" -f $HostName, $RemoteFolder.Trim('/')).TrimEnd('/')
    $relativeDir = Split-Path $relative -Parent
    if ($relativeDir) {
        Ensure-RemoteDirectory "$remoteBase/$($relativeDir.Replace('\', '/'))"
    }
    $uri = "$remoteBase/$relative"
    $request = [System.Net.FtpWebRequest]::Create($uri)
    $request.Method = [System.Net.WebRequestMethods+Ftp]::UploadFile
    $request.Credentials = New-Object System.Net.NetworkCredential($UserName, $Password)
    $bytes = [System.IO.File]::ReadAllBytes($LocalPath)
    $request.ContentLength = $bytes.Length
    $stream = $request.GetRequestStream()
    $stream.Write($bytes, 0, $bytes.Length)
    $stream.Close()
    $response = $request.GetResponse()
    $response.Close()
    Write-Host "Uploaded $relative"
}

function Copy-DeployFile($LocalPath) {
    $relative = Get-DeployRelativePath $LocalPath
    $destination = Join-Path $script:DeployTarget $relative
    $destinationDir = Split-Path $destination -Parent
    if (-not (Test-Path $destinationDir)) {
        New-Item -ItemType Directory -Path $destinationDir -Force | Out-Null
    }
    Copy-Item -LiteralPath $LocalPath -Destination $destination -Force
    Write-Host "Copied $relative"
}

function Ensure-RemoteDirectory($Uri) {
    $parts = ([Uri]$Uri).AbsolutePath.Trim('/').Split('/')
    $current = "ftp://$HostName"
    foreach ($part in $parts) {
        if (-not $part) { continue }
        $current = "$current/$part"
        try {
            $request = [System.Net.FtpWebRequest]::Create($current)
            $request.Method = [System.Net.WebRequestMethods+Ftp]::MakeDirectory
            $request.Credentials = New-Object System.Net.NetworkCredential($UserName, $Password)
            $response = $request.GetResponse()
            $response.Close()
        } catch {
        }
    }
}

Set-Location $root
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
