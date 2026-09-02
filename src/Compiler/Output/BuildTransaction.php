<?php

declare(strict_types=1);

namespace Amasiye\Ppphp\Compiler\Output;

use Amasiye\Ppphp\Compiler\Output\Enumerations\BuildTransactionState;

final readonly class BuildTransaction
{
    public const int FORMAT_VERSION = 1;

    public function __construct(
        public string $identity,
        public string $output,
        public string $stage,
        public string $backup,
        public string $candidateManifestIdentity,
        public ?string $priorManifestIdentity,
        public BuildTransactionState $state,
    ) {}

    public function withState(BuildTransactionState $state): self
    {
        return new self(
            $this->identity,
            $this->output,
            $this->stage,
            $this->backup,
            $this->candidateManifestIdentity,
            $this->priorManifestIdentity,
            $state,
        );
    }
}
