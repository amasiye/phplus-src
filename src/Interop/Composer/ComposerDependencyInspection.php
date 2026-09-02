<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Interop\Composer;

use PhpParser\Node\Stmt;

final readonly class ComposerDependencyInspection
{
    /**
     * @param list<string> $staticIncludes
     * @param list<Stmt> $conditionalDeclarations
     * @param array<string, string> $aliases
     */
    public function __construct(
        public array $staticIncludes,
        public array $conditionalDeclarations,
        public array $aliases,
        public bool $hasDynamicInclude,
        public bool $hasDynamicAlias,
    ) {}
}
