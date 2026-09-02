<?php

declare(strict_types=1);

use Atatusoft\Ppphp\Diagnostics\Diagnostic;
use Atatusoft\Ppphp\Diagnostics\Enumerations\DiagnosticCode;
use Atatusoft\Ppphp\Frontend\PpphpParser;
use Atatusoft\Ppphp\Project\ProjectParseResult;
use Atatusoft\Ppphp\Semantic\SemanticAnalysisResult;
use Atatusoft\Ppphp\Semantic\SemanticAnalyzer;
use Atatusoft\Ppphp\Source\Enumerations\FileKind;
use Atatusoft\Ppphp\Source\SourceFile;
use Atatusoft\Ppphp\Support\Path;
use Atatusoft\Ppphp\Transpilation\PhpLowerer;
use Symfony\Component\Process\Process;

/** @return array{Atatusoft\Ppphp\Frontend\ParseResult, SemanticAnalysisResult} */
function analyzeStageSevenSource(string $contents): array
{
    $path = '/project/src/Feature.ppphp';
    $source = new SourceFile($path, 'src/Feature.ppphp', FileKind::Ppphp, $contents);
    $parse = (new PpphpParser())->parse($source);
    $key = Path::buildComparisonKey($path);
    $projectParse = new ProjectParseResult(
        $parse->parsedFile === null ? [] : [$key => $parse->parsedFile],
        [$key => $source],
        $parse->diagnostics,
    );

    return [$parse, (new SemanticAnalyzer())->analyze($projectParse)];
}

/** @return list<string> */
function resolveStageSevenCodes(SemanticAnalysisResult $result): array
{
    return array_map(
        static fn (Diagnostic $diagnostic): string => $diagnostic->code->value,
        iterator_to_array($result->diagnostics),
    );
}

test('typed for declarations enter the enclosing scope and retain readonly behavior', function (): void {
    [, $valid] = analyzeStageSevenSource(<<<'PPP'
<?php
function valid(): int
{
    for (int $index = 0; $index < 1; ++$index) {
    }

    return $index;
}
PPP);
    [, $readonly] = analyzeStageSevenSource(<<<'PPP'
<?php
function invalid(): void
{
    for (readonly int $index = 0; $index < 1; ++$index) {
    }
}
PPP);

    expect($valid->isSuccessful)->toBeTrue()
        ->and(resolveStageSevenCodes($valid))->toBe([])
        ->and(resolveStageSevenCodes($readonly))->toContain(DiagnosticCode::ReadonlyLocalCannotBeReassigned->value);
});

test('new foreach bindings require the exact broad array contract and may be uninitialized afterwards', function (): void {
    [, $valid] = analyzeStageSevenSource(<<<'PPP'
<?php
function valid(array $items): void
{
    foreach ($items as mixed $item) {
        echo $item;
    }

    if (isset($item)) {
        echo $item;
    }
}
PPP);
    [, $unsafe] = analyzeStageSevenSource(<<<'PPP'
<?php
function invalid(array $items): void
{
    foreach ($items as mixed $item) {
    }

    echo $item;
}
PPP);
    [, $mismatch] = analyzeStageSevenSource(<<<'PPP'
<?php
function invalid(array $items): void
{
    foreach ($items as string $item) {
    }
}
PPP);

    expect($valid->isSuccessful)->toBeTrue()
        ->and(resolveStageSevenCodes($valid))->toBe([])
        ->and(resolveStageSevenCodes($unsafe))->toContain(DiagnosticCode::LocalVariableMayBeUninitialized->value)
        ->and(resolveStageSevenCodes($mismatch))->toContain(DiagnosticCode::LoopBindingTypeDoesNotMatch->value);
});

test('typed loop declarations share duplicate checks with ordinary local bindings', function (): void {
    [, $analysis] = analyzeStageSevenSource(<<<'PPP'
<?php
function invalid(array $first, array $second): void
{
    foreach ($first as mixed $item) {
    }

    foreach ($second as mixed $item) {
    }
}
PPP);

    expect(resolveStageSevenCodes($analysis))->toContain(DiagnosticCode::DuplicateLocalDeclaration->value);
});

test('loop lowering emits deterministic PHPDoc preserves bodies and executes as ordinary PHP', function (): void {
    $contents = <<<'PPP'
<?php
function main(array $items): void
{
    for (int $index = 0; $index < 1; ++$index) {
    }

    foreach ($items as mixed $key => mixed $value) {
        echo $index, ':', $key, '=', $value;
    }
}
main(['name' => 'Andrew']);
PPP;
    [$parse, $analysis] = analyzeStageSevenSource($contents);
    $model = $analysis->findModel('/project/src/Feature.ppphp');

    expect($parse->parsedFile)->not->toBeNull()
        ->and($model)->not->toBeNull()
        ->and($analysis->isSuccessful)->toBeTrue();

    $generated = (new PhpLowerer())->lower($parse->parsedFile, $model)->contents;
    $path = sys_get_temp_dir() . '/ppphp-stage-seven-loops-' . bin2hex(random_bytes(6)) . '.php';
    file_put_contents($path, $generated);
    $lint = new Process([PHP_BINARY, '-l', $path]);
    $lint->run();
    $runtime = new Process([PHP_BINARY, $path]);
    $runtime->run();
    unlink($path);

    expect($generated)->toContain('/** @var int $index */')
        ->toContain("/**\n * @var mixed \$key\n * @var mixed \$value\n */")
        ->not->toContain('for (int $index')
        ->not->toContain('as mixed $key')
        ->and($lint->isSuccessful())->toBeTrue()
        ->and($runtime->isSuccessful())->toBeTrue()
        ->and($runtime->getOutput())->toBe('1:name=Andrew');
});
