'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const { Core, loadImage, gray, gaussian } = require('./helpers');

const page = loadImage('text-page.png');
const g = gray(page);

test('sharp rendered text scores as sharp', () => {
    const s = Core.sharpnessScore(g, page.w, page.h);
    assert.ok(s > Core.BLUR_SOFT, 'score ' + s);
    assert.equal(Core.sharpnessLevel(s), 'sharp');
});

test('blur levels: σ 1.5 px is at most soft, σ 2 px is blurry', () => {
    const s15 = Core.sharpnessScore(gaussian(g, page.w, page.h, 1.5), page.w, page.h);
    const s2 = Core.sharpnessScore(gaussian(g, page.w, page.h, 2), page.w, page.h);
    assert.ok(s15 < Core.BLUR_SOFT, 'σ1.5 score ' + s15);
    assert.ok(s2 < Core.BLUR_WARN, 'σ2 score ' + s2);
    assert.equal(Core.sharpnessLevel(s2), 'blurry');
});

test('score is measured at a fixed size: a 2× larger page scores about the same', () => {
    const big = new Float32Array(page.w * 2 * page.h * 2);
    for (let y = 0; y < page.h * 2; y++) { for (let x = 0; x < page.w * 2; x++) { big[y * page.w * 2 + x] = g[(y >> 1) * page.w + (x >> 1)]; } }
    const a = Core.sharpnessScore(g, page.w, page.h), b = Core.sharpnessScore(big, page.w * 2, page.h * 2);
    assert.ok(Math.abs(Math.log(a / b)) < 0.5, a + ' vs ' + b);
});

test('a blank page has nothing to judge (null), not "blurry"', () => {
    const blank = new Float32Array(800 * 1100).fill(240);
    assert.equal(Core.sharpnessScore(blank, 800, 1100), null);
    assert.equal(Core.sharpnessLevel(null), 'unknown');
});
