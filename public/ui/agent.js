'use strict';

// An AI agent's conversation with the shop over MCP, from
// GET /api/v1/agent/transcript: two identities ask the same questions, and
// only the one with permissions sees the tools at all.

const view = { data: null, failed: false, loading: false, answeredAt: null };

function bubble(side, title, body) {
    const node = UI.el('div', 'bubble ' + side);
    node.append(UI.el('strong', '', title));
    if (body !== undefined) {
        node.append(UI.el('pre', '', typeof body === 'string' ? body : JSON.stringify(body, null, 2)));
    }

    return node;
}

function render() {
    const { $, t } = UI;
    // The address the page itself was opened on; the secret is never shown.
    $('mcp-command').textContent = 'claude mcp add --transport http sandbox ' + location.origin + '/mcp --header "X-Mcp-Secret: <MCP_ENDPOINT_SECRET>"';
    $('unavailable').hidden = !view.failed;
    $('unavailable').textContent = view.failed ? t('page.unavailable') : '';
    // Asking the same order twice draws the same transcript, so the button
    // has to show on its own that it asked: busy while waiting, then when.
    $('ask-button').disabled = view.loading;
    $('ask-button').textContent = t(view.loading ? 'agent.asking' : 'agent.ask');
    $('asked').textContent = view.loading
        ? t('agent.asking.status')
        : (view.answeredAt === null || view.data === null ? '' : t('agent.answered', { order: view.data.orderId, time: view.answeredAt }));
    if (view.loading || view.data === null) {
        $('agents').replaceChildren();

        return;
    }
    $('agents').replaceChildren(...view.data.identities.map((identity) => {
        const panel = UI.el('section', 'panel');
        const role = identity.permitted ? 'permitted' : 'limited-user';
        panel.append(UI.el('h2', '', t('agent.identity.' + role, { user: identity.user })), UI.el('p', 'note', t('agent.identity.' + role + '.text')));
        for (const step of identity.steps) {
            const question = step.call === 'tools/list'
                ? t('agent.q.list')
                : t('agent.q.call', { tool: step.call, args: step.arguments && Object.keys(step.arguments).length ? JSON.stringify(step.arguments) : '' });
            panel.append(bubble('agent', '🤖 ' + question));
            if (step.call === 'tools/list') {
                panel.append(bubble('server', step.result.length === 0 ? t('agent.a.none') : t('agent.a.list', { count: step.result.length }), step.result.length === 0 ? undefined : step.result.map((tool) => tool.name).join('\n')));
            } else if (step.refused) {
                panel.append(bubble('server refused', t('agent.a.refused'), step.result));
            } else if (step.error) {
                panel.append(bubble('server refused', t('agent.a.error'), step.result));
            } else {
                panel.append(bubble('server', t('agent.a.result'), step.result));
            }
        }

        // What yii3-mcp-audit-log-bridge recorded for these calls, read back
        // from the audit table: a refused agent never reached a tool.
        panel.append(UI.el('h3', '', t('agent.audit.title')));
        if (identity.audit.length === 0) {
            panel.append(UI.el('p', 'note', t('agent.audit.none')));
        } else {
            const list = UI.el('ul', 'audit-list');
            for (const row of identity.audit) {
                list.append(UI.el('li', '', t('agent.audit.row', {
                    at: row.at,
                    tool: row.tool,
                    outcome: row.outcome,
                    duration: row.durationMs,
                    args: Object.keys(row.arguments).length ? JSON.stringify(row.arguments) : '',
                })));
            }
            panel.append(list);
        }

        return panel;
    }));
}

async function ask(event) {
    event?.preventDefault();
    const orderId = UI.$('order-id').value.trim();
    if (orderId === '' || view.loading) {
        return;
    }
    view.loading = true;
    render();
    try {
        const { response, body } = await UI.json('/api/v1/agent/transcript?orderId=' + encodeURIComponent(orderId));
        view.failed = !response.ok;
        if (response.ok) {
            view.data = body;
        }
    } catch {
        view.failed = true;
    }
    view.loading = false;
    view.answeredAt = new Date().toLocaleTimeString();
    render();
    if (event !== undefined) {
        UI.$('asked').scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }
}

// The newest order placed from the shop page, by anyone: the agent page opened
// from the menu in a fresh browser still has an order to ask about.
async function latestShopOrder() {
    try {
        const { response, body } = await UI.json('/api/v1/orders?customerId=ui-visitor&sort=recent&limit=1');

        return response.ok && body.items.length > 0 ? body.items[0].id : null;
    } catch {
        return null;
    }
}

UI.start(render).then(async () => {
    UI.$('ask').addEventListener('submit', ask);
    // Start from the order named in the link, the one this browser placed
    // last, or the newest one from the shop page.
    const preset = new URLSearchParams(location.search).get('order') ?? UI.lastOrder() ?? await latestShopOrder();
    if (preset !== null && preset !== '') {
        UI.$('order-id').value = preset;
        await ask();
    }
});
