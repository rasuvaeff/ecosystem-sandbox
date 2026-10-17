<?php

declare(strict_types=1);

use Rasuvaeff\RectorNamedLiterals\AddNameToLiteralArgumentRector;
use Rector\CodeQuality\Rector\CallLike\AddNameToNullArgumentRector;
use Rector\CodeQuality\Rector\Catch_\ThrowWithPreviousExceptionRector;
use Rector\CodingStyle\Rector\FuncCall\CallUserFuncArrayToVariadicRector;
use Rector\CodingStyle\Rector\FuncCall\FunctionFirstClassCallableRector;
use Rector\CodingStyle\Rector\Ternary\TernaryConditionVariableAssignmentRector;
use Rector\Config\RectorConfig;
use Rector\DeadCode\Rector\If_\RemoveUnusedNonEmptyArrayBeforeForeachRector;
use Rector\Php70\Rector\Ternary\TernaryToNullCoalescingRector;
use Rector\Php74\Rector\Closure\ClosureToArrowFunctionRector;
use Rector\Php80\Rector\NotIdentical\StrContainsRector;
use Rector\Php80\Rector\Switch_\ChangeSwitchToMatchRector;
use Rector\Php83\Rector\ClassConst\AddTypeToConstRector;
use Rector\Set\ValueObject\SetList;
use Rector\TypeDeclaration\Rector\Class_\TypedPropertyFromCreateMockAssignRector;
use Rector\TypeDeclaration\Rector\ClassMethod\AddReturnTypeDeclarationRector;
use Rector\TypeDeclaration\Rector\Property\TypedPropertyFromAssignsRector;
use Rector\ValueObject\PhpVersion;

return static function (RectorConfig $rectorConfig): void {
    $rectorConfig->paths(paths: [
        __DIR__ . '/src',
        __DIR__ . '/tests',
    ]);

    $rectorConfig->phpVersion(phpVersion: PhpVersion::PHP_85);

    $rectorConfig->sets(sets: [
        SetList::TYPE_DECLARATION,
        SetList::CODE_QUALITY,
    ]);

    $rectorConfig->rules(rectorClasses: [
        FunctionFirstClassCallableRector::class,
        TernaryConditionVariableAssignmentRector::class,
        StrContainsRector::class,
        ChangeSwitchToMatchRector::class,
        ClosureToArrowFunctionRector::class,
        AddTypeToConstRector::class,
        TernaryToNullCoalescingRector::class,
        AddReturnTypeDeclarationRector::class,
        TypedPropertyFromAssignsRector::class,
        CallUserFuncArrayToVariadicRector::class,
        RemoveUnusedNonEmptyArrayBeforeForeachRector::class,
    ]);

    $rectorConfig->importNames(importNames: true, importDocBlockNames: false);
    $rectorConfig->importShortClasses(importShortClasses: false);

    // Our rule (rasuvaeff/rector-named-literals): every literal argument gets
    // its parameter name; null literals through Rector's own rule.
    $rectorConfig->ruleWithConfiguration(
        rectorClass: AddNameToLiteralArgumentRector::class,
        configuration: [
            AddNameToLiteralArgumentRector::BOOL => true,
            AddNameToLiteralArgumentRector::NUMERIC => true,
            AddNameToLiteralArgumentRector::STRING => true,
        ],
    );
    $rectorConfig->rule(rectorClass: AddNameToNullArgumentRector::class);

    $rectorConfig->skip(skip: [
        TypedPropertyFromCreateMockAssignRector::class,
        ThrowWithPreviousExceptionRector::class,
    ]);
};
