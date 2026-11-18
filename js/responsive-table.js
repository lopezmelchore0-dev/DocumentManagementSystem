/*
 * Stacked-card tables on phones (presentation only).
 *
 * Any <table class="dms-stack"> keeps its normal layout on wider screens; under
 * 768px css/dms-theme.css shows each row as a card with "LABEL  value" lines.
 * This script supplies the labels: it copies each header cell's text onto the
 * matching body cells as data-label, and tags the first data cell (card title)
 * and the cell holding the row buttons (.dms-row-actions).
 *
 * DataTables only keeps the current page's rows in the DOM, so labelling runs
 * again on every draw (search, sort, paging) and whenever rows are added.
 * Loaded deferred from sidebar.php.
 */
(function (window, document) {
    'use strict';

    function headerNames(table) {
        var head = table.tHead;
        if (!head || !head.rows.length) { return []; }
        var cells = head.rows[head.rows.length - 1].cells, names = [];
        for (var i = 0; i < cells.length; i++) {
            var span = cells[i].colSpan || 1;
            var text = (cells[i].textContent || '').replace(/\s+/g, ' ').trim();
            for (var s = 0; s < span; s++) { names.push(text); }
        }
        return names;
    }

    function label(table) {
        if (!table || !table.classList || !table.classList.contains('dms-stack')) { return; }
        var names = headerNames(table);
        for (var b = 0; b < table.tBodies.length; b++) {
            var rows = table.tBodies[b].rows;
            for (var r = 0; r < rows.length; r++) {
                var cells = rows[r].cells, col = 0, titled = false;
                // "No matching records" and similar full-width rows
                if (cells.length === 1 && cells[0].colSpan > 1) {
                    cells[0].classList.add('dms-stack-full');
                    continue;
                }
                for (var c = 0; c < cells.length; c++) {
                    var td = cells[c];
                    td.setAttribute('data-label', names[col] || '');
                    col += td.colSpan || 1;
                    if (td.classList.contains('dms-ob-check-col')) { continue; }
                    if (td.querySelector('.dms-row-actions, [data-stack-actions]')) {
                        td.classList.add('dms-stack-actions');
                    } else if (!titled) {
                        td.classList.add('dms-stack-title');
                        titled = true;
                    }
                }
            }
        }
    }

    // Rows added later (history modals filled by fetch, rows re-rendered by scripts) are
    // labelled too: one pass per animation frame per table.
    var watched = typeof WeakSet === 'function' ? new WeakSet() : null;
    function watch(table) {
        if (!watched || watched.has(table) || typeof MutationObserver !== 'function') { return; }
        watched.add(table);
        var pending = false;
        new MutationObserver(function () {
            if (pending) { return; }
            pending = true;
            requestAnimationFrame(function () { pending = false; label(table); });
        }).observe(table, { childList: true, subtree: true });
    }

    function labelAll() {
        var tables = document.querySelectorAll('table.dms-stack');
        for (var i = 0; i < tables.length; i++) { label(tables[i]); watch(tables[i]); }
    }

    // Other scripts that add rows by hand can call this.
    window.dmsStackTables = labelAll;

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', labelAll);
    } else {
        labelAll();
    }
    window.addEventListener('load', labelAll);

    // DataTables draw events bubble up through jQuery
    var $ = window.jQuery;
    if ($) {
        $(document).on('draw.dt', function (e, settings) {
            label(settings && settings.nTable ? settings.nTable : e.target);
        });
    }
})(window, document);
