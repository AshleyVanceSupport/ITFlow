/*
 * Mountaineer IT - time fields on every agent / admin page (loaded by mitThemeHead, functions/mit_theme.php). The clock icon
 * of a time field puts in the time now instead of opening the browser's scroll list. An empty time or date-and-time field
 * starts from now when it is clicked into, so typing just the hour and minutes keeps the current AM / PM (and today's date);
 * left without typing, it goes back to empty, so tabbing through a form fills nothing. Fields with a step (Dispatch: 15 min)
 * are rounded to it. Works by event delegation, so fields in modals and drawn by scripts are covered too.
 */
(function () {
    'use strict';

    const pad = (n) => String(n).padStart(2, '0');

    function usable(el) {
        return el instanceof HTMLInputElement && (el.type === 'time' || el.type === 'datetime-local') && !el.disabled && !el.readOnly;
    }
    // Now, to the minute or the field's step, in the field's own format
    function now(el) {
        const d = new Date();
        d.setSeconds(0, 0);
        const step = parseInt(el.step, 10) / 60;
        if (step > 1) {
            d.setHours(0, Math.round((d.getHours() * 60 + d.getMinutes()) / step) * step);
        }
        const time = pad(d.getHours()) + ':' + pad(d.getMinutes());
        return el.type === 'time' ? time : d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()) + 'T' + time;
    }

    // The icon does nothing itself, so the click lands on the field and is ours
    const style = document.createElement('style');
    style.textContent = 'input[type="time"]::-webkit-calendar-picker-indicator{pointer-events:none}';
    document.head.appendChild(style);

    document.addEventListener('focusin', function (e) {
        const el = e.target;
        if (usable(el) && el.value === '') {
            el.value = now(el);
            el.dataset.mitTimeStarted = el.value;
        }
    });
    document.addEventListener('keydown', function (e) {
        // Typing, arrows up / down or deleting keeps it; Tab and the like do not
        if (usable(e.target) && (e.key.length === 1 || ['ArrowUp', 'ArrowDown', 'Backspace', 'Delete'].includes(e.key))) {
            delete e.target.dataset.mitTimeStarted;
        }
    });
    document.addEventListener('focusout', function (e) {
        const el = e.target;
        // Only when it still holds the time it was started with (a script or a paste may have set another)
        if (usable(el) && el.dataset.mitTimeStarted !== undefined && el.value === el.dataset.mitTimeStarted) {
            el.value = '';
            delete el.dataset.mitTimeStarted;
        } else if (usable(el)) {
            delete el.dataset.mitTimeStarted;
        }
    });
    document.addEventListener('click', function (e) {
        const el = e.target;
        if (!usable(el) || el.type !== 'time') {
            return;
        }
        const box = el.getBoundingClientRect();
        if (e.clientX < box.right - 32) {
            return; // not the clock icon
        }
        e.preventDefault();
        el.value = now(el);
        delete el.dataset.mitTimeStarted;
        el.dispatchEvent(new Event('input', { bubbles: true }));
        el.dispatchEvent(new Event('change', { bubbles: true }));
    });
})();
