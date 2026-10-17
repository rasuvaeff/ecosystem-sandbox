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
            <h1 data-i18n="webhooks.title"></h1>
            <p class="lead" data-i18n="webhooks.lead"></p>
        </div>
        <button type="button" class="link-button" id="lang" data-i18n="lang.switch"></button>
    </header>
    <nav class="nav" id="nav" aria-label="Sections"></nav>
    <p class="message error" id="unavailable" hidden></p>

    <section class="panel">
        <h2 data-i18n="webhooks.how.title"></h2>
        <p data-i18n="webhooks.how.text"></p>
        <ul class="glossary">
            <li data-i18n="webhooks.how.sign"></li>
            <li data-i18n="webhooks.how.retry"></li>
            <li data-i18n="webhooks.how.dedupe"></li>
        </ul>
    </section>

    <section class="panel">
        <h2 data-i18n="webhooks.sent.title"></h2>
        <p class="note" data-i18n="webhooks.sent.text"></p>
        <p class="note" data-i18n="generated.note"></p>
        <table class="table">
            <thead><tr><th data-i18n="webhooks.col.event"></th><th data-i18n="webhooks.col.type"></th><th data-i18n="webhooks.col.status"></th><th data-i18n="webhooks.col.error"></th><th data-i18n="webhooks.col.at"></th></tr></thead>
            <tbody id="sent"></tbody>
        </table>
        <p class="note" id="sent-empty" data-i18n="webhooks.empty"></p>
    </section>

    <section class="panel">
        <h2 data-i18n="webhooks.received.title"></h2>
        <p class="note" data-i18n="webhooks.received.text"></p>
        <table class="table">
            <thead><tr><th data-i18n="webhooks.col.event"></th><th data-i18n="webhooks.col.type"></th><th data-i18n="webhooks.col.answer"></th><th data-i18n="webhooks.col.signature"></th><th data-i18n="webhooks.col.at"></th></tr></thead>
            <tbody id="received"></tbody>
        </table>
        <p class="note" id="received-empty" hidden></p>
    </section>
</main>
