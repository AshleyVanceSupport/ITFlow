/*
 * Mountaineer IT - the timesheet grid (agent/mit_timesheet.php, functions/mit_timesheets.php): adds up
 * each row, day and week as hours are typed, works out the unaccounted (lost) time, keeps each week's
 * note bar on the box last clicked, shows leave left, and checks the hours before saving so a mistake
 * does not lose what was typed. Reads its settings from #mitTsForm's data- attributes.
 */
(function () {
    const form = document.getElementById('mitTsForm');
    const hm = form.dataset.format === 'hm';
    const today = form.dataset.today;
    const autoLost = form.dataset.autoLost === '1';
    const started = form.dataset.started || '';
    // Paid breaks: breakMinutes for every breakPer minutes worked (functions/mit_timesheets.php mitTsBreakFor)
    const breakMinutes = +form.dataset.breakMinutes || 0;
    const breakPer = Math.max(30, +form.dataset.breakPer || 240);
    // "7.5", "7:30", "7h30", "450m" -> minutes, as mitTsParseHours() reads them on the server
    function minutes(text) {
        text = String(text || '').trim().replace(',', '.');
        let m = text.match(/^(\d{1,2}):(\d{1,2})$/) || text.match(/^(\d{1,2})h\s*(\d{1,2})?m?$/i);
        if (m) return +(m[2] || 0) < 60 ? +m[1] * 60 + +(m[2] || 0) : 0;
        m = text.match(/^(\d{1,4})\s*m(in)?$/i);
        if (m) return +m[1];
        const n = parseFloat(text);
        return isNaN(n) ? 0 : Math.round(n * 60);
    }
    function hours(m, zero) {
        if (!m) return zero ? (hm ? '0:00' : '0') : '';
        return hm ? Math.floor(m / 60) + ':' + String(m % 60).padStart(2, '0') : String(+(m / 60).toFixed(2));
    }
    function sum() {
        form.querySelectorAll('details').forEach(function (week) {
            const table = week.querySelector('.mit-ts-table');
            const days = {};
            const worked = {};
            let total = 0;
            table.querySelectorAll('tbody tr').forEach(function (row) {
                const totalCell = row.querySelector('[data-mit-ts-row-total]');
                if (!totalCell) return;
                let rowTotal = 0;
                row.querySelectorAll('[data-mit-ts-auto], input[data-date]').forEach(function (cell) {
                    const m = cell.matches('input') ? minutes(cell.value) : +cell.dataset.minutes;
                    days[cell.dataset.date] = (days[cell.dataset.date] || 0) + m;
                    if (cell.dataset.worked === '1') {
                        worked[cell.dataset.date] = (worked[cell.dataset.date] || 0) + m;
                    }
                    rowTotal += m;
                    if (cell.matches('input')) {
                        const max = +cell.dataset.max;
                        cell.classList.toggle('is-invalid', max > 0 && m > max);
                    }
                });
                totalCell.textContent = hours(rowTotal);
                total += rowTotal;
            });
            let unaccounted = 0;
            let breaks = 0;
            table.querySelectorAll('[data-mit-ts-day]').forEach(function (cell) {
                const date = cell.dataset.mitTsDay;
                // Paid breaks count in the day, as accounted-for time (as stored, on an approved period)
                const breakCell = table.querySelector('[data-mit-ts-breaks="' + date + '"]');
                let paidBreak = 0;
                if (breakCell) {
                    paidBreak = breakCell.dataset.fixed !== undefined ? +breakCell.dataset.fixed
                        : (breakMinutes > 0 ? Math.floor((worked[date] || 0) / breakPer) * breakMinutes : 0);
                    breakCell.textContent = hours(paidBreak);
                    breaks += paidBreak;
                }
                const m = (days[date] || 0) + paidBreak;
                const expected = +cell.dataset.expected;
                cell.textContent = hours(m, expected > 0 && date <= today);
                cell.classList.toggle('text-warning', expected > 0 && date <= today && m < expected);
                cell.classList.toggle('text-danger', m > 1440);
                const gapCell = table.querySelector('[data-mit-ts-unaccounted="' + date + '"]');
                if (gapCell) {
                    // An approved period shows what was stored; otherwise worked out as the server does
                    const gap = gapCell.dataset.fixed !== undefined ? +gapCell.dataset.fixed
                        : (autoLost && date < today && date >= started ? Math.max(0, expected - m) : 0);
                    gapCell.textContent = hours(gap);
                    unaccounted += gap;
                }
            });
            const breakTotal = table.querySelector('[data-mit-ts-breaks-total]');
            if (breakTotal) breakTotal.textContent = hours(breaks);
            total += breaks;
            const gapTotal = table.querySelector('[data-mit-ts-unaccounted-total]');
            if (gapTotal) gapTotal.textContent = hours(unaccounted);
            table.querySelector('[data-mit-ts-week-total]').textContent = hours(total);
            week.querySelector('[data-mit-ts-week-summary]').textContent = total ? hours(total) + ' hours' : '';
        });
    }
    // Notes: the week's note bar follows the box last clicked into
    form.querySelectorAll('details').forEach(function (week) {
        const bar = week.querySelector('[data-mit-ts-note-bar]');
        const label = bar.querySelector('[data-mit-ts-note-label]');
        const input = bar.querySelector('[data-mit-ts-note-input]');
        let current = null;
        week.querySelectorAll('input[data-date]').forEach(function (cell) {
            cell.addEventListener('focus', function () {
                current = cell;
                bar.classList.remove('d-none');
                label.textContent = cell.dataset.code + ', ' + new Date(cell.dataset.date + 'T12:00').toLocaleDateString(undefined, { month: 'short', day: 'numeric' });
                input.value = cell.parentElement.querySelector('input[type=hidden]').value;
                input.placeholder = cell.dataset.noteRequired === '1' ? 'Note (needed for this code)' : 'Note (optional)';
            });
        });
        input.addEventListener('input', function () {
            if (!current) return;
            const hidden = current.parentElement.querySelector('input[type=hidden]');
            hidden.value = input.value;
            const dot = current.parentElement.querySelector('[data-mit-ts-note-dot]');
            dot.classList.toggle('d-none', input.value.trim() === '');
            dot.title = input.value;
        });
    });
    function readable(text) {
        text = String(text || '').trim().replace(',', '.');
        const hm = text.match(/^\d{1,2}:(\d{1,2})$/) || text.match(/^\d{1,2}h\s*(\d{1,2})?m?$/i);
        if (hm) return +(hm[1] || 0) < 60;
        return text === '' || /^\d{1,4}\s*m(in)?$/i.test(text) || (text !== '.' && /^\d{0,2}(\.\d{1,2})?$/.test(text));
    }
    // Checked here first, so a mistake does not lose what was typed
    form.addEventListener('submit', function (e) {
        const bad = [...form.querySelectorAll('input[data-date]')].find(function (cell) {
            const max = +cell.dataset.max;
            return !cell.disabled && (!readable(cell.value) || (max > 0 && minutes(cell.value) > max));
        });
        if (bad) {
            e.preventDefault();
            bad.closest('details').open = true;
            bad.focus();
            alert(bad.dataset.code + ': ' + (readable(bad.value) ? 'at most ' + hours(+bad.dataset.max) + ' hours a day.' : 'type hours as e.g. 7.5 or 7:30.'));
            return;
        }
        const short = document.querySelector('[data-mit-leave-bank].border-danger');
        if (short) {
            e.preventDefault();
            alert('Not enough ' + short.dataset.name + ' for those hours.');
            return;
        }
        const missing = [...form.querySelectorAll('input[data-note-required="1"]')].find(function (cell) {
            return !cell.disabled && minutes(cell.value) > 0 && cell.parentElement.querySelector('input[type=hidden]').value.trim() === '';
        });
        if (missing) {
            e.preventDefault();
            missing.closest('details').open = true;
            missing.focus();
            alert(missing.dataset.code + ' needs a note - type it in the box under the week.');
        }
    });
    // Leave left, as hours are typed: each bank's balance less what is on its codes in this period
    function leave() {
        document.querySelectorAll('[data-mit-leave-bank]').forEach(function (box) {
            const codes = box.dataset.codes.split(',');
            let used = 0;
            form.querySelectorAll('input[data-date]').forEach(function (cell) {
                const code = cell.name.match(/\[(\d+)\]$/);
                if (code && codes.includes(code[1])) used += minutes(cell.value);
            });
            const left = +box.dataset.base - used;
            const out = box.querySelector('[data-mit-leave-left]');
            out.textContent = (left < 0 ? '-' : '') + hours(Math.abs(left), true);
            out.classList.toggle('text-danger', left < 0);
            box.classList.toggle('border-danger', left < 0 && box.dataset.negative !== '1');
        });
    }
    form.addEventListener('input', function () {
        sum();
        leave();
    });
    sum();
    leave();
})();
