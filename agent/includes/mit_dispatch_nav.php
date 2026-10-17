<?php
/*
 * Mountaineer IT - Dispatch in the agent side menu (functions/mit_dispatch.php), required from
 * agent/includes/side_nav.php under Calendar. For Support edit, with the count of open tickets with no tech.
 */

if (function_exists('mitDispatchCan') && $config_module_enable_ticketing == 1 && mitDispatchCan()) {
    $mit_dispatch_waiting = intval(mysqli_fetch_row(mysqli_query($mysqli, "SELECT COUNT(*) FROM tickets WHERE ticket_assigned_to = 0
        AND ticket_resolved_at IS NULL AND ticket_closed_at IS NULL AND ticket_archived_at IS NULL" . clientScopeSql('ticket_client_id')))[0]); ?>
    <li class="nav-item">
        <a href="/agent/mit_dispatch.php" class="nav-link <?= basename($_SERVER['PHP_SELF']) === 'mit_dispatch.php' ? 'active' : '' ?>">
            <i class="nav-icon fas fa-route"></i>
            <p>
                Dispatch
                <?php if ($mit_dispatch_waiting) { ?><span class="right badge text-bg-danger" data-bs-toggle="tooltip" title="Open tickets with no tech"><?= $mit_dispatch_waiting ?></span><?php } ?>
            </p>
        </a>
    </li>
<?php }
