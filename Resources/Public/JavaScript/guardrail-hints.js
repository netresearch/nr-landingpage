/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

/** Time for the browser to register the inserted region before it changes. */
const ANNOUNCE_DELAY_MS = 100;

/**
 * The hints nr-llm's guardrails report for a generated text, such as a rule of
 * the rule set that the text breaks or a check that could not run. The text is
 * delivered anyway, so the hints are a warning next to it, announced politely.
 *
 * The server sends them as `guardrailHints`, a list of `{kind, message}`; the
 * message quotes generated text and is only ever set as text.
 *
 * A status region that enters the document with its content is not announced
 * by every screen reader, so the element is returned with its heading only and
 * the messages are added after the caller has inserted it.
 *
 * @param {unknown} hints
 * @param {string} title
 * @returns {HTMLDivElement|null} null when there is no hint to show
 */
export function guardrailHintsElement(hints, title) {
    const messages = (Array.isArray(hints) ? hints : [])
        .map((hint) => (hint && typeof hint.message === 'string' ? hint.message : ''))
        .filter((message) => message !== '');
    if (messages.length === 0) {
        return null;
    }

    const alert = document.createElement('div');
    alert.className = 'alert alert-warning';
    alert.setAttribute('role', 'status');
    alert.dataset.guardrailHints = '';

    const heading = document.createElement('p');
    heading.className = 'mb-1 fw-bold';
    heading.textContent = title;
    alert.appendChild(heading);

    const list = document.createElement('ul');
    list.className = 'mb-0';
    alert.appendChild(list);

    // Every caller inserts the element in the task that created it.
    setTimeout(() => {
        messages.forEach((message) => {
            const item = document.createElement('li');
            item.textContent = message;
            list.appendChild(item);
        });
    }, ANNOUNCE_DELAY_MS);

    return alert;
}
