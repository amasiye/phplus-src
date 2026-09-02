<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Semantic\Effect;

use Atatusoft\Ppphp\Frontend\Ast\ThrowsClause;
use Atatusoft\Ppphp\Source\SourceFile;
use Atatusoft\Ppphp\Support\Path;

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
