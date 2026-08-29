# PHPStan Integration

> **Status:** Implemented in Stage 6.

PHPStan has two independent roles:

1. `phpstan.neon.dist` checks the compiler implementation.
2. `resources/phpstan/ppphp.neon` is the compiler-owned base for user-project analysis.

PHPStan is a pinned, replaceable backend. Its rule level and wording are not the ++PHP language contract.

## Analysis Workspace

Each check prepares `.ppphp-cache/analysis/` with deterministic areas for selected files, unselected context, configured stubs, backend configuration, mappings, results, and temporary backend data. A source-root hash prevents equal relative paths from different roots from colliding.

Selected `.ppp` is parsed, checked, and lowered to analysis PHP. Selected `.php` is copied byte-for-byte. Valid unselected sources are prepared as scan context; invalid unrelated sources are omitted without surfacing their diagnostics. Configured stubs are supplied as `stubFiles` and scan context. Existing Composer PSR-4, classmap, and files paths are scanned as data.

Production lowering returns generated contents, the source edits, and a generated-to-original source map. Copied PHP uses an identity map. Backend findings are mapped through these records to original `.ppp` or `.php` spans.

## Process Boundary

`PhpStanProjectAnalyzer` implements the compiler-owned `ProjectAnalyzer` interface. It resolves the Composer-installed PHPStan binary under the compiler package and invokes it through `PHP_BINARY` and Symfony Process with argument-array execution, JSON output, no progress display, a finite timeout, and a generated configuration.

Exit code 0 is success and exit code 1 may contain normal source findings. Timeouts, missing executables, unexpected exits, malformed JSON, and invalid result shapes become `P6005` or `P6006` diagnostics.

The generated configuration sets the target PHP version, selected `paths`, context `scanFiles` and `scanDirectories`, configured `stubFiles`, and a workspace-local `tmpDir`. The PHPStan level is compiler-owned and is not a project setting.

## Security Boundary

Analysis does not load or execute:

- a project `phpstan.neon`, baseline, bootstrap, extension, or custom rule;
- project `vendor/autoload.php`;
- Composer scripts or plugins;
- Composer `autoload.files` entries as executable code; or
- application bootstrap files.

Source and metadata are parsed or scanned as data. Normal diagnostics never expose analysis-cache paths, backend command lines, or raw PHPStan identifiers. Debug metadata retains backend details for infrastructure diagnosis.
