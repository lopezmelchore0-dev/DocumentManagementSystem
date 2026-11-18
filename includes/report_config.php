<?php
/**
 * Report definitions for the Print / Export to PDF / Export to Excel toolbar.
 *
 * Every module the toolbar supports is described here once; the toolbar
 * partial, the data layer and the exporters all read from this config.
 *
 *  table        DB table to read
 *  select       columns to SELECT (fixed, never user input)
 *  dateCol      column the From/To range applies to
 *  dateLabel    human wording of that column (shown in the toolbar note)
 *  orderBy      ORDER BY clause for exports
 *  filterLabel  label of the status/category dropdown
 *  filterAll    text of the "no filter" option
 *  filters      value => [where-fragment, bound params]; the value is what
 *               the list page puts in each row's data-filter attribute
 *  columns      export columns: label, key (row field), relative width w
 *               (mm at A4 landscape, 277 in total; the PDF and the print
 *               page scale them to the chosen paper), align, type
 *
 * Paper sizes for Print / Preview / PDF: dms_report_paper_sizes() below.
 */

function dms_report_modules()
{
    $remarksFilters = [];
    foreach (['Forwarded to LCE', 'Signed by LCE', 'SP Approval', 'Disapprove'] as $status) {
        $remarksFilters[$status] = ['remarks = ?', [$status]];
    }

    $categoryFilters = [];
    foreach (['Economic', 'Social', 'Infrastructure', 'Institutional', 'Environmental'] as $category) {
        $categoryFilters[$category] = ['committee_category = ?', [$category]];
    }

    return [
        'resolution' => [
            'title'       => 'Resolutions Report',
            'table'       => 'resolution',
            'select'      => 'id, reso_no, title, author_sponsor, d_adopted, remarks',
            'dateCol'     => 'd_adopted',
            'dateLabel'   => 'date adopted',
            'orderBy'     => 'd_adopted ASC, id ASC',
            'filterLabel' => 'Status',
            'filterAll'   => 'All statuses',
            'filters'     => $remarksFilters,
            'columns'     => [
                ['label' => 'RESO NO.',       'key' => 'reso_no',        'w' => 30,  'align' => 'C'],
                ['label' => 'TITLE',          'key' => 'title',          'w' => 120, 'align' => 'L'],
                ['label' => 'AUTHOR/SPONSOR', 'key' => 'author_sponsor', 'w' => 55,  'align' => 'C'],
                ['label' => 'DATE ADOPTED',   'key' => 'd_adopted',      'w' => 35,  'align' => 'C', 'type' => 'date'],
                ['label' => 'REMARKS',        'key' => 'remarks',        'w' => 37,  'align' => 'C'],
            ],
        ],

        'ordinance' => [
            'title'       => 'Ordinances Report',
            'table'       => 'ordinance',
            'select'      => 'id, mo_no, title, date_adopted, author_sponsor, remarks',
            'dateCol'     => 'date_adopted',
            'dateLabel'   => 'date adopted',
            'orderBy'     => 'date_adopted ASC, id ASC',
            'filterLabel' => 'Status',
            'filterAll'   => 'All statuses',
            'filters'     => $remarksFilters,
            'columns'     => [
                ['label' => 'MO NO.',         'key' => 'mo_no',          'w' => 30,  'align' => 'C'],
                ['label' => 'TITLE',          'key' => 'title',          'w' => 120, 'align' => 'L'],
                ['label' => 'AUTHOR/SPONSOR', 'key' => 'author_sponsor', 'w' => 55,  'align' => 'C'],
                ['label' => 'DATE ADOPTED',   'key' => 'date_adopted',   'w' => 35,  'align' => 'C', 'type' => 'date'],
                ['label' => 'REMARKS',        'key' => 'remarks',        'w' => 37,  'align' => 'C'],
            ],
        ],

        'minutes' => [
            'title'       => 'Order of Business Report',
            'table'       => 'minutes',
            'select'      => 'id, no_regSession, date, resNo, title, status, returnNo, resolutionNo, returnDate, resolutionDate, ob_action, reading, referral_office, referral_notes, ob_status',
            'dateCol'     => 'date',
            'dateLabel'   => 'session date',
            'orderBy'     => "date ASC, CAST(SUBSTRING_INDEX(resNo, '.', 1) AS UNSIGNED) ASC, CAST(SUBSTRING_INDEX(resNo, '.', -1) AS UNSIGNED) ASC",
            'filterLabel' => 'Section',
            'filterAll'   => 'All sections',
            // Same rule as dms_ob_section() (includes/minutes_workflow.php):
            // the stored reading, First Reading for anything unknown.
            'filters'     => [
                'First Reading'  => ["reading NOT IN ('Second Reading', 'Third Reading', 'Other Matters')", []],
                'Second Reading' => ["reading = 'Second Reading'", []],
                'Third Reading'  => ["reading = 'Third Reading'", []],
                'Other Matters'  => ["reading = 'Other Matters'", []],
            ],
            'columns'     => [
                ['label' => 'SESSION',  'key' => 'no_regSession', 'w' => 42,  'align' => 'C'],
                ['label' => 'DATE',     'key' => 'date',          'w' => 32,  'align' => 'C', 'type' => 'date'],
                ['label' => 'ITEM NO.', 'key' => 'resNo',         'w' => 18,  'align' => 'C'],
                ['label' => 'TITLE',    'key' => 'title',         'w' => 115, 'align' => 'L'],
                ['label' => 'STATUS',   'key' => 'status_full',   'w' => 70,  'align' => 'L'],
            ],
        ],

        'committee' => [
            'title'       => 'Committee Reports',
            'table'       => 'committee_reports',
            'select'      => 'id, title, committee_category, committee_section, councilor, date_report',
            'dateCol'     => 'date_report',
            'dateLabel'   => 'date reported',
            'orderBy'     => 'date_report ASC, id ASC',
            'filterLabel' => 'Category',
            'filterAll'   => 'All categories',
            'filters'     => $categoryFilters,
            'columns'     => [
                ['label' => 'TITLE',              'key' => 'title',              'w' => 111, 'align' => 'L'],
                ['label' => 'COMMITTEE CATEGORY', 'key' => 'committee_category', 'w' => 39,  'align' => 'C'],
                ['label' => 'COMMITTEE SECTION',  'key' => 'committee_section',  'w' => 45,  'align' => 'C'],
                ['label' => 'COUNCILOR',          'key' => 'councilor',          'w' => 47,  'align' => 'C'],
                ['label' => 'DATE REPORTED',      'key' => 'date_report',        'w' => 35,  'align' => 'C', 'type' => 'date'],
            ],
        ],
    ];
}

/** Config for one module, or null when the module name is unknown. */
function dms_report_module($module)
{
    $modules = dms_report_modules();
    return isset($modules[$module]) ? $modules[$module] : null;
}

const DMS_REPORT_PAPER_DEFAULT  = 'long';
const DMS_REPORT_ORIENT_DEFAULT = 'portrait';

/**
 * Paper sizes offered for printing. w/h are portrait millimetres; css is the
 * portrait @page size.
 */
function dms_report_paper_sizes()
{
    return [
        'letter' => ['label' => 'Short / Letter (8.5 × 11 in)', 'w' => 215.9, 'h' => 279.4, 'css' => ['8.5in', '11in']],
        'long'   => ['label' => 'Long / Folio (8.5 × 13 in)',   'w' => 215.9, 'h' => 330.2, 'css' => ['8.5in', '13in']],
        'legal'  => ['label' => 'Legal (8.5 × 14 in)',          'w' => 215.9, 'h' => 355.6, 'css' => ['8.5in', '14in']],
        'a4'     => ['label' => 'A4 (210 × 297 mm)',            'w' => 210.0, 'h' => 297.0, 'css' => ['210mm', '297mm']],
    ];
}

/**
 * The paper for a request, validated (unknown values fall back to Long,
 * Portrait) and already turned for landscape: key, orient, label, w, h (mm),
 * css (e.g. "8.5in 13in").
 */
function dms_report_paper($key, $orient)
{
    $sizes  = dms_report_paper_sizes();
    $key    = isset($sizes[$key]) ? $key : DMS_REPORT_PAPER_DEFAULT;
    $orient = in_array($orient, ['portrait', 'landscape'], true) ? $orient : DMS_REPORT_ORIENT_DEFAULT;
    $size   = $sizes[$key];

    $w   = $size['w'];
    $h   = $size['h'];
    $css = $size['css'];
    if ($orient === 'landscape') {
        list($w, $h) = [$h, $w];
        $css = [$css[1], $css[0]];
    }

    return [
        'key'    => $key,
        'orient' => $orient,
        'label'  => $size['label'],
        'w'      => $w,
        'h'      => $h,
        'css'    => $css[0] . ' ' . $css[1],
    ];
}

/** Office name for the running header/footer of printed reports. */
function dms_report_office_name()
{
    return 'Sangguniang Bayan · Municipality of Talisay, Camarines Norte';
}
