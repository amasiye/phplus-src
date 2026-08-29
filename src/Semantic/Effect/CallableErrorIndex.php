<?php

declare(strict_types=1);

namespace Amasiye\Ppphp\Semantic\Effect;

use Amasiye\Ppphp\Frontend\Ast\ThrowsClause;
use Amasiye\Ppphp\Source\SourceFile;
use Amasiye\Ppphp\Support\Path;

final class CallableErrorIndex
{
    /** @var array<string, CallableErrorContract> */
    private array $contractsByOwner = [];

    public function record(
        SourceFile $sourceFile,
        ThrowsClause $clause,
        CallableErrorContract $contract,
    ): void {
        $this->contractsByOwner[$this->buildKey($sourceFile, $clause)] = $contract;
    }

    public function find(SourceFile $sourceFile, ThrowsClause $clause): ?CallableErrorContract
    {
        return $this->contractsByOwner[$this->buildKey($sourceFile, $clause)] ?? null;
    }

    private function buildKey(SourceFile $sourceFile, ThrowsClause $clause): string
    {
        return implode('#', [
            Path::buildComparisonKey($sourceFile->path),
            $clause->id->value,
            (string) $clause->ownerNameSpan->start->offset,
        ]);
    }
}
