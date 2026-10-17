<?php

/*
 * Mountaineer IT - Dispatch (functions/mit_dispatch.php): each tech's booked work beside the tickets still
 * to dispatch. Day: a column per tech; Week: a row per tech, a column per day. Drag a ticket from the
 * queue onto a tech and time (or a booking to another tech or time) and confirm; drag a booking back onto
 * the queue to take it off the schedule. The script is agent/js/mit_dispatch.js; its requests go to
 * agent/mit_dispatch_ajax.php. ?date=Y-m-d opens on that day.
 */

require_once "includes/inc_all.php";

if (!mitDispatchCan()) {
    enforceUserPermission('module_support', 2);
    // Support edit but ticketing or Dispatch is switched off
    flashAlert('Dispatch is switched off (Admin > Settings > Modules).', 'error');
    redirect('/agent/calendar.php');
}

$techs = mitDispatchTechs();
$state = mitDispatchState();
$row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT user_config_calendar_first_day FROM user_settings WHERE user_id = $session_user_id"));
$first_day = intval($row['user_config_calendar_first_day'] ?? 0);
$clients = mysqli_fetch_all(mysqli_query($mysqli, "SELECT client_id, client_name FROM clients WHERE client_archived_at IS NULL" . clientScopeSql('client_id') . " ORDER BY client_name"), MYSQLI_ASSOC);

?>

<style>
    .mit-dispatch-queue { max-height: calc(100vh - 17rem); overflow-y: auto; }
    .mit-dq-item { cursor: grab; border-left: 4px solid var(--bs-secondary-border-subtle); }
    .mit-dq-item[data-priority="Urgent"] { border-left-color: var(--bs-danger); }
    .mit-dq-item[data-priority="High"] { border-left-color: var(--bs-warning); }
    .mit-dq-item[data-priority="Medium"] { border-left-color: var(--bs-info); }
    .mit-dq-item:hover { background: var(--bs-tertiary-bg); }
    .mit-dispatch-queue.is-drop-target { outline: 2px dashed var(--bs-danger); outline-offset: -4px; }
    .mit-dispatch-cols { display: flex; overflow-x: auto; gap: 0; }
    .mit-dispatch-col { flex: 1 0 210px; min-width: 210px; border-right: 1px solid var(--bs-border-color); }
    .mit-dispatch-col:last-child { border-right: 0; }
    .mit-dispatch-col-head { position: sticky; top: 0; padding: .4rem .5rem; border-bottom: 1px solid var(--bs-border-color); background: var(--bs-body-bg); z-index: 3; }
    .mit-dispatch [data-kind="outlook_busy"] { background-image: repeating-linear-gradient(135deg, rgba(255, 255, 255, .25) 0 4px, transparent 4px 10px); opacity: .7; cursor: default; }
    .mit-dispatch [data-kind="helper"] { outline: 2px dashed rgba(255, 255, 255, .75); outline-offset: -3px; }
    .mit-dispatch-week th, .mit-dispatch-week td { vertical-align: top; }
    .mit-dispatch-week td.mit-dw-cell { min-width: 130px; height: 70px; padding: .25rem; }
    .mit-dispatch-week td.mit-dw-cell.is-off { background: var(--bs-tertiary-bg); }
    .mit-dw-chip { font-size: .78rem; border-radius: .3rem; padding: .15rem .35rem; margin-bottom: .2rem; color: #fff; cursor: grab; overflow: hidden; white-space: nowrap; text-overflow: ellipsis; }
    .mit-dw-chip[data-kind="outlook_busy"], .mit-dw-chip[data-kind="helper"] { cursor: default; }
    .mit-dw-cell .sortable-ghost, .mit-dispatch-queue .sortable-ghost { opacity: .4; }
    /* Side by side (lg and up) the queue and the board are one height, filling the window; each scrolls inside */
    @media (min-width: 992px) {
        .mit-dispatch-pane { height: max(28rem, calc(100vh - 13.5rem)); display: flex; flex-direction: column; }
        .mit-dispatch-pane .mit-dispatch-queue { flex: 1 1 auto; min-height: 0; max-height: none; }
        .mit-dispatch-pane [data-md-board] { flex: 1 1 auto; min-height: 0; overflow: auto; }
        .mit-dispatch-pane .mit-dispatch-cols { height: 100%; }
        .mit-dispatch-pane .mit-dispatch-col { display: flex; flex-direction: column; }
        .mit-dispatch-pane .mit-dispatch-col [data-md-cal] { flex: 1 1 auto; min-height: 0; }
        .mit-dispatch-pane .mit-dispatch-week thead th { position: sticky; top: 0; z-index: 2; background: var(--bs-body-bg); }
        .mit-dispatch-pane .mit-dispatch-week-wrap { overflow: visible; }
    }
</style>

<div class="mit-dispatch" id="mitDispatch"
    data-csrf="<?= $_SESSION['csrf_token'] ?>"
    data-techs="<?= escapeHtml(json_encode(array_values($techs))) ?>"
    data-state="<?= escapeHtml(json_encode($state)) ?>"
    data-first-day="<?= $first_day ?>"
    data-default-minutes="<?= CALENDAR_TICKET_MINUTES ?>"
    data-lengths="<?= escapeHtml(json_encode(MIT_DISPATCH_LENGTHS)) ?>"
    data-business-hours="<?= escapeHtml(json_encode(mitDispatchBusinessHours())) ?>"
    data-contact-default="<?= intval($config_ticket_client_general_notifications ?? 0) === 1 ? 1 : 0 ?>"
    data-initial-date="<?= calendarIsDate($_GET['date'] ?? '') ? escapeHtml($_GET['date']) : date('Y-m-d') ?>">

    <div class="card mb-3">
        <div class="card-header bg-dark py-2 d-flex flex-wrap align-items-center gap-2">
            <h3 class="card-title mt-2 me-auto"><i class="fas fa-fw fa-people-arrows me-2"></i>Dispatch <span class="fw-normal opacity-75 ms-2 small" data-md-title></span></h3>
            <div class="btn-group btn-group-sm" role="group" aria-label="View">
                <button type="button" class="btn btn-light" data-md-view="day">Day</button>
                <button type="button" class="btn btn-light" data-md-view="week">Week</button>
            </div>
            <div class="btn-group btn-group-sm">
                <button type="button" class="btn btn-light" data-md-nav="-1" title="Back"><i class="fas fa-chevron-left"></i></button>
                <button type="button" class="btn btn-light" data-md-nav="0">Today</button>
                <button type="button" class="btn btn-light" data-md-nav="1" title="Forward"><i class="fas fa-chevron-right"></i></button>
            </div>
            <input type="date" class="form-control form-control-sm w-auto" data-md-date aria-label="Go to date">
            <div class="dropdown">
                <button class="btn btn-light btn-sm dropdown-toggle" type="button" data-bs-toggle="dropdown" data-bs-auto-close="outside"><i class="fas fa-users me-2"></i>Agents <span data-md-tech-count></span></button>
                <div class="dropdown-menu dropdown-menu-end p-2" style="max-height: 60vh; overflow-y: auto; min-width: 220px">
                    <?php foreach ($techs as $tech) { ?>
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" value="<?= intval($tech['id']) ?>" id="mdTech<?= intval($tech['id']) ?>" data-md-tech <?= in_array($tech['id'], $state['hidden'], true) ? '' : 'checked' ?>>
                            <label class="form-check-label" for="mdTech<?= intval($tech['id']) ?>"><span class="d-inline-block rounded-circle me-1" style="width: 10px; height: 10px; background: <?= escapeHtml($tech['color']) ?>"></span><?= escapeHtml($tech['name']) ?></label>
                        </div>
                    <?php } ?>
                    <hr class="my-2">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="mdWeekends" data-md-weekends <?= $state['weekends'] ? 'checked' : '' ?>>
                        <label class="form-check-label" for="mdWeekends">Show weekends (week view)</label>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-3">
        <div class="col-lg-3">
            <div class="card mb-0 mit-dispatch-pane">
                <div class="card-header p-2">
                    <ul class="nav nav-pills nav-fill small" role="tablist">
                        <li class="nav-item"><a class="nav-link py-1" href="#" data-md-tab="unassigned">No agent <span class="badge text-bg-danger ms-1" data-md-count="unassigned"></span></a></li>
                        <li class="nav-item"><a class="nav-link py-1" href="#" data-md-tab="unbooked">Not scheduled <span class="badge text-bg-warning ms-1" data-md-count="unbooked"></span></a></li>
                    </ul>
                    <div class="mt-2 d-flex flex-column gap-1">
                        <input type="search" class="form-control form-control-sm" placeholder="Search tickets" data-md-filter="q" aria-label="Search">
                        <div class="d-flex gap-1">
                            <select class="form-select form-select-sm" data-md-filter="client_id" aria-label="Client">
                                <option value="">All clients</option>
                                <?php foreach ($clients as $client) { ?>
                                    <option value="<?= intval($client['client_id']) ?>"><?= escapeHtml($client['client_name']) ?></option>
                                <?php } ?>
                            </select>
                            <select class="form-select form-select-sm w-auto" data-md-filter="priority" aria-label="Priority">
                                <option value="">Any priority</option>
                                <?php foreach (['Urgent', 'High', 'Medium', 'Low'] as $priority) { ?>
                                    <option><?= $priority ?></option>
                                <?php } ?>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="card-body p-2 mit-dispatch-queue" data-md-queue></div>
                <div class="card-footer small text-muted py-2">Drag a ticket onto an agent and time. Drag scheduled work back here to take it off the schedule.</div>
            </div>
        </div>
        <div class="col-lg-9">
            <div class="card mb-0 mit-dispatch-pane">
                <div class="card-body p-0" data-md-board></div>
            </div>
        </div>
    </div>
</div>

<?php require_once "../includes/footer.php"; ?>
<link href="/libs/fullcalendar/skeleton.css" rel="stylesheet">
<link href="/libs/fullcalendar/themes/classic/theme.css" rel="stylesheet">
<link href="/libs/fullcalendar/themes/classic/palette.css" rel="stylesheet">
<script src="/libs/fullcalendar/fullcalendar.global.js"></script>
<script src="/libs/fullcalendar/themes/classic/global.js"></script>
<script src="/libs/SortableJS/Sortable.min.js"></script>
<script src="/agent/js/mit_dispatch.js"></script>
