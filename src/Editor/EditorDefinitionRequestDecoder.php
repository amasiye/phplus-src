<?php

declare(strict_types=1);

namespace Amasiye\Ppphp\Editor;

final readonly class EditorDefinitionRequestDecoder
{
    public function decode(string $json): EditorDefinitionRequest
    {
        if (strlen($json) > EditorDefinitionRequest::MAXIMUM_TRANSPORT_BYTES) {
            throw new \InvalidArgumentException('The editor definition request is too large.');
        }

        try {
            $payload = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new \InvalidArgumentException('The editor definition request must be valid JSON.', previous: $exception);
        }

        if (!is_array($payload) || ($payload['version'] ?? null) !== EditorDefinitionRequest::VERSION) {
            throw new \InvalidArgumentException('The editor definition request version is unsupported.');
        }

        $document = $payload['document'] ?? null;
        $position = $payload['position'] ?? null;
        $path = is_array($document) ? ($document['path'] ?? null) : null;
        $contents = is_array($document) ? ($document['contents'] ?? null) : null;
        $offset = is_array($position) ? ($position['offset'] ?? null) : null;

        if (!is_string($path) || !is_string($contents) || !is_int($offset)) {
            throw new \InvalidArgumentException('The editor definition request requires document.path, document.contents, and position.offset.');
        }

        return new EditorDefinitionRequest($path, $contents, $offset);
    }
}
