#!/usr/bin/env bash
#
# End-to-end test for `wp migrate_app_pull`, and for the claim that the whole
# tool reduces to two steps.
#
#   step 1   wp migrate_app_pull  ./folder --from=A
#   step 2   wp migrate_app_remote ./folder --to=B
#
# Those two lines together ARE server-to-server migration, so this rig stands up
# two separate WordPress installs and proves a site moves from one to the other
# with no third command and no hand-editing in between.
#
# Origin A and destination B are deliberately unalike — different URLs, different
# table prefixes, different themes — because a migration that only works between
# identical installs has not been tested.
#
#   ./tests/e2e-pull.sh
#
# Requires: docker, wp-cli, php, ssh-keygen, curl. Leaves nothing behind on
# success or failure, including the throwaway host keys it must add to your real
# ~/.ssh/known_hosts (WP-CLI's own --ssh reads the default file and takes no -F).

set -euo pipefail

REPO="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

WP_BIN="$(command -v wp)"
wp() { php -d memory_limit=512M "$WP_BIN" "$@"; }

# Keep any copy installed with `wp package install` out of this run — two copies
# of these classes in one process is a redeclaration fatal.
PKGDIR="$(mktemp -d)"
export WP_CLI_PACKAGES_DIR="$PKGDIR"

SUFFIX="$$"
DB="mgapp-pull-db-${SUFFIX}"
ORIGIN="mgapp-pull-origin-${SUFFIX}"
DEST="mgapp-pull-dest-${SUFFIX}"
NET="mgapp-pull-net-${SUFFIX}"
WORKDIR="$(mktemp -d)"
PORT_A=12822
PORT_B=12823
FAILED=0

REMOTE_WP='WP_CLI_ALLOW_ROOT=1 php -d memory_limit=512M /usr/local/bin/wp'
ROOT=/var/www/html

cleanup() {
	echo
	echo "--- teardown ---"
	docker rm -f "$ORIGIN" "$DEST" "$DB" >/dev/null 2>&1 || true
	docker network rm "$NET" >/dev/null 2>&1 || true
	ssh-keygen -R "[127.0.0.1]:${PORT_A}" >/dev/null 2>&1 || true
	ssh-keygen -R "[127.0.0.1]:${PORT_B}" >/dev/null 2>&1 || true
	rm -rf "$WORKDIR" "$PKGDIR"
	echo "removed containers, network, $WORKDIR"
}
trap cleanup EXIT

assert() {
	local label="$1" actual="$2" expected="$3"
	if [[ "$actual" == "$expected" ]]; then
		printf '  \033[32mPASS\033[0m %-52s %s\n' "$label" "$actual"
	else
		printf '  \033[31mFAIL\033[0m %-52s got=%s want=%s\n' "$label" "$actual" "$expected"
		FAILED=1
	fi
}

assert_contains() {
	local label="$1" haystack="$2" needle="$3"
	if [[ "$haystack" == *"$needle"* ]]; then
		printf '  \033[32mPASS\033[0m %-52s %s\n' "$label" "matched"
	else
		printf '  \033[31mFAIL\033[0m %-52s wanted: %s\n' "$label" "$needle"
		FAILED=1
	fi
}

assert_absent() {
	local label="$1" path="$2"
	if [[ ! -e "$path" ]]; then
		printf '  \033[32mPASS\033[0m %-52s %s\n' "$label" "absent"
	else
		printf '  \033[31mFAIL\033[0m %-52s present: %s\n' "$label" "$path"
		FAILED=1
	fi
}

awp() { docker exec "$ORIGIN" sh -c "cd ${ROOT} && ${REMOTE_WP} $(printf '%q ' "$@")"; }
bwp() { docker exec "$DEST"   sh -c "cd ${ROOT} && ${REMOTE_WP} $(printf '%q ' "$@")"; }

# ---------------------------------------------------------------------------
# Infrastructure
# ---------------------------------------------------------------------------

echo "--- network + MariaDB (two databases, one server) ---"
docker network create "$NET" >/dev/null
docker run -d --name "$DB" --network "$NET" \
	-e MARIADB_ROOT_PASSWORD=testpw -e MARIADB_DATABASE=origindb mariadb:11 >/dev/null

db_ready() {
	docker exec "$DB" sh -c \
		'mariadb-admin ping -uroot -ptestpw --silent 2>/dev/null || mysqladmin ping -uroot -ptestpw --silent 2>/dev/null' \
		>/dev/null 2>&1
}
for _ in $(seq 1 45); do db_ready && break; sleep 2; done
db_ready || { echo "the database never became ready" >&2; exit 1; }

docker exec "$DB" sh -c 'mariadb -uroot -ptestpw -e "CREATE DATABASE destdb;" 2>/dev/null || mysql -uroot -ptestpw -e "CREATE DATABASE destdb;"'

boot_site() {
	local name="$1" port="$2"
	docker run -d --name "$name" --network "$NET" --user 0:0 \
		-p "${port}:22" --entrypoint sh wordpress:cli -c 'tail -f /dev/null' >/dev/null
	docker exec "$name" sh -c 'command -v mysql >/dev/null 2>&1 || apk add --no-cache mysql-client >/dev/null 2>&1 || apk add --no-cache mariadb-client >/dev/null 2>&1' || true
	docker exec "$name" sh -c 'apk add --no-cache openssh rsync >/dev/null 2>&1 && ssh-keygen -A >/dev/null 2>&1'
	docker exec -i "$name" sh -c 'mkdir -p /root/.ssh && chmod 700 /root/.ssh && cat > /root/.ssh/authorized_keys && chmod 600 /root/.ssh/authorized_keys' < "${WORKDIR}/id.pub"
	docker exec "$name" sh -c 'printf "PermitRootLogin prohibit-password\nPasswordAuthentication no\n" >> /etc/ssh/sshd_config; /usr/sbin/sshd'
	docker exec "$name" sh -c "mkdir -p ${ROOT}"
}

ssh-keygen -q -t ed25519 -N '' -f "${WORKDIR}/id" >/dev/null

echo "--- origin A and destination B ---"
boot_site "$ORIGIN" "$PORT_A"
boot_site "$DEST" "$PORT_B"

for port in "$PORT_A" "$PORT_B"; do
	scanned=""
	for _ in $(seq 1 20); do
		scanned="$(ssh-keyscan -p "$port" 127.0.0.1 2>/dev/null)"
		[[ -n "$scanned" ]] && break
		sleep 1
	done
	[[ -n "$scanned" ]] || { echo "sshd on port $port never came up" >&2; exit 1; }
	printf '%s\n' "$scanned" >> "${WORKDIR}/known_hosts"
done

# Ours reads MIGRATE_APP_SSH_OPTS; WP-CLI's own --ssh can only read the default
# file, so the handoff leg needs the real one. Teardown removes both entries.
export MIGRATE_APP_SSH_OPTS="-o UserKnownHostsFile=${WORKDIR}/known_hosts"
mkdir -p "${HOME}/.ssh" && chmod 700 "${HOME}/.ssh"
cat "${WORKDIR}/known_hosts" >> "${HOME}/.ssh/known_hosts"

echo "--- installing WordPress on both, deliberately dissimilar ---"
awp core download --quiet
awp config create --dbname=origindb --dbuser=root --dbpass=testpw \
	--dbhost="$DB" --dbprefix=wp_ --skip-check --quiet
awp core install --url=https://old-site.test --title="Origin Site" \
	--admin_user=originadmin --admin_email=o@example.com --admin_password=x \
	--skip-email --quiet

bwp core download --quiet
bwp config create --dbname=destdb --dbuser=root --dbpass=testpw \
	--dbhost="$DB" --dbprefix=dst_ --skip-check --quiet
bwp core install --url=https://new-site.test --title="Destination Site" \
	--admin_user=destadmin --admin_email=d@example.com --admin_password=x \
	--skip-email --quiet

echo "--- giving the origin content worth migrating ---"
# A theme that exists nowhere else, so its arrival at B proves the file leg.
docker exec "$ORIGIN" sh -c "mkdir -p ${ROOT}/wp-content/themes/origin-theme && \
	printf '/*\nTheme Name: Origin Theme\n*/\n' > ${ROOT}/wp-content/themes/origin-theme/style.css && \
	printf '<?php // origin theme index\n' > ${ROOT}/wp-content/themes/origin-theme/index.php"
awp theme activate origin-theme

docker exec "$ORIGIN" sh -c "mkdir -p ${ROOT}/wp-content/plugins/origin-plugin && \
	printf '<?php\n/*\nPlugin Name: Origin Plugin\n*/\n' > ${ROOT}/wp-content/plugins/origin-plugin/origin-plugin.php"

docker exec "$ORIGIN" sh -c "mkdir -p ${ROOT}/wp-content/uploads/2026/08 && \
	echo 'origin-upload-payload' > ${ROOT}/wp-content/uploads/2026/08/origin-file.txt"

# A post whose content carries the origin URL, so the rewrite is observable.
awp post create --post_title="Origin Post" --post_status=publish \
	--post_content='<a href="https://old-site.test/somewhere">link home</a>'

# The noise a real site accumulates and a pull must refuse to carry: a backup
# plugin's archive directory, holding what looks like another full dump.
docker exec "$ORIGIN" sh -c "mkdir -p ${ROOT}/wp-content/uploads/ai1wm-backups && \
	head -c 200000 /dev/zero | tr '\\0' 'x' > ${ROOT}/wp-content/uploads/ai1wm-backups/huge.wpress"

FROM_A="root@127.0.0.1:${PORT_A}${ROOT}"
TO_B="root@127.0.0.1:${PORT_B}${ROOT}"

P() { wp --require="${REPO}/migrate-app.php" migrate_app_pull "$@" --identity="${WORKDIR}/id"; }
R() { wp --require="${REPO}/migrate-app.php" migrate_app_remote "$@" --identity="${WORKDIR}/id"; }

cd "$WORKDIR"

# ---------------------------------------------------------------------------
# Step 1 — pull
# ---------------------------------------------------------------------------

echo
echo "=== STEP 1: pull the origin into a folder ==="

echo "--- a dry run measures without moving anything ---"
DRY="$(P ./pulled --from="$FROM_A" --wp-binary="$REMOTE_WP" --dry-run 2>&1 || true)"
printf '%s
' "$DRY" | sed "s/^/    | /"
assert_contains "dry run reports the origin home URL" "$DRY" "https://old-site.test"
assert_contains "dry run names the theme it found" "$DRY" "origin-theme"
assert_contains "dry run says the origin is read-only" "$DRY" "read-only"
assert "dry run created no package" "$( [[ -f ./pulled/migration.yaml ]] && echo yes || echo no )" "no"

echo "--- the pull ---"
P ./pulled --from="$FROM_A" --wp-binary="$REMOTE_WP" --yes

echo "--- the folder is a package, with no hand-editing ---"
assert "migration.yaml written" "$( [[ -f ./pulled/migration.yaml ]] && echo yes || echo no )" "yes"
assert "themes came across" "$( [[ -d ./pulled/wp-content/themes/origin-theme ]] && echo yes || echo no )" "yes"
assert "plugins came across" "$( [[ -d ./pulled/wp-content/plugins/origin-plugin ]] && echo yes || echo no )" "yes"
assert "uploads came across" "$( cat ./pulled/wp-content/uploads/2026/08/origin-file.txt 2>/dev/null || echo missing )" "origin-upload-payload"

SQL_COUNT="$(find ./pulled -maxdepth 1 -name '*.sql' | wc -l | tr -d ' ')"
assert "exactly one database dump" "$SQL_COUNT" "1"

DUMP="$(find ./pulled -maxdepth 1 -name '*.sql' | head -1)"
assert "the dump is a real dump" "$(grep -c 'CREATE TABLE' "$DUMP" | tr -d ' ' | awk '{print ($1>0)?"yes":"no"}')" "yes"
assert "the dump reached its end" "$(tail -c 4096 "$DUMP" | grep -c 'Dump completed' | tr -d ' ' | awk '{print ($1>0)?"yes":"no"}')" "yes"

echo "--- the manifest says what it should ---"
assert "origin_url is the origin's real home" "$(grep '^origin_url:' ./pulled/migration.yaml | sed 's/^origin_url: *//')" "https://old-site.test"
assert "target_url is deliberately empty" "$(grep -c '^target_url:$' ./pulled/migration.yaml | tr -d ' ')" "1"
assert "table_prefix is the ORIGIN's prefix" "$(grep '^table_prefix:' ./pulled/migration.yaml | sed 's/^table_prefix: *//')" "wp_"
assert "theme_path points at the active theme" "$(grep '^theme_path:' ./pulled/migration.yaml | sed 's/^theme_path: *//')" "wp-content/themes/origin-theme"

echo "--- what a pull must refuse to bring home ---"
assert_absent "no wp-config.php in the package" "./pulled/wp-config.php"
assert "no wp-config.php anywhere in the tree" "$(find ./pulled -name 'wp-config.php' | wc -l | tr -d ' ')" "0"
assert "backup-plugin archive was excluded" "$(find ./pulled -name '*.wpress' | wc -l | tr -d ' ')" "0"
assert "the ai1wm-backups directory was excluded" "$(find ./pulled -type d -name 'ai1wm-backups' | wc -l | tr -d ' ')" "0"

echo "--- the capture window is recorded, not hidden ---"
assert "manifest records when files were captured" "$(grep -c '^# Files captured:' ./pulled/migration.yaml | tr -d ' ')" "1"
assert "manifest records when the database was captured" "$(grep -c '^# Database captured:' ./pulled/migration.yaml | tr -d ' ')" "1"
assert "manifest warns that later writes are excluded" "$(grep -c 'are NOT in this package' ./pulled/migration.yaml | tr -d ' ')" "1"

echo "--- production data is not left world-readable ---"
assert "the package folder is 0700" "$(stat -f '%Lp' ./pulled 2>/dev/null || stat -c '%a' ./pulled)" "700"
assert "the dump is 0600" "$(stat -f '%Lp' "$DUMP" 2>/dev/null || stat -c '%a' "$DUMP")" "600"

echo "--- the origin is left as it was found ---"
LEFTOVER="$(docker exec "$ORIGIN" sh -c 'ls /tmp/migrate-app-pull-*.sql 2>/dev/null | wc -l' | tr -d ' \r')"
assert "temp dump removed from the origin" "$LEFTOVER" "0"
assert "origin still serves its own home URL" "$(awp option get home | tr -d '\r')" "https://old-site.test"
assert "origin post count unchanged" "$(awp post list --post_type=post --format=count | tr -d '\r')" "2"

echo "--- a second pull resumes rather than restarting ---"
REPULL="$(P ./pulled --from="$FROM_A" --wp-binary="$REMOTE_WP" --yes --force 2>&1 || true)"
RESENT="$(printf '%s\n' "$REPULL" | grep -i 'Total transferred file size' | head -1 | grep -oE '[0-9,]+' | head -1 | tr -d ',')"
if [[ -n "${RESENT:-}" ]]; then
	assert "re-pull transferred almost nothing" "$( [[ "$RESENT" -lt 200000 ]] && echo yes || echo no )" "yes"
else
	printf '  \033[33mSKIP\033[0m %-52s %s\n' "re-pull resume" "no rsync stats line"
fi

echo "--- a pulled package cannot be committed by accident ---"
if git -C "$REPO" rev-parse --git-dir >/dev/null 2>&1; then
	mkdir -p "${REPO}/tests/tmp-pullcheck/wp-content"
	printf 'origin_url: x\n' > "${REPO}/tests/tmp-pullcheck/migration.yaml"
	IGNORED="$(git -C "$REPO" check-ignore -q tests/tmp-pullcheck/migration.yaml && echo yes || echo no)"
	rm -rf "${REPO}/tests/tmp-pullcheck"
	assert "a package migration.yaml is git-ignored" "$IGNORED" "yes"
else
	printf '  \033[33mSKIP\033[0m %-52s %s\n' "gitignore check" "not a git repo"
fi

# ---------------------------------------------------------------------------
# Step 2 — install, into a different server
# ---------------------------------------------------------------------------

echo
echo "=== STEP 2: install that same folder into destination B ==="

echo "--- the confirmation resolves the blank target_url to B's own home ---"
DRY2="$(R ./pulled --to="$TO_B" --wp-binary="$REMOTE_WP" --dry-run 2>&1 || true)"
assert_contains "install dry-run sees B's home URL" "$DRY2" "https://new-site.test"
assert_contains "install dry-run shows the planned rewrite" "$DRY2" "URLs"

echo "--- the migration ---"
R ./pulled --to="$TO_B" --wp-binary="$REMOTE_WP" --yes --cleanup

echo "--- B is now the origin site, at B's own address ---"
assert "B kept its own home URL" "$(bwp option get home | tr -d '\r')" "https://new-site.test"
assert "B has the origin's site title" "$(bwp option get blogname | tr -d '\r')" "Origin Site"
assert "B is running the origin's theme" "$(bwp option get stylesheet | tr -d '\r')" "origin-theme"
assert "the origin's plugin arrived" "$( [[ -n "$(docker exec "$DEST" sh -c "ls ${ROOT}/wp-content/plugins/origin-plugin 2>/dev/null")" ]] && echo yes || echo no )" "yes"
assert "the origin's upload arrived" "$(docker exec "$DEST" sh -c "cat ${ROOT}/wp-content/uploads/2026/08/origin-file.txt 2>/dev/null" | tr -d '\r')" "origin-upload-payload"

echo "--- URLs were rewritten to B, not left pointing at A ---"
POST_CONTENT="$(bwp post list --post_type=post --field=content --format=csv 2>/dev/null | tr -d '\r' || true)"
assert_contains "post body now points at the new domain" "$POST_CONTENT" "https://new-site.test"
assert "no old-site.test left in the post body" "$(printf '%s' "$POST_CONTENT" | grep -c 'old-site.test' | tr -d ' ')" "0"

echo "--- users kept their roles (the prefix-rewrite regression) ---"
assert "the origin's administrator survived" "$(bwp user list --role=administrator --format=count | tr -d '\r')" "1"
assert "the roles option carries B's prefix" "$(bwp option get dst_user_roles --format=json 2>/dev/null | grep -c administrator | tr -d ' ')" "1"

echo "--- B's tables carry B's prefix, not A's ---"
assert "destination prefix preserved" "$(bwp db prefix | tr -d '\r')" "dst_"

echo "--- serialized data survived the rewrite ---"
CORRUPT="$(bwp db query "SELECT COUNT(*) FROM dst_options WHERE option_value LIKE 'a:%' AND option_value LIKE '%old-site.test%'" --skip-column-names 2>/dev/null | tr -d ' \r' || echo 0)"
assert "no serialized option still holds the old domain" "$CORRUPT" "0"

# ---------------------------------------------------------------------------
# The docker: transport, so pull_dir's other backend is not untested
# ---------------------------------------------------------------------------

echo
echo "=== the docker: transport for pull ==="
P ./pulled-docker --from="docker:${ORIGIN}${ROOT}" --wp-binary="$REMOTE_WP" --yes --skip-db
assert "docker pull produced a manifest" "$( [[ -f ./pulled-docker/migration.yaml ]] && echo yes || echo no )" "yes"
assert "docker pull brought the theme" "$( [[ -d ./pulled-docker/wp-content/themes/origin-theme ]] && echo yes || echo no )" "yes"
assert "skip-db left the database key empty" "$(grep '^database:' ./pulled-docker/migration.yaml | sed 's/^database: *//' | wc -c | tr -d ' ')" "1"

# ---------------------------------------------------------------------------
# Refusals
# ---------------------------------------------------------------------------

echo
echo "=== refusals ==="

REFUSE="$(P ./pulled --from="$FROM_A" --wp-binary="$REMOTE_WP" --yes 2>&1 || true)"
assert_contains "a second pull into an occupied folder is refused" "$REFUSE" "already holds a package"

# WP-CLI validates the synopsis before the command body runs, so these two are
# refused by the declared signature rather than by our own guards. Assert on the
# behaviour the operator actually sees.
NOFROM="$(P ./nowhere 2>&1 || true)"
assert_contains "pulling with no --from is refused" "$NOFROM" "missing --from parameter"

NOFOLDER="$(P --from="$FROM_A" 2>&1 || true)"
assert_contains "pulling with no folder prints the usage" "$NOFOLDER" "usage: wp migrate_app_pull"

BADHOST="$(P ./bad --from="root@127.0.0.1:1${ROOT}" 2>&1 || true)"
assert_contains "an unreachable origin says so plainly" "$BADHOST" "This is a connection problem"
assert "a failed pull leaves no empty folder behind" "$( [[ -d ./bad ]] && echo yes || echo no )" "no"

echo
if [[ "$FAILED" -eq 0 ]]; then
	printf '\033[32mPULL E2E GREEN\033[0m — pull, then install into another server, with no third command\n'
else
	printf '\033[31mPULL E2E FAILED\033[0m\n'
fi
exit "$FAILED"
