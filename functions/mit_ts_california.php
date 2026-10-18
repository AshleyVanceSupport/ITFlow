<?php

/*
 * Mountaineer IT - California time records for non-exempt people (database update M018; required
 * from functions/mit_timesheets.php). Who: Admin > Timesheets > People, "California non-exempt" -
 * on for hourly people unless turned off (mit_timesheet_people.ca_rules: 1 / 0 / NULL = by pay type).
 *
 * For them the timesheet also records, each day, when work starts and ends and when each meal period
 * starts and ends (Labor Code 1174, IWC wage orders section 7), to the minute and never rounded -
 * typed by the person, every change kept (mit_ts_changes) with who made it and why; a change made by
 * someone else waits for the person to acknowledge it. Those times are the hours worked: charge codes
 * and ticket time say what the time was spent on, and time on neither is "unallocated" work, still paid.
 *
 * Meal periods (Labor Code 512, wage order section 11): a 30-minute meal period starting by the end of
 * the 5th hour worked when the day is over 5 hours, a second by the end of the 10th when it is over 10.
 * A day whose times show one missing, late or short asks the person why: they chose to skip, shorten
 * or delay it (or waived it), or they were not able to take it - then one hour of premium pay is owed
 * (Labor Code 226.7, at most one meal premium a day). Rest breaks are paid and not recorded; a day the
 * person says they could not take one owes a rest premium hour. Unanswered counts as owed.
 *
 * Overtime (Labor Code 510): over 8 hours a day 1.5x, over 12 a day 2x, over 40 regular hours a week
 * 1.5x, and the 7th consecutive day worked in the workweek 1.5x for the first 8 hours, 2x after.
 * Premium hours are paid on top and are not hours worked.
 *
 * Submitting a period takes a signed attestation (mit_ts_attestations): the statement word for word,
 * the typed name, when, from where, and a copy of the times signed for. Taking it back, sending it
 * back or reopening it sets that signature aside, and the next submit signs again.
 *
 * This is the record-keeping side; it is not legal advice - the rules and the wording of the
 * statement should be confirmed with an employment lawyer.
 */

const MIT_TS_CA_ATTEST_VERSION = '2026-10';
const MIT_TS_CA_ANSWERS = ['chose' => 'I chose to skip, shorten or delay it', 'not_provided' => 'I was not able to take it (premium hour owed)'];

/** Has database update M018 run? */
function mitTsCaReady() {
    global $mysqli;
    static $ready = null;
    if ($ready === null) {
        try {
            $ready = mysqli_num_rows(mysqli_query($mysqli, "SHOW TABLES LIKE 'mit_ts_attestations'")) === 1;
        } catch (Throwable $e) {
            $ready = false;
        }
    }
    return $ready;
}

/** The first day the rules apply (mit_settings ts_ca_start - the day database update M018 ran). */
function mitTsCaStart() {
    $start = (string) mitSetting('ts_ca_start', '');
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $start) ? $start : '2000-01-01';
}

/**
 * Is this person on California non-exempt rules? The People tab setting, else on for hourly people. With a date
 * (a day, or a period's last day): only from the day the rules started - earlier days keep their hours as they were.
 */
function mitTsCaOn($user_id, $date = null) {
    if (!mitTsCaReady() || ($date !== null && $date < mitTsCaStart())) {
        return false;
    }
    $row = mitTsPeople()[intval($user_id)] ?? [];
    if (isset($row['ca_rules']) && $row['ca_rules'] !== null && $row['ca_rules'] !== '') {
        return intval($row['ca_rules']) === 1;
    }
    return mitTsPerson($user_id)['pay_type'] === 'hourly';
}

/*
 * ---------- reading the records ----------
 */

/** Work periods: [user_id][date] => [['start' => 'Y-m-d H:i:s', 'end' => ...], ...] in order. */
function mitTsCaPeriods(array $user_ids, $from, $to) {
    global $mysqli;
    $user_ids = array_filter(array_map('intval', $user_ids));
    $periods = [];
    if ($user_ids && mitTsCaReady()) {
        $result = mysqli_query($mysqli, "SELECT user_id, work_date, start_at, end_at FROM mit_ts_work_periods WHERE user_id IN (" . implode(',', $user_ids) . ")
            AND work_date BETWEEN '" . escapeSql($from) . "' AND '" . escapeSql($to) . "' ORDER BY start_at");
        while ($row = mysqli_fetch_assoc($result)) {
            $periods[intval($row['user_id'])][$row['work_date']][] = ['start' => $row['start_at'], 'end' => $row['end_at']];
        }
    }
    return $periods;
}

/** The person's answers: [user_id][date] => ['meal1', 'meal2', 'rest_missed']. */
function mitTsCaAnswers(array $user_ids, $from, $to) {
    global $mysqli;
    $user_ids = array_filter(array_map('intval', $user_ids));
    $answers = [];
    if ($user_ids && mitTsCaReady()) {
        $result = mysqli_query($mysqli, "SELECT user_id, work_date, meal1, meal2, rest_missed FROM mit_ts_days WHERE user_id IN (" . implode(',', $user_ids) . ")
            AND work_date BETWEEN '" . escapeSql($from) . "' AND '" . escapeSql($to) . "'");
        while ($row = mysqli_fetch_assoc($result)) {
            $answers[intval($row['user_id'])][$row['work_date']] = ['meal1' => (string) $row['meal1'], 'meal2' => (string) $row['meal2'], 'rest_missed' => intval($row['rest_missed'])];
        }
    }
    return $answers;
}

/*
 * ---------- the rules ----------
 */

/**
 * A day's work periods against the meal rules: ['worked' => minutes, 'meals' => [1 => status, 2 => status]]
 * - status null when that meal is not due, else ok | missing | late | short; and 'gaps' (minutes between periods).
 */
function mitTsCaAnalyze(array $periods) {
    $worked = 0;
    $gaps = [];  // [worked before it, minutes]
    $last_end = null;
    foreach ($periods as $p) {
        $start = strtotime($p['start']);
        $end = strtotime($p['end']);
        if ($last_end !== null) {
            $gaps[] = ['before' => $worked, 'minutes' => intdiv(max(0, $start - $last_end), 60)];
        }
        $worked += intdiv(max(0, $end - $start), 60);
        $last_end = $end;
    }
    $meals = [1 => null, 2 => null];
    $used = -1;
    foreach ([1 => 300, 2 => 600] as $n => $by) {
        if ($worked <= $by) {
            continue;
        }
        $status = 'missing';
        foreach ($gaps as $i => $gap) {
            if ($i <= $used) {
                continue;
            }
            if ($gap['minutes'] >= 30 && $gap['before'] <= $by) {
                $status = 'ok';
                $used = $i;
                break;
            }
        }
        if ($status !== 'ok') {
            // Not met: say how, and let a later or shorter break stand in for this meal so the next one is judged on its own
            foreach ($gaps as $i => $gap) {
                if ($i > $used) {
                    $status = $gap['before'] <= $by ? 'short' : 'late';
                    $used = $i;
                    break;
                }
            }
        }
        $meals[$n] = $status;
    }
    return ['worked' => $worked, 'meals' => $meals, 'gaps' => $gaps];
}

/** "No 2nd meal period", "1st meal period started after the 5th hour", ... */
function mitTsCaIssueText($n, $status) {
    $which = $n === 1 ? '1st' : '2nd';
    $by = $n === 1 ? '5th' : '10th';
    switch ($status) {
        case 'missing':
            return "No $which meal period (due for a day over " . ($n === 1 ? '5' : '10') . ' hours)';
        case 'late':
            return "$which meal period started after the end of the $by hour";
        case 'short':
            return "$which meal period was under 30 minutes";
    }
    return '';
}

/**
 * California days for people on the rules: [user_id][date] => ['clocked', 'meals', 'issues' => [n => status],
 * 'unanswered' => [n...], 'meal_premium', 'rest_premium' (minutes), 'periods'].
 */
function mitTsCaDays(array $user_ids, $from, $to) {
    $ca_users = array_values(array_filter(array_map('intval', $user_ids), 'mitTsCaOn'));
    $days = [];
    if (!$ca_users) {
        return $days;
    }
    $periods = mitTsCaPeriods($ca_users, $from, $to);
    $answers = mitTsCaAnswers($ca_users, $from, $to);
    $first = mitTsCaStart();
    foreach ($ca_users as $user_id) {
        foreach (mitTsDates(['start' => max($from, $first), 'end' => $to]) as $date) {
            $day_periods = $periods[$user_id][$date] ?? [];
            $a = mitTsCaAnalyze($day_periods);
            $answer = $answers[$user_id][$date] ?? ['meal1' => '', 'meal2' => '', 'rest_missed' => 0];
            $issues = array_filter($a['meals'], function ($s) {
                return $s !== null && $s !== 'ok';
            });
            $unanswered = [];
            $premium = false;
            foreach ($issues as $n => $status) {
                $given = $answer["meal$n"];
                if (!isset(MIT_TS_CA_ANSWERS[$given])) {
                    $unanswered[] = $n;
                }
                // Unanswered counts as owed until the person says otherwise
                $premium = $premium || $given !== 'chose';
            }
            $days[$user_id][$date] = ['clocked' => $a['worked'], 'meals' => $a['meals'], 'issues' => $issues, 'unanswered' => $unanswered,
                'answers' => $answer, 'meal_premium' => $premium ? 60 : 0, 'rest_premium' => $answer['rest_missed'] ? 60 : 0, 'periods' => $day_periods];
        }
    }
    return $days;
}

/**
 * California overtime over days of hours worked (date => minutes, from the start of a workweek, every date):
 * date => ['regular', 'overtime', 'double'].
 */
function mitTsCaOvertime(array $worked) {
    ksort($worked);
    $out = [];
    $week_regular = [];
    foreach ($worked as $date => $w) {
        $w = max(0, intval($w));
        $week = mitTsWeekStart($date);
        // The 7th consecutive day of the workweek: every day of the week so far worked
        $index = (int) round((strtotime($date) - strtotime($week)) / 86400);
        $seventh = $index === 6 && $w > 0;
        for ($i = 0; $seventh && $i < 6; $i++) {
            $seventh = intval($worked[date('Y-m-d', strtotime("$week +$i days"))] ?? 0) > 0;
        }
        if ($seventh) {
            $regular = 0;
            $overtime = min($w, 480);
            $double = max(0, $w - 480);
        } else {
            $regular = min($w, 480);
            $double = max(0, $w - 720);
            $overtime = $w - $regular - $double;
        }
        // Over 40 regular hours in the workweek: the rest of the regular hours are overtime
        $so_far = $week_regular[$week] ?? 0;
        $past = max(0, $so_far + $regular - 2400) - max(0, $so_far - 2400);
        $week_regular[$week] = $so_far + $regular;
        $out[$date] = ['regular' => $regular - $past, 'overtime' => $overtime + $past, 'double' => $double];
    }
    return $out;
}

/*
 * ---------- showing times ----------
 */

/** "8:00 AM - 12:00 PM, 12:30 PM - 5:00 PM" (or "no times"). */
function mitTsCaPeriodsText(array $periods) {
    if (!$periods) {
        return 'no times';
    }
    return implode(', ', array_map(function ($p) {
        return date('g:i A', strtotime($p['start'])) . ' - ' . date('g:i A', strtotime($p['end'])) . (substr($p['end'], 0, 10) > substr($p['start'], 0, 10) ? ' (+1 day)' : '');
    }, $periods));
}

/** The six boxes of a day from its periods: start, meal_out, meal_in, meal2_out, meal2_in, end ('H:i' or ''). */
function mitTsCaBoxes(array $periods) {
    $boxes = ['start' => '', 'meal_out' => '', 'meal_in' => '', 'meal2_out' => '', 'meal2_in' => '', 'end' => ''];
    $times = [];
    foreach ($periods as $p) {
        $times[] = date('H:i', strtotime($p['start']));
        $times[] = date('H:i', strtotime($p['end']));
    }
    if (count($times) >= 2) {
        $boxes['start'] = array_shift($times);
        $boxes['end'] = array_pop($times);
        foreach (['meal_out', 'meal_in', 'meal2_out', 'meal2_in'] as $key) {
            $boxes[$key] = (string) array_shift($times);
        }
    }
    return $boxes;
}

/*
 * ---------- saving ----------
 */

/**
 * Turn a day's boxes into work periods: [periods, error]. Times are 'HH:MM'; a time earlier than the one
 * before it is the next day (a shift past midnight), and the day may span at most 24 hours.
 */
function mitTsCaParseDay($date, array $boxes) {
    $read = function ($key) use ($boxes) {
        $value = trim((string) ($boxes[$key] ?? ''));
        return preg_match('/^([01]?\d|2[0-3]):([0-5]\d)$/', $value, $m) ? sprintf('%02d:%02d', $m[1], $m[2]) : ($value === '' ? '' : null);
    };
    $v = [];
    foreach (['start', 'meal_out', 'meal_in', 'meal2_out', 'meal2_in', 'end'] as $key) {
        $v[$key] = $read($key);
        if ($v[$key] === null) {
            return [[], 'a time could not be read'];
        }
    }
    if (implode('', $v) === '') {
        return [[], ''];
    }
    if ($v['start'] === '' || $v['end'] === '') {
        return [[], 'needs a start and an end'];
    }
    if (($v['meal_out'] === '') !== ($v['meal_in'] === '') || ($v['meal2_out'] === '') !== ($v['meal2_in'] === '')) {
        return [[], 'a meal period needs both its out and back times'];
    }
    if ($v['meal2_out'] !== '' && $v['meal_out'] === '') {
        return [[], 'put the first meal period in the first meal boxes'];
    }
    $points = array_values(array_filter([$v['start'], $v['meal_out'], $v['meal_in'], $v['meal2_out'], $v['meal2_in'], $v['end']], 'strlen'));
    $stamps = [];
    $day = 0;
    foreach ($points as $i => $time) {
        $t = strtotime("$date $time") + $day * 86400;
        if ($i && $t <= $stamps[$i - 1]) {
            $day++;
            $t += 86400;
        }
        $stamps[] = $t;
    }
    if (end($stamps) - $stamps[0] > 86400 || $day > 1) {
        return [[], 'spans more than 24 hours'];
    }
    $periods = [];
    for ($i = 0; $i + 1 < count($stamps); $i += 2) {
        $periods[] = ['start' => date('Y-m-d H:i:s', $stamps[$i]), 'end' => date('Y-m-d H:i:s', $stamps[$i + 1])];
    }
    return [$periods, ''];
}

/**
 * Save the times and answers from the timesheet form: $times[date][box] and $answers[date][meal1|meal2|rest_missed].
 * Only the person answers their own meal questions. Someone else changing the times must say why, and the
 * person is asked to acknowledge it. Returns [ok, message, changed days].
 */
function mitTsCaSave(array $sheet, array $period, array $times, array $answers, $reason) {
    global $mysqli, $session_user_id;
    $user_id = intval($sheet['user_id']);
    $own = $user_id === intval($session_user_id);
    $reason = trim((string) $reason);
    $dates = array_flip(mitTsDates($period));
    $current = mitTsCaPeriods([$user_id], $period['start'], $period['end'])[$user_id] ?? [];
    $wanted = [];
    $problems = [];
    foreach ($times as $date => $boxes) {
        if (!isset($dates[$date]) || !is_array($boxes)) {
            continue;
        }
        [$periods, $error] = mitTsCaParseDay($date, $boxes);
        if ($error !== '') {
            $problems[] = date('D M j', strtotime($date)) . ": $error";
            continue;
        }
        if ($periods && $date > date('Y-m-d')) {
            $problems[] = date('D M j', strtotime($date)) . ': times go on once the day has come';
            continue;
        }
        if (mitTsCaPeriodsText($periods) !== mitTsCaPeriodsText($current[$date] ?? [])) {
            $wanted[$date] = $periods;
        }
    }
    if ($problems) {
        return [false, 'Not saved - ' . implode('; ', array_slice($problems, 0, 4)) . '.', 0];
    }
    if ($wanted && !$own && $reason === '') {
        return [false, 'Not saved - say why you are changing their times (they see it and are asked to acknowledge it).', 0];
    }
    $who = mitTsWho();
    foreach ($wanted as $date => $periods) {
        $date_sql = escapeSql($date);
        mysqli_query($mysqli, "DELETE FROM mit_ts_work_periods WHERE user_id = $user_id AND work_date = '$date_sql'");
        foreach ($periods as $p) {
            mysqli_query($mysqli, "INSERT INTO mit_ts_work_periods SET user_id = $user_id, work_date = '$date_sql', start_at = '" . escapeSql($p['start']) . "',
                end_at = '" . escapeSql($p['end']) . "', updated_by = " . intval($session_user_id));
        }
        mysqli_query($mysqli, "INSERT INTO mit_ts_changes SET user_id = $user_id, work_date = '$date_sql', changed_by = " . intval($session_user_id) . ",
            changed_by_name = '" . escapeSql(mb_substr($who, 0, 200)) . "', before_text = '" . escapeSql(mitTsCaPeriodsText($current[$date] ?? [])) . "',
            after_text = '" . escapeSql(mitTsCaPeriodsText($periods)) . "', reason = " . ($reason === '' ? 'NULL' : "'" . escapeSql(mb_substr($reason, 0, 255)) . "'") . ",
            acknowledged_at = " . ($own ? 'NOW()' : 'NULL'));
    }
    // The person's own answers about their meal periods and rest breaks
    $answered = 0;
    if ($own) {
        $had = mitTsCaAnswers([$user_id], $period['start'], $period['end'])[$user_id] ?? [];
        foreach ($answers as $date => $a) {
            if (!isset($dates[$date]) || !is_array($a)) {
                continue;
            }
            $meal1 = isset(MIT_TS_CA_ANSWERS[$a['meal1'] ?? '']) ? $a['meal1'] : '';
            $meal2 = isset(MIT_TS_CA_ANSWERS[$a['meal2'] ?? '']) ? $a['meal2'] : '';
            $rest = empty($a['rest_missed']) ? 0 : 1;
            $before = $had[$date] ?? ['meal1' => '', 'meal2' => '', 'rest_missed' => 0];
            if ([$meal1, $meal2, $rest] === [$before['meal1'], $before['meal2'], $before['rest_missed']]) {
                continue;
            }
            $set = "meal1 = " . ($meal1 === '' ? 'NULL' : "'$meal1'") . ", meal2 = " . ($meal2 === '' ? 'NULL' : "'$meal2'") . ", rest_missed = $rest, updated_by = " . intval($session_user_id);
            mysqli_query($mysqli, "INSERT INTO mit_ts_days SET user_id = $user_id, work_date = '" . escapeSql($date) . "', $set ON DUPLICATE KEY UPDATE $set");
            $answered++;
        }
    }
    if ($wanted || $answered) {
        mitTsSheetSaved($user_id, $period);
        if (!$own && $wanted) {
            logAudit('Timesheet', 'Edit', escapeSql("$who changed the work times of user $user_id on " . count($wanted) . ' day(s) for ' . $period['label'] . " - $reason"), 0, $user_id);
            mitTsNotify($user_id, "$who changed your work times on " . count($wanted) . ' day' . (count($wanted) === 1 ? '' : 's') . ' - please review them', mitTsLink($user_id, $period), intval($sheet['sheet_id']));
        }
    }
    return [true, '', count($wanted) + $answered];
}

/** Changes to a person's times in a period: rows, newest first. */
function mitTsCaChanges($user_id, array $period) {
    global $mysqli;
    if (!mitTsCaReady()) {
        return [];
    }
    $result = mysqli_query($mysqli, "SELECT change_id, work_date, changed_by, changed_by_name, changed_at, before_text, after_text, reason, acknowledged_at FROM mit_ts_changes
        WHERE user_id = " . intval($user_id) . " AND work_date BETWEEN '" . escapeSql($period['start']) . "' AND '" . escapeSql($period['end']) . "' ORDER BY change_id DESC");
    return mysqli_fetch_all($result, MYSQLI_ASSOC);
}

/** Acknowledge the changes others made to the signed-in person's times in a period. Returns how many. */
function mitTsCaAcknowledge(array $period) {
    global $mysqli, $session_user_id;
    mysqli_query($mysqli, "UPDATE mit_ts_changes SET acknowledged_at = NOW() WHERE user_id = " . intval($session_user_id) . " AND acknowledged_at IS NULL
        AND work_date BETWEEN '" . escapeSql($period['start']) . "' AND '" . escapeSql($period['end']) . "'");
    return mysqli_affected_rows($mysqli);
}

/*
 * ---------- before submitting, and the attestation ----------
 */

/**
 * What stops a period being submitted: days with time on tickets or work codes but no start and end
 * times, and meal questions not answered. Returns a list of sentences.
 */
function mitTsCaProblems($user_id, array $period) {
    $user_id = intval($user_id);
    $ca = mitTsCaDays([$user_id], $period['start'], $period['end'])[$user_id] ?? [];
    $days = mitTsDays([$user_id], $period['start'], $period['end'])[$user_id] ?? [];
    $problems = [];
    foreach ($ca as $date => $c) {
        $label = date('D M j', strtotime($date));
        $d = $days[$date] ?? [];
        $on_codes = intval($d['billable'] ?? 0) + intval($d['other'] ?? 0) + intval($d['work'] ?? 0) + intval($d['lost'] ?? 0);
        if (!$c['clocked'] && $on_codes) {
            $problems[] = "$label: hours on tickets or charge codes, but no start and end times";
        }
        foreach ($c['unanswered'] as $n) {
            $problems[] = "$label: " . mitTsCaIssueText($n, $c['issues'][$n]) . ' - say why';
        }
    }
    return $problems;
}

/** The statement the person signs, with their name in it. */
function mitTsCaStatement($name, array $period) {
    return "I, $name, certify that this timesheet for " . $period['label'] . " is complete and accurate. It shows every start and end time "
        . "of the work I did and of my meal periods, including any work before or after my shift, and I did not work off the clock. "
        . "Where a meal period was missed, late or short, or I could not take a rest break, I have said so on that day. "
        . "I understand I am owed one hour of premium pay for each day I was not able to take a meal period, and one for each day I was not "
        . "able to take a rest break, and that I can raise any problem with my hours without retaliation.";
}

/** The current (not set aside) attestation of a timesheet, or null. */
function mitTsCaAttestation($sheet_id) {
    global $mysqli;
    if (!mitTsCaReady() || !intval($sheet_id)) {
        return null;
    }
    return mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT attest_id, sheet_id, user_id, attested_at, signature_name, ip, text_version, statement, breaks_ok, exceptions, snapshot
        FROM mit_ts_attestations WHERE sheet_id = " . intval($sheet_id) . " AND superseded_at IS NULL ORDER BY attest_id DESC LIMIT 1")) ?: null;
}

/** Set a timesheet's signature aside (taken back, sent back, reopened). */
function mitTsCaSupersede($sheet_id) {
    global $mysqli;
    if (mitTsCaReady() && intval($sheet_id)) {
        mysqli_query($mysqli, "UPDATE mit_ts_attestations SET superseded_at = NOW() WHERE sheet_id = " . intval($sheet_id) . " AND superseded_at IS NULL");
    }
}

/**
 * Sign: $post has mit_ca_accurate, mit_ca_breaks (ok | exceptions), mit_ca_exceptions, mit_ca_signature. Only the
 * person signs their own. Stores the statement, their answers and a copy of the period's times and totals.
 * Returns [ok, message].
 */
function mitTsCaAttest(array $sheet, array $period, array $post) {
    global $mysqli, $session_user_id;
    $user_id = intval($sheet['user_id']);
    if ($user_id !== intval($session_user_id)) {
        return [false, 'Only they can sign their timesheet - ask them to submit it.'];
    }
    if ($problems = mitTsCaProblems($user_id, $period)) {
        return [false, 'Not submitted - ' . implode('; ', array_slice($problems, 0, 4)) . (count($problems) > 4 ? ' (and ' . (count($problems) - 4) . ' more)' : '') . '.'];
    }
    $user = mitTsUsers([$user_id])[$user_id] ?? null;
    $name = trim((string) ($post['mit_ca_signature'] ?? ''));
    $exceptions = trim((string) ($post['mit_ca_exceptions'] ?? ''));
    $breaks_ok = ($post['mit_ca_breaks'] ?? '') === 'ok';
    if (empty($post['mit_ca_accurate'])) {
        return [false, 'Not submitted - tick the box to certify your hours.'];
    }
    if (!$breaks_ok && ($post['mit_ca_breaks'] ?? '') !== 'exceptions') {
        return [false, 'Not submitted - say whether you had your meal periods and rest breaks.'];
    }
    if (!$breaks_ok && $exceptions === '') {
        return [false, 'Not submitted - describe the missed or short breaks.'];
    }
    if (!$user || mb_strtolower(preg_replace('/\s+/', ' ', $name)) !== mb_strtolower(preg_replace('/\s+/', ' ', trim($user['user_name'])))) {
        return [false, 'Not submitted - type your name exactly as ' . ($user['user_name'] ?? 'it is on your account') . ' to sign.'];
    }
    $sheet = mitTsSheetSaved($user_id, $period);
    $ca = mitTsCaDays([$user_id], $period['start'], $period['end'])[$user_id] ?? [];
    $totals = mitTsTotals([$user_id], $period['start'], $period['end'])[$user_id];
    $snapshot = ['days' => [], 'totals' => array_intersect_key($totals, array_flip(['worked', 'leave', 'paid', 'regular', 'overtime', 'double', 'meal_premium', 'rest_premium']))];
    foreach ($ca as $date => $c) {
        if ($c['periods'] || $c['issues'] || $c['answers']['rest_missed']) {
            $snapshot['days'][$date] = ['times' => mitTsCaPeriodsText($c['periods']), 'worked' => $c['clocked'],
                'meals' => array_map(function ($n) use ($c) {
                    return mitTsCaIssueText($n, $c['issues'][$n]) . ': ' . (MIT_TS_CA_ANSWERS[$c['answers']["meal$n"]] ?? 'not answered');
                }, array_keys($c['issues'])), 'rest_missed' => $c['answers']['rest_missed']];
        }
    }
    $statement = mitTsCaStatement($user['user_name'], $period);
    mitTsCaSupersede($sheet['sheet_id']);
    mysqli_query($mysqli, "INSERT INTO mit_ts_attestations SET sheet_id = " . intval($sheet['sheet_id']) . ", user_id = $user_id,
        period_start = '" . escapeSql($period['start']) . "', period_end = '" . escapeSql($period['end']) . "',
        signature_name = '" . escapeSql(mb_substr($name, 0, 200)) . "', ip = '" . escapeSql(mb_substr((string) getIP(), 0, 64)) . "',
        user_agent = '" . escapeSql(mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255)) . "', text_version = '" . MIT_TS_CA_ATTEST_VERSION . "',
        statement = '" . mysqli_real_escape_string($mysqli, $statement) . "', breaks_ok = " . ($breaks_ok ? 1 : 0) . ",
        exceptions = " . ($breaks_ok ? 'NULL' : "'" . mysqli_real_escape_string($mysqli, mb_substr($exceptions, 0, 2000)) . "'") . ",
        snapshot = '" . mysqli_real_escape_string($mysqli, json_encode($snapshot)) . "'");
    // Signing acknowledges the changes others made, which the statement covers
    mitTsCaAcknowledge($period);
    logAudit('Timesheet', 'Edit', escapeSql($user['user_name'] . ' signed the attestation for the timesheet of ' . $period['label']), 0, $user_id);
    return [true, 'Signed'];
}

/** Changes others made after the person signed, or not yet acknowledged - for the approver. */
function mitTsCaUnacknowledged($user_id, array $period) {
    return array_values(array_filter(mitTsCaChanges($user_id, $period), function ($c) {
        return $c['acknowledged_at'] === null;
    }));
}

/*
 * ---------- on the timesheet page (agent/mit_timesheet.php - the day rows and panel are agent/js/mit_timesheet.js) ----------
 */

/** Changes to the times: the person acknowledges those others made; everyone sees the history. */
function mitTsCaChangesBox($user_id, array $period, $own) {
    if (!mitTsCaOn($user_id, $period['end'])) {
        return '';
    }
    $changes = mitTsCaChanges($user_id, $period);
    $others = array_values(array_filter($changes, function ($c) use ($user_id) {
        return intval($c['changed_by']) !== intval($user_id);
    }));
    if (!$others) {
        return $changes ? '<details class="small text-muted mb-3"><summary>Change history (' . count($changes) . ')</summary>' . mitTsCaChangesTable($changes) . '</details>' : '';
    }
    $waiting = array_filter($others, function ($c) {
        return $c['acknowledged_at'] === null;
    });
    $html = '<div class="alert ' . ($waiting ? 'alert-warning' : 'alert-light border') . ' small mb-3"><div class="d-flex flex-wrap align-items-center gap-2 mb-2"><strong class="me-auto"><i class="fas fa-user-edit me-1"></i>'
        . ($waiting ? count($waiting) . ' change' . (count($waiting) === 1 ? '' : 's') . ' to ' . ($own ? 'your' : 'their') . ' times made by someone else - ' . ($own ? 'please review' : 'waiting for them to acknowledge') : 'Changes made by someone else (acknowledged)') . '</strong>';
    if ($own && $waiting) {
        $html .= '<a class="btn btn-sm btn-warning" href="post.php?mit_ca_acknowledge=' . $period['start'] . '&csrf_token=' . escapeHtml($_SESSION['csrf_token']) . '"><i class="fas fa-check me-1"></i>I have reviewed these</a>';
    }
    return $html . '</div>' . mitTsCaChangesTable($others) . (count($changes) > count($others) ? '<details class="mt-2"><summary>All changes (' . count($changes) . ')</summary>' . mitTsCaChangesTable($changes) . '</details>' : '') . '</div>';
}

function mitTsCaChangesTable(array $changes) {
    $html = '<div class="table-responsive"><table class="table table-sm mb-0 small"><thead><tr><th>Day</th><th>Changed</th><th>By</th><th>Before</th><th>After</th><th>Why</th><th>Acknowledged</th></tr></thead><tbody>';
    foreach ($changes as $c) {
        $html .= '<tr><td class="text-nowrap">' . date('D M j', strtotime($c['work_date'])) . '</td><td class="text-nowrap">' . date('M j, g:i A', strtotime($c['changed_at'])) . '</td><td>' . escapeHtml($c['changed_by_name'])
            . '</td><td>' . escapeHtml($c['before_text']) . '</td><td>' . escapeHtml($c['after_text']) . '</td><td>' . escapeHtml((string) $c['reason'])
            . '</td><td class="text-nowrap">' . ($c['acknowledged_at'] ? date('M j, g:i A', strtotime($c['acknowledged_at'])) : '<span class="text-warning-emphasis">Not yet</span>') . '</td></tr>';
    }
    return $html . '</tbody></table></div>';
}

/** The signing window, inside the timesheet form - opened by the script when Submit is pressed. */
function mitTsCaSignModal($user_id, array $period) {
    global $session_name;
    $user = mitTsUsers([$user_id])[intval($user_id)] ?? ['user_name' => stripslashes((string) $session_name)];
    $problems = mitTsCaProblems($user_id, $period);
    return '<div class="modal fade" id="mitCaSign" tabindex="-1" aria-hidden="true"><div class="modal-dialog modal-lg"><div class="modal-content">'
        . '<div class="modal-header bg-dark"><h5 class="modal-title"><i class="fas fa-file-signature me-2"></i>Sign and submit your hours</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>'
        . '<div class="modal-body">'
        // Kept current by the page's script after each save
        . '<div data-mit-ca-problems>' . mitTsCaProblemsHtml($problems) . '</div>'
        . '<p class="border rounded p-3 bg-body-tertiary">' . escapeHtml(mitTsCaStatement($user['user_name'], $period)) . '</p>'
        . '<div class="form-check mb-3"><input class="form-check-input" type="checkbox" name="mit_ca_accurate" value="1" id="mitCaAccurate"><label class="form-check-label" for="mitCaAccurate">'
        . 'I certify the times on this timesheet are complete and accurate, and I did not work off the clock.</label></div>'
        . '<div class="mb-2 fw-semibold">Meal periods and rest breaks</div>'
        . '<div class="form-check"><input class="form-check-input" type="radio" name="mit_ca_breaks" value="ok" id="mitCaBreaksOk"><label class="form-check-label" for="mitCaBreaksOk">'
        . 'I was able to take every meal period and rest break I was entitled to, except where I said otherwise on the day.</label></div>'
        . '<div class="form-check mb-2"><input class="form-check-input" type="radio" name="mit_ca_breaks" value="exceptions" id="mitCaBreaksNo"><label class="form-check-label" for="mitCaBreaksNo">'
        . 'Something else to report about my breaks or hours:</label></div>'
        . '<textarea class="form-control mb-3" name="mit_ca_exceptions" rows="2" maxlength="2000" placeholder="e.g. On Tuesday I could not take my afternoon rest break; I answered a call during lunch on the 9th"></textarea>'
        . '<label class="form-label">Type your full name to sign <span class="text-muted small">(' . escapeHtml($user['user_name']) . ')</span></label>'
        . '<input type="text" class="form-control" name="mit_ca_signature" maxlength="200" autocomplete="off">'
        . '<div class="form-text">Signed electronically with the date, time and where it was sent from. Your manager is told about anything you report.</div>'
        . '</div><div class="modal-footer"><button type="submit" name="mit_ts_save" value="submit" class="btn btn-success"><i class="fas fa-file-signature me-2"></i>Sign and submit</button>'
        . '<button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button></div></div></div></div>';
}

/** The "before you can submit" list for the signing window ('' when there is nothing). */
function mitTsCaProblemsHtml(array $problems) {
    return $problems ? '<div class="alert alert-warning small"><strong>Before you can submit:</strong><ul class="mb-0">' . implode('', array_map(function ($p) {
        return '<li>' . escapeHtml($p) . '</li>';
    }, $problems)) . '</ul></div>' : '';
}

/** What the approver sees about the signature, in the Approve window. */
function mitTsCaApproverNote($user_id, array $period, array $sheet) {
    if (!mitTsCaOn($user_id, $period['end'])) {
        return '';
    }
    $attest = mitTsCaAttestation($sheet['sheet_id']);
    $t = mitTsTotals([$user_id], $period['start'], $period['end'])[intval($user_id)];
    $html = '<div class="border rounded p-2 small mb-3"><div class="fw-semibold mb-1"><i class="fas fa-user-clock me-1"></i>California non-exempt</div>'
        . mitTsHours($t['worked'], false) . ' h worked · ' . mitTsHours($t['overtime'], false) . ' h overtime · ' . mitTsHours($t['double'], false) . ' h double time'
        . ($t['meal_premium'] ? ' · <span class="text-danger">' . intdiv($t['meal_premium'], 60) . ' meal premium hour(s)</span>' : '')
        . ($t['rest_premium'] ? ' · <span class="text-danger">' . intdiv($t['rest_premium'], 60) . ' rest premium hour(s)</span>' : '') . '<br>';
    if ($attest) {
        $html .= '<i class="fas fa-file-signature text-success me-1"></i>Signed by ' . escapeHtml($attest['signature_name']) . ' on ' . date('M j, g:i A', strtotime($attest['attested_at']));
        if (!intval($attest['breaks_ok'])) {
            $html .= '<div class="alert alert-warning py-1 px-2 mt-1 mb-0"><strong>They reported:</strong> ' . nl2br(escapeHtml((string) $attest['exceptions'])) . '</div>';
        }
    } else {
        $html .= '<span class="text-danger"><i class="fas fa-exclamation-circle me-1"></i>Not signed by them yet - it cannot be approved until they submit it.</span>';
    }
    $waiting = mitTsCaUnacknowledged($user_id, $period);
    if ($waiting) {
        $html .= '<div class="text-warning-emphasis mt-1"><i class="fas fa-user-edit me-1"></i>' . count($waiting) . ' change(s) to their times they have not acknowledged yet.</div>';
    }
    return $html . '</div>';
}
