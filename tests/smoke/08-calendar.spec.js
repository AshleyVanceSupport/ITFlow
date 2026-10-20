// The calendar's sidebar lists: To schedule and Left behind (functions/mit_left_behind.php) follow the Whose picker -
// Everyone = everyone's - and a ticket whose booked day has gone is left behind (marked on the calendar, and in Dispatch's
// Left behind tab) until it is booked again. Makes its own client and ticket ("Smoke test ...") and deletes them, even when a step fails; the
// calendar's Whose choice is put back.

const { test, expect } = require('@playwright/test');
const { smokeName, watch, visit, openModal, fillForm, submit, flash, action, getJson, expectClean } = require('./helpers');

test.describe.configure({ mode: 'serial' });

const made = { clientName: smokeName('client'), clientId: 0, ticketId: 0, whose: null };

function day(offset, time = '10:00') {
    const d = new Date(Date.now() + offset * 86400000);
    const pad = (n) => String(n).padStart(2, '0');
    return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())} ${time}`;
}

// Book through the calendar's own move request
async function book(page, start) {
    return page.evaluate(async ([id, start]) => {
        const root = document.getElementById('itflowCalendar');
        const body = new URLSearchParams({ calendar_move: 1, csrf_token: root.dataset.csrf, kind: 'ticket', id, start });
        return (await fetch('ajax.php', { method: 'POST', credentials: 'same-origin', body })).json();
    }, [String(made.ticketId), start]);
}

async function pickWhose(page, value) {
    await page.evaluate((v) => {
        const select = document.getElementById('calendarAgent');
        select.value = v;
        select.dispatchEvent(new Event('change', { bubbles: true }));
    }, String(value));
}

test('calendar: To schedule and Left behind follow Whose, and a passed booking is left behind', async ({ page }) => {
    const errors = watch(page);
    await visit(page, 'agent/calendar.php', errors);
    test.skip(await page.locator('#mitLeftBehind').count() === 0, 'needs Support edit (scheduling) and the Left behind list');
    const me = await page.locator('#itflowCalendar').getAttribute('data-user-id');
    made.whose = await page.locator('#calendarAgent').inputValue();

    await visit(page, 'agent/clients.php');
    let modal = await openModal(page, 'modals/client/client_add.php', 'lg');
    await fillForm(modal, { name: made.clientName, contact: 'Smoke Contact' });
    await submit(modal, 'add_client');
    await visit(page, `agent/clients.php?q=${encodeURIComponent(made.clientName)}`);
    made.clientId = Number(new URL(await page.locator('a[href*="client_id="]', { hasText: made.clientName }).first().getAttribute('href'), page.url()).searchParams.get('client_id'));
    expect(made.clientId).toBeGreaterThan(0);
    modal = await openModal(page, `modals/ticket/ticket_add.php?client_id=${made.clientId}`, 'lg');
    await fillForm(modal, { subject: smokeName('ticket'), details: 'Opened by the smoke tests.', priority: 'Low', assigned_to: me });
    await submit(modal, 'add_ticket');
    made.ticketId = Number(new URL(page.url()).searchParams.get('ticket_id'));
    expect(made.ticketId, `ticket page after saving (${await flash(page)})`).toBeGreaterThan(0);

    // To schedule: mine, and everyone's on Everyone
    await visit(page, 'agent/calendar.php', errors);
    const ids = (json) => (json.tickets || []).map((t) => t.id);
    expect(ids(await getJson(page, `ajax.php?calendar_unscheduled=1&user_id=${me}&client_id=${made.clientId}`)), 'To schedule, mine').toContain(made.ticketId);
    const everyone = await getJson(page, `ajax.php?calendar_unscheduled=1&user_id=0&client_id=${made.clientId}`);
    expect(ids(everyone), 'To schedule, everyone\'s').toContain(made.ticketId);
    expect(everyone.tickets.find((t) => t.id === made.ticketId).agent, 'with the agent named').toBeTruthy();
    await pickWhose(page, 0);
    await expect(page.locator(`#calendarUnscheduled [data-ticket="${made.ticketId}"]`), 'in the list on Everyone').toHaveCount(1);

    // Booked three days ago and still open: left behind - in the list (Everyone and mine) and marked on the calendar
    expect((await book(page, day(-3))).ok, 'booked in the past').toBe(true);
    await visit(page, 'agent/calendar.php', errors);
    await pickWhose(page, 0);
    await expect(page.locator(`#mitLeftBehind [data-ticket="${made.ticketId}"]`), 'left behind, Everyone').toHaveCount(1);
    await pickWhose(page, me);
    await expect(page.locator(`#mitLeftBehind [data-ticket="${made.ticketId}"]`), 'left behind, mine').toHaveCount(1);
    const date = day(-3).slice(0, 10);
    const items = await getJson(page, `ajax.php?calendar_items=1&from=${date}&to=${date}&sources=tickets`);
    const item = items.find((i) => i.id === `ticket-${made.ticketId}`);
    expect(item && item.className, 'its old booking is marked').toContain('mit-left-behind');
    // and in Dispatch's Left behind tab (every agent's)
    const dispatch = await page.evaluate(async () => {
        const res = await fetch('mit_dispatch_ajax.php?queue=left_behind&board=all', { credentials: 'same-origin' });
        return res.ok ? res.json() : null;
    });
    if (dispatch) { // Dispatch can be switched off
        expect(dispatch.tickets.map((t) => t.id), 'in Dispatch\'s Left behind tab').toContain(made.ticketId);
        expect(dispatch.counts.left_behind, 'counted on the tab').toBeGreaterThan(0);
    }

    // Booked again for tomorrow: no longer left behind
    expect((await book(page, day(1))).ok, 'booked again').toBe(true);
    expect(ids(await getJson(page, `ajax.php?mit_left_behind=1&user_id=0&client_id=${made.clientId}`)), 'moved on').not.toContain(made.ticketId);
    await expectClean(page, errors);
});

test.afterAll(async ({ browser }) => {
    const use = test.info().project.use;
    const context = await browser.newContext({ storageState: use.storageState, baseURL: use.baseURL, ignoreHTTPSErrors: use.ignoreHTTPSErrors });
    const page = await context.newPage();
    if (made.whose !== null) {
        await page.goto('agent/calendar.php');
        await pickWhose(page, made.whose);
        await page.waitForTimeout(1500); // the choice is saved to the account a moment after the change
    }
    if (made.ticketId) {
        await action(page, `delete_ticket=${made.ticketId}`, `agent/ticket.php?client_id=${made.clientId}&ticket_id=${made.ticketId}`);
    }
    if (made.clientId) {
        await action(page, `delete_client=${made.clientId}`, `agent/client_overview.php?client_id=${made.clientId}`);
    }
    await context.close();
});
