<?php

declare(strict_types=1);

namespace Amasiye\Ppphp\Analysis\Parity;

use Amasiye\Ppphp\Diagnostics\Diagnostic;

final class DiagnosticFingerprint
{
    public function __construct(
        public readonly string $code,
        public readonly ?string $path,
        public readonly ?int $start,
        public readonly ?int $end,
        public readonly ?string $identity,
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

    public string $key {
        get => implode('|', [
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
