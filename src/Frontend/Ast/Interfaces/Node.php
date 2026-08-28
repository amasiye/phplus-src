<?php

declare(strict_types=1);

namespace Amasiye\Phplus\Frontend\Ast\Interfaces;

use Amasiye\Phplus\Frontend\Ast\NodeId;
use Amasiye\Phplus\Source\Span;

interface Node
{
    public NodeId $id { get; }

    public Span $span { get; }
}
