<?php

declare(strict_types=1);

namespace Amasiye\Ppphp\Frontend\Ast\Interfaces;

use Amasiye\Ppphp\Frontend\Ast\NodeId;
use Amasiye\Ppphp\Source\Span;

interface Node
{
    public NodeId $id { get; }

    public Span $span { get; }
}
