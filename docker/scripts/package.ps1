<#
.SYNOPSIS
  Builds the installable plugin ZIP from plugin/wpguard-nc-backup/ only
  (no test fixtures, no dev tooling, no .env/.git) into dist/.

  Entries are written manually with forward-slash separators via
  System.IO.Compression directly (not Compress-Archive) -- Compress-Archive
  stores backslash separators on Windows, which PHP's ZipArchive/PclZip on
  a real Linux host takes literally instead of treating as a directory
  separator, so extracted files never land at the expected path and
  WordPress reports "Plugin file does not exist." on activation.
#>
$ErrorActionPreference = "Stop"
$root = Split-Path -Parent (Split-Path -Parent $PSScriptRoot)
$pluginDir = Join-Path $root "plugin\wpguard-nc-backup"
$distDir = Join-Path $root "dist"
New-Item -ItemType Directory -Force -Path $distDir | Out-Null

$version = (Select-String -Path (Join-Path $pluginDir "wpguard-nc-backup.php") -Pattern "Version:\s+([\d.]+)").Matches[0].Groups[1].Value
$zipPath = Join-Path $distDir "wpguard-nc-backup-$version.zip"

if (Test-Path $zipPath) { Remove-Item $zipPath -Force }

Add-Type -AssemblyName System.IO.Compression
Add-Type -AssemblyName System.IO.Compression.FileSystem

$zip = [System.IO.Compression.ZipFile]::Open($zipPath, [System.IO.Compression.ZipArchiveMode]::Create)
try {
    Get-ChildItem -Path $pluginDir -Recurse -File | ForEach-Object {
        $relative = $_.FullName.Substring($pluginDir.Length + 1) -replace '\\', '/'
        $entryName = "wpguard-nc-backup/$relative"
        [System.IO.Compression.ZipFileExtensions]::CreateEntryFromFile(
            $zip, $_.FullName, $entryName, [System.IO.Compression.CompressionLevel]::Optimal
        ) | Out-Null
    }
}
finally {
    $zip.Dispose()
}

Write-Host "Built: $zipPath"
Get-Item $zipPath | Select-Object FullName, Length
