<?php

declare(strict_types=1);

use Amasiye\Phplus\Source\Enumerations\FileKind;
use Amasiye\Phplus\Source\SourceFile;
use Amasiye\Phplus\Source\SourceManager;

test('empty and single-line source files expose one-based positions', function (): void {
    $empty = new SourceFile('/project/empty.php', 'empty.php', FileKind::Php, '');
    $single = new SourceFile('/project/main.php', 'main.php', FileKind::Php, 'abc');

    expect($empty->lineCount())->toBe(1)
        ->and($empty->positionAt(0)->line)->toBe(1)
        ->and($empty->positionAt(0)->column)->toBe(1)
        ->and($single->positionAt(3)->column)->toBe(4)
        ->and($single->lineText(1))->toBe('abc');
});

test('LF CRLF and trailing newlines create logical lines', function (): void {
    $lf = new SourceFile('/project/lf.php', 'lf.php', FileKind::Php, "one\ntwo\n");
    $crlf = new SourceFile('/project/crlf.php', 'crlf.php', FileKind::Php, "one\r\ntwo");

    expect($lf->lineCount())->toBe(3)
        ->and($lf->positionAt(4)->line)->toBe(2)
        ->and($lf->positionAt(4)->column)->toBe(1)
        ->and($lf->lineText(3))->toBe('')
        ->and($crlf->lineCount())->toBe(2)
        ->and($crlf->positionAt(5)->line)->toBe(2)
        ->and($crlf->positionAt(5)->column)->toBe(1)
        ->and($crlf->lineText(1))->toBe('one');
});

test('columns count UTF-8 code points rather than bytes', function (): void {
    $source = new SourceFile('/project/utf8.phplus', 'utf8.phplus', FileKind::Phplus, "aé🙂z");

    expect($source->positionAt(strlen('aé🙂'))->offset)->toBe(7)
        ->and($source->positionAt(strlen('aé🙂'))->column)->toBe(4);
});

test('spans are half-open and may be empty or end at EOF', function (): void {
    $source = new SourceFile('/project/main.php', 'main.php', FileKind::Php, 'value');

    expect($source->span(1, 1)->isEmpty())->toBeTrue()
        ->and($source->span(1, 5)->text())->toBe('alue')
        ->and(fn () => $source->span(-1, 1))->toThrow(OutOfBoundsException::class)
        ->and(fn () => $source->span(3, 2))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $source->span(0, 6))->toThrow(OutOfBoundsException::class);
});

test('source managers reuse duplicate normalized paths', function (): void {
    $root = $this->temporaryDirectory();
    $this->writeFile($root . '/src/main.php', '<?php');
    $manager = new SourceManager($root);
    $first = $manager->load('src/main.php');
    $second = $manager->load('src/./domain/../main.php');

    expect($second)->toBe($first)
        ->and($manager->get($root . '/src/main.php'))->toBe($first)
        ->and($first->displayPath)->toBe('src/main.php')
        ->and(FileKind::fromPath('example.phplus'))->toBe(FileKind::Phplus)
        ->and(FileKind::fromPath('example.stub.php'))->toBe(FileKind::Stub);
});
