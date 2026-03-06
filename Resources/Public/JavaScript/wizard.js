import WizardState from '@netresearch/nr-landingpage/wizard-state.js';
import Notification from '@typo3/backend/notification.js';

/**
 * Main Landing Page Wizard orchestrator.
 *
 * Handles step navigation, AJAX calls to the backend controller,
 * and dynamic UI rendering for all five wizard steps.
 */
class LandingPageWizard {
    constructor() {
        this.steps = ['template', 'briefing', 'pageFields', 'content', 'placement'];
        this.container = null;
        this.contentArea = null;
        this.navigationArea = null;
        this.progressBar = null;
        this.stepLabels = null;
    }

    /**
     * @param {HTMLElement} container
     */
    initialize(container) {
        this.container = container;
        this.contentArea = container.querySelector('.wizard-content');
        this.navigationArea = container.querySelector('.wizard-navigation');
        this.progressBar = container.querySelector('.progress-bar');
        this.stepLabels = container.querySelectorAll('.wizard-step-labels span');

        const parentPageId = parseInt(container.dataset.parentPageId || '0', 10);
        if (parentPageId > 0) {
            WizardState.setParentPageId(parentPageId);
        }

        WizardState.reset();
        if (parentPageId > 0) {
            WizardState.setParentPageId(parentPageId);
        }

        this.renderStep(0);
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
     * Perform a fetch request and return parsed JSON.
     *
     * @param {string} url
     * @param {Object|null} data - POST body (null for GET)
     * @returns {Promise<Object>}
     */
    async fetchJson(url, data = null) {
        const options = {
            method: data ? 'POST' : 'GET',
            headers: {},
        };

        if (data) {
            options.headers['Content-Type'] = 'application/json';
            options.body = JSON.stringify(data);
        }

        let response;
        try {
            response = await fetch(url, options);
        } catch (error) {
            throw new Error('Network error: ' + error.message);
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

    // ── Step rendering ──────────────────────────────────────────

    /**
     * Render a wizard step by index.
     *
     * @param {number} index
     */
    async renderStep(index) {
        WizardState.setCurrentStep(index);
        this.updateProgress(index);
        this.contentArea.innerHTML = '';
        this.navigationArea.innerHTML = '';

        switch (this.steps[index]) {
            case 'template':
                await this.renderTemplateStep();
                break;
            case 'briefing':
                await this.renderBriefingStep();
                break;
            case 'pageFields':
                await this.renderPageFieldsStep();
                break;
            case 'content':
                await this.renderContentStep();
                break;
            case 'placement':
                this.renderPlacementStep();
                break;
        }
    }

    /**
     * Step 1: Template selection.
     */
    async renderTemplateStep() {
        this.showLoading('Loading templates...');

        try {
            const templates = await this.fetchJson(this.getAjaxUrl('templates'));

            this.contentArea.innerHTML = '';

            if (!templates || templates.length === 0) {
                this.contentArea.innerHTML =
                    '<div class="alert alert-warning" role="alert">No templates available. Please configure at least one template.</div>';
                return;
            }

            const heading = document.createElement('h2');
            heading.textContent = 'Select a Template';
            this.contentArea.appendChild(heading);

            const grid = document.createElement('div');
            grid.className = 'row g-3';
            grid.setAttribute('role', 'list');
            grid.setAttribute('aria-label', 'Template list');

            templates.forEach((template) => {
                const col = document.createElement('div');
                col.className = 'col-12 col-md-6 col-lg-4';
                col.setAttribute('role', 'listitem');

                const card = document.createElement('div');
                card.className = 'card h-100 template-card';
                card.style.cursor = 'pointer';
                card.setAttribute('role', 'button');
                card.setAttribute('tabindex', '0');
                card.setAttribute('aria-label', 'Template: ' + this.escapeHtml(template.title));

                const cardBody = document.createElement('div');
                cardBody.className = 'card-body';

                const title = document.createElement('h5');
                title.className = 'card-title';
                title.textContent = template.title;

                const description = document.createElement('p');
                description.className = 'card-text text-muted';
                description.textContent = template.description || '';

                const badge = document.createElement('span');
                badge.className = 'badge bg-info';
                badge.textContent = 'Briefing: ' + this.escapeHtml(template.briefingMode || 'none');

                cardBody.appendChild(title);
                cardBody.appendChild(description);
                cardBody.appendChild(badge);
                card.appendChild(cardBody);
                col.appendChild(card);
                grid.appendChild(col);

                const selectHandler = () => {
                    WizardState.setTemplate(template);
                    if (template.briefingMode === 'none') {
                        // Skip briefing step
                        this.renderStep(2);
                    } else {
                        this.goNext();
                    }
                };

                card.addEventListener('click', selectHandler);
                card.addEventListener('keydown', (e) => {
                    if (e.key === 'Enter' || e.key === ' ') {
                        e.preventDefault();
                        selectHandler();
                    }
                });
            });

            this.contentArea.appendChild(grid);
        } catch (error) {
            this.showError('Failed to load templates: ' + error.message, () => this.renderTemplateStep());
        }
    }

    /**
     * Step 2: Briefing questions.
     */
    async renderBriefingStep() {
        this.showLoading('Generating briefing questions...');

        try {
            const template = WizardState.getTemplate();
            const questions = await this.fetchJson(this.getAjaxUrl('generateBriefing'), {
                templateUid: template.uid,
            });

            this.contentArea.innerHTML = '';

            const heading = document.createElement('h2');
            heading.textContent = 'Briefing';
            this.contentArea.appendChild(heading);

            const description = document.createElement('p');
            description.className = 'text-muted';
            description.textContent = 'Answer the following questions to help generate your landing page content.';
            this.contentArea.appendChild(description);

            const form = document.createElement('form');
            form.setAttribute('aria-label', 'Briefing form');
            form.addEventListener('submit', (e) => e.preventDefault());

            // Always add a title/topic field
            const titleGroup = this.createFormGroup(
                'briefing_title',
                'Title / Topic',
                'text',
                '',
                true,
                'What is the main topic of this landing page?'
            );
            form.appendChild(titleGroup);

            // Render dynamic questions from LLM
            if (Array.isArray(questions)) {
                questions.forEach((question, index) => {
                    const fieldId = 'briefing_q_' + index;
                    const type = question.type || 'text';
                    const required = question.required === true;
                    const placeholder = question.placeholder || '';
                    const label = question.label || question.question || 'Question ' + (index + 1);

                    if (type === 'select' && Array.isArray(question.options)) {
                        const group = this.createSelectGroup(fieldId, label, question.options, required);
                        form.appendChild(group);
                    } else if (type === 'textarea') {
                        const group = this.createFormGroup(fieldId, label, 'textarea', '', required, placeholder);
                        form.appendChild(group);
                    } else {
                        const group = this.createFormGroup(fieldId, label, 'text', '', required, placeholder);
                        form.appendChild(group);
                    }
                });
            }

            this.contentArea.appendChild(form);

            const isOptional = template.briefingMode === 'optional';

            this.renderNavigation(
                true,
                {
                    label: 'Continue',
                    handler: () => {
                        const answers = this.collectBriefingAnswers(form, questions);
                        const titleInput = form.querySelector('#briefing_title');
                        if (!titleInput || !titleInput.value.trim()) {
                            Notification.warning('Title required', 'Please enter a title or topic.');
                            titleInput?.focus();
                            return;
                        }
                        answers.title = titleInput.value.trim();
                        WizardState.setBriefingAnswers(answers);
                        WizardState.setTitle(answers.title);
                        WizardState.setSlug(this.generateSlug(answers.title));
                        this.goNext();
                    },
                },
                isOptional
                    ? {
                        label: 'Skip',
                        handler: () => {
                            WizardState.setBriefingAnswers({});
                            this.goNext();
                        },
                    }
                    : null
            );
        } catch (error) {
            this.showError('Failed to generate briefing: ' + error.message, () => this.renderBriefingStep());
            this.renderNavigation(
                true,
                null,
                {
                    label: 'Skip',
                    handler: () => {
                        WizardState.setBriefingAnswers({});
                        this.goNext();
                    },
                }
            );
        }
    }

    /**
     * Step 3: Page fields (SEO, metadata).
     */
    async renderPageFieldsStep() {
        this.showLoading('Generating page fields...');

        try {
            const template = WizardState.getTemplate();
            const fields = await this.fetchJson(this.getAjaxUrl('generatePageFields'), {
                templateUid: template.uid,
                briefingAnswers: WizardState.getBriefingAnswers(),
            });

            this.contentArea.innerHTML = '';

            const heading = document.createElement('h2');
            heading.textContent = 'Page Fields';
            this.contentArea.appendChild(heading);

            const description = document.createElement('p');
            description.className = 'text-muted';
            description.textContent = 'Review and edit the generated page fields. These will be used as page properties.';
            this.contentArea.appendChild(description);

            const form = document.createElement('form');
            form.setAttribute('aria-label', 'Page fields form');
            form.addEventListener('submit', (e) => e.preventDefault());

            // Title field
            const titleValue = fields.title || WizardState.getTitle() || '';
            form.appendChild(this.createFormGroup('pf_title', 'Page Title', 'text', titleValue, true));

            // Slug
            const slugValue = fields.slug || WizardState.getSlug() || this.generateSlug(titleValue);
            form.appendChild(this.createFormGroup('pf_slug', 'URL Slug', 'text', slugValue, false));

            // SEO Title with character counter
            const seoTitleValue = fields.seo_title || '';
            const seoGroup = this.createFormGroup('pf_seo_title', 'SEO Title', 'text', seoTitleValue, false);
            this.addCharacterCounter(seoGroup, 'pf_seo_title', 60);
            form.appendChild(seoGroup);

            // Meta description with character counter
            const descValue = fields.description || '';
            const descGroup = this.createFormGroup('pf_description', 'Meta Description', 'textarea', descValue, false);
            this.addCharacterCounter(descGroup, 'pf_description', 160);
            form.appendChild(descGroup);

            // OG Title
            if (fields.og_title !== undefined) {
                form.appendChild(this.createFormGroup('pf_og_title', 'OG Title', 'text', fields.og_title || '', false));
            }

            // OG Description
            if (fields.og_description !== undefined) {
                form.appendChild(
                    this.createFormGroup('pf_og_description', 'OG Description', 'textarea', fields.og_description || '', false)
                );
            }

            // Any additional fields from the response
            const knownFields = ['title', 'slug', 'seo_title', 'description', 'og_title', 'og_description'];
            Object.keys(fields).forEach((key) => {
                if (!knownFields.includes(key) && typeof fields[key] === 'string') {
                    form.appendChild(
                        this.createFormGroup('pf_' + key, this.humanizeFieldName(key), 'text', fields[key], false)
                    );
                }
            });

            this.contentArea.appendChild(form);

            // Auto-generate slug from title
            const titleInput = form.querySelector('#pf_title');
            const slugInput = form.querySelector('#pf_slug');
            if (titleInput && slugInput) {
                titleInput.addEventListener('input', () => {
                    slugInput.value = this.generateSlug(titleInput.value);
                });
            }

            WizardState.setPageFields(fields);

            this.renderNavigation(
                true,
                {
                    label: 'Continue',
                    handler: () => {
                        const pageFields = this.collectPageFields(form);
                        WizardState.setPageFields(pageFields);
                        WizardState.setTitle(pageFields.title || '');
                        WizardState.setSlug(pageFields.slug || '');
                        this.goNext();
                    },
                },
                {
                    label: 'Skip',
                    handler: () => this.goNext(),
                }
            );
        } catch (error) {
            this.showError('Failed to generate page fields: ' + error.message, () => this.renderPageFieldsStep());
            this.renderNavigation(
                true,
                null,
                {
                    label: 'Skip',
                    handler: () => this.goNext(),
                }
            );
        }
    }

    /**
     * Step 4: Content sections.
     */
    async renderContentStep() {
        this.showLoading('Generating content sections...');

        try {
            const template = WizardState.getTemplate();
            const result = await this.fetchJson(this.getAjaxUrl('generateContent'), {
                templateUid: template.uid,
                briefingAnswers: WizardState.getBriefingAnswers(),
            });

            const sections = result.sections || [];
            const images = result.images || [];

            WizardState.setContentSections(sections);
            WizardState.setImages(images);

            this.renderContentSections(sections, images);
        } catch (error) {
            this.showError('Failed to generate content: ' + error.message, () => this.renderContentStep());
            this.renderNavigation(
                true,
                null,
                {
                    label: 'Skip',
                    handler: () => this.goNext(),
                }
            );
        }
    }

    /**
     * Render content sections UI (used both for initial load and after regeneration).
     *
     * @param {Array} sections
     * @param {Array} images
     */
    renderContentSections(sections, images) {
        this.contentArea.innerHTML = '';

        const heading = document.createElement('h2');
        heading.textContent = 'Content Sections';
        this.contentArea.appendChild(heading);

        const description = document.createElement('p');
        description.className = 'text-muted';
        description.textContent = 'Review the generated content sections. You can regenerate individual sections.';
        this.contentArea.appendChild(description);

        if (!sections || sections.length === 0) {
            this.contentArea.innerHTML +=
                '<div class="alert alert-info" role="alert">No content sections were generated.</div>';
            this.renderNavigation(true, { label: 'Continue', handler: () => this.goNext() }, null);
            return;
        }

        const container = document.createElement('div');
        container.className = 'content-sections';
        container.setAttribute('aria-label', 'Content sections');

        sections.forEach((section, index) => {
            const card = document.createElement('div');
            card.className = 'card mb-3';
            card.id = 'section-card-' + index;

            const cardHeader = document.createElement('div');
            cardHeader.className = 'card-header d-flex justify-content-between align-items-center';

            const sectionTitle = document.createElement('span');
            sectionTitle.innerHTML =
                '<strong>' +
                this.escapeHtml(section.section || 'Section ' + (index + 1)) +
                '</strong> ' +
                '<span class="badge bg-secondary ms-2">' +
                this.escapeHtml(section.ctype || 'text') +
                '</span>';

            const regenerateBtn = this.createButton('Regenerate', 'btn btn-sm btn-outline-primary', async () => {
                await this.regenerateSection(index);
            });
            regenerateBtn.setAttribute('aria-label', 'Regenerate section ' + (index + 1));

            cardHeader.appendChild(sectionTitle);
            cardHeader.appendChild(regenerateBtn);

            const cardBody = document.createElement('div');
            cardBody.className = 'card-body';

            if (section.header) {
                const header = document.createElement('h5');
                header.textContent = section.header;
                cardBody.appendChild(header);
            }

            if (section.subheader) {
                const subheader = document.createElement('h6');
                subheader.className = 'text-muted';
                subheader.textContent = section.subheader;
                cardBody.appendChild(subheader);
            }

            if (section.bodytext) {
                const bodytext = document.createElement('div');
                bodytext.className = 'section-bodytext';
                bodytext.textContent = section.bodytext;
                cardBody.appendChild(bodytext);
            }

            // Image suggestions
            if (images[index] && images[index].length > 0) {
                const imageSection = document.createElement('div');
                imageSection.className = 'mt-3';

                const imageLabel = document.createElement('small');
                imageLabel.className = 'text-muted d-block mb-2';
                imageLabel.textContent = 'Image suggestions:';
                imageSection.appendChild(imageLabel);

                const imageList = document.createElement('div');
                imageList.className = 'd-flex gap-2 flex-wrap';

                images[index].forEach((img) => {
                    const imgBadge = document.createElement('span');
                    imgBadge.className = 'badge bg-light text-dark border';
                    imgBadge.textContent = img.title || img.name || 'Image';
                    imgBadge.setAttribute('title', this.escapeHtml(img.description || ''));
                    imageList.appendChild(imgBadge);
                });

                imageSection.appendChild(imageList);
                cardBody.appendChild(imageSection);
            }

            card.appendChild(cardHeader);
            card.appendChild(cardBody);
            container.appendChild(card);
        });

        this.contentArea.appendChild(container);

        this.renderNavigation(
            true,
            {
                label: 'Continue',
                handler: () => this.goNext(),
            },
            {
                label: 'Skip',
                handler: () => {
                    WizardState.setContentSections([]);
                    this.goNext();
                },
            }
        );
    }

    /**
     * Regenerate a single content section via AJAX.
     *
     * @param {number} index
     */
    async regenerateSection(index) {
        const card = document.getElementById('section-card-' + index);
        if (card) {
            const cardBody = card.querySelector('.card-body');
            if (cardBody) {
                cardBody.innerHTML =
                    '<div class="d-flex align-items-center" role="status" aria-live="polite">' +
                    '<div class="spinner-border spinner-border-sm me-2" aria-hidden="true"></div>' +
                    '<span>Regenerating section...</span></div>';
            }
        }

        try {
            const template = WizardState.getTemplate();
            const newSection = await this.fetchJson(this.getAjaxUrl('regenerateSection'), {
                templateUid: template.uid,
                briefingAnswers: WizardState.getBriefingAnswers(),
                sectionIndex: index,
            });

            WizardState.updateContentSection(index, newSection);
            Notification.success('Section regenerated', 'Section ' + (index + 1) + ' has been regenerated.');

            // Re-render all sections
            this.renderContentSections(WizardState.getContentSections(), WizardState.getImages());
        } catch (error) {
            Notification.error('Regeneration failed', error.message);

            // Restore original section display
            this.renderContentSections(WizardState.getContentSections(), WizardState.getImages());
        }
    }

    /**
     * Step 5: Placement and save.
     */
    renderPlacementStep() {
        this.contentArea.innerHTML = '';

        const heading = document.createElement('h2');
        heading.textContent = 'Placement & Save';
        this.contentArea.appendChild(heading);

        const description = document.createElement('p');
        description.className = 'text-muted';
        description.textContent = 'Review your settings and create the landing page.';
        this.contentArea.appendChild(description);

        const form = document.createElement('form');
        form.setAttribute('aria-label', 'Placement form');
        form.addEventListener('submit', (e) => e.preventDefault());

        // Title
        form.appendChild(
            this.createFormGroup('placement_title', 'Page Title', 'text', WizardState.getTitle(), true)
        );

        // Slug
        form.appendChild(
            this.createFormGroup('placement_slug', 'URL Slug', 'text', WizardState.getSlug(), false)
        );

        // Parent Page ID
        const parentValue = WizardState.getParentPageId() > 0 ? String(WizardState.getParentPageId()) : '';
        form.appendChild(
            this.createFormGroup(
                'placement_parent',
                'Parent Page ID',
                'number',
                parentValue,
                true,
                'Enter the UID of the parent page'
            )
        );

        // Auto-generate slug from title
        const titleInput = form.querySelector('#placement_title');
        const slugInput = form.querySelector('#placement_slug');
        if (titleInput && slugInput) {
            titleInput.addEventListener('input', () => {
                slugInput.value = this.generateSlug(titleInput.value);
            });
        }

        this.contentArea.appendChild(form);

        // Summary
        this.renderSummary();

        this.renderNavigation(
            true,
            {
                label: 'Generate Landing Page',
                handler: () => this.saveLandingPage(form),
                cssClass: 'btn btn-success',
            },
            null
        );
    }

    /**
     * Render a summary of selections.
     */
    renderSummary() {
        const template = WizardState.getTemplate();
        const sections = WizardState.getContentSections();
        const pageFields = WizardState.getPageFields();

        const summary = document.createElement('div');
        summary.className = 'card mt-4';

        const cardHeader = document.createElement('div');
        cardHeader.className = 'card-header';
        cardHeader.innerHTML = '<strong>Summary</strong>';

        const cardBody = document.createElement('div');
        cardBody.className = 'card-body';

        const list = document.createElement('dl');
        list.className = 'row mb-0';

        // Template
        if (template) {
            list.innerHTML +=
                '<dt class="col-sm-3">Template</dt>' +
                '<dd class="col-sm-9">' +
                this.escapeHtml(template.title) +
                '</dd>';
        }

        // Page fields count
        const fieldCount = Object.keys(pageFields).length;
        if (fieldCount > 0) {
            list.innerHTML +=
                '<dt class="col-sm-3">Page Fields</dt>' +
                '<dd class="col-sm-9">' +
                fieldCount +
                ' field(s) configured</dd>';
        }

        // Sections count
        if (sections.length > 0) {
            list.innerHTML +=
                '<dt class="col-sm-3">Content Sections</dt>' +
                '<dd class="col-sm-9">' +
                sections.length +
                ' section(s)</dd>';

            const sectionNames = sections
                .map((s) => this.escapeHtml(s.section || s.header || 'Untitled'))
                .join(', ');
            list.innerHTML +=
                '<dt class="col-sm-3">Sections</dt>' +
                '<dd class="col-sm-9">' +
                sectionNames +
                '</dd>';
        }

        cardBody.appendChild(list);
        summary.appendChild(cardHeader);
        summary.appendChild(cardBody);
        this.contentArea.appendChild(summary);
    }

    /**
     * Save the landing page via AJAX.
     *
     * @param {HTMLFormElement} form
     */
    async saveLandingPage(form) {
        const titleInput = form.querySelector('#placement_title');
        const slugInput = form.querySelector('#placement_slug');
        const parentInput = form.querySelector('#placement_parent');

        const title = titleInput?.value?.trim() || '';
        const slug = slugInput?.value?.trim() || '';
        const parentPageId = parseInt(parentInput?.value || '0', 10);

        if (!title) {
            Notification.warning('Title required', 'Please enter a page title.');
            titleInput?.focus();
            return;
        }

        if (parentPageId <= 0) {
            Notification.warning('Parent page required', 'Please enter a valid parent page ID.');
            parentInput?.focus();
            return;
        }

        this.showLoading('Creating landing page...');

        try {
            const template = WizardState.getTemplate();
            const result = await this.fetchJson(this.getAjaxUrl('save'), {
                templateUid: template.uid,
                parentPageId: parentPageId,
                title: title,
                slug: slug,
                pageFields: WizardState.getPageFields(),
                contentSections: WizardState.getContentSections(),
            });

            this.renderSuccessScreen(result, title);
            Notification.success('Landing page created', 'The landing page "' + title + '" has been created.');
        } catch (error) {
            this.showError('Failed to create landing page: ' + error.message, () => this.saveLandingPage(form));
            this.renderNavigation(
                true,
                {
                    label: 'Retry',
                    handler: () => this.renderPlacementStep(),
                },
                null
            );
        }
    }

    /**
     * Render success screen after page creation.
     *
     * @param {Object} result
     * @param {string} title
     */
    renderSuccessScreen(result, title) {
        this.contentArea.innerHTML = '';
        this.navigationArea.innerHTML = '';

        const container = document.createElement('div');
        container.className = 'text-center py-5';

        const icon = document.createElement('div');
        icon.className = 'mb-3';
        icon.innerHTML =
            '<span class="icon icon-size-large icon-state-default">' +
            '<svg xmlns="http://www.w3.org/2000/svg" width="64" height="64" fill="currentColor" class="text-success" viewBox="0 0 16 16">' +
            '<path d="M16 8A8 8 0 1 1 0 8a8 8 0 0 1 16 0zm-3.97-3.03a.75.75 0 0 0-1.08.022L7.477 9.417 5.384 7.323a.75.75 0 0 0-1.06 1.06L6.97 11.03a.75.75 0 0 0 1.079-.02l3.992-4.99a.75.75 0 0 0-.01-1.05z"/>' +
            '</svg></span>';

        const heading = document.createElement('h2');
        heading.className = 'text-success';
        heading.textContent = 'Landing Page Created!';

        const message = document.createElement('p');
        message.className = 'lead';
        message.textContent = 'The landing page "' + this.escapeHtml(title) + '" has been successfully created.';

        container.appendChild(icon);
        container.appendChild(heading);
        container.appendChild(message);

        if (result.pageUid) {
            const btnGroup = document.createElement('div');
            btnGroup.className = 'd-flex gap-2 justify-content-center mt-4';

            // Edit page button
            const editUrl =
                top.TYPO3.settings.FormEngine?.moduleUrl ||
                '/typo3/record/edit?edit[pages][' + result.pageUid + ']=edit';
            const editBtn = this.createButton('Edit Page Properties', 'btn btn-primary', () => {
                top.TYPO3.Backend.ContentContainer.setUrl(
                    '/typo3/record/edit?edit[pages][' + result.pageUid + ']=edit'
                );
            });

            // View in page module
            const viewBtn = this.createButton('Open in Page Module', 'btn btn-outline-primary', () => {
                top.TYPO3.Backend.ContentContainer.setUrl('/typo3/module/web/layout?id=' + result.pageUid);
            });

            // Create another
            const newBtn = this.createButton('Create Another', 'btn btn-outline-secondary', () => {
                WizardState.reset();
                this.renderStep(0);
            });

            btnGroup.appendChild(editBtn);
            btnGroup.appendChild(viewBtn);
            btnGroup.appendChild(newBtn);
            container.appendChild(btnGroup);
        }

        this.contentArea.appendChild(container);

        // Update progress to 100%
        if (this.progressBar) {
            this.progressBar.style.width = '100%';
        }
    }

    // ── Navigation ──────────────────────────────────────────────

    goNext() {
        const current = WizardState.getCurrentStep();
        if (current < this.steps.length - 1) {
            this.renderStep(current + 1);
        }
    }

    goBack() {
        const current = WizardState.getCurrentStep();
        if (current > 0) {
            // If briefing was skipped (briefingMode === 'none'), go back to template
            const template = WizardState.getTemplate();
            if (current === 2 && template && template.briefingMode === 'none') {
                this.renderStep(0);
            } else {
                this.renderStep(current - 1);
            }
        }
    }

    // ── UI helpers ──────────────────────────────────────────────

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
     * Show a loading spinner in the content area.
     *
     * @param {string} message
     */
    showLoading(message = 'Loading...') {
        this.contentArea.innerHTML =
            '<div class="d-flex align-items-center justify-content-center py-5" role="status" aria-live="polite">' +
            '<div class="spinner-border me-3" aria-hidden="true"></div>' +
            '<span>' +
            this.escapeHtml(message) +
            '</span></div>';
    }

    /**
     * Show an error message with an optional retry button.
     *
     * @param {string} message
     * @param {Function|null} retryHandler
     */
    showError(message, retryHandler = null) {
        this.contentArea.innerHTML = '';

        const alert = document.createElement('div');
        alert.className = 'alert alert-danger';
        alert.setAttribute('role', 'alert');
        alert.textContent = message;

        if (retryHandler) {
            const retryBtn = this.createButton('Retry', 'btn btn-outline-danger btn-sm ms-3', retryHandler);
            alert.appendChild(retryBtn);
        }

        this.contentArea.appendChild(alert);
    }

    /**
     * Update progress bar and step labels.
     *
     * @param {number} stepIndex
     */
    updateProgress(stepIndex) {
        const percentage = ((stepIndex + 1) / this.steps.length) * 100;

        if (this.progressBar) {
            this.progressBar.style.width = percentage + '%';
            this.progressBar.parentElement?.parentElement?.setAttribute('aria-valuenow', String(stepIndex + 1));
        }

        if (this.stepLabels) {
            this.stepLabels.forEach((label, i) => {
                label.classList.toggle('fw-bold', i === stepIndex);
                label.classList.toggle('text-primary', i === stepIndex);
                label.classList.toggle('text-muted', i < stepIndex);
            });
        }
    }

    /**
     * Render navigation buttons (back, next/action, skip).
     *
     * @param {boolean} showBack
     * @param {Object|null} nextConfig - { label, handler, cssClass? }
     * @param {Object|null} skipConfig - { label, handler }
     */
    renderNavigation(showBack, nextConfig, skipConfig) {
        this.navigationArea.innerHTML = '';

        const nav = document.createElement('div');
        nav.className = 'd-flex justify-content-between';

        const leftGroup = document.createElement('div');
        const rightGroup = document.createElement('div');
        rightGroup.className = 'd-flex gap-2';

        if (showBack && WizardState.getCurrentStep() > 0) {
            const backBtn = this.createButton('Back', 'btn btn-outline-secondary', () => this.goBack());
            backBtn.setAttribute('aria-label', 'Go to previous step');
            leftGroup.appendChild(backBtn);
        }

        if (skipConfig) {
            const skipBtn = this.createButton(
                skipConfig.label || 'Skip',
                'btn btn-outline-secondary',
                skipConfig.handler
            );
            skipBtn.setAttribute('aria-label', 'Skip this step');
            rightGroup.appendChild(skipBtn);
        }

        if (nextConfig) {
            const nextBtn = this.createButton(
                nextConfig.label || 'Continue',
                nextConfig.cssClass || 'btn btn-primary',
                nextConfig.handler
            );
            nextBtn.setAttribute('aria-label', nextConfig.label || 'Continue to next step');
            rightGroup.appendChild(nextBtn);
        }

        nav.appendChild(leftGroup);
        nav.appendChild(rightGroup);
        this.navigationArea.appendChild(nav);
    }

    /**
     * Create a form group with label and input.
     *
     * @param {string} id
     * @param {string} label
     * @param {string} type - 'text', 'textarea', 'number'
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
        emptyOption.textContent = '-- Please select --';
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

        const counter = document.createElement('small');
        counter.className = 'form-text text-muted';
        counter.setAttribute('aria-live', 'polite');

        const updateCounter = () => {
            const length = input.value.length;
            counter.textContent = length + ' / ' + maxLength + ' characters';
            counter.classList.toggle('text-danger', length > maxLength);
            counter.classList.toggle('text-muted', length <= maxLength);
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
                    const key = question.key || question.label || 'question_' + index;
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
        return '/' + text
            .toLowerCase()
            .replace(/[äÄ]/g, 'ae')
            .replace(/[öÖ]/g, 'oe')
            .replace(/[üÜ]/g, 'ue')
            .replace(/ß/g, 'ss')
            .replace(/[^a-z0-9]+/g, '-')
            .replace(/^-+|-+$/g, '')
            .replace(/-{2,}/g, '-');
    }

    /**
     * Convert a field key to a human-readable label.
     *
     * @param {string} key
     * @returns {string}
     */
    humanizeFieldName(key) {
        return key
            .replace(/_/g, ' ')
            .replace(/([a-z])([A-Z])/g, '$1 $2')
            .replace(/\b\w/g, (c) => c.toUpperCase());
    }
}

// Auto-initialize when DOM is ready
document.addEventListener('DOMContentLoaded', () => {
    const container = document.getElementById('landing-page-wizard');
    if (container) {
        new LandingPageWizard().initialize(container);
    }
});

export default LandingPageWizard;
