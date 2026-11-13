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
        const items = body.split(/\r?\n/).map((line) => line.trim()).filter((line) => line !== '');
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
    const image = section.imageUid ? imageCatalog[section.imageUid] : null;
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
        + '<div class="clear"></div></section>';
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
