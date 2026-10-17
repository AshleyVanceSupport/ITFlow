<?php

/*
 * Mountaineer IT - Admin > Charge codes (admin/mit_charge_codes.php): codes and the pay period.
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
    $set = "code_name = '" . escapeSql(mb_substr($name, 0, 100)) . "', code_kind = '$kind', code_paid = " . (empty($_POST['paid']) ? 0 : 1)
        . ", code_color = " . (preg_match('/^#[0-9a-f]{6}$/i', $color) ? "'$color'" : 'NULL');
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

if (isset($_POST['mit_edit_timesheet_settings'])) {

    validateCSRFToken();

    $type = (string) ($_POST['mit_ts_period'] ?? '');
    $anchor = (string) ($_POST['mit_ts_anchor'] ?? '');
    if (!isset(MIT_TS_PERIODS[$type]) || ($type === 'biweekly' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $anchor))) {
        flashAlert('Pick a pay period, and the first day of one for every two weeks.', 'error');
        redirect();
    }
    mitSetSetting('timesheet_period', $type);
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $anchor)) {
        mitSetSetting('timesheet_anchor', $anchor);
    }
    logAudit('Settings', 'Edit', "$session_name edited timesheet settings");
    flashAlert('Pay period saved');
    redirect();
}
