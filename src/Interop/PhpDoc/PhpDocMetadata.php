<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Interop\PhpDoc;

final readonly class PhpDocMetadata
{
    /**
     * @param list<array{name: string, bound: ?string}> $templates
     * @param array<string, string> $parameters
     * @param list<string> $returns
     * @param array<string, string> $variables
     * @param list<string> $extends
     * @param list<string> $implements
     * @param list<string> $uses
     * @param list<string> $throws
     */
    public function __construct(
        public array $templates = [],
        public array $parameters = [],
        public array $returns = [],
        public array $variables = [],
        public array $extends = [],
        public array $implements = [],
        public array $uses = [],
        public array $throws = [],
    ) {}
}
