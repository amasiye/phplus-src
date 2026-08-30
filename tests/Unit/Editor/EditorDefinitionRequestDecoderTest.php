<?php

declare(strict_types=1);

use Amasiye\Ppphp\Editor\EditorDefinitionRequest;
use Amasiye\Ppphp\Editor\EditorDefinitionRequestDecoder;

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
