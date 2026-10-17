<?php

declare(strict_types=1);

namespace App\Domain\Payments\Exception;

use App\Domain\Shared\Exception\DomainException;

final class ChargeRejectedException extends DomainException {}
