# Mountaineer IT – ITFlow deployment

This repository is Mountaineer IT's build of [ITFlow](https://github.com/itflow-org/itflow).
Servers install and update from **this** repository, never from itflow-org directly.

The commands below assume Debian/Ubuntu with Apache, ITFlow at `/var/www/itflow`, and the
web server user `www-data` (home directory `/var/www` – check with `getent passwd www-data`).
Adjust paths if yours differ.

## Branches

| Branch          | Purpose                                                        | Who pulls from it   |
|-----------------|----------------------------------------------------------------|---------------------|
| `MountaineerIT` | Production. Default branch. Changes arrive only by PR from `develop`. | Production servers  |
| `develop`       | Staging. Our changes and ITFlow's releases land here first.    | Beta / test server  |
| `master`        | Untouched copy of upstream `master`, for comparison only. **Never deploy it** – see "Moving an existing install over". | Nobody |

Flow:

```
itflow-org/itflow (official releases)
        │  merged on request ("Claude, pull in ITFlow's updates") – PR into develop
        ▼
develop  ──►  beta server         ← feature branches are PR'd into develop too
        │  PR once tested on beta ("Claude, promote develop to production")
        ▼
MountaineerIT  ──►  production servers
```

## How a server knows which branch to follow

ITFlow's updater does a `git fetch` from the clone's `origin` remote, hard-resets the files
to `origin/$repo_branch`, then applies any pending database updates. `$repo_branch` lives
in the server's `config.php`:

| Server     | `origin`                                         | `$repo_branch`              |
|------------|--------------------------------------------------|-----------------------------|
| Production | `https://github.com/MountaineerIT/ITFLOW.git`    | `'MountaineerIT'` (default) |
| Beta       | `https://github.com/MountaineerIT/ITFLOW.git`    | `'develop'`                 |

Admin > Update shows "Not the release branch" on anything other than `MountaineerIT` –
expected on the beta server.

Clicking **Update** in Admin > Update does not update immediately: it *queues* the update and
the cron dispatcher (`cron/cron.php`) runs it within a minute. So **cron must be installed and
switched on** (Maintenance > Cron) for updates to happen.

## New install – one line (Debian 12 / 13)

On a fresh Debian server, as root:

**Beta / testing server** (tracks `develop`, updates itself nightly):

```bash
curl -fsSL https://raw.githubusercontent.com/MountaineerIT/ITFLOW/develop/scripts/install.sh | bash -s -- --branch develop
```

(Minimal Debian may not have `curl`: use `wget -qO- <same URL> | bash -s -- --branch develop`.)

It asks for the server name, certificate type (Let's Encrypt for a public name, self-signed
for an internal one), timezone, company name and the first admin's name, email and password,
shows a summary and asks to continue. It then:

- installs Apache, MariaDB, PHP and git, and sets PHP upload limits to 500 MB
- creates the `itflow` database (utf8mb4_general_ci) and a database user with a random password
- clones this repository into `/var/www/itflow` as `www-data`, on the chosen branch
- sets up the Apache site with HTTP→HTTPS redirect and the certificate
- runs ITFlow's setup (company, first admin, `config.php` with `$repo_branch` set)
- installs the cron entry, switches cron on, sets Domain Refresher to every 30 minutes
- on non-production branches, schedules the nightly self-update (see "Updating")
- saves the database password to `/root/itflow-install-credentials.txt` (root only)

Every question can also be given as an option for an unattended install – run it with
`--help` to list them. Known limit: company and admin names can't contain `'` or `\` yet.

It is for fresh servers only: it stops if `/var/www/itflow` or an `itflow` database already
exists. If it stops part-way, fix the problem it reports and rerun on a fresh server (or drop
the `itflow` database and user and delete `/var/www/itflow` first).

## New install – manual

1. Create the database with the collation ITFlow's schema uses, so tables added by future
   database updates match:

   ```sql
   CREATE DATABASE itflow CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
   ```

2. Create the install folder as root (`/var/www` is root-owned), then clone into it as
   `www-data`:

   ```bash
   sudo install -d -o www-data -g www-data /var/www/itflow
   sudo -u www-data git clone -b MountaineerIT https://github.com/MountaineerIT/ITFLOW.git /var/www/itflow
   # beta server: use -b develop
   ```

3. Run the web setup (`https://<host>/setup`) or `scripts/setup_cli.php` (as `www-data`).
   Setup writes `$repo_branch = 'MountaineerIT'` into `config.php`; **on the beta server
   change it to `'develop'`**.
   - Known issue: `setup_cli.php` crashes if the company or admin name contains an apostrophe
     (e.g. "O'Brien IT"). Use the web setup for such names, or rename afterwards.

4. Add the single cron entry to `www-data`'s crontab (`sudo crontab -u www-data -e`) and turn
   cron on in Maintenance > Cron (it ships switched off):

   ```
   * * * * * php /var/www/itflow/cron/cron.php >/dev/null
   ```

5. Recommended cron setting: in Maintenance > Cron set **Domain Refresher** to run every
   15–60 minutes. It refreshes only one domain per run, so on the default daily schedule a
   list of N domains takes N days to refresh and expiry dates go stale.

6. If you use **nginx** instead of Apache, the `.htaccess` files are ignored – deny web access
   to `/.git`, `/config.php`, `/cron/`, `/scripts/` and `/uploads/backups/` in the nginx config.

## Moving an existing install over from itflow-org

> ⚠️ **Change `$repo_branch` before updating.** An install set up from itflow-org has
> `$repo_branch = 'master'` in `config.php`. In this repository `master` is the untouched
> upstream copy, so updating without changing it would deploy upstream code and run
> database updates that cannot be rolled back. (Recommended: rename this repository's
> `master` branch, e.g. to `upstream-master`, so a forgotten setting fails instead.)

1. Check the server is not ahead of the branch it is moving to: it must be on the same ITFlow
   release as that branch or older (Admin > Update shows the version). Never move a server
   that has run upstream's unreleased `develop`.
2. **Take a backup** (Admin > Backup) – see "Updating".
3. Point the clone at this repository:

   ```bash
   cd /var/www/itflow
   sudo -u www-data git remote set-url origin https://github.com/MountaineerIT/ITFLOW.git
   sudo -u www-data git fetch origin
   ```

4. Edit `config.php`: `$repo_branch = 'MountaineerIT';` (or `'develop'` on beta).
5. Check `www-data`'s crontab has only the single `cron.php` line. Older installs may still
   have separate lines for `nightly_tasks.php`, `ticket_email_parser.php` etc. – remove them,
   or those jobs run twice and outside the schedules set in Maintenance > Cron.
6. Make sure cron is switched on (Maintenance > Cron), then update from Admin > Update.

## Updating

**Always take a backup first** (Admin > Backup, and keep a copy off the server). If a
database update fails part-way, re-running it usually stops on the part that already ran,
and recovery means restoring the backup or repairing the database by hand.

**Production** – manual, when you choose: Admin > Update > Update. To run it from the shell
instead (useful if the web update reports a problem):

```bash
cd /var/www/itflow/scripts && sudo -u www-data php update_cli.php
```

Don't run the shell update while an update queued from the web page is still in progress.

**Beta** – may update itself nightly. Don't call `update_cli.php` straight from crontab: it
swaps the files while the every-minute cron jobs may be running. Instead queue the update
the same way the Update button does, and let the cron dispatcher apply it safely. Create
`/var/www/itflow/scripts/custom/queue_update.php` (the `*/custom/` folders other than
`agent/custom` are not tracked by git, so updates leave it alone; `scripts/` is not reachable
from the web):

```php
<?php
// Queue an ITFlow update for the cron dispatcher - same as Admin > Update > Update.
if (php_sapi_name() !== 'cli') { exit(1); }
require __DIR__ . '/../../config.php';
mysqli_query($mysqli, "UPDATE settings SET config_update_queued_at = NOW() WHERE company_id = 1");
mysqli_query($mysqli, "UPDATE cron_jobs SET cron_job_run_now = 1 WHERE cron_job_name = 'app_update'");
```

```bash
sudo chown www-data:www-data /var/www/itflow/scripts/custom/queue_update.php
```

Then add to `www-data`'s crontab (03:15 nightly):

```
15 3 * * * php /var/www/itflow/scripts/custom/queue_update.php
```

The result appears in Maintenance > Cron (Application Update) and Admin > Update.

**What an update keeps and replaces:** the update hard-resets every file tracked by git.
Untracked files are kept: `config.php`, `uploads/`, and the `custom/` folders – **except
`agent/custom/`**, whose `index.php`, `post.php` and `includes/` are tracked and will be
overwritten. Don't edit tracked files on a server; make changes in this repository instead.

**Browser caching:** CSS and JavaScript files are not versioned, so after an update ask users
to hard-refresh (Ctrl+F5) if a page looks or behaves oddly.

## If the repository is ever made private again (deploy keys)

The repository is public, so servers clone and update over HTTPS with no credentials.
If it is made private, each server needs its own read-only **deploy key**, and its `origin`
must be switched to `git@github.com:MountaineerIT/ITFLOW.git`
(`sudo -u www-data git -C /var/www/itflow remote set-url origin git@github.com:MountaineerIT/ITFLOW.git`).

`/var/www` is owned by root, so create the `.ssh` folder as root first, then generate the
key as `www-data`:

```bash
sudo install -d -o www-data -g www-data -m 700 /var/www/.ssh
sudo -u www-data ssh-keygen -t ed25519 -N "" -C "itflow-$(hostname)" -f /var/www/.ssh/id_ed25519
sudo -u www-data cat /var/www/.ssh/id_ed25519.pub
sudo -u www-data ssh -o StrictHostKeyChecking=accept-new -T git@github.com   # accepts GitHub's host key
```

The last command should say "successfully authenticated" once the key has been added.

On GitHub: **Repository > Settings > Deploy keys > Add deploy key**, paste the public key,
name it after the server, and leave **Allow write access unchecked**.
To retire a server, delete its key there.

## Pulling in ITFlow's updates

Ask Claude Code (with this repository attached): *"Check ITFlow for updates and bring them in."*
It will fetch the latest **official release** from itflow-org, summarise what changed
(including any database updates), merge it into a branch off `develop` and open a PR into
`develop`. Once that is merged the beta server picks it up; test there, then ask Claude to
open the `develop` → `MountaineerIT` PR.

## Rules for our own changes

- Feature branches come off `develop` and are PR'd into `develop`.
- Nothing is pushed or merged into `MountaineerIT` except a reviewed PR from `develop`.
- Don't modify `libs/` (third-party code).
- Keep edits to upstream files small – every changed line can conflict when ITFlow releases
  are merged in. Prefer the `*/custom/` directories where they fit.
- **No custom database migrations** in `admin/database_updates/` without a plan: upstream
  numbers them sequentially, so our own would collide with their next release.
