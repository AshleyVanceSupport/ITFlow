<?php
defined('FROM_POST_HANDLER') || die("Direct file access is not allowed");

$date = escapeSql($_POST['date']);
$source = escapeSql($_POST['source']);
$destination = escapeSql($_POST['destination']);
$miles = floatval($_POST['miles']);
$roundtrip = intval($_POST['roundtrip'] ?? 0);
$purpose = escapeSql($_POST['purpose']);
$user_id = intval($_POST['user']);
// Only an active agent (or nobody) - not any user id posted by hand
if ($user_id && !mysqli_num_rows(mysqli_query($mysqli, "SELECT 1 FROM users WHERE user_id = $user_id AND user_type = 1 AND user_archived_at IS NULL"))) {
    $user_id = 0;
}
