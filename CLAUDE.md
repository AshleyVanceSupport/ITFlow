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
- Explain the plan before big changes or anything touching the schema.
- **Our database changes go in `admin/database_updates/mountaineerit/` as M001.php, M002.php …**
  (agreed scheme). Never add files to ITFlow's numbered `admin/database_updates/*.php` and
  never edit `db.sql`. Our updates are tracked by name in `mit_database_updates`, run by
  `admin/database_updates_mountaineerit.php` after ITFlow's (update_cli) and at the end of
  both installers. Each file must be safe to run twice (use `mitColumnExists()` etc.).
- **Stay portable to stock ITFlow** (the user may want to move back to itflow-org's `master`): DB
  changes only add – `mit_` tables, or columns on ITFlow tables that are NULL-able/defaulted; never
  rename/drop/alter ITFlow columns, change ITFlow data's meaning, or FK ITFlow tables into ours.
  After every feature that touches the DB or settings, and after every upstream merge, run
  `php scripts/mit_portability_check.php` on the test install and report the result to the user.
  Before every "Promote to production", also do the full test: backup on the test install ->
  restore into a stock checkout of the latest release (`git archive vYY.MM`; install schema first,
  then `scripts/restore_cli.php`) -> click through the main pages and save a client/ticket/reply.
  Keep DEPLOYMENT.md "Moving to stock ITFlow" (what stops working after a move) up to date.
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
- Calendar (Oct 2026): `functions/mit_calendar.php` (ours) - tech colours, booking end
  times (`tickets.ticket_schedule_end`, M001), overlap conflicts. Hooks in
  `functions/ticket.php` (ticketSetSchedule/ticketCancelSchedule), `functions/app.php`
  (createiCalStr `$end`), `functions/calendar.php` (ticket items + feeds), `agent/ajax.php`
  (calendar_move), `agent/calendar.php` + `agent/js/calendar.js` (Techs legend, opens on me),
  `agent/modals/ticket/ticket_edit_schedule.php` + `agent/js/ticket_schedule_modal.js`
  (Tech + How long), `agent/post/ticket.php` (edit_ticket_schedule), `agent/ticket.php`
  (start - end wording), `functions.php` (require), `scripts/update_cli.php`, `setup/index.php`,
  `scripts/setup_cli.php` (run our DB updates). Re-apply them if an upstream merge drops them.
  Calendar settings (view, whose, ticked) are also saved per user in `mit_user_preferences`
  (M002) via `ajax.php?mit_calendar_state` and loaded by `agent/calendar.php` (data-saved-state).
  Each tech can pick their calendar colour (admins: anyone's) from the Techs legend - saved as
  the `calendar.color` preference via `ajax.php?mit_agent_color`; `mitAgentColor()` falls back
  to the automatic colour.
- Microsoft 365 app (Oct 2026): `functions/mit_m365.php` (ours) creates ITFlow's own app in the
  tenant (device-code sign-in as Global Admin, Graph CLI client) or takes a hand-made one, with a
  certificate instead of a secret, renewed 30 days before expiry via Graph `addKey`. Settings in
  `mit_settings` (M003). The mail OAuth secret holds the marker `mit-m365-certificate`; one-line
  hooks swap it for a signed client assertion: `functions/request.php` (httpFormPost),
  `admin/post/settings_mail.php` (token test; Connect `prompt` via `mitM365ConnectPrompt` so a
  non-admin mailbox is not sent to the consent screen), `admin/oauth_microsoft_mail_callback.php`. UI:
  `admin/includes/mit_m365_card.php` required at the top of `admin/settings_mail.php`;
  handlers in `admin/post/mit_m365.php`. Re-apply the hooks if an upstream merge drops them.
  Mail goes through Microsoft Graph (not SMTP/IMAP) once the app has the Mail permissions
  (`MIT_M365_PERMISSIONS_VERSION` 2; older apps get "Update permissions" on the card). Hooks:
  `cron/mail_queue.php` (`$mit_graph = ...mitM365GraphSendReady()` skips the XOAUTH2 block,
  `mitM365GraphSendMail($mail)` before `$mail->send()`; >3 MB goes as draft + upload session),
  `cron/ticket_email_parser.php` (microsoft_oauth `$pass` skips the IMAP token when
  `mitM365GraphMailboxReady()`; `mitM365GraphMailbox(...) ?? new Mailbox(...)`), and
  `admin/post/settings_mail.php` (Test IMAP via `mitM365GraphMailboxTest`; both OAuth saves keep
  the marker via `mitM365KeepCertificateSecret`). Graph stand-ins for ImapEngine are in
  `functions/mit_m365_mailbox.php`. Also `(string)$config_smtp_host` in mail_queue's two
  sendQueueEmail calls (crashed when the SMTP host was never set). Secrets at rest (cert key,
  Outlook tokens) use `mitEncryptSecret()` - key from `$installation_id` in config.php.
- Outlook calendar per tech (Oct 2026, Phase 4 step 1): `functions/mit_outlook.php` (ours, required
  from mit_m365.php) - Account > Outlook calendar (`agent/user/mit_outlook.php`, handlers
  `agent/user/post/mit_outlook.php`, Microsoft returns to `agent/user/mit_outlook_callback.php`).
  Tables `mit_outlook_links` / `mit_outlook_events` (M004); refresh tokens AES-GCM encrypted with
  a key from config.php. Cron job `mit_outlook_sync` (`cron/mit_outlook_sync.php`, one entry
  added to `includes/cron_jobs.php`) pushes changed bookings every minute and checks Outlook
  for edits every 15 min (ITFlow wins). Side-nav entry in `agent/user/includes/user_side_nav.php`.
  Step 2: Outlook busy times copied every 5 min into `mit_outlook_busy` (M005; also
  `mit_outlook_links.busy_refreshed_at`) by the same job. Shown as the `outlook_busy` calendar
  source - hooks in `functions/calendar.php` (calendarSources `+=`, calendarSourceItems early
  return), striped via `[data-kind="outlook_busy"]` CSS in `agent/calendar.php`; counted in
  `mitScheduleConflicts()`. Titles only for the owner; others see Busy/Tentative/Out of office.
  Techs with Outlook synced get booking emails without the .ics (`functions/ticket.php`
  ticketMailSchedule hook, `mitOutlookSyncsEmail`); busy copy skips ITFlow invites
  (iCalUId `ticket-<id>@`). Disabled users (user_status 0) are paused, archived ones removed.
  Reads of our tables fall back quietly when the database update has not run yet.
- Ticket timers in the top bar (Oct 2026): `includes/mit_timers_nav.php` + `js/mit_timers.js` (ours)
  list the browser's ticket timers (ITFlow's localStorage keys `<id>-startTime`/`-pausedTime`
  from `agent/js/ticket_time_tracking.js` - re-check if upstream changes them) with pause /
  resume / discard. Hooks: one require in `includes/top_nav.php` before the bell, and
  `agent/ajax.php?mit_timer_tickets` (ticket names, client-scoped).
- Repeats by weekday (Oct 2026): `functions/mit_recurring.php` (ours, required from mit_calendar.php)
  + `js/mit_repeat_on.js` - "the third Wednesday" / "the last Friday" (RFC 5545 BYDAY, e.g. `3WE`,
  `-1FR`) in `recurring_tickets.recurring_ticket_by_day` and `calendar_events.event_repeat_by_day`
  (M006). Hooks: `functions/ticket.php` (recurringTicketNextRun `$by_day` param + its 4 callers,
  CadenceText), `cron/nightly_tasks.php` (recurring SELECT *), `agent/post/recurring_ticket.php`
  (save / bulk next run), both recurring ticket modals and `calendar_event_form.php` (one-line
  `mitRepeatOnField`), `functions/calendar.php` (event SET, expandRecurringEvent, RepeatText,
  icsRepeatToRrule BYDAY/BYMONTH), event SELECTs with explicit columns in calendar.php,
  dashboard.php, client_overview.php (`mitEventByDayColumn()`).
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
   changed PHP files, run `scripts/mit_portability_check.php` on the test install after the
   database update, then ask the user before pushing to `develop`.

## "Promote to production"
Open a PR `develop` → `MountaineerIT` listing what's included and any DB updates, plus the
result of the stock-ITFlow portability test (see Rules). Ask the user before merging.
