<?php

declare(strict_types=1);

namespace Amasiye\Phplus\Cli;

use Symfony\Component\Console\Application as SymfonyApplication;

final class Application extends SymfonyApplication
{
    public const NAME = 'PHPlus';

    public const VERSION = 'development';

    public function __construct()
    {
        parent::__construct(self::NAME, self::VERSION);
    }
}
