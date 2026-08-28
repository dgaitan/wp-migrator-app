# CLAUDE.md — wp-cli-migrate-app

Rules for working inside this repo. Everything here is a thing that has already gone wrong or would
plausibly go wrong; nothing here restates good general practice.

## What this is

Two WP-CLI commands.

`wp migrate_app <folder_name>` imports an uploaded WordPress package (typically Duplicator Lite) into
an **already-working** WordPress installation, rewrites URLs, and merges themes/plugins/uploads into
the live site. It runs on the destination server.

`wp migrate_app_remote <folder> --to=<target>` runs on the operator's machine: it preflights a remote
host, uploads the package outside its webroot, pulls a verified backup home, and then runs the first
command over there. It is a transport wrapper — it never migrates anything itself.

`ISA.md` is the system of record: the ideal state, the criteria, the decision log, and the evidence.
Read it before changing behaviour. Update it when behaviour changes.

## The architectural thesis

**This is a sequencer, not an engine.** Every byte-level operation already has a correct
implementation in WP-CLI core. The value here is ordering, prefix reconciliation, and reversibility.

Concretely: do not write a SQL parser, a serialization walker, or a dump splitter. If you find
yourself reaching for one, the answer is a `WP_CLI::runcommand()` call plus a fallback.

## Invariants — do not break these

| Rule | Why it exists |
|---|---|
| Never call `wp db reset` | It drops **every** table in the schema, including a co-tenant site's. Drop only `{prefix}%` via `target_tables()`. |
| Never modify the destination `wp-config.php` | Reconciling a prefix mismatch by editing `$table_prefix` is the obvious fix and it is wrong — it breaks everything else pointing at that install. Rewrite the *dump* instead. |
| Never regex-replace URLs in the raw `.sql` | PHP serialization encodes byte lengths (`s:26:"..."`). A length-changing edit without rewriting the header corrupts the value. `wp search-replace --precise --recurse-objects` is the only correct tool. |
| Never replace the **bare** domain | `old.com` with no scheme also matches `admin@old.com` and DNS strings. Only `https://old.com`, `https:\/\/old.com` and `//old.com` are in scope. Leftover bare-domain hits are correct, not a bug. |
| Never rewrite `guid` | WordPress treats it as a permanent feed identifier. Rewriting it makes every subscriber re-download every post. |
| Prefix rewriting must stay statement-anchored | Anchor on `CREATE TABLE`/`INSERT INTO`/`ALTER TABLE`/`DROP TABLE`/`LOCK TABLES`. A bare backtick match would rewrite a post containing `` `wp_options` `` in a code sample. |
| Prefix rewriting must also cover the three value-side keys | `{prefix}user_roles` (options), `{prefix}capabilities` and `{prefix}user_level` (usermeta). Miss them and every user, including the operator, imports with no role. Silent admin lockout. |
| Sub-commands need `'launch' => true` | The calling process bootstrapped WordPress against the **pre-import** database. In-process calls operate on stale `$wpdb` and a stale option cache. |
| Sub-commands in the danger window need `--skip-plugins --skip-themes` | `search-replace` runs after the import but **before** files are merged, so `active_plugins` names code that is not on disk yet. Booting it risks a fatal that aborts a half-finished replacement. `rewrite flush` is the deliberate exception — it needs plugins to register CPT rules. |
| `rsync` must be `-aI`, never `-a` | `-a` preserves mtimes and rsync's quick check compares size+mtime, so a same-size source edit is **skipped** and the stale destination file wins. `-I` forces every transfer and matches the PHP fallback's always-overwrite behaviour. |
| Nothing destructive before the backup exists | If `wp db export` produces nothing, stop. Do not continue without an undo. |
| `migrate_app_remote` must never duplicate migration logic | It preflights, moves bytes, and hands off. The far end runs the byte-identical `migrate_app`. Anything else means two implementations drifting. |
| The remote handoff must NOT use `--require=<remote path>` | `--require` is resolved during **local** bootstrap, before the SSH dispatch, so a remote-only path fails on the near side with "Required file doesn't exist". The require travels in a generated config the remote reads via `WP_CLI_CONFIG_PATH`. Verified empirically; it is not a guess. |
| Do not add `--skip-plugins` to the handoff | `WP_CLI::runcommand(launch => true)` forwards the caller's runtime config to children unless the child command string repeats the flag. `finish()` runs `rewrite flush --hard` **without** it on purpose, so an outer `--skip-plugins` would silently neuter the flush. |
| The remote backup is pulled home **before** the import | A backup on the machine you are about to overwrite is not an undo — that is exactly the machine you cannot reach when it goes wrong. Pull, verify it parses as SQL, then pass `--skip-backup` so the DB is dumped once. |
| Never accept an SSH password | Keys and `ssh-agent` only. Never disable `StrictHostKeyChecking`. |
| `rsync` from macOS must not be `-a` | `-a` drags resource forks, `.DS_Store` and local uid/gid onto a Linux host. Use `-rlptDz --no-owner --no-group` plus explicit excludes. ControlPath must use `%C` or long hostnames exceed the 104-char socket limit. |
| Every error path after the backup prints **both** restore forms | `wp db import` and a plain `mysql ... < backup`. See the MySQL 9 gotcha — the hosts where the import fails are the hosts where a `wp db import` rollback also fails. |

## Gotchas

**`wp db import` is broken on MySQL client 9.x.** It shells out to `mysql --execute="SOURCE <file>"`,
and 9.x removed `SOURCE` from `--execute`. Fails with `ERROR 1064 ... near 'SOURCE ...'` for *any*
dump. `import_via_pipe()` is the fallback; it pipes on stdin, which every client version supports.
Do not "simplify" it away.

**`wp-content/plugins/hello.php` is a real plugin at the container root.** So a `Plugin Name:` header
test alone reports the whole plugins directory as a single plugin. `is_plugin_dir()` must also check
that no *child* directory is itself a plugin.

**A migrated plugin can fatal during `rewrite flush`.** That is the first moment the migrated code
actually executes. It must not abort the run — the migration is already done — but it must not report
`ok` either. Report `WARN`, name the plugin, and tell the operator how to recover.

**A package can now live outside the webroot.** Remote mode stages in `$HOME/.migrate-app`. Any
message that says "publicly reachable" must first check the folder is actually under `ABSPATH` —
`post_run_notices()` does. A warning that cries wolf teaches operators to ignore warnings.

**Two copies of this tool can load in one run.** Installed as a package, `--require`d from a
checkout, and uploaded to a remote — all at once. Guards key on `class_exists` and on whether the
command name is taken, never on a constant: a constant only helps when every copy in play is new
enough to define it, which is precisely the case you cannot rely on.

**`--ssh` cannot be combined with `migrate_app_remote`, and cannot be guarded against.** WP-CLI
intercepts `--ssh` before dispatch and strips it from argv, so the command runs on the remote and
never sees the flag. The hint therefore lives on the "not a directory" error the operator does see.

**Duplicator's `dup-archive__*.txt` is JSON despite the extension.** It carries `wp_tableprefix`,
`subsites[0].domain`, `adminUsers`, and the origin's absolute `home` path. Prefer it over parsing.

**The dump uses double-quoted values**: `INSERT INTO \`wp_options\` VALUES("2", "siteurl", "https://…", "on")`.
Not the single-quoted form `mysqldump` emits.

## Constraints

- **PHP 7.4+.** No `str_contains`, `match()`, named arguments, constructor promotion, or union types.
  Verify with `docker run --rm -v "$PWD":/app -w /app php:7.4-cli php -l src/YourFile.php`.
- **No runtime Composer dependencies.** WP-CLI is the only dependency. YAML degrades
  Symfony → Spyc → the built-in parser. Do not add a `require` that the command needs to function.
- **WordPress coding standards**: yoda conditions, spaces inside parens, tabs, full docblocks.
  Match the surrounding code.
- **Single-site only.** Multisite is refused at preflight, deliberately — half-migrating is worse.

## Testing

Both are real and both must pass before you claim anything works.

```bash
# Unit — no database, no WordPress, no setup. 26 assertions.
php tests/probe.php

# Unit + Duplicator introspection against a real package. 44 assertions.
PKG=/path/to/extracted/package php tests/probe.php

# End-to-end — throwaway MySQL container + scratch WordPress, real migration,
# asserts the result, tears everything down. Destination prefix is dst_ on
# purpose so every run exercises the prefix rewrite.
./tests/e2e.sh /path/to/extracted/package

# End-to-end for remote mode — 21 assertions, no SSH server involved. WP-CLI's
# --ssh accepts a docker: scheme and Ssh.php has a matching docker backend, so
# the whole remote path runs against a container.
./tests/e2e-remote.sh /path/to/extracted/package
```

Both e2e rigs set `WP_CLI_PACKAGES_DIR` to a temp dir. Without it, a copy of this tool installed via
`wp package install` loads alongside the checkout and the run dies on a class redeclaration.

The remote rig uses **MariaDB**, not MySQL 8, deliberately: the `wordpress:cli` image ships the
MariaDB client, which cannot reach a MySQL 8 server here at all — it rejects the self-signed TLS
cert, and with `--skip-ssl` it cannot load `caching_sha2_password`. MySQL 8 coverage lives in
`e2e.sh`, where the client runs on the host.

**Static analysis is not evidence here.** The five most serious bugs this tool has had — the rsync
mtime skip, the Hello Dolly misdetection, the MySQL 9 `SOURCE` failure, the rollback hint that
inherited it, and the single-quote blindness in `rewrite_prefix()` — all passed `php -l` and all
passed code review. Every one was caught by running an e2e. Run them.

**A new producer for an existing consumer is a new interface.** `migrate_app_pull` did not change one
line of the importer, and it still broke it: the value-level prefix pattern accepted double-quoted
option names only, which Duplicator emits and mysqldump does not, so every pulled package stripped
every user of their role. "The engine is untouched" is not a safety argument once you have changed
what the engine is fed. Any new way of producing a package must be tested by *installing* one, not by
inspecting it.

**The folder is the only interface between the two steps.** `migrate_app_pull` writes a folder and
stops; `migrate_app` and `migrate_app_remote` read a folder and nothing else. Do not add a command
that goes origin-to-destination directly, however convenient it looks — it needs agent-forwarding or
a private key on the origin, it assumes two hosts can reach each other, and it destroys the on-disk
checkpoint that makes a failed second leg cheap. See ISC-124.

**Never pull `wp-config.php`.** Not as an exclude pattern — structurally. The pull addresses
`wp-content` subdirectories by name so the file cannot be reached. An exclusion the operator can
switch off is a footgun; credentials and salts have no business on a laptop.

## File map

```
migrate-app.php            bootstrap; returns early unless WP_CLI is defined
src/MigrateAppCommand.php  the sequencer — preflight, backup, import, replace, merge, finish
src/Duplicator.php         reads dup-archive JSON + scans the dump for options
src/MigrateAppRemoteCommand.php  step 2, remote — preflight, push, backup-and-pull, handoff
src/MigrateAppPullCommand.php    step 1 — preflight, pull files then db, write the manifest
src/Ssh.php                connection resolution, remote exec, rsync/docker push and pull
src/Fs.php                 path resolution, additive merge, theme/plugin cardinality
src/Yaml.php               three-tier YAML loader + dumper
tests/probe.php            unit harness
tests/e2e.sh               containerised end-to-end, local mode
tests/e2e-remote.sh        containerised end-to-end, remote install over a real sshd + docker:
tests/e2e-pull.sh          containerised end-to-end, BOTH steps: two WordPress installs, A into B
ISA.md                     system of record — criteria, decisions, evidence
```
