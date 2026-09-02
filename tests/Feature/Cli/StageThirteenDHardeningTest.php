<?php

declare(strict_types=1);

use Atatusoft\Ppphp\Analysis\Browser\BrowserAnalysisProtocol;
use Atatusoft\Ppphp\Analysis\Browser\CompilerAnalysisProtocol;
use Atatusoft\Ppphp\Analysis\Browser\CompilerAnalysisRequest;
use Atatusoft\Ppphp\Analysis\Browser\PrepareAnalysisRequest;
use Atatusoft\Ppphp\Cli\Application;
use Atatusoft\Ppphp\Cli\Enumerations\ExitCode;
use Atatusoft\Ppphp\Editor\EditorSemanticTokenResolver;
use Atatusoft\Ppphp\Frontend\PpphpParser;
use Atatusoft\Ppphp\Source\Enumerations\FileKind;
use Atatusoft\Ppphp\Source\SourceFile;
use Symfony\Component\Console\Tester\ApplicationTester;

function runStageThirteenDHardeningCommand(array $input): ApplicationTester
{
    $application = new Application();
    $application->setAutoExit(false);
    $tester = new ApplicationTester($application);
    $tester->run(['--no-ansi' => true, ...$input]);

    return $tester;
}

test('malformed source remains structured across compiler command surfaces', function (string $source): void {
    $root = $this->createTemporaryDirectory();
    $this->writeConfiguration($root);
    $this->writeFile($root . '/src/Malformed.ppphp', $source);
    $check = runStageThirteenDHardeningCommand([
        'command' => 'check',
        '--working-directory' => $root,
        '--format' => 'json',
    ]);
    $build = runStageThirteenDHardeningCommand([
        'command' => 'build',
        '--working-directory' => $root,
    ]);
    $dump = runStageThirteenDHardeningCommand([
        'command' => 'dump:ast',
        'path' => 'src/Malformed.ppphp',
        '--working-directory' => $root,
        '--format' => 'json',
    ]);
    $checkJson = json_decode($check->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
    $dumpJson = json_decode($dump->getDisplay(), true, flags: JSON_THROW_ON_ERROR);

    expect($check->getStatusCode())->toBe(ExitCode::DiagnosticsReported->value)
        ->and($build->getStatusCode())->toBe(ExitCode::DiagnosticsReported->value)
        ->and($dump->getStatusCode())->toBe(ExitCode::DiagnosticsReported->value)
        ->and($checkJson)->toBeArray()
        ->and($dumpJson)->toBeArray()
        ->and($check->getDisplay())->not->toContain('P9001')
        ->and($build->getDisplay())->not->toContain('P9001')
        ->and($dump->getDisplay())->not->toContain('P9001')
        ->and(strlen($build->getDisplay()))->toBeLessThan(8_192)
        ->and(file_exists($root . '/build/ppphp'))->toBeFalse();
})->with([
    'NUL byte' => "<?php\nfunction broken(\0",
    'unterminated string' => "<?php\n\$value = 'unterminated",
    'unterminated heredoc' => "<?php\n\$value = <<<TEXT\nunterminated",
    'broken interpolation' => "<?php\n\$value = \"{\$broken[\";",
    'invalid display byte' => "<?php\n// invalid \xff\nfunction broken(",
    'deep delimiters' => '<?php $value = ' . str_repeat('(', 512) . '1;',
    'long identifier' => '<?php function ' . str_repeat('identifier', 8_192) . '(',
    'large comment and CRLF' => "<?php\r\n/*" . str_repeat('x', 65_536) . "*/\r\nfunction broken(",
]);

test('malformed source remains bounded in editor and browser compiler protocols', function (): void {
    $root = $this->createTemporaryDirectory();
    $this->writeConfiguration($root);
    $source = "<?php\n// invalid \xff\nfunction broken(";
    $this->writeFile($root . '/src/Malformed.ppphp', $source);
    $file = new SourceFile(
        $root . '/src/Malformed.ppphp',
        'src/Malformed.ppphp',
        FileKind::Ppphp,
        $source,
    );
    $parse = (new PpphpParser())->parse($file);
    $tokens = $parse->parsedFile === null
        ? []
        : (new EditorSemanticTokenResolver())->resolve($parse->parsedFile);
    $compiler = (new CompilerAnalysisProtocol())->analyze(
        new CompilerAnalysisRequest('malformed-compiler', null),
        $root,
    )->toArray();
    $browser = (new BrowserAnalysisProtocol())->prepare(
        new PrepareAnalysisRequest('malformed-browser', 'check', null),
        $root,
    );
    $compilerJson = json_encode($compiler, JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
    $browserJson = json_encode($browser->diagnostics, JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);

    expect($parse->diagnostics->hasErrors)->toBeTrue()
        ->and($tokens)->toBe([])
        ->and($compiler['status'] ?? null)->toBe('complete')
        ->and($browser->status)->toBe('diagnostics')
        ->and($browser->continuation)->toBeNull()
        ->and($compilerJson)->toBeString()
        ->and($browserJson)->toBeString()
        ->and(strlen($compilerJson))->toBeLessThan(16_384)
        ->and(strlen($browserJson))->toBeLessThan(16_384)
        ->and(file_exists($root . '/build/ppphp'))->toBeFalse();
});

test('a read-only project root produces structured operation diagnostics', function (): void {
    if (DIRECTORY_SEPARATOR !== '/' || !function_exists('chmod')) {
        $this->markTestSkipped('Reliable POSIX read-only assertions are unavailable.');
    }

    $root = $this->createTemporaryDirectory();
    $this->writeConfiguration($root);
    $this->writeFile($root . '/src/Value.ppphp', "<?php\nfunction readOnlyValue(): int { return 1; }\n");
    chmod($root, 0555);
    clearstatcache(true, $root);

    if (is_writable($root)) {
        chmod($root, 0755);
        $this->markTestSkipped('The current filesystem does not enforce the requested read-only mode.');
    }

    try {
        $check = runStageThirteenDHardeningCommand([
            'command' => 'check',
            '--working-directory' => $root,
        ]);
        $build = runStageThirteenDHardeningCommand([
            'command' => 'build',
            '--working-directory' => $root,
        ]);

        expect($check->getStatusCode())->toBe(ExitCode::DiagnosticsReported->value)
            ->and($build->getStatusCode())->toBe(ExitCode::OutputValidationFailed->value)
            ->and($check->getDisplay())->toContain('P7005')
            ->and($build->getDisplay())->toContain('P7005')
            ->and($check->getDisplay())->not->toContain('P9001')
            ->and($build->getDisplay())->not->toContain('P9001')
            ->and(file_exists($root . '/build/ppphp'))->toBeFalse();
    } finally {
        chmod($root, 0755);
    }
});
