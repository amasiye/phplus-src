<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Semantic\Effect;

use Atatusoft\Ppphp\Semantic\Effect\Enumerations\ThrowableKind;
use Atatusoft\Ppphp\Semantic\Symbol\SymbolTable;

final readonly class ThrowableHierarchy
{
    /** @var array<string, string> */
    private const BUILTIN_PARENTS = [
        'exception' => 'throwable',
        'errorexception' => 'exception',
        'closedgeneratorexception' => 'exception',
        'jsonexception' => 'exception',
        'random\\randomexception' => 'exception',
        'reflectionexception' => 'exception',
        'pharexception' => 'exception',
        'runtimeexception' => 'exception',
        'outofboundsexception' => 'runtimeexception',
        'overflowexception' => 'runtimeexception',
        'rangeexception' => 'runtimeexception',
        'underflowexception' => 'runtimeexception',
        'unexpectedvalueexception' => 'runtimeexception',
        'logicexception' => 'exception',
        'badfunctioncallexception' => 'logicexception',
        'badmethodcallexception' => 'badfunctioncallexception',
        'domainexception' => 'logicexception',
        'invalidargumentexception' => 'logicexception',
        'lengthexception' => 'logicexception',
        'outofrangeexception' => 'logicexception',
        'error' => 'throwable',
        'arithmeticerror' => 'error',
        'divisionbyzeroerror' => 'arithmeticerror',
        'assertionerror' => 'error',
        'compileerror' => 'error',
        'parseerror' => 'compileerror',
        'typeerror' => 'error',
        'argumentcounterror' => 'typeerror',
        'valueerror' => 'error',
        'unhandledmatcherror' => 'error',
    ];

    public function __construct(private SymbolTable $symbols) {}

    public function classify(string $type): ThrowableKind
    {
        $canonical = $this->normalize($type);

        if ($this->matchesSubtype($canonical, 'error')) {
            return ThrowableKind::Unchecked;
        }

        if ($this->matchesSubtype($canonical, 'throwable')) {
            return ThrowableKind::Checked;
        }

        if ($this->containsKnownType($canonical)) {
            return ThrowableKind::NotThrowable;
        }

        return ThrowableKind::Unknown;
    }

    public function matchesSubtype(string $child, string $parent): bool
    {
        $child = $this->normalize($child);
        $parent = $this->normalize($parent);

        if ($child === $parent) {
            return true;
        }

        $pending = [$child];
        $visited = [];

        while ($pending !== []) {
            $current = array_pop($pending);

            if ($current === $parent) {
                return true;
            }

            if (isset($visited[$current])) {
                continue;
            }

            $visited[$current] = true;
            $builtinParent = self::BUILTIN_PARENTS[$current] ?? null;

            if ($builtinParent !== null) {
                $pending[] = $this->normalize($builtinParent);
            }

            $symbol = $this->symbols->findClass($current);

            if ($symbol === null) {
                continue;
            }

            if ($symbol->parent !== null) {
                $pending[] = $this->normalize($symbol->parent);
            }

            foreach ($symbol->interfaces as $interface) {
                $pending[] = $this->normalize($interface);
            }
        }

        return false;
    }

    private function containsKnownType(string $type): bool
    {
        return in_array($type, [
            'array', 'bool', 'callable', 'false', 'float', 'int', 'iterable',
            'mixed', 'never', 'null', 'object', 'resource', 'string', 'true', 'void',
            'throwable', 'exception', 'error',
        ], true)
            || isset(self::BUILTIN_PARENTS[$type])
            || $this->symbols->findClass($type) !== null;
    }

    private function normalize(string $type): string
    {
        return strtolower(ltrim($type, '\\'));
    }
}
