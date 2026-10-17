<?php

declare(strict_types=1);

namespace App\Domain\Shared\Exception;

/**
 * A rule of the domain refused the operation. An endpoint maps each concrete
 * one to its HTTP problem; anything else reaching the edge is a 500.
 */
abstract class DomainException extends \RuntimeException {}
