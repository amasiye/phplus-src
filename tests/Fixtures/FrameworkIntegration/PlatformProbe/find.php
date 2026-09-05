<?php

// PHP 8.4 array_find: https://www.php.net/manual/en/function.array-find.php
echo array_find([2, 7, 9], static fn (int $value): bool => $value > 5), "\n";
