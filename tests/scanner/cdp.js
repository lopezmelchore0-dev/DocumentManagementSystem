/**
 * Minimal Chrome DevTools Protocol driver (headless Chrome / Edge, Node 22+ built-in WebSocket).
 * Used to make text fixtures with real fonts and to smoke-test the scanner's Web Worker in a browser.
 */
'use strict';
const { spawn } = require('child_process');
const fs = require('fs');
const os = require('os');
const path = require('path');

const CANDIDATES = [
    process.env.CHROME_PATH,
    'C:/Program Files/Google/Chrome/Application/chrome.exe',
    'C:/Program Files (x86)/Google/Chrome/Application/chrome.exe',
    path.join(process.env.LOCALAPPDATA || '', 'Google/Chrome/Application/chrome.exe'),
    'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe',
    'C:/Program Files/Microsoft/Edge/Application/msedge.exe',
    '/usr/bin/google-chrome', '/usr/bin/chromium', '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome'
].filter(Boolean);

function findBrowser() {
    for (const p of CANDIDATES) { if (fs.existsSync(p)) { return p; } }
    return null;
}

async function waitFor(fn, ms) {
    const end = Date.now() + ms;
    for (;;) {
        try { const v = await fn(); if (v) { return v; } } catch (e) { /* retry */ }
        if (Date.now() > end) { throw new Error('Timed out waiting for the browser'); }
        await new Promise((r) => setTimeout(r, 150));
    }
}

/** Runs fn(page) with a fresh headless browser; page.eval(expr) awaits promises and returns values. */
async function withBrowser(fn, extraArgs) {
    const exe = findBrowser();
    if (!exe) { throw new Error('Chrome or Edge not found (set CHROME_PATH).'); }
    const port = 9400 + Math.floor(Math.random() * 400);
    const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'dsc-cdp-'));
    const proc = spawn(exe, ['--headless=new', '--remote-debugging-port=' + port, '--user-data-dir=' + dir,
        '--ignore-certificate-errors', '--no-first-run', '--no-default-browser-check', '--disable-extensions'].concat(extraArgs || [], ['about:blank']),
        { stdio: 'ignore' });
    let ws;
    try {
        const target = await waitFor(async () => {
            const list = await (await fetch('http://127.0.0.1:' + port + '/json/list')).json();
            return list.find((t) => t.type === 'page');
        }, 20000);
        ws = new WebSocket(target.webSocketDebuggerUrl);
        await new Promise((res, rej) => { ws.onopen = res; ws.onerror = rej; });
        let id = 0;
        const pending = new Map();
        const logs = [];
        ws.onmessage = (ev) => {
            const msg = JSON.parse(ev.data);
            if (msg.id && pending.has(msg.id)) { const p = pending.get(msg.id); pending.delete(msg.id); msg.error ? p.rej(new Error(msg.error.message)) : p.res(msg.result); }
            else if (msg.method === 'Runtime.consoleAPICalled') { logs.push(msg.params.type + ': ' + msg.params.args.map((a) => a.value !== undefined ? a.value : a.description).join(' ')); }
            else if (msg.method === 'Runtime.exceptionThrown') { logs.push('exception: ' + (msg.params.exceptionDetails.exception || {}).description); }
        };
        const send = (method, params) => new Promise((res, rej) => { const i = ++id; pending.set(i, { res, rej }); ws.send(JSON.stringify({ id: i, method, params: params || {} })); });
        await send('Runtime.enable');
        await send('Page.enable');
        const page = {
            send, logs,
            async goto(url) {
                await send('Page.navigate', { url });
                await waitFor(async () => (await page.eval('document.readyState')) === 'complete', 30000);
            },
            async eval(expression) {
                const r = await send('Runtime.evaluate', { expression, awaitPromise: true, returnByValue: true });
                if (r.exceptionDetails) { throw new Error((r.exceptionDetails.exception && r.exceptionDetails.exception.description) || r.exceptionDetails.text); }
                return r.result.value;
            }
        };
        return await fn(page);
    } finally {
        try { if (ws) { ws.close(); } } catch (e) { /* ignore */ }
        proc.kill();
        setTimeout(() => { try { fs.rmSync(dir, { recursive: true, force: true }); } catch (e) { /* ignore */ } }, 1500);
    }
}

module.exports = { withBrowser, findBrowser };
