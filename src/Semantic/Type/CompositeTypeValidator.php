<?php

declare(strict_types=1);

namespace Amasiye\Ppphp\Semantic\Type;

use Amasiye\Ppphp\Semantic\Type\Interfaces\Type;

final class CompositeTypeValidator
{
    public function __construct(private readonly CompositeTypeParser $parser = new CompositeTypeParser()) {}

    /** @return list<string> */
    public function validateLocal(string $source): array
    {
        $source = trim($source);
        $type = $this->parser->parse($source);
        $issues = [];
        $topLevelUnion = $this->parser->splitAtTopLevel($source, '|');

        if (str_starts_with($source, '?') && count($topLevelUnion) > 1) {
            $issues[] = 'Nullable shorthand cannot be combined with another union member; write an explicit null union.';
        }

        if (count($topLevelUnion) > 1) {
            foreach ($topLevelUnion as $member) {
                if (count($this->parser->splitAtTopLevel($member, '&')) > 1 && !$this->parser->hasWrappingParentheses($member)) {
                    $issues[] = 'An intersection member of a union must be parenthesized as a DNF type.';
                    break;
                }
            }
        }

        $this->validateType($type, 'local', $issues);

        return array_values(array_unique($issues));
    }

    /** @param list<string> $issues */
    private function validateType(Type $type, string $position, array &$issues): void
    {
        if ($type instanceof AtomicType) {
            if ($position !== 'return' && in_array($type->canonical, ['void', 'never'], true)) {
                $issues[] = sprintf('%s cannot be used as a %s type.', $type->canonical, $position);
            }

            return;
        }

        if ($type instanceof UnionType) {
            $canonicals = array_map(static fn (Type $member): string => $member->canonical, $type->members);

            if (count($canonicals) !== count(array_unique($canonicals))) {
                $issues[] = 'A union cannot contain duplicate type members.';
            }

            if (in_array('mixed', $canonicals, true) && count($canonicals) > 1) {
                $issues[] = 'mixed must be the only member of a type.';
            }

            if (in_array('bool', $canonicals, true) && (in_array('true', $canonicals, true) || in_array('false', $canonicals, true))) {
                $issues[] = 'A union cannot combine bool with redundant true or false members.';
            }

            if (in_array('true', $canonicals, true) && in_array('false', $canonicals, true)) {
                $issues[] = 'Use bool instead of the redundant true|false union.';
            }

            foreach ($type->members as $member) {
                $this->validateType($member, $position, $issues);
            }

            return;
        }

        if ($type instanceof IntersectionType) {
            $canonicals = array_map(static fn (Type $member): string => $member->canonical, $type->members);

            if (count($canonicals) !== count(array_unique($canonicals))) {
                $issues[] = 'An intersection cannot contain duplicate type members.';
            }

            foreach ($type->members as $member) {
                if ($member instanceof UnionType) {
                    $issues[] = 'A union cannot be nested inside an intersection.';
                } elseif (!$member instanceof AtomicType || $member->isBuiltin) {
                    $issues[] = 'Intersection members must be class or interface types.';
                }

                $this->validateType($member, $position, $issues);
            }

            return;
        }

        if ($type instanceof GenericType) {
            foreach ($type->arguments as $argument) {
                $this->validateType($argument, $position, $issues);
            }

            return;
        }

        if ($type instanceof TypedArrayType) {
            $this->validateType($type->keyType, $position, $issues);
            $this->validateType($type->valueType, $position, $issues);
        }
    }
}
