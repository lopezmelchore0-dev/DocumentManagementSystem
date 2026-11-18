/**
 * Behaviour of the "Print & Export Reports" popup (includes/report-modal.php),
 * opened from the top-bar button in sidebar.php.
 *
 * Every element with [data-report-toolbar] is wired up on its own; parts are
 * found inside it through data-rt="from|to|filter|filter-label|module|reset|error".
 *  - Quick ranges fill From/To; Reset clears dates and status.
 *  - With data-table="<id>" the filters also narrow that DataTable live
 *    (rows carry data-date / data-filter); with data-table-module only while
 *    that report type is selected.
 *  - The module comes from a [data-rt="module"] select when present, otherwise
 *    from data-module. Changing that select swaps the status options using the
 *    root's data-filters JSON ({module: {label, all, options: []}}).
 *  - Preview / Print / PDF / Excel open export_report.php with the same filters.
 *    Preview, Print and PDF also send the paper size / orientation
 *    (data-rt="paper|orient"), remembered in localStorage 'dmsPrintPaper'.
 *  - Any [data-rt-open="#modalId"] button opens that report modal (the
 *    top-bar shortcut in sidebar.php).
 *
 * sidebar.php loads this file (deferred) on every page and the list pages also
 * load it themselves, so it only runs once.
 */
(function ($) {
    'use strict';

    if (window.dmsReportToolbarLoaded) {
        return;
    }
    window.dmsReportToolbarLoaded = true;

    // Last paper size / orientation, shared with the print page (export_report.php).
    var PAPER_KEY = 'dmsPrintPaper';

    function loadPaper() {
        try {
            var saved = JSON.parse(window.localStorage.getItem(PAPER_KEY) || 'null');
            return (saved && typeof saved === 'object') ? saved : {};
        } catch (e) {
            return {};
        }
    }

    function savePaper(paper, orient) {
        try {
            window.localStorage.setItem(PAPER_KEY, JSON.stringify({ paper: paper, orient: orient }));
        } catch (e) { /* storage unavailable: the choice just isn't remembered */ }
    }

    function pad(n) { return (n < 10 ? '0' : '') + n; }
    function ymd(d) { return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()); }

    function rangeFor(key) {
        var now = new Date(), y = now.getFullYear(), m = now.getMonth();
        switch (key) {
            case 'this-month': return [ymd(new Date(y, m, 1)), ymd(new Date(y, m + 1, 0))];
            case 'last-month': return [ymd(new Date(y, m - 1, 1)), ymd(new Date(y, m, 0))];
            case 'this-year':  return [ymd(new Date(y, 0, 1)), ymd(new Date(y, 11, 31))];
            default:           return ['', ''];
        }
    }

    function initToolbar(root) {
        var $root    = $(root);
        if ($root.data('rtReady')) {
            return;
        }
        $root.data('rtReady', true);

        var $from    = $root.find('[data-rt="from"]');
        var $to      = $root.find('[data-rt="to"]');
        var $filter  = $root.find('[data-rt="filter"]');
        var $fLabel  = $root.find('[data-rt="filter-label"]');
        var $module  = $root.find('[data-rt="module"]');
        var $error   = $root.find('[data-rt="error"]');
        var $quick   = $root.find('.dms-rt-quick button');
        var $paper   = $root.find('[data-rt="paper"]');
        var $orient  = $root.find('[data-rt="orient"]');
        var filters  = $root.data('filters') || {};
        var tableId  = $root.data('table') || '';
        var tableMod = $root.data('table-module') || ''; // table only follows this report type
        var hasTable = tableId !== '' && !!$.fn.dataTable;

        function currentModule() {
            return $module.length ? $module.val() : $root.data('module');
        }

        function invalidRange() {
            var from = $from.val(), to = $to.val();
            return from !== '' && to !== '' && from > to;
        }

        // Highlight the quick range that matches the current dates, if any.
        function syncQuick() {
            var from = $from.val(), to = $to.val();
            $quick.each(function () {
                var r = rangeFor($(this).data('range'));
                $(this).toggleClass('is-active', r[0] === from && r[1] === to);
            });
        }

        // Rebuild the status/category options for the selected module.
        function loadFilterOptions() {
            var def = filters[currentModule()];
            if (!def) {
                return;
            }
            $fLabel.text(def.label);
            $filter.empty().append($('<option>', { value: '', text: def.all }));
            $.each(def.options, function (i, value) {
                $filter.append($('<option>', { value: value, text: value }));
            });
            $filter.val('');
        }

        // Live table filtering. ext.search is global, so it survives the
        // page re-initialising the DataTable.
        if (hasTable) {
            $.fn.dataTable.ext.search.push(function (settings, data, dataIndex) {
                if (settings.nTable.id !== tableId) {
                    return true;
                }
                if (tableMod && currentModule() !== tableMod) {
                    return true; // another report type is selected; leave this table alone
                }
                var tr = settings.aoData[dataIndex] && settings.aoData[dataIndex].nTr;
                if (!tr) {
                    return true;
                }
                var from = $from.val(), to = $to.val(), f = $filter.val();
                var date = tr.getAttribute('data-date') || '';

                if (from || to) {
                    if (!date || date === '0000-00-00') { return false; }
                    if (from && date < from) { return false; }
                    if (to && date > to) { return false; }
                }
                if (f && tr.getAttribute('data-filter') !== f) {
                    return false;
                }
                return true;
            });
        }

        function redraw() {
            var bad = invalidRange();
            $error.prop('hidden', !bad);
            $from.add($to).toggleClass('is-invalid', bad);
            syncQuick();
            if (hasTable && $.fn.DataTable.isDataTable('#' + tableId)) {
                $('#' + tableId).DataTable().draw();
            }
        }

        $from.add($to).add($filter).on('change input', redraw);

        $module.on('change', function () {
            loadFilterOptions();
            redraw();
        });

        $quick.on('click', function () {
            var r = rangeFor($(this).data('range'));
            $from.val(r[0]);
            $to.val(r[1]);
            redraw();
        });

        $root.find('[data-rt="reset"]').on('click', function () {
            $from.val('');
            $to.val('');
            $filter.val('');
            redraw();
        });

        $root.find('[data-export]').on('click', function () {
            if (invalidRange()) {
                Swal.fire({
                    icon: 'error',
                    title: 'Invalid date range',
                    text: '"From" must be on or before "To".'
                });
                return;
            }

            var format = $(this).data('export');
            var params = {
                module: currentModule(),
                format: format === 'preview' ? 'print' : format,
                from: $from.val(),
                to: $to.val(),
                filter: $filter.val()
            };
            if (format !== 'xlsx' && $paper.length) {
                params.paper  = $paper.val();
                params.orient = $orient.val();
            }
            if (format === 'preview') {
                params.preview = 1; // same page as Print, without the automatic print dialog
            }
            var url = 'export_report.php?' + $.param(params);

            if (format === 'xlsx') {
                window.location.href = url; // served as an attachment, page stays put
            } else {
                window.open(url, '_blank');
            }
        });

        // Restore the remembered paper; keep the markup default if the saved
        // value is no longer offered.
        if ($paper.length) {
            var saved = loadPaper();
            var offers = function ($select, value) {
                return $select.find('option').filter(function () { return this.value === value; }).length > 0;
            };
            if (offers($paper, saved.paper)) {
                $paper.val(saved.paper);
            }
            if (offers($orient, saved.orient)) {
                $orient.val(saved.orient);
            }
            $paper.add($orient).on('change', function () {
                savePaper($paper.val(), $orient.val());
            });
        }

        if ($module.length) {
            loadFilterOptions();
        }
        redraw();
    }

    // Ctrl+P straight from an app page: print on the remembered paper. Bootstrap's
    // print CSS forces A3, so a matching @page rule goes in just before printing and
    // comes out afterwards. Sizes come from the modal's paper options (data-size).
    var PRINT_RULE_ID = 'dmsPrintPaperRule';

    function removePrintRule() {
        $('#' + PRINT_RULE_ID).remove();
    }

    window.addEventListener('beforeprint', function () {
        removePrintRule();
        var saved   = loadPaper();
        var $opts   = $('[data-rt="paper"]').first().find('option');
        var $chosen = $opts.filter(function () { return this.value === saved.paper; });
        if (!$chosen.length) {
            $chosen = $opts.filter(function () { return this.defaultSelected; });
        }
        var size = String($chosen.first().data('size') || '8.5in 13in').split(' ');
        if (saved.orient === 'landscape') {
            size.reverse();
        }
        $('<style>', { id: PRINT_RULE_ID, text: '@page { size: ' + size.join(' ') + '; margin: 0.5in; }' })
            .appendTo(document.body); // last in the document, so it beats Bootstrap's @page
    });
    window.addEventListener('afterprint', removePrintRule);

    // Shortcut buttons. Opened from script rather than data-toggle: pages load
    // two jQuery/Bootstrap copies, and both would react to data-toggle.
    $(document).on('click', '[data-rt-open]', function (e) {
        e.preventDefault();
        var $modal = $($(this).data('rt-open'));
        if (!$modal.length || !$.fn.modal) {
            return;
        }
        if (!$modal.parent().is('body')) {
            $modal.appendTo('body'); // keep it out of the top bar's stacking context
        }
        $modal.modal('show');
    });

    $(function () {
        $('[data-report-toolbar]').each(function () {
            initToolbar(this);
        });
    });
})(jQuery);
