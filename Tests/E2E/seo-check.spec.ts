/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

import { test, expect, clickNext, walkToStructuredContent } from './fixtures';
import { Locator, Page } from '@playwright/test';

/**
 * The SEO check of the placement step. The page-field and content replies are
 * mocked; the check itself runs on the server, as in production.
 */

const GOOD_TITLE = 'Rocket 2: the faster way to ship';

const goodFields = { title: GOOD_TITLE, slug: 'rocket-2', seo_title: GOOD_TITLE, description: 'd'.repeat(140) };

const goodContent = {
    sections: [
        { section: 'Hero', ctype: 'text', header: 'Why Rocket 2', subheader: '', bodytext: '<p>Text</p><h3>Speed</h3><p>More</p>', imageUid: 0 },
    ],
    images: [],
};

const flawedFields = { title: 'Short', slug: 'short', seo_title: '', description: '' };

const flawedContent = {
    sections: [
        { section: 'Hero', ctype: 'text', header: 'Why', subheader: '', bodytext: '<h4>Deep</h4><img src="/a.png">', imageUid: 0 },
    ],
    images: [],
};

async function toPlacement(page: Page, content: unknown, fields: unknown): Promise<Locator> {
    const modal = await walkToStructuredContent(page, content, fields);
    await clickNext(modal);
    await modal.locator('.carousel-item.active [data-seo-check]').waitFor({ state: 'visible', timeout: 15000 });
    return modal;
}

const check = (modal: Locator): Locator => modal.locator('.carousel-item.active [data-seo-check]');

test.describe('Placement step: SEO check', () => {
    test('a page that follows the rules has no notes', async ({ authenticatedPage: page }) => {
        const modal = await toPlacement(page, goodContent, goodFields);

        await expect(check(modal).locator('[role="status"]')).toContainText('No notes');
        await expect(check(modal).locator('li')).toHaveCount(0);
    });

    test('lists the notes with their severity in words and the section they concern', async ({ authenticatedPage: page }) => {
        const modal = await toPlacement(page, flawedContent, flawedFields);

        await expect(check(modal).locator('[role="status"]')).toContainText(': 4.');
        await expect(check(modal).locator('[data-seo-rule]')).toHaveText([
            /Warning: The title has 5 characters; at least 30 are recommended\./,
            /Warning: The page has no meta description\./,
            /Warning: Section 1 \(Hero\): The heading level jumps from h2 to h4: “Deep”/,
            /Warning: Section 1 \(Hero\): An image in the text has no alternative text\./,
        ]);
    });

    test('the notes do not stop the page from being created', async ({ authenticatedPage: page }) => {
        const modal = await toPlacement(page, flawedContent, flawedFields);

        await expect(check(modal).locator('li')).toHaveCount(4);
        await expect(modal.locator('button.btn-success')).toBeEnabled();
    });

    test('Check again takes the title as it is now', async ({ authenticatedPage: page }) => {
        const modal = await toPlacement(page, goodContent, { ...goodFields, seo_title: '' });
        await expect(check(modal).locator('li')).toHaveCount(0);

        await modal.locator('.carousel-item.active #placement_title').fill('t'.repeat(70));
        await check(modal).locator('[data-seo-check-again]').click();

        await expect(check(modal).locator('[data-seo-rule="title-long"]')).toContainText('70 characters');
    });

    test('the check can be run again with the keyboard', async ({ authenticatedPage: page }) => {
        const modal = await toPlacement(page, goodContent, goodFields);
        let requests = 0;
        await page.route('**/nr-landingpage/wizard/check-seo**', async (route) => {
            requests++;
            await route.fallback();
        });

        await check(modal).locator('[data-seo-check-again]').focus();
        await page.keyboard.press('Enter');

        await expect.poll(() => requests).toBe(1);
        await expect(check(modal).locator('[role="status"]')).toContainText('No notes');
    });

    test('says so when the check cannot run, and Generate stays available', async ({ authenticatedPage: page }) => {
        await page.route('**/nr-landingpage/wizard/check-seo**', (route) => route.fulfill({ status: 500, contentType: 'application/json', body: JSON.stringify({ success: false, error: 'boom' }) }));
        const modal = await toPlacement(page, goodContent, goodFields);

        await expect(check(modal)).toContainText('could not run');
        await expect(modal.locator('button.btn-success')).toBeEnabled();
    });
});
