<?php
/*
 * Mountaineer IT - timesheet reminders (functions/mit_timesheets.php): on a pay period's last day, and
 * when the last period is still not submitted a set number of days after it ended - both set in
 * Admin > Timesheets, each sent once. People can turn them off in Account > Preferences.
 */

// Set working directory to the directory this cron script lives at.
chdir(dirname(__FILE__));

// Ensure we're running from command line
if (php_sapi_name() !== 'cli') {
    die("This script must be run from the command line.\n");
}

// Prevent overlapping runs of this script
$cron_lock_script = __FILE__;
require_once "includes/cron_lock.php";

require_once "../config.php";
require_once "../includes/inc_set_timezone.php";
require_once "../functions.php";
require_once "../includes/load_global_settings.php";

$row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT config_enable_cron FROM settings WHERE company_id = 1"));
if (intval($row['config_enable_cron']) === 0) {
    cronJobStop("Cron: is not enabled -- Quitting..");
}

if (!mitTsReady()) {
    cronJobStop();
}

foreach (mitTsSendReminders() as $mit_ts_line) {
    echo "$mit_ts_line\n";
}
