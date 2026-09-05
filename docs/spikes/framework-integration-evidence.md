# Framework Integration Evidence And Decisions

Date: **2026-09-05**. Base compiler: `3a0cffcc291e0b128b96cc7ab13529fedff022f2`; experiments are the accompanying test-only changes. Earlier layout-only runs began at `9a618c0`; the repository-contained amendment was then read and incorporated without discarding that concurrent commit. See [scope](framework-integration-2026.4.md), [versions/seams](framework-integration-matrix.md), [FI-1 handoff](framework-integration-fi1-codex-prompt.md).

**Outcome: GO WITH LIMITS for FI-1.** Production framework integration remains unqualified. Negative probes are useful evidence, not passing application support. No production command/schema/manifest, dependency, existing golden, namespace or release identity changed.

## Platform Evidence

Explicit interpreters:

```text
PHP84=/opt/homebrew/opt/php@8.4/bin/php
  actual /opt/homebrew/Cellar/php@8.4/8.4.21/bin/php, PHP 8.4.21 CLI
PHP85=/opt/homebrew/opt/php/bin/php
  actual /opt/homebrew/Cellar/php/8.5.6/bin/php, PHP 8.5.6 CLI
COMPOSER=/usr/local/bin/composer
```

Compiler lock SHA-256: `eba71eec9b50b3d0a92fb5d5706f40aba4c4398ddfd25511922529574f3d9c56`. Installed parser `nikic/php-parser v5.8.0` can emulate the specimen grammar on the older host. Production frontend explicitly selects/restricts 8.4. Production built-in signatures `8.4.23.2` derive from php-src 8.4.23 commit `52cee85adfeeb6f017f2ac796ab7973353702c20`; **no production 8.5 signature package exists**. The experiment's signature identity is the SHA-256 of `PlatformProfile.php`, explicitly a two-function capability specimen rather than fabricated full signature data.

From repository root, execute these exact matrix commands (the symlink targets were verified above):

```bash
/opt/homebrew/opt/php@8.4/bin/php tests/Support/FrameworkIntegrationSpike/run-platform-matrix.php /opt/homebrew/opt/php@8.4/bin/php /opt/homebrew/opt/php/bin/php /usr/local/bin/composer
/opt/homebrew/opt/php/bin/php tests/Support/FrameworkIntegrationSpike/run-platform-matrix.php /opt/homebrew/opt/php@8.4/bin/php /opt/homebrew/opt/php/bin/php /usr/local/bin/composer
```

Both runs: **29 PASS, 2 NOT RUN, exit 2**. Exit 2 deliberately retains the separate unexecuted complete-framework/source-free gates; it is not a green production-support exit. No unexpected FAIL rows. Each emitted JSON row includes revision, actual host, parser version, production/specimen signature identity, emission mode/target, runtime identity, lock, command, exit and outcome. Runtime-identification rows include versioned loaded extensions under the same `-n` mode as native lint/execution. Composer uses normal CLI configuration and its own requirement checks, not `-n` or ignored requirements. Missing executables/Composer paths produce NOT RUN; wrong identities produce FAIL.

| Host → platform/runtime | Same fixture set / stages | Observed outcome |
| --- | --- | --- |
| 8.4.21 → 8.4 / 8.4.21 | Hooks and `array_find`: parser/specimen check, native lint, native execution | PASS; output `hooks` / `7` |
| 8.4.21 → 8.5 / 8.5.6 | Same project specimens including pipe and `array_first`, selected target interpreter | PASS for the scoped parser/API gates and native fixtures; output `probe` / `first` |
| 8.5.6 → 8.5 / 8.5.6 | Same-version newer profile | PASS for all specimen stages |
| 8.5.6 → 8.4 / 8.4.21 | Same-version policy on newer host, older runtime explicitly selected | PASS, including expected rejection of newer features |

These are ordinary-PHP input files passed unchanged to the selected native runtime, **not ++PHP-to-8.5 lowering**. Fixture sources link to official [PHP 8.4](https://www.php.net/releases/8.4/en.php), [PHP 8.5](https://www.php.net/releases/8.5/en.php), [array_find](https://www.php.net/manual/en/function.array-find.php) and [array_first](https://www.php.net/manual/en/function.array-first.php) contracts. The arithmetic/examples are independently written minimal tests, not imported framework code.

Negative rows are strict: 8.4 parser rejects pipe with `SPIKE_PARSE: Syntax error`; 8.4 lint/runtime must contain the native syntax error; 8.4 API profile rejects `array_first` with `SPIKE_API_REQUIRES_8_5_SIGNATURES`. Native 8.4 lint **accepts** that API call, but execution rejects it as undefined. This is direct evidence that syntax-only lint cannot validate API compatibility. Unit tests also reject native emission/runtime mismatches, missing extension/minimum requirements, and unreviewed 8.6.

The harness invokes real Composer `check-platform-reqs --lock --format=json` against the committed **synthetic negative lock input**. It is not a resolved/deployable installation. On 8.4: PHP requirement `>=8.5` fails and `ext-fi0-unavailable` is missing; on 8.5: PHP succeeds and the extension remains missing. Both require nonzero exit and exact JSON statuses to count as expected-rejection PASS. Actual Laravel's solved lock separately passes platform checks on both runtimes. No `--ignore-platform-reqs` was used.

| Gate | FI-0 result | Limit / production acceptance still required |
| --- | --- | --- |
| PV-1 | PASS, scoped specimen checks differ correctly for 8.4/8.5 syntax and API; native results independently checked | NOT RUN: complete 8.5 compiler semantic analysis/signature package/lowering; registry covers only named specimens |
| PV-2 | PASS, actual host 8.4.21 drives parser emulation and explicit runtime 8.5.6; same-version rows separate | Preserve declared host minimum; audit compiler lexer, dependencies and full native/++PHP pipeline |
| PV-3 | PASS, explicitly identified native lint/execution interpreters | Production lint still uses `PHP_BINARY`; generated 8.5 ++PHP output NOT RUN |
| PV-4 | PASS, observed native, Composer, API, extension and unknown-target rejections | Prototype minimum checker is not a Composer solver; production complete-application diagnostics not implemented |
| PV-5 | PASS, proposed identity changes for host/syntax/signatures/emission/runtime, compiler/parser/signature/lock and extension versions | In-memory proposed identity only; no claim of production cross-target cache/manifest support or persisted cache round trips |
| PV-6 | PASS, centralized reviewed capability registry, no framework-name selection, unknown versions rejected | Next release requires capability/grammar review, signatures, negative/positive matrix and support publication; adding a registry key alone is insufficient |

## Production Inventory And FI-1 Mapping

Locations are repository-relative. Defaults and historical pins are intentional until their owning slice gains evidence; do not blanket-replace version strings.

| Dimension / current location | Finding / required bounded slice |
| --- | --- |
| Host: `composer.json`, `composer.lock` | Compiler `^8.4`; Symfony Console/Process `^8.1`, parser `^5.6` (locked 5.8), PHPStan `^2.2`, phpdoc parser `^2.3`, Pest `^5.1`. Lock constraints can impose patch minimums (Symfony >=8.4.1). Validate actual host dependency floor, do not equate it with target. |
| Config: `resources/schema/ppphp.schema.json:40`, `src/Config/ProjectConfigLoader.php:17,144` | Target enum/guard only 8.4. Preserve default and diagnostic; later central selection must precede public target exposure. |
| Frontend: `src/Frontend/PhpParserAdapter.php:25–32`, `PhpParserDiagnosticMapper.php`; `src/Frontend/Token/Lexer.php` | Fixed parser version/guard and diagnostic copy; own lexer uses host `PhpToken`. Upstream emulation success does not prove all compiler token handling supports a newer target. |
| Nested syntax: `src/Semantic/When/WhenFragmentParser.php:29,205` | Separate 8.4 parser and message; route through same syntax capabilities, not a second ceiling. |
| Dependency data: `src/Interop/Composer/ComposerDependencyDeclarationLoader.php:30,158`; `Index/PortableDeclarationValidator.php:21`; `Index/DeclarationCompatibilityIdentity.php:18` | Loader defaults to fixed PpphpParser; validator instead chooses newest supported parser; identity says `body-free-php-8.4-declarations`. Make syntax contract explicit end to end and preserve non-executing/path/resource bounds. |
| Platform data: `src/Interop/Php/Signature/PhpSignaturePackageGenerator.php:19,34`; `PhpStubNormalizer.php:110`; `PhpSignaturePackageVerifier.php:20,131`; `resources/php-signatures/8.4/` | Version-specific generator/package guard, parser, manifest, overrides and shards. Generate/verify authoritative 8.5 data; do not infer from host or copy 8.4 labels. |
| Analysis: `src/Analysis/DeclarationContextCollector.php:81`; `src/Analysis/PhpStan/PhpStanConfigBuilder.php:26,75`; `PhpStanAnalysisPlanBuilder.php:57` | Target propagated to selected context/backend numeric PHP version; subprocess host defaults to `PHP_BINARY`. Distinguish analysis platform from interpreter and preserve normal supplemental path. |
| Lowering: `src/Transpilation/Emission/ProductionPhpEmitter.php`; `src/Transpilation/` passes | Ordinary PHP is copied, not rewritten. Thread capabilities into actual lowering/output validation; reject unsupported lowering instead of assuming all passes are version-independent. No native backward-transpilation promise. |
| Lint: `src/Compiler/Validation/SymfonyPhpLintRunner.php:17` | `[PHP_BINARY, '-n', '-l', path]` conflates host and target runtime. Introduce explicit validated executable identity while preserving bounded subprocess safety. |
| Manifest: `src/Compiler/Manifest/BuildManifest.php`, `BuildManifestCodec.php`, `ConfigurationFingerprint.php:38`; `src/Compiler/Output/AtomicBuildCommitter.php:271` | Target string alone is not complete syntax/signature/emitter/runtime identity. Resource/file ownership and generation identity need versioned serialization and migration/recovery tests. |
| Cache: `src/Cache/CompilerBuildIdentity.php:30–31`, `ProjectInputSnapshotBuilder.php:80,100`, `CompilerCache.php:259–737,792` | Hard-coded 8.4 signature files alongside existing target checks; supplemental host version tracked separately. Hash selected signature/dependency/emitter identities; keep core/supplemental separation and corruption-as-miss behavior. |
| Tools: `tools/build-dependency-index.php:70,74`, `verify-dependency-index.php`, `verify-php-signatures.php` | Hard-coded declaration platform or 8.4 defaults; make selection deliberate and record exact platform identity. |
| Distribution/release: `src/Versioning/ReleaseAssetBuilder.php:54,69`, `ReleaseAssetVerifier.php:124,132`; `tools/verify-distribution.php:136,163,178,238,243`; `tools/release/`, `tools/verify-documentation.php` | Host ^8.4, target 8.4, signature packaging, runtime `PHP_BINARY`. Preserve existing release identity; future support catalog/assets must name combinations only after validation. |
| CI: `.github/workflows/php.yml:28,83,105,123`, release workflow | Existing 8.4/8.5 matrix is **host** coverage. Add syntax/signature/emission/runtime axes and explicit older-host/newer-target job; consumer/distribution/lowest rows currently use 8.4. |
| Historical evidence: `src/Analysis/Parity/AnalyzerParityRunner.php`, `tests/Fixtures/`, examples, `tools/benchmark-compiler.php`, browser/web spike | Old 8.4 golden/config/runtime pins remain historical evidence, not accidental restrictions to rewrite. Add new fixtures instead. Distinguish unrelated package version numbers such as JavaScript `8.5.x` from PHP assumptions. |

The first live framework failures also require a measured declaration-context scalability slice: Laravel OOMs before planning at 128 MiB and 1 GiB; Assegai OOMs at 128 MiB. At 1 GiB, Assegai's initial P6006 was a sandbox artifact: PHPStan's localhost worker socket was not permitted. An unrestricted retry completes analysis and reports 27 actual template/view typing errors. Profile discovery, retained source/tokens/ASTs, limits and child-process memory/output before changing resource policy; these observations do not establish a precise memory root cause. Do not raise limits indefinitely or suppress diagnostics.

## Layout And Lifecycle Experiments

`FrameworkLayoutCharacterizationTest.php` uses real production loader/source/resolver/planner contracts: flattening collision diagnosed before emission, extensionless configured file root rejected, production 8.5 target still rejected. These tests explicitly name current limitations, not desired long-term behavior.

`LayoutPlanner.php` plans but does not materialize output. Fixture plan: 17 unique entries, including distinct bootstrap/config basenames, compiled classes/tests, native PHP and config/language PHP, opaque Blade/Tempest templates, CSS and TypeScript input, extensionless PHP launcher and two empty runtime directories. Secrets/state are synthetic. Source bytes are hashed and untouched; the hash proposal changes with resource content. Reversed input order yields identical entries/identities.

Tests cover empty/nested mounts and slash normalization; file/directory mismatch; traversal/absolute/drive/metadata paths; case-folded output conflicts; overlapping ownership; symlink roots/nested escapes; resource-rule smuggling and broad PHP rules; co-located classification and relative view planning; cache/log/session exclusion; extensionless non-PHP resources; runtime/file collision. Case folding mirrors `OutputPlanner`'s destination reservation on all hosts (not just `Path`'s drive-path comparison rule).

`GenerationSelection.php` is an in-memory lifecycle model: old selection survives failed preparation, retry succeeds, stale owned paths are listed, clean leaves an external synthetic session file untouched. **Not proved:** physical atomic publication, persistent-state preservation inside replaced output, crash recovery, filesystem races, resource permissions, discovery cache relocation or process reload. Existing production transaction tests remain unchanged; FI-1 must integrate resources into them rather than adopt this toy selector as a writer.

## Live Framework Commands

Disposable root `D=/private/tmp/ppphp-fi0-probes-XgVU6h`. Compiler entrypoint `C=/Users/andrewmasiye/Development/atatusoft/external/languages/ppphp/ppphp-src/bin/ppphp`. All `php` below resolved to the actual PHP 8.5.6 executable recorded above. Dependency downloads initially failed sandbox DNS (Composer exit 100); network-authorized retry succeeded. No package trees or generated secrets are committed.

Envelope for these rows: compiler revision above; production parser/signatures/emission 8.4/8.4.23.2/8.4 when invoking C; native framework baselines have **no compiler emission**. Framework runtime is PHP 8.5.6 with default CLI extensions including PDO/sqlite, curl, mbstring, intl, DOM/XML; OpenSwoole absent. Laravel lock `7d1b0ada9d462e0bf55a5860cf8a024969112a2e9bd308d4719ce3972571f9ac`; Assegai app lock `f720897e4567ed5cc91461a227d9a524ac903c0e8c82314dc9d06f5c938fd4c9`. Commands in each group use its stated working directory. Baseline framework locks, not compiler signatures, govern native execution.

### AssegaiPHP

Source clones were read-only at the pinned Core/Console commits in the matrix. A separate runner installed Console 0.10.3 using `composer require assegaiphp/console:0.10.3 --no-interaction`; exit 0. From `D/assegai-runner`:

| Command | Exit / result |
| --- | --- |
| `php vendor/bin/assegai new fi0-app --skip-git --no-interaction` | 0 PASS; copied template, replaced namespace with `Assegaiphp\Fi0App`, installed Core 0.10.1 and ORM 0.10.2. Noninteractive defaults generated synthetic DB config; no migration against a real DB was run. |

From `D/assegai-runner/fi0-app`:

| Command | Exit / result |
| --- | --- |
| `php ../vendor/bin/assegai generate service probe --no-interaction` | 0 PASS; authored service and AppModule update |
| `php /usr/local/bin/composer test` | 127 FAIL baseline; template script references missing `vendor/bin/pest` |
| `php ../vendor/bin/assegai queue:list --no-interaction` | 0 PASS baseline, no configured queues; not worker-delivery proof |
| `php ../vendor/bin/assegai api:export openapi --output=fi0-openapi.json --no-interaction` | 0 PASS, exported app metadata |
| `php ../vendor/bin/assegai migration:up --help` | 0 help inspection only; DB migration execution NOT RUN (no isolated DB configured) |
| `php ../vendor/bin/assegai wc:watch --no-interaction` | 0, no Web Components found; **actual watcher/rebuild NOT RUN** |
| `php ../vendor/bin/assegai serve --runtime=openswoole --no-interaction` | 1 expected rejection PASS, exact missing-openswoole-extension message; actual worker/reload NOT RUN |
| `php -S 127.0.0.1:55089 -t D/assegai-runner/fi0-app/public D/assegai-runner/fi0-app/index.php` | HTTP `200 OK`, 7466 bytes; bounded probe stopped its own server. Baseline HTTP PASS; not compiled output. |
| `php C check --format=json` | 255 FAIL, 128 MiB OOM in dependency context |
| `php -d memory_limit=1G C check --format=json --debug` | Initial sandbox run: 1 / P6006, empty backend result. Unrestricted retry: 1, **27 actual errors**, including P2099 mixed interpolation/offsets and undefined view variables, P2015 argument types. No suppressions or baseline added. |

Integration points from inspected source: `WorkspaceManager::init/updateNamespace` and `ProjectTemplateDefaults` own language/template selection; `Generate` owns prepare/build/finalize and source destination; custom/package schematic loaders expose extension seams. `Serve::resolveProjectRoot/buildServeCommand` chooses root/document root and `ASSEGAI_WORKING_DIR`; PHP server uses `php` from PATH while OpenSwoole/WC child paths use `PHP_BINARY`, requiring explicit platform coordination. `WatchWebComponents` owns esbuild/hot-reload state. `WorkspaceApiBridge` and `WorkspaceQueueBridge` execute project autoload/config and must select the ready runtime root. Core OpenSwoole runtime has workerStart/workerExit/app shutdown/request hooks; reload safety not proved without the extension. No Core rewrite needed or performed.

### Laravel

From D: `env COMPOSER_CACHE_DIR=D/composer-cache php /usr/local/bin/composer create-project --no-interaction laravel/laravel laravel-probe '^13.0'`; exit 0. Skeleton 13.10.1, framework 13.30.1, 109 locked packages. From `D/laravel-probe`:

| Commands (each executed) | Exit / result |
| --- | --- |
| `php artisan --version`; `php artisan package:discover`; `php artisan make:controller ProbeController --no-interaction` | All 0 PASS |
| `php artisan test` | 0 PASS, 2 tests / 2 assertions (includes baseline HTTP test) |
| Creation's `php artisan migrate --graceful --ansi`; `php artisan migrate:status` | 0 PASS, three migrations in isolated SQLite |
| `php artisan queue:work --once --stop-when-empty --no-interaction` | 0 PASS empty-queue baseline, no job-delivery claim |
| `php artisan route:cache`; `php artisan config:cache`; `php artisan view:cache`; `php artisan optimize:clear` | All 0 PASS |
| `PHP84 /usr/local/bin/composer check-platform-reqs --lock`; same with PHP85 | Both 0 PASS for the actual installed lock |
| `php /usr/local/bin/composer dump-autoload --optimize --classmap-authoritative`; `php artisan test` | Both 0 PASS, 6847 classes; tests still 2/2 |
| `php C build --format=json`; retry `php -d memory_limit=1G C build --format=json` | Both 255 FAIL, dependency parsing OOM at 128 MiB / 1 GiB |
| Generated-output source-free execution; normalized enhanced Larastan comparison | NOT RUN: no successful compiler build. No manually fabricated output or authored-source fallback substituted. |

Current native `artisan` explicitly requires same-root `vendor/autoload.php` and `bootstrap/app.php`; bootstrap resolves routes relative to itself and application base via `dirname(__DIR__)`. Native copying deeper without vendor/resource layout is invalid even if application classes compile. Mounted planning alone cannot claim relocation. Composer post-autoload invokes package discovery, so projecting absent application classes before first build can break installation. Generation uses application paths/default namespace; source-aware destinations need an adapter contract. The three command/recovery models are compared in the [feasibility document](framework-integration-2026.4.md#bootstrap-recovery-and-state); argument/signal/container and recovery implementations remain untested.

Larastan `bootstrap.php`, inspected at [3b73cd5](https://github.com/larastan/larastan/blob/3b73cd5a978b5edfcdc2418091951956e4830c03/bootstrap.php), requires app bootstrap and boots the console kernel. This confirms enhanced analysis is application execution, not portable metadata. An actual normalized-output comparison is NOT RUN because compilation is blocked; do not silently integrate it into checks.

## Decisions

All decisions concern feasibility, not public API acceptance. BC = backward compatibility. Next slices refer to the [FI-1 prompt](framework-integration-fi1-codex-prompt.md).

| Capability / decision | Evidence and tradeoff | Security / BC | Cost / next slice |
| --- | --- | --- | --- |
| Shared platform selection — GO WITH LIMITS | Real dual-host specimen matrix; incomplete feature/signature coverage | Fail closed, no host reflection; preserve 8.4 default/minimum | Medium-high; A–C |
| PHP 8.4/8.5 support work — GO; advertise support — NO-GO | Native matrix works, full 8.5 compiler absent | Reject unsupported native/vendor requirements; unchanged released claims | High; A–D and full matrix |
| Explicit lint/runtime — GO | Older host successfully launches selected newer runtime | Validate executable identity; bounded argv process, no implicit PATH substitution | Medium; D |
| Cross-target evidence identity — GO WITH LIMITS | Input-change identity tests, existing target guards | No stale evidence authority; versioned format migration not yet proved | Medium; E |
| Future PHP extension process — GO | Shared registry + unknown-version rejection | No wildcard support; evidence before publication | Recurring medium; A–E, reviewed release checklist |
| Mounted directories — GO WITH LIMITS | Real collision and deterministic noncolliding candidate | Containment/ownership; old string roots retain empty mount | Medium; F |
| File/extensionless roots — GO WITH LIMITS | Artisan rejected today; candidate file classification works | No arbitrary executable hooks; preserve native bytes and launcher modes | Medium; F |
| Opaque resources — GO WITH LIMITS | Specific template/hash tests, PHP smuggling rejection | Explicit ownership and exclusions; not broad `*.php` ignores | Medium; F–G |
| Specific template suffixes — GO | Co-located view/Blade cases; config remains PHP | Template execution remains framework-owned; no ++PHP inside views | Low-medium; F |
| Empty runtime directories — GO WITH LIMITS | Planned identities/collision tests only | Never treat populated live state as disposable; no silent clean deletion | High; G |
| External persistent state — GO WITH LIMITS | In-memory selection/external-session test; framework storage seams | Configuration/link/permissions and final-path cache validity unproved | High; G and adapter fixtures |
| Declarative framework profiles — GO WITH LIMITS | Ten distinct layout/platform/command models | Data only, explicit version validation; no implicit executable config | Medium; H |
| Automatic framework activation — NO-GO | Composer metadata can suggest but cannot establish chosen app roots/version | No auto-bootstrap or rewriting user config; opt-in suggestions only | Low for suggestions; H |
| Watch/build events — GO WITH LIMITS | Existing separate WC watcher, in-memory preparation failure model | Commit then prepare/select; never execute hooks in portable checking | High; H + FI-2/FI-3 reload evidence |
| Root proxy — GO WITH LIMITS; standalone recovery — GO | Inspected first-build bootstrap cycle | Preserve argv/exit/signals; recovery must not require application boot | Medium-high; H + adapter command tests |
| Mandatory new Composer plugin — NO-GO | Ordinary packages/native seams exist; no contrary evidence | Avoid implicit install-time execution; no approved policy reversal | Avoided cost; explicit adapter install commands |
| Portable framework stubs — GO WITH LIMITS | Existing data-only declaration model; framework dynamic surfaces identified | Review provenance/version; no bootstrap/global ignores | Recurring medium; adapter-specific fixtures |
| Explicit enhanced analysis — GO WITH LIMITS | Larastan demonstrably boots application | Explicit trust/environment/resource contract, source mapping; default unchanged | High; FI-3 after successful normalized output |

ADR inventory contained 0001–0004; next available number is **0005**. No new accepted ADR is added: layout API, physical state lifecycle, proxy behavior and full platform production compatibility remain open, while portable isolation is already governed by existing ADRs. Do not duplicate them or label a prototype an accepted public contract.

## Repository Validation

Focused tests: `/opt/homebrew/opt/php@8.4/bin/php vendor/bin/pest tests/Unit/Compiler/FrameworkLayoutCharacterizationTest.php tests/Unit/Compiler/FrameworkLayoutPrototypeTest.php tests/Unit/Compiler/FrameworkPlatformPrototypeTest.php --compact --colors=never` — PASS, 32 tests / 120 assertions. The platform harness additionally runs its focused tests under both actual hosts.

| Repository check | Result |
| --- | --- |
| `composer validate --strict` | PASS |
| `composer verify:version` | PASS; `2026.3.1-rc-2` unchanged |
| `composer analyse` | PASS |
| `composer test -- --compact --colors=never` | PASS; 811 tests / 5,466 assertions, 625.56 seconds |
| `php -d memory_limit=512M vendor/bin/phpstan analyse --no-progress --level=6 tests/Support/FrameworkIntegrationSpike` | PASS; supplemental analysis of the test-only prototype/harness |
| `composer verify:mixed-application` | PASS |
| `composer verify:distribution` | PASS |
| `composer verify:documentation` | PASS; 21 public, 18 maintainer and 25 technical documentation entries |
| Relative Markdown links in the four spike documents, programme inputs and canonical-plan addition | PASS; local targets and FI-1 required repository inputs verified |
| `git diff --cached --check` | PASS |

The initial sandboxed aggregate run failed (100 failures / 710 passes) because PHPStan workers could not open their local TCP socket (`tcp://127.0.0.1:0`, EPERM). The initial mixed-application run had the same restriction. A direct worker check reproduced it; rerunning with local process/socket access produced the passing results above. No suppression, baseline or existing assertion was changed. Live-framework failures above are independent of the repository's test-suite outcome and remain open implementation gates.
