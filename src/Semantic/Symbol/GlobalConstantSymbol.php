<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Semantic\Symbol;

use Atatusoft\Ppphp\Semantic\Type\Interfaces\Type;
use Atatusoft\Ppphp\Source\SourceFile;
use Atatusoft\Ppphp\Source\Span;

final readonly class GlobalConstantSymbol
{
    public function __construct(
        public string $fullyQualifiedName,
        public string $namespace,
        public ?Type $type,
        public SourceFile $sourceFile,
        public Span $declarationSpan,
        public Span $selectionSpan,
    ) {}
}
