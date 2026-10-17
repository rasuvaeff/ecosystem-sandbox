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
            <h1 data-i18n="operator.title"></h1>
            <p class="lead" data-i18n="operator.lead"></p>
        </div>
        <button type="button" class="link-button" id="lang" data-i18n="lang.switch"></button>
    </header>
    <nav class="nav" id="nav" aria-label="Sections"></nav>
    <p class="message error" id="unavailable" hidden></p>

    <section class="panel">
        <h2 data-i18n="operator.health.title"></h2>
        <p data-i18n="operator.health.text"></p>
        <ul class="board" id="health"></ul>
    </section>

    <section class="panel">
        <h2 data-i18n="operator.flags.title"></h2>
        <p data-i18n="operator.flags.text"></p>
        <table class="table"><tbody id="flags"></tbody></table>
        <p><a href="/admin/flags" data-i18n="operator.flags.admin"></a></p>
    </section>

    <section class="panel">
        <h2 data-i18n="operator.maintenance.title"></h2>
        <p data-i18n="operator.maintenance.text"></p>
        <p class="step-extra" id="maintenance"></p>
        <pre>MAINTENANCE_ENABLED=true make ui</pre>
    </section>

    <section class="panel">
        <h2 data-i18n="operator.settings.title"></h2>
        <p data-i18n="operator.settings.text"></p>
        <table class="table"><tbody id="settings"></tbody></table>
        <p><a href="/admin/settings" data-i18n="operator.settings.admin"></a></p>
    </section>

    <section class="panel">
        <h2 data-i18n="operator.masking.title"></h2>
        <p data-i18n="operator.masking.text"></p>
        <p class="note" id="sensitive-keys"></p>
        <table class="table">
            <thead><tr><th data-i18n="operator.masking.field"></th><th data-i18n="operator.masking.input"></th><th data-i18n="operator.masking.output"></th></tr></thead>
            <tbody id="masking"></tbody>
        </table>
        <p class="note" data-i18n="operator.masking.note"></p>
    </section>

    <section class="panel">
        <h2 data-i18n="operator.how.title"></h2>
        <p data-i18n="operator.how.text"></p>
    </section>
</main>
