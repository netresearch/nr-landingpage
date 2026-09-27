import { test, expect, navigateToModule, openWizard, mockAjaxRoute, sampleTemplate, waitForSlideSettled } from './fixtures';
import { Locator, Page } from '@playwright/test';

/**
 * E2E tests for the image section of the content step, in both generation
 * modes. Structured and creative sections share the same image UI with three
 * differences, pinned here:
 *
 * - creative: without image keywords the label reads "Search images…" instead
 *   of "Select an image for this section:";
 * - creative: the "Generate with AI" button needs image keywords; structured
 *   shows it whenever AI generation and an image task are available;
 * - both: the image-generation error alert, the automatic-search info, the
 *   keyword-prefilled search, and the search/generate requests.
 *
 * All AJAX calls are mocked; nothing is generated. The intro and the stored
 * images are pinned per mode, since the creative renderer reads its images from
 * WizardState rather than from its arguments.
 */

type Mode = 'structured' | 'creative';

function section(mode: Mode, index: number, imageKeywords: string[]): Record<string, unknown> {
    if (mode === 'creative') {
        return {
            section: 'Block ' + index,
            colPos: 0,
            bodytext: '<section><h2>Block ' + index + '</h2></section>',
            imagePrompt: 'prompt ' + index,
            imageKeywords,
        };
    }
    return {
        section: 'Section ' + index,
        ctype: 'textmedia',
        header: 'Header ' + index,
        subheader: '',
        bodytext: '<p>Body ' + index + '</p>',
        imagePrompt: 'prompt ' + index,
        imageKeywords,
    };
}

function contentResponse(mode: Mode, options: {
    keywords?: string[][];
    images?: unknown[][];
    imageErrors?: string[];
    aiGenerationAvailable?: boolean;
    hasImageTask?: boolean;
}): Record<string, unknown> {
    const keywords = options.keywords ?? [['beach', 'sun'], ['team']];
    return {
        generationMode: mode,
        sections: keywords.map((kw, i) => section(mode, i, kw)),
        images: options.images ?? keywords.map(() => []),
        imageErrors: options.imageErrors ?? [],
        aiGenerationAvailable: options.aiGenerationAvailable ?? false,
        hasImageTask: options.hasImageTask ?? false,
    };
}

async function openContentStep(page: Page, content: Record<string, unknown>): Promise<Locator> {
    await mockAjaxRoute(page, '/nr-landingpage/wizard/templates', [sampleTemplate]);
    await mockAjaxRoute(page, '/nr-landingpage/wizard/generate-briefing', []);
    await mockAjaxRoute(page, '/nr-landingpage/wizard/generate-page-fields', {
        title: 'Test',
        seo_title: 'SEO',
        description: 'Desc',
    });
    await mockAjaxRoute(page, '/nr-landingpage/wizard/generate-content', content);

    const frame = await navigateToModule(page);
    const modal = await openWizard(page, frame);

    const next = async (): Promise<void> => {
        const nextButton = modal.locator('button[name="next"]:not([disabled])');
        await nextButton.waitFor({ state: 'visible', timeout: 15000 });
        await waitForSlideSettled(modal);
        await nextButton.click();
    };
    await modal.locator('.template-card').first().click();
    await next();
    await modal.locator('#briefing_title').waitFor({ state: 'visible', timeout: 15000 });
    await next();
    await modal.locator('#pf_title').waitFor({ state: 'visible', timeout: 15000 });
    await next();
    await modal.locator('#section-card-0').waitFor({ state: 'visible', timeout: 15000 });
    return modal;
}

function imageSection(modal: Locator, index: number): Locator {
    return modal.locator('#section-card-' + index + ' .border-top');
}

for (const mode of ['structured', 'creative'] as Mode[]) {
    test.describe(`Content step image section (${mode})`, () => {
        test('shows the intro of the mode', async ({ authenticatedPage: page }) => {
            const modal = await openContentStep(page, contentResponse(mode, {}));

            const intro = mode === 'creative'
                ? 'The AI generated creative HTML blocks for your landing page. Preview each block below. Toggle "Source" to edit the HTML directly.'
                : 'Review the generated content sections. You can regenerate individual sections.';
            await expect(modal.locator('.carousel-item.active p').first()).toHaveText(intro);
            // The intro replaces the loading spinner: it is the slide's first element.
            const first = await modal.locator('.carousel-item.active').evaluate(
                (slide) => [slide.firstElementChild?.tagName ?? null, slide.firstElementChild?.textContent?.trim() ?? null],
            );
            expect(first).toEqual(['P', intro]);
        });

        test('shows the stored images of a section and no automatic-search info', async ({ authenticatedPage: page }) => {
            const modal = await openContentStep(page, contentResponse(mode, {
                images: [[{ uid: 41, name: 'stored.jpg', title: 'Stored image' }], []],
            }));

            const first = imageSection(modal, 0);
            await expect(first.locator('[data-image-uid="41"]')).toContainText('Stored image');
            await expect(first.locator('.alert-info')).toHaveCount(0);
            await expect(imageSection(modal, 1).locator('[data-image-uid]')).toHaveCount(0);
        });

        test('shows the image-generation error of a section', async ({ authenticatedPage: page }) => {
            const modal = await openContentStep(page, contentResponse(mode, { imageErrors: ['Quota exceeded'] }));

            const alert = imageSection(modal, 0).locator('.alert-warning');
            await expect(alert).toHaveCount(1);
            await expect(alert).toHaveText('AI image generation failed: Quota exceeded');
            await expect(imageSection(modal, 1).locator('.alert-warning')).toHaveCount(0);
        });

        test('shows the automatic-search info and prefills the search with the keywords', async ({ authenticatedPage: page }) => {
            const modal = await openContentStep(page, contentResponse(mode, {}));

            const first = imageSection(modal, 0);
            await expect(first.locator('small').first()).toHaveText('Select an image for this section:');
            await expect(first.locator('.alert-info[role="status"]')).toHaveText(
                'No images found automatically for keywords: beach, sun. Try refining the search below.',
            );
            await expect(first.locator('input[type="text"]')).toHaveValue('beach sun');
        });

        test('search button queries the image search and adds the result', async ({ authenticatedPage: page }) => {
            const modal = await openContentStep(page, contentResponse(mode, {}));

            const requests: unknown[] = [];
            await page.route('**/nr-landingpage/wizard/search-images**', async (route) => {
                requests.push(route.request().postDataJSON());
                await route.fulfill({
                    status: 200,
                    contentType: 'application/json',
                    body: JSON.stringify({ success: true, data: { images: [{ uid: 21, name: 'beach.jpg', title: 'Beach photo' }] } }),
                });
            });

            const first = imageSection(modal, 0);
            await first.locator('input[type="text"]').fill('beach sunset');
            await first.getByRole('button', { name: 'Search' }).click();

            await expect(first.locator('[data-image-uid="21"]')).toContainText('Beach photo');
            await expect(imageSection(modal, 1).locator('[data-image-uid]')).toHaveCount(0);
            expect(requests).toEqual([{ query: 'beach sunset' }]);
        });

        test('Enter in the search input runs the search', async ({ authenticatedPage: page }) => {
            const modal = await openContentStep(page, contentResponse(mode, {}));

            const requests: unknown[] = [];
            await page.route('**/nr-landingpage/wizard/search-images**', async (route) => {
                requests.push(route.request().postDataJSON());
                await route.fulfill({
                    status: 200,
                    contentType: 'application/json',
                    body: JSON.stringify({ success: true, data: { images: [{ uid: 22, name: 'team.jpg', title: 'Team photo' }] } }),
                });
            });

            const second = imageSection(modal, 1);
            await second.locator('input[type="text"]').press('Enter');

            await expect(second.locator('[data-image-uid="22"]')).toContainText('Team photo');
            expect(requests).toEqual([{ query: 'team' }]);
        });

        test('generate button requests an AI image and adds it', async ({ authenticatedPage: page }) => {
            const modal = await openContentStep(page, contentResponse(mode, { aiGenerationAvailable: true, hasImageTask: true }));

            const requests: unknown[] = [];
            await page.route('**/nr-landingpage/wizard/generate-image**', async (route) => {
                requests.push(route.request().postDataJSON());
                await route.fulfill({
                    status: 200,
                    contentType: 'application/json',
                    body: JSON.stringify({ success: true, data: { image: { uid: 31, name: 'ai.png', title: 'Generated image', generated: true } } }),
                });
            });

            const first = imageSection(modal, 0);
            const generate = first.getByRole('button', { name: 'Generate with AI' });
            await expect(generate).toHaveCount(1);
            await generate.click();

            await expect(first.locator('[data-image-uid="31"]')).toContainText('Generated image');
            await expect(first.locator('[data-image-uid="31"]')).toContainText('AI');
            await expect(generate).toBeEnabled();
            await expect(generate).toHaveText('Generate with AI');
            expect(requests).toEqual([{
                templateUid: sampleTemplate.uid,
                imagePrompt: 'prompt 0',
                sectionHeader: mode === 'creative' ? 'Block 0' : 'Header 0',
            }]);
        });

        test('no generate button without an image task', async ({ authenticatedPage: page }) => {
            const modal = await openContentStep(page, contentResponse(mode, { aiGenerationAvailable: true, hasImageTask: false }));

            await expect(imageSection(modal, 0).getByRole('button', { name: 'Search' })).toHaveCount(1);
            await expect(modal.getByRole('button', { name: 'Generate with AI' })).toHaveCount(0);
        });

        test('a section without image keywords', async ({ authenticatedPage: page }) => {
            const modal = await openContentStep(page, contentResponse(mode, {
                keywords: [[]],
                aiGenerationAvailable: true,
                hasImageTask: true,
            }));

            const first = imageSection(modal, 0);
            await expect(first.locator('.alert-info')).toHaveCount(0);
            await expect(first.locator('input[type="text"]')).toHaveValue('');
            if (mode === 'creative') {
                await expect(first.locator('small').first()).toHaveText('Search images…');
                await expect(first.getByRole('button', { name: 'Generate with AI' })).toHaveCount(0);
            } else {
                await expect(first.locator('small').first()).toHaveText('Select an image for this section:');
                await expect(first.getByRole('button', { name: 'Generate with AI' })).toHaveCount(1);
            }
        });
    });
}
