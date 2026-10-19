<?php

/*
 * Mountaineer IT - pick who an email reply goes to (agent/ticket.php reply box, agent/post/ticket.php add_ticket_reply).
 * ITFlow emails the contact and every watcher. Here the "Email reply" box lists the contact and the watchers (ticked),
 * the lead agent and helpers, the client's other contacts (not ticked) and any address typed in; whatever is ticked gets
 * the email. The choice is remembered per ticket (mit_ticket_mail_choices, M021) where it differs from that default.
 * Without the database update, or from anywhere that does not post the list (the API, older forms), ITFlow's own
 * ticketMailContactAndWatchers() runs as before.
 */

function mitReplyRecipientsReady() {
    global $mysqli;
    static $ready = null;
    if ($ready === null) {
        try {
            $ready = mysqli_num_rows(mysqli_query($mysqli, "SHOW TABLES LIKE 'mit_ticket_mail_choices'")) === 1;
        } catch (Throwable $e) {
            $ready = false;
        }
    }
    return $ready;
}

/**
 * Everyone the reply box offers: email => [name, group (contact | watcher | agent | client | added), default ticked, ticked now].
 * Saved choices for this ticket override the default; addresses typed in before (and ticked) come back as "added".
 */
function mitReplyRecipients($ticket_id) {
    global $mysqli;
    $ticket_id = intval($ticket_id);
    $ticket = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT ticket_client_id, ticket_contact_id, ticket_assigned_to FROM tickets WHERE ticket_id = $ticket_id"));
    if (!$ticket) {
        return [];
    }
    $list = [];
    $add = function ($email, $name, $group, $default) use (&$list) {
        $email = strtolower(trim((string) $email));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || isset($list[$email])) {
            return;
        }
        $list[$email] = ['name' => (string) $name, 'group' => $group, 'default' => $default, 'send' => $default];
    };
    $contact_id = intval($ticket['ticket_contact_id']);
    if ($contact_id) {
        $row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT contact_name, contact_email FROM contacts WHERE contact_id = $contact_id"));
        if ($row) {
            $add($row['contact_email'], $row['contact_name'], 'contact', true);
        }
    }
    $result = mysqli_query($mysqli, "SELECT watcher_email FROM ticket_watchers WHERE watcher_ticket_id = $ticket_id ORDER BY watcher_email");
    while ($row = mysqli_fetch_assoc($result)) {
        $add($row['watcher_email'], '', 'watcher', true);
    }
    $lead = intval($ticket['ticket_assigned_to']);
    if ($lead) {
        $row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT user_name, user_email FROM users WHERE user_id = $lead AND user_archived_at IS NULL"));
        if ($row) {
            $add($row['user_email'], $row['user_name'] . ' (assigned)', 'agent', false);
        }
    }
    foreach (function_exists('mitTicketTechs') ? mitTicketTechs($ticket_id) : [] as $helper) {
        $add($helper['user_email'], $helper['user_name'] . ' (helping)', 'agent', false);
    }
    $client_id = intval($ticket['ticket_client_id']);
    if ($client_id) {
        $result = mysqli_query($mysqli, "SELECT contact_name, contact_email FROM contacts WHERE contact_client_id = $client_id
            AND contact_archived_at IS NULL AND contact_email != '' ORDER BY contact_primary DESC, contact_name");
        while ($row = mysqli_fetch_assoc($result)) {
            $add($row['contact_email'], $row['contact_name'], 'client', false);
        }
    }
    if (mitReplyRecipientsReady()) {
        $result = mysqli_query($mysqli, "SELECT email, send FROM mit_ticket_mail_choices WHERE ticket_id = $ticket_id");
        while ($row = mysqli_fetch_assoc($result)) {
            $email = $row['email'];
            if (isset($list[$email])) {
                $list[$email]['send'] = (bool) intval($row['send']);
            } elseif (intval($row['send'])) {
                $add($email, '', 'added', false);
                $list[$email]['send'] = true;
            }
        }
    }
    return $list;
}

/** The "Send to" box, under the reply type picker - shown when "Email reply" is picked. */
function mitReplyRecipientsField($ticket_id) {
    if (!mitReplyRecipientsReady()) {
        return '';
    }
    $groups = ['contact' => 'Contact', 'watcher' => 'Watchers', 'agent' => 'Our team', 'added' => 'Added before', 'client' => 'Other contacts at the client'];
    $list = mitReplyRecipients($ticket_id);
    $html = '<div class="border rounded p-2 mb-3 small" id="mitSendTo" hidden><input type="hidden" name="mit_send_to_present" value="1">'
        . '<div class="d-flex align-items-center mb-1"><strong><i class="fas fa-fw fa-paper-plane me-1"></i>Send to</strong>'
        . '<span class="text-body-secondary ms-2">Your choice is remembered for this ticket.</span></div>';
    $i = 0;
    foreach ($groups as $group => $label) {
        $rows = array_filter($list, function ($r) use ($group) {
            return $r['group'] === $group;
        });
        if (!$rows) {
            continue;
        }
        $scroll = $group === 'client' && count($rows) > 6 ? ' style="max-height: 9.5rem; overflow-y: auto;"' : '';
        $html .= '<div class="text-body-secondary mt-2">' . $label . '</div><div class="d-flex flex-wrap column-gap-3"' . $scroll . '>';
        foreach ($rows as $email => $r) {
            $id = 'mitSendTo' . $i++;
            $html .= '<div class="form-check"><input type="hidden" name="mit_send_to_listed[]" value="' . escapeHtml($email) . '">'
                . '<input class="form-check-input" type="checkbox" name="mit_send_to[]" value="' . escapeHtml($email) . '" id="' . $id . '"' . ($r['send'] ? ' checked' : '') . '>'
                . '<label class="form-check-label" for="' . $id . '">' . ($r['name'] !== '' ? escapeHtml($r['name']) . ' <span class="text-body-secondary">&lt;' . escapeHtml($email) . '&gt;</span>' : escapeHtml($email)) . '</label></div>';
        }
        $html .= '</div>';
    }
    $html .= '<div class="input-group input-group-sm mt-2" style="max-width: 26rem;"><input type="email" class="form-control" id="mitSendToNew" placeholder="Add an email address" aria-label="Add an email address">'
        . '<button class="btn btn-outline-secondary" type="button" id="mitSendToAdd">Add</button></div></div>';
    return $html . '<script src="' . mitAsset('/agent/js/mit_reply_recipients.js') . '"></script>';
}

/**
 * From add_ticket_reply, instead of ticketMailContactAndWatchers() when the box was posted: emails whoever was ticked
 * (the contact gets ITFlow's own email, everyone else the same with ITFlow's collaborator line) and remembers the choice.
 * Returns the number of emails queued.
 */
function mitReplyRecipientsMail($ticket_id, array $post, $subject, $body, array $attachments) {
    global $mysqli, $config_smtp_provider, $config_ticket_from_email, $config_ticket_from_name, $session_user_id;
    $ticket_id = intval($ticket_id);
    $list = mitReplyRecipients($ticket_id);
    $clean = function ($emails) {
        $out = [];
        foreach ((array) $emails as $email) {
            $email = strtolower(trim((string) $email));
            if ($email !== '' && strlen($email) <= 255 && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $out[$email] = true;
            }
        }
        return $out;
    };
    $ticked = $clean($post['mit_send_to'] ?? []);
    $listed = $clean($post['mit_send_to_listed'] ?? []) + $ticked;

    // Remember it where it differs from the default; a typed address counts as not ticked by default
    foreach (array_slice(array_keys($listed), 0, 200) as $email) {
        $default = !empty($list[$email]['default']);
        $send = isset($ticked[$email]);
        $email_sql = escapeSql($email);
        if ($send === $default) {
            mysqli_query($mysqli, "DELETE FROM mit_ticket_mail_choices WHERE ticket_id = $ticket_id AND email = '$email_sql'");
        } else {
            mysqli_query($mysqli, "INSERT INTO mit_ticket_mail_choices SET ticket_id = $ticket_id, email = '$email_sql', send = " . ($send ? 1 : 0) . ", updated_by = " . intval($session_user_id) . "
                ON DUPLICATE KEY UPDATE send = VALUES(send), updated_by = VALUES(updated_by)");
        }
    }

    if (empty($config_smtp_provider) || !$ticked) {
        return 0;
    }
    $from_email = escapeSql($config_ticket_from_email);
    $from_name = escapeSql($config_ticket_from_name);
    $copy_body = $body . "<br><br>----------------------------------------<br>YOU ARE A COLLABORATOR ON THIS TICKET";
    $data = [];
    foreach (array_slice(array_keys($ticked), 0, 50) as $email) {
        $is_contact = ($list[$email]['group'] ?? '') === 'contact';
        $name = ($list[$email]['name'] ?? '') !== '' ? preg_replace('/ \((assigned|helping)\)$/', '', $list[$email]['name']) : $email;
        $data[] = ['from' => $from_email, 'from_name' => $from_name, 'recipient' => escapeSql($email), 'recipient_name' => escapeSql($name),
            'subject' => $subject, 'body' => $is_contact ? $body : $copy_body, 'attachments' => $attachments];
    }
    addToMailQueue($data);
    $names = implode(', ', array_keys($ticked));
    logTicketHistory($ticket_id, escapeSql("Reply emailed to " . mb_substr($names, 0, 400)));
    return count($data);
}
