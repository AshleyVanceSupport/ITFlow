// Development mode (functions/mit_dev_mode.php): switched on in Admin > Backup (a test inbox required) it is kept in
// config.php and shows the DEVELOPMENT badge in the top bar with the inbox; switched off the badge goes away. While on,
// integrations only read: NinjaOne's organizations can still be read (sign-in token + GET pass mitDevAllows); writes are refused
// before they leave (checked by hand - there is no button here that writes to another system on the test install).
// Always switched off again at the end, even when a step fails - the other tests need the normal behaviour.

const { test, expect } = require('@playwright/test');
const { watch, visit, fillForm, submit, flash, expectClean } = require('./helpers');

test.describe.configure({ mode: 'serial' });

async function setDevMode(page, on, mailTo = 'dev-inbox@mountaineerit.test') {
    await visit(page, 'admin/backup.php');
    const form = page.locator('form:has(button[name=mit_dev_mode_save])');
    await fillForm(form, { dev_mode: on, dev_mail_to: on ? mailTo : '' });
    await submit(form, 'mit_dev_mode_save');
    return flash(page);
}

test('development mode: on shows the badge and keeps the test inbox, off takes it away', async ({ page }) => {
    const errors = watch(page);
    await visit(page, 'admin/backup.php', errors);
    test.skip(await page.locator('[data-mit-dev-card]').count() === 0, 'no development mode card');

    expect(await setDevMode(page, true, ''), 'a test inbox is required').toContain('Give a test inbox');
    expect(await setDevMode(page, true), 'switched on').toContain('Development mode is on');
    await visit(page, 'agent/dashboard.php', errors);
    await expect(page.locator('[data-mit-dev-mode]'), 'the badge in the top bar').toBeVisible();
    await expect(page.locator('[data-mit-dev-mode]')).toHaveAttribute('title', /dev-inbox@mountaineerit\.test/);

    // Reads still work - only when NinjaOne is set up (the test install's stand-in)
    await visit(page, 'admin/mit_ninjaone.php?tab=mapping', errors);
    const read = page.locator('form:has(button[name=mit_ninja_refresh_orgs])');
    if (await read.count()) {
        await submit(read, 'mit_ninja_refresh_orgs');
        expect(await flash(page), 'NinjaOne still reads in development mode').toMatch(/organizations? read from NinjaOne/);
    }

    expect(await setDevMode(page, false), 'switched off').toContain('Development mode is off');
    await visit(page, 'agent/dashboard.php', errors);
    await expect(page.locator('[data-mit-dev-mode]'), 'no badge').toHaveCount(0);
    await expectClean(page, errors);
});

test.afterAll(async ({ browser }) => {
    const use = test.info().project.use;
    const context = await browser.newContext({ storageState: use.storageState, baseURL: use.baseURL, ignoreHTTPSErrors: use.ignoreHTTPSErrors });
    await setDevMode(await context.newPage(), false).catch(() => {});
    await context.close();
});
