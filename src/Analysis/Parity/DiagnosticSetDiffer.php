<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Analysis\Parity;

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
            $available[$item->key] = ($available[$item->key] ?? 0) + 1;
        }

        $difference = [];

        foreach ($left as $item) {
            if (($available[$item->key] ?? 0) > 0) {
                $available[$item->key]--;
                continue;
            }

            $difference[] = $item;
        }

        return $difference;
    }
}
