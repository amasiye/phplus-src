<?php

declare(strict_types=1);

namespace Amasiye\Ppphp\Semantic\Type;

use Amasiye\Ppphp\Semantic\Type\Interfaces\Type;

final readonly class ExpressionTypeResolution
{
    public function __construct(
        public Type $type,
        public ExpressionResolutionStatus $status,
        public string $provenance,
    ) {}

    public static function known(Type $type, string $provenance = 'compiler'): self
    {
        return new self($type, ExpressionResolutionStatus::Known, $provenance);
    }

    public static function unknown(
        ExpressionResolutionStatus $status = ExpressionResolutionStatus::UnknownExpression,
        string $provenance = 'unsupported-expression',
    ): self {
        return new self(new UnknownType(), $status, $provenance);
    }
}
