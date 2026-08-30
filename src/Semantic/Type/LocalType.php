<?php

declare(strict_types=1);

namespace Amasiye\Ppphp\Semantic\Type;

use Amasiye\Ppphp\Frontend\Ast\SourceType;

final readonly class LocalType
{
    /**
     * Each variant is an intersection; the outer list is a union.
     *
     * @param list<list<string>> $variants
     */
    private function __construct(
        public string $text,
        public array $variants,
        public bool $unknown,
    ) {}

    public static function createUnknown(): self
    {
        return new self('unknown', [], true);
    }

    public static function createAtomic(string $name): self
    {
        $normalized = self::normalizeAtom($name);

        return new self($name, [[$normalized]], false);
    }

    public static function createFromSourceType(SourceType $type): self
    {
        return self::createFromText($type->text);
    }

    public static function createFromText(string $text): self
    {
        $source = trim($text);

        if ($source === '') {
            return self::createUnknown();
        }

        if (str_starts_with($source, '?')) {
            $source = 'null|' . substr($source, 1);
        }

        $variants = [];

        foreach (self::splitAtTopLevel($source, '|') as $variant) {
            $variant = self::stripWrappingParentheses(trim($variant));
            $atoms = [];

            foreach (self::splitAtTopLevel($variant, '&') as $atom) {
                $atom = self::stripWrappingParentheses(trim($atom));

                if ($atom !== '') {
                    $atoms[] = self::normalizeAtom($atom);
                }
            }

            if ($atoms !== []) {
                sort($atoms);
                $variants[] = array_values(array_unique($atoms));
            }
        }

        return $variants === []
            ? self::createUnknown()
            : new self($text, $variants, false);
    }

    public function includes(string $name): bool
    {
        $normalized = self::normalizeAtom($name);

        foreach ($this->variants as $variant) {
            if ($variant === [$normalized]) {
                return true;
            }
        }

        return false;
    }

    public function equalsCanonical(self $other): bool
    {
        if ($this->unknown || $other->unknown) {
            return $this->unknown && $other->unknown;
        }

        $left = $this->variants;
        $right = $other->variants;
        usort($left, self::compareVariants(...));
        usort($right, self::compareVariants(...));

        return $left === $right;
    }

    /**
     * @param list<string> $left
     * @param list<string> $right
     */
    private static function compareVariants(array $left, array $right): int
    {
        return implode('&', $left) <=> implode('&', $right);
    }

    public function resolveSingleNamedType(): ?string
    {
        if (count($this->variants) !== 1 || count($this->variants[0]) !== 1) {
            return null;
        }

        $name = $this->variants[0][0];

        return in_array($name, [
            'array', 'bool', 'callable', 'false', 'float', 'int', 'iterable',
            'mixed', 'never', 'null', 'object', 'resource', 'string', 'true', 'void',
        ], true) ? null : $name;
    }

    private static function normalizeAtom(string $atom): string
    {
        $atom = self::stripWrappingParentheses(trim($atom));
        $normalized = ltrim($atom, '\\');
        $builtin = strtolower($normalized);

        return in_array($builtin, [
            'array', 'bool', 'callable', 'false', 'float', 'int', 'iterable',
            'mixed', 'never', 'null', 'object', 'resource', 'string', 'true', 'void',
        ], true) ? $builtin : strtolower($normalized);
    }

    /** @return list<string> */
    private static function splitAtTopLevel(string $text, string $separator): array
    {
        $parts = [];
        $start = 0;
        $depth = 0;
        $length = strlen($text);

        for ($offset = 0; $offset < $length; $offset++) {
            $character = $text[$offset];

            if ($character === '(') {
                $depth++;
            } elseif ($character === ')') {
                $depth = max(0, $depth - 1);
            } elseif ($character === $separator && $depth === 0) {
                $parts[] = substr($text, $start, $offset - $start);
                $start = $offset + 1;
            }
        }

        $parts[] = substr($text, $start);

        return $parts;
    }

    private static function stripWrappingParentheses(string $text): string
    {
        while (strlen($text) >= 2 && $text[0] === '(' && $text[strlen($text) - 1] === ')') {
            $depth = 0;
            $wrapsEntireText = true;
            $length = strlen($text);

            for ($offset = 0; $offset < $length; $offset++) {
                if ($text[$offset] === '(') {
                    $depth++;
                } elseif ($text[$offset] === ')') {
                    $depth--;

                    if ($depth === 0 && $offset !== $length - 1) {
                        $wrapsEntireText = false;
                        break;
                    }
                }
            }

            if (!$wrapsEntireText || $depth !== 0) {
                break;
            }

            $text = trim(substr($text, 1, -1));
        }

        return $text;
    }
}
