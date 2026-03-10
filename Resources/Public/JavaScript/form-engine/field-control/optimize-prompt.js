import DocumentService from '@typo3/core/document-service.js';
import AjaxRequest from '@typo3/core/ajax/ajax-request.js';
import Icons from '@typo3/backend/icons.js';
import Notification from '@typo3/backend/notification.js';
import Modal from '@typo3/backend/modal.js';

class OptimizePrompt {
  constructor(controlElementId) {
    this.controlElement = null;
    this.textareaElement = null;

    DocumentService.ready().then(() => {
      this.controlElement = document.getElementById(controlElementId);
      if (!this.controlElement) {
        return;
      }

      const itemName = this.controlElement.dataset.itemName;
      this.textareaElement = document.querySelector(
        'textarea[name="' + itemName + '"]'
      );

      this.controlElement.addEventListener('click', (e) => {
        e.preventDefault();
        this.optimize();
      });
    });
  }

  optimize() {
    const templateUid = parseInt(this.controlElement.dataset.templateUid, 10);
    if (!templateUid) {
      Notification.warning('Please save the record first');
      return;
    }

    const currentPrompt = this.textareaElement ? this.textareaElement.value : '';
    if (currentPrompt.trim() !== '') {
      const modal = Modal.confirm(
        TYPO3.lang['fieldControl.optimizePrompt.confirm.title'] || 'Generate Optimized AI Instructions',
        TYPO3.lang['fieldControl.optimizePrompt.confirm.message'] || 'The AI will analyze your template configuration (content types, page fields, example pages, layout) and generate optimized instructions. This replaces the current text. Continue?',
      );
      modal.addEventListener('confirm.button.ok', () => {
        Modal.dismiss();
        this.callOptimizer(templateUid);
      });
      modal.addEventListener('confirm.button.cancel', () => {
        Modal.dismiss();
      });
    } else {
      this.callOptimizer(templateUid);
    }
  }

  callOptimizer(templateUid) {
    this.controlElement.classList.add('disabled');

    // Replace button icon with spinner
    const icon = this.controlElement.querySelector('.icon');
    const originalIconHtml = icon ? icon.outerHTML : '';
    if (icon) {
      Icons.getIcon('spinner-circle', Icons.sizes.small).then((spinnerHtml) => {
        const currentIcon = this.controlElement.querySelector('.icon');
        if (currentIcon) {
          currentIcon.outerHTML = spinnerHtml;
        }
      });
    }

    // Add loading state to textarea
    if (this.textareaElement) {
      this.textareaElement.style.opacity = '0.5';
      this.textareaElement.setAttribute('readonly', 'readonly');
    }

    Notification.info(
      TYPO3.lang['fieldControl.optimizePrompt.running'] || 'Optimizing instructions…',
      TYPO3.lang['fieldControl.optimizePrompt.running.detail'] || 'The AI is analyzing your template — this may take a moment.',
      0
    );

    new AjaxRequest(TYPO3.settings.ajaxUrls.nr_landingpage_optimize_prompt)
      .post({ templateUid: templateUid })
      .then(async (response) => {
        const data = await response.resolve();
        if (data.success && data.data && data.data.prompt) {
          if (this.textareaElement) {
            this.textareaElement.value = data.data.prompt;
            this.textareaElement.dispatchEvent(new Event('change'));
          }
          Notification.success(
            TYPO3.lang['fieldControl.optimizePrompt.success'] || 'Instructions optimized',
            '',
            3
          );
        } else {
          Notification.error(
            TYPO3.lang['fieldControl.optimizePrompt.error'] || 'Optimization failed',
            data.error || ''
          );
        }
      })
      .catch(async (error) => {
        let detail = 'Could not reach the server';
        try {
          const data = await error.response?.resolve();
          if (data?.error) {
            detail = data.error;
          }
        } catch {
          // response not parseable, keep default message
        }
        Notification.error(
          TYPO3.lang['fieldControl.optimizePrompt.error'] || 'Optimization failed',
          detail
        );
      })
      .finally(() => {
        this.controlElement.classList.remove('disabled');

        // Restore original icon
        const spinner = this.controlElement.querySelector('.icon');
        if (spinner && originalIconHtml) {
          spinner.outerHTML = originalIconHtml;
        }

        // Remove textarea loading state
        if (this.textareaElement) {
          this.textareaElement.style.opacity = '';
          this.textareaElement.removeAttribute('readonly');
        }

        // Dismiss persistent info notifications
        document.querySelectorAll('typo3-notification-message[notification-severity="info"]').forEach((el) => el.clear());
      });
  }
}

export default OptimizePrompt;
