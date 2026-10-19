// Mountaineer IT's own additions that the page crawl cannot judge by loading
// alone. Uses the admin login's own preferences - run with a test login.

const { test, expect } = require('@playwright/test');
const { watch, visit, expectClean, fetchText, getJson, csrf } = require('./helpers');

test('Tickets list remembers the view, and Reset forgets it', async ({ page }) => {
    const pill = () => page.locator('.card-header nav a.nav-link.active, nav.nav a.nav-link.active').first().innerText();
    const go = (selector) => Promise.all([page.waitForNavigation(), page.click(selector)]);

    await visit(page, 'agent/tickets.php');
    await go('a[href^="?mit_reset_view="]');
    await go('a[title="Board"]');
    await go('nav a.nav-link:has-text("Unassigned")');
    await go('a[title="List"]');

    // Away and back by the side menu
    await visit(page, 'agent/dashboard.php');
    await go('.app-sidebar a[href$="/agent/tickets.php"]');
    expect(await pill()).toContain('Unassigned');
    await expect(page.locator('a[title="List"]')).toHaveClass(/btn-dark/);

    // A reset link without the session's token (as another site could make) leaves the view alone
    await visit(page, 'agent/tickets.php?mit_reset_view=1');
    await visit(page, 'agent/dashboard.php');
    await go('.app-sidebar a[href$="/agent/tickets.php"]');
    expect(await pill()).toContain('Unassigned');

    // A link from somewhere else shows what it points to, and is not remembered
    await visit(page, 'agent/tickets.php?show=closed');
    await visit(page, 'agent/dashboard.php');
    await go('.app-sidebar a[href$="/agent/tickets.php"]');
    expect(await pill()).toContain('Unassigned');

    await go('a[href^="?mit_reset_view="]');
    expect(await pill()).toContain('Open');
    await visit(page, 'agent/tickets.php');
    expect(await pill()).toContain('Open');
});

test('ticket board and filters', async ({ page }) => {
    const errors = watch(page);
    for (const url of ['agent/tickets.php?view=kanban', 'agent/tickets.php?show=all&sort=ticket_priority&order=ASC', 'agent/tickets.php?show=scheduled', 'agent/tickets.php?q=smoke']) {
        await visit(page, url, errors);
    }
    await visit(page, 'agent/tickets.php', errors);
    await Promise.all([page.waitForNavigation(), page.click('a[href^="?mit_reset_view="]')]);
});

test('timesheet and payroll report', async ({ page }) => {
    const errors = watch(page);
    await visit(page, 'agent/mit_timesheet.php', errors);
    await page.waitForTimeout(300);
    await expectClean(page, errors);
    await visit(page, 'agent/reports/mit_payroll.php', errors);
});

test('timesheets: who is behind, and reminding them', async ({ page }) => {
    const errors = watch(page);
    await visit(page, 'agent/mit_timesheets.php', errors);
    // The handler answers even when there is nobody to remind (yourself is never reminded)
    const token = await csrf(page);
    // (fetch follows the redirect back to the page, which shows the message)
    const answer = await page.evaluate(async (token) => (await fetch('post.php', {method: 'POST', headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: new URLSearchParams({csrf_token: token, mit_ts_remind: '0'}).toString()})).text(), token);
    expect(answer, 'the Remind handler').toContain('Nobody to remind');
    const box = page.locator('[data-mit-ts-behind]');
    test.skip(await box.count() === 0, 'nobody is behind on their timesheets');
    // Remind the first person: sent, or already reminded within the hour (a second run)
    await Promise.all([page.waitForNavigation(), box.locator('button[name=mit_ts_remind]:not([value=all])').first().click()]);
    await expect(page.locator('body')).toContainText(/Reminder sent to|reminded in the last hour/);
    await expect(page.locator('[data-mit-ts-behind]')).toContainText(/Reminded /);
    await expectClean(page, errors);
});

test('Dispatch board, day and week', async ({ page }) => {
    const errors = watch(page);
    await visit(page, 'agent/mit_dispatch.php', errors);
    test.skip(!/mit_dispatch\.php/.test(page.url()), 'Dispatch is switched off');
    await page.waitForTimeout(1000); // the board draws itself
    await expectClean(page, errors);
    for (const queue of ['unassigned', 'unscheduled']) {
        const { status, text } = await fetchText(page, `mit_dispatch_ajax.php?queue=${queue}`);
        expect(status).toBe(200);
        expect(() => JSON.parse(text), `queue ${queue}: ${text.slice(0, 200)}`).not.toThrow();
    }
});

test('calendar draws', async ({ page }) => {
    const errors = watch(page);
    await visit(page, 'agent/calendar.php', errors);
    await page.waitForTimeout(1000);
    await expectClean(page, errors);
});

test('cron health address and alert settings', async ({ page }) => {
    const errors = watch(page);
    await visit(page, 'admin/cron.php', errors);
    const url = page.locator('#mitHealthUrl');
    test.skip(await url.count() === 0, 'cron alerts card not shown');
    const healthPath = new URL(await url.inputValue()).pathname + new URL(await url.inputValue()).search;

    // The right key answers OK (200) or PROBLEM (503) - either way plain text, no PHP error
    const right = await fetchText(page, healthPath);
    expect([200, 503]).toContain(right.status);
    expect(right.text).toMatch(/^(OK|PROBLEM)\n/);
    expect(right.text).not.toMatch(/Fatal error|Warning:/);
    // A wrong key or none is refused
    expect((await fetchText(page, '/mit_health.php?key=wrong')).status).toBe(403);
    expect((await fetchText(page, '/mit_health.php')).status).toBe(403);

    // The top bar warning matches: shown exactly when the address reports a problem
    await expect(page.locator('#mitCronHealth')).toHaveCount(right.status === 503 ? 1 : 0);
});

test('purchase order action buttons are buttons', async ({ page }) => {
    await visit(page, 'agent/mit_purchase_orders.php?show=all');
    const link = page.locator('a[href*="mit_purchase_order.php?po_id="]').first();
    test.skip(await link.count() === 0, 'no purchase orders');
    await visit(page, await link.evaluate((a) => a.href));
    // Every action opening a window keeps its button look (a second class attribute would be dropped by the browser)
    const plain = await page.locator('[data-modal-url*="po_action.php"]').evaluateAll((links) => links
        .filter((a) => !a.classList.contains('ajax-modal') || !(a.classList.contains('btn') || a.classList.contains('dropdown-item')))
        .map((a) => a.textContent.trim()));
    expect(plain).toEqual([]);
});

test('reply box starts open on Public note', async ({ page }) => {
    await visit(page, 'agent/tickets.php?show=open');
    const ticket = page.locator('a[href*="ticket.php?"][href*="ticket_id="]').first();
    test.skip(await ticket.count() === 0, 'no open tickets');
    const errors = watch(page);
    await visit(page, await ticket.evaluate((a) => a.href), errors);
    await expect(page.locator('#public_reply_type_opt1')).toBeChecked();
    await expect(page.locator('#replyComposer')).toHaveClass(/\bshow\b/);
    await expect(page.locator('#replyAudience [data-audience="1"]')).toBeVisible();
    await expect(page.locator('#replySubmitLabel')).toHaveText('Post note');
    // The editor is sized for an open box, not squashed to nothing
    const editor = page.locator('#replyComposer .tox-tinymce');
    await editor.waitFor();
    expect((await editor.boundingBox()).height, 'reply editor height').toBeGreaterThan(100);
    // Cancel still shuts it
    await page.click('#cancelReply');
    await expect(page.locator('#replyComposer')).not.toHaveClass(/\bshow\b/);
    await expectClean(page, errors);
});

test('Dispatch workload', async ({ page }) => {
    const errors = watch(page);
    await visit(page, 'agent/mit_dispatch.php', errors);
    test.skip(!/mit_dispatch\.php/.test(page.url()), 'Dispatch is switched off');
    await page.click('[data-md-view=workload]');
    await page.locator('.mit-wl-row').first().waitFor();
    await expect(page.locator('[data-md-board]')).toContainText('Team average');

    const techs = JSON.parse(await page.locator('#mitDispatch').getAttribute('data-techs')).map((t) => t.id);
    const workload = await getJson(page, `mit_dispatch_ajax.php?workload=1&techs=${techs.join(',')}`);
    expect(workload.techs.length, 'a row per agent').toBe(techs.length);
    for (const t of workload.techs) {
        expect(Object.keys(t.sla).sort()).toEqual(['at_risk', 'breached', 'none', 'paused', 'running']);
        const counted = Object.values(t.sla).reduce((a, b) => a + b, 0);
        expect(counted, `${t.name}: every open ticket has an SLA state`).toBe(t.open);
    }

    // Move to: give an unbooked ticket to another agent, and back
    const owner = workload.techs.find((t) => t.tickets.some((k) => k.role === 'lead' && !k.schedule_start));
    test.skip(!owner || techs.length < 2, 'no unbooked ticket to move');
    const ticket = owner.tickets.find((k) => k.role === 'lead' && !k.schedule_start);
    const other = techs.find((id) => id !== owner.id);
    const token = await page.locator('#mitDispatch').getAttribute('data-csrf');
    const assign = (tech) => page.evaluate(async ([id, tech, token]) => (await fetch('mit_dispatch_ajax.php', {
        method: 'POST', headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: new URLSearchParams({assign: 1, csrf_token: token, ticket_id: id, tech_id: tech}).toString()})).json(), [ticket.id, tech, token]);
    expect((await assign(other)).ok, 'moved').toBe(true);
    const after = await getJson(page, `mit_dispatch_ajax.php?workload=1&techs=${other}`);
    expect(after.techs[0].tickets.some((k) => k.id === ticket.id), 'on the other agent now').toBe(true);
    expect((await assign(owner.id)).ok, 'moved back').toBe(true);
    await expectClean(page, errors);
});

test('Dispatch this week: in vs closed, SLA, by agent', async ({ page }) => {
    const errors = watch(page);
    await visit(page, 'agent/mit_dispatch.php', errors);
    test.skip(!/mit_dispatch\.php/.test(page.url()), 'Dispatch is switched off');
    await page.locator('.mit-stats canvas[data-ms-chart]').waitFor();
    await expect(page.locator('[data-md-stats]')).toContainText('SLA this week');

    const techs = JSON.parse(await page.locator('#mitDispatch').getAttribute('data-techs')).map((t) => t.id);
    const week = await getJson(page, `mit_dispatch_ajax.php?week_stats=1&first_day=1&techs=${techs.join(',')}`);
    expect(week.days.length, 'seven days').toBe(7);
    expect(new Date(week.week[0] + 'T12:00:00').getDay(), 'a Monday week starts on Monday').toBe(1);
    expect(week.days.reduce((a, d) => a + d.inbound, 0), 'inbound adds up').toBe(week.totals.inbound);
    expect(week.days.reduce((a, d) => a + d.closed, 0), 'closed adds up').toBe(week.totals.closed);
    expect(week.totals.net).toBe(week.totals.inbound - week.totals.closed);
    expect(week.techs.length, 'a row per agent').toBe(techs.length);
    expect(week.techs.reduce((a, t) => a + t.closed, 0), 'agents close no more than the team').toBeLessThanOrEqual(week.totals.closed);
    for (const key of ['response_met', 'response_missed', 'resolution_met', 'resolution_missed', 'breached_now', 'at_risk_now']) {
        expect(typeof week.sla[key], `sla.${key}`).toBe('number');
    }
    await expectClean(page, errors);
});

test('NinjaOne: settings pages, and the status asked for beside assets', async ({ page }) => {
    const errors = watch(page);
    for (const tab of ['settings', 'mapping', 'runs']) {
        await visit(page, `admin/mit_ninjaone.php?tab=${tab}`, errors);
        await expect(page.locator('.card-title')).toContainText('NinjaOne');
    }
    // The endpoint the buttons use answers with an object of linked assets (empty when none are linked)
    const { status, text } = await fetchText(page, '/agent/mit_ninjaone_ajax.php?status=1,2,3');
    expect(status, 'NinjaOne status endpoint').toBe(200);
    const devices = JSON.parse(text);
    expect(typeof devices).toBe('object');
    for (const device of Object.values(devices)) {
        expect(device.url, 'button goes to a device page in NinjaOne').toMatch(/^https:\/\/[^/]+\/#\/deviceDashboard\/\d+\/overview$/);
    }
    await expectClean(page, errors);
});

test('description ticked Read stays folded away, untick shows it again', async ({ page }) => {
    await visit(page, 'agent/tickets.php?show=open');
    const ticket = page.locator('a[href*="ticket.php?"][href*="ticket_id="]').first();
    test.skip(await ticket.count() === 0, 'no open tickets');
    const url = await ticket.evaluate((a) => a.href);
    const errors = watch(page);
    await visit(page, url, errors);
    const tick = page.locator('#mitTicketRead');
    const details = page.locator('#ticketDetails');
    if (await tick.isChecked()) { // left ticked by hand - start from unread
        await Promise.all([page.waitForResponse((r) => r.url().includes('ajax.php')), tick.uncheck()]);
        await visit(page, url, errors);
    }
    await expect(details).toBeVisible();
    await Promise.all([page.waitForResponse((r) => r.url().includes('ajax.php') && r.ok()), tick.check()]);
    await expect(details).toBeHidden();
    await visit(page, url, errors);
    await expect(tick, 'still ticked after a reload').toBeChecked();
    await expect(details, 'and still folded away').toBeHidden();
    await Promise.all([page.waitForResponse((r) => r.url().includes('ajax.php') && r.ok()), tick.uncheck()]);
    await expect(details).toBeVisible();
    await visit(page, url, errors);
    await expect(tick).not.toBeChecked();
    await expect(details).toBeVisible();
    await expectClean(page, errors);
});
