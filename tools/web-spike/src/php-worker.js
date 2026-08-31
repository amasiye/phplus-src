import {
  consumeAPI,
  PHP,
  loadPHPRuntime,
  proxyFileSystem,
} from '@php-wasm/universal';
import { getPHPLoaderModule } from '@php-wasm/web-8-4';
import { drainAwareSandboxedSpawnHandlerFactory } from './drain-aware-spawn-handler.js';

const compilerRoot = '/opt/ppphp';
const workspaceRoot = '/workspace';
const sharedPaths = [compilerRoot, workspaceRoot];
const loaderPromise = getPHPLoaderModule();
const spawnedCommands = [];
const subprocessObservations = [];

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

async function copyTree(source, destination, path) {
  if (source.isDir(path)) {
    if (!await destination.fileExists(path)) {
      await destination.mkdir(path);
    }

    for (const name of source.listFiles(path)) {
      if (name !== '.' && name !== '..') {
        await copyTree(source, destination, `${path}/${name}`);
      }
    }

    return;
  }

  await destination.writeFile(path, source.readFileAsBuffer(path));
}

function createBrowserSpawnHandler(host, remoteChild) {
  const handler = drainAwareSandboxedSpawnHandlerFactory(
    remoteChild === null ? undefined : async () => {
      await copyTree(host, remoteChild, workspaceRoot);

      return {
        php: remoteChild,
        reap() {},
      };
    },
    (observation) => subprocessObservations.push(observation),
  );

  return (command, args) => {
    let normalizedCommand = command;

    if (typeof normalizedCommand === 'string') {
      normalizedCommand = normalizedCommand.replace(/^exec "" /, 'exec php ');

      if (normalizedCommand.includes('/vendor/phpstan/phpstan/phpstan')) {
        normalizedCommand += " '--debug'";
      }
    }

    spawnedCommands.push({ command: normalizedCommand, args });
    return handler(normalizedCommand, args);
  };
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

  return manifest;
}

async function createCommandRuntime(host, needsSubprocess) {
  const runtime = await createPHP();
  await proxyFileSystem(host, runtime, sharedPaths);
  let childWorker = null;
  let remoteChild = null;

  if (needsSubprocess) {
    childWorker = new Worker(new URL('./php-child-worker.js', import.meta.url), { type: 'module' });
    remoteChild = consumeAPI(childWorker);
    await remoteChild.isReady();
  }

  await runtime.setSpawnHandler(createBrowserSpawnHandler(host, remoteChild));

  return { runtime, childWorker };
}

async function runCLI(host, argv, cwd = workspaceRoot) {
  const needsSubprocess = argv.some((argument) => argument === 'check' || argument === 'build');
  const { runtime, childWorker } = await createCommandRuntime(host, needsSubprocess);

  try {
    return await readResponse(await runtime.cli(argv, {
      cwd,
      env: {
        HOME: '/tmp',
        NO_COLOR: '1',
        PATH: '/usr/bin:/bin',
        TERM: 'dumb',
      },
    }));
  } finally {
    disposePHP(runtime);
    childWorker?.terminate();
  }
}

async function runCompiler(host, action) {
  report(action, `Running ppphp ${action} with the real compiler.`);
  const startedAt = performance.now();
  const response = await runCLI(host, [
    'php',
    `${compilerRoot}/bin/ppphp`,
    action,
    `--working-directory=${workspaceRoot}`,
    '--format=json',
    '--debug',
    '--no-interaction',
    '--no-ansi',
  ]);

  return {
    ...response,
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
  const runtimeInitializationMs = Math.round(performance.now() - startedAt);
  const compilerPreparationStartedAt = performance.now();
  const manifest = await prepareFilesystem(host);
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
  const check = await runCompiler(host, 'check');
  const build = check.exitCode === 0 ? await runCompiler(host, 'build') : null;

  let program = null;
  if (build?.exitCode === 0 && host.fileExists(`${workspaceRoot}/build/main.php`)) {
    report('run', 'Executing the generated PHP in a fresh WebAssembly runtime.');
    const runStartedAt = performance.now();
    program = {
      ...await runCLI(host, [
        'php',
        '-n',
        '-d',
        'allow_url_fopen=0',
        '-d',
        'allow_url_include=0',
        '-d',
        'display_errors=stderr',
        `${workspaceRoot}/build/main.php`,
      ], `${workspaceRoot}/build`),
      durationMs: Math.round(performance.now() - runStartedAt),
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
    check,
    build,
    program,
    spawnedCommands,
    subprocessObservations,
    totalDurationMs: Math.round(performance.now() - startedAt),
  };

  disposePHP(host);
  self.postMessage({ type: 'ready', result });
}

boot().catch((error) => {
  self.postMessage({
    type: 'error',
    message: error instanceof Error ? `${error.message}\n${error.stack ?? ''}` : String(error),
  });
});
