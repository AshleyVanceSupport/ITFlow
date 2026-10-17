<?php

/*
 * Mountaineer IT - everyone's timesheets for one pay period (functions/mit_timesheets.php): hours
 * by kind, billable share, status. Timesheets View (or being someone's approver) to see it; Full
 * access, or the person's approver, ticks and approves submitted ones. Anyone who has left but has
 * hours in the period is listed too. ?start= any date in the period.
 */

require_once "includes/inc_all.php";

mitModuleRequire('timesheets');

if (!mitTsReady()) {
    echo '<div class="alert alert-warning">Timesheets need the database update - Admin > Update.</div>';
    require_once "../includes/footer.php";
    exit;
}
if (mitTsLevel() < 1 && !mitTsMyReports()) {
    enforceUserPermission('module_timesheets');
}

$settings = mitTsSettings();
$approval = $settings['approval'];
$overtime = $settings['ot_day'] || $settings['ot_week'];
$period = mitTsPeriodFromRequest($_GET['start'] ?? '');
$people = mitTsVisibleUsers($period);
$sheets = mitTsSheets(array_keys($people), $period);
$totals = mitTsTotals(array_keys($people), $period['start'], $period['end']);
$prev = mitTsPeriodShift($period, -1);
$next = mitTsPeriodShift($period, 1);
$sum = ['billable' => 0, 'other' => 0, 'work' => 0, 'lost' => 0, 'leave_paid' => 0, 'unpaid' => 0, 'worked' => 0, 'leave' => 0, 'total' => 0, 'overtime' => 0];
foreach ($totals as $t) {
    foreach ($sum as $key => $value) {
        $sum[$key] += $t[$key];
    }
}
$waiting = $approval ? count(array_filter($sheets, function ($sheet) {
    return $sheet['status'] === 'submitted';
})) : 0;
$can_approve = [];
foreach ($people as $user_id => $person) {
    $can_approve[$user_id] = $approval && mitTsMayApprove($user_id) && ($user_id !== intval($session_user_id) || mitTsLevel() >= 3);
}
$bulk = in_array(true, $can_approve, true);

?>

<div class="card">
    <div class="card-header bg-dark py-2 d-flex flex-wrap align-items-center gap-2">
        <h3 class="card-title mt-2 me-auto"><i class="fas fa-fw fa-users me-2"></i>Timesheets</h3>
        <div class="btn-group">
            <a class="btn btn-light btn-sm" href="?start=<?= $prev['start'] ?>" title="Previous period"><i class="fas fa-chevron-left"></i></a>
            <a class="btn btn-light btn-sm fw-semibold" href="?start=<?= mitTsPeriodFor()['start'] ?>" title="This period"><?= escapeHtml($period['label']) ?></a>
            <a class="btn btn-light btn-sm" href="?start=<?= $next['start'] ?>" title="Next period"><i class="fas fa-chevron-right"></i></a>
        </div>
        <?php if (reportCan(mitTsReportEntry())) { ?>
            <a class="btn btn-outline-light btn-sm" href="reports/mit_payroll.php?period=custom&from=<?= $period['start'] ?>&to=<?= $period['end'] ?>"><i class="fas fa-file-invoice-dollar me-2"></i>Payroll report</a>
        <?php } ?>
    </div>
    <div class="card-body">
        <p class="text-muted"><?= count($people) ?> people · <?= mitTsHours($sum['total'], false) ?> hours · <?= mitTsBillableShare($sum) ?> of hours worked billable · <?= mitTsHours($sum['lost'], false) ?> lost<?= $waiting ? " · <strong>$waiting waiting for approval</strong>" : '' ?></p>
        <form action="post.php" method="post">
            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
            <input type="hidden" name="start" value="<?= $period['start'] ?>">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="text-nowrap small">
                        <tr>
                            <?php if ($bulk) { ?><th style="width: 36px"></th><?php } ?>
                            <th>Person</th>
                            <th class="text-end">Billable</th>
                            <th class="text-end">Other tickets</th>
                            <th class="text-end">Admin &amp; work</th>
                            <th class="text-end">Lost</th>
                            <th class="text-end">Leave</th>
                            <th class="text-end">Total</th>
                            <?php if ($overtime) { ?><th class="text-end">Overtime</th><?php } ?>
                            <th class="text-end">Billable %</th>
                            <?php if ($approval) { ?><th>Status</th><?php } ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($people as $user_id => $person) {
                            $t = $totals[$user_id];
                            $status = $sheets[$user_id]['status'] ?? 'open'; ?>
                            <tr>
                                <?php if ($bulk) { ?>
                                    <td><input class="form-check-input" type="checkbox" name="user_ids[]" value="<?= intval($user_id) ?>" <?= $status === 'submitted' && $can_approve[$user_id] ? '' : 'disabled' ?> aria-label="Approve <?= escapeHtml($person['user_name']) ?>"></td>
                                <?php } ?>
                                <td><a href="mit_timesheet.php?user_id=<?= intval($user_id) ?>&start=<?= $period['start'] ?>"><?= escapeHtml($person['user_name']) ?></a>
                                    <?php if (intval($person['user_status']) !== 1) { ?><span class="badge text-bg-secondary ms-1">Inactive</span><?php } ?></td>
                                <td class="text-end"><?= mitTsHours($t['billable'], false) ?></td>
                                <td class="text-end"><?= mitTsHours($t['other'], false) ?></td>
                                <td class="text-end"><?= mitTsHours($t['work'], false) ?></td>
                                <td class="text-end <?= $t['lost'] ? 'text-danger' : '' ?>"><?= mitTsHours($t['lost'], false) ?></td>
                                <td class="text-end"><?= mitTsHours($t['leave'], false) ?></td>
                                <td class="text-end fw-semibold"><?= mitTsHours($t['total'], false) ?></td>
                                <?php if ($overtime) { ?><td class="text-end"><?= mitTsHours($t['overtime'], false) ?></td><?php } ?>
                                <td class="text-end"><?= mitTsBillableShare($t) ?></td>
                                <?php if ($approval) { ?><td><?= mitTsBadge($status) ?></td><?php } ?>
                            </tr>
                        <?php } ?>
                    </tbody>
                </table>
            </div>
            <?php if ($bulk && $waiting) { ?>
                <div class="d-flex mt-3">
                    <button type="submit" name="mit_ts_bulk_approve" class="btn btn-success ms-auto"><i class="fas fa-check-double me-2"></i>Approve ticked</button>
                </div>
            <?php } ?>
        </form>
    </div>
</div>

<?php
require_once "../includes/footer.php";
