<?php

/*
 * Mountaineer IT - timesheets (database updates M012, M013).
 *
 * Every agent (unless left out on the People tab) keeps a timesheet per pay period (semi-monthly by default - 1st to 15th, 16th to the
 * end). Everything is set in Admin > Timesheets (admin/mit_timesheets.php): company settings in
 * mit_settings (ts_*, mitTsSettings), charge codes, and each person's schedule, approver, employee ID
 * and pay type (mit_timesheet_people); personal choices in mit_user_preferences (timesheet.*).
 * PTO / sick balances are in functions/mit_leave.php (required at the end).
 * A day's hours are:
 *   - ticket time, filled in on its own from the time logged on ticket replies that day - billable
 *     when the ticket is billable, the rest "other ticket time";
 *   - hours put against charge codes (mit_charge_codes): work (Admin, Training ...), lost time,
 *     leave (PTO, Holiday, Sick ...) and breaks (Lunch - logged by the person; not worked, unpaid unless
 *     the code is paid), each paid or unpaid;
 *   - paid short breaks, worked out from the hours worked (Admin > Timesheets, e.g. 15 minutes for every
 *     4 hours; a person can have their own rule) - nothing to log; they count as paid time and toward
 *     overtime, not toward the billable share.
 * The person submits the period, someone with Full Timesheets access approves it (or sends it
 * back), and an approved period is locked. Approving copies the period's ticket time and unaccounted
 * time in as entries (source ticket_billable / ticket_other / auto_lost, code 0), so payroll does not
 * move if a reply or a schedule changes later. Overtime is worked out from those with the current
 * overtime settings.
 *
 * Permission module_timesheets: anyone can keep their own; View 1 sees everyone's and the payroll
 * report (agent/reports/mit_payroll.php), Edit 2 corrects other people's, Full 3 approves (and may
 * change their own after submitting). Admins are Full.
 *
 * A person's own approver (People tab) can see and approve their timesheets too.
 * Optional: overtime over a day / week, unaccounted hours as lost time (unpaid unless set to paid),
 * reminders (cron job mit_timesheets, cron/mit_timesheets.php), notes on hours (required on some
 * codes), codes for some roles only. People can be left out of timesheets (People tab, "Keeps a
 * timesheet" - database update M015).
 *
 * Pages: agent/mit_timesheet.php (one person's period), agent/mit_timesheets.php (everyone, one
 * period), agent/modals/mit_timesheet/day.php (a day's tickets), handlers agent/post/mit_timesheets.php,
 * side menu agent/includes/mit_timesheet_nav.php, Admin > Timesheets (admin/mit_timesheets.php),
 * grid script agent/js/mit_timesheet.js.
 */

// break: lunch and the like - accounted for (not lost) but not worked; unpaid unless the code says paid (M016)
const MIT_TS_KINDS = ['work' => 'Work', 'lost' => 'Lost time', 'leave' => 'Leave', 'break' => 'Break / lunch (not worked)'];
const MIT_TS_PERIODS = ['semi_monthly' => 'Semi-monthly (1st-15th, 16th-end)', 'monthly' => 'Monthly', 'biweekly' => 'Every two weeks', 'weekly' => 'Weekly'];
const MIT_TS_PAY_TYPES = ['hourly' => 'Hourly', 'salary' => 'Salaried (no overtime)'];
const MIT_TS_WEEKDAYS = [1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun'];
const MIT_TS_STATUSES = [
    'open' => ['Open', 'text-bg-light border'],
    'submitted' => ['Submitted', 'text-bg-warning'],
    'returned' => ['Sent back', 'text-bg-danger'],
    'approved' => ['Approved', 'text-bg-success'],
];

/** Has database update M012 run? */
function mitTsReady() {
    global $mysqli;
    static $ready = null;
    if ($ready === null) {
        try {
            $ready = mysqli_num_rows(mysqli_query($mysqli, "SHOW TABLES LIKE 'mit_timesheets'")) === 1;
        } catch (Throwable $e) {
            $ready = false;
        }
    }
    return $ready;
}

/** Are timesheets in use: database update M012 run, and switched on (Admin > Settings > Modules)? */
function mitTsOn() {
    return mitTsReady() && mitModuleOn('timesheets');
}

/** The signed-in agent's Timesheets level (0 = own timesheet only). */
function mitTsLevel() {
    return mitTsReady() ? intval(lookupUserPermission('module_timesheets')) : 0;
}

/**
 * The report's entry in ITFlow's report catalogue (functions/reports.php). Switched off (Admin > Settings >
 * Modules), it names a feature reportFeatureOn() does not know, so the catalogue hides it and reportEnforce() refuses it.
 */
function mitTsReportEntry() {
    return [
        'title' => 'Timesheets & Payroll', 'icon' => 'fa-business-time', 'module' => 'module_timesheets', 'feature' => mitModuleOn('timesheets') ? [] : ['mit_timesheets'],
        'description' => 'Hours for each person by charge code - billable, other ticket time, admin, lost time and leave - added up for payroll.',
    ];
}

/*
 * ---------- settings ----------
 */

/**
 * The company's timesheet settings, with today's behavior as the defaults:
 *   week_start 1 (Mon) | 7 (Sun); approval 1/0; future all | leave | none (days after today);
 *   autolock_days (0 = never - otherwise a period locks that many days after it ends);
 *   remind_due 1/0 (on a period's last day); remind_late_days (0 = off); format decimal | hm;
 *   rounding 0 | 6 | 15 | 30 (ticket time per day); ticket_time 1/0; ticket_internal 1/0 (internal
 *   notes' time counts); billable flag | client; schedule '' (from Admin > SLA) or minutes Mon..Sun;
 *   ot_day / ot_week minutes (0 = off); auto_lost 1/0; auto_lost_paid 1/0 (unaccounted time is
 *   unpaid unless this is on); break_minutes / break_per - paid breaks, break_minutes for every break_per
 *   minutes worked (0 = off; a person can have their own - mitTsBreakRule).
 */
function mitTsSettings() {
    static $settings = null;
    if ($settings === null) {
        $defaults = ['week_start' => 1, 'approval' => 1, 'future' => 'all', 'autolock_days' => 0, 'remind_due' => 0, 'remind_late_days' => 0,
            'format' => 'decimal', 'rounding' => 0, 'ticket_time' => 1, 'ticket_internal' => 1, 'billable' => 'flag', 'schedule' => '',
            'ot_day' => 0, 'ot_week' => 0, 'auto_lost' => 0, 'auto_lost_paid' => 0, 'break_minutes' => 0, 'break_per' => 240];
        $settings = [];
        foreach ($defaults as $key => $default) {
            $value = mitSetting("ts_$key", $default);
            $settings[$key] = is_int($default) ? intval($value) : (string) $value;
        }
        $settings['week_start'] = $settings['week_start'] === 7 ? 7 : 1;
        $settings['future'] = in_array($settings['future'], ['all', 'leave', 'none'], true) ? $settings['future'] : 'all';
        $settings['format'] = $settings['format'] === 'hm' ? 'hm' : 'decimal';
        $settings['rounding'] = in_array($settings['rounding'], [0, 6, 15, 30], true) ? $settings['rounding'] : 0;
        $settings['billable'] = $settings['billable'] === 'client' ? 'client' : 'flag';
        $settings['schedule'] = mitTsScheduleClean($settings['schedule']) ?? '';
    }
    return $settings;
}

/** "480,480,480,480,480,0,0" if it is a valid week of minutes, else null. */
function mitTsScheduleClean($text) {
    $parts = explode(',', (string) $text);
    if (count($parts) !== 7) {
        return null;
    }
    foreach ($parts as $part) {
        if (!preg_match('/^\d{1,4}$/', trim($part)) || intval($part) > 1440) {
            return null;
        }
    }
    return implode(',', array_map('intval', $parts));
}

/** Hours a day from a form's schedule[1..7] - "480,480,..." , '' when all empty, or null when one cannot be read. */
function mitTsScheduleFromPost($input) {
    $minutes = [];
    $any = false;
    foreach (range(1, 7) as $n) {
        $text = trim((string) ($input[$n] ?? ''));
        $any = $any || $text !== '';
        $m = mitTsParseHours($text);
        if ($m === null || $m > 1440) {
            return null;
        }
        $minutes[] = $m;
    }
    return $any ? implode(',', $minutes) : '';
}

/** The company's normal week, [1..7 => minutes]: the setting, or the business days and hours in Admin > SLA. */
function mitTsCompanySchedule() {
    $schedule = mitTsSettings()['schedule'];
    if ($schedule !== '') {
        return array_combine(range(1, 7), array_map('intval', explode(',', $schedule)));
    }
    $sla = getSlaSettings();
    $days = $sla['business_days'] ?: [1, 2, 3, 4, 5];
    $minutes = (int) round(reportWorkdayHours() * 60);
    $week = [];
    foreach (range(1, 7) as $day) {
        $week[$day] = in_array($day, $days, true) ? $minutes : 0;
    }
    return $week;
}

/** Each person's timesheet details (People tab): user_id => [schedule, approver_id, employee_id, pay_type]. */
function mitTsPeople() {
    global $mysqli;
    static $people = null;
    if ($people === null) {
        $people = [];
        try {
            // Every column on purpose: later updates add to this small table (M014 hire_date) and it must read before they run
            $result = mysqli_query($mysqli, "SELECT * FROM mit_timesheet_people");
            while ($result && $row = mysqli_fetch_assoc($result)) {
                $people[intval($row['user_id'])] = $row;
            }
        } catch (Throwable $e) {
            // database update M013 not run yet
        }
    }
    return $people;
}

/** One person's details, with the defaults. */
function mitTsPerson($user_id) {
    $row = mitTsPeople()[intval($user_id)] ?? [];
    return [
        'schedule' => mitTsScheduleClean($row['schedule'] ?? '') ?? '',
        'approver_id' => intval($row['approver_id'] ?? 0),
        'employee_id' => (string) ($row['employee_id'] ?? ''),
        'pay_type' => isset(MIT_TS_PAY_TYPES[$row['pay_type'] ?? '']) ? $row['pay_type'] : 'hourly',
        // Their own paid-break rule (M016) - null = the company's
        'break_minutes' => isset($row['break_minutes']) ? intval($row['break_minutes']) : null,
        'break_per' => isset($row['break_per']) ? intval($row['break_per']) : null,
    ];
}

/** A person's paid-break rule: [minutes, for every so many minutes worked] - their own, else the company's. [0, x] = none. */
function mitTsBreakRule($user_id) {
    $person = mitTsPerson($user_id);
    $settings = mitTsSettings();
    $minutes = $person['break_minutes'] ?? $settings['break_minutes'];
    $per = $person['break_per'] ?? $settings['break_per'];
    return [max(0, intval($minutes)), max(30, intval($per))];
}

/** Paid break minutes earned by a day's worked minutes under a rule, e.g. 15 for every 240 -> 30 for 8 hours. */
function mitTsBreakFor($worked, array $rule) {
    [$minutes, $per] = $rule;
    return $minutes > 0 && $worked > 0 ? intdiv(intval($worked), $per) * $minutes : 0;
}

/** Does this person keep a timesheet? (People tab - on unless turned off; M015.) */
function mitTsKeepsTimesheet($user_id) {
    $row = mitTsPeople()[intval($user_id)] ?? [];
    return !isset($row['keeps_timesheet']) || intval($row['keeps_timesheet']) === 1;
}

/** Has database update M015 added the "keeps a timesheet" column yet? */
function mitTsKeepsColumn() {
    global $mysqli;
    static $ready = null;
    if ($ready === null) {
        try {
            $ready = mysqli_num_rows(mysqli_query($mysqli, "SHOW COLUMNS FROM mit_timesheet_people LIKE 'keeps_timesheet'")) === 1;
        } catch (Throwable $e) {
            $ready = false;
        }
    }
    return $ready;
}

/** Has database update M016 added the per-person break columns yet? */
function mitTsBreakColumns() {
    global $mysqli;
    static $ready = null;
    if ($ready === null) {
        try {
            $ready = mysqli_num_rows(mysqli_query($mysqli, "SHOW COLUMNS FROM mit_timesheet_people LIKE 'break_per'")) === 1;
        } catch (Throwable $e) {
            $ready = false;
        }
    }
    return $ready;
}

/** The first day a person can owe hours: their hire date, else the day their ITFlow login was made. */
function mitTsStartDate($user_id) {
    global $mysqli;
    static $created = null;
    $hire = (string) (mitTsPeople()[intval($user_id)]['hire_date'] ?? '');
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $hire)) {
        return $hire;
    }
    if ($created === null) {
        $created = [];
        $result = mysqli_query($mysqli, "SELECT user_id, DATE(user_created_at) AS d FROM users WHERE user_type = 1");
        while ($row = mysqli_fetch_assoc($result)) {
            $created[intval($row['user_id'])] = (string) $row['d'];
        }
    }
    return $created[intval($user_id)] ?? '';
}

/** A person's normal week, [1..7 => minutes]. */
function mitTsSchedule($user_id) {
    $own = mitTsPerson($user_id)['schedule'];
    return $own !== '' ? array_combine(range(1, 7), array_map('intval', explode(',', $own))) : mitTsCompanySchedule();
}

/** The minutes a person is expected to work on a date - none on closure days. */
function mitTsExpected($user_id, $date) {
    if (mitTsClosure($date) !== '') {
        return 0;
    }
    return mitTsSchedule($user_id)[intval(date('N', strtotime($date)))] ?? 0;
}

/** The hour format for this person: their choice, else the company's - decimal | hm. */
function mitTsFormat($user_id = null) {
    global $session_user_id;
    $mine = (string) mitUserPreference($user_id ?? $session_user_id, 'timesheet.format', '');
    return in_array($mine, ['decimal', 'hm'], true) ? $mine : mitTsSettings()['format'];
}

/*
 * ---------- pay periods ----------
 */

/** [type, anchor] - the anchor is a first day, for every-two-weeks. */
function mitTsPeriodSettings() {
    $type = (string) mitSetting('timesheet_period', 'semi_monthly');
    $anchor = (string) mitSetting('timesheet_anchor', '2026-01-05');
    return [isset(MIT_TS_PERIODS[$type]) ? $type : 'semi_monthly', preg_match('/^\d{4}-\d{2}-\d{2}$/', $anchor) ? $anchor : '2026-01-05'];
}

/** The pay period a date falls in: ['start', 'end', 'label']. */
function mitTsPeriodFor($date = null) {
    [$type, $anchor] = mitTsPeriodSettings();
    $time = strtotime($date ?: date('Y-m-d')) ?: time();
    $day = intval(date('j', $time));
    switch ($type) {
        case 'monthly':
            $start = date('Y-m-01', $time);
            $end = date('Y-m-t', $time);
            break;
        case 'weekly':
            $start = mitTsWeekStart(date('Y-m-d', $time));
            $end = date('Y-m-d', strtotime("$start +6 days"));
            break;
        case 'biweekly':
            $length = 14;
            $anchor_time = strtotime($anchor);
            $days = (int) round(($time - $anchor_time) / 86400);
            $offset = (($days % $length) + $length) % $length;
            $start = date('Y-m-d', strtotime("-$offset days", $time));
            $end = date('Y-m-d', strtotime('+' . ($length - 1) . ' days', strtotime($start)));
            break;
        default:
            $start = $day <= 15 ? date('Y-m-01', $time) : date('Y-m-16', $time);
            $end = $day <= 15 ? date('Y-m-15', $time) : date('Y-m-t', $time);
    }
    return ['start' => $start, 'end' => $end, 'label' => mitTsRangeText($start, $end)];
}

/** "Oct 1 - 15, 2026" */
function mitTsRangeText($start, $end) {
    $s = strtotime($start);
    $e = strtotime($end);
    if (date('Y-m', $s) === date('Y-m', $e)) {
        return date('M j', $s) . ' - ' . date('j, Y', $e);
    }
    return date('M j', $s) . ' - ' . date('M j, Y', $e);
}

/** The period before (-1) or after (+1) this one. */
function mitTsPeriodShift(array $period, $step) {
    return $step < 0 ? mitTsPeriodFor(date('Y-m-d', strtotime($period['start'] . ' -1 day'))) : mitTsPeriodFor(date('Y-m-d', strtotime($period['end'] . ' +1 day')));
}

/** The period starting on ?start= (any date in it will do), or the current one. */
function mitTsPeriodFromRequest($value) {
    $value = (string) $value;
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) && strtotime($value) ? mitTsPeriodFor($value) : mitTsPeriodFor();
}

/** The dates of a period, Y-m-d. */
function mitTsDates(array $period) {
    $dates = [];
    for ($t = strtotime($period['start']), $guard = 0; $t <= strtotime($period['end']) && $guard < 40; $t = strtotime('+1 day', $t), $guard++) {
        $dates[] = date('Y-m-d', $t);
    }
    return $dates;
}

/** The first day of the week a date is in (weeks start on Monday or Sunday - a setting). */
function mitTsWeekStart($date) {
    $offset = (intval(date('N', strtotime($date))) - mitTsSettings()['week_start'] + 7) % 7;
    return date('Y-m-d', strtotime("$date -$offset days"));
}

/** The period's dates in weeks, for the grid. */
function mitTsWeeks(array $period) {
    $weeks = [];
    foreach (mitTsDates($period) as $date) {
        $weeks[mitTsWeekStart($date)][] = $date;
    }
    return array_values($weeks);
}

/** A closure day's name (Admin > SLA), or ''. */
function mitTsClosure($date) {
    return (string) (getBusinessHolidays()[$date] ?? '');
}

/*
 * ---------- hours ----------
 */

/** Minutes as hours for people: 450 -> "7.5" (or "7:30" in the hours-and-minutes format), 0 -> "". */
function mitTsHours($minutes, $blank_zero = true, $format = null) {
    $minutes = intval($minutes);
    if ($minutes === 0 && $blank_zero) {
        return '';
    }
    if (($format ?? mitTsFormat()) === 'hm') {
        return ($minutes < 0 ? '-' : '') . intdiv(abs($minutes), 60) . ':' . sprintf('%02d', abs($minutes) % 60);
    }
    return rtrim(rtrim(number_format($minutes / 60, 2, '.', ''), '0'), '.');
}

/** "7.5", "7:30", "450m", "7h30" -> minutes; '' -> 0; null when it cannot be read. */
function mitTsParseHours($text) {
    $text = strtolower(trim(str_replace(',', '.', (string) $text)));
    if ($text === '') {
        return 0;
    }
    // Hours and minutes: 7:30, 7h30, 7h 30m - the minutes must be under 60
    if (preg_match('/^(\d{1,2}):(\d{1,2})$/', $text, $m) || preg_match('/^(\d{1,2})h\s*(\d{1,2})?m?$/', $text, $m)) {
        return intval($m[2] ?? 0) < 60 ? intval($m[1]) * 60 + intval($m[2] ?? 0) : null;
    }
    if (preg_match('/^(\d{1,4})\s*m(in)?$/', $text, $m)) {
        return intval($m[1]);
    }
    if (preg_match('/^\d{0,2}(\.\d{1,2})?$/', $text) && $text !== '.') {
        return (int) round((float) $text * 60);
    }
    return null;
}

/** Hours of any size for settings ("80", "3.33", "7:30") -> minutes; '' -> 0; null when it cannot be read. */
function mitTsParseHoursAny($text) {
    $text = trim(str_replace(',', '.', (string) $text));
    if ($text === '') {
        return 0;
    }
    if (is_numeric($text) && (float) $text >= 0 && (float) $text <= 10000) {
        return (int) round((float) $text * 60);
    }
    return mitTsParseHours($text);
}

/*
 * ---------- charge codes ----------
 */

/** code_id => row, in order. Archived ones too when asked (an old period can still have them). */
function mitTsCodes($with_archived = false) {
    global $mysqli;
    static $cache = [];
    $key = $with_archived ? 'all' : 'active';
    if (!isset($cache[$key])) {
        $cache[$key] = [];
        if (mitTsReady()) {
            // Every column on purpose: M013 added columns, and the codes must still read before it has run
            $result = mysqli_query($mysqli, "SELECT * FROM mit_charge_codes" . ($with_archived ? '' : ' WHERE code_archived_at IS NULL') . " ORDER BY code_sort, code_name");
            while ($row = mysqli_fetch_assoc($result)) {
                $cache[$key][intval($row['code_id'])] = $row;
            }
        }
    }
    return $cache[$key];
}

/** The active codes a person may use - all, or those given to their role. */
function mitTsCodesFor($user_id) {
    global $mysqli;
    $role = intval(mysqli_fetch_row(mysqli_query($mysqli, "SELECT user_role_id FROM users WHERE user_id = " . intval($user_id)))[0] ?? 0);
    return array_filter(mitTsCodes(), function ($code) use ($role) {
        $roles = array_filter(array_map('intval', explode(',', (string) ($code['code_roles'] ?? ''))));
        return !$roles || in_array($role, $roles, true);
    });
}

/** A small color dot for a code. */
function mitTsCodeDot(array $code) {
    $color = preg_match('/^#[0-9a-f]{3,8}$/i', (string) $code['code_color']) ? $code['code_color'] : '#6c757d';
    return '<span class="d-inline-block rounded-circle me-2" style="width: 10px; height: 10px; background: ' . $color . '"></span>';
}

/*
 * ---------- people and timesheets ----------
 */

/**
 * Agents who keep timesheets: active ones not left out on the People tab, plus anyone named in $also
 * (someone who has left, or was left out, but has hours in the dates being looked at). $everyone: the
 * people left out too (Admin > Timesheets > People). user_id => row
 */
function mitTsUsers(array $also = [], $everyone = false) {
    global $mysqli;
    $also = array_filter(array_map('intval', $also));
    $result = mysqli_query($mysqli, "SELECT user_id, user_name, user_email, user_avatar, user_status FROM users WHERE user_type = 1
        AND ((user_status = 1 AND user_archived_at IS NULL)" . ($also ? ' OR user_id IN (' . implode(',', $also) . ')' : '') . ") ORDER BY user_name");
    $users = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $user_id = intval($row['user_id']);
        if ($everyone || mitTsKeepsTimesheet($user_id) || in_array($user_id, $also, true)) {
            $users[$user_id] = $row;
        }
    }
    return $users;
}

/** People with anything on a timesheet in these dates (hours, or a stored timesheet) - so someone who has left still shows. */
function mitTsUsersWithActivity($from, $to) {
    global $mysqli;
    $ids = [];
    if (!mitTsReady()) {
        return $ids;
    }
    $from = escapeSql($from);
    $to = escapeSql($to);
    $result = mysqli_query($mysqli, "SELECT DISTINCT user_id FROM mit_time_entries WHERE entry_date BETWEEN '$from' AND '$to' AND minutes > 0
        UNION SELECT user_id FROM mit_timesheets WHERE period_end >= '$from' AND period_start <= '$to' AND status <> 'open'");
    while ($row = mysqli_fetch_row($result)) {
        $ids[] = intval($row[0]);
    }
    return $ids;
}

/** The person's timesheet for a period - a stored row, or an open one not saved yet (sheet_id 0). */
function mitTsSheet($user_id, array $period) {
    global $mysqli;
    // s.* on purpose: M013 added the reminder columns, and a timesheet must still read before it has run
    $row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT s.*, u.user_name AS decided_by_name FROM mit_timesheets s LEFT JOIN users u ON u.user_id = s.decided_by
        WHERE s.user_id = " . intval($user_id) . " AND s.period_start = '" . escapeSql($period['start']) . "'"));
    return $row ?: ['sheet_id' => 0, 'user_id' => intval($user_id), 'period_start' => $period['start'], 'period_end' => $period['end'], 'status' => 'open',
        'submitted_at' => null, 'decided_by' => 0, 'decided_by_name' => null, 'decided_at' => null, 'decision_note' => null, 'reminded_due_at' => null, 'reminded_late_at' => null];
}

/** The stored row, made if it is not there yet. */
function mitTsSheetSaved($user_id, array $period) {
    global $mysqli;
    mysqli_query($mysqli, "INSERT IGNORE INTO mit_timesheets SET user_id = " . intval($user_id) . ", period_start = '" . escapeSql($period['start']) . "', period_end = '" . escapeSql($period['end']) . "'");
    return mitTsSheet($user_id, $period);
}

/** Timesheets of several people for one period: user_id => row (only stored ones). */
function mitTsSheets(array $user_ids, array $period) {
    global $mysqli;
    $user_ids = array_filter(array_map('intval', $user_ids));
    $sheets = [];
    if ($user_ids) {
        $result = mysqli_query($mysqli, "SELECT sheet_id, user_id, period_start, period_end, status FROM mit_timesheets WHERE period_start = '" . escapeSql($period['start']) . "' AND user_id IN (" . implode(',', $user_ids) . ")");
        while ($row = mysqli_fetch_assoc($result)) {
            $sheets[intval($row['user_id'])] = $row;
        }
    }
    return $sheets;
}

/** A status badge. */
function mitTsBadge($status) {
    [$label, $class] = MIT_TS_STATUSES[$status] ?? MIT_TS_STATUSES['open'];
    return '<span class="badge ' . $class . '">' . $label . '</span>';
}

/** The people whose own approver (People tab) is the signed-in agent. */
function mitTsMyReports() {
    global $session_user_id;
    $ids = [];
    foreach (mitTsPeople() as $user_id => $row) {
        if (intval($row['approver_id']) === intval($session_user_id) && $user_id !== intval($session_user_id)) {
            $ids[] = $user_id;
        }
    }
    return $ids;
}

/** May the signed-in agent see this person's timesheets? Their own, Timesheets View, or their approver. */
function mitTsMaySee($user_id) {
    global $session_user_id;
    return intval($user_id) === intval($session_user_id) || mitTsLevel() >= 1 || in_array(intval($user_id), mitTsMyReports(), true);
}

/** May the signed-in agent approve this person's timesheets? Full access, or their approver. */
function mitTsMayApprove($user_id) {
    return mitTsLevel() >= 3 || in_array(intval($user_id), mitTsMyReports(), true);
}

/** May the signed-in agent correct this person's hours? Timesheets Edit, or their approver. */
function mitTsMayCorrect($user_id) {
    return mitTsLevel() >= 2 || in_array(intval($user_id), mitTsMyReports(), true);
}

/**
 * The people the signed-in agent may look at (Everyone page, person picker, payroll report): everyone
 * with Timesheets View, else the people they approve. With a period, people who have left or were
 * left out but have hours in it are included. user_id => row
 */
function mitTsVisibleUsers(?array $period = null) {
    $users = mitTsUsers($period ? mitTsUsersWithActivity($period['start'], $period['end']) : []);
    if (mitTsLevel() >= 1) {
        return $users;
    }
    return array_intersect_key($users, array_flip(mitTsMyReports()));
}

/** Has the period locked itself (a set number of days after it ended)? */
function mitTsAutoLocked(array $sheet) {
    $days = mitTsSettings()['autolock_days'];
    return $days > 0 && $sheet['status'] !== 'approved' && date('Y-m-d') > date('Y-m-d', strtotime($sheet['period_end'] . " +$days days"));
}

/** May the signed-in agent change this timesheet's hours? Returns '' or why not. */
function mitTsWhyLocked(array $sheet) {
    global $session_user_id;
    $own = intval($sheet['user_id']) === intval($session_user_id);
    // Edit access corrects other people's timesheets; on your own it takes Full access to get past the locks
    $correct = $own ? mitTsLevel() >= 3 : mitTsMayCorrect($sheet['user_id']);
    if ($sheet['status'] === 'approved') {
        return 'This period is approved and locked.' . (mitTsMayApprove($sheet['user_id']) ? ' Reopen it to change it.' : '');
    }
    if (!$own && !$correct) {
        return 'You can see this timesheet but not change it.';
    }
    if ($own && !$correct && $sheet['status'] === 'submitted') {
        return 'Submitted for approval - take it back to change it.';
    }
    if (!$correct && mitTsAutoLocked($sheet)) {
        return 'This period closed ' . mitTsSettings()['autolock_days'] . ' days after it ended - ask your manager to change it.';
    }
    return '';
}

/** Can hours go on this date with this code? '' or why not (future days, a setting). */
function mitTsFutureBlock($date, array $code) {
    $future = mitTsSettings()['future'];
    if ($date <= date('Y-m-d') || $future === 'all' || ($future === 'leave' && $code['code_kind'] === 'leave')) {
        return '';
    }
    return $future === 'leave' ? 'Only leave can go on days still to come.' : 'Hours go on once the day has come.';
}

/*
 * ---------- what is on a timesheet ----------
 */

/** Hours put against charge codes: [user_id][date][code_id] => ['minutes', 'note']. */
function mitTsEntries(array $user_ids, $from, $to) {
    global $mysqli;
    $user_ids = array_filter(array_map('intval', $user_ids));
    $entries = [];
    if ($user_ids && mitTsReady()) {
        $result = mysqli_query($mysqli, "SELECT user_id, entry_date, code_id, minutes, note FROM mit_time_entries
            WHERE source = 'manual' AND user_id IN (" . implode(',', $user_ids) . ") AND entry_date BETWEEN '" . escapeSql($from) . "' AND '" . escapeSql($to) . "'");
        while ($row = mysqli_fetch_assoc($result)) {
            $entries[intval($row['user_id'])][$row['entry_date']][intval($row['code_id'])] = ['minutes' => intval($row['minutes']), 'note' => $row['note']];
        }
    }
    return $entries;
}

/** Ticket time from the replies, as it is now: [user_id][date] => ['billable', 'other'] minutes. */
function mitTsTicketMinutesLive(array $user_ids, $from, $to) {
    global $mysqli;
    $user_ids = array_filter(array_map('intval', $user_ids));
    $minutes = [];
    if (!$user_ids) {
        return $minutes;
    }
    $settings = mitTsSettings();
    if (!$settings['ticket_time']) {
        return $minutes;
    }
    $billable = $settings['billable'] === 'client' ? 'ticket_client_id > 0' : 'ticket_billable = 1';
    $result = mysqli_query($mysqli, "SELECT ticket_reply_by AS u, DATE(ticket_reply_created_at) AS d,
            SUM(CASE WHEN $billable THEN TIME_TO_SEC(ticket_reply_time_worked) ELSE 0 END) AS b,
            SUM(CASE WHEN $billable THEN 0 ELSE TIME_TO_SEC(ticket_reply_time_worked) END) AS o
        FROM ticket_replies JOIN tickets ON ticket_id = ticket_reply_ticket_id
        WHERE ticket_reply_archived_at IS NULL AND ticket_reply_time_worked > '00:00:00'" . ($settings['ticket_internal'] ? '' : " AND ticket_reply_type <> 'Internal'") . "
        AND ticket_reply_by IN (" . implode(',', $user_ids) . ")
        AND ticket_reply_created_at >= '" . escapeSql($from) . " 00:00:00' AND ticket_reply_created_at < '" . escapeSql(date('Y-m-d', strtotime($to . ' +1 day'))) . " 00:00:00'
        GROUP BY ticket_reply_by, DATE(ticket_reply_created_at)");
    while ($row = mysqli_fetch_assoc($result)) {
        $minutes[intval($row['u'])][$row['d']] = ['billable' => mitTsRound($row['b'] / 60), 'other' => mitTsRound($row['o'] / 60)];
    }
    return $minutes;
}

/** Minutes rounded to the nearest 6, 15 or 30 when set (ticket time per day). */
function mitTsRound($minutes) {
    $step = mitTsSettings()['rounding'];
    return $step ? (int) (round($minutes / $step) * $step) : (int) round($minutes);
}

/**
 * Ticket time for the timesheets: what was copied in when the period was approved, and the
 * replies as they are now for periods not approved. [user_id][date] => ['billable', 'other'], and
 * on approved days also 'approved' => true and the stored 'auto_lost' (when it was stored).
 */
function mitTsTicketMinutes(array $user_ids, $from, $to) {
    global $mysqli;
    $user_ids = array_filter(array_map('intval', $user_ids));
    $minutes = mitTsTicketMinutesLive($user_ids, $from, $to);
    if (!$user_ids || !mitTsReady()) {
        return $minutes;
    }
    // Approved periods overlapping these dates: their stored ticket time replaces the live figures
    $result = mysqli_query($mysqli, "SELECT user_id, period_start, period_end FROM mit_timesheets WHERE status = 'approved'
        AND user_id IN (" . implode(',', $user_ids) . ") AND period_end >= '" . escapeSql($from) . "' AND period_start <= '" . escapeSql($to) . "'");
    while ($sheet = mysqli_fetch_assoc($result)) {
        $u = intval($sheet['user_id']);
        // Unaccounted time was stored on approval from this update on (at least a 0 row on the first day); a period
        // approved before that has none, and keeps having it worked out live
        $lost_stored = mysqli_num_rows(mysqli_query($mysqli, "SELECT 1 FROM mit_time_entries WHERE user_id = $u AND source = 'auto_lost'
            AND entry_date BETWEEN '" . escapeSql($sheet['period_start']) . "' AND '" . escapeSql($sheet['period_end']) . "' LIMIT 1")) > 0;
        foreach (mitTsDates(['start' => max($from, $sheet['period_start']), 'end' => min($to, $sheet['period_end'])]) as $date) {
            // Paid breaks: as stored on approval - none for periods approved before they existed
            $minutes[$u][$date] = ['billable' => 0, 'other' => 0, 'break_paid' => 0, 'approved' => true] + ($lost_stored ? ['auto_lost' => 0] : []);
        }
        $stored = mysqli_query($mysqli, "SELECT entry_date, source, minutes FROM mit_time_entries WHERE user_id = $u AND source IN ('ticket_billable', 'ticket_other', 'auto_lost', 'break_paid')
            AND entry_date BETWEEN '" . escapeSql(max($from, $sheet['period_start'])) . "' AND '" . escapeSql(min($to, $sheet['period_end'])) . "'");
        $keys = ['ticket_billable' => 'billable', 'ticket_other' => 'other', 'auto_lost' => 'auto_lost', 'break_paid' => 'break_paid'];
        while ($row = mysqli_fetch_assoc($stored)) {
            $minutes[$u][$row['entry_date']][$keys[$row['source']]] = intval($row['minutes']);
        }
    }
    return $minutes;
}

/**
 * Each person's days: [user_id][date] => ['billable', 'other', 'work', 'lost', 'auto_lost', 'leave_paid', 'leave_unpaid',
 * 'unpaid_work', 'codes' => [code_id => minutes]] in minutes. auto_lost (when the setting is on) is the part of a
 * finished day's expected hours that nothing accounts for - not before the person started, and as stored on an
 * approved period. It counts as lost time, and as unpaid unless the "paid" setting is on.
 */
function mitTsDays(array $user_ids, $from, $to) {
    $codes = mitTsCodes(true);
    $entries = mitTsEntries($user_ids, $from, $to);
    $tickets = mitTsTicketMinutes($user_ids, $from, $to);
    $auto = mitTsSettings()['auto_lost'];
    $auto_paid = mitTsSettings()['auto_lost_paid'];
    $today = date('Y-m-d');
    $blank = ['billable' => 0, 'other' => 0, 'work' => 0, 'lost' => 0, 'auto_lost' => 0, 'leave_paid' => 0, 'leave_unpaid' => 0, 'unpaid_work' => 0,
        'break' => 0, 'break_code_paid' => 0, 'break_paid' => 0, 'codes' => []];
    $days = [];
    foreach ($user_ids as $user_id) {
        $user_id = intval($user_id);
        $days[$user_id] = [];
        $started = mitTsStartDate($user_id);
        $break_rule = mitTsBreakRule($user_id);
        foreach (mitTsDates(['start' => $from, 'end' => $to]) as $date) {
            $d = $blank;
            $d['billable'] = $tickets[$user_id][$date]['billable'] ?? 0;
            $d['other'] = $tickets[$user_id][$date]['other'] ?? 0;
            foreach ($entries[$user_id][$date] ?? [] as $code_id => $entry) {
                $m = $entry['minutes'];
                $code = $codes[$code_id] ?? ['code_kind' => 'work', 'code_paid' => 1];
                $d['codes'][$code_id] = $m;
                if ($code['code_kind'] === 'leave') {
                    $d[intval($code['code_paid']) ? 'leave_paid' : 'leave_unpaid'] += $m;
                } elseif ($code['code_kind'] === 'break') {
                    $d['break'] += $m;
                    if (intval($code['code_paid'])) {
                        $d['break_code_paid'] += $m;
                    }
                } else {
                    $d[$code['code_kind'] === 'lost' ? 'lost' : 'work'] += $m;
                    if (!intval($code['code_paid'])) {
                        $d['unpaid_work'] += $m;
                    }
                }
            }
            // Paid breaks for the hours worked that day (as stored, on an approved period)
            if (isset($tickets[$user_id][$date]['break_paid'])) {
                $d['break_paid'] = $tickets[$user_id][$date]['break_paid'];
            } else {
                $d['break_paid'] = mitTsBreakFor($d['billable'] + $d['other'] + $d['work'] + $d['lost'] - $d['unpaid_work'], $break_rule);
            }
            if (isset($tickets[$user_id][$date]['auto_lost'])) {
                $d['auto_lost'] = $tickets[$user_id][$date]['auto_lost'];
            } elseif ($auto && $date < $today && $date >= $started) {
                // Lunch and breaks are accounted for - they are not lost time
                $accounted = $d['billable'] + $d['other'] + $d['work'] + $d['lost'] + $d['leave_paid'] + $d['leave_unpaid'] + $d['break'] + $d['break_paid'];
                $d['auto_lost'] = max(0, mitTsExpected($user_id, $date) - $accounted);
            }
            $d['lost'] += $d['auto_lost'];
            if (!$auto_paid) {
                $d['unpaid_work'] += $d['auto_lost'];
            }
            $days[$user_id][$date] = $d;
        }
    }
    return $days;
}

/** Hours worked that are paid, for a day from mitTsDays() - paid breaks count (short breaks are work time for overtime). */
function mitTsDayWorked(array $d) {
    return $d['billable'] + $d['other'] + $d['work'] + $d['lost'] - $d['unpaid_work'] + $d['break_paid'] + $d['break_code_paid'];
}

/**
 * Totals for people over dates: user_id => ['billable', 'other', 'codes' => [code_id => minutes], 'work', 'lost', 'auto_lost',
 * 'leave_paid', 'unpaid', 'worked', 'leave', 'break' (lunch etc. logged), 'break_paid' (paid breaks, automatic and on paid break
 * codes), 'paid', 'total', 'overtime', 'regular'] - all minutes. Leave is paid and unpaid. Worked (and the billable share) leaves
 * breaks out; paid hours and the total count paid breaks.
 * Overtime (hourly people, when set): over the daily limit on each day, plus, going through each week day by day,
 * the hours past the weekly limit - so a week that crosses into the next pay period splits its overtime by date.
 */
function mitTsTotals(array $user_ids, $from, $to) {
    $settings = mitTsSettings();
    $week_from = mitTsWeekStart($from);
    $all_days = mitTsDays($user_ids, $week_from, $to);
    $totals = [];
    foreach ($user_ids as $user_id) {
        $user_id = intval($user_id);
        $t = ['billable' => 0, 'other' => 0, 'codes' => [], 'work' => 0, 'lost' => 0, 'auto_lost' => 0, 'leave_paid' => 0, 'unpaid' => 0, 'overtime' => 0,
            'break' => 0, 'break_paid' => 0];
        $leave_unpaid = 0;
        $unpaid_work = 0;
        foreach ($all_days[$user_id] as $date => $d) {
            if ($date < $from) {
                continue;
            }
            foreach (['billable', 'other', 'work', 'lost', 'auto_lost', 'leave_paid'] as $key) {
                $t[$key] += $d[$key];
            }
            foreach ($d['codes'] as $code_id => $m) {
                $t['codes'][$code_id] = ($t['codes'][$code_id] ?? 0) + $m;
            }
            $leave_unpaid += $d['leave_unpaid'];
            $unpaid_work += $d['unpaid_work'];
            $t['break'] += $d['break'];
            $t['break_paid'] += $d['break_paid'] + $d['break_code_paid'];
        }
        $t['unpaid'] = $leave_unpaid + $unpaid_work;
        $t['worked'] = $t['billable'] + $t['other'] + $t['work'] + $t['lost'];
        $t['leave'] = $t['leave_paid'] + $leave_unpaid;
        $t['paid'] = $t['worked'] - $unpaid_work + $t['leave_paid'] + $t['break_paid'];
        $t['total'] = $t['worked'] + $t['leave'] + $t['break_paid'];

        if (($settings['ot_day'] || $settings['ot_week']) && mitTsPerson($user_id)['pay_type'] === 'hourly') {
            $week_regular = [];  // week start => regular minutes so far that week
            foreach ($all_days[$user_id] as $date => $d) {
                $worked = mitTsDayWorked($d);
                $over = $settings['ot_day'] ? max(0, $worked - $settings['ot_day']) : 0;
                $regular = $worked - $over;
                if ($settings['ot_week']) {
                    $week = mitTsWeekStart($date);
                    $so_far = $week_regular[$week] ?? 0;
                    $past_limit = max(0, $so_far + $regular - $settings['ot_week']) - max(0, $so_far - $settings['ot_week']);
                    $week_regular[$week] = $so_far + $regular;
                    $over += $past_limit;
                }
                if ($date >= $from) {
                    $t['overtime'] += $over;
                }
            }
        }
        $t['regular'] = $t['paid'] - $t['overtime'];
        $totals[$user_id] = $t;
    }
    return $totals;
}

/** "62%" of worked hours on billable tickets, or '-'. */
function mitTsBillableShare(array $t) {
    return $t['worked'] > 0 ? round($t['billable'] / $t['worked'] * 100) . '%' : '-';
}

/** A day's tickets with time on them: ticket rows with 'minutes' and 'ticket_billable'. */
function mitTsDayTickets($user_id, $date) {
    global $mysqli;
    $settings = mitTsSettings();
    $billable = $settings['billable'] === 'client' ? 'ticket_client_id > 0' : 'ticket_billable = 1';
    $result = mysqli_query($mysqli, "SELECT ticket_id, ticket_prefix, ticket_number, ticket_subject, IF($billable, 1, 0) AS ticket_billable, ticket_client_id, client_name,
            SUM(TIME_TO_SEC(ticket_reply_time_worked)) AS seconds
        FROM ticket_replies JOIN tickets ON ticket_id = ticket_reply_ticket_id LEFT JOIN clients ON client_id = ticket_client_id
        WHERE ticket_reply_archived_at IS NULL AND ticket_reply_time_worked > '00:00:00' AND ticket_reply_by = " . intval($user_id) . "
        " . ($settings['ticket_internal'] ? '' : "AND ticket_reply_type <> 'Internal'") . "
        AND ticket_reply_created_at >= '" . escapeSql($date) . " 00:00:00' AND ticket_reply_created_at < '" . escapeSql(date('Y-m-d', strtotime($date . ' +1 day'))) . " 00:00:00'
        GROUP BY ticket_id ORDER BY seconds DESC");
    $rows = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $row['minutes'] = (int) round($row['seconds'] / 60);
        $rows[] = $row;
    }
    return $rows;
}

/*
 * ---------- changing a timesheet ----------
 */

/** Who is doing it, for logs (session name is stored SQL-escaped). */
function mitTsWho() {
    global $session_name;
    return stripslashes((string) $session_name);
}

/**
 * Save the grid: $cells[date][code_id] = hours text, $notes[date][code_id] = a note. Returns [ok, message].
 * Checks: dates in the period, codes the person may use (or already has hours on), days up to 24 hours with the
 * ticket time, a code's daily limit, a note where the code needs one, and the days-to-come setting.
 */
function mitTsSaveCells(array $sheet, array $period, array $cells, array $notes = []) {
    global $mysqli, $session_user_id;
    $user_id = intval($sheet['user_id']);
    $codes = mitTsCodes(true);
    $allowed = mitTsCodesFor($user_id);
    $dates = array_flip(mitTsDates($period));
    $tickets = mitTsTicketMinutes([$user_id], $period['start'], $period['end'])[$user_id] ?? [];
    $current = mitTsEntries([$user_id], $period['start'], $period['end'])[$user_id] ?? [];
    $wanted = [];
    $problems = [];
    foreach ($cells as $date => $by_code) {
        if (!isset($dates[$date]) || !is_array($by_code)) {
            continue;
        }
        foreach ($by_code as $code_id => $text) {
            $code_id = intval($code_id);
            $had = $current[$date][$code_id] ?? null;
            if (!isset($codes[$code_id]) || (!isset($allowed[$code_id]) && !$had)) {
                continue;
            }
            $code = $codes[$code_id];
            $label = date('M j', strtotime($date)) . ' ' . $code['code_name'];
            $minutes = mitTsParseHours($text);
            $note = trim((string) ($notes[$date][$code_id] ?? ($had['note'] ?? '')));
            if ($minutes === null || $minutes > 1440) {
                $problems[] = "$label: use e.g. 7.5 or 7:30";
                continue;
            }
            $was = intval($had['minutes'] ?? 0);
            // An archived code (or one no longer given to their role) keeps what it had, but takes nothing new
            if (($code['code_archived_at'] !== null || !isset($allowed[$code_id])) && $minutes > $was) {
                continue;
            }
            if ($minutes > $was && ($why = mitTsFutureBlock($date, $code)) !== '') {
                $problems[] = "$label: $why";
                continue;
            }
            if ($minutes && intval($code['code_daily_max'] ?? 0) > 0 && $minutes > intval($code['code_daily_max'] ?? 0)) {
                $problems[] = "$label: at most " . mitTsHours($code['code_daily_max'], false) . ' hours a day';
                continue;
            }
            if ($minutes && intval($code['code_note_required'] ?? 0) && $note === '') {
                $problems[] = "$label: needs a note";
                continue;
            }
            $wanted[$date][$code_id] = [$minutes, mb_substr($note, 0, 255)];
        }
    }
    if ($problems) {
        return [false, 'Not saved - ' . implode('; ', array_slice($problems, 0, 4)) . (count($problems) > 4 ? ' (and ' . (count($problems) - 4) . ' more)' : '') . '.'];
    }
    $leave_wanted = [];
    foreach ($wanted as $date => $by_code) {
        foreach ($by_code as $code_id => [$minutes]) {
            $leave_wanted[$date][$code_id] = $minutes;
        }
    }
    if (($short = mitLeaveCheckSave($user_id, $leave_wanted, $current)) !== '') {
        return [false, "Not saved - $short."];
    }
    foreach ($wanted as $date => $by_code) {
        $day = array_sum(array_column($by_code, 0)) + ($tickets[$date]['billable'] ?? 0) + ($tickets[$date]['other'] ?? 0);
        foreach ($current[$date] ?? [] as $code_id => $entry) {
            if (!isset($by_code[$code_id])) {
                $day += $entry['minutes'];
            }
        }
        if ($day > 1440) {
            return [false, 'Not saved - ' . date('M j', strtotime($date)) . ' adds up to more than 24 hours.'];
        }
    }
    $changed = 0;
    $closure_code = mitTsClosureCode($user_id);
    foreach ($wanted as $date => $by_code) {
        foreach ($by_code as $code_id => [$minutes, $note]) {
            $had = $current[$date][$code_id] ?? null;
            if (intval($had['minutes'] ?? 0) === $minutes && (!$minutes || (string) ($had['note'] ?? '') === $note)) {
                continue;
            }
            $note_sql = $note === '' ? 'NULL' : "'" . escapeSql($note) . "'";
            // A closure day's filled-in hours that are cleared stay as a 0 row, so mitTsFillClosures leaves the day alone
            $keep_cleared = $minutes === 0 && $had && $closure_code && intval($closure_code['code_id']) === $code_id && mitTsClosure($date) !== '';
            if ($keep_cleared) {
                mysqli_query($mysqli, "UPDATE mit_time_entries SET minutes = 0, note = $note_sql, updated_by = " . intval($session_user_id) . "
                    WHERE user_id = $user_id AND entry_date = '" . escapeSql($date) . "' AND code_id = $code_id AND source = 'manual'");
            } elseif ($minutes === 0) {
                mysqli_query($mysqli, "DELETE FROM mit_time_entries WHERE user_id = $user_id AND entry_date = '" . escapeSql($date) . "' AND code_id = $code_id AND source = 'manual'");
            } else {
                mysqli_query($mysqli, "INSERT INTO mit_time_entries SET user_id = $user_id, entry_date = '" . escapeSql($date) . "', code_id = $code_id, source = 'manual',
                    minutes = $minutes, note = $note_sql, updated_by = " . intval($session_user_id) . "
                    ON DUPLICATE KEY UPDATE minutes = $minutes, note = $note_sql, updated_by = " . intval($session_user_id));
            }
            $changed++;
        }
    }
    $changed += mitTsFillClosures($user_id, $period);
    if ($changed) {
        mitTsSheetSaved($user_id, $period);
        if ($user_id !== intval($session_user_id)) {
            logAudit('Timesheet', 'Edit', escapeSql(mitTsWho() . " changed $changed entr" . ($changed === 1 ? 'y' : 'ies') . " on the timesheet of user $user_id for " . $period['label']), 0, $user_id);
        }
    }
    return [true, $changed ? 'Timesheet saved' : 'Nothing changed'];
}

/** The code closure days are filled in with (the first one set to do it that the person may use), or null. */
function mitTsClosureCode($user_id) {
    foreach (mitTsCodesFor($user_id) as $code_id => $code) {
        if (intval($code['code_closure_fill'] ?? 0)) {
            return $code;
        }
    }
    return null;
}

/**
 * Closure days (Admin > SLA) up to today with nothing on that code: filled in with the person's normal hours for that
 * weekday. A day whose filled-in hours were cleared keeps a 0 row and is not filled again. Runs when the timesheet is
 * saved, submitted or approved, and from the cron job for the period just ended. Returns how many days were filled.
 */
function mitTsFillClosures($user_id, array $period) {
    global $mysqli, $session_user_id;
    $code = mitTsClosureCode($user_id);
    if (!$code) {
        return 0;
    }
    $user_id = intval($user_id);
    $code_id = intval($code['code_id']);
    $schedule = mitTsSchedule($user_id);
    $current = mitTsEntries([$user_id], $period['start'], $period['end'])[$user_id] ?? [];
    $filled = 0;
    $started = mitTsStartDate($user_id);
    foreach (mitTsDates($period) as $date) {
        $minutes = $schedule[intval(date('N', strtotime($date)))] ?? 0;
        if ($date > date('Y-m-d') || $date < $started || mitTsClosure($date) === '' || !$minutes || isset($current[$date][$code_id])) {
            continue;
        }
        mysqli_query($mysqli, "INSERT IGNORE INTO mit_time_entries SET user_id = $user_id, entry_date = '$date', code_id = $code_id, source = 'manual',
            minutes = $minutes, note = '" . escapeSql(mb_substr(mitTsClosure($date), 0, 255)) . "', updated_by = " . intval($session_user_id ?? 0));
        $filled += mysqli_affected_rows($mysqli) > 0 ? 1 : 0;
    }
    return $filled;
}

/** For a handler: the stored timesheet by id, if the agent may see it, with its period - or back with an error. */
function mitTsSheetForRequest($sheet_id) {
    global $mysqli;
    $row = mitTsReady() ? mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT sheet_id, user_id, period_start, period_end FROM mit_timesheets WHERE sheet_id = " . intval($sheet_id))) : null;
    if (!$row || !mitTsMaySee($row['user_id'])) {
        flashAlert('That timesheet does not exist, or it is not yours.', 'error');
        redirect();
    }
    // Its own dates - the pay period setting may have changed since it was kept
    $period = ['start' => $row['period_start'], 'end' => $row['period_end'], 'label' => mitTsRangeText($row['period_start'], $row['period_end'])];
    return [mitTsSheet($row['user_id'], $period), $period];
}

/** The people who approve timesheets (Full Timesheets access, or admins). user_id => row */
function mitTsApprovers() {
    global $mysqli;
    $result = mysqli_query($mysqli, "SELECT users.user_id, user_name, user_email FROM users
        JOIN user_roles ON role_id = users.user_role_id
        LEFT JOIN user_role_permissions urp ON urp.user_role_id = role_id
            AND urp.module_id = (SELECT module_id FROM modules WHERE module_name = 'module_timesheets' LIMIT 1)
        WHERE user_type = 1 AND user_status = 1 AND user_archived_at IS NULL
        AND (role_is_admin = 1 OR urp.user_role_permission_level >= 3)");
    $approvers = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $approvers[intval($row['user_id'])] = $row;
    }
    return $approvers;
}

/** Who approves this person: their own approver (People tab) if set and active, else everyone with Full access. */
function mitTsApproversFor($user_id) {
    $approver_id = mitTsPerson($user_id)['approver_id'];
    if ($approver_id && $approver_id !== intval($user_id)) {
        $approver = mitTsUsers()[$approver_id] ?? null;
        if ($approver) {
            return [$approver_id => $approver];
        }
    }
    return mitTsApprovers();
}

/** An app notification for someone, and an email when they want reminders by email ($email true). */
function mitTsNotify($user_id, $text, $link, $entity_id, $email = false) {
    global $mysqli, $config_base_url, $config_smtp_provider, $config_mail_from_email, $config_mail_from_name;
    mysqli_query($mysqli, "INSERT INTO notifications SET notification_type = 'Timesheet', notification = '" . escapeSql($text) . "',
        notification_action = '" . escapeSql($link) . "', notification_user_id = " . intval($user_id) . ", notification_entity_id = " . intval($entity_id));
    $user = $email ? (mitTsUsers([$user_id])[intval($user_id)] ?? null) : null;
    if ($user && !empty($config_smtp_provider) && filter_var($user['user_email'], FILTER_VALIDATE_EMAIL)) {
        addToMailQueue([['from' => escapeSql($config_mail_from_email), 'from_name' => escapeSql($config_mail_from_name),
            'recipient' => escapeSql($user['user_email']), 'recipient_name' => escapeSql($user['user_name']), 'subject' => escapeSql($text),
            // mitMailBody, not escapeSql: escapeSql strips tags and would take the link out
            'body' => mitMailBody('Hello ' . escapeHtml($user['user_name']) . ',<br><br>' . escapeHtml($text) . '.<br><br><a href="https://' . escapeHtml($config_base_url . $link) . '">Open your timesheet</a>')]]);
    }
}

/** The timesheet page's link. */
function mitTsLink($user_id, array $period) {
    return '/agent/mit_timesheet.php?user_id=' . intval($user_id) . '&start=' . $period['start'];
}

/** Submit a period for approval. Returns [ok, message]. */
function mitTsSubmit(array $sheet, array $period) {
    global $mysqli, $session_user_id;
    if (!in_array($sheet['status'], ['open', 'returned'], true)) {
        return [false, 'This timesheet is already ' . strtolower(MIT_TS_STATUSES[$sheet['status']][0]) . '.'];
    }
    if (!mitTsSettings()['approval']) {
        return [false, 'Timesheets are not approved here (Admin > Timesheets) - saving is enough.'];
    }
    mitTsFillClosures($sheet['user_id'], $period);
    $sheet = mitTsSheetSaved($sheet['user_id'], $period);
    mysqli_query($mysqli, "UPDATE mit_timesheets SET status = 'submitted', submitted_at = NOW(), decision_note = NULL WHERE sheet_id = " . intval($sheet['sheet_id']));
    $user = mitTsUsers([$sheet['user_id']])[intval($sheet['user_id'])] ?? ['user_name' => 'Someone'];
    foreach (mitTsApproversFor($sheet['user_id']) as $approver_id => $approver) {
        if ($approver_id !== intval($session_user_id)) {
            mitTsNotify($approver_id, $user['user_name'] . "'s timesheet for " . $period['label'] . ' is waiting for approval', mitTsLink($sheet['user_id'], $period), $sheet['sheet_id']);
        }
    }
    logAudit('Timesheet', 'Edit', escapeSql(mitTsWho() . ' submitted the timesheet of ' . $user['user_name'] . ' for ' . $period['label']), 0, intval($sheet['user_id']));
    return [true, 'Submitted for approval'];
}

/** Take a submitted period back (its owner, before it is decided). */
function mitTsWithdraw(array $sheet) {
    global $mysqli;
    if ($sheet['status'] !== 'submitted') {
        return [false, 'Only a submitted timesheet can be taken back.'];
    }
    mysqli_query($mysqli, "UPDATE mit_timesheets SET status = 'open', submitted_at = NULL WHERE sheet_id = " . intval($sheet['sheet_id']));
    $period = mitTsPeriodFor($sheet['period_start']);
    logAudit('Timesheet', 'Edit', escapeSql(mitTsWho() . ' took back the timesheet for ' . $period['label']), 0, intval($sheet['user_id']));
    return [true, 'Taken back - you can change it and submit it again'];
}

/**
 * Copy the period's ticket time, unaccounted time and paid breaks in as entries (on approval, before the
 * status changes - so they are still worked out live), replacing any copied before.
 */
function mitTsSnapshotTickets($user_id, array $period) {
    global $mysqli, $session_user_id;
    $user_id = intval($user_id);
    $days = mitTsDays([$user_id], $period['start'], $period['end'])[$user_id] ?? [];
    mysqli_query($mysqli, "DELETE FROM mit_time_entries WHERE user_id = $user_id AND source IN ('ticket_billable', 'ticket_other', 'auto_lost', 'break_paid')
        AND entry_date BETWEEN '" . escapeSql($period['start']) . "' AND '" . escapeSql($period['end']) . "'");
    foreach ($days as $date => $d) {
        if ($d['break_paid'] > 0) {
            mysqli_query($mysqli, "INSERT INTO mit_time_entries SET user_id = $user_id, entry_date = '" . escapeSql($date) . "', code_id = 0, source = 'break_paid',
                minutes = " . intval($d['break_paid']) . ", updated_by = " . intval($session_user_id));
        }
        // Every day with some, and the first day always (a 0 there marks the period as stored - see mitTsTicketMinutes)
        if ($d['auto_lost'] > 0 || $date === $period['start']) {
            mysqli_query($mysqli, "INSERT INTO mit_time_entries SET user_id = $user_id, entry_date = '" . escapeSql($date) . "', code_id = 0, source = 'auto_lost',
                minutes = " . intval($d['auto_lost']) . ", updated_by = " . intval($session_user_id));
        }
    }
    foreach (mitTsTicketMinutesLive([$user_id], $period['start'], $period['end'])[$user_id] ?? [] as $date => $values) {
        foreach (['ticket_billable' => $values['billable'], 'ticket_other' => $values['other']] as $source => $minutes) {
            if ($minutes > 0) {
                mysqli_query($mysqli, "INSERT INTO mit_time_entries SET user_id = $user_id, entry_date = '" . escapeSql($date) . "', code_id = 0, source = '$source',
                    minutes = $minutes, updated_by = " . intval($session_user_id));
            }
        }
    }
}

/** Approve or send back a timesheet (Full access, or the person's approver). $decision 'approve' | 'return'. Returns [ok, message]. */
function mitTsDecide(array $sheet, array $period, $decision, $note = '') {
    global $mysqli, $session_user_id;
    $note = trim((string) $note);
    if ($decision === 'approve') {
        if (!in_array($sheet['status'], ['submitted', 'open', 'returned'], true)) {
            return [false, 'This timesheet is already approved.'];
        }
        mitTsFillClosures($sheet['user_id'], $period);
        $sheet = mitTsSheetSaved($sheet['user_id'], $period);
        mitTsSnapshotTickets($sheet['user_id'], $period);
        mitLeaveAccruePeriod($sheet['user_id'], $period);
        mysqli_query($mysqli, "UPDATE mit_timesheets SET status = 'approved', decided_by = " . intval($session_user_id) . ", decided_at = NOW(),
            decision_note = " . ($note === '' ? 'NULL' : "'" . escapeSql(mb_substr($note, 0, 255)) . "'") . " WHERE sheet_id = " . intval($sheet['sheet_id']));
        $text = 'approved';
    } else {
        if ($sheet['status'] !== 'submitted' || $note === '') {
            return [false, $note === '' ? 'Say why it is being sent back.' : 'Only a submitted timesheet can be sent back.'];
        }
        mysqli_query($mysqli, "UPDATE mit_timesheets SET status = 'returned', decided_by = " . intval($session_user_id) . ", decided_at = NOW(),
            decision_note = '" . escapeSql(mb_substr($note, 0, 255)) . "' WHERE sheet_id = " . intval($sheet['sheet_id']));
        $text = 'sent back';
    }
    if (intval($sheet['user_id']) !== intval($session_user_id)) {
        mitTsNotify($sheet['user_id'], 'Your timesheet for ' . $period['label'] . " was $text by " . mitTsWho() . ($note !== '' ? " - $note" : ''), mitTsLink($sheet['user_id'], $period), $sheet['sheet_id']);
    }
    $user = mitTsUsers([$sheet['user_id']])[intval($sheet['user_id'])] ?? ['user_name' => 'user ' . intval($sheet['user_id'])];
    logAudit('Timesheet', 'Edit', escapeSql(mitTsWho() . " $text the timesheet of " . $user['user_name'] . ' for ' . $period['label']), 0, intval($sheet['user_id']));
    return [true, 'Timesheet ' . $text];
}

/** Reopen an approved period (Full access, or the person's approver) - ticket and unaccounted time are live again until it is approved again. */
function mitTsReopen(array $sheet, array $period) {
    global $mysqli;
    if ($sheet['status'] !== 'approved') {
        return [false, 'Only an approved timesheet can be reopened.'];
    }
    mysqli_query($mysqli, "DELETE FROM mit_time_entries WHERE user_id = " . intval($sheet['user_id']) . " AND source IN ('ticket_billable', 'ticket_other', 'auto_lost', 'break_paid')
        AND entry_date BETWEEN '" . escapeSql($period['start']) . "' AND '" . escapeSql($period['end']) . "'");
    mysqli_query($mysqli, "UPDATE mit_timesheets SET status = 'open', submitted_at = NULL, decided_by = 0, decided_at = NULL, decision_note = NULL WHERE sheet_id = " . intval($sheet['sheet_id']));
    mitLeaveUnaccruePeriod($sheet['user_id'], $period);
    $user = mitTsUsers([$sheet['user_id']])[intval($sheet['user_id'])] ?? ['user_name' => 'user ' . intval($sheet['user_id'])];
    logAudit('Timesheet', 'Edit', escapeSql(mitTsWho() . ' reopened the approved timesheet of ' . $user['user_name'] . ' for ' . $period['label']), 0, intval($sheet['user_id']));
    return [true, 'Reopened - approve it again when it is right'];
}

/** Submitted timesheets the signed-in agent can approve (side menu badge). */
function mitTsWaitingCount() {
    global $mysqli, $session_user_id;
    if (!mitTsOn() || !mitTsSettings()['approval']) {
        return 0;
    }
    // Full access approves anyone's; otherwise only the people this agent approves (People tab)
    if (mitTsLevel() >= 3) {
        $who = '';
    } else {
        $reports = mitTsMyReports();
        if (!$reports) {
            return 0;
        }
        $who = ' AND user_id IN (' . implode(',', array_map('intval', $reports)) . ')';
    }
    return intval(mysqli_fetch_row(mysqli_query($mysqli, "SELECT COUNT(*) FROM mit_timesheets WHERE status = 'submitted' AND user_id <> " . intval($session_user_id) . $who))[0]);
}

/*
 * ---------- personal choices (Account > Preferences) ----------
 */

/** Charge codes this person has hidden from their timesheet (still shown on a period that has hours on them). */
function mitTsHiddenCodes($user_id) {
    return array_filter(array_map('intval', explode(',', (string) mitUserPreference($user_id, 'timesheet.hidden_codes', ''))));
}

/** Does the person want timesheet reminders? (on unless they turned them off) */
function mitTsRemindersOn($user_id) {
    return mitUserPreference($user_id, 'timesheet.reminders', '1') !== '0';
}

/** The Timesheet part of Account > Preferences (agent/user/user_preferences.php). */
function mitTsPreferenceFields($user_id) {
    if (!mitTsOn()) {
        return '';
    }
    $user_id = intval($user_id);
    $format = (string) mitUserPreference($user_id, 'timesheet.format', '');
    $view = (string) mitUserPreference($user_id, 'timesheet.view', 'period');
    $hidden = mitTsHiddenCodes($user_id);
    $settings = mitTsSettings();
    $html = '<div class="col-12"><hr><h6 class="mb-3"><i class="fas fa-fw fa-business-time me-2"></i>Timesheet</h6><div class="row g-3">'
        . '<div class="col-md-4"><label class="form-label">Hours shown as</label><select class="form-select" name="mit_ts_format">'
        . '<option value=""' . ($format === '' ? ' selected' : '') . '>Company default (' . ($settings['format'] === 'hm' ? '7:30' : '7.5') . ')</option>'
        . '<option value="decimal"' . ($format === 'decimal' ? ' selected' : '') . '>Decimal - 7.5</option>'
        . '<option value="hm"' . ($format === 'hm' ? ' selected' : '') . '>Hours and minutes - 7:30</option></select></div>'
        . '<div class="col-md-4"><label class="form-label">Open on</label><select class="form-select" name="mit_ts_view">'
        . '<option value="period"' . ($view !== 'week' ? ' selected' : '') . '>The whole pay period</option>'
        . '<option value="week"' . ($view === 'week' ? ' selected' : '') . '>This week (others folded)</option></select></div>'
        . '<div class="col-md-4 d-flex align-items-end"><div class="form-check mb-2"><input class="form-check-input" type="checkbox" name="mit_ts_reminders" value="1" id="mitTsReminders"'
        . (mitTsRemindersOn($user_id) ? ' checked' : '') . '><label class="form-check-label" for="mitTsReminders">Remind me to fill it in</label></div></div>'
        . '<div class="col-12"><label class="form-label">Charge codes I use</label><div class="d-flex flex-wrap gap-3">';
    foreach (mitTsCodesFor($user_id) as $code_id => $code) {
        $html .= '<div class="form-check"><input class="form-check-input" type="checkbox" name="mit_ts_codes[]" value="' . intval($code_id) . '" id="mitTsCode' . intval($code_id) . '"'
            . (in_array(intval($code_id), $hidden, true) ? '' : ' checked') . '><label class="form-check-label" for="mitTsCode' . intval($code_id) . '">' . mitTsCodeDot($code) . escapeHtml($code['code_name']) . '</label></div>';
    }
    return $html . '<input type="hidden" name="mit_ts_prefs" value="1"></div><div class="form-text">Unticked ones are left off your timesheet.</div></div></div></div>';
}

/** Save those (a no-op when the form did not have them). */
function mitTsSavePreferences($user_id, array $post) {
    if (empty($post['mit_ts_prefs']) || !mitTsOn()) {
        return;
    }
    $format = (string) ($post['mit_ts_format'] ?? '');
    mitSetUserPreference($user_id, 'timesheet.format', in_array($format, ['decimal', 'hm'], true) ? $format : '');
    mitSetUserPreference($user_id, 'timesheet.view', ($post['mit_ts_view'] ?? '') === 'week' ? 'week' : 'period');
    mitSetUserPreference($user_id, 'timesheet.reminders', empty($post['mit_ts_reminders']) ? '0' : '1');
    $shown = array_map('intval', (array) ($post['mit_ts_codes'] ?? []));
    $hidden = array_diff(array_keys(mitTsCodesFor($user_id)), $shown);
    mitSetUserPreference($user_id, 'timesheet.hidden_codes', implode(',', $hidden));
}

/*
 * ---------- the cron job (cron/mit_timesheets.php) ----------
 */

/** Fill closure days on everyone's period that just ended (not yet approved). Returns lines for the log. */
function mitTsFillEndedClosures() {
    $log = [];
    $last = mitTsPeriodShift(mitTsPeriodFor(), -1);
    foreach (mitTsUsers() as $user_id => $user) {
        if (mitTsSheet($user_id, $last)['status'] === 'approved') {
            continue;
        }
        if (($filled = mitTsFillClosures($user_id, $last)) > 0) {
            mitTsSheetSaved($user_id, $last);
            $log[] = "{$user['user_name']}: $filled closure day(s) filled in for {$last['label']}";
        }
    }
    return $log;
}

/** Send the reminders that are due: on a period's last day, and when the last one is late. Returns lines for the log. */
function mitTsSendReminders() {
    global $mysqli;
    $settings = mitTsSettings();
    $log = [];
    $today = date('Y-m-d');
    $current = mitTsPeriodFor();
    $last = mitTsPeriodShift($current, -1);
    foreach (mitTsUsers() as $user_id => $user) {
        if (!mitTsRemindersOn($user_id)) {
            continue;
        }
        if ($settings['remind_due'] && $current['end'] === $today) {
            $sheet = mitTsSheet($user_id, $current);
            if (in_array($sheet['status'], ['open', 'returned'], true) && empty($sheet['reminded_due_at'])) {
                $sheet = mitTsSheetSaved($user_id, $current);
                mysqli_query($mysqli, "UPDATE mit_timesheets SET reminded_due_at = NOW() WHERE sheet_id = " . intval($sheet['sheet_id']));
                mitTsNotify($user_id, 'Your timesheet for ' . $current['label'] . ' ends today - check your hours' . ($settings['approval'] ? ' and submit it' : ''),
                    mitTsLink($user_id, $current), $sheet['sheet_id'], true);
                $log[] = "Reminded {$user['user_name']}: period ends today";
            }
        }
        $late = $settings['remind_late_days'];
        if ($settings['approval'] && $late && $today >= date('Y-m-d', strtotime($last['end'] . " +$late days"))) {
            $sheet = mitTsSheet($user_id, $last);
            if (in_array($sheet['status'], ['open', 'returned'], true) && empty($sheet['reminded_late_at'])) {
                $sheet = mitTsSheetSaved($user_id, $last);
                mysqli_query($mysqli, "UPDATE mit_timesheets SET reminded_late_at = NOW() WHERE sheet_id = " . intval($sheet['sheet_id']));
                mitTsNotify($user_id, 'Your timesheet for ' . $last['label'] . ' has not been submitted yet', mitTsLink($user_id, $last), $sheet['sheet_id'], true);
                $log[] = "Reminded {$user['user_name']}: last period not submitted";
            }
        }
    }
    return $log;
}

require_once __DIR__ . '/mit_leave.php';
