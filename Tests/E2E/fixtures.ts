import { test as base, expect, Page, FrameLocator } from '@playwright/test';

const TYPO3_CREDENTIALS = {
    username: process.env.TYPO3_USERNAME || 'admin',
    password: process.env.TYPO3_PASSWORD || 'Joh316!!',
};

/**
 * Get the module iframe content in TYPO3 v14 backend.
 */
export function getModuleFrame(page: Page): FrameLocator {
    return page.frameLocator('iframe').first();
}

/**
 * Login to TYPO3 backend (v14 field names).
 */
async function loginToBackend(page: Page): Promise<void> {
    await page.goto('/typo3/login');
    await page.waitForSelector('input[name="username"]', { state: 'visible', timeout: 10000 });

    await page.fill('input[name="username"]', TYPO3_CREDENTIALS.username);
    await page.fill('input[name="p_field"]', TYPO3_CREDENTIALS.password);
    await page.click('button[type="submit"]');

    await page.waitForSelector('.modulemenu', { state: 'visible', timeout: 15000 });
}

/**
 * Navigate to the Landing Page module via the module menu.
 *
 * TYPO3 v14 loads modules inside an iframe — direct URL navigation
 * does not update the iframe. We must click the menu item instead.
 */
export async function navigateToModule(page: Page): Promise<FrameLocator> {
    // Click "Landing Pages" in the module menu
    const menuItem = page.locator('.modulemenu [data-modulemenu-identifier="web_nr-landingpage"], .modulemenu a:has-text("Landing Pages")');
    await menuItem.click({ timeout: 10000 });

    const moduleFrame = getModuleFrame(page);
    await moduleFrame.locator('#landing-page-module').waitFor({ state: 'visible', timeout: 15000 });

    // Wait for the wizard JS module to load and bind the click handler.
    // The JS module is loaded asynchronously via importmap; we detect readiness
    // by waiting for the button to have a click listener attached.
    // Polling the button's __wizardReady attribute set by MutationObserver is fragile,
    // so instead we simply wait a short moment for the ES module to execute.
    await page.waitForTimeout(1000);

    return moduleFrame;
}

/**
 * Open the wizard by clicking the launch button and waiting for the dialog.
 * Handles flaky timing by retrying the click if the dialog doesn't appear.
 */
export async function openWizard(page: Page, frame: FrameLocator): Promise<import('@playwright/test').Locator> {
    const button = frame.locator('#nr-landingpage-launch-wizard');
    const dialog = page.locator('dialog');

    // Click button and wait for dialog — retry once if dialog doesn't appear
    await button.click();
    try {
        await dialog.waitFor({ state: 'visible', timeout: 8000 });
    } catch {
        // JS module might not have been ready; retry click
        await page.waitForTimeout(1000);
        await button.click();
        await dialog.waitFor({ state: 'visible', timeout: 10000 });
    }

    return dialog;
}

/**
 * Navigate to template record edit form.
 */
export async function navigateToNewTemplateRecord(page: Page): Promise<FrameLocator> {
    await page.goto('/typo3/record/edit?edit[tx_nrlandingpage_domain_model_template][0]=new&returnUrl=/typo3/module/web/list');

    const moduleFrame = getModuleFrame(page);
    await moduleFrame.locator('form[name="editform"]').waitFor({ state: 'visible', timeout: 15000 });

    return moduleFrame;
}

/**
 * Mock AJAX response helper for intercepting wizard AJAX calls.
 * Works by intercepting fetch requests matching the given URL path fragment.
 */
export async function mockAjaxRoute(
    page: Page,
    urlPath: string,
    responseData: unknown,
    success: boolean = true,
): Promise<void> {
    await page.route(`**${urlPath}**`, async (route) => {
        await route.fulfill({
            status: 200,
            contentType: 'application/json',
            body: JSON.stringify({
                success,
                data: responseData,
                ...(success ? {} : { error: typeof responseData === 'string' ? responseData : 'Error' }),
            }),
        });
    });
}

/** Sample template fixture for AJAX mocking. */
export const sampleTemplate = {
    uid: 1,
    title: 'Test Template',
    identifier: 'test-template',
    description: 'A template for E2E testing',
    briefingMode: 'optional',
    allowedCTypes: ['text', 'textmedia'],
    pageFields: ['title', 'seo_title', 'description'],
};

/** Sample template with empty allowedCTypes (the bug scenario). */
export const templateWithEmptyCTypes = {
    ...sampleTemplate,
    uid: 2,
    title: 'Empty CTypes Template',
    identifier: 'empty-ctypes',
    allowedCTypes: [],
};

/** Sample content sections response. */
export const sampleContentSections = {
    sections: [
        {
            section: 'Hero',
            ctype: 'text',
            header: 'Welcome to Our Page',
            subheader: 'Your next big thing',
            bodytext: '<p>This is the hero section.</p>',
        },
        {
            section: 'Features',
            ctype: 'textmedia',
            header: 'Our Features',
            subheader: '',
            bodytext: '<ul><li>Fast</li><li>Reliable</li></ul>',
        },
    ],
    images: [],
};

/** Content sections response with image suggestions. */
export const contentSectionsWithImages = {
    sections: [
        {
            section: 'Hero',
            ctype: 'text',
            header: 'Welcome to Our Page',
            subheader: 'Your next big thing',
            bodytext: '<p>This is the hero section.</p>',
        },
        {
            section: 'Products',
            ctype: 'textmedia',
            header: 'Our Products',
            subheader: '',
            bodytext: '<p>Check out our products.</p>',
        },
    ],
    images: [
        [
            { uid: 1, name: 'hero-banner.jpg', title: 'Hero Banner', alternative: 'Main banner image', description: 'A large hero banner' },
            { uid: 2, name: 'background.jpg', title: 'Background Image', alternative: 'Page background', description: '' },
        ],
        [
            { uid: 3, name: 'product-photo.jpg', title: 'Product Photo', alternative: 'Product showcase', description: 'Featured product image' },
        ],
    ],
};

/** Empty content sections response (the bug: all sections were dropped). */
export const emptyContentSections = {
    sections: [],
    images: [],
};

/** Template with required briefing mode for testing briefing data flow. */
export const templateWithRequiredBriefing = {
    ...sampleTemplate,
    uid: 3,
    title: 'Briefing Required Template',
    identifier: 'briefing-required',
    briefingMode: 'required',
};

/** Sample briefing questions returned by generateBriefing. */
export const sampleBriefingQuestions = [
    { label: 'Target audience', type: 'text', required: true, placeholder: 'e.g. developers' },
    { label: 'Key message', type: 'textarea', required: false, placeholder: 'Main message' },
];

/** Page fields response with SEO fields populated (Bug 1 scenario). */
export const pageFieldsWithSeo = {
    title: 'My Landing Page',
    slug: 'my-landing-page',
    seo_title: 'Best Landing Page Ever',
    description: 'A compelling meta description generated by LLM',
    og_title: 'Share This Page',
    og_description: 'OG description for social sharing',
};

export const test = base.extend<{ authenticatedPage: Page }>({
    authenticatedPage: async ({ page }, use) => {
        await loginToBackend(page);
        await use(page);
    },
});

export { expect };
