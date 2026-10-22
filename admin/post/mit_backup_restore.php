<?php

/*
 * Mountaineer IT - Admin > Backup: upload a backup, and restore one over this install (functions/mit_backup_restore.php).
 * Admins only (admin/post.php).
 */

defined('FROM_POST_HANDLER') || die("Direct file access is not allowed");

if (isset($_POST['mit_backup_upload'])) {

    validateCSRFToken();

    [$backup_id, $message] = mitRestoreUpload($_FILES['backup_file'] ?? [], $_POST['restore_key'] ?? '');
    if ($backup_id) {
        logAudit("Backup", "Upload", escapeSql(stripslashes((string) $session_name) . " uploaded a backup to restore"));
        flashAlert(escapeHtml($message) . ' Check it and confirm below.');
        redirect("backup.php?mit_restore=$backup_id");
    }
    flashAlert(escapeHtml($message), 'error');
    redirect("backup.php");
}

if (isset($_POST['mit_backup_restore'])) {

    validateCSRFToken();

    $who = stripslashes((string) $session_name);
    $row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT user_password FROM users WHERE user_id = " . intval($session_user_id)));
    if (!$row || !password_verify((string) ($_POST['password'] ?? ''), $row['user_password'])) {
        logAudit("Backup", "Restore", escapeSql("$who tried to restore a backup with a wrong password"));
        flashAlert('Incorrect password - nothing was changed.', 'error');
        redirect("backup.php");
    }
    if (($_POST['confirm'] ?? '') !== 'RESTORE') {
        flashAlert('Type RESTORE to confirm - nothing was changed.', 'error');
        redirect("backup.php");
    }
    [$backup, $path] = mitRestoreStored($_POST['backup_id'] ?? 0);
    if (!$backup) {
        flashAlert(escapeHtml($path), 'error');
        redirect("backup.php");
    }

    // Other tabs of this agent must not wait on the session lock for minutes
    session_write_close();
    [$ok, $message] = mitRestoreRun($path, mitRestoreKey($_POST['restore_key'] ?? ''), $who, $backup);
    if (!$ok) {
        session_start();
        logAudit("Backup", "Restore", escapeSql("$who could not restore " . $backup['backup_file_name'] . ": $message"));
        flashAlert(escapeHtml($message), 'error');
        redirect("backup.php");
    }

    // The restored database may be older than this code - ITFlow's updates, then ours (as scripts/restore_cli.php)
    $warnings = [];
    $mit_itflow_error = mitRestoreItflowUpdates();
    if ($mit_itflow_error !== '') {
        $warnings[] = "ITFlow's database update stopped at $mit_itflow_error - run Admin > Update to finish.";
    } else {
        define('MIT_FROM_RESTORE', true);
        require __DIR__ . "/../database_updates_mountaineerit.php";
        if ($mit_updates_error) {
            $warnings[] = "Mountaineer IT database update stopped at $mit_updates_error - run Admin > Update to retry.";
        }
    }
    logAudit("Backup", "Restore", escapeSql("$who restored " . $backup['backup_file_name'] . " from Admin > Backup"));

    // Everyone signs in again - the user list is the backup's now
    session_start();
    $_SESSION = [];
    session_destroy();
    ?>
    <!DOCTYPE html>
    <html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Backup restored</title>
    <link rel="stylesheet" href="/libs/adminlte/css/adminlte.min.css"></head>
    <body class="bg-body-tertiary"><div class="container py-5" style="max-width: 640px">
        <div class="card shadow-sm"><div class="card-body p-4" data-mit-restored>
            <h4 class="mb-3">Backup restored</h4>
            <p><?= escapeHtml($message) ?></p>
            <?php foreach ($warnings as $warning) { ?><div class="alert alert-warning"><?= escapeHtml($warning) ?></div><?php } ?>
            <p class="text-muted">You have been signed out. Sign in with an account from the restored backup.</p>
            <a href="/login.php" class="btn btn-primary">Sign in</a>
        </div></div>
    </div></body></html>
    <?php
    exit;
}
