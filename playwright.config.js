/**
 * Browser end-to-end tests (ISSUE-40): `npm run test:e2e`.
 *
 * Playwright starts the app (tests/e2e/support/serve.js: a fresh
 * database/e2e.sqlite on :8001) and Reverb, unless Reverb is already
 * running locally (e.g. from `composer dev`). Build the frontend first
 * (`npm run build`). See "Browser tests" in the README.
 */
import { defineConfig, devices } from '@playwright/test';

import { APP_URL, REVERB_PORT, phpEnv } from './tests/e2e/support/environment.js';

const php = process.env.PHP_BINARY ?? 'php';
const ci = Boolean(process.env.CI);

export default defineConfig({
    testDir: './tests/e2e',
    outputDir: './storage/framework/testing/e2e-results',
    // One app, one database, users talking to each other: run serially.
    fullyParallel: false,
    workers: 1,
    forbidOnly: ci,
    retries: ci ? 1 : 0,
    timeout: 60_000,
    expect: { timeout: 10_000 },
    reporter: ci ? [['list'], ['github'], ['html', { open: 'never', outputFolder: 'playwright-report' }]] : 'list',
    use: {
        baseURL: APP_URL,
        trace: 'retain-on-failure',
        screenshot: 'only-on-failure',
        video: 'retain-on-failure',
    },
    projects: [{ name: 'chromium', use: { ...devices['Desktop Chrome'] } }],
    webServer: [
        {
            name: 'reverb',
            command: `${php} artisan reverb:start --port=${REVERB_PORT}`,
            port: REVERB_PORT,
            env: phpEnv(),
            reuseExistingServer: !ci,
            timeout: 30_000,
        },
        {
            name: 'app',
            command: 'node tests/e2e/support/serve.js',
            url: `${APP_URL}/up`,
            env: phpEnv(),
            // Always a fresh app and database, never the dev server.
            reuseExistingServer: false,
            timeout: 120_000,
            gracefulShutdown: { signal: 'SIGTERM', timeout: 5_000 },
        },
    ],
});
