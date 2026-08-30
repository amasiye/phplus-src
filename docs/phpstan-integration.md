# PHPStan Integration

> **Status:** Implemented in Stage 6 and extended for checked errors in Stage 7 and generic interoperability in Stage 8.

PHPStan has two independent roles:

1. `phpstan.neon.dist` checks the compiler implementation.
2. `resources/phpstan/ppphp.neon` is the compiler-owned base for user-project analysis.

PHPStan is a pinned, replaceable backend. Its rule level and wording are not the ++PHP language contract.

## Analysis Workspace

Each check prepares `.ppphp-cache/analysis/` with deterministic areas for selected files, unselected context, configured stubs, backend configuration, mappings, results, and temporary backend data. A source-root hash prevents equal relative paths from different roots from colliding.

Selected `.ppphp` is parsed, checked, and lowered to analysis PHP with complete generic, typed-array, composite, checked-error, and `when` result metadata. Selected `.php` is copied byte-for-byte. Valid unselected sources are prepared as scan context; invalid unrelated sources are omitted without surfacing their diagnostics. Configured stubs are supplied as `stubFiles` and scan context. Preserved Composer source PSR-4, classmap, and files paths are scanned as data even after runtime mappings point to generated output.

Production lowering returns generated contents, the source edits, and a generated-to-original source map. Copied PHP uses an identity map. Backend findings are mapped through these records to original `.ppphp` or `.php` spans. Successful production builds persist the same mapping model beneath the output `.ppphp/source-maps/` directory; analysis remains independent of a prior production build.

For `when`, the analysis workspace receives real closure-free conditional control flow rather than the parser's normalized placeholder. Result temporaries carry the inferred semantic type at their consuming statement. Source-edit submappings associate conditions, result expressions, and temporary uses with the owning source spans, so backend member, argument, return, and typed-array findings report the original `.ppphp` location without generated variable names or synthetic control-flow paths.

## Process Boundary

`PhpStanProjectAnalyzer` implements the compiler-owned `ProjectAnalyzer` interface. It resolves the Composer-installed PHPStan binary under the compiler package and invokes it through `PHP_BINARY` and Symfony Process with argument-array execution, JSON output, no progress display, a finite timeout, and a generated configuration.

Exit code 0 is success and exit code 1 may contain normal source findings. Timeouts, missing executables, unexpected exits, malformed JSON, and invalid result shapes become `P6005` or `P6006` diagnostics.

The generated configuration sets the target PHP version, selected `paths`, context `scanFiles` and `scanDirectories`, configured `stubFiles`, and a workspace-local `tmpDir`. The PHPStan level is compiler-owned and is not a project setting.

## Checked Exceptions

The compiler-owned base configuration treats Exception descendants as checked and Error descendants as unchecked. Implicit throws are disabled; missing checked `@throws` declarations and throw-contract covariance are enabled. Unused throws findings are suppressed because a native ++PHP clause is an explicit public contract.

Supported backend exception identifiers map to P4002, P4003, P4004, P4012, or P4013. Internal semantic diagnostics run first, and project checking stops before backend analysis when those errors already invalidate the selected source. Remaining backend findings are source-mapped and deduplicated through the normal diagnostic pipeline.

## Generics And Typed Arrays

The compiler emits PHPStan-compatible `@template`, bound, parameter, return, property, inheritance, trait-use, `list<T>`, and `array<K, V>` metadata before backend analysis. PHPStan completes flow-sensitive call-site inference and substitution; stable compiler mappings convert supported generic and collection findings to P3xxx diagnostics.

Ordinary PHP and configured stubs are parsed through the same PHPDoc model and may provide generic contracts to ++PHP callers. Native ++PHP generic syntax is authoritative over PHPDoc on the same declaration. Raw generic types remain permitted at ordinary PHP boundaries when their PHPDoc contract does not provide arguments, but are rejected in .ppphp.

## Security Boundary

Analysis does not load or execute:

- a project `phpstan.neon`, baseline, bootstrap, extension, or custom rule;
- project `vendor/autoload.php`;
- Composer scripts or plugins;
- Composer `autoload.files` entries as executable code; or
- application bootstrap files.

Source and metadata are parsed or scanned as data. Normal diagnostics never expose analysis-cache paths, backend command lines, or raw PHPStan identifiers. Debug metadata retains backend details for infrastructure diagnosis.
