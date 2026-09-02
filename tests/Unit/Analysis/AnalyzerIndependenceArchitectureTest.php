<?php

declare(strict_types=1);

test('compiler-owned analysis has no supplemental backend or process dependency', function (): void {
    $root = dirname(__DIR__, 3);
    $paths = [
        $root . '/src/Analysis/CompilerProjectAnalysis.php',
        $root . '/src/Analysis/CompilerProjectAnalyzer.php',
        $root . '/src/Analysis/DeclarationContextCollector.php',
        $root . '/src/Analysis/Browser/CompilerAnalysisProtocol.php',
        $root . '/src/Analysis/Browser/CompilerAnalysisRequest.php',
        $root . '/src/Analysis/Browser/CompilerAnalysisRequestDecoder.php',
        $root . '/src/Analysis/Browser/CompilerAnalysisResponse.php',
    ];
    $contents = implode("\n", array_map(static fn (string $path): string => (string) file_get_contents($path), $paths));

    expect($contents)->not->toContain(
        'PhpStan',
        'Symfony\\Component\\Process',
        'AnalysisContinuation',
        'AnalysisProject',
        'proc_open',
        'result.json',
        'phpstan.neon',
    );
});

test('the semantic core does not depend on the PHPStan adapter namespace', function (): void {
    $root = dirname(__DIR__, 3) . '/src/Semantic';
    $contents = '';
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));

    foreach ($iterator as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            $contents .= (string) file_get_contents($file->getPathname());
        }
    }

    expect($contents)->not->toContain('Atatusoft\\Ppphp\\Analysis\\PhpStan');
});

test('the supplemental backend has an explicit package optionalization boundary', function (): void {
    $root = dirname(__DIR__, 3);
    $composer = json_decode(
        (string) file_get_contents($root . '/composer.json'),
        true,
        flags: JSON_THROW_ON_ERROR,
    );
    $core = implode("\n", array_map(
        static fn (string $path): string => (string) file_get_contents($root . '/' . $path),
        [
            'src/Analysis/CompilerProjectAnalyzer.php',
            'src/Analysis/DeclarationContextCollector.php',
            'src/Interop/Composer/ComposerDependencyDeclarationLoader.php',
            'src/Interop/Php/Signature/PhpSignaturePackageLoader.php',
            'src/Semantic/SemanticAnalyzer.php',
        ],
    ));

    expect($composer['require'])->toHaveKeys([
        'nikic/php-parser',
        'phpstan/phpdoc-parser',
        'phpstan/phpstan',
        'symfony/console',
        'symfony/process',
    ])->and($composer['require-dev'])->toHaveKey('pestphp/pest')
        ->and($core)->not->toContain(
            'Atatusoft\\Ppphp\\Analysis\\PhpStan',
            'PHPStan\\',
            'Symfony\\Component\\Process',
        );
});
