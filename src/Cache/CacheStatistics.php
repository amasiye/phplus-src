<?php

declare(strict_types=1);

namespace Amasiye\Ppphp\Cache;

final class CacheStatistics
{
    public int $readAttempts = 0;
    public int $hits = 0;
    public int $misses = 0;
    public int $corruptEntries = 0;
    public int $invalidatedEntries = 0;
    public int $blobsRead = 0;
    public int $blobsWritten = 0;
    public int $bytesRead = 0;
    public int $bytesWritten = 0;
    public int $parserWorkAvoided = 0;
    public int $semanticWorkAvoided = 0;
    public int $loweringWorkAvoided = 0;
    public int $supplementalProcessesAvoided = 0;

    /** @return array<string, int> */
    public function toArray(): array
    {
        return [
            'blobsRead' => $this->blobsRead,
            'blobsWritten' => $this->blobsWritten,
            'bytesRead' => $this->bytesRead,
            'bytesWritten' => $this->bytesWritten,
            'corruptEntries' => $this->corruptEntries,
            'hits' => $this->hits,
            'invalidatedEntries' => $this->invalidatedEntries,
            'loweringWorkAvoided' => $this->loweringWorkAvoided,
            'misses' => $this->misses,
            'parserWorkAvoided' => $this->parserWorkAvoided,
            'readAttempts' => $this->readAttempts,
            'semanticWorkAvoided' => $this->semanticWorkAvoided,
            'supplementalProcessesAvoided' => $this->supplementalProcessesAvoided,
        ];
    }
}
