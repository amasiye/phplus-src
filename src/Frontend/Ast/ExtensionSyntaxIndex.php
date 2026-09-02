<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Frontend\Ast;

use Atatusoft\Ppphp\Frontend\Ast\Interfaces\Node;

final class ExtensionSyntaxIndex
{
    /**
     * @param list<TypedLocalDeclaration> $typedLocals
     * @param list<TypedForInitializer> $typedForInitializers
     * @param list<TypedForeachBinding> $typedForeachBindings
     * @param list<GenericDeclaration> $genericDeclarations
     * @param list<GenericType> $genericTypes
     * @param list<ThrowsClause> $throwsClauses
     * @param list<WhenExpression> $whenExpressions
     * @param list<Node> $nodes
     */
    public function __construct(
        public readonly array $typedLocals = [],
        public readonly array $typedForInitializers = [],
        public readonly array $typedForeachBindings = [],
        public readonly array $genericDeclarations = [],
        public readonly array $genericTypes = [],
        public readonly array $throwsClauses = [],
        public readonly array $whenExpressions = [],
        public readonly array $nodes = [],
    ) {}

    public static function createEmpty(): self
    {
        return new self();
    }

    public bool $isEmpty {
        get => $this->nodes === [];
    }
}
