<?php

/*
 * Mountaineer IT - calendar additions on top of ITFlow's calendar (functions/calendar.php).
 *
 *  - Every agent has a colour of their own, worked out from their user id, so a new tech
 *    has one the moment their account exists and their booked work is told apart on the
 *    calendar at a glance.
 *  - Booked work on a ticket has an end as well as a start (tickets.ticket_schedule_end,
 *    database update M001). An empty end is ITFlow's default length, CALENDAR_TICKET_MINUTES.
 */

/** Twelve colours that read well on white and dark backgrounds and are easy to tell apart. */
const MIT_AGENT_COLORS = [
    '#2563eb', '#16a34a', '#d97706', '#9333ea', '#dc2626', '#0891b2',
    '#db2777', '#65a30d', '#7c3aed', '#ea580c', '#0d9488', '#4f46e5',
];

/**
 * An agent's calendar colour: the one they picked (saved as the calendar.color preference),
 * or one worked out from their user id - the same every time, different for the first twelve.
 */
function mitAgentColor($user_id) {
    $user_id = intval($user_id);
    if ($user_id <= 0) {
        return '#6c757d';
    }
    $chosen = mitAgentChosenColors();
    return $chosen[$user_id] ?? mitAgentDefaultColor($user_id);
}

/** The colour an agent has until they pick one. */
function mitAgentDefaultColor($user_id) {
    return MIT_AGENT_COLORS[(max(1, intval($user_id)) - 1) % count(MIT_AGENT_COLORS)];
}

/** Every agent's picked colour, user_id => '#rrggbb' - read once per request. Pass true after saving one. */
function mitAgentChosenColors($reload = false) {
    global $mysqli;
    static $colors = null;
    if ($colors === null || $reload) {
        $colors = [];
        $result = mysqli_query($mysqli, "SELECT user_id, value FROM mit_user_preferences WHERE preference = 'calendar.color'");
        while ($result && $row = mysqli_fetch_assoc($result)) {
            if (mitIsHexColor($row['value'])) {
                $colors[intval($row['user_id'])] = strtolower($row['value']);
            }
        }
    }
    return $colors;
}

/** A '#rrggbb' colour? */
function mitIsHexColor($color) {
    return is_string($color) && preg_match('/^#[0-9a-fA-F]{6}$/', $color) === 1;
}

/** May the signed-in agent change this agent's calendar colour? Their own, or anyone's for an admin. */
function mitCanSetAgentColor($user_id) {
    global $session_user_id, $session_is_admin;
    return intval($user_id) > 0 && (intval($user_id) === intval($session_user_id) || !empty($session_is_admin));
}

/** When booked work ends: its stored end, or ITFlow's default length after the start. */
function mitTicketScheduleEnd($start, $end = null) {
    if (empty($start)) {
        return null;
    }
    if (!empty($end) && strtotime($end) > strtotime($start)) {
        return date('Y-m-d H:i:s', strtotime($end));
    }
    return date('Y-m-d H:i:s', strtotime($start) + CALENDAR_TICKET_MINUTES * 60);
}

/** Booked work's length in minutes. */
function mitTicketScheduleMinutes($start, $end = null) {
    if (empty($start)) {
        return CALENDAR_TICKET_MINUTES;
    }
    return intval(round((strtotime(mitTicketScheduleEnd($start, $end)) - strtotime($start)) / 60));
}

/** The lengths offered when booking work, in minutes => label. */
function mitScheduleLengths() {
    $lengths = [];
    foreach ([15, 30, 45, 60, 90, 120, 150, 180, 240, 300, 360, 420, 480] as $minutes) {
        $hours = intdiv($minutes, 60);
        $rest = $minutes % 60;
        $label = $hours ? $hours . ' hour' . ($hours > 1 ? 's' : '') : '';
        if ($rest) {
            $label .= ($label ? ' ' : '') . "$rest min";
        }
        $lengths[$minutes] = $label;
    }
    return $lengths;
}

/** "Tuesday, October 20, 2026, 1:00pm - 3:00pm" (or across days, both dates in full). */
function mitScheduleWhenText($start, $end = null) {
    $end = mitTicketScheduleEnd($start, $end);
    $start_ts = strtotime($start);
    $end_ts = strtotime($end);
    if (date('Y-m-d', $start_ts) === date('Y-m-d', $end_ts)) {
        return date('l, F j, Y, g:ia', $start_ts) . ' - ' . date('g:ia', $end_ts);
    }
    return date('l, F j, Y, g:ia', $start_ts) . ' - ' . date('l, F j, Y, g:ia', $end_ts);
}

/** Short form for the ticket page: "Oct 20, 2026 • 1:00 PM - 3:00 PM". */
function mitScheduleShortText($start, $end = null) {
    $end = mitTicketScheduleEnd($start, $end);
    $start_ts = strtotime($start);
    $end_ts = strtotime($end);
    if (date('Y-m-d', $start_ts) === date('Y-m-d', $end_ts)) {
        return date('M d, Y • g:i A', $start_ts) . ' - ' . date('g:i A', $end_ts);
    }
    return date('M d, Y • g:i A', $start_ts) . ' - ' . date('M d, g:i A', $end_ts);
}

/**
 * The agent's other open booked work that overlaps $start - $end, as plain text lines.
 * Overlap uses each booking's real end (or the default length when it has none).
 */
function mitScheduleConflicts($user_id, $start, $end, $ticket_id = 0) {
    global $mysqli;
    $user_id = intval($user_id);
    $ticket_id = intval($ticket_id);
    if (!$user_id || empty($start)) {
        return [];
    }
    $start = escapeSql(date('Y-m-d H:i:s', strtotime($start)));
    $end = escapeSql(mitTicketScheduleEnd($start, $end));
    $default_minutes = intval(CALENDAR_TICKET_MINUTES);
    $conflicts = [];
    $sql = mysqli_query($mysqli, "SELECT ticket_prefix, ticket_number, ticket_schedule, ticket_schedule_end, ticket_subject FROM tickets
        WHERE ticket_assigned_to = $user_id AND ticket_id <> $ticket_id
        AND ticket_resolved_at IS NULL AND ticket_closed_at IS NULL AND ticket_archived_at IS NULL
        AND ticket_schedule IS NOT NULL
        AND ticket_schedule < '$end'
        AND COALESCE(ticket_schedule_end, DATE_ADD(ticket_schedule, INTERVAL $default_minutes MINUTE)) > '$start'
        ORDER BY ticket_schedule");
    while ($row = mysqli_fetch_assoc($sql)) {
        $row_end = mitTicketScheduleEnd($row['ticket_schedule'], $row['ticket_schedule_end']);
        $conflicts[] = ticketReference($row) . ' - ' . $row['ticket_subject'] . ' @ '
            . date('g:ia', strtotime($row['ticket_schedule'])) . '-' . date('g:ia', strtotime($row_end));
    }
    return $conflicts;
}

/*
 * ---------- per-user preferences (mit_user_preferences, database update M002) ----------
 */

/** A saved preference for a user, or $default. */
function mitUserPreference($user_id, $preference, $default = null) {
    global $mysqli;
    $user_id = intval($user_id);
    $preference = escapeSql($preference);
    if (!$user_id) {
        return $default;
    }
    $result = mysqli_query($mysqli, "SELECT value FROM mit_user_preferences WHERE user_id = $user_id AND preference = '$preference' LIMIT 1");
    $row = $result ? mysqli_fetch_assoc($result) : null;
    return $row ? $row['value'] : $default;
}

/** Save a preference for a user. */
function mitSetUserPreference($user_id, $preference, $value) {
    global $mysqli;
    $user_id = intval($user_id);
    if (!$user_id) {
        return;
    }
    $preference = mysqli_real_escape_string($mysqli, (string) $preference);
    $value = mysqli_real_escape_string($mysqli, (string) $value);
    mysqli_query($mysqli, "INSERT INTO mit_user_preferences SET user_id = $user_id, preference = '$preference', value = '$value'
        ON DUPLICATE KEY UPDATE value = '$value'");
}

/** The calendar's settings as saved for this user ('all' or 'client' page), cleaned - [] when none. */
function mitCalendarState($user_id, $page) {
    $state = json_decode((string) mitUserPreference($user_id, "calendar.$page", ''), true);
    return is_array($state) ? mitCleanCalendarState($state) : [];
}

/** Only the keys the calendar page uses, in the shapes it expects. */
function mitCleanCalendarState(array $state) {
    $clean = [];
    if (isset($state['view']) && in_array($state['view'], ['dayGridMonth', 'timeGridWeek', 'timeGridDay', 'listWeek', 'listMonth', 'listDay'], true)) {
        $clean['view'] = $state['view'];
    }
    if (isset($state['agent'])) {
        $clean['agent'] = max(0, intval($state['agent']));
    }
    if (isset($state['hidden']) && is_array($state['hidden'])) {
        $clean['hidden'] = array_values(array_slice(array_map('strval', array_map('intval', $state['hidden'])), 0, 200));
    }
    if (isset($state['sources']) && is_array($state['sources'])) {
        // An object, even when empty - the page sets keys on it
        $clean['sources'] = new stdClass();
        foreach ($state['sources'] as $key => $on) {
            if (preg_match('/^[a-z_]{1,40}$/', (string) $key)) {
                $clean['sources']->$key = (bool) $on;
            }
        }
    }
    return $clean;
}
