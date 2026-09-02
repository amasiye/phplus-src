<?php

declare(strict_types=1);

namespace Amasiye\Ppphp\Analysis\Browser;

final readonly class CompilerAnalysisRequest
{
    public const int VERSION = 2;
    public const int MAXIMUM_TRANSPORT_BYTES = 16_384;
    public const int MAXIMUM_SOURCE_FILES = 256;
    public const int MAXIMUM_SOURCE_BYTES = 4_194_304;
    public const int MAXIMUM_DIAGNOSTICS = 1_000;
    public const int MAXIMUM_RESPONSE_BYTES = 2_097_152;

    public function __construct(
        public string $requestId,
        public ?string $path,
        public ?PortableDependencyContext $dependencyContext = null,
    ) {
        if ($requestId === '' || strlen($requestId) > 128) {
            throw new \InvalidArgumentException('The browser analysis request identifier must contain between 1 and 128 bytes.');
        }

        if ($path !== null && ($path === '' || strlen($path) > 4096)) {
            throw new \InvalidArgumentException('The browser analysis selection path must contain between 1 and 4096 bytes.');
        }
    }
}
