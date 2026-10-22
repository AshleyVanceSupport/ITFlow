// Restore from Admin > Backup (functions/mit_backup_restore.php). Every run: a stored backup's Restore window says what is
// in it, a wrong password changes nothing, a file that is not a backup is refused. With ITFLOW_SMOKE_RESTORE=1 only (it
// really replaces the test install's database): restore the newest backup, then the "Before restore" copy it made - the
// install ends as it started - and sign in again for the tests after this one.

const path = require('path');
const fs = require('fs');
const { test, expect } = require('@playwright/test');
const { watch, visit, openModal, flash, expectClean } = require('./helpers');

test.describe.configure({ mode: 'serial' });

// Complete, restorable backups on the page: [{id, source}]
async function backups(page) {
    await visit(page, 'admin/backup.php');
    return page.locator('[data-modal-url*="mit_backup/restore.php?backup_id="]').evaluateAll((buttons) => buttons.map((b) => ({
        id: Number(new URL(b.dataset.modalUrl, location.href).searchParams.get('backup_id')),
        source: b.closest('tr').children[3].textContent.trim(),
    })));
}

async function restore(page, id, password) {
    await visit(page, 'admin/backup.php');
    const modal = await openModal(page, `modals/mit_backup/restore.php?backup_id=${id}`);
    await modal.locator('input[name=password]').fill(password);
    await modal.locator('input[name=confirm]').fill('RESTORE');
    await Promise.all([page.waitForNavigation({ timeout: 300000 }), modal.locator('button[data-mit-restore-go]').click()]);
}

test('restore: the window shows the backup, and a wrong password changes nothing', async ({ page }) => {
    const errors = watch(page);
    const list = await backups(page);
    test.skip(await page.locator('td .badge', { hasText: /^Complete$/ }).count() === 0, 'no complete backup to look at');
    expect(list.length, 'a Restore button on the complete backups').toBeGreaterThan(0);
    const modal = await openModal(page, `modals/mit_backup/restore.php?backup_id=${list[0].id}`);
    await expect(modal, 'what is in it').toContainText(/Contains\s*(Database and uploads|Database only)/);
    await expect(modal, 'the safety copy is promised').toContainText('Before restore');
    const before = list.length;
    await modal.locator('input[name=password]').fill('not the password');
    await modal.locator('input[name=confirm]').fill('RESTORE');
    await Promise.all([page.waitForNavigation(), modal.locator('button[data-mit-restore-go]').click()]);
    expect(await flash(page), 'refused').toContain('Incorrect password - nothing was changed');
    expect((await backups(page)).length, 'no safety backup made, nothing restored').toBe(before);
    await expectClean(page, errors);
});

test('restore: a file that is not a backup is refused', async ({ page }) => {
    const file = path.join(test.info().outputDir, 'not-a-backup.zip');
    fs.mkdirSync(path.dirname(file), { recursive: true });
    fs.writeFileSync(file, 'this is not a zip');
    await visit(page, 'admin/backup.php');
    const form = page.locator('form:has(button[name=mit_backup_upload])');
    await form.locator('input[name=backup_file]').setInputFiles(file);
    await Promise.all([page.waitForNavigation(), form.locator('button[name=mit_backup_upload]').click()]);
    expect(await flash(page)).toContain('not a backup that can be read');
});

test('restore: a backup, then the "Before restore" copy - the install ends as it started', async ({ page, browser }) => {
    test.skip(process.env.ITFLOW_SMOKE_RESTORE !== '1', 'set ITFLOW_SMOKE_RESTORE=1 to restore the test install for real');
    const list = await backups(page);
    test.skip(!list.length, 'no complete backup to restore');

    const password = process.env.ITFLOW_PASSWORD;
    await restore(page, list[0].id, password);
    await expect(page.locator('[data-mit-restored]'), 'restored').toContainText('Backup restored');

    // Signed out: sign in again, find the safety copy it made, restore that
    const signIn = async () => {
        await page.goto('login.php');
        await page.fill('input[name=email]', process.env.ITFLOW_EMAIL);
        await page.fill('input[name=password]', password);
        await Promise.all([page.waitForNavigation(), page.click('button[name=login]')]);
    };
    await signIn();
    const safety = (await backups(page)).filter((b) => b.source === 'Before restore').sort((a, b) => b.id - a.id)[0];
    expect(safety, 'the "Before restore" copy is listed').toBeTruthy();
    await restore(page, safety.id, password);
    await expect(page.locator('[data-mit-restored]'), 'put back').toContainText('Backup restored');
    await expect(page.locator('[data-mit-restored] .alert-warning'), 'database updates ran cleanly').toHaveCount(0);

    // The tests after this one use the saved admin sign-in - make a fresh one
    await signIn();
    await page.context().storageState({ path: path.join(__dirname, '.auth', 'admin.json') });
});
