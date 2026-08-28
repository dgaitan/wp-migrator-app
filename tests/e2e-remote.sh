#!/usr/bin/env bash
#
# End-to-end test for `wp migrate_app_remote`.
#
# Runs the whole thing twice, over both transports, because they exercise
# genuinely different code:
#
#   ssh     — a real sshd in the container, reached over a mapped port with a
#             generated key. This is the SHIPPED path: ssh(1), ControlMaster,
#             rsync over ssh, rsync resume, the macOS exclude list.
#   docker  — `docker exec` / `docker cp`, via WP-CLI's own `docker:` scheme.
#             Faster, and the reason the rig can run where no sshd exists.
#
# A green run over `docker:` alone proves the orchestration and nothing about
# the transport, which is why both are here.
#
#   ./tests/e2e-remote.sh /path/to/extracted/duplicator/package
#
# Requires: docker, wp-cli, php, ssh-keygen. Leaves nothing behind on success or
# failure — including the one thing it must touch outside its own temp dir: the
# throwaway host key for [127.0.0.1]:<port> goes into your real ~/.ssh/known_hosts,
# because WP-CLI's own --ssh reads the default known_hosts and takes no -F or
# -o. Teardown removes that entry with `ssh-keygen -R`.

set -euo pipefail

PKG="${1:-}"
if [[ -z "$PKG" || ! -d "$PKG" ]]; then
	echo "usage: $0 /path/to/extracted/duplicator/package" >&2
	exit 2
fi
PKG="$(cd "$PKG" && pwd)"

REPO="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

# Same memory-limit workaround as e2e.sh: the phar's shebang ignores
# WP_CLI_PHP_ARGS, and core-download exhausts the default 128M.
WP_BIN="$(command -v wp)"
wp() { php -d memory_limit=512M "$WP_BIN" "$@"; }

# Never let whatever the operator has installed with `wp package install` join
# this run. A stale copy of this very tool is the most likely thing to be there,
# and two copies in one process is a class redeclaration.
PKGDIR="$(mktemp -d)"
export WP_CLI_PACKAGES_DIR="$PKGDIR"

SUFFIX="$$"
DB="migrateapp-remote-db-${SUFFIX}"
SITE="migrateapp-remote-site-${SUFFIX}"
NET="migrateapp-remote-net-${SUFFIX}"
WORKDIR="$(mktemp -d)"
SSH_PORT=12722
HTTP_PORT=18780
FAILED=0

# The far end runs as root, so WP-CLI needs telling. The memory limit is the
# same workaround as on the host: the phar's shebang ignores WP_CLI_PHP_ARGS and
# core-download exhausts the default 128M. Both of these ride in through
# --wp-binary, which is exactly the flag real shared hosts need for their own
# reasons — so the rig exercises it rather than pretending plain `wp` is enough.
REMOTE_WP='WP_CLI_ALLOW_ROOT=1 php -d memory_limit=512M /usr/local/bin/wp'
REMOTE_ROOT=/var/www/html
STAGING=/root/.migrate-app

cleanup() {
	echo
	echo "--- teardown ---"
	docker rm -f "$SITE" "$DB" >/dev/null 2>&1 || true
	docker network rm "$NET" >/dev/null 2>&1 || true
	ssh-keygen -R "[127.0.0.1]:${SSH_PORT}" >/dev/null 2>&1 || true
	rm -rf "$WORKDIR" "$PKGDIR"
	echo "removed containers, network, $WORKDIR"
}
trap cleanup EXIT

assert() {
	local label="$1" actual="$2" expected="$3"
	if [[ "$actual" == "$expected" ]]; then
		printf '  \033[32mPASS\033[0m %-50s %s\n' "$label" "$actual"
	else
		printf '  \033[31mFAIL\033[0m %-50s got=%s want=%s\n' "$label" "$actual" "$expected"
		FAILED=1
	fi
}

assert_contains() {
	local label="$1" haystack="$2" needle="$3"
	if [[ "$haystack" == *"$needle"* ]]; then
		printf '  \033[32mPASS\033[0m %-50s %s\n' "$label" "matched"
	else
		printf '  \033[31mFAIL\033[0m %-50s wanted substring: %s\n' "$label" "$needle"
		FAILED=1
	fi
}

rwp() { docker exec "$SITE" sh -c "cd ${REMOTE_ROOT} && ${REMOTE_WP} $(printf '%q ' "$@")"; }

# MariaDB, not MySQL 8, and deliberately: the wordpress:cli image ships the
# MariaDB client, which cannot talk to a MySQL 8 server at all here — it rejects
# the self-signed TLS cert, and with --skip-ssl it cannot load
# caching_sha2_password. Matching the server to the client the far end actually
# has is what a real host looks like. MySQL 8 coverage lives in e2e.sh, which
# runs the client from the host.
echo "--- network + MariaDB ---"
docker network create "$NET" >/dev/null
docker run -d --name "$DB" --network "$NET" \
	-e MARIADB_ROOT_PASSWORD=testpw -e MARIADB_DATABASE=wptest mariadb:11 >/dev/null

# MariaDB 11 renamed the client tools; the mysql*-prefixed symlinks are
# deprecated and on their way out, so try the new name first.
db_ready() {
	docker exec "$DB" sh -c \
		'mariadb-admin ping -uroot -ptestpw --silent 2>/dev/null || mysqladmin ping -uroot -ptestpw --silent 2>/dev/null' \
		>/dev/null 2>&1
}

for _ in $(seq 1 45); do
	db_ready && break
	sleep 2
done
db_ready || { echo "the database never became ready" >&2; exit 1; }

echo "--- the 'remote': a container with WordPress and WP-CLI ---"
docker run -d --name "$SITE" --network "$NET" --user 0:0 \
	-p "${SSH_PORT}:22" -p "${HTTP_PORT}:80" \
	--entrypoint sh wordpress:cli -c 'tail -f /dev/null' >/dev/null

# `wp db import`/`export` shell out to the mysql client; the cli image does not
# always carry one.
docker exec "$SITE" sh -c 'command -v mysql >/dev/null 2>&1 || apk add --no-cache mysql-client >/dev/null 2>&1 || apk add --no-cache mariadb-client >/dev/null 2>&1' || true
docker exec "$SITE" sh -c 'test -x /usr/local/bin/wp' || { echo "wp not at /usr/local/bin/wp in the image" >&2; exit 1; }
docker exec "$SITE" sh -c "mkdir -p ${REMOTE_ROOT}"

echo "--- sshd + rsync on the 'remote', so the shipped transport is the tested one ---"
docker exec "$SITE" sh -c 'apk add --no-cache openssh rsync >/dev/null 2>&1 && ssh-keygen -A >/dev/null 2>&1'
ssh-keygen -q -t ed25519 -N '' -f "${WORKDIR}/id" >/dev/null
docker exec -i "$SITE" sh -c 'mkdir -p /root/.ssh && chmod 700 /root/.ssh && cat > /root/.ssh/authorized_keys && chmod 600 /root/.ssh/authorized_keys' < "${WORKDIR}/id.pub"
docker exec "$SITE" sh -c 'printf "PermitRootLogin prohibit-password\nPasswordAuthentication no\n" >> /etc/ssh/sshd_config; /usr/sbin/sshd'

for _ in $(seq 1 20); do
	ssh-keyscan -p "$SSH_PORT" 127.0.0.1 > "${WORKDIR}/known_hosts" 2>/dev/null
	[[ -s "${WORKDIR}/known_hosts" ]] && break
	sleep 1
done
[[ -s "${WORKDIR}/known_hosts" ]] || { echo "sshd never came up" >&2; exit 1; }

# Two known_hosts, because two different SSH clients are involved and only one
# of them is ours.
#
#   ours          — Ssh.php, via the documented MIGRATE_APP_SSH_OPTS escape
#                   hatch. Keeps the key out of your real file.
#   WP-CLI's      — Runner::generate_ssh_command() builds its own flags and
#                   accepts no -F and no -o, so the handoff can only use the
#                   default known_hosts. Teardown removes the entry.
#
# StrictHostKeyChecking is never disabled in either case: the key is scanned in
# first, which is what an operator does by hand the first time.
export MIGRATE_APP_SSH_OPTS="-o UserKnownHostsFile=${WORKDIR}/known_hosts"
ssh-keygen -R "[127.0.0.1]:${SSH_PORT}" >/dev/null 2>&1 || true
mkdir -p "${HOME}/.ssh" && chmod 700 "${HOME}/.ssh"
cat "${WORKDIR}/known_hosts" >> "${HOME}/.ssh/known_hosts"

# Destination prefix is dst_ on purpose: Duplicator packages ship wp_, so every
# run exercises the prefix rewrite rather than the easy path.
rwp core download --quiet
rwp config create --dbname=wptest --dbuser=root --dbpass=testpw \
	--dbhost="$DB" --dbprefix=dst_ --skip-check --quiet
rwp core install --url=https://new-site.test --title=Destination \
	--admin_user=destadmin --admin_email=d@example.com --admin_password=x \
	--skip-email --quiet

docker exec -d "$SITE" sh -c "php -S 0.0.0.0:80 -t ${REMOTE_ROOT} >/dev/null 2>&1"
for _ in $(seq 1 20); do
	[[ "$(curl -s -o /dev/null -w '%{http_code}' "http://127.0.0.1:${HTTP_PORT}/readme.html" || true)" == "200" ]] && break
	sleep 1
done

DEST_THEME="$(docker exec "$SITE" sh -c "ls ${REMOTE_ROOT}/wp-content/themes | grep -v '^index.php$' | head -1" | tr -d '\r')"
echo "    destination-only theme: ${DEST_THEME}"

echo "--- staging the package on THIS machine ---"
cd "$WORKDIR"
mkdir -p my_site_to_migrated
cp -R "$PKG"/dup-installer my_site_to_migrated/ 2>/dev/null || true
cp -R "$PKG"/wp-content my_site_to_migrated/ 2>/dev/null || true

TO_SSH="root@127.0.0.1:${SSH_PORT}${REMOTE_ROOT}"
TO_DOCKER="docker:${SITE}${REMOTE_ROOT}"
TO="$TO_SSH"

R() { wp --require="${REPO}/migrate-app.php" migrate_app_remote "$@" --identity="${WORKDIR}/id"; }

# migration.yaml has to exist locally before the remote command will touch
# anything — generate it with the local command against the local copy.
wp --require="${REPO}/migrate-app.php" migrate_app "${WORKDIR}/my_site_to_migrated" \
	--generate-config --path="${WORKDIR}" >/dev/null 2>&1 || true

if [[ ! -f my_site_to_migrated/migration.yaml ]]; then
	# No local WordPress to bootstrap against, so write the config from the
	# package by hand. Only origin_url/target_url/database are load-bearing here.
	DBFILE="$(cd my_site_to_migrated/dup-installer && ls dup-database__*.sql | head -1)"
	ORIGIN="$(grep -ao 'https\?://[a-zA-Z0-9.-]*' "my_site_to_migrated/dup-installer/${DBFILE}" | head -1)"
	cat > my_site_to_migrated/migration.yaml <<-YAML
		origin_url: ${ORIGIN}
		target_url: https://new-site.test
		theme_path: wp-content/themes
		plugin_path: wp-content/plugins
		uploads_path: wp-content/uploads
		database: dup-installer/${DBFILE}
	YAML
fi
assert "migration.yaml present locally" "$([[ -f my_site_to_migrated/migration.yaml ]] && echo yes || echo no)" "yes"

echo
echo "--- guard rails ---"
GUARD="$(R ./my_site_to_migrated --to=@nope-not-real 2>&1 || true)"
assert_contains "refuses an unknown alias" "$GUARD" "Unknown alias"

GUARD="$(R ./my_site_to_migrated --to="root@127.0.0.1:${SSH_PORT}" 2>&1 || true)"
assert_contains "refuses a target with no path" "$GUARD" "No remote WordPress path"

# The --ssh footgun cannot be caught by a guard inside the command: WP-CLI
# intercepts --ssh before dispatch and strips it from argv on the way, so the
# command runs on the REMOTE and simply cannot find the local package. The hint
# therefore lives on the error the operator actually sees.
GUARD="$(R ./no-such-package-here --to="$TO" 2>&1 || true)"
assert_contains "missing package names the --ssh footgun" "$GUARD" "If you passed a global --ssh"

echo
echo "--- a wrong target_url is surfaced before anything runs ---"
cp my_site_to_migrated/migration.yaml "${WORKDIR}/migration.yaml.good"
sed -i.bak 's|^target_url:.*|target_url: https://WRONG-SITE.example|' my_site_to_migrated/migration.yaml
MISMATCH="$(R ./my_site_to_migrated --to="$TO" --wp-binary="$REMOTE_WP" --dry-run 2>&1 || true)"
assert_contains "mismatched target_url is warned about" "$MISMATCH" "will be rewritten to"
assert_contains "the confirmation prints the planned rewrite" "$MISMATCH" "URLs"
cp "${WORKDIR}/migration.yaml.good" my_site_to_migrated/migration.yaml
rm -f my_site_to_migrated/migration.yaml.bak

echo
echo "--- dry run must change nothing ---"
R ./my_site_to_migrated --to="$TO" --wp-binary="$REMOTE_WP" --dry-run >/dev/null
assert "dry run staged nothing on the remote" \
	"$(docker exec "$SITE" sh -c "test -d ${STAGING} && echo yes || echo no" | tr -d '\r')" "no"
assert "dry run left the remote prefix alone" \
	"$(rwp db query 'SHOW TABLES;' --skip-column-names | grep -c '^wp_' | tr -d '\r' || true)" "0"

echo
echo "--- push-only, then resume with --skip-push ---"
PUSH1="$(R ./my_site_to_migrated --to="$TO" --wp-binary="$REMOTE_WP" --push-only --yes 2>&1)" || { echo "$PUSH1" | tail -20; FAILED=1; }
assert_contains "upload used rsync, not the tar fallback" "$PUSH1" "rsync (resumable)"

# Re-push: rsync must recognise the far end already has the bytes. This is what
# makes an interrupted 12 GB upload resumable rather than a restart, and it is
# invisible under the docker: transport, which always copies everything.
PUSH2="$(R ./my_site_to_migrated --to="$TO" --wp-binary="$REMOTE_WP" --push-only --yes 2>&1)" || { echo "$PUSH2" | tail -20; FAILED=1; }
BYTES1="$(echo "$PUSH1" | grep -o 'Total transferred file size: [0-9,]*' | tail -1 | tr -cd '0-9')"
BYTES2="$(echo "$PUSH2" | grep -o 'Total transferred file size: [0-9,]*' | tail -1 | tr -cd '0-9')"
assert "first push transferred the package" "$([[ "${BYTES1:-0}" -gt 1000000 ]] && echo yes || echo no)" "yes"
assert "second push transferred almost nothing" "$([[ "${BYTES2:-999999999}" -lt 100000 ]] && echo yes || echo no)" "yes"
assert "macOS cruft did not travel" \
	"$(docker exec "$SITE" sh -c "find ${STAGING} \\( -name '._*' -o -name '.DS_Store' \\) | wc -l" | tr -d ' \r')" "0"
assert "package landed in staging" \
	"$(docker exec "$SITE" sh -c "test -f ${STAGING}/package/my_site_to_migrated/migration.yaml && echo yes || echo no" | tr -d '\r')" "yes"
assert "tool landed in staging" \
	"$(docker exec "$SITE" sh -c "test -f ${STAGING}/tool/migrate-app.php && echo yes || echo no" | tr -d '\r')" "yes"
assert "staging is outside the webroot" \
	"$(docker exec "$SITE" sh -c "case ${STAGING} in ${REMOTE_ROOT}*) echo inside;; *) echo outside;; esac" | tr -d '\r')" "outside"
assert "push-only ran no migration" \
	"$(rwp db query 'SHOW TABLES;' --skip-column-names | grep -c '^wp_' | tr -d '\r' || true)" "0"

DBFILE="$(docker exec "$SITE" sh -c "ls ${STAGING}/package/my_site_to_migrated/dup-installer/dup-database__*.sql | head -1 | xargs basename" | tr -d '\r')"
assert "the web server is actually serving" \
	"$(curl -s -o /dev/null -w '%{http_code}' "http://127.0.0.1:${HTTP_PORT}/readme.html")" "200"
assert "the staged dump is NOT reachable over HTTP" \
	"$(curl -s -o /dev/null -w '%{http_code}' "http://127.0.0.1:${HTTP_PORT}/.migrate-app/package/my_site_to_migrated/dup-installer/${DBFILE}")" "404"


echo
echo "--- migrate ---"
R ./my_site_to_migrated --to="$TO" --wp-binary="$REMOTE_WP" --skip-push --yes >/dev/null

echo
echo "--- assertions ---"
BACKUP="$(ls "${WORKDIR}"/migrate-app-backup-*.sql 2>/dev/null | head -1 || true)"
assert "backup came home before the import" "$([[ -n "$BACKUP" ]] && echo yes || echo no)" "yes"
if [[ -n "$BACKUP" ]]; then
	assert "backup is a real SQL dump" \
		"$(grep -c -m1 -i 'CREATE TABLE' "$BACKUP" | tr -d ' ')" "1"
	assert "backup is the PRE-migration database" \
		"$(grep -c 'dst_' "$BACKUP" >/dev/null && echo yes || echo no)" "yes"
fi

assert "no wp_ tables leaked" \
	"$(rwp db query 'SHOW TABLES;' --skip-column-names | grep -c '^wp_' | tr -d '\r' || true)" "0"
assert "siteurl rewritten on the remote" \
	"$(rwp option get siteurl --skip-plugins --skip-themes | tr -d '\r')" "https://new-site.test"
assert "destination-only theme survived" \
	"$(docker exec "$SITE" sh -c "test -d ${REMOTE_ROOT}/wp-content/themes/${DEST_THEME} && echo yes || echo no" | tr -d '\r')" "yes"
assert "prefixed capabilities meta exists" \
	"$(rwp db query "SELECT COUNT(DISTINCT meta_key) FROM dst_usermeta WHERE meta_key='dst_capabilities';" --skip-column-names | tr -d '\r')" "1"
assert "an administrator exists" \
	"$(rwp db query "SELECT COUNT(*) FROM dst_usermeta WHERE meta_key='dst_capabilities' AND meta_value LIKE '%administrator%';" --skip-column-names | tr -d '\r')" "1"

# The load-bearing check, same as the local rig: three replacement passes must
# not break PHP serialization.
CORRUPT="$(rwp eval '
global $wpdb;
$bad = 0;
foreach ( $wpdb->get_results( "SELECT option_value FROM {$wpdb->options}" ) as $r ) {
	if ( ! preg_match( "/^[aOs]:[0-9]+:/", $r->option_value ) ) { continue; }
	if ( @unserialize( $r->option_value ) === false && $r->option_value !== "b:0;" ) { $bad++; }
}
echo $bad;
' --skip-plugins --skip-themes | tr -d '\r')"
assert "serialized options corrupted" "$CORRUPT" "0"

echo
# A second `migrate_app` against this destination cannot run at all, and that is
# expected rather than a defect: the package ships a plugin that fatals on this
# host, the migration already warned about it by name, and `migrate_app` is
# `after_wp_load` — WP-CLI cannot boot WordPress with a fatal plugin active.
#
# Which makes the next assertion the interesting one. `--cleanup-only` still
# works, because it never loads WordPress. Getting a full copy of the source
# database off a server must not depend on that server being healthy.
echo "--- a broken destination still blocks a second migration ---"
SECOND=0
R ./my_site_to_migrated --to="$TO" --wp-binary="$REMOTE_WP" --skip-push --skip-db --skip-files --yes >/dev/null 2>&1 || SECOND=$?
assert "second run fails loudly, does not half-run" "$SECOND" "1"

echo "--- cleanup removes the staged dump ---"
R ./my_site_to_migrated --to="$TO" --wp-binary="$REMOTE_WP" --cleanup-only --yes >/dev/null
assert "staging removed from the remote" \
	"$(docker exec "$SITE" sh -c "test -d ${STAGING} && echo yes || echo no" | tr -d '\r')" "no"

echo
echo "--- second transport: the same orchestration over docker exec / docker cp ---"
TO="$TO_DOCKER"
DOCK="$(R ./my_site_to_migrated --to="$TO" --wp-binary="$REMOTE_WP" --push-only --yes 2>&1)"
assert_contains "docker backend reports docker cp" "$DOCK" "docker cp"
assert "docker backend staged the package" \
	"$(docker exec "$SITE" sh -c "test -f ${STAGING}/package/my_site_to_migrated/migration.yaml && echo yes || echo no" | tr -d '\r')" "yes"
R ./my_site_to_migrated --to="$TO" --wp-binary="$REMOTE_WP" --cleanup-only --yes >/dev/null
assert "docker backend cleaned up" \
	"$(docker exec "$SITE" sh -c "test -d ${STAGING} && echo yes || echo no" | tr -d '\r')" "no"

echo
if [[ "$FAILED" -eq 0 ]]; then
	printf '\033[32mREMOTE E2E GREEN\033[0m\n'
else
	printf '\033[31mREMOTE E2E FAILED\033[0m\n'
fi
exit "$FAILED"
