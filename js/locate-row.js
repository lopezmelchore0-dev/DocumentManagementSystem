/**
 * "Show in list" from the History page: a list page opened with ?locate=<id>
 * switches to the table view, pages the DataTable #example to the row whose
 * <tr data-id> matches, scrolls it into view and flashes it (.dms-located in
 * dms-theme.css). If the current search hides the row, the search is cleared.
 * The ?locate= part is then removed from the address so a reload is clean.
 */
(function ($) {
    'use strict';

    var TABLE = '#example';
    var match = /[?&]locate=(\d+)/.exec(window.location.search);
    if (!match) { return; }
    var id = match[1];

    function dropParam() {
        try {
            var url = new URL(window.location.href);
            url.searchParams.delete('locate');
            // keep history.state: js/dms-nav.js stores the entry's id there
            window.history.replaceState(window.history.state, '', url.pathname + url.search + url.hash);
        } catch (e) { /* old browser: leave the address as is */ }
    }

    function position(dt) {
        var nodes = dt.rows({ order: 'applied', search: 'applied' }).nodes().toArray();
        for (var i = 0; i < nodes.length; i++) {
            if (nodes[i].getAttribute('data-id') === id) { return { node: nodes[i], index: i }; }
        }
        return null;
    }

    function locate() {
        if (!$.fn.DataTable || !$.fn.DataTable.isDataTable(TABLE)) { return; }
        var dt = $(TABLE).DataTable();

        // The timeline view hides the table; show the table instead.
        var $tableBtn = $('[data-ht-toggle] button[data-view="table"]');
        if ($tableBtn.length && !$tableBtn.hasClass('is-active')) { $tableBtn.trigger('click'); }

        var hit = position(dt);
        if (!hit && dt.search()) { // a remembered search hides it
            dt.search('').draw(false);
            hit = position(dt);
        }
        var $allTab = $('[data-ob-tabs] [data-ob-tab=""]'); // Order of Business: a remembered section tab hides it
        if (!hit && $allTab.length && !$allTab.hasClass('is-active')) {
            $allTab.trigger('click');
            hit = position(dt);
        }
        dropParam();
        if (!hit) { return; }

        var len = dt.page.len();
        if (len > 0) { dt.page(Math.floor(hit.index / len)).draw(false); }

        var tr = hit.node;
        tr.classList.remove('dms-located');
        void tr.offsetWidth; // restart the flash
        tr.classList.add('dms-located');
        tr.scrollIntoView({ block: 'center', behavior: 'smooth' });
        if (!tr.hasAttribute('tabindex')) { tr.setAttribute('tabindex', '-1'); }
        try { tr.focus({ preventScroll: true }); } catch (e) { /* focus options unsupported */ }
    }

    // Run after the page's own ready handler has built the DataTable.
    $(function () { setTimeout(locate, 0); });
})(jQuery);
