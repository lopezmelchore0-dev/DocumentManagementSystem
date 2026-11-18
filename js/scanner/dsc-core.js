/**
 * Document scanner: image-processing core (no DOM).
 *
 * Pure functions on typed arrays, shared by
 *   - js/doc-scanner.js       (main thread: geometry for the UI, and the fallback when Web Workers are missing)
 *   - js/scanner/dsc-worker.js (the scanner's workers: edge detection, warp, filters, analysis)
 *   - tests/scanner/*.test.js  (Node unit tests)
 *
 * Conventions
 *   rgba   Uint8ClampedArray, 4 bytes per pixel (canvas ImageData layout)
 *   gray   Uint8ClampedArray / Float32Array, 1 value per pixel
 *   quad   [x0,y0, x1,y1, x2,y2, x3,y3] ordered top-left, top-right, bottom-right, bottom-left;
 *          "normalized" quads are 0..1 of the image size, "px" quads are pixels.
 *   rotations are clockwise (as seen on screen), in degrees.
 */
(function (root, factory) {
    var api = factory();
    if (typeof module === 'object' && module.exports) { module.exports = api; }
    else { root.DscCore = api; }
}(typeof self !== 'undefined' ? self : this, function () {
    'use strict';

    /* =====================================================================
       Constants
       ===================================================================== */
    /** Paper proportions (long side / short side). */
    var SHAPES = {
        folio:  936 / 612,
        a4:     841.89 / 595.28,
        letter: 792 / 612,
        legal:  1008 / 612,
        id:     85.6 / 53.98
    };
    /** Shapes "Auto" snaps to (an ID card is too close to Folio to be told apart by shape alone). */
    var SNAP_SHAPES = ['folio', 'a4', 'letter', 'legal'];
    var SNAP_TOLERANCE = 0.03;
    // Sharpness (Laplacian variance) is measured at this size. Calibrated on rendered 10–13 px text:
    // Gaussian blur 1 px ≈ 500–600, 1.5 px ≈ 80–90, 2 px ≈ 25–40 (hard to read).
    var SHARP_LONG = 1000, BLUR_WARN = 90, BLUR_SOFT = 350;
    var FILTER_IDS = ['original', 'magic', 'enhanced', 'gray', 'bw', 'noshadow', 'lighten'];

    /* =====================================================================
       Geometry
       ===================================================================== */
    /** [x0,y0,...x3,y3] in any order → ordered top-left, top-right, bottom-right, bottom-left. */
    function orderQuad(pts) {
        var p = [];
        for (var i = 0; i < 8; i += 2) { p.push({ x: pts[i], y: pts[i + 1] }); }
        var cx = (p[0].x + p[1].x + p[2].x + p[3].x) / 4, cy = (p[0].y + p[1].y + p[2].y + p[3].y) / 4;
        p.sort(function (a, b) { return Math.atan2(a.y - cy, a.x - cx) - Math.atan2(b.y - cy, b.x - cx); });
        // clockwise (y down); start at the corner with the smallest x+y
        var best = 0;
        for (var j = 1; j < 4; j++) { if (p[j].x + p[j].y < p[best].x + p[best].y) { best = j; } }
        var out = [];
        for (var k = 0; k < 4; k++) { var q = p[(best + k) % 4]; out.push(q.x, q.y); }
        return out;
    }
    function fullQuad() { return [0, 0, 1, 0, 1, 1, 0, 1]; }
    function isFullQuad(q) {
        if (!q) { return true; }
        var f = fullQuad();
        for (var i = 0; i < 8; i++) { if (Math.abs(q[i] - f[i]) > 0.002) { return false; } }
        return true;
    }
    function dist(ax, ay, bx, by) { var dx = ax - bx, dy = ay - by; return Math.sqrt(dx * dx + dy * dy); }
    /** Longest opposite sides of a px quad (the resolution the page was captured at). */
    function quadSize(q) {
        var w = Math.max(dist(q[0], q[1], q[2], q[3]), dist(q[6], q[7], q[4], q[5]));
        var h = Math.max(dist(q[0], q[1], q[6], q[7]), dist(q[2], q[3], q[4], q[5]));
        return { w: Math.max(1, w), h: Math.max(1, h) };
    }
    function quadArea(q) {
        var a = 0;
        for (var i = 0; i < 4; i++) {
            var j = (i + 1) % 4;
            a += q[i * 2] * q[j * 2 + 1] - q[j * 2] * q[i * 2 + 1];
        }
        return Math.abs(a) / 2;
    }
    function isConvexQuad(q) {
        var sign = 0;
        for (var i = 0; i < 4; i++) {
            var ax = q[i * 2], ay = q[i * 2 + 1];
            var bx = q[((i + 1) % 4) * 2], by = q[((i + 1) % 4) * 2 + 1];
            var cx = q[((i + 2) % 4) * 2], cy = q[((i + 2) % 4) * 2 + 1];
            var cross = (bx - ax) * (cy - by) - (by - ay) * (cx - bx);
            if (Math.abs(cross) < 1e-9) { continue; }
            var s = cross > 0 ? 1 : -1;
            if (sign === 0) { sign = s; } else if (s !== sign) { return false; }
        }
        return true;
    }
    function scaleQuad(q, sx, sy) { return q.map(function (v, i) { return v * (i % 2 ? sy : sx); }); }
    /** Solve the 3×3 homography mapping 4 source points onto 4 destination points. */
    function homography(src, dst) {
        var A = [], b = [], i;
        for (i = 0; i < 4; i++) {
            var x = src[i * 2], y = src[i * 2 + 1], u = dst[i * 2], v = dst[i * 2 + 1];
            A.push([x, y, 1, 0, 0, 0, -u * x, -u * y]); b.push(u);
            A.push([0, 0, 0, x, y, 1, -v * x, -v * y]); b.push(v);
        }
        var n = 8;
        for (var c = 0; c < n; c++) {
            var piv = c;
            for (var r = c + 1; r < n; r++) { if (Math.abs(A[r][c]) > Math.abs(A[piv][c])) { piv = r; } }
            var tmp = A[c]; A[c] = A[piv]; A[piv] = tmp;
            var tb = b[c]; b[c] = b[piv]; b[piv] = tb;
            if (Math.abs(A[c][c]) < 1e-12) { return null; }
            for (var r2 = c + 1; r2 < n; r2++) {
                var f = A[r2][c] / A[c][c];
                for (var k = c; k < n; k++) { A[r2][k] -= f * A[c][k]; }
                b[r2] -= f * b[c];
            }
        }
        var h = new Array(n);
        for (var i2 = n - 1; i2 >= 0; i2--) {
            var s = b[i2];
            for (var k2 = i2 + 1; k2 < n; k2++) { s -= A[i2][k2] * h[k2]; }
            h[i2] = s / A[i2][i2];
        }
        return [h[0], h[1], h[2], h[3], h[4], h[5], h[6], h[7], 1];
    }
    function applyH(H, x, y) {
        var w = H[6] * x + H[7] * y + H[8];
        return [(H[0] * x + H[1] * y + H[2]) / w, (H[3] * x + H[4] * y + H[5]) / w];
    }
    /** How to fit an image of iw×ih on a page (points), keeping the aspect ratio. */
    function fitOnPage(iw, ih, pw, ph, margin) {
        var bw = pw - 2 * margin, bh = ph - 2 * margin;
        var s = Math.min(bw / iw, bh / ih);
        var w = iw * s, h = ih * s;
        return { x: (pw - w) / 2, y: (ph - h) / 2, w: w, h: h };
    }

    function cross3(a, b) { return [a[1] * b[2] - a[2] * b[1], a[2] * b[0] - a[0] * b[2], a[0] * b[1] - a[1] * b[0]]; }
    function dot3(a, b) { return a[0] * b[0] + a[1] * b[1] + a[2] * b[2]; }
    /**
     * True width / height of the rectangle photographed as px quad q in an iw×ih photo
     * (Zhang & He, "Whiteboard scanning and image enhancement", 2007). The camera's focal length is
     * recovered from the two vanishing points when the view allows it; otherwise a typical phone
     * camera (focal length ≈ 0.8 × the long side) is assumed. A frontal view gives the plain side ratio.
     */
    function estimateAspect(q, iw, ih) {
        var cx = iw / 2, cy = ih / 2, L = Math.max(iw, ih);
        // paper notation: m1 top-left, m2 top-right, m3 bottom-left, m4 bottom-right
        var m1 = [q[0] - cx, q[1] - cy, 1], m2 = [q[2] - cx, q[3] - cy, 1];
        var m4 = [q[4] - cx, q[5] - cy, 1], m3 = [q[6] - cx, q[7] - cy, 1];
        var c14 = cross3(m1, m4);
        var d2 = dot3(cross3(m2, m4), m3), d3 = dot3(cross3(m3, m4), m2);
        var avg = (dist(q[0], q[1], q[2], q[3]) + dist(q[6], q[7], q[4], q[5])) /
            Math.max(1e-6, dist(q[0], q[1], q[6], q[7]) + dist(q[2], q[3], q[4], q[5]));
        if (Math.abs(d2) < 1e-9 || Math.abs(d3) < 1e-9) { return avg; }
        var k2 = dot3(c14, m3) / d2, k3 = dot3(c14, m2) / d3;
        var n2 = [k2 * m2[0] - m1[0], k2 * m2[1] - m1[1], k2 * m2[2] - m1[2]];
        var n3 = [k3 * m3[0] - m1[0], k3 * m3[1] - m1[1], k3 * m3[2] - m1[2]];
        var f2 = NaN, den = n2[2] * n3[2];
        // only trust the recovered focal length when both vanishing points are well defined
        if (Math.abs(n2[2]) > 0.02 && Math.abs(n3[2]) > 0.02) { f2 = -(n2[0] * n3[0] + n2[1] * n3[1]) / den; }
        var f = f2 > 0 ? Math.sqrt(f2) : NaN;
        if (!(f >= 0.3 * L && f <= 4 * L)) { f = 0.8 * L; f2 = f * f; }
        var num = (n2[0] * n2[0] + n2[1] * n2[1]) / f2 + n2[2] * n2[2];
        var dd = (n3[0] * n3[0] + n3[1] * n3[1]) / f2 + n3[2] * n3[2];
        if (!(num > 0) || !(dd > 0)) { return avg; }
        var r = Math.sqrt(num / dd);
        return isFinite(r) && r > 0.05 && r < 20 ? r : avg;
    }
    /** width/height ratio → the nearest standard paper within tolerance (keeps the orientation). */
    function snapAspect(r) {
        var land = r >= 1, L = land ? r : 1 / r, best = null, bd = SNAP_TOLERANCE;
        SNAP_SHAPES.forEach(function (k) {
            var d = Math.abs(L - SHAPES[k]) / SHAPES[k];
            if (d <= bd) { bd = d; best = k; }
        });
        if (!best) { return { ratio: r, shape: null }; }
        return { ratio: land ? SHAPES[best] : 1 / SHAPES[best], shape: best };
    }
    /**
     * Output size of the corrected page for px quad q in an iw×ih photo.
     * shape: 'auto' (true proportions, snapped to standard paper), 'free' (true proportions),
     * or folio | a4 | letter | legal | id. The long side keeps the captured resolution.
     */
    function pageDims(q, iw, ih, shape) {
        var meas = quadSize(q), long = Math.max(meas.w, meas.h);
        var r = estimateAspect(q, iw, ih), snapped = null;
        if (SHAPES[shape]) { r = r >= 1 ? SHAPES[shape] : 1 / SHAPES[shape]; snapped = shape; }
        else if (shape !== 'free') { var s = snapAspect(r); r = s.ratio; snapped = s.shape; }
        return r >= 1 ? { w: long, h: long / r, ratio: r, shape: snapped } : { w: long * r, h: long, ratio: r, shape: snapped };
    }

    /* =====================================================================
       Pixel helpers
       ===================================================================== */
    function lumaOf(d, n) {
        var g = new Uint8ClampedArray(n);
        for (var i = 0, j = 0; i < n; i++, j += 4) { g[i] = (d[j] * 77 + d[j + 1] * 150 + d[j + 2] * 29) >> 8; }
        return g;
    }
    /** Separable box blur (running sums, clamped edges). Returns a Float32Array. */
    function boxBlur(src, w, h, r) {
        var tmp = new Float32Array(w * h), out = new Float32Array(w * h), win = 2 * r + 1, x, y, i, acc, row;
        for (y = 0; y < h; y++) {
            row = y * w; acc = 0;
            for (i = -r; i <= r; i++) { acc += src[row + (i < 0 ? 0 : (i > w - 1 ? w - 1 : i))]; }
            for (x = 0; x < w; x++) {
                tmp[row + x] = acc / win;
                var add = x + r + 1, sub = x - r;
                acc += src[row + (add > w - 1 ? w - 1 : add)] - src[row + (sub < 0 ? 0 : sub)];
            }
        }
        for (x = 0; x < w; x++) {
            acc = 0;
            for (i = -r; i <= r; i++) { acc += tmp[(i < 0 ? 0 : (i > h - 1 ? h - 1 : i)) * w + x]; }
            for (y = 0; y < h; y++) {
                out[y * w + x] = acc / win;
                var a2 = y + r + 1, s2 = y - r;
                acc += tmp[(a2 > h - 1 ? h - 1 : a2) * w + x] - tmp[(s2 < 0 ? 0 : s2) * w + x];
            }
        }
        return out;
    }
    /** Three box passes ≈ Gaussian blur. */
    function gaussBlur(src, w, h, r) { return boxBlur(boxBlur(boxBlur(src, w, h, r), w, h, r), w, h, r); }
    /**
     * Exact area-average downscale of a 1-channel image so its long side is ≤ maxLong
     * (every source pixel is weighted by how much of it falls into the output pixel).
     */
    function downscaleArea(g, w, h, maxLong) {
        var f = Math.max(w, h) / maxLong;
        if (f <= 1) { return { data: g, w: w, h: h }; }
        var ow = Math.max(1, Math.round(w / f)), oh = Math.max(1, Math.round(h / f));
        function axis(n, on) {
            // per output index: list of [source index, weight]
            var sc = n / on, taps = [];
            for (var o = 0; o < on; o++) {
                var a = o * sc, b = a + sc, list = [];
                for (var s = Math.floor(a); s < Math.min(n, Math.ceil(b)); s++) {
                    var ov = Math.min(b, s + 1) - Math.max(a, s);
                    if (ov > 1e-6) { list.push(s, ov / sc); }
                }
                taps.push(list);
            }
            return taps;
        }
        var tx = axis(w, ow), ty = axis(h, oh), tmp = new Float32Array(ow * h), out = new Float32Array(ow * oh), x, y, k, acc, t;
        for (y = 0; y < h; y++) {
            var row = y * w;
            for (x = 0; x < ow; x++) { t = tx[x]; acc = 0; for (k = 0; k < t.length; k += 2) { acc += g[row + t[k]] * t[k + 1]; } tmp[y * ow + x] = acc; }
        }
        for (y = 0; y < oh; y++) {
            t = ty[y];
            for (x = 0; x < ow; x++) { acc = 0; for (k = 0; k < t.length; k += 2) { acc += tmp[t[k] * ow + x] * t[k + 1]; } out[y * ow + x] = acc; }
        }
        return { data: out, w: ow, h: oh };
    }

    /* =====================================================================
       Warp / rotate
       ===================================================================== */
    /** Perspective-correct px quad q of src (sw×sh, `ch` channels) into ow×oh (bilinear, clamped). */
    function warp(src, sw, sh, q, ow, oh, ch) {
        ch = ch || 4;
        var out = ch === 4 ? new Uint8ClampedArray(ow * oh * 4) : new Float32Array(ow * oh);
        var H = homography([0, 0, ow, 0, ow, oh, 0, oh], q);
        if (!H) { return out; }
        for (var y = 0; y < oh; y++) {
            var py = y + 0.5;
            for (var x = 0; x < ow; x++) {
                var px = x + 0.5;
                var w = H[6] * px + H[7] * py + H[8];
                var fx = (H[0] * px + H[1] * py + H[2]) / w - 0.5, fy = (H[3] * px + H[4] * py + H[5]) / w - 0.5;
                if (fx < 0) { fx = 0; } else if (fx > sw - 1.001) { fx = sw - 1.001; }
                if (fy < 0) { fy = 0; } else if (fy > sh - 1.001) { fy = sh - 1.001; }
                var x0 = fx | 0, y0 = fy | 0, dx = fx - x0, dy = fy - y0;
                if (ch === 4) {
                    var i00 = (y0 * sw + x0) * 4, i10 = i00 + 4, i01 = i00 + sw * 4, i11 = i01 + 4, oi = (y * ow + x) * 4;
                    for (var c = 0; c < 3; c++) {
                        var top = src[i00 + c] + (src[i10 + c] - src[i00 + c]) * dx;
                        var bot = src[i01 + c] + (src[i11 + c] - src[i01 + c]) * dx;
                        out[oi + c] = top + (bot - top) * dy;
                    }
                    out[oi + 3] = 255;
                } else {
                    var j00 = y0 * sw + x0, j01 = j00 + sw;
                    var t1 = src[j00] + (src[j00 + 1] - src[j00]) * dx, b1 = src[j01] + (src[j01 + 1] - src[j01]) * dx;
                    out[y * ow + x] = t1 + (b1 - t1) * dy;
                }
            }
        }
        return out;
    }
    function warpRGBA(src, sw, sh, q, ow, oh) { return warp(src, sw, sh, q, ow, oh, 4); }
    function warpGray(src, sw, sh, q, ow, oh) { return warp(src, sw, sh, q, ow, oh, 1); }
    /** Rotate by 90 / 180 / 270 degrees clockwise. ch = 4 (RGBA) or 1. Returns {data, w, h}. */
    function rotate90(d, w, h, deg, ch) {
        deg = ((deg % 360) + 360) % 360;
        ch = ch || 4;
        if (!deg) { return { data: d, w: w, h: h }; }
        var swap = deg === 90 || deg === 270, ow = swap ? h : w, oh = swap ? w : h;
        var out = new d.constructor(ow * oh * ch);
        for (var y = 0; y < h; y++) {
            for (var x = 0; x < w; x++) {
                var nx, ny;
                if (deg === 90) { nx = h - 1 - y; ny = x; }
                else if (deg === 180) { nx = w - 1 - x; ny = h - 1 - y; }
                else { nx = y; ny = w - 1 - x; }
                var si = (y * w + x) * ch, di = (ny * ow + nx) * ch;
                for (var c = 0; c < ch; c++) { out[di + c] = d[si + c]; }
            }
        }
        return { data: out, w: ow, h: oh };
    }
    /** Small clockwise rotation (deskew) about the centre, same size, edges replicated. */
    function rotateSmall(d, w, h, deg, ch) {
        ch = ch || 4;
        var a = deg * Math.PI / 180, cs = Math.cos(a), sn = Math.sin(a), cx = (w - 1) / 2, cy = (h - 1) / 2;
        var out = new d.constructor(w * h * ch);
        for (var y = 0; y < h; y++) {
            for (var x = 0; x < w; x++) {
                var rx = x - cx, ry = y - cy;
                var fx = cx + rx * cs + ry * sn, fy = cy - rx * sn + ry * cs;
                if (fx < 0) { fx = 0; } else if (fx > w - 1.001) { fx = w - 1.001; }
                if (fy < 0) { fy = 0; } else if (fy > h - 1.001) { fy = h - 1.001; }
                var x0 = fx | 0, y0 = fy | 0, dx = fx - x0, dy = fy - y0;
                var i00 = (y0 * w + x0) * ch, i10 = i00 + ch, i01 = i00 + w * ch, i11 = i01 + ch, oi = (y * w + x) * ch;
                for (var c = 0; c < ch; c++) {
                    var top = d[i00 + c] + (d[i10 + c] - d[i00 + c]) * dx;
                    var bot = d[i01 + c] + (d[i11 + c] - d[i01 + c]) * dx;
                    out[oi + c] = top + (bot - top) * dy;
                }
            }
        }
        return out;
    }

    /* =====================================================================
       Sharpness (blur detection)
       ===================================================================== */
    /**
     * Laplacian variance of a gray page, measured at about SHARP_LONG px so scores are comparable.
     * Only tiles with detail count (a mostly blank page is not "blurry"). null = too little content.
     */
    function sharpnessScore(g, w, h) {
        if (Math.max(w, h) > SHARP_LONG * 1.15) { var s = downscaleArea(g, w, h, SHARP_LONG); g = s.data; w = s.w; h = s.h; }
        var T = 8, tw = Math.max(8, Math.floor(w / T)), th = Math.max(8, Math.floor(h / T)), scores = [];
        for (var ty = 0; ty + th <= h; ty += th) {
            for (var tx = 0; tx + tw <= w; tx += tw) {
                var sum = 0, sum2 = 0, ls = 0, ls2 = 0, n = 0;
                for (var y = ty + 1; y < ty + th - 1; y++) {
                    for (var x = tx + 1; x < tx + tw - 1; x++) {
                        var i = y * w + x, v = g[i];
                        var lap = 4 * v - g[i - 1] - g[i + 1] - g[i - w] - g[i + w];
                        sum += v; sum2 += v * v; ls += lap; ls2 += lap * lap; n++;
                    }
                }
                if (!n) { continue; }
                var sd = Math.sqrt(Math.max(0, sum2 / n - (sum / n) * (sum / n)));
                if (sd > 14) { scores.push(ls2 / n - (ls / n) * (ls / n)); }
            }
        }
        if (scores.length < 3) { return null; }
        scores.sort(function (a, b) { return b - a; });
        // the sharpest half of the detailed tiles: robust against large flat areas and photos
        var top = scores.slice(0, Math.max(2, Math.ceil(scores.length / 2)));
        return top[Math.floor(top.length / 2)];
    }
    function sharpnessLevel(score) {
        if (score == null) { return 'unknown'; }
        return score < BLUR_WARN ? 'blurry' : (score < BLUR_SOFT ? 'soft' : 'sharp');
    }

    /* =====================================================================
       Enhancement building blocks (pixel filters only: nothing is added or redrawn)
       ===================================================================== */
    /**
     * Shadow removal / even lighting: estimate the paper brightness (local maximum at low resolution,
     * grown over text strokes, smoothed) and scale every pixel so the paper becomes evenly white.
     */
    function normalizeIllumination(d, w, h, strength, gray) {
        var n = w * h, Y = gray || lumaOf(d, n);
        var f = Math.max(2, Math.round(Math.max(w, h) / 500)), sw = Math.ceil(w / f), sh = Math.ceil(h / f);
        var small = new Float32Array(sw * sh), x, y, i;
        for (y = 0; y < h; y++) {
            var sy = (y / f) | 0;
            for (x = 0; x < w; x++) {
                var si = sy * sw + ((x / f) | 0), v = Y[y * w + x];
                if (v > small[si]) { small[si] = v; }
            }
        }
        var r = 3, grown = new Float32Array(sw * sh);
        for (y = 0; y < sh; y++) {
            for (x = 0; x < sw; x++) {
                var m = 0;
                for (var dy = -r; dy <= r; dy++) {
                    var yy = y + dy; if (yy < 0 || yy >= sh) { continue; }
                    for (var dx = -r; dx <= r; dx++) {
                        var xx = x + dx; if (xx < 0 || xx >= sw) { continue; }
                        var vv = small[yy * sw + xx]; if (vv > m) { m = vv; }
                    }
                }
                grown[y * sw + x] = m;
            }
        }
        var bg = gaussBlur(grown, sw, sh, Math.max(2, Math.round(Math.max(sw, sh) / 60)));
        var target = 245, s = strength == null ? 1 : strength;
        for (y = 0; y < h; y++) {
            var fy = Math.min(sh - 1, Math.max(0, (y + 0.5) / f - 0.5)), y0 = fy | 0, y1 = Math.min(sh - 1, y0 + 1), ay = fy - y0;
            for (x = 0; x < w; x++) {
                var fx = Math.min(sw - 1, Math.max(0, (x + 0.5) / f - 0.5)), x0 = fx | 0, x1 = Math.min(sw - 1, x0 + 1), ax = fx - x0;
                var b = (bg[y0 * sw + x0] * (1 - ax) + bg[y0 * sw + x1] * ax) * (1 - ay) + (bg[y1 * sw + x0] * (1 - ax) + bg[y1 * sw + x1] * ax) * ay;
                var gain = target / Math.max(24, b);
                if (gain > 3.5) { gain = 3.5; } else if (gain < 0.85) { gain = 0.85; }
                gain = 1 + (gain - 1) * s;
                i = (y * w + x) * 4;
                d[i] = d[i] * gain; d[i + 1] = d[i + 1] * gain; d[i + 2] = d[i + 2] * gain;
            }
        }
    }
    /** CLAHE (contrast-limited adaptive histogram equalization) on a gray image. */
    function clahe(g, w, h, clip, tiles) {
        var tx = Math.max(2, tiles || 8), ty = Math.max(2, Math.round((tiles || 8) * h / Math.max(1, w)));
        var tw = Math.ceil(w / tx), th = Math.ceil(h / ty);
        var luts = new Uint8Array(tx * ty * 256), hist = new Float64Array(256);
        for (var j = 0; j < ty; j++) {
            for (var i = 0; i < tx; i++) {
                hist.fill(0);
                var x0 = i * tw, y0 = j * th, x1 = Math.min(w, x0 + tw), y1 = Math.min(h, y0 + th), cnt = 0;
                var step = (x1 - x0) * (y1 - y0) > 40000 ? 2 : 1;
                for (var y = y0; y < y1; y += step) { for (var x = x0; x < x1; x += step) { hist[g[y * w + x] | 0]++; cnt++; } }
                if (!cnt) { continue; }
                var limit = Math.max(1, (clip || 2) * cnt / 256), excess = 0, v;
                for (v = 0; v < 256; v++) { if (hist[v] > limit) { excess += hist[v] - limit; hist[v] = limit; } }
                var add = excess / 256, acc = 0, base = (j * tx + i) * 256;
                for (v = 0; v < 256; v++) { acc += hist[v] + add; luts[base + v] = Math.min(255, Math.round(acc * 255 / cnt)); }
            }
        }
        var out = new Uint8ClampedArray(w * h);
        for (var yy = 0; yy < h; yy++) {
            var fy = (yy + 0.5) / th - 0.5, j0 = Math.floor(fy), ay = fy - j0;
            if (j0 < 0) { j0 = 0; ay = 0; } if (j0 >= ty - 1) { j0 = ty - 1; ay = 0; }
            var j1 = Math.min(ty - 1, j0 + 1);
            for (var xx = 0; xx < w; xx++) {
                var fx = (xx + 0.5) / tw - 0.5, i0 = Math.floor(fx), ax = fx - i0;
                if (i0 < 0) { i0 = 0; ax = 0; } if (i0 >= tx - 1) { i0 = tx - 1; ax = 0; }
                var i1 = Math.min(tx - 1, i0 + 1), val = g[yy * w + xx] | 0;
                var a = luts[(j0 * tx + i0) * 256 + val], b = luts[(j0 * tx + i1) * 256 + val];
                var c2 = luts[(j1 * tx + i0) * 256 + val], d2 = luts[(j1 * tx + i1) * 256 + val];
                out[yy * w + xx] = (a * (1 - ax) + b * ax) * (1 - ay) + (c2 * (1 - ax) + d2 * ax) * ay;
            }
        }
        return out;
    }
    /** Light noise reduction, then a thresholded unsharp mask (edges crisper, flat paper untouched). */
    function denoiseSharpen(g, w, h, amount) {
        var n = w * h, out = new Float32Array(n), i;
        var soft = boxBlur(g, w, h, 1);
        for (i = 0; i < n; i++) { out[i] = g[i] * 0.55 + soft[i] * 0.45; }
        if (amount > 0) {
            var bl = gaussBlur(out, w, h, 2);
            for (i = 0; i < n; i++) {
                var diff = out[i] - bl[i];
                if (diff > 3 || diff < -3) { out[i] += amount * diff; }
            }
        }
        return out;
    }
    /** Unsharp mask only (no noise reduction): used by the Sharpness slider on unprocessed modes. */
    function unsharp(g, w, h, amount) {
        var n = w * h, out = new Float32Array(n), bl = gaussBlur(g, w, h, 1);
        for (var i = 0; i < n; i++) { var diff = g[i] - bl[i]; out[i] = (diff > 2 || diff < -2) ? g[i] + amount * diff : g[i]; }
        return out;
    }
    /** Stretch so the darkest ~0.5% become black and the paper (~99.5%) white. */
    function stretchGray(g, n) {
        var hist = new Uint32Array(256), i, step = n > 400000 ? 3 : 1, cnt = 0;
        for (i = 0; i < n; i += step) { hist[g[i] < 0 ? 0 : (g[i] > 255 ? 255 : g[i] | 0)]++; cnt++; }
        var lo = 0, hi = 255, acc = 0, v;
        for (v = 0; v < 256; v++) { acc += hist[v]; if (acc >= cnt * 0.005) { lo = v; break; } }
        acc = 0;
        for (v = 255; v >= 0; v--) { acc += hist[v]; if (acc >= cnt * 0.005) { hi = v; break; } }
        if (hi - lo < 60) { return g; }
        var span = hi - lo, out = new Float32Array(n);
        for (i = 0; i < n; i++) { out[i] = (g[i] - lo) * 255 / span; }
        return out;
    }
    /** Write new gray values back: colour keeps its chroma (same change added to R, G and B). */
    function applyLuma(d, oldY, newY, n, asGray) {
        for (var i = 0, j = 0; i < n; i++, j += 4) {
            var ny = newY[i];
            if (asGray) { d[j] = d[j + 1] = d[j + 2] = ny; }
            else { var delta = ny - oldY[i]; d[j] += delta; d[j + 1] += delta; d[j + 2] += delta; }
        }
    }
    /** Remove single black pixels left by thresholding (specks), in place on RGBA. */
    function despeckle(d, w, h) {
        for (var y = 1; y < h - 1; y++) {
            for (var x = 1; x < w - 1; x++) {
                var i = (y * w + x) * 4;
                if (d[i] !== 0) { continue; }
                var dark = 0;
                for (var dy = -1; dy <= 1 && !dark; dy++) {
                    for (var dx = -1; dx <= 1; dx++) {
                        if ((dx || dy) && d[((y + dy) * w + x + dx) * 4] === 0) { dark = 1; break; }
                    }
                }
                if (!dark) { d[i] = d[i + 1] = d[i + 2] = 255; }
            }
        }
    }
    /** Brightness / contrast lookup table. b, c in -100..100. */
    function adjustLut(b, c, lo, hi) {
        var lut = new Uint8ClampedArray(256);
        var f = c >= 0 ? 1 + c / 50 : 1 + c / 125;
        var off = b * 1.28;
        lo = lo || 0; hi = hi == null ? 255 : hi;
        var span = Math.max(1, hi - lo);
        for (var v = 0; v < 256; v++) {
            var s = (v - lo) * 255 / span;
            lut[v] = (s - 128) * f + 128 + off;
        }
        return lut;
    }
    /** Adaptive black & white (Bradley–Roth, integral image). Brightness shifts the threshold. */
    function thresholdBradley(d, w, h, bright, contrast, grayIn) {
        var n = w * h, gray = grayIn || lumaOf(d, n), i, x, y;
        var integral = new Float64Array(n);
        for (y = 0; y < h; y++) {
            var sum = 0;
            for (x = 0; x < w; x++) {
                sum += gray[y * w + x];
                integral[y * w + x] = (y > 0 ? integral[(y - 1) * w + x] : 0) + sum;
            }
        }
        var s = Math.max(8, Math.round(Math.max(w, h) / 24)), half = s >> 1;
        var t = 0.15 - (bright || 0) / 500 + (contrast || 0) / 1000;   // brighter → fewer black pixels
        t = Math.min(0.4, Math.max(0.02, t));
        for (y = 0; y < h; y++) {
            var y1 = Math.max(0, y - half), y2 = Math.min(h - 1, y + half);
            for (x = 0; x < w; x++) {
                var x1 = Math.max(0, x - half), x2 = Math.min(w - 1, x + half);
                var count = (x2 - x1 + 1) * (y2 - y1 + 1);
                var a = integral[y2 * w + x2]
                    - (x1 > 0 ? integral[y2 * w + x1 - 1] : 0)
                    - (y1 > 0 ? integral[(y1 - 1) * w + x2] : 0)
                    + (x1 > 0 && y1 > 0 ? integral[(y1 - 1) * w + x1 - 1] : 0);
                var v = gray[y * w + x] * count <= a * (1 - t) ? 0 : 255;
                i = (y * w + x) * 4;
                d[i] = d[i + 1] = d[i + 2] = v;
            }
        }
    }
    /** Saturation boost around each pixel's own gray value (keeps its brightness). */
    function saturate(d, n, amount) {
        for (var i = 0, j = 0; i < n; i++, j += 4) {
            var r = d[j], g = d[j + 1], b = d[j + 2], m = (r * 77 + g * 150 + b * 29) / 256;
            d[j] = m + (r - m) * amount; d[j + 1] = m + (g - m) * amount; d[j + 2] = m + (b - m) * amount;
        }
    }
    /** Paper-coloured pixels (bright, nearly neutral) are pushed to clean white. */
    function whitenPaper(d, n, from) {
        from = from || 214;
        for (var i = 0, j = 0; i < n; i++, j += 4) {
            var r = d[j], g = d[j + 1], b = d[j + 2], y = (r * 77 + g * 150 + b * 29) >> 8;
            if (y <= from) { continue; }
            var mx = Math.max(r, g, b), mn = Math.min(r, g, b);
            if (mx - mn > 40) { continue; }
            var t = Math.min(1, (y - from) / 30) * (1 - (mx - mn) / 40);
            d[j] = r + (255 - r) * t; d[j + 1] = g + (255 - g) * t; d[j + 2] = b + (255 - b) * t;
        }
    }
    /**
     * White point for gray values: the paper (the most common bright level) becomes pure white, so
     * faint banding left by smooth lighting gradients disappears. Text is darkened by a few percent at most.
     */
    function whitePoint(g, n) {
        var hist = new Uint32Array(256), i, cnt = 0, step = n > 400000 ? 3 : 1;
        for (i = 0; i < n; i += step) { hist[g[i] < 0 ? 0 : (g[i] > 255 ? 255 : g[i] | 0)]++; cnt++; }
        // paper level: the brightest well-populated level (60th percentile of the bright half)
        var acc = 0, half = 0, v;
        for (v = 255; v >= 0; v--) { acc += hist[v]; if (acc >= cnt * 0.5) { half = v; break; } }
        if (half < 150) { return g; }                        // a dark photo, not a page: leave it
        var bright = 0;
        for (v = half; v < 256; v++) { bright += hist[v]; }
        acc = 0;
        var paper = 255;
        for (v = half; v < 256; v++) { acc += hist[v]; if (acc >= bright * 0.4) { paper = v; break; } }
        var W = Math.max(200, Math.min(250, paper - 6)), k = 255 / W, out = new Float32Array(n);
        for (i = 0; i < n; i++) { out[i] = g[i] * k; }
        return out;
    }

    /**
     * Glare (specular highlight) reduction. Bright, nearly colourless areas clearly above the paper's
     * own brightness are pulled down to the surrounding paper level; anything darker inside them
     * (faint text) is kept. Text that is completely washed out cannot be recovered.
     * Returns the share of the page that was treated (0 = no glare found).
     */
    function reduceGlare(d, w, h) {
        var n = w * h, Y = lumaOf(d, n), hist = new Uint32Array(256), i;
        for (i = 0; i < n; i += 3) { hist[Y[i]]++; }
        var cnt = Math.ceil(n / 3), acc = 0, paper = 255;
        for (var v = 0; v < 256; v++) { acc += hist[v]; if (acc >= cnt * 0.6) { paper = v; break; } }
        var thr = Math.max(232, paper + 16);
        if (thr >= 250) { return 0; }                       // already a bright, evenly lit page
        var wgt = new Float32Array(n), glare = 0;
        for (i = 0; i < n; i++) {
            var j = i * 4, mx = Math.max(d[j], d[j + 1], d[j + 2]), mn = Math.min(d[j], d[j + 1], d[j + 2]);
            if (mx - mn > 30) { continue; }
            var t = (Y[i] - (thr - 14)) / 18;                 // soft ramp: no hard ring at the edge
            if (t <= 0) { continue; }
            wgt[i] = t > 1 ? 1 : t;
            if (t >= 1) { glare++; }
        }
        var frac = glare / n;
        if (frac < 0.001 || frac > 0.35) { return 0; }
        // the paper around the glare: normalized convolution over the unaffected pixels (low resolution)
        var f = Math.max(2, Math.round(Math.max(w, h) / 300)), sw = Math.ceil(w / f), sh = Math.ceil(h / f);
        var num = [new Float32Array(sw * sh), new Float32Array(sw * sh), new Float32Array(sw * sh)], den = new Float32Array(sw * sh);
        for (var y = 0; y < h; y++) {
            for (var x = 0; x < w; x++) {
                var k = y * w + x, ok = 1 - wgt[k];
                if (ok <= 0) { continue; }
                var si = ((y / f) | 0) * sw + ((x / f) | 0), p = k * 4;
                num[0][si] += d[p] * ok; num[1][si] += d[p + 1] * ok; num[2][si] += d[p + 2] * ok; den[si] += ok;
            }
        }
        var r = Math.max(3, Math.round(Math.max(sw, sh) / 25)), dB = gaussBlur(den, sw, sh, r);
        var fill = num.map(function (a) { return gaussBlur(a, sw, sh, r); });
        for (var y2 = 0; y2 < h; y2++) {
            var sy = Math.min(sh - 1, (y2 / f) | 0);
            for (var x2 = 0; x2 < w; x2++) {
                var k2 = y2 * w + x2, wv = wgt[k2];
                if (!wv) { continue; }
                var s2 = sy * sw + Math.min(sw - 1, (x2 / f) | 0), dn = dB[s2];
                if (dn < 1e-3) { continue; }
                var q = k2 * 4;
                for (var c = 0; c < 3; c++) {
                    var target = fill[c][s2] / dn, cur = d[q + c];
                    if (target < cur) { d[q + c] = cur - (cur - target) * wv; }
                }
            }
        }
        return frac;
    }

    /**
     * Enhancement modes + brightness / contrast / sharpness, in place on RGBA.
     *  original   colours as captured (sliders only)
     *  magic      Magic Color: shadows removed, contrast, whiter paper, vivid colours
     *  enhanced   Auto Enhance: even lighting, CLAHE contrast, noise reduction, controlled sharpening
     *  gray       Auto Enhance as grayscale
     *  bw         even lighting + adaptive threshold (clean black & white)
     *  noshadow   shadows / uneven light removed, colours otherwise natural
     *  lighten    brightens dark photos (gamma + even lighting + mild contrast)
     * p: filter, bright, contrast (-100..100), sharpen (0..100, 50 = mode default), level (sharpness level)
     */
    function applyFilterData(d, w, h, p) {
        var filter = p.filter || 'original', b = p.bright || 0, k = p.contrast || 0;
        var sf = (p.sharpen == null ? 50 : p.sharpen) / 50;      // 0..2 × the mode's own sharpening
        var n = w * h, i;
        var base = p.level === 'soft' || p.level === 'blurry' ? 0.9 : 0.6;
        if (filter === 'enhanced' || filter === 'gray') {
            normalizeIllumination(d, w, h, 1);
            var Y0 = lumaOf(d, n);
            var Y3 = whitePoint(denoiseSharpen(stretchGray(clahe(Y0, w, h, 2.0, 8), n), w, h, base * sf), n);
            applyLuma(d, Y0, Y3, n, filter === 'gray');
        } else if (filter === 'magic') {
            normalizeIllumination(d, w, h, 1);
            var M0 = lumaOf(d, n);
            var M3 = denoiseSharpen(stretchGray(clahe(M0, w, h, 1.6, 8), n), w, h, base * sf);
            applyLuma(d, M0, M3, n, false);
            saturate(d, n, 1.35);
            whitenPaper(d, n, 214);
        } else if (filter === 'lighten') {
            var L0 = lumaOf(d, n), gam = new Float32Array(n), gl = new Float32Array(256);
            for (i = 0; i < 256; i++) { gl[i] = 255 * Math.pow(i / 255, 0.7); }
            for (i = 0; i < n; i++) { gam[i] = gl[L0[i]]; }
            applyLuma(d, L0, gam, n, false);
            normalizeIllumination(d, w, h, 0.7);
            var L1 = lumaOf(d, n), L2 = clahe(L1, w, h, 1.5, 8);
            applyLuma(d, L1, denoiseSharpen(L2, w, h, 0.4 * sf), n, false);
        } else if (filter === 'bw') {
            normalizeIllumination(d, w, h, 1);
            // a little sharpening first keeps thin strokes from breaking up
            thresholdBradley(d, w, h, b, k, denoiseSharpen(lumaOf(d, n), w, h, 0.5));
            despeckle(d, w, h);
        } else if (filter === 'noshadow') {
            normalizeIllumination(d, w, h, 1);
        }
        // Sharpness slider above the default on modes without their own sharpening
        if ((filter === 'original' || filter === 'noshadow') && sf > 1.02) {
            var U0 = lumaOf(d, n);
            applyLuma(d, U0, unsharp(U0, w, h, (sf - 1) * 1.2), n, false);
        }
        if ((b || k) && filter !== 'bw') {
            var lut = adjustLut(b, k, 0, 255);
            for (i = 0; i < n * 4; i += 4) {
                d[i] = lut[d[i]]; d[i + 1] = lut[d[i + 1]]; d[i + 2] = lut[d[i + 2]];
            }
        }
        return d;
    }

    /**
     * The whole page pipeline: perspective warp → rotation (90° steps) → deskew → glare → filter.
     * j: src (RGBA), sw, sh, quad (px in src, or null for the whole image), ow, oh,
     *    rot, skew, glare, filter, bright, contrast, sharpen, level.  Returns {data, w, h}.
     */
    function renderJob(j) {
        var d = j.src, w = j.sw, h = j.sh;
        if (j.quad) { d = warpRGBA(j.src, j.sw, j.sh, j.quad, j.ow, j.oh); w = j.ow; h = j.oh; }
        if (j.rot) { var r = rotate90(d, w, h, j.rot, 4); d = r.data; w = r.w; h = r.h; }
        if (j.skew && Math.abs(j.skew) >= 0.2) { d = rotateSmall(d, w, h, j.skew, 4); }
        if (j.glare) { reduceGlare(d, w, h); }
        applyFilterData(d, w, h, j);
        return { data: d, w: w, h: h };
    }

    /* =====================================================================
       Edge detection (OpenCV) and refinement (pure JS)
       ===================================================================== */
    /**
     * Find the page outline in an RGBA image (live preview frame or a downscaled photo).
     * Several strategies are tried (edges at two contrasts, bright paper, low-colour paper) and every
     * four-cornered outline is scored by size, how well it fills its outline, and its corner angles.
     * Returns {quad (normalized), score} or null.
     */
    function detectQuadCv(cv, rgba, w, h, opt) {
        opt = opt || {};
        var minFrac = opt.minArea || 0.12, cands = [], mats = [];
        function m(x) { mats.push(x); return x; }
        function collect(bin, mode) {
            var contours = new cv.MatVector(), hier = new cv.Mat();
            cv.findContours(bin, contours, hier, mode, cv.CHAIN_APPROX_SIMPLE);
            for (var i = 0; i < contours.size(); i++) {
                var cnt = contours.get(i), area = cv.contourArea(cnt);
                if (area >= minFrac * w * h) {
                    var peri = cv.arcLength(cnt, true), approx = new cv.Mat(), pts = null, hull = false;
                    cv.approxPolyDP(cnt, approx, 0.02 * peri, true);
                    if (approx.rows === 4 && cv.isContourConvex(approx)) {
                        pts = Array.prototype.slice.call(approx.data32S, 0, 8);
                    } else {
                        // rounded / slightly bent pages: the hull's 4-point approximation
                        var hm = new cv.Mat(), ha = new cv.Mat();
                        cv.convexHull(cnt, hm, false, true);
                        cv.approxPolyDP(hm, ha, 0.04 * cv.arcLength(hm, true), true);
                        if (ha.rows === 4) { pts = Array.prototype.slice.call(ha.data32S, 0, 8); hull = true; }
                        hm.delete(); ha.delete();
                    }
                    approx.delete();
                    if (pts) { cands.push({ pts: pts, area: area, hull: hull }); }
                }
                cnt.delete();
            }
            contours.delete(); hier.delete();
        }
        try {
            var src = m(new cv.Mat(h, w, cv.CV_8UC4));
            src.data.set(rgba);
            var gray = m(new cv.Mat());
            cv.cvtColor(src, gray, cv.COLOR_RGBA2GRAY);
            cv.GaussianBlur(gray, gray, new cv.Size(5, 5), 0);
            var k3 = m(cv.Mat.ones(3, 3, cv.CV_8U)), k5 = m(cv.Mat.ones(5, 5, cv.CV_8U));
            // 1–2) edges, normal and low contrast (white paper on a light desk)
            [[40, 140], [12, 40]].forEach(function (t) {
                var e = m(new cv.Mat());
                cv.Canny(gray, e, t[0], t[1]);
                cv.morphologyEx(e, e, cv.MORPH_CLOSE, k5);
                cv.dilate(e, e, k3);
                collect(e, cv.RETR_LIST);
            });
            // 3) bright paper on a darker background
            var th = m(new cv.Mat());
            cv.threshold(gray, th, 0, 255, cv.THRESH_BINARY + cv.THRESH_OTSU);
            cv.morphologyEx(th, th, cv.MORPH_CLOSE, k5);
            collect(th, cv.RETR_EXTERNAL);
            // 4) nearly colourless paper on a coloured surface (wood, cloth)
            var chm = m(new cv.Mat(h, w, cv.CV_8UC1)), cd = chm.data, maxC = 0;
            for (var i = 0, j = 0; i < w * h; i++, j += 4) {
                var c = Math.max(rgba[j], rgba[j + 1], rgba[j + 2]) - Math.min(rgba[j], rgba[j + 1], rgba[j + 2]);
                cd[i] = c; if (c > maxC) { maxC = c; }
            }
            if (maxC > 40) {
                cv.GaussianBlur(chm, chm, new cv.Size(5, 5), 0);
                cv.threshold(chm, chm, 0, 255, cv.THRESH_BINARY_INV + cv.THRESH_OTSU);
                cv.morphologyEx(chm, chm, cv.MORPH_OPEN, k5);
                cv.morphologyEx(chm, chm, cv.MORPH_CLOSE, k5);
                collect(chm, cv.RETR_EXTERNAL);
            }
        } catch (e) {
            /* fall through with what was found */
        } finally {
            mats.forEach(function (x) { try { x.delete(); } catch (e2) { /* ignore */ } });
        }
        var best = null;
        cands.forEach(function (c) {
            var qpx = orderQuad(c.pts);
            if (!isConvexQuad(qpx)) { return; }
            var qa = quadArea(qpx), frac = qa / (w * h);
            if (frac < minFrac || frac > 0.995) { return; }
            var fill = Math.min(1, c.area / Math.max(1, qa));
            if (fill < 0.8) { return; }
            var ang = 0, border = 0;
            for (var k = 0; k < 4; k++) {
                var p0 = (k + 3) % 4, p1 = k, p2 = (k + 1) % 4;
                var ax = qpx[p0 * 2] - qpx[p1 * 2], ay = qpx[p0 * 2 + 1] - qpx[p1 * 2 + 1];
                var bx = qpx[p2 * 2] - qpx[p1 * 2], by = qpx[p2 * 2 + 1] - qpx[p1 * 2 + 1];
                ang += Math.abs((ax * bx + ay * by) / (Math.sqrt(ax * ax + ay * ay) * Math.sqrt(bx * bx + by * by) || 1));
                var x = qpx[k * 2] / w, y = qpx[k * 2 + 1] / h;
                if (x < 0.012 || x > 0.988 || y < 0.012 || y > 0.988) { border++; }
            }
            var angScore = Math.max(0, 1 - ang / 4 * 1.6);
            var score = frac * (0.4 + 0.6 * fill) * (0.4 + 0.6 * angScore) * (border === 4 ? 0.5 : 1) * (c.hull ? 0.9 : 1);
            if (!best || score > best.score) { best = { qpx: qpx, score: score }; }
        });
        if (!best) { return null; }
        var q = best.qpx.slice();
        for (var t = 0; t < 8; t += 2) { q[t] = Math.min(1, Math.max(0, q[t] / w)); q[t + 1] = Math.min(1, Math.max(0, q[t + 1] / h)); }
        return { quad: q, score: best.score };
    }

    function sampleGray(g, w, h, x, y) {
        if (x < 0) { x = 0; } else if (x > w - 1.001) { x = w - 1.001; }
        if (y < 0) { y = 0; } else if (y > h - 1.001) { y = h - 1.001; }
        var x0 = x | 0, y0 = y | 0, dx = x - x0, dy = y - y0, i = y0 * w + x0;
        var t = g[i] + (g[i + 1] - g[i]) * dx, b = g[i + w] + (g[i + w + 1] - g[i + w]) * dx;
        return t + (b - t) * dy;
    }
    /** Total-least-squares line through points: {cx, cy, dx, dy} (unit direction). */
    function fitLine(pts) {
        var n = pts.length, mx = 0, my = 0, i;
        for (i = 0; i < n; i++) { mx += pts[i][0]; my += pts[i][1]; }
        mx /= n; my /= n;
        var sxx = 0, sxy = 0, syy = 0;
        for (i = 0; i < n; i++) { var dx = pts[i][0] - mx, dy = pts[i][1] - my; sxx += dx * dx; sxy += dx * dy; syy += dy * dy; }
        var a = 0.5 * Math.atan2(2 * sxy, sxx - syy);
        return { cx: mx, cy: my, dx: Math.cos(a), dy: Math.sin(a) };
    }
    function lineDist(L, x, y) { return Math.abs((x - L.cx) * L.dy - (y - L.cy) * L.dx); }
    function intersect(A, B) {
        var den = A.dx * B.dy - A.dy * B.dx;
        if (Math.abs(den) < 1e-9) { return null; }
        var t = ((B.cx - A.cx) * B.dy - (B.cy - A.cy) * B.dx) / den;
        return [A.cx + t * A.dx, A.cy + t * A.dy];
    }
    /**
     * Refine a coarse px quad on the full-resolution gray image: along each side the strongest
     * paper-edge step is searched across the side, a straight line is fitted through those points
     * (outliers dropped) and neighbouring lines are intersected. Corners that would move implausibly
     * far keep their coarse position.
     */
    function refineQuad(g, w, h, q) {
        var L = Math.max(w, h), R = Math.max(6, Math.round(L * 0.008)), step = Math.max(1, R / 16);
        var cx = (q[0] + q[2] + q[4] + q[6]) / 4, cy = (q[1] + q[3] + q[5] + q[7]) / 4;
        var lines = [];
        for (var s = 0; s < 4; s++) {
            var ax = q[s * 2], ay = q[s * 2 + 1], bx = q[((s + 1) % 4) * 2], by = q[((s + 1) % 4) * 2 + 1];
            var len = dist(ax, ay, bx, by), ux = (bx - ax) / len, uy = (by - ay) / len;
            var nx = -uy, ny = ux;
            // make the normal point outwards (away from the page centre)
            if (((ax + bx) / 2 - cx) * nx + ((ay + by) / 2 - cy) * ny < 0) { nx = -nx; ny = -ny; }
            var N = Math.max(12, Math.min(80, Math.round(len / 8))), pts = [];
            for (var k = 0; k < N; k++) {
                var t = 0.1 + 0.8 * (k + 0.5) / N, px = ax + (bx - ax) * t, py = ay + (by - ay) * t;
                var es = [], bi = -1, ai = -1;
                for (var o = -R, oi = 0; o <= R; o += step, oi++) {
                    var inV = sampleGray(g, w, h, px + nx * (o - 1.5), py + ny * (o - 1.5));
                    var outV = sampleGray(g, w, h, px + nx * (o + 1.5), py + ny * (o + 1.5));
                    es.push(inV - outV);
                    if (bi < 0 || es[oi] > es[bi]) { bi = oi; }
                    if (ai < 0 || Math.abs(es[oi]) > Math.abs(es[ai])) { ai = oi; }
                }
                // paper brighter than the background first; any strong step otherwise
                var pick = es[bi] >= 10 ? bi : (Math.abs(es[ai]) >= 10 ? ai : -1);
                if (pick >= 0) {
                    var off = -R + pick * step, sg = es[pick] < 0 ? -1 : 1;
                    if (pick > 0 && pick < es.length - 1) {          // parabola through the peak: sub-pixel
                        var a0 = sg * es[pick - 1], a1 = sg * es[pick], a2 = sg * es[pick + 1], den = a0 - 2 * a1 + a2;
                        if (den < 0) { off += step * 0.5 * (a0 - a2) / den; }
                    }
                    pts.push([px + nx * off, py + ny * off]);
                }
            }
            if (pts.length < 6) { lines.push({ cx: ax, cy: ay, dx: ux, dy: uy, coarse: true }); continue; }
            var Lf = fitLine(pts);
            var res = pts.map(function (p) { return lineDist(Lf, p[0], p[1]); }).sort(function (a, b) { return a - b; });
            var lim = Math.max(1.5, 2.5 * res[Math.floor(res.length / 2)]);
            var keep = pts.filter(function (p) { return lineDist(Lf, p[0], p[1]) <= lim; });
            lines.push(keep.length >= 6 ? fitLine(keep) : Lf);
        }
        var out = q.slice();
        for (var c = 0; c < 4; c++) {
            var P = intersect(lines[(c + 3) % 4], lines[c]);
            if (P && dist(P[0], P[1], q[c * 2], q[c * 2 + 1]) <= R * 2.5) {
                out[c * 2] = Math.min(w, Math.max(0, P[0]));
                out[c * 2 + 1] = Math.min(h, Math.max(0, P[1]));
            }
        }
        return isConvexQuad(out) ? out : q.slice();
    }

    /* =====================================================================
       Deskew and text orientation
       ===================================================================== */
    /** Dark (ink) pixels of a gray page, away from the page border. */
    function inkPoints(g, w, h, maxPts) {
        var n = w * h, hist = new Uint32Array(256), i, total = 0;
        for (i = 0; i < n; i += 2) { hist[g[i] < 0 ? 0 : (g[i] > 255 ? 255 : g[i] | 0)]++; total++; }
        // Otsu
        var sum = 0, v;
        for (v = 0; v < 256; v++) { sum += v * hist[v]; }
        var sumB = 0, wB = 0, best = 0, thr = 128;
        for (v = 0; v < 256; v++) {
            wB += hist[v]; if (!wB) { continue; }
            var wF = total - wB; if (!wF) { break; }
            sumB += v * hist[v];
            var mB = sumB / wB, mF = (sum - sumB) / wF, between = wB * wF * (mB - mF) * (mB - mF);
            if (between > best) { best = between; thr = v; }
        }
        var mx = Math.round(w * 0.04), my = Math.round(h * 0.04), xs = [], ys = [];
        var cnt = 0;
        for (var y = my; y < h - my; y++) { for (var x = mx; x < w - mx; x++) { if (g[y * w + x] < thr) { cnt++; } } }
        var keepEvery = Math.max(1, Math.ceil(cnt / (maxPts || 60000))), c2 = 0;
        for (var y2 = my; y2 < h - my; y2++) {
            for (var x2 = mx; x2 < w - mx; x2++) {
                if (g[y2 * w + x2] < thr && (c2++ % keepEvery) === 0) { xs.push(x2); ys.push(y2); }
            }
        }
        return { xs: xs, ys: ys, frac: cnt / Math.max(1, (w - 2 * mx) * (h - 2 * my)) };
    }
    /** How "peaky" the profile of t = v·cos a − u·sin a is (1 = flat); high when lines run along u. */
    function profilePeak(us, vs, a, len) {
        var cs = Math.cos(a), sn = Math.sin(a), off = Math.ceil(len * 0.15) + 2, size = len + 2 * off;
        var bins = new Float64Array(size), n = us.length, i;
        for (i = 0; i < n; i++) {
            var t = Math.round(vs[i] * cs - us[i] * sn) + off;
            if (t >= 0 && t < size) { bins[t]++; }
        }
        var s2 = 0;
        for (i = 0; i < size; i++) { s2 += bins[i] * bins[i]; }
        return { peak: n ? s2 * len / (n * n) : 0, bins: bins, off: off };
    }
    function bestAngle(us, vs, len) {
        var best = { a: 0, peak: -1 }, a, r;
        for (a = -5; a <= 5.001; a += 0.5) { r = profilePeak(us, vs, a * Math.PI / 180, len); if (r.peak > best.peak) { best = { a: a, peak: r.peak }; } }
        var c = best.a;
        for (a = c - 0.4; a <= c + 0.401; a += 0.1) { r = profilePeak(us, vs, a * Math.PI / 180, len); if (r.peak > best.peak) { best = { a: a, peak: r.peak }; } }
        best.zero = profilePeak(us, vs, 0, len).peak;
        return best;
    }
    /**
     * Latin text has more ink above the x-height band (capitals, b d f h k l t) than below
     * (g j p q y). Returns {up, down, lines} for profile bins whose low index is "up".
     */
    function lineAsymmetry(bins) {
        var size = bins.length, mx = 0, i, sm = bins;      // unsmoothed: lines are only ~10 px tall
        for (i = 0; i < size; i++) { if (sm[i] > mx) { mx = sm[i]; } }
        var T = mx * 0.05, up = 0, down = 0, lines = 0;
        i = 0;
        while (i < size) {
            if (sm[i] <= T) { i++; continue; }
            var a = i;
            while (i < size && sm[i] > T) { i++; }
            var b = i - 1;
            if (b - a < 4) { continue; }
            var P = 0, k;
            for (k = a; k <= b; k++) { if (sm[k] > P) { P = sm[k]; } }
            var top = a, bot = b;
            while (top < b && sm[top] < P * 0.5) { top++; }
            while (bot > a && sm[bot] < P * 0.5) { bot--; }
            for (k = a; k < top; k++) { up += sm[k]; }
            for (k = bot + 1; k <= b; k++) { down += sm[k]; }
            lines++;
        }
        return { up: up, down: down, lines: lines };
    }
    /**
     * Page orientation and residual skew of a gray page (≈1000 px long side).
     * Returns {rot: 0|90|270, skew: degrees to rotate clockwise, upsideDown: bool (not applied), text: bool}.
     * Only confident results are reported; anything unclear leaves the page as it is.
     */
    function orientation(g, w, h) {
        var res = { rot: 0, skew: 0, upsideDown: false, text: false };
        var ink = inkPoints(g, w, h);
        if (ink.xs.length < 800 || ink.frac > 0.35) { return res; }
        var rows = bestAngle(ink.xs, ink.ys, h), cols = bestAngle(ink.ys, ink.xs, w);
        var vertical = cols.peak > rows.peak * 1.25 && cols.peak > 1.6;
        if (vertical) {
            var pc = profilePeak(ink.ys, ink.xs, cols.a * Math.PI / 180, w), asy = lineAsymmetry(pc.bins);
            // low x = "up" after a 90° clockwise turn
            if (asy.lines >= 3 && Math.max(asy.up, asy.down) > 1.3 * Math.min(asy.up, asy.down)) {
                res.rot = asy.up > asy.down ? 90 : 270;
                var r = rotate90(g, w, h, res.rot, 1), ink2 = inkPoints(r.data, r.w, r.h), rows2 = bestAngle(ink2.xs, ink2.ys, r.h);
                res.text = true;
                if (rows2.peak > 1.5 && rows2.peak > rows2.zero * 1.02) { res.skew = -rows2.a; }
            }
            return res;
        }
        if (rows.peak < 1.5) { return res; }
        res.text = true;
        if (rows.peak > rows.zero * 1.02) { res.skew = -rows.a; }
        var pr = profilePeak(ink.xs, ink.ys, rows.a * Math.PI / 180, h), asy2 = lineAsymmetry(pr.bins);
        res.upsideDown = asy2.lines >= 6 && asy2.down > asy2.up * 1.6;
        if (Math.abs(res.skew) < 0.2) { res.skew = 0; }
        return res;
    }

    /**
     * Everything measured once per capture, on the full-resolution photo.
     * rgba/w/h: the stored original; quad: coarse normalized quad from detectQuadCv (or null).
     * Returns {quad (normalized, refined) | null, sharpness, rot, skew, upsideDown}.
     */
    function analyzeCapture(rgba, w, h, quad) {
        var g = lumaOf(rgba, w * h), page, pw, ph, out = { quad: null, sharpness: null, rot: 0, skew: 0, upsideDown: false };
        if (quad) {
            var qpx = refineQuad(g, w, h, scaleQuad(quad, w, h));
            out.quad = scaleQuad(qpx, 1 / w, 1 / h);
            var dims = pageDims(qpx, w, h, 'auto'), s = Math.min(1, 2000 / Math.max(dims.w, dims.h));
            pw = Math.max(1, Math.round(dims.w * s)); ph = Math.max(1, Math.round(dims.h * s));
            page = warpGray(g, w, h, qpx, pw, ph);
        } else {
            page = g; pw = w; ph = h;
        }
        var small = downscaleArea(page, pw, ph, SHARP_LONG);
        out.sharpness = sharpnessScore(small.data, small.w, small.h);
        var o = orientation(small.data, small.w, small.h);
        out.rot = o.rot; out.skew = o.skew; out.upsideDown = o.upsideDown;
        return out;
    }

    return {
        SHAPES: SHAPES, SNAP_SHAPES: SNAP_SHAPES, SHARP_LONG: SHARP_LONG, BLUR_WARN: BLUR_WARN, BLUR_SOFT: BLUR_SOFT, FILTER_IDS: FILTER_IDS,
        orderQuad: orderQuad, fullQuad: fullQuad, isFullQuad: isFullQuad, dist: dist, quadSize: quadSize, quadArea: quadArea,
        isConvexQuad: isConvexQuad, scaleQuad: scaleQuad, homography: homography, applyH: applyH, fitOnPage: fitOnPage,
        estimateAspect: estimateAspect, snapAspect: snapAspect, pageDims: pageDims,
        lumaOf: lumaOf, boxBlur: boxBlur, gaussBlur: gaussBlur, downscaleArea: downscaleArea,
        warpRGBA: warpRGBA, warpGray: warpGray, rotate90: rotate90, rotateSmall: rotateSmall,
        sharpnessScore: sharpnessScore, sharpnessLevel: sharpnessLevel,
        normalizeIllumination: normalizeIllumination, clahe: clahe, denoiseSharpen: denoiseSharpen, unsharp: unsharp,
        stretchGray: stretchGray, applyLuma: applyLuma, despeckle: despeckle, adjustLut: adjustLut, thresholdBradley: thresholdBradley,
        saturate: saturate, whitenPaper: whitenPaper, whitePoint: whitePoint, reduceGlare: reduceGlare, applyFilterData: applyFilterData, renderJob: renderJob,
        detectQuadCv: detectQuadCv, refineQuad: refineQuad, fitLine: fitLine, intersect: intersect,
        inkPoints: inkPoints, orientation: orientation, analyzeCapture: analyzeCapture
    };
}));
