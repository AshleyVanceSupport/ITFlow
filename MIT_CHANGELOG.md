# Mountaineer IT build of ITFlow - release notes

Version = ITFlow's release + our iteration (26.10.1, 26.10.2 ...). It goes up with every push to `develop` and starts
again at .1 when an ITFlow release is merged. Newest first. Shown under Admin > Update. (ITFlow's own release notes are
CHANGELOG.md.) Versions up to 26.10.62 were numbered afterwards, one per push to develop since 15 October 2026.

## 26.10.89 - 2026-10-20
- Ticket notes now record when the work was done: Date worked, Start and End sit under the time worked in the reply box. They follow the timer (ending now) until you type them; typing them sets the length. A note with time can't be sent without them, and they show on the note ("Tue Oct 20, 9:10 - 9:40 AM"). Edit reply has them too - change only the length and the start moves.
- Timesheets count ticket time on the day it was worked, not the day the note was posted. The day panel lists each ticket entry with its times, and warns when two overlap or (on California / time-clock days) when one falls outside your clocked times.
- Timesheets: a new Contractor pay type - no overtime and never under the California rules (even if switched on for them); they still keep a timesheet. The People list shows who is on California rules.
- Timesheets: automatic lost time now shows as a row with the charge codes in the day panel, going down as you add time, so it is clear what the day is charged with.

## 26.10.88 - 2026-10-20
- Dispatch Week view: the agent names stay on the left while the days scroll sideways, and each day is kept to a set width - long titles are cut short (hover for the whole title) instead of stretching one day across the screen.

## 26.10.87 - 2026-10-20
- Dispatch: each agent's column (Day and Week) now also shows the rest of their day - calendar meetings and events they organise or are invited to, closure days (holidays) and their Outlook busy times - so tickets can be booked around them. Only tickets can be moved there; click a meeting to open it.
- Dispatch: bookings show the onsite (car) or remote (headset) icon, as on the calendar page, with where / who underneath in the Day view.
- Booking a ticket over an agent's meeting now warns about the clash, like overlapping tickets and Outlook appointments already did.

## 26.10.86 - 2026-10-20
- Dispatch: a third queue tab, "Left behind" - every agent's open tickets booked on a day that has gone, oldest first, with the day they were booked. Drag one onto an agent and time to book it again.

## 26.10.85 - 2026-10-20
- Calendar: a "Left behind" list under To schedule - open tickets booked on a day that has gone without being done or moved on. Drag one onto the calendar to book it again (it keeps its length), or click it for a quick look; its old booking is outlined in red on the calendar.
- Calendar: To schedule and Left behind both follow whose calendar you are viewing - on "Everyone", To schedule now lists everyone's unbooked tickets (with each agent's name) instead of only yours.

## 26.10.84 - 2026-10-20
- Tickets: the List / Board buttons are now List / Kanban, and the kanban view's first tab is "Active" (was "On the board") - so "board" on the Tickets page only means a service board (Service Desk, Tier 1 ...).

## 26.10.83 - 2026-10-20
- Service boards, part 2 - round-robin: a board can hand its new tickets to its members in turn (Admin > Settings > Boards > Edit > Auto-assign). It skips anyone left out of the turn, on leave today (timesheets), Out of office in Outlook right now, who can't see the client, or who already holds the board's limit of open tickets. Tickets that arrive after working hours wait for the day to start. Only tickets placed on the board after it is turned on are handed out - never the ones already waiting.
- Dashboard: a Board picker beside the greeting (remembered) - Needs attention, Open tickets by tech and the Opened and resolved chart follow it - and a "Your boards" strip with each of your boards' open, unassigned and past-SLA tickets.
- New report, Board Performance (Reports > Service Desk): for each board, new tickets, moved in, escalated on, resolved, the typical first reply and time to resolve, SLAs met and what is open now; where escalations go; and each agent on one board. Exports to CSV.
- Reports: the Export button is visible again with the modern look.

## 26.10.82 - 2026-10-20
- Service boards: tickets now sit on a board - Service Desk (where new tickets land), Tier 1, Tier 2, Sysadmin and Sales to start with - separate from their category. Admin > Settings > Boards adds, edits, orders and archives boards and picks each board's members (and who of them gets told about new and moved tickets).
- Routing: new tickets go on a board by the address the email was sent to, then by client, then by category, else the default board - set under Admin > Settings > Boards > Routing. "Place open tickets by category" moves today's open tickets to their category's board once.
- Tickets: a board switcher above the list (with open counts, remembered like your other list settings) and the board on each row. New Ticket has a Board choice (Automatic follows the routing rules).
- Ticket page: a Board line (click to move it) and "Escalate to another board" in the ⋮ menu - a reason is required and kept as an internal note, the ticket is unassigned by default, and the board's members are told.
- Dispatch: pick All boards, My boards (the default when you are on one) or a single board - the queue, Workload and This week follow it; queue cards show their board, the Schedule dialog can move a ticket to another board, and "Only the board's members" shows just that board's agents. The Dispatch count in the menu counts your own boards.

## 26.10.81 - 2026-10-20
- Admin > Settings > Theme: the colour names now show properly even where the browser kept an old copy of ITFlow's stylesheet (no refresh needed with the modern look).

## 26.10.80 - 2026-10-20
- Modern look follows the company theme colour again (Admin > Settings > Theme): the menu's current page, buttons, tabs, links and a line along the top bar take the colour - with dark text on yellow, and very dark colours lifted in dark mode so they still show.
- Admin > Settings > Theme: the colour names under each preview were squeezed to a letter or two per line - fixed.

## 26.10.79 - 2026-10-20
- Modern look: the Financial boxes on a client's overview (Balance, Paid this year, Recurring, Not yet invoiced, Terms) are readable again - label, amount on one line and the note under it.

## 26.10.78 - 2026-10-20
- Quotes, invoices and recurring invoices look like a document: a white sheet with the buttons in a strip above it, a bold blue QUOTE / INVOICE title, the number and dates in a neat box, the customer in a card with a blue edge, line items with a tinted heading, the add-a-line row set apart, a softer "Our cost" column and the Total in a blue band.
- The broken "Company logo" picture on quotes and invoices is hidden when no logo is uploaded.

## 26.10.77 - 2026-10-20
- New sign-in screen: a dark navy background with soft blue glows, a faint grid and network lines, and a white rounded sign-in box (also the client portal's password reset). An uploaded company logo sits on a white plate so it still shows.

## 26.10.76 - 2026-10-20
- Modern look: the "Needs attention" boxes on the dashboard and client overview, and a client's count tiles, are now compact one-line tiles (icon, label, number on the right) in even columns - about a third of the height.

## 26.10.75 - 2026-10-20
- Modern look, part 2: it now works in Dark mode too (dark navy panels, same blue).
- A client's Assets, Licenses and Tickets pages open with count tiles - warranty expired or ending, devices over 5 years old, offline in NinjaOne, licenses expired or renewing, open / overdue / unassigned tickets, closed in the last 30 days and time logged this month. Click a tile to see the list it counts.
- The client header shows the primary contact, phone, email and website.
- Charts use the same softer colours.

## 26.10.74 - 2026-10-20
- New modern look: a slate side menu with a blue pill on the page you're on, a white top bar, soft grey rounded panels, count tiles with pastel icons, tinted status pills and the Inter font. On for everyone; go back to ITFlow's look under Account > Preferences > Look > Classic. It's light only - the Dark theme keeps ITFlow's dark look.

## 26.10.73 - 2026-10-20
- Security: changing a password (yourself, an admin, or the "reset all passwords" button) or turning MFA on or off now signs that person out everywhere else on their next click - also client portal logins.
- API: send the key in an `X-API-Key` header (or `Authorization: Bearer`); keys in the address (`?api_key=`) are refused, since they end up in server logs. Update n8n and any scripts that used the address.
- Security headers on every page (no MIME sniffing, a same-site referrer, no framing by other sites, HTTPS remembered), and files opened straight from uploads can't run scripts.
- Outlook calendars can get their own calendar-only Microsoft app (Admin > Settings > Mail, one Global Admin sign-in): staff Outlook sign-ins then can't reach their mail. Everyone who connected Outlook reconnects once. You're warned when the Outlook account isn't your ITFlow email.
- Client portal: sign-ins end when the client or contact is archived or the portal is switched off; Microsoft sign-in only from the client's own Microsoft 365 organization; paying with a saved card can't charge twice.
- Emails from someone who isn't a contact, watcher or agent but quote a ticket number are kept as an internal note without reopening the ticket or adding their attachments; bounces no longer get an automatic "ticket closed" reply.
- Moving a ticket to another client takes off the old client's watchers and remembered email recipients.
- Schedule clashes for clients you can't see show as "Another booking"; the Schedule window only offers agents who can see the client.
- Trips need Financial edit access, and trips made by reimbursements are changed on the ticket; moving expenses to another client checks their current client too.
- The last admin role can't lose admin; the AI summary is cleaned before it's shown; the API keeps an asset's or contact's links within its own client.
- Fixes: the API reply endpoint (closed tickets, bad statuses), API limits and the software lookup; lists no longer error on page 0 or an unknown sort; a code of 000000 now works for MFA; guest feedback; quote accept / decline email links; opening the site address while signed in to the portal; tickets with your client's asset only from the portal.
- Reimbursements: amounts are checked properly and receipts are limited to 20 MB; the old Microsoft certificate is taken off the apps after a renewal.

## 26.10.72 - 2026-10-20
- Security: a link in an emailed ticket could load another website's page into ITFlow and run it as the agent who clicked it - pop-ups now only open ITFlow's own pages, and ticket text can't carry the pop-up marker.
- With Microsoft 365 (or Google) mail, portal "Forgot password?", payment receipts, quote accept / decline emails, new-user emails, share-link emails and the "your account was changed" security emails were silently skipped - they are sent again.
- Microsoft 365 mail reading no longer stops when about 200 unprocessed messages pile up in the inbox, and a failed mailbox read (or a mail server refusing the connection) now shows as a failed job, so cron alerts report it.
- Client portal: only the primary contact can give someone a portal login or change a login's email or sign-in method (another contact could take over a billing contact's login); files on internal notes can no longer be downloaded from the portal; attachments on closed tickets download again.
- Installer: setup runs before the site goes live (the setup page was briefly open to the internet); "behind a reverse proxy" mode keeps secure cookies and sees each visitor's real address (one proxy address could lock everyone out).
- Autopay now charges invoices that are partly paid.
- Reimbursed purchases: changing or removing one after a reopen updates or removes its expense; with timesheet approval off they are recorded as expenses once the period settles.
- Two people (or a double click) approving a timesheet, recording a vendor bill or putting the same items on a PO at once can no longer double up.
- NinjaOne: an asset you delete in ITFlow is no longer made again by the next sync (it is reported instead); a device whose asset is under another client keeps its link; an unknown online status no longer shows as Online.
- Outlook: events of bookings older than 30 days are no longer removed from techs' calendars.
- Cron alerts also report a job that has never run, and a Microsoft 365 certificate that failed to renew or runs out within 14 days.
- Purchase order numbers are now unique on every install (duplicates are renumbered, noted in the PO's history).
- API client delete puts unbilled parts back in stock and removes the client's files and quote images.
- Restoring an older backup runs ITFlow's database updates before ours.
- Scheduling: picking a new tech who can't see the client is refused, and the old tech's invite is only withdrawn once the new one has the ticket.
- Emails from our nightly jobs no longer show a backslash in names with an apostrophe.

## 26.10.71 - 2026-10-20
- Timesheets: changing someone's time zone now counts from that moment - or, if they have a submitted or approved timesheet that ends later, from the day after it - so ticket time already on a timesheet stays where it was (it could be counted twice or dropped before). Every change is logged with who made it.
- Timesheets (California rules): a block that starts and ends at the same time, or blocks typed out of order, are refused with a message instead of being saved as 24 hours or as the next morning. At most 12 blocks a day.
- Timesheets: time put on the default code for "Not on a code" stops at that code's daily limit.
- On a split-shift day the day row no longer says "no meal" when the time off between shifts counts as the meal break.

## 26.10.70 - 2026-10-20
- Timesheets (California rules): Admin > Timesheets > Settings has "Time not on a code goes on" - pick a charge code (e.g. Admin) and the hours worked that are not on ticket time or a code count on it, instead of showing as "Not on a code". The day shows it as "<code> (not on a code)"; the payroll report counts it under that code. Approved timesheets keep the code they were approved with.

## 26.10.69 - 2026-10-19
- Timesheets (California rules): work times are now blocks - Start and End - with "Add another block" for as many as a day needs. Between blocks, pick a meal break or "off between shifts" for a split shift; the day shows the meal and the time off separately. Weekends and holidays take times like any other day.
- Timesheets: time zones for remote people - set one on Admin > Timesheets > People, or yourself under Account > Preferences. Their timesheet's "today", days still to come and the day their ticket time falls on follow their own clock; the timesheet shows the zone.

## 26.10.68 - 2026-10-19
- Timesheets: a Holidays tab under Admin > Timesheets - add, edit (date and name) and remove holidays a year at a time, or add the US holidays for a year. They are the same closure days as Admin > SLA.
- Changing a holiday puts open timesheets right straight away (hours move with it, renamed, or come off); submitted and approved timesheets are left as they are.
- Holidays show on the calendar too, all day in red, under a new Holidays tick box.

## 26.10.67 - 2026-10-19
- Fix: the Notify and To lists on the reply box close when you click into the note editor (it is its own frame, so the click was not seen and the list stayed open).

## 26.10.66 - 2026-10-19
- Fix: a running ticket timer now stops when the note is saved. ITFlow reset it a moment after the button was clicked, which was missed when the page moved on quickly, so the timer kept running.
- After a note the timer stays at 0, even with timer auto-start on; it starts again the next time the ticket is opened.
- An email reply that is not sent (nobody in To) keeps its timer.

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
