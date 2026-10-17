<?php

declare(strict_types=1);

namespace App\Endpoint\Web\Ui;

/**
 * The web UI's pages: the view that renders each, the script that drives it,
 * the i18n key of its heading and what search engines and link previews are
 * told about it. The texts are the English ones of i18n.json; the page
 * itself switches language in the browser.
 */
enum UiPage: string
{
    case Order = 'index';
    case Resilience = 'resilience';
    case Trace = 'trace';
    case Operator = 'operator';
    case Contract = 'contract';
    case Agent = 'agent';
    case Webhooks = 'webhooks';
    case Analytics = 'analytics';
    case Tests = 'tests';

    public function path(): string
    {
        return '/ui/' . $this->value . '.html';
    }

    public function script(): string
    {
        return $this === self::Order ? 'app.js' : $this->value . '.js';
    }

    public function titleKey(): string
    {
        return $this === self::Order ? 'title' : $this->value . '.title';
    }

    public function title(): string
    {
        return match ($this) {
            self::Order => 'How paying for an order works',
            self::Resilience => 'Circuit breaker and the queue to the bank',
            self::Trace => 'The path of one request',
            self::Operator => 'Operator console',
            self::Contract => 'API contract: a machine checks the server',
            self::Agent => 'An AI agent asks the shop',
            self::Webhooks => 'Webhooks: how the shop tells a partner',
            self::Analytics => 'Analytics',
            self::Tests => 'How the shop tests itself',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Order => 'A teaching shop: place an order and watch every step of the payment, read from the real database.',
            self::Resilience => 'How a circuit breaker, retries and a bulkhead keep the shop up while the bank is failing.',
            self::Trace => 'One click on Pay as a dozen steps in different processes, each with its duration, in one trace.',
            self::Operator => 'What the engineer on call sees and controls: dependency health, feature flags, maintenance and settings.',
            self::Contract => 'Requests generated from the OpenAPI document, valid and deliberately broken, checked against the server.',
            self::Agent => 'An AI assistant answers customers about their orders through read-only MCP tools.',
            self::Webhooks => 'Signed webhooks to a partner server: what the shop sent and what the partner received.',
            self::Analytics => 'The payment events in ClickHouse: reports by status, campaign and A/B variant.',
            self::Tests => 'The last runs of the unit, property and mutation tests: what each kind checks, and the bugs planted on purpose that the tests caught.',
        };
    }

    /**
     * The order page shows one visitor's orders; the others describe the
     * shop and may be indexed.
     */
    public function isPersonal(): bool
    {
        return $this === self::Order;
    }
}
