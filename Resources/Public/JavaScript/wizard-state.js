/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

/**
 * Client-side state manager for the Landing Page Wizard.
 *
 * Holds all wizard data across steps: selected template, briefing answers,
 * page fields, content sections, images, and placement info.
 */
class WizardState {
    constructor() {
        this.reset();
    }

    reset() {
        this.selectedTemplate = null;
        this.briefingAnswers = {};
        this.pageFields = {};
        this.contentSections = [];
        this.images = [];
        this.parentPageId = 0;
        this.parentPageTitle = '';
        this.sourcePageUid = 0;
        this.regenerateMode = false;
        this.title = '';
        this.slug = '';
        this.imageErrors = [];
        // Hints of nr-llm's guardrails for the generated content, and per
        // section for one regenerated on its own (index => hints).
        this.contentHints = [];
        this.sectionHints = {};
        this.hasImageTask = false;
        this.aiGenerationAvailable = false;
        this.generationMode = 'structured';
        // Every image the wizard has shown (uid => image), for the page preview.
        this.imageCatalog = {};
        this.previewOpen = false;
    }

    setTemplate(template) {
        this.selectedTemplate = template;
    }

    getTemplate() {
        return this.selectedTemplate;
    }

    setBriefingAnswers(answers) {
        this.briefingAnswers = answers;
    }

    getBriefingAnswers() {
        return this.briefingAnswers;
    }

    setPageFields(fields) {
        this.pageFields = fields;
    }

    getPageFields() {
        return this.pageFields;
    }

    setContentSections(sections) {
        this.contentSections = sections;
    }

    getContentSections() {
        return this.contentSections;
    }

    setImages(images) {
        this.images = images;
    }

    getImages() {
        return this.images;
    }

    setParentPageId(id) {
        this.setParentPage(id, '');
    }

    /**
     * The page the landing page is created below; 0 and '' for none.
     *
     * @param {number} id
     * @param {string} title
     */
    setParentPage(id, title) {
        this.parentPageId = id;
        this.parentPageTitle = id > 0 ? title : '';
    }

    getParentPageId() {
        return this.parentPageId;
    }

    getParentPageTitle() {
        return this.parentPageTitle;
    }

    setTitle(title) {
        this.title = title;
    }

    getTitle() {
        return this.title;
    }

    setSlug(slug) {
        this.slug = slug;
    }

    getSlug() {
        return this.slug;
    }

    /**
     * Put the sections in a new order. Everything kept per section (images,
     * image errors, guardrail hints) follows, so card N still shows the
     * images and hints of section N.
     *
     * @param {number[]} order Current indices, in the order they get; an index left out removes that section
     */
    reorderSections(order) {
        this.contentSections = order.map((i) => this.contentSections[i]);
        this.images = order.map((i) => this.images[i] ?? []);
        this.imageErrors = order.map((i) => this.imageErrors[i] ?? '');
        const hints = {};
        order.forEach((oldIndex, newIndex) => {
            if (this.sectionHints[oldIndex] !== undefined) {
                hints[newIndex] = this.sectionHints[oldIndex];
            }
        });
        this.sectionHints = hints;
    }

    /**
     * Move a section up (offset -1) or down (offset 1).
     *
     * @param {number} index
     * @param {number} offset
     * @returns {number} the new index, or -1 when the section cannot move that way
     */
    moveSection(index, offset) {
        const target = index + offset;
        if (index < 0 || index >= this.contentSections.length || target < 0 || target >= this.contentSections.length) {
            return -1;
        }
        const order = this.contentSections.map((_, i) => i);
        [order[index], order[target]] = [order[target], order[index]];
        this.reorderSections(order);
        return target;
    }

    /**
     * Remove a section.
     *
     * @param {number} index
     * @returns {boolean} whether a section was removed
     */
    removeSection(index) {
        if (index < 0 || index >= this.contentSections.length) {
            return false;
        }
        this.reorderSections(this.contentSections.map((_, i) => i).filter((i) => i !== index));
        return true;
    }

    updateContentSection(index, section) {
        if (index >= 0 && index < this.contentSections.length) {
            this.contentSections[index] = section;
        }
    }
}

export default new WizardState();
