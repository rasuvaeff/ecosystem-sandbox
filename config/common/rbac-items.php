<?php

declare(strict_types=1);

use App\Domain\Assistant\Access\Permission;
use App\Domain\Assistant\Access\Role;

// yiisoft/rbac-php items, built from the domain's enums: the permission and
// role names are defined once.
$items = [];

foreach (Permission::cases() as $permission) {
    $items[] = ['name' => $permission->value, 'type' => 'permission'];
}

foreach (Role::cases() as $role) {
    $items[] = [
        'name' => $role->value,
        'type' => 'role',
        'children' => array_map(
            callback: static fn (Permission $permission): string => $permission->value,
            array: $role->permissions(),
        ),
    ];
}

return $items;
