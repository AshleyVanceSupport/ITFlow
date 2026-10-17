<?php
/*
 * Mountaineer IT - our entries in the admin side menu's LISTS, required from admin/includes/side_nav.php.
 */
?>
<li class="nav-item">
    <a href="/admin/mit_timesheets.php" class="nav-link <?= basename($_SERVER['PHP_SELF']) === 'mit_timesheets.php' ? 'active' : '' ?>">
        <i class="nav-icon fas fa-business-time"></i>
        <p>Timesheets</p>
    </a>
</li>
