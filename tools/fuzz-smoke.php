<?php

declare(strict_types=1);

use Atatusoft\Ppphp\Diagnostics\JsonRenderer;
use Atatusoft\Ppphp\Frontend\Enumerations\ParseMode;
use Atatusoft\Ppphp\Frontend\PpphpParser;
use Atatusoft\Ppphp\Source\Enumerations\FileKind;
use Atatusoft\Ppphp\Source\SourceFile;

require dirname(__DIR__) . '/vendor/autoload.php';

const FUZZ_SEED = 13_004;
const FUZZ_CASES = 64;
const FUZZ_MAXIMUM_SOURCE_BYTES = 16_384;
const FUZZ_MAXIMUM_SECONDS = 15.0;

$seeds = [
    "<?php\nfunction value(int \$input): int { return \$input + 1; }\n",
    "<?php\nfunction first<T>(T \$value): T { return \$value; }\n",
    "<?php\nfunction values(array<string, int> \$items): array<string, int> { return \$items; }\n",
    "<?php\nfunction risky(): void throws RuntimeException { throw new RuntimeException(); }\n",
    "<?php\nfunction choose(bool \$value): int { return when (\$value) { return 1; } else { return 2; }; }\n",
];
$parser = new PpphpParser();
$renderer = new JsonRenderer();
$started = microtime(true);
mt_srand(FUZZ_SEED);

for ($case = 0; $case < FUZZ_CASES; $case++) {
    $source = $seeds[$case % count($seeds)];
    $mutation = $case % 8;
    $offset = $source === '' ? 0 : mt_rand(0, strlen($source) - 1);

    $source = match ($mutation) {
        0 => substr($source, 0, $offset) . substr($source, $offset + 1),
        1 => substr($source, 0, $offset) . chr(mt_rand(0, 255)) . substr($source, $offset),
        2 => substr($source, 0, $offset) . substr($source, $offset, min(12, strlen($source) - $offset)) . substr($source, $offset),
        3 => substr($source, 0, $offset) . ['(', ')', '{', '}', '[', ']', '<', '>'][mt_rand(0, 7)] . substr($source, $offset + 1),
        4 => substr($source, 0, $offset),
        5 => str_repeat('(', 32) . $source . str_repeat(')', 32),
        6 => str_replace("\n", "\r\n", $source),
        7 => substr($source, 0, $offset) . "\xff" . substr($source, $offset),
    };
    $source = substr($source, 0, FUZZ_MAXIMUM_SOURCE_BYTES);

    try {
        $file = new SourceFile(
            '/virtual/fuzz-' . $case . '.ppphp',
            'fuzz-' . $case . '.ppphp',
            FileKind::Ppphp,
            $source,
        );
        $result = $parser->parse($file, ParseMode::PlusPlusPhp);
        $json = $renderer->render($result->diagnostics);
        json_decode($json, true, flags: JSON_THROW_ON_ERROR);
    } catch (Throwable $exception) {
        fwrite(STDERR, sprintf(
            "Fuzz smoke failed (seed=%d case=%d mutation=%d): %s: %s\n",
            FUZZ_SEED,
            $case,
            $mutation,
            $exception::class,
            $exception->getMessage(),
        ));

        exit(1);
    }

    if (microtime(true) - $started > FUZZ_MAXIMUM_SECONDS) {
        fwrite(STDERR, sprintf("Fuzz smoke exceeded its time limit (seed=%d case=%d).\n", FUZZ_SEED, $case));

        exit(1);
    }
}

fwrite(STDOUT, sprintf("Fuzz smoke passed: seed=%d cases=%d\n", FUZZ_SEED, FUZZ_CASES));
