<?php

declare(strict_types=1);

namespace Amasiye\Ppphp\Semantic\Effect;

use Amasiye\Ppphp\Semantic\Effect\Enumerations\ThrowableKind;
use Amasiye\Ppphp\Semantic\Symbol\SymbolTable;

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

        if ($canonical === 'throwable' || $this->matchesSubtype($canonical, 'exception')) {
            return ThrowableKind::Checked;
        }

        if ($this->matchesSubtype($canonical, 'error')) {
            return ThrowableKind::Unchecked;
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

        $visited = [];

        while (!isset($visited[$child])) {
            $visited[$child] = true;
            $next = self::BUILTIN_PARENTS[$child] ?? $this->symbols->findClass($child)?->parent;

            if ($next === null) {
                return false;
            }

            $child = $this->normalize($next);

            if ($child === $parent) {
                return true;
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
