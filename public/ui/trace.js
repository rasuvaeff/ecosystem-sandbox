'use strict';

// One order's trace as Jaeger holds it (GET /api/v1/orders/{id}/trace): every
// bar is a span the application actually reported, laid out on one time axis.

const view = { data: null, failed: false, missing: false, timer: null, tight: null };

function render() {
    const { $, t } = UI;
    $('unavailable').hidden = !view.failed;
    $('unavailable').textContent = view.failed ? t('page.unavailable') : '';
    const stateNode = $('trace-state');
    stateNode.hidden = !(view.missing || (view.data !== null && view.data.spans.length === 0));
    stateNode.textContent = view.missing ? t('trace.missing') : t('trace.pending');
    const ready = view.data !== null && view.data.spans.length > 0;
    $('trace-panel').hidden = !ready;
    if (!ready) {
        return;
    }
    const spans = view.data.spans;
    const total = Math.max(...spans.map((span) => span.startMs + span.durationMs), 1);
    $('waterfall').replaceChildren(...spans.map((span) => {
        const row = UI.el('div', 'wf-row');
        const label = UI.el('div', 'wf-label');
        label.style.paddingLeft = (span.depth * 14) + 'px';
        label.append(UI.el('strong', '', t('trace.kind.' + span.kind)), UI.el('span', 'note', ' ' + span.name + ' · ' + span.service));
        label.append(UI.el('div', 'note', t('trace.kind.' + span.kind + '.text')));
        const track = UI.el('div', 'wf-track');
        const bar = UI.el('div', 'wf-bar ' + span.kind);
        bar.style.left = (span.startMs / total * 100) + '%';
        bar.style.width = Math.max(span.durationMs / total * 100, 0.8) + '%';
        bar.title = Object.entries(span.attributes).map(([key, value]) => key + '=' + value).join('\n');
        track.append(bar);
        row.append(label, track, UI.el('div', 'wf-time', span.durationMs + ' ms'));

        return row;
    }));
    const server = spans.find((span) => span.kind === 'server');
    const consumer = spans.find((span) => span.kind === 'consumer');
    $('queue-wait').hidden = !(server && consumer);
    if (server && consumer) {
        $('queue-wait').textContent = t('trace.queueWait', { ms: Math.max(0, Math.round(consumer.startMs - server.startMs - server.durationMs)) });
    }
    $('jaeger-link').href = UI.serviceUrl('jaeger', '/trace/' + view.data.traceId);
    renderBudget();
}

// The request budget (rasuvaeff/context-http) this very page call ran under,
// and the same call asked with a budget too small to reach Jaeger.
function renderBudget() {
    const { $, t } = UI;
    const budget = view.data?.budget ?? null;
    $('budget-panel').hidden = budget === null;
    if (budget === null) {
        return;
    }
    $('budget-used').textContent = t('budget.used', { limit: budget.limitMs, left: budget.remainingMs, spent: budget.limitMs - budget.remainingMs });
    $('budget-result').hidden = view.tight === null;
    $('budget-result').className = view.tight === 504 ? 'message' : 'message error';
    $('budget-result').textContent = view.tight === null ? '' : t(view.tight === 504 ? 'budget.expired' : 'budget.unexpected', { status: view.tight });
}

async function askTight() {
    const orderId = UI.$('order-id').value.trim();
    try {
        const response = await fetch('/api/v1/orders/' + encodeURIComponent(orderId) + '/trace', {
            headers: { Accept: 'application/json', [view.data.budget.header]: '1' },
        });
        view.tight = response.status;
    } catch {
        view.tight = 0;
    }
    renderBudget();
}

async function load(orderId) {
    try {
        const { response, body } = await UI.json('/api/v1/orders/' + encodeURIComponent(orderId) + '/trace');
        view.failed = response.status >= 500;
        view.missing = response.status === 404;
        view.data = response.ok ? body : null;
    } catch {
        view.failed = true;
    }
    render();
    // Spans arrive in Jaeger a few seconds after the work; keep asking until
    // the worker's half is there too.
    const consumerSeen = view.data?.spans.some((span) => span.kind === 'consumer');
    clearTimeout(view.timer);
    if (view.data !== null && !consumerSeen) {
        view.timer = setTimeout(() => load(orderId), 2000);
    }
}

UI.start(render).then(() => {
    UI.$('budget-tight').addEventListener('click', askTight);
    UI.$('pick').addEventListener('submit', (event) => {
        event.preventDefault();
        load(UI.$('order-id').value.trim());
    });
    const preset = new URLSearchParams(location.search).get('order') ?? UI.lastOrder();
    if (preset !== null && preset !== '') {
        UI.$('order-id').value = preset;
        load(preset);
    }
});
