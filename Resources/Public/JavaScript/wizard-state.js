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
        this.hasImageTask = false;
        this.aiGenerationAvailable = false;
        this.generationMode = 'structured';
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

    updateContentSection(index, section) {
        if (index >= 0 && index < this.contentSections.length) {
            this.contentSections[index] = section;
        }
    }
}

export default new WizardState();
