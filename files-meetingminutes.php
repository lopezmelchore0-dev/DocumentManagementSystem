<?php 
    error_reporting(E_ALL); // Enable error reporting for development
    ini_set('display_errors', 1);
?>
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
    
    <!--**********************************
        Main wrapper start
    ***********************************-->
    <div id="main-wrapper">

        <?php 
            
            include "sidebar.php";
            
            // Fetch role from session
            $role = isset($_SESSION['role']) ? $_SESSION['role'] : 'user';
        ?>

        <!--**********************************
            Content body start
        ***********************************-->
        <div class="content-body">
            <div class="container-fluid">
                <?php
                    require_once "includes/history_timeline.php"; // Table / Timeline switch (top of the card)
                    require_once "includes/report_data.php"; // dms_report_row_key() for the rows; printing/exporting is in the Reports popup (sidebar)
                    require_once "includes/minutes_workflow.php"; // sections, statuses, pills, details

                    include "connect.php";
                    dms_ob_ensure_schema($conn);
                    $canEdit = ($role === 'admin' || $role === 'master');
                    // Attachment columns are blobs: only short values (file paths) are loaded here.
                    $sql = "SELECT id, no_regSession, date, resNo, title, status,
                                   IF(LENGTH(genAttachment) > 1000, '', genAttachment) AS genAttachment, LENGTH(genAttachment) > 1000 AS genAttachment_stored,
                                   IF(LENGTH(attachment) > 1000, '', attachment) AS attachment, LENGTH(attachment) > 1000 AS attachment_stored, returnNo, resolutionNo, returnDate, resolutionDate,
                                   ob_action, reading, referral_office, referral_notes, action_updated_at, ob_status, status_updated_at
                            FROM minutes ORDER BY date DESC, CAST(SUBSTRING_INDEX(resNo, '.', 1) AS UNSIGNED) DESC, CAST(SUBSTRING_INDEX(resNo, '.', -1) AS UNSIGNED) DESC";
                    $stmt = $conn->prepare($sql);
                    if (!$stmt) {
                        die("SQL Error: " . $conn->error);
                    }
                    $stmt->execute();
                    $result = $stmt->get_result();

                    if (!$result) {
                        die("SQL Error: " . $conn->error);
                    }

                    // Fetched once: the tabs, the table and the history timeline all render from $rows.
                    $rows = $result->fetch_all(MYSQLI_ASSOC);
                    $schedules = dms_ob_load_schedules($conn);

                    $sectionCounts = array_fill_keys(dms_ob_sections(), 0);
                    foreach ($rows as $row) {
                        $sectionCounts[dms_ob_section($row)]++;
                    }
                ?>
                <!-- row -->
                <div class="row">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-body dms-ht-host">
                                <div class="dms-card-tools"><?php dms_history_toggle(); ?></div>
                                <!-- Sections: filter the table (and timeline) by First / Second / Third Reading or Other Matters -->
                                <div class="dms-ob-tabs" role="group" aria-label="Filter by section" data-ob-tabs>
                                    <button type="button" data-ob-tab="" class="is-active" aria-pressed="true">All <span class="dms-ob-count"><?php echo count($rows); ?></span></button>
                                    <?php foreach ($sectionCounts as $sectionName => $sectionCount) { ?>
                                        <button type="button" data-ob-tab="<?php echo htmlspecialchars($sectionName); ?>" aria-pressed="false">
                                            <?php echo htmlspecialchars($sectionName); ?> <span class="dms-ob-count" data-ob-count="<?php echo htmlspecialchars($sectionName); ?>"><?php echo (int) $sectionCount; ?></span>
                                        </button>
                                    <?php } ?>
                                </div>
                                <div class="dms-ob-selectbar" data-ob-selectbar>
                                        <p class="dms-ob-help" data-ob-help-idle><i class="fa fa-mouse-pointer" aria-hidden="true"></i> <span class="dms-fine-only">Click an agenda item to view its details<?php echo $canEdit ? '; right-click it to set its status, or use Select to work on several items at once' : ''; ?>.</span><span class="dms-touch-only">Tap an agenda item to view its details<?php echo $canEdit ? '; press and hold it to set its status, or use Select to work on several items at once' : ''; ?>.</span></p>
                                        <?php if ($canEdit) { ?>
                                            <p class="dms-ob-help" data-ob-help-selecting hidden><i class="fa fa-check-square-o" aria-hidden="true"></i> <span class="dms-fine-only">Click items to select them (Shift+click selects a range), then right-click a selected item for actions.</span><span class="dms-touch-only">Tap items to select them, then press and hold a selected item or use Actions.</span></p>
                                            <div class="dms-ob-select-actions">
                                                <span class="dms-ob-select-count" data-ob-sel-count hidden aria-live="polite"></span>
                                                <button type="button" class="btn btn-light btn-sm" data-ob-select-all hidden><i class="fa fa-check-square-o" aria-hidden="true"></i>&nbsp;Select all</button>
                                                <button type="button" class="btn btn-primary btn-sm" data-ob-sel-actions hidden disabled><i class="fa fa-bars" aria-hidden="true"></i>&nbsp;Actions</button>
                                                <button type="button" class="btn btn-light btn-sm" data-ob-select-toggle aria-pressed="false"><i class="fa fa-check-square-o" aria-hidden="true"></i>&nbsp;<span>Select</span></button>
                                            </div>
                                        <?php } ?>
                                    </div>
                                <div class="table-responsive">
                                    <table id="example" class="display dms-stack" style="width: 100%;"
                                           data-ob-table data-ob-can-edit="<?php echo $canEdit ? '1' : '0'; ?>"
                                           data-ob-statuses="<?php echo htmlspecialchars(json_encode(dms_ob_statuses_for_js()), ENT_QUOTES); ?>"
                                           data-ob-sections="<?php echo htmlspecialchars(json_encode(dms_ob_sections()), ENT_QUOTES); ?>">
                                        <thead class="text-center">
                                            <tr>
                                                <?php if ($canEdit) { ?>
                                                    <th class="dms-ob-check-col"><input type="checkbox" class="dms-ob-check" data-ob-check-all aria-label="Select all items shown"></th>
                                                <?php } ?>
                                                <th>NUMBER OF REGULAR SESSION</th>
                                                <th>DATE</th>
                                                <th style="width: 40%;">TITLE</th>
                                                <th>STATUS</th>
                                                <th>ACTION</th>
                                            </tr>
                                        </thead>
                                        <tbody class="text-left" >
                                            <?php
                                                $details = [];
                                                foreach ($rows as $row) {
                                                    $rowSched = isset($schedules[(int) $row['id']]) ? $schedules[(int) $row['id']] : [];
                                                    $details[(int) $row['id']] = dms_ob_item_details($row, $rowSched);
                                                    ?>
                                                    <tr class="dms-ob-row" tabindex="0" data-id="<?php echo (int) $row['id']; ?>" data-date="<?php echo htmlspecialchars($row["date"]); ?>" data-filter="<?php echo htmlspecialchars(dms_report_row_key('minutes', $row)); ?>" data-reading="<?php echo htmlspecialchars(dms_ob_section($row)); ?>" data-status="<?php echo htmlspecialchars(dms_ob_status($row)); ?>">
                                                        <?php if ($canEdit) { ?>
                                                            <td class="dms-ob-check-col"><input type="checkbox" class="dms-ob-check" data-ob-check tabindex="-1" aria-label="Select <?php echo htmlspecialchars(trim($row['resNo']) !== '' ? 'item ' . $row['resNo'] : 'this item'); ?>"></td>
                                                        <?php } ?>
                                                        <td><?php echo htmlspecialchars($row["no_regSession"]); ?></td>
                                                        <td><?php echo htmlspecialchars($row["date"]); ?></td>
                                                        <td style="white-space: pre-line;"><?php echo nl2br(htmlspecialchars($row["title"])); ?></td>

                                                        <td style="padding-top: 10px;" data-ob-cell>
                                                            <?php dms_ob_render_compact($row, $rowSched); ?>
                                                        </td>




                                                        <td style="text-align: center; vertical-align: middle;">
                                                            <div class="dms-row-actions">
                                                                <a href="viewmeetingminutes.php?id=<?php echo $row["id"] ?>" class="btn btn-primary btn-sm d-flex align-items-center justify-content-center p-2">
                                                                    <i class="fa fa-eye" aria-hidden="true"></i>
                                                                </a>
                                                                <?php if ($role === 'admin' || $role === 'master') { ?>
                                                                    <a onclick="confirmEdit(<?php echo $row['id']; ?>)" class="btn btn-success btn-sm d-flex align-items-center justify-content-center p-2 ml-1 mr-1">
                                                                        <i class="fa fa-edit" aria-hidden="true"></i>
                                                                    </a>
                                                                    <a onclick="confirmDelete(<?php echo $row['id']; ?>)" class="btn btn-danger btn-sm d-flex align-items-center justify-content-center p-2">
                                                                        <i class="fa fa-trash" aria-hidden="true"></i>
                                                                    </a>
                                                                <?php } ?>
                                                            </div>
                                                        </td>
                                                    </tr>
                                                    <?php
                                                }
                                            ?>
                                        </tbody>
                                    </table>
                                </div>
                                <?php
                                    $items = [];
                                    foreach ($rows as $row) {
                                        $state    = dms_ob_section($row);
                                        $number = trim($row['no_regSession']);
                                        if (trim($row['resNo']) !== '') {
                                            $number .= ($number !== '' ? ' · ' : '') . 'Item ' . trim($row['resNo']);
                                        }
                                        $items[] = [
                                            'id'         => $row['id'],
                                            'date'       => $row['date'],
                                            'date_label' => 'Session',
                                            'url'        => 'viewmeetingminutes.php?id=' . (int) $row['id'],
                                            'number'     => $number,
                                            'title'      => $row['title'],
                                            'type'       => 'Order of Business',
                                            'meta'       => [
                                                'Status'        => dms_ob_status($row),
                                                'Resolution No' => $row['resolutionNo'],
                                            ],
                                            'pill'       => [$state, dms_ob_section_pill($state)],
                                        ];
                                    }
                                    dms_history_render($items);
                                ?>
                                <!-- Per-item details for the left-click view (js/ob-status-menu.js) -->
                                <script type="application/json" id="ob-items"><?php echo json_encode((object) $details, JSON_INVALID_UTF8_SUBSTITUTE) /* "/" is escaped, so "</script>" cannot occur */; ?></script>

                                <!-- Agenda item details (left-click) -->
                                <div class="modal fade" id="obDetailsModal" tabindex="-1" role="dialog" aria-labelledby="obDetailsTitle" aria-hidden="true">
                                    <div class="modal-dialog modal-lg modal-dialog-centered" role="document">
                                        <div class="modal-content">
                                            <div class="modal-header">
                                                <h5 class="modal-title" id="obDetailsTitle">Agenda item details</h5>
                                                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                                                    <span aria-hidden="true">&times;</span>
                                                </button>
                                            </div>
                                            <div class="modal-body dms-ob-details" data-ob-details></div>
                                            <div class="modal-footer">
                                                <a href="#" class="btn btn-primary" data-ob-details-open>Open full page</a>
                                                <button type="button" class="btn btn-light" data-dismiss="modal">Close</button>
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


        <!--**********************************
            Footer start
        ***********************************-->
        <div class="footer">
            <div class="copyright">
                <!-- <p>Copyright © Designed &amp; Developed by <a href="#" target="_blank">Quixkit</a> 2019</p> -->
            </div>
        </div>
        <!--**********************************
            Footer end
        ***********************************-->

        <!--**********************************
           Support ticket button start
        ***********************************-->

        <!--**********************************
           Support ticket button end
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

    <!-- Datatable -->
    <script src="./vendor/datatables/js/jquery.dataTables.min.js"></script>
    <script src="./js/plugins-init/datatables.init.js"></script>

    <!-- Sweetalert for deletion-->
    <script>
        function confirmDelete(id) {
            Swal.fire({
                title: "Enter Password",
                input: "password",
                inputAttributes: {
                    autocapitalize: "off",
                    required: true
                },
                showCancelButton: true,
                confirmButtonText: "Submit",
                showLoaderOnConfirm: true,
                preConfirm: (password) => {
                    return fetch("validate_password.php", {
                        method: "POST",
                        headers: {
                            "Content-Type": "application/x-www-form-urlencoded"
                        },
                        body: "password=" + encodeURIComponent(password)
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (!data.success) {
                            throw new Error(data.message || "Incorrect password.");
                        }
                    })
                    .catch(error => {
                        Swal.showValidationMessage(error.message);
                    });
                },
                allowOutsideClick: () => !Swal.isLoading()
            }).then((result) => {
                if (result.isConfirmed) {
                    Swal.fire({
                        title: "Are you sure?",
                        text: "You won't be able to revert this!",
                        icon: "warning",
                        showCancelButton: true,
                        confirmButtonColor: "#3085d6",
                        cancelButtonColor: "#d33",
                        confirmButtonText: "Confirm!"
                    }).then((result) => {
                        if (result.isConfirmed) {
                            Swal.fire({
                                title: "Deleted!",
                                text: "Your file has been deleted.",
                                icon: "success",
                                showConfirmButton: false,
                                timer: 2000
                            });
                            DmsNav.go('deletemeetingminutes.php?id=' + id, { replace: true }); // replace: the list comes back in place of the delete
                        }
                    });
                }
            });
        }

        function confirmEdit(id) {
            Swal.fire({
                title: "Enter Password",
                input: "password",
                inputAttributes: {
                    autocapitalize: "off",
                    required: true
                },
                showCancelButton: true,
                confirmButtonText: "Submit",
                showLoaderOnConfirm: true,
                preConfirm: (password) => {
                    return fetch("validate_password.php", {
                        method: "POST",
                        headers: {
                            "Content-Type": "application/x-www-form-urlencoded"
                        },
                        body: "password=" + encodeURIComponent(password)
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (!data.success) {
                            throw new Error(data.message || "Incorrect password.");
                        }
                    })
                    .catch(error => {
                        Swal.showValidationMessage(error.message);
                    });
                },
                allowOutsideClick: () => !Swal.isLoading()
            }).then((result) => {
                if (result.isConfirmed) {
                    DmsNav.go('editmeetingminutes.php?id=' + id);
                }
            });
        }

        $(document).ready(function() {
            if ($.fn.DataTable.isDataTable("#example")) {
                $("#example").DataTable().destroy(); // Destroy existing DataTable instance
            }

            // Admins/masters have a leading checkbox column (multi-select), so DATE shifts by one.
            var hasCheck = $("#example [data-ob-check-all]").length > 0;
            $("#example").DataTable({
                "order": [[hasCheck ? 2 : 1, "desc"]], // Sort by DATE, newest first
                "columnDefs": hasCheck ? [{ "targets": 0, "orderable": false, "searchable": false }] : [],
                "destroy": true // Ensure previous instance is removed
            });
        });

        // Section tabs: All / First / Second / Third Reading / Other Matters.
        // Rows carry data-reading (dms_ob_section). ext.search is global, so the
        // filter survives the DataTable being re-initialised; the timeline view
        // follows the table's filtered rows (js/history-timeline.js).
        (function ($) {
            var storeKey = 'dms-ob-tab:' + window.location.pathname;
            var active = '';
            try { active = window.localStorage.getItem(storeKey) || ''; } catch (e) { active = ''; }

            var $tabs = $('[data-ob-tabs] [data-ob-tab]');
            if (!$tabs.filter(function () { return $(this).attr('data-ob-tab') === active; }).length) {
                active = '';
            }

            $.fn.dataTable.ext.search.push(function (settings, data, dataIndex) {
                if (!active || settings.nTable.id !== 'example') { return true; }
                var tr = settings.aoData[dataIndex] && settings.aoData[dataIndex].nTr;
                return !tr || tr.getAttribute('data-reading') === active;
            });

            function paint() {
                $tabs.each(function () {
                    var on = $(this).attr('data-ob-tab') === active;
                    $(this).toggleClass('is-active', on).attr('aria-pressed', on ? 'true' : 'false');
                });
            }

            $tabs.on('click', function () {
                active = $(this).attr('data-ob-tab');
                try { window.localStorage.setItem(storeKey, active); } catch (e) { /* storage blocked */ }
                paint();
                if ($.fn.DataTable.isDataTable('#example')) {
                    $('#example').DataTable().draw();
                }
            });

            paint();
            $(function () {
                if (active && $.fn.DataTable.isDataTable('#example')) {
                    $('#example').DataTable().draw();
                }
            });
        })(jQuery);

    </script>


    <!-- Report toolbar: filters + Print / PDF / Excel -->
    <script src="./js/report-toolbar.js?v=<?php echo filemtime(__DIR__ . '/js/report-toolbar.js'); ?>"></script>
    <!-- History "Show in list": ?locate=id pages to the row and flashes it -->
    <script src="./js/locate-row.js?v=<?php echo filemtime(__DIR__ . '/js/locate-row.js'); ?>"></script>
    <!-- History timeline: Table | Timeline switch; cards follow the table's search and filters -->
    <script src="./js/history-timeline.js?v=<?php echo filemtime(__DIR__ . '/js/history-timeline.js'); ?>"></script>
    <!-- Agenda items: left-click details, right-click SET STATUS menu -->
    <script src="./js/ob-status-menu.js?v=<?php echo filemtime(__DIR__ . '/js/ob-status-menu.js'); ?>"></script>
</body>

</html>