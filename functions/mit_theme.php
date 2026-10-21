<?php

/*
 * Mountaineer IT - the modern look: css/mit_theme.css + js/mit_theme.js on top of ITFlow's own styles, for agent,
 * admin, account and report pages (includes/header.php, one line). Each agent picks Modern (the default) or Classic
 * under Account > Preferences, saved as the ui.look preference (mit_user_preferences); it follows ITFlow's Light / Dark.
 * With it on, a client's pages also get count tiles and a contact line in the header (functions/mit_client_tiles.php).
 */

const MIT_LOOKS = ['modern' => 'Modern', 'classic' => 'Classic ITFlow'];

/** The look an agent picked: 'modern' (default) or 'classic'. */
function mitLook($user_id) {
    $look = (string) mitUserPreference($user_id, 'ui.look', 'modern');
    return isset(MIT_LOOKS[$look]) ? $look : 'modern';
}

/** True when the signed-in agent sees the modern look on this page. */
function mitLookOn() {
    global $session_user_id;
    static $on = null;
    if ($on === null) {
        $on = !empty($session_user_id) && mitLook($session_user_id) === 'modern';
    }
    return $on;
}

/** For includes/header.php: the time-field script (everyone), and the modern look's stylesheet and script (not for Classic or nobody signed in). */
function mitThemeHead() {
    // Time fields (clock icon = now, AM / PM from now) - for every look
    $time = '<script src="' . mitAsset('/js/mit_time_inputs.js') . '" defer></script>';
    if (!mitLookOn()) {
        return $time;
    }
    return $time . '<link rel="stylesheet" href="' . mitAsset('/css/mit_theme.css') . '">'
        . '<script src="' . mitAsset('/js/mit_theme.js') . '" defer></script>';
}

/** The sign-in screens' backdrop and card (login.php, client/login_reset.php) - for everyone, before anyone is signed in. */
function mitLoginHead() {
    return '<link rel="stylesheet" href="' . mitAsset('/css/mit_login.css') . '">';
}

/** The Look choice on Account > Preferences. */
function mitThemePreferenceField($user_id) {
    $look = mitLook($user_id);
    $html = '<div class="col-md-6"><label class="form-label" for="mitLook">Look</label><div class="input-group">'
        . '<span class="input-group-text"><i class="fas fa-fw fa-palette"></i></span><select class="form-select" name="mit_look" id="mitLook">';
    foreach (MIT_LOOKS as $value => $label) {
        $html .= '<option value="' . $value . '"' . ($look === $value ? ' selected' : '') . '>' . $label . '</option>';
    }
    return $html . '</select></div><div class="form-text">Modern works with both Light and Dark.</div></div>';
}

/** Save it (a no-op when the form did not have it). */
function mitThemeSavePreference($user_id, array $post) {
    if (isset($post['mit_look'])) {
        mitSetUserPreference($user_id, 'ui.look', isset(MIT_LOOKS[$post['mit_look']]) ? $post['mit_look'] : 'modern');
    }
}

require_once __DIR__ . '/mit_client_tiles.php';
