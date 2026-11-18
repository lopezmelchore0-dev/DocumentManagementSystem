'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const { Core, projectRect } = require('./helpers');

test('orderQuad returns TL, TR, BR, BL for any input order', () => {
    const want = [10, 12, 200, 8, 210, 300, 5, 290];
    const pts = [[10, 12], [200, 8], [210, 300], [5, 290]];
    const perms = [[0, 1, 2, 3], [3, 2, 1, 0], [2, 0, 3, 1], [1, 3, 0, 2]];
    for (const p of perms) {
        const flat = [].concat(...p.map((i) => pts[i]));
        assert.deepEqual(Core.orderQuad(flat), want);
    }
});

test('homography maps the 4 points exactly and round-trips', () => {
    const src = [0, 0, 100, 0, 100, 140, 0, 140], dst = [12, 30, 95, 18, 110, 160, 3, 150];
    const H = Core.homography(src, dst), Hi = Core.homography(dst, src);
    for (let i = 0; i < 4; i++) {
        const p = Core.applyH(H, src[i * 2], src[i * 2 + 1]);
        assert.ok(Math.abs(p[0] - dst[i * 2]) < 1e-6 && Math.abs(p[1] - dst[i * 2 + 1]) < 1e-6);
    }
    const q = Core.applyH(Hi, ...Core.applyH(H, 37, 81));
    assert.ok(Math.abs(q[0] - 37) < 1e-6 && Math.abs(q[1] - 81) < 1e-6);
});

test('isConvexQuad rejects crossed outlines', () => {
    assert.ok(Core.isConvexQuad([0, 0, 1, 0, 1, 1, 0, 1]));
    assert.ok(!Core.isConvexQuad([0, 0, 1, 1, 1, 0, 0, 1]));
});

test('estimateAspect recovers the true page proportions across camera tilts', () => {
    const iw = 3200, ih = 2400, A4 = 841.89 / 595.28;
    const cases = [
        { tiltX: 0, tiltY: 0 }, { tiltX: 25, tiltY: 0 }, { tiltX: 0, tiltY: 25 }, { tiltX: 30, tiltY: 15 },
        { tiltX: -20, tiltY: 20, roll: 12 }, { tiltX: 35, tiltY: -10, roll: -25 }, { tiltX: 15, tiltY: 0, roll: 90 }
    ];
    for (const f of [0.8 * iw, 1.15 * iw]) {
        for (const c of cases) {
            // the app always orders corners as they appear on screen
            const q = Core.orderQuad(projectRect(595.28, 841.89, Object.assign({ iw, ih, f, dist: 1500 }, c)));
            const r = Core.estimateAspect(q, iw, ih);
            const truth = c.roll === 90 ? A4 : 1 / A4;           // w/h on screen
            const err = Math.abs(r - truth) / truth;
            // Tilted about one axis only, there is a single vanishing point and the focal length cannot be
            // recovered: a normal phone lens (0.8 × long side) is assumed. A zoomed shot then keeps a few
            // percent of error, which is still far better than the old side-length ratio.
            const singleAxis = (!c.tiltX || !c.tiltY) && (!c.roll || c.roll === 90) && (c.tiltX || c.tiltY);
            if (singleAxis && f !== 0.8 * iw) {
                const s = Core.quadSize(q), naive = Math.abs(s.w / s.h - truth) / truth;
                assert.ok(err < 0.06 && err < naive / 2, `zoomed single-axis ${JSON.stringify(c)}: ${(err * 100).toFixed(1)}% vs naive ${(naive * 100).toFixed(1)}%`);
            } else {
                assert.ok(err < 0.02, `f=${f / iw} tilt ${JSON.stringify(c)}: ratio ${r.toFixed(4)} vs ${truth.toFixed(4)} (${(err * 100).toFixed(1)}%)`);
            }
        }
    }
});

test('the old side-length ratio is clearly wrong at a tilt (why estimateAspect exists)', () => {
    const iw = 3200, ih = 2400;
    const q = projectRect(595.28, 841.89, { iw, ih, f: 0.8 * iw, dist: 1500, tiltX: 35 });
    const s = Core.quadSize(q), naive = s.w / s.h, truth = 595.28 / 841.89;
    assert.ok(Math.abs(naive - truth) / truth > 0.08, 'naive ratio ' + naive);
});

test('snapAspect snaps to standard paper within 3% and keeps orientation', () => {
    assert.equal(Core.snapAspect(612 / 936 * 1.02).shape, 'folio');
    assert.equal(Core.snapAspect(1.41).shape, 'a4');
    assert.ok(Core.snapAspect(1.41).ratio > 1);
    assert.equal(Core.snapAspect(0.77).shape, 'letter');
    assert.equal(Core.snapAspect(0.5).shape, null);             // receipt: left as measured
    assert.equal(Core.snapAspect(0.5).ratio, 0.5);
});

test('pageDims: shapes, snapping and resolution kept', () => {
    const iw = 3200, ih = 2400;
    const q = projectRect(612, 936, { iw, ih, f: 0.8 * iw, dist: 1700, tiltX: 20 });
    const auto = Core.pageDims(q, iw, ih, 'auto');
    assert.equal(auto.shape, 'folio');
    assert.ok(Math.abs(auto.w / auto.h - 612 / 936) < 1e-9);
    assert.ok(Math.round(auto.h) === Math.round(Math.max(Core.quadSize(q).w, Core.quadSize(q).h)));
    const a4 = Core.pageDims(q, iw, ih, 'a4');
    assert.ok(Math.abs(a4.h / a4.w - 841.89 / 595.28) < 1e-9);
    const free = Core.pageDims(q, iw, ih, 'free');
    assert.equal(free.shape, null);
    assert.ok(Math.abs(free.w / free.h - 612 / 936) / (612 / 936) < 0.02);
});

test('rotate90 moves pixels clockwise; rotateSmall(0) is the identity', () => {
    // 3×2 image, values = index
    const d = new Float32Array([0, 1, 2, 3, 4, 5]);
    const r = Core.rotate90(d, 3, 2, 90, 1);
    assert.equal(r.w, 2); assert.equal(r.h, 3);
    // clockwise: bottom-left (3) becomes top-left
    assert.deepEqual(Array.from(r.data), [3, 0, 4, 1, 5, 2]);
    const back = Core.rotate90(r.data, r.w, r.h, 270, 1);
    assert.deepEqual(Array.from(back.data), [0, 1, 2, 3, 4, 5]);
    const img = new Uint8ClampedArray(4 * 4 * 4).map((_, i) => (i * 7) % 256);
    assert.deepEqual(Array.from(Core.rotateSmall(img, 4, 4, 0, 4)), Array.from(img));
});

test('warpRGBA undoes a known perspective (checkerboard stays square)', () => {
    const N = 240, tex = new Uint8ClampedArray(N * N * 4);
    for (let y = 0; y < N; y++) { for (let x = 0; x < N; x++) { const v = ((x / 30 | 0) + (y / 30 | 0)) % 2 ? 230 : 25, i = (y * N + x) * 4; tex[i] = tex[i + 1] = tex[i + 2] = v; tex[i + 3] = 255; } }
    // forward: put the board into a perspective quad of a 400×400 image
    const W = 400, quad = [60, 40, 330, 70, 360, 350, 30, 320];
    const Hinv = Core.homography(quad, [0, 0, N, 0, N, N, 0, N]);
    const img = new Uint8ClampedArray(W * W * 4);
    for (let y = 0; y < W; y++) {
        for (let x = 0; x < W; x++) {
            const p = Core.applyH(Hinv, x + 0.5, y + 0.5), u = Math.floor(p[0]), v = Math.floor(p[1]), o = (y * W + x) * 4;
            const val = u >= 0 && v >= 0 && u < N && v < N ? tex[(v * N + u) * 4] : 128;
            img[o] = img[o + 1] = img[o + 2] = val; img[o + 3] = 255;
        }
    }
    const out = Core.warpRGBA(img, W, W, quad, N, N);
    let diff = 0, cnt = 0;
    for (let y = 4; y < N - 4; y++) {
        for (let x = 4; x < N - 4; x++) {
            if (x % 30 < 3 || x % 30 > 27 || y % 30 < 3 || y % 30 > 27) { continue; }   // skip square borders
            diff += Math.abs(out[(y * N + x) * 4] - tex[(y * N + x) * 4]); cnt++;
        }
    }
    assert.ok(diff / cnt < 3, 'mean difference ' + (diff / cnt));
});
