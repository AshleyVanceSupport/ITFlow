<?php

/*
 * Mountaineer IT - times on ticket notes: when the work on a note with time worked was done - date, start and end, as the
 * person's clock read them (stored as typed, like the California work times) - in mit_reply_times (M028). The reply box
 * asks for them whenever time is logged (agent/js/mit_reply_times.js keeps them in step with ITFlow's timer: end = now,
 * start = end less the timer, until they are typed; typed times set the length). Edit reply has them too.
 * A note without a row (from before, the API, bulk resolve, email) counts as ending when it was posted.
 * Timesheets count ticket time on the day it was worked (mitTsReplyWorkRows in functions/mit_timesheets.php).
 * Hooks: agent/ticket.php (mitReplyTimesField in the reply box, mitReplyTimesText beside a note's time),
 * agent/modals/ticket/ticket_reply_edit.php (mitReplyTimesEditField), agent/post/ticket.php add_ticket_reply
 * (mitReplyTimesFromPost + mitReplyTimesSavePending) and edit_ticket_reply (mitReplyTimesFromEdit + mitReplyTimesSavePending).
 */

/** Has database update M028 run? */
function mitReplyTimesReady() {
    global $mysqli;
    static $ready = null;
    if ($ready === null) {
        try {
            $ready = mysqli_num_rows(mysqli_query($mysqli, "SHOW TABLES LIKE 'mit_reply_times'")) === 1;
        } catch (Throwable $e) {
            $ready = false;
        }
    }
    return $ready;
}

/** A date (Y-m-d) and HH:MM start / end -> ['Y-m-d H:i:s' start, end] (an end before the start runs past midnight), or null. */
function mitReplyTimesParse($date, $start, $end) {
    $date = (string) $date;
    $start = (string) $start;
    $end = (string) $end;
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !strtotime($date) || !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $start) || !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $end)) {
        return null;
    }
    $from = strtotime("$date $start:00");
    $to = strtotime("$date $end:00");
    if ($to <= $from) {
        $to += 86400;
    }
    if ($to - $from > 86400 - 60) {
        return null;
    }
    return [date('Y-m-d H:i:s', $from), date('Y-m-d H:i:s', $to)];
}

/** "HH:MM:SS" -> seconds. */
function mitReplyTimesSeconds($time) {
    $parts = array_map('intval', explode(':', (string) $time) + [0, 0, 0]);
    return $parts[0] * 3600 + $parts[1] * 60 + $parts[2];
}

/** Seconds -> "HH:MM:SS" (under a day). */
function mitReplyTimesClock($seconds) {
    $seconds = max(0, min(86399, intval($seconds)));
    return sprintf('%02d:%02d:%02d', intdiv($seconds, 3600), intdiv($seconds % 3600, 60), $seconds % 60);
}

/** What to save once the note exists: [start, end], 'delete', or null (nothing). */
function mitReplyTimesPending($set = false) {
    static $pending = null;
    if ($set !== false) {
        $pending = $set;
    }
    return $pending;
}

/**
 * Is the timesheet holding $date for this person submitted or approved? Returns 'submitted' / 'approved' or ''. Time on
 * those days can't move in or out from a note.
 */
function mitReplyTimesLocked($user_id, $date) {
    if (!function_exists('mitTsReady') || !mitTsReady() || !mitModuleOn('timesheets') || !$date) {
        return '';
    }
    $sheet = mitTsSheet($user_id, mitTsPeriodFor($date));
    if ($sheet['status'] === 'approved' || mitTsApprovedDates($user_id, $date, $date)) {
        return 'approved';
    }
    return $sheet['status'] === 'submitted' ? 'submitted' : '';
}

/**
 * Hook in add_ticket_reply, after ITFlow has made the time worked from Hrs / Mins / Secs: the note's times from the form.
 * Typed times decide the length (unless they agree with it to the minute, keeping the timer's seconds). Returns the time
 * worked to store; mitReplyTimesSavePending() writes the times once the note is in. A note whose times can't be read, or
 * fall on a submitted / approved timesheet, keeps its length and counts as ending when posted (with a message).
 */
function mitReplyTimesFromPost(array $post, $time_worked) {
    global $session_user_id;
    mitReplyTimesPending(null);
    $seconds = mitReplyTimesSeconds($time_worked);
    if (!mitReplyTimesReady() || $seconds <= 0) {
        return $time_worked;
    }
    $range = mitReplyTimesParse($post['mit_work_date'] ?? '', $post['mit_work_start'] ?? '', $post['mit_work_end'] ?? '');
    if (!$range) {
        return $time_worked;
    }
    $locked = mitReplyTimesLocked($session_user_id, substr($range[0], 0, 10));
    if ($locked) {
        flashAlert('The times were not kept: ' . date('M j', strtotime($range[0])) . " is on your $locked timesheet, so the time counts as worked now.", 'warning');
        return $time_worked;
    }
    $length = strtotime($range[1]) - strtotime($range[0]);
    mitReplyTimesPending($range);
    return abs($length - $seconds) < 60 ? $time_worked : mitReplyTimesClock($length);
}

/**
 * Hook in edit_ticket_reply, before ITFlow's save: the times as edited. New times decide the length; the same times with
 * a new length move the start (the end stays). Moving time into or out of a submitted / approved timesheet is refused.
 * Returns the time worked to store.
 */
function mitReplyTimesFromEdit($ticket_reply_id, array $post, $time_worked) {
    global $mysqli;
    mitReplyTimesPending(null);
    $ticket_reply_id = intval($ticket_reply_id);
    if (!mitReplyTimesReady() || !$ticket_reply_id) {
        return $time_worked;
    }
    $reply = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT ticket_reply_by, ticket_reply_created_at, ticket_reply_time_worked FROM ticket_replies WHERE ticket_reply_id = $ticket_reply_id"));
    if (!$reply) {
        return $time_worked;
    }
    $old = mitReplyTimesOf($ticket_reply_id);
    $old_date = substr($old ? $old['work_start'] : $reply['ticket_reply_created_at'], 0, 10);
    $seconds = mitReplyTimesSeconds($time_worked);
    $range = null;
    if ($seconds > 0) {
        $range = mitReplyTimesParse($post['mit_work_date'] ?? '', $post['mit_work_start'] ?? '', $post['mit_work_end'] ?? '');
        $same = $range && $old && substr($range[0], 0, 16) === substr($old['work_start'], 0, 16) && substr($range[1], 0, 16) === substr($old['work_end'], 0, 16);
        if ($range && ($same || !$old) && $seconds !== mitReplyTimesSeconds($reply['ticket_reply_time_worked'])) {
            // Only the length changed: the end stays, the start moves
            $range[0] = date('Y-m-d H:i:s', strtotime($range[1]) - $seconds);
        } elseif ($range) {
            $length = strtotime($range[1]) - strtotime($range[0]);
            $time_worked = abs($length - $seconds) < 60 ? $time_worked : mitReplyTimesClock($length);
        }
    }
    $new_date = $range ? substr($range[0], 0, 10) : $old_date;
    if ($new_date !== $old_date) {
        foreach ([$old_date, $new_date] as $date) {
            $locked = mitReplyTimesLocked($reply['ticket_reply_by'], $date);
            if ($locked) {
                flashAlert('This note\'s time can\'t move ' . ($date === $old_date ? 'off ' : 'onto ') . date('M j', strtotime($date)) . " - that day is on a $locked timesheet.", 'error');
                redirect();
            }
        }
    }
    mitReplyTimesPending($seconds > 0 ? $range : 'delete');
    return $time_worked;
}

/** After the note is saved: write (or remove) its times. */
function mitReplyTimesSavePending($ticket_reply_id) {
    global $mysqli, $session_user_id;
    $pending = mitReplyTimesPending();
    $ticket_reply_id = intval($ticket_reply_id);
    if (!$ticket_reply_id || $pending === null || !mitReplyTimesReady()) {
        return;
    }
    if ($pending === 'delete') {
        mysqli_query($mysqli, "DELETE FROM mit_reply_times WHERE reply_id = $ticket_reply_id");
    } else {
        [$start, $end] = array_map('escapeSql', $pending);
        mysqli_query($mysqli, "INSERT INTO mit_reply_times SET reply_id = $ticket_reply_id, work_start = '$start', work_end = '$end', set_by = " . intval($session_user_id) . "
            ON DUPLICATE KEY UPDATE work_start = '$start', work_end = '$end', set_by = " . intval($session_user_id) . ", set_at = NOW()");
    }
    mitReplyTimesPending(null);
}

/** One note's times, or null. */
function mitReplyTimesOf($ticket_reply_id) {
    global $mysqli;
    if (!mitReplyTimesReady()) {
        return null;
    }
    return mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT work_start, work_end FROM mit_reply_times WHERE reply_id = " . intval($ticket_reply_id))) ?: null;
}

/** "Tue Oct 20, 9:10 - 9:40 AM" (the end's day too when it runs past midnight). */
function mitReplyTimesWords($start, $end) {
    $from = strtotime($start);
    $to = strtotime($end);
    $same_half = date('A', $from) === date('A', $to) && date('Y-m-d', $from) === date('Y-m-d', $to);
    return date('D M j, ', $from) . date($same_half ? 'g:i' : 'g:i A', $from) . ' - '
        . (date('Y-m-d', $from) !== date('Y-m-d', $to) ? date('D ', $to) : '') . date('g:i A', $to);
}

/** agent/ticket.php: beside a note's time, when it was worked. */
function mitReplyTimesText($ticket_reply_id, $ticket_id) {
    global $mysqli;
    static $cache = [];
    $ticket_id = intval($ticket_id);
    if (!mitReplyTimesReady()) {
        return '';
    }
    if (!isset($cache[$ticket_id])) {
        $cache[$ticket_id] = [];
        $sql = mysqli_query($mysqli, "SELECT t.reply_id, t.work_start, t.work_end FROM mit_reply_times t JOIN ticket_replies ON ticket_reply_id = t.reply_id
            WHERE ticket_reply_ticket_id = $ticket_id");
        while ($row = mysqli_fetch_assoc($sql)) {
            $cache[$ticket_id][intval($row['reply_id'])] = $row;
        }
    }
    $row = $cache[$ticket_id][intval($ticket_reply_id)] ?? null;
    return $row ? ' <span class="text-nowrap" data-mit-reply-times title="When the work was done">(' . escapeHtml(mitReplyTimesWords($row['work_start'], $row['work_end'])) . ')</span>' : '';
}

/** The reply box: Date worked / Start / End, kept in step with the timer by agent/js/mit_reply_times.js. */
function mitReplyTimesField() {
    if (!mitReplyTimesReady()) {
        return '';
    }
    return '<div class="col-12" data-mit-reply-times-field>'
        . '<div class="row g-2 align-items-end">'
        . '<div class="col-sm-4"><label class="form-label small text-secondary mb-1" for="mitWorkDate">Date worked</label>'
        . '<input type="date" class="form-control" id="mitWorkDate" name="mit_work_date"></div>'
        . '<div class="col-6 col-sm-3"><label class="form-label small text-secondary mb-1" for="mitWorkStart">Start</label>'
        . '<input type="time" step="60" class="form-control" id="mitWorkStart" name="mit_work_start"></div>'
        . '<div class="col-6 col-sm-3"><label class="form-label small text-secondary mb-1" for="mitWorkEnd">End</label>'
        . '<input type="time" step="60" class="form-control" id="mitWorkEnd" name="mit_work_end"></div>'
        . '<div class="col-sm-2 small text-secondary pb-2" data-mit-times-mode>Follows the timer</div>'
        . '</div>'
        . '<div class="small text-danger mt-1" data-mit-times-error hidden></div>'
        . '</div><script src="' . mitAsset('/agent/js/mit_reply_times.js') . '"></script>';
}

/** Edit reply: the note's times (or, for an older note, when it ended by its posting). */
function mitReplyTimesEditField($ticket_reply_id) {
    global $mysqli;
    if (!mitReplyTimesReady()) {
        return '';
    }
    $row = mitReplyTimesOf($ticket_reply_id);
    if (!$row) {
        $reply = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT ticket_reply_created_at, ticket_reply_time_worked FROM ticket_replies WHERE ticket_reply_id = " . intval($ticket_reply_id)));
        $end = strtotime($reply['ticket_reply_created_at'] ?? 'now');
        $row = ['work_start' => date('Y-m-d H:i:s', $end - mitReplyTimesSeconds($reply['ticket_reply_time_worked'] ?? '')), 'work_end' => date('Y-m-d H:i:s', $end)];
    }
    return '<div class="row g-2 mt-1">'
        . '<div class="col-sm-4"><label class="form-label small mb-1">Date worked</label><input type="date" class="form-control" name="mit_work_date" value="' . substr($row['work_start'], 0, 10) . '"></div>'
        . '<div class="col-6 col-sm-4"><label class="form-label small mb-1">Start</label><input type="time" step="60" class="form-control" name="mit_work_start" value="' . substr($row['work_start'], 11, 5) . '"></div>'
        . '<div class="col-6 col-sm-4"><label class="form-label small mb-1">End</label><input type="time" step="60" class="form-control" name="mit_work_end" value="' . substr($row['work_end'], 11, 5) . '"></div>'
        . '<div class="col-12 form-text mt-0">Change the times and the length follows them; change only the length and the start moves.</div>'
        . '</div>';
}

/*
 * ---------- for the timesheets (functions/mit_timesheets.php) ----------
 */

/**
 * Notes with time worked by these people that count on $from - $to: the day they were worked - from their times, else
 * the day they were posted where the person was (mitTsLocalDate). Each row: user_id, date, reply_id, ticket_id, reference,
 * subject, client_id, client_name, billable (1/0), seconds, start / end ('Y-m-d H:i:s', or null when the note has no times:
 * it is then taken as ending when posted - 'est' true).
 */
function mitTsReplyWorkRows(array $user_ids, $from, $to) {
    global $mysqli;
    $user_ids = array_values(array_filter(array_map('intval', $user_ids)));
    if (!$user_ids) {
        return [];
    }
    $settings = mitTsSettings();
    $billable = mitTsBillableSql($settings['billable']);
    $where = "ticket_reply_archived_at IS NULL AND ticket_reply_time_worked > '00:00:00' AND ticket_reply_by IN (" . implode(',', $user_ids) . ")"
        . ($settings['ticket_internal'] ? '' : " AND ticket_reply_type <> 'Internal'");
    // Posted two days either side (people in other time zones), or worked within the dates
    $wide_from = escapeSql(date('Y-m-d', strtotime("$from -2 days")) . ' 00:00:00');
    $wide_to = escapeSql(date('Y-m-d', strtotime("$to +3 days")) . ' 00:00:00');
    $day_from = escapeSql($from . ' 00:00:00');
    $day_to = escapeSql(date('Y-m-d', strtotime("$to +1 day")) . ' 00:00:00');
    $ready = mitReplyTimesReady();
    $result = mysqli_query($mysqli, "SELECT ticket_reply_id, ticket_reply_by, ticket_reply_created_at, TIME_TO_SEC(ticket_reply_time_worked) AS seconds,
            IF($billable, 1, 0) AS billable, ticket_id, ticket_prefix, ticket_number, ticket_subject, ticket_client_id, client_name,
            " . ($ready ? 'mrt.work_start, mrt.work_end' : 'NULL AS work_start, NULL AS work_end') . "
        FROM ticket_replies JOIN tickets ON ticket_id = ticket_reply_ticket_id LEFT JOIN clients ON client_id = ticket_client_id
        " . ($ready ? 'LEFT JOIN mit_reply_times mrt ON mrt.reply_id = ticket_reply_id' : '') . "
        WHERE $where AND (" . ($ready
            ? "(mrt.reply_id IS NULL AND ticket_reply_created_at >= '$wide_from' AND ticket_reply_created_at < '$wide_to')
               OR (mrt.work_start >= '$day_from' AND mrt.work_start < '$day_to')"
            : "ticket_reply_created_at >= '$wide_from' AND ticket_reply_created_at < '$wide_to'") . ")
        ORDER BY COALESCE(" . ($ready ? 'mrt.work_start, ' : '') . "ticket_reply_created_at)");
    $rows = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $user_id = intval($row['ticket_reply_by']);
        $date = $row['work_start'] ? substr($row['work_start'], 0, 10) : mitTsLocalDate($user_id, $row['ticket_reply_created_at']);
        if ($date < $from || $date > $to) {
            continue;
        }
        $rows[] = [
            'user_id' => $user_id, 'date' => $date, 'reply_id' => intval($row['ticket_reply_id']), 'ticket_id' => intval($row['ticket_id']),
            'reference' => ticketReference($row), 'ticket_prefix' => $row['ticket_prefix'], 'ticket_number' => $row['ticket_number'], 'subject' => $row['ticket_subject'], 'client_id' => intval($row['ticket_client_id']),
            'client_name' => (string) $row['client_name'], 'billable' => intval($row['billable']), 'seconds' => intval($row['seconds']),
            'start' => $row['work_start'], 'end' => $row['work_end'], 'est' => !$row['work_start'],
        ];
    }
    return $rows;
}

/**
 * A day's ticket entries for the day panel, in time order, each flagged: 'overlap' (another of the person's timed entries
 * runs at the same time) and 'outside' (a California day with clocked work times, and this entry is not inside them).
 * Notes without times have no flags. $periods: the day's clocked work periods (start / end datetimes), or [].
 */
function mitTsDayEntryFlags(array $rows, array $periods) {
    $out = [];
    foreach ($rows as $i => $row) {
        $row['overlap'] = false;
        $row['outside'] = false;
        if (!$row['est']) {
            foreach ($rows as $j => $other) {
                if ($i !== $j && !$other['est'] && $row['start'] < $other['end'] && $other['start'] < $row['end']) {
                    $row['overlap'] = true;
                    break;
                }
            }
            if ($periods) {
                $inside = false;
                foreach ($periods as $period) {
                    if ($row['start'] >= $period['start'] && $row['end'] <= $period['end']) {
                        $inside = true;
                        break;
                    }
                }
                $row['outside'] = !$inside;
            }
        }
        $out[] = $row;
    }
    return $out;
}
