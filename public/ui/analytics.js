'use strict';

// Payment results as ClickHouse holds them, from GET /api/v1/analytics.
// ClickHouse runs only in `make ui-full`; the page says so otherwise.

const view = { data: null, failed: false };

function render() {
    const { $, t } = UI;
    $('unavailable').hidden = !view.failed;
    $('unavailable').textContent = view.failed ? t('page.unavailable') : '';
    if (view.data === null) {
        return;
    }
    $('campaigns-empty').hidden = view.data.campaigns.length > 0;
    $('campaigns-table').hidden = view.data.campaigns.length === 0;
    $('campaigns').replaceChildren(...view.data.campaigns.map((row) => {
        const tr = UI.el('tr');
        const name = [row.source, row.medium, row.campaign].map((part) => part ?? '—').join(' / ');
        for (const value of [t('utm.channel.' + row.channel), name, String(row.orders), t('analytics.campaigns.paid', { orders: row.paidOrders, amount: UI.money(row.paidAmountCents) })]) {
            tr.append(UI.el('td', '', value));
        }

        return tr;
    }));
    const ab = view.data.experiment;
    $('ab-pending').textContent = view.data.available
        ? (ab.variants.length === 0 ? t('analytics.ab.empty') : t('analytics.ab.pending', { count: ab.pending }))
        : t('analytics.ab.waiting', { count: ab.pending });
    $('ab-table').hidden = ab.variants.length === 0;
    $('ab-variants').replaceChildren(...ab.variants.map((row) => {
        const tr = UI.el('tr');
        const rate = row.visitors === 0 ? '—' : (100 * row.converted / row.visitors).toFixed(1) + ' %';
        for (const value of [row.variant, String(row.visitors), String(row.converted), rate]) {
            tr.append(UI.el('td', '', value));
        }

        return tr;
    }));
    $('grafana-link').hidden = !view.data.grafana;
    $('grafana-off').hidden = view.data.grafana;
    $('off').hidden = view.data.available;
    $('query-error').hidden = view.data.error === null;
    $('query-error').textContent = view.data.error === null ? '' : t('analytics.error', { message: view.data.error });
    $('data').hidden = !view.data.available;
    if (!view.data.available) {
        return;
    }
    $('totals').replaceChildren(...view.data.totals.map((row) => {
        const meter = UI.el('div', 'meter');
        meter.append(UI.el('span', 'meter-value', String(row.orders)), UI.el('span', '', t('place.' + row.status) + ' · ' + UI.money(row.amountCents)));

        return meter;
    }));
    $('excluded').textContent = t('analytics.excluded', { orders: view.data.excluded.orders, amount: UI.money(view.data.excluded.amountCents) });
    $('recent').replaceChildren(...view.data.recent.map((row) => {
        const tr = UI.el('tr');
        for (const value of [row.orderId, t('place.' + row.status), UI.money(row.amountCents), row.occurredAt]) {
            tr.append(UI.el('td', '', value));
        }

        return tr;
    }));
}

async function load() {
    try {
        const { response, body } = await UI.json('/api/v1/analytics');
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
    setInterval(load, 3000);
});
