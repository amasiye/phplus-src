# ++PHP browser runtime spike

This spike tests whether the production ++PHP compiler can provide Check, Build PHP, and Run entirely inside a browser worker. It uses the real Composer-installed compiler, PHP 8.4 WebAssembly, and a versioned compiler archive generated from this repository.

## Outcome

The browser can start PHP, load the production compiler, parse `.ppphp` source, and terminate runaway user code. Full checking is blocked at the PHPStan subprocess boundary. Build PHP and Run correctly remain unavailable after that failure.

The drain-ordering hypothesis was not sufficient. The spike-local adapter starts draining stdout and stderr before awaiting the child exit, but the nested PHP-WASM response rejects while awaiting `exitCode`, before either stream produces a chunk. The compiler then reports `P6006 Static Analysis Result Is Invalid`, as it should.

Browser-only execution is therefore not ready to replace isolated server workers. No PHPStan bypass, empty-result fallback, or compiler weakening was introduced.

| Capability | Native control | Browser result |
| --- | --- | --- |
| Start PHP | Pass | Pass: PHP 8.4.23, `wasm` SAPI |
| Load the production ++PHP CLI | Pass | Pass: `ppphp development`, exit 0 |
| Parse `.ppphp` source | Pass | Pass: AST includes `WhenExpression` |
| Verify compiler bundle integrity | Not applicable | Pass: SHA-256 checked before extraction |
| Run full `ppphp check` | Pass | Blocked at nested PHPStan response |
| Run full `ppphp build` | Pass | Correctly blocked after failed analysis |
| Execute generated PHP | Pass: `Order total: 240` | Not reached |
| Stop runaway user code | Not applicable | Pass: worker terminated by the host |

## Drain-aware adapter

`src/drain-aware-spawn-handler.js` is a spike-local copy of the upstream sandbox adapter behavior. It preserves command allowlisting, shell command splitting, working-directory and environment forwarding, child-runtime isolation, and child reaping.

For PHP subprocesses it:

1. connects stdout and stderr destinations;
2. awaits the subprocess exit response;
3. awaits both stream drains;
4. exposes the exit code to `proc_open()` only after the drains complete.

Failures are explicit. A failed exit response or stream drain is written to stderr, exits with status 1, and is rethrown. The adapter also records its phase, exit code, stream chunk counts, stream byte counts, drain completion, and any transport error.

The spike directly imports the public `createSpawnHandler` and `splitShellCommand` APIs from `@php-wasm/util`, so that package is declared and pinned at `3.1.52` rather than relied on as an undeclared transitive dependency. No files in `node_modules` or `vendor` are modified.

## Exact browser blocker

The real browser run reached PHPStan with this command:

```text
php /opt/ppphp/vendor/phpstan/phpstan/phpstan analyse \
  --configuration=/workspace/.cache/analysis/phpstan.neon \
  --error-format=json \
  --no-progress \
  --memory-limit=1G \
  --debug
```

The adapter recorded:

```text
phase: await-exit
exit code: unavailable
stdout: 0 chunks, 0 bytes
stderr: 0 chunks, 0 bytes
drains completed: false
error: null function
```

This is deeper than an exit-before-drain race. Both drains were connected first, but the remote child response failed before an exit code or output became available. The compiler converted that incomplete analysis into `P6006` and refused to emit PHP. The upstream ordering problem tracked in [wordpress-playground#4166](https://github.com/WordPress/wordpress-playground/issues/4166) is avoided by the adapter, but the nested subprocess transport remains unusable for this workload.

The stop rule applies here. The spike does not implement a broader compiler protocol without explicit approval.

## Native control

The corrected positive fixture uses a `when` expression for branch-local preprocessing:

```php
<?php

function summarizeOrders(array<int> $orders, string $emptySummary): string
{
    return when ($orders !== []) {
        int $total = 0;
        foreach ($orders as int $amount) {
            $total += $amount;
        }
        return 'Order total: ' . $total;
    } else {
        return $emptySummary;
    };
}

array<int> $orders = [120, 80, 40];
string $summary = summarizeOrders($orders, 'No orders');
echo $summary . "\n";
```

The native control ran the production CLI against the same source shape:

```shell
php bin/ppphp check \
  --working-directory=/private/tmp/ppphp-web-spike-native \
  --format=json --debug --no-interaction --no-ansi

php bin/ppphp build \
  --working-directory=/private/tmp/ppphp-web-spike-native \
  --format=json --debug --no-interaction --no-ansi

php /private/tmp/ppphp-web-spike-native/build/main.php
```

Check and Build PHP returned no diagnostics. The generated program printed exactly:

```text
Order total: 240
```

An earlier fixture also demonstrated why analysis cannot be skipped. Native PHPStan correctly reported `P2099` for an invalid PHPDoc/native-type combination, while the browser transport could return only `P6006` because no PHPStan output crossed the subprocess boundary.

## Browser reproduction

From `tools/web-spike`:

```shell
npm install
npm run build
npm run preview -- --port 4173
```

Open `http://127.0.0.1:4173/` and run the probe. The recorded run used the Codex in-app Chromium browser on macOS on 2026-08-31. The browser version was not exposed by the test surface.

The page reports runtime capabilities, compiler version, AST parsing, Check, Build PHP, Run, subprocess observations, and runaway-worker termination. The probe requires real PHP-WASM execution. It has no mock success path.

## Measurements

These measurements are local development observations, not production network benchmarks:

| Measurement | Observed value |
| --- | ---: |
| Versioned compiler archive | 14,122,197 bytes |
| PHP runtime variants | 19,310,275 and 19,943,873 bytes raw |
| PHP runtime variants, Vite gzip estimate | about 7.60 and 7.78 MB |
| Current unoptimized `dist` allocation | 80,968 KiB |
| AST parse | 106 ms |
| Check through failed PHPStan response | 815 ms |
| Derived cold path before parse and check | about 1,064 ms |
| Total probe through failed Check | 1,985 ms |
| Infinite-loop termination | about 300 ms with a 250 ms host timeout |

The build currently emits both JSPI and Asyncify PHP variants, both `intl` artifacts, and two copies of the compiler archive. A production integration must choose one runtime path and avoid duplicate generated assets. The current payload is suitable for feasibility testing, not deployment budgeting.

Vite also reports upstream PHP-WASM uses of direct `eval` and browser externalization of Node's `worker_threads` and `events` modules. The spike does not modify installed packages to hide those warnings. A production integration needs a deliberate Content Security Policy and must verify that its selected runtime path does not depend on an unavailable externalized API.

## Validation status

| Test | Result |
| --- | --- |
| Real compiler version in browser | Pass |
| Real AST parse in browser | Pass |
| Incomplete analysis cannot become success | Pass |
| Build cannot follow failed analysis | Pass |
| Host terminates infinite user code | Pass |
| Native positive Check, Build PHP, and Run | Pass |
| Browser positive Check, Build PHP, and Run | Blocked |
| Browser delivery of a genuine PHPStan finding | Blocked |
| Browser syntax and compiler-owned semantic parity matrix | Not continued after the failed analysis gate |
| Browser multi-chunk stdout and stderr stress | Not continued after the failed analysis gate |
| Browser large-output cap and post-timeout recovery matrix | Not continued after the failed analysis gate |
| Emitted PHP and runtime-output parity | Not reachable |

Stopping at the first failed production gate avoids collecting misleading downstream results from a runtime that cannot complete static analysis.

## Proposed next protocol

If browser-only compilation remains a goal, the next experiment should move the PHPStan execution boundary into an explicit three-phase compiler protocol:

1. **Prepare Analysis**: parse and validate compiler-owned rules, materialize PHPStan configuration and inputs, and return an opaque continuation identifier.
2. **Run PHPStan**: invoke PHPStan as a top-level browser PHP execution, not through nested `proc_open()`, and capture its complete JSON result.
3. **Complete Analysis**: validate the continuation and PHPStan result, map diagnostics, and permit emission only after successful analysis.

This protocol must preserve the same compiler-owned orchestration and diagnostic contracts as native execution. It must not expose a way to submit fabricated analysis success. It was not implemented in this spike because it changes the compiler API and requires explicit approval.

## Recommendation

Use isolated server-side workers for the first public Playground and Learn release. Keep the browser editor and lesson UI in the existing website, and send only sandbox operations to the worker service.

The browser-only path remains attractive for future cost reduction, and worker termination provides a useful client-side limit for runaway code. Today, however, the production compiler cannot complete its mandatory PHPStan phase through the nested PHP-WASM subprocess transport. The three-phase protocol is the next credible browser experiment.

## Repository verification

The final spike state was checked with:

```shell
cd tools/web-spike
npm install
npm run build

cd ../..
composer validate --strict
composer analyse
composer test
```

`npm install` reported no vulnerabilities. The Vite build completed with the upstream warnings recorded above. Composer validation and PHPStan analysis passed. The test suite passed 492 tests with 3,204 assertions.

## Scope

This is an isolated feasibility spike. It does not modify the website, alter native compiler behavior, edit installed dependencies, weaken diagnostics, or change the production hosting plan.
