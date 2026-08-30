<?php

declare(strict_types=1);

use Amasiye\Ppphp\Cli\Enumerations\ExitCode;
use Amasiye\Ppphp\Editor\EditorSemanticTokensRequest;
use Symfony\Component\Process\Process;

/** @return array<string, mixed> */
function resolveEditorSemanticTokens(string $root, string $path, string $contents): array
{
    $process = new Process([
        PHP_BINARY,
        dirname(__DIR__, 3) . '/bin/ppphp',
        'editor:semantic-tokens',
        '--working-directory',
        $root,
        '--format=json',
        '--no-ansi',
    ]);
    $process->setInput(json_encode([
        'version' => EditorSemanticTokensRequest::VERSION,
        'document' => [
            'path' => $path,
            'contents' => $contents,
        ],
    ], JSON_THROW_ON_ERROR));
    $process->run();

    expect($process->getExitCode())->toBe(
        ExitCode::Success->value,
        $process->getErrorOutput() . $process->getOutput(),
    );

    $response = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);

    if (!is_array($response)) {
        throw new RuntimeException('The editor semantic tokens response must be an object.');
    }

    return $response;
}

test('semantic tokens classify PHP symbols and ++PHP extensions from unsaved source', function (): void {
    $root = $this->createTemporaryDirectory();
    $this->writeConfiguration($root);
    $source = <<<'PPPHP'
<?php

namespace My\App\Core;

readonly class Person
{
    public function __construct(public string $firstName, public ?int $age = null)
    {
    }
}

class Box<T>
{
    public function __construct(public T $value)
    {
    }

    public function getValue(): T
    {
        return $this->value;
    }
}

function unwrap(Box<Person> $box): Person throws RuntimeException
{
    readonly Person $person = $box->getValue();

    return $person;
}
PPPHP;
    $this->writeFile($root . '/src/Model.ppphp', "<?php\nclass Placeholder {}\n");

    $response = resolveEditorSemanticTokens($root, 'src/Model.ppphp', $source);
    $tokens = $response['tokens'] ?? null;

    expect($response['version'] ?? null)->toBe(EditorSemanticTokensRequest::VERSION)
        ->and($response['error'] ?? null)->toBeNull()
        ->and($tokens)->toBeArray();

    $classified = [];

    foreach ($tokens as $token) {
        if (!is_array($token)) {
            continue;
        }

        $start = $token['range']['start']['offset'] ?? null;
        $end = $token['range']['end']['offset'] ?? null;

        if (!is_int($start) || !is_int($end)) {
            continue;
        }

        $classified[] = [
            'text' => substr($source, $start, $end - $start),
            'type' => $token['type'] ?? null,
            'modifiers' => $token['modifiers'] ?? null,
        ];
    }

    expect($classified)
        ->toContain(['text' => 'Box', 'type' => 'class', 'modifiers' => ['declaration']])
        ->toContain(['text' => 'T', 'type' => 'typeParameter', 'modifiers' => ['declaration']])
        ->toContain(['text' => 'getValue', 'type' => 'method', 'modifiers' => ['declaration']])
        ->toContain(['text' => 'getValue', 'type' => 'method', 'modifiers' => []])
        ->toContain(['text' => 'unwrap', 'type' => 'function', 'modifiers' => ['declaration']])
        ->toContain(['text' => '$firstName', 'type' => 'property', 'modifiers' => ['declaration']])
        ->toContain(['text' => '$value', 'type' => 'property', 'modifiers' => ['declaration']])
        ->toContain(['text' => 'value', 'type' => 'property', 'modifiers' => []])
        ->toContain(['text' => 'throws', 'type' => 'keyword', 'modifiers' => []])
        ->toContain(['text' => 'readonly', 'type' => 'keyword', 'modifiers' => []])
        ->toContain(['text' => 'string', 'type' => 'type', 'modifiers' => ['defaultLibrary']])
        ->toContain(['text' => 'int', 'type' => 'type', 'modifiers' => ['defaultLibrary']])
        ->toContain(['text' => 'null', 'type' => 'enumMember', 'modifiers' => ['defaultLibrary']]);
});

test('semantic token protocol rejects documents outside the configured project', function (): void {
    $root = $this->createTemporaryDirectory();
    $this->writeConfiguration($root);
    $this->writeFile($root . '/src/index.ppphp', "<?php\n");
    $process = new Process([
        PHP_BINARY,
        dirname(__DIR__, 3) . '/bin/ppphp',
        'editor:semantic-tokens',
        '--working-directory',
        $root,
        '--format=json',
        '--no-ansi',
    ]);
    $process->setInput(json_encode([
        'version' => EditorSemanticTokensRequest::VERSION,
        'document' => [
            'path' => 'outside.ppphp',
            'contents' => "<?php\n",
        ],
    ], JSON_THROW_ON_ERROR));
    $process->run();
    $response = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);

    expect($process->getExitCode())->toBe(ExitCode::InvalidProject->value)
        ->and($response['error']['code'] ?? null)->toBe('document-not-owned')
        ->and($response['tokens'] ?? null)->toBe([]);
});
