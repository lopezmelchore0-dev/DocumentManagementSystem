<?php
    include 'header.php';

    // Fetch role from session
    $role = isset($_SESSION['role']) ? $_SESSION['role'] : 'user';

    // ---- Presentation helpers (UI only) -------------------------------------------
    // Which nav item should be highlighted, and what the header title says.
    $dms_page = isset($current_page) ? $current_page : basename($_SERVER['PHP_SELF']);
    $dms_page_map = [
        'dashboard.php'              => ['Dashboard', 'dashboard'],
        'files-resolution.php'       => ['Resolutions', 'resolution'],
        'addresolution.php'          => ['Resolutions', 'resolution'],
        'editresolution.php'         => ['Resolutions', 'resolution'],
        'viewresolution.php'         => ['Resolutions', 'resolution'],
        'files-ordinances.php'       => ['Ordinances', 'ordinance'],
        'addordinance.php'           => ['Ordinances', 'ordinance'],
        'editordinance.php'          => ['Ordinances', 'ordinance'],
        'viewordinance.php'          => ['Ordinances', 'ordinance'],
        'files-meetingminutes.php'   => ['Order of Business', 'minutes'],
        'addmeetingminutes.php'      => ['Order of Business', 'minutes'],
        'editmeetingminutes.php'     => ['Order of Business', 'minutes'],
        'viewmeetingminutes.php'     => ['Order of Business', 'minutes'],
        'files-committee-report.php' => ['Committee Reports', 'committee'],
        'addCommitteeReports.php'    => ['Committee Reports', 'committee'],
        'editCommitteReports.php'    => ['Committee Reports', 'committee'],
        'viewCommitteeReports.php'   => ['Committee Reports', 'committee'],
        'accountSettings.php'        => ['Account Settings', 'settings'],
        'manageAccounts.php'         => ['Manage Accounts', 'accounts'],
        'files-officials.php'        => ['Organizational Chart', 'officials'],
        'addOfficials.php'           => ['Organizational Chart', 'officials'],
        'previousTerms.php'          => ['Organizational Chart', 'officials'],
        'publishedTerms.php'         => ['Published Terms', 'terms'],
        'fullHistory.php'            => ['History', 'history'],
        'backup.php'                 => ['Backup & Restore', 'backup'],
        'manual.php'                 => ['Manual', 'manual'],
        'faq.php'                    => ['FAQ', 'faq'],
    ];
    $dms_title = isset($dms_page_map[$dms_page]) ? $dms_page_map[$dms_page][0] : 'Document Management System';
    $dms_group = isset($dms_page_map[$dms_page]) ? $dms_page_map[$dms_page][1] : '';
    $dms_files_open = in_array($dms_group, ['resolution', 'ordinance', 'minutes', 'committee'], true);
    // 'is-current' is what the active style keys on; aria-current tells screen readers.
    $dms_active = function ($group) use ($dms_group) { return $dms_group === $group ? ' is-current" aria-current="page' : ''; };

    $dms_email = isset($_SESSION['email']) ? $_SESSION['email'] : '';

    // Name shown in the header: the account's full name when it has one (accounts.full_name,
    // optional column), otherwise the email. Read-only lookup; any failure falls back quietly.
    $dms_name = '';
    if (isset($conn) && $dms_email !== '') {
        try {
            $dms_stmt = $conn->prepare("SELECT full_name FROM accounts WHERE email = ? LIMIT 1");
            if ($dms_stmt) {
                $dms_stmt->bind_param("s", $dms_email);
                $dms_stmt->execute();
                $dms_stmt->bind_result($dms_full_name);
                if ($dms_stmt->fetch() && trim((string) $dms_full_name) !== '') { $dms_name = trim($dms_full_name); }
                $dms_stmt->close();
            }
        } catch (Throwable $e) { /* column missing on an older database: keep the email */ }
    }
    $dms_display = $dms_name !== '' ? $dms_name : $dms_email;

    // Two-letter initials: first + last word of the name, or the first two letters of the email
    if ($dms_name !== '') {
        $dms_words = preg_split('/\s+/', $dms_name);
        $dms_initial = substr($dms_words[0], 0, 1) . (count($dms_words) > 1 ? substr(end($dms_words), 0, 1) : substr($dms_words[0], 1, 1));
    } else {
        $dms_initial = $dms_email !== '' ? substr($dms_email, 0, 2) : 'U';
    }
    $dms_initial = strtoupper($dms_initial);
?>

<!-- Sidebar state before the first paint (no jump): desktop remembers the icon rail,
     tablets start as the rail, phones start with the drawer closed. -->
<script>
    (function () {
        var d = document.documentElement, w = window.innerWidth || d.clientWidth, mini = false;
        if (w >= 1200) { try { mini = localStorage.getItem("dms.sidebar.mini") === "1"; } catch (e) { mini = false; } }
        else if (w >= 768) { mini = true; }
        if (mini) { d.classList.add("dms-sb-mini"); } else { d.classList.remove("dms-sb-mini"); }
    })();
</script>

<!--**********************************
    Sidebar start
***********************************-->
<aside class="dms-sidebar" id="dmsSidebar" aria-label="Main navigation">
    <div class="dms-sb-brand">
        <a href="./dashboard.php" class="dms-sb-brand-link" title="SB DMS — Dashboard">
            <img src="./images/logo.png" alt="">
            <span class="dms-wordmark">
                <strong>SB DMS</strong>
                <small>Sangguniang Bayan</small>
            </span>
        </a>
        <button type="button" class="dms-sb-close" id="dmsSbClose" aria-label="Close menu">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg>
        </button>
    </div>

    <nav class="dms-sb-scroll" aria-label="Main">
        <p class="dms-sb-label">Menu</p>
        <ul class="dms-nav">
            <li class="dms-nav-item">
                <a href="./dashboard.php" class="dms-nav-link<?php echo $dms_active('dashboard'); ?>" title="Dashboard"><i class="ti-dashboard"></i><span class="dms-nav-text">Dashboard</span></a>
            </li>
            <li class="dms-nav-item dms-has-sub<?php echo $dms_files_open ? ' is-open' : ''; ?>">
                <button type="button" class="dms-nav-link dms-sub-toggle<?php echo $dms_files_open ? ' is-current-parent' : ''; ?>" aria-expanded="<?php echo $dms_files_open ? 'true' : 'false'; ?>" aria-controls="dmsSubFiles" title="Files">
                    <i class="ti-folder"></i><span class="dms-nav-text">Files</span><svg class="dms-caret" viewBox="0 0 16 16" aria-hidden="true"><path d="M4 6l4 4 4-4" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
                </button>
                <ul class="dms-sub" id="dmsSubFiles">
                    <li><a href="./files-resolution.php" class="dms-sub-link<?php echo $dms_active('resolution'); ?>"><i class="ti-files"></i>Resolution</a></li>
                    <li><a href="./files-ordinances.php" class="dms-sub-link<?php echo $dms_active('ordinance'); ?>"><i class="ti-agenda"></i>Ordinance</a></li>
                    <li><a href="./files-meetingminutes.php" class="dms-sub-link<?php echo $dms_active('minutes'); ?>"><i class="ti-list"></i>Order of Business</a></li>
                    <li><a href="./files-committee-report.php" class="dms-sub-link<?php echo $dms_active('committee'); ?>"><i class="ti-clipboard"></i>Committee Reports</a></li>
                </ul>
            </li>
            <li class="dms-nav-item">
                <a href="#" class="dms-nav-link" data-rt-open="#dmsReportModal" title="Reports"><i class="ti-printer"></i><span class="dms-nav-text">Reports</span></a>
            </li>
            <li class="dms-nav-item">
                <a href="./faq.php" class="dms-nav-link<?php echo $dms_active('faq'); ?>" title="FAQ"><i class="ti-help-alt"></i><span class="dms-nav-text">FAQ</span></a>
            </li>
        </ul>

        <?php if ($role === 'master') { ?>
            <p class="dms-sb-label">Administration</p>
            <ul class="dms-nav">
                <li class="dms-nav-item">
                    <a href="./fullHistory.php" class="dms-nav-link<?php echo $dms_active('history'); ?>" title="History"><i class="ti-time"></i><span class="dms-nav-text">History</span></a>
                </li>
                <li class="dms-nav-item">
                    <a href="./manageAccounts.php" class="dms-nav-link<?php echo $dms_active('accounts'); ?>" title="Manage Accounts"><i class="ti-id-badge"></i><span class="dms-nav-text">Manage Accounts</span></a>
                </li>
                <li class="dms-nav-item">
                    <a href="./files-officials.php" class="dms-nav-link<?php echo $dms_active('officials'); ?>" title="Organizational Chart"><i class="ti-direction-alt"></i><span class="dms-nav-text">Organizational Chart</span></a>
                </li>
                <li class="dms-nav-item">
                    <a href="./publishedTerms.php" class="dms-nav-link<?php echo $dms_active('terms'); ?>" title="Published Terms"><i class="ti-archive"></i><span class="dms-nav-text">Published Terms</span></a>
                </li>
                <li class="dms-nav-item">
                    <a href="./backup.php" class="dms-nav-link<?php echo $dms_active('backup'); ?>" title="Backup &amp; Restore"><i class="ti-server"></i><span class="dms-nav-text">Backup &amp; Restore</span></a>
                </li>
            </ul>
        <?php } ?>

        <p class="dms-sb-label">Account</p>
        <ul class="dms-nav">
            <li class="dms-nav-item">
                <a href="./accountSettings.php" class="dms-nav-link<?php echo $dms_active('settings'); ?>" title="Account Settings"><i class="ti-settings"></i><span class="dms-nav-text">Account Settings</span></a>
            </li>
            <li class="dms-nav-item">
                <a href="#" class="dms-nav-link" data-dms-logout title="Sign out"><i class="ti-power-off"></i><span class="dms-nav-text">Sign out</span></a>
            </li>
        </ul>
    </nav>
</aside>
<div class="dms-sb-backdrop" id="dmsSbBackdrop" aria-hidden="true"></div>

<!-- Header: sidebar toggle, page title, signed-in account -->
<header class="dms-header" id="dmsHeader">
    <button type="button" class="dms-sb-toggle" id="dmsSbToggle" aria-controls="dmsSidebar" aria-expanded="true" aria-label="Toggle menu">
        <span></span><span></span><span></span>
    </button>
    <a href="./dashboard.php" class="dms-header-brand" aria-label="Dashboard"><img src="./images/logo.png" alt=""></a>
    <p class="dms-header-title"><?php echo htmlspecialchars($dms_title); ?></p>

    <?php if ($role === 'master' || $role === 'admin') {
        // "New …" shortcuts: open the add form in the pop-up card (js/add-modal.js).
        // Wide screens show the buttons; narrower ones fold them into the "New" menu.
        $dms_quick = [
            ['addmeetingminutes.php',   'Order of Business', 'New Order of Business', 'ti-list'],
            ['addordinance.php',        'Ordinance',         'New Ordinance',         'ti-agenda'],
            ['addresolution.php',       'Resolution',        'New Resolution',        'ti-files'],
            ['addCommitteeReports.php', 'Committee Report',  'New Committee Report',  'ti-clipboard'],
        ];
        // Scanner on every page for admin/master (OpenCV itself loads only when the scanner opens).
        require_once __DIR__ . '/includes/scanner.php';
        dms_scanner_assets();
        $dms_scan_attrs = 'data-dms-scan="1" data-mode="pick" data-session="scan:quick"'
            . ' data-max-post="' . min(dms_scan_ini_bytes(ini_get('post_max_size')), dms_scan_ini_bytes(ini_get('upload_max_filesize'))) . '"'
            . ' data-max-files="' . (int) ini_get('max_file_uploads') . '"';
    ?>
        <nav class="dms-header-quick" aria-label="Add new">
            <?php foreach ($dms_quick as $q) { ?>
                <a href="./<?php echo $q[0]; ?>" class="dms-quick-btn" data-add-modal data-add-title="<?php echo $q[2]; ?>" title="<?php echo $q[2]; ?>"><i class="ti-plus" aria-hidden="true"></i><span><?php echo $q[1]; ?></span></a>
            <?php } ?>
        </nav>
        <div class="dms-has-drop dms-header-new">
            <button type="button" class="dms-quick-btn dms-drop-toggle" aria-expanded="false" aria-label="Add new">
                <i class="ti-plus" aria-hidden="true"></i><span>New</span>
            </button>
            <ul class="dms-drop dms-drop-end">
                <?php foreach ($dms_quick as $q) { ?>
                    <li><a href="./<?php echo $q[0]; ?>" class="dms-drop-link" data-add-modal data-add-title="<?php echo $q[2]; ?>"><i class="<?php echo $q[3]; ?>"></i><?php echo $q[1]; ?></a></li>
                <?php } ?>
                <li><button type="button" class="dms-drop-link" <?php echo $dms_scan_attrs; ?>><i class="ti-camera"></i>Scan document</button></li>
            </ul>
        </div>
        <!-- Scan shortcut: scan first, then choose the existing record to attach the PDFs to
             (js/doc-scanner.js "pick" mode). Always visible, also on phones. -->
        <button type="button" class="dms-quick-btn dms-header-scan" <?php echo $dms_scan_attrs; ?> title="Scan documents into an existing record">
            <i class="ti-camera" aria-hidden="true"></i><span>Scan</span>
        </button>
    <?php } ?>

    <div class="dms-has-drop dms-header-account">
        <button type="button" class="dms-account-btn dms-drop-toggle<?php echo $dms_group === 'settings' ? ' is-current-parent' : ''; ?>" aria-expanded="false" aria-label="Account menu">
            <span class="dms-avatar"><?php echo htmlspecialchars($dms_initial); ?></span>
            <span class="dms-user-name"><?php echo htmlspecialchars($dms_display); ?></span>
            <svg class="dms-caret" viewBox="0 0 16 16" aria-hidden="true"><path d="M4 6l4 4 4-4" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
        </button>
        <ul class="dms-drop dms-drop-end">
            <li class="dms-drop-head">
                <strong><?php echo htmlspecialchars($dms_display); ?></strong>
                <?php if ($dms_name !== '') { ?><small class="dms-drop-email"><?php echo htmlspecialchars($dms_email); ?></small><?php } ?>
                <small><?php echo htmlspecialchars($role); ?></small>
            </li>
            <li><a href="./accountSettings.php" class="dms-drop-link<?php echo $dms_active('settings'); ?>"><i class="ti-settings"></i>Account Settings</a></li>
            <li><a href="#" class="dms-drop-link is-danger" id="logoutBtn" data-dms-logout><i class="ti-power-off"></i>Sign out</a></li>
        </ul>
    </div>
</header>
<!-- The base theme script (js/custom.min.js) attaches a scrollbar to .quixnav-scroll and stops
     with an error if it is missing; this empty hook keeps the rest of that script running. -->
<div class="quixnav-scroll" hidden></div>
<!--**********************************
    Sidebar end
***********************************-->

<!-- Print & Export Reports popup (opened from the sidebar "Reports" link). The script is
     deferred so it runs after each page's own jQuery/Bootstrap/DataTables. -->
<?php
    // On a list page the popup opens on that page's report and also filters its table.
    $reportModalPage = in_array($dms_page, ['files-resolution.php', 'files-ordinances.php', 'files-meetingminutes.php', 'files-committee-report.php'], true)
        ? $dms_group : '';
    include __DIR__ . '/includes/report-modal.php';
?>
<script src="./js/report-toolbar.js?v=<?php echo filemtime(__DIR__ . '/js/report-toolbar.js'); ?>" defer></script>
<!-- Phones/tablets: tables turn into stacked cards; press-and-hold opens row menus -->
<script src="./js/responsive-table.js?v=<?php echo filemtime(__DIR__ . '/js/responsive-table.js'); ?>" defer></script>
<script src="./js/long-press.js?v=<?php echo filemtime(__DIR__ . '/js/long-press.js'); ?>" defer></script>

<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
    // Sign out (header account menu and sidebar)
    document.querySelectorAll("[data-dms-logout]").forEach(function (link) {
        link.addEventListener("click", function (event) {
            event.preventDefault(); // Prevent default logout action

            Swal.fire({
                title: "Sign out",
                text: "Are you sure you would like to sign out of your account?",
                icon: "warning",
                showCancelButton: true,
                confirmButtonColor: "#3085d6",
                cancelButtonColor: "#d33",
                confirmButtonText: "Yes"
            }).then((result) => {
                if (result.isConfirmed) {
                    window.location.href = "logout.php"; // Redirect to logout page
                }
            });
        });
    });

    // ---- Sidebar + header (presentation only) ----
    (function () {
        var root = document.documentElement;
        var sidebar = document.getElementById("dmsSidebar");
        var toggle = document.getElementById("dmsSbToggle");
        var header = document.getElementById("dmsHeader");
        var backdrop = document.getElementById("dmsSbBackdrop");
        var closeBtn = document.getElementById("dmsSbClose");
        if (!sidebar || !toggle || !header) { return; }

        var KEY = "dms.sidebar.mini";
        var mqPhone = window.matchMedia("(max-width: 767.98px)");
        var mqDesk = window.matchMedia("(min-width: 1200px)");

        function mode() { return mqPhone.matches ? "phone" : (mqDesk.matches ? "desktop" : "tablet"); }
        function isMini() { return root.classList.contains("dms-sb-mini"); }
        function isDrawerOpen() { return root.classList.contains("dms-sb-open"); }
        function storedMini() { try { return localStorage.getItem(KEY) === "1"; } catch (e) { return false; } }
        function saveMini(v) { try { localStorage.setItem(KEY, v ? "1" : "0"); } catch (e) { /* private mode */ } }

        function sync() {
            var phone = mode() === "phone";
            var expanded = phone ? isDrawerOpen() : !isMini();
            toggle.setAttribute("aria-expanded", expanded ? "true" : "false");
            toggle.setAttribute("aria-label", phone ? (expanded ? "Close menu" : "Open menu")
                                                    : (expanded ? "Collapse sidebar" : "Expand sidebar"));
        }
        function setMini(v) {
            root.classList.toggle("dms-sb-mini", v);
            if (mode() === "desktop") { saveMini(v); }
            sync();
        }
        function openDrawer() {
            root.classList.add("dms-sb-open");
            sync();
            if (closeBtn) { closeBtn.focus(); }
        }
        function closeDrawer(returnFocus) {
            if (!isDrawerOpen()) { return; }
            root.classList.remove("dms-sb-open");
            sync();
            if (returnFocus) { toggle.focus(); }
        }
        // Screen crossed a breakpoint: reset to that size's default
        function applyMode() {
            root.classList.remove("dms-sb-open");
            var m = mode();
            root.classList.toggle("dms-sb-mini", m === "desktop" ? storedMini() : m === "tablet");
            sync();
        }

        toggle.addEventListener("click", function () {
            if (mode() === "phone") { isDrawerOpen() ? closeDrawer(false) : openDrawer(); }
            else { setMini(!isMini()); }
        });
        if (backdrop) { backdrop.addEventListener("click", function () { closeDrawer(true); }); }
        if (closeBtn) { closeBtn.addEventListener("click", function () { closeDrawer(true); }); }

        // Files group: accordion; in the icon rail it opens the full sidebar first
        sidebar.querySelectorAll(".dms-sub-toggle").forEach(function (btn) {
            btn.addEventListener("click", function () {
                var item = btn.closest(".dms-has-sub");
                if (mode() !== "phone" && isMini()) {
                    setMini(false);
                    item.classList.add("is-open");
                    btn.setAttribute("aria-expanded", "true");
                    return;
                }
                var open = !item.classList.contains("is-open");
                item.classList.toggle("is-open", open);
                btn.setAttribute("aria-expanded", open ? "true" : "false");
            });
        });
        // Phones: tapping a destination closes the drawer
        sidebar.querySelectorAll("a[href]").forEach(function (a) {
            a.addEventListener("click", function () { if (mode() === "phone") { closeDrawer(false); } });
        });

        // Account dropdown in the header
        function closeDrops(except) {
            header.querySelectorAll(".dms-has-drop.is-open").forEach(function (item) {
                if (item === except) { return; }
                item.classList.remove("is-open");
                item.querySelector(".dms-drop-toggle").setAttribute("aria-expanded", "false");
            });
        }
        header.querySelectorAll(".dms-drop-toggle").forEach(function (btn) {
            btn.addEventListener("click", function () {
                var item = btn.closest(".dms-has-drop");
                var open = !item.classList.contains("is-open");
                closeDrops(item);
                item.classList.toggle("is-open", open);
                btn.setAttribute("aria-expanded", open ? "true" : "false");
            });
        });
        document.addEventListener("click", function (e) {
            if (!header.contains(e.target)) { closeDrops(); }
        });
        // Picking an item in a header menu (e.g. a "New …" form) closes the menu
        header.querySelectorAll(".dms-drop a").forEach(function (a) {
            a.addEventListener("click", function () { closeDrops(); });
        });

        // Esc: close the account menu, then the phone drawer
        document.addEventListener("keydown", function (e) {
            if (e.key !== "Escape") { return; }
            var openToggle = header.querySelector(".dms-has-drop.is-open .dms-drop-toggle");
            if (openToggle) { closeDrops(); openToggle.focus(); return; }
            if (isDrawerOpen()) { closeDrawer(true); }
        });

        function onChange(mq) {
            if (mq.addEventListener) { mq.addEventListener("change", applyMode); } else if (mq.addListener) { mq.addListener(applyMode); }
        }
        onChange(mqPhone);
        onChange(mqDesk);
        sync();
        // Phone Back (js/dms-nav.js) closes the header menus, then the drawer
        window.DmsShell = {
            closeDrops: function () { closeDrops(); },
            closeDrawer: function () { closeDrawer(false); }
        };
        // Turn transitions on only after the first paint, so the page does not animate in
        requestAnimationFrame(function () { requestAnimationFrame(function () { root.classList.add("dms-sb-ready"); }); });
    })();

    // Auto-sized textareas are measured before the web font loads; re-measure once fonts are ready
    // (only those a page already sized via an inline height — no events are fired).
    if (document.fonts && document.fonts.ready) {
        document.fonts.ready.then(function () {
            document.querySelectorAll("textarea[style*='height']").forEach(function (t) {
                t.style.height = "auto";
                t.style.height = (t.scrollHeight + 2) + "px";
            });
        });
    }
</script>
