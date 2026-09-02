<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Semantic\Type\Interfaces;

interface Type
{
    public string $canonical { get; }

    public bool $isNullable { get; }

    public bool $isUnknown { get; }

    public function renderPhpDoc(): string;

    public function eraseToNative(): string;
}
