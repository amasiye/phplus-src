<?php

declare(strict_types=1);

namespace Amasiye\Ppphp\Semantic;

final class CallableSignatureIndex
{
    /** @var array<string, list<int>|null> */
    private array $functions = [];

    /** @var array<string, list<int>|null> */
    private array $methods = [];

    /** @param list<int> $byReferencePositions */
    public function recordFunction(string $name, array $byReferencePositions): void
    {
        $this->record($this->functions, $name, $byReferencePositions);
    }

    /** @param list<int> $byReferencePositions */
    public function recordMethod(string $className, string $methodName, array $byReferencePositions): void
    {
        $this->record($this->methods, $className . '::' . $methodName, $byReferencePositions);
    }

    /** @return list<int>|null */
    public function resolveFunction(string $name): ?array
    {
        return $this->functions[$this->normalize($name)] ?? null;
    }

    /** @return list<int>|null */
    public function resolveMethod(string $className, string $methodName): ?array
    {
        return $this->methods[$this->normalize($className . '::' . $methodName)] ?? null;
    }

    /**
     * @param array<string, list<int>|null> $index
     * @param list<int> $byReferencePositions
     */
    private function record(array &$index, string $name, array $byReferencePositions): void
    {
        $key = $this->normalize($name);

        if (array_key_exists($key, $index)) {
            $index[$key] = null;

            return;
        }

        $index[$key] = $byReferencePositions;
    }

    private function normalize(string $name): string
    {
        return strtolower(ltrim($name, '\\'));
    }
}
