<?php

/*
 * Mountaineer IT - timesheets (functions/mit_timesheets.php): save, submit, take back, approve or
 * send back, reopen, and approve several at once from the Everyone page.
 */

defined('FROM_POST_HANDLER') || die("Direct file access is not allowed");

mitModuleGuard('timesheets', ['mit_ts_']);

if (isset($_POST['mit_ts_save'])) {

    validateCSRFToken();

    $user_id = intval($_POST['user_id'] ?? 0);
    if (!mitTsReady() || !mitTsMaySee($user_id) || !(mitTsUsers([$user_id])[$user_id] ?? null)) {
        flashAlert('That timesheet is not yours.', 'error');
        redirect();
    }
    $period = mitTsPeriodFromRequest($_POST['start'] ?? '');
    $sheet = mitTsSheet($user_id, $period);
    $locked = mitTsWhyLocked($sheet);
    if ($locked !== '') {
        flashAlert(escapeHtml($locked), 'error');
        redirect();
    }
    [$ok, $message] = mitTsSaveCells($sheet, $period, (array) ($_POST['cells'] ?? []), (array) ($_POST['notes'] ?? []));
    if ($ok && $_POST['mit_ts_save'] === 'submit') {
        if ($user_id !== intval($session_user_id) && !mitTsMayCorrect($user_id)) {
            [$ok, $message] = [false, 'Only they can submit their timesheet.'];
        } else {
            [$ok, $message] = mitTsSubmit(mitTsSheet($user_id, $period), $period);
        }
    }
    flashAlert(escapeHtml($message), $ok ? 'success' : 'error');
    redirect();
}

if (isset($_GET['mit_ts_withdraw'])) {

    validateCSRFToken();

    [$sheet, $period] = mitTsSheetForRequest($_GET['mit_ts_withdraw']);
    if (intval($sheet['user_id']) !== intval($session_user_id)) {
        flashAlert('Only they can take their timesheet back.', 'error');
        redirect();
    }
    [$ok, $message] = mitTsWithdraw($sheet);
    flashAlert(escapeHtml($message), $ok ? 'success' : 'error');
    redirect();
}

if (isset($_POST['mit_ts_decide'])) {

    validateCSRFToken();

    $user_id = intval($_POST['user_id'] ?? 0);
    if (!mitTsSettings()['approval']) {
        flashAlert('Timesheets are not approved here (Admin > Timesheets).', 'error');
        redirect();
    }
    if (!(mitTsUsers([$user_id])[$user_id] ?? null) || !mitTsMayApprove($user_id) || ($user_id === intval($session_user_id) && mitTsLevel() < 3)) {
        flashAlert('You cannot approve that timesheet.', 'error');
        redirect();
    }
    $period = mitTsPeriodFromRequest($_POST['start'] ?? '');
    [$ok, $message] = mitTsDecide(mitTsSheet($user_id, $period), $period, ($_POST['decision'] ?? '') === 'return' ? 'return' : 'approve', $_POST['note'] ?? '');
    flashAlert(escapeHtml($message), $ok ? 'success' : 'error');
    redirect();
}

if (isset($_GET['mit_ts_reopen'])) {

    validateCSRFToken();

    [$sheet, $period] = mitTsSheetForRequest($_GET['mit_ts_reopen']);
    if (!mitTsMayApprove($sheet['user_id']) || (intval($sheet['user_id']) === intval($session_user_id) && mitTsLevel() < 3)) {
        flashAlert('You cannot reopen that timesheet.', 'error');
        redirect();
    }
    [$ok, $message] = mitTsReopen($sheet, $period);
    flashAlert(escapeHtml($message), $ok ? 'success' : 'error');
    redirect();
}

if (isset($_POST['mit_ts_bulk_approve'])) {

    validateCSRFToken();

    $period = mitTsPeriodFromRequest($_POST['start'] ?? '');
    $people = mitTsSettings()['approval'] ? mitTsVisibleUsers($period) : [];
    $done = 0;
    foreach ((array) ($_POST['user_ids'] ?? []) as $user_id) {
        $user_id = intval($user_id);
        $sheet = mitTsSheet($user_id, $period);
        if (isset($people[$user_id]) && $sheet['status'] === 'submitted' && mitTsMayApprove($user_id) && ($user_id !== intval($session_user_id) || mitTsLevel() >= 3)) {
            [$ok] = mitTsDecide($sheet, $period, 'approve');
            $done += $ok ? 1 : 0;
        }
    }
    flashAlert($done ? "Approved $done timesheet" . ($done === 1 ? '' : 's') : 'Nothing to approve - only submitted timesheets can be approved here.', $done ? 'success' : 'error');
    redirect();
}
