<?php

/*
 * Mountaineer IT - Dispatch (agent/mit_dispatch.php, agent/js/mit_dispatch.js, agent/mit_dispatch_ajax.php).
 *
 * A board of each tech's booked work - a day with a column per tech, or a week with a row per tech -
 * beside the queue of open tickets still to dispatch: those with no tech, and those with a tech but
 * no time booked. Dropping a ticket on the board (or moving a booking to another tech or time) opens
 * a quick confirm with the tech, start, length, onsite/remote and whether to email the contact, then
 * assigns it (ticketChangeAssignee) and books it (ticketSetSchedule) exactly as the ticket page does.
 * A booking dragged back onto the queue is taken off the schedule (ticketCancelSchedule).
 * Needs Support edit (calendarCanScheduleTickets), ticketing on, and the Dispatch switch on (Admin > Settings > Modules). Which techs show, the view and weekends are saved
 * per person in mit_user_preferences (dispatch.state). No tables of its own.
 */

/** The lengths offered when booking (minutes); a ticket's own length is added when it is not one of them. */
const MIT_DISPATCH_LENGTHS = [15, 30, 45, 60, 90, 120, 180, 240, 360, 480];

/** The board's working hours, from Admin > SLA's business days and hours: FullCalendar businessHours. */
function mitDispatchBusinessHours() {
    $sla = getSlaSettings();
    $time = function ($value, $fallback) {
        return preg_match('/^\d{1,2}:\d{2}/', (string) $value) ? substr((string) $value, 0, 5) : $fallback;
    };
    // ISO weekdays (Mon 1 .. Sun 7) to FullCalendar's (Sun 0 .. Sat 6)
    $days = array_map(function ($day) {
        return $day % 7;
    }, $sla['business_days'] ?: [1, 2, 3, 4, 5]);
    return ['daysOfWeek' => array_values($days), 'startTime' => $time($sla['business_hours_start'], '08:00'), 'endTime' => $time($sla['business_hours_end'], '17:00')];
}

/** May the signed-in agent dispatch? Support edit, with ticketing on (Admin > Modules) and our Dispatch switch on. */
function mitDispatchCan() {
    return mitModuleOn('dispatch') && calendarCanScheduleTickets();
}

/** Agents who can be dispatched to: user_id => [user_id, name, color, initials]. */
function mitDispatchTechs() {
    global $mysqli;
    $techs = [];
    $result = mysqli_query($mysqli, "SELECT user_id, user_name FROM users WHERE user_type = 1 AND user_status = 1 AND user_archived_at IS NULL ORDER BY user_name");
    while ($row = mysqli_fetch_assoc($result)) {
        $techs[intval($row['user_id'])] = ['id' => intval($row['user_id']), 'name' => $row['user_name'], 'color' => mitAgentColor($row['user_id'])];
    }
    return $techs;
}

/** The saved board settings for the signed-in agent: view (day | week), hidden (tech ids), weekends. */
function mitDispatchState() {
    global $session_user_id;
    $state = json_decode((string) mitUserPreference($session_user_id, 'dispatch.state', ''), true);
    $state = is_array($state) ? $state : [];
    return [
        'view' => ($state['view'] ?? '') === 'week' ? 'week' : 'day',
        'hidden' => array_values(array_filter(array_map('intval', (array) ($state['hidden'] ?? [])))),
        'weekends' => !empty($state['weekends']),
    ];
}

/**
 * The queue: open tickets with no tech ($tab 'unassigned') or with a tech and nothing booked ('unbooked'),
 * most urgent and oldest first. $filters: client_id, priority, q. Also the count for each tab.
 */
function mitDispatchQueue($tab, array $filters = []) {
    global $mysqli;
    $where = "ticket_resolved_at IS NULL AND ticket_closed_at IS NULL AND ticket_archived_at IS NULL" . clientScopeSql('ticket_client_id');
    $client_id = intval($filters['client_id'] ?? 0);
    if ($client_id && hasClientAccess($client_id)) {
        $where .= " AND ticket_client_id = $client_id";
    }
    $priority = (string) ($filters['priority'] ?? '');
    if (in_array($priority, ['Urgent', 'High', 'Medium', 'Low'], true)) {
        $where .= " AND ticket_priority = '$priority'";
    }
    $q = trim((string) ($filters['q'] ?? ''));
    if ($q !== '') {
        $like = escapeSql(mb_substr($q, 0, 100));
        $where .= " AND (ticket_subject LIKE '%$like%' OR client_name LIKE '%$like%' OR CONCAT(ticket_prefix, ticket_number) LIKE '%$like%' OR ticket_number = '" . intval($q) . "')";
    }
    $tabs = [
        'unassigned' => "ticket_assigned_to = 0",
        'unbooked' => "ticket_assigned_to > 0 AND ticket_schedule IS NULL",
    ];
    $tab = isset($tabs[$tab]) ? $tab : 'unassigned';
    $counts = [];
    foreach ($tabs as $key => $condition) {
        $counts[$key] = intval(mysqli_fetch_row(mysqli_query($mysqli, "SELECT COUNT(*) FROM tickets LEFT JOIN clients ON client_id = ticket_client_id WHERE $where AND $condition"))[0]);
    }
    $result = mysqli_query($mysqli, "SELECT tickets.ticket_id, ticket_prefix, ticket_number, ticket_subject, ticket_priority, ticket_onsite, ticket_client_id, ticket_created_at,
            ticket_schedule, ticket_schedule_end, ticket_assigned_to, client_name, user_name, ticket_status_name
        FROM tickets
        LEFT JOIN clients ON client_id = ticket_client_id
        LEFT JOIN users ON user_id = ticket_assigned_to
        LEFT JOIN ticket_statuses ON ticket_status_id = ticket_status
        WHERE $where AND {$tabs[$tab]}
        ORDER BY FIELD(ticket_priority, 'Urgent', 'High', 'Medium', 'Low'), ticket_created_at ASC
        LIMIT 200");
    $tickets = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $tickets[] = [
            'id' => intval($row['ticket_id']),
            'reference' => ticketReference($row),
            'subject' => $row['ticket_subject'],
            'client' => $row['client_name'] ?: 'Internal',
            'priority' => (string) $row['ticket_priority'],
            'status' => (string) $row['ticket_status_name'],
            'onsite' => intval($row['ticket_onsite']),
            'tech' => intval($row['ticket_assigned_to']),
            'tech_name' => (string) $row['user_name'],
            'age' => timeAgo($row['ticket_created_at']),
            'minutes' => mitTicketScheduleMinutes($row['ticket_schedule'], $row['ticket_schedule_end']),
        ];
    }
    return ['tab' => $tab, 'counts' => $counts, 'tickets' => $tickets];
}

/**
 * The board's bookings from $from to $to (Y-m-d) for these techs: each tech's tickets (lead and helper)
 * and their Outlook busy times, as FullCalendar items with 'tech' set.
 */
function mitDispatchItems($from, $to, array $tech_ids) {
    $techs = mitDispatchTechs();
    $sources = calendarSources();
    $items = [];
    $ticket_ids = [];
    foreach (array_unique(array_map('intval', $tech_ids)) as $tech_id) {
        if (!isset($techs[$tech_id])) {
            continue;
        }
        $opts = ['client_id' => 0, 'user_id' => $tech_id];
        $found = calendarSourceItems('tickets', "$from 00:00:00", "$to 23:59:59", $opts);
        if (isset($sources['outlook_busy'])) {
            $found = array_merge($found, calendarSourceItems('outlook_busy', "$from 00:00:00", "$to 23:59:59", $opts));
        }
        foreach ($found as $item) {
            $kind = $item['extendedProps']['kind'] ?? '';
            $ticket_ids[] = intval($item['extendedProps']['ticket_id'] ?? 0);
            // A helper's booking shows on their own column only
            if ($kind === 'helper' && intval($item['extendedProps']['user_id'] ?? 0) !== $tech_id) {
                continue;
            }
            $item['extendedProps']['tech'] = $tech_id;
            $item['editable'] = $kind === 'ticket' && !empty($item['editable']);
            $item['durationEditable'] = $item['editable'];
            $items[] = $item;
        }
    }
    // Each booking's ticket reference (the confirm's title), in one query
    $ticket_ids = array_filter(array_unique($ticket_ids));
    if ($ticket_ids) {
        $references = [];
        $result = mysqli_query($GLOBALS['mysqli'], "SELECT ticket_id, ticket_prefix, ticket_number FROM tickets WHERE ticket_id IN (" . implode(',', $ticket_ids) . ")");
        while ($row = mysqli_fetch_assoc($result)) {
            $references[intval($row['ticket_id'])] = ticketReference($row);
        }
        foreach ($items as &$item) {
            $item['extendedProps']['reference'] = $references[intval($item['extendedProps']['ticket_id'] ?? 0)] ?? '';
        }
        unset($item);
    }
    return $items;
}

/**
 * Assign (when it is someone new) and book a ticket. $minutes 15 - 1440; $tell_contact false keeps the contact
 * and watchers out of it (the tech is always told). Returns [ok, message, conflicts].
 */
function mitDispatchBook($ticket_id, $tech_id, $start, $minutes, $onsite, $tell_contact = true) {
    global $mit_schedule_skip_contact;
    $row = ticketRow($ticket_id);
    if (!$row || !empty($row['ticket_archived_at'])) {
        return [false, 'That ticket no longer exists.', []];
    }
    if (intval($row['ticket_client_id']) && !hasClientAccess($row['ticket_client_id'])) {
        return [false, 'You do not have access to that client.', []];
    }
    if (!ticketIsOpen($row)) {
        return [false, 'Ticket ' . ticketReference($row) . ' is resolved or closed - reopen it to schedule work.', []];
    }
    $start = ticketScheduleValue($start);
    if ($start === '') {
        return [false, 'Pick the date and time.', []];
    }
    $tech_id = intval($tech_id);
    if (!isset(mitDispatchTechs()[$tech_id])) {
        return [false, 'Pick an agent.', []];
    }
    if ($tech_id !== intval($row['ticket_assigned_to'])) {
        mitScheduleLeadChanging($row, $tech_id);
        [$assigned_ok, $assigned_message] = ticketChangeAssignee($row['ticket_id'], $tech_id);
        if (!$assigned_ok) {
            return [false, strip_tags($assigned_message), []];
        }
        $row = ticketRow($ticket_id);
    }
    $minutes = max(15, min(1440, intval($minutes)));
    $end = date('Y-m-d H:i:s', strtotime($start) + $minutes * 60);
    $mit_schedule_skip_contact = !$tell_contact;
    $result = ticketSetSchedule($row, $start, $onsite ? 1 : 0, $end);
    $mit_schedule_skip_contact = false;
    $tech = mitDispatchTechs()[$tech_id]['name'];
    return [true, ticketReference($row) . " scheduled with $tech for " . $result['when_text'], $result['conflicts']];
}

/** Take a ticket's booking off (dragged back to the queue). Returns [ok, message]. */
function mitDispatchUnbook($ticket_id) {
    $row = ticketRow($ticket_id);
    if (!$row || (intval($row['ticket_client_id']) && !hasClientAccess($row['ticket_client_id']))) {
        return [false, 'That ticket no longer exists.'];
    }
    if (empty($row['ticket_schedule'])) {
        return [false, ticketReference($row) . ' has no scheduled time.'];
    }
    ticketCancelSchedule($row);
    return [true, ticketReference($row) . ' taken off the schedule - it is back in the queue'];
}
