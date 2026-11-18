<?php
/**
 * Order of Business: sections and agenda item statuses.
 *
 * One source of truth for the add, edit, view and list pages of the
 * Order of Business module (table `minutes`), its status endpoint
 * (ob_set_status.php) and its reports.
 *
 *  - Section (minutes.reading): First Reading | Second Reading | Third Reading
 *    | Other Matters. New items start in First Reading unless another section
 *    is chosen on the Add form. Items move between sections from the Add/Edit
 *    form or the list page's right-click menu (MOVE TO); moving clears the status.
 *  - Status (minutes.ob_status): blank until the user picks one. Each section
 *    has its own options (dms_ob_statuses). Only "Approved" (First or Second
 *    Reading) changes the section: the item moves to Third Reading
 *    (see 'moves' and dms_ob_status_section).
 *  - Older data is kept and shown read-only as "earlier" details: the old
 *    action (ob_action), referral, committee meeting / public hearing
 *    schedules (minutes_schedule) and the free-text status / return columns.
 *
 * See database/add_minutes_workflow.sql and database/add_ob_item_status.sql.
 */

if (!function_exists('dms_ob_sections')) {

    /** Sections in display order. */
    function dms_ob_sections()
    {
        return ['First Reading', 'Second Reading', 'Third Reading', 'Other Matters'];
    }

    /**
     * Status options per section, in display order.
     *  tone   colour (green | lime | amber | blue | red | plain), matches .dms-pill.is-*
     *  icon   Font Awesome 4 icon
     *  moves  section the item moves to when this status is set (optional)
     */
    function dms_ob_statuses()
    {
        return [
            'First Reading' => [
                'Referred'        => ['tone' => 'lime',  'icon' => 'fa-share-square-o'],
                'For Information' => ['tone' => 'blue',  'icon' => 'fa-info-circle'],
                'Approved'        => ['tone' => 'green', 'icon' => 'fa-check-circle', 'moves' => 'Third Reading'],
            ],
            'Second Reading' => [
                'Deferred'     => ['tone' => 'amber', 'icon' => 'fa-pause-circle'],
                'Returned'     => ['tone' => 'red',   'icon' => 'fa-undo'],
                'File It Away' => ['tone' => 'plain', 'icon' => 'fa-archive'],
                'For Approval' => ['tone' => 'blue',  'icon' => 'fa-hourglass-half'],
                'Approved'     => ['tone' => 'green', 'icon' => 'fa-check-circle', 'moves' => 'Third Reading'],
            ],
            'Third Reading' => [
                'Approved' => ['tone' => 'green', 'icon' => 'fa-check-circle'],
            ],
            'Other Matters' => [
                'Adopted'          => ['tone' => 'green', 'icon' => 'fa-check-square-o'],
                'Referred'         => ['tone' => 'lime',  'icon' => 'fa-share-square-o'],
                'To Be Calendared' => ['tone' => 'blue',  'icon' => 'fa-calendar-plus-o'],
            ],
        ];
    }

    /** Status options of one section ([] for an unknown section). */
    function dms_ob_section_statuses($section)
    {
        $all = dms_ob_statuses();
        return isset($all[$section]) ? $all[$section] : [];
    }

    /** Status options as {section: [{label, tone, icon}]} for the page scripts. */
    function dms_ob_statuses_for_js()
    {
        $out = [];
        foreach (dms_ob_statuses() as $section => $options) {
            $out[$section] = [];
            foreach ($options as $label => $o) {
                $out[$section][] = ['label' => $label, 'tone' => $o['tone'], 'icon' => $o['icon']];
            }
        }
        return $out;
    }

    /** A valid section (First Reading for anything unknown). */
    function dms_ob_clean_section($value)
    {
        $value = trim((string) $value);
        return in_array($value, dms_ob_sections(), true) ? $value : 'First Reading';
    }

    /** Section an item ends up in after setting $status in $section (Approved → Third Reading). */
    function dms_ob_status_section($section, $status)
    {
        $options = dms_ob_section_statuses($section);
        return isset($options[$status]['moves']) ? $options[$status]['moves'] : $section;
    }

    /** True for '' (no status) or an option of $section. */
    function dms_ob_valid_status($section, $status)
    {
        if ($status === '' || $status === null) {
            return true;
        }
        $options = dms_ob_section_statuses($section);
        return isset($options[$status]);
    }

    /** Section of a row. */
    function dms_ob_section(array $row)
    {
        return dms_ob_clean_section(isset($row['reading']) ? $row['reading'] : '');
    }

    /** Status of a row ('' when none). */
    function dms_ob_status(array $row)
    {
        $status = isset($row['ob_status']) ? trim((string) $row['ob_status']) : '';
        return dms_ob_valid_status(dms_ob_section($row), $status) ? $status : '';
    }

    /** .dms-pill modifier for a section. */
    function dms_ob_section_pill($section)
    {
        $map = [
            'First Reading'  => 'is-blue',
            'Second Reading' => 'is-amber',
            'Third Reading'  => 'is-green',
            'Other Matters'  => 'is-lime',
        ];
        return isset($map[$section]) ? $map[$section] : 'is-plain';
    }

    /** .dms-pill modifier for a status within its section. */
    function dms_ob_status_pill($section, $status)
    {
        $options = dms_ob_section_statuses($section);
        return isset($options[$status]) ? 'is-' . $options[$status]['tone'] : 'is-plain';
    }

    /** Font Awesome icon of a status within its section. */
    function dms_ob_status_icon($section, $status)
    {
        $options = dms_ob_section_statuses($section);
        return isset($options[$status]) ? $options[$status]['icon'] : 'fa-circle-o';
    }

    /** Old action labels (before the section/status model): used only to display earlier data. */
    function dms_ob_schedule_types()
    {
        return [
            'committee' => ['action' => 'For Committee Meeting', 'type' => 'Committee Meeting', 'icon' => 'fa-users',    'venue' => 'Meeting venue'],
            'hearing'   => ['action' => 'For Public Hearing',    'type' => 'Public Hearing',    'icon' => 'fa-bullhorn', 'venue' => 'Hearing venue'],
        ];
    }

    /* ------------------------------------------------------------------ *
     * Schema
     * ------------------------------------------------------------------ */

    /**
     * Adds the workflow columns / table the first time they are missing
     * (same SQL as database/add_minutes_workflow.sql and
     * database/add_ob_item_status.sql). Existing data is never changed.
     */
    function dms_ob_ensure_schema($conn)
    {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;

        try {
            $res = $conn->query("SHOW COLUMNS FROM `minutes` LIKE 'ob_action'");
            if ($res && $res->num_rows === 0) {
                $conn->query("ALTER TABLE `minutes`
                    ADD COLUMN `ob_action` varchar(50) NOT NULL DEFAULT '',
                    ADD COLUMN `reading` varchar(20) NOT NULL DEFAULT 'First Reading',
                    ADD COLUMN `referral_office` varchar(255) NOT NULL DEFAULT '',
                    ADD COLUMN `referral_notes` text DEFAULT NULL,
                    ADD COLUMN `action_updated_at` datetime DEFAULT NULL");
            }

            $res = $conn->query("SHOW COLUMNS FROM `minutes` LIKE 'ob_status'");
            if ($res && $res->num_rows === 0) {
                $conn->query("ALTER TABLE `minutes`
                    ADD COLUMN `ob_status` varchar(30) NOT NULL DEFAULT '',
                    ADD COLUMN `status_updated_at` datetime DEFAULT NULL");
            }

            // Items approved before the Approved → Third Reading rule.
            $conn->query("UPDATE `minutes` SET `reading` = 'Third Reading'
                          WHERE `ob_status` = 'Approved' AND `reading` IN ('First Reading', 'Second Reading')");

            $res = $conn->query("SHOW TABLES LIKE 'minutes_schedule'");
            if ($res && $res->num_rows === 0) {
                $columns = "`id` int(11) NOT NULL AUTO_INCREMENT,
                    `minutes_id` int(255) NOT NULL,
                    `schedule_type` varchar(30) NOT NULL,
                    `sched_date` date DEFAULT NULL,
                    `sched_time` time DEFAULT NULL,
                    `venue` varchar(255) NOT NULL DEFAULT '',
                    `invitees` text DEFAULT NULL,
                    `notes` text DEFAULT NULL,
                    `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `uq_minutes_type` (`minutes_id`, `schedule_type`)";
                $tail = ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";
                $fk   = ", CONSTRAINT `fk_minutes_schedule_minutes` FOREIGN KEY (`minutes_id`) REFERENCES `minutes` (`id`) ON DELETE CASCADE";

                $ok = false;
                try {
                    $ok = $conn->query("CREATE TABLE IF NOT EXISTS `minutes_schedule` (" . $columns . $fk . $tail);
                } catch (Exception $e) {
                    $ok = false;
                }
                if (!$ok) {
                    // Without the foreign key if the server refuses it.
                    $conn->query("CREATE TABLE IF NOT EXISTS `minutes_schedule` (" . $columns . $tail);
                }
            }
        } catch (Exception $e) {
            error_log('Order of Business schema: ' . $e->getMessage());
        }
    }

    /* ------------------------------------------------------------------ *
     * Input + saving
     * ------------------------------------------------------------------ */

    /** A real Y-m-d date, or ''. */
    function dms_ob_clean_date($value)
    {
        $value = trim((string) $value);
        $d = DateTime::createFromFormat('!Y-m-d', $value);
        return ($d && $d->format('Y-m-d') === $value) ? $value : '';
    }

    /** HH:MM (or HH:MM:SS) as HH:MM:SS, or ''. */
    function dms_ob_clean_time($value)
    {
        $value = trim((string) $value);
        if (!preg_match('/^([01]\d|2[0-3]):([0-5]\d)(?::([0-5]\d))?$/', $value, $m)) {
            return '';
        }
        return $m[1] . ':' . $m[2] . ':' . (isset($m[3]) ? $m[3] : '00');
    }

    /** Trimmed non-empty lines of a textarea, joined by "\n". */
    function dms_ob_clean_lines($value)
    {
        $lines = preg_split('/\r\n|\r|\n/', (string) $value);
        $out = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line !== '') {
                $out[] = $line;
            }
        }
        return implode("\n", $out);
    }

    /**
     * Normalise one item's posted fields:
     *  [section, status, resolutionNo, resolutionDate]
     * The status is dropped when it is not an option of the section.
     */
    function dms_ob_item_input($src)
    {
        $src = is_array($src) ? $src : [];
        $str = function ($key) use ($src) {
            return (isset($src[$key]) && !is_array($src[$key])) ? trim((string) $src[$key]) : '';
        };

        $section = dms_ob_clean_section($str('section'));
        $status  = $str('status');
        if (!dms_ob_valid_status($section, $status)) {
            $status = '';
        }
        return [
            'section'        => $section,
            'status'         => $status,
            'resolutionNo'   => $str('resolutionNo'),
            'resolutionDate' => dms_ob_clean_date($str('resolutionDate')),
        ];
    }

    /**
     * Write an item's section and status in place (no new rows).
     * status_updated_at is set when the status changes.
     */
    function dms_ob_update_state($conn, $id, $section, $status, $statusChanged)
    {
        $id   = (int) $id;
        $sql  = "UPDATE `minutes` SET `reading` = ?, `ob_status` = ?"
              . ($statusChanged ? ", `status_updated_at` = NOW()" : "")
              . " WHERE `id` = ?";
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param('ssi', $section, $status, $id);
        $ok = $stmt->execute();
        $stmt->close();
        return $ok;
    }

    /**
     * Save an item's section, status and (optionally) resolution details.
     *
     * @param array $in   dms_ob_item_input()
     * @param array $old  the stored row (reading, ob_status), [] for a new item
     * @param bool  $withResolution also write resolutionNo / resolutionDate
     * @return array [section, status, section_changed, status_changed]
     */
    function dms_ob_save_item($conn, $id, array $in, array $old, $withResolution = true)
    {
        $oldSection = $old ? dms_ob_section($old) : '';
        $oldStatus  = $old ? dms_ob_status($old) : '';
        $section    = dms_ob_clean_section($in['section']);
        $status     = dms_ob_valid_status($section, $in['status']) ? (string) $in['status'] : '';
        $section    = dms_ob_status_section($section, $status); // Approved → Third Reading
        $statusChanged = ($status !== $oldStatus);

        dms_ob_update_state($conn, $id, $section, $status, $statusChanged);

        if ($withResolution) {
            $resDate = $in['resolutionDate'] !== '' ? $in['resolutionDate'] : '0000-00-00';
            $stmt = $conn->prepare("UPDATE `minutes` SET `resolutionNo` = ?, `resolutionDate` = ? WHERE `id` = ?");
            if ($stmt) {
                $id = (int) $id;
                $stmt->bind_param('ssi', $in['resolutionNo'], $resDate, $id);
                $stmt->execute();
                $stmt->close();
            }
        }

        return [
            'section'         => $section,
            'status'          => $status,
            'section_changed' => ($oldSection !== '' && $section !== $oldSection),
            'status_changed'  => $statusChanged,
        ];
    }

    /** One history_log entry for an Order of Business item. */
    function dms_ob_log_history($conn, $id, $title, $logAction, $section, $status)
    {
        $id = (int) $id;
        $logStatus = dms_ob_history_status($status, $section);
        $stmt = $conn->prepare("INSERT INTO history_log (action, file_type, file_id, title, status) VALUES (?, 'Minutes', ?, ?, ?)");
        if ($stmt) {
            $stmt->bind_param('siss', $logAction, $id, $title, $logStatus);
            $stmt->execute();
            $stmt->close();
        }
    }

    /** History action text for a save result ('' when nothing changed). */
    function dms_ob_change_label(array $result)
    {
        // A new status first: "Status: Approved" also covers its move to Third Reading.
        if (!empty($result['status_changed']) && $result['status'] !== '') {
            return 'Status: ' . $result['status'];
        }
        if (!empty($result['section_changed'])) {
            return 'Moved to ' . $result['section'];
        }
        if (!empty($result['status_changed'])) {
            return 'Status cleared';
        }
        return '';
    }

    /** Status text written to history_log for an item. */
    function dms_ob_history_status($status, $section)
    {
        $section = dms_ob_clean_section($section);
        return $status !== '' ? $status . ' (' . $section . ')' : $section;
    }

    /* ------------------------------------------------------------------ *
     * Loading
     * ------------------------------------------------------------------ */

    /**
     * Earlier committee meeting / public hearing details keyed by minutes id,
     * then by key (committee | hearing). Pass null to load every schedule.
     */
    function dms_ob_load_schedules($conn, $ids = null)
    {
        $byType = [];
        foreach (dms_ob_schedule_types() as $key => $type) {
            $byType[$type['type']] = $key;
        }

        $sql = "SELECT * FROM `minutes_schedule`";
        if (is_array($ids)) {
            $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
            if (!$ids) {
                return [];
            }
            $sql .= " WHERE `minutes_id` IN (" . implode(',', $ids) . ")"; // integers only
        }

        $out = [];
        try {
            $res = $conn->query($sql);
            if ($res) {
                while ($r = $res->fetch_assoc()) {
                    if (isset($byType[$r['schedule_type']])) {
                        $out[(int) $r['minutes_id']][$byType[$r['schedule_type']]] = $r;
                    }
                }
            }
        } catch (Exception $e) {
            error_log('Order of Business schedules: ' . $e->getMessage());
        }
        return $out;
    }

    /* ------------------------------------------------------------------ *
     * Formatting
     * ------------------------------------------------------------------ */

    function dms_ob_e($value)
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }

    /** "March 3, 2026", or '' for an empty date. */
    function dms_ob_fmt_date($value)
    {
        $value = dms_ob_clean_date(substr((string) $value, 0, 10));
        return $value !== '' ? date('F j, Y', strtotime($value)) : '';
    }

    /** "9:30 AM", or '' for an empty time. */
    function dms_ob_fmt_time($value)
    {
        $value = dms_ob_clean_time(substr((string) $value, 0, 8));
        return $value !== '' ? date('g:i A', strtotime('2000-01-01 ' . $value)) : '';
    }

    /** "March 3, 2026 at 9:30 AM", or '' for an empty datetime. */
    function dms_ob_fmt_datetime($value)
    {
        $ts = !empty($value) ? strtotime((string) $value) : false;
        return ($ts && (int) date('Y', $ts) > 1970) ? date('F j, Y \a\t g:i A', $ts) : '';
    }

    /** Non-empty lines of a stored multi-line value. */
    function dms_ob_lines($value)
    {
        $value = dms_ob_clean_lines($value);
        return $value === '' ? [] : explode("\n", $value);
    }

    /** True when a schedule row has any detail. */
    function dms_ob_schedule_has_data($s)
    {
        if (!is_array($s)) {
            return false;
        }
        if (dms_ob_fmt_date(isset($s['sched_date']) ? $s['sched_date'] : '') !== '') {
            return true;
        }
        if (dms_ob_fmt_time(isset($s['sched_time']) ? $s['sched_time'] : '') !== '') {
            return true;
        }
        foreach (['venue', 'invitees', 'notes'] as $k) {
            if (trim((string) (isset($s[$k]) ? $s[$k] : '')) !== '') {
                return true;
            }
        }
        return false;
    }

    /** "March 3, 2026 · 9:30 AM · Session Hall" for one schedule. */
    function dms_ob_schedule_when($s)
    {
        $parts = array_filter([
            dms_ob_fmt_date(isset($s['sched_date']) ? $s['sched_date'] : ''),
            dms_ob_fmt_time(isset($s['sched_time']) ? $s['sched_time'] : ''),
            trim((string) (isset($s['venue']) ? $s['venue'] : '')),
        ], 'strlen');
        return implode(' · ', $parts);
    }

    /** Trimmed string value of a row column. */
    function dms_ob_val(array $row, $key)
    {
        return isset($row[$key]) ? trim((string) $row[$key]) : '';
    }

    /** True when a row carries old free-text status / return details. */
    function dms_ob_has_legacy(array $row)
    {
        return dms_ob_val($row, 'status') !== ''
            || dms_ob_val($row, 'returnNo') !== ''
            || dms_ob_fmt_date(dms_ob_val($row, 'returnDate')) !== '';
    }

    /** True when a row has any earlier details (old action, schedules, referral, old status). */
    function dms_ob_has_earlier(array $row, array $sched)
    {
        if (dms_ob_val($row, 'ob_action') !== '' || dms_ob_val($row, 'referral_office') !== ''
            || dms_ob_val($row, 'referral_notes') !== '' || dms_ob_has_legacy($row)) {
            return true;
        }
        foreach ($sched as $s) {
            if (dms_ob_schedule_has_data($s)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Plain-text summary lines (reports, timeline cards).
     * @param array $sched this item's earlier schedules ([committee|hearing => row])
     */
    function dms_ob_text_lines(array $row, array $sched, $withLegacy = true)
    {
        $status  = dms_ob_status($row);
        $lines   = [dms_ob_section($row)];
        $lines[] = 'Status: ' . ($status !== '' ? $status : '—');

        if (dms_ob_val($row, 'resolutionNo') !== '') {
            $lines[] = 'Resolution No: ' . dms_ob_val($row, 'resolutionNo');
        }
        $d = dms_ob_fmt_date(dms_ob_val($row, 'resolutionDate'));
        if ($d !== '') {
            $lines[] = 'Date Approved: ' . $d;
        }

        if ($withLegacy) {
            if (dms_ob_val($row, 'ob_action') !== '') {
                $lines[] = 'Earlier action: ' . dms_ob_val($row, 'ob_action');
            }
            if (dms_ob_val($row, 'status') !== '') {
                $lines[] = 'Previous status: ' . dms_ob_val($row, 'status');
            }
            if (dms_ob_val($row, 'returnNo') !== '') {
                $lines[] = 'Return No: ' . dms_ob_val($row, 'returnNo');
            }
        }
        return $lines;
    }

    /* ------------------------------------------------------------------ *
     * Rendering
     * ------------------------------------------------------------------ */

    /** Section <select> (Add / Edit forms). */
    function dms_ob_render_section_field($name, $id, $current = 'First Reading')
    {
        $e = 'dms_ob_e';
        $current = dms_ob_clean_section($current);
        ?>
        <select class="form-control" id="<?php echo $e($id); ?>" name="<?php echo $e($name); ?>" data-ob-section>
            <?php foreach (dms_ob_sections() as $section) { ?>
                <option value="<?php echo $e($section); ?>"<?php echo $section === $current ? ' selected' : ''; ?>><?php echo $e($section); ?></option>
            <?php } ?>
        </select>
        <?php
    }

    /**
     * Status <select> (Edit form). Its options follow the section <select>
     * through data-ob-status-options (see editmeetingminutes.php).
     */
    function dms_ob_render_status_field($name, $id, $section, $current = '')
    {
        $e = 'dms_ob_e';
        $section = dms_ob_clean_section($section);
        $options = [];
        foreach (dms_ob_statuses() as $s => $list) {
            $options[$s] = array_keys($list);
        }
        ?>
        <select class="form-control" id="<?php echo $e($id); ?>" name="<?php echo $e($name); ?>" data-ob-status
                data-ob-status-options="<?php echo $e(json_encode($options)); ?>">
            <option value="">— No status —</option>
            <?php foreach (dms_ob_section_statuses($section) as $label => $o) { ?>
                <option value="<?php echo $e($label); ?>"<?php echo $label === $current ? ' selected' : ''; ?>><?php echo $e($label); ?></option>
            <?php } ?>
        </select>
        <?php
    }

    /** One labelled value in a summary block. */
    function dms_ob_summary_item($label, $value, $raw = false)
    {
        if (trim(strip_tags((string) $value)) === '') {
            return;
        }
        echo '<div class="dms-ob-kv"><dt>' . dms_ob_e($label) . '</dt><dd>'
            . ($raw ? $value : nl2br(dms_ob_e($value))) . '</dd></div>';
    }

    /** Earlier details (old action, schedules, referral, old status), read-only. */
    function dms_ob_render_earlier(array $row, array $sched)
    {
        $e      = 'dms_ob_e';
        $action = dms_ob_val($row, 'ob_action');

        if ($action !== '') {
            $when = dms_ob_fmt_datetime(isset($row['action_updated_at']) ? $row['action_updated_at'] : '');
        ?>
            <div class="dms-ob-block">
                <div class="dms-ob-block-head"><i class="fa fa-history" aria-hidden="true"></i> Earlier action <span class="dms-ob-tag">Before status update</span></div>
                <dl>
                    <?php dms_ob_summary_item('Action', $action); ?>
                    <?php dms_ob_summary_item('Set on', $when); ?>
                </dl>
            </div>
        <?php }

        foreach (dms_ob_schedule_types() as $key => $type) {
            if (!isset($sched[$key]) || !dms_ob_schedule_has_data($sched[$key])) { continue; }
            $s = $sched[$key];
            $invitees = dms_ob_lines($s['invitees']);
            $list = '';
            if ($invitees) {
                $list = '<ul class="dms-ob-invitees">';
                foreach ($invitees as $person) { $list .= '<li>' . $e($person) . '</li>'; }
                $list .= '</ul>';
            }
        ?>
            <div class="dms-ob-block">
                <div class="dms-ob-block-head">
                    <i class="fa <?php echo $e($type['icon']); ?>" aria-hidden="true"></i> <?php echo $e($type['type']); ?>
                    <span class="dms-ob-tag">Earlier</span>
                </div>
                <dl>
                    <?php dms_ob_summary_item('Date', dms_ob_fmt_date($s['sched_date'])); ?>
                    <?php dms_ob_summary_item('Time', dms_ob_fmt_time($s['sched_time'])); ?>
                    <?php dms_ob_summary_item($type['venue'], trim((string) $s['venue'])); ?>
                    <?php dms_ob_summary_item('Invited (' . count($invitees) . ')', $list, true); ?>
                    <?php dms_ob_summary_item('Notes / agenda', trim((string) $s['notes'])); ?>
                </dl>
            </div>
        <?php }

        $refOffice = dms_ob_val($row, 'referral_office');
        $refNotes  = dms_ob_val($row, 'referral_notes');
        if ($refOffice !== '' || $refNotes !== '') { ?>
            <div class="dms-ob-block">
                <div class="dms-ob-block-head">
                    <i class="fa fa-share-square-o" aria-hidden="true"></i> Referred to concerned offices
                    <span class="dms-ob-tag">Earlier</span>
                </div>
                <dl>
                    <?php dms_ob_summary_item('Assigned office', $refOffice); ?>
                    <?php dms_ob_summary_item('Instructions / status / remarks', $refNotes); ?>
                </dl>
            </div>
        <?php }

        if (dms_ob_has_legacy($row)) { ?>
            <div class="dms-ob-block">
                <div class="dms-ob-block-head"><i class="fa fa-history" aria-hidden="true"></i> Previous status <span class="dms-ob-tag">Before the reading workflow</span></div>
                <dl>
                    <?php dms_ob_summary_item('Status', dms_ob_val($row, 'status')); ?>
                    <?php dms_ob_summary_item('Return No.', dms_ob_val($row, 'returnNo')); ?>
                    <?php dms_ob_summary_item('Return date', dms_ob_fmt_date(dms_ob_val($row, 'returnDate'))); ?>
                </dl>
            </div>
        <?php }
    }

    /** Read-only section / status block (view page, list details). */
    function dms_ob_render_summary(array $row, array $sched)
    {
        $e       = 'dms_ob_e';
        $section = dms_ob_section($row);
        $status  = dms_ob_status($row);
        $updated = dms_ob_fmt_datetime(isset($row['status_updated_at']) ? $row['status_updated_at'] : '');
        $resNo   = dms_ob_val($row, 'resolutionNo');
        $resDate = dms_ob_fmt_date(dms_ob_val($row, 'resolutionDate'));
        ?>
        <div class="dms-ob-summary">
            <div class="dms-ob-summary-head">
                <span class="dms-pill <?php echo $e(dms_ob_section_pill($section)); ?>"><?php echo $e($section); ?></span>
                <?php if ($status !== '') { ?>
                    <span class="dms-pill <?php echo $e(dms_ob_status_pill($section, $status)); ?>"><i class="fa <?php echo $e(dms_ob_status_icon($section, $status)); ?>" aria-hidden="true"></i> <?php echo $e($status); ?></span>
                    <?php if ($updated !== '') { ?>
                        <span class="dms-ob-muted">Status set <?php echo $e($updated); ?></span>
                    <?php } ?>
                <?php } else { ?>
                    <span class="dms-ob-muted">No status set</span>
                <?php } ?>
            </div>

            <?php if ($resNo !== '' || $resDate !== '') { ?>
                <div class="dms-ob-block is-green">
                    <div class="dms-ob-block-head"><i class="fa fa-check-circle" aria-hidden="true"></i> Resolution / Ordinance</div>
                    <dl>
                        <?php dms_ob_summary_item('Resolution / Ordinance No.', $resNo); ?>
                        <?php dms_ob_summary_item('Date approved', $resDate); ?>
                    </dl>
                </div>
            <?php } ?>

            <?php dms_ob_render_earlier($row, $sched); ?>
        </div>
        <?php
    }

    /** dms_ob_render_summary() as a string. */
    function dms_ob_summary_html(array $row, array $sched)
    {
        ob_start();
        dms_ob_render_summary($row, $sched);
        return dms_ob_squeeze(ob_get_clean());
    }

    /** Compact section / status cell for the list table. */
    function dms_ob_render_compact(array $row, array $sched = [])
    {
        $e       = 'dms_ob_e';
        $section = dms_ob_section($row);
        $status  = dms_ob_status($row);
        $lines   = [];

        if (dms_ob_val($row, 'resolutionNo') !== '') {
            $lines[] = $e(dms_ob_val($row, 'resolutionNo'));
        }
        $d = dms_ob_fmt_date(dms_ob_val($row, 'resolutionDate'));
        if ($d !== '') {
            $lines[] = 'Approved ' . $e($d);
        }
        if (dms_ob_val($row, 'ob_action') !== '') {
            $lines[] = '<span class="dms-ob-muted">Earlier action: ' . $e(dms_ob_val($row, 'ob_action')) . '</span>';
        }
        if (dms_ob_val($row, 'status') !== '') {
            $lines[] = '<span class="dms-ob-muted">' . nl2br($e(dms_ob_val($row, 'status'))) . '</span>';
        }
        if (dms_ob_val($row, 'returnNo') !== '') {
            $lines[] = '<span class="dms-ob-muted">Return No: ' . $e(dms_ob_val($row, 'returnNo')) . '</span>';
        }
        ?>
        <div class="dms-ob-cell">
            <?php if ($status !== '') { // the section is shown by the tabs, not here; blank until a status is set ?>
                <div class="dms-ob-cell-pills">
                    <span class="dms-pill <?php echo $e(dms_ob_status_pill($section, $status)); ?>"><i class="fa <?php echo $e(dms_ob_status_icon($section, $status)); ?>" aria-hidden="true"></i> <?php echo $e($status); ?></span>
                </div>
            <?php } ?>
            <?php foreach ($lines as $line) { ?>
                <div class="dms-ob-cell-line"><?php echo $line; ?></div>
            <?php } ?>
        </div>
        <?php
    }

    /** dms_ob_render_compact() as a string. */
    function dms_ob_compact_html(array $row, array $sched = [])
    {
        ob_start();
        dms_ob_render_compact($row, $sched);
        return dms_ob_squeeze(ob_get_clean());
    }

    /** Template indentation between tags collapsed to one space (smaller JSON payloads). */
    function dms_ob_squeeze($html)
    {
        return trim(preg_replace('/>\s+</', '> <', (string) $html));
    }

    /** An attachment column as a file path, or '' when empty or not a readable path. */
    function dms_ob_file_path(array $row, $key)
    {
        $value = isset($row[$key]) ? trim((string) $row[$key]) : '';
        if ($value === '' || strlen($value) > 1000 || !mb_check_encoding($value, 'UTF-8')) {
            return '';
        }
        return $value;
    }

    /**
     * Details for the list page's left-click view (one entry per item).
     * Strings only; the page prints them as JSON.
     */
    function dms_ob_item_details(array $row, array $sched)
    {
        $section = dms_ob_section($row);
        return [
            'id'            => (int) $row['id'],
            'no_regSession' => (string) $row['no_regSession'],
            'date'          => dms_ob_fmt_date($row['date']) !== '' ? dms_ob_fmt_date($row['date']) : (string) $row['date'],
            'resNo'         => (string) $row['resNo'],
            'title'         => htmlspecialchars_decode((string) $row['title']), // as on the view page
            'genAttachment' => dms_ob_file_path($row, 'genAttachment'),
            'attachment'    => dms_ob_file_path($row, 'attachment'),
            'genAttachment_stored' => !empty($row['genAttachment_stored']),
            'attachment_stored'    => !empty($row['attachment_stored']),
            'section'       => $section,
            'status'        => dms_ob_status($row),
            'summaryHtml'   => dms_ob_summary_html($row, $sched),
        ];
    }

    /** Item counts per section. */
    function dms_ob_section_counts($conn)
    {
        $counts = array_fill_keys(dms_ob_sections(), 0);
        $res = $conn->query("SELECT reading FROM `minutes`");
        if ($res) {
            while ($r = $res->fetch_assoc()) {
                $counts[dms_ob_section($r)]++;
            }
        }
        return $counts;
    }
}
