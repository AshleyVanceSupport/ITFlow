/*
 * Mountaineer IT - the reply box's "Send to" list (functions/mit_reply_recipients.php): shown for "Email reply",
 * an address can be added, and an email reply with nobody ticked is not sent.
 */
(function () {
    'use strict';

    const box = document.getElementById('mitSendTo');
    if (!box) {
        return;
    }
    const form = box.closest('form');
    const audience = document.querySelector('#replyAudience [data-audience="2"]');
    if (audience) {
        audience.innerHTML = '<i class="fas fa-fw fa-paper-plane me-1"></i>Emails the people ticked below, and shows in the portal.';
    }

    function emailPicked() {
        const picked = document.querySelector('input[name="public_reply_type"]:checked');
        return !!picked && picked.value === '2';
    }
    function update() {
        box.hidden = !emailPicked();
    }
    document.querySelectorAll('input[name="public_reply_type"]').forEach(function (radio) {
        radio.addEventListener('change', update);
    });
    update();

    const input = document.getElementById('mitSendToNew');
    let added = 0;
    function addAddress() {
        const email = input.value.trim().toLowerCase();
        if (!email || !input.checkValidity()) {
            input.reportValidity();
            return;
        }
        const existing = Array.prototype.find.call(box.querySelectorAll('input[name="mit_send_to[]"]'), function (c) {
            return c.value === email;
        });
        if (existing) {
            existing.checked = true;
        } else {
            const wrap = document.createElement('div');
            wrap.className = 'form-check';
            const check = document.createElement('input');
            check.className = 'form-check-input';
            check.type = 'checkbox';
            check.name = 'mit_send_to[]';
            check.value = email;
            check.checked = true;
            check.id = 'mitSendToAdded' + (added++);
            const label = document.createElement('label');
            label.className = 'form-check-label';
            label.htmlFor = check.id;
            label.textContent = email;
            wrap.appendChild(check);
            wrap.appendChild(label);
            input.closest('.input-group').before(wrap);
        }
        input.value = '';
    }
    document.getElementById('mitSendToAdd').addEventListener('click', addAddress);
    input.addEventListener('keydown', function (e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            addAddress();
        }
    });

    if (form) {
        form.addEventListener('submit', function (e) {
            if (emailPicked() && !box.querySelector('input[name="mit_send_to[]"]:checked')) {
                e.preventDefault();
                e.stopImmediatePropagation();
                alert('Tick at least one person to email, or pick "Public note" to post it without an email.');
            }
        }, true);
    }
})();
