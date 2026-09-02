<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Analysis\Browser;

final readonly class PrepareAnalysisRequestDecoder
{
    public function decode(string $json): PrepareAnalysisRequest
    {
        if (strlen($json) > PrepareAnalysisRequest::MAXIMUM_TRANSPORT_BYTES) {
            throw new \InvalidArgumentException('The browser Prepare Analysis request is too large.');
        }

        try {
            $payload = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new \InvalidArgumentException('The browser Prepare Analysis request must be valid JSON.', previous: $exception);
        }

        if (!is_array($payload) || ($payload['version'] ?? null) !== PrepareAnalysisRequest::VERSION) {
            throw new \InvalidArgumentException('The browser analysis protocol version is unsupported.');
        }

        if (($payload['action'] ?? null) !== 'prepare') {
            throw new \InvalidArgumentException('The browser analysis request action must be prepare.');
        }

        $requestId = $payload['requestId'] ?? null;
        $operation = $payload['operation'] ?? null;
        $selection = $payload['selection'] ?? null;
        $path = is_array($selection) ? ($selection['path'] ?? null) : null;

        if (!is_string($requestId) || !is_string($operation) || ($path !== null && !is_string($path))) {
            throw new \InvalidArgumentException('The browser Prepare Analysis request is malformed.');
        }

        return new PrepareAnalysisRequest($requestId, $operation, $path);
    }
}
