'use strict';

// Both ends of a webhook, from GET /api/v1/webhooks: the sender's attempt log
// and the receiver's own journal, refreshed every two seconds.

const view = { data: null, failed: false };

function cells(values) {
    const tr = UI.el('tr');
    for (const value of values) {
        const td = UI.el('td');
        td.append(value instanceof Node ? value : document.createTextNode(String(value ?? '—')));
        tr.append(td);
    }

    return tr;
}

function shortId(id) {
    return id.slice(0, 10) + '…';
}

function render() {
    const { $, t } = UI;
    $('unavailable').hidden = !view.failed;
    $('unavailable').textContent = view.failed ? t('page.unavailable') : '';
    if (view.data === null) {
        return;
    }
    const { deliveries, received } = view.data;
    $('sent').replaceChildren(...deliveries.map((row) => cells([
        shortId(row.eventId),
        row.type,
        UI.el('span', 'badge ' + (row.status === 'delivered' ? 'ok' : 'bad'), t('webhooks.status.' + row.status)),
        row.lastError,
        row.createdAt,
    ])));
    $('sent-empty').hidden = deliveries.length > 0;

    const empty = $('received-empty');
    if (received === null) {
        $('received').replaceChildren();
        empty.hidden = false;
        empty.textContent = t('webhooks.receiverDown');

        return;
    }
    $('received').replaceChildren(...received.map((row) => cells([
        shortId(row.eventId),
        row.type,
        UI.el('span', 'badge ' + (row.status < 300 ? 'ok' : 'bad'), String(row.status)),
        UI.el('span', 'badge ' + (row.signatureValid ? 'ok' : 'bad'), row.signatureValid ? t('webhooks.signature.ok') : t('webhooks.signature.bad')),
        row.at,
    ])));
    empty.hidden = received.length > 0;
    empty.textContent = t('webhooks.empty');
}

async function load() {
    try {
        const { response, body } = await UI.json('/api/v1/webhooks');
        view.failed = !response.ok;
        if (response.ok) {
            view.data = body;
        }
    } catch {
        view.failed = true;
    }
    render();
}

UI.start(render).then(() => {
    load();
    setInterval(load, 2000);
});
