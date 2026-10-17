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
  (calendar_move), `agent/calendar.php` + `agent/js/calendar.js` (colour dots in Whose, opens on me),
  `agent/modals/ticket/ticket_edit_schedule.php` + `agent/js/ticket_schedule_modal.js`
  (Tech + How long), `agent/post/ticket.php` (edit_ticket_schedule), `agent/ticket.php`
  (start - end wording), `functions.php` (require), `scripts/update_cli.php`, `setup/index.php`,
  `scripts/setup_cli.php` (run our DB updates). Re-apply them if an upstream merge drops them.
  Calendar settings (view, whose, ticked) are also saved per user in `mit_user_preferences`
  (M002) via `ajax.php?mit_calendar_state` and loaded by `agent/calendar.php` (data-saved-state).
  Each tech picks their calendar colour under Account > Preferences (`agent/user/user_preferences.php`
  + `agent/user/post/profile.php`); admins can set anyone's in Admin > Users > Edit
  (`admin/modals/user/user_form.php` + `admin/post/users.php` edit_user) - one-line hooks,
  `mitCalendarColorField()` / `mitSaveAgentColor()`, saved as the `calendar.color` preference;
  `mitAgentColor()` falls back to the automatic colour.
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
  `functions.php` (require). "Customise layout" is added to the ticket's ⋮ menu by the script.
- Procurement (Oct 2026, part 1 of 3): `functions/mit_procurement.php` (ours) - items to order
  (`mit_procurement_items`), purchase orders (`mit_purchase_orders`, `mit_po_lines`, `mit_po_tracking`,
  `mit_po_receipts`, `mit_po_history`; M009), approval when over `procurement_approval_over`
  (`mit_settings`, 0 = always), tracking links, receiving (partial, serials), PO PDF (TCPDF) and email
  (attachment in `uploads/mit_po/`). Permission: ITFlow module row `module_procurement` (M009) -
  View 1 / Edit 2 / Full 3 approves + cancels; admins Full. Pages `agent/mit_procurement.php`,
  `mit_purchase_orders.php`, `mit_purchase_order.php`, `mit_receiving.php`; modals
  `agent/modals/mit_procurement/`; handlers `agent/post/mit_procurement.php`. Hooks: one require of
  `agent/includes/mit_procurement_nav.php` in `agent/includes/side_nav.php` (before FINANCE),
  settings card `admin/includes/mit_procurement_card.php` at the end of `admin/settings_invoice.php`
  (handler `admin/post/mit_procurement_settings.php`), `functions.php` (require).
  Part 2: `functions/mit_procurement_parts.php` (ours, required from mit_procurement.php), M010
  (`mit_ticket_parts`, `products.product_vendor_id`). Quote -> invoice ticks: `mitProcQuoteConvertFields`
  in `agent/modals/quote/quote_to_invoice.php` (+ data-modal-size="lg" on the Invoice button in
  `agent/quote.php`), `mitProcFromQuoteLine` in `agent/post/quote.php` add_quote_to_invoice loop.
  Invoice: `mitProcInvoiceMenuItem` in the `agent/invoice.php` ⋮ menu -> modal
  `agent/modals/mit_procurement/from_invoice.php`. Ticket Parts box: `mitTicketPartsCard` in
  `agent/ticket.php` (side column, above Linked; icon fa-microchip = layout box `parts`), modals
  `part.php` / `parts_bill.php`, handlers `agent/post/mit_procurement_parts.php`. From-stock parts take
  product_stock at once (billed lines have no item_product_id, so ITFlow does not take it again);
  to-order parts become procurement items (source ticket). Product vendor: `mitProductVendorField`
  appended to the product modals' pricing hook line, saved from `mitProductSave`.
  Part 3: `functions/mit_procurement_extras.php` (ours, required from mit_procurement.php), M011
  (`mit_po_receipts.asset_ids`). Receiving (`mitPoReceive` options -> `mitProcAfterReceive`): client
  lines become assets (one per unit, serial, type picked per line on `agent/mit_receiving.php`, linked
  to the ticket for ticket parts), stock lines add product_stock, ticket parts get an internal note.
  "Record vendor bill" on the PO (`po_action.php?action=bill`, needs Financial edit) inserts an ITFlow
  expense (`mitPoRecordBill`, PO `expense_id`). Hooks: `mitProcInvoiceSummary` + `mitProcInvoiceLineStatus`
  in `agent/invoice.php` (above the items / under each line name), `mitProcProjectCard` in
  `agent/project.php` (left column, under the tabs card).
- Timesheets (Oct 2026): `functions/mit_timesheets.php` (ours) - per pay period (semi-monthly default;
  `mit_settings` timesheet_period / timesheet_anchor; weekly follows the week start), ticket time auto from ticket replies (billable by
  `ticket_billable`), hours on charge codes (`mit_charge_codes`: work / lost / leave, paid or not),
  `mit_time_entries`, `mit_timesheets` (open / submitted / returned / approved; approving copies ticket
  time in as source ticket_billable/ticket_other, code 0) - M012, plus module row `module_timesheets`
  (everyone keeps their own; View 1 sees all + report, Edit 2 corrects, Full 3 approves). Pages
  `agent/mit_timesheet.php`, `agent/mit_timesheets.php`, modals `agent/modals/mit_timesheet/`, handlers
  `agent/post/mit_timesheets.php`, report `agent/reports/mit_payroll.php` (CSV summary + detail).
  Admin > Timesheets `admin/mit_timesheets.php` (tabs Settings / Charge codes / People), modals
  `admin/modals/mit_timesheets/`, handlers `admin/post/mit_timesheets.php`. Options (M013): company settings
  `mit_settings` ts_* (`mitTsSettings` - week start, approval on/off, future days, auto-lock, reminders, h:mm,
  rounding, ticket time / internal notes / billable rule, normal week, overtime day/week, auto lost time);
  codes `code_roles` / `code_note_required` / `code_daily_max` / `code_closure_fill`; `mit_timesheet_people`
  (schedule, approver - who can then see, correct and approve them -, employee_id, pay_type: salaried = no
  overtime); personal `mit_user_preferences` timesheet.format / .view / .hidden_codes / .reminders.
  Cron job `mit_timesheets` (`cron/mit_timesheets.php`, entry in `includes/cron_jobs.php`, hourly: reminders -
  `mit_timesheets.reminded_due_at` / `reminded_late_at` -, leave earned when approval is off, leave year ends).
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
  `agent/includes/mit_timesheet_nav.php` in `agent/includes/side_nav.php` (under Calendar), require of
  `admin/includes/mit_admin_nav.php` in `admin/includes/side_nav.php` (after Categories), Account > Preferences
  (`mitTsPreferenceFields` in `agent/user/user_preferences.php`, `mitTsSavePreferences` in
  `agent/user/post/profile.php`), `functions.php` (require).
- Dispatch (Oct 2026): `functions/mit_dispatch.php` (ours) - page `agent/mit_dispatch.php`, script
  `agent/js/mit_dispatch.js` (Day: one FullCalendar v7 timeGridDay per tech - v7 options, e.g. `slotHeaderContent`,
  and hashed class names, so style by data- attributes; Week: techs x days table with SortableJS), requests
  `agent/mit_dispatch_ajax.php` (queue, items, book, unbook, state). Queue: open tickets with no tech / with a tech
  and nothing booked. Book = `ticketChangeAssignee` + `ticketSetSchedule`; unbook = `ticketCancelSchedule`.
  Needs Support edit (`calendarCanScheduleTickets`). Settings per agent in `mit_user_preferences` dispatch.state.
  Hooks: require of `agent/includes/mit_dispatch_nav.php` in `agent/includes/side_nav.php` (under Calendar),
  `functions.php` (require), and one condition in `functions/ticket.php` ticketMailSchedule
  (`empty($GLOBALS['mit_schedule_skip_contact'])` - the "Email the contact" tick).
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
