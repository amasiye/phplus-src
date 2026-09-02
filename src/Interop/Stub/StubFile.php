<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Interop\Stub;

use Atatusoft\Ppphp\Source\Enumerations\FileKind;
use Atatusoft\Ppphp\Support\Path;

final readonly class StubFile
{
    public string $path;

    public string $stubRoot;

    public FileKind $kind;

    public function __construct(string $path, string $stubRoot)
    {
        $this->path = Path::normalize($path);
        $this->stubRoot = Path::normalize($stubRoot);
        $this->kind = FileKind::Stub;

        if (
            !Path::isAbsolute($this->path)
            || !Path::isAbsolute($this->stubRoot)
            || !Path::contains($this->stubRoot, $this->path)
        ) {
            throw new \InvalidArgumentException('A stub file must be contained by an absolute stub root.');
        }

        if (!str_ends_with(strtolower($this->path), '.stub.php')) {
            throw new \InvalidArgumentException('A stub file must use the .stub.php suffix.');
        }
    }

}
