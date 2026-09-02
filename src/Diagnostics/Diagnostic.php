<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Diagnostics;

use Atatusoft\Ppphp\Diagnostics\Enumerations\DiagnosticCode;
use Atatusoft\Ppphp\Diagnostics\Enumerations\DiagnosticFamily;
use Atatusoft\Ppphp\Diagnostics\Enumerations\DiagnosticOrigin;
use Atatusoft\Ppphp\Diagnostics\Enumerations\DiagnosticStatus;
use Atatusoft\Ppphp\Diagnostics\Enumerations\Severity;

final class Diagnostic
{
    public readonly ?string $help;

    /**
     * @param list<DiagnosticLabel> $related
     * @param array<string, mixed> $debug
     */
    public function __construct(
        public readonly DiagnosticCode $code,
        public readonly string $message,
        public readonly ?DiagnosticLabel $primary = null,
        public readonly array $related = [],
        ?string $help = null,
        public readonly array $debug = [],
        public readonly DiagnosticOrigin $origin = DiagnosticOrigin::Compiler,
        public readonly ?string $identity = null,
    ) {
        if ($this->status === DiagnosticStatus::Reserved) {
            throw new \LogicException(sprintf('Diagnostic code %s is reserved and cannot be emitted.', $code->value));
        }

        $this->help = $help ?? DiagnosticHelpProvider::resolve($code);
    }

    public DiagnosticDefinition $definition {
        get => DiagnosticCatalog::definition($this->code);
    }

    public DiagnosticFamily $family {
        get => $this->definition->family;
    }

    public DiagnosticStatus $status {
        get => $this->definition->status;
    }

    public Severity $severity {
        get => $this->definition->severity;
    }

    public string $title {
        get => $this->definition->title;
    }
}
