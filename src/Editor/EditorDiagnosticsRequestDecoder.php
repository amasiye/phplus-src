<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Editor;

final readonly class EditorDiagnosticsRequestDecoder
{
    public function decode(string $json): EditorDiagnosticsRequest
    {
        if (strlen($json) > EditorDiagnosticsRequest::MAXIMUM_REQUEST_BYTES) {
            throw new \InvalidArgumentException('The editor diagnostics request exceeds sixteen mebibytes.');
        }

        try {
            $payload = json_decode($json, false, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new \InvalidArgumentException('The editor diagnostics request must be valid UTF-8 JSON.', previous: $exception);
        }

        if (!$payload instanceof \stdClass || ($payload->version ?? null) !== EditorDiagnosticsRequest::VERSION) {
            throw new \InvalidArgumentException('The editor diagnostics request version is unsupported.');
        }

        $target = $payload->document ?? null;
        $overlays = property_exists($payload, 'overlays') ? $payload->overlays : [];

        if (!$target instanceof \stdClass || !is_array($overlays)
            || count($overlays) >= EditorDiagnosticsRequest::MAXIMUM_DOCUMENTS) {
            throw new \InvalidArgumentException('Provide one document and a list of at most 31 context overlays.');
        }

        $version = $target->version ?? null;

        if ((property_exists($target, 'version') && $version === null) || ($version !== null && !is_int($version))) {
            throw new \InvalidArgumentException('The editor document version must be an integer when supplied.');
        }

        $documents = [];
        $bytes = 0;

        foreach ([$target, ...$overlays] as $document) {
            $path = $document instanceof \stdClass ? ($document->path ?? null) : null;
            $contents = $document instanceof \stdClass ? ($document->contents ?? null) : null;

            if (!is_string($path) || $path === '' || strlen($path) > 4096
                || preg_match('/[\x00-\x1f\x7f]/', $path) === 1
                || in_array('..', explode('/', str_replace('\\', '/', $path)), true)
                || !is_string($contents)) {
                throw new \InvalidArgumentException('Each editor document requires a safe path of 1–4096 bytes and string contents.');
            }

            $bytes += strlen($contents);

            if (strlen($contents) > EditorDiagnosticsRequest::MAXIMUM_CONTENT_BYTES
                || $bytes > EditorDiagnosticsRequest::MAXIMUM_TOTAL_CONTENT_BYTES) {
                throw new \InvalidArgumentException('Editor contents exceed two mebibytes per document or eight mebibytes in total.');
            }

            $documents[] = ['path' => $path, 'contents' => $contents];
        }

        return new EditorDiagnosticsRequest($documents, $version);
    }
}
