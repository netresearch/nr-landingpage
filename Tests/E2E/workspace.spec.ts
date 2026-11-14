/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

import { test, expect, clickNext, getModuleFrame, sampleContentSections, walkToStructuredContent } from './fixtures';
import { Locator, Page } from '@playwright/test';

/**
 * The Content Workspace (ADR-0001): saving the wizard's content as a draft,
 * and the module that lists drafts. Generation replies are mocked, the module
 * runs against the real backend.
 */

async function toPlacement(page: Page): Promise<Locator> {
    const modal = await walkToStructuredContent(page, sampleContentSections, { title: 'My Landing Page', slug: 'my-landing-page' });
    await clickNext(modal);
    await modal.locator('.carousel-item.active #placement_title').waitFor({ state: 'visible', timeout: 15000 });
    return modal;
}

test.describe('Content Workspace', () => {
    test('the placement step offers to save the content as a draft', async ({ authenticatedPage: page }) => {
        const modal = await toPlacement(page);

        const option = modal.locator('.carousel-item.active [data-draft-option]');
        await expect(option).toBeVisible();
        await expect(option.locator('[data-save-draft]')).toHaveText('Save as workspace draft');
    });

    test('saving a draft sends the content and no page is created', async ({ authenticatedPage: page }) => {
        const modal = await toPlacement(page);
        let draftBody: Record<string, unknown> | null = null;
        let saves = 0;
        await page.route('**/nr-landingpage/workspace/save-draft**', async (route) => {
            draftBody = route.request().postDataJSON();
            await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ success: true, data: { draftUid: 7 } }) });
        });
        await page.route('**/nr-landingpage/wizard/save**', async (route) => {
            saves++;
            await route.fulfill({ status: 500, contentType: 'application/json', body: JSON.stringify({ success: false, error: 'must not be called' }) });
        });

        await modal.locator('.carousel-item.active [data-save-draft]').click();

        await expect.poll(() => draftBody).not.toBeNull();
        expect(draftBody).toMatchObject({ templateUid: 1, title: 'My Landing Page' });
        expect((draftBody as unknown as { contentSections: unknown[] }).contentSections).toHaveLength(2);
        expect(saves).toBe(0);
        await expect(page.locator('dialog')).toBeHidden({ timeout: 15000 });
    });

    test('a refused draft says why and keeps the wizard open', async ({ authenticatedPage: page }) => {
        const modal = await toPlacement(page);
        await page.route('**/nr-landingpage/workspace/save-draft**', (route) => route.fulfill({
            status: 400,
            contentType: 'application/json',
            body: JSON.stringify({ success: false, error: 'A draft needs at least one section.' }),
        }));

        await modal.locator('.carousel-item.active [data-save-draft]').click();

        await expect(page.locator('typo3-notification-message, .alert').filter({ hasText: 'could not be saved' }).first()).toBeVisible({ timeout: 15000 });
        await expect(modal.locator('.carousel-item.active [data-save-draft]')).toBeEnabled();
    });

    test('the module lists drafts and says how to make one', async ({ authenticatedPage: page }) => {
        await page.locator('[data-modulemenu-identifier="nr_landingpage_workspace"]').click({ timeout: 10000 });
        const frame = getModuleFrame(page);

        await expect(frame.locator('#nr-landingpage-workspace h1')).toHaveText('Content Workspace', { timeout: 15000 });
        const hasDrafts = await frame.locator('#nr-landingpage-workspace-drafts').count();
        if (hasDrafts === 0) {
            await expect(frame.locator('#nr-landingpage-workspace-empty')).toContainText('Save as workspace draft');
        }
    });
});
