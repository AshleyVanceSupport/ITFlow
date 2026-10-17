<?php

/*
 * Mountaineer IT - timesheets (database update M012).
 *
 * Every agent keeps a timesheet per pay period (semi-monthly by default - 1st to 15th, 16th to the
 * end; Admin > Charge codes can change it). A day's hours are:
 *   - ticket time, filled in on its own from the time logged on ticket replies that day - billable
 *     when the ticket is billable, the rest "other ticket time";
 *   - hours put against charge codes (mit_charge_codes): work (Admin, Training ...), lost time, and
 *     leave (PTO, Holiday, Sick ...), each paid or unpaid.
 * The person submits the period, someone with Full Timesheets access approves it (or sends it
 * back), and an approved period is locked. Approving copies the period's ticket time in as entries
 * (source ticket_billable / ticket_other, code 0), so payroll does not move if a reply changes later.
 *
 * Permission module_timesheets: anyone can keep their own; View 1 sees everyone's and the payroll
 * report (agent/reports/mit_payroll.php), Edit 2 corrects them, Full 3 approves. Admins are Full.
 *
 * Pages: agent/mit_timesheet.php (one person's period), agent/mit_timesheets.php (everyone, one
 * period), agent/modals/mit_timesheet/day.php (a day's tickets), handlers agent/post/mit_timesheets.php,
 * side menu agent/includes/mit_timesheet_nav.php, Admin > Charge codes (admin/mit_charge_codes.php).
 */

const MIT_TS_KINDS = ['work' => 'Work', 'lost' => 'Lost time', 'leave' => 'Leave'];
const MIT_TS_PERIODS = ['semi_monthly' => 'Semi-monthly (1st-15th, 16th-end)', 'monthly' => 'Monthly', 'biweekly' => 'Every two weeks', 'weekly' => 'Weekly'];
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

/** The signed-in agent's Timesheets level (0 = own timesheet only). */
function mitTsLevel() {
    return mitTsReady() ? intval(lookupUserPermission('module_timesheets')) : 0;
}

/** The report's entry in ITFlow's report catalogue (functions/reports.php). */
function mitTsReportEntry() {
    return [
        'title' => 'Timesheets & Payroll', 'icon' => 'fa-business-time', 'module' => 'module_timesheets', 'feature' => [],
        'description' => 'Hours for each person by charge code - billable, other ticket time, admin, lost time and leave - added up for payroll.',
    ];
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
        case 'biweekly':
            $length = $type === 'weekly' ? 7 : 14;
            $anchor_time = strtotime($type === 'weekly' ? 'monday this week' : $anchor, strtotime($anchor));
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

/** The period's dates in weeks (Monday to Sunday), for the grid. */
function mitTsWeeks(array $period) {
    $weeks = [];
    foreach (mitTsDates($period) as $date) {
        $weeks[date('o-W', strtotime($date))][] = $date;
    }
    return array_values($weeks);
}

/** Is it a working day - one of the business days set in Admin > SLA, and not a closure day? */
function mitTsWorkingDay($date) {
    static $days = null, $holidays = null;
    if ($days === null) {
        $settings = getSlaSettings();
        $days = $settings['business_days'] ?: [1, 2, 3, 4, 5];
        $holidays = getBusinessHolidays();
    }
    return in_array(intval(date('N', strtotime($date))), $days, true) && !isset($holidays[$date]);
}

/** A closure day's name (Admin > SLA), or ''. */
function mitTsClosure($date) {
    return (string) (getBusinessHolidays()[$date] ?? '');
}

/*
 * ---------- hours ----------
 */

/** Minutes as hours for people: 450 -> "7.5", 0 -> "". */
function mitTsHours($minutes, $blank_zero = true) {
    $minutes = intval($minutes);
    if ($minutes === 0 && $blank_zero) {
        return '';
    }
    return rtrim(rtrim(number_format($minutes / 60, 2, '.', ''), '0'), '.');
}

/** "7.5", "7:30", "450m", "7h30" -> minutes; '' -> 0; null when it cannot be read. */
function mitTsParseHours($text) {
    $text = strtolower(trim(str_replace(',', '.', (string) $text)));
    if ($text === '') {
        return 0;
    }
    if (preg_match('/^(\d{1,2}):(\d{1,2})$/', $text, $m)) {
        return intval($m[1]) * 60 + intval($m[2]);
    }
    if (preg_match('/^(\d{1,2})h\s*(\d{1,2})?m?$/', $text, $m)) {
        return intval($m[1]) * 60 + intval($m[2] ?? 0);
    }
    if (preg_match('/^(\d{1,4})\s*m(in)?$/', $text, $m)) {
        return intval($m[1]);
    }
    if (preg_match('/^\d{0,2}(\.\d{1,2})?$/', $text) && $text !== '.') {
        return (int) round((float) $text * 60);
    }
    return null;
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
            $result = mysqli_query($mysqli, "SELECT * FROM mit_charge_codes" . ($with_archived ? '' : ' WHERE code_archived_at IS NULL') . " ORDER BY code_sort, code_name");
            while ($row = mysqli_fetch_assoc($result)) {
                $cache[$key][intval($row['code_id'])] = $row;
            }
        }
    }
    return $cache[$key];
}

/** A small colour dot for a code. */
function mitTsCodeDot(array $code) {
    $color = preg_match('/^#[0-9a-f]{3,8}$/i', (string) $code['code_color']) ? $code['code_color'] : '#6c757d';
    return '<span class="d-inline-block rounded-circle me-2" style="width: 10px; height: 10px; background: ' . $color . '"></span>';
}

/*
 * ---------- people and timesheets ----------
 */

/** Agents who keep timesheets: active ones, plus anyone named in $also. user_id => row */
function mitTsUsers(array $also = []) {
    global $mysqli;
    $also = array_filter(array_map('intval', $also));
    $result = mysqli_query($mysqli, "SELECT user_id, user_name, user_email, user_avatar FROM users WHERE user_type = 1
        AND ((user_status = 1 AND user_archived_at IS NULL)" . ($also ? ' OR user_id IN (' . implode(',', $also) . ')' : '') . ") ORDER BY user_name");
    $users = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $users[intval($row['user_id'])] = $row;
    }
    return $users;
}

/** The person's timesheet for a period - a stored row, or an open one not saved yet (sheet_id 0). */
function mitTsSheet($user_id, array $period) {
    global $mysqli;
    $row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT s.*, u.user_name AS decided_by_name FROM mit_timesheets s LEFT JOIN users u ON u.user_id = s.decided_by
        WHERE s.user_id = " . intval($user_id) . " AND s.period_start = '" . escapeSql($period['start']) . "'"));
    return $row ?: ['sheet_id' => 0, 'user_id' => intval($user_id), 'period_start' => $period['start'], 'period_end' => $period['end'], 'status' => 'open',
        'submitted_at' => null, 'decided_by' => 0, 'decided_by_name' => null, 'decided_at' => null, 'decision_note' => null];
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
        $result = mysqli_query($mysqli, "SELECT * FROM mit_timesheets WHERE period_start = '" . escapeSql($period['start']) . "' AND user_id IN (" . implode(',', $user_ids) . ")");
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

/** May the signed-in agent see this person's timesheets? */
function mitTsMaySee($user_id) {
    global $session_user_id;
    return intval($user_id) === intval($session_user_id) || mitTsLevel() >= 1;
}

/** May the signed-in agent change this timesheet's hours? Returns '' or why not. */
function mitTsWhyLocked(array $sheet) {
    global $session_user_id;
    $own = intval($sheet['user_id']) === intval($session_user_id);
    if ($sheet['status'] === 'approved') {
        return 'This period is approved and locked.' . (mitTsLevel() >= 3 ? ' Reopen it to change it.' : '');
    }
    if ($own && $sheet['status'] === 'submitted' && mitTsLevel() < 2) {
        return 'Submitted for approval - take it back to change it.';
    }
    if (!$own && mitTsLevel() < 2) {
        return 'You can see this timesheet but not change it.';
    }
    return '';
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
    $result = mysqli_query($mysqli, "SELECT ticket_reply_by AS u, DATE(ticket_reply_created_at) AS d,
            SUM(CASE WHEN ticket_billable = 1 THEN TIME_TO_SEC(ticket_reply_time_worked) ELSE 0 END) AS b,
            SUM(CASE WHEN ticket_billable = 1 THEN 0 ELSE TIME_TO_SEC(ticket_reply_time_worked) END) AS o
        FROM ticket_replies JOIN tickets ON ticket_id = ticket_reply_ticket_id
        WHERE ticket_reply_archived_at IS NULL AND ticket_reply_time_worked > '00:00:00'
        AND ticket_reply_by IN (" . implode(',', $user_ids) . ")
        AND ticket_reply_created_at >= '" . escapeSql($from) . " 00:00:00' AND ticket_reply_created_at < '" . escapeSql(date('Y-m-d', strtotime($to . ' +1 day'))) . " 00:00:00'
        GROUP BY ticket_reply_by, DATE(ticket_reply_created_at)");
    while ($row = mysqli_fetch_assoc($result)) {
        $minutes[intval($row['u'])][$row['d']] = ['billable' => (int) round($row['b'] / 60), 'other' => (int) round($row['o'] / 60)];
    }
    return $minutes;
}

/**
 * Ticket time for the timesheets: what was copied in when the period was approved, and the
 * replies as they are now for periods not approved. [user_id][date] => ['billable', 'other'].
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
        foreach ($minutes[$u] ?? [] as $date => $values) {
            if ($date >= $sheet['period_start'] && $date <= $sheet['period_end']) {
                unset($minutes[$u][$date]);
            }
        }
        $stored = mysqli_query($mysqli, "SELECT entry_date, source, minutes FROM mit_time_entries WHERE user_id = $u AND source IN ('ticket_billable', 'ticket_other')
            AND entry_date BETWEEN '" . escapeSql(max($from, $sheet['period_start'])) . "' AND '" . escapeSql(min($to, $sheet['period_end'])) . "'");
        while ($row = mysqli_fetch_assoc($stored)) {
            $minutes[$u][$row['entry_date']] = ($minutes[$u][$row['entry_date']] ?? ['billable' => 0, 'other' => 0]);
            $minutes[$u][$row['entry_date']][$row['source'] === 'ticket_billable' ? 'billable' : 'other'] = intval($row['minutes']);
        }
    }
    return $minutes;
}

/**
 * Totals for people over dates: user_id => ['billable', 'other', 'codes' => [code_id => minutes], 'work', 'lost',
 * 'leave_paid', 'unpaid', 'worked', 'leave', 'paid', 'total'] - all minutes. Leave is paid and unpaid.
 */
function mitTsTotals(array $user_ids, $from, $to) {
    $codes = mitTsCodes(true);
    $entries = mitTsEntries($user_ids, $from, $to);
    $tickets = mitTsTicketMinutes($user_ids, $from, $to);
    $totals = [];
    foreach ($user_ids as $user_id) {
        $user_id = intval($user_id);
        $t = ['billable' => 0, 'other' => 0, 'codes' => [], 'work' => 0, 'lost' => 0, 'leave_paid' => 0, 'unpaid' => 0];
        foreach ($tickets[$user_id] ?? [] as $values) {
            $t['billable'] += $values['billable'];
            $t['other'] += $values['other'];
        }
        foreach ($entries[$user_id] ?? [] as $by_code) {
            foreach ($by_code as $code_id => $entry) {
                $t['codes'][$code_id] = ($t['codes'][$code_id] ?? 0) + $entry['minutes'];
                $code = $codes[$code_id] ?? ['code_kind' => 'work', 'code_paid' => 1];
                if (!intval($code['code_paid'])) {
                    $t['unpaid'] += $entry['minutes'];
                } elseif ($code['code_kind'] === 'leave') {
                    $t['leave_paid'] += $entry['minutes'];
                }
                if ($code['code_kind'] === 'lost') {
                    $t['lost'] += $entry['minutes'];
                } elseif ($code['code_kind'] === 'work') {
                    $t['work'] += $entry['minutes'];
                }
            }
        }
        $t['worked'] = $t['billable'] + $t['other'] + $t['work'] + $t['lost'];
        $unpaid_work = 0;
        foreach ($t['codes'] as $code_id => $m) {
            if (!intval($codes[$code_id]['code_paid'] ?? 1) && ($codes[$code_id]['code_kind'] ?? 'work') !== 'leave') {
                $unpaid_work += $m;
            }
        }
        $t['paid'] = $t['worked'] - $unpaid_work + $t['leave_paid'];
        $t['total'] = $t['worked'] + array_sum(array_map(function ($code_id) use ($codes, $t) {
            return ($codes[$code_id]['code_kind'] ?? '') === 'leave' ? $t['codes'][$code_id] : 0;
        }, array_keys($t['codes'])));
        $t['leave'] = $t['total'] - $t['worked'];
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
    $result = mysqli_query($mysqli, "SELECT ticket_id, ticket_prefix, ticket_number, ticket_subject, ticket_billable, ticket_client_id, client_name,
            SUM(TIME_TO_SEC(ticket_reply_time_worked)) AS seconds
        FROM ticket_replies JOIN tickets ON ticket_id = ticket_reply_ticket_id LEFT JOIN clients ON client_id = ticket_client_id
        WHERE ticket_reply_archived_at IS NULL AND ticket_reply_time_worked > '00:00:00' AND ticket_reply_by = " . intval($user_id) . "
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
 * Save the grid: $cells[date][code_id] = hours text. Returns [ok, message]. Days are limited to 24 hours
 * with the ticket time; codes must exist; dates must be in the period.
 */
function mitTsSaveCells(array $sheet, array $period, array $cells) {
    global $mysqli, $session_user_id;
    $user_id = intval($sheet['user_id']);
    $codes = mitTsCodes(true);
    $dates = array_flip(mitTsDates($period));
    $tickets = mitTsTicketMinutes([$user_id], $period['start'], $period['end'])[$user_id] ?? [];
    $current = mitTsEntries([$user_id], $period['start'], $period['end'])[$user_id] ?? [];
    $wanted = [];
    $bad = [];
    foreach ($cells as $date => $by_code) {
        if (!isset($dates[$date]) || !is_array($by_code)) {
            continue;
        }
        foreach ($by_code as $code_id => $text) {
            $code_id = intval($code_id);
            if (!isset($codes[$code_id])) {
                continue;
            }
            $minutes = mitTsParseHours($text);
            if ($minutes === null || $minutes > 1440) {
                $bad[] = date('M j', strtotime($date)) . ' ' . $codes[$code_id]['code_name'];
                continue;
            }
            // An archived code keeps what it had, but takes nothing new
            if ($codes[$code_id]['code_archived_at'] !== null && $minutes > intval($current[$date][$code_id]['minutes'] ?? 0)) {
                continue;
            }
            $wanted[$date][$code_id] = $minutes;
        }
    }
    if ($bad) {
        return [false, 'Could not read the hours for ' . implode(', ', array_slice($bad, 0, 5)) . ' - use e.g. 7.5 or 7:30.'];
    }
    foreach ($wanted as $date => $by_code) {
        $day = array_sum($by_code) + ($tickets[$date]['billable'] ?? 0) + ($tickets[$date]['other'] ?? 0);
        foreach ($current[$date] ?? [] as $code_id => $entry) {
            if (!isset($by_code[$code_id])) {
                $day += $entry['minutes'];
            }
        }
        if ($day > 1440) {
            return [false, date('M j', strtotime($date)) . ' adds up to more than 24 hours.'];
        }
    }
    $changed = 0;
    foreach ($wanted as $date => $by_code) {
        foreach ($by_code as $code_id => $minutes) {
            $was = intval($current[$date][$code_id]['minutes'] ?? 0);
            if ($was === $minutes) {
                continue;
            }
            if ($minutes === 0) {
                mysqli_query($mysqli, "DELETE FROM mit_time_entries WHERE user_id = $user_id AND entry_date = '" . escapeSql($date) . "' AND code_id = $code_id AND source = 'manual'");
            } else {
                mysqli_query($mysqli, "INSERT INTO mit_time_entries SET user_id = $user_id, entry_date = '" . escapeSql($date) . "', code_id = $code_id, source = 'manual',
                    minutes = $minutes, updated_by = " . intval($session_user_id) . " ON DUPLICATE KEY UPDATE minutes = $minutes, updated_by = " . intval($session_user_id));
            }
            $changed++;
        }
    }
    if ($changed) {
        mitTsSheetSaved($user_id, $period);
        if ($user_id !== intval($session_user_id)) {
            logAudit('Timesheet', 'Edit', escapeSql(mitTsWho() . " changed $changed entr" . ($changed === 1 ? 'y' : 'ies') . " on the timesheet of user $user_id for " . $period['label']), 0, $user_id);
        }
    }
    return [true, $changed ? 'Timesheet saved' : 'Nothing changed'];
}

/** For a handler: the stored timesheet by id, if the agent may see it, with its period - or back with an error. */
function mitTsSheetForRequest($sheet_id) {
    global $mysqli;
    $row = mitTsReady() ? mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT * FROM mit_timesheets WHERE sheet_id = " . intval($sheet_id))) : null;
    if (!$row || !mitTsMaySee($row['user_id'])) {
        flashAlert('That timesheet does not exist, or it is not yours.', 'error');
        redirect();
    }
    $period = mitTsPeriodFor($row['period_start']);
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

/** An app notification for someone. */
function mitTsNotify($user_id, $text, $link, $entity_id) {
    global $mysqli;
    mysqli_query($mysqli, "INSERT INTO notifications SET notification_type = 'Timesheet', notification = '" . escapeSql($text) . "',
        notification_action = '" . escapeSql($link) . "', notification_user_id = " . intval($user_id) . ", notification_entity_id = " . intval($entity_id));
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
    $sheet = mitTsSheetSaved($sheet['user_id'], $period);
    mysqli_query($mysqli, "UPDATE mit_timesheets SET status = 'submitted', submitted_at = NOW(), decision_note = NULL WHERE sheet_id = " . intval($sheet['sheet_id']));
    $user = mitTsUsers([$sheet['user_id']])[intval($sheet['user_id'])] ?? ['user_name' => 'Someone'];
    foreach (mitTsApprovers() as $approver_id => $approver) {
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
    return [true, 'Taken back - you can change it and submit it again'];
}

/** Copy the period's ticket time in as entries (on approval), replacing any copied before. */
function mitTsSnapshotTickets($user_id, array $period) {
    global $mysqli, $session_user_id;
    $user_id = intval($user_id);
    mysqli_query($mysqli, "DELETE FROM mit_time_entries WHERE user_id = $user_id AND source IN ('ticket_billable', 'ticket_other')
        AND entry_date BETWEEN '" . escapeSql($period['start']) . "' AND '" . escapeSql($period['end']) . "'");
    foreach (mitTsTicketMinutesLive([$user_id], $period['start'], $period['end'])[$user_id] ?? [] as $date => $values) {
        foreach (['ticket_billable' => $values['billable'], 'ticket_other' => $values['other']] as $source => $minutes) {
            if ($minutes > 0) {
                mysqli_query($mysqli, "INSERT INTO mit_time_entries SET user_id = $user_id, entry_date = '" . escapeSql($date) . "', code_id = 0, source = '$source',
                    minutes = $minutes, updated_by = " . intval($session_user_id));
            }
        }
    }
}

/** Approve or send back a timesheet (Full access). $decision 'approve' | 'return'. Returns [ok, message]. */
function mitTsDecide(array $sheet, array $period, $decision, $note = '') {
    global $mysqli, $session_user_id;
    $note = trim((string) $note);
    if ($decision === 'approve') {
        if (!in_array($sheet['status'], ['submitted', 'open', 'returned'], true)) {
            return [false, 'This timesheet is already approved.'];
        }
        $sheet = mitTsSheetSaved($sheet['user_id'], $period);
        mitTsSnapshotTickets($sheet['user_id'], $period);
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

/** Reopen an approved period (Full access) - ticket time is live again until it is approved again. */
function mitTsReopen(array $sheet, array $period) {
    global $mysqli;
    if ($sheet['status'] !== 'approved') {
        return [false, 'Only an approved timesheet can be reopened.'];
    }
    mysqli_query($mysqli, "DELETE FROM mit_time_entries WHERE user_id = " . intval($sheet['user_id']) . " AND source IN ('ticket_billable', 'ticket_other')
        AND entry_date BETWEEN '" . escapeSql($period['start']) . "' AND '" . escapeSql($period['end']) . "'");
    mysqli_query($mysqli, "UPDATE mit_timesheets SET status = 'open', submitted_at = NULL, decided_by = 0, decided_at = NULL, decision_note = NULL WHERE sheet_id = " . intval($sheet['sheet_id']));
    $user = mitTsUsers([$sheet['user_id']])[intval($sheet['user_id'])] ?? ['user_name' => 'user ' . intval($sheet['user_id'])];
    logAudit('Timesheet', 'Edit', escapeSql(mitTsWho() . ' reopened the approved timesheet of ' . $user['user_name'] . ' for ' . $period['label']), 0, intval($sheet['user_id']));
    return [true, 'Reopened - approve it again when it is right'];
}

/** Submitted timesheets waiting for an approver (side menu badge). */
function mitTsWaitingCount() {
    global $mysqli;
    return mitTsReady() ? intval(mysqli_fetch_row(mysqli_query($mysqli, "SELECT COUNT(*) FROM mit_timesheets WHERE status = 'submitted'"))[0]) : 0;
}
