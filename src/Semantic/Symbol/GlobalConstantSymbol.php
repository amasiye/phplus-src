<?php

declare(strict_types=1);

namespace Amasiye\Ppphp\Semantic\Symbol;

use Amasiye\Ppphp\Semantic\Type\Interfaces\Type;
use Amasiye\Ppphp\Source\SourceFile;
use Amasiye\Ppphp\Source\Span;

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
