<?php

declare(strict_types=1);

namespace Amasiye\Ppphp\Semantic\Type;

use Amasiye\Ppphp\Semantic\Type\Interfaces\Type;

final class AtomicType implements Type
{
    private const BUILTINS = [
        'array', 'bool', 'callable', 'false', 'float', 'int', 'iterable',
        'mixed', 'never', 'null', 'object', 'resource', 'string', 'true', 'void',
    ];

    public readonly string $name;

    public function __construct(string $name)
    {
        $this->name = ltrim(trim($name), '\\');
    }

    public string $canonical {
        get {
            $lower = strtolower($this->name);

            return in_array($lower, self::BUILTINS, true) ? $lower : $lower;
        }
    }

    public bool $isNullable {
        get => in_array($this->canonical, ['mixed', 'null'], true);
    }

    public bool $isUnknown {
        get => false;
    }

    public bool $isBuiltin {
        get => in_array($this->canonical, self::BUILTINS, true);
    }

    public function renderPhpDoc(): string
    {
        return $this->isBuiltin ? $this->canonical : $this->name;
    }

    public function eraseToNative(): string
    {
        return $this->renderPhpDoc();
    }
}
