<?php

declare(strict_types=1);

namespace Amasiye\Ppphp\Semantic\Type;

use Amasiye\Ppphp\Source\SourceFile;
use Amasiye\Ppphp\Support\Path;
use PhpParser\Node\Expr;

final class ExpressionTypeTable
{
    /** @var array<string, ExpressionTypeResolution> */
    private array $entries = [];

    public function record(SourceFile $source, Expr $expression, ExpressionTypeResolution $resolution): void
    {
        $this->entries[$this->key($source, $expression)] = $resolution;
    }

    public function resolve(SourceFile $source, Expr $expression): ?ExpressionTypeResolution
    {
        return $this->entries[$this->key($source, $expression)] ?? null;
    }

    /** @var array<string, ExpressionTypeResolution> */
    public array $all {
        get => $this->entries;
    }

    private function key(SourceFile $source, Expr $expression): string
    {
        return Path::buildComparisonKey($source->path) . '#' . spl_object_id($expression);
    }
}
