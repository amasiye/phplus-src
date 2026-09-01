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

    expect($contents)->not->toContain('Amasiye\\Ppphp\\Analysis\\PhpStan');
});
