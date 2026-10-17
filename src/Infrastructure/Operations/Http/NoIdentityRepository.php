<?php

declare(strict_types=1);

namespace App\Infrastructure\Operations\Http;

use Yiisoft\Auth\IdentityInterface;
use Yiisoft\Auth\IdentityRepositoryInterface;

/**
 * The sandbox has no users: the admin pages run without authentication, so
 * CurrentUser is always the guest and a flag or setting change is credited to
 * nobody (the packages' change events carry `actor: null`).
 */
final readonly class NoIdentityRepository implements IdentityRepositoryInterface
{
    public function findIdentity(string $id): ?IdentityInterface
    {
        return null;
    }
}
