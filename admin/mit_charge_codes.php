<?php

/*
 * Mountaineer IT - Admin > Charge codes (functions/mit_timesheets.php): what people can put hours
 * against on their timesheets, and the pay period. Handlers in admin/post/mit_timesheets.php.
 */

require_once "includes/inc_all_admin.php";

if (!mitTsReady()) {
    echo '<div class="alert alert-warning">Timesheets need the database update - Admin > Update.</div>';
    require_once "../includes/footer.php";
    exit;
}

$show_archived = !empty($_GET['archived']);
$codes = array_filter(mitTsCodes(true), function ($code) use ($show_archived) {
    return ($code['code_archived_at'] !== null) === $show_archived;
});
$used = [];
$result = mysqli_query($mysqli, "SELECT code_id, COUNT(*) AS n FROM mit_time_entries WHERE code_id > 0 GROUP BY code_id");
while ($row = mysqli_fetch_assoc($result)) {
    $used[intval($row['code_id'])] = intval($row['n']);
}
[$period_type, $period_anchor] = mitTsPeriodSettings();
$csrf = $_SESSION['csrf_token'];

?>

<div class="card">
    <div class="card-header bg-dark py-2">
        <h3 class="card-title mt-2"><i class="fas fa-fw fa-business-time me-2"></i>Charge codes</h3>
        <div class="card-tools">
            <button type="button" class="btn btn-primary ajax-modal" data-modal-url="modals/mit_timesheets/charge_code.php"><i class="fas fa-plus me-2"></i>New charge code</button>
        </div>
    </div>
    <div class="card-body pb-0">
        <p class="text-muted">What people put hours against on their timesheets, besides ticket time (which fills itself in).
            <strong>Work</strong> and <strong>Lost time</strong> count as hours worked; <strong>Leave</strong> is time off. Unpaid codes are left out of paid hours.</p>
        <ul class="nav nav-tabs">
            <li class="nav-item"><a class="nav-link <?= $show_archived ? '' : 'active' ?>" href="?">Active</a></li>
            <li class="nav-item"><a class="nav-link <?= $show_archived ? 'active' : '' ?>" href="?archived=1">Archived</a></li>
        </ul>
    </div>
    <div class="table-responsive-sm">
        <table class="table table-striped table-borderless table-hover mb-0 align-middle">
            <thead class="text-dark">
                <tr><th class="ps-3">Name</th><th>Kind</th><th>Paid</th><th>Used</th><th class="text-center">Action</th></tr>
            </thead>
            <tbody>
                <?php if (!$codes) { ?>
                    <tr><td colspan="5" class="text-center text-muted py-4"><?= $show_archived ? 'Nothing archived.' : 'No charge codes yet.' ?></td></tr>
                <?php } ?>
                <?php foreach ($codes as $code_id => $code) { ?>
                    <tr>
                        <td class="ps-3">
                            <a class="text-dark text-bold ajax-modal" href="#" data-modal-url="modals/mit_timesheets/charge_code.php?id=<?= intval($code_id) ?>"><?= mitTsCodeDot($code) ?><?= escapeHtml($code['code_name']) ?></a>
                        </td>
                        <td><?= escapeHtml(MIT_TS_KINDS[$code['code_kind']] ?? $code['code_kind']) ?></td>
                        <td><?= intval($code['code_paid']) ? 'Paid' : '<span class="badge text-bg-light border">Unpaid</span>' ?></td>
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
</div>

<div class="card card-dark">
    <div class="card-header py-3">
        <h3 class="card-title"><i class="fas fa-fw fa-calendar-week me-2"></i>Pay period</h3>
    </div>
    <div class="card-body">
        <form action="post.php" method="post" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label">Timesheets cover</label>
                    <select class="form-select" name="mit_ts_period">
                        <?php foreach (MIT_TS_PERIODS as $key => $label) { ?>
                            <option value="<?= $key ?>" <?= $key === $period_type ? 'selected' : '' ?>><?= escapeHtml($label) ?></option>
                        <?php } ?>
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label">First day of a pay period <span class="text-muted small">(every two weeks only)</span></label>
                    <input type="date" class="form-control" name="mit_ts_anchor" value="<?= escapeHtml($period_anchor) ?>">
                </div>
            </div>
            <div class="form-text mb-3">Changing it starts new timesheets from the next period - timesheets already approved keep their dates, but ones still open may need submitting again.</div>
            <button type="submit" name="mit_edit_timesheet_settings" class="btn btn-primary"><i class="fas fa-check me-2"></i>Save</button>
        </form>
    </div>
</div>

<?php
require_once "../includes/footer.php";
