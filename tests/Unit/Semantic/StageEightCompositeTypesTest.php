<?php

declare(strict_types=1);

use Atatusoft\Ppphp\Diagnostics\Diagnostic;
use Atatusoft\Ppphp\Diagnostics\Enumerations\DiagnosticCode;
use Atatusoft\Ppphp\Frontend\PpphpParser;
use Atatusoft\Ppphp\Project\ProjectParseResult;
use Atatusoft\Ppphp\Semantic\SemanticAnalysisResult;
use Atatusoft\Ppphp\Semantic\SemanticAnalyzer;
use Atatusoft\Ppphp\Semantic\Type\AtomicType;
use Atatusoft\Ppphp\Semantic\Type\LocalType;
use Atatusoft\Ppphp\Semantic\Type\UnionType;
use Atatusoft\Ppphp\Source\Enumerations\FileKind;
use Atatusoft\Ppphp\Source\SourceFile;
use Atatusoft\Ppphp\Support\Path;
use Atatusoft\Ppphp\Transpilation\PhpLowerer;
use Symfony\Component\Process\Process;

/** @return array{Atatusoft\Ppphp\Frontend\ParseResult, SemanticAnalysisResult} */
function analyzeStageEightCompositeSource(string $contents): array
{
    $path = '/project/src/Composite.ppphp';
    $source = new SourceFile($path, 'src/Composite.ppphp', FileKind::Ppphp, $contents);
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
function resolveStageEightCompositeCodes(SemanticAnalysisResult $result): array
{
    return array_map(
        static fn (Diagnostic $diagnostic): string => $diagnostic->code->value,
        iterator_to_array($result->diagnostics),
    );
}

test('composite semantic types have order-insensitive canonical equality and deterministic rendering', function (): void {
    $union = LocalType::createFromText('string|int');
    $canonicalUnion = LocalType::createFromText('int|string');
    $intersection = LocalType::createFromText('Iterator&Countable');
    $canonicalIntersection = LocalType::createFromText('Countable&Iterator');
    $dnf = LocalType::createFromText('(Iterator&Countable)|array');

    expect($union->equalsCanonical($canonicalUnion))->toBeTrue()
        ->and($union->canonical)->toBe('int|string')
        ->and($intersection->equalsCanonical($canonicalIntersection))->toBeTrue()
        ->and($intersection->canonical)->toBe('countable&iterator')
        ->and($dnf->semanticType->renderPhpDoc())->toBe('array|(Countable&Iterator)');
});

test('semantic local types preserve display spelling without changing canonical identity', function (): void {
    $type = LocalType::createFromSemanticType(new UnionType([
        new AtomicType('My\\Name\\Is\\AndrewMasiye', true),
        new AtomicType('null'),
    ]));

    expect($type->text)->toBe('\\My\\Name\\Is\\AndrewMasiye|null')
        ->and($type->canonical)->toBe('my\\name\\is\\andrewmasiye|null');
});

test('union and nullable locals enforce every possible assignment without widening', function (): void {
    [, $valid] = analyzeStageEightCompositeSource(<<<'PPP'
<?php
function valid(): void
{
    int|string $value = 1;
    $value = 'one';
    int|string|null $explicit = null;
    ?int $nullable = null;
    $nullable = 1;
}
PPP);
    [, $invalid] = analyzeStageEightCompositeSource(<<<'PPP'
<?php
function invalid(): void
{
    int|string $value = 1;
    $value = 1.5;
}
PPP);

    expect($valid->isSuccessful)->toBeTrue()
        ->and(resolveStageEightCompositeCodes($valid))->toBe([])
        ->and(resolveStageEightCompositeCodes($invalid))->toContain(DiagnosticCode::AssignmentNotAssignableToDeclaredType->value);
});

test('intersection locals use the known project hierarchy and DNF accepts a matching branch', function (): void {
    [, $valid] = analyzeStageEightCompositeSource(<<<'PPP'
<?php
interface First {}
interface Second {}
final class Both implements First, Second {}
function valid(): void
{
    First&Second $both = new Both();
    (First&Second)|array $source = [];
}
PPP);
    [, $invalid] = analyzeStageEightCompositeSource(<<<'PPP'
<?php
interface First {}
interface Second {}
final class OnlyFirst implements First {}
function invalid(): void
{
    First&Second $value = new OnlyFirst();
}
PPP);

    expect($valid->isSuccessful)->toBeTrue()
        ->and(resolveStageEightCompositeCodes($valid))->toBe([])
        ->and(resolveStageEightCompositeCodes($invalid))->toContain(DiagnosticCode::IntersectionTypeIsNotSatisfied->value);
});

test('invalid composite source shapes receive compiler-owned diagnostics', function (string $type): void {
    [, $analysis] = analyzeStageEightCompositeSource(sprintf(<<<'PPP'
<?php
function invalid(): void
{
    %s $value = null;
}
PPP, $type));

    expect(resolveStageEightCompositeCodes($analysis))->toContain(DiagnosticCode::InvalidCompositeType->value);
})->with([
    'duplicate union member' => 'int|int',
    'mixed union' => 'mixed|string',
    'scalar intersection' => 'int&string',
    'nullable shorthand union' => '?int|string',
    'unparenthesized DNF' => 'First&Second|array',
    'union inside intersection' => 'First&(Second|Third)',
    'void local' => 'void',
    'never local' => 'never',
]);

test('invalid composites nested in erased type positions receive compiler-owned diagnostics', function (string $declaration): void {
    [, $analysis] = analyzeStageEightCompositeSource(sprintf(<<<'PPP'
<?php
class Box<T> {}
%s
PPP, $declaration));

    expect(resolveStageEightCompositeCodes($analysis))->toContain(DiagnosticCode::InvalidCompositeType->value);
})->with([
    'parameter generic argument' => ['function invalid(Box<int&string> $value): void {}'],
    'property generic argument' => ['final class Holder { public Box<mixed|string> $value; }'],
    'return generic argument' => ['function invalid(): Box<?int|string> {}'],
    'typed array element' => ['function invalid(array<int&string> $value): void {}'],
]);

test('composite local lowering preserves precise PHPDoc and produces valid PHP', function (): void {
    $contents = <<<'PPP'
<?php
interface First {}
interface Second {}
final class Both implements First, Second {}
function main(): void
{
    int|string $number = 1;
    First&Second $both = new Both();
}
PPP;
    [$parse, $analysis] = analyzeStageEightCompositeSource($contents);
    $model = $analysis->findModel('/project/src/Composite.ppphp');
    expect($parse->parsedFile)->not->toBeNull()
        ->and($model)->not->toBeNull()
        ->and($analysis->isSuccessful)->toBeTrue();

    $generated = (new PhpLowerer())->lower($parse->parsedFile, $model)->contents;
    $temporary = $this->createTemporaryDirectory() . '/Composite.php';
    $this->writeFile($temporary, $generated);
    $lint = new Process([PHP_BINARY, '-l', $temporary]);
    $lint->run();

    expect($generated)->toContain('/** @var int|string $number */')
        ->toContain('/** @var First&Second $both */')
        ->not->toContain("\n    int|string \$number")
        ->and($lint->isSuccessful())->toBeTrue();
});

test('composite loop bindings check canonical contracts and lower precise PHPDoc', function (): void {
    $contents = <<<'PPP'
<?php
interface First {}
interface Second {}
final class Both implements First, Second {}
function valid(array<First&Second> $items): void
{
    for (int|string $index = 0; $index === 0; $index = 'done') {}
    array<int|string> $values = [1, 'two'];
    foreach ($values as int $key => int|string $value) {}
    foreach ($items as int $itemKey => First&Second $item) {}
}
PPP;
    [$parse, $analysis] = analyzeStageEightCompositeSource($contents);
    $model = $analysis->findModel('/project/src/Composite.ppphp');

    expect($parse->parsedFile)->not->toBeNull()
        ->and($model)->not->toBeNull()
        ->and($analysis->isSuccessful)->toBeTrue();

    $generated = (new PhpLowerer())->lower($parse->parsedFile, $model)->contents;

    expect($generated)->toContain('/** @var int|string $index */')
        ->toContain('@var int|string $value')
        ->toContain('@var First&Second $item');
});

test('composite foreach declarations reject widening and retain initialization checks', function (): void {
    [, $analysis] = analyzeStageEightCompositeSource(<<<'PPP'
<?php
function invalid(): void
{
    array<int|string> $values = [1, 'two'];
    foreach ($values as string $value) {}
    echo $value;
}
PPP);
    $codes = resolveStageEightCompositeCodes($analysis);

    expect($codes)->toContain(DiagnosticCode::LoopBindingTypeDoesNotMatch->value)
        ->toContain(DiagnosticCode::LocalVariableMayBeUninitialized->value);
});
