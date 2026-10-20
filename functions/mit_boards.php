<?php

/*
 * Mountaineer IT - service boards: who works a ticket (Service Desk, Tier 1, Tier 2, Sysadmin, Sales ...), apart from
 * its category, which keeps saying what the ticket is about. Boards only organise - who can see a ticket still goes by
 * client access, as everywhere else.
 *
 * Tables (M026): mit_boards, mit_board_members (who works a board, and who is told about new and moved tickets),
 * mit_ticket_boards (a ticket's board - no row = the default board, so tickets from before boards and stock ITFlow need
 * nothing), mit_board_rules (routing: the address an email was sent to, the client, the category).
 *
 * A new ticket gets its board from the first rule that matches - mailbox, then client, then category - else the default
 * board. The New Ticket form can pick one, the email parser passes the addresses (mitBoardRoute), and the cron job
 * mit_boards (mitBoardSweep) places tickets made the other ways (portal, API, recurring, projects) within a minute.
 * Moving and escalating: mitBoardMove (ticket history, optional internal note, members told).
 * Pages: Admin > Settings > Boards (admin/mit_boards.php), the Tickets switcher, the ticket's Board line and Escalate,
 * Dispatch's board picker (functions/mit_dispatch.php).
 */

const MIT_BOARD_RULE_KINDS = ['mailbox' => 'Sent to', 'client' => 'Client', 'category' => 'Category'];
const MIT_BOARD_ICONS = ['inbox', 'headset', 'layer-group', 'server', 'handshake', 'columns', 'tools', 'network-wired', 'shield-alt',
    'cloud', 'laptop', 'user-tie', 'star', 'bolt', 'life-ring', 'file-invoice-dollar'];

function mitBoardsReady() {
    global $mysqli;
    static $ready = null;
    if ($ready === null) {
        try {
            $ready = mysqli_num_rows(mysqli_query($mysqli, "SHOW TABLES LIKE 'mit_board_rules'")) === 1;
        } catch (Throwable $e) {
            $ready = false;
        }
    }
    return $ready;
}

/** The boards, id => row, in their order; archived ones only when asked. */
function mitBoards($with_archived = false) {
    global $mysqli;
    static $cache = null;
    if (!mitBoardsReady()) {
        return [];
    }
    if ($cache === null) {
        $cache = [];
        $sql = mysqli_query($mysqli, "SELECT * FROM mit_boards ORDER BY board_archived_at IS NOT NULL, board_order, board_name");
        while ($row = mysqli_fetch_assoc($sql)) {
            $cache[intval($row['board_id'])] = $row;
        }
    }
    return $with_archived ? $cache : array_filter($cache, function ($b) { return $b['board_archived_at'] === null; });
}

/** The default board's id (where new tickets land and where tickets with no board sit), 0 when boards are not set up. */
function mitBoardDefaultId() {
    foreach (mitBoards() as $id => $board) {
        if (intval($board['board_is_default'])) {
            return $id;
        }
    }
    return array_key_first(mitBoards()) ?? 0;
}

/** SQL for a ticket's board id ($alias = the tickets table's name or alias in the query). */
function mitBoardSql($alias = 'tickets') {
    if (!mitBoardsReady()) {
        return '0';
    }
    return "COALESCE((SELECT mtb.board_id FROM mit_ticket_boards mtb WHERE mtb.ticket_id = $alias.ticket_id), " . intval(mitBoardDefaultId()) . ")";
}

/** A ticket's board id. */
function mitBoardOf($ticket_id) {
    global $mysqli;
    if (!mitBoardsReady()) {
        return 0;
    }
    $row = mysqli_fetch_row(mysqli_query($mysqli, "SELECT board_id FROM mit_ticket_boards WHERE ticket_id = " . intval($ticket_id)));
    $id = $row ? intval($row[0]) : 0;
    return isset(mitBoards()[$id]) ? $id : mitBoardDefaultId();
}

/** The boards a tech is on: board id => notify. */
function mitBoardsOfUser($user_id) {
    global $mysqli;
    static $cache = [];
    $user_id = intval($user_id);
    if (!mitBoardsReady() || !$user_id) {
        return [];
    }
    if (!isset($cache[$user_id])) {
        $cache[$user_id] = [];
        $sql = mysqli_query($mysqli, "SELECT m.board_id, m.member_notify FROM mit_board_members m JOIN mit_boards b ON b.board_id = m.board_id
            WHERE m.user_id = $user_id AND b.board_archived_at IS NULL");
        while ($row = mysqli_fetch_row($sql)) {
            $cache[$user_id][intval($row[0])] = intval($row[1]);
        }
    }
    return $cache[$user_id];
}

/** A board's members: user id => ['user_name', 'user_email', 'notify'] (active agents only). */
function mitBoardMembers($board_id) {
    global $mysqli;
    $members = [];
    if (!mitBoardsReady()) {
        return $members;
    }
    $sql = mysqli_query($mysqli, "SELECT u.user_id, u.user_name, u.user_email, m.member_notify FROM mit_board_members m
        JOIN users u ON u.user_id = m.user_id WHERE m.board_id = " . intval($board_id) . "
        AND u.user_type = 1 AND u.user_status = 1 AND u.user_archived_at IS NULL ORDER BY u.user_name");
    while ($row = mysqli_fetch_assoc($sql)) {
        $members[intval($row['user_id'])] = ['user_name' => $row['user_name'], 'user_email' => $row['user_email'], 'notify' => intval($row['member_notify'])];
    }
    return $members;
}

/**
 * Which boards a "board" choice means: '' / 'all' = every board (null), 'mine' = the tech's boards (every board when they
 * are on none), or one board's id.
 */
function mitBoardChoiceIds($choice, $user_id) {
    $choice = (string) $choice;
    if ($choice === '' || $choice === 'all' || !mitBoardsReady()) {
        return null;
    }
    if ($choice === 'mine') {
        $mine = array_keys(mitBoardsOfUser($user_id));
        return $mine ?: null;
    }
    $id = intval($choice);
    return isset(mitBoards()[$id]) ? [$id] : null;
}

/** " AND <ticket's board> IN (...)" for a choice, or ''. */
function mitBoardWhere($choice, $user_id, $alias = 'tickets') {
    $ids = mitBoardChoiceIds($choice, $user_id);
    return $ids === null ? '' : ' AND ' . mitBoardSql($alias) . ' IN (' . implode(',', array_map('intval', $ids)) . ')';
}

/** A board's coloured tag. */
function mitBoardBadge($board_id, $small = false) {
    $board = mitBoards(true)[intval($board_id)] ?? null;
    if (!$board) {
        return '';
    }
    return '<span class="badge mit-board-badge' . ($small ? ' fw-normal' : '') . '" data-mit-board="' . intval($board_id) . '" style="--mit-board:' . escapeHtml($board['board_color']) . '">'
        . '<i class="fas fa-fw fa-' . escapeHtml($board['board_icon']) . ' me-1" aria-hidden="true"></i>' . escapeHtml($board['board_name']) . '</span>';
}

/** The tag's look, once per page (works with or without the modern look). */
function mitBoardStyle() {
    static $done = false;
    if ($done) {
        return '';
    }
    $done = true;
    return '<style>.mit-board-badge{background:color-mix(in srgb,var(--mit-board) 16%,var(--bs-body-bg))!important;'
        . 'color:color-mix(in srgb,var(--mit-board) 75%,var(--bs-body-color))!important;border:1px solid color-mix(in srgb,var(--mit-board) 35%,transparent)}'
        . '.mit-board-dot{display:inline-block;width:.6rem;height:.6rem;border-radius:50%;background:var(--mit-board)}</style>';
}

/*
 * ---------- placing and moving ----------
 */

/** Rules: kind => [match => board id]. Mailbox matches are lower-case addresses. */
function mitBoardRules() {
    global $mysqli;
    static $cache = null;
    if ($cache === null) {
        $cache = array_fill_keys(array_keys(MIT_BOARD_RULE_KINDS), []);
        if (mitBoardsReady()) {
            $sql = mysqli_query($mysqli, "SELECT r.rule_kind, r.rule_match, r.board_id FROM mit_board_rules r JOIN mit_boards b ON b.board_id = r.board_id
                WHERE b.board_archived_at IS NULL");
            while ($row = mysqli_fetch_assoc($sql)) {
                if (isset($cache[$row['rule_kind']])) {
                    $cache[$row['rule_kind']][$row['rule_match']] = intval($row['board_id']);
                }
            }
        }
    }
    return $cache;
}

/** The board a ticket should land on: [board id, why]. $addresses = what an email was sent to (To / Cc / Delivered-To). */
function mitBoardPick(array $ticket, array $addresses = []) {
    $rules = mitBoardRules();
    foreach ($addresses as $address) {
        $address = strtolower(trim((string) $address));
        if (isset($rules['mailbox'][$address])) {
            return [$rules['mailbox'][$address], "sent to $address"];
        }
    }
    $client_id = intval($ticket['ticket_client_id'] ?? 0);
    if ($client_id && isset($rules['client'][(string) $client_id])) {
        return [$rules['client'][(string) $client_id], 'client rule'];
    }
    $category_id = intval($ticket['ticket_category'] ?? 0);
    if ($category_id && isset($rules['category'][(string) $category_id])) {
        return [$rules['category'][(string) $category_id], 'category rule'];
    }
    return [mitBoardDefaultId(), 'default board'];
}

/**
 * Put a new ticket on its board (once - a ticket that already has one is left alone) and tell that board's members.
 * Returns the board id, or 0.
 */
function mitBoardRoute($ticket_id, array $addresses = []) {
    global $mysqli;
    $ticket_id = intval($ticket_id);
    if (!mitBoardsReady() || !$ticket_id || !mitBoards()) {
        return 0;
    }
    if (mysqli_num_rows(mysqli_query($mysqli, "SELECT 1 FROM mit_ticket_boards WHERE ticket_id = $ticket_id"))) {
        return mitBoardOf($ticket_id);
    }
    $ticket = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT ticket_id, ticket_client_id, ticket_category FROM tickets WHERE ticket_id = $ticket_id"));
    if (!$ticket) {
        return 0;
    }
    [$board_id, $why] = mitBoardPick($ticket, $addresses);
    if (!$board_id) {
        return 0;
    }
    mysqli_query($mysqli, "INSERT IGNORE INTO mit_ticket_boards SET ticket_id = $ticket_id, board_id = $board_id, board_set_by = 0, board_set_how = 'routed'");
    if (!mysqli_affected_rows($mysqli)) {
        return mitBoardOf($ticket_id);
    }
    $name = mitBoards()[$board_id]['board_name'] ?? '';
    logTicketHistory($ticket_id, escapeSql("Placed on the $name board ($why)"));
    mitBoardNotify($ticket_id, $board_id, 'new', '', 0);
    return $board_id;
}

/**
 * Move a ticket to a board. $reason goes in as an internal note (required by Escalate, not by a plain move); $unassign
 * hands it back to nobody. Members of the new board who asked to be told get a bell notification and an email.
 * Returns [ok, message].
 */
function mitBoardMove($ticket_id, $board_id, $reason = '', $unassign = false, $how = 'moved') {
    global $mysqli, $session_user_id, $session_name;
    $ticket_id = intval($ticket_id);
    $board_id = intval($board_id);
    $boards = mitBoards();
    if (!mitBoardsReady() || !isset($boards[$board_id])) {
        return [false, 'Pick a board.'];
    }
    $from = mitBoardOf($ticket_id);
    $to_name = $boards[$board_id]['board_name'];
    if ($from === $board_id && $how !== 'created') {
        return [true, "The ticket is already on $to_name."];
    }
    $by = intval($session_user_id);
    $how_sql = escapeSql(in_array($how, ['moved', 'escalated', 'created'], true) ? $how : 'moved');
    mysqli_query($mysqli, "INSERT INTO mit_ticket_boards SET ticket_id = $ticket_id, board_id = $board_id, board_set_by = $by, board_set_how = '$how_sql'
        ON DUPLICATE KEY UPDATE board_id = $board_id, board_set_at = NOW(), board_set_by = $by, board_set_how = '$how_sql'");

    $from_name = $boards[$from]['board_name'] ?? mitBoards(true)[$from]['board_name'] ?? 'no board';
    $verb = $how === 'escalated' ? 'escalated the ticket' : ($how === 'created' ? 'opened the ticket' : 'moved the ticket');
    $reason = trim((string) $reason);
    if ($how === 'created') {
        logTicketHistory($ticket_id, escapeSql("$session_name put the ticket on the $to_name board"));
    } else {
        logTicketHistory($ticket_id, escapeSql("$session_name $verb from $from_name to $to_name" . ($reason !== '' ? ": $reason" : '')));
        $note = ($how === 'escalated' ? 'Escalated' : 'Moved') . " from $from_name to $to_name." . ($reason !== '' ? "\n\n$reason" : '');
        mysqli_query($mysqli, "INSERT INTO ticket_replies SET ticket_reply = '" . mitSqlHtml(nl2br(escapeHtml($note))) . "', ticket_reply_type = 'Internal',
            ticket_reply_time_worked = '00:00:00', ticket_reply_by = $by, ticket_reply_ticket_id = $ticket_id");
    }
    if ($unassign) {
        $assigned = intval(mysqli_fetch_row(mysqli_query($mysqli, "SELECT ticket_assigned_to FROM tickets WHERE ticket_id = $ticket_id"))[0] ?? 0);
        if ($assigned) {
            ticketChangeAssignee($ticket_id, 0);
        }
    }
    $ticket = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT ticket_prefix, ticket_number, ticket_client_id FROM tickets WHERE ticket_id = $ticket_id"));
    logAudit('Ticket', 'Edit', "$session_name $verb " . ticketReference($ticket ?: []) . " to the $to_name board", intval($ticket['ticket_client_id'] ?? 0), $ticket_id);
    $told = mitBoardNotify($ticket_id, $board_id, $how === 'created' ? 'new' : $how, $reason, $by);
    return [true, ($how === 'escalated' ? 'Escalated' : 'Moved') . " to $to_name" . ($told ? ' - ' . implode(', ', $told) . ' told' : '') . '.'];
}

/**
 * Tell a board's members who asked for it ('new' = landed there, 'moved' / 'escalated' = moved there): a bell
 * notification and an email - only those who can see the client, never the person who did it. Returns their names.
 */
function mitBoardNotify($ticket_id, $board_id, $why, $reason, $by_user_id) {
    global $mysqli, $session_name, $config_base_url;
    $ticket = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT ticket_id, ticket_prefix, ticket_number, ticket_subject, ticket_client_id, client_name
        FROM tickets LEFT JOIN clients ON client_id = ticket_client_id WHERE ticket_id = " . intval($ticket_id)));
    $board = mitBoards()[intval($board_id)] ?? null;
    if (!$ticket || !$board) {
        return [];
    }
    $reference = ticketReference($ticket);
    $client_id = intval($ticket['ticket_client_id']);
    $url = '/agent/' . ticketUrl($ticket['ticket_id'], $client_id);
    $who = $by_user_id ? (string) $session_name : '';
    $line = $why === 'new'
        ? "New ticket on $board[board_name]: $reference - $ticket[ticket_subject]"
        : ($who ?: 'Someone') . ($why === 'escalated' ? ' escalated ' : ' moved ') . "$reference to $board[board_name] - $ticket[ticket_subject]";
    $mail_ok = mitBoardMailReady();
    $from = mitMailFrom();
    $base = (string) ($config_base_url ?: mysqli_fetch_row(mysqli_query($mysqli, "SELECT config_base_url FROM settings WHERE company_id = 1"))[0] ?? '');
    $mail = [];
    $told = [];
    foreach (mitBoardMembers($board_id) as $user_id => $member) {
        if (!$member['notify'] || $user_id === intval($by_user_id) || !mitAgentCanSeeClient($user_id, $client_id)) {
            continue;
        }
        mysqli_query($mysqli, "INSERT INTO notifications SET notification_type = 'Ticket', notification = '" . escapeSql($line) . "',
            notification_action = '" . escapeSql($url) . "', notification_client_id = $client_id, notification_entity_id = " . intval($ticket['ticket_id']) . ", notification_user_id = $user_id");
        if ($mail_ok && filter_var($member['user_email'], FILTER_VALIDATE_EMAIL) && $from['email'] !== '') {
            $body = escapeHtml($line) . ($ticket['client_name'] ? '<br>Client: ' . escapeHtml($ticket['client_name']) : '')
                . ($reason !== '' ? '<br><br>' . nl2br(escapeHtml($reason)) : '')
                . '<br><br><a href="' . escapeHtml("https://$base$url") . '">Open the ticket</a>'
                . '<br><br><i style="color:#808080">You get this because you are on the ' . escapeHtml($board['board_name']) . ' board - change it under Admin &gt; Settings &gt; Boards.</i>';
            $mail[] = ['from' => escapeSql($from['email']), 'from_name' => escapeSql($from['name']), 'recipient' => escapeSql($member['user_email']),
                'recipient_name' => escapeSql($member['user_name']), 'subject' => escapeSql("[$reference] $line"), 'body' => mitMailBody($body)];
        }
        $told[] = $member['user_name'];
    }
    if ($mail) {
        addToMailQueue($mail);
    }
    return $told;
}

/** Whether outgoing mail is set up (an SMTP host or a mail provider such as Microsoft 365). */
function mitBoardMailReady() {
    global $mysqli;
    static $ready = null;
    if ($ready === null) {
        $row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT config_smtp_host, config_smtp_provider FROM settings WHERE company_id = 1")) ?: [];
        $ready = trim((string) ($row['config_smtp_host'] ?? '')) !== '' || trim((string) ($row['config_smtp_provider'] ?? '')) !== '';
    }
    return $ready;
}

/**
 * The cron job mit_boards (every minute): tickets made since boards came in that have no board yet - the portal, the
 * API, recurring tickets, projects - get one by the client and category rules.
 */
function mitBoardSweep() {
    global $mysqli;
    if (!mitBoardsReady() || !mitBoards()) {
        return 0;
    }
    $since = escapeSql((string) mitSetting('boards_since', date('Y-m-d H:i:s')));
    $sql = mysqli_query($mysqli, "SELECT t.ticket_id FROM tickets t LEFT JOIN mit_ticket_boards b ON b.ticket_id = t.ticket_id
        WHERE b.ticket_id IS NULL AND t.ticket_created_at >= '$since' ORDER BY t.ticket_id LIMIT 200");
    $placed = 0;
    while ($row = mysqli_fetch_row($sql)) {
        if (mitBoardRoute(intval($row[0]))) {
            $placed++;
        }
    }
    return $placed;
}

/**
 * Admin's "Place open tickets by category": open tickets still on the default board (never moved by hand) go to their
 * category's board. Nobody is notified. Returns how many moved.
 */
function mitBoardPlaceByCategory() {
    global $mysqli;
    $rules = mitBoardRules()['category'];
    $default = mitBoardDefaultId();
    $moved = 0;
    foreach ($rules as $category_id => $board_id) {
        if ($board_id === $default) {
            continue;
        }
        $category_id = intval($category_id);
        $sql = mysqli_query($mysqli, "SELECT t.ticket_id FROM tickets t LEFT JOIN mit_ticket_boards b ON b.ticket_id = t.ticket_id
            WHERE t.ticket_category = $category_id AND " . str_replace('tickets.', 't.', TICKET_OPEN_SQL) . "
            AND (b.ticket_id IS NULL OR (b.board_id = $default AND b.board_set_how = 'routed'))");
        while ($row = mysqli_fetch_row($sql)) {
            $ticket_id = intval($row[0]);
            mysqli_query($mysqli, "INSERT INTO mit_ticket_boards SET ticket_id = $ticket_id, board_id = " . intval($board_id) . ", board_set_by = 0, board_set_how = 'routed'
                ON DUPLICATE KEY UPDATE board_id = " . intval($board_id) . ", board_set_at = NOW()");
            logTicketHistory($ticket_id, escapeSql('Placed on the ' . (mitBoards()[$board_id]['board_name'] ?? '') . ' board by its category'));
            $moved++;
        }
    }
    return $moved;
}

/*
 * ---------- tickets: list, ticket page, New Ticket ----------
 */

/** agent/tickets.php: the board filter (?board= all / mine / id) - into the page's filters, so the pills count it too. */
function mitBoardTicketsFilter(array &$filter_sql) {
    global $session_user_id;
    $where = mitBoardWhere($_GET['board'] ?? '', $session_user_id);
    if ($where !== '') {
        $filter_sql[] = $where;
    }
}

/** agent/tickets.php: keeps the board when the search form is sent. */
function mitBoardHiddenInput() {
    $board = (string) ($_GET['board'] ?? '');
    return mitBoardsReady() && $board !== '' ? '<input type="hidden" name="board" value="' . escapeHtml($board) . '">' : '';
}

/** agent/tickets.php: the board switcher next to the title - All boards, My boards and each board with its open tickets. */
function mitBoardSwitcher(callable $filter_url) {
    global $mysqli, $session_user_id;
    $boards = mitBoards();
    if (!$boards) {
        return '';
    }
    $choice = (string) ($_GET['board'] ?? '');
    $mine = mitBoardsOfUser($session_user_id);
    $counts = [];
    $sql = mysqli_query($mysqli, "SELECT " . mitBoardSql() . " AS b, COUNT(*) FROM tickets WHERE " . TICKET_OPEN_SQL
        . clientScopeSql('ticket_client_id') . (function_exists('mitServiceTicketsAnd') ? mitServiceTicketsAnd('tickets.ticket_project_id') : '') . " GROUP BY b");
    while ($row = mysqli_fetch_row($sql)) {
        $counts[intval($row[0])] = intval($row[1]);
    }
    $label = $choice === 'mine' && $mine ? 'My boards' : (isset($boards[intval($choice)]) && $choice !== '' ? $boards[intval($choice)]['board_name'] : 'All boards');
    $current = isset($boards[intval($choice)]) && $choice !== '' ? $boards[intval($choice)] : null;
    $html = mitBoardStyle() . '<div class="dropdown d-inline-block ms-3 mit-board-switcher"><button type="button" class="btn btn-sm btn-light dropdown-toggle" data-bs-toggle="dropdown" title="Board">'
        . ($current ? '<span class="mit-board-dot me-2" style="--mit-board:' . escapeHtml($current['board_color']) . '"></span>' : '<i class="fas fa-columns me-2"></i>')
        . escapeHtml($label) . '</button><div class="dropdown-menu">';
    $item = function ($value, $text, $count, $dot = '', $active = false) use ($filter_url) {
        return '<a class="dropdown-item d-flex align-items-center gap-2' . ($active ? ' active' : '') . '" href="' . escapeHtml($filter_url(['board' => $value])) . '">'
            . $dot . '<span class="me-auto">' . escapeHtml($text) . '</span>' . ($count !== null ? '<span class="badge text-bg-secondary">' . $count . '</span>' : '') . '</a>';
    };
    $html .= $item('all', 'All boards', array_sum($counts), '<i class="fas fa-fw fa-columns"></i>', $choice === '' || $choice === 'all');
    if ($mine) {
        $html .= $item('mine', 'My boards', array_sum(array_intersect_key($counts, $mine)), '<i class="fas fa-fw fa-user-check"></i>', $choice === 'mine');
    }
    $html .= '<div class="dropdown-divider"></div>';
    foreach ($boards as $id => $board) {
        $html .= $item((string) $id, $board['board_name'], $counts[$id] ?? 0,
            '<span class="mit-board-dot" style="--mit-board:' . escapeHtml($board['board_color']) . '"></span>', $choice === (string) $id);
    }
    return $html . '<div class="dropdown-divider"></div><span class="dropdown-item-text small text-body-secondary">Counts are open tickets</span></div></div>';
}

/** The ticket list and board cards: a small board tag - left out when the list shows one board. */
function mitBoardListTag($ticket_id) {
    $boards = mitBoards();
    if (count($boards) < 2 || isset($boards[intval($_GET['board'] ?? 0)])) {
        return '';
    }
    $board = $boards[mitBoardOf($ticket_id)] ?? null;
    return $board ? mitBoardStyle() . '<span class="text-nowrap" title="Board"><span class="mit-board-dot me-1" style="--mit-board:' . escapeHtml($board['board_color']) . '"></span>'
        . escapeHtml($board['board_name']) . '</span>' : '';
}

/** The ticket page's Board line (under Assigned to): the tag, and a click to move it. */
function mitBoardTicketProp(array $ticket, $can_change) {
    if (!mitBoardsReady() || !mitBoards()) {
        return '';
    }
    $ticket_id = intval($ticket['ticket_id']);
    $badge = mitBoardBadge(mitBoardOf($ticket_id));
    $html = mitBoardStyle() . '<div class="ticket-prop"><dt>Board</dt><dd>';
    if ($can_change && ticketIsOpen($ticket)) {
        $html .= '<a href="#" class="ticket-prop-edit ajax-modal" data-modal-url="modals/mit_board/board_move.php?ticket_id=' . $ticket_id . '" title="Move to another board">'
            . $badge . '<i class="fas fa-pen ticket-prop-edit-icon"></i></a>';
    } else {
        $html .= '<span class="ticket-prop-static">' . $badge . '</span>';
    }
    return $html . '</dd></div>';
}

/** The ticket page's ⋮ menu: Escalate to... */
function mitBoardEscalateMenuItem(array $ticket) {
    if (!mitBoardsReady() || count(mitBoards()) < 2 || !ticketIsOpen($ticket)) {
        return '';
    }
    return '<a class="dropdown-item ajax-modal" href="#" data-modal-url="modals/mit_board/board_move.php?escalate=1&ticket_id=' . intval($ticket['ticket_id']) . '">'
        . '<i class="fas fa-fw fa-level-up-alt me-2"></i>Escalate to another board</a>';
}

/** New Ticket: the Board field - Automatic (the routing rules) or a board. */
function mitBoardNewTicketField() {
    $boards = mitBoards();
    if (!$boards) {
        return '';
    }
    $html = '<div class="col"><div class="mb-3"><label>Board</label><div class="input-group">'
        . '<span class="input-group-text"><i class="fas fa-fw fa-columns"></i></span><select class="form-select" name="mit_board">'
        . '<option value="">Automatic (routing rules)</option>';
    foreach ($boards as $id => $board) {
        $html .= '<option value="' . $id . '">' . escapeHtml($board['board_name']) . '</option>';
    }
    return $html . '</select></div></div></div>';
}

/** After New Ticket saved: the board picked, else the routing rules. */
function mitBoardAfterCreate($ticket_id, $picked) {
    $picked = intval($picked);
    if ($picked && isset(mitBoards()[$picked])) {
        mitBoardMove($ticket_id, $picked, '', false, 'created');
        return;
    }
    mitBoardRoute($ticket_id);
}

/** The email parser: the addresses a message was sent to (To, Cc and Delivered-To / X-Original-To headers). */
function mitBoardMessageAddresses($message) {
    $addresses = [];
    try {
        foreach (['to', 'cc'] as $part) {
            if (method_exists($message, $part)) {
                foreach ((array) $message->$part() as $address) {
                    $addresses[] = is_object($address) && method_exists($address, 'email') ? $address->email() : (string) $address;
                }
            }
        }
        if (method_exists($message, 'header')) {
            foreach (['Delivered-To', 'X-Original-To'] as $name) {
                $header = $message->header($name);
                if ($header) {
                    $addresses[] = method_exists($header, 'getValue') ? $header->getValue() : (string) $header;
                }
            }
        }
    } catch (Throwable $e) {
        // Routing then falls back to the client / category rules
    }
    $clean = [];
    foreach ($addresses as $address) {
        if (preg_match('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', (string) $address, $m)) {
            $clean[] = strtolower($m[0]);
        }
    }
    return array_values(array_unique($clean));
}
