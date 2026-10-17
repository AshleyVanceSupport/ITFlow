// Mountaineer IT - Dispatch (agent/mit_dispatch.php, functions/mit_dispatch.php).
//
// The queue (no tech / not booked) beside a board of each tech's bookings: Day is one FullCalendar
// time grid per tech, side by side; Week is a table of techs by days, worked with SortableJS. Anything
// dropped on the board opens a quick confirm (tech, date, start, length, onsite/remote, email the
// contact) and is booked through mit_dispatch_ajax.php; a booking dropped on the queue is taken off.
// The view, hidden techs and weekends are saved to the agent's account.

(function () {

    const root = document.getElementById('mitDispatch');
    if (!root || typeof FullCalendar === 'undefined') {
        return;
    }

    const csrf = root.dataset.csrf;
    const techs = JSON.parse(root.dataset.techs || '[]');
    const saved = JSON.parse(root.dataset.state || '{}');
    const firstDay = Number(root.dataset.firstDay) || 0;
    const defaultMinutes = Number(root.dataset.defaultMinutes) || 60;
    const contactDefault = root.dataset.contactDefault === '1';
    const board = root.querySelector('[data-md-board]');
    const queueEl = root.querySelector('[data-md-queue]');
    const titleEl = root.querySelector('[data-md-title]');
    const dateInput = root.querySelector('[data-md-date]');

    const state = {
        view: saved.view === 'week' ? 'week' : 'day',
        hidden: Array.isArray(saved.hidden) ? saved.hidden.map(Number) : [],
        weekends: !!saved.weekends,
        date: parseYmd(root.dataset.initialDate) || new Date(),
        tab: 'unassigned',
        filters: {q: '', client_id: '', priority: ''}
    };
    let calendars = [];
    let sortables = [];
    let queueDraggable = null;
    let queueSortable = null;
    let queueTickets = [];

    // ---------- small helpers ----------

    function pad(n) {
        return String(n).padStart(2, '0');
    }
    function ymd(date) {
        return date.getFullYear() + '-' + pad(date.getMonth() + 1) + '-' + pad(date.getDate());
    }
    function hm(date) {
        return pad(date.getHours()) + ':' + pad(date.getMinutes());
    }
    function parseYmd(text) {
        const m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(text || '');
        return m ? new Date(Number(m[1]), Number(m[2]) - 1, Number(m[3])) : null;
    }
    function addDays(date, n) {
        const d = new Date(date.getTime());
        d.setDate(d.getDate() + n);
        return d;
    }
    function weekStart(date) {
        const offset = (date.getDay() - firstDay + 7) % 7;
        return addDays(date, -offset);
    }
    function escapeText(text) {
        const div = document.createElement('div');
        div.textContent = text == null ? '' : String(text);
        return div.innerHTML;
    }
    function shownTechs() {
        return techs.filter(function (t) {
            return state.hidden.indexOf(t.id) === -1;
        });
    }
    function techById(id) {
        return techs.find(function (t) {
            return t.id === Number(id);
        });
    }
    function lengthText(minutes) {
        return minutes % 60 ? (minutes >= 60 ? Math.floor(minutes / 60) + 'h ' : '') + (minutes % 60) + 'm' : (minutes / 60) + 'h';
    }
    function openModal(url, size) {
        const link = document.createElement('a');
        link.href = '#';
        link.className = 'ajax-modal d-none';
        link.dataset.modalUrl = url;
        if (size) {
            link.dataset.modalSize = size;
        }
        document.body.appendChild(link);
        link.click();
        link.remove();
    }
    function toast(message, type) {
        if (typeof itflowToast === 'function') {
            itflowToast(message, type);
        }
    }

    function request(method, params) {
        const body = new URLSearchParams(params);
        const url = 'mit_dispatch_ajax.php' + (method === 'GET' ? '?' + body.toString() : '');
        return fetch(url, method === 'GET' ? {credentials: 'same-origin'} : {
            method: 'POST', credentials: 'same-origin',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'}, body: body.toString()
        }).then(function (res) {
            return res.json().catch(function () {
                return {error: 'Something went wrong - reload and try again.'};
            });
        });
    }

    let saveTimer = null;
    function saveState() {
        clearTimeout(saveTimer);
        saveTimer = setTimeout(function () {
            request('POST', {state: 1, csrf_token: csrf, view: state.view, hidden: state.hidden.join(','), weekends: state.weekends ? 1 : 0});
        }, 600);
    }

    // ---------- the quick confirm ----------

    // ticket: {id, reference, subject, tech, minutes, onsite}; at: Date (or null); tech: id
    function confirmBooking(ticket, at, techId) {
        const start = at || (function () {
            const d = new Date(state.date.getTime());
            d.setHours(9, 0, 0, 0);
            return d;
        })();
        const minutes = ticket.minutes || defaultMinutes;
        const lengths = [15, 30, 45, 60, 90, 120, 180, 240, 360, 480];
        if (lengths.indexOf(minutes) === -1) {
            lengths.push(minutes);
            lengths.sort(function (a, b) {
                return a - b;
            });
        }
        const html = '<div class="text-start small">'
            + '<div class="mb-2 text-muted">' + escapeText(ticket.subject || '') + '</div>'
            + '<div class="row g-2">'
            + '<div class="col-12"><label class="form-label mb-0">Tech</label><select class="form-select form-select-sm" id="mdTech">'
            + techs.map(function (t) {
                return '<option value="' + t.id + '"' + (t.id === Number(techId) ? ' selected' : '') + '>' + escapeText(t.name) + '</option>';
            }).join('') + '</select></div>'
            + '<div class="col-5"><label class="form-label mb-0">Date</label><input type="date" class="form-control form-control-sm" id="mdDate" value="' + ymd(start) + '"></div>'
            + '<div class="col-4"><label class="form-label mb-0">Start</label><input type="time" step="900" class="form-control form-control-sm" id="mdStart" value="' + hm(start) + '"></div>'
            + '<div class="col-3"><label class="form-label mb-0">Length</label><select class="form-select form-select-sm" id="mdLength">'
            + lengths.map(function (m) {
                return '<option value="' + m + '"' + (m === minutes ? ' selected' : '') + '>' + lengthText(m) + '</option>';
            }).join('') + '</select></div>'
            + '<div class="col-12 d-flex gap-3 mt-2">'
            + '<div class="form-check"><input class="form-check-input" type="radio" name="mdWhere" id="mdOnsite" value="1"' + (ticket.onsite ? ' checked' : '') + '><label class="form-check-label" for="mdOnsite">Onsite</label></div>'
            + '<div class="form-check"><input class="form-check-input" type="radio" name="mdWhere" id="mdRemote" value="0"' + (ticket.onsite ? '' : ' checked') + '><label class="form-check-label" for="mdRemote">Remote</label></div>'
            + '<div class="form-check ms-auto"><input class="form-check-input" type="checkbox" id="mdContact"' + (contactDefault ? ' checked' : '') + '><label class="form-check-label" for="mdContact">Email the contact</label></div>'
            + '</div></div></div>';

        return Swal.fire({
            theme: document.documentElement.getAttribute('data-bs-theme') === 'dark' ? 'dark' : 'light',
            title: 'Book ' + ticket.reference,
            html: html,
            showCancelButton: true,
            confirmButtonText: 'Book it',
            reverseButtons: true,
            buttonsStyling: false,
            focusConfirm: true,
            customClass: {confirmButton: 'btn btn-primary mx-1', cancelButton: 'btn btn-secondary mx-1'},
            preConfirm: function () {
                const date = document.getElementById('mdDate').value;
                const time = document.getElementById('mdStart').value;
                if (!date || !time) {
                    Swal.showValidationMessage('Pick the date and start time');
                    return false;
                }
                return request('POST', {
                    book: 1, csrf_token: csrf, ticket_id: ticket.id,
                    tech_id: document.getElementById('mdTech').value,
                    start: date + ' ' + time + ':00',
                    minutes: document.getElementById('mdLength').value,
                    onsite: document.getElementById('mdOnsite').checked ? 1 : 0,
                    contact: document.getElementById('mdContact').checked ? 1 : 0
                }).then(function (json) {
                    if (json.error) {
                        Swal.showValidationMessage(json.error);
                        return false;
                    }
                    return json;
                });
            }
        }).then(function (result) {
            if (result.isConfirmed && result.value) {
                toast(result.value.message, 'success');
                if (result.value.conflicts && result.value.conflicts.length) {
                    toast('Same tech, overlapping: ' + result.value.conflicts.join('; '), 'warning');
                }
            }
            refresh();
            return result.isConfirmed;
        });
    }

    function confirmUnbook(ticket) {
        return Swal.fire({
            theme: document.documentElement.getAttribute('data-bs-theme') === 'dark' ? 'dark' : 'light',
            icon: 'question',
            title: 'Take ' + ticket.reference + ' off the schedule?',
            text: 'The booked time is cancelled and the tech and contact are told. It goes back in the queue.',
            showCancelButton: true,
            confirmButtonText: 'Take it off',
            reverseButtons: true,
            buttonsStyling: false,
            customClass: {confirmButton: 'btn btn-danger mx-1', cancelButton: 'btn btn-secondary mx-1'}
        }).then(function (result) {
            if (result.isConfirmed) {
                return request('POST', {unbook: 1, csrf_token: csrf, ticket_id: ticket.id}).then(function (json) {
                    toast(json.error || json.message, json.error ? 'error' : 'success');
                    refresh();
                });
            }
            refresh();
        });
    }

    // A ticket from a board item or a queue element
    function ticketFromEvent(event) {
        const props = event.extendedProps || {};
        const minutes = event.end && event.start ? Math.round((event.end - event.start) / 60000) : defaultMinutes;
        return {
            id: props.ticket_id, reference: (event.title || '').split(' ')[0], subject: event.title,
            minutes: minutes, onsite: (props.icon || '') === 'fa-car' ? 1 : 0
        };
    }
    function ticketFromQueue(el) {
        const found = queueTickets.find(function (t) {
            return t.id === Number(el.dataset.ticket);
        });
        return found || {id: Number(el.dataset.ticket), reference: el.dataset.reference, subject: '', minutes: defaultMinutes, onsite: 0};
    }

    // ---------- the queue ----------

    function loadQueue() {
        const params = Object.assign({queue: state.tab}, state.filters);
        request('GET', params).then(function (json) {
            if (json.error) {
                queueEl.innerHTML = '<div class="text-danger small p-2">' + escapeText(json.error) + '</div>';
                return;
            }
            queueTickets = json.tickets || [];
            Object.keys(json.counts || {}).forEach(function (key) {
                const badge = root.querySelector('[data-md-count="' + key + '"]');
                if (badge) {
                    badge.textContent = json.counts[key] || '';
                }
            });
            root.querySelectorAll('[data-md-tab]').forEach(function (tab) {
                tab.classList.toggle('active', tab.dataset.mdTab === state.tab);
            });
            if (!queueTickets.length) {
                queueEl.innerHTML = '<div class="text-center text-muted small py-4"><i class="fas fa-check-circle fa-2x text-success mb-2 d-block"></i>Nothing to dispatch here.</div>';
            } else {
                queueEl.innerHTML = queueTickets.map(function (t) {
                    return '<div class="mit-dq-item border rounded p-2 mb-2 small" data-ticket="' + t.id + '" data-reference="' + escapeText(t.reference) + '" data-priority="' + escapeText(t.priority) + '">'
                        + '<div class="d-flex align-items-center gap-1"><strong>' + escapeText(t.reference) + '</strong>'
                        + (t.priority ? '<span class="badge text-bg-light border">' + escapeText(t.priority) + '</span>' : '')
                        + (t.onsite ? '<i class="fas fa-car text-muted" title="Onsite"></i>' : '')
                        + '<span class="ms-auto text-muted">' + escapeText(t.age) + '</span></div>'
                        + '<div class="text-truncate" title="' + escapeText(t.subject) + '">' + escapeText(t.subject) + '</div>'
                        + '<div class="text-muted text-truncate">' + escapeText(t.client) + (t.tech_name ? ' · <i class="fas fa-user"></i> ' + escapeText(t.tech_name) : '') + (t.status ? ' · ' + escapeText(t.status) : '') + '</div>'
                        + '</div>';
                }).join('');
            }
        });
    }

    queueEl.addEventListener('click', function (e) {
        const item = e.target.closest('.mit-dq-item');
        if (item) {
            openModal('modals/ticket/ticket.php?id=' + item.dataset.ticket, 'lg');
        }
    });
    root.querySelectorAll('[data-md-tab]').forEach(function (tab) {
        tab.addEventListener('click', function (e) {
            e.preventDefault();
            state.tab = tab.dataset.mdTab;
            loadQueue();
        });
    });
    let filterTimer = null;
    root.querySelectorAll('[data-md-filter]').forEach(function (input) {
        input.addEventListener(input.tagName === 'SELECT' ? 'change' : 'input', function () {
            state.filters[input.dataset.mdFilter] = input.value;
            clearTimeout(filterTimer);
            filterTimer = setTimeout(loadQueue, 250);
        });
    });

    // ---------- the board ----------

    function range() {
        if (state.view === 'day') {
            return [state.date, state.date];
        }
        // Weekends hidden and it is one: the week coming, not the one ending
        let date = state.date;
        if (!state.weekends && (date.getDay() === 0 || date.getDay() === 6)) {
            date = addDays(date, date.getDay() === 6 ? 2 : 1);
        }
        const start = weekStart(date);
        return [start, addDays(start, 6)];
    }

    function loadItems(callback) {
        const r = range();
        request('GET', {items: 1, from: ymd(r[0]), to: ymd(r[1]), techs: shownTechs().map(function (t) {
            return t.id;
        }).join(',')}).then(function (items) {
            callback(Array.isArray(items) ? items : []);
        });
    }

    function teardown() {
        calendars.forEach(function (cal) {
            cal.destroy();
        });
        calendars = [];
        sortables.forEach(function (s) {
            s.destroy();
        });
        sortables = [];
        if (queueDraggable) {
            queueDraggable.destroy();
            queueDraggable = null;
        }
        if (queueSortable) {
            queueSortable.destroy();
            queueSortable = null;
        }
    }

    function render() {
        teardown();
        const r = range();
        titleEl.textContent = state.view === 'day'
            ? state.date.toLocaleDateString(undefined, {weekday: 'long', month: 'long', day: 'numeric', year: 'numeric'})
            : r[0].toLocaleDateString(undefined, {month: 'short', day: 'numeric'}) + ' - ' + r[1].toLocaleDateString(undefined, {month: 'short', day: 'numeric', year: 'numeric'});
        dateInput.value = ymd(state.date);
        root.querySelectorAll('[data-md-view]').forEach(function (b) {
            b.classList.toggle('active', b.dataset.mdView === state.view);
        });
        const count = root.querySelector('[data-md-tech-count]');
        count.textContent = shownTechs().length + '/' + techs.length;
        if (!shownTechs().length) {
            board.innerHTML = '<div class="text-center text-muted py-5">Pick some techs to show.</div>';
            return;
        }
        if (state.view === 'day') {
            renderDay();
        } else {
            renderWeek();
        }
    }

    function refresh() {
        loadQueue();
        if (state.view === 'day') {
            loadItems(function (items) {
                calendars.forEach(function (cal) {
                    cal.removeAllEvents();
                    cal.addEventSource(items.filter(function (item) {
                        return Number(item.extendedProps.tech) === Number(cal.mdTech);
                    }));
                });
                dayTotals(items);
            });
        } else {
            renderWeek();
        }
    }

    function overQueue(jsEvent) {
        const rect = queueEl.getBoundingClientRect();
        return jsEvent && jsEvent.clientX >= rect.left && jsEvent.clientX <= rect.right && jsEvent.clientY >= rect.top && jsEvent.clientY <= rect.bottom;
    }

    // Day: a time grid per tech
    function renderDay() {
        board.innerHTML = '<div class="mit-dispatch-cols">' + shownTechs().map(function (t) {
            return '<div class="mit-dispatch-col" data-tech="' + t.id + '"><div class="mit-dispatch-col-head small fw-semibold text-truncate">'
                + '<span class="d-inline-block rounded-circle me-1" style="width: 10px; height: 10px; background: ' + escapeText(t.color) + '"></span>'
                + escapeText(t.name) + ' <span class="fw-normal text-muted ms-1" data-md-booked></span></div><div data-md-cal></div></div>';
        }).join('') + '</div>';

        board.querySelectorAll('.mit-dispatch-col').forEach(function (col, index) {
            const techId = Number(col.dataset.tech);
            const cal = new FullCalendar.Calendar(col.querySelector('[data-md-cal]'), {
                initialView: 'timeGridDay',
                initialDate: ymd(state.date),
                headerToolbar: false,
                dayHeaders: false,
                allDaySlot: true,
                allDayText: '',
                height: 'calc(100vh - 16rem)',
                slotMinTime: '06:00:00',
                slotMaxTime: '21:00:00',
                scrollTime: '07:30:00',
                slotDuration: '00:30:00',
                snapDuration: '00:15:00',
                nowIndicator: true,
                editable: true,
                droppable: true,
                eventDurationEditable: true,
                businessHours: {daysOfWeek: [1, 2, 3, 4, 5], startTime: '08:00', endTime: '18:00'},
                // The times down the side once, on the first column
                slotHeaderContent: function (arg) {
                    return index === 0 ? arg.text : '';
                },
                eventDidMount: function (info) {
                    const props = info.event.extendedProps || {};
                    info.el.dataset.kind = props.kind || '';
                    info.el.setAttribute('title', info.event.title + (props.detail ? '\n' + props.detail : ''));
                },
                eventClick: function (info) {
                    info.jsEvent.preventDefault();
                    const props = info.event.extendedProps || {};
                    if (props.modal) {
                        openModal(props.modal, 'lg');
                    }
                },
                // From the queue
                drop: function (info) {
                    const start = new Date(info.date.getTime());
                    if (info.allDay) {
                        start.setHours(9, 0, 0, 0);
                    }
                    confirmBooking(ticketFromQueue(info.draggedEl), start, techId);
                },
                // To another time on this tech
                eventDrop: function (info) {
                    const ticket = ticketFromEvent(info.event);
                    const start = new Date(info.event.start.getTime());
                    if (info.event.allDay) {
                        start.setHours(9, 0, 0, 0);
                    }
                    confirmBooking(ticket, start, techId).then(function (ok) {
                        if (!ok) {
                            info.revert();
                        }
                    });
                },
                eventResize: function (info) {
                    confirmBooking(ticketFromEvent(info.event), info.event.start, techId).then(function (ok) {
                        if (!ok) {
                            info.revert();
                        }
                    });
                },
                // From another tech's column
                eventReceive: function (info) {
                    const ticket = ticketFromEvent(info.event);
                    const start = new Date(info.event.start.getTime());
                    if (info.event.allDay) {
                        start.setHours(9, 0, 0, 0);
                    }
                    confirmBooking(ticket, start, techId);
                },
                // Back onto the queue
                eventDragStop: function (info) {
                    queueEl.classList.remove('is-drop-target');
                    if (overQueue(info.jsEvent)) {
                        confirmUnbook(ticketFromEvent(info.event));
                    }
                },
                eventDragStart: function () {
                    queueEl.classList.add('is-drop-target');
                }
            });
            cal.mdTech = techId;
            cal.render();
            calendars.push(cal);
        });

        if (FullCalendar.Interaction && FullCalendar.Interaction.Draggable) {
            queueDraggable = new FullCalendar.Interaction.Draggable(queueEl, {
                itemSelector: '.mit-dq-item',
                eventData: function (item) {
                    const ticket = ticketFromQueue(item);
                    const minutes = ticket.minutes || defaultMinutes;
                    return {title: ticket.reference, duration: pad(Math.floor(minutes / 60)) + ':' + pad(minutes % 60), create: false, color: '#6c757d'};
                }
            });
        }
        refresh();
    }

    function dayTotals(items) {
        board.querySelectorAll('.mit-dispatch-col').forEach(function (col) {
            let minutes = 0;
            items.forEach(function (item) {
                if (Number(item.extendedProps.tech) === Number(col.dataset.tech) && ['ticket', 'helper'].indexOf(item.extendedProps.kind) !== -1 && !item.allDay) {
                    minutes += Math.round((new Date(item.end.replace(' ', 'T')) - new Date(item.start.replace(' ', 'T'))) / 60000);
                }
            });
            col.querySelector('[data-md-booked]').textContent = minutes ? lengthText(minutes) + ' booked' : '';
        });
    }

    // Week: techs by days
    function renderWeek() {
        teardown();
        const start = range()[0];
        const days = [];
        for (let i = 0; i < 7; i++) {
            const d = addDays(start, i);
            if (state.weekends || (d.getDay() !== 0 && d.getDay() !== 6)) {
                days.push(d);
            }
        }
        loadItems(function (items) {
            const today = ymd(new Date());
            let html = '<div class="table-responsive"><table class="table table-bordered table-sm mb-0 mit-dispatch-week"><thead><tr><th style="min-width: 150px">Tech</th>';
            days.forEach(function (d) {
                html += '<th class="small text-center' + (ymd(d) === today ? ' text-primary' : '') + '">' + d.toLocaleDateString(undefined, {weekday: 'short', month: 'short', day: 'numeric'}) + '</th>';
            });
            html += '</tr></thead><tbody>';
            shownTechs().forEach(function (t) {
                let week = 0;
                const cells = days.map(function (d) {
                    const day = ymd(d);
                    const chips = items.filter(function (item) {
                        return Number(item.extendedProps.tech) === t.id && String(item.start).substring(0, 10) === day;
                    }).sort(function (a, b) {
                        return String(a.start).localeCompare(String(b.start));
                    });
                    const off = d.getDay() === 0 || d.getDay() === 6;
                    return '<td class="mit-dw-cell' + (off ? ' is-off' : '') + '" data-tech="' + t.id + '" data-date="' + day + '">' + chips.map(function (item) {
                        const props = item.extendedProps || {};
                        const startText = item.allDay ? 'All day' : String(item.start).substring(11, 16);
                        let minutes = 0;
                        if (!item.allDay && item.end) {
                            minutes = Math.round((new Date(String(item.end).replace(' ', 'T')) - new Date(String(item.start).replace(' ', 'T'))) / 60000);
                            if (['ticket', 'helper'].indexOf(props.kind) !== -1) {
                                week += minutes;
                            }
                        }
                        return '<div class="mit-dw-chip" style="background: ' + escapeText(item.color || '#6c757d') + '" data-kind="' + escapeText(props.kind || '') + '"'
                            + (props.kind === 'ticket' && item.editable ? ' data-ticket="' + props.ticket_id + '" data-start="' + escapeText(String(item.start).substring(0, 16)) + '" data-minutes="' + minutes + '" data-onsite="' + (props.icon === 'fa-car' ? 1 : 0) + '"' : '')
                            + ' data-modal="' + escapeText(props.modal || '') + '" title="' + escapeText(item.title + (props.detail ? '\n' + props.detail : '')) + '">'
                            + escapeText(startText + ' ' + item.title) + '</div>';
                    }).join('') + '</td>';
                }).join('');
                html += '<tr><th class="small"><span class="d-inline-block rounded-circle me-1" style="width: 10px; height: 10px; background: ' + escapeText(t.color) + '"></span>'
                    + escapeText(t.name) + '<div class="fw-normal text-muted">' + (week ? lengthText(week) + ' booked' : '') + '</div></th>' + cells + '</tr>';
            });
            html += '</tbody></table></div>';
            board.innerHTML = html;

            board.querySelectorAll('.mit-dw-chip').forEach(function (chip) {
                chip.addEventListener('click', function () {
                    if (chip.dataset.modal) {
                        openModal(chip.dataset.modal, 'lg');
                    }
                });
            });
            if (typeof Sortable === 'undefined') {
                return;
            }
            board.querySelectorAll('.mit-dw-cell').forEach(function (cell) {
                sortables.push(Sortable.create(cell, {
                    group: {name: 'mit-dispatch', pull: true, put: true},
                    sort: false,
                    draggable: '.mit-dw-chip[data-ticket], .mit-dq-item',
                    forceFallback: true,
                    onStart: function () {
                        queueEl.classList.add('is-drop-target');
                    },
                    onEnd: function () {
                        queueEl.classList.remove('is-drop-target');
                    },
                    onAdd: function (evt) {
                        const item = evt.item;
                        const techId = Number(cell.dataset.tech);
                        const date = cell.dataset.date;
                        let ticket;
                        let time = '09:00';
                        if (item.classList.contains('mit-dq-item')) {
                            ticket = ticketFromQueue(item);
                        } else {
                            ticket = {id: Number(item.dataset.ticket), reference: item.textContent.split(' ')[1] || '', subject: item.getAttribute('title') || '',
                                minutes: Number(item.dataset.minutes) || defaultMinutes, onsite: Number(item.dataset.onsite)};
                            time = (item.dataset.start || '').substring(11, 16) || time;
                        }
                        const at = new Date(date + 'T' + time + ':00');
                        confirmBooking(ticket, at, techId);
                    }
                }));
            });
            // The queue takes bookings back (and gives tickets out)
            queueSortable = Sortable.create(queueEl, {
                group: {name: 'mit-dispatch', pull: 'clone', put: function (to, from, el) {
                    return el.classList.contains('mit-dw-chip');
                }},
                sort: false,
                draggable: '.mit-dq-item',
                forceFallback: true,
                onAdd: function (evt) {
                    const chip = evt.item;
                    chip.remove();
                    confirmUnbook({id: Number(chip.dataset.ticket), reference: chip.textContent.split(' ')[1] || 'the ticket'});
                }
            });
        });
    }

    // ---------- the toolbar ----------

    root.querySelectorAll('[data-md-view]').forEach(function (b) {
        b.addEventListener('click', function () {
            state.view = b.dataset.mdView;
            saveState();
            render();
        });
    });
    root.querySelectorAll('[data-md-nav]').forEach(function (b) {
        b.addEventListener('click', function () {
            const step = Number(b.dataset.mdNav);
            state.date = step === 0 ? new Date() : addDays(state.date, step * (state.view === 'day' ? 1 : 7));
            render();
        });
    });
    dateInput.addEventListener('change', function () {
        const d = parseYmd(dateInput.value);
        if (d) {
            state.date = d;
            render();
        }
    });
    root.querySelectorAll('[data-md-tech]').forEach(function (box) {
        box.addEventListener('change', function () {
            const id = Number(box.value);
            state.hidden = state.hidden.filter(function (h) {
                return h !== id;
            });
            if (!box.checked) {
                state.hidden.push(id);
            }
            saveState();
            render();
        });
    });
    root.querySelector('[data-md-weekends]').addEventListener('change', function (e) {
        state.weekends = e.target.checked;
        saveState();
        if (state.view === 'week') {
            render();
        }
    });
    // A ticket changed in its quick look - the queue and board follow
    document.addEventListener('hidden.bs.modal', function () {
        refresh();
    });

    render();
    loadQueue();
})();
