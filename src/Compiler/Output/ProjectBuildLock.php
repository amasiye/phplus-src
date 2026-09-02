<?php

declare(strict_types=1);

namespace Amasiye\Ppphp\Compiler\Output;

use Amasiye\Ppphp\Config\ProjectConfig;
use Amasiye\Ppphp\Support\Path;

final class ProjectBuildLock
{
    /** @var array<string, array{exclusive: bool, shared: int}> */
    private static array $heldLocks = [];

    /** @var resource|null */
    private mixed $handle = null;

    private ?string $lockKey = null;

    private bool $exclusive = true;

    public bool $isAcquired {
        get => is_resource($this->handle);
    }

    public function acquire(ProjectConfig $configuration, bool $exclusive = true): bool
    {
        if ($this->isAcquired) {
            throw new \LogicException('A build lock instance cannot acquire more than one lock.');
        }

        $lockPath = Path::join($configuration->projectRoot, '.ppphp-operation.lock');
        $key = Path::buildComparisonKey($lockPath);
        $held = self::$heldLocks[$key] ?? ['exclusive' => false, 'shared' => 0];

        if (($exclusive && ($held['exclusive'] || $held['shared'] > 0)) || (!$exclusive && $held['exclusive'])) {
            return false;
        }

        if (
            !Path::contains($configuration->projectRoot, $lockPath)
            || is_link($lockPath)
            || Path::hasSymlinkAncestor($lockPath, $configuration->projectRoot)
            || (file_exists($lockPath) && (!is_file($lockPath) || !is_writable($lockPath)))
            || (!file_exists($lockPath) && !is_writable($configuration->projectRoot))
        ) {
            throw new \RuntimeException('The project operation lock path is unsafe.');
        }

        $handle = @fopen($lockPath, 'c+b');

        if ($handle === false) {
            throw new \RuntimeException('The project operation lock could not be opened.');
        }

        @chmod($lockPath, 0600);

        if (!flock($handle, ($exclusive ? LOCK_EX : LOCK_SH) | LOCK_NB)) {
            fclose($handle);

            return false;
        }

        self::$heldLocks[$key] = $exclusive
            ? ['exclusive' => true, 'shared' => 0]
            : ['exclusive' => false, 'shared' => $held['shared'] + 1];
        $this->handle = $handle;
        $this->lockKey = $key;
        $this->exclusive = $exclusive;

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
            $held = self::$heldLocks[$this->lockKey] ?? ['exclusive' => false, 'shared' => 0];

            if ($this->exclusive) {
                $held['exclusive'] = false;
            } else {
                $held['shared'] = max(0, $held['shared'] - 1);
            }

            if (!$held['exclusive'] && $held['shared'] === 0) {
                unset(self::$heldLocks[$this->lockKey]);
            } else {
                self::$heldLocks[$this->lockKey] = $held;
            }
        }

        $this->handle = null;
        $this->lockKey = null;
    }

    public function __destruct()
    {
        $this->release();
    }
}
