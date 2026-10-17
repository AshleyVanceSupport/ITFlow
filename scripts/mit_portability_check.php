<?php

/*
 * Mountaineer IT - can this database still go back to stock ITFlow?
 *
 * A backup made here should restore into ITFlow's own release and keep working (DEPLOYMENT.md,
 * "Moving to stock ITFlow"). That holds as long as our changes to the database only ever add:
 * our own mit_ tables, and columns on ITFlow's tables that stock ITFlow can ignore. This compares
 * the live database with ITFlow's schema for this code (db.sql - which we never edit) and says
 * what would break, and lists the things that stop working after a move.
 *
 * Usage: php scripts/mit_portability_check.php     (exit code 0 = portable, 1 = not)
 *
 * Run it after every feature change that touches the database, after every ITFlow update
 * merge, and before promoting develop to production (CLAUDE.md).
 */

chdir(dirname(__FILE__));

if (php_sapi_name() !== 'cli') {
    die("This script must be run from the command line.\n");
}

require_once "../config.php";
require_once "../includes/database_version.php";

$problems = [];
$warnings = [];
$notes = [];

/** ITFlow's tables and columns from db.sql: table => [column => definition], plus foreign keys. */
function mitStockSchema($file) {
    $tables = [];
    $table = null;
    foreach (file($file) as $line) {
        if (preg_match('/^CREATE TABLE `([^`]+)`/', $line, $m)) {
            $table = $m[1];
            $tables[$table] = [];
        } elseif ($table && preg_match('/^\s+`([^`]+)`\s+(.+?),?\s*$/', $line, $m)) {
            $tables[$table][$m[1]] = $m[2];
        } elseif ($table && preg_match('/^\)/', $line)) {
            $table = null;
        }
    }
    return $tables;
}

$stock = mitStockSchema(dirname(__DIR__) . '/db.sql');
if (count($stock) < 50) {
    fwrite(STDERR, "Could not read ITFlow's schema from db.sql.\n");
    exit(1);
}

$db = mysqli_real_escape_string($mysqli, $database);
$live = [];
$result = mysqli_query($mysqli, "SELECT TABLE_NAME, COLUMN_NAME, IS_NULLABLE, COLUMN_DEFAULT, EXTRA, GENERATION_EXPRESSION
    FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = '$db' ORDER BY TABLE_NAME, ORDINAL_POSITION");
while ($row = mysqli_fetch_assoc($result)) {
    $live[$row['TABLE_NAME']][$row['COLUMN_NAME']] = $row;
}

// The database should be at the version this code expects, or the comparison is with the wrong schema
$version_row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT config_current_database_version FROM settings WHERE company_id = 1"));
$version = $version_row['config_current_database_version'] ?? 'unknown';
if ($version !== LATEST_DATABASE_VERSION) {
    $warnings[] = "The database is at $version but this code expects " . LATEST_DATABASE_VERSION . " - run the database update first, then check again.";
}

// 1. Everything ITFlow has must still be there
foreach ($stock as $table => $columns) {
    if (!isset($live[$table])) {
        $problems[] = "ITFlow's table `$table` is missing.";
        continue;
    }
    foreach ($columns as $column => $definition) {
        if (!isset($live[$table][$column])) {
            $problems[] = "ITFlow's column `$table`.`$column` is missing.";
        }
    }
}

// 2. Columns we added to ITFlow's tables: stock ITFlow never fills them, so they must accept being left out
foreach ($stock as $table => $columns) {
    foreach ($live[$table] ?? [] as $column => $info) {
        if (isset($columns[$column])) {
            continue;
        }
        $optional = $info['IS_NULLABLE'] === 'YES' || $info['COLUMN_DEFAULT'] !== null
            || stripos($info['EXTRA'], 'auto_increment') !== false || !empty($info['GENERATION_EXPRESSION']);
        if (!$optional) {
            $problems[] = "Our column `$table`.`$column` is NOT NULL with no default - stock ITFlow's inserts into `$table` would fail.";
        } else {
            $notes[] = "Extra column `$table`.`$column` (ours - stock ITFlow ignores it).";
        }
    }
}

// 3. Tables that are not ITFlow's must be ours (mit_), so it is clear what can be ignored or dropped
foreach (array_keys($live) as $table) {
    if (!isset($stock[$table]) && strpos($table, 'mit_') !== 0) {
        $warnings[] = "Table `$table` is neither ITFlow's (db.sql) nor ours (mit_) - check where it came from.";
    }
}

// 4. ITFlow's tables must not depend on ours (a foreign key into mit_ would block stock ITFlow's deletes/inserts)
$result = mysqli_query($mysqli, "SELECT TABLE_NAME, CONSTRAINT_NAME, REFERENCED_TABLE_NAME FROM information_schema.KEY_COLUMN_USAGE
    WHERE TABLE_SCHEMA = '$db' AND REFERENCED_TABLE_NAME LIKE 'mit\\_%'");
while ($row = mysqli_fetch_assoc($result)) {
    if (strpos($row['TABLE_NAME'], 'mit_') !== 0) {
        $problems[] = "ITFlow's table `{$row['TABLE_NAME']}` has a foreign key ({$row['CONSTRAINT_NAME']}) into our `{$row['REFERENCED_TABLE_NAME']}`.";
    }
}

// What stops working after a move (works again on moving back) - see DEPLOYMENT.md
$settings = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT config_mail_oauth_client_secret, config_smtp_provider, config_imap_provider FROM settings WHERE company_id = 1"));
$after = [];
if (($settings['config_mail_oauth_client_secret'] ?? '') === 'mit-m365-certificate') {
    $after[] = "Microsoft 365 email: signs in with our certificate and sends/receives through Graph. On stock ITFlow add a client secret to the app in Entra and paste it under Settings > Mail > OAuth (and SMTP AUTH / IMAP must be enabled for the mailbox).";
}
$count = function ($sql) use ($mysqli) {
    $r = @mysqli_query($mysqli, $sql);
    return $r ? intval(mysqli_fetch_row($r)[0]) : 0;
};
if ($n = $count("SELECT COUNT(*) FROM mit_outlook_links")) {
    $after[] = "Outlook calendars: $n connected - sync stops; ITFlow's events already in Outlook stay until deleted (they have the ITFlow category).";
}
if ($n = $count("SELECT COUNT(*) FROM tickets WHERE ticket_schedule_end IS NOT NULL")) {
    $after[] = "Booking lengths: $n bookings have an end time - stock ITFlow shows its default length instead.";
}
$n = $count("SELECT COUNT(*) FROM recurring_tickets WHERE recurring_ticket_by_day IS NOT NULL") + $count("SELECT COUNT(*) FROM calendar_events WHERE event_repeat_by_day IS NOT NULL");
if ($n) {
    $after[] = "Repeats by weekday: $n recurring tickets / events repeat on e.g. the third Wednesday - stock ITFlow repeats them on the same date each month instead.";
}
$n = $count("SELECT COUNT(*) FROM mit_purchase_orders");
if ($n || $count("SELECT COUNT(*) FROM modules WHERE module_name = 'module_procurement'")) {
    $after[] = "Procurement: $n purchase order" . ($n === 1 ? '' : 's') . " and the items to order are not shown by stock ITFlow (they stay in the mit_ tables; PO PDFs in uploads/mit_po). Admin > Roles keeps an unused \"Procurement\" permission - delete its row from the modules table if it bothers you.";
}
if ($n = $count("SELECT COUNT(*) FROM mit_ticket_parts")) {
    $after[] = "Ticket parts: $n part" . ($n === 1 ? '' : 's') . " on tickets are not shown by stock ITFlow (billed ones are ordinary invoice lines and stay). Products' \"Order from\" vendor is not shown either.";
}
if ($n = $count("SELECT COUNT(*) FROM mit_ticket_techs tt JOIN tickets t ON t.ticket_id = tt.ticket_id WHERE t.ticket_resolved_at IS NULL AND t.ticket_closed_at IS NULL")) {
    $after[] = "Additional techs: $n helpers on open tickets - stock ITFlow keeps only the lead (assigned) tech; helpers and their own bookings are not shown.";
}
$n = $count("SELECT COUNT(*) FROM quote_items WHERE item_cost IS NOT NULL OR item_image IS NOT NULL") + $count("SELECT COUNT(*) FROM products WHERE product_cost IS NOT NULL OR product_image IS NOT NULL");
if ($n) {
    $after[] = "Quote pricing and images: $n quote lines / products have our cost or an image - stock ITFlow shows the customer prices only, without the images (the files stay in uploads/mit_images).";
}
if ($n = $count("SELECT COUNT(*) FROM mit_user_preferences")) {
    $after[] = "Per-person settings - calendar view, tech colours, ticket page layout ($n saved) - are not used by stock ITFlow.";
}

echo "Stock ITFlow portability check - database $version, ITFlow schema " . LATEST_DATABASE_VERSION . " (db.sql)\n\n";
foreach ($problems as $line) {
    echo "  FAIL  $line\n";
}
foreach ($warnings as $line) {
    echo "  WARN  $line\n";
}
foreach ($notes as $line) {
    echo "  ok    $line\n";
}
echo $problems ? "\nNOT portable - a backup of this database would break stock ITFlow. Fix the FAIL lines.\n"
    : "\nPortable - a backup of this database restores into stock ITFlow " . LATEST_DATABASE_VERSION . " (or newer) and keeps working.\n";
if ($after) {
    echo "\nAfter moving to stock ITFlow, redo or expect:\n";
    foreach ($after as $line) {
        echo "  - $line\n";
    }
}
exit($problems ? 1 : 0);
