<?php

declare(strict_types=1);

use HttpSoft\Message\RequestFactory;
use HttpSoft\Message\StreamFactory;
use Rasuvaeff\OpenApiContract\Contract;
use Rasuvaeff\PropertyTesting\OpenApi\NegativeRequestCaseArbitrary;
use Rasuvaeff\PropertyTesting\OpenApi\RequestCaseArbitrary;
use Rasuvaeff\PropertyTesting\OpenApi\RequestMaterializer;
use Rasuvaeff\PropertyTesting\Random;

require dirname(__DIR__) . '/vendor/autoload.php';

// The document generated from the actions' OpenAPI attributes, the same one
// the running application validates the checkout against.
/** @var Psr\Container\ContainerInterface $container */
$container = require __DIR__ . '/support/container.php';
$contract = $container->get(Contract::class);
\assert($contract instanceof Contract);
$operation = $contract->operation('checkout.create');
$materializer = new RequestMaterializer(new RequestFactory(), new StreamFactory());
$valid = new RequestCaseArbitrary()->forOperation($operation);
$negative = new NegativeRequestCaseArbitrary()->forOperation($operation);
$random = new Random(20261013);

$validCount = 0;
for ($i = 0; $i < 100; ++$i) {
    $case = $valid->generate($random)->value;
    $request = $materializer->materialize($operation, $case);
    $result = $contract->validateRequest($request);
    if (!$result->isValid()) {
        throw new RuntimeException('Generated valid case was rejected: ' . $result->format());
    }
    ++$validCount;
}

$negativeCount = 0;
for ($i = 0; $i < 50; ++$i) {
    $case = $negative->generate($random)->value;
    $request = $materializer->materialize($operation, $case);
    if ($contract->validateRequest($request)->isValid()) {
        throw new RuntimeException('Generated negative case was accepted');
    }
    ++$negativeCount;
}

echo "OPENAPI PROPERTY PASS: valid={$validCount} negative={$negativeCount} seed=20261013 shrinkable=yes\n";
