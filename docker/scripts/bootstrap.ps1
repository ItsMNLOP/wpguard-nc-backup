<#
.SYNOPSIS
  One-command bootstrap of the disposable wpguard-test stack: generates
  secrets, TLS CA, brings up containers, installs WordPress (source +
  restore target) and Nextcloud, creates a dedicated non-admin Nextcloud
  backup account + app password + destination folder, and seeds source
  fixture content. Safe to re-run; skips steps already done where practical.
  Idempotent only up to container state - for a truly clean run, use
  cleanup.ps1 first.
#>
$ErrorActionPreference = "Stop"
$root = Split-Path -Parent (Split-Path -Parent $PSScriptRoot)
$docker = Join-Path $root "docker"
Set-Location $docker

function New-Secret {
    $bytes = New-Object byte[] 24
    [System.Security.Cryptography.RandomNumberGenerator]::Create().GetBytes($bytes)
    ([Convert]::ToBase64String($bytes) -replace '[+/=]', '') -replace '^(.{20}).*', '$1'
}

if (-not (Test-Path ".env")) {
    Write-Host "Generating .env with fresh random secrets..."
    $vars = [ordered]@{
        COMPOSE_PROJECT_NAME        = "wpguard-test"
        WP_SOURCE_DB_ROOT_PASSWORD  = New-Secret
        WP_SOURCE_DB_NAME           = "wp_source"
        WP_SOURCE_DB_USER           = "wp_source"
        WP_SOURCE_DB_PASSWORD       = New-Secret
        WP_SOURCE_TABLE_PREFIX      = "wps_"
        WP_RESTORE_DB_ROOT_PASSWORD = New-Secret
        WP_RESTORE_DB_NAME          = "wp_restore"
        WP_RESTORE_DB_USER          = "wp_restore"
        WP_RESTORE_DB_PASSWORD      = New-Secret
        WP_RESTORE_TABLE_PREFIX     = "wpr_"
        NC_DB_ROOT_PASSWORD         = New-Secret
        NC_DB_NAME                  = "nextcloud"
        NC_DB_USER                  = "nextcloud"
        NC_DB_PASSWORD              = New-Secret
        NEXTCLOUD_ADMIN_USER        = "ncadmin"
        NEXTCLOUD_ADMIN_PASSWORD    = New-Secret
        WP_ADMIN_USER               = "wpadmin"
        WP_ADMIN_PASSWORD           = New-Secret
        WP_ADMIN_EMAIL              = "wpadmin@wpguard-test.local"
        NC_BACKUP_USER              = "wpguard-backup"
        NC_BACKUP_USER_PASSWORD     = New-Secret
    }
    $lines = $vars.GetEnumerator() | ForEach-Object { "$($_.Key)=$($_.Value)" }
    Set-Content -Path ".env" -Value $lines -Encoding utf8
} else {
    Write-Host ".env already exists, reusing it"
}

Write-Host "Generating TLS CA + nc-proxy cert (if missing)..."
& bash "scripts/gen-certs.sh"
if ($LASTEXITCODE -ne 0) { throw "gen-certs.sh failed" }

Write-Host "Bringing up containers..."
docker compose --env-file .env -f docker-compose.yml up -d
if ($LASTEXITCODE -ne 0) { throw "docker compose up failed" }

function Wait-For($desc, [scriptblock]$check, $timeoutSec = 180) {
    Write-Host -NoNewline "Waiting for $desc"
    $elapsed = 0
    while ($elapsed -lt $timeoutSec) {
        if (& $check) { Write-Host " ready"; return }
        Write-Host -NoNewline "."
        Start-Sleep -Seconds 3
        $elapsed += 3
    }
    throw "$desc did not become ready within ${timeoutSec}s"
}

Wait-For "db-source" { (docker compose exec -T db-source mariadb-admin ping -h 127.0.0.1 2>$null) -match "mysqld is alive" }
Wait-For "db-nextcloud" { (docker compose exec -T db-nextcloud mariadb-admin ping -h 127.0.0.1 2>$null) -match "mysqld is alive" }
Wait-For "db-restore" { (docker compose exec -T db-restore mariadb-admin ping -h 127.0.0.1 2>$null) -match "mysqld is alive" }
Wait-For "wp-source http" { try { (Invoke-WebRequest -Uri "http://127.0.0.1:8081/wp-login.php" -UseBasicParsing -TimeoutSec 5).StatusCode -in 200,302 } catch { $false } }
Wait-For "nextcloud http (via proxy)" { try { (Invoke-WebRequest -Uri "https://127.0.0.1:8443/status.php" -UseBasicParsing -TimeoutSec 5 -SkipCertificateCheck).StatusCode -eq 200 } catch { $false } }

function Env($name) { (Get-Content .env | Where-Object { $_ -like "$name=*" }) -replace "^$name=", "" }

$wpAdminUser = Env "WP_ADMIN_USER"
$wpAdminPass = Env "WP_ADMIN_PASSWORD"
$wpAdminEmail = Env "WP_ADMIN_EMAIL"
$ncAdminUser = Env "NEXTCLOUD_ADMIN_USER"
$ncAdminPass = Env "NEXTCLOUD_ADMIN_PASSWORD"
$ncBackupUser = Env "NC_BACKUP_USER"
$ncBackupPass = Env "NC_BACKUP_USER_PASSWORD"

Write-Host "Installing WordPress (source)..."
docker compose run --rm wpcli wp --path=/var/www/html-source core is-installed 2>$null
if ($LASTEXITCODE -ne 0) {
    docker compose run --rm wpcli wp --path=/var/www/html-source core install `
        --url="http://wp-source" --title="WPGuard Source Test Site" `
        --admin_user="$wpAdminUser" --admin_password="$wpAdminPass" --admin_email="$wpAdminEmail" --skip-email
}

Write-Host "Activating plugin on source site..."
docker compose run --rm wpcli wp --path=/var/www/html-source plugin activate wpguard-nc-backup

Write-Host "Seeding fixture content on source site..."
docker compose run --rm wpcli wp --path=/var/www/html-source eval-file /fixtures/seed-fixtures.php

Write-Host "Creating dedicated non-admin Nextcloud backup account..."
docker compose exec -T nextcloud php occ user:add --password-from-env --display-name="WPGuard Backup (dedicated, non-admin)" "$ncBackupUser" `
    --group-add="" 2>$null
$env:OC_PASS = $ncBackupPass
docker compose exec -T -e OC_PASS="$ncBackupPass" nextcloud php occ user:add --password-from-env "$ncBackupUser" 2>&1 | Out-String | Write-Host

Write-Host "Generating Nextcloud app password for the backup account via OCS API..."
$caPath = (Resolve-Path "certs/wpguard-test-ca.crt").Path
$pair = "$($ncBackupUser):$($ncBackupPass)"
$basic = [Convert]::ToBase64String([Text.Encoding]::ASCII.GetBytes($pair))
$resp = Invoke-RestMethod -Method Post `
    -Uri "https://127.0.0.1:8443/ocs/v2.php/core/getapppassword" `
    -Headers @{ Authorization = "Basic $basic"; "OCS-APIRequest" = "true"; Accept = "application/json" } `
    -SkipCertificateCheck
$appPassword = $resp.ocs.data.apppassword
if (-not $appPassword) { throw "failed to obtain Nextcloud app password: $($resp | ConvertTo-Json -Depth 5)" }

Write-Host "Creating destination backup folder in Nextcloud..."
$folderPair = "$($ncBackupUser):$($appPassword)"
$folderBasic = [Convert]::ToBase64String([Text.Encoding]::ASCII.GetBytes($folderPair))
Invoke-WebRequest -Method "MKCOL" -Uri "https://127.0.0.1:8443/remote.php/dav/files/$ncBackupUser/wpguard-backups" `
    -Headers @{ Authorization = "Basic $folderBasic" } -SkipCertificateCheck -UseBasicParsing | Out-Null

Write-Host "Configuring plugin settings on source site (admin-entered values, via wp-cli for repeatable testing)..."
docker compose run --rm wpcli wp --path=/var/www/html-source option update wpguard_nc_settings (@{
    nextcloud_url    = "https://nc-proxy"
    nextcloud_user   = $ncBackupUser
    app_password     = $appPassword
    remote_folder    = "wpguard-backups"
} | ConvertTo-Json -Compress) --format=json

Write-Host ""
Write-Host "=== Bootstrap complete ==="
Write-Host "Source site:   http://127.0.0.1:8081/wp-admin  (user: $wpAdminUser)"
Write-Host "Restore target:http://127.0.0.1:8082/wp-admin  (not yet installed - restore test installs it)"
Write-Host "Nextcloud:     https://127.0.0.1:8443  (admin: $ncAdminUser)"
Write-Host "Backup account:$ncBackupUser (dedicated, non-admin, app password generated and stored in plugin settings for automated testing)"
Write-Host "Secrets are in .env and were not printed above except where needed for this console session; do not commit .env."
