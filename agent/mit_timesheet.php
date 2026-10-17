<?php

/*
 * Mountaineer IT - a timesheet (functions/mit_timesheets.php): one person's pay period, week by week.
 * Ticket time fills itself in; hours go against charge codes, with notes. ?user_id= (Timesheets View,
 * or their approver, to see someone else's) and ?start= (any date in the period).
 */

require_once "includes/inc_all.php";

mitModuleRequire('timesheets');

if (!mitTsReady()) {
    echo '<div class="alert alert-warning">Timesheets need the database update - Admin > Update.</div>';
    require_once "../includes/footer.php";
    exit;
}

$settings = mitTsSettings();
$user_id = intval($_GET['user_id'] ?? $session_user_id);
if (!mitTsMaySee($user_id)) {
    $user_id = intval($session_user_id);
}
$period = mitTsPeriodFromRequest($_GET['start'] ?? '');
// The person picker: who this agent may look at (with anyone who has hours in this period), and themselves
$people = mitTsVisibleUsers($period) + mitTsUsers([$session_user_id, $user_id]);
uasort($people, function ($a, $b) {
    return strcasecmp($a['user_name'], $b['user_name']);
});
$person = $people[$user_id] ?? null;
if (!$person) {
    echo '<div class="alert alert-warning">That person does not keep a timesheet.</div>';
    require_once "../includes/footer.php";
    exit;
}
$own = $user_id === intval($session_user_id);
// Approving your own timesheet takes Full access; someone else's, Full access or being their approver
$may_approve = $own ? mitTsLevel() >= 3 : mitTsMayApprove($user_id);
$sheet = mitTsSheet($user_id, $period);
$locked = mitTsWhyLocked($sheet);
$format = mitTsFormat();
$codes_all = mitTsCodes(true);
$entries = mitTsEntries([$user_id], $period['start'], $period['end'])[$user_id] ?? [];
$days = mitTsDays([$user_id], $period['start'], $period['end'])[$user_id];
$totals = mitTsTotals([$user_id], $period['start'], $period['end'])[$user_id];
$today = date('Y-m-d');

// Rows: the codes this person uses (less any they hid), and any code with hours in this period
$used = [];
foreach ($entries as $by_code) {
    foreach ($by_code as $code_id => $entry) {
        $used[$code_id] = true;
    }
}
$allowed = mitTsCodesFor($user_id);
$hidden = array_flip(mitTsHiddenCodes($user_id));
$codes = array_filter($codes_all, function ($code) use ($used, $allowed, $hidden) {
    $id = intval($code['code_id']);
    return isset($used[$id]) || (isset($allowed[$id]) && !isset($hidden[$id]));
});
$closure_code = mitTsClosureCode($user_id);
$weeks = mitTsWeeks($period);
$fold = mitUserPreference($session_user_id, 'timesheet.view', 'period') === 'week' && $period['start'] <= $today && $period['end'] >= $today;
$prev = mitTsPeriodShift($period, -1);
$next = mitTsPeriodShift($period, 1);
$url = function (array $p, $uid = null) use ($user_id) {
    return 'mit_timesheet.php?user_id=' . intval($uid ?? $user_id) . '&start=' . $p['start'];
};
$approval = $settings['approval'];
$can_submit = $approval && !$locked && in_array($sheet['status'], ['open', 'returned'], true) && ($own || mitTsMayCorrect($user_id));
$csrf = $_SESSION['csrf_token'];
$break_rule = mitTsBreakRule($user_id);
$show_breaks = $break_rule[0] > 0 || $totals['break_paid'] > 0;
$tiles = [
    ['Total hours', mitTsHours($totals['total'], false), 'Worked plus leave' . ($totals['break_paid'] ? ', incl. ' . mitTsHours($totals['break_paid']) . ' paid breaks' : '')],
    ['Billable', mitTsHours($totals['billable'], false), mitTsBillableShare($totals) . ' of hours worked'],
    ['Other ticket time', mitTsHours($totals['other'], false), 'Tickets not billable'],
    ['Admin & other work', mitTsHours($totals['work'], false), 'Charge codes'],
    ['Lost time', mitTsHours($totals['lost'], false), $totals['auto_lost'] ? mitTsHours($totals['auto_lost']) . ' unaccounted' . ($settings['auto_lost_paid'] ? '' : ' (unpaid)') : ''],
    ['Leave', mitTsHours($totals['leave_paid'], false), $totals['leave'] > $totals['leave_paid'] ? mitTsHours($totals['leave'] - $totals['leave_paid']) . ' unpaid' : 'Paid'],
];
if ($settings['ot_day'] || $settings['ot_week']) {
    $tiles[] = ['Overtime', mitTsHours($totals['overtime'], false), mitTsPerson($user_id)['pay_type'] === 'salary' ? 'Salaried - none' : 'Over ' . trim(($settings['ot_day'] ? mitTsHours($settings['ot_day']) . '/day ' : '') . ($settings['ot_week'] ? mitTsHours($settings['ot_week']) . '/week' : ''))];
}

?>

<div class="card">
    <div class="card-header bg-dark py-2 d-flex flex-wrap align-items-center gap-2">
        <h3 class="card-title mt-2 me-auto"><i class="fas fa-fw fa-business-time me-2"></i>Timesheet<?= $own ? '' : ' · ' . escapeHtml($person['user_name']) ?></h3>
        <?php if (count($people) > 1) { ?>
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
        <?php if (mitTsLevel() >= 1 || mitTsMyReports()) { ?>
            <a class="btn btn-outline-light btn-sm" href="mit_timesheets.php?start=<?= $period['start'] ?>"><i class="fas fa-users me-2"></i>Everyone</a>
        <?php } ?>
    </div>

    <div class="card-body">
        <div class="d-flex flex-wrap align-items-center gap-2 mb-3">
            <?php if ($approval || $sheet['status'] === 'approved') { ?><?= mitTsBadge($sheet['status']) ?><?php } ?>
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
            <?php foreach ($tiles as [$label, $value, $sub]) { ?>
                <div class="col-6 col-md-4 col-xl">
                    <div class="border rounded p-2 h-100">
                        <div class="small text-muted"><?= $label ?></div>
                        <div class="fs-5 fw-semibold"><?= $value ?></div>
                        <?php if ($sub !== '') { ?><div class="small text-muted"><?= escapeHtml($sub) ?></div><?php } ?>
                    </div>
                </div>
            <?php } ?>
        </div>

        <?= mitLeaveTimesheetBox($user_id, $period) ?>
        <?= mitReimbTimesheetBox($user_id, $period) ?>

        <form action="post.php" method="post" autocomplete="off" id="mitTsForm" data-format="<?= $format ?>" data-today="<?= $today ?>" data-auto-lost="<?= $settings['auto_lost'] ?>" data-started="<?= escapeHtml(mitTsStartDate($user_id)) ?>"
            data-break-minutes="<?= $break_rule[0] ?>" data-break-per="<?= $break_rule[1] ?>">
            <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
            <input type="hidden" name="user_id" value="<?= $user_id ?>">
            <input type="hidden" name="start" value="<?= $period['start'] ?>">

            <?php foreach ($weeks as $week) {
                $open = !$fold || ($week[0] <= $today && end($week) >= $today); ?>
                <details class="mb-3" <?= $open ? 'open' : '' ?>>
                    <summary class="fw-semibold mb-2">Week of <?= escapeHtml(date('M j', strtotime($week[0]))) ?> <span class="text-muted fw-normal ms-2" data-mit-ts-week-summary></span></summary>
                    <div class="table-responsive">
                        <table class="table table-sm table-bordered align-middle mb-0 mit-ts-table">
                            <thead>
                                <tr class="text-center small">
                                    <th class="text-start" style="min-width: 190px"></th>
                                    <?php foreach ($week as $date) {
                                        $closure = mitTsClosure($date);
                                        $expected = mitTsExpected($user_id, $date); ?>
                                        <th class="<?= $expected ? '' : 'bg-body-tertiary' ?> <?= $date === $today ? 'text-primary' : '' ?>" style="min-width: 74px">
                                            <?= date('D', strtotime($date)) ?><br><?= date('M j', strtotime($date)) ?>
                                            <?php if ($closure !== '') { ?><div><span class="badge text-bg-success text-truncate" style="max-width: 70px" title="<?= escapeHtml($closure) ?>"><?= escapeHtml($closure) ?></span></div><?php } ?>
                                        </th>
                                    <?php } ?>
                                    <th style="min-width: 70px">Week</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ($settings['ticket_time']) {
                                    foreach (['billable' => ['Ticket time - billable', 'text-success'], 'other' => ['Ticket time - other', 'text-secondary']] as $kind => [$label, $class]) { ?>
                                        <tr>
                                            <td><i class="fas fa-fw fa-life-ring <?= $class ?> me-1"></i><?= $label ?> <span class="small text-muted">(auto)</span></td>
                                            <?php foreach ($week as $date) {
                                                $m = intval($days[$date][$kind] ?? 0); ?>
                                                <td class="text-center <?= mitTsExpected($user_id, $date) ? '' : 'bg-body-tertiary' ?>" data-mit-ts-auto data-worked="1" data-date="<?= $date ?>" data-minutes="<?= $m ?>">
                                                    <?php if ($m) { ?>
                                                        <a href="#" class="ajax-modal" data-modal-url="modals/mit_timesheet/day.php?user_id=<?= $user_id ?>&date=<?= $date ?>" title="The tickets"><?= mitTsHours($m) ?></a>
                                                    <?php } ?>
                                                </td>
                                            <?php } ?>
                                            <td class="text-center fw-semibold" data-mit-ts-row-total></td>
                                        </tr>
                                    <?php }
                                } ?>
                                <?php foreach ($codes as $code_id => $code) {
                                    $note_required = intval($code['code_note_required'] ?? 0);
                                    $max = intval($code['code_daily_max'] ?? 0); ?>
                                    <tr>
                                        <td>
                                            <?= mitTsCodeDot($code) ?><?= escapeHtml($code['code_name']) ?>
                                            <?php if (!intval($code['code_paid'])) { ?><span class="badge text-bg-light border ms-1">Unpaid</span><?php } ?>
                                            <?php if ($note_required) { ?><i class="fas fa-comment-dots text-muted ms-1" title="Needs a note"></i><?php } ?>
                                            <?php if ($code['code_archived_at'] !== null || !isset($allowed[$code_id])) { ?><span class="badge text-bg-light border ms-1">No longer used</span><?php } ?>
                                        </td>
                                        <?php foreach ($week as $date) {
                                            $entry = $entries[$date][$code_id] ?? ['minutes' => 0, 'note' => null];
                                            $m = intval($entry['minutes']);
                                            $future = mitTsFutureBlock($date, $code);
                                            $hint = $closure_code && intval($closure_code['code_id']) === $code_id && mitTsClosure($date) !== '' && !isset($entries[$date][$code_id])
                                                ? mitTsHours(mitTsSchedule($user_id)[intval(date('N', strtotime($date)))] ?? 0) : ''; ?>
                                            <td class="p-1 position-relative <?= mitTsExpected($user_id, $date) ? '' : 'bg-body-tertiary' ?>">
                                                <input type="text" inputmode="decimal" class="form-control form-control-sm text-center" name="cells[<?= $date ?>][<?= $code_id ?>]"
                                                    value="<?= mitTsHours($m) ?>" <?= $hint !== '' ? 'placeholder="' . $hint . '" title="Closed - ' . escapeHtml(mitTsClosure($date)) . ' (filled in when you save)"' : '' ?>
                                                    data-date="<?= $date ?>" data-code="<?= escapeHtml($code['code_name']) ?>" data-note-required="<?= $note_required ?>" data-max="<?= $max ?>"
                                                    data-worked="<?= in_array($code['code_kind'], ['work', 'lost'], true) && intval($code['code_paid']) ? 1 : 0 ?>"
                                                    aria-label="<?= escapeHtml($code['code_name'] . ' ' . date('M j', strtotime($date))) ?>" <?= $locked || ($future !== '' && !$m) ? 'disabled' : '' ?>
                                                    <?= $future !== '' && !$m ? 'title="' . escapeHtml($future) . '"' : '' ?>>
                                                <input type="hidden" name="notes[<?= $date ?>][<?= $code_id ?>]" value="<?= escapeHtml((string) $entry['note']) ?>" <?= $locked ? 'disabled' : '' ?>>
                                                <span class="position-absolute top-0 end-0 me-1 text-primary small <?= (string) $entry['note'] === '' ? 'd-none' : '' ?>" data-mit-ts-note-dot title="<?= escapeHtml((string) $entry['note']) ?>"><i class="fas fa-comment-dots"></i></span>
                                            </td>
                                        <?php } ?>
                                        <td class="text-center fw-semibold" data-mit-ts-row-total></td>
                                    </tr>
                                <?php } ?>
                                <?php if ($show_breaks) { ?>
                                    <tr class="text-success">
                                        <td><i class="fas fa-fw fa-coffee me-1"></i>Breaks <span class="small text-muted">(paid, auto)</span></td>
                                        <?php foreach ($week as $date) { ?>
                                            <td class="text-center <?= mitTsExpected($user_id, $date) ? '' : 'bg-body-tertiary' ?>" data-mit-ts-breaks="<?= $date ?>"
                                                <?= $sheet['status'] === 'approved' ? 'data-fixed="' . intval($days[$date]['break_paid'] ?? 0) . '"' : '' ?>></td>
                                        <?php } ?>
                                        <td class="text-center fw-semibold" data-mit-ts-breaks-total></td>
                                    </tr>
                                <?php } ?>
                                <?php if ($settings['auto_lost'] || $totals['auto_lost']) { ?>
                                    <tr class="text-danger">
                                        <td><i class="fas fa-fw fa-hourglass-half me-1"></i>Unaccounted <span class="small text-muted">(lost, auto<?= $settings['auto_lost_paid'] ? '' : ', unpaid' ?>)</span></td>
                                        <?php foreach ($week as $date) { ?>
                                            <td class="text-center <?= mitTsExpected($user_id, $date) ? '' : 'bg-body-tertiary' ?>" data-mit-ts-unaccounted="<?= $date ?>"
                                                <?= $sheet['status'] === 'approved' || !$settings['auto_lost'] ? 'data-fixed="' . intval($days[$date]['auto_lost'] ?? 0) . '"' : '' ?>></td>
                                        <?php } ?>
                                        <td class="text-center fw-semibold" data-mit-ts-unaccounted-total></td>
                                    </tr>
                                <?php } ?>
                            </tbody>
                            <tfoot>
                                <tr class="fw-semibold">
                                    <td>Day total</td>
                                    <?php foreach ($week as $date) { ?>
                                        <td class="text-center" data-mit-ts-day="<?= $date ?>" data-expected="<?= mitTsExpected($user_id, $date) ?>"></td>
                                    <?php } ?>
                                    <td class="text-center" data-mit-ts-week-total></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                    <div class="input-group input-group-sm mt-2 d-none" data-mit-ts-note-bar>
                        <span class="input-group-text"><i class="fas fa-comment-dots me-2"></i><span data-mit-ts-note-label></span></span>
                        <input type="text" class="form-control" maxlength="255" placeholder="Note (optional)" aria-label="Note" data-mit-ts-note-input <?= $locked ? 'disabled' : '' ?>>
                    </div>
                </details>
            <?php } ?>

            <p class="small text-muted">
                Type hours as 7.5 or 7:30; click into a box to add a note. Ticket time comes from the time logged on ticket replies each day.
                A day under its normal hours is shown in orange. Put lunch on its own line (it is not paid, and not lost time)<?= $break_rule[0] > 0 ? '; short paid breaks - ' . intval($break_rule[0]) . ' minutes for every ' . mitTsHours($break_rule[1], false, 'decimal') . ' hours worked - are added for you' : '' ?>.
            </p>

            <div class="d-flex flex-wrap align-items-center gap-2 p-3 rounded bg-body-tertiary border">
                <?php if (!$locked) { ?>
                    <button type="submit" name="mit_ts_save" value="save" class="btn btn-primary"><i class="fas fa-save me-2"></i>Save</button>
                <?php } ?>
                <?php if ($can_submit) { ?>
                    <button type="submit" name="mit_ts_save" value="submit" class="btn btn-success"><i class="fas fa-paper-plane me-2"></i>Save and submit for approval</button>
                <?php } ?>
                <?php if ($approval && $own && $sheet['status'] === 'submitted') { ?>
                    <a class="btn btn-light border" href="post.php?mit_ts_withdraw=<?= intval($sheet['sheet_id']) ?>&csrf_token=<?= $csrf ?>"><i class="fas fa-undo me-2"></i>Take it back</a>
                <?php } ?>
                <span class="ms-auto"></span>
                <?php if ($may_approve && $approval && $sheet['status'] !== 'approved') { ?>
                    <a href="#" class="btn btn-success ajax-modal" data-modal-url="modals/mit_timesheet/decide.php?user_id=<?= $user_id ?>&start=<?= $period['start'] ?>&decision=approve"><i class="fas fa-check me-2"></i>Approve</a>
                    <?php if ($sheet['status'] === 'submitted') { ?>
                        <a href="#" class="btn btn-outline-danger ajax-modal" data-modal-url="modals/mit_timesheet/decide.php?user_id=<?= $user_id ?>&start=<?= $period['start'] ?>&decision=return">Send back</a>
                    <?php } ?>
                <?php } ?>
                <?php if ($may_approve && $sheet['status'] === 'approved') { ?>
                    <a class="btn btn-light border confirm-link" href="post.php?mit_ts_reopen=<?= intval($sheet['sheet_id']) ?>&csrf_token=<?= $csrf ?>"><i class="fas fa-lock-open me-2"></i>Reopen</a>
                <?php } ?>
            </div>
        </form>
    </div>
</div>

<script src="/agent/js/mit_timesheet.js"></script>

<?php
require_once "../includes/footer.php";
