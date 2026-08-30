<?php

declare(strict_types=1);

namespace Amasiye\Ppphp\Diagnostics;

final readonly class DiagnosticProcessor
{
    public function __construct(
        private DiagnosticValidator $validator = new DiagnosticValidator(),
        private DiagnosticSanitizer $sanitizer = new DiagnosticSanitizer(),
        private DiagnosticDeduplicator $deduplicator = new DiagnosticDeduplicator(),
        private DiagnosticCascadeSuppressor $cascadeSuppressor = new DiagnosticCascadeSuppressor(),
        private DiagnosticSorter $sorter = new DiagnosticSorter(),
    ) {}

    public function process(DiagnosticBag $diagnostics): DiagnosticBag
    {
        return $this->sorter->sort(
            $this->cascadeSuppressor->suppress(
                $this->deduplicator->deduplicate(
                    $this->sanitizer->sanitize(
                        $this->validator->validate($diagnostics),
                    ),
                ),
            ),
        );
    }
}
