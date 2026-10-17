'use strict';

// Requests generated from the OpenAPI document (/openapi.json) by property-testing-openapi, sent from
// the browser to the real API. A valid case must be accepted (2xx), a broken
// one refused (4xx); anything else is a disagreement between the document and
// the server, and the page says so instead of hiding it.

const view = { seed: null, rows: [], running: false, failed: false };

function describe(misuse) {
    if (misuse === null) {
        return UI.t('contract.valid');
    }

    return UI.t('contract.misuse.' + misuse.kind, { name: misuse.name, location: UI.t('contract.location.' + misuse.location) });
}

function verdict(row) {
    if (row.status === null) {
        return 'pending';
    }
    const accepted = row.status >= 200 && row.status < 300;
    const refused = row.status >= 400 && row.status < 500;

    return (row.case.kind === 'valid' ? accepted : refused) ? 'agree' : 'disagree';
}

function render() {
    const { $, t } = UI;
    $('unavailable').hidden = !view.failed;
    $('unavailable').textContent = view.failed ? t('page.unavailable') : '';
    $('run').disabled = view.running;
    $('results-panel').hidden = view.rows.length === 0;
    $('seed').hidden = view.seed === null;
    $('seed').textContent = view.seed === null ? '' : t('contract.seed', { seed: view.seed });
    const done = view.rows.filter((row) => row.status !== null);
    $('summary').hidden = done.length === 0;
    $('summary').textContent = t('contract.summary', {
        total: done.length,
        agree: done.filter((row) => verdict(row) === 'agree').length,
        disagree: done.filter((row) => verdict(row) === 'disagree').length,
    });
    $('results').replaceChildren(...view.rows.map((row) => {
        const tr = UI.el('tr');
        const v = verdict(row);
        tr.append(
            UI.el('td', '', t('contract.kind.' + row.case.kind)),
            UI.el('td', '', describe(row.case.misuse)),
            UI.el('td', '', row.case.kind === 'valid' ? t('contract.expect.accept') : t('contract.expect.refuse')),
            UI.el('td', '', row.status === null ? '…' : String(row.status)),
        );
        const cell = UI.el('td');
        cell.append(UI.el('span', 'badge ' + (v === 'agree' ? 'ok' : v === 'disagree' ? 'bad' : ''), t('contract.verdict.' + v)));
        tr.append(cell);

        return tr;
    }));
}

async function run() {
    view.running = true;
    view.rows = [];
    render();
    try {
        const { response, body } = await UI.json('/api/v1/contract/checkout-cases');
        view.failed = !response.ok;
        if (!response.ok) {
            return;
        }
        view.seed = body.seed;
        view.rows = body.cases.map((item) => ({ case: item, status: null }));
        render();
        for (const row of view.rows) {
            try {
                const answer = await fetch(row.case.path, {
                    method: row.case.method,
                    headers: row.case.headers,
                    body: row.case.method === 'GET' ? undefined : row.case.body,
                });
                row.status = answer.status;
            } catch {
                row.status = 0;
            }
            render();
        }
    } catch {
        view.failed = true;
    } finally {
        view.running = false;
        render();
    }
}

UI.start(render).then(() => {
    UI.$('run').addEventListener('click', run);
});
