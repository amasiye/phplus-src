<?php

declare(strict_types=1);

namespace Amasiye\Ppphp\Compiler\Output;

use Amasiye\Ppphp\Compiler\Manifest\BuildManifest;
use Amasiye\Ppphp\Diagnostics\DiagnosticBag;

final readonly class BuildCommitResult
{
    public function __construct(
        public ?BuildManifest $manifest,
        public int $staleRemovalCount,
        public bool $committed,
        public DiagnosticBag $diagnostics,
    ) {
        if ($committed && $manifest === null) {
            throw new \InvalidArgumentException('A committed build requires its manifest.');
        }
    }
}
