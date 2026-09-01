import {
  createSpawnHandler,
  splitShellCommand,
} from '@php-wasm/util';

const allowedCommands = new Set(['php', 'ls', 'pwd']);

function describeError(error) {
  if (error instanceof Error) {
    return `${error.message}\n${error.stack ?? ''}`;
  }

  if (typeof error === 'object' && error !== null) {
    return JSON.stringify(error, Object.getOwnPropertyNames(error));
  }

  return String(error);
}

function outputDestination(write) {
  return new WritableStream({ write });
}

function chunkBytes(chunk) {
  if (typeof chunk === 'string') {
    return new TextEncoder().encode(chunk).byteLength;
  }

  return chunk?.byteLength ?? chunk?.length ?? 0;
}

function recordObservation(observe, observation) {
  try {
    observe(observation);
  } catch {
    // Instrumentation must not change subprocess behavior.
  }
}

async function drainPHPResponse(response, processApi, command, observe) {
  const observation = {
    command: [...command],
    stdoutChunks: 0,
    stdoutBytes: 0,
    stderrChunks: 0,
    stderrBytes: 0,
    drainsCompleted: false,
    phase: 'pipe-stdout',
  };
  let drains = [];

  try {
    const stdoutDrain = response.stdout.pipeTo(outputDestination((chunk) => {
      observation.stdoutChunks += 1;
      observation.stdoutBytes += chunkBytes(chunk);
      processApi.stdout(chunk);
    }));
    observation.phase = 'pipe-stderr';
    const stderrDrain = response.stderr.pipeTo(outputDestination((chunk) => {
      observation.stderrChunks += 1;
      observation.stderrBytes += chunkBytes(chunk);
      processApi.stderr(chunk);
    }));
    drains = [stdoutDrain, stderrDrain];
    observation.phase = 'await-exit';
    const exitCode = await response.exitCode;
    observation.exitCode = exitCode;

    observation.phase = 'await-drains';
    await Promise.all([stdoutDrain, stderrDrain]);
    observation.drainsCompleted = true;
    observation.phase = 'complete';
    recordObservation(observe, observation);
    processApi.exit(exitCode);
  } catch (error) {
    await Promise.allSettled(drains);
    observation.error = describeError(error);
    recordObservation(observe, observation);
    throw new Error(`PHP subprocess response failed during ${observation.phase}: ${describeError(error)}`, {
      cause: error,
    });
  }
}

/**
 * Spike-local copy of PHP-WASM's sandboxed spawn adapter.
 *
 * The behavior intentionally stays aligned with the upstream adapter except
 * that PHP subprocess output is completely drained before the exit is exposed
 * to proc_open().
 */
export function drainAwareSandboxedSpawnHandlerFactory(getPHPInstance, observePHPSubprocess = () => {}) {
  return createSpawnHandler(async (command, processApi, options) => {
    processApi.notifySpawn();

    if (command?.[0] === '/bin/sh' && command?.[1] === '-c' && typeof command[2] === 'string') {
      command = splitShellCommand(command[2]);
    }

    if (command[0] === 'exec') {
      command.shift();
    }

    if (command[0]?.endsWith('.php') || command[0]?.endsWith('.phar')) {
      command.unshift('php');
    }

    const executable = command[0]?.split('/').pop();

    if (command[0] === '/usr/bin/env' && command[1] === 'stty' && command[2] === 'size') {
      processApi.stdout('18 140');
      processApi.exit(0);
      return;
    }

    if (executable === 'tput' && command[1] === 'cols') {
      processApi.stdout('140');
      processApi.exit(0);
      return;
    }

    if (executable === 'less') {
      processApi.on('stdin', (chunk) => {
        processApi.stdout(chunk);
      });
      await new Promise((resolve) => {
        processApi.childProcess.stdin.on('finish', resolve);
      });
      processApi.exit(0);
      return;
    }

    if (!allowedCommands.has(executable ?? '')) {
      processApi.exit(127);
      return;
    }

    if (!getPHPInstance) {
      console.warn('A PHP subprocess was requested without a PHP-WASM child runtime.');
      processApi.exit(127);
      return;
    }

    const { php, reap } = await getPHPInstance();

    try {
      if (options.cwd) {
        await php.chdir(options.cwd);
      }

      const cwd = await php.cwd();

      switch (executable) {
        case 'php': {
          const response = await php.cli(command, {
            env: {
              ...options.env,
              SCRIPT_PATH: command[1],
              SHELL_PIPE: '0',
            },
          });
          await drainPHPResponse(response, processApi, command, observePHPSubprocess);
          break;
        }

        case 'ls': {
          for (const filename of await php.listFiles(command[1] ?? cwd)) {
            processApi.stdout(`${filename}\n`);
          }
          await new Promise((resolve) => setTimeout(resolve, 10));
          processApi.exit(0);
          break;
        }

        case 'pwd': {
          processApi.stdout(`${cwd}\n`);
          await new Promise((resolve) => setTimeout(resolve, 10));
          processApi.exit(0);
          break;
        }
      }
    } catch (error) {
      try {
        processApi.stderr(`[spawn error] ${describeError(error)}`);
      } finally {
        processApi.exit(1);
      }
      throw error;
    } finally {
      reap();
    }
  });
}
