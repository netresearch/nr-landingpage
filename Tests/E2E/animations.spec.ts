/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

import { test, expect, Page } from '@playwright/test';

/**
 * Frontend animation runtime and the GSAP stand-ins, without TYPO3.
 *
 * The page is built with setContent and the extension's own files are added
 * from the repository, so these tests need no running instance.
 */

const RUNTIME = 'Resources/Public/JavaScript/frontend/animations.js';
const STAND_INS = ['gsap.min.js', 'ScrollTrigger.min.js', 'TextPlugin.min.js']
    .map((file) => `Resources/Public/JavaScript/vendor/gsap/3/${file}`);

async function pageWithMap(page: Page, body: string, map: Record<string, unknown>): Promise<void> {
    await page.setViewportSize({ width: 1000, height: 800 });
    await page.setContent(
        `<!doctype html><html><body style="margin:0">${body}`
        + `<script type="application/json" data-nr-landingpage-animations>${JSON.stringify(map)}</script>`
        + '</body></html>',
    );
    await page.addScriptTag({ path: RUNTIME });
}

async function opacity(page: Page, selector: string): Promise<string> {
    return page.locator(selector).evaluate((element) => (element as HTMLElement).style.opacity);
}

test.describe('animation runtime', () => {
    test('reveals an element taller than the viewport once it scrolls into view', async ({ page }) => {
        await pageWithMap(
            page,
            '<div style="height:1200px"></div><div id="c10" style="height:9000px"><p>Tall</p></div>',
            { 10: { type: 'fade-up', duration: 0.1 } },
        );

        expect(await opacity(page, '#c10')).toBe('0');

        await page.evaluate(() => window.scrollTo(0, 2000));
        await expect.poll(() => opacity(page, '#c10')).toBe('');
    });

    for (const type of ['fade-up', 'fade-down', 'slide-left', 'slide-right', 'zoom-in', 'scale-up']) {
        test(`reveals a ${type} element in view`, async ({ page }) => {
            await pageWithMap(page, '<div id="c5"><p>Visible</p></div>', { 5: { type, duration: 0.1 } });

            await expect.poll(() => opacity(page, '#c5')).toBe('');
        });
    }

    test('reveals every child of a stagger-children element', async ({ page }) => {
        await pageWithMap(page, '<div id="c6"><p>One</p><p>Two</p></div>', { 6: { type: 'stagger-children', duration: 0.1 } });

        await expect.poll(() => opacity(page, '#c6 > p:nth-child(1)')).toBe('');
        await expect.poll(() => opacity(page, '#c6 > p:nth-child(2)')).toBe('');
    });

    test('types plain text and leaves a paragraph with markup as it is', async ({ page }) => {
        await pageWithMap(
            page,
            '<div id="c7"><h2>Heading</h2><p>Text with <a href="#x">a link</a></p></div>',
            { 7: { type: 'typewriter', duration: 0.1 } },
        );

        await expect(page.locator('#c7 h2')).toHaveText('Heading');
        await expect(page.locator('#c7 p')).toHaveText('Text with a link');
        await expect(page.locator('#c7 p a')).toHaveCount(1);
    });
});

test.describe('GSAP stand-ins for pages generated with GSAP', () => {
    test('keep the content of a stored GSAP script visible', async ({ page }) => {
        const errors: string[] = [];
        page.on('pageerror', (error) => errors.push(error.message));

        // The script shape AnimationScriptBuilder generated before the runtime
        // replaced GSAP: typewriter empties the text before calling gsap.to().
        await page.setContent(
            '<!doctype html><html><body>'
            + '<div id="c8"><h2>Old heading</h2><p>Old para</p></div>'
            + '<div id="c9"><p>Faded</p></div>'
            + '</body></html>',
        );
        for (const path of STAND_INS) {
            await page.addScriptTag({ path });
        }
        await page.addScriptTag({
            content: "gsap.registerPlugin(ScrollTrigger, TextPlugin);"
                + "gsap.matchMedia().add('(prefers-reduced-motion: no-preference)', function() {});"
                + "document.querySelectorAll('#c8 h1, #c8 h2, #c8 p').forEach(function(el) {"
                + " var t = el.textContent; el.textContent = '';"
                + " gsap.to(el, {scrollTrigger: '#c8', text: t, duration: 1, delay: 0, ease: 'none'}); });"
                + "gsap.from('#c9', {scrollTrigger: '#c9', opacity: 0, y: 40, duration: 0.8, delay: 0});"
                + "gsap.to('#c9', {scrollTrigger: {trigger: '#c9', scrub: true}, y: -30, ease: 'none'});",
        });

        await expect(page.locator('#c8 h2')).toHaveText('Old heading');
        await expect(page.locator('#c8 p')).toHaveText('Old para');
        await expect(page.locator('#c9 p')).toBeVisible();
        expect(errors).toEqual([]);
    });
});
