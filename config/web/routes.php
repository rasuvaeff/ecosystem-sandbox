<?php

declare(strict_types=1);

use App\Endpoint\Rest\Analytics\ShowReport\Action as AnalyticsShowReportAction;
use App\Endpoint\Rest\Assistant\ShowTranscript\Action as AssistantShowTranscriptAction;
use App\Endpoint\Rest\Marketing\ShowExperiment\Action as MarketingShowExperimentAction;
use App\Endpoint\Rest\Operations\ShowCaptcha\Action as OperationsShowCaptchaAction;
use App\Endpoint\Rest\Operations\ShowCheckoutTrace\Action as OperationsShowCheckoutTraceAction;
use App\Endpoint\Rest\Operations\ShowOperatorPanel\Action as OperationsShowOperatorPanelAction;
use App\Endpoint\Rest\Operations\ShowTenant\Action as OperationsShowTenantAction;
use App\Endpoint\Rest\Ordering\Checkout\Action as OrderingCheckoutAction;
use App\Endpoint\Rest\Ordering\GenerateCheckoutCases\Action as OrderingGenerateCheckoutCasesAction;
use App\Endpoint\Rest\Ordering\IssueRealtimeToken\Action as OrderingIssueRealtimeTokenAction;
use App\Endpoint\Rest\Ordering\ListOrders\Action as OrderingListOrdersAction;
use App\Endpoint\Rest\Ordering\ShowEvidence\Action as OrderingShowEvidenceAction;
use App\Endpoint\Rest\Ordering\ShowLifecycle\Action as OrderingShowLifecycleAction;
use App\Endpoint\Rest\Ordering\ShowOrder\Action as OrderingShowOrderAction;
use App\Endpoint\Rest\Ordering\ShowTimeline\Action as OrderingShowTimelineAction;
use App\Endpoint\Rest\Ordering\ShowWebhookDeliveries\Action as OrderingShowWebhookDeliveriesAction;
use App\Endpoint\Rest\Payments\ShowGatewayProtection\Action as PaymentsShowGatewayProtectionAction;
use App\Endpoint\Rest\Shared\ServeOpenApiDocument\Action as SharedServeOpenApiDocumentAction;
use App\Endpoint\Rest\Ui\ShowOrderScreen\Action as UiShowOrderScreenAction;
use App\Endpoint\Rest\Ui\ShowTestReport\Action as UiShowTestReportAction;
use App\Endpoint\Web\Ui\ShowPage\Action as WebUiShowPageAction;
use App\Endpoint\Web\Ui\ShowPorts\Action as WebUiShowPortsAction;
use App\Infrastructure\Marketing\Http\RememberCheckoutTouchpoints;
use App\Infrastructure\Operations\Http\CaptchaGate;
use App\Infrastructure\Operations\Http\RequestBudget;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Rasuvaeff\Yii3AbTestingWeb\StickyAssignmentMiddleware;
use Rasuvaeff\Yii3AbTestingWeb\SubjectIdMiddleware;
use Rasuvaeff\Yii3FeatureFlagsUi\FlagRoutes;
use Rasuvaeff\Yii3FilestorageWeb\Action\FileDownloadAction;
use Rasuvaeff\Yii3HealthCheck\HealthEndpoint;
use Rasuvaeff\Yii3HealthCheck\LivenessEndpoint;
use Rasuvaeff\Yii3HealthCheck\ReadinessEndpoint;
use Rasuvaeff\Yii3Mcp\McpAction;
use Rasuvaeff\Yii3MetricsPrometheus\MetricsEndpoint;
use Rasuvaeff\Yii3Seo\RobotsTxtAction;
use Rasuvaeff\Yii3SettingsUi\SettingsRoutes;
use Rasuvaeff\Yii3Utm\UtmCaptureMiddleware;
use Yiisoft\Request\Body\RequestBodyParser;
use Yiisoft\Router\Group;
use Yiisoft\Router\Route;
use Yiisoft\Swagger\Action\SwaggerUi;

/** @var array $params */

return [
    // The web UI: server-rendered pages (their head from yii3-seo), the side
    // services' host ports, and robots.txt. Scripts, styles and i18n.json are
    // static files under public/ui/.
    Route::get(pattern: '/')->action(middlewareDefinition: static fn (ResponseFactoryInterface $responses): ResponseInterface => $responses->createResponse(code: 302)->withHeader('Location', '/ui/'))->name(name: 'home'),
    Route::get(pattern: '/ui')->action(middlewareDefinition: static fn (ResponseFactoryInterface $responses): ResponseInterface => $responses->createResponse(code: 302)->withHeader('Location', '/ui/'))->name(name: 'ui.root'),
    Route::get(pattern: '/ui/')->action(middlewareDefinition: WebUiShowPageAction::class)->name(name: 'ui.home'),
    Route::get(pattern: '/ui/ports.json')->action(middlewareDefinition: WebUiShowPortsAction::class)->name(name: 'ui.ports'),
    Route::get(pattern: '/ui/{page:[a-z]+}.html')->action(middlewareDefinition: WebUiShowPageAction::class)->name(name: 'ui.page'),
    Route::get(pattern: '/robots.txt')->action(middlewareDefinition: RobotsTxtAction::class)->name(name: 'robots'),

    // Operations: probes and metrics.
    Route::get(pattern: '/live')->action(middlewareDefinition: LivenessEndpoint::class)->name(name: 'health.live'),
    Route::get(pattern: '/ready')->action(middlewareDefinition: ReadinessEndpoint::class)->name(name: 'health.ready'),
    Route::get(pattern: '/health')->action(middlewareDefinition: HealthEndpoint::class)->name(name: 'health'),
    Route::get(pattern: '/metrics')->action(middlewareDefinition: MetricsEndpoint::class)->name(name: 'metrics'),

    // The HTTP contract, generated from the actions' attributes, and Swagger UI over it.
    Route::get(pattern: '/openapi.json')->action(middlewareDefinition: SharedServeOpenApiDocumentAction::class)->name(name: 'openapi'),
    Route::get(pattern: '/docs')->action(middlewareDefinition: SwaggerUi::class)->name(name: 'docs'),

    // The AI agent's door (yii3-mcp, behind a shared secret).
    Route::methods(methods: ['POST', 'GET', 'DELETE', 'OPTIONS'], pattern: '/mcp')->action(middlewareDefinition: McpAction::class)->name(name: 'mcp'),

    // Signed, expiring download URLs of stored files (the order receipt).
    Route::get(pattern: '/files/{token}')->action(middlewareDefinition: FileDownloadAction::class)->name(name: 'files.download'),

    // The packages' own admin pages over the flag and settings tables. No
    // authentication: a demo on a developer's machine only.
    ...FlagRoutes::fromParams($params),
    ...SettingsRoutes::fromParams($params),

    Group::create(prefix: '/api/v1')->routes(
        // Ordering. The checkout carries the opt-in captcha gate and the UTM
        // capture on this one route: it is the request that turns a campaign
        // touch into an attributed order.
        Route::post(pattern: '/checkout')
            // The body reaches the Input through the parsed body; a malformed
            // one is left unparsed for the contract to refuse as a problem.
            ->middleware(static fn (RequestBodyParser $parser): RequestBodyParser => $parser->ignoreBadRequestBody())
            ->middleware(CaptchaGate::class)
            ->middleware(UtmCaptureMiddleware::class)
            ->middleware(RememberCheckoutTouchpoints::class)
            ->action(middlewareDefinition: OrderingCheckoutAction::class)
            ->name(name: 'checkout'),
        Route::get(pattern: '/orders')->action(middlewareDefinition: OrderingListOrdersAction::class)->name(name: 'orders'),
        Route::get(pattern: '/orders/{id}')->action(middlewareDefinition: OrderingShowOrderAction::class)->name(name: 'order'),
        Route::get(pattern: '/orders/{id}/timeline')->action(middlewareDefinition: OrderingShowTimelineAction::class)->name(name: 'order.timeline'),
        // The web UI's own backend: one request per screen.
        Route::get(pattern: '/ui/orders/{id}')->action(middlewareDefinition: UiShowOrderScreenAction::class)->name(name: 'ui.order_screen'),
        Route::get(pattern: '/ui/tests')->action(middlewareDefinition: UiShowTestReportAction::class)->name(name: 'ui.tests'),
        Route::get(pattern: '/orders/{id}/evidence')->action(middlewareDefinition: OrderingShowEvidenceAction::class)->name(name: 'order.evidence'),
        Route::get(pattern: '/orders/{id}/realtime-token')->action(middlewareDefinition: OrderingIssueRealtimeTokenAction::class)->name(name: 'order.realtime_token'),
        Route::get(pattern: '/workflows/order')->action(middlewareDefinition: OrderingShowLifecycleAction::class)->name(name: 'workflow.order'),
        Route::get(pattern: '/webhooks')->action(middlewareDefinition: OrderingShowWebhookDeliveriesAction::class)->name(name: 'webhooks'),
        Route::get(pattern: '/contract/checkout-cases')->action(middlewareDefinition: OrderingGenerateCheckoutCasesAction::class)->name(name: 'contract.checkout_cases'),

        // Payments.
        Route::get(pattern: '/resilience/payment-gateway')->action(middlewareDefinition: PaymentsShowGatewayProtectionAction::class)->name(name: 'resilience.payment_gateway'),

        // Marketing. The visitor cookie and the sticky variant live on this
        // route alone: a global middleware would put Set-Cookie on every API
        // answer, replays of the idempotency cache included.
        Route::get(pattern: '/experiments/checkout-button')
            ->middleware(SubjectIdMiddleware::class)
            ->middleware(StickyAssignmentMiddleware::class)
            ->action(middlewareDefinition: MarketingShowExperimentAction::class)
            ->name(name: 'experiment.checkout_button'),

        // Analytics.
        Route::get(pattern: '/analytics')->action(middlewareDefinition: AnalyticsShowReportAction::class)->name(name: 'analytics'),

        // Assistant.
        Route::get(pattern: '/agent/transcript')->action(middlewareDefinition: AssistantShowTranscriptAction::class)->name(name: 'agent.transcript'),

        // Operations. The trace read runs under a request budget:
        // X-Request-Timeout-Ms shortens it, an exhausted one is a 504.
        Route::get(pattern: '/orders/{id}/trace')
            ->middleware(RequestBudget::class)
            ->action(middlewareDefinition: OperationsShowCheckoutTraceAction::class)
            ->name(name: 'order.trace'),
        Route::get(pattern: '/operator')->action(middlewareDefinition: OperationsShowOperatorPanelAction::class)->name(name: 'operator'),
        Route::get(pattern: '/captcha')->action(middlewareDefinition: OperationsShowCaptchaAction::class)->name(name: 'captcha'),
        Route::get(pattern: '/tenant')->action(middlewareDefinition: OperationsShowTenantAction::class)->name(name: 'tenant'),
    ),
];
