# ++PHP browser runtime spike

This spike tests whether the production ++PHP compiler can provide Check, Build PHP, and Run entirely inside browser workers. It uses the real Composer-installed compiler, PHP 8.4 WebAssembly, and a versioned compiler archive generated from this repository.

## Outcome

Prepare Analysis works in the browser. The compiler loads the normal project, parses PHP and ++PHP sources, runs compiler-owned semantic analysis, materializes the normal analysis workspace and pinned PHPStan configuration, and returns a content-addressed continuation.

The first stop gate fails when PHPStan runs as a top-level PHP-WASM command. PHPStan starts without a spawn handler or nested subprocess adapter, but PHP-WASM aborts in `_getcontext` before the top-level response can provide stdout, stderr, or an exit code. No PHPStan JSON is returned.

The gate therefore stops the experiment before Complete Analysis, Build PHP, generated-PHP Run, or website integration. No PHPStan bypass, alternate browser checker, fabricated success result, or weaker compiler rule was added.

| Capability | Result |
| --- | --- |
| Start PHP-WASM | Pass: PHP 8.4.23, `wasm` SAPI |
| Load the production ++PHP CLI | Pass |
| Parse real `.ppphp` source | Pass |
| Verify compiler archive integrity | Pass: SHA-256 checked before extraction |
| Prepare Analysis | Pass: 3-file workspace manifest and content-addressed continuation |
| Run PHPStan as a top-level command | Fail: PHP-WASM aborts in `_getcontext` |
| Receive complete PHPStan JSON | Fail: no stdout, stderr, or exit code completes |
| Complete Analysis | Not implemented because the first stop gate failed |
| Build PHP | Not implemented because analysis cannot complete |
| Run generated PHP | Not implemented because no validated build exists |
| Stop runaway user code | Pass from the preceding spike: terminate the disposable worker |

Browser-only compilation is a no-go with this PHP-WASM runtime. Isolated server-side workers remain required for the first public Playground and Learn release.

## Prepare Analysis protocol

The compiler owns the version 1 Prepare Analysis protocol. Website code does not reproduce compiler orchestration.

A request is stored in the browser virtual project and passed to the hidden compiler command:

```json
{
  "version": 1,
  "requestId": "5f229cf0-b144-4a98-a3d4-910dcf3bbf59",
  "action": "prepare",
  "operation": "check",
  "selection": {
    "path": null
  }
}
```

```text
php /opt/ppphp/bin/ppphp browser:analysis \
  /workspace/browser-analysis-request.json \
  --working-directory=/workspace \
  --no-interaction \
  --no-ansi
```

The response contains:

- protocol version, request identifier, action, and status;
- normal compiler diagnostics;
- the exact top-level PHPStan command, working directory, and result path; and
- an `AnalysisContinuation` when compiler-owned preparation succeeds.

The continuation binds:

- protocol version and Prepare request identifier;
- requested Check or Build operation and selected path;
- compiler name, version, and lowering format version;
- every project source path and SHA-256 hash;
- the effective project configuration hash;
- the selected source set;
- every prepared workspace file path, size, and SHA-256 hash;
- the generated PHPStan configuration hash; and
- the expected result path, `phpstan-json-v1` format, and 2 MiB size limit.

The continuation hash is deterministic and content-addressed. It is not described as signed because it uses no secret.

Prepare uses the same `ProjectConfigLoader`, `ProjectLoader`, `ProjectSelector`, `ProjectSyntaxChecker`, semantic analysis, `AnalysisWorkspacePreparer`, `PhpStanConfigBuilder`, and diagnostic processing as native Check. The native path still invokes PHPStan through the existing isolated Symfony Process backend.

Requests larger than 16 KiB, unsupported versions, malformed fields, invalid operations, and project-external request paths are rejected. Syntax and compiler-owned semantic errors return normal diagnostic payloads without a continuation or PHPStan command.

## Top-level PHPStan gate

PHP-WASM reports an empty `PHP_BINARY`, so the browser plan deliberately selects the public CLI token `php`. Native plans continue using `PHP_BINARY`.

The exact browser command was:

```text
php /opt/ppphp/vendor/phpstan/phpstan/phpstan analyse \
  --configuration=/workspace/.cache/analysis/phpstan.neon \
  --error-format=json \
  --no-progress \
  --memory-limit=1G \
  --debug
```

The command was passed directly to `PHP.cli()` in a fresh top-level runtime. No spawn handler was installed. Fixed `COLUMNS` and `LINES` values prevented terminal-size probes from confusing the result.

The real Chromium run failed with:

```text
RuntimeError: unreachable
    at abort
    at _getcontext
```

The top-level response promise rejected before its stdout, stderr, or exit code could complete. The final clean reproduction installed no spawn handler and attempted no subprocess. This distinguishes the blocker from the earlier nested `proc_open()` transport failure.

Because complete JSON is mandatory input to `PhpStanResultParser`, the experiment cannot safely infer success or continue. The requested stop rule applies.

## Browser reproduction

From `tools/web-spike`:

```shell
npm install
npm run build
npm run preview -- --port 4173
```

Open `http://127.0.0.1:4173/`. The page starts the compiler probe automatically.

The recorded run used Codex in-app Chromium on macOS on 2026-09-01:

```text
Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7)
AppleWebKit/537.36 (KHTML, like Gecko)
Chrome/151.0.0.0 Safari/537.36
```

PHP reported:

```text
version: 8.4.23
SAPI: wasm
PharData: available
proc_open: compiled in, with no spawn handler installed for the gate
zlib: available
```

## Measurements

These are local development observations, not production network benchmarks.

| Measurement | Observed value |
| --- | ---: |
| Compiler archive | 14,130,113 bytes |
| Compiler archive transfer | 14,130,413 bytes |
| Primary PHP-WASM binary | 19,310,275 bytes |
| Primary PHP-WASM transfer | 19,310,575 bytes |
| Primary runtime JavaScript | 42,672 bytes encoded, 140,578 bytes decoded |
| Prepare Analysis | 114 ms |
| Cold path to top-level PHPStan start | 1,166 ms |
| PHPStan start to `_getcontext` abort | 892 ms |
| Total path to gate failure | 2,058 ms |
| Prepared workspace | 3 files |

The Vite output still contains both Asyncify and JSPI runtime variants and both `intl` artifacts. The current distribution is a feasibility probe, not a production payload. Repeated PHP runtimes reuse the browser cache, so later WASM resource entries transferred only response overhead while retaining the same 19,310,275-byte encoded body.

Precise process-memory usage was not exposed by the worker/browser surface. The spike does not invent an estimate.

## Runtime and CSP warnings

Vite reports direct `eval` usage inside the upstream PHP-WASM packages. It also externalizes Node `worker_threads` and `events` imports for browser compatibility. No installed dependency was edited to suppress these warnings.

A production browser runtime would need a deliberate Content Security Policy, one selected PHP-WASM runtime variant, deduplicated compiler assets, and verified compatibility with the selected deployment headers. Those optimizations cannot solve the `_getcontext` failure proven here.

## Validation scope

The implemented Prepare tests cover:

- unsupported protocol versions and malformed actions;
- deterministic continuation hashes;
- changed continuation content with a stale hash;
- normal workspace and PHPStan configuration materialization;
- browser `php` selection without changing the native `PHP_BINARY` plan;
- syntax failure before PHPStan; and
- compiler-owned semantic failure before PHPStan.

The requested malformed, empty, truncated, stale, changed-source, changed-configuration, changed-compiler, mismatched-PHPStan-configuration, failed-exit, timeout, recovery, diagnostic-parity, emitted-PHP, and generated-runtime cases belong to Complete Analysis and later phases. They were intentionally not implemented after the first gate failed.

## Recommendation

Keep Playground and Learn client code in the website, but execute Check, Build PHP, and Run through isolated server-side workers for the public release. The browser worker can still enforce client-side wall-clock cancellation and response limits, but it cannot replace the trusted analysis service while the production PHPStan runtime aborts before returning JSON.

Revisit browser-only compilation only after the selected PHP-WASM runtime can execute the pinned PHPStan build without `_getcontext`, or after an upstream PHPStan/PHP-WASM combination provides an equivalent top-level execution path. Any future retry must begin at the same JSON gate before implementing completion or emission.

## Repository verification

The final spike state is checked with:

```shell
cd tools/web-spike
npm install
npm run build

cd ../..
composer validate --strict
composer analyse
composer test
```

The final run passed `composer validate --strict`, `composer analyse`, and all 500 tests with 3,277 assertions. `npm install` reported no vulnerabilities, and `npm run build` completed with only the documented upstream PHP-WASM warnings.

The browser gate must also be run in real Chromium as described above. Generated `dist`, compiler archives, runtime binaries, `vendor`, and `node_modules` are not part of this change.

## Scope

This remains an isolated compiler feasibility spike. It does not modify the website, alter native compiler isolation, weaken diagnostics, edit installed dependencies, change language semantics, or change production availability.
