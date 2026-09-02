<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Frontend\Interfaces;

use Atatusoft\Ppphp\Frontend\Enumerations\ParseMode;
use Atatusoft\Ppphp\Frontend\ParseResult;
use Atatusoft\Ppphp\Source\SourceFile;

interface Parser
{
    public function parse(
        SourceFile $sourceFile,
        ParseMode $mode = ParseMode::PlusPlusPhp,
    ): ParseResult;
}
