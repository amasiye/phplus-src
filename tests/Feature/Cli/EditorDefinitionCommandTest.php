<?php

declare(strict_types=1);

use Amasiye\Ppphp\Cli\Enumerations\ExitCode;
use Amasiye\Ppphp\Editor\EditorDefinitionRequest;
use Symfony\Component\Process\Process;

/** @return array<string, mixed> */
function resolveEditorDefinition(string $root, string $path, string $contents, string $needle, int $occurrence = 1): array
{
    $offset = -1;

    for ($index = 0; $index < $occurrence; $index++) {
        $offset = strpos($contents, $needle, $offset + 1);

        if ($offset === false) {
            throw new RuntimeException(sprintf('Unable to find occurrence %d of "%s".', $occurrence, $needle));
        }
    }

    $process = new Process([
        PHP_BINARY,
        dirname(__DIR__, 3) . '/bin/ppphp',
        'editor:definition',
        '--working-directory',
        $root,
        '--format=json',
        '--no-ansi',
    ]);
    $process->setInput(json_encode([
        'version' => EditorDefinitionRequest::VERSION,
        'document' => [
            'path' => $path,
            'contents' => $contents,
        ],
        'position' => ['offset' => $offset],
    ], JSON_THROW_ON_ERROR));
    $process->run();

    expect($process->getExitCode())->toBe(ExitCode::Success->value, $process->getErrorOutput() . $process->getOutput());

    $response = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);

    if (!is_array($response)) {
        throw new RuntimeException('The editor definition response must be an object.');
    }

    return $response;
}

test('editor definitions resolve project symbols, members, inheritance, chains, locals, and parameters', function (): void {
    $root = $this->createTemporaryDirectory();
    $this->writeConfiguration($root);
    $model = <<<'PPPHP'
<?php

namespace My\App\Core;

class Entity
{
    public string $id;

    public function identifier(): string
    {
        return $this->id;
    }
}

class Address
{
    public string $city;
}

class Box<T>
{
    public function __construct(public T $value)
    {
    }

    public function getValue(): T
    {
        return $this->value;
    }
}

class Person extends Entity
{
    public string $firstName;

    public function address(): Address
    {
        return new Address();
    }

    public function renamed(): self
    {
        return $this;
    }
}
PPPHP;
    $this->writeFile($root . '/src/Core/Model.ppphp', $model);
    $this->writeFile($root . '/src/Interop/Clock.php', <<<'PHP'
<?php

namespace My\App\Interop;

class Clock
{
    public function now(): string
    {
        return 'now';
    }
}
PHP);
    $support = <<<'PPPHP'
<?php

namespace My\App\Support;

use My\App\Core\Address;
use My\App\Core\Person;

function greet(Person $person): string
{
    return $person->firstName;
}

function createAddress(): Address
{
    return new Address();
}
PPPHP;
    $this->writeFile($root . '/src/Support/functions.ppphp', $support);
    $onDisk = <<<'PPPHP'
<?php

namespace My\App;

use My\App\Core\Person;
use My\App\Core\Box;
use My\App\Interop\Clock;
use function My\App\Support\createAddress;
use function My\App\Support\greet;

Person $person = new Person();
Box<Person> $box = new Box($person);
Clock $clock = new Clock();
echo greet($person);
echo $person->firstName;
echo $person->identifier();
echo $clock->now();
PPPHP;
    $this->writeFile($root . '/src/index.ppphp', $onDisk);
    $this->writeFile($root . '/src/UnrelatedBroken.ppphp', '<?php function incomplete(');
    $unsaved = $onDisk . "\necho \$person->address()->city;\necho \$person->renamed()->firstName;\necho createAddress()->city;\necho \$box->getValue()->firstName;\n";

    $expectations = [
        ['src/index.ppphp', $unsaved, 'Person', 3, 'type:my\\app\\core\\person', 'src/Core/Model.ppphp'],
        ['src/index.ppphp', $unsaved, 'greet', 2, 'function:my\\app\\support\\greet', 'src/Support/functions.ppphp'],
        ['src/index.ppphp', $unsaved, '$person', 2, 'local:src/index.ppphp:', 'src/index.ppphp'],
        ['src/index.ppphp', $unsaved, 'firstName', 1, 'property:my\\app\\core\\person::$firstName', 'src/Core/Model.ppphp'],
        ['src/index.ppphp', $unsaved, 'identifier', 1, 'method:my\\app\\core\\entity::identifier', 'src/Core/Model.ppphp'],
        ['src/index.ppphp', $unsaved, 'city', 1, 'property:my\\app\\core\\address::$city', 'src/Core/Model.ppphp'],
        ['src/index.ppphp', $unsaved, 'city', 2, 'property:my\\app\\core\\address::$city', 'src/Core/Model.ppphp'],
        ['src/index.ppphp', $unsaved, 'firstName', 2, 'property:my\\app\\core\\person::$firstName', 'src/Core/Model.ppphp'],
        ['src/index.ppphp', $unsaved, 'firstName', 3, 'property:my\\app\\core\\person::$firstName', 'src/Core/Model.ppphp'],
        ['src/index.ppphp', $unsaved, 'now', 1, 'method:my\\app\\interop\\clock::now', 'src/Interop/Clock.php'],
        ['src/Support/functions.ppphp', $support, '$person', 2, 'parameter:function:my\\app\\support\\greet:$person', 'src/Support/functions.ppphp'],
        ['src/Core/Model.ppphp', $model, '$this', 3, 'type:my\\app\\core\\person', 'src/Core/Model.ppphp'],
    ];

    foreach ($expectations as [$path, $contents, $needle, $occurrence, $symbolId, $file]) {
        $response = resolveEditorDefinition($root, $path, $contents, $needle, $occurrence);
        $definition = $response['definition'] ?? null;

        expect($response['version'] ?? null)->toBe(EditorDefinitionRequest::VERSION)
            ->and($response['error'] ?? null)->toBeNull()
            ->and($definition)->toBeArray()
            ->and($definition['symbolId'] ?? null)->toStartWith($symbolId)
            ->and($definition['location']['file'] ?? null)->toBe($file)
            ->and($definition['location']['selectionRange']['start']['offset'] ?? null)->toBeInt();
    }
});

test('editor definitions resolve imported function declarations and return null for non-symbol text', function (): void {
    $root = $this->createTemporaryDirectory();
    $this->writeConfiguration($root);
    $this->writeFile($root . '/src/functions.ppphp', "<?php\nnamespace My\\App;\nfunction greet(): void {}\n");
    $source = "<?php\nnamespace Consumer;\nuse function My\\App\\greet;\ngreet();\n// ordinary words\n";
    $this->writeFile($root . '/src/index.ppphp', $source);

    $import = resolveEditorDefinition($root, 'src/index.ppphp', $source, 'greet', 1);
    $ordinary = resolveEditorDefinition($root, 'src/index.ppphp', $source, 'ordinary');

    expect($import['definition']['symbolId'] ?? null)->toBe('function:my\\app\\greet')
        ->and(array_key_exists('definition', $ordinary))->toBeTrue()
        ->and($ordinary['definition'])->toBeNull();
});

test('editor definitions resolve nested generic components and owner-qualified parameters', function (): void {
    $root = $this->createTemporaryDirectory();
    $this->writeConfiguration($root);
    $domain = <<<'PPPHP'
<?php
namespace Domain;
final class Product {}
final class Item<T> {}
final class Cart<TItem>
{
    public TItem $item;

    public function retain(array<TItem> $items): array<TItem>
    {
        foreach ($items as TItem $item) {}
        callable $callback = fn (TItem $value): TItem => $value;
        return $items;
    }
}
PPPHP;
    $consumer = <<<'PPPHP'
<?php
namespace Application;
use Domain\Cart;
use Domain\Item;
use Domain\Product;
final class User
{
    public Cart<Item<Product>> $cart;
}
PPPHP;
    $this->writeFile($root . '/src/Domain.ppphp', $domain);
    $this->writeFile($root . '/src/User.ppphp', $consumer);

    foreach ([
        ['Cart', 2, 'type:domain\cart', 'src/Domain.ppphp'],
        ['Item', 2, 'type:domain\item', 'src/Domain.ppphp'],
        ['Product', 2, 'type:domain\product', 'src/Domain.ppphp'],
    ] as [$needle, $occurrence, $symbol, $file]) {
        $response = resolveEditorDefinition($root, 'src/User.ppphp', $consumer, $needle, $occurrence);

        expect($response['definition']['symbolId'] ?? null)->toBe($symbol)
            ->and($response['definition']['location']['file'] ?? null)->toBe($file);
    }

    foreach ([1, 2, 3, 4, 5, 6] as $occurrence) {
        $response = resolveEditorDefinition($root, 'src/Domain.ppphp', $domain, 'TItem', $occurrence);

        expect($response['definition']['symbolId'] ?? null)->toStartWith('type-parameter:')
            ->and($response['definition']['location']['file'] ?? null)->toBe('src/Domain.ppphp')
            ->and($response['definition']['location']['selectionRange']['start']['offset'] ?? null)
            ->toBe(strpos($domain, 'TItem'));
    }
});
