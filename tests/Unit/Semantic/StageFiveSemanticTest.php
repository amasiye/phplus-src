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
use Amasiye\Ppphp\Transpilation\PhpLowerer;
use Symfony\Component\Process\Process;

/** @return array{Amasiye\Ppphp\Frontend\ParseResult, SemanticAnalysisResult} */
function analyzeStageFiveSource(string $contents, FileKind $kind = FileKind::Ppphp): array
{
    $relativePath = $kind === FileKind::Ppphp ? 'src/Feature.ppphp' : 'src/Feature.php';
    $path = '/project/' . $relativePath;
    $source = new SourceFile($path, $relativePath, $kind, $contents);
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
function resolveStageFiveCodes(SemanticAnalysisResult $result): array
{
    return array_map(
        static fn (Diagnostic $diagnostic): string => $diagnostic->code->value,
        iterator_to_array($result->diagnostics),
    );
}

test('typed declarations introduce mutable and readonly bindings in callable scopes', function (): void {
    [$parse, $analysis] = analyzeStageFiveSource(<<<'PPP'
<?php
function calculate(int $seed): int
{
    int $count = 1;
    $count += $seed;
    array $items = [];
    mixed $item = null;
    foreach ($items as $item) {
        $count++;
    }

    callable $read = function () use ($count): int {
        int $nested = 2;
        return $count + $nested;
    };

    return $read();
}
PPP);

    $model = $analysis->findModel('/project/src/Feature.ppphp');

    expect($parse->isSuccessful)->toBeTrue()
        ->and($analysis->isSuccessful)->toBeTrue()
        ->and($model)->not->toBeNull()
        ->and($model?->bindings->bindings)->toHaveCount(5)
        ->and(array_map(static fn ($binding): string => $binding->name, $model?->bindings->bindings ?? []))
        ->toBe(['$count', '$items', '$item', '$nested', '$read']);
});

test('plain assignment and reads cannot introduce undeclared local variables once typed bindings are active', function (): void {
    [, $analysis] = analyzeStageFiveSource(<<<'PPP'
<?php
$outside = 1;
function invalid(): void
{
    int $declared = 1;
    $missing = 2;
    echo $unknown;
}
PPP);

    expect(resolveStageFiveCodes($analysis))
        ->toContain(DiagnosticCode::AssignmentCannotDeclareVariable->value)
        ->toContain(DiagnosticCode::LocalVariableNotDeclared->value);
});

test('every supported read and write form requires an existing local declaration', function (): void {
    [, $analysis] = analyzeStageFiveSource(<<<'PPP'
<?php
function invalid(): void
{
    $assigned = 1;
    $compound += 1;
    $increment++;
    --$decrement;
    $append[] = 1;
    $offset['key'] = 1;
    unset($unset);
    isset($isset);
    empty($empty);
    echo $coalesce ?? 'fallback';
}
PPP);

    $counts = array_count_values(resolveStageFiveCodes($analysis));

    expect($counts[DiagnosticCode::AssignmentCannotDeclareVariable->value] ?? 0)->toBe(1)
        ->and($counts[DiagnosticCode::LocalVariableNotDeclared->value] ?? 0)->toBe(9);
});

test('normal nested blocks share a callable scope while nested closures have their own scope', function (): void {
    [, $duplicate] = analyzeStageFiveSource(<<<'PPP'
<?php
function invalid(): void
{
    int $value = 1;
    if (true) {
        int $value = 2;
    }
}
PPP);
    [, $nestedClosure] = analyzeStageFiveSource(<<<'PPP'
<?php
function valid(): void
{
    int $value = 1;
    callable $closure = function (): int {
        int $value = 2;
        return $value;
    };
}
PPP);

    $diagnostic = iterator_to_array($duplicate->diagnostics)[0] ?? null;

    expect(resolveStageFiveCodes($duplicate))->toContain(DiagnosticCode::DuplicateLocalDeclaration->value)
        ->and($diagnostic?->related)->not->toBeEmpty()
        ->and($nestedClosure->isSuccessful)->toBeTrue()
        ->and(resolveStageFiveCodes($nestedClosure))->toBe([])
        ->not->toContain(DiagnosticCode::DuplicateLocalDeclaration->value);
});

test('callable scopes recognize PHP binding positions and isolate functions and methods', function (): void {
    [, $analysis] = analyzeStageFiveSource(<<<'PPP'
<?php
function first(int $parameter): void
{
    int $same = $parameter;
    try {
        throw new RuntimeException();
    } catch (Throwable $caught) {
        echo $caught->getMessage();
    }
    echo $_SERVER['REQUEST_METHOD'] ?? '';
}
function second(): void
{
    int $same = 2;
}
final class Example
{
    public function first(): void
    {
        int $same = 1;
        echo $this::class;
    }

    public function second(): void
    {
        int $same = 2;
    }
}
function captures(): void
{
    int $value = 1;
    callable $reader = function () use ($value): int {
        return $value;
    };
}
PPP);

    expect($analysis->isSuccessful)->toBeTrue()
        ->and(resolveStageFiveCodes($analysis))->toBe([DiagnosticCode::UncheckedCallBoundary->value]);
});

test('closure capture requires a visible binding and preserves readonly mutability', function (): void {
    [, $analysis] = analyzeStageFiveSource(<<<'PPP'
<?php
function invalid(): void
{
    readonly int $value = 1;
    callable $missing = function () use ($undeclared): void {};
    callable $reference = function () use (&$value): void {};
    callable $writer = function () use ($value): void {
        $value = 2;
    };
}
PPP);

    expect(resolveStageFiveCodes($analysis))
        ->toContain(DiagnosticCode::LocalVariableNotDeclared->value)
        ->toContain(DiagnosticCode::ReadonlyLocalCannotBeReferenced->value)
        ->toContain(DiagnosticCode::ReadonlyLocalCannotBeReassigned->value);
});

test('readonly locals reject storage replacement structural mutation and references but allow object mutation', function (): void {
    [, $analysis] = analyzeStageFiveSource(<<<'PPP'
<?php
function invalid(): void
{
    readonly int $count = 1;
    $count = 2;
    $count += 1;
    $count++;
    --$count;
    $reference =& $count;

    readonly array $items = [];
    $items[] = 1;
    $items['key'] = 1;
    sort($items);
    unset($items[0]);

    readonly array $groups = ['primary' => []];
    $groups['primary'][] = 1;

    readonly stdClass $box = new stdClass();
    $box->value = 1;
    $box->reset();
}
PPP);

    $codes = resolveStageFiveCodes($analysis);

    expect($codes)
        ->toContain(DiagnosticCode::ReadonlyLocalCannotBeReassigned->value)
        ->toContain(DiagnosticCode::ReadonlyLocalCannotBeMutated->value)
        ->toContain(DiagnosticCode::ReadonlyLocalCannotBeReferenced->value);
});

test('readonly locals are rejected at unambiguously known by-reference call sites', function (): void {
    [, $readonly] = analyzeStageFiveSource(<<<'PPP'
<?php
function mutate(int &$value): void {}
final class Mutator
{
    public function change(int &$value): void {}
}
function invalid(): void
{
    readonly int $value = 1;
    Mutator $mutator = new Mutator();
    mutate($value);
    $mutator->change($value);
}
PPP);
    [, $mutable] = analyzeStageFiveSource(<<<'PPP'
<?php
function mutate(int &$value): void {}
function valid(): void
{
    int $value = 1;
    mutate($value);
}
PPP);

    $codes = resolveStageFiveCodes($readonly);

    expect(array_values(array_filter(
        $codes,
        static fn (string $code): bool => $code === DiagnosticCode::ReadonlyLocalCannotBeReferenced->value,
    )))->toHaveCount(2)
        ->and($mutable->isSuccessful)->toBeTrue();
});

test('definitive initializer and later assignment types are checked without guessing unresolved expressions', function (): void {
    [, $valid] = analyzeStageFiveSource(<<<'PPP'
<?php
function valid(): void
{
    int $integer = 1;
    float $float = 1.5;
    string $string = 'value';
    bool $boolean = true;
    array $array = [];
    callable $callable = static fn (): int => 1;
    ?int $nullable = null;
    int|string $union = 'value';
    mixed $value = 1;
    stdClass $object = new stdClass();
    int $unknown = load_value();
    int $first = 1;
    int $second = $first;
    $integer = 2;
    $nullable = 4;
    $nullable = null;
    $value = 'text';
    $value = null;
}
PPP);
    [, $invalid] = analyzeStageFiveSource(<<<'PPP'
<?php
function invalid(): void
{
    int $initializer = 'wrong';
    int $assignment = 1;
    $assignment = 'wrong';
    int $nonNullable = null;
    int $source = 1;
    string $target = $source;
}
PPP);

    expect($valid->isSuccessful)->toBeTrue()
        ->and(resolveStageFiveCodes($invalid))
        ->toContain(DiagnosticCode::InitializerNotAssignableToDeclaredType->value)
        ->toContain(DiagnosticCode::AssignmentNotAssignableToDeclaredType->value);
});

test('mutable bindings support compatible updates without widening their declared type', function (): void {
    [, $valid] = analyzeStageFiveSource(<<<'PPP'
<?php
function valid(): void
{
    int $count = 0;
    $count = 4;
    $count += 2;
    $count++;
    --$count;
    string $text = 'a';
    $text .= 'b';
    array $items = [];
    $items[] = 1;
}
PPP);
    [, $invalid] = analyzeStageFiveSource(<<<'PPP'
<?php
function invalid(): void
{
    int $count = 1;
    $count = 1.5;
    $count /= 2;
    string $text = 'a';
    $text++;
}
PPP);

    expect($valid->isSuccessful)->toBeTrue()
        ->and(resolveStageFiveCodes($invalid))
        ->each->toBe(DiagnosticCode::AssignmentNotAssignableToDeclaredType->value);
});

test('unsupported binding positions receive explicit diagnostics', function (): void {
    [, $analysis] = analyzeStageFiveSource(<<<'PPP'
<?php
function invalid(array $source): void
{
    int $item = 0;
    array $pair = [1, 2];
    foreach ($source as &$item) {}
    [$item, $missing] = $pair;
    static $cached = 1;
    global $service;
}
PPP);

    expect(resolveStageFiveCodes($analysis))
        ->toContain(DiagnosticCode::UnsupportedLocalBindingPosition->value);
});

test('implicit binding constructs and top-level assignment cannot introduce locals', function (): void {
    [, $analysis] = analyzeStageFiveSource(<<<'PPP'
<?php
$topLevel = 1;
function invalid(array $source): void
{
    int $item = 0;
    array $pair = [1, 2];
    foreach ($source as $newItem) {}
    foreach ($source as &$item) {}
    [$item, $newPairItem] = $pair;
    static $cached = 1;
    global $service;
}
PPP);

    expect(resolveStageFiveCodes($analysis))
        ->toContain(DiagnosticCode::AssignmentCannotDeclareVariable->value)
        ->toContain(DiagnosticCode::UnsupportedLocalBindingPosition->value);
});

test('file-scope declarations share one scope across namespaces and enforce fixed and readonly types', function (): void {
    [, $analysis] = analyzeStageFiveSource(<<<'PPP'
<?php
namespace {
    int $count = 1;
    $count = 2;
    readonly string $name = 'Andrew';
    $name = 'Lucy';
}
namespace First {
    int $duplicate = 1;
}
namespace Second {
    int $duplicate = 2;
    $missing = 1;
}
PPP);

    expect(resolveStageFiveCodes($analysis))
        ->toContain(DiagnosticCode::ReadonlyLocalCannotBeReassigned->value)
        ->toContain(DiagnosticCode::DuplicateLocalDeclaration->value)
        ->toContain(DiagnosticCode::AssignmentCannotDeclareVariable->value);
});

test('file-scope typed declarations lower to executable ordinary PHP', function (): void {
    $contents = <<<'PPP'
<?php
readonly string $name = 'Andrew';
int $count = 2;
echo $name . ':' . $count;
PPP;
    [$parse, $analysis] = analyzeStageFiveSource($contents);
    $model = $analysis->findModel('/project/src/Feature.ppphp');

    expect($analysis->isSuccessful)->toBeTrue()
        ->and($parse->parsedFile)->not->toBeNull()
        ->and($model)->not->toBeNull();

    $generated = (new PhpLowerer())->lower($parse->parsedFile, $model)->contents;
    $path = sys_get_temp_dir() . '/ppphp-stage-five-file-scope-' . bin2hex(random_bytes(6)) . '.php';
    file_put_contents($path, $generated);
    $runtime = new Process([PHP_BINARY, $path]);
    $runtime->run();
    unlink($path);

    expect($generated)->toContain('/** @var string $name */')
        ->toContain('/** @var int $count */')
        ->and($runtime->getExitCode())->toBe(0)
        ->and($runtime->getOutput())->toBe('Andrew:2');
});

test('lowering replaces only typed declaration prefixes and emits valid source-preserving PHP', function (): void {
    $contents = "<?php\r\nfunction example(): void\r\n{\r\n    // before\r\n    readonly int /* between */ \$count = 1; // after\r\n    string \$name = 'Andrew';\r\n}\r\n";
    [$parse, $analysis] = analyzeStageFiveSource($contents);
    $model = $analysis->findModel('/project/src/Feature.ppphp');

    expect($parse->parsedFile)->not->toBeNull()
        ->and($model)->not->toBeNull()
        ->and($analysis->isSuccessful)->toBeTrue();

    $generated = (new PhpLowerer())->lower($parse->parsedFile, $model)->contents;
    $path = sys_get_temp_dir() . '/ppphp-lowered-' . bin2hex(random_bytes(6)) . '.php';
    file_put_contents($path, $generated);
    $lint = new Process([PHP_BINARY, '-l', $path]);
    $lint->run();
    unlink($path);

    expect($generated)->toContain('/** @var int $count */')
        ->toContain('/* between */')
        ->toContain('// before')
        ->toContain('// after')
        ->toContain('/** @var string $name */')
        ->not->toContain('readonly int')
        ->and(str_contains($generated, "\r\n"))->toBeTrue()
        ->and($lint->getExitCode())->toBe(0);
});

test('ordinary PHP-only ppphp files keep byte-identical output and existing PHP local behavior', function (): void {
    $contents = "<?php\nfunction ordinary(int \$value): int { return \$value; }\n";
    [$parse, $analysis] = analyzeStageFiveSource($contents);
    $model = $analysis->findModel('/project/src/Feature.ppphp');

    expect($analysis->isSuccessful)->toBeTrue()
        ->and($parse->parsedFile)->not->toBeNull()
        ->and($model)->not->toBeNull()
        ->and((new PhpLowerer())->lower($parse->parsedFile, $model)->contents)->toBe($contents);
});

test('lowering preserves all Stage 5 type metadata and generated code executes', function (): void {
    $contents = <<<'PPP'
<?php
function main(): void
{
    string $name = "André"; int $count = 1;
    ?int $optional = null;
    mixed $value = load_value();
    array $items = ['original'];
    readonly stdClass $object = new stdClass();
    echo $name . ':' . $count . ':' . $items[0] . PHP_EOL;
}
function load_value(): string { return 'value'; }
main();
PPP;
    [$parse, $analysis] = analyzeStageFiveSource($contents);
    $model = $analysis->findModel('/project/src/Feature.ppphp');

    expect($parse->parsedFile)->not->toBeNull()
        ->and($model)->not->toBeNull()
        ->and($analysis->isSuccessful)->toBeTrue();

    $generated = (new PhpLowerer())->lower($parse->parsedFile, $model)->contents;
    $path = sys_get_temp_dir() . '/ppphp-stage-five-runtime-' . bin2hex(random_bytes(6)) . '.php';
    file_put_contents($path, $generated);
    $runtime = new Process([PHP_BINARY, $path]);
    $runtime->run();
    unlink($path);

    expect($generated)->toContain('/** @var string $name */')
        ->toContain('/** @var int $count */')
        ->toContain('/** @var int|null $optional */')
        ->toContain('/** @var mixed $value */')
        ->toContain('/** @var array $items */')
        ->toContain('/** @var stdClass $object */')
        ->toContain('"André"')
        ->not->toContain('readonly stdClass')
        ->and($runtime->getExitCode())->toBe(0)
        ->and($runtime->getOutput())->toBe("André:1:original\n");
});

test('ordinary PHP files do not receive ++PHP binding analysis', function (): void {
    [, $analysis] = analyzeStageFiveSource(
        "<?php\n\$dynamic = 1;\n\$dynamic++;\necho \$dynamic;\n",
        FileKind::Php,
    );

    expect($analysis->isSuccessful)->toBeTrue()
        ->and($analysis->models)->toBe([])
        ->and(resolveStageFiveCodes($analysis))->toBe([]);
});
