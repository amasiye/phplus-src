<?php

declare(strict_types=1);

namespace Amasiye\Ppphp\Compiler\Output;

use Amasiye\Ppphp\Config\ProjectConfig;
use Amasiye\Ppphp\Support\Path;

final class ProjectBuildLock
{
    /** @var array<string, true> */
    private static array $heldLocks = [];

    /** @var resource|null */
    private mixed $handle = null;

    private ?string $lockKey = null;

    public bool $isAcquired {
        get => is_resource($this->handle);
    }

    public function acquire(ProjectConfig $configuration): bool
    {
        if ($this->isAcquired) {
            throw new \LogicException('A build lock instance cannot acquire more than one lock.');
        }

        $lockPath = Path::join($configuration->cachePath, 'build.lock');
        $key = Path::buildComparisonKey($lockPath);

        if (isset(self::$heldLocks[$key])) {
            return false;
        }

        if (
            !Path::contains($configuration->projectRoot, $lockPath)
            || is_link($configuration->cachePath)
            || Path::hasSymlinkAncestor($lockPath, $configuration->projectRoot)
        ) {
            throw new \RuntimeException('The project build lock path is unsafe.');
        }

        if (!is_dir($configuration->cachePath) && !@mkdir($configuration->cachePath, 0777, true) && !is_dir($configuration->cachePath)) {
            throw new \RuntimeException('The project build lock directory could not be created.');
        }

        $handle = @fopen($lockPath, 'c+b');

        if ($handle === false) {
            throw new \RuntimeException('The project build lock could not be opened.');
        }

        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);

            return false;
        }

        self::$heldLocks[$key] = true;
        $this->handle = $handle;
        $this->lockKey = $key;

        return true;
    }

    public function release(): void
    {
        if (!is_resource($this->handle)) {
            return;
        }

        flock($this->handle, LOCK_UN);
        fclose($this->handle);

        if ($this->lockKey !== null) {
            unset(self::$heldLocks[$this->lockKey]);
        }

        $this->handle = null;
        $this->lockKey = null;
    }

    public function __destruct()
    {
        $this->release();
    }
}
