/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

import Modal from '@typo3/backend/modal.js';
import Notification from '@typo3/backend/notification.js';

/**
 * The share link form of one draft in the Content Workspace module (ADR-0001).
 *
 * The link comes back in the response of the request that created it, goes
 * into one read-only field and is shown once: it is not kept anywhere else, and
 * closing the panel reloads the page. The same one-time reveal as nr-llm's
 * A2A token module.
 */
class Workspace {
    constructor() {
        this.root = document.getElementById('nr-landingpage-workspace');
        if (!this.root) {
            return;
        }
        this.settings = TYPO3.settings.NrLandingpage?.workspace || {};
        this.panel = document.getElementById('ws-reveal');
        this.field = document.getElementById('ws-reveal-value');
        this.form = document.getElementById('ws-link-form');
        this.error = document.getElementById('ws-form-error');
        this.busy = false;

        this.form?.addEventListener('submit', (event) => {
            event.preventDefault();
            void this.createLink();
        });
        document.getElementById('ws-reveal-copy')?.addEventListener('click', () => {
            void this.copy();
        });
        document.getElementById('ws-reveal-close')?.addEventListener('click', () => {
            this.close();
        });
        this.root.addEventListener('click', (event) => {
            const revoke = event.target.closest('.js-ws-revoke');
            if (revoke) {
                this.confirmRevoke(revoke);
                return;
            }
            const accept = event.target.closest('.js-ws-accept');
            if (accept) {
                void this.settle('acceptSuggestion', accept);
                return;
            }
            const reject = event.target.closest('.js-ws-reject');
            if (reject) {
                void this.settle('rejectSuggestion', reject);
                return;
            }
            if (event.target.closest('#ws-approval-reset')) {
                this.confirmReset();
            }
        });
        document.getElementById('ws-approve-form')?.addEventListener('submit', (event) => {
            event.preventDefault();
            this.confirmApprove();
        });
    }

    label(key) {
        return TYPO3.lang?.['workspace.' + key] || key;
    }

    async post(url, data) {
        let response;
        try {
            response = await fetch(url, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(data),
            });
        } catch {
            throw new Error(this.label('error.generic'));
        }
        let body = null;
        try {
            body = await response.json();
        } catch {
            // Not JSON: reported below with the generic text.
        }
        if (!response.ok || body?.success !== true) {
            throw new Error(body?.error || this.label('error.generic'));
        }

        return body;
    }

    async createLink() {
        // One request at a time, and none while a link is on screen: a
        // second response would replace the link the first one showed.
        if (this.busy || !this.panel.hidden) {
            return;
        }
        const form = this.form;
        const data = {
            draft: this.settings.draftUid,
            label: form.elements.label.value,
            permission: form.elements.permission.value,
            lifetimeDays: Number.parseInt(form.elements.lifetimeDays.value, 10) || 0,
            passphrase: form.elements.passphrase.value,
            maxUses: Number.parseInt(form.elements.maxUses.value, 10) || 0,
        };
        this.error.hidden = true;
        this.busy = true;
        form.querySelector('button[type="submit"]').disabled = true;
        try {
            const body = await this.post(this.settings.ajaxUrls.createLink, data);
            form.reset();
            this.reveal(body.url);
        } catch (error) {
            this.error.textContent = error.message;
            this.error.hidden = false;
            form.querySelector('button[type="submit"]').disabled = false;
        } finally {
            this.busy = false;
        }
    }

    reveal(url) {
        if (typeof url !== 'string' || url === '') {
            return;
        }
        this.field.value = url;
        this.panel.hidden = false;
        this.panel.scrollIntoView({ block: 'nearest' });
        this.panel.focus();
    }

    async copy() {
        try {
            await navigator.clipboard.writeText(this.field.value);
            Notification.success(this.label('reveal.copied'), '');
        } catch {
            // No clipboard permission: select the text so the user can copy it.
            this.field.select();
            Notification.warning(this.label('reveal.copyFailed'), '');
        }
    }

    close() {
        this.field.value = '';
        location.reload();
    }

    /**
     * Accept or reject one suggestion, then show the draft as it is now.
     */
    async settle(route, button) {
        button.disabled = true;
        try {
            await this.post(this.settings.ajaxUrls[route], {
                draft: this.settings.draftUid,
                comment: Number.parseInt(button.dataset.commentUid, 10),
            });
            location.reload();
        } catch (error) {
            button.disabled = false;
            Notification.error(this.label('suggestion.failed'), error.message);
        }
    }

    /**
     * Approve at the version the page shows. The server refuses when the
     * draft changed since, and says so: nothing is created then.
     */
    confirmApprove() {
        const form = document.getElementById('ws-approve-form');
        const parent = Number.parseInt(form.elements.parentPageUid.value, 10) || 0;
        const version = Number.parseInt(form.dataset.version, 10) || 0;
        const modal = Modal.confirm(this.label('approval.confirm.title'), this.label('approval.confirm.message'), Modal.sizes.small);
        let answered = false;
        modal.addEventListener('confirm.button.ok', async () => {
            if (answered) {
                return;
            }
            answered = true;
            modal.hideModal();
            const submit = document.getElementById('ws-approve-submit');
            submit.disabled = true;
            try {
                await this.post(this.settings.ajaxUrls.approve, { draft: this.settings.draftUid, version, parentPageUid: parent });
                Notification.success(this.label('approval.done'), '');
                location.reload();
            } catch (error) {
                submit.disabled = false;
                Notification.error(this.label('approval.failed'), error.message);
            }
        });
        modal.addEventListener('confirm.button.cancel', () => {
            modal.hideModal();
        });
    }

    confirmReset() {
        const modal = Modal.confirm(this.label('approval.reset'), this.label('approval.reset.confirm'), Modal.sizes.small);
        let answered = false;
        modal.addEventListener('confirm.button.ok', async () => {
            if (answered) {
                return;
            }
            answered = true;
            modal.hideModal();
            try {
                await this.post(this.settings.ajaxUrls.resetApproval, { draft: this.settings.draftUid });
                location.reload();
            } catch (error) {
                Notification.error(this.label('approval.failed'), error.message);
            }
        });
        modal.addEventListener('confirm.button.cancel', () => {
            modal.hideModal();
        });
    }

    confirmRevoke(button) {
        const linkUid = Number.parseInt(button.dataset.linkUid, 10);
        const modal = Modal.confirm(this.label('link.revoke'), this.label('link.revoke.confirm'), Modal.sizes.small);
        let answered = false;
        modal.addEventListener('confirm.button.ok', async () => {
            if (answered) {
                return;
            }
            answered = true;
            modal.hideModal();
            try {
                await this.post(this.settings.ajaxUrls.revokeLink, { draft: this.settings.draftUid, link: linkUid });
                location.reload();
            } catch (error) {
                Notification.error(this.label('link.revoke.failed'), error.message);
            }
        });
        modal.addEventListener('confirm.button.cancel', () => {
            modal.hideModal();
        });
    }
}

export default new Workspace();
