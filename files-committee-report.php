<?php 
include "header.php"; 
error_reporting(E_ALL); // Enable error reporting for development
ini_set('display_errors', 1);

?>

<!DOCTYPE html>
<html lang="en">

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
                    require_once "includes/report_data.php"; // dms_report_row_key() for the rows; printing/exporting is in the Reports popup (sidebar)
                    require_once "includes/bulk_select.php"; // Select / Select all (admin and master)
                    $canEdit = ($role === 'admin' || $role === 'master');
                ?>
                <!-- row -->
                <div class="row">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-body">
                                <?php if ($canEdit) { dms_bulk_selectbar('committee reports'); } ?>
                                <div class="table-responsive">
                                    <table id="example" class="display dms-stack"<?php echo $canEdit ? ' data-bulk-table data-bulk-type="committee" data-bulk-noun="report"' : ''; ?>>
                                        <colgroup>
                                            <?php if ($canEdit) { ?><col class="dms-ob-check-col"><?php } ?>
                                            <col style="width: 15%;">
                                            <col style="width: 15%;">
                                            <col style="width: 25%;">
                                            <col style="width: 15%;">
                                            <col style="width: 15%;">
                                            <col style="width: 15%;">
                                        </colgroup>
                                        <thead class="text-center">
                                            <tr>
                                                <?php if ($canEdit) { dms_bulk_head_check(); } ?>
                                                <th data-orderable="false">TITLE</th>
                                                <th data-orderable="false">COMMITTEE CATEGORY</th>
                                                <th data-orderable="false">COMMITTEE SECTION</th>
                                                <th data-orderable="false">COUNCILOR</th>
                                                <th>DATE REPORTED</th>
                                                <th >ACTION</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php
                                                include "connect.php";

                                                $sql = "SELECT id, title, committee_category, committee_section, councilor, date_report FROM committee_reports ORDER BY date_report DESC";
                                                $stmt = $conn->prepare($sql);
                                                $stmt->execute();
                                                $result = $stmt->get_result();

                                                if (!$result) {
                                                    die("SQL Error: " . $conn->error);
                                                }

                                                while ($row = mysqli_fetch_assoc($result)) {
                                                    ?>
                                                    <tr<?php echo $canEdit ? ' class="dms-bulk-row" tabindex="0"' : ''; ?> data-id="<?php echo (int) $row['id']; ?>" data-date="<?php echo htmlspecialchars($row["date_report"]); ?>" data-filter="<?php echo htmlspecialchars(dms_report_row_key('committee', $row)); ?>">
                                                        <?php if ($canEdit) { dms_bulk_row_check('report: ' . mb_strimwidth((string) $row['title'], 0, 80, '…')); } ?>
                                                        <td><?php echo $row["title"] ?></td>
                                                        <td><?php echo $row["committee_category"] ?></td>
                                                        <td><?php echo $row["committee_section"] ?></td>
                                                        <td><?php echo $row["councilor"] ?></td>     
                                                        <td><?php echo $row["date_report"] ?></td>

                                                        <td style="text-align: center; vertical-align: middle;">
                                                            <div class="dms-row-actions">
                                                                <a href="viewCommitteeReports.php?id=<?php echo $row["id"] ?>" class="btn btn-primary btn-sm d-flex align-items-center justify-content-center p-2">
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
                            DmsNav.go('deleteCommitteeReport.php?id=' + id, { replace: true }); // replace: the list comes back in place of the delete
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
                    DmsNav.go('editCommitteReports.php?id=' + id);
                }
            });
        }

        $(document).ready(function() {
            if ($.fn.DataTable.isDataTable("#example")) {
                $("#example").DataTable().destroy(); // Destroy existing DataTable instance
            }

            // Admins/masters have a leading checkbox column (multi-select), so DATE REPORTED shifts by one.
            var hasCheck = $("#example [data-bulk-check-all]").length > 0;
            $("#example").DataTable({
                "order": [[hasCheck ? 5 : 4, "desc"]], // Sort by DATE REPORTED, newest first
                "columnDefs": hasCheck ? [{ "targets": 0, "orderable": false, "searchable": false }] : [],
                "destroy": true // Ensure previous instance is removed
            });
        });


    </script>
    <!-- Select / Select all: delete several committee reports at once -->
    <script src="./js/bulk-select.js?v=<?php echo filemtime(__DIR__ . '/js/bulk-select.js'); ?>"></script>


    <!-- Report toolbar: filters + Print / PDF / Excel -->
    <script src="./js/report-toolbar.js?v=<?php echo filemtime(__DIR__ . '/js/report-toolbar.js'); ?>"></script>
    <!-- History "Show in list": ?locate=id pages to the row and flashes it -->
    <script src="./js/locate-row.js?v=<?php echo filemtime(__DIR__ . '/js/locate-row.js'); ?>"></script>
</body>

</html>