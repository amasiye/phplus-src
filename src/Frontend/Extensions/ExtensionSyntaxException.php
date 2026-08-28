<?php

declare(strict_types=1);

namespace Amasiye\Phplus\Frontend\Extensions;

use Amasiye\Phplus\Source\Span;

final class ExtensionSyntaxException extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly Span $span,
        public readonly bool $isUnsupported = false,
    ) {
        parent::__construct($message);
    }
}
