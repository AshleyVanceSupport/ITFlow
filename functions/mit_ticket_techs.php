<?php

/*
 * Mountaineer IT - additional techs on a ticket (database update M008, table mit_ticket_techs).
 *
 * The ticket's assigned tech stays the lead (tickets.ticket_assigned_to - everything ITFlow does
 * with it is untouched). Other techs can be added to help. Each one is booked at the same time as
 * the lead (follows_lead - moves and cancels with the lead's booking), at their own time, or not
 * booked at all. A helper sees the ticket in their own lists, on their calendar and in their
 * Outlook, is told when a client replies, and gets their own booking emails. The customer's
 * emails stay about the lead's booking only.
 *
 * A helper row for whoever is now the lead is ignored everywhere (they are the lead), and cleared
 * by mitTicketTechsTidy().
 */

/** Is the table there yet? (The code can be updated before the database update runs.) */
function mitTicketTechsReady() {
    global $mysqli;
    static $ready = null;
    if ($ready === null) {
        try {
            $ready = mysqli_num_rows(mysqli_query($mysqli, "SHOW TABLES LIKE 'mit_ticket_techs'")) === 1;
        } catch (Throwable $e) {
            $ready = false;
        }
    }
    return $ready;
}

/**
 * A helper who has since become the ticket's lead is no longer a helper. ITFlow changes the lead in
 * several places (the assignee picker, Edit ticket, bulk assign, the API), so instead of a hook in
 * each, those rows are cleared whenever helpers are read - once per request.
 */
function mitTicketTechsTidy() {
    global $mysqli;
    static $done = false;
    if (!$done && mitTicketTechsReady()) {
        $done = true;
        mysqli_query($mysqli, "DELETE tt FROM mit_ticket_techs tt JOIN tickets ON tickets.ticket_id = tt.ticket_id WHERE tt.user_id = tickets.ticket_assigned_to");
    }
}

// A helper's booking: the lead's when they follow the lead, else their own. Alias tt = mit_ticket_techs.
const MIT_HELPER_START_SQL = "IF(tt.follows_lead = 1, tickets.ticket_schedule, tt.schedule_start)";
const MIT_HELPER_END_SQL = "IF(tt.follows_lead = 1, tickets.ticket_schedule_end, tt.schedule_end)";

/**
 * "Assigned to this tech" for the ticket lists: the lead, or one of the helpers.
 * $id_column is how the query names the ticket's id.
 */
function mitAssignedSql($user_id, $id_column = 'tickets.ticket_id') {
    $user_id = intval($user_id);
    if (!mitTicketTechsReady()) {
        return "ticket_assigned_to = $user_id";
    }
    return "(ticket_assigned_to = $user_id OR $id_column IN (SELECT tt.ticket_id FROM mit_ticket_techs tt WHERE tt.user_id = $user_id))";
}

/** A ticket's helpers (not the lead), with their booking: start / end ('' when not booked). */
function mitTicketTechs($ticket_id) {
    global $mysqli;
    $ticket_id = intval($ticket_id);
    if (!$ticket_id || !mitTicketTechsReady()) {
        return [];
    }
    mitTicketTechsTidy();
    $result = mysqli_query($mysqli, "SELECT tt.*, user_name, user_email, user_avatar,
            " . MIT_HELPER_START_SQL . " AS start, " . MIT_HELPER_END_SQL . " AS end
        FROM mit_ticket_techs tt
        JOIN tickets ON tickets.ticket_id = tt.ticket_id
        JOIN users ON users.user_id = tt.user_id
        WHERE tt.ticket_id = $ticket_id AND tt.user_id <> tickets.ticket_assigned_to AND users.user_archived_at IS NULL
        ORDER BY user_name");
    $techs = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $row['end'] = $row['start'] ? mitTicketScheduleEnd($row['start'], $row['end']) : '';
        $row['start'] = (string) $row['start'];
        $techs[intval($row['user_id'])] = $row;
    }
    return $techs;
}

/** The helpers of every ticket, ticket_id => [user_id => user_name] - for the ticket list. */
function mitTicketTechNames() {
    global $mysqli;
    static $names = null;
    if ($names === null) {
        $names = [];
        if (mitTicketTechsReady()) {
            mitTicketTechsTidy();
            $result = mysqli_query($mysqli, "SELECT tt.ticket_id, tt.user_id, user_name FROM mit_ticket_techs tt
                JOIN tickets ON tickets.ticket_id = tt.ticket_id
                JOIN users ON users.user_id = tt.user_id
                WHERE tt.user_id <> tickets.ticket_assigned_to AND users.user_archived_at IS NULL
                ORDER BY user_name");
            while ($row = mysqli_fetch_assoc($result)) {
                $names[intval($row['ticket_id'])][intval($row['user_id'])] = $row['user_name'];
            }
        }
    }
    return $names;
}

/** What a helper's booking says: "Same time as the lead", a time, or "Not booked". */
function mitTicketTechWhenText(array $tech) {
    if (intval($tech['follows_lead']) === 1) {
        return $tech['start'] !== '' ? 'Same time as the lead' : 'With the lead - not booked yet';
    }
    return $tech['start'] !== '' ? mitScheduleShortText($tech['start'], $tech['end']) : 'Not booked';
}

/*
 * ---------- the ticket page ----------
 */

/** The ticket page's "Additional techs" line (agent/ticket.php, under Assigned to). */
function mitTicketTechsProp(array $ticket, $can_change) {
    if (!mitTicketTechsReady()) {
        return '';
    }
    $ticket_id = intval($ticket['ticket_id']);
    $techs = mitTicketTechs($ticket_id);
    if (!$techs && !$can_change) {
        return '';
    }
    $html = '<div class="ticket-prop"><dt>Additional techs</dt><dd>';
    foreach ($techs as $user_id => $tech) {
        $value = '<span><span class="d-inline-flex align-items-center gap-2"><span class="rounded-circle flex-shrink-0" style="width:.7rem;height:.7rem;background:' . mitAgentColor($user_id) . '"></span>'
            . escapeHtml($tech['user_name']) . '</span><span class="ticket-prop-sub">' . escapeHtml(mitTicketTechWhenText($tech)) . '</span></span>';
        if ($can_change) {
            $html .= '<a href="#" class="ticket-prop-edit ajax-modal" data-modal-url="modals/ticket/mit_ticket_tech.php?ticket_id=' . $ticket_id . '&user_id=' . $user_id . '" title="Change ' . escapeHtml($tech['user_name']) . '\'s booking">'
                . $value . '<i class="fas fa-pen ticket-prop-edit-icon"></i></a>';
        } else {
            $html .= '<span class="ticket-prop-static">' . $value . '</span>';
        }
    }
    if ($can_change && ticketIsOpen($ticket)) {
        $html .= '<a href="#" class="ticket-prop-edit ajax-modal small" data-modal-url="modals/ticket/mit_ticket_tech.php?ticket_id=' . $ticket_id . '" title="Add a tech to help">'
            . '<span class="' . ($techs ? 'text-body-secondary' : 'text-muted') . '"><i class="fas fa-user-plus me-1"></i>' . ($techs ? 'Add another' : 'Add a tech') . '</span><i class="fas fa-plus ticket-prop-edit-icon"></i></a>';
    } elseif (!$techs) {
        $html .= '<span class="ticket-prop-static text-muted">None</span>';
    }
    return $html . '</dd></div>';
}

/** The ticket list's assignee cell: "+2" after the lead, highlighted when you are one of them. */
function mitTicketTechsListBadge($ticket_id) {
    global $session_user_id;
    $names = mitTicketTechNames()[intval($ticket_id)] ?? [];
    if (!$names) {
        return '';
    }
    $mine = isset($names[intval($session_user_id)]);
    return ' <span class="badge rounded-pill ' . ($mine ? 'text-bg-primary' : 'text-bg-light border') . ' align-middle" title="' . escapeHtml(($mine ? 'You are helping. ' : '') . 'Also on it: ' . implode(', ', $names)) . '">'
        . ($mine ? '<i class="fas fa-user-friends me-1"></i>Helping' : '+' . count($names)) . '</span>';
}

/*
 * ---------- saving ----------
 */

/**
 * Add a helper, or change their booking. $mode: 'lead' (same time as the lead), 'own' ($start - $end)
 * or 'none'. Emails the helper their booking (or its cancellation), notes it on the ticket.
 * Returns [ok, message, conflicts].
 */
function mitTicketTechSave(array $ticket, $user_id, $mode, $start = '', $end = '') {
    global $mysqli, $session_user_id, $session_name;
    $who = stripslashes((string) $session_name); // it is kept SQL-escaped
    $ticket_id = intval($ticket['ticket_id']);
    $user_id = intval($user_id);
    if (!mitTicketTechsReady()) {
        return [false, 'The database update for additional techs has not run yet.', []];
    }
    $tech = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT user_id, user_name FROM users WHERE user_id = $user_id AND user_type = 1 AND user_status = 1 AND user_archived_at IS NULL"));
    if (!$tech) {
        return [false, 'Pick a tech.', []];
    }
    if ($user_id === intval($ticket['ticket_assigned_to'])) {
        return [false, escapeHtml($tech['user_name']) . ' is the lead tech on this ticket.', []];
    }
    $mode = in_array($mode, ['lead', 'own', 'none'], true) ? $mode : 'lead';
    if ($mode === 'own' && ($start === '' || $end === '' || $end <= $start)) {
        return [false, 'Pick the date and time for ' . escapeHtml($tech['user_name']) . '.', []];
    }

    $before = mitTicketTechs($ticket_id)[$user_id] ?? null;
    $follows = $mode === 'lead' ? 1 : 0;
    $start_sql = $mode === 'own' ? "'" . escapeSql($start) . "'" : 'NULL';
    $end_sql = $mode === 'own' ? "'" . escapeSql($end) . "'" : 'NULL';
    mysqli_query($mysqli, "INSERT INTO mit_ticket_techs SET ticket_id = $ticket_id, user_id = $user_id, follows_lead = $follows,
            schedule_start = $start_sql, schedule_end = $end_sql, added_by = " . intval($session_user_id) . "
        ON DUPLICATE KEY UPDATE follows_lead = $follows, schedule_start = $start_sql, schedule_end = $end_sql");
    $after = mitTicketTechs($ticket_id)[$user_id] ?? null;

    $name = $tech['user_name'];
    $when = $after && $after['start'] !== '' ? mitScheduleWhenText($after['start'], $after['end']) : '';
    $conflicts = $when !== '' ? mitScheduleConflicts($user_id, $after['start'], $after['end'], $ticket_id) : [];

    // Tell them: their booking (new or moved), or its cancellation, or just that they are on it
    $was_booked = $before && $before['start'] !== '';
    $changed = !$before || $before['start'] !== ($after['start'] ?? '') || $before['end'] !== ($after['end'] ?? '');
    if ($when !== '' && $changed) {
        mitTicketTechMail($ticket_id, $user_id, $after['start'], $after['end'], 'booked');
    } elseif ($when === '' && $was_booked) {
        mitTicketTechMail($ticket_id, $user_id, $before['start'], $before['end'], 'cancelled');
    } elseif (!$before) {
        mitTicketTechMail($ticket_id, $user_id, '', '', 'added');
    }

    $what = $mode === 'lead' ? 'at the same time as the lead' . ($when !== '' ? " ($when)" : '') : ($mode === 'own' ? "for $when" : 'not booked');
    $note = $before ? "$name's booking changed - $what." : "$name added as an additional tech - $what.";
    mysqli_query($mysqli, "INSERT INTO ticket_replies SET ticket_reply = '" . escapeSql($note) . "', ticket_reply_type = 'Internal', ticket_reply_time_worked = '00:00:00', ticket_reply_by = " . intval($session_user_id) . ", ticket_reply_ticket_id = $ticket_id");
    logTicketHistory($ticket_id, escapeSql("$who " . ($before ? "changed $name's booking - $what" : "added $name as an additional tech - $what")));
    logAudit("Ticket", "Edit", escapeSql("$who " . ($before ? "changed $name's booking on " : "added $name to ") . ticketReference($ticket)), intval($ticket['ticket_client_id']), $ticket_id);

    // Their app notification, when someone else added them
    if (!$before && $user_id !== intval($session_user_id)) {
        $client_uri = intval($ticket['ticket_client_id']) ? '&client_id=' . intval($ticket['ticket_client_id']) : '';
        mysqli_query($mysqli, "INSERT INTO notifications SET notification_type = 'Ticket', notification = '" . escapeSql('Ticket ' . ticketReference($ticket) . ' - ' . $ticket['ticket_subject'] . " - $who added you to help") . "',
            notification_action = '/agent/ticket.php?ticket_id=$ticket_id$client_uri', notification_client_id = " . intval($ticket['ticket_client_id']) . ", notification_user_id = $user_id");
    }

    return [true, $before ? "$name's booking is saved" : "$name added to the ticket", $conflicts];
}

/** Take a helper off a ticket - their booking is cancelled. */
function mitTicketTechRemove(array $ticket, $user_id) {
    global $mysqli, $session_user_id, $session_name;
    $who = stripslashes((string) $session_name); // it is kept SQL-escaped
    $ticket_id = intval($ticket['ticket_id']);
    $user_id = intval($user_id);
    $tech = mitTicketTechs($ticket_id)[$user_id] ?? null;
    if (!$tech) {
        return false;
    }
    mysqli_query($mysqli, "DELETE FROM mit_ticket_techs WHERE ticket_id = $ticket_id AND user_id = $user_id");
    if ($tech['start'] !== '') {
        mitTicketTechMail($ticket_id, $user_id, $tech['start'], $tech['end'], 'cancelled');
    }
    $name = $tech['user_name'];
    mysqli_query($mysqli, "INSERT INTO ticket_replies SET ticket_reply = '" . escapeSql("$name removed from the ticket.") . "', ticket_reply_type = 'Internal', ticket_reply_time_worked = '00:00:00', ticket_reply_by = " . intval($session_user_id) . ", ticket_reply_ticket_id = $ticket_id");
    logTicketHistory($ticket_id, escapeSql("$who removed $name from the ticket"));
    logAudit("Ticket", "Edit", escapeSql("$who removed $name from " . ticketReference($ticket)), intval($ticket['ticket_client_id']), $ticket_id);
    return $name;
}

/*
 * ---------- the lead's booking moved or cancelled (hooks in functions/ticket.php) ----------
 */

/** After ticketSetSchedule(): helpers who follow the lead get the new time. Returns their clashes. */
function mitTicketTechsLeadMoved($ticket_id) {
    $conflicts = [];
    foreach (mitTicketTechs($ticket_id) as $user_id => $tech) {
        if (intval($tech['follows_lead']) === 1 && $tech['start'] !== '') {
            mitTicketTechMail($ticket_id, $user_id, $tech['start'], $tech['end'], 'booked');
            foreach (mitScheduleConflicts($user_id, $tech['start'], $tech['end'], $ticket_id) as $line) {
                $conflicts[] = $tech['user_name'] . ': ' . $line;
            }
        }
    }
    return $conflicts;
}

/** After ticketCancelSchedule(): helpers who follow the lead get the cancellation. */
function mitTicketTechsLeadCancelled($ticket_id, $old_start) {
    foreach (mitTicketTechs($ticket_id) as $user_id => $tech) {
        if (intval($tech['follows_lead']) === 1 && $old_start) {
            mitTicketTechMail($ticket_id, $user_id, $old_start, '', 'cancelled');
        }
    }
}

/*
 * ---------- emails ----------
 */

/** The calendar-invite UID of a helper's booking - its own, so it never replaces the lead's. */
function mitTicketTechUid($ticket_id, $user_id) {
    return 'ticket-' . intval($ticket_id) . '-tech-' . intval($user_id) . '@' . ($_SERVER['SERVER_NAME'] ?? 'itflow');
}

/** Email a helper: $kind 'booked' (with the invite), 'cancelled' (its cancellation) or 'added'. */
function mitTicketTechMail($ticket_id, $user_id, $start, $end, $kind) {
    global $mysqli, $config_smtp_provider, $config_ticket_from_email, $config_ticket_from_name, $config_base_url, $session_company_name;
    if (empty($config_smtp_provider)) {
        return;
    }
    $ticket_id = intval($ticket_id);
    $user_id = intval($user_id);
    $row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT tickets.ticket_id, ticket_prefix, ticket_number, ticket_subject, ticket_details, ticket_client_id, ticket_onsite,
            client_name, location_address, lead.user_name AS lead_name
        FROM tickets
        LEFT JOIN clients ON ticket_client_id = client_id
        LEFT JOIN contacts ON ticket_contact_id = contact_id
        LEFT JOIN locations ON location_id = IF(ticket_location_id > 0, ticket_location_id, contact_location_id)
        LEFT JOIN users lead ON lead.user_id = ticket_assigned_to
        WHERE tickets.ticket_id = $ticket_id"));
    $tech = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT user_name, user_email FROM users WHERE user_id = $user_id"));
    if (!$row || !$tech || !filter_var($tech['user_email'], FILTER_VALIDATE_EMAIL)) {
        return;
    }
    $html = function ($value) {
        return escapeHtml((string) $value);
    };
    $reference = ticketReference($row);
    $client_id = intval($row['ticket_client_id']);
    $link = "https://$config_base_url/agent/ticket.php?ticket_id=$ticket_id" . ($client_id ? "&client_id=$client_id" : '');
    $about = 'the ticket regarding ' . $html($row['ticket_subject']) . ($row['client_name'] ? ' (' . $html($row['client_name']) . ')' : '');
    $lead = $row['lead_name'] ? ' The lead tech is ' . $html($row['lead_name']) . '.' : '';

    $cal_str = '';
    if ($kind === 'booked') {
        $when = mitScheduleWhenText($start, $end);
        $what = "You are booked to help on $about for " . $html($when) . " (" . (intval($row['ticket_onsite']) ? 'onsite' : 'remote') . ").$lead";
        $subject = "Ticket Scheduled - [$reference] - " . $row['ticket_subject'];
        $cal_subject = intval($row['ticket_number']) . ": " . $row['client_name'] . " - " . $row['ticket_subject'];
        $cal_str = createiCalStr($start, $cal_subject, mb_substr(strip_tags((string) $row['ticket_details']), 0, 100) . " - $link", (string) $row['location_address'], mitTicketTechUid($ticket_id, $user_id), $end);
    } elseif ($kind === 'cancelled') {
        $what = "Your booking to help on $about has been cancelled.";
        $subject = "Ticket Schedule Cancelled - [$reference] - " . $row['ticket_subject'];
        $cal_str = createiCalStrCancel($start, intval($row['ticket_number']) . ": " . $row['client_name'] . " - " . $row['ticket_subject'], mitTicketTechUid($ticket_id, $user_id));
    } else {
        $what = "You have been added to help on $about. It is not booked on your calendar.$lead";
        $subject = "Ticket - [$reference] - " . $row['ticket_subject'];
    }
    // A tech whose Outlook is synced gets the booking there - no second copy from an invite
    if ($cal_str !== '' && function_exists('mitOutlookSyncsEmail') && mitOutlookSyncsEmail($tech['user_email'])) {
        $cal_str = '';
    }
    $body = 'Hello, ' . $html($tech['user_name']) . "<br><br>$what<br><br>--------------------------------<br><a href=\"$link\">" . $html($reference) . "</a><br>--------------------------------<br><br>Please do not reply to this email.<br><br>~<br>"
        . $html($session_company_name) . "<br>Support Department<br>" . $html($config_ticket_from_email);
    $esc = function ($value) use ($mysqli) {
        return mysqli_real_escape_string($mysqli, (string) $value);
    };
    addToMailQueue([[
        'from' => $esc($config_ticket_from_email), 'from_name' => $esc($config_ticket_from_name),
        'recipient' => $esc($tech['user_email']), 'recipient_name' => $esc($tech['user_name']),
        'subject' => $esc($subject), 'body' => $esc($body), 'cal_str' => $cal_str,
    ]]);
}

/**
 * A client replied (cron/ticket_email_parser.php, client/post.php): the same email the lead was
 * queued, for each helper. $mail is the lead's queued row, already escaped for the queue.
 */
function mitTicketTechsCopyMail($ticket_id, array $mail) {
    $copies = [];
    foreach (mitTicketTechs($ticket_id) as $tech) {
        if (!filter_var($tech['user_email'], FILTER_VALIDATE_EMAIL) || strcasecmp($tech['user_email'], stripslashes((string) $mail['recipient'])) === 0) {
            continue;
        }
        $name = escapeSql($tech['user_name']);
        $copies[] = ['recipient' => escapeSql($tech['user_email']), 'recipient_name' => $name,
            'body' => str_replace('Hello ' . $mail['recipient_name'] . ',', "Hello $name,", (string) $mail['body'])] + $mail;
    }
    if ($copies) {
        addToMailQueue($copies);
    }
}

/*
 * ---------- calendar, clashes, Outlook ----------
 */

/** Helpers' bookings for the ITFlow calendar (functions/calendar.php, the tickets source). */
function mitTicketTechCalendarItems($from, $to, $client_id, $user_id, $can_move) {
    global $mysqli;
    if (!mitTicketTechsReady()) {
        return [];
    }
    $from = escapeSql($from);
    $to = escapeSql($to);
    $client_id = intval($client_id);
    $user_id = intval($user_id);
    $start = MIT_HELPER_START_SQL;
    $sql = mysqli_query($mysqli, "SELECT tickets.ticket_id, ticket_prefix, ticket_number, ticket_subject, ticket_onsite, ticket_client_id,
            ticket_resolved_at, ticket_closed_at, client_name, ticket_status_name, tt.user_id, tt.follows_lead, helper.user_name, lead.user_name AS lead_name,
            $start AS start, " . MIT_HELPER_END_SQL . " AS end
        FROM mit_ticket_techs tt
        JOIN tickets ON tickets.ticket_id = tt.ticket_id
        JOIN users helper ON helper.user_id = tt.user_id
        LEFT JOIN users lead ON lead.user_id = ticket_assigned_to
        LEFT JOIN clients ON client_id = ticket_client_id
        LEFT JOIN ticket_statuses ON ticket_status_id = ticket_status
        WHERE $start BETWEEN '$from' AND '$to' AND ticket_archived_at IS NULL AND tt.user_id <> ticket_assigned_to
        AND helper.user_archived_at IS NULL
        " . ($client_id ? "AND ticket_client_id = $client_id" : '') . "
        " . ($user_id ? "AND tt.user_id = $user_id" : '') . "
        " . clientScopeSql('ticket_client_id'));
    $items = [];
    while ($row = mysqli_fetch_assoc($sql)) {
        $open = ticketIsOpen($row);
        $ticket_id = intval($row['ticket_id']);
        $items[] = [
            'id' => "helper-$ticket_id-" . intval($row['user_id']),
            'title' => calendarTicketTitle($row, !$client_id) . ' (helping)',
            'start' => $row['start'],
            'end' => mitTicketScheduleEnd($row['start'], $row['end']),
            'allDay' => false,
            'editable' => $can_move && $open,
            'durationEditable' => $can_move && $open,
            'color' => mitAgentColor($row['user_id']),
            'className' => $open ? '' : 'itflow-cal-done',
            'extendedProps' => ['kind' => 'helper', 'ticket_id' => $ticket_id, 'user_id' => intval($row['user_id']), 'follows_lead' => intval($row['follows_lead']),
                'client_id' => intval($row['ticket_client_id']),
                'icon' => intval($row['ticket_onsite']) ? 'fa-car' : 'fa-headset',
                'modal' => "modals/ticket/ticket.php?id=$ticket_id",
                'detail' => implode(' · ', array_filter([$row['user_name'] . ' helping' . ($row['lead_name'] ? ' ' . $row['lead_name'] : ''), $row['ticket_status_name']]))],
        ];
    }
    return $items;
}

/** Helpers' bookings for a person's calendar feed (functions/calendar.php calendarUserFeedItems). */
function mitTicketTechFeedItems(array $feed, $from, $to) {
    global $mysqli;
    if (!mitTicketTechsReady() || empty($feed['feed_tickets']) || lookupUserPermission('module_support') < 1) {
        return [];
    }
    $user_id = intval($feed['feed_user_id']);
    $everyone = ($feed['feed_scope'] ?? 'mine') === 'everyone';
    $host = calendarHost();
    $from = escapeSql($from);
    $to = escapeSql($to);
    $start = MIT_HELPER_START_SQL;
    $sql = mysqli_query($mysqli, "SELECT tickets.ticket_id, ticket_prefix, ticket_number, ticket_subject, ticket_onsite, ticket_client_id,
            ticket_created_at, ticket_updated_at, ticket_resolved_at, ticket_closed_at, client_name, tt.user_id, helper.user_name,
            $start AS start, " . MIT_HELPER_END_SQL . " AS end
        FROM mit_ticket_techs tt
        JOIN tickets ON tickets.ticket_id = tt.ticket_id
        JOIN users helper ON helper.user_id = tt.user_id
        LEFT JOIN clients ON client_id = ticket_client_id
        WHERE $start BETWEEN '$from' AND '$to' AND ticket_archived_at IS NULL AND tt.user_id <> ticket_assigned_to
        " . ($everyone ? '' : "AND tt.user_id = $user_id") . "
        " . clientScopeSql('ticket_client_id'));
    $items = [];
    while ($row = mysqli_fetch_assoc($sql)) {
        $ticket_id = intval($row['ticket_id']);
        $link = "https://$host/agent/" . ticketUrl($ticket_id, $row['ticket_client_id']);
        $items[] = [
            'uid' => "itflow-ticket-$ticket_id-tech-" . intval($row['user_id']) . "@$host",
            'start' => $row['start'],
            'end' => mitTicketScheduleEnd($row['start'], $row['end']),
            'summary' => calendarTicketTitle($row) . ' (' . ($everyone ? $row['user_name'] . ' ' : '') . 'helping)' . (ticketIsOpen($row) ? '' : ' [done]'),
            'location' => '',
            'description' => implode("\n", array_filter([$row['client_name'] ? 'Client: ' . $row['client_name'] : '', 'Helping: ' . $row['user_name'], $link])),
            'url' => $link,
            'stamp' => $row['ticket_updated_at'] ?: $row['ticket_created_at'],
            'categories' => intval($row['ticket_onsite']) ? 'Onsite' : 'Remote',
            'busy' => !empty($feed['feed_busy_only']),
        ];
    }
    return $items;
}

/** A tech's open helper bookings that overlap $start - $end (for mitScheduleConflicts), as text lines. */
function mitTicketTechConflicts($user_id, $start, $end, $ticket_id = 0) {
    global $mysqli;
    if (!mitTicketTechsReady()) {
        return [];
    }
    $user_id = intval($user_id);
    $ticket_id = intval($ticket_id);
    $start = escapeSql($start);
    $end = escapeSql($end);
    $default_minutes = intval(CALENDAR_TICKET_MINUTES);
    $s = MIT_HELPER_START_SQL;
    $e = MIT_HELPER_END_SQL;
    $sql = mysqli_query($mysqli, "SELECT ticket_prefix, ticket_number, ticket_subject, $s AS start, $e AS end FROM mit_ticket_techs tt
        JOIN tickets ON tickets.ticket_id = tt.ticket_id
        WHERE tt.user_id = $user_id AND tt.ticket_id <> $ticket_id AND tt.user_id <> ticket_assigned_to
        AND ticket_resolved_at IS NULL AND ticket_closed_at IS NULL AND ticket_archived_at IS NULL
        AND $s IS NOT NULL AND $s < '$end'
        AND COALESCE($e, DATE_ADD($s, INTERVAL $default_minutes MINUTE)) > '$start'
        ORDER BY start");
    $lines = [];
    while ($row = mysqli_fetch_assoc($sql)) {
        $row_end = mitTicketScheduleEnd($row['start'], $row['end']);
        $lines[] = ticketReference($row) . ' - ' . $row['ticket_subject'] . ' (helping) @ ' . date('g:ia', strtotime($row['start'])) . '-' . date('g:ia', strtotime($row_end));
    }
    return $lines;
}

/** A tech's open helper bookings for their Outlook (functions/mit_outlook.php), ticket_id => row like the lead's. */
function mitTicketTechOutlookBookings($user_id, $past_days) {
    global $mysqli;
    if (!mitTicketTechsReady()) {
        return [];
    }
    mitTicketTechsTidy();
    $user_id = intval($user_id);
    $default_minutes = intval(CALENDAR_TICKET_MINUTES);
    $past_days = intval($past_days);
    $s = MIT_HELPER_START_SQL;
    $e = MIT_HELPER_END_SQL;
    $result = mysqli_query($mysqli, "SELECT tickets.ticket_id, ticket_prefix, ticket_number, ticket_subject, $s AS ticket_schedule, $e AS ticket_schedule_end, ticket_onsite,
            client_name, contact_name, contact_phone, contact_mobile, location_name, location_address, location_city, location_state, location_zip
        FROM mit_ticket_techs tt
        JOIN tickets ON tickets.ticket_id = tt.ticket_id
        LEFT JOIN clients ON client_id = ticket_client_id
        LEFT JOIN contacts ON contact_id = ticket_contact_id
        LEFT JOIN locations ON location_id = ticket_location_id
        WHERE tt.user_id = $user_id AND tt.user_id <> ticket_assigned_to AND $s IS NOT NULL
        AND ticket_resolved_at IS NULL AND ticket_closed_at IS NULL AND ticket_archived_at IS NULL
        AND COALESCE($e, DATE_ADD($s, INTERVAL $default_minutes MINUTE)) >= DATE_SUB(NOW(), INTERVAL $past_days DAY)
        ORDER BY ticket_schedule");
    $bookings = [];
    while ($result && $row = mysqli_fetch_assoc($result)) {
        $bookings[intval($row['ticket_id'])] = $row;
    }
    return $bookings;
}

/**
 * A helper's booking dragged on the calendar (agent/ajax.php calendar_move, kind 'helper'):
 * it becomes their own time. Answers in JSON and stops.
 */
function mitTicketTechCalendarMove(array $post) {
    $fail = function ($message, $code = 400) {
        http_response_code($code);
        echo json_encode(['error' => $message]);
        exit;
    };
    if (!calendarCanScheduleTickets()) {
        $fail('Your role cannot schedule tickets.', 403);
    }
    $row = ticketRow($post['id'] ?? 0);
    if (!$row || !empty($row['ticket_archived_at'])) {
        $fail('That ticket no longer exists.', 404);
    }
    if (intval($row['ticket_client_id']) && !hasClientAccess($row['ticket_client_id'])) {
        $fail('You do not have access to that client.', 403);
    }
    if (!ticketIsOpen($row)) {
        $fail('Ticket ' . ticketReference($row) . ' is resolved or closed - reopen it to schedule work.');
    }
    $user_id = intval($post['user_id'] ?? 0);
    $tech = mitTicketTechs($row['ticket_id'])[$user_id] ?? null;
    if (!$tech) {
        $fail('That tech is no longer on the ticket.', 404);
    }
    $start = ticketScheduleValue($post['start'] ?? '');
    if ($start === '') {
        $fail('That is not a date and time.');
    }
    if (!empty($post['all_day'])) {
        $start = substr($start, 0, 10) . ' ' . ($tech['start'] !== '' ? date('H:i:s', strtotime($tech['start'])) : '09:00:00');
    }
    $end = ticketScheduleValue($post['end'] ?? '');
    if ($end === '' || $end <= $start || !empty($post['all_day'])) {
        $length = $tech['start'] !== '' ? mitTicketScheduleMinutes($tech['start'], $tech['end']) : CALENDAR_TICKET_MINUTES;
        $end = date('Y-m-d H:i:s', strtotime($start) + $length * 60);
    }
    [$ok, $message, $conflicts] = mitTicketTechSave($row, $user_id, 'own', $start, $end);
    if (!$ok) {
        $fail(strip_tags($message));
    }
    echo json_encode(['ok' => true, 'message' => $tech['user_name'] . ' booked on ' . ticketReference($row) . ' for ' . mitScheduleWhenText($start, $end), 'conflicts' => $conflicts]);
    exit;
}
