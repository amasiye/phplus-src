<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Editor;

final readonly class EditorDiagnosticsRequest
{
    public const int VERSION = 1;
    public const int MAXIMUM_DOCUMENTS = 32;
    public const int MAXIMUM_CONTENT_BYTES = 2_097_152;
    public const int MAXIMUM_TOTAL_CONTENT_BYTES = 8_388_608;
    public const int MAXIMUM_REQUEST_BYTES = 16_777_216;
    public const int MAXIMUM_DIAGNOSTICS = 1000;
    public const int MAXIMUM_RESPONSE_BYTES = 4_194_304;

    /** @param non-empty-list<array{path: string, contents: string}> $documents Target first, then context overlays. */
    public function __construct(
        public array $documents,
        public ?int $documentVersion,
    ) {}
}
