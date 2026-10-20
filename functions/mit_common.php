<?php

/*
 * Mountaineer IT - small helpers shared by our features (the mit_*.php files in this folder).
 * Required from functions.php before them.
 */

/**
 * An HTML email body made ready for addToMailQueue(), which takes SQL-escaped values. The pieces inside
 * must already be escapeHtml()'d; this only escapes the whole for SQL. escapeSql() is not usable here:
 * it runs strip_tags() and would take out the line breaks and links (as ticketMailSchedule() does it).
 */
function mitMailBody($html) {
    return mitSqlHtml($html);
}

/**
 * HTML for a column that holds HTML (email bodies, ticket replies), escaped for SQL only - the pieces inside
 * must already be escapeHtml()'d. escapeSql() would strip_tags() it.
 */
function mitSqlHtml($html) {
    global $mysqli;
    return mysqli_real_escape_string($mysqli, (string) $html);
}

/** Is this product a tangible one (ITFlow keeps stock only for those)? */
function mitProductIsTangible($product_id) {
    return intval($product_id) > 0 && getFieldById('products', intval($product_id), 'product_type') === 'product';
}

/**
 * Is one of our modules switched on (Admin > Settings > Modules - admin/includes/mit_modules_card.php)?
 * $name: procurement | timesheets | dispatch. On unless switched off; saved in mit_settings as module_<name>.
 * Procurement also needs ITFlow's invoicing module, and dispatch its ticketing module.
 */
function mitModuleOn($name) {
    global $config_module_enable_accounting, $config_module_enable_ticketing;
    if ($name === 'procurement' && intval($config_module_enable_accounting ?? 1) !== 1) {
        return false;
    }
    if ($name === 'dispatch' && intval($config_module_enable_ticketing ?? 1) !== 1) {
        return false;
    }
    return mitSetting("module_$name", '1') !== '0';
}

/**
 * Stop here when one of our modules is switched off: a page shows why and ends (after inc_all.php), a modal
 * answers with a JSON error, as ITFlow's modals do. ITFlow itself only hides a switched-off module's menus; ours
 * also close their pages, so a bookmark does not lead into a feature that is meant to be off.
 */
function mitModuleRequire($name, $modal = false) {
    if (mitModuleOn($name)) {
        return;
    }
    $text = ucfirst($name) . ' is switched off (Admin > Settings > Modules).';
    if ($modal) {
        exit(json_encode(['error' => $text]));
    }
    echo '<div class="alert alert-warning">' . escapeHtml($text) . '</div>';
    require_once __DIR__ . '/../includes/footer.php';
    exit;
}

/**
 * For a handler file (agent/post/): when the module is switched off and the request is one of its own (a GET or
 * POST key starting with one of $prefixes), say so and go back. Other requests pass by untouched, since every
 * handler file is loaded for every request.
 */
function mitModuleGuard($name, array $prefixes) {
    if (mitModuleOn($name)) {
        return;
    }
    foreach (array_keys($_GET + $_POST) as $key) {
        foreach ($prefixes as $prefix) {
            if (strpos((string) $key, $prefix) === 0) {
                flashAlert(escapeHtml(ucfirst($name) . ' is switched off (Admin > Settings > Modules).'), 'error');
                redirect();
            }
        }
    }
}

/** Our admin settings pages, so ITFlow's SETTINGS menu opens on them (admin/includes/side_nav.php). */
function mitAdminSettingsPages() {
    return ['mit_procurement_settings.php', 'mit_timesheets.php'];
}

/** Save our module switches from Admin > Settings > Modules (hook in admin/post/settings_module.php). */
function mitModulesSave(array $post) {
    foreach (['procurement', 'timesheets', 'dispatch'] as $name) {
        if (isset($post["mit_module_$name"])) {
            mitSetSetting("module_$name", $post["mit_module_$name"] === '1' ? '1' : '0');
        }
    }
}

/**
 * The system mail From address and name, read from the settings as stored. Not the $config_mail_from_* globals: a
 * cron job before ours (nightly_tasks) SQL-escapes those in the shared scope, so "O'Brien" would go out as "O\'Brien".
 */
function mitMailFrom() {
    global $mysqli;
    static $from = null;
    if ($from === null) {
        $row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT config_mail_from_email, config_mail_from_name FROM settings WHERE company_id = 1")) ?: [];
        $from = ['email' => (string) ($row['config_mail_from_email'] ?? ''), 'name' => (string) ($row['config_mail_from_name'] ?? '')];
    }
    return $from;
}

/**
 * After a restore (setup/index.php, scripts/restore_cli.php): ITFlow's own database updates up to this release, as
 * update_cli runs them, so ours come after. Returns '' or "version: error".
 */
function mitRestoreItflowUpdates() {
    global $mysqli;
    defined('LATEST_DATABASE_VERSION') || require __DIR__ . '/../includes/database_version.php';
    $row = mysqli_fetch_row(mysqli_query($mysqli, "SELECT config_current_database_version FROM settings LIMIT 1"));
    $current = trim((string) ($row[0] ?? ''));
    if ($current === '' || !version_compare($current, LATEST_DATABASE_VERSION, '<') || defined('CURRENT_DATABASE_VERSION')) {
        return '';
    }
    define('CURRENT_DATABASE_VERSION', $current);
    $database_updates_applied = [];
    $database_updates_error = null;
    require __DIR__ . '/../admin/database_updates.php';
    return (string) $database_updates_error;
}

/**
 * A named database lock (MySQL GET_LOCK) so two requests can't do the same thing at once - a double click, two approvers.
 * $name is made safe here. Returns true when it was taken within $timeout seconds; release it with mitUnlock().
 */
function mitLock($name, $timeout = 15) {
    global $mysqli;
    $name = 'mit_' . substr(preg_replace('/[^a-z0-9_]/', '_', strtolower((string) $name)), 0, 56);
    $row = mysqli_fetch_row(mysqli_query($mysqli, "SELECT GET_LOCK('$name', " . intval($timeout) . ")"));
    return intval($row[0] ?? 0) === 1;
}

function mitUnlock($name) {
    global $mysqli;
    $name = 'mit_' . substr(preg_replace('/[^a-z0-9_]/', '_', strtolower((string) $name)), 0, 56);
    mysqli_query($mysqli, "SELECT RELEASE_LOCK('$name')");
}

/**
 * The address of one of our scripts with its file time on the end (/js/x.js?v=1729...), so a browser
 * fetches it again after an update instead of running the copy it kept.
 */
function mitAsset($path) {
    $file = dirname(__DIR__) . $path;
    return escapeHtml($path . (is_file($file) ? '?v=' . filemtime($file) : ''));
}

require_once dirname(__DIR__) . '/includes/mit_version.php';

/** Our build's version (26.10.1 ...) - includes/mit_version.php. */
function mitVersion() {
    return MIT_VERSION;
}

/**
 * Our release notes from MIT_CHANGELOG.md, newest first: [['version', 'date', 'items' => [lines]]].
 * A release is a "## <version> - <date>" heading followed by "- " lines.
 */
function mitChangelog() {
    $file = dirname(__DIR__) . '/MIT_CHANGELOG.md';
    $releases = [];
    foreach (is_readable($file) ? file($file, FILE_IGNORE_NEW_LINES) : [] as $line) {
        if (preg_match('/^##\s+([0-9][0-9.]*)\s*(?:-\s*(.+))?$/', $line, $m)) {
            $releases[] = ['version' => $m[1], 'date' => trim($m[2] ?? ''), 'items' => []];
        } elseif ($releases && preg_match('/^\s*-\s+(.+)$/', $line, $m)) {
            $releases[count($releases) - 1]['items'][] = trim(str_replace('`', '', $m[1]));
        }
    }
    return $releases;
}
