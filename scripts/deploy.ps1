param(
    [string]$HostName,
    [string]$UserName,
    [string]$Password,
    [string]$RemoteFolder,
    [switch]$UploadMedia
)

if (-not $HostName) { $HostName = Read-Host "FTP host" }
if (-not $UserName) { $UserName = Read-Host "FTP user" }
if (-not $Password) { $Password = Read-Host "FTP password" }
if (-not $RemoteFolder) { $RemoteFolder = Read-Host "Remote folder" }
if (-not $PSBoundParameters.ContainsKey('UploadMedia')) {
    $UploadMedia = ((Read-Host "Upload media folders? y/N") -match '^[Yy]')
}

$root = Resolve-Path "$PSScriptRoot\.."
$excludeDirs = @('.git', 'cache', 'logs', 'tmp')
if (-not $UploadMedia) { $excludeDirs += 'galleries' }
$excludeFiles = @('config.php', '.env', '*.log', '*.tmp')

function Should-Skip($Path) {
    $relative = Resolve-Path $Path -Relative
    foreach ($dir in $excludeDirs) {
        if ($relative -match "^[.\\/]?$([regex]::Escape($dir))([\\/]|$)") { return $true }
    }
    foreach ($pattern in $excludeFiles) {
        if ([System.Management.Automation.WildcardPattern]::new($pattern).IsMatch((Split-Path $Path -Leaf))) { return $true }
    }
    return $false
}

function Upload-File($LocalPath) {
    $relative = (Resolve-Path $LocalPath -Relative).TrimStart('.', '\', '/').Replace('\', '/')
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
Get-ChildItem -Recurse -File | Where-Object { -not (Should-Skip $_.FullName) } | ForEach-Object {
    Upload-File $_.FullName
}
