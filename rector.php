<?php

declare(strict_types=1);

use Rector\CodingStyle\Rector\ClassConst\RemoveFinalFromConstRector;
use Rector\Config\RectorConfig;
use Rector\Php55\Rector\ClassConstFetch\StaticToSelfOnFinalClassRector;
use Rector\Php81\Rector\Array_\FirstClassCallableRector;
use Rector\Privatization\Rector\Class_\FinalizeTestCaseClassRector;
use Rector\Privatization\Rector\ClassMethod\PrivatizeFinalClassMethodRector;
use Rector\TypeDeclaration\Rector\Class_\TypedPropertyFromCreateMockAssignRector;
use Rector\TypeDeclaration\Rector\Property\TypedPropertyFromStrictConstructorRector;
use Rector\TypeDeclaration\Rector\StmtsAwareInterface\DeclareStrictTypesRector;

return RectorConfig::configure()
    ->withPaths(
        [
            __DIR__.'/config',
            __DIR__.'/src',
            __DIR__.'/tests',
        ],
    )
    ->withRules(
        [
            TypedPropertyFromStrictConstructorRector::class,
            DeclareStrictTypesRector::class,
            FinalizeTestCaseClassRector::class,
            PrivatizeFinalClassMethodRector::class,
            RemoveFinalFromConstRector::class,
        ],
    )
    ->withPhpSets(
        php83: true,
    )
    ->withComposerBased(
        phpunit: true,
        symfony: true,
    )
    ->withTypeCoverageLevel(10)
    ->withDeadCodeLevel(10)
    ->withCodeQualityLevel(10)
    ->withPHPStanConfigs(
        [
            __DIR__.'/phpstan.neon',
        ],
    )
    ->withSkip(
        [
            TypedPropertyFromCreateMockAssignRector::class,
            FirstClassCallableRector::class => [
                __DIR__.'/tests/TransactionNamingStrategy/ControllerNamingStrategyTest.php',
            ],
        ],
    )
    ->withTreatClassesAsFinal()
;
