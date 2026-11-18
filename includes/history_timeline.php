<?php
/**
 * History Timeline: month-grouped document cards shown on the Resolution,
 * Ordinance and Order of Business list pages as an alternative to the table.
 *
 * The page renders its rows once and passes them here as "items". Cards carry
 * data-id matching the table's <tr data-id>, so js/history-timeline.js can
 * show only the cards whose rows survive the DataTable search and the
 * report-toolbar filters.
 */

if (!function_exists('dms_history_render')) {

    /** Pill class for a resolution/ordinance remark (same map as the tables). */
    function dms_remarks_pill_class($remarks)
    {
        $map = [
            'Forwarded to LCE' => 'is-amber',
            'Signed by LCE'    => 'is-blue',
            'SP Approval'      => 'is-green',
            'Disapprove'       => 'is-red',
        ];
        return isset($map[$remarks]) ? $map[$remarks] : 'is-plain';
    }

    /** A Y-m-d date as a timestamp, or null when empty / 0000-00-00 / invalid. */
    function dms_history_timestamp($date)
    {
        $date = trim((string) $date);
        if ($date === '' || strpos($date, '0000-00-00') === 0) {
            return null;
        }
        $ts = strtotime($date);
        return $ts === false ? null : $ts;
    }

    /** The "Table | Timeline" switch (top of the list page's table card). */
    function dms_history_toggle()
    {
        ?>
        <div class="dms-view-toggle" data-ht-toggle role="group" aria-label="Choose view">
            <button type="button" data-view="table" class="is-active" aria-pressed="true"><i class="fa fa-list" aria-hidden="true"></i>&nbsp;Table</button>
            <button type="button" data-view="timeline" aria-pressed="false"><i class="fa fa-clock-o" aria-hidden="true"></i>&nbsp;Timeline</button>
        </div>
        <?php
    }

    /**
     * Print the timeline.
     *
     * @param array  $items     each: id, date, url, number, title, type,
     *                          date_label, meta ([label => value]), pill ([text, class])
     * @param string $emptyText shown when no card matches the current filters
     */
    function dms_history_render(array $items, $emptyText = 'No records match the current search or filters.')
    {
        $e = function ($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); };

        // Newest first; undated records last.
        foreach ($items as $i => $item) {
            $items[$i]['_ts'] = dms_history_timestamp(isset($item['date']) ? $item['date'] : '');
        }
        usort($items, function ($a, $b) {
            if ($a['_ts'] === null || $b['_ts'] === null) {
                if ($a['_ts'] === $b['_ts']) {
                    return (int) $b['id'] - (int) $a['id'];
                }
                return $a['_ts'] === null ? 1 : -1;
            }
            if ($a['_ts'] !== $b['_ts']) {
                return $b['_ts'] - $a['_ts'];
            }
            return (int) $b['id'] - (int) $a['id'];
        });

        $groups = [];
        foreach ($items as $item) {
            $key = $item['_ts'] === null ? 'undated' : date('Y-m', $item['_ts']);
            if (!isset($groups[$key])) {
                $groups[$key] = [
                    'label' => $item['_ts'] === null ? 'Undated' : date('F Y', $item['_ts']),
                    'items' => [],
                ];
            }
            $groups[$key]['items'][] = $item;
        }
        ?>
        <div class="dms-ht" data-history-timeline hidden>
            <?php foreach ($groups as $key => $group) { $n = count($group['items']); ?>
                <section class="dms-ht-month" data-ht-month="<?php echo $e($key); ?>">
                    <header class="dms-ht-month-head">
                        <span class="dms-ht-dot" aria-hidden="true"></span>
                        <h3><?php echo $e($group['label']); ?></h3>
                        <span class="dms-ht-count" data-ht-count><?php echo $n . ($n === 1 ? ' record' : ' records'); ?></span>
                    </header>
                    <div class="dms-ht-grid">
                        <?php foreach ($group['items'] as $item) {
                            $pill = isset($item['pill']) ? $item['pill'] : ['', 'is-plain'];
                            $dateText = $item['_ts'] === null ? 'No date' : date('M d, Y', $item['_ts']);
                        ?>
                            <a class="dms-ht-card" href="<?php echo $e($item['url']); ?>" data-id="<?php echo (int) $item['id']; ?>">
                                <span class="dms-ht-icon" aria-hidden="true"><i class="fa fa-file-text-o"></i></span>
                                <span class="dms-ht-body">
                                    <span class="dms-ht-number"><?php echo $e($item['number'] !== '' ? $item['number'] : 'No number'); ?></span>
                                    <span class="dms-ht-title"><?php echo $e($item['title']); ?></span>
                                    <span class="dms-ht-date"><i class="fa fa-calendar-o" aria-hidden="true"></i> <?php echo $e($item['date_label']); ?> <?php echo $e($dateText); ?></span>
                                    <?php if (!empty($item['meta'])) { ?>
                                        <span class="dms-ht-meta">
                                            <?php foreach ($item['meta'] as $label => $value) {
                                                if (trim((string) $value) === '') { continue; } ?>
                                                <span><b><?php echo $e($label); ?>:</b> <?php echo $e($value); ?></span>
                                            <?php } ?>
                                        </span>
                                    <?php } ?>
                                    <span class="dms-ht-foot">
                                        <span class="dms-ht-type"><?php echo $e($item['type']); ?></span>
                                        <?php if (trim((string) $pill[0]) !== '') { ?>
                                            <span class="dms-pill <?php echo $e($pill[1]); ?>"><?php echo $e($pill[0]); ?></span>
                                        <?php } ?>
                                    </span>
                                </span>
                            </a>
                        <?php } ?>
                    </div>
                </section>
            <?php } ?>
            <div class="dms-ht-empty" data-ht-empty<?php echo $groups ? ' hidden' : ''; ?>>
                <i class="fa fa-folder-open-o" aria-hidden="true"></i>
                <p><?php echo $groups ? $e($emptyText) : 'No records yet.'; ?></p>
            </div>
        </div>
        <?php
    }
}
