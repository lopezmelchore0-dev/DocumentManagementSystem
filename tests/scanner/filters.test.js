'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const { Core, loadImage, stats, makeScene } = require('./helpers');

const page = loadImage('text-page.png');
const W = page.w, H = page.h, N = W * H;
const L0 = Core.lumaOf(page.rgba, N);
const isPaper = (i) => L0[i] > 235;
const isInk = (i) => L0[i] < 80;

/** The page lit unevenly: the right part darker (a hand / phone shadow). */
function shadowed(strength) {
    const d = page.rgba.slice();
    for (let y = 0; y < H; y++) {
        for (let x = 0; x < W; x++) {
            const f = 0.9 * (1 - strength * Math.min(1, Math.max(0, (x / W - 0.3) / 0.6))), i = (y * W + x) * 4;
            d[i] *= f; d[i + 1] *= f; d[i + 2] *= f;
        }
    }
    return d;
}
function run(d, p) { const c = d.slice(); Core.applyFilterData(c, W, H, p); return c; }
function luma(d) { return Core.lumaOf(d, N); }

test('shadow removal (No Shadow) evens out the paper and keeps ink dark', () => {
    const src = shadowed(0.55), before = stats(luma(src), isPaper);
    const out = luma(run(src, { filter: 'noshadow' }));
    const after = stats(out, isPaper), ink = stats(out, isInk);
    assert.ok(after.std < before.std * 0.3, `paper std ${before.std.toFixed(1)} → ${after.std.toFixed(1)}`);
    assert.ok(after.mean > 235, 'paper mean ' + after.mean.toFixed(1));
    assert.ok(ink.mean < 110, 'ink mean ' + ink.mean.toFixed(1));
});

test('Auto Enhance and Magic Color also remove the shadow', () => {
    const src = shadowed(0.55), before = stats(luma(src), isPaper);
    for (const filter of ['enhanced', 'magic', 'gray']) {
        const after = stats(luma(run(src, { filter })), isPaper);
        assert.ok(after.std < before.std * 0.3, `${filter}: paper std ${before.std.toFixed(1)} → ${after.std.toFixed(1)}`);
    }
});

test('Auto Enhance / Grayscale / Magic Color turn the paper clean white (no banding from smooth lighting)', () => {
    // smooth, 8-bit quantized lighting gradient like a real JPEG
    const d = page.rgba.slice();
    for (let y = 0; y < H; y++) { for (let x = 0; x < W; x++) { const f = 0.9 * (1 - 0.45 * x / W), i = (y * W + x) * 4; d[i] = Math.round(d[i] * f); d[i + 1] = Math.round(d[i + 1] * f); d[i + 2] = Math.round(d[i + 2] * f); } }
    const clean = new Uint8Array(N);       // paper well away from any ink
    for (let y = 6; y < H - 6; y++) { for (let x = 6; x < W - 6; x++) { let ok = 1; for (let k = -6; k <= 6 && ok; k += 3) { for (let j = -6; j <= 6; j += 3) { if (L0[(y + k) * W + x + j] < 225) { ok = 0; break; } } } clean[y * W + x] = ok; } }
    for (const filter of ['enhanced', 'gray', 'magic']) {
        const Y = luma(run(d, { filter }));
        let white = 0, n = 0;
        const cm = new Float64Array(W), cn = new Float64Array(W);
        for (let i = 0; i < N; i++) { if (clean[i]) { n++; if (Y[i] >= 250) { white++; } cm[i % W] += Y[i]; cn[i % W]++; } }
        const means = [];
        for (let x = 0; x < W; x++) { if (cn[x] > 50) { means.push(cm[x] / cn[x]); } }
        const mu = means.reduce((a, b) => a + b) / means.length, band = Math.sqrt(means.reduce((a, b) => a + (b - mu) ** 2, 0) / means.length);
        assert.ok(white / n > 0.98, `${filter}: white paper ${(white / n * 100).toFixed(1)}%`);
        assert.ok(band < 0.8, `${filter}: column banding ${band.toFixed(2)}`);
        assert.ok(stats(Y, isInk).mean < 90, `${filter}: ink stays dark`);
    }
});

test('Black & White: pure black/white, text kept, paper clean, specks removed', () => {
    const src = shadowed(0.5);
    // a lone dark speck in an empty area near the bottom
    const sx = 500, sy = 1350, si = (sy * W + sx) * 4;
    src[si] = src[si + 1] = src[si + 2] = 20;
    const out = run(src, { filter: 'bw' });
    let nonBinary = 0;
    for (let i = 0; i < N; i++) { const v = out[i * 4]; if (v !== 0 && v !== 255) { nonBinary++; } }
    assert.equal(nonBinary, 0);
    let inkBlack = 0, inkN = 0, paperWhite = 0, paperN = 0;
    for (let i = 0; i < N; i++) {
        if (isInk(i)) { inkN++; if (out[i * 4] === 0) { inkBlack++; } }
        if (isPaper(i)) { paperN++; if (out[i * 4] === 255) { paperWhite++; } }
    }
    assert.ok(inkBlack / inkN > 0.9, 'ink kept ' + (inkBlack / inkN * 100).toFixed(1) + '%');
    assert.ok(paperWhite / paperN > 0.99, 'paper white ' + (paperWhite / paperN * 100).toFixed(2) + '%');
    assert.equal(out[si], 255, 'speck removed');
});

test('Magic Color keeps colours vivid (saturation not reduced)', () => {
    const src = shadowed(0.2), stamp = [];
    // a blue stamp
    for (let y = 1180; y < 1300; y++) { for (let x = 650; x < 850; x++) { const i = (y * W + x) * 4; src[i] = 50; src[i + 1] = 75; src[i + 2] = 170; stamp.push(i); } }
    const sat = (d) => stamp.reduce((s, i) => s + Math.max(d[i], d[i + 1], d[i + 2]) - Math.min(d[i], d[i + 1], d[i + 2]), 0) / stamp.length;
    const out = run(src, { filter: 'magic' });
    assert.ok(sat(out) >= sat(src), `saturation ${sat(src).toFixed(1)} → ${sat(out).toFixed(1)}`);
    const auto = run(src, { filter: 'enhanced' });
    assert.ok(sat(out) > sat(auto), 'magic more vivid than Auto Enhance');
});

test('brightness / contrast lookup tables are monotonic', () => {
    for (const [b, c] of [[0, 0], [40, 0], [-40, 30], [20, -60], [100, 100], [-100, -100]]) {
        const lut = Core.adjustLut(b, c, 0, 255);
        for (let v = 1; v < 256; v++) { assert.ok(lut[v] >= lut[v - 1], `b=${b} c=${c} at ${v}`); }
    }
});

test('Sharpness slider: 0 is softer and 100 crisper than the default', () => {
    const src = page.rgba.slice();
    const lap = (d) => { const g = luma(d); let s = 0; for (let y = 1; y < H - 1; y++) { for (let x = 1; x < W - 1; x++) { const i = y * W + x, l = 4 * g[i] - g[i - 1] - g[i + 1] - g[i - W] - g[i + W]; s += l * l; } } return s; };
    const lo = lap(run(src, { filter: 'enhanced', sharpen: 0 })), mid = lap(run(src, { filter: 'enhanced' })), hi = lap(run(src, { filter: 'enhanced', sharpen: 100 }));
    assert.ok(lo < mid && mid < hi, [lo, mid, hi].join(' < '));
    const orig = lap(src), origHi = lap(run(src, { filter: 'original', sharpen: 100 }));
    assert.ok(origHi > orig);
    assert.deepEqual(Array.from(run(src, { filter: 'original' }).subarray(0, 4000)), Array.from(src.subarray(0, 4000)), 'Original with default sliders is untouched');
});

test('glare: a specular highlight is pulled down to the paper level; a clean page is untouched', () => {
    const cx = 500, cy = 700, r = 110;
    const scene = makeScene(page, W, H, [0, 0, W, 0, W, H, 0, H], { glare: { x: cx, y: cy, r } });
    const core = (i) => { const x = i % W, y = (i / W) | 0; return (x - cx) ** 2 + (y - cy) ** 2 < (r * 0.35) ** 2 && L0[i] > 200; };
    const ring = (i) => { const x = i % W, y = (i / W) | 0, d = (x - cx) ** 2 + (y - cy) ** 2; return d > (r * 2.2) ** 2 && d < (r * 3) ** 2 && L0[i] > 200; };
    const paper = stats(luma(scene.rgba), ring).mean, before = stats(luma(scene.rgba), core).mean;
    const d = scene.rgba.slice(), frac = Core.reduceGlare(d, W, H), after = stats(luma(d), core).mean;
    assert.ok(frac > 0, 'glare found');
    assert.ok(before - paper > 25, `test setup: glare ${before.toFixed(0)} vs paper ${paper.toFixed(0)}`);
    assert.ok(Math.abs(after - paper) < 12, `glare core ${before.toFixed(0)} → ${after.toFixed(0)} (paper ${paper.toFixed(0)})`);
    const clean = makeScene(page, W, H, [0, 0, W, 0, W, H, 0, H], {});
    assert.equal(Core.reduceGlare(clean.rgba.slice(), W, H), 0);
});

test('every mode runs and keeps the image size; renderJob warps, turns and filters', () => {
    for (const filter of Core.FILTER_IDS) {
        const out = run(page.rgba, { filter, bright: 10, contrast: 10 });
        assert.equal(out.length, page.rgba.length, filter);
    }
    const r = Core.renderJob({ src: page.rgba.slice(), sw: W, sh: H, quad: [10, 10, W - 10, 20, W - 20, H - 10, 20, H - 20], ow: 400, oh: 560, rot: 90, skew: 1, glare: true, filter: 'magic' });
    assert.deepEqual([r.w, r.h, r.data.length], [560, 400, 560 * 400 * 4]);
});
