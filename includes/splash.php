<?php
/**
 * Start-up splash screen (Sangguniang Bayan seal) shown over the login page.
 *
 * Included right after <body> on login.php. It plays the first time the app is
 * opened in a tab (sessionStorage flag) and on every page refresh. Redirects
 * back to login.php (failed login, logout) skip it. Styles live in
 * css/dms-theme.css under "Splash screen".
 *
 * While html.dms-splash-on is set, the login card is hidden and its entrance
 * animation is held back; removing the class plays the login's normal fade-in.
 * Without JavaScript the overlay stays hidden and the login shows as usual.
 * A click/tap or Enter/Esc/Space skips it; when it is gone the document gets a
 * "dms:splash-done" event (js/auth-forms.js then focuses the username field).
 */
?>
<script>
(function () {
    var root = document.documentElement, show = false, reload = false;
    try {
        var nav = performance.getEntriesByType && performance.getEntriesByType('navigation')[0];
        reload = nav ? nav.type === 'reload'
                     : (performance.navigation && performance.navigation.type === 1);
    } catch (e) { reload = false; }
    try {
        if (reload || !sessionStorage.getItem('dmsSplashSeen')) {
            sessionStorage.setItem('dmsSplashSeen', '1');
            show = true;
        }
    } catch (e) { show = reload; }
    if (show) root.classList.add('dms-splash', 'dms-splash-on');
})();
</script>
<div id="dmsSplash" class="dms-splash-screen" aria-hidden="true">
    <img class="dms-splash-logo" src="./images/logo.png" alt="" width="1563" height="1563" decoding="async">
</div>
<script>
(function () {
    var root = document.documentElement;
    var splash = document.getElementById('dmsSplash');
    if (!splash) return;
    if (!root.classList.contains('dms-splash-on')) { splash.parentNode.removeChild(splash); return; }

    // Reduced motion: a much shorter hold (the fades are already simplified in CSS)
    var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    var FADE_IN = reduce ? 400 : 900, HOLD = reduce ? 600 : 2000, FADE_OUT = 600;
    var logo = splash.querySelector('.dms-splash-logo');
    var started = false, leaving = false, finished = false, holdTimer = null;

    function finish() {
        if (finished) return;
        finished = true;
        document.removeEventListener('keydown', onKey);
        if (splash.parentNode) splash.parentNode.removeChild(splash);
        root.classList.remove('dms-splash-on'); // login card now plays its own fade-in
        // js/auth-forms.js waits for this before focusing the first field
        var ev;
        try { ev = new CustomEvent('dms:splash-done'); }
        catch (e) { ev = document.createEvent('Event'); ev.initEvent('dms:splash-done', false, false); }
        document.dispatchEvent(ev);
    }

    function fadeOut() {
        if (leaving) return;
        leaving = true;
        clearTimeout(holdTimer);
        splash.classList.add('is-out');
        splash.addEventListener('transitionend', function (e) {
            if (e.target === splash) finish();
        });
        setTimeout(finish, FADE_OUT + 200); // fallback if transitionend never fires
    }

    function start() {
        if (started) return;
        started = true;
        // Next frame so the initial (hidden) state is painted before transitioning
        requestAnimationFrame(function () {
            requestAnimationFrame(function () {
                splash.classList.add('is-in');
                holdTimer = setTimeout(fadeOut, FADE_IN + HOLD);
            });
        });
    }

    // Skip: click/tap anywhere, or Enter / Esc / Space
    function onKey(e) {
        if (e.key === 'Enter' || e.key === 'Escape' || e.key === 'Esc' || e.key === ' ' || e.key === 'Spacebar') {
            e.preventDefault();
            fadeOut();
        }
    }
    splash.addEventListener('click', fadeOut);
    document.addEventListener('keydown', onKey);

    // Wait for the seal to load so it never pops in half-drawn; don't wait forever
    if (logo.complete && logo.naturalWidth) start();
    else {
        logo.addEventListener('load', start);
        logo.addEventListener('error', start);
        setTimeout(start, 1500);
    }
})();
</script>
