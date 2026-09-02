<?php

declare(strict_types=1);

use Atatusoft\Ppphp\Editor\EditorSemanticTokensRequest;
use Atatusoft\Ppphp\Editor\EditorSemanticTokensRequestDecoder;

test('semantic token requests decode bounded unsaved documents', function (): void {
    $request = (new EditorSemanticTokensRequestDecoder())->decode(json_encode([
        'version' => EditorSemanticTokensRequest::VERSION,
        'document' => [
            'path' => 'src/Box.ppphp',
            'contents' => "<?php\nclass Box {}\n",
        ],
    ], JSON_THROW_ON_ERROR));

    expect($request->path)->toBe('src/Box.ppphp')
        ->and($request->contents)->toContain('class Box');
});

test('semantic token requests reject unsupported and incomplete envelopes', function (array $payload): void {
    expect(fn (): EditorSemanticTokensRequest =>
        (new EditorSemanticTokensRequestDecoder())->decode(json_encode($payload, JSON_THROW_ON_ERROR)))
        ->toThrow(InvalidArgumentException::class);
})->with([
    [['version' => 2, 'document' => ['path' => 'src/Box.ppphp', 'contents' => '']]],
    [['version' => 1, 'document' => ['path' => 'src/Box.ppphp']]],
    [['version' => 1, 'document' => ['path' => '', 'contents' => '']]],
]);
