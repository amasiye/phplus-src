<?php

declare(strict_types=1);

use Atatusoft\Ppphp\Editor\EditorDiagnosticsRequest;
use Atatusoft\Ppphp\Editor\EditorDiagnosticsRequestDecoder;

test('diagnostic requests preserve target version and ordered unsaved context', function (): void {
    $document = ['path' => 'src/main.ppphp', 'contents' => '<?php int $value = 1;'];
    $overlay = ['path' => 'src/Model.php', 'contents' => '<?php class Model {}'];
    $request = (new EditorDiagnosticsRequestDecoder())->decode(json_encode([
        'version' => 1, 'document' => [...$document, 'version' => 42], 'overlays' => [$overlay],
    ], JSON_THROW_ON_ERROR));
    expect($request->documentVersion)->toBe(42)->and($request->documents)->toBe([$document, $overlay]);
});

test('diagnostic request decoding fails closed', function (array $payload): void {
    expect(fn () => (new EditorDiagnosticsRequestDecoder())->decode(json_encode($payload, JSON_THROW_ON_ERROR)))
        ->toThrow(InvalidArgumentException::class);
})->with([
    'unknown version' => [['version' => 2]],
    'string version' => [['version' => '1']],
    'missing document' => [['version' => 1]],
    'missing contents' => [['version' => 1, 'document' => ['path' => 'src/a.ppphp']]],
    'null contents' => [['version' => 1, 'document' => ['path' => 'src/a.ppphp', 'contents' => null]]],
    'empty path' => [['version' => 1, 'document' => ['path' => '', 'contents' => '']]],
    'nul path' => [['version' => 1, 'document' => ['path' => "src/a\0.ppphp", 'contents' => '']]],
    'traversal' => [['version' => 1, 'document' => ['path' => 'src/../a.ppphp', 'contents' => '']]],
    'windows traversal' => [['version' => 1, 'document' => ['path' => 'src\\..\\a.ppphp', 'contents' => '']]],
    'null document version' => [['version' => 1, 'document' => ['path' => 'src/a.ppphp', 'contents' => '', 'version' => null]]],
    'string document version' => [['version' => 1, 'document' => ['path' => 'src/a.ppphp', 'contents' => '', 'version' => '7']]],
    'object overlays' => [['version' => 1, 'document' => ['path' => 'src/a.ppphp', 'contents' => ''], 'overlays' => ['path' => 'x']]],
    'null overlays' => [['version' => 1, 'document' => ['path' => 'src/a.ppphp', 'contents' => ''], 'overlays' => null]],
    'empty object overlays' => [['version' => 1, 'document' => ['path' => 'src/a.ppphp', 'contents' => ''], 'overlays' => new stdClass()]],
    'invalid overlay' => [['version' => 1, 'document' => ['path' => 'src/a.ppphp', 'contents' => ''], 'overlays' => [null]]],
]);

test('diagnostic requests enforce byte and document limits', function (): void {
    $decoder = new EditorDiagnosticsRequestDecoder();
    $document = ['path' => 'src/main.ppphp', 'contents' => ''];
    foreach ([
        ['document' => ['path' => str_repeat('x', 4097), 'contents' => '']],
        ['document' => [...$document, 'contents' => str_repeat('x', EditorDiagnosticsRequest::MAXIMUM_CONTENT_BYTES + 1)]],
        ['document' => $document, 'overlays' => array_fill(0, 32, $document)],
        ['document' => $document, 'overlays' => array_fill(0, 5, [...$document, 'contents' => str_repeat('x', EditorDiagnosticsRequest::MAXIMUM_CONTENT_BYTES)])],
    ] as $payload) {
        expect(fn () => $decoder->decode(json_encode(['version' => 1, ...$payload], JSON_THROW_ON_ERROR)))
            ->toThrow(InvalidArgumentException::class);
    }
    expect(fn () => $decoder->decode(str_repeat(' ', EditorDiagnosticsRequest::MAXIMUM_REQUEST_BYTES + 1)))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => $decoder->decode("{\"version\":1,\"document\":{\"path\":\"src/a.ppphp\",\"contents\":\"\xff\"}}"))
        ->toThrow(InvalidArgumentException::class);

    $accepted = $decoder->decode(json_encode([
        'version' => 1,
        'document' => [...$document, 'contents' => str_repeat('é', EditorDiagnosticsRequest::MAXIMUM_CONTENT_BYTES / 2)],
        'overlays' => array_fill(0, 31, $document),
    ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    expect($accepted->documents)->toHaveCount(32)->and($accepted->documentVersion)->toBeNull();
});
