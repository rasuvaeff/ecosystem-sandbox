<?php

declare(strict_types=1);

namespace App\Infrastructure\Operations\Tracing;

use App\Application\Operations\GetCheckoutTrace\TraceStore;
use App\Environment;
use GuzzleHttp\Client;
use Psr\Http\Message\RequestFactoryInterface;
use Rasuvaeff\Context\Context;
use Rasuvaeff\Context\ContextException;
use Rasuvaeff\Context\Http\ContextClient;
use Rasuvaeff\Schema\Schema;
use Rasuvaeff\Schema\Type\ObjectSchema;

/**
 * Jaeger's query API, called through rasuvaeff/context-http's ContextClient:
 * Guzzle's timeout is what is left of the request's budget. Jaeger's answer
 * is read as untrusted data, and only whitelisted span attributes pass.
 *
 * @psalm-type JaegerSpan = array{
 *     spanID: string,
 *     processID: string,
 *     operationName?: string,
 *     startTime: float,
 *     duration?: float,
 *     references?: list<array{refType: string, spanID: string}>,
 *     tags?: list<array{key: string, value: scalar}>,
 * }
 */
final readonly class JaegerTraceStore implements TraceStore
{
    private const array ATTRIBUTES = [
        'http.request.method', 'http.route', 'http.response.status_code', 'url.path', 'server.address',
        'messaging.system', 'messaging.destination.name', 'messaging.operation.type', 'process.exit.code',
    ];

    public function __construct(private RequestFactoryInterface $requestFactory) {}

    /** @return list<array{name: string, service: string, kind: string, startMs: float, durationMs: float, depth: int, attributes: array<string, scalar>}> */
    public function spans(string $traceId, Context $budget): array
    {
        try {
            $answer = new ContextClient(new Client())->send($this->requestFactory->createRequest(
                'GET',
                Environment::string(name: 'JAEGER_URL', default: 'http://jaeger:16686') . '/api/traces/' . $traceId,
            ), $budget);
            $parsed = $this->answerSchema()->parse(input: json_decode(json: (string) $answer->getBody(), associative: true));
        } catch (ContextException $exhausted) {
            // RequestBudget answers 504: the caller's time is up.
            throw $exhausted;
        } catch (\Throwable) {
            return [];
        }
        // Jaeger's answer is untrusted data: a trace still being assembled
        // may lack any part of it, and that is "no spans yet", never a 500.
        if (!$parsed->ok) {
            return [];
        }
        /** @var array{data: list<array{spans: list<JaegerSpan>, processes: array<string, array{serviceName: string}>}>} $data */
        $data = $parsed->value;
        $trace = $data['data'][0] ?? null;
        if ($trace === null || $trace['spans'] === []) {
            return [];
        }

        $raw = $trace['spans'];
        usort($raw, static fn (array $a, array $b): int => $a['startTime'] <=> $b['startTime']);
        $start = $raw[0]['startTime'];
        $parents = [];
        foreach ($raw as $span) {
            foreach ($span['references'] ?? [] as $reference) {
                if ($reference['refType'] === 'CHILD_OF') {
                    $parents[$span['spanID']] = $reference['spanID'];
                }
            }
        }

        $spans = [];
        foreach ($raw as $span) {
            $depth = 0;
            for ($cursor = $span['spanID']; isset($parents[$cursor]) && $depth < 10; $cursor = $parents[$cursor]) {
                ++$depth;
            }
            $kind = 'internal';
            $attributes = [];
            foreach ($span['tags'] ?? [] as $tag) {
                if ($tag['key'] === 'span.kind') {
                    $kind = (string) $tag['value'];
                } elseif (in_array(needle: $tag['key'], haystack: self::ATTRIBUTES, strict: true)) {
                    $attributes[$tag['key']] = $tag['value'];
                }
            }
            $spans[] = [
                'name' => $span['operationName'] ?? '',
                'service' => $trace['processes'][$span['processID']]['serviceName'] ?? '',
                'kind' => $kind,
                'startMs' => round(num: ($span['startTime'] - $start) / 1000, precision: 1),
                'durationMs' => round(num: ($span['duration'] ?? 0.0) / 1000, precision: 1),
                'depth' => $depth,
                'attributes' => $attributes,
            ];
        }

        return $spans;
    }

    /**
     * The part of Jaeger's `/api/traces/{id}` answer the page shows; every
     * other key is stripped. A tag value that is not a scalar fails the parse.
     */
    private function answerSchema(): ObjectSchema
    {
        $span = Schema::object([
            'spanID' => Schema::string(),
            'processID' => Schema::string(),
            'operationName' => Schema::string()->optional(),
            'startTime' => Schema::float()->allowInt(),
            'duration' => Schema::float()->allowInt()->optional(),
            'references' => Schema::arrayOf(Schema::object([
                'refType' => Schema::string(),
                'spanID' => Schema::string(),
            ])->strip())->optional(),
            'tags' => Schema::arrayOf(Schema::object([
                'key' => Schema::string(),
                'value' => Schema::union([Schema::string(), Schema::int(), Schema::float(), Schema::bool()]),
            ])->strip())->optional(),
        ])->strip();

        return Schema::object([
            'data' => Schema::arrayOf(Schema::object([
                'spans' => Schema::arrayOf($span),
                'processes' => Schema::mapOf(Schema::object(['serviceName' => Schema::string()])->strip()),
            ])->strip()),
        ])->strip();
    }
}
