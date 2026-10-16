# Mountaineer IT build of ITFlow

PHP/MySQL MSP PSA, forked from https://github.com/itflow-org/itflow. Deployment and branch
model: see DEPLOYMENT.md.

## Branches
- `MountaineerIT` – production, default branch. **Never push to or merge into it without
  asking the user first.** Changes reach it only via a PR from `develop`.
- `develop` – staging; beta server tracks it. All work goes here first, committed directly.
- `master` – untouched copy of upstream master, comparison only.
- Other branches are leftover upstream copies; ignore them.

## Rules
- Commit directly to `develop` (no feature branches or PRs into `develop`) – after the user
  confirms the push. PRs are only for `develop` → `MountaineerIT`.
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
- `DEPLOYMENT.md`, `CLAUDE.md`, `scripts/install.sh` (Debian one-line installer) – ours.
- Removed `.github/workflows/first-interaction.yml` (upstream's welcome bot – it points people
  to ITFlow's forum). If an upstream merge brings it back, delete it again.
- Bug and security fixes made here ahead of upstream (Oct 2026, "Phase 1" – see `git log`):
  portal contacts, saved-card charging, income/transactions XSS, API client checks, recurring
  invoices/expenses, payments, autopay, contract templates, ticket time rounding, time
  reports, quote due date, invoice stock, task rows, Admin > Modules, setup_cli/restore_cli.
  When an upstream release fixes the same thing, resolve conflicts in favour of upstream.
- Phase 2 (after the next upstream release is merged): recheck the review findings in files
  upstream rewrote – ticket merge/move/link client checks, AI summary XSS, certificate-check
  SSRF, broken tag links, Clients/Tickets/dashboard/search performance.

## "Pull in ITFlow's updates"
1. `git remote add upstream https://github.com/itflow-org/itflow.git` (if missing);
   `git fetch upstream master --tags`.
2. Merge only official releases: `upstream/master` / the newest `vYY.MM` tag – not
   upstream `develop`. Exception (Oct 2026): upstream `develop` (unreleased 26.11 work, DB
   2.8.9) was merged into our `develop` at the user's request to get the new ticket page
   early. Until upstream releases 26.11, `develop` is ahead of any release - **do not promote
   `develop` to `MountaineerIT` before 26.11 is out** without the user explicitly accepting
   unreleased upstream code in production. When 26.11 is released, merge its tag as usual.
3. Summarise for the user: version, notable features/fixes, new files in
   `admin/database_updates/` (DB version change), anything touching our deviations above.
4. On `develop`, `git merge <tag>`, resolve conflicts keeping our deviations, `php -l`
   changed PHP files, then ask the user before pushing to `develop`.

## "Promote to production"
Open a PR `develop` → `MountaineerIT` listing what's included and any DB updates. Ask the
user before merging.
