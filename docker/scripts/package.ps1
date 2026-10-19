<#
.SYNOPSIS
  Builds the installable plugin ZIP from plugin/wpguard-nc-backup/ only
  (no test fixtures, no dev tooling, no .env/.git) into dist/.
#>
$ErrorActionPreference = "Stop"
$root = Split-Path -Parent (Split-Path -Parent $PSScriptRoot)
$pluginDir = Join-Path $root "plugin\wpguard-nc-backup"
$distDir = Join-Path $root "dist"
New-Item -ItemType Directory -Force -Path $distDir | Out-Null

$version = (Select-String -Path (Join-Path $pluginDir "wpguard-nc-backup.php") -Pattern "Version:\s+([\d.]+)").Matches[0].Groups[1].Value
$zipPath = Join-Path $distDir "wpguard-nc-backup-$version.zip"

if (Test-Path $zipPath) { Remove-Item $zipPath -Force }

$tempStage = Join-Path $env:TEMP "wpguard-nc-backup-stage"
if (Test-Path $tempStage) { Remove-Item $tempStage -Recurse -Force }
New-Item -ItemType Directory -Path (Join-Path $tempStage "wpguard-nc-backup") | Out-Null
Copy-Item -Path "$pluginDir\*" -Destination (Join-Path $tempStage "wpguard-nc-backup") -Recurse -Force

Compress-Archive -Path (Join-Path $tempStage "wpguard-nc-backup") -DestinationPath $zipPath -CompressionLevel Optimal
Remove-Item $tempStage -Recurse -Force

Write-Host "Built: $zipPath"
Get-Item $zipPath | Select-Object FullName, Length
