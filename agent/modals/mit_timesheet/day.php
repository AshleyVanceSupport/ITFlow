<?php

/*
 * Mountaineer IT - a timesheet day's ticket time (functions/mit_timesheets.php): the tickets behind
 * the auto-filled hours. Tickets for clients the viewer cannot see are counted but not named.
 */

require_once '../../../includes/modal_header.php';

$user_id = intval($_GET['user_id'] ?? 0);
$date = (string) ($_GET['date'] ?? '');
if (!mitTsReady() || !mitTsMaySee($user_id) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    exit('<div class="modal-body">You cannot see that timesheet.</div>');
}
$rows = mitTsDayTickets($user_id, $date);
$hidden = 0;

ob_start();

?>

<div class="modal-header bg-dark">
    <h5 class="modal-title"><i class="fas fa-fw fa-life-ring me-2"></i>Ticket time <small class="opacity-75 ms-1"><?= escapeHtml(date('l, M j', strtotime($date))) ?></small></h5>
    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
</div>
<div class="modal-body">
    <?php if (!$rows) { ?>
        <div class="text-muted">No ticket time that day.</div>
    <?php } else { ?>
        <table class="table table-sm align-middle mb-0">
            <thead class="small text-muted"><tr><th>Ticket</th><th>Client</th><th class="text-center">Billable</th><th class="text-end">Hours</th></tr></thead>
            <tbody>
                <?php foreach ($rows as $row) {
                    if (!hasClientAccess($row['ticket_client_id'])) {
                        $hidden += $row['minutes'];
                        continue;
                    } ?>
                    <tr>
                        <td><a href="ticket.php?ticket_id=<?= intval($row['ticket_id']) ?>"><?= escapeHtml($row['ticket_prefix'] . $row['ticket_number']) ?></a> <?= escapeHtml($row['ticket_subject']) ?></td>
                        <td class="small"><?= escapeHtml($row['client_name'] ?: '-') ?></td>
                        <td class="text-center"><?= intval($row['ticket_billable']) ? '<i class="fas fa-check text-success" title="Billable"></i>' : '' ?></td>
                        <td class="text-end"><?= mitTsHours($row['minutes'], false) ?></td>
                    </tr>
                <?php } ?>
                <?php if ($hidden) { ?>
                    <tr><td colspan="3" class="text-muted small">Tickets for clients you cannot see</td><td class="text-end"><?= mitTsHours($hidden, false) ?></td></tr>
                <?php } ?>
            </tbody>
        </table>
    <?php } ?>
</div>
<div class="modal-footer">
    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Close</button>
</div>

<?php
require_once '../../../includes/modal_footer.php';
