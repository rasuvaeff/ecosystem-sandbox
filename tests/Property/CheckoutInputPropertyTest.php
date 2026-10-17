<?php

declare(strict_types=1);

namespace App\Tests\Property;

use App\Domain\Ordering\Amount;
use App\Domain\Ordering\CustomerId;
use App\Domain\Ordering\OrderId;
use App\Endpoint\Rest\Ordering\Checkout\Input;
use App\Tests\Support\CheckoutInputs;
use GuzzleHttp\Psr7\Request;
use HttpSoft\Message\RequestFactory;
use HttpSoft\Message\StreamFactory;
use Psr\Container\ContainerInterface;
use Rasuvaeff\OpenApiContract\Contract;
use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\Classify;
use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\PropertyTesting\OpenApi\ContractSuite;
use Rasuvaeff\PropertyTesting\OpenApi\RequestCaseArbitrary;
use Rasuvaeff\PropertyTesting\OpenApi\RequestMaterializer;
use Rasuvaeff\PropertyTesting\Property;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

/**
 * The checkout body is checked twice: by the OpenAPI document (generated from
 * the action's attributes) and then by the Input's validation rules (the
 * domain's bounds). The properties keep the two from drifting: on any
 * payload they agree, and everything the document generates as valid the
 * Input accepts.
 *
 * @psalm-import-type CaseData from ContractSuite
 */
#[Test]
#[Covers(Input::class)]
final class CheckoutInputPropertyTest
{
    /**
     * Parity law, in both directions: inside the domain's bounds both accept,
     * outside them both refuse, whichever field broke it.
     *
     * @param array<string, mixed> $payload
     */
    #[Property(runs: 300)]
    public function theInputAndTheDocumentAgree(array $payload): void
    {
        $inputValid = CheckoutInputs::accepts(body: $payload);
        Classify::cover($inputValid, label: 'accepted', minPercent: 30.0);
        Classify::cover(!$inputValid, label: 'refused', minPercent: 30.0);

        Assert::same($this->documentAccepts(payload: $payload), $inputValid);
    }

    /** @return array<string, ArbitraryInterface> */
    public static function theInputAndTheDocumentAgreeGenerators(): array
    {
        $orderId = Gen::stringOf(minLength: 1, maxLength: OrderId::MAX_LENGTH);
        $customerId = Gen::stringOf(minLength: 1, maxLength: CustomerId::MAX_LENGTH);
        $amount = Gen::intBetween(Amount::MIN_CENTS, Amount::MAX_CENTS);
        $tooLong = Gen::stringOf(minLength: OrderId::MAX_LENGTH + 1, maxLength: 120);
        $outOfRange = Gen::frequency([
            [1, Gen::intBetween(PHP_INT_MIN, Amount::MIN_CENTS - 1)],
            [1, Gen::intBetween(Amount::MAX_CENTS + 1, PHP_INT_MAX)],
        ]);
        $payload = static fn (ArbitraryInterface $order, ArbitraryInterface $customer, ArbitraryInterface $cents): ArbitraryInterface => Gen::map(
            Gen::tuple($order, $customer, $cents),
            static fn (array $values): array => ['orderId' => $values[0], 'customerId' => $values[1], 'amountCents' => $values[2]],
        );

        return ['payload' => Gen::frequency([
            [3, $payload($orderId, $customerId, $amount)],
            [1, $payload($tooLong, $customerId, $amount)],
            [1, $payload($orderId, $tooLong, $amount)],
            [1, $payload($orderId, $customerId, $outOfRange)],
        ])];
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function theInputAndTheDocumentAgreeExamples(): iterable
    {
        yield 'minimum amount' => [['orderId' => 'a', 'customerId' => 'b', 'amountCents' => Amount::MIN_CENTS]];
        yield 'maximum amount' => [['orderId' => 'a', 'customerId' => 'b', 'amountCents' => Amount::MAX_CENTS]];
        // Whitespace is an id to the document and the domain; a validator
        // rule once counted it as empty and refused what the contract passed.
        yield 'carriage return id' => [['orderId' => "\r", 'customerId' => 'a', 'amountCents' => 1]];
        yield 'space ids' => [['orderId' => ' ', 'customerId' => ' ', 'amountCents' => 1]];
        yield 'zero amount' => [['orderId' => 'a', 'customerId' => 'b', 'amountCents' => 0]];
        yield 'one above the maximum' => [['orderId' => 'a', 'customerId' => 'b', 'amountCents' => Amount::MAX_CENTS + 1]];
        yield 'longest ids, multibyte' => [[
            'orderId' => str_repeat(string: 'я', times: OrderId::MAX_LENGTH),
            'customerId' => str_repeat(string: 'я', times: CustomerId::MAX_LENGTH),
            'amountCents' => 1999,
        ]];
        yield 'one character too long, multibyte' => [[
            'orderId' => str_repeat(string: 'я', times: OrderId::MAX_LENGTH + 1),
            'customerId' => 'b',
            'amountCents' => 1999,
        ]];
    }

    /**
     * The other side's generator: valid checkout requests built from the
     * document by property-testing-openapi all pass the Input.
     *
     * @param CaseData $case
     */
    #[Property(runs: 150)]
    public function everyRequestTheDocumentCallsValidPassesTheInput(array $case): void
    {
        $request = new RequestMaterializer(new RequestFactory(), new StreamFactory())
            ->materialize(self::contract()->operation(key: 'checkout.create'), $case);
        /** @var array<string, mixed> $body */
        $body = json_decode(json: (string) $request->getBody(), associative: true, flags: JSON_THROW_ON_ERROR);

        Assert::true(CheckoutInputs::accepts(body: $body));
    }

    /** @return array<string, ArbitraryInterface> */
    public static function everyRequestTheDocumentCallsValidPassesTheInputGenerators(): array
    {
        return ['case' => new RequestCaseArbitrary()->forOperation(self::contract()->operation(key: 'checkout.create'))];
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function documentAccepts(array $payload): bool
    {
        return self::contract()->validateRequest(new Request(
            method: 'POST',
            uri: 'http://sandbox.invalid/api/v1/checkout',
            headers: ['Content-Type' => 'application/json'],
            body: json_encode($payload, JSON_THROW_ON_ERROR),
        ))->isValid();
    }

    private static function contract(): Contract
    {
        /** @var ContainerInterface $container */
        $container = require dirname(__DIR__, levels: 2) . '/bin/support/container.php';
        $contract = $container->get(Contract::class);
        \assert($contract instanceof Contract);

        return $contract;
    }
}
