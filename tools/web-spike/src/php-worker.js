import {
  PHP,
  loadPHPRuntime,
  proxyFileSystem,
} from '@php-wasm/universal';
import { getPHPLoaderModule } from '@php-wasm/web-8-4';

const compilerRoot = '/opt/ppphp';
const workspaceRoot = '/workspace';
const sharedPaths = [compilerRoot, workspaceRoot];
const loaderPromise = getPHPLoaderModule();
const evidence = {
  startedAt: performance.now(),
  userAgent: self.navigator.userAgent,
};

function report(phase, detail) {
  self.postMessage({ type: 'progress', phase, detail });
}

async function createPHP() {
  const loader = await loaderPromise;
  return new PHP(await loadPHPRuntime(loader));
}

function disposePHP(php) {
  try {
    php[Symbol.dispose]();
  } catch {
    // A completed CLI runtime has already shut itself down.
  }
}

async function readResponse(response) {
  const [stdout, stderr, exitCode] = await Promise.all([
    response.stdoutText,
    response.stderrText,
    response.exitCode,
  ]);

  return { stdout, stderr, exitCode };
}

async function verifyArchive(bytes, expectedHash) {
  const digest = await crypto.subtle.digest('SHA-256', bytes);
  const actualHash = Array.from(new Uint8Array(digest), (byte) => byte.toString(16).padStart(2, '0')).join('');

  if (actualHash !== expectedHash) {
    throw new Error(`Compiler archive checksum mismatch: expected ${expectedHash}, received ${actualHash}.`);
  }
}

async function prepareFilesystem(host) {
  report('compiler', 'Downloading the versioned compiler bundle.');
  const manifestResponse = await fetch('/generated/compiler.json');

  if (!manifestResponse.ok) {
    throw new Error('The prepared compiler manifest could not be loaded.');
  }

  const manifest = await manifestResponse.json();
  const archiveResponse = await fetch(`/generated/${manifest.archive}`);

  if (!archiveResponse.ok) {
    throw new Error('The prepared compiler bundle could not be loaded.');
  }

  const archive = await archiveResponse.arrayBuffer();
  await verifyArchive(archive, manifest.sha256);

  host.mkdir(compilerRoot);
  host.mkdir(workspaceRoot);
  host.mkdir(`${workspaceRoot}/src`);
  host.mkdir(`${workspaceRoot}/stubs`);
  host.writeFile('/tmp/compiler.tar.gz', new Uint8Array(archive));

  report('compiler', 'Extracting the compiler into the browser filesystem.');
  const extraction = await readResponse(await host.runStream({
    code: `<?php
$archive = new PharData('/tmp/compiler.tar.gz');
$archive->extractTo('${compilerRoot}', null, true);
echo is_file('${compilerRoot}/bin/ppphp') ? 'ready' : 'missing';
`,
  }));

  if (extraction.exitCode !== 0 || extraction.stdout.trim() !== 'ready') {
    throw new Error(`Compiler extraction failed.\n${extraction.stderr || extraction.stdout}`);
  }

  const configuration = {
    source: ['src'],
    output: 'build',
    cache: '.cache',
    targetPhpVersion: '8.4',
    stubs: ['stubs'],
    exclude: ['build', '.cache'],
  };
  const source = `<?php

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

echo $summary . "\\n";
`;

  host.writeFile(`${workspaceRoot}/ppphp.json`, `${JSON.stringify(configuration, null, 2)}\n`);
  host.writeFile(`${workspaceRoot}/src/main.ppphp`, source);
  host.writeFile(`${workspaceRoot}/src/invalid.ppphp`, `<?php

function invalid(): void
{
    int $value = 'wrong';
}
`);

  return manifest;
}

async function runCompilerAnalysisGate(host) {
  const requestPath = `${workspaceRoot}/compiler-analysis-request.json`;
  const request = {
    version: 2,
    requestId: crypto.randomUUID(),
    action: 'analyze',
    operation: 'check',
    analysis: { engine: 'compiler' },
    selection: { path: null },
  };
  host.writeFile(requestPath, `${JSON.stringify(request)}\n`);

  report('compiler-analysis', 'Running one top-level compiler-only analysis invocation.');
  const startedAt = performance.now();
  const response = await runCLI(host, [
    'php',
    `${compilerRoot}/bin/ppphp`,
    'browser:analysis',
    requestPath,
    `--working-directory=${workspaceRoot}`,
    '--no-interaction',
    '--no-ansi',
  ]);

  if (response.exitCode !== 0) {
    throw new Error(`Compiler-only analysis failed with exit ${response.exitCode}.\n${response.stderr || response.stdout}`);
  }

  let payload;
  try {
    payload = JSON.parse(response.stdout);
  } catch (error) {
    throw new Error(`Compiler-only analysis did not return complete JSON.\n${response.stdout}`, { cause: error });
  }

  const diagnostics = payload.diagnostics?.diagnostics ?? [];
  const codes = diagnostics.map((diagnostic) => diagnostic.code);
  const invalidDiagnostic = diagnostics.find((diagnostic) => diagnostic.code === 'P2008');
  const forbiddenProtocolKeys = ['phpStan', 'continuation', 'command']
    .filter((key) => Object.hasOwn(payload, key));
  const contextFailure = `${response.stdout}\n${response.stderr}`.includes('_getcontext');
  const workspaceCreated = host.fileExists(`${workspaceRoot}/.cache/analysis`)
    || host.fileExists(`${workspaceRoot}/.cache/analysis/phpstan.neon`)
    || host.fileExists(`${workspaceRoot}/.cache/analysis/result.json`);

  if (
    payload.version !== 2
    || payload.status !== 'complete'
    || payload.engine !== 'compiler'
    || payload.completeness !== 'compilerCore'
    || payload.fullParity !== false
    || !Array.isArray(payload.uncoveredRequiredCapabilities)
    || payload.uncoveredRequiredCapabilities.length === 0
    || codes.length !== 1
    || codes[0] !== 'P2008'
    || invalidDiagnostic?.location?.file !== 'src/invalid.ppphp'
    || forbiddenProtocolKeys.length !== 0
    || workspaceCreated
    || contextFailure
  ) {
    throw new Error(`Compiler-only analysis violated its browser contract.\n${response.stdout}\n${response.stderr}`);
  }

  return {
    exitCode: response.exitCode,
    stderr: response.stderr,
    durationMs: Math.round(performance.now() - startedAt),
    topLevelCompilerInvocations: 1,
    spawnHandlerInstalled: false,
    validSource: 'src/main.ppphp',
    invalidSource: invalidDiagnostic.location.file,
    diagnosticCodes: codes,
    completeness: payload.completeness,
    catalogVersion: payload.catalogVersion,
    fullParity: payload.fullParity,
    uncoveredRequiredCapabilities: payload.uncoveredRequiredCapabilities,
    backendWorkspaceCreated: workspaceCreated,
    continuationReturned: Object.hasOwn(payload, 'continuation'),
    phpStanPlanReturned: Object.hasOwn(payload, 'phpStan'),
    getcontextObserved: contextFailure,
  };
}

async function createCommandRuntime(host) {
  const runtime = await createPHP();
  await proxyFileSystem(host, runtime, sharedPaths);

  return runtime;
}

async function runCLI(host, argv, cwd = workspaceRoot) {
  const runtime = await createCommandRuntime(host);

  try {
    return await readResponse(await runtime.cli(argv, {
      cwd,
      env: {
        HOME: '/tmp',
        NO_COLOR: '1',
        PATH: '/usr/bin:/bin',
        TERM: 'dumb',
        COLUMNS: '140',
        LINES: '18',
      },
    }));
  } finally {
    disposePHP(runtime);
  }
}

async function prepareAnalysis(host, operation = 'check') {
  const requestPath = `${workspaceRoot}/browser-analysis-request.json`;
  const request = {
    version: 1,
    requestId: crypto.randomUUID(),
    action: 'prepare',
    operation,
    selection: { path: null },
  };
  host.writeFile(requestPath, `${JSON.stringify(request)}\n`);

  report('prepare', `Preparing browser ${operation} analysis with the real compiler.`);
  const startedAt = performance.now();
  const response = await runCLI(host, [
    'php',
    `${compilerRoot}/bin/ppphp`,
    'browser:analysis',
    requestPath,
    `--working-directory=${workspaceRoot}`,
    '--no-interaction',
    '--no-ansi',
  ]);

  if (response.exitCode !== 0) {
    throw new Error(`Prepare Analysis failed with exit ${response.exitCode}.\n${response.stderr || response.stdout}`);
  }

  let payload;
  try {
    payload = JSON.parse(response.stdout);
  } catch (error) {
    throw new Error(`Prepare Analysis did not return complete JSON.\n${response.stdout}`, { cause: error });
  }

  if (payload.status !== 'prepared' || !payload.continuation || !payload.phpStan) {
    throw new Error(`Prepare Analysis did not produce a continuation.\n${response.stdout}`);
  }

  return {
    ...response,
    payload,
    durationMs: Math.round(performance.now() - startedAt),
  };
}

async function runTopLevelPHPStan(host, prepared) {
  report('phpstan', 'Running PHPStan as a top-level PHP-WASM command.');
  const startedAt = performance.now();
  evidence.phpStan = {
    command: prepared.payload.phpStan.command,
    workingDirectory: prepared.payload.phpStan.workingDirectory,
    startedAtMs: Math.round(startedAt - evidence.startedAt),
  };
  const response = await runCLI(
    host,
    prepared.payload.phpStan.command,
    prepared.payload.phpStan.workingDirectory,
  );
  const stdoutBytes = new TextEncoder().encode(response.stdout).byteLength;
  const stderrBytes = new TextEncoder().encode(response.stderr).byteLength;
  const maximumBytes = prepared.payload.continuation.expectedResult.maximumBytes;

  if (stdoutBytes === 0) {
    throw new Error('Top-level PHPStan returned an empty result.');
  }

  if (stdoutBytes > maximumBytes) {
    throw new Error(`Top-level PHPStan exceeded its ${maximumBytes}-byte result limit.`);
  }

  let result;
  try {
    result = JSON.parse(response.stdout);
  } catch (error) {
    throw new Error(`Top-level PHPStan did not return complete JSON.\n${response.stdout}\n${response.stderr}`, {
      cause: error,
    });
  }

  host.writeFile(prepared.payload.phpStan.resultPath, response.stdout);

  return {
    exitCode: response.exitCode,
    stderr: response.stderr,
    stdoutBytes,
    stderrBytes,
    result,
    durationMs: Math.round(performance.now() - startedAt),
  };
}

async function boot() {
  const startedAt = performance.now();
  report('runtime', 'Starting PHP 8.4 WebAssembly.');
  const host = await createPHP();
  const probe = await readResponse(await host.runStream({
    code: `<?php
echo json_encode([
    'version' => PHP_VERSION,
    'sapi' => PHP_SAPI,
    'phar' => class_exists('PharData'),
    'procOpen' => function_exists('proc_open'),
    'zlib' => extension_loaded('zlib'),
]);
`,
  }));
  const runtime = JSON.parse(probe.stdout);
  evidence.runtime = runtime;
  const runtimeInitializationMs = Math.round(performance.now() - startedAt);
  const compilerPreparationStartedAt = performance.now();
  const manifest = await prepareFilesystem(host);
  evidence.compilerArchive = manifest;
  const compilerPreparationMs = Math.round(performance.now() - compilerPreparationStartedAt);

  report('version', 'Running the packaged ++PHP CLI.');
  const versionStartedAt = performance.now();
  const version = await runCLI(host, [
    'php',
    `${compilerRoot}/bin/ppphp`,
    '--version',
    '--no-ansi',
    '--no-interaction',
  ]);
  const versionDurationMs = Math.round(performance.now() - versionStartedAt);
  const coldInitializationMs = Math.round(performance.now() - startedAt);
  report('parse', 'Parsing the ++PHP fixture with the packaged compiler.');
  const parseStartedAt = performance.now();
  const parseResponse = await runCLI(host, [
    'php',
    `${compilerRoot}/bin/ppphp`,
    'dump:ast',
    'src/main.ppphp',
    `--working-directory=${workspaceRoot}`,
    '--format=json',
    '--no-interaction',
    '--no-ansi',
  ]);
  const parse = {
    exitCode: parseResponse.exitCode,
    stderr: parseResponse.stderr,
    astBytes: new TextEncoder().encode(parseResponse.stdout).byteLength,
    containsWhenExpression: parseResponse.stdout.includes('WhenExpression'),
    durationMs: Math.round(performance.now() - parseStartedAt),
  };
  const compilerAnalysis = await runCompilerAnalysisGate(host);
  evidence.compilerAnalysis = compilerAnalysis;
  host.writeFile(`${workspaceRoot}/src/invalid.ppphp`, `<?php

function corrected(): void {}
`);
  const prepared = await prepareAnalysis(host);
  evidence.prepare = {
    durationMs: prepared.durationMs,
    continuationHash: prepared.payload.continuation.contentHash,
    workspaceFiles: prepared.payload.continuation.workspaceManifest.length,
  };
  let topLevelPHPStan;

  try {
    topLevelPHPStan = {
      status: 'completed',
      result: await runTopLevelPHPStan(host, prepared),
    };
  } catch (error) {
    const message = error instanceof Error ? `${error.message}\n${error.stack ?? ''}` : String(error);

    if (!message.includes('_getcontext')) {
      throw error;
    }

    topLevelPHPStan = {
      status: 'expectedFailure',
      blocker: '_getcontext',
      message,
    };
  }

  const result = {
    environment: {
      userAgent: self.navigator.userAgent,
      resources: performance.getEntriesByType('resource').map((entry) => ({
        name: entry.name,
        transferSize: entry.transferSize,
        encodedBodySize: entry.encodedBodySize,
        decodedBodySize: entry.decodedBodySize,
        durationMs: Math.round(entry.duration),
      })),
    },
    runtime,
    compiler: {
      archiveBytes: manifest.bytes,
      archiveSha256: manifest.sha256,
      version: version.stdout.trim(),
      versionExitCode: version.exitCode,
    },
    timings: {
      runtimeInitializationMs,
      compilerPreparationMs,
      versionDurationMs,
      coldInitializationMs,
    },
    parse,
    compilerAnalysis,
    prepared: {
      exitCode: prepared.exitCode,
      stderr: prepared.stderr,
      durationMs: prepared.durationMs,
      continuation: prepared.payload.continuation,
      phpStan: prepared.payload.phpStan,
    },
    topLevelPHPStan,
    totalDurationMs: Math.round(performance.now() - startedAt),
  };

  disposePHP(host);
  self.postMessage({ type: 'ready', result });
}

boot().catch((error) => {
  evidence.failedAtMs = Math.round(performance.now() - evidence.startedAt);
  evidence.resources = performance.getEntriesByType('resource').map((entry) => ({
    name: entry.name,
    transferSize: entry.transferSize,
    encodedBodySize: entry.encodedBodySize,
    decodedBodySize: entry.decodedBodySize,
    durationMs: Math.round(entry.duration),
  }));
  self.postMessage({
    type: 'error',
    message: `${error instanceof Error ? `${error.message}\n${error.stack ?? ''}` : String(error)}\n${JSON.stringify(evidence, null, 2)}`,
  });
});
