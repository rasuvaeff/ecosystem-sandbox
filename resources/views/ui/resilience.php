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
            <h1 data-i18n="resilience.title"></h1>
            <p class="lead" data-i18n="resilience.lead"></p>
        </div>
        <button type="button" class="link-button" id="lang" data-i18n="lang.switch"></button>
    </header>
    <nav class="nav" id="nav" aria-label="Sections"></nav>
    <p class="message error" id="unavailable" hidden></p>

    <section class="panel">
        <h2 data-i18n="resilience.breaker.title"></h2>
        <div class="breaker-strip big">
            <span class="lamp" id="lamp" aria-hidden="true"></span>
            <div>
                <strong id="state"></strong>
                <p id="state-text"></p>
                <p class="step-extra" id="countdown" hidden></p>
            </div>
        </div>
        <p data-i18n="resilience.breaker.how"></p>
        <p class="step-extra" id="threshold"></p>
        <div class="meters">
            <div class="meter"><span class="meter-value" id="failures"></span><span data-i18n="resilience.failures"></span></div>
            <div class="meter"><span class="meter-value" id="successes"></span><span data-i18n="resilience.successes"></span></div>
            <div class="meter"><span class="meter-value" id="rejected"></span><span data-i18n="resilience.rejected"></span></div>
        </div>
    </section>

    <section class="panel">
        <h2 data-i18n="resilience.bulkhead.title"></h2>
        <p data-i18n="resilience.bulkhead.text"></p>
        <div class="slots" id="slots"></div>
        <p class="step-extra" id="slots-text"></p>
    </section>

    <section class="panel">
        <h2 data-i18n="resilience.retry.title"></h2>
        <p data-i18n="resilience.retry.text"></p>
        <h3 data-i18n="resilience.try.title"></h3>
        <p data-i18n="resilience.try.text"></p>
        <p><a href="index.html" data-i18n="resilience.try.link"></a></p>
    </section>
</main>
