<?php

/*
 * Mountaineer IT - each person's Outlook calendar (Account > Outlook calendar).
 *
 * A tech connects their own Microsoft 365 account to ITFlow's Microsoft 365 app
 * (functions/mit_m365.php - already approved for the organisation, so nobody is asked to approve
 * anything). From then on every open ticket booked for them is an event in their Outlook calendar,
 * and ITFlow keeps it that way:
 *
 *  - a booking that is moved, made longer, renamed, reassigned, cancelled or closed changes or
 *    removes its event within a minute (cron/mit_outlook_sync.php);
 *  - ITFlow is the source of truth - an event moved, renamed or deleted in Outlook is put back by
 *    the next check (every 15 minutes, or Sync now);
 *  - only events ITFlow made are touched, never the person's own appointments.
 *
 * Tables from database update M004: mit_outlook_links (one per person) and mit_outlook_events
 * (one per booked ticket per person). The refresh token is encrypted with a key made from this
 * server's config.php, so a copy of the database alone does not give access to anyone's calendar.
 */

/** Bookings that ended longer ago than this are left alone (and not added). */
const MIT_OUTLOOK_PAST_DAYS = 30;
/** How often Outlook itself is checked for events changed or deleted there. */
const MIT_OUTLOOK_CHECK_MINUTES = 15;
const MIT_OUTLOOK_CATEGORY = 'ITFlow';
const MIT_OUTLOOK_SCOPES = 'offline_access openid profile email https://graph.microsoft.com/Calendars.ReadWrite https://graph.microsoft.com/User.Read';

/*
 * ---------- the person's link ----------
 */

/** A person's Outlook link, or null. */
function mitOutlookLink($user_id) {
    global $mysqli;
    $user_id = intval($user_id);
    $result = mysqli_query($mysqli, "SELECT * FROM mit_outlook_links WHERE user_id = $user_id LIMIT 1");
    return $result ? mysqli_fetch_assoc($result) : null;
}

/** Can people connect Outlook here? Needs ITFlow's Microsoft 365 app (Admin > Settings > Mail). */
function mitOutlookAvailable() {
    return mitM365App() !== null;
}

/** The key the refresh tokens are encrypted with - from config.php, so not in the database. */
function mitOutlookKey() {
    global $installation_id, $dbpassword;
    return hash('sha256', 'mit-outlook|' . ($installation_id ?? '') . '|' . ($dbpassword ?? ''), true);
}

function mitOutlookEncrypt($plain) {
    $iv = random_bytes(12);
    $cipher = openssl_encrypt((string) $plain, 'aes-256-gcm', mitOutlookKey(), OPENSSL_RAW_DATA, $iv, $tag);
    return 'v1:' . base64_encode($iv . $tag . $cipher);
}

function mitOutlookDecrypt($stored) {
    if (strpos((string) $stored, 'v1:') !== 0) {
        return '';
    }
    $raw = base64_decode(substr($stored, 3));
    $plain = strlen($raw) > 28 ? openssl_decrypt(substr($raw, 28), 'aes-256-gcm', mitOutlookKey(), OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16)) : false;
    return $plain === false ? '' : $plain;
}

/** Remember a problem with the person's sync, shown on their Outlook calendar page. */
function mitOutlookSetError($user_id, $error) {
    global $mysqli;
    $user_id = intval($user_id);
    $error_sql = $error === null ? 'NULL' : "'" . mysqli_real_escape_string($mysqli, substr((string) $error, 0, 1000)) . "'";
    mysqli_query($mysqli, "UPDATE mit_outlook_links SET last_error = $error_sql WHERE user_id = $user_id");
}

/*
 * ---------- signing in ----------
 */

function mitOutlookTokenUrl() {
    return MIT_M365_LOGIN . '/' . rawurlencode(mitM365App()['tenant_id']) . '/oauth2/v2.0/token';
}

/** Where to send the person to sign in to Microsoft. */
function mitOutlookAuthorizeUrl($state) {
    $app = mitM365App();
    return MIT_M365_LOGIN . '/' . rawurlencode($app['tenant_id']) . '/oauth2/v2.0/authorize?' . http_build_query([
        'client_id' => $app['client_id'],
        'response_type' => 'code',
        'redirect_uri' => mitM365RedirectUris()[1],
        'response_mode' => 'query',
        'scope' => MIT_OUTLOOK_SCOPES,
        'state' => $state,
        'prompt' => 'select_account',
    ], '', '&', PHP_QUERY_RFC3986);
}

/** Back from Microsoft with a code: save the person's link. Throws with what went wrong. */
function mitOutlookConnect($user_id, $code) {
    global $mysqli;
    $app = mitM365App();
    $response = mitHttp('POST', mitOutlookTokenUrl(), [], mitMicrosoftTokenFields(mitOutlookTokenUrl(), [
        'client_id' => $app['client_id'],
        'client_secret' => MIT_M365_CERT_SECRET,
        'grant_type' => 'authorization_code',
        'code' => $code,
        'redirect_uri' => mitM365RedirectUris()[1],
        'scope' => MIT_OUTLOOK_SCOPES,
    ]));
    if (empty($response['json']['access_token']) || empty($response['json']['refresh_token'])) {
        throw new RuntimeException(mitM365Error($response, 'Signing in to Outlook'));
    }
    $access = $response['json']['access_token'];
    $me = mitGraph('GET', '/me?$select=displayName,mail,userPrincipalName', $access);
    if ($me['status'] !== 200) {
        throw new RuntimeException(mitM365Error($me, 'Reading your Microsoft 365 account'));
    }
    $email = (string) ($me['json']['mail'] ?? '') ?: (string) ($me['json']['userPrincipalName'] ?? '');

    $user_id = intval($user_id);
    $email_sql = mysqli_real_escape_string($mysqli, $email);
    $name_sql = mysqli_real_escape_string($mysqli, (string) ($me['json']['displayName'] ?? ''));
    $refresh_sql = mysqli_real_escape_string($mysqli, mitOutlookEncrypt($response['json']['refresh_token']));
    $access_sql = mysqli_real_escape_string($mysqli, mitOutlookEncrypt($access));
    $expires_sql = date('Y-m-d H:i:s', time() + intval($response['json']['expires_in'] ?? 3600));

    // A different Microsoft account than before: its events are not this one's to keep track of
    $old = mitOutlookLink($user_id);
    if ($old && strcasecmp($old['account_email'], $email) !== 0) {
        mysqli_query($mysqli, "DELETE FROM mit_outlook_events WHERE user_id = $user_id");
    }
    mysqli_query($mysqli, "INSERT INTO mit_outlook_links SET user_id = $user_id, account_email = '$email_sql', account_name = '$name_sql',
        refresh_token = '$refresh_sql', access_token = '$access_sql', access_expires_at = '$expires_sql', connected_at = NOW(), sync_signature = '', last_error = NULL
        ON DUPLICATE KEY UPDATE account_email = VALUES(account_email), account_name = VALUES(account_name), refresh_token = VALUES(refresh_token),
        access_token = VALUES(access_token), access_expires_at = VALUES(access_expires_at), connected_at = NOW(), sync_signature = '', last_error = NULL");
    return mitOutlookLink($user_id);
}

/** A Graph token for the person's calendar - refreshed when needed. Throws when Microsoft refuses. */
function mitOutlookToken(array $link) {
    global $mysqli;
    if (!empty($link['access_expires_at']) && strtotime($link['access_expires_at']) - 120 > time()) {
        $access = mitOutlookDecrypt($link['access_token']);
        if ($access !== '') {
            return $access;
        }
    }
    if (!mitOutlookAvailable()) {
        throw new RuntimeException('ITFlow\'s Microsoft 365 app has been removed - ask an admin to set it up again under Admin > Settings > Mail.');
    }
    $response = mitHttp('POST', mitOutlookTokenUrl(), [], mitMicrosoftTokenFields(mitOutlookTokenUrl(), [
        'client_id' => mitM365App()['client_id'],
        'client_secret' => MIT_M365_CERT_SECRET,
        'grant_type' => 'refresh_token',
        'refresh_token' => mitOutlookDecrypt($link['refresh_token']),
        'scope' => MIT_OUTLOOK_SCOPES,
    ]));
    if (empty($response['json']['access_token'])) {
        $error = mitM365Error($response, 'Signing in to Outlook');
        if (($response['json']['error'] ?? '') === 'invalid_grant') {
            $error = 'Microsoft asked for you to sign in again - click Reconnect. (' . $error . ')';
        }
        throw new RuntimeException($error);
    }
    $user_id = intval($link['user_id']);
    $access_sql = mysqli_real_escape_string($mysqli, mitOutlookEncrypt($response['json']['access_token']));
    $expires_sql = date('Y-m-d H:i:s', time() + intval($response['json']['expires_in'] ?? 3600));
    $refresh_set = '';
    if (!empty($response['json']['refresh_token'])) {
        $refresh_set = ", refresh_token = '" . mysqli_real_escape_string($mysqli, mitOutlookEncrypt($response['json']['refresh_token'])) . "'";
    }
    mysqli_query($mysqli, "UPDATE mit_outlook_links SET access_token = '$access_sql', access_expires_at = '$expires_sql' $refresh_set WHERE user_id = $user_id");
    return $response['json']['access_token'];
}

/*
 * ---------- what should be in the calendar ----------
 */

/** The person's open booked tickets, ticket_id => row. */
function mitOutlookBookings($user_id) {
    global $mysqli;
    $user_id = intval($user_id);
    $default_minutes = intval(CALENDAR_TICKET_MINUTES);
    $past_days = intval(MIT_OUTLOOK_PAST_DAYS);
    $result = mysqli_query($mysqli, "SELECT ticket_id, ticket_prefix, ticket_number, ticket_subject, ticket_schedule, ticket_schedule_end, ticket_onsite,
            client_name, contact_name, contact_phone, contact_mobile, location_name, location_address, location_city, location_state, location_zip
        FROM tickets
        LEFT JOIN clients ON client_id = ticket_client_id
        LEFT JOIN contacts ON contact_id = ticket_contact_id
        LEFT JOIN locations ON location_id = ticket_location_id
        WHERE ticket_assigned_to = $user_id AND ticket_schedule IS NOT NULL
        AND ticket_resolved_at IS NULL AND ticket_closed_at IS NULL AND ticket_archived_at IS NULL
        AND COALESCE(ticket_schedule_end, DATE_ADD(ticket_schedule, INTERVAL $default_minutes MINUTE)) >= DATE_SUB(NOW(), INTERVAL $past_days DAY)
        ORDER BY ticket_schedule");
    $bookings = [];
    while ($result && $row = mysqli_fetch_assoc($result)) {
        $bookings[intval($row['ticket_id'])] = $row;
    }
    return $bookings;
}

/** The Outlook event for a booking. Times go to Microsoft in UTC. */
function mitOutlookEvent(array $row) {
    $start = strtotime($row['ticket_schedule']);
    $end = strtotime(mitTicketScheduleEnd($row['ticket_schedule'], $row['ticket_schedule_end']));
    $reference = ticketReference($row);
    $client = trim((string) $row['client_name']);
    $onsite = intval($row['ticket_onsite']) === 1;

    $address = trim(implode(', ', array_filter([$row['location_address'], $row['location_city'], trim($row['location_state'] . ' ' . $row['location_zip'])])), ', ');
    $where = $onsite ? trim(($client !== '' ? "Onsite - $client" : 'Onsite') . ($address !== '' ? ", $address" : '')) : 'Remote';
    $phone = $row['contact_mobile'] ?: $row['contact_phone'];

    $lines = [];
    $lines[] = '<a href="' . escapeHtml(mitM365BaseUrl() . '/agent/ticket.php?ticket_id=' . intval($row['ticket_id'])) . '">Open ticket ' . escapeHtml($reference) . ' in ITFlow</a>';
    if ($client !== '') {
        $lines[] = 'Client: ' . escapeHtml($client);
    }
    if (!empty($row['contact_name'])) {
        $lines[] = 'Contact: ' . escapeHtml($row['contact_name']) . ($phone ? ' - ' . escapeHtml($phone) : '');
    }
    $lines[] = escapeHtml($where);
    $lines[] = '<small>Booked in ITFlow - change it there; changes made here are put back.</small>';

    return [
        'subject' => $reference . ' - ' . $row['ticket_subject'] . ($client !== '' ? " ($client)" : ''),
        'body' => ['contentType' => 'HTML', 'content' => '<p>' . implode('<br>', $lines) . '</p>'],
        'start' => ['dateTime' => gmdate('Y-m-d\TH:i:s', $start), 'timeZone' => 'UTC'],
        'end' => ['dateTime' => gmdate('Y-m-d\TH:i:s', $end), 'timeZone' => 'UTC'],
        'location' => ['displayName' => $where],
        'categories' => [MIT_OUTLOOK_CATEGORY],
        'showAs' => 'busy',
        'isReminderOn' => true,
        'reminderMinutesBeforeStart' => 15,
    ];
}

/** Does the event in Outlook still match what ITFlow made? (The parts people would change.) */
function mitOutlookEventMatches(array $want, array $have) {
    $time = function ($value) {
        return substr((string) ($value['dateTime'] ?? ''), 0, 19);
    };
    return (string) ($have['subject'] ?? '') === $want['subject']
        && $time($have['start'] ?? []) === $want['start']['dateTime']
        && $time($have['end'] ?? []) === $want['end']['dateTime']
        && (string) ($have['location']['displayName'] ?? '') === $want['location']['displayName']
        && empty($have['isCancelled']);
}

/*
 * ---------- the sync ----------
 */

/**
 * Bring a person's Outlook calendar in line with their bookings. Changed bookings are sent every
 * time; Outlook itself is checked for edits and deletions every MIT_OUTLOOK_CHECK_MINUTES, or now
 * with $check. Returns a short summary; problems are kept on the link (last_error) and thrown.
 */
function mitOutlookSync($user_id, $check = false) {
    global $mysqli;
    $user_id = intval($user_id);
    $link = mitOutlookLink($user_id);
    if (!$link) {
        return 'Not connected';
    }

    $want = [];
    foreach (mitOutlookBookings($user_id) as $ticket_id => $row) {
        $event = mitOutlookEvent($row);
        $want[$ticket_id] = ['event' => $event, 'hash' => sha1(json_encode($event))];
    }
    $signature = sha1(json_encode(array_map(function ($w) { return $w['hash']; }, $want)));
    $check = $check || empty($link['last_check_at']) || strtotime($link['last_check_at']) <= time() - MIT_OUTLOOK_CHECK_MINUTES * 60;
    if (!$check && $signature === $link['sync_signature'] && empty($link['last_error'])) {
        return 'Up to date';
    }

    $have = [];
    $result = mysqli_query($mysqli, "SELECT ticket_id, event_id, event_hash FROM mit_outlook_events WHERE user_id = $user_id");
    while ($row = mysqli_fetch_assoc($result)) {
        $have[intval($row['ticket_id'])] = $row;
    }

    $counts = ['added' => 0, 'updated' => 0, 'removed' => 0, 'restored' => 0];
    try {
        $token = mitOutlookToken($link);

        // What Outlook has now for the events that should be unchanged
        $outlook = [];
        if ($check) {
            $ids = [];
            foreach ($have as $ticket_id => $row) {
                if (isset($want[$ticket_id]) && $want[$ticket_id]['hash'] === $row['event_hash']) {
                    $ids[$ticket_id] = $row['event_id'];
                }
            }
            $outlook = mitOutlookFetchEvents($token, $ids);
        }

        foreach ($want as $ticket_id => $w) {
            $row = $have[$ticket_id] ?? null;
            if (!$row) {
                mitOutlookCreate($token, $user_id, $ticket_id, $w);
                $counts['added']++;
            } elseif ($row['event_hash'] !== $w['hash']) {
                mitOutlookUpdate($token, $user_id, $ticket_id, $row['event_id'], $w) ? $counts['updated']++ : $counts['added']++;
            } elseif ($check && array_key_exists($ticket_id, $outlook)) {
                if ($outlook[$ticket_id] === null) {
                    mitOutlookCreate($token, $user_id, $ticket_id, $w);  // deleted in Outlook
                    $counts['restored']++;
                } elseif (!mitOutlookEventMatches($w['event'], $outlook[$ticket_id])) {
                    mitOutlookUpdate($token, $user_id, $ticket_id, $row['event_id'], $w);  // changed in Outlook
                    $counts['restored']++;
                }
            }
        }
        foreach ($have as $ticket_id => $row) {
            if (!isset($want[$ticket_id])) {
                mitOutlookDelete($token, $row['event_id']);
                mysqli_query($mysqli, "DELETE FROM mit_outlook_events WHERE user_id = $user_id AND ticket_id = " . intval($ticket_id));
                $counts['removed']++;
            }
        }
    } catch (Throwable $e) {
        mitOutlookSetError($user_id, $e->getMessage());
        throw $e;
    }

    $signature_sql = mysqli_real_escape_string($mysqli, $signature);
    mysqli_query($mysqli, "UPDATE mit_outlook_links SET last_sync_at = NOW(), sync_signature = '$signature_sql', last_error = NULL"
        . ($check ? ", last_check_at = NOW()" : '') . " WHERE user_id = $user_id");

    $parts = [];
    foreach ($counts as $what => $count) {
        if ($count) {
            $parts[] = "$count $what";
        }
    }
    return $parts ? implode(', ', $parts) : 'Up to date';
}

/** Events by ticket id as Outlook has them now (null = gone), 20 to a Graph batch. */
function mitOutlookFetchEvents($token, array $event_ids) {
    $found = [];
    foreach (array_chunk($event_ids, 20, true) as $chunk) {
        $requests = [];
        foreach ($chunk as $ticket_id => $event_id) {
            $requests[] = ['id' => (string) $ticket_id, 'method' => 'GET',
                'url' => '/me/events/' . rawurlencode($event_id) . '?$select=subject,start,end,location,isCancelled',
                'headers' => ['Prefer' => 'outlook.timezone="UTC"']];
        }
        $response = mitGraph('POST', '/$batch', $token, ['requests' => $requests]);
        if ($response['status'] !== 200) {
            throw new RuntimeException(mitM365Error($response, 'Checking your Outlook calendar'));
        }
        foreach ($response['json']['responses'] ?? [] as $answer) {
            $status = intval($answer['status'] ?? 0);
            if ($status === 200) {
                $found[intval($answer['id'])] = $answer['body'] ?? [];
            } elseif ($status === 404) {
                $found[intval($answer['id'])] = null;
            }
            // anything else (throttled, a hiccup): leave it for the next check
        }
    }
    return $found;
}

function mitOutlookSaveEvent($user_id, $ticket_id, $event_id, $hash) {
    global $mysqli;
    $user_id = intval($user_id);
    $ticket_id = intval($ticket_id);
    $event_id_sql = mysqli_real_escape_string($mysqli, $event_id);
    $hash_sql = mysqli_real_escape_string($mysqli, $hash);
    mysqli_query($mysqli, "INSERT INTO mit_outlook_events SET user_id = $user_id, ticket_id = $ticket_id, event_id = '$event_id_sql', event_hash = '$hash_sql'
        ON DUPLICATE KEY UPDATE event_id = VALUES(event_id), event_hash = VALUES(event_hash)");
}

function mitOutlookCreate($token, $user_id, $ticket_id, array $w) {
    $created = mitGraph('POST', '/me/events', $token, $w['event']);
    if ($created['status'] !== 201 || empty($created['json']['id'])) {
        throw new RuntimeException(mitM365Error($created, 'Adding a booking to Outlook'));
    }
    mitOutlookSaveEvent($user_id, $ticket_id, $created['json']['id'], $w['hash']);
}

/** Change an event; true when changed, false when it had been deleted in Outlook and was added again. */
function mitOutlookUpdate($token, $user_id, $ticket_id, $event_id, array $w) {
    $patched = mitGraph('PATCH', '/me/events/' . rawurlencode($event_id), $token, $w['event']);
    if ($patched['status'] === 404) {
        mitOutlookCreate($token, $user_id, $ticket_id, $w);
        return false;
    }
    if ($patched['status'] !== 200) {
        throw new RuntimeException(mitM365Error($patched, 'Changing a booking in Outlook'));
    }
    mitOutlookSaveEvent($user_id, $ticket_id, $event_id, $w['hash']);
    return true;
}

function mitOutlookDelete($token, $event_id) {
    $deleted = mitGraph('DELETE', '/me/events/' . rawurlencode($event_id), $token);
    if ($deleted['status'] !== 204 && $deleted['status'] !== 404) {
        throw new RuntimeException(mitM365Error($deleted, 'Removing a booking from Outlook'));
    }
}

/** Disconnect: take ITFlow's events out of the person's Outlook (as far as Microsoft lets us) and forget the link. */
function mitOutlookDisconnect($user_id) {
    global $mysqli;
    $user_id = intval($user_id);
    $link = mitOutlookLink($user_id);
    $left = 0;
    if ($link) {
        try {
            $token = mitOutlookToken($link);
            $result = mysqli_query($mysqli, "SELECT event_id FROM mit_outlook_events WHERE user_id = $user_id");
            while ($row = mysqli_fetch_assoc($result)) {
                try {
                    mitOutlookDelete($token, $row['event_id']);
                } catch (Throwable $e) {
                    $left++;
                }
            }
        } catch (Throwable $e) {
            $left = intval(mysqli_fetch_row(mysqli_query($mysqli, "SELECT COUNT(*) FROM mit_outlook_events WHERE user_id = $user_id"))[0]);
        }
    }
    mysqli_query($mysqli, "DELETE FROM mit_outlook_events WHERE user_id = $user_id");
    mysqli_query($mysqli, "DELETE FROM mit_outlook_links WHERE user_id = $user_id");
    return $left;
}
