// Mountaineer IT's own additions that the page crawl cannot judge by loading
// alone. Uses the admin login's own preferences - run with a test login.

const { test, expect } = require('@playwright/test');
const { watch, visit, expectClean, fetchText } = require('./helpers');

test('Tickets list remembers the view, and Reset forgets it', async ({ page }) => {
    const pill = () => page.locator('.card-header nav a.nav-link.active, nav.nav a.nav-link.active').first().innerText();
    const go = (selector) => Promise.all([page.waitForNavigation(), page.click(selector)]);

    await visit(page, 'agent/tickets.php?mit_reset_view=1');
    await go('a[title="Board"]');
    await go('nav a.nav-link:has-text("Unassigned")');
    await go('a[title="List"]');

    // Away and back by the side menu
    await visit(page, 'agent/dashboard.php');
    await go('.app-sidebar a[href$="/agent/tickets.php"]');
    expect(await pill()).toContain('Unassigned');
    await expect(page.locator('a[title="List"]')).toHaveClass(/btn-dark/);

    // A link from somewhere else shows what it points to, and is not remembered
    await visit(page, 'agent/tickets.php?show=closed');
    await visit(page, 'agent/dashboard.php');
    await go('.app-sidebar a[href$="/agent/tickets.php"]');
    expect(await pill()).toContain('Unassigned');

    await go('a[href="?mit_reset_view=1"]');
    expect(await pill()).toContain('Open');
    await visit(page, 'agent/tickets.php');
    expect(await pill()).toContain('Open');
});

test('ticket board and filters', async ({ page }) => {
    const errors = watch(page);
    for (const url of ['agent/tickets.php?view=kanban', 'agent/tickets.php?show=all&sort=ticket_priority&order=ASC', 'agent/tickets.php?show=scheduled', 'agent/tickets.php?q=smoke']) {
        await visit(page, url, errors);
    }
    await visit(page, 'agent/tickets.php?mit_reset_view=1', errors);
});

test('timesheet and payroll report', async ({ page }) => {
    const errors = watch(page);
    await visit(page, 'agent/mit_timesheet.php', errors);
    await page.waitForTimeout(300);
    await expectClean(page, errors);
    await visit(page, 'agent/reports/mit_payroll.php', errors);
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
