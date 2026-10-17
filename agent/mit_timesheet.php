<?php

/*
 * Mountaineer IT - a timesheet (functions/mit_timesheets.php): one person's pay period, week by week.
 * Ticket time fills itself in; hours go against charge codes. ?user_id= (Timesheets View to see
 * someone else's) and ?start= (any date in the period).
 */

require_once "includes/inc_all.php";

if (!mitTsReady()) {
    echo '<div class="alert alert-warning">Timesheets need the database update - Admin > Update.</div>';
    require_once "../includes/footer.php";
    exit;
}

$level = mitTsLevel();
$user_id = intval($_GET['user_id'] ?? $session_user_id);
if (!mitTsMaySee($user_id)) {
    $user_id = intval($session_user_id);
}
$period = mitTsPeriodFromRequest($_GET['start'] ?? '');
$people = $level >= 1 ? mitTsUsers([$user_id]) : mitTsUsers([$session_user_id]);
$person = $people[$user_id] ?? null;
if (!$person) {
    echo '<div class="alert alert-warning">That person does not keep a timesheet.</div>';
    require_once "../includes/footer.php";
    exit;
}
$own = $user_id === intval($session_user_id);
$sheet = mitTsSheet($user_id, $period);
$locked = mitTsWhyLocked($sheet);
$codes_all = mitTsCodes(true);
$entries = mitTsEntries([$user_id], $period['start'], $period['end'])[$user_id] ?? [];
$tickets = mitTsTicketMinutes([$user_id], $period['start'], $period['end'])[$user_id] ?? [];
$totals = mitTsTotals([$user_id], $period['start'], $period['end'])[$user_id];
$workday = reportWorkdayHours();
$today = date('Y-m-d');

// Rows: every active code, and archived ones that have hours in this period
$used = [];
foreach ($entries as $by_code) {
    foreach ($by_code as $code_id => $entry) {
        $used[$code_id] = true;
    }
}
$codes = array_filter($codes_all, function ($code) use ($used) {
    return $code['code_archived_at'] === null || isset($used[intval($code['code_id'])]);
});
$holiday_code = 0;
foreach ($codes as $code_id => $code) {
    if ($code['code_archived_at'] === null && strtolower($code['code_name']) === 'holiday') {
        $holiday_code = $code_id;
    }
}
$prev = mitTsPeriodShift($period, -1);
$next = mitTsPeriodShift($period, 1);
$url = function (array $p, $uid = null) use ($user_id) {
    return 'mit_timesheet.php?user_id=' . intval($uid ?? $user_id) . '&start=' . $p['start'];
};
$can_submit = !$locked && in_array($sheet['status'], ['open', 'returned'], true) && ($own || $level >= 2);
$csrf = $_SESSION['csrf_token'];

?>

<div class="card">
    <div class="card-header bg-dark py-2 d-flex flex-wrap align-items-center gap-2">
        <h3 class="card-title mt-2 me-auto"><i class="fas fa-fw fa-business-time me-2"></i>Timesheet<?= $own ? '' : ' · ' . escapeHtml($person['user_name']) ?></h3>
        <?php if ($level >= 1) { ?>
            <select class="form-select form-select-sm w-auto" aria-label="Person" onchange="location.href = 'mit_timesheet.php?start=<?= $period['start'] ?>&user_id=' + this.value">
                <?php foreach ($people as $uid => $p) { ?>
                    <option value="<?= intval($uid) ?>" <?= $uid === $user_id ? 'selected' : '' ?>><?= escapeHtml($p['user_name']) ?></option>
                <?php } ?>
            </select>
        <?php } ?>
        <div class="btn-group">
            <a class="btn btn-light btn-sm" href="<?= $url($prev) ?>" title="Previous period"><i class="fas fa-chevron-left"></i></a>
            <a class="btn btn-light btn-sm fw-semibold" href="<?= $url(mitTsPeriodFor()) ?>" title="This period"><?= escapeHtml($period['label']) ?></a>
            <a class="btn btn-light btn-sm" href="<?= $url($next) ?>" title="Next period"><i class="fas fa-chevron-right"></i></a>
        </div>
        <?php if ($level >= 1) { ?>
            <a class="btn btn-outline-light btn-sm" href="mit_timesheets.php?start=<?= $period['start'] ?>"><i class="fas fa-users me-2"></i>Everyone</a>
        <?php } ?>
    </div>

    <div class="card-body">
        <div class="d-flex flex-wrap align-items-center gap-2 mb-3">
            <?= mitTsBadge($sheet['status']) ?>
            <?php if ($sheet['status'] === 'submitted') { ?>
                <span class="small text-muted">Submitted <?= escapeHtml(date('M j, g:ia', strtotime($sheet['submitted_at']))) ?></span>
            <?php } elseif ($sheet['status'] === 'approved') { ?>
                <span class="small text-muted">Approved by <?= escapeHtml($sheet['decided_by_name'] ?: '-') ?> <?= escapeHtml(date('M j', strtotime($sheet['decided_at']))) ?><?= $sheet['decision_note'] ? ' - ' . escapeHtml($sheet['decision_note']) : '' ?></span>
            <?php } ?>
            <?php if ($locked) { ?><span class="small text-muted ms-auto"><i class="fas fa-lock me-1"></i><?= escapeHtml($locked) ?></span><?php } ?>
        </div>
        <?php if ($sheet['status'] === 'returned') { ?>
            <div class="alert alert-danger py-2"><i class="fas fa-undo me-2"></i>Sent back by <?= escapeHtml($sheet['decided_by_name'] ?: '-') ?>: <?= escapeHtml($sheet['decision_note']) ?></div>
        <?php } ?>

        <div class="row g-2 mb-3">
            <?php foreach ([
                ['Total hours', mitTsHours($totals['total'], false), 'Worked plus leave'],
                ['Billable', mitTsHours($totals['billable'], false), mitTsBillableShare($totals) . ' of hours worked'],
                ['Other ticket time', mitTsHours($totals['other'], false), 'Tickets not billable'],
                ['Admin & other work', mitTsHours($totals['work'], false), 'Charge codes'],
                ['Lost time', mitTsHours($totals['lost'], false), ''],
                ['Leave', mitTsHours($totals['leave_paid'], false), $totals['unpaid'] ? mitTsHours($totals['unpaid']) . ' unpaid' : 'Paid'],
            ] as [$label, $value, $sub]) { ?>
                <div class="col-6 col-md-4 col-xl-2">
                    <div class="border rounded p-2 h-100">
                        <div class="small text-muted"><?= $label ?></div>
                        <div class="fs-5 fw-semibold"><?= $value ?></div>
                        <?php if ($sub !== '') { ?><div class="small text-muted"><?= escapeHtml($sub) ?></div><?php } ?>
                    </div>
                </div>
            <?php } ?>
        </div>

        <form action="post.php" method="post" autocomplete="off" id="mitTsForm">
            <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
            <input type="hidden" name="user_id" value="<?= $user_id ?>">
            <input type="hidden" name="start" value="<?= $period['start'] ?>">

            <?php foreach (mitTsWeeks($period) as $week) { ?>
                <div class="table-responsive mb-3">
                    <table class="table table-sm table-bordered align-middle mb-0 mit-ts-table">
                        <thead>
                            <tr class="text-center small">
                                <th class="text-start" style="min-width: 190px">Week of <?= escapeHtml(date('M j', strtotime($week[0]))) ?></th>
                                <?php foreach ($week as $date) {
                                    $closure = mitTsClosure($date); ?>
                                    <th class="<?= mitTsWorkingDay($date) ? '' : 'bg-body-tertiary' ?> <?= $date === $today ? 'text-primary' : '' ?>" style="min-width: 74px">
                                        <?= date('D', strtotime($date)) ?><br><?= date('M j', strtotime($date)) ?>
                                        <?php if ($closure !== '') { ?><div><span class="badge text-bg-success text-truncate" style="max-width: 70px" title="<?= escapeHtml($closure) ?>"><?= escapeHtml($closure) ?></span></div><?php } ?>
                                    </th>
                                <?php } ?>
                                <th style="min-width: 70px">Week</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach (['billable' => ['Ticket time - billable', 'text-success'], 'other' => ['Ticket time - other', 'text-secondary']] as $kind => [$label, $class]) { ?>
                                <tr>
                                    <td><i class="fas fa-fw fa-life-ring <?= $class ?> me-1"></i><?= $label ?> <span class="small text-muted">(auto)</span></td>
                                    <?php foreach ($week as $date) {
                                        $m = intval($tickets[$date][$kind] ?? 0); ?>
                                        <td class="text-center <?= mitTsWorkingDay($date) ? '' : 'bg-body-tertiary' ?>" data-mit-ts-auto data-date="<?= $date ?>" data-minutes="<?= $m ?>">
                                            <?php if ($m) { ?>
                                                <a href="#" class="ajax-modal" data-modal-url="modals/mit_timesheet/day.php?user_id=<?= $user_id ?>&date=<?= $date ?>" title="The tickets"><?= mitTsHours($m) ?></a>
                                            <?php } ?>
                                        </td>
                                    <?php } ?>
                                    <td class="text-center fw-semibold" data-mit-ts-row-total></td>
                                </tr>
                            <?php } ?>
                            <?php foreach ($codes as $code_id => $code) { ?>
                                <tr>
                                    <td>
                                        <?= mitTsCodeDot($code) ?><?= escapeHtml($code['code_name']) ?>
                                        <?php if (!intval($code['code_paid'])) { ?><span class="badge text-bg-light border ms-1">Unpaid</span><?php } ?>
                                        <?php if ($code['code_archived_at'] !== null) { ?><span class="badge text-bg-light border ms-1">Archived</span><?php } ?>
                                    </td>
                                    <?php foreach ($week as $date) {
                                        $m = intval($entries[$date][$code_id]['minutes'] ?? 0);
                                        $hint = $code_id === $holiday_code && mitTsClosure($date) !== '' && !$m ? mitTsHours($workday * 60) : ''; ?>
                                        <td class="p-1 <?= mitTsWorkingDay($date) ? '' : 'bg-body-tertiary' ?>">
                                            <input type="text" inputmode="decimal" class="form-control form-control-sm text-center" name="cells[<?= $date ?>][<?= $code_id ?>]"
                                                value="<?= mitTsHours($m) ?>" <?= $hint !== '' ? 'placeholder="' . $hint . '" title="Closed - ' . escapeHtml(mitTsClosure($date)) . '"' : '' ?>
                                                data-date="<?= $date ?>" aria-label="<?= escapeHtml($code['code_name'] . ' ' . date('M j', strtotime($date))) ?>" <?= $locked ? 'disabled' : '' ?>>
                                        </td>
                                    <?php } ?>
                                    <td class="text-center fw-semibold" data-mit-ts-row-total></td>
                                </tr>
                            <?php } ?>
                        </tbody>
                        <tfoot>
                            <tr class="fw-semibold">
                                <td>Day total</td>
                                <?php foreach ($week as $date) { ?>
                                    <td class="text-center" data-mit-ts-day="<?= $date ?>" data-expected="<?= mitTsWorkingDay($date) && $date <= $today ? $workday * 60 : 0 ?>"></td>
                                <?php } ?>
                                <td class="text-center" data-mit-ts-week-total></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            <?php } ?>

            <p class="small text-muted">Type hours as 7.5 or 7:30. Ticket time comes from the time logged on ticket replies each day - billable when the ticket is billable. A day under <?= mitTsHours($workday * 60) ?> hours is shown in orange.</p>

            <div class="d-flex flex-wrap align-items-center gap-2 p-3 rounded bg-body-tertiary border">
                <?php if (!$locked) { ?>
                    <button type="submit" name="mit_ts_save" value="save" class="btn btn-primary"><i class="fas fa-save me-2"></i>Save</button>
                <?php } ?>
                <?php if ($can_submit) { ?>
                    <button type="submit" name="mit_ts_save" value="submit" class="btn btn-success"><i class="fas fa-paper-plane me-2"></i>Save and submit for approval</button>
                <?php } ?>
                <?php if ($own && $sheet['status'] === 'submitted') { ?>
                    <a class="btn btn-light border" href="post.php?mit_ts_withdraw=<?= intval($sheet['sheet_id']) ?>&csrf_token=<?= $csrf ?>"><i class="fas fa-undo me-2"></i>Take it back</a>
                <?php } ?>
                <span class="ms-auto"></span>
                <?php if ($level >= 3 && $sheet['status'] !== 'approved') { ?>
                    <a href="#" class="btn btn-success ajax-modal" data-modal-url="modals/mit_timesheet/decide.php?user_id=<?= $user_id ?>&start=<?= $period['start'] ?>&decision=approve"><i class="fas fa-check me-2"></i>Approve</a>
                    <?php if ($sheet['status'] === 'submitted') { ?>
                        <a href="#" class="btn btn-outline-danger ajax-modal" data-modal-url="modals/mit_timesheet/decide.php?user_id=<?= $user_id ?>&start=<?= $period['start'] ?>&decision=return">Send back</a>
                    <?php } ?>
                <?php } ?>
                <?php if ($level >= 3 && $sheet['status'] === 'approved') { ?>
                    <a class="btn btn-light border confirm-link" href="post.php?mit_ts_reopen=<?= intval($sheet['sheet_id']) ?>&csrf_token=<?= $csrf ?>"><i class="fas fa-lock-open me-2"></i>Reopen</a>
                <?php } ?>
            </div>
        </form>
    </div>
</div>

<script>
    (function () {
        const form = document.getElementById('mitTsForm');
        function minutes(text) {
            text = String(text || '').trim().replace(',', '.');
            let m = text.match(/^(\d{1,2}):(\d{1,2})$/);
            if (m) return +m[1] * 60 + +m[2];
            m = text.match(/^(\d{1,2})h\s*(\d{1,2})?m?$/i);
            if (m) return +m[1] * 60 + +(m[2] || 0);
            m = text.match(/^(\d{1,4})\s*m(in)?$/i);
            if (m) return +m[1];
            const n = parseFloat(text);
            return isNaN(n) ? 0 : Math.round(n * 60);
        }
        function hours(m) {
            return m ? String(+(m / 60).toFixed(2)) : '';
        }
        function sum() {
            form.querySelectorAll('.mit-ts-table').forEach(function (table) {
                const days = {};
                let week = 0;
                table.querySelectorAll('tbody tr').forEach(function (row) {
                    let total = 0;
                    row.querySelectorAll('[data-mit-ts-auto], input[data-date]').forEach(function (cell) {
                        const m = cell.matches('input') ? minutes(cell.value) : +cell.dataset.minutes;
                        days[cell.dataset.date] = (days[cell.dataset.date] || 0) + m;
                        total += m;
                    });
                    row.querySelector('[data-mit-ts-row-total]').textContent = hours(total);
                    week += total;
                });
                table.querySelectorAll('[data-mit-ts-day]').forEach(function (cell) {
                    const m = days[cell.dataset.mitTsDay] || 0;
                    cell.textContent = hours(m) || (+cell.dataset.expected ? '0' : '');
                    cell.classList.toggle('text-warning', +cell.dataset.expected > 0 && m < +cell.dataset.expected);
                    cell.classList.toggle('text-danger', m > 1440);
                });
                table.querySelector('[data-mit-ts-week-total]').textContent = hours(week);
            });
        }
        form.addEventListener('input', sum);
        sum();
    })();
</script>

<?php
require_once "../includes/footer.php";
