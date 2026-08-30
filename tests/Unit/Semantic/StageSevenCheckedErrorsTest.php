<?php

declare(strict_types=1);

use Amasiye\Ppphp\Diagnostics\Diagnostic;
use Amasiye\Ppphp\Diagnostics\DiagnosticBag;
use Amasiye\Ppphp\Diagnostics\Enumerations\DiagnosticCode;
use Amasiye\Ppphp\Frontend\Enumerations\ParseMode;
use Amasiye\Ppphp\Frontend\PpphpParser;
use Amasiye\Ppphp\Project\ProjectParseResult;
use Amasiye\Ppphp\Semantic\SemanticAnalysisResult;
use Amasiye\Ppphp\Semantic\SemanticAnalyzer;
use Amasiye\Ppphp\Source\Enumerations\FileKind;
use Amasiye\Ppphp\Source\SourceFile;
use Amasiye\Ppphp\Support\Path;
use Amasiye\Ppphp\Transpilation\PhpLowerer;
use Symfony\Component\Process\Process;

/**
 * @param array<string, array{FileKind, string}> $files
 * @return array{ProjectParseResult, SemanticAnalysisResult}
 */
function analyzeCheckedErrorProject(array $files): array
{
    $parser = new PpphpParser();
    $diagnostics = new DiagnosticBag();
    $parsedFiles = [];
    $sourceFiles = [];

    foreach ($files as $relativePath => [$kind, $contents]) {
        $path = '/project/' . $relativePath;
        $source = new SourceFile($path, $relativePath, $kind, $contents);
        $parse = $parser->parse(
            $source,
            $kind === FileKind::Ppp ? ParseMode::PlusPlusPhp : ParseMode::Php,
        );
        $diagnostics->addAll($parse->diagnostics);
        $key = Path::buildComparisonKey($path);
        $sourceFiles[$key] = $source;

        if ($parse->parsedFile !== null) {
            $parsedFiles[$key] = $parse->parsedFile;
        }
    }

    $project = new ProjectParseResult($parsedFiles, $sourceFiles, $diagnostics);

    return [$project, (new SemanticAnalyzer())->analyze($project)];
}

/** @return list<string> */
function checkedErrorCodes(SemanticAnalysisResult $result): array
{
    return array_map(
        static fn (Diagnostic $diagnostic): string => $diagnostic->code->value,
        iterator_to_array($result->diagnostics),
    );
}

/** @return list<string> */
function checkedErrorMessages(SemanticAnalysisResult $result): array
{
    return array_map(
        static fn (Diagnostic $diagnostic): string => $diagnostic->message,
        iterator_to_array($result->diagnostics),
    );
}

test('direct checked errors are declared while PHP Error descendants remain unchecked', function (): void {
    [, $valid] = analyzeCheckedErrorProject([
        'src/Feature.ppp' => [FileKind::Ppp, <<<'PPP'
<?php
final class StorageFailure extends \RuntimeException {}
function load(): void throws StorageFailure
{
    throw new StorageFailure();
}
function unchecked(): void
{
    throw new \TypeError();
}
PPP],
    ]);
    [, $invalid] = analyzeCheckedErrorProject([
        'src/Feature.ppp' => [FileKind::Ppp, <<<'PPP'
<?php
final class StorageFailure extends \RuntimeException {}
function load(): void
{
    throw new StorageFailure();
}
PPP],
    ]);

    expect($valid->isSuccessful)->toBeTrue()
        ->and(checkedErrorCodes($valid))->toBe([])
        ->and(checkedErrorCodes($invalid))->toContain(DiagnosticCode::CheckedErrorNotHandled->value);
});

test('called contracts propagate through functions constructors instance static and nullsafe calls', function (): void {
    [, $analysis] = analyzeCheckedErrorProject([
        'src/Feature.ppp' => [FileKind::Ppp, <<<'PPP'
<?php
final class StorageFailure extends \RuntimeException {}
function load(): void throws StorageFailure
{
    throw new StorageFailure();
}
final class Repository
{
    public function __construct() throws StorageFailure
    {
        throw new StorageFailure();
    }

    public function find(): void throws StorageFailure
    {
        throw new StorageFailure();
    }

    public static function refresh(): void throws StorageFailure
    {
        throw new StorageFailure();
    }
}
function callFunction(): void { load(); }
function construct(): void { new Repository(); }
function callInstance(Repository $repository): void { $repository->find(); }
function callNullsafe(?Repository $repository): void { $repository?->find(); }
function callStatic(): void { Repository::refresh(); }
PPP],
    ]);

    expect(array_count_values(checkedErrorCodes($analysis))[DiagnosticCode::CheckedErrorNotHandled->value] ?? 0)
        ->toBe(5);
});

test('catches remove handled errors and finally termination suppresses pending errors', function (): void {
    [, $analysis] = analyzeCheckedErrorProject([
        'src/Feature.ppp' => [FileKind::Ppp, <<<'PPP'
<?php
class StorageFailure extends \RuntimeException {}
class OtherFailure extends \RuntimeException {}
function caught(): void
{
    try {
        throw new StorageFailure();
    } catch (\Exception $error) {
    }
}
function replaced(): void throws OtherFailure
{
    try {
        throw new StorageFailure();
    } finally {
        throw new OtherFailure();
    }
}
function suppressed(): void
{
    try {
        throw new StorageFailure();
    } finally {
        return;
    }
}
PPP],
    ]);

    expect($analysis->isSuccessful)->toBeTrue()
        ->and(checkedErrorCodes($analysis))->toBe([]);
});

test('file anonymous and destructor scopes cannot leak checked errors', function (): void {
    [, $analysis] = analyzeCheckedErrorProject([
        'src/Feature.ppp' => [FileKind::Ppp, <<<'PPP'
<?php
final class StorageFailure extends \RuntimeException {}
$closure = function (): void { throw new StorageFailure(); };
$arrow = fn (): never => throw new StorageFailure();
final class Resource
{
    public function __destruct()
    {
        throw new StorageFailure();
    }
}
throw new StorageFailure();
PPP],
    ]);

    expect(checkedErrorCodes($analysis))
        ->toContain(DiagnosticCode::CheckedErrorCannotEscapeFileScope->value)
        ->toContain(DiagnosticCode::CheckedErrorCannotEscapeAnonymousCallable->value)
        ->toContain(DiagnosticCode::CheckedErrorCannotEscapeDestructor->value);
});

test('checked error contracts narrow inherited methods and exclude constructors', function (): void {
    [, $analysis] = analyzeCheckedErrorProject([
        'src/Feature.ppp' => [FileKind::Ppp, <<<'PPP'
<?php
class StorageFailure extends \RuntimeException {}
class ParentService
{
    public function perform(): void throws StorageFailure {}
    public function __construct() {}
}
class ChildService extends ParentService
{
    public function perform(): void throws \Exception {}
    public function __construct() throws StorageFailure
    {
        throw new StorageFailure();
    }
}
PPP],
    ]);

    expect(checkedErrorCodes($analysis))
        ->toContain(DiagnosticCode::CheckedErrorDeclarationNotCovariant->value)
        ->not->toContain(DiagnosticCode::CheckedErrorNotHandled->value);
});

test('ordinary PHP and configured stubs contribute PHPDoc contracts with stub precedence', function (): void {
    [, $phpBoundary] = analyzeCheckedErrorProject([
        'src/Boundary.php' => [FileKind::Php, <<<'PHP'
<?php
namespace App;
class Failure extends \RuntimeException {}
/** @throws Failure when storage is unavailable */
function load(): void {}
PHP],
        'src/Caller.ppp' => [FileKind::Ppp, <<<'PPP'
<?php
namespace App;
function caller(): void
{
    load();
}
PPP],
    ]);
    [, $stubBoundary] = analyzeCheckedErrorProject([
        'src/Boundary.php' => [FileKind::Php, <<<'PHP'
<?php
namespace App;
class ProjectFailure extends \RuntimeException {}
/** @throws ProjectFailure */
function load(): void {}
PHP],
        'stubs/Boundary.stub.php' => [FileKind::Stub, <<<'PHP'
<?php
namespace App;
class StubFailure extends \RuntimeException {}
/** @throws StubFailure */
function load(): void {}
PHP],
        'src/Caller.ppp' => [FileKind::Ppp, <<<'PPP'
<?php
namespace App;
function caller(): void
{
    load();
}
PPP],
    ]);

    expect(checkedErrorCodes($phpBoundary))->toContain(DiagnosticCode::CheckedErrorNotHandled->value)
        ->and(checkedErrorCodes($stubBoundary))->toContain(DiagnosticCode::CheckedErrorNotHandled->value)
        ->and(implode("\n", checkedErrorMessages($stubBoundary)))->toContain('StubFailure')
        ->not->toContain('ProjectFailure');
});

test('native clauses validate throwable types PHPDoc authority and dynamic boundaries', function (): void {
    [, $analysis] = analyzeCheckedErrorProject([
        'src/Feature.ppp' => [FileKind::Ppp, <<<'PPP'
<?php
class Failure extends \RuntimeException {}
class NotThrowable {}
function scalar(): void throws Failure|int {}
function invalidClass(): void throws NotThrowable {}
/** @throws Failure */
function docOnly(): void {}
/** @throws \LogicException */
function conflict(): void throws Failure {}
function dynamic(callable $callback): void
{
    $callback();
}
PPP],
    ]);

    expect(checkedErrorCodes($analysis))
        ->toContain(DiagnosticCode::ErrorTypeNotThrowable->value)
        ->toContain(DiagnosticCode::NativeThrowsClauseRequired->value)
        ->toContain(DiagnosticCode::ThrowsDocumentationConflictsWithNativeClause->value)
        ->toContain(DiagnosticCode::UncheckedCallBoundary->value)
        ->and($analysis->diagnostics->warnings)->toHaveCount(1);
});

test('throws lowering preserves PHPDoc attributes source style and ordinary runtime behavior', function (): void {
    $contents = <<<'PPP'
<?php
namespace App;
final class Failure extends \RuntimeException {}
/**
 * Loads a value.
 *
 * @return void
 */
#[Demo]
function load(): void throws Failure
{
    throw new Failure('caught');
}
try {
    load();
} catch (Failure $error) {
    echo $error->getMessage();
}
PPP;
    [$project, $analysis] = analyzeCheckedErrorProject([
        'src/Feature.ppp' => [FileKind::Ppp, $contents],
    ]);
    $parsed = $project->findParsedFile('/project/src/Feature.ppp');
    $model = $analysis->findModel('/project/src/Feature.ppp');

    expect($analysis->isSuccessful)->toBeTrue()
        ->and($parsed)->not->toBeNull()
        ->and($model)->not->toBeNull();

    $generated = (new PhpLowerer())->lower($parsed, $model)->contents;
    $path = sys_get_temp_dir() . '/ppphp-stage-seven-errors-' . bin2hex(random_bytes(6)) . '.php';
    file_put_contents($path, $generated);
    $lint = new Process([PHP_BINARY, '-l', $path]);
    $lint->run();
    $runtime = new Process([PHP_BINARY, $path]);
    $runtime->run();
    unlink($path);

    expect($generated)->toContain('Loads a value.')
        ->toContain('@return void')
        ->toContain('@throws \\App\\Failure')
        ->toContain('#[Demo]')
        ->not->toContain('throws Failure')
        ->and($lint->isSuccessful())->toBeTrue()
        ->and($runtime->isSuccessful())->toBeTrue()
        ->and($runtime->getOutput())->toBe('caught');
});

test('throws lowering covers interface and abstract owners without duplicating matching PHPDoc', function (): void {
    $contents = <<<'PPP'
<?php
namespace App;
class Failure extends \RuntimeException {}
interface Repository
{
    /** @throws \App\Failure when unavailable */
    public function load(): void throws Failure;
}
abstract class Service
{
    /** Performs work. */
    abstract protected function perform(): void throws Failure;
}
PPP;
    [$project, $analysis] = analyzeCheckedErrorProject([
        'src/Contracts.ppp' => [FileKind::Ppp, $contents],
    ]);
    $parsed = $project->findParsedFile('/project/src/Contracts.ppp');
    $model = $analysis->findModel('/project/src/Contracts.ppp');

    expect($analysis->isSuccessful)->toBeTrue()
        ->and($parsed)->not->toBeNull()
        ->and($model)->not->toBeNull();

    $generated = (new PhpLowerer())->lower($parsed, $model)->contents;
    $path = sys_get_temp_dir() . '/ppphp-stage-seven-contracts-' . bin2hex(random_bytes(6)) . '.php';
    file_put_contents($path, $generated);
    $lint = new Process([PHP_BINARY, '-l', $path]);
    $lint->run();
    unlink($path);

    expect(substr_count($generated, '@throws \\App\\Failure'))->toBe(2)
        ->and($generated)->toContain('@throws \\App\\Failure when unavailable')
        ->toContain('Performs work.')
        ->not->toContain('throws Failure')
        ->and($lint->isSuccessful())->toBeTrue();
});

test('throw expressions rethrows recursion and parent calls use declared contracts', function (): void {
    [, $analysis] = analyzeCheckedErrorProject([
        'src/Feature.ppp' => [FileKind::Ppp, <<<'PPP'
<?php
class Failure extends \RuntimeException {}
class ParentService
{
    public function __construct() throws Failure {}
    public function perform(): void throws Failure {}
}
class ChildService extends ParentService
{
    public function __construct() throws Failure
    {
        parent::__construct();
    }

    public function perform(): void throws Failure
    {
        parent::perform();
    }
}
function value(?string $input): string throws Failure
{
    return $input ?? throw new Failure();
}
function rethrow(): void throws \Exception
{
    try {
        throw new Failure();
    } catch (Failure $error) {
        throw $error;
    }
}
function recurse(bool $again): void throws Failure
{
    if ($again) {
        recurse(false);
    }

    throw new Failure();
}
PPP],
    ]);

    expect($analysis->isSuccessful)->toBeTrue()
        ->and(checkedErrorCodes($analysis))->toBe([]);
});

test('partial multi catches catch bodies and catch ordering retain precise flow', function (): void {
    [, $analysis] = analyzeCheckedErrorProject([
        'src/Feature.ppp' => [FileKind::Ppp, <<<'PPP'
<?php
class FirstFailure extends \RuntimeException {}
class SecondFailure extends \RuntimeException {}
function first(): void throws FirstFailure { throw new FirstFailure(); }
function second(): void throws SecondFailure { throw new SecondFailure(); }
function partial(): void throws SecondFailure
{
    try {
        first();
        second();
    } catch (FirstFailure) {
    }
}
function multi(): void
{
    try {
        first();
        second();
    } catch (FirstFailure|SecondFailure) {
    }
}
function catchBody(): void throws SecondFailure
{
    try {
        first();
    } catch (FirstFailure) {
        throw new SecondFailure();
    }
}
function ordered(): void
{
    try {
        first();
    } catch (\Exception) {
    } catch (FirstFailure) {
    }
}
PPP],
    ]);

    expect(array_count_values(checkedErrorCodes($analysis))[DiagnosticCode::ErrorCatchUnreachable->value] ?? 0)
        ->toBe(1)
        ->and(checkedErrorCodes($analysis))->not->toContain(DiagnosticCode::CheckedErrorNotHandled->value);
});

test('PHPDoc imports unions multiple tags aliases fully qualified names and void', function (): void {
    [, $analysis] = analyzeCheckedErrorProject([
        'src/Failure.php' => [FileKind::Php, <<<'PHP'
<?php
namespace Library;
class Failure extends \RuntimeException {}
PHP],
        'src/Boundary.php' => [FileKind::Php, <<<'PHP'
<?php
namespace App;
use Library\Failure as ImportedFailure;
/**
 * @throws ImportedFailure|\LogicException when unavailable
 * @throws void
 */
function boundary(): void {}
/** @throws void */
function safeBoundary(): void {}
PHP],
        'src/Caller.ppp' => [FileKind::Ppp, <<<'PPP'
<?php
namespace App;
function caller(): void
{
    boundary();
    safeBoundary();
}
PPP],
    ]);

    expect(array_count_values(checkedErrorCodes($analysis))[DiagnosticCode::CheckedErrorNotHandled->value] ?? 0)
        ->toBe(2)
        ->and(implode("\n", checkedErrorMessages($analysis)))
        ->toContain('Library\\Failure')
        ->toContain('LogicException');
});

test('all genuinely dynamic invocation forms warn without becoming errors', function (): void {
    [, $analysis] = analyzeCheckedErrorProject([
        'src/Feature.ppp' => [FileKind::Ppp, <<<'PPP'
<?php
function invoke(callable $callback, object $object, string $method): void
{
    $callback();
    $object->{$method}();
    call_user_func($callback);
    call_user_func_array($callback, []);
}
PPP],
    ]);

    expect($analysis->isSuccessful)->toBeTrue()
        ->and(array_count_values(checkedErrorCodes($analysis))[DiagnosticCode::UncheckedCallBoundary->value] ?? 0)
        ->toBe(4)
        ->and($analysis->diagnostics->warnings)->toHaveCount(4);
});

test('override compatibility handles empty private interface and unchecked contracts', function (): void {
    [, $analysis] = analyzeCheckedErrorProject([
        'src/Feature.ppp' => [FileKind::Ppp, <<<'PPP'
<?php
class Failure extends \RuntimeException {}
interface Contract
{
    public function perform(): void throws Failure;
}
class ValidService implements Contract
{
    public function perform(): void throws Failure {}
}
class EmptyParent
{
    public function run(): void {}
    private function privateRun(): void {}
}
class InvalidChild extends EmptyParent
{
    public function run(): void throws Failure {}
    public function privateRun(): void throws Failure {}
}
class ErrorParent
{
    public function unchecked(): void throws \Error {}
}
class ErrorChild extends ErrorParent
{
    public function unchecked(): void throws \TypeError {}
}
PPP],
    ]);

    expect(array_count_values(checkedErrorCodes($analysis))[DiagnosticCode::CheckedErrorDeclarationNotCovariant->value] ?? 0)
        ->toBe(1);
});

test('throws lowering preserves CRLF and maps generated declarations to original source', function (): void {
    $contents = "<?php\r\nnamespace App;\r\nclass Failure extends \\RuntimeException {}\r\nabstract class Service\r\n{\r\n    /** Performs work. */\r\n    abstract protected function perform(): void throws Failure;\r\n}\r\n";
    [$project, $analysis] = analyzeCheckedErrorProject([
        'src/Contracts.ppp' => [FileKind::Ppp, $contents],
    ]);
    $parsed = $project->findParsedFile('/project/src/Contracts.ppp');
    $model = $analysis->findModel('/project/src/Contracts.ppp');

    expect($analysis->isSuccessful)->toBeTrue()
        ->and($parsed)->not->toBeNull()
        ->and($model)->not->toBeNull();

    $generated = (new PhpLowerer())->lower($parsed, $model);
    $generatedNameOffset = strpos($generated->contents, 'perform');

    expect(str_replace("\r\n", '', $generated->contents))->not->toContain("\n")
        ->not->toContain("\r")
        ->and($generated->contents)->toContain('Performs work.')
        ->toContain('@throws \\App\\Failure')
        ->not->toContain('throws Failure')
        ->and($generatedNameOffset)->not->toBeFalse()
        ->and($generated->sourceMap->resolveOriginalOffset($generatedNameOffset))
        ->toBe(strpos($contents, 'perform'));
});

test('nested anonymous binding types do not leak into their enclosing callable', function (): void {
    [, $analysis] = analyzeCheckedErrorProject([
        'src/Feature.ppp' => [FileKind::Ppp, <<<'PPP'
<?php
class OuterFailure extends \RuntimeException {}
class InnerFailure extends \RuntimeException {}
class OuterService
{
    public function run(): void throws OuterFailure {}
}
class InnerService
{
    public function run(): void throws InnerFailure {}
}
function execute(OuterService $service): void throws OuterFailure
{
    callable $closure = function (): void {
        InnerService $service = new InnerService();

        try {
            $service->run();
        } catch (InnerFailure) {
        }
    };

    $service->run();
}
PPP],
    ]);

    expect(checkedErrorCodes($analysis))->toBe([])
        ->and($analysis->isSuccessful)->toBeTrue();
});

test('unqualified function calls prefer the current namespace before the global fallback', function (): void {
    [, $analysis] = analyzeCheckedErrorProject([
        'src/Feature.ppp' => [FileKind::Ppp, <<<'PPP'
<?php
namespace {
    class GlobalFailure extends \RuntimeException {}
    function execute(): void throws GlobalFailure { throw new GlobalFailure(); }
}
namespace App {
    class LocalFailure extends \RuntimeException {}
    function execute(): void throws LocalFailure { throw new LocalFailure(); }
    function acceptsLocal(): void throws LocalFailure { execute(); }
    function acceptsGlobal(): void throws \GlobalFailure { execute(); }
}
PPP],
    ]);

    expect(array_count_values(checkedErrorCodes($analysis))[DiagnosticCode::CheckedErrorNotHandled->value] ?? 0)
        ->toBe(1)
        ->and(implode("\n", checkedErrorMessages($analysis)))
        ->toContain('App\\LocalFailure');
});

test('unresolved named invocation targets warn at every static call boundary', function (): void {
    [, $analysis] = analyzeCheckedErrorProject([
        'src/Feature.ppp' => [FileKind::Ppp, <<<'PPP'
<?php
function invokeExternal(ExternalService $service): void
{
    external_function();
    ExternalService::perform();
    $service->perform();
    new ExternalService();
}
PPP],
    ]);

    expect($analysis->isSuccessful)->toBeTrue()
        ->and(array_count_values(checkedErrorCodes($analysis))[DiagnosticCode::UncheckedCallBoundary->value] ?? 0)
        ->toBe(4)
        ->and($analysis->diagnostics->warnings)->toHaveCount(4);
});

test('throwable interfaces participate in classification catch coverage and declared contracts', function (): void {
    [, $analysis] = analyzeCheckedErrorProject([
        'src/Feature.ppp' => [FileKind::Ppp, <<<'PPP'
<?php
interface FailureContract extends \Throwable {}
class Failure extends \Exception implements FailureContract {}
function declared(): void throws FailureContract
{
    throw new Failure();
}
function caught(): void
{
    try {
        throw new Failure();
    } catch (FailureContract) {
    }
}
PPP],
    ]);

    expect($analysis->isSuccessful)->toBeTrue()
        ->and(checkedErrorCodes($analysis))->toBe([]);
});
