<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Semantic\Binding;

use Atatusoft\Ppphp\Frontend\Ast\NodeId;

final class BindingTable
{
    /** @var array<string, LocalBinding> */
    private array $recordedBindings = [];

    /** @var list<LocalBinding> */
    public array $bindings {
        get => array_values($this->recordedBindings);
    }

    public function record(LocalBinding $binding): void
    {
        $this->recordedBindings[$binding->id->value] = $binding;
    }

    public function find(NodeId|string $id): ?LocalBinding
    {
        $key = $id instanceof NodeId ? $id->value : $id;

        return $this->recordedBindings[$key] ?? null;
    }
}
