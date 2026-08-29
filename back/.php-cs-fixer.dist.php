<?php

declare(strict_types=1);

/**
 * ---------------------------------------------------------------------------
 * Formatage PSR-12.
 *
 * Le style cesse d'être un sujet de revue : la machine tranche. Les règles
 * ajoutées au-delà de PSR-12 sont celles qui évitent des diffs bruyants
 * (ordre des imports, virgule finale) ou des erreurs discrètes (comparaison
 * stricte).
 * ---------------------------------------------------------------------------
 */

$finder = PhpCsFixer\Finder::create()
    ->in([__DIR__ . '/src', __DIR__ . '/tests', __DIR__ . '/routes', __DIR__ . '/public'])
    ->name('*.php');

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(true)
    ->setRules([
        '@PSR12' => true,

        // Toujours des types stricts : une conversion silencieuse est un bug
        // qui attend son heure.
        'declare_strict_types' => true,
        'strict_comparison' => true,
        'strict_param' => true,

        // Imports : triés, sans alias inutilisé, jamais en une seule ligne.
        'ordered_imports' => ['sort_algorithm' => 'alpha'],
        'no_unused_imports' => true,
        'single_import_per_statement' => true,

        // Virgule finale sur les appels multilignes : ajouter un argument ne
        // touche plus la ligne précédente dans le diff.
        'trailing_comma_in_multiline' => ['elements' => ['arrays', 'arguments', 'parameters']],

        // Cohérence d'écriture
        'array_syntax' => ['syntax' => 'short'],
        'concat_space' => ['spacing' => 'one'],
        'single_quote' => true,
        'no_superfluous_phpdoc_tags' => false,
        'phpdoc_align' => false,
        'phpdoc_separation' => false,
    ])
    ->setFinder($finder);
