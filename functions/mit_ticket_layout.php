<?php

/*
 * Mountaineer IT - a customisable ticket page layout: which column each box sits in, their order,
 * and which start collapsed. An admin can save a company default; each tech can save their own on
 * top of it. Stored as JSON - the default in mit_settings (ticket_layout), a tech's own in
 * mit_user_preferences (ticket.layout) - and applied in the browser by agent/js/mit_ticket_layout.js,
 * which moves ITFlow's own boxes, so the page's markup stays upstream's.
 *
 * Layout: {"columns": '' | even | side | single | three, "left": [box ids] (the third column),
 * "main": [...], "side": [...], "collapsed": [...], "half": [...] (half width - two sit side by side)}.
 * Box ids are the script's names for the boxes (description, reply, conversation, details, contact,
 * also_open, site, tasks, linked, history). A box a layout does not mention stays where ITFlow puts it.
 */

/** A layout as posted or stored, made safe - or null when it is not one. */
function mitTicketLayoutClean($layout) {
    if (is_string($layout)) {
        $layout = json_decode($layout, true);
    }
    if (!is_array($layout)) {
        return null;
    }
    $clean = ['columns' => in_array($layout['columns'] ?? '', ['', 'even', 'side', 'single', 'three'], true) ? ($layout['columns'] ?? '') : ''];
    foreach (['left', 'main', 'side', 'collapsed', 'half'] as $key) {
        $ids = [];
        foreach ((array) ($layout[$key] ?? []) as $id) {
            if (is_string($id) && preg_match('/^[a-z_]{1,24}$/', $id) && count($ids) < 30) {
                $ids[$id] = $id;
            }
        }
        $clean[$key] = array_values($ids);
    }
    // A box is in one column only
    $clean['left'] = array_values(array_diff($clean['left'], $clean['main']));
    $clean['side'] = array_values(array_diff($clean['side'], $clean['main'], $clean['left']));
    return $clean['left'] || $clean['main'] || $clean['side'] ? $clean : null;
}

/** The company default layout, or null for ITFlow's own. */
function mitTicketLayoutDefault() {
    return mitTicketLayoutClean((string) mitSetting('ticket_layout', ''));
}

/** A tech's own layout, or null when they use the company default. */
function mitTicketLayoutMine($user_id) {
    return mitTicketLayoutClean((string) mitUserPreference($user_id, 'ticket.layout', ''));
}

/** The ticket page's hook (agent/ticket.php, after the layout): what the script needs, and the script. */
function mitTicketLayoutHtml() {
    global $session_user_id, $session_is_admin;
    $mine = mitTicketLayoutMine($session_user_id);
    $default = mitTicketLayoutDefault();
    $config = [
        'layout' => $mine ?? $default,
        'default' => $default,
        'hasMine' => $mine !== null,
        'isAdmin' => !empty($session_is_admin),
        'csrf' => $_SESSION['csrf_token'] ?? '',
    ];
    return '<script>window.mitTicketLayout = ' . json_encode($config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . ';</script>'
        . '<script src="/agent/js/mit_ticket_layout.js"></script>';
}

/**
 * Save or reset a layout (agent/ajax.php?mit_ticket_layout). $post['scope']: 'mine', 'default'
 * (admins - also clears their own, so they see what they saved), 'reset_mine', 'reset_default'.
 * Answers in JSON and stops.
 */
function mitTicketLayoutAjax(array $post) {
    global $mysqli, $session_user_id, $session_is_admin;
    header('Content-Type: application/json');
    $fail = function ($message, $code = 400) {
        http_response_code($code);
        echo json_encode(['error' => $message]);
        exit;
    };
    $scope = (string) ($post['scope'] ?? '');
    $user_id = intval($session_user_id);
    if (in_array($scope, ['default', 'reset_default'], true) && empty($session_is_admin)) {
        $fail('Only an admin can change the company layout.', 403);
    }
    $layout = mitTicketLayoutClean($post['layout'] ?? '');
    if (in_array($scope, ['mine', 'default'], true) && !$layout) {
        $fail('That layout could not be read.');
    }
    try {
        if ($scope === 'mine') {
            mitSetUserPreference($user_id, 'ticket.layout', json_encode($layout));
            $message = 'Your ticket layout is saved';
        } elseif ($scope === 'default') {
            mitSetSetting('ticket_layout', json_encode($layout));
            mysqli_query($mysqli, "DELETE FROM mit_user_preferences WHERE user_id = $user_id AND preference = 'ticket.layout'");
            $message = 'Company default layout saved';
        } elseif ($scope === 'reset_mine') {
            mysqli_query($mysqli, "DELETE FROM mit_user_preferences WHERE user_id = $user_id AND preference = 'ticket.layout'");
            $message = 'You are back on the company default layout';
        } elseif ($scope === 'reset_default') {
            mysqli_query($mysqli, "DELETE FROM mit_settings WHERE setting_name = 'ticket_layout'");
            $message = 'Company default is back to ITFlow\'s layout';
        } else {
            $fail('Unknown request.');
        }
    } catch (Throwable $e) {
        $fail('The layout could not be saved - has the database update run?', 500);
    }
    echo json_encode(['ok' => true, 'message' => $message]);
    exit;
}
