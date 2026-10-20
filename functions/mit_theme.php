<?php

/*
 * Mountaineer IT - the modern look: css/mit_theme.css + js/mit_theme.js on top of ITFlow's own styles, for agent,
 * admin, account and report pages (includes/header.php, one line). Each agent picks Modern (the default) or Classic
 * under Account > Preferences, saved as the ui.look preference (mit_user_preferences). With ITFlow's Dark theme on,
 * the classic dark look is used - the modern look is light only.
 */

const MIT_LOOKS = ['modern' => 'Modern', 'classic' => 'Classic ITFlow'];

/** The look an agent picked: 'modern' (default) or 'classic'. */
function mitLook($user_id) {
    $look = (string) mitUserPreference($user_id, 'ui.look', 'modern');
    return isset(MIT_LOOKS[$look]) ? $look : 'modern';
}

/** The stylesheet and script for the modern look, for includes/header.php - '' for Classic, Dark or nobody signed in. */
function mitThemeHead() {
    global $session_user_id, $user_config_theme_dark;
    if (empty($session_user_id) || !empty($user_config_theme_dark) || mitLook($session_user_id) !== 'modern') {
        return '';
    }
    return '<link rel="stylesheet" href="' . mitAsset('/css/mit_theme.css') . '">'
        . '<script src="' . mitAsset('/js/mit_theme.js') . '" defer></script>';
}

/** The Look choice on Account > Preferences. */
function mitThemePreferenceField($user_id) {
    $look = mitLook($user_id);
    $html = '<div class="col-md-6"><label class="form-label" for="mitLook">Look</label><div class="input-group">'
        . '<span class="input-group-text"><i class="fas fa-fw fa-palette"></i></span><select class="form-select" name="mit_look" id="mitLook">';
    foreach (MIT_LOOKS as $value => $label) {
        $html .= '<option value="' . $value . '"' . ($look === $value ? ' selected' : '') . '>' . $label . '</option>';
    }
    return $html . '</select></div><div class="form-text">Modern is used with the Light theme; Dark keeps ITFlow\'s dark look.</div></div>';
}

/** Save it (a no-op when the form did not have it). */
function mitThemeSavePreference($user_id, array $post) {
    if (isset($post['mit_look'])) {
        mitSetUserPreference($user_id, 'ui.look', isset(MIT_LOOKS[$post['mit_look']]) ? $post['mit_look'] : 'modern');
    }
}
