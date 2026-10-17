'use strict';

// Live state of the payment gateway's breaker and bulkhead, polled every
// second from GET /api/v1/resilience/payment-gateway — the same Redis state
// the payment worker reads.

const view = { data: null, failed: false };

function render() {
    const { $, t } = UI;
    $('unavailable').hidden = !view.failed;
    $('unavailable').textContent = view.failed ? t('page.unavailable') : '';
    if (view.data === null) {
        return;
    }
    const { breaker, bulkhead, now } = view.data;
    const mode = breaker.state === 'open' && breaker.readyToProbe ? 'probe' : breaker.state;
    $('lamp').className = 'lamp ' + mode;
    $('state').textContent = t('breaker.state.' + mode);
    $('state-text').textContent = t('breaker.text.' + mode);

    const countdown = $('countdown');
    if (mode === 'open' && breaker.halfOpenAt !== null) {
        const seconds = Math.max(0, Math.ceil((Date.parse(breaker.halfOpenAt) - Date.parse(now)) / 1000));
        countdown.hidden = false;
        countdown.textContent = t('resilience.countdown', { seconds });
    } else {
        countdown.hidden = true;
    }

    $('threshold').textContent = t('resilience.threshold', {
        failures: breaker.failureThreshold.failures,
        window: breaker.failureThreshold.window,
        within: breaker.failureThreshold.withinSeconds,
        cooldown: breaker.cooldownSeconds,
    });
    $('failures').textContent = breaker.failures;
    $('successes').textContent = breaker.successes;
    $('rejected').textContent = breaker.rejected;

    const slots = [];
    for (let index = 0; index < bulkhead.maxConcurrent; index++) {
        slots.push(UI.el('span', index < bulkhead.inUse ? 'slot busy' : 'slot'));
    }
    $('slots').replaceChildren(...slots);
    $('slots-text').textContent = t('resilience.slots', { inUse: bulkhead.inUse, max: bulkhead.maxConcurrent });
}

async function poll() {
    try {
        const { response, body } = await UI.json('/api/v1/resilience/payment-gateway');
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
    poll();
    setInterval(poll, 1000);
});
