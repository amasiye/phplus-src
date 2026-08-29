<?php

declare(strict_types=1);

namespace Amasiye\Ppphp\Frontend\Ast;

use Amasiye\Ppphp\Frontend\Ast\Interfaces\Node;

final class ExtensionSyntaxIndex
{
    /**
     * @param list<TypedLocalDeclaration> $typedLocals
     * @param list<GenericDeclaration> $genericDeclarations
     * @param list<GenericType> $genericTypes
     * @param list<ThrowsClause> $throwsClauses
     * @param list<WhenExpression> $whenExpressions
     * @param list<Node> $nodes
     */
    public function __construct(
        public readonly array $typedLocals = [],
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
