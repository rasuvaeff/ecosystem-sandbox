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
            <h1 data-i18n="agent.title"></h1>
            <p class="lead" data-i18n="agent.lead"></p>
        </div>
        <button type="button" class="link-button" id="lang" data-i18n="lang.switch"></button>
    </header>
    <nav class="nav" id="nav" aria-label="Sections"></nav>
    <p class="message error" id="unavailable" hidden></p>

    <section class="panel">
        <h2 data-i18n="agent.how.title"></h2>
        <p data-i18n="agent.how.text"></p>
        <form id="ask" class="actions">
            <label><span data-i18n="agent.order"></span> <input id="order-id" maxlength="64" required></label>
            <button type="submit" class="primary" id="ask-button" data-i18n="agent.ask"></button>
        </form>
        <p class="note" id="asked" role="status"></p>
    </section>

    <div class="agents" id="agents"></div>

    <section class="panel">
        <h2 data-i18n="agent.real.title"></h2>
        <p data-i18n="agent.real.text"></p>
        <pre id="mcp-command"></pre>
        <p class="note" data-i18n="agent.real.secret"></p>
        <p data-i18n="agent.real.ask"></p>
    </section>
</main>
