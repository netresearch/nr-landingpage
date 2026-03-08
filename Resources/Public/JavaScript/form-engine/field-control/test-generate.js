import DocumentService from '@typo3/core/document-service.js';
import AjaxRequest from '@typo3/core/ajax/ajax-request.js';
import Notification from '@typo3/backend/notification.js';
import Modal from '@typo3/backend/modal.js';

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
   * Create a DOM element from an HTML string.
   *
   * TYPO3 Modal.advanced() with type "default" and a string content wraps it
   * in a Lit template literal (<p>${content}</p>) which auto-escapes HTML.
   * Passing a DOM object instead triggers the "template" type path which
   * renders the content as-is.
   */
  htmlToElement(html) {
    const wrapper = document.createElement('div');
    wrapper.innerHTML = html;
    return wrapper;
  }

  showInputDialog() {
    const templateUid = parseInt(this.controlElement.dataset.templateUid, 10);
    if (!templateUid) {
      Notification.warning(this.lang('fieldControl.testGenerate.saveFirst', 'Please save the record first'));
      return;
    }

    const modal = Modal.advanced({
      title: this.lang('fieldControl.testGenerate.modal.title', 'Preview — Full Page Generation'),
      content: this.htmlToElement(
        '<div class="form-group mb-3">'
        + '<label for="testGenerateTitle" class="form-label">' + this.escapeHtml(this.lang('fieldControl.testGenerate.form.label', 'Sample Title / Topic')) + '</label>'
        + '<input type="text" class="form-control" id="testGenerateTitle" placeholder="' + this.escapeHtml(this.lang('fieldControl.testGenerate.form.placeholder', 'e.g. Summer Sale 2026')) + '">'
        + '<small class="form-text text-body-secondary">' + this.escapeHtml(this.lang('fieldControl.testGenerate.form.helpText', 'Enter a sample topic. The AI will generate a complete page preview with content sections and images based on your template settings.')) + '</small>'
        + '</div>'
      ),
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
            this.runTestGenerate(modal, templateUid, title);
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

    contentArea.innerHTML = '<div class="text-center py-5">'
      + '<div class="spinner-border text-primary" role="status"></div>'
      + '<p class="mt-3 text-body-secondary">' + this.escapeHtml(this.lang('fieldControl.testGenerate.loading', 'Generating content preview…')) + '</p>'
      + '</div>';

    // Disable buttons during generation
    modal.querySelectorAll('.modal-footer button').forEach(btn => btn.disabled = true);

    try {
      const response = await new AjaxRequest(TYPO3.settings.ajaxUrls.nr_landingpage_test_generate)
        .post({ templateUid, sampleTitle });
      const data = await response.resolve();

      if (data.success && data.data) {
        this.renderPreview(contentArea, data.data, sampleTitle);
      } else {
        contentArea.innerHTML = '<div class="alert alert-danger">'
          + '<strong>' + this.escapeHtml(this.lang('fieldControl.testGenerate.error.failed', 'Generation failed:')) + '</strong> ' + this.escapeHtml(data.error || this.lang('fieldControl.testGenerate.error.unknown', 'Unknown error'))
          + '</div>';
      }
    } catch {
      contentArea.innerHTML = '<div class="alert alert-danger">'
        + this.escapeHtml(this.lang('fieldControl.testGenerate.error.server', 'Could not reach the server. Please check your LLM configuration.'))
        + '</div>';
    } finally {
      modal.querySelectorAll('.modal-footer button').forEach(btn => btn.disabled = false);
    }
  }

  renderPreview(container, data, sampleTitle) {
    const sections = data.sections || [];
    const images = data.images || [];
    const aiAvailable = data.aiGenerationAvailable || false;

    let html = '<div class="mb-3">'
      + '<span class="badge bg-success me-2">' + sections.length + this.escapeHtml(this.lang('fieldControl.testGenerate.badge.sectionsGenerated', ' sections generated')) + '</span>'
      + (aiAvailable ? '<span class="badge bg-info">' + this.escapeHtml(this.lang('fieldControl.testGenerate.badge.aiImages', 'AI image generation available')) + '</span>' : '')
      + '</div>';

    html += '<p class="text-body-secondary small">' + this.escapeHtml(this.lang('fieldControl.testGenerate.preview.sampleTopic', 'Sample topic: ')) + '<strong>' + this.escapeHtml(sampleTitle) + '</strong></p>';

    sections.forEach((section, index) => {
      const sectionImages = (images[index] && images[index].length > 0) ? images[index] : [];

      html += '<div class="card mb-3">';
      html += '<div class="card-header d-flex justify-content-between align-items-center">';
      html += '<strong>' + this.escapeHtml(section.section || this.lang('fieldControl.testGenerate.preview.section', 'Section')) + '</strong>';
      html += '<span class="badge bg-secondary">' + this.escapeHtml(section.ctype || this.lang('fieldControl.testGenerate.preview.text', 'text')) + '</span>';
      html += '</div>';
      html += '<div class="card-body">';

      if (section.header) {
        html += '<h5>' + this.escapeHtml(section.header) + '</h5>';
      }
      if (section.subheader) {
        html += '<h6 class="text-body-secondary">' + this.escapeHtml(section.subheader) + '</h6>';
      }
      if (section.bodytext) {
        // bodytext is already sanitized server-side (HtmlSanitizer allows only safe tags)
        html += '<div class="border rounded p-2 bg-body-tertiary small mb-2">' + section.bodytext + '</div>';
      }

      // Image keywords
      if (section.imageKeywords && section.imageKeywords.length > 0) {
        html += '<div class="mb-2"><small class="text-body-secondary">' + this.escapeHtml(this.lang('fieldControl.testGenerate.preview.imageKeywords', 'Image keywords: ')) + '</small>';
        section.imageKeywords.forEach(kw => {
          html += '<span class="badge bg-light text-dark me-1">' + this.escapeHtml(kw) + '</span>';
        });
        html += '</div>';
      }

      // Image prompt
      if (section.imagePrompt) {
        html += '<div class="mb-2"><small class="text-body-secondary">' + this.escapeHtml(this.lang('fieldControl.testGenerate.preview.imagePrompt', 'Image prompt: ')) + '</small>'
          + '<em class="small">' + this.escapeHtml(section.imagePrompt) + '</em></div>';
      }

      // FAL images found
      if (sectionImages.length > 0) {
        html += '<div class="mt-2"><small class="text-body-secondary d-block mb-1">' + this.escapeHtml(this.lang('fieldControl.testGenerate.preview.imagesFound', 'Images found:')) + '</small>';
        html += '<div class="d-flex gap-2 flex-wrap">';
        sectionImages.forEach(img => {
          html += '<div class="text-center" style="width:100px;">';
          if (img.publicUrl) {
            html += '<img src="' + this.escapeHtml(img.publicUrl) + '" alt="' + this.escapeHtml(img.title || img.name || '') + '" '
              + 'class="img-thumbnail" style="height:60px;width:100px;object-fit:cover;">';
          }
          html += '<small class="d-block text-truncate">' + this.escapeHtml(img.title || img.name || '') + '</small>';
          if (img.generated) {
            html += '<span class="badge bg-warning text-dark" style="font-size:0.65em;">' + this.escapeHtml(this.lang('fieldControl.testGenerate.preview.aiGenerated', 'AI generated')) + '</span>';
          }
          html += '</div>';
        });
        html += '</div></div>';
      } else {
        html += '<div class="mt-2"><small class="text-body-secondary">' + this.escapeHtml(this.lang('fieldControl.testGenerate.preview.noImages', 'No FAL images found for this section.')) + '</small></div>';
      }

      html += '</div></div>';
    });

    if (sections.length === 0) {
      html += '<div class="alert alert-warning">' + this.escapeHtml(this.lang('fieldControl.testGenerate.preview.noSections', 'No sections were generated. Check your AI instructions and content type settings.')) + '</div>';
    }

    container.innerHTML = html;
  }

  escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
  }
}

export default TestGenerate;
