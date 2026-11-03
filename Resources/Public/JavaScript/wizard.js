/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

import WizardState from '@netresearch/nr-landingpage/wizard-state.js';
import MultiStepWizard from '@typo3/backend/multi-step-wizard.js';
import Modal from '@typo3/backend/modal.js';
import Notification from '@typo3/backend/notification.js';
import Severity from '@typo3/backend/severity.js';
import Icons from '@typo3/backend/icons.js';
import '@typo3/backend/element/spinner-element.js';

/**
 * Landing Page Wizard using TYPO3 MultiStepWizard modal overlay.
 *
 * Triggered from the backend module launcher page.
 * Uses the same AJAX endpoints and state management as before,
 * but renders inside a native TYPO3 multi-step modal.
 */
class LandingPageWizard {
    constructor() {
        // The title of the page the launcher passes to open() (see the
        // launcher binding at the end of this file).
        this.initialParentPageTitle = '';
        // The running save or section regeneration, or null (see isBusy()).
        this._busy = null;
        // True while the confirmation before a save is open (see confirmAndSave()).
        this._confirmOpen = false;
        // The number of the latest parent page check per picker (see checkParentPage()).
        this._parentPageChecks = new WeakMap();
        this._briefingForm = null;
        this._briefingQuestions = null;
        this._pageFieldsForm = null;
        // Whether the user has pressed a key or a pointer in the wizard since
        // it opened (see focusStep()).
        this._userActed = false;
        // Re-generate mode: the generation info is applied on the first render
        // of the template step only.
        this._generationInfoLoaded = false;
        // Counts open() calls. An action that awaits a reply (save,
        // regenerate a section, image search and generation) compares it
        // afterwards: a wizard closed and opened again in the meantime is not
        // the one the reply belongs to (see isStaleRun()).
        this._run = 0;
        // Counts slide renders, and open() calls. A slide renderer compares it
        // after each reply: any render started since — Back, Next, or a new
        // wizard — makes its reply stale (see beginRender()).
        this._render = 0;
    }

    /**
     * Get AJAX URL from TYPO3 inline settings.
     *
     * @param {string} key
     * @returns {string}
     */
    getAjaxUrl(key) {
        const url = TYPO3.settings.NrLandingpage?.ajaxUrls?.[key] || '';
        if (!url) {
            console.warn('[NrLandingpage] Missing AJAX URL for key:', key);
        }
        return url;
    }

    /**
     * Get a localized label from TYPO3.lang.
     *
     * @param {string} key
     * @param {...(string|number)} args
     * @returns {string}
     */
    label(key, ...args) {
        let text = TYPO3.lang?.[key] || key;
        if (args.length > 0) {
            let i = 0;
            text = text.replace(/%[sd]/g, () => String(args[i++] ?? ''));
        }
        return text;
    }

    /**
     * Perform a fetch request and return parsed JSON.
     *
     * The error thrown for a reply carries its HTTP status as `status`; an
     * error without one means no reply arrived (network, timeout).
     *
     * @param {string} url
     * @param {Object|null} data
     * @param {number} timeout  Milliseconds until the request is given up; 0 waits for the reply
     * @returns {Promise<Object>}
     */
    async fetchJson(url, data = null, timeout = 60000) {
        const controller = new AbortController();
        const timeoutId = timeout > 0 ? setTimeout(() => controller.abort(), timeout) : null;

        const options = {
            method: data ? 'POST' : 'GET',
            credentials: 'same-origin',
            headers: {},
            signal: controller.signal,
        };

        if (data) {
            options.headers['Content-Type'] = 'application/json';
            options.body = JSON.stringify(data);
        }

        let response;
        try {
            response = await fetch(url, options);
        } catch (error) {
            if (error.name === 'AbortError') {
                throw new Error('Request timed out');
            }
            throw new Error('Network error: ' + error.message);
        } finally {
            if (timeoutId !== null) {
                clearTimeout(timeoutId);
            }
        }

        if (!response.ok) {
            let errorMsg = `HTTP ${response.status}: ${response.statusText}`;
            try {
                const errorJson = await response.json();
                if (errorJson.error) errorMsg = errorJson.error;
            } catch (_) { /* non-JSON error response, use HTTP status */ }
            const error = new Error(errorMsg);
            error.status = response.status;
            throw error;
        }

        let json;
        try {
            json = await response.json();
        } catch (error) {
            throw new Error('Invalid JSON response from server');
        }

        if (!json.success) {
            const error = new Error(json.error || 'Unknown server error');
            error.status = response.status;
            throw error;
        }

        return json.data;
    }

    /**
     * Open the wizard modal.
     *
     * @param {number} parentPageId
     * @param {number} regeneratePageUid  Page UID to re-generate (0 = new page)
     * @param {Object|null} preSelectTemplate  Template object to skip selection step
     */
    open(parentPageId = 0, regeneratePageUid = 0, preSelectTemplate = null) {
        // The core clears its own slide stack on `wizard-dismissed`, and that
        // handler is only bound inside initializeEvents(), which runs after a
        // dynamic import resolves. A modal closed before that — or dismissed
        // from outside — leaves both the stack and the cached carousel in place.
        // getComponent()/generateSlides() then return the previous run's
        // carousel unchanged, so every addSlide() below is silently ignored and
        // the user sees the last run's slide with only Cancel/Previous/Next.
        // Our own state is reset below; this resets the part the core owns.
        MultiStepWizard.setup.slides = [];
        MultiStepWizard.setup.$carousel = null;
        MultiStepWizard.setup.carousel = null;

        WizardState.reset();
        this._briefingForm = null;
        this._briefingQuestions = null;
        this._pageFieldsForm = null;
        this._generationInfoLoaded = false;
        this._run++;
        this._render++;
        this._busy = null;
        this._confirmOpen = false;
        this._userActed = false;
        if (parentPageId > 0) {
            WizardState.setParentPage(parentPageId, this.initialParentPageTitle);
        }
        if (regeneratePageUid > 0) {
            WizardState.sourcePageUid = regeneratePageUid;
            WizardState.regenerateMode = true;
        }

        // When a template is provided directly, set it and skip the selection slide
        if (preSelectTemplate?.uid) {
            WizardState.setTemplate(preSelectTemplate);
        } else {
            MultiStepWizard.addSlide(
                'landing-page-template',
                this.label('wizard.step.template'),
                '',
                Severity.info,
                this.label('wizard.step.template'),
                ($slide) => this.renderTemplateSlide($slide),
            );
        }

        MultiStepWizard.addSlide(
            'landing-page-briefing',
            this.label('wizard.step.briefing'),
            '',
            Severity.info,
            this.label('wizard.step.briefing'),
            ($slide) => this.renderBriefingSlide($slide),
        );

        MultiStepWizard.addSlide(
            'landing-page-fields',
            this.label('wizard.step.pageFields'),
            '',
            Severity.info,
            this.label('wizard.step.pageFields'),
            ($slide) => this.renderPageFieldsSlide($slide),
        );

        MultiStepWizard.addSlide(
            'landing-page-content',
            this.label('wizard.step.content'),
            '',
            Severity.info,
            this.label('wizard.step.content'),
            ($slide) => this.renderContentSlide($slide),
        );

        MultiStepWizard.addSlide(
            'landing-page-placement',
            this.label('wizard.step.placement'),
            '',
            Severity.notice,
            this.label('wizard.step.placement'),
            ($slide) => this.renderPlacementSlide($slide),
        );

        MultiStepWizard.show();
        this.seedCurrentModal();

        // Enable keyboard navigation: Enter advances to next step.
        // Use a polling approach to attach the handler once the modal DOM exists,
        // because the jQuery wizard-visible event may not propagate across frames.
        let keyboardAttempts = 0;
        const attachKeyboardHandler = () => {
            const carousel = MultiStepWizard.getComponent();
            const modal = carousel?.closest('.modal')?.get(0);
            if (!modal) {
                if (++keyboardAttempts < 50) {
                    setTimeout(attachKeyboardHandler, 100);
                }
                return;
            }

            // Bootstrap's carousel listens for ArrowLeft/ArrowRight on the
            // .carousel element and slides to the previous/next step, past the
            // locked Next button and away from the focused control (a select,
            // a button, a radio card; Bootstrap itself skips inputs and
            // textareas). Arrow keys inside the steps belong to those controls,
            // so they stop at .carousel-inner. No preventDefault: the control
            // still gets its own arrow-key behaviour.
            const carouselInner = modal.querySelector('.carousel-inner');
            carouselInner?.addEventListener('keydown', (e) => {
                if (e.key === 'ArrowLeft' || e.key === 'ArrowRight') {
                    e.stopPropagation();
                }
            });

            // See focusStep(): until the user presses a key or a pointer in
            // the wizard, the focus is where core put it on opening.
            const userActed = () => { this._userActed = true; };
            modal.addEventListener('keydown', userActed, true);
            modal.addEventListener('pointerdown', userActed, true);

            modal.addEventListener('keydown', (e) => {
                if (e.key !== 'Enter') return;

                // An inner handler already used this Enter (image search
                // input, template card): it must not also advance the wizard.
                if (e.defaultPrevented) return;

                // Don't intercept Enter in textareas (multiline input),
                // on buttons (let native click), or on selects (let native open/select)
                const tag = e.target?.tagName;
                if (tag === 'TEXTAREA' || tag === 'BUTTON' || tag === 'SELECT') return;

                const nextBtn = modal.querySelector('button[name="next"]');
                if (nextBtn && !nextBtn.disabled) {
                    e.preventDefault();
                    nextBtn.click();
                }
            });
        };
        attachKeyboardHandler();
    }

    /**
     * Work around a TYPO3 v14 core race that empties the first wizard step.
     *
     * `ModalElement.firstUpdated()` calls the `Modal.advanced()` callback
     * straight away, while `showModal()` awaits one animation frame before
     * dispatching `typo3-modal-show` — and that event is the only thing that
     * assigns `Modal.currentModal`. Core's own callback ends in
     * `MultiStepWizard.initializeEvents()`, whose last statement is
     * `Modal.currentModal.addEventListener(...)`. When the progress-tracker
     * import it awaits resolves from cache, it wins that frame and the read
     * throws.
     *
     * Everything after the throw is skipped: the Next/Previous locks, the
     * `wizard-dismissed` reset — and the FIRST slide's renderer. Opening from a
     * template card pre-selects the template, which drops the template slide and
     * makes briefing slide 0, so the step arrives with no fields at all. That is
     * the empty briefing reported from the demo.
     *
     * `generate()` appends the modal element synchronously, so by the time
     * `show()` returns the element core is about to assign is already in the
     * DOM. Seeding it changes nothing else: `typo3-modal-show` overwrites the
     * property with the same element, and pushing to `Modal.instances` stays
     * core's job. A modal that is legitimately open keeps the slot.
     *
     * Remove once the core bug is fixed — `initializeEvents()` already has the
     * element in its own first line (`$carousel.closest('.modal')`).
     */
    seedCurrentModal() {
        if (Modal.currentModal) {
            return;
        }

        // This document, not top's: modal.js appends into the document of the
        // realm that loaded it, and that is the same realm whose Modal
        // singleton MultiStepWizard reads. Reaching for top.document would
        // both miss and risk a cross-origin throw inside open().
        const modals = document.querySelectorAll('typo3-backend-modal');
        if (modals.length > 0) {
            Modal.currentModal = modals[modals.length - 1];
        }
    }

    /**
     * True when the wizard was opened again since `run` was taken.
     *
     * An action that awaits a reply checks this before it touches anything:
     * `MultiStepWizard` and `WizardState` are singletons, so a reply for a
     * closed wizard would otherwise write into the state of the wizard opened
     * after it, or dismiss it.
     *
     * @param {number} run  The value of `_run` when the action started
     * @returns {boolean}
     */
    isStaleRun(run) {
        return run !== this._run;
    }

    /**
     * True when an image search or generation reply no longer belongs to the
     * section it was asked for: the wizard was opened again, or the section's
     * image list was replaced — the content step rendered again (Back and
     * Next) or the section was regenerated. Applied anyway, a recommended
     * image in the reply would be chosen for a section the user never saw it
     * in.
     *
     * @param {number} run  The value of `_run` when the request started
     * @param {HTMLElement} imageList  The section's image list at that time
     * @returns {boolean}
     */
    isStaleImageReply(run, imageList) {
        return this.isStaleRun(run) || !imageList.isConnected;
    }

    /**
     * True when a section regeneration reply no longer belongs to the section
     * it was asked for: the wizard was opened again, or the content step has
     * rendered its sections anew since (the user left the step and came
     * back), which replaces the section card.
     *
     * @param {number} run  The value of `_run` when the request started
     * @param {HTMLElement|null} card  The section card at that time
     * @returns {boolean}
     */
    isStaleSectionReply(run, card) {
        return this.isStaleRun(run) || (card !== null && !card.isConnected);
    }

    /**
     * Start a slide render and return its token.
     *
     * Every slide render takes one, so a reply that arrives after the user
     * has moved on — Back while the briefing loads, another template picked
     * and Next again, or a closed and reopened wizard — is recognised by its
     * renderer (isStaleRender()) and dropped: it must not lock or unlock Next
     * on the step now shown, press Next, or replace that step's form.
     *
     * @returns {number}
     */
    beginRender() {
        return ++this._render;
    }

    /**
     * True when another slide render, or a new wizard, has started since
     * `token` was taken, or when the slide is no longer in the document: the
     * wizard was closed. Core resets its own state on `wizard-dismissed`, so
     * locking or unlocking Next for a closed wizard that was not opened again
     * throws.
     *
     * @param {number} token  The value beginRender() returned
     * @param {HTMLElement} container  The slide the renderer writes into
     * @returns {boolean}
     */
    isStaleRender(token, container) {
        return token !== this._render || !container.isConnected;
    }

    /**
     * True while a save or a section regeneration runs: another one must not
     * start. A section regeneration whose card has been replaced since — the
     * content step rendered its sections anew after Back and Next — does not
     * count: its reply is dropped (isStaleSectionReply()), and Regenerate on
     * the new card would otherwise do nothing until it arrives.
     *
     * @returns {boolean}
     */
    isBusy() {
        const owner = this._busy;
        return owner !== null && (owner.card === null || owner.card.isConnected);
    }

    /**
     * Mark a save or a section regeneration as running and return its owner,
     * which releaseBusy() takes when the request is over.
     *
     * @param {HTMLElement|null} card  The section card being regenerated
     * @returns {{card: HTMLElement|null}}
     */
    takeBusy(card) {
        this._busy = { card };
        return this._busy;
    }

    /**
     * Clear the busy flag if `owner` still holds it. A new wizard (open()
     * resets the flag) or a regeneration on a replaced card may have taken it
     * since, and their request is still running.
     *
     * @param {{card: HTMLElement|null}} owner  The value takeBusy() returned
     */
    releaseBusy(owner) {
        if (this._busy === owner) {
            this._busy = null;
        }
    }

    /**
     * Re-generate mode, first render of the template step only: fetch the
     * generation info and store its briefing answers and parent page for the
     * later steps.
     *
     * @param {number} token  The template renderer's token from beginRender()
     * @param {HTMLElement} container  The template slide
     * @returns {Promise<Object|null>}  null outside that first render, when the
     *     request fails (non-fatal: the wizard continues without pre-fill), and
     *     when another render has started in the meantime
     */
    async loadGenerationInfo(token, container) {
        if (!WizardState.regenerateMode || WizardState.sourcePageUid <= 0 || this._generationInfoLoaded) {
            return null;
        }
        this._generationInfoLoaded = true;

        try {
            const generationInfo = await this.fetchJson(this.getAjaxUrl('generationInfo'), {
                pageUid: WizardState.sourcePageUid,
            });
            if (this.isStaleRender(token, container)) {
                return null;
            }
            if (generationInfo.briefingAnswers) {
                WizardState.setBriefingAnswers(generationInfo.briefingAnswers);
            }
            if (generationInfo.parentPageId > 0 && WizardState.getParentPageId() === 0) {
                WizardState.setParentPage(generationInfo.parentPageId, generationInfo.parentPageTitle || '');
            }
            return generationInfo;
        } catch (infoError) {
            return null;
        }
    }

    /**
     * Focus `element` when the focus has been lost: nothing focused, the
     * body, a control that was disabled while it had focus, or a control in a
     * step that is no longer shown (Enter in a field presses Next and leaves
     * the focus in that field; Chromium moves it to the body only later).
     * Focus the user has moved somewhere else stays there. The modal lives in
     * the top document, not in this module's frame, so the element's own
     * document is the one asked.
     *
     * @param {HTMLElement} element
     */
    focusIfLost(element) {
        const active = element.ownerDocument.activeElement;
        if (!active || active === element.ownerDocument.body || active.disabled === true
            || active.closest('.carousel-item:not(.active)') !== null) {
            element.focus();
        }
    }

    /**
     * Give a step that has finished loading its content a defined focus.
     *
     * Core locks Next as the step slides in (`forceSelection`), which
     * disables the button that has focus, and moves focus nowhere; a step that
     * then waits for a reply leaves it on the body. Like core's modal, which
     * focuses a control of the dialog once it has opened, this focuses the
     * step's first field; a step that failed to load counts its Retry button
     * as that field (Next stays locked, see showSlideError()). A step without
     * either (no content) gets Next when it is enabled, otherwise Previous,
     * otherwise Cancel. Only when the focus
     * has been lost (see focusIfLost()): call it after the step has locked or
     * unlocked Next.
     *
     * Two more places count as lost, because the step change left the focus
     * there, not the user:
     * - Next, when the step has a field. Next is locked from the moment the
     *   step slides in until its reply has been rendered, so no one can have
     *   moved focus there in between: it is the press that started the step.
     *   Chromium does not always move focus off the disabled button: after
     *   Next from the template step it reports the body, after Next from the
     *   briefing still the button, enabled again.
     * - Core's initial focus (the modal's active footer button, Cancel) while
     *   the user has not pressed a key or a pointer in the wizard yet: in
     *   re-generate mode the template step advances by itself, and the focus
     *   core set on opening is still there.
     *
     * @param {HTMLElement} container  The step's slide
     */
    focusStep(container) {
        const field = container.querySelector(
            'input:not([type="hidden"]):not([disabled]), select:not([disabled]), textarea:not([disabled])',
        ) ?? container.querySelector('button[data-step-retry]');
        const modal = container.closest('.modal');
        const button = (name) => modal?.querySelector('button[name="' + name + '"]:not([disabled])') ?? null;
        const target = field ?? button('next') ?? button('prev') ?? button('cancel');
        if (!target) {
            return;
        }
        const active = container.ownerDocument.activeElement;
        const pressedNext = active !== null && active.getAttribute('name') === 'next' && modal?.contains(active) === true;
        const initialFocus = !this._userActed && active?.classList.contains('t3js-active') === true
            && modal?.contains(active) === true;
        if (target !== active && ((field !== null && pressedNext) || initialFocus)) {
            target.focus();
        } else {
            this.focusIfLost(target);
        }
    }

    // ── Slide renderers ──────────────────────────────────────────

    /**
     * Step 1: Template selection.
     *
     * In re-generate mode, the first render fetches the generation info to
     * pre-select the stored template, store the briefing answers and advance.
     * Back re-runs this renderer; later renders keep what the user has chosen
     * and typed since, and stay on this step so another template can be picked.
     */
    async renderTemplateSlide($slide) {
        const token = this.beginRender();
        const container = this.getSlideElement($slide);
        container.innerHTML = this.spinnerHtml(this.label('wizard.loading.templates'));
        MultiStepWizard.lockNextStep();

        try {
            // In re-generate mode, load generation info in parallel with templates
            const templatePromise = this.fetchJson(this.getAjaxUrl('templates'));
            const generationInfo = await this.loadGenerationInfo(token, container);

            const templates = await templatePromise;
            if (this.isStaleRender(token, container)) {
                return;
            }
            container.innerHTML = '';

            if (!templates || templates.length === 0) {
                const alert = document.createElement('div');
                alert.className = 'alert alert-warning';
                alert.setAttribute('role', 'alert');
                alert.textContent = this.label('wizard.template.none');
                container.appendChild(alert);
                return;
            }

            // Show re-generate info banner
            if (WizardState.regenerateMode) {
                const infoBanner = document.createElement('div');
                infoBanner.className = 'alert alert-info mb-3';
                infoBanner.textContent = this.label('wizard.regenerate.info');
                container.appendChild(infoBanner);
            }

            const heading = document.createElement('p');
            heading.className = 'text-variant mb-3';
            heading.id = 'nr-landingpage-template-select-label';
            heading.textContent = this.label('wizard.template.select');
            container.appendChild(heading);

            // The template cards are a single choice: WAI-ARIA radio group with
            // roving tabindex. Arrow keys move focus and check, Space and Enter
            // check. The card handler prevents the default of that Enter, so the
            // modal's Enter-to-Next handler leaves it alone and Next stays a
            // separate step.
            const grid = document.createElement('div');
            grid.className = 'row g-3';
            grid.setAttribute('role', 'radiogroup');
            grid.setAttribute('aria-labelledby', heading.id);

            const preSelectUid = generationInfo?.templateUid || 0;
            const checkedUid = WizardState.getTemplate()?.uid;
            const cards = [];
            let keptCard = null;

            // Selection is marked by a 2px border (.border-2, core at 13.4 and
            // 14.3) in the scheme-aware primary colour plus aria-checked.
            // .shadow only adds depth on 13.4, where core still defines it.
            const selectCard = (card, template, moveFocus) => {
                grid.querySelectorAll('.template-card').forEach((c) => {
                    c.classList.remove('border-2', 'shadow');
                    c.style.removeProperty('border-color');
                    c.setAttribute('aria-checked', 'false');
                    c.setAttribute('tabindex', '-1');
                });
                card.classList.add('border-2', 'shadow');
                card.style.setProperty('border-color', 'var(--typo3-component-primary-color)');
                card.setAttribute('aria-checked', 'true');
                card.setAttribute('tabindex', '0');
                if (moveFocus) {
                    card.focus();
                }
                WizardState.setTemplate(template);
                MultiStepWizard.unlockNextStep();
            };

            templates.forEach((template, position) => {
                const col = document.createElement('div');
                col.className = 'col-12 col-md-6';

                const card = document.createElement('div');
                card.className = 'card h-100 template-card';
                card.style.cursor = 'pointer';
                card.setAttribute('role', 'radio');
                card.setAttribute('aria-checked', 'false');
                // Roving tabindex: until a card is checked, the first card is
                // the group's tab stop.
                card.setAttribute('tabindex', position === 0 ? '0' : '-1');
                card.setAttribute('aria-label', template.title);

                const cardBody = document.createElement('div');
                cardBody.className = 'card-body';

                const title = document.createElement('h2');
                title.className = 'card-title h5';
                title.textContent = template.title;

                const description = document.createElement('p');
                description.className = 'card-text text-variant';
                description.id = 'nr-landingpage-template-' + position + '-description';
                description.textContent = template.description || '';

                const badge = document.createElement('span');
                badge.className = 'badge badge-info';
                badge.id = 'nr-landingpage-template-' + position + '-briefing';
                badge.textContent = this.label('wizard.template.briefingBadge', template.briefingMode || 'none');

                // The radio's name is the title (aria-label); its description
                // and briefing mode are read after it.
                card.setAttribute('aria-describedby', (template.description ? description.id + ' ' : '') + badge.id);

                cardBody.appendChild(title);
                cardBody.appendChild(description);
                cardBody.appendChild(badge);
                card.appendChild(cardBody);
                col.appendChild(card);
                grid.appendChild(col);
                cards.push({ card, template });

                card.addEventListener('click', () => selectCard(card, template, false));
                card.addEventListener('keydown', (e) => {
                    const step = { ArrowRight: 1, ArrowDown: 1, ArrowLeft: -1, ArrowUp: -1 }[e.key];
                    if (step !== undefined) {
                        e.preventDefault();
                        const target = cards[(position + step + cards.length) % cards.length];
                        selectCard(target.card, target.template, true);
                    } else if (e.key === ' ' || e.key === 'Enter') {
                        e.preventDefault();
                        selectCard(card, template, false);
                    }
                });

                // Auto-select and auto-advance in re-generate mode, on the
                // first render only: preSelectUid is 0 on later renders.
                if (preSelectUid > 0 && template.uid === preSelectUid) {
                    selectCard(card, template, false);
                    MultiStepWizard.triggerStepButton('next');
                } else if (preSelectUid === 0 && checkedUid !== undefined && template.uid === checkedUid) {
                    // Back from a later step re-renders this slide: keep the
                    // template the wizard state still holds checked, with its
                    // tab stop, and Next unlocked.
                    selectCard(card, template, false);
                    keptCard = card;
                }
            });

            container.appendChild(grid);

            // Back disables core's Back button on this first step while it
            // still has focus, which drops focus to the body. Put it on the
            // checked card, the group's tab stop.
            if (keptCard) {
                this.focusIfLost(keptCard);
            }

            // Warn if original template was deleted and could not be pre-selected
            if (preSelectUid > 0 && !WizardState.getTemplate()) {
                const warning = document.createElement('div');
                warning.className = 'alert alert-warning mt-3';
                warning.textContent = this.label('wizard.regenerate.templateDeleted');
                container.appendChild(warning);
            }
        } catch (error) {
            if (this.isStaleRender(token, container)) {
                return;
            }
            this.showSlideError(container, this.label('wizard.error.templates', error.message), () => this.renderTemplateSlide($slide));
            this.focusStep(container);
        }
    }

    /**
     * Step 2: Briefing questions.
     */
    async renderBriefingSlide($slide) {
        const token = this.beginRender();
        const container = this.getSlideElement($slide);
        const template = WizardState.getTemplate();

        if (!template || template.briefingMode === 'none') {
            const msg = document.createElement('p');
            msg.className = 'text-variant';
            msg.textContent = this.label('wizard.briefing.skipped');
            container.appendChild(msg);
            MultiStepWizard.unlockNextStep();
            this.focusStep(container);
            return;
        }

        container.innerHTML = this.spinnerHtml(this.label('wizard.loading.briefing'));
        MultiStepWizard.lockNextStep();

        try {
            const questions = await this.fetchJson(this.getAjaxUrl('generateBriefing'), {
                templateUid: template.uid,
                parentPageId: WizardState.getParentPageId(),
            });
            if (this.isStaleRender(token, container)) {
                return;
            }

            container.innerHTML = '';

            const description = document.createElement('p');
            description.className = 'text-variant mb-3';
            description.textContent = this.label('wizard.briefing.description');
            container.appendChild(description);

            const form = document.createElement('form');
            form.addEventListener('submit', (e) => e.preventDefault());

            // Pre-fill title from saved briefing answers in re-generate mode
            const savedAnswers = WizardState.getBriefingAnswers();
            const prefillTitle = savedAnswers.title || '';

            form.appendChild(this.createFormGroup(
                'briefing_title',
                this.label('wizard.briefing.titleLabel'),
                'text',
                prefillTitle,
                true,
                this.label('wizard.briefing.titlePlaceholder'),
            ));

            if (Array.isArray(questions)) {
                questions.forEach((question, index) => {
                    const fieldId = 'briefing_q_' + index;
                    const type = question.type || 'text';
                    const required = question.required === true;
                    const placeholder = question.placeholder || '';
                    const labelText = question.label || question.question || 'Question ' + (index + 1);

                    // Try to pre-fill from saved answers
                    const questionKey = question.id || question.label || 'question_' + index;
                    const prefillValue = savedAnswers[questionKey] || '';

                    if (type === 'select' && Array.isArray(question.options)) {
                        const group = this.createSelectGroup(fieldId, labelText, question.options, required);
                        if (prefillValue) {
                            const select = group.querySelector('select');
                            if (select) {
                                select.value = prefillValue;
                            }
                        }
                        form.appendChild(group);
                    } else if (type === 'textarea') {
                        form.appendChild(this.createFormGroup(fieldId, labelText, 'textarea', prefillValue, required, placeholder));
                    } else {
                        form.appendChild(this.createFormGroup(fieldId, labelText, 'text', prefillValue, required, placeholder));
                    }
                });
            }

            container.appendChild(form);
            this._briefingForm = form;
            this._briefingQuestions = questions;

            // Keep the answers in the wizard state as they are typed, not only
            // on the way forward: Back to the template step and Next again
            // re-renders this form from the state.
            form.addEventListener('input', () => this.collectAndStoreBriefingAnswers());
            form.addEventListener('change', () => this.collectAndStoreBriefingAnswers());

            const titleInput = form.querySelector('#briefing_title');
            const checkUnlock = () => {
                if (titleInput?.value?.trim()) {
                    MultiStepWizard.unlockNextStep();
                } else if (template.briefingMode !== 'optional') {
                    MultiStepWizard.lockNextStep();
                }
            };
            titleInput?.addEventListener('input', checkUnlock);

            // Check immediately — handles pre-filled values in re-generate mode
            checkUnlock();

            if (template.briefingMode === 'optional') {
                MultiStepWizard.unlockNextStep();
            }
            this.focusStep(container);
        } catch (error) {
            if (this.isStaleRender(token, container)) {
                return;
            }
            this.showSlideError(container, this.label('wizard.error.briefing', error.message), () => this.renderBriefingSlide($slide));
            this.focusStep(container);
        }
    }

    /**
     * Collect briefing answers from the briefing form if it's still in the DOM.
     */
    collectAndStoreBriefingAnswers() {
        const form = this._briefingForm;
        if (!form || !form.isConnected) return;

        const answers = this.collectBriefingAnswers(form, this._briefingQuestions);
        const titleInput = form.querySelector('#briefing_title');
        const titleVal = titleInput?.value?.trim() || '';
        if (titleVal) {
            answers.title = titleVal;
        }
        // Always write the title, also when it was cleared: the answers are
        // stored while typing, so a skipped empty value would keep the last
        // typed one for the page-fields step.
        WizardState.setTitle(titleVal);
        WizardState.setSlug(titleVal ? this.generateSlug(titleVal) : '');
        WizardState.setBriefingAnswers(answers);
    }

    /**
     * Collect page field edits from the page fields form if it's still in the DOM.
     */
    collectAndStorePageFields() {
        const form = this._pageFieldsForm;
        if (!form || !form.isConnected) return;

        const pageFields = this.collectPageFields(form);
        WizardState.setPageFields(pageFields);
        WizardState.setTitle(pageFields.title || '');
        WizardState.setSlug(pageFields.slug || '');
    }

    /**
     * Step 3: Page fields (SEO, metadata).
     */
    async renderPageFieldsSlide($slide) {
        const token = this.beginRender();
        this.collectAndStoreBriefingAnswers();

        const container = this.getSlideElement($slide);
        container.innerHTML = this.spinnerHtml(this.label('wizard.loading.pageFields'));
        MultiStepWizard.lockNextStep();

        try {
            const template = WizardState.getTemplate();
            if (!template?.uid) {
                throw new Error('No template selected');
            }
            const fields = await this.fetchJson(this.getAjaxUrl('generatePageFields'), {
                templateUid: template.uid,
                briefingAnswers: WizardState.getBriefingAnswers(),
                parentPageId: WizardState.getParentPageId(),
            });
            if (this.isStaleRender(token, container)) {
                return;
            }

            container.innerHTML = '';

            const description = document.createElement('p');
            description.className = 'text-variant mb-3';
            description.textContent = this.label('wizard.pageFields.description');
            container.appendChild(description);

            const form = document.createElement('form');
            form.addEventListener('submit', (e) => e.preventDefault());

            const titleValue = fields.title || WizardState.getTitle() || '';
            form.appendChild(this.createFormGroup('pf_title', this.label('wizard.pageFields.pageTitle'), 'text', titleValue, true));

            const slugValue = fields.slug || WizardState.getSlug() || this.generateSlug(titleValue);
            form.appendChild(this.createFormGroup('pf_slug', this.label('wizard.pageFields.urlSlug'), 'text', slugValue, false));

            const seoTitleValue = fields.seo_title || '';
            const seoGroup = this.createFormGroup('pf_seo_title', this.label('wizard.pageFields.seoTitle'), 'text', seoTitleValue, false);
            this.addCharacterCounter(seoGroup, 'pf_seo_title', 60);
            form.appendChild(seoGroup);

            const descValue = fields.description || '';
            const descGroup = this.createFormGroup('pf_description', this.label('wizard.pageFields.metaDescription'), 'textarea', descValue, false);
            this.addCharacterCounter(descGroup, 'pf_description', 160);
            form.appendChild(descGroup);

            if (fields.og_title !== undefined) {
                form.appendChild(this.createFormGroup('pf_og_title', this.label('wizard.pageFields.ogTitle'), 'text', fields.og_title || '', false));
            }
            if (fields.og_description !== undefined) {
                form.appendChild(this.createFormGroup('pf_og_description', this.label('wizard.pageFields.ogDescription'), 'textarea', fields.og_description || '', false));
            }

            const knownFields = ['title', 'slug', 'seo_title', 'description', 'og_title', 'og_description'];
            Object.keys(fields).forEach((key) => {
                if (!knownFields.includes(key) && typeof fields[key] === 'string') {
                    form.appendChild(this.createFormGroup('pf_' + key, this.humanizeFieldName(key), 'text', fields[key], false));
                }
            });

            container.appendChild(form);

            const titleInput = form.querySelector('#pf_title');
            const slugInput = form.querySelector('#pf_slug');
            if (titleInput && slugInput) {
                titleInput.addEventListener('input', () => {
                    slugInput.value = this.generateSlug(titleInput.value);
                });
            }

            this._pageFieldsForm = form;
            WizardState.setPageFields(fields);
            MultiStepWizard.unlockNextStep();
            this.focusStep(container);
        } catch (error) {
            if (this.isStaleRender(token, container)) {
                return;
            }
            this.showSlideError(container, this.label('wizard.error.pageFields', error.message), () => this.renderPageFieldsSlide($slide));
            this.focusStep(container);
        }
    }

    /**
     * Step 4: Content sections.
     */
    async renderContentSlide($slide) {
        const token = this.beginRender();
        this.collectAndStorePageFields();

        const container = this.getSlideElement($slide);
        container.innerHTML = this.spinnerHtml(this.label('wizard.loading.content'));
        MultiStepWizard.lockNextStep();

        try {
            const template = WizardState.getTemplate();
            if (!template?.uid) {
                throw new Error('No template selected');
            }
            const result = await this.fetchJson(this.getAjaxUrl('generateContent'), {
                templateUid: template.uid,
                briefingAnswers: WizardState.getBriefingAnswers(),
                parentPageId: WizardState.getParentPageId(),
            });
            if (this.isStaleRender(token, container)) {
                return;
            }

            const sections = result.sections || [];
            const images = result.images || [];
            const imageErrors = result.imageErrors || [];
            const hasImageTask = result.hasImageTask || false;
            const aiAvailable = result.aiGenerationAvailable || false;

            const generationMode = result.generationMode || 'structured';

            WizardState.setContentSections(sections);
            WizardState.setImages(images);
            WizardState.imageErrors = imageErrors;
            WizardState.hasImageTask = hasImageTask;
            WizardState.aiGenerationAvailable = aiAvailable;
            WizardState.generationMode = generationMode;

            if (generationMode === 'creative') {
                this.renderCreativeContentSections(container, sections);
            } else {
                this.renderContentSections(container, sections, images);
            }
            MultiStepWizard.unlockNextStep();
            this.focusStep(container);
        } catch (error) {
            if (this.isStaleRender(token, container)) {
                return;
            }
            this.showSlideError(container, this.label('wizard.error.content', error.message), () => this.renderContentSlide($slide));
            this.focusStep(container);
        }
    }

    /**
     * Render content sections inside a container.
     *
     * @param {HTMLElement} container
     * @param {Array} sections
     * @param {Array} images
     */
    renderContentSections(container, sections, images) {
        if (!this.renderContentIntro(container, 'wizard.content.description', sections)) {
            return;
        }

        sections.forEach((section, index) => {
            const card = document.createElement('div');
            card.className = 'card mb-3';
            card.id = 'section-card-' + index;

            const cardHeader = document.createElement('div');
            cardHeader.className = 'card-header d-flex justify-content-between align-items-center';

            // Build section title with DOM methods instead of innerHTML
            const sectionTitle = document.createElement('span');
            const strong = document.createElement('strong');
            strong.textContent = section.section || 'Section ' + (index + 1);
            sectionTitle.appendChild(strong);
            sectionTitle.appendChild(document.createTextNode(' '));
            const ctypeBadge = document.createElement('span');
            ctypeBadge.className = 'badge badge-default ms-2';
            ctypeBadge.textContent = section.ctype || 'text';
            sectionTitle.appendChild(ctypeBadge);

            const regenerateBtn = this.createButton(this.label('wizard.button.regenerate'), 'btn btn-sm btn-default', async () => {
                await this.regenerateSection(container, index);
            });
            regenerateBtn.setAttribute('aria-label', this.label('wizard.button.regenerate') + ' ' + (index + 1));

            cardHeader.appendChild(sectionTitle);
            cardHeader.appendChild(regenerateBtn);

            const cardBody = document.createElement('div');
            cardBody.className = 'card-body';

            if (section.header) {
                const header = document.createElement('input');
                header.type = 'text';
                header.className = 'form-control section-header mb-2';
                header.value = section.header;
                header.setAttribute('aria-label', this.label('wizard.content.sectionHeader'));
                header.addEventListener('input', () => {
                    WizardState.getContentSections()[index].header = header.value;
                });
                cardBody.appendChild(header);
            }

            if (section.subheader) {
                const subheader = document.createElement('input');
                subheader.type = 'text';
                subheader.className = 'form-control form-control-sm mb-2';
                subheader.value = section.subheader;
                subheader.setAttribute('aria-label', this.label('wizard.content.sectionSubheader'));
                subheader.addEventListener('input', () => {
                    WizardState.getContentSections()[index].subheader = subheader.value;
                });
                cardBody.appendChild(subheader);
            }

            if (section.bodytext) {
                const bodytext = document.createElement('textarea');
                bodytext.className = 'form-control section-bodytext mb-2';
                bodytext.rows = 4;
                bodytext.value = section.bodytext;
                bodytext.setAttribute('aria-label', this.label('wizard.content.sectionBody'));
                bodytext.addEventListener('input', () => {
                    WizardState.getContentSections()[index].bodytext = bodytext.value;
                });
                cardBody.appendChild(bodytext);
            }

            // Image selection area (always shown)
            const sectionImages = (images[index] && images[index].length > 0) ? images[index] : [];
            cardBody.appendChild(this.renderImageSection(section, index, sectionImages, false));

            card.appendChild(cardHeader);
            card.appendChild(cardBody);
            container.appendChild(card);
        });
    }

    /**
     * Render creative mode content sections with HTML preview and source editor.
     *
     * @param {HTMLElement} container
     * @param {Array} sections
     */
    renderCreativeContentSections(container, sections) {
        if (!this.renderContentIntro(container, 'wizard.content.creativeDescription', sections)) {
            return;
        }

        sections.forEach((section, index) => {
            const card = document.createElement('div');
            card.className = 'card mb-3';
            card.id = 'section-card-' + index;

            const cardHeader = document.createElement('div');
            cardHeader.className = 'card-header d-flex justify-content-between align-items-center';

            const sectionTitle = document.createElement('span');
            const strong = document.createElement('strong');
            strong.textContent = section.section || 'Block ' + (index + 1);
            sectionTitle.appendChild(strong);
            const colBadge = document.createElement('span');
            colBadge.className = 'badge badge-info ms-2';
            colBadge.textContent = 'colPos ' + (section.colPos ?? 0);
            sectionTitle.appendChild(document.createTextNode(' '));
            sectionTitle.appendChild(colBadge);
            const modeBadge = document.createElement('span');
            modeBadge.className = 'badge badge-warning ms-1';
            modeBadge.textContent = 'HTML';
            sectionTitle.appendChild(document.createTextNode(' '));
            sectionTitle.appendChild(modeBadge);

            const btnGroup = document.createElement('div');
            btnGroup.className = 'd-flex gap-1';

            const toggleBtn = this.createButton(
                this.label('wizard.content.creativeToggleSource'),
                'btn btn-sm btn-default',
                () => {
                    const preview = card.querySelector('.creative-preview');
                    const source = card.querySelector('.creative-source');
                    if (preview && source) {
                        const showingSource = source.style.display !== 'none';
                        preview.style.display = showingSource ? 'block' : 'none';
                        source.style.display = showingSource ? 'none' : 'block';
                        toggleBtn.setAttribute('aria-expanded', showingSource ? 'false' : 'true');
                    }
                },
            );
            toggleBtn.setAttribute('aria-expanded', 'false');

            const regenerateBtn = this.createButton(
                this.label('wizard.button.regenerate'),
                'btn btn-sm btn-default',
                async () => { await this.regenerateSection(container, index); },
            );

            btnGroup.appendChild(toggleBtn);
            btnGroup.appendChild(regenerateBtn);
            cardHeader.appendChild(sectionTitle);
            cardHeader.appendChild(btnGroup);

            const cardBody = document.createElement('div');
            cardBody.className = 'card-body';

            // HTML preview (sandboxed in iframe)
            const preview = document.createElement('div');
            preview.className = 'creative-preview border rounded p-0 mb-2';
            const iframe = document.createElement('iframe');
            // allow-same-origin needed for auto-resize via contentDocument; scripts blocked (no allow-scripts)
            iframe.sandbox = 'allow-same-origin';
            iframe.title = section.section || 'Block ' + (index + 1);
            iframe.style.cssText = 'width:100%;border:none;min-height:200px;';
            iframe.srcdoc = section.bodytext || '';
            iframe.addEventListener('load', () => {
                // Auto-resize iframe to content height
                try {
                    const docHeight = iframe.contentDocument?.documentElement?.scrollHeight;
                    if (docHeight) iframe.style.height = docHeight + 'px';
                } catch (_) { /* cross-origin fallback */ }
            });
            preview.appendChild(iframe);
            cardBody.appendChild(preview);

            // Image selection for creative sections
            const images = WizardState.getImages();
            const sectionImages = (images[index] && images[index].length > 0) ? images[index] : [];
            cardBody.appendChild(this.renderImageSection(section, index, sectionImages, true));

            // Source code editor (hidden by default)
            const source = document.createElement('div');
            source.className = 'creative-source';
            source.style.display = 'none';
            const textarea = document.createElement('textarea');
            textarea.className = 'form-control font-monospace';
            textarea.rows = 12;
            textarea.value = section.bodytext || '';
            textarea.setAttribute('aria-label', this.label('wizard.content.creativeSourceLabel'));
            textarea.addEventListener('input', () => {
                WizardState.getContentSections()[index].bodytext = textarea.value;
                iframe.srcdoc = textarea.value;
            });
            source.appendChild(textarea);
            cardBody.appendChild(source);

            card.appendChild(cardHeader);
            card.appendChild(cardBody);
            container.appendChild(card);
        });
    }

    /**
     * Start a content slide: the description paragraph, or an info alert when
     * there are no sections.
     *
     * @param {HTMLElement} container
     * @param {string} descriptionKey
     * @param {Array} sections
     * @returns {boolean} false when there is nothing to render
     */
    renderContentIntro(container, descriptionKey, sections) {
        container.innerHTML = '';

        const description = document.createElement('p');
        description.className = 'text-variant mb-3';
        description.textContent = this.label(descriptionKey);
        container.appendChild(description);

        if (!sections || sections.length === 0) {
            const alert = document.createElement('div');
            alert.className = 'alert alert-info';
            alert.setAttribute('role', 'alert');
            alert.textContent = this.label('wizard.content.none');
            container.appendChild(alert);
            return false;
        }

        return true;
    }

    /**
     * Image selection area of one content section: label, generation error,
     * image cards, automatic-search info, search row and AI generate button.
     *
     * Creative sections differ in two ways: without image keywords the label
     * reads like the search placeholder, and the AI generate button needs
     * image keywords.
     *
     * @param {Object} section
     * @param {number} index
     * @param {Array} sectionImages
     * @param {boolean} creative
     * @returns {HTMLDivElement}
     */
    renderImageSection(section, index, sectionImages, creative) {
        const keywords = section.imageKeywords || [];
        const hasKeywords = keywords.length > 0;

        const imageSection = document.createElement('div');
        imageSection.className = 'mt-3 border-top pt-3';

        const imageLabel = document.createElement('small');
        imageLabel.className = 'text-variant d-block mb-2';
        imageLabel.textContent = (creative && !hasKeywords)
            ? this.label('wizard.content.imageSearchPlaceholder')
            : this.label('wizard.content.imageSuggestions');
        imageSection.appendChild(imageLabel);

        // Show image generation error if present
        const imageError = (WizardState.imageErrors || [])[index];
        if (imageError) {
            const errorAlert = document.createElement('div');
            errorAlert.className = 'alert alert-warning py-1 px-2 mb-2';
            errorAlert.style.fontSize = '0.85em';
            errorAlert.textContent = this.label('wizard.content.imageGenerationError') + ' ' + imageError;
            imageSection.appendChild(errorAlert);
        }

        const imageList = document.createElement('div');
        imageList.className = 'd-flex gap-2 flex-wrap mb-2';
        imageList.setAttribute('role', 'group');
        imageList.setAttribute('aria-label', this.label('wizard.content.imageSuggestions'));

        this.renderImageCards(imageList, sectionImages, index);

        // Show info when automatic search found no images but keywords were present
        if (sectionImages.length === 0 && hasKeywords) {
            const emptyInfo = document.createElement('div');
            emptyInfo.className = 'alert alert-info py-2 px-3 mb-2';
            emptyInfo.style.fontSize = '0.85em';
            emptyInfo.setAttribute('role', 'status');
            emptyInfo.setAttribute('aria-live', 'polite');
            emptyInfo.textContent = this.label('wizard.content.imageAutoSearchEmpty', keywords.join(', '));
            imageSection.appendChild(emptyInfo);
        }

        imageSection.appendChild(imageList);

        // Search input for finding more images — pre-filled with AI keywords
        const searchRow = document.createElement('div');
        searchRow.className = 'd-flex gap-2 align-items-center flex-wrap';

        const searchInput = document.createElement('input');
        searchInput.type = 'text';
        searchInput.className = 'form-control form-control-sm';
        searchInput.placeholder = this.label('wizard.content.imageSearchPlaceholder');
        searchInput.setAttribute('aria-label', this.label('wizard.content.imageSearchPlaceholder'));
        searchInput.style.maxWidth = '250px';
        if (hasKeywords) {
            searchInput.value = keywords.join(' ');
        }

        const searchBtn = this.createIconButton(
            'actions-search',
            this.label('wizard.content.imageSearchButton'),
            'btn btn-sm btn-default',
            async () => {
                const query = searchInput.value.trim();
                if (!query) return;
                const run = this._run;
                searchBtn.disabled = true;
                try {
                    const result = await this.fetchJson(this.getAjaxUrl('searchImages'), { query });
                    if (this.isStaleImageReply(run, imageList)) {
                        return;
                    }
                    const found = result.images || [];
                    if (found.length === 0) {
                        Notification.info(this.label('wizard.content.imageSearchEmpty'));
                    } else {
                        this.renderImageCards(imageList, found, index);
                    }
                } catch (err) {
                    if (this.isStaleImageReply(run, imageList)) {
                        return;
                    }
                    Notification.error(this.label('wizard.error.imageSearch'), err.message);
                } finally {
                    searchBtn.disabled = false;
                }
            },
        );

        searchInput.addEventListener('keydown', (e) => {
            if (e.key === 'Enter') {
                e.preventDefault();
                searchBtn.click();
            }
        });

        searchRow.appendChild(searchInput);
        searchRow.appendChild(searchBtn);

        // AI Generate button (shown when AI source is configured and available;
        // creative sections additionally need image keywords)
        const aiAvailable = WizardState.aiGenerationAvailable || false;
        const hasImageTask = WizardState.hasImageTask || false;
        if (aiAvailable && hasImageTask && (!creative || hasKeywords)) {
            const generateBtn = this.createIconButton(
                'actions-bolt',
                this.label('wizard.content.imageGenerateButton'),
                'btn btn-sm btn-default',
                async () => {
                    const run = this._run;
                    generateBtn.disabled = true;
                    generateBtn.textContent = this.label('wizard.content.imageGenerating');
                    try {
                        const template = WizardState.getTemplate();
                        const sectionData = WizardState.getContentSections()[index] || {};
                        const result = await this.fetchJson(this.getAjaxUrl('generateImage'), {
                            templateUid: template.uid,
                            imagePrompt: sectionData.imagePrompt || '',
                            sectionHeader: sectionData.header || sectionData.section || '',
                        });
                        if (this.isStaleImageReply(run, imageList)) {
                            return;
                        }
                        const img = result.image;
                        if (img) {
                            this.renderImageCards(imageList, [img], index);
                            Notification.success(this.label('wizard.content.imageGenerated'));
                        }
                    } catch (err) {
                        if (this.isStaleImageReply(run, imageList)) {
                            return;
                        }
                        Notification.error(this.label('wizard.error.imageGenerate'), err.message);
                    } finally {
                        generateBtn.disabled = false;
                        this.setIconButtonLabel(generateBtn, this.label('wizard.content.imageGenerateButton'));
                    }
                },
            );
            searchRow.appendChild(generateBtn);
        }

        imageSection.appendChild(searchRow);

        return imageSection;
    }

    /**
     * Render selectable image cards into a container.
     * Merges new images with any already shown (avoids duplicates).
     *
     * @param {HTMLElement} imageList
     * @param {Array} newImages
     * @param {number} sectionIndex
     */
    renderImageCards(imageList, newImages, sectionIndex) {
        // Track already-shown UIDs to avoid duplicates when searching
        const shownUids = new Set();
        imageList.querySelectorAll('[data-image-uid]').forEach((el) => {
            shownUids.add(parseInt(el.dataset.imageUid, 10));
        });

        const sections = WizardState.getContentSections();
        const currentImageUid = sections[sectionIndex].imageUid || 0;

        newImages.forEach((img) => {
            if (shownUids.has(img.uid)) return;
            shownUids.add(img.uid);

            const imgCard = document.createElement('div');
            imgCard.className = 'card text-center';
            imgCard.style.cssText = 'width:120px;cursor:pointer;';
            imgCard.setAttribute('role', 'button');
            imgCard.setAttribute('tabindex', '0');
            imgCard.setAttribute('aria-label', img.title || img.name || 'Image');
            imgCard.dataset.imageUid = String(img.uid);
            imgCard.style.position = 'relative';

            // Auto-select recommended image when no image is selected yet
            const isRecommended = img.recommended === true;
            const shouldAutoSelect = isRecommended && currentImageUid === 0 && sections[sectionIndex].imageUid === 0;
            if (shouldAutoSelect) {
                sections[sectionIndex].imageUid = img.uid;
            }

            const isSelected = sections[sectionIndex].imageUid === img.uid;
            imgCard.setAttribute('aria-pressed', isSelected ? 'true' : 'false');
            if (isSelected) {
                imgCard.classList.add('border-primary', 'border-2', 'shadow-sm');
                this._addCheckOverlay(imgCard);
            }

            // Thumbnail or placeholder
            if (img.publicUrl) {
                const thumbnail = document.createElement('img');
                thumbnail.src = img.publicUrl;
                thumbnail.alt = img.alternative || img.title || img.name || '';
                thumbnail.style.cssText = 'height:80px;object-fit:cover;';
                imgCard.appendChild(thumbnail);
            } else {
                const placeholder = document.createElement('div');
                placeholder.className = 'd-flex align-items-center justify-content-center';
                placeholder.style.cssText = 'height:80px;background:var(--typo3-surface-container-high);';
                placeholder.textContent = '\uD83D\uDDBC';
                imgCard.appendChild(placeholder);
            }

            const imgBody = document.createElement('div');
            imgBody.className = 'card-body p-1';

            // Show "recommended" / "AI" badge
            if (isRecommended || img.generated) {
                const badge = document.createElement('span');
                badge.className = img.generated
                    ? 'badge badge-warning mb-1'
                    : 'badge badge-success mb-1';
                badge.style.fontSize = '0.75rem';
                badge.textContent = img.generated ? 'AI' : '\u2605 Best';
                imgBody.appendChild(badge);
            }

            const imgTitle = document.createElement('small');
            imgTitle.className = 'text-truncate d-block';
            imgTitle.style.maxWidth = '110px';
            imgTitle.textContent = img.title || img.name || 'Image';
            imgBody.appendChild(imgTitle);
            imgCard.appendChild(imgBody);

            const selectImage = () => {
                const secs = WizardState.getContentSections();
                const wasSelected = secs[sectionIndex].imageUid === img.uid;

                secs[sectionIndex].imageUid = wasSelected ? 0 : img.uid;

                // Update visual + ARIA state for all cards in this section's image list
                imageList.querySelectorAll('.card').forEach((c) => {
                    c.classList.remove('border-primary', 'border-2', 'shadow-sm');
                    c.setAttribute('aria-pressed', 'false');
                    const check = c.querySelector('.image-check-overlay');
                    if (check) check.remove();
                });
                if (!wasSelected) {
                    imgCard.classList.add('border-primary', 'border-2', 'shadow-sm');
                    imgCard.setAttribute('aria-pressed', 'true');
                    this._addCheckOverlay(imgCard);
                }
            };

            imgCard.addEventListener('click', selectImage);
            imgCard.addEventListener('keydown', (e) => {
                if (e.key === 'Enter' || e.key === ' ') {
                    e.preventDefault();
                    selectImage();
                }
            });

            imageList.appendChild(imgCard);
        });
    }

    /**
     * Add a checkmark overlay to an image card to visually indicate selection.
     * Provides a non-color visual indicator (WCAG 1.4.1).
     *
     * @param {HTMLElement} card
     */
    _addCheckOverlay(card) {
        const check = document.createElement('span');
        check.className = 'image-check-overlay';
        check.setAttribute('aria-hidden', 'true');
        check.textContent = '\u2713';
        check.style.cssText = 'position:absolute;top:4px;right:4px;background:var(--typo3-surface-primary);color:var(--typo3-surface-primary-text);'
            + 'border-radius:50%;width:20px;height:20px;display:flex;align-items:center;'
            + 'justify-content:center;font-size:12px;font-weight:bold;line-height:1;';
        card.appendChild(check);
    }

    /**
     * Re-render content sections using the appropriate renderer for the current generation mode.
     *
     * @param {HTMLElement} container
     */
    rerenderContentSlide(container) {
        if (WizardState.generationMode === 'creative') {
            this.renderCreativeContentSections(container, WizardState.getContentSections());
        } else {
            this.renderContentSections(container, WizardState.getContentSections(), WizardState.getImages());
        }
    }

    /**
     * Regenerate a single content section via AJAX.
     *
     * @param {HTMLElement} container
     * @param {number} index
     */
    async regenerateSection(container, index) {
        if (this.isBusy()) {
            return;
        }
        const run = this._run;

        // The card lives in the modal, which is in the top document, not in
        // this module's frame: look it up in the content step's own element.
        const card = container.querySelector('#section-card-' + index);
        const owner = this.takeBusy(card);
        if (card) {
            const cardBody = card.querySelector('.card-body');
            if (cardBody) {
                cardBody.innerHTML = this.spinnerHtml(this.label('wizard.loading.regenerating'));
            }
        }

        try {
            const template = WizardState.getTemplate();
            if (!template?.uid) {
                throw new Error('No template selected');
            }
            const newSection = await this.fetchJson(this.getAjaxUrl('regenerateSection'), {
                templateUid: template.uid,
                briefingAnswers: WizardState.getBriefingAnswers(),
                parentPageId: WizardState.getParentPageId(),
                sectionIndex: index,
            });
            if (this.isStaleSectionReply(run, card)) {
                return;
            }

            WizardState.updateContentSection(index, newSection);
            Notification.success(
                this.label('wizard.notification.sectionRegenerated'),
                this.label('wizard.notification.sectionRegeneratedMessage', index + 1),
            );

            this.rerenderContentSlide(container);
        } catch (error) {
            if (this.isStaleSectionReply(run, card)) {
                return;
            }
            Notification.error(this.label('wizard.notification.regenerationFailed'), error.message);
            this.rerenderContentSlide(container);
        } finally {
            this.releaseBusy(owner);
        }
    }

    /**
     * Step 5: Placement & Save.
     */
    async renderPlacementSlide($slide) {
        this.beginRender();
        const container = this.getSlideElement($slide);
        container.innerHTML = '';

        const description = document.createElement('p');
        description.className = 'text-variant mb-3';
        description.textContent = this.label('wizard.placement.description');
        container.appendChild(description);

        const form = document.createElement('form');
        form.addEventListener('submit', (e) => e.preventDefault());

        form.appendChild(this.createFormGroup('placement_title', this.label('wizard.pageFields.pageTitle'), 'text', WizardState.getTitle(), true));
        form.appendChild(this.createFormGroup('placement_slug', this.label('wizard.pageFields.urlSlug'), 'text', WizardState.getSlug(), false));

        form.appendChild(this.createParentPagePicker());

        const titleInput = form.querySelector('#placement_title');
        const slugInput = form.querySelector('#placement_slug');
        if (titleInput && slugInput) {
            titleInput.addEventListener('input', () => {
                slugInput.value = this.generateSlug(titleInput.value);
            });
        }

        container.appendChild(form);

        this.renderSummary(container);

        // Repurpose the Next button as "Generate Landing Page" action
        this.replaceNextButtonWithGenerate(form);
        this.focusStep(container);
    }

    /**
     * The parent page field of the placement step: TYPO3's element browser
     * for pages, the picked page shown with title and id, and a button to
     * remove it. The page id goes into the hidden #placement_parent, which
     * confirmAndSave() and saveLandingPage() read.
     *
     * The server checks the page (checkParentPage) when it is picked and when
     * the step opens with a page already chosen, so a page the editor may not
     * use is named right away, not after the save.
     *
     * @returns {HTMLElement}
     */
    createParentPagePicker() {
        const group = document.createElement('div');
        group.className = 'mb-3';
        group.dataset.placementParent = '';
        // The heading names the group; the buttons keep their visible text
        // as their name (a <label> on "Choose page" would replace it), and
        // the shown page describes the browse button.
        group.setAttribute('role', 'group');
        group.setAttribute('aria-labelledby', 'placement_parent_label');

        const label = document.createElement('div');
        label.className = 'form-label';
        label.id = 'placement_parent_label';
        label.textContent = this.label('wizard.placement.parentPage');
        const asterisk = document.createElement('span');
        asterisk.className = 'text-danger ms-1';
        asterisk.textContent = '*';
        asterisk.setAttribute('aria-hidden', 'true');
        // The asterisk is decoration; this tells a screen reader instead.
        const required = document.createElement('span');
        required.className = 'visually-hidden';
        required.textContent = ' (' + this.label('wizard.placement.parentPage.required') + ')';
        label.append(asterisk, required);

        const input = document.createElement('input');
        input.type = 'hidden';
        input.id = 'placement_parent';
        input.name = 'placement_parent';

        const inputGroup = document.createElement('div');
        inputGroup.className = 'input-group';
        // A plain element, not <output>: a description is read from its text.
        const item = document.createElement('div');
        item.className = 'form-control text-truncate';
        item.id = 'placement_parent_item';
        const browse = document.createElement('button');
        browse.type = 'button';
        browse.className = 'btn btn-default';
        browse.id = 'placement_parent_browse';
        browse.dataset.pickerBrowse = '';
        browse.setAttribute('aria-describedby', 'placement_parent_item placement_parent_help');
        browse.textContent = this.label('wizard.placement.parentPage.browse');
        const remove = document.createElement('button');
        remove.type = 'button';
        remove.className = 'btn btn-default';
        remove.dataset.pickerRemove = '';
        remove.setAttribute('aria-label', this.label('wizard.placement.parentPage.removeLabel'));
        remove.textContent = this.label('wizard.placement.parentPage.remove');
        inputGroup.append(item, browse, remove);

        const help = document.createElement('div');
        help.className = 'form-text';
        help.id = 'placement_parent_help';
        help.textContent = this.label('wizard.placement.parentPage.description');

        // The alert role is there before any text: a live region is
        // announced when its content changes, not when it appears.
        const error = document.createElement('div');
        error.className = 'invalid-feedback';
        error.id = 'placement_parent_error';
        error.dataset.pickerError = '';
        error.setAttribute('role', 'alert');

        group.append(label, input, inputGroup, help, error);

        // textContent paths (not innerHTML): page titles and XLF strings are no markup.
        const show = (id, title) => {
            input.value = id > 0 ? String(id) : '';
            item.textContent = id > 0 ? `${title} [${id}]`.trim() : this.label('wizard.placement.parentPage.empty');
            item.classList.toggle('text-variant', id <= 0);
            remove.hidden = id <= 0;
            WizardState.setParentPage(id, title);
        };
        show(WizardState.getParentPageId(), WizardState.getParentPageTitle());
        if (WizardState.getParentPageId() > 0) {
            void this.checkParentPage(group, show, WizardState.getParentPageId());
        }

        browse.addEventListener('click', () => this.browseParentPage(group, show));
        remove.addEventListener('click', () => {
            show(0, '');
            this.showParentPageError(group, '');
            browse.focus();
        });

        return group;
    }

    /**
     * Open TYPO3's page browser, take the first pick and check it.
     *
     * TYPO3 14 reports a pick as an event on the browser's modal (`useEvents`
     * in its URL), the way FormEngine listens too; the wizard has no
     * FormEngine. TYPO3 13 posts it as a message to this module frame (see
     * LandingPageWizardController::pageBrowserUrl()). Both carry the same
     * action name and value.
     *
     * @param {HTMLElement} group  The picker (createParentPagePicker())
     * @param {function(number, string): void} show  Shows and stores a page
     */
    browseParentPage(group, show) {
        const baseUrl = TYPO3.settings.NrLandingpage?.moduleUrls?.pageBrowser || '';
        if (!baseUrl) {
            return;
        }
        const current = WizardState.getParentPageId();
        const modal = Modal.advanced({
            type: Modal.types.iframe,
            content: current > 0 ? baseUrl + '&expandPage=' + current : baseUrl,
            size: Modal.sizes.large,
        });
        let hidden = false;
        let picked = false;
        const take = (value, label) => {
            if (picked) {
                return;
            }
            picked = true;
            // "pages_12" from the page browser.
            const uid = parseInt(String(value).split('_').pop() ?? '', 10);
            if (uid > 0) {
                show(uid, label ?? '');
                this.showParentPageError(group, '');
                void this.checkParentPage(group, show, uid);
            }
        };

        // TYPO3 14: the browser stays open; one parent page, so any pick ends
        // the browsing.
        modal.addEventListener('typo3:element-browser:message', (event) => {
            const { actionName, value, label } = event.detail ?? {};
            if (actionName !== 'typo3:elementBrowser:elementAdded') {
                return;
            }
            modal.hideModal();
            take(value, label);
        });

        // TYPO3 13: the browser may close itself right after the message (it
        // dismisses the topmost modal). Hiding it here as well would then hide
        // the wizard, so the modal is closed only if it is still open a moment
        // later.
        const onMessage = (event) => {
            // Same origin only, the check MessageUtility.verifyOrigin() makes.
            if (event.origin !== window.origin) {
                return;
            }
            const data = event.data ?? {};
            if (data.actionName !== 'typo3:elementBrowser:elementAdded'
                || data.fieldName !== 'nr-landingpage-parent-page') {
                return;
            }
            take(data.value, data.label);
            setTimeout(() => {
                if (!hidden) {
                    modal.hideModal();
                }
            }, 150);
        };
        window.addEventListener('message', onMessage);
        modal.addEventListener('typo3-modal-hidden', () => {
            hidden = true;
            window.removeEventListener('message', onMessage);
            // TYPO3 14's <dialog> gives the focus back on its own; TYPO3 13's
            // browser focuses the module frame behind the wizard instead.
            const browse = group.querySelector('[data-picker-browse]');
            if (group.isConnected && browse) {
                browse.focus();
            }
        });
    }

    /**
     * Ask the server whether a landing page may be created below the picked
     * page; on a refusal, remove the page again and say why.
     *
     * @param {HTMLElement} group
     * @param {function(number, string): void} show
     * @param {number} uid
     */
    async checkParentPage(group, show, uid) {
        // Only the latest check speaks: the same page picked again must not
        // be removed by the refusal of an earlier check of it.
        const check = (this._parentPageChecks.get(group) ?? 0) + 1;
        this._parentPageChecks.set(group, check);
        try {
            await this.fetchJson(this.getAjaxUrl('checkParentPage'), { parentPageId: uid });
        } catch (error) {
            // Only a refusal says something about the page; a lost
            // connection leaves the pick, and the save checks it again.
            if (typeof error.status === 'number' && error.status >= 400 && error.status < 500
                && this._parentPageChecks.get(group) === check
                && WizardState.getParentPageId() === uid && group.isConnected) {
                show(0, '');
                this.showParentPageError(group, error.message);
            }
        }
    }

    /**
     * Show a message below the parent page picker, or remove it ('').
     *
     * @param {HTMLElement|null} group
     * @param {string} message
     */
    showParentPageError(group, message) {
        const error = group?.querySelector('[data-picker-error]');
        const browse = group?.querySelector('[data-picker-browse]');
        if (!error || !browse) {
            return;
        }
        error.textContent = message;
        error.classList.toggle('d-block', message !== '');
        browse.setAttribute(
            'aria-describedby',
            message !== '' ? 'placement_parent_item placement_parent_help placement_parent_error' : 'placement_parent_item placement_parent_help',
        );
    }

    /**
     * Render a summary of selections.
     *
     * @param {HTMLElement} container
     */
    renderSummary(container) {
        const template = WizardState.getTemplate();
        const sections = WizardState.getContentSections();
        const pageFields = WizardState.getPageFields();

        const summary = document.createElement('div');
        summary.className = 'card mt-4';

        const cardHeader = document.createElement('div');
        cardHeader.className = 'card-header';
        const headerStrong = document.createElement('strong');
        headerStrong.textContent = this.label('wizard.summary');
        cardHeader.appendChild(headerStrong);

        const cardBody = document.createElement('div');
        cardBody.className = 'card-body';

        const list = document.createElement('dl');
        list.className = 'row mb-0';

        if (template) {
            this.addDefinitionItem(list, this.label('wizard.step.template'), template.title);
        }

        const fieldCount = Object.keys(pageFields).length;
        if (fieldCount > 0) {
            this.addDefinitionItem(list, this.label('wizard.step.pageFields'), this.label('wizard.summary.fieldsConfigured', fieldCount));
        }

        if (sections.length > 0) {
            this.addDefinitionItem(list, this.label('wizard.step.content'), this.label('wizard.summary.sections', sections.length));

            const sectionNames = sections
                .map((s) => s.section || s.header || this.label('wizard.summary.untitled'))
                .join(', ');
            this.addDefinitionItem(list, this.label('wizard.summary.sectionNames'), sectionNames);
        }

        cardBody.appendChild(list);
        summary.appendChild(cardHeader);
        summary.appendChild(cardBody);
        container.appendChild(summary);
    }

    /**
     * Add a dt/dd pair to a definition list.
     *
     * @param {HTMLDListElement} dl
     * @param {string} term
     * @param {string} definition
     */
    addDefinitionItem(dl, term, definition) {
        const dt = document.createElement('dt');
        dt.className = 'col-sm-3';
        dt.textContent = term;

        const dd = document.createElement('dd');
        dd.className = 'col-sm-9';
        dd.textContent = definition;

        dl.appendChild(dt);
        dl.appendChild(dd);
    }

    /**
     * Repurpose the modal "Next" button as "Generate Landing Page" on the final step.
     *
     * Replaces the default carousel-advance handler with the save flow,
     * updates the button label, and adds success styling.
     *
     * @param {HTMLFormElement} form
     */
    replaceNextButtonWithGenerate(form) {
        const carousel = MultiStepWizard.getComponent();
        const modal = carousel?.closest('.modal');
        const nextBtn = modal?.find('button[name="next"]');
        if (!nextBtn || nextBtn.length === 0) {
            return;
        }

        // Remove TYPO3's default next-slide handler and attach save flow
        nextBtn.off('click').on('click', (e) => {
            e.preventDefault();
            this.confirmAndSave(form);
        });
        nextBtn.text(this.label('wizard.button.generate'));
        nextBtn.removeClass('btn-primary').addClass('btn-success');
        nextBtn.prop('disabled', false);
    }

    /**
     * The wizard modal's Next (on the last step: Generate) and Previous
     * buttons, as jQuery collections; empty when the wizard is closed.
     *
     * @returns {{next: JQuery|undefined, prev: JQuery|undefined}}
     */
    wizardButtons() {
        const modal = MultiStepWizard.getComponent()?.closest('.modal');
        return {
            next: modal?.find('button[name="next"]'),
            prev: modal?.find('button[name="prev"]'),
        };
    }

    /**
     * Show confirmation modal before saving.
     *
     * Generate pressed again while the confirmation is open, or while the
     * save runs, does nothing: one landing page per press of OK.
     *
     * @param {HTMLFormElement} form
     */
    confirmAndSave(form) {
        if (this.isBusy() || this._confirmOpen) {
            return;
        }
        const titleInput = form.querySelector('#placement_title');
        const parentInput = form.querySelector('#placement_parent');
        const title = titleInput?.value?.trim() || '';
        const parentPageId = parseInt(parentInput?.value || '0', 10);

        if (!title) {
            Notification.warning(
                this.label('wizard.notification.titleRequired'),
                this.label('wizard.notification.pageTitleRequired'),
            );
            titleInput?.focus();
            return;
        }

        if (parentPageId <= 0) {
            const picker = form.querySelector('[data-placement-parent]');
            this.showParentPageError(picker, this.label('wizard.notification.parentRequiredMessage'));
            picker?.querySelector('[data-picker-browse]')?.focus();
            return;
        }

        const confirmTitleKey = WizardState.regenerateMode ? 'wizard.confirm.regenerateTitle' : 'wizard.confirm.title';
        const confirmMsgKey = WizardState.regenerateMode ? 'wizard.confirm.regenerateMessage' : 'wizard.confirm.message';
        this._confirmOpen = true;
        const modal = Modal.confirm(
            this.label(confirmTitleKey),
            this.label(confirmMsgKey),
            Modal.sizes.small,
        );
        modal.addEventListener('typo3-modal-hidden', () => {
            this._confirmOpen = false;
        });
        // A double click on OK fires this twice before the modal is gone. The
        // second one must not hide the modal again: a second hideModal() on
        // the closing confirmation leaves the wizard unable to close itself
        // after the save.
        let answered = false;
        modal.addEventListener('confirm.button.ok', () => {
            if (answered) {
                return;
            }
            answered = true;
            this._confirmOpen = false;
            modal.hideModal();
            // saveLandingPage() reports its own errors; nothing awaits it.
            void this.saveLandingPage(form);
        });
        modal.addEventListener('confirm.button.cancel', () => {
            this._confirmOpen = false;
            modal.hideModal();
        });
    }

    /**
     * Save the landing page via AJAX.
     *
     * Creating the page and its content can take half a minute or more (an
     * installation may translate every new record while it is saved), so the
     * placement step shows that the work runs, and Generate and Previous are
     * locked until the reply. The request has no client timeout: giving up
     * would not stop the server, which would create the page anyway.
     *
     * @param {HTMLFormElement} form
     */
    async saveLandingPage(form) {
        if (this.isBusy()) {
            return;
        }

        const template = WizardState.getTemplate();
        if (!template || !template.uid) {
            Notification.error(
                this.label('wizard.error.templateMissing'),
                '',
            );
            return;
        }

        const titleInput = form.querySelector('#placement_title');
        const slugInput = form.querySelector('#placement_slug');
        const parentInput = form.querySelector('#placement_parent');

        const title = titleInput?.value?.trim() || '';
        const slug = slugInput?.value?.trim() || '';
        const parentPageId = parseInt(parentInput?.value || '0', 10);

        const owner = this.takeBusy(null);
        const run = this._run;
        const container = form.parentElement;
        this.showSaveProgress(container);

        let failure = null;
        try {
            const result = await this.fetchJson(this.getAjaxUrl('save'), {
                templateUid: template.uid,
                parentPageId: parentPageId,
                title: title,
                slug: slug,
                pageFields: WizardState.getPageFields(),
                contentSections: WizardState.getContentSections(),
                briefingAnswers: WizardState.getBriefingAnswers(),
                sourcePageUid: WizardState.sourcePageUid || 0,
            }, 0);

            // The page exists whether or not this wizard is still open, so
            // the notification and the page tree refresh stay. Closing the
            // wizard and leaving for the page module do not: after a close
            // and reopen they would hit the wizard the user is working in.
            const current = !this.isStaleRun(run);
            if (current) {
                MultiStepWizard.dismiss();
            }

            Notification.success(
                this.label('wizard.notification.created'),
                this.label('wizard.notification.createdMessage', title),
            );

            if (result.pageUid) {
                // Refresh page tree so the new page appears
                top.document.dispatchEvent(new CustomEvent('typo3:pagetree:refresh'));

                const pageLayoutUrl = TYPO3.settings.NrLandingpage?.moduleUrls?.pageLayout || '';
                if (current && pageLayoutUrl) {
                    top.TYPO3.Backend.ContentContainer.setUrl(pageLayoutUrl + '&id=' + result.pageUid);
                }
            }
        } catch (error) {
            failure = error;
        } finally {
            this.releaseBusy(owner);
        }

        if (failure === null) {
            return;
        }
        if (!this.isStaleRun(run) && container?.isConnected) {
            this.hideSaveProgress(container);
            this.showSaveFailure(container, failure);
        } else {
            // The wizard was closed (and maybe opened again) during the save:
            // no step to explain it in, but the editor must still learn it,
            // and, unless the server refused, that the page may exist anyway.
            const refused = typeof failure.status === 'number' && failure.status >= 400 && failure.status < 500;
            const kind = refused ? 'refused' : 'unknown';
            // Its content is gone with it: open() starts a new wizard empty.
            Notification.error(
                this.label('wizard.error.save', failure.message),
                this.label('wizard.saveFailed.closed.' + kind),
            );
        }
    }

    /**
     * Lock Generate and Previous and show, below the placement form, that the
     * page is being created.
     *
     * @param {HTMLElement|null} container  The placement step's element
     */
    showSaveProgress(container) {
        const { next, prev } = this.wizardButtons();
        next?.prop('disabled', true);
        prev?.prop('disabled', true);
        if (!container) {
            return;
        }
        container.querySelector('[data-save-failure]')?.remove();
        container.querySelector('[data-save-status]')?.remove();

        // The modal lives in the top document, not in this module's frame:
        // a custom element made in the frame's document cannot attach its
        // styles there.
        const doc = container.ownerDocument;
        const status = doc.createElement('div');
        status.className = 'callout callout-info mt-3';
        status.dataset.saveStatus = '';
        status.setAttribute('role', 'status');
        const content = doc.createElement('div');
        content.className = 'callout-content d-flex align-items-center gap-2';
        const spinner = doc.createElement('typo3-backend-spinner');
        spinner.setAttribute('size', 'small');
        spinner.setAttribute('aria-hidden', 'true');
        const text = doc.createElement('span');
        content.append(spinner, text);
        status.appendChild(content);
        const form = container.querySelector('form');
        if (form) {
            form.after(status);
        } else {
            container.appendChild(status);
        }
        // The text arrives a frame after the status region is in the
        // document: a live region is announced when its content changes,
        // not when it appears. textContent path (not innerHTML): XLF strings
        // are no markup.
        requestAnimationFrame(() => {
            text.textContent = this.label('wizard.save.running');
        });
    }

    /**
     * Remove the progress notice and unlock Generate and Previous again.
     *
     * @param {HTMLElement} container  The placement step's element
     */
    hideSaveProgress(container) {
        container.querySelector('[data-save-status]')?.remove();
        const { next, prev } = this.wizardButtons();
        next?.prop('disabled', false);
        prev?.prop('disabled', false);
    }

    /**
     * Explain a failed save the way the editor needs it: what happened, what
     * exists now, what to do next and whom to ask.
     *
     * A refusal (HTTP 4xx: input, permission, missing template) created
     * nothing. Any other failure — a server error, a lost connection — may
     * have come after the page was written, so the editor looks in the page
     * tree before pressing Generate again, or the page exists twice.
     *
     * @param {HTMLElement} container  The placement step's element
     * @param {Error & {status?: number}} error
     */
    showSaveFailure(container, error) {
        const refused = typeof error.status === 'number' && error.status >= 400 && error.status < 500;
        const kind = refused ? 'refused' : 'unknown';

        const doc = container.ownerDocument;
        const box = doc.createElement('div');
        box.className = 'callout callout-danger mt-3';
        box.dataset.saveFailure = kind;
        box.setAttribute('role', 'alert');
        box.tabIndex = -1;

        const content = doc.createElement('div');
        content.className = 'callout-content';
        const title = doc.createElement('div');
        title.className = 'callout-title';
        title.textContent = this.label('wizard.saveFailed.title');
        content.appendChild(title);

        const body = doc.createElement('div');
        body.className = 'callout-body';
        const parts = [
            ['happened', this.label('wizard.saveFailed.happened', error.message)],
            ['exists', this.label('wizard.saveFailed.exists.' + kind)],
            ['todo', this.label('wizard.saveFailed.todo.' + kind)],
            ['ask', this.label('wizard.saveFailed.ask')],
        ];
        for (const [part, text] of parts) {
            const heading = doc.createElement('h3');
            heading.className = 'h6 fw-bold mb-1';
            heading.textContent = this.label('wizard.saveFailed.heading.' + part);
            const paragraph = doc.createElement('p');
            paragraph.className = 'mb-2';
            paragraph.dataset.failurePart = part;
            paragraph.textContent = text;
            body.append(heading, paragraph);
        }
        content.appendChild(body);
        box.appendChild(content);

        const form = container.querySelector('form');
        if (form) {
            form.after(box);
        } else {
            container.appendChild(box);
        }
        box.focus();
    }

    // ── UI helpers ──────────────────────────────────────────────

    /**
     * Extract the raw DOM element from jQuery or Element.
     *
     * @param {*} $slide
     * @returns {HTMLElement}
     */
    getSlideElement($slide) {
        if ($slide instanceof HTMLElement) {
            return $slide;
        }
        if ($slide?.get) {
            return $slide.get(0);
        }
        return $slide;
    }

    /**
     * Escape HTML to prevent XSS.
     *
     * @param {string} text
     * @returns {string}
     */
    escapeHtml(text) {
        if (typeof text !== 'string') {
            return '';
        }
        const div = document.createElement('div');
        div.appendChild(document.createTextNode(text));
        return div.innerHTML;
    }

    /**
     * Create a spinner loading HTML string.
     *
     * @param {string} message
     * @returns {string}
     */
    spinnerHtml(message = '') {
        return '<div class="d-flex align-items-center justify-content-center py-4" role="status" aria-live="polite">'
            + '<typo3-backend-spinner size="small" class="me-2" aria-hidden="true"></typo3-backend-spinner>'
            + '<span>' + this.escapeHtml(message) + '</span></div>';
    }

    /**
     * Show the error of a step that failed to load, and a Retry button that
     * loads it again. The caller keeps Next locked: a step that failed cannot
     * be skipped, or the wizard would save a page from what is missing (#107).
     *
     * @param {HTMLElement} container
     * @param {string} message
     * @param {Function} retry  Renders the step again
     */
    showSlideError(container, message, retry) {
        container.replaceChildren();
        const alert = document.createElement('div');
        alert.className = 'alert alert-danger';
        alert.id = 'nr-landingpage-step-error-' + this._render;
        alert.setAttribute('role', 'alert');
        alert.textContent = message;
        container.appendChild(alert);

        // Next stays locked after a failure, so Retry is the way on. The
        // caller's focusStep() puts the focus on it, and it carries the
        // message as its description: the message is read out with the
        // focused button, also where a screen reader misses the inserted
        // alert.
        const button = this.createButton(this.label('wizard.button.retry'), 'btn btn-default', retry);
        button.dataset.stepRetry = '';
        button.setAttribute('aria-describedby', alert.id);
        container.appendChild(button);
    }

    /**
     * Create a button element.
     *
     * @param {string} label
     * @param {string} cssClass
     * @param {Function} onClick
     * @returns {HTMLButtonElement}
     */
    createButton(label, cssClass, onClick) {
        const button = document.createElement('button');
        button.type = 'button';
        button.className = cssClass;
        button.textContent = label;
        button.addEventListener('click', onClick);
        return button;
    }

    /**
     * Create a button with a TYPO3 icon and label text.
     *
     * @param {string} iconIdentifier TYPO3 icon identifier (e.g. 'actions-search')
     * @param {string} label Button text
     * @param {string} cssClass CSS classes for the button
     * @param {Function} onClick Click handler
     * @returns {HTMLButtonElement}
     */
    createIconButton(iconIdentifier, label, cssClass, onClick) {
        const button = document.createElement('button');
        button.type = 'button';
        button.className = cssClass;
        button.addEventListener('click', onClick);

        // Set text immediately, then replace with icon + text once loaded
        button.textContent = label;
        Icons.getIcon(iconIdentifier, Icons.sizes.small).then((iconMarkup) => {
            const span = document.createElement('span');
            span.className = 'me-1';
            // iconMarkup is from TYPO3 Icons API (trusted backend source), safe for innerHTML
            span.innerHTML = iconMarkup;
            button.textContent = '';
            button.appendChild(span);
            button.appendChild(document.createTextNode(label));
        });

        return button;
    }

    /**
     * Update label text of an icon button (preserves icon if present).
     *
     * @param {HTMLButtonElement} button
     * @param {string} label
     */
    setIconButtonLabel(button, label) {
        const iconSpan = button.querySelector('span.me-1');
        button.textContent = '';
        if (iconSpan) {
            button.appendChild(iconSpan);
        }
        button.appendChild(document.createTextNode(label));
    }

    /**
     * Create a form group with label and input.
     *
     * @param {string} id
     * @param {string} label
     * @param {string} type
     * @param {string} value
     * @param {boolean} required
     * @param {string} placeholder
     * @returns {HTMLDivElement}
     */
    createFormGroup(id, label, type, value = '', required = false, placeholder = '') {
        const group = document.createElement('div');
        group.className = 'mb-3';

        const labelEl = document.createElement('label');
        labelEl.className = 'form-label';
        labelEl.setAttribute('for', id);
        labelEl.textContent = label;
        if (required) {
            const asterisk = document.createElement('span');
            asterisk.className = 'text-danger ms-1';
            asterisk.textContent = '*';
            asterisk.setAttribute('aria-hidden', 'true');
            labelEl.appendChild(asterisk);
        }
        group.appendChild(labelEl);

        let input;
        if (type === 'textarea') {
            input = document.createElement('textarea');
            input.className = 'form-control';
            input.rows = 3;
            input.value = value;
        } else {
            input = document.createElement('input');
            input.type = type;
            input.className = 'form-control';
            input.value = value;
        }

        input.id = id;
        input.name = id;
        if (required) {
            input.required = true;
            input.setAttribute('aria-required', 'true');
        }
        if (placeholder) {
            input.placeholder = placeholder;
        }

        group.appendChild(input);
        return group;
    }

    /**
     * Create a select form group.
     *
     * @param {string} id
     * @param {string} label
     * @param {Array} options
     * @param {boolean} required
     * @returns {HTMLDivElement}
     */
    createSelectGroup(id, label, options, required = false) {
        const group = document.createElement('div');
        group.className = 'mb-3';

        const labelEl = document.createElement('label');
        labelEl.className = 'form-label';
        labelEl.setAttribute('for', id);
        labelEl.textContent = label;
        if (required) {
            const asterisk = document.createElement('span');
            asterisk.className = 'text-danger ms-1';
            asterisk.textContent = '*';
            asterisk.setAttribute('aria-hidden', 'true');
            labelEl.appendChild(asterisk);
        }
        group.appendChild(labelEl);

        const select = document.createElement('select');
        select.className = 'form-select';
        select.id = id;
        select.name = id;
        if (required) {
            select.required = true;
            select.setAttribute('aria-required', 'true');
        }

        const emptyOption = document.createElement('option');
        emptyOption.value = '';
        emptyOption.textContent = '-- ' + this.label('wizard.select.placeholder') + ' --';
        select.appendChild(emptyOption);

        options.forEach((opt) => {
            const option = document.createElement('option');
            if (typeof opt === 'object') {
                option.value = opt.value || opt.label || '';
                option.textContent = opt.label || opt.value || '';
            } else {
                option.value = String(opt);
                option.textContent = String(opt);
            }
            select.appendChild(option);
        });

        group.appendChild(select);
        return group;
    }

    /**
     * Add a character counter below an input.
     *
     * @param {HTMLDivElement} group
     * @param {string} inputId
     * @param {number} maxLength
     */
    addCharacterCounter(group, inputId, maxLength) {
        const input = group.querySelector('#' + inputId);
        if (!input) return;

        const counterId = 'char-counter-' + inputId;
        const counter = document.createElement('small');
        counter.className = 'form-text text-variant';
        counter.id = counterId;
        counter.setAttribute('aria-live', 'polite');
        counter.setAttribute('aria-atomic', 'true');
        input.setAttribute('aria-describedby', counterId);

        const updateCounter = () => {
            const length = input.value.length;
            counter.textContent = length + ' / ' + maxLength;
            counter.classList.toggle('text-danger', length > maxLength);
            counter.classList.toggle('text-variant', length <= maxLength);
        };

        updateCounter();
        input.addEventListener('input', updateCounter);
        group.appendChild(counter);
    }

    /**
     * Collect briefing answers from form.
     *
     * @param {HTMLFormElement} form
     * @param {Array} questions
     * @returns {Object}
     */
    collectBriefingAnswers(form, questions) {
        const answers = {};
        if (Array.isArray(questions)) {
            questions.forEach((question, index) => {
                const input = form.querySelector('#briefing_q_' + index);
                if (input) {
                    const key = question.id || question.label || 'question_' + index;
                    answers[key] = input.value.trim();
                }
            });
        }
        return answers;
    }

    /**
     * Collect page fields from form.
     *
     * @param {HTMLFormElement} form
     * @returns {Object}
     */
    collectPageFields(form) {
        const fields = {};
        const inputs = form.querySelectorAll('input, textarea, select');
        inputs.forEach((input) => {
            const name = input.id.replace(/^pf_/, '');
            if (name) {
                fields[name] = input.value.trim();
            }
        });
        return fields;
    }

    /**
     * Generate a URL slug from text.
     *
     * @param {string} text
     * @returns {string}
     */
    generateSlug(text) {
        if (!text) return '';
        const slug = text
            .toLowerCase()
            .replace(/[äÄ]/g, 'ae')
            .replace(/[öÖ]/g, 'oe')
            .replace(/[üÜ]/g, 'ue')
            .replace(/ß/g, 'ss')
            .replace(/[^a-z0-9]+/g, '-')
            .replace(/^-+|-+$/g, '')
            .replace(/-{2,}/g, '-');
        return slug ? '/' + slug : '';
    }

    /**
     * Convert a field name to a human-readable label.
     *
     * @param {string} fieldName
     * @returns {string}
     */
    humanizeFieldName(fieldName) {
        return fieldName
            .replace(/_/g, ' ')
            .replace(/\b\w/g, (c) => c.toUpperCase());
    }
}

// Initialize: bind buttons on the launcher page
const launchButton = document.getElementById('nr-landingpage-launch-wizard');
if (launchButton) {
    const wizard = new LandingPageWizard();
    const parentPageId = parseInt(launchButton.dataset.parentPageId || '0', 10);
    const regeneratePageUid = parseInt(launchButton.dataset.regeneratePageUid || '0', 10);
    wizard.initialParentPageTitle = launchButton.dataset.parentPageTitle || '';

    launchButton.addEventListener('click', () => {
        wizard.open(parentPageId, regeneratePageUid);
    });

    // "Create Landing Page" buttons on template cards — skip template selection step
    document.querySelectorAll('.nr-landingpage-create-from-template').forEach((btn) => {
        btn.addEventListener('click', () => {
            try {
                const template = JSON.parse(btn.dataset.template || '{}');
                wizard.open(parentPageId, 0, template);
            } catch {
                wizard.open(parentPageId);
            }
        });
    });

    // Auto-start wizard when triggered from context menu or re-generate button
    if (launchButton.dataset.autoStart === '1') {
        wizard.open(parentPageId, regeneratePageUid);
    }

    // The handlers above are bound: E2E tests wait for this before clicking.
    launchButton.dataset.wizardReady = '1';
}

export default LandingPageWizard;
