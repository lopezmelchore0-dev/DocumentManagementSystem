/**
 * Test helpers: fixtures, an independent Gaussian blur, synthetic "photos" of a page in perspective,
 * and OpenCV.js loaded in Node (the same vendored build the browser uses).
 */
'use strict';
const fs = require('fs');
const path = require('path');
const { PNG } = require('pngjs');
const jpeg = require('jpeg-js');
const Core = require('../../js/scanner/dsc-core.js');

const FIX = path.join(__dirname, 'fixtures');

/** {rgba: Uint8ClampedArray, w, h} from a PNG or JPEG file in fixtures/ (or an absolute path). */
function loadImage(name) {
    const file = path.isAbsolute(name) ? name : path.join(FIX, name);
    const buf = fs.readFileSync(file);
    if (/\.png$/i.test(file)) {
        const png = PNG.sync.read(buf);
        return { rgba: new Uint8ClampedArray(png.data.buffer, png.data.byteOffset, png.data.length).slice(), w: png.width, h: png.height };
    }
    const j = jpeg.decode(buf, { useTArray: true, formatAsRGBA: true });
    return { rgba: new Uint8ClampedArray(j.data), w: j.width, h: j.height };
}
function savePng(file, img) {
    const png = new PNG({ width: img.w, height: img.h });
    Buffer.from(img.rgba.buffer, img.rgba.byteOffset, img.rgba.length).copy(png.data);
    fs.writeFileSync(file, PNG.sync.write(png));
}
function gray(img) { return Core.lumaOf(img.rgba, img.w * img.h); }

/** Deterministic PRNG (mulberry32). */
function rng(seed) {
    let a = seed >>> 0;
    return function () {
        a = (a + 0x6D2B79F5) >>> 0;
        let t = a;
        t = Math.imul(t ^ (t >>> 15), t | 1);
        t ^= t + Math.imul(t ^ (t >>> 7), t | 61);
        return ((t ^ (t >>> 14)) >>> 0) / 4294967296;
    };
}

/** Real Gaussian blur (separable kernel), independent of the code under test. 1 channel. */
function gaussian(src, w, h, sigma) {
    const r = Math.ceil(sigma * 3), k = [];
    let s = 0;
    for (let i = -r; i <= r; i++) { const v = Math.exp(-(i * i) / (2 * sigma * sigma)); k.push(v); s += v; }
    for (let i = 0; i < k.length; i++) { k[i] /= s; }
    const tmp = new Float32Array(w * h), out = new Float32Array(w * h);
    for (let y = 0; y < h; y++) {
        for (let x = 0; x < w; x++) {
            let a = 0;
            for (let i = -r; i <= r; i++) { a += src[y * w + Math.min(w - 1, Math.max(0, x + i))] * k[i + r]; }
            tmp[y * w + x] = a;
        }
    }
    for (let y = 0; y < h; y++) {
        for (let x = 0; x < w; x++) {
            let a = 0;
            for (let i = -r; i <= r; i++) { a += tmp[Math.min(h - 1, Math.max(0, y + i)) * w + x] * k[i + r]; }
            out[y * w + x] = a;
        }
    }
    return out;
}
function grayToRgba(g, w, h) {
    const d = new Uint8ClampedArray(w * h * 4);
    for (let i = 0; i < w * h; i++) { d[i * 4] = d[i * 4 + 1] = d[i * 4 + 2] = g[i]; d[i * 4 + 3] = 255; }
    return d;
}
function stats(arr, pick) {
    let n = 0, s = 0, s2 = 0;
    for (let i = 0; i < arr.length; i++) { if (pick && !pick(i)) { continue; } s += arr[i]; s2 += arr[i] * arr[i]; n++; }
    const mean = s / n;
    return { n, mean, std: Math.sqrt(Math.max(0, s2 / n - mean * mean)) };
}

/** Backgrounds for synthetic photos: (x, y, rand) → [r, g, b]. */
const BACKGROUNDS = {
    dark: (x, y, r) => { const v = 55 + 25 * r(); return [v, v * 0.95, v * 0.9]; },
    wood: (x, y, r) => { const s = 0.5 + 0.5 * Math.sin(y * 0.09 + Math.sin(x * 0.013) * 3); return [150 + 40 * s + 10 * r(), 100 + 30 * s + 8 * r(), 55 + 20 * s + 6 * r()]; },
    light: (x, y, r) => { const v = 196 + 10 * r(); return [v, v, v - 4]; },          // white paper on a light-grey desk
    gray: (x, y, r) => { const v = 120 + 12 * r(); return [v, v, v]; }
};

/**
 * A "photo" of a texture (the page) seen through perspective quad `quad` (px, TL TR BR BL) on a
 * background, with 2×2 supersampling. Options: shadow (0..1, darkens the right part),
 * glare {x, y, r} (px, a specular highlight), seed.
 */
function makeScene(tex, w, h, quad, opt) {
    opt = opt || {};
    const bg = BACKGROUNDS[opt.bg || 'dark'], r = rng(opt.seed || 7);
    const Hinv = Core.homography(quad, [0, 0, tex.w, 0, tex.w, tex.h, 0, tex.h]);
    const out = new Uint8ClampedArray(w * h * 4), T = tex.rgba, tw = tex.w, th = tex.h;
    const subs = [[0.25, 0.25], [0.75, 0.25], [0.25, 0.75], [0.75, 0.75]];
    for (let y = 0; y < h; y++) {
        for (let x = 0; x < w; x++) {
            let R = 0, G = 0, B = 0;
            const b = bg(x, y, r);
            for (const s of subs) {
                const p = Core.applyH(Hinv, x + s[0], y + s[1]), u = p[0], v = p[1];
                if (u >= 0 && v >= 0 && u < tw - 1 && v < th - 1) {
                    const x0 = u | 0, y0 = v | 0, dx = u - x0, dy = v - y0, i = (y0 * tw + x0) * 4;
                    for (let c = 0; c < 3; c++) {
                        const top = T[i + c] + (T[i + 4 + c] - T[i + c]) * dx, bot = T[i + tw * 4 + c] + (T[i + tw * 4 + 4 + c] - T[i + tw * 4 + c]) * dx;
                        const val = top + (bot - top) * dy;
                        if (c === 0) { R += val; } else if (c === 1) { G += val; } else { B += val; }
                    }
                } else { R += b[0]; G += b[1]; B += b[2]; }
            }
            let f = 0.88;                                     // camera exposure: paper is never pure white
            if (opt.shadow) { f *= 1 - opt.shadow * Math.min(1, Math.max(0, (x / w - 0.35) / 0.5)); }
            let add = 0;
            if (opt.glare) { const d2 = ((x - opt.glare.x) ** 2 + (y - opt.glare.y) ** 2) / (opt.glare.r * opt.glare.r); add = 160 * Math.exp(-d2 * 2); }
            const o = (y * w + x) * 4;
            out[o] = R / 4 * f + add + (r() - 0.5) * 6;
            out[o + 1] = G / 4 * f + add + (r() - 0.5) * 6;
            out[o + 2] = B / 4 * f + add + (r() - 0.5) * 6;
            out[o + 3] = 255;
        }
    }
    return { rgba: out, w, h, quad };
}
/** A plain texture: paper colour with optional dark text-like bars. */
function plainPage(w, h, opt) {
    opt = opt || {};
    const d = new Uint8ClampedArray(w * h * 4), paper = opt.paper || [246, 244, 239];
    for (let i = 0; i < w * h; i++) { d[i * 4] = paper[0]; d[i * 4 + 1] = paper[1]; d[i * 4 + 2] = paper[2]; d[i * 4 + 3] = 255; }
    if (opt.bars) {
        for (let y = Math.round(h * 0.1); y < h * 0.9; y += 24) {
            for (let x = Math.round(w * 0.1); x < w * 0.85; x++) {
                if (((x / 37) | 0) % 5 === 4) { continue; }        // word gaps
                for (let k = 0; k < 9; k++) { const i = ((y + k) * w + x) * 4; d[i] = d[i + 1] = d[i + 2] = 30; }
            }
        }
    }
    return { rgba: d, w, h };
}
/** Max corner distance between two px quads, as a fraction of the image's long side. */
function quadError(a, b, w, h) {
    let m = 0;
    for (let i = 0; i < 4; i++) { m = Math.max(m, Math.hypot(a[i * 2] - b[i * 2], a[i * 2 + 1] - b[i * 2 + 1])); }
    return m / Math.max(w, h);
}

/** Pinhole camera looking at a W×H rectangle: returns its px corners (TL TR BR BL). */
function projectRect(W, H, opt) {
    const iw = opt.iw, ih = opt.ih, f = opt.f, rx = (opt.tiltX || 0) * Math.PI / 180, ry = (opt.tiltY || 0) * Math.PI / 180, rz = (opt.roll || 0) * Math.PI / 180;
    const Z = opt.dist || 3 * Math.max(W, H);
    const pts = [[-W / 2, -H / 2], [W / 2, -H / 2], [W / 2, H / 2], [-W / 2, H / 2]];
    const out = [];
    for (const [x0, y0] of pts) {
        let x = x0, y = y0, z = 0;
        // roll (z), tilt about x, tilt about y
        [x, y] = [x * Math.cos(rz) - y * Math.sin(rz), x * Math.sin(rz) + y * Math.cos(rz)];
        [y, z] = [y * Math.cos(rx) - z * Math.sin(rx), y * Math.sin(rx) + z * Math.cos(rx)];
        [x, z] = [x * Math.cos(ry) + z * Math.sin(ry), -x * Math.sin(ry) + z * Math.cos(ry)];
        z += Z;
        out.push(iw / 2 + (opt.offX || 0) + f * x / z, ih / 2 + (opt.offY || 0) + f * y / z);
    }
    return out;
}

let cvPromise = null;
/** OpenCV.js (vendor/opencv/opencv.js) in Node. */
function loadCv() {
    if (cvPromise) { return cvPromise; }
    cvPromise = new Promise((resolve, reject) => {
        const timer = setTimeout(() => reject(new Error('OpenCV did not load')), 120000);
        global.Module = {
            onRuntimeInitialized() { clearTimeout(timer); resolve(mod && mod.Mat ? mod : global.Module); }
        };
        const mod = require('../../vendor/opencv/opencv.js');
        if (mod && typeof mod.then === 'function' && !mod.Mat) {
            mod.then((api) => { clearTimeout(timer); try { delete api.then; } catch (e) { /* ignore */ } resolve(api); });
        } else if (mod && typeof mod.Mat === 'function' && mod.getPerspectiveTransform) {
            clearTimeout(timer); resolve(mod);
        }
    });
    return cvPromise;
}
/** Downscale an RGBA image (area average) to long side ≤ maxLong. */
function shrinkRgba(img, maxLong) {
    const f = Math.max(img.w, img.h) / maxLong;
    if (f <= 1) { return img; }
    const ow = Math.round(img.w / f), oh = Math.round(img.h / f), out = new Uint8ClampedArray(ow * oh * 4);
    for (let y = 0; y < oh; y++) {
        const y0 = Math.floor(y * img.h / oh), y1 = Math.max(y0 + 1, Math.floor((y + 1) * img.h / oh));
        for (let x = 0; x < ow; x++) {
            const x0 = Math.floor(x * img.w / ow), x1 = Math.max(x0 + 1, Math.floor((x + 1) * img.w / ow));
            const s = [0, 0, 0];
            for (let yy = y0; yy < y1; yy++) { for (let xx = x0; xx < x1; xx++) { const i = (yy * img.w + xx) * 4; s[0] += img.rgba[i]; s[1] += img.rgba[i + 1]; s[2] += img.rgba[i + 2]; } }
            const n = (y1 - y0) * (x1 - x0), o = (y * ow + x) * 4;
            out[o] = s[0] / n; out[o + 1] = s[1] / n; out[o + 2] = s[2] / n; out[o + 3] = 255;
        }
    }
    return { rgba: out, w: ow, h: oh };
}

module.exports = { Core, FIX, loadImage, savePng, gray, rng, gaussian, grayToRgba, stats, makeScene, plainPage, quadError, projectRect, loadCv, shrinkRgba, BACKGROUNDS };
