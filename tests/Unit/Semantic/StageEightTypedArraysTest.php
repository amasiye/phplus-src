<?php

declare(strict_types=1);

use Amasiye\Ppphp\Diagnostics\Diagnostic;
use Amasiye\Ppphp\Diagnostics\Enumerations\DiagnosticCode;
use Amasiye\Ppphp\Frontend\PpphpParser;
use Amasiye\Ppphp\Project\ProjectParseResult;
use Amasiye\Ppphp\Semantic\SemanticAnalysisResult;
use Amasiye\Ppphp\Semantic\SemanticAnalyzer;
use Amasiye\Ppphp\Source\Enumerations\FileKind;
use Amasiye\Ppphp\Source\SourceFile;
use Amasiye\Ppphp\Support\Path;
use Symfony\Component\Process\Process;

/** @return array{Amasiye\Ppphp\Frontend\ParseResult, SemanticAnalysisResult} */
function analyzeStageEightTypedArraySource(string $contents): array
{
    $path = '/project/src/TypedArrays.ppphp';
    $source = new SourceFile($path, 'src/TypedArrays.ppphp', FileKind::Ppphp, $contents);
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
function resolveStageEightTypedArrayCodes(SemanticAnalysisResult $result): array
{
    return array_map(
        static fn (Diagnostic $diagnostic): string => $diagnostic->code->value,
        iterator_to_array($result->diagnostics),
    );
}

test('typed lists maps nested arrays and nullable arrays accept their exact contracts', function (): void {
    [, $analysis] = analyzeStageEightTypedArraySource(<<<'PPP'
<?php
final class User {}
function valid(?array<string, int> $input): void
{
    array<string> $names = ['Matthew', 'Mark'];
    $names[] = 'Luke';
    $names[0] = 'John';
    array<string, int> $scores = ['Peter' => 90, 'John' => 100];
    $scores['Mark'] = 85;
    array<int, string> $indexed = [];
    $indexed[] = 'one';
    array<string, array<int>> $groups = ['primary' => [1, 2]];
    $groups['primary'][] = 3;
    array<int|string> $values = [1, 'two'];
    array<string, User|null> $users = ['primary' => new User(), 'secondary' => null];
    ?array<string, int> $nullable = null;
    $nullable = ['one' => 1];
}
PPP);

    expect($analysis->isSuccessful)->toBeTrue()
        ->and(resolveStageEightTypedArrayCodes($analysis))->toBe([]);
});

test('typed lists reject noncontiguous literals string writes and offset unsetting', function (): void {
    [, $analysis] = analyzeStageEightTypedArraySource(<<<'PPP'
<?php
function invalid(): void
{
    array<string> $names = [1 => 'Mark'];
    $names['author'] = 'John';
    unset($names[0]);
}
PPP);

    $codes = resolveStageEightTypedArrayCodes($analysis);

    expect(array_count_values($codes)[DiagnosticCode::OperationWouldBreakListShape->value] ?? 0)->toBe(3);
});

test('typed maps reject invalid declarations keys values and string-only appends', function (): void {
    [, $analysis] = analyzeStageEightTypedArraySource(<<<'PPP'
<?php
function invalid(): void
{
    array<bool, string> $invalidKeys = [];
    array<string, int> $scores = ['John' => 'A'];
    $scores[1] = 100;
    $scores['Mark'] = 'B';
    array<string, string> $names = [];
    $names[] = 'Andrew';
}
PPP);

    $codes = resolveStageEightTypedArrayCodes($analysis);
    $counts = array_count_values($codes);

    expect($codes)->toContain(DiagnosticCode::TypedArrayKeyTypeIsInvalid->value)
        ->and($counts[DiagnosticCode::TypedArrayKeyTypeDoesNotMatch->value] ?? 0)->toBe(2)
        ->and($counts[DiagnosticCode::TypedArrayValueTypeDoesNotMatch->value] ?? 0)->toBe(2);
});

test('literal numeric string keys follow the runtime PHP array-key normalization rule', function (): void {
    [, $analysis] = analyzeStageEightTypedArraySource(<<<'PPP'
<?php
function keys(): void
{
    array<int, string> $integerKeys = ['1' => 'one'];
    array<string, string> $stringKeys = ['01' => 'leading'];
    array<string, string> $invalid = ['1' => 'normalized'];
}
PPP);
    $process = new Process([
        PHP_BINARY,
        '-r',
        'echo json_encode(array_keys(["1" => 1, "01" => 2, "+1" => 3, "-1" => 4]));',
    ]);
    $process->run();

    expect($process->isSuccessful())->toBeTrue()
        ->and(json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR))->toBe([1, '01', '+1', -1])
        ->and(resolveStageEightTypedArrayCodes($analysis))->toContain(DiagnosticCode::TypedArrayKeyTypeDoesNotMatch->value);
});

test('named typed array elements and whole collections remain invariant', function (): void {
    [, $analysis] = analyzeStageEightTypedArraySource(<<<'PPP'
<?php
class Animal {}
final class Dog extends Animal {}
function invalid(): void
{
    array<Animal> $animals = [];
    $animals[] = new Dog();
    array<Dog> $dogs = [new Dog()];
    $animals = $dogs;
}
PPP);

    $codes = resolveStageEightTypedArrayCodes($analysis);

    expect($codes)->toContain(DiagnosticCode::TypedArrayValueTypeDoesNotMatch->value)
        ->and($codes)->toContain(DiagnosticCode::GenericTypeIsInvariant->value);
});

test('typed foreach declarations extract exact list map nested and parameter contracts', function (): void {
    [, $valid] = analyzeStageEightTypedArraySource(<<<'PPP'
<?php
function consume(array<string> $parameterNames): void
{
    foreach ($parameterNames as int $index => string $name) {}
    array<string, int> $scores = ['Andrew' => 100];
    foreach ($scores as string $key => int $score) {}
    array<string, array<int>> $groups = ['primary' => [1]];
    foreach ($groups as string $group => array<int> $items) {}
    array $broad = [];
    foreach ($broad as mixed $broadKey => mixed $broadValue) {}
}
PPP);
    [, $invalid] = analyzeStageEightTypedArraySource(<<<'PPP'
<?php
class Animal {}
final class Dog extends Animal {}
function invalid(): void
{
    array<Dog> $dogs = [new Dog()];
    foreach ($dogs as Animal $animal) {}
}
PPP);

    expect(resolveStageEightTypedArrayCodes($valid))->toBe([])
        ->and(resolveStageEightTypedArrayCodes($invalid))->toContain(DiagnosticCode::LoopBindingTypeDoesNotMatch->value);
});

test('readonly typed arrays reject direct and nested structural mutation', function (): void {
    [, $analysis] = analyzeStageEightTypedArraySource(<<<'PPP'
<?php
function invalid(): void
{
    readonly array<string> $names = [];
    $names[] = 'Andrew';
    readonly array<string, array<int>> $groups = ['primary' => [1, 2]];
    $groups['primary'][] = 3;
}
PPP);

    $counts = array_count_values(resolveStageEightTypedArrayCodes($analysis));

    expect($counts[DiagnosticCode::ReadonlyLocalCannotBeMutated->value] ?? 0)->toBe(2);
});

test('typed array unpacking validates list shape key domains and element contracts', function (): void {
    [, $valid] = analyzeStageEightTypedArraySource(<<<'PPP'
<?php
function valid(): void
{
    array<string> $first = ['Matthew'];
    array<string> $names = [...$first, 'Mark'];
    array<string, int> $scores = ['Matthew' => 90];
    array<string, int> $combined = [...$scores, 'Mark' => 80];
}
PPP);
    [, $invalid] = analyzeStageEightTypedArraySource(<<<'PPP'
<?php
function invalid(): void
{
    array<int> $numbers = [1];
    array<string> $strings = [...$numbers];
    array<string, int> $scores = ['Matthew' => 90];
    array<int> $indexed = [...$scores];
    array<string, int> $map = [...$numbers];
}
PPP);

    $codes = resolveStageEightTypedArrayCodes($invalid);

    expect(resolveStageEightTypedArrayCodes($valid))->toBe([])
        ->and($codes)->toContain(DiagnosticCode::TypedArrayValueTypeDoesNotMatch->value)
        ->toContain(DiagnosticCode::OperationWouldBreakListShape->value)
        ->toContain(DiagnosticCode::TypedArrayKeyTypeDoesNotMatch->value);
});
