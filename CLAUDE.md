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
  both installers and restores. Each file must be safe to run twice (use `mitColumnExists()`,
  `mitIndexExists()` etc.).
- **Stay portable to stock ITFlow** (the user may want to move back to itflow-org's `master`): DB
  changes only add – `mit_` tables, or columns on ITFlow tables that are NULL-able/defaulted; never
  rename/drop/alter ITFlow columns, change ITFlow data's meaning, add unique keys or triggers to
  ITFlow tables, or FK ITFlow tables into ours. Our tables may FK ITFlow's (tickets, users) only with
  ON DELETE CASCADE / SET NULL, so ITFlow's deletes carry on. Our columns on ITFlow tables are named
  like theirs (`ticket_schedule_end`, `item_cost`, `product_vendor_id`) rather than with a `mit_`
  prefix - the portability check and this file are how they are told apart.
  After every feature that touches the DB or settings, and after every upstream merge, run
  `php scripts/mit_portability_check.php` on the test install and report the result to the user.
  Before every "Promote to production", also do the full test: backup on the test install ->
  restore into a stock checkout of the latest release (`git archive vYY.MM`; install schema first,
  then `scripts/restore_cli.php`) -> click through the main pages and save a client/ticket/reply.
  Keep DEPLOYMENT.md "Moving to stock ITFlow" (what stops working after a move) up to date.
- Smoke tests: `cd tests/smoke && npx playwright test` against the test install (never production) - after
  every upstream merge, before every "Promote to production", and after changing a page or hook in ITFlow's files.
  Report the result. New features get a test there (it must fail when the feature is broken).
- Don't modify `libs/` unless asked.
- Keep diffs against upstream minimal so release merges stay clean.

## Our deviations from upstream (re-check after every upstream merge)
- Default/release branch is `MountaineerIT` instead of `master`: `functions/app.php`
  (`getRepoBranch`), `admin/update.php` (release-branch badge), `setup/index.php` and
  `scripts/setup_cli.php` (default `$repo_branch`).
- `DEPLOYMENT.md`, `CLAUDE.md`, `scripts/install.sh` (Debian one-line installer), `tests/smoke/` (Playwright smoke
  tests, see its README) – ours.
- Removed `.github/workflows/first-interaction.yml` (upstream's welcome bot – it points people
  to ITFlow's forum). If an upstream merge brings it back, delete it again.
- Bug and security fixes made here ahead of upstream (Oct 2026, "Phase 1" – see `git log`):
  portal contacts, saved-card charging, income/transactions XSS, API client checks, recurring
  invoices/expenses, payments, autopay, contract templates, ticket time rounding, time
  reports, quote due date (`agent/post/quote.php` add_quote_to_invoice due from the invoice date),
  invoice stock, task rows, Admin > Modules, setup_cli/restore_cli. Also removed
  `agent/client_autopay.php` (unlinked, broken queries) and the `agent/custom/post.php` require of a
  missing `post/ai.php` (commit 2985b652) - if an upstream merge brings them back, check they work first.
  These fixes carry no "Mountaineer IT" marker (they are plain bug fixes, meant to match upstream's
  eventual fix) - find them with `git log`.
  When an upstream release fixes the same thing, resolve conflicts in favour of upstream.
- Calendar (Oct 2026): `functions/mit_calendar.php` (ours) - agent colors, booking end
  times (`tickets.ticket_schedule_end`, M001), overlap conflicts. Hooks in
  `functions/ticket.php` (ticketSetSchedule/ticketCancelSchedule), `functions/app.php`
  (createiCalStr `$end`), `functions/calendar.php` (ticket items + feeds), `agent/ajax.php`
  (calendar_move), `agent/calendar.php` + `agent/js/calendar.js` (color dots in Whose, opens on me),
  `agent/modals/ticket/ticket_edit_schedule.php` + `agent/js/ticket_schedule_modal.js`
  (Tech + How long), `agent/post/ticket.php` (edit_ticket_schedule), `agent/ticket.php`
  (start - end wording), `functions.php` (require), `scripts/update_cli.php`, `setup/index.php`,
  `scripts/setup_cli.php` (run our DB updates). Re-apply them if an upstream merge drops them.
  Calendar settings (view, whose, ticked) are also saved per user in `mit_user_preferences`
  (M002) via `ajax.php?mit_calendar_state` and loaded by `agent/calendar.php` (data-saved-state).
  Each tech picks their calendar color under Account > Preferences (`agent/user/user_preferences.php`
  + `agent/user/post/profile.php`); admins can set anyone's in Admin > Users > Edit
  (`admin/modals/user/user_form.php` + `admin/post/users.php` edit_user) - one-line hooks,
  `mitCalendarColorField()` / `mitSaveAgentColor()`, saved as the `calendar.color` preference;
  `mitAgentColor()` falls back to the automatic color.
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
- Quote pricing and images (Oct 2026): `functions/mit_quotes.php` + `js/mit_quote_pricing.js` (ours) -
  our cost, Markup / Margin % or Set price, and one image per quote line and per product (upload or
  a link - an image or a product page's og:image, fetched public-only; GD re-encodes into
  `uploads/mit_images/`). Columns `quote_items.item_cost/_price_method/_price_percent/_image` and
  `products.product_cost/...` (M007); defaults in `mit_settings` `quote_price_*` / `quote_show_images`
  (card `admin/includes/mit_quote_card.php` required at the end of `admin/settings_quote.php`,
  handler `admin/post/mit_quote_settings.php`). Hooks: `agent/quote.php` (Image / Our cost cells, add
  row multipart, profit box under the totals), `agent/modals/quote/quote_item_edit.php`, `agent/post/quote.php`
  (add/edit/copy/delete items, PDF image), `guest/guest_view_quote.php` + `guest/guest_post.php` (PDF),
  `agent/modals/product/product_add.php` / `product_edit.php`, `agent/post/product.php`,
  `agent/post/client.php` (client delete). Invoices are untouched (customer price only).
- Additional techs on tickets (Oct 2026): `functions/mit_ticket_techs.php` (ours, required from
  mit_calendar.php), table `mit_ticket_techs` (M008) - helpers beside the lead (`ticket_assigned_to`
  unchanged), each booked with the lead (follows_lead), at their own time, or not booked. UI: modal
  `agent/modals/ticket/mit_ticket_tech.php`, handlers `agent/post/mit_ticket_techs.php`, one-line
  `mitTicketTechsProp` in `agent/ticket.php` (under Assigned to). Hooks: `functions/ticket.php`
  (ticketSetSchedule `mitTicketTechsLeadMoved`, ticketCancelSchedule `mitTicketTechsLeadCancelled`),
  `functions/calendar.php` (tickets source + user feeds), `agent/ajax.php` (calendar_move kind
  `helper`, calendar_day skips `helper-<ticket>-`), `agent/js/calendar.js` (moved: helper branch),
  `agent/calendar.php` (dashed `[data-kind="helper"]` CSS), `agent/tickets.php` (Mine + assigned
  filter via `mitAssignedSql`), `functions/dashboard.php` (dashboardMyTickets), `agent/ticket_list.php`
  + `agent/ticket_kanban.php` (`mitTicketTechsListBadge`), `cron/ticket_email_parser.php` +
  `client/post.php` (`mitTicketTechsCopyMail` after the lead's reply email). Ours: helper clashes
  in `mitScheduleConflicts`, helper bookings in `mitOutlookBookings`, busy-copy skips
  `ticket-<id>-tech-<user>@` invites. A helper who becomes the lead is cleared by `mitTicketTechsTidy()`
  on read (no hooks in ITFlow's four reassign paths). Customer emails stay about the lead's booking only.
- Ticket page layout (Oct 2026): `functions/mit_ticket_layout.php` + `agent/js/mit_ticket_layout.js` (ours) -
  columns (main wide / even / side wider / one / three - an extra `.mit-layout-left` column the
  script adds), box order / column / half width / start collapsed, company default (`mit_settings` ticket_layout, admins) and
  per tech (`mit_user_preferences` ticket.layout). The script moves ITFlow's own boxes in the browser,
  found by their title icon (fa-align-left, fa-sliders-h, fa-user, fa-layer-group, fa-map-marker-alt,
  fa-tasks, fa-link, fa-history), the reply form by `#replyComposer`, the rest of the main column
  wrapped as "conversation" - re-check those if upstream reworks `agent/ticket.php`. Hooks: one line
  after the layout in `agent/ticket.php` (`mitTicketLayoutHtml`), `agent/ajax.php?mit_ticket_layout`,
  `functions.php` (require). "Customize layout" is added to the ticket's ⋮ menu by the script.
- Procurement (Oct 2026, part 1 of 3): `functions/mit_procurement.php` (ours) - items to order
  (`mit_procurement_items`), purchase orders (`mit_purchase_orders`, `mit_po_lines`, `mit_po_tracking`,
  `mit_po_receipts`, `mit_po_history`; M009), approval when over `procurement_approval_over`
  (`mit_settings`, 0 = always), tracking links, receiving (partial, serials), PO PDF (TCPDF) and email
  (attachment in `uploads/mit_po/`). Permission: ITFlow module row `module_procurement` (M009) -
  View 1 / Edit 2 / Full 3 approves + cancels; admins Full. Pages `agent/mit_procurement.php`,
  `mit_purchase_orders.php`, `mit_purchase_order.php`, `mit_receiving.php`; modals
  `agent/modals/mit_procurement/`; handlers `agent/post/mit_procurement.php`. Hooks: one require of
  `agent/includes/mit_procurement_nav.php` in `agent/includes/side_nav.php` (before FINANCE),
  Admin > Settings > Procurement page `admin/mit_procurement_settings.php` (card
  `admin/includes/mit_procurement_card.php`, handler `admin/post/mit_procurement_settings.php`; menu via
  `admin/includes/mit_admin_nav.php`), `functions.php` (require). PO numbers are unique (M015); a PO
  needs approval when over the limit or when a line has no cost - unless approval is switched off (`procurement_approval_on`) -
  approvers are Full Procurement access + admins (roles - `mitPoApprovers`); nobody approves their own PO unless
  they are an admin (logged as self-approved) or the only approver (`mitPoMayApprove`). Draft POs can be deleted at Edit (level 2) on purpose -
  nothing was ordered yet. Daily cron job `mit_procurement_tidy` (`cron/mit_procurement_tidy.php`,
  `mitProcTidy`) cancels waiting items of deleted tickets/clients and frees parts whose invoice line went;
  a deleted ticket's unbilled stock parts go back on the shelf through `mitTicketPartsDeleting` (one line in
  `functions/ticket.php` ticketDeleteForGood and in `agent/post/client.php` client delete) before the
  `mit_ticket_parts` FK cascade removes them.
  Part 2: `functions/mit_procurement_parts.php` (ours, required from mit_procurement.php), M010
  (`mit_ticket_parts`, `products.product_vendor_id`). Quote -> invoice ticks: `mitProcQuoteConvertFields`
  in `agent/modals/quote/quote_to_invoice.php` (+ data-modal-size="lg" on the Invoice button in
  `agent/quote.php`), `mitProcFromQuoteLine` in `agent/post/quote.php` add_quote_to_invoice loop.
  Invoice: `mitProcInvoiceMenuItem` in the `agent/invoice.php` ⋮ menu -> modal
  `agent/modals/mit_procurement/from_invoice.php`. Ticket Parts box: `mitTicketPartsCard` in
  `agent/ticket.php` (side column, above Linked; icon fa-microchip = layout box `parts`), modals
  `part.php` / `parts_bill.php`, handlers `agent/post/mit_procurement_parts.php`. From-stock parts take
  product_stock at once (billed lines have no item_product_id, so ITFlow does not take it again);
  to-order parts become procurement items (source ticket). Procurement > Unbilled Parts (`agent/mit_unbilled_parts.php`, side-menu
  link with a count in `agent/includes/mit_procurement_nav.php`): every part not on an invoice, by client and ticket, with
  Bill parts (`mitUnbilledParts` / `mitUnbilledPartsCount`, sharing `mitTicketPartsWhere` with `mitTicketParts`). Project pickers (item modal, from-invoice, quote convert) use `mitProcProjectField` - the client's open
  projects (client 0 = projects without a client), with a note when there are none; the item modal's list follows its client.
  Product vendor: `mitProductVendorField`
  appended to the product modals' pricing hook line, saved from `mitProductSave`.
  Part 3: `functions/mit_procurement_extras.php` (ours, required from mit_procurement.php), M011
  (`mit_po_receipts.asset_ids`). Receiving (`mitPoReceive` options -> `mitProcAfterReceive`): client
  lines become assets (one per unit, serial, type picked per line on `agent/mit_receiving.php`, linked
  to the ticket for ticket parts; several units named "<line> #n"), stock lines add product_stock, ticket parts get an internal note.
  "Fill in each asset's details" (options[review]) sends Receiving back with `?mit_review_assets=ids&mit_review_total=n`, and
  `agent/mit_receiving.php` opens ITFlow's own `modals/asset/asset_edit.php` for each in turn (the address drops each id as its
  window opens, so ITFlow's save-and-redirect carries on with the rest; Cancel skips one).
  "Record vendor bill" on the PO (`po_action.php?action=bill`, needs Financial edit) inserts an ITFlow
  expense (`mitPoRecordBill`, PO `expense_id`). Hooks: `mitProcInvoiceSummary` + `mitProcInvoiceLineStatus`
  in `agent/invoice.php` (above the items / under each line name), `mitProcProjectCard` in
  `agent/project.php` (left column, under the tabs card).
  Products API: `api/v1/products/read.php` selects `mitProductApiColumns()` (every column but our cost /
  markup) instead of `*` - two lines.
- Timesheets (Oct 2026): `functions/mit_timesheets.php` (ours) - per pay period (semi-monthly default;
  `mit_settings` timesheet_period / timesheet_anchor; weekly follows the week start), ticket time auto from ticket replies (billable by
  `ticket_billable`), hours on charge codes (`mit_charge_codes`: work / lost / leave, paid or not),
  `mit_time_entries`, `mit_timesheets` (open / submitted / returned / approved; approving copies ticket
  time and unaccounted time in as source ticket_billable/ticket_other/auto_lost, code 0 - a 0 auto_lost row on
  the first day marks a period stored this way) - M012, plus module row `module_timesheets`
  (everyone keeps their own; View 1 sees all + report, Edit 2 corrects, Full 3 approves). Pages
  `agent/mit_timesheet.php`, `agent/mit_timesheets.php`, modals `agent/modals/mit_timesheet/`, handlers
  `agent/post/mit_timesheets.php`, report `agent/reports/mit_payroll.php` (CSV summary + detail).
  Admin > Timesheets `admin/mit_timesheets.php` (tabs Settings / Charge codes / People), modals
  `admin/modals/mit_timesheets/`, handlers `admin/post/mit_timesheets.php`. Options (M013): company settings
  `mit_settings` ts_* (`mitTsSettings` - week start, approval on/off, future days, auto-lock, reminders, h:mm,
  rounding, ticket time / internal notes / billable rule (`ts_billable` all - the default - | flag | client, timesheets only, `mitTsBillableSql`), normal week, overtime day/week (weekly overtime
  falls on the day it is worked), auto lost time - unpaid unless ts_auto_lost_paid, never before the hire
  date or the login's creation);
  codes `code_roles` / `code_note_required` / `code_daily_max` / `code_closure_fill`; `mit_timesheet_people`
  (schedule, approver - who can then see, correct and approve them -, employee_id, pay_type: salaried = no
  overtime, keeps_timesheet - M015); personal `mit_user_preferences` timesheet.format / .view / .hidden_codes / .reminders.
  Closure days fill in the closure code on save / submit / approve / cron; a filled day cleared by hand keeps a
  0 row so it is not filled again. The page (Oct 2026 redesign): a week at a time, a row a day, drawn by `agent/js/mit_timesheet.js`
  from JSON built by `functions/mit_timesheet_view.php` (`mitTsViewDays`, `mitTsViewCodes`, `mitTsViewSummary`); clicking a day
  opens a side panel (times, meal answers, hours by code, notes) that saves as it is typed through `agent/mit_timesheet_ajax.php`
  (`save_day` - `mitTsCaSave` + `mitTsSaveCells` for that date, returns the day, summary, leave box, sign problems); a fixed bar
  holds the save state and Submit / Approve. The old form handler (`mit_ts_save`) is kept for submitting and signing.
  Lunch and breaks (M016): charge-code kind `break` (Lunch seeded, unpaid) - logged by people, accounted for (not
  lost) but not worked; paid short breaks are automatic, `mitTsBreakFor` from ts_break_minutes / ts_break_per (or a
  person's `mit_timesheet_people.break_minutes` / `break_per`), counted in paid hours, the total and overtime, not in
  worked / billable %; stored on approval as source `break_paid` (approved periods without them = none).
  Reimbursements (M017): `functions/mit_reimbursements.php` (ours, required from mit_timesheets.php) - mileage
  (makes an ITFlow trip, rate `ts_mileage_rate` kept on the entry) and purchases (receipt in `uploads/mit_receipts/`,
  served by `agent/mit_receipt.php`; optional ticket part via `mitTicketPartSave`, which now also returns the part id)
  in `mit_reimbursements`; by date in the pay period, locked with the timesheet; approving records purchases as ITFlow
  expenses (`mitReimbRecordExpenses`, `ts_reimb_category` / `ts_reimb_account`). Box `mitReimbTicketCard` - one line in
  `agent/ticket.php` under Parts (layout box `reimburse`, fa-receipt) - modal `agent/modals/mit_reimburse/reimburse.php`,
  handlers `agent/post/mit_reimbursements.php`; payroll report columns Miles / Mileage / Purchases / Reimburse.
  California non-exempt (M018): `functions/mit_ts_california.php` (ours, required from mit_timesheets.php) - per person
  `mit_timesheet_people.ca_rules` (NULL = on for hourly; People modal), from `mit_settings` ts_ca_start on (set to the day M018
  ran - earlier days and periods keep their old hours; `mitTsCaOn($user_id, $date)`). Typed start / meal / end times per day
  (`mit_ts_work_periods`, gaps = meal periods, never rounded) are the hours worked - codes and ticket time only allocate them
  (`unallocated` = paid work on no code); no auto lost time, no unpaid work codes, no auto paid breaks, break-kind codes hidden.
  Meal rules (`mitTsCaAnalyze`: 30 min by end of hour 5 / 10), the person's answer per missed/late/short meal and
  "could not take a rest break" (`mit_ts_days`) -> meal / rest premium hours (unanswered = owed); CA overtime
  (`mitTsCaOvertime`: >8/day, >12 double, >40 regular/week, 7th consecutive day). Changes to times (`mit_ts_changes`) need a
  reason from anyone but the person, who acknowledges them (`?mit_ca_acknowledge=`). Submit = signed attestation
  (`mit_ts_attestations`: statement text + version, typed name, IP, UA, breaks answer / exceptions, JSON snapshot) via the
  window `mitTsCaSignModal`; `mitTsSubmit` / approve refuse without one; withdraw / send back / reopen set it aside
  (`mitTsCaSupersede`). Hooks in mit_timesheets.php: mitTsDays, mitTsDayWorked, mitTsTotals (double, premiums), mitTsSubmit,
  mitTsWithdraw, mitTsDecide, mitTsReopen. UI: the day panel of `agent/js/mit_timesheet.js` (Work times section, same meal rules
  in JS), `agent/mit_timesheet.php` (sign window, changes box), decide modal (`mitTsCaApproverNote`), payroll report (Double time, Meal / Rest premium
  columns; detail "Work times" rows). No FKs on the M018 tables: records outlive deleted logins (keep 3 years).
  Cron job `mit_timesheets` (`cron/mit_timesheets.php`, entry in `includes/cron_jobs.php`, hourly: closure days
  of the period just ended, reminders - `mit_timesheets.reminded_due_at` / `reminded_late_at` -, leave earned
  when approval is off (once the period has settled: auto-lock days, else 7), leave year ends - carry-over
  worked out again for the first 60 days). A charge code belongs to one leave bank at most.
  Leave balances (M014): `functions/mit_leave.php` (ours, required from mit_timesheets.php) - banks
  (`mit_leave_banks`: codes that use it up, earned per period / per hour worked / yearly / not, max, carry-over
  keep all / cap / lose, year from 1 Jan or hire anniversary, waiting days, below 0 allowed), ledger
  (`mit_leave_ledger`, unique person+bank+kind+ref for automatic rows), per-person rate / left out
  (`mit_leave_rates`), `mit_timesheet_people.hire_date`. Balance = ledger - hours on its codes. Period earnings
  added in `mitTsDecide` approve (`mitLeaveAccruePeriod`), removed in `mitTsReopen`; save checks
  `mitLeaveCheckSave`. UI: balances box on the timesheet (`mitLeaveTimesheetBox`, history modal
  `agent/modals/mit_timesheet/leave.php`), Admin > Timesheets > Leave tab (`admin/modals/mit_timesheets/leave_bank.php`)
  and People tab (`person_leave.php`: rates, adjustments, history); "<bank> left" columns in the payroll report. Hooks: one line in `functions/reports.php`
  reportCatalog (`'mit_payroll' => mitTsReportEntry()` after time_by_tech), require of
  `agent/includes/mit_timesheet_nav.php` in `agent/includes/side_nav.php` (its own TIME section, before TOOLS),
  require of `admin/includes/mit_admin_nav.php` in `admin/includes/side_nav.php` (inside SETTINGS after Ticket,
  plus `array_merge(..., mitAdminSettingsPages())` on the SETTINGS menu-open line), Account > Preferences
  (`mitTsPreferenceFields` in `agent/user/user_preferences.php`, `mitTsSavePreferences` in
  `agent/user/post/profile.php`), `functions.php` (require).
- Dispatch (Oct 2026): `functions/mit_dispatch.php` (ours) - page `agent/mit_dispatch.php`, script
  `agent/js/mit_dispatch.js` (Day: one FullCalendar v7 timeGridDay per tech - v7 options, e.g. `slotHeaderContent`,
  and hashed class names, so style by data- attributes; Week: techs x days table with SortableJS), requests
  `agent/mit_dispatch_ajax.php` (queue, items, book, unbook, state). Queue: open tickets with no tech / with a tech
  and nothing booked. Book = `ticketChangeAssignee` + `ticketSetSchedule`; unbook = `ticketCancelSchedule`.
  The Schedule dialog also shows the ticket's client, contact, priority, status, category and SLA (`?details=` /
  `?contacts=`); changes are saved first by `mitDispatchSaveDetails` - the same rules as ITFlow's Edit ticket,
  Change client (`change_client_ticket`, public replies become internal) and SLA forms; re-check it if those change.
  Under Contact it lists the contact's other open tickets (`?contact_tickets=`) with "Merge into this" / "Merge this into it"
  (`mitDispatchMerge`: ITFlow's Merge form checks, a scheduled ticket being merged has its schedule cancelled, then ITFlow's
  own `ticketMergeInto()`, which lives in `agent/post/ticket.php` and is loaded from there - re-check if upstream moves it).
  Needs Support edit (`calendarCanScheduleTickets`). Settings per agent in `mit_user_preferences` dispatch.state.
  Hooks: require of `agent/includes/mit_dispatch_nav.php` in `agent/includes/side_nav.php` (SUPPORT, after
  Recurring Tickets), `functions.php` (require), and one condition in `functions/ticket.php` ticketMailSchedule
  (`empty($GLOBALS['mit_schedule_skip_contact'])` - the "Email the contact" tick; a global rather than a new
  parameter so ITFlow's function signature stays as it is). Working hours on the board come from Admin > SLA
  (`mitDispatchBusinessHours`). Needs ticketing on and the Dispatch switch on.
- Remembered list views (Oct 2026): `functions/mit_list_views.php` (ours) - the Tickets list keeps each agent's
  pill, filters, sort and List / Board in `mit_user_preferences` `listview.tickets` (like the calendar) and puts
  them back when Tickets is opened with none; saved only when picked on the page itself (Referer), never q / page /
  open, not in a client's ticket list. Hooks in `agent/tickets.php`: one line after `enforceUserPermission`
  (`mitListViewRestore('tickets')`, then filter_header.php runs again so sort / order / dates follow) and
  `mitListViewResetButton('tickets')` before the List / Board group (`?mit_reset_view=1`). `functions.php` (require).
- Cron health (Oct 2026): `functions/mit_cron_health.php` (ours) reads ITFlow's own `cron_jobs` / `config_cron_last_dispatch_at`
  (`mitCronProblems`: not running > 5 min, switched off, a job Failed, stuck Running > 2 h, or late - interval jobs past two
  intervals (min +10 min), daily ones past 26 h). Shown as a warning next to the bell for admins (`includes/mit_cron_nav.php`,
  one require in `includes/top_nav.php` after the timers); emailed by cron job `mit_cron_watch` (`cron/mit_cron_watch.php`,
  entry in `includes/cron_jobs.php`, every 5 min: new / daily reminder / fixed, state in `mit_settings` cron_alert_state;
  cron_alert_email, cron_alert_to - empty = active admins); `/mit_health.php?key=` (ours, web root, `cron_health_key`) answers
  200 OK / 503 PROBLEM for an outside uptime monitor - the only alert when cron itself stops. Settings card
  `admin/includes/mit_cron_card.php` (one require at the end of `admin/cron.php`), handlers `admin/post/mit_cron_health.php`.
- Review fixes (Oct 2026): `functions/mit_common.php` (ours, required first in `functions.php`) - shared
  helpers: `mitMailBody` / `mitSqlHtml` (HTML for SQL without escapeSql's strip_tags), `mitProductIsTangible`,
  module switches (`mitModuleOn`, `mitModuleRequire` for pages/modals, `mitModuleGuard` for handler files,
  `mitModulesSave`), `mitAdminSettingsPages`. Module switches on Admin > Settings > Modules: require of
  `admin/includes/mit_modules_fields.php` in `admin/settings_module.php` (inside ITFlow's form) and
  `mitModulesSave($_POST)` in `admin/post/settings_module.php`; saved as `mit_settings` module_procurement /
  module_timesheets / module_dispatch (default on). Procurement also needs invoicing on, Dispatch ticketing.
  Secrets: `$config_mit_secret_key` (appended to config.php on first use, like the backup key) - `mitEncryptSecret`
  writes v2 (AES-256-GCM), v1 values (key from `$installation_id`) still read and upgraded by `mitUpgradeSecrets`.
  Calendar: when the schedule modal or Dispatch gives a scheduled ticket a new lead, `mitScheduleLeadChanging`
  (one line in `agent/post/ticket.php` edit_ticket_schedule) cancels the old lead's invite; `dashboardSchedule`
  in `functions/dashboard.php` adds `mitTicketTechDashboardItems` (tickets you help on); helpers must have access
  to the ticket's client (`mitAgentCanSeeClient`). `cron/nightly_tasks.php` keeps upstream's column list plus
  `mitRecurringTicketByDayColumn()`. Restores (`setup/index.php` restore, `scripts/restore_cli.php`) run our
  database updates afterwards, so a stock ITFlow backup gets `ticket_schedule_end` etc.; setup shows a failed
  update. Our runner (`admin/database_updates_mountaineerit.php`) has `mitColumnExists` / `mitIndexExists`.
  Our features check their own update with a `...Ready()` function (one cached query per request) and fall back
  quietly; `function_exists()` guards in ITFlow files are kept on purpose so a half-merged checkout still loads.
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
   changed PHP files, run `scripts/mit_portability_check.php` and the smoke tests (`tests/smoke`)
   on the test install after the database update, then ask the user before pushing to `develop`.

## "Promote to production"
Open a PR `develop` → `MountaineerIT` listing what's included and any DB updates, plus the
result of the stock-ITFlow portability test (see Rules) and of the smoke tests. Ask the user before merging.
