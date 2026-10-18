// California non-exempt timesheets (functions/mit_ts_california.php), as the Technician (ITFLOW_TECH_EMAIL,
// who must be hourly / on California rules). Read-only: times are typed into the page to see the hours and
// meal checks worked out, never saved.

const fs = require('fs');
const path = require('path');
const { test, expect } = require('@playwright/test');
const { watch, visit, expectClean } = require('./helpers');

const techState = path.join(__dirname, '.auth', 'tech.json');
test.skip(!fs.existsSync(techState), 'no ITFLOW_TECH_EMAIL / ITFLOW_TECH_PASSWORD given');
test.use({ storageState: techState });

test('hours worked card works out hours and meal periods', async ({ page }) => {
    const errors = watch(page);
    await visit(page, 'agent/mit_timesheet.php', errors);
    test.skip(await page.locator('#mitTsCa').count() === 0, 'the technician is not on California rules');
    const row = page.locator('tr[data-mit-ca-day]').first();
    // Typed in by script so a locked period still works - nothing is saved
    const type = (values) => row.evaluate((tr, values) => {
        for (const [box, value] of Object.entries(values)) {
            const input = tr.querySelector(`input[data-box="${box}"]`);
            input.value = value;
            input.dispatchEvent(new Event('input', { bubbles: true }));
        }
    }, values);

    await type({ start: '08:00', meal_out: '12:00', meal_in: '12:30', meal2_out: '', meal2_in: '', end: '17:00' });
    await expect(row.locator('[data-mit-ca-worked]')).toHaveText(/^8(\.5|:30)$/);
    await expect(row.locator('[data-mit-ca-meal="1"]')).toBeHidden();

    await type({ meal_out: '', meal_in: '', end: '14:00' });
    await expect(row.locator('[data-mit-ca-meal="1"]')).toBeVisible();
    await expect(row.locator('[data-mit-ca-meal="1"] [data-mit-ca-issue]')).toContainText('No 1st meal period');

    await type({ meal_out: '13:10', meal_in: '13:40', end: '15:00' });
    await expect(row.locator('[data-mit-ca-meal="1"] [data-mit-ca-issue]')).toContainText('after the end of the 5th hour');
    await expectClean(page, errors);
});
