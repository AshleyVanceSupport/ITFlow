<?php

/*
 * Mountaineer IT - add an item to the list to order, or change one (?item_id=) - functions/mit_procurement.php.
 */

require_once '../../../includes/modal_header.php';

enforceUserPermission('module_procurement', 2);

$item = null;
$item_id = intval($_GET['item_id'] ?? 0);
if ($item_id) {
    $item = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT * FROM mit_procurement_items proc WHERE item_id = $item_id AND status = 'waiting'" . clientScopeSql('proc.client_id')));
    if (!$item) {
        exit('<div class="modal-body">That item is no longer waiting to be ordered.</div>');
    }
}
$value = function ($key, $default = '') use ($item) {
    return escapeHtml($item[$key] ?? $default);
};

$vendors = mysqli_fetch_all(mysqli_query($mysqli, "SELECT vendor_id, vendor_name FROM vendors WHERE vendor_client_id = 0 AND vendor_archived_at IS NULL ORDER BY vendor_name"), MYSQLI_ASSOC);
$clients = mysqli_fetch_all(mysqli_query($mysqli, "SELECT client_id, client_name FROM clients WHERE client_archived_at IS NULL" . clientScopeSql('client_id') . " ORDER BY client_name"), MYSQLI_ASSOC);
$projects = mysqli_fetch_all(mysqli_query($mysqli, "SELECT project_id, project_prefix, project_number, project_name, project_client_id, client_name FROM projects
    LEFT JOIN clients ON clients.client_id = project_client_id
    WHERE project_archived_at IS NULL AND project_completed_at IS NULL" . clientScopeSql('project_client_id') . " ORDER BY project_number DESC"), MYSQLI_ASSOC);
$products = mysqli_fetch_all(mysqli_query($mysqli, "SELECT product_id, product_name, product_description" . (mitQuoteColumnsReady() ? ', product_cost' : ', NULL AS product_cost') . " FROM products
    WHERE product_archived_at IS NULL AND product_type = 'product' ORDER BY product_name"), MYSQLI_ASSOC);

ob_start();

?>

<div class="modal-header bg-dark">
    <h5 class="modal-title"><i class="fas fa-fw fa-cart-plus me-2"></i><?= $item ? 'Item to order' : 'Add an item to order' ?></h5>
    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
</div>
<form action="post.php" method="post" autocomplete="off">
    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
    <input type="hidden" name="item_id" value="<?= $item_id ?>">
    <div class="modal-body">

        <?php if ($products && !$item) { ?>
            <div class="mb-3">
                <label class="form-label">From a product <span class="text-muted small">(optional)</span></label>
                <select class="form-select select2" name="product_id" data-mit-product>
                    <option value="0">- Type it in below -</option>
                    <?php foreach ($products as $product) { ?>
                        <option value="<?= intval($product['product_id']) ?>" data-name="<?= escapeHtml($product['product_name']) ?>" data-cost="<?= $product['product_cost'] === null ? '' : number_format((float) $product['product_cost'], 2, '.', '') ?>"><?= escapeHtml($product['product_name']) ?></option>
                    <?php } ?>
                </select>
            </div>
        <?php } ?>

        <div class="mb-3">
            <label class="form-label">Item <strong class="text-danger">*</strong></label>
            <input type="text" class="form-control" name="name" maxlength="255" required value="<?= $value('name') ?>" data-mit-name>
        </div>

        <div class="row g-2 mb-3">
            <div class="col-sm-4">
                <label class="form-label">Quantity <strong class="text-danger">*</strong></label>
                <input type="text" inputmode="decimal" class="form-control" name="quantity" required value="<?= $item ? mitQty($item['quantity']) : '1' ?>">
            </div>
            <div class="col-sm-4">
                <label class="form-label">Our cost (each)</label>
                <div class="input-group">
                    <span class="input-group-text"><i class="fas fa-fw fa-dollar-sign"></i></span>
                    <input type="text" inputmode="decimal" class="form-control" name="unit_cost" placeholder="0.00" value="<?= $item && $item['unit_cost'] !== null ? number_format((float) $item['unit_cost'], 2, '.', '') : '' ?>" data-mit-cost>
                </div>
            </div>
            <div class="col-sm-4">
                <label class="form-label">Order from</label>
                <select class="form-select select2" name="vendor_id">
                    <option value="0">- Later -</option>
                    <?php foreach ($vendors as $vendor) { ?>
                        <option value="<?= intval($vendor['vendor_id']) ?>" <?= intval($item['vendor_id'] ?? 0) === intval($vendor['vendor_id']) ? 'selected' : '' ?>><?= escapeHtml($vendor['vendor_name']) ?></option>
                    <?php } ?>
                </select>
            </div>
        </div>

        <div class="row g-2 mb-3">
            <div class="col-sm-6">
                <label class="form-label">For client</label>
                <select class="form-select select2" name="client_id">
                    <option value="0">Our stock - no client</option>
                    <?php foreach ($clients as $client) { ?>
                        <option value="<?= intval($client['client_id']) ?>" <?= intval($item['client_id'] ?? 0) === intval($client['client_id']) ? 'selected' : '' ?>><?= escapeHtml($client['client_name']) ?></option>
                    <?php } ?>
                </select>
            </div>
            <div class="col-sm-6">
                <label class="form-label">Project</label>
                <select class="form-select select2" name="project_id">
                    <option value="0">- None -</option>
                    <?php foreach ($projects as $project) { ?>
                        <option value="<?= intval($project['project_id']) ?>" <?= intval($item['project_id'] ?? 0) === intval($project['project_id']) ? 'selected' : '' ?>><?= escapeHtml($project['project_prefix'] . $project['project_number'] . ' ' . $project['project_name'] . ($project['client_name'] ? ' (' . $project['client_name'] . ')' : '')) ?></option>
                    <?php } ?>
                </select>
            </div>
        </div>

        <div class="mb-0">
            <label class="form-label">Note</label>
            <input type="text" class="form-control" name="note" maxlength="255" placeholder="e.g. Restock - 2 left on the shelf" value="<?= $value('note') ?>">
        </div>

    </div>
    <div class="modal-footer">
        <button type="submit" name="mit_proc_save_item" class="btn btn-primary text-bold"><i class="fas fa-check me-2"></i><?= $item ? 'Save' : 'Add' ?></button>
        <button type="button" class="btn btn-light" data-bs-dismiss="modal"><i class="fas fa-times me-2"></i>Cancel</button>
    </div>
</form>

<script>
    // A product fills in the name and our cost
    if (!window.mitProcProductBound) {
        window.mitProcProductBound = true;
        document.addEventListener('change', function (e) {
            if (!e.target.matches('[data-mit-product]')) {
                return;
            }
            const option = e.target.options[e.target.selectedIndex];
            const form = e.target.form;
            if (option && option.value !== '0') {
                form.querySelector('[data-mit-name]').value = option.dataset.name || '';
                if (option.dataset.cost) {
                    form.querySelector('[data-mit-cost]').value = option.dataset.cost;
                }
            }
        });
    }
</script>

<?php
require_once '../../../includes/modal_footer.php';
