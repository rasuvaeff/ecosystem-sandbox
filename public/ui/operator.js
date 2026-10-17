'use strict';

// Read-only operator console: GET /api/v1/operator plus GET /health. When
// maintenance mode is on, the API itself answers 503 — the page shows that
// answer instead of an error, because it is exactly what a customer would get.

const view = { operator: null, health: null, problem: null, failed: false };

function row(cells) {
    const tr = UI.el('tr');
    for (const cell of cells) {
        tr.append(cell instanceof Node ? (() => { const td = UI.el('td'); td.append(cell); return td; })() : UI.el('td', '', String(cell)));
    }

    return tr;
}

function badge(ok, yes, no) {
    return UI.el('span', 'badge ' + (ok ? 'ok' : 'bad'), ok ? yes : no);
}

function render() {
    const { $, t } = UI;
    const banner = $('unavailable');
    if (view.problem !== null) {
        banner.hidden = false;
        banner.textContent = t('operator.maintenance.active', { status: view.problem.status, retryAfter: view.problem.retryAfter ?? '—' });
    } else {
        banner.hidden = !view.failed;
        banner.textContent = view.failed ? t('page.unavailable') : '';
    }

    if (view.health !== null) {
        $('health').replaceChildren(...Object.values(view.health.checks ?? {}).map((check) => {
            const item = UI.el('li', 'board-item');
            item.append(badge(check.status === 'pass', t('operator.health.pass'), t('operator.health.fail')), ' ', UI.el('strong', '', t('operator.health.check.' + check.name)));
            item.append(UI.el('span', 'note', ` ${Math.round(check.elapsedMs ?? 0)} ms`));

            return item;
        }));
    }

    if (view.operator === null) {
        return;
    }
    const data = view.operator;
    $('flags').replaceChildren(...data.flags.map((flag) => row([
        flag.name,
        badge(flag.enabled, t('operator.on'), t('operator.off')),
        t('operator.reason.' + flag.reason),
    ])));
    $('maintenance').textContent = data.maintenance.enabled
        ? t('operator.maintenance.on', { retryAfter: data.maintenance.retryAfterSeconds })
        : t('operator.maintenance.off', { retryAfter: data.maintenance.retryAfterSeconds });
    $('settings').replaceChildren(...data.settings.map((setting) => row([
        setting.key,
        setting.secret ? t('operator.secret') : String(setting.value),
        t('operator.setting.' + setting.key),
    ])));
    $('sensitive-keys').textContent = t('operator.masking.keys', { keys: data.auditMasking.sensitiveKeys.join(', ') });
    $('masking').replaceChildren(...data.auditMasking.input.map((change, index) => {
        const masked = data.auditMasking.output[index];

        return row([change.field, `${change.old} → ${change.new}`, `${masked.old} → ${masked.new}`]);
    }));
}

async function load() {
    try {
        const health = await UI.json('/health');
        view.health = health.body;
        const { response, body } = await UI.json('/api/v1/operator');
        view.failed = false;
        view.problem = null;
        if (response.status === 503) {
            view.problem = { status: 503, retryAfter: response.headers.get('Retry-After') };
        } else if (response.ok) {
            view.operator = body;
        } else {
            view.failed = true;
        }
    } catch {
        view.failed = true;
    }
    render();
}

UI.start(render).then(() => {
    load();
    setInterval(load, 5000);
});
