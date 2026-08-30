<?php

declare(strict_types=1);

namespace Amasiye\Ppphp\Semantic\Effect;

final readonly class ErrorFlow
{
    public function __construct(
        public ErrorSet $escapingErrors,
        public bool $mayCompleteNormally = true,
    ) {}

    public static function createEmpty(bool $mayCompleteNormally = true): self
    {
        return new self(new ErrorSet(), $mayCompleteNormally);
    }

    public function continueWith(self $next): self
    {
        if (!$this->mayCompleteNormally) {
            return $this;
        }

        return new self(
            $this->escapingErrors->combine($next->escapingErrors),
            $next->mayCompleteNormally,
        );
    }
}
