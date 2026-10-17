<?php

declare(strict_types=1);

namespace App\Endpoint\Rest\Shared\ResponseFactory;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Yiisoft\Http\Status;

/**
 * Endpoints answer through this: the body is encoded here, eagerly. A lazily
 * formatted DataResponse would be encoded after ProblemDetails and the
 * observability middlewares returned, so an encoding error would escape the
 * problem renderer and the RED metrics would have counted a 200.
 */
final readonly class ResponseFactory
{
    public function __construct(private ResponseFactoryInterface $responseFactory) {}

    /**
     * @param array<string, string> $headers
     */
    public function success(\JsonSerializable $data, int $status = Status::OK, array $headers = []): ResponseInterface
    {
        $response = $this->responseFactory->createResponse(code: $status)->withHeader('Content-Type', 'application/json');
        foreach ($headers as $name => $value) {
            $response = $response->withHeader($name, $value);
        }
        $response->getBody()->write(string: json_encode(value: $data, flags: JSON_THROW_ON_ERROR));

        return $response;
    }
}
