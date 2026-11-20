/**
 * Starts the app for the browser tests (Playwright's webServer):
 * a fresh database/e2e.sqlite, migrated, then PHP's built-in server on
 * APP_PORT and one queue worker for the broadcasts (ISSUE-42). `php -S` is used instead of `artisan serve` because artisan
 * serve drops environment variables that .env also sets, unless --no-reload.
 */
import { spawn, spawnSync } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';

import { APP_PORT, DATABASE, ROOT, phpEnv } from './environment.js';

const php = process.env.PHP_BINARY ?? 'php';
const env = phpEnv();

function fail(message) {
    console.error(`[e2e] ${message}`);
    process.exit(1);
}

if (!fs.existsSync(path.join(ROOT, '.env'))) {
    fail('No .env file. Run `composer setup` (or copy .env.example and run `php artisan key:generate`).');
}

const built = fs.existsSync(path.join(ROOT, 'public', 'build', 'manifest.json'));
const hot = fs.existsSync(path.join(ROOT, 'public', 'hot'));
if (!built && !hot) {
    fail('No frontend assets. Run `npm run build` (or keep `npm run dev` running).');
}

function artisan(...args) {
    const result = spawnSync(php, ['artisan', ...args], { cwd: ROOT, env, stdio: 'inherit' });
    if (result.status !== 0) {
        fail(`php artisan ${args.join(' ')} failed.`);
    }
}

// A cached config would ignore the overrides in environment.js.
artisan('config:clear');

// migrate:fresh instead of deleting the file: on Windows the file can stay
// locked for a moment by the server of a run that is still shutting down.
if (!fs.existsSync(DATABASE)) {
    fs.writeFileSync(DATABASE, '');
}
artisan('migrate:fresh', '--force', '--no-interaction');

const router = path.join(ROOT, 'vendor', 'laravel', 'framework', 'src', 'Illuminate', 'Foundation', 'resources', 'server.php');
// Laravel's router script takes the public directory from the working directory.
const server = spawn(php, ['-S', `127.0.0.1:${APP_PORT}`, router], {
    cwd: path.join(ROOT, 'public'),
    env,
    stdio: ['ignore', 'inherit', 'pipe'],
});

// `php -S` logs every request on stderr; pass on only PHP's own warnings and
// errors (the app's exceptions are in storage/logs/laravel.log).
let pending = '';
server.stderr.setEncoding('utf8').on('data', (chunk) => {
    const lines = (pending + chunk).split(/\r?\n/);
    pending = lines.pop();
    for (const line of lines) {
        if (/PHP (Fatal|Parse|Warning|Notice|Deprecated)|Failed to listen/.test(line)) {
            console.error(line);
        }
    }
});

// One queue worker (ISSUE-42): it sends the broadcasts the requests queue.
// Started after migrate:fresh, so the jobs table exists.
const worker = spawn(php, ['artisan', 'queue:work', 'database', '--sleep=1', '--tries=1'], {
    cwd: ROOT,
    env,
    stdio: ['ignore', 'ignore', 'inherit'],
});

for (const signal of ['SIGINT', 'SIGTERM']) {
    process.on(signal, () => {
        worker.kill();
        server.kill();
    });
}
worker.on('exit', (code) => {
    if (code !== null && code !== 0) {
        server.kill();
        fail(`The queue worker stopped (exit code ${code}).`);
    }
});
server.on('exit', (code) => {
    worker.kill();
    process.exit(code ?? 0);
});
