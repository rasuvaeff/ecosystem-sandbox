<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Endpoint\Rest\Ordering\Checkout\Input;
use HttpSoft\Message\ServerRequest;
use Yiisoft\Hydrator\AttributeHandling\ResolverFactory\ContainerAttributeResolverFactory;
use Yiisoft\Hydrator\Hydrator;
use Yiisoft\Input\Http\Attribute\Data\FromBodyResolver;
use Yiisoft\RequestProvider\RequestProvider;
use Yiisoft\Test\Support\Container\SimpleContainer;
use Yiisoft\Validator\Validator;

/**
 * The checkout Input the way yiisoft/input-http builds it: #[FromBody] reads
 * the request's parsed body, then the validation rules run.
 */
final class CheckoutInputs
{
    /**
     * @param array<string, mixed> $body
     */
    public static function accepts(array $body): bool
    {
        $hydrator = new Hydrator(attributeResolverFactory: new ContainerAttributeResolverFactory(container: new SimpleContainer([
            FromBodyResolver::class => new FromBodyResolver(requestProvider: new RequestProvider(request: new ServerRequest()->withParsedBody(data: $body))),
        ])));

        return new Validator()->validate(data: $hydrator->create(class: Input::class))->isValid();
    }
}
