/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

import {
    test,
    expect,
    clickNext,
    mockAjaxRoute,
    navigateToModule,
    openWizard,
    sampleContentSections,
    sampleTemplate,
    walkToStructuredContent,
} from './fixtures';
import { Page } from '@playwright/test';

/**
 * Every step that waits for the LLM waits for its reply, however long it
 * takes: a generation that needs more than a minute arrives (NEXT-181, where
 * generate-content took 71 and 80 seconds on the demo and the wizard gave up
 * after 60). The server and the proxy in front of it bound the request, the
 * client does not.
 *
 * The reply is held until the page's clock has been moved past the old limit.
 * The clock is installed just before the request goes out: installed earlier
 * it holds up the wizard's own start (the template step stays empty).
 */

const LONGER_THAN_THE_OLD_LIMIT = 61_000;

interface HeldRoute {
    arrived: Promise<void>;
    release: () => void;
}

/**
 * Hold the replies of one endpoint until release(); `data` is the reply.
 * Registered after the fixtures' mocks, so it answers first.
 */
async function holdRoute(page: Page, path: string, data: unknown): Promise<HeldRoute> {
    let arrive: () => void = () => {};
    let release: () => void = () => {};
    const arrived = new Promise<void>((resolve) => { arrive = resolve; });
    const released = new Promise<void>((resolve) => { release = resolve; });
    await page.route(`**${path}**`, async (route) => {
        arrive();
        await released;
        // With a client timeout the browser has given up by now, and fulfilling
        // an aborted request throws; the assertions below report that case.
        await route.fulfill({
            status: 200,
            contentType: 'application/json',
            body: JSON.stringify({ success: true, data }),
        }).catch(() => {});
    });
    return { arrived, release };
}

async function outlastTheOldLimit(page: Page, held: HeldRoute): Promise<void> {
    await held.arrived;
    await page.clock.fastForward(LONGER_THAN_THE_OLD_LIMIT);
    held.release();
}

test.describe('Generation steps wait for a slow reply', () => {
    test('the content step shows sections that take longer than a minute', async ({ authenticatedPage: page }) => {
        await mockAjaxRoute(page, '/nr-landingpage/wizard/templates', [sampleTemplate]);
        await mockAjaxRoute(page, '/nr-landingpage/wizard/generate-briefing', []);
        await mockAjaxRoute(page, '/nr-landingpage/wizard/generate-page-fields', { title: 'My Landing Page', slug: 'my-landing-page' });
        const held = await holdRoute(page, '/nr-landingpage/wizard/generate-content', sampleContentSections);

        const modal = await openWizard(page, await navigateToModule(page));
        await modal.locator('.template-card').first().click();
        await clickNext(modal);
        await modal.locator('.carousel-item.active #briefing_title').waitFor({ state: 'visible', timeout: 15000 });
        await clickNext(modal);
        await modal.locator('.carousel-item.active #pf_title').waitFor({ state: 'visible', timeout: 15000 });
        await page.clock.install();
        await clickNext(modal);

        await outlastTheOldLimit(page, held);

        await expect(modal.locator('.carousel-item.active #section-card-0 .section-header')).toHaveValue('Welcome to Our Page', { timeout: 15000 });
        await expect(modal.locator('.carousel-item.active')).not.toContainText('timed out');
    });

    test('Regenerate replaces a section whose generation takes longer than a minute', async ({ authenticatedPage: page }) => {
        const modal = await walkToStructuredContent(page, sampleContentSections);
        const held = await holdRoute(page, '/nr-landingpage/wizard/regenerate-section', {
            ...sampleContentSections.sections[0],
            header: 'Hero regenerated',
        });

        await page.clock.install();
        await modal.locator('#section-card-0 .card-header button').first().click();
        await outlastTheOldLimit(page, held);

        await expect(modal.locator('#section-card-0 .section-header')).toHaveValue('Hero regenerated', { timeout: 15000 });
    });

    test('the briefing step shows questions that take longer than a minute', async ({ authenticatedPage: page }) => {
        await mockAjaxRoute(page, '/nr-landingpage/wizard/templates', [sampleTemplate]);
        const held = await holdRoute(page, '/nr-landingpage/wizard/generate-briefing', [
            { label: 'Target audience', type: 'text', required: false, placeholder: '' },
        ]);

        const modal = await openWizard(page, await navigateToModule(page));
        await modal.locator('.template-card').first().click();
        await page.clock.install();
        await clickNext(modal);
        await outlastTheOldLimit(page, held);

        await expect(modal.locator('.carousel-item.active')).toContainText('Target audience', { timeout: 15000 });
        await expect(modal.locator('.carousel-item.active')).not.toContainText('timed out');
    });

    test('the page fields step shows fields that take longer than a minute', async ({ authenticatedPage: page }) => {
        await mockAjaxRoute(page, '/nr-landingpage/wizard/templates', [sampleTemplate]);
        await mockAjaxRoute(page, '/nr-landingpage/wizard/generate-briefing', []);
        const held = await holdRoute(page, '/nr-landingpage/wizard/generate-page-fields', { title: 'Slow title', slug: 'slow-title' });

        const modal = await openWizard(page, await navigateToModule(page));
        await modal.locator('.template-card').first().click();
        await clickNext(modal);
        await modal.locator('.carousel-item.active #briefing_title').waitFor({ state: 'visible', timeout: 15000 });
        await page.clock.install();
        await clickNext(modal);
        await outlastTheOldLimit(page, held);

        await expect(modal.locator('.carousel-item.active #pf_title')).toHaveValue('Slow title', { timeout: 15000 });
    });

    test('an AI image that takes longer than a minute is added', async ({ authenticatedPage: page }) => {
        const modal = await walkToStructuredContent(page, { ...sampleContentSections, aiGenerationAvailable: true, hasImageTask: true });
        const held = await holdRoute(page, '/nr-landingpage/wizard/generate-image', {
            image: { uid: 31, name: 'ai.png', title: 'Slow generated image', generated: true },
        });

        await page.clock.install();
        await modal.locator('#section-card-0').getByRole('button', { name: 'Generate with AI' }).click();
        await outlastTheOldLimit(page, held);

        await expect(modal.locator('#section-card-0 [data-image-uid="31"]')).toContainText('Slow generated image', { timeout: 15000 });
    });
});
