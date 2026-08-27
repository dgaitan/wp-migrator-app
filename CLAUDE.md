# CLAUDE.md — wp-cli-migrate-app

Rules for working inside this repo. Everything here is a thing that has already gone wrong or would
plausibly go wrong; nothing here restates good general practice.

## What this is

A WP-CLI command, `wp migrate_app <folder_name>`, that imports an uploaded WordPress package
(typically Duplicator Lite) into an **already-working** WordPress installation, rewrites URLs, and
merges themes/plugins/uploads into the live site. It runs entirely from the destination server.

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
```

**Static analysis is not evidence here.** The four most serious bugs this tool has had — the rsync
mtime skip, the Hello Dolly misdetection, the MySQL 9 `SOURCE` failure, and the rollback hint that
inherited it — all passed `php -l` and all passed code review. Every one was caught by running
`tests/e2e.sh`. Run it.

## File map

```
migrate-app.php            bootstrap; returns early unless WP_CLI is defined
src/MigrateAppCommand.php  the sequencer — preflight, backup, import, replace, merge, finish
src/Duplicator.php         reads dup-archive JSON + scans the dump for options
src/Fs.php                 path resolution, additive merge, theme/plugin cardinality
src/Yaml.php               three-tier YAML loader + dumper
tests/probe.php            unit harness
tests/e2e.sh               containerised end-to-end
ISA.md                     system of record — criteria, decisions, evidence
```
