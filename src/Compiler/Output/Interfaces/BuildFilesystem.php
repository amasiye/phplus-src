<?php

declare(strict_types=1);

namespace Amasiye\Ppphp\Compiler\Output\Interfaces;

interface BuildFilesystem
{
    public function checkExists(string $path): bool;

    public function checkIsFile(string $path): bool;

    public function checkIsDirectory(string $path): bool;

    public function checkIsLink(string $path): bool;

    public function createDirectory(string $path): void;

    public function readFile(string $path): string;

    public function readFileBounded(string $path, int $maximumBytes): string;

    public function writeFile(string $path, string $contents, ?int $mode = null): void;

    public function writeFileAtomically(string $path, string $contents, ?int $mode = null): void;

    public function move(string $from, string $to): void;

    public function remove(string $path): void;

    public function cloneTree(string $from, string $to): void;

    /** @return list<string> */
    public function listFiles(string $root): array;

    /** @return list<string> */
    public function listEntries(string $root): array;

    public function pruneEmptyDirectories(string $root): void;
}
