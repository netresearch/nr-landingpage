/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

import { test, expect, walkToStructuredContent } from './fixtures';
import { Locator } from '@playwright/test';

/**
 * The page preview of the structured content step. AJAX replies are mocked.
 */

const PIXEL = 'data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7';

const content = {
    sections: [
        { section: 'Hero', ctype: 'text', header: 'Hero header', subheader: 'Hero sub', bodytext: '<p>Hero body</p>', imageUid: 0, imageorient: 0 },
        { section: 'Benefits', ctype: 'bullets', header: 'Benefits header', subheader: '', bodytext: 'Fast &amp; cheap\nReliable &lt;always&gt;', imageUid: 0, imageorient: 0 },
        { section: 'Contact', ctype: 'textmedia', header: 'Contact header', subheader: '', bodytext: '<p>Contact body</p>', imageUid: 7, imageorient: 25 },
    ],
    images: [[], [], [{ uid: 7, name: 'office.png', title: 'Office', alternative: 'Our office', publicUrl: PIXEL, recommended: true }]],
};

const toggle = (modal: Locator): Locator => modal.locator('[data-page-preview-toggle]');
const preview = (modal: Locator) => modal.frameLocator('iframe[title="Page preview"]');

test.describe('Structured content step: page preview', () => {
    test('is closed at first and opens with the title and the sections in order', async ({ authenticatedPage: page }) => {
        const modal = await walkToStructuredContent(page, content);

        await expect(toggle(modal)).toHaveAttribute('aria-expanded', 'false');
        await expect(modal.locator('#page-preview-panel')).toBeHidden();

        await toggle(modal).click();

        await expect(toggle(modal)).toHaveAttribute('aria-expanded', 'true');
        await expect(preview(modal).locator('h1')).toHaveText('My Landing Page');
        await expect(preview(modal).locator('h2')).toHaveText(['Hero header', 'Benefits header', 'Contact header']);
        await expect(preview(modal).locator('.subheader')).toHaveText('Hero sub');
    });

    test('shows a bullets element as a list and the chosen image with its alternative text', async ({ authenticatedPage: page }) => {
        const modal = await walkToStructuredContent(page, content);

        await toggle(modal).click();

        await expect(preview(modal).locator('li')).toHaveText(['Fast & cheap', 'Reliable <always>']);
        const image = preview(modal).locator('img');
        await expect(image).toHaveAttribute('alt', 'Our office');
        await expect(image).toHaveClass('image-right');
    });

    test('follows an edit, a move and a removed image while it is open', async ({ authenticatedPage: page }) => {
        const modal = await walkToStructuredContent(page, content);
        await toggle(modal).click();
        await expect(preview(modal).locator('h2')).toHaveCount(3);

        await modal.locator('#section-card-0 .section-header').fill('Edited header');
        await expect(preview(modal).locator('h2').first()).toHaveText('Edited header');

        await modal.locator('[data-section-action="down"][data-section-index="0"]').click();
        await expect(preview(modal).locator('h2')).toHaveText(['Benefits header', 'Edited header', 'Contact header']);
        // The panel stays open after the cards were drawn again.
        await expect(toggle(modal)).toHaveAttribute('aria-expanded', 'true');

        await modal.locator('#section-card-2 [data-image-uid="7"]').click();
        await expect(preview(modal).locator('img')).toHaveCount(0);
    });

    test('is a sandboxed frame without scripts', async ({ authenticatedPage: page }) => {
        const modal = await walkToStructuredContent(page, {
            ...content,
            sections: [{ ...content.sections[0], subheader: '', bodytext: '<p>safe</p><script>window.top.document.title = "pwned"</script>' }],
            images: [],
        });
        await toggle(modal).click();
        await expect(preview(modal).locator('p', { hasText: 'safe' })).toHaveCount(1);

        await expect(modal.locator('iframe[title="Page preview"]')).toHaveAttribute('sandbox', 'allow-same-origin');
        expect(await page.title()).not.toBe('pwned');
    });

    test('the toggle works with the keyboard and hides the panel again', async ({ authenticatedPage: page }) => {
        const modal = await walkToStructuredContent(page, content);

        await toggle(modal).focus();
        await page.keyboard.press('Enter');
        await expect(modal.locator('#page-preview-panel')).toBeVisible();
        await expect(toggle(modal)).toHaveText('Hide preview');

        await page.keyboard.press('Enter');
        await expect(modal.locator('#page-preview-panel')).toBeHidden();
        await expect(toggle(modal)).toHaveText('Preview page');
    });
});
