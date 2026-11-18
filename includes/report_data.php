<?php
/**
 * Data helpers shared by the report toolbar, the list pages and export_report.php.
 */

require_once __DIR__ . '/report_config.php';
require_once __DIR__ . '/minutes_workflow.php';

/** Returns $value if it is a real Y-m-d date, otherwise ''. */
function dms_report_valid_date($value)
{
    $value = trim((string) $value);
    $d = DateTime::createFromFormat('!Y-m-d', $value);
    return ($d && $d->format('Y-m-d') === $value) ? $value : '';
}

/** True for a usable DB date (empty dates are stored as '0000-00-00'). */
function dms_report_has_date($value)
{
    return !empty($value) && $value !== '0000-00-00' && strtotime($value) !== false;
}

/** "November 1, 2026", or '' for an empty DB date. */
function dms_report_format_date($value)
{
    return dms_report_has_date($value) ? date('F j, Y', strtotime($value)) : '';
}

/** Human description of the selected period. */
function dms_report_period_label($from, $to)
{
    if ($from !== '' && $to !== '') {
        return dms_report_format_date($from) . ' – ' . dms_report_format_date($to);
    }
    if ($from !== '') {
        return 'From ' . dms_report_format_date($from);
    }
    if ($to !== '') {
        return 'Up to ' . dms_report_format_date($to);
    }
    return 'All Records';
}

/**
 * The value a row is matched against by the status/category dropdown.
 * List pages print it in data-filter; it must agree with the SQL in
 * report_config.php so the on-screen table and the exports match.
 */
function dms_report_row_key($module, array $row)
{
    switch ($module) {
        case 'resolution':
        case 'ordinance':
            return (string) $row['remarks'];
        case 'committee':
            return (string) $row['committee_category'];
        case 'minutes':
            return dms_ob_section($row);
    }
    return '';
}

/**
 * Fetch the rows of a module's report.
 *
 * @param mysqli $conn
 * @param string $module  key of dms_report_modules()
 * @param string $from    Y-m-d or ''
 * @param string $to      Y-m-d or ''
 * @param string $filter  key of the module's filters, or '' for all
 * @return array
 */
function dms_report_fetch($conn, $module, $from, $to, $filter)
{
    $cfg = dms_report_module($module);
    if ($cfg === null) {
        return [];
    }

    $where  = [];
    $params = [];

    // Table/column names come from the config only, never from the request.
    if ($from !== '') {
        $where[]  = $cfg['dateCol'] . ' >= ?';
        $params[] = $from;
    }
    if ($to !== '') {
        $where[]  = $cfg['dateCol'] . ' <= ?';
        $params[] = $to;
    }
    if ($from !== '' || $to !== '') {
        $where[] = $cfg['dateCol'] . " <> '0000-00-00'";
    }
    if ($filter !== '' && isset($cfg['filters'][$filter])) {
        list($fragment, $fragmentParams) = $cfg['filters'][$filter];
        $where[] = '(' . $fragment . ')';
        $params  = array_merge($params, $fragmentParams);
    }

    $schedules = [];
    if ($module === 'minutes') {
        dms_ob_ensure_schema($conn);
        $schedules = dms_ob_load_schedules($conn);
    }

    $sql = 'SELECT ' . $cfg['select'] . ' FROM ' . $cfg['table']
         . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
         . ' ORDER BY ' . $cfg['orderBy'];

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException('Report query failed: ' . $conn->error);
    }
    if ($params) {
        $stmt->bind_param(str_repeat('s', count($params)), ...$params);
    }
    $stmt->execute();
    $result = $stmt->get_result();

    $rows = [];
    while ($row = $result->fetch_assoc()) {
        if ($module === 'minutes') {
            $id = (int) $row['id'];
            $row['status_full'] = dms_report_minutes_status($row, isset($schedules[$id]) ? $schedules[$id] : []);
        }
        $rows[] = $row;
    }
    $stmt->close();

    return $rows;
}

/**
 * Status text for an Order of Business item: reading, action and its details
 * (schedule, referral, approval), then any status recorded before the workflow.
 */
function dms_report_minutes_status(array $row, array $schedules = [])
{
    return implode("\n", dms_ob_text_lines($row, $schedules));
}

/** Display text of one export cell. */
function dms_report_cell(array $row, array $col)
{
    $value = isset($row[$col['key']]) ? $row[$col['key']] : '';
    if (isset($col['type']) && $col['type'] === 'date') {
        return dms_report_format_date($value);
    }
    return trim((string) $value);
}
