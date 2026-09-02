<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Analysis\Browser;

final readonly class PrepareAnalysisRequest
{
    public const int VERSION = 1;

    public const int MAXIMUM_TRANSPORT_BYTES = 16_384;

    public function __construct(
        public string $requestId,
        public string $operation,
        public ?string $path,
    ) {
        if ($requestId === '' || strlen($requestId) > 128) {
            throw new \InvalidArgumentException('The browser analysis request identifier must contain between 1 and 128 bytes.');
        }

        if (!in_array($operation, ['check', 'build'], true)) {
            throw new \InvalidArgumentException('The browser analysis operation must be check or build.');
        }

        if ($path !== null && ($path === '' || strlen($path) > 4096)) {
            throw new \InvalidArgumentException('The browser analysis selection path must contain between 1 and 4096 bytes.');
        }
    }
}
