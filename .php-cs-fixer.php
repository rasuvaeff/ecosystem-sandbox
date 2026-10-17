<?php

declare(strict_types=1);

use PhpCsFixer\Config;
use PhpCsFixer\Finder;
use PhpCsFixer\Runner\Parallel\ParallelConfigFactory;

ini_set(option: 'memory_limit', value: '512M');

$finder = new Finder()
    ->in([
        __DIR__ . '/bin',
        __DIR__ . '/config',
        __DIR__ . '/mock-payment',
        __DIR__ . '/mock-webhook',
        __DIR__ . '/resources',
        __DIR__ . '/src',
        __DIR__ . '/tests',
    ])
    ->append([
        __DIR__ . '/public/index.php',
        __DIR__ . '/.php-cs-fixer.php',
        __DIR__ . '/rector.php',
        __DIR__ . '/yii',
    ]);

return new Config()
    ->setParallelConfig(ParallelConfigFactory::detect())
    ->setUsingCache(false)
    ->setRules([
        '@PER-CS3.0' => true,
        '@PHP85Migration' => true,
        'blank_line_before_statement' => ['statements' => [
            'continue',
            'declare',
            'default',
            'return',
            'throw',
            'try',
            'while',
        ]],
        'increment_style' => false,
        'blank_line_between_import_groups' => false,
        'function_declaration' => ['closure_function_spacing' => 'one'],
        'semicolon_after_instruction' => false,
        'no_alternative_syntax' => false,
        'class_attributes_separation' => [
            'elements' => [
                'case' => 'only_if_meta',
                'const' => 'only_if_meta',
                'method' => 'one',
                'property' => 'one',
                'trait_import' => 'only_if_meta',
            ],
        ],
        'method_chaining_indentation' => false,
        'multiline_whitespace_before_semicolons' => ['strategy' => 'no_multi_line'],
        'no_trailing_whitespace_in_string' => false,
        'nullable_type_declaration_for_default_null_value' => true,
        'ordered_class_elements' => ['order' => ['use_trait', 'case', 'constant', 'property', 'method']],
        'ordered_imports' => ['imports_order' => ['class', 'function', 'const']],
        'ordered_types' => ['sort_algorithm' => 'none'],
        'php_unit_internal_class' => false,
        'php_unit_strict' => false,
        'phpdoc_add_missing_param_annotation' => false,
        'phpdoc_align' => false,
        'phpdoc_no_alias_tag' => ['replacements' => [
            'property-write' => 'property',
            'type' => 'var',
            'link' => 'see',
        ]],
        'phpdoc_to_comment' => false,
        'phpdoc_types_order' => false,
        'return_assignment' => false,

        'trailing_comma_in_multiline' => ['after_heredoc' => true, 'elements' => ['arrays', 'arguments', 'parameters']],
        'yoda_style' => ['equal' => false, 'identical' => false, 'less_and_greater' => false],

        'indentation_type' => true,
        'array_indentation' => true,
        'binary_operator_spaces' => [
            'default' => 'single_space',
        ],
        'single_blank_line_at_eof' => false,
        'final_class' => false,
        'single_space_around_construct' => true,
        'control_structure_braces' => true,
        'control_structure_continuation_position' => false,
        'declare_parentheses' => true,
        'no_multiple_statements_per_line' => true,
        'statement_indentation' => true,
        'heredoc_indentation' => false,
        'no_extra_blank_lines' => true,
        'concat_space' => [
            'spacing' => 'one',
        ],
        'echo_tag_syntax' => ['format' => 'short'],
        'native_function_invocation' => false,
        'no_unused_imports' => true,
        'no_trailing_whitespace' => true,
        'no_whitespace_in_blank_line' => true,
        'single_import_per_statement' => true,
        'single_line_after_imports' => true,
        'no_break_comment' => false,
        'no_spaces_around_offset' => true,
        'no_trailing_comma_in_singleline' => true,
        'no_whitespace_before_comma_in_array' => true,
        'trim_array_spaces' => true,
        'whitespace_after_comma_in_array' => true,
        'type_declaration_spaces' => true,
        'single_line_comment_style' => [
            'comment_types' => ['hash'],
        ],
        'fully_qualified_strict_types' => false,
        'global_namespace_import' => false,
        'no_leading_import_slash' => true,
        'no_unneeded_import_alias' => true,
        'phpdoc_indent' => true,
        'phpdoc_inline_tag_normalizer' => true,
        'phpdoc_no_access' => true,
        'phpdoc_order' => true,
        'phpdoc_scalar' => true,
        'phpdoc_separation' => false,
        'phpdoc_single_line_var_spacing' => true,
        'phpdoc_summary' => false,
        'phpdoc_trim' => true,
        'phpdoc_types' => true,
        'phpdoc_var_without_name' => true,
        'heredoc_to_nowdoc' => false,
    ])
    ->setFinder($finder);
