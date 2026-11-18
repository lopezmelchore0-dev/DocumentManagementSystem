<!DOCTYPE html>
<html lang="en">

<?php include "header.php"; ?>


<body>
    <!--*******************
        Preloader start
    ********************-->
    <?php include"loadingscreen.php" ?>
    <!--*******************
        Preloader end
    ********************-->

    <!-- Main Wrapper Start -->
    <div id="main-wrapper">

        <?php
            include "sidebar.php";

            // Fetch counts from tables
            $resolution_count = $conn->query("SELECT COUNT(*) as count FROM resolution")->fetch_assoc()['count'];
            $ordinance_count = $conn->query("SELECT COUNT(*) as count FROM ordinance")->fetch_assoc()['count'];
            $minutes_count = $conn->query("SELECT COUNT(*) as count FROM minutes")->fetch_assoc()['count'];

            // Fetch role from session
            $role = isset($_SESSION['role']) ? $_SESSION['role'] : 'user';

            // ---- Read-only dashboard statistics (SELECT only) ----------------------------
            // Runs a SELECT and returns all rows, or null if the query fails (e.g. a missing table).
            $dms_select = function ($sql) use ($conn) {
                try {
                    $res = $conn->query($sql);
                    if ($res === false) { return null; }
                    $rows = [];
                    while ($r = $res->fetch_assoc()) { $rows[] = $r; }
                    return $rows;
                } catch (Throwable $e) {
                    return null;
                }
            };

            // Committee reports (table may not exist on older databases)
            $committee_rows = $dms_select("SELECT COUNT(*) AS count FROM committee_reports");
            $committee_count = $committee_rows !== null ? (int)$committee_rows[0]['count'] : null;

            // Document types: table => date column used for "this month" and the chart
            $dms_types = [
                'resolution' => ['table' => 'resolution',        'col' => 'd_adopted',    'label' => 'Resolutions'],
                'ordinance'  => ['table' => 'ordinance',         'col' => 'date_adopted', 'label' => 'Ordinances'],
                'minutes'    => ['table' => 'minutes',           'col' => 'date',         'label' => 'Order of Business'],
                'committee'  => ['table' => 'committee_reports', 'col' => 'date_report',  'label' => 'Committee Reports'],
            ];

            // "This month" counts for the KPI cards
            $this_month = [];
            foreach ($dms_types as $key => $t) {
                $rows = $dms_select("SELECT COUNT(*) AS count FROM `{$t['table']}`
                                     WHERE YEAR(`{$t['col']}`) = YEAR(CURDATE()) AND MONTH(`{$t['col']}`) = MONTH(CURDATE())");
                $this_month[$key] = $rows !== null ? (int)$rows[0]['count'] : 0;
            }

            // ---- Report timelines: latest records per type (one tab each) -------------
            require_once "includes/history_timeline.php"; // dms_remarks_pill_class(), dms_history_timestamp()
            require_once "includes/minutes_workflow.php"; // dms_ob_section(), dms_ob_section_pill()
            try { dms_ob_ensure_schema($conn); } catch (Throwable $e) { /* timeline falls back to no pill */ }

            $rt_limit = 8;
            $timelines = [
                'resolution' => [
                    'label' => 'Resolutions', 'color' => 'var(--dash-res)', 'count' => (int)$resolution_count,
                    'list'  => 'files-resolution.php', 'date_label' => 'Adopted', 'items' => [],
                    'rows'  => $dms_select("SELECT id, reso_no, title, d_adopted, remarks FROM resolution
                                            ORDER BY d_adopted DESC, id DESC LIMIT $rt_limit"),
                ],
                'ordinance' => [
                    'label' => 'Ordinances', 'color' => 'var(--dash-ord)', 'count' => (int)$ordinance_count,
                    'list'  => 'files-ordinances.php', 'date_label' => 'Adopted', 'items' => [],
                    'rows'  => $dms_select("SELECT id, mo_no, title, date_adopted, remarks FROM ordinance
                                            ORDER BY date_adopted DESC, id DESC LIMIT $rt_limit"),
                ],
                'minutes' => [
                    'label' => 'Order of Business', 'color' => 'var(--dash-ob)', 'count' => (int)$minutes_count,
                    'list'  => 'files-meetingminutes.php', 'date_label' => 'Session', 'items' => [],
                    'rows'  => $dms_select("SELECT * FROM minutes ORDER BY date DESC, id DESC LIMIT $rt_limit"),
                ],
                'committee' => [
                    'label' => 'Committee Reports', 'color' => 'var(--dash-cr)', 'count' => $committee_count,
                    'list'  => 'files-committee-report.php', 'date_label' => 'Reported', 'items' => [],
                    'rows'  => $dms_select("SELECT id, title, committee_category, councilor, date_report FROM committee_reports
                                            ORDER BY date_report DESC, id DESC LIMIT $rt_limit"),
                ],
            ];
            foreach ($timelines as $key => &$tl) {
                foreach ((array)$tl['rows'] as $r) {
                    $item = ['id' => (int)$r['id'], 'title' => trim((string)$r['title']), 'pill' => ['', 'is-plain'], 'sub' => ''];
                    if ($key === 'resolution') {
                        $item += ['number' => $r['reso_no'], 'date' => $r['d_adopted'], 'url' => 'viewresolution.php?id='];
                        $item['pill'] = [trim((string)$r['remarks']), dms_remarks_pill_class($r['remarks'])];
                    } elseif ($key === 'ordinance') {
                        $item += ['number' => $r['mo_no'], 'date' => $r['date_adopted'], 'url' => 'viewordinance.php?id='];
                        $item['pill'] = [trim((string)$r['remarks']), dms_remarks_pill_class($r['remarks'])];
                    } elseif ($key === 'minutes') {
                        $number = trim((string)$r['no_regSession']);
                        if (trim((string)$r['resNo']) !== '') {
                            $number .= ($number !== '' ? ' · ' : '') . 'Item ' . trim((string)$r['resNo']);
                        }
                        $item += ['number' => $number, 'date' => $r['date'], 'url' => 'viewmeetingminutes.php?id='];
                        if (function_exists('dms_ob_section') && array_key_exists('reading', $r)) {
                            $section = dms_ob_section($r);
                            $item['pill'] = [$section, dms_ob_section_pill($section)];
                        }
                    } else {
                        $item += ['number' => $r['committee_category'], 'date' => $r['date_report'], 'url' => 'viewCommitteeReports.php?id='];
                        $item['sub'] = trim((string)$r['councilor']);
                    }
                    // "Choose..." is the unselected dropdown value: no remarks yet
                    if ($item['pill'][0] === 'Choose...') { $item['pill'][0] = ''; }
                    $item['url'] .= $item['id'];
                    $ts = dms_history_timestamp($item['date']);
                    $item['date_text'] = $ts === null ? 'No date' : date('M d, Y', $ts);
                    $tl['items'][] = $item;
                }
                unset($tl['rows']);
            }
            unset($tl);


            // KPI cards: [label, count, this-month count, delta wording, colour token]
            $kpis = [
                ['Resolutions',       (int)$resolution_count, $this_month['resolution'], 'adopted this month',  'var(--dash-res)'],
                ['Ordinances',        (int)$ordinance_count,  $this_month['ordinance'],  'adopted this month',  'var(--dash-ord)'],
                ['Order of Business', (int)$minutes_count,    $this_month['minutes'],    'this month',          'var(--dash-ob)'],
                ['Committee Reports', $committee_count,       $this_month['committee'],  'reported this month', 'var(--dash-cr)'],
            ];
        ?>

        <!-- Content Body Start -->
        <div class="content-body dms-dash-bg">
            <div class="container-fluid">

                <!-- Row: one card per document type ("New …" shortcuts live in the top bar, sidebar.php) -->
                <div class="row dms-kpi-row">
                    <?php foreach ($kpis as $k) { ?>
                        <div class="col-xl-3 col-6">
                            <div class="card dms-kpi-card">
                                <div class="card-body">
                                    <div class="dms-kpi-label"><i class="dms-dot" style="background: <?php echo $k[4]; ?>;"></i><?php echo htmlspecialchars($k[0]); ?></div>
                                    <div class="dms-kpi-value"><?php echo $k[1] === null ? '—' : number_format($k[1]); ?></div>
                                    <div class="dms-kpi-delta">+<?php echo (int)$k[2]; ?> <?php echo htmlspecialchars($k[3]); ?></div>
                                </div>
                            </div>
                        </div>
                    <?php } ?>
                </div>

                <!-- Row: report timelines (left) + mini organizational chart (right, includes/org_chart.php).
                     Side by side from xl up; stacked below that. -->
                <?php
                    $h = function ($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); };
                    require_once "includes/org_chart.php";
                    dms_org_ensure_schema($conn);
                    $org = dms_org_load($conn);
                ?>
                <div class="row">
                    <div class="col-xl-6 d-flex">
                        <div class="card dms-dash-card w-100">
                            <div class="card-body">
                                <div class="dms-card-head">
                                    <div>
                                        <h4 class="dms-card-title">Report timelines</h4>
                                        <p class="dms-card-sub">Latest records by type</p>
                                    </div>
                                </div>
                                <div class="dms-ob-tabs dms-tl-tabs" role="tablist" aria-label="Report type" data-rt-tabs>
                                    <?php $first = true; foreach ($timelines as $key => $tl) { ?>
                                        <button type="button" role="tab" id="rt-tab-<?php echo $key; ?>" aria-controls="rt-panel-<?php echo $key; ?>"
                                                data-rt-tab="<?php echo $key; ?>" class="<?php echo $first ? 'is-active' : ''; ?>"
                                                aria-selected="<?php echo $first ? 'true' : 'false'; ?>" tabindex="<?php echo $first ? '0' : '-1'; ?>">
                                            <i class="dms-tl-swatch" style="background: <?php echo $tl['color']; ?>;" aria-hidden="true"></i>
                                            <?php echo $h($tl['label']); ?>
                                            <span class="dms-ob-count"><?php echo $tl['count'] === null ? '—' : number_format($tl['count']); ?></span>
                                        </button>
                                    <?php $first = false; } ?>
                                </div>
                                <?php $first = true; foreach ($timelines as $key => $tl) { ?>
                                    <div class="dms-tl-panel" role="tabpanel" id="rt-panel-<?php echo $key; ?>" aria-labelledby="rt-tab-<?php echo $key; ?>"
                                         data-rt-panel="<?php echo $key; ?>" style="--rt-color: <?php echo $tl['color']; ?>;"<?php echo $first ? '' : ' hidden'; ?>>
                                        <?php if ($tl['items']) { ?>
                                            <ol class="dms-tl">
                                                <?php foreach ($tl['items'] as $it) { ?>
                                                    <li class="dms-tl-item">
                                                        <a class="dms-tl-link" href="<?php echo $h($it['url']); ?>">
                                                            <span class="dms-tl-date"><?php echo $h($tl['date_label'] . ' ' . $it['date_text']); ?></span>
                                                            <span class="dms-tl-main">
                                                                <span class="dms-tl-number"><?php echo $h(trim((string)$it['number']) !== '' ? $it['number'] : 'No number'); ?></span>
                                                                <span class="dms-tl-title"><?php echo $h($it['title'] !== '' ? $it['title'] : 'Untitled'); ?></span>
                                                                <?php if ($it['sub'] !== '') { ?>
                                                                    <span class="dms-tl-sub"><?php echo $h($it['sub']); ?></span>
                                                                <?php } ?>
                                                            </span>
                                                            <?php if ($it['pill'][0] !== '') { ?>
                                                                <span class="dms-pill <?php echo $h($it['pill'][1]); ?>"><?php echo $h($it['pill'][0]); ?></span>
                                                            <?php } ?>
                                                        </a>
                                                    </li>
                                                <?php } ?>
                                            </ol>
                                        <?php } else { ?>
                                            <div class="dms-empty"><i class="ti-time"></i>No <?php echo $h(strtolower($tl['label'])); ?> yet.</div>
                                        <?php } ?>
                                        <div class="dms-tl-foot">
                                            <a href="./<?php echo $tl['list']; ?>" class="dms-link">View all <?php echo $h(strtolower($tl['label'])); ?> <i class="ti-angle-right"></i></a>
                                        </div>
                                    </div>
                                <?php $first = false; } ?>
                            </div>
                        </div>
                    </div>

                    <div class="col-xl-6 d-flex">
                        <div class="card dms-dash-card w-100">
                            <div class="card-body">
                                <div class="dms-card-head">
                                    <div>
                                        <h4 class="dms-card-title">Organizational chart</h4>
                                        <p class="dms-card-sub"><?php echo $h($org['title']); ?><?php echo $org['count'] ? ' · ' . number_format($org['count']) . ' official' . ($org['count'] === 1 ? '' : 's') : ''; ?></p>
                                    </div>
                                    <a href="./files-officials.php" class="dms-chip">View full chart <i class="ti-angle-right"></i></a>
                                </div>
                                <?php if ($org['count']) { ?>
                                    <div class="dms-org-mini-wrap">
                                        <?php echo dms_org_render($org, ['mini' => true, 'link' => 'files-officials.php']); ?>
                                    </div>
                                <?php } else { ?>
                                    <div class="dms-empty"><i class="ti-direction-alt"></i>No officials on the chart yet.</div>
                                <?php } ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Recent Activities Section -->
                <div class="row">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-body">
                                <div class="dms-card-head">
                                    <div>
                                        <h4 class="dms-card-title">Recent activities</h4>
                                        <p class="dms-card-sub">Latest changes across all files</p>
                                    </div>
                                    <?php if ($role === 'master') { ?>
                                        <a href="./fullHistory.php" class="dms-chip">See all <i class="ti-angle-right"></i></a>
                                    <?php } ?>
                                </div>
                                <div class="dms-activity-head"><span>Document</span><span>Action</span></div>
                                <div class="dms-activity-list" id="recentActivities">
                                    <p id="lastUpdated" class="text-muted"></p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

            </div>
        </div>

    </div>

    <!-- Scripts -->
    <script src="./vendor/global/global.min.js"></script>
    <script src="./js/quixnav-init.js"></script>
    <script src="./js/custom.min.js"></script>
    <script src="./js/org-chart.js?v=<?php echo filemtime(__DIR__ . '/js/org-chart.js'); ?>"></script>

    <script>
        document.addEventListener("DOMContentLoaded", function () {
            fetchRecentActivities();
            initReportTimelines();
        });

        // Report timelines: one tab per document type; the last tab picked is remembered.
        function initReportTimelines() {
            const bar = document.querySelector("[data-rt-tabs]");
            if (!bar) { return; }
            const tabs = Array.from(bar.querySelectorAll("[data-rt-tab]"));
            const storeKey = "dms.dashboard.rtTab";

            function select(key, focus) {
                tabs.forEach(tab => {
                    const on = tab.dataset.rtTab === key;
                    tab.classList.toggle("is-active", on);
                    tab.setAttribute("aria-selected", on ? "true" : "false");
                    tab.tabIndex = on ? 0 : -1;
                    if (on && focus) { tab.focus(); }
                });
                document.querySelectorAll("[data-rt-panel]").forEach(panel => {
                    panel.hidden = panel.dataset.rtPanel !== key;
                });
                try { localStorage.setItem(storeKey, key); } catch (e) { /* storage unavailable */ }
            }

            bar.addEventListener("click", e => {
                const tab = e.target.closest("[data-rt-tab]");
                if (tab) { select(tab.dataset.rtTab, false); }
            });
            bar.addEventListener("keydown", e => {
                if (e.key !== "ArrowLeft" && e.key !== "ArrowRight") { return; }
                const i = tabs.findIndex(t => t.classList.contains("is-active"));
                const next = tabs[(i + (e.key === "ArrowRight" ? 1 : -1) + tabs.length) % tabs.length];
                select(next.dataset.rtTab, true);
                e.preventDefault();
            });

            let saved = null;
            try { saved = localStorage.getItem(storeKey); } catch (e) { /* storage unavailable */ }
            if (saved && tabs.some(t => t.dataset.rtTab === saved)) { select(saved, false); }
        }

        function escapeHtml(text) {
            return String(text === null || text === undefined ? "" : text)
                .replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;")
                .replace(/"/g, "&quot;").replace(/'/g, "&#39;");
        }

        function fetchRecentActivities() {
            fetch("recentactivities.php?timestamp=" + new Date().getTime())
                .then(response => {
                    if (!response.ok) {
                        throw new Error("HTTP error! Status: " + response.status);
                    }
                    return response.json();
                })
                .then(data => {
                    let activitiesContainer = document.getElementById("recentActivities");
                    activitiesContainer.innerHTML = "";

                    if (data.activities.length > 0) {
                        data.activities.forEach(activity => {
                            const style =
                                activity.action === 'Edited'  ? { ic: 'dms-ic-blue',  pill: 'is-blue',  icon: 'ti-pencil' } :
                                activity.action === 'Created' ? { ic: 'dms-ic-green', pill: 'is-green', icon: 'ti-plus' } :
                                activity.action === 'Deleted' ? { ic: 'dms-ic-red',   pill: 'is-red',   icon: 'ti-trash' } :
                                                                { ic: '',             pill: '',         icon: 'ti-file' };
                            let activityHTML = `
                                <div class="dms-activity-row">
                                    <span class="dms-activity-ic ${style.ic}"><i class="${style.icon}"></i></span>
                                    <div class="dms-activity-main">
                                        <div class="dms-activity-title">${escapeHtml(activity.file_type)}: ${escapeHtml(activity.title)}</div>
                                        <div class="dms-activity-meta">${escapeHtml(activity.action)} on ${escapeHtml(activity.timestamp)}</div>
                                    </div>
                                    <div class="dms-activity-side"><span class="dms-pill ${style.pill}">${escapeHtml(activity.action)}</span></div>
                                </div>
                            `;
                            activitiesContainer.insertAdjacentHTML("beforeend", activityHTML);
                        });
                    } else {
                        activitiesContainer.innerHTML = '<div class="dms-empty"><i class="ti-time"></i>No recent activities.</div>';
                    }
                })
                .catch(error => console.error("Error fetching activities:", error));
        }


    </script>


</body>
</html>
