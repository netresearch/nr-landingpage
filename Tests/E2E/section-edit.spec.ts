/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

import { test, expect, navigateToModule, openWizard, waitForSlideSettled, mockAjaxRoute, sampleTemplate } from './fixtures';
import { Locator, Page } from '@playwright/test';

/**
 * Reordering and removing sections in the content step of structured mode.
 * AJAX replies are mocked, as in wizard.spec.ts.
 */

const sections = ['Hero', 'Features', 'Contact'].map((name) => ({
    section: name,
    ctype: 'text',
    header: name + ' header',
    subheader: '',
    bodytext: '<p>' + name + ' body</p>',
    imageUid: 0,
}));

async function clickNext(modal: Locator): Promise<void> {
    const next = modal.locator('button[name="next"]:not([disabled])');
    await next.waitFor({ state: 'visible', timeout: 15000 });
    await waitForSlideSettled(modal);
    await next.click();
}

async function walkToContent(page: Page): Promise<Locator> {
    await mockAjaxRoute(page, '/nr-landingpage/wizard/templates', [sampleTemplate]);
    await mockAjaxRoute(page, '/nr-landingpage/wizard/generate-briefing', []);
    await mockAjaxRoute(page, '/nr-landingpage/wizard/generate-page-fields', { title: 'T', slug: 't' });
    await mockAjaxRoute(page, '/nr-landingpage/wizard/generate-content', { sections, images: [] });

    const modal = await openWizard(page, await navigateToModule(page));
    await modal.locator('.template-card').first().click();
    await clickNext(modal);
    await modal.locator('.carousel-item.active #briefing_title').waitFor({ state: 'visible', timeout: 15000 });
    await clickNext(modal);
    await modal.locator('.carousel-item.active #pf_title').waitFor({ state: 'visible', timeout: 15000 });
    await clickNext(modal);
    await modal.locator('.carousel-item.active #section-card-0').waitFor({ state: 'visible', timeout: 15000 });
    await waitForSlideSettled(modal);
    return modal;
}

function headers(modal: Locator): Promise<string[]> {
    return modal.locator('.carousel-item.active .section-header').evaluateAll((inputs) => inputs.map((i) => (i as HTMLInputElement).value));
}

function action(modal: Locator, name: 'up' | 'down' | 'remove', index: number): Locator {
    return modal.locator('[data-section-action="' + name + '"][data-section-index="' + index + '"]');
}

async function saveAndCapture(page: Page, modal: Locator): Promise<{ contentSections: Array<Record<string, unknown>> }> {
    let saved: { contentSections: Array<Record<string, unknown>> } | null = null;
    await page.route('**/nr-landingpage/wizard/save**', async (route) => {
        saved = route.request().postDataJSON();
        await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ success: true, data: { pageUid: 0 } }) });
    });
    await clickNext(modal);
    await modal.locator('.carousel-item.active [data-placement-parent]').waitFor({ state: 'visible', timeout: 15000 });
    await modal.locator('.carousel-item.active #placement_parent').evaluate((input: HTMLInputElement) => { input.value = '1'; });
    await modal.locator('button.btn-success').click();
    const ok = page.locator('dialog:not([data-severity=""])').last().locator('button[name="ok"]');
    await ok.waitFor({ state: 'visible', timeout: 10000 });
    await ok.click();
    await expect.poll(() => saved !== null, { timeout: 15000 }).toBe(true);
    return saved!;
}

test.describe('Structured sections: reorder and remove', () => {
    test('Move down swaps the section with the next one and the save keeps that order', async ({ authenticatedPage: page }) => {
        const modal = await walkToContent(page);

        await action(modal, 'down', 0).click();

        expect(await headers(modal)).toEqual(['Features header', 'Hero header', 'Contact header']);
        const saved = await saveAndCapture(page, modal);
        expect(saved.contentSections.map((s) => s.section)).toEqual(['Features', 'Hero', 'Contact']);
    });

    test('the first section cannot move up and the last cannot move down', async ({ authenticatedPage: page }) => {
        const modal = await walkToContent(page);

        await expect(action(modal, 'up', 0)).toBeDisabled();
        await expect(action(modal, 'down', 2)).toBeDisabled();
        await expect(action(modal, 'down', 0)).toBeEnabled();
    });

    test('Remove drops the section, also from the save', async ({ authenticatedPage: page }) => {
        const modal = await walkToContent(page);

        await action(modal, 'remove', 1).click();

        expect(await headers(modal)).toEqual(['Hero header', 'Contact header']);
        const saved = await saveAndCapture(page, modal);
        expect(saved.contentSections.map((s) => s.section)).toEqual(['Hero', 'Contact']);
    });

    test('with the keyboard: Enter on Move down keeps the focus on the moved section and announces the move', async ({ authenticatedPage: page }) => {
        const modal = await walkToContent(page);

        await action(modal, 'down', 0).focus();
        await page.keyboard.press('Enter');

        // Hero is now second: the focus is on its Move down button, still enabled.
        await expect(action(modal, 'down', 1)).toBeFocused();
        await expect(modal.locator('.carousel-item.active [role="status"]')).toHaveText('Hero moved to position 2 of 3.');
        await expect(action(modal, 'down', 1)).toHaveAccessibleName('Move down: Hero');
    });

    test('with the keyboard: a section moved to the end passes the focus to its Move up button', async ({ authenticatedPage: page }) => {
        const modal = await walkToContent(page);

        await action(modal, 'down', 1).focus();
        await page.keyboard.press('Enter');

        await expect(action(modal, 'up', 2)).toBeFocused();
    });

    test('after Remove the focus moves to the section that took its place', async ({ authenticatedPage: page }) => {
        const modal = await walkToContent(page);

        await action(modal, 'remove', 0).focus();
        await page.keyboard.press('Enter');

        await expect(action(modal, 'remove', 0)).toBeFocused();
        await expect(modal.locator('.carousel-item.active [role="status"]')).toHaveText('Hero removed.');
    });

    test('Regenerate after a move asks for the section by its place in the generated answer', async ({ authenticatedPage: page }) => {
        const modal = await walkToContent(page);
        const requested: number[] = [];
        await page.route('**/nr-landingpage/wizard/regenerate-section**', async (route) => {
            requested.push(route.request().postDataJSON().sectionIndex);
            await route.fulfill({
                status: 200,
                contentType: 'application/json',
                body: JSON.stringify({ success: true, data: { ...sections[0], header: 'Hero regenerated' } }),
            });
        });

        await action(modal, 'remove', 0).click();
        // Features is now first, but it was the second section of the answer.
        await modal.locator('#section-card-0 .card-header button').first().click();

        await expect(modal.locator('#section-card-0 .section-header')).toHaveValue('Hero regenerated');
        expect(requested).toEqual([1]);
    });
});
