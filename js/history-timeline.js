/**
 * Table | Timeline switch for the list pages (includes/history_timeline.php).
 *
 * The timeline cards mirror the DataTable #example: after every draw, only
 * cards whose <tr data-id> passes the current search and report-toolbar
 * filters stay visible (all of them, not just the current page). Empty months
 * are hidden and their counts updated. The chosen view is remembered per page.
 */
(function ($) {
    'use strict';

    var TABLE = '#example';
    var storeKey = 'dms-view:' + window.location.pathname;

    function readView() {
        try { return window.localStorage.getItem(storeKey) === 'timeline' ? 'timeline' : 'table'; }
        catch (e) { return 'table'; }
    }

    function saveView(view) {
        try { window.localStorage.setItem(storeKey, view); } catch (e) { /* storage blocked */ }
    }

    function sync() {
        var $timeline = $('[data-history-timeline]');
        if (!$timeline.length || !$.fn.DataTable || !$.fn.DataTable.isDataTable(TABLE)) {
            return;
        }

        var visible = {};
        $($(TABLE).DataTable().rows({ search: 'applied' }).nodes()).each(function () {
            visible[this.getAttribute('data-id')] = true;
        });

        var total = 0;
        $timeline.find('.dms-ht-month').each(function () {
            var shown = 0;
            $(this).find('.dms-ht-card').each(function () {
                var on = visible[this.getAttribute('data-id')] === true;
                this.hidden = !on;
                if (on) { shown++; }
            });
            this.hidden = shown === 0;
            $(this).find('[data-ht-count]').text(shown + (shown === 1 ? ' record' : ' records'));
            total += shown;
        });

        $timeline.find('[data-ht-empty]').prop('hidden', total > 0);
    }

    function setView(view) {
        var timeline = view === 'timeline';
        $('.dms-ht-host').toggleClass('is-timeline', timeline);
        $('[data-history-timeline]').prop('hidden', !timeline);
        $('[data-ht-toggle] button').each(function () {
            var active = $(this).data('view') === view;
            $(this).toggleClass('is-active', active).attr('aria-pressed', active ? 'true' : 'false');
        });
        if (timeline) {
            sync();
        }
    }

    // Delegated, so it survives the pages destroying and re-creating the table.
    $(document).on('draw.dt', TABLE, sync);

    $(document).on('click', '[data-ht-toggle] button', function () {
        var view = $(this).data('view');
        saveView(view);
        setView(view);
    });

    $(function () {
        setView(readView());
        sync();
    });
})(jQuery);
