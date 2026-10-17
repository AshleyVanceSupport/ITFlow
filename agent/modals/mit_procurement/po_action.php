<?php

/*
 * Mountaineer IT - the smaller forms on a purchase order (agent/mit_purchase_order.php):
 * ?action= approve | reject | ordered | email | tracking | details. Handlers in agent/post/mit_procurement.php.
 */

require_once '../../../includes/modal_header.php';

$action = (string) ($_GET['action'] ?? '');
$levels = ['approve' => 3, 'reject' => 3, 'ordered' => 2, 'email' => 2, 'tracking' => 2, 'details' => 2];
if (!isset($levels[$action])) {
    exit('<div class="modal-body">Unknown action.</div>');
}
enforceUserPermission('module_procurement', $levels[$action]);
$po = mitPoRow($_GET['po_id'] ?? 0);
if (!$po || !mitPoAccessible($po['po_id'])) {
    exit('<div class="modal-body">That purchase order does not exist, or it is for a client you cannot see.</div>');
}
$po_id = intval($po['po_id']);
$reference = escapeHtml(mitPoReference($po));
$titles = [
    'approve' => ['fa-check', "Approve $reference"],
    'reject' => ['fa-undo', "Send $reference back"],
    'ordered' => ['fa-shopping-cart', "Mark $reference ordered"],
    'email' => ['fa-paper-plane', "Email $reference to the vendor"],
    'tracking' => ['fa-shipping-fast', "Add tracking to $reference"],
    'details' => ['fa-pen', "$reference details"],
];
[$icon, $title] = $titles[$action];

ob_start();

?>

<div class="modal-header bg-dark">
    <h5 class="modal-title"><i class="fas fa-fw <?= $icon ?> me-2"></i><?= $title ?></h5>
    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
</div>
<form action="post.php" method="post" autocomplete="off">
    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
    <input type="hidden" name="po_id" value="<?= $po_id ?>">
    <div class="modal-body">

        <?php if ($action === 'approve' || $action === 'reject') { ?>
            <p class="mb-3"><?= escapeHtml($po['vendor_name']) ?> · <strong><?= mitMoney($po['total']) ?></strong> · from <?= escapeHtml($po['created_by_name'] ?: 'someone') ?></p>
            <input type="hidden" name="decision" value="<?= $action ?>">
            <label class="form-label"><?= $action === 'approve' ? 'Note (optional)' : 'Why - they see this on the PO' ?></label>
            <input type="text" class="form-control" name="note" maxlength="255" <?= $action === 'reject' ? 'required' : '' ?>>

        <?php } elseif ($action === 'ordered') { ?>
            <div class="row g-2">
                <div class="col-sm-5">
                    <label class="form-label">Order date</label>
                    <input type="date" class="form-control" name="order_date" value="<?= date('Y-m-d') ?>" required>
                </div>
                <div class="col-sm-7">
                    <label class="form-label">Vendor's order # <span class="text-muted small">(can add later)</span></label>
                    <input type="text" class="form-control" name="vendor_order_number" maxlength="100" value="<?= escapeHtml($po['vendor_order_number']) ?>">
                </div>
            </div>

        <?php } elseif ($action === 'email') {
            $to = $po['vendor_email'] ?: $po['vendor_contact_email']; ?>
            <label class="form-label">Send to</label>
            <input type="email" class="form-control" name="email" value="<?= escapeHtml($to) ?>" required>
            <div class="form-text">The PO goes as a PDF, asking them to quote <?= $reference ?> on their invoice and packing slip.</div>

        <?php } elseif ($action === 'tracking') { ?>
            <div class="row g-2 mb-3">
                <div class="col-sm-4">
                    <label class="form-label">Carrier</label>
                    <select class="form-select" name="carrier">
                        <?php foreach (MIT_CARRIERS as $key => [$label]) { ?>
                            <option value="<?= $key ?>"><?= escapeHtml($label) ?></option>
                        <?php } ?>
                    </select>
                </div>
                <div class="col-sm-8">
                    <label class="form-label">Tracking number</label>
                    <input type="text" class="form-control font-monospace" name="tracking_number" maxlength="100" required>
                </div>
            </div>
            <div class="row g-2">
                <div class="col-sm-8">
                    <label class="form-label">What is in it <span class="text-muted small">(optional)</span></label>
                    <input type="text" class="form-control" name="note" maxlength="255" placeholder="e.g. 2 boxes - the PCs and the switch">
                </div>
                <div class="col-sm-4">
                    <label class="form-label">Expected</label>
                    <input type="date" class="form-control" name="eta">
                </div>
            </div>

        <?php } elseif ($action === 'details') {
            $vendors = mysqli_fetch_all(mysqli_query($mysqli, "SELECT vendor_id, vendor_name FROM vendors WHERE vendor_client_id = 0 AND vendor_archived_at IS NULL ORDER BY vendor_name"), MYSQLI_ASSOC); ?>
            <?php if ($po['status'] === 'draft') { ?>
                <div class="mb-3">
                    <label class="form-label">Vendor</label>
                    <select class="form-select select2" name="vendor_id">
                        <?php foreach ($vendors as $vendor) { ?>
                            <option value="<?= intval($vendor['vendor_id']) ?>" <?= intval($vendor['vendor_id']) === intval($po['vendor_id']) ? 'selected' : '' ?>><?= escapeHtml($vendor['vendor_name']) ?></option>
                        <?php } ?>
                    </select>
                </div>
            <?php } ?>
            <div class="row g-2 mb-3">
                <div class="col-sm-7">
                    <label class="form-label">Vendor's order #</label>
                    <input type="text" class="form-control" name="vendor_order_number" maxlength="100" value="<?= escapeHtml($po['vendor_order_number']) ?>">
                </div>
                <div class="col-sm-5">
                    <label class="form-label">Order date</label>
                    <input type="date" class="form-control" name="order_date" value="<?= escapeHtml($po['order_date']) ?>">
                </div>
            </div>
            <div class="mb-3">
                <label class="form-label">Ship to</label>
                <textarea class="form-control" name="ship_to_address" rows="3"><?= escapeHtml($po['ship_to_address']) ?></textarea>
                <div class="form-text">Our office by default - type a client's site to have it drop-shipped.</div>
            </div>
            <div>
                <label class="form-label">Note to the vendor <span class="text-muted small">(on the PDF)</span></label>
                <textarea class="form-control" name="note" rows="2"><?= escapeHtml($po['note']) ?></textarea>
            </div>
        <?php } ?>

    </div>
    <div class="modal-footer">
        <?php
        $buttons = [
            'approve' => ['mit_po_decide', 'btn-success', 'Approve'],
            'reject' => ['mit_po_decide', 'btn-danger', 'Send back'],
            'ordered' => ['mit_po_ordered', 'btn-primary', 'Mark ordered'],
            'email' => ['mit_po_email', 'btn-primary', 'Send'],
            'tracking' => ['mit_po_tracking_add', 'btn-primary', 'Add'],
            'details' => ['mit_po_details', 'btn-primary', 'Save'],
        ];
        [$name, $class, $label] = $buttons[$action]; ?>
        <button type="submit" name="<?= $name ?>" class="btn <?= $class ?> text-bold"><i class="fas fa-check me-2"></i><?= $label ?></button>
        <button type="button" class="btn btn-light" data-bs-dismiss="modal"><i class="fas fa-times me-2"></i>Cancel</button>
    </div>
</form>

<?php
require_once '../../../includes/modal_footer.php';
