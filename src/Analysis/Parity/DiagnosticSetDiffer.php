<?php

declare(strict_types=1);

namespace Amasiye\Ppphp\Analysis\Parity;

final readonly class DiagnosticSetDiffer
{
    /**
     * @param list<DiagnosticFingerprint> $left
     * @param list<DiagnosticFingerprint> $right
     * @return list<DiagnosticFingerprint>
     */
    public function subtract(array $left, array $right): array
    {
        $available = [];

        foreach ($right as $item) {
            $key = $item->key();
            $available[$key] = ($available[$key] ?? 0) + 1;
        }

        $difference = [];

        foreach ($left as $item) {
            $key = $item->key();

            if (($available[$key] ?? 0) > 0) {
                $available[$key]--;
            } else {
                $difference[] = $item;
            }
        }

        return $difference;
    }
}
