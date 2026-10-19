<#
.SYNOPSIS
  Runs the full backup -> verify -> restore -> content-assertion E2E
  drill (tests/e2e/run-e2e.sh) against the stack bootstrap.ps1 started.
#>
$ErrorActionPreference = "Stop"
$root = Split-Path -Parent (Split-Path -Parent $PSScriptRoot)
$gitBash = "C:\Program Files\Git\bin\bash.exe"
if (-not (Test-Path $gitBash)) { $gitBash = "bash" }
& $gitBash "$root/tests/e2e/run-e2e.sh".Replace("\", "/")
if ($LASTEXITCODE -ne 0) { throw "E2E drill failed (exit $LASTEXITCODE)" }
Write-Host "E2E drill passed." -ForegroundColor Green
