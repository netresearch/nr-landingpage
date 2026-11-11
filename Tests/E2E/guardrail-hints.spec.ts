/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

import { test, expect, navigateToModule, openWizard, mockAjaxRoute, sampleTemplate, sampleContentSections, waitForSlideSettled } from './fixtures';
import { Locator, Page } from '@playwright/test';

/**
 * The hints of nr-llm's guardrails (the rule-set judge in report mode) next to
 * a generated text: a warning with role="status" after the step's intro, the
 * messages set as text only. All AJAX calls are mocked; the replies carry the
 * hints as `guardrailHints` beside `data`, as LandingPageWizardController sends
 * them.
 */

const markupHint = { kind: 'violation', message: 'Rule 1 "Address the reader formally" is broken by "<b>du</b>".' };
const unavailableHint = { kind: 'unavailable', message: 'The text was not checked against the rule set.' };

async function mockSteps(page: Page, hints: { briefing?: unknown[]; pageFields?: unknown[]; content?: unknown[] }): Promise<void> {
    const extra = (list?: unknown[]): Record<string, unknown> => (list ? { guardrailHints: list } : {});
    await mockAjaxRoute(page, '/nr-landingpage/wizard/templates', [sampleTemplate]);
    await mockAjaxRoute(page, '/nr-landingpage/wizard/generate-briefing', [], true, extra(hints.briefing));
    await mockAjaxRoute(page, '/nr-landingpage/wizard/generate-page-fields', { title: 'T', slug: 't' }, true, extra(hints.pageFields));
    await mockAjaxRoute(page, '/nr-landingpage/wizard/generate-content', sampleContentSections, true, extra(hints.content));
}

async function next(modal: Locator): Promise<void> {
    const nextButton = modal.locator('button[name="next"]:not([disabled])');
    await nextButton.waitFor({ state: 'visible', timeout: 15000 });
    await waitForSlideSettled(modal);
    await nextButton.click();
}

async function toBriefing(page: Page): Promise<Locator> {
    const modal = await openWizard(page, await navigateToModule(page));
    await modal.locator('.template-card').first().click();
    await next(modal);
    await modal.locator('.carousel-item.active #briefing_title').waitFor({ state: 'visible', timeout: 15000 });
    return modal;
}

async function toPageFields(page: Page): Promise<Locator> {
    const modal = await toBriefing(page);
    await next(modal);
    await modal.locator('.carousel-item.active #pf_title').waitFor({ state: 'visible', timeout: 15000 });
    return modal;
}

async function toContent(page: Page): Promise<Locator> {
    const modal = await toPageFields(page);
    await next(modal);
    await modal.locator('.carousel-item.active #section-card-0').waitFor({ state: 'visible', timeout: 15000 });
    await waitForSlideSettled(modal);
    return modal;
}

/** The hint warning of the active step, and what it says, item by item. */
async function hintsOf(scope: Locator): Promise<{ count: number; items: string[]; elementChildren: number; role: string | null; after: string | null }> {
    const alert = scope.locator('[data-guardrail-hints]');
    const count = await alert.count();
    if (count === 0) {
        return { count, items: [], elementChildren: 0, role: null, after: null };
    }
    // The messages are added after the warning is inserted.
    await alert.locator('li').first().waitFor();
    return {
        count,
        items: await alert.locator('li').allTextContents(),
        elementChildren: await alert.locator('li *').count(),
        role: await alert.getAttribute('role'),
        after: await alert.evaluate((el) => el.previousElementSibling?.tagName ?? null),
    };
}

test.describe('Guardrail hints', () => {
    test('the briefing step shows the hints after its intro, as text', async ({ authenticatedPage: page }) => {
        await mockSteps(page, { briefing: [markupHint, unavailableHint] });
        const modal = await toBriefing(page);

        const hints = await hintsOf(modal.locator('.carousel-item.active'));
        expect(hints).toEqual({
            count: 1,
            items: [markupHint.message, unavailableHint.message],
            elementChildren: 0,
            role: 'status',
            after: 'P',
        });
        await expect(modal.locator('.carousel-item.active [data-guardrail-hints] p')).toHaveText('Notes on the generated text');
    });

    test('the page fields step shows its hints', async ({ authenticatedPage: page }) => {
        await mockSteps(page, { pageFields: [unavailableHint] });
        const modal = await toPageFields(page);

        const hints = await hintsOf(modal.locator('.carousel-item.active'));
        expect(hints.items).toEqual([unavailableHint.message]);
        expect(hints.role).toBe('status');
    });

    test('a step without hints shows no warning', async ({ authenticatedPage: page }) => {
        await mockSteps(page, {});
        const modal = await toContent(page);

        await expect(modal.locator('[data-guardrail-hints]')).toHaveCount(0);
    });

    test('the content hints stay after a section is regenerated, which shows its own in its card', async ({ authenticatedPage: page }) => {
        await mockSteps(page, { content: [markupHint] });
        const regenerated = { ...sampleContentSections.sections[1], header: 'Regenerated header' };
        await mockAjaxRoute(page, '/nr-landingpage/wizard/regenerate-section', regenerated, true, { guardrailHints: [unavailableHint] });
        const modal = await toContent(page);
        const slide = modal.locator('.carousel-item.active');

        await expect(slide.locator(':scope > [data-guardrail-hints] li')).toHaveText([markupHint.message]);
        await expect(slide.locator('#section-card-1 [data-guardrail-hints]')).toHaveCount(0);

        await slide.locator('#section-card-1 .card-header button').first().click();
        await expect(slide.locator('#section-card-1 [data-guardrail-hints] li')).toHaveText([unavailableHint.message], { timeout: 15000 });
        await expect(slide.locator('#section-card-1 [data-guardrail-hints]')).toHaveAttribute('role', 'status');

        await expect(slide.locator(':scope > [data-guardrail-hints] li')).toHaveText([markupHint.message]);
        await expect(slide.locator('#section-card-0 [data-guardrail-hints]')).toHaveCount(0);
    });
});

test.describe('Guardrail hints in the template form controls', () => {
    // The controls run in the record form, which needs a saved template record;
    // these tests build them on the backend page instead and let them send
    // their real request, answered by a mock.

    test('the test generation preview shows the hints of the reply as text', async ({ authenticatedPage: page }) => {
        await mockAjaxRoute(page, '/nr-landingpage/wizard/test-generate', { sections: [], images: [], pageFields: {} }, true, { guardrailHints: [markupHint] });
        await navigateToModule(page);
        const result = await page.evaluate(async () => {
            const TestGenerate = (await import('@netresearch/nr-landingpage/form-engine/field-control/test-generate.js')).default;
            const modal = document.createElement('div');
            modal.innerHTML = '<div class="modal-body"></div><div class="modal-footer"><button>Go</button></div>';
            document.body.appendChild(modal);
            await new TestGenerate('no-such-control').runTestGenerate(modal, 1, 'Topic');
            const alert = modal.querySelector('.modal-body [data-guardrail-hints]');
            const itemsOnInsert = alert?.querySelectorAll('li').length ?? -1;
            // The messages are added after the warning is inserted.
            for (let i = 0; i < 100 && alert && !alert.querySelector('li'); i++) {
                await new Promise((resolve) => setTimeout(resolve, 20));
            }
            return {
                itemsOnInsert,
                items: Array.from(alert?.querySelectorAll('li') ?? []).map((li) => li.textContent),
                elementChildren: alert?.querySelectorAll('li *').length ?? -1,
                role: alert?.getAttribute('role') ?? null,
            };
        });

        // The status region is in the document before its messages arrive.
        expect(result).toEqual({ itemsOnInsert: 0, items: [markupHint.message], elementChildren: 0, role: 'status' });
    });

    test('the prompt optimizer shows the hints of the reply below the field, keeps them when a run fails and drops them on the next new text', async ({ authenticatedPage: page }) => {
        let replies = 0;
        await page.route('**/nr-landingpage/wizard/optimize-prompt**', async (route) => {
            replies++;
            if (replies === 2) {
                await route.fulfill({
                    status: 500,
                    contentType: 'application/json',
                    body: JSON.stringify({ success: false, error: 'Provider down' }),
                });
                return;
            }
            await route.fulfill({
                status: 200,
                contentType: 'application/json',
                body: JSON.stringify({ success: true, data: { prompt: 'Optimized ' + replies }, ...(replies === 1 ? { guardrailHints: [unavailableHint] } : {}) }),
            });
        });
        await navigateToModule(page);
        const result = await page.evaluate(async () => {
            const OptimizePrompt = (await import('@netresearch/nr-landingpage/form-engine/field-control/optimize-prompt.js')).default;
            const field = document.createElement('div');
            field.className = 'formengine-field-item';
            const textarea = document.createElement('textarea');
            textarea.name = 'data[guardrail-hints-test]';
            const button = document.createElement('a');
            button.id = 'guardrail-hints-optimize';
            button.dataset.itemName = textarea.name;
            field.append(textarea, button);
            document.body.appendChild(field);

            // The control finds its button and field once the document is ready.
            const control = new OptimizePrompt(button.id);
            for (let i = 0; i < 100 && control.textareaElement !== textarea; i++) {
                await new Promise((resolve) => setTimeout(resolve, 20));
            }
            const settled = async (value: string): Promise<void> => {
                for (let i = 0; i < 100 && (textarea.value !== value || button.classList.contains('disabled')); i++) {
                    await new Promise((resolve) => setTimeout(resolve, 50));
                }
            };

            // The messages are added after the warning is inserted.
            const items = async (): Promise<string[]> => {
                for (let i = 0; i < 100 && field.querySelector('[data-guardrail-hints]') && !field.querySelector('[data-guardrail-hints] li'); i++) {
                    await new Promise((resolve) => setTimeout(resolve, 20));
                }
                return Array.from(field.querySelectorAll('[data-guardrail-hints] li')).map((li) => li.textContent ?? '');
            };

            control.callOptimizer(1);
            await settled('Optimized 1');
            const first = await items();
            const role = field.querySelector('[data-guardrail-hints]')?.getAttribute('role') ?? null;

            // The second reply fails: the text stays, and so do its hints.
            control.callOptimizer(1);
            await settled('Optimized 1');
            const afterFailure = await items();

            control.callOptimizer(1);
            await settled('Optimized 3');
            const third = field.querySelectorAll('[data-guardrail-hints]').length;
            return { first, role, afterFailure, third, value: textarea.value };
        });

        expect(result).toEqual({
            first: [unavailableHint.message],
            role: 'status',
            afterFailure: [unavailableHint.message],
            third: 0,
            value: 'Optimized 3',
        });
    });
});
