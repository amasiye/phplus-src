<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Compiler\Output;

use Atatusoft\Ppphp\Compiler\Manifest\BuildManifest;
use Atatusoft\Ppphp\Diagnostics\DiagnosticBag;

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
