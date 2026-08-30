<?php

declare(strict_types=1);

namespace Amasiye\Ppphp\Semantic\Type;

use Amasiye\Ppphp\Semantic\Type\Interfaces\Type;

final class UnionType implements Type
{
    /** @param non-empty-list<Type> $members */
    public function __construct(public readonly array $members) {}

    public string $canonical {
        get {
            $members = array_values(array_unique(array_map(
                static fn (Type $member): string => $member->canonical,
                $this->members,
            )));
            sort($members, SORT_STRING);

            return implode('|', $members);
        }
    }

    public bool $isNullable {
        get {
            foreach ($this->members as $member) {
                if ($member->isNullable) {
                    return true;
                }
            }

            return false;
        }
    }

    public bool $isUnknown {
        get {
            foreach ($this->members as $member) {
                if ($member->isUnknown) {
                    return true;
                }
            }

            return false;
        }
    }

    public function renderPhpDoc(): string
    {
        $members = [];

        foreach ($this->members as $member) {
            $rendered = $member->renderPhpDoc();
            $members[$member->canonical] = $member instanceof IntersectionType ? '(' . $rendered . ')' : $rendered;
        }

        ksort($members, SORT_STRING);

        return implode('|', $members);
    }

    public function eraseToNative(): string
    {
        $members = [];

        foreach ($this->members as $member) {
            $rendered = $member->eraseToNative();
            $members[$member->canonical] = $member instanceof IntersectionType ? '(' . $rendered . ')' : $rendered;
        }

        ksort($members, SORT_STRING);

        return implode('|', $members);
    }
}
