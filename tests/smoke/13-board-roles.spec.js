// Service boards by role (functions/mit_board_roles.php): the Technician's role (ITFLOW_TECH_EMAIL) is limited to one
// board in Admin > Roles > Edit. As the technician, a ticket on another board is left out of the Tickets list, global
// search and the Dispatch queue - unless it is assigned to them or they escalated it there - and New Ticket doesn't offer
// that board; a direct link still opens it. The admin still sees everything. The role's setting is put back and the
// client and tickets ("Smoke test ...") removed at the end, even when a step fails.

const fs = require('fs');
const path = require('path');
const { test, expect } = require('@playwright/test');
const { smokeName, watch, visit, openModal, fillForm, submit, flash, action, getJson, expectClean } = require('./helpers');

const techState = path.join(__dirname, '.auth', 'tech.json');
test.skip(!fs.existsSync(techState), 'no ITFLOW_TECH_EMAIL / ITFLOW_TECH_PASSWORD given');
test.describe.configure({ mode: 'serial' });

const made = { clientName: smokeName('client'), clientId: 0, roleId: 0, restore: null, hidden: 0, shown: 0, tickets: {} };

async function techPage(browser) {
    const use = test.info().project.use;
    const context = await browser.newContext({ storageState: techState, baseURL: use.baseURL, ignoreHTTPSErrors: use.ignoreHTTPSErrors });
    return context.newPage();
}

// The role form's Service boards section
async function roleBoards(page, roleId) {
    await page.goto('admin/roles.php');
    const modal = await openModal(page, `modals/role/role_edit.php?id=${roleId}`, 'lg');
    const values = await modal.evaluate((root) => ({
        present: !!root.querySelector('[name=mit_boards_present]'),
        mit_boards_all: !!(root.querySelector('[name=mit_boards_all]') || {}).checked,
        'mit_boards[]': [...root.querySelectorAll('[name="mit_boards[]"]:checked')].map((c) => c.value),
    }));
    return { modal, values };
}

async function setRoleBoards(page, roleId, all, boards) {
    const { modal } = await roleBoards(page, roleId);
    await modal.evaluate((root, [all, boards]) => {
        const s = root.querySelector('[name=mit_boards_all]');
        s.checked = all;
        s.dispatchEvent(new Event('change'));
        root.querySelectorAll('[name="mit_boards[]"]').forEach((c) => { c.checked = boards.includes(c.value); });
    }, [all, boards]);
    await submit(modal, 'edit_role');
}

async function addTicket(page, name, values) {
    const modal = await openModal(page, `modals/ticket/ticket_add.php?client_id=${made.clientId}`, 'lg');
    await fillForm(modal, { subject: smokeName(name), details: 'Opened by the smoke tests.', priority: 'Low', ...values });
    await submit(modal, 'add_ticket');
    const id = Number(new URL(page.url()).searchParams.get('ticket_id'));
    expect(id, `ticket "${name}" saved (${await flash(page)})`).toBeGreaterThan(0);
    return id;
}

test('board roles: limit the Technician role to one board', async ({ page }) => {
    const errors = watch(page);
    await visit(page, 'admin/mit_boards.php', errors);
    test.skip(await page.locator('text=need the database update').count() > 0, 'Boards not set up');
    const others = [];
    for (const href of await page.locator('a[href*="mit_board_default="]').evaluateAll((links) => links.map((a) => a.href))) {
        others.push(new URL(href).searchParams.get('mit_board_default'));
    }
    expect(others.length, 'at least two boards besides the default').toBeGreaterThanOrEqual(2);
    [made.hidden, made.shown] = others;

    // The technician's role, from their user form
    await visit(page, `admin/users.php?q=${encodeURIComponent(process.env.ITFLOW_TECH_EMAIL)}`, errors);
    const quick = await page.locator('[data-modal-url*="modals/user/user.php?id="]').first().getAttribute('data-modal-url');
    const userId = new URL(quick, page.url()).searchParams.get('id');
    const userModal = await openModal(page, `modals/user/user_edit.php?id=${userId}`, 'lg');
    made.roleId = Number(await userModal.locator('select[name=role]').inputValue());
    expect(made.roleId).toBeGreaterThan(0);

    const { values } = await roleBoards(page, made.roleId);
    test.skip(!values.present, 'needs database update M034');
    made.restore = values;
    await setRoleBoards(page, made.roleId, false, [made.shown]);
    const after = (await roleBoards(page, made.roleId)).values;
    expect(after.mit_boards_all, 'saved as limited').toBe(false);
    expect(after['mit_boards[]'], 'only the one board ticked').toEqual([made.shown]);

    // Admin > Settings > Boards says who sees each board
    await visit(page, 'admin/mit_boards.php', errors);
    await expect(page.locator(`tr:has([data-mit-board="${made.hidden}"]) [data-mit-seen-by]`), 'Seen by on the hidden board').not.toContainText('Every role');

    // A client with three tickets on the hidden board: one plain, one assigned to the technician, one the technician escalates
    await visit(page, 'agent/clients.php');
    const modal = await openModal(page, 'modals/client/client_add.php', 'lg');
    await fillForm(modal, { name: made.clientName, contact: 'Smoke Contact' });
    await submit(modal, 'add_client');
    await visit(page, `agent/clients.php?q=${encodeURIComponent(made.clientName)}`);
    const href = await page.locator('a[href*="client_id="]', { hasText: made.clientName }).first().getAttribute('href');
    made.clientId = Number(new URL(href, page.url()).searchParams.get('client_id'));
    expect(made.clientId).toBeGreaterThan(0);
    made.tickets.plain = await addTicket(page, 'ticket hidden', { mit_board: made.hidden });
    made.tickets.assigned = await addTicket(page, 'ticket assigned', { mit_board: made.hidden, assigned_to: userId });
    made.tickets.escalated = await addTicket(page, 'ticket escalated', { mit_board: made.shown });
    await expectClean(page, errors);
});

test('board roles: the technician sees only their boards and their own tickets', async ({ page: adminPage, browser }) => {
    test.skip(!made.tickets.escalated, 'not set up');
    const page = await techPage(browser);
    const errors = watch(page);
    const ids = made.tickets;
    const listed = () => page.locator('a[href*="ticket_id="]').evaluateAll((links) => links.map((a) => Number(new URL(a.href).searchParams.get('ticket_id'))));

    // The technician escalates one onto the hidden board - it stays theirs to see
    await visit(page, `agent/ticket.php?client_id=${made.clientId}&ticket_id=${ids.escalated}`, errors);
    const modal = await openModal(page, `modals/mit_board/board_move.php?escalate=1&ticket_id=${ids.escalated}`);
    await expect(modal.locator(`select[name=board_id] option[value="${made.hidden}"]`), 'Escalate offers every board').toHaveCount(1);
    await fillForm(modal, { board_id: made.hidden, reason: 'Smoke test escalation by the technician' });
    await submit(modal, 'mit_board_move');

    await visit(page, `agent/tickets.php?client_id=${made.clientId}`, errors);
    let found = await listed();
    expect(found, 'a ticket on a hidden board is not listed').not.toContain(ids.plain);
    expect(found, 'one assigned to them is').toContain(ids.assigned);
    expect(found, 'one they escalated is').toContain(ids.escalated);
    await expect(page.locator(`a[href*="board=${made.hidden}"]`), 'the hidden board is not in the switcher').toHaveCount(0);

    await visit(page, `agent/tickets.php?board=${made.hidden}&client_id=${made.clientId}`, errors);
    expect(await listed(), 'asking for the hidden board by address does not show it').not.toContain(ids.plain);

    await visit(page, `agent/global_search.php?query=${encodeURIComponent(smokeName('ticket hidden').replace(/ \d+$/, ''))}`, errors);
    found = await listed();
    expect(found, 'not in global search').not.toContain(ids.plain);

    const dispatch = await page.goto('agent/mit_dispatch.php');
    if (dispatch.status() < 400 && !page.url().includes('dashboard')) {
        const queue = await getJson(page, '/agent/mit_dispatch_ajax.php?queue=unassigned&board=all');
        expect((queue.tickets || queue).map((t) => Number(t.id)), 'not in the Dispatch queue').not.toContain(ids.plain);
    }

    await visit(page, `agent/client_overview.php?client_id=${made.clientId}`, errors);
    const add = await openModal(page, `modals/ticket/ticket_add.php?client_id=${made.clientId}`, 'lg');
    await expect(add.locator(`select[name=mit_board] option[value="${made.hidden}"]`), 'New Ticket does not offer the hidden board').toHaveCount(0);
    await expect(add.locator(`select[name=mit_board] option[value="${made.shown}"]`), 'but offers theirs').toHaveCount(1);

    // Hidden, not blocked
    await visit(page, `agent/ticket.php?client_id=${made.clientId}&ticket_id=${ids.plain}`, errors);
    await expect(page.locator('body'), 'a direct link still opens it').toContainText('ticket hidden');
    await expectClean(page, errors);
    await page.context().close();

    // The admin sees it all
    await visit(adminPage, `agent/tickets.php?client_id=${made.clientId}`);
    expect(await adminPage.locator(`a[href*="ticket_id=${ids.plain}"]`).count(), 'the admin still sees the hidden board').toBeGreaterThan(0);
});

test.afterAll(async ({ browser }) => {
    const use = test.info().project.use;
    const context = await browser.newContext({ storageState: use.storageState, baseURL: use.baseURL, ignoreHTTPSErrors: use.ignoreHTTPSErrors });
    const page = await context.newPage();
    if (made.restore) {
        await setRoleBoards(page, made.roleId, made.restore.mit_boards_all, made.restore['mit_boards[]']).catch(() => {});
    }
    for (const id of Object.values(made.tickets)) {
        await action(page, `delete_ticket=${id}`, `agent/ticket.php?client_id=${made.clientId}&ticket_id=${id}`).catch(() => {});
    }
    if (made.clientId) {
        await action(page, `delete_client=${made.clientId}`, `agent/client_overview.php?client_id=${made.clientId}`).catch(() => {});
    }
    await context.close();
});
