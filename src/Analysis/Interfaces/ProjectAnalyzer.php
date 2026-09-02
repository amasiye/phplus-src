<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Analysis\Interfaces;

use Atatusoft\Ppphp\Analysis\AnalysisProject;
use Atatusoft\Ppphp\Analysis\AnalysisResult;

interface ProjectAnalyzer
{
    public function analyze(AnalysisProject $project): AnalysisResult;
}
