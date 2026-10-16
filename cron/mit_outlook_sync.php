<?php
/*
 * Mountaineer IT - keep each person's Outlook calendar in line with their booked tickets
 * (functions/mit_outlook.php). Every minute; only people whose bookings changed, or whose
 * 15-minute check of Outlook is due, cost a call to Microsoft.
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

$row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT config_enable_cron FROM settings WHERE company_id = 1"));
if (intval($row['config_enable_cron']) === 0) {
    cronJobStop("Cron: is not enabled -- Quitting..");
}

if (!mitOutlookAvailable()) {
    cronJobStop();
}

$mit_outlook_users = [];
$mit_outlook_result = mysqli_query($mysqli, "SELECT user_id FROM mit_outlook_links JOIN users USING (user_id) WHERE user_archived_at IS NULL");
while ($mit_outlook_result && $mit_outlook_row = mysqli_fetch_assoc($mit_outlook_result)) {
    $mit_outlook_users[] = intval($mit_outlook_row['user_id']);
}

foreach ($mit_outlook_users as $mit_outlook_user_id) {
    try {
        $mit_outlook_summary = mitOutlookSync($mit_outlook_user_id);
        if ($mit_outlook_summary !== 'Up to date') {
            echo "Outlook sync user $mit_outlook_user_id: $mit_outlook_summary\n";
        }
    } catch (Throwable $e) {
        // Kept on the person's link and shown on their Outlook calendar page; logged once, not every minute
        echo "Outlook sync user $mit_outlook_user_id failed: " . $e->getMessage() . "\n";
    }
}
