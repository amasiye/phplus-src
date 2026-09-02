<?php

declare(strict_types=1);

namespace Amasiye\Ppphp\Analysis\Browser;

final readonly class CompilerAnalysisRequestDecoder
{
    public function decode(string $json): CompilerAnalysisRequest
    {
        if (strlen($json) > CompilerAnalysisRequest::MAXIMUM_TRANSPORT_BYTES) {
            throw new \InvalidArgumentException('The browser compiler analysis request is too large.');
        }

        try {
            $payload = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new \InvalidArgumentException('The browser compiler analysis request must be valid JSON.', previous: $exception);
        }

        if (!is_array($payload) || ($payload['version'] ?? null) !== CompilerAnalysisRequest::VERSION) {
            throw new \InvalidArgumentException('The browser analysis protocol version is unsupported.');
        }

        if (($payload['action'] ?? null) !== 'analyze') {
            throw new \InvalidArgumentException('The browser compiler analysis request action must be analyze.');
        }

        if (($payload['operation'] ?? null) !== 'check') {
            throw new \InvalidArgumentException('Browser compiler analysis supports check only.');
        }

        $analysis = $payload['analysis'] ?? null;

        if (!is_array($analysis) || ($analysis['engine'] ?? null) !== 'compiler') {
            throw new \InvalidArgumentException('Browser compiler analysis requires the compiler engine.');
        }

        $requestId = $payload['requestId'] ?? null;
        $selection = $payload['selection'] ?? null;
        $path = is_array($selection) ? ($selection['path'] ?? null) : null;

        if (
            !is_string($requestId)
            || !is_array($selection)
            || !array_key_exists('path', $selection)
            || ($path !== null && !is_string($path))
        ) {
            throw new \InvalidArgumentException('The browser compiler analysis request is malformed.');
        }

        $dependencyContext = $payload['dependencyContext'] ?? null;
        $portableContext = null;

        if ($dependencyContext !== null) {
            if (!is_array($dependencyContext)
                || ($dependencyContext['kind'] ?? null) !== 'portable-index'
                || !is_string($dependencyContext['manifestPath'] ?? null)
                || !is_string($dependencyContext['sha256'] ?? null)) {
                throw new \InvalidArgumentException('The browser dependency context is malformed.');
            }

            $portableContext = new PortableDependencyContext(
                $dependencyContext['manifestPath'],
                $dependencyContext['sha256'],
            );
        }

        return new CompilerAnalysisRequest($requestId, $path, $portableContext);
    }
}
