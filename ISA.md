---
project: wp-cli-migrate-app
task: `wp migrate_app` — Duplicator-package migrator, local and over SSH
effort: E3
phase: complete
progress: 142/147
mode: build
started: 2026-08-27
updated: 2026-08-28
---

## Problem

A Duplicator Lite package (SQL dump + `dup-installer/` + a full WP tree) has been uploaded into the
webroot of an **already-working** WordPress instance. Duplicator's own installer cannot complete the
import on the free tier, and there is no SSH/SFTP access to the origin server, so every step must run
from the destination side. Doing it by hand means: pre-dropping tables, reconciling table prefixes,
importing 2.5 MB of SQL, replacing 330 occurrences of the origin URL across PHP-serialized option
values, and merging three content directories without clobbering the destination's other themes and
plugins. Each of those is a distinct way to silently corrupt a live site.

## Vision

One command. `wp migrate_app my_site_to_migrated`. It prints exactly what it is about to do, takes a
backup you did not have to ask for, and lands the site — serialized options intact, media in place,
permalinks working. When the source folder has a `dup-archive__*.txt` in it, the command already knows
the origin URL and the table prefix, so `--generate-config` writes the `migration.yaml` for you and
you only fill in what it could not infer.

## Out of Scope

Multisite / subsite migration. Downloading or unpacking the Duplicator `.zip`/`.daf` archive (the
folder is expected already extracted). Rewriting the
destination `wp-config.php` or its DB credentials. Merging `mu-plugins`, drop-ins, or arbitrary
`wp-content` subdirectories beyond themes/plugins/uploads. Any Duplicator paid-tier feature. Migrating
*into* a WordPress install that does not yet exist.

**Revised 2026-08-27:** "Reaching the origin server for anything" was removed. It encoded a fact about
one migration — no SSH to that particular origin — not a property of the tool. Pull mode (ISC-81..116)
reaches an origin *the operator can already log into*, read-only apart from a temp dump it cleans up.
Writing to an origin remains out of scope: a pull never imports, never rewrites URLs, never touches a
row on the far end.

## Principles

- **Serialized data is structured data.** URL replacement goes through a serialization-aware walker,
  never through `sed` on a SQL file.
- **Destructive by nature, so reversible by construction.** Nothing irreversible happens before a
  restorable database export exists on disk.
- **Announce, then act.** A dry run is the same code path as a real run with the writes suppressed.
- **Merge is additive at the directory level, authoritative at the file level.** The source wins for
  files it ships; the destination keeps everything the source does not mention.
- **Infer, but never assume silently.** Anything auto-detected is printed as detected.

## Constraints

- Runs as a WP-CLI command inside a bootstrapped WordPress instance (`wp migrate_app <folder_name>`).
- PHP 7.4+ / WP-CLI 2.x. No Composer dependency required at runtime — YAML parsing must degrade from
  Symfony YAML → WP-CLI's bundled Spyc → a built-in flat-mapping parser.
- Destination-side execution only for `migrate_app` and `migrate_app_remote`. A pull command may
  read from an origin over SSH, but never writes to it beyond a temp dump it removes.
- The source folder is addressed by name relative to `ABSPATH`, exactly as the user described.
- Sub-operations that need fresh WordPress state (`search-replace`, `rewrite flush`) run as separate
  WP-CLI processes, because the calling process bootstrapped against the pre-import database.

## Goal

Ship a self-contained WP-CLI package at `~/Documents/WordPress/wp-cli-migrate-app/` providing
`wp migrate_app <folder_name>`, which reads `<folder_name>/migration.yaml`, backs up the current
database, imports the package SQL (reconciling table prefixes), performs a serialization-safe
`origin_url` → `target_url` replacement across all tables, merges the source theme/plugin/uploads
directories into the live instance, and reports a per-step result table — with `--dry-run` proving
every step without writing.

## Criteria

### Packaging & registration
- [x] ISC-1: `~/Documents/WordPress/wp-cli-migrate-app/migrate-app.php` exists and is valid PHP (`php -l`)
- [x] ISC-2: `src/MigrateAppCommand.php` exists and is valid PHP (`php -l`)
- [x] ISC-3: `src/Yaml.php` exists and is valid PHP (`php -l`)
- [x] ISC-4: `src/Fs.php` exists and is valid PHP (`php -l`)
- [x] ISC-5: `composer.json` parses as valid JSON and declares the `wp-cli/wp-cli` bootstrap file
- [x] ISC-6: bootstrap calls `WP_CLI::add_command('migrate_app', ...)` — grep returns the line
- [x] ISC-7: bootstrap guards on `!class_exists('WP_CLI')` so a plain `php -l`/require is harmless
- [x] ISC-8: `README.md` documents install via `wp package install` **and** via `--require=`
- [x] ISC-9: `migration.example.yaml` exists containing all six documented keys

### Config contract
- [x] ISC-10: all six keys (`origin_url`, `target_url`, `theme_path`, `plugin_path`, `uploads_path`, `database`) are read by the parser — grep each key name in `src/`
- [x] ISC-11: the YAML loader resolves in order Symfony → Spyc → built-in fallback; grep shows all three branches
- [x] ISC-12: the built-in fallback parses `key: value`, quoted values, `#` comments, and `- ` list items — unit-probe via `php -r`
- [x] ISC-13: relative paths in `migration.yaml` resolve against the source folder, absolute paths pass through — `php -r` probe on the resolver
- [x] ISC-14: `theme_path` and `plugin_path` accept either a single string or a YAML list
- [x] ISC-15: a missing/unreadable `migration.yaml` exits with `WP_CLI::error` naming the expected path

### Auto-detection (Duplicator awareness)
- [x] ISC-16: `--generate-config` writes a `migration.yaml` into the source folder
- [x] ISC-17: generated `origin_url` equals `https://old-site.com` for the reference package
- [x] ISC-18: generated `database` points at the globbed `dup-installer/dup-database__*.sql`
- [x] ISC-19: source table prefix is read from `dup-archive__*.txt` `wp_tableprefix`, falling back to the first `CREATE TABLE` in the dump
- [x] ISC-20: `--generate-config` on the reference folder produces a file whose keys parse back through the loader

### Safety
- [x] ISC-21: a `wp db export` backup is written before the first destructive write, path echoed to stdout
- [x] ISC-22: `--dry-run` performs zero writes — probe by running it and confirming no backup file and no new tables
- [x] ISC-23: an interactive confirmation guards the real run, skippable with `--yes`
- [x] ISC-24: preflight verifies source folder exists, DB is reachable, and `wp-content` is writable, before anything else
- [x] ISC-25: `Anti:` the command never writes to, moves, or deletes the destination `wp-config.php`
- [x] ISC-26: `Anti:` the command never drops tables outside the destination table prefix
- [x] ISC-27: `Anti:` no `sed`/regex URL rewrite is applied to the raw `.sql` file — grep finds no such path
- [x] ISC-28: `Anti:` `wp db reset` is never invoked — grep returns nothing

### Database import
- [x] ISC-29: existing destination tables carrying the target prefix are dropped before import (FK checks off)
- [x] ISC-30: when source prefix ≠ destination prefix the dump is rewritten to a temp file, rewriting backticked identifiers only
- [x] ISC-30.1: the prefix rewrite is anchored to SQL statement keywords (`CREATE TABLE`, `INSERT INTO`, `ALTER TABLE`, `DROP TABLE`, `LOCK TABLES`) — never a free-floating backtick match, which would corrupt post content containing code samples
- [x] ISC-31: prefix rewrite also covers the prefix-bearing rows `{p}user_roles`, `{p}capabilities`, `{p}user_level`
- [x] ISC-32: import is delegated to `wp db import` so multi-MB dumps stream rather than load into memory
- [x] ISC-33: the temp rewritten dump is deleted after import (or on failure)

### URL replacement
- [x] ISC-34: replacement runs via `wp search-replace` with `--all-tables-with-prefix --precise --recurse-objects --skip-columns=guid`
- [x] ISC-35: the sub-command is launched as a separate process (`'launch' => true`) so it bootstraps post-import state
- [x] ISC-36: a second pass replaces the JSON-escaped form (`https:\/\/origin`) which serialization-aware replace does not reach
- [x] ISC-37: a third pass replaces the protocol-relative form (`//origin.tld`)
- [x] ISC-38: `siteurl` and `home` are explicitly re-asserted to `target_url` after replacement

### File merge & finish
- [x] ISC-39: theme/plugin/uploads merge is additive — a destination-only sibling directory survives the merge (probe on a temp fixture)
- [x] ISC-40: the merge uses `rsync -a` when available and a PHP recursive copy otherwise; grep shows both branches
- [x] ISC-41: after a successful run the command flushes rewrite rules and object cache and prints a per-step summary table

### Added at THINK (IterativeDepth, 2-lens sweep)
- [x] ISC-42: `origin_url` and `target_url` are normalized (trimmed, trailing slash stripped) before any replacement
- [x] ISC-43: `Anti:` the bare domain with no scheme and no `//` is never replaced — protects e-mail addresses and DNS strings
- [x] ISC-44: any failure occurring after the backup exists prints the literal `wp db import <backup-path>` restore command
- [x] ISC-45: post-run output lists the source admin users parsed from the `dup-archive` JSON, warning that destination logins were replaced
- [x] ISC-46: post-run output warns the source folder is publicly reachable; `--cleanup` removes it
- [x] ISC-47: preflight checks free disk space is at least 3x the dump size
- [x] ISC-48: preflight warns when the destination `wp-content` holds `object-cache.php` or `advanced-cache.php` drop-ins
- [x] ISC-49: a list-valued `theme_path` migrates both parent and child theme — probed with a `recap` + `recap-child` fixture
- [x] ISC-50: preflight verifies the server knows the collation declared in the dump before dropping any table
- [x] ISC-51: README documents why `guid` is excluded from replacement

### Remote mode — `wp migrate_app_remote` (added 2026-08-27)

- [x] ISC-52: the command registers `before_wp_load` and runs where there is no WordPress
- [x] ISC-53: `--to=` accepts a WP-CLI alias and a raw connection string, parsed with WP-CLI's own `parse_ssh_url`
- [x] ISC-54: Anti: a group alias (`@all`) is refused
- [x] ISC-55: Anti: no password is accepted anywhere; keys and ssh-agent only
- [x] ISC-56: Anti: `StrictHostKeyChecking` is never disabled — `MIGRATE_APP_SSH_OPTS` is the escape hatch and does not weaken it
- [x] ISC-57: preflight asserts `wp-config.php` at the remote path
- [x] ISC-58: preflight refuses when WP-CLI does not run on the remote, naming the phar workaround
- [x] ISC-59: preflight reads remote PHP and refuses below 7.4
- [x] ISC-60: preflight reads `open_basedir` and refuses a staging path PHP cannot read
- [x] ISC-61: free-space gate uses portable `du -sk` / `df -Pk` and budgets 3x
- [x] ISC-62: the tool and package are staged outside the webroot
- [x] ISC-63: Anti: the staged dump is not reachable over HTTP — verified with a real GET against a live server
- [x] ISC-64: the upload uses rsync and resumes; a second push transfers almost nothing
- [x] ISC-65: Anti: macOS resource forks and `.DS_Store` do not cross to the remote
- [x] ISC-66: the tar fallback warns that it cannot resume
- [x] ISC-67: push and run are separately invocable (`--push-only`, `--skip-push`)
- [x] ISC-68: the backup is exported, pulled home and verified BEFORE the import starts
- [x] ISC-69: a truncated or non-SQL backup aborts the run — `Fs::looks_like_sql_dump` unit-probed four ways
- [x] ISC-70: a remote lock blocks a concurrent second run, with a stale-takeover policy and `--force-unlock`
- [x] ISC-71: the confirmation names the resolved host, path, home URL and database size, not the alias
- [x] ISC-72: `--dry-run` transfers nothing and stages nothing
- [x] ISC-73: `--cleanup-only` removes the staging directory without loading WordPress
- [x] ISC-74: `--identity` applies to the migration leg as well as the upload
- [x] ISC-75: Anti: `--wp-binary` ending in a WP-CLI flag is refused when it would displace the connection alias
- [x] ISC-76: the remote exit code propagates to the local shell
- [x] ISC-77: Anti: `src/MigrateAppCommand.php` changed in exactly one place — the webroot-exposure message — and the local e2e still passes unchanged
- [x] ISC-78: the remote path is end-to-end tested over a real sshd, not only over `docker:`
- [ ] ISC-79: [DROPPED — see Decisions 2026-08-27] a `remote:` block in `migration.yaml`
- [ ] ISC-80: [NOT BUILT — see Decisions 2026-08-27] detached execution surviving a dropped connection

### Pull mode and server-to-server — PROPOSED, not built (2026-08-27)

Awaiting go-ahead. These describe the ideal state of a `migrate_app_pull` command and of
server-to-server migration by composition. Nothing below is shipped; `phase: complete` above refers
to the local and push-remote scope only.

**Transport**

- [x] ISC-81: `Ssh::pull_dir()` exists and mirrors `push_dir()` across the same three backends — `docker cp`, rsync, tar-over-pipe
- [x] ISC-82: `pull_dir()` uses the identical openrsync-safe flag set (`-rlptDz --partial --stats --no-owner --no-group`)
- [x] ISC-83: `rsync_excludes()` is applied in the origin -> local direction too
- [x] ISC-84: an interrupted pull resumes — a second run reports ~0 bytes transferred
- [ ] ISC-85: two `Ssh` instances for two different hosts coexist in one process without a ControlPath collision
- [x] ISC-86: Anti: `pull_dir()` never writes outside the named local destination

**Export from the origin**

- [x] ISC-87: `wp db export` runs on the origin over SSH and the dump is brought home
- [x] ISC-88: the pulled dump passes `Fs::looks_like_sql_dump()` before the package is declared good
- [x] ISC-89: `--stream-db` exports via `wp db export -` so no writable temp dir is needed on the origin
- [x] ISC-90: the temporary dump is removed from the origin after a successful pull
- [x] ISC-91: Anti: the origin database is never modified — no import, no search-replace, no option write
- [x] ISC-92: `origin_url` is read from the origin via `wp option get home`, never guessed
- [x] ISC-93: `table_prefix` is read from the origin via `wp db prefix`, never parsed out of the dump

**Package shape — the pulled folder is importable with no hand-editing**

- [x] ISC-94: the folder contains `wp-content/themes/<template>` and `<stylesheet>` for the origin's active theme
- [x] ISC-95: the folder contains `wp-content/plugins`
- [x] ISC-96: the folder contains `wp-content/uploads` unless `--skip-uploads`
- [x] ISC-97: a `migration.yaml` is written carrying origin_url, target_url, theme_path, plugin_path, uploads_path, database, table_prefix
- [x] ISC-98: `target_url` is written EMPTY, so the importer falls back to the destination's own `home_url()`
- [x] ISC-99: `wp migrate_app <pulled-folder> --dry-run` accepts the package with zero hand-editing
- [x] ISC-100: Anti: the pulled package contains no `wp-config.php` and no `.git`

**Live-origin consistency**

- [x] ISC-101: files are pulled BEFORE the database dump, so the DB is the newest artifact in the package
- [ ] ISC-102: [NOT BUILT] the capture window — first byte to dump complete — is printed in the report
- [ ] ISC-103: [NOT BUILT — documented instead, see README 'Files first, database last'] a maintenance-mode hint is offered when the origin looks like live production
- [x] ISC-104: Anti: maintenance mode is never enabled on the origin without an explicit `--maintenance` (held trivially — no `--maintenance` flag exists and nothing writes to the origin)

**Preflight and operator surface**

- [x] ISC-105: `du -sk` sizes for uploads, plugins, themes and the database are printed before any transfer
- [x] ISC-106: the confirmation prints origin host, origin path, origin home URL, total bytes, and the local destination
- [x] ISC-107: `--dry-run` reports what would be pulled and transfers nothing
- [x] ISC-108: free space on the LOCAL disk is checked against the measured origin size
- [x] ISC-109: `--skip-uploads`, `--skip-db` and `--skip-files` each work independently

**Server-to-server, by composition**

- [x] ISC-110: `migrate_app_pull A` then `migrate_app_remote <folder> --to=B` completes a server-to-server migration with no third command
- [x] ISC-111: the intermediate package is a usable standalone backup of A
- [x] ISC-112: A and B never need network reachability to each other
- [x] ISC-113: A and B may use different keys, ports and jump hosts

**Handling of production data**

- [x] ISC-114: Anti: a pulled package directory cannot be committed — `.gitignore` covers the whole folder, not only `*.sql`
- [x] ISC-115: the operator is told, at the end of a pull, that the folder holds a full production database
- [x] ISC-116: Anti: no SSH password is ever accepted, on either leg

**Raised by the Advisor at VERIFY, 2026-08-27**

- [x] ISC-117: preflight proves the origin can actually run `mysqldump` — `wp db export` shells out, and
  shared hosts with `disable_functions=exec,proc_open` are exactly this tool's population
- [x] ISC-118: Anti: a truncated dump is never accepted — a remote-computed byte count or checksum is
  asserted locally, because `Fs::looks_like_sql_dump()` only reads the head of the file
- [x] ISC-119: the origin temp dump is removed on the FAILURE path, not only on success
- [x] ISC-120: Anti: no origin database credentials or salts reach the local package — `wp-config.php` is
  never pulled; prefix, home and siteurl are read via `wp config get` / `wp option get` and synthesized
- [x] ISC-121: backup-plugin archive directories (`updraft`, `backwpup`, `ai1wm-backups`,
  `wpvividbackups`) and page/object caches are excluded by default — they are frequently tens of GB and
  often contain *other* full database dumps
- [x] ISC-122: Anti: a multisite origin is refused with a clear message rather than pulled into a subtly
  broken package
- [x] ISC-123: [SUPERSEDED by ISC-141] version skew is now reported at preflight from the package
  manifest, which is a better place for it than the transport — it works for a local install too — step two already reports remote PHP and WP-CLI versions, but nothing
  compares them against the origin's
- [x] ISC-124: Anti: no dedicated `A -> B` command exists — the package on disk between the two commands
  IS the checkpoint, and a wrapper would reintroduce the dropped-connection failure as one long-running
  local process

**Found by the round-trip test, 2026-08-28**

- [x] ISC-125: the prefix rewriter rewrites `{prefix}user_roles`, `{prefix}capabilities` and
  `{prefix}user_level` in SINGLE-quoted dumps as well as double-quoted ones — mysqldump, and so
  `wp db export`, and so every pulled package, writes single quotes
- [x] ISC-126: Anti: a failed preflight leaves no empty destination folder behind
- [x] ISC-127: a transport failure during preflight is reported as a connection problem, not as a
  missing `wp-config.php`
- [x] ISC-128: a pulled package installs into a destination with a DIFFERENT table prefix and every
  user keeps their role

**Raised by the Advisor at VERIFY, 2026-08-28**

- [x] ISC-129: the prefix rewriter corrects the byte length of PHP-serialized strings that embed the
  prefix — `s:15:"wp_capabilities"` becomes `s:16:"dst_capabilities"`, not a 16-byte string still
  declaring 15
- [x] ISC-130: Anti: no rewritten serialized payload fails `unserialize()` — probed against the
  corrected and uncorrected forms so the test proves the difference, not just the result
- [x] ISC-131: a pulled package folder is `0700` and its dump `0600` — on a shared machine the default
  umask hands a production database to every other account
- [x] ISC-132: the capture window is written into the manifest and printed at the end, naming both
  timestamps and saying plainly that later origin writes are not included
- [x] ISC-133: Anti: the remote cleanup `rm` refuses any path that is not under `/tmp/migrate-app-pull-`
  and ending `.sql`

**Fiction Drafts interoperability, 2026-08-28**

- [x] ISC-134: an unzipped Fiction Drafts export is recognised by its `manifest.json`, detected
  structurally rather than by a name — its `schema` key is the integer 1 and identifies nothing
- [x] ISC-135: `table_prefix`, `multisite` and the WP/PHP/MySQL versions are read from that manifest
- [x] ISC-136: theme detection is NOT taken from the manifest — it records the stylesheet only, while
  scanning the dump recovers the template too, so a child theme keeps its parent
- [x] ISC-137: Anti: `source_abspath()` returns null for a Fiction Drafts package rather than a URL —
  that slot is a filesystem path and the format records none
- [x] ISC-138: a multisite Fiction Drafts export is refused, naming the manifest that said so
- [x] ISC-139: a partial export (`database_only`, `files_no_media`) is reported at preflight
- [x] ISC-140: an export carrying `wp-config.php` is warned about — it holds the origin's database
  password and all eight salts
- [x] ISC-141: a destination older than the source's PHP or WordPress is warned about, using the
  versions the manifest carries — this is the origin/destination skew gate ISC-123 wanted
- [x] ISC-142: the dump scanner finds an option anywhere in an extended INSERT, not only in the first
  tuple
- [x] ISC-143: the dump scanner accepts single-quoted and double-quoted values, with escaped quotes and
  the opposite quote character inside the value
- [x] ISC-144: Anti: an unrelated `manifest.json` is not claimed as a package
- [x] ISC-145: `fiction-drafts-*` is excluded from a pull, for the case where
  `FICTION_DRAFTS_STORAGE_DIR` has been relocated inside uploads
- [x] ISC-146: `Fs::sql_dump_is_complete()` accepts Fiction Drafts' `SET FOREIGN_KEY_CHECKS=1` footer
  as well as mysqldump's marker



### Config generation without WordPress (added 2026-09-03)

- [x] ISC-147: `migrate_app_remote --generate-config` writes a `migration.yaml` from the package alone
- [x] ISC-148: it requires no WordPress on the operator's machine — the local command cannot, being `after_wp_load`
- [x] ISC-149: it requires no network, and `--to=` is optional in this mode
- [x] ISC-150: generated `target_url` is empty, so the destination's own `home_url()` is used at import
- [x] ISC-151: Anti: it refuses to overwrite an existing `migration.yaml` without `--force`
- [x] ISC-152: `--dry-run` prints the file and writes nothing
- [x] ISC-153: Anti: a package with no dump is refused by name, not by writing a config with an empty `database`
- [x] ISC-154: all three writers derive the file through one `ConfigFile`, so there is no fourth format to drift
- [x] ISC-155: a nested active theme (`stylesheet` containing `/`) yields the themes **container**, never the theme's own path
- [x] ISC-156: the nested case is explained in the generated file and as a `WARN`, so the container does not read as a detection failure

## Test Strategy

| isc | type | check | threshold | tool |
|-----|------|-------|-----------|------|
| ISC-1..4 | static | `php -l` on each file | "No syntax errors" | Bash |
| ISC-5 | static | `php -r 'json_decode(...)'` | valid, non-null | Bash |
| ISC-6,7,11,27,28,40 | static | grep for the symbol / absence of it | >=1 hit / 0 hits | Bash grep |
| ISC-8,9,10,14 | static | grep key names in file | all present | Bash grep |
| ISC-12,13 | unit | `php -r` harness requiring `src/Yaml.php` + `src/Fs.php` | parsed values match expected | Bash |
| ISC-15,21..24,29..38,41 | static+trace | grep the guard/flag and read the surrounding block | code path present and ordered | Bash grep + read |
| ISC-16..20 | live | run the generator against the reference package | yaml written, values correct | Bash (php harness) |
| ISC-25,26 | anti | grep for `wp-config` writes and for unprefixed `DROP TABLE` | 0 hits | Bash grep |
| ISC-39 | live | build a temp fixture tree, run the merge function, list result | destination-only dir still present | Bash (php harness) |
| ISC-81..86 | live | `tests/e2e-pull.sh` against the sshd container, pull then re-pull | 2nd run ~0 bytes | Bash |
| ISC-87..93 | live | pull from the container, inspect dump header and generated yaml | prefix + origin_url match source | Bash |
| ISC-94..100 | live | `find` the pulled folder; `grep` the yaml | all paths present, no wp-config.php | Bash |
| ISC-101..104 | trace | read the ordering in the command; assert dump mtime > files mtime | DB is newest | Bash stat |
| ISC-105..109 | live | run with each skip flag and with `--dry-run` | reported bytes match, 0 transferred on dry-run | Bash |
| ISC-110..113 | live | pull from container A, push to container B, assert B serves A's content | HTTP 200 + origin string present | Bash curl |
| ISC-114..116 | anti | `git check-ignore` the pulled folder; grep for any password prompt | ignored; 0 hits | Bash |

## Features

| name | description | satisfies | depends_on | parallelizable |
|------|-------------|-----------|------------|----------------|
| package-skeleton | composer.json, bootstrap, README, example yaml | ISC-1..9 | — | yes |
| yaml-and-paths | dependency-free YAML loader + path resolver | ISC-10..15 | package-skeleton | yes |
| duplicator-introspect | read dup-archive JSON + dump header, `--generate-config` | ISC-16..20 | yaml-and-paths | no |
| safety-rails | preflight, backup, confirm, dry-run, anti-criteria | ISC-21..28 | package-skeleton | no |
| db-import | prefix reconcile, drop, stream import, temp cleanup | ISC-29..33 | safety-rails | no |
| url-replace | three-pass serialization-safe replacement + option re-assert | ISC-34..38 | db-import | no |
| file-merge | rsync/PHP additive merge + finish tasks | ISC-39..41 | safety-rails | yes |
| pull-transport | `Ssh::pull_dir()` — mirror of `push_dir` across all three backends | ISC-81..86 | — | yes |
| pull-export | remote `wp db export` + detection of origin_url and prefix | ISC-87..93 | pull-transport | yes |
| pull-package | assemble the folder and write `migration.yaml` with an empty target_url | ISC-94..100 | pull-export | no |
| pull-safety | ordering, capture window, sizes, space check, skip flags, data warning | ISC-101..109, 114..116 | pull-package | no |
| server-to-server | compose pull + existing `migrate_app_remote`; docs and e2e only | ISC-110..113 | pull-safety | no |

## Decisions

- **2026-09-03 - `--generate-config` belongs on the remote command, and must not phone home.**
  Reported from the field: on a machine with no WordPress there is no way to produce a
  `migration.yaml`, because `migrate_app` is registered `after_wp_load` and WP-CLI therefore never
  dispatches it — the operator sees a bootstrap error, not a missing-config error. The documented
  workaround (`--path=/path/to/any/wordpress`, borrowing an unrelated install) works and reads like an
  apology. `migrate_app_remote` is already `before_wp_load` and is already the command you run on a
  laptop, so it is the right host. It deliberately does **not** SSH to fill in `target_url`, even
  though it has a `--to` and knows how to use it: the value is written empty regardless, because the
  importer falls back to the destination's own `home_url()` and a literal that goes stale rewrites
  every URL in the database to the wrong host. Reading it over SSH would make it accurate today and a
  liability the day the package is installed somewhere else. Nothing else in the file describes the
  destination, so the network buys nothing and costs the offline case. ISC-147..153.

- **2026-09-03 - the third writer forced the extraction that should have happened at the second.**
  `MigrateAppCommand::generate_config()` and `MigrateAppPullCommand::write_manifest()` had already
  drifted — one rendered through `Yaml::dump()`, the other hand-built its lines — and both carried the
  same theme bug. Adding a third copy would have violated the standing invariant directly, so the
  shared tail moved to `src/ConfigFile.php`: theme cardinality, `wp-content` detection, and rendering.
  It depends on neither WordPress nor WP-CLI, which is what lets `tests/probe.php` cover it with no
  bootstrap. `Yaml::dump()` now emits a bare `key:` for an empty scalar, matching the form the pull
  command has always written and `tests/e2e-pull.sh` asserts. ISC-154.

- **2026-09-03 - a nested active theme must generate the container, not the theme.** WordPress
  supports a theme one level below the themes root: `search_theme_directories()` descends past a
  directory with no `style.css` and records the theme as `subdir/theme`, so `stylesheet` can read
  `themes/rem`. Every generator wrote that straight through as
  `theme_path: wp-content/themes/themes/rem`, and `merge_into()` places a single theme at `themes/` .
  `basename( $src )` — flattening it to `wp-content/themes/rem`, which the database does not point at
  and where a *different* theme of the same basename may already sit. Found on a real package where
  both copies existed and differed. The container is the only form `merge_into()` preserves, so a
  nested slug now forces it, with the reason written into the file and repeated as a `WARN` — a
  container that appears without explanation reads as the detector having failed. ISC-155, ISC-156.

- **2026-08-28 - Fiction Drafts is read by `Duplicator`, not by a new class.** Fiction Drafts
  (github.com/dgaitan/Fiction-Drafts) is an export-only backup plugin whose README states it will never
  restore, migrate or rewrite URLs. That makes it the exact complement of this tool. Considered a
  `FictionDrafts` class behind a `PackageManifest` interface; rejected. `MigrateAppCommand` has fifteen
  `$dup->` call sites and every one of them — including the multisite refusal and the version report —
  starts working for the new format the moment its manifest is normalised into Duplicator's key shape
  at load time. An interface would have been more code for less coverage. The class keeps its name and
  its docblock says why.

- **2026-08-28 - the manifest supersedes the transport-level skew gate.** ISC-123 wanted
  origin-vs-destination version checking in the server-to-server path. Reading it from the package
  manifest instead (ISC-141) covers the local install as well, needs no second connection, and works
  for a Duplicator package too. The transport was the wrong layer for it.

- **2026-08-28 - the Advisor raised nine risks; three were already covered, one was a real bug.**
  Checked each against the code rather than accepting the list. Already handled: `capabilities` and
  `user_level` were always in the rewritten key set and are asserted behaviourally
  (`wp user list --role=administrator` on the destination); step two still backs the destination up
  before importing (`backup came home before the import` in the remote e2e); the local free-space gate
  exists in `confirm()`. Genuinely missed and now fixed: serialized byte lengths (ISC-129/130), file
  permissions (ISC-131), capture-window reporting (ISC-132), the unguarded remote `rm` (ISC-133).
  `--stream-db` is now documented as experimental rather than as a peer of the default path, because
  it has never run against a live origin.

- **2026-08-28 - `MigrateAppCommand.php` changed a second time, deliberately.** ISC-77 recorded that
  the local command had been touched in exactly one place. It has now been touched in two. The second
  is `rewrite_prefix()`, and it is a correctness fix rather than churn: the value-level pattern
  accepted double-quoted option names only, so `wp_user_roles` survived unrewritten in any dump from
  mysqldump. Pull mode makes that the common case rather than the rare one. ISC-77 is amended rather
  than dropped, because its intent - do not refactor the working importer while adding transports -
  still holds.

- **2026-08-28 - the folder is the only interface, and no command may shortcut it.** `migrate_app_pull`
  writes a folder and stops; the install commands read a folder and nothing else. Neither knows the
  other exists. This is what makes local-install, remote-install and server-to-server the same two
  steps in different orders, and it is why ISC-124 refuses a direct `A -> B` command even though one
  would be easy to write.

- **2026-08-28 - the destination folder is created after preflight, not before.** A pull that fails on
  a bad key used to leave an empty directory behind. Small, but it trains the operator to ignore
  directories, and the next thing they ignore is a real one. ISC-126.

- **2026-08-28 - Delegation floor deliberately unmet, third time (show your math).** E3 soft floor is
  >=2; 0 selected. The session system prompt forbids the Agent tool unless the user asks. The
  substitute this run was a real round-trip e2e over two live WordPress installs, which found a
  shipped data-loss bug that no amount of cross-reading would have.

- **2026-08-27 - refined: the origin-access constraint was situational, not architectural.** The ISA
  declared "Reaching the origin server for anything" out of scope and "no network calls to the origin"
  a constraint. Both encoded a single fact - the operator had no SSH to *that* origin, which is the
  whole reason the tool runs destination-side. Asked whether a pull direction is possible, the
  FirstPrinciples classification says soft: nothing in the design depends on the origin being
  unreachable. Constraint revised to "may read from an origin over SSH, never writes to it." The hard
  part is unchanged and unchangeable: pull needs SSH to the origin, so it cannot help the migration
  currently in flight.

- **2026-08-27 - server-to-server gets no command of its own.** Considered a dedicated A-to-B command
  that streams between two hosts. Rejected, and the load-bearing reason is key custody, not topology:
  a direct A-to-B transfer needs either agent-forwarding into a host you may not trust, or your private
  key written onto A. Both are strictly worse than paying for a second transfer. Secondarily, direct
  A-to-B requires the two hosts to reach each other, which is false across most shared hosts, and it
  produces no artifact - if the run dies halfway there is nothing to resume from and nothing to
  inspect. Routing through the operator's machine costs a
  second transfer and buys: works across any two hosts, different keys and jump hosts per leg, and a
  standalone backup of A that outlives the migration. So server-to-server is `migrate_app_pull`
  followed by the `migrate_app_remote` that already ships. ISC-110..113.

- **2026-08-27 - pull mode is largely already written, in the wrong place.**
  `MigrateAppRemoteCommand::backup()` already SSHes to a WordPress install, runs `wp db export`,
  brings the dump home with `pull_file()` and plausibility-checks it with `Fs::looks_like_sql_dump()`.
  That is the export half of a pull. What is missing is `pull_dir()` (the mirror of `push_dir()`, same
  three backends) and live-site detection of `origin_url` and `table_prefix`; the manifest itself can
  reuse `generate_config()` once the folder is assembled. The engine is untouched, because the package
  folder is the interface between transport and engine. Resist quoting a percentage-complete: what
  already exists is the *transport*, which was always the part this repo owned. The unbuilt work is
  preflight, consistency ordering, and the refusals in ISC-117..124.

- **2026-08-27 - composition verified, not assumed.** The Advisor flagged "pull then push needs no new
  command" as the highest-risk unverified claim, on the theory that the importer might depend on a
  hand-edited `migration.yaml`. Checked: `MigrateAppCommand.php:212-224` reads `origin_url` and
  `target_url` out of the package's own yaml, and a blank `target_url` falls back to the destination's
  `home_url()`. Composition holds, conditional on the pull writing a valid manifest — ISC-97, ISC-98.

- **2026-08-27 - a pulled package writes `target_url` empty on purpose.** ISC-98. The importer already
  falls back to the destination's own `home_url()` when the value is blank, and the URL-mismatch
  warning added earlier today fires when it is wrong. A pull cannot know where the package will land,
  so guessing a target is strictly worse than declining to.

- **2026-08-27 - Delegation floor deliberately unmet, second time (show your math).** E3 soft floor is
  >=2; 0 selected. The session system prompt forbids the Agent tool unless the user asks. Delegation
  would have bought a Forge cross-read of the pull design; the substitute is that the design mirrors
  code already e2e-verified over a real sshd, plus an Advisor call at VERIFY.

- **2026-08-27 — Delegation floor deliberately unmet (show your math).** E3 soft floor is >=2
  delegation capabilities; 0 selected. The session system prompt explicitly forbids the Agent tool
  unless the user requests it, and the system prompt outranks CLAUDE.md in the PAI instruction
  hierarchy. What Forge would have done: an independent GPT-5.4 pass over `MigrateAppCommand.php`
  for completeness gaps. Mitigation: the anti-criteria (ISC-25..28) plus the live merge and YAML
  probes carry the verification load instead.
- **2026-08-27 — Sub-commands launch as separate processes.** The calling WP-CLI process bootstrapped
  WordPress against the *pre-import* database. Running `search-replace` in-process would operate on a
  stale `$wpdb` state and stale option cache. `WP_CLI::runcommand(..., ['launch' => true])` forks.
- **2026-08-27 — Pre-drop target-prefixed tables rather than `wp db reset`.** Duplicator dumps carry
  no `DROP TABLE IF EXISTS`, so import into a populated DB fails. `wp db reset` drops *every* table in
  the schema, which destroys co-tenants on a shared database. Dropping only `{prefix}%` is the
  narrowest correct action. Becomes ISC-26 and ISC-28.
- **2026-08-27 — Three replacement passes, not one.** `wp search-replace` walks PHP serialization
  correctly, but page-builder and block content store URLs JSON-escaped (`https:\/\/`), and themes
  emit protocol-relative `//host`. Neither is reached by the canonical pass. Becomes ISC-36, ISC-37.
- **2026-08-27 — Classifier tier overridden E1 → E2 (documentation pass).** The classifier returned
  ALGORITHM E1 for "create a CLAUDE.md and a README.md with all instructions". A <90s budget cannot
  honestly document a 2,453-line tool with a troubleshooting section. Escalated under the
  conversation-context override; thinking floor met with BitterPillEngineering + ReReadCheck.
- **2026-08-27 — Test harness moved into the repo before writing docs.** `probe.php` lived in a
  session scratch directory and would have been deleted. A `CLAUDE.md` whose "how to test" section
  points at a missing file is worse than one with no testing section. Now `tests/probe.php`
  (repo-relative, zero-setup, 26 assertions; 44 with `PKG=`) and `tests/e2e.sh` (containerised,
  self-tearing-down, asserts the migration result).
- **2026-08-27 — `rewrite flush` failure is reported, not swallowed.** Surfaced by running `e2e.sh`
  against the full 20-plugin source set: `ai-provider-for-google` fatals the moment migrated code
  first executes. The run must not abort — the migration is already complete — but reporting
  `flush ok` after the operator watched a fatal scroll past is a lie, and permalinks genuinely did
  not flush. Now `flush WARN` plus the plugin name, the failing line, and three recovery commands.
- **2026-08-27 — PHP 7.4 support verified rather than asserted.** `php -l` under 8.3 cannot prove
  7.4 compatibility. Linted all five files and ran the 26-assertion harness under a real
  `php:7.4-cli` container: green.

- **2026-08-27 — remote mode rides WP-CLI's `--ssh` rather than owning the transport for the run.**
  The earlier design called for detached execution under `nohup` so a dropped connection could not
  kill an import. ISC-43 refuted the combination: `Runner::run_ssh_command()` `passthru`s a fixed
  template and `WP_CLI_SSH_PRE_CMD` only prepends, so there is no seam to background anything.
  Detachment and `--ssh` are mutually exclusive. Chose `--ssh` — smaller, and it keeps WP-CLI's
  scheme handling, tty and exit-code propagation. The hazard is documented, not fixed; ISC-70 (lock)
  is the mitigation that mattered, because the realistic damage from a drop is a *second* concurrent
  import, not the first partial one.
- **2026-08-27 — ISC-79 dropped: no `remote:` block in `migration.yaml`.** That file is uploaded to
  the destination. Putting host, user and key path in it would ship connection details to the very
  server being migrated into. WP-CLI aliases already solve this and keep the secret local.
- **2026-08-27 — ISC-77: `MigrateAppCommand.php` was changed, deliberately, in one place.** The
  post-run notice claimed any leftover source folder was "in your webroot and publicly reachable".
  Remote mode stages outside the webroot on purpose, so the claim became false — and a warning that
  cries wolf teaches operators to ignore warnings. The message is now conditional on the folder being
  under `ABSPATH`. The local e2e passes unchanged, which is what the anti-criterion was protecting.
- **2026-08-27 — did not add `--skip-plugins` to the handoff.** It would make a second migration
  possible on a destination left with a fatal plugin, which is tempting. But
  `WP_CLI::runcommand(launch => true)` forwards the caller's runtime config to children unless the
  child command repeats the flag, and `finish()` runs `rewrite flush --hard` without it on purpose.
  The fix would silently neuter the flush. Left alone; documented instead.

## Changelog

- **2026-08-28 — the same blind spot, in a second reader**
  - **conjectured:** the single-quote bug was closed. `rewrite_prefix()` had been fixed and probed
    across quoting and serialized length, and both e2e suites were green.
  - **refuted by:** building a synthetic Fiction Drafts package and asking the existing code to read
    it. Theme detection returned null for both template and stylesheet, and the origin URL that
    appeared to be read from the dump had actually come from the manifest fallback with a guessed
    scheme. `scan_dump_for_option()` carried the identical double-quote assumption AND a second one —
    its pattern was anchored to `VALUES (`, so it only ever examined the first tuple of a statement.
    Duplicator writes double quotes and one row per INSERT, which hid both faults completely;
    mysqldump, `wp db export`, `migrate_app_pull` and Fiction Drafts all write single quotes and
    extended inserts.
  - **learned:** fixing an assumption in one place does not fix it in the others that share it. When a
    bug turns out to be an assumption about an input format, the next move is to grep for every other
    reader of that format rather than to close the ticket. Two readers here made the same two
    assumptions independently, and only a genuinely different input exposed either.
  - **criterion now:** ISC-142 and ISC-143 — an option is found anywhere in a five-row statement, in
    either quote style, with escaped quotes and the opposite quote character inside the value; plus
    a note in `CLAUDE.md` that any new dump parser ships with that test.

- **2026-08-28 — the rewriter broke serialized data while fixing serialized data**
  - **conjectured:** the single-quote fix to `rewrite_prefix()` closed the prefix-rewriting hole. Both
    quote styles now matched, eight unit cases passed including non-matches, and the round trip was
    green with roles intact on the destination.
  - **refuted by:** the Advisor asking whether any probe used prefixes of *different lengths* with the
    token inside a serialized blob. None did. `a:1:{s:15:"wp_capabilities";b:1;}` came out as
    `a:1:{s:15:"dst_capabilities";b:1;}` — sixteen bytes still declaring fifteen. `unserialize()`
    returns `false` on that, silently, so the setting does not error, it evaporates. The tool's own
    first principle is "serialized data is structured data, never `sed` on a SQL file", and its dump
    rewriter was doing exactly that to itself.
  - **learned:** a fix aimed at one class of failure inherits every assumption of the code it lands
    in. The eight probe cases all varied the *quoting* because quoting was the bug in front of me;
    none varied the *length*, which was the bug underneath it. When adding cases to a regression test,
    vary the dimension nobody has thought about yet, not the one that just failed.
  - **criterion now:** ISC-129 and ISC-130 — the length is recomputed from the rewritten value, and
    the probe asserts both that the corrected payload unserializes and that the uncorrected one does
    not, so the test fails if the fix is ever reverted.

- **2026-08-28 — the importer could not read its own tool's dumps**
  - **conjectured:** pull mode was additive. The install path was e2e-verified over a real sshd and
    the engine was untouched, so a package assembled by `migrate_app_pull` would install exactly like
    a Duplicator one.
  - **refuted by:** the first green round trip. `wp migrate_app_remote ./pulled --to=B` aborted with
    "`dst_user_roles` is missing after import. Every user would have no role and you would be locked
    out." The value-level prefix pattern in `rewrite_prefix()` was `/"wp_(user_roles|...)"/` —
    double quotes only. Duplicator's dumps happen to use them; mysqldump does not. Every package this
    new command produces would have stripped every user of their role.
  - **learned:** "the engine is untouched, so the engine is fine" is not a safety argument when you
    have changed what the engine is fed. A new producer for an existing consumer is a new interface,
    and the interface is where the assumptions live. Two things saved this: the importer's own
    post-import guard, which refused rather than half-completing, and an e2e that installed the pulled
    package instead of merely inspecting it.
  - **criterion now:** ISC-125 (both quote styles, unit-probed against eight cases including
    non-matches), ISC-128 (a pulled package installs into a different-prefix destination with roles
    intact, asserted live in `tests/e2e-pull.sh`).

- **2026-08-27 — pull mode is mostly transport work**
  - **conjectured:** a pull direction is roughly 70% already built, because the remote backup step
    already exports a database over SSH and brings it home verified; what remains is a mirrored
    `pull_dir()` and a config writer.
  - **refuted by:** the Advisor call at VERIFY, which pointed out the figure measures only transport —
    the half this repo already owned — and that the unbuilt half is preflight and refusals. Three
    concrete gaps followed that I had not named: `wp db export` shells out to `mysqldump` and fails on
    hosts with `disable_functions=exec,proc_open`, which is precisely this tool's population; a dump
    streamed over stdout can truncate silently and still exit 0, and `looks_like_sql_dump()` reads only
    the head; and pulling `wp-content` whole drags in backup-plugin archives that routinely hold other
    full database dumps.
  - **learned:** readiness estimates that count the code you already have are estimates of the wrong
    thing. On a tool whose entire job is not corrupting a live site, the remaining work is almost always
    the refusals, not the mechanism.
  - **criterion now:** ISC-117..124 — a `mysqldump` capability preflight, an anti-truncation assertion,
    failure-path cleanup, a credentials anti-criterion, default backup-archive excludes, a multisite
    refusal, an A/B version-skew gate, and an explicit refusal to ever add a direct `A -> B` command.

- **conjectured:** `wp db import` is the reliable way to stream a dump, so the import step can
  delegate to it and stop worrying.
  **refuted by:** the first real end-to-end run — `ERROR 1064 ... near 'SOURCE /private/tmp/...'`.
  `wp db import` shells out to `mysql --execute="SOURCE <file>"`, and MySQL client 9.6 removed
  `SOURCE` from `--execute`. Isolated with a two-line probe: `mysql -e "SOURCE f"` fails, `mysql < f`
  succeeds.
  **learned:** delegating to WP-CLI buys correctness (serialization) but inherits WP-CLI's own
  environment coupling. Delegation needs a fallback whenever the delegate shells out to a
  third-party binary whose CLI contract can change. The same bug also poisoned the *rollback
  instruction*, which told the operator to run the very command that does not work.
  **criterion now:** ISC-32.1 (pipe fallback) and ISC-44.1 (rollback hint prints both forms).

- **conjectured:** `rsync -a` performs an additive merge in which the source wins for the files it
  ships.
  **refuted by:** the ISC-39 fixture — a destination file with the same size and mtime kept its old
  contents ("OLD" survived where "NEW" was expected). `-a` preserves mtimes and rsync's default
  quick check compares size + mtime, so a same-size edit is skipped.
  **learned:** "the source is authoritative" is not a property of `rsync -a`; it must be asserted
  with `-I`. The unit fixture caught what a happy-path migration never would, because real source
  and destination files usually differ in mtime by accident rather than by design.
  **criterion now:** ISC-40 requires `rsync -aI`, matching the PHP fallback's always-overwrite
  behaviour.

- **conjectured:** a directory containing a PHP file with a `Plugin Name:` header is a single plugin.
  **refuted by:** `Fs::is_plugin_dir( 'wp-content/plugins' )` returning true. Hello Dolly ships as
  `plugins/hello.php` — a genuine single-file plugin sitting at the *container* root, so every real
  plugins directory answers yes.
  **learned:** the header test identifies *plugin-ness*, not *cardinality*. Cardinality needs a
  second question: does this directory contain children that are themselves plugins?
  **criterion now:** ISC-39 fixture asserts container-vs-single detection in both directions, using
  a self-built fixture so it runs without a package.

- **conjectured:** a successful migration means the finishing steps succeeded too, so `finish()` can
  fire-and-forget with `exit_error => false`.
  **refuted by:** the full-plugin-set e2e run — `rewrite flush` fataled inside
  `ai-provider-for-google`, the step table still printed `flush ok`, and permalinks were silently
  never regenerated.
  **learned:** `exit_error => false` decides whether to *abort*; it does not decide what to
  *report*. Those are separate judgements, and conflating them turns a recoverable warning into a
  silent defect. `rewrite flush` is also the exact point where migrated code first executes, which
  makes it the most likely step to fail and the worst one to report dishonestly.
  **criterion now:** the flush step reports `WARN` with the failing plugin, the file:line, and the
  recovery commands.

- **conjectured:** the migration could be handed to the remote with
  `wp --ssh=<target> --require=<remote>/migrate-app.php migrate_app <pkg>`, since WP-CLI forwards
  every argument verbatim. **refuted by:** the first end-to-end run — `Error: Required file
  'migrate-app.php' doesn't exist (from runtime argument)`, raised by the LOCAL process. `--require`
  is resolved at bootstrap step 31 (`LoadRequiredCommand`), and the SSH dispatch does not happen
  until `LaunchRunner` at step 37. The same string therefore has to be valid on both machines, which
  in general it cannot be. **learned:** "arguments are forwarded verbatim" is not the same as
  "arguments are only interpreted remotely"; anything WP-CLI acts on during bootstrap is acted on
  twice. **criterion now:** the require travels in a generated config the remote reads through
  `WP_CLI_CONFIG_PATH`, set via `WP_CLI_SSH_PRE_CMD` (ISC-52).

- **conjectured:** `ControlPath=%C` was enough to stay inside the 104-byte UNIX socket limit — it was
  written into the code as a comment warning future maintainers about exactly that limit.
  **refuted by:** every SSH connection failing with exit 255 and no message.
  `unix_listener: path "/var/folders/n9/<32>/T/mgapp-<40 hex>.<16 random>" too long`. `%C` is 40
  characters, macOS `$TMPDIR` is ~50, and OpenSSH appends a random 17-character suffix while the
  master is being established. **learned:** knowing about a limit is not the same as budgeting for
  it; the failure mode is indistinguishable from a network problem because `-q` swallows the reason.
  **criterion now:** a short deterministic socket name under `/tmp`, plus a length check that drops
  multiplexing entirely rather than failing to connect.

- **conjectured:** a green `docker:`-transport end-to-end run demonstrated remote mode worked.
  **refuted by:** the advisor pass — `docker exec`/`docker cp` touches no ssh, no rsync, no
  ControlMaster, so 21/21 said nothing about the shipped path. Standing up a real sshd immediately
  surfaced three genuine defects: the ControlPath overflow above, `--info=stats1` being rejected by
  the openrsync that macOS now ships, and `--identity` silently not applying to the migration leg
  because WP-CLI reads keys from alias config only. **learned:** a test double that replaces the
  component under test proves the orchestration and nothing else. **criterion now:** ISC-78 — the
  remote path is tested over a real sshd, with the docker transport kept as a second pass.

## Verification

**Config generation without WordPress — 2026-09-03**

```
php tests/probe.php                   ALL GREEN  108 passed, 0 failed, 2 skipped
PKG=<fiction-drafts pkg> probe.php    119 passed, 1 failed  (pre-existing: `admin users found`
                                      asserts a Duplicator-only `adminUsers` key; identical on
                                      clean HEAD at 96 passed, 1 failed)
./tests/e2e.sh <duplicator pkg>       E2E GREEN         9/9
./tests/e2e-pull.sh <duplicator pkg>  PULL E2E GREEN    50/50
./tests/e2e-remote.sh <duplicator pkg> 30 passed, 2 failed — byte-identical to clean HEAD on the
                                      same fixture, so not a regression from this change
php -l under PHP 7.4 in a container   clean across migrate-app.php, src/*.php, tests/probe.php
```

- ISC-147..150, 152: run against a real Fiction Drafts package on a machine with no WordPress:
  `wp migrate_app_remote ./rem --generate-config` with **no `--to`** wrote a config whose seven values
  are identical to one derived by hand from the manifest and the dump. `--dry-run` printed the same
  file and left the folder without one.
- ISC-151: `Error: migration.yaml already exists: ... Re-run with --force to overwrite it.`
- ISC-154: `tests/e2e-pull.sh` still asserts `target_url is deliberately empty  1` and
  `theme_path points at the active theme  wp-content/themes/origin-theme` after the pull command was
  moved onto `ConfigFile::theme_paths()` — the shared resolver is behaviour-preserving for the
  ordinary case, which is the only claim that refactor makes.
- ISC-155, ISC-156: probed directly, no bootstrap —
  `a nested active theme forces the container  ["wp-content/themes"]` and
  `and never names the nested directory itself  false`. On the real package, where the origin's
  `stylesheet` is literally `themes/rem`, the command wrote `theme_path: wp-content/themes` and
  warned why.
- The two e2e rigs stage only `dup-installer/` and `wp-content/`, so a Fiction Drafts package — whose
  dump sits at the archive root — stages with no dump at all and fails at `origin_url is required`.
  Confirmed pre-existing on clean HEAD. A Duplicator-shaped fixture was built to exercise them.
  Worth fixing in the rigs: they no longer cover every package format the tool claims to read.

**Pull mode and server-to-server — 2026-08-28**

```
php tests/probe.php                 ALL GREEN  57 passed, 0 failed, 2 skipped
./tests/e2e-pull.sh                 PULL E2E GREEN    52/52
./tests/e2e-remote.sh <package>     REMOTE E2E GREEN  (no regression from either rewrite_prefix change)
php -l, and PHP 7.4 in a container  clean across migrate-app.php and src/*.php
```

- ISC-81..84, 86: `pull_dir` over rsync, docker cp and tar. Re-pull reported under 200 KB against a
  14.7 MB package — resume works in the pull direction as it does in the push direction.
- ISC-87..93: `origin_url is the origin's real home  https://old-site.test`,
  `table_prefix is the ORIGIN's prefix  wp_`, `temp dump removed from the origin  0`.
- ISC-94..100: `theme_path points at the active theme  wp-content/themes/origin-theme`,
  `target_url is deliberately empty  1`, `no wp-config.php anywhere in the tree  0`,
  `backup-plugin archive was excluded  0`.
- ISC-110..113: the whole point. `B has the origin's site title  Origin Site`,
  `B kept its own home URL  https://new-site.test`, `destination prefix preserved  dst_`,
  `no old-site.test left in the post body  0` — origin A into destination B, two commands, two
  different prefixes, two different URLs.
- ISC-118: unit-probed both ways — a truncated dump passes the head check and fails the tail check,
  which is the entire reason the tail check exists.
- ISC-125, ISC-128: `the origin's administrator survived  1`,
  `the roles option carries B's prefix  1`.
- ISC-126, ISC-127: `a failed pull leaves no empty folder behind  no`,
  `an unreachable origin says so plainly  matched`.

- ISC-129..133: `serialized length is corrected  a:1:{s:16:"dst_capabilities";b:1;}`,
  `the corrected payload unserializes  true`, `the UNcorrected payload would not have  false`,
  `the package folder is 0700  700`, `the dump is 0600  600`,
  `manifest records when the database was captured  1`.

**Fiction Drafts interoperability — 2026-08-28**

```
php tests/probe.php                        ALL GREEN  85 passed, 0 failed, 2 skipped
PKG=<real duplicator package> …probe.php   ALL GREEN  97 passed, 0 failed, 0 skipped
./tests/e2e-pull.sh                        PULL E2E GREEN
./tests/e2e-remote.sh <package>            REMOTE E2E GREEN
```

- ISC-134..141: probed against a synthetic package built from the real `Manifest::KEYS` — schema as
  integer 1, `active_theme` as stylesheet only, an escaped-and-single-quoted dump with the
  `SET FOREIGN_KEY_CHECKS` footer. `the format is named  'fiction-drafts'`,
  `the ORIGIN prefix comes from the manifest  'fdw_'`, `no source path is invented from a URL  null`.
- ISC-136: `the child theme is detected  'childish'` and `the PARENT theme is detected too  'parental'`
  — the manifest names only the former.
- ISC-142, ISC-143: `option 3 of 5 is found (not just the first)  'parent-theme'`, and the pattern
  probe covers an escaped apostrophe, an embedded double quote, and an empty value.
- Regression on the Duplicator path is the strongest evidence here: with the real package the suite
  runs **97/97 with zero skips**, so the rewritten scanner reads the format it was originally written
  for at least as well as before.

**Not verified.** No test installs a genuine Fiction Drafts zip end to end — the probes use a synthetic
package built to that plugin's documented manifest shape and dump format, not one produced by running
it. Nothing exercises a real multi-volume export, and the `volumes` array is read but unused.

**Not verified.** ISC-85 (two simultaneous `Ssh` instances in one process) is not exercised: the two
legs run as separate commands, so the situation does not arise yet. ISC-102, ISC-103 and ISC-123 are
not built. The `--stream-db` path is coded and its truncation check is unit-probed, but no test has
run it against a live origin.

Probe harness: `php tests/probe.php` (26 assertions standalone, 44 with `PKG=`); end-to-end: `./tests/e2e.sh <pkg>`. Live environment: WordPress 6.7 on MySQL 8.4 in
an isolated Docker container, destination prefix deliberately set to `dst_` against the package's
`wp_` to exercise the prefix rewrite. Environment torn down after the run.

- ISC-1..4: `php -l` — "No syntax errors detected" for `migrate-app.php` and all four `src/*.php`.
- ISC-5: `json_decode` — valid; `autoload.files = migrate-app.php`, `require = php, wp-cli/wp-cli`.
- ISC-6,7: live — `wp --require=... help migrate_app` renders NAME/DESCRIPTION/SYNOPSIS/OPTIONS/
  EXAMPLES against real WP-CLI 2.12.0, proving both registration and docblock parsing.
- ISC-10,14: all six keys read; `theme_path` accepted as a list and as a string.
- ISC-11,12: three backends present; built-in parser passes 7/7 (quoted, comments, empty→null,
  sequences, key-after-sequence). Live runs used the `spyc` backend.
- ISC-13: 4/4 — relative resolves against the folder, absolute passes through.
- ISC-16..20: live on the reference package — wrote `migration.yaml` with
  `origin_url: "https://old-site.com"` (read from the dump's `siteurl` row),
  `database: dup-installer/dup-database__abc1234-20260821.sql`, `table_prefix: wp_`,
  and **both** `recap` and `recap-child` detected from `template`/`stylesheet`. Round-trips through
  the loader.
- ISC-21: live — `Backup written to .../migrate-app-backup-20260827-205923.sql (87.6 KB)`.
- ISC-22: live — after `--dry-run`: 0 backup files, 12 `dst_` tables unchanged, 0 `wp_` tables, no
  theme copied. `Fs::merge_dir(dry)` creates no directory and reports `files: 2`.
- ISC-24,47,50: live preflight — `database reachable (wptest, prefix dst_)`, `wp-content writable`,
  `disk space: 251.4 GB free`, `collation utf8mb4_unicode_520_ci supported`.
- ISC-25,26,27,28: greps — 0 writes touching `wp-config`; DROP only over `SHOW TABLES LIKE prefix%`;
  no URL rewrite anywhere in `rewrite_prefix`; `db reset` appears only in comments explaining why it
  is not used.
- ISC-29,30,30.1,31,32: live — `drop tables ok 12 dropped`, `prefix rewrite ok wp_ -> dst_`,
  `import ok 31 tables`, 0 `wp_` tables leaked. `SHOW TABLES` after import returns `dst_capabilities`
  and `dst_user_level` in usermeta, and `wp user list` resolves `admin@old-site.com,administrator` —
  the ISC-31 payoff.
- ISC-32.1 (new): `import ok 31 tables (mysql < dump)` — the pipe fallback carried the import after
  `wp db import` failed on MySQL client 9.6.
- ISC-33: temp rewritten dump removed in a `finally` block; no `*.tmp.sql` left in the package.
- ISC-34..38,42,43: live — `Made 50 replacements` on the canonical pass; all three passes ok;
  `siteurl`/`home` = `https://new-site.test`. 0 occurrences of the origin domain left in `dst_posts`
  or `dst_postmeta`. The 3 survivors in `dst_options` are correct by design: one bare hostname in
  `rsssl_ssl_labs_data` (ISC-43 protecting scheme-less strings) and two backup *filenames* whose URL
  prefix was rewritten.
- **Serialization integrity (the load-bearing check):** 116 serialized options, **0 corrupt** after
  three replacement passes. `cron` unserializes to 29 hooks; `active_plugins` to 16 entries.
- ISC-39,40: live — `twentytwentyfive` and `akismet` (destination-only) survived; `recap`,
  `recap-child`, `add-to-any`, `contact-form-7` arrived; `wp theme list` shows `recap-child active`
  with `recap parent`. 826 media files; `wp_get_attachment_url()` returns the target domain.
- ISC-41: `flush ok`, `verify home ok https://new-site.test`, per-step table rendered.
- ISC-44,44.1: live — the first (failed) run printed
  `The migration failed. Restore the database with: wp db import <path>`. After the MySQL-9 finding it
  prints that **and** a `mysql -h... -u... -p <db> < <backup>` line, because on the hosts where the
  import fails the `wp db import` rollback fails identically.
- ISC-45: live — `Log in with a SOURCE account: admin@old-site.com`.
- ISC-46: live — `--cleanup` removed the source folder; `dup-installer` gone; migrated themes intact.
- ISC-48: drop-in warning covers `object-cache.php`, `advanced-cache.php`, `db.php`.
- ISC-49: live — child theme active with parent resolved (see ISC-39).
- ISC-51: README documents the `guid` exclusion and its reason.
- **Render probe:** `php -S` + `curl` on the migrated site → `status=200 bytes=122888`, title
  `Old Site – Just another WordPress site`, both `themes/recap/style.css` and
  `themes/recap-child/style.css` referenced, 0 occurrences of `old-site.com` in the rendered
  HTML, 0 PHP fatals.
- **Post-advisor assertions (rerun):** `assert tables ok 31/31`, `assert admin ok 1 administrator(s)`.
  Regression after all edits: 39/39 unit assertions green, 116 serialized options, 0 corrupt.

**Not verified here (honest gaps):** behaviour on a genuinely shared host — LVE/process caps,
`max_allowed_packet`, `wait_timeout`, `DEFINER` clauses in dumped views/triggers, OPcache staleness
after cutover, and the abort path under a mid-import kill. `assert_import_complete()` now detects the
partial-import *symptom*, but those conditions were not themselves reproduced.

### Remote mode (2026-08-27)

`./tests/e2e-remote.sh <package>` — **27/27 green**, run twice over two transports:
a real sshd in the container (generated ed25519 key, scanned host key, rsync over ssh) and
`docker exec`/`docker cp`. Load-bearing evidence from that run:

```
PASS upload used rsync, not the tar fallback        matched
PASS first push transferred the package             yes
PASS second push transferred almost nothing         yes      <- resume works
PASS macOS cruft did not travel                     0
PASS the web server is actually serving             200
PASS the staged dump is NOT reachable over HTTP     404      <- the point of the feature
PASS backup came home before the import             yes
PASS backup is the PRE-migration database           yes
PASS no wp_ tables leaked                           0
PASS siteurl rewritten on the remote                https://new-site.test
PASS serialized options corrupted                   0
PASS second run fails loudly, does not half-run     1
PASS staging removed from the remote                no
```

Regression: `php tests/probe.php` 31 passed / 0 failed / 2 skipped; `./tests/e2e.sh <package>`
E2E GREEN, unchanged. PHP 7.4 lint clean across all nine files.

**Not verified.** A dropped connection mid-import (ISC-80): the run is attached, so the failure is
real and documented rather than handled. Import idempotency under interruption is untested — the
lock (ISC-70) prevents the concurrent-import case, which is the one that compounds. `open_basedir`
refusal (ISC-60) is coded and reviewed but never observed firing, because the container is
unrestricted; the same is true of the free-space refusal (ISC-61) and the tar fallback (ISC-66).
