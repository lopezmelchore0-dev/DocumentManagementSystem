/**
 * Screenshots of the scanner editor (desktop + phone size) for a visual check:
 *   node tests/scanner/browser/screenshot.js <output folder>
 */
'use strict';
const http = require('http');
const fs = require('fs');
const path = require('path');
const { withBrowser } = require('../cdp');

const ROOT = path.resolve(__dirname, '..', '..', '..');
const OUT = path.resolve(process.argv[2] || '.');
const TYPES = { '.html': 'text/html', '.js': 'text/javascript', '.css': 'text/css', '.png': 'image/png' };

(async () => {
    const srv = http.createServer((req, res) => {
        const file = path.resolve(ROOT, decodeURIComponent(req.url.split('?')[0]).replace(/^\/+/, ''));
        if (!file.startsWith(ROOT) || !fs.existsSync(file) || fs.statSync(file).isDirectory()) { res.writeHead(404); res.end(); return; }
        res.writeHead(200, { 'Content-Type': TYPES[path.extname(file)] || 'application/octet-stream' });
        fs.createReadStream(file).pipe(res);
    });
    await new Promise((r) => srv.listen(0, '127.0.0.1', r));
    const url = 'http://127.0.0.1:' + srv.address().port + '/tests/scanner/browser/harness.html';
    try {
        for (const vp of [{ name: 'desktop', width: 1280, height: 860, mobile: false, dpr: 1 }, { name: 'phone', width: 390, height: 844, mobile: true, dpr: 2 }]) {
            await withBrowser(async (page) => {
                await page.send('Emulation.setDeviceMetricsOverride', { width: vp.width, height: vp.height, deviceScaleFactor: vp.dpr, mobile: vp.mobile });
                await page.goto(url);
                await page.eval(`(async () => {
                    const until = async (f, ms) => { const e = Date.now() + ms; while (!f()) { if (Date.now() > e) throw new Error('timeout'); await new Promise(r => setTimeout(r, 100)); } };
                    const img = await createImageBitmap(await (await fetch('../fixtures/text-page.png')).blob());
                    const c = document.createElement('canvas'); c.width = 1600; c.height = 1200; const x = c.getContext('2d');
                    x.fillStyle = '#5a4630'; x.fillRect(0, 0, 1600, 1200);
                    const g = x.createLinearGradient(0, 0, 1600, 0); g.addColorStop(0, 'rgba(0,0,0,0)'); g.addColorStop(1, 'rgba(0,0,0,.45)');
                    x.setTransform(0.72 * Math.cos(0.12), 0.72 * Math.sin(0.12), -0.72 * Math.sin(0.12), 0.72 * Math.cos(0.12), 800, 600);
                    x.drawImage(img, -img.width / 2, -img.height / 2); x.setTransform(1, 0, 0, 1, 0, 0);
                    x.fillStyle = g; x.fillRect(0, 0, 1600, 1200);
                    document.querySelector('[data-dms-scan]').click();
                    await until(() => document.querySelector('[data-import]'), 5000);
                    const b = await new Promise(r => c.toBlob(r, 'image/jpeg', .92));
                    const dt = new DataTransfer(); dt.items.add(new File([b], 'p.jpg', { type: 'image/jpeg' }));
                    const inp = document.querySelector('[data-import]'); inp.files = dt.files; inp.dispatchEvent(new Event('change', { bubbles: true }));
                    document.querySelector('[data-act="review"]').click();
                    await until(() => document.querySelector('.dsc-page') && !document.querySelector('.dsc-page-busy'), 60000);
                    document.querySelector('.dsc-page-btn').click();
                    await until(() => document.querySelectorAll('[data-chip-img]:not(.is-empty)').length === 7, 60000);
                    await until(() => document.querySelector('[data-ed-busy]').hidden, 60000);
                    await new Promise(r => setTimeout(r, 400));
                    return true;
                })()`, 120000);
                const shot = await page.send('Page.captureScreenshot', { format: 'png' });
                fs.writeFileSync(path.join(OUT, 'editor-' + vp.name + '.png'), Buffer.from(shot.data, 'base64'));
                console.log('saved editor-' + vp.name + '.png');
            }, ['--use-fake-ui-for-media-stream', '--use-fake-device-for-media-stream']);
        }
    } finally { srv.close(); }
})().catch((e) => { console.error(e); process.exit(1); });
