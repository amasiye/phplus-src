<?php

namespace Acme;

/** @template T */
final class Service
{
    /**
     * @param T $value
     * @return T
     * @throws \RuntimeException
     */
    public function apply(mixed $value): mixed
    {
        throw new \RuntimeException('dependency code must not execute');
    }
}
