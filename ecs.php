<?php

declare(strict_types=1);

use PhpCsFixer\Fixer\ClassNotation\ClassAttributesSeparationFixer;
use PhpCsFixer\Fixer\ClassNotation\OrderedTypesFixer;
use PhpCsFixer\Fixer\ClassNotation\SingleClassElementPerStatementFixer;
use PhpCsFixer\Fixer\FunctionNotation\MultilinePromotedPropertiesFixer;
use PhpCsFixer\Fixer\Import\NoUnusedImportsFixer;
use PhpCsFixer\Fixer\LanguageConstruct\NullableTypeDeclarationFixer;
use PhpCsFixer\Fixer\Operator\ConcatSpaceFixer;
use PhpCsFixer\Fixer\Operator\OperatorLinebreakFixer;
use PhpCsFixer\Fixer\Whitespace\TypesSpacesFixer;
use Symplify\CodingStandard\Fixer\LineLength\LineLengthFixer;
use Symplify\EasyCodingStandard\Config\ECSConfig;

return ECSConfig::configure()
    ->withPaths(
        [
            __DIR__.'/config',
            __DIR__.'/src',
            __DIR__.'/tests',
            __DIR__.'/ecs.php',
        ],
    )
    ->withPhpCsFixerSets(
        perCS20: true,
        perCS20Risky: true,
    )
    ->withRules(
        [
            NoUnusedImportsFixer::class,
            NullableTypeDeclarationFixer::class,
            OperatorLinebreakFixer::class,
            SingleClassElementPerStatementFixer::class,
            TypesSpacesFixer::class,
            MultilinePromotedPropertiesFixer::class,
        ],
    )
    ->withConfiguredRule(
        ClassAttributesSeparationFixer::class,
        [
            'elements' => [
                'const' => 'one',
                'method' => 'one',
                'property' => 'only_if_meta',
                'trait_import' => 'none',
                'case' => 'none',
            ],
        ],
    )
    ->withConfiguredRule(
        LineLengthFixer::class,
        ['max_line_length' => 120, 'break_long_lines' => true, 'inline_short_lines' => false],
    )
    ->withConfiguredRule(
        OrderedTypesFixer::class,
        ['null_adjustment' => 'always_last', 'sort_algorithm' => 'none'],
    )
    ->withConfiguredRule(
        ConcatSpaceFixer::class,
        ['spacing' => 'none'],
    )
    // Prefix all native function calls with "\" inside namespaced code so the engine
    // skips the userland-function lookup. Note: this fixer cannot touch first-class
    // callable syntax (e.g. `strval(...)`) — php-cs-fixer's FunctionsAnalyzer excludes
    // T_FIRST_CLASS_CALLABLE from function *calls*, so those must be prefixed by hand
    // (`\strval(...)`) to stay consistent with the rest of the codebase.
    ->withConfiguredRule(
        PhpCsFixer\Fixer\FunctionNotation\NativeFunctionInvocationFixer::class,
        [
            'scope' => 'namespaced',
            'include' => ['@all'],
            'exclude' => ['time', 'microtime', 'sleep', 'usleep', 'gmdate', 'date'],
        ],
    )
    ->withConfiguredRule(
        PhpCsFixer\Fixer\ConstantNotation\NativeConstantInvocationFixer::class,
        ['scope' => 'namespaced'],
    )
;
