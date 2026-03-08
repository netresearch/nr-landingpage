import { defineConfig, devices } from '@playwright/test';

/**
 * Playwright configuration for nr-landingpage E2E tests.
 *
 * Requires a running TYPO3 v14 instance.
 * Set TYPO3_BASE_URL env var or runTests.sh will detect it from ddev describe.
 */
export default defineConfig({
    testDir: '.',
    fullyParallel: false,
    forbidOnly: !!process.env.CI,
    retries: process.env.CI ? 2 : 0,
    workers: 1,
    reporter: [['html', { open: 'never' }]],
    timeout: 60000,
    use: {
        baseURL: process.env.TYPO3_BASE_URL,
        trace: 'on-first-retry',
        ignoreHTTPSErrors: true,
        screenshot: 'only-on-failure',
    },
    projects: [
        {
            name: 'chromium',
            use: { ...devices['Desktop Chrome'] },
        },
    ],
});
