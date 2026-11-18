'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const { Core, loadImage, gray } = require('./helpers');

function orient(name) {
    const img = loadImage(name), g = gray(img);
    const s = Core.downscaleArea(g, img.w, img.h, Core.SHARP_LONG);
    return Core.orientation(s.data, s.w, s.h);
}

test('an upright page needs no rotation or deskew', () => {
    const o = orient('text-page.png');
    assert.equal(o.rot, 0);
    assert.ok(Math.abs(o.skew) <= 0.3, 'skew ' + o.skew);
    assert.equal(o.upsideDown, false);
    assert.ok(o.text);
});

test('a page turned 3° clockwise is straightened by −3° (±0.5°)', () => {
    const o = orient('text-skew3.png');
    assert.equal(o.rot, 0);
    assert.ok(Math.abs(o.skew + 3) <= 0.5, 'skew ' + o.skew);
});

test('a page turned 90° clockwise is turned back (270° clockwise)', () => {
    const o = orient('text-rot90.png');
    assert.equal(o.rot, 270);
    assert.ok(Math.abs(o.skew) <= 0.3, 'skew ' + o.skew);
});

test('a page turned 90° counter-clockwise is turned back (90° clockwise)', () => {
    const o = orient('text-rot270.png');
    assert.equal(o.rot, 90);
});

test('the correction really makes the text horizontal (rotate90 + rotateSmall)', () => {
    const img = loadImage('text-skew3.png'), o = orient('text-skew3.png');
    const fixed = Core.rotateSmall(img.rgba, img.w, img.h, o.skew, 4);
    const g = Core.lumaOf(fixed, img.w * img.h), s = Core.downscaleArea(g, img.w, img.h, Core.SHARP_LONG);
    const again = Core.orientation(s.data, s.w, s.h);
    assert.ok(Math.abs(again.skew) <= 0.3, 'residual skew ' + again.skew);
});

test('an upside-down page is reported (not applied in Phase 1)', () => {
    const img = loadImage('text-page.png'), r = Core.rotate90(img.rgba, img.w, img.h, 180, 4);
    const g = Core.lumaOf(r.data, r.w * r.h), s = Core.downscaleArea(g, r.w, r.h, Core.SHARP_LONG);
    const o = Core.orientation(s.data, s.w, s.h);
    assert.equal(o.rot, 0);
    assert.equal(o.upsideDown, true);
});

test('a page without text is left alone', () => {
    const blank = new Float32Array(700 * 1000).fill(235);
    const o = Core.orientation(blank, 700, 1000);
    assert.deepEqual([o.rot, o.skew, o.text], [0, 0, false]);
});
