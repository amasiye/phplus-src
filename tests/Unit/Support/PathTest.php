<?php

declare(strict_types=1);

use Atatusoft\Ppphp\Support\Path;

test('paths are normalized lexically without requiring filesystem entries', function (): void {
    expect(Path::normalize('/project/./src/../app'))->toBe('/project/app')
        ->and(Path::normalize('src/../app'))->toBe('app')
        ->and(Path::normalize('../../app'))->toBe('../../app')
        ->and(Path::normalize('C:\\project\\src\\..\\app'))->toBe('C:/project/app');
});

test('absolute paths and path joining support Unix and Windows roots', function (): void {
    expect(Path::isAbsolute('/project/src'))->toBeTrue()
        ->and(Path::isAbsolute('C:\\project\\src'))->toBeTrue()
        ->and(Path::isAbsolute('src/File.php'))->toBeFalse()
        ->and(Path::resolveAbsolute('src/File.php', '/project'))->toBe('/project/src/File.php')
        ->and(Path::join('/project', 'src', '..', 'stubs'))->toBe('/project/stubs');
});

test('containment overlap and relative display paths use path boundaries', function (): void {
    expect(Path::contains('/project', '/project/src/File.php'))->toBeTrue()
        ->and(Path::contains('/project', '/projectile'))->toBeFalse()
        ->and(Path::overlaps('/project/src', '/project/src/domain'))->toBeTrue()
        ->and(Path::overlaps('/project/src', '/project/stubs'))->toBeFalse()
        ->and(Path::resolveRelativeTo('/project/src/File.php', '/project'))->toBe('src/File.php')
        ->and(Path::contains('C:/Project', 'c:/project/src'))->toBeTrue()
        ->and(Path::isRoot('/'))->toBeTrue()
        ->and(Path::isRoot('C:/'))->toBeTrue();
});

test('relative paths traverse between arbitrary absolute locations', function (): void {
    expect(Path::makeRelative('/project/vendor/autoload.php', '/project/build/src'))
        ->toBe('../../vendor/autoload.php')
        ->and(Path::makeRelative('/project/build/src', '/project/build/src'))
        ->toBe('.')
        ->and(Path::makeRelative('C:/Project/Vendor/autoload.php', 'c:/project/build/src'))
        ->toBe('../../Vendor/autoload.php')
        ->and(Path::makeRelative('C:/project/vendor/autoload.php', 'D:/project/build'))
        ->toBeNull()
        ->and(Path::makeRelative('//server/share/vendor/autoload.php', '//SERVER/SHARE/build/src'))
        ->toBe('../../vendor/autoload.php')
        ->and(Path::makeRelative('//server/one/autoload.php', '//server/two/build'))
        ->toBeNull();
});
