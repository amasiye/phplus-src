<?php

declare(strict_types=1);

use Amasiye\Ppphp\Analysis\Browser\CompilerAnalysisProtocol;
use Amasiye\Ppphp\Analysis\Browser\CompilerAnalysisRequest;
use Amasiye\Ppphp\Analysis\Browser\CompilerAnalysisRequestDecoder;
use Amasiye\Ppphp\Analysis\Capability\AnalysisCapabilityCatalog;

function writeCompilerAnalysisProject(string $root, string $source): void
{
    mkdir($root . '/src', 0777, true);
    file_put_contents($root . '/ppphp.json', json_encode([
        'source' => ['src'],
        'output' => 'build/ppphp',
        'cache' => '.ppphp-cache',
        'targetPhpVersion' => '8.4',
        'stubs' => [],
        'exclude' => ['vendor', 'build', '.ppphp-cache'],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
    file_put_contents($root . '/src/main.ppphp', $source);
}

test('compiler analysis request version two accepts only compiler-owned checks', function (): void {
    $decoder = new CompilerAnalysisRequestDecoder();
    $request = $decoder->decode(json_encode([
        'version' => 2,
        'requestId' => 'compiler-check',
        'action' => 'analyze',
        'operation' => 'check',
        'analysis' => ['engine' => 'compiler'],
        'selection' => ['path' => null],
    ], JSON_THROW_ON_ERROR));

    expect($request->requestId)->toBe('compiler-check')
        ->and($request->path)->toBeNull()
        ->and(fn () => $decoder->decode(json_encode([
            'version' => 2,
            'requestId' => 'build',
            'action' => 'analyze',
            'operation' => 'build',
            'analysis' => ['engine' => 'compiler'],
            'selection' => ['path' => null],
        ], JSON_THROW_ON_ERROR)))->toThrow(InvalidArgumentException::class, 'supports check only')
        ->and(fn () => $decoder->decode(json_encode([
            'version' => 2,
            'requestId' => 'missing-path',
            'action' => 'analyze',
            'operation' => 'check',
            'analysis' => ['engine' => 'compiler'],
            'selection' => [],
        ], JSON_THROW_ON_ERROR)))->toThrow(InvalidArgumentException::class, 'malformed')
        ->and(fn () => $decoder->decode(str_repeat(' ', CompilerAnalysisRequest::MAXIMUM_TRANSPORT_BYTES + 1)))
        ->toThrow(InvalidArgumentException::class, 'too large');
});

test('compiler analysis completes valid and invalid projects without materializing backend state', function (string $source, array $codes): void {
    $root = $this->createTemporaryDirectory();
    writeCompilerAnalysisProject($root, $source);
    $response = (new CompilerAnalysisProtocol())->analyze(
        new CompilerAnalysisRequest('compiler-only', null),
        $root,
    )->toArray();

    expect($response['status'])->toBe('complete')
        ->and($response['engine'])->toBe('compiler')
        ->and($response['completeness'])->toBe('compilerCore')
        ->and($response['catalogVersion'])->toBe(AnalysisCapabilityCatalog::VERSION)
        ->and($response['fullParity'])->toBeFalse()
        ->and($response['uncoveredRequiredCapabilities'])->toBe((new AnalysisCapabilityCatalog())->uncoveredRequiredCapabilityIds)
        ->and(array_column($response['diagnostics']['diagnostics'], 'code'))->toBe($codes)
        ->and($response)->not->toHaveKeys(['phpStan', 'continuation', 'command'])
        ->and(is_dir($root . '/.ppphp-cache/analysis'))->toBeFalse()
        ->and(is_file($root . '/.ppphp-cache/analysis/phpstan.neon'))->toBeFalse()
        ->and(is_file($root . '/.ppphp-cache/analysis/result.json'))->toBeFalse();
})->with([
    'valid source' => ["<?php\nfunction identity(int \$value): int { return \$value; }\n", []],
    'invalid source' => ["<?php\nfunction invalid(): void { int \$value = 'wrong'; }\n", ['P2008']],
]);

test('compiler analysis enforces source count and total source byte limits', function (string $limit): void {
    $root = $this->createTemporaryDirectory();
    $this->writeConfiguration($root, ['stubs' => []]);

    if ($limit === 'sourceCount') {
        for ($index = 0; $index <= CompilerAnalysisRequest::MAXIMUM_SOURCE_FILES; $index++) {
            $this->writeFile($root . sprintf('/src/file-%03d.ppphp', $index), "<?php\n");
        }
    } else {
        $this->writeFile(
            $root . '/src/main.ppphp',
            "<?php\n/*" . str_repeat('x', CompilerAnalysisRequest::MAXIMUM_SOURCE_BYTES) . "*/\n",
        );
    }

    $response = (new CompilerAnalysisProtocol())->analyze(
        new CompilerAnalysisRequest('resource-limit', null),
        $root,
    )->toArray();
    $json = json_encode($response, JSON_THROW_ON_ERROR);

    expect($response['status'])->toBe('error')
        ->and($response['error']['code'])->toBe('resource-limit-exceeded')
        ->and($response['error']['limit'])->toBe($limit)
        ->and(json_decode($json, true, flags: JSON_THROW_ON_ERROR))->toBeArray();
})->with(['sourceCount', 'sourceBytes']);

test('compiler analysis rejects excessive diagnostics without truncating its JSON response', function (): void {
    $root = $this->createTemporaryDirectory();
    $source = "<?php\nfunction invalid(): void {\n";

    for ($index = 0; $index <= CompilerAnalysisRequest::MAXIMUM_DIAGNOSTICS; $index++) {
        $source .= sprintf("\$missing%d = %d;\n", $index, $index);
    }

    $source .= "}\n";
    writeCompilerAnalysisProject($root, $source);
    $response = (new CompilerAnalysisProtocol())->analyze(
        new CompilerAnalysisRequest('diagnostic-limit', null),
        $root,
    )->toArray();
    $json = json_encode($response, JSON_THROW_ON_ERROR);

    expect($response['status'])->toBe('error')
        ->and($response['error']['limit'])->toBe('diagnosticCount')
        ->and(json_decode($json, true, flags: JSON_THROW_ON_ERROR))->toBe($response);
});

test('compiler analysis parses user code as data and never executes it', function (): void {
    $root = $this->createTemporaryDirectory();
    $marker = $root . '/executed.txt';
    writeCompilerAnalysisProject($root, sprintf(
        "<?php\nfile_put_contents(%s, 'executed');\n",
        var_export($marker, true),
    ));
    $response = (new CompilerAnalysisProtocol())->analyze(
        new CompilerAnalysisRequest('no-execution', null),
        $root,
    )->toArray();

    expect($response['status'])->toBe('complete')
        ->and(file_exists($marker))->toBeFalse();
});
