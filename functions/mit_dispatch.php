<?php

/*
 * Mountaineer IT - Dispatch (agent/mit_dispatch.php, agent/js/mit_dispatch.js, agent/mit_dispatch_ajax.php).
 *
 * A board of each tech's booked work - a day with a column per tech, or a week with a row per tech -
 * beside the queue of open tickets still to dispatch: those with no tech, and those with a tech but
 * no time booked. Dropping a ticket on the board (or moving a booking to another tech or time) opens
 * a quick confirm with the tech, start, length, onsite/remote, whether to email the contact, and the ticket's details
 * (client, contact, priority, category, SLA - changes saved first, by mitDispatchSaveDetails), then
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

/*
 * ---------- the ticket's details in the Schedule dialog ----------
 */

/** A client's contacts for the dialog's Contact list: [{id, name}], primary first. [] for no client or one out of reach. */
function mitDispatchContacts($client_id) {
    global $mysqli;
    $client_id = intval($client_id);
    if (!$client_id || !hasClientAccess($client_id)) {
        return [];
    }
    $contacts = [];
    $result = mysqli_query($mysqli, "SELECT contact_id, contact_name, contact_title FROM contacts WHERE contact_client_id = $client_id AND contact_archived_at IS NULL
        ORDER BY contact_primary DESC, contact_name");
    while ($row = mysqli_fetch_assoc($result)) {
        $contacts[] = ['id' => intval($row['contact_id']), 'name' => $row['contact_name'] . ($row['contact_title'] ? ' - ' . $row['contact_title'] : '')];
    }
    return $contacts;
}

/**
 * What the Schedule dialog shows of a ticket, so the client, contact, priority, status, category and SLA can be put right as it is
 * scheduled: the current values and the choices for each (clients this agent can see, the client's contacts, ticket
 * categories and SLAs still in use - plus the ticket's own if it was archived). Null when the ticket is out of reach.
 */
function mitDispatchDetails($ticket_id) {
    global $mysqli;
    $row = ticketRow($ticket_id);
    if (!$row || (intval($row['ticket_client_id']) && !hasClientAccess($row['ticket_client_id']))) {
        return null;
    }
    $category_id = intval($row['ticket_category']);
    $sla_id = intval($row['ticket_sla_id']);
    $pick = function ($sql) use ($mysqli) {
        $list = [];
        $result = mysqli_query($mysqli, $sql);
        while ($r = mysqli_fetch_row($result)) {
            $list[] = ['id' => intval($r[0]), 'name' => $r[1]];
        }
        return $list;
    };
    return [
        'ticket' => ['client_id' => intval($row['ticket_client_id']), 'contact_id' => intval($row['ticket_contact_id']), 'priority' => $row['ticket_priority'],
            'status_id' => intval($row['ticket_status']), 'category_id' => $category_id, 'sla_id' => $sla_id],
        // The statuses an agent may set by hand (ITFlow's own list), plus the one it has now when that is not one of them (New)
        'statuses' => array_values(array_map(function ($status) {
            return ['id' => intval($status['ticket_status_id']), 'name' => $status['ticket_status_name']];
        }, ticketPickableStatuses() + [intval($row['ticket_status']) => ['ticket_status_id' => $row['ticket_status'], 'ticket_status_name' => $row['ticket_status_name']]])),
        'clients' => $pick("SELECT client_id, client_name FROM clients WHERE (client_archived_at IS NULL OR client_id = " . intval($row['ticket_client_id']) . ")"
            . clientScopeSql('client_id') . " ORDER BY client_name"),
        'contacts' => mitDispatchContacts($row['ticket_client_id']),
        'priorities' => TICKET_PRIORITIES,
        'categories' => $pick("SELECT category_id, category_name FROM categories WHERE category_type = 'Ticket' AND (category_archived_at IS NULL OR category_id = $category_id) ORDER BY category_name"),
        'slas' => $pick("SELECT sla_id, sla_name FROM slas WHERE sla_archived_at IS NULL OR sla_id = $sla_id ORDER BY sla_name"),
    ];
}

/**
 * Save the details posted with a Dispatch booking (detail_client_id, detail_contact_id, detail_priority, detail_category_id,
 * detail_status, detail_sla - "auto", or an SLA id, 0 for none), only what changed, the way ITFlow's own forms do it: a new client as
 * "Change client" does (agent/post/ticket.php change_client_ticket - its public replies become internal, the old
 * client's asset, site and project links go); the contact only one of the client's; the priority through
 * ticketChangePriority(); the status through ticketChangeStatus() (only the ones an agent may set by hand); the SLA chosen pins it (edit_ticket_sla), otherwise a new client or priority works it out again.
 * Every change goes in the ticket's history and the audit log. Returns [ok, message or ''].
 */
function mitDispatchSaveDetails($ticket_id, array $post) {
    global $mysqli, $session_name;
    if (!isset($post['detail_client_id'])) {
        return [true, ''];  // the dialog did not show them
    }
    $row = ticketRow($ticket_id);
    if (!$row || (intval($row['ticket_client_id']) && !hasClientAccess($row['ticket_client_id']))) {
        return [false, 'That ticket no longer exists.'];
    }
    if (!empty($row['ticket_closed_at'])) {
        return [false, 'Ticket ' . ticketReference($row) . ' is closed.'];
    }
    $ticket_id = intval($row['ticket_id']);
    $reference = escapeSql(ticketReference($row));
    $old_client_id = intval($row['ticket_client_id']);
    $client_id = intval($post['detail_client_id']);
    $changed = [];

    // Client
    if ($client_id && $client_id !== $old_client_id) {
        $client_name = (string) getFieldById('clients', $client_id, 'client_name');
        if ($client_name === '' || !hasClientAccess($client_id)) {
            return [false, 'You do not have access to that client.'];
        }
        $vendor_id = ticketClientOwnedId('vendor', $row['ticket_vendor_id'], 0) ?: ticketClientOwnedId('vendor', $row['ticket_vendor_id'], $client_id);
        $project_id = ticketClientOwnedId('project', $row['ticket_project_id'], $client_id);
        mysqli_query($mysqli, "UPDATE ticket_replies SET ticket_reply_type = 'Internal' WHERE ticket_reply_ticket_id = $ticket_id AND ticket_reply_type = 'Public'");
        mysqli_query($mysqli, "UPDATE tickets SET ticket_client_id = $client_id, ticket_contact_id = 0, ticket_asset_id = 0, ticket_location_id = 0,
            ticket_vendor_id = $vendor_id, ticket_project_id = $project_id WHERE ticket_id = $ticket_id LIMIT 1");
        mysqli_query($mysqli, "DELETE FROM ticket_assets WHERE ticket_id = $ticket_id");
        $old_name = $old_client_id ? (string) $row['client_name'] : 'no client';
        logTicketHistory($ticket_id, "$session_name moved the ticket from " . escapeSql($old_name) . " to " . escapeSql($client_name));
        logAudit("Ticket", "Change", "$session_name moved ticket $reference from " . escapeSql($old_name) . " to " . escapeSql($client_name), $client_id, $ticket_id);
        $changed[] = "client $client_name";
        $row['ticket_contact_id'] = 0;
        $row['contact_name'] = '';
    } else {
        $client_id = $old_client_id;
    }

    // Contact - one of the client's, or none
    $contact_id = ticketClientOwnedId('contact', $post['detail_contact_id'] ?? 0, $client_id);
    if ($contact_id !== intval($row['ticket_contact_id'])) {
        mysqli_query($mysqli, "UPDATE tickets SET ticket_contact_id = $contact_id WHERE ticket_id = $ticket_id");
        $contact_name = $contact_id ? (string) getFieldById('contacts', $contact_id, 'contact_name') : 'No one';
        logTicketHistory($ticket_id, "$session_name changed the contact from " . escapeSql($row['contact_name'] ?: 'No one') . " to " . escapeSql($contact_name));
        $changed[] = "contact $contact_name";
    }

    // Category
    $category_id = ticketCategoryId($post['detail_category_id'] ?? 0);
    if ($category_id !== intval($row['ticket_category'])) {
        mysqli_query($mysqli, "UPDATE tickets SET ticket_category = $category_id WHERE ticket_id = $ticket_id");
        $category_name = $category_id ? (string) getFieldById('categories', $category_id, 'category_name') : 'none';
        logTicketHistory($ticket_id, "$session_name changed the category to " . escapeSql($category_name));
        $changed[] = "category $category_name";
    }

    // Priority (ticketChangePriority logs it and works the SLA out again)
    $priority = (string) ($post['detail_priority'] ?? $row['ticket_priority']);
    $priority_changed = in_array($priority, TICKET_PRIORITIES, true) && $priority !== $row['ticket_priority'];
    if ($priority_changed) {
        ticketChangePriority($ticket_id, $priority);
        $changed[] = "priority $priority";
    }

    // Status - ticketChangeStatus() keeps to the statuses an agent may set, logs it and keeps the SLA clock right
    $status_id = intval($post['detail_status'] ?? $row['ticket_status']);
    if ($status_id !== intval($row['ticket_status']) && isset(ticketPickableStatuses()[$status_id])) {
        [$status_ok] = ticketChangeStatus($ticket_id, $status_id);
        if ($status_ok) {
            $changed[] = 'status ' . ticketPickableStatuses()[$status_id]['ticket_status_name'];
        }
    }

    // SLA: the dialog starts on the ticket's own. "Automatic" works it out from the client and priority; a different
    // one pins it; left as it was, a new client works it out again (a new priority already did, above)
    $old_sla_id = intval($row['ticket_sla_id']);
    $sla = (string) ($post['detail_sla'] ?? $old_sla_id);
    if ($sla === 'auto') {
        applyTicketSla($ticket_id);
        $new_sla_id = intval(mysqli_fetch_row(mysqli_query($mysqli, "SELECT ticket_sla_id FROM tickets WHERE ticket_id = $ticket_id"))[0] ?? 0);
        if ($new_sla_id !== $old_sla_id) {
            $changed[] = 'SLA ' . ($new_sla_id ? (string) getFieldById('slas', $new_sla_id, 'sla_name') : 'None') . ' (automatic)';
        }
    } elseif (intval($sla) !== $old_sla_id) {
        $sla_id = intval($sla);
        $sla_name = 'None';
        if ($sla_id) {
            $sla_name = (string) (mysqli_fetch_row(mysqli_query($mysqli, "SELECT sla_name FROM slas WHERE sla_id = $sla_id AND (sla_archived_at IS NULL OR sla_id = $old_sla_id)"))[0] ?? '');
            if ($sla_name === '') {
                return [false, 'That SLA plan is not available.'];
            }
        }
        applyTicketSla($ticket_id, $sla_id);
        $old_sla_name = $old_sla_id ? (string) getFieldById('slas', $old_sla_id, 'sla_name') : 'None';
        logTicketHistory($ticket_id, "$session_name changed SLA from " . escapeSql($old_sla_name) . " to " . escapeSql($sla_name));
        $changed[] = "SLA $sla_name";
    } elseif ($client_id !== $old_client_id) {
        applyTicketSla($ticket_id);
    }

    if ($changed) {
        logAudit("Ticket", "Edit", "$session_name changed ticket $reference from Dispatch: " . escapeSql(implode(', ', $changed)), $client_id, $ticket_id);
        triggerCustomAction('ticket_update', $ticket_id);
    }
    return [true, $changed ? 'Updated ' . implode(', ', $changed) : ''];
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
