<?php

/*
 * Mountaineer IT - Admin > Timesheets (admin/mit_timesheets.php): settings, charge codes and people.
 */

defined('FROM_POST_HANDLER') || die("Direct file access is not allowed");

if (isset($_POST['mit_charge_code_save'])) {

    validateCSRFToken();

    $code_id = intval($_POST['code_id'] ?? 0);
    $name = trim((string) ($_POST['name'] ?? ''));
    $kind = (string) ($_POST['kind'] ?? 'work');
    $color = (string) ($_POST['color'] ?? '');
    if ($name === '' || !isset(MIT_TS_KINDS[$kind])) {
        flashAlert('Give the charge code a name.', 'error');
        redirect();
    }
    $daily_max = mitTsParseHours($_POST['daily_max'] ?? '');
    if ($daily_max === null || $daily_max > 1440) {
        flashAlert('The daily limit should be hours, e.g. 8 or 7.5.', 'error');
        redirect();
    }
    $code_roles = implode(',', array_filter(array_map('intval', (array) ($_POST['roles'] ?? []))));
    $set = "code_name = '" . escapeSql(mb_substr($name, 0, 100)) . "', code_kind = '$kind', code_paid = " . (empty($_POST['paid']) ? 0 : 1)
        . ", code_color = " . (preg_match('/^#[0-9a-f]{6}$/i', $color) ? "'$color'" : 'NULL')
        . ", code_roles = " . ($code_roles === '' ? 'NULL' : "'$code_roles'")
        . ", code_note_required = " . (empty($_POST['note_required']) ? 0 : 1)
        . ", code_daily_max = " . ($daily_max ? $daily_max : 'NULL')
        . ", code_closure_fill = " . (empty($_POST['closure_fill']) ? 0 : 1);
    if ($code_id) {
        mysqli_query($mysqli, "UPDATE mit_charge_codes SET $set WHERE code_id = $code_id");
    } else {
        $sort = intval(mysqli_fetch_row(mysqli_query($mysqli, "SELECT COALESCE(MAX(code_sort), 0) + 1 FROM mit_charge_codes"))[0]);
        mysqli_query($mysqli, "INSERT INTO mit_charge_codes SET $set, code_sort = $sort");
    }
    logAudit('Settings', $code_id ? 'Edit' : 'Create', "$session_name " . ($code_id ? 'edited' : 'created') . " charge code " . escapeSql($name));
    flashAlert('Charge code <strong>' . escapeHtml($name) . '</strong> saved');
    redirect();
}

if (isset($_GET['mit_charge_code_move'])) {

    validateCSRFToken();

    $code_id = intval($_GET['mit_charge_code_move']);
    $ids = array_keys(mitTsCodes());
    $at = array_search($code_id, $ids, true);
    $to = $at === false ? false : $at + (($_GET['dir'] ?? '') === 'up' ? -1 : 1);
    if ($to !== false && isset($ids[$to])) {
        [$ids[$at], $ids[$to]] = [$ids[$to], $ids[$at]];
        foreach ($ids as $i => $id) {
            mysqli_query($mysqli, "UPDATE mit_charge_codes SET code_sort = " . ($i + 1) . " WHERE code_id = " . intval($id));
        }
    }
    redirect();
}

if (isset($_GET['mit_charge_code_archive']) || isset($_GET['mit_charge_code_restore'])) {

    validateCSRFToken();

    $archive = isset($_GET['mit_charge_code_archive']);
    $code_id = intval($archive ? $_GET['mit_charge_code_archive'] : $_GET['mit_charge_code_restore']);
    mysqli_query($mysqli, "UPDATE mit_charge_codes SET code_archived_at = " . ($archive ? 'NOW()' : 'NULL') . " WHERE code_id = $code_id");
    logAudit('Settings', $archive ? 'Archive' : 'Restore', "$session_name " . ($archive ? 'archived' : 'restored') . " charge code $code_id");
    flashAlert($archive ? 'Archived - hours already on it stay' : 'Restored');
    redirect();
}

if (isset($_GET['mit_charge_code_delete'])) {

    validateCSRFToken();

    $code_id = intval($_GET['mit_charge_code_delete']);
    if (mysqli_num_rows(mysqli_query($mysqli, "SELECT 1 FROM mit_time_entries WHERE code_id = $code_id LIMIT 1"))) {
        flashAlert('People have hours on that code - archive it instead.', 'error');
        redirect();
    }
    mysqli_query($mysqli, "DELETE FROM mit_charge_codes WHERE code_id = $code_id");
    logAudit('Settings', 'Delete', "$session_name deleted charge code $code_id");
    flashAlert('Deleted', 'error');
    redirect();
}

if (isset($_POST['mit_ts_settings_save'])) {

    validateCSRFToken();

    $type = (string) ($_POST['period'] ?? '');
    $anchor = (string) ($_POST['anchor'] ?? '');
    $schedule = mitTsScheduleFromPost((array) ($_POST['schedule'] ?? []));
    $ot_day = mitTsParseHours($_POST['ot_day'] ?? '');
    $ot_week = trim((string) ($_POST['ot_week'] ?? ''));
    $ot_week = $ot_week === '' ? 0 : (is_numeric($ot_week) && $ot_week >= 0 && $ot_week <= 168 ? (int) round($ot_week * 60) : null);
    if (!isset(MIT_TS_PERIODS[$type]) || ($type === 'biweekly' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $anchor)) || $schedule === null || $ot_day === null || $ot_week === null) {
        flashAlert('Not saved - check the pay period (and its first day for every two weeks), the normal week and the overtime hours.', 'error');
        redirect();
    }
    mitSetSetting('timesheet_period', $type);
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $anchor)) {
        mitSetSetting('timesheet_anchor', $anchor);
    }
    $values = [
        'week_start' => intval($_POST['week_start'] ?? 1) === 7 ? 7 : 1,
        'approval' => empty($_POST['approval']) ? 0 : 1,
        'future' => in_array($_POST['future'] ?? '', ['all', 'leave', 'none'], true) ? $_POST['future'] : 'all',
        'autolock_days' => max(0, min(365, intval($_POST['autolock_days'] ?? 0))),
        'remind_due' => empty($_POST['remind_due']) ? 0 : 1,
        'remind_late_days' => max(0, min(60, intval($_POST['remind_late_days'] ?? 0))),
        'format' => ($_POST['format'] ?? '') === 'hm' ? 'hm' : 'decimal',
        'rounding' => in_array(intval($_POST['rounding'] ?? 0), [0, 6, 15, 30], true) ? intval($_POST['rounding']) : 0,
        'ticket_time' => empty($_POST['ticket_time']) ? 0 : 1,
        'ticket_internal' => empty($_POST['ticket_internal']) ? 0 : 1,
        'billable' => ($_POST['billable'] ?? '') === 'client' ? 'client' : 'flag',
        'schedule' => $schedule,
        'ot_day' => $ot_day,
        'ot_week' => $ot_week,
        'auto_lost' => empty($_POST['auto_lost']) ? 0 : 1,
    ];
    foreach ($values as $key => $value) {
        mitSetSetting("ts_$key", $value);
    }
    logAudit('Settings', 'Edit', "$session_name edited timesheet settings");
    flashAlert('Timesheet settings saved');
    redirect();
}

if (isset($_POST['mit_ts_person_save'])) {

    validateCSRFToken();

    $user_id = intval($_POST['user_id'] ?? 0);
    $schedule = mitTsScheduleFromPost((array) ($_POST['schedule'] ?? []));
    if (!(mitTsUsers([$user_id])[$user_id] ?? null) || $schedule === null) {
        flashAlert('Not saved - check the hours in the normal week.', 'error');
        redirect();
    }
    $approver_id = intval($_POST['approver_id'] ?? 0);
    if ($approver_id === $user_id || ($approver_id && !(mitTsUsers()[$approver_id] ?? null))) {
        $approver_id = 0;
    }
    $employee_id = trim((string) ($_POST['employee_id'] ?? ''));
    $pay_type = isset(MIT_TS_PAY_TYPES[$_POST['pay_type'] ?? '']) ? $_POST['pay_type'] : 'hourly';
    $set = "schedule = " . ($schedule === '' ? 'NULL' : "'$schedule'") . ", approver_id = $approver_id,
        employee_id = " . ($employee_id === '' ? 'NULL' : "'" . escapeSql(mb_substr($employee_id, 0, 50)) . "'") . ", pay_type = '$pay_type'";
    mysqli_query($mysqli, "INSERT INTO mit_timesheet_people SET user_id = $user_id, $set ON DUPLICATE KEY UPDATE $set");
    logAudit('Settings', 'Edit', "$session_name edited the timesheet details of user $user_id");
    flashAlert('Saved');
    redirect();
}
