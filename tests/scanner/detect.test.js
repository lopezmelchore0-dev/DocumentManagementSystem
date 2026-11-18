'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const { Core, loadImage, makeScene, quadError, loadCv, shrinkRgba, gray } = require('./helpers');

const page = loadImage('text-page.png');
const W = 1600, H = 1200;
// a page photographed at an angle (TL TR BR BL, px)
const QUADS = {
    tilted: [560, 95, 1130, 140, 1210, 1110, 455, 1065],
    rotated: [430, 160, 1090, 60, 1240, 980, 560, 1120],
    small: [650, 330, 1010, 350, 1030, 860, 620, 840]
};

let cv;
test.before(async () => { cv = await loadCv(); });

/** Coarse detection on a ~1024 px copy (what the app does for a still photo). */
function detect(scene) {
    const small = shrinkRgba(scene, 1024);
    const r = Core.detectQuadCv(cv, small.rgba, small.w, small.h);
    return r && Core.scaleQuad(r.quad, scene.w, scene.h);
}

const SCENES = [
    ['dark desk', 'tilted', { bg: 'dark' }],
    ['wood desk with a shadow', 'tilted', { bg: 'wood', shadow: 0.45 }],
    ['white paper on a light-grey desk (low contrast)', 'rotated', { bg: 'light' }],
    ['grey desk, page rotated', 'rotated', { bg: 'gray', shadow: 0.3 }]
];
for (const [name, qk, opt] of SCENES) {
    test('detects the page: ' + name, { timeout: 60000 }, () => {
        const scene = makeScene(page, W, H, QUADS[qk], opt);
        const q = detect(scene);
        assert.ok(q, 'no page found');
        const err = quadError(q, QUADS[qk], W, H);
        assert.ok(err < 0.015, 'coarse corner error ' + (err * 100).toFixed(2) + '%');
        // full-resolution refinement makes the corners precise
        const ref = Core.refineQuad(gray(scene), W, H, q);
        const err2 = quadError(ref, QUADS[qk], W, H);
        assert.ok(err2 < 0.002, 'refined corner error ' + (err2 * 100).toFixed(2) + '% (coarse ' + (err * 100).toFixed(2) + '%)');
    });
}

test('refinement fixes a coarse outline that is several pixels off', { timeout: 60000 }, () => {
    const scene = makeScene(page, W, H, QUADS.tilted, { bg: 'wood', shadow: 0.3 });
    const off = [9, -7, -8, 10, 7, 9, -10, -6];                 // what a 480 px live frame can give
    const coarse = QUADS.tilted.map((v, i) => v + off[i]);
    const ref = Core.refineQuad(gray(scene), W, H, coarse);
    assert.ok(quadError(coarse, QUADS.tilted, W, H) > 0.007);
    assert.ok(quadError(ref, QUADS.tilted, W, H) < 0.002, 'refined ' + (quadError(ref, QUADS.tilted, W, H) * 100).toFixed(2) + '%');
});

test('a page under 12% of the photo is not taken as the page, unless asked (for the "Move closer" hint)', { timeout: 60000 }, () => {
    const scene = makeScene(page, W, H, QUADS.small, { bg: 'wood' }), small = shrinkRgba(scene, 1024);
    assert.ok(Core.quadArea(Core.scaleQuad(QUADS.small, 1 / W, 1 / H)) < 0.12);
    assert.equal(Core.detectQuadCv(cv, small.rgba, small.w, small.h), null);
    const r = Core.detectQuadCv(cv, small.rgba, small.w, small.h, { minArea: 0.05 });
    assert.ok(r && quadError(Core.scaleQuad(r.quad, W, H), QUADS.small, W, H) < 0.015);
});

test('no page in the photo → null', { timeout: 60000 }, () => {
    for (const bg of ['wood', 'dark']) {
        const blank = { rgba: new Uint8ClampedArray(4 * 4 * 4), w: 4, h: 4 };
        const scene = makeScene(blank, W, H, [-10, -10, -9, -10, -9, -9, -10, -9], { bg });   // texture off-screen
        assert.equal(detect(scene), null, bg);
    }
});

test('refineQuad keeps a good quad when there is no edge to snap to', () => {
    const flat = new Float32Array(400 * 300).fill(128), q = [50, 40, 350, 45, 340, 260, 60, 250];
    const r = Core.refineQuad(flat, 400, 300, q);
    r.forEach((v, i) => assert.ok(Math.abs(v - q[i]) < 1e-6, `corner value ${i}: ${v} vs ${q[i]}`));
});

test('analyzeCapture: refined quad, sharpness and orientation in one pass', { timeout: 60000 }, () => {
    const scene = makeScene(page, W, H, QUADS.tilted, { bg: 'wood' });
    const coarse = detect(scene);
    const a = Core.analyzeCapture(scene.rgba, W, H, Core.scaleQuad(coarse, 1 / W, 1 / H));
    assert.ok(quadError(Core.scaleQuad(a.quad, W, H), QUADS.tilted, W, H) < 0.004);
    assert.notEqual(Core.sharpnessLevel(a.sharpness), 'blurry', 'sharpness ' + a.sharpness);
    assert.equal(a.rot, 0);
    assert.ok(Math.abs(a.skew) <= 0.5, 'skew ' + a.skew);
});
