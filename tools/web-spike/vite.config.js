import { defineConfig } from 'vite';
import { readFileSync } from 'node:fs';
import { basename } from 'node:path';

function phpWasmAssetPlugin() {
  return {
    name: 'ppphp-php-wasm-assets',
    enforce: 'pre',
    load(id) {
      if (!id.includes('@php-wasm/web-8-4/') || !id.endsWith('.wasm')) {
        return null;
      }

      const referenceId = this.emitFile({
        type: 'asset',
        name: basename(id),
        source: readFileSync(id),
      });

      return `export default import.meta.ROLLUP_FILE_URL_${referenceId};`;
    },
  };
}

export default defineConfig({
  plugins: [phpWasmAssetPlugin()],
  optimizeDeps: {
    exclude: ['@php-wasm/universal', '@php-wasm/web-8-4'],
  },
  build: {
    target: 'esnext',
  },
  worker: {
    format: 'es',
    plugins: () => [phpWasmAssetPlugin()],
  },
});
