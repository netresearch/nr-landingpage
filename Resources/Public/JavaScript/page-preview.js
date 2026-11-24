/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

/**
 * The preview of a structured landing page: the page title and the sections as
 * the wizard holds them, in a neutral style. It shows content and order, not the
 * design of the site's frontend.
 */

const ESCAPES = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' };

/**
 * The text of a piece of HTML, character references decoded: the server
 * sanitizes bodytext as HTML, so a plain-text item can hold `&amp;`. The
 * document is inert (no scripts, no loading).
 *
 * @param {string} html
 * @returns {string}
 */
function decodeCharacterReferences(html) {
    return new DOMParser().parseFromString(html, 'text/html').documentElement.textContent || '';
}

/**
 * @param {unknown} text
 * @returns {string}
 */
export function escapeHtml(text) {
    return String(text ?? '').replace(/[&<>"']/g, (c) => ESCAPES[c]);
}

/** Image positions (imageorient) that float the image beside the text. */
const FLOAT_RIGHT = new Set([17, 25]);
const FLOAT_LEFT = new Set([18, 26]);

/**
 * The text of a bullets element is one item per line; every other element
 * holds HTML that the server sanitized and the editor may have edited.
 *
 * @param {{ctype?: string, bodytext?: string}} section
 * @returns {string}
 */
function bodyHtml(section) {
    const body = section.bodytext || '';
    if (section.ctype === 'bullets' && !/<[a-z][^>]*>/i.test(body)) {
        const items = body.split(/\r?\n/).map((line) => decodeCharacterReferences(line).trim()).filter((line) => line !== '');
        return items.length ? '<ul>' + items.map((item) => '<li>' + escapeHtml(item) + '</li>').join('') + '</ul>' : '';
    }
    return body;
}

/**
 * @param {{section?: string, ctype?: string, header?: string, subheader?: string, bodytext?: string, imageUid?: number, imageorient?: number}} section
 * @param {Object<string, {publicUrl?: string, alternative?: string, title?: string, name?: string}>} imageCatalog
 * @returns {string}
 */
function sectionHtml(section, imageCatalog) {
    // An element with items gets no image when the page is created.
    const image = section.imageUid && !hasItems(section) ? imageCatalog[section.imageUid] : null;
    let figure = '';
    if (image?.publicUrl) {
        const side = FLOAT_RIGHT.has(section.imageorient) ? 'right' : (FLOAT_LEFT.has(section.imageorient) ? 'left' : 'top');
        const alt = image.alternative || image.title || '';
        figure = '<img class="image-' + side + '" src="' + escapeHtml(image.publicUrl) + '" alt="' + escapeHtml(alt) + '">';
    }
    return '<section>'
        + figure
        + (section.header ? '<h2>' + escapeHtml(section.header) + '</h2>' : '')
        + (section.subheader ? '<p class="subheader">' + escapeHtml(section.subheader) + '</p>' : '')
        + bodyHtml(section)
        + itemsHtml(section)
        + '<div class="clear"></div></section>';
}

/**
 * @param {{children?: Object<string, Array<Object<string, string>>>}} section
 * @returns {boolean}
 */
function hasItems(section) {
    return Object.values(section.children || {}).some((records) => Array.isArray(records) && records.length > 0);
}

/**
 * The items of an accordion, card group or similar element, in order. A
 * value holding markup is the server-sanitized rich text of the item and is
 * shown like the body text; any other value is text.
 *
 * @param {{children?: Object<string, Array<Object<string, string>>>}} section
 * @returns {string}
 */
function itemsHtml(section) {
    if (!hasItems(section)) {
        return '';
    }
    const items = Object.values(section.children).flatMap((records) => Array.isArray(records) ? records : []);
    return '<ol class="items">' + items.map((record) => '<li>' + Object.values(record || {}).map((value) => {
        const text = String(value ?? '');
        return /<[a-z][^>]*>/i.test(text) ? itemMarkup(text) : '<p>' + escapeHtml(text) + '</p>';
    }).join('') + '</li>').join('') + '</ol>';
}

/** The tags the server keeps in rich text (StructuredHtmlSanitizer). */
const ITEM_TAGS = new Set(['P', 'BR', 'UL', 'OL', 'LI', 'STRONG', 'EM', 'A', 'H2', 'H3', 'H4']);

/**
 * The markup of an item value as the server would keep it: the editor can
 * change an item in the wizard after the server sanitized it, so the preview
 * keeps only the tags the server allows, links only to http(s), mailto, tel
 * and the site, and shows everything else as text.
 *
 * @param {string} html
 * @returns {string}
 */
function itemMarkup(html) {
    const doc = new DOMParser().parseFromString('<body>' + html + '</body>', 'text/html');
    const render = (node) => Array.from(node.childNodes).map((child) => {
        if (child.nodeType === Node.TEXT_NODE) {
            return escapeHtml(child.textContent || '');
        }
        if (child.nodeType !== Node.ELEMENT_NODE) {
            return '';
        }
        const inner = render(child);
        if (!ITEM_TAGS.has(child.tagName)) {
            return ['STYLE', 'SCRIPT', 'TEMPLATE'].includes(child.tagName) ? '' : inner;
        }
        const tag = child.tagName.toLowerCase();
        if (tag === 'br') {
            return '<br>';
        }
        const href = tag === 'a' ? child.getAttribute('href') || '' : '';
        const link = /^(https?:\/\/|\/|mailto:|tel:)/.test(href) ? ' href="' + escapeHtml(href) + '"' : '';
        return '<' + tag + link + '>' + inner + '</' + tag + '>';
    }).join('');

    return render(doc.body);
}

const STYLE = 'body{font:16px/1.55 system-ui,sans-serif;color:#222;margin:0;padding:1.5rem 2rem;background:#fff}'
    + 'main{max-width:860px;margin:0 auto}h1{font-size:2rem;margin:0 0 1.5rem}'
    + 'section{margin:0 0 2rem;padding:0 0 1.5rem;border-bottom:1px solid #ddd}h2{font-size:1.5rem;margin:0 0 .5rem}'
    + '.subheader{font-size:1.1rem;color:#555;margin:0 0 1rem}img{max-width:100%;height:auto}'
    + '.image-top{display:block;margin:0 0 1rem}.image-right{float:right;max-width:40%;margin:0 0 1rem 1.5rem}'
    + '.image-left{float:left;max-width:40%;margin:0 1.5rem 1rem 0}.clear{clear:both}';

/**
 * @param {{title: string, sections: Array<Object>, imageCatalog: Object, lang?: string}} page
 * @returns {string} a complete HTML document
 */
export function buildPagePreview({ title, sections, imageCatalog, lang = 'en' }) {
    return '<!doctype html><html lang="' + escapeHtml(lang) + '"><head><meta charset="utf-8"><style>' + STYLE + '</style></head><body><main>'
        + (title ? '<h1>' + escapeHtml(title) + '</h1>' : '')
        + sections.map((section) => sectionHtml(section, imageCatalog || {})).join('')
        + '</main></body></html>';
}
