# Mountaineer IT – ITFlow deployment

This repository is Mountaineer IT's build of [ITFlow](https://github.com/itflow-org/itflow).
Servers install and update from **this** repository, never from itflow-org directly.

## Branches

| Branch          | Purpose                                                        | Who pulls from it   |
|-----------------|----------------------------------------------------------------|---------------------|
| `MountaineerIT` | Production. Default branch. Changes arrive only by PR from `develop`. | Production servers  |
| `develop`       | Staging. Our changes and ITFlow's releases land here first.    | Beta / test server  |
| `master`        | Untouched copy of upstream `master`, for comparison only. Never deploy it. | Nobody |

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

ITFlow's updater (Admin > Update, or `scripts/update_cli.php`) does a `git fetch` from the
clone's `origin` remote and hard-resets the files to `origin/$repo_branch`, then runs any
pending database updates. `$repo_branch` lives in the server's `config.php`:

| Server     | `origin`                                         | `$repo_branch`              |
|------------|--------------------------------------------------|-----------------------------|
| Production | `git@github.com:MountaineerIT/ITFLOW.git`        | `'MountaineerIT'` (default) |
| Beta       | `git@github.com:MountaineerIT/ITFLOW.git`        | `'develop'`                 |

The update page shows "Not the release branch" on anything other than `MountaineerIT`
– expected on the beta server.

## Giving a server access (deploy key)

The repository is private, so each server gets its own read-only **deploy key**.
Run as the user that owns the ITFlow files (usually `www-data`):

```bash
sudo -u www-data mkdir -p /var/www/.ssh
sudo -u www-data ssh-keygen -t ed25519 -N "" -C "itflow-$(hostname)" -f /var/www/.ssh/id_ed25519
sudo -u www-data cat /var/www/.ssh/id_ed25519.pub
sudo -u www-data ssh -o StrictHostKeyChecking=accept-new -T git@github.com   # accepts GitHub's host key
```

(Use that user's home directory – check with `getent passwd www-data`.)

On GitHub: **Repository > Settings > Deploy keys > Add deploy key**, paste the public key,
name it after the server, and leave **Allow write access unchecked**.
To retire a server, delete its key there.

## New install

```bash
cd /var/www
sudo -u www-data git clone -b MountaineerIT git@github.com:MountaineerIT/ITFLOW.git itflow   # beta: -b develop
```

Then run the web setup (`https://<host>/setup`) or `scripts/setup_cli.php`. Setup writes
`$repo_branch = 'MountaineerIT'` into `config.php`; **on the beta server change it to
`'develop'`**.

Add the cron entry to the file owner's crontab and turn cron on in Maintenance > Cron:

```
* * * * * php /var/www/itflow/cron/cron.php >/dev/null
```

## Moving an existing install over from itflow-org

```bash
cd /var/www/itflow
sudo -u www-data git remote set-url origin git@github.com:MountaineerIT/ITFLOW.git
sudo -u www-data git fetch origin
```

Set `$repo_branch` in `config.php` (`'MountaineerIT'` or `'develop'`), then update from
Admin > Update. The server must not be ahead of the branch it is moving to: a production
server should already be on the same ITFlow release as `MountaineerIT` (or older), never on
upstream's unreleased `develop`, because database updates cannot be rolled back.

## Updating

**Production** – manual, when you choose. Take a backup, then Admin > Update > Update,
or from the shell:

```bash
cd /var/www/itflow/scripts && sudo -u www-data php update_cli.php
```

**Beta** – may update itself. Add to the file owner's crontab (runs nightly at 03:15):

```
15 3 * * * cd /var/www/itflow/scripts && php update_cli.php >> /var/log/itflow-update.log 2>&1
```

The update discards local edits to tracked files. Untracked files – `config.php`,
`uploads/` and the `*/custom/` directories – are left alone.

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
