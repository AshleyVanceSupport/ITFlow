# Mountaineer IT build of ITFlow - release notes

Version = ITFlow's release + our iteration (26.10.1, 26.10.2 ...). It goes up with every push to `develop` and starts
again at .1 when an ITFlow release is merged. Newest first. Shown under Admin > Update. (ITFlow's own release notes are
CHANGELOG.md.) Versions up to 26.10.62 were numbered afterwards, one per push to develop since 15 October 2026.

## 26.10.65 - 2026-10-19
- Internal notes: a "Notify" line to ping techs about the note - they get a bell notification and an email with the note and a link to the ticket. A reply to that email from their own address is added to the ticket as an internal note.

## 26.10.64 - 2026-10-19
- Email reply: who it goes to is now one "To" line - names as chips (no email addresses for people in ITFlow), type to find a contact, watcher or someone on our team, or type a new address.

## 26.10.63 - 2026-10-19
- Version numbers: our build is ITFlow's release + an iteration, shown in the admin footer and on Admin > Update.
- Release notes for every update so far (this list), shown under Admin > Update.

## 26.10.62 - 2026-10-19
- Tickets: pick who an email reply goes to (M021)

## 26.10.61 - 2026-10-19
- Dispatch: edit the ticket title in the Schedule dialog

## 26.10.60 - 2026-10-19
- Ticket page: Read tick on the description keeps it folded away

## 26.10.59 - 2026-10-19
- Calendar: project ticket bookings follow the Projects tick box

## 26.10.58 - 2026-10-19
- Project tickets kept apart: off the Tickets board, no SLA, own calendar color

## 26.10.57 - 2026-10-18
- NinjaOne page: header and tabs like the other admin pages

## 26.10.56 - 2026-10-18
- NinjaOne moves to Admin > Integrations

## 26.10.55 - 2026-10-18
- CLAUDE.md: notes for merging ITFlow 26.11 (product column clash, overlaps)
- NinjaOne RMM: device sync to assets (replaces the n8n workflow), org mapping with n8n import, NinjaOne buttons with live status on tickets and assets (M019)

## 26.10.54 - 2026-10-18
- Timesheets: who is behind with Remind buttons, Send back on the team list, send-back emails

## 26.10.53 - 2026-10-18
- Security review fixes; end-to-end smoke tests for our features

## 26.10.52 - 2026-10-18
- Dispatch: This week dashboard - inbound vs closed, SLA results, assigned vs closed by agent

## 26.10.51 - 2026-10-18
- Dispatch: Workload view of each tech's open tickets, SLA and booked hours

## 26.10.50 - 2026-10-18
- Our scripts load with their file time on the address, so browsers pick up updates

## 26.10.49 - 2026-10-18
- Ticket reply box starts open on Public note

## 26.10.48 - 2026-10-18
- Procurement settings: switch approval on or off, and choose who approves
- Procurement settings: drop 'Who can approve' - approvers come from roles
- Procurement only for roles with the Procurement permission
- Ticket Parts box for everyone on the ticket; the rest of Procurement stays with the role
- Unbilled Parts moves to Billing and follows the Sales permission

## 26.10.47 - 2026-10-18
- Procurement > Unbilled Parts: every ticket part not billed yet, in one place

## 26.10.46 - 2026-10-18
- Timesheet: the signing window could not be clicked

## 26.10.45 - 2026-10-18
- My timesheet: all time on tickets shows as one 'Ticket time'
- Timesheets: 'All ticket time' counts as billable (the new default)

## 26.10.44 - 2026-10-18
- Receiving: open the standard asset window for each new client asset
- Smoke test: look for a PO among all of them, not only open ones
- Receiving: 'Make client items assets' and 'Add stock items to stock' start unticked

## 26.10.43 - 2026-10-18
- Purchase orders: admins can approve their own; action links are buttons again

## 26.10.42 - 2026-10-17
- Timesheet page redesign: a week at a time, a row a day, a side panel that saves as you type

## 26.10.41 - 2026-10-17
- Timesheets: California non-exempt time records, meal premiums, CA overtime and signed attestation
- California timesheet rules apply from the day the update runs

## 26.10.40 - 2026-10-17
- Procurement: project picker follows the client and says why it is empty

## 26.10.39 - 2026-10-17
- Cron health: top-bar warning for admins, alert emails, health address for an uptime monitor

## 26.10.38 - 2026-10-17
- Add Playwright smoke tests (tests/smoke) for the test install

## 26.10.37 - 2026-10-17
- Tickets list remembers each agent's filters, with a Reset view button

## 26.10.36 - 2026-10-17
- Dispatch: set the ticket's status in the Schedule dialog
- Dispatch: show the contact's other open tickets, and merge them

## 26.10.35 - 2026-10-17
- Reimbursements: mileage and purchases logged on tickets, paid with payroll

## 26.10.34 - 2026-10-17
- Timesheets: lunch and paid breaks

## 26.10.33 - 2026-10-17
- Dispatch: check the ticket's details while scheduling it

## 26.10.32 - 2026-10-17
- Dispatch: keep every agent column's hours full height
- Dispatch: queue and board are the same height in day and week

## 26.10.31 - 2026-10-17
- Review of our additions: fixes, permissions, module switches, menus, docs

## 26.10.30 - 2026-10-17
- Dispatch board: tech columns by day, techs by week, and the queue

## 26.10.29 - 2026-10-17
- Timesheets: charge codes, pay-period timesheets with approval, payroll report
- Timesheet options: settings, people, code rules, overtime, reminders
- PTO and sick leave balances with configurable accrual

## 26.10.28 - 2026-10-17
- Procurement part 3: receiving extras, vendor bill as expense, order status

## 26.10.27 - 2026-10-17
- Procurement part 2: quote, invoice and ticket parts into procurement

## 26.10.26 - 2026-10-17
- Procurement part 1: items to order, purchase orders with approval, receiving

## 26.10.25 - 2026-10-17
- Ticket layout: column widths, a third column, half-width boxes

## 26.10.24 - 2026-10-17
- Tickets: customisable page layout - box order, column, start collapsed

## 26.10.23 - 2026-10-17
- Tickets: additional techs, each with the lead's time or their own
- Additional techs: a helper who becomes the lead stops being a helper

## 26.10.22 - 2026-10-17
- Calendar colour moves to Account > Preferences; dots in the Whose list

## 26.10.21 - 2026-10-17
- Quotes: add an image from a link on the add-item row too
- Quotes: line the quote page up - profit box under the totals

## 26.10.20 - 2026-10-17
- Quotes: our cost with markup / margin pricing, and an image per line

## 26.10.19 - 2026-10-16
- Repeat by weekday: "the third Wednesday", "the last Friday"

## 26.10.18 - 2026-10-16
- Cron and code interplay: fixes from a review of everything around our changes

## 26.10.17 - 2026-10-16
- Keep this build portable to stock ITFlow, and check it

## 26.10.16 - 2026-10-16
- Ticket timers in the top bar, next to the bell

## 26.10.15 - 2026-10-16
- Microsoft 365 mail and Outlook: fixes from a full review

## 26.10.14 - 2026-10-16
- Outlook busy times on the ITFlow calendar and in booking overlaps

## 26.10.13 - 2026-10-16
- Outlook calendar per tech: booked tickets show in each tech's Outlook

## 26.10.12 - 2026-10-16
- Microsoft 365: let a non-admin mailbox connect to ITFlow's own app

## 26.10.11 - 2026-10-16
- Microsoft 365: send and receive mail through Microsoft Graph

## 26.10.10 - 2026-10-16
- Microsoft 365: ITFlow creates its own app in the tenant

## 26.10.9 - 2026-10-16
- Calendar: show the Techs list (and your colour dot) even when there is only one tech

## 26.10.8 - 2026-10-16
- Calendar: each tech can pick their own calendar colour

## 26.10.7 - 2026-10-16
- Calendar: remember the view, whose calendar and ticked calendars on the agent's account (M002)
- Calendar settings: keep the ticked sources an object when empty; CLAUDE.md note

## 26.10.6 - 2026-10-16
- Add Mountaineer IT database update track (M001: ticket schedule end)
- Calendar: each tech's colour and calendar, book a block of time from the ticket
- Calendar: spacing above the Techs legend; CLAUDE.md records the DB update scheme and calendar hooks

## 26.10.5 - 2026-10-16
- Merged unreleased ITFlow 26.11 work (upstream develop) early, for the new ticket page.
- CLAUDE.md: note the early merge of upstream develop and hold production promotion until 26.11

## 26.10.4 - 2026-10-16
- Portal: stop contacts editing the primary contact or granting themselves billing access
- Security: saved-card client scope, date-filter XSS, API client checks
- API: stop ticket updates wiping the vendor ticket number
- Billing: recurring force/discount, recurring expense dates, payments, autopay
- Billing: contract rates, time rounding, time reports, quote due date, stock
- Fix broken features: guest payment errors, task rows, modules, setup, restore
- Installer: /etc/cron.d, upstream-style short options, --ssl none; drop welcome workflow

## 26.10.3 - 2026-10-15
- install.sh: fall back to su when runuser is not available

## 26.10.2 - 2026-10-15
- install.sh: set a full PATH so runuser/a2enmod are found after plain 'su'

## 26.10.1 - 2026-10-15
- Set up Mountaineer IT deployment: MountaineerIT as release branch
- CLAUDE.md: confirm with the user before every push or merge
- DEPLOYMENT.md: fix install steps and beta auto-update from review
- Add one-line Debian installer (scripts/install.sh)
- Docs: commit directly to develop; PRs only for develop -> MountaineerIT
