import { defineConfig, devices } from '@playwright/test';

/**
 * Playwright configuration for nr-landingpage E2E tests.
 *
 * Requires a running TYPO3 instance. Adjust the baseURL below to match
 * your local development environment (e.g. DDEV).
 */
export default defineConfig({
    testDir: '.',
    fullyParallel: false,
    forbidOnly: !!process.env.CI,
    retries: process.env.CI ? 2 : 0,
    workers: 1,
    reporter: 'html',
    use: {
        baseURL: process.env.TYPO3_BASE_URL || 'https://nr-landingpage.ddev.site',
        trace: 'on-first-retry',
        ignoreHTTPSErrors: true,
    },
    projects: [
        {
            name: 'chromium',
            use: { ...devices['Desktop Chrome'] },
        },
    ],
});
