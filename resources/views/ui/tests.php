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
            <h1 data-i18n="tests.title"></h1>
            <p class="lead" data-i18n="tests.lead"></p>
        </div>
        <button type="button" class="link-button" id="lang" data-i18n="lang.switch"></button>
    </header>
    <nav class="nav" id="nav" aria-label="Sections"></nav>
    <p class="message error" id="unavailable" hidden></p>

    <section class="panel">
        <h2 data-i18n="tests.summary.title"></h2>
        <div class="meters" id="summary"></div>
        <p class="note" data-i18n="tests.source"></p>
    </section>

    <section class="panel">
        <h2 data-i18n="tests.unit.title"></h2>
        <p data-i18n="tests.unit.text"></p>
        <p class="note" id="unit-empty" data-i18n="tests.unit.empty" hidden></p>
        <div id="layers"></div>
    </section>

    <section class="panel">
        <h2 data-i18n="tests.property.title"></h2>
        <p data-i18n="tests.property.text"></p>
        <p class="note" id="property-empty" data-i18n="tests.unit.empty" hidden></p>
        <div id="properties"></div>
    </section>

    <section class="panel">
        <h2 data-i18n="tests.mutation.title"></h2>
        <p data-i18n="tests.mutation.text"></p>
        <p class="note" id="mutation-empty" data-i18n="tests.mutation.empty" hidden></p>
        <div id="mutation"></div>
    </section>
</main>
