/**
 * Shared behaviour for the sign-in forms: Log In, Register, Forgot Password and
 * Change Password (Account Settings). Behaviour only, no new visuals.
 *
 * Markup hooks
 *   form[data-auth-form]          Enter moves to the next empty field; focused fields are kept
 *                                 above the on-screen keyboard
 *   form[data-auth-guard]         warn before leaving the page with typed data
 *   form[data-auth-dirty]         (set by PHP) the form came back from the server with the user's
 *                                 data in it, so it counts as typed data for the guard
 *   form[data-auth-autofocus]     focus the first empty field on load (mouse/trackpad devices
 *                                 only, so phones don't pop the keyboard up uninvited)
 *   [data-pw-toggle="inputId"]    show/hide password eye
 *
 * API (window.DmsAuth)
 *   form(form, check)             check(form) returns null or [fieldId, title, text];
 *                                 failures show the popup, passes lock the form while it posts
 *   fail(field, title, text, opt) error popup, then focus + mark the field (.is-invalid)
 *   alert(data)                   server result {icon, title, text, redirect, field, ...}
 */
(function () {
    'use strict';

    var doc = document;
    var fineQuery = window.matchMedia ? window.matchMedia('(pointer: fine)') : null;
    var alertPending = false; // a server popup will take care of focus
    var leaving = false;      // navigating away on purpose (no leave warning)

    function byId(f) { return typeof f === 'string' ? doc.getElementById(f) : f; }

    function popup(opts) {
        opts.confirmButtonText = opts.confirmButtonText || 'OK';
        opts.returnFocus = false; // we move focus to the right field ourselves
        if (window.Swal && Swal.fire) return Swal.fire(opts);
        window.alert((opts.title ? opts.title + '\n\n' : '') + (opts.text || ''));
        return Promise.resolve();
    }

    /* Fields the user can type in, in order */
    function fieldsOf(form) {
        return Array.prototype.filter.call(form.elements, function (el) {
            return el.tagName === 'INPUT' && el.type !== 'hidden' && el.type !== 'submit' &&
                   !el.hidden && !el.disabled && !el.readOnly && el.offsetParent !== null;
        });
    }

    function focusField(el) {
        if (!el) return;
        try { el.focus({ preventScroll: false }); } catch (e) { el.focus(); }
        // Caret at the end so the user can carry on typing / correct the value
        try { var n = el.value.length; el.setSelectionRange(n, n); } catch (e) {}
    }

    /* ---------- Invalid-field marking ------------------------------------------ */
    function mark(el) {
        el.classList.add('is-invalid');
        el.setAttribute('aria-invalid', 'true');
    }
    function unmark(el) {
        el.classList.remove('is-invalid');
        el.removeAttribute('aria-invalid');
    }
    doc.addEventListener('input', function (e) {
        var el = e.target;
        if (el && el.classList && el.classList.contains('is-invalid')) unmark(el);
        if (el && el.form && el.form.hasAttribute('data-auth-guard')) el.form.setAttribute('data-auth-typed', '1');
    });

    function fail(field, title, text, opt) {
        var el = byId(field);
        if (el) mark(el);
        var o = { icon: 'error', title: title, text: text };
        if (opt) for (var k in opt) o[k] = opt[k];
        return popup(o).then(function () { focusField(el); });
    }

    /* ---------- Busy (submitting) state ----------------------------------------- */
    function isBusy(form) { return form.getAttribute('data-busy') === '1'; }

    function busy(form) {
        if (isBusy(form)) return false;
        form.setAttribute('data-busy', '1');
        form.setAttribute('aria-busy', 'true');
        var btn = form.querySelector('[type="submit"]');
        if (btn) {
            btn.disabled = true;
            if (!btn.querySelector('.dms-busy-icon')) {
                var i = doc.createElement('i');
                i.className = 'fa fa-spinner fa-spin dms-busy-icon';
                i.setAttribute('aria-hidden', 'true');
                btn.insertBefore(i, btn.firstChild);
            }
        }
        return true;
    }

    function unbusy(form) {
        form.removeAttribute('data-busy');
        form.removeAttribute('aria-busy');
        var btn = form.querySelector('[type="submit"]');
        if (btn) {
            btn.disabled = false;
            var i = btn.querySelector('.dms-busy-icon');
            if (i) i.parentNode.removeChild(i);
        }
    }

    // Back/forward cache: a page restored from memory must not stay locked
    window.addEventListener('pageshow', function (e) {
        if (!e.persisted) return;
        leaving = false;
        Array.prototype.forEach.call(doc.querySelectorAll('form[data-busy]'), unbusy);
    });

    /* ---------- Validated submit ------------------------------------------------ */
    function form(f, check) {
        f = byId(f);
        if (!f) return;
        f.addEventListener('submit', function (e) {
            if (isBusy(f)) { e.preventDefault(); return; } // already sending
            var problem = check ? check(f) : null;
            if (problem) {
                e.preventDefault();
                fail(problem[0], problem[1], problem[2], problem[3]);
                return;
            }
            busy(f);
        });
    }

    /* ---------- Server results -------------------------------------------------- */
    function alertResult(data) {
        alertPending = true;
        var o = { icon: data.icon, title: data.title, text: data.text };
        if (data.confirmButtonColor) o.confirmButtonColor = data.confirmButtonColor;
        var el = byId(data.field);
        if (el && data.icon === 'error') mark(el);
        return popup(o).then(function () {
            if (data.redirect) {
                leaving = true;
                window.location.href = data.redirect;
                return;
            }
            alertPending = false;
            if (el) focusField(el);
            else autoFocus();
        });
    }

    /* ---------- Enter moves to the next empty field ----------------------------- */
    doc.addEventListener('keydown', function (e) {
        if (e.key !== 'Enter' || e.isComposing || e.shiftKey || e.altKey || e.ctrlKey || e.metaKey) return;
        var el = e.target;
        if (!el || el.tagName !== 'INPUT' || !el.form || !el.form.hasAttribute('data-auth-form')) return;
        if (!el.value.trim()) return; // let the submit check point at this field
        var list = fieldsOf(el.form);
        var rest = list.slice(list.indexOf(el) + 1);
        for (var i = 0; i < rest.length; i++) {
            if (!rest[i].value.trim()) { e.preventDefault(); focusField(rest[i]); return; }
        }
        // Everything is filled in: Enter submits as usual
    });

    /* ---------- Password show / hide --------------------------------------------- */
    function syncToggle(t) {
        var input = doc.getElementById(t.getAttribute('data-pw-toggle'));
        if (!input) return;
        var shown = input.type === 'text';
        t.classList.toggle('fa-eye', shown);
        t.classList.toggle('fa-eye-slash', !shown);
        t.setAttribute('aria-pressed', shown ? 'true' : 'false');
        t.setAttribute('aria-label', shown ? 'Hide password' : 'Show password');
        t.setAttribute('title', shown ? 'Hide password' : 'Show password');
    }

    function flip(t) {
        var input = doc.getElementById(t.getAttribute('data-pw-toggle'));
        if (!input) return;
        var hadFocus = doc.activeElement === input;
        var s = input.selectionStart, en = input.selectionEnd;
        input.type = input.type === 'password' ? 'text' : 'password';
        syncToggle(t);
        if (hadFocus) {
            try { input.focus(); input.setSelectionRange(s, en); } catch (e) {}
        }
    }

    function initToggles() {
        Array.prototype.forEach.call(doc.querySelectorAll('[data-pw-toggle]'), function (t) {
            t.setAttribute('role', 'button');
            t.setAttribute('tabindex', '0');
            t.setAttribute('aria-controls', t.getAttribute('data-pw-toggle'));
            syncToggle(t);
        });
    }

    // Keep focus (and the phone keyboard) in the password field while tapping the eye
    doc.addEventListener('mousedown', function (e) {
        if (e.target.closest && e.target.closest('[data-pw-toggle]')) e.preventDefault();
    });
    doc.addEventListener('click', function (e) {
        var t = e.target.closest && e.target.closest('[data-pw-toggle]');
        if (t) { e.preventDefault(); flip(t); }
    });
    doc.addEventListener('keydown', function (e) {
        var t = e.target;
        if (!t || !t.hasAttribute || !t.hasAttribute('data-pw-toggle')) return;
        if (e.key === 'Enter' || e.key === ' ' || e.key === 'Spacebar') { e.preventDefault(); flip(t); }
    });

    /* ---------- Keep the focused field above the on-screen keyboard -------------- */
    function reveal() {
        var el = doc.activeElement;
        if (!el || el.tagName !== 'INPUT' || !el.form || !el.form.hasAttribute('data-auth-form')) return;
        var vv = window.visualViewport;
        var top = vv ? vv.offsetTop : 0;
        var height = vv ? vv.height : window.innerHeight;
        var r = el.getBoundingClientRect();
        if (r.top < top + 8 || r.bottom > top + height - 8) {
            try { el.scrollIntoView({ block: 'center', inline: 'nearest' }); } catch (e) { el.scrollIntoView(false); }
        }
    }
    var revealTimer = null;
    function revealSoon(delay) {
        clearTimeout(revealTimer);
        revealTimer = setTimeout(reveal, delay);
    }
    doc.addEventListener('focusin', function () { revealSoon(350); }); // after the keyboard slides up
    if (window.visualViewport) window.visualViewport.addEventListener('resize', function () { revealSoon(60); });

    /* ---------- Warn before losing typed data ------------------------------------ */
    window.addEventListener('beforeunload', function (e) {
        if (leaving) return;
        var forms = doc.querySelectorAll('form[data-auth-guard]');
        for (var i = 0; i < forms.length; i++) {
            var f = forms[i];
            if (isBusy(f)) continue; // being submitted
            if (!f.hasAttribute('data-auth-typed') && !f.hasAttribute('data-auth-dirty')) continue;
            var filled = fieldsOf(f).some(function (el) { return el.value.trim() !== ''; });
            if (filled) { e.preventDefault(); e.returnValue = ''; return ''; }
        }
    });

    /* ---------- First field focus ----------------------------------------------- */
    function autoFocus() {
        if (alertPending) return;
        if (!(fineQuery && fineQuery.matches)) return;
        var f = doc.querySelector('form[data-auth-autofocus]');
        if (!f) return;
        var active = doc.activeElement;
        if (active && active !== doc.body && f.contains(active)) return; // user got there first
        var list = fieldsOf(f);
        for (var i = 0; i < list.length; i++) {
            if (!list[i].value) { focusField(list[i]); return; }
        }
    }

    function init() {
        initToggles();
        // The login splash hides the form for a moment: focus once it has gone
        if (doc.documentElement.classList.contains('dms-splash-on')) {
            doc.addEventListener('dms:splash-done', function () { autoFocus(); }, { once: true });
        } else {
            autoFocus();
        }
    }
    if (doc.readyState === 'loading') doc.addEventListener('DOMContentLoaded', init);
    else init();

    window.DmsAuth = { form: form, fail: fail, alert: alertResult, busy: busy, unbusy: unbusy };
})();
