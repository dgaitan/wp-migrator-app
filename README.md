# wp migrate_app

Move a WordPress site into an **existing, working** WordPress installation — rewriting URLs safely
and merging the source's themes, plugins and uploads into the site that is already there.

Everything reduces to two steps.

```bash
# 1. Get the site into a folder.
wp migrate_app_pull ./old-site --from=@old      # ...from a server you can SSH to
                                                 # ...or just unzip a Duplicator package

# 2. Install that folder.
wp migrate_app ./old-site                        # ...into WordPress on this machine
wp migrate_app_remote ./old-site --to=@new       # ...into WordPress on another server
```

**A folder is the only thing the two steps share.** Step one knows nothing about installing; step two
knows nothing about where the folder came from. That is what makes the combinations free:
server-to-server migration is step one against A, then step two against B. There is no third command
for it, and there does not need to be one.

### Step 1 — get the site into a folder

| | |
|---|---|
| **`wp migrate_app_pull <folder> --from=<origin>`** | You have SSH to the source server. It brings the database and `wp-content` home and writes the manifest for you. |
| **Unzip a Duplicator package** | You have no access to the source at all — only the export. Extract it and run `--generate-config`. See [Preparing the package](#preparing-the-package). On a machine with no WordPress of its own, that is `wp migrate_app_remote <folder> --generate-config`. |
| **Unzip a [Fiction Drafts](https://github.com/dgaitan/Fiction-Drafts) export** | Same, for a site running that plugin. Its `manifest.json` is read directly. See [Fiction Drafts exports](#fiction-drafts-exports). |

All three produce the same thing: a folder with a `migration.yaml`, a `.sql` dump, and `wp-content/`.

### Step 2 — install that folder

|  | `migrate_app` | `migrate_app_remote` |
|---|---|---|
| You run it | on the destination server | on your laptop |
| Needs WordPress where you type it | yes | **no** |
| Gets the folder there | you do, by SFTP or File Manager | it does, over rsync |
| Folder ends up | in the webroot, publicly reachable until you delete it | outside the webroot, never web-reachable |
| Backup ends up | on the destination server | **on your machine**, before the import starts |
| Needs SSH | no | yes, key-based |

If you can SSH to the destination, prefer `migrate_app_remote` — fewer manual steps, and it never
leaves a copy of your database sitting under a live webroot.

### Which combination is yours?

| Your situation | The two commands |
|---|---|
| Duplicator export, destination you can SSH to | unzip, then `migrate_app_remote ./pkg --to=@new` |
| Duplicator export, you are already on the destination | unzip into the webroot, then `migrate_app ./pkg` |
| **Server to server** | `migrate_app_pull ./site --from=@old`, then `migrate_app_remote ./site --to=@new` |
| Production down to your laptop | `migrate_app_pull ./site --from=@prod`, then `migrate_app ./site` |

> **`migrate_app_pull` needs SSH to the *source*.** If you only have access to the destination — the
> case this tool was originally built for — you cannot pull. Use a Duplicator export as step one
> instead. Everything from step two onward is identical either way.

---

## Contents

**Start here — read this and nothing else on your first run**
- [**Two scenarios, start to finish**](#two-scenarios-start-to-finish) — install, then a complete
  walkthrough for each of the two situations you can be in
  - [Scenario A — importing into WordPress on this machine](#scenario-a--importing-into-wordpress-on-this-machine)
  - [Scenario B — importing into WordPress on another server](#scenario-b--importing-into-wordpress-on-another-server)
- [Requirements](#requirements)
- [Install](#install)

**Step 1 — getting the site into a folder**
- [Preparing the package](#preparing-the-package) — from a Duplicator export
- [Pulling a site from a server](#pulling-a-site-from-a-server) — over SSH, with `migrate_app_pull`
- [Fiction Drafts exports](#fiction-drafts-exports) — importing what that plugin exports

**Step 2 — installing the folder**
- [Usage — the three-step flow](#usage--the-three-step-flow)
- [A complete worked run](#a-complete-worked-run)
- [Running it against a remote server](#running-it-against-a-remote-server) — from your own machine, over SSH
- [Server to server](#server-to-server) — the two steps back to back

**Reference**
- [migration.yaml reference](#migrationyaml-reference)
- [Flags](#flags)
- [What it actually does](#what-it-actually-does)

**When it is done, or when it goes wrong**
- [After the migration — check these](#after-the-migration--check-these)
- [Rollback](#rollback)
- [Troubleshooting](#troubleshooting)
- [Things worth knowing](#things-worth-knowing)

**Other**
- [Using it without Duplicator](#using-it-without-duplicator)
- [Development and testing](#development-and-testing)

---

## Two scenarios, start to finish

Almost everything in this README is detail on one of these two. **Find the one that matches where you
are sitting, follow it top to bottom, and ignore the other.**

|  | **A — the destination is this machine** | **B — the destination is another server** |
|---|---|---|
| Where you type the commands | on the destination itself | on your own machine |
| Is WordPress installed where you type? | **yes** — that is the site being migrated into | **no** — and it does not need to be |
| Command you use | `wp migrate_app` | `wp migrate_app_remote` |
| How the package gets there | you put it there (SFTP, File Manager, git) | the command uploads it over rsync |
| Do you need SSH? | no | yes, key-based |
| Where the safety backup lands | on the destination | **on your machine**, before anything is touched |

If you can SSH to the destination, prefer **B** even when A is possible. Fewer manual steps, the
backup comes home before the risky part, and the package never sits under a live webroot.

---

### Before either scenario: install the tool

Pick whichever fits the machine you will be typing on. For **A** that is the destination server; for
**B** it is your own machine.

```bash
# Best default — makes `wp migrate_app` available everywhere on that machine.
wp package install /path/to/wp-cli-migrate-app
```

```bash
# No install — point at the bootstrap each time. Handy on a host you just uploaded to.
wp --require=/path/to/wp-cli-migrate-app/migrate-app.php migrate_app ./my-site
```

Confirm it is there before going further:

```bash
wp help migrate_app          # scenario A
wp help migrate_app_remote   # scenario B
```

Full details, including the plugin-folder route: [Install](#install).

---

### Scenario A — importing into WordPress on this machine

**You are on the destination server.** WordPress is installed and working there, and you want the old
site merged into it.

**1. Put the package folder in the WordPress root**, next to `wp-admin/`. However you like — SFTP,
File Manager, `unzip`. You should end up with:

```
/var/www/html/            ← the working WordPress
├── wp-admin/
├── wp-content/
├── wp-config.php
└── my-site/              ← the package you just put there
    ├── database.sql
    └── wp-content/
```

**2. Write the config:**

```bash
cd /var/www/html
wp migrate_app ./my-site --generate-config
```

This reads the package and writes `my-site/migration.yaml`. Because WordPress is loaded around it, it
fills `target_url` with **this site's own URL**.

**3. Read the file it wrote.** Genuinely read it — it is seven lines and it decides what happens:

```bash
cat my-site/migration.yaml
```

**4. Rehearse. Nothing is written:**

```bash
wp migrate_app ./my-site --dry-run
```

**5. Do it:**

```bash
wp migrate_app ./my-site --yes
```

A backup is exported first, to the WordPress root. **6.** Then delete the package folder — it is
sitting in your webroot where the internet can read it:

```bash
rm -rf my-site   # or run step 5 with --cleanup
```

---

### Scenario B — importing into WordPress on another server

**You are on your own machine.** It does **not** need WordPress, PHP-with-a-database, or anything but
WP-CLI, `rsync` and an SSH key. The destination has the WordPress.

**1. Tell WP-CLI about the server, once.** This is the step that answers *"how does it know the
server?"* — you name it here, in WP-CLI's own alias file, `~/.wp-cli/config.yml`:

```yaml
@new:
  ssh: deploy@example.com:22/home/deploy/public_html
  key: ~/.ssh/id_rsa
```

Read that as `<user>@<host>:<port>/absolute/path/to/the/wordpress/root` — substitute your own. The
port is optional, and so is `key:`, which you can leave out if the key is already in your
`ssh-agent` or `~/.ssh/config`.

Check the connection works before going any further, with plain `ssh`:

```bash
ssh deploy@example.com 'ls /home/deploy/public_html/wp-config.php'
```

If that prints the path, you are good. If it asks for a password, stop and fix your keys — this tool
will not accept one.

> Do not test with `wp --ssh=@new`. It looks equivalent and is not: WP-CLI's own `--ssh` resolves
> aliases differently and may fail on an alias this tool reads perfectly well. `--to=@new` is what
> matters here, and step 5's `--dry-run` is the real test of it.

**2. Have the package folder anywhere on your machine.** Any folder, not a webroot:

```
~/Sites/my-site/
├── database.sql
└── wp-content/
```

**3. Write the config — no server involved yet:**

```bash
wp migrate_app_remote ./my-site --generate-config
```

Note there is **no `--to`** here, and that is not an oversight. This step only reads the package on
your disk. The file it writes describes the *source site* — its old URL, its table prefix, which
folders to bring — and contains nothing at all about the destination. So there is no server for it to
know about yet.

`target_url` is left **empty** on purpose, and that is the piece worth understanding: at import time
the destination fills in its own URL, read live from the far end. That is why the same package can be
installed anywhere without editing it, and why a hardcoded value would be a bug waiting to happen.

**4. Read the file it wrote:**

```bash
cat my-site/migration.yaml
```

**5. Now bring in the server. Rehearse first** — this connects, checks the far end and reports, but
transfers nothing and changes nothing:

```bash
wp migrate_app_remote ./my-site --to=@new --dry-run
```

Read the summary it prints. It resolves `@new` to a real host, path and home URL — check they are the
ones you meant.

**6. Do it:**

```bash
wp migrate_app_remote ./my-site --to=@new
```

It uploads to a staging directory **outside** the webroot, takes a database backup and pulls it home
to your machine *before* anything destructive, then runs the import on the far end.

**7. Remove the staging copy** once you are happy — it holds a dump of the old database:

```bash
wp migrate_app_remote ./my-site --to=@new --cleanup-only
```

---

### The one thing that trips people up

`migration.yaml` describes the **package**, never the destination.

```
generate-config  ──reads──>  the folder on your disk        (no server, no --to)
                 ──writes─>  migration.yaml

the migration    ──reads──>  migration.yaml  +  --to=@new   (server named here)
```

That separation is why step 3 needs no `--to` and step 6 does. It is also why one package folder can
be installed on staging, then on production, with no edits in between.

---

## Requirements

| | |
|---|---|
| WordPress | An **already installed and working** single-site install at the destination. Not multisite. |
| WP-CLI | 2.5+ (developed and tested against 2.12). |
| PHP | 7.4+ (linted and unit-tested under real 7.4). |
| Database user | Needs `DROP`, `CREATE`, `INSERT` on the destination schema. |
| Disk | About 3× the dump size free — the dump, a prefix-rewritten copy, and the backup. |
| Optional | `rsync` for faster merges. Without it a PHP walk is used, with identical results. |
| Remote mode | See [Running it against a remote server](#running-it-against-a-remote-server). Your machine needs WP-CLI and SSH; it does **not** need WordPress. |

No Composer install is needed at runtime. WP-CLI is the only dependency.

---

## Install

**As a WP-CLI package** — the command is then available everywhere:

```bash
wp package install /path/to/wp-cli-migrate-app
```

**Without installing** — point at the bootstrap directly. Usually the easiest option on a host where
you just uploaded the folder over SFTP:

```bash
wp --require=/path/to/wp-cli-migrate-app/migrate-app.php migrate_app my_site_to_migrated
```

To make that permanent, add it to the site's `wp-cli.yml` in the WordPress root:

```yaml
require:
  - wp-cli-migrate-app/migrate-app.php
```

**As a plugin** — drop the folder into `wp-content/plugins/`. It registers nothing outside WP-CLI, so
it stays inert for web requests and does not need activating.

Confirm it loaded:

```bash
wp help migrate_app
```

---

## Preparing the package

The command expects an **already-extracted** folder sitting in the destination's webroot, beside
`wp-admin` and `wp-config.php`:

```
public_html/
├── wp-admin/
├── wp-content/          ← the LIVE site's content; will be merged into, never replaced
├── wp-includes/
├── wp-config.php        ← never touched
├── ...
└── my_site_to_migrated/     ← the uploaded package
    ├── migration.yaml       ← written for you by --generate-config
    ├── dup-installer/
    │   ├── dup-archive__abc1234.txt    (Duplicator's manifest — JSON)
    │   └── dup-database__abc1234.sql   (the dump)
    └── wp-content/
        ├── themes/
        ├── plugins/
        └── uploads/
```

To get there from a Duplicator archive:

```bash
mkdir -p ~/public_html/my_site_to_migrated
cd ~/public_html/my_site_to_migrated
unzip /path/to/20260821_yoursite_abc1234_archive.zip     # or: unzip *.daf
```

If you only have SFTP, unzip locally and upload the extracted folder.

The folder name is yours to choose — it is the argument you pass to the command.

> **Do not run Duplicator's own `installer.php`.** This command replaces it. Delete
> `installer-backup.php` and `installer.php` from the webroot if they are there; they are publicly
> reachable and are a remote-code-execution surface.

---

## Pulling a site from a server

`wp migrate_app_pull` is step one when you can SSH to the source. It brings the database and
`wp-content` home into a folder, writes the `migration.yaml`, and stops. It installs nothing.

```bash
wp migrate_app_pull ./old-site --from=@old --dry-run   # measure first
wp migrate_app_pull ./old-site --from=@old
```

`--from` takes the same target grammar as `--to`: a WP-CLI alias, or
`[<user>@]<host>[:<port>][<path>]`. See [Naming the target](#naming-the-target).

### What you get

```
old-site/
├── migration.yaml
├── old.example.com-20260828-011455.sql
└── wp-content/
    ├── themes/your-theme/
    ├── plugins/
    └── uploads/
```

That folder is a complete, installable package. Nothing needs editing before step two.

### The origin is read-only

The pull runs `wp db export` and reads a handful of options. It never imports, never rewrites a URL,
never writes a row. The one file it creates on the far end is a temporary dump in `/tmp`, removed on
the way out — **including when the run fails**, because a forgotten dump is a full copy of the
database sitting on a server nobody is looking at.

### What it deliberately does not bring

| Left behind | Why |
|---|---|
| `wp-config.php` | Database credentials and salts. It is not excluded by pattern — the pull addresses `wp-content` subdirectories by name, so it cannot be reached at all. |
| `updraft/`, `ai1wm-backups/`, `*.wpress`, `backwpup-*` | Backup-plugin archives. Routinely tens of gigabytes, and they usually contain *another* full copy of the database. |
| `cache/`, `et-cache/`, `debug.log`, `node_modules/` | Regenerable. Costs transfer, buys nothing. |

Add your own with `--exclude=pattern1,pattern2`.

### `target_url` is written empty, on purpose

A pull cannot know where the package will eventually land, so it declines to guess. Both install
commands fall back to the destination's own `home_url()` when the value is blank — which in step two
is exactly right. See [Which migration.yaml is used](#which-migrationyaml-is-used).

### Files first, database last

A live site changes while you are copying it. Of the two possible inconsistencies, an uploaded file
that no row references yet is harmless; a row pointing at a file that was never copied is a broken
page. So the files go first and the dump is taken last, making the database the newest thing in the
package.

For a site taking orders, this is still a window. Put the origin in maintenance mode yourself if the
gap matters.

### Proving the dump arrived whole

A truncated dump is worse than no dump: it imports without complaint and loses data silently. The
head of a cut-off dump looks perfect, so the head is not what gets checked.

| Route | Proof |
|---|---|
| Default | The dump is written to a temp file on the origin, rsynced home (resumable), and the byte counts on both ends must match exactly. |
| `--stream-db` | No file, no size to compare — so the dump's own trailing `Dump completed` marker must be present. Cannot resume. |

Use `--stream-db` only when the origin has nowhere to write. It is marked experimental for a reason:
it has been unit-probed but never run against a live origin, and the marker check is a smoke test
where the byte comparison is a proof.

### The capture window is recorded, not hidden

The manifest carries both timestamps, and the run prints them:

```
Capture window:
    files      2026-08-28 01:22:14 UTC
    database   2026-08-28 01:24:02 UTC
    Anything written to the origin after the database timestamp — orders, comments,
    uploads — is not in this package. The gap widens for as long as you wait to install.
```

That last line is the one that matters. A package pulled on Monday and installed on Friday is missing
five days of the origin's writes. Nothing warns you at install time, so check the timestamps.

### The folder is production data

It holds a full copy of the origin's database — users, hashed passwords, whatever a plugin parked in
the options table — and the whole uploads tree. So:

- the folder is created `0700` and the dump `0600`, because on a shared machine the default umask
  hands all of it to every other account;
- `.gitignore` covers pulled packages, not just `*.sql`;
- delete it once the migration is done and verified. Nothing deletes it for you.

`wp-config.php` is never pulled. Not as an exclusion you could switch off — the pull addresses
`wp-content` subdirectories by name, so the file is unreachable by construction.

### Pull flags

| Flag | Effect |
|---|---|
| `--from=<target>` | **Required.** Alias or connection string for the origin. |
| `--remote-path=<path>` | WordPress root on the origin, overriding the target's path. |
| `--identity=<file>` | SSH private key. Defaults to your agent / `~/.ssh/config`. |
| `--proxyjump=<spec>` | Passed to ssh as `-J`. |
| `--wp-binary=<command>` | How to run WP-CLI on the origin when plain `wp` is not on its PATH. |
| `--skip-uploads` | Leave the media library behind. |
| `--skip-files` | Database only. |
| `--skip-db` | Files only. |
| `--stream-db` | **Experimental.** Export down the pipe instead of via a temp file. Weaker integrity check, no resume, not yet run against a live origin. |
| `--exclude=<patterns>` | Extra comma-separated rsync excludes. |
| `--dry-run` | Measure and report. Transfers nothing, creates nothing — not even the folder. |
| `--yes` | Skip the confirmation. |
| `--force` | Write into a folder that already holds a package. |

### What the run shows you

```
Origin:      ssh:deploy@old.example.com/home/deploy/public_html
Into:        /Users/you/old-site

Checking the origin...

About to pull:
    host      old.example.com
    path      /home/deploy/public_html
    home URL  https://old.example.com
    prefix    wp_
    theme     mytheme (child of storefront)
    themes    24.1 MB
    plugins   118.4 MB
    uploads   2.1 GB
    ------------------------------
    transfer  2.2 GB  (plus the database dump)
    into      /Users/you/old-site
    free here 407.8 GB

The origin is read-only in this operation. Nothing is imported and no row is written there.

Pull this site? [y/n]
```

---

## Fiction Drafts exports

[Fiction Drafts](https://github.com/dgaitan/Fiction-Drafts) is an export-only backup plugin — its
README says plainly that it does not restore, migrate or rewrite URLs, and never will. This tool only
imports. They are the two halves of the same job, so an unzipped Fiction Drafts export is a first-class
package here.

```bash
unzip fiction-drafts-backup-1.zip -d ./old-site
wp migrate_app ./old-site --generate-config
wp migrate_app ./old-site --dry-run
```

Migrating to a remote server from a machine with no WordPress on it? Generate the config with the
remote command instead — same derivation, no bootstrap:

```bash
wp migrate_app_remote ./old-site --generate-config
wp migrate_app_remote ./old-site --to=@prod --dry-run
```

### What gets read

An extracted export has `manifest.json` and `database.sql` at its root, with everything else relative
to the WordPress root:

```
old-site/
├── manifest.json
├── database.sql
└── wp-content/…
```

`--generate-config` reads that manifest the same way it reads Duplicator's, and fills in:

| From the manifest | Into `migration.yaml` |
|---|---|
| `home_url` | `origin_url` (the dump is still preferred — it carries the scheme) |
| `table_prefix` | `table_prefix` |
| `multisite` | refuses the migration outright when true |
| `wp_version`, `php_version`, `mysql_version` | reported, and warned about when this server is older |
| `includes_wp_config` | warned about — see below |
| `profile_areas` | warned about when the export is partial |

The **active theme is deliberately not taken from the manifest.** Fiction Drafts records the
stylesheet only, whereas scanning the dump recovers both the stylesheet and its template — so a child
theme keeps its parent.

### Three things worth knowing

**A `full` profile is not a whole WordPress.** Fiction Drafts backs up what you asked for. Migrating a
`database_only` or `files_no_media` export into a live site is legitimate, but it is a partial merge,
and preflight now says so rather than letting you find out afterwards.

**If the export includes `wp-config.php`, the folder holds the origin's credentials.** That is a
per-job choice in Fiction Drafts, off by default, and the manifest records it. The file is never
merged into your site — `migration.yaml` addresses `wp-content` paths only — but it is sitting in the
folder. Preflight warns; delete the folder when you are done.

**Version skew is reported, not enforced.** Fiction Drafts requires PHP 8.1+ and WordPress 6.4+, so a
site running it is likely newer than an old destination. If the source ran a newer PHP than this
server, preflight warns — migrated plugin code written for 8.1 can fatal on 7.4, which surfaces as
[permalinks not flushing](#troubleshooting).

### Pulling from a site that runs Fiction Drafts

Its archives live in `wp-content/fiction-drafts-<32 hex>`, a sibling of themes, plugins and uploads —
which `migrate_app_pull` never walks, so they are excluded by construction. If you have relocated
storage with `FICTION_DRAFTS_STORAGE_DIR` to somewhere inside uploads, the `fiction-drafts-*` exclude
pattern catches it there too. Either way you will not accidentally drag a pile of full-site archives
across the wire.

---

## Usage — the three-step flow

Step two, once you have a folder from either route above.

> Running this from your own machine instead? Everything below still happens — it just happens over
> SSH. Skip to [Running it against a remote server](#running-it-against-a-remote-server).

### 1. Let it write the config

```bash
wp migrate_app my_site_to_migrated --generate-config
```

It reads Duplicator's manifest and the dump header, and writes a `migration.yaml` filled in with the
origin URL, the source table prefix, the database path, and **both the active theme and its parent**
if the source runs a child theme.

**Read the file it wrote.** Fix anything it guessed wrong. In particular check `target_url`, which
defaults to this site's current `home_url()`.

> This form needs a working WordPress around it — `migrate_app` is registered `after_wp_load`, so on a
> machine that has none, WP-CLI never dispatches it and you get a bootstrap error. Use
> `wp migrate_app_remote my_site_to_migrated --generate-config` there; it derives the same file from
> the package alone and leaves `target_url` empty for the destination to fill.

### 2. Dry run

```bash
wp migrate_app my_site_to_migrated --dry-run
```

Every check runs and every action is reported. Nothing is written — no backup, no dropped tables, no
copied files. Read the step table before continuing.

### 3. Migrate

```bash
wp migrate_app my_site_to_migrated
```

It shows you a summary, warns you what it is about to replace, and asks for confirmation. Add
`--yes` to skip the prompt in a script.

---

## A complete worked run

```
$ wp migrate_app my_site_to_migrated --generate-config

Source folder: /home/user/public_html/my_site_to_migrated

# migration.yaml — generated by `wp migrate_app --generate-config`
# Read every line before running the migration. Paths are relative to this folder.
# Source: Old Site (Duplicator 1.5.16.1, WP 7.1, PHP 8.1.34)
# table_prefix is the SOURCE prefix; this site's own prefix is left untouched.

origin_url: "https://old-site.com"
target_url: "https://new-site.test"
theme_path:
  - wp-content/themes/recap
  - wp-content/themes/recap-child
plugin_path: wp-content/plugins
uploads_path: wp-content/uploads
database: dup-installer/dup-database__abc1234-20260821.sql
table_prefix: wp_

Detected theme: recap
Detected theme: recap-child
Success: Wrote .../migration.yaml — review it, then run: wp migrate_app my_site_to_migrated --dry-run
```

```
$ wp migrate_app my_site_to_migrated --yes

Source folder: /home/user/public_html/my_site_to_migrated
Config:        /home/user/public_html/my_site_to_migrated/migration.yaml (yaml backend: spyc)

Preflight
  ✓ database reachable (wptest, prefix `dst_`)
  ✓ wp-content writable
  ✓ dump found: dup-database__abc1234-20260821.sql (2.4 MB)
  ✓ disk space: 251.4 GB free
  ✓ collation utf8mb4_unicode_520_ci supported
  ✓ prefix rewrite queued: `wp_` -> `dst_`
  ✓ configured source paths exist

setting         value
source site     Old Site
origin_url      https://old-site.com
target_url      https://new-site.test
database        dup-database__abc1234-20260821.sql
source prefix   wp_
target prefix   dst_
themes          wp-content/themes/recap, wp-content/themes/recap-child
plugins         wp-content/plugins
uploads         wp-content/uploads

Warning: This REPLACES the contents of database `wptest` (tables prefixed `dst_`) ...

Backing up the current database...
Success: Backup written to /home/user/public_html/migrate-app-backup-20260827-205923.sql

Dropping 12 existing `dst_` tables...
Rewriting table prefix `wp_` -> `dst_`...
Importing database...
Success: Imported 31 tables.

Rewriting URLs...
Success: Made 50 replacements.

Merging files...
  + theme: recap (163 files)
  + theme: recap-child (3 files)
  + plugin: contact-form-7 (143 files)
  + uploads (825 files)

step                            result   detail
backup                          ok       .../migrate-app-backup-20260827-205923.sql (87.6 KB)
drop tables                     ok       12 dropped
prefix rewrite                  ok       `wp_` -> `dst_`
import                          ok       31 tables (mysql < dump)
assert tables                   ok       31/31
assert admin                    ok       1 administrator(s)
search-replace (canonical)      ok       https://old-site.com  ->  https://new-site.test
search-replace (json-escaped)   ok       https:\/\/old-site.com  ->  https:\/\/new-site.test
search-replace (protocol-rel)   ok       //old-site.com  ->  //new-site.test
siteurl/home                    ok       https://new-site.test
theme: recap                    ok       163 files -> .../wp-content/themes/recap (rsync)
uploads                         ok       825 files -> .../wp-content/uploads (rsync)
flush                           ok       rewrite rules + object cache
verify home                     ok       https://new-site.test

Warning: This site's user table now comes from the source. Log in with a SOURCE account:
admin@old-site.com
Warning: The source folder is still in your webroot and is publicly reachable ...
Rollback if needed: wp db import /home/user/public_html/migrate-app-backup-20260827-205923.sql
              or: mysql -hlocalhost -uwpuser -p wptest < /home/user/.../migrate-app-backup-...sql
Success: Migration complete. Visit https://new-site.test
```

---

## Running it against a remote server

Everything above assumes you are sitting on the destination server. You do not have to be.

`wp migrate_app_remote` runs on **your own machine**. It checks the far end can do the job, uploads
the package to a directory outside the remote's webroot, brings a verified database backup home
*before* anything destructive happens, and then runs the same `wp migrate_app` over there. The
migration itself is byte-identical to a local run — same sequencer, same prefix reconciliation, same
serialization-safe replacement.

It is also the safer way to do this. Uploading by hand puts `dup-installer/dup-database__*.sql`
inside your webroot, where anyone who guesses the folder name can download your whole database over
HTTP. Remote mode stages it in `$HOME/.migrate-app`, where the web server cannot serve it at all.

### Setup, once

```bash
# 1. Make the command available on your machine.
wp package install /path/to/wp-cli-migrate-app

# 2. Make sure SSH works on its own. Fix it here, not mid-migration.
ssh deploy@example.com          # accept the host key if asked
ssh-copy-id deploy@example.com  # only if it asked for a password
```

Then name the destination once, in `~/.wp-cli/config.yml`:

```yaml
@prod:
  ssh: deploy@example.com:22/home/deploy/public_html
  key: ~/.ssh/id_ed25519
```

That is a standard WP-CLI alias, so `wp @prod plugin list` and friends start working too.

### Then, every migration

```bash
cd ~/packages   # wherever your extracted package folder is

# 1. Look before you leap. Transfers nothing, changes nothing.
wp migrate_app_remote ./my_site_to_migrated --to=@prod --dry-run

# 2. Upload. Safe to interrupt and repeat — rsync resumes where it stopped.
wp migrate_app_remote ./my_site_to_migrated --to=@prod --push-only

# 3. Migrate what you uploaded.
wp migrate_app_remote ./my_site_to_migrated --to=@prod --skip-push

# 4. Get the source database dump off the server.
wp migrate_app_remote ./my_site_to_migrated --to=@prod --cleanup-only
```

Steps 2 and 3 collapse into one command if you drop both flags. Split them for anything large: a
multi-gigabyte uploads directory is hours of transfer, and a dropped upload should not also mean
re-running the decision to migrate.

Step 4 is not optional housekeeping. Until you run it, a full copy of the source database is sitting
on the server.

### Which migration.yaml is used

The one in your **local** package folder. It is not read on your machine — it is uploaded with
everything else, and the remote reads the copy that lands in staging.

Three consequences worth knowing:

- **It must exist locally before anything starts.** The command refuses immediately if
  `./my_site_to_migrated/migration.yaml` is missing, rather than uploading gigabytes and then
  discovering there is nothing to act on.
- **`--skip-push` uses the copy already on the server.** Edit your local file after pushing and the
  next `--skip-push` run will not see the change. Re-push, or edit the staged copy directly at
  `$HOME/.migrate-app/package/<folder>/migration.yaml`.
- **`target_url` must be the remote site's URL, or empty.** Every URL in the database gets rewritten
  to whatever it says. Leave the value blank and the migration uses the destination's own
  `home_url()`, which in remote mode is almost always what you want — blank is a better default than
  a guess. A stale value copied from another run is the dangerous case, so the confirmation now
  prints the planned rewrite and warns when `target_url` does not match the site it is about to
  change:

  ```
      URLs      https://old-site.com  ->  https://WRONG-SITE.example

  Warning: target_url in migration.yaml is https://WRONG-SITE.example, but this site's home URL is
  https://new-site.com. Every URL in the database will be rewritten to WRONG-SITE.example, not to
  new-site.com. Fix target_url, or delete the value entirely and it will use this site's own home URL.
  ```

`--config=<path>` overrides which file is read, but it is resolved **on the remote** — an absolute
remote path, or one relative to the remote WordPress root. It is not resolved against your machine
or against the staged package.

`migrate_app_remote` has its own `--generate-config`, and on an operator's machine it is the one you
want:

```bash
wp migrate_app_remote ./my_site_to_migrated --generate-config
```

It reads the package and nothing else — **no local WordPress, no network, and `--to` is not
required.** That matters because the local command cannot do this job here. `migrate_app` is
registered `after_wp_load`, so on a machine with no WordPress installed WP-CLI never dispatches it;
you get a bootstrap error rather than a config, and no combination of flags gets you past it. The old
advice was to borrow an unrelated install with `--path=/path/to/any/wordpress`, which works and reads
like an apology.

`target_url` is written **empty**, which is what remote mode wants anyway — the destination's own
`home_url()` is used at import time, so the file cannot go stale by naming a host it later stops
being installed on. That is also why the command does not SSH anywhere to fill it in: an accurate
value today is a liability the day the package is installed somewhere else.

It refuses to overwrite an existing `migration.yaml` unless you pass `--force`, and `--dry-run`
prints the file it would write without creating it.

You can still write the file by hand from [migration.example.yaml](migration.example.yaml).

### What the run shows you

A dry run, and the same summary a real run opens with:

```
Local package: /Users/you/packages/my_site_to_migrated
Remote target: ssh:deploy@example.com:22/home/deploy/public_html

Checking the remote...
+--------------+----+-------------------------------------+
| step         | ok | detail                              |
+--------------+----+-------------------------------------+
| wordpress    | ok | /home/deploy/public_html            |
| wp-cli       | ok | WP-CLI 2.12.0                       |
| php          | ok | 8.1.34                              |
| open_basedir | ok | unrestricted                        |
| transfer     | ok | rsync (resumable)                   |
| disk         | ok | 41.2 GB free, 185.2 MB to send      |
+--------------+----+-------------------------------------+

This will overwrite the database and wp-content of:
    host      deploy@example.com
    path      /home/deploy/public_html
    home URL  https://new-site.com
    database  84.1 MB
    from      /Users/you/packages/my_site_to_migrated (185.2 MB)
```

The prompt names the *resolved* host, path and home URL rather than the alias, because a mistyped
alias is exactly what it is there to catch.

---

Everything from here down is reference — read it when something needs it.

### What you need

| On your machine | On the remote |
|---|---|
| WP-CLI 2.5+ and PHP — **no WordPress required** | WordPress, installed and working |
| `rsync` (optional but strongly recommended) | WP-CLI on `PATH`, or a phar you point at |
| SSH access via key or `ssh-agent` | PHP 7.4+, `rsync` if you want resumable uploads |

SSH keys and `ssh-agent` only — there is no password field anywhere, by design. If you normally type
a password, run `ssh-copy-id user@host` once first. Host key checking is never disabled: if the key
is unknown, `ssh user@host` on its own once and accept it.

This command has to load before WordPress does, so dropping the tool into `wp-content/plugins` will
not register it. `wp package install` (as in the setup above) or an explicit
`wp --require=/path/to/wp-cli-migrate-app/migrate-app.php migrate_app_remote ...` are the two routes
that work.

### Naming the target

`--to=` takes either an alias, as in the setup above, or a connection string in the same grammar
WP-CLI's own `--ssh` accepts:

```
[<scheme>:][<user>@]<host>[:<port>][<path>]
```

```bash
--to=@prod
--to=deploy@example.com:22/home/deploy/public_html
--to=deploy@example.com --remote-path=/home/deploy/public_html
```

An alias is the better habit: it keeps the key path and any ProxyJump in one place, and it is the
only form that survives being typed at 2am. Group aliases (`@all: [@one, @two]`) are refused —
fanning a destructive migration across several sites from one flag is not a feature.

### The backup is taken before anything else, and brought to you

The remote database is dumped, pulled to your machine, and checked that it is a real SQL dump — all
**before** the import starts. Only then does the migration run.

The ordering is the point. A backup that lives only on the machine you are about to overwrite is not
an undo: if something goes wrong, that is precisely the machine you cannot reach. So it comes home
first, and the remote run is told `--skip-backup` so the database is only dumped once.

You will see the remote run print *"You ran with --skip-backup. There is no way back from here."*
Ignore it. That is this command telling the far end not to dump twice, and the summary says so.

The file lands in your working directory (or `--backup-dir=<path>`), named for the host and the time:

```
migrate-app-backup-example.com-20260827-231038.sql
```

To roll back:

```bash
wp --ssh=@prod db import /home/deploy/.migrate-app/migrate-app-backup-....sql
# or, if the remote copy is gone, push yours back up first
```

### Remote flags

Everything from the [Flags](#flags) table still applies to the migration itself and is passed
through. These are the ones remote mode adds:

| flag | effect |
|---|---|
| `--to=<target>` | **Required**, except with `--generate-config`. Alias (`@prod`) or connection string. |
| `--generate-config` | Write `migration.yaml` from the package and exit. No WordPress, no network. |
| `--force` | With `--generate-config`, overwrite an existing `migration.yaml`. |
| `--remote-path=<path>` | WordPress root on the remote, if not in the connection string. |
| `--identity=<file>` | SSH key. Defaults to `ssh-agent` / `~/.ssh/config`. |
| `--proxyjump=<spec>` | Passed to ssh as `-J`. |
| `--staging=<path>` | Where to upload. Defaults to `$HOME/.migrate-app` on the remote. |
| `--wp-binary=<command>` | How to run WP-CLI on the remote when plain `wp` is not on its `PATH`. |
| `--push-only` | Upload and stop. Nothing on the remote is modified. |
| `--skip-push` | Already uploaded. Run the migration. |
| `--cleanup-only` | Delete the staging directory and stop. |
| `--force-unlock` | Take over a lock left by an interrupted run. Check nothing is still importing. |
| `--backup-dir=<path>` | Where the pulled backup goes on **your** machine. |

One environment variable, for hosts that need something ssh_config cannot express:

```bash
MIGRATE_APP_SSH_OPTS="-o PubkeyAcceptedKeyTypes=+ssh-rsa" wp migrate_app_remote ...
```

It is appended to every `ssh` and `rsync` invocation this command makes. It is not a way to turn
`StrictHostKeyChecking` off, and it does not reach the migration itself — WP-CLI builds its own ssh
flags for that leg and accepts no overrides.

### Shared hosts

Two things go wrong on cPanel-class hosting, and both are checked before anything moves.

**Two migrations at once.** Once a run starts, a lock file sits in the staging directory. If a
connection drops, the far end may still be importing, and the natural next move — run it again — would
put two imports into one database. A second run refuses and tells you who holds it and since when.
After six hours the lock is treated as stale and taken over with a warning; `--force-unlock` overrides
sooner, once you have checked nothing is still running.

**No `wp` on the remote.** Upload the phar and point at it:

```bash
scp "$(command -v wp)" deploy@example.com:~/wp-cli.phar
wp migrate_app_remote ./my_site_to_migrated --to=@prod \
   --wp-binary='/usr/local/bin/ea-php81 ~/wp-cli.phar'
```

The explicit PHP binary matters more than it looks. On cPanel the default `php` is often an ancient
5.6 while the site runs 8.1 — `ea-php81`, `ea-php82` and friends are the real ones.

`--wp-binary` may name an interpreter and a script, but it must not end in a WP-CLI *flag*.
`php ~/wp-cli.phar` is fine; `wp --allow-root` is not, when combined with `--identity` or
`--proxyjump`. WP-CLI needs the connection details to be its first argument and a trailing flag takes
that slot. Use the environment instead:

```bash
--wp-binary='WP_CLI_ALLOW_ROOT=1 php ~/wp-cli.phar'
```

The command checks for this and says so rather than failing with `'@migrate-app-target' is not a
registered wp command`.

**`open_basedir`.** PHP is often confined to `~/public_html` plus a temp directory, which makes a
package staged in `$HOME/.migrate-app` unreadable to the very process that has to read it. Preflight
reads `open_basedir` and refuses up front rather than letting it surface later as a confusing "file
not found". If it does, stage inside the webroot and clean up afterwards:

```bash
wp migrate_app_remote ./my_site_to_migrated --to=@prod \
   --staging=/home/deploy/public_html/.migrate-app
wp migrate_app_remote ./my_site_to_migrated --to=@prod --cleanup-only
```

The second command is not optional in that case. A database dump under a live webroot is a download
link.

#### Remote mode: `Cannot connect to <host>`

Exit 255 from ssh, which means the connection never opened. In order of likelihood: the host key is
unknown (`ssh user@host` once and accept it), the key is not loaded (`ssh-add -l`, or pass
`--identity=~/.ssh/id_ed25519`), or the port is wrong. The tool never disables host-key checking, so
"unknown host key" and "wrong key" both look the same from here — running plain `ssh` tells you which.

### Remote mode: `unix_listener: path ... too long for Unix domain socket`

The shared-connection socket exceeded the 104-byte UNIX limit. The tool computes a short path under
`/tmp` and silently drops connection sharing if even that would not fit, so you should not see this
— if you do, `TMPDIR` is unusually long and `MIGRATE_APP_SSH_OPTS="-o ControlMaster=no"` is the
escape hatch. The cost is authenticating once per step instead of once per run.

### Remote mode: `rsync: unrecognized option '--info=...'`

An rsync that does not speak a flag the other end used. The tool deliberately sticks to `--stats`
rather than `--info=stats1`, because macOS ships openrsync now and openrsync rejects GNU-only
options outright. If you still hit this, uninstalling rsync on the remote falls back to a tar stream
— which works, but cannot resume.

### Remote mode: `'@migrate-app-target' is not a registered wp command`

`--wp-binary` ends in a WP-CLI flag, and the flag took the argument slot the connection alias needs.
`wp --allow-root` is the usual culprit. Move the flag into the environment:

```bash
--wp-binary='WP_CLI_ALLOW_ROOT=1 php ~/wp-cli.phar'
```

The command checks for this before it hands off, so you should get the explanation rather than the
raw error.

### Remote mode: `Another migration holds this remote`

A previous run did not finish cleanly. Because the migration runs attached, a dropped connection can
leave the far end still importing — so this refusal exists to stop a second import landing in the
same database. Check first:

```bash
ssh deploy@example.com 'ps aux | grep -i "[m]igrate_app\|[m]ysql"'
```

If nothing is running, re-run with `--force-unlock`. Locks older than six hours are taken over
automatically with a warning.

### Remote mode: the connection dropped mid-import

This is the one genuinely unhandled case. The destination database may be partially imported. Your
backup is on **your** machine — the run printed its path, and it was pulled and verified before the
import started. Restore it:

```bash
wp --ssh=@prod db import /home/deploy/.migrate-app/migrate-app-backup-....sql
```

If the remote copy is gone, push yours back up first with `scp`, then import. Re-running
`migrate_app_remote` from scratch is also viable, but restore first — a partially imported database
is not a clean starting point.

## Things worth knowing about remote mode

**`--ssh` and this command do not combine.** `wp --ssh=@prod migrate_app_remote ...` ships *this*
command to the remote and looks for your package there. WP-CLI intercepts `--ssh` before dispatch and
strips it from the arguments, so the command cannot detect it and refuse — the error it gives you
names the cause instead. Use `--to`.

**`--identity` reaches both legs, but only because of a workaround.** WP-CLI's `--ssh` cannot carry an
identity file — it reads keys from *alias* config only. So the migration leg is handed a synthesised
runtime alias rather than a bare `--ssh=`. If you have an `IdentityFile` for the host in
`~/.ssh/config` you will never notice; if you rely on `--identity`, this is what makes it work.

**macOS ships openrsync now, and it is fine.** It reports itself as rsync 2.6.9 and rejects GNU-only
options, so the upload uses `--stats` rather than `--info=stats1`. Resume still works.

**Uploads resume, tar streams do not.** With `rsync` on both ends an interrupted transfer picks up
where it stopped. Without it the fallback is a tar stream over SSH, which restarts from zero — you
get a warning saying so. On anything large, installing `rsync` on the remote is worth the five
minutes.

**A dropped connection during the import is not handled.** The migration runs attached, so closing
your laptop mid-import leaves a half-imported database. For a large database, run it from somewhere
that stays awake, or add `ServerAliveInterval 30` to your `~/.ssh/config` for the host — that covers
NAT idle timeouts, though not a closed lid.

**A destination left with a fatal plugin cannot be migrated again.** `migrate_app` loads WordPress,
so if a migrated plugin fatals, WP-CLI cannot boot the site and a second run fails outright. The
first run tells you which plugin and how to disable it. `--cleanup-only` still works regardless — it
never loads WordPress.

**The remote's global WP-CLI config is bypassed during the migration.** The uploaded tool is loaded
through `WP_CLI_CONFIG_PATH` pointed at a small generated config in the staging directory, because
`--require` is resolved on *your* machine during bootstrap and a remote-only path fails there. A
project `wp-cli.yml` in the WordPress root is still read normally.

---

## Server to server

There is no `A -> B` command, and that is a deliberate choice rather than a gap. Server-to-server is
the two steps, back to back:

```bash
wp migrate_app_pull   ./site --from=@old
wp migrate_app_remote ./site --to=@new
```

**Why route through your machine instead of host-to-host?** Key custody, mostly. A direct A-to-B
transfer needs either agent-forwarding into a host you may not fully trust, or your private key
written onto A. Paying for a second transfer is the cheaper trade. Beyond that:

- **The two hosts never need to reach each other.** Most shared hosts cannot, and no amount of
  cleverness in this tool would change that.
- **Each leg is configured separately** — different keys, ports, jump hosts, `--wp-binary`.
- **The folder between them is a checkpoint.** If step two fails, step one does not have to run
  again. rsync resumes; the dump is already home.
- **You end up holding a standalone backup of A** that outlives the migration.

The one thing to watch is version skew: check that B's PHP and WordPress are not older than A's
before you start. Step two's preflight reports both.

Both legs are covered end to end in `tests/e2e-pull.sh`, which stands up two separate WordPress
installs — different URLs, different table prefixes, different themes — and moves one into the other.

---

## migration.yaml reference

```yaml
origin_url: https://old-site.com
target_url: https://new-site.com

theme_path:
  - wp-content/themes/recap
  - wp-content/themes/recap-child

plugin_path: wp-content/plugins
uploads_path: wp-content/uploads
database: dup-installer/dup-database__abc1234.sql
table_prefix: wp_
```

| key | required | meaning |
|---|---|---|
| `origin_url` | yes\* | URL the source site lived at. Read from the dump's `siteurl` row if omitted. |
| `target_url` | yes\* | URL it lives at now. Defaults to this site's `home_url()`. |
| `theme_path` | no | A theme directory, or a **list** of them. Point at a container (`wp-content/themes`) to merge every theme inside it. |
| `plugin_path` | no | A plugin directory, a list, or a container. |
| `uploads_path` | no | Media library directory. Merged into `wp_upload_dir()['basedir']`. |
| `database` | yes\* | The `.sql` dump. Globbed out of `dup-installer/` if omitted. |
| `table_prefix` | no | The **source** prefix. Read from the manifest, or the dump's first `CREATE TABLE`. This site's own prefix is never changed. |

\* required, but auto-detected for a Duplicator package.

**Paths** are relative to the package folder; absolute paths are used as-is.

**Child themes:** if the source runs one, list the parent too. `--generate-config` detects this from
the dump's `template` and `stylesheet` options and writes both. A child theme without its parent
renders the site unstyled.

**Single vs container:** a directory containing `style.css` is one theme; a directory containing a
`Plugin Name:` header and no plugin subdirectories is one plugin. Anything else is treated as a
container and each child is merged individually — which is what leaves the destination's own themes
and plugins alone.

**Nested themes:** WordPress lets a theme live one level deeper — `search_theme_directories()`
descends a second level past a directory with no `style.css`, and records the theme as
`subdir/theme`. So `stylesheet` can legitimately read `themes/rem`, meaning
`wp-content/themes/themes/rem`. When `--generate-config` sees that, it writes the **container**
(`theme_path: wp-content/themes`) rather than the theme's own path, and says so in a comment. This is
not the generator giving up: a single theme is placed at `themes/` + `basename()`, which flattens
`themes/rem` to `rem` — where a *different* theme of that name may already be sitting, so the site
either renders unstyled or silently loads the wrong code. The container is the only form that
preserves the nesting. If you hit this, it is usually a leftover from an earlier botched migration on
the source; worth cleaning up there rather than carrying it forward.

---

## Flags

These are `migrate_app`'s. `migrate_app_remote` adds [its own](#remote-flags); `migrate_app_pull`
has [a separate set](#pull-flags).

| flag | effect |
|---|---|
| `--generate-config` | Write `migration.yaml` from the package and exit. |
| `--dry-run` | Report everything, write nothing. |
| `--yes` | Skip the confirmation prompt. |
| `--config=<path>` | Use a config somewhere other than `<folder>/migration.yaml`. |
| `--skip-db` | Files only. |
| `--skip-files` | Database only. |
| `--skip-backup` | Do not export a backup first. Strongly discouraged. |
| `--backup-dir=<path>` | Where the backup goes. Defaults to the WordPress root. |
| `--cleanup` | Delete the source folder on success. |

---

## What it actually does

1. **Preflight** — refuses multisite; checks the DB answers, `wp-content` is writable, the dump
   exists, there is 3× the dump size free on disk, and **the server knows the collation the dump
   declares**. That last one matters: discovering an unsupported collation *after* the tables are
   dropped leaves a blank site.
2. **Backup** — `wp db export` before the first destructive write. If the export produces nothing,
   the migration stops rather than continuing without an undo.
3. **Drop** — only the tables carrying *this site's* prefix. `wp db reset` is never used, because it
   drops every table in the schema, including a co-tenant's.
4. **Prefix reconcile** — if the source prefix differs, the **dump** is rewritten, not
   `wp-config.php`. The rewrite is anchored to SQL statement keywords (`CREATE TABLE`, `INSERT INTO`,
   `ALTER TABLE`, …) so a post containing `` `wp_options` `` in a code sample is not mangled. It also
   rewrites the prefix-bearing *values* `{prefix}user_roles`, `{prefix}capabilities` and
   `{prefix}user_level` — miss those and every user loses their role.
5. **Import** — delegated to `wp db import`, so a large dump streams through `mysql` instead of being
   read into PHP memory. If that fails it retries by piping the dump in on stdin (see
   [MySQL client 9x](#mysql-client-9x-import-fails-with-error-1064-near-source)).
6. **Assert** — compares imported table count against the dump's own `CREATE TABLE` count, and checks
   `{prefix}user_roles` exists and at least one administrator survived. A partial import that reported
   success is caught here.
7. **URL rewrite** — three passes, each in its own process:
   - `wp search-replace --precise --recurse-objects` for the canonical form, which walks PHP
     serialization correctly (a `sed` over the `.sql` cannot);
   - the JSON-escaped form `https:\/\/host`, which block and page-builder content stores;
   - the protocol-relative form `//host` that themes emit for assets.

   Then `siteurl` and `home` are asserted directly.
8. **Merge** — themes, plugins and uploads copied in additively. Files the package ships overwrite
   their counterparts; **anything the destination has that the package does not mention survives.**
   `rsync -aI` when available, a PHP walk otherwise.
9. **Finish** — flush the object cache and rewrite rules, print the per-step table.

---

## After the migration — check these

```bash
# The site answers and the URL is right.
wp option get home --skip-plugins --skip-themes
curl -sI https://your-new-site.com | head -1

# Serialized data survived (should print 0).
wp eval 'global $wpdb; $bad=0;
foreach($wpdb->get_results("SELECT option_value FROM {$wpdb->options}") as $r){
  if(!preg_match("/^[aOs]:\d+:/",$r->option_value)) continue;
  if(@unserialize($r->option_value)===false && $r->option_value!=="b:0;") $bad++;
} echo $bad;' --skip-plugins --skip-themes

# You can still get in.
wp user list --fields=user_login,roles --skip-plugins --skip-themes

# The active theme resolves, parent included.
wp theme list --skip-plugins --skip-themes

# Every active plugin actually exists on disk.
wp plugin list --status=active --skip-plugins --skip-themes
```

Then, in a browser: load the front page, load a single post (proves permalinks), load `/wp-admin`,
and open one image from the media library.

Finally, **delete the source folder** — `--cleanup`, or by hand.

---

## Rollback

Every failure after the backup exists prints both forms, because on some hosts only the second works:

```bash
wp db import /path/to/public_html/migrate-app-backup-20260827-205923.sql
# or
mysql -hlocalhost -uwpuser -p yourdb < /path/to/public_html/migrate-app-backup-20260827-205923.sql
```

Files are merged additively and are never deleted, so a rollback of the database returns the site to
its previous state; migrated theme and plugin directories simply remain on disk, inactive.

---

## Troubleshooting

### MySQL client 9x import fails with ERROR 1064 near SOURCE

`wp db import` runs `mysql --execute="SOURCE <file>"`, and MySQL client 9.x removed `SOURCE` from
`--execute`. This affects *any* dump, not just yours. `migrate_app` detects it and retries with a
stdin pipe. The step table tells you which path ran:

```
import   ok   31 tables (mysql < dump)
```

The same limitation applies to restoring a backup, which is why the rollback hint prints both forms.

### `Folder not found`

The argument is a folder name relative to the WordPress root, not a path relative to your shell's
current directory. Run `wp eval 'echo ABSPATH;'` to see where it is looking.

### `This server does not support the collation the dump declares`

Raised at preflight, **before** anything is dropped. The destination MySQL/MariaDB is older than the
source. Either upgrade it, or convert the dump:

```bash
sed -i 's/utf8mb4_unicode_520_ci/utf8mb4_unicode_ci/g; s/utf8mb4_0900_ai_ci/utf8mb4_unicode_ci/g' \
  my_site_to_migrated/dup-installer/dup-database__*.sql
```

### `The dump declares N tables but only M imported`

Some tables were rejected while the client still returned success. Usual causes: a `DEFINER=` clause
on a dumped view or trigger that your DB user cannot assume, an index too long for an older server,
or a per-table collation. Find the gap:

```bash
grep -oE 'CREATE TABLE `[^`]+`' my_site_to_migrated/dup-installer/dup-database__*.sql | sort > /tmp/want
wp db query "SHOW TABLES;" --skip-column-names | sort > /tmp/have
```

For `DEFINER`, strip it and re-run:

```bash
sed -i 's/DEFINER=[^ ]* //g' my_site_to_migrated/dup-installer/dup-database__*.sql
```

### `Permalinks were not flushed: loading the migrated plugins produced a fatal error`

The migration succeeded. One of the migrated plugins does not run on this host — that step is the
first moment migrated code actually executes. The warning names the file and line. Then:

```bash
wp plugin list --status=active --skip-plugins --skip-themes
wp plugin deactivate <slug> --skip-plugins --skip-themes
wp rewrite flush --hard
```

### The site white-screens after migrating

Almost always a drop-in pointing at a service that is not there. Rename and retry:

```bash
mv wp-content/object-cache.php   wp-content/object-cache.php.off
mv wp-content/advanced-cache.php wp-content/advanced-cache.php.off
```

If that is not it, `define( 'WP_DEBUG', true );` plus `define( 'WP_DEBUG_LOG', true );` and read
`wp-content/debug.log`.

### Posts 404 but the homepage works

Rewrite rules. `wp rewrite flush --hard`, and confirm the destination has a writable `.htaccess`
(Apache) or the correct `try_files` block (nginx).

### Images 404 / media library is empty

Check `uploads_path` actually pointed somewhere, and that the destination's upload base matches:

```bash
wp option get upload_path --skip-plugins --skip-themes   # should usually be empty
wp eval 'print_r( wp_upload_dir() );' --skip-plugins --skip-themes
```

An `upload_path` inherited from the source pointing at the origin's absolute path is the usual cause
— clear it with `wp option update upload_path ''`.

### I cannot log in

The user table came from the source site. Use a source account — the command prints the ones it found.
Otherwise:

```bash
wp user create newadmin you@example.com --role=administrator --skip-plugins --skip-themes
```

### Out of memory during the run

WP-CLI ships as a phar with a `#!/usr/bin/env php` shebang, so `WP_CLI_PHP_ARGS` is ignored. Raise
the limit by invoking PHP directly:

```bash
php -d memory_limit=512M "$(command -v wp)" migrate_app my_site_to_migrated
```

### Some of the old domain is still in the database

Expected in three cases, all correct: the `guid` column, bare-domain occurrences with no scheme (an
email address at that domain), and filenames that happen to contain the domain. See below.

---

## Things worth knowing

**Your login changes.** The dump includes the source's `users` table. After the import the
destination's own accounts are gone. The command prints the source admin logins it found.

**`guid` is deliberately not rewritten.** WordPress uses it as a permanent identifier for feed
readers, not as a URL. Changing it makes every subscriber re-download every post as new.

**The bare domain is deliberately not rewritten.** Replacing `old-site.com` without a scheme would
also rewrite `admin@old-site.com` and DNS strings in the options table. Only `https://old-site.com`,
`https:\/\/old-site.com` and `//old-site.com` are touched.

**Delete the source folder when you are done.** It contains `dup-installer/`, which is reachable over
HTTP and is a remote-code-execution surface on a live site. `--cleanup` handles it.

**Drop-ins.** If `wp-content/object-cache.php`, `advanced-cache.php` or `db.php` exist on the
destination, they belong to a plugin configured against the *old* database. On shared
Redis/Memcached with a non-unique key prefix, a surviving `object-cache.php` can also serve another
site's cached data.

**A merge is a union, not a replacement.** That is the point, and it has a consequence: if the same
plugin exists on both sides at *different versions*, you get the union of two file trees — files
deleted or renamed between those versions survive and can still be autoloaded. If a plugin misbehaves
in a way that makes no sense after migrating, delete its directory and reinstall it. The command
never removes anything, so this is always your call.

**The origin's filesystem path.** Cache plugins and `upload_path` store absolute server paths, which
no URL replacement reaches. After a run the command counts how many option rows still contain the
origin's path and hands you a `--dry-run` search-replace to inspect. It does not rewrite paths
automatically: a scheme-qualified URL is unambiguous, an absolute path is not.

**Salts are not migrated.** The destination keeps its own `AUTH_KEY` and friends. A plugin that
encrypted an API key against the *source* salts cannot decrypt it here, and usually fails silently.
Re-enter third-party credentials after migrating.

**Multisite is not supported.** The command refuses rather than half-migrating.

---

## Using it without Duplicator

Nothing requires a Duplicator package. Any folder containing a `.sql` dump and some `wp-content`
directories works — you just write `migration.yaml` by hand instead of generating it, because there
is no manifest to read:

```yaml
origin_url: https://old-site.com
target_url: https://new-site.com
theme_path: wp-content/themes/mytheme
plugin_path: wp-content/plugins
uploads_path: wp-content/uploads
database: backup.sql
table_prefix: wp_
```

`table_prefix` is still auto-detected from the dump's first `CREATE TABLE` if you leave it out.

---

## Development and testing

```bash
# Unit — no database, no WordPress, no setup at all.
php tests/probe.php

# Unit + Duplicator introspection against a real package.
PKG=/path/to/extracted/package php tests/probe.php

# End-to-end — throwaway MySQL container + scratch WordPress install, a real
# migration, assertions, then full teardown. Touches nothing you own. The
# destination prefix is dst_ on purpose, so every run exercises the prefix rewrite.
./tests/e2e.sh /path/to/extracted/package

# End-to-end for the remote install leg — a real sshd in the container, so
# ssh(1), ControlMaster, rsync and the resume path are the tested ones. Also
# runs the whole thing a second time over the docker: transport.
./tests/e2e-remote.sh /path/to/extracted/package

# End-to-end for BOTH steps: stands up two separate WordPress installs with
# different URLs, prefixes and themes, pulls one into a folder and installs that
# folder into the other. This is the server-to-server proof.
./tests/e2e-pull.sh

# PHP 7.4 compatibility.
docker run --rm -v "$PWD":/app -w /app php:7.4-cli sh -c 'for f in migrate-app.php src/*.php; do php -l "$f"; done'
```

`ISA.md` holds the full criteria list, the decision log, and the evidence behind every claim in this
README. `CLAUDE.md` holds the invariants for anyone — human or agent — changing the code.

---

## License

MIT
