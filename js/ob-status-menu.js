/**
 * Order of Business list (files-meetingminutes.php): agenda item rows.
 *
 *  - Left-click (or Enter) on a row opens its details modal, filled from the
 *    #ob-items JSON (includes/minutes_workflow.php, dms_ob_item_details).
 *  - Right-click (or the ContextMenu key / Shift+F10) opens the SET STATUS
 *    menu with only the options of the row's section, plus MOVE TO. Admin and
 *    master only; for other roles the browser's own menu is left alone.
 *  - Choosing an option posts to ob_set_status.php, which updates the item in
 *    place; the row, tab counts, timeline card and details refresh at once.
 *
 * Multi-select (admin and master):
 *  - The Select button turns on selection mode: a checkbox column appears and
 *    clicking a row (or Space) toggles it; Shift+click selects a range;
 *    Ctrl/Cmd+click on a row also starts selection mode.
 *  - Select all / the header checkbox select every item the current section
 *    tab, search and report filters show (on all pages, not only this one).
 *    Items hidden by a later filter change are unselected.
 *  - Right-clicking a selected item (or the Actions button) opens the bulk
 *    menu: SET STATUS (statuses every selected section offers), Clear status,
 *    MOVE TO, Delete selected (password, ob_bulk_delete.php), Clear selection.
 *
 * Events are delegated on the table, so they keep working across DataTables
 * paging, sorting and re-initialisation.
 */
(function (window, document, $) {
    'use strict';

    var table = document.querySelector('[data-ob-table]');
    if (!table) { return; }

    var canEdit = table.getAttribute('data-ob-can-edit') === '1';
    var statuses = parseJson(table.getAttribute('data-ob-statuses'), {});
    var sections = parseJson(table.getAttribute('data-ob-sections'), []);
    var itemsNode = document.getElementById('ob-items');
    var items = parseJson(itemsNode ? itemsNode.textContent : '', {});

    // Same as dms_ob_section_pill()
    var SECTION_PILL = { 'First Reading': 'is-blue', 'Second Reading': 'is-amber', 'Third Reading': 'is-green', 'Other Matters': 'is-lime' };

    function parseJson(text, fallback) {
        try { return JSON.parse(text || '') || fallback; } catch (e) { return fallback; }
    }

    function rowFrom(target) {
        var tr = target && target.closest ? target.closest('tr.dms-ob-row') : null;
        return tr && table.contains(tr) ? tr : null;
    }

    /** Clicks on the row's own buttons/links keep their normal behaviour. */
    function isControl(target) {
        return !!(target.closest && target.closest('a, button, input, select, textarea, label, .dms-row-actions'));
    }

    function el(tag, className, text) {
        var node = document.createElement(tag);
        if (className) { node.className = className; }
        if (text !== undefined && text !== null) { node.textContent = text; }
        return node;
    }

    function plural(n, one, many) {
        return n + ' ' + (n === 1 ? one : (many || one + 's'));
    }

    // datatables.init.js toggles a "selected" class on row click; not wanted here.
    function unselect(tr) {
        window.setTimeout(function () { tr.classList.remove('selected'); }, 0);
    }

    function dt() {
        return ($ && $.fn.DataTable && $.fn.DataTable.isDataTable(table)) ? $(table).DataTable() : null;
    }

    function toArray(list) {
        return Array.prototype.slice.call(list || []);
    }

    /** All rows, including those on other pages or hidden by filters. */
    function allRows() {
        var api = dt();
        return api ? toArray(api.rows().nodes()) : toArray(table.querySelectorAll('tbody tr.dms-ob-row'));
    }

    /** Rows passing the current tab/search/report filters, in display order. */
    function shownRows() {
        var api = dt();
        return api ? toArray(api.rows({ search: 'applied', order: 'applied' }).nodes()) : allRows();
    }

    function errorBox(message) {
        if (window.Swal) {
            Swal.fire({ icon: 'error', title: 'Not saved', text: message, confirmButtonText: 'OK' });
        } else {
            window.alert(message);
        }
    }

    function toast(text, icon) {
        if (!window.Swal) { return; }
        Swal.fire({ toast: true, position: 'top-end', icon: icon || 'success', title: text, showConfirmButton: false, timer: 2200 });
    }

    function post(url, body) {
        return fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: body
        }).then(function (res) {
            return res.json().catch(function () {
                throw new Error('Your session may have expired. Please refresh the page and log in again.');
            });
        });
    }

    /* ------------------------------------------------------------------ *
     * Details modal (left-click)
     * ------------------------------------------------------------------ */

    var modal = document.getElementById('obDetailsModal');
    if (modal && modal.parentNode !== document.body) {
        document.body.appendChild(modal); // outside the card, so it stacks above the page
    }
    var lastFocus = null;

    // Same rule as viewFile() in js/view-file.js: open the stored path directly, whatever the file
    // type, encoding each segment so names with "%", "#" or "?" do not turn into a 404.
    function fileUrl(id, field, path) {
        if (/^https?:\/\//i.test(path)) { return path; }
        if (/^[a-z][a-z0-9+.-]*:/i.test(path)) { return ''; } // no other URL schemes
        return path.split('/').map(encodeURIComponent).join('/');
    }

    function kv(dl, label, valueNode) {
        var row = el('div', 'dms-ob-kv');
        row.appendChild(el('dt', '', label));
        var dd = el('dd');
        if (typeof valueNode === 'string') {
            dd.textContent = valueNode !== '' ? valueNode : '—';
        } else {
            dd.appendChild(valueNode);
        }
        row.appendChild(dd);
        dl.appendChild(row);
    }

    function fileNode(item, field) {
        var path = item[field] || '';
        if (!path && item[field + '_stored']) { return el('span', 'dms-ob-muted', 'Stored file: use Open full page to view it'); }
        if (!path) { return el('span', 'dms-ob-muted', 'No file attached'); }
        var url = fileUrl(item.id, field, path);
        var name = path.split('/').pop();
        if (!url) { return el('span', '', name); }
        var a = el('a', 'dms-ob-file');
        a.href = url;
        a.target = '_blank';
        a.rel = 'noopener';
        a.innerHTML = '<i class="fa fa-paperclip" aria-hidden="true"></i> ';
        a.appendChild(document.createTextNode(name));
        return a;
    }

    function openDetails(tr) {
        var item = items[tr.getAttribute('data-id')];
        if (!item || !modal) { return; }
        var body = modal.querySelector('[data-ob-details]');
        var open = modal.querySelector('[data-ob-details-open]');
        var title = modal.querySelector('#obDetailsTitle');

        title.textContent = item.resNo ? 'Agenda item ' + item.resNo : 'Agenda item details';
        body.innerHTML = '';

        var dl = el('dl', 'dms-ob-details-list');
        kv(dl, 'Title', item.title || '');
        kv(dl, 'No. of Regular Session', item.no_regSession || '');
        kv(dl, 'Session date', item.date || '');
        kv(dl, 'Item No.', item.resNo || '');
        var status = el('div');
        status.innerHTML = item.summaryHtml || ''; // server-rendered, escaped markup
        kv(dl, 'Section & status', status);
        kv(dl, 'Order of Business attachment', fileNode(item, 'genAttachment'));
        kv(dl, 'Supporting document', fileNode(item, 'attachment'));
        body.appendChild(dl);

        if (canEdit) {
            body.appendChild(el('p', 'dms-ob-muted dms-ob-details-tip', 'Tip: right-click the item in the list to set its status.'));
        }
        open.href = 'viewmeetingminutes.php?id=' + encodeURIComponent(item.id);

        lastFocus = tr;
        if ($ && $.fn && $.fn.modal) {
            $(modal).modal('show');
        }
    }

    if (modal && $) {
        $(modal).on('hidden.bs.modal', function () {
            if (lastFocus && document.contains(lastFocus)) { lastFocus.focus(); }
        });
    }

    /* ------------------------------------------------------------------ *
     * Selection (multi-select)
     * ------------------------------------------------------------------ */

    var bar = document.querySelector('[data-ob-selectbar]');
    var ui = {
        toggle: bar && bar.querySelector('[data-ob-select-toggle]'),
        all: bar && bar.querySelector('[data-ob-select-all]'),
        actions: bar && bar.querySelector('[data-ob-sel-actions]'),
        count: bar && bar.querySelector('[data-ob-sel-count]'),
        helpIdle: bar && bar.querySelector('[data-ob-help-idle]'),
        helpSel: bar && bar.querySelector('[data-ob-help-selecting]')
    };
    var selecting = false;
    var selected = {};   // id → true
    var anchorId = null; // last clicked row, for Shift+click ranges

    function isSelected(tr) {
        return selected[tr.getAttribute('data-id')] === true;
    }

    function markRow(tr, on) {
        var id = tr.getAttribute('data-id');
        if (on) { selected[id] = true; } else { delete selected[id]; }
        tr.classList.toggle('is-selected', on);
        tr.setAttribute('aria-selected', on ? 'true' : 'false');
        var cb = tr.querySelector('[data-ob-check]');
        if (cb) { cb.checked = on; }
    }

    function selectedRows() {
        return allRows().filter(isSelected);
    }

    function clearSelection() {
        allRows().forEach(function (tr) { if (isSelected(tr)) { markRow(tr, false); } });
        selected = {};
        anchorId = null;
        refreshSelection();
    }

    function setSelecting(on) {
        if (!canEdit || !ui.toggle) { return; }
        selecting = on;
        table.classList.toggle('is-selecting', on);
        if (!on) { clearSelection(); }
        allRows().forEach(function (tr) {
            if (on) { tr.setAttribute('aria-selected', isSelected(tr) ? 'true' : 'false'); } else { tr.removeAttribute('aria-selected'); }
        });
        if (on) { table.setAttribute('aria-multiselectable', 'true'); } else { table.removeAttribute('aria-multiselectable'); }

        ui.toggle.setAttribute('aria-pressed', on ? 'true' : 'false');
        ui.toggle.classList.toggle('is-active', on);
        ui.toggle.querySelector('span').textContent = on ? 'Done' : 'Select';
        ui.all.hidden = !on;
        ui.actions.hidden = !on;
        ui.count.hidden = !on;
        if (ui.helpIdle) { ui.helpIdle.hidden = on; }
        if (ui.helpSel) { ui.helpSel.hidden = !on; }
        refreshSelection();
    }

    /** Count, Select all label, header checkbox and Actions button. */
    function refreshSelection() {
        if (!canEdit || !ui.toggle) { return; }
        var shown = shownRows();
        var n = Object.keys(selected).length;
        var shownSel = shown.filter(isSelected).length;
        var allOn = shown.length > 0 && shownSel === shown.length;

        ui.count.textContent = n ? plural(n, 'item') + ' selected' : 'No items selected';
        ui.actions.disabled = n === 0;
        ui.all.innerHTML = '<i class="fa ' + (allOn ? 'fa-square-o' : 'fa-check-square-o') + '" aria-hidden="true"></i>&nbsp;';
        ui.all.appendChild(document.createTextNode(allOn ? 'Unselect all' : 'Select all (' + shown.length + ')'));
        ui.all.disabled = shown.length === 0;

        var head = table.querySelector('[data-ob-check-all]');
        if (head) {
            head.checked = allOn;
            head.indeterminate = shownSel > 0 && !allOn;
        }
    }

    /** Select (or unselect) every row the current filters show. */
    function selectAllShown(on) {
        shownRows().forEach(function (tr) { markRow(tr, on); });
        anchorId = null;
        refreshSelection();
    }

    /** Toggle a row; with Shift, set the whole range from the last clicked row. */
    function toggleRow(tr, shift, state) {
        var on = state === undefined ? !isSelected(tr) : state;
        if (shift && anchorId !== null) {
            var list = shownRows();
            var a = -1, b = -1;
            list.forEach(function (row, i) {
                if (row.getAttribute('data-id') === anchorId) { a = i; }
                if (row === tr) { b = i; }
            });
            if (a !== -1 && b !== -1) {
                var from = Math.min(a, b), to = Math.max(a, b);
                for (var i = from; i <= to; i++) { markRow(list[i], on); }
                refreshSelection();
                return;
            }
        }
        markRow(tr, on);
        anchorId = tr.getAttribute('data-id');
        refreshSelection();
    }

    if (canEdit && ui.toggle) {
        ui.toggle.addEventListener('click', function () { setSelecting(!selecting); });
        ui.all.addEventListener('click', function () {
            var shown = shownRows();
            selectAllShown(!(shown.length && shown.every(isSelected)));
        });
        ui.actions.addEventListener('click', function () {
            var rows = selectedRows();
            if (!rows.length) { return; }
            var r = ui.actions.getBoundingClientRect();
            openBulkMenu(rows, r.left, r.bottom + 4, ui.actions);
        });

        // Items hidden by a tab/search/filter change are unselected, so an
        // action never touches something the user cannot see.
        if ($) {
            $(document).on('draw.dt', '#' + table.id, function () {
                if (!selecting) { return; }
                var visible = {};
                shownRows().forEach(function (tr) { visible[tr.getAttribute('data-id')] = true; });
                allRows().forEach(function (tr) {
                    if (isSelected(tr) && !visible[tr.getAttribute('data-id')]) { markRow(tr, false); }
                });
                refreshSelection();
            });
        }
    }

    /* ------------------------------------------------------------------ *
     * Context menu (single SET STATUS, or bulk actions)
     * ------------------------------------------------------------------ */

    var menu = el('div', 'dms-ob-menu');
    menu.setAttribute('role', 'menu');
    menu.setAttribute('aria-label', 'Set status');
    menu.hidden = true;
    document.body.appendChild(menu);
    var menuRow = null;   // single-row menu target
    var menuRows = null;  // bulk menu targets
    var menuReturn = null; // where focus goes when the menu closes
    var busy = false;

    function menuItem(label, opts) {
        var b = el('button', 'dms-ob-menu-item' + (opts.tone ? ' is-' + opts.tone : ''));
        b.type = 'button';
        b.setAttribute('role', opts.checkable ? 'menuitemradio' : 'menuitem');
        if (opts.checkable) { b.setAttribute('aria-checked', opts.checked ? 'true' : 'false'); }
        if (opts.checked) { b.classList.add('is-current'); }
        b.innerHTML = '<i class="fa ' + (opts.icon || 'fa-circle-o') + '" aria-hidden="true"></i>';
        b.appendChild(el('span', 'dms-ob-menu-label', label));
        if (opts.checked) {
            var check = el('i', 'fa fa-check dms-ob-menu-check');
            check.setAttribute('aria-hidden', 'true');
            b.appendChild(check);
        }
        b.setAttribute('data-op', opts.op);
        b.setAttribute('data-value', opts.value);
        return b;
    }

    function buildMenu(tr) {
        var section = tr.getAttribute('data-reading') || 'First Reading';
        var current = tr.getAttribute('data-status') || '';
        menu.innerHTML = '';
        menu.setAttribute('aria-label', 'Set status');

        var head = el('div', 'dms-ob-menu-head', 'SET STATUS');
        head.appendChild(el('span', 'dms-ob-menu-sub', section));
        menu.appendChild(head);

        (statuses[section] || []).forEach(function (s) {
            menu.appendChild(menuItem(s.label, {
                op: 'status', value: s.label, tone: s.tone, icon: s.icon,
                checkable: true, checked: s.label === current
            }));
        });
        if (current) {
            menu.appendChild(menuItem('Clear status', { op: 'status', value: '', icon: 'fa-eraser' }));
        }

        var others = sections.filter(function (s) { return s !== section; });
        if (others.length) {
            menu.appendChild(el('div', 'dms-ob-menu-sep'));
            menu.appendChild(el('div', 'dms-ob-menu-head', 'MOVE TO'));
            others.forEach(function (s) {
                menu.appendChild(menuItem(s, { op: 'move', value: s, icon: 'fa-arrow-right' }));
            });
        }
    }

    /** Statuses every one of the given sections offers (by label). */
    function commonStatuses(secs) {
        var first = statuses[secs[0]] || [];
        return first.filter(function (s) {
            return secs.every(function (sec) {
                return (statuses[sec] || []).some(function (o) { return o.label === s.label; });
            });
        });
    }

    function buildBulkMenu(rows) {
        var secs = [], stats = {};
        rows.forEach(function (tr) {
            var sec = tr.getAttribute('data-reading') || 'First Reading';
            if (secs.indexOf(sec) === -1) { secs.push(sec); }
            stats[tr.getAttribute('data-status') || ''] = true;
        });
        var statusKeys = Object.keys(stats);
        var sameStatus = statusKeys.length === 1 ? statusKeys[0] : null;
        var anyStatus = statusKeys.some(function (s) { return s !== ''; });

        menu.innerHTML = '';
        menu.setAttribute('aria-label', 'Actions for ' + plural(rows.length, 'selected item'));

        var top = el('div', 'dms-ob-menu-head dms-ob-menu-title', plural(rows.length, 'ITEM', 'ITEMS') + ' SELECTED');
        menu.appendChild(top);

        var head = el('div', 'dms-ob-menu-head', 'SET STATUS');
        head.appendChild(el('span', 'dms-ob-menu-sub', secs.length === 1 ? secs[0] : 'Mixed sections'));
        menu.appendChild(head);

        var opts = commonStatuses(secs);
        if (opts.length) {
            opts.forEach(function (s) {
                menu.appendChild(menuItem(s.label, {
                    op: 'status', value: s.label, tone: s.tone, icon: s.icon,
                    checkable: true, checked: s.label === sameStatus
                }));
            });
        } else {
            menu.appendChild(el('div', 'dms-ob-menu-note', 'These sections have different statuses. Select items from one section to set a status.'));
        }
        if (anyStatus) {
            menu.appendChild(menuItem('Clear status', { op: 'status', value: '', icon: 'fa-eraser' }));
        }

        var targets = sections.filter(function (s) { return !(secs.length === 1 && secs[0] === s); });
        if (targets.length) {
            menu.appendChild(el('div', 'dms-ob-menu-sep'));
            menu.appendChild(el('div', 'dms-ob-menu-head', 'MOVE TO'));
            targets.forEach(function (s) {
                menu.appendChild(menuItem(s, { op: 'move', value: s, icon: 'fa-arrow-right' }));
            });
        }

        menu.appendChild(el('div', 'dms-ob-menu-sep'));
        menu.appendChild(menuItem('Delete selected', { op: 'delete', value: '', icon: 'fa-trash', tone: 'red' }));
        menu.appendChild(menuItem('Clear selection', { op: 'unselect', value: '', icon: 'fa-times' }));
    }

    /** Show the menu at (x, y), kept inside the visible screen. */
    function placeMenu(x, y) {
        menu.hidden = false;
        menu.style.left = '0px';
        menu.style.top = '0px';
        var pad = 8;
        var w = menu.offsetWidth;
        var h = menu.offsetHeight;
        var vw = document.documentElement.clientWidth || window.innerWidth;
        var vh = document.documentElement.clientHeight || window.innerHeight;

        if (x + w + pad > vw) { x = Math.max(pad, x - w); }       // flip left
        if (y + h + pad > vh) { y = Math.max(pad, y - h); }       // flip up
        x = Math.min(Math.max(pad, x), Math.max(pad, vw - w - pad));
        y = Math.min(Math.max(pad, y), Math.max(pad, vh - h - pad));

        menu.style.left = x + 'px';
        menu.style.top = y + 'px';
    }

    function menuButtons() {
        return toArray(menu.querySelectorAll('.dms-ob-menu-item'));
    }

    function focusFirst() {
        var first = menu.querySelector('.dms-ob-menu-item.is-current') || menuButtons()[0];
        if (first) { first.focus(); }
    }

    function openMenu(tr, x, y) {
        closeMenu(false);
        menuRow = tr;
        menuReturn = tr;
        tr.classList.add('is-menu-open');
        buildMenu(tr);
        placeMenu(x, y);
        focusFirst();
    }

    function openBulkMenu(rows, x, y, returnTo) {
        closeMenu(false);
        menuRows = rows;
        menuReturn = returnTo || null;
        buildBulkMenu(rows);
        placeMenu(x, y);
        focusFirst();
    }

    function closeMenu(restoreFocus) {
        if (menu.hidden) { return; }
        menu.hidden = true;
        if (menuRow) { menuRow.classList.remove('is-menu-open'); }
        if (restoreFocus && menuReturn && document.contains(menuReturn)) { menuReturn.focus(); }
        menuRow = null;
        menuRows = null;
        menuReturn = null;
    }

    menu.addEventListener('click', function (e) {
        var b = e.target.closest('.dms-ob-menu-item');
        if (!b) { return; }
        var op = b.getAttribute('data-op');
        var value = b.getAttribute('data-value');
        if (menuRow) {
            var tr = menuRow;
            closeMenu(true);
            save(tr, op, value);
        } else if (menuRows) {
            var rows = menuRows;
            closeMenu(true);
            if (op === 'unselect') {
                clearSelection();
            } else if (op === 'delete') {
                bulkDelete(rows);
            } else {
                bulkSave(rows, op, value);
            }
        }
    });

    menu.addEventListener('keydown', function (e) {
        var list = menuButtons();
        var i = list.indexOf(document.activeElement);
        if (e.key === 'ArrowDown') {
            e.preventDefault();
            list[(i + 1) % list.length].focus();
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            list[(i - 1 + list.length) % list.length].focus();
        } else if (e.key === 'Home') {
            e.preventDefault();
            list[0].focus();
        } else if (e.key === 'End') {
            e.preventDefault();
            list[list.length - 1].focus();
        } else if (e.key === 'Escape' || e.key === 'Tab') {
            e.preventDefault();
            closeMenu(true);
        }
    });
    menu.addEventListener('contextmenu', function (e) { e.preventDefault(); });

    document.addEventListener('mousedown', function (e) {
        if (!menu.hidden && !menu.contains(e.target)) { closeMenu(false); }
    }, true);
    window.addEventListener('resize', function () { closeMenu(false); });
    window.addEventListener('scroll', function (e) {
        if (!menu.contains(e.target)) { closeMenu(false); }
    }, true);
    window.addEventListener('blur', function () { closeMenu(false); });

    /* ------------------------------------------------------------------ *
     * Saving
     * ------------------------------------------------------------------ */

    function save(tr, op, value) {
        if (busy) { return; }
        var id = tr.getAttribute('data-id');
        var section = tr.getAttribute('data-reading');
        var current = tr.getAttribute('data-status') || '';
        if ((op === 'status' && value === current) || (op === 'move' && value === section)) { return; }

        busy = true;
        tr.classList.add('is-saving');
        var body = 'id=' + encodeURIComponent(id) + '&op=' + encodeURIComponent(op) + '&value=' + encodeURIComponent(value);

        post('ob_set_status.php', body)
            .then(function (data) {
                if (!data || !data.success) {
                    throw new Error((data && data.message) || 'The status could not be saved.');
                }
                apply(tr, data);
                updateCounts(data.counts);
                redraw();
                var text = op === 'move' ? 'Moved to ' + data.section : (data.status ? 'Status set to ' + data.status : 'Status cleared');
                if (op === 'status' && data.section !== section) { text += ', moved to ' + data.section; } // Approved → Third Reading
                toast(text);
            })
            .catch(function (err) { errorBox(err.message); })
            .then(function () {
                busy = false;
                tr.classList.remove('is-saving');
            });
    }

    function bulkSave(rows, op, value) {
        if (busy || !rows.length) { return; }
        // Skip rows the change would not affect; the server checks again.
        var todo = rows.filter(function (tr) {
            if (op === 'move') { return tr.getAttribute('data-reading') !== value; }
            return (tr.getAttribute('data-status') || '') !== value;
        });
        if (!todo.length) {
            toast('Nothing to change: ' + (op === 'move' ? 'the items are already in ' + value : 'the items already have that status'), 'info');
            return;
        }

        busy = true;
        todo.forEach(function (tr) { tr.classList.add('is-saving'); });
        var ids = todo.map(function (tr) { return tr.getAttribute('data-id'); });
        var body = 'ids=' + encodeURIComponent(ids.join(',')) + '&op=' + encodeURIComponent(op) + '&value=' + encodeURIComponent(value);

        post('ob_set_status.php', body)
            .then(function (data) {
                if (!data || !data.success) {
                    throw new Error((data && data.message) || 'The changes could not be saved.');
                }
                var byId = {};
                allRows().forEach(function (tr) { byId[tr.getAttribute('data-id')] = tr; });
                var moved = 0;
                (data.items || []).forEach(function (item) {
                    var tr = byId[String(item.id)];
                    if (!tr) { return; }
                    if (op === 'status' && item.section !== tr.getAttribute('data-reading')) { moved++; }
                    apply(tr, item);
                });
                updateCounts(data.counts);
                redraw();

                var n = data.changed || 0;
                var what = op === 'move' ? 'moved to ' + value : (value ? 'set to ' + value : 'cleared');
                var text = op === 'move' ? plural(n, 'item') + ' ' + what : 'Status ' + what + ' on ' + plural(n, 'item');
                if (moved) { text += ' (' + moved + ' moved to Third Reading)'; } // Approved → Third Reading
                var skipped = (data.skipped || 0) + (rows.length - todo.length);
                if (skipped) { text += '; ' + skipped + ' skipped'; }
                toast(text, n ? 'success' : 'info');
            })
            .catch(function (err) { errorBox(err.message); })
            .then(function () {
                busy = false;
                todo.forEach(function (tr) { tr.classList.remove('is-saving'); });
            });
    }

    function bulkDelete(rows) {
        if (busy || !rows.length || !window.Swal) { return; }
        var ids = rows.map(function (tr) { return tr.getAttribute('data-id'); });
        var count = plural(ids.length, 'agenda item');

        Swal.fire({
            title: 'Delete ' + count + '?',
            text: 'Enter the password to delete the selected items. This cannot be undone.',
            icon: 'warning',
            input: 'password',
            inputAttributes: { autocapitalize: 'off', required: true },
            showCancelButton: true,
            confirmButtonText: 'Delete',
            confirmButtonColor: '#d33',
            showLoaderOnConfirm: true,
            preConfirm: function (password) {
                busy = true;
                var body = 'ids=' + encodeURIComponent(ids.join(',')) + '&password=' + encodeURIComponent(password || '');
                return post('ob_bulk_delete.php', body)
                    .then(function (data) {
                        if (!data || !data.success) {
                            throw new Error((data && data.message) || 'The items could not be deleted.');
                        }
                        return data;
                    })
                    .catch(function (err) {
                        Swal.showValidationMessage(err.message);
                    })
                    .then(function (data) {
                        busy = false;
                        return data;
                    });
            },
            allowOutsideClick: function () { return !Swal.isLoading(); }
        }).then(function (result) {
            if (!result.isConfirmed || !result.value) { return; }
            var data = result.value;
            var gone = {};
            (data.deleted || []).forEach(function (id) { gone[String(id)] = true; });
            var removed = allRows().filter(function (tr) { return gone[tr.getAttribute('data-id')]; });
            removed.forEach(function (tr) {
                markRow(tr, false);
                delete items[tr.getAttribute('data-id')];
            });
            var api = dt();
            if (api) {
                api.rows(removed).remove().draw(false); // timeline cards follow the table on draw
            } else {
                removed.forEach(function (tr) { tr.parentNode.removeChild(tr); });
            }
            updateCounts(data.counts);
            refreshSelection();
            var n = removed.length;
            toast(plural(n, 'item') + ' deleted' + (n < ids.length ? '; ' + (ids.length - n) + ' could not be deleted' : ''));
        });
    }

    /** Refresh one row (and its details/timeline card) from a save reply. */
    function apply(tr, data) {
        tr.setAttribute('data-reading', data.section);
        tr.setAttribute('data-filter', data.section); // report toolbar filter key (dms_report_row_key)
        tr.setAttribute('data-status', data.status || '');
        var cell = tr.querySelector('[data-ob-cell]');
        if (cell) { cell.innerHTML = data.cellHtml; }

        var item = items[data.id];
        if (item) {
            item.section = data.section;
            item.status = data.status || '';
            item.summaryHtml = data.summaryHtml;
        }

        // Timeline card (includes/history_timeline.php)
        var card = document.querySelector('.dms-ht-card[data-id="' + String(data.id).replace(/\D/g, '') + '"]');
        if (card) {
            var pill = card.querySelector('.dms-ht-foot .dms-pill');
            if (pill) {
                pill.textContent = data.section;
                pill.className = 'dms-pill ' + (SECTION_PILL[data.section] || 'is-plain');
            }
            var meta = card.querySelector('.dms-ht-meta');
            if (meta) {
                var line = meta.querySelector('[data-ob-meta-status]');
                if (!line) {
                    Array.prototype.forEach.call(meta.children, function (span) {
                        var b = span.querySelector('b');
                        if (b && b.textContent === 'Status:') { line = span; }
                    });
                }
                if (!line && data.status) {
                    line = el('span');
                    meta.insertBefore(line, meta.firstChild);
                }
                if (line) {
                    line.setAttribute('data-ob-meta-status', '');
                    line.hidden = !data.status;
                    line.innerHTML = '';
                    line.appendChild(el('b', '', 'Status:'));
                    line.appendChild(document.createTextNode(' ' + (data.status || '')));
                }
            }
        }

        var api = dt();
        if (api) { api.row(tr).invalidate(); }
    }

    /** Section tab counts, plus the All count. */
    function updateCounts(counts) {
        if (!counts) { return; }
        var total = 0;
        Object.keys(counts).forEach(function (name) {
            total += Number(counts[name]) || 0;
            var badge = document.querySelector('[data-ob-count="' + name.replace(/"/g, '\\"') + '"]');
            if (badge) { badge.textContent = counts[name]; }
        });
        var all = document.querySelector('[data-ob-tab=""] .dms-ob-count');
        if (all) { all.textContent = total; }
    }

    /** Re-apply the section tab filter and sorting without losing the page. */
    function redraw() {
        var api = dt();
        if (api) { api.draw(false); }
    }

    /* ------------------------------------------------------------------ *
     * Row events
     * ------------------------------------------------------------------ */

    table.addEventListener('click', function (e) {
        // Header "select all shown" checkbox
        var head = e.target.closest && e.target.closest('[data-ob-check-all]');
        if (head) {
            e.stopPropagation();
            selectAllShown(head.checked);
            return;
        }

        var tr = rowFrom(e.target);
        if (!tr) { return; }

        // Row checkbox
        var cb = e.target.closest && e.target.closest('[data-ob-check]');
        if (cb && selecting) {
            unselect(tr);
            toggleRow(tr, e.shiftKey, cb.checked);
            return;
        }

        if (isControl(e.target)) { unselect(tr); return; }

        // Ctrl/Cmd+click starts selection mode with this row.
        if (canEdit && !selecting && (e.ctrlKey || e.metaKey) && ui.toggle) {
            unselect(tr);
            setSelecting(true);
            toggleRow(tr, false, true);
            return;
        }

        if (selecting) {
            unselect(tr);
            if (e.shiftKey && window.getSelection) { window.getSelection().removeAllRanges(); }
            toggleRow(tr, e.shiftKey);
            return;
        }

        if (window.getSelection && String(window.getSelection()).length > 0) { return; } // user is selecting text
        unselect(tr);
        openDetails(tr);
    });

    // Shift+click in selection mode: don't start a text selection.
    table.addEventListener('mousedown', function (e) {
        if (selecting && e.shiftKey && rowFrom(e.target)) { e.preventDefault(); }
    });

    table.addEventListener('contextmenu', function (e) {
        if (!canEdit) { return; }
        var tr = rowFrom(e.target);
        if (!tr) { return; }
        e.preventDefault();

        var x = e.clientX, y = e.clientY;
        if (e.button !== 2) { // ContextMenu key / Shift+F10 on a focused row
            var r = tr.getBoundingClientRect();
            x = r.left + 24;
            y = r.top + Math.min(r.height, 40);
        }

        if (selecting) {
            if (!isSelected(tr)) { toggleRow(tr, false, true); } // right-clicking adds the row
            openBulkMenu(selectedRows(), x, y, tr);
        } else {
            openMenu(tr, x, y);
        }
    });

    table.addEventListener('keydown', function (e) {
        var tr = rowFrom(e.target);
        if (!tr || e.target !== tr) { return; }
        if (e.key === ' ' && selecting) {
            e.preventDefault();
            toggleRow(tr, e.shiftKey);
        } else if (e.key === 'Enter' || e.key === ' ') {
            e.preventDefault();
            openDetails(tr);
        } else if (e.key === 'Escape' && selecting) {
            e.preventDefault();
            setSelecting(false);
        }
    });
})(window, document, window.jQuery);
