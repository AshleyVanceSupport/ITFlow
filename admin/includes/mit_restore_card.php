<?php
/*
 * Mountaineer IT - Admin > Backup, "Restoring" box: restore from here (functions/mit_backup_restore.php). Required from
 * admin/backup.php. Restore (the undo button) on a backup in the list, or upload a .zip - it is added to the list and its
 * Restore window opens (?mit_restore=<backup id>).
 */
$mit_restore_open = intval($_GET['mit_restore'] ?? 0);
?>
<p><strong>From here</strong> - press <span class="badge text-bg-warning"><i class="fas fa-fw fa-undo"></i></span> on a backup above, or upload one
    (a backup from this or another ITFlow server, up to <?= escapeHtml(backupFormatBytes(backupMaxUploadBytes())) ?>). You see what is in it before
    anything changes; a Full backup of the system as it is now is kept as "Before restore", and everyone signs in again afterwards.</p>
<form action="post.php" method="post" enctype="multipart/form-data" class="row g-2 align-items-end mb-4" autocomplete="off">
    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
    <div class="col-md-5">
        <label class="form-label small mb-1">Backup file (.zip)</label>
        <input type="file" class="form-control" name="backup_file" accept=".zip,application/zip" required>
    </div>
    <div class="col-md-4">
        <label class="form-label small mb-1">Its encryption key <span class="text-muted">(blank = this install's)</span></label>
        <input type="password" class="form-control" name="restore_key" autocomplete="off">
    </div>
    <div class="col-md-3">
        <button type="submit" name="mit_backup_upload" class="btn btn-outline-primary w-100"><i class="fas fa-fw fa-upload me-2"></i>Upload to restore</button>
    </div>
</form>
<?php if ($mit_restore_open) { ?>
    <button type="button" class="d-none ajax-modal" data-mit-restore-open data-modal-url="modals/mit_backup/restore.php?backup_id=<?= $mit_restore_open ?>"></button>
    <script>window.addEventListener('load', function () { document.querySelector('[data-mit-restore-open]').click(); });</script>
<?php } ?>
