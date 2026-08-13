import { defineConfig, devices } from '@playwright/test';

/**
 * Playwright configuration for nr-landingpage E2E tests.
 *
 * At the repository root rather than next to the specs: the shared e2e workflow
 * in netresearch/typo3-ci-workflows runs npm from the root, and Playwright
 * discovers its config there. testDir points back at the suite.
 *
 * Requires a running TYPO3 v14 instance; TYPO3_BASE_URL says where. The shared
 * workflow sets it to the built-in PHP server it starts.
 */
export default defineConfig({
    testDir: 'Tests/E2E',
    fullyParallel: false,
    forbidOnly: !!process.env.CI,
    retries: process.env.CI ? 2 : 0,
    workers: 1,
    reporter: [['list'], ['html', { open: 'never' }]],
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
