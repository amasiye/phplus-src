import './styles.css';

const status = document.querySelector('#status');
const output = document.querySelector('#output');
const worker = new Worker(new URL('./php-worker.js', import.meta.url), {
  type: 'module',
});

function verifyRunawayTermination() {
  return new Promise((resolve, reject) => {
    const hostileWorker = new Worker(new URL('./timeout-worker.js', import.meta.url), {
      type: 'module',
    });
    const startedAt = performance.now();

    hostileWorker.addEventListener('message', ({ data }) => {
      if (data.type === 'running') {
        setTimeout(() => {
          hostileWorker.terminate();
          resolve({
            terminated: true,
            limitMs: 250,
            elapsedMs: Math.round(performance.now() - startedAt),
          });
        }, 250);
        return;
      }

      hostileWorker.terminate();
      reject(new Error(data.message ?? 'Runaway code escaped its worker boundary.'));
    });

    hostileWorker.addEventListener('error', (event) => {
      hostileWorker.terminate();
      reject(event.error ?? new Error(event.message));
    });
  });
}

worker.addEventListener('message', async ({ data }) => {
  if (data.type === 'progress') {
    status.textContent = data.detail;
    return;
  }

  if (data.type === 'ready') {
    status.textContent = 'Verifying termination of runaway user code.';

    try {
      const containment = await verifyRunawayTermination();
      status.textContent = 'The browser completed the ++PHP compiler spike.';
      output.textContent = JSON.stringify({ ...data.result, containment }, null, 2);
    } catch (error) {
      status.textContent = 'The runaway-code containment probe failed.';
      output.textContent = String(error);
    }

    return;
  }

  if (data.type === 'error') {
    status.textContent = 'PHP WebAssembly runtime failed to start.';
    output.textContent = data.message;
  }
});

worker.addEventListener('error', (event) => {
  const details = [event.message, event.filename, event.lineno, event.colno]
    .filter(Boolean)
    .join(':');

  console.error('PHP WebAssembly worker error', event.error ?? event);
  status.textContent = 'PHP WebAssembly worker crashed.';
  output.textContent = details || 'The worker failed before it could report an error.';
});

worker.addEventListener('messageerror', (event) => {
  console.error('PHP WebAssembly worker message error', event);
  status.textContent = 'PHP WebAssembly worker returned an unreadable message.';
});
