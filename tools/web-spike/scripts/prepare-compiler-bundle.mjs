import { createHash } from 'node:crypto';
import { mkdirSync, readFileSync, writeFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { spawnSync } from 'node:child_process';

const spikeRoot = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const compilerRoot = resolve(spikeRoot, '../..');
const outputDirectory = resolve(spikeRoot, 'public/generated');
// The .bin suffix prevents static servers from adding Content-Encoding: gzip.
// Browsers transparently decode encoded responses, which would invalidate the
// checksum and turn a 14 MB transfer into a 70 MB in-memory response.
const archiveName = 'compiler.tar.gz.bin';
const archivePath = resolve(outputDirectory, archiveName);
const manifestPath = resolve(outputDirectory, 'compiler.json');

mkdirSync(outputDirectory, { recursive: true });

const archive = spawnSync('tar', [
  '-czf',
  archivePath,
  '-C',
  compilerRoot,
  'bin',
  'composer.json',
  'composer.lock',
  'resources',
  'src',
  'vendor',
], {
  env: {
    ...process.env,
    COPYFILE_DISABLE: '1',
  },
  encoding: 'utf8',
});

if (archive.status !== 0) {
  throw new Error(archive.stderr || 'Unable to create the browser compiler bundle.');
}

const bytes = readFileSync(archivePath);
const lockBytes = readFileSync(resolve(compilerRoot, 'composer.lock'));
const manifest = {
  archive: archiveName,
  bytes: bytes.byteLength,
  compilerLockSha256: createHash('sha256').update(lockBytes).digest('hex'),
  sha256: createHash('sha256').update(bytes).digest('hex'),
};

writeFileSync(manifestPath, `${JSON.stringify(manifest, null, 2)}\n`);
console.log(`Prepared ${archivePath} (${manifest.bytes} bytes).`);
