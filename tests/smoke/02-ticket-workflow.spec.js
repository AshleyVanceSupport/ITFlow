// The everyday path through the forms a person uses: add a client, open a
// ticket for it, add an internal note with time, book it on the calendar, and
// see the booking on the calendar and Dispatch. Everything it makes is named
// "Smoke test ..." and deleted again at the end, even when a step fails.
// Nothing is emailed to a customer: the contact has no email address and the
// note is internal (the booking email goes to the agent it is booked for).

const { test, expect } = require('@playwright/test');
const { smokeName, watch, visit, openModal, fillForm, submit, flash, action, fetchText, getJson, expectClean } = require('./helpers');

test.describe.configure({ mode: 'serial' });

const made = { clientName: smokeName('client'), clientId: 0, ticketId: 0 };

function tomorrow(hour) {
    const d = new Date(Date.now() + 86400000);
    const pad = (n) => String(n).padStart(2, '0');
    return { date: `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`, time: `${pad(hour)}:00` };
}

test('add a client', async ({ page }) => {
    await visit(page, 'agent/clients.php');
    const modal = await openModal(page, 'modals/client/client_add.php', 'lg');
    await fillForm(modal, { name: made.clientName, contact: 'Smoke Contact' });
    await submit(modal, 'add_client');

    await visit(page, `agent/clients.php?q=${encodeURIComponent(made.clientName)}`);
    const href = await page.locator('a[href*="client_id="]', { hasText: made.clientName }).first().getAttribute('href');
    made.clientId = Number(new URL(href, page.url()).searchParams.get('client_id'));
    expect(made.clientId, 'new client found in the list').toBeGreaterThan(0);
});

test('open a ticket for it', async ({ page }) => {
    test.skip(!made.clientId, 'no client');
    await visit(page, `agent/client_overview.php?client_id=${made.clientId}`);
    const modal = await openModal(page, `modals/ticket/ticket_add.php?client_id=${made.clientId}`, 'lg');
    await fillForm(modal, { subject: smokeName('ticket'), details: 'Opened by the smoke tests.', priority: 'Low' });
    await submit(modal, 'add_ticket');

    made.ticketId = Number(new URL(page.url()).searchParams.get('ticket_id'));
    expect(made.ticketId, `ticket page after saving (${page.url()} - ${await flash(page)})`).toBeGreaterThan(0);
    await expect(page.locator('body')).toContainText('Smoke test ticket');
});

test('add an internal note with time', async ({ page }) => {
    test.skip(!made.ticketId, 'no ticket');
    const errors = watch(page);
    await visit(page, `agent/ticket.php?client_id=${made.clientId}&ticket_id=${made.ticketId}`, errors);
    // As a person does: pick the internal note button, which opens the composer
    await page.click('label[for=public_reply_type_opt0]');
    await page.locator('#replyComposer.show').waitFor();
    const form = page.locator('form:has(#replyComposer)');
    await fillForm(form, { ticket_reply: 'Smoke test internal note', minutes: '15' });
    await submit(form, 'add_ticket_reply');
    await expect(page.locator('body')).toContainText('Smoke test internal note');
    await expectClean(page, errors);
});

test('book it and see it on the calendar and Dispatch', async ({ page }) => {
    test.skip(!made.ticketId, 'no ticket');
    await visit(page, `agent/ticket.php?client_id=${made.clientId}&ticket_id=${made.ticketId}`);
    const when = tomorrow(10);
    const modal = await openModal(page, `modals/ticket/ticket_edit_schedule.php?ticket_id=${made.ticketId}`);
    await fillForm(modal, { scheduled_date_time: `${when.date}T${when.time}`, schedule_minutes: '60', onsite: '0' });
    await submit(modal, 'edit_ticket_schedule');
    expect(await flash(page)).not.toMatch(/error|could not|can't/i);

    // URLs below are relative to the page we are on (agent/)
    const items = await getJson(page, `ajax.php?calendar_items&from=${when.date}&to=${when.date}&sources=tickets`);
    const booking = items.find((item) => item.id === `ticket-${made.ticketId}`);
    expect(booking, 'the booking on the calendar').toBeTruthy();
    expect(booking.start).toContain(`${when.date} 10:00`);
    expect(booking.end, 'booking end time (Mountaineer IT calendar)').toContain(`${when.date} 11:00`);

    await visit(page, 'agent/calendar.php');
    const dispatch = await fetchText(page, `mit_dispatch_ajax.php?items&from=${when.date}&to=${when.date}&techs=`);
    if (dispatch.status === 200) { // 403 when Dispatch is off or not allowed
        expect(dispatch.text).not.toMatch(/Fatal error|Warning:/);
        expect(() => JSON.parse(dispatch.text)).not.toThrow();
    }
});

test('our boxes on the ticket page', async ({ page }) => {
    test.skip(!made.ticketId, 'no ticket');
    const errors = watch(page);
    await visit(page, `agent/ticket.php?client_id=${made.clientId}&ticket_id=${made.ticketId}`, errors);
    await page.waitForTimeout(500); // the layout script moves the boxes
    for (const icon of ['fa-microchip', 'fa-receipt']) {
        if (await page.locator(`.card-title i.${icon}, .card-header i.${icon}`).count() === 0) {
            test.info().annotations.push({ type: 'note', description: `${icon} box not shown (module off?)` });
        }
    }
    await expectClean(page, errors);
});

test.afterAll(async ({ browser }) => {
    const use = test.info().project.use;
    const context = await browser.newContext({ storageState: use.storageState, baseURL: use.baseURL, ignoreHTTPSErrors: use.ignoreHTTPSErrors });
    const page = await context.newPage();
    if (made.ticketId) {
        await action(page, `delete_ticket=${made.ticketId}`, `agent/ticket.php?client_id=${made.clientId}&ticket_id=${made.ticketId}`);
    }
    // This run's client, and any a failed earlier run left behind (deleting a client deletes its tickets)
    await page.goto('agent/clients.php?q=Smoke+test+client');
    const ids = new Set(made.clientId ? [made.clientId] : []);
    for (const href of await page.locator('a[href*="client_id="]', { hasText: /^Smoke test client \d{14}$/ }).evaluateAll((links) => links.map((a) => a.href))) {
        ids.add(Number(new URL(href).searchParams.get('client_id')));
    }
    for (const id of ids) {
        await action(page, `delete_client=${id}`, `agent/client_overview.php?client_id=${id}`);
    }
    await context.close();
});
