<?php

/*
 * Mountaineer IT - add or change a charge code (admin/mit_charge_codes.php).
 */

require_once '../../includes/modal_header.php';

$code_id = intval($_GET['id'] ?? 0);
$code = $code_id ? (mitTsCodes(true)[$code_id] ?? null) : null;
if ($code_id && !$code) {
    exit('<div class="modal-body">That charge code does not exist.</div>');
}
$code = $code ?: ['code_name' => '', 'code_kind' => 'work', 'code_paid' => 1, 'code_color' => '#0d6efd'];

ob_start();

?>

<div class="modal-header bg-dark">
    <h5 class="modal-title"><i class="fas fa-fw fa-business-time me-2"></i><?= $code_id ? 'Charge code' : 'New charge code' ?></h5>
    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
</div>
<form action="post.php" method="post" autocomplete="off">
    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
    <input type="hidden" name="code_id" value="<?= $code_id ?>">
    <div class="modal-body">
        <div class="mb-3">
            <label class="form-label">Name <strong class="text-danger">*</strong></label>
            <input type="text" class="form-control" name="name" maxlength="100" value="<?= escapeHtml($code['code_name']) ?>" placeholder="e.g. PTO, Admin, Training" required autofocus>
        </div>
        <div class="row g-3 mb-3">
            <div class="col-sm-6">
                <label class="form-label">Kind</label>
                <select class="form-select" name="kind">
                    <?php foreach (MIT_TS_KINDS as $key => $label) { ?>
                        <option value="<?= $key ?>" <?= $key === $code['code_kind'] ? 'selected' : '' ?>><?= escapeHtml($label) ?></option>
                    <?php } ?>
                </select>
                <div class="form-text">Work and Lost time count as hours worked; Leave is time off.</div>
            </div>
            <div class="col-sm-3">
                <label class="form-label">Colour</label>
                <input type="color" class="form-control form-control-color" name="color" value="<?= escapeHtml(preg_match('/^#[0-9a-f]{6}$/i', (string) $code['code_color']) ? $code['code_color'] : '#6c757d') ?>">
            </div>
        </div>
        <div class="form-check">
            <input class="form-check-input" type="checkbox" name="paid" value="1" id="mitCodePaid" <?= intval($code['code_paid']) ? 'checked' : '' ?>>
            <label class="form-check-label" for="mitCodePaid">Paid - counts toward paid hours for payroll</label>
        </div>
    </div>
    <div class="modal-footer">
        <button type="submit" name="mit_charge_code_save" class="btn btn-primary text-bold"><i class="fa fa-check me-2"></i><?= $code_id ? 'Save' : 'Create' ?></button>
        <button type="button" class="btn btn-light" data-bs-dismiss="modal"><i class="fas fa-times me-2"></i>Cancel</button>
    </div>
</form>

<?php
require_once '../../../includes/modal_footer.php';
