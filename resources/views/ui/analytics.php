<?php

declare(strict_types=1);

use App\Endpoint\Web\Ui\UiPage;
use Yiisoft\View\WebView;

/**
 * @var WebView $this
 * @var UiPage $page
 */

// The layout renders the head and the scripts of this page.
$this->setParameter('page', $page);
?>
<main class="page" data-ui-root>
    <header class="masthead">
        <div>
            <h1 data-i18n="analytics.title"></h1>
            <p class="lead" data-i18n="analytics.lead"></p>
        </div>
        <button type="button" class="link-button" id="lang" data-i18n="lang.switch"></button>
    </header>
    <nav class="nav" id="nav" aria-label="Sections"></nav>
    <p class="message error" id="unavailable" hidden></p>

    <section class="panel">
        <h2 data-i18n="analytics.why.title"></h2>
        <p data-i18n="analytics.why.text"></p>
        <p class="message" id="off" hidden data-i18n="analytics.off"></p>
        <p class="message error" id="query-error" hidden></p>
    </section>

    <section class="panel">
        <h2 data-i18n="analytics.campaigns.title"></h2>
        <p class="note" data-i18n="analytics.campaigns.text"></p>
        <p class="note" id="campaigns-empty" hidden data-i18n="analytics.campaigns.empty"></p>
        <table class="table" id="campaigns-table" hidden>
            <thead><tr><th data-i18n="analytics.campaigns.col.channel"></th><th data-i18n="analytics.campaigns.col.campaign"></th><th data-i18n="analytics.campaigns.col.orders"></th><th data-i18n="analytics.campaigns.col.paid"></th></tr></thead>
            <tbody id="campaigns"></tbody>
        </table>
    </section>

    <section class="panel">
        <h2 data-i18n="analytics.ab.title"></h2>
        <p class="note" data-i18n="analytics.ab.text"></p>
        <p class="note" id="ab-pending"></p>
        <table class="table" id="ab-table" hidden>
            <thead><tr><th data-i18n="analytics.ab.col.variant"></th><th data-i18n="analytics.ab.col.visitors"></th><th data-i18n="analytics.ab.col.converted"></th><th data-i18n="analytics.ab.col.rate"></th></tr></thead>
            <tbody id="ab-variants"></tbody>
        </table>
    </section>

    <section class="panel" id="data" hidden>
        <h2 data-i18n="analytics.totals.title"></h2>
        <div class="meters" id="totals"></div>
        <p class="note" id="excluded"></p>
        <h3 data-i18n="analytics.recent.title"></h3>
        <table class="table">
            <thead><tr><th data-i18n="analytics.col.order"></th><th data-i18n="analytics.col.status"></th><th data-i18n="analytics.col.amount"></th><th data-i18n="analytics.col.at"></th></tr></thead>
            <tbody id="recent"></tbody>
        </table>
    </section>

    <section class="panel">
        <h2 data-i18n="analytics.tools.title"></h2>
        <p data-i18n="analytics.tools.text"></p>
        <ul class="tools">
            <li id="grafana-link"><a data-service="grafana" data-path="/d/rasuvaeff-sandbox" href="#" data-i18n="analytics.grafana"></a></li>
            <li id="grafana-off" hidden data-i18n="analytics.grafanaOff"></li>
            <li><a data-service="prometheus" href="#" data-i18n="tools.prometheus"></a></li>
        </ul>
    </section>
</main>
