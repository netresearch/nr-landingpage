import WizardState from '@netresearch/nr-landingpage/wizard-state.js';
import MultiStepWizard from '@typo3/backend/multi-step-wizard.js';
import Modal from '@typo3/backend/modal.js';
import Notification from '@typo3/backend/notification.js';
import Severity from '@typo3/backend/severity.js';
import Icons from '@typo3/backend/icons.js';

/**
 * Landing Page Wizard using TYPO3 MultiStepWizard modal overlay.
 *
 * Triggered from the backend module launcher page.
 * Uses the same AJAX endpoints and state management as before,
 * but renders inside a native TYPO3 multi-step modal.
 */
class LandingPageWizard {
    constructor() {
        this._busy = false;
        this._briefingForm = null;
        this._briefingQuestions = null;
        this._pageFieldsForm = null;
    }

    /**
     * Get AJAX URL from TYPO3 inline settings.
     *
     * @param {string} key
     * @returns {string}
     */
    getAjaxUrl(key) {
        return TYPO3.settings.NrLandingpage?.ajaxUrls?.[key] || '';
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
     * @param {string} url
     * @param {Object|null} data
     * @returns {Promise<Object>}
     */
    async fetchJson(url, data = null) {
        const controller = new AbortController();
        const timeoutId = setTimeout(() => controller.abort(), 60000);

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
            clearTimeout(timeoutId);
        }

        let json;
        try {
            json = await response.json();
        } catch (error) {
            throw new Error('Invalid JSON response from server');
        }

        if (!json.success) {
            throw new Error(json.error || 'Unknown server error');
        }

        return json.data;
    }

    /**
     * Open the wizard modal.
     *
     * @param {number} parentPageId
     * @param {number} regeneratePageUid  Page UID to re-generate (0 = new page)
     */
    open(parentPageId = 0, regeneratePageUid = 0, preSelectTemplate = null) {
        WizardState.reset();
        this._briefingForm = null;
        this._briefingQuestions = null;
        this._pageFieldsForm = null;
        if (parentPageId > 0) {
            WizardState.setParentPageId(parentPageId);
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

            modal.addEventListener('keydown', (e) => {
                if (e.key !== 'Enter') return;

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

    // ── Slide renderers ──────────────────────────────────────────

    /**
     * Step 1: Template selection.
     *
     * In re-generate mode, fetches generation info first to pre-select the
     * template and store briefing answers. The template card is highlighted
     * but all cards remain clickable (user may switch).
     */
    async renderTemplateSlide($slide) {
        const container = this.getSlideElement($slide);
        container.innerHTML = this.spinnerHtml(this.label('wizard.loading.templates'));
        MultiStepWizard.lockNextStep();

        try {
            // In re-generate mode, load generation info in parallel with templates
            let generationInfo = null;
            const templatePromise = this.fetchJson(this.getAjaxUrl('templates'));

            if (WizardState.regenerateMode && WizardState.sourcePageUid > 0) {
                try {
                    generationInfo = await this.fetchJson(this.getAjaxUrl('generationInfo'), {
                        pageUid: WizardState.sourcePageUid,
                    });
                    // Store briefing answers and parent page for later steps
                    if (generationInfo.briefingAnswers) {
                        WizardState.setBriefingAnswers(generationInfo.briefingAnswers);
                    }
                    if (generationInfo.parentPageId > 0 && WizardState.getParentPageId() === 0) {
                        WizardState.setParentPageId(generationInfo.parentPageId);
                    }
                } catch (infoError) {
                    // Non-fatal — continue without pre-fill
                    generationInfo = null;
                }
            }

            const templates = await templatePromise;
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
            heading.className = 'text-body-secondary mb-3';
            heading.textContent = this.label('wizard.template.select');
            container.appendChild(heading);

            const grid = document.createElement('div');
            grid.className = 'row g-3';
            grid.setAttribute('role', 'list');

            const preSelectUid = generationInfo?.templateUid || 0;

            templates.forEach((template) => {
                const col = document.createElement('div');
                col.className = 'col-12 col-md-6';
                col.setAttribute('role', 'listitem');

                const card = document.createElement('div');
                card.className = 'card h-100 template-card';
                card.style.cursor = 'pointer';
                card.setAttribute('role', 'button');
                card.setAttribute('tabindex', '0');
                card.setAttribute('aria-label', template.title);

                const cardBody = document.createElement('div');
                cardBody.className = 'card-body';

                const title = document.createElement('h5');
                title.className = 'card-title';
                title.textContent = template.title;

                const description = document.createElement('p');
                description.className = 'card-text text-body-secondary';
                description.textContent = template.description || '';

                const badge = document.createElement('span');
                badge.className = 'badge bg-info';
                badge.textContent = this.label('wizard.template.briefingBadge', template.briefingMode || 'none');

                cardBody.appendChild(title);
                cardBody.appendChild(description);
                cardBody.appendChild(badge);
                card.appendChild(cardBody);
                col.appendChild(card);
                grid.appendChild(col);

                const selectHandler = () => {
                    grid.querySelectorAll('.card').forEach((c) => c.classList.remove('border-primary', 'shadow'));
                    card.classList.add('border-primary', 'shadow');
                    WizardState.setTemplate(template);
                    MultiStepWizard.unlockNextStep();
                };

                card.addEventListener('click', selectHandler);
                card.addEventListener('keydown', (e) => {
                    if (e.key === 'Enter' || e.key === ' ') {
                        e.preventDefault();
                        selectHandler();
                    }
                });

                // Auto-select in re-generate mode
                if (preSelectUid > 0 && template.uid === preSelectUid) {
                    selectHandler();
                }
            });

            container.appendChild(grid);

            // Warn if original template was deleted and could not be pre-selected
            if (preSelectUid > 0 && !WizardState.getTemplate()) {
                const warning = document.createElement('div');
                warning.className = 'alert alert-warning mt-3';
                warning.textContent = this.label('wizard.regenerate.templateDeleted');
                container.appendChild(warning);
            }
        } catch (error) {
            this.showSlideError(container, this.label('wizard.error.templates', error.message));
        }
    }

    /**
     * Step 2: Briefing questions.
     */
    async renderBriefingSlide($slide) {
        const container = this.getSlideElement($slide);
        const template = WizardState.getTemplate();

        if (!template || template.briefingMode === 'none') {
            const msg = document.createElement('p');
            msg.className = 'text-body-secondary';
            msg.textContent = this.label('wizard.briefing.skipped');
            container.appendChild(msg);
            MultiStepWizard.unlockNextStep();
            return;
        }

        container.innerHTML = this.spinnerHtml(this.label('wizard.loading.briefing'));
        MultiStepWizard.lockNextStep();

        try {
            const questions = await this.fetchJson(this.getAjaxUrl('generateBriefing'), {
                templateUid: template.uid,
            });

            container.innerHTML = '';

            const description = document.createElement('p');
            description.className = 'text-body-secondary mb-3';
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
        } catch (error) {
            this.showSlideError(container, this.label('wizard.error.briefing', error.message));
            if (template.briefingMode !== 'required') {
                MultiStepWizard.unlockNextStep();
            }
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
            WizardState.setTitle(titleVal);
            WizardState.setSlug(this.generateSlug(titleVal));
        }
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

            container.innerHTML = '';

            const description = document.createElement('p');
            description.className = 'text-body-secondary mb-3';
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
        } catch (error) {
            this.showSlideError(container, this.label('wizard.error.pageFields', error.message));
            MultiStepWizard.unlockNextStep();
        }
    }

    /**
     * Step 4: Content sections.
     */
    async renderContentSlide($slide) {
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

            const sections = result.sections || [];
            const images = result.images || [];
            const imageErrors = result.imageErrors || [];
            const hasImageTask = result.hasImageTask || false;
            const aiAvailable = result.aiGenerationAvailable || false;

            WizardState.setContentSections(sections);
            WizardState.setImages(images);
            WizardState.imageErrors = imageErrors;
            WizardState.hasImageTask = hasImageTask;
            WizardState.aiGenerationAvailable = aiAvailable;

            this.renderContentSections(container, sections, images);
            MultiStepWizard.unlockNextStep();
        } catch (error) {
            this.showSlideError(container, this.label('wizard.error.content', error.message));
            MultiStepWizard.unlockNextStep();
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
        container.innerHTML = '';

        const description = document.createElement('p');
        description.className = 'text-body-secondary mb-3';
        description.textContent = this.label('wizard.content.description');
        container.appendChild(description);

        if (!sections || sections.length === 0) {
            const alert = document.createElement('div');
            alert.className = 'alert alert-info';
            alert.setAttribute('role', 'alert');
            alert.textContent = this.label('wizard.content.none');
            container.appendChild(alert);
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
            ctypeBadge.className = 'badge bg-secondary ms-2';
            ctypeBadge.textContent = section.ctype || 'text';
            sectionTitle.appendChild(ctypeBadge);

            const regenerateBtn = this.createButton(this.label('wizard.button.regenerate'), 'btn btn-sm btn-outline-primary', async () => {
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
                header.className = 'form-control form-control-lg mb-2';
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
                subheader.className = 'form-control form-control-sm text-body-secondary mb-2';
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
            {
                const imageSection = document.createElement('div');
                imageSection.className = 'mt-3 border-top pt-3';

                const imageLabel = document.createElement('small');
                imageLabel.className = 'text-body-secondary d-block mb-2';
                imageLabel.textContent = this.label('wizard.content.imageSuggestions');
                imageSection.appendChild(imageLabel);

                // Show image generation error if present
                const imageError = (WizardState.imageErrors || [])[index];
                if (imageError) {
                    const errorAlert = document.createElement('div');
                    errorAlert.className = 'alert alert-warning alert-sm py-1 px-2 mb-2';
                    errorAlert.style.fontSize = '0.85em';
                    errorAlert.textContent = this.label('wizard.content.imageGenerationError') + ' ' + imageError;
                    imageSection.appendChild(errorAlert);
                }

                const imageList = document.createElement('div');
                imageList.className = 'd-flex gap-2 flex-wrap mb-2';

                const sectionImages = (images[index] && images[index].length > 0) ? images[index] : [];
                this.renderImageCards(imageList, sectionImages, index);

                // Show info when automatic search found no images
                const keywords = section.imageKeywords || [];
                if (sectionImages.length === 0 && keywords.length > 0) {
                    const emptyInfo = document.createElement('div');
                    emptyInfo.className = 'alert alert-info py-2 px-3 mb-2';
                    emptyInfo.style.fontSize = '0.85em';
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
                searchInput.style.maxWidth = '250px';
                if (keywords.length > 0) {
                    searchInput.value = keywords.join(' ');
                }

                const searchBtn = this.createIconButton(
                    'actions-search',
                    this.label('wizard.content.imageSearchButton'),
                    'btn btn-sm btn-outline-secondary',
                    async () => {
                        const query = searchInput.value.trim();
                        if (!query) return;
                        searchBtn.disabled = true;
                        try {
                            const result = await this.fetchJson(this.getAjaxUrl('searchImages'), { query });
                            const found = result.images || [];
                            if (found.length === 0) {
                                Notification.info(this.label('wizard.content.imageSearchEmpty'));
                            } else {
                                this.renderImageCards(imageList, found, index);
                            }
                        } catch (err) {
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

                // AI Generate button (shown when AI source is configured and available)
                const aiAvailable = WizardState.aiGenerationAvailable || false;
                const hasImageTask = WizardState.hasImageTask || false;
                if (aiAvailable && hasImageTask) {
                    const generateBtn = this.createIconButton(
                        'actions-bolt',
                        this.label('wizard.content.imageGenerateButton'),
                        'btn btn-sm btn-outline-warning',
                        async () => {
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
                                const img = result.image;
                                if (img) {
                                    this.renderImageCards(imageList, [img], index);
                                    Notification.success(this.label('wizard.content.imageGenerated'));
                                }
                            } catch (err) {
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

                cardBody.appendChild(imageSection);
            }

            card.appendChild(cardHeader);
            card.appendChild(cardBody);
            container.appendChild(card);
        });
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

            // Auto-select recommended image when no image is selected yet
            const isRecommended = img.recommended === true;
            const shouldAutoSelect = isRecommended && currentImageUid === 0 && sections[sectionIndex].imageUid === 0;
            if (shouldAutoSelect) {
                sections[sectionIndex].imageUid = img.uid;
            }

            if (sections[sectionIndex].imageUid === img.uid) {
                imgCard.classList.add('border-primary', 'shadow-sm');
            }

            // Thumbnail or placeholder
            if (img.publicUrl) {
                const thumbnail = document.createElement('img');
                thumbnail.src = img.publicUrl;
                thumbnail.alt = img.alternative || img.title || img.name || '';
                thumbnail.className = 'card-img-top';
                thumbnail.style.cssText = 'height:80px;object-fit:cover;';
                imgCard.appendChild(thumbnail);
            } else {
                const placeholder = document.createElement('div');
                placeholder.className = 'bg-secondary-subtle d-flex align-items-center justify-content-center';
                placeholder.style.cssText = 'height:80px;';
                placeholder.textContent = '\uD83D\uDDBC';
                imgCard.appendChild(placeholder);
            }

            const imgBody = document.createElement('div');
            imgBody.className = 'card-body p-1';

            // Show "recommended" / "AI" badge
            if (isRecommended || img.generated) {
                const badge = document.createElement('span');
                badge.className = img.generated
                    ? 'badge bg-warning text-dark mb-1'
                    : 'badge bg-success text-white mb-1';
                badge.style.fontSize = '0.65rem';
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
                const isSelected = secs[sectionIndex].imageUid === img.uid;

                secs[sectionIndex].imageUid = isSelected ? 0 : img.uid;

                // Update visual state for all cards in this section's image list
                imageList.querySelectorAll('.card').forEach((c) => {
                    c.classList.remove('border-primary', 'shadow-sm');
                });
                if (!isSelected) {
                    imgCard.classList.add('border-primary', 'shadow-sm');
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
     * Regenerate a single content section via AJAX.
     *
     * @param {HTMLElement} container
     * @param {number} index
     */
    async regenerateSection(container, index) {
        if (this._busy) {
            return;
        }
        this._busy = true;

        const card = document.getElementById('section-card-' + index);
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

            WizardState.updateContentSection(index, newSection);
            Notification.success(
                this.label('wizard.notification.sectionRegenerated'),
                this.label('wizard.notification.sectionRegeneratedMessage', index + 1),
            );

            this.renderContentSections(container, WizardState.getContentSections(), WizardState.getImages());
        } catch (error) {
            Notification.error(this.label('wizard.notification.regenerationFailed'), error.message);
            this.renderContentSections(container, WizardState.getContentSections(), WizardState.getImages());
        } finally {
            this._busy = false;
        }
    }

    /**
     * Step 5: Placement & Save.
     */
    async renderPlacementSlide($slide) {
        const container = this.getSlideElement($slide);
        container.innerHTML = '';

        const description = document.createElement('p');
        description.className = 'text-body-secondary mb-3';
        description.textContent = this.label('wizard.placement.description');
        container.appendChild(description);

        const form = document.createElement('form');
        form.addEventListener('submit', (e) => e.preventDefault());

        form.appendChild(this.createFormGroup('placement_title', this.label('wizard.pageFields.pageTitle'), 'text', WizardState.getTitle(), true));
        form.appendChild(this.createFormGroup('placement_slug', this.label('wizard.pageFields.urlSlug'), 'text', WizardState.getSlug(), false));

        const parentValue = WizardState.getParentPageId() > 0 ? String(WizardState.getParentPageId()) : '';
        form.appendChild(this.createFormGroup(
            'placement_parent',
            this.label('wizard.placement.parentPageId'),
            'number',
            parentValue,
            true,
            this.label('wizard.placement.parentPageIdPlaceholder'),
        ));

        // Set min="1" on parent page input
        const parentInput = form.querySelector('#placement_parent');
        if (parentInput) {
            parentInput.min = '1';
        }

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
     * Show confirmation modal before saving.
     *
     * @param {HTMLFormElement} form
     */
    confirmAndSave(form) {
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
            Notification.warning(
                this.label('wizard.notification.parentRequired'),
                this.label('wizard.notification.parentRequiredMessage'),
            );
            parentInput?.focus();
            return;
        }

        const confirmTitleKey = WizardState.regenerateMode ? 'wizard.confirm.regenerateTitle' : 'wizard.confirm.title';
        const confirmMsgKey = WizardState.regenerateMode ? 'wizard.confirm.regenerateMessage' : 'wizard.confirm.message';
        const modal = Modal.confirm(
            this.label(confirmTitleKey),
            this.label(confirmMsgKey),
            Modal.sizes.small,
        );
        modal.addEventListener('confirm.button.ok', () => {
            modal.hideModal();
            this.saveLandingPage(form);
        });
        modal.addEventListener('confirm.button.cancel', () => {
            modal.hideModal();
        });
    }

    /**
     * Save the landing page via AJAX.
     *
     * @param {HTMLFormElement} form
     */
    async saveLandingPage(form) {
        if (this._busy) {
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

        this._busy = true;

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
            });

            MultiStepWizard.dismiss();

            Notification.success(
                this.label('wizard.notification.created'),
                this.label('wizard.notification.createdMessage', title),
            );

            if (result.pageUid) {
                // Refresh page tree so the new page appears
                top.document.dispatchEvent(new CustomEvent('typo3:pagetree:refresh'));

                const pageLayoutUrl = TYPO3.settings.NrLandingpage?.moduleUrls?.pageLayout || '';
                if (pageLayoutUrl) {
                    top.TYPO3.Backend.ContentContainer.setUrl(pageLayoutUrl + '&id=' + result.pageUid);
                }
            }
        } catch (error) {
            Notification.error(
                this.label('wizard.error.save', error.message),
                '',
            );
        } finally {
            this._busy = false;
        }
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
            + '<div class="spinner-border spinner-border-sm me-2" aria-hidden="true"></div>'
            + '<span>' + this.escapeHtml(message) + '</span></div>';
    }

    /**
     * Show an error message inside a slide container.
     *
     * @param {HTMLElement} container
     * @param {string} message
     */
    showSlideError(container, message) {
        container.innerHTML = '';
        const alert = document.createElement('div');
        alert.className = 'alert alert-danger';
        alert.setAttribute('role', 'alert');
        alert.textContent = message;
        container.appendChild(alert);
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
        counter.className = 'form-text text-body-secondary';
        counter.id = counterId;
        counter.setAttribute('aria-live', 'polite');
        counter.setAttribute('aria-atomic', 'true');
        input.setAttribute('aria-describedby', counterId);

        const updateCounter = () => {
            const length = input.value.length;
            counter.textContent = length + ' / ' + maxLength;
            counter.classList.toggle('text-danger', length > maxLength);
            counter.classList.toggle('text-body-secondary', length <= maxLength);
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
}

export default LandingPageWizard;
