<?php
/**
 * Report toolbar card: date range, quick ranges, status/category filter,
 * Reset / Print / Export to PDF / Export to Excel.
 *
 * Set $reportModule ('resolution' | 'ordinance' | 'minutes' | 'committee')
 * before including. The list table must be #example and each <tr> must carry
 * data-date (Y-m-d) and data-filter (see dms_report_row_key()). Load
 * js/report-toolbar.js after the DataTables scripts.
 */

require_once __DIR__ . '/report_data.php'; // also loads report_config.php; pages use dms_report_row_key()

$rtCfg = dms_report_module(isset($reportModule) ? $reportModule : '');
if ($rtCfg === null) {
    return;
}
?>
<div class="card dms-report-toolbar" id="dmsReportToolbar" data-report-toolbar data-table="example" data-module="<?php echo htmlspecialchars($reportModule); ?>">
    <div class="card-body">
        <div class="dms-rt-fields">
            <div class="dms-rt-field">
                <label for="rtFrom">From</label>
                <input type="date" id="rtFrom" class="form-control" data-rt="from">
            </div>
            <div class="dms-rt-field">
                <label for="rtTo">To</label>
                <input type="date" id="rtTo" class="form-control" data-rt="to">
            </div>
            <div class="dms-rt-field">
                <label for="rtFilter"><?php echo htmlspecialchars($rtCfg['filterLabel']); ?></label>
                <select id="rtFilter" class="form-control" data-rt="filter">
                    <option value=""><?php echo htmlspecialchars($rtCfg['filterAll']); ?></option>
                    <?php foreach (array_keys($rtCfg['filters']) as $rtValue) { ?>
                        <option value="<?php echo htmlspecialchars($rtValue); ?>"><?php echo htmlspecialchars($rtValue); ?></option>
                    <?php } ?>
                </select>
            </div>
        </div>

        <div class="dms-rt-quick" role="group" aria-label="Quick date ranges">
            <button type="button" data-range="this-month">This Month</button>
            <button type="button" data-range="last-month">Last Month</button>
            <button type="button" data-range="this-year">This Year</button>
            <button type="button" data-range="all" class="is-active">All Time</button>
        </div>

        <p class="dms-rt-note">
            The date range applies to the <strong><?php echo htmlspecialchars($rtCfg['dateLabel']); ?></strong>.
            The table below, the printout and the exports all use these filters.
            <span class="dms-rt-error" id="rtError" data-rt="error" hidden>“From” must be on or before “To”.</span>
        </p>

        <div class="dms-rt-actions">
            <button type="button" class="btn dms-rt-reset" id="rtReset" data-rt="reset"><i class="fa fa-undo"></i>&nbsp;Reset Filters</button>
            <button type="button" class="btn dms-rt-btn" data-export="print"><i class="fa fa-print"></i>&nbsp;Print Report</button>
            <button type="button" class="btn dms-rt-btn" data-export="pdf"><i class="fa fa-file-pdf-o"></i>&nbsp;Export to PDF</button>
            <button type="button" class="btn dms-rt-btn" data-export="xlsx"><i class="fa fa-file-excel-o"></i>&nbsp;Export to Excel</button>
        </div>
    </div>
</div>
