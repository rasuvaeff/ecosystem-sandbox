<?php

declare(strict_types=1);

namespace App\Domain\Payments;

use App\Domain\Ordering\OrderId;
use App\Domain\Payments\Exception\ChargeRejectedException;
use App\Domain\Payments\Exception\GatewayUnavailableException;

interface PaymentGateway
{
    /**
     * @throws ChargeRejectedException the gateway refused the request itself; repeating it cannot help
     * @throws GatewayUnavailableException no answer within the budget; a later attempt may succeed
     */
    public function charge(Charge $charge): ChargeResult;

    /**
     * Asks the bank what became of the charge made under the key.
     *
     * @param non-empty-string $gatewayKey
     *
     * @throws GatewayUnavailableException
     */
    public function find(OrderId $orderId, string $gatewayKey): ChargeFinding;
}
