<?php

/*
 * Mountaineer IT - restore a backup on a running install from Admin > Backup (ITFlow only restores in the setup wizard on a
 * fresh install, or from the command line). Required from functions/mit_common.php. The work is ITFlow's own
 * backupRestoreArchive() (functions/backup.php - the current database is dumped first and put back if the import fails);
 * around it:
 *   - a stored backup is picked from the list, or a .zip uploaded and added to the list (source "Uploaded")
 *   - before anything changes, a Full backup of the system as it is ("Before restore") - kept, so a restore can be undone
 *   - after it, the "Before restore" row is put back in the list (the restore brought back the backups table as it was)
 * The handler (admin/post/mit_backup_restore.php) then runs ITFlow's and our database updates, logs it and signs the admin out.
 * Admins only (the admin pages); the admin's own password and typing RESTORE confirm it.
 */

const MIT_RESTORE_SOURCE = 'Before restore';

/** A stored backup that can be restored: [row, path] or [null, why]. */
function mitRestoreStored($backup_id) {
    global $mysqli;
    $row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT * FROM backups WHERE backup_id = " . intval($backup_id)));
    if (!$row || $row['backup_status'] !== 'Complete') {
        return [null, 'That backup is not there, or not complete.'];
    }
    if ($row['backup_type'] === BACKUP_TYPE_MASTER_KEY) {
        return [null, 'A master key export cannot be restored.'];
    }
    $path = backupResolvePath($row['backup_file_name']);
    if ($path === false || !is_file($path)) {
        return [null, 'The backup file is missing from the server.'];
    }
    return [$row, $path];
}

/** The key to open an archive with: the one typed, else this install's. */
function mitRestoreKey($typed) {
    $typed = trim((string) $typed);
    return $typed !== '' ? $typed : backupEncryptionKey();
}

/**
 * What is in an archive, and whether it may be restored here: [meta, problem] - problem '' when it can be restored.
 * A backup from a newer database version than this code is refused (the app would break after it).
 */
function mitRestoreCheck($path, $key) {
    global $mysqli;
    $error = null;
    $meta = backupInspectArchive($path, $key, $error);
    if ($meta === false) {
        return [null, (string) $error];
    }
    $current = backupCurrentDatabaseVersion($mysqli);
    if ($meta['database_version'] !== 'Unknown' && version_compare($meta['database_version'], $current, '>')) {
        return [$meta, "This backup is from a newer database version ({$meta['database_version']}) than this install ($current) - update ITFlow first."];
    }
    return [$meta, ''];
}

/** An uploaded .zip ($_FILES entry): checked, stored with the other backups and listed. Returns [backup_id, message]. */
function mitRestoreUpload(array $file, $typed_key) {
    global $mysqli, $session_name;
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'] ?? '')) {
        $too_big = in_array($file['error'] ?? 0, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true);
        return [0, $too_big ? 'The file is bigger than this server takes (' . backupFormatBytes(backupMaxUploadBytes()) . ') - copy it to the server and use the command line restore.' : 'Pick a backup .zip to upload.'];
    }
    [$meta, $problem] = mitRestoreCheck($file['tmp_name'], mitRestoreKey($typed_key));
    if (!$meta) {
        return [0, "That is not a backup that can be read: $problem"];
    }
    $type = in_array($meta['type'], backupAllTypes(), true) ? $meta['type'] : BACKUP_TYPE_FULL;
    $name = backupBuildFileName($type, randomString(32));
    $path = backupStorageDir() . '/' . $name;
    if (!move_uploaded_file($file['tmp_name'], $path)) {
        return [0, 'The file could not be saved with the backups (is the backup folder writable?).'];
    }
    @chmod($path, 0600);
    $size = filesize($path) ?: 0;
    $sha = hash_file('sha256', $path);
    mysqli_query($mysqli, "INSERT INTO backups SET backup_type = '" . escapeSql($type) . "', backup_file_name = '" . escapeSql($name) . "', backup_size = $size,
        backup_sha256 = '$sha', backup_status = 'Complete', backup_source = 'Uploaded', backup_created_by = '" . escapeSql(stripslashes((string) $session_name)) . "',
        backup_completed_at = NOW()");
    return [intval(mysqli_insert_id($mysqli)), 'Backup uploaded (' . backupTypeLabel($type) . ', taken ' . $meta['generated'] . ').' . ($problem !== '' ? " $problem" : '')];
}

/**
 * Restore an archive over this install: a "Before restore" Full backup first, then ITFlow's restore, then the "Before
 * restore" row put back in the restored list. Returns [ok, message]. The database updates are the caller's (they are
 * included files that run at file scope).
 */
function mitRestoreRun($path, $key, $who, array $from = []) {
    global $mysqli;
    @set_time_limit(0);
    ignore_user_abort(true); // a browser or proxy that gives up must not stop it half way

    [$meta, $problem] = mitRestoreCheck($path, $key);
    if (!$meta || $problem !== '') {
        return [false, $problem];
    }
    if (!mitLock('backup_restore', 1)) {
        return [false, 'A restore is already running.'];
    }

    // The system as it is now, kept - so this can be undone
    $error = null;
    $safety_id = backupCreate($mysqli, BACKUP_TYPE_FULL, $who, MIT_RESTORE_SOURCE, $error);
    if (!$safety_id) {
        mitUnlock('backup_restore');
        return [false, 'Nothing was changed - the safety backup of the current system failed: ' . $error];
    }
    $safety = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT * FROM backups WHERE backup_id = $safety_id"));

    $error = null;
    $ok = backupRestoreArchive($mysqli, $path, $key, $error);
    $mysqli = backupDbEnsure($mysqli);
    if (!$ok) {
        mitUnlock('backup_restore');
        return [false, "Restore failed: $error"];
    }

    // The restored list is the backup's own, from while it was being made (so its row says Running) - put its row and the
    // safety copy's right (others made since are adopted by ITFlow's retention)
    foreach (array_filter([$from, $safety]) as $keep) {
        $set = [];
        foreach (['backup_type', 'backup_file_name', 'backup_size', 'backup_sha256', 'backup_status', 'backup_source', 'backup_created_by', 'backup_created_at', 'backup_completed_at'] as $column) {
            $set[] = "$column = " . ($keep[$column] === null ? 'NULL' : "'" . escapeSql($keep[$column]) . "'");
        }
        $name = escapeSql($keep['backup_file_name']);
        if (mysqli_num_rows(mysqli_query($mysqli, "SELECT 1 FROM backups WHERE backup_file_name = '$name'"))) {
            mysqli_query($mysqli, "UPDATE backups SET " . implode(', ', $set) . ", backup_error = NULL WHERE backup_file_name = '$name'");
        } else {
            mysqli_query($mysqli, "INSERT INTO backups SET " . implode(', ', $set));
        }
    }
    // A backup from another server lists the one it was making then (its file is not here) - not left as Running
    $running = mysqli_query($mysqli, "SELECT backup_id, backup_file_name FROM backups WHERE backup_status IN ('Running', 'Pending')");
    while ($row = mysqli_fetch_assoc($running)) {
        $file = backupResolvePath($row['backup_file_name']);
        if ($file === false || !is_file($file)) {
            mysqli_query($mysqli, "UPDATE backups SET backup_status = 'Failed', backup_error = 'Came with a restored backup - the file is not on this server', backup_completed_at = NOW() WHERE backup_id = " . intval($row['backup_id']));
        }
    }
    mitUnlock('backup_restore');
    return [true, backupTypeLabel($meta['type']) . ' taken ' . $meta['generated'] . ' restored. The system as it was is kept as the "' . MIT_RESTORE_SOURCE . '" backup.'];
}

/** Admin > Backup: the Restore button on a backup's row (one line in admin/backup.php). */
function mitRestoreRowButton(array $backup) {
    if ($backup['backup_status'] !== 'Complete' || $backup['backup_type'] === BACKUP_TYPE_MASTER_KEY) {
        return '';
    }
    return '<button type="button" class="btn btn-sm btn-warning ajax-modal" title="Restore this backup" data-modal-url="modals/mit_backup/restore.php?backup_id='
        . intval($backup['backup_id']) . '"><i class="fas fa-fw fa-undo"></i></button>';
}
