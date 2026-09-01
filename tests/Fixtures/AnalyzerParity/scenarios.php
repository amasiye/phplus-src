<?php

declare(strict_types=1);

$scenario = static function (
    string $id,
    string $capabilityId,
    string $source,
    array $compiler = [],
    ?array $requiredFull = null,
    array $supplemental = [],
    array $optional = [],
    bool $releaseBlocking = true,
    ?string $disagreement = null,
    string $path = 'main.ppphp',
    ?string $selection = null,
    array $stubs = [],
    array $projectFiles = [],
    bool $backendUnavailable = false,
): array {
    return [
        'id' => $id,
        'capabilityId' => $capabilityId,
        'sources' => [$path => $source],
        'stubs' => $stubs,
        'projectFiles' => $projectFiles,
        'selection' => $selection,
        'expectedCompilerDiagnostics' => $compiler,
        'expectedRequiredFullDiagnostics' => $requiredFull ?? $compiler,
        'expectedSupplementalFullDiagnostics' => $supplemental,
        'expectedOptionalDiagnostics' => $optional,
        'releaseBlocking' => $releaseBlocking,
        'expectedDisagreement' => $disagreement,
        'backendUnavailable' => $backendUnavailable,
    ];
};

return [
    $scenario('syntax-php', 'syntax.php', "<?php\nfunction broken(\n", ['P1001']),
    $scenario('syntax-extension', 'syntax.extension', <<<'PPP'
<?php
function valid(): void { int $value = 1; }
PPP, optional: ['P2099'], disagreement: 'optionalLint'),
    $scenario('declarations-strict', 'declarations.strict', <<<'PPP'
<?php
function invalid($value): void {}
PPP, ['P2011']),
    $scenario('types-name-resolution-import', 'types.name-resolution', <<<'PPP'
<?php
namespace App;
use DateTimeImmutable as Clock;
function identity(Clock $clock): Clock { return $clock; }
PPP),
    $scenario('types-name-resolution-missing', 'types.name-resolution', <<<'PPP'
<?php
namespace App;
final class Known {}
function invalid(): void
{
    MissingProjectType $value = new MissingProjectType();
    \App\missing_function();
}
PPP, ['P2020', 'P2021']),
    $scenario('types-name-resolution-deferred', 'types.name-resolution', <<<'PPP'
<?php
namespace App;
final class Known {}
function boundary(\Vendor\DeferredType $value): void {}
PPP, supplemental: ['P2020'], disagreement: 'supplemental'),
    $scenario('types-composites', 'types.composites', <<<'PPP'
<?php
class Box<T> {}
function invalid(Box<int&string> $value): void {}
PPP, ['P2030']),
    $scenario('types-assignability', 'types.assignability', <<<'PPP'
<?php
function invalid(): void { int $value = 'wrong'; }
PPP, ['P2008']),
    $scenario('types-assignability-expression', 'types.assignability', <<<'PPP'
<?php
function valid(): void
{
    int $length = strlen('++PHP');
    string $label = $length > 0 ? 'ready' : 'empty';
}
PPP, optional: ['P2099', 'P2099'], disagreement: 'optionalLint'),
    $scenario('flow-locals', 'flow.locals', <<<'PPP'
<?php
function invalid(): void { $value = 1; }
PPP, ['P2002']),
    $scenario('flow-loops', 'flow.loops', <<<'PPP'
<?php
final class Animal {}
function invalid(array<Animal> $animals): void
{
    foreach ($animals as string $animal) {}
}
PPP, ['P2026']),
    $scenario('flow-properties', 'flow.properties', <<<'PPP'
<?php
final class Feature
{
    public function invalid(): void { $this->created = 1; }
}
PPP, ['P2022']),
    $scenario('flow-properties-assignment', 'flow.properties', <<<'PPP'
<?php
final class Feature
{
    public string $name = 'ready';
    public function invalid(): void { $this->name = 1; }
}
PPP, ['P2024']),
    $scenario('flow-properties-initialization', 'flow.properties', <<<'PPP'
<?php
final class Feature
{
    public string $name;
    public function __construct(bool $ready)
    {
        if ($ready) { $this->name = 'ready'; }
    }
}
PPP, ['P2044']),
    $scenario('flow-when', 'flow.when', <<<'PPP'
<?php
function invalid(bool $value): int
{
    return when ($value) { if (true) { return 1; } } else { return 0; };
}
PPP, ['P5002']),
    $scenario('calls-arguments-negative', 'calls.arguments', <<<'PPP'
<?php
function take(int $value): void {}
function invalid(): void { take('wrong'); }
PPP, ['P2015']),
    $scenario('calls-arguments-positive', 'calls.arguments', <<<'PPP'
<?php
function take(string $name, int $times = 1): void {}
function valid(): void { take('Andrew', 2); }
PPP),
    $scenario('calls-arguments-named', 'calls.arguments', <<<'PPP'
<?php
function take(string $name, int $times = 1): void {}
function valid(): void { take(times: 2, name: 'Andrew'); }
PPP),
    $scenario('calls-returns-mismatch', 'calls.returns', <<<'PPP'
<?php
function invalid(): int { return 'wrong'; }
PPP, ['P2016']),
    $scenario('calls-returns-paths', 'calls.returns', <<<'PPP'
<?php
function choose(bool $condition): string
{
    if ($condition) { return 'yes'; } else { return 'no'; }
}
PPP),
    $scenario('calls-returns-finally', 'calls.returns', <<<'PPP'
<?php
function finalized(): string
{
    try { return 'before'; } finally { return 'after'; }
}
PPP, optional: ['P2099', 'P2099'], disagreement: 'optionalLint'),
    $scenario('calls-members-missing', 'calls.members', <<<'PPP'
<?php
final class Service {}
function invalid(Service $service): void { $service->missing(); }
PPP, ['P2018']),
    $scenario('calls-members-generic', 'calls.members', <<<'PPP'
<?php
final class Product {}
final class Box<T>
{
    public function __construct(public T $value) {}
    public function get(): T { return $this->value; }
}
function valid(Box<Product> $box): void { Product $product = $box->get(); }
PPP),
    $scenario('calls-members-static', 'calls.members', <<<'PPP'
<?php
final class State { public string $name = 'ready'; }
function invalid(): void { echo State::$name; }
PPP, ['P2040']),
    $scenario('calls-intrinsics-negative', 'calls.intrinsics', <<<'PPP'
<?php
function invalid(): void { strlen([]); }
PPP, ['P2015']),
    $scenario('calls-intrinsics-collections', 'calls.intrinsics', <<<'PPP'
<?php
function valid(array<string> $items): array<string>
{
    array<int, string> $filtered = array_filter($items, fn (string $item): bool => true);
    return array_values($filtered);
}
PPP),
    $scenario('calls-dynamic', 'calls.dynamic', <<<'PPP'
<?php
function invoke(callable $callback): void { $callback(); }
PPP, ['P4005']),
    $scenario('generics-declarations', 'generics.declarations', <<<'PPP'
<?php
class Pair<T, t> {}
PPP, ['P3002']),
    $scenario('generics-arity', 'generics.arity', <<<'PPP'
<?php
class Box<T> {}
function invalid(Box<int, string> $box): void {}
PPP, ['P3004']),
    $scenario('generics-bounds', 'generics.bounds', <<<'PPP'
<?php
interface Entity {}
final class Other {}
class Box<T : Entity> {}
function invalid(Box<Other> $box): void {}
PPP, ['P3005']),
    $scenario('generics-substitution', 'generics.substitution', <<<'PPP'
<?php
final class Product {}
class Box<T>
{
    public function __construct(public T $value) {}
    public function get(): T { return $this->value; }
}
function valid(Box<Product> $box): void { Product $value = $box->get(); }
PPP),
    $scenario('generics-invariance', 'generics.invariance', <<<'PPP'
<?php
class Animal {}
final class Dog extends Animal {}
class Box<T> { public function __construct(T $value) {} }
function invalid(): void
{
    Box<Dog> $dogs = new Box(new Dog());
    Box<Animal> $animals = $dogs;
}
PPP, ['P3016']),
    $scenario('generics-dependent-bounds', 'generics.dependent-bounds', <<<'PPP'
<?php
final class Product {}
final class Service {}
final class Item<T> {}
class Cart<TProduct, TItem : Item<TProduct>> {}
function invalid(Cart<Product, Item<Service>> $cart): void {}
PPP, ['P3005']),
    $scenario('generics-this', 'generics.this', <<<'PPP'
<?php
class Box<T>
{
    public function __construct(public T $value) {}
    public function get(): T { return $this->value; }
    public function callback(): callable { return fn (): T => $this->get(); }
}
PPP),
    $scenario('collections-typed-arrays', 'collections.typed-arrays', <<<'PPP'
<?php
function invalid(): void { array<string, int> $scores = ['John' => 'wrong']; }
PPP, ['P3013']),
    $scenario('collections-list-shape', 'collections.list-shape', <<<'PPP'
<?php
function invalid(): void
{
    array<string> $names = ['John'];
    $names['author'] = 'Mark';
}
PPP, ['P3015']),
    $scenario('collections-transforms', 'collections.transforms', <<<'PPP'
<?php
class Collection<T>
{
    public function normalize(array<T> $items): array<T>
    {
        array<int, T> $filtered = array_filter($items, fn (T $item): bool => true);
        array<T> $values = array_values($filtered);
        return $values;
    }
}
PPP),
    $scenario('flow-generators', 'flow.generators', <<<'PPP'
<?php
function values(): iterable
{
    yield 1;
}
PPP),
    $scenario('errors-declarations', 'errors.declarations', <<<'PPP'
<?php
final class Failure extends RuntimeException {}
function invalid(): void { throw new Failure(); }
PPP, ['P4003']),
    $scenario('errors-propagation', 'errors.propagation', <<<'PPP'
<?php
final class Failure extends RuntimeException {}
function load(): void throws Failure { throw new Failure(); }
function invalid(): void { load(); }
PPP, ['P4003']),
    $scenario('errors-catches', 'errors.catches', <<<'PPP'
<?php
final class Failure extends RuntimeException {}
function load(): void throws Failure { throw new Failure(); }
function invalid(): void
{
    try { load(); } catch (Exception) {} catch (Failure) {}
}
PPP, ['P4013']),
    $scenario('errors-override-covariance', 'errors.override-covariance', <<<'PPP'
<?php
final class Failure extends RuntimeException {}
class ParentService { public function run(): void {} }
class ChildService extends ParentService { public function run(): void throws Failure {} }
PPP, ['P4004']),
    $scenario('interop-ordinary-php-bodies', 'interop.ordinary-php-bodies', <<<'PHP'
<?php
function invalid(int $value): int { return 'wrong'; }
invalid('wrong');
PHP, supplemental: ['P2015', 'P2016'], optional: ['P2099'], disagreement: 'supplemental', path: 'main.php'),
    $scenario('interop-ordinary-php-contracts-negative', 'interop.ordinary-php-contracts', <<<'PPP'
<?php
namespace Boundary;
function invalid(): void { User $user = load(10); }
PPP, ['P2015'], projectFiles: [
        'src/Boundary.php' => <<<'PHP'
<?php
namespace Boundary;
final class User {}
function load(string $id): User { return new User(); }
PHP,
    ]),
    $scenario('interop-ordinary-php-contracts-positive', 'interop.ordinary-php-contracts', <<<'PPP'
<?php
namespace Boundary;
function valid(): void { User $user = load('10'); }
PPP, projectFiles: [
        'src/Boundary.php' => <<<'PHP'
<?php
namespace Boundary;
final class User {}
function load(string $id): User { return new User(); }
PHP,
    ]),
    $scenario('interop-composer-vendor', 'interop.composer-vendor', <<<'PPP'
<?php
function clock(External\Clock $clock): External\Clock { return $clock; }
PPP, projectFiles: [
        'composer.json' => <<<'JSON'
{
  "autoload": {
    "psr-4": {
      "External\\": "packages/External/"
    }
  }
}
JSON,
        'packages/External/Clock.php' => <<<'PHP'
<?php
namespace External;
final class Clock {}
PHP,
    ]),
    $scenario('interop-stubs-positive', 'interop.stubs', <<<'PPP'
<?php
function valid(ExternalService $service): ExternalService { return $service; }
PPP, stubs: [
        'ExternalService.stub.php' => <<<'PHP'
<?php
final class ExternalService {}
PHP,
    ]),
    $scenario('interop-stubs-negative', 'interop.stubs', <<<'PPP'
<?php
namespace Boundary;
function invalid(): void { string $stamp = timestamp('high'); }
PPP, ['P2015'], stubs: [
        'Runtime.stub.php' => <<<'PHP'
<?php
namespace Boundary;
function timestamp(int $precision = 0): string {}
PHP,
    ]),
    $scenario('interop-stubs-conflict', 'interop.stubs', <<<'PPP'
<?php
namespace Boundary;
function useLookup(): void { object $value = lookup(1); }
PPP, ['P6012'], stubs: [
        'Runtime.stub.php' => <<<'PHP'
<?php
namespace Boundary;
function lookup(int $id): object {}
PHP,
    ], projectFiles: [
        'src/Runtime.php' => <<<'PHP'
<?php
namespace Boundary;
function lookup(string $id): object { return new \stdClass(); }
PHP,
    ]),
    $scenario('interop-builtin-signatures', 'interop.builtin-signatures', <<<'PPP'
<?php
function invalid(): void { array_chunk('wrong', 2); }
PPP, supplemental: ['P2015'], optional: ['P2099', 'P2099'], disagreement: 'supplemental'),
    $scenario('infrastructure-backend-failure', 'infrastructure.backend-failure', <<<'PPP'
<?php
function valid(): void {}
PPP, requiredFull: ['P6005'], releaseBlocking: false, disagreement: 'backendGap', backendUnavailable: true),
];
