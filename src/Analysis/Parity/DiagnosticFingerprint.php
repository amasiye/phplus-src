<?php

declare(strict_types=1);

namespace Amasiye\Ppphp\Analysis\Parity;

use Amasiye\Ppphp\Diagnostics\Diagnostic;

final readonly class DiagnosticFingerprint
{
    public function __construct(
        public string $code,
        public ?string $path,
        public ?int $start,
        public ?int $end,
        public ?string $identity,
    ) {}

    public static function fromDiagnostic(Diagnostic $diagnostic): self
    {
        return new self(
            $diagnostic->code->value,
            $diagnostic->primary?->span->sourceFile->displayPath,
            $diagnostic->primary?->span->start->offset,
            $diagnostic->primary?->span->end->offset,
            $diagnostic->identity,
        );
    }

    public function key(): string
    {
        return implode('|', [
            $this->code,
            $this->path ?? '',
            (string) ($this->start ?? -1),
            (string) ($this->end ?? -1),
            $this->identity ?? '',
        ]);
    }

    /** @return array{code: string, path: ?string, range: ?array{start: int, end: int}, identity: ?string} */
    public function toArray(): array
    {
        return [
            'code' => $this->code,
            'path' => $this->path,
            'range' => $this->start === null || $this->end === null
                ? null
                : ['start' => $this->start, 'end' => $this->end],
            'identity' => $this->identity,
        ];
    }
}
