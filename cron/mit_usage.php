<?php
/*
 * Mountaineer IT - usage billing, daily (functions/mit_usage.php): records each client's count of contacts carrying a usage
 * line's tag, so the snapshot day has a count to bill. Counts reported through the API or typed need nothing from here.
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

if (!mitUsageReady()) {
    cronJobStop("Usage billing needs the database update");
} else {
    echo 'Usage billing: ' . mitUsageRecordTagCounts() . " new tag counts recorded\n";
}
