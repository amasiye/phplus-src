<?php

declare(strict_types=1);

namespace Amasiye\Ppphp\Analysis\Interfaces;

use Amasiye\Ppphp\Analysis\AnalysisProject;
use Amasiye\Ppphp\Analysis\AnalysisResult;

interface ProjectAnalyzer
{
    public function analyze(AnalysisProject $project): AnalysisResult;
}
