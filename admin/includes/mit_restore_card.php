<?php
/*
 * Mountaineer IT - Admin > Backup, "Restoring" box: restore from here (functions/mit_backup_restore.php). Required from
 * admin/backup.php. Restore (the undo button) on a backup in the list, or upload a .zip - it is added to the list and its
 * Restore window opens (?mit_restore=<backup id>).
 */
$mit_restore_open = intval($_GET['mit_restore'] ?? 0);
?>
<div class="border rounded p-3 mb-4 <?= mitDevMode() ? 'border-danger' : '' ?>" data-mit-dev-card>
    <h6 class="mb-1"><i class="fas fa-fw fa-flask me-2"></i>Development mode <?= mitDevMode() ? '<span class="badge text-bg-danger ms-1">On</span>' : '<span class="badge text-bg-secondary ms-1">Off</span>' ?></h6>
    <p class="small text-muted mb-2">Turn this on <strong>before</strong> restoring production's backup onto a test server. Every email then goes to the test inbox
        instead of clients and staff; the ticket mailbox is not read; Outlook, QuickBooks, Stripe card payments and custom actions are switched off.
        It is kept in this server's config.php, so a restore cannot switch it off. Never turn it on in production.</p>
    <form action="post.php" method="post" class="row g-2 align-items-end" autocomplete="off">
        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
        <div class="col-md-3">
            <div class="form-check form-switch mb-2">
                <input class="form-check-input" type="checkbox" role="switch" name="dev_mode" value="1" id="mitDevMode" <?= mitDevMode() ? 'checked' : '' ?>>
                <label class="form-check-label" for="mitDevMode">Development mode</label>
            </div>
        </div>
        <div class="col-md-6">
            <label class="form-label small mb-1">Test inbox - every email goes here</label>
            <input type="email" class="form-control" name="dev_mail_to" value="<?= escapeHtml(mitDevMailTo()) ?>" placeholder="dev@yourcompany.com">
        </div>
        <div class="col-md-3">
            <button type="submit" name="mit_dev_mode_save" class="btn btn-outline-danger w-100"><i class="fas fa-fw fa-check me-2"></i>Save</button>
        </div>
    </form>
</div>
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
