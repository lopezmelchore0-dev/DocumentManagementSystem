/**
 * Document scanner worker (started by js/doc-scanner.js). One file, two roles:
 *   cv    loads OpenCV.js and finds page outlines (live preview frames and photos), so live
 *         detection never waits behind heavy processing
 *   proc  no OpenCV (small and fast to start): warp, rotate, deskew, glare, filters, filter chips,
 *         corner refinement, sharpness and orientation of a capture
 *
 * Protocol: {id, op, args} → {id, ok, result | error}. Pixel buffers are transferred, not copied.
 * The first message must be {op: 'init', args: {role, core, cv}} (versioned script URLs).
 */
/* global importScripts, DscCore */
(function () {
    'use strict';
    var role = null, cv = null, cvWait = null;

    function loadCv(url) {
        return new Promise(function (resolve) {
            var done = false;
            function finish(api) {
                if (done) { return; }
                if (api && typeof api.Mat === 'function' && typeof api.findContours === 'function') { done = true; cv = api; resolve(true); }
            }
            var mod = {};
            mod.onRuntimeInitialized = function () { finish(self.cv && self.cv.Mat ? self.cv : mod); };
            self.Module = mod;
            try { importScripts(url); } catch (e) { done = true; resolve(false); return; }
            var c = self.cv;
            if (c && typeof c.Mat === 'function') { finish(c); }
            else if (c && typeof c.then === 'function') {
                try { c.then(function (api) { if (api) { try { delete api.then; } catch (e) { /* ignore */ } } finish(api || mod); }); } catch (e) { /* ignore */ }
            }
            var started = Date.now();
            (function poll() {
                if (done) { return; }
                if (self.cv && typeof self.cv.Mat === 'function') { finish(self.cv); return; }
                if (Date.now() - started > 90000) { done = true; resolve(false); return; }
                setTimeout(poll, 200);
            })();
        });
    }

    function u8(buf) { return new Uint8ClampedArray(buf); }

    var ops = {
        init: function (a) {
            role = a.role;
            importScripts(a.core);
            if (role === 'cv') { cvWait = loadCv(a.cv); return cvWait.then(function (ok) { return { cv: ok }; }); }
            return { ready: true };
        },
        /** {buf, w, h, minArea} → {quad, score} | null */
        detect: function (a) {
            return (cvWait || Promise.resolve(false)).then(function () {
                if (!cv) { return null; }
                return DscCore.detectQuadCv(cv, u8(a.buf), a.w, a.h, { minArea: a.minArea });
            });
        },
        /** Full photo: {buf, w, h, quad} → {quad, sharpness, rot, skew, upsideDown} */
        analyze: function (a) { return DscCore.analyzeCapture(u8(a.buf), a.w, a.h, a.quad); },
        /** Page pipeline (see DscCore.renderJob): job with src as buf → {buf, w, h} */
        render: function (a) {
            a.src = u8(a.buf);
            var r = DscCore.renderJob(a);
            return { buf: r.data.buffer, w: r.w, h: r.h, transfer: [r.data.buffer] };
        },
        /** Filter chips: {buf, w, h, filters: [id], params} → {bufs: [ArrayBuffer]} */
        chips: function (a) {
            var base = u8(a.buf), out = [];
            a.filters.forEach(function (f) {
                var d = base.slice();
                DscCore.applyFilterData(d, a.w, a.h, { filter: f, level: a.level });
                out.push(d.buffer);
            });
            return { bufs: out, transfer: out.slice() };
        }
    };

    self.onmessage = function (e) {
        var m = e.data || {};
        var fn = ops[m.op];
        if (!fn) { self.postMessage({ id: m.id, ok: false, error: 'Unknown operation ' + m.op }); return; }
        Promise.resolve().then(function () { return fn(m.args || {}); }).then(function (res) {
            var transfer = (res && res.transfer) || [];
            if (res && res.transfer) { delete res.transfer; }
            self.postMessage({ id: m.id, ok: true, result: res }, transfer);
        }, function (err) {
            self.postMessage({ id: m.id, ok: false, error: (err && err.message) || String(err) });
        });
    };
})();
