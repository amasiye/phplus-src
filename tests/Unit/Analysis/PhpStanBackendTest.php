<?php

declare(strict_types=1);

use Amasiye\Ppphp\Analysis\PhpStan\Exceptions\PhpStanExecutionException;
use Amasiye\Ppphp\Analysis\AnalysisFile;
use Amasiye\Ppphp\Analysis\AnalysisProject;
use Amasiye\Ppphp\Analysis\AnalysisSourceMap;
use Amasiye\Ppphp\Analysis\PhpStan\PhpStanProcessResult;
use Amasiye\Ppphp\Analysis\PhpStan\PhpStanProcessRunner;
use Amasiye\Ppphp\Analysis\PhpStan\PhpStanProjectAnalyzer;
use Amasiye\Ppphp\Analysis\PhpStan\PhpStanResultParser;
use Amasiye\Ppphp\Analysis\PhpStan\PhpStanDiagnosticMapper;
use Amasiye\Ppphp\Analysis\PhpStan\PhpStanFinding;
use Amasiye\Ppphp\Diagnostics\Diagnostic;
use Amasiye\Ppphp\Diagnostics\Enumerations\DiagnosticCode;
use Amasiye\Ppphp\Source\Enumerations\FileKind;
use Amasiye\Ppphp\Source\SourceFile;
use Amasiye\Ppphp\Transpilation\GeneratedSourceMap;

/** @return list<string> */
function backendDiagnosticCodes(iterable $diagnostics): array
{
    return array_map(
        static fn (Diagnostic $diagnostic): string => $diagnostic->code->value,
        iterator_to_array($diagnostics),
    );
}

function createBackendAnalysisProject(string $root): AnalysisProject
{
    $source = new SourceFile($root . '/src/Feature.ppp', 'src/Feature.ppp', FileKind::Ppp, "<?php\nfunction feature(): void {}\n");
    $analysisPath = $root . '/analysis/selected/root/Feature.php';
    $directory = dirname($analysisPath);
    mkdir($directory, 0777, true);
    file_put_contents($analysisPath, $source->contents);
    $map = new AnalysisSourceMap($analysisPath, $source->contents, GeneratedSourceMap::createIdentity($source));
    $file = new AnalysisFile($source, $analysisPath, $source->contents, FileKind::Ppp, true, $map);

    return new AnalysisProject($root, $root . '/analysis', [$file], [], [], [], [], '8.4');
}

test('the pinned phpstan json shape is parsed into compiler-owned findings', function (): void {
    $json = json_encode([
        'totals' => ['errors' => 0, 'file_errors' => 1],
        'files' => [
            '/analysis/Feature.php' => [
                'errors' => 1,
                'messages' => [[
                    'message' => 'Parameter expects int, string given.',
                    'line' => 7,
                    'ignorable' => true,
                    'identifier' => 'argument.type',
                ]],
            ],
        ],
        'errors' => [],
    ], JSON_THROW_ON_ERROR);
    $result = (new PhpStanResultParser())->parse($json);

    expect($result->findings)->toHaveCount(1)
        ->and($result->findings[0]->identifier)->toBe('argument.type')
        ->and($result->findings[0]->line)->toBe(7)
        ->and($result->globalErrors)->toBe([]);
});

test('empty success is accepted while malformed backend output is rejected', function (): void {
    expect((new PhpStanResultParser())->parse('')->findings)->toBe([]);

    expect(fn () => (new PhpStanResultParser())->parse('{'))
        ->toThrow(PhpStanExecutionException::class);
});

test('backend timeouts unexpected exits and malformed results become infrastructure diagnostics', function (PhpStanProcessResult $processResult, string $code): void {
    $root = $this->createTemporaryDirectory();
    $project = createBackendAnalysisProject($root);
    $runner = new class($processResult) extends PhpStanProcessRunner {
        public function __construct(private readonly PhpStanProcessResult $result) {}

        public function run(array $command, string $workingDirectory, float $timeout): PhpStanProcessResult
        {
            return $this->result;
        }
    };
    $analysis = (new PhpStanProjectAnalyzer(dirname(__DIR__, 3), $runner))->analyze($project);

    expect(backendDiagnosticCodes($analysis->diagnostics))->toBe([$code]);
})->with([
    'timeout' => [new PhpStanProcessResult([], '', '', -1, true), DiagnosticCode::StaticAnalysisBackendFailed->value],
    'unexpected exit' => [new PhpStanProcessResult([], '', 'failed', 2, false), DiagnosticCode::StaticAnalysisBackendFailed->value],
    'malformed json' => [new PhpStanProcessResult([], '{', '', 1, false), DiagnosticCode::StaticAnalysisResultInvalid->value],
]);

test('a missing pinned backend executable becomes an infrastructure diagnostic', function (): void {
    $root = $this->createTemporaryDirectory();
    $analysis = (new PhpStanProjectAnalyzer($root . '/missing-compiler'))->analyze(
        createBackendAnalysisProject($root),
    );

    expect(backendDiagnosticCodes($analysis->diagnostics))
        ->toBe([DiagnosticCode::StaticAnalysisBackendFailed->value]);
});

test('exit one with valid findings remains a source-analysis result', function (): void {
    $root = $this->createTemporaryDirectory();
    $project = createBackendAnalysisProject($root);
    $json = json_encode([
        'totals' => ['errors' => 0, 'file_errors' => 1],
        'files' => [
            $project->selectedFiles[0]->analysisPath => [
                'errors' => 1,
                'messages' => [[
                    'message' => 'Parameter expects int, string given.',
                    'line' => 2,
                    'ignorable' => true,
                    'identifier' => 'argument.type',
                ]],
            ],
        ],
        'errors' => [],
    ], JSON_THROW_ON_ERROR);
    $runner = new class($json) extends PhpStanProcessRunner {
        public function __construct(private readonly string $json) {}

        public function run(array $command, string $workingDirectory, float $timeout): PhpStanProcessResult
        {
            return new PhpStanProcessResult($command, $this->json, '', 1, false);
        }
    };
    $analysis = (new PhpStanProjectAnalyzer(dirname(__DIR__, 3), $runner))->analyze($project);

    expect(backendDiagnosticCodes($analysis->diagnostics))
        ->toBe([DiagnosticCode::ArgumentTypeDoesNotMatch->value]);
});

test('checked-error PHPStan identifiers map to compiler diagnostics while unused declarations are filtered', function (string $identifier, ?DiagnosticCode $expected): void {
    $root = $this->createTemporaryDirectory();
    $project = createBackendAnalysisProject($root);
    $finding = new PhpStanFinding(
        $project->selectedFiles[0]->analysisPath,
        'Backend checked-error message.',
        2,
        $identifier,
        true,
    );
    $diagnostic = (new PhpStanDiagnosticMapper())->map($finding, $project);

    if ($expected === null) {
        expect($diagnostic)->toBeNull();

        return;
    }

    expect($diagnostic?->code)->toBe($expected)
        ->and($diagnostic?->debug['backendIdentifier'] ?? null)->toBe($identifier)
        ->and($diagnostic?->message)->not->toContain('.ppphp-cache/analysis');
})->with([
    'missing declaration' => ['missingType.checkedException', DiagnosticCode::CheckedErrorNotHandled],
    'override covariance' => ['throws.notCovariant', DiagnosticCode::CheckedErrorDeclarationNotCovariant],
    'throws type' => ['throws.notThrowable', DiagnosticCode::ErrorTypeNotThrowable],
    'catch type' => ['catch.notThrowable', DiagnosticCode::ErrorTypeNotThrowable],
    'dead catch' => ['catch.neverThrown', DiagnosticCode::CaughtErrorNeverThrown],
    'catch order' => ['catch.alreadyCaught', DiagnosticCode::ErrorCatchUnreachable],
    'conservative declaration' => ['throws.unusedType', null],
]);

test('the compiler-owned PHPStan configuration enables the Stage 7 exception contract', function (): void {
    $configuration = file_get_contents(dirname(__DIR__, 3) . '/resources/phpstan/ppphp.neon');

    expect($configuration)->toBeString()
        ->toContain('implicitThrows: false')
        ->toContain('checkedExceptionClasses:')
        ->toContain('- Exception')
        ->toContain('uncheckedExceptionClasses:')
        ->toContain('- Error')
        ->toContain('missingCheckedExceptionInThrows: true')
        ->toContain('throwTypeCovariance: true');
});
