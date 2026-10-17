<?php

declare(strict_types=1);

use Rasuvaeff\Understudy\Testo\UnderstudyPlugin;
use Testo\Application\Config\ApplicationConfig;
use Testo\Application\Config\FinderConfig;
use Testo\Application\Config\SuiteConfig;

return new ApplicationConfig(
    src: ['src'],
    suites: [
        new SuiteConfig(
            name: 'Unit',
            location: new FinderConfig(include: ['tests/Unit'], exclude: []),
            plugins: [new UnderstudyPlugin()],
        ),
        new SuiteConfig(
            name: 'Property',
            location: new FinderConfig(include: ['tests/Property']),
        ),
        // What a mutant run executes: everything that needs no running
        // service. Integration needs a migrated PostgreSQL and stays out.
        new SuiteConfig(
            name: 'Mutation',
            location: new FinderConfig(include: ['tests/Unit', 'tests/Property']),
            plugins: [new UnderstudyPlugin()],
        ),
        new SuiteConfig(
            name: 'Integration',
            location: new FinderConfig(include: ['tests/Integration']),
        ),
    ],
);
