<?php

declare(strict_types=1);

namespace Amasiye\Ppphp\Semantic\Type;

use Amasiye\Ppphp\Frontend\Ast\SourceType;
use Amasiye\Ppphp\Semantic\Type\Interfaces\Type;

final class LocalType
{
    private function __construct(
        public readonly string $text,
        public readonly Type $semanticType,
    ) {}

    public static function createUnknown(): self
    {
        return new self('unknown', new UnknownType());
    }

    public static function createAtomic(string $name): self
    {
        return new self($name, new AtomicType($name));
    }

    public static function createFromSourceType(SourceType $type): self
    {
        return self::createFromText($type->text);
    }

    public static function createFromText(string $text): self
    {
        $type = (new CompositeTypeParser())->parse($text);

        return $type->isUnknown ? self::createUnknown() : new self($text, $type);
    }

    public static function createFromSemanticType(Type $type): self
    {
        return $type->isUnknown ? self::createUnknown() : new self($type->canonical, $type);
    }

    public bool $unknown {
        get => $this->semanticType->isUnknown;
    }

    /** @var list<list<string>> */
    public array $variants {
        get => $this->resolveVariants($this->semanticType);
    }

    public bool $hasIntersection {
        get => $this->containsIntersection($this->semanticType);
    }

    public string $canonical {
        get => $this->semanticType->canonical;
    }

    public function includes(string $name): bool
    {
        $canonical = (new AtomicType($name))->canonical;

        foreach ($this->variants as $variant) {
            if ($variant === [$canonical]) {
                return true;
            }
        }

        return false;
    }

    public function equalsCanonical(self $other): bool
    {
        return $this->canonical === $other->canonical;
    }

    public function resolveSingleNamedType(): ?string
    {
        if (!$this->semanticType instanceof AtomicType || $this->semanticType->isBuiltin) {
            return null;
        }

        return $this->semanticType->name;
    }

    /** @return list<list<string>> */
    private function resolveVariants(Type $type): array
    {
        if ($type instanceof UnionType) {
            $variants = [];

            foreach ($type->members as $member) {
                array_push($variants, ...$this->resolveVariants($member));
            }

            return $variants;
        }

        if ($type instanceof IntersectionType) {
            $members = array_map(static fn (Type $member): string => $member->canonical, $type->members);
            sort($members, SORT_STRING);

            return [array_values(array_unique($members))];
        }

        if ($type instanceof GenericType || $type instanceof TypedArrayType || $type instanceof TypeParameter) {
            return [[$type->canonical]];
        }

        return [[$type->canonical]];
    }

    private function containsIntersection(Type $type): bool
    {
        if ($type instanceof IntersectionType) {
            return true;
        }

        if ($type instanceof UnionType) {
            foreach ($type->members as $member) {
                if ($this->containsIntersection($member)) {
                    return true;
                }
            }
        }

        return false;
    }
}
