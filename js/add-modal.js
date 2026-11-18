/*
 * "New …" pop-up card.
 *
 * Parent role (list pages, dashboard): a click on any [data-add-modal] link opens one shared
 * Bootstrap modal that loads the link's add page in an iframe with ?embed=1. The link's href
 * stays a normal link, so middle-click / no-JS still open the full page.
 *
 * Child role (an add page running inside that iframe): dmsAddDone() / dmsAddLeave() tell the
 * parent the form was saved / cancelled; outside an iframe they just navigate like before.
 * The child also reports its content height so the iframe can fit it.
 */
(function () {
    'use strict';

    // Pages include header.php twice (directly and again via sidebar.php), so this file can load
    // twice. Set up only once, or a click would open two stacked pop-ups with two forms.
    if (window.__dmsAddModalReady) { return; }
    window.__dmsAddModalReady = true;

    var inFrame = window.parent && window.parent !== window;

    function post(type, extra) {
        var msg = { type: type };
        if (extra) { for (var k in extra) { msg[k] = extra[k]; } }
        try { window.parent.postMessage(msg, window.location.origin); } catch (e) { /* ignore */ }
    }

    /* ---------------- Child role ---------------- */

    // Called after a successful save (SweetAlert "OK"). Stays on the add form: reload it as a fresh
    // GET (empty form, no re-POST on refresh). In the pop-up, tell the parent first so it reloads
    // its list once the pop-up is closed. `url` (the list page) is kept for older callers.
    window.dmsAddDone = function (url) {
        if (inFrame) { post('dms-add-saved'); }
        // js/dms-nav.js: no "Leave site?" prompt, and the reload replaces this history entry
        if (window.DmsNav) { window.DmsNav.allowLeave(); window.DmsNav.markReplace(); }
        window.location.replace(window.location.pathname + window.location.search);
    };

    // Has the user typed/changed anything on this add form since it loaded?
    var dirty = false;
    window.dmsAddIsDirty = function () {
        return dirty || !!(window.DmsNav && window.DmsNav.isDirty && window.DmsNav.isDirty());
    };

    function swalOpen(win) {
        try { return !!(win.Swal && win.Swal.isVisible && win.Swal.isVisible()); } catch (e) { return false; }
    }

    function startChildKeys() {
        if (!inFrame || !document.body || !document.body.classList.contains('dms-embed')) { return; }
        document.addEventListener('input', function () { dirty = true; }, true);
        document.addEventListener('change', function () { dirty = true; }, true);
        // ESC inside the form → ask the parent to close the pop-up. SweetAlert handles its own ESC
        // (e.g. dismissing the "Are you sure?" confirm), and an open select2 list closes first.
        document.addEventListener('keydown', function (e) {
            if (e.key !== 'Escape' && e.key !== 'Esc') { return; }
            if (swalOpen(window) || document.querySelector('.select2-container--open')) { return; }
            e.preventDefault();
            post('dms-add-esc');
        });
    }

    // Called when the user confirms Cancel.
    // Full page: go back to the list already in history (js/dms-nav.js) instead of stacking a new copy.
    window.dmsAddLeave = function (url) {
        if (window.DmsNav) { window.DmsNav.allowLeave(); }
        if (inFrame) { post('dms-add-cancel'); }
        else if (window.DmsNav) { window.DmsNav.returnTo(url); }
        else { window.location.href = url; }
    };

    function startHeightReports() {
        if (!inFrame || !document.body || !document.body.classList.contains('dms-embed')) { return; }
        var last = 0;
        function report() {
            var wrap = document.getElementById('main-wrapper') || document.body;
            var h = Math.ceil(wrap.getBoundingClientRect().height);
            if (h && h !== last) { last = h; post('dms-add-height', { height: h }); }
        }
        report();
        if (window.ResizeObserver) {
            new ResizeObserver(report).observe(document.getElementById('main-wrapper') || document.body);
        } else {
            setInterval(report, 500);
        }
    }

    /* ---------------- Parent role ---------------- */

    var modal = null;   // .modal element, built on first use
    var frame = null;   // its iframe
    var contentHeight = 0;
    var savedSinceOpen = false; // a record was added → reload the list when the pop-up closes
    var frameWanted = false;    // the iframe should be showing a form (not blank)
    var frameStep = 0;          // which of its own documents the form is on (1 = first load; a save adds one)
    var backWaiter = null;      // waiting for the form to answer a Back press

    // History: the form is loaded with location.replace, so opening the pop-up adds no history entry
    // of its own. js/dms-nav.js adds one "overlay" entry once the form has loaded (so phone Back
    // closes the pop-up) and removes it again, with any entries a save added, when the pop-up closes.
    function settle(fn) {
        if (window.DmsNav && window.DmsNav.settle) { window.DmsNav.settle(fn); } else { fn(); }
    }
    function loadFrame(url) {
        try { frame.contentWindow.location.replace(url); } catch (e) { frame.setAttribute('src', url); }
    }
    window.dmsAddFrameStep = function () { return frameStep; };

    function jq() { return window.jQuery; }

    function buildModal() {
        modal = document.createElement('div');
        modal.className = 'modal fade dms-add-modal';
        modal.id = 'dmsAddModal';
        modal.setAttribute('tabindex', '-1');
        modal.setAttribute('role', 'dialog');
        modal.setAttribute('aria-labelledby', 'dmsAddModalTitle');
        modal.setAttribute('aria-hidden', 'true');
        modal.innerHTML =
            '<div class="modal-dialog modal-xl modal-dialog-centered" role="document">' +
                '<div class="modal-content">' +
                    '<div class="modal-header">' +
                        '<span class="dms-am-icon" aria-hidden="true"><i class="fa fa-plus"></i></span>' +
                        '<h5 class="modal-title" id="dmsAddModalTitle"></h5>' +
                        '<button type="button" class="close" data-am-close aria-label="Close">' +
                            '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" aria-hidden="true"><path d="M5 5l14 14M19 5L5 19"/></svg>' +
                        '</button>' +
                    '</div>' +
                    '<div class="modal-body">' +
                        '<div class="dms-am-loading"><span class="dms-am-spinner"></span>Loading form…</div>' +
                        // camera: the "Scan Document" button (js/doc-scanner.js) works inside the form
                        '<iframe class="dms-am-frame" title="Add form" allow="camera; fullscreen"></iframe>' +
                    '</div>' +
                '</div>' +
            '</div>';
        document.body.appendChild(modal);
        frame = modal.querySelector('iframe');

        modal.querySelector('[data-am-close]').addEventListener('click', requestClose);

        frame.addEventListener('load', function () {
            if (!frameWanted) { return; }
            var path = '';
            try { path = frame.contentWindow.location.pathname; } catch (e) { /* cross-origin: leave it */ }
            // Session expired → the add page redirected to the login page inside the popup.
            if (/\/login\.php$/i.test(path)) { window.location.href = 'login.php'; return; }
            modal.classList.add('is-loaded');
        });

        jq()(modal).on('hidden.bs.modal', function () {
            frameWanted = false;
            backWaiter = null;
            frame.style.height = '';
            contentHeight = 0;
            modal.classList.remove('is-loaded', 'dms-am-scanning');
            var reload = savedSinceOpen;
            savedSinceOpen = false;
            // First step back over the pop-up's history entries, then blank the form / refresh the list.
            settle(function () {
                frameStep = 0;
                try { if (frame.contentWindow.DmsNav) { frame.contentWindow.DmsNav.allowLeave(); } } catch (e) { /* ignore */ }
                if (!frameWanted) { loadFrame('about:blank'); }
                if (reload) { window.location.reload(); }
            });
        });

        // ESC while focus is on the parent page (e.g. the pop-up header).
        document.addEventListener('keydown', function (e) {
            if (e.key !== 'Escape' && e.key !== 'Esc') { return; }
            if (!modal.classList.contains('show')) { return; }
            if (modal.classList.contains('dms-am-scanning')) { return; } // the scanner handles ESC itself
            if (swalOpen(window)) { return; }
            try { if (swalOpen(frame.contentWindow)) { return; } } catch (err) { /* ignore */ }
            e.preventDefault();
            requestClose();
        });

        window.addEventListener('resize', fitFrame);
        // Phones: size the form once the dialog (and its header) is on screen
        jq()(modal).on('shown.bs.modal', fitFrame);
    }

    // Phones: the pop-up is full screen (css/dms-theme.css), so the form fills it.
    function isPhone() { return window.innerWidth < 576; }

    function maxFrameHeight() {
        var header = modal.querySelector('.modal-header');
        var headH = header ? header.offsetHeight : 64;
        // Leave room for the dialog's top/bottom margins (none on phones).
        var margin = isPhone() ? 0 : 56;
        return Math.max(240, window.innerHeight - headH - margin);
    }

    function fitFrame() {
        if (!frame) { return; }
        if (isPhone()) { frame.style.height = maxFrameHeight() + 'px'; return; }
        if (!contentHeight) { return; }
        frame.style.height = Math.min(contentHeight, maxFrameHeight()) + 'px';
    }

    function hideModal() {
        if (modal) { jq()(modal).modal('hide'); }
    }

    // Close X / ESC: an untouched form closes right away; otherwise run the form's own Cancel
    // (it asks "All unsaved changes will be lost").
    function requestClose() {
        var cancelBtn = null, isDirty = true;
        try {
            cancelBtn = frame.contentDocument && frame.contentDocument.getElementById('cancel_btn');
            if (typeof frame.contentWindow.dmsAddIsDirty === 'function') { isDirty = frame.contentWindow.dmsAddIsDirty(); }
        } catch (e) { /* ignore */ }
        if (cancelBtn && isDirty && modal.classList.contains('is-loaded')) { cancelBtn.click(); } else { hideModal(); }
    }

    // Phone Back while the pop-up is open (js/dms-nav.js): the form first closes its own dialog or
    // scanner, if one is open; otherwise the pop-up closes like the X (asking first if typed into).
    window.dmsAddModalBack = function () {
        if (!modal || !modal.classList.contains('show')) { return; }
        var answered = false;
        backWaiter = function (handled) { answered = true; if (!handled) { requestClose(); } };
        try { frame.contentWindow.postMessage({ type: 'dms-nav-back' }, window.location.origin); } catch (e) { /* ignore */ }
        setTimeout(function () {
            if (!answered && backWaiter) { backWaiter = null; requestClose(); }
        }, 250);
    };

    function openModal(href, title) {
        if (!modal) { buildModal(); }
        var url = href + (href.indexOf('?') === -1 ? '?' : '&') + 'embed=1';
        modal.querySelector('.modal-title').textContent = title || 'New record';
        modal.classList.remove('is-loaded');
        frame.style.height = '';
        contentHeight = 0;
        savedSinceOpen = false;
        frameStep = 0;
        frameWanted = true;
        try { window.sessionStorage.setItem('dms.nav.fstep', '0'); } catch (e) { /* storage blocked */ }
        loadFrame(url);
        jq()(modal).modal({ backdrop: 'static', keyboard: false, show: true });
    }

    window.addEventListener('message', function (e) {
        if (e.origin !== window.location.origin || !modal || !frame || e.source !== frame.contentWindow) { return; }
        var data = e.data || {};
        if (data.type === 'dms-add-saved') {
            savedSinceOpen = true; // the form reloads itself empty; the list refreshes on close
        } else if (data.type === 'dms-add-cancel') {
            hideModal();
        } else if (data.type === 'dms-scan-open' || data.type === 'dms-scan-close') {
            // The document scanner opened inside the form: give it the whole screen.
            modal.classList.toggle('dms-am-scanning', data.type === 'dms-scan-open');
        } else if (data.type === 'dms-add-esc') {
            if (modal.classList.contains('show') && !modal.classList.contains('dms-am-scanning')) { requestClose(); }
        } else if (data.type === 'dms-nav-step') {
            frameStep = data.step || 0;
            // Back was pressed inside the form after a save (it went back to its earlier copy): close.
            if (data.back && modal.classList.contains('show')) { requestClose(); }
        } else if (data.type === 'dms-nav-back-done') {
            if (backWaiter) { var w = backWaiter; backWaiter = null; w(!!data.handled); }
        } else if (data.type === 'dms-add-height' && data.height) {
            contentHeight = data.height;
            fitFrame();
        }
    });

    document.addEventListener('click', function (e) {
        if (inFrame) { return; }
        var link = e.target.closest ? e.target.closest('[data-add-modal]') : null;
        if (!link) { return; }
        // Let modified clicks (new tab/window) behave like a normal link.
        if (e.button !== 0 || e.ctrlKey || e.metaKey || e.shiftKey || e.altKey) { return; }
        var href = link.getAttribute('href') || link.getAttribute('data-href');
        if (!href || !jq() || !jq().fn || !jq().fn.modal) { return; } // no Bootstrap → plain navigation
        e.preventDefault();
        openModal(href, link.getAttribute('data-add-title'));
    });

    function startChild() { startHeightReports(); startChildKeys(); }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', startChild);
    } else {
        startChild();
    }
})();
