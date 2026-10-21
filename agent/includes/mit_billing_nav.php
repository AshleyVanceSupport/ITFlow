<?php
/*
 * Mountaineer IT - Unbilled Parts in the agent side menu's BILLING section (agent/mit_unbilled_parts.php,
 * functions/mit_procurement_parts.php), required from agent/includes/side_nav.php after Products. Follows the
 * Sales permission (the section already needs Sales View); needs the Procurement module switched on, which keeps parts.
 */

if (function_exists('mitUnbilledPartsCount') && mitProcOn() && mitTicketPartsReady()) {
    $mit_unbilled = mitNavCachedCount('unbilled_parts:' . clientScopeSql(defined('MIT_PART_CLIENT_SQL') ? MIT_PART_CLIENT_SQL : 'client_id'), 'mitUnbilledPartsCount',
        basename($_SERVER['PHP_SELF']) === 'mit_unbilled_parts.php'); ?>
    <li class="nav-item">
        <a href="/agent/mit_unbilled_parts.php" class="nav-link <?= basename($_SERVER['PHP_SELF']) === 'mit_unbilled_parts.php' ? 'active' : '' ?>">
            <i class="nav-icon fas fa-file-invoice-dollar"></i>
            <p>
                Unbilled Parts
                <?php if ($mit_unbilled) { ?><span class="right badge text-light" data-bs-toggle="tooltip" title="Parts on tickets not billed yet"><?= $mit_unbilled ?></span><?php } ?>
            </p>
        </a>
    </li>
<?php }
