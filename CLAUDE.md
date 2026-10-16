# Mountaineer IT build of ITFlow

PHP/MySQL MSP PSA, forked from https://github.com/itflow-org/itflow. Deployment and branch
model: see DEPLOYMENT.md.

## Branches
- `MountaineerIT` – production, default branch. **Never push to or merge into it without
  asking the user first.** Changes reach it only via a PR from `develop`.
- `develop` – staging; beta server tracks it. All work goes here first via PR.
- `master` – untouched copy of upstream master, comparison only.
- Other branches are leftover upstream copies; ignore them.

## Rules
- Feature branches off `develop`, PR into `develop`.
- **Ask the user to confirm before every `git push`** (any branch) and before merging any PR.
- Explain the plan before big changes or anything touching the schema (`db.sql`,
  `admin/database_updates/`). Upstream numbers migrations sequentially – custom ones would
  collide, so don't add any without agreeing a scheme with the user.
- Don't modify `libs/` unless asked.
- Keep diffs against upstream minimal so release merges stay clean.

## Our deviations from upstream (re-check after every upstream merge)
- Default/release branch is `MountaineerIT` instead of `master`: `functions/app.php`
  (`getRepoBranch`), `admin/update.php` (release-branch badge), `setup/index.php` and
  `scripts/setup_cli.php` (default `$repo_branch`).
- `DEPLOYMENT.md`, `CLAUDE.md` (ours).

## "Pull in ITFlow's updates"
1. `git remote add upstream https://github.com/itflow-org/itflow.git` (if missing);
   `git fetch upstream master --tags`.
2. Merge only official releases: `upstream/master` / the newest `vYY.MM` tag – not
   upstream `develop`.
3. Summarise for the user: version, notable features/fixes, new files in
   `admin/database_updates/` (DB version change), anything touching our deviations above.
4. Branch `upstream/vYY.MM` off `develop`, `git merge <tag>`, resolve conflicts keeping our
   deviations, `php -l` changed PHP files, push, open PR into `develop`.

## "Promote to production"
Open a PR `develop` → `MountaineerIT` listing what's included and any DB updates. Ask the
user before merging.
