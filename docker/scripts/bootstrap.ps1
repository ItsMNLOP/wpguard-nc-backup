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
$ErrorActionPreference = "Continue"
$root = Split-Path -Parent (Split-Path -Parent $PSScriptRoot)
$docker = Join-Path $root "docker"
Set-Location $docker

# Windows PowerShell 5.1's Invoke-WebRequest has no -SkipCertificateCheck
# (that's PowerShell 7+ only), so this process-wide cert bypass is needed
# for THIS SCRIPT's own host-side convenience calls (creating the test
# Nextcloud account / app password) against our disposable local stack.
# This is a bootstrap-tooling concession, not the plugin: the plugin
# itself (running inside the WP container) verifies TLS for real, via the
# generated CA trusted through WPGUARD_NC_CA_BUNDLE (ADR 0007) — see
# tests/unit/NoInsecureTlsTest.php, which checks the plugin source, not
# this script.
if (-not ("TrustAllCertsPolicy" -as [type])) {
    Add-Type -TypeDefinition @"
        using System.Net;
        using System.Security.Cryptography.X509Certificates;
        public class TrustAllCertsPolicy : ICertificatePolicy {
            public bool CheckValidationResult(ServicePoint sp, X509Certificate cert, WebRequest req, int problem) { return true; }
        }
"@
}
[System.Net.ServicePointManager]::CertificatePolicy = New-Object TrustAllCertsPolicy

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
$gitBash = "C:\Program Files\Git\bin\bash.exe"
if (-not (Test-Path $gitBash)) { $gitBash = "bash" }
& $gitBash "scripts/gen-certs.sh"
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

function Env($name) { (Get-Content .env | Where-Object { $_ -like "$name=*" }) -replace "^$name=", "" }

$wpSourceRootPw = Env "WP_SOURCE_DB_ROOT_PASSWORD"
$ncDbRootPw = Env "NC_DB_ROOT_PASSWORD"
$wpRestoreRootPw = Env "WP_RESTORE_DB_ROOT_PASSWORD"

Wait-For "db-source" { (docker compose exec -T db-source mariadb-admin ping -h 127.0.0.1 -u root -p"$wpSourceRootPw" 2>$null) -match "mysqld is alive" }
Wait-For "db-nextcloud" { (docker compose exec -T db-nextcloud mariadb-admin ping -h 127.0.0.1 -u root -p"$ncDbRootPw" 2>$null) -match "mysqld is alive" }
Wait-For "db-restore" { (docker compose exec -T db-restore mariadb-admin ping -h 127.0.0.1 -u root -p"$wpRestoreRootPw" 2>$null) -match "mysqld is alive" }
Wait-For "wp-source http" { try { (Invoke-WebRequest -Uri "http://127.0.0.1:8081/wp-login.php" -UseBasicParsing -TimeoutSec 5).StatusCode -in 200,302 } catch { $false } }
Wait-For "nextcloud http (via proxy)" { try { (Invoke-WebRequest -Uri "https://127.0.0.1:8443/status.php" -UseBasicParsing -TimeoutSec 5).StatusCode -eq 200 } catch { $false } }

$wpAdminUser = Env "WP_ADMIN_USER"
$wpAdminPass = Env "WP_ADMIN_PASSWORD"
$wpAdminEmail = Env "WP_ADMIN_EMAIL"
$ncAdminUser = Env "NEXTCLOUD_ADMIN_USER"
$ncAdminPass = Env "NEXTCLOUD_ADMIN_PASSWORD"
$ncBackupUser = Env "NC_BACKUP_USER"
$ncBackupPass = Env "NC_BACKUP_USER_PASSWORD"

# wp-config.php reads DB_* via getenv_docker() at PHP runtime, so the
# wpcli container (which has no WORDPRESS_DB_* env of its own in the
# compose file, since it serves two different WP installs) must be
# given the right ones per invocation, matching whichever --path it's
# pointed at. This is also why the one-off "up -d wpcli" container shows
# as Exited shortly after - it's never meant to stay running.
$sourceDbEnv = @(
    "-e", "WORDPRESS_DB_HOST=db-source",
    "-e", "WORDPRESS_DB_NAME=$(Env 'WP_SOURCE_DB_NAME')",
    "-e", "WORDPRESS_DB_USER=$(Env 'WP_SOURCE_DB_USER')",
    "-e", "WORDPRESS_DB_PASSWORD=$(Env 'WP_SOURCE_DB_PASSWORD')"
)
$restoreDbEnv = @(
    "-e", "WORDPRESS_DB_HOST=db-restore",
    "-e", "WORDPRESS_DB_NAME=$(Env 'WP_RESTORE_DB_NAME')",
    "-e", "WORDPRESS_DB_USER=$(Env 'WP_RESTORE_DB_USER')",
    "-e", "WORDPRESS_DB_PASSWORD=$(Env 'WP_RESTORE_DB_PASSWORD')"
)
function Wp-Source { docker compose run --rm @sourceDbEnv wpcli wp --path=/var/www/html-source @args }
function Wp-Restore { docker compose run --rm @restoreDbEnv wpcli wp --path=/var/www/html-restore @args }

Write-Host "Installing WordPress (source)..."
Wp-Source core is-installed 2>$null
if ($LASTEXITCODE -ne 0) {
    Wp-Source core install `
        --url="http://127.0.0.1:8081" --title="WPGuard Source Test Site" `
        --admin_user="$wpAdminUser" --admin_password="$wpAdminPass" --admin_email="$wpAdminEmail" --skip-email
    if ($LASTEXITCODE -ne 0) { throw "WordPress core install (source) failed" }
}

Write-Host "Activating plugin on source site..."
Wp-Source plugin activate wpguard-nc-backup
if ($LASTEXITCODE -ne 0) { throw "plugin activation failed" }

Write-Host "Seeding fixture content on source site..."
Wp-Source eval-file /fixtures/seed-fixtures.php
if ($LASTEXITCODE -ne 0) { throw "fixture seeding failed" }

Write-Host "Creating dedicated non-admin Nextcloud backup account (if not already present)..."
docker compose exec -T nextcloud php occ user:list --output=json 2>$null | Out-String -OutVariable ncUsersJson | Out-Null
$ncUserExists = $false
try { $ncUserExists = ($ncUsersJson | ConvertFrom-Json).PSObject.Properties.Name -contains $ncBackupUser } catch {}
if (-not $ncUserExists) {
    docker compose exec -T -e OC_PASS="$ncBackupPass" nextcloud php occ user:add --password-from-env --display-name="WPGuard Backup (dedicated, non-admin)" "$ncBackupUser"
}

Write-Host "Generating Nextcloud app password for the backup account via OCS API..."
$pair = "$($ncBackupUser):$($ncBackupPass)"
$basic = [Convert]::ToBase64String([Text.Encoding]::ASCII.GetBytes($pair))
$resp = Invoke-RestMethod -Method Get `
    -Uri "https://127.0.0.1:8443/ocs/v2.php/core/getapppassword" `
    -Headers @{ Authorization = "Basic $basic"; "OCS-APIRequest" = "true"; Accept = "application/json" }
$appPassword = $resp.ocs.data.apppassword
if (-not $appPassword) { throw "failed to obtain Nextcloud app password: $($resp | ConvertTo-Json -Depth 5)" }

Write-Host "Configuring plugin settings on source site via Settings::save() (env-passed, never in shell history)..."
docker compose run --rm @sourceDbEnv `
    -e WPGUARD_TEST_NC_URL="https://nc-proxy.wpguard-test.local" `
    -e WPGUARD_TEST_NC_USER="$ncBackupUser" `
    -e WPGUARD_TEST_NC_APP_PASSWORD="$appPassword" `
    -e WPGUARD_TEST_NC_FOLDER="wpguard-backups" `
    wpcli wp --path=/var/www/html-source eval-file /fixtures/configure-plugin.php
if ($LASTEXITCODE -ne 0) { throw "plugin settings configuration failed validation" }

Write-Host ""
Write-Host "=== Bootstrap complete ==="
Write-Host "Source site:   http://127.0.0.1:8081/wp-admin  (user: $wpAdminUser)"
Write-Host "Restore target:http://127.0.0.1:8082/wp-admin  (not yet installed - restore test installs it)"
Write-Host "Nextcloud:     https://127.0.0.1:8443  (admin: $ncAdminUser)"
Write-Host "Backup account:$ncBackupUser (dedicated, non-admin, app password generated and stored in plugin settings for automated testing)"
Write-Host "Secrets are in .env and were not printed above except where needed for this console session; do not commit .env."
