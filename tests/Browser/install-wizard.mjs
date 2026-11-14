import assert from 'node:assert/strict';
import { execFileSync, spawn } from 'node:child_process';
import { mkdtempSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { once } from 'node:events';

// 使用真实 Blade 输出和隔离浏览器；所有请求都由 CDP 拦截，不提交实际安装。
const executable = process.env.PTADMIN_TEST_BROWSER;
assert(executable, 'Set PTADMIN_TEST_BROWSER to a Chromium or Chrome executable.');
const html = execFileSync(process.env.PHP_BINARY || 'php', [
    fileURLToPath(new URL('../Fixtures/Install/browser-page.php', import.meta.url)),
], { encoding: 'utf8' });
const profile = mkdtempSync(join(tmpdir(), 'ptadmin-browser-profile-'));
const browser = spawn(executable, [
    '--headless=new', '--no-first-run', '--no-default-browser-check',
    '--disable-background-networking', '--remote-debugging-pipe', `--user-data-dir=${profile}`,
], { stdio: ['ignore', 'ignore', 'pipe', 'pipe', 'pipe'] });
let sequence = 0;
let buffer = '';
let session;
let stderr = '';
const pending = new Map();
const exceptions = [];
const posts = [];
const message = 'Database error: <img src="data:," onerror="window.installErrorExecuted=1"> & <svg onload="window.installErrorExecuted=1"></svg>';
const hostileType = '<img src="data:," onerror="window.installErrorExecuted=1">';
const stream = [
    { type: hostileType, message },
    { type: 'error', message },
].map(event => JSON.stringify(event) + '\n\n').join('');
browser.stderr.on('data', chunk => { stderr += chunk; });

function send(method, params = {}, sessionId = session) {
    if (browser.exitCode !== null || browser.signalCode !== null || browser.stdio[3].destroyed || browser.stdio[3].writableEnded) {
        return Promise.reject(new Error('Browser connection is closed.'));
    }
    const id = ++sequence;
    return new Promise((resolve, reject) => {
        pending.set(id, { resolve, reject });
        browser.stdio[3].write(JSON.stringify({ id, method, params, ...(sessionId ? { sessionId } : {}) }) + '\0');
    });
}

browser.stdio[3].on('error', error => {
    for (const promise of pending.values()) {
        promise.reject(error);
    }
    pending.clear();
});

browser.stdio[4].on('data', chunk => {
    buffer += chunk;
    let position;
    while ((position = buffer.indexOf('\0')) >= 0) {
        const event = JSON.parse(buffer.slice(0, position));
        buffer = buffer.slice(position + 1);
        if (event.id) {
            const promise = pending.get(event.id);
            pending.delete(event.id);
            event.error ? promise.reject(event.error) : promise.resolve(event.result);
        } else if (event.method === 'Runtime.exceptionThrown') {
            exceptions.push(event.params);
        } else if (event.method === 'Fetch.requestPaused') {
            const request = event.params.request;
            const isStream = request.url.endsWith('/install/stream');
            if (isStream) {
                posts.push(request);
            }
            send('Fetch.fulfillRequest', {
                requestId: event.params.requestId,
                responseCode: 200,
                responseHeaders: [{ name: 'Content-Type', value: isStream ? 'application/x-ndjson' : 'text/html; charset=utf-8' }],
                body: Buffer.from(isStream ? stream : html).toString('base64'),
            }).catch(error => exceptions.push(error));
        }
    }
});

async function evaluate(expression) {
    const result = await send('Runtime.evaluate', { expression, returnByValue: true, awaitPromise: true });
    assert(!result.exceptionDetails, JSON.stringify(result.exceptionDetails));
    return result.result.value;
}

async function waitUntil(expression) {
    for (let attempt = 0; attempt < 100; attempt++) {
        if (await evaluate(expression)) {
            return;
        }
        await new Promise(resolve => setTimeout(resolve, 30));
    }
    assert.fail(`Browser condition timed out: ${expression}`);
}

const timeout = setTimeout(() => {
    console.error('Installation browser verification timed out.', stderr);
    browser.kill();
}, 20000);
browser.on('exit', () => {
    for (const promise of pending.values()) {
        promise.reject(new Error('Browser exited before completing the command.'));
    }
    pending.clear();
});
try {
    const target = await send('Target.createTarget', { url: 'about:blank' }, null);
    session = (await send('Target.attachToTarget', { targetId: target.targetId, flatten: true }, null)).sessionId;
    await send('Runtime.enable');
    await send('Page.enable');
    await send('Fetch.enable', { patterns: [{ urlPattern: '*' }] });
    await send('Page.navigate', { url: 'https://install.example.test/__ptadmin__/install/account' });
    await waitUntil('!!document.querySelector("#install-forms")');
    assert.equal(await evaluate('document.querySelector("[name=app_url]").value'), 'https://install.example.test');
    await evaluate(`(() => {
        const values = { username: 'founder-1', password: 'abc123', password_confirmation: 'abc123', db_username: 'dbuser' };
        for (const [name, value] of Object.entries(values)) {
            const field = document.querySelector('[name=' + name + ']');
            field.value = value;
            field.dispatchEvent(new Event('input'));
        }
        document.querySelector('#submit').click();
    })()`);
    await waitUntil('document.querySelectorAll(".install-console-item").length >= 4');
    // 等待潜在的图片错误和 SVG load 事件，不能只检查刚插入 DOM 的瞬间。
    await evaluate('new Promise(resolve => setTimeout(resolve, 150))');
    assert.equal(posts.length, 1);
    assert.equal(posts[0].url, 'https://install.example.test/__ptadmin__/install/stream');
    assert.equal(await evaluate('window.installErrorExecuted === 1'), false);
    assert.equal(await evaluate('document.querySelectorAll("#install-console img, #install-console svg").length'), 0);
    const rows = await evaluate('Array.from(document.querySelectorAll(".install-console-item"), row => row.textContent)');
    assert(rows.some(row => row.includes(hostileType) && row.includes(message)));
    assert(rows.some(row => row.startsWith('error') && row.includes(message)));
    assert.deepEqual(exceptions, []);
    await evaluate('document.querySelector("#install-dialog-actions button").click()');
    assert.equal(await evaluate('document.querySelector("#submit").disabled'), false);
    console.log('PASS: installation error type and message remain plain text; no injected events run; retry remains available.');
} finally {
    clearTimeout(timeout);
    await send('Browser.close', {}, null).catch(() => {});
    if (browser.exitCode === null && browser.signalCode === null) {
        const stopped = once(browser, 'exit');
        browser.kill();
        await stopped;
    }
    rmSync(profile, { recursive: true, force: true });
}
