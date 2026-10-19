<#
.SYNOPSIS
  Tears down ONLY wpguard-test's own containers/volumes/network. Never
  touches anything else on the machine.
#>
$ErrorActionPreference = "Stop"
$root = Split-Path -Parent (Split-Path -Parent $PSScriptRoot)
Set-Location (Join-Path $root "docker")
docker compose -p wpguard-test -f docker-compose.yml down -v
Write-Host "wpguard-test containers, volumes, and network removed."
Write-Host "Certs, .env, and dist/ were left in place. Delete docker/certs/*, docker/.env, and dist/ manually if you want a fully clean slate."
