# Smoke tests (Mountaineer IT build)

A quick check, in a real browser, that the app still works after a change - above all after
merging an ITFlow release. About a minute. **Run it against the beta / test install, never
production**: it adds and deletes a client and a ticket, and changes the test logins' own
saved views.

## What it checks

| File | What |
| --- | --- |
| `01-pages.spec.js` | Every page in the agent, client, Admin and Account menus, plus one ticket / invoice / quote / project / PO / timesheet, opens with no PHP or JavaScript error. The menus are read from the pages, so pages a release adds are covered too. |
| `02-ticket-workflow.spec.js` | Through the real forms: add a client, open a ticket, add an internal note with time, book it for tomorrow 10:00-11:00, find it on the calendar (with its end time) and Dispatch. Deletes what it made, and any "Smoke test client ..." an earlier failed run left. No customer email is sent. |
| `03-our-features.spec.js` | Remembered Tickets view + Reset, board and filters, timesheet, payroll report, Dispatch, calendar, version / release notes, holidays, pop-up links to other sites refused, security headers and API keys only in a header, the Microsoft 365 calendar-app offer, the modern look (on by default, light and dark, a client's count tiles, quotes / invoices as a document, follows the company theme colour; Classic turns it off), the sign-in page's backdrop. The "Remind" step emails a real person, so it only runs with `ITFLOW_SMOKE_REMIND=1` (when everyone on the install is a test login). |
| `04-permissions.spec.js` | A Technician can work tickets but cannot open Admin pages or save an admin setting (tries the real Procurement settings form and checks, as admin, that nothing changed). Also: changing your password ends your other sign-ins (signs the technician in again afterwards). Skipped without a technician login. |
| `05-california.spec.js` | As the technician (on California rules): work times and the meal check, split shifts, refused blocks (same start and end, out of order), the signing window, a remote time zone's "today", and the default charge code for time not on a code. Clears what it types, also when a step fails. |
| `06-flows.spec.js` | Our features end to end through their real forms: procurement (item to order -> PO -> approve -> order -> receive), a ticket's parts, mileage and helper agents, quote pricing from our cost (never shown to the customer), a recurring ticket on "the third Wednesday", and a ticket put in a project and taken out. Deletes what it makes (a received PO can't be - its line is named "Smoke test ..."). |
| `07-boards.spec.js` | Service boards: Admin > Settings > Boards, a client routing rule placing a new ticket on its board, Escalate (reason required, kept as an internal note), the Tickets board switcher and Dispatch's queue by board; round-robin auto-assign handing out a ticket moved in unassigned, the dashboard's board picker (remembered) and the Board Performance report with its escalations export. Removes its client, ticket and rule and puts the board's settings back, also when a step fails. |
| `99-php-log.spec.js` | No PHP warning or error was written to the site's error log during the run. Production-style servers don't show PHP warnings on the page, so this is the only way to see them. Skipped unless `ITFLOW_PHP_LOG` is set (i.e. when run on the server itself). |

## Set up (once)

Needs Node 18 or newer.

```bash
cd tests/smoke
npm ci
npx playwright install chromium     # the browser (skip where Chromium is already provided)
cp .env.example .env                # then fill it in
```

On the test install, make two logins just for this, **without 2FA**:
- an admin (e.g. `smoke-admin@...`),
- a Technician-role user (optional, for `04-permissions`), with access to all clients.

`tests/smoke/.htaccess` stops Apache serving these files if the
checkout is the web root. `.env`, `node_modules/`, `.auth/` and the reports are git-ignored.

## Run

```bash
cd tests/smoke
npx playwright test                 # everything
npx playwright test 02              # one file
npx playwright show-report report   # the HTML report: screenshots and a trace of anything that failed
```

## When to run

- After every upstream merge (with the database update done) - before pushing to `develop`.
- Before every "Promote to production".
- After any change to a page or hook in ITFlow's own files.

## Adding a test

Use the helpers in `helpers.js`: `visit()` (open + check for errors), `openModal()` (an ITFlow ajax
modal), `fillForm()` (by field name - copes with select2 and TinyMCE), `submit()` (says which field
blocked the form), `action()` (a GET action link with the CSRF token), `getJson()`. Name anything you
create with `smokeName()` and delete it in `afterAll`. Check a new test fails when the thing it
guards is broken.
