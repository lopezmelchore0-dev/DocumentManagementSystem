/**
 * Browser smoke test of the real scanner (headless Chrome, fake camera):
 *   npm run test:browser
 * 1. OpenCV in its worker, page detection + refinement on a photo-like image (worker and no-worker modes)
 * 2. The whole UI flow in form mode: open → camera (fake device) → import a photo → review → editor
 *    (filter chips, mode change, undo, crop handles, page shape) → Attach to form → PDF in the file input
 * Fails on any console error or uncaught exception.
 */
'use strict';
const http = require('http');
const fs = require('fs');
const path = require('path');
const { withBrowser } = require('./cdp');

const ROOT = path.resolve(__dirname, '..', '..');
const TYPES = { '.html': 'text/html', '.js': 'text/javascript', '.css': 'text/css', '.png': 'image/png', '.jpg': 'image/jpeg', '.wasm': 'application/wasm', '.json': 'application/json' };

function serve() {
    return new Promise((resolve) => {
        const srv = http.createServer((req, res) => {
            const rel = decodeURIComponent(req.url.split('?')[0]).replace(/^\/+/, '');
            const file = path.resolve(ROOT, rel);
            if (!file.startsWith(ROOT) || !fs.existsSync(file) || fs.statSync(file).isDirectory()) { res.writeHead(404); res.end(); return; }
            res.writeHead(200, { 'Content-Type': TYPES[path.extname(file)] || 'application/octet-stream', 'Cache-Control': 'no-store' });
            fs.createReadStream(file).pipe(res);
        });
        srv.listen(0, '127.0.0.1', () => resolve(srv));
    });
}

// In-page helpers (evaluated once per page load).
const HELPERS = `
window.__t = {
  sleep: (ms) => new Promise((r) => setTimeout(r, ms)),
  async until(fn, ms, what) {
    const end = Date.now() + (ms || 30000);
    for (;;) { let v; try { v = fn(); } catch (e) { v = false; } if (v) { return v; } if (Date.now() > end) { throw new Error('Timed out: ' + (what || fn)); } await new Promise((r) => setTimeout(r, 100)); }
  },
  /** A photo-like scene: the text page turned 8° and shrunk on a dark textured desk; returns {canvas, quad}. */
  async scene() {
    const img = await createImageBitmap(await (await fetch('../fixtures/text-page.png')).blob());
    const W = 1600, H = 1200, c = document.createElement('canvas'); c.width = W; c.height = H;
    const x = c.getContext('2d');
    x.fillStyle = '#3b342c'; x.fillRect(0, 0, W, H);
    for (let i = 0; i < 4000; i++) { x.fillStyle = 'rgba(255,255,255,' + (Math.random() * 0.06) + ')'; x.fillRect(Math.random() * W, Math.random() * H, 3, 3); }
    const s = 0.72, a = 8 * Math.PI / 180, cx = W / 2, cy = H / 2;
    x.setTransform(s * Math.cos(a), s * Math.sin(a), -s * Math.sin(a), s * Math.cos(a), cx, cy);
    x.drawImage(img, -img.width / 2, -img.height / 2);
    x.setTransform(1, 0, 0, 1, 0, 0);
    const q = [];
    for (const [px, py] of [[-1, -1], [1, -1], [1, 1], [-1, 1]]) {
      const u = px * img.width / 2, v = py * img.height / 2;
      q.push((cx + s * (u * Math.cos(a) - v * Math.sin(a))) / W, (cy + s * (u * Math.sin(a) + v * Math.cos(a))) / H);
    }
    return { canvas: c, quad: q };
  },
  quadErr(a, b) { let m = 0; for (let i = 0; i < 8; i++) { m = Math.max(m, Math.abs(a[i] - b[i])); } return m; },
  click(sel) { const el = document.querySelector(sel); if (!el) { throw new Error('missing ' + sel); } el.click(); return true; }
};
true`;

async function checkDetection(page, label) {
    const r = await page.eval(`(async () => {
        const T = window.DmsScanner._test;
        T.startProcessors();
        await __t.until(() => T.cvState() === 'ready' || T.cvState() === 'failed', 90000, 'OpenCV');
        if (T.cvState() !== 'ready') { throw new Error('edge detection failed to load'); }
        const sc = await __t.scene();
        const t0 = performance.now();
        const a = await T.analyzePhoto(sc.canvas);
        const ms = Math.round(performance.now() - t0);
        // a full-size page (Folio at 200 dpi ≈ 2600 px) with Auto Enhance: how long is the page frozen?
        let longest = 0;
        const obs = new PerformanceObserver((l) => { l.getEntries().forEach((e) => { longest = Math.max(longest, e.duration); }); });
        obs.observe({ entryTypes: ['longtask'] });
        const big = document.createElement('canvas'); big.width = 1700; big.height = 2600;
        big.getContext('2d').drawImage(sc.canvas, 0, 0, 1700, 2600);
        const t1 = performance.now();
        await T.Proc.render({ sw: 1700, sh: 2600, quad: null, rot: 0, skew: 0, glare: false, filter: 'enhanced', bright: 0, contrast: 0, sharpen: 50 }, T.pixelsOf(big));
        const renderMs = Math.round(performance.now() - t1);
        await __t.sleep(50);
        obs.disconnect();
        return { workers: T.usesWorkers(), ms, err: a.quad ? __t.quadErr(a.quad, sc.quad) : null,
                 sharp: a.sharpness, rot: a.rot, skew: a.skew, renderMs, longest: Math.round(longest) };
    })()`);
    console.log(`  [${label}] workers=${r.workers} analyze=${r.ms}ms cornerErr=${r.err == null ? 'none' : (r.err * 100).toFixed(2) + '%'} sharpness=${r.sharp && Math.round(r.sharp)} rot=${r.rot} skew=${r.skew}`);
    console.log(`  [${label}] full page Auto Enhance: ${r.renderMs}ms, longest main-thread block ${r.longest}ms`);
    if (r.workers && r.longest > 300) { throw new Error(label + ': the page froze for ' + r.longest + 'ms while processing'); }
    if (r.err == null || r.err > 0.006) { throw new Error(label + ': page not found precisely (' + r.err + ')'); }
    if (r.rot !== 0 || Math.abs(r.skew) > 0.5) { throw new Error(label + ': wrong orientation ' + JSON.stringify(r)); }
    return r;
}

async function uiFlow(page, label) {
    // open the scanner from the Scan button; the fake camera starts
    await page.eval(`(async () => {
        __t.click('[data-dms-scan]');
        await __t.until(() => document.querySelector('.dsc .dsc-camera'), 10000, 'camera screen');
        await __t.until(() => { const v = document.querySelector('.dsc-video'); return v && v.videoWidth > 0; }, 15000, 'fake camera video');
        await __t.sleep(800);   // let the live detection loop run a few frames
        return true;
    })()`);
    // import the photo-like scene
    await page.eval(`(async () => {
        const sc = await __t.scene();
        const blob = await new Promise((r) => sc.canvas.toBlob(r, 'image/jpeg', 0.92));
        const dt = new DataTransfer(); dt.items.add(new File([blob], 'photo.jpg', { type: 'image/jpeg' }));
        const inp = document.querySelector('[data-import]'); inp.files = dt.files; inp.dispatchEvent(new Event('change', { bubbles: true }));
        await __t.until(() => /1 page/.test((document.querySelector('[data-counter]') || {}).textContent || ''), 5000, 'counter');
        __t.click('[data-act="review"]');
        await __t.until(() => document.querySelector('.dsc-page') && !document.querySelector('.dsc-page-busy'), 60000, 'page processed');
        await __t.until(() => { const i = document.querySelector('.dsc-page img'); return i && /^blob:/.test(i.src); }, 30000, 'thumbnail');
        return true;
    })()`);
    // editor: views, chips, a mode change, undo, crop handles, shape
    const ed = await page.eval(`(async () => {
        __t.click('.dsc-page-btn');
        const scanImg = () => document.querySelector('[data-view-img="scan"]');
        await __t.until(() => scanImg() && /^blob:/.test(scanImg().src), 60000, 'scan preview');
        await __t.until(() => document.querySelectorAll('[data-chip-img]:not(.is-empty)').length === document.querySelectorAll('[data-chip-img]').length, 60000, 'filter chips');
        const chips = document.querySelectorAll('[data-chip-img]').length;
        const before = scanImg().src, startFilter = document.querySelector('[data-act="filter"].is-on').dataset.filter;
        __t.click('[data-act="filter"][data-filter="bw"]');
        await __t.until(() => scanImg().src !== before, 60000, 'B&W render');
        const undoOn = !document.querySelector('[data-act="ed-undo"]').disabled;
        __t.click('[data-act="ed-undo"]');
        const afterUndo = document.querySelector('[data-act="filter"].is-on').dataset.filter;
        __t.click('[data-act="ed-glare"]');
        __t.click('[data-act="ed-deskew"]');
        const sel = document.querySelector('[data-act="ed-shape"]'); sel.value = 'a4'; sel.dispatchEvent(new Event('change', { bubbles: true }));
        const note = (document.querySelector('[data-shape-note]') || {}).textContent;
        const s2 = document.querySelector('[data-slider="sharpen"]'); s2.value = 80; s2.dispatchEvent(new Event('input', { bubbles: true })); s2.dispatchEvent(new Event('change', { bubbles: true }));
        __t.click('[data-act="crop"]');
        await __t.until(() => !document.querySelector('[data-ed-crop]').hidden && document.querySelector('[data-ed-busy]').hidden, 20000, 'crop editor');
        const r = document.querySelector('[data-ed-crop]').getBoundingClientRect();
        return { chips, startFilter, undoOn, afterUndo, note, crop: { x: r.left, y: r.top, w: r.width, h: r.height } };
    })()`);
    // drag the middle of the top side with real mouse events
    const L = ed.crop, pad = 28, iw = 1600, ih = 1200, s = Math.min((L.w - pad * 2) / iw, (L.h - pad * 2) / ih);
    const ox = L.x + (L.w - iw * s) / 2, oy = L.y + (L.h - ih * s) / 2;
    // the detected page's top side runs between corners 0 and 1 of the scene quad
    const sceneQuad = await page.eval(`(async () => (await __t.scene()).quad)()`);
    const mx = ox + (sceneQuad[0] + sceneQuad[2]) / 2 * iw * s, my = oy + (sceneQuad[1] + sceneQuad[3]) / 2 * ih * s;
    const mouse = (type, x, y) => page.send('Input.dispatchMouseEvent', { type, x, y, button: 'left', buttons: type === 'mouseReleased' ? 0 : 1, clickCount: 1, pointerType: 'mouse' });
    await mouse('mousePressed', mx, my);
    await mouse('mouseMoved', mx, my + 15);
    await mouse('mouseMoved', mx, my + 30);
    await mouse('mouseReleased', mx, my + 30);
    const fin = await page.eval(`(async () => {
        const undoAfterDrag = !document.querySelector('[data-act="ed-undo"]').disabled;
        __t.click('[data-act="crop-apply"]');
        await __t.until(() => document.querySelector('[data-ed-crop]').hidden, 5000, 'crop closed');
        await __t.until(() => document.querySelector('[data-ed-busy]').hidden, 60000, 'render after crop');
        __t.click('[data-act="editor-done"]');
        await __t.until(() => document.querySelector('.dsc-review') || document.querySelector('.dsc-docs'), 10000, 'review');
        await __t.until(() => !document.querySelector('.dsc-page-busy'), 30000);
        __t.click('[data-act="to-save"]');
        await __t.until(() => document.querySelector('[data-act="save-all"]'), 10000, 'save screen');
        __t.click('[data-act="save-all"]');
        await __t.until(() => { const f = document.getElementById('attachment').files; return f && f.length === 1; }, 90000, 'PDF in the form');
        const f = document.getElementById('attachment').files[0];
        const head = new TextDecoder().decode(new Uint8Array(await f.slice(0, 5).arrayBuffer()));
        await __t.until(() => !document.querySelector('.dsc'), 10000, 'scanner closed');
        return { undoAfterDrag, name: f.name, type: f.type, size: f.size, head, dialogs: window.__dialogs };
    })()`);
    console.log(`  [${label}] chips=${ed.chips} start=${ed.startFilter} undo=${ed.undoOn}→${ed.afterUndo} shapeNote="${ed.note}" dragUndo=${fin.undoAfterDrag} pdf=${fin.name} ${fin.size}B ${fin.head}`);
    // Scan again on the same form: no question, the new page joins the same PDF, Done attaches it.
    const again = await page.eval(`(async () => {
        window.__dialogs = [];
        const input = document.getElementById('attachment');
        __t.click('[data-dms-scan]');
        await __t.until(() => document.querySelector('.dsc .dsc-camera'), 10000, 'camera (scan again)');
        const addPageMode = document.querySelector('[data-act="mode-page"]').classList.contains('is-on');
        const sc = await __t.scene();
        const blob = await new Promise((r) => sc.canvas.toBlob(r, 'image/jpeg', 0.92));
        const dt = new DataTransfer(); dt.items.add(new File([blob], 'photo2.jpg', { type: 'image/jpeg' }));
        const inp = document.querySelector('[data-import]'); inp.files = dt.files; inp.dispatchEvent(new Event('change', { bubbles: true }));
        await __t.until(() => /1 document captured · 2 pages/.test(document.querySelector('[data-counter]').textContent), 5000, 'second page counted');
        const before = input.files[0];
        __t.click('[data-act="done"]');
        await __t.until(() => input.files.length === 1 && input.files[0] !== before && !document.querySelector('.dsc'), 120000, 'attached on Done');
        const text = new TextDecoder('latin1').decode(new Uint8Array(await input.files[0].arrayBuffer()));
        const pages = (text.match(/\\/Type \\/Page\\b/g) || []).length;
        const asked = window.__dialogs.filter((d) => /Continue the earlier scan/.test(d)).length;
        // Remove scan → the next Scan starts empty
        document.querySelector('[data-scan-clear]').click();
        await __t.sleep(400);
        __t.click('[data-dms-scan]');
        await __t.until(() => document.querySelector('.dsc .dsc-camera'), 10000, 'camera (after remove)');
        const fresh = document.querySelector('[data-counter]').textContent;
        __t.click('[data-act="close"]');
        await __t.until(() => !document.querySelector('.dsc'), 5000, 'closed');
        return { addPageMode, pages, asked, fresh, extras: document.querySelectorAll('input[data-scan-extras]').length };
    })()`);
    console.log(`  [${label}] scan again: addPageMode=${again.addPageMode} asked=${again.asked} pdfPages=${again.pages} extras=${again.extras} afterRemove="${again.fresh}"`);
    const errs = [];
    if (!again.addPageMode || again.asked) { errs.push('scanning again did not continue silently in Add page mode'); }
    if (again.pages !== 2 || again.extras) { errs.push('Done did not attach one PDF with both pages (' + again.pages + ' pages, ' + again.extras + ' extras)'); }
    if (!/0 pages/.test(again.fresh)) { errs.push('Remove scan did not reset the stored scan: ' + again.fresh); }
    if (ed.chips !== 7) { errs.push('expected 7 filter chips'); }
    if (!ed.undoOn || ed.afterUndo !== ed.startFilter) { errs.push('undo did not restore the mode'); }
    if (!fin.undoAfterDrag) { errs.push('dragging a side handle did not register an edit'); }
    if (fin.type !== 'application/pdf' || fin.head !== '%PDF-' || fin.size < 5000) { errs.push('bad PDF in the form'); }
    const bad = fin.dialogs.filter((d) => /fail|error|cannot/i.test(d));
    if (bad.length) { errs.push('error dialogs: ' + bad.join(' | ')); }
    if (errs.length) { throw new Error(label + ': ' + errs.join('; ')); }
}

(async () => {
    const srv = await serve();
    const base = 'http://127.0.0.1:' + srv.address().port + '/tests/scanner/browser/harness.html';
    let failed = false;
    try {
        for (const mode of [{ q: '', label: 'workers' }, { q: '?noworker=1', label: 'no workers' }]) {
            await withBrowser(async (page) => {
                await page.send('Emulation.setDeviceMetricsOverride', { width: 1280, height: 900, deviceScaleFactor: 1, mobile: false });
                await page.goto(base + mode.q);
                await page.eval(HELPERS);
                console.log('— ' + mode.label);
                const r = await checkDetection(page, mode.label);
                if (mode.label === 'workers' && !r.workers) { throw new Error('the processing worker did not start'); }
                if (mode.label !== 'workers' && r.workers) { throw new Error('workers were used although disabled'); }
                await uiFlow(page, mode.label);
                const problems = page.logs.filter((l) => /^(error|exception|assert)/.test(l));
                if (problems.length) { throw new Error(mode.label + ': console errors:\n  ' + problems.join('\n  ')); }
                console.log('  [' + mode.label + '] OK, no console errors');
            }, ['--use-fake-ui-for-media-stream', '--use-fake-device-for-media-stream', '--autoplay-policy=no-user-gesture-required']);
        }
    } catch (e) {
        failed = true;
        console.error('FAILED: ' + (e && e.stack || e));
    } finally {
        srv.close();
    }
    console.log(failed ? 'browser smoke test FAILED' : 'browser smoke test passed');
    process.exit(failed ? 1 : 0);
})();
