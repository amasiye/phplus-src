<?php

declare(strict_types=1);

namespace Amasiye\Ppphp\Analysis\Enumerations;

enum AnalysisCompleteness: string
{
    case CompilerCore = 'compilerCore';
    case Full = 'full';
}
