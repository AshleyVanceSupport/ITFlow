<?php

/*
 * Mountaineer IT - Admin > Timesheets (functions/mit_timesheets.php): ?tab= settings (the company's rules),
 * codes (charge codes) and people (each person's schedule, approver, employee ID, pay type).
 * Handlers in admin/post/mit_timesheets.php.
 */

require_once "includes/inc_all_admin.php";

if (!mitTsReady()) {
    echo '<div class="alert alert-warning">Timesheets need the database update - Admin > Update.</div>';
    require_once "../includes/footer.php";
    exit;
}

$tab = in_array($_GET['tab'] ?? '', ['settings', 'codes', 'people'], true) ? $_GET['tab'] : 'settings';
$settings = mitTsSettings();
[$period_type, $period_anchor] = mitTsPeriodSettings();
$csrf = $_SESSION['csrf_token'];
$roles = [];
$result = mysqli_query($mysqli, "SELECT role_id, role_name FROM user_roles WHERE role_archived_at IS NULL ORDER BY role_name");
while ($row = mysqli_fetch_assoc($result)) {
    $roles[intval($row['role_id'])] = $row['role_name'];
}
$hours = function ($minutes) {
    return $minutes ? mitTsHours($minutes, true, 'decimal') : '';
};
$company_week = mitTsCompanySchedule();

?>

<div class="card">
    <div class="card-header bg-dark py-2">
        <h3 class="card-title mt-2"><i class="fas fa-fw fa-business-time me-2"></i>Timesheets</h3>
        <?php if ($tab === 'codes') { ?>
            <div class="card-tools">
                <button type="button" class="btn btn-primary ajax-modal" data-modal-url="modals/mit_timesheets/charge_code.php"><i class="fas fa-plus me-2"></i>New charge code</button>
            </div>
        <?php } ?>
    </div>
    <div class="card-body pb-0">
        <ul class="nav nav-tabs">
            <li class="nav-item"><a class="nav-link <?= $tab === 'settings' ? 'active' : '' ?>" href="?tab=settings"><i class="fas fa-sliders-h me-2"></i>Settings</a></li>
            <li class="nav-item"><a class="nav-link <?= $tab === 'codes' ? 'active' : '' ?>" href="?tab=codes"><i class="fas fa-tags me-2"></i>Charge codes</a></li>
            <li class="nav-item"><a class="nav-link <?= $tab === 'people' ? 'active' : '' ?>" href="?tab=people"><i class="fas fa-users me-2"></i>People</a></li>
        </ul>
    </div>

    <?php if ($tab === 'settings') { ?>
        <div class="card-body">
            <form action="post.php" method="post" autocomplete="off">
                <input type="hidden" name="csrf_token" value="<?= $csrf ?>">

                <h6 class="text-muted text-uppercase small mb-3">Pay period</h6>
                <div class="row g-3 mb-4">
                    <div class="col-md-4">
                        <label class="form-label">Timesheets cover</label>
                        <select class="form-select" name="period">
                            <?php foreach (MIT_TS_PERIODS as $key => $label) { ?>
                                <option value="<?= $key ?>" <?= $key === $period_type ? 'selected' : '' ?>><?= escapeHtml($label) ?></option>
                            <?php } ?>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">First day of a pay period <span class="text-muted small">(every two weeks)</span></label>
                        <input type="date" class="form-control" name="anchor" value="<?= escapeHtml($period_anchor) ?>">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Weeks start on</label>
                        <select class="form-select" name="week_start">
                            <option value="1" <?= $settings['week_start'] === 1 ? 'selected' : '' ?>>Monday</option>
                            <option value="7" <?= $settings['week_start'] === 7 ? 'selected' : '' ?>>Sunday</option>
                        </select>
                        <div class="form-text">For the grid and weekly overtime.</div>
                    </div>
                </div>

                <h6 class="text-muted text-uppercase small mb-3">Approval and locking</h6>
                <div class="row g-3 mb-4">
                    <div class="col-md-4">
                        <div class="form-check form-switch mt-md-4">
                            <input class="form-check-input" type="checkbox" role="switch" name="approval" value="1" id="mitTsApproval" <?= $settings['approval'] ? 'checked' : '' ?>>
                            <label class="form-check-label" for="mitTsApproval">Timesheets are submitted and approved</label>
                        </div>
                        <div class="form-text">Off: hours just count, nothing to submit. Each person's approver is set on the People tab.</div>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Hours on days still to come</label>
                        <select class="form-select" name="future">
                            <option value="all" <?= $settings['future'] === 'all' ? 'selected' : '' ?>>Any code</option>
                            <option value="leave" <?= $settings['future'] === 'leave' ? 'selected' : '' ?>>Leave only (book time off ahead)</option>
                            <option value="none" <?= $settings['future'] === 'none' ? 'selected' : '' ?>>None</option>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Lock a period after it ends</label>
                        <div class="input-group">
                            <input type="number" min="0" max="365" class="form-control" name="autolock_days" value="<?= intval($settings['autolock_days']) ?>">
                            <span class="input-group-text">days</span>
                        </div>
                        <div class="form-text">0 = never. After that only managers (Timesheets Edit, or their approver) can change it.</div>
                    </div>
                </div>

                <h6 class="text-muted text-uppercase small mb-3">Reminders</h6>
                <div class="row g-3 mb-4">
                    <div class="col-md-4">
                        <div class="form-check form-switch mt-md-4">
                            <input class="form-check-input" type="checkbox" role="switch" name="remind_due" value="1" id="mitTsRemindDue" <?= $settings['remind_due'] ? 'checked' : '' ?>>
                            <label class="form-check-label" for="mitTsRemindDue">On a pay period's last day</label>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">When still not submitted</label>
                        <div class="input-group">
                            <input type="number" min="0" max="60" class="form-control" name="remind_late_days" value="<?= intval($settings['remind_late_days']) ?>">
                            <span class="input-group-text">days after it ends</span>
                        </div>
                        <div class="form-text">0 = off. Needs approval on.</div>
                    </div>
                    <div class="col-md-4 small text-muted pt-md-4">A notification and an email, once each. People can turn them off in Account > Preferences.</div>
                </div>

                <h6 class="text-muted text-uppercase small mb-3">Hours</h6>
                <div class="row g-3 mb-4">
                    <div class="col-md-4">
                        <label class="form-label">Shown as</label>
                        <select class="form-select" name="format">
                            <option value="decimal" <?= $settings['format'] === 'decimal' ? 'selected' : '' ?>>Decimal - 7.5</option>
                            <option value="hm" <?= $settings['format'] === 'hm' ? 'selected' : '' ?>>Hours and minutes - 7:30</option>
                        </select>
                        <div class="form-text">People can pick their own. Reports and exports stay decimal.</div>
                    </div>
                    <div class="col-md-8">
                        <label class="form-label">Normal week <span class="text-muted small">(hours a day - people can have their own on the People tab)</span></label>
                        <div class="d-flex flex-wrap gap-2">
                            <?php foreach (MIT_TS_WEEKDAYS as $n => $day) { ?>
                                <div style="width: 64px">
                                    <div class="small text-muted text-center"><?= $day ?></div>
                                    <input type="text" inputmode="decimal" class="form-control form-control-sm text-center" name="schedule[<?= $n ?>]" value="<?= $settings['schedule'] === '' ? '' : $hours($company_week[$n]) ?>" placeholder="<?= $hours($company_week[$n]) ?: '0' ?>" aria-label="<?= $day ?>">
                                </div>
                            <?php } ?>
                        </div>
                        <div class="form-text">Empty = the business days and hours in Admin > SLA (shown faintly). Used for short days, unaccounted time and filling closure days.</div>
                    </div>
                </div>

                <h6 class="text-muted text-uppercase small mb-3">Ticket time</h6>
                <div class="row g-3 mb-4">
                    <div class="col-md-4">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" role="switch" name="ticket_time" value="1" id="mitTsTicketTime" <?= $settings['ticket_time'] ? 'checked' : '' ?>>
                            <label class="form-check-label" for="mitTsTicketTime">Fill in ticket time</label>
                        </div>
                        <div class="form-check form-switch mt-2">
                            <input class="form-check-input" type="checkbox" role="switch" name="ticket_internal" value="1" id="mitTsInternal" <?= $settings['ticket_internal'] ? 'checked' : '' ?>>
                            <label class="form-check-label" for="mitTsInternal">Count time on internal notes</label>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Billable is</label>
                        <select class="form-select" name="billable">
                            <option value="flag" <?= $settings['billable'] === 'flag' ? 'selected' : '' ?>>Tickets marked billable</option>
                            <option value="client" <?= $settings['billable'] === 'client' ? 'selected' : '' ?>>Every ticket for a client</option>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Round each day's ticket time to</label>
                        <select class="form-select" name="rounding">
                            <?php foreach ([0 => 'No rounding', 6 => 'Nearest 6 minutes (0.1 h)', 15 => 'Nearest 15 minutes', 30 => 'Nearest 30 minutes'] as $step => $label) { ?>
                                <option value="<?= $step ?>" <?= $settings['rounding'] === $step ? 'selected' : '' ?>><?= $label ?></option>
                            <?php } ?>
                        </select>
                    </div>
                </div>

                <h6 class="text-muted text-uppercase small mb-3">Overtime and lost time</h6>
                <div class="row g-3 mb-4">
                    <div class="col-md-4">
                        <label class="form-label">Overtime over</label>
                        <div class="input-group">
                            <input type="text" inputmode="decimal" class="form-control" name="ot_day" value="<?= $hours($settings['ot_day']) ?>" placeholder="off" aria-label="Hours a day">
                            <span class="input-group-text">h a day</span>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">&nbsp;</label>
                        <div class="input-group">
                            <input type="text" inputmode="decimal" class="form-control" name="ot_week" value="<?= $hours($settings['ot_week']) ?>" placeholder="off" aria-label="Hours a week">
                            <span class="input-group-text">h a week</span>
                        </div>
                        <div class="form-text">Empty = no overtime. Salaried people (People tab) never get overtime.</div>
                    </div>
                    <div class="col-md-4">
                        <div class="form-check form-switch mt-md-4">
                            <input class="form-check-input" type="checkbox" role="switch" name="auto_lost" value="1" id="mitTsAutoLost" <?= $settings['auto_lost'] ? 'checked' : '' ?>>
                            <label class="form-check-label" for="mitTsAutoLost">Normal hours nothing accounts for are lost time</label>
                        </div>
                        <div class="form-text">For days already over - shown on the timesheet as "Unaccounted".</div>
                    </div>
                </div>

                <button type="submit" name="mit_ts_settings_save" class="btn btn-primary mb-3"><i class="fas fa-check me-2"></i>Save settings</button>
            </form>
        </div>

    <?php } elseif ($tab === 'codes') {
        $show_archived = !empty($_GET['archived']);
        $codes = array_filter(mitTsCodes(true), function ($code) use ($show_archived) {
            return ($code['code_archived_at'] !== null) === $show_archived;
        });
        $used = [];
        $result = mysqli_query($mysqli, "SELECT code_id, COUNT(*) AS n FROM mit_time_entries WHERE code_id > 0 GROUP BY code_id");
        while ($row = mysqli_fetch_assoc($result)) {
            $used[intval($row['code_id'])] = intval($row['n']);
        } ?>
        <div class="card-body pb-0">
            <p class="text-muted">What people put hours against, besides ticket time. <strong>Work</strong> and <strong>Lost time</strong> count as hours worked; <strong>Leave</strong> is time off. Unpaid codes are left out of paid hours.</p>
            <div class="mb-2">
                <a class="btn btn-sm <?= $show_archived ? 'btn-light border' : 'btn-secondary' ?>" href="?tab=codes">Active</a>
                <a class="btn btn-sm <?= $show_archived ? 'btn-secondary' : 'btn-light border' ?>" href="?tab=codes&archived=1">Archived</a>
            </div>
        </div>
        <div class="table-responsive-sm">
            <table class="table table-striped table-borderless table-hover mb-0 align-middle">
                <thead class="text-dark">
                    <tr><th class="ps-3">Name</th><th>Kind</th><th>Paid</th><th>Who</th><th>Rules</th><th>Used</th><th class="text-center">Action</th></tr>
                </thead>
                <tbody>
                    <?php if (!$codes) { ?>
                        <tr><td colspan="7" class="text-center text-muted py-4"><?= $show_archived ? 'Nothing archived.' : 'No charge codes yet.' ?></td></tr>
                    <?php } ?>
                    <?php foreach ($codes as $code_id => $code) {
                        $code_roles = array_filter(array_map('intval', explode(',', (string) $code['code_roles'])));
                        $rules = [];
                        if (intval($code['code_note_required'])) {
                            $rules[] = 'Needs a note';
                        }
                        if (intval($code['code_daily_max'])) {
                            $rules[] = 'At most ' . mitTsHours($code['code_daily_max'], false, 'decimal') . ' h a day';
                        }
                        if (intval($code['code_closure_fill'])) {
                            $rules[] = 'Fills closure days';
                        } ?>
                        <tr>
                            <td class="ps-3">
                                <a class="text-dark text-bold ajax-modal" href="#" data-modal-url="modals/mit_timesheets/charge_code.php?id=<?= intval($code_id) ?>"><?= mitTsCodeDot($code) ?><?= escapeHtml($code['code_name']) ?></a>
                            </td>
                            <td><?= escapeHtml(MIT_TS_KINDS[$code['code_kind']] ?? $code['code_kind']) ?></td>
                            <td><?= intval($code['code_paid']) ? 'Paid' : '<span class="badge text-bg-light border">Unpaid</span>' ?></td>
                            <td class="small"><?= $code_roles ? escapeHtml(implode(', ', array_map(function ($id) use ($roles) { return $roles[$id] ?? "Role $id"; }, $code_roles))) : '<span class="text-muted">Everyone</span>' ?></td>
                            <td class="small"><?= $rules ? escapeHtml(implode(' · ', $rules)) : '<span class="text-muted">-</span>' ?></td>
                            <td class="text-muted small"><?= isset($used[$code_id]) ? $used[$code_id] . ' entr' . ($used[$code_id] === 1 ? 'y' : 'ies') : 'Not used yet' ?></td>
                            <td>
                                <div class="d-flex justify-content-center gap-1">
                                    <?php if (!$show_archived) { ?>
                                        <a class="btn btn-light btn-sm" href="post.php?mit_charge_code_move=<?= intval($code_id) ?>&dir=up&csrf_token=<?= $csrf ?>" title="Move up"><i class="fas fa-arrow-up"></i></a>
                                        <a class="btn btn-light btn-sm" href="post.php?mit_charge_code_move=<?= intval($code_id) ?>&dir=down&csrf_token=<?= $csrf ?>" title="Move down"><i class="fas fa-arrow-down"></i></a>
                                        <a class="btn btn-light btn-sm confirm-link" href="post.php?mit_charge_code_archive=<?= intval($code_id) ?>&csrf_token=<?= $csrf ?>" title="Archive - hours already on it stay"><i class="fas fa-archive"></i></a>
                                    <?php } else { ?>
                                        <a class="btn btn-light btn-sm" href="post.php?mit_charge_code_restore=<?= intval($code_id) ?>&csrf_token=<?= $csrf ?>" title="Restore"><i class="fas fa-redo-alt"></i></a>
                                    <?php } ?>
                                    <?php if (!isset($used[$code_id])) { ?>
                                        <a class="btn btn-light btn-sm text-danger confirm-link" href="post.php?mit_charge_code_delete=<?= intval($code_id) ?>&csrf_token=<?= $csrf ?>" title="Delete (never used)"><i class="fas fa-trash"></i></a>
                                    <?php } ?>
                                </div>
                            </td>
                        </tr>
                    <?php } ?>
                </tbody>
            </table>
        </div>

    <?php } else {
        $people = mitTsUsers();
        $result = mysqli_query($mysqli, "SELECT user_id, role_name FROM users LEFT JOIN user_roles ON role_id = user_role_id WHERE user_type = 1");
        $user_roles = [];
        while ($row = mysqli_fetch_assoc($result)) {
            $user_roles[intval($row['user_id'])] = $row['role_name'];
        } ?>
        <div class="card-body pb-0">
            <p class="text-muted">Each person's normal week (for short days, unaccounted time and overtime), who approves their timesheets, and what payroll needs. Leave the week empty to use the company's.</p>
        </div>
        <div class="table-responsive-sm">
            <table class="table table-striped table-borderless table-hover mb-0 align-middle">
                <thead class="text-dark">
                    <tr><th class="ps-3">Person</th><th>Role</th><th>Normal week</th><th>Approver</th><th>Employee ID</th><th>Pay</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($people as $user_id => $person) {
                        $details = mitTsPerson($user_id);
                        $week = mitTsSchedule($user_id);
                        $approver = $details['approver_id'] ? ($people[$details['approver_id']]['user_name'] ?? 'Someone no longer here') : ''; ?>
                        <tr>
                            <td class="ps-3"><a class="text-dark text-bold ajax-modal" href="#" data-modal-url="modals/mit_timesheets/person.php?id=<?= intval($user_id) ?>"><?= escapeHtml($person['user_name']) ?></a></td>
                            <td class="small"><?= escapeHtml($user_roles[$user_id] ?? '-') ?></td>
                            <td class="small">
                                <?= mitTsHours(array_sum($week), false, 'decimal') ?> h ·
                                <?= escapeHtml(implode(' ', array_map(function ($n) use ($week) { return $week[$n] ? MIT_TS_WEEKDAYS[$n] : ''; }, range(1, 7)))) ?>
                                <?php if ($details['schedule'] === '') { ?><span class="text-muted">(company)</span><?php } ?>
                            </td>
                            <td class="small"><?= $approver !== '' ? escapeHtml($approver) : '<span class="text-muted">Anyone with Full access</span>' ?></td>
                            <td class="small font-monospace"><?= escapeHtml($details['employee_id'] ?: '-') ?></td>
                            <td class="small"><?= $details['pay_type'] === 'salary' ? 'Salaried' : 'Hourly' ?></td>
                        </tr>
                    <?php } ?>
                </tbody>
            </table>
        </div>
    <?php } ?>
</div>

<?php
require_once "../includes/footer.php";
