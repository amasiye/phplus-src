<?php

declare(strict_types=1);

use Atatusoft\Ppphp\Interop\PhpDoc\PhpDocReader;
use PhpParser\Comment\Doc;

test('PHPDoc metadata reader exposes the supported generic contract tags', function (): void {
    $metadata = (new PhpDocReader())->readMetadata(new Doc(<<<'DOC'
/**
 * Summary.
 * @template T of Entity
 * @extends Base<T>
 * @implements Contract<T>
 * @use Stores<T>
 * @param list<T> $items Description.
 * @return array<string, T>
 * @var T $value
 * @throws Failure
 */
DOC));

    expect($metadata->templates)->toBe([['name' => 'T', 'bound' => 'Entity']])
        ->and($metadata->extends)->toBe(['Base<T>'])
        ->and($metadata->implements)->toBe(['Contract<T>'])
        ->and($metadata->uses)->toBe(['Stores<T>'])
        ->and($metadata->parameters)->toBe(['$items' => 'list<T>'])
        ->and($metadata->returns)->toBe(['array<string, T>'])
        ->and($metadata->variables)->toBe(['$value' => 'T'])
        ->and($metadata->throws)->toBe(['Failure']);
});
