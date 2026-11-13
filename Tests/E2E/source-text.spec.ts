/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

import { test, expect, clickNext, openBriefingStep, sampleContentSections } from './fixtures';
import { Locator, Page } from '@playwright/test';

/**
 * The pasted source text of the briefing step. AJAX replies are mocked.
 */

const PASTED = 'Acme ships Rocket 2 on 3 May.\nIt is 40% faster than Rocket 1.';

/** Collect the bodies of the requests the wizard sends to an endpoint. */
async function collectBodies(page: Page, endpoint: string): Promise<Array<Record<string, any>>> {
    const bodies: Array<Record<string, any>> = [];
    await page.route('**/nr-landingpage/wizard/' + endpoint + '**', async (route) => {
        bodies.push(route.request().postDataJSON());
        await route.fallback();
    });
    return bodies;
}

const source = (modal: Locator): Locator => modal.getByLabel('Source text (optional)');

test.describe('Briefing step: pasted source text', () => {
    test('is an optional, labelled field with a character counter and does not lock Next', async ({ authenticatedPage: page }) => {
        const modal = await openBriefingStep(page, sampleContentSections);

        await expect(source(modal)).toBeVisible();
        await expect(source(modal)).not.toHaveAttribute('required', '');
        await expect(modal.locator('#char-counter-briefing_source')).toHaveText('0 / 12000');
        await source(modal).fill(PASTED);
        await expect(modal.locator('#char-counter-briefing_source')).toHaveText(PASTED.length + ' / 12000');
        await expect(source(modal)).toHaveAccessibleDescription(/12000/);
    });

    test('goes to the generation requests as the answer sourceText, beside the other answers', async ({ authenticatedPage: page }) => {
        const modal = await openBriefingStep(page, sampleContentSections);
        // Registered after the mocks of openBriefingStep: the last route runs first.
        const pageFields = await collectBodies(page, 'generate-page-fields');
        const content = await collectBodies(page, 'generate-content');

        await modal.locator('#briefing_title').fill('Rocket 2');
        await source(modal).fill(PASTED);
        await clickNext(modal);
        await modal.locator('.carousel-item.active #pf_title').waitFor({ state: 'visible', timeout: 15000 });
        await clickNext(modal);
        await modal.locator('.carousel-item.active #section-card-0').waitFor({ state: 'visible', timeout: 15000 });

        expect(pageFields[0].briefingAnswers).toMatchObject({ title: 'Rocket 2', sourceText: PASTED });
        expect(content[0].briefingAnswers).toMatchObject({ title: 'Rocket 2', sourceText: PASTED });
    });

    test('is not sent when the field is empty or only white space', async ({ authenticatedPage: page }) => {
        const modal = await openBriefingStep(page, sampleContentSections);
        const pageFields = await collectBodies(page, 'generate-page-fields');

        await modal.locator('#briefing_title').fill('Rocket 2');
        await source(modal).fill('  \n ');
        await clickNext(modal);
        await modal.locator('.carousel-item.active #pf_title').waitFor({ state: 'visible', timeout: 15000 });

        expect(pageFields[0].briefingAnswers).not.toHaveProperty('sourceText');
    });

    test('is still there after Back to the template step and Next again', async ({ authenticatedPage: page }) => {
        const modal = await openBriefingStep(page, sampleContentSections);
        await modal.locator('#briefing_title').fill('Rocket 2');
        await source(modal).fill(PASTED);

        await modal.locator('button[name="prev"]:not([disabled])').click();
        await modal.locator('.carousel-item.active .template-card').first().waitFor({ state: 'visible', timeout: 15000 });
        await clickNext(modal);
        await modal.locator('.carousel-item.active #briefing_title').waitFor({ state: 'visible', timeout: 15000 });

        await expect(source(modal)).toHaveValue(PASTED);
    });
});
