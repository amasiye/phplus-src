import { PHP, loadPHPRuntime } from '@php-wasm/universal';
import { getPHPLoaderModule } from '@php-wasm/web-8-4';

async function run() {
  const loader = await getPHPLoaderModule();
  const php = new PHP(await loadPHPRuntime(loader));
  self.postMessage({ type: 'running' });
  const response = await php.runStream({ code: '<?php while (true) {}' });
  await response.finished;
  self.postMessage({ type: 'escaped' });
}

run().catch((error) => {
  self.postMessage({ type: 'error', message: String(error) });
});
