<?php 
    error_reporting(E_ALL); // Enable error reporting for development
    ini_set('display_errors', 1);
?>

<!DOCTYPE html>
<html lang="en">

<?php include"header.php" ?>


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
                    require_once "includes/bulk_select.php"; // Select / Select all (admin and master)
                    $canEdit = ($role === 'admin' || $role === 'master');
                ?>
                <!-- row -->
                <div class="row">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-body dms-ht-host">
                                <div class="dms-card-tools"><?php dms_history_toggle(); ?></div>
                                <?php if ($canEdit) { dms_bulk_selectbar('ordinances'); } ?>
                                <div class="table-responsive">
                                    <table id="example" class="display dms-stack"<?php echo $canEdit ? ' data-bulk-table data-bulk-type="ordinance" data-bulk-noun="ordinance"' : ''; ?>>
                                        <colgroup>
                                            <?php if ($canEdit) { ?><col class="dms-ob-check-col"><?php } ?>
                                            <col style="width: 15%;">
                                            <col style="width: 25%;">
                                            <col style="width: 15%;">
                                            <col style="width: 15%;">
                                            <col style="width: 15%;">
                                            <col style="width: 15%;">
                                        </colgroup>
                                        <thead class="text-center">
                                            <tr>
                                                <?php if ($canEdit) { dms_bulk_head_check(); } ?>
                                                <th data-orderable="false">RES NO./MO NO.</th>
                                                <th data-orderable="false">TITLE</th>
                                                <th>DATE ADOPTED</th>
                                                <th data-orderable="false">AUTHOR/SPONSOR</th>
                                                <th data-orderable="false">REMARKS</th>
                                                <th data-orderable="false">ACTION</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php
                                                include "connect.php";

                                                $sql = "SELECT id, mo_no, title, date_adopted, author_sponsor, remarks, date_fwd, date_signed, sp_approval FROM ordinance";
                                                $stmt = $conn->prepare($sql);
                                                $stmt->execute();
                                                $result = $stmt->get_result();

                                                if (!$result) {
                                                    die("SQL Error: " . $conn->error);
                                                }

                                                // Fetched once: the table and the history timeline both render from $rows.
                                                $rows = $result->fetch_all(MYSQLI_ASSOC);
                                                foreach ($rows as $row) {
                                                    ?>
                                                    <tr<?php echo $canEdit ? ' class="dms-bulk-row" tabindex="0"' : ''; ?> data-id="<?php echo (int) $row['id']; ?>" data-date="<?php echo htmlspecialchars($row["date_adopted"]); ?>" data-filter="<?php echo htmlspecialchars(dms_report_row_key('ordinance', $row)); ?>">
                                                        <?php if ($canEdit) { dms_bulk_row_check(trim($row['mo_no']) !== '' ? 'ordinance ' . $row['mo_no'] : 'this ordinance'); } ?>
                                                        <td><?php echo $row["mo_no"] ?></td>
                                                        <td><?php echo $row["title"] ?></td>
                                                        <td><?php echo $row["date_adopted"] ?></td> 
                                                        <td><?php echo $row["author_sponsor"] ?></td>     
                                                        <td>
                                                            <div class="text-center d-flex justify-content-center gap-2">
                                                                <a class="dms-pill <?php echo isset(["Forwarded to LCE"=>1,"Signed by LCE"=>1,"SP Approval"=>1,"Disapprove"=>1][$row["remarks"]]) ? ["Forwarded to LCE"=>"is-amber","Signed by LCE"=>"is-blue","SP Approval"=>"is-green","Disapprove"=>"is-red"][$row["remarks"]] : "is-plain"; ?>" data-placement="bottom" data-toggle="tooltip" title="
                                                                    <?php
                                                                        $d_forward = !empty($row["date_fwd"]) ? $row["date_fwd"] : "N/A";
                                                                        $d_signed = !empty($row["date_signed"]) ? $row["date_signed"] : "N/A";
                                                                        $d_approved = !empty($row["sp_approval"]) ? $row["sp_approval"] : "N/A";

                                                                        // Display relevant dates based on remarks
                                                                        if ($row["remarks"] == "Forwarded to LCE") {
                                                                            echo "Forwarded to LCE: $d_forward";
                                                                        } elseif ($row["remarks"] == "Signed by LCE") {
                                                                            echo "Forwarded to LCE: $d_forward \nSigned by LCE: $d_signed";
                                                                        } elseif ($row["remarks"] == "SP Approval") {
                                                                            echo "Forwarded to LCE: $d_forward \nSigned by LCE: $d_signed \nSP Approval: $d_approved";
                                                                        }
                                                                    ?>
                                                                ">
                                                                    <?php echo $row["remarks"] ?>
                                                                </a>
                                                            </div>
                                                        </td>
                                                        <!-- <td>
                                                            <div class="container">
                                                                <a id="popoverData" class="btn" href="#" data-content="Forwarded to LCE: <?php echo $row["d_forward"] ?>" rel="popover" 
                                                                data-placement="bottom" data-trigger="hover"><?php echo $row["remarks"] ?></a>
                                                            </div>
                                                        </td> -->
                                                        <td style="text-align: center; vertical-align: middle;">
                                                            <div class="dms-row-actions">
                                                                <a href="viewordinance.php?id=<?php echo $row["id"] ?>" class="btn btn-primary btn-sm d-flex align-items-center justify-content-center p-2">
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
                                        $items[] = [
                                            'id'         => $row['id'],
                                            'date'       => $row['date_adopted'],
                                            'date_label' => 'Adopted',
                                            'url'        => 'viewordinance.php?id=' . (int) $row['id'],
                                            'number'     => $row['mo_no'],
                                            'title'      => $row['title'],
                                            'type'       => 'Ordinance',
                                            'meta'       => ['Author/Sponsor' => $row['author_sponsor']],
                                            'pill'       => [$row['remarks'], dms_remarks_pill_class($row['remarks'])],
                                        ];
                                    }
                                    dms_history_render($items);
                                ?>
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
    
    <!-- Datatable -->
    <script src="./vendor/datatables/js/jquery.dataTables.min.js"></script>
    <script src="./js/plugins-init/datatables.init.js"></script>

    <script>
        $(document).ready(function(){
        $('[data-toggle="tooltip"]').tooltip(); 
    });
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
                            DmsNav.go('deleteordinance.php?id=' + id, { replace: true }); // replace: the list comes back in place of the delete
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
                    DmsNav.go('editordinance.php?id=' + id);
                }
            });
        }

        $(document).ready(function() {
            if ($.fn.DataTable.isDataTable("#example")) {
                $("#example").DataTable().destroy(); // Destroy existing DataTable instance
            }

            // Admins/masters have a leading checkbox column (multi-select), so DATE ADOPTED shifts by one.
            var hasCheck = $("#example [data-bulk-check-all]").length > 0;
            $("#example").DataTable({
                "order": [[hasCheck ? 3 : 2, "desc"]], // Sort by DATE ADOPTED, newest first
                "columnDefs": hasCheck ? [{ "targets": 0, "orderable": false, "searchable": false }] : [],
                "destroy": true // Ensure previous instance is removed
            });
        });

    </script>
    <!-- Select / Select all: delete several ordinances at once -->
    <script src="./js/bulk-select.js?v=<?php echo filemtime(__DIR__ . '/js/bulk-select.js'); ?>"></script>


    <!-- Report toolbar: filters + Print / PDF / Excel -->
    <script src="./js/report-toolbar.js?v=<?php echo filemtime(__DIR__ . '/js/report-toolbar.js'); ?>"></script>
    <!-- History "Show in list": ?locate=id pages to the row and flashes it -->
    <script src="./js/locate-row.js?v=<?php echo filemtime(__DIR__ . '/js/locate-row.js'); ?>"></script>
    <!-- History timeline: Table | Timeline switch; cards follow the table's search and filters -->
    <script src="./js/history-timeline.js?v=<?php echo filemtime(__DIR__ . '/js/history-timeline.js'); ?>"></script>
</body>

</html>