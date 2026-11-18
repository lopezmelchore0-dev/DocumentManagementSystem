<?php
/**
 * Dashboard "Print & Export Reports" modal.
 *
 * The only place printing/exporting is offered: Report, From/To, Status,
 * quick ranges, Paper size / Orientation, Reset, Preview, Print, PDF, Excel. Behaviour comes from js/report-toolbar.js and the output from
 * export_report.php; report types and status lists come from report_config.php.
 * Opened by the "Print & Export Reports" link in the sidebar (sidebar.php) ([data-rt-open]).
 *
 * Optional $reportModalPage ('resolution' | 'ordinance' | 'minutes' | 'committee'):
 * set on a list page, it preselects that report and lets the filters narrow the
 * page's #example table (rows carry data-date / data-filter).
 */

require_once __DIR__ . '/report_data.php'; // also loads report_config.php

$rmLabels = [
    'resolution' => 'Resolutions',
    'ordinance'  => 'Ordinances',
    'minutes'    => 'Order of Business',
    'committee'  => 'Committee Reports',
];

$rmFilters = [];
foreach (dms_report_modules() as $rmKey => $rmCfg) {
    $rmFilters[$rmKey] = [
        'label'   => $rmCfg['filterLabel'],
        'all'     => $rmCfg['filterAll'],
        'options' => array_map('strval', array_keys($rmCfg['filters'])),
    ];
}
$rmPage  = (isset($reportModalPage) && isset($rmFilters[$reportModalPage])) ? $reportModalPage : '';
$rmFirst = $rmPage !== '' ? $rmPage : key($rmFilters);
?>
<div class="modal fade" id="dmsReportModal" tabindex="-1" role="dialog" aria-labelledby="dmsReportModalTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered dms-report-modal" role="document">
        <div class="modal-content" data-report-toolbar
             <?php if ($rmPage !== '') { ?>data-table="example" data-table-module="<?php echo htmlspecialchars($rmPage); ?>"<?php } ?>
             data-filters="<?php echo htmlspecialchars(json_encode($rmFilters), ENT_QUOTES, 'UTF-8'); ?>">

            <div class="modal-header">
                <span class="dms-rm-icon" aria-hidden="true"><i class="fa fa-print"></i></span>
                <h5 class="modal-title" id="dmsReportModalTitle">Print &amp; Export Reports</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" aria-hidden="true"><path d="M5 5l14 14M19 5L5 19"/></svg>
                </button>
            </div>

            <div class="modal-body">
                <div class="dms-rm-fields">
                    <div class="dms-rm-field dms-rm-wide">
                        <label for="rmModule">Report</label>
                        <select id="rmModule" class="form-control" data-rt="module">
                            <?php foreach ($rmFilters as $rmKey => $unused) { ?>
                                <option value="<?php echo htmlspecialchars($rmKey); ?>"<?php echo $rmKey === $rmFirst ? ' selected' : ''; ?>><?php echo htmlspecialchars(isset($rmLabels[$rmKey]) ? $rmLabels[$rmKey] : dms_report_module($rmKey)['title']); ?></option>
                            <?php } ?>
                        </select>
                    </div>
                    <div class="dms-rm-field">
                        <label for="rmFrom">From</label>
                        <input type="date" id="rmFrom" class="form-control" data-rt="from">
                    </div>
                    <div class="dms-rm-field">
                        <label for="rmTo">To</label>
                        <input type="date" id="rmTo" class="form-control" data-rt="to">
                    </div>
                    <div class="dms-rm-field dms-rm-wide">
                        <label for="rmFilter" data-rt="filter-label"><?php echo htmlspecialchars($rmFilters[$rmFirst]['label']); ?></label>
                        <select id="rmFilter" class="form-control" data-rt="filter">
                            <option value=""><?php echo htmlspecialchars($rmFilters[$rmFirst]['all']); ?></option>
                        </select>
                    </div>
                </div>

                <p class="dms-rm-error" data-rt="error" hidden>“From” must be on or before “To”.</p>

                <div class="dms-rt-quick dms-rm-quick" role="group" aria-label="Quick date ranges">
                    <button type="button" data-range="this-month"><i class="fa fa-calendar"></i>This Month</button>
                    <button type="button" data-range="last-month"><i class="fa fa-calendar"></i>Last Month</button>
                    <button type="button" data-range="this-year"><i class="fa fa-calendar"></i>This Year</button>
                    <button type="button" data-range="all" class="is-active"><i class="fa fa-calendar"></i>All Time</button>
                </div>

                <div class="dms-rm-fields dms-rm-paper">
                    <div class="dms-rm-field">
                        <label for="rmPaper">Paper size</label>
                        <select id="rmPaper" class="form-control" data-rt="paper">
                            <?php foreach (dms_report_paper_sizes() as $rmKey => $rmSize) { ?>
                                <option value="<?php echo htmlspecialchars($rmKey); ?>" data-size="<?php echo htmlspecialchars(implode(' ', $rmSize['css'])); ?>"<?php echo $rmKey === DMS_REPORT_PAPER_DEFAULT ? ' selected' : ''; ?>><?php echo htmlspecialchars($rmSize['label']); ?></option>
                            <?php } ?>
                        </select>
                    </div>
                    <div class="dms-rm-field">
                        <label for="rmOrient">Orientation</label>
                        <select id="rmOrient" class="form-control" data-rt="orient">
                            <option value="portrait" selected>Portrait</option>
                            <option value="landscape">Landscape</option>
                        </select>
                    </div>
                    <p class="dms-rm-hint">Used by Preview, Print and PDF. Your choice is remembered. Need a PDF file? Use <strong>Export to PDF</strong>; Print is for paper.</p>
                </div>

                <div class="dms-rm-actions">
                    <button type="button" class="btn dms-rm-reset" data-rt="reset"><i class="fa fa-undo"></i>Reset Filters</button>
                    <div class="dms-rm-exports">
                        <button type="button" class="btn dms-rm-preview" data-export="preview"><i class="fa fa-eye"></i>Preview</button>
                        <button type="button" class="btn dms-rm-print" data-export="print"><i class="fa fa-print"></i>Print Report</button>
                        <button type="button" class="btn dms-rm-pdf" data-export="pdf"><i class="fa fa-file-pdf-o"></i>Export to PDF</button>
                        <button type="button" class="btn dms-rm-xlsx" data-export="xlsx"><i class="fa fa-file-excel-o"></i>Export to Excel</button>
                    </div>
                </div>
            </div>

        </div>
    </div>
</div>
