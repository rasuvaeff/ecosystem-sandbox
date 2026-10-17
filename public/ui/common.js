'use strict';

// Shared by every page of the UI: the RU/EN dictionary, the language switch,
// the navigation bar and a JSON fetch helper. Each page script calls
// UI.start(render) and redraws itself from its own state in render().

const PAGES = [
    { href: 'index.html', key: 'nav.order' },
    { href: 'resilience.html', key: 'nav.resilience' },
    { href: 'trace.html', key: 'nav.trace' },
    { href: 'operator.html', key: 'nav.operator' },
    { href: 'contract.html', key: 'nav.contract' },
    { href: 'agent.html', key: 'nav.agent' },
    { href: 'webhooks.html', key: 'nav.webhooks' },
    { href: 'analytics.html', key: 'nav.analytics' },
    { href: 'tests.html', key: 'nav.tests' },
];

const UI = {
    lang: 'ru',
    dict: {},
    renderers: [],
    // Host ports of the side services the pages link to; compose may publish
    // them anywhere. The WebSocket address comes with the realtime token.
    ports: { jaeger: 16686, prometheus: 9090, grafana: 13000 },

    t(key, params = {}) {
        const text = UI.dict[UI.lang]?.[key] ?? key;

        return text.replace(/\{(\w+)\}/g, (_, name) => String(params[name] ?? ''));
    },

    $(id) {
        return document.getElementById(id);
    },

    locale() {
        return UI.lang === 'ru' ? 'ru-RU' : 'en-US';
    },

    money(cents) {
        return new Intl.NumberFormat(UI.locale(), { style: 'currency', currency: 'USD' }).format(cents / 100);
    },

    async json(url, options = {}) {
        const response = await fetch(url, { headers: { Accept: 'application/json' }, ...options });
        const body = await response.json().catch(() => null);

        return { response, body };
    },

    el(tag, className, text) {
        const node = document.createElement(tag);
        if (className) {
            node.className = className;
        }
        if (text !== undefined) {
            node.textContent = text;
        }

        return node;
    },

    storedLang() {
        try {
            return localStorage.getItem('sandbox-ui-lang');
        } catch {
            return null;
        }
    },

    storeLang(lang) {
        try {
            localStorage.setItem('sandbox-ui-lang', lang);
        } catch {
            // Private mode or blocked storage: the choice simply is not remembered.
        }
    },

    rememberOrder(id) {
        try {
            localStorage.setItem('sandbox-ui-last-order', id);
        } catch {
            // Not remembered; other pages simply start empty.
        }
    },

    lastOrder() {
        try {
            return localStorage.getItem('sandbox-ui-last-order');
        } catch {
            return null;
        }
    },

    serviceUrl(service, path = '/') {
        return location.protocol + '//' + location.hostname + ':' + UI.ports[service] + path;
    },

    renderNav() {
        const nav = UI.$('nav');
        if (nav === null) {
            return;
        }
        const current = location.pathname.split('/').pop() || 'index.html';
        const links = PAGES.map((page) => {
            const link = UI.el('a', page.href === current ? 'current' : '', UI.t(page.key));
            link.href = page.href;
            if (page.href === current) {
                link.setAttribute('aria-current', 'page');
            }

            return link;
        });
        nav.replaceChildren(...links);
    },

    apply() {
        document.documentElement.lang = UI.lang;
        const titleKey = document.body.dataset.titleKey ?? 'title';
        document.title = UI.t(titleKey);
        for (const node of document.querySelectorAll('[data-i18n]')) {
            node.textContent = UI.t(node.dataset.i18n);
        }
        for (const link of document.querySelectorAll('a[data-service]')) {
            link.href = UI.serviceUrl(link.dataset.service, link.dataset.path ?? '/');
        }
        UI.renderNav();
        for (const render of UI.renderers) {
            render();
        }
    },

    async start(render) {
        if (render) {
            UI.renderers.push(render);
        }
        const [dict, ports] = await Promise.all([
            fetch('i18n.json').then((response) => response.json()),
            // Served by the built-in server only; elsewhere the defaults stand.
            fetch('ports.json').then((response) => response.json()).catch(() => ({})),
        ]);
        UI.dict = dict;
        UI.ports = { ...UI.ports, ...ports };
        const preferred = UI.storedLang() ?? (navigator.language.startsWith('ru') ? 'ru' : 'en');
        UI.lang = preferred in UI.dict ? preferred : 'en';
        UI.$('lang')?.addEventListener('click', () => {
            UI.lang = UI.lang === 'ru' ? 'en' : 'ru';
            UI.storeLang(UI.lang);
            UI.apply();
        });
        UI.apply();
    },
};
