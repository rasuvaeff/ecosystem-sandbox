<?php

declare(strict_types=1);

namespace App\Endpoint\Web\Ui\ShowPage;

use App\Endpoint\Web\Ui\UiPage;
use App\Shared\Http\Problem;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Rasuvaeff\Yii3Seo\Alternates;
use Rasuvaeff\Yii3Seo\JsonLd;
use Rasuvaeff\Yii3Seo\Metadata;
use Rasuvaeff\Yii3Seo\OpenGraph;
use Rasuvaeff\Yii3Seo\Robots;
use Rasuvaeff\Yii3Seo\SeoMetadataEvent;
use Yiisoft\Router\CurrentRoute;
use Yiisoft\Yii\View\Renderer\WebViewRenderer;

/**
 * One page of the web UI, rendered on the server so the head carries the
 * page's own metadata (rasuvaeff/yii3-seo: title, description, canonical,
 * Open Graph, JSON-LD). The body is static markup; the page's script fills
 * it from the API and switches the language in the browser.
 *
 * Rendered to a string here, not as a lazy view response: an error in a view
 * then surfaces inside the middleware stack, as every other one does.
 */
final readonly class Action
{
    public function __construct(
        private WebViewRenderer $views,
        private EventDispatcherInterface $events,
        private ResponseFactoryInterface $responses,
        private CurrentRoute $currentRoute,
    ) {}

    public function __invoke(): ResponseInterface
    {
        $page = UiPage::tryFrom(value: $this->currentRoute->getArgument(name: 'page', default: UiPage::Order->value) ?? '')
            ?? throw Problem::pageNotFound();

        $this->events->dispatch(new SeoMetadataEvent(metadata: new Metadata(
            title: $page->title(),
            description: $page->description(),
            robots: $page->isPersonal() ? Robots::noindex() : null,
            alternates: new Alternates(canonical: $page->path()),
            openGraph: new OpenGraph(type: 'website', url: $page->path()),
            jsonLd: [JsonLd::fromArray(['@context' => 'https://schema.org', '@type' => 'WebPage', 'name' => $page->title(), 'description' => $page->description()])],
        )));

        $html = $this->views
            ->withViewPath(viewPath: '@views/ui')
            ->withLayout(layout: '@views/layout/ui')
            ->renderAsString(view: $page->value, parameters: ['page' => $page]);
        $response = $this->responses->createResponse()->withHeader('Content-Type', 'text/html; charset=utf-8');
        $response->getBody()->write($html);

        return $response;
    }
}
