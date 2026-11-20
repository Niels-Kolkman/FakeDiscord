/**
 * The environment the browser tests run the app in.
 *
 * The app gets its own SQLite file and port, so a run never touches the
 * development database or a `composer dev` server on :8000. Everything
 * else (APP_KEY, the Reverb credentials) comes from .env. Real environment
 * variables win over .env in Laravel, so these overrides are passed to the
 * PHP processes as plain environment variables.
 *
 * Reverb keeps the host and port from .env: the frontend bundle has
 * VITE_REVERB_HOST / VITE_REVERB_PORT baked in at build time.
 */
import path from 'node:path';
import { fileURLToPath } from 'node:url';

export const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../..');

export const APP_PORT = Number(process.env.E2E_APP_PORT ?? 8001);
export const APP_URL = `http://127.0.0.1:${APP_PORT}`;
export const REVERB_PORT = Number(process.env.E2E_REVERB_PORT ?? 8080);

export const DATABASE = path.join(ROOT, 'database', 'e2e.sqlite');

/** Merged over process.env for every PHP process the tests start. */
export const APP_ENV = {
    APP_URL,
    DB_CONNECTION: 'sqlite',
    DB_DATABASE: DATABASE,
    DB_URL: '',
    BROADCAST_CONNECTION: 'reverb',
    // Like production (ISSUE-42): broadcasts go onto the database queue and
    // serve.js runs one queue worker next to the app.
    QUEUE_CONNECTION: 'database',
    MAIL_MAILER: 'log',
    // Debugbar (ISSUE-42) is for looking at pages by hand, not for tests.
    DEBUGBAR_ENABLED: 'false',
    BCRYPT_ROUNDS: '4',
};

export function phpEnv() {
    return { ...process.env, ...APP_ENV };
}
