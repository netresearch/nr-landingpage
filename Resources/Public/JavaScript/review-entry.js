/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

/**
 * The entry page of a share link (ADR-0001, section 5).
 *
 * A share link carries its access code in the URL fragment, which a browser
 * never sends to a server and never puts into a Referer. This script only
 * copies the code from the fragment into the form and removes it from the
 * address bar. It does not submit anything: opening the review takes a click,
 * so a mail scanner that runs a headless browser cannot use up a usage limit
 * by loading the page. The fragment is taken when the page loads and again
 * whenever it changes, so a second link opened in the same tab works too.
 */
(() => {
    const field = document.getElementById('review-token');
    if (!field) {
        return;
    }
    const name = document.getElementById('review-name');
    const takeFragment = () => {
        const fragment = window.location.hash.replace(/^#/, '');
        if (fragment === '') {
            return;
        }
        field.value = fragment;
        window.history.replaceState(null, '', window.location.pathname + window.location.search);
        (name || document.querySelector('button[type="submit"]'))?.focus();
    };
    takeFragment();
    // A second link opened in the same tab only changes the fragment: the page
    // is not loaded again, so the code has to be taken on `hashchange` too.
    window.addEventListener('hashchange', takeFragment);
})();
