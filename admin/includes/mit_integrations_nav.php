<?php
/*
 * Mountaineer IT - our integrations in the admin side menu's INTEGRATIONS, required from admin/includes/side_nav.php
 * after AI Providers: NinjaOne, QuickBooks Online.
 */
?>
<li class="nav-item">
    <a href="/admin/mit_ninjaone.php" class="nav-link <?= basename($_SERVER['PHP_SELF']) === 'mit_ninjaone.php' ? 'active' : '' ?>">
        <i class="nav-icon fas fa-satellite-dish"></i>
        <p>NinjaOne</p>
    </a>
</li>
<li class="nav-item">
    <a href="/admin/mit_qbo.php" class="nav-link <?= basename($_SERVER['PHP_SELF']) === 'mit_qbo.php' ? 'active' : '' ?>">
        <i class="nav-icon fas fa-file-invoice-dollar"></i>
        <p>QuickBooks Online</p>
    </a>
</li>
