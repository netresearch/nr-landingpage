import {
    test,
    expect,
    navigateToModule,
    openWizard,
    mockAjaxRoute,
    sampleTemplate,
    templateWithEmptyCTypes,
    templateWithRequiredBriefing,
    sampleBriefingQuestions,
    sampleContentSections,
    emptyContentSections,
    contentSectionsWithImages,
    pageFieldsWithSeo,
} from './fixtures';
import { Locator, Page } from '@playwright/test';

/**
 * E2E tests for the Landing Page Wizard.
 *
 * These tests use Playwright route interception to mock AJAX responses,
 * allowing us to test the wizard UI without a live LLM backend.
 *
 * Prerequisites:
 * - A TYPO3 v14 with nr_landingpage installed, reachable at TYPO3_BASE_URL
 * - Playwright system dependencies installed
 *
 * No page in the page tree is needed. The module renders its launch button
 * unconditionally and no test selects a page; the pageUid in the save response
 * is mocked like every other AJAX reply. CI runs the suite against a TYPO3 that
 * has no pages at all.
 *
 * Run in CI via .github/workflows/e2e.yml, locally with: npx playwright test
 */

/**
 * Wait for the Next button to be enabled, then click it.
 * The TYPO3 MultiStepWizard uses Bootstrap carousel with `forceSelection`,
 * which briefly disables Next during slide transitions. We wait for the
 * button to be enabled before clicking.
 */
async function clickNext(modal: Locator, page: Page): Promise<void> {
    const nextButton = modal.locator('button[name="next"]:not([disabled])');
    await nextButton.waitFor({ state: 'visible', timeout: 15000 });
    // Small delay to ensure carousel animation is complete
    // (Bootstrap 5 carousel transition is ~600ms)
    await page.waitForTimeout(800);
    await nextButton.click();
}

/**
 * Select a template card, then advance to the briefing step.
 * Waits for the briefing form's title input to appear (confirming the
 * carousel animation and slide callback are complete).
 */
async function selectTemplateAndAdvanceToBriefing(modal: Locator, page: Page): Promise<void> {
    await modal.locator('.template-card').first().click();
    await clickNext(modal, page);
    // Wait for briefing form title input — this only exists after the slide
    // callback runs (post-carousel-animation)
    await modal.locator('#briefing_title').waitFor({ state: 'visible', timeout: 15000 });
}

/**
 * Advance from briefing to page fields step.
 * Waits for the page fields form to render (#pf_title visible).
 */
async function advanceToPageFields(modal: Locator, page: Page): Promise<void> {
    await clickNext(modal, page);
    await modal.locator('#pf_title').waitFor({ state: 'visible', timeout: 15000 });
}

/**
 * Advance from page fields to content step.
 * Waits for content to render (section cards or "no content" alert).
 */
async function advanceToContent(modal: Locator, page: Page): Promise<void> {
    await clickNext(modal, page);
    await modal.locator('[id^="section-card-"], .alert-info').first().waitFor({ state: 'visible', timeout: 15000 });
}

test.describe('Landing Page Wizard', () => {
    // -- Enter handled by an inner control must not advance the wizard --

    /**
     * True while the carousel is sliding or once it has moved away from the
     * slide that holds `selector`. Checked right after the key press: Bootstrap
     * adds the transitional classes synchronously when next() runs.
     */
    async function wizardMovedAwayFrom(modal: Locator, selector: string): Promise<boolean> {
        return modal.evaluate((root, sel) => {
            const sliding = root.querySelector('.carousel-item-next, .carousel-item-prev, .carousel-item-start, .carousel-item-end') !== null;
            const active = root.querySelector('.carousel-item.active');
            return sliding || !active || active.querySelector(sel) === null;
        }, selector);
    }

    /** Mock the wizard's AJAX replies (path fragment -> data) and open it. */
    async function openMockedWizard(page: Page, replies: Record<string, unknown>): Promise<Locator> {
        for (const [path, data] of Object.entries(replies)) {
            await mockAjaxRoute(page, '/nr-landingpage/wizard/' + path, data);
        }
        return openWizard(page, await navigateToModule(page));
    }

    test('Enter on a template card selects it and keeps the step', async ({ authenticatedPage: page }) => {
        const modal = await openMockedWizard(page, { 'templates': [sampleTemplate], 'generate-briefing': [] });

        const card = modal.locator('.template-card').first();
        await expect(card).toBeVisible({ timeout: 10000 });
        // The modal moves focus to its active footer button once it has
        // opened; wait for that, or it takes the focus back from the card.
        await expect.poll(() => page.evaluate(() => document.activeElement?.classList.contains('t3js-active') ?? false)).toBe(true);
        await card.focus();
        await expect.poll(() => page.evaluate(() => document.activeElement?.classList.contains('template-card') ?? false)).toBe(true);
        await page.keyboard.press('Enter');

        expect(await wizardMovedAwayFrom(modal, '.template-card')).toBe(false);
        // Enter selected the card (which unlocks Next) without pressing Next.
        await expect(card).toHaveClass(/border-primary/);
        await expect(modal.locator('button[name="next"]')).toBeEnabled();
        await expect(modal.locator('.carousel-item.active .template-card')).toBeVisible();
        await expect(modal.locator('#briefing_title')).toHaveCount(0);
    });

    test('Enter in the image search input searches and keeps the content step', async ({ authenticatedPage: page }) => {
        let searches = 0;
        await page.route('**/nr-landingpage/wizard/search-images**', async (route) => {
            searches++;
            await route.fulfill({
                status: 200,
                contentType: 'application/json',
                body: JSON.stringify({ success: true, data: { images: [{ uid: 51, name: 'found.jpg', title: 'Found image' }] } }),
            });
        });

        const modal = await openMockedWizard(page, {
            'templates': [sampleTemplate],
            'generate-briefing': [],
            'generate-page-fields': { title: 'Enter test', seo_title: 'SEO', description: 'Desc' },
            'generate-content': sampleContentSections,
        });
        await selectTemplateAndAdvanceToBriefing(modal, page);
        await advanceToPageFields(modal, page);
        await advanceToContent(modal, page);

        const search = modal.locator('#section-card-0').getByRole('textbox', { name: 'Search images…' });
        await search.fill('beach');
        await search.press('Enter');

        expect(await wizardMovedAwayFrom(modal, '#section-card-0')).toBe(false);
        await expect(modal.locator('#section-card-0 [data-image-uid="51"]')).toContainText('Found image');
        await expect(modal.locator('.carousel-item.active #section-card-0 .border-top')).toBeVisible();
        expect(searches).toBe(1);
    });

    test('module launcher page renders Create button', async ({ authenticatedPage: page }) => {
        const frame = await navigateToModule(page);

        const button = frame.locator('#nr-landingpage-launch-wizard');
        await expect(button).toBeVisible();
        await expect(button).toContainText('Create Landing Page');
    });

    test('wizard opens when clicking Create button', async ({ authenticatedPage: page }) => {
        await mockAjaxRoute(page, '/nr-landingpage/wizard/templates', [sampleTemplate]);

        const frame = await navigateToModule(page);
        const modal = await openWizard(page, frame);

        await expect(modal).toBeVisible();
    });

    /**
     * Regression net for the empty first step — NOT a reproduction of it.
     *
     * TYPO3 v14 calls the Modal.advanced() callback one animation frame before
     * `typo3-modal-show` assigns Modal.currentModal, and
     * MultiStepWizard.initializeEvents() ends by reading that property. Which
     * side wins depends on whether the progress-tracker import the callback
     * awaits resolves before the frame does.
     *
     * Two attempts to force the throwing side both stayed green on the same
     * TYPO3 v14.3.6 this suite installs: opening the wizard twice, and pulling
     * the import into the module registry beforehand (kept below). So this
     * environment lands on the safe side of the race, and this test has never
     * seen the failure it is named after — passing it proves nothing about the
     * fix in wizard.js.
     *
     * It is kept because it asserts the property that matters, first slide
     * renders and no null-receiver error, and would catch a future change that
     * makes the throw unconditional.
     */
    test('renders the first step when the modal callback wins the race', async ({ authenticatedPage: page }) => {
        await mockAjaxRoute(page, '/nr-landingpage/wizard/templates', [sampleTemplate]);

        const errors: string[] = [];
        page.on('pageerror', (error) => errors.push(error.message));
        page.on('console', (message) => {
            if (message.type() === 'error') {
                errors.push(message.text());
            }
        });

        const frame = await navigateToModule(page);

        // Evaluate in the top frame, which is where the modal lives.
        await page.evaluate(async () => {
            await import('@typo3/backend/element/progress-tracker-element.js');
        });

        const modal = await openWizard(page, frame);
        await expect(modal.locator('.template-card')).toBeVisible({ timeout: 10000 });

        expect(errors.filter((message) => message.includes('addEventListener'))).toEqual([]);
    });

    test('wizard loads and displays templates', async ({ authenticatedPage: page }) => {
        await mockAjaxRoute(page, '/nr-landingpage/wizard/templates', [sampleTemplate]);

        const frame = await navigateToModule(page);
        const modal = await openWizard(page, frame);

        const templateCard = modal.locator('.template-card');
        await expect(templateCard).toBeVisible({ timeout: 10000 });
        await expect(templateCard).toContainText('Test Template');
    });

    test('template cards are a single choice: checking B unchecks A', async ({ authenticatedPage: page }) => {
        await mockAjaxRoute(page, '/nr-landingpage/wizard/templates', [sampleTemplate, templateWithEmptyCTypes]);

        const frame = await navigateToModule(page);
        const modal = await openWizard(page, frame);

        const group = modal.getByRole('radiogroup');
        await expect(group).toBeVisible({ timeout: 10000 });
        const a = group.getByRole('radio', { name: sampleTemplate.title });
        const b = group.getByRole('radio', { name: templateWithEmptyCTypes.title });
        await expect(a).toHaveAttribute('aria-checked', 'false');
        await expect(b).toHaveAttribute('aria-checked', 'false');

        await a.click();
        await expect(a).toHaveAttribute('aria-checked', 'true');
        await expect(b).toHaveAttribute('aria-checked', 'false');
        await expect(a).toHaveClass(/border-2/);

        await b.click();
        await expect(a).toHaveAttribute('aria-checked', 'false');
        await expect(b).toHaveAttribute('aria-checked', 'true');
        await expect(a).not.toHaveClass(/border-2/);
        await expect(b).toHaveClass(/border-2/);
        await expect(a).toHaveAttribute('tabindex', '-1');
        await expect(b).toHaveAttribute('tabindex', '0');
    });

    test('template radio cards read out their description and briefing mode', async ({ authenticatedPage: page }) => {
        await mockAjaxRoute(page, '/nr-landingpage/wizard/templates', [sampleTemplate, templateWithEmptyCTypes]);

        const frame = await navigateToModule(page);
        const modal = await openWizard(page, frame);

        const a = modal.getByRole('radio', { name: sampleTemplate.title });
        await expect(a).toBeVisible({ timeout: 10000 });
        await expect(a).toHaveAccessibleDescription(sampleTemplate.description + ' Briefing: ' + sampleTemplate.briefingMode);
    });

    test('going Back to the template step keeps the checked template', async ({ authenticatedPage: page }) => {
        await mockAjaxRoute(page, '/nr-landingpage/wizard/templates', [sampleTemplate, templateWithEmptyCTypes]);
        await mockAjaxRoute(page, '/nr-landingpage/wizard/generate-briefing', []);

        const frame = await navigateToModule(page);
        const modal = await openWizard(page, frame);

        const b = modal.getByRole('radio', { name: templateWithEmptyCTypes.title });
        await b.click();
        await clickNext(modal, page);
        await modal.locator('#briefing_title').waitFor({ state: 'visible', timeout: 15000 });

        // Back re-runs the template slide's renderer. Mark the old group so the
        // assertions below can only pass on the freshly rendered cards.
        await modal.locator('[role="radiogroup"]').evaluate((g) => g.setAttribute('data-stale', '1'));
        await modal.locator('button[name="prev"]:not([disabled])').click();
        const group = modal.locator('[role="radiogroup"]:not([data-stale])');
        await expect(group).toBeVisible({ timeout: 15000 });
        const a = group.getByRole('radio', { name: sampleTemplate.title });
        const b2 = group.getByRole('radio', { name: templateWithEmptyCTypes.title });
        await expect(b2).toHaveAttribute('aria-checked', 'true');
        await expect(b2).toHaveAttribute('tabindex', '0');
        await expect(a).toHaveAttribute('aria-checked', 'false');
        await expect(a).toHaveAttribute('tabindex', '-1');
        await expect(modal.locator('button[name="next"]')).toBeEnabled();
    });

    test('arrow keys in the briefing step stay with the control, not the carousel', async ({ authenticatedPage: page }) => {
        await mockAjaxRoute(page, '/nr-landingpage/wizard/templates', [templateWithRequiredBriefing]);
        await mockAjaxRoute(page, '/nr-landingpage/wizard/generate-briefing', [
            { label: 'Tone', type: 'select', options: ['Formal', 'Casual'], required: false },
        ]);

        const frame = await navigateToModule(page);
        const modal = await openWizard(page, frame);
        await selectTemplateAndAdvanceToBriefing(modal, page);

        // Bootstrap adds its transitional classes synchronously when it slides,
        // so the state right after a key press shows whether a slide started.
        const stayed = () => modal.evaluate((root) => {
            const active = root.querySelector('.carousel-item.active');
            return root.querySelector('.carousel-item-next, .carousel-item-prev, .carousel-item-start, .carousel-item-end') === null
                && !!active && active.querySelector('#briefing_title') !== null;
        });

        // briefingMode "required" and an empty title: Next stays locked.
        await expect(modal.locator('button[name="next"]')).toBeDisabled();
        const select = modal.locator('#briefing_q_0');
        await select.selectOption('Formal');
        await select.focus();

        // ArrowRight would slide forward past the locked Next; the select
        // still gets the key and moves to the next option.
        await page.keyboard.press('ArrowRight');
        expect(await stayed()).toBe(true);
        await expect(select).toHaveValue('Casual');

        // ArrowLeft would slide back to the template step.
        await page.keyboard.press('ArrowLeft');
        expect(await stayed()).toBe(true);
        await expect(select).toHaveValue('Formal');

        // In a text input the arrow keys still move the caret.
        const title = modal.locator('#briefing_title');
        await title.fill('abc');
        await title.press('End');
        await page.keyboard.press('ArrowLeft');
        expect(await stayed()).toBe(true);
        expect(await title.evaluate((input: HTMLInputElement) => input.selectionStart)).toBe(2);

        await expect(modal.locator('.carousel-item.active #briefing_title')).toBeVisible();
    });

    test('briefing answers survive Back to the template step and Next again', async ({ authenticatedPage: page }) => {
        await mockAjaxRoute(page, '/nr-landingpage/wizard/templates', [sampleTemplate]);
        await mockAjaxRoute(page, '/nr-landingpage/wizard/generate-briefing', [
            { label: 'Tone', type: 'select', options: ['Formal', 'Casual'], required: false },
        ]);

        const frame = await navigateToModule(page);
        const modal = await openWizard(page, frame);
        await selectTemplateAndAdvanceToBriefing(modal, page);

        await modal.locator('#briefing_title').fill('Kept title');
        await modal.locator('#briefing_q_0').selectOption('Casual');

        await modal.locator('button[name="prev"]:not([disabled])').click();
        await expect(modal.locator('.carousel-item.active [role="radiogroup"]')).toBeVisible({ timeout: 15000 });
        // Mark the old form: the assertions below must read the re-rendered one.
        await modal.locator('#briefing_title').evaluate((input) => input.setAttribute('data-stale', '1'));

        await clickNext(modal, page);
        const title = modal.locator('#briefing_title:not([data-stale])');
        await expect(title).toBeVisible({ timeout: 15000 });
        await expect(title).toHaveValue('Kept title');
        await expect(modal.locator('#briefing_q_0')).toHaveValue('Casual');
    });

    /**
     * Go Back without moving focus: a real click on the button would blur the
     * field and fire its change event, which hides whether the input listener
     * alone keeps the answers.
     */
    async function backWithoutBlur(modal: Locator): Promise<void> {
        await modal.locator('button[name="prev"]:not([disabled])').evaluate((button: HTMLButtonElement) => button.click());
        await expect(modal.locator('.carousel-item.active [role="radiogroup"]')).toBeVisible({ timeout: 15000 });
        await modal.locator('#briefing_title').evaluate((input) => input.setAttribute('data-stale', '1'));
    }

    test('typed briefing title is stored by the input listener alone', async ({ authenticatedPage: page }) => {
        await mockAjaxRoute(page, '/nr-landingpage/wizard/templates', [sampleTemplate]);
        await mockAjaxRoute(page, '/nr-landingpage/wizard/generate-briefing', []);

        const frame = await navigateToModule(page);
        const modal = await openWizard(page, frame);
        await selectTemplateAndAdvanceToBriefing(modal, page);

        // The wizard state lives in the module frame's realm (wizard.js is
        // loaded there); read it while the field keeps focus, so no change
        // event can have fired: only the input listener can have stored it.
        const storedTitle = () => frame.locator('body').evaluate(async () => {
            const state = (await import('@netresearch/nr-landingpage/wizard-state.js')).default;
            return [state.getTitle(), state.getBriefingAnswers().title ?? null];
        });
        const title = modal.locator('#briefing_title');
        await title.focus();
        await page.keyboard.type('Typed title');
        await expect.poll(() => page.evaluate(() => document.activeElement?.id ?? null)).toBe('briefing_title');
        expect(await storedTitle()).toEqual(['Typed title', 'Typed title']);
    });

    test('briefing answer set with a change event only is kept by the change listener', async ({ authenticatedPage: page }) => {
        await mockAjaxRoute(page, '/nr-landingpage/wizard/templates', [sampleTemplate]);
        await mockAjaxRoute(page, '/nr-landingpage/wizard/generate-briefing', [
            { label: 'Tone', type: 'select', options: ['Formal', 'Casual'], required: false },
        ]);

        const frame = await navigateToModule(page);
        const modal = await openWizard(page, frame);
        await selectTemplateAndAdvanceToBriefing(modal, page);

        // Some controls (and autofill) report a new value with change and no
        // input event; dispatch exactly that.
        await modal.locator('#briefing_q_0').evaluate((select: HTMLSelectElement) => {
            select.value = 'Casual';
            select.dispatchEvent(new Event('change', { bubbles: true }));
        });
        await backWithoutBlur(modal);

        await clickNext(modal, page);
        await expect(modal.locator('#briefing_title:not([data-stale])')).toBeVisible({ timeout: 15000 });
        await expect(modal.locator('#briefing_q_0')).toHaveValue('Casual');
    });

    test('a briefing title typed and cleared again leaves the page title empty', async ({ authenticatedPage: page }) => {
        await mockAjaxRoute(page, '/nr-landingpage/wizard/templates', [sampleTemplate]);
        await mockAjaxRoute(page, '/nr-landingpage/wizard/generate-briefing', []);
        await mockAjaxRoute(page, '/nr-landingpage/wizard/generate-page-fields', {});

        const frame = await navigateToModule(page);
        const modal = await openWizard(page, frame);
        await selectTemplateAndAdvanceToBriefing(modal, page);

        const title = modal.locator('#briefing_title');
        await title.focus();
        await page.keyboard.type('Draft');
        await title.fill('');

        await advanceToPageFields(modal, page);
        await expect(modal.locator('#pf_title')).toHaveValue('');
        await expect(modal.locator('#pf_slug')).toHaveValue('');
    });

    test('template radio group: roving tabindex and arrow keys', async ({ authenticatedPage: page }) => {
        // The cards are created by the module frame's script and live in the
        // top document's modal, so focus is read from that document directly.
        const focusedLabel = () => page.evaluate(() => document.activeElement?.getAttribute('aria-label') ?? null);
        await mockAjaxRoute(page, '/nr-landingpage/wizard/templates', [sampleTemplate, templateWithEmptyCTypes]);

        const frame = await navigateToModule(page);
        const modal = await openWizard(page, frame);

        const group = modal.getByRole('radiogroup');
        await expect(group).toBeVisible({ timeout: 10000 });
        const a = group.getByRole('radio', { name: sampleTemplate.title });
        const b = group.getByRole('radio', { name: templateWithEmptyCTypes.title });
        // Nothing checked yet: the first card is the only tab stop.
        await expect(a).toHaveAttribute('tabindex', '0');
        await expect(b).toHaveAttribute('tabindex', '-1');

        // The modal moves focus to its active footer button once it has
        // opened; wait for that, or it takes the focus back from the card.
        await expect.poll(() => page.evaluate(() => document.activeElement?.classList.contains('t3js-active') ?? false)).toBe(true);
        await a.focus();
        await expect.poll(focusedLabel).toBe(sampleTemplate.title);
        await page.keyboard.press(' ');
        await expect(a).toHaveAttribute('aria-checked', 'true');

        await page.keyboard.press('ArrowDown');
        await expect(b).toHaveAttribute('aria-checked', 'true');
        await expect(a).toHaveAttribute('aria-checked', 'false');
        await expect.poll(focusedLabel).toBe(templateWithEmptyCTypes.title);

        // Wraps around.
        await page.keyboard.press('ArrowRight');
        await expect(a).toHaveAttribute('aria-checked', 'true');
        await expect.poll(focusedLabel).toBe(sampleTemplate.title);

        await page.keyboard.press('ArrowUp');
        await expect(b).toHaveAttribute('aria-checked', 'true');
        await expect.poll(focusedLabel).toBe(templateWithEmptyCTypes.title);

        // Arrow keys stay inside the group: the wizard's carousel reads
        // ArrowRight as "next step" and would slide, bypassing the Next button.
        // Bootstrap adds its transitional classes synchronously, so the state
        // right after the key press shows whether a slide started.
        await page.keyboard.press('ArrowRight');
        const slid = await modal.evaluate((root) => {
            const active = root.querySelector('.carousel-item.active');
            return root.querySelector('.carousel-item-next, .carousel-item-prev, .carousel-item-start, .carousel-item-end') !== null
                || !active || active.querySelector('.template-card') === null;
        });
        expect(slid).toBe(false);
        await expect(a).toHaveAttribute('aria-checked', 'true');
        await expect(modal.locator('.carousel-item.active .template-card').first()).toBeVisible();
    });

    test('wizard shows empty template message when no templates', async ({ authenticatedPage: page }) => {
        await mockAjaxRoute(page, '/nr-landingpage/wizard/templates', []);

        const frame = await navigateToModule(page);
        const modal = await openWizard(page, frame);

        const alert = modal.locator('.alert-warning');
        await expect(alert).toBeVisible({ timeout: 10000 });
    });

    test('content step displays generated sections', async ({ authenticatedPage: page }) => {
        await mockAjaxRoute(page, '/nr-landingpage/wizard/templates', [sampleTemplate]);
        await mockAjaxRoute(page, '/nr-landingpage/wizard/generate-briefing', []);
        await mockAjaxRoute(page, '/nr-landingpage/wizard/generate-page-fields', {
            title: 'Test Page',
            seo_title: 'Test SEO',
            description: 'Test description',
        });
        await mockAjaxRoute(page, '/nr-landingpage/wizard/generate-content', sampleContentSections);

        const frame = await navigateToModule(page);
        const modal = await openWizard(page, frame);

        await selectTemplateAndAdvanceToBriefing(modal, page);
        await advanceToPageFields(modal, page);
        await advanceToContent(modal, page);

        const sectionCard = modal.locator('.card .card-header strong');
        await expect(sectionCard.first()).toContainText('Hero');
        await expect(modal.locator('[id^="section-card-"]')).toHaveCount(2);
        await expect(modal.locator('.alert-info')).toHaveCount(0);
    });

    test('content step shows "no content" when API returns empty sections', async ({ authenticatedPage: page }) => {
        await mockAjaxRoute(page, '/nr-landingpage/wizard/templates', [sampleTemplate]);
        await mockAjaxRoute(page, '/nr-landingpage/wizard/generate-briefing', []);
        await mockAjaxRoute(page, '/nr-landingpage/wizard/generate-page-fields', {
            title: 'Test Page',
            seo_title: 'SEO',
            description: 'Desc',
        });
        await mockAjaxRoute(page, '/nr-landingpage/wizard/generate-content', emptyContentSections);

        const frame = await navigateToModule(page);
        const modal = await openWizard(page, frame);

        await selectTemplateAndAdvanceToBriefing(modal, page);
        await advanceToPageFields(modal, page);
        await advanceToContent(modal, page);

        await expect(modal.locator('.alert-info')).toBeVisible();
        await expect(modal.locator('[id^="section-card-"]')).toHaveCount(0);
    });

    test('content sections have editable header and bodytext fields', async ({ authenticatedPage: page }) => {
        await mockAjaxRoute(page, '/nr-landingpage/wizard/templates', [sampleTemplate]);
        await mockAjaxRoute(page, '/nr-landingpage/wizard/generate-briefing', []);
        await mockAjaxRoute(page, '/nr-landingpage/wizard/generate-page-fields', {
            title: 'Test',
            seo_title: 'SEO',
            description: 'Desc',
        });
        await mockAjaxRoute(page, '/nr-landingpage/wizard/generate-content', sampleContentSections);

        const frame = await navigateToModule(page);
        const modal = await openWizard(page, frame);

        await selectTemplateAndAdvanceToBriefing(modal, page);
        await advanceToPageFields(modal, page);
        await advanceToContent(modal, page);

        const firstCard = modal.locator('#section-card-0');
        await expect(firstCard).toBeVisible();

        const headerInput = firstCard.locator('input.section-header');
        await expect(headerInput).toHaveValue('Welcome to Our Page');
        await expect(firstCard.locator('textarea.section-bodytext')).toBeVisible();
        await expect(firstCard.locator('button', { hasText: /regenerate/i })).toBeVisible();
    });

    test('each content section displays ctype badge', async ({ authenticatedPage: page }) => {
        await mockAjaxRoute(page, '/nr-landingpage/wizard/templates', [sampleTemplate]);
        await mockAjaxRoute(page, '/nr-landingpage/wizard/generate-briefing', []);
        await mockAjaxRoute(page, '/nr-landingpage/wizard/generate-page-fields', {
            title: 'Test',
            seo_title: 'SEO',
            description: 'Desc',
        });
        await mockAjaxRoute(page, '/nr-landingpage/wizard/generate-content', sampleContentSections);

        const frame = await navigateToModule(page);
        const modal = await openWizard(page, frame);

        await selectTemplateAndAdvanceToBriefing(modal, page);
        await advanceToPageFields(modal, page);
        await advanceToContent(modal, page);

        await expect(modal.locator('#section-card-0 .badge.badge-default')).toHaveText('text');
        await expect(modal.locator('#section-card-1 .badge.badge-default')).toHaveText('textmedia');
    });

    // -- Bug 1: SEO fields populated from LLM response --

    test('page fields step shows SEO fields pre-filled from LLM response', async ({ authenticatedPage: page }) => {
        await mockAjaxRoute(page, '/nr-landingpage/wizard/templates', [sampleTemplate]);
        await mockAjaxRoute(page, '/nr-landingpage/wizard/generate-briefing', []);
        await mockAjaxRoute(page, '/nr-landingpage/wizard/generate-page-fields', pageFieldsWithSeo);

        const frame = await navigateToModule(page);
        const modal = await openWizard(page, frame);

        await selectTemplateAndAdvanceToBriefing(modal, page);
        await advanceToPageFields(modal, page);

        await expect(modal.locator('#pf_seo_title')).toHaveValue('Best Landing Page Ever');
        await expect(modal.locator('#pf_description')).toHaveValue('A compelling meta description generated by LLM');
        await expect(modal.locator('#pf_og_title')).toHaveValue('Share This Page');
        await expect(modal.locator('#pf_og_description')).toHaveValue('OG description for social sharing');
    });

    // -- Bug 2: Briefing answers passed to subsequent API calls --

    test('briefing answers are collected and sent to generatePageFields', async ({ authenticatedPage: page }) => {
        await mockAjaxRoute(page, '/nr-landingpage/wizard/templates', [templateWithRequiredBriefing]);
        await mockAjaxRoute(page, '/nr-landingpage/wizard/generate-briefing', sampleBriefingQuestions);

        // Intercept generatePageFields to capture the POST body
        let capturedBody: Record<string, unknown> | null = null;
        await page.route('**/nr-landingpage/wizard/generate-page-fields**', async (route) => {
            const request = route.request();
            const postData = request.postDataJSON?.() ?? JSON.parse(request.postData() || '{}');
            capturedBody = postData;
            await route.fulfill({
                status: 200,
                contentType: 'application/json',
                body: JSON.stringify({ success: true, data: { title: 'Generated', seo_title: 'SEO' } }),
            });
        });

        const frame = await navigateToModule(page);
        const modal = await openWizard(page, frame);

        // Step 1: Select template
        await modal.locator('.template-card').first().click();
        await clickNext(modal, page);

        // Step 2: Briefing — wait for form, then fill in fields
        const titleInput = modal.locator('#briefing_title');
        await expect(titleInput).toBeVisible({ timeout: 15000 });
        await titleInput.fill('My Event Landing Page');

        const q0Input = modal.locator('#briefing_q_0');
        await expect(q0Input).toBeVisible();
        await q0Input.fill('Enterprise developers');

        const q1Input = modal.locator('#briefing_q_1');
        await expect(q1Input).toBeVisible();
        await q1Input.fill('Join our conference');

        // Step 3: Advance to Page Fields
        await advanceToPageFields(modal, page);

        // Verify the captured request body contains briefing answers
        expect(capturedBody).not.toBeNull();
        const briefingAnswers = (capturedBody as Record<string, unknown>)?.briefingAnswers as Record<string, string>;
        expect(briefingAnswers).toBeDefined();
        expect(briefingAnswers.title).toBe('My Event Landing Page');
    });

    // -- Bug 3: Enter key navigates to next step --

    test('pressing Enter on text input advances to next wizard step', async ({ authenticatedPage: page }) => {
        await mockAjaxRoute(page, '/nr-landingpage/wizard/templates', [sampleTemplate]);
        await mockAjaxRoute(page, '/nr-landingpage/wizard/generate-briefing', []);
        await mockAjaxRoute(page, '/nr-landingpage/wizard/generate-page-fields', {
            title: 'Test',
            seo_title: 'SEO',
            description: 'Desc',
        });

        const frame = await navigateToModule(page);
        const modal = await openWizard(page, frame);

        // Navigate to briefing step normally (clicking Next)
        await selectTemplateAndAdvanceToBriefing(modal, page);

        // On briefing step — Title/Topic input is visible
        const titleInput = modal.locator('#briefing_title');
        await expect(titleInput).toBeVisible();

        // Type a title (satisfies any validation)
        await titleInput.fill('My Test Page');

        // Wait for Next to be enabled (briefingMode=optional, should be unlocked)
        await modal.locator('button[name="next"]:not([disabled])').waitFor({ state: 'visible', timeout: 5000 });
        await page.waitForTimeout(800); // Wait for carousel to settle

        // Dispatch Enter keydown on the title input — should advance to page fields
        await page.evaluate(() => {
            const input = document.querySelector('#briefing_title');
            if (input) {
                const event = new KeyboardEvent('keydown', {
                    key: 'Enter', code: 'Enter',
                    bubbles: true, cancelable: true, composed: true,
                });
                input.dispatchEvent(event);
            }
        });

        // Page fields step should be visible now (confirming Enter triggered Next)
        await expect(modal.locator('#pf_title')).toBeVisible({ timeout: 15000 });
    });

    // -- Image suggestions in content step --

    test('content step displays image suggestion cards', async ({ authenticatedPage: page }) => {
        await mockAjaxRoute(page, '/nr-landingpage/wizard/templates', [sampleTemplate]);
        await mockAjaxRoute(page, '/nr-landingpage/wizard/generate-briefing', []);
        await mockAjaxRoute(page, '/nr-landingpage/wizard/generate-page-fields', {
            title: 'Test',
            seo_title: 'SEO',
            description: 'Desc',
        });
        await mockAjaxRoute(page, '/nr-landingpage/wizard/generate-content', contentSectionsWithImages);

        const frame = await navigateToModule(page);
        const modal = await openWizard(page, frame);

        await selectTemplateAndAdvanceToBriefing(modal, page);
        await advanceToPageFields(modal, page);
        await advanceToContent(modal, page);

        // First section should have 2 image cards
        const firstCard = modal.locator('#section-card-0');
        await expect(firstCard).toBeVisible();
        const firstCardImages = firstCard.locator('[data-image-uid]');
        await expect(firstCardImages).toHaveCount(2);
        await expect(firstCardImages.nth(0)).toContainText('Hero Banner');
        await expect(firstCardImages.nth(1)).toContainText('Background Image');

        // Second section should have 1 image card
        const secondCard = modal.locator('#section-card-1');
        const secondCardImages = secondCard.locator('[data-image-uid]');
        await expect(secondCardImages).toHaveCount(1);
        await expect(secondCardImages.first()).toContainText('Product Photo');
    });

    test('content step shows no image cards when images array is empty', async ({ authenticatedPage: page }) => {
        await mockAjaxRoute(page, '/nr-landingpage/wizard/templates', [sampleTemplate]);
        await mockAjaxRoute(page, '/nr-landingpage/wizard/generate-briefing', []);
        await mockAjaxRoute(page, '/nr-landingpage/wizard/generate-page-fields', {
            title: 'Test',
            seo_title: 'SEO',
            description: 'Desc',
        });
        await mockAjaxRoute(page, '/nr-landingpage/wizard/generate-content', sampleContentSections);

        const frame = await navigateToModule(page);
        const modal = await openWizard(page, frame);

        await selectTemplateAndAdvanceToBriefing(modal, page);
        await advanceToPageFields(modal, page);
        await advanceToContent(modal, page);

        // No image cards should exist when images is empty
        await expect(modal.locator('[data-image-uid]')).toHaveCount(0);
    });

    // -- Image selection and assignment --

    test('clicking an image card marks it as selected', async ({ authenticatedPage: page }) => {
        await mockAjaxRoute(page, '/nr-landingpage/wizard/templates', [sampleTemplate]);
        await mockAjaxRoute(page, '/nr-landingpage/wizard/generate-briefing', []);
        await mockAjaxRoute(page, '/nr-landingpage/wizard/generate-page-fields', {
            title: 'Test',
            seo_title: 'SEO',
            description: 'Desc',
        });
        await mockAjaxRoute(page, '/nr-landingpage/wizard/generate-content', contentSectionsWithImages);

        const frame = await navigateToModule(page);
        const modal = await openWizard(page, frame);

        await selectTemplateAndAdvanceToBriefing(modal, page);
        await advanceToPageFields(modal, page);
        await advanceToContent(modal, page);

        // Click the first image card in section 0
        const firstImage = modal.locator('#section-card-0 [data-image-uid="1"]');
        await expect(firstImage).toBeVisible();
        await firstImage.click();

        // Should have selection styling
        await expect(firstImage).toHaveClass(/border-primary/);

        // Second image in same section should NOT be selected
        const secondImage = modal.locator('#section-card-0 [data-image-uid="2"]');
        await expect(secondImage).not.toHaveClass(/border-primary/);
    });

    test('clicking a selected image card deselects it', async ({ authenticatedPage: page }) => {
        await mockAjaxRoute(page, '/nr-landingpage/wizard/templates', [sampleTemplate]);
        await mockAjaxRoute(page, '/nr-landingpage/wizard/generate-briefing', []);
        await mockAjaxRoute(page, '/nr-landingpage/wizard/generate-page-fields', {
            title: 'Test',
            seo_title: 'SEO',
            description: 'Desc',
        });
        await mockAjaxRoute(page, '/nr-landingpage/wizard/generate-content', contentSectionsWithImages);

        const frame = await navigateToModule(page);
        const modal = await openWizard(page, frame);

        await selectTemplateAndAdvanceToBriefing(modal, page);
        await advanceToPageFields(modal, page);
        await advanceToContent(modal, page);

        const imageCard = modal.locator('#section-card-0 [data-image-uid="1"]');
        await expect(imageCard).toBeVisible();

        // Select
        await imageCard.click();
        await expect(imageCard).toHaveClass(/border-primary/);

        // Deselect by clicking again
        await imageCard.click();
        await expect(imageCard).not.toHaveClass(/border-primary/);
    });

    test('selected image imageUid is sent in save request', async ({ authenticatedPage: page }) => {
        await mockAjaxRoute(page, '/nr-landingpage/wizard/templates', [sampleTemplate]);
        await mockAjaxRoute(page, '/nr-landingpage/wizard/generate-briefing', []);
        await mockAjaxRoute(page, '/nr-landingpage/wizard/generate-page-fields', {
            title: 'Test Page',
            seo_title: 'SEO',
            description: 'Desc',
        });
        await mockAjaxRoute(page, '/nr-landingpage/wizard/generate-content', contentSectionsWithImages);

        // Intercept save to capture the POST body
        let capturedSaveBody: Record<string, unknown> | null = null;
        await page.route('**/nr-landingpage/wizard/save**', async (route) => {
            const request = route.request();
            capturedSaveBody = request.postDataJSON?.() ?? JSON.parse(request.postData() || '{}');
            await route.fulfill({
                status: 200,
                contentType: 'application/json',
                body: JSON.stringify({ success: true, data: { pageUid: 42 } }),
            });
        });

        const frame = await navigateToModule(page);
        const modal = await openWizard(page, frame);

        await selectTemplateAndAdvanceToBriefing(modal, page);
        await advanceToPageFields(modal, page);
        await advanceToContent(modal, page);

        // Select image uid=1 in section 0
        const heroImage = modal.locator('#section-card-0 [data-image-uid="1"]');
        await expect(heroImage).toBeVisible();
        await heroImage.click();
        await expect(heroImage).toHaveClass(/border-primary/);

        // Select image uid=3 in section 1
        const productImage = modal.locator('#section-card-1 [data-image-uid="3"]');
        await expect(productImage).toBeVisible();
        await productImage.click();
        await expect(productImage).toHaveClass(/border-primary/);

        // Advance to placement step (step 5)
        await clickNext(modal, page);

        // Fill placement form
        const titleInput = modal.locator('#placement_title');
        await titleInput.waitFor({ state: 'visible', timeout: 15000 });
        const parentInput = modal.locator('#placement_parent');
        await parentInput.fill('1');

        // Click save button
        const saveBtn = modal.locator('button.btn-success');
        await saveBtn.click();

        // Confirm in the confirmation dialog
        const confirmDialog = page.locator('dialog:not([data-severity=""])').last();
        const okButton = confirmDialog.locator('button[name="ok"]');
        await okButton.waitFor({ state: 'visible', timeout: 10000 });
        await okButton.click();

        // Wait for the save request to be captured
        await page.waitForTimeout(2000);

        // Verify the save request was made with imageUid values
        expect(capturedSaveBody).not.toBeNull();
        const sections = (capturedSaveBody as Record<string, unknown>)?.contentSections as Array<Record<string, unknown>>;
        expect(sections).toBeDefined();
        expect(sections.length).toBe(2);

        // Section 0 (Hero, ctype=text) should have imageUid=1
        expect(sections[0].imageUid).toBe(1);
        // Section 1 (Products, ctype=textmedia) should have imageUid=3
        expect(sections[1].imageUid).toBe(3);
    });

    /**
     * The core MultiStepWizard clears its own slide stack on `wizard-dismissed`,
     * and that handler is bound inside initializeEvents(), which runs only after
     * a dynamic import resolves. A modal closed before that — or dismissed from
     * outside — leaves the stack behind, and the next open() appends to it
     * instead of starting fresh, so the user sees the previous run's slide.
     *
     * The leftover state is seeded directly rather than raced into existence:
     * the race is what makes the bug hard to hit, not what the fix addresses.
     * The fix's contract is that open() starts from an empty stack whatever was
     * left there, and that is what this asserts.
     */
    test('a fresh open discards a slide stack left behind by a previous run', async ({ authenticatedPage: page }) => {
        const frame = await navigateToModule(page);
        await mockAjaxRoute(page, '/nr-landingpage/wizard/templates', [sampleTemplate]);
        await mockAjaxRoute(page, '/nr-landingpage/wizard/generate-briefing', sampleBriefingQuestions);

        // The core singleton is created wherever multi-step-wizard.js is first
        // imported, which is our module iframe unless the backend shell got
        // there first. Open once so it exists, then close through Cancel — the
        // path that does reset — so the seeding below is the only leftover.
        const first = await openWizard(page, frame);
        await first.locator('button[name="cancel"]').click();
        await page.locator('dialog').waitFor({ state: 'hidden', timeout: 15000 });

        const STALE = 'STALE-SLIDE-FROM-A-PREVIOUS-RUN';
        const seeded = await Promise.all(
            page.frames().map((f) =>
                f.evaluate((marker) => {
                    const wizard = (globalThis as unknown as {
                        TYPO3?: { MultiStepWizard?: { setup: Record<string, unknown> } };
                    }).TYPO3?.MultiStepWizard;
                    if (!wizard) {
                        return false;
                    }
                    wizard.setup.slides = [{
                        identifier: 'stale-slide',
                        title: marker,
                        content: marker,
                        severity: 0,
                        progressBarTitle: marker,
                        callback: null,
                    }];
                    return true;
                }, STALE).catch(() => false),
            ),
        );
        // A test that seeded nothing would pass without proving anything.
        expect(seeded.some(Boolean), 'no frame exposes TYPO3.MultiStepWizard — the premise is wrong').toBe(true);

        const modal = await openWizard(page, frame);

        // The stale slide must not survive into this run in any form.
        await expect(modal).not.toContainText(STALE);

        // And the wizard must actually be usable, not merely free of the marker:
        // the first step has to render its own content.
        await expect(modal.locator('.template-card').first()).toBeVisible({ timeout: 15000 });
    });
});
