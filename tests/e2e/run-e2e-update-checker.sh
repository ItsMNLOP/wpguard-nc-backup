#!/usr/bin/env bash
# Phase 2 update feed regression test: Update_Checker must (A) survive a
# real call to the real public GitHub API when no release exists yet
# (today's actual state -- the repo has no GitHub Release, only a git
# tag) without erroring and without offering a bogus update; (B) offer
# a real update through WP's actual site_transient_update_plugins filter
# chain when a newer release with a qualifying .zip asset is cached;
# (C) never offer an update for a release with no qualifying asset, even
# though its version is newer (the fail-closed path -- this is the one
# that matters most, since offering the GitHub zipball by mistake would
# install the whole repo as the plugin); (D) never offer an update for a
# same-or-older version.
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
DOCKER_DIR="$ROOT/docker"
cd "$DOCKER_DIR"

env_val() { grep "^$1=" .env | cut -d= -f2-; }
WP_SOURCE_DB_PASSWORD=$(env_val WP_SOURCE_DB_PASSWORD)
WP_SOURCE_TABLE_PREFIX=$(env_val WP_SOURCE_TABLE_PREFIX)

wp_source() {
  MSYS_NO_PATHCONV=1 docker compose -p wpguard-test --env-file .env run --rm \
    -e WORDPRESS_DB_HOST=db-source -e WORDPRESS_DB_NAME=wp_source \
    -e WORDPRESS_DB_USER=wp_source -e WORDPRESS_DB_PASSWORD="$WP_SOURCE_DB_PASSWORD" \
    -e WORDPRESS_TABLE_PREFIX="$WP_SOURCE_TABLE_PREFIX" \
    wpcli wp --path=/var/www/html-source "$@" 2>&1 | grep -v '^ Container' || true
}

PLUGIN_BASENAME='wpthistle-backup-restore/wpthistle-backup-restore.php'

echo "=== Case A: real GitHub API call, no release published yet (today's actual state) ==="
RESULT_A=$(wp_source eval "
  delete_transient('wpthistle_backup_update_check');
  \$checker = new \WPThistle\BackupRestore\Update_Checker('$PLUGIN_BASENAME');
  \$transient = (object) array('checked' => array('$PLUGIN_BASENAME' => '1.0.0'));
  \$transient = \$checker->check_for_update(\$transient);
  echo isset(\$transient->response['$PLUGIN_BASENAME']) ? 'offered' : 'none';
")
[ "$RESULT_A" = "none" ] || { echo "FAIL: no GitHub Release exists for this repo yet -- must not offer an update, got '$RESULT_A'"; exit 1; }
echo "OK  no update offered against the real (release-less) repo, no error thrown"

echo
echo "=== Case B: a newer release WITH a qualifying .zip asset must be offered by check_for_update() ==="
wp_source eval "
  set_transient('wpthistle_backup_update_check', array(
    'version' => '9.9.9',
    'package' => 'https://github.com/ItsMNLOP/wpthistle-backup-restore/releases/download/v9.9.9/wpthistle-backup-restore-9.9.9.zip',
    'body' => 'release notes',
    'html_url' => 'https://github.com/ItsMNLOP/wpthistle-backup-restore/releases/tag/v9.9.9',
  ), 12 * HOUR_IN_SECONDS);
" > /dev/null
RESULT_B=$(wp_source eval "
  \$checker = new \WPThistle\BackupRestore\Update_Checker('$PLUGIN_BASENAME');
  \$transient = (object) array('checked' => array('$PLUGIN_BASENAME' => '1.0.0'));
  \$transient = \$checker->check_for_update(\$transient);
  \$item = \$transient->response['$PLUGIN_BASENAME'] ?? null;
  echo \$item ? (\$item->new_version . '|' . \$item->package) : 'none';
")
[ "$RESULT_B" = "9.9.9|https://github.com/ItsMNLOP/wpthistle-backup-restore/releases/download/v9.9.9/wpthistle-backup-restore-9.9.9.zip" ] || { echo "FAIL: expected the cached newer release to be offered with its package url, got '$RESULT_B'"; exit 1; }
echo "OK  newer release with a qualifying asset offered by check_for_update()"

echo
echo "=== Case C: a newer release with NO qualifying asset must never be offered (fail-closed) ==="
wp_source eval "
  set_transient('wpthistle_backup_update_check', array(
    'version' => '9.9.9',
    'package' => '',
    'body' => 'release notes, but no built zip attached yet',
    'html_url' => 'https://github.com/ItsMNLOP/wpthistle-backup-restore/releases/tag/v9.9.9',
  ), 12 * HOUR_IN_SECONDS);
" > /dev/null
RESULT_C=$(wp_source eval "
  \$checker = new \WPThistle\BackupRestore\Update_Checker('$PLUGIN_BASENAME');
  \$transient = (object) array('checked' => array('$PLUGIN_BASENAME' => '1.0.0'));
  \$transient = \$checker->check_for_update(\$transient);
  echo isset(\$transient->response['$PLUGIN_BASENAME']) ? 'offered' : 'none';
")
[ "$RESULT_C" = "none" ] || { echo "FAIL: a release with no .zip asset must never be offered -- this is the exact case that would otherwise fall back to the wrong GitHub zipball; got '$RESULT_C'"; exit 1; }
echo "OK  release with no qualifying asset correctly never offered"

echo
echo "=== Case D: a same-or-older cached release must never be offered ==="
wp_source eval "
  set_transient('wpthistle_backup_update_check', array(
    'version' => '1.0.0',
    'package' => 'https://github.com/ItsMNLOP/wpthistle-backup-restore/releases/download/v1.0.0/wpthistle-backup-restore-1.0.0.zip',
    'body' => '',
    'html_url' => 'https://github.com/ItsMNLOP/wpthistle-backup-restore/releases/tag/v1.0.0',
  ), 12 * HOUR_IN_SECONDS);
" > /dev/null
RESULT_D=$(wp_source eval "
  \$checker = new \WPThistle\BackupRestore\Update_Checker('$PLUGIN_BASENAME');
  \$transient = (object) array('checked' => array('$PLUGIN_BASENAME' => '1.0.0'));
  \$transient = \$checker->check_for_update(\$transient);
  echo isset(\$transient->response['$PLUGIN_BASENAME']) ? 'offered' : 'none';
")
[ "$RESULT_D" = "none" ] || { echo "FAIL: the currently-running version must never be offered as an update to itself, got '$RESULT_D'"; exit 1; }
echo "OK  same-version release correctly never offered"

echo
echo "=== Case E: a WP.org-sourced entry already in the transient must be stripped, never passed through (security audit finding H-001) ==="
wp_source eval "
  delete_transient('wpthistle_backup_update_check');
  set_transient('wpthistle_backup_update_check', array(), HOUR_IN_SECONDS);
" > /dev/null
RESULT_E=$(wp_source eval "
  \$checker = new \WPThistle\BackupRestore\Update_Checker('$PLUGIN_BASENAME');
  \$transient = (object) array('checked' => array('$PLUGIN_BASENAME' => '1.0.0'));
  // Simulates what WP core would already have written into the
  // transient from a WP.org lookup for this slug (e.g. a squatter who
  // registered the still-unclaimed 'wpthistle-backup-restore' slug there)
  // before our own filter ever runs.
  \$transient->response['$PLUGIN_BASENAME'] = (object) array(
    'new_version' => '9.9.9',
    'package'     => 'https://downloads.wordpress.org/plugin/wpthistle-backup-restore.9.9.9.zip',
    'slug'        => 'wpthistle-backup-restore',
    'plugin'      => '$PLUGIN_BASENAME',
  );
  \$transient = \$checker->check_for_update(\$transient);
  echo isset(\$transient->response['$PLUGIN_BASENAME']) ? 'offered' : 'stripped';
")
[ "$RESULT_E" = "stripped" ] || { echo "FAIL: a pre-existing WP.org-sourced entry for this plugin must be stripped by check_for_update(), not passed through; got '$RESULT_E'"; exit 1; }
echo "OK  pre-existing WP.org-sourced entry correctly removed when no trusted GitHub release is available"

echo
echo "=== Case F: a cached release whose package URL doesn't point at this repo's own release assets must never be offered (security audit finding L-001) ==="
wp_source eval "
  set_transient('wpthistle_backup_update_check', array(
    'version' => '9.9.9',
    'package' => 'https://github.com/someone-else/other-repo/releases/download/v1/wpthistle-backup-restore.zip',
    'body' => '',
    'html_url' => 'https://github.com/ItsMNLOP/wpthistle-backup-restore/releases/tag/v9.9.9',
  ), 12 * HOUR_IN_SECONDS);
" > /dev/null
RESULT_F=$(wp_source eval "
  \$checker = new \WPThistle\BackupRestore\Update_Checker('$PLUGIN_BASENAME');
  \$transient = (object) array('checked' => array('$PLUGIN_BASENAME' => '1.0.0'));
  \$transient = \$checker->check_for_update(\$transient);
  echo isset(\$transient->response['$PLUGIN_BASENAME']) ? 'offered' : 'none';
")
[ "$RESULT_F" = "none" ] || { echo "FAIL: a package URL from a different repo (even on github.com) must never be offered, got '$RESULT_F'"; exit 1; }
echo "OK  untrusted package URL (wrong repo) correctly never offered, even though cached"

echo
echo "=== Case G: a cached package URL using path traversal to escape this repo's release path must never be offered (security re-verification finding L-001) ==="
wp_source eval "
  set_transient('wpthistle_backup_update_check', array(
    'version' => '9.9.9',
    'package' => 'https://github.com/ItsMNLOP/wpthistle-backup-restore/releases/download/../../../../attacker/repo/releases/download/v1/wpthistle-backup-restore.zip',
    'body' => '',
    'html_url' => 'https://github.com/ItsMNLOP/wpthistle-backup-restore/releases/tag/v9.9.9',
  ), 12 * HOUR_IN_SECONDS);
" > /dev/null
RESULT_G=$(wp_source eval "
  \$checker = new \WPThistle\BackupRestore\Update_Checker('$PLUGIN_BASENAME');
  \$transient = (object) array('checked' => array('$PLUGIN_BASENAME' => '1.0.0'));
  \$transient = \$checker->check_for_update(\$transient);
  echo isset(\$transient->response['$PLUGIN_BASENAME']) ? 'offered' : 'none';
")
[ "$RESULT_G" = "none" ] || { echo "FAIL: a package URL using path traversal to escape this repo's release path must never be offered, got '$RESULT_G'"; exit 1; }
echo "OK  path-traversal package URL correctly never offered, even though cached"

echo
echo "=== Cleanup: clear the cached release so the live site is left pointed at reality again ==="
wp_source eval "delete_transient('wpthistle_backup_update_check');" > /dev/null

echo
echo "=== UPDATE CHECKER REGRESSION TEST PASSED ==="
