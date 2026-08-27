<?php

declare(strict_types=1);

namespace Amasiye\Phplus\Source;

use Amasiye\Phplus\Source\Enumerations\FileKind;
use Amasiye\Phplus\Support\Path;

final class SourceManager
{
    /** @var array<string, SourceFile> */
    private array $sources = [];

    public function __construct(private readonly ?string $projectRoot = null)
    {
        if ($projectRoot !== null && !Path::isAbsolute($projectRoot)) {
            throw new \InvalidArgumentException('The source manager project root must be absolute.');
        }
    }

    public function load(string $path, ?FileKind $kind = null): SourceFile
    {
        $absolutePath = $this->absolutePath($path);
        $key = Path::comparisonKey($absolutePath);

        if (isset($this->sources[$key])) {
            return $this->sources[$key];
        }

        if (!is_file($absolutePath) || !is_readable($absolutePath)) {
            throw new \RuntimeException(sprintf('Unable to read source file "%s".', $path));
        }

        $contents = file_get_contents($absolutePath);

        if ($contents === false) {
            throw new \RuntimeException(sprintf('Unable to read source file "%s".', $path));
        }

        return $this->register(new SourceFile(
            $absolutePath,
            $this->displayPath($absolutePath),
            $kind ?? FileKind::fromPath($absolutePath),
            $contents,
        ));
    }

    public function register(SourceFile $sourceFile): SourceFile
    {
        $key = Path::comparisonKey($sourceFile->path);

        return $this->sources[$key] ??= $sourceFile;
    }

    public function get(string $path): ?SourceFile
    {
        return $this->sources[Path::comparisonKey($this->absolutePath($path))] ?? null;
    }

    public function position(string $path, int $offset): Position
    {
        return $this->required($path)->positionAt($offset);
    }

    public function span(string $path, int $startOffset, int $endOffset): Span
    {
        return $this->required($path)->span($startOffset, $endOffset);
    }

    private function required(string $path): SourceFile
    {
        $source = $this->get($path);

        if ($source === null) {
            throw new \OutOfBoundsException(sprintf('Source file "%s" is not registered.', $path));
        }

        return $source;
    }

    private function absolutePath(string $path): string
    {
        if (Path::isAbsolute($path)) {
            return Path::normalize($path);
        }

        $base = $this->projectRoot ?? getcwd();

        if ($base === false) {
            throw new \RuntimeException('Unable to determine the current working directory.');
        }

        return Path::absolute($path, Path::normalize($base));
    }

    private function displayPath(string $path): string
    {
        if ($this->projectRoot === null) {
            return $path;
        }

        return Path::relativeTo($path, Path::normalize($this->projectRoot));
    }
}
