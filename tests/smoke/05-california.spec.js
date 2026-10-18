// California non-exempt timesheets (functions/mit_ts_california.php) and the timesheet page (agent/js/mit_timesheet.js),
// as the Technician (ITFLOW_TECH_EMAIL, who must be hourly / on California rules). Types times on a past day of the
// current period that has none, checks the meal check and that it saves on its own, then clears them again (the
// change history keeps both - this is a test login).

const fs = require('fs');
const path = require('path');
const { test, expect } = require('@playwright/test');
const { watch, visit, expectClean } = require('./helpers');

const techState = path.join(__dirname, '.auth', 'tech.json');
test.skip(!fs.existsSync(techState), 'no ITFLOW_TECH_EMAIL / ITFLOW_TECH_PASSWORD given');
test.use({ storageState: techState });

test('a day: times, meal check, saves as you type', async ({ page }) => {
    const errors = watch(page);
    await visit(page, 'agent/mit_timesheet.php', errors);
    await expect(page.locator('.mit-ts-day').first()).toBeVisible();
    const data = JSON.parse(await page.locator('#mitTsData').textContent());
    test.skip(!data.ca, 'the technician is not on California rules');
    test.skip(data.locked !== '', 'this period is locked: ' + data.locked);
    const date = Object.keys(data.days).find((d) => !data.days[d].future && data.days[d].ca && !data.days[d].ca.boxes.start);
    test.skip(!date, 'no past day without times in this period');

    const week = data.weeks.findIndex((w) => w.includes(date));
    await page.click(`#mitTsWeeks [data-week="${week}"]`);
    await page.click(`.mit-ts-day[data-date="${date}"]`);
    const panel = page.locator('#mitTsDay');
    await panel.locator('[data-box="start"]').fill('08:00');
    await panel.locator('[data-box="end"]').fill('14:00');
    await expect(panel.locator('[data-worked]')).toHaveText(/^6(:00)? h$/);
    await expect(panel.locator('[data-issues]')).toContainText('No meal period');
    await expect(panel.locator('[data-mit-day-status]')).toContainText('Saved', { timeout: 10000 });
    await expect(page.locator(`.mit-ts-day[data-date="${date}"]`)).toContainText('8:00 AM - 2:00 PM');

    // Clear it again
    await panel.locator('[data-box="start"]').fill('');
    await panel.locator('[data-box="end"]').fill('');
    await expect(panel.locator('[data-mit-day-status]')).toContainText('Saved', { timeout: 10000 });
    await page.waitForTimeout(300);
    await expect(page.locator(`.mit-ts-day[data-date="${date}"]`)).not.toContainText('8:00 AM');
    await expectClean(page, errors);
});

test('the signing window can be used (nothing covers it)', async ({ page }) => {
    await visit(page, 'agent/mit_timesheet.php');
    const submit = page.locator('[data-mit-ts-submit]');
    test.skip(await submit.count() === 0, 'nothing to sign in this period (submitted, locked or not on California rules)');
    await submit.click();
    const sign = page.locator('#mitCaSign');
    await expect(sign).toBeVisible();
    // A real click - it fails when the grey backdrop is on top of the window
    await sign.locator('#mitCaAccurate').check({ timeout: 5000 });
    await sign.locator('input[name=mit_ca_signature]').fill('test');
    await sign.locator('[data-bs-dismiss="modal"]').last().click();
    await expect(sign).toBeHidden();
});
