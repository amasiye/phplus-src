<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Semantic\Binding;

use Atatusoft\Ppphp\Frontend\Ast\NodeId;
use Atatusoft\Ppphp\Semantic\Binding\Enumerations\BindingMutability;
use Atatusoft\Ppphp\Semantic\Binding\Enumerations\BindingInitialization;
use Atatusoft\Ppphp\Semantic\Type\LocalType;
use Atatusoft\Ppphp\Source\Span;
use PhpParser\Node\Expr;

final class LocalBinding
{
    /** @var list<Span> */
    private array $recordedReads = [];

    /** @var list<Span> */
    private array $recordedWrites = [];

    private BindingInitialization $currentInitialization;

    public function __construct(
        public readonly NodeId $id,
        public readonly string $name,
        public readonly LocalType $type,
        public readonly BindingMutability $mutability,
        public readonly Span $declarationSpan,
        public readonly Span $variableSpan,
        public readonly ?Span $initializerSpan,
        public readonly ?Expr $initializerExpression,
        public readonly LocalType $initializerType,
        BindingInitialization $initialization = BindingInitialization::Initialized,
    ) {
        $this->currentInitialization = $initialization;
    }

    /** @var list<Span> */
    public array $reads {
        get => $this->recordedReads;
    }

    /** @var list<Span> */
    public array $writes {
        get => $this->recordedWrites;
    }

    public BindingInitialization $initialization {
        get => $this->currentInitialization;
    }

    public function markInitialized(): void
    {
        $this->currentInitialization = BindingInitialization::Initialized;
    }

    public function markMaybeUninitialized(): void
    {
        $this->currentInitialization = BindingInitialization::MaybeUninitialized;
    }

    public function recordRead(Span $span): void
    {
        $this->recordedReads[] = $span;
    }

    public function recordWrite(Span $span): void
    {
        $this->recordedWrites[] = $span;
    }
}
