<?php
/**
 * Select / Select all bar for the Resolutions, Ordinances and Committee
 * Reports lists (admin and master only; js/bulk-select.js, bulk_delete.php).
 *
 * The table also needs: data-bulk-table data-bulk-type data-bulk-noun, a
 * leading <th class="dms-ob-check-col"> with dms_bulk_head_check(), and per row
 * class="dms-bulk-row" tabindex="0" plus a dms_bulk_row_check() cell.
 * Styles: .dms-ob-* in css/dms-theme.css (shared with the Order of Business list).
 */

/** The bar above the table. $plural: e.g. "resolutions". */
function dms_bulk_selectbar($plural)
{
    $p = htmlspecialchars($plural);
    ?>
    <div class="dms-ob-selectbar" data-bulk-selectbar>
        <p class="dms-ob-help" data-bulk-help-idle><i class="fa fa-check-square-o" aria-hidden="true"></i> Use Select to work on several <?php echo $p; ?> at once<span class="dms-fine-only"> (or <kbd>Ctrl</kbd>+click a row)</span>.</p>
        <p class="dms-ob-help" data-bulk-help-selecting hidden><i class="fa fa-check-square-o" aria-hidden="true"></i> <span class="dms-fine-only">Click rows to select them (Shift+click selects a range), then right-click a selected row or use Actions. Press <kbd>Esc</kbd> when done.</span><span class="dms-touch-only">Tap rows to select them, then press and hold a selected row or use Actions. Tap Done when finished.</span></p>
        <div class="dms-ob-select-actions">
            <span class="dms-ob-select-count" data-bulk-count hidden aria-live="polite"></span>
            <button type="button" class="btn btn-light btn-sm" data-bulk-select-all hidden><i class="fa fa-check-square-o" aria-hidden="true"></i>&nbsp;Select all</button>
            <button type="button" class="btn btn-primary btn-sm" data-bulk-actions hidden disabled><i class="fa fa-bars" aria-hidden="true"></i>&nbsp;Actions</button>
            <button type="button" class="btn btn-light btn-sm" data-bulk-select-toggle aria-pressed="false"><i class="fa fa-check-square-o" aria-hidden="true"></i>&nbsp;<span>Select</span></button>
        </div>
    </div>
    <?php
}

/** Header checkbox: select every record shown. */
function dms_bulk_head_check()
{
    echo '<th class="dms-ob-check-col" data-orderable="false"><input type="checkbox" class="dms-ob-check" data-bulk-check-all aria-label="Select all shown"></th>';
}

/** Row checkbox cell. $label: e.g. "resolution 001 S.2011". */
function dms_bulk_row_check($label)
{
    echo '<td class="dms-ob-check-col"><input type="checkbox" class="dms-ob-check" data-bulk-check tabindex="-1" aria-label="Select ' . htmlspecialchars($label) . '"></td>';
}
