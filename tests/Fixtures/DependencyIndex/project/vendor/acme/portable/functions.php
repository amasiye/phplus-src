<?php

declare(strict_types=1);

require_once __DIR__ . '/included.php';

function acme_portable(string $value): string
{
    throw new LogicException('dependency code must not execute');
}

if (!function_exists('acme_fallback')) {
    function acme_fallback(int $value): int
    {
        return $value;
    }
}

class_alias(Acme\Service::class, 'Acme\\AliasService');
