<?php

declare(strict_types=1);

use Tests\Support\FrameworkIntegrationSpike\LayoutPlanner;
use Tests\Support\FrameworkIntegrationSpike\GenerationSelection;

function frameworkSpikeRoots(): array
{
    return array_map(static fn (string $path): array => [
        'path' => $path, 'mount' => $path, 'kind' => $path === 'artisan' ? 'file' : 'directory',
    ], ['app', 'bootstrap', 'config', 'public', 'resources', 'routes', 'tests', 'artisan', 'storage']);
}

test('layout spike preserves framework paths and classifies co-located resources once', function (): void {
    $root = dirname(__DIR__, 2) . '/Fixtures/FrameworkIntegration/LayoutProbe';
    $planner = new LayoutPlanner();
    $runtime = ['storage/framework/views', 'bootstrap/cache'];
    $plan = $planner->plan($root, frameworkSpikeRoots(), $runtime);
    $byOutput = array_column($plan, null, 'output');
    expect($byOutput)->toHaveCount(17)
        ->and($byOutput['bootstrap/app.php']['source'])->toBe('bootstrap/app.php')
        ->and($byOutput['config/app.php']['source'])->toBe('config/app.php')
        ->and($byOutput['app/Service.php']['operation'])->toBe('compile')
        ->and($byOutput['app/Books/Book.php']['operation'])->toBe('copy-php')
        ->and($byOutput['app/Books/database.config.php']['operation'])->toBe('copy-php')
        ->and($byOutput['app/Books/books.view.php']['operation'])->toBe('copy-resource')
        ->and($byOutput['resources/views/home.blade.php']['operation'])->toBe('copy-resource')
        ->and($byOutput['public/app.css']['operation'])->toBe('copy-resource')
        ->and($byOutput['app/main.entrypoint.ts']['operation'])->toBe('copy-resource')
        ->and($byOutput['artisan']['operation'])->toBe('copy-php')
        ->and($byOutput['storage/framework/views']['operation'])->toBe('create-directory')
        ->and($byOutput)->not->toHaveKey('bootstrap/cache/config.php')
        ->and($plan)->toBe($planner->plan($root, array_reverse(frameworkSpikeRoots()), array_reverse($runtime)))
        ->and(array_unique(array_column($plan, 'identity')))->toHaveCount(count($plan));
});

test('layout spike normalizes separators and supports legacy empty and nested mounts', function (): void {
    $root = $this->createTemporaryDirectory();
    $this->writeFile($root . '/app/User.ppphp', '<?php class User {}');
    $planner = new LayoutPlanner();
    foreach (['' => 'User.php', 'app' => 'app/User.php', 'nested\\app' => 'nested/app/User.php'] as $mount => $output) {
        $plan = $planner->plan($root, [['path' => 'app', 'mount' => $mount, 'kind' => 'directory']]);
        expect($plan[0]['output'])->toBe($output);
    }
});

test('layout spike rejects noncanonical paths before planning', function (string $mount): void {
    expect(fn () => (new LayoutPlanner())->normalizeRelative($mount))->toThrow(InvalidArgumentException::class);
})->with(['', '.', '..', './app', 'app/../other', '/app', 'C:/app', 'C:app', '//host/share', 'app//child', '.ppphp/maps']);

test('layout spike forbids broad PHP opaque rules and compiled source smuggling', function (): void {
    $root = $this->createTemporaryDirectory();
    $this->writeFile($root . '/app/template.view.ppphp', '<?php string $name = "probe";');
    $planner = new LayoutPlanner();
    $roots = [['path' => 'app', 'mount' => 'app', 'kind' => 'directory']];
    expect($planner->plan($root, $roots)[0]['operation'])->toBe('compile')
        ->and(fn () => $planner->plan($root, $roots, templates: ['*.php']))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $planner->plan($root, $roots, templates: ['.php']))->toThrow(InvalidArgumentException::class);
    $roots[0]['resource'] = true;
    expect(fn () => $planner->plan($root, $roots))->toThrow(InvalidArgumentException::class);
    $this->writeFile($root . '/resources/messages.php', '<?php return [];');
    expect(fn () => $planner->plan($root, [['path' => 'resources', 'mount' => 'resources', 'kind' => 'directory', 'resource' => true]]))
        ->toThrow(InvalidArgumentException::class);
});

test('layout spike retains relative view lookup when a feature moves as a unit', function (): void {
    $root = $this->createTemporaryDirectory();
    $this->writeFile($root . '/Books/BookController.ppphp', '<?php class BookController {}');
    $this->writeFile($root . '/Books/books.view.php', '<h1>Books</h1>');
    foreach (['app/Books', 'features/Catalog'] as $mount) {
        $plan = (new LayoutPlanner())->plan($root, [['path' => 'Books', 'mount' => $mount, 'kind' => 'directory']]);
        expect(array_column($plan, 'output'))->toContain($mount . '/BookController.php', $mount . '/books.view.php');
    }
});

test('layout spike excludes fixture secrets and runtime state from a mapped root', function (): void {
    $root = $this->createTemporaryDirectory();
    foreach (['.env', '.env.local', 'logs/app.log', 'sessions/token', 'cache/state.php'] as $file) {
        $this->writeFile($root . '/app/' . $file, 'fixture state');
    }
    $this->writeFile($root . '/app/keep.css', 'body {}');
    $plan = (new LayoutPlanner())->plan($root, [['path' => 'app', 'mount' => '', 'kind' => 'directory']]);
    expect(array_column($plan, 'output'))->toBe(['keep.css']);
});

test('layout spike rejects case folded collisions and ambiguous source resource ownership', function (): void {
    $root = $this->createTemporaryDirectory();
    $this->writeFile($root . '/one/Value.ppphp', '<?php class Value {}');
    $this->writeFile($root . '/two/value.php', '<?php class Other {}');
    $planner = new LayoutPlanner();
    $roots = [
        ['path' => 'one', 'mount' => '', 'kind' => 'directory'],
        ['path' => 'two', 'mount' => '', 'kind' => 'directory'],
    ];
    expect(fn () => $planner->plan($root, $roots))->toThrow(InvalidArgumentException::class);
    $roots[1] = ['path' => 'one/Value.ppphp', 'mount' => 'other.php', 'kind' => 'file', 'resource' => true];
    expect(fn () => $planner->plan($root, $roots))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $planner->plan($root, [$roots[0]], ['Value.php']))->toThrow(InvalidArgumentException::class);
});

test('layout spike rejects symlink roots and nested escapes', function (): void {
    $root = $this->createTemporaryDirectory();
    $outside = $this->createTemporaryDirectory();
    $this->writeFile($outside . '/secret.php', '<?php return "fixture";');
    $this->createDirectory($root . '/app');
    if (!@symlink($outside . '/secret.php', $root . '/app/link.php')) {
        $this->markTestSkipped('Host does not permit test symlinks.');
    }
    $planner = new LayoutPlanner();
    expect(fn () => $planner->plan($root, [['path' => 'app', 'mount' => '', 'kind' => 'directory']]))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => $planner->plan($root, [['path' => 'app/link.php', 'mount' => 'link.php', 'kind' => 'file']]))
        ->toThrow(InvalidArgumentException::class);
});

test('layout spike distinguishes file roots and preserves opaque bytes and content identity', function (): void {
    $root = $this->createTemporaryDirectory();
    $bytes = "<h1><?php deliberately not a PHP compilation unit \0</h1>";
    $this->writeFile($root . '/app/probe.view.php', $bytes);
    $this->writeFile($root . '/app/NOTICE', 'opaque notice');
    $planner = new LayoutPlanner();
    $roots = [['path' => 'app', 'mount' => 'app', 'kind' => 'directory']];
    $plan = $planner->plan($root, $roots);
    expect(array_column($plan, 'operation'))->toBe(['copy-resource', 'copy-resource'])
        ->and($plan[1]['hash'])->toBe(hash('sha256', $bytes))
        ->and(file_get_contents($root . '/app/probe.view.php'))->toBe($bytes)
        ->and(fn () => $planner->plan($root, [['path' => 'app', 'mount' => 'app', 'kind' => 'file']]))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $planner->plan($root, [['path' => 'app/NOTICE', 'mount' => 'app', 'kind' => 'directory']]))->toThrow(InvalidArgumentException::class);
    $this->writeFile($root . '/app/probe.view.php', $bytes . 'changed');
    expect($planner->plan($root, $roots)[1]['identity'])->not->toBe($plan[1]['identity']);
});

test('layout spike maps compiled file roots without truncating explicit PHP destinations', function (): void {
    $root = $this->createTemporaryDirectory();
    $this->writeFile($root . '/User.ppphp', '<?php class User {}');
    $planner = new LayoutPlanner();
    foreach (['app/User.ppphp', 'app/User.php'] as $mount) {
        expect($planner->plan($root, [['path' => 'User.ppphp', 'mount' => $mount, 'kind' => 'file']])[0]['output'])
            ->toBe('app/User.php');
    }
    expect(fn () => $planner->plan($root, [['path' => 'User.ppphp', 'mount' => 'User', 'kind' => 'file']]))
        ->toThrow(InvalidArgumentException::class, 'Compiled file mounts');
});

test('lifecycle spike retires stale resources only after successful preparation and leaves external state alone', function (): void {
    $generation = new GenerationSelection();
    $first = ['app/Old.php' => 'class-1', 'app/old.view.php' => 'view-1'];
    $next = ['app/New.php' => 'class-2', 'app/new.view.php' => 'view-2'];
    $state = $this->createTemporaryDirectory();
    $this->writeFile($state . '/session', 'live synthetic session');
    $generation->publish($first, static fn () => null);
    expect($generation->findStale($next))->toBe(['app/Old.php', 'app/old.view.php']);
    expect(fn () => $generation->publish($next, static fn () => throw new RuntimeException('discovery failed')))
        ->toThrow(RuntimeException::class, 'discovery failed');
    expect($generation->active)->toBe($first);
    $generation->publish($next, static fn () => null);
    expect($generation->active)->toBe($next);
    $generation->publish([], static fn () => null);
    expect($generation->active)->toBe([])->and(file_get_contents($state . '/session'))->toBe('live synthetic session');
});
