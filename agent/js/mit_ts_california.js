/*
 * Mountaineer IT - the Hours worked card on a California non-exempt timesheet (functions/mit_ts_california.php):
 * works out each day's hours and its meal periods as the times are typed - the same rules as mitTsCaAnalyze()
 * on the server, which has the last word on save - shows the 2nd meal period columns when needed, and opens
 * the signing window when the timesheet is submitted.
 */
(function () {
    const card = document.getElementById('mitTsCa');
    const form = document.getElementById('mitTsForm');
    if (!card || !form) return;
    const hm = form.dataset.format === 'hm';
    const order = ['start', 'meal_out', 'meal_in', 'meal2_out', 'meal2_in', 'end'];
    const texts = {
        1: { missing: 'No 1st meal period (due for a day over 5 hours)', late: '1st meal period started after the end of the 5th hour', short: '1st meal period was under 30 minutes' },
        2: { missing: 'No 2nd meal period (due for a day over 10 hours)', late: '2nd meal period started after the end of the 10th hour', short: '2nd meal period was under 30 minutes' },
    };

    function hours(m) {
        if (!m) return '';
        return hm ? Math.floor(m / 60) + ':' + String(m % 60).padStart(2, '0') : String(+(m / 60).toFixed(2));
    }
    // The day's work periods in minutes from its start - a time earlier than the one before it is the next day
    function periods(row) {
        const v = {};
        row.querySelectorAll('input[data-box]').forEach(function (input) {
            v[input.dataset.box] = input.value;
        });
        if (!v.start || !v.end) return [];
        const points = order.map(function (key) { return v[key]; }).filter(Boolean);
        if (points.length % 2) return [];
        const stamps = [];
        let day = 0;
        points.forEach(function (time, i) {
            const [h, m] = time.split(':').map(Number);
            let t = h * 60 + m + day * 1440;
            if (i && t <= stamps[i - 1]) {
                day++;
                t += 1440;
            }
            stamps.push(t);
        });
        const list = [];
        for (let i = 0; i + 1 < stamps.length; i += 2) list.push([stamps[i], stamps[i + 1]]);
        return list;
    }
    function analyze(list) {
        let worked = 0;
        const gaps = [];
        let lastEnd = null;
        list.forEach(function (p) {
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
                if (gaps[i].minutes >= 30 && gaps[i].before <= by) {
                    status = 'ok';
                    used = i;
                    break;
                }
            }
            if (status !== 'ok' && used + 1 < gaps.length) {
                used++;
                status = gaps[used].before <= by ? 'short' : 'late';
            }
            meals[n] = status;
        });
        return { worked: worked, meals: meals };
    }
    function update(row) {
        const a = analyze(periods(row));
        row.querySelector('[data-mit-ca-worked]').textContent = hours(a.worked);
        [1, 2].forEach(function (n) {
            const box = row.querySelector('[data-mit-ca-meal="' + n + '"]');
            const status = a.meals[n];
            const issue = status && status !== 'ok';
            box.hidden = !issue;
            if (issue) {
                box.querySelector('[data-mit-ca-issue]').textContent = texts[n][status];
                const select = box.querySelector('select');
                select.classList.toggle('is-invalid', select.value === '');
            }
        });
        const rest = row.querySelector('[data-mit-ca-rest]');
        if (rest) rest.hidden = !a.worked && !rest.querySelector('input').checked;
        if (a.worked > 600) showSecond();
    }
    function showSecond() {
        card.dataset.second = '1';
        card.querySelectorAll('[data-mit-ca-col2]').forEach(function (cell) { cell.hidden = false; });
        const button = card.querySelector('[data-mit-ca-second]');
        if (button) button.hidden = true;
    }
    card.querySelectorAll('[data-mit-ca-col2]').forEach(function (cell) { cell.hidden = card.dataset.second !== '1'; });
    card.querySelector('[data-mit-ca-second]')?.addEventListener('click', showSecond);
    card.addEventListener('input', function (e) {
        const row = e.target.closest('[data-mit-ca-day]');
        if (row) update(row);
    });
    card.addEventListener('change', function (e) {
        if (e.target.matches('select')) e.target.classList.toggle('is-invalid', e.target.value === '');
    });
    card.querySelectorAll('[data-mit-ca-day]').forEach(update);

    // Submitting: sign first, in the window (its own button submits the form with the signature)
    const sign = document.getElementById('mitCaSign');
    if (sign) {
        form.addEventListener('submit', function (e) {
            const by = e.submitter;
            if (!by || by.value !== 'submit' || sign.contains(by) || e.defaultPrevented) return;
            e.preventDefault();
            bootstrap.Modal.getOrCreateInstance(sign).show();
        });
    }
})();
