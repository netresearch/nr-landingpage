import {
    test,
    expect,
    navigateToModule,
    getModuleFrame,
    openWizard,
    waitForSlideSettled,
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
    await waitForSlideSettled(modal);
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

    test('Enter on an image card selects it and keeps the content step', async ({ authenticatedPage: page }) => {
        const modal = await openMockedWizard(page, {
            'templates': [sampleTemplate],
            'generate-briefing': [],
            'generate-page-fields': { title: 'Image card', seo_title: 'SEO', description: 'Desc' },
            'generate-content': contentSectionsWithImages,
        });
        await selectTemplateAndAdvanceToBriefing(modal, page);
        await advanceToPageFields(modal, page);
        await advanceToContent(modal, page);

        const imageCard = modal.locator('#section-card-0 [data-image-uid="2"]');
        await expect(imageCard).toHaveAttribute('aria-pressed', 'false');
        await imageCard.focus();
        await expect.poll(() => page.evaluate(() => document.activeElement?.getAttribute('data-image-uid') ?? null)).toBe('2');
        await page.keyboard.press('Enter');

        expect(await wizardMovedAwayFrom(modal, '#section-card-0')).toBe(false);
        await expect(imageCard).toHaveAttribute('aria-pressed', 'true');
        await expect(modal.locator('.carousel-item.active #section-card-0')).toBeVisible();
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
     * Re-generate mode: the module renders the launch button with the page to
     * re-generate, as the page tree's "Re-generate" action does. The page does
     * not exist here; its generation info is mocked like every other reply.
     */
    async function openRegenerateWizard(page: Page): Promise<Locator> {
        const frame = await navigateToModule(page);
        const moduleFrame = page.frames().find((f) => f.url().includes('/module/'));
        expect(moduleFrame).toBeDefined();
        const url = new URL(moduleFrame!.url());
        url.searchParams.set('regeneratePageUid', '42');
        await moduleFrame!.goto(url.toString());
        await expect(frame.locator('#nr-landingpage-launch-wizard')).toHaveAttribute('data-regenerate-page-uid', '42');
        return openWizard(page, frame);
    }

    test('re-generate: Back to the template step stays there and keeps the edited briefing', async ({ authenticatedPage: page }) => {
        let infoRequests = 0;
        await page.route('**/nr-landingpage/wizard/generation-info**', async (route) => {
            infoRequests++;
            await route.fulfill({
                status: 200,
                contentType: 'application/json',
                body: JSON.stringify({ success: true, data: {
                    templateUid: templateWithEmptyCTypes.uid,
                    briefingAnswers: { title: 'Stored title' },
                    configHash: '',
                    generatedAt: 0,
                    sourcePageUid: 0,
                    parentPageId: 0,
                } }),
            });
        });
        await mockAjaxRoute(page, '/nr-landingpage/wizard/templates', [sampleTemplate, templateWithEmptyCTypes]);
        await mockAjaxRoute(page, '/nr-landingpage/wizard/generate-briefing', []);

        const modal = await openRegenerateWizard(page);

        // First render: the stored template is checked and the wizard moves on
        // to the briefing, pre-filled from the generation info.
        const title = modal.locator('#briefing_title');
        await expect(title).toBeVisible({ timeout: 15000 });
        await expect(title).toHaveValue('Stored title');
        expect(infoRequests).toBe(1);

        await title.fill('Edited title');

        await modal.locator('[role="radiogroup"]').evaluate((g) => g.setAttribute('data-stale', '1'));
        await modal.locator('button[name="prev"]:not([disabled])').click();
        const group = modal.locator('[role="radiogroup"]:not([data-stale])');
        await expect(group).toBeVisible({ timeout: 15000 });
        await waitForSlideSettled(modal);

        // (a) The wizard stays on the template step, the stored template still
        // checked, so another one can be picked.
        await expect(modal.locator('.carousel-item.active [role="radiogroup"]:not([data-stale])')).toBeVisible();
        await expect(modal.locator('.carousel-item.active #briefing_title')).toHaveCount(0);
        await expect(group.getByRole('radio', { name: templateWithEmptyCTypes.title })).toHaveAttribute('aria-checked', 'true');
        await expect(modal.locator('button[name="next"]')).toBeEnabled();
        expect(infoRequests).toBe(1);

        // (b) Forward again: the briefing shows what the user typed, not the
        // stored answers.
        await modal.locator('#briefing_title').evaluate((input) => input.setAttribute('data-stale', '1'));
        await clickNext(modal, page);
        const freshTitle = modal.locator('#briefing_title:not([data-stale])');
        await expect(freshTitle).toBeVisible({ timeout: 15000 });
        await expect(freshTitle).toHaveValue('Edited title');
    });

    test('re-generate: opening the wizard again applies the generation info again', async ({ authenticatedPage: page }) => {
        await mockAjaxRoute(page, '/nr-landingpage/wizard/generation-info', {
            templateUid: sampleTemplate.uid,
            briefingAnswers: { title: 'Stored title' },
            parentPageId: 0,
        });
        await mockAjaxRoute(page, '/nr-landingpage/wizard/templates', [sampleTemplate]);
        await mockAjaxRoute(page, '/nr-landingpage/wizard/generate-briefing', []);

        const first = await openRegenerateWizard(page);
        await expect(first.locator('#briefing_title')).toHaveValue('Stored title', { timeout: 15000 });
        await first.locator('button[name="cancel"]').click();
        await page.locator('dialog').waitFor({ state: 'hidden', timeout: 15000 });

        const modal = await openWizard(page, getModuleFrame(page));
        await expect(modal.locator('#briefing_title')).toHaveValue('Stored title', { timeout: 15000 });
        await expect(modal.locator('.carousel-item.active #briefing_title')).toBeVisible();
    });

    /**
     * Count, per endpoint, the wizard replies whose JSON body has been parsed,
     * in every frame (wizard.js runs in the module frame). A renderer resumes
     * right after that parse, in the same task, so once a count is reached the
     * renderer has reacted to that reply — or has decided not to.
     */
    async function countParsedReplies(page: Page): Promise<void> {
        await page.addInitScript(() => {
            const parse = Response.prototype.json;
            const counts: Record<string, number> = {};
            (window as any).__parsedReplies = counts;
            Response.prototype.json = async function (this: Response) {
                try {
                    return await parse.call(this);
                } finally {
                    const endpoint = new URL(this.url).pathname.split('/').pop() ?? '';
                    counts[endpoint] = (counts[endpoint] ?? 0) + 1;
                }
            };
        });
    }

    function parsedReplies(page: Page, endpoint: string): Promise<number> {
        return getModuleFrame(page).locator('body').evaluate((_, e) => (window as any).__parsedReplies?.[e] ?? 0, endpoint);
    }

    /**
     * Route `path` so that its first request waits until `release()` is
     * called; `first` resolves once that request has arrived. With a `status`
     * other than 200, that first request fails with it.
     */
    async function holdFirstReply(page: Page, path: string, data: unknown, status = 200): Promise<{ first: Promise<void>; release: () => void; count: () => number }> {
        let release!: () => void;
        const held = new Promise<void>((resolve) => { release = resolve; });
        let arrived!: () => void;
        const first = new Promise<void>((resolve) => { arrived = resolve; });
        let requests = 0;
        await page.route('**' + path + '**', async (route) => {
            requests++;
            if (requests === 1) {
                arrived();
                await held;
                if (status !== 200) {
                    await route.fulfill({ status, contentType: 'application/json', body: JSON.stringify({ success: false, error: 'Held reply failed' }) });
                    return;
                }
            }
            await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ success: true, data }) });
        });
        return { first, release, count: () => requests };
    }

    /**
     * Route `path` so that its first two requests (one per wizard) each wait
     * until `releases[i]()` is called; `arrivals[i]` resolves once request i
     * has arrived. Later requests are answered at once.
     */
    async function holdFirstTwoReplies(page: Page, path: string, data: unknown): Promise<{ arrivals: Array<Promise<void>>; releases: Array<() => void>; count: () => number }> {
        const releases: Array<() => void> = [];
        const arrivals: Array<Promise<void>> = [];
        const arrived: Array<() => void> = [];
        for (let i = 0; i < 2; i++) {
            arrivals.push(new Promise<void>((resolve) => { arrived.push(resolve); }));
        }
        let requests = 0;
        await page.route('**' + path + '**', async (route) => {
            const mine = requests++;
            if (mine < 2) {
                await new Promise<void>((resolve) => { releases[mine] = resolve; arrived[mine](); });
            }
            await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ success: true, data }) });
        });
        return { arrivals, releases, count: () => requests };
    }

    /** The wizard state in the module frame's realm, where wizard.js runs. */
    function wizardState(page: Page): Promise<{ template: number | null; contentSections: Array<Record<string, unknown>> }> {
        return getModuleFrame(page).locator('body').evaluate(async () => {
            const state = (await import('@netresearch/nr-landingpage/wizard-state.js')).default;
            return JSON.parse(JSON.stringify({ template: state.getTemplate()?.uid ?? null, contentSections: state.getContentSections() }));
        });
    }

    async function closeWizard(page: Page): Promise<void> {
        await page.keyboard.press('Escape');
        await page.locator('dialog').waitFor({ state: 'hidden', timeout: 15000 });
    }

    /** From the template step of an open wizard to the content step. */
    async function walkToContent(modal: Locator, page: Page): Promise<void> {
        await modal.locator('.template-card').first().click();
        await clickNext(modal, page);
        await modal.locator('.carousel-item.active #briefing_title').waitFor({ state: 'visible', timeout: 15000 });
        await clickNext(modal, page);
        await modal.locator('.carousel-item.active #pf_title').waitFor({ state: 'visible', timeout: 15000 });
        await clickNext(modal, page);
        await modal.locator('.carousel-item.active #section-card-0').waitFor({ state: 'visible', timeout: 15000 });
        await waitForSlideSettled(modal);
    }

    /**
     * From the template step of an open wizard, check the first template and
     * press Next until the given step has been entered (not waiting for it
     * to render).
     */
    async function nextToStep(modal: Locator, page: Page, step: 'briefing' | 'page-fields' | 'content'): Promise<void> {
        await modal.locator('.template-card').first().click();
        await clickNext(modal, page);
        if (step !== 'briefing') {
            await modal.locator('.carousel-item.active #briefing_title').waitFor({ state: 'visible', timeout: 15000 });
            await clickNext(modal, page);
        }
        if (step === 'content') {
            await modal.locator('.carousel-item.active #pf_title').waitFor({ state: 'visible', timeout: 15000 });
            await clickNext(modal, page);
        }
    }

    /** From the content step to the confirmed save. */
    async function saveFromContent(modal: Locator, page: Page): Promise<void> {
        await clickNext(modal, page);
        await modal.locator('.carousel-item.active #placement_parent').waitFor({ state: 'visible', timeout: 15000 });
        await modal.locator('#placement_parent').fill('1');
        await modal.locator('button.btn-success').click();
        const ok = page.locator('dialog:not([data-severity=""])').last().locator('button[name="ok"]');
        await ok.waitFor({ state: 'visible', timeout: 10000 });
        await ok.click();
    }

    async function mockUpToContent(page: Page, content: unknown = sampleContentSections): Promise<void> {
        await mockAjaxRoute(page, '/nr-landingpage/wizard/templates', [sampleTemplate]);
        await mockAjaxRoute(page, '/nr-landingpage/wizard/generate-briefing', []);
        await mockAjaxRoute(page, '/nr-landingpage/wizard/generate-page-fields', { title: 'T', slug: 't' });
        await mockAjaxRoute(page, '/nr-landingpage/wizard/generate-content', content);
    }

    const staleSection = { section: 'STALE', ctype: 'text', header: 'STALE header', subheader: '', bodytext: '<p>stale</p>' };

    test('a regenerated section of a closed wizard is not written into the one opened after it', async ({ authenticatedPage: page }) => {
        await countParsedReplies(page);
        await mockUpToContent(page);
        const regenerate = await holdFirstReply(page, '/nr-landingpage/wizard/regenerate-section', staleSection);
        let saved: { contentSections: Array<Record<string, unknown>> } | null = null;
        await page.route('**/nr-landingpage/wizard/save**', async (route) => {
            saved = route.request().postDataJSON();
            await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ success: true, data: { pageUid: 0 } }) });
        });

        const first = await openWizard(page, await navigateToModule(page));
        await walkToContent(first, page);
        await first.locator('#section-card-0 .card-header button').first().click();
        await regenerate.first;
        await closeWizard(page);

        const modal = await openWizard(page, getModuleFrame(page));
        await walkToContent(modal, page);
        regenerate.release();
        await expect.poll(() => parsedReplies(page, 'regenerate-section'), { timeout: 15000 }).toBe(1);

        expect((await wizardState(page)).contentSections[0].header).toBe(sampleContentSections.sections[0].header);
        await saveFromContent(modal, page);
        await expect.poll(() => saved !== null, { timeout: 15000 }).toBe(true);
        expect(saved!.contentSections[0].header).toBe(sampleContentSections.sections[0].header);
    });

    test('a pending section regeneration of a closed wizard does not block Save in the one opened after it', async ({ authenticatedPage: page }) => {
        await mockUpToContent(page);
        const regenerate = await holdFirstReply(page, '/nr-landingpage/wizard/regenerate-section', staleSection);
        let saves = 0;
        await page.route('**/nr-landingpage/wizard/save**', async (route) => {
            saves++;
            await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ success: true, data: { pageUid: 0 } }) });
        });

        const first = await openWizard(page, await navigateToModule(page));
        await walkToContent(first, page);
        await first.locator('#section-card-0 .card-header button').first().click();
        await regenerate.first;
        await closeWizard(page);

        const modal = await openWizard(page, getModuleFrame(page));
        await walkToContent(modal, page);
        await saveFromContent(modal, page);
        await expect.poll(() => saves, { timeout: 10000 }).toBe(1);
        regenerate.release();
    });

    test('a section regeneration of a closed wizard finishing does not unlock a second one in the wizard opened after it', async ({ authenticatedPage: page }) => {
        await countParsedReplies(page);
        await mockUpToContent(page);
        // The first two requests (one per wizard) are held until released.
        const { arrivals, releases, count } = await holdFirstTwoReplies(page, '/nr-landingpage/wizard/regenerate-section', staleSection);

        const first = await openWizard(page, await navigateToModule(page));
        await walkToContent(first, page);
        await first.locator('#section-card-0 .card-header button').first().click();
        await arrivals[0];
        await closeWizard(page);

        const modal = await openWizard(page, getModuleFrame(page));
        await walkToContent(modal, page);
        await modal.locator('#section-card-0 .card-header button').first().click();
        await arrivals[1];

        // The closed wizard's request finishes while the open wizard's is
        // still running: the open wizard must still count as busy.
        releases[0]();
        await expect.poll(() => parsedReplies(page, 'regenerate-section'), { timeout: 15000 }).toBe(1);
        // Not busy, this click would send its request at once — before the
        // open wizard's own request is released below.
        await modal.locator('#section-card-1 .card-header button').first().click();
        releases[1]();
        await expect.poll(() => parsedReplies(page, 'regenerate-section'), { timeout: 15000 }).toBe(2);
        expect(count()).toBe(2);
    });

    test('a section regeneration shows its loading spinner in the section card', async ({ authenticatedPage: page }) => {
        await mockUpToContent(page);
        const regenerate = await holdFirstReply(page, '/nr-landingpage/wizard/regenerate-section', staleSection);

        const modal = await openWizard(page, await navigateToModule(page));
        await walkToContent(modal, page);
        await modal.locator('#section-card-0 .card-header button').first().click();
        await regenerate.first;

        const status = modal.locator('#section-card-0 .card-body [role="status"]');
        await expect(status).toBeVisible();
        await expect(status.locator('typo3-backend-spinner')).toHaveCount(1);
        regenerate.release();
        await expect(modal.locator('#section-card-0 .card-body [role="status"]')).toHaveCount(0, { timeout: 15000 });
    });

    test('a section regeneration reply after leaving the content step and coming back is dropped', async ({ authenticatedPage: page }) => {
        await countParsedReplies(page);
        await mockUpToContent(page);
        const regenerate = await holdFirstReply(page, '/nr-landingpage/wizard/regenerate-section', staleSection);
        let saved: { contentSections: Array<Record<string, unknown>> } | null = null;
        await page.route('**/nr-landingpage/wizard/save**', async (route) => {
            saved = route.request().postDataJSON();
            await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ success: true, data: { pageUid: 0 } }) });
        });

        const modal = await openWizard(page, await navigateToModule(page));
        await walkToContent(modal, page);
        await modal.locator('#section-card-0 .card-header button').first().click();
        await regenerate.first;

        // Back to the page fields and Next again: the content step renders
        // its sections anew, from a new generate-content reply.
        await modal.locator('#section-card-0').evaluate((card) => card.setAttribute('data-stale', '1'));
        await modal.locator('button[name="prev"]:not([disabled])').click();
        await modal.locator('.carousel-item.active #pf_title').waitFor({ state: 'visible', timeout: 15000 });
        await clickNext(modal, page);
        await modal.locator('.carousel-item.active #section-card-0:not([data-stale])').waitFor({ state: 'visible', timeout: 15000 });
        await waitForSlideSettled(modal);

        regenerate.release();
        await expect.poll(() => parsedReplies(page, 'regenerate-section'), { timeout: 15000 }).toBe(1);
        expect((await wizardState(page)).contentSections[0].header).toBe(sampleContentSections.sections[0].header);

        // The request is over for this wizard: Save works, with the sections
        // the user sees.
        await saveFromContent(modal, page);
        await expect.poll(() => saved !== null, { timeout: 15000 }).toBe(true);
        expect(saved!.contentSections[0].header).toBe(sampleContentSections.sections[0].header);
    });

    test('Regenerate on a section card replaced by Back and Next runs while the old card\'s request is pending', async ({ authenticatedPage: page }) => {
        await countParsedReplies(page);
        await mockUpToContent(page);
        const freshSection = { section: 'FRESH', ctype: 'text', header: 'FRESH header', subheader: '', bodytext: '<p>fresh</p>' };
        // The first request (the old card's) is held; later ones reply at once.
        let release!: () => void;
        const held = new Promise<void>((resolve) => { release = resolve; });
        let arrived!: () => void;
        const first = new Promise<void>((resolve) => { arrived = resolve; });
        let requests = 0;
        await page.route('**/nr-landingpage/wizard/regenerate-section**', async (route) => {
            const mine = ++requests;
            if (mine === 1) {
                arrived();
                await held;
            }
            const data = mine === 1 ? staleSection : freshSection;
            await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ success: true, data }) });
        });

        const modal = await openWizard(page, await navigateToModule(page));
        await walkToContent(modal, page);
        await modal.locator('#section-card-0 .card-header button').first().click();
        await first;

        await modal.locator('#section-card-0').evaluate((card) => card.setAttribute('data-stale', '1'));
        await modal.locator('button[name="prev"]:not([disabled])').click();
        await modal.locator('.carousel-item.active #pf_title').waitFor({ state: 'visible', timeout: 15000 });
        await clickNext(modal, page);
        await modal.locator('.carousel-item.active #section-card-0:not([data-stale])').waitFor({ state: 'visible', timeout: 15000 });
        await waitForSlideSettled(modal);

        // The old card is gone and its reply will be dropped: Regenerate on
        // the new card must not wait for it.
        await modal.locator('#section-card-1 .card-header button').first().click();
        await expect.poll(() => requests, { timeout: 10000 }).toBe(2);
        await expect(modal.locator('#section-card-1 .section-header')).toHaveValue(freshSection.header, { timeout: 15000 });

        release();
        await expect.poll(() => parsedReplies(page, 'regenerate-section'), { timeout: 15000 }).toBe(2);
        const sections = (await wizardState(page)).contentSections;
        expect(sections[0].header).toBe(sampleContentSections.sections[0].header);
        expect(sections[1].header).toBe(freshSection.header);
        await expect(modal.locator('#section-card-0 .section-header')).toHaveValue(sampleContentSections.sections[0].header);
    });

    // Sections that declare "no image chosen" (imageUid 0): a recommended image
    // in a reply is then chosen automatically, which is what a stale reply
    // must not do to the next wizard's state.
    const contentWithImageChoice = {
        sections: [0, 1].map((i) => ({
            section: 'Section ' + i, ctype: 'textmedia', header: 'Header ' + i, subheader: '',
            bodytext: '<p>Body ' + i + '</p>', imagePrompt: 'prompt ' + i, imageKeywords: ['beach'], imageUid: 0,
        })),
        images: [[], []],
        aiGenerationAvailable: true,
        hasImageTask: true,
    };

    for (const action of [
        { name: 'image search', path: '/nr-landingpage/wizard/search-images', endpoint: 'search-images', button: 'Search', reply: { images: [{ uid: 21, name: 'stale.jpg', title: 'Stale', recommended: true }] } },
        { name: 'image generation', path: '/nr-landingpage/wizard/generate-image', endpoint: 'generate-image', button: 'Generate with AI', reply: { image: { uid: 21, name: 'stale.png', title: 'Stale', recommended: true, generated: true } } },
    ]) {
        test(`an ${action.name} reply for a closed wizard does not choose an image in the one opened after it`, async ({ authenticatedPage: page }) => {
            await countParsedReplies(page);
            await mockUpToContent(page, contentWithImageChoice);
            const held = await holdFirstReply(page, action.path, action.reply);

            const first = await openWizard(page, await navigateToModule(page));
            await walkToContent(first, page);
            await first.locator('#section-card-0 .border-top').getByRole('button', { name: action.button }).click();
            await held.first;
            await closeWizard(page);

            const modal = await openWizard(page, getModuleFrame(page));
            await walkToContent(modal, page);
            expect((await wizardState(page)).contentSections[0].imageUid).toBe(0);

            held.release();
            await expect.poll(() => parsedReplies(page, action.endpoint), { timeout: 15000 }).toBe(1);
            expect((await wizardState(page)).contentSections[0].imageUid).toBe(0);
            await expect(modal.locator('[data-image-uid="21"]')).toHaveCount(0);
        });
    }

    test('an image search reply for sections replaced by Back and Next does not choose an image in the new ones', async ({ authenticatedPage: page }) => {
        await countParsedReplies(page);
        await mockUpToContent(page, contentWithImageChoice);
        const held = await holdFirstReply(page, '/nr-landingpage/wizard/search-images', { images: [{ uid: 21, name: 'stale.jpg', title: 'Stale', recommended: true }] });

        const modal = await openWizard(page, await navigateToModule(page));
        await walkToContent(modal, page);
        await modal.locator('#section-card-0 .border-top').getByRole('button', { name: 'Search' }).click();
        await held.first;

        // Back to the page fields and Next again: the content step renders
        // its sections anew, from a new generate-content reply.
        await modal.locator('#section-card-0').evaluate((card) => card.setAttribute('data-stale', '1'));
        await modal.locator('button[name="prev"]:not([disabled])').click();
        await modal.locator('.carousel-item.active #pf_title').waitFor({ state: 'visible', timeout: 15000 });
        await clickNext(modal, page);
        await modal.locator('.carousel-item.active #section-card-0:not([data-stale])').waitFor({ state: 'visible', timeout: 15000 });
        await waitForSlideSettled(modal);
        expect((await wizardState(page)).contentSections[0].imageUid).toBe(0);

        held.release();
        await expect.poll(() => parsedReplies(page, 'search-images'), { timeout: 15000 }).toBe(1);
        expect((await wizardState(page)).contentSections[0].imageUid).toBe(0);
        await expect(modal.locator('[data-image-uid="21"]')).toHaveCount(0);
    });

    test('a save reply for a closed wizard does not close the one opened after it', async ({ authenticatedPage: page }) => {
        await countParsedReplies(page);
        await mockUpToContent(page);
        const save = await holdFirstReply(page, '/nr-landingpage/wizard/save', { pageUid: 5 });

        const frame = await navigateToModule(page);
        const first = await openWizard(page, frame);
        await walkToContent(first, page);
        await saveFromContent(first, page);
        await save.first;
        await expect(page.locator('dialog:visible')).toHaveCount(1);
        await closeWizard(page);

        const modal = await openWizard(page, getModuleFrame(page));
        await expect(modal.locator('.carousel-item.active .template-card')).toHaveCount(1, { timeout: 15000 });
        await waitForSlideSettled(modal);

        // Record the two calls a save reply makes to leave the wizard: both
        // happen synchronously in the reply's continuation, so they are in
        // the record once the reply has been parsed. The navigation itself
        // and the modal's closing animation would come later.
        await page.evaluate(() => {
            const container = (window as any).TYPO3.Backend.ContentContainer;
            const setUrl = container.setUrl;
            (window as any).__setUrlCalls = [];
            container.setUrl = function (...args: unknown[]) {
                (window as any).__setUrlCalls.push(args[0]);
                return setUrl.apply(this, args);
            };
        });
        await getModuleFrame(page).locator('body').evaluate(async () => {
            const modal = (await import('@typo3/backend/modal.js')).default;
            const dismiss = modal.dismiss;
            (window as any).__dismissCalls = 0;
            modal.dismiss = function (...args: unknown[]) {
                (window as any).__dismissCalls++;
                return dismiss.apply(this, args);
            };
        });

        save.release();
        await expect.poll(() => parsedReplies(page, 'save'), { timeout: 15000 }).toBe(1);

        expect(await getModuleFrame(page).locator('body').evaluate(() => (window as any).__dismissCalls)).toBe(0);
        expect(await page.evaluate(() => (window as any).__setUrlCalls)).toEqual([]);
        await expect(modal.locator('.carousel-item.active .template-card')).toHaveCount(1);
    });

    test('a save of a closed wizard finishing does not unlock a second save in the wizard opened after it', async ({ authenticatedPage: page }) => {
        await countParsedReplies(page);
        await mockUpToContent(page);
        // The first two save requests (one per wizard) are held until
        // released. pageUid 0: no reply navigates to the page module.
        const { arrivals, releases, count } = await holdFirstTwoReplies(page, '/nr-landingpage/wizard/save', { pageUid: 0 });

        const first = await openWizard(page, await navigateToModule(page));
        await walkToContent(first, page);
        await saveFromContent(first, page);
        await arrivals[0];
        await expect(page.locator('dialog:visible')).toHaveCount(1);
        await closeWizard(page);

        const modal = await openWizard(page, getModuleFrame(page));
        await walkToContent(modal, page);
        await saveFromContent(modal, page);
        await arrivals[1];

        // The closed wizard's save finishes while the open wizard's is still
        // running: the open wizard must still count as busy.
        releases[0]();
        await expect.poll(() => parsedReplies(page, 'save'), { timeout: 15000 }).toBe(1);
        // Not busy, this confirmed Save would send its request at once —
        // before the open wizard's own request is released below.
        await modal.locator('button.btn-success').click();
        const ok = page.locator('dialog:not([data-severity=""])').last().locator('button[name="ok"]');
        await ok.waitFor({ state: 'visible', timeout: 10000 });
        await ok.click();
        releases[1]();
        await expect.poll(() => parsedReplies(page, 'save'), { timeout: 15000 }).toBe(2);
        expect(count()).toBe(2);
    });

    // A slide renderer whose wizard was closed, and not opened again, gets its
    // reply after core has reset its own state on `wizard-dismissed`: the
    // carousel it would lock or unlock Next on no longer exists.
    for (const step of ['briefing', 'page-fields', 'content'] as const) {
        for (const status of [200, 500]) {
            test(`a ${step} reply (${status}) for a wizard closed and not opened again throws no error`, async ({ authenticatedPage: page }) => {
                await countParsedReplies(page);
                // Uncaught errors only: a 500 reply also logs a failed resource
                // load to the console by design.
                const errors: string[] = [];
                page.on('pageerror', (error) => errors.push(error.message));
                const replies: Record<string, [string, unknown]> = {
                    'briefing': ['generate-briefing', [{ label: 'Q', type: 'text' }]],
                    'page-fields': ['generate-page-fields', { title: 'T', slug: 't' }],
                    'content': ['generate-content', sampleContentSections],
                };
                // Briefing mode "optional": a briefing error still unlocks Next.
                await mockAjaxRoute(page, '/nr-landingpage/wizard/templates', [sampleTemplate]);
                for (const [name, [endpoint, data]] of Object.entries(replies)) {
                    if (name !== step) {
                        await mockAjaxRoute(page, '/nr-landingpage/wizard/' + endpoint, data);
                    }
                }
                const [endpoint, data] = replies[step];
                const held = await holdFirstReply(page, '/nr-landingpage/wizard/' + endpoint, data, status);

                const modal = await openWizard(page, await navigateToModule(page));
                await nextToStep(modal, page, step);
                await held.first;
                await closeWizard(page);

                held.release();
                await expect.poll(() => parsedReplies(page, endpoint), { timeout: 15000 }).toBe(1);
                // The renderer resumes in the task that parsed the reply; one
                // more round trip lets an error it threw reach the test.
                await parsedReplies(page, endpoint);
                expect(errors).toEqual([]);
            });
        }
    }

    // The same late replies after the wizard was opened again: the new wizard
    // starts on the template step with nothing checked, so Next is locked,
    // and no content is loaded.
    for (const step of ['page-fields', 'content'] as const) {
        for (const status of [200, 500]) {
            test(`a ${step} reply (${status}) for a closed wizard changes nothing in the one opened after it`, async ({ authenticatedPage: page }) => {
                await countParsedReplies(page);
                const endpoint = step === 'page-fields' ? 'generate-page-fields' : 'generate-content';
                await mockUpToContent(page);
                const held = await holdFirstReply(page, '/nr-landingpage/wizard/' + endpoint, step === 'page-fields' ? { title: 'T', slug: 't' } : sampleContentSections, status);

                const first = await openWizard(page, await navigateToModule(page));
                await nextToStep(first, page, step);
                await held.first;
                await closeWizard(page);

                const modal = await openWizard(page, getModuleFrame(page));
                await expect(modal.locator('.carousel-item.active .template-card')).toHaveCount(1, { timeout: 15000 });
                await waitForSlideSettled(modal);
                await expect(modal.locator('button[name="next"]')).toBeDisabled();

                held.release();
                await expect.poll(() => parsedReplies(page, endpoint), { timeout: 15000 }).toBe(1);

                await expect(modal.locator('.carousel-item.active')).toHaveAttribute('data-bs-slide', 'landing-page-template');
                await expect(modal.locator('button[name="next"]')).toBeDisabled();
                expect((await wizardState(page)).contentSections).toEqual([]);
            });
        }
    }

    test('a briefing reply that arrives after Back does not lock Next on the template step', async ({ authenticatedPage: page }) => {
        await countParsedReplies(page);
        await mockAjaxRoute(page, '/nr-landingpage/wizard/templates', [templateWithRequiredBriefing]);
        // briefingMode "required" and an empty title: a finished briefing
        // render locks Next.
        const briefing = await holdFirstReply(page, '/nr-landingpage/wizard/generate-briefing', [{ label: 'Q', type: 'text' }]);

        const modal = await openWizard(page, await navigateToModule(page));
        await modal.locator('.template-card').first().click();
        await clickNext(modal, page);
        await briefing.first;
        await waitForSlideSettled(modal);
        await modal.locator('[role="radiogroup"]').evaluate((g) => g.setAttribute('data-stale', '1'));
        await modal.locator('button[name="prev"]:not([disabled])').click();
        const group = modal.locator('[role="radiogroup"]:not([data-stale])');
        await expect(group.locator('.template-card[aria-checked="true"]')).toHaveCount(1, { timeout: 15000 });
        await waitForSlideSettled(modal);
        await expect(modal.locator('button[name="next"]')).toBeEnabled();

        briefing.release();
        await expect.poll(() => parsedReplies(page, 'generate-briefing'), { timeout: 15000 }).toBe(1);
        await expect(modal.locator('.carousel-item.active')).toHaveAttribute('data-bs-slide', 'landing-page-template');
        await expect(modal.locator('button[name="next"]')).toBeEnabled();
    });

    test('the briefing of a template left with Back does not replace the briefing of the one picked next', async ({ authenticatedPage: page }) => {
        await countParsedReplies(page);
        const a = { ...templateWithRequiredBriefing, uid: 11, title: 'Template A' };
        const b = { ...templateWithRequiredBriefing, uid: 12, title: 'Template B' };
        await mockAjaxRoute(page, '/nr-landingpage/wizard/templates', [a, b]);
        let release!: () => void;
        const held = new Promise<void>((resolve) => { release = resolve; });
        let arrived!: () => void;
        const firstForA = new Promise<void>((resolve) => { arrived = resolve; });
        await page.route('**/nr-landingpage/wizard/generate-briefing**', async (route) => {
            const uid = route.request().postDataJSON()?.templateUid;
            if (uid === a.uid) {
                arrived();
                await held;
            }
            const data = [{ label: 'Question for ' + (uid === a.uid ? 'A' : 'B'), type: 'text' }];
            await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ success: true, data }) });
        });

        const modal = await openWizard(page, await navigateToModule(page));
        await modal.getByRole('radio', { name: 'Template A' }).click();
        await clickNext(modal, page);
        await firstForA;
        await waitForSlideSettled(modal);
        await modal.locator('[role="radiogroup"]').evaluate((g) => g.setAttribute('data-stale', '1'));
        await modal.locator('button[name="prev"]:not([disabled])').click();
        const group = modal.locator('[role="radiogroup"]:not([data-stale])');
        await expect(group).toBeVisible({ timeout: 15000 });
        await waitForSlideSettled(modal);
        await group.getByRole('radio', { name: 'Template B' }).click();
        await clickNext(modal, page);
        await expect(modal.locator('.carousel-item.active')).toContainText('Question for B', { timeout: 15000 });
        await waitForSlideSettled(modal);

        release();
        await expect.poll(() => parsedReplies(page, 'generate-briefing'), { timeout: 15000 }).toBe(2);
        expect((await wizardState(page)).template).toBe(b.uid);
        await expect(modal.locator('.carousel-item.active')).toContainText('Question for B');
        await expect(modal.locator('.carousel-item.active')).not.toContainText('Question for A');
    });

    test('re-generate: a reply for a closed wizard does not move the one opened after it', async ({ authenticatedPage: page }) => {
        await countParsedReplies(page);
        // The first generation-info request is held until the test releases
        // it, so its renderer is still waiting when the wizard is closed and
        // opened again.
        let release!: () => void;
        const held = new Promise<void>((resolve) => { release = resolve; });
        let arrived!: () => void;
        const firstRequest = new Promise<void>((resolve) => { arrived = resolve; });
        let infoRequests = 0;
        await page.route('**/nr-landingpage/wizard/generation-info**', async (route) => {
            infoRequests++;
            if (infoRequests === 1) {
                arrived();
                await held;
            }
            await route.fulfill({
                status: 200,
                contentType: 'application/json',
                body: JSON.stringify({ success: true, data: {
                    templateUid: sampleTemplate.uid,
                    briefingAnswers: { title: 'Stored title' },
                    parentPageId: 0,
                } }),
            });
        });
        let pageFieldsRequests = 0;
        await page.route('**/nr-landingpage/wizard/generate-page-fields**', async (route) => {
            pageFieldsRequests++;
            await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ success: true, data: { title: 'PF', slug: 'pf' } }) });
        });
        await mockAjaxRoute(page, '/nr-landingpage/wizard/templates', [sampleTemplate]);
        await mockAjaxRoute(page, '/nr-landingpage/wizard/generate-briefing', []);

        await openRegenerateWizard(page);
        await firstRequest;
        await page.keyboard.press('Escape');
        await page.locator('dialog').waitFor({ state: 'hidden', timeout: 15000 });

        const modal = await openWizard(page, getModuleFrame(page));
        await expect(modal.locator('.carousel-item.active #briefing_title')).toHaveValue('Stored title', { timeout: 15000 });
        await waitForSlideSettled(modal);
        expect(await parsedReplies(page, 'generation-info')).toBe(1);

        // Now the closed wizard's renderer gets its reply. Unguarded, it
        // checks the stored template and presses Next in the open wizard
        // straight away: the carousel starts sliding to the page fields.
        release();
        await expect.poll(() => parsedReplies(page, 'generation-info'), { timeout: 15000 }).toBe(2);

        await waitForSlideSettled(modal);
        await expect(modal.locator('.carousel-item.active')).toHaveAttribute('data-bs-slide', 'landing-page-briefing');
        await expect(modal.locator('.carousel-item.active #briefing_title')).toHaveValue('Stored title');
        expect(pageFieldsRequests).toBe(0);
        expect(infoRequests).toBe(2);
    });

    test('a briefing reply for a closed wizard does not unlock Next in the one opened after it', async ({ authenticatedPage: page }) => {
        await countParsedReplies(page);
        let release!: () => void;
        const held = new Promise<void>((resolve) => { release = resolve; });
        let arrived!: () => void;
        const firstRequest = new Promise<void>((resolve) => { arrived = resolve; });
        let briefingRequests = 0;
        await page.route('**/nr-landingpage/wizard/generate-briefing**', async (route) => {
            briefingRequests++;
            if (briefingRequests === 1) {
                arrived();
                await held;
            }
            await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ success: true, data: [] }) });
        });
        // Briefing mode "optional": a finished briefing render unlocks Next.
        await mockAjaxRoute(page, '/nr-landingpage/wizard/templates', [sampleTemplate]);

        const first = await openWizard(page, await navigateToModule(page));
        await first.locator('.template-card').first().click();
        await clickNext(first, page);
        await firstRequest;
        await page.keyboard.press('Escape');
        await page.locator('dialog').waitFor({ state: 'hidden', timeout: 15000 });

        // The new wizard starts on the template step with nothing checked, so
        // Next is locked.
        const modal = await openWizard(page, getModuleFrame(page));
        await expect(modal.locator('.carousel-item.active .template-card')).toHaveCount(1, { timeout: 15000 });
        await waitForSlideSettled(modal);
        await expect(modal.locator('button[name="next"]')).toBeDisabled();
        expect(await parsedReplies(page, 'generate-briefing')).toBe(0);

        release();
        await expect.poll(() => parsedReplies(page, 'generate-briefing'), { timeout: 15000 }).toBe(1);

        await expect(modal.locator('.carousel-item.active')).toHaveAttribute('data-bs-slide', 'landing-page-template');
        await expect(modal.locator('.template-card[aria-checked="true"]')).toHaveCount(0);
        await expect(modal.locator('button[name="next"]')).toBeDisabled();
    });

    test('Back to the template step puts focus on the checked template', async ({ authenticatedPage: page }) => {
        await mockAjaxRoute(page, '/nr-landingpage/wizard/templates', [sampleTemplate, templateWithEmptyCTypes]);
        await mockAjaxRoute(page, '/nr-landingpage/wizard/generate-briefing', []);

        const modal = await openWizard(page, await navigateToModule(page));
        await modal.getByRole('radio', { name: templateWithEmptyCTypes.title }).click();
        await clickNext(modal, page);
        await modal.locator('#briefing_title').waitFor({ state: 'visible', timeout: 15000 });

        // Back disables the Back button on the first step, which takes the
        // focus away from it.
        await modal.locator('[role="radiogroup"]').evaluate((g) => g.setAttribute('data-stale', '1'));
        await modal.locator('button[name="prev"]:not([disabled])').click();
        const group = modal.locator('[role="radiogroup"]:not([data-stale])');
        await expect(group).toBeVisible({ timeout: 15000 });
        await expect(group.getByRole('radio', { name: templateWithEmptyCTypes.title })).toBeFocused();
    });

    test('re-generate: Back to the template step puts focus on the checked template', async ({ authenticatedPage: page }) => {
        await mockAjaxRoute(page, '/nr-landingpage/wizard/generation-info', {
            templateUid: templateWithEmptyCTypes.uid,
            briefingAnswers: { title: 'Stored title' },
            parentPageId: 0,
        });
        await mockAjaxRoute(page, '/nr-landingpage/wizard/templates', [sampleTemplate, templateWithEmptyCTypes]);
        await mockAjaxRoute(page, '/nr-landingpage/wizard/generate-briefing', []);

        const modal = await openRegenerateWizard(page);
        await expect(modal.locator('.carousel-item.active #briefing_title')).toHaveValue('Stored title', { timeout: 15000 });
        await waitForSlideSettled(modal);

        await modal.locator('[role="radiogroup"]').evaluate((g) => g.setAttribute('data-stale', '1'));
        await modal.locator('button[name="prev"]:not([disabled])').click();
        const group = modal.locator('[role="radiogroup"]:not([data-stale])');
        await expect(group).toBeVisible({ timeout: 15000 });
        await expect(group.getByRole('radio', { name: templateWithEmptyCTypes.title })).toBeFocused();
    });

    // Core locks Next as each step slides in, which disables the button that
    // has focus. A step that loads its content keeps Next locked while it
    // waits, and its first field takes the focus once rendered. With these
    // instant replies Chromium reports the body after the template step and
    // the re-enabled Next after the briefing: both cases are covered.
    test('Next puts focus on the first field of the new step', async ({ authenticatedPage: page }) => {
        await mockUpToContent(page);

        const modal = await openWizard(page, await navigateToModule(page));
        await modal.locator('.template-card').first().click();
        await clickNext(modal, page);
        await expect(modal.locator('.carousel-item.active #briefing_title')).toBeFocused({ timeout: 15000 });
        await clickNext(modal, page);
        await expect(modal.locator('.carousel-item.active #pf_title')).toBeFocused({ timeout: 15000 });
        await clickNext(modal, page);
        await expect(modal.locator('.carousel-item.active #section-card-0 .section-header')).toBeFocused({ timeout: 15000 });
        // The placement step renders in the same task that locked Next and
        // enables it again as Generate; the pressed button still has focus.
        await clickNext(modal, page);
        await expect(modal.locator('.carousel-item.active #placement_title')).toBeFocused({ timeout: 15000 });
    });

    test('Enter in a content field puts focus on the first field of the placement step', async ({ authenticatedPage: page }) => {
        await mockUpToContent(page);

        const modal = await openWizard(page, await navigateToModule(page));
        await walkToContent(modal, page);
        await modal.locator('.carousel-item.active #section-card-0 .section-header').focus();
        await page.keyboard.press('Enter');
        await expect(modal.locator('.carousel-item.active #placement_title')).toBeFocused({ timeout: 15000 });
    });

    test('a required briefing that fails to load puts focus on Previous', async ({ authenticatedPage: page }) => {
        await mockAjaxRoute(page, '/nr-landingpage/wizard/templates', [templateWithRequiredBriefing]);
        // Required: the step shows the error and keeps Next locked. The reply
        // arrives late, after Chromium has moved the focus off the locked Next.
        await page.route('**/nr-landingpage/wizard/generate-briefing**', async (route) => {
            await new Promise((resolve) => setTimeout(resolve, 500));
            await route.fulfill({ status: 500, contentType: 'application/json', body: JSON.stringify({ success: false, error: 'Step failed' }) });
        });

        const modal = await openWizard(page, await navigateToModule(page));
        await modal.locator('.template-card').first().click();
        await clickNext(modal, page);
        await expect(modal.locator('.carousel-item.active .alert-danger')).toBeVisible({ timeout: 15000 });
        await waitForSlideSettled(modal);
        await expect(modal.locator('button[name="next"]')).toBeDisabled();
        await expect(modal.locator('button[name="prev"]')).toBeFocused();
    });

    // Re-generate mode: the template step advances by itself, and the focus
    // core put on Cancel when the modal opened would stay there.
    test('re-generate: the briefing reached by the automatic advance gets the focus', async ({ authenticatedPage: page }) => {
        await mockAjaxRoute(page, '/nr-landingpage/wizard/generation-info', {
            templateUid: sampleTemplate.uid,
            briefingAnswers: { title: 'Stored title' },
            parentPageId: 0,
        });
        await mockAjaxRoute(page, '/nr-landingpage/wizard/templates', [sampleTemplate]);
        await mockAjaxRoute(page, '/nr-landingpage/wizard/generate-briefing', []);

        const modal = await openRegenerateWizard(page);
        await expect(modal.locator('.carousel-item.active #briefing_title')).toBeFocused({ timeout: 15000 });
    });

    test('re-generate: a skipped briefing reached by the automatic advance puts focus on Next', async ({ authenticatedPage: page }) => {
        await mockAjaxRoute(page, '/nr-landingpage/wizard/generation-info', {
            templateUid: sampleTemplate.uid,
            briefingAnswers: {},
            parentPageId: 0,
        });
        await mockAjaxRoute(page, '/nr-landingpage/wizard/templates', [{ ...sampleTemplate, briefingMode: 'none' }]);

        const modal = await openRegenerateWizard(page);
        await expect(modal.locator('.carousel-item.active')).toHaveAttribute('data-bs-slide', 'landing-page-briefing', { timeout: 15000 });
        await waitForSlideSettled(modal);
        await expect(modal.locator('button[name="next"]')).toBeFocused();
    });

    test('re-generate: focus the user puts on Cancel while the briefing loads stays there', async ({ authenticatedPage: page }) => {
        await mockAjaxRoute(page, '/nr-landingpage/wizard/generation-info', {
            templateUid: sampleTemplate.uid,
            briefingAnswers: { title: 'Stored title' },
            parentPageId: 0,
        });
        await mockAjaxRoute(page, '/nr-landingpage/wizard/templates', [sampleTemplate]);
        const briefing = await holdFirstReply(page, '/nr-landingpage/wizard/generate-briefing', []);

        const modal = await openRegenerateWizard(page);
        await briefing.first;
        await waitForSlideSettled(modal);
        const cancel = modal.locator('button[name="cancel"]');
        await expect(cancel).toBeFocused();
        // Away and back with the keyboard: now the user has put it there.
        await page.keyboard.press('Tab');
        await expect(cancel).not.toBeFocused();
        await page.keyboard.press('Shift+Tab');
        await expect(cancel).toBeFocused();

        briefing.release();
        await expect(modal.locator('.carousel-item.active #briefing_title')).toBeVisible({ timeout: 15000 });
        await expect(cancel).toBeFocused();
    });

    test('Enter in a field puts focus on the first field of the new step', async ({ authenticatedPage: page }) => {
        await mockUpToContent(page);

        const modal = await openWizard(page, await navigateToModule(page));
        await modal.locator('.template-card').first().click();
        await clickNext(modal, page);
        await expect(modal.locator('.carousel-item.active #briefing_title')).toBeFocused({ timeout: 15000 });
        await waitForSlideSettled(modal);
        await page.keyboard.press('Enter');
        await expect(modal.locator('.carousel-item.active #pf_title')).toBeFocused({ timeout: 15000 });
    });

    // A step that fails to load shows the error and unlocks Next (briefing
    // mode "optional"), and has no field. The failure arrives late, as a slow
    // server's would: Chromium has moved the focus off the disabled Next by
    // then. (A reply within a few milliseconds can still find it there; the
    // test above covers that case.)
    for (const step of ['briefing', 'page-fields', 'content'] as const) {
        test(`Next to a ${step} step that fails to load puts focus on Next`, async ({ authenticatedPage: page }) => {
            const endpoints = { 'briefing': 'generate-briefing', 'page-fields': 'generate-page-fields', 'content': 'generate-content' };
            await mockUpToContent(page);
            await page.route('**/nr-landingpage/wizard/' + endpoints[step] + '**', async (route) => {
                await new Promise((resolve) => setTimeout(resolve, 500));
                await route.fulfill({ status: 500, contentType: 'application/json', body: JSON.stringify({ success: false, error: 'Step failed' }) });
            });

            const modal = await openWizard(page, await navigateToModule(page));
            await nextToStep(modal, page, step);
            await expect(modal.locator('.carousel-item.active .alert-danger')).toBeVisible({ timeout: 15000 });
            await waitForSlideSettled(modal);
            await expect(modal.locator('button[name="next"]')).toBeFocused();
        });
    }

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
        await waitForSlideSettled(modal);

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
        await expect.poll(() => capturedSaveBody !== null, { timeout: 15000 }).toBe(true);

        // Verify the save request was made with imageUid values
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
