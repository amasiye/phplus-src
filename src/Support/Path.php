<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Support;

final class Path
{
    public static function isAbsolute(string $path): bool
    {
        $path = self::normalizeSeparators($path);

        if (str_starts_with($path, '/')) {
            return true;
        }

        return strlen($path) >= 3
            && ctype_alpha($path[0])
            && $path[1] === ':'
            && $path[2] === '/';
    }

    public static function join(string ...$segments): string
    {
        $path = '';

        foreach ($segments as $segment) {
            if ($segment === '') {
                continue;
            }

            if ($path === '' || self::isAbsolute($segment)) {
                $path = $segment;
                continue;
            }

            $path = rtrim($path, '/\\') . '/' . ltrim($segment, '/\\');
        }

        return self::normalize($path);
    }

    public static function resolveAbsolute(string $path, string $base): string
    {
        if (self::isAbsolute($path)) {
            return self::normalize($path);
        }

        if (!self::isAbsolute($base)) {
            throw new \InvalidArgumentException('The base path must be absolute.');
        }

        return self::join($base, $path);
    }

    public static function normalize(string $path): string
    {
        $path = self::normalizeSeparators($path);

        if ($path === '') {
            return '.';
        }

        $isUnc = str_starts_with($path, '//');
        $isUnixAbsolute = !$isUnc && str_starts_with($path, '/');
        $drive = null;
        $isDriveAbsolute = false;

        if (strlen($path) >= 2 && ctype_alpha($path[0]) && $path[1] === ':') {
            $drive = strtoupper($path[0]) . ':';
            $isDriveAbsolute = isset($path[2]) && $path[2] === '/';
            $path = substr($path, $isDriveAbsolute ? 3 : 2);
        } elseif ($isUnc) {
            $path = ltrim($path, '/');
        } elseif ($isUnixAbsolute) {
            $path = ltrim($path, '/');
        }

        $parts = [];

        foreach (explode('/', $path) as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }

            if ($part === '..') {
                if ($parts !== [] && end($parts) !== '..') {
                    array_pop($parts);
                } elseif (!$isUnixAbsolute && !$isDriveAbsolute && !$isUnc) {
                    $parts[] = '..';
                }

                continue;
            }

            $parts[] = $part;
        }

        $suffix = implode('/', $parts);

        if ($isDriveAbsolute && $drive !== null) {
            return $drive . '/' . $suffix;
        }

        if ($drive !== null) {
            return $drive . $suffix;
        }

        if ($isUnc) {
            return '//' . $suffix;
        }

        if ($isUnixAbsolute) {
            return '/' . $suffix;
        }

        return $suffix === '' ? '.' : $suffix;
    }

    public static function contains(string $parent, string $child): bool
    {
        $parent = self::trimTrailingSeparator(self::normalize($parent));
        $child = self::trimTrailingSeparator(self::normalize($child));
        $parentKey = self::buildComparisonKey($parent);
        $childKey = self::buildComparisonKey($child);

        if ($parentKey === $childKey) {
            return true;
        }

        if ($parentKey === '/' || self::isDriveRoot($parentKey)) {
            return str_starts_with($childKey, $parentKey);
        }

        return str_starts_with($childKey, $parentKey . '/');
    }

    public static function overlaps(string $first, string $second): bool
    {
        return self::contains($first, $second) || self::contains($second, $first);
    }

    public static function resolveRelativeTo(string $path, string $root): string
    {
        $path = self::normalize($path);
        $root = self::trimTrailingSeparator(self::normalize($root));

        if (!self::contains($root, $path)) {
            return $path;
        }

        if (self::buildComparisonKey($path) === self::buildComparisonKey($root)) {
            return '.';
        }

        return ltrim(substr($path, strlen($root)), '/');
    }

    public static function makeRelative(string $path, string $base): ?string
    {
        [$pathRoot, $pathParts] = self::splitAbsolutePath($path);
        [$baseRoot, $baseParts] = self::splitAbsolutePath($base);
        $caseInsensitive = str_ends_with($pathRoot, ':') || str_starts_with($pathRoot, '//');

        if (!self::pathPartsEqual($pathRoot, $baseRoot, $caseInsensitive)) {
            return null;
        }

        $shared = 0;
        $limit = min(count($pathParts), count($baseParts));

        while (
            $shared < $limit
            && self::pathPartsEqual($pathParts[$shared], $baseParts[$shared], $caseInsensitive)
        ) {
            $shared++;
        }

        $parts = [
            ...array_fill(0, count($baseParts) - $shared, '..'),
            ...array_slice($pathParts, $shared),
        ];

        return $parts === [] ? '.' : implode('/', $parts);
    }

    public static function isRoot(string $path): bool
    {
        $path = self::normalize($path);

        return $path === '/' || self::isDriveRoot($path);
    }

    public static function buildComparisonKey(string $path): string
    {
        $path = self::normalize($path);

        if (strlen($path) >= 2 && ctype_alpha($path[0]) && $path[1] === ':') {
            return strtolower($path);
        }

        return $path;
    }

    public static function hasSymlinkAncestor(string $path, string $root): bool
    {
        $path = self::normalize($path);
        $root = self::normalize($root);

        if (!self::contains($root, $path)) {
            return true;
        }

        $relative = self::resolveRelativeTo($path, $root);

        if ($relative === '.') {
            return false;
        }

        $current = $root;
        $segments = explode('/', $relative);
        array_pop($segments);

        foreach ($segments as $segment) {
            $current = self::join($current, $segment);

            if (is_link($current)) {
                return true;
            }
        }

        return false;
    }

    private static function normalizeSeparators(string $path): string
    {
        return str_replace('\\', '/', $path);
    }

    private static function trimTrailingSeparator(string $path): string
    {
        if ($path === '/' || self::isDriveRoot($path)) {
            return $path;
        }

        return rtrim($path, '/');
    }

    private static function isDriveRoot(string $path): bool
    {
        return strlen($path) === 3
            && ctype_alpha($path[0])
            && $path[1] === ':'
            && $path[2] === '/';
    }

    /** @return array{string, list<string>} */
    private static function splitAbsolutePath(string $path): array
    {
        $path = self::normalize($path);

        if (!self::isAbsolute($path)) {
            throw new \InvalidArgumentException('A relative path requires absolute path and base inputs.');
        }

        if (strlen($path) >= 3 && ctype_alpha($path[0]) && $path[1] === ':') {
            return [substr($path, 0, 2), self::splitPathParts(substr($path, 3))];
        }

        if (str_starts_with($path, '//')) {
            $parts = self::splitPathParts(substr($path, 2));

            if (count($parts) < 2) {
                return [$path, []];
            }

            return [
                '//' . $parts[0] . '/' . $parts[1],
                array_slice($parts, 2),
            ];
        }

        return ['/', self::splitPathParts(substr($path, 1))];
    }

    /** @return list<string> */
    private static function splitPathParts(string $path): array
    {
        return array_values(array_filter(
            explode('/', $path),
            static fn (string $part): bool => $part !== '',
        ));
    }

    private static function pathPartsEqual(string $left, string $right, bool $caseInsensitive): bool
    {
        return $caseInsensitive
            ? strcasecmp($left, $right) === 0
            : $left === $right;
    }
}
