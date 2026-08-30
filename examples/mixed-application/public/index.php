<?php

declare(strict_types=1);

use Example\Mixed\Application;

require_once dirname(__DIR__) . '/vendor/autoload.php';

echo (new Application())->run(), "\n";

