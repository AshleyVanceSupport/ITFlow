/*
 * Mountaineer IT - the timesheet page (agent/mit_timesheet.php, functions/mit_timesheet_view.php): draws the
 * week tabs and a row a day from #mitTsData, opens a day on the side to fill in - start, meal and end times on
 * California rules (the meal checks as mitTsCaAnalyze() does them on the server), ticket time, hours by charge
 * code with notes - and saves it as it is typed (agent/mit_timesheet_ajax.php), taking back what the server
 * worked out. Submitting waits for the last save; on California rules it opens the signing window first.
 */
(function () {
    const D = JSON.parse(document.getElementById('mitTsData').textContent);
    const hm = D.format === 'hm';
    const weeksEl = document.getElementById('mitTsWeeks');
    const daysEl = document.getElementById('mitTsDays');
    const panelEl = document.getElementById('mitTsDay');
    const panel = bootstrap.Offcanvas.getOrCreateInstance(panelEl);
    const body = panelEl.querySelector('[data-mit-day-body]');
    const savedEl = document.getElementById('mitTsSaved');
    const readOnly = D.locked !== '';
    const BOXES = [['start', 'Start'], ['meal_out', 'Meal out'], ['meal_in', 'Back'], ['meal2_out', '2nd meal out'], ['meal2_in', 'Back'], ['end', 'End']];
    const ISSUES = {
        1: { missing: 'No meal period - one is due for a day over 5 hours', late: 'Meal period started after the 5th hour', short: 'Meal period was under 30 minutes' },
        2: { missing: 'No 2nd meal period - one is due for a day over 10 hours', late: '2nd meal period started after the 10th hour', short: '2nd meal period was under 30 minutes' },
    };
    let week = Math.min(D.open_week || 0, D.weeks.length - 1);
    let current = null;      // the date open on the side
    let timer = null;        // the save waiting for typing to stop
    let saving = null;       // the save on its way
    let again = false;       // something changed while saving
    let removed = new Set(); // codes taken off the open day

    // ---------- small helpers ----------
    const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    function hours(m, zero) {
        m = Math.round(m || 0);
        if (!m) return zero ? (hm ? '0:00' : '0') : '';
        return hm ? Math.floor(m / 60) + ':' + String(m % 60).padStart(2, '0') : String(+(m / 60).toFixed(2));
    }
    // "7.5", "7:30", "7h30", "450m" -> minutes, as mitTsParseHours() reads them; null when it cannot be read
    function parse(text) {
        text = String(text || '').trim().toLowerCase().replace(',', '.');
        if (text === '') return 0;
        let m = text.match(/^(\d{1,2}):(\d{1,2})$/) || text.match(/^(\d{1,2})h\s*(\d{1,2})?m?$/);
        if (m) return +(m[2] || 0) < 60 ? +m[1] * 60 + +(m[2] || 0) : null;
        m = text.match(/^(\d{1,4})\s*m(in)?$/);
        if (m) return +m[1];
        return text !== '.' && /^\d{0,2}(\.\d{1,2})?$/.test(text) ? Math.round(parseFloat(text) * 60) : null;
    }
    function clock(t) {
        if (!t) return '';
        const [h, m] = t.split(':').map(Number);
        return (h % 12 || 12) + ':' + String(m).padStart(2, '0') + (h < 12 ? ' AM' : ' PM');
    }
    function chip(color, label, m, tone) {
        return '<span class="mit-ts-chip' + (tone ? ' mit-ts-chip-' + tone : '') + '">' + (color ? '<i style="background:' + esc(color) + '"></i>' : '') + esc(label) + (m !== null ? ' <b>' + hours(m, true) + '</b>' : '') + '</span>';
    }

    // ---------- the meal rules, as on the server ----------
    function periods(b) {
        if (!b.start || !b.end) return [];
        const points = ['start', 'meal_out', 'meal_in', 'meal2_out', 'meal2_in', 'end'].map((k) => b[k]).filter(Boolean);
        if (points.length % 2) return [];
        const stamps = [];
        let day = 0;
        points.forEach(function (time, i) {
            const [h, m] = time.split(':').map(Number);
            let t = h * 60 + m + day * 1440;
            if (i && t <= stamps[i - 1]) { day++; t += 1440; }
            stamps.push(t);
        });
        const list = [];
        for (let i = 0; i + 1 < stamps.length; i += 2) list.push([stamps[i], stamps[i + 1]]);
        return list;
    }
    function analyze(b) {
        let worked = 0, lastEnd = null;
        const gaps = [];
        periods(b).forEach(function (p) {
            if (lastEnd !== null) gaps.push({ before: worked, minutes: Math.max(0, p[0] - lastEnd) });
            worked += Math.max(0, p[1] - p[0]);
            lastEnd = p[1];
        });
        const meals = { 1: null, 2: null };
        let used = -1;
        [[1, 300], [2, 600]].forEach(function ([n, by]) {
            if (worked <= by) return;
            let status = 'missing';
            for (let i = used + 1; i < gaps.length; i++) {
                if (gaps[i].minutes >= 30 && gaps[i].before <= by) { status = 'ok'; used = i; break; }
            }
            if (status !== 'ok' && used + 1 < gaps.length) { used++; status = gaps[used].before <= by ? 'short' : 'late'; }
            meals[n] = status;
        });
        return { worked: worked, meals: meals, mealMinutes: gaps.reduce((s, g) => s + g.minutes, 0) };
    }

    // ---------- the week tabs and the day rows ----------
    function weekTotal(dates) {
        return dates.reduce((s, d) => s + (D.days[d] ? D.days[d].total : 0), 0);
    }
    function drawWeeks() {
        weeksEl.innerHTML = D.weeks.map(function (dates, i) {
            const first = D.days[dates[0]], last = D.days[dates[dates.length - 1]];
            const label = first.label + (dates.length > 1 ? ' - ' + (last.label.split(' ')[0] === first.label.split(' ')[0] ? last.label.split(' ')[1] : last.label) : '');
            const total = weekTotal(dates);
            return '<li class="nav-item"><button type="button" class="nav-link' + (i === week ? ' active' : '') + '" data-week="' + i + '">' + esc(label)
                + '<span class="mit-ts-week-total">' + (total ? hours(total) + ' h' : '-') + '</span></button></li>';
        }).join('');
    }
    function dayRow(date) {
        const d = D.days[date];
        const parts = [];
        let times = '';
        const flags = [];
        if (d.ca) {
            const b = d.ca.boxes;
            if (b.start && b.end) {
                const meal = analyze(b).mealMinutes;
                times = '<span class="mit-ts-times">' + clock(b.start) + ' - ' + clock(b.end) + '</span>' + (meal ? '<span class="text-body-secondary"> · ' + meal + ' min meal</span>' : '<span class="text-body-secondary"> · no meal</span>');
            }
            [1, 2].forEach(function (n) {
                const s = d.ca.meals[n];
                if (s && s !== 'ok') {
                    const a = d.ca.answers['meal' + n];
                    flags.push(a ? chip('', a === 'chose' ? 'Meal ' + (n === 2 ? '2 ' : '') + s + ' - chose' : 'Meal premium', null, a === 'chose' ? 'muted' : 'danger')
                        : chip('', 'Meal ' + (n === 2 ? '2 ' : '') + s + ' - say why', null, 'danger'));
                }
            });
            if (d.ca.answers.rest_missed) flags.push(chip('', 'Rest premium', null, 'danger'));
        }
        if (d.tickets.billable) parts.push(chip('#198754', 'Billable', d.tickets.billable));
        if (d.tickets.other) parts.push(chip('#6c757d', 'Other tickets', d.tickets.other));
        Object.entries(d.codes).forEach(function ([id, e]) {
            const c = D.codes[id] || { name: 'Code ' + id, color: '#6c757d' };
            if (e.m) parts.push(chip(c.color, c.name, e.m));
        });
        if (d.break_paid) parts.push(chip('#20c997', 'Paid breaks', d.break_paid));
        if (d.ca && d.ca.worked) {
            // On California rules the times are the hours worked - more than that on tickets and work codes is a mistake somewhere
            const spent = d.tickets.billable + d.tickets.other + Object.entries(d.codes).reduce((s, [id, e]) => s + ((D.codes[id] || {}).kind === 'leave' ? 0 : e.m), 0);
            if (spent > d.ca.worked) flags.push(chip('', 'Codes over hours worked', null, 'warning'));
        }
        if (d.unallocated && (parts.length || d.ca)) parts.push(chip('', 'Not on a code', d.unallocated, 'muted'));
        if (d.auto_lost) flags.push(chip('', 'Unaccounted', d.auto_lost, 'warning'));
        if (d.under && !d.auto_lost) flags.push(chip('', 'Under ' + hours(d.expected) + ' h', null, 'warning'));
        const empty = !times && !parts.length;
        const off = !d.expected;
        return '<button type="button" class="list-group-item list-group-item-action mit-ts-day' + (d.today ? ' is-today' : '') + (off ? ' is-off' : '') + (date === current ? ' active-day' : '') + '" data-date="' + date + '">'
            + '<div class="mit-ts-day-date"><div class="mit-ts-dow">' + d.dow + '</div><div class="mit-ts-dnum">' + esc(d.label) + '</div></div>'
            + '<div class="mit-ts-day-main">'
            + (d.closure ? '<span class="badge text-bg-success-subtle text-success-emphasis me-2">' + esc(d.closure) + '</span>' : '')
            + (times ? '<div class="mb-1">' + times + '</div>' : '')
            + (parts.length || flags.length ? '<div class="mit-ts-chips">' + parts.join('') + flags.join('') + '</div>' : '')
            + (empty && !d.closure ? '<span class="text-body-tertiary small">' + (d.future ? '' : (readOnly ? 'Nothing logged' : (off ? 'Day off' : 'Add your time'))) + '</span>' : '')
            + '</div>'
            + '<div class="mit-ts-day-total">' + (d.total ? hours(d.total) : '<span class="text-body-tertiary">-</span>') + '</div>'
            + '<i class="fas fa-chevron-right text-body-tertiary ms-2"></i></button>';
    }
    function drawDays() {
        daysEl.innerHTML = D.weeks[week].map(dayRow).join('');
        const total = weekTotal(D.weeks[week]);
        daysEl.insertAdjacentHTML('beforeend', '<div class="list-group-item mit-ts-week-foot"><span>Week total</span><b>' + hours(total, true) + ' h</b></div>');
    }
    function draw() {
        drawWeeks();
        drawDays();
    }

    // ---------- one day, on the side ----------
    function usableCodes(date, have) {
        const d = D.days[date];
        return Object.entries(D.codes).filter(function ([id, c]) {
            if (!c.usable || have.has(id)) return false;
            if (d.future && D.future === 'none') return false;
            if (d.future && D.future === 'leave') return c.kind === 'leave';
            return true;
        });
    }
    function codeRow(id, e) {
        const c = D.codes[id] || { name: 'Code ' + id, color: '#6c757d', note: 0 };
        const dis = readOnly ? ' disabled' : '';
        return '<div class="mit-ts-code" data-code="' + id + '">'
            + '<div class="d-flex align-items-center gap-2"><span class="mit-ts-dot" style="background:' + esc(c.color) + '"></span>'
            + '<span class="flex-grow-1">' + esc(c.name) + (c.paid ? '' : ' <span class="badge text-bg-light border">Unpaid</span>') + (c.kind === 'leave' ? ' <span class="badge text-bg-light border">Leave</span>' : '') + '</span>'
            + '<input type="text" inputmode="decimal" class="form-control form-control-sm text-end mit-ts-hours" data-hours value="' + hours(e.m) + '" placeholder="0" aria-label="' + esc(c.name) + ' hours"' + dis + '>'
            + (readOnly ? '' : '<button type="button" class="btn btn-sm btn-link text-body-secondary px-1" data-note-toggle title="Note"><i class="far fa-comment-dots"></i></button>'
                + '<button type="button" class="btn btn-sm btn-link text-body-secondary px-1" data-remove title="Take off"><i class="fas fa-times"></i></button>') + '</div>'
            // The note shows when there is one, or the code needs one; otherwise behind the note button
            + '<input type="text" class="form-control form-control-sm mt-1" data-note maxlength="255" value="' + esc(e.note) + '" placeholder="' + (c.note ? 'Note (needed)' : 'Note') + '"' + dis + (e.note || c.note ? '' : ' hidden') + '>'
            + '</div>';
    }
    function openDay(date) {
        flush();
        current = date;
        removed = new Set();
        const d = D.days[date];
        panelEl.querySelector('#mitTsDayTitle').textContent = d.long;
        panelEl.querySelector('[data-mit-day-sub]').textContent = (d.closure ? d.closure + ' · ' : '') + (d.expected ? 'Normal day ' + hours(d.expected) + ' h' : 'Day off');
        let html = '';
        const dis = readOnly ? ' disabled' : '';
        if (d.ca) {
            const b = d.ca.boxes;
            const second = !!(b.meal2_out || b.meal2_in);
            const timeDis = readOnly || (d.future && !b.start) ? ' disabled' : '';
            html += '<section class="mb-4"><h6 class="mit-ts-section">Work times</h6><div class="row g-2 mit-ts-time-grid">';
            BOXES.forEach(function ([key, label]) {
                const two = key.startsWith('meal2');
                html += '<div class="col-6' + (two ? ' mit-ts-meal2' : '') + '"' + (two && !second ? ' hidden' : '') + '><label class="form-label small mb-1">' + label + '</label>'
                    + '<input type="time" step="60" class="form-control" data-box="' + key + '" value="' + esc(b[key]) + '"' + timeDis + '></div>';
            });
            html += '</div>'
                + (readOnly ? '' : '<button type="button" class="btn btn-sm btn-link px-0" data-meal2' + (second ? ' hidden' : '') + '><i class="fas fa-plus me-1"></i>Second meal period</button>')
                + '<div class="d-flex justify-content-between align-items-center mt-2 small"><span class="text-body-secondary">Hours worked</span><b data-worked></b></div>'
                + '<div data-issues></div>'
                + '<div class="form-check mt-2"><input class="form-check-input" type="checkbox" data-answer="rest_missed" id="mitTsRest"' + (d.ca.answers.rest_missed ? ' checked' : '') + (readOnly || !D.own ? ' disabled' : '') + '>'
                + '<label class="form-check-label small" for="mitTsRest">I could not take a rest break this day</label></div>'
                + (!D.own && !readOnly ? '<div class="mt-3"><label class="form-label small mb-1">Why are you changing their times? <span class="text-danger">*</span></label><input type="text" class="form-control form-control-sm" data-reason maxlength="255" placeholder="They see this and are asked to acknowledge it"></div>' : '')
                + '</section>';
        }
        html += '<section><h6 class="mit-ts-section">' + (d.ca ? 'What the time was spent on' : 'Hours') + '</h6>';
        if (d.tickets.billable || d.tickets.other) {
            html += '<a href="#" class="mit-ts-auto ajax-modal" data-modal-url="modals/mit_timesheet/day.php?user_id=' + D.user_id + '&date=' + date + '">'
                + (d.tickets.billable ? '<span>' + chip('#198754', 'Ticket time - billable', d.tickets.billable) + '</span>' : '')
                + (d.tickets.other ? '<span>' + chip('#6c757d', 'Ticket time - other', d.tickets.other) + '</span>' : '')
                + '<span class="small ms-auto">The tickets <i class="fas fa-external-link-alt"></i></span></a>';
        }
        html += '<div data-codes>' + Object.entries(d.codes).map(([id, e]) => codeRow(id, e)).join('') + '</div>';
        if (!readOnly) {
            html += '<select class="form-select form-select-sm mt-2" data-add aria-label="Add time to a code"></select>';
        }
        if (d.break_paid) html += '<div class="small text-body-secondary mt-2"><i class="fas fa-coffee me-1"></i>Paid breaks added for you: ' + hours(d.break_paid) + ' h</div>';
        if (d.auto_lost) html += '<div class="small text-warning-emphasis mt-2"><i class="fas fa-hourglass-half me-1"></i>' + hours(d.auto_lost) + ' h of your normal day is not accounted for</div>';
        html += '</section>';
        body.innerHTML = html;
        fillAdd();
        live();
        status(readOnly ? '<i class="fas fa-lock me-1"></i>Read only' : '');
        draw();
        panel.show();
    }
    function fillAdd() {
        const add = body.querySelector('[data-add]');
        if (!add) return;
        const have = new Set([...body.querySelectorAll('[data-code]')].map((r) => r.dataset.code));
        const options = usableCodes(current, have);
        add.innerHTML = '<option value="">+ Add time to...</option>' + options.map(([id, c]) => '<option value="' + id + '">' + esc(c.name) + '</option>').join('');
        add.hidden = !options.length;
    }
    // What the day adds up to as it is typed - the server's figures replace it after each save
    function live() {
        const d = D.days[current];
        let codes = 0;
        body.querySelectorAll('[data-code]').forEach(function (row) {
            const m = parse(row.querySelector('[data-hours]').value);
            const c = D.codes[row.dataset.code] || {};
            row.querySelector('[data-hours]').classList.toggle('is-invalid', m === null || (c.max > 0 && m > c.max));
            if (m && (!d.ca || c.kind === 'leave')) codes += m;
        });
        let total;
        if (d.ca) {
            const b = boxes();
            const a = analyze(b);
            body.querySelector('[data-worked]').textContent = a.worked ? hours(a.worked, true) + ' h' : '-';
            const issues = body.querySelector('[data-issues]');
            issues.innerHTML = [1, 2].map(function (n) {
                const s = a.meals[n];
                if (!s || s === 'ok') return '';
                const given = d.ca.answers['meal' + n] || '';
                return '<div class="mit-ts-issue mt-2"><div class="small mb-1"><i class="fas fa-exclamation-triangle me-1"></i>' + ISSUES[n][s] + '</div>'
                    + '<select class="form-select form-select-sm' + (given ? '' : ' is-invalid') + '" data-answer="meal' + n + '"' + (readOnly || !D.own ? ' disabled' : '') + '>'
                    + '<option value="">Why? - choose</option>' + Object.entries(D.answers).map(([v, l]) => '<option value="' + v + '"' + (v === given ? ' selected' : '') + '>' + esc(l) + '</option>').join('') + '</select></div>';
            }).join('');
            total = a.worked + codes;
        } else {
            total = d.tickets.billable + d.tickets.other + codes + d.break_paid;
        }
        panelEl.querySelector('[data-mit-day-total]').textContent = hours(total, true) + ' h';
    }
    function boxes() {
        const b = {};
        body.querySelectorAll('[data-box]').forEach((i) => { b[i.dataset.box] = i.value; });
        return b;
    }
    function status(html, tone) {
        const el = panelEl.querySelector('[data-mit-day-status]');
        el.innerHTML = html;
        el.className = 'small me-auto ' + (tone === 'error' ? 'text-danger' : 'text-body-secondary');
    }
    function topStatus(html) {
        savedEl.innerHTML = html;
    }

    // ---------- saving ----------
    function changed() {
        if (readOnly || !current) return;
        live();
        clearTimeout(timer);
        topStatus('<i class="fas fa-circle-notch fa-spin me-1"></i>Saving...');
        status('Saving...');
        timer = setTimeout(save, 800);
    }
    function problem() {
        const bad = [...body.querySelectorAll('[data-hours].is-invalid')][0];
        if (bad) return 'Type hours as e.g. 7.5 or 7:30' + (D.codes[bad.closest('[data-code]').dataset.code].max ? ' (at most ' + hours(D.codes[bad.closest('[data-code]').dataset.code].max) + ' a day)' : '');
        const note = [...body.querySelectorAll('[data-code]')].find(function (row) {
            return D.codes[row.dataset.code]?.note && parse(row.querySelector('[data-hours]').value) > 0 && !row.querySelector('[data-note]').value.trim();
        });
        if (note) return D.codes[note.dataset.code].name + ' needs a note';
        const b = boxes();
        if (D.days[current].ca && ((b.meal_out && !b.meal_in) || (!b.meal_out && b.meal_in) || (b.meal2_out && !b.meal2_in) || (!b.meal2_out && b.meal2_in))) return 'A meal period needs both its out and back times';
        if (D.days[current].ca && (b.start || b.end) && !(b.start && b.end)) return 'Add both a start and an end';
        const reason = body.querySelector('[data-reason]');
        if (reason && !reason.value.trim() && JSON.stringify(b) !== JSON.stringify(D.days[current].ca.boxes)) return 'Say why you are changing their times';
        return '';
    }
    function save() {
        clearTimeout(timer);
        timer = null;
        if (!current) return Promise.resolve();
        if (saving) { again = true; return saving; }
        const why = problem();
        if (why) {
            status('<i class="fas fa-exclamation-circle me-1"></i>' + esc(why) + ' - not saved yet', 'error');
            topStatus('<i class="fas fa-exclamation-circle text-danger me-1"></i>Not saved - ' + esc(why));
            return Promise.resolve();
        }
        const date = current;
        const form = new FormData();
        form.append('save_day', '1');
        form.append('csrf_token', D.csrf);
        form.append('user_id', D.user_id);
        form.append('start', D.start);
        form.append('date', date);
        if (D.days[date].ca) {
            Object.entries(boxes()).forEach(([k, v]) => form.append('times[' + k + ']', v));
            body.querySelectorAll('[data-answer]').forEach(function (el) {
                form.append('answers[' + el.dataset.answer + ']', el.type === 'checkbox' ? (el.checked ? '1' : '') : el.value);
            });
            const reason = body.querySelector('[data-reason]');
            if (reason) form.append('reason', reason.value);
        }
        body.querySelectorAll('[data-code]').forEach(function (row) {
            form.append('cells[' + row.dataset.code + ']', row.querySelector('[data-hours]').value);
            form.append('notes[' + row.dataset.code + ']', row.querySelector('[data-note]').value);
        });
        removed.forEach((id) => form.append('cells[' + id + ']', ''));
        saving = fetch('mit_timesheet_ajax.php', { method: 'POST', body: form, credentials: 'same-origin' })
            .then((r) => r.json())
            .then(function (r) {
                if (r.error) throw new Error(r.error);
                if (r.day) D.days[date] = r.day;
                removed = new Set();
                document.getElementById('mitTsSummary').innerHTML = r.summary;
                document.getElementById('mitTsLeave').innerHTML = r.leave;
                if (D.ca) {
                    document.getElementById('mitTsChanges').innerHTML = r.changes;
                    D.problems = r.problems;
                    const list = document.querySelector('[data-mit-ca-problems]');
                    if (list) list.innerHTML = problemsHtml();
                }
                draw();
                const at = new Date().toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' });
                if (r.ok) {
                    status('<i class="fas fa-check-circle text-success me-1"></i>Saved ' + at);
                    topStatus('<i class="fas fa-check-circle text-success me-1"></i>All changes saved');
                    if (current === date && D.days[date].ca) live();
                } else {
                    status('<i class="fas fa-exclamation-circle me-1"></i>' + esc(r.message), 'error');
                    topStatus('<i class="fas fa-exclamation-circle text-danger me-1"></i>Not saved - ' + esc(r.message));
                }
            })
            .catch(function (e) {
                status('<i class="fas fa-exclamation-circle me-1"></i>' + esc(e.message || 'Could not save - check your connection'), 'error');
                topStatus('<i class="fas fa-exclamation-circle text-danger me-1"></i>Not saved');
            })
            .finally(function () {
                saving = null;
                if (again) { again = false; return save(); }
            });
        return saving;
    }
    // Save now what is waiting (before leaving a day or submitting)
    function flush() {
        if (timer) return save();
        return saving || Promise.resolve();
    }
    function problemsHtml() {
        if (!D.problems.length) return '';
        return '<div class="alert alert-warning small"><strong>Before you can submit:</strong><ul class="mb-0">' + D.problems.map((p) => '<li>' + esc(p) + '</li>').join('') + '</ul></div>';
    }

    // ---------- events ----------
    weeksEl.addEventListener('click', function (e) {
        const b = e.target.closest('[data-week]');
        if (!b) return;
        week = +b.dataset.week;
        draw();
    });
    daysEl.addEventListener('click', function (e) {
        const row = e.target.closest('[data-date]');
        if (row) openDay(row.dataset.date);
    });
    panelEl.querySelectorAll('[data-mit-day-step]').forEach(function (b) {
        b.addEventListener('click', function () {
            const all = D.weeks.flat();
            const i = all.indexOf(current) + +b.dataset.mitDayStep;
            if (i < 0 || i >= all.length) return;
            week = D.weeks.findIndex((w) => w.includes(all[i]));
            openDay(all[i]);
        });
    });
    panelEl.addEventListener('hide.bs.offcanvas', function () {
        flush();
    });
    panelEl.addEventListener('hidden.bs.offcanvas', function () {
        current = null;
        draw();
    });
    body.addEventListener('input', function (e) {
        if (e.target.matches('[data-hours], [data-note], [data-box], [data-reason]')) changed();
    });
    body.addEventListener('change', function (e) {
        if (e.target.matches('[data-answer]')) {
            if (e.target.dataset.answer !== 'rest_missed') {
                D.days[current].ca.answers[e.target.dataset.answer] = e.target.value;
                e.target.classList.toggle('is-invalid', e.target.value === '');
            }
            changed();
        }
        if (e.target.matches('[data-add]') && e.target.value) {
            const id = e.target.value;
            removed.delete(id);
            body.querySelector('[data-codes]').insertAdjacentHTML('beforeend', codeRow(id, { m: 0, note: '' }));
            body.querySelector('[data-code="' + id + '"] [data-hours]').focus();
            fillAdd();
        }
    });
    body.addEventListener('click', function (e) {
        if (e.target.closest('[data-remove]')) {
            const row = e.target.closest('[data-code]');
            removed.add(row.dataset.code);
            row.remove();
            fillAdd();
            changed();
        }
        if (e.target.closest('[data-note-toggle]')) {
            const note = e.target.closest('[data-code]').querySelector('[data-note]');
            note.hidden = false;
            note.focus();
        }
        if (e.target.closest('[data-meal2]')) {
            body.querySelectorAll('.mit-ts-meal2').forEach((c) => { c.hidden = false; });
            e.target.closest('[data-meal2]').hidden = true;
        }
    });
    window.addEventListener('beforeunload', function (e) {
        if (timer || saving) {
            flush();
            e.preventDefault();
            e.returnValue = '';
        }
    });

    // Submitting: the last save first; on California rules, the signing window
    const form = document.getElementById('mitTsForm');
    const sign = document.getElementById('mitCaSign');
    form.addEventListener('submit', function (e) {
        const by = e.submitter;
        if (!by || !by.matches('[data-mit-ts-submit]') || form.dataset.ready === '1') return;
        e.preventDefault();
        flush().then(function () {
            if (sign) {
                const list = sign.querySelector('[data-mit-ca-problems]');
                if (list) list.innerHTML = problemsHtml();
                bootstrap.Modal.getOrCreateInstance(sign).show();
            } else {
                form.dataset.ready = '1';
                form.requestSubmit(by);
            }
        });
    });

    draw();
})();
