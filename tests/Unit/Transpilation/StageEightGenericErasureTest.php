<?php

declare(strict_types=1);

use Amasiye\Ppphp\Diagnostics\Diagnostic;
use Amasiye\Ppphp\Diagnostics\Enumerations\DiagnosticCode;
use Amasiye\Ppphp\Frontend\Enumerations\ParseMode;
use Amasiye\Ppphp\Frontend\PpphpParser;
use Amasiye\Ppphp\Project\ProjectParseResult;
use Amasiye\Ppphp\Semantic\SemanticAnalysisResult;
use Amasiye\Ppphp\Semantic\SemanticAnalyzer;
use Amasiye\Ppphp\Source\Enumerations\FileKind;
use Amasiye\Ppphp\Source\SourceFile;
use Amasiye\Ppphp\Support\Path;
use Amasiye\Ppphp\Transpilation\GeneratedPhp;
use Amasiye\Ppphp\Transpilation\PhpLowerer;
use Symfony\Component\Process\Process;

/** @return array{Amasiye\Ppphp\Frontend\ParseResult, SemanticAnalysisResult} */
function analyzeStageEightErasureSource(string $contents): array
{
    $path = '/project/src/Erasure.ppphp';
    $source = new SourceFile($path, 'src/Erasure.ppphp', FileKind::Ppphp, $contents);
    $parse = (new PpphpParser())->parse($source);
    $key = Path::buildComparisonKey($path);
    $project = new ProjectParseResult(
        $parse->parsedFile === null ? [] : [$key => $parse->parsedFile],
        [$key => $source],
        $parse->diagnostics,
    );

    return [$parse, (new SemanticAnalyzer())->analyze($project)];
}

function lowerStageEightSource(string $contents): GeneratedPhp
{
    [$parse, $analysis] = analyzeStageEightErasureSource($contents);
    $model = $analysis->findModel('/project/src/Erasure.ppphp');

    expect($parse->parsedFile)->not->toBeNull()
        ->and($model)->not->toBeNull()
        ->and($analysis->isSuccessful)->toBeTrue();

    return (new PhpLowerer())->lower($parse->parsedFile, $model);
}

/** @return list<string> */
function resolveStageEightErasureCodes(SemanticAnalysisResult $analysis): array
{
    return array_map(
        static fn (Diagnostic $diagnostic): string => $diagnostic->code->value,
        iterator_to_array($analysis->diagnostics),
    );
}

test('generic declarations applications inheritance and typed arrays erase to valid precise PHP', function (): void {
    $generated = lowerStageEightSource(<<<'PPP'
<?php
interface Entity {}
interface Reads<T : Entity> {}
final class User implements Entity {}
class Box<T : Entity>
{
    public T $value;
    public function __construct(T $value) { $this->value = $value; }
    public function get(): T { return $this->value; }
}
trait Stores<T : Entity> {}
class UserBox extends Box<User> implements Reads<User>
{
    use Stores<User>;
    public Box<User> $other;
}
function identity<T : Entity>(T $value): T { return $value; }
function total(array<string, int> $scores): int { return 0; }
function names(): array<string> { return []; }
function locals(): void
{
    array<string> $names = [];
    array<string, int> $scores = [];
}
PPP);
    $temporary = $this->createTemporaryDirectory() . '/Erasure.php';
    $this->writeFile($temporary, $generated->contents);
    $lint = new Process([PHP_BINARY, '-l', $temporary]);
    $lint->run();

    expect($generated->contents)
        ->toContain('@template T of Entity')
        ->toContain('@param T $value')
        ->toContain('@return T')
        ->toContain('@extends Box<User>')
        ->toContain('@implements Reads<User>')
        ->toContain('@use Stores<User>')
        ->toContain('@var Box<User>')
        ->toContain('@param array<string, int> $scores')
        ->toContain('@return list<string>')
        ->toContain('/** @var list<string> $names */')
        ->toContain('/** @var array<string, int> $scores */')
        ->toContain('function identity(Entity $value): Entity')
        ->toContain('function total(array $scores): int')
        ->toContain('function names(): array')
        ->toContain('class UserBox extends Box')
        ->not->toContain('class Box<T')
        ->and($lint->isSuccessful())->toBeTrue();
});

test('unbounded and nullable type parameters erase to legal mixed while retaining PHPDoc', function (): void {
    $generated = lowerStageEightSource(<<<'PPP'
<?php
function identity<T>(T $value): T { return $value; }
function optional<T>(T|null $value): T|null { return $value; }
PPP);

    expect($generated->contents)
        ->toContain('function identity(mixed $value): mixed')
        ->toContain('function optional(mixed $value): mixed')
        ->toContain('@param T|null $value')
        ->toContain('@return T|null')
        ->not->toContain('mixed|null');
});

test('coordinated PHPDoc emission preserves summaries descriptions attributes and matching tags', function (): void {
    $generated = lowerStageEightSource(<<<'PPP'
<?php
#[Attribute]
class Marker {}
/**
 * Stores a value.
 * @template T Existing template description.
 */
#[Marker]
class Box<T>
{
    /** Returns the supplied value. */
    public function value(T $input): T { return $input; }
}
PPP);

    expect(substr_count($generated->contents, '@template T'))->toBe(1)
        ->and($generated->contents)->toContain('Stores a value.')
        ->toContain('Existing template description.')
        ->toContain("*/\n#[Marker]\nclass Box")
        ->toContain('Returns the supplied value.')
        ->toContain('@param T $input')
        ->toContain('@return T');
});

test('ordinary PHP template metadata participates in native generic resolution', function (): void {
    $phpPath = '/project/src/Boundary.php';
    $phpSource = new SourceFile($phpPath, 'src/Boundary.php', FileKind::Php, <<<'PHP'
<?php
interface Entity {}
/** @template T of Entity */
class Box {}
PHP);
    $ppphpPath = '/project/src/UseBoundary.ppphp';
    $ppphpSource = new SourceFile($ppphpPath, 'src/UseBoundary.ppphp', FileKind::Ppphp, <<<'PPP'
<?php
final class User implements Entity {}
function valid(): void { Box<User> $box = new Box(); }
PPP);
    $parser = new PpphpParser();
    $php = $parser->parse($phpSource, ParseMode::Php);
    $ppphp = $parser->parse($ppphpSource);
    $phpKey = Path::buildComparisonKey($phpPath);
    $ppphpKey = Path::buildComparisonKey($ppphpPath);
    $project = new ProjectParseResult(
        [$phpKey => $php->parsedFile, $ppphpKey => $ppphp->parsedFile],
        [$phpKey => $phpSource, $ppphpKey => $ppphpSource],
        new Amasiye\Ppphp\Diagnostics\DiagnosticBag(),
    );
    $analysis = (new SemanticAnalyzer())->analyze($project);

    expect($analysis->isSuccessful)->toBeTrue()
        ->and($analysis->symbols?->findClass('Box')?->genericDeclaration?->parameters)->toHaveCount(1);
});

test('conflicting native and PHPDoc generic contracts are rejected', function (string $contents): void {
    [, $analysis] = analyzeStageEightErasureSource($contents);

    expect(resolveStageEightErasureCodes($analysis))->toContain(DiagnosticCode::GenericDocumentationConflictsWithNativeSyntax->value);
})->with([
    'template name' => [<<<'PPP'
<?php
/** @template U */
class Box<T> {}
PPP],
    'template bound' => [<<<'PPP'
<?php
interface Entity {}
interface Other {}
/** @template T of Other */
class Box<T : Entity> {}
PPP],
    'parameter type' => [<<<'PPP'
<?php
/** @param int $values */
function values(array<string> $values): void {}
PPP],
    'return type' => [<<<'PPP'
<?php
/** @return array<int, string> */
function values(): array<string> { return []; }
PPP],
]);

test('anonymous callable signatures erase generic types and preserve precise PHPDoc', function (string $newline): void {
    $source = <<<'PPP'
<?php
interface Entity {}
final class Product implements Entity {}
class Box<T : Entity>
{
    public function __construct(public T $value) {}

    public function callbacks<U>(T $item, U $other): array<callable>
    {
        callable $arrow = fn (T $value): T => $value;
        /** Existing callback description. */
        callable $closure = function (U $value): U { return $value; };
        callable $nested = fn (Box<T> $box): array<T> => [$box->value];
        return [$arrow, $closure, $nested];
    }
}
PPP;
    $source = str_replace("\n", $newline, $source);
    $generated = lowerStageEightSource($source);
    $temporary = $this->createTemporaryDirectory() . '/Anonymous.php';
    $this->writeFile($temporary, $generated->contents);
    $lint = new Process([PHP_BINARY, '-l', $temporary]);
    $lint->run();

    expect($generated->contents)
        ->toContain('@param T $value')
        ->toContain('@return T')
        ->toContain('fn (Entity $value): Entity')
        ->toContain('Existing callback description.')
        ->toContain('@param U $value')
        ->toContain('@return U')
        ->toContain('function (mixed $value): mixed')
        ->toContain('@param Box<T> $box')
        ->toContain('@return list<T>')
        ->toContain('fn (Box $box): array')
        ->and(substr_count($generated->contents, $newline))->toBeGreaterThan(5)
        ->and($lint->isSuccessful())->toBeTrue($lint->getErrorOutput());
})->with([
    'LF' => ["\n"],
    'CRLF' => ["\r\n"],
]);
