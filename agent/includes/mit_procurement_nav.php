<?php
/*
 * Mountaineer IT - the Procurement section of the agent side menu (functions/mit_procurement.php),
 * required from agent/includes/side_nav.php. Only for roles with the Procurement permission.
 */

if (function_exists('mitProcLevel') && mitProcLevel() >= 1) {
    $mit_nav_page = basename($_SERVER['PHP_SELF']);
    $mit_nav_counts = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT
        (SELECT COUNT(*) FROM mit_procurement_items proc WHERE status = 'waiting'" . clientScopeSql('proc.client_id') . ") AS to_order,
        (SELECT COUNT(*) FROM mit_purchase_orders WHERE status = 'pending') AS pending,
        (SELECT COUNT(DISTINCT po.po_id) FROM mit_purchase_orders po WHERE po.status IN ('ordered', 'partial')) AS arriving"));
    $mit_nav_links = [
        ['mit_procurement.php', 'fa-cart-plus', 'To order', intval($mit_nav_counts['to_order']), 'Items waiting to be put on a PO', []],
        ['mit_purchase_orders.php', 'fa-file-signature', 'Purchase orders', intval($mit_nav_counts['pending']), 'Waiting for approval', ['mit_purchase_order.php']],
        ['mit_receiving.php', 'fa-truck-loading', 'Receiving', intval($mit_nav_counts['arriving']), 'Ordered POs still to arrive', []],
    ];
    ?>
    <li class="nav-header mt-3">PROCUREMENT</li>
    <?php foreach ($mit_nav_links as [$mit_file, $mit_icon, $mit_label, $mit_count, $mit_title, $mit_also]) { ?>
        <li class="nav-item">
            <a href="/agent/<?= $mit_file ?>" class="nav-link <?= $mit_nav_page === $mit_file || in_array($mit_nav_page, $mit_also, true) ? 'active' : '' ?>">
                <i class="nav-icon fas <?= $mit_icon ?>"></i>
                <p>
                    <?= $mit_label ?>
                    <?php if ($mit_count) { ?>
                        <span class="right badge <?= $mit_label === 'Purchase orders' ? 'text-bg-warning' : 'text-light' ?>" data-bs-toggle="tooltip" title="<?= $mit_title ?>"><?= $mit_count ?></span>
                    <?php } ?>
                </p>
            </a>
        </li>
    <?php }
}
