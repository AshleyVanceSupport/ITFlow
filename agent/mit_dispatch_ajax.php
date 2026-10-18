<?php

/*
 * Mountaineer IT - Dispatch's requests (functions/mit_dispatch.php), JSON:
 *   GET  ?queue=unassigned|unbooked [&client_id &priority &q]     the queue and both tab counts
 *   GET  ?items=1&from=Y-m-d&to=Y-m-d&techs=1,2                  the board's bookings
 *   GET  ?details=TICKET_ID                                      the ticket's client, contact, priority, category, SLA + choices
 *   GET  ?contacts=CLIENT_ID                                     a client's contacts
 *   GET  ?contact_tickets=CONTACT_ID&except=TICKET_ID            the contact's other open tickets
 *   GET  ?workload=1&techs=1,2&first_day=0|1                       each tech's open tickets, SLA and booked hours
 *   POST assign=1 ticket_id tech_id                               give an unbooked ticket to another agent
 *   POST merge=1 child_id parent_id comment move_replies         merge one ticket into another (ITFlow's merge)
 *   POST book=1 ticket_id tech_id start minutes onsite contact    assign and book (+ detail_* - saved first)
 *   POST unbook=1 ticket_id                                       take the booking off
 *   POST state=1 view (day | week | workload) hidden weekends                             the board settings for this agent
 */

require_once "../config.php";
require_once "../functions.php";
require_once "../includes/check_login.php";

header('Content-Type: application/json');

$mit_fail = function ($message, $code = 400) {
    http_response_code($code);
    echo json_encode(['error' => $message]);
    exit;
};

if (!mitDispatchCan()) {
    $mit_fail('Dispatching needs Support edit access.', 403);
}

if (isset($_GET['queue'])) {
    echo json_encode(mitDispatchQueue((string) $_GET['queue'], $_GET));
    exit;
}

if (isset($_GET['items'])) {
    $from = calendarIsDate($_GET['from'] ?? '') ? $_GET['from'] : date('Y-m-d');
    $to = calendarIsDate($_GET['to'] ?? '') ? $_GET['to'] : $from;
    if ($to < $from || strtotime($to) - strtotime($from) > 86400 * 42) {
        $to = $from;
    }
    echo json_encode(mitDispatchItems($from, $to, explode(',', (string) ($_GET['techs'] ?? ''))));
    exit;
}

if (isset($_GET['details'])) {
    $details = mitDispatchDetails($_GET['details']);
    if (!$details) {
        $mit_fail('That ticket no longer exists.', 404);
    }
    echo json_encode($details);
    exit;
}

if (isset($_GET['contacts'])) {
    echo json_encode(mitDispatchContacts($_GET['contacts']));
    exit;
}

if (isset($_GET['contact_tickets'])) {
    echo json_encode(mitDispatchContactTickets($_GET['contact_tickets'], $_GET['except'] ?? 0));
    exit;
}

if (isset($_GET['workload'])) {
    echo json_encode(mitDispatchWorkload(explode(',', (string) ($_GET['techs'] ?? '')), intval($_GET['first_day'] ?? 0) === 1 ? 1 : 0));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCSRFToken();

    if (isset($_POST['merge'])) {
        [$ok, $message] = mitDispatchMerge($_POST['child_id'] ?? 0, $_POST['parent_id'] ?? 0, $_POST['comment'] ?? '', !empty($_POST['move_replies']));
        if (!$ok) {
            $mit_fail($message);
        }
        echo json_encode(['ok' => true, 'message' => $message]);
        exit;
    }

    if (isset($_POST['book'])) {
        // The details first, so the booking emails go to the right contact
        [$ok, $details_message] = mitDispatchSaveDetails($_POST['ticket_id'] ?? 0, $_POST);
        if (!$ok) {
            $mit_fail($details_message);
        }
        [$ok, $message, $conflicts] = mitDispatchBook($_POST['ticket_id'] ?? 0, $_POST['tech_id'] ?? 0, (string) ($_POST['start'] ?? ''),
            $_POST['minutes'] ?? CALENDAR_TICKET_MINUTES, !empty($_POST['onsite']), !empty($_POST['contact']));
        if (!$ok) {
            $mit_fail($message);
        }
        echo json_encode(['ok' => true, 'message' => $message . ($details_message !== '' ? ". $details_message" : ''), 'conflicts' => $conflicts]);
        exit;
    }

    if (isset($_POST['assign'])) {
        [$ok, $message] = mitDispatchAssign($_POST['ticket_id'] ?? 0, $_POST['tech_id'] ?? 0);
        if (!$ok) {
            $mit_fail($message);
        }
        echo json_encode(['ok' => true, 'message' => $message]);
        exit;
    }

    if (isset($_POST['unbook'])) {
        [$ok, $message] = mitDispatchUnbook($_POST['ticket_id'] ?? 0);
        if (!$ok) {
            $mit_fail($message);
        }
        echo json_encode(['ok' => true, 'message' => $message]);
        exit;
    }

    if (isset($_POST['state'])) {
        $hidden = array_values(array_filter(array_map('intval', explode(',', (string) ($_POST['hidden'] ?? '')))));
        mitSetUserPreference($session_user_id, 'dispatch.state', json_encode([
            'view' => in_array($_POST['view'] ?? '', ['week', 'workload'], true) ? $_POST['view'] : 'day',
            'hidden' => array_slice($hidden, 0, 500),
            'weekends' => !empty($_POST['weekends']),
        ]));
        echo json_encode(['ok' => true]);
        exit;
    }
}

$mit_fail('Unknown request.');
