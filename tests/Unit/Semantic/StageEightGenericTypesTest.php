<?php

declare(strict_types=1);

use Atatusoft\Ppphp\Diagnostics\Diagnostic;
use Atatusoft\Ppphp\Diagnostics\Enumerations\DiagnosticCode;
use Atatusoft\Ppphp\Frontend\Enumerations\ParseMode;
use Atatusoft\Ppphp\Frontend\PpphpParser;
use Atatusoft\Ppphp\Project\ProjectParseResult;
use Atatusoft\Ppphp\Semantic\SemanticAnalysisResult;
use Atatusoft\Ppphp\Semantic\SemanticAnalyzer;
use Atatusoft\Ppphp\Source\Enumerations\FileKind;
use Atatusoft\Ppphp\Source\SourceFile;
use Atatusoft\Ppphp\Support\Path;

/** @return array{Atatusoft\Ppphp\Frontend\ParseResult, SemanticAnalysisResult} */
function analyzeStageEightGenericSource(string $contents): array
{
    $path = '/project/src/Generics.ppphp';
    $source = new SourceFile($path, 'src/Generics.ppphp', FileKind::Ppphp, $contents);
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
function resolveStageEightGenericCodes(SemanticAnalysisResult $result): array
{
    return array_map(
        static fn (Diagnostic $diagnostic): string => $diagnostic->code->value,
        iterator_to_array($result->diagnostics),
    );
}

test('generic classes interfaces traits functions and methods activate with scoped parameters', function (): void {
    [$parse, $analysis] = analyzeStageEightGenericSource(<<<'PPP'
<?php
interface Entity {}
final class User implements Entity {}
interface Repository<T : Entity>
{
    public function find(string $id): T;
}
trait Stores<T : Entity>
{
    public function store(T $value): void {}
}
class UserRepository implements Repository<User>
{
    use Stores<User>;
    public function find(string $id): User { return new User(); }
    public function preserve<TValue : Entity>(TValue $value): TValue { return $value; }
}
function identity<T>(T $value): T { return $value; }
function valid(): void
{
    Repository<User> $repository = new UserRepository();
    User $user = identity(new User());
}
PPP);

    expect($parse->isSuccessful)->toBeTrue()
        ->and(resolveStageEightGenericCodes($analysis))->toBe([])
        ->and($analysis->symbols?->findClass('Repository')?->genericDeclaration?->parameters)->toHaveCount(1)
        ->and($analysis->symbols?->findFunction('identity')?->genericDeclaration?->parameters)->toHaveCount(1)
        ->and($analysis->symbols?->findClass('UserRepository')?->findMethod('preserve')?->genericDeclaration?->parameters)->toHaveCount(1);
});

test('generic parameter duplication and nested shadowing are rejected case-insensitively', function (): void {
    [, $analysis] = analyzeStageEightGenericSource(<<<'PPP'
<?php
class Pair<T, t> {}
class Box<T>
{
    public function map<t>(t $value): t { return $value; }
}
PPP);

    $counts = array_count_values(resolveStageEightGenericCodes($analysis));

    expect($counts[DiagnosticCode::DuplicateTypeParameter->value] ?? 0)->toBeGreaterThanOrEqual(2);
});

test('generic references validate arity bounds raw uses and nongeneric bases', function (string $body, DiagnosticCode $code): void {
    [, $analysis] = analyzeStageEightGenericSource(<<<PPP
<?php
interface Entity {}
final class User implements Entity {}
final class Other {}
class Box<T : Entity> {}
{$body}
PPP);

    expect(resolveStageEightGenericCodes($analysis))->toContain($code->value);

    if (in_array($code, [
        DiagnosticCode::GenericTypeArgumentCountDoesNotMatch,
        DiagnosticCode::TypeArgumentDoesNotSatisfyBound,
    ], true)) {
        $diagnostic = array_values(array_filter(
            iterator_to_array($analysis->diagnostics),
            static fn (Diagnostic $diagnostic): bool => $diagnostic->code === $code,
        ))[0];

        expect($diagnostic->related)->not->toBeEmpty();
    }
})->with([
    'wrong arity' => ['function invalid(): void { Box<User, Other> $value = new Box(); }', DiagnosticCode::GenericTypeArgumentCountDoesNotMatch],
    'bound mismatch' => ['function invalid(): void { Box<Other> $value = new Box(); }', DiagnosticCode::TypeArgumentDoesNotSatisfyBound],
    'raw generic' => ['function invalid(Box $value): void {}', DiagnosticCode::GenericTypeArgumentsAreRequired],
    'nongeneric base' => ['function invalid(User<string> $value): void {}', DiagnosticCode::TypeIsNotGeneric],
]);

test('generic bounds reject unions and recursive constraints', function (string $declaration): void {
    [, $analysis] = analyzeStageEightGenericSource("<?php\ninterface Entity {}\n{$declaration}\n");

    expect(resolveStageEightGenericCodes($analysis))->toContain(DiagnosticCode::InvalidGenericBound->value);
})->with([
    'union bound' => ['class Box<T : Entity|Stringable> {}'],
    'recursive bound' => ['class Box<T : T> {}'],
    'enum bound' => ['enum Status {} class Box<T : Status> {}'],
]);

test('unresolved external class or interface bounds are deferred to Composer-aware backend analysis', function (): void {
    [, $analysis] = analyzeStageEightGenericSource(<<<'PPP'
<?php
class ExternalBox<T : Vendor\Contracts\Entity> {}
PPP);

    expect(resolveStageEightGenericCodes($analysis))->not->toContain(DiagnosticCode::InvalidGenericBound->value);
});

test('erased type parameters cannot drive runtime operations or class static signatures', function (): void {
    [, $runtime] = analyzeStageEightGenericSource(<<<'PPP'
<?php
class Box<T>
{
    public function make(): mixed { return new T(); }
    public function test(mixed $value): bool { return $value instanceof T; }
    public function name(): string { return T::class; }
}
PPP);
    [, $static] = analyzeStageEightGenericSource(<<<'PPP'
<?php
class Box<T>
{
    public static function take(T $value): void {}
}
PPP);

    expect(resolveStageEightGenericCodes($runtime))->toContain(DiagnosticCode::GenericRuntimeOperationIsNotAllowed->value)
        ->and(resolveStageEightGenericCodes($static))->toContain(DiagnosticCode::StaticMemberCannotUseClassTypeParameter->value);
});

test('generic parameters are scoped to their owner and generic applications remain invariant', function (): void {
    [, $scope] = analyzeStageEightGenericSource(<<<'PPP'
<?php
class Box<T> {}
function invalid(T $value): void {}
PPP);
    [, $invariance] = analyzeStageEightGenericSource(<<<'PPP'
<?php
class Animal {}
class Dog extends Animal {}
class Box<T> { public function __construct(T $value) {} }
function invalid(): void
{
    Box<Dog> $dogs = new Box(new Dog());
    Box<Animal> $animals = $dogs;
}
PPP);

    expect(resolveStageEightGenericCodes($scope))->toContain(DiagnosticCode::UnknownTypeParameter->value)
        ->and(resolveStageEightGenericCodes($invariance))->toContain(DiagnosticCode::GenericTypeIsInvariant->value);
});

test('generic constructor arguments are checked against native and imported PHPDoc parameters', function (): void {
    [, $native] = analyzeStageEightGenericSource(<<<'PPP'
<?php
class Box<T> { public function __construct(public T $value) {} }
function invalid(): void { Box<string> $box = new Box(1); }
PPP);

    $phpPath = '/project/src/LegacyBox.php';
    $phpSource = new SourceFile($phpPath, 'src/LegacyBox.php', FileKind::Php, <<<'PHP'
<?php
/** @template T */
class LegacyBox
{
    /** @param T $value */
    public function __construct(public mixed $value) {}
}
PHP);
    $ppphpPath = '/project/src/UseLegacyBox.ppphp';
    $ppphpSource = new SourceFile($ppphpPath, 'src/UseLegacyBox.ppphp', FileKind::Ppphp, <<<'PPP'
<?php
function invalid(): void { LegacyBox<string> $box = new LegacyBox(1); }
PPP);
    $parser = new PpphpParser();
    $php = $parser->parse($phpSource, ParseMode::Php);
    $ppphp = $parser->parse($ppphpSource);
    $phpKey = Path::buildComparisonKey($phpPath);
    $ppphpKey = Path::buildComparisonKey($ppphpPath);
    $imported = (new SemanticAnalyzer())->analyze(new ProjectParseResult(
        [$phpKey => $php->parsedFile, $ppphpKey => $ppphp->parsedFile],
        [$phpKey => $phpSource, $ppphpKey => $ppphpSource],
        new Atatusoft\Ppphp\Diagnostics\DiagnosticBag(),
    ));

    expect(resolveStageEightGenericCodes($native))->toContain(DiagnosticCode::GenericTypeIsInvariant->value)
        ->and(resolveStageEightGenericCodes($imported))->toContain(DiagnosticCode::GenericTypeIsInvariant->value);
});

test('generic constructor validation resolves imported aliases and nested typed array values', function (): void {
    [, $aliases] = analyzeStageEightGenericSource(<<<'PPP'
<?php
namespace Library {
    class Box<T> { public function __construct(public T $value) {} }
}
namespace Application {
    use Library\Box as B;
    function invalid(): void { B<string> $box = new B(1); }
}
PPP);
    [, $nested] = analyzeStageEightGenericSource(<<<'PPP'
<?php
class Box<T> { public function __construct(public T $value) {} }
function invalid(): void
{
    array<Box<string>> $boxes = [new Box(1)];
    array<array<Box<string>>> $groups = [[new Box(2)]];
}
PPP);

    expect(resolveStageEightGenericCodes($aliases))->toContain(DiagnosticCode::GenericTypeIsInvariant->value)
        ->and(array_count_values(resolveStageEightGenericCodes($nested))[DiagnosticCode::GenericTypeIsInvariant->value] ?? 0)
        ->toBe(2);
});
