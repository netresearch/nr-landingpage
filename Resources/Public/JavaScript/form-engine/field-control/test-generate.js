/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

import DocumentService from '@typo3/core/document-service.js';
import AjaxRequest from '@typo3/core/ajax/ajax-request.js';
import Notification from '@typo3/backend/notification.js';
import Modal from '@typo3/backend/modal.js';
import '@typo3/backend/element/spinner-element.js';

class TestGenerate {
  /**
   * Resolve a localized label via TYPO3.lang, falling back to the given default.
   */
  lang(key, fallback) {
    return (typeof TYPO3 !== 'undefined' && TYPO3.lang && TYPO3.lang[key]) || fallback;
  }

  constructor(controlElementId) {
    this.controlElement = null;

    DocumentService.ready().then(() => {
      this.controlElement = document.getElementById(controlElementId);
      if (!this.controlElement) {
        return;
      }

      this.controlElement.addEventListener('click', (e) => {
        e.preventDefault();
        this.showInputDialog();
      });
    });
  }

  /**
   * Create an element with optional class, text content and attributes.
   *
   * All markup in this control is built with DOM methods: values from the
   * server are only ever set as text or attribute values, never parsed as HTML.
   */
  el(tagName, options = {}, children = []) {
    const element = document.createElement(tagName);
    if (options.className) {
      element.className = options.className;
    }
    if (options.text !== undefined) {
      element.textContent = String(options.text);
    }
    Object.entries(options.attrs || {}).forEach(([name, value]) => element.setAttribute(name, String(value)));
    children.forEach((child) => element.appendChild(child));
    return element;
  }

  /**
   * Render generated bodytext in a sandboxed frame without script execution.
   */
  bodytextFrame(bodytext, title) {
    const iframe = document.createElement('iframe');
    iframe.setAttribute('sandbox', 'allow-same-origin');
    iframe.title = title;
    iframe.className = 'border rounded w-100 mb-2';
    iframe.style.minHeight = '120px';
    iframe.srcdoc = bodytext;
    iframe.addEventListener('load', () => {
      const height = iframe.contentDocument?.documentElement?.scrollHeight;
      if (height) {
        iframe.style.height = height + 'px';
      }
    });
    return iframe;
  }

  /**
   * Replace the content of a container with the given nodes.
   */
  setContent(container, ...nodes) {
    container.replaceChildren(...nodes);
  }

  showInputDialog() {
    const templateUid = parseInt(this.controlElement.dataset.templateUid, 10);
    if (!templateUid) {
      Notification.warning(this.lang('fieldControl.testGenerate.saveFirst', 'Please save the record first'));
      return;
    }

    const modal = Modal.advanced({
      title: this.lang('fieldControl.testGenerate.modal.title', 'Preview — Full Page Generation'),
      // A DOM node (not a string) makes Modal.advanced() render it as-is.
      content: this.el('div', {}, [
        this.el('div', { className: 'form-group mb-3' }, [
          this.el('label', { className: 'form-label', text: this.lang('fieldControl.testGenerate.form.label', 'Sample Title / Topic'), attrs: { for: 'testGenerateTitle' } }),
          this.el('input', { className: 'form-control', attrs: { type: 'text', id: 'testGenerateTitle', placeholder: this.lang('fieldControl.testGenerate.form.placeholder', 'e.g. Summer Sale 2026') } }),
          this.el('small', { className: 'form-text text-variant', text: this.lang('fieldControl.testGenerate.form.helpText', 'Enter a sample topic. The AI will generate a complete page preview with content sections and images based on your template settings.') }),
        ]),
      ]),
      size: Modal.sizes.large,
      buttons: [
        {
          text: this.lang('fieldControl.testGenerate.button.cancel', 'Cancel'),
          btnClass: 'btn-default',
          trigger: () => modal.hideModal(),
        },
        {
          text: this.lang('fieldControl.testGenerate.button.generate', 'Generate Preview'),
          btnClass: 'btn-primary',
          trigger: () => {
            const input = modal.querySelector('#testGenerateTitle');
            const title = input ? input.value.trim() : '';
            if (!title) {
              Notification.warning(this.lang('fieldControl.testGenerate.validation.titleRequired', 'Please enter a sample title'));
              return;
            }
            // runTestGenerate catches and reports its request errors itself.
            void this.runTestGenerate(modal, templateUid, title);
          },
        },
      ],
    });

    // Focus input after modal opens
    setTimeout(() => {
      const input = modal.querySelector('#testGenerateTitle');
      if (input) input.focus();
    }, 300);
  }

  async runTestGenerate(modal, templateUid, sampleTitle) {
    const contentArea = modal.querySelector('.modal-body');
    if (!contentArea) return;

    this.setContent(contentArea, this.el('div', { className: 'text-center py-5', attrs: { role: 'status', 'aria-live': 'polite' } }, [
      this.el('typo3-backend-spinner', { attrs: { size: 'large', 'aria-hidden': 'true' } }),
      this.el('p', { className: 'mt-3 text-variant', text: this.lang('fieldControl.testGenerate.loading', 'Generating content preview…') }),
    ]));

    // Disable buttons during generation
    modal.querySelectorAll('.modal-footer button').forEach(btn => btn.disabled = true);

    try {
      const response = await new AjaxRequest(TYPO3.settings.ajaxUrls.nr_landingpage_test_generate)
        .post({ templateUid, sampleTitle });
      const data = await response.resolve();

      if (data.success && data.data) {
        this.renderPreview(contentArea, data.data, sampleTitle);
      } else {
        this.setContent(contentArea, this.el('div', { className: 'alert alert-danger' }, [
          this.el('strong', { text: this.lang('fieldControl.testGenerate.error.failed', 'Generation failed:') }),
          document.createTextNode(' ' + (data.error || this.lang('fieldControl.testGenerate.error.unknown', 'Unknown error'))),
        ]));
      }
    } catch {
      this.setContent(contentArea, this.el('div', {
        className: 'alert alert-danger',
        text: this.lang('fieldControl.testGenerate.error.server', 'Could not reach the server. Please check your LLM configuration.'),
      }));
    } finally {
      modal.querySelectorAll('.modal-footer button').forEach(btn => btn.disabled = false);
    }
  }

  renderPreview(container, data, sampleTitle) {
    const sections = data.sections || [];
    const images = data.images || [];
    const aiAvailable = data.aiGenerationAvailable || false;
    const nodes = [];

    const badges = this.el('div', { className: 'mb-3' }, [
      this.el('span', { className: 'badge badge-success me-2', text: sections.length + this.lang('fieldControl.testGenerate.badge.sectionsGenerated', ' sections generated') }),
    ]);
    if (aiAvailable) {
      badges.appendChild(this.el('span', { className: 'badge badge-info', text: this.lang('fieldControl.testGenerate.badge.aiImages', 'AI image generation available') }));
    }
    nodes.push(badges);

    nodes.push(this.el('p', { className: 'text-variant small', text: this.lang('fieldControl.testGenerate.preview.sampleTopic', 'Sample topic: ') }, [
      this.el('strong', { text: sampleTitle }),
    ]));

    // Page fields (SEO, OG, etc.)
    const pageFields = data.pageFields || {};
    const pageFieldKeys = Object.keys(pageFields);
    if (pageFieldKeys.length > 0) {
      const rows = pageFieldKeys.map((key) => this.el('tr', {}, [
        this.el('td', { className: 'text-variant fw-bold', text: key, attrs: { style: 'width:140px;' } }),
        this.el('td', { text: pageFields[key] }),
      ]));
      nodes.push(this.el('div', { className: 'card mb-3 border-info' }, [
        this.el('div', { className: 'card-header bg-info bg-opacity-10' }, [
          this.el('strong', { text: this.lang('fieldControl.testGenerate.preview.pageFields', 'Page Fields') }),
        ]),
        this.el('div', { className: 'card-body' }, [this.el('table', { className: 'table mb-0' }, [this.el('tbody', {}, rows)])]),
      ]));
    }

    sections.forEach((section, index) => {
      const sectionImages = (images[index] && images[index].length > 0) ? images[index] : [];
      const sectionLabel = section.section || this.lang('fieldControl.testGenerate.preview.section', 'Section');

      const headerBadges = this.el('div');
      if (section.colPos !== undefined) {
        headerBadges.appendChild(this.el('span', { className: 'badge badge-info me-1', text: 'colPos ' + String(section.colPos) }));
      }
      headerBadges.appendChild(this.el('span', { className: 'badge badge-default', text: section.ctype || this.lang('fieldControl.testGenerate.preview.text', 'text') }));

      const body = this.el('div', { className: 'card-body' });
      if (section.header) {
        body.appendChild(this.el('h2', { className: 'h5', text: section.header }));
      }
      if (section.subheader) {
        body.appendChild(this.el('h3', { className: 'h6 text-variant', text: section.subheader }));
      }
      if (section.bodytext) {
        body.appendChild(this.bodytextFrame(section.bodytext, sectionLabel));
      }

      // Image keywords
      if (section.imageKeywords && section.imageKeywords.length > 0) {
        body.appendChild(this.el('div', { className: 'mb-2' }, [
          this.el('small', { className: 'text-variant', text: this.lang('fieldControl.testGenerate.preview.imageKeywords', 'Image keywords: ') }),
          ...section.imageKeywords.map((kw) => this.el('span', { className: 'badge badge-default me-1', text: kw })),
        ]));
      }

      // Image prompt
      if (section.imagePrompt) {
        body.appendChild(this.el('div', { className: 'mb-2' }, [
          this.el('small', { className: 'text-variant', text: this.lang('fieldControl.testGenerate.preview.imagePrompt', 'Image prompt: ') }),
          this.el('em', { className: 'small', text: section.imagePrompt }),
        ]));
      }

      // FAL images found
      if (sectionImages.length > 0) {
        const tiles = sectionImages.map((img) => {
          const tile = this.el('div', { className: 'text-center', attrs: { style: 'width:100px;' } });
          if (img.publicUrl) {
            tile.appendChild(this.el('img', {
              className: 'img-thumbnail',
              attrs: { src: img.publicUrl, alt: img.title || img.name || '', style: 'height:60px;width:100px;object-fit:cover;' },
            }));
          }
          tile.appendChild(this.el('small', { className: 'd-block text-truncate', text: img.title || img.name || '' }));
          if (img.generated) {
            tile.appendChild(this.el('span', { className: 'badge badge-warning', text: this.lang('fieldControl.testGenerate.preview.aiGenerated', 'AI generated'), attrs: { style: 'font-size:0.65em;' } }));
          }
          return tile;
        });
        body.appendChild(this.el('div', { className: 'mt-2' }, [
          this.el('small', { className: 'text-variant d-block mb-1', text: this.lang('fieldControl.testGenerate.preview.imagesFound', 'Images found:') }),
          this.el('div', { className: 'd-flex gap-2 flex-wrap' }, tiles),
        ]));
      } else {
        body.appendChild(this.el('div', { className: 'mt-2' }, [
          this.el('small', { className: 'text-variant', text: this.lang('fieldControl.testGenerate.preview.noImages', 'No FAL images found for this section.') }),
        ]));
      }

      nodes.push(this.el('div', { className: 'card mb-3' }, [
        this.el('div', { className: 'card-header d-flex justify-content-between align-items-center' }, [
          this.el('strong', { text: sectionLabel }),
          headerBadges,
        ]),
        body,
      ]));
    });

    if (sections.length === 0) {
      nodes.push(this.el('div', {
        className: 'alert alert-warning',
        text: this.lang('fieldControl.testGenerate.preview.noSections', 'No sections were generated. Check your AI instructions and content type settings.'),
      }));
    }

    this.setContent(container, ...nodes);
  }
}

export default TestGenerate;
