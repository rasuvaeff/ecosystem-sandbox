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
            <h1 data-i18n="contract.title"></h1>
            <p class="lead" data-i18n="contract.lead"></p>
        </div>
        <button type="button" class="link-button" id="lang" data-i18n="lang.switch"></button>
    </header>
    <nav class="nav" id="nav" aria-label="Sections"></nav>
    <p class="message error" id="unavailable" hidden></p>

    <section class="panel">
        <h2 data-i18n="contract.how.title"></h2>
        <p data-i18n="contract.how.text"></p>
        <div class="actions">
            <button type="button" class="primary" id="run" data-i18n="contract.run"></button>
        </div>
        <p class="step-extra" id="summary" hidden></p>
        <p class="note" id="seed" hidden></p>
    </section>

    <section class="panel" id="results-panel" hidden>
        <h2 data-i18n="contract.results.title"></h2>
        <table class="table">
            <thead><tr><th data-i18n="contract.col.kind"></th><th data-i18n="contract.col.what"></th><th data-i18n="contract.col.expected"></th><th data-i18n="contract.col.got"></th><th data-i18n="contract.col.verdict"></th></tr></thead>
            <tbody id="results"></tbody>
        </table>
        <p class="note" data-i18n="contract.note"></p>
    </section>
</main>
