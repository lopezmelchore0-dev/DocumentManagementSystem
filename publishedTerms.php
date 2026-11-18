<?php 
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

include 'connect.php';
include 'header.php';

$publishedTerms = $conn->query("SELECT DISTINCT term_start, term_end FROM published_terms ORDER BY term_start DESC");
$published = $publishedTerms->fetch_all(MYSQLI_ASSOC);
?>

<body>
    <?php include "loadingscreen.php"; ?>

    <div id="main-wrapper">
        <?php 
            include "sidebar.php";
            $role = $_SESSION['role'] ?? 'master';
        ?>

        <div class="content-body">
            <div class="container-fluid">
                <div class="dms-page-head">
                    <div>
                        <h1>Published Terms</h1>
                        <p>Organizational charts that have been published, one per term.</p>
                    </div>
                </div>

                <?php foreach ($published as $term): ?>
                    <div class="card mb-4">
                        <div class="card-header d-flex justify-content-between align-items-center flex-wrap" style="gap: .75rem;">
                            <div>
                                <h4 class="dms-card-title">Term: <?= date("F j, Y", strtotime($term['term_start'])) ?> – <?= date("F j, Y", strtotime($term['term_end'])) ?></h4>
                                <p class="dms-card-sub mb-0">Published organizational chart</p>
                            </div>

                            <form method="POST" action="deletePublishedTerm.php" class="mb-0 delete-term-form">
                                <input type="hidden" name="term_start" value="<?= htmlspecialchars($term['term_start']) ?>">
                                <input type="hidden" name="term_end" value="<?= htmlspecialchars($term['term_end']) ?>">
                                <button type="button" class="btn btn-danger btn-sm delete-term-btn">Delete Term</button>
                            </form>

                        </div>
                        <div class="card-body">
                            <div class="row">
                                <?php
                                $stmt = $conn->prepare("
                                    SELECT * FROM published_terms 
                                    WHERE term_start = ? AND term_end = ?
                                    ORDER BY 
                                        FIELD(position, 'Vice-Mayor', 'Councilor', 'LNB', 'PPSK'),
                                        name
                                ");
                                $stmt->bind_param("ss", $term['term_start'], $term['term_end']);
                                $stmt->execute();
                                $result = $stmt->get_result();

                                while ($official = $result->fetch_assoc()):
                                ?>
                                    <div class="col-xl-3 col-lg-4 col-sm-6 mb-3">
                                        <div class="card h-100 text-center dms-official-card" style="cursor: default;">
                                            <img src="<?= htmlspecialchars($official['photo_path']) ?>"
                                                 class="card-img-top"
                                                 alt="<?= htmlspecialchars($official['name']) ?>"
                                                 style="object-fit: cover; height: 200px;">
                                            <div class="card-body">
                                                <h5 class="card-title"><?= htmlspecialchars($official['name']) ?></h5>
                                                <p class="card-text dms-pill is-green is-plain"><?= htmlspecialchars($official['position']) ?></p>
                                            </div>
                                        </div>
                                    </div>
                                <?php endwhile; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <script>
    document.querySelectorAll('.delete-term-btn').forEach(button => {
        button.addEventListener('click', function () {
            const form = this.closest('form');
            Swal.fire({
                title: 'Are you sure?',
                text: "This will delete all officials under the selected term.",
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#d33',
                cancelButtonColor: '#3085d6',
                confirmButtonText: 'Yes, delete it!'
            }).then((result) => {
                if (result.isConfirmed) {
                    form.submit();
                }
            });
        });
    });
    </script>


    <!-- Required Vendors and Scripts -->
    <script src="./vendor/global/global.min.js"></script>
    <script src="./js/quixnav-init.js"></script>
    <script src="./js/custom.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
