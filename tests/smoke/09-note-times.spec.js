// Times on ticket notes (functions/mit_reply_times.php) and what the timesheet does with them: a note with time worked
// must carry its date, start and end; typed times set its length; editing only the length moves the start; the timesheet
// counts it on the day it was worked and lists it with its times, flagging two that overlap. Also the Contractor pay type
// (never under California rules) and lost time shown with the codes. Makes its own client and ticket (deleted again,
// with their notes, even when a step fails); a person's pay type is put back.

const { test, expect } = require('@playwright/test');
const { smokeName, watch, visit, openModal, fillForm, submit, flash, action, expectClean } = require('./helpers');

test.describe.configure({ mode: 'serial' });

const made = { clientName: smokeName('client'), clientId: 0, ticketId: 0, person: null };
const yesterday = (() => {
    const d = new Date(Date.now() - 86400000);
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
})();

// A public note (counted on timesheets whatever the internal-notes setting) with these times
async function note(page, text, start, end) {
    await page.click('label[for=public_reply_type_opt1]');
    await page.locator('#replyComposer.show').waitFor();
    test.skip(await page.locator('#mitWorkStart').count() === 0, 'needs database update M028');
    await page.evaluate((text) => window.tinymce.get(document.querySelector('#replyComposer textarea').id).setContent(text), text);
    await page.fill('#mitWorkDate', yesterday);
    await page.fill('#mitWorkStart', start);
    await page.fill('#mitWorkEnd', end);
    await Promise.all([page.waitForNavigation(), page.click('#ticket_add_reply')]);
    await expectClean(page);
}

test('note times: required with time worked, set the length, shown on the note, and edited', async ({ page }) => {
    const errors = watch(page);
    await visit(page, 'agent/clients.php');
    let modal = await openModal(page, 'modals/client/client_add.php', 'lg');
    await fillForm(modal, { name: made.clientName, contact: 'Smoke Contact' });
    await submit(modal, 'add_client');
    await visit(page, `agent/clients.php?q=${encodeURIComponent(made.clientName)}`);
    made.clientId = Number(new URL(await page.locator('a[href*="client_id="]', { hasText: made.clientName }).first().getAttribute('href'), page.url()).searchParams.get('client_id'));
    modal = await openModal(page, `modals/ticket/ticket_add.php?client_id=${made.clientId}`, 'lg');
    await fillForm(modal, { subject: smokeName('ticket'), details: 'Opened by the smoke tests.', priority: 'Low' });
    await submit(modal, 'add_ticket');
    made.ticketId = Number(new URL(page.url()).searchParams.get('ticket_id'));
    expect(made.ticketId, `ticket page after saving (${await flash(page)})`).toBeGreaterThan(0);
    const ticketPage = `agent/ticket.php?client_id=${made.clientId}&ticket_id=${made.ticketId}`;

    // Time worked with no times: not sent
    await visit(page, ticketPage, errors);
    await page.click('label[for=public_reply_type_opt1]');
    await page.locator('#replyComposer.show').waitFor();
    test.skip(await page.locator('#mitWorkStart').count() === 0, 'needs database update M028');
    await page.fill('#minutes', '20');
    await page.fill('#mitWorkStart', '');
    await page.click('#ticket_add_reply');
    await expect(page.locator('[data-mit-times-error]'), 'refused without times').toBeVisible();
    expect(page.url(), 'still on the ticket').toContain(`ticket_id=${made.ticketId}`);

    // Typed times set the length and show on the note
    await visit(page, ticketPage, errors);
    await note(page, 'Smoke test timed note', '09:00', '09:45');
    const shown = page.locator('[data-mit-reply-times]').first();
    await expect(shown, 'the times on the note').toContainText('9:00 - 9:45 AM');
    await expect(page.locator('body'), 'the length from the times').toContainText('45m');

    // Edit only the length: the end stays, the start moves
    const replyId = await page.locator('[data-modal-url*="ticket_reply_edit.php?id="]').first().getAttribute('data-modal-url').then((u) => new URL(u, page.url()).searchParams.get('id'));
    modal = await openModal(page, `modals/ticket/ticket_reply_edit.php?id=${replyId}`, 'lg');
    await fillForm(modal, { time: '01:00:00' });
    await submit(modal, 'edit_ticket_reply');
    await expect(page.locator('[data-mit-reply-times]').first(), 'start moved back').toContainText('8:45 - 9:45 AM');
    await expectClean(page, errors);
});

test('timesheet: the note counts on the day it was worked, listed with its times, and overlaps are flagged', async ({ page }) => {
    test.skip(!made.ticketId, 'no ticket');
    const errors = watch(page);
    // A second note overlapping the first
    await visit(page, `agent/ticket.php?client_id=${made.clientId}&ticket_id=${made.ticketId}`, errors);
    await note(page, 'Smoke test overlapping note', '09:30', '10:00');

    await visit(page, `agent/mit_timesheet.php?start=${yesterday}`, errors);
    test.skip(await page.locator('text=Timesheets need the database update').count() > 0, 'Timesheets not set up');
    const day = page.locator(`[data-date="${yesterday}"]`).first();
    test.skip(await day.count() === 0, 'yesterday is not on this timesheet page');
    await expect(day, 'flagged on the day').toContainText('Ticket times overlap');
    await day.click();
    const entries = page.locator('[data-ts-entries] li');
    await expect(entries.filter({ hasText: '08:45 - 09:45' }), 'the first note with its times').toHaveCount(1);
    await expect(entries.filter({ hasText: '09:30 - 10:00' }).locator('.badge', { hasText: 'Overlaps' }), 'the overlap marked').toHaveCount(1);
    // Lost time sits with the codes when the day has some
    if (await day.locator('text=Lost time (auto)').count()) {
        await expect(page.locator('[data-auto-lost]'), 'lost time among the codes').toBeVisible();
    }
    await expectClean(page, errors);
});

test('timesheets: a Contractor is never under California rules', async ({ page }) => {
    const errors = watch(page);
    await visit(page, 'admin/mit_timesheets.php?tab=people', errors);
    const row = page.locator('tr', { has: page.locator('.badge', { hasText: 'California' }) }).first();
    test.skip(await row.count() === 0, 'nobody is on California rules here');
    const id = new URL(await row.locator('[data-modal-url*="person.php?id="]').getAttribute('data-modal-url'), page.url()).searchParams.get('id');
    let modal = await openModal(page, `modals/mit_timesheets/person.php?id=${id}`);
    made.person = { id, pay_type: await modal.locator('select[name=pay_type]').inputValue(), ca_rules: await modal.locator('select[name=ca_rules]').inputValue() };
    // Even with California rules switched on for them by hand
    await fillForm(modal, { pay_type: 'contractor', ca_rules: '1' });
    await submit(modal, 'mit_ts_person_save');
    const person = page.locator('tr', { has: page.locator(`[data-modal-url*="person.php?id=${id}"]`) });
    await expect(person, 'shown as a contractor').toContainText('Contractor');
    await expect(person.locator('.badge', { hasText: 'California' }), 'no California rules').toHaveCount(0);
    modal = await openModal(page, `modals/mit_timesheets/person.php?id=${id}`);
    await fillForm(modal, { pay_type: made.person.pay_type, ca_rules: made.person.ca_rules });
    await submit(modal, 'mit_ts_person_save');
    made.person = null;
    await expect(person.locator('.badge', { hasText: 'California' }), 'back on California rules').toHaveCount(1);
    await expectClean(page, errors);
});

test.afterAll(async ({ browser }) => {
    const use = test.info().project.use;
    const context = await browser.newContext({ storageState: use.storageState, baseURL: use.baseURL, ignoreHTTPSErrors: use.ignoreHTTPSErrors });
    const page = await context.newPage();
    if (made.person) {
        await page.goto('admin/mit_timesheets.php?tab=people');
        const modal = await openModal(page, `modals/mit_timesheets/person.php?id=${made.person.id}`);
        await fillForm(modal, { pay_type: made.person.pay_type, ca_rules: made.person.ca_rules });
        await submit(modal, 'mit_ts_person_save');
    }
    if (made.ticketId) {
        await action(page, `delete_ticket=${made.ticketId}`, `agent/ticket.php?client_id=${made.clientId}&ticket_id=${made.ticketId}`);
    }
    if (made.clientId) {
        await action(page, `delete_client=${made.clientId}`, `agent/client_overview.php?client_id=${made.clientId}`);
    }
    await context.close();
});
