import {
  PHP,
  PHPWorker,
  exposeAPI,
  loadPHPRuntime,
} from '@php-wasm/universal';
import { getPHPLoaderModule } from '@php-wasm/web-8-4';

const compilerRoot = '/opt/ppphp';
const api = new PHPWorker();
const [setReady, setFailed] = exposeAPI(api);

async function readResponse(response) {
  const [stdout, stderr, exitCode] = await Promise.all([
    response.stdoutText,
    response.stderrText,
    response.exitCode,
  ]);

  return { stdout, stderr, exitCode };
}

async function boot() {
  const loader = await getPHPLoaderModule();
  const php = new PHP(await loadPHPRuntime(loader));
  const manifestResponse = await fetch('/generated/compiler.json');

  if (!manifestResponse.ok) {
    throw new Error('The compiler manifest could not be loaded in the subprocess worker.');
  }

  const manifest = await manifestResponse.json();
  const archiveResponse = await fetch(`/generated/${manifest.archive}`);

  if (!archiveResponse.ok) {
    throw new Error('The compiler bundle could not be loaded in the subprocess worker.');
  }

  php.mkdir(compilerRoot);
  php.writeFile('/tmp/compiler.tar.gz', new Uint8Array(await archiveResponse.arrayBuffer()));
  const extraction = await readResponse(await php.runStream({
    code: `<?php
$archive = new PharData('/tmp/compiler.tar.gz');
$archive->extractTo('${compilerRoot}', null, true);
`,
  }));

  if (extraction.exitCode !== 0) {
    throw new Error(`Subprocess compiler extraction failed.\n${extraction.stderr}`);
  }

  await api.setPrimaryPHP(php);
  setReady();
}

boot().catch(setFailed);
