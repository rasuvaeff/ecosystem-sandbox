<?php

declare(strict_types=1);

namespace App\Endpoint\Rest\Ordering\GenerateCheckoutCases;

use App\Endpoint\Rest\Ordering\GenerateCheckoutCases\Data\CheckoutCasesResult;
use App\Endpoint\Rest\Shared\ResponseFactory\ResponseFactory;
use HttpSoft\Message\RequestFactory;
use HttpSoft\Message\StreamFactory;
use OpenApi\Attributes as OA;
use Psr\Http\Message\ResponseInterface;
use Rasuvaeff\OpenApiContract\Contract;
use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\OpenApi\ContractSuite;
use Rasuvaeff\PropertyTesting\OpenApi\NegativeRequestCaseArbitrary;
use Rasuvaeff\PropertyTesting\OpenApi\RequestCaseArbitrary;
use Rasuvaeff\PropertyTesting\OpenApi\RequestMaterializer;
use Rasuvaeff\PropertyTesting\Random;

/**
 * Checkout requests generated from the OpenAPI document — some valid, some broken on
 * purpose, each broken one saying what was broken. Nothing is sent from here:
 * the built-in server answers one request at a time and would deadlock calling
 * itself, so the browser sends every case to the real API and compares.
 *
 * @psalm-import-type CaseData from ContractSuite
 */
final readonly class Action
{
    private const int VALID_CASES = 4;

    /** Amounts the payment mock reacts to; a generated order must not start an outage. */
    private const array TEST_AMOUNTS = [4002, 5030, 5031, 5040];

    /** Headers fetch() refuses to set. */
    private const array FORBIDDEN_HEADERS = ['cookie', 'host', 'content-length', 'connection', 'origin', 'referer'];

    public function __construct(
        private Contract $contract,
        private ResponseFactory $responseFactory,
    ) {}

    #[OA\Get(
        path: '/api/v1/contract/checkout-cases',
        operationId: 'contract.checkoutCases',
        summary: 'Checkout requests generated from this document, valid and deliberately broken; nothing is sent',
        parameters: [
            new OA\Parameter(
                name: 'seed',
                in: 'query',
                required: false,
                schema: new OA\Schema(
                    type: 'integer',
                    minimum: 0,
                ),
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Generated cases',
                content: [
                    new OA\MediaType(
                        mediaType: 'application/json',
                        schema: new OA\Schema(
                            required: ['seed', 'operation', 'cases'],
                            properties: [
                                new OA\Property(
                                    property: 'seed',
                                    type: 'integer',
                                ),
                                new OA\Property(
                                    property: 'operation',
                                    type: 'string',
                                ),
                                new OA\Property(
                                    property: 'cases',
                                    type: 'array',
                                    items: new OA\Items(
                                        required: ['kind', 'misuse', 'method', 'path', 'headers', 'body'],
                                        properties: [
                                            new OA\Property(
                                                property: 'kind',
                                                type: 'string',
                                                enum: ['valid', 'invalid'],
                                            ),
                                            new OA\Property(
                                                property: 'misuse',
                                                type: ['object', 'null'],
                                            ),
                                            new OA\Property(
                                                property: 'method',
                                                type: 'string',
                                            ),
                                            new OA\Property(
                                                property: 'path',
                                                type: 'string',
                                            ),
                                            new OA\Property(
                                                property: 'headers',
                                                type: 'object',
                                            ),
                                            new OA\Property(
                                                property: 'body',
                                                type: 'string',
                                            ),
                                        ],
                                        type: 'object',
                                        additionalProperties: false,
                                    ),
                                ),
                            ],
                            type: 'object',
                            additionalProperties: false,
                        ),
                    ),
                ],
            ),
        ],
    )]
    public function __invoke(Input $input): ResponseInterface
    {
        $seed = $input->seed();
        $operation = $this->contract->operation(key: 'checkout.create');
        $materializer = new RequestMaterializer(new RequestFactory(), new StreamFactory());
        $random = new Random($seed);
        $negative = new NegativeRequestCaseArbitrary();
        /** @var list<array{string, ArbitraryInterface<CaseData>, int}> $sources */
        $sources = [['valid', new RequestCaseArbitrary()->forOperation($operation), self::VALID_CASES]];
        // One case per way of breaking a request, so the page shows the range
        // of what the validator refuses rather than six missing bodies.
        $categories = [
            $negative->forOperation(...),
            $negative->typeMismatchForOperation(...),
            $negative->boundaryMismatchForOperation(...),
            $negative->lengthMismatchForOperation(...),
            $negative->additionalPropertyForOperation(...),
            $negative->mediaTypeMismatchForOperation(...),
            $negative->malformedJsonForOperation(...),
            $negative->bodyTypeMismatchForOperation(...),
            $negative->bodyBoundaryMismatchForOperation(...),
            $negative->bodyLengthMismatchForOperation(...),
        ];
        foreach ($categories as $category) {
            try {
                $sources[] = ['invalid', $category($operation), 1];
            } catch (\Throwable) {
                // The operation has nothing this category can break.
            }
        }

        $cases = [];
        foreach ($sources as [$kind, $arbitrary, $wanted]) {
            $taken = 0;
            for ($draw = 0; $draw < 100 && $taken < $wanted; ++$draw) {
                $case = $arbitrary->generate($random)->value;
                $built = $materializer->materialize($operation, $case);
                $body = (string) $built->getBody();
                if ($case['cookies'] !== [] || $this->touchesTestAmount($body) || !$this->sendableHeaders($built->getHeaders())) {
                    continue;
                }
                $headers = [];
                foreach ($built->getHeaders() as $name => $values) {
                    $headers[$name] = implode(separator: ', ', array: $values);
                }
                $cases[] = [
                    'kind' => $kind,
                    'misuse' => $case['misuse'],
                    'method' => $built->getMethod(),
                    'path' => $built->getUri()->getPath() . ($built->getUri()->getQuery() !== '' ? '?' . $built->getUri()->getQuery() : ''),
                    'headers' => $headers,
                    'body' => $body,
                ];
                ++$taken;
            }
        }

        return $this->responseFactory->success(data: new CheckoutCasesResult(seed: $seed, operation: 'checkout.create', cases: $cases));
    }

    private function touchesTestAmount(string $body): bool
    {
        return preg_match(pattern: '/"amountCents"\s*:\s*(\d+)/', subject: $body, matches: $match) === 1
            && in_array((int) $match[1], self::TEST_AMOUNTS, strict: true);
    }

    /**
     * fetch() refuses some header names outright and any value outside
     * printable ASCII; a case it cannot send as generated is skipped.
     *
     * @param array<array-key, array<array-key, string>> $headers
     */
    private function sendableHeaders(array $headers): bool
    {
        foreach ($headers as $name => $values) {
            if (in_array(strtolower(string: (string) $name), self::FORBIDDEN_HEADERS, strict: true)) {
                return false;
            }
            foreach ($values as $value) {
                if (preg_match(pattern: '/\A[\x20-\x7e]*\z/', subject: $value) !== 1) {
                    return false;
                }
            }
        }

        return true;
    }
}
