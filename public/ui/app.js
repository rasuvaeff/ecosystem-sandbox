'use strict';

// Every step below is derived from GET /api/v1/orders/{id}/evidence: nothing is
// animated on a timer, so the page can only show what the database holds.

const AMOUNTS = { ok: 1999, declined: 4002, blip: 5031, outage: 5030, lost: 5040 };
const POLL_MS = 1000;

const state = {
    socket: null,
    liveGeneration: 0,
    live: null,
    workflow: null,
    breaker: null,
    order: null,
    evidence: null,
    requestId: null,
    timer: null,
    message: null,
    campaign: null,
    experiment: null,
    captcha: null,
};

const $ = UI.$;
const t = UI.t;

// The campaign that brought this visitor: read from the address once and kept
// in localStorage, the client profile yii3-utm documents for an API checkout.
// It travels to the server as X-Utm-* headers, which UtmCaptureMiddleware reads.
const UTM_KEYS = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'utm_id'];
const CLICK_KEYS = ['gclid', 'fbclid', 'yclid', 'msclkid'];

function captureCampaign() {
    const query = new URLSearchParams(location.search);
    const utm = {};
    for (const key of [...UTM_KEYS, ...CLICK_KEYS]) {
        if (query.get(key)) {
            utm[key] = query.get(key);
        }
    }
    if (Object.keys(utm).length === 0) {
        return;
    }
    const touch = { ...utm, landing: location.href, referrer: document.referrer };
    try {
        localStorage.setItem('sandbox-ui-campaign', JSON.stringify(touch));
    } catch {
        // Not kept; this order is still attributed from the address.
    }
    state.campaign = touch;
}

function storedCampaign() {
    try {
        return JSON.parse(localStorage.getItem('sandbox-ui-campaign') ?? 'null');
    } catch {
        return null;
    }
}

function campaignHeaders() {
    const touch = state.campaign;
    if (touch === null) {
        return {};
    }
    const headers = {};
    for (const key of UTM_KEYS) {
        if (touch[key]) {
            headers['X-Utm-' + key.slice(4, 5).toUpperCase() + key.slice(5)] = touch[key];
        }
    }
    const clicks = Object.fromEntries(CLICK_KEYS.filter((key) => touch[key]).map((key) => [key, touch[key]]));
    if (Object.keys(clicks).length > 0) {
        headers['X-Utm-Click-Ids'] = JSON.stringify(clicks);
    }
    headers['X-Utm-Landing-Page'] = touch.landing;
    if (touch.referrer) {
        headers['X-Utm-Referrer'] = touch.referrer;
    }

    return headers;
}

function renderCampaign() {
    const touch = state.campaign;
    $('campaign-current').textContent = touch === null
        ? t('utm.none')
        : t('utm.current', { source: touch.utm_source ?? '—', medium: touch.utm_medium ?? '—', campaign: touch.utm_campaign ?? '—' });
    $('campaign-clear').hidden = touch === null;
}

// The A/B test on the pay button: the server assigns the variant from the
// visitor's ab_id cookie and records the exposure; the signed receipt goes back
// with the checkout, which records the conversion for the variant seen.
async function loadExperiment() {
    try {
        const { response, body } = await UI.json('/api/v1/experiments/checkout-button');
        state.experiment = response.ok ? body : null;
    } catch {
        state.experiment = null;
    }
}

// Opt-in reCAPTCHA (CAPTCHA_ENABLED): Google's widget is loaded only when the
// checkout asks for it, and its answer travels as X-Captcha-Token.
async function loadCaptcha() {
    try {
        const { response, body } = await UI.json('/api/v1/captcha');
        state.captcha = response.ok && body.enabled ? { siteKey: body.siteKey, widget: null } : null;
    } catch {
        state.captcha = null;
    }
    if (state.captcha === null) {
        return;
    }
    $('captcha-box').hidden = false;
    window.sandboxCaptchaReady = () => {
        state.captcha.widget = window.grecaptcha.render('captcha', { sitekey: state.captcha.siteKey });
    };
    const script = document.createElement('script');
    script.src = 'https://www.google.com/recaptcha/api.js?onload=sandboxCaptchaReady&render=explicit';
    script.async = true;
    document.head.append(script);
}

function captchaHeaders() {
    if (state.captcha === null || state.captcha.widget === null || !window.grecaptcha) {
        return {};
    }

    return { 'X-Captcha-Token': window.grecaptcha.getResponse(state.captcha.widget) };
}

function renderExperiment() {
    const experiment = state.experiment;
    const green = experiment !== null && experiment.variant === 'green';
    $('pay').classList.toggle('variant-green', green);
    if (!$('pay').disabled) {
        $('pay').textContent = t(green ? 'form.pay.green' : 'form.pay');
    }
    $('experiment').textContent = experiment === null
        ? ''
        : t('ab.current', { variant: experiment.variant, kind: t(experiment.sticky ? 'ab.sticky' : 'ab.computed') });
}

function render() {
    renderExperiment();
    renderCampaign();
    renderAmount();
    renderMessage();
    renderStory();
}

function selectedCard() {
    return document.querySelector('input[name="card"]:checked').value;
}

const formatAmount = UI.money;

function renderAmount() {
    $('amount').textContent = formatAmount(AMOUNTS[selectedCard()]);
}

function showMessage(key, params = {}, isError = false) {
    state.message = { key, params, isError };
    renderMessage();
}

function renderMessage() {
    const node = $('message');
    if (state.message === null) {
        node.hidden = true;

        return;
    }
    node.hidden = false;
    node.className = state.message.isError ? 'message error' : 'message';
    node.textContent = t(state.message.key, state.message.params);
}

async function postCheckout(order) {
    const response = await fetch('/api/v1/checkout', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'Accept': 'application/json',
            'Idempotency-Key': order.key,
            ...campaignHeaders(),
            ...captchaHeaders(),
            ...(state.experiment === null ? {} : { 'X-Ab-Receipt': state.experiment.receipt }),
        },
        body: JSON.stringify({
            orderId: order.id,
            customerId: 'ui-visitor',
            amountCents: order.amountCents,
        }),
    });
    const body = await response.json().catch(() => ({}));

    return { response, body };
}

async function pay(event) {
    event.preventDefault();
    stopPolling();
    const id = 'ui-' + Date.now().toString(36) + '-' + Math.random().toString(36).slice(2, 6);
    const order = { id, key: 'ui-checkout-' + id, amountCents: AMOUNTS[selectedCard()] };
    $('pay').disabled = true;
    $('pay').textContent = t('form.paying');
    state.message = null;
    renderMessage();

    try {
        const { response, body } = await postCheckout(order);
        if (response.status !== 202) {
            showMessage('error.http', { status: response.status, title: body.title ?? '' }, true);

            return;
        }
        state.order = order;
        state.evidence = null;
        state.requestId = response.headers.get('X-Request-ID');
        history.replaceState(null, '', '?order=' + encodeURIComponent(order.id));
        UI.rememberOrder(order.id);
        $('again').hidden = false;
        $('again-note').hidden = false;
        $('new-order').hidden = false;
        lockForm(true);
        await refresh();
        startPolling();
        connectLive(order.id).catch(() => {});
    } catch {
        showMessage('error.network', {}, true);
    } finally {
        $('pay').disabled = false;
        renderExperiment();
        // A reCAPTCHA answer is single-use: the next order needs a new one.
        // A replay of this order does not: the idempotency cache answers it
        // before the captcha gate runs.
        if (state.captcha !== null && state.captcha.widget !== null) {
            window.grecaptcha.reset(state.captcha.widget);
        }
    }
}

async function payAgain() {
    if (state.order === null) {
        return;
    }
    $('again').disabled = true;
    try {
        const { response, body } = await postCheckout(state.order);
        if (response.status !== 202) {
            showMessage('error.http', { status: response.status, title: body.title ?? '' }, true);

            return;
        }
        await refresh();
        const jobs = (state.evidence?.outbox ?? []).filter((row) => row.type === 'payment.requested').length;
        showMessage('replay.same', { count: jobs });
    } catch {
        showMessage('error.network', {}, true);
    } finally {
        $('again').disabled = false;
    }
}

function newOrder() {
    stopPolling();
    disconnectLive();
    state.order = null;
    state.evidence = null;
    state.requestId = null;
    state.message = null;
    $('again').hidden = true;
    $('again-note').hidden = true;
    $('new-order').hidden = true;
    history.replaceState(null, '', location.pathname);
    lockForm(false);
    renderMessage();
    render();
}

function lockForm(locked) {
    // While an order is shown, the choice that made it is history: hiding it
    // keeps the form from contradicting the order's own amount.
    $('choose').hidden = locked;
    $('pay').hidden = locked;
}

async function refresh() {
    if (state.order === null) {
        return;
    }
    // One request per refresh: the page's backend (GET /api/v1/ui/orders/{id})
    // answers with the evidence and, while the payment is open, the breaker.
    const response = await fetch('/api/v1/ui/orders/' + encodeURIComponent(state.order.id), {
        headers: { Accept: 'application/json' },
    });
    if (response.ok) {
        const screen = await response.json();
        state.evidence = screen.evidence;
        // Once the order settled the server sends no breaker; the last
        // reading stays on screen.
        if (screen.breaker !== null) {
            state.breaker = screen.breaker;
        }
        render();
        if (isSettled(state.evidence)) {
            stopPolling();
        }
    }
}

// Live updates over Centrifugo's WebSocket. The server issues a connection
// token whose `channels` claim subscribes this page to orders:<id> and nothing
// else. A push only triggers a re-read of the evidence: the page still draws
// from the database, the push just makes it happen at once. Polling stays as
// the fallback when the realtime service is not running.
async function connectLive(orderId) {
    disconnectLive();
    // A token fetch that finishes after the visitor moved on to another order
    // must not open a socket for the old one over the new one's.
    const generation = state.liveGeneration;
    const { response, body } = await UI.json('/api/v1/orders/' + encodeURIComponent(orderId) + '/realtime-token');
    if (!response.ok || generation !== state.liveGeneration) {
        return;
    }
    const scheme = location.protocol === 'https:' ? 'wss://' : 'ws://';
    let socket;
    try {
        socket = new WebSocket(scheme + location.hostname + ':' + body.port + body.path);
    } catch {
        return;
    }
    state.socket = socket;
    socket.addEventListener('open', () => {
        socket.send(JSON.stringify({ id: 1, connect: { token: body.token, name: 'sandbox-ui' } }));
    });
    socket.addEventListener('message', (event) => {
        for (const line of String(event.data).split('\n')) {
            if (line.trim() === '') {
                continue;
            }
            const frame = JSON.parse(line);
            if (Object.keys(frame).length === 0) {
                socket.send('{}');
                continue;
            }
            if (frame.connect) {
                state.live = { connected: true, last: null };
                render();
            }
            const publication = frame.push?.pub;
            if (publication && frame.push.channel === body.channel) {
                state.live = { connected: true, last: publication.data };
                refresh().catch(() => {});
            }
        }
    });
    socket.addEventListener('close', () => {
        if (state.socket === socket) {
            state.socket = null;
            state.live = state.live?.last ? state.live : null;
            render();
        }
    });
}

function disconnectLive() {
    state.liveGeneration++;
    if (state.socket !== null) {
        const socket = state.socket;
        state.socket = null;
        socket.close();
    }
    state.live = null;
}

function renderLive() {
    const node = $('live');
    if (state.live === null) {
        node.hidden = true;

        return;
    }
    node.hidden = false;
    node.textContent = state.live.last === null
        ? t('live.connected')
        : t('live.received', { status: t('place.' + state.live.last.status) });
}

function startPolling() {
    stopPolling();
    state.timer = setInterval(() => refresh().catch(() => {}), POLL_MS);
}

function stopPolling() {
    if (state.timer !== null) {
        clearInterval(state.timer);
        state.timer = null;
    }
}

function isSettled(evidence) {
    const final = ['paid', 'payment_failed'].includes(evidence.order.status);

    const event = resultEvent(evidence);

    // An abandoned saga is still asking the bank: the order may yet be paid.
    const reconciling = evidence.payment?.status === 'abandoned';

    return final && !reconciling && event !== undefined && (event.status === 'published' || event.status === 'failed');
}

function paymentJob(evidence) {
    return evidence.outbox.find((row) => row.type === 'payment.requested');
}

function resultEvent(evidence) {
    // A recovered payment has two results, failed then paid: the latest one counts.
    return evidence.outbox.findLast((row) => row.type === 'order.paid' || row.type === 'order.payment_failed');
}

function deriveSteps(evidence) {
    const status = evidence.order.status;
    const job = paymentJob(evidence);
    const attempts = job?.attempts ?? 0;
    const maxAttempts = evidence.maxAttempts;
    const gaveUp = status === 'payment_failed' && job?.status === 'failed';
    const started = evidence.transitions.some((row) => row.transition === 'start_payment');

    const accepted = { key: 'accepted', state: started ? 'done' : 'active' };

    let queued = { key: 'queued', state: 'waiting' };
    if (job !== undefined) {
        queued = { key: 'queued', state: attempts === 0 && status === 'processing' ? 'active' : 'done' };
    }

    const bank = { key: 'bank', state: 'waiting', extra: [] };
    if (attempts > 0 || status === 'paid' || status === 'payment_failed') {
        bank.extra.push(t('step.bank.attempt', { n: attempts, m: maxAttempts }));
        if (status === 'processing') {
            bank.state = 'active';
            if (job?.status === 'pending') {
                bank.extra.push(t('step.bank.retrying'));
            }
        } else if (gaveUp) {
            bank.state = 'failed';
            bank.extra.push(t('step.bank.gaveUp', { m: maxAttempts }));
        } else {
            bank.state = 'done';
        }
    }

    let result = { key: 'result', state: 'waiting' };
    if (status === 'paid') {
        result = { key: 'paid', state: 'done' };
    } else if (status === 'payment_failed') {
        result = { key: gaveUp ? 'abandoned' : 'declined', state: 'failed' };
    }

    // Delivered means the fan-out published the event to every subscriber.
    const event = resultEvent(evidence);
    const notify = { key: 'notify', state: 'waiting', extra: [] };
    if (event !== undefined) {
        notify.state = { published: 'done', failed: 'failed' }[event.status] ?? 'active';
        if (event.attempts > 1 || (event.status === 'pending' && event.attempts > 0)) {
            notify.extra.push(t('step.notify.attempts', { n: event.attempts }));
        }
    }

    // The payment saga: an unanswered last attempt leaves the outcome unknown,
    // and the shop asks the bank afterwards (reconciliation). Shown only when
    // that happened; the status comes from the evidence, never a timer.
    const steps = [accepted, queued, bank, result];
    const saga = evidence.payment?.status;
    if (saga === 'abandoned' || saga === 'not_charged' || saga === 'recovered') {
        const reconcile = { key: 'reconcile', state: saga === 'abandoned' ? 'active' : 'done', extra: [] };
        if (saga === 'recovered') {
            reconcile.extra.push(t('step.reconcile.recovered'));
        } else if (saga === 'not_charged') {
            reconcile.extra.push(t('step.reconcile.notCharged'));
        }
        steps.push(reconcile);
    }
    steps.push(notify);

    return steps;
}

function renderStep(step) {
    const item = document.createElement('li');
    item.className = 'step ' + step.state;

    const head = document.createElement('div');
    head.className = 'step-head';
    const title = document.createElement('span');
    title.className = 'step-title';
    title.textContent = t('step.' + step.key + '.title');
    const badge = document.createElement('span');
    badge.className = 'badge';
    badge.textContent = t('status.' + step.state);
    head.append(title, badge);

    const text = document.createElement('p');
    text.className = 'step-text';
    text.textContent = t('step.' + step.key + '.text');
    item.append(head, text);

    for (const line of step.extra ?? []) {
        const extra = document.createElement('p');
        extra.className = 'step-extra';
        extra.textContent = line;
        item.append(extra);
    }

    return item;
}

function renderStory() {
    const evidence = state.evidence;
    $('story-empty').hidden = evidence !== null;
    $('story').hidden = evidence === null;
    if (evidence === null) {
        return;
    }

    $('order-title').textContent = t('story.order', { id: evidence.order.id }) + ' · ' + formatAmount(evidence.order.amountCents);
    $('ask-agent').href = 'agent.html?order=' + encodeURIComponent(evidence.order.id);
    $('see-trace').href = 'trace.html?order=' + encodeURIComponent(evidence.order.id);
    $('request-id').hidden = state.requestId === null;
    $('request-id').textContent = state.requestId === null ? '' : t('story.requestId', { id: state.requestId });
    // The receipt appears once the worker stored it (paid orders only).
    const receipt = evidence.receipt;
    $('receipt').hidden = receipt === null;
    if (receipt !== null) {
        const link = UI.el('a', '', t('story.receipt.link', { name: receipt.fileName }));
        link.href = receipt.url;
        link.target = '_blank';
        link.rel = 'noopener';
        $('receipt').replaceChildren(link, document.createTextNode(' ' + t('story.receipt.note', { size: receipt.size })));
    }
    const touch = evidence.attribution[0] ?? null;
    $('attribution').hidden = false;
    $('attribution').textContent = touch === null
        ? t('story.attribution.none')
        : t('story.attribution', { channel: t('utm.channel.' + touch.channel), source: touch.source ?? '—', medium: touch.medium ?? '—', campaign: touch.campaign ?? '—' });
    $('steps').replaceChildren(...deriveSteps(evidence).map(renderStep));
    $('tech-transitions').textContent = JSON.stringify(evidence.transitions, null, 2);
    $('tech-audit').textContent = JSON.stringify(evidence.audit, null, 2);
    $('tech-outbox').textContent = JSON.stringify(evidence.outbox, null, 2);
    renderWorkflow(evidence);
    renderLive();
    renderBreaker();
    renderAudit(evidence);
}

const SVG = 'http://www.w3.org/2000/svg';

function svg(tag, attributes) {
    const node = document.createElementNS(SVG, tag);
    for (const [name, value] of Object.entries(attributes)) {
        node.setAttribute(name, String(value));
    }

    return node;
}

// Lays the graph out by distance from the initial place, so any definition the
// endpoint returns is drawn without a hand-made picture.
function layoutWorkflow(workflow) {
    const depth = Object.fromEntries(workflow.initial.map((place) => [place, 0]));
    let changed = true;
    while (changed) {
        changed = false;
        for (const transition of workflow.transitions) {
            for (const from of transition.from) {
                for (const to of transition.to) {
                    if (depth[from] !== undefined && depth[to] === undefined) {
                        depth[to] = depth[from] + 1;
                        changed = true;
                    }
                }
            }
        }
    }
    const layers = {};
    for (const place of workflow.places) {
        const layer = depth[place] ?? 0;
        (layers[layer] ??= []).push(place);
    }
    const tallest = Math.max(...Object.values(layers).map((places) => places.length));
    const position = {};
    for (const [layer, places] of Object.entries(layers)) {
        places.forEach((place, index) => {
            position[place] = {
                x: 20 + Number(layer) * 310,
                y: 30 + (index + (tallest - places.length) / 2) * 80,
            };
        });
    }

    return { position, width: Object.keys(layers).length * 310 - 120, height: 30 + tallest * 80 };
}

function renderWorkflow(evidence) {
    const box = $('workflow');
    if (state.workflow === null) {
        box.replaceChildren();

        return;
    }
    const workflow = state.workflow;
    const { position, width, height } = layoutWorkflow(workflow);
    const taken = new Set(evidence.transitions.map((row) => row.transition));
    const next = new Set(evidence.nextTransitions);
    const current = evidence.order.status;
    const nodeW = 170;
    const nodeH = 40;

    const root = svg('svg', { viewBox: `0 0 ${width} ${height}`, class: 'workflow', role: 'img' });
    root.append(svg('title', {}));
    root.firstChild.textContent = t('workflow.title');
    const defs = svg('defs', {});
    const marker = svg('marker', { id: 'arrow', viewBox: '0 0 10 10', refX: 9, refY: 5, markerWidth: 7, markerHeight: 7, orient: 'auto-start-reverse' });
    marker.append(svg('path', { d: 'M0,0 L10,5 L0,10 z', fill: 'currentColor' }));
    defs.append(marker);
    root.append(defs);

    for (const transition of workflow.transitions) {
        for (const from of transition.from) {
            for (const to of transition.to) {
                const a = position[from];
                const b = position[to];
                const kind = taken.has(transition.name) ? 'taken' : next.has(transition.name) ? 'next' : 'idle';
                const group = svg('g', { class: 'edge ' + kind });
                group.append(svg('line', {
                    x1: a.x + nodeW, y1: a.y + nodeH / 2, x2: b.x - 4, y2: b.y + nodeH / 2, 'marker-end': 'url(#arrow)',
                }));
                const label = svg('text', { x: (a.x + nodeW + b.x) / 2, y: (a.y + b.y) / 2 + nodeH / 2 - 6, 'text-anchor': 'middle' });
                label.textContent = transition.name;
                group.append(label);
                root.append(group);
            }
        }
    }
    for (const place of workflow.places) {
        const { x, y } = position[place];
        const group = svg('g', { class: 'node' + (place === current ? ' current' : '') });
        group.append(svg('rect', { x, y, width: nodeW, height: nodeH, rx: 8 }));
        const label = svg('text', { x: x + nodeW / 2, y: y + nodeH / 2 + 5, 'text-anchor': 'middle' });
        label.textContent = t('place.' + place);
        group.append(label);
        root.append(group);
    }
    box.replaceChildren(root);
    $('workflow-next').textContent = evidence.nextTransitions.length === 0
        ? t('workflow.final')
        : t('workflow.next', { transitions: evidence.nextTransitions.join(', ') });
}

function renderBreaker() {
    const box = $('breaker');
    const breaker = state.breaker?.breaker;
    if (breaker === undefined) {
        box.hidden = true;

        return;
    }
    box.hidden = false;
    const mode = breaker.state === 'open' && breaker.readyToProbe ? 'probe' : breaker.state;
    $('breaker-lamp').className = 'lamp ' + mode;
    $('breaker-state').textContent = t('breaker.state.' + mode);
    $('breaker-text').textContent = t('breaker.text.' + mode);
}

function renderAudit(evidence) {
    const list = $('audit');
    list.replaceChildren(...evidence.audit.map((row) => {
        const item = UI.el('li');
        const changes = row.changes.map((change) => `${change.field}: ${change.old} → ${change.new}`).join('; ');
        item.append(UI.el('span', 'audit-at', row.at), ' ', UI.el('strong', '', changes || row.action));
        if (row.requestId) {
            item.append(' ', UI.el('span', 'note', t('audit.request', { id: row.requestId })));
        }

        return item;
    }));
}

async function init() {
    $('checkout').addEventListener('submit', pay);
    $('again').addEventListener('click', payAgain);
    $('new-order').addEventListener('click', newOrder);
    state.campaign = storedCampaign();
    captureCampaign();
    $('campaign-clear').addEventListener('click', () => {
        try {
            localStorage.removeItem('sandbox-ui-campaign');
        } catch {
            // Nothing was stored.
        }
        state.campaign = null;
        renderCampaign();
    });
    for (const input of document.querySelectorAll('input[name="card"]')) {
        input.addEventListener('change', renderAmount);
    }

    const workflow = await UI.json('/api/v1/workflows/order');
    state.workflow = workflow.response.ok ? workflow.body : null;

    await loadExperiment();
    await loadCaptcha();
    await UI.start(render);

    // A link to one order's story: ?order=<id>, the address the page itself
    // switches to after a checkout. The replay button needs the original
    // request, so it stays hidden for an order opened this way.
    const shared = new URLSearchParams(location.search).get('order');
    if (shared !== null && shared !== '') {
        state.order = { id: shared, key: null, amountCents: null };
        $('new-order').hidden = false;
        lockForm(true);
        await refresh();
        if (state.evidence === null) {
            newOrder();
        } else {
            startPolling();
            if (!isSettled(state.evidence)) {
                connectLive(shared).catch(() => {});
            }
        }
    }
}

init();
