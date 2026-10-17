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
            <h1 data-i18n="title"></h1>
            <p class="lead" data-i18n="lead"></p>
        </div>
        <button type="button" class="link-button" id="lang" data-i18n="lang.switch"></button>
    </header>
    <nav class="nav" id="nav" aria-label="Sections"></nav>

    <section class="panel" aria-labelledby="campaign-title">
        <h2 id="campaign-title" data-i18n="utm.title"></h2>
        <p class="note" data-i18n="utm.text"></p>
        <ul class="campaign-links">
            <li><a href="?utm_source=newsletter&amp;utm_medium=email&amp;utm_campaign=autumn-sale" data-i18n="utm.link.email"></a></li>
            <li><a href="?utm_source=google&amp;utm_medium=cpc&amp;utm_campaign=brand&amp;gclid=demo-click-1" data-i18n="utm.link.ads"></a></li>
            <li><a href="?utm_source=telegram&amp;utm_medium=social&amp;utm_campaign=launch-post" data-i18n="utm.link.social"></a></li>
        </ul>
        <p class="note" id="campaign-current"></p>
        <button type="button" class="link-button" id="campaign-clear" hidden data-i18n="utm.clear"></button>
    </section>

    <section class="panel" aria-labelledby="form-title">
        <h2 id="form-title" data-i18n="form.title"></h2>
        <form id="checkout">
            <div id="choose">
            <fieldset class="cards">
                <legend data-i18n="form.card"></legend>
                <label class="card"><input type="radio" name="card" value="ok" checked><span><strong data-i18n="card.ok.name"></strong><small data-i18n="card.ok.hint"></small></span></label>
                <label class="card"><input type="radio" name="card" value="declined"><span><strong data-i18n="card.declined.name"></strong><small data-i18n="card.declined.hint"></small></span></label>
                <label class="card"><input type="radio" name="card" value="blip"><span><strong data-i18n="card.blip.name"></strong><small data-i18n="card.blip.hint"></small></span></label>
                <label class="card"><input type="radio" name="card" value="outage"><span><strong data-i18n="card.outage.name"></strong><small data-i18n="card.outage.hint"></small></span></label>
                <label class="card"><input type="radio" name="card" value="lost"><span><strong data-i18n="card.lost.name"></strong><small data-i18n="card.lost.hint"></small></span></label>
            </fieldset>
            <p class="amount"><span data-i18n="form.amount"></span>: <strong id="amount"></strong></p>
            <p class="note" data-i18n="form.amountNote"></p>
            <p class="note" data-i18n="form.outageNote"></p>
            </div>
            <div id="captcha-box" hidden>
                <p class="note" data-i18n="captcha.text"></p>
                <div id="captcha"></div>
            </div>
            <div class="actions">
                <button type="submit" class="primary" id="pay" data-i18n="form.pay"></button>
                <button type="button" id="again" hidden data-i18n="form.again"></button>
                <button type="button" class="link-button" id="new-order" hidden data-i18n="form.newOrder"></button>
            </div>
            <p class="note" id="again-note" hidden data-i18n="form.againNote"></p>
            <p class="note" id="experiment"></p>
            <p class="message" id="message" role="status" hidden></p>
        </form>
    </section>

    <section class="panel" aria-labelledby="story-title">
        <h2 id="story-title" data-i18n="story.title"></h2>
        <p class="note" id="story-empty" data-i18n="story.empty"></p>
        <div id="story" hidden>
            <p class="order-head"><strong id="order-title"></strong></p>
            <p class="note" id="request-id" hidden></p>
            <p class="note" id="attribution" hidden></p>
            <p class="note" id="receipt" hidden></p>
            <p class="live" id="live" role="status" hidden></p>
            <p class="note"><a id="ask-agent" href="agent.html" data-i18n="story.askAgent"></a> · <a id="see-trace" href="trace.html" data-i18n="story.seeTrace"></a></p>
            <ol class="steps" id="steps"></ol>
            <div class="breaker-strip" id="breaker" hidden>
                <span class="lamp" id="breaker-lamp" aria-hidden="true"></span>
                <div>
                    <strong id="breaker-state"></strong>
                    <p class="note" id="breaker-text"></p>
                    <a href="resilience.html" data-i18n="breaker.more"></a>
                </div>
            </div>
            <h3 data-i18n="workflow.title"></h3>
            <p class="note" data-i18n="workflow.lead"></p>
            <div class="workflow-box" id="workflow"></div>
            <p class="note" id="workflow-next"></p>
            <h3 data-i18n="audit.title"></h3>
            <p class="note" data-i18n="audit.lead"></p>
            <ul class="audit" id="audit"></ul>
            <details class="tech">
                <summary data-i18n="details"></summary>
                <h3 data-i18n="details.transitions"></h3>
                <pre id="tech-transitions"></pre>
                <h3 data-i18n="details.audit"></h3>
                <pre id="tech-audit"></pre>
                <h3 data-i18n="details.outbox"></h3>
                <pre id="tech-outbox"></pre>
            </details>
        </div>
    </section>

    <section class="panel" aria-labelledby="glossary-title">
        <h2 id="glossary-title" data-i18n="glossary.title"></h2>
        <ul class="glossary">
            <li data-i18n="glossary.transaction"></li>
            <li data-i18n="glossary.outbox"></li>
            <li data-i18n="glossary.worker"></li>
            <li data-i18n="glossary.idempotency"></li>
            <li data-i18n="glossary.retry"></li>
            <li data-i18n="glossary.breaker"></li>
            <li data-i18n="glossary.audit"></li>
            <li data-i18n="glossary.tracing"></li>
        </ul>
        <h3 data-i18n="tools.title"></h3>
        <ul class="tools">
            <li><a data-service="jaeger" href="#" data-i18n="tools.jaeger"></a></li>
            <li><a data-service="prometheus" href="#" data-i18n="tools.prometheus"></a></li>
            <li><a href="/metrics" data-i18n="tools.metrics"></a></li>
            <li><a href="/health" data-i18n="tools.health"></a></li>
        </ul>
    </section>
</main>
