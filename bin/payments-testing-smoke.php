<?php

declare(strict_types=1);

use Rasuvaeff\Payments\CreatePaymentRequest;
use Rasuvaeff\Payments\Money;
use Rasuvaeff\Payments\OperationId;
use Rasuvaeff\Payments\PaymentState;
use Rasuvaeff\PaymentsTesting\FakeGatewayConfig;
use Rasuvaeff\PaymentsTesting\FakePaymentGateway;
use Rasuvaeff\PaymentsTesting\PaymentGatewayAssertions;

require dirname(__DIR__) . '/vendor/autoload.php';

$gateway = new FakePaymentGateway(new FakeGatewayConfig(createState: PaymentState::RequiresAction));
$request = new CreatePaymentRequest(
    operationId: new OperationId('payments-testing-smoke'),
    amount: Money::minorUnits(1999, 'USD'),
    description: 'Payments testing contract smoke',
    metadata: ['orderId' => 'payments-testing-smoke'],
    idempotencyKey: 'payments-testing-smoke-key',
);

$attempt = PaymentGatewayAssertions::assertCreatePaymentIdempotency($gateway, $request);
PaymentGatewayAssertions::assertCapabilities($gateway);

if ($attempt->state !== PaymentState::RequiresAction) {
    throw new RuntimeException('Fake gateway did not preserve the configured payment state');
}

printf(
    "PAYMENTS TESTING PASS: state=%s provider=%s idempotency=verified\n",
    $attempt->state->value,
    $attempt->provider->value,
);
