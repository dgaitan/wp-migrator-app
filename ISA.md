---
project: wp-cli-migrate-app
task: `wp migrate_app` — Duplicator-package migrator, local and over SSH
effort: E3
phase: complete
progress: 79/81
mode: build
started: 2026-08-27
updated: 2026-08-27
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
folder is expected already extracted). Reaching the origin server for anything. Rewriting the
destination `wp-config.php` or its DB credentials. Merging `mu-plugins`, drop-ins, or arbitrary
`wp-content` subdirectories beyond themes/plugins/uploads. Any Duplicator paid-tier feature. Migrating
*into* a WordPress install that does not yet exist.

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
- Destination-side execution only. No network calls to the origin.
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

## Decisions

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
