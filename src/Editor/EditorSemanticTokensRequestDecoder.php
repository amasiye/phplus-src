<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Editor;

final readonly class EditorSemanticTokensRequestDecoder
{
    public function decode(string $json): EditorSemanticTokensRequest
    {
        if (strlen($json) > EditorSemanticTokensRequest::MAXIMUM_CONTENT_BYTES + 16_384) {
            throw new \InvalidArgumentException('The editor semantic tokens request is too large.');
        }

        try {
            $payload = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new \InvalidArgumentException('The editor semantic tokens request must be valid JSON.', previous: $exception);
        }

        if (!is_array($payload) || ($payload['version'] ?? null) !== EditorSemanticTokensRequest::VERSION) {
            throw new \InvalidArgumentException('The editor semantic tokens request version is unsupported.');
        }

        $document = $payload['document'] ?? null;
        $path = is_array($document) ? ($document['path'] ?? null) : null;
        $contents = is_array($document) ? ($document['contents'] ?? null) : null;

        if (!is_string($path) || !is_string($contents)) {
            throw new \InvalidArgumentException('The editor semantic tokens request requires document.path and document.contents.');
        }

        return new EditorSemanticTokensRequest($path, $contents);
    }
}
