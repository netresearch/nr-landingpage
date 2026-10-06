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

async function pageWithMap(
    page: Page,
    body: string,
    map: Record<string, unknown>,
    beforeRuntime?: () => void,
): Promise<void> {
    await page.setViewportSize({ width: 1000, height: 800 });
    await page.setContent(
        `<!doctype html><html><body style="margin:0">${body}`
        + `<script type="application/json" data-nr-landingpage-animations>${JSON.stringify(map)}</script>`
        + '</body></html>',
    );
    if (beforeRuntime) {
        await page.evaluate(beforeRuntime);
    }
    await page.addScriptTag({ path: RUNTIME });
}

async function opacity(page: Page, selector: string): Promise<string> {
    return page.locator(selector).evaluate((element) => (element as HTMLElement).style.opacity);
}

async function transform(page: Page, selector: string): Promise<string> {
    return page.locator(selector).evaluate((element) => (element as HTMLElement).style.transform);
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
        test(`hides a ${type} element until it scrolls into view`, async ({ page }) => {
            await pageWithMap(
                page,
                '<div style="height:1200px"></div><div id="c5"><p>Visible</p></div>',
                { 5: { type, duration: 0.1 } },
            );

            expect(await opacity(page, '#c5')).toBe('0');
            expect(await transform(page, '#c5')).not.toBe('');

            await page.evaluate(() => window.scrollTo(0, 1000));
            await expect.poll(() => opacity(page, '#c5')).toBe('');
            expect(await transform(page, '#c5')).toBe('');
        });
    }

    test('reveals a fade-down element at the top of the page', async ({ page }) => {
        // The start state moves it 40px up, out of the viewport.
        await pageWithMap(page, '<div id="c12"><p>Top</p></div>', { 12: { type: 'fade-down', duration: 0.1 } });

        await expect.poll(() => opacity(page, '#c12')).toBe('');
    });

    test('hides every child of a stagger-children element until it scrolls into view', async ({ page }) => {
        await pageWithMap(
            page,
            '<div style="height:1200px"></div><div id="c6"><p>One</p><p>Two</p></div>',
            { 6: { type: 'stagger-children', duration: 0.1 } },
        );

        expect(await opacity(page, '#c6 > p:nth-child(1)')).toBe('0');
        expect(await opacity(page, '#c6 > p:nth-child(2)')).toBe('0');

        await page.evaluate(() => window.scrollTo(0, 1000));
        await expect.poll(() => opacity(page, '#c6 > p:nth-child(1)')).toBe('');
        await expect.poll(() => opacity(page, '#c6 > p:nth-child(2)')).toBe('');
    });

    test('types plain text in steps and leaves a paragraph with markup as it is', async ({ page }) => {
        await pageWithMap(
            page,
            '<div id="c7"><h2>Heading</h2><p>Text with <a href="#x">a link</a></p></div>',
            { 7: { type: 'typewriter', duration: 0.5 } },
            () => {
                const seen: string[] = [];
                (window as unknown as { typed: string[] }).typed = seen;
                const heading = document.querySelector('#c7 h2') as HTMLElement;
                new MutationObserver(() => seen.push(heading.textContent || ''))
                    .observe(heading, { subtree: true, childList: true, characterData: true });
            },
        );

        const typed = (): Promise<string[]> => page.evaluate(() => (window as unknown as { typed: string[] }).typed);
        await expect.poll(async () => (await typed()).at(-1)).toBe('Heading');
        expect((await typed()).some((text) => text !== '' && text.length < 'Heading'.length && 'Heading'.startsWith(text))).toBe(true);
        await expect(page.locator('#c7 p')).toHaveText('Text with a link');
        await expect(page.locator('#c7 p a')).toHaveCount(1);
    });

    test('moves a parallax element while the page scrolls', async ({ page }) => {
        await pageWithMap(
            page,
            '<div style="height:600px"></div><div id="c11" style="height:200px"><p>Parallax</p></div><div style="height:2000px"></div>',
            { 11: { type: 'parallax' } },
        );
        const before = await transform(page, '#c11');

        await page.evaluate(() => window.scrollTo(0, 500));
        await expect.poll(() => transform(page, '#c11')).not.toBe(before);
        expect(await transform(page, '#c11')).toMatch(/^translateY\(-\d+(\.\d)?px\)$/);
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

    test('accept other GSAP calls and apply the end state that decides visibility', async ({ page }) => {
        const errors: string[] = [];
        page.on('pageerror', (error) => errors.push(error.message));

        // Shapes a script written in creative mode may use; the page hides
        // the content in CSS and relies on GSAP to show it.
        await page.setContent(
            '<!doctype html><html><head><style>'
            + '#a, #d { opacity: 0 } #c { opacity: 0; visibility: hidden }'
            + '</style></head><body>'
            + '<div id="a">A</div><div id="b">B</div><div id="c">C</div><div id="d">D</div>'
            + '<p class="item">One</p><p class="item">Two</p>'
            + '</body></html>',
        );
        for (const path of STAND_INS) {
            await page.addScriptTag({ path });
        }
        await page.addScriptTag({
            content: "gsap.registerPlugin(ScrollTrigger, TextPlugin);"
                + "gsap.set('.item', {y: 20});"
                + "gsap.timeline({scrollTrigger: {trigger: '#a', start: 'top 80%'}})"
                + ".fromTo('#a', {opacity: 0}, {opacity: 1, duration: 1})"
                + ".to('#b', {text: {value: 'Typed'}, duration: 1});"
                + "ScrollTrigger.create({trigger: '#c', onEnter: function () { gsap.to('#c', {autoAlpha: 1}); }});"
                + "ScrollTrigger.refresh();"
                + "gsap.utils.toArray('.item').forEach(function (el, i) { gsap.from(el, {y: 20, delay: i * 0.1}); });"
                + "gsap.matchMedia().add('(min-width: 1px)', function () { gsap.to('#d', {opacity: 1}); });"
                + "gsap.quickTo('#a', 'x')(10);"
                + "document.body.dataset.done = 'yes';",
        });

        expect(errors).toEqual([]);
        await expect(page.locator('body')).toHaveAttribute('data-done', 'yes');
        await expect(page.locator('#a')).toHaveCSS('opacity', '1');
        await expect(page.locator('#b')).toHaveText('Typed');
        await expect(page.locator('#c')).toHaveCSS('opacity', '1');
        await expect(page.locator('#c')).toHaveCSS('visibility', 'visible');
        await expect(page.locator('#d')).toHaveCSS('opacity', '1');
        await expect(page.locator('.item')).toHaveCount(2);
    });

    test('never hide visible content and settle tweens used as promises', async ({ page }) => {
        const errors: string[] = [];
        page.on('pageerror', (error) => errors.push(error.message));

        await page.setContent(
            '<!doctype html><html><head><style>.card, #r { opacity: 0 }</style></head><body>'
            + '<h1 id="hero">Hero</h1><div class="card">1</div><div class="card">2</div><div id="r">R</div>'
            + '</body></html>',
        );
        for (const path of STAND_INS) {
            await page.addScriptTag({ path });
        }
        await page.addScriptTag({
            content: "gsap.to('#hero', {opacity: 0, y: -50, scrollTrigger: {trigger: '#hero', scrub: true}});"
                + "gsap.to('#hero', {autoAlpha: 0, delay: 2});"
                + "gsap.matchMedia().add('(max-width: 1px)', function () { gsap.set('#hero', {visibility: 'hidden'}); });"
                + "ScrollTrigger.batch(gsap.utils.toArray('.card'), {onEnter: function (batch) { gsap.to(batch, {opacity: 1}); }});"
                + "gsap.context(function () { gsap.to('#r', {opacity: 1}); });"
                + "gsap.to('#hero', {y: 10}).then(function () { document.body.dataset.then = 'yes'; });"
                + "(async function () { await gsap.timeline().to('#hero', {y: 0}); document.body.dataset.awaited = 'yes'; })();",
        });

        expect(errors).toEqual([]);
        await expect(page.locator('#hero')).toHaveCSS('opacity', '1');
        await expect(page.locator('#hero')).toHaveCSS('visibility', 'visible');
        await expect(page.locator('.card').nth(0)).toHaveCSS('opacity', '1');
        await expect(page.locator('.card').nth(1)).toHaveCSS('opacity', '1');
        await expect(page.locator('#r')).toHaveCSS('opacity', '1');
        await expect(page.locator('body')).toHaveAttribute('data-then', 'yes');
        await expect(page.locator('body')).toHaveAttribute('data-awaited', 'yes');
    });
});
