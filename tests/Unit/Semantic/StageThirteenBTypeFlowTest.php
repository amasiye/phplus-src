<?php

declare(strict_types=1);

use Amasiye\Ppphp\Diagnostics\Diagnostic;
use Amasiye\Ppphp\Diagnostics\Enumerations\DiagnosticCode;
use Amasiye\Ppphp\Frontend\PpphpParser;
use Amasiye\Ppphp\Project\ProjectParseResult;
use Amasiye\Ppphp\Semantic\SemanticAnalysisResult;
use Amasiye\Ppphp\Semantic\SemanticAnalyzer;
use Amasiye\Ppphp\Semantic\Type\AtomicType;
use Amasiye\Ppphp\Semantic\Type\ExpressionResolutionStatus;
use Amasiye\Ppphp\Semantic\Type\TypeCompatibility;
use Amasiye\Ppphp\Semantic\Type\TypeCompatibilityResult;
use Amasiye\Ppphp\Semantic\Type\TypedArrayType;
use Amasiye\Ppphp\Semantic\Type\UnknownType;
use Amasiye\Ppphp\Source\Enumerations\FileKind;
use Amasiye\Ppphp\Source\SourceFile;
use Amasiye\Ppphp\Support\Path;

/**
 * @param array<string, array{FileKind, string}> $files
 * @return array{ProjectParseResult, SemanticAnalysisResult}
 */
function analyzeStageThirteenBProject(array $files): array
{
    $parsed = [];
    $sources = [];
    $diagnostics = new \Amasiye\Ppphp\Diagnostics\DiagnosticBag();
    $parser = new PpphpParser();

    foreach ($files as $relative => [$kind, $contents]) {
        $path = '/project/' . $relative;
        $source = new SourceFile($path, $relative, $kind, $contents);
        $result = $parser->parse($source);
        $key = Path::buildComparisonKey($path);
        $sources[$key] = $source;
        $diagnostics->addAll($result->diagnostics);

        if ($result->parsedFile !== null) {
            $parsed[$key] = $result->parsedFile;
        }
    }

    $project = new ProjectParseResult($parsed, $sources, $diagnostics);

    return [$project, (new SemanticAnalyzer())->analyze($project)];
}

/** @return list<string> */
function stageThirteenBCodes(SemanticAnalysisResult $analysis): array
{
    return array_map(
        static fn (Diagnostic $diagnostic): string => $diagnostic->code->value,
        iterator_to_array($analysis->diagnostics),
    );
}

test('type compatibility distinguishes proof rejection and unavailable information', function (): void {
    $compatibility = new TypeCompatibility();

    expect($compatibility->compare(new AtomicType('string'), new AtomicType('string')))
        ->toBe(TypeCompatibilityResult::Compatible)
        ->and($compatibility->compare(new AtomicType('string'), new AtomicType('int')))
        ->toBe(TypeCompatibilityResult::Incompatible)
        ->and($compatibility->compare(new AtomicType('Vendor\\User'), new AtomicType('Vendor\\Admin')))
        ->toBe(TypeCompatibilityResult::Unknown)
        ->and($compatibility->compare(new AtomicType('string'), new UnknownType()))
        ->toBe(TypeCompatibilityResult::Unknown);
});

test('call binding validates source and intrinsic contracts without backend participation', function (): void {
    [, $analysis] = analyzeStageThirteenBProject([
        'src/Calls.ppphp' => [FileKind::Ppphp, <<<'PPP'
<?php
function greet(string $name, int $times = 1): string
{
    return $name;
}
function update(string &$value): void {}
function exercise(): void
{
    string $valid = greet('Andrew');
    greet(10);
    greet();
    greet('Andrew', 1, 2);
    greet(name: 'Andrew');
    greet(NAME: 'Andrew');
    greet(unknown: 'Andrew');
    update('literal');
    strlen([]);
}
PPP],
    ]);
    $codes = stageThirteenBCodes($analysis);

    expect($codes)->toContain(
        DiagnosticCode::ArgumentTypeDoesNotMatch->value,
        DiagnosticCode::ArgumentCountDoesNotMatch->value,
        DiagnosticCode::NamedArgumentDoesNotExist->value,
        DiagnosticCode::ArgumentMustBeReferenceable->value,
    )->not->toContain(DiagnosticCode::UncheckedCallBoundary->value)
        ->and(array_count_values($codes)[DiagnosticCode::NamedArgumentDoesNotExist->value] ?? 0)->toBe(2);
});

test('by-reference calls invalidate narrower local facts to the parameter contract', function (): void {
    [, $analysis] = analyzeStageThirteenBProject([
        'src/References.ppphp' => [FileKind::Ppphp, <<<'PPP'
<?php
function replace(?string &$value): void {}
function exercise(?string $value): void
{
    if ($value !== null) {
        replace($value);
        strlen($value);
    }
}
PPP],
    ]);

    expect(stageThirteenBCodes($analysis))->toContain(DiagnosticCode::ArgumentTypeDoesNotMatch->value);
});

test('return member and property diagnostics share compiler-owned expression types', function (): void {
    [, $analysis] = analyzeStageThirteenBProject([
        'src/Flow.ppphp' => [FileKind::Ppphp, <<<'PPP'
<?php
final class User
{
    public string $name;

    public function __construct(bool $initialize)
    {
        if ($initialize) {
            $this->name = 'Andrew';
        }
    }

    public function wrong(): string
    {
        return 10;
    }

    public function partial(bool $ok): string
    {
        if ($ok) {
            return 'ok';
        }
    }
}
function exercise(User $user): void
{
    $user->missing();
    echo $user->unknown;
    $user->name = 10;
}
PPP],
    ]);
    $codes = stageThirteenBCodes($analysis);

    expect($codes)->toContain(
        DiagnosticCode::ReturnTypeDoesNotMatch->value,
        DiagnosticCode::NotAllPathsReturnValue->value,
        DiagnosticCode::MethodDoesNotExist->value,
        DiagnosticCode::PropertyDoesNotExist->value,
        DiagnosticCode::PropertyTypeDoesNotMatch->value,
        DiagnosticCode::PropertyMayBeUninitialized->value,
    );
});

test('constructor helper summaries require statically fixed dispatch', function (): void {
    [, $analysis] = analyzeStageThirteenBProject([
        'src/Properties.ppphp' => [FileKind::Ppphp, <<<'PPP'
<?php
final class Complete
{
    public string $value;
    public function __construct() { $this->initialize(); }
    private function initialize(): void { $this->value = 'ready'; }
}
class Incomplete
{
    public string $value;
    public function __construct() { $this->initialize(); }
    protected function initialize(): void { $this->value = 'maybe'; }
}
PPP],
    ]);
    $diagnostics = array_values(array_filter(
        iterator_to_array($analysis->diagnostics),
        static fn (Diagnostic $diagnostic): bool => $diagnostic->code === DiagnosticCode::PropertyMayBeUninitialized,
    ));

    expect($diagnostics)->toHaveCount(1)
        ->and($diagnostics[0]->message)->toContain('Incomplete::$value');
});

test('constructor completion tracks explicit returns and switch break states', function (): void {
    [, $analysis] = analyzeStageThirteenBProject([
        'src/ConstructorFlow.ppphp' => [FileKind::Ppphp, <<<'PPP'
<?php
final class EarlyReturn
{
    public string $value;
    public function __construct(bool $skip)
    {
        if ($skip) {
            return;
        }
        $this->value = 'ready';
    }
}
final class Switched
{
    public string $value;
    public function __construct(int $mode)
    {
        switch ($mode) {
            case 1:
                $this->value = 'one';
                break;
            default:
                $this->value = 'other';
        }
    }
}
PPP],
    ]);
    $diagnostics = array_values(array_filter(
        iterator_to_array($analysis->diagnostics),
        static fn (Diagnostic $diagnostic): bool => $diagnostic->code === DiagnosticCode::PropertyMayBeUninitialized,
    ));

    expect($diagnostics)->toHaveCount(1)
        ->and($diagnostics[0]->message)->toContain('EarlyReturn::$value');
});

test('ordinary PHP and configured stubs contribute effective call contracts', function (): void {
    [, $analysis] = analyzeStageThirteenBProject([
        'src/Boundary.php' => [FileKind::Php, <<<'PHP'
<?php
namespace Boundary;
final class User {}
function load(string $id): User { return new User(); }
PHP],
        'stubs/Clock.stub.php' => [FileKind::Stub, <<<'PHP'
<?php
namespace Boundary;
function timestamp(int $precision = 0): string {}
PHP],
        'src/UseBoundary.ppphp' => [FileKind::Ppphp, <<<'PPP'
<?php
namespace Boundary;
function exercise(): void
{
    User $user = load(10);
    string $stamp = timestamp('high');
}
PPP],
    ]);
    $codes = stageThirteenBCodes($analysis);

    expect(array_count_values($codes)[DiagnosticCode::ArgumentTypeDoesNotMatch->value] ?? 0)->toBe(2)
        ->and($codes)->not->toContain(
            DiagnosticCode::MissingParameterType->value,
            DiagnosticCode::MissingReturnType->value,
            DiagnosticCode::FunctionDoesNotExist->value,
        );
});

test('generic calls constructors arrays and narrowing preserve precise receiving types', function (): void {
    [, $analysis] = analyzeStageThirteenBProject([
        'src/Inference.ppphp' => [FileKind::Ppphp, <<<'PPP'
<?php
final class Product {}
final class Box<T>
{
    public function __construct(public T $value) {}
    public function get(): T { return $this->value; }
}
function identity<T>(T $value): T { return $value; }
function exercise(?string $name): void
{
    string $value = identity('Andrew');
    Box<Product> $box = new Box(new Product());
    Product $product = $box->get();
    array<int> $numbers = [1, 2, 3];
    array<int|string> $mixed = [1, 'two'];
    if ($name !== null) {
        int $length = strlen($name);
    }
    $name !== null && strlen($name);
}
PPP],
    ]);
    $codes = stageThirteenBCodes($analysis);
    $model = array_values($analysis->models)[0];
    $known = array_values(array_filter(
        $model->expressionTypes->all,
        static fn ($resolution): bool => $resolution->status === ExpressionResolutionStatus::Known,
    ));

    expect($codes)->not->toContain(
        DiagnosticCode::InitializerNotAssignableToDeclaredType->value,
        DiagnosticCode::ArgumentTypeDoesNotMatch->value,
        DiagnosticCode::ReturnTypeDoesNotMatch->value,
        DiagnosticCode::NotAllPathsReturnValue->value,
    )->and($known)->not->toBeEmpty()
        ->and(array_filter($known, static fn ($resolution): bool => $resolution->type instanceof TypedArrayType))->not->toBeEmpty();
});

test('both if branches and terminating finally satisfy all-path return flow', function (): void {
    [, $analysis] = analyzeStageThirteenBProject([
        'src/Returns.ppphp' => [FileKind::Ppphp, <<<'PPP'
<?php
function choose(bool $condition): string
{
    if ($condition) {
        return 'yes';
    } else {
        return 'no';
    }
}
function finalized(): string
{
    try {
        return 'before';
    } finally {
        return 'after';
    }
}
function fallthrough(int $value): string
{
    switch ($value) {
        case 1:
            $value = 2;
        case 2:
            return 'matched';
        default:
            return 'other';
    }
}
PPP],
    ]);

    expect(stageThirteenBCodes($analysis))->not->toContain(
        DiagnosticCode::ReturnTypeDoesNotMatch->value,
        DiagnosticCode::NotAllPathsReturnValue->value,
    );
});

test('closures arrows names constants and static property forms retain distinct contracts', function (): void {
    [, $analysis] = analyzeStageThirteenBProject([
        'src/Names.ppphp' => [FileKind::Ppphp, <<<'PPP'
<?php
namespace App;
enum Mode { case Active; }
final class State
{
    public const string LABEL = 'ready';
    public static string $shared = 'shared';
    public string $instance = 'instance';
}
function exercise(): void
{
    callable $wrongArrow = fn (): string => 10;
    callable $wrongClosure = function (): string { return 10; };
    string $label = State::LABEL;
    Mode $mode = Mode::Active;
    echo State::$shared;
    echo State::$instance;
    State $state = new State();
    echo $state->shared;
    echo State::MISSING;
    MissingProjectType $missing = new MissingProjectType();
    \Vendor\DeferredType $external = new \Vendor\DeferredType();
}
PPP],
    ]);
    $codes = stageThirteenBCodes($analysis);

    expect(array_count_values($codes)[DiagnosticCode::ReturnTypeDoesNotMatch->value] ?? 0)->toBe(2)
        ->and($codes)->toContain(
            DiagnosticCode::StaticMemberAccessIsInvalid->value,
            DiagnosticCode::InstanceMemberAccessIsInvalid->value,
            DiagnosticCode::ClassConstantDoesNotExist->value,
            DiagnosticCode::TypeDoesNotExist->value,
        )
        ->and(array_count_values($codes)[DiagnosticCode::TypeDoesNotExist->value] ?? 0)->toBe(1);
});

test('stub overlays enrich matching declarations and diagnose native contradictions', function (): void {
    [, $compatible] = analyzeStageThirteenBProject([
        'src/Runtime.php' => [FileKind::Php, <<<'PHP'
<?php
namespace Boundary;
function lookup(string $id): object { return new \stdClass(); }
PHP],
        'stubs/Runtime.stub.php' => [FileKind::Stub, <<<'PHP'
<?php
namespace Boundary;
/** @return object */
function lookup(string $id): object {}
PHP],
        'src/Use.ppphp' => [FileKind::Ppphp, <<<'PPP'
<?php
namespace Boundary;
function useLookup(): void { object $value = lookup('1'); }
PPP],
    ]);
    [, $conflicting] = analyzeStageThirteenBProject([
        'src/Runtime.php' => [FileKind::Php, <<<'PHP'
<?php
namespace Boundary;
function lookup(string $id): object { return new \stdClass(); }
PHP],
        'stubs/Runtime.stub.php' => [FileKind::Stub, <<<'PHP'
<?php
namespace Boundary;
function lookup(int $id): object {}
PHP],
        'src/Use.ppphp' => [FileKind::Ppphp, <<<'PPP'
<?php
namespace Boundary;
function useLookup(): void { object $value = lookup('1'); }
PPP],
    ]);
    [, $methodConflicting] = analyzeStageThirteenBProject([
        'src/Runtime.php' => [FileKind::Php, <<<'PHP'
<?php
namespace Boundary;
final class Worker { public function run(string $value): void {} }
PHP],
        'stubs/Runtime.stub.php' => [FileKind::Stub, <<<'PHP'
<?php
namespace Boundary;
final class Worker { public function run(int $value): void {} }
PHP],
        'src/Use.ppphp' => [FileKind::Ppphp, <<<'PPP'
<?php
namespace Boundary;
function useWorker(Worker $worker): void { $worker->run('work'); }
PPP],
    ]);

    expect(stageThirteenBCodes($compatible))->not->toContain(
        DiagnosticCode::DuplicateProjectDeclaration->value,
        DiagnosticCode::StubContractConflict->value,
    )->and(stageThirteenBCodes($conflicting))->toContain(DiagnosticCode::StubContractConflict->value)
        ->not->toContain(DiagnosticCode::DuplicateProjectDeclaration->value)
        ->and(stageThirteenBCodes($methodConflicting))->toContain(DiagnosticCode::StubContractConflict->value)
        ->not->toContain(DiagnosticCode::DuplicateProjectDeclaration->value);
});
