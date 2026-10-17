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
            <h1 data-i18n="trace.title"></h1>
            <p class="lead" data-i18n="trace.lead"></p>
        </div>
        <button type="button" class="link-button" id="lang" data-i18n="lang.switch"></button>
    </header>
    <nav class="nav" id="nav" aria-label="Sections"></nav>
    <p class="message error" id="unavailable" hidden></p>

    <section class="panel">
        <h2 data-i18n="trace.how.title"></h2>
        <p data-i18n="trace.how.text"></p>
        <form id="pick" class="actions">
            <label><span data-i18n="agent.order"></span> <input id="order-id" maxlength="64" required></label>
            <button type="submit" class="primary" data-i18n="trace.show"></button>
        </form>
        <p class="note" id="trace-state" hidden></p>
    </section>

    <section class="panel" id="trace-panel" hidden>
        <h2 data-i18n="trace.waterfall.title"></h2>
        <div class="waterfall" id="waterfall"></div>
        <p class="step-extra" id="queue-wait" hidden></p>
        <p class="note"><a id="jaeger-link" href="#" data-i18n="trace.jaeger"></a></p>
    </section>

    <section class="panel" id="budget-panel" hidden>
        <h2 data-i18n="budget.title"></h2>
        <p data-i18n="budget.text"></p>
        <p class="note" id="budget-used"></p>
        <div class="actions">
            <button type="button" id="budget-tight" data-i18n="budget.tight"></button>
        </div>
        <p class="message" id="budget-result" role="status" hidden></p>
    </section>
</main>
