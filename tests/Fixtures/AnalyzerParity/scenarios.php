<?php

declare(strict_types=1);

$scenario = static function (
    string $id,
    string $capabilityId,
    string $source,
    array $compiler = [],
    ?array $full = null,
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
        'expectedFullDiagnostics' => $full ?? $compiler,
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
PPP, [], ['P2099'], true, 'optionalLint'),
    $scenario('declarations-strict', 'declarations.strict', <<<'PPP'
<?php
function invalid($value): void {}
PPP, ['P2011']),
    $scenario('types-aliases', 'types.aliases', <<<'PPP'
<?php
namespace App;
use DateTimeImmutable as Clock;
function identity(Clock $clock): Clock { return $clock; }
PPP),
    $scenario('types-composites', 'types.composites', <<<'PPP'
<?php
class Box<T> {}
function invalid(Box<int&string> $value): void {}
PPP, ['P2030']),
    $scenario('types-assignability', 'types.assignability', <<<'PPP'
<?php
function invalid(): void { int $value = 'wrong'; }
PPP, ['P2008']),
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
    $scenario('flow-when', 'flow.when', <<<'PPP'
<?php
function invalid(bool $value): int
{
    return when ($value) { if (true) { return 1; } } else { return 0; };
}
PPP, ['P5002']),
    $scenario('calls-arguments', 'calls.arguments', <<<'PPP'
<?php
function take(int $value): void {}
function invalid(): void { take('wrong'); }
PPP, [], ['P2015'], true, 'compilerGap'),
    $scenario('calls-returns', 'calls.returns', <<<'PPP'
<?php
function invalid(): int { return 'wrong'; }
PPP, [], ['P2016'], true, 'compilerGap'),
    $scenario('calls-members', 'calls.members', <<<'PPP'
<?php
final class Service {}
function invalid(Service $service): void { $service->missing(); }
PPP, [], ['P2018'], true, 'compilerGap'),
    $scenario('calls-builtins', 'calls.builtins', <<<'PPP'
<?php
function invalid(): void { strlen([]); }
PPP, [], ['P2015', 'P2099', 'P2099'], true, 'compilerGap'),
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
    $scenario('interop-ordinary-php', 'interop.ordinary-php', <<<'PHP'
<?php
function invalid(int $value): int { return 'wrong'; }
invalid('wrong');
PHP, [], ['P2015', 'P2016', 'P2099'], true, 'compilerGap', 'main.php'),
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
    $scenario('interop-stubs', 'interop.stubs', <<<'PPP'
<?php
function valid(ExternalService $service): ExternalService { return $service; }
PPP, stubs: [
        'ExternalService.stub.php' => <<<'PHP'
<?php
final class ExternalService {}
PHP,
    ]),
    $scenario('infrastructure-backend-failure', 'infrastructure.backend-failure', <<<'PPP'
<?php
function valid(): void {}
PPP, [], ['P6005'], false, 'backendGap', backendUnavailable: true),
];
