<?php
/*
 * Mountaineer IT - Timesheet in the agent side menu (functions/mit_timesheets.php), required from
 * agent/includes/side_nav.php under Calendar. Everyone keeps their own; "Timesheets" (everyone's)
 * for Timesheets View and for people's own approvers, with a count of those waiting for them.
 */

if (function_exists('mitTsReady') && mitTsReady()) {
    $mit_nav_page = basename($_SERVER['PHP_SELF']);
    $mit_ts_everyone = mitTsLevel() >= 1 || mitTsMyReports();
    $mit_ts_waiting = $mit_ts_everyone ? mitTsWaitingCount() : 0; ?>
    <li class="nav-item">
        <a href="/agent/mit_timesheet.php" class="nav-link <?= $mit_nav_page === 'mit_timesheet.php' ? 'active' : '' ?>">
            <i class="nav-icon fas fa-business-time"></i>
            <p>My timesheet</p>
        </a>
    </li>
    <?php if ($mit_ts_everyone) { ?>
        <li class="nav-item">
            <a href="/agent/mit_timesheets.php" class="nav-link <?= $mit_nav_page === 'mit_timesheets.php' ? 'active' : '' ?>">
                <i class="nav-icon fas fa-users-cog"></i>
                <p>
                    Timesheets
                    <?php if ($mit_ts_waiting) { ?><span class="right badge text-bg-warning" data-bs-toggle="tooltip" title="Waiting for approval"><?= $mit_ts_waiting ?></span><?php } ?>
                </p>
            </a>
        </li>
    <?php }
}
