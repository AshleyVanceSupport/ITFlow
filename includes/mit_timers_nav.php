<?php
/*
 * Mountaineer IT - the ticket timers running in this browser, next to the bell (js/mit_timers.js).
 * ITFlow keeps a ticket's timer in the browser (agent/js/ticket_time_tracking.js), so this lists
 * what that browser has: running and paused timers, with their time, to open, pause or throw away.
 * Hidden until the script finds a timer.
 */
?>
<li class="nav-item dropdown" id="mitTimers" hidden>
    <a class="nav-link" href="#" role="button" data-bs-toggle="dropdown" data-bs-auto-close="outside" aria-expanded="false" title="Ticket timers">
        <i class="fas fa-stopwatch" aria-hidden="true"></i>
        <span class="badge rounded-pill navbar-badge" data-timers-badge></span>
    </a>
    <div class="dropdown-menu dropdown-menu-end p-0 itflow-notify-panel mit-timers-panel" data-bs-theme="<?= $user_config_theme_dark ? 'dark' : 'light' ?>">
        <div class="itflow-notify-head border-bottom pb-2">
            <strong class="me-auto"><i class="fas fa-stopwatch me-2"></i>Ticket timers</strong>
            <small class="text-body-secondary">In this browser</small>
        </div>
        <div data-timers-list></div>
    </div>
</li>
<style>
    #mitTimers.is-running > .nav-link { color: #ffc107; }
    #mitTimers.is-running .fa-stopwatch { animation: mit-timer-pulse 1.6s ease-in-out infinite; }
    @keyframes mit-timer-pulse { 50% { opacity: .45; } }
    .mit-timers-panel .mit-timer-row { padding: .6rem 1rem; border-bottom: 1px solid var(--bs-border-color); }
    .mit-timers-panel .mit-timer-row:last-child { border-bottom: 0; }
    .mit-timers-panel .mit-timer-time { font-variant-numeric: tabular-nums; font-weight: 600; }
    @media (prefers-reduced-motion: reduce) { #mitTimers.is-running .fa-stopwatch { animation: none; } }
</style>
<script src="/js/mit_timers.js" defer></script>
