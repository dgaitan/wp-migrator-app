#!/usr/bin/env bash
#
# End-to-end test for `wp migrate_app`.
#
# Stands up a throwaway MySQL container and a scratch WordPress install, runs a
# real migration of a real package against them, asserts the result, and tears
# everything down. Touches nothing you own.
#
# The destination prefix is deliberately `dst_` while Duplicator packages ship
# `wp_`, so every run exercises the prefix rewrite rather than the easy path.
#
#   ./tests/e2e.sh /path/to/extracted/duplicator/package
#
# Requires: docker, wp-cli, php. Leaves nothing behind on success or failure.

set -euo pipefail

PKG="${1:-}"
if [[ -z "$PKG" || ! -d "$PKG" ]]; then
	echo "usage: $0 /path/to/extracted/duplicator/package" >&2
	exit 2
fi
PKG="$(cd "$PKG" && pwd)"

REPO="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

# Run WP-CLI through php with a raised memory limit. WP-CLI ships as a phar with
# a `#!/usr/bin/env php` shebang, so WP_CLI_PHP_ARGS is ignored and the default
# 128M is what you get — which the core-download extractor exhausts. The same
# limit is the usual cause of a mid-migration OOM on a large real site.
WP_BIN="$(command -v wp)"
wp() { php -d memory_limit=512M "$WP_BIN" "$@"; }

CONTAINER="migrateapp-e2e-$$"
PORT=13399
WORKDIR="$(mktemp -d)"
FAILED=0

cleanup() {
	echo
	echo "--- teardown ---"
	docker rm -f "$CONTAINER" >/dev/null 2>&1 || true
	rm -rf "$WORKDIR"
	echo "removed container $CONTAINER and $WORKDIR"
}
trap cleanup EXIT

assert() {
	local label="$1" actual="$2" expected="$3"
	if [[ "$actual" == "$expected" ]]; then
		printf '  \033[32mPASS\033[0m %-46s %s\n' "$label" "$actual"
	else
		printf '  \033[31mFAIL\033[0m %-46s got=%s want=%s\n' "$label" "$actual" "$expected"
		FAILED=1
	fi
}

echo "--- starting MySQL ---"
docker run -d --name "$CONTAINER" \
	-e MYSQL_ROOT_PASSWORD=testpw -e MYSQL_DATABASE=wptest \
	-p "${PORT}:3306" mysql:8 >/dev/null

for _ in $(seq 1 45); do
	if docker exec "$CONTAINER" mysqladmin ping -uroot -ptestpw --silent >/dev/null 2>&1; then
		break
	fi
	sleep 2
done
docker exec "$CONTAINER" mysqladmin ping -uroot -ptestpw --silent >/dev/null 2>&1 \
	|| { echo "MySQL never became ready" >&2; exit 1; }

echo "--- installing destination WordPress (prefix dst_) ---"
cd "$WORKDIR"
wp core download --quiet
wp config create --dbname=wptest --dbuser=root --dbpass=testpw \
	--dbhost="127.0.0.1:${PORT}" --dbprefix=dst_ --skip-check --quiet
wp core install --url=https://new-site.test --title="Destination" \
	--admin_user=destadmin --admin_email=d@example.com --admin_password=x \
	--skip-email --quiet

# Destination-only content that the additive merge must not remove.
DEST_THEME="$(ls wp-content/themes | grep -v '^index.php$' | head -1)"

echo "--- staging the package as my_site_to_migrated ---"
mkdir -p my_site_to_migrated
cp -R "$PKG"/dup-installer my_site_to_migrated/ 2>/dev/null || true
cp -R "$PKG"/wp-content my_site_to_migrated/ 2>/dev/null || true

WP() { wp --require="${REPO}/migrate-app.php" "$@"; }

echo "--- generate-config ---"
WP migrate_app my_site_to_migrated --generate-config >/dev/null
assert "migration.yaml written" \
	"$([[ -f my_site_to_migrated/migration.yaml ]] && echo yes || echo no)" "yes"

echo "--- dry run must write nothing ---"
WP migrate_app my_site_to_migrated --dry-run >/dev/null
assert "dry run created no backup" "$(ls migrate-app-backup-*.sql 2>/dev/null | wc -l | tr -d ' ')" "0"
assert "dry run left dst_ tables alone" \
	"$(wp db query 'SHOW TABLES;' --skip-column-names | grep -c '^dst_')" "12"

echo "--- migrate ---"
WP migrate_app my_site_to_migrated --yes >/dev/null

echo
echo "--- assertions ---"
assert "no wp_ tables leaked" \
	"$(wp db query 'SHOW TABLES;' --skip-column-names | grep -c '^wp_' || true)" "0"
assert "siteurl rewritten" "$(wp option get siteurl --skip-plugins --skip-themes)" "https://new-site.test"
assert "destination-only theme survived" \
	"$([[ -d "wp-content/themes/${DEST_THEME}" ]] && echo yes || echo no)" "yes"
assert "prefixed capabilities meta exists" \
	"$(wp db query "SELECT COUNT(DISTINCT meta_key) FROM dst_usermeta WHERE meta_key='dst_capabilities';" --skip-column-names)" "1"
assert "an administrator exists" \
	"$(wp db query "SELECT COUNT(*) FROM dst_usermeta WHERE meta_key='dst_capabilities' AND meta_value LIKE '%administrator%';" --skip-column-names)" "1"

# The load-bearing check: three replacement passes must not break PHP serialization.
CORRUPT="$(wp eval '
global $wpdb;
$bad = 0;
foreach ( $wpdb->get_results( "SELECT option_value FROM {$wpdb->options}" ) as $r ) {
	if ( ! preg_match( "/^[aOs]:\d+:/", $r->option_value ) ) { continue; }
	if ( @unserialize( $r->option_value ) === false && $r->option_value !== "b:0;" ) { $bad++; }
}
echo $bad;
' --skip-plugins --skip-themes)"
assert "serialized options corrupted" "$CORRUPT" "0"

echo
if [[ "$FAILED" -eq 0 ]]; then
	printf '\033[32mE2E GREEN\033[0m\n'
else
	printf '\033[31mE2E FAILED\033[0m\n'
fi
exit "$FAILED"
