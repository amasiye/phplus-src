<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Semantic\Scope;

final class ScopeStack
{
    /** @var list<Scope> */
    private array $scopes = [];

    public ?Scope $current {
        get => $this->scopes === [] ? null : $this->scopes[array_key_last($this->scopes)];
    }

    public function push(Scope $scope): void
    {
        $this->scopes[] = $scope;
    }

    public function pop(): Scope
    {
        $scope = array_pop($this->scopes);

        if (!$scope instanceof Scope) {
            throw new \LogicException('Cannot leave a semantic scope when none is active.');
        }

        return $scope;
    }
}
