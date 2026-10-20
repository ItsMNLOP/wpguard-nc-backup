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

wp_source() {
  MSYS_NO_PATHCONV=1 docker compose -p wpguard-test --env-file .env run --rm \
    -e WORDPRESS_DB_HOST=db-source -e WORDPRESS_DB_NAME=wp_source \
    -e WORDPRESS_DB_USER=wp_source -e WORDPRESS_DB_PASSWORD="$WP_SOURCE_DB_PASSWORD" \
    wpcli wp --path=/var/www/html-source "$@" 2>&1 | grep -v '^ Container' || true
}

PLUGIN_BASENAME='wpguard-nc-backup/wpguard-nc-backup.php'

echo "=== Case A: real GitHub API call, no release published yet (today's actual state) ==="
RESULT_A=$(wp_source eval "
  delete_transient('wpguard_nc_update_check');
  \$checker = new \WPGuard\NCBackup\Update_Checker('$PLUGIN_BASENAME');
  \$transient = (object) array('checked' => array('$PLUGIN_BASENAME' => '1.0.0'));
  \$transient = \$checker->check_for_update(\$transient);
  echo isset(\$transient->response['$PLUGIN_BASENAME']) ? 'offered' : 'none';
")
[ "$RESULT_A" = "none" ] || { echo "FAIL: no GitHub Release exists for this repo yet -- must not offer an update, got '$RESULT_A'"; exit 1; }
echo "OK  no update offered against the real (release-less) repo, no error thrown"

echo
echo "=== Case B: a newer release WITH a qualifying .zip asset must be offered through the real filter ==="
wp_source eval "
  set_transient('wpguard_nc_update_check', array(
    'version' => '9.9.9',
    'package' => 'https://example.test/wpguard-nc-backup-9.9.9.zip',
    'body' => 'release notes',
    'html_url' => 'https://github.com/ItsMNLOP/wpguard-nc-backup/releases/tag/v9.9.9',
  ), 12 * HOUR_IN_SECONDS);
" > /dev/null
RESULT_B=$(wp_source eval "
  \$checker = new \WPGuard\NCBackup\Update_Checker('$PLUGIN_BASENAME');
  \$transient = (object) array('checked' => array('$PLUGIN_BASENAME' => '1.0.0'));
  \$transient = \$checker->check_for_update(\$transient);
  \$item = \$transient->response['$PLUGIN_BASENAME'] ?? null;
  echo \$item ? (\$item->new_version . '|' . \$item->package) : 'none';
")
[ "$RESULT_B" = "9.9.9|https://example.test/wpguard-nc-backup-9.9.9.zip" ] || { echo "FAIL: expected the cached newer release to be offered with its package url, got '$RESULT_B'"; exit 1; }
echo "OK  newer release with a qualifying asset offered through the real site_transient_update_plugins filter"

echo
echo "=== Case C: a newer release with NO qualifying asset must never be offered (fail-closed) ==="
wp_source eval "
  set_transient('wpguard_nc_update_check', array(
    'version' => '9.9.9',
    'package' => '',
    'body' => 'release notes, but no built zip attached yet',
    'html_url' => 'https://github.com/ItsMNLOP/wpguard-nc-backup/releases/tag/v9.9.9',
  ), 12 * HOUR_IN_SECONDS);
" > /dev/null
RESULT_C=$(wp_source eval "
  \$checker = new \WPGuard\NCBackup\Update_Checker('$PLUGIN_BASENAME');
  \$transient = (object) array('checked' => array('$PLUGIN_BASENAME' => '1.0.0'));
  \$transient = \$checker->check_for_update(\$transient);
  echo isset(\$transient->response['$PLUGIN_BASENAME']) ? 'offered' : 'none';
")
[ "$RESULT_C" = "none" ] || { echo "FAIL: a release with no .zip asset must never be offered -- this is the exact case that would otherwise fall back to the wrong GitHub zipball; got '$RESULT_C'"; exit 1; }
echo "OK  release with no qualifying asset correctly never offered"

echo
echo "=== Case D: a same-or-older cached release must never be offered ==="
wp_source eval "
  set_transient('wpguard_nc_update_check', array(
    'version' => '1.0.0',
    'package' => 'https://example.test/wpguard-nc-backup-1.0.0.zip',
    'body' => '',
    'html_url' => 'https://github.com/ItsMNLOP/wpguard-nc-backup/releases/tag/v1.0.0',
  ), 12 * HOUR_IN_SECONDS);
" > /dev/null
RESULT_D=$(wp_source eval "
  \$checker = new \WPGuard\NCBackup\Update_Checker('$PLUGIN_BASENAME');
  \$transient = (object) array('checked' => array('$PLUGIN_BASENAME' => '1.0.0'));
  \$transient = \$checker->check_for_update(\$transient);
  echo isset(\$transient->response['$PLUGIN_BASENAME']) ? 'offered' : 'none';
")
[ "$RESULT_D" = "none" ] || { echo "FAIL: the currently-running version must never be offered as an update to itself, got '$RESULT_D'"; exit 1; }
echo "OK  same-version release correctly never offered"

echo
echo "=== Cleanup: clear the cached release so the live site is left pointed at reality again ==="
wp_source eval "delete_transient('wpguard_nc_update_check');" > /dev/null

echo
echo "=== UPDATE CHECKER REGRESSION TEST PASSED ==="
