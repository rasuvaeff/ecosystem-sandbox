'use strict';

// How the shop tests itself, from GET /api/v1/ui/tests: the reports the last
// `make testo`, `make property-test` and `make mutation` left under build/.

const view = { data: null, failed: false };
const LAYERS = ['Domain', 'Application', 'Infrastructure', 'Architecture', 'Other'];

// `aFoundChargePaysTheOrderOnce` reads as "a found charge pays the order once".
function sentence(name) {
    return name.replace(/([a-z0-9])([A-Z])/g, '$1 $2').toLowerCase();
}

function when(iso) {
    return new Date(iso).toLocaleString(UI.lang === 'ru' ? 'ru-RU' : 'en-GB');
}

function meter(value, label) {
    const node = UI.el('div', 'meter');
    node.append(UI.el('span', 'meter-value', value), UI.el('span', '', label));

    return node;
}

function share(label, hits, runs, tone, title) {
    const row = UI.el('div', 'share');
    if (title !== undefined) {
        row.title = title;
    }
    const bar = UI.el('div', 'share-bar');
    const fill = UI.el('span', tone);
    fill.style.width = (runs === 0 ? 0 : Math.round(100 * hits / runs)) + '%';
    bar.append(fill);
    row.append(UI.el('span', '', label), bar, UI.el('span', '', hits + ' / ' + runs));

    return row;
}

function renderSummary(data) {
    const { $, t } = UI;
    const items = [];
    const suites = [data.unit, data.property].filter((suite) => suite !== null);
    if (suites.length > 0) {
        const tests = suites.reduce((sum, suite) => sum + suite.tests, 0);
        const failures = suites.reduce((sum, suite) => sum + suite.failures, 0);
        items.push(meter(String(tests), t('tests.summary.tests', { failures })));
        // Only the property tests that classify their inputs report how many they generated.
        let generated = 0;
        for (const suite of suites) {
            for (const item of suite.classes) {
                for (const one of item.cases) {
                    generated += one.distribution.length > 0 ? one.distribution[0].runs : 0;
                }
            }
        }
        if (generated > 0) {
            items.push(meter(String(generated), t('tests.summary.generated')));
        }
    }
    if (data.mutation !== null) {
        items.push(meter(data.mutation.caught + ' / ' + data.mutation.total, t('tests.summary.mutation', { msi: data.mutation.msi })));
    }
    $('summary').replaceChildren(...items);
}

function renderLayers(unit) {
    const { $, t } = UI;
    $('unit-empty').hidden = unit !== null;
    if (unit === null) {
        $('layers').replaceChildren();

        return;
    }
    const blocks = [];
    for (const layer of LAYERS) {
        const classes = unit.classes.filter((item) => item.layer === layer);
        if (classes.length === 0) {
            continue;
        }
        const block = UI.el('div', 'layer');
        const count = classes.reduce((sum, item) => sum + item.cases.length, 0);
        block.append(UI.el('h3', '', t('tests.layer.' + layer) + ' · ' + t('tests.count', { n: count })), UI.el('p', '', t('tests.layer.' + layer + '.text')));
        for (const item of classes) {
            const details = UI.el('details', 'test-class');
            const summary = UI.el('summary');
            const failed = item.cases.filter((one) => one.status === 'failed').length;
            summary.append(UI.el('span', '', item.name), UI.el('span', 'badge ' + (failed === 0 ? 'ok' : 'bad'), failed === 0 ? t('tests.passed', { n: item.cases.length }) : t('tests.failed', { n: failed })));
            const list = UI.el('ul');
            for (const one of item.cases) {
                list.append(UI.el('li', one.status === 'failed' ? 'diff-del' : '', sentence(one.name)));
            }
            details.append(summary, list);
            block.append(details);
        }
        blocks.push(block);
    }
    $('layers').replaceChildren(...blocks);
}

function renderProperties(data) {
    const { $, t } = UI;
    const suites = [data.unit, data.property].filter((suite) => suite !== null);
    $('property-empty').hidden = suites.length > 0;
    const blocks = [];
    for (const suite of suites) {
        for (const item of suite.classes) {
            for (const one of item.cases) {
                if (one.distribution.length === 0) {
                    continue;
                }
                const block = UI.el('div', 'property');
                block.append(UI.el('strong', '', sentence(one.name)), UI.el('p', 'note', t('tests.property.runs', { runs: one.distribution[0].runs, test: item.name })));
                for (const part of one.distribution) {
                    block.append(share(part.label, part.hits, part.runs, ''));
                }
                blocks.push(block);
            }
        }
    }
    $('properties').replaceChildren(...blocks);
}

function renderMutation(mutation) {
    const { $, t } = UI;
    $('mutation-empty').hidden = mutation !== null;
    if (mutation === null) {
        $('mutation').replaceChildren();

        return;
    }
    const nodes = [UI.el('p', '', t('tests.mutation.result', { total: mutation.total, caught: mutation.caught, missed: mutation.missed, at: when(mutation.ranAt) }))];
    if (mutation.minMsi !== null) {
        nodes.push(UI.el('p', 'note', t('tests.mutation.floor', { min: mutation.minMsi })));
    }

    nodes.push(UI.el('h3', '', t('tests.mutation.files')));
    for (const file of mutation.files.slice(0, 12)) {
        nodes.push(share(file.file.split('/').pop(), file.caught, file.caught + file.missed, file.missed === 0 ? 'ok' : 'bad', file.file));
    }

    if (mutation.examples.length > 0) {
        nodes.push(UI.el('h3', '', t('tests.mutation.examples')), UI.el('p', 'note', t('tests.mutation.examples.text')));
        for (const example of mutation.examples) {
            const box = UI.el('div', 'mutant');
            box.append(UI.el('strong', '', example.file.replace(/^src\//, '') + ':' + example.line), UI.el('span', 'note', ' · ' + example.mutator));
            const pre = UI.el('pre');
            for (const line of example.diff.split('\n')) {
                pre.append(UI.el('span', line.startsWith('-') ? 'diff-del' : (line.startsWith('+') ? 'diff-add' : ''), line + '\n'));
            }
            box.append(pre, UI.el('p', '', t('tests.mutation.caughtBy', { tests: example.caughtBy.join(', ') })));
            nodes.push(box);
        }
    }

    if (mutation.trend.length > 1) {
        nodes.push(UI.el('h3', '', t('tests.mutation.trend')));
        for (const point of mutation.trend.slice(-10)) {
            nodes.push(share(point.run, Math.round(point.msi), 100, point.msi === 100 ? 'ok' : 'bad'));
        }
    }
    $('mutation').replaceChildren(...nodes);
}

function render() {
    const { $, t } = UI;
    $('unavailable').hidden = !view.failed;
    $('unavailable').textContent = view.failed ? t('page.unavailable') : '';
    if (view.data === null) {
        return;
    }
    renderSummary(view.data);
    renderLayers(view.data.unit);
    renderProperties(view.data);
    renderMutation(view.data.mutation);
}

async function load() {
    try {
        const { response, body } = await UI.json('/api/v1/ui/tests');
        view.failed = !response.ok;
        view.data = response.ok ? body : view.data;
    } catch {
        view.failed = true;
    }
    render();
}

UI.start(render).then(load);
