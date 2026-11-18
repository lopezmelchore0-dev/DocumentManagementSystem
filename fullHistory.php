<!DOCTYPE html>
<html lang="en">

<?php 
    include "header.php"; 
    include "connect.php";

    // Right-click a history row to open its file or show it in its list (menu script at the bottom).
    // Where each history file_type lives; ids that still exist are loaded so deleted files can't be opened.
    $dms_hist_targets = [
        'Resolution'       => ['table' => 'resolution',        'view' => 'viewresolution.php',       'list' => 'files-resolution.php',       'list_name' => 'Resolutions'],
        'Ordinance'        => ['table' => 'ordinance',         'view' => 'viewordinance.php',        'list' => 'files-ordinances.php',       'list_name' => 'Ordinances'],
        'Minutes'          => ['table' => 'minutes',           'view' => 'viewmeetingminutes.php',   'list' => 'files-meetingminutes.php',   'list_name' => 'Order of Business'],
        'Committee Report' => ['table' => 'committee_reports', 'view' => 'viewCommitteeReports.php', 'list' => 'files-committee-report.php', 'list_name' => 'Committee Reports'],
    ];
    $dms_hist_ids = [];
    foreach ($dms_hist_targets as $type => $t) {
        $dms_hist_ids[$type] = [];
        $res = $conn->query("SELECT id FROM `{$t['table']}`");
        if ($res) {
            while ($r = $res->fetch_assoc()) { $dms_hist_ids[$type][(int) $r['id']] = true; }
        }
    }

    function dms_hist_row_attrs($row) {
        global $dms_hist_targets, $dms_hist_ids;
        $type = $row['file_type'];
        $id   = (int) $row['file_id'];
        if (!isset($dms_hist_targets[$type])) { return ''; }
        $t      = $dms_hist_targets[$type];
        $exists = $id > 0 && isset($dms_hist_ids[$type][$id]);
        $attrs  = ' class="dms-hist-row" tabindex="0"'
                . ' data-title="' . htmlspecialchars(strip_tags((string) $row['title'])) . '"'
                . ' data-list-name="' . htmlspecialchars($t['list_name']) . '"'
                . ' data-exists="' . ($exists ? '1' : '0') . '"';
        if ($exists) {
            $attrs .= ' data-view-url="' . $t['view'] . '?id=' . $id . '"'
                    . ' data-list-url="' . $t['list'] . '?locate=' . $id . '"';
        }
        return $attrs;
    }

?>

<body>

    <!--**********************************
        Main wrapper start
    ***********************************-->
    <div id="main-wrapper">

        <?php include "sidebar.php"; ?>

        <!--**********************************
            Content body start
        ***********************************-->
        <div class="content-body">
            <div class="container-fluid" >
                <div class="dms-page-head">
                    <div>
                        <h1>History</h1>
                        <p>The last 100 changes per document type: what was created, edited or deleted, and when. Right-click a row to open the file or find it in its list.</p>
                    </div>
                </div>
                <div class="row">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-body">
                                <!-- Nav tabs -->
                                <div class="custom-tab-1">
                                    <ul class="nav nav-tabs">
                                        <li class="nav-item">
                                            <a class="nav-link active" data-toggle="tab" href="#resolution">Resolution</a>
                                        </li>
                                        <li class="nav-item">
                                            <a class="nav-link" data-toggle="tab" href="#ordinance">Ordinance</a>
                                        </li>
                                        <li class="nav-item">
                                            <a class="nav-link" data-toggle="tab" href="#minutes">Order of Business</a>
                                        </li>
                                                                                <li class="nav-item">
                                            <a class="nav-link" data-toggle="tab" href="#committee_reports">Committee Reports</a>
                                        </li>
                                    </ul>
                                    <div class="tab-content">
                                        <!--Resolution-->
                                        <div class="tab-pane fade show active" id="resolution" role="tabpanel">
                                            <div class="pt-4">
                                                <div class="table-responsive">
                                                    <table class="table table-bordered dms-stack">
                                                        <colgroup>
                                                            <col style="width: 70%;">
                                                            <col style="width: 15%;">
                                                            <col style="width: 15%;">
                                                        </colgroup>
                                                        <thead class="text-center">
                                                            <tr>
                                                                <th>TITLE</th>
                                                                <th>ACTION TAKEN</th>
                                                                <th>TIMESTAMP</th>
                                                            </tr>
                                                        </thead>
                                                        <tbody>
                                                            <?php 
                                                                include "connect.php";
                                                                $sql = "SELECT file_type, file_id, title, action, timestamp FROM history_log WHERE file_type = 'Resolution' ORDER BY timestamp DESC LIMIT 100";
                                                                $stmt = $conn->prepare($sql);
                                                                $stmt->execute();
                                                                $result = $stmt->get_result();
                                                                if (!$result) {
                                                                    die("SQL Error: " . $conn->error);
                                                                }

                                                                while ($row = mysqli_fetch_assoc($result)) {
                                                                    ?>
                                                                    <tr<?php echo dms_hist_row_attrs($row); ?>>
                                                                        <td><?php echo $row['title']; ?></td>
                                                                        <td class="text-center fs-4">
                                                                            <?php 
                                                                                switch ($row['action']) {
                                                                                    case 'Created':
                                                                                        echo '<span class="badge badge-success">Created</span>';
                                                                                        break;
                                                                                    case 'Edited':
                                                                                        echo '<span class="badge badge-primary">Edited</span>';
                                                                                        break;
                                                                                    case 'Deleted':
                                                                                        echo '<span class="badge badge-danger">Deleted</span>';
                                                                                        break;
                                                                                    default:
                                                                                        echo $row['action']; // fallback if action is not recognized
                                                                                }
                                                                            ?>
                                                                        </td>
                                                                        <td class="text-center"><?php echo date('F j, Y \a\t h:i A', strtotime($row['timestamp'])); ?></td>
                                                                    </tr>   
                                                                    <?php
                                                                }
                                                            ?>
                                                        </tbody>
                                                    </table>
                                                </div>               
                                            </div>
                                        </div>
                                        <!--Ordinance-->
                                        <div class="tab-pane fade" id="ordinance">
                                            <div class="pt-4">
                                            <div class="table-responsive">
                                                    <table class="table table-bordered dms-stack">
                                                        <colgroup>
                                                            <col style="width: 70%;">
                                                            <col style="width: 15%;">
                                                            <col style="width: 15%;">
                                                        </colgroup>
                                                        <thead class="text-center">
                                                            <tr>
                                                                <th>TITLE</th>
                                                                <th>ACTION TAKEN</th>
                                                                <th>TIMESTAMP</th>
                                                            </tr>
                                                        </thead>
                                                        <tbody>
                                                            <?php 
                                                                include "connect.php";
                                                                $sql = "SELECT file_type, file_id, title, action, timestamp FROM history_log WHERE file_type = 'Ordinance' ORDER BY timestamp DESC LIMIT 100";
                                                                $stmt = $conn->prepare($sql);
                                                                $stmt->execute();
                                                                $result = $stmt->get_result();
                                                                if (!$result) {
                                                                    die("SQL Error: " . $conn->error);
                                                                }

                                                                while ($row = mysqli_fetch_assoc($result)) {
                                                                    ?>
                                                                    <tr<?php echo dms_hist_row_attrs($row); ?>>
                                                                        <td><?php echo $row['title']; ?></td>
                                                                        <td class="text-center fs-4">
                                                                            <?php 
                                                                                switch ($row['action']) {
                                                                                    case 'Created':
                                                                                        echo '<span class="badge badge-success">Created</span>';
                                                                                        break;
                                                                                    case 'Edited':
                                                                                        echo '<span class="badge badge-primary">Edited</span>';
                                                                                        break;
                                                                                    case 'Deleted':
                                                                                        echo '<span class="badge badge-danger">Deleted</span>';
                                                                                        break;
                                                                                    default:
                                                                                        echo $row['action']; // fallback if action is not recognized
                                                                                }
                                                                            ?>
                                                                        </td>
                                                                        <td class="text-center"><?php echo date('F j, Y \a\t h:i A', strtotime($row['timestamp'])); ?></td>
                                                                    </tr>   
                                                                    <?php
                                                                }
                                                            ?>
                                                        </tbody>
                                                    </table>
                                                </div> 
                                            </div>
                                        </div>
                                        <div class="tab-pane fade" id="minutes">
                                            <div class="pt-4">
                                                <div class="table-responsive">
                                                    <table class="table table-bordered dms-stack">
                                                        <colgroup>
                                                            <col style="width: 60%;">
                                                            <col style="width: 10%;">
                                                            <col style="width: 20%;">
                                                            <col style="width: 10%;">
                                                        </colgroup>
                                                        <thead class="text-center">
                                                            <tr>
                                                                <th>TITLE</th>
                                                                <th>ACTION TAKEN</th>
                                                                <th>STATUS</th>
                                                                <th>TIMESTAMP</th>
                                                            </tr>
                                                        </thead>
                                                        <tbody>
                                                            <?php 
                                                                include "connect.php";
                                                                $sql = "SELECT file_type, file_id, title, action, status, timestamp FROM history_log WHERE file_type = 'Minutes' ORDER BY timestamp DESC LIMIT 100";
                                                                $stmt = $conn->prepare($sql);
                                                                $stmt->execute();
                                                                $result = $stmt->get_result();
                                                                if (!$result) {
                                                                    die("SQL Error: " . $conn->error);
                                                                }

                                                                while ($row = mysqli_fetch_assoc($result)) {
                                                                    ?>
                                                                    <tr<?php echo dms_hist_row_attrs($row); ?>>
                                                                        <td><?php echo $row['title']; ?></td>
                                                                        <td class="text-center fs-4">
                                                                            <?php 
                                                                                switch ($row['action']) {
                                                                                    case 'Created':
                                                                                        echo '<span class="badge badge-success">Created</span>';
                                                                                        break;
                                                                                    case 'Edited':
                                                                                        echo '<span class="badge badge-primary">Edited</span>';
                                                                                        break;
                                                                                    case 'Deleted':
                                                                                        echo '<span class="badge badge-danger">Deleted</span>';
                                                                                        break;
                                                                                    default:
                                                                                        echo $row['action']; // fallback if action is not recognized
                                                                                }
                                                                            ?>
                                                                        </td>
                                                                        <td class="text-center"><?php echo $row['status']; ?></td>
                                                                        <td class="text-center"><?php echo date('F j, Y \a\t h:i A', strtotime($row['timestamp'])); ?></td>
                                                                    </tr>   
                                                                    <?php
                                                                }
                                                            ?>
                                                        </tbody>
                                                    </table>
                                                </div>  
                                            </div>
                                        </div>
                                        <div class="tab-pane fade" id="committee_reports">
                                            <div class="pt-4">
                                                <div class="table-responsive">
                                                    <table class="table table-bordered dms-stack">
                                                        <colgroup>
                                                            <col style="width: 70%;">
                                                            <col style="width: 15%;">
                                                            <col style="width: 15%;">
                                                        </colgroup>
                                                        <thead class="text-center">
                                                            <tr>
                                                                <th>TITLE</th>
                                                                <th>ACTION TAKEN</th>
                                                                <th>TIMESTAMP</th>
                                                            </tr>
                                                        </thead>
                                                        <tbody>
                                                            <?php 
                                                                include "connect.php";
                                                                $sql = "SELECT file_type, file_id, title, action, timestamp FROM history_log WHERE file_type = 'Committee Report' ORDER BY timestamp DESC LIMIT 100";
                                                                $stmt = $conn->prepare($sql);
                                                                $stmt->execute();
                                                                $result = $stmt->get_result();
                                                                if (!$result) {
                                                                    die("SQL Error: " . $conn->error);
                                                                }

                                                                while ($row = mysqli_fetch_assoc($result)) {
                                                                    ?>
                                                                    <tr<?php echo dms_hist_row_attrs($row); ?>>
                                                                        <td><?php echo $row['title']; ?></td>
                                                                        <td class="text-center fs-4">
                                                                            <?php 
                                                                                switch ($row['action']) {
                                                                                    case 'Created':
                                                                                        echo '<span class="badge badge-success">Created</span>';
                                                                                        break;
                                                                                    case 'Edited':
                                                                                        echo '<span class="badge badge-primary">Edited</span>';
                                                                                        break;
                                                                                    case 'Deleted':
                                                                                        echo '<span class="badge badge-danger">Deleted</span>';
                                                                                        break;
                                                                                    default:
                                                                                        echo $row['action']; // fallback if action is not recognized
                                                                                }
                                                                            ?>
                                                                        </td>
                                                                        <td class="text-center"><?php echo date('F j, Y \a\t h:i A', strtotime($row['timestamp'])); ?></td>
                                                                    </tr>   
                                                                    <?php
                                                                }
                                                            ?>
                                                        </tbody>
                                                    </table>
                                                </div>  
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <!--**********************************
            Content body end
        ***********************************-->

        
    </div>
    <!--**********************************
        Main wrapper end
    ***********************************-->

    <!--**********************************
        Scripts
    ***********************************-->
    <!-- Required vendors -->
    <script src="./vendor/global/global.min.js"></script>
    <script src="./js/quixnav-init.js"></script>
    <script src="./js/custom.min.js"></script>

    <script>
        // Right-click (or ContextMenu key / Shift+F10) a history row: Open file, or Show in list.
        // Styled by .dms-ob-menu in dms-theme.css; rows carry their links from dms_hist_row_attrs().
        (function () {
            var menu = document.createElement("div");
            menu.className = "dms-ob-menu";
            menu.setAttribute("role", "menu");
            menu.hidden = true;
            menu.innerHTML =
                '<div class="dms-ob-menu-head dms-ob-menu-title"><span data-menu-name></span></div>' +
                '<button type="button" class="dms-ob-menu-item is-green" role="menuitem" data-op="view">' +
                    '<i class="fa fa-eye" aria-hidden="true"></i><span class="dms-ob-menu-label">Open file</span></button>' +
                '<button type="button" class="dms-ob-menu-item is-blue" role="menuitem" data-op="list">' +
                    '<i class="fa fa-crosshairs" aria-hidden="true"></i><span class="dms-ob-menu-label" data-list-label>Show in list</span></button>' +
                '<div class="dms-ob-menu-note" data-menu-note hidden>This file has been deleted, so it can no longer be opened.</div>';
            document.body.appendChild(menu);
            var menuName = menu.querySelector("[data-menu-name]");
            var listLabel = menu.querySelector("[data-list-label]");
            var note = menu.querySelector("[data-menu-note]");
            var items = Array.prototype.slice.call(menu.querySelectorAll(".dms-ob-menu-item"));
            var menuRow = null;

            function closeMenu() {
                if (menu.hidden) { return; }
                menu.hidden = true;
                if (menuRow) { menuRow.classList.remove("is-menu-open"); }
                menuRow = null;
            }

            function openMenu(tr, x, y) {
                closeMenu();
                menuRow = tr;
                tr.classList.add("is-menu-open");
                var exists = tr.getAttribute("data-exists") === "1";
                var title = tr.getAttribute("data-title") || "";
                menuName.textContent = title.length > 60 ? title.slice(0, 57) + "…" : title;
                listLabel.textContent = "Show in " + (tr.getAttribute("data-list-name") || "list");
                items.forEach(function (b) { b.disabled = !exists; });
                note.hidden = exists;
                menu.hidden = false;
                // Keep the menu inside the visible screen
                var w = menu.offsetWidth, h = menu.offsetHeight;
                menu.style.left = Math.max(8, Math.min(x, window.innerWidth - w - 8)) + "px";
                menu.style.top = Math.max(8, Math.min(y, window.innerHeight - h - 8)) + "px";
                if (exists) { items[0].focus(); } else { menu.focus(); }
            }
            menu.tabIndex = -1;

            document.addEventListener("contextmenu", function (e) {
                var tr = e.target.closest ? e.target.closest("tr.dms-hist-row") : null;
                if (!tr) { return; } // everything else keeps the browser's own menu
                e.preventDefault();
                if (e.button !== 2) { // keyboard: place the menu at the focused row
                    var r = tr.getBoundingClientRect();
                    openMenu(tr, r.left + 24, r.top + Math.min(r.height, 40));
                } else {
                    openMenu(tr, e.clientX, e.clientY);
                }
            });

            menu.addEventListener("click", function (e) {
                var b = e.target.closest(".dms-ob-menu-item");
                if (!b || b.disabled || !menuRow) { return; }
                var url = menuRow.getAttribute(b.getAttribute("data-op") === "view" ? "data-view-url" : "data-list-url");
                closeMenu();
                if (url) { DmsNav.go(url); }
            });

            menu.addEventListener("keydown", function (e) {
                var on = items.filter(function (b) { return !b.disabled; });
                var i = on.indexOf(document.activeElement);
                if (e.key === "Escape") { e.preventDefault(); var tr = menuRow; closeMenu(); if (tr) { tr.focus(); } }
                else if (e.key === "ArrowDown" && on.length) { e.preventDefault(); on[(i + 1) % on.length].focus(); }
                else if (e.key === "ArrowUp" && on.length) { e.preventDefault(); on[(i - 1 + on.length) % on.length].focus(); }
            });

            menu.addEventListener("contextmenu", function (e) { e.preventDefault(); });
            document.addEventListener("mousedown", function (e) { if (!menu.contains(e.target)) { closeMenu(); } });
            document.addEventListener("scroll", closeMenu, true);
            window.addEventListener("resize", closeMenu);
            window.addEventListener("blur", closeMenu);
        })();
    </script>

</body>

</html>