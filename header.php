<?php
    // Pages include this file and sidebar.php includes it again: print the <head> (and load
    // jQuery/Bootstrap/add-modal.js, run session.php) only the first time.
    if (defined('DMS_HEADER_DONE')) { return; }
    define('DMS_HEADER_DONE', true);
    include "session.php";
    // Add pages opened inside the "New …" pop-up card (js/add-modal.js) load with ?embed=1:
    // they skip the sidebar/header and talk to the parent page instead of redirecting.
    $dmsEmbed = !empty($_GET['embed']);
?>

<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <?php
        // Sign-in pages set $dmsAuthPage (full-bleed background under phone notches, see the
        // safe-area padding in css/dms-theme.css) and $dmsPageTitle (tab / screen-reader title).
    ?>
    <meta name="viewport" content="width=device-width,initial-scale=1<?php echo !empty($dmsAuthPage) ? ',viewport-fit=cover' : ''; ?>">
    <title><?php echo !empty($dmsPageTitle) ? htmlspecialchars($dmsPageTitle) . ' · ' : ''; ?>Document Management System</title>
    <!-- Favicon icon -->
    <link rel="icon" type="image/png" sizes="16x16" href="./images/logo.png">
    <!-- Datatable -->
    <link href="./vendor/datatables/css/jquery.dataTables.min.css" rel="stylesheet">
    <!-- Custom Stylesheet -->
    <link href="./css/style.css" rel="stylesheet">
    <!-- UI theme (presentation only; loaded after the base theme so it can override it) -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="./css/dms-theme.css?v=<?php echo filemtime(__DIR__ . '/css/dms-theme.css'); ?>" rel="stylesheet">
    <!-- Include SweetAlert CSS and JS -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js"></script>
    <!-- For the Toggle Button -->
    <link href="https://cdn.jsdelivr.net/gh/gitbrent/bootstrap4-toggle@3.6.1/css/bootstrap4-toggle.min.css" rel="stylesheet">

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <!-- Bootstrap JS (for modal) -->
    <script src="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/js/bootstrap.min.js"></script>
    <!-- "New …" pop-up card: opens the add forms in a modal (parent) / reports back from inside it (child) -->
    <script src="./js/add-modal.js?v=<?php echo filemtime(__DIR__ . '/js/add-modal.js'); ?>"></script>
    <?php if (!empty($_SESSION['token'])) { ?>
    <script>
        // A signed-in page restored from the back/forward cache (e.g. Back after signing out)
        // is reloaded, so the server decides what to show (login page if signed out).
        // (__dmsBfReload tells js/dms-nav.js not to touch history for a page that is about to reload.)
        window.addEventListener('pageshow', function (e) { if (e.persisted) { window.__dmsBfReload = true; location.reload(); } });
    </script>
    <!-- Phone/tablet Back: closes menus and pop-ups first, returns to the previous page as it was -->
    <script src="./js/dms-nav.js?v=<?php echo filemtime(__DIR__ . '/js/dms-nav.js'); ?>"></script>
    <?php } else { ?>
    <script>
        // Signed out: forget this tab's page trail (js/dms-nav.js) so the next sign-in starts fresh.
        try { ['dms.nav.trail', 'dms.nav.cur', 'dms.nav.replace', 'dms.nav.expect'].forEach(function (k) { sessionStorage.removeItem(k); }); } catch (e) { /* storage blocked */ }
    </script>
    <?php } ?>

</head>

