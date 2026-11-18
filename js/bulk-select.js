/**
 * Multi-select for the Resolutions, Ordinances and Committee Reports lists
 * (admin and master). Same behaviour as the Order of Business list
 * (js/ob-status-menu.js), with a smaller Actions menu:
 *
 *  - The Select button turns on selection mode: a checkbox column appears and
 *    clicking a row (or Space) toggles it; Shift+click selects a range;
 *    Ctrl/Cmd+click on a row also starts selection mode. Esc leaves it.
 *  - Select all / the header checkbox select every record the current search
 *    and report filters show (on all pages, not only this one). Records hidden
 *    by a later filter change are unselected.
 *  - Right-clicking a selected record (or the Actions button) opens the menu:
 *    Delete selected (password, bulk_delete.php) and Clear selection.
 *
 * Markup: <table data-bulk-table data-bulk-type="resolution|ordinance|committee"
 * data-bulk-noun="resolution">, rows <tr class="dms-bulk-row" data-id>, a
 * leading .dms-ob-check-col cell, and a [data-bulk-selectbar] above the table.
 */
(function (window, document, $) {
    'use strict';

    var table = document.querySelector('[data-bulk-table]');
    var bar = document.querySelector('[data-bulk-selectbar]');
    if (!table || !bar) { return; }

    var type = table.getAttribute('data-bulk-type') || '';
    var noun = table.getAttribute('data-bulk-noun') || 'record';

    var ui = {
        toggle: bar.querySelector('[data-bulk-select-toggle]'),
        all: bar.querySelector('[data-bulk-select-all]'),
        actions: bar.querySelector('[data-bulk-actions]'),
        count: bar.querySelector('[data-bulk-count]'),
        helpIdle: bar.querySelector('[data-bulk-help-idle]'),
        helpSel: bar.querySelector('[data-bulk-help-selecting]')
    };
    if (!ui.toggle) { return; }

    var selecting = false;
    var selected = {};   // id → true
    var anchorId = null; // last clicked row, for Shift+click ranges
    var busy = false;

    function el(tag, className, text) {
        var node = document.createElement(tag);
        if (className) { node.className = className; }
        if (text !== undefined && text !== null) { node.textContent = text; }
        return node;
    }

    function plural(n, one, many) {
        return n + ' ' + (n === 1 ? one : (many || one + 's'));
    }

    function toArray(list) {
        return Array.prototype.slice.call(list || []);
    }

    function dt() {
        return ($ && $.fn.DataTable && $.fn.DataTable.isDataTable(table)) ? $(table).DataTable() : null;
    }

    /** All rows, including those on other pages or hidden by filters. */
    function allRows() {
        var api = dt();
        return api ? toArray(api.rows().nodes()) : toArray(table.querySelectorAll('tbody tr.dms-bulk-row'));
    }

    /** Rows passing the current search/report filters, in display order. */
    function shownRows() {
        var api = dt();
        return api ? toArray(api.rows({ search: 'applied', order: 'applied' }).nodes()) : allRows();
    }

    function rowFrom(target) {
        var tr = target && target.closest ? target.closest('tr.dms-bulk-row') : null;
        return tr && table.contains(tr) ? tr : null;
    }

    /** Clicks on the row's own buttons/links keep their normal behaviour. */
    function isControl(target) {
        // a[href] only: the remarks pills are <a> without a link and should select the row.
        return !!(target.closest && target.closest('a[href], button, input, select, textarea, label, .dms-row-actions'));
    }

    // datatables.init.js toggles a "selected" class on row click; not wanted here.
    function unselect(tr) {
        window.setTimeout(function () { tr.classList.remove('selected'); }, 0);
    }

    function toast(text, icon) {
        if (!window.Swal) { return; }
        Swal.fire({ toast: true, position: 'top-end', icon: icon || 'success', title: text, showConfirmButton: false, timer: 2200 });
    }

    /* ------------------------------------------------------------------ *
     * Selection
     * ------------------------------------------------------------------ */

    function isSelected(tr) {
        return selected[tr.getAttribute('data-id')] === true;
    }

    function markRow(tr, on) {
        var id = tr.getAttribute('data-id');
        if (on) { selected[id] = true; } else { delete selected[id]; }
        tr.classList.toggle('is-selected', on);
        tr.setAttribute('aria-selected', on ? 'true' : 'false');
        var cb = tr.querySelector('[data-bulk-check]');
        if (cb) { cb.checked = on; }
    }

    function selectedRows() {
        return allRows().filter(isSelected);
    }

    function clearSelection() {
        allRows().forEach(function (tr) { if (isSelected(tr)) { markRow(tr, false); } });
        selected = {};
        anchorId = null;
        refresh();
    }

    function setSelecting(on) {
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
        refresh();
    }

    /** Count, Select all label, header checkbox and Actions button. */
    function refresh() {
        var shown = shownRows();
        var n = Object.keys(selected).length;
        var shownSel = shown.filter(isSelected).length;
        var allOn = shown.length > 0 && shownSel === shown.length;

        ui.count.textContent = n ? plural(n, noun) + ' selected' : 'Nothing selected';
        ui.actions.disabled = n === 0;
        ui.all.innerHTML = '<i class="fa ' + (allOn ? 'fa-square-o' : 'fa-check-square-o') + '" aria-hidden="true"></i>&nbsp;';
        ui.all.appendChild(document.createTextNode(allOn ? 'Unselect all' : 'Select all (' + shown.length + ')'));
        ui.all.disabled = shown.length === 0;

        var head = table.querySelector('[data-bulk-check-all]');
        if (head) {
            head.checked = allOn;
            head.indeterminate = shownSel > 0 && !allOn;
        }
    }

    /** Select (or unselect) every row the current filters show. */
    function selectAllShown(on) {
        shownRows().forEach(function (tr) { markRow(tr, on); });
        anchorId = null;
        refresh();
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
                refresh();
                return;
            }
        }
        markRow(tr, on);
        anchorId = tr.getAttribute('data-id');
        refresh();
    }

    ui.toggle.addEventListener('click', function () { setSelecting(!selecting); });
    ui.all.addEventListener('click', function () {
        var shown = shownRows();
        selectAllShown(!(shown.length && shown.every(isSelected)));
    });
    ui.actions.addEventListener('click', function () {
        var rows = selectedRows();
        if (!rows.length) { return; }
        var r = ui.actions.getBoundingClientRect();
        openMenu(rows, r.left, r.bottom + 4, ui.actions);
    });

    // Records hidden by a search/filter change are unselected, so an action
    // never touches something the user cannot see.
    if ($) {
        $(document).on('draw.dt', '#' + table.id, function () {
            if (!selecting) { return; }
            var visible = {};
            shownRows().forEach(function (tr) { visible[tr.getAttribute('data-id')] = true; });
            allRows().forEach(function (tr) {
                if (isSelected(tr) && !visible[tr.getAttribute('data-id')]) { markRow(tr, false); }
            });
            refresh();
        });
    }

    /* ------------------------------------------------------------------ *
     * Actions menu
     * ------------------------------------------------------------------ */

    var menu = el('div', 'dms-ob-menu');
    menu.setAttribute('role', 'menu');
    menu.hidden = true;
    document.body.appendChild(menu);
    var menuRows = null;
    var menuReturn = null;

    function menuItem(label, op, icon, tone) {
        var b = el('button', 'dms-ob-menu-item' + (tone ? ' is-' + tone : ''));
        b.type = 'button';
        b.setAttribute('role', 'menuitem');
        b.innerHTML = '<i class="fa ' + icon + '" aria-hidden="true"></i>';
        b.appendChild(el('span', 'dms-ob-menu-label', label));
        b.setAttribute('data-op', op);
        return b;
    }

    function buildMenu(rows) {
        menu.innerHTML = '';
        menu.setAttribute('aria-label', 'Actions for ' + plural(rows.length, 'selected ' + noun));
        menu.appendChild(el('div', 'dms-ob-menu-head dms-ob-menu-title', plural(rows.length, noun.toUpperCase()) + ' SELECTED'));
        menu.appendChild(menuItem('Delete selected', 'delete', 'fa-trash', 'red'));
        menu.appendChild(menuItem('Clear selection', 'unselect', 'fa-times'));
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

        if (x + w + pad > vw) { x = Math.max(pad, x - w); }
        if (y + h + pad > vh) { y = Math.max(pad, y - h); }
        x = Math.min(Math.max(pad, x), Math.max(pad, vw - w - pad));
        y = Math.min(Math.max(pad, y), Math.max(pad, vh - h - pad));

        menu.style.left = x + 'px';
        menu.style.top = y + 'px';
    }

    function menuButtons() {
        return toArray(menu.querySelectorAll('.dms-ob-menu-item'));
    }

    function openMenu(rows, x, y, returnTo) {
        closeMenu(false);
        menuRows = rows;
        menuReturn = returnTo || null;
        buildMenu(rows);
        placeMenu(x, y);
        var first = menuButtons()[0];
        if (first) { first.focus(); }
    }

    function closeMenu(restoreFocus) {
        if (menu.hidden) { return; }
        menu.hidden = true;
        if (restoreFocus && menuReturn && document.contains(menuReturn)) { menuReturn.focus(); }
        menuRows = null;
        menuReturn = null;
    }

    menu.addEventListener('click', function (e) {
        var b = e.target.closest('.dms-ob-menu-item');
        if (!b || !menuRows) { return; }
        var op = b.getAttribute('data-op');
        var rows = menuRows;
        closeMenu(true);
        if (op === 'unselect') {
            clearSelection();
        } else if (op === 'delete') {
            bulkDelete(rows);
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
     * Delete selected
     * ------------------------------------------------------------------ */

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

    // bulk_delete.php takes at most 1000 ids per request; large selections
    // (e.g. Select all on ~2,400 resolutions) are sent in chunks, one after another.
    var CHUNK = 500;

    /** Remove the rows with these ids from the table; returns how many were removed. */
    function removeRows(deletedIds) {
        var gone = {};
        deletedIds.forEach(function (id) { gone[String(id)] = true; });
        var removed = allRows().filter(function (tr) { return gone[tr.getAttribute('data-id')]; });
        removed.forEach(function (tr) { markRow(tr, false); });
        var api = dt();
        if (api) {
            api.rows(removed).remove().draw(false); // timeline cards follow the table on draw
        } else {
            removed.forEach(function (tr) { tr.parentNode.removeChild(tr); });
        }
        refresh();
        return removed.length;
    }

    function bulkDelete(rows) {
        if (busy || !rows.length || !window.Swal) { return; }
        var ids = rows.map(function (tr) { return tr.getAttribute('data-id'); });
        var total = ids.length;
        var pending = ids.slice(); // ids not yet sent successfully
        var removedCount = 0;      // rows already removed after an earlier partial run
        var count = plural(total, noun);
        var promptText = 'Enter the password to delete the selected ' + (total === 1 ? noun : noun + 's') + '. This cannot be undone.';

        function setText(text) {
            var box = Swal.getHtmlContainer && Swal.getHtmlContainer();
            if (box) { box.textContent = text; }
        }

        Swal.fire({
            title: 'Delete ' + count + '?',
            text: promptText,
            icon: 'warning',
            input: 'password',
            inputAttributes: { autocapitalize: 'off', required: true },
            showCancelButton: true,
            confirmButtonText: 'Delete',
            confirmButtonColor: '#d33',
            showLoaderOnConfirm: true,
            preConfirm: function (password) {
                busy = true;
                var deleted = [];
                var chunks = [];
                for (var i = 0; i < pending.length; i += CHUNK) { chunks.push(pending.slice(i, i + CHUNK)); }
                var sent = total - pending.length;

                function next(index) {
                    if (index >= chunks.length) { return Promise.resolve(); }
                    var chunk = chunks[index];
                    if (chunks.length > 1) {
                        setText('Deleting… ' + sent.toLocaleString() + ' of ' + total.toLocaleString() + ' done.');
                    }
                    var body = 'type=' + encodeURIComponent(type) + '&ids=' + encodeURIComponent(chunk.join(',')) + '&password=' + encodeURIComponent(password || '');
                    return post('bulk_delete.php', body).then(function (data) {
                        if (!data || !data.success) {
                            throw new Error((data && data.message) || 'The records could not be deleted.');
                        }
                        deleted = deleted.concat(data.deleted || []);
                        sent += chunk.length;
                        pending = pending.slice(chunk.length);
                        return next(index + 1);
                    });
                }

                return next(0)
                    .then(function () {
                        return { deleted: deleted };
                    })
                    .catch(function (err) {
                        // Chunks already sent are gone from the database: take them
                        // out of the table now, so a retry only sends the rest.
                        if (deleted.length) {
                            removedCount += removeRows(deleted);
                            setText(removedCount + ' of ' + total + ' deleted before the error. ' + promptText);
                        } else {
                            setText(promptText);
                        }
                        Swal.showValidationMessage(err.message);
                    })
                    .then(function (data) {
                        busy = false;
                        return data;
                    });
            },
            allowOutsideClick: function () { return !Swal.isLoading(); }
        }).then(function (result) {
            if (!result.isConfirmed || !result.value) {
                if (removedCount) { toast(plural(removedCount, noun) + ' deleted; the rest were not', 'warning'); }
                return;
            }
            var n = removedCount + removeRows(result.value.deleted || []);
            toast(plural(n, noun) + ' deleted' + (n < total ? '; ' + (total - n) + ' could not be deleted' : ''));
        });
    }

    /* ------------------------------------------------------------------ *
     * Row events
     * ------------------------------------------------------------------ */

    table.addEventListener('click', function (e) {
        var head = e.target.closest && e.target.closest('[data-bulk-check-all]');
        if (head) {
            e.stopPropagation();
            selectAllShown(head.checked);
            return;
        }

        var tr = rowFrom(e.target);
        if (!tr) { return; }

        var cb = e.target.closest && e.target.closest('[data-bulk-check]');
        if (cb && selecting) {
            unselect(tr);
            toggleRow(tr, e.shiftKey, cb.checked);
            return;
        }

        if (isControl(e.target)) { return; }

        // Ctrl/Cmd+click starts selection mode with this row.
        if (!selecting && (e.ctrlKey || e.metaKey)) {
            unselect(tr);
            setSelecting(true);
            toggleRow(tr, false, true);
            return;
        }

        if (selecting) {
            unselect(tr);
            if (e.shiftKey && window.getSelection) { window.getSelection().removeAllRanges(); }
            toggleRow(tr, e.shiftKey);
        }
    });

    // Shift+click in selection mode: don't start a text selection.
    table.addEventListener('mousedown', function (e) {
        if (selecting && e.shiftKey && rowFrom(e.target)) { e.preventDefault(); }
    });

    // Right-click opens the Actions menu only in selection mode; otherwise the
    // browser's own menu is left alone.
    table.addEventListener('contextmenu', function (e) {
        if (!selecting) { return; }
        var tr = rowFrom(e.target);
        if (!tr || isControl(e.target)) { return; }
        e.preventDefault();
        var x = e.clientX, y = e.clientY;
        if (e.button !== 2) { // ContextMenu key / Shift+F10
            var r = tr.getBoundingClientRect();
            x = r.left + 24;
            y = r.top + Math.min(r.height, 40);
        }
        if (!isSelected(tr)) { toggleRow(tr, false, true); } // right-clicking adds the row
        openMenu(selectedRows(), x, y, tr);
    });

    table.addEventListener('keydown', function (e) {
        var tr = rowFrom(e.target);
        if (!tr || e.target !== tr || !selecting) { return; }
        if (e.key === ' ') {
            e.preventDefault();
            toggleRow(tr, e.shiftKey);
        }
    });

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && selecting && menu.hidden && !document.querySelector('.swal2-container, .modal.show')) {
            setSelecting(false);
        }
    });
})(window, document, window.jQuery);
