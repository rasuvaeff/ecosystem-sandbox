// What only a browser can show, driven through Chrome DevTools: the order page
// receives its status over Centrifugo's WebSocket, the contract page finds no
// disagreement between the OpenAPI document and the server, and no page throws.
//
// Runs on the host against `make ui`: needs Node 22+ (built-in WebSocket) and
// Chrome (`CHROME`, default google-chrome).
import { spawn } from 'node:child_process';
import { mkdtempSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

const base = process.env.UI_URL ?? 'http://127.0.0.1:8080/ui/';
const port = 9300 + Math.floor(Math.random() * 500);
const chrome = spawn(process.env.CHROME ?? 'google-chrome', [
    '--headless=new', '--no-sandbox', '--disable-gpu', `--remote-debugging-port=${port}`,
    `--user-data-dir=${mkdtempSync(join(tmpdir(), 'sandbox-ui-'))}`, 'about:blank',
], { stdio: 'ignore' });
const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));
const fail = (message) => { console.error(`UI BROWSER SMOKE FAIL: ${message}`); chrome.kill(); process.exit(1); };

let targets;
for (let i = 0; i < 50 && targets === undefined; i++) {
    try { targets = await (await fetch(`http://127.0.0.1:${port}/json`)).json(); } catch { await sleep(200); }
}
if (targets === undefined) fail('Chrome did not start');
const socket = new WebSocket(targets.find((target) => target.type === 'page').webSocketDebuggerUrl);
await new Promise((resolve) => socket.addEventListener('open', resolve));
let nextId = 0;
const pending = new Map();
const errors = [];
socket.addEventListener('message', (event) => {
    const message = JSON.parse(event.data);
    if (pending.has(message.id)) { pending.get(message.id)(message); pending.delete(message.id); }
    if (message.method === 'Runtime.exceptionThrown') errors.push(message.params.exceptionDetails.exception?.description ?? message.params.exceptionDetails.text);
});
const send = (method, params = {}) => new Promise((resolve) => { const id = ++nextId; pending.set(id, resolve); socket.send(JSON.stringify({ id, method, params })); });
const evaluate = async (expression) => (await send('Runtime.evaluate', { expression, awaitPromise: true, returnByValue: true })).result?.result?.value;
const open = async (page) => { await send('Page.navigate', { url: base + page }); await sleep(2500); };
await send('Page.enable');
await send('Runtime.enable');

for (const page of ['index.html', 'resilience.html', 'trace.html', 'operator.html', 'contract.html', 'agent.html', 'webhooks.html', 'analytics.html']) {
    await open(page);
    const nav = await evaluate(`document.querySelectorAll('#nav a').length`);
    if (nav < 8) fail(`${page} rendered no navigation`);
}
if (errors.length > 0) fail('a page threw: ' + errors.join(' | '));

// Arrive through an "ad" link: the campaign must ride the checkout and come
// back in the order's story.
const campaign = 'browser-smoke-' + Date.now();
await open(`index.html?utm_source=newsletter&utm_medium=email&utm_campaign=${campaign}`);
const variantShown = await evaluate(`document.getElementById('experiment').textContent`);
const greenButton = await evaluate(`document.getElementById('pay').classList.contains('variant-green')`);
if (!/control|green/.test(variantShown) || greenButton !== variantShown.includes('green')) fail(`the pay button does not show its A/B variant: "${variantShown}"`);
await evaluate(`document.querySelector('input[value="ok"]').click(); document.getElementById('pay').click(); true`);
let live = '';
for (let i = 0; i < 20 && !/WebSocket.*(оплачен|paid)/.test(live); i++) {
    await sleep(1000);
    live = await evaluate(`(() => { const n = document.getElementById('live'); return n.hidden ? '' : n.textContent; })()`);
}
if (!/WebSocket.*(оплачен|paid)/.test(live)) fail(`the order page got no WebSocket push: "${live}"`);
const attribution = await evaluate(`document.getElementById('attribution').textContent`);
if (!attribution.includes(campaign)) fail(`the order story does not name the campaign: "${attribution}"`);
await evaluate(`localStorage.removeItem('sandbox-ui-campaign'); true`);
const paidId = await evaluate(`new URLSearchParams(location.search).get('order')`);

// The request budget on the trace page: asking with 1 ms must come back 504.
await open(`trace.html?order=${encodeURIComponent(paidId)}`);
let budgetShown = false;
for (let i = 0; i < 15 && !budgetShown; i++) {
    await sleep(1000);
    budgetShown = await evaluate(`!document.getElementById('budget-panel').hidden`);
}
if (!budgetShown) fail('the trace page shows no request budget');
await evaluate(`document.getElementById('budget-tight').click(); true`);
await sleep(1500);
const budgetResult = await evaluate(`document.getElementById('budget-result').textContent`);
if (!budgetResult.includes('504')) fail(`a 1 ms budget did not come back 504: "${budgetResult}"`);

// The agent page's button, clicked as a person clicks it: a fresh browser gets
// an order filled in, and every press asks again and says when the answer came,
// even when the transcript is the same as before.
await send('Emulation.setDeviceMetricsOverride', { width: 1280, height: 900, deviceScaleFactor: 1, mobile: false });
await open('agent.html');
const preset = await evaluate(`document.getElementById('order-id').value`);
if (preset === '') fail('the agent page filled in no order to ask about');
const asked = await evaluate(`document.getElementById('asked').textContent`);
await sleep(1100);
const box = JSON.parse(await evaluate(`JSON.stringify(document.getElementById('ask-button').getBoundingClientRect())`));
const point = { x: box.x + box.width / 2, y: box.y + box.height / 2, button: 'left', clickCount: 1 };
await send('Input.dispatchMouseEvent', { type: 'mousePressed', ...point });
await send('Input.dispatchMouseEvent', { type: 'mouseReleased', ...point });
let answered = asked;
for (let i = 0; i < 10 && answered === asked; i++) {
    await sleep(500);
    answered = await evaluate(`document.getElementById('asked').textContent`);
}
if (answered === asked || !answered.includes(preset)) fail(`the agent button did not ask again: "${asked}" -> "${answered}"`);
const panels = await evaluate(`document.querySelectorAll('#agents .panel').length`);
if (panels !== 2) fail(`the agent page drew ${panels} identity panels, expected 2`);
const auditRows = await evaluate(`document.querySelectorAll('#agents .panel')[0].querySelectorAll('.audit-list li').length`);
if (auditRows !== 3) fail(`the permitted agent's panel shows ${auditRows} audit rows, expected one per tool call (3)`);
await send('Emulation.clearDeviceMetricsOverride');

await open('contract.html');
await evaluate(`document.getElementById('run').click(); true`);
let summary = '';
let finished = false;
for (let i = 0; i < 30 && !finished; i++) {
    await sleep(1000);
    finished = await evaluate(`!document.getElementById('run').disabled`);
}
if (!finished) fail('the contract page did not finish sending its requests within 30 s');
summary = await evaluate(`document.getElementById('summary').textContent`);
const sent = Number(summary.match(/\d+/)?.[0] ?? 0);
if (sent === 0) fail(`the contract page sent no generated requests: "${summary}"`);
const disagreements = await evaluate(`document.querySelectorAll('#results .badge.bad').length`);
if (disagreements !== 0) fail(`the contract page found ${disagreements} disagreement(s): ${summary}`);

socket.close();
chrome.kill();
console.log(`UI BROWSER SMOKE PASS: 8 pages, live push received, campaign attributed, A/B variant shown, budget 504, agent button asked again, contract ${summary}`);
