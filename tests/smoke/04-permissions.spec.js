// A Technician (ITFLOW_TECH_EMAIL) can work tickets but not reach Admin or
// the admin-only parts of our features. Skipped when no technician login is given.

const fs = require('fs');
const path = require('path');
const { test, expect } = require('@playwright/test');
const { visit, csrf } = require('./helpers');

const techState = path.join(__dirname, '.auth', 'tech.json');
test.skip(!fs.existsSync(techState), 'no ITFLOW_TECH_EMAIL / ITFLOW_TECH_PASSWORD given');
test.use({ storageState: techState });

test('a technician can work tickets', async ({ page }) => {
    await visit(page, 'agent/tickets.php');
    await visit(page, 'agent/calendar.php');
    await visit(page, 'agent/mit_timesheet.php');
});

test('a technician cannot open Admin', async ({ page }) => {
    for (const url of ['admin/users.php', 'admin/settings_mail.php', 'admin/mit_timesheets.php', 'admin/mit_procurement_settings.php', 'admin/backup.php']) {
        await page.goto(url);
        const inAdmin = /\/admin\//.test(page.url()) && await page.locator('.app-sidebar a[href*="/admin/users.php"]').count() > 0;
        expect(inAdmin, `${url} opened for a technician`).toBe(false);
    }
});

test('a technician cannot save admin settings', async ({ page, browser }) => {
    const use = test.info().project.use;
    const admin = await browser.newContext({ storageState: path.join(__dirname, '.auth', 'admin.json'), baseURL: use.baseURL, ignoreHTTPSErrors: use.ignoreHTTPSErrors });
    const adminPage = await admin.newPage();
    const readForm = async () => {
        await adminPage.goto('admin/mit_procurement_settings.php');
        return adminPage.locator('form:has(input[name=mit_po_prefix])').evaluate((form) => Object.fromEntries(new FormData(form)));
    };
    const before = await readForm();
    test.skip(!before.mit_po_prefix, 'Procurement settings page not available');

    // The real form, complete and valid, sent with the technician's own valid token
    await visit(page, 'agent/tickets.php');
    const fields = { ...before, csrf_token: await csrf(page), mit_edit_procurement_settings: '1', mit_po_prefix: 'TECH-' };
    await page.evaluate(async (fields) => {
        await fetch('/admin/post.php', { method: 'POST', body: new URLSearchParams(fields), credentials: 'same-origin' });
    }, fields);

    const after = await readForm();
    if (after.mit_po_prefix !== before.mit_po_prefix) {
        // It got through - put it back before failing
        await adminPage.locator('form:has(input[name=mit_po_prefix]) input[name=mit_po_prefix]').fill(before.mit_po_prefix);
        await Promise.all([adminPage.waitForNavigation(), adminPage.locator('button[name=mit_edit_procurement_settings]').click()]);
    }
    await admin.close();
    expect(after.mit_po_prefix, 'a technician changed the PO prefix').toBe(before.mit_po_prefix);
});

test('ticket Parts box for everyone; the rest of procurement only with the Procurement permission', async ({ page }) => {
    await visit(page, 'agent/dashboard.php');
    const hasProcurement = await page.locator('.app-sidebar .nav-header:has-text("PROCUREMENT")').count() > 0;
    // An open ticket (a closed one with no parts has no Parts box for anyone)
    await visit(page, 'agent/tickets.php?show=open');
    const ticket = page.locator('a[href*="ticket.php?"][href*="ticket_id="]').first();
    test.skip(await ticket.count() === 0, 'no open tickets');
    await visit(page, await ticket.evaluate((a) => a.href));
    await page.waitForTimeout(500);
    expect(await page.locator('.card-title:has(i.fa-microchip)').count(), 'Parts box on the ticket').toBe(1);
    // Procurement's own pages: open with the permission, refused without it
    const text = await page.evaluate(async () => (await fetch('/agent/mit_procurement.php')).text());
    expect(text.includes('To order</h3>'), 'Procurement > To order page').toBe(hasProcurement);
});
