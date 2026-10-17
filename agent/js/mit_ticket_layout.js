/*
 * Mountaineer IT - the ticket page's customisable layout (functions/mit_ticket_layout.php).
 *
 * Finds ITFlow's boxes on the page, names them, and puts them where the saved layout says - left
 * (main) or right (side) column, in order, collapsed or not. "Customise layout" (in the ticket's
 * menu, or under the right column) lets you drag them about, then save it for yourself or, as an
 * admin, as the company default. The page's markup is ITFlow's own; nothing here changes it
 * server-side, so a box this script does not know simply stays where ITFlow put it.
 */
(function () {
    'use strict';

    const cfg = window.mitTicketLayout || {};
    const root = document.querySelector('.ticket-layout');
    const main = root && root.querySelector(':scope > .ticket-layout-main');
    const side = root && root.querySelector(':scope > .ticket-layout-side');
    if (!main || !side) {
        return;
    }

    const style = document.createElement('style');
    style.textContent = '.ticket-layout .card.collapsed-card > .list-group { display: none; }'
        + '.mit-layout-editing [data-mit-box] { outline: 2px dashed var(--bs-primary-border-subtle); outline-offset: 2px; border-radius: .375rem; margin-bottom: 1rem; }'
        + '.mit-layout-editing .ticket-layout-main, .mit-layout-editing .ticket-layout-side { min-height: 8rem; }'
        + '.mit-box-bar { background: var(--bs-primary-bg-subtle); color: var(--bs-primary-text-emphasis); border-radius: .375rem .375rem 0 0; }'
        + '.mit-box-handle { cursor: grab; font-weight: 600; } .mit-layout-toolbar { position: sticky; top: .5rem; z-index: 1020; }';
    document.head.appendChild(style);

    // ITFlow's boxes, known by the icon in their title
    const ICONS = {
        'fa-align-left': 'description', 'fa-sliders-h': 'details', 'fa-user': 'contact', 'fa-layer-group': 'also_open',
        'fa-map-marker-alt': 'site', 'fa-tasks': 'tasks', 'fa-link': 'linked', 'fa-history': 'history'
    };
    const NAMES = {
        description: 'Description', reply: 'Reply box', conversation: 'Conversation', details: 'Details', contact: 'Contact',
        also_open: 'Also open', site: 'Site', tasks: 'Tasks', linked: 'Linked', history: 'History'
    };
    // ITFlow's own layout - where a box goes back to, and where a saved layout slots a box it never saw
    const DEFAULT = {
        main: ['description', 'reply', 'conversation'],
        side: ['details', 'contact', 'also_open', 'site', 'tasks', 'linked', 'history'],
        collapsed: ['history']
    };
    const NO_COLLAPSE = ['reply', 'conversation'];

    function boxes() {
        const found = {};
        root.querySelectorAll('[data-mit-box]').forEach(function (box) {
            found[box.dataset.mitBox] = box;
        });
        return found;
    }

    // ---------- naming the boxes ----------

    [main, side].forEach(function (column) {
        Array.prototype.forEach.call(column.children, function (el) {
            if (el.querySelector('#replyComposer')) {
                el.dataset.mitBox = 'reply';
                return;
            }
            const icon = el.classList.contains('card') && el.querySelector(':scope > .card-header .card-title i.fas');
            if (icon) {
                Object.keys(ICONS).some(function (name) {
                    if (icon.classList.contains(name)) {
                        el.dataset.mitBox = ICONS[name];
                        return true;
                    }
                    return false;
                });
            }
        });
    });
    // The conversation is a heading and a run of reply cards - wrap them so they move as one
    const loose = Array.prototype.filter.call(main.children, function (el) {
        return !el.dataset.mitBox && !['SCRIPT', 'STYLE', 'TEMPLATE'].includes(el.tagName);
    });
    if (loose.length) {
        const conversation = document.createElement('div');
        conversation.dataset.mitBox = 'conversation';
        main.insertBefore(conversation, loose[0]);
        loose.forEach(function (el) {
            conversation.appendChild(el);
        });
    }

    // ---------- applying a layout ----------

    function collapseButton(card) {
        let button = card.querySelector(':scope > .card-header [data-lte-toggle="card-collapse"]');
        if (!button) {
            const header = card.querySelector(':scope > .card-header');
            if (!header) {
                return null;
            }
            let tools = header.querySelector(':scope > .card-tools');
            if (!tools) {
                tools = document.createElement('div');
                tools.className = 'card-tools';
                header.appendChild(tools);
            }
            button = document.createElement('button');
            button.type = 'button';
            button.className = 'btn btn-tool';
            button.title = 'Show/hide';
            button.dataset.lteToggle = 'card-collapse';
            button.innerHTML = '<i class="fas fa-chevron-down"></i>';
            tools.appendChild(button);
        }
        return button;
    }

    function setCollapsed(box, collapsed) {
        if (!box.classList.contains('card') || NO_COLLAPSE.includes(box.dataset.mitBox)) {
            return;
        }
        if (collapsed === box.classList.contains('collapsed-card')) {
            return;
        }
        const button = collapseButton(box);
        if (!button) {
            return;
        }
        box.classList.toggle('collapsed-card', collapsed);
        box.querySelectorAll(':scope > .card-body, :scope > .card-footer').forEach(function (part) {
            part.style.display = collapsed ? 'none' : '';
        });
        const icon = button.querySelector('i');
        if (icon) {
            icon.classList.toggle('fa-chevron-up', collapsed);
            icon.classList.toggle('fa-chevron-down', !collapsed);
        }
    }

    function apply(layout) {
        if (!layout) {
            return;
        }
        const found = boxes();
        ['main', 'side'].forEach(function (name) {
            const column = name === 'main' ? main : side;
            (layout[name] || []).forEach(function (id) {
                if (found[id]) {
                    column.appendChild(found[id]);
                }
            });
        });
        const collapsed = layout.collapsed || [];
        Object.keys(found).forEach(function (id) {
            setCollapsed(found[id], collapsed.includes(id));
        });
    }

    apply(cfg.layout);

    // ---------- customising ----------

    /*
     * The layout as the page now shows it. Boxes this ticket does not have (Site, Also open...) keep
     * their place from the layout in use - after the box they followed - so a layout saved on one
     * ticket still suits the next.
     */
    function current() {
        const before = cfg.layout || DEFAULT;
        const shown = {main: [], side: []};
        main.querySelectorAll(':scope > [data-mit-box]').forEach(function (box) {
            shown.main.push(box.dataset.mitBox);
        });
        side.querySelectorAll(':scope > [data-mit-box]').forEach(function (box) {
            shown.side.push(box.dataset.mitBox);
        });
        const present = shown.main.concat(shown.side);
        ['main', 'side'].forEach(function (name) {
            const list = (before[name] || []).concat(DEFAULT[name].filter(function (id) {
                return !(before.main || []).includes(id) && !(before.side || []).includes(id);
            }));
            list.forEach(function (id, index) {
                if (present.includes(id) || shown[name].includes(id)) {
                    return;
                }
                let at = 0;
                for (let i = index - 1; i >= 0; i--) {
                    const pos = shown[name].indexOf(list[i]);
                    if (pos !== -1) {
                        at = pos + 1;
                        break;
                    }
                }
                shown[name].splice(at, 0, id);
            });
        });
        const collapsed = (before.collapsed || DEFAULT.collapsed).filter(function (id) {
            return !present.includes(id);
        });
        root.querySelectorAll('[data-mit-box]').forEach(function (box) {
            const tick = box.querySelector(':scope > .mit-box-bar input[type=checkbox]');
            if (tick && tick.checked) {
                collapsed.push(box.dataset.mitBox);
            }
        });
        return {main: shown.main, side: shown.side, collapsed: collapsed};
    }

    function send(scope, layout) {
        const body = new URLSearchParams({mit_ticket_layout: 1, csrf_token: cfg.csrf, scope: scope, layout: layout ? JSON.stringify(layout) : ''});
        fetch('ajax.php', {method: 'POST', headers: {'Content-Type': 'application/x-www-form-urlencoded'}, credentials: 'same-origin', body: body.toString()})
            .then(function (res) {
                return res.json();
            })
            .then(function (json) {
                if (!json || !json.ok) {
                    throw new Error(json && json.error ? json.error : 'The layout could not be saved.');
                }
                window.location.reload();
            })
            .catch(function (error) {
                if (typeof itflowToast === 'function') {
                    itflowToast(error.message, 'error');
                } else {
                    alert(error.message);
                }
            });
    }

    function button(label, css, onClick) {
        const b = document.createElement('button');
        b.type = 'button';
        b.className = 'btn btn-sm ' + css;
        b.innerHTML = label;
        b.addEventListener('click', onClick);
        return b;
    }

    let editing = false;

    function customise() {
        if (editing || typeof Sortable === 'undefined') {
            return;
        }
        editing = true;
        document.body.classList.add('mit-layout-editing');

        const bar = document.createElement('div');
        bar.className = 'mit-layout-toolbar card card-body py-2 mb-3 d-flex flex-row flex-wrap align-items-center gap-2';
        bar.innerHTML = '<span class="me-auto"><i class="fas fa-th-large me-2 text-primary"></i><strong>Customise layout</strong>'
            + '<span class="text-body-secondary small ms-2">Drag boxes by their bar - between the columns too - and tick the ones that should start collapsed.</span></span>';
        bar.appendChild(button('<i class="fas fa-check me-1"></i>Save for me', 'btn-primary', function () {
            send('mine', current());
        }));
        if (cfg.isAdmin) {
            bar.appendChild(button('<i class="fas fa-users me-1"></i>Save as company default', 'btn-outline-primary', function () {
                send('default', current());
            }));
        }
        if (cfg.hasMine) {
            bar.appendChild(button('Use company default', 'btn-outline-secondary', function () {
                send('reset_mine');
            }));
        }
        if (cfg.isAdmin && cfg.default) {
            bar.appendChild(button('Company default back to ITFlow\'s', 'btn-outline-danger', function () {
                send('reset_default');
            }));
        }
        bar.appendChild(button('Cancel', 'btn-light', function () {
            window.location.reload();
        }));
        root.parentNode.insertBefore(bar, root);

        Object.values(boxes()).forEach(function (box) {
            const id = box.dataset.mitBox;
            const strip = document.createElement('div');
            strip.className = 'mit-box-bar d-flex align-items-center gap-2 px-2 py-1 small';
            strip.innerHTML = '<span class="mit-box-handle flex-grow-1" title="Drag to move"><i class="fas fa-grip-vertical me-2"></i>' + NAMES[id] + '</span>';
            if (box.classList.contains('card') && !NO_COLLAPSE.includes(id)) {
                const label = document.createElement('label');
                label.className = 'form-check mb-0';
                label.innerHTML = '<input type="checkbox" class="form-check-input"> <span class="form-check-label">Start collapsed</span>';
                label.querySelector('input').checked = box.classList.contains('collapsed-card');
                strip.appendChild(label);
            }
            box.insertBefore(strip, box.firstChild);
        });

        [main, side].forEach(function (column) {
            new Sortable(column, {group: 'mitTicketLayout', handle: '.mit-box-handle', draggable: '[data-mit-box]', animation: 150, forceFallback: true});
        });
        bar.scrollIntoView({behavior: 'smooth', block: 'start'});
    }

    // ---------- the way in ----------

    const link = document.createElement('a');
    link.href = '#';
    link.className = 'dropdown-item';
    link.innerHTML = '<i class="fas fa-fw fa-th-large me-2"></i>Customise layout';
    link.addEventListener('click', function (e) {
        e.preventDefault();
        customise();
    });
    const menu = root.querySelector('.ticket-layout-header .dropdown-menu');
    if (menu) {
        const divider = document.createElement('div');
        divider.className = 'dropdown-divider';
        menu.appendChild(divider);
        menu.appendChild(link);
    } else {
        link.className = 'small text-body-secondary d-none d-lg-block text-end';
        side.appendChild(link);
    }
})();
