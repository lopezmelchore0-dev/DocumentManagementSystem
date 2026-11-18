/*
 * Press-and-hold = right-click, for phones and tablets.
 *
 * Several lists open their row menu on right-click (contextmenu): Order of
 * Business status, bulk actions, Manage Accounts, History. Touch screens have
 * no right button (iOS never fires contextmenu), so holding a finger on a table
 * row for about half a second sends the same contextmenu event at that spot.
 * The click that would follow the hold is swallowed so the row does not also
 * open or toggle. Android's own long-press contextmenu is used when it comes
 * first, and never doubled. Loaded deferred from sidebar.php.
 */
(function (window, document) {
    'use strict';

    var touchCapable = ('ontouchstart' in window) || (navigator.maxTouchPoints > 0);
    if (!touchCapable) { return; }

    var HOLD_MS = 550;
    var MOVE_TOLERANCE = 10; // px
    var ROW_SELECTOR = 'table tbody tr, [data-longpress]';
    var SKIP_SELECTOR = 'a, button, input, select, textarea, label, .dms-row-actions';

    var timer = null;
    var active = false;   // a finger is down on a row
    var fired = false;    // the hold already produced a contextmenu
    var startX = 0, startY = 0, target = null;

    function cancelTimer() {
        if (timer) { clearTimeout(timer); timer = null; }
    }

    document.addEventListener('touchstart', function (e) {
        cancelTimer();
        fired = false;
        active = false;
        if (e.touches.length !== 1) { return; }
        var el = e.target;
        if (!el.closest || !el.closest(ROW_SELECTOR) || el.closest(SKIP_SELECTOR)) { return; }

        active = true;
        target = el;
        startX = e.touches[0].clientX;
        startY = e.touches[0].clientY;
        timer = setTimeout(function () {
            timer = null;
            fired = true;
            var ev;
            try {
                ev = new MouseEvent('contextmenu', {
                    bubbles: true, cancelable: true, view: window,
                    clientX: startX, clientY: startY, screenX: startX, screenY: startY,
                    button: 2, buttons: 2
                });
            } catch (err) {
                ev = document.createEvent('MouseEvents');
                ev.initMouseEvent('contextmenu', true, true, window, 1, startX, startY, startX, startY,
                    false, false, false, false, 2, null);
            }
            target.dispatchEvent(ev);
            if (navigator.vibrate) { try { navigator.vibrate(12); } catch (err) { /* ignore */ } }
        }, HOLD_MS);
    }, { passive: true });

    document.addEventListener('touchmove', function (e) {
        if (!timer || !e.touches.length) { return; }
        var dx = e.touches[0].clientX - startX, dy = e.touches[0].clientY - startY;
        if (Math.abs(dx) > MOVE_TOLERANCE || Math.abs(dy) > MOVE_TOLERANCE) { cancelTimer(); active = false; }
    }, { passive: true });

    var swallowUntil = 0;
    function end(e) {
        cancelTimer();
        if (fired) {
            if (e.cancelable) { e.preventDefault(); } // no click after a hold
            swallowUntil = Date.now() + 450;
        }
        fired = false;
        active = false;
    }
    document.addEventListener('touchend', end, { passive: false });
    document.addEventListener('touchcancel', end, { passive: true });

    // The browser's own long-press menu (Android) during our hold: let the first one through
    // and stop our timer; if ours already fired, drop the browser's duplicate.
    document.addEventListener('contextmenu', function (e) {
        if (!e.isTrusted || !active) { return; }
        if (fired) {
            e.preventDefault();
            e.stopImmediatePropagation();
            return;
        }
        cancelTimer();
        fired = true;
    }, true);

    // Belt and braces: a click arriving right after a hold is swallowed too.
    document.addEventListener('click', function (e) {
        if (Date.now() > swallowUntil) { return; }
        swallowUntil = 0;
        e.preventDefault();
        e.stopImmediatePropagation();
    }, true);
})(window, document);
