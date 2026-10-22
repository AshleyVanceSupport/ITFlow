<?php

/*
 * Mountaineer IT - our own database updates, kept apart from ITFlow's.
 *
 * ITFlow numbers its updates sequentially (admin/database_updates/2.8.9.php ...), so an
 * update of ours in that sequence would collide with their next release. Ours live in
 * admin/database_updates/mountaineerit/ as M001.php, M002.php ... and are tracked by name
 * in the mit_database_updates table, so they are independent of ITFlow's version number.
 *
 * Runs after ITFlow's updates (scripts/update_cli.php) and at the end of a fresh install
 * (setup/index.php, scripts/setup_cli.php), so every install ends up with them.
 *
 * Each update file is applied once, in name order, and must be safe to run again (check
 * before adding a column or table) - DDL commits as it goes, so a file that fails half way
 * is retried from the start. A failure stops the run; later files wait for the next one.
 *
 * Expects $mysqli. Sets $mit_updates_applied (names applied this run) and
 * $mit_updates_error ("name: message" or null).
 */

// Only ever included by the updater or setup, never opened in a browser
if (php_sapi_name() !== 'cli' && !defined('FROM_SETUP') && !defined('MIT_FROM_RESTORE')) { // MIT_FROM_RESTORE: Admin > Backup restore
    echo "Cannot access this file directly.";
    exit();
}

defined('ITFLOW_MIT_DB_UPDATES') || define('ITFLOW_MIT_DB_UPDATES', true);

/** Does a column exist? For update files that must be safe to run twice. */
if (!function_exists('mitColumnExists')) {
    function mitColumnExists($mysqli, $table, $column) {
        $table = mysqli_real_escape_string($mysqli, $table);
        $column = mysqli_real_escape_string($mysqli, $column);
        $result = mysqli_query($mysqli, "SHOW COLUMNS FROM `$table` LIKE '$column'");
        return $result && mysqli_num_rows($result) > 0;
    }
}

/** Does an index exist on a table? */
if (!function_exists('mitIndexExists')) {
    function mitIndexExists($mysqli, $table, $index) {
        $table = mysqli_real_escape_string($mysqli, $table);
        $index = mysqli_real_escape_string($mysqli, $index);
        $result = mysqli_query($mysqli, "SHOW INDEX FROM `$table` WHERE Key_name = '$index'");
        return $result && mysqli_num_rows($result) > 0;
    }
}

$mit_updates_applied = [];
$mit_updates_error = null;

mysqli_query($mysqli, "CREATE TABLE IF NOT EXISTS `mit_database_updates` (
    `mit_update_name` varchar(50) NOT NULL,
    `mit_update_applied_at` datetime NOT NULL DEFAULT current_timestamp(),
    PRIMARY KEY (`mit_update_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

$mit_update_done = [];
$mit_update_result = mysqli_query($mysqli, "SELECT mit_update_name FROM mit_database_updates");
while ($mit_update_row = mysqli_fetch_assoc($mit_update_result)) {
    $mit_update_done[$mit_update_row['mit_update_name']] = true;
}

$mit_update_files = glob(__DIR__ . "/database_updates/mountaineerit/M[0-9][0-9][0-9].php") ?: [];
sort($mit_update_files, SORT_STRING);

foreach ($mit_update_files as $mit_update_file) {
    $mit_update_name = basename($mit_update_file, ".php");
    if (isset($mit_update_done[$mit_update_name])) {
        continue;
    }

    try {
        require $mit_update_file;
        // IGNORE: a second run that got here at the same time (two cron updates) is not a failure
        mysqli_query($mysqli, "INSERT IGNORE INTO mit_database_updates SET mit_update_name = '" . mysqli_real_escape_string($mysqli, $mit_update_name) . "'");
    } catch (Throwable $e) {
        $mit_updates_error = "$mit_update_name: " . $e->getMessage();
        error_log("Mountaineer IT database update $mit_update_name failed: " . $e->getMessage());
        break;
    }
    $mit_updates_applied[] = $mit_update_name;
}
