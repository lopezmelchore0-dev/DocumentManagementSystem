/*
 * Back navigation that feels like an app (phone Back button, iOS/iPadOS swipe-back, browser Back).
 *
 * Loaded from header.php on every signed-in page. Plain JavaScript on purpose: the list pages load a
 * second jQuery (vendor/global/global.min.js), so window.jQuery is only read when it is needed.
 *
 *  1. Overlays close first. While the phone sidebar drawer, a header menu, a row menu, a pop-up
 *     (Bootstrap modal, the "New …" form, Reports), a SweetAlert dialog or the scanner is open, one
 *     extra history entry sits on top of the page. Back removes that entry, and that closes the top
 *     overlay instead of leaving the page. Closing the overlay any other way (×, Esc, OK) removes
 *     the entry again, so history never fills up with dead entries.
 *  2. Pages come back as they were. Search, sorting, page and page length of every DataTable, the
 *     Reports filters that narrow the list, and the scroll position are saved per history entry
 *     (sessionStorage) and restored when the entry is reached with Back/Forward or a reload. Plain
 *     navigation (sidebar, links) still opens pages fresh. Tabs and the Table/Timeline view are
 *     remembered by their own scripts.
 *  3. No duplicate entries. DmsNav.returnTo(url) (Cancel, after a save) goes back to the list page
 *     already in history instead of stacking a new copy on top; DmsNav.go(url, {replace}) navigates
 *     after removing an overlay entry; links to the page you are on replace it.
 *  4. Unsaved forms. On add/edit pages (#cancel_btn) the browser asks "Leave site?" when the user
 *     leaves with unsaved typing. Save, a confirmed Cancel and dmsAddDone() switch this off.
 *
 * Inside the "New …" pop-up (iframe .dms-am-frame, js/add-modal.js) only a small "child" part runs:
 * it numbers the form's own history entries and tells the parent when Back was pressed inside it.
 *
 * Nothing here runs when the page is the first one in the tab: Back then does what the browser does.
 */
(function () {
    'use strict';

    if (window.__dmsNavReady) { return; }
    window.__dmsNavReady = true;

    var H = window.history;
    var root = document.documentElement;

    function plainGo(url, opts) {
        if (opts && opts.replace) { window.location.replace(url); } else { window.location.href = url; }
    }

    if (!H || typeof H.pushState !== 'function' || typeof H.replaceState !== 'function') {
        window.DmsNav = {
            go: plainGo,
            returnTo: function (url) { plainGo(url, { replace: true }); },
            reload: function () { window.location.reload(); },
            settle: function (fn) { if (fn) { fn(); } },
            allowLeave: function () {},
            markReplace: function () {},
            isDirty: function () { return false; }
        };
        return;
    }

    /* ---------------- helpers ---------------- */

    var store = {
        get: function (k) { try { return window.sessionStorage.getItem(k); } catch (e) { return null; } },
        set: function (k, v) { try { window.sessionStorage.setItem(k, v); } catch (e) { /* storage blocked */ } },
        del: function (k) { try { window.sessionStorage.removeItem(k); } catch (e) { /* storage blocked */ } },
        json: function (k) { try { return JSON.parse(this.get(k) || 'null'); } catch (e) { return null; } },
        put: function (k, v) { try { this.set(k, JSON.stringify(v)); } catch (e) { /* storage blocked */ } }
    };

    var K = {
        trail: 'dms.nav.trail',     // depth → { e: entry id, u: path + query } for this tab
        cur: 'dms.nav.cur',         // the page shown last: { d: depth, e: entry id }
        repl: 'dms.nav.replace',    // the next page load replaces the current entry
        expect: 'dms.nav.expect',   // returnTo() fallback: the entry it is travelling to
        states: 'dms.nav.states',   // ids of the entries that have saved page state (oldest first)
        state: 'dms.nav.state.',    // + entry id → saved page state
        fstep: 'dms.nav.fstep',     // pop-up form: number of its last document
        frepl: 'dms.nav.frepl'      // pop-up form: the next load replaces the current one
    };

    function state() {
        var s = H.state;
        return (s && typeof s === 'object') ? s : {};
    }

    // Copy of a, with b's keys set (undefined removes the key)
    function merge(a, b) {
        var o = {}, k;
        for (k in a) { if (Object.prototype.hasOwnProperty.call(a, k)) { o[k] = a[k]; } }
        for (k in b) {
            if (!Object.prototype.hasOwnProperty.call(b, k)) { continue; }
            if (b[k] === undefined) { delete o[k]; } else { o[k] = b[k]; }
        }
        return o;
    }

    // How this document was reached: navigate | reload | back_forward
    function navType() {
        try {
            var nav = window.performance.getEntriesByType('navigation')[0];
            if (nav && nav.type) { return nav.type; }
        } catch (e) { /* old browser */ }
        try {
            var t = window.performance.navigation.type;
            return t === 1 ? 'reload' : (t === 2 ? 'back_forward' : 'navigate');
        } catch (e) { /* old browser */ }
        return 'navigate';
    }

    function pressEscape(target) {
        var ev;
        try {
            ev = new KeyboardEvent('keydown', { key: 'Escape', code: 'Escape', keyCode: 27, which: 27, bubbles: true, cancelable: true });
        } catch (e) { return; }
        target.dispatchEvent(ev);
    }

    // A SweetAlert dialog (not a toast) is on screen and not already closing
    function swalVisible() {
        return !!document.querySelector('.swal2-container .swal2-popup.swal2-modal:not(.swal2-hide)');
    }

    function swalBusy() {
        try { return !!(window.Swal && window.Swal.isLoading && window.Swal.isLoading()); } catch (e) { return false; }
    }

    function closeSwal() {
        if (swalBusy()) { return; } // e.g. checking a password: let it finish
        try { if (window.Swal && window.Swal.close) { window.Swal.close(); } } catch (e) { /* ignore */ }
        // A dialog opened by another copy of SweetAlert: its own Esc handling closes it.
        setTimeout(function () { if (swalVisible() && !swalBusy()) { pressEscape(document); } }, 80);
    }

    var type = navType();
    var frameEl = null;
    try { frameEl = window.frameElement; } catch (e) { frameEl = null; }
    var inPopup = !!(frameEl && frameEl.classList && frameEl.classList.contains('dms-am-frame'));
    var inFrame = window.parent && window.parent !== window;

    /* ---------------- unsaved-form guard (add/edit pages) ---------------- */

    var guard = { form: null, dirty: false, allowed: false };

    function onBeforeUnload(e) {
        if (!guard.dirty || guard.allowed) { return undefined; }
        e.preventDefault();
        e.returnValue = '';
        return '';
    }

    function setDirty() {
        if (guard.dirty || guard.allowed) { return; }
        guard.dirty = true;
        window.addEventListener('beforeunload', onBeforeUnload);
    }

    function allowLeave() {
        guard.allowed = true;
        window.removeEventListener('beforeunload', onBeforeUnload);
    }

    function startGuard() {
        var cancel = document.getElementById('cancel_btn');
        if (!cancel) { return; }
        guard.form = (cancel.closest && cancel.closest('form')) || document.querySelector('.content-body form');
        if (!guard.form) { return; }
        // Only the user's own typing/choices count (scripts that fill fields on load fire untrusted events).
        function onEdit(e) {
            if (e.isTrusted === false || !e.target || !guard.form.contains(e.target)) { return; }
            setDirty();
        }
        document.addEventListener('input', onEdit, true);
        document.addEventListener('change', onEdit, true);
        // select2 lists live outside the form and change the field through jQuery only.
        function onPick(e) {
            if (e.isTrusted === false || !e.target || !e.target.closest) { return; }
            if (e.target.closest('.select2-results__option')) { setDirty(); }
        }
        document.addEventListener('mouseup', onPick, true);
        document.addEventListener('touchend', onPick, true);
        // Saving leaves on purpose: a submit nobody stopped, or a script calling form.submit().
        window.addEventListener('submit', function (e) {
            if (e.target === guard.form && !e.defaultPrevented) { allowLeave(); }
        });
        var nativeSubmit = HTMLFormElement.prototype.submit;
        HTMLFormElement.prototype.submit = function () {
            if (this === guard.form) { allowLeave(); }
            return nativeSubmit.apply(this, arguments);
        };
    }

    function onReady(fn) {
        if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', fn); } else { fn(); }
    }

    onReady(startGuard);

    /* ================= pop-up form (child) ================= */

    if (inPopup) {
        (function () {
            var s = state(), step;
            if (s.dmsFrameStep) {
                step = s.dmsFrameStep;
            } else {
                var last = parseInt(store.get(K.fstep), 10) || 0;
                step = (store.get(K.frepl) === '1' && last) ? last : last + 1;
                H.replaceState(merge(s, { dmsFrameStep: step }), '');
            }
            store.del(K.frepl);
            store.set(K.fstep, String(step));

            function post(msg) {
                try { window.parent.postMessage(msg, window.location.origin); } catch (e) { /* ignore */ }
            }
            // back: the form was reloaded by Back after a save → the parent closes the pop-up.
            post({ type: 'dms-nav-step', step: step, back: type === 'back_forward' && !!s.dmsFrameStep });

            // Back pressed on the parent: close this form's own dialog or scanner first, if any.
            window.addEventListener('message', function (e) {
                if (e.origin !== window.location.origin || e.source !== window.parent) { return; }
                if (!e.data || e.data.type !== 'dms-nav-back') { return; }
                var handled = false;
                if (swalVisible()) {
                    handled = true;
                    closeSwal();
                } else if (root.classList.contains('dms-scan-open')) {
                    handled = true;
                    pressEscape(window);
                }
                post({ type: 'dms-nav-back-done', handled: handled });
            });
        })();

        window.DmsNav = {
            go: function (url, opts) { plainGo(url, opts); },
            returnTo: function (url) { allowLeave(); plainGo(url, { replace: true }); },
            reload: function () { window.location.reload(); },
            settle: function (fn) { if (fn) { fn(); } },
            allowLeave: allowLeave,
            markReplace: function () { store.set(K.frepl, '1'); },
            isDirty: function () { return guard.dirty && !guard.allowed; }
        };
        return;
    }

    if (inFrame) {
        // Some other frame (not ours): keep the API, touch nothing.
        window.DmsNav = {
            go: plainGo,
            returnTo: function (url) { allowLeave(); plainGo(url, { replace: true }); },
            reload: function () { window.location.reload(); },
            settle: function (fn) { if (fn) { fn(); } },
            allowLeave: allowLeave,
            markReplace: function () {},
            isDirty: function () { return guard.dirty && !guard.allowed; }
        };
        return;
    }

    /* ================= top-level page ================= */

    // Navigation API (Chrome/Edge/Samsung Internet on Android, desktop Chromium): exact history list.
    var NAV = (window.navigation && typeof window.navigation.traverseTo === 'function' &&
               typeof window.navigation.entries === 'function') ? window.navigation : null;

    try { H.scrollRestoration = 'manual'; } catch (e) { /* not supported: browser default */ }

    function here() { return window.location.pathname + window.location.search; }
    function newId() { return Date.now().toString(36) + Math.random().toString(36).slice(2, 7); }

    /* ---------- 1. which history entry is this? ---------- */

    var entry, depth, trail = store.json(K.trail) || {};

    (function stamp() {
        var s = state();
        if (s.dmsEntry) {
            // Back/Forward or reload: the entry keeps its id (and saved state)
            entry = s.dmsEntry;
            depth = s.dmsDepth || 1;
        } else {
            entry = newId();
            var cur = store.json(K.cur);
            var replaced = store.get(K.repl) === '1';
            if (H.length <= 1 || !cur) {
                depth = 1;
                trail = {};
            } else {
                depth = replaced ? cur.d : cur.d + 1;
            }
            if (!replaced) {
                // A new entry drops everything that was "forward" of the previous page
                for (var d in trail) {
                    if (Object.prototype.hasOwnProperty.call(trail, d) && +d >= depth) { delete trail[d]; }
                }
            }
        }
        store.del(K.repl);
        // dmsOverlay: an overlay entry left behind by a page script that navigated away with a dialog
        // open (only possible without the Navigation API). Reached with Back from a later page, step
        // over it to the page's own entry; otherwise it becomes an ordinary entry again.
        // ('reload' too: a page restored from the back/forward cache is reloaded by header.php.)
        if (s.dmsOverlay && (type === 'back_forward' || type === 'reload')) {
            var prev = store.json(K.cur);
            if (prev && prev.d > depth) { H.back(); }
        }
        H.replaceState(merge(s, { dmsEntry: entry, dmsDepth: depth, dmsOverlay: undefined }), '');

        // returnTo() without the Navigation API: did we land where we meant to?
        var expect = store.json(K.expect);
        store.del(K.expect);
        if (expect && expect.e && expect.e !== entry && expect.u) {
            store.set(K.repl, '1');
            window.location.replace(expect.u);
        }
    })();

    function record() {
        trail[depth] = { e: entry, u: here() };
        store.put(K.trail, trail);
        store.put(K.cur, { d: depth, e: entry });
    }
    record();

    // "?msg=…" after a delete is only for that one response: drop it so Back/reload stay clean.
    if (/[?&]msg=/.test(window.location.search)) {
        try {
            var clean = new URL(window.location.href);
            clean.searchParams.delete('msg');
            H.replaceState(state(), '', clean.pathname + clean.search + clean.hash);
            record();
        } catch (e) { /* old browser: leave the address */ }
    }

    // A page shown again from the back/forward cache (header.php reloads signed-in pages, but not
    // every browser fires that in time): it is not leaving any more and is the current page again.
    window.addEventListener('pageshow', function (e) {
        if (!e.persisted) { return; }
        ov.queue = [];
        ov.travelling = false;
        if (window.__dmsBfReload) { ov.leaving = true; return; } // header.php is reloading it: hands off
        ov.leaving = false;
        ov.pushed = !!state().dmsOverlay;
        record();
        setTimeout(schedule, 300);
    });

    /* ---------- 2. page state: save on leave, restore on Back/Forward/reload ---------- */

    function saveState() {
        var data = { y: Math.round(window.pageYOffset || root.scrollTop || 0), t: {}, f: {} };
        var $ = window.jQuery;
        if ($ && $.fn && $.fn.dataTable && $.fn.dataTable.tables) {
            try {
                $.each($.fn.dataTable.tables(), function (i, node) {
                    if (!node.id) { return; }
                    var dt = $(node).DataTable();
                    data.t[node.id] = { s: dt.search(), o: dt.order(), l: dt.page.len(), p: dt.page() };
                });
            } catch (e) { /* table being rebuilt */ }
        }
        // Reports pop-up filters (they also narrow the list table); paper/orientation are remembered elsewhere.
        var fields = document.querySelectorAll('[data-report-toolbar] input[id], [data-report-toolbar] select[id]');
        for (var i = 0; i < fields.length; i++) {
            var rt = fields[i].getAttribute('data-rt');
            if (rt === 'paper' || rt === 'orient' || fields[i].type === 'file') { continue; }
            data.f[fields[i].id] = fields[i].value;
        }
        store.put(K.state + entry, data);
        var list = store.json(K.states) || [];
        list = list.filter(function (x) { return x !== entry; });
        list.push(entry);
        while (list.length > 40) { store.del(K.state + list.shift()); }
        store.put(K.states, list);
    }

    window.addEventListener('pagehide', saveState);
    document.addEventListener('visibilitychange', function () {
        if (document.visibilityState === 'hidden') { saveState(); } // iOS may never fire pagehide
    });

    (function restoreState() {
        if (type !== 'back_forward' && type !== 'reload') { return; }
        if (/[?&]locate=/.test(window.location.search)) { return; } // "Show in list" decides the position
        var data = store.json(K.state + entry);
        if (!data) { return; }

        var userMoved = false;
        function moved() { userMoved = true; }
        ['wheel', 'touchstart', 'keydown', 'mousedown'].forEach(function (ev) {
            window.addEventListener(ev, moved, { passive: true, once: true });
        });

        function scrollBack() {
            if (!userMoved && data.y) { window.scrollTo(0, data.y); }
        }

        // Wait for the page's own DataTables (built in $(document).ready) before applying their state.
        function tablesReady() {
            var ids = Object.keys(data.t || {});
            if (!ids.length) { return true; }
            var $ = window.jQuery;
            if (!$ || !$.fn || !$.fn.dataTable) { return false; }
            for (var i = 0; i < ids.length; i++) {
                var node = document.getElementById(ids[i]);
                if (node && !$.fn.dataTable.isDataTable(node)) { return false; }
            }
            return true;
        }

        function defaultValue(el) {
            if (el.tagName !== 'SELECT') { return el.defaultValue; }
            for (var i = 0; i < el.options.length; i++) { if (el.options[i].defaultSelected) { return el.options[i].value; } }
            return el.options.length ? el.options[0].value : '';
        }

        function applyFilters() {
            var f = data.f || {};
            Object.keys(f).forEach(function (id) {
                var el = document.getElementById(id);
                if (!el) { return; }
                // The browser may already have put the value back without telling the page: whenever
                // it is not the default, announce it so the filters are applied to the table.
                if (el.value !== f[id]) { el.value = f[id]; }
                if (el.value !== f[id] || el.value === defaultValue(el)) { return; }
                el.dispatchEvent(new Event('input', { bubbles: true }));
                el.dispatchEvent(new Event('change', { bubbles: true }));
            });
        }

        function applyTables() {
            var $ = window.jQuery;
            if (!$ || !$.fn || !$.fn.dataTable) { return; }
            Object.keys(data.t || {}).forEach(function (id) {
                var node = document.getElementById(id);
                if (!node || !$.fn.dataTable.isDataTable(node)) { return; }
                var t = data.t[id];
                try {
                    var dt = $(node).DataTable();
                    if (t.l && dt.page.len() !== t.l) { dt.page.len(t.l); }
                    if (typeof t.s === 'string' && dt.search() !== t.s) { dt.search(t.s); }
                    if (t.o && JSON.stringify(dt.order()) !== JSON.stringify(t.o)) { dt.order(t.o); }
                    dt.draw(false);
                    if (t.p > 0 && t.p < dt.page.info().pages) { dt.page(t.p).draw('page'); }
                } catch (e) { /* columns changed since: leave the table as it is */ }
            });
        }

        var tries = 0;
        function apply() {
            if (!tablesReady() && tries++ < 60) { setTimeout(apply, 50); return; }
            applyFilters();
            applyTables();
            scrollBack();
            window.requestAnimationFrame(scrollBack);
            setTimeout(scrollBack, 300); // fonts and images settle the layout
            if (document.readyState !== 'complete') { window.addEventListener('load', scrollBack, { once: true }); }
        }

        onReady(function () { setTimeout(apply, 30); });
    })();

    /* ---------- 3. overlays close on Back ---------- */

    var ov = {
        pushed: false,      // our overlay entry is the current history entry
        baseKey: null,      // Navigation API key of the entry under it
        travelling: false,  // we are moving back over it ourselves
        queue: [],          // run once we are back on the page's own entry
        closeTimer: 0,
        closingUntil: 0,    // a Back is closing an overlay right now
        leaving: false,     // the page is navigating away
        safety: 0
    };

    function topModal() {
        var list = document.querySelectorAll('.modal.show'), top = null;
        for (var i = 0; i < list.length; i++) {
            // The "New …" pop-up counts once its form has loaded (its first load must not add history).
            if (list[i].id === 'dmsAddModal' && !list[i].classList.contains('is-loaded')) { continue; }
            top = list[i];
        }
        return top;
    }

    function openMenu() {
        return document.querySelector('.dms-ob-menu:not([hidden])');
    }

    // Open overlays, topmost first
    function openKinds() {
        var k = [];
        if (swalVisible()) { k.push('swal'); }
        if (root.classList.contains('dms-scan-open')) { k.push('scan'); }
        if (topModal()) { k.push('modal'); }
        if (openMenu()) { k.push('menu'); }
        if (document.querySelector('#dmsHeader .dms-has-drop.is-open')) { k.push('drop'); }
        if (root.classList.contains('dms-sb-open')) { k.push('drawer'); }
        return k;
    }

    function push() {
        try { ov.baseKey = NAV && NAV.currentEntry ? NAV.currentEntry.key : null; } catch (e) { ov.baseKey = null; }
        H.pushState(merge(state(), { dmsOverlay: true }), '');
        ov.pushed = true;
    }

    function flush() {
        var q = ov.queue;
        ov.queue = [];
        for (var i = 0; i < q.length; i++) {
            try { q[i](); } catch (e) { if (window.console) { window.console.error(e); } }
        }
    }

    function landed() {
        clearTimeout(ov.safety);
        ov.travelling = false;
        record();
        flush();
        schedule();
    }

    function baseExists() {
        if (!NAV || !ov.baseKey) { return false; }
        try {
            var list = NAV.entries();
            for (var i = 0; i < list.length; i++) { if (list[i].key === ov.baseKey) { return true; } }
        } catch (e) { /* ignore */ }
        return false;
    }

    // Entries to go back over: the overlay entry plus any the pop-up form added (a save posts the form).
    function stepsBack() {
        var f = 0;
        try { if (typeof window.dmsAddFrameStep === 'function') { f = window.dmsAddFrameStep() || 0; } } catch (e) { f = 0; }
        return Math.max(1, f);
    }

    // Remove our overlay entry (if it is on top), then run `after`.
    function settle(after) {
        if (after) { ov.queue.push(after); }
        if (ov.travelling) { return; }
        if (!ov.pushed) { flush(); return; }
        ov.pushed = false;
        clearTimeout(ov.closeTimer);
        ov.closeTimer = 0;
        ov.travelling = true;
        var done = false;
        if (baseExists()) {
            try {
                var r = NAV.traverseTo(ov.baseKey);
                done = true;
                if (r && r.finished && r.finished.catch) { r.finished.catch(function () { /* popstate/safety handles it */ }); }
            } catch (e) { done = false; }
        }
        if (!done) { H.go(-stepsBack()); }
        ov.safety = setTimeout(landed, 900); // no popstate came (blocked): carry on anyway
    }

    function detect() {
        if (ov.leaving || ov.travelling) { return; }
        if (Date.now() < ov.closingUntil) { return; }
        var open = openKinds().length > 0;
        if (open) {
            clearTimeout(ov.closeTimer);
            ov.closeTimer = 0;
            if (!ov.pushed) { push(); }
        } else if (ov.pushed && !ov.closeTimer) {
            // Short delay: one dialog closing and the next opening (Swal → Swal) share the entry.
            ov.closeTimer = setTimeout(function () {
                ov.closeTimer = 0;
                if (!ov.leaving && !ov.travelling && ov.pushed && !openKinds().length) { settle(); }
            }, 150);
        }
    }

    var raf = 0;
    function schedule() {
        if (raf) { return; }
        raf = window.requestAnimationFrame(function () { raf = 0; detect(); });
    }

    function closeModal(m) {
        if (m.id === 'dmsAddModal' && typeof window.dmsAddModalBack === 'function') { window.dmsAddModalBack(); return; }
        var btn = m.querySelector('[data-dismiss="modal"], [data-bs-dismiss="modal"], .close, .btn-close');
        if (btn) { btn.click(); return; }
        var $ = window.jQuery;
        if ($ && $.fn && $.fn.modal) { $(m).modal('hide'); }
    }

    // The user pressed Back with something open: close the topmost overlay.
    function closeTop() {
        ov.closingUntil = Date.now() + 400;
        setTimeout(schedule, 420); // still open (or a confirm appeared)? → the entry comes back
        var kind = openKinds()[0];
        if (kind === 'swal') {
            closeSwal();
        } else if (kind === 'scan') {
            pressEscape(window); // the scanner steps back screen by screen, like Esc
        } else if (kind === 'modal') {
            closeModal(topModal());
        } else if (kind === 'menu') {
            var menu = openMenu();
            if (menu) { pressEscape(menu); }
        } else if (kind === 'drop') {
            if (window.DmsShell) { window.DmsShell.closeDrops(); }
        } else if (kind === 'drawer') {
            if (window.DmsShell) { window.DmsShell.closeDrawer(); }
        }
    }

    window.addEventListener('popstate', function (e) {
        if (ov.travelling) { landed(); return; }
        var s = (e.state && typeof e.state === 'object') ? e.state : {};
        if (ov.pushed && !s.dmsOverlay) {
            ov.pushed = false;
            closeTop();
        } else if (!ov.pushed && s.dmsOverlay) {
            // Forward onto an overlay entry that was already used: step back over it.
            ov.travelling = true;
            H.back();
            ov.safety = setTimeout(landed, 900);
        }
    });

    function leave() {
        ov.leaving = true;
        // Still here after a while (the link was a download, or the navigation was cancelled)
        setTimeout(function () { ov.leaving = false; schedule(); }, 4000);
    }

    function go(url, opts) {
        opts = opts || {};
        leave();
        settle(function () {
            if (opts.replace) { store.set(K.repl, '1'); window.location.replace(url); } else { window.location.href = url; }
        });
    }

    function reload() {
        leave();
        settle(function () { window.location.reload(); });
    }

    // The nearest earlier entry (a few steps at most) showing `path`
    function navApiTarget(path) {
        if (!NAV || !NAV.currentEntry) { return null; }
        try {
            var list = NAV.entries(), idx = NAV.currentEntry.index;
            for (var i = idx - 1; i >= 0 && i >= idx - 8; i--) {
                if (!list[i].url) { continue; }
                if (new URL(list[i].url).pathname.toLowerCase() === path) { return list[i].key; }
            }
        } catch (e) { /* ignore */ }
        return null;
    }

    function returnTo(url, opts) {
        opts = opts || {};
        if (!opts.keepGuard) { allowLeave(); }
        var target;
        try { target = new URL(url, window.location.href); } catch (e) { window.location.href = url; return; }
        var path = target.pathname.toLowerCase();
        leave();
        settle(function () {
            var key = navApiTarget(path);
            if (key) {
                try {
                    var r = NAV.traverseTo(key);
                    if (r && r.finished && r.finished.catch) { r.finished.catch(function () { /* cancelled by "Leave site?" */ }); }
                    return;
                } catch (e) { /* fall through */ }
            }
            for (var d = depth - 1; d >= 1 && d >= depth - 5; d--) {
                var t = trail[d];
                if (!t) { break; }
                if (String(t.u).split('?')[0].toLowerCase() === path) {
                    store.put(K.expect, { e: t.e, u: target.href });
                    H.go(d - depth);
                    return;
                }
            }
            store.set(K.repl, '1');
            window.location.replace(target.href);
        });
    }

    // Links: leave an overlay entry behind cleanly, and don't stack the page you are already on.
    document.addEventListener('click', function (e) {
        if (e.defaultPrevented || e.button !== 0 || e.ctrlKey || e.metaKey || e.shiftKey || e.altKey) { return; }
        var a = e.target && e.target.closest ? e.target.closest('a[href]') : null;
        if (!a || a.hasAttribute('download')) { return; }
        if (a.target && a.target.toLowerCase() !== '_self') { return; }
        var raw = a.getAttribute('href') || '';
        if (!raw || raw.charAt(0) === '#' || /^(javascript|mailto|tel):/i.test(raw)) { return; }
        var u;
        try { u = new URL(a.href, window.location.href); } catch (err) { return; }
        if (u.origin !== window.location.origin) { return; }
        var same = u.pathname === window.location.pathname && u.search === window.location.search;
        if (same && u.hash) { return; } // in-page anchor
        if (a.hasAttribute('data-dms-return')) { e.preventDefault(); returnTo(u.href, { keepGuard: true }); return; }
        if (!same && !ov.pushed) { return; } // ordinary link: the browser handles it
        e.preventDefault();
        go(u.href, { replace: same });
    });

    // Forms submitted from inside an overlay (e.g. a pop-up form): remove the overlay entry first.
    var formSubmit = HTMLFormElement.prototype.submit;
    window.addEventListener('submit', function (e) {
        if (!ov.pushed || ov.leaving || e.defaultPrevented) { return; }
        var f = e.target;
        if (!f || (f.target && f.target.toLowerCase() !== '_self')) { return; }
        e.preventDefault();
        var sub = e.submitter && e.submitter.form === f ? e.submitter : null;
        leave();
        settle(function () {
            if (typeof f.requestSubmit === 'function') {
                try { if (sub) { f.requestSubmit(sub); } else { f.requestSubmit(); } return; } catch (err) { /* fall back */ }
            }
            if (sub && sub.name) {
                var hidden = document.createElement('input');
                hidden.type = 'hidden';
                hidden.name = sub.name;
                hidden.value = sub.value;
                f.appendChild(hidden);
            }
            formSubmit.call(f);
        });
    });

    // Chromium: catch every other script navigation (location.href = … after a dialog's OK) too.
    if (NAV && typeof NAV.addEventListener === 'function') {
        NAV.addEventListener('navigate', function (e) {
            if (e.hashChange || (e.destination && e.destination.sameDocument)) { return; }
            if (e.navigationType === 'traverse') { return; }
            if (e.navigationType === 'replace') { store.set(K.repl, '1'); }
            if (!ov.pushed || ov.leaving || !e.cancelable || e.formData || e.downloadRequest) { return; }
            e.preventDefault();
            if (e.navigationType === 'reload') { reload(); } else { go(e.destination.url, { replace: e.navigationType === 'replace' }); }
        });
    }

    onReady(function () {
        if (!window.MutationObserver) { return; }
        new MutationObserver(schedule).observe(root, {
            subtree: true, childList: true, attributes: true, attributeFilter: ['class', 'hidden']
        });
        schedule();
    });

    window.DmsNav = {
        go: go,
        returnTo: returnTo,
        reload: reload,
        settle: settle,
        allowLeave: allowLeave,
        markReplace: function () { store.set(K.repl, '1'); },
        isDirty: function () { return guard.dirty && !guard.allowed; }
    };
})();
