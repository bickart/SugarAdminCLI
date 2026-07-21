<?php

/** @var PhpCsFixer\Config $config */

$finder = (new PhpCsFixer\Finder())
    ->in([__DIR__ . '/crm/custom', __DIR__ . '/src', __DIR__ . '/tests', __DIR__ . '/bin'])
    ->notName('*.ext.php')
    ->notName('*orderMapping.php');

$config = getRules($finder);

return $config->setFinder($finder);


function getRules(PhpCsFixer\Finder $finder): PhpCsFixer\ConfigInterface
{
    return (new PhpCsFixer\Config())
        ->setUnsupportedPhpVersionAllowed(true)
        ->setRules([
            '@PHP8x4Migration' => true,
            '@Symfony' => true,
            'cast_spaces' => false,
            'blank_line_after_opening_tag' => false,
            'linebreak_after_opening_tag' => false,
            'blank_lines_before_namespace' => ['min_line_breaks' => 1, 'max_line_breaks' => 1],
            'blank_line_before_statement' => false,
            'single_line_throw' => false,
            'class_definition' => ['single_line' => true, 'single_item_single_line' => true, 'multi_line_extends_each_single_line' => false],
            'braces_position' => ['classes_opening_brace' => 'same_line'],
            'new_with_parentheses' => false,
            'no_unneeded_braces' => true,
            'no_extra_blank_lines' => ['tokens' => ['break', 'case', 'continue', 'curly_brace_block', 'default', 'extra', 'parenthesis_brace_block', 'return', 'square_brace_block', 'switch', 'throw', 'use']],
            'class_attributes_separation' => ['elements' => ['const' => 'none', 'method' => 'one', 'property' => 'one', 'trait_import' => 'none']],
            'concat_space' => ['spacing' => 'none'],
            'binary_operator_spaces' => ['default' => 'at_least_single_space'],
            'operator_linebreak' => ['position' => 'end', 'only_booleans' => true],
            'not_operator_with_space' => false,
            'no_whitespace_in_blank_line' => false,
            'no_trailing_whitespace' => false,
            'no_trailing_whitespace_in_comment' => false,
            'increment_style' => false,
            'fully_qualified_strict_types' => ['import_symbols' => true, 'leading_backslash_in_global_namespace' => true],
            'single_import_per_statement' => false,
            'group_import' => false,
            'ordered_imports' => true,
            'phpdoc_summary' => false,
            'phpdoc_align' => ['align' => 'left'],
            'phpdoc_tag_type' => ['tags' => ['inheritDoc' => 'annotation']],
            'phpdoc_separation' => [
                'groups' => [
                    ['deprecated', 'link', 'see', 'since'],
                    ['author', 'copyright', 'license'],
                    ['category', 'package', 'subpackage'],
                    ['property', 'property-read', 'property-write'],
                    ['param'],
                    ['return'],
                ],
            ],
            'phpdoc_to_comment' => false,
            'yoda_style' => false,
            'no_superfluous_phpdoc_tags' => false,
            'trailing_comma_in_multiline' => ['elements' => ['array_destructuring', 'arrays']],
        ])->setFinder($finder);
}
