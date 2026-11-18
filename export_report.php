<?php
/**
 * Report export endpoint used by the report toolbar on the list pages.
 *
 *   export_report.php?module=resolution&format=pdf&from=2026-11-01&to=2026-11-30&filter=SP%20Approval
 *
 * module  resolution | ordinance | minutes | committee
 * format  pdf (inline PDF) | xlsx (download) | print (printable HTML page)
 * from/to optional Y-m-d range on the module's date column
 * filter  optional status/category value (see includes/report_config.php)
 * paper   letter | long | legal | a4 (default long)  – print and pdf
 * orient  portrait | landscape (default portrait)    – print and pdf
 * preview  1 = print page without the automatic print dialog
 * download 1 = pdf as a download instead of inline (the print page's "Download PDF")
 */

require 'session.php'; // redirects to login.php when not signed in; provides $conn
require_once __DIR__ . '/includes/report_data.php';

date_default_timezone_set('Asia/Manila');

$module = isset($_GET['module']) ? (string) $_GET['module'] : '';
$format = isset($_GET['format']) ? (string) $_GET['format'] : '';
$cfg    = dms_report_module($module);

if ($cfg === null || !in_array($format, ['pdf', 'xlsx', 'print'], true)) {
    http_response_code(400);
    exit('Invalid report request.');
}

$from = dms_report_valid_date(isset($_GET['from']) ? $_GET['from'] : '');
$to   = dms_report_valid_date(isset($_GET['to']) ? $_GET['to'] : '');
if ($from !== '' && $to !== '' && $from > $to) {
    list($from, $to) = [$to, $from];
}
$filter = isset($_GET['filter']) ? (string) $_GET['filter'] : '';
if (!isset($cfg['filters'][$filter])) {
    $filter = '';
}

$paper   = dms_report_paper(
    isset($_GET['paper']) ? (string) $_GET['paper'] : '',
    isset($_GET['orient']) ? (string) $_GET['orient'] : ''
);
$preview = isset($_GET['preview']) && $_GET['preview'] === '1';
$office  = dms_report_office_name();
$printed = 'Printed: ' . date('F j, Y g:i A');

$rows    = dms_report_fetch($conn, $module, $from, $to, $filter);
$columns = $cfg['columns'];

$period    = dms_report_period_label($from, $to);
$subLines  = ['Period: ' . $period];
if ($filter !== '') {
    $subLines[] = $cfg['filterLabel'] . ': ' . $filter;
}
$count     = count($rows);
$generated = 'Generated: ' . date('F j, Y g:i A') . '  ·  ' . $count . ' record' . ($count === 1 ? '' : 's');
$baseName  = $module . '_report_' . date('Y-m-d');

// Display text of every cell, in column order.
$cells = [];
foreach ($rows as $row) {
    $line = [];
    foreach ($columns as $col) {
        $line[] = dms_report_cell($row, $col);
    }
    $cells[] = $line;
}

/* ---------------------------------------------------------------- PDF */
if ($format === 'pdf') {
    require_once __DIR__ . '/includes/ReportPDF.php';

    $pdf = new DmsReportPDF($columns, __DIR__ . '/images/watermark.png', $paper, $office . '  ·  ' . $printed);
    $pdf->SetTitle($cfg['title'], true);
    $pdf->render($cfg['title'], $subLines, $generated, $cells);
    // download=1 (the print page's "Download PDF"): save straight to Downloads; otherwise view inline.
    $pdf->Output(isset($_GET['download']) && $_GET['download'] === '1' ? 'D' : 'I', $baseName . '.pdf');
    exit;
}

/* --------------------------------------------------------------- XLSX */
if ($format === 'xlsx') {
    require_once __DIR__ . '/includes/SimpleXlsx.php';

    $n    = count($columns);
    $xlsx = new DmsSimpleXlsx($cfg['title']);
    $xlsx->setWidths(array_map(function ($col) {
        return max(14, round($col['w'] * 0.5));
    }, $columns));

    $top = [
        ['Republic of the Philippines', DmsSimpleXlsx::STYLE_SUB],
        ['Province of Camarines Norte', DmsSimpleXlsx::STYLE_SUB],
        ['Municipality of Talisay', DmsSimpleXlsx::STYLE_SUB],
        [$cfg['title'], DmsSimpleXlsx::STYLE_TITLE],
    ];
    foreach ($subLines as $line) {
        $top[] = [$line, DmsSimpleXlsx::STYLE_SUB];
    }
    $top[] = [$generated, DmsSimpleXlsx::STYLE_SUB];

    foreach ($top as $item) {
        $xlsx->mergeRow($xlsx->addRow([$item[0]], $item[1]), $n);
    }
    $xlsx->addRow([]);

    $headerRow = $xlsx->addRow(array_column($columns, 'label'), DmsSimpleXlsx::STYLE_HEADER);
    $xlsx->freezeAfterRow($headerRow);

    foreach ($rows as $r => $row) {
        $line = [];
        foreach ($columns as $c => $col) {
            $isDate = isset($col['type']) && $col['type'] === 'date';
            $line[] = ($isDate && dms_report_has_date($row[$col['key']]))
                ? DmsSimpleXlsx::date($row[$col['key']])
                : $cells[$r][$c];
        }
        $xlsx->addRow($line, DmsSimpleXlsx::STYLE_BODY);
    }
    if (!$rows) {
        $xlsx->mergeRow($xlsx->addRow(['No records found for the selected filters.'], DmsSimpleXlsx::STYLE_BODY), $n);
    }

    $xlsx->download($baseName . '.xlsx');
    exit;
}

/* -------------------------------------------------------------- PRINT */
function h($text)
{
    return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8');
}
$totalWidth = array_sum(array_column($columns, 'w'));

// Portrait CSS sizes for the page's own Paper / Orientation dropdowns.
$paperCss = [];
foreach (dms_report_paper_sizes() as $key => $size) {
    $paperCss[$key] = $size['css'];
}
$jsonFlags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE;
list($paperW, $paperH) = explode(' ', $paper['css']);

// Server-made PDF of the same report: the way to get a PDF file without the printer driver.
$pdfUrl = 'export_report.php?' . http_build_query([
    'module' => $module, 'format' => 'pdf', 'from' => $from, 'to' => $to, 'filter' => $filter,
    'paper'  => $paper['key'], 'orient' => $paper['orient'], 'download' => 1,
]);
$largeReport = 300; // records; above this the page warns that printing will be slow
?>
<!DOCTYPE html>
<html lang="en" style="--paper-w: <?php echo h($paperW); ?>; --paper-h: <?php echo h($paperH); ?>;">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title><?php echo h($cfg['title'] . ' – ' . $period); ?></title>
    <link rel="icon" type="image/png" sizes="16x16" href="./images/logo.png">
    <style>
        /* --paper-w / --paper-h: the chosen paper, set on <html> and updated by the
           script at the bottom. The @page rule itself lives in #dmsPageRule. */
        :root { --text: #1A1D1A; --muted: #4A4F49; --line: #BFC3BC; --head: #EEF7EE; --accent: #098209; --margin: 0.5in; }
        * { box-sizing: border-box; }
        body { margin: 0; background: #F4F5F2; color: var(--text); font-family: "Times New Roman", Times, serif; }

        .screen-bar {
            position: sticky; top: 0; z-index: 2; display: flex; flex-wrap: wrap; gap: .5rem .75rem; align-items: center;
            padding: .75rem 16px; background: #fff; border-bottom: 1px solid #ECEDEA;
            font: 400 .875rem Inter, system-ui, sans-serif;
        }
        .screen-bar label { display: flex; align-items: center; gap: .4rem; color: var(--muted); }
        .screen-bar select, .screen-bar button, .screen-bar a.button {
            font: 500 .875rem Inter, system-ui, sans-serif; padding: .5rem .75rem; border-radius: 10px;
            border: 1px solid #DFE1DC; background: #fff; color: var(--text);
        }
        .screen-bar button, .screen-bar a.button { cursor: pointer; padding: .55rem 1rem; text-decoration: none; line-height: normal; }
        .screen-bar a.button { border-color: #C9302C; color: #C9302C; }
        .screen-bar button.primary { background: var(--accent); border-color: var(--accent); color: #fff; }
        .screen-warn {
            max-width: var(--paper-w); margin: 1rem auto 0; padding: .7rem 1rem; border-radius: 10px;
            background: #FFF6E0; border: 1px solid #F0D48A; color: #6B4E00;
            font: 400 .875rem/1.45 Inter, system-ui, sans-serif;
        }
        .screen-bar .note { flex: 1 1 14rem; color: var(--muted); font-size: .8rem; }
        .screen-bar .actions { margin-left: auto; display: flex; gap: .5rem; }

        /* On screen the sheet is a sheet of the chosen paper. The faint red lines show
           where each page's printable area ends (approximate: rows are never split). */
        .sheet {
            position: relative; width: var(--paper-w); max-width: 100%; min-height: var(--paper-h);
            margin: 1.5rem auto; padding: var(--margin); background-color: #fff;
            /* layer 1: page-end guides, kept inside the content box (no stray line in the
               top margin); layer 2: plain white paper, edge to edge */
            background-image: linear-gradient(to bottom, transparent calc(100% - 1px), rgba(200, 60, 60, .45) 0), linear-gradient(#fff, #fff);
            background-origin: content-box, border-box;
            background-clip: content-box, border-box;
            background-size: 100% calc(var(--paper-h) - 2 * var(--margin)), auto;
            background-repeat: repeat-y, no-repeat;
            box-shadow: 0 1px 3px rgba(0,0,0,.12), 0 0 0 1px rgba(0,0,0,.04);
        }
        .sheet::before {
            content: ""; position: absolute; inset: 0; z-index: 0; pointer-events: none;
            /* Opaque copy of watermark.png (flattened onto white). A transparent image on
               every page makes Windows printer drivers crawl, "Microsoft Print to PDF" most
               of all. It sits behind the table, so the white around the seal never shows. */
            background: url("images/watermark-print.png") center 260px / 340px no-repeat;
        }
        .sheet > * { position: relative; z-index: 1; }

        .lgu { text-align: center; line-height: 1.35; font-size: 12pt; }
        h1 { text-align: center; font-size: 16pt; margin: .8rem 0 .3rem; }
        .meta { text-align: center; font-size: 11pt; line-height: 1.45; }
        .meta .generated { font-style: italic; font-size: 9.5pt; color: var(--muted); }

        /* Fixed layout + wrapping: the table can never be wider than the page. */
        table {
            width: 100%; table-layout: fixed; border-collapse: collapse; margin-top: 1rem;
            font-family: Arial, Helvetica, sans-serif; font-size: 10pt;
        }
        th, td { border: 1px solid var(--line); padding: 4px 5px; vertical-align: top; overflow-wrap: anywhere; word-break: break-word; }
        th { background: var(--head); font-size: 9pt; text-align: center; vertical-align: middle; }
        td.c { text-align: center; }
        td.pre { white-space: pre-line; }
        td.empty { text-align: center; padding: 14px; color: var(--muted); }
        thead { display: table-header-group; }
        tr { break-inside: avoid; page-break-inside: avoid; }

        @media print {
            html, body { background: #fff; }
            .screen-bar, .screen-warn { display: none !important; }
            .sheet {
                width: 100%; max-width: none; min-height: 0; margin: 0; padding: 0;
                box-shadow: none; background-image: none;
            }
            /* black on white */
            body, .meta .generated, td.empty { color: #000; }
            th { background: #fff; }
            th, td { border-color: #666; }
            /* fixed = repeated, centred, on every printed page */
            .sheet::before { position: fixed; background-position: center; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        }
        @media screen and (max-width: 640px) {
            .sheet { width: auto; min-height: 0; margin: 0; padding: 1rem 16px; background-image: none; }
            .sheet table { display: block; overflow-x: auto; }
            .screen-bar .actions { margin-left: 0; }
        }
    </style>
    <!-- Paper size, margins and the running header/footer; rebuilt by the script below. -->
    <style id="dmsPageRule">@page { size: <?php echo h($paper['css']); ?>; margin: 0.5in; }</style>
</head>
<body>
    <div class="screen-bar">
        <label>Paper
            <select id="paperSize">
                <?php foreach (dms_report_paper_sizes() as $key => $size) { ?>
                    <option value="<?php echo h($key); ?>"<?php echo $key === $paper['key'] ? ' selected' : ''; ?>><?php echo h($size['label']); ?></option>
                <?php } ?>
            </select>
        </label>
        <label>Orientation
            <select id="paperOrient">
                <option value="portrait"<?php echo $paper['orient'] === 'portrait' ? ' selected' : ''; ?>>Portrait</option>
                <option value="landscape"<?php echo $paper['orient'] === 'landscape' ? ' selected' : ''; ?>>Landscape</option>
            </select>
        </label>
        <span class="note">To save a PDF file, use <strong>Download PDF</strong>. Printing to “Microsoft Print to PDF” is very slow for long reports. Red lines mark approximate page ends.</span>
        <div class="actions">
            <button type="button" onclick="window.close()">Close</button>
            <a class="button" id="pdfLink" href="<?php echo h($pdfUrl); ?>">Download PDF</a>
            <button type="button" class="primary" onclick="window.print()">Print</button>
        </div>
    </div>

    <?php if ($count > $largeReport) { ?>
        <div class="screen-warn">
            <strong>Large report:</strong> <?php echo number_format($count); ?> records, roughly <?php echo number_format(ceil($count / 7.5)); ?> pages.
            Printing it will take a long time. Narrow the date range or status, or use <strong>Download PDF</strong> to get a file instantly.
        </div>
    <?php } ?>

    <div class="sheet">
        <div class="lgu">
            Republic of the Philippines<br>
            Province of Camarines Norte<br>
            Municipality of Talisay
        </div>
        <h1><?php echo h($cfg['title']); ?></h1>
        <div class="meta">
            <?php foreach ($subLines as $line) { ?>
                <div><?php echo h($line); ?></div>
            <?php } ?>
            <div class="generated"><?php echo h($generated); ?></div>
        </div>

        <table>
            <colgroup>
                <?php foreach ($columns as $col) { ?>
                    <col style="width: <?php echo round($col['w'] / $totalWidth * 100, 2); ?>%">
                <?php } ?>
            </colgroup>
            <thead>
                <tr>
                    <?php foreach ($columns as $col) { ?>
                        <th><?php echo h($col['label']); ?></th>
                    <?php } ?>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($cells as $line) { ?>
                    <tr>
                        <?php foreach ($columns as $c => $col) { ?>
                            <td class="<?php echo $col['align'] === 'C' ? 'c' : ''; ?> pre"><?php echo h($line[$c]); ?></td>
                        <?php } ?>
                    </tr>
                <?php } ?>
                <?php if (!$cells) { ?>
                    <tr><td class="empty" colspan="<?php echo count($columns); ?>">No records found for the selected filters.</td></tr>
                <?php } ?>
            </tbody>
        </table>
    </div>

    <script>
        (function () {
            var PAPERS  = <?php echo json_encode($paperCss, $jsonFlags); ?>;
            var OFFICE  = <?php echo json_encode($office, $jsonFlags); ?>;
            var TITLE   = <?php echo json_encode($cfg['title'], $jsonFlags); ?>;
            var printed = <?php echo json_encode($printed, $jsonFlags); ?>;
            var rule    = document.getElementById('dmsPageRule');
            var size    = document.getElementById('paperSize');
            var orient  = document.getElementById('paperOrient');
            var pdfLink = document.getElementById('pdfLink');

            function cssString(text) {
                return '"' + String(text).replace(/\\/g, '\\\\').replace(/"/g, '\\"').replace(/[\r\n]+/g, ' ') + '"';
            }

            function marginBox(where, content) {
                return ' @' + where + ' { content: ' + content + '; font: 8pt Arial, Helvetica, sans-serif; color: #333; }';
            }

            // The @page rule for the selected paper: size, 0.5in margins, and the running
            // header/footer in the page-margin boxes (Chrome/Edge 131+; other browsers
            // leave them out, and page 1 still carries the full header and date).
            function apply() {
                var css = PAPERS[size.value] || PAPERS.long;
                var w = css[0], h = css[1];
                if (orient.value === 'landscape') { w = css[1]; h = css[0]; }

                document.documentElement.style.setProperty('--paper-w', w);
                document.documentElement.style.setProperty('--paper-h', h);
                rule.textContent = '@page { size: ' + w + ' ' + h + '; margin: 0.5in;' +
                    marginBox('top-left', cssString(OFFICE)) +
                    marginBox('top-right', cssString(TITLE)) +
                    marginBox('bottom-left', cssString(printed)) +
                    marginBox('bottom-right', '"Page " counter(page) " of " counter(pages)') +
                    ' }';
            }

            function remember() {
                try {
                    localStorage.setItem('dmsPrintPaper', JSON.stringify({ paper: size.value, orient: orient.value }));
                } catch (e) { /* storage unavailable: just not remembered */ }
                try {
                    var url = new URL(window.location.href);
                    url.searchParams.set('paper', size.value);
                    url.searchParams.set('orient', orient.value);
                    history.replaceState(null, '', url); // a reload keeps the choice

                    var pdf = new URL(pdfLink.href); // Download PDF follows the dropdowns
                    pdf.searchParams.set('paper', size.value);
                    pdf.searchParams.set('orient', orient.value);
                    pdfLink.href = pdf.toString();
                } catch (e) { /* older browser */ }
            }

            size.addEventListener('change', function () { apply(); remember(); });
            orient.addEventListener('change', function () { apply(); remember(); });

            // The rule is kept (this page exists only to be printed, so Ctrl+P uses the
            // same size); before each print just the "Printed:" date is refreshed.
            window.addEventListener('beforeprint', function () {
                var now = new Date();
                printed = 'Printed: ' + now.toLocaleDateString('en-US', { month: 'long', day: 'numeric', year: 'numeric' }) +
                    ' ' + now.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit' });
                apply();
            });

            apply();
        })();
<?php if (!$preview) { ?>
        window.addEventListener('load', function () {
            setTimeout(function () { window.print(); }, 250);
        });
<?php } ?>
    </script>
</body>
</html>
