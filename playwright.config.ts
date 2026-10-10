import { defineConfig, devices } from '@playwright/test';

/**
 * Minimal Playwright harness for isolated-cloud / local preview.
 * BASE_URL (or APP_URL) must point at a local or preview host — never production.
 */
const baseURL = process.env.BASE_URL ?? process.env.APP_URL ?? 'http://127.0.0.1:8000';

export default defineConfig({
    testDir: './tests/e2e',
    testMatch: '**/*.spec.ts',
    fullyParallel: false,
    forbidOnly: !!process.env.CI,
    retries: 0,
    workers: 1,
    reporter: [['list']],
    timeout: 30_000,
    use: {
        baseURL,
        ...devices['Desktop Chrome'],
        ignoreHTTPSErrors: true,
        screenshot: 'only-on-failure',
        trace: 'on-first-retry',
        storageState: process.env.E2E_STORAGE_STATE,
    },
    webServer:
        process.env.E2E_START_SERVER === '1'
            ? {
                  command: 'php artisan serve --host=127.0.0.1 --port=8000',
                  url: 'http://127.0.0.1:8000/app/login',
                  reuseExistingServer: true,
                  timeout: 120_000,
              }
            : undefined,
});
