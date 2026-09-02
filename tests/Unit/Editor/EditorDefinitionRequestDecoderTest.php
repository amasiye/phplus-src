<?php

declare(strict_types=1);

use Atatusoft\Ppphp\Editor\EditorDefinitionRequest;
use Atatusoft\Ppphp\Editor\EditorDefinitionRequestDecoder;

test('editor definition requests use a bounded versioned protocol', function (): void {
    $request = (new EditorDefinitionRequestDecoder())->decode(json_encode([
        'version' => EditorDefinitionRequest::VERSION,
        'document' => [
            'path' => 'src/index.ppphp',
            'contents' => "<?php\necho 'hello';\n",
        ],
        'position' => ['offset' => 7],
    ], JSON_THROW_ON_ERROR));

    expect($request->path)->toBe('src/index.ppphp')
        ->and($request->contents)->toBe("<?php\necho 'hello';\n")
        ->and($request->offset)->toBe(7);
});

test('editor definition requests reject unsupported versions and invalid offsets', function (): void {
    $decoder = new EditorDefinitionRequestDecoder();

    expect(fn (): EditorDefinitionRequest => $decoder->decode(json_encode([
        'version' => 2,
        'document' => ['path' => 'src/index.ppphp', 'contents' => '<?php'],
        'position' => ['offset' => 0],
    ], JSON_THROW_ON_ERROR)))->toThrow(InvalidArgumentException::class, 'version is unsupported')
        ->and(fn (): EditorDefinitionRequest => $decoder->decode(json_encode([
            'version' => EditorDefinitionRequest::VERSION,
            'document' => ['path' => 'src/index.ppphp', 'contents' => '<?php'],
            'position' => ['offset' => 6],
        ], JSON_THROW_ON_ERROR)))->toThrow(InvalidArgumentException::class, 'outside the document');
});

test('editor definition requests reject oversized documents', function (): void {
    expect(fn (): EditorDefinitionRequest => new EditorDefinitionRequest(
        'src/index.ppphp',
        str_repeat('x', EditorDefinitionRequest::MAXIMUM_CONTENT_BYTES + 1),
        0,
    ))->toThrow(InvalidArgumentException::class, 'two-megabyte request limit');
});

test('editor definition transport accepts maximally escaped valid documents and remains bounded', function (): void {
    $decoder = new EditorDefinitionRequestDecoder();
    $contents = str_repeat("\0", EditorDefinitionRequest::MAXIMUM_CONTENT_BYTES);
    $json = json_encode([
        'version' => EditorDefinitionRequest::VERSION,
        'document' => ['path' => 'src/index.ppphp', 'contents' => $contents],
        'position' => ['offset' => 0],
    ], JSON_THROW_ON_ERROR);

    expect(strlen($json))->toBeGreaterThan(EditorDefinitionRequest::MAXIMUM_CONTENT_BYTES)
        ->and($decoder->decode($json)->contents)->toBe($contents)
        ->and(fn (): EditorDefinitionRequest => $decoder->decode(
            str_repeat(' ', EditorDefinitionRequest::MAXIMUM_TRANSPORT_BYTES + 1),
        ))->toThrow(InvalidArgumentException::class, 'request is too large');
});
