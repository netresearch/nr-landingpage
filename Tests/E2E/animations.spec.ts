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

/**
 * Loads the stand-ins into a page and runs a stored script against them.
 * Returns the page errors, collected from before the page content is set.
 */
async function runWithStandIn(page: Page, html: string, script: string): Promise<string[]> {
    const errors: string[] = [];
    page.on('pageerror', (error) => errors.push(error.message));
    await page.setContent(html);
    for (const path of STAND_INS) {
        await page.addScriptTag({ path });
    }
    await page.addScriptTag({ content: script });
    return errors;
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

test.describe('GSAP stand-in for pages generated with GSAP', () => {
    test('keep the content of a stored GSAP script visible', async ({ page }) => {
        // The script shape AnimationScriptBuilder generated before the runtime
        // replaced GSAP: typewriter empties the text before calling gsap.to().
        const errors = await runWithStandIn(
            page,
            '<!doctype html><html><body>'
            + '<div id="c8"><h2>Old heading</h2><p>Old para</p></div>'
            + '<div id="c9"><p>Faded</p></div>'
            + '</body></html>',
            "gsap.registerPlugin(ScrollTrigger, TextPlugin);"
            + "gsap.matchMedia().add('(prefers-reduced-motion: no-preference)', function() {});"
            + "document.querySelectorAll('#c8 h1, #c8 h2, #c8 p').forEach(function(el) {"
            + " var t = el.textContent; el.textContent = '';"
            + " gsap.to(el, {scrollTrigger: '#c8', text: t, duration: 1, delay: 0, ease: 'none'}); });"
            + "gsap.from('#c9', {scrollTrigger: '#c9', opacity: 0, y: 40, duration: 0.8, delay: 0});"
            + "gsap.to('#c9', {scrollTrigger: {trigger: '#c9', scrub: true}, y: -30, ease: 'none'});",
        );

        await expect(page.locator('#c8 h2')).toHaveText('Old heading');
        await expect(page.locator('#c8 p')).toHaveText('Old para');
        await expect(page.locator('#c9 p')).toBeVisible();
        expect(errors).toEqual([]);
    });

    test('never write over visible text and never hide content', async ({ page }) => {
        const errors = await runWithStandIn(
            page,
            '<!doctype html><html><body><h1 id="hero">Hero</h1><p id="v">Third</p><p id="w">Fourth</p>'
            + '<p id="y">Fifth</p><p id="z">Sixth</p></body></html>',
            "gsap.to('#hero', {opacity: 0, y: -50, scrollTrigger: {trigger: '#hero', scrub: true}});"
            + "gsap.to('#hero', {autoAlpha: 0, delay: 2});"
            + "gsap.set('#hero', {visibility: 'hidden'});"
            + "gsap.from('#v', {text: 'Loading'});"
            + "gsap.to('#w', {text: ''});"
            + "gsap.fromTo('#y', {text: 'Start'}, {text: 'End'});"
            + "gsap.to('#z', {text: 'Loading', repeat: 1, yoyo: true});",
        );

        expect(errors).toEqual([]);
        await expect(page.locator('#hero')).toHaveCSS('opacity', '1');
        await expect(page.locator('#hero')).toHaveCSS('visibility', 'visible');
        await expect(page.locator('#v')).toHaveText('Third');
        await expect(page.locator('#w')).toHaveText('Fourth');
        await expect(page.locator('#y')).toHaveText('Fifth');
        await expect(page.locator('#z')).toHaveText('Sixth');
    });

    test('run no callbacks of the stored script', async ({ page }) => {
        const errors = await runWithStandIn(
            page,
            '<!doctype html><html><body><header id="nav">Nav</header></body></html>',
            "var nav = document.getElementById('nav');"
            + "ScrollTrigger.create({start: 'top top', end: 'max', onUpdate: function () { nav.dataset.hidden = 'yes'; }});"
            + "Observer.create({target: window, onDown: function () { nav.dataset.hidden = 'yes'; }});"
            + "gsap.to('#nav', {y: -60, onComplete: function () { nav.dataset.hidden = 'yes'; }});"
            + "document.body.dataset.done = 'yes';",
        );

        expect(errors).toEqual([]);
        await expect(page.locator('body')).toHaveAttribute('data-done', 'yes');
        await expect(page.locator('#nav')).not.toHaveAttribute('data-hidden', 'yes');
    });

    // GSAP-shaped code from scripts written in creative mode, collected in
    // review. The stand-in does not emulate these scripts; each must run to
    // its end without an error.
    const SHAPES: Record<string, string> = {
        power2Ease: "gsap.to('#x', {opacity: 1, duration: 0.1, ease: Power2.easeOut});",
        backConfig: "gsap.to('#x', {opacity: 1, ease: Back.easeOut.config(1.7)});",
        tweenMax: "TweenMax.to('#x', 0.1, {opacity: 1});",
        timelineMax: "var tl = new TimelineMax(); tl.to('#x', 0.1, {opacity: 1}).add('label').play();",
        coreTimeline: "new gsap.core.Timeline().to('#x', {opacity: 1});",
        contextAdd: "var ctx = gsap.context(function () {}); ctx.add(function () {}); ctx.revert();",
        matchMedia: "gsap.matchMedia().add('(min-width: 1px)', function () {});",
        timelineCall: "gsap.timeline({scrollTrigger: {trigger: '#x', start: 'top 80%'}}).call(function () {}).fromTo('#x', {opacity: 0}, {opacity: 1});",
        scrollTrigger: "ScrollTrigger.create({trigger: '#x', onEnter: function () {}}); ScrollTrigger.refresh(); new ScrollTrigger({trigger: '#x'});",
        batch: "ScrollTrigger.batch(gsap.utils.toArray('#x'), {onEnter: function (batch) { gsap.to(batch, {opacity: 1}); }});",
        observer: "gsap.registerPlugin(Observer); Observer.create({target: window, type: 'wheel,touch', onUp: function () {}});",
        quickTo: "gsap.quickTo('#x', 'x')(10);",
        utils: "var n = gsap.utils.snap(1, 2.4) + gsap.utils.clamp(0, 1, 2); gsap.utils.toArray('#x').forEach(function (el) { el.dataset.loop = 'yes'; });",
        strictAssign: "(function () { 'use strict'; var tl = gsap.timeline(); tl.name = 'intro'; tl.length = 2; gsap.defaults({ease: 'none'}); delete tl.name; for (const step of tl) { step.kill(); } })();",
        thenAndAwait: "gsap.to('#x', {opacity: 1}).then(function (tween) { tween.kill(); }); (async function () { const tw = await gsap.to('#x', {opacity: 1}); tw.kill(); async function build() { return gsap.timeline(); } (await build()).play(); for await (const el of gsap.utils.toArray('#x')) { el.dataset.awaited = 'yes'; } })();",
        repeatingCallback: "function again() { gsap.to('#x', {opacity: 1, onComplete: again}); } again();",
    };
    for (const [name, script] of Object.entries(SHAPES)) {
        test(`run the shape ${name} to its end`, async ({ page }) => {
            const errors = await runWithStandIn(
                page,
                '<!doctype html><html><body><div id="x">X</div></body></html>',
                'gsap.registerPlugin(ScrollTrigger, TextPlugin);' + script + "document.body.dataset.done='yes';",
            );

            await expect(page.locator('body')).toHaveAttribute('data-done', 'yes');
            if (name === 'thenAndAwait') {
                // The async part ends after the synchronous marker.
                await expect(page.locator('#x')).toHaveAttribute('data-awaited', 'yes');
            }
            expect(errors).toEqual([]);
        });
    }
});
