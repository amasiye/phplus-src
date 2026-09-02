<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Frontend\Ast\Interfaces;

use Atatusoft\Ppphp\Frontend\Ast\NodeId;
use Atatusoft\Ppphp\Source\Span;

interface Node
{
    public NodeId $id { get; }

    public Span $span { get; }
}
