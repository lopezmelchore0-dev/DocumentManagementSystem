<!DOCTYPE html>
<html lang="en">

    <?php include('header.php'); ?>

<body>

    <!--**********************************
        Main wrapper start
    ***********************************-->
    <div id="main-wrapper">

        <?php include('sidebar.php'); ?>

        <?php
            // FAQ content. Answers are static HTML written here (trusted); questions are escaped.
            // Sections marked 'master' => true are only shown to the Secretary (master) account.
            $faq_is_master = (isset($_SESSION['role']) ? $_SESSION['role'] : 'user') === 'master';

            $faq_sections = [
                [
                    'id' => 'accounts', 'title' => 'Accounts & signing in', 'icon' => 'ti-user', 'master' => false,
                    'items' => [
                        ['q' => 'How do I create an account?',
                         'a' => '<p>Click <strong>Register</strong> on the sign-in page and enter your full name, a username (4&ndash;50 characters) and a password of at least 8 characters. Your account is created as <em>pending</em> and can be used once the Sangguniang Bayan Secretary approves it.</p>'],
                        ['q' => 'It says "Account Pending Approval" or "Account Inactive". What do I do?',
                         'a' => '<p><strong>Pending Approval</strong> means your registration has not been approved yet. <strong>Inactive</strong> means your account has been switched off. In both cases, contact the Sangguniang Bayan Secretary, who manages accounts.</p>'],
                        ['q' => 'What are the different account roles?',
                         'a' => '<ul>'
                              . '<li><strong>Sangguniang Bayan Secretary</strong> &ndash; full access, including Manage Accounts, History, Organizational Chart, Published Terms and Backup &amp; Restore.</li>'
                              . '<li><strong>Sangguniang Bayan Staff</strong> &ndash; can add, edit and delete Resolutions, Ordinances, Order of Business and Committee Reports.</li>'
                              . '<li><strong>Sangguniang Bayan Council</strong> &ndash; can view files and print or export reports.</li>'
                              . '</ul><p>Your role is shown when you click your account button (your initials) at the top right of the screen.</p>'],
                        ['q' => 'Why was I signed out unexpectedly?',
                         'a' => '<p>An account can only be signed in at one place at a time. Signing in on another computer or browser ends the earlier session, and that page returns to the sign-in screen.</p>'],
                        ['q' => 'Can I use the system on a phone or tablet?',
                         'a' => '<p>Yes. The menu is in the sidebar on the left:</p>'
                              . '<ul><li><strong>Computer</strong> &ndash; the sidebar is always shown. The &#9776; button at the top left shrinks it to icons; the system remembers your choice.</li>'
                              . '<li><strong>Tablet</strong> &ndash; the sidebar shows icons only. Tap &#9776; to show the full menu.</li>'
                              . '<li><strong>Phone</strong> &ndash; tap &#9776; to slide the menu out; tap a page, the &times; or outside the menu to close it.</li></ul>'
                              . '<p>On a phone, lists show each record as a card. Where the computer uses a right-click (for example, setting an Order of Business item&rsquo;s status), <strong>press and hold</strong> the record instead.</p>'],
                        ['q' => 'Can I use the phone&rsquo;s Back button?',
                         'a' => '<p>Yes. The phone or tablet <strong>Back</strong> button, the swipe-back gesture (iPhone/iPad) and the browser&rsquo;s Back button all work the same way:</p>'
                              . '<ul><li>If a menu, popup, message or the scanner is open, Back <strong>closes it first</strong> and you stay on the page.</li>'
                              . '<li>Otherwise Back returns to the <strong>page you were on before</strong>, with the same search, sorting, page of the list, filters and scroll position.</li>'
                              . '<li>After you save or cancel an edit, you return to the list you came from, so pressing Back afterwards does not take you through the form again.</li>'
                              . '<li>If you have typed into a form and not saved it, the browser asks before leaving, so your typing isn&rsquo;t lost by accident. (iPhone and iPad do not show this question; use Save or Cancel there.)</li></ul>'
                              . '<p>On the first page you opened, Back does what it normally does in your browser.</p>'],
                        ['q' => 'How do I change my password?',
                         'a' => '<p>Click <strong>Account Settings</strong> in the sidebar (or click your account button at the top right and choose it there). Enter your current password, then the new password (at least 8 characters) twice, and save.</p>'],
                        ['q' => 'I forgot my password. What do I do?',
                         'a' => '<p>On the sign-in page, click <strong>Forgot password?</strong>. Enter your username and the new password you want (twice), then click <strong>Send Request</strong>. The request goes to the Sangguniang Bayan Secretary, who may contact you to confirm it was you. Once it is approved, sign in with the new password; until then your old password still works.</p>'
                              . '<p>The Secretary&rsquo;s own account can&rsquo;t be reset this way &ndash; contact the system administrator.</p>'],
                        ['q' => 'Why does Edit or Delete ask for a password?',
                         'a' => '<p>Changing or removing a record must be authorized by the Secretary, so Edit and Delete ask for the <strong>Secretary&rsquo;s password</strong>, not your own.</p>'],
                    ],
                ],
                [
                    'id' => 'resolutions', 'title' => 'Resolutions & Ordinances', 'icon' => 'ti-files', 'master' => false,
                    'items' => [
                        ['q' => 'How do I add a resolution or ordinance?',
                         'a' => '<p>Click <strong>+ Resolution</strong> or <strong>+ Ordinance</strong> in the top bar (on smaller screens: <strong>+ New</strong>), from any page. Required fields:</p>'
                              . '<ul><li><strong>Resolution</strong> &ndash; Reso No., Title, Date Adopted, Author/Sponsor.</li>'
                              . '<li><strong>Ordinance</strong> &ndash; MO No., Title, Author/Sponsor.</li></ul>'
                              . '<p>On the resolution form the other fields unlock after you type the Reso No. If that number already exists, the form shows the existing record and Save is disabled, so duplicates are not created.</p>'],
                        ['q' => 'What do the statuses mean?',
                         'a' => '<ul>'
                              . '<li><span class="dms-pill is-amber">Forwarded to LCE</span> &ndash; sent to the Local Chief Executive; asks for the Date Forwarded.</li>'
                              . '<li><span class="dms-pill is-blue">Signed by LCE</span> &ndash; also asks for the Date Signed.</li>'
                              . '<li><span class="dms-pill is-green">SP Approval</span> &ndash; also asks for the SP Resolution No. and SP approval date.</li>'
                              . '<li><span class="dms-pill is-red">Disapprove</span> &ndash; asks for notes explaining why.</li>'
                              . '</ul><p>Each later date cannot be earlier than the date before it. Hover a status pill in the list to see its dates.</p>'],
                        ['q' => 'What files can I attach?',
                         'a' => '<p>Resolutions and committee reports take a <strong>PDF</strong>. The maximum upload size is <strong>40 MB</strong>.</p>'
                              . '<p>Uploading a file with the same name as an existing one never replaces the old file &ndash; the new one is saved as, for example, <em>001-A (2).pdf</em>. If an upload fails you get an error message and the record is not saved with a broken link.</p>'],
                        ['q' => 'View File says &ldquo;File missing&rdquo;. What do I do?',
                         'a' => '<p>The record points to a file that is not in the <code>uploads</code> folder on the server (for example it was uploaded on another computer, or an older upload failed). A red <strong>File missing</strong> note appears under the attachment. Open the record with <strong>Edit</strong> and upload the file again, or use <strong>Scan Document</strong> on its View page.</p>'],
                        ['q' => 'How do I scan paper documents with my phone or tablet?',
                         'a' => '<p>Tap <strong>Scan Document</strong> next to an attachment (on the add and edit forms, and on the View page of a resolution, ordinance, Order of Business item or committee report). The camera opens inside the DMS &ndash; no scanner app is needed.</p>'
                              . '<ul><li>Hold the page in view: a green outline shows the detected page edges. Tap the round button to capture; turn on <strong>AUTO</strong> to capture automatically when the page is steady. The camera stays open, so you can scan many documents in a row.</li>'
                              . '<li><strong>New document</strong> starts a separate document with each capture; <strong>Add page</strong> adds pages to the same document.</li>'
                              . '<li>After each capture the detected page outline flashes briefly; the page is cropped and straightened automatically. If a photo is out of focus you see <em>&ldquo;Document appears blurry. Please retake the photo for better quality.&rdquo;</em> &ndash; tap <strong>Retake</strong>. (Blur cannot be repaired reliably, so the scanner asks for a new photo instead.)</li>'
                              . '<li>Tap <strong>Done</strong> to review: drag documents and pages to reorder them, and use <strong>Mode for selected</strong> to give many pages the same look. Tap a page for the <strong>before / after preview</strong> (original photo, cropped, final scan &ndash; press and hold the scan to compare). Each mode shows a small preview of the page: <strong>Magic Color</strong> (white paper, vivid colours &ndash; good for stamps and colour forms), <strong>Auto Enhance</strong> (evens out shadows, improves contrast and sharpness), <strong>No Shadow</strong> (removes shadows, colours left natural), <strong>Grayscale</strong>, <strong>Black &amp; White</strong> (for printing), <strong>Lighten</strong> (dark photos) and <strong>Original</strong>. The mode you choose is remembered for the next pages on that device.</li>'
    + '<li>The page is flattened to its <strong>true proportions</strong> even when photographed at an angle, and standard paper (Folio, A4, Letter, Legal) is recognised automatically; change it under <strong>Page shape</strong> if needed (ID card and Free are also available). <strong>Auto-straighten</strong> turns sideways pages upright and straightens slightly tilted text. <strong>Reduce glare</strong> tones down shiny reflections (text that is completely washed out by a reflection cannot be recovered &ndash; retake from a different angle instead).</li>'
    + '<li>In <strong>Adjust crop</strong> drag a corner, or the middle of a side to move the whole side; the magnifier shows the edge up close. Change brightness, contrast and <strong>Sharpness</strong>, retake, or <strong>Restore original</strong> &ndash; the original photo is always kept. <strong>Undo</strong> (or <kbd>Ctrl</kbd>+<kbd>Z</kbd>) takes back the last change. Tap <strong>Confirm Scan</strong> to keep the changes.</li>'
                              . '<li>On add and edit forms, <strong>Done</strong> in the camera attaches the scan to the form right away (tap the page thumbnail instead to review first). Need more pages? Tap <strong>Scan Document</strong> again: the camera opens straight away and the new pages are added to the <strong>same PDF</strong>, then tap <strong>Done</strong>. <strong>Remove scan</strong> under the field starts over. Enhancement only adjusts light, contrast and sharpness; it never changes text, signatures or stamps.</li>'
                              . '<li><strong>Select all</strong> / <strong>Delete selected</strong> work on whole documents; <strong>Preview batch</strong> shows every page as it will appear on the paper.</li>'
                              . '<li>In <strong>Save &amp; export</strong> choose <strong>Separate PDFs</strong> (one per document) or <strong>Combine into one PDF</strong>. The paper size is <strong>Folio (8.5 &times; 13 in), portrait</strong> by default; A4, Letter, Legal and landscape are available. Edit the file names, use <strong>Preview PDF</strong>, then <strong>Save &amp; Attach All</strong>.</li></ul>'
                              . '<p>On a View page the PDFs are attached right away: the first becomes the record&rsquo;s attachment if it has none, the rest appear under <strong>Additional attachments</strong> (View / Download / Delete). On add and edit forms the scan is placed in the form and saved when you click Save / Update. Scans stay on the device until they are attached, so a failed upload can simply be retried.</p>'],
                        ['q' => 'How do I scan papers into a record that is already in the system?',
                         'a' => '<p>Tap <strong>Scan</strong> (camera icon) in the top bar &ndash; it is on every page, also on phones (or <strong>+ New &rarr; Scan document</strong>). Scan the pages as usual, then in <strong>Save &amp; export</strong> use <strong>Attach to</strong>: <strong>Recently added</strong> (selected first) lists the newest records of every type, and its search looks through all of them; or choose Resolution, Ordinance, Order of Business or Committee Report and search by number or title. Tap the record. For an Order of Business item choose <strong>Supporting document</strong> or the <strong>Order of Business attachment</strong>.</p>'
                              . '<p>Tap <strong>Save &amp; Attach All</strong>. If the record has no file yet, the first PDF becomes its attachment; the others are added under <strong>Additional attachments</strong>. Afterwards choose <strong>Open record</strong> to check it, or <strong>Scan more</strong> to continue with the same record.</p>'],
                        ['q' => 'The scanner says the live camera needs a secure (https) connection.',
                         'a' => '<p>Browsers only allow the camera inside a web page on <strong>https://</strong> (or on the server PC itself as <em>localhost</em>). Over plain http the scanner still works: tap <strong>Take photo</strong> to use the phone&rsquo;s own camera, or <strong>Import images</strong>; every photo goes through the same edge detection, editing and PDF steps.</p>'
                              . '<p>For the live camera on phones, open the DMS over <strong>https</strong>. The server uses a certificate from its own <strong>DMS Local CA</strong>; install that CA once on each phone so there is no warning:</p>'
                              . '<ul><li>On the phone open <code>http://&lt;server-PC-address&gt;/dms-local-ca.crt</code> to download it.</li>'
                              . '<li><strong>Android:</strong> Settings &rarr; Security (or Security &amp; privacy) &rarr; Encryption &amp; credentials &rarr; Install a certificate &rarr; <strong>CA certificate</strong> &rarr; choose the downloaded file.</li>'
                              . '<li><strong>iPhone / iPad:</strong> open the link in Safari &rarr; Allow &rarr; Settings &rarr; <strong>Profile Downloaded</strong> &rarr; Install. Then Settings &rarr; General &rarr; About &rarr; <strong>Certificate Trust Settings</strong> &rarr; turn on <em>DMS Local CA</em>.</li>'
                              . '<li>Then open <code>https://&lt;server-PC-address&gt;/dms/Document-Management-System/</code>.</li></ul>'
                              . '<p>The certificate is valid for <em>localhost</em>, the server PC&rsquo;s name and the address it had when the certificate was made. If the server PC gets a new address, ask the administrator to make a new certificate (or reserve the PC&rsquo;s address in the Wi-Fi router so it never changes).</p>'],
                        ['q' => 'How do I find a record quickly?',
                         'a' => '<p>Use the <strong>Search</strong> box above the table &ndash; it matches any column. Click a column heading to sort. Use the <strong>Table / Timeline</strong> toggle to switch views, and <strong>View</strong> on a row to open the full record with its attachment and change history.</p>'],
                        ['q' => 'Can I delete many records at once?',
                         'a' => '<p>Yes (Resolutions, Ordinances and Committee Reports). Click <strong>Select</strong> above the table (or <kbd>Ctrl</kbd>+click a row), then click rows to add them; <kbd>Shift</kbd>+click selects a range. <strong>Select all</strong> picks every record matching the current search and filters &ndash; across all pages. Then use <strong>Actions</strong> (or right-click a selected row) and choose <strong>Delete selected</strong>; it asks for the Secretary&rsquo;s password. Press <kbd>Esc</kbd> or <strong>Done</strong> to leave selection mode.</p>'],
                    ],
                ],
                [
                    'id' => 'order-of-business', 'title' => 'Order of Business', 'icon' => 'ti-agenda', 'master' => false,
                    'items' => [
                        ['q' => 'How do I create an Order of Business?',
                         'a' => '<p>Click <strong>+ Order of Business</strong> in the top bar (on smaller screens: <strong>+ New</strong>). Enter the No. of Regular Session and the Date (optionally attach the Order of Business file). To add agenda items, type how many you need (1&ndash;50) and click <strong>+ Form</strong>; each item needs an Item No. and Title, and can have its own attachment. New items start in <strong>First Reading</strong>.</p>'],
                        ['q' => 'What sections and statuses are there?',
                         'a' => '<ul>'
                              . '<li><strong>First Reading</strong> &ndash; Referred, For Information, Approved</li>'
                              . '<li><strong>Second Reading</strong> &ndash; Deferred, Returned, File It Away, For Approval, Approved</li>'
                              . '<li><strong>Third Reading</strong> &ndash; Approved</li>'
                              . '<li><strong>Other Matters</strong> &ndash; Adopted, Referred, To Be Calendared</li>'
                              . '</ul><p>Setting <strong>Approved</strong> in First or Second Reading automatically moves the item to Third Reading. Moving an item to another section clears its status. Use the tabs above the list to see one section at a time.</p>'],
                        ['q' => 'How do I set an item\'s status or move it?',
                         'a' => '<p><strong>Right-click</strong> the item (or select it and press <kbd>Shift</kbd>+<kbd>F10</kbd>) and choose from <strong>Set status</strong> or <strong>Move to</strong>. Only statuses valid for that section are offered. A normal click opens the item&rsquo;s details, with a button to open the full page.</p>'],
                        ['q' => 'Can I update or delete many items at once?',
                         'a' => '<p>Yes. Click <strong>Select</strong> (or <kbd>Ctrl</kbd>+click an item), then click items to add them; <kbd>Shift</kbd>+click selects a range. <strong>Select all</strong> picks every item matching the current tab, search and filters &ndash; across all pages. Then use the <strong>Actions</strong> menu (or right-click a selected item) to set or clear status, move, or delete. Press <kbd>Esc</kbd> to leave selection mode.</p>'
                              . '<p>Items where a change doesn&rsquo;t apply are skipped. Deleting asks for the Secretary&rsquo;s password.</p>'],
                    ],
                ],
                [
                    'id' => 'committee', 'title' => 'Committee Reports', 'icon' => 'ti-layers-alt', 'master' => false,
                    'items' => [
                        ['q' => 'How do I add a committee report?',
                         'a' => '<p>Click <strong>+ Committee Report</strong> in the top bar (on smaller screens: <strong>+ New</strong>). Fill in Title, Councilor, Date Reported, Committee Category and Committee Section, and attach the PDF.</p>'],
                        ['q' => 'Why is the Committee Section list empty or short?',
                         'a' => '<p>The Section list depends on the <strong>Category</strong> you pick (Economic, Social, Infrastructure, Institutional, Environmental). Choose the category first and only its committees are shown.</p>'],
                        ['q' => 'What happens when I delete a committee report?',
                         'a' => '<p>The record and its attached PDF are both removed, and a &ldquo;Deleted&rdquo; entry is added to the history. Deleting asks for the Secretary&rsquo;s password.</p>'],
                    ],
                ],
                [
                    'id' => 'reports', 'title' => 'Printing & exporting', 'icon' => 'ti-printer', 'master' => false,
                    'items' => [
                        ['q' => 'How do I print or export a list?',
                         'a' => '<p>Click <strong>Reports</strong> in the sidebar. Pick the report (Resolutions, Ordinances, Order of Business or Committee Reports), set the date range and filters, then choose <strong>Preview</strong>, <strong>Print Report</strong>, <strong>Export to PDF</strong> or <strong>Export to Excel</strong>.</p>'
                              . '<p>When opened from a list page, the report is preselected for that page and its filters also filter the table.</p>'],
                        ['q' => 'What filters are available?',
                         'a' => '<p>A From/To date range with quick ranges (<em>This Month, Last Month, This Year, All Time</em>), plus Status for resolutions and ordinances, Section for Order of Business, and Category for committee reports. &ldquo;From&rdquo; must be on or before &ldquo;To&rdquo;.</p>'],
                        ['q' => 'How do I change the paper size?',
                         'a' => '<p>Choose the paper in the report popup: <strong>Short/Letter</strong> (8.5&times;11), <strong>Long/Folio</strong> (8.5&times;13, default), <strong>Legal</strong> (8.5&times;14) or <strong>A4</strong>, in Portrait or Landscape. It applies to Preview, Print and PDF, and is remembered on this computer&rsquo;s browser.</p>'],
                    ],
                ],
                [
                    'id' => 'forms', 'title' => 'Forms & popups', 'icon' => 'ti-new-window', 'master' => false,
                    'items' => [
                        ['q' => 'Why does "New…" open in a popup?',
                         'a' => '<p>So you can add records without leaving the page you are on. The add shortcuts are in the top bar, left of your name. After saving, the form clears so you can add the next one right away; the page refreshes when you close the popup. Middle-click a shortcut if you prefer the full page in a new tab.</p>'],
                        ['q' => 'How do I close the popup?',
                         'a' => '<p>Press <kbd>Esc</kbd>, click the <strong>&times;</strong>, or use the phone&rsquo;s <strong>Back</strong> button. If you have typed anything, you are asked to confirm before unsaved changes are discarded. Clicking outside the popup does not close it, so work isn&rsquo;t lost by accident.</p>'],
                    ],
                ],
                [
                    'id' => 'administration', 'title' => 'Administration', 'icon' => 'ti-shield', 'master' => true,
                    'items' => [
                        ['q' => 'How do I approve new registrations?',
                         'a' => '<p>Open <strong>Manage Accounts</strong>; the header shows how many registrations are pending. Use <strong>Approve</strong> or <strong>Reject</strong> on the row, or right-click a pending/inactive row. Approve activates the account; <strong>Reject deletes it</strong>.</p>'],
                        ['q' => 'How do I handle a password reset request?',
                         'a' => '<p>When someone uses <strong>Forgot password?</strong>, <strong>Manage Accounts</strong> shows the number of requests in the header and a <em>Password Reset Requested</em> badge on the account. First confirm the request really came from that person (in person or by phone). Then click the <strong>key</strong> button (or right-click the row and choose <strong>Approve password reset</strong>) to switch the account to the password they chose &ndash; any open session of theirs is signed out. Use the <strong>ban</strong> button / <strong>Reject password reset</strong> to discard the request; their current password stays.</p>'],
                        ['q' => 'How do I add, deactivate or change an account?',
                         'a' => '<p>In <strong>Manage Accounts</strong>, accounts you add yourself are active immediately (role Staff or Council). Edit an account to switch it Active/Inactive or change its role; the username can&rsquo;t be changed. Secretary (master) accounts are protected from editing and deletion.</p>'],
                        ['q' => 'What does History show?',
                         'a' => '<p>The last 100 changes per document type: records created, edited and deleted; Order of Business status changes and moves; and backups created or restored. Right-click a row to <strong>Open file</strong> or <strong>Show in list</strong> (unavailable if the file was deleted). History does not record which user made the change.</p>'],
                        ['q' => 'How do I manage the Organizational Chart?',
                         'a' => '<p>Open <strong>Organizational Chart</strong> and click <strong>Add Officials</strong> for the full form. Limits per term: Vice-Mayor 1, Councilor 10, LNB 5, PPSK 4 &ndash; full positions are greyed out; choose <em>Other</em> to type any other position (e.g. SB Secretary). Click an official on the chart to view, edit or delete them.</p>'],
                        ['q' => 'How do I edit the chart itself (titles, photos, groups, order)?',
                         'a' => '<p>On <strong>Organizational Chart</strong>, click <strong>Edit chart</strong> (Secretary and Staff accounts). While editing:</p><ul>'
                              . '<li>Click the chart title, subtitle or a group heading and type; press Enter to save (Esc cancels).</li>'
                              . '<li>Use the colour box on a group to change its colour, the arrows to move the group up or down, and the bin to delete it &ndash; its officials move to the group above.</li>'
                              . '<li>Click the <strong>camera</strong> on a photo to replace it.</li>'
                              . '<li>Drag an official to another place or group, or use the &lsaquo; &rsaquo; buttons (they also move into the previous/next group). The pencil opens the full details.</li>'
                              . '<li>Click the dashed <strong>+ Add official</strong> circle in a group to insert someone with just a photo, name, position and term; fill in the rest later. <strong>Add group</strong> at the bottom adds a new row (e.g. Secretariat).</li>'
                              . '</ul><p>Changes save immediately. Click <strong>Done editing</strong> when finished. The Dashboard shows a small copy of the chart.</p>'],
                        ['q' => 'How do I put the chart in a Word document or report?',
                         'a' => '<p>Click <strong>Download PNG</strong> to save the chart as an image file and insert it (Word: Insert &rsaquo; Pictures), or click <strong>Copy image</strong> and paste it with Ctrl+V. The edit buttons are never included in the image. If copying is not supported by the browser, the image is downloaded instead.</p>'],
                        ['q' => 'What does "Publish the Chart" do?',
                         'a' => '<p>It saves a copy of the officials of the <strong>most recent term</strong> to <strong>Published Terms</strong>, replacing any earlier copy of that same term. Published Terms keeps one card per term; <strong>Delete Term</strong> removes all officials under it.</p>'],
                        ['q' => 'How do I back up the system?',
                         'a' => '<p>Open <strong>Backup &amp; Restore</strong>:</p><ul>'
                              . '<li><strong>Back up database</strong> &ndash; records only (.sql).</li>'
                              . '<li><strong>Full backup</strong> &ndash; records plus all uploaded files (.zip). Don&rsquo;t close the page while it runs.</li>'
                              . '</ul><p>Backups are saved in the <code>backups</code> folder of the system and can be downloaded from the list. Keep a copy off this computer too (USB drive or cloud).</p>'],
                        ['q' => 'What happens when I restore a backup?',
                         'a' => '<p>The whole database is replaced with the backup &ndash; anything added after it was made is lost. A <strong>pre-restore safety copy</strong> is created automatically first, so you can undo a restore by restoring that copy. A full (.zip) restore adds or overwrites uploaded files but never deletes any.</p>'
                              . '<p>To restore a file larger than 40 MB, copy it into the <code>backups</code> folder and restore it from the list instead of uploading it.</p>'],
                    ],
                ],
            ];

            $faq_sections = array_values(array_filter($faq_sections, function ($s) use ($faq_is_master) {
                return !$s['master'] || $faq_is_master;
            }));
        ?>

        <!--**********************************
            Content body start
        ***********************************-->
        <div class="content-body">
            <div class="container-fluid">
                <div class="dms-page-head">
                    <div>
                        <h1>Frequently Asked Questions</h1>
                        <p>Quick answers on using the Document Management System.</p>
                    </div>
                    <div class="dms-page-actions">
                        <label class="dms-faq-search" for="faqSearch">
                            <i class="ti-search" aria-hidden="true"></i>
                            <input type="search" id="faqSearch" placeholder="Search questions…" autocomplete="off" aria-label="Search FAQ">
                        </label>
                    </div>
                </div>

                <nav class="dms-faq-jump" aria-label="FAQ sections">
                    <?php foreach ($faq_sections as $s) { ?>
                        <a class="dms-chip" href="#faq-<?php echo $s['id']; ?>"><i class="<?php echo $s['icon']; ?>" aria-hidden="true"></i><?php echo htmlspecialchars($s['title']); ?></a>
                    <?php } ?>
                </nav>

                <?php foreach ($faq_sections as $s) { ?>
                    <section class="card dms-faq-section" id="faq-<?php echo $s['id']; ?>">
                        <div class="card-body">
                            <div class="dms-card-head">
                                <h2 class="dms-card-title"><i class="<?php echo $s['icon']; ?> dms-faq-icon" aria-hidden="true"></i><?php echo htmlspecialchars($s['title']); ?></h2>
                            </div>
                            <?php foreach ($s['items'] as $i => $item) { $faq_id = 'faq-' . $s['id'] . '-' . $i; ?>
                                <div class="dms-faq-item">
                                    <button type="button" class="dms-faq-q collapsed" data-toggle="collapse" data-target="#<?php echo $faq_id; ?>" aria-expanded="false" aria-controls="<?php echo $faq_id; ?>">
                                        <span><?php echo htmlspecialchars($item['q']); ?></span>
                                        <i class="ti-angle-down" aria-hidden="true"></i>
                                    </button>
                                    <div class="collapse" id="<?php echo $faq_id; ?>">
                                        <div class="dms-faq-a"><?php echo $item['a']; ?></div>
                                    </div>
                                </div>
                            <?php } ?>
                        </div>
                    </section>
                <?php } ?>

                <div class="card dms-faq-none" id="faqNone" hidden>
                    <div class="card-body">
                        <div class="dms-empty">
                            <i class="ti-search" aria-hidden="true"></i>
                            <span>No questions match your search.</span>
                        </div>
                    </div>
                </div>

                <p class="dms-faq-foot">Still stuck? Contact the Sangguniang Bayan Secretary.</p>
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
        (function () {
            var input = document.getElementById('faqSearch');
            var none = document.getElementById('faqNone');
            var sections = Array.prototype.slice.call(document.querySelectorAll('.dms-faq-section'));
            var chips = Array.prototype.slice.call(document.querySelectorAll('.dms-faq-jump .dms-chip'));

            function setOpen(item, open) {
                var btn = item.querySelector('.dms-faq-q');
                var panel = item.querySelector('.collapse');
                panel.classList.toggle('show', open);
                btn.classList.toggle('collapsed', !open);
                btn.setAttribute('aria-expanded', open ? 'true' : 'false');
            }

            function filter() {
                var q = input.value.trim().toLowerCase();
                var anyShown = false;

                sections.forEach(function (section, idx) {
                    var shown = 0;
                    section.querySelectorAll('.dms-faq-item').forEach(function (item) {
                        var match = q === '' || item.textContent.toLowerCase().indexOf(q) !== -1;
                        item.hidden = !match;
                        setOpen(item, q !== '' && match);
                        if (match) { shown++; }
                    });
                    section.hidden = shown === 0;
                    if (chips[idx]) { chips[idx].hidden = shown === 0; }
                    if (shown) { anyShown = true; }
                });

                none.hidden = anyShown;
            }

            input.addEventListener('input', filter);
            input.addEventListener('keydown', function (e) {
                if (e.key === 'Escape' && input.value !== '') {
                    e.preventDefault();
                    input.value = '';
                    filter();
                }
            });
        })();
    </script>

</body>

</html>
