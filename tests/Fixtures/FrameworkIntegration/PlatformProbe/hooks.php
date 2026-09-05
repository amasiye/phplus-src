<?php

// PHP 8.4 property hooks: https://www.php.net/releases/8.4/en.php
final class PlatformHookProbe
{
    public string $label { get => 'hooks'; }
}
echo (new PlatformHookProbe())->label, "\n";
