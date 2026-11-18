/**
 * Built-in document scanner (Scan Document buttons from includes/scanner.php).
 *
 * Camera (getUserMedia, rear camera) → automatic edge detection (OpenCV.js, loaded on first use) →
 * perspective correction → batch of documents, each with one or more pages → review / edit
 * (corners, rotate, filters, brightness, contrast, reorder) → PDF (jsPDF, Folio 8.5×13 in by
 * default) → attach.
 *
 *  form mode    the PDF goes into the page's existing file input (and extra PDFs into a hidden
 *               multi-file input), so the form's normal Save uploads them (includes/upload_helper.php).
 *  record mode  each PDF is uploaded to scan_attach.php and attached to the record right away.
 *
 * Everything is processed in the browser. Captured pages are kept in IndexedDB (not in memory)
 * until they have been attached, so a failed upload or a reload never loses them.
 * Without a secure (HTTPS) connection browsers block the live camera: the scanner then uses the
 * phone's own camera through a file input, and the rest of the workflow is the same.
 */
(function () {
    'use strict';
    if (window.DmsScanner) { return; }

    var SCRIPT_SRC = (document.currentScript && document.currentScript.src) || '';
    var ROOT = SCRIPT_SRC ? new URL('../', SCRIPT_SRC.split('?')[0]).href : './';

    /* =====================================================================
       Constants
       ===================================================================== */
    var PAPERS = {
        folio:  { label: 'Folio (8.5 × 13 in)',  w: 612,    h: 936 },
        a4:     { label: 'A4 (210 × 297 mm)',    w: 595.28, h: 841.89 },
        letter: { label: 'Letter (8.5 × 11 in)', w: 612,    h: 792 },
        legal:  { label: 'Legal (8.5 × 14 in)',  w: 612,    h: 1008 }
    };
    var DEFAULT_PAPER = 'folio';
    var DEFAULT_ORIENTATION = 'portrait';
    var PDF_MARGIN = 18;         // 0.25 in
    var ORIG_MAX = 3200;         // long side of the stored original (keeps text readable on Folio)
    var THUMB_LONG = 360;
    var DETECT_LONG = 480;
    var QUALITIES = {
        standard: { label: 'Standard (200 dpi)', dpi: 200, q: 0.85 },
        high:     { label: 'High (250 dpi)',     dpi: 250, q: 0.9 },
        compact:  { label: 'Compact (150 dpi)',  dpi: 150, q: 0.78 }
    };
    // 'enhanced' keeps its id so scans saved on the device before this version still load.
    var FILTERS = [
        { id: 'original', label: 'Original' },
        { id: 'magic',    label: 'Magic Color' },
        { id: 'enhanced', label: 'Auto Enhance' },
        { id: 'noshadow', label: 'No Shadow' },
        { id: 'gray',     label: 'Grayscale' },
        { id: 'bw',       label: 'Black & White' },
        { id: 'lighten',  label: 'Lighten' }
    ];
    var DEFAULT_FILTER = 'enhanced';
    var SHAPE_OPTIONS = [
        ['auto', 'Auto (detect paper)'], ['folio', 'Folio (8.5 × 13 in)'], ['a4', 'A4'], ['letter', 'Letter'],
        ['legal', 'Legal'], ['id', 'ID card'], ['free', 'Free (as measured)']
    ];
    var CHIP_LONG = 220;
    /** The mode new pages start with: the one used last on this device. */
    function lastFilter() {
        var v = null;
        try { v = window.localStorage.getItem('dms-scan-filter'); } catch (e) { /* storage blocked */ }
        return FILTERS.some(function (f) { return f.id === v; }) ? v : DEFAULT_FILTER;
    }
    function rememberFilter(id) {
        try { window.localStorage.setItem('dms-scan-filter', id); } catch (e) { /* storage blocked */ }
    }
    var BLUR_MESSAGE = 'Document appears blurry. Please retake the photo for better quality.';
    var MODULE_NOUN = { resolution: 'Resolution', ordinance: 'Ordinance', committee_report: 'Committee-Report', ob: 'Order-of-Business' };

    /* =====================================================================
       Small helpers
       ===================================================================== */
    function uid(prefix) {
        var r = '';
        if (window.crypto && window.crypto.getRandomValues) {
            var a = new Uint8Array(12);
            window.crypto.getRandomValues(a);
            for (var i = 0; i < a.length; i++) { r += ('0' + a[i].toString(16)).slice(-2); }
        } else {
            r = Math.random().toString(16).slice(2) + Date.now().toString(16);
        }
        return (prefix || '') + r;
    }
    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }
    function fmtBytes(n) {
        if (n >= 1048576) { return (n / 1048576).toFixed(1) + ' MB'; }
        if (n >= 1024) { return Math.round(n / 1024) + ' KB'; }
        return n + ' B';
    }
    function plural(n, one, many) { return n + ' ' + (n === 1 ? one : (many || one + 's')); }
    function today() {
        var d = new Date();
        return d.getFullYear() + '-' + ('0' + (d.getMonth() + 1)).slice(-2) + '-' + ('0' + d.getDate()).slice(-2);
    }
    function isMobile() {
        var ua = navigator.userAgent || '';
        return /Android|iPhone|iPad|iPod|Mobile/i.test(ua) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
    }
    function safeName(s) {
        s = String(s || '').replace(/[<>:"\/\\|?*\x00-\x1F]+/g, ' ').replace(/[.,;]+/g, ' ').trim().replace(/\s+/g, '-');
        return s.slice(0, 80) || 'Scan';
    }
    function ensurePdfName(s) {
        s = String(s || '').replace(/[<>:"\/\\|?*\x00-\x1F]+/g, '').trim();
        if (!s) { s = 'Scan'; }
        if (!/\.pdf$/i.test(s)) { s += '.pdf'; }
        return s;
    }
    function sleep(ms) { return new Promise(function (r) { setTimeout(r, ms); }); }
    function nextFrame() { return new Promise(function (r) { (window.requestAnimationFrame || setTimeout)(function () { r(); }); }); }
    function swal() { return window.Swal && typeof window.Swal.fire === 'function' ? window.Swal : null; }
    function alertMsg(icon, title, text) {
        var S = swal();
        if (S) { return S.fire({ icon: icon, title: title, text: text }); }
        window.alert(title + (text ? '\n\n' + text : ''));
        return Promise.resolve({});
    }
    function confirmMsg(opts) {
        var S = swal();
        if (S) {
            return S.fire(Object.assign({ icon: 'warning', showCancelButton: true, confirmButtonColor: '#098209', reverseButtons: true }, opts));
        }
        return Promise.resolve({ isConfirmed: window.confirm(opts.title + (opts.text ? '\n\n' + opts.text : '')), isDenied: false });
    }
    function newCanvas(w, h) {
        var c = document.createElement('canvas');
        c.width = Math.max(1, Math.round(w));
        c.height = Math.max(1, Math.round(h));
        return c;
    }
    function freeCanvas(c) { if (c && c.getContext) { c.width = 1; c.height = 1; } }
    function canvasToBlob(c, type, q) {
        return new Promise(function (resolve, reject) {
            if (c.toBlob) {
                c.toBlob(function (b) { b ? resolve(b) : reject(new Error('Image encoding failed')); }, type, q);
            } else {
                try {
                    var data = c.toDataURL(type, q), bin = atob(data.split(',')[1]), arr = new Uint8Array(bin.length);
                    for (var i = 0; i < bin.length; i++) { arr[i] = bin.charCodeAt(i); }
                    resolve(new Blob([arr], { type: type }));
                } catch (e) { reject(e); }
            }
        });
    }
    function blobToBytes(b) {
        if (b.arrayBuffer) { return b.arrayBuffer().then(function (ab) { return new Uint8Array(ab); }); }
        return new Promise(function (resolve, reject) {
            var fr = new FileReader();
            fr.onload = function () { resolve(new Uint8Array(fr.result)); };
            fr.onerror = reject;
            fr.readAsArrayBuffer(b);
        });
    }
    /** Blob/File → drawable (ImageBitmap or <img>) with EXIF orientation applied. */
    function loadImage(blob) {
        if (window.createImageBitmap) {
            return createImageBitmap(blob, { imageOrientation: 'from-image' }).catch(function () {
                return createImageBitmap(blob);
            }).catch(function () { return loadImgEl(blob); });
        }
        return loadImgEl(blob);
    }
    function loadImgEl(blob) {
        return new Promise(function (resolve, reject) {
            var url = URL.createObjectURL(blob), img = new Image();
            img.onload = function () { URL.revokeObjectURL(url); resolve(img); };
            img.onerror = function () { URL.revokeObjectURL(url); reject(new Error('The image could not be read.')); };
            img.src = url;
        });
    }
    function imgW(img) { return img.naturalWidth || img.videoWidth || img.width; }
    function imgH(img) { return img.naturalHeight || img.videoHeight || img.height; }
    function releaseImage(img) { if (img && typeof img.close === 'function') { try { img.close(); } catch (e) { /* ignore */ } } }
    /** Draw `src` scaled so its long side is at most `maxLong`. */
    function scaledCanvas(src, maxLong, sw, sh) {
        sw = sw || imgW(src); sh = sh || imgH(src);
        var s = Math.min(1, maxLong / Math.max(sw, sh));
        var c = newCanvas(sw * s, sh * s), ctx = c.getContext('2d');
        ctx.imageSmoothingEnabled = true;
        if ('imageSmoothingQuality' in ctx) { ctx.imageSmoothingQuality = 'high'; }
        ctx.drawImage(src, 0, 0, c.width, c.height);
        return c;
    }
    /** Downscale in halving steps for a sharper result when shrinking a lot. */
    function resizeCanvas(src, w, h) {
        var cur = src;
        while (cur.width / 2 >= w && cur.height / 2 >= h) {
            var half = newCanvas(cur.width / 2, cur.height / 2);
            half.getContext('2d').drawImage(cur, 0, 0, half.width, half.height);
            if (cur !== src) { freeCanvas(cur); }
            cur = half;
        }
        var out = newCanvas(w, h), ctx = out.getContext('2d');
        if ('imageSmoothingQuality' in ctx) { ctx.imageSmoothingQuality = 'high'; }
        ctx.drawImage(cur, 0, 0, out.width, out.height);
        if (cur !== src) { freeCanvas(cur); }
        return out;
    }

    /* =====================================================================
       Storage: IndexedDB (falls back to memory if it is not available)
       ===================================================================== */
    var Store = (function () {
        var dbp = null, memBlobs = new Map(), memSessions = new Map(), useMem = false;
        function open() {
            if (dbp) { return dbp; }
            dbp = new Promise(function (resolve) {
                try {
                    if (!window.indexedDB) { useMem = true; resolve(null); return; }
                    var req = indexedDB.open('dms-scanner', 1);
                    req.onupgradeneeded = function () {
                        var db = req.result;
                        if (!db.objectStoreNames.contains('blobs')) { db.createObjectStore('blobs'); }
                        if (!db.objectStoreNames.contains('sessions')) { db.createObjectStore('sessions', { keyPath: 'key' }); }
                    };
                    req.onsuccess = function () { resolve(req.result); };
                    req.onerror = function () { useMem = true; resolve(null); };
                    req.onblocked = function () { useMem = true; resolve(null); };
                } catch (e) { useMem = true; resolve(null); }
            });
            return dbp;
        }
        function tx(store, mode, fn) {
            return open().then(function (db) {
                if (!db) { return fn(null); }
                return new Promise(function (resolve, reject) {
                    var t, s, result;
                    try { t = db.transaction(store, mode); s = t.objectStore(store); } catch (e) { reject(e); return; }
                    var r = fn(s);
                    if (r && 'onsuccess' in r) { r.onsuccess = function () { result = r.result; }; }
                    t.oncomplete = function () { resolve(result); };
                    t.onerror = function () { reject(t.error || new Error('Storage error')); };
                    t.onabort = function () { reject(t.error || new Error('Storage full')); };
                });
            });
        }
        return {
            putBlob: function (k, b) {
                return tx('blobs', 'readwrite', function (s) { if (!s) { memBlobs.set(k, b); return null; } return s.put(b, k); })
                    .catch(function () { memBlobs.set(k, b); });
            },
            getBlob: function (k) {
                if (memBlobs.has(k)) { return Promise.resolve(memBlobs.get(k)); }
                return tx('blobs', 'readonly', function (s) { return s ? s.get(k) : null; }).then(function (v) { return v || null; })
                    .catch(function () { return null; });
            },
            delBlob: function (k) {
                memBlobs.delete(k);
                return tx('blobs', 'readwrite', function (s) { return s ? s.delete(k) : null; }).catch(function () { /* ignore */ });
            },
            putSession: function (sess) {
                var copy = JSON.parse(JSON.stringify(sess));
                return tx('sessions', 'readwrite', function (s) { if (!s) { memSessions.set(copy.key, copy); return null; } return s.put(copy); })
                    .catch(function () { memSessions.set(copy.key, copy); });
            },
            getSession: function (k) {
                if (memSessions.has(k)) { return Promise.resolve(memSessions.get(k)); }
                return tx('sessions', 'readonly', function (s) { return s ? s.get(k) : null; }).then(function (v) { return v || null; })
                    .catch(function () { return null; });
            },
            delSession: function (k) {
                memSessions.delete(k);
                return tx('sessions', 'readwrite', function (s) { return s ? s.delete(k) : null; }).catch(function () { /* ignore */ });
            },
            allSessions: function () {
                var mem = Array.from(memSessions.values());
                return tx('sessions', 'readonly', function (s) { return s ? s.getAll() : null; })
                    .then(function (v) { return (v || []).concat(mem); }).catch(function () { return mem; });
            },
            isMemory: function () { return useMem; }
        };
    })();

    function pageKeys(p) { return [p.id + ':orig', p.id + ':thumb']; }
    /** Remove a stored session and every image that belongs to it. */
    function dropSession(sess) {
        if (!sess) { return Promise.resolve(); }
        var jobs = [];
        (sess.docs || []).forEach(function (d) {
            (d.pages || []).forEach(function (p) { pageKeys(p).forEach(function (k) { jobs.push(Store.delBlob(k)); }); });
        });
        jobs.push(Store.delSession(sess.key));
        return Promise.all(jobs);
    }
    /** Keys from dms_scan_clear_script(): exact key, or every key starting with "key:". */
    function clearSessionKeys(keys) {
        if (!keys || !keys.length) { return Promise.resolve(); }
        return Store.allSessions().then(function (all) {
            return Promise.all(all.filter(function (s) {
                return keys.some(function (k) { return s.key === k || s.key.indexOf(k + ':') === 0; });
            }).map(dropSession));
        });
    }
    /** Old scans (over 30 days) are removed automatically. */
    function pruneOldSessions() {
        var limit = Date.now() - 30 * 86400000;
        return Store.allSessions().then(function (all) {
            return Promise.all(all.filter(function (s) { return (s.updated || 0) < limit; }).map(dropSession));
        }).catch(function () { /* ignore */ });
    }

    /* =====================================================================
       Image processing: js/scanner/dsc-core.js, run in Web Workers (js/scanner/dsc-worker.js)
       ===================================================================== */
    var C = window.DscCore || {};
    var orderQuad = C.orderQuad, fullQuad = C.fullQuad, isFullQuad = C.isFullQuad, dist = C.dist, quadSize = C.quadSize,
        isConvexQuad = C.isConvexQuad, scaleQuad = C.scaleQuad, fitOnPage = C.fitOnPage, sharpnessLevel = C.sharpnessLevel;
    function pageSize(paperId, landscape) {
        var p = PAPERS[paperId] || PAPERS[DEFAULT_PAPER];
        return landscape ? { w: p.h, h: p.w } : { w: p.w, h: p.h };
    }
    var ASSETS = (function () {
        var a = window.DMS_SCANNER_ASSETS || {};
        return {
            worker: ROOT + (a.worker || 'js/scanner/dsc-worker.js'),
            core: ROOT + (a.core || 'js/scanner/dsc-core.js'),
            cv: ROOT + (a.cv || 'vendor/opencv/opencv.js')
        };
    })();

    /** A worker with a promise-based call(op, args, transfer). Returns null when workers are unavailable. */
    function spawnWorker(role) {
        var w;
        try { w = new Worker(ASSETS.worker); } catch (e) { return null; }
        var seq = 0, pend = {}, dead = false;
        function failAll(msg) {
            dead = true;
            Object.keys(pend).forEach(function (k) { pend[k].rej(new Error(msg)); });
            pend = {};
        }
        w.onmessage = function (e) {
            var m = e.data || {}, p = pend[m.id];
            if (!p) { return; }
            delete pend[m.id];
            if (m.ok) { p.res(m.result); } else { p.rej(new Error(m.error || 'Processing failed')); }
        };
        w.onerror = function (e) { if (e && e.preventDefault) { e.preventDefault(); } failAll('The image processor stopped.'); };
        function call(op, args, transfer) {
            if (dead) { return Promise.reject(new Error('The image processor stopped.')); }
            return new Promise(function (res, rej) {
                var id = ++seq;
                pend[id] = { res: res, rej: rej };
                try { w.postMessage({ id: id, op: op, args: args }, transfer || []); } catch (err) { delete pend[id]; rej(err); }
            });
        }
        var ready = call('init', { role: role, core: ASSETS.core, cv: ASSETS.cv });
        return { call: call, ready: ready, dead: function () { return dead; }, terminate: function () { failAll('stopped'); try { w.terminate(); } catch (e) { /* ignore */ } } };
    }

    /*
     * Edge detection state for the UI ('idle' | 'loading' | 'ready' | 'failed').
     * Normally OpenCV runs in its own worker; without workers it is loaded on the page (as before).
     */
    var cv = null, cvState = 'idle', cvListeners = [], cvMainPromise = null;
    var procW = null, cvW = null, cvStopTimer = null, useWorkers = typeof Worker === 'function';
    function cvReady() { return cvState === 'ready'; }
    function onCvChange(fn) {
        cvListeners.push(fn);
        return function () { var i = cvListeners.indexOf(fn); if (i >= 0) { cvListeners.splice(i, 1); } };
    }
    /** Resolves once edge detection has finished loading (or failed, or after ms). */
    function cvSettled(ms) {
        if (cvState !== 'loading') { return Promise.resolve(); }
        return new Promise(function (resolve) {
            var off = null, t = setTimeout(function () { if (off) { off(); } resolve(); }, ms);
            off = onCvChange(function (st) { if (st !== 'loading') { clearTimeout(t); off(); resolve(); } });
        });
    }
    function setCvState(s) { cvState = s; cvListeners.forEach(function (fn) { try { fn(s); } catch (e) { /* ignore */ } }); }
    /** Start (or keep) the processors; called when the scanner opens. */
    function startProcessors() {
        clearTimeout(cvStopTimer);
        if (useWorkers && (!procW || procW.dead())) {
            procW = spawnWorker('proc');
            if (procW) { procW.ready.catch(function () { procW = null; useWorkers = false; }); }
            else { useWorkers = false; }
        }
        if (cvState === 'ready' || cvState === 'loading') { return; }
        setCvState('loading');
        if (useWorkers) {
            cvW = spawnWorker('cv');
            if (cvW) {
                cvW.ready.then(function (r) {
                    if (r && r.cv) { setCvState('ready'); return; }
                    cvW.terminate(); cvW = null; loadCvMain();      // the worker could not load it: try the page
                }, function () { cvW = null; loadCvMain(); });
                return;
            }
        }
        loadCvMain();
    }
    /** After the scanner closes, free OpenCV's memory (it is reloaded from the browser cache when needed). */
    function stopProcessorsLater() {
        clearTimeout(cvStopTimer);
        cvStopTimer = setTimeout(function () {
            if (S || !cvW) { return; }
            cvW.terminate(); cvW = null; setCvState('idle');
        }, 60000);
    }
    /** OpenCV.js on the page itself (fallback when Web Workers are not available). */
    function loadCvMain() {
        if (cvMainPromise) { return cvMainPromise; }
        setCvState('loading');
        cvMainPromise = new Promise(function (resolve) {
            if (typeof WebAssembly !== 'object') { setCvState('failed'); resolve(null); return; }
            var done = false;
            function finish(api) {
                if (done) { return; }
                if (api && typeof api.Mat === 'function' && typeof api.getPerspectiveTransform === 'function') {
                    done = true; cv = api; setCvState('ready'); resolve(api);
                }
            }
            function fail() { if (!done) { done = true; setCvState('failed'); resolve(null); } }
            // The UMD wrapper would register as an AMD module if a loader is present.
            var savedDefine = window.define;
            if (savedDefine && savedDefine.amd) { window.define = undefined; }
            var mod = window.Module && typeof window.Module === 'object' ? window.Module : {};
            var prevInit = mod.onRuntimeInitialized;
            mod.onRuntimeInitialized = function () {
                if (typeof prevInit === 'function') { try { prevInit(); } catch (e) { /* ignore */ } }
                finish(window.cv && window.cv.Mat ? window.cv : mod);
            };
            window.Module = mod;
            var s = document.createElement('script');
            s.src = ASSETS.cv;
            s.async = true;
            s.onload = function () {
                if (savedDefine) { window.define = savedDefine; }
                var c = window.cv;
                if (c && typeof c.Mat === 'function') { finish(c); return; }
                if (c && typeof c.then === 'function') {
                    try { c.then(function (api) { if (api) { try { delete api.then; } catch (e) { /* ignore */ } } finish(api || mod); }); } catch (e) { /* ignore */ }
                }
            };
            s.onerror = function () { if (savedDefine) { window.define = savedDefine; } fail(); };
            document.head.appendChild(s);
            var started = Date.now();
            (function poll() {
                if (done) { return; }
                if (window.cv && typeof window.cv.Mat === 'function') { finish(window.cv); return; }
                if (mod && typeof mod.Mat === 'function' && typeof mod.getPerspectiveTransform === 'function' && mod.calledRun !== false) { finish(mod); return; }
                if (Date.now() - started > 60000) { fail(); return; }
                setTimeout(poll, 250);
            })();
        });
        return cvMainPromise;
    }

    /** {data, w, h} of a canvas (its own buffer, safe to transfer). */
    function pixelsOf(c) { var d = c.getContext('2d').getImageData(0, 0, c.width, c.height); return { data: d.data, w: c.width, h: c.height }; }
    function canvasFrom(data, w, h) {
        var c = newCanvas(w, h), ctx = c.getContext('2d'), img;
        try { img = new ImageData(data, w, h); } catch (e) { img = ctx.createImageData(w, h); img.data.set(data); }
        ctx.putImageData(img, 0, 0);
        return c;
    }
    /** Run on the processing worker, or on the page when there is none. */
    function procCall(op, args, transfer, onMain) {
        if (procW && !procW.dead()) {
            return procW.ready.then(function () { return procW.call(op, args, transfer); });
        }
        return new Promise(function (resolve) { resolve(onMain()); });
    }
    var Proc = {
        /** Page outline in pixels {data, w, h} → {quad, score} | null. */
        detect: function (px, opt) {
            opt = opt || {};
            if (cvW && !cvW.dead() && cvState === 'ready') {
                return cvW.call('detect', { buf: px.data.buffer, w: px.w, h: px.h, minArea: opt.minArea }, [px.data.buffer]);
            }
            return Promise.resolve(cv ? C.detectQuadCv(cv, px.data, px.w, px.h, opt) : null);
        },
        /** Full-resolution analysis of a capture: refined quad, sharpness, orientation. */
        analyze: function (px, quad) {
            return procCall('analyze', { buf: px.data.buffer, w: px.w, h: px.h, quad: quad }, [px.data.buffer], function () {
                return C.analyzeCapture(px.data, px.w, px.h, quad);
            });
        },
        /** The page pipeline (C.renderJob) → canvas. */
        render: function (job, px) {
            job.buf = px.data.buffer;
            return procCall('render', job, [px.data.buffer], function () {
                job.src = px.data;
                var r = C.renderJob(job);
                return { buf: r.data.buffer, w: r.w, h: r.h };
            }).then(function (r) { return canvasFrom(new Uint8ClampedArray(r.buf), r.w, r.h); });
        },
        /** Every filter applied to one small page → [canvas]. */
        chips: function (px, filters, level) {
            return procCall('chips', { buf: px.data.buffer, w: px.w, h: px.h, filters: filters, level: level }, [px.data.buffer], function () {
                return { bufs: filters.map(function (f) { var d = px.data.slice(); C.applyFilterData(d, px.w, px.h, { filter: f, level: level }); return d.buffer; }) };
            }).then(function (r) { return r.bufs.map(function (b) { return canvasFrom(new Uint8ClampedArray(b), px.w, px.h); }); });
        }
    };
    /** Coarse outline on a ~1024 px copy, then refined on the full photo (with sharpness + orientation). */
    var STILL_DETECT_LONG = 1024;
    function analyzePhoto(canvas) {
        var small = scaledCanvas(canvas, STILL_DETECT_LONG), spx = pixelsOf(small);
        freeCanvas(small);
        // a photo taken / imported while OpenCV is still loading waits for it (instead of staying uncropped)
        var coarse = cvSettled(45000).then(function () {
            return cvReady() ? Proc.detect(spx, { minArea: 0.12 }).catch(function () { return null; }) : null;
        });
        return coarse.then(function (r) {
            return Proc.analyze(pixelsOf(canvas), r ? r.quad : null);
        });
    }

    /** Keep one decoded original at a time (the editor / PDF loop ask for the same page repeatedly). */
    var origCache = { id: null, ver: null, img: null };
    function getOriginal(page) {
        if (origCache.id === page.id && origCache.ver === page.origVer && origCache.img) { return Promise.resolve(origCache.img); }
        return Store.getBlob(page.id + ':orig').then(function (blob) {
            if (!blob) { throw new Error('This scanned page is no longer stored on this device. Please retake it.'); }
            return loadImage(blob);
        }).then(function (img) {
            // The previous image is not closed here: another render may still be drawing it.
            origCache = { id: page.id, ver: page.origVer, img: img };
            return img;
        });
    }
    /** The edits a page is rendered with (older saved scans lack the newer fields). */
    function editsOf(p) {
        return { quad: p.quad || fullQuad(), rot: p.rot || 0, filter: p.filter || 'original', bright: p.bright || 0, contrast: p.contrast || 0,
            sharpen: p.sharpen == null ? 50 : p.sharpen, shape: p.shape || 'auto', glare: !!p.glare, deskew: p.deskew !== false };
    }
    /**
     * Fully processed page, long side ≤ maxLong: perspective (true proportions / chosen paper shape)
     * → rotation + automatic straightening → glare → filter. `edits` defaults to the page's own.
     */
    function renderPage(page, maxLong, edits) {
        var e = edits ? Object.assign(editsOf(page), edits) : editsOf(page);
        return getOriginal(page).then(function (img) {
            var iw = imgW(img), ih = imgH(img), full = isFullQuad(e.quad), qpx = scaleQuad(e.quad, iw, ih);
            var dims = full ? { w: iw, h: ih } : C.pageDims(qpx, iw, ih, e.shape);
            var s = Math.min(1, maxLong / Math.max(dims.w, dims.h));
            var ow = Math.max(1, Math.round(dims.w * s)), oh = Math.max(1, Math.round(dims.h * s));
            var auto = e.deskew;
            var job = {
                rot: ((e.rot || 0) + (auto ? page.autoRot || 0 : 0)) % 360, skew: auto ? page.skew || 0 : 0, glare: e.glare,
                filter: e.filter, bright: e.bright, contrast: e.contrast, sharpen: e.sharpen, level: page.sharpLevel
            };
            var src;
            if (full) {
                src = newCanvas(ow, oh);
                var ctx = src.getContext('2d');
                if ('imageSmoothingQuality' in ctx) { ctx.imageSmoothingQuality = 'high'; }
                ctx.drawImage(img, 0, 0, ow, oh);
                job.quad = null;
            } else {
                // a source no larger than the output needs (the browser's high-quality downscale)
                var qs = quadSize(qpx), pre = Math.min(1, 1.15 * Math.max(ow / qs.w, oh / qs.h));
                src = newCanvas(iw * pre, ih * pre);
                var sctx = src.getContext('2d');
                if ('imageSmoothingQuality' in sctx) { sctx.imageSmoothingQuality = 'high'; }
                sctx.drawImage(img, 0, 0, src.width, src.height);
                job.quad = scaleQuad(qpx, src.width / iw, src.height / ih);
                job.ow = ow; job.oh = oh;
            }
            job.sw = src.width; job.sh = src.height;
            var px = pixelsOf(src);
            freeCanvas(src);
            return Proc.render(job, px);
        });
    }

    /* =====================================================================
       PDF (jsPDF)
       ===================================================================== */
    /**
     * Build one PDF from a list of pages.
     * opt: paper, orientation (portrait | landscape | auto), quality, title. onStep(done, total).
     */
    function buildPdf(pages, opt, onStep) {
        var J = window.jspdf && window.jspdf.jsPDF;
        if (!J) { return Promise.reject(new Error('The PDF library did not load. Check the connection and reload the page.')); }
        var qual = QUALITIES[opt.quality] || QUALITIES.standard;
        var paper = PAPERS[opt.paper] ? opt.paper : DEFAULT_PAPER;
        var pdf = null, i = 0;
        var longPx = Math.round(Math.max(PAPERS[paper].w, PAPERS[paper].h) / 72 * qual.dpi);
        function next() {
            if (i >= pages.length) {
                pdf.setProperties({ title: opt.title || 'Scanned document', creator: 'DMS Document Scanner' });
                return Promise.resolve(pdf.output('blob'));
            }
            var page = pages[i];
            return renderPage(page, longPx).then(function (c) {
                var land = opt.orientation === 'landscape' || (opt.orientation === 'auto' && c.width > c.height);
                var ps = pageSize(paper, land);
                var fit = fitOnPage(c.width, c.height, ps.w, ps.h, PDF_MARGIN);
                var tw = Math.max(1, Math.round(fit.w / 72 * qual.dpi)), th = Math.max(1, Math.round(fit.h / 72 * qual.dpi));
                var out = (tw < c.width) ? resizeCanvas(c, tw, th) : c;
                return canvasToBlob(out, 'image/jpeg', qual.q).then(blobToBytes).then(function (bytes) {
                    if (out !== c) { freeCanvas(out); }
                    freeCanvas(c);
                    if (!pdf) {
                        pdf = new J({ unit: 'pt', format: [ps.w, ps.h], orientation: land ? 'l' : 'p', compress: true });
                    } else {
                        pdf.addPage([ps.w, ps.h], land ? 'l' : 'p');
                    }
                    pdf.addImage(bytes, 'JPEG', fit.x, fit.y, fit.w, fit.h, undefined, 'NONE');
                    i++;
                    if (onStep) { onStep(i, pages.length); }
                    return nextFrame().then(next);
                });
            });
        }
        if (!pages.length) { return Promise.reject(new Error('There are no pages to save.')); }
        return next();
    }

    /* =====================================================================
       The scanner UI
       ===================================================================== */
    var S = null;   // current scanner state (one scanner at a time)
    var root = null;

    function ctxFromButton(btn) {
        var d = btn.dataset;
        var label = d.label || '';
        // Add forms: the record number is typed on the form (data-label-from = that field's id).
        if (d.labelFrom) {
            var src = document.getElementById(d.labelFrom);
            var v = src && String(src.value || '').trim();
            if (v) { label = (label + ' ' + v).trim(); }
        }
        return {
            btn: btn,
            // pick = top-bar shortcut: scan first, choose the existing record on the save screen
            mode: d.mode === 'record' ? 'record' : (d.mode === 'pick' ? 'pick' : 'form'),
            module: d.module || '',
            recordId: d.recordId || '',
            field: d.field || '',
            input: d.input ? document.getElementById(d.input) : null,
            extraField: d.extraField || '',
            label: label,
            hasMain: d.hasMain === '1',
            key: d.session || ((d.module || 'scan') + ':' + (d.recordId || 'add') + ':' + (d.field || '')),
            maxPost: parseInt(d.maxPost, 10) || 40 * 1048576,
            maxFiles: parseInt(d.maxFiles, 10) || 20
        };
    }

    function totalPages() { return S.docs.reduce(function (n, d) { return n + d.pages.length; }, 0); }
    function findDoc(id) { for (var i = 0; i < S.docs.length; i++) { if (S.docs[i].id === id) { return S.docs[i]; } } return null; }
    function findPage(pid) {
        for (var i = 0; i < S.docs.length; i++) {
            for (var j = 0; j < S.docs[i].pages.length; j++) {
                if (S.docs[i].pages[j].id === pid) { return { doc: S.docs[i], page: S.docs[i].pages[j], di: i, pi: j }; }
            }
        }
        return null;
    }
    function newDoc() { return { id: uid('d'), token: uid('t'), name: '', pages: [] }; }
    function serialize() {
        return {
            key: S.ctx.key, label: S.ctx.label, updated: Date.now(),
            combinedName: S.combinedName || '',
            paper: S.paper, orientation: S.orientation, quality: S.quality, outMode: S.outMode,
            docs: S.docs.map(function (d) {
                return {
                    id: d.id, token: d.token, name: d.name || '',
                    pages: d.pages.filter(function (p) { return !p.failed; }).map(function (p) {
                        return { id: p.id, w: p.w, h: p.h, quad: p.quad, detQuad: p.detQuad || null, rot: p.rot, filter: p.filter, bright: p.bright, contrast: p.contrast,
                            origVer: p.origVer || 1, sharpness: p.sharpness == null ? null : p.sharpness, sharpLevel: p.sharpLevel || 'unknown', blurry: !!p.blurry,
                            sharpen: p.sharpen == null ? 50 : p.sharpen, shape: p.shape || 'auto', glare: !!p.glare, deskew: p.deskew !== false,
                            autoRot: p.autoRot || 0, skew: p.skew || 0 };
                    })
                };
            })
        };
    }
    var saveTimer = null;
    function persist(now) {
        if (!S) { return Promise.resolve(); }
        clearTimeout(saveTimer);
        var sess = serialize(), st = S;
        function write() {
            if (!sess.docs.length || !sess.docs.some(function (d) { return d.pages.length; })) { return Store.delSession(sess.key); }
            return Store.putSession(sess);
        }
        if (now) { return write(); }
        return new Promise(function (resolve) { saveTimer = setTimeout(function () { if (st === S) { write().then(resolve, resolve); } else { resolve(); } }, 250); });
    }

    /* ---------- thumbnails ---------- */
    var thumbUrls = new Map();
    function thumbUrl(p) { return thumbUrls.get(p.id + '@' + (p.thumbVer || 0)) || ''; }
    function makeThumb(p) {
        return renderPage(p, THUMB_LONG).then(function (c) {
            return canvasToBlob(c, 'image/jpeg', 0.8).then(function (b) {
                freeCanvas(c);
                return Store.putBlob(p.id + ':thumb', b).then(function () { return setThumb(p, b); });
            });
        });
    }
    function setThumb(p, blob) {
        thumbUrls.forEach(function (url, k) { if (k.indexOf(p.id + '@') === 0) { URL.revokeObjectURL(url); thumbUrls.delete(k); } });
        p.thumbVer = (p.thumbVer || 0) + 1;
        thumbUrls.set(p.id + '@' + p.thumbVer, URL.createObjectURL(blob));
        updateThumbs(p);
    }
    function loadThumb(p) {
        return Store.getBlob(p.id + ':thumb').then(function (b) {
            if (b) { setThumb(p, b); return; }
            return makeThumb(p).catch(function () { /* ignore */ });
        });
    }
    function updateThumbs(p) {
        if (!root) { return; }
        var url = thumbUrl(p);
        root.querySelectorAll('[data-thumb="' + p.id + '"]').forEach(function (img) { if (url) { img.src = url; img.classList.remove('is-empty'); } });
    }
    function freeAllThumbs() { thumbUrls.forEach(function (url) { URL.revokeObjectURL(url); }); thumbUrls.clear(); }

    /* ---------- open / close ---------- */
    function open(ctx) {
        if (S) { return; }
        S = {
            ctx: ctx, docs: [], screen: null, captureMode: 'doc', targetDocId: null, retake: null,
            selected: new Set(), outMode: 'separate', paper: DEFAULT_PAPER, orientation: DEFAULT_ORIENTATION,
            quality: 'standard', target: 'extra', combinedName: '', processing: 0, queue: Promise.resolve(),
            stream: null, track: null, torch: false, deviceIds: [], deviceIndex: -1, facing: 'environment',
            autoCapture: false, detectTimer: null, lastQuad: null, stableSince: 0, lastAutoAt: 0, autoArmed: true,
            uploads: null, busy: false, previewUrl: null
        };
        S.allowExtras = ctx.mode === 'pick' ? true : !!ctx.extraField;
        S.target = ctx.mode === 'record' ? (ctx.hasMain ? 'extra' : 'main') : 'extra';
        if (ctx.mode === 'pick') {
            S.pick = { type: 'recent', q: '', results: null, loading: false, error: '', chosen: null, field: null, seq: 0, timer: null };
        }
        buildRoot();
        document.documentElement.classList.add('dms-scan-open');
        if (window.parent && window.parent !== window) {
            try { window.parent.postMessage({ type: 'dms-scan-open' }, window.location.origin); } catch (e) { /* ignore */ }
        }
        startProcessors();
        Store.getSession(ctx.key).then(function (sess) {
            if (!S) { return; }
            var n = sess ? sess.docs.reduce(function (a, d) { return a + d.pages.length; }, 0) : 0;
            if (!n) { showScreen('camera'); return; }
            // Scanning again on a form that already holds this scan: just add pages to the same PDF.
            if (ctx.mode === 'form' && ctx.input && ctx.input.getAttribute('data-scanned') === '1') {
                restoreSession(sess);
                var lastDoc = S.docs[S.docs.length - 1];
                S.captureMode = 'page';
                S.targetDocId = lastDoc ? lastDoc.id : null;
                showScreen('camera');
                return;
            }
            var when = new Date(sess.updated || Date.now());
            confirmMsg({
                icon: 'question', title: 'Continue the earlier scan?',
                text: 'There ' + (n === 1 ? 'is 1 scanned page' : 'are ' + n + ' scanned pages') + ' from ' + when.toLocaleString() + ' that ' + (n === 1 ? 'was' : 'were') + ' not attached yet.',
                confirmButtonText: 'Restore scans', cancelButtonText: 'Start over', allowOutsideClick: false
            }).then(function (r) {
                if (!S) { return; }
                if (r.isConfirmed) {
                    restoreSession(sess);
                    showScreen('review');
                } else {
                    dropSession(sess).then(function () { showScreen('camera'); });
                }
            });
        });
    }

    /** Put a stored scan back into the scanner (pages, names and the output settings used last). */
    function restoreSession(sess) {
        S.docs = sess.docs.map(function (d) {
            return { id: d.id, token: d.token || uid('t'), name: d.name || '', pages: d.pages.map(function (p) { return Object.assign({ ready: true }, p); }) };
        });
        S.combinedName = sess.combinedName || '';
        if (PAPERS[sess.paper]) { S.paper = sess.paper; }
        if (sess.orientation === 'portrait' || sess.orientation === 'landscape' || sess.orientation === 'auto') { S.orientation = sess.orientation; }
        if (QUALITIES[sess.quality]) { S.quality = sess.quality; }
        if (sess.outMode === 'separate' || sess.outMode === 'combine') { S.outMode = sess.outMode; }
        S.docs.forEach(function (d) { d.pages.forEach(loadThumb); });
    }

    function requestClose() {
        if (!S) { return; }
        if (S.busy) { return; }
        var n = totalPages();
        if (!n) { close(); return; }
        var S2 = swal();
        if (!S2) { if (window.confirm('Close the scanner? Your scanned pages are kept on this device.')) { close(); } return; }
        S2.fire({
            icon: 'question', title: 'Close the scanner?',
            text: 'Your ' + plural(n, 'scanned page') + ' will be kept on this device until you attach them, so you can continue later.',
            showDenyButton: true, showCancelButton: true,
            confirmButtonText: 'Close, keep scans', denyButtonText: 'Discard scans', cancelButtonText: 'Back',
            confirmButtonColor: '#098209', denyButtonColor: '#E5484D', reverseButtons: true
        }).then(function (r) {
            if (r.isConfirmed) { persist(true).then(close); }
            else if (r.isDenied) {
                confirmMsg({ title: 'Discard all scans?', text: 'This cannot be undone.', confirmButtonText: 'Discard', confirmButtonColor: '#E5484D' })
                    .then(function (r2) { if (r2.isConfirmed) { dropSession(serialize()).then(close); } });
            }
        });
    }

    function close() {
        if (!S) { return; }
        stopCamera();
        clearTimeout(saveTimer);
        if (S.previewUrl) { URL.revokeObjectURL(S.previewUrl); }
        freeAllThumbs();
        if (origCache.img) { releaseImage(origCache.img); }
        origCache = { id: null, ver: null, img: null };
        if (root) { root.remove(); root = null; }
        document.documentElement.classList.remove('dms-scan-open');
        if (window.parent && window.parent !== window) {
            try { window.parent.postMessage({ type: 'dms-scan-close' }, window.location.origin); } catch (e) { /* ignore */ }
        }
        var done = S.reloadOnClose;
        S = null;
        stopProcessorsLater();
        if (done) { window.location.reload(); }
    }

    function buildRoot() {
        root = document.createElement('div');
        root.className = 'dsc';
        root.setAttribute('role', 'dialog');
        root.setAttribute('aria-modal', 'true');
        root.setAttribute('aria-label', 'Document scanner');
        root.innerHTML = '<div class="dsc-screen" data-screen></div>' +
            '<input type="file" accept="image/*" capture="environment" class="dsc-hidden" data-native-capture>' +
            '<input type="file" accept="image/*" multiple class="dsc-hidden" data-import>';
        document.body.appendChild(root);
        root.addEventListener('click', onClick);
        root.addEventListener('change', onChange);
        root.addEventListener('input', onInput);
        root.querySelector('[data-native-capture]').addEventListener('change', function (e) { importFiles(e.target.files); e.target.value = ''; });
        root.querySelector('[data-import]').addEventListener('change', function (e) { importFiles(e.target.files); e.target.value = ''; });
    }

    function showScreen(name, arg) {
        if (!S || !root) { return; }
        var prev = S.screen;
        S.screen = name;
        // Leaving the camera, or re-entering it (e.g. Retake from the camera): restart cleanly.
        if (prev === 'camera') { stopCamera(); }
        var el = root.querySelector('[data-screen]');
        el.className = 'dsc-screen dsc-' + name;
        if (name === 'camera') { renderCamera(el); startCamera(); }
        else if (name === 'review') { renderReview(el); }
        else if (name === 'editor') { renderEditor(el, arg); }
        else if (name === 'save') { renderSave(el); }
    }

    /* ---------- camera screen ---------- */
    function counterText() {
        var d = S.docs.filter(function (x) { return x.pages.length; }).length, p = totalPages();
        return plural(d, 'document') + ' captured · ' + plural(p, 'page');
    }
    onCvChange(function () {
        var chip = root && root.querySelector('[data-cv-chip]');
        if (chip) { chip.outerHTML = cvChip(); }
    });
    function cvChip() {
        if (cvState === 'ready') { return '<span class="dsc-chip is-ok" data-cv-chip><i class="fa fa-magic"></i> Auto edge detection</span>'; }
        if (cvState === 'failed') { return '<span class="dsc-chip" data-cv-chip><i class="fa fa-crop"></i> Manual corners</span>'; }
        return '<span class="dsc-chip" data-cv-chip><span class="dsc-spin"></span> Loading edge detection…</span>';
    }
    function renderCamera(el) {
        var retake = S.retake;
        var last = lastPage();
        el.innerHTML =
            '<div class="dsc-cam">' +
                '<video class="dsc-video" playsinline muted autoplay></video>' +
                '<canvas class="dsc-overlay"></canvas>' +
                '<div class="dsc-flash"></div>' +
                '<div class="dsc-snap" data-snap-wrap aria-hidden="true"><canvas data-snap></canvas><span data-snap-label></span></div>' +
                '<div class="dsc-cam-msg" data-cam-msg hidden></div>' +
            '</div>' +
            '<div class="dsc-cam-top">' +
                '<button type="button" class="dsc-icon-btn" data-act="close" aria-label="Cancel and close"><i class="fa fa-times"></i></button>' +
                '<div class="dsc-cam-status">' +
                    '<span class="dsc-detect" data-detect><span class="dsc-dot"></span><span data-detect-text>Looking for a document…</span></span>' +
                    cvChip() +
                '</div>' +
                '<div class="dsc-cam-tools">' +
                    '<button type="button" class="dsc-icon-btn" data-act="torch" data-torch hidden aria-label="Flashlight" title="Flashlight"><i class="fa fa-bolt"></i></button>' +
                    '<button type="button" class="dsc-icon-btn" data-act="switch" data-switch hidden aria-label="Switch camera" title="Switch camera"><i class="fa fa-refresh"></i></button>' +
                    '<button type="button" class="dsc-icon-btn dsc-auto' + (S.autoCapture ? ' is-on' : '') + '" data-act="auto" aria-pressed="' + S.autoCapture + '" title="Auto-capture when a document is steady"><span>AUTO</span></button>' +
                '</div>' +
            '</div>' +
            '<div class="dsc-cam-bottom">' +
                '<div class="dsc-blur-toast" data-blur-toast role="alert" hidden></div>' +
                (retake
                    ? '<div class="dsc-retake-note"><i class="fa fa-repeat"></i> Retaking a page — capture the replacement <button type="button" class="dsc-link" data-act="cancel-retake">Cancel</button></div>'
                    : '<div class="dsc-seg" role="radiogroup" aria-label="Capture as">' +
                        '<button type="button" role="radio" data-act="mode-doc" class="' + (S.captureMode === 'doc' ? 'is-on' : '') + '" aria-checked="' + (S.captureMode === 'doc') + '"><i class="fa fa-file-o"></i> New document</button>' +
                        '<button type="button" role="radio" data-act="mode-page" class="' + (S.captureMode === 'page' ? 'is-on' : '') + '" aria-checked="' + (S.captureMode === 'page') + '"><i class="fa fa-files-o"></i> Add page' + targetDocLabel() + '</button>' +
                      '</div>') +
                '<div class="dsc-counter" data-counter aria-live="polite">' + counterText() + '</div>' +
                '<div class="dsc-shutter-row">' +
                    '<button type="button" class="dsc-last" data-act="review" aria-label="Review scans">' +
                        (last ? '<img data-thumb="' + last.id + '" src="' + thumbUrl(last) + '" class="' + (thumbUrl(last) ? '' : 'is-empty') + '" alt="">' : '<i class="fa fa-th-large"></i>') +
                        '<span class="dsc-badge" data-badge>' + totalPages() + '</span>' +
                    '</button>' +
                    '<button type="button" class="dsc-shutter" data-act="capture" aria-label="Capture document"><span></span></button>' +
                    (S.ctx.mode === 'form'
                        ? '<button type="button" class="dsc-done" data-act="done" title="Attach to the form" aria-label="Done: attach to the form">Done <i class="fa fa-check"></i></button>'
                        : '<button type="button" class="dsc-done" data-act="review">Done <i class="fa fa-chevron-right"></i></button>') +
                '</div>' +
                '<div class="dsc-cam-alt">' +
                    '<button type="button" class="dsc-link" data-act="native"><i class="fa fa-camera"></i> Phone camera</button>' +
                    '<button type="button" class="dsc-link" data-act="import"><i class="fa fa-picture-o"></i> Import images</button>' +
                '</div>' +
            '</div>';
    }
    function targetDocLabel() {
        var d = S.targetDocId ? findDoc(S.targetDocId) : null;
        if (!d) { return ''; }
        return ' <small>(Doc ' + (S.docs.indexOf(d) + 1) + ')</small>';
    }
    function lastPage() {
        for (var i = S.docs.length - 1; i >= 0; i--) { if (S.docs[i].pages.length) { return S.docs[i].pages[S.docs[i].pages.length - 1]; } }
        return null;
    }
    function camMsg(html) {
        var m = root && root.querySelector('[data-cam-msg]');
        if (!m) { return; }
        if (!html) { m.hidden = true; m.innerHTML = ''; return; }
        m.hidden = false;
        m.innerHTML = html;
    }
    function updateCounter() {
        if (!root) { return; }
        var c = root.querySelector('[data-counter]');
        if (c) { c.textContent = counterText(); }
        var b = root.querySelector('[data-badge]');
        if (b) { b.textContent = totalPages(); }
    }

    function cameraSupported() {
        return !!(navigator.mediaDevices && navigator.mediaDevices.getUserMedia) && window.isSecureContext !== false;
    }
    function startCamera() {
        if (!S || S.screen !== 'camera' || S.stream || S.starting) { return; }
        if (!cameraSupported()) {
            var why = window.isSecureContext === false
                ? 'The live camera only works on a secure <b>https://</b> connection. You can still scan: use your phone\'s camera below — every photo goes through the same edge detection, editing and PDF steps.'
                : 'This browser does not support the live camera. Use your device\'s camera or import images instead.';
            camMsg('<i class="fa fa-video-camera dsc-msg-icon"></i><p>' + why + '</p>' +
                '<div class="dsc-msg-actions"><button type="button" class="btn btn-primary" data-act="native"><i class="fa fa-camera"></i> Take photo</button>' +
                '<button type="button" class="btn btn-light" data-act="import"><i class="fa fa-picture-o"></i> Import images</button></div>');
            root.querySelector('.dsc-shutter').setAttribute('data-act', 'native');
            return;
        }
        camMsg('<span class="dsc-spin dsc-spin-lg"></span><p>Starting camera…</p>');
        // Ask for the sharpest stream the camera offers (browsers pick the closest it supports).
        var video = { width: { ideal: 3840 }, height: { ideal: 2160 } };
        if (S.deviceIndex >= 0 && S.deviceIds[S.deviceIndex]) { video.deviceId = { exact: S.deviceIds[S.deviceIndex] }; }
        else { video.facingMode = { ideal: S.facing }; }
        var myS = S;
        S.starting = true;
        navigator.mediaDevices.getUserMedia({ video: video, audio: false }).catch(function (err) {
            // Some devices reject the resolution hint: retry with defaults.
            if (err && (err.name === 'OverconstrainedError' || err.name === 'ConstraintNotSatisfiedError')) {
                return navigator.mediaDevices.getUserMedia({ video: video.deviceId ? { deviceId: video.deviceId } : { facingMode: S.facing }, audio: false });
            }
            throw err;
        }).then(function (stream) {
            myS.starting = false;
            if (S !== myS || S.screen !== 'camera') { stream.getTracks().forEach(function (t) { t.stop(); }); return; }
            S.stream = stream;
            S.track = stream.getVideoTracks()[0] || null;
            S.torch = false;
            var v = root.querySelector('.dsc-video');
            v.srcObject = stream;
            var p = v.play();
            if (p && p.catch) { p.catch(function () { /* autoplay muted is allowed */ }); }
            v.addEventListener('loadedmetadata', function () { camMsg(''); sizeOverlay(); }, { once: true });
            setupCameraTools();
            startDetectLoop();
        }).catch(function (err) {
            myS.starting = false;
            if (S !== myS) { return; }
            var name = err && err.name;
            var text = name === 'NotAllowedError' || name === 'SecurityError'
                ? 'Camera access was blocked. Allow the camera for this site in the browser settings (the lock icon next to the address), then tap Try again.'
                : (name === 'NotFoundError' || name === 'OverconstrainedError'
                    ? 'No camera was found on this device.'
                    : (name === 'NotReadableError' ? 'The camera is being used by another app. Close it and tap Try again.' : 'The camera could not be started.'));
            camMsg('<i class="fa fa-video-camera dsc-msg-icon"></i><p>' + text + '</p>' +
                '<div class="dsc-msg-actions"><button type="button" class="btn btn-primary" data-act="retry-camera"><i class="fa fa-refresh"></i> Try again</button>' +
                '<button type="button" class="btn btn-light" data-act="native"><i class="fa fa-camera"></i> Phone camera</button>' +
                '<button type="button" class="btn btn-light" data-act="import"><i class="fa fa-picture-o"></i> Import images</button></div>');
        });
    }
    function setupCameraTools() {
        var track = S.track;
        var torchBtn = root.querySelector('[data-torch]');
        try {
            var caps = track && track.getCapabilities ? track.getCapabilities() : {};
            if (torchBtn) { torchBtn.hidden = !caps.torch; torchBtn.classList.remove('is-on'); }
        } catch (e) { if (torchBtn) { torchBtn.hidden = true; } }
        if (navigator.mediaDevices.enumerateDevices) {
            navigator.mediaDevices.enumerateDevices().then(function (list) {
                if (!S || !root) { return; }
                var ids = list.filter(function (d) { return d.kind === 'videoinput'; }).map(function (d) { return d.deviceId; }).filter(Boolean);
                S.deviceIds = ids;
                if (S.deviceIndex < 0 && track && track.getSettings) {
                    var cur = track.getSettings().deviceId;
                    S.deviceIndex = ids.indexOf(cur);
                }
                var sw = root.querySelector('[data-switch]');
                if (sw) { sw.hidden = ids.length < 2; }
            }).catch(function () { /* ignore */ });
        }
    }
    function stopCamera() {
        if (!S) { return; }
        clearTimeout(S.detectTimer);
        S.detectTimer = null;
        if (S.stream) { S.stream.getTracks().forEach(function (t) { try { t.stop(); } catch (e) { /* ignore */ } }); }
        S.stream = null; S.track = null; S.torch = false;
        var v = root && root.querySelector('.dsc-video');
        if (v) { try { v.pause(); } catch (e) { /* ignore */ } v.srcObject = null; }
    }
    function toggleTorch() {
        if (!S.track) { return; }
        var on = !S.torch;
        S.track.applyConstraints({ advanced: [{ torch: on }] }).then(function () {
            S.torch = on;
            var b = root.querySelector('[data-torch]');
            if (b) { b.classList.toggle('is-on', on); b.setAttribute('aria-pressed', String(on)); }
        }).catch(function () { alertMsg('info', 'Flashlight not available', 'This camera does not allow the flashlight to be turned on from the browser.'); });
    }
    function switchCamera() {
        stopCamera();
        if (S.deviceIds.length > 1) {
            S.deviceIndex = (S.deviceIndex + 1) % S.deviceIds.length;
        } else {
            S.deviceIndex = -1;
            S.facing = S.facing === 'environment' ? 'user' : 'environment';
        }
        startCamera();
    }

    /** The displayed (object-fit: contain) rectangle of the video inside its box. */
    function videoRect(v) {
        var bw = v.clientWidth, bh = v.clientHeight, vw = v.videoWidth, vh = v.videoHeight;
        if (!vw || !vh) { return { x: 0, y: 0, w: bw, h: bh }; }
        var s = Math.min(bw / vw, bh / vh);
        return { x: (bw - vw * s) / 2, y: (bh - vh * s) / 2, w: vw * s, h: vh * s };
    }
    function sizeOverlay() {
        if (!root) { return; }
        var ov = root.querySelector('.dsc-overlay'), v = root.querySelector('.dsc-video');
        if (!ov || !v) { return; }
        var dpr = window.devicePixelRatio || 1;
        ov.width = v.clientWidth * dpr; ov.height = v.clientHeight * dpr;
        ov.style.width = v.clientWidth + 'px'; ov.style.height = v.clientHeight + 'px';
    }
    function drawOverlay(q, good) {
        var ov = root && root.querySelector('.dsc-overlay'), v = root && root.querySelector('.dsc-video');
        if (!ov || !v) { return; }
        var ctx = ov.getContext('2d'), dpr = window.devicePixelRatio || 1;
        ctx.setTransform(1, 0, 0, 1, 0, 0);
        ctx.clearRect(0, 0, ov.width, ov.height);
        if (!q) { return; }
        var r = videoRect(v);
        ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
        ctx.beginPath();
        for (var i = 0; i < 4; i++) {
            var x = r.x + q[i * 2] * r.w, y = r.y + q[i * 2 + 1] * r.h;
            if (i === 0) { ctx.moveTo(x, y); } else { ctx.lineTo(x, y); }
        }
        ctx.closePath();
        ctx.fillStyle = good ? 'rgba(9,130,9,.22)' : 'rgba(255,255,255,.12)';
        ctx.fill();
        ctx.lineWidth = 3;
        ctx.strokeStyle = good ? '#3ddc3d' : 'rgba(255,255,255,.85)';
        ctx.stroke();
    }
    function setDetect(state, text) {
        var d = root && root.querySelector('[data-detect]');
        if (!d) { return; }
        d.className = 'dsc-detect' + (state ? ' is-' + state : '');
        d.querySelector('[data-detect-text]').textContent = text;
    }
    function quadDelta(a, b) {
        if (!a || !b) { return 1; }
        var m = 0;
        for (var i = 0; i < 8; i++) { m = Math.max(m, Math.abs(a[i] - b[i])); }
        return m;
    }
    /** Live edge detection: one preview frame at a time goes to the detection worker. */
    var STEADY_MS = 500, AUTO_MS = 1000, frameCanvas = null;
    function grabFrame(v) {
        var s = Math.min(1, DETECT_LONG / Math.max(v.videoWidth, v.videoHeight));
        var w = Math.max(1, Math.round(v.videoWidth * s)), h = Math.max(1, Math.round(v.videoHeight * s));
        if (!frameCanvas) { frameCanvas = document.createElement('canvas'); }
        if (frameCanvas.width !== w || frameCanvas.height !== h) { frameCanvas.width = w; frameCanvas.height = h; }
        var ctx = frameCanvas.getContext('2d', { willReadFrequently: true }) || frameCanvas.getContext('2d');
        ctx.drawImage(v, 0, 0, w, h);
        return { data: ctx.getImageData(0, 0, w, h).data, w: w, h: h };
    }
    function startDetectLoop() {
        clearTimeout(S.detectTimer);
        var myS = S;
        function again() {
            if (S !== myS || S.screen !== 'camera' || !S.stream) { return; }
            S.detectTimer = setTimeout(tick, cvReady() ? 90 : 400);
        }
        function tick() {
            if (S !== myS || S.screen !== 'camera' || !S.stream) { return; }
            var v = root.querySelector('.dsc-video');
            if (cvReady() && v && v.readyState >= 2 && v.videoWidth) {
                var fr;
                try { fr = grabFrame(v); } catch (e) { again(); return; }
                Proc.detect(fr, { minArea: 0.12 }).then(function (r) {
                    if (S === myS && S.screen === 'camera' && S.stream) { onDetected(r ? r.quad : null); }
                }, function () { /* a dropped frame */ }).then(again);
                return;
            }
            if (cvState === 'failed') { setDetect('manual', 'Manual mode — tap the shutter'); }
            again();
        }
        tick();
    }
    function onDetected(q) {
        var now = Date.now();
        if (q) {
            if (quadDelta(q, S.lastQuad) >= 0.025 || !S.stableSince) { S.stableSince = now; }
            S.lastQuad = q;
            var held = now - S.stableSince, steady = held >= STEADY_MS;
            drawOverlay(q, steady);
            if (S.autoCapture && S.autoArmed && !S.retakeBusy) {
                if (held >= AUTO_MS && now - S.lastAutoAt > 1500) {
                    S.lastAutoAt = now;
                    S.autoArmed = false;          // re-armed when the page moves or disappears
                    S.armQuad = q;
                    capture();
                } else {
                    setDetect('ok', steady ? 'Hold steady…' : 'Document detected');
                }
            } else {
                if (!S.autoArmed && quadDelta(q, S.armQuad) > 0.08) { S.autoArmed = true; }
                setDetect('ok', S.autoCapture && !S.autoArmed ? 'Captured — show the next page' : 'Document detected');
            }
        } else {
            S.stableSince = 0; S.lastQuad = null; S.autoArmed = true;
            drawOverlay(null);
            setDetect('', 'Looking for a document…');
        }
    }

    /** Take a frame from the live video. */
    function capture() {
        if (!S) { return; }
        var v = root.querySelector('.dsc-video');
        if (!S.stream || !v || !v.videoWidth) {
            if (!cameraSupported()) { nativeCapture(); }
            return;
        }
        var c = newCanvas(v.videoWidth, v.videoHeight);
        c.getContext('2d').drawImage(v, 0, 0, c.width, c.height);
        var fl = root.querySelector('.dsc-flash');
        if (fl) { fl.classList.remove('is-on'); void fl.offsetWidth; fl.classList.add('is-on'); }
        if (navigator.vibrate) { try { navigator.vibrate(30); } catch (e) { /* ignore */ } }
        addCapture(c, c.width, c.height, true);
    }
    function nativeCapture() { root.querySelector('[data-native-capture]').click(); }
    function importFiles(files) {
        if (!files || !files.length || !S) { return; }
        Array.prototype.forEach.call(files, function (f) {
            if (!/^image\//.test(f.type) && !/\.(jpe?g|png|webp|heic|heif|gif|bmp)$/i.test(f.name)) { return; }
            var placeholder = beginPage();
            S.queue = S.queue.then(function () {
                return loadImage(f).then(function (img) {
                    return processCapture(placeholder, img, imgW(img), imgH(img)).then(function () { releaseImage(img); });
                }).catch(function (e) { failPage(placeholder, e); });
            });
        });
        if (S.afterRetake) { S.afterRetake = false; showScreen('review'); return; }
        if (S.screen === 'review') { renderReview(root.querySelector('[data-screen]')); }
        else { updateCounter(); }
    }
    /** Create the page entry right away (so the counter moves), process it in the queue. */
    function beginPage() {
        var p = { id: uid('p'), w: 0, h: 0, quad: fullQuad(), rot: 0, filter: lastFilter(), bright: 0, contrast: 0, sharpen: 50,
            shape: 'auto', glare: false, deskew: true, autoRot: 0, skew: 0, origVer: 1, ready: false };
        if (S.retake) {
            var f = findPage(S.retake);
            if (f) {
                p = f.page;
                p.ready = false;
                p.origVer = (p.origVer || 1) + 1;
                p.rot = 0;
                S.retake = null;
                S.afterRetake = true;
                return p;
            }
            S.retake = null;
        }
        var doc = null;
        if (S.captureMode === 'page') { doc = findDoc(S.targetDocId) || S.docs[S.docs.length - 1] || null; }
        if (!doc) { doc = newDoc(); S.docs.push(doc); }
        S.targetDocId = doc.id;
        doc.pages.push(p);
        updateCounter();
        return p;
    }
    function addCapture(canvas, w, h, freeAfter) {
        var p = beginPage();
        var wasRetake = !!S.afterRetake;
        S.afterRetake = false;
        S.queue = S.queue.then(function () {
            return processCapture(p, canvas, w, h).catch(function (e) { failPage(p, e); });
        }).then(function () { if (freeAfter) { freeCanvas(canvas); } });
        // After a retake go back where it was started (Review, the editor, or the camera itself).
        if (wasRetake) { var back = S.retakeReturn || 'review'; S.retakeReturn = null; showScreen(back === 'camera' ? 'camera' : 'review'); }
        else if (S.screen === 'camera') {
            // refresh the "Add page (Doc n)" label
            var pageBtn = root.querySelector('[data-act="mode-page"]');
            if (pageBtn) { pageBtn.innerHTML = '<i class="fa fa-files-o"></i> Add page' + targetDocLabel(); }
        }
    }
    /**
     * A new photo: keep a copy (long side ≤ ORIG_MAX), find the page (coarse on a small copy, refined on
     * the full photo), measure sharpness and orientation (all in the workers), store it, make its thumbnail.
     */
    function processCapture(p, src, w, h) {
        var myS = S;
        S.processing++;
        var orig = scaledCanvas(src, ORIG_MAX, w, h);
        p.w = orig.width; p.h = orig.height;
        return analyzePhoto(orig).catch(function () { return { quad: null, sharpness: null, rot: 0, skew: 0 }; }).then(function (a) {
            var q = a.quad && isConvexQuad(a.quad) && C.quadArea(a.quad) >= 0.1 ? a.quad : null;
            p.quad = q || fullQuad();
            p.detQuad = q || null;
            p.autoQuad = !!q;
            p.sharpness = a.sharpness == null ? null : a.sharpness;
            p.sharpLevel = sharpnessLevel(p.sharpness);
            p.blurry = p.sharpLevel === 'blurry';
            p.autoRot = a.rot || 0;
            p.skew = a.skew || 0;
            if (S === myS && S.screen === 'camera') { showSnap(orig, q); }
            return canvasToBlob(orig, 'image/jpeg', 0.92);
        }).then(function (blob) {
            freeCanvas(orig);
            return Store.putBlob(p.id + ':orig', blob);
        }).then(function () {
            if (origCache.id === p.id) { origCache = { id: null, ver: null, img: null }; }
            p.ready = true;
            return makeThumb(p);
        }).then(function () {
            myS.processing--;
            if (S !== myS) { return; }          // the scanner was closed meanwhile (the page is stored)
            persist();
            refreshLast(p);
            if (p.blurry && S.screen === 'camera') { showBlurToast(p); }
            if (S.screen === 'review') { renderReview(root.querySelector('[data-screen]')); }
        }, function (e) { myS.processing--; freeCanvas(orig); throw e; });
    }
    /** Briefly show the captured frame with the detected page outline and corners. */
    function showSnap(src, q) {
        var cv2 = root && root.querySelector('[data-snap]'), v = root && root.querySelector('.dsc-video');
        if (!cv2 || !v) { return; }
        var bw = v.clientWidth, bh = v.clientHeight, dpr = window.devicePixelRatio || 1;
        cv2.width = bw * dpr; cv2.height = bh * dpr;
        var ctx = cv2.getContext('2d');
        ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
        ctx.fillStyle = '#000'; ctx.fillRect(0, 0, bw, bh);
        var s = Math.min(bw / src.width, bh / src.height) * 0.9, w = src.width * s, h = src.height * s, x = (bw - w) / 2, y = (bh - h) / 2;
        ctx.drawImage(src, x, y, w, h);
        if (q) {
            ctx.beginPath();
            for (var i = 0; i < 4; i++) { var px = x + q[i * 2] * w, py = y + q[i * 2 + 1] * h; if (i) { ctx.lineTo(px, py); } else { ctx.moveTo(px, py); } }
            ctx.closePath();
            ctx.fillStyle = 'rgba(9,130,9,.25)'; ctx.fill();
            ctx.lineWidth = 3; ctx.strokeStyle = '#3ddc3d'; ctx.stroke();
            for (var k = 0; k < 4; k++) {
                ctx.beginPath(); ctx.arc(x + q[k * 2] * w, y + q[k * 2 + 1] * h, 8, 0, Math.PI * 2);
                ctx.fillStyle = '#fff'; ctx.fill(); ctx.lineWidth = 3; ctx.strokeStyle = '#098209'; ctx.stroke();
            }
        }
        var label = root.querySelector('[data-snap-label]');
        if (label) { label.textContent = q ? 'Page edges detected — cropped and straightened' : 'No edges found — full photo kept (adjust the corners in Review)'; }
        var wrap = root.querySelector('[data-snap-wrap]');
        wrap.classList.remove('is-on'); void wrap.offsetWidth; wrap.classList.add('is-on');
        clearTimeout(S.snapTimer);
        S.snapTimer = setTimeout(function () { if (wrap) { wrap.classList.remove('is-on'); } }, 900);
    }
    /** The required blur warning, with Retake / Keep. Capturing can continue meanwhile. */
    function showBlurToast(p) {
        var t = root && root.querySelector('[data-blur-toast]');
        if (!t) { return; }
        var f = findPage(p.id);
        t.innerHTML = '<i class="fa fa-exclamation-triangle"></i><span>' + esc(BLUR_MESSAGE) +
            (f ? ' <small>(Doc ' + (f.di + 1) + ' · Page ' + (f.pi + 1) + ')</small>' : '') + '</span>' +
            '<button type="button" class="btn btn-sm btn-light" data-act="retake" data-page="' + p.id + '"><i class="fa fa-repeat"></i> Retake</button>' +
            '<button type="button" class="dsc-link" data-act="blur-keep">Keep</button>';
        t.hidden = false;
        clearTimeout(S.blurTimer);
        S.blurTimer = setTimeout(function () { if (t) { t.hidden = true; } }, 8000);
    }
    function failPage(p, e) {
        if (!S) { return; }
        var f = findPage(p.id);
        if (f) { f.doc.pages.splice(f.pi, 1); if (!f.doc.pages.length) { S.docs.splice(S.docs.indexOf(f.doc), 1); } }
        updateCounter();
        persist();
        var msg = (e && e.name === 'QuotaExceededError') ? 'The device storage is full. Attach the scans you have, then continue.' : ((e && e.message) || 'The image could not be processed.');
        alertMsg('error', 'Capture failed', msg);
    }
    function refreshLast(p) {
        if (!root || S.screen !== 'camera') { return; }
        var btn = root.querySelector('.dsc-last');
        if (!btn) { return; }
        var img = btn.querySelector('img');
        if (!img) {
            btn.innerHTML = '<img data-thumb="' + p.id + '" alt=""><span class="dsc-badge" data-badge>' + totalPages() + '</span>';
            img = btn.querySelector('img');
        }
        img.setAttribute('data-thumb', p.id);
        img.src = thumbUrl(p);
        btn.classList.remove('is-pop'); void btn.offsetWidth; btn.classList.add('is-pop');
    }

    /* ---------- review screen ---------- */
    function renderReview(el) {
        if (!el) { return; }
        var docs = S.docs.filter(function (d) { return d.pages.length; });
        if (docs.length !== S.docs.length) { S.docs = docs; }
        var n = docs.length, sel = selectedDocs(true).length;
        var html =
            '<div class="dsc-bar">' +
                '<button type="button" class="dsc-icon-btn dsc-dark" data-act="close" aria-label="Close"><i class="fa fa-times"></i></button>' +
                '<div class="dsc-bar-title"><b>Review scans</b><span>' + counterText() + (S.processing ? ' · processing ' + S.processing + '…' : '') + '</span></div>' +
                '<button type="button" class="btn btn-primary dsc-bar-next" data-act="to-save"' + (n ? '' : ' disabled') + '>Next <i class="fa fa-chevron-right"></i></button>' +
            '</div>' +
            '<div class="dsc-review-tools">' +
                '<label class="dsc-check"><input type="checkbox" data-act="select-all"' + (n && sel === n ? ' checked' : '') + (n ? '' : ' disabled') + '> Select all</label>' +
                '<span class="dsc-sel-count">' + (sel ? sel + ' selected' : '') + '</span>' +
                '<button type="button" class="btn btn-sm btn-outline-danger" data-act="delete-selected"' + (sel ? '' : ' disabled') + '><i class="fa fa-trash"></i> Delete selected</button>' +
                '<label class="dsc-apply-mode"><span>Mode for ' + (sel ? 'selected' : 'all') + ':</span><select class="form-control form-control-sm" data-act="apply-mode"' + (n ? '' : ' disabled') + '>' +
                    '<option value="">Choose…</option>' +
                    FILTERS.map(function (f) { return '<option value="' + f.id + '">' + f.label + '</option>'; }).join('') +
                '</select></label>' +
                (blurryCount() ? '<span class="dsc-blur-count"><i class="fa fa-exclamation-triangle"></i> ' + plural(blurryCount(), 'blurry page') + '</span>' : '') +
                '<span class="dsc-hint">Drag <i class="fa fa-arrows"></i> to reorder documents, drag pages to reorder or move them.</span>' +
            '</div>' +
            '<div class="dsc-docs" data-docs>';
        if (!n) {
            html += '<div class="dsc-empty"><i class="fa fa-camera"></i><p>No scans yet.</p><button type="button" class="btn btn-primary" data-act="add-doc"><i class="fa fa-plus"></i> Scan a document</button></div>';
        }
        docs.forEach(function (d, di) {
            var checked = S.selected.has(d.id);
            html += '<section class="dsc-doc' + (checked ? ' is-selected' : '') + '" data-doc-id="' + d.id + '">' +
                '<header class="dsc-doc-head">' +
                    '<span class="dsc-doc-handle" title="Drag to reorder" aria-hidden="true"><i class="fa fa-bars"></i></span>' +
                    '<label class="dsc-check"><input type="checkbox" data-act="select-doc" data-doc="' + d.id + '"' + (checked ? ' checked' : '') + '><span class="sr-only">Select</span></label>' +
                    '<div class="dsc-doc-title"><b>Document ' + (di + 1) + '</b><span>' + plural(d.pages.length, 'page') + '</span></div>' +
                    '<div class="dsc-doc-actions">' +
                        '<button type="button" class="dsc-mini" data-act="doc-up" data-doc="' + d.id + '" title="Move up"' + (di ? '' : ' disabled') + '><i class="fa fa-arrow-up"></i></button>' +
                        '<button type="button" class="dsc-mini" data-act="doc-down" data-doc="' + d.id + '" title="Move down"' + (di < n - 1 ? '' : ' disabled') + '><i class="fa fa-arrow-down"></i></button>' +
                        '<button type="button" class="dsc-mini is-danger" data-act="doc-delete" data-doc="' + d.id + '" title="Delete document"><i class="fa fa-trash"></i></button>' +
                    '</div>' +
                '</header>' +
                '<div class="dsc-pages" data-pages="' + d.id + '">';
            d.pages.forEach(function (p, pi) {
                var url = thumbUrl(p);
                html += '<div class="dsc-page" data-page-id="' + p.id + '">' +
                    '<button type="button" class="dsc-page-btn" data-act="edit-page" data-page="' + p.id + '" aria-label="Document ' + (di + 1) + ', page ' + (pi + 1) + ': preview and edit">' +
                        '<img data-thumb="' + p.id + '" src="' + url + '" class="' + (url ? '' : 'is-empty') + '" alt="" draggable="false">' +
                        (p.ready ? '' : '<span class="dsc-page-busy"><span class="dsc-spin"></span></span>') +
                        (p.blurry ? '<span class="dsc-page-warn" title="' + esc(BLUR_MESSAGE) + '"><i class="fa fa-exclamation-triangle"></i> Blurry</span>' : '') +
                    '</button>' +
                    '<span class="dsc-page-no">Doc ' + (di + 1) + ' · Page ' + (pi + 1) + '</span>' +
                    '<div class="dsc-page-acts">' +
                        '<button type="button" data-act="retake" data-page="' + p.id + '" title="Retake"><i class="fa fa-repeat"></i></button>' +
                        '<button type="button" data-act="rotate-page" data-page="' + p.id + '" title="Rotate"><i class="fa fa-rotate-right"></i></button>' +
                        '<button type="button" data-act="delete-page" data-page="' + p.id + '" title="Delete page" class="is-danger"><i class="fa fa-trash"></i></button>' +
                    '</div>' +
                '</div>';
            });
            html += '<button type="button" class="dsc-add-page" data-act="add-page" data-doc="' + d.id + '"><i class="fa fa-plus"></i><span>Add page</span></button>' +
                '</div></section>';
        });
        html += '</div>' +
            '<div class="dsc-footer">' +
                '<button type="button" class="btn btn-light" data-act="add-doc"><i class="fa fa-plus"></i> Add document</button>' +
                '<button type="button" class="btn btn-light" data-act="preview-batch"' + (n ? '' : ' disabled') + '><i class="fa fa-th-list"></i> Preview batch</button>' +
                '<button type="button" class="btn btn-light" data-act="preview"' + (n ? '' : ' disabled') + '><i class="fa fa-eye"></i> Preview PDF</button>' +
                '<button type="button" class="btn btn-primary" data-act="to-save"' + (n ? '' : ' disabled') + '>Save &amp; export <i class="fa fa-chevron-right"></i></button>' +
            '</div>';
        el.innerHTML = html;
        setupSortable(el);
    }
    function blurryCount() {
        return S.docs.reduce(function (n, d) { return n + d.pages.filter(function (p) { return p.blurry; }).length; }, 0);
    }
    /** Batch: one enhancement mode for every page of the selected documents (all when none selected). */
    function applyModeToDocs(mode) {
        if (!FILTERS.some(function (f) { return f.id === mode; })) { return; }
        var docs = selectedDocs(), pages = [];
        docs.forEach(function (d) { d.pages.forEach(function (p) { if (p.filter !== mode) { p.filter = mode; pages.push(p); } }); });
        persist();
        renderReview(root.querySelector('[data-screen]'));
        // regenerate the thumbnails one after another (keeps memory low on phones)
        var chain = Promise.resolve();
        pages.forEach(function (p) { chain = chain.then(function () { return makeThumb(p).catch(function () { /* ignore */ }); }); });
        var label = (FILTERS.filter(function (f) { return f.id === mode; })[0] || {}).label;
        toast(label + ' applied to ' + plural(docs.length, 'document') + '.');
    }
    function toast(text) {
        if (!root) { return; }
        var t = document.createElement('div');
        t.className = 'dsc-toast';
        t.setAttribute('role', 'status');
        t.textContent = text;
        root.appendChild(t);
        setTimeout(function () { t.classList.add('is-out'); }, 2200);
        setTimeout(function () { t.remove(); }, 2700);
    }
    /** Every page of the batch as it will be laid out on the chosen paper. */
    function previewBatch() {
        if (S.processing) { alertMsg('info', 'Still processing', 'Please wait until all captured pages are processed.'); return; }
        var docs = S.docs.filter(function (d) { return d.pages.length; });
        var layer = document.createElement('div');
        layer.className = 'dsc-preview';
        var total = docs.reduce(function (n, d) { return n + d.pages.length; }, 0);
        layer.innerHTML =
            '<div class="dsc-bar">' +
                '<button type="button" class="btn btn-light btn-sm" data-pv="close"><i class="fa fa-times"></i> Close</button>' +
                '<div class="dsc-bar-title"><b>Batch preview</b><span>' + plural(docs.length, 'document') + ' · ' + plural(total, 'page') + ' · ' + esc(PAPERS[S.paper].label) + ', ' + S.orientation + '</span></div><span></span>' +
            '</div>' +
            '<div class="dsc-pv-body"><div class="dsc-pv-pages" data-pv-pages></div></div>';
        root.appendChild(layer);
        var cancelled = false;
        function closeLayer() { cancelled = true; layer.remove(); }
        layer.querySelector('[data-pv="close"]').addEventListener('click', closeLayer);
        S.closePreview = closeLayer;
        var pages = [], labels = [];
        docs.forEach(function (d, di) { d.pages.forEach(function (p, pi) { pages.push(p); labels.push('Document ' + (di + 1) + ' · Page ' + (pi + 1) + (p.blurry ? ' · ⚠ blurry' : '')); }); });
        renderPagePreviews({ pages: pages }, layer.querySelector('[data-pv-pages]'), function () { return cancelled; }, labels);
    }
    function setupSortable(el) {
        if (!window.Sortable) { return; }
        var docsEl = el.querySelector('[data-docs]');
        if (!docsEl) { return; }
        window.Sortable.create(docsEl, {
            handle: '.dsc-doc-handle', draggable: '.dsc-doc', animation: 160,
            onEnd: syncOrderFromDom
        });
        el.querySelectorAll('[data-pages]').forEach(function (strip) {
            window.Sortable.create(strip, {
                group: 'dsc-pages', draggable: '.dsc-page', animation: 160, filter: '.dsc-add-page, .dsc-page-acts',
                preventOnFilter: false, delay: 180, delayOnTouchOnly: true, fallbackTolerance: 4,
                onEnd: syncOrderFromDom
            });
        });
    }
    function syncOrderFromDom() {
        var docsEl = root.querySelector('[data-docs]');
        if (!docsEl) { return; }
        var pageMap = {};
        S.docs.forEach(function (d) { d.pages.forEach(function (p) { pageMap[p.id] = p; }); });
        var newDocs = [];
        docsEl.querySelectorAll('.dsc-doc').forEach(function (sec) {
            var d = findDoc(sec.getAttribute('data-doc-id'));
            if (!d) { return; }
            d.pages = Array.prototype.map.call(sec.querySelectorAll('.dsc-page'), function (pe) { return pageMap[pe.getAttribute('data-page-id')]; }).filter(Boolean);
            newDocs.push(d);
        });
        S.docs = newDocs.filter(function (d) { return d.pages.length; });
        persist();
        // re-render on the next frame so Sortable finishes its own DOM work first
        setTimeout(function () { if (S && S.screen === 'review') { renderReview(root.querySelector('[data-screen]')); } }, 0);
    }
    function selectedDocs(onlyExplicit) {
        var docs = S.docs.filter(function (d) { return d.pages.length; });
        var sel = docs.filter(function (d) { return S.selected.has(d.id); });
        if (onlyExplicit) { return sel; }
        return sel.length ? sel : docs;
    }
    function deletePages(pages) {
        pages.forEach(function (p) {
            pageKeys(p).forEach(function (k) { Store.delBlob(k); });
            thumbUrls.forEach(function (url, k) { if (k.indexOf(p.id + '@') === 0) { URL.revokeObjectURL(url); thumbUrls.delete(k); } });
        });
    }
    function removeDoc(d) {
        deletePages(d.pages);
        S.docs.splice(S.docs.indexOf(d), 1);
        S.selected.delete(d.id);
    }

    /* ---------- editor screen (preview + crop / rotate / filters) ---------- */
    var ED = null;
    function renderEditor(el, pid) {
        var f = findPage(pid);
        if (!f) { showScreen('review'); return; }
        var p = f.page;
        var e0 = editsOf(p);
        e0.quad = e0.quad.slice();
        ED = { pid: pid, edits: e0, hist: [], crop: false, timer: null, renderSeq: 0, cache: {}, chips: { key: null, urls: {}, seq: 0 },
            view: window.innerWidth >= 900 ? 'compare' : 'scan' };
        var lvl = p.sharpLevel || 'unknown';
        var sharpChip = { sharp: '<span class="dsc-sharp is-sharp"><i class="fa fa-check-circle"></i> Sharp</span>',
            soft: '<span class="dsc-sharp is-soft"><i class="fa fa-info-circle"></i> Slightly soft</span>',
            blurry: '<span class="dsc-sharp is-blurry"><i class="fa fa-exclamation-triangle"></i> Blurry</span>',
            unknown: '' }[lvl] || '';
        el.innerHTML =
            '<div class="dsc-bar">' +
                '<button type="button" class="btn btn-light btn-sm" data-act="editor-cancel">Cancel</button>' +
                '<div class="dsc-bar-title"><b>Doc ' + (f.di + 1) + ' · Page ' + (f.pi + 1) + ' ' + sharpChip + '</b><span data-ed-sub>Before / after preview</span></div>' +
                '<button type="button" class="btn btn-light btn-sm dsc-undo" data-act="ed-undo" disabled title="Undo (Ctrl+Z)" aria-label="Undo"><i class="fa fa-undo"></i><span class="dsc-undo-text"> Undo</span></button>' +
                '<button type="button" class="btn btn-primary btn-sm" data-act="editor-done"><i class="fa fa-check"></i> Confirm Scan</button>' +
            '</div>' +
            '<div class="dsc-seg dsc-seg-dark dsc-ed-tabs" role="tablist" data-ed-tabs>' +
                [['photo', 'Photo'], ['crop', 'Cropped'], ['scan', 'Scan'], ['compare', 'Compare']].map(function (v) {
                    return '<button type="button" role="tab" data-act="ed-view" data-view="' + v[0] + '" class="' + (ED.view === v[0] ? 'is-on' : '') + (v[0] === 'compare' ? ' dsc-wide-only' : '') + '" aria-selected="' + (ED.view === v[0]) + '">' + v[1] + '</button>';
                }).join('') +
            '</div>' +
            '<div class="dsc-ed-stage" data-ed-stage>' +
                '<div class="dsc-ed-views" data-views data-mode="' + ED.view + '">' +
                    '<figure class="dsc-view" data-view-box="photo"><img data-view-img="photo" alt="Original photo with the detected page outline"><figcaption>Original photo · detected edges</figcaption></figure>' +
                    '<figure class="dsc-view" data-view-box="crop"><img data-view-img="crop" alt="Cropped and straightened, before enhancement"><figcaption>Cropped &amp; straightened</figcaption></figure>' +
                    '<figure class="dsc-view" data-view-box="scan"><img data-view-img="scan" alt="Final scan"><figcaption data-scan-cap>Final scan · press and hold to see before</figcaption></figure>' +
                '</div>' +
                '<canvas class="dsc-ed-crop" data-ed-crop hidden></canvas>' +
                '<canvas class="dsc-loupe" data-loupe hidden width="240" height="240"></canvas>' +
                '<div class="dsc-ed-busy" data-ed-busy><span class="dsc-spin dsc-spin-lg"></span></div>' +
            '</div>' +
            '<div class="dsc-ed-panel">' +
                '<div class="dsc-ed-row dsc-ed-crop-tools" data-crop-tools hidden>' +
                    '<button type="button" class="btn btn-sm btn-light" data-act="crop-auto"' + (cvReady() ? '' : ' disabled title="Edge detection is not available"') + '><i class="fa fa-magic"></i> Auto detect</button>' +
                    '<button type="button" class="btn btn-sm btn-light" data-act="crop-full"><i class="fa fa-expand"></i> Full image</button>' +
                    '<button type="button" class="btn btn-sm btn-primary" data-act="crop-apply"><i class="fa fa-check"></i> Apply corners</button>' +
                '</div>' +
                '<div data-main-tools>' +
                    (p.blurry ? '<div class="dsc-ed-blur" role="alert"><i class="fa fa-exclamation-triangle"></i><span>' + esc(BLUR_MESSAGE) + '</span>' +
                        '<button type="button" class="btn btn-sm btn-warning" data-act="ed-retake"><i class="fa fa-repeat"></i> Retake</button></div>' : '') +
                    '<div class="dsc-ed-row dsc-chips" role="radiogroup" aria-label="Scan mode">' +
                        FILTERS.map(function (fl) {
                            var on = ED.edits.filter === fl.id;
                            return '<button type="button" role="radio" data-act="filter" data-filter="' + fl.id + '" class="dsc-chip-f' + (on ? ' is-on' : '') + '" aria-checked="' + on + '">' +
                                '<span class="dsc-chip-img"><img data-chip-img="' + fl.id + '" alt="" class="is-empty"></span><span class="dsc-chip-label">' + fl.label + '</span></button>';
                        }).join('') +
                    '</div>' +
                    '<div class="dsc-ed-row dsc-ed-actions">' +
                        '<button type="button" class="dsc-tool" data-act="crop"><i class="fa fa-crop"></i><span>Adjust crop</span></button>' +
                        '<button type="button" class="dsc-tool" data-act="rot-left"><i class="fa fa-rotate-left"></i><span>Rotate left</span></button>' +
                        '<button type="button" class="dsc-tool" data-act="rot-right"><i class="fa fa-rotate-right"></i><span>Rotate right</span></button>' +
                        '<button type="button" class="dsc-tool dsc-toggle" data-act="ed-deskew" aria-pressed="' + ED.edits.deskew + '" title="Turn sideways pages upright and straighten slightly tilted text"><i class="fa fa-align-left"></i><span>Auto-straighten</span></button>' +
                        '<button type="button" class="dsc-tool dsc-toggle" data-act="ed-glare" aria-pressed="' + ED.edits.glare + '" title="Tone down shiny reflections (text that is completely washed out cannot be recovered)"><i class="fa fa-sun-o"></i><span>Reduce glare</span></button>' +
                        '<button type="button" class="dsc-tool" data-act="ed-retake"><i class="fa fa-repeat"></i><span>Retake</span></button>' +
                        '<button type="button" class="dsc-tool" data-act="ed-restore"><i class="fa fa-history"></i><span>Restore original</span></button>' +
                        '<button type="button" class="dsc-tool" data-act="ed-move-prev"' + (f.pi ? '' : ' disabled') + '><i class="fa fa-arrow-left"></i><span>Move earlier</span></button>' +
                        '<button type="button" class="dsc-tool" data-act="ed-move-next"' + (f.pi < f.doc.pages.length - 1 ? '' : ' disabled') + '><i class="fa fa-arrow-right"></i><span>Move later</span></button>' +
                        '<button type="button" class="dsc-tool is-danger" data-act="ed-delete"><i class="fa fa-trash"></i><span>Delete</span></button>' +
                    '</div>' +
                    '<div class="dsc-ed-row dsc-ed-shape">' +
                        '<label><span>Page shape</span><select class="form-control form-control-sm" data-act="ed-shape">' +
                            SHAPE_OPTIONS.map(function (o) { return '<option value="' + o[0] + '"' + (ED.edits.shape === o[0] ? ' selected' : '') + '>' + o[1] + '</option>'; }).join('') +
                        '</select></label>' +
                        '<span class="dsc-shape-note" data-shape-note></span>' +
                    '</div>' +
                    '<div class="dsc-ed-row dsc-sliders">' +
                        sliderHtml('bright', 'fa-sun-o', 'Brightness', -100, 100) +
                        sliderHtml('contrast', 'fa-adjust', 'Contrast', -100, 100) +
                        sliderHtml('sharpen', 'fa-crosshairs', 'Sharpness', 0, 100) +
                        '<button type="button" class="dsc-link" data-act="reset-adjust">Reset</button>' +
                    '</div>' +
                '</div>' +
            '</div>';
        // Press and hold the final scan to see it before enhancement.
        var scanImg = el.querySelector('[data-view-img="scan"]');
        var holding = false;
        function holdStart(e) {
            if (!ED || ED.crop) { return; }
            holding = true;
            if (e.cancelable) { e.preventDefault(); }
            edEnsure('crop').then(function (url) {
                if (holding && url && ED) { scanImg.src = url; el.querySelector('[data-scan-cap]').textContent = 'Before (cropped, no enhancement)'; }
            });
        }
        function holdEnd() {
            if (!holding) { return; }
            holding = false;
            if (ED && ED.cache.scan && ED.cache.scan.url) { scanImg.src = ED.cache.scan.url; }
            var cap = el.querySelector('[data-scan-cap]');
            if (cap) { cap.textContent = 'Final scan · press and hold to see before'; }
        }
        scanImg.addEventListener('pointerdown', holdStart);
        ['pointerup', 'pointerleave', 'pointercancel'].forEach(function (n) { scanImg.addEventListener(n, holdEnd); });
        scanImg.addEventListener('contextmenu', function (e) { e.preventDefault(); });
        edShapeNote();
        edRender();
    }
    function sliderHtml(key, icon, label, min, max) {
        var v = ED.edits[key];
        return '<label><span><i class="fa ' + icon + '"></i> ' + label + ' <output data-out="' + key + '">' + v + '</output></span>' +
            '<input type="range" min="' + min + '" max="' + max + '" step="1" value="' + v + '" data-slider="' + key + '" aria-label="' + label + '"></label>';
    }
    /** Remember the edits before a change, for Undo. */
    function edPush() {
        if (!ED) { return; }
        ED.hist.push(JSON.stringify(ED.edits));
        if (ED.hist.length > 60) { ED.hist.shift(); }
        edUndoState();
    }
    function edUndoState() {
        var b = root && root.querySelector('[data-act="ed-undo"]');
        if (b) { b.disabled = !ED || !ED.hist.length; }
    }
    function edUndo() {
        if (!ED || !ED.hist.length) { return; }
        ED.edits = JSON.parse(ED.hist.pop());
        edUndoState();
        edSyncControls();
        if (ED.crop) { if (ED.cropRedraw) { ED.cropRedraw(); } return; }
        edRender();
    }
    /** Put the controls in line with ED.edits (after Undo / Restore / moving the page). */
    function edSyncControls() {
        if (!ED || !root) { return; }
        var e = ED.edits;
        root.querySelectorAll('[data-act="filter"]').forEach(function (b) { var on = b.getAttribute('data-filter') === e.filter; b.classList.toggle('is-on', on); b.setAttribute('aria-checked', String(on)); });
        root.querySelectorAll('[data-slider]').forEach(function (s) { var k = s.getAttribute('data-slider'); s.value = e[k]; });
        root.querySelectorAll('[data-out]').forEach(function (o) { o.textContent = e[o.getAttribute('data-out')]; });
        var dk = root.querySelector('[data-act="ed-deskew"]'), gl = root.querySelector('[data-act="ed-glare"]'), sh = root.querySelector('[data-act="ed-shape"]');
        if (dk) { dk.setAttribute('aria-pressed', String(e.deskew)); }
        if (gl) { gl.setAttribute('aria-pressed', String(e.glare)); }
        if (sh) { sh.value = e.shape; }
        edShapeNote();
    }
    /** Which paper "Auto" recognised, or why the shape does not apply. */
    function edShapeNote() {
        var n = root && root.querySelector('[data-shape-note]'), f = ED && findPage(ED.pid);
        if (!n || !f) { return; }
        var e = ED.edits, sel = root.querySelector('[data-act="ed-shape"]');
        if (isFullQuad(e.quad)) {
            n.textContent = 'Uncropped photo: shape applies after the corners are set.';
            if (sel) { sel.disabled = true; }
            return;
        }
        if (sel) { sel.disabled = false; }
        var p = f.page, iw = p.w || 1000, ih = p.h || 1000;
        var d = C.pageDims(scaleQuad(e.quad, iw, ih), iw, ih, e.shape === 'free' ? 'free' : 'auto');
        if (e.shape === 'auto') {
            n.textContent = d.shape ? 'Detected: ' + (SHAPE_OPTIONS.filter(function (o) { return o[0] === d.shape; })[0] || [0, d.shape])[1].replace(/ \(.*/, '') : 'Not a standard paper size: kept as measured.';
        } else { n.textContent = ''; }
    }
    /** Cache key per view: a view is only re-rendered when something it depends on changed. */
    function edKey(view) {
        var e = ED.edits;
        if (view === 'photo') { return JSON.stringify(e.quad); }
        if (view === 'crop') { return JSON.stringify([e.quad, e.rot, e.shape, e.deskew]); }
        return JSON.stringify([e.quad, e.rot, e.shape, e.deskew, e.glare, e.filter, e.bright, e.contrast, e.sharpen]);
    }
    /** Render one view (photo / crop / scan) if needed; resolves with its object URL. */
    function edEnsure(view) {
        if (!ED) { return Promise.resolve(null); }
        var key = edKey(view), cached = ED.cache[view] || (ED.cache[view] = { key: null, url: null, jobs: {} });
        if (cached.key === key && cached.url) { return Promise.resolve(cached.url); }
        if (cached.jobs[key]) { return cached.jobs[key]; }
        var f = findPage(ED.pid);
        if (!f) { return Promise.resolve(null); }
        var myED = ED, long = edPreviewLong(), job;
        if (view === 'photo') {
            job = getOriginal(f.page).then(function (img) {
                var c = scaledCanvas(img, long), q = myED.edits.quad, ctx = c.getContext('2d');
                if (!isFullQuad(q)) {
                    ctx.beginPath();
                    for (var i = 0; i < 4; i++) { var x = q[i * 2] * c.width, y = q[i * 2 + 1] * c.height; if (i) { ctx.lineTo(x, y); } else { ctx.moveTo(x, y); } }
                    ctx.closePath();
                    ctx.fillStyle = 'rgba(9,130,9,.18)'; ctx.fill();
                    ctx.lineWidth = Math.max(3, c.width / 250); ctx.strokeStyle = '#3ddc3d'; ctx.stroke();
                    for (var k = 0; k < 4; k++) {
                        ctx.beginPath(); ctx.arc(q[k * 2] * c.width, q[k * 2 + 1] * c.height, Math.max(7, c.width / 90), 0, Math.PI * 2);
                        ctx.fillStyle = '#fff'; ctx.fill(); ctx.lineWidth = Math.max(3, c.width / 300); ctx.strokeStyle = '#098209'; ctx.stroke();
                    }
                }
                return c;
            });
        } else {
            var me = myED.edits;
            var e = view === 'crop' ? { quad: me.quad, rot: me.rot, shape: me.shape, deskew: me.deskew, glare: false, filter: 'original', bright: 0, contrast: 0, sharpen: 50 } : me;
            job = renderPage(f.page, long, JSON.parse(JSON.stringify(e)));
        }
        var promise = job.then(function (c) {
            return canvasToBlob(c, 'image/jpeg', 0.9).then(function (b) {
                freeCanvas(c);
                delete cached.jobs[key];
                // A render that finished after the edits changed again is stale: never show or cache it.
                if (ED !== myED || edKey(view) !== key) { return null; }
                if (cached.url) { URL.revokeObjectURL(cached.url); }
                cached.key = key;
                cached.url = URL.createObjectURL(b);
                return cached.url;
            });
        }, function (err) { delete cached.jobs[key]; throw err; });
        cached.jobs[key] = promise;
        return promise;
    }
    /** Small previews of every mode for this page (rendered after the main view, from one small copy). */
    function edChips() {
        if (!ED || !root) { return Promise.resolve(); }
        var e = ED.edits, key = JSON.stringify([e.quad, e.rot, e.shape, e.deskew, e.glare]);
        if (ED.chips.key === key) { return Promise.resolve(); }
        var f = findPage(ED.pid), myED = ED, seq = ++ED.chips.seq;
        if (!f) { return Promise.resolve(); }
        var base = { quad: e.quad, rot: e.rot, shape: e.shape, deskew: e.deskew, glare: e.glare, filter: 'original', bright: 0, contrast: 0, sharpen: 50 };
        return renderPage(f.page, CHIP_LONG, JSON.parse(JSON.stringify(base))).then(function (c) {
            var px = pixelsOf(c);
            freeCanvas(c);
            return Proc.chips(px, FILTERS.map(function (x) { return x.id; }), f.page.sharpLevel);
        }).then(function (canvases) {
            return Promise.all(canvases.map(function (cc) {
                return canvasToBlob(cc, 'image/jpeg', 0.82).then(function (b) { freeCanvas(cc); return b; });
            }));
        }).then(function (blobs) {
            if (ED !== myED || seq !== ED.chips.seq) { return; }
            ED.chips.key = key;
            FILTERS.forEach(function (fl, i) {
                if (ED.chips.urls[fl.id]) { URL.revokeObjectURL(ED.chips.urls[fl.id]); }
                ED.chips.urls[fl.id] = URL.createObjectURL(blobs[i]);
                var img = root && root.querySelector('[data-chip-img="' + fl.id + '"]');
                if (img) { img.src = ED.chips.urls[fl.id]; img.classList.remove('is-empty'); }
            });
        }).catch(function () { /* the chips are optional */ });
    }
    function edSetView(view) {
        if (!ED || !root) { return; }
        ED.view = view;
        var box = root.querySelector('[data-views]');
        if (box) { box.setAttribute('data-mode', view); }
        root.querySelectorAll('[data-act="ed-view"]').forEach(function (b) {
            var on = b.getAttribute('data-view') === view;
            b.classList.toggle('is-on', on); b.setAttribute('aria-selected', String(on));
        });
        edRender();
    }
    function edPreviewLong() {
        var dpr = Math.min(2, window.devicePixelRatio || 1);
        return Math.min(1800, Math.round(Math.max(window.innerWidth, window.innerHeight) * dpr));
    }
    /** Render the views that are visible now (one after another, to keep memory low), then the chips. */
    function edRender(delay) {
        if (!ED) { return; }
        clearTimeout(ED.timer);
        ED.timer = setTimeout(function () {
            if (!ED || !root || ED.crop) { return; }
            var seq = ++ED.renderSeq;
            var views = ED.view === 'compare' ? ['photo', 'scan'] : [ED.view];
            var busy = root.querySelector('[data-ed-busy]');
            if (busy) { busy.hidden = false; }
            var chain = Promise.resolve();
            views.forEach(function (v) {
                chain = chain.then(function () {
                    if (!ED || seq !== ED.renderSeq) { return; }
                    return edEnsure(v).then(function (url) {
                        var img = root && root.querySelector('[data-view-img="' + v + '"]');
                        if (img && url && ED && seq === ED.renderSeq) { img.src = url; }
                    });
                });
            });
            chain.catch(function (e) {
                alertMsg('error', 'Preview failed', e && e.message);
            }).then(function () {
                var b2 = root && root.querySelector('[data-ed-busy]');
                if (b2 && ED && seq === ED.renderSeq) { b2.hidden = true; }
                if (ED && seq === ED.renderSeq) { edChips(); }
            });
        }, delay || 0);
    }
    function edLeave() {
        if (ED && ED.cache) { Object.keys(ED.cache).forEach(function (k) { if (ED.cache[k].url) { URL.revokeObjectURL(ED.cache[k].url); } }); }
        if (ED && ED.chips) { Object.keys(ED.chips.urls).forEach(function (k) { URL.revokeObjectURL(ED.chips.urls[k]); }); }
        if (ED && ED.cropCleanup) { ED.cropCleanup(); }
        ED = null;
    }
    /** Back to the photo exactly as captured: no crop, no rotation, no straightening, no enhancement. */
    function edRestoreOriginal() {
        confirmMsg({ icon: 'question', title: 'Restore the original photo?', text: 'Cropping, rotation, straightening and enhancement are removed for this page (Undo brings them back). The original capture was never changed.', confirmButtonText: 'Restore original' })
            .then(function (r) {
                if (!r.isConfirmed || !ED) { return; }
                edPush();
                ED.edits = { quad: fullQuad(), rot: 0, filter: 'original', bright: 0, contrast: 0, sharpen: 50, shape: 'auto', glare: false, deskew: false };
                edSyncControls();
                edSetView(ED.view === 'compare' ? 'compare' : 'scan');
                toast('Original photo restored. Use a mode or Adjust crop to process it again.');
            });
    }
    var EDIT_FIELDS = ['quad', 'rot', 'filter', 'bright', 'contrast', 'sharpen', 'shape', 'glare', 'deskew'];
    function edCommit() {
        var f = findPage(ED.pid);
        if (f) {
            var p = f.page, e = ED.edits, cur = editsOf(p);
            var changed = JSON.stringify(EDIT_FIELDS.map(function (k) { return cur[k]; })) !== JSON.stringify(EDIT_FIELDS.map(function (k) { return e[k]; }));
            if (changed) {
                if (e.filter !== cur.filter) { rememberFilter(e.filter); }
                EDIT_FIELDS.forEach(function (k) { p[k] = k === 'quad' ? e.quad.slice() : e[k]; });
                persist();
                makeThumb(p).catch(function () { /* ignore */ });
            }
        }
        edLeave();
        showScreen('review');
    }

    /** Corner editor on the original image: drag a corner, or the middle of a side to move that side. */
    function edStartCrop() {
        var f = findPage(ED.pid);
        if (!f) { return; }
        ED.crop = true;
        var stage = root.querySelector('[data-ed-stage]');
        var cvs = root.querySelector('[data-ed-crop]'), img = root.querySelector('[data-views]'), loupe = root.querySelector('[data-loupe]');
        var tabs = root.querySelector('[data-ed-tabs]');
        if (tabs) { tabs.hidden = true; }
        root.querySelector('[data-crop-tools]').hidden = false;
        root.querySelector('[data-main-tools]').hidden = true;
        root.querySelector('[data-ed-sub]').textContent = 'Drag the corners or the sides to the page edges';
        img.hidden = true;
        cvs.hidden = false;
        var busy = root.querySelector('[data-ed-busy]');
        busy.hidden = false;
        getOriginal(f.page).then(function (orig) {
            busy.hidden = true;
            var iw = imgW(orig), ih = imgH(orig);
            // A display-size copy, so redraws while dragging stay fast.
            var disp = null, layout = null, drag = -1, start = null;
            function doLayout() {
                var sw = stage.clientWidth, sh = stage.clientHeight, pad = 28;
                var s = Math.min((sw - pad * 2) / iw, (sh - pad * 2) / ih);
                var dpr = window.devicePixelRatio || 1;
                layout = { s: s, x: (sw - iw * s) / 2, y: (sh - ih * s) / 2, w: iw * s, h: ih * s, dpr: dpr };
                cvs.width = sw * dpr; cvs.height = sh * dpr;
                cvs.style.width = sw + 'px'; cvs.style.height = sh + 'px';
                freeCanvas(disp);
                disp = scaledCanvas(orig, Math.max(layout.w, layout.h) * dpr, iw, ih);
                draw();
            }
            function pt(i) { return { x: layout.x + ED.edits.quad[i * 2] * layout.w, y: layout.y + ED.edits.quad[i * 2 + 1] * layout.h }; }
            /** Handle k: 0–3 corners, 4–7 the middle of side k-4 → k-3. */
            function handle(k) {
                if (k < 4) { return pt(k); }
                var a = pt(k - 4), b = pt((k - 3) % 4);
                return { x: (a.x + b.x) / 2, y: (a.y + b.y) / 2 };
            }
            function draw() {
                var ctx = cvs.getContext('2d');
                ctx.setTransform(layout.dpr, 0, 0, layout.dpr, 0, 0);
                ctx.clearRect(0, 0, cvs.width, cvs.height);
                ctx.drawImage(disp, layout.x, layout.y, layout.w, layout.h);
                // dim outside the quad
                ctx.save();
                ctx.beginPath();
                ctx.rect(layout.x, layout.y, layout.w, layout.h);
                for (var i = 0; i < 4; i++) { var p0 = pt(3 - i); if (i === 0) { ctx.moveTo(p0.x, p0.y); } else { ctx.lineTo(p0.x, p0.y); } }
                ctx.closePath();
                ctx.fillStyle = 'rgba(0,0,0,.45)';
                ctx.fill('evenodd');
                ctx.restore();
                ctx.beginPath();
                for (var j = 0; j < 4; j++) { var p1 = pt(j); if (j === 0) { ctx.moveTo(p1.x, p1.y); } else { ctx.lineTo(p1.x, p1.y); } }
                ctx.closePath();
                ctx.lineWidth = 2;
                ctx.strokeStyle = isConvexQuad(ED.edits.quad) ? '#3ddc3d' : '#E5484D';
                ctx.stroke();
                for (var m = 4; m < 8; m++) {               // side handles: small pills along the side
                    var a = pt(m - 4), b = pt((m - 3) % 4), c = handle(m), ang = Math.atan2(b.y - a.y, b.x - a.x);
                    ctx.save();
                    ctx.translate(c.x, c.y); ctx.rotate(ang);
                    ctx.beginPath();
                    if (ctx.roundRect) { ctx.roundRect(-14, -5, 28, 10, 5); } else { ctx.rect(-14, -5, 28, 10); }
                    ctx.fillStyle = drag === m ? 'rgba(9,130,9,.9)' : 'rgba(255,255,255,.95)';
                    ctx.fill(); ctx.lineWidth = 2; ctx.strokeStyle = '#098209'; ctx.stroke();
                    ctx.restore();
                }
                for (var k = 0; k < 4; k++) {
                    var p2 = pt(k);
                    ctx.beginPath();
                    ctx.arc(p2.x, p2.y, drag === k ? 14 : 11, 0, Math.PI * 2);
                    ctx.fillStyle = drag === k ? 'rgba(9,130,9,.9)' : 'rgba(255,255,255,.95)';
                    ctx.fill();
                    ctx.lineWidth = 3;
                    ctx.strokeStyle = '#098209';
                    ctx.stroke();
                }
            }
            function drawLoupe(k) {
                var p0 = handle(k), lctx = loupe.getContext('2d'), z = 2.5, r = 120;
                loupe.hidden = false;
                // keep the loupe away from the finger
                loupe.style.left = (p0.x < stage.clientWidth / 2 ? stage.clientWidth - 140 : 20) + 'px';
                lctx.save();
                lctx.clearRect(0, 0, 240, 240);
                lctx.beginPath(); lctx.arc(r, r, r - 2, 0, Math.PI * 2); lctx.clip();
                lctx.fillStyle = '#000'; lctx.fillRect(0, 0, 240, 240);
                var sx = (p0.x - layout.x) / layout.w * disp.width, sy = (p0.y - layout.y) / layout.h * disp.height;
                var span = 240 / z * (disp.width / layout.w);   // source pixels shown in the loupe
                lctx.drawImage(disp, sx - span / 2, sy - span / 2, span, span, 0, 0, 240, 240);
                lctx.strokeStyle = '#3ddc3d'; lctx.lineWidth = 2;
                lctx.beginPath(); lctx.moveTo(r - 14, r); lctx.lineTo(r + 14, r); lctx.moveTo(r, r - 14); lctx.lineTo(r, r + 14); lctx.stroke();
                lctx.restore();
                lctx.beginPath(); lctx.arc(r, r, r - 2, 0, Math.PI * 2); lctx.lineWidth = 4; lctx.strokeStyle = '#fff'; lctx.stroke();
            }
            function local(e) { var r = cvs.getBoundingClientRect(); return { x: e.clientX - r.left, y: e.clientY - r.top }; }
            function clamp01(v) { return Math.min(1, Math.max(0, v)); }
            function down(e) {
                var m = local(e), best = -1, bd = 44;
                for (var k = 0; k < 8; k++) {
                    var h0 = handle(k), d = dist(m.x, m.y, h0.x, h0.y) + (k >= 4 ? 6 : 0);   // corners win ties
                    if (d < bd) { bd = d; best = k; }
                }
                if (best < 0) { return; }
                edPush();
                drag = best;
                start = { m: m, quad: ED.edits.quad.slice() };
                try { cvs.setPointerCapture(e.pointerId); } catch (err) { /* ignore */ }
                e.preventDefault();
                draw(); drawLoupe(best);
            }
            function move(e) {
                if (drag < 0) { return; }
                e.preventDefault();
                var m = local(e), q = ED.edits.quad;
                if (drag < 4) {
                    q[drag * 2] = clamp01((m.x - layout.x) / layout.w);
                    q[drag * 2 + 1] = clamp01((m.y - layout.y) / layout.h);
                } else {
                    var dx = (m.x - start.m.x) / layout.w, dy = (m.y - start.m.y) / layout.h;
                    [drag - 4, (drag - 3) % 4].forEach(function (c) {
                        q[c * 2] = clamp01(start.quad[c * 2] + dx);
                        q[c * 2 + 1] = clamp01(start.quad[c * 2 + 1] + dy);
                    });
                }
                draw(); drawLoupe(drag);
            }
            function up() {
                if (drag < 0) { return; }
                // a tap without movement is not an edit
                if (start && JSON.stringify(start.quad) === JSON.stringify(ED.edits.quad)) { ED.hist.pop(); edUndoState(); }
                drag = -1; start = null; loupe.hidden = true; draw();
            }
            cvs.addEventListener('pointerdown', down);
            cvs.addEventListener('pointermove', move);
            cvs.addEventListener('pointerup', up);
            cvs.addEventListener('pointercancel', up);
            window.addEventListener('resize', doLayout);
            ED.cropRedraw = draw;
            ED.cropCleanup = function () {
                cvs.removeEventListener('pointerdown', down);
                cvs.removeEventListener('pointermove', move);
                cvs.removeEventListener('pointerup', up);
                cvs.removeEventListener('pointercancel', up);
                window.removeEventListener('resize', doLayout);
                freeCanvas(disp);
                ED.cropCleanup = null; ED.cropRedraw = null;
            };
            ED.cropDetect = function () {
                var myED = ED, c = scaledCanvas(orig, ORIG_MAX, iw, ih);
                busy.hidden = false;
                analyzePhoto(c).then(function (a) {
                    freeCanvas(c);
                    busy.hidden = true;
                    if (ED !== myED || !ED.crop) { return; }
                    if (a && a.quad) { edPush(); ED.edits.quad = a.quad; draw(); }
                    else { alertMsg('info', 'No page edge found', 'Drag the corners by hand.'); }
                }, function () { freeCanvas(c); busy.hidden = true; alertMsg('info', 'No page edge found', 'Drag the corners by hand.'); });
            };
            doLayout();
        }).catch(function (e) { busy.hidden = true; alertMsg('error', 'Cannot edit this page', e && e.message); edEndCrop(); });
    }
    function edEndCrop() {
        if (!ED) { return; }
        ED.crop = false;
        if (ED.cropCleanup) { ED.cropCleanup(); }
        root.querySelector('[data-crop-tools]').hidden = true;
        root.querySelector('[data-main-tools]').hidden = false;
        root.querySelector('[data-ed-crop]').hidden = true;
        root.querySelector('[data-loupe]').hidden = true;
        root.querySelector('[data-views]').hidden = false;
        var tabs = root.querySelector('[data-ed-tabs]');
        if (tabs) { tabs.hidden = false; }
        root.querySelector('[data-ed-sub]').textContent = 'Before / after preview';
        edShapeNote();
        edRender();
    }

    /* ---------- save & export screen ---------- */
    function forcedCombine() { return !S.allowExtras && selectedDocs().length > 1; }
    function outMode() { return forcedCombine() ? 'combine' : S.outMode; }
    function baseName() {
        var label = S.ctx.label || MODULE_NOUN[S.ctx.module] || 'Scan';
        return safeName(label) + '_scan_' + today();
    }
    function defaultName(i, n) { return baseName() + (n > 1 ? '_' + ('0' + (i + 1)).slice(-2) : '') + '.pdf'; }
    /** The PDFs to produce: [{key, docs, name, token, target}]. */
    function outputs() {
        var docs = selectedDocs(), list = [];
        if (outMode() === 'combine') {
            var tokens = docs.map(function (d) { return d.token; }).join('.');
            list.push({ key: 'combined', docs: docs, name: ensurePdfName(S.combinedName || defaultName(0, 1)), token: 'c' + hashStr(tokens + '|' + S.paper + S.orientation + S.quality) });
        } else {
            docs.forEach(function (d, i) {
                list.push({ key: d.id, docs: [d], name: ensurePdfName(d.name || defaultName(i, docs.length)), token: d.token + hashStr(S.paper + S.orientation + S.quality) });
            });
        }
        list.forEach(function (o, i) {
            o.target = (isRec() && i === 0 && (S.target === 'main' || !S.allowExtras)) ? 'main' : 'extra';
            o.pages = [];
            o.docs.forEach(function (d) { o.pages = o.pages.concat(d.pages); });
        });
        return list;
    }
    function hashStr(s) {
        var h1 = 0x811c9dc5, h2 = 0x1000193;
        for (var i = 0; i < s.length; i++) { h1 = Math.imul(h1 ^ s.charCodeAt(i), 16777619); h2 = Math.imul(h2 + s.charCodeAt(i), 2246822519); }
        return ((h1 >>> 0).toString(36) + (h2 >>> 0).toString(36)).slice(0, 14);
    }
    /** Uploading straight to a record (its View page, or the top-bar shortcut once a record is chosen). */
    function isRec() { return S.ctx.mode === 'record' || S.ctx.mode === 'pick'; }
    function needsPick() { return S.ctx.mode === 'pick' && !(S.pick && S.pick.chosen); }

    /* ---------- "Attach to": choose an existing record (top-bar Scan shortcut) ---------- */
    var PICK_TYPES = [['recent', 'Recently added'], ['resolution', 'Resolution'], ['ordinance', 'Ordinance'], ['ob', 'Order of Business'], ['committee_report', 'Committee Report']];
    var PICK_NOUN = { resolution: 'Resolution', ordinance: 'Ordinance', ob: 'OB Item', committee_report: 'Committee Report' };
    function recordName(type, r) {
        type = r.type || type;   // the Recently added list mixes types
        if (type === 'committee_report') { return 'Committee Report: ' + r.title; }
        return PICK_NOUN[type] + (r.number ? ' ' + r.number : ' #' + r.id);
    }
    function recordMeta(type, r) {
        type = r.type || type;
        var bits = [];
        if (r.added) { bits.push('added ' + r.added); }
        if (type === 'ob' && r.session) { bits.push('Session ' + r.session); }
        if (r.date && r.date !== r.added) { bits.push(r.date); }
        if (type !== 'committee_report' && r.title) { bits.push(r.title); }
        return bits.join(' · ');
    }
    function pickerHtml() {
        var P = S.pick, html = '<fieldset class="dsc-field dsc-pick"' + (S.busy ? ' disabled' : '') + '><legend>Attach to</legend>';
        if (P.chosen) {
            var r = P.chosen;
            html += '<div class="dsc-pick-chosen"><i class="fa fa-check-circle"></i>' +
                '<div class="dsc-pick-main"><b>' + esc(recordName(P.type, r)) + '</b><span>' + esc(recordMeta(P.type, r)) + '</span></div>' +
                '<button type="button" class="btn btn-sm btn-light" data-act="pick-change">Change</button></div>';
            if (r.fields.length > 1) {
                html += '<div class="dsc-pick-fields">' + r.fields.map(function (f) {
                    return '<label class="dsc-radio"><input type="radio" name="dsc-pick-field" value="' + f.field + '" data-act="pick-field"' + (P.field === f.field ? ' checked' : '') + '> ' + esc(f.label) +
                        ' <small>(' + (f.main_exists ? 'has a file' : (f.has_main ? 'file missing' : 'empty')) + (f.extra_allowed ? '' : ', one file only') + ')</small></label>';
                }).join('') + '</div>';
            }
        } else {
            html += '<div class="dsc-seg dsc-seg-light dsc-pick-types" role="tablist">' + PICK_TYPES.map(function (t) {
                    return '<button type="button" role="tab" data-act="pick-type" data-value="' + t[0] + '" class="' + (P.type === t[0] ? 'is-on' : '') + '" aria-selected="' + (P.type === t[0]) + '">' + t[1] + '</button>';
                }).join('') + '</div>' +
                '<input type="search" class="form-control dsc-pick-q" data-act="pick-q" value="' + esc(P.q) + '" placeholder="Search by number or title…" autocomplete="off" aria-label="Search records">' +
                '<div class="dsc-pick-results" data-pick-results>' + pickResultsHtml() + '</div>';
        }
        return html + '</fieldset>';
    }
    function pickResultsHtml() {
        var P = S.pick;
        if (P.loading && !P.results) { return '<p class="dsc-pick-msg"><span class="dsc-spin"></span> Searching…</p>'; }
        if (P.error) { return '<p class="dsc-pick-msg is-error"><i class="fa fa-exclamation-triangle"></i> ' + esc(P.error) + '</p>'; }
        if (!P.results) { return ''; }
        if (!P.results.length) { return '<p class="dsc-pick-msg">No matching ' + esc(P.type === 'recent' ? 'records' : PICK_TYPES.filter(function (t) { return t[0] === P.type; })[0][1]) + ' found.</p>'; }
        return (P.q ? '' : '<p class="dsc-pick-msg">' + (P.type === 'recent' ? 'Most recently added, all types — type to search everything.' : 'Latest records — type to search.') + '</p>') + '<ul class="dsc-pick-list">' + P.results.map(function (r) {
            var f = r.fields[0];
            var badge = f.main_exists ? '<span class="dsc-pick-badge is-ok">Has attachment</span>'
                : (f.has_main ? '<span class="dsc-pick-badge is-warn">File missing</span>' : '<span class="dsc-pick-badge">No attachment</span>');
            if (r.extra_count) { badge += '<span class="dsc-pick-badge">+' + r.extra_count + ' additional</span>'; }
            return '<li><button type="button" data-act="pick-choose" data-id="' + r.id + '">' +
                '<span class="dsc-pick-main"><b>' + esc(recordName(P.type, r)) + '</b><span>' + esc(recordMeta(P.type, r)) + '</span></span>' +
                '<span class="dsc-pick-badges">' + badge + '</span></button></li>';
        }).join('') + '</ul>';
    }
    function refreshPickResults() {
        var box = root && root.querySelector('[data-pick-results]');
        if (box) { box.innerHTML = pickResultsHtml(); box.classList.toggle('is-loading', !!S.pick.loading); }
    }
    function pickSearch(delay) {
        var P = S.pick;
        clearTimeout(P.timer);
        P.timer = setTimeout(function () {
            var seq = ++P.seq;
            P.loading = true; P.error = '';
            refreshPickResults();
            var xhr = new XMLHttpRequest();
            xhr.open('GET', ROOT + 'scan_records.php?type=' + encodeURIComponent(P.type) + '&q=' + encodeURIComponent(P.q), true);
            xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
            xhr.onload = function () {
                if (!S || S.pick !== P || seq !== P.seq) { return; }
                var data = null;
                try { data = JSON.parse(xhr.responseText); } catch (e) { /* ignore */ }
                P.loading = false;
                if (data && data.success) { P.results = data.records; }
                else { P.results = null; P.error = (data && data.message) || (/login/i.test(xhr.responseURL || '') ? 'Your session has expired. Sign in again.' : 'The records could not be loaded.'); }
                refreshPickResults();
            };
            xhr.onerror = function () {
                if (!S || S.pick !== P || seq !== P.seq) { return; }
                P.loading = false; P.results = null; P.error = 'Network error — check the connection.';
                refreshPickResults();
            };
            xhr.send();
        }, delay || 0);
    }
    function pickChoose(id) {
        var P = S.pick, r = (P.results || []).filter(function (x) { return String(x.id) === String(id); })[0];
        if (!r) { return; }
        P.chosen = r;
        pickField(r.fields[0].field);
    }
    /** Point the upload at the chosen record / attachment field (then the record-mode flow takes over). */
    function pickField(field) {
        var P = S.pick, r = P.chosen, f = r.fields.filter(function (x) { return x.field === field; })[0] || r.fields[0];
        P.field = f.field;
        S.ctx.module = r.type || P.type;
        S.ctx.recordId = String(r.id);
        S.ctx.field = f.field;
        S.ctx.hasMain = !!f.main_exists;
        S.ctx.extraField = f.extra_allowed ? '1' : '';
        S.ctx.label = recordName(P.type, r).replace(/^Committee Report: /, 'Committee Report ').slice(0, 60);
        S.allowExtras = !!f.extra_allowed;
        S.target = f.main_exists ? 'extra' : 'main';
        S.uploads = null;   // default file names now follow the chosen record (typed names are kept)
        renderSave(root.querySelector('[data-screen]'));
    }
    function pickReset() {
        var P = S.pick;
        P.chosen = null; P.field = null;
        S.ctx.module = ''; S.ctx.recordId = ''; S.ctx.field = ''; S.ctx.hasMain = false; S.ctx.label = '';
        S.allowExtras = true; S.target = 'extra'; S.uploads = null;
        renderSave(root.querySelector('[data-screen]'));
        if (!P.results) { pickSearch(); }
        var q = root.querySelector('[data-act="pick-q"]');
        if (q && !isMobile()) { q.focus(); }
    }

    function renderSave(el) {
        var docs = selectedDocs(), all = S.docs.filter(function (d) { return d.pages.length; });
        var outs = outputs(), mode = outMode(), rec = isRec(), pick = S.ctx.mode === 'pick';
        if (pick && !S.pick.results && !S.pick.loading && !S.pick.chosen) { setTimeout(function () { pickSearch(); }, 0); }
        var pagesN = docs.reduce(function (n, d) { return n + d.pages.length; }, 0);
        var html =
            '<div class="dsc-bar">' +
                '<button type="button" class="btn btn-light btn-sm" data-act="back-review"' + (S.busy ? ' disabled' : '') + '><i class="fa fa-chevron-left"></i> Back</button>' +
                '<div class="dsc-bar-title"><b>Save &amp; export</b><span>' + plural(docs.length, 'document') + ' · ' + plural(pagesN, 'page') +
                    (docs.length < all.length ? ' (selected of ' + all.length + ')' : '') + '</span></div>' +
                '<span></span>' +
            '</div>' +
            '<div class="dsc-save">' +
                (pick ? pickerHtml() : '') +
                '<fieldset class="dsc-field"' + (S.busy ? ' disabled' : '') + '><legend>Output</legend>' +
                    '<div class="dsc-cards">' +
                        '<label class="dsc-card' + (mode === 'separate' ? ' is-on' : '') + (forcedCombine() ? ' is-disabled' : '') + '">' +
                            '<input type="radio" name="dsc-out" value="separate" data-act="out-mode"' + (mode === 'separate' ? ' checked' : '') + (forcedCombine() ? ' disabled' : '') + '>' +
                            '<i class="fa fa-files-o"></i><b>Separate PDFs</b><span>One PDF per document (' + docs.length + ')</span></label>' +
                        '<label class="dsc-card' + (mode === 'combine' ? ' is-on' : '') + '">' +
                            '<input type="radio" name="dsc-out" value="combine" data-act="out-mode"' + (mode === 'combine' ? ' checked' : '') + '>' +
                            '<i class="fa fa-file-pdf-o"></i><b>Combine into one PDF</b><span>' + plural(pagesN, 'page') + ' in the order shown</span></label>' +
                    '</div>' +
                    (forcedCombine() ? '<p class="dsc-note"><i class="fa fa-info-circle"></i> This attachment holds one file, so the documents are combined into one PDF.</p>' : '') +
                '</fieldset>' +
                '<fieldset class="dsc-field dsc-grid"' + (S.busy ? ' disabled' : '') + '><legend>Page setup</legend>' +
                    '<label>Paper size<select class="form-control" data-act="paper">' +
                        Object.keys(PAPERS).map(function (k) { return '<option value="' + k + '"' + (S.paper === k ? ' selected' : '') + '>' + PAPERS[k].label + (k === DEFAULT_PAPER ? ' — default' : '') + '</option>'; }).join('') +
                    '</select></label>' +
                    '<label>Orientation<div class="dsc-seg dsc-seg-light" role="radiogroup">' +
                        [['portrait', 'Portrait'], ['landscape', 'Landscape'], ['auto', 'Auto']].map(function (o) {
                            return '<button type="button" role="radio" data-act="orientation" data-value="' + o[0] + '" class="' + (S.orientation === o[0] ? 'is-on' : '') + '" aria-checked="' + (S.orientation === o[0]) + '">' + o[1] + '</button>';
                        }).join('') +
                    '</div></label>' +
                    '<label>Quality<select class="form-control" data-act="quality">' +
                        Object.keys(QUALITIES).map(function (k) { return '<option value="' + k + '"' + (S.quality === k ? ' selected' : '') + '>' + QUALITIES[k].label + '</option>'; }).join('') +
                    '</select></label>' +
                    '<p class="dsc-note dsc-span">Pages are fitted inside the paper with a 0.25 in margin, keeping their proportions (no stretching or cutting). <b>Auto</b> turns wide pages to landscape.</p>' +
                '</fieldset>' +
                '<fieldset class="dsc-field"' + (S.busy ? ' disabled' : '') + '><legend>File name' + (outs.length > 1 ? 's' : '') + '</legend>' +
                    '<div class="dsc-names">' +
                        outs.map(function (o, i) {
                            var pn = o.pages.length;
                            return '<label class="dsc-name"><span class="dsc-name-tag">' + (mode === 'combine' ? 'Combined' : 'Document ' + (S.docs.indexOf(o.docs[0]) + 1)) +
                                ' · ' + plural(pn, 'page') + (rec ? ' · ' + (o.target === 'main' ? '<b>main attachment</b>' : 'additional') : (i === 0 ? ' · <b>attachment field</b>' : ' · additional')) + '</span>' +
                                '<input type="text" class="form-control" data-act="rename" data-key="' + o.key + '" value="' + esc(o.name) + '" maxlength="120" spellcheck="false"></label>';
                        }).join('') +
                    '</div>' +
                '</fieldset>' +
                (needsPick() ? '' : (rec ? saveTargetHtml(outs) : formTargetHtml(outs))) +
                '<div class="dsc-uploads" data-uploads>' + uploadsHtml() + '</div>' +
            '</div>' +
            '<div class="dsc-footer">' +
                (needsPick() ? '<span class="dsc-footer-hint"><i class="fa fa-hand-o-up"></i> Choose the record to attach to</span>' : '') +
                '<button type="button" class="btn btn-light" data-act="preview"' + (S.busy ? ' disabled' : '') + '><i class="fa fa-eye"></i> Preview PDF</button>' +
                '<button type="button" class="btn btn-primary" data-act="save-all"' + (S.busy || !docs.length || needsPick() ? ' disabled' : '') + '>' +
                    (S.busy ? '<span class="dsc-spin"></span> Working…' : (rec ? '<i class="fa fa-cloud-upload"></i> Save &amp; Attach All' : '<i class="fa fa-paperclip"></i> Attach All to Form')) +
                '</button>' +
            '</div>';
        el.innerHTML = html;
    }
    function saveTargetHtml(outs) {
        if (!S.ctx.hasMain) {
            return '<p class="dsc-note"><i class="fa fa-info-circle"></i> This record has no attachment yet: the first PDF becomes its attachment' +
                (outs.length > 1 ? ', the others are added as additional attachments.' : '.') + '</p>';
        }
        if (!S.allowExtras) {
            return '<p class="dsc-note is-warn"><i class="fa fa-exclamation-triangle"></i> This attachment holds one file: saving <b>replaces</b> the current file.</p>';
        }
        return '<fieldset class="dsc-field"' + (S.busy ? ' disabled' : '') + '><legend>Attach as</legend>' +
            '<label class="dsc-radio"><input type="radio" name="dsc-target" value="extra" data-act="target"' + (S.target === 'extra' ? ' checked' : '') + '> Additional attachment' + (outs.length > 1 ? 's' : '') + ' <small>(the current attachment is kept)</small></label>' +
            '<label class="dsc-radio"><input type="radio" name="dsc-target" value="main" data-act="target"' + (S.target === 'main' ? ' checked' : '') + '> Replace the current attachment with the first PDF' + (outs.length > 1 ? ' <small>(the others are added as additional attachments)</small>' : '') + '</label>' +
            '</fieldset>';
    }
    function formTargetHtml(outs) {
        var extra = outs.length - 1;
        return '<p class="dsc-note"><i class="fa fa-info-circle"></i> The ' + (outs.length > 1 ? 'first ' : '') + 'PDF is placed in the form\'s attachment field' +
            (extra > 0 ? ' and the other ' + plural(extra, 'PDF') + ' are saved as additional attachments' : '') +
            ' when you click <b>Save</b> / <b>Update</b> on the form.</p>';
    }
    function uploadsHtml() {
        if (!S.uploads) { return ''; }
        var failed = S.uploads.filter(function (u) { return u.status === 'failed'; }).length;
        return '<div class="dsc-up-head"><b>Progress</b>' + (failed && !S.busy ? '<button type="button" class="btn btn-sm btn-outline-primary" data-act="retry-failed"><i class="fa fa-refresh"></i> Retry failed (' + failed + ')</button>' : '') + '</div>' +
            S.uploads.map(function (u, i) {
                var icon = { waiting: 'fa-clock-o', building: 'fa-cog fa-spin', uploading: 'fa-cloud-upload', done: 'fa-check-circle', failed: 'fa-times-circle' }[u.status] || 'fa-clock-o';
                var txt = {
                    waiting: 'Waiting', building: 'Creating PDF… page ' + (u.step || 0) + ' of ' + u.pages,
                    uploading: 'Uploading ' + (u.pct || 0) + '%' + (u.size ? ' of ' + fmtBytes(u.size) : ''),
                    done: (u.message || 'Attached') + (u.size ? ' · ' + fmtBytes(u.size) : ''), failed: u.error || 'Failed'
                }[u.status];
                var pct = u.status === 'done' ? 100 : (u.status === 'uploading' ? (u.pct || 0) : (u.status === 'building' ? Math.round((u.step || 0) / u.pages * 100) : 0));
                return '<div class="dsc-up is-' + u.status + '"><i class="fa ' + icon + '"></i><div class="dsc-up-main"><span class="dsc-up-name">' + esc(u.name) + '</span>' +
                    '<span class="dsc-up-txt">' + esc(txt) + '</span><span class="dsc-progress"><span style="width:' + pct + '%"></span></span></div>' +
                    (u.status === 'failed' && !S.busy ? '<button type="button" class="btn btn-sm btn-light" data-act="retry-one" data-index="' + i + '">Retry</button>' : '') + '</div>';
            }).join('');
    }
    function refreshUploads() {
        var box = root && root.querySelector('[data-uploads]');
        if (box) { box.innerHTML = uploadsHtml(); }
    }
    function pdfOpts(o) { return { paper: S.paper, orientation: S.orientation, quality: S.quality, title: o.name.replace(/\.pdf$/i, '') }; }

    /** Upload one PDF; resolves with the server reply, rejects with an Error (message for the user). */
    function uploadPdf(o, blob, onProgress) {
        return new Promise(function (resolve, reject) {
            var fd = new FormData();
            fd.append('module', S.ctx.module);
            fd.append('record_id', S.ctx.recordId);
            fd.append('field', S.ctx.field);
            fd.append('target', o.target);
            fd.append('client_token', o.token);
            fd.append('paper', S.paper);
            fd.append('orientation', S.orientation);
            fd.append('file', blob, o.name);
            var xhr = new XMLHttpRequest();
            xhr.open('POST', ROOT + 'scan_attach.php', true);
            xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
            xhr.timeout = 10 * 60 * 1000;
            xhr.upload.onprogress = function (e) { if (e.lengthComputable) { onProgress(Math.round(e.loaded / e.total * 100)); } };
            xhr.onload = function () {
                var data = null;
                try { data = JSON.parse(xhr.responseText); } catch (e) { /* not JSON */ }
                if (xhr.status >= 200 && xhr.status < 300 && data && data.success) { resolve(data); return; }
                if (!data && /login/i.test(xhr.responseURL || '')) { reject(new Error('Your session has expired. Sign in again in another tab, then tap Retry.')); return; }
                reject(new Error((data && data.message) || ('Server error (' + xhr.status + ').')));
            };
            xhr.onerror = function () { reject(new Error('Network error — check the connection and tap Retry.')); };
            xhr.ontimeout = function () { reject(new Error('The upload timed out. Tap Retry.')); };
            xhr.send(fd);
        });
    }

    function saveAll(onlyIndex) {
        if (S.busy) { return; }
        if (!window.jspdf) { alertMsg('error', 'PDF library missing', 'The PDF library did not load. Reload the page and try again.'); return; }
        if (S.processing) { alertMsg('info', 'Still processing', 'Please wait until all captured pages are processed.'); return; }
        if (S.ctx.mode === 'form') { attachToForm(); return; }
        if (needsPick()) { alertMsg('info', 'Choose a record', 'Choose the Resolution, Ordinance, Order of Business item or Committee Report to attach the scans to.'); return; }
        if (!S.uploads || onlyIndex == null && !S.uploads.some(function (u) { return u.status === 'failed'; })) {
            S.uploads = outputs().map(function (o) { return Object.assign({ status: 'waiting', pages: o.pages.length }, o); });
        }
        var list = S.uploads;
        var targets = list.map(function (u, i) { return i; }).filter(function (i) {
            return onlyIndex != null ? i === onlyIndex : list[i].status !== 'done';
        });
        if (list[0] && list[0].target === 'main' && S.ctx.hasMain && list[0].status !== 'done') {
            confirmMsg({ title: 'Replace the current attachment?', text: 'The record\'s current attachment will be replaced by "' + list[0].name + '". The old file stays on the server.', confirmButtonText: 'Replace' })
                .then(function (r) { if (r.isConfirmed) { runUploads(list, targets); } });
            return;
        }
        runUploads(list, targets);
    }
    function runUploads(list, idxs) {
        S.busy = true;
        renderSave(root.querySelector('[data-screen]'));
        var chain = Promise.resolve();
        idxs.forEach(function (i) {
            chain = chain.then(function () {
                var u = list[i];
                u.status = 'building'; u.step = 0; u.error = '';
                refreshUploads();
                return buildPdf(u.pages, pdfOpts(u), function (n) { u.step = n; refreshUploads(); }).then(function (blob) {
                    if (blob.size > S.ctx.maxPost - 65536) {
                        throw new Error('This PDF is ' + fmtBytes(blob.size) + ', larger than the server accepts (' + fmtBytes(S.ctx.maxPost) + '). Use Separate PDFs or the Compact quality.');
                    }
                    u.size = blob.size; u.status = 'uploading'; u.pct = 0;
                    refreshUploads();
                    return uploadPdf(u, blob, function (pct) { u.pct = pct; refreshUploads(); });
                }).then(function (res) {
                    u.status = 'done';
                    u.message = res.duplicate ? 'Already attached' : (u.target === 'main' ? 'Attached as main file' : 'Attached');
                    if (u.target === 'main') { S.ctx.hasMain = true; }
                    // These scans are attached: remove them from the device.
                    u.docs.forEach(function (d) {
                        var live = findDoc(d.id);
                        if (live) { removeDoc(live); }
                    });
                    persist(true);
                    refreshUploads();
                }).catch(function (e) {
                    u.status = 'failed';
                    u.error = (e && e.message) || 'Failed';
                    refreshUploads();
                });
            });
        });
        chain.then(function () {
            S.busy = false;
            var done = list.filter(function (u) { return u.status === 'done'; }).length;
            var failed = list.length - done;
            renderSave(root.querySelector('[data-screen]'));
            var pick = S.ctx.mode === 'pick';
            if (!failed && pick) {
                pickDone(done);
            } else if (!failed) {
                S.reloadOnClose = true;
                var Sw = swal();
                (Sw ? Sw.fire({ icon: 'success', title: plural(done, 'PDF') + ' attached', text: 'The scanned documents were saved to this record.', confirmButtonText: 'OK', confirmButtonColor: '#098209' }) : Promise.resolve())
                    .then(function () { Store.delSession(S.ctx.key).then(close); });
            } else {
                if (done && !pick) { S.reloadOnClose = true; }
                alertMsg('warning', done ? 'Some PDFs were not attached' : 'Nothing was attached',
                    plural(failed, 'PDF') + ' failed. ' + (done ? plural(done, 'PDF') + ' were attached. ' : '') + 'Your scans for the failed ones are kept — tap Retry.');
            }
        });
    }

    /** Top-bar shortcut finished: the page is not reloaded; offer to open the record or keep scanning. */
    function pickDone(done) {
        var P = S.pick, r = P.chosen, name = recordName(P.type, r), url = ROOT + r.view_url;
        var key = S.ctx.key;
        Store.delSession(key);
        // the record now has a main file / more extras; refresh what the picker shows for it
        r.extra_count = (r.extra_count || 0) + S.uploads.filter(function (u) { return u.status === 'done' && u.target === 'extra'; }).length;
        r.fields.forEach(function (f) { if (f.field === P.field && S.ctx.hasMain) { f.has_main = true; f.main_exists = true; } });
        var Sw = swal();
        if (!Sw) { window.alert(plural(done, 'PDF') + ' attached to ' + name + '.'); close(); return; }
        Sw.fire({
            icon: 'success', title: plural(done, 'PDF') + ' attached',
            html: 'Saved to <b>' + esc(name) + '</b>' + (recordMeta(P.type, r) ? '<br><small>' + esc(recordMeta(P.type, r)) + '</small>' : '') + '.',
            showDenyButton: true, showCancelButton: true,
            confirmButtonText: '<i class="fa fa-external-link"></i> Open record', denyButtonText: '<i class="fa fa-camera"></i> Scan more', cancelButtonText: 'Close',
            confirmButtonColor: '#098209', denyButtonColor: '#3B82F6', reverseButtons: false
        }).then(function (res) {
            if (!S) { return; }
            if (res.isConfirmed) { close(); window.location.href = url; }
            else if (res.isDenied) {
                // keep the chosen record for the next batch
                S.uploads = null; S.selected = new Set(); S.captureMode = 'doc';
                showScreen('camera');
            } else { close(); }
        });
    }

    /** Done on a form: attach the scan straight away (Review / Save & export remain one tap away). */
    function finishToForm() {
        if (!totalPages() || S.ctx.mode !== 'form') { showScreen('review'); return; }
        if (S.busy) { return; }
        var myS = S;
        S.uploads = null;
        showScreen('save');
        // photos still being processed finish first
        S.queue.then(function () {
            if (S !== myS || S.screen !== 'save') { return; }
            if (!totalPages()) { showScreen('review'); return; }
            saveAll();
        });
    }

    /** Form mode: put the PDFs into the form's file inputs. */
    function attachToForm() {
        var input = S.ctx.input;
        if (!input) { alertMsg('error', 'No attachment field', 'This form has no attachment field to receive the scan.'); return; }
        if (typeof DataTransfer === 'undefined') {
            alertMsg('error', 'Not supported in this browser', 'This browser cannot place the scan into the form. Save the record first, then scan from its View page.');
            return;
        }
        try { new DataTransfer(); } catch (e) {
            alertMsg('error', 'Not supported in this browser', 'This browser cannot place the scan into the form. Save the record first, then scan from its View page.');
            return;
        }
        var outs = outputs();
        S.busy = true;
        S.uploads = outs.map(function (o) { return Object.assign({ status: 'waiting', pages: o.pages.length }, o); });
        renderSave(root.querySelector('[data-screen]'));
        var files = [], chain = Promise.resolve(), failedMsg = null;
        S.uploads.forEach(function (u) {
            chain = chain.then(function () {
                if (failedMsg) { return; }
                u.status = 'building'; refreshUploads();
                return buildPdf(u.pages, pdfOpts(u), function (n) { u.step = n; refreshUploads(); }).then(function (blob) {
                    u.size = blob.size;
                    u.status = 'done'; u.message = 'Ready';
                    files.push(new File([blob], u.name, { type: 'application/pdf', lastModified: Date.now() }));
                    refreshUploads();
                }).catch(function (e) { u.status = 'failed'; u.error = e.message; failedMsg = e.message; refreshUploads(); });
            });
        });
        chain.then(function () {
            S.busy = false;
            if (failedMsg) { renderSave(root.querySelector('[data-screen]')); alertMsg('error', 'The PDF could not be created', failedMsg); return; }
            var total = files.reduce(function (n, f) { return n + f.size; }, 0);
            var tooBig = total > S.ctx.maxPost - 1048576;
            var tooMany = files.length > S.ctx.maxFiles - 1;
            if (tooBig || tooMany) {
                renderSave(root.querySelector('[data-screen]'));
                alertMsg('warning', 'Too much for one form save',
                    'These PDFs total ' + fmtBytes(total) + ' in ' + plural(files.length, 'file') + '; one form save accepts up to ' + fmtBytes(S.ctx.maxPost) + ' and ' + (S.ctx.maxFiles - 1) +
                    ' files. Combine into one PDF, choose Compact quality, or save the record first and scan from its View page.');
                return;
            }
            placeFiles(files);
            S.uploads = null;
            var Sw = swal();
            (Sw ? Sw.fire({ icon: 'success', title: 'Attached to the form', text: plural(files.length, 'PDF') + ' ready. Click ' + formSaveLabel() + ' on the form to store ' + (files.length > 1 ? 'them' : 'it') + '. Your scans stay on this device until the form is saved.', confirmButtonColor: '#098209' }) : Promise.resolve())
                .then(function () { persist(true).then(close); });
        });
    }
    function formSaveLabel() {
        var form = S.ctx.input && S.ctx.input.form;
        var b = form && form.querySelector('#save_btn, button[type=submit]');
        return b ? '"' + (b.textContent || 'Save').trim() + '"' : '"Save"';
    }
    function placeFiles(files) {
        var input = S.ctx.input, btn = S.ctx.btn, key = S.ctx.key;
        var dt = new DataTransfer();
        dt.items.add(files[0]);
        input.files = dt.files;
        input.dispatchEvent(new Event('change', { bubbles: true }));
        input.setAttribute('data-scanned', '1');
        var form = input.form || btn.closest('form');
        var holder = scanHolder(btn);
        holder.innerHTML = '';
        if (files.length > 1 && S.ctx.extraField) {
            var ex = document.createElement('input');
            ex.type = 'file'; ex.name = S.ctx.extraField; ex.multiple = true; ex.className = 'dsc-hidden'; ex.setAttribute('data-scan-extras', '1');
            var dt2 = new DataTransfer();
            files.slice(1).forEach(function (f) { dt2.items.add(f); });
            ex.files = dt2.files;
            holder.appendChild(ex);
        }
        if (form && !form.querySelector('input[name="scan_extra_info"]')) {
            var info = document.createElement('input');
            info.type = 'hidden'; info.name = 'scan_extra_info';
            form.appendChild(info);
        }
        if (form) { form.querySelector('input[name="scan_extra_info"]').value = JSON.stringify({ paper: S.paper, orientation: S.orientation }); }
        var note = document.createElement('div');
        note.className = 'dsc-form-note';
        note.innerHTML = '<i class="fa fa-check-circle"></i> <span>Scanned: <b>' + esc(files[0].name) + '</b>' +
            (files.length > 1 ? ' + ' + plural(files.length - 1, 'additional PDF') : '') +
            ' (' + fmtBytes(files.reduce(function (n, f) { return n + f.size; }, 0)) + ') — saved with the form.</span> ' +
            '<button type="button" class="dsc-link" data-scan-clear>Remove scan</button>';
        holder.appendChild(note);
        note.querySelector('[data-scan-clear]').addEventListener('click', function () {
            if (input.getAttribute('data-scanned') === '1') {
                input.value = '';
                input.removeAttribute('data-scanned');
                input.dispatchEvent(new Event('change', { bubbles: true }));
            }
            holder.innerHTML = '';
            clearSessionKeys([key]);       // the next Scan starts fresh
        });
    }
    /** Container after the input-group holding the extra-files input and the note. */
    function scanHolder(btn) {
        var group = btn.closest('.input-group') || btn.parentNode;
        var next = group.nextElementSibling;
        if (next && next.classList.contains('dsc-form-scan')) { return next; }
        var h = document.createElement('div');
        h.className = 'dsc-form-scan';
        group.parentNode.insertBefore(h, group.nextSibling);
        return h;
    }

    /* ---------- PDF preview ---------- */
    function preview() {
        if (S.busy) { return; }
        if (S.processing) { alertMsg('info', 'Still processing', 'Please wait until all captured pages are processed.'); return; }
        var outs = outputs();
        if (!outs.length) { return; }
        showPreview(outs, 0);
    }
    function showPreview(outs, index) {
        var o = outs[index];
        var layer = document.createElement('div');
        layer.className = 'dsc-preview';
        layer.innerHTML =
            '<div class="dsc-bar">' +
                '<button type="button" class="btn btn-light btn-sm" data-pv="close"><i class="fa fa-times"></i> Close</button>' +
                '<div class="dsc-bar-title"><b>PDF preview</b><span>' + esc(o.name) + ' · ' + plural(o.pages.length, 'page') + ' · ' + esc(PAPERS[S.paper].label) + ', ' + S.orientation + '</span></div>' +
                (outs.length > 1 ? '<select class="form-control form-control-sm dsc-pv-pick" data-pv="pick">' + outs.map(function (x, i) { return '<option value="' + i + '"' + (i === index ? ' selected' : '') + '>' + esc(x.name) + '</option>'; }).join('') + '</select>' : '<span></span>') +
            '</div>' +
            '<div class="dsc-pv-body" data-pv-body><div class="dsc-pv-wait"><span class="dsc-spin dsc-spin-lg"></span><p data-pv-step>Creating PDF…</p></div></div>';
        root.appendChild(layer);
        var url = null, cancelled = false;
        function closeLayer() { cancelled = true; if (url) { URL.revokeObjectURL(url); } layer.remove(); }
        layer.addEventListener('click', function (e) {
            var t = e.target.closest('[data-pv]');
            if (t && t.getAttribute('data-pv') === 'close') { closeLayer(); }
        });
        layer.addEventListener('change', function (e) {
            if (e.target.getAttribute('data-pv') === 'pick') { closeLayer(); showPreview(outs, parseInt(e.target.value, 10)); }
        });
        S.closePreview = closeLayer;
        buildPdf(o.pages, pdfOpts(o), function (n, t) {
            var st = layer.querySelector('[data-pv-step]');
            if (st) { st.textContent = 'Creating PDF… page ' + n + ' of ' + t; }
        }).then(function (blob) {
            if (cancelled) { return; }
            url = URL.createObjectURL(blob);
            var body = layer.querySelector('[data-pv-body]');
            var info = '<div class="dsc-pv-info"><span><i class="fa fa-file-pdf-o"></i> ' + plural(o.pages.length, 'page') + ' · ' + fmtBytes(blob.size) + '</span>' +
                '<a class="btn btn-sm btn-primary" href="' + url + '" target="_blank" rel="noopener"><i class="fa fa-external-link"></i> Open PDF</a>' +
                '<a class="btn btn-sm btn-light" href="' + url + '" download="' + esc(o.name) + '"><i class="fa fa-download"></i> Download</a></div>';
            if (isMobile()) {
                // Mobile browsers do not show PDFs inside a page: show the pages as laid out in the PDF.
                body.innerHTML = info + '<div class="dsc-pv-pages" data-pv-pages></div>';
                renderPagePreviews(o, layer.querySelector('[data-pv-pages]'), function () { return cancelled; });
            } else {
                body.innerHTML = info + '<iframe class="dsc-pv-frame" title="PDF preview" src="' + url + '"></iframe>';
            }
        }).catch(function (e) {
            if (cancelled) { return; }
            layer.querySelector('[data-pv-body]').innerHTML = '<div class="dsc-pv-wait"><i class="fa fa-exclamation-triangle dsc-msg-icon"></i><p>' + esc(e.message) + '</p></div>';
        });
    }
    function renderPagePreviews(o, box, isCancelled, labels) {
        var i = 0;
        (function next() {
            if (i >= o.pages.length || isCancelled()) { return; }
            var p = o.pages[i++];
            renderPage(p, 900).then(function (c) {
                if (isCancelled()) { freeCanvas(c); return; }
                var land = S.orientation === 'landscape' || (S.orientation === 'auto' && c.width > c.height);
                var ps = pageSize(S.paper, land);
                var scale = 600 / ps.w;
                var pc = newCanvas(ps.w * scale, ps.h * scale), ctx = pc.getContext('2d');
                ctx.fillStyle = '#fff'; ctx.fillRect(0, 0, pc.width, pc.height);
                var fit = fitOnPage(c.width, c.height, ps.w, ps.h, PDF_MARGIN);
                ctx.drawImage(c, fit.x * scale, fit.y * scale, fit.w * scale, fit.h * scale);
                freeCanvas(c);
                return canvasToBlob(pc, 'image/jpeg', 0.85).then(function (b) {
                    freeCanvas(pc);
                    var fig = document.createElement('figure');
                    var img = document.createElement('img');
                    img.src = URL.createObjectURL(b);
                    img.onload = function () { URL.revokeObjectURL(img.src); };
                    img.alt = 'Page ' + i;
                    fig.appendChild(img);
                    var cap = document.createElement('figcaption');
                    cap.textContent = labels ? labels[i - 1] : 'Page ' + i + ' of ' + o.pages.length;
                    fig.appendChild(cap);
                    box.appendChild(fig);
                });
            }).then(next, next);
        })();
    }

    /* ---------- events ---------- */
    function onClick(e) {
        var t = e.target.closest('[data-act]');
        if (!t || !root.contains(t) || t.disabled) { return; }
        var act = t.getAttribute('data-act');
        if (t.tagName === 'INPUT' && (t.type === 'checkbox' || t.type === 'radio')) { return; } // handled on change
        switch (act) {
            case 'close': requestClose(); break;
            case 'capture': capture(); break;
            case 'native': nativeCapture(); break;
            case 'import': root.querySelector('[data-import]').click(); break;
            case 'retry-camera': stopCamera(); startCamera(); break;
            case 'torch': toggleTorch(); break;
            case 'switch': switchCamera(); break;
            case 'auto':
                S.autoCapture = !S.autoCapture;
                t.classList.toggle('is-on', S.autoCapture);
                t.setAttribute('aria-pressed', String(S.autoCapture));
                if (S.autoCapture && cvState === 'failed') { alertMsg('info', 'Auto-capture unavailable', 'Edge detection could not be loaded on this device, so pages must be captured with the shutter button.'); S.autoCapture = false; t.classList.remove('is-on'); }
                break;
            case 'mode-doc': case 'mode-page':
                S.captureMode = act === 'mode-doc' ? 'doc' : 'page';
                root.querySelectorAll('[data-act="mode-doc"],[data-act="mode-page"]').forEach(function (b) {
                    var on = b.getAttribute('data-act') === act;
                    b.classList.toggle('is-on', on); b.setAttribute('aria-checked', String(on));
                });
                break;
            case 'cancel-retake': { var rb = S.retakeReturn; S.retake = null; S.retakeReturn = null; showScreen(rb === 'camera' ? 'camera' : 'review'); break; }
            case 'blur-keep': { var bt = root.querySelector('[data-blur-toast]'); if (bt) { bt.hidden = true; } break; }
            case 'review': showScreen('review'); break;
            case 'done': finishToForm(); break;
            case 'add-doc': S.captureMode = 'doc'; S.retake = null; showScreen('camera'); break;
            case 'add-page': S.captureMode = 'page'; S.targetDocId = t.getAttribute('data-doc'); S.retake = null; showScreen('camera'); break;
            case 'to-save':
                if (S.processing) { alertMsg('info', 'Still processing', 'Please wait a moment until all captured pages are processed.'); break; }
                S.uploads = null; showScreen('save'); break;
            case 'back-review': S.uploads = null; showScreen('review'); break;
            case 'preview': preview(); break;
            case 'preview-batch': previewBatch(); break;
            case 'doc-up': case 'doc-down': {
                var d = findDoc(t.getAttribute('data-doc')), i = S.docs.indexOf(d), j = act === 'doc-up' ? i - 1 : i + 1;
                if (d && j >= 0 && j < S.docs.length) { S.docs.splice(i, 1); S.docs.splice(j, 0, d); persist(); renderReview(root.querySelector('[data-screen]')); }
                break;
            }
            case 'doc-delete': {
                var dd = findDoc(t.getAttribute('data-doc'));
                if (!dd) { break; }
                confirmMsg({ title: 'Delete document ' + (S.docs.indexOf(dd) + 1) + '?', text: plural(dd.pages.length, 'page') + ' will be removed.', confirmButtonText: 'Delete', confirmButtonColor: '#E5484D' })
                    .then(function (r) { if (r.isConfirmed) { removeDoc(dd); persist(); renderReview(root.querySelector('[data-screen]')); } });
                break;
            }
            case 'delete-selected': {
                var sel = selectedDocs(true);
                if (!sel.length) { break; }
                var np = sel.reduce(function (n, x) { return n + x.pages.length; }, 0);
                confirmMsg({ title: 'Delete ' + plural(sel.length, 'document') + '?', text: plural(np, 'page') + ' will be removed.', confirmButtonText: 'Delete', confirmButtonColor: '#E5484D' })
                    .then(function (r) { if (r.isConfirmed) { sel.forEach(removeDoc); persist(); renderReview(root.querySelector('[data-screen]')); } });
                break;
            }
            case 'edit-page': showScreen('editor', t.getAttribute('data-page')); break;
            case 'retake': startRetake(t.getAttribute('data-page')); break;
            case 'rotate-page': {
                var fp = findPage(t.getAttribute('data-page'));
                if (fp) { fp.page.rot = ((fp.page.rot || 0) + 90) % 360; persist(); makeThumb(fp.page).catch(function () { /* ignore */ }); }
                break;
            }
            case 'delete-page': {
                var fd = findPage(t.getAttribute('data-page'));
                if (!fd) { break; }
                confirmMsg({ title: 'Delete this page?', text: 'Document ' + (fd.di + 1) + ', page ' + (fd.pi + 1) + '.', confirmButtonText: 'Delete', confirmButtonColor: '#E5484D' })
                    .then(function (r) {
                        if (!r.isConfirmed) { return; }
                        deletePages([fd.page]);
                        fd.doc.pages.splice(fd.doc.pages.indexOf(fd.page), 1);
                        if (!fd.doc.pages.length) { removeDoc(fd.doc); }
                        persist();
                        if (S.screen === 'editor') { edLeave(); showScreen('review'); } else { renderReview(root.querySelector('[data-screen]')); }
                    });
                break;
            }
            // editor
            case 'editor-cancel': edLeave(); showScreen('review'); break;
            case 'editor-done': if (ED && ED.crop) { edEndCrop(); } edCommit(); break;
            case 'crop': edStartCrop(); break;
            case 'ed-view': edSetView(t.getAttribute('data-view')); break;
            case 'ed-restore': edRestoreOriginal(); break;
            case 'crop-auto': if (ED && ED.cropDetect) { ED.cropDetect(); } break;
            case 'crop-full': if (ED) { edPush(); ED.edits.quad = fullQuad(); if (ED.cropRedraw) { ED.cropRedraw(); } } break;
            case 'ed-undo': edUndo(); break;
            case 'ed-deskew': case 'ed-glare': {
                if (!ED) { break; }
                var fld = act === 'ed-deskew' ? 'deskew' : 'glare';
                edPush();
                ED.edits[fld] = !ED.edits[fld];
                t.setAttribute('aria-pressed', String(ED.edits[fld]));
                edRender();
                break;
            }
            case 'crop-apply':
                if (ED && !isConvexQuad(ED.edits.quad)) { alertMsg('warning', 'Corners crossed', 'Move the corners so the outline does not cross itself.'); break; }
                edEndCrop(); break;
            case 'rot-left': if (ED) { edPush(); ED.edits.rot = (ED.edits.rot + 270) % 360; edRender(); } break;
            case 'rot-right': if (ED) { edPush(); ED.edits.rot = (ED.edits.rot + 90) % 360; edRender(); } break;
            case 'filter':
                if (!ED || ED.edits.filter === t.getAttribute('data-filter')) { break; }
                edPush();
                ED.edits.filter = t.getAttribute('data-filter');
                root.querySelectorAll('[data-act="filter"]').forEach(function (b) { var on = b === t; b.classList.toggle('is-on', on); b.setAttribute('aria-checked', String(on)); });
                edRender();
                break;
            case 'reset-adjust':
                if (!ED || (!ED.edits.bright && !ED.edits.contrast && ED.edits.sharpen === 50)) { break; }
                edPush();
                ED.edits.bright = 0; ED.edits.contrast = 0; ED.edits.sharpen = 50;
                edSyncControls();
                edRender();
                break;
            case 'ed-retake': { var pid = ED && ED.pid; edLeave(); startRetake(pid); break; }
            case 'ed-move-prev': case 'ed-move-next': {
                var fm = ED && findPage(ED.pid);
                if (!fm) { break; }
                var to = act === 'ed-move-prev' ? fm.pi - 1 : fm.pi + 1;
                if (to < 0 || to >= fm.doc.pages.length) { break; }
                fm.doc.pages.splice(fm.pi, 1); fm.doc.pages.splice(to, 0, fm.page);
                persist();
                var keep = ED.edits, keepHist = ED.hist;
                edLeave();
                renderEditor(root.querySelector('[data-screen]'), fm.page.id);
                ED.edits = keep; ED.hist = keepHist;
                edUndoState(); edSyncControls();
                edRender();
                break;
            }
            case 'ed-delete': {
                var fx = ED && findPage(ED.pid);
                if (fx) { var del = document.createElement('button'); del.setAttribute('data-act', 'delete-page'); del.setAttribute('data-page', fx.page.id); root.appendChild(del); del.click(); del.remove(); }
                break;
            }
            // save
            case 'orientation':
                S.orientation = t.getAttribute('data-value'); S.uploads = null; renderSave(root.querySelector('[data-screen]')); break;
            case 'save-all': saveAll(); break;
            // "Attach to" picker (top-bar shortcut)
            case 'pick-type':
                if (S.pick.type !== t.getAttribute('data-value')) {
                    S.pick.type = t.getAttribute('data-value'); S.pick.results = null; S.pick.error = '';
                    root.querySelectorAll('[data-act="pick-type"]').forEach(function (b) { var on = b === t; b.classList.toggle('is-on', on); b.setAttribute('aria-selected', String(on)); });
                    pickSearch();
                }
                break;
            case 'pick-choose': pickChoose(t.getAttribute('data-id')); break;
            case 'pick-change': pickReset(); break;
            case 'retry-failed': saveAll(); break;
            case 'retry-one': saveAll(parseInt(t.getAttribute('data-index'), 10)); break;
        }
    }
    function onChange(e) {
        var t = e.target, act = t.getAttribute && t.getAttribute('data-act');
        if (t.hasAttribute && t.hasAttribute('data-slider') && ED) { ED.sliding = null; return; }   // drag finished
        if (!act) { return; }
        if (act === 'select-all') {
            S.selected = new Set(t.checked ? S.docs.map(function (d) { return d.id; }) : []);
            renderReview(root.querySelector('[data-screen]'));
        } else if (act === 'select-doc') {
            if (t.checked) { S.selected.add(t.getAttribute('data-doc')); } else { S.selected.delete(t.getAttribute('data-doc')); }
            renderReview(root.querySelector('[data-screen]'));
        } else if (act === 'pick-field') {
            pickField(t.value);
        } else if (act === 'apply-mode') {
            if (t.value) { applyModeToDocs(t.value); rememberFilter(t.value); }
        } else if (act === 'ed-shape') {
            if (ED && ED.edits.shape !== t.value) { edPush(); ED.edits.shape = t.value; edShapeNote(); edRender(); }
        } else if (act === 'out-mode') {
            S.outMode = t.value; S.uploads = null; renderSave(root.querySelector('[data-screen]'));
        } else if (act === 'paper') {
            S.paper = PAPERS[t.value] ? t.value : DEFAULT_PAPER; S.uploads = null; renderSave(root.querySelector('[data-screen]'));
        } else if (act === 'quality') {
            S.quality = QUALITIES[t.value] ? t.value : 'standard'; S.uploads = null;
        } else if (act === 'target') {
            S.target = t.value; S.uploads = null; renderSave(root.querySelector('[data-screen]'));
        } else if (act === 'rename') {
            t.value = ensurePdfName(t.value);
            setName(t.getAttribute('data-key'), t.value);
        }
    }
    function onInput(e) {
        var t = e.target;
        if (t.hasAttribute && t.hasAttribute('data-slider') && ED) {
            var k = t.getAttribute('data-slider');
            if (ED.sliding !== k) { edPush(); ED.sliding = k; }
            ED.edits[k] = parseInt(t.value, 10) || 0;
            var out = root.querySelector('[data-out="' + k + '"]');
            if (out) { out.textContent = ED.edits[k]; }
            edRender(120);
        } else if (t.getAttribute && t.getAttribute('data-act') === 'pick-q') {
            S.pick.q = t.value.trim();
            pickSearch(250);
        } else if (t.getAttribute && t.getAttribute('data-act') === 'rename') {
            setName(t.getAttribute('data-key'), t.value);
        }
    }
    function setName(key, v) {
        if (key === 'combined') { S.combinedName = v; }
        else { var d = findDoc(key); if (d) { d.name = v; } }
        if (S.uploads) { S.uploads.forEach(function (u) { if (u.key === key && u.status !== 'done') { u.name = ensurePdfName(v); } }); }
        persist();
    }
    function startRetake(pid) {
        if (!findPage(pid)) { return; }
        S.retakeReturn = S.screen === 'camera' ? 'camera' : 'review';
        S.retake = pid;
        showScreen('camera');
    }
    function onKey(e) {
        if (S && ED && (e.ctrlKey || e.metaKey) && !e.shiftKey && (e.key === 'z' || e.key === 'Z')) {
            var tg = e.target && e.target.tagName;
            if (tg !== 'INPUT' || (e.target.type === 'range')) { e.preventDefault(); edUndo(); }
            return;
        }
        if (!S || (e.key !== 'Escape' && e.key !== 'Esc')) { return; }
        var Sw = swal();
        if (Sw && Sw.isVisible && Sw.isVisible()) { return; }
        e.preventDefault();
        e.stopPropagation();
        if (e.stopImmediatePropagation) { e.stopImmediatePropagation(); }
        var pv = root && root.querySelector('.dsc-preview');
        if (pv && S.closePreview) { S.closePreview(); return; }
        if (S.screen === 'editor') { if (ED && ED.crop) { edEndCrop(); } else { edLeave(); showScreen('review'); } }
        else if (S.screen === 'save') { if (!S.busy) { S.uploads = null; showScreen('review'); } }
        else if (S.screen === 'camera' && S.retake) { S.retake = null; showScreen('review'); }
        else { requestClose(); }
    }
    window.addEventListener('keydown', onKey, true);
    window.addEventListener('resize', function () { if (S && S.screen === 'camera') { sizeOverlay(); } });
    document.addEventListener('visibilitychange', function () {
        if (!S || S.screen !== 'camera') { return; }
        if (document.hidden) { stopCamera(); } else { startCamera(); }
    });
    window.addEventListener('pagehide', function () { if (S) { stopCamera(); persist(true); } });
    window.addEventListener('beforeunload', function (e) {
        if (S && S.busy) { e.preventDefault(); e.returnValue = ''; }
    });

    /* =====================================================================
       Page wiring: scan buttons, attachment list deletes, form guards
       ===================================================================== */
    document.addEventListener('click', function (e) {
        var btn = e.target.closest && e.target.closest('[data-dms-scan]');
        if (btn) {
            e.preventDefault();
            if (!window.jspdf || !window.DscCore) {
                alertMsg('error', 'Scanner not ready', 'The scanner files are still loading or could not be loaded. Reload the page and try again.');
                return;
            }
            open(ctxFromButton(btn));
            return;
        }
        var del = e.target.closest && e.target.closest('[data-ra-delete]');
        if (del) {
            e.preventDefault();
            deleteAttachment(del);
        }
    });

    function deleteAttachment(btn) {
        var id = btn.getAttribute('data-ra-delete'), name = btn.getAttribute('data-ra-name') || 'this file';
        confirmMsg({ title: 'Delete attachment?', text: '"' + name + '" will be removed from this record and the server.', confirmButtonText: 'Delete', confirmButtonColor: '#E5484D' })
            .then(function (r) {
                if (!r.isConfirmed) { return; }
                btn.disabled = true;
                var fd = new FormData();
                fd.append('op', 'delete');
                fd.append('id', id);
                var xhr = new XMLHttpRequest();
                xhr.open('POST', ROOT + 'attachment_action.php', true);
                xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
                xhr.onload = function () {
                    var data = null;
                    try { data = JSON.parse(xhr.responseText); } catch (err) { /* ignore */ }
                    if (data && data.success) {
                        var li = btn.closest('.dms-ra-item'), box = btn.closest('.dms-ra');
                        if (li) { li.remove(); }
                        if (box) {
                            var left = box.querySelectorAll('.dms-ra-item').length;
                            if (!left) { box.remove(); } else { var c = box.querySelector('.dms-ra-count'); if (c) { c.textContent = left; } }
                        }
                    } else {
                        btn.disabled = false;
                        alertMsg('error', 'Not deleted', (data && data.message) || 'The attachment could not be deleted.');
                    }
                };
                xhr.onerror = function () { btn.disabled = false; alertMsg('error', 'Not deleted', 'Network error.'); };
                xhr.send(fd);
            });
    }

    /** Before a form with scanned extras is submitted: keep it under the server's limits. */
    document.addEventListener('submit', function (e) {
        var form = e.target;
        if (!form || !form.querySelector) { return; }
        var extras = form.querySelectorAll('input[data-scan-extras]');
        if (!extras.length) { return; }
        var btn = form.querySelector('[data-dms-scan]');
        var maxPost = btn ? (parseInt(btn.getAttribute('data-max-post'), 10) || 40 * 1048576) : 40 * 1048576;
        var maxFiles = btn ? (parseInt(btn.getAttribute('data-max-files'), 10) || 20) : 20;
        var total = 0, count = 0;
        form.querySelectorAll('input[type=file]').forEach(function (inp) {
            Array.prototype.forEach.call(inp.files || [], function (f) { total += f.size; count++; });
        });
        if (total > maxPost - 524288 || count > maxFiles) {
            e.preventDefault();
            if (e.stopImmediatePropagation) { e.stopImmediatePropagation(); }
            alertMsg('warning', 'Attachments too large',
                'The attached files total ' + fmtBytes(total) + ' in ' + plural(count, 'file') + '. One save accepts up to ' + fmtBytes(maxPost) + ' and ' + maxFiles +
                ' files. Remove the scan and combine it into one PDF (or use Compact quality).');
        }
    }, true);

    // After a successful save the page asks to forget the scans kept for that form.
    clearSessionKeys(window.__dmsScanClear || []);
    window.__dmsScanClear = { push: function (k) { clearSessionKeys([k]); } };
    pruneOldSessions();

    window.DmsScanner = {
        open: function (opts) { open(Object.assign({ mode: 'form', key: 'scan:' + (opts && opts.module || '') }, opts || {})); },
        isOpen: function () { return !!S; },
        clearSession: function (key) { return clearSessionKeys([key]); },
        // exposed for tests (tests/scanner/browser-smoke.js)
        _test: { core: C, PAPERS: PAPERS, pageSize: pageSize, BLUR_MESSAGE: BLUR_MESSAGE, editsOf: editsOf, Proc: Proc,
            startProcessors: startProcessors, cvState: function () { return cvState; }, usesWorkers: function () { return !!(procW && !procW.dead()); },
            canvasFrom: canvasFrom, pixelsOf: pixelsOf, analyzePhoto: analyzePhoto }
    };
})();
